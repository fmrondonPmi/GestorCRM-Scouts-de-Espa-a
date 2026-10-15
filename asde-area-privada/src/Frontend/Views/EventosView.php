<?php
namespace AsdeBalears\AreaPrivada\Frontend\Views;

use AsdeBalears\AreaPrivada\Auth\Ambito;
use AsdeBalears\AreaPrivada\Crm\CrmException;
use AsdeBalears\AreaPrivada\Crm\PersonaRepository;
use AsdeBalears\AreaPrivada\Eventos\Cobros;
use AsdeBalears\AreaPrivada\Eventos\Config;
use AsdeBalears\AreaPrivada\Eventos\EventosTable;
use AsdeBalears\AreaPrivada\Eventos\Inscripciones;
use AsdeBalears\AreaPrivada\Eventos\InscripcionesTable;
use AsdeBalears\AreaPrivada\Eventos\MovimientosTable;
use AsdeBalears\AreaPrivada\Eventos\Normativa;
use AsdeBalears\AreaPrivada\Eventos\PartidasTable;
use AsdeBalears\AreaPrivada\Eventos\Presupuesto;
use AsdeBalears\AreaPrivada\Eventos\Servicio;
use AsdeBalears\AreaPrivada\Frontend\Notices;

defined( 'ABSPATH' ) || exit;

/**
 * Eventos del grupo (v0.19.0, doc 32): la agenda, el formulario del evento y su ficha con
 * cuatro pestañas: resumen (con la lista de comprobación de la normativa), equipo dirigente,
 * inscripciones (v0.19.1: participantes, lista de espera, autorizaciones en papel, bajas y,
 * para Tesorería, los cobros) y presupuesto (con el precio por sección y su validación).
 */
final class EventosView extends EventosBaseView {

