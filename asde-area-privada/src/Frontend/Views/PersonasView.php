<?php
namespace AsdeBalears\AreaPrivada\Frontend\Views;

use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Crm\Altas;
use AsdeBalears\AreaPrivada\Crm\CrmException;
use AsdeBalears\AreaPrivada\Crm\Duplicados;
use AsdeBalears\AreaPrivada\Crm\Escritura;
use AsdeBalears\AreaPrivada\Crm\Instantanea;
use AsdeBalears\AreaPrivada\Crm\PersonaRepository;
use AsdeBalears\AreaPrivada\Domain\Grupo;
use AsdeBalears\AreaPrivada\Domain\Persona;
use AsdeBalears\AreaPrivada\Domain\Secciones;
use AsdeBalears\AreaPrivada\Frontend\AltaSocio;
use AsdeBalears\AreaPrivada\ListaEspera\Inscripciones;
use AsdeBalears\AreaPrivada\Frontend\Notices;
use AsdeBalears\AreaPrivada\Frontend\Tabla;
use AsdeBalears\AreaPrivada\Socios\Numeracion;
use AsdeBalears\AreaPrivada\Socios\SociosTable;
use AsdeBalears\AreaPrivada\Socios\Eliminadas;
use AsdeBalears\AreaPrivada\Socios\TareaNumeracion;
use AsdeBalears\AreaPrivada\Usuarios\Usuarios;

defined( 'ABSPATH' ) || exit;

/**
 * Personas del grupo, activas y NO activas: la única página del área privada en
 * la que aparecen personas sin membresía activa. También revisa los números de
 * socio (formato, duplicados, faltantes) y permite asignar el siguiente número.
 */
final class PersonasView extends View {

	private const CAP = 'asde_manage_users';

	/**
	 * Paso del «Alta de socio/a» de esta petición (POST sin redirección: lo escrito no se guarda).
	 * La comprobación del documento solo está dentro del alta (v0.23.1, decisión del usuario):
	 * la pantalla suelta «¿Está ya en el CRM?» de la v0.21 se ha quitado.
	 */
	private static ?array $alta = null;

