<?php
namespace AsdeBalears\AreaPrivada\Frontend\Views;

use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Auth\AccessHooks;
use AsdeBalears\AreaPrivada\Auth\Identificacion;
use AsdeBalears\AreaPrivada\Auth\Intentos;
use AsdeBalears\AreaPrivada\Domain\Grupo;
use AsdeBalears\AreaPrivada\Domain\Grupos;
use AsdeBalears\AreaPrivada\Frontend\Pages;

defined( 'ABSPATH' ) || exit;

/**
 * Pantalla de acceso al área privada (pública): Grupo Scout + nº de socio/a o
 * email + contraseña (+ código de verificación en dos pasos si lo tiene activado).
 *
 * El email es el asociado a ese nº en el CRM (Auth\Identificacion): se busca en
 * el CRM del grupo elegido en el momento de entrar.
 *
 * El grupo se comprueba en el servidor (AccessHooks::comprobar_acceso): cada
 * usuario solo puede entrar en su grupo "padre". Los administradores de
 * WordPress pueden entrar en cualquiera, que pasa a ser su grupo de trabajo.
 * El código de verificación lo valida Wordfence (campo wfls-token).
 */
final class AccesoView extends View {

	/** Errores que se muestran con el mismo mensaje genérico (no revelan qué falla). */
	private const ERRORES_CREDENCIALES = array( 'invalid_username', 'incorrect_password', 'invalid_email', 'empty_username', 'empty_password', 'asde_grupo' );

	public const MENSAJE_CREDENCIALES = 'Nº de socio/a o email, contraseña o grupo incorrectos.';

	private static string $error      = '';
	private static bool $pedir_codigo = false;

	public function render( string $content = '' ): string {
		if ( is_user_logged_in() ) {
			return '<div class="asde-ap">' . $this->tpl( 'activar/con-sesion', array( 'url_area' => $this->url( 'area' ) ) ) . '</div>';
		}
		$grupos = Grupos::todos();
		return '<div class="asde-ap">' . $this->tpl(
			'acceso/formulario',
			array(
				'grupos'        => $grupos,
				'grupo'         => $this->post( 'grupo' ) ?: ( 1 === count( $grupos ) ? (string) array_key_first( $grupos ) : '' ),
				'usuario'       => $this->post( 'usuario' ),
				'error'         => self::$error,
				'pedir_codigo'  => self::$pedir_codigo,
				'activada'      => '' !== $this->get( 'asde_activada' ),
				'redirect_to'   => $this->destino(),
				'url_recuperar' => $this->url( 'recuperar' ),
				'url_solicitar' => $this->url( 'solicitar' ),
			)
		) . '</div>';
	}