	public const PESTANAS = array(
		'resumen'       => 'Resumen',
		'equipo'        => 'Equipo',
		'inscripciones' => 'Inscripciones',
		'presupuesto'   => 'Presupuesto',
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
				return $this->denegado( asde_t( 'No puedes crear eventos.' ) );
			}
			return $this->layout( 'eventos', $this->formulario( null, $a, $cfg ) );
		}

		$id = (int) $this->get( 'evento' );
		if ( $id ) {
			$e = EventosTable::obtener( $id );
			if ( ! $e || ! Servicio::ve( $e, $a ) ) {
				return $this->denegado( asde_t( 'No tienes acceso a ese evento.' ) );
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
			'familias'    => Cobros::puede() ? Cobros::familias( $e ) : array(),
			'economia'    => Cobros::puede(),
			'gestionable' => $gestiona && Inscripciones::gestionable( $e ),
		);
	}

	public function handle_post(): void {
		if ( ! Servicio::puede_entrar() ) {
			wp_die( esc_html__( 'No tienes permiso para hacer esto.', 'asde-area-privada' ), 403 );
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
			Notices::add( 'success', esc_html( sprintf( asde_t( 'Evento %s creado como borrador. Añade el equipo y, si tiene coste, el presupuesto.' ), EventosTable::numero( EventosTable::obtener( $id ) ?? array( 'grupo' => '', 'id' => $id ) ) ) ) );
			$this->redirect( $this->url( 'eventos', array( 'evento' => (string) $id ) ) );
		}

		$e = EventosTable::obtener( (int) $this->post( 'evento' ) );
		check_admin_referer( 'asde_evento_' . ( $e['id'] ?? 0 ) );
		if ( ! $e || ! Servicio::ve( $e, $a ) ) {
			Notices::add( 'error', asde_t( 'No tienes acceso a ese evento.' ) );
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
		$r       = new \WP_Error( 'asde_evento', asde_t( 'Acción desconocida.' ) );

		switch ( $accion ) {
			case 'evento_guardar':
				$r = Servicio::guardar( $e, $a, $in );
				if ( is_wp_error( $r ) ) {
					$this->errores( $r, $in, $e['id'] );
					return;
				}
				$hecho  = sprintf( asde_t( 'Evento %s guardado.' ), $num );
				$volver = $this->url( 'eventos', array( 'evento' => (string) $e['id'] ) );
				break;

			case 'evento_equipo_anadir':
				$r     = Servicio::anadir_equipo( $e, $a, $this->post( 'socio' ), sanitize_key( $this->post( 'papel' ) ) );
				$hecho = asde_t( 'Añadido al equipo.' );
				break;

			case 'evento_equipo_papel':
				$r     = Servicio::cambiar_papel( $e, $a, (int) $this->post( 'fila' ), sanitize_key( $this->post( 'papel' ) ) );
				$hecho = asde_t( 'Equipo actualizado.' );
				break;

			case 'evento_equipo_quitar':
				$r     = Servicio::quitar_equipo( $e, $a, (int) $this->post( 'fila' ) );
				$hecho = asde_t( 'Quitado del equipo.' );
				break;

			case 'evento_prevision':
				$r     = Servicio::guardar_prevision( $e, $a, (int) $this->post( 'equipo_previsto' ), '1' === $this->post( 'margen_activo' ), (float) str_replace( ',', '.', $this->post( 'margen_pct' ) ) );
				$hecho = asde_t( 'Previsión guardada: el precio se ha recalculado.' );
				break;

			case 'evento_partida_anadir':
				$r     = Servicio::anadir_partida( $e, $a, sanitize_key( $this->post( 'tipo' ) ), $this->post( 'concepto' ), sanitize_key( $this->post( 'reparto' ) ), $this->post( 'seccion' ), (float) str_replace( ',', '.', $this->post( 'importe' ) ) );
				$hecho = asde_t( 'Partida añadida al presupuesto.' );
				break;

			case 'evento_partida_quitar':
				$r     = Servicio::quitar_partida( $e, $a, (int) $this->post( 'partida' ) );
				$hecho = asde_t( 'Partida quitada del presupuesto.' );
				break;

			case 'evento_pedir_validacion':
				$r     = Servicio::pedir_validacion( $e, $a );
				$hecho = asde_t( 'Presupuesto enviado a Tesorería para que lo valide.' );
				break;

			case 'evento_validar':
				$r = Servicio::validar( $e );
				if ( ! is_wp_error( $r ) ) {
					$hecho = sprintf( asde_t( 'Presupuesto del evento %s validado: el precio por sección ya es fijo.' ), $num );
				}
				break;

			case 'evento_reabrir':
				$r     = Servicio::reabrir( $e );
				$hecho = asde_t( 'Presupuesto reabierto: se puede cambiar y habrá que volver a validarlo.' );
				break;

			case 'evento_publicar':
				$r     = Servicio::publicar( $e, $a );
				$hecho = sprintf( asde_t( 'Evento %s publicado.' ), $num );
				break;

			case 'evento_despublicar':
				$r     = Servicio::despublicar( $e, $a );
				$hecho = sprintf( asde_t( 'El evento %s vuelve a ser un borrador.' ), $num );
				break;

			case 'evento_cancelar':
				$r     = Servicio::cancelar( $e, $a, $this->post( 'motivo' ) );
				$hecho = sprintf( asde_t( 'Evento %s cancelado.' ), $num );
				break;

			case 'evento_inscribir':
				$r = Inscripciones::inscribir_gestor( $e, $a, $this->post( 'socio' ) ?: $this->post( 'otro' ), $in );
				if ( ! is_wp_error( $r ) ) {
					$hecho = InscripcionesTable::ESPERA === $r['estado'] ? asde_t( 'No quedaban plazas: queda en la lista de espera.' ) : asde_t( 'Inscripción guardada.' );
				}
				break;

			case 'evento_papel':
				$r     = Inscripciones::papel( $e, $a, (int) $this->post( 'fila' ) );
				$hecho = asde_t( 'Autorización en papel anotada.' );
				break;

			case 'evento_baja':
				$r     = Inscripciones::baja( $e, $a, (int) $this->post( 'fila' ), $this->post( 'motivo' ), true );
				$hecho = asde_t( 'Inscripción dada de baja.' );
				break;

			case 'evento_pago':
				$importe = (float) str_replace( ',', '.', $this->post( 'importe' ) );
				$r       = Cobros::pagar( $e, (int) $this->post( 'fila' ), $importe, sanitize_key( $this->post( 'forma' ) ), $this->post( 'fecha' ), $this->post( 'referencia' ), $this->post( 'nota' ) );
				$hecho   = sprintf( asde_t( 'Pago de %s registrado.' ), \AsdeBalears\AreaPrivada\Tesoreria\Calculadora::euros( $importe ) );
				break;

			case 'evento_pago_familia':
				$importe = (float) str_replace( ',', '.', $this->post( 'importe' ) );
				$r       = Cobros::pagar_familia( $e, (int) $this->post( 'familia' ), $importe, sanitize_key( $this->post( 'forma' ) ), $this->post( 'fecha' ), $this->post( 'referencia' ), $this->post( 'nota' ) );
				$hecho   = sprintf( asde_t( 'Pago de %s registrado y repartido entre las inscripciones de la familia.' ), \AsdeBalears\AreaPrivada\Tesoreria\Calculadora::euros( $importe ) );
				break;

			case 'evento_ajuste':
				$importe = abs( (float) str_replace( ',', '.', $this->post( 'importe' ) ) ) * ( 'recargo' === $this->post( 'sentido' ) ? 1 : -1 );
				$r       = Cobros::ajustar( $e, (int) $this->post( 'fila' ), $importe, $this->post( 'motivo' ) );
				$hecho   = asde_t( 'Ajuste guardado: el importe de la inscripción ha cambiado.' );
				break;

			case 'evento_anular':
				$r     = Cobros::anular( $e, (int) $this->post( 'movimiento' ) );
				$hecho = asde_t( 'Movimiento anulado.' );
				break;

			case 'evento_borrar':
				$r = Servicio::borrar( $e, $a );
				if ( ! is_wp_error( $r ) ) {
					Notices::add( 'success', esc_html( sprintf( asde_t( 'Borrador %s borrado.' ), $num ) ) );
					$this->redirect( $this->url( 'eventos' ) );
				}
				break;
		}

		is_wp_error( $r ) ? Notices::add( 'error', esc_html( $r->get_error_message() ) ) : Notices::add( 'success', esc_html( $hecho ) );
		$this->redirect( $volver );
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
