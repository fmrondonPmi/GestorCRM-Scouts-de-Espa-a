<?php
namespace AsdeBalears\AreaPrivada\Pwa;

use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Auth\Ambito;

defined( 'ABSPATH' ) || exit;

/**
 * Notificaciones push de la app (docs/pwa/PUSH.md, sección 7). Paso 7a: suscribir y dar de baja los
 * dispositivos de cada cuenta, elegir las categorías de avisos y la tarjeta «Avisos en este móvil» del
 * inicio del área. (El envío es el paso 7b.) Nacen apagadas: las enciende el administrador en
 * *Grupos Scouts → App móvil*.
 */
final class Push {

	public const AJAX = 'asde_ap_push';

	/** Categorías de avisos (P-PUSH-1: los cuatro grupos). */
	public const CLAVES = array( 'uniforme', 'pagos', 'fichas', 'equipo' );

	public const COLA      = 'asde_ap_push_cola';
	public const HOOK_COLA = 'asde_ap_push_cola';
	private const COLA_MAX = 500;  // Tope de avisos esperando: si se llena, se descartan los nuevos (nunca crece sin límite).
	private const LOTE     = 20;   // Envíos por pasada del WP-Cron.
	private const INTENTOS = 3;

	public function register(): void {
		add_action( 'wp_ajax_' . self::AJAX, array( $this, 'ajax' ) );
		add_action( self::HOOK_COLA, array( self::class, 'procesar_cola' ) );
	}

	/** Ruta de una dirección de la app (lo único que viaja en el mensaje). */
	public static function ruta_de( string $url ): string {
		return (string) wp_parse_url( $url, PHP_URL_PATH );
	}

	/**
	 * Pone en cola un aviso para una cuenta: uno por cada móvil suscrito, si tiene activada la categoría. El
	 * aviso es genérico: solo viaja la página a la que llevar. Devuelve cuántos móviles quedan en cola.
	 */
	public static function encolar( int $user_id, string $categoria, string $ruta = '' ): int {
		if ( ! self::activo() || ! in_array( $categoria, self::CLAVES, true ) || ! Suscripciones::categoria_activa( $user_id, $categoria ) ) {
			return 0;
		}
		$ruta  = '' !== $ruta ? $ruta : self::ruta_de( Manifiesto::inicio() );
		$cola  = self::cola();
		$nuevo = 0;
		foreach ( Suscripciones::de_usuario( $user_id ) as $d ) {
			if ( count( $cola ) >= self::COLA_MAX ) {
				break;
			}
			$cola[] = array(
				'u' => $user_id,
				'h' => $d['huella'],
				'c' => $categoria,
				'r' => $ruta,
				'n' => 0,
				't' => time(),
			);
			++$nuevo;
		}
		if ( $nuevo ) {
			update_option( self::COLA, $cola, false );
			self::programar( time() + 5 );
		}
		return $nuevo;
	}

	/** @return array<int, array{u:int, h:string, c:string, r:string, n:int, t:int}> */
	public static function cola(): array {
		$c = get_option( self::COLA, array() );
		return is_array( $c ) ? array_values( $c ) : array();
	}

	private static function programar( int $cuando ): void {
		if ( ! wp_next_scheduled( self::HOOK_COLA ) ) {
			wp_schedule_single_event( max( time() + 1, $cuando ), self::HOOK_COLA );
		}
	}

