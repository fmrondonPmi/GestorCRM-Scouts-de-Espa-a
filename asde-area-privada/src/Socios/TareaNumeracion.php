<?php
namespace AsdeBalears\AreaPrivada\Socios;

use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Crm\CrmException;
use AsdeBalears\AreaPrivada\Crm\Escritura;
use AsdeBalears\AreaPrivada\Crm\PersonaRepository;
use AsdeBalears\AreaPrivada\Domain\Grupo;
use AsdeBalears\AreaPrivada\Domain\Grupos;

defined( 'ABSPATH' ) || exit;

/**
 * Correcciones masivas de números de socio EN SEGUNDO PLANO (una tarea por grupo).
 *
 * Por qué: escribir cientos de fichas en el CRM lleva minutos (≈1,2 s cada una).
 * En una sola petición la pantalla se quedaba «congelada» y el hosting (o un
 * reinicio del servidor) la cortaba a medias. Ahora:
 *
 * - el botón solo CREA la tarea y la pantalla vuelve enseguida;
 * - un trabajador hace TANDAS cortas (10 s): relee el grupo del CRM, corrige lo
 *   que quepa y se vuelve a lanzar él mismo con una petición interna que no
 *   espera respuesta. De respaldo, WP-Cron; y si la tarea lleva rato parada,
 *   la reactiva el propio monitor al consultar el estado;
 * - una ficha se corrige entera o no se toca, y repetirla no hace daño: en cada
 *   tanda se vuelve a comprobar su estado en el CRM;
 * - las fichas que fallan se apuntan y no se reintentan en bucle;
 * - se puede detener. Solo guarda números de socio y contadores (sin nombres).
 */
final class TareaNumeracion {