	public function handle_post(): void {
		if ( 'acceder' !== $this->post( 'asde_action' ) || is_user_logged_in() ) {
			return;
		}
		if ( ! wp_verify_nonce( $this->post( '_wpnonce' ), 'asde_acceso' ) ) {
			self::$error = asde_t( 'La sesión del formulario ha caducado. Vuelve a intentarlo.' );
			return;
		}
		$grupo = $this->post( 'grupo' );
		if ( ! Grupos::obtener( $grupo ) ) {
			self::$error = asde_t( 'Elige tu Grupo Scout.' );
			return;
		}
		$login = Identificacion::normalizar( $this->post( 'usuario' ) );
		// El límite de intentos es por cuenta, no por lo escrito: el nº de Scouts de España y el local son la misma cuenta.
		$cuenta = Identificacion::canonico( $login );
		if ( Intentos::bloqueado( $cuenta ) ) {
			self::$error = asde_t( 'Demasiados intentos fallidos. Espera unos minutos antes de volver a intentarlo o recupera tu contraseña.' );
			return;
		}
		// Código de verificación: Wordfence lo lee de $_POST['wfls-token']. Si viene
		// vacío se quita, para que pida el código en vez de darlo por erróneo.
		$codigo = preg_replace( '/\s+/', '', $this->post( 'wfls-token' ) );
		if ( '' === $codigo ) {
			unset( $_POST['wfls-token'] );
		} else {
			$_POST['wfls-token'] = $codigo;
		}
		$clave = isset( $_POST['clave'] ) ? (string) wp_unslash( $_POST['clave'] ) : ''; // phpcs:ignore WordPress.Security

		// Con un email: ¿de qué cuenta del grupo es? (con un nº de socio/a, es ese nº).
		$usuario = Identificacion::usuario_para( $login, Grupos::obtener( $grupo ), $clave );
		if ( is_wp_error( $usuario ) ) {
			$codigo      = $usuario->get_error_code();
			self::$error = in_array( $codigo, self::ERRORES_CREDENCIALES, true ) ? asde_t( self::MENSAJE_CREDENCIALES ) : $usuario->get_error_message();
			if ( 'asde_email_varias' !== $codigo ) { // En ese caso la contraseña era correcta.
				Intentos::fallo( $cuenta );
			}
			return;
		}

		$user = AccessHooks::en_grupo(
			$grupo,
			fn() => wp_signon(
				array(
					'user_login'    => $usuario,
					'user_password' => $clave,
					'remember'      => '1' === $this->post( 'recordar' ),
				),
				is_ssl()
			)
		);

		if ( is_wp_error( $user ) ) {
			$codigo = $user->get_error_code();
			if ( 'wfls_twofactor_required' === $codigo ) {
				self::$pedir_codigo = true;
				self::$error        = asde_t( 'Tienes activada la verificación en dos pasos: escribe el código de 6 cifras de tu app y vuelve a escribir la contraseña.' );
				return; // No es un fallo: la contraseña era correcta.
			}
			if ( 'wfls_twofactor_failed' === $codigo ) {
				self::$pedir_codigo = true;
				self::$error        = asde_t( 'El código de verificación no es correcto o ha caducado. Vuelve a intentarlo.' );
			} elseif ( in_array( $codigo, self::ERRORES_CREDENCIALES, true ) ) {
				self::$error = asde_t( self::MENSAJE_CREDENCIALES );
			} else {
				// Otros avisos (p. ej. de Wordfence): se muestra su texto, sin HTML.
				self::$error = wp_strip_all_tags( $user->get_error_message() ) ?: asde_t( 'No se ha podido iniciar sesión.' );
			}
			Intentos::fallo( $cuenta );
			return;
		}

		Intentos::exito( $cuenta );
		// Administradores: el grupo elegido es su grupo de trabajo en el área privada.
		if ( $user->has_cap( 'manage_options' ) || $user->has_cap( 'asde_multigrupo' ) ) { // v0.50: también la oficina federal.
			update_user_meta( $user->ID, Grupo::META_CONTEXTO, $grupo );
		}
		wp_set_current_user( $user->ID );
		Logger::log(
			'acceso',
			'user',
			(string) $user->ID,
			array(
				'grupo' => Grupos::obtener( $grupo )['numero'] ?? '',
				'con'   => Identificacion::es_email( $login ) ? 'email' : 'usuario',
			)
		);
		$this->redirect( $this->destino() );
	}

	/** Tras entrar solo se vuelve a una página del área privada que pida sesión. */
	private function destino(): string {
		$area  = $this->url( 'area' );
		$pedir = isset( $_REQUEST['redirect_to'] ) && is_string( $_REQUEST['redirect_to'] ) ? esc_url_raw( wp_unslash( $_REQUEST['redirect_to'] ) ) : ''; // phpcs:ignore WordPress.Security
		if ( '' === $pedir || ! str_starts_with( $pedir, $area ) ) {
			return $area;
		}
		foreach ( Pages::PUBLICAS as $key ) {
			if ( str_starts_with( $pedir, $this->url( $key ) ) ) {
				return $area;
			}
		}
		return $pedir;
	}
}
