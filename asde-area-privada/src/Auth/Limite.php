<?php
namespace AsdeBalears\AreaPrivada\Auth;

defined( 'ABSPATH' ) || exit;

/**
 * Límite de peticiones por dispositivo (IP) para los formularios públicos (p. ej.
 * «Solicitar acceso», que consulta el CRM). De la IP solo se guarda un HMAC.
 */
final class Limite {

	/** Cuenta una petición; false si ya se han hecho $max en la ventana (entonces no se cuenta). */
	public static function permitir( string $accion, int $max, int $ventana ): bool {
		$clave = self::clave( $accion );
		$v     = get_transient( $clave );
		if ( ! is_array( $v ) || $v['hasta'] <= time() ) {
			$v = array(
				'n'     => 0,
				'hasta' => time() + $ventana,
			);
		}
		if ( $v['n'] >= $max ) {
			return false;
		}
		++$v['n'];
		set_transient( $clave, $v, max( 1, $v['hasta'] - time() ) );
		return true;
	}

	private static function clave( string $accion ): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		return 'asde_ap_lim_' . substr( hash_hmac( 'sha256', $accion . '|' . $ip, wp_salt( 'auth' ) ), 0, 32 );
	}
}