	/** Etiquetas traducidas al idioma de WordPress (doc 62). @return array<array-key, mixed> */
	public static function filtros(): array {
		return array(
		'activas'     => __( 'With an active membership', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
		'inactivas'   => __( 'No active membership', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
		'todas'       => __( 'All', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
		'incidencias' => __( 'Active with member number issues', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
	);
	}

	public function render( string $content = '' ): string {
		if ( ! current_user_can( self::CAP ) ) {
			return $this->denegado();
		}
		$repo = PersonaRepository::make();
		if ( ! $repo ) {
			return $this->error_crm( 'personas', __( 'Your group\'s connection to the CRM is not set up or could not be checked. An administrator can review it in wp-admin → Scout Groups.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
		}

		// «Alta de socio/a» (doc 34, fase 3): en la ventana emergente o en su página.
		if ( '' !== $this->get( 'alta' ) ) {
			return $this->layout( 'personas', $this->pantalla_alta() );
		}

		$filtro = isset( self::filtros()[ $this->get( 'ver' ) ] ) ? $this->get( 'ver' ) : 'activas';
		$q      = mb_strtolower( remove_accents( $this->get( 'q' ) ) );

		try {
			// «Actualizar ahora»: vuelve a leer las fichas del CRM sin fiarse de la instantánea.
			if ( '1' === $this->get( 'actualizar' ) && wp_verify_nonce( $this->get( '_wpnonce' ), 'asde_actualizar_personas' ) && Instantanea::segundos() > 0 && Instantanea::puede_forzar() ) {
				$repo->refrescar_instantanea();
				Numeracion::olvidar_cache();
			}
			$todas    = $repo->todas( false );
			// v0.35.1: sin las eliminadas de la web (mientras no vuelvan a tener una membresía activa).
			$eliminadas = Eliminadas::todas();
			if ( $eliminadas ) {
				$todas = array_values(
					array_filter(
						$todas,
						static function ( Persona $p ) use ( $eliminadas ): bool {
							if ( ! isset( $eliminadas[ $p->num_socio_asde ] ) ) {
								return true;
							}
							if ( $p->tiene_membresia_activa() ) {
								Eliminadas::quitar_marca( $p->num_socio_asde );
								return true;
							}
							return false;
						}
					)
				);
			}
			$num      = new Numeracion( $repo );
			$analisis = $num->analizar( $todas ); // Sincroniza la tabla de equivalencias.
		} catch ( CrmException $e ) {
			return $this->error_crm( 'personas', __( 'The CRM could not be queried: ', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) . $e->getMessage() );
		}

		$resumen = array(
			'activas'   => 0,
			'inactivas' => 0,
		);
		$incid   = array();
		foreach ( $todas as $p ) {
			$activa = $p->tiene_membresia_activa();
			++$resumen[ $activa ? 'activas' : 'inactivas' ];
			$estado = $analisis[ $p->num_socio_asde ]['estado'] ?? Numeracion::FALTA;
			if ( $activa && ! in_array( $estado, array( Numeracion::OK ), true ) ) {
				$incid[ $estado ] = ( $incid[ $estado ] ?? 0 ) + 1;
			}
		}

		$lista = array_values(
			array_filter(
				$todas,
				function ( Persona $p ) use ( $filtro, $analisis, $q ) {
					$activa = $p->tiene_membresia_activa();
					$estado = $analisis[ $p->num_socio_asde ]['estado'] ?? Numeracion::FALTA;
					$ok     = match ( $filtro ) {
						'activas'     => $activa,
						'inactivas'   => ! $activa,
						'incidencias' => $activa && Numeracion::OK !== $estado,
						default       => true,
					};
					if ( $ok && '' !== $q ) {
						$texto = mb_strtolower( remove_accents( $p->nombre_completo() . ' ' . $p->num_socio_asde . ' ' . ( $analisis[ $p->num_socio_asde ]['numero'] ?? '' ) ) );
						$ok    = str_contains( $texto, $q );
					}
					return $ok;
				}
			)
		);

		$usuarios   = Usuarios::por_socios( array_map( static fn( Persona $p ) => $p->num_socio_asde, $lista ) );
		$familiares = Usuarios::familiares_de( array_map( static fn( Persona $p ) => $p->num_socio_asde, $lista ) );
		$orden      = array_flip( array_keys( Secciones::all() ) );
		$tabla    = new Tabla(
			array(
				'nombre'    => static fn( Persona $p ) => $p->apellidos . ' ' . $p->nombre,
				'numero'    => static fn( Persona $p ) => (string) ( $analisis[ $p->num_socio_asde ]['numero'] ?? '' ),
				'asde'      => static fn( Persona $p ) => (int) $p->num_socio_asde,
				// Por sección (de la más pequeña a la mayor) y, dentro de cada una, por apellidos.
				'membresia' => static fn( Persona $p ) => $p->tiene_membresia_activa() && $p->secciones ? sprintf( '%02d %s %s', min( array_map( static fn( $s ) => $orden[ $s ] ?? 99, $p->secciones ) ), $p->apellidos, $p->nombre ) : '',
				'acceso'    => static fn( Persona $p ) => ( $usuarios[ $p->num_socio_asde ] ?? $familiares[ $p->num_socio_asde ] ?? null )?->user_login ?? '',
			),
			'membresia' // v0.31: por defecto, siempre por sección (petición del usuario).
		);
		$total = count( $lista );
		$pagina = $tabla->aplicar( $lista );

		// Corrección en segundo plano: su avance se ve arriba (monitor); aquí, las fichas que fallaron, con nombre.
		$tarea    = TareaNumeracion::para_usuario();
		$nombres  = array_column( array_map( static fn( Persona $p ) => array( $p->num_socio_asde, $p->nombre_completo() ), $todas ), 1, 0 );
		$fallidas = array();
		foreach ( (array) ( $tarea['fallidas'] ?? array() ) as $asde => $motivo ) {
			$fallidas[] = array(
				'asde'   => (string) $asde,
				'nombre' => $nombres[ (string) $asde ] ?? '',
				'motivo' => (string) $motivo,
			);
		}

		return $this->layout(
			'personas',
			$this->tpl(
				'personas/listado',
				array(
					'lista'      => $pagina,
					'total'      => $total,
					'tabla'      => $tabla,
					'analisis'   => $analisis,
					'resumen'    => $resumen,
					'incid'      => $incid,
					'filtro'     => $filtro,
					'q'          => $this->get( 'q' ),
					'siguiente'  => $num->siguiente(),
					'generados'  => SociosTable::generados_pendientes(),
					'usuarios'   => $usuarios,
					'familiares' => $familiares,
					'escritura'  => Escritura::disponible(),
					'altas'      => Altas::disponible(),
					'en_marcha'  => $tarea && TareaNumeracion::EN_CURSO === $tarea['estado'],
					'fallidas'   => $fallidas,
					'url'        => $this->url( 'personas' ),
					'url_alta'   => $this->url( 'alta' ),
					'url_ficha'  => $this->url( 'ficha' ),
					'edad_datos' => Instantanea::segundos() > 0 ? Instantanea::edad( Grupo::numero() ) : null,
				)
			)
		);
	}

	/** Acciones sobre el número de UNA ficha. */
	private const ACCIONES_FICHA = array( 'asignar_numero', 'pasar_crm', 'corregir_formato', 'resolver_duplicado' );

	/** Acciones sobre todas las fichas con una incidencia. */
	private const ACCIONES_TODAS = array( 'corregir_todos', 'pasar_todos', 'resolver_duplicados' );

	public function handle_post(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), 403 );
		}
		$accion = $this->post( 'asde_action' );
		if ( in_array( $accion, AltaSocio::ACCIONES, true ) ) {
			check_admin_referer( 'asde_alta_socio' );
			// Sin redirigir: cada paso se pinta en esta misma petición y lo escrito va en el
			// formulario, así no tiene que guardarse en ningún sitio para enseñarlo después.
			if ( Altas::disponible() ) {
				$campos = $this->campos_alta();
				$espera = $this->inscripcion_espera( (int) $campos['espera'] );
				if ( ! $espera ) {
					$campos['espera'] = '';
				} elseif ( 'alta_documento' === $accion ) {
					// v0.32 (doc 39): desde la lista de espera, sus datos se descifran aquí (nunca van en el HTML de la lista).
					$campos['d']       = Inscripciones::datos_alta( $espera ) + $campos['d'];
					$campos['seccion'] = '' !== $campos['seccion'] ? $campos['seccion'] : $espera['seccion_final'];
					// Doc 59, RC-NUE-1: el botón de la lista solo manda la inscripción; el documento sale de ella (con él se
					// comprueba antes en el CRM; sin él, menores de 14, se sigue sin comprobarlo).
					if ( '' === $campos['documento'] && '' === $campos['sin_documento'] ) {
						if ( '' !== $espera['datos']['documento'] ) {
							$campos['documento'] = $espera['datos']['documento'];
						} else {
							$campos['sin_documento'] = '1';
						}
					}
				}
				self::$alta = AltaSocio::atender( $accion, $campos, fn( string $d ) => $this->comprobar( $d ), Altas::make() );
				if ( $espera && AltaSocio::HECHO === self::$alta['paso'] ) {
					// Ya está en el CRM: la inscripción pasa a «Asociado/a» y sus datos se vacían en el momento (RC-NUE-1).
					self::$alta['espera_asociada'] = Inscripciones::asociar( $espera, (string) ( self::$alta['resultado']['numero'] ?? '' ) );
				}
			}
			return;
		}
		$volver = wp_get_referer() ?: $this->url( 'personas' );
		$repo   = PersonaRepository::make();
		if ( ! $repo ) {
			Notices::add( 'error', __( 'The connection to the CRM is not available.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
			$this->redirect( $volver );
		}
		$num = new Numeracion( $repo );

		if ( in_array( $accion, self::ACCIONES_TODAS, true ) ) {
			check_admin_referer( 'asde_numeracion_todas' );
			$this->iniciar_tarea( $accion, $num );
			$this->redirect( $volver );
		}

		$socio = $this->post( 'socio' );
		if ( ! in_array( $accion, self::ACCIONES_FICHA, true ) || ! PersonaRepository::es_num_asde( $socio ) ) {
			Notices::add( 'error', __( 'Invalid request.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
			$this->redirect( $volver );
		}
		check_admin_referer( 'asde_numero_' . $accion . '_' . $socio );

		try {
			$p = $repo->obtener( $socio );
			if ( ! $p ) {
				Notices::add( 'error', __( 'That person cannot be found in the group\'s CRM.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
				$this->redirect( $volver );
			}
			$nombre = esc_html( $p->nombre_completo() );
			switch ( $accion ) {
				case 'asignar_numero':
					$r = $num->asignar_y_guardar( $p );
					if ( is_wp_error( $r ) ) {
						Notices::add( 'error', esc_html( $r->get_error_message() ) );
						break;
					}
					Logger::log( 'numero_socio_asignado', 'socio', $socio, array( 'numero' => $r['numero'], 'crm' => $r['crm'] ) );
					Notices::add(
						$r['crm'] ? 'success' : 'warning',
						$r['crm']
							? sprintf( /* translators: 1: text, 2: text */ __( 'Member no. <strong>%1$s</strong> assigned to %2$s and saved in the CRM.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), esc_html( $r['numero'] ), $nombre )
							: sprintf( /* translators: 1: text, 2: text, 3: text */ __( 'Member no. <strong>%1$s</strong> assigned to %2$s, but it could not be saved in the CRM%3$s. 📌 Note it in the CRM ("Local/territorial member no." field).', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), esc_html( $r['numero'] ), $nombre, $r['error'] ? ': ' . esc_html( $r['error'] ) : __( ' (the CRM user cannot edit records)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) )
					);
					break;

				case 'pasar_crm':
				case 'corregir_formato':
					$r = 'pasar_crm' === $accion ? $num->pasar_al_crm( $p ) : $num->corregir_formato( $p );
					is_wp_error( $r )
						? Notices::add( 'error', esc_html( $r->get_error_message() ) )
						: Notices::add( 'success', sprintf( /* translators: %s: text */ __( 'Member no. of %s saved in the CRM in the correct format.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $nombre ) );
					break;

				case 'resolver_duplicado':
					$r = $num->resolver_duplicado( $p );
					if ( is_wp_error( $r ) ) {
						Notices::add( 'error', esc_html( $r->get_error_message() ) );
					} else {
						Logger::log( 'numero_duplicado_resuelto', 'socio', $socio, array( 'numero' => $r ) );
						Notices::add( 'success', sprintf( /* translators: 1: text, 2: text */ __( '%1$s now has member no. <strong>%2$s</strong> (saved in the CRM). The other record keeps theirs.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $nombre, esc_html( $r ) ) );
					}
					break;
			}
		} catch ( CrmException $e ) {
			Notices::add( 'error', esc_html( __( 'The CRM could not be queried: ', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) . $e->getMessage() ) );
		}
		$this->redirect( $volver );
	}

	/** «Alta de socio/a»: el paso que toca (o por qué no se puede). */
	private function pantalla_alta(): string {
		$disponible = Altas::disponible();
		return $this->tpl(
			'personas/alta',
			array(
				's'          => $disponible ? ( self::$alta ?? AltaSocio::inicio() ) : null,
				'motivo'     => Altas::activo() ? __( 'Your group\'s CRM user does not have permission to create people and memberships. An administrator can review it in wp-admin → Scout Groups.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) : __( 'Registrations in the CRM are not enabled for your group.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
				'url'        => $this->url( 'personas', array( 'alta' => '1' ) ),
				'url_ficha'  => $this->url( 'ficha' ),
				'url_acceso' => $this->url( 'alta' ),
				'volver'     => $this->url( 'personas' ),
			)
		);
	}

	/** Inscripción de la lista de espera de la que viene el alta (solo para quien puede dar de alta desde ella). */
	private function inscripcion_espera( int $id ): ?array {
		$f = $id && current_user_can( ListaEsperaView::CAP_ALTA ) ? Inscripciones::obtener( $id ) : null;
		return $f && Inscripciones::ASOCIADA !== $f['estado'] ? $f : null; // Una «Asociado/a» ya no tiene datos.
	}

	/** Campos que envían los pasos del alta. */
	private function campos_alta(): array {
		$out = array( 'd' => $this->post_array( 'd' ) );
		foreach ( array( 'documento', 'sin_documento', 'sin_doc', 'seccion', 'inicio', 'comprobado', 'otros', 'confirmo', 'corregir', 'espera' ) as $c ) {
			$out[ $c ] = $this->post( $c );
		}
		return $out;
	}

	/**
	 * ¿Tiene ya ficha la persona con este documento? Con un límite por persona y hora:
	 * el recuento federal no debe servir para recorrer documentos.
	 *
	 * @return array Duplicados::comprobar() + 'escrito'
	 */
	private function comprobar( string $documento ): array {
		$error = static fn( string $texto ): array => array(
			'ok'       => false,
			'error'    => $texto,
			'escrito'  => $documento,
			'en_grupo' => array(),
			'otros'    => null,
		);
		$clave = 'asde_ap_comprobaciones_' . get_current_user_id();
		$hechas = (int) get_transient( $clave );
		if ( $hechas >= Duplicados::MAX_POR_HORA ) {
			return $error( __( 'You have made many checks in a row. Wait a while and try again.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
		}
		$dup = Duplicados::make();
		if ( ! $dup ) {
			return $error( __( 'The connection to the CRM is not available.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
		}
		try {
			$r = $dup->comprobar( $documento );
		} catch ( CrmException $e ) {
			return $error( __( 'The CRM could not be queried: ', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) . $e->getMessage() );
		}
		if ( $r['ok'] ) {
			set_transient( $clave, $hechas + 1, HOUR_IN_SECONDS );
		}
		return $r + array( 'escrito' => $documento );
	}

	/**
	 * Botones «para todas»: crean la tarea en segundo plano y la pantalla vuelve
	 * enseguida. El avance se ve en el monitor (arriba, en todo el área privada).
	 */
	private function iniciar_tarea( string $accion, Numeracion $num ): void {
		if ( ! Escritura::disponible() ) {
			Notices::add( 'error', __( 'To correct in the CRM, the group\'s CRM user must be able to edit records.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
			return;
		}
		try {
			$analisis = $num->analizar_todo( true );
		} catch ( CrmException $e ) {
			Notices::add( 'error', esc_html( __( 'The CRM could not be queried: ', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) . $e->getMessage() ) );
			return;
		}
		$estado = array(
			'corregir_todos'      => Numeracion::CORREGIDO,
			'pasar_todos'         => Numeracion::GENERADO,
			'resolver_duplicados' => Numeracion::DUPLICADO,
		)[ $accion ];
		$afectadas = array_filter( $analisis, static fn( $r ) => $estado === $r['estado'] );
		$total     = count( $afectadas );
		if ( Numeracion::DUPLICADO === $estado ) {
			// Cada número repetido lo conserva una ficha: cambian las demás.
			$total -= count( array_unique( array_map( 'strval', array_column( $afectadas, 'numero' ) ) ) );
		}
		if ( $total <= 0 ) {
			Notices::add( 'info', __( 'There are no records with that issue.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
			return;
		}
		$t = TareaNumeracion::crear( $accion, Grupo::numero(), get_current_user_id(), $total );
		if ( is_wp_error( $t ) ) {
			Notices::add( 'error', esc_html( $t->get_error_message() ) );
			return;
		}
		Notices::add( 'success', sprintf( /* translators: %d: number */ __( 'Correction started in the background: %d records. You can keep using the website normally; progress is shown in the box above.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $total ) );
	}
}
