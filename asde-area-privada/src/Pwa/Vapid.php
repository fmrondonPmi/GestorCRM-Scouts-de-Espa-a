<?php
namespace AsdeBalears\AreaPrivada\Pwa;

use AsdeBalears\AreaPrivada\Crm\Crypto;

defined( 'ABSPATH' ) || exit;

/**
 * Claves VAPID del sitio (RFC 8292): con ellas el servidor se identifica ante los servicios de avisos
 * de los navegadores (docs/pwa/PUSH.md). Un par P-256 propio: no hace falta cuenta con Google ni Apple.
 * La pública va al navegador; la privada se guarda cifrada y solo sirve para firmar los envíos (7b).
 */
final class Vapid {

	public const OPCION = 'asde_ap_push_vapid';

	public static function base64url( string $bin ): string {
		return rtrim( strtr( base64_encode( $bin ), '+/', '-_' ), '=' );
	}

	public static function de_base64url( string $texto ): string {
		$b = base64_decode( strtr( $texto, '-_', '+/' ), true );
		return false === $b ? '' : $b;
	}

	/** Clave pública en el formato que pide el navegador (punto sin comprimir, 65 bytes, en base64url), o '' si no hay. */
	public static function publica(): string {
		$d = get_option( self::OPCION, array() );
		return is_array( $d ) ? (string) ( $d['publica'] ?? '' ) : '';
	}

	/** Clave privada en PEM (descifrada), o null. Solo para firmar los envíos. */
	public static function privada_pem(): ?string {
		$d = get_option( self::OPCION, array() );
		return is_array( $d ) && ! empty( $d['privada'] ) ? Crypto::decrypt( (string) $d['privada'] ) : null;
	}

	public static function existe(): bool {
		return '' !== self::publica() && null !== self::privada_pem();
	}

	/** Crea el par si no existe. Devuelve false si el servidor no puede generar claves (OpenSSL). */
	public static function asegurar(): bool {
		if ( self::existe() ) {
			return true;
		}
		$par = self::generar();
		if ( ! $par ) {
			return false;
		}
		update_option(
			self::OPCION,
			array(
				'publica' => $par['publica'],
				'privada' => Crypto::encrypt( $par['pem'] ),
				'creada'  => time(),
			),
			false
		);
		return true;
	}

	/**
	 * Genera un par P-256. En Windows (Local) PHP no encuentra openssl.cnf y falla: se reintenta con el mínimo del plugin.
	 *
	 * @return array{publica:string, pem:string}|null
	 */
	public static function generar(): ?array {
		if ( ! function_exists( 'openssl_pkey_new' ) ) {
			return null;
		}
		$base = array(
			'curve_name'       => 'prime256v1',
			'private_key_type' => OPENSSL_KEYTYPE_EC,
		);
		$intentos = array( array() );
		$cnf      = ASDE_AP_DIR . 'src/Pwa/openssl-minimo.cnf';
		if ( is_readable( $cnf ) ) {
			$intentos[] = array( 'config' => $cnf );
		}
		foreach ( $intentos as $extra ) {
			while ( openssl_error_string() ) { // Vacía errores anteriores.
				continue;
			}
			$k = @openssl_pkey_new( $base + $extra ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( ! $k ) {
				continue;
			}
			$det = openssl_pkey_get_details( $k );
			if ( ! is_array( $det ) || empty( $det['ec']['x'] ) || empty( $det['ec']['y'] ) ) {
				continue;
			}
			$pem = '';
			if ( ! @openssl_pkey_export( $k, $pem, null, $extra ) || '' === $pem ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
				continue;
			}
			$x = str_pad( (string) $det['ec']['x'], 32, "\0", STR_PAD_LEFT );
			$y = str_pad( (string) $det['ec']['y'], 32, "\0", STR_PAD_LEFT );
			return array(
				'publica' => self::base64url( "\x04" . $x . $y ),
				'pem'     => $pem,
			);
		}
		return null;
	}
}
