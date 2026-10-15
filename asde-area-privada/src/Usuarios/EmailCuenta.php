<?php
namespace AsdeBalears\AreaPrivada\Usuarios;

use AsdeBalears\AreaPrivada\Crm\Crypto;
use AsdeBalears\AreaPrivada\Domain\Grupos;

defined( 'ABSPATH' ) || exit;

/**
 * Email de cada cuenta del área privada, guardado en WordPress (decisión del
 * usuario, 2026-09-28: el email no es un dato sensible si va cifrado).
 *
 * - asde_email_cifrado: el email cifrado con una clave derivada de la clave del
 *   sitio y del id del usuario (Crypto::encrypt_contexto "email|<id>"): solo se
 *   descifra para ese usuario y nunca con la base de datos sola.
 * - asde_email_huella: HMAC del email con la clave del sitio, para encontrar la
 *   cuenta al entrar o recuperar la contraseña sin descifrar nada ni consultar el CRM.
 *
 * Si la cuenta tiene un email de origen en el CRM (asde_email_origen), el CRM
 * manda: cuando se lee un valor distinto, se guarda el nuevo (Contacto::email y
 * la tarea diaria).
 */
final class EmailCuenta {

	public const META_CIFRADO = 'asde_email_cifrado';
	public const META_HUELLA  = 'asde_email_huella';

	public static function normalizar( string $email ): string {
		return strtolower( trim( $email ) );
	}

	public static function huella( string $email ): string {
		return Crypto::hmac( 'email|' . self::normalizar( $email ) );
	}

	/** Guarda (o borra, con null o un email no válido) el email de la cuenta. @return bool Si queda un email guardado. */
	public static function guardar( int $user_id, ?string $email ): bool {
		$email = null === $email ? '' : self::normalizar( $email );
		if ( '' === $email || ! is_email( $email ) ) {
			delete_user_meta( $user_id, self::META_CIFRADO );
			delete_user_meta( $user_id, self::META_HUELLA );
			return false;
		}
		if ( self::leer( $user_id ) === $email ) {
			return true;
		}
		update_user_meta( $user_id, self::META_CIFRADO, Crypto::encrypt_contexto( $email, 'email|' . $user_id ) );
		update_user_meta( $user_id, self::META_HUELLA, self::huella( $email ) );
		return true;
	}

	public static function leer( int $user_id ): ?string {
		$raw   = (string) get_user_meta( $user_id, self::META_CIFRADO, true );
		$email = '' === $raw ? null : Crypto::decrypt_contexto( $raw, 'email|' . $user_id );
		return null !== $email && is_email( $email ) ? $email : null;
	}

	public static function tiene( int $user_id ): bool {
		return '' !== (string) get_user_meta( $user_id, self::META_HUELLA, true );
	}

	/**
	 * Cuentas con este email (de cualquier grupo, desactivadas incluidas).
	 *
	 * @return \WP_User[]
	 */
	public static function usuarios( string $email ): array {
		$email = self::normalizar( $email );
		if ( ! is_email( $email ) ) {
			return array();
		}
		return get_users(
			array(
				'meta_key'   => self::META_HUELLA, // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value' => self::huella( $email ), // phpcs:ignore WordPress.DB.SlowDBQuery
				'orderby'    => 'login',
			)
		);
	}

	/**
	 * Cuentas con email de origen en el CRM pero sin email guardado (creadas antes
	 * de la v0.10): hasta que la tarea diaria o un envío lo guarden, para ellas se
	 * sigue buscando el email en el CRM.
	 *
	 * @return \WP_User[]
	 */
	public static function pendientes( string $grupo_id = '' ): array {
		$usuarios = get_users(
			array(
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery
					array( 'key' => Usuarios::META_EMAIL_ORIGEN ),
					array(
						'key'     => self::META_HUELLA,
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);
		if ( '' === $grupo_id ) {
			return $usuarios;
		}
		return array_values( array_filter( $usuarios, static fn( \WP_User $u ) => ( Grupos::de_usuario( $u )['id'] ?? '' ) === $grupo_id ) );
	}
}