	/**
	 * Manda los avisos de la cola que ya tocan (hasta `$max`) y reprograma lo que quede. Lo ejecuta el WP-Cron.
	 *
	 * @return array{enviados:int, muertas:int, reintentos:int, fallos:int, pendientes:int}
	 */
	public static function procesar_cola( int $max = self::LOTE ): array {
		$res = array(
			'enviados'   => 0,
			'muertas'    => 0,
			'reintentos' => 0,
			'fallos'     => 0,
			'pendientes' => 0,
		);
		if ( get_transient( 'asde_ap_push_lock' ) ) {
			return $res; // Ya hay una pasada en marcha.
		}
		set_transient( 'asde_ap_push_lock', 1, MINUTE_IN_SECONDS );
		try {
			$ahora   = time();
			$restos  = array();
			$hechos  = 0;
			$activo  = self::activo();
			foreach ( self::cola() as $job ) {
				if ( ! $activo ) {
					continue; // Con los avisos apagados, la cola se vacía sin enviar.
				}
				if ( $job['t'] > $ahora || $hechos >= $max ) {
					$restos[] = $job;
					continue;
				}
				++$hechos;
				if ( ! Suscripciones::categoria_activa( (int) $job['u'], (string) $job['c'] ) ) {
					continue; // La persona la desactivó mientras esperaba.
				}
				$disp = null;
				foreach ( Suscripciones::de_usuario( (int) $job['u'] ) as $d ) {
					if ( $d['huella'] === $job['h'] ) {
						$disp = $d;
					}
				}
				if ( ! $disp ) {
					continue; // El móvil ya no está (baja o cuenta borrada).
				}
				$r = WebPush::enviar( $disp, (string) $job['r'] );
				if ( WebPush::OK === $r['estado'] ) {
					++$res['enviados'];
				} elseif ( WebPush::MUERTA === $r['estado'] ) {
					Suscripciones::quitar_huella( (int) $job['u'], (string) $job['h'] );
					++$res['muertas'];
				} elseif ( WebPush::REINTENTAR === $r['estado'] && (int) $job['n'] + 1 < self::INTENTOS ) {
					$job['n'] = (int) $job['n'] + 1;
					$job['t'] = $ahora + ( $r['espera'] ?: 300 * $job['n'] );
					$restos[] = $job;
					++$res['reintentos'];
				} else {
					++$res['fallos'];
				}
			}
			$restos ? update_option( self::COLA, $restos, false ) : delete_option( self::COLA );
			$res['pendientes'] = count( $restos );
			if ( $restos ) {
				self::programar( min( array_column( $restos, 't' ) ) );
			}
			if ( $res['enviados'] || $res['muertas'] || $res['reintentos'] || $res['fallos'] ) {
				Logger::log( 'push_lote', 'sitio', '', $res );
			}
		} finally {
			delete_transient( 'asde_ap_push_lock' );
		}
		return $res;
	}

	/**
	 * Manda ya (sin cola) un aviso a todos los móviles de una cuenta, sin mirar las categorías: es el
	 * «Enviar aviso de prueba a mi móvil» de la pantalla «App móvil».
	 *
	 * @return array<int, array{estado:string, codigo:int, espera:int}> Un resultado por móvil.
	 */
	public static function enviar_ahora( int $user_id, string $ruta = '' ): array {
		$out = array();
		if ( ! self::activo() ) {
			return $out;
		}
		$ruta = '' !== $ruta ? $ruta : self::ruta_de( Manifiesto::inicio() );
		foreach ( Suscripciones::de_usuario( $user_id ) as $d ) {
			$r = WebPush::enviar( $d, $ruta );
			if ( WebPush::MUERTA === $r['estado'] ) {
				Suscripciones::quitar_huella( $user_id, $d['huella'] );
			}
			$out[] = $r;
		}
		return $out;
	}

	/** ¿Están las notificaciones push en marcha en este sitio? (App activa, push encendido y claves VAPID creadas.) */
	public static function activo(): bool {
		return Pwa::activa() && Ajustes::push() && Vapid::existe();
	}

	/**
	 * Categorías que ve esta cuenta, con su nombre en el idioma de la persona. «Avisos del equipo» solo para
	 * quien aprueba solicitudes o cambios de fichas.
	 *
	 * @return array<string, string> clave => etiqueta
	 */
	public static function categorias_de( int $user_id ): array {
		$cats = array(
			'uniforme' => asde_t( 'Uniforme' ),
			'pagos'    => asde_t( 'Pagos y cargos' ),
			'fichas'   => asde_t( 'Cambios de ficha' ),
		);
		if ( user_can( $user_id, 'asde_aprobar_solicitudes' ) || user_can( $user_id, 'asde_approve_changes' ) ) {
			$cats['equipo'] = asde_t( 'Avisos del equipo' );
		}
		return $cats;
	}

