<?php
namespace AsdeBalears\AreaPrivada\Crm;

defined( 'ABSPATH' ) || exit;

/**
 * Cifrado simétrico (libsodium) para los secretos guardados en la base de datos.
 *
 * La clave se deriva de ASDE_AP_ENCRYPTION_KEY si está definida en wp-config.php,
 * o en su defecto de las salts de WordPress. Si la clave cambia (p. ej. se
 * regeneran las salts), los secretos guardados dejan de poder descifrarse y hay
 * que volver a introducirlos.
 */
final class Crypto {

	private const PREFIX = 'v1:';

	public static function encrypt( string $plain ): string {
		return self::cifrar( $plain, self::key() );
	}

	/** @return string|null null si está vacío o no se puede descifrar. */
	public static function decrypt( string $stored ): ?string {
		return self::descifrar( $stored, self::key() );
	}

	/**
	 * Cifrado con una clave propia de cada contexto, derivada de la clave del sitio
	 * y del contexto (p. ej. "email|42": el email del usuario 42). Lo cifrado para
	 * un usuario solo se descifra con el id de ese usuario, y nunca sin la clave del
	 * sitio (wp-config.php), que no está en la base de datos.
	 */
	public static function encrypt_contexto( string $plain, string $contexto ): string {
		return self::cifrar( $plain, self::key_contexto( $contexto ) );
	}

	public static function decrypt_contexto( string $stored, string $contexto ): ?string {
		return self::descifrar( $stored, self::key_contexto( $contexto ) );
	}

	/** Huella (HMAC-SHA256) con la misma clave: para comparar sin guardar el dato en claro. */
	public static function hmac( string $data ): string {
		return hash_hmac( 'sha256', $data, self::key() );
	}

	private static function key(): string {
		$material = defined( 'ASDE_AP_ENCRYPTION_KEY' ) ? (string) ASDE_AP_ENCRYPTION_KEY : wp_salt( 'auth' ) . wp_salt( 'secure_auth' );
		return sodium_crypto_generichash( $material, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
	}

	private static function key_contexto( string $contexto ): string {
		return sodium_crypto_generichash( 'asde-ap|' . $contexto, self::key(), SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
	}

	private static function cifrar( string $plain, string $key ): string {
		$nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = sodium_crypto_secretbox( $plain, $nonce, $key );
		return self::PREFIX . base64_encode( $nonce . $cipher );
	}

	private static function descifrar( string $stored, string $key ): ?string {
		if ( '' === $stored || ! str_starts_with( $stored, self::PREFIX ) ) {
			return null;
		}
		$raw = base64_decode( substr( $stored, strlen( self::PREFIX ) ), true );
		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return null;
		}
		$nonce  = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$plain  = sodium_crypto_secretbox_open( $cipher, $nonce, $key );
		return false === $plain ? null : $plain;
	}
}