	/** Etiquetas traducidas al idioma de WordPress (doc 62). @return array<array-key, mixed> */
	public static function acciones(): array {
		return array(
		'corregir_todos'      => __( 'Correct the old formats in the CRM', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
		'pasar_todos'         => __( 'Transfer the generated numbers to the CRM', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
		'resolver_duplicados' => __( 'Resolve the duplicates', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
	);
	}

	public const EN_CURSO  = 'en_curso';
	public const TERMINADA = 'terminada';
	public const CANCELADA = 'cancelada';
	public const ERROR     = 'error';

	/** Quién puede lanzarlas y ver el monitor (la misma capacidad que Personas). */
	public const CAP = 'asde_manage_users';

	public const HOOK_CRON = 'asde_ap_tarea_numeracion';

	private const AJAX_TRABAJAR = 'asde_ap_tarea';
	private const OPCION        = 'asde_ap_tarea_num_';
	private const CERROJO       = 'asde_ap_tarea_lock_';

	/** Segundos sin avanzar para que el monitor la reactive. */
	private const PARADA = 45;

	/** Tandas seguidas sin poder leer el CRM antes de rendirse. */
	private const MAX_FALLOS_CRM = 5;

	public static function register(): void {
		add_action( 'wp_ajax_nopriv_' . self::AJAX_TRABAJAR, array( self::class, 'ajax_trabajar' ) );
		add_action( 'wp_ajax_' . self::AJAX_TRABAJAR, array( self::class, 'ajax_trabajar' ) );
		add_action( 'wp_ajax_asde_ap_tarea_estado', array( self::class, 'ajax_estado' ) );
		add_action( 'wp_ajax_asde_ap_tarea_detener', array( self::class, 'ajax_detener' ) );
		add_action( 'wp_ajax_asde_ap_tarea_cerrar', array( self::class, 'ajax_cerrar' ) );
		add_action( self::HOOK_CRON, array( self::class, 'cron' ) );
	}

	// ---------------------------------------------------------------------
	// Estado
	// ---------------------------------------------------------------------

	/**
	 * @param bool $fresco Leer de la base de datos (otra petición puede haberla cambiado).
	 * @return array<string, mixed>|null
	 */
	public static function actual( string $grupo, bool $fresco = false ): ?array {
		if ( $fresco ) {
			wp_cache_delete( self::OPCION . $grupo, 'options' );
		}
		$t = get_option( self::OPCION . $grupo );
		return is_array( $t ) && isset( $t['id'], $t['accion'], $t['estado'], $t['grupo'] ) ? $t : null;
	}

	/** La tarea del grupo del usuario conectado, si puede verla. */
	public static function para_usuario(): ?array {
		if ( ! is_user_logged_in() || ! current_user_can( self::CAP ) ) {
			return null;
		}
		$grupo = Grupo::numero();
		return '' !== $grupo ? self::actual( $grupo ) : null;
	}

	private static function guardar( array $t ): void {
		update_option( self::OPCION . $t['grupo'], $t, false );
	}

	/**
	 * Crea la tarea (una a la vez por grupo) y lanza la primera tanda.
	 *
	 * @param int $total Fichas con la incidencia al crearla (para el avance).
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function crear( string $accion, string $grupo, int $usuario, int $total ) {
		if ( ! isset( self::acciones()[ $accion ] ) || '' === $grupo ) {
			return new \WP_Error( 'asde_tarea', __( 'Invalid task.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
		}
		$previa = self::actual( $grupo, true );
		if ( $previa && self::EN_CURSO === $previa['estado'] ) {
			return new \WP_Error( 'asde_tarea', __( 'A correction is already running in your group: wait for it to finish or stop it.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
		}
		$t = array(
			'id'          => wp_generate_password( 12, false ),
			'token'       => wp_generate_password( 32, false ),
			'accion'      => $accion,
			'grupo'       => $grupo,
			'usuario'     => $usuario,
			'estado'      => self::EN_CURSO,
			'creada'      => time(),
			'actualizada' => time(),
			'terminada'   => 0,
			'total'       => $total,
			'hechas'      => 0,
			'pendientes'  => $total,
			'fallidas'    => array(), // nº Scouts de España => motivo
			'tandas'      => 0,
			'fallos_crm'  => 0,
			'aviso'       => '',
			// Creada por las pruebas automáticas (CRM simulado): nunca la ejecuta
			// una petición normal, que escribiría en el CRM real.
			'prueba'      => Escritura::en_pruebas(),
		);
		self::guardar( $t );
		Logger::log(
			'numeros_tarea_iniciada',
			'grupo',
			$grupo,
			array(
				'accion' => $accion,
				'total'  => $total,
			)
		);
		self::lanzar( $grupo );
		return $t;
	}

	/** Detiene la tarea en curso (lo ya corregido se queda corregido). */
	public static function detener( string $grupo ): ?array {
		$t = self::actual( $grupo, true );
		if ( $t && self::EN_CURSO === $t['estado'] ) {
			$t['estado']    = self::CANCELADA;
			$t['terminada'] = time();
			self::guardar( $t );
			wp_clear_scheduled_hook( self::HOOK_CRON, array( $grupo ) );
			Logger::log(
				'numeros_tarea_detenida',
				'grupo',
				$grupo,
				array(
					'accion'     => $t['accion'],
					'resueltos'  => $t['hechas'],
					'pendientes' => $t['pendientes'],
				)
			);
		}
		return $t;
	}

	/** Quita del monitor una tarea ya acabada. */
	public static function cerrar( string $grupo ): bool {
		$t = self::actual( $grupo, true );
		if ( ! $t || self::EN_CURSO === $t['estado'] ) {
			return false;
		}
		return delete_option( self::OPCION . $grupo );
	}

	public static function token_valido( string $grupo, string $token ): bool {
		$t = self::actual( $grupo, true );
		return $t && self::EN_CURSO === $t['estado'] && '' !== $token && hash_equals( (string) $t['token'], $token );
	}

	/** Una tarea de las pruebas solo se ejecuta con el CRM simulado activo. */
	public static function ejecutable( array $t, bool $crm_simulado ): bool {
		return self::EN_CURSO === $t['estado'] && ( empty( $t['prueba'] ) || $crm_simulado );
	}

	// ---------------------------------------------------------------------
	// Trabajador
	// ---------------------------------------------------------------------

	/** Duración de cada tanda (filtro asde_ap_tarea_segundos), sin pasar del límite de PHP. */
	public static function segundos(): float {
		$segundos = (float) apply_filters( 'asde_ap_tarea_segundos', 10 );
		$limite   = (int) ini_get( 'max_execution_time' );
		if ( $limite > 0 ) {
			$segundos = min( $segundos, max( 5, $limite - 15 ) );
		}
		return max( 0.0, $segundos );
	}

	/**
	 * Lanza la tanda siguiente sin esperar la respuesta (petición interna) y
	 * deja WP-Cron de respaldo por si esa petición no llega.
	 */
	public static function lanzar( string $grupo ): void {
		$t = self::actual( $grupo, true );
		if ( ! $t || self::EN_CURSO !== $t['estado'] || ! apply_filters( 'asde_ap_tarea_lanzar', true, $grupo ) ) {
			return;
		}
		if ( ! wp_next_scheduled( self::HOOK_CRON, array( $grupo ) ) ) {
			wp_schedule_single_event( time() + 2 * MINUTE_IN_SECONDS, self::HOOK_CRON, array( $grupo ) );
		}
		// La acción va en la URL para que la regla «noabort» de LiteSpeed (.htaccess) se
		// limite a estas peticiones; el token, en el cuerpo (no queda en los registros).
		wp_remote_post(
			admin_url( 'admin-ajax.php?action=' . self::AJAX_TRABAJAR ),
			array(
				'timeout'   => 0.01,
				'blocking'  => false,
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- gancho del núcleo de WordPress
				'body'      => array(
					'grupo' => $grupo,
					'token' => $t['token'],
				),
			)
		);
	}

	/**
	 * Una tanda. Devuelve la tarea como queda (o como estaba, si no tocaba
	 * trabajar: acabada, sin permiso o con otra tanda en marcha).
	 */
	public static function trabajar( string $grupo, float $segundos ): ?array {
		$t = self::actual( $grupo, true );
		if ( ! $t || ! self::ejecutable( $t, Escritura::en_pruebas() ) || ! self::cerrojo( $grupo, $segundos ) ) {
			return $t;
		}
		try {
			$t = self::tanda( $t, microtime( true ) + $segundos );
		} finally {
			self::abrir_cerrojo( $grupo );
		}
		if ( self::EN_CURSO === $t['estado'] ) {
			self::lanzar( $grupo );
		}
		return $t;
	}

	private static function tanda( array $t, float $hasta ): array {
		$datos   = Grupos::por_numero( $t['grupo'] );
		$usuario = get_userdata( (int) $t['usuario'] );
		if ( ! $datos || ! $usuario || ! user_can( $usuario, self::CAP ) ) {
			$t['estado'] = self::ERROR;
			$t['aviso']  = __( 'Whoever started the task no longer has permission, or the group no longer exists.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' );
			return self::acabar( $t );
		}
		$antes = get_current_user_id();
		wp_set_current_user( $usuario->ID ); // En la auditoría consta quien la inició.
		try {
			return Grupo::con( $datos, static fn() => self::corregir( $t, $hasta ) );
		} finally {
			wp_set_current_user( $antes );
		}
	}

	private static function corregir( array $t, float $hasta ): array {
		$repo = PersonaRepository::make();
		if ( ! $repo || ! Escritura::disponible() ) {
			$t['estado'] = self::ERROR;
			$t['aviso']  = __( 'The group\'s CRM user can no longer edit records.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' );
			return self::acabar( $t );
		}
		$num = new Numeracion( $repo );
		try {
			$todas = $repo->todas( false );
			$num->analizar( $todas );
		} catch ( CrmException $e ) {
			++$t['fallos_crm'];
			$t['aviso']       = __( 'The CRM could not be read: ', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) . $e->getMessage();
			$t['actualizada'] = time();
			if ( $t['fallos_crm'] >= self::MAX_FALLOS_CRM ) {
				$t['estado'] = self::ERROR;
				return self::acabar( $t );
			}
			return self::guardar_tanda( $t );
		}

		$grupo  = (string) $t['grupo'];
		$saltar = array_map( 'strval', array_keys( $t['fallidas'] ) );
		$seguir = static fn() => self::EN_CURSO === ( self::actual( $grupo, true )['estado'] ?? '' );
		$r      = 'resolver_duplicados' === $t['accion']
			? $num->resolver_duplicados( $todas, $hasta, $saltar, $seguir )
			: $num->guardar_todos( $todas, 'corregir_todos' === $t['accion'] ? Numeracion::CORREGIDO : Numeracion::GENERADO, $hasta, $saltar, $seguir );

		$t['hechas']     += $r['resueltos'];
		$t['fallidas']    = $t['fallidas'] + $r['fallidas'];
		$t['pendientes']  = $r['pendientes'];
		$t['tandas']     += 1;
		$t['fallos_crm']  = 0;
		$t['aviso']       = '';
		$t['actualizada'] = time();
		if ( 0 === $r['pendientes'] ) {
			$t['estado'] = self::TERMINADA;
			return self::acabar( $t );
		}
		return self::guardar_tanda( $t );
	}

	/**
	 * Guarda el avance respetando lo que otra petición haya hecho mientras
	 * tanto: si la detuvieron, se queda detenida; si la quitaron, no se resucita.
	 */
	private static function guardar_tanda( array $t ): array {
		$fresca = self::actual( $t['grupo'], true );
		if ( ! $fresca || $fresca['id'] !== $t['id'] ) {
			return $t;
		}
		if ( self::EN_CURSO !== $fresca['estado'] ) {
			$t['estado']    = $fresca['estado'];
			$t['terminada'] = $fresca['terminada'];
		}
		self::guardar( $t );
		return $t;
	}

	/** Fin (terminada o con error): resumen en la auditoría y fuera de WP-Cron. */
	private static function acabar( array $t ): array {
		$t['terminada'] = time();
		$fresca         = self::actual( $t['grupo'], true );
		if ( ! $fresca || $fresca['id'] !== $t['id'] ) {
			return $t;
		}
		if ( self::CANCELADA === $fresca['estado'] ) {
			return self::guardar_tanda( $t ); // Ya la detuvieron (y quedó en la auditoría).
		}
		self::guardar( $t );
		wp_clear_scheduled_hook( self::HOOK_CRON, array( $t['grupo'] ) );
		Logger::log(
			'resolver_duplicados' === $t['accion'] ? 'numeros_duplicados_resueltos' : 'numeros_' . $t['accion'],
			'grupo',
			$t['grupo'],
			array(
				'estado'     => $t['estado'],
				'resueltos'  => $t['hechas'],
				'fallidas'   => count( $t['fallidas'] ),
				'pendientes' => $t['pendientes'],
				'tandas'     => $t['tandas'],
			)
		);
		return $t;
	}

	/**
	 * Cerrojo para que no trabajen dos tandas a la vez (INSERT IGNORE: atómico).
	 * Si una tanda murió sin soltarlo, caduca solo.
	 */
	private static function cerrojo( string $grupo, float $segundos ): bool {
		global $wpdb;
		$clave  = self::CERROJO . $grupo;
		$caduca = time() + (int) ceil( $segundos ) + 90;
		$sql    = "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')";
		if ( 1 === (int) $wpdb->query( $wpdb->prepare( $sql, $clave, (string) $caduca ) ) ) { // phpcs:ignore WordPress.DB
			return true;
		}
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND CAST(option_value AS UNSIGNED) < %d", $clave, time() ) ); // phpcs:ignore WordPress.DB
		return 1 === (int) $wpdb->query( $wpdb->prepare( $sql, $clave, (string) $caduca ) ); // phpcs:ignore WordPress.DB
	}

	private static function abrir_cerrojo( string $grupo ): void {
		global $wpdb;
		$wpdb->delete( $wpdb->options, array( 'option_name' => self::CERROJO . $grupo ) ); // phpcs:ignore WordPress.DB
	}

	// ---------------------------------------------------------------------
	// Peticiones
	// ---------------------------------------------------------------------

	/** Petición interna del propio servidor (sin sesión): la autoriza el token de la tarea. */
	public static function ajax_trabajar(): void {
		$grupo = isset( $_POST['grupo'] ) ? sanitize_text_field( wp_unslash( $_POST['grupo'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$token = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! self::token_valido( $grupo, $token ) ) {
			wp_die( '', '', array( 'response' => 403 ) );
		}
		ignore_user_abort( true );
		self::trabajar( $grupo, self::segundos() );
		wp_die( '', '', array( 'response' => 200 ) );
	}

	public static function cron( string $grupo ): void {
		self::trabajar( $grupo, self::segundos() );
	}

	/** El monitor pregunta cómo va (y, si lleva rato parada, la reactiva). */
	public static function ajax_estado(): void {
		check_ajax_referer( 'asde_ap_tarea', 'nonce' );
		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( null, 403 );
		}
		$grupo = Grupo::numero();
		$t     = self::actual( $grupo, true );
		if ( $t && self::EN_CURSO === $t['estado'] ) {
			$parada = time() - (int) $t['actualizada'];
			if ( $parada > 2 * self::PARADA ) {
				// Ni la petición interna ni WP-Cron la mueven: avanza el propio monitor.
				$t = self::trabajar( $grupo, min( 8.0, self::segundos() ) ) ?? $t;
			} elseif ( $parada > self::PARADA ) {
				self::lanzar( $grupo );
			}
		}
		wp_send_json_success( self::monitor( $t ) );
	}

	public static function ajax_detener(): void {
		check_ajax_referer( 'asde_ap_tarea', 'nonce' );
		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( null, 403 );
		}
		wp_send_json_success( self::monitor( self::detener( Grupo::numero() ) ) );
	}

	public static function ajax_cerrar(): void {
		check_ajax_referer( 'asde_ap_tarea', 'nonce' );
		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( null, 403 );
		}
		self::cerrar( Grupo::numero() );
		wp_send_json_success( self::monitor( self::actual( Grupo::numero(), true ) ) );
	}

	// ---------------------------------------------------------------------
	// Monitor
	// ---------------------------------------------------------------------

	/**
	 * Lo que ve el monitor: contadores y un texto (sin nombres ni datos personales).
	 *
	 * @return array<string, mixed>
	 */
	public static function monitor( ?array $t ): array {
		if ( ! $t ) {
			return array( 'estado' => 'ninguna' );
		}
		$total      = max( 0, (int) $t['total'] );
		$hechas     = (int) $t['hechas'];
		$fallidas   = count( (array) $t['fallidas'] );
		$pendientes = max( 0, (int) $t['pendientes'] );
		$avance     = $total ? (int) floor( 100 * max( 0, min( $total, $total - $pendientes ) ) / $total ) : 100;
		$hace       = max( 0, time() - (int) $t['actualizada'] );

		$corregidas = 1 === $hechas ? sprintf( /* translators: %d: number */ __( '%d record corrected', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $hechas ) : sprintf( /* translators: %d: number */ __( '%d records corrected', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $hechas );
		switch ( $t['estado'] ) {
			case self::EN_CURSO:
				$texto = 0 === (int) $t['tandas']
					? sprintf( /* translators: %s: text */ __( 'Starting… %s to correct.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), sprintf( /* translators: %d: number */ _n( '%d record', '%d records', $pendientes, 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $pendientes ) )
					: sprintf( /* translators: 1: number, 2: number, 3: text */ _n( '%1$d of %2$d left · %3$s', '%1$d of %2$d left · %3$s', $pendientes, 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $pendientes, $total, sprintf( /* translators: %d: number */ _n( '%d corrected', '%d corrected', $hechas, 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $hechas ) )
						. ( $fallidas ? sprintf( /* translators: %d: number */ __( ' · %d with errors', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $fallidas ) : '' )
						. ( $hace > 20 ? sprintf( /* translators: %d: number */ __( ' · last activity %d s ago', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $hace ) : '' );
				if ( '' !== (string) $t['aviso'] ) {
					$texto .= ' · ⚠️ ' . $t['aviso'];
				}
				break;
			case self::TERMINADA:
				$texto = $fallidas
					? sprintf( /* translators: 1: text, 2: text */ __( 'Finished: %1$s in the CRM; %2$s (details in People).', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $corregidas, 1 === $fallidas ? __( '1 could not be corrected', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) : sprintf( /* translators: %d: number */ __( '%d could not be corrected', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $fallidas ) )
					: sprintf( /* translators: %s: text */ __( 'Finished: %s in the CRM.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $corregidas );
				$avance = 100;
				break;
			case self::CANCELADA:
				$texto = 1 === $pendientes
					? sprintf( /* translators: %s: text */ __( 'Stopped: %s; 1 was left uncorrected.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $corregidas )
					: sprintf( /* translators: 1: text, 2: number */ __( 'Stopped: %1$s; %2$d were left uncorrected.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $corregidas, $pendientes );
				break;
			default:
				$error = rtrim( (string) $t['aviso'], '.' );
				$texto = 1 === $pendientes
					? sprintf( /* translators: 1: text, 2: text */ __( 'Stopped because of an error: %1$s. %2$s; 1 left.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $error, $corregidas )
					: sprintf( /* translators: 1: text, 2: text, 3: number */ __( 'Stopped because of an error: %1$s. %2$s; %3$d left.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $error, $corregidas, $pendientes );
		}

		return array(
			'estado'     => (string) $t['estado'],
			'accion'     => ( self::acciones()[ $t['accion'] ] ?? (string) $t['accion'] ),
			'total'      => $total,
			'hechas'     => $hechas,
			'fallidas'   => $fallidas,
			'pendientes' => $pendientes,
			'avance'     => $avance,
			'hace'       => $hace,
			'texto'      => $texto,
		);
	}

	/** «1 ficha corregida» / «3 fichas corregidas». */
	private static function n( int $n, string $una, string $varias ): string {
		return $n . ' ' . ( 1 === $n ? $una : $varias );
	}
}
