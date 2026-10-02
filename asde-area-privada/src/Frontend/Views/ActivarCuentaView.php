<?php
namespace AsdeBalears\AreaPrivada\Frontend\Views;

use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Usuarios\Invitacion;
use AsdeBalears\AreaPrivada\Usuarios\Usuarios;

defined( 'ABSPATH' ) || exit;

/** Página pública: elegir contraseña con el enlace del email (invitación o recuperación). */
final class ActivarCuentaView extends View {

	public const LONGITUD_MINIMA = 10;

	/** Error del último POST, para mostrarlo al pintar la página en la misma petición. */
	private static string $error = '';

	public function render( string $content = '' ): string {
		if ( is_user_logged_in() ) {
			return '<div class="asde-ap">' . $this->tpl( 'activar/con-sesion', array( 'url_area' => $this->url( 'area' ) ) ) . '</div>';
		}

		$user_id = (int) ( $this->post( 'u' ) ?: $this->get( 'u' ) );
		$token   = $this->post( 't' ) ?: $this->get( 't' );
		$user    = Invitacion::validar( $user_id, $token );

		if ( ! $user ) {
			return '<div class="asde-ap">' . $this->tpl( 'activar/invalido', array( 'url_recuperar' => $this->url( 'recuperar' ) ) ) . '</div>';
		}

		return '<div class="asde-ap">' . $this->tpl(
			'activar/formulario',
			array(
				'user'     => $user,
				'tipo'     => Invitacion::tipo( $user_id ),
				'u'        => $user_id,
				't'        => $token,
				'error'    => self::$error,
				'longitud' => self::LONGITUD_MINIMA,
			)
		) . '</div>';
	}

	public function handle_post(): void {
		if ( 'activar' !== $this->post( 'asde_action' ) || is_user_logged_in() ) {
			return;
		}
		$user_id = (int) $this->post( 'u' );
		$user    = Invitacion::validar( $user_id, $this->post( 't' ) );
		if ( ! $user ) {
			return; // render() mostrará "enlace no válido".
		}
		if ( ! wp_verify_nonce( $this->post( '_wpnonce' ), 'asde_activar_' . $user_id ) ) {
			self::$error = 'La sesión del formulario ha caducado. Vuelve a intentarlo.';
			return;
		}

		$pass  = isset( $_POST['pass1'] ) ? (string) wp_unslash( $_POST['pass1'] ) : ''; // phpcs:ignore WordPress.Security
		$pass2 = isset( $_POST['pass2'] ) ? (string) wp_unslash( $_POST['pass2'] ) : ''; // phpcs:ignore WordPress.Security
		$login = rtrim( $user->user_login, 'Tt' );

		if ( mb_strlen( $pass ) < self::LONGITUD_MINIMA ) {
			self::$error = sprintf( 'La contraseña debe tener al menos %d caracteres.', self::LONGITUD_MINIMA );
			return;
		}
		if ( $pass !== $pass2 ) {
			self::$error = 'Las dos contraseñas no coinciden.';
			return;
		}
		if ( strlen( $login ) >= 4 && false !== stripos( $pass, $login ) ) {
			self::$error = 'La contraseña no puede contener tu nº de socio/a.';
			return;
		}

		$tipo = Invitacion::tipo( $user_id );
		wp_set_password( $pass, $user_id );
		Invitacion::consumir( $user_id );
		if ( Usuarios::DESACTIVADO !== Usuarios::estado( $user ) ) {
			update_user_meta( $user_id, Usuarios::META_ESTADO, Usuarios::ACTIVO );
		}
		if ( ! Usuarios::ha_activado( $user ) ) {
			update_user_meta( $user_id, Usuarios::META_ACTIVADA_EN, current_time( 'mysql', true ) );
		}
		Logger::log( Invitacion::RESET === $tipo ? 'contrasena_cambiada' : 'cuenta_activada', 'user', (string) $user_id );

		$this->redirect( add_query_arg( 'asde_activada', '1', wp_login_url( $this->url( 'area' ) ) ) );
	}
}
