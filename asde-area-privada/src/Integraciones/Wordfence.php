<?php
namespace AsdeBalears\AreaPrivada\Integraciones;

use AsdeBalears\AreaPrivada\Install\Roles;

defined( 'ABSPATH' ) || exit;

/**
 * Verificación en dos pasos (2FA) con Wordfence Login Security.
 *
 * Decisión del usuario (2026-09-26): 2FA OBLIGATORIO para Scouter, Secretaría y
 * Coordinación; opcional para cuentas familiares y participantes. Wordfence da
 * 10 días de margen para activarlo. Como estos usuarios no entran en wp-admin,
 * lo gestionan desde la página "Seguridad" del área privada (shortcode de
 * Wordfence, que hay que habilitar).
 */
final class Wordfence {

	private const OPTION  = 'asde_ap_wordfence_config';
	private const VERSION = 2; // 2: añade Tesorería.

	public static function disponible(): bool {
		return class_exists( '\WordfenceLS\Controller_Settings' ) && class_exists( '\WordfenceLS\Controller_Users' );
	}

	/** Aplica la configuración una vez (después, el administrador puede cambiarla en Wordfence). */
	public static function configurar(): bool {
		if ( ! self::disponible() || (int) get_option( self::OPTION ) >= self::VERSION ) {
			return false;
		}
		$ok = \WordfenceLS\Controller_Settings::shared()->set_multiple(
			array(
				'enabled-roles.' . Roles::SCOUTER      => 'required',
				'enabled-roles.' . Roles::SECRETARIA   => 'required',
				'enabled-roles.' . Roles::COORDINACION => 'required',
				'enabled-roles.' . Roles::TESORERIA    => 'required',
				'enabled-roles.' . Roles::TUTOR        => 'optional',
				'enabled-roles.' . Roles::PARTICIPANTE => 'optional',
				'enable-shortcode'                     => true,
			)
		);
		if ( $ok ) {
			update_option( self::OPTION, self::VERSION, false );
		}
		return (bool) $ok;
	}

	/**
	 * ¿Le exige Wordfence 2FA a este usuario y aún no lo tiene activo?
	 *
	 * @param int|null $limite Timestamp a partir del cual será obligatorio (si hay margen).
	 */
	public static function pendiente( \WP_User $user, ?int &$limite = null ): bool {
		if ( ! self::disponible() ) {
			return false;
		}
		$users  = \WordfenceLS\Controller_Users::shared();
		$margen = false;
		$desde  = null;
		// requires_2fa() devuelve false mientras dura el margen de 10 días, pero lo
		// indica en $margen y da la fecha límite en $desde.
		$obligatorio = $users->requires_2fa( $user, $margen, $desde );
		if ( $users->has_2fa_active( $user ) || ( ! $obligatorio && true !== $margen ) ) {
			return false;
		}
		$limite = $desde ? (int) $desde : null;
		return true;
	}

	public static function activo( \WP_User $user ): bool {
		return self::disponible() && \WordfenceLS\Controller_Users::shared()->has_2fa_active( $user );
	}
}
