<?php
namespace AsdeBalears\AreaPrivada\Frontend\Views;

use AsdeBalears\AreaPrivada\Auth\Ambito;
use AsdeBalears\AreaPrivada\Crm\CrmException;
use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Crm\PersonaRepository;
use AsdeBalears\AreaPrivada\Eventos\AnticiposTable;
use AsdeBalears\AreaPrivada\Eventos\Cobros;
use AsdeBalears\AreaPrivada\Eventos\Config;
use AsdeBalears\AreaPrivada\Eventos\Cuentas;
use AsdeBalears\AreaPrivada\Eventos\EventosTable;
use AsdeBalears\AreaPrivada\Eventos\GastosTable;
use AsdeBalears\AreaPrivada\Eventos\Inscripciones;
use AsdeBalears\AreaPrivada\Eventos\InscripcionesTable;
use AsdeBalears\AreaPrivada\Eventos\Justificantes;
use AsdeBalears\AreaPrivada\Eventos\Material;
use AsdeBalears\AreaPrivada\Eventos\MovimientosTable;
use AsdeBalears\AreaPrivada\Eventos\Normativa;
use AsdeBalears\AreaPrivada\Eventos\PartidasTable;
use AsdeBalears\AreaPrivada\Eventos\Plantillas;
use AsdeBalears\AreaPrivada\Eventos\Presupuesto;
use AsdeBalears\AreaPrivada\Eventos\Servicio;
use AsdeBalears\AreaPrivada\Eventos\Tareas;
use AsdeBalears\AreaPrivada\Eventos\TareasTable;
use AsdeBalears\AreaPrivada\Frontend\Notices;
use AsdeBalears\AreaPrivada\Informes\Generador;
use AsdeBalears\AreaPrivada\Menus\AvisosTable;
use AsdeBalears\AreaPrivada\Menus\HojaCocina;
use AsdeBalears\AreaPrivada\Menus\MenuEvento;
use AsdeBalears\AreaPrivada\Menus\Validacion;
use AsdeBalears\AreaPrivada\Menus\RecetasTable;

defined( 'ABSPATH' ) || exit;

/**
 * Eventos del grupo (v0.35, doc 43): la agenda, el formulario del evento y su ficha con
 * cuatro pestañas: resumen (con la lista de comprobación de la normativa), equipo dirigente,
 * inscripciones (v0.35: participantes, lista de espera, autorizaciones en papel, bajas y,
 * para Tesorería, los cobros) y presupuesto (con el precio por sección y su validación).
 * v0.36: la pestaña «Cuentas» (gastos reales con justificante, reembolsos, anticipos, fondo,
 * balance y cierre), las devoluciones a las familias y, con las cuentas cerradas, solo lectura.
 */
final class EventosView extends EventosBaseView {

	public const PESTANAS = array(
		'resumen'       => 'Resumen',
		'calendario'    => 'Calendario',
		'equipo'        => 'Equipo',
		'inscripciones' => 'Inscripciones',
		'menu'          => 'Menú',
		'material'      => 'Material',
		'presupuesto'   => 'Presupuesto',
		'cuentas'       => 'Cuentas',
	);

	/** Lo escrito en el formulario cuando no se ha podido guardar (se vuelve a enseñar): evento (0 = nuevo) y datos. */
	private static ?array $valores = null;

