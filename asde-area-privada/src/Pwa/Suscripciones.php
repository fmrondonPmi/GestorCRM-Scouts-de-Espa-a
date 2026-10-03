<?php
namespace AsdeBalears\AreaPrivada\Pwa;

use AsdeBalears\AreaPrivada\Crm\Crypto;

defined( 'ABSPATH' ) || exit;

/**
 * Suscripciones push de cada cuenta (docs/pwa/PUSH.md, §10). Una suscripción identifica un dispositivo
 * (dirección del servicio de avisos del navegador + dos claves de cifrado), así que se trata como un dato
 * personal: guardada cifrada, con la clave del contexto «push|<id de usuario>», en el usermeta
 * `asde_ap_push` (hasta 5 dispositivos por cuenta). Al borrar el usuario, WordPress la borra con él.
 */
final class Suscripciones {

	public const META      = 'asde_ap_push';
	public const META_CATS = 'asde_ap_push_cats';
	public const MAX       = 5;

	/**
	 * Servicios de avisos conocidos. El servidor hará peticiones a la dirección de cada suscripción, así que
	 * solo se admiten estos (si no, una cuenta podría apuntarla a una dirección interna: SSRF).
	 *
	 * @return string[] Sufijos de nombre de servidor.
	 */
	public static function hosts_permitidos(): array {
		return (array) apply_filters(
			'asde_ap_push_hosts',
			array(
				'fcm.googleapis.com',            // Chrome, Edge, Opera, Brave…
				'push.services.mozilla.com',     // Firefox.
				'push.apple.com',                // Safari (iPhone, iPad, Mac).
				'notify.windows.com',            // Edge antiguo.
			)
		);
	}

	public static function endpoint_valido( string $endpoint ): bool {
		if ( strlen( $endpoint ) > 1000 || ! str_starts_with( $endpoint, 'https://' ) ) {
			return false;
		}
		$p = wp_parse_url( $endpoint );
		if ( ! is_array( $p ) || empty( $p['host'] ) || isset( $p['user'] ) || isset( $p['pass'] ) || ( isset( $p['port'] ) && 443 !== (int) $p['port'] ) ) {
			return false;
		}
		$host = strtolower( (string) $p['host'] );
		foreach ( self::hosts_permitidos() as $sufijo ) {
			$sufijo = strtolower( ltrim( (string) $sufijo, '.' ) );
			if ( $host === $sufijo || str_ends_with( $host, '.' . $sufijo ) ) {
				return true;
			}
		}
		return false;
	}

	/** Claves del navegador: p256dh (punto de 65 bytes) y auth (16 bytes), en base64url. */
	public static function claves_validas( string $p256dh, string $auth ): bool {
		return 65 === strlen( Vapid::de_base64url( $p256dh ) ) && 16 === strlen( Vapid::de_base64url( $auth ) );
	}

	private static function contexto( int $user_id ): string {
		return 'push|' . $user_id;
	}

	/** Huella del endpoint (para no duplicar ni enseñar la dirección). */
	public static function huella( string $endpoint ): string {
		return substr( hash( 'sha256', $endpoint ), 0, 16 );
	}

	/** @return array<int, array{h:string, c:string, t:int}> Lista guardada (cifrada) de la cuenta. */
	private static function lista( int $user_id ): array {
		$l = get_user_meta( $user_id, self::META, true );
		return is_array( $l ) ? array_values( array_filter( $l, static fn( $x ) => is_array( $x ) && isset( $x['h'], $x['c'] ) ) ) : array();
	}

