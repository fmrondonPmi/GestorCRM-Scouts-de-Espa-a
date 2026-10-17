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
 *
 * v0.35.1 (petición del usuario, 2026-10-15): OPCIONAL para todos los roles. Si el
 * administrador vuelve a hacerla obligatoria para algún rol en Wordfence, el inicio y
 * «Seguridad» lo siguen avisando (pendiente()).
 */
final class Wordfence {

	private const OPTION = 'asde_ap_wordfence_config';
	/** 2: añade Tesorería. 3: opcional para todos los roles (v0.35.1). */
	public const VERSION = 4;

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
				'enabled-roles.' . Roles::SCOUTER      => 'optional',
				'enabled-roles.' . Roles::SECRETARIA   => 'optional',
				'enabled-roles.' . Roles::COORDINACION => 'optional',
				'enabled-roles.' . Roles::TESORERIA    => 'optional',
				'enabled-roles.' . Roles::INTENDENTE   => 'optional',
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

	/**
	 * Quita a una cuenta todo lo que Wordfence guarda para entrar con ella: la verificación en
	 * dos pasos (el secreto de la app y los códigos de recuperación) y las llaves de acceso
	 * (passkeys, que dejan entrar sin contraseña). Al desactivar la cuenta (v0.26.1). Sin
	 * Wordfence, o si algo falla en él, no se corta la desactivación.
	 *
	 * @return array{dos_pasos:bool, llaves:int} Si tenía la verificación en dos pasos y cuántas llaves se han quitado.
	 */
	public static function quitar_acceso( \WP_User $user ): array {
		$out = array(
			'dos_pasos' => false,
			'llaves'    => 0,
		);
		if ( ! self::disponible() ) {
			return $out;
		}
		try {
			$users            = \WordfenceLS\Controller_Users::shared();
			$out['dos_pasos'] = method_exists( $users, 'has_2fa_enrollment' ) ? (bool) $users->has_2fa_enrollment( $user ) : (bool) $users->has_2fa_active( $user );
			$users->deactivate_2fa( $user ); // Borra el secreto y los códigos de recuperación.
			if ( class_exists( '\WordfenceLS\Controller_Passkey' ) && method_exists( '\WordfenceLS\Controller_Passkey', 'shared' ) ) {
				$llaves = \WordfenceLS\Controller_Passkey::shared();
				if ( method_exists( $llaves, 'get_passkeys' ) && method_exists( $llaves, 'remove_passkey' ) ) {
					foreach ( (array) $llaves->get_passkeys( $user ) as $p ) {
						if ( true === $llaves->remove_passkey( $user, (int) $p['id'] ) ) {
							++$out['llaves'];
						}
					}
				}
			}
		} catch ( \Throwable $e ) {
			\AsdeBalears\AreaPrivada\Audit\Logger::log( 'wordfence_quitar_acceso_error', 'user', (string) $user->ID, array( 'error' => $e->getMessage() ) );
		}
		return $out;
	}
}
