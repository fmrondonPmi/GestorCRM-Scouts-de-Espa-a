<?php
namespace AsdeBalears\AreaPrivada\Auth;

defined( 'ABSPATH' ) || exit;

/**
 * Límite de intentos fallidos en la pantalla de acceso:
 * - 5 por dispositivo (IP) y nº de socio cada 15 minutos;
 * - 20 por IP cada hora (evita probar muchos números de socio).
 * Se comprueba ANTES de validar la contraseña, así que durante el bloqueo no se
 * puede saber si una contraseña es correcta. La IP no se guarda: solo un HMAC.
 */
final class Intentos {

	private const POR_USUARIO     = 5;
	private const VENTANA_USUARIO = 15 * MINUTE_IN_SECONDS;
	private const POR_IP          = 20;
	private const VENTANA_IP      = HOUR_IN_SECONDS;

	public static function bloqueado( string $login ): bool {
		return self::contador( self::clave( $login ) ) >= self::POR_USUARIO
			|| self::contador( self::clave( '' ) ) >= self::POR_IP;
	}

	public static function fallo( string $login ): void {
		self::sumar( self::clave( $login ), self::VENTANA_USUARIO );
		self::sumar( self::clave( '' ), self::VENTANA_IP );
	}

	public static function exito( string $login ): void {
		delete_transient( self::clave( $login ) );
	}

	private static function clave( string $login ): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		return 'asde_ap_int_' . substr( hash_hmac( 'sha256', $ip . '|' . strtoupper( $login ), wp_salt( 'auth' ) ), 0, 32 );
	}

	private static function contador( string $clave ): int {
		$v = get_transient( $clave );
		return is_array( $v ) ? (int) $v['n'] : 0;
	}

	private static function sumar( string $clave, int $ventana ): void {
		$v = get_transient( $clave );
		if ( ! is_array( $v ) || $v['hasta'] <= time() ) {
			$v = array(
				'n'     => 0,
				'hasta' => time() + $ventana,
			);
		}
		++$v['n'];
		set_transient( $clave, $v, max( 1, $v['hasta'] - time() ) );
	}
}