	/**
	 * Guarda (o actualiza) el dispositivo de una cuenta. Devuelve false si los datos no valen.
	 */
	public static function agregar( int $user_id, string $endpoint, string $p256dh, string $auth ): bool {
		if ( $user_id <= 0 || ! self::endpoint_valido( $endpoint ) || ! self::claves_validas( $p256dh, $auth ) ) {
			return false;
		}
		$h     = self::huella( $endpoint );
		$lista = array_values( array_filter( self::lista( $user_id ), static fn( $x ) => $x['h'] !== $h ) );
		$json  = (string) wp_json_encode(
			array(
				'e' => $endpoint,
				'p' => $p256dh,
				'a' => $auth,
			)
		);
		$lista[] = array(
			'h' => $h,
			'c' => Crypto::encrypt_contexto( $json, self::contexto( $user_id ) ),
			't' => time(),
		);
		if ( count( $lista ) > self::MAX ) {
			$lista = array_slice( $lista, -self::MAX ); // Se van los más antiguos.
		}
		update_user_meta( $user_id, self::META, $lista );
		return true;
	}

	/** Quita un dispositivo (por su endpoint). Devuelve si había algo que quitar. */
	public static function quitar( int $user_id, string $endpoint ): bool {
		$h     = self::huella( $endpoint );
		$lista = self::lista( $user_id );
		$nueva = array_values( array_filter( $lista, static fn( $x ) => $x['h'] !== $h ) );
		if ( count( $nueva ) === count( $lista ) ) {
			return false;
		}
		$nueva ? update_user_meta( $user_id, self::META, $nueva ) : delete_user_meta( $user_id, self::META );
		return true;
	}

	/** Quita un dispositivo por su huella (para limpiar las que el servicio de avisos da por muertas). */
	public static function quitar_huella( int $user_id, string $huella ): void {
		$nueva = array_values( array_filter( self::lista( $user_id ), static fn( $x ) => $x['h'] !== $huella ) );
		$nueva ? update_user_meta( $user_id, self::META, $nueva ) : delete_user_meta( $user_id, self::META );
	}

	public static function quitar_todas( int $user_id ): void {
		delete_user_meta( $user_id, self::META );
	}

	/**
	 * Dispositivos de una cuenta, descifrados (solo para enviar; no se enseñan).
	 *
	 * @return array<int, array{huella:string, endpoint:string, p256dh:string, auth:string}>
	 */
	public static function de_usuario( int $user_id ): array {
		$out = array();
		foreach ( self::lista( $user_id ) as $x ) {
			$j = Crypto::decrypt_contexto( (string) $x['c'], self::contexto( $user_id ) );
			$d = null === $j ? null : json_decode( $j, true );
			if ( is_array( $d ) && ! empty( $d['e'] ) && ! empty( $d['p'] ) && ! empty( $d['a'] ) ) {
				$out[] = array(
					'huella'   => (string) $x['h'],
					'endpoint' => (string) $d['e'],
					'p256dh'   => (string) $d['p'],
					'auth'     => (string) $d['a'],
				);
			}
		}
		return $out;
	}

	public static function cuantos( int $user_id ): int {
		return count( self::lista( $user_id ) );
	}

	/** Cuentas con al menos un dispositivo (para enviar y para el recuento del administrador). */
	public static function usuarios_con_suscripcion(): array {
		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s", self::META ) ); // phpcs:ignore WordPress.DB
		return array_map( 'intval', (array) $ids );
	}

	// ---------------------------------------------------------------------
	// Preferencias por categoría
	// ---------------------------------------------------------------------

	/**
	 * Categorías que la cuenta ha desactivado. Sin guardar nada, están todas activadas (el consentimiento
	 * es haber activado los avisos en un dispositivo).
	 *
	 * @return string[]
	 */
	public static function desactivadas( int $user_id ): array {
		$d = get_user_meta( $user_id, self::META_CATS, true );
		return is_array( $d ) ? array_values( array_map( 'strval', $d ) ) : array();
	}

	/** @param string[] $desactivadas */
	public static function guardar_desactivadas( int $user_id, array $desactivadas ): void {
		$desactivadas = array_values( array_intersect( Push::CLAVES, array_map( 'strval', $desactivadas ) ) );
		$desactivadas ? update_user_meta( $user_id, self::META_CATS, $desactivadas ) : delete_user_meta( $user_id, self::META_CATS );
	}

	public static function categoria_activa( int $user_id, string $categoria ): bool {
		return ! in_array( $categoria, self::desactivadas( $user_id ), true );
	}
}
