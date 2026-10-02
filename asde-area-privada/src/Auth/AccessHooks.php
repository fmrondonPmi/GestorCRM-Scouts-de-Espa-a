<?php
namespace AsdeBalears\AreaPrivada\Auth;

use AsdeBalears\AreaPrivada\Domain\Grupos;
use AsdeBalears\AreaPrivada\Frontend\Pages;
use AsdeBalears\AreaPrivada\Usuarios\Usuarios;

defined( 'ABSPATH' ) || exit;

/**
 * - Los socios entran SOLO por la pantalla "Acceso socios" (grupo + nº de socio/a
 *   o su email del CRM + contraseña). Cada usuario solo puede entrar en su grupo
 *   "padre"; los administradores de WordPress eligen el grupo en el que trabajan.
 * - wp-login.php, XML-RPC y las contraseñas de aplicación quedan para los
 *   usuarios de WordPress (editores, administradores): los usuarios que solo
 *   tienen roles del área privada no pueden entrar por ahí.
 * - Esos usuarios no ven wp-admin ni la barra de administración.
 */
final class AccessHooks {

	/** Grupo elegido en la pantalla de acceso mientras se valida el login. */
	private static ?string $grupo_formulario = null;

	public static function register(): void {
		add_filter( 'authenticate', array( self::class, 'comprobar_acceso' ), 22, 3 );
		add_filter( 'wordfence_ls_require_captcha', array( self::class, 'captcha_wordfence' ) );
		add_filter( 'wp_is_application_passwords_available_for_user', array( self::class, 'contrasenas_aplicacion' ), 10, 2 );
		add_filter( 'lostpassword_errors', array( self::class, 'recuperar_en_wp_login' ), 10, 2 );
		add_filter( 'login_redirect', array( self::class, 'login_redirect' ), 20, 3 );
		add_filter( 'show_admin_bar', array( self::class, 'admin_bar' ) );
		add_action( 'admin_init', array( self::class, 'block_admin' ), 1 );
	}

	/**
	 * Valida el login dentro del grupo elegido (lo usa la pantalla de acceso).
	 *
	 * @template T
	 * @param callable(): T $fn wp_signon() o wp_authenticate()
	 * @return T
	 */
	public static function en_grupo( string $grupo_id, callable $fn ) {
		self::$grupo_formulario = $grupo_id;
		try {
			return $fn();
		} finally {
			self::$grupo_formulario = null;
		}
	}

	/** Usuario del área privada sin permisos editoriales (incluye los desactivados, que ya no tienen roles). */
	public static function solo_area_privada( $user ): bool {
		if ( ! $user instanceof \WP_User || ! $user->exists() || $user->has_cap( 'edit_posts' ) ) {
			return false;
		}
		return $user->has_cap( 'asde_access_area_privada' ) || '' !== (string) get_user_meta( $user->ID, Usuarios::META_ESTADO, true );
	}

	/**
	 * Filtro `authenticate` (después de comprobar la contraseña, prioridad 20, y
	 * antes del doble factor de Wordfence, 25).
	 *
	 * @param \WP_User|\WP_Error|null $user
	 */
	public static function comprobar_acceso( $user, $username = '', $password = '' ) {
		// Pantalla de acceso: el usuario tiene que ser del grupo elegido.
		if ( null !== self::$grupo_formulario ) {
			if ( ! $user instanceof \WP_User || $user->has_cap( 'manage_options' ) ) {
				return $user;
			}
			$grupo = Grupos::de_usuario( $user );
			if ( ! $grupo || $grupo['id'] !== self::$grupo_formulario ) {
				// Mismo mensaje que con la contraseña incorrecta: no revela nada.
				return new \WP_Error( 'asde_grupo', __( 'Nº de socio/a o email, contraseña o grupo incorrectos.', 'asde-area-privada' ) );
			}
			return $user;
		}

		// Cualquier otra vía (wp-login.php, XML-RPC…): los socios no entran por aquí.
		// Se responde igual sea o no correcta la contraseña (no sirve para adivinarla).
		$candidato = $user instanceof \WP_User ? $user : ( is_string( $username ) && '' !== $username ? get_user_by( 'login', $username ) : false );
		if ( $candidato && self::solo_area_privada( $candidato ) ) {
			return new \WP_Error(
				'asde_usar_acceso',
				Pages::id( 'acceso' )
					? sprintf(
						/* translators: %s: URL de la pantalla de acceso */
						__( 'Los socios y las familias entran desde <a href="%s">Acceso socios</a>, en el menú de la web.', 'asde-area-privada' ),
						esc_url( Pages::url( 'acceso' ) )
					)
					: __( 'Los socios y las familias entran desde "Acceso socios", en el menú de la web.', 'asde-area-privada' )
			);
		}
		return $user;
	}

	/**
	 * El reCAPTCHA de Wordfence, si se activa, no se aplica a la pantalla de acceso:
	 * cuando duda, pide verificar por email y los usuarios del área privada no
	 * tienen email en WordPress (se quedarían fuera). La pantalla tiene su propio
	 * límite de intentos (Intentos) además de la protección de Wordfence.
	 */
	public static function captcha_wordfence( $requerido ) {
		return null !== self::$grupo_formulario ? false : $requerido;
	}

	public static function contrasenas_aplicacion( $disponible, $user ) {
		return self::solo_area_privada( $user ) ? false : $disponible;
	}

	/** "¿Has olvidado tu contraseña?" de wp-login.php con un nº de socio: se remite a nuestra página (email del CRM). */
	public static function recuperar_en_wp_login( $errors, $user_data ) {
		if ( self::solo_area_privada( $user_data ) && Pages::id( 'recuperar' ) ) {
			$errors->add(
				'asde_recuperar',
				sprintf(
					/* translators: %s: URL de la página de recuperación */
					__( 'Los socios y las familias recuperan la contraseña desde <a href="%s">esta página</a>.', 'asde-area-privada' ),
					esc_url( Pages::url( 'recuperar' ) )
				)
			);
		}
		return $errors;
	}

	public static function login_redirect( $redirect_to, $requested, $user ) {
		if ( ! self::solo_area_privada( $user ) ) {
			return $redirect_to;
		}
		$area = Pages::url( 'area' );
		return ( is_string( $requested ) && str_starts_with( $requested, $area ) ) ? $requested : $area;
	}

	public static function admin_bar( $show ) {
		return self::solo_area_privada( wp_get_current_user() ) ? false : $show;
	}

	public static function block_admin(): void {
		if ( wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}
		if ( self::solo_area_privada( wp_get_current_user() ) ) {
			wp_safe_redirect( Pages::url( 'area' ) );
			exit;
		}
	}
}