	/**
	 * Textos de la tarjeta y de los avisos, ya en el idioma de la persona (el JS no lleva textos propios).
	 *
	 * @return array<string, string>
	 */
	public static function textos(): array {
		return array(
			'titulo'      => asde_t( 'Avisos en este móvil' ),
			'texto'       => asde_t( 'Recibe un aviso en el móvil cuando haya novedades. El aviso no lleva datos personales: al abrirlo verás los detalles con tu sesión.' ),
			'activar'     => asde_t( 'Activar avisos' ),
			'desactivar'  => asde_t( 'Desactivar avisos' ),
			'activados'   => asde_t( 'Avisos activados en este móvil.' ),
			'desactivados' => asde_t( 'Los avisos están desactivados en este móvil.' ),
			'bloqueado'   => asde_t( 'Has bloqueado los avisos en este navegador. Actívalos en los ajustes del navegador o del móvil y vuelve a intentarlo.' ),
			'no_soporta'  => asde_t( 'Este móvil o este navegador no admite avisos desde la web.' ),
			'ios'         => asde_t( 'En el iPhone, instala primero la app en la pantalla de inicio (Compartir → Añadir a pantalla de inicio) y ábrela desde su icono para activar los avisos.' ),
			'error'       => asde_t( 'No se han podido guardar los avisos. Inténtalo de nuevo.' ),
			'categorias'  => asde_t( 'Qué avisos quieres recibir' ),
		);
	}

	/**
	 * Lo que recibe el JS de la tarjeta (window.asdePwa.push), o null si no hay que enseñarla a esta cuenta.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function datos_js( int $user_id ): ?array {
		if ( $user_id <= 0 || ! self::activo() ) {
			return null;
		}
		$apagadas = Suscripciones::desactivadas( $user_id );
		$cats     = array();
		foreach ( self::categorias_de( $user_id ) as $clave => $etiqueta ) {
			$cats[ $clave ] = array(
				'etiqueta' => $etiqueta,
				'activa'   => ! in_array( $clave, $apagadas, true ),
			);
		}
		return array(
			'vapid'      => Vapid::publica(),
			'ajax'       => admin_url( 'admin-ajax.php' ),
			'nonce'      => wp_create_nonce( self::AJAX ),
			'categorias' => $cats,
			'textos'     => self::textos(),
		);
	}

	/** Operaciones de la tarjeta: suscribir, baja y categorías. Solo cuentas con acceso al área. */
	public function ajax(): void {
		if ( ! check_ajax_referer( self::AJAX, 'nonce', false ) ) {
			wp_send_json_error( array( 'mensaje' => 'nonce' ), 403 );
		}
		$uid = get_current_user_id();
		if ( $uid <= 0 || ! self::activo() ) {
			wp_send_json_error( array( 'mensaje' => 'apagado' ), 400 );
		}
		if ( ! Ambito::actual()->puede_acceder() ) {
			wp_send_json_error( array( 'mensaje' => 'sin-acceso' ), 403 );
		}
		$post = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification
		$op   = sanitize_key( (string) ( $post['op'] ?? '' ) );
		$ep   = sanitize_text_field( (string) ( $post['endpoint'] ?? '' ) );

		if ( 'suscribir' === $op ) {
			$p256dh = (string) ( $post['p256dh'] ?? '' );
			$auth   = (string) ( $post['auth'] ?? '' );
			if ( 1 !== preg_match( '/^[A-Za-z0-9_-]+$/', $p256dh ) || 1 !== preg_match( '/^[A-Za-z0-9_-]+$/', $auth ) || ! Suscripciones::agregar( $uid, $ep, $p256dh, $auth ) ) {
				wp_send_json_error( array( 'mensaje' => 'datos' ), 400 );
			}
			Logger::log( 'push_activado', 'cuenta', (string) $uid, array( 'dispositivos' => Suscripciones::cuantos( $uid ) ) );
			wp_send_json_success( array( 'dispositivos' => Suscripciones::cuantos( $uid ) ) );
		}
		if ( 'baja' === $op ) {
			$quitada = Suscripciones::quitar( $uid, $ep );
			if ( $quitada ) {
				Logger::log( 'push_desactivado', 'cuenta', (string) $uid, array( 'dispositivos' => Suscripciones::cuantos( $uid ) ) );
			}
			wp_send_json_success( array( 'dispositivos' => Suscripciones::cuantos( $uid ) ) );
		}
		if ( 'categorias' === $op ) {
			$apagadas = isset( $post['desactivadas'] ) ? array_map( 'sanitize_key', (array) $post['desactivadas'] ) : array();
			$visibles = array_keys( self::categorias_de( $uid ) );
			Suscripciones::guardar_desactivadas( $uid, array_values( array_intersect( $apagadas, $visibles ) ) );
			Logger::log( 'push_categorias', 'cuenta', (string) $uid, array( 'desactivadas' => count( Suscripciones::desactivadas( $uid ) ) ) );
			wp_send_json_success( array( 'desactivadas' => Suscripciones::desactivadas( $uid ) ) );
		}
		wp_send_json_error( array( 'mensaje' => 'op' ), 400 );
	}
}