	public function render( string $content = '' ): string {
		if ( ! Servicio::puede_entrar() ) {
			return $this->denegado();
		}
		$a   = Ambito::actual();
		$cfg = Config::de();

		if ( '1' === $this->get( 'nuevo' ) ) {
			if ( ! Servicio::puede_crear( $a ) ) {
				return $this->denegado( __( 'You cannot create events.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
			}
			return $this->layout( 'eventos', $this->formulario( null, $a, $cfg ) );
		}

		$id = (int) $this->get( 'evento' );
		if ( $id ) {
			$e = EventosTable::obtener( $id );
			if ( ! $e || ! Servicio::ve( $e, $a ) ) {
				return $this->denegado( __( 'You do not have access to that event.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
			}
			if ( '1' === $this->get( 'editar' ) && Servicio::puede_editar( $e, $a ) ) {
				return $this->layout( 'eventos', $this->formulario( $e, $a, $cfg ) );
			}
			return $this->layout( 'eventos', $this->evento( $e, $a, $cfg ) );
		}

		return $this->layout( 'eventos', $this->subnav( 'eventos' ) . $this->agenda( $a, $cfg ) );
	}

	private function agenda( Ambito $a, array $cfg ): string {
		$todos   = Servicio::de_agenda( $a );
		$filtro  = isset( EventosTable::FILTROS[ $this->get( 'estado' ) ] ) ? $this->get( 'estado' ) : '';
		$seccion = $this->get( 'seccion' );
		$tipo    = $this->get( 'tipo' );
		$lista   = array_values(
			array_filter(
				$todos,
				static fn( array $e ) => EventosTable::cumple( $e, $filtro )
					&& ( '' === $seccion || in_array( $seccion, $e['secciones'], true ) )
					&& ( '' === $tipo || $tipo === $e['tipo'] )
			)
		);
		if ( 'pasados' === $filtro ) {
			$lista = array_reverse( $lista ); // Lo más reciente primero.
		}
		$cuentas = array();
		foreach ( array_keys( EventosTable::FILTROS ) as $f ) {
			$cuentas[ $f ] = count( array_filter( $todos, static fn( $e ) => EventosTable::cumple( $e, $f ) ) );
		}
		return $this->tpl(
			'eventos/agenda',
			array(
				'cfg'        => $cfg,
				'lista'      => $lista,
				'cuentas'    => $cuentas,
				'filtro'     => $filtro,
				'seccion'    => $seccion,
				'tipo'       => $tipo,
				'global'     => Servicio::vision_global( $a ),
				'por_validar' => Servicio::pendientes( $a )['validar'],
				'puede_crear' => Servicio::puede_crear( $a ),
				'url'        => $this->url( 'eventos' ),
			)
		);
	}

	private function formulario( ?array $e, Ambito $a, array $cfg ): string {
		return $this->tpl(
			'eventos/formulario',
			array(
				'e'          => $e,
				'v'          => null !== self::$valores && self::$valores['evento'] === ( $e['id'] ?? 0 ) ? self::$valores['datos'] : null,
				'cfg'        => $cfg,
				'tipo'       => sanitize_key( $this->get( 'tipo' ) ),
				'secciones'  => array_values( array_unique( array_merge( Servicio::secciones_para( $a ), $e ? $e['secciones'] : array() ) ) ),
				'ambitos'    => array_values( array_unique( array_merge( Servicio::ambitos_para( $a ), $e ? array( $e['ambito'] ) : array() ) ) ),
				'validado'   => $e && null !== $e['validado_en'],
				'url'        => $this->url( 'eventos' ),
				'volver'     => $e ? $this->url( 'eventos', array( 'evento' => (string) $e['id'] ) ) : $this->url( 'eventos' ),
			)
		);
	}

	private function evento( array $e, Ambito $a, array $cfg ): string {
		$pestana = isset( self::PESTANAS[ $this->get( 'pestana' ) ] ) ? $this->get( 'pestana' ) : 'resumen';
		if ( Servicio::solo_material( $e, $a ) && ! in_array( $pestana, array( 'resumen', 'material' ), true ) ) {
			$pestana = 'resumen';
		}
		if ( 'cuentas' === $pestana && ! Cuentas::puede_ver( $e, $a ) || 'menu' === $pestana && ! MenuEvento::puede_ver( $e, $a ) || 'material' === $pestana && ! Material::puede_ver( $e, $a ) ) {
			$pestana = 'resumen';
		}
		Inscripciones::revisar( $e ); // Lista de espera al día (plazas que caducan o se liberan).
		$filas           = InscripcionesTable::participantes( $e['id'] );
		$e['inscritos']  = Inscripciones::recuento( $e, $filas ); // La ratio y la comunicación cuentan a los inscritos.
		$equipo  = InscripcionesTable::equipo( $e['id'] );
		$nombres = $this->personas( array_column( $equipo, 'socio' ) );
		// La formación y el certificado se leen en vivo del CRM (una consulta) en el resumen y en el equipo.
		$check   = in_array( $pestana, array( 'resumen', 'equipo' ), true ) ? Servicio::comprobar_equipo( $e, $equipo, $nombres ) : null;
		$vars    = array(
			'e'        => $e,
			'cfg'      => $cfg,
			'ambito'   => $a,
			'equipo'   => $equipo,
			'nombres'  => $nombres,
			'check'    => $check,
			'gestiona' => Servicio::en_gestion( $e, $a ),
			'url'      => $this->url( 'eventos', array( 'evento' => (string) $e['id'] ) ),
		);
		$contenido = '';
		if ( 'resumen' === $pestana ) {
			$contenido = $this->tpl( 'eventos/resumen', $vars + array( 'lista' => Normativa::lista( $e, (array) $check ) ) );
		} elseif ( 'inscripciones' === $pestana ) {
			$contenido = $this->tpl( 'eventos/inscripciones', $vars + $this->inscripciones( $e, $a, $filas ) );
		} elseif ( 'cuentas' === $pestana ) {
			$contenido = $this->tpl( 'eventos/cuentas', $vars + $this->cuentas( $e ) );
		} elseif ( 'material' === $pestana ) {
			$contenido = $this->tpl( 'eventos/material', $vars + $this->material( $e, $a ) );
		} elseif ( 'menu' === $pestana ) {
			$contenido = $this->tpl( 'eventos/menu', $vars + $this->menu( $e, $a ) );
		} elseif ( 'calendario' === $pestana ) {
			$contenido = $this->tpl( 'eventos/calendario', $vars + $this->calendario( $e, $a, $cfg, $equipo, $nombres ) );
		} elseif ( 'equipo' === $pestana ) {
			$contenido = $this->tpl(
				'eventos/equipo',
				$vars + array(
					'candidatos' => Servicio::puede_editar( $e, $a ) ? array_diff_key( Servicio::candidatos(), array_flip( array_column( $equipo, 'socio' ) ) ) : array(),
				)
			);
		} else {
			$contenido = $this->tpl(
				'eventos/presupuesto',
				$vars + array(
					'partidas' => PartidasTable::previstas( $e['id'] ),
					'calculo'  => null !== $e['presupuesto'] ? $e['presupuesto'] + array(
						'ok'      => true,
						'errores' => array(),
					) : Presupuesto::del_evento( $e ),
				)
			);
		}
		return $this->tpl(
			'eventos/evento',
			$vars + array(
				'pestana'   => $pestana,
				'contenido' => $contenido,
				'agenda'    => $this->url( 'eventos' ),
			)
		);
	}

	/** Lo que necesita la pestaña «Inscripciones». */
	private function inscripciones( array $e, Ambito $a, array $filas ): array {
		$movs     = MovimientosTable::de_evento( $e['id'] );
		$gestiona = Servicio::en_gestion( $e, $a );
		$personas = $this->personas( array_column( $filas, 'socio' ) );
		$cobros   = array();
		$refs     = array();
		$datos    = array();
		foreach ( $filas as $f ) {
			$cobros[ $f['id'] ] = Cobros::de( $f, $movs[ $f['id'] ] ?? array() );
			if ( Cobros::puede() && in_array( $f['estado'], InscripcionesTable::ACTIVAS, true ) ) {
				$refs[ $f['id'] ] = Cobros::referencia( $e, $f );
			}
			// Quién recoge y las observaciones, solo para quien gestiona el evento.
			if ( $gestiona && ! empty( $f['datos_enc'] ) ) {
				$datos[ $f['id'] ] = Inscripciones::datos( $f );
			}
		}
		// Convocados sin respuesta y quién se puede inscribir (de la instantánea: una sola lectura).
		$convocados = array();
		$otros      = array();
		$repo       = PersonaRepository::make();
		$con_fila   = array_flip( array_column( $filas, 'socio' ) );
		$activos    = array_flip( array_column( array_filter( $filas, static fn( $f ) => in_array( $f['estado'], array( InscripcionesTable::PENDIENTE, InscripcionesTable::CONFIRMADA, InscripcionesTable::ESPERA ), true ) ), 'socio' ) );
		$equipo     = array_flip( array_column( InscripcionesTable::equipo( $e['id'] ), 'socio' ) );
		if ( $repo && EventosTable::BORRADOR !== $e['estado'] ) {
			try {
				foreach ( $repo->todas() as $p ) {
					if ( isset( $activos[ $p->num_socio_asde ] ) || isset( $equipo[ $p->num_socio_asde ] ) || ! $p->tiene_membresia_activa() ) {
						continue;
					}
					if ( Inscripciones::convocada( $e, $p ) ) {
						$convocados[ $p->num_socio_asde ] = $p;
					} elseif ( $p->es_participante() || $p->es_adulto_voluntario() ) {
						$otros[ $p->num_socio_asde ] = $p;
					}
				}
			} catch ( CrmException $ex ) {
				$convocados = array();
				$otros      = array();
			}
		}
		$orden = static fn( $x, $y ) => strcoll( remove_accents( $x->nombre_completo() ), remove_accents( $y->nombre_completo() ) );
		uasort( $convocados, $orden );
		uasort( $otros, $orden );
		return array(
			'filas'       => $filas,
			'personas'    => $personas + $convocados,
			'cobros'      => $cobros,
			'refs'        => $refs,
			'datos'       => $datos,
			'movs'        => $movs,
			'ocupadas'    => Inscripciones::ocupadas( $filas ),
			'espera'      => Inscripciones::lista_espera( $filas ),
			'sin_respuesta' => array_diff_key( $convocados, $con_fila ),
			'convocados'  => $convocados,
			'otros'       => $otros,
			'familias'    => Cobros::puede() && Cobros::abiertas( $e ) ? Cobros::familias( $e ) : array(),
			'economia'    => Cobros::puede(),
			'abiertas'    => Cobros::abiertas( $e ),
			'gestionable' => $gestiona && Inscripciones::gestionable( $e ),
		);
	}

	/** Lo que necesita la pestaña «Material» (v0.42, doc 43). */
	private function material( array $e, Ambito $a ): array {
		$lineas = Material::lineas( $e );
		$tipo   = Config::de()['material_por_tipo'][ $e['tipo'] ] ?? '';
		return array(
			'lineas'       => $lineas,
			'reservable'   => Material::reservable(),
			'habitual'     => '' !== trim( (string) $tipo ),
			'puede_editar' => Material::puede_editar( $e, $a ),
			'resumen'      => Material::resumen( $e ),
			'url_material' => $this->url( 'eventos', array( 'evento' => (string) $e['id'], 'pestana' => 'material' ) ),
			'url_config'   => $this->url( 'eventos_config' ),
			'url_inventario' => $this->url( 'inventario' ),
		);
	}

	/** Lo que necesita la pestaña «Menú» (v0.41, doc 49, B2). */
	private function menu( array $e, Ambito $a ): array {
		$url_menu = $this->url( 'eventos', array( 'evento' => (string) $e['id'], 'pestana' => 'menu' ) );
		// La hoja de cocina para imprimir: se compone en vivo, no se guarda y deja constancia.
		if ( '1' === $this->get( 'hoja' ) && HojaCocina::puede_generar( $e, $a ) ) {
			$hoja = HojaCocina::componer( $e );
			if ( 'ok' === $hoja['estado'] ) {
				HojaCocina::recordar( $e, $hoja, 'impresa' );
			}
			Logger::log( 'menu_hoja_generada', 'evento', (string) $e['id'] );
			return array( 'modo' => 'hoja', 'hoja' => $hoja, 'url_menu' => $url_menu );
		}
		$quienes = Validacion::puede_ver_quienes( $e, $a );
		$val     = Validacion::evaluar( $e, $quienes ); // En vivo: la salud no se guarda.
		$conf    = AvisosTable::de_evento( $e['id'] );
		if ( 'ok' === $val['estado'] ) {
			Logger::log( 'menu_validado', 'evento', (string) $e['id'], array( 'con_nombres' => $quienes ? 1 : 0 ) );
		}
		$entrega = null;
		if ( HojaCocina::puede_generar( $e, $a ) && null !== get_option( 'asde_ap_hoja_cocina_' . $e['id'], null ) ) {
			$entrega = HojaCocina::estado( $e, HojaCocina::componer( $e ) );
		}
		$dia    = in_array( $this->get( 'dia' ), MenuEvento::dias( $e ), true ) ? $this->get( 'dia' ) : '';
		$platos = MenuEvento::platos( $e );
		$por    = array();
		foreach ( $platos as $p ) {
			$por[ $p['plato']['dia'] ][ $p['plato']['comida'] ][] = $p;
		}
		$url = $url_menu;
		return array(
			'modo'        => 'menu',
			'validacion'  => $val,
			'nivel'       => Validacion::nivel_con_confirmaciones( $val, $conf ),
			'confirmaciones' => $conf,
			've_quienes'  => $quienes,
			'puede_hoja'  => HojaCocina::puede_generar( $e, $a ),
			'entrega'     => $entrega,
			'url_hoja'    => add_query_arg( 'hoja', '1', $url_menu ),
			'confirmantes' => array_filter( array_map( static fn( $c ) => ( $u = get_userdata( $c['por'] ) ) ? $u->display_name : '', $conf ) ),
			'dias'        => MenuEvento::dias( $e ),
			'por_dia'     => $por,
			'recetas'     => array_values( array_filter( RecetasTable::visibles(), static fn( $r ) => $r['activa'] ) ),
			'comensales'  => MenuEvento::comensales( $e ),
			'compra'      => MenuEvento::compra( $e, $dia ),
			'coste_total' => MenuEvento::compra( $e )['total'],
			'coste_dias'  => MenuEvento::coste_por_dia( $e ),
			'dia'         => $dia,
			'puede_editar' => MenuEvento::puede_editar( $e, $a ),
			'puede_partida' => Servicio::puede_editar_presupuesto( $e, $a ),
			'url_menu'    => $url,
			'url_csv'     => $this->url( 'eventos', array_filter( array( 'evento' => (string) $e['id'], 'formato' => 'csv', 'dia' => $dia, '_wpnonce' => wp_create_nonce( 'asde_menu_csv_' . ( $e['id'] ?? 0 ) ) ) ) ),
			'url_recetas' => $this->url( 'eventos_recetas' ),
		);
	}

	/** Descarga la lista de la compra del menú (se llama antes de pintar la página). */
	public function exportar_compra(): void {
		$e = EventosTable::obtener( (int) $this->get( 'evento' ) );
		if ( ! wp_verify_nonce( $this->get( '_wpnonce' ), 'asde_menu_csv_' . ( $e['id'] ?? 0 ) ) ) {
			wp_die( esc_html__( 'Download link not valid or expired.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), 403 );
		}
		if ( ! $e || ! MenuEvento::puede_ver( $e, Ambito::actual() ) ) {
			wp_die( esc_html__( 'Download link not valid or expired.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), 403 );
		}
		$dia = in_array( $this->get( 'dia' ), MenuEvento::dias( $e ), true ) ? $this->get( 'dia' ) : '';
		Logger::log( 'menu_compra_exportada', 'evento', (string) $e['id'] );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="compra-' . sanitize_file_name( EventosTable::numero( $e ) ) . '-' . wp_date( 'Ymd' ) . '.csv"' );
		echo MenuEvento::csv( $e, $dia ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- descarga CSV, no HTML
		exit;
	}

	/** Lo que necesita la pestaña «Calendario» (v0.40, doc 49). */
	private function calendario( array $e, Ambito $a, array $cfg, array $equipo, array $nombres ): array {
		$tareas = TareasTable::de_evento( $e['id'] );
		$hoy    = wp_date( 'Y-m-d' );
		// La formación del equipo se lee en vivo del CRM solo si alguna tarea la comprueba sola.
		$check  = in_array( 'titulos', array_column( $tareas, 'auto' ), true ) ? Servicio::comprobar_equipo( $e, $equipo, $nombres ) : null;
		$auto   = Tareas::automaticas( $e, $tareas, $check );
		$estado = array();
		$dias   = array();
		foreach ( $tareas as $t ) {
			$estado[ $t['id'] ] = Tareas::estado( $t, $hoy, $auto[ $t['id'] ] ?? null );
			$dias[ $t['id'] ]   = Tareas::dias( $t, $hoy );
		}
		$plantillas = array();
		foreach ( Plantillas::de_grupo( $cfg ) as $id => $p ) {
			if ( $p['tareas'] ) {
				$plantillas[ $id ] = Plantillas::nombre( (string) $id, $p );
			}
		}
		return array(
			'tareas'     => $tareas,
			'auto'       => $auto,
			'estado'     => $estado,
			'dias'       => $dias,
			'puede_marcar' => Tareas::puede_marcar( $e, $a ),
			'puede_editar' => Tareas::puede_editar( $e, $a ),
			'plantillas' => $plantillas,
			'propuesta'  => Plantillas::propuesta( $e['tipo'], $cfg ),
			'viernes'    => (bool) $cfg['calendario_viernes'],
		);
	}

	/** Lo que necesita la pestaña «Cuentas» (v0.36). */
	private function cuentas( array $e ): array {
		$personas = Cuentas::personas( $e );
		$nombres  = array();
		$fichas   = $this->personas( array_values( $personas ) );
		foreach ( $personas as $uid => $socio ) {
			$u               = get_userdata( $uid );
			$nombres[ $uid ] = isset( $fichas[ $socio ] ) ? $fichas[ $socio ]->nombre_completo() : ( $u ? $u->display_name : $socio );
		}
		$cerrado = null !== $e['cerrado_en'] && is_array( $e['balance'] );
		$balance = $cerrado ? $e['balance'] : Cuentas::balance( $e );
		return array(
			'gastos'     => GastosTable::de_evento( $e['id'] ),
			'anticipos'  => AnticiposTable::de_evento( $e['id'] ),
			'personas'   => $nombres,
			'partidas'   => PartidasTable::previstas( $e['id'] ),
			'balance'    => $balance,
			'por_cerrar' => $cerrado ? array() : Cuentas::por_cerrar( $e, $balance ),
			'economia'   => Cobros::puede(),
			'abiertas'   => Cuentas::abiertas( $e ),
			// v0.52: los movimientos del banco que Tesorería ha asociado a este evento (solo los ve quien usa el banco).
			'banco'      => Cobros::puede() && \AsdeBalears\AreaPrivada\Domain\Modulos::activo( \AsdeBalears\AreaPrivada\Domain\Modulos::BANCO ) ? \AsdeBalears\AreaPrivada\Tesoreria\Banco\BancoTable::revisados_de_evento( $e['id'] ) : array(),
			'tipos_banco' => \AsdeBalears\AreaPrivada\Tesoreria\Banco\TiposMovimiento::todos(),
		);
	}

	public function handle_post(): void {
		if ( ! Servicio::puede_entrar() ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), 403 );
		}
		$a      = Ambito::actual();
		$accion = $this->post( 'asde_action' );
		$in     = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification -- se comprueba abajo, por acción.

		if ( 'evento_crear' === $accion ) {
			check_admin_referer( 'asde_evento_nuevo' );
			$id = Servicio::crear( $a, $in );
			if ( is_wp_error( $id ) ) {
				$this->errores( $id, $in );
				return;
			}
			Notices::add( 'success', esc_html( sprintf( /* translators: %s: text */ __( 'Event %s created as a draft. Add the team and, if it has a cost, the budget.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), EventosTable::numero( EventosTable::obtener( $id ) ?? array( 'grupo' => '', 'id' => $id ) ) ) ) );
			$this->redirect( $this->url( 'eventos', array( 'evento' => (string) $id ) ) );
		}

		$e = EventosTable::obtener( (int) $this->post( 'evento' ) );
		check_admin_referer( 'asde_evento_' . ( $e['id'] ?? 0 ) );
		if ( ! $e || ! Servicio::ve( $e, $a ) ) {
			Notices::add( 'error', __( 'You do not have access to that event.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
			$this->redirect( $this->url( 'eventos' ) );
		}
		$pestana = isset( self::PESTANAS[ $this->post( 'pestana' ) ] ) ? $this->post( 'pestana' ) : 'resumen';
		$volver  = $this->url(
			'eventos',
			array(
				'evento'  => (string) $e['id'],
				'pestana' => $pestana,
			)
		);
		$num     = EventosTable::numero( $e );
		$hecho   = '';
		$r       = new \WP_Error( 'asde_evento', __( 'Unknown action.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
		$cifra   = static fn( string $v ): float => (float) str_replace( ',', '.', $v );

		// v0.36: con las cuentas cerradas el evento es de solo lectura; v0.36.1: Tesorería puede reabrirlas (P-162).
		if ( null !== $e['cerrado_en'] && ! in_array( $accion, array( 'evento_justificante', 'evento_informe', 'evento_reabrir_cuentas' ), true ) ) {
			Notices::add( 'error', __( 'This event\'s accounts are closed: they can no longer be changed.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
			$this->redirect( $volver );
		}

		switch ( $accion ) {
			case 'evento_guardar':
				$r = Servicio::guardar( $e, $a, $in );
				if ( is_wp_error( $r ) ) {
					$this->errores( $r, $in, $e['id'] );
					return;
				}
				$hecho   = sprintf( /* translators: %s: text */ __( 'Event %s saved.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $num );
				$movidas = Tareas::recalcular( EventosTable::obtener( $e['id'] ) ?? $e ); // v0.40: si cambian las fechas, las del calendario.
				if ( $movidas ) {
					$hecho .= ' ' . sprintf( /* translators: %d: number */ _n( 'The date of %d calendar task has changed.', 'The dates of %d calendar tasks have changed.', $movidas , 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $movidas );
				}
				$volver = $this->url( 'eventos', array( 'evento' => (string) $e['id'] ) );
				break;

			case 'evento_equipo_anadir':
				$r     = Servicio::anadir_equipo( $e, $a, $this->post( 'socio' ), sanitize_key( $this->post( 'papel' ) ) );
				$hecho = __( 'Added to the team.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' );
				break;

			case 'evento_equipo_papel':
				$r     = Servicio::cambiar_papel( $e, $a, (int) $this->post( 'fila' ), sanitize_key( $this->post( 'papel' ) ) );
				$hecho = __( 'Team updated.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' );
				break;

			case 'evento_equipo_quitar':
				$r     = Servicio::quitar_equipo( $e, $a, (int) $this->post( 'fila' ) );
				$hecho = __( 'Removed from the team.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' );
				break;

			case 'evento_prevision':
				$r     = Servicio::guardar_prevision( $e, $a, (int) $this->post( 'equipo_previsto' ), '1' === $this->post( 'margen_activo' ), (float) str_replace( ',', '.', $this->post( 'margen_pct' ) ) );
				$hecho = __( 'Forecast saved: the price has been recalculated.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' );
				break;

			case 'evento_partida_anadir':
				$r     = Servicio::anadir_partida( $e, $a, sanitize_key( $this->post( 'tipo' ) ), $this->post( 'concepto' ), sanitize_key( $this->post( 'reparto' ) ), $this->post( 'seccion' ), (float) str_replace( ',', '.', $this->post( 'importe' ) ) );
				$hecho = __( 'Item added to the budget.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' );
				break;

			case 'evento_autobuses':
				$r     = Servicio::anadir_autobuses( $e, $a, (int) $this->post( 'personas' ), (int) $this->post( 'plazas' ), (float) str_replace( ',', '.', $this->post( 'precio' ) ) );
				$hecho = __( 'Bus item added to the budget.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' );
				break;

			case 'evento_partida_quitar':
				$r     = Servicio::quitar_partida( $e, $a, (int) $this->post( 'partida' ) );
				$hecho = __( 'Item removed from the budget.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' );
				break;

			case 'evento_pedir_validacion':
				$r     = Servicio::pedir_validacion( $e, $a );
				$hecho = __( 'Budget sent to Treasury for validation.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' );
				break;

			case 'evento_validar':
				$r = Servicio::validar( $e );
				if ( ! is_wp_error( $r ) ) {
					$hecho = sprintf( /* translators: %s: text */ __( 'Budget of event %s validated: the price per section is now fixed.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $num );
				}
				break;

			case 'evento_reabrir':
				$r     = Servicio::reabrir( $e );
				$hecho = __( 'Budget reopened: it can be changed and will have to be validated again.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' );
				break;

			case 'evento_publicar':
				$avisar = \AsdeBalears\AreaPrivada\Eventos\Avisos::destinos_validos( $this->post_array( 'avisar' ) );
				$r      = Servicio::publicar( $e, $a, $avisar );
				$hecho  = sprintf( /* translators: %s: text */ __( 'Event %s published.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $num ) . ' ' . (
					array( 'familias' ) === $avisar ? __( 'Families will be notified by email.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) : (
						array( 'scouters' ) === $avisar ? __( 'Scouters will be notified by email.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) : (
							$avisar ? __( 'Families and scouters will be notified by email.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) : __( 'No email notice has been sent.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' )
						)
					)
				);
				break;

			case 'evento_despublicar':
				$r     = Servicio::despublicar( $e, $a );
				$hecho = sprintf( /* translators: %s: text */ __( 'Event %s is a draft again.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $num );
				break;

			case 'evento_cancelar':
				$r     = Servicio::cancelar( $e, $a, $this->post( 'motivo' ) );
				$hecho = sprintf( /* translators: %s: text */ __( 'Event %s cancelled.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $num );
				break;

			case 'evento_inscribir':
				$r = Inscripciones::inscribir_gestor( $e, $a, $this->post( 'socio' ) ?: $this->post( 'otro' ), $in );
				if ( ! is_wp_error( $r ) ) {
					$hecho = InscripcionesTable::ESPERA === $r['estado'] ? __( 'There were no places left: they remain on the waiting list.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) : __( 'Sign-up saved.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' );
				}
				break;

			case 'evento_papel':
				$r     = Inscripciones::papel( $e, $a, (int) $this->post( 'fila' ) );
				$hecho = __( 'Paper authorisation recorded.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' );
				break;

			case 'evento_baja':
				$r     = Inscripciones::baja( $e, $a, (int) $this->post( 'fila' ), $this->post( 'motivo' ), true );
				$hecho = __( 'Sign-up withdrawn.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' );
				break;

			case 'evento_pago':
				$importe = (float) str_replace( ',', '.', $this->post( 'importe' ) );
				$r       = Cobros::pagar( $e, (int) $this->post( 'fila' ), $importe, sanitize_key( $this->post( 'forma' ) ), $this->post( 'fecha' ), $this->post( 'referencia' ), $this->post( 'nota' ) );
				$hecho   = sprintf( /* translators: %s: text */ __( 'Payment of %s recorded.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), \AsdeBalears\AreaPrivada\Tesoreria\Calculadora::euros( $importe ) );
				break;

			case 'evento_pago_familia':
				$importe = (float) str_replace( ',', '.', $this->post( 'importe' ) );
				$r       = Cobros::pagar_familia( $e, (int) $this->post( 'familia' ), $importe, sanitize_key( $this->post( 'forma' ) ), $this->post( 'fecha' ), $this->post( 'referencia' ), $this->post( 'nota' ) );
				$hecho   = sprintf( /* translators: %s: text */ __( 'Payment of %s recorded and split among the family\'s sign-ups.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), \AsdeBalears\AreaPrivada\Tesoreria\Calculadora::euros( $importe ) );
				break;

			case 'evento_ajuste':
				$importe = abs( (float) str_replace( ',', '.', $this->post( 'importe' ) ) ) * ( 'recargo' === $this->post( 'sentido' ) ? 1 : -1 );
				$r       = Cobros::ajustar( $e, (int) $this->post( 'fila' ), $importe, $this->post( 'motivo' ) );
				$hecho   = __( 'Adjustment saved: the sign-up amount has changed.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' );
				break;

			case 'evento_anular':
				$r     = Cobros::anular( $e, (int) $this->post( 'movimiento' ) );
				$hecho = __( 'Transaction cancelled.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' );
				break;

			case 'evento_devolucion':
				$r     = Cobros::devolver( $e, (int) $this->post( 'fila' ), $cifra( $this->post( 'importe' ) ), sanitize_key( $this->post( 'forma' ) ), $this->post( 'fecha' ), $this->post( 'motivo' ) );
				$hecho = sprintf( /* translators: %s: text */ __( 'Refund of %s recorded.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), \AsdeBalears\AreaPrivada\Tesoreria\Calculadora::euros( $cifra( $this->post( 'importe' ) ) ) );
				break;

			// v0.36: cuentas del evento.
			case 'evento_gasto':
				$fichero = Justificantes::subido();
				$r       = is_wp_error( $fichero ) ? $fichero : Cuentas::proponer( $e, $a, $in, $fichero );
				$hecho   = Cobros::puede() ? __( 'Expense recorded.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) : __( 'Expense sent to Treasury for review.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' );
				break;

			case 'evento_ingreso':
				$r     = Cuentas::ingreso( $e, $in );
				$hecho = __( 'Income recorded.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' );
				break;

			case 'evento_gasto_retirar':
				$r     = Cuentas::retirar( $e, (int) $this->post( 'gasto' ) );
				$hecho = __( 'Expense withdrawn.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' );
				break;

			case 'evento_gasto_validar':
				$r     = Cuentas::validar( $e, (int) $this->post( 'gasto' ) );
				$hecho = __( 'Expense validated.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' );
				break;

			case 'evento_gasto_rechazar':
				$r     = Cuentas::rechazar( $e, (int) $this->post( 'gasto' ), $this->post( 'motivo' ) );
				$hecho = __( 'Expense rejected.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' );
				break;

			case 'evento_gasto_reembolsar':
				$r     = Cuentas::reembolsar( $e, (int) $this->post( 'gasto' ), $this->post( 'fecha' ), sanitize_key( $this->post( 'forma' ) ) );
				$hecho = __( 'Reimbursement recorded.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' );
				break;

			case 'evento_gasto_anular':
				$r     = Cuentas::anular( $e, (int) $this->post( 'gasto' ), $this->post( 'motivo' ) );
				$hecho = __( 'Transaction cancelled.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' );
				break;

			case 'evento_anticipo':
				$r     = Cuentas::anticipo( $e, (int) $this->post( 'persona' ), $cifra( $this->post( 'importe' ) ), $this->post( 'fecha' ), sanitize_key( $this->post( 'forma' ) ), $this->post( 'nota' ) );
				$hecho = __( 'Advance recorded.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' );
				break;

			case 'evento_anticipo_liquidar':
				$r = Cuentas::liquidar( $e, (int) $this->post( 'anticipo' ), $cifra( $this->post( 'devuelto' ) ), $this->post( 'nota' ) );
				if ( ! is_wp_error( $r ) ) {
					$hecho = abs( $r ) >= 0.01 ? sprintf( /* translators: %s: text */ __( 'Advance settled, with a difference of %s.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), \AsdeBalears\AreaPrivada\Tesoreria\Calculadora::euros( $r ) ) : __( 'Advance settled.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' );
				}
				break;

			case 'evento_anticipo_anular':
				$r     = Cuentas::anular_anticipo( $e, (int) $this->post( 'anticipo' ) );
				$hecho = __( 'Advance cancelled.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' );
				break;

			case 'evento_cerrar':
				$r     = Cuentas::cerrar( $e );
				$hecho = sprintf( /* translators: %s: text */ __( 'The accounts of event %s are closed: the balance becomes the final report.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $num );
				break;

			case 'evento_reabrir_cuentas':
				$r     = Cuentas::reabrir( $e, $this->post( 'motivo' ) );
				$hecho = sprintf( /* translators: %s: text */ __( 'The accounts of event %s are reopened: they can be changed now. When everything is done, close them again.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $num );
				break;

			case 'evento_justificante':
				$r = $this->ver_justificante( $e, $a, (int) $this->post( 'gasto' ) ); // Si se puede ver, lo envía y no vuelve.
				break;

			case 'evento_informe':
				$r = $this->informe_csv( $e, $a ); // Si se puede ver, lo envía y no vuelve.
				break;

			case 'tarea_aplicar':
				$r     = Tareas::aplicar( $e, sanitize_key( $this->post( 'plantilla' ) ), $a );
				$hecho = is_wp_error( $r ) ? '' : sprintf( /* translators: %d: number */ _n( '%d task has been created.', '%d tasks have been created.', (int) $r , 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), (int) $r );
				break;

			case 'material_anadir':
				$r     = Material::anadir( $e, $a, (int) $this->post( 'material' ), (int) $this->post( 'cantidad' ) );
				$hecho = __( 'Equipment reserved for the event.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' );
				break;

			case 'material_cantidad':
				$r     = Material::cambiar_cantidad( $e, $a, (int) $this->post( 'linea' ), (int) $this->post( 'cantidad' ) );
				$hecho = __( 'Quantity changed.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' );
				break;

			case 'material_quitar':
				$r     = Material::quitar( $e, $a, (int) $this->post( 'linea' ) );
				$hecho = __( 'Equipment removed from the event list.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' );
				break;

			case 'material_habitual':
				$r = Material::cargar_habitual( $e, $a );
				if ( ! is_wp_error( $r ) ) {
					$hecho = sprintf( /* translators: %d: number */ _n( '%d usual item has been reserved.', '%d usual items have been reserved.', $r['anadidas'] , 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $r['anadidas'] );
					if ( $r['no_encontradas'] ) {
						$hecho .= ' ' . sprintf( /* translators: %s: text */ __( 'They are not in the inventory: %s.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), implode( ', ', $r['no_encontradas'] ) );
					}
					$r = true;
				}
				break;

			case 'material_salida':
				$r = Material::salida( $e, $a, (int) $this->post( 'linea' ) );
				if ( ! is_wp_error( $r ) ) {
					$hecho = sprintf( /* translators: %d: number */ _n( 'Departure recorded: %d line.', 'Departure recorded: %d lines.', $r , 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $r );
					$r     = true;
				}
				break;

			case 'material_devolver':
				$r     = Material::devolver( $e, $a, (int) $this->post( 'linea' ), (int) $this->post( 'devueltas' ), $this->post( 'incidencia' ) );
				$hecho = __( 'Refund recorded.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' );
				break;

			case 'material_descontar':
				$r = Material::descontar_faltantes( $e, $a, (int) $this->post( 'linea' ) );
				if ( ! is_wp_error( $r ) ) {
					$hecho = sprintf( /* translators: %d: number */ _n( '%d unit has been deducted from the inventory.', '%d units have been deducted from the inventory.', $r , 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $r );
					$r     = true;
				}
				break;

			case 'menu_anadir':
				$r     = MenuEvento::anadir( $e, $a, $this->post( 'dia' ), $this->post( 'comida' ), (int) $this->post( 'receta' ), $this->post( 'comensales' ) );
				$hecho = __( 'Dish added to the menu.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' );
				break;

			case 'menu_quitar':
				$r     = MenuEvento::quitar( $e, $a, (int) $this->post( 'plato' ) );
				$hecho = __( 'Dish removed from the menu.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' );
				break;

			case 'menu_comensales':
				$r     = MenuEvento::cambiar_comensales( $e, $a, (int) $this->post( 'plato' ), $this->post( 'comensales' ) );
				$hecho = __( 'Diners changed.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' );
				break;

			case 'menu_confirmar':
				$r     = Validacion::confirmar( $e, $a, (int) $this->post( 'plato' ), sanitize_key( $this->post( 'alergeno' ) ), sanitize_key( $this->post( 'alternativa' ) ) );
				$hecho = __( 'Notice confirmed.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' );
				break;

			case 'menu_desconfirmar':
				$r     = MenuEvento::puede_editar( $e, $a ) && AvisosTable::quitar( $e['id'], (int) $this->post( 'plato' ), sanitize_key( $this->post( 'alergeno' ) ) ) ? true : new \WP_Error( 'asde_menus', __( 'You cannot change this event\'s menu.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
				$hecho = __( 'The confirmation has been removed: the notice is unresolved again.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' );
				break;

			case 'menu_hoja_enviar':
				$r = HojaCocina::enviar( $e, $a );
				if ( ! is_wp_error( $r ) ) {
					$hecho = sprintf( /* translators: %d: number */ _n( 'Kitchen sheet sent to %d person.', 'Kitchen sheet sent to %d people.', $r['enviados'] , 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $r['enviados'] ) . ( $r['sin_cuenta'] ? ' ' . sprintf( /* translators: %d: number */ _n( '%d support team member has no account: print it for them.', '%d support team members have no account: print them for them.', $r['sin_cuenta'] , 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $r['sin_cuenta'] ) : '' ) . ( $r['fallidos'] ? ' ' . sprintf( /* translators: %d: number */ _n( 'Sending to %d failed.', 'Sending to %d failed.', $r['fallidos'] , 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $r['fallidos'] ) : '' );
					$r     = true;
				}
				break;

			case 'menu_partida':
				$r     = MenuEvento::proponer_partida( $e, $a );
				$hecho = __( 'Food item added to the budget.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' );
				break;

			case 'tarea_hecha':
			case 'tarea_deshecha':
				$t     = TareasTable::obtener( (int) $this->post( 'tarea' ) );
				$r     = $t ? Tareas::marcar( $e, $t, 'tarea_hecha' === $accion, $a, $this->post( 'nota' ) ) : new \WP_Error( 'asde_tareas', __( 'That task does not exist.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
				$hecho = 'tarea_hecha' === $accion ? __( 'Task done.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) : __( 'The task is pending again.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' );
				break;

			case 'tarea_guardar':
				$t     = TareasTable::obtener( (int) $this->post( 'tarea' ) );
				$r     = $t ? Tareas::guardar( $e, $t, $this->post( 'responsable' ), $this->post( 'fecha' ), $this->post( 'nota' ), array_column( InscripcionesTable::equipo( $e['id'] ), 'socio' ), $a ) : new \WP_Error( 'asde_tareas', __( 'That task does not exist.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
				$hecho = __( 'Task saved.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' );
				break;

			case 'tarea_anadir':
				$r     = Tareas::anadir( $e, $this->post( 'titulo' ), $this->post( 'fecha' ), $this->post( 'detalle' ), $a );
				$hecho = __( 'Task added.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' );
				break;

			case 'tarea_quitar':
				$t     = TareasTable::obtener( (int) $this->post( 'tarea' ) );
				$r     = $t ? Tareas::quitar( $e, $t, $a ) : new \WP_Error( 'asde_tareas', __( 'That task does not exist.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
				$hecho = __( 'Task removed.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' );
				break;

			case 'evento_borrar':
				$r = Servicio::borrar( $e, $a );
				if ( ! is_wp_error( $r ) ) {
					Notices::add( 'success', esc_html( sprintf( /* translators: %s: text */ __( 'Draft %s deleted.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $num ) ) );
					$this->redirect( $this->url( 'eventos' ) );
				}
				break;
		}

		is_wp_error( $r ) ? Notices::add( 'error', esc_html( $r->get_error_message() ) ) : Notices::add( 'success', esc_html( $hecho ) );
		$this->redirect( $volver );
	}

	/**
	 * El justificante subido con el gasto: solo su ruta temporal (comprobada con
	 * is_uploaded_file()) y su nombre; null si no se ha subido ninguno.
	 *
	 * @return array{ruta:string, nombre:string}|\WP_Error|null
	 */
	/** Envía el justificante de un gasto (descifrado) al navegador (Justificantes::enviar()): solo quien ve las cuentas. */
	private function ver_justificante( array $e, Ambito $a, int $id ): \WP_Error {
		$g = GastosTable::obtener( $id );
		if ( ! Cuentas::puede_ver( $e, $a ) || ! $g || $g['evento_id'] !== $e['id'] || '' === $g['justificante'] ) {
			return new \WP_Error( 'asde_cuentas', __( 'That receipt does not exist or you cannot see it.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
		}
		$contenido = Justificantes::leer( $g );
		if ( null === $contenido || ! isset( Justificantes::TIPOS[ $g['justificante_tipo'] ] ) ) {
			return new \WP_Error( 'asde_cuentas', __( 'The receipt could not be read.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
		}
		Logger::log( 'evento_justificante_visto', 'evento', (string) $e['id'], array( 'gasto' => $id ) );
		Justificantes::enviar( $contenido, $g['justificante_tipo'], 'justificante-' . $id );
		exit; // Justificantes::enviar() ya termina.
	}

	/** El balance (o el informe final, si están cerradas) en CSV. */
	private function informe_csv( array $e, Ambito $a ): \WP_Error {
		if ( ! Cuentas::puede_ver( $e, $a ) ) {
			return new \WP_Error( 'asde_cuentas', __( 'You cannot see this event\'s accounts.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
		}
		$balance = null !== $e['cerrado_en'] && is_array( $e['balance'] ) ? $e['balance'] : Cuentas::balance( $e );
		Logger::log( 'evento_informe', 'evento', (string) $e['id'] );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="cuentas-' . sanitize_file_name( EventosTable::numero( $e ) ) . '-' . wp_date( 'Ymd' ) . '.csv"' );
		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- php://output (descarga), no es el sistema de ficheros
		foreach ( Cuentas::filas_csv( $balance ) as $fila ) {
			fputcsv( $out, array_map( array( Generador::class, 'celda_segura' ), $fila ), ';' );
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- php://output (descarga)
		exit;
	}

	/** Sin redirigir: se vuelve a enseñar el formulario con lo escrito y los errores. */
	private function errores( \WP_Error $error, array $in, int $evento = 0 ): void {
		$lista = (array) $error->get_error_data();
		foreach ( $lista ?: array( $error->get_error_message() ) as $mensaje ) {
			Notices::add( 'error', esc_html( (string) $mensaje ) );
		}
		self::$valores = array(
			'evento' => $evento,
			'datos'  => $in,
		);
	}
}
