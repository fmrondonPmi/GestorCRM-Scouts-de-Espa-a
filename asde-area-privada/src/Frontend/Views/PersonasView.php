<?php
namespace AsdeBalears\AreaPrivada\Frontend\Views;

use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Crm\CrmException;
use AsdeBalears\AreaPrivada\Crm\Duplicados;
use AsdeBalears\AreaPrivada\Crm\Escritura;
use AsdeBalears\AreaPrivada\Crm\Instantanea;
use AsdeBalears\AreaPrivada\Crm\PersonaRepository;
use AsdeBalears\AreaPrivada\Domain\Grupo;
use AsdeBalears\AreaPrivada\Domain\Persona;
use AsdeBalears\AreaPrivada\Domain\Secciones;
use AsdeBalears\AreaPrivada\Frontend\Notices;
use AsdeBalears\AreaPrivada\Frontend\Tabla;
use AsdeBalears\AreaPrivada\Socios\Numeracion;
use AsdeBalears\AreaPrivada\Socios\SociosTable;
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

	/** Resultado de «¿Está ya en el CRM?» de esta petición (POST sin redirección: el documento no se guarda). */
	private static ?array $comprobacion = null;

	public const FILTROS = array(
		'activas'     => 'Con membresía activa',
		'inactivas'   => 'Sin membresía activa',
		'todas'       => 'Todas',
		'incidencias' => 'Activas con incidencias en el nº de socio',
	);

	public function render( string $content = '' ): string {
		if ( ! current_user_can( self::CAP ) ) {
			return $this->denegado();
		}
		$repo = PersonaRepository::make();
		if ( ! $repo ) {
			return $this->error_crm( 'personas', asde_t( 'La conexión de tu grupo con el CRM no está configurada o no se ha podido comprobar. Un administrador puede revisarla en wp-admin → Grupos Scouts.' ) );
		}

		// «¿Está ya en el CRM?» (doc 34, fase 1): en la ventana emergente o en su página.
		if ( '' !== $this->get( 'comprobar' ) ) {
			return $this->layout(
				'personas',
				$this->tpl(
					'personas/comprobar',
					array(
						'resultado' => self::$comprobacion,
						'url'       => $this->url( 'personas', array( 'comprobar' => '1' ) ),
						'url_ficha' => $this->url( 'ficha' ),
						'volver'    => $this->url( 'personas' ),
					)
				)
			);
		}

		$filtro = isset( self::FILTROS[ $this->get( 'ver' ) ] ) ? $this->get( 'ver' ) : 'activas';
		$q      = mb_strtolower( remove_accents( $this->get( 'q' ) ) );

		try {
			// «Actualizar ahora»: vuelve a leer las fichas del CRM sin fiarse de la instantánea.
			if ( '1' === $this->get( 'actualizar' ) && wp_verify_nonce( $this->get( '_wpnonce' ), 'asde_actualizar_personas' ) && Instantanea::segundos() > 0 && Instantanea::puede_forzar() ) {
				$repo->refrescar_instantanea();
				Numeracion::olvidar_cache();
			}
			$todas    = $repo->todas( false );
			$num      = new Numeracion( $repo );
			$analisis = $num->analizar( $todas ); // Sincroniza la tabla de equivalencias.
		} catch ( CrmException $e ) {
			return $this->error_crm( 'personas', asde_t( 'No se ha podido consultar el CRM: ' ) . $e->getMessage() );
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
				'membresia' => static fn( Persona $p ) => $p->tiene_membresia_activa() && $p->secciones ? min( array_map( static fn( $s ) => $orden[ $s ] ?? 99, $p->secciones ) ) : '',
				'acceso'    => static fn( Persona $p ) => ( $usuarios[ $p->num_socio_asde ] ?? $familiares[ $p->num_socio_asde ] ?? null )?->user_login ?? '',
			),
			'nombre'
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
			wp_die( esc_html__( 'No tienes permiso para hacer esto.', 'asde-area-privada' ), 403 );
		}
		$accion = $this->post( 'asde_action' );
		if ( 'comprobar_documento' === $accion ) {
			check_admin_referer( 'asde_comprobar_documento' );
			// Sin redirigir: esta misma petición pinta el resultado, así el documento no
			// tiene que guardarse en ningún sitio para enseñarlo después.
			self::$comprobacion = $this->comprobar( $this->post( 'documento' ) );
			return;
		}
		$volver = wp_get_referer() ?: $this->url( 'personas' );
		$repo   = PersonaRepository::make();
		if ( ! $repo ) {
			Notices::add( 'error', asde_t( 'La conexión con el CRM no está disponible.' ) );
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
			Notices::add( 'error', asde_t( 'Petición no válida.' ) );
			$this->redirect( $volver );
		}
		check_admin_referer( 'asde_numero_' . $accion . '_' . $socio );

		try {
			$p = $repo->obtener( $socio );
			if ( ! $p ) {
				Notices::add( 'error', asde_t( 'No se encuentra esa persona en el CRM del grupo.' ) );
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
							? sprintf( asde_t( 'Nº de socio/a <strong>%s</strong> asignado a %s y guardado en el CRM.' ), esc_html( $r['numero'] ), $nombre )
							: sprintf( asde_t( 'Nº de socio/a <strong>%s</strong> asignado a %s, pero no se ha podido guardar en el CRM%s. 📌 Anótalo en el CRM (campo «Nº de socio/a local/territorial»).' ), esc_html( $r['numero'] ), $nombre, $r['error'] ? ': ' . esc_html( $r['error'] ) : asde_t( ' (el usuario del CRM no puede editar fichas)' ) )
					);
					break;

				case 'pasar_crm':
				case 'corregir_formato':
					$r = 'pasar_crm' === $accion ? $num->pasar_al_crm( $p ) : $num->corregir_formato( $p );
					is_wp_error( $r )
						? Notices::add( 'error', esc_html( $r->get_error_message() ) )
						: Notices::add( 'success', sprintf( asde_t( 'Nº de socio/a de %s guardado en el CRM con el formato correcto.' ), $nombre ) );
					break;

				case 'resolver_duplicado':
					$r = $num->resolver_duplicado( $p );
					if ( is_wp_error( $r ) ) {
						Notices::add( 'error', esc_html( $r->get_error_message() ) );
					} else {
						Logger::log( 'numero_duplicado_resuelto', 'socio', $socio, array( 'numero' => $r ) );
						Notices::add( 'success', sprintf( asde_t( '%s tiene ahora el nº de socio/a <strong>%s</strong> (guardado en el CRM). La otra ficha conserva el suyo.' ), $nombre, esc_html( $r ) ) );
					}
					break;
			}
		} catch ( CrmException $e ) {
			Notices::add( 'error', esc_html( asde_t( 'No se ha podido consultar el CRM: ' ) . $e->getMessage() ) );
		}
		$this->redirect( $volver );
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
			return $error( asde_t( 'Has hecho muchas comprobaciones seguidas. Espera un rato y vuelve a intentarlo.' ) );
		}
		$dup = Duplicados::make();
		if ( ! $dup ) {
			return $error( asde_t( 'La conexión con el CRM no está disponible.' ) );
		}
		try {
			$r = $dup->comprobar( $documento );
		} catch ( CrmException $e ) {
			return $error( asde_t( 'No se ha podido consultar el CRM: ' ) . $e->getMessage() );
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
			Notices::add( 'error', asde_t( 'Para corregir en el CRM hace falta que el usuario del CRM del grupo pueda editar fichas.' ) );
			return;
		}
		try {
			$analisis = $num->analizar_todo( true );
		} catch ( CrmException $e ) {
			Notices::add( 'error', esc_html( asde_t( 'No se ha podido consultar el CRM: ' ) . $e->getMessage() ) );
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
			Notices::add( 'info', asde_t( 'No hay fichas con esa incidencia.' ) );
			return;
		}
		$t = TareaNumeracion::crear( $accion, Grupo::numero(), get_current_user_id(), $total );
		if ( is_wp_error( $t ) ) {
			Notices::add( 'error', esc_html( $t->get_error_message() ) );
			return;
		}
		Notices::add( 'success', sprintf( asde_t( 'Corrección iniciada en segundo plano: %d fichas. Puedes seguir usando la web con normalidad; el avance se ve en el recuadro de arriba.' ), $total ) );
	}
}
