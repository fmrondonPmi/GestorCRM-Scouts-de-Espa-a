<?php
namespace AsdeBalears\AreaPrivada\Frontend\Views;

use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Auth\Identificacion;
use AsdeBalears\AreaPrivada\Domain\Grupos;
use AsdeBalears\AreaPrivada\Usuarios\EmailCuenta;
use AsdeBalears\AreaPrivada\Usuarios\Invitacion;
use AsdeBalears\AreaPrivada\Usuarios\Usuarios;

defined( 'ABSPATH' ) || exit;

/**
 * "¿Has olvidado tu contraseña?" (pública). El enlace se envía al email de la
 * cuenta (Contacto::email: el del CRM si viene de un campo del CRM; si no, el
 * guardado cifrado en la web). La respuesta es siempre la misma, exista o no el usuario.
 *
 * Se puede pedir con el nº de socio/a o con el email de la cuenta (se encuentra
 * por su huella, en cualquier grupo). Si el email es el de varias cuentas (p. ej.
 * la propia y la familiar), llega un enlace para cada una.
 */
final class RecuperarView extends View {

	private const ESPERA = 5 * MINUTE_IN_SECONDS;

	/** Peticiones por dispositivo (IP) y hora. */
	private const POR_IP = 10;

	private static bool $enviado = false;

	public function render( string $content = '' ): string {
		if ( is_user_logged_in() ) {
			return '<div class="asde-ap">' . $this->tpl( 'activar/con-sesion', array( 'url_area' => $this->url( 'area' ) ) ) . '</div>';
		}
		return '<div class="asde-ap">' . $this->tpl(
			'recuperar/formulario',
			array(
				'enviado'   => self::$enviado,
				'login'     => $this->url( 'acceso' ),
				'solicitar' => $this->url( 'solicitar' ),
			)
		) . '</div>';
	}

	public function handle_post(): void {
		if ( 'recuperar' !== $this->post( 'asde_action' ) || is_user_logged_in() ) {
			return;
		}
		if ( ! wp_verify_nonce( $this->post( '_wpnonce' ), 'asde_recuperar' ) ) {
			return;
		}
		self::$enviado = true; // La respuesta no revela si el usuario existe.

		$login = Identificacion::normalizar( $this->post( 'usuario' ) );
		$ip    = (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$clave = 'asde_ap_rec_' . md5( strtolower( $login ) . '|' . $ip );
		if ( '' === $login || get_transient( $clave ) || ! self::contar_peticion( $ip ) ) {
			return; // Petición repetida o demasiadas desde el mismo dispositivo: no se envía (evita abusos).
		}
		set_transient( $clave, 1, self::ESPERA );

		if ( Identificacion::es_email( $login ) ) {
			// Cuentas con ese email en cualquier grupo (email guardado en la web).
			$cuentas = array();
			foreach ( EmailCuenta::usuarios( $login ) as $u ) {
				$cuentas[ $u->ID ] = $u;
			}
			// Con un solo grupo, también las cuentas de antes de la v0.10 que aún no lo tienen guardado (se busca en el CRM).
			$grupos = Grupos::todos();
			if ( 1 === count( $grupos ) ) {
				foreach ( (array) Identificacion::cuentas_con_email( $login, reset( $grupos ) ) as $u ) {
					$cuentas[ $u->ID ] = $u;
				}
			}
			foreach ( $cuentas as $user ) {
				$this->enviar( $user );
			}
			if ( ! $cuentas && is_email( $login ) ) {
				// Usuarios de WordPress "normales" con ese email (p. ej. administradores).
				$user = get_user_by( 'email', $login );
				if ( $user && ! Usuarios::email_origen( $user->ID ) ) {
					retrieve_password( $user->user_login );
				}
			}
			return;
		}

		$user = get_user_by( 'login', \AsdeBalears\AreaPrivada\Domain\Identidad::resolver( $login ) ); // Vale el nº de Scouts de España o el local.
		if ( ! $user ) {
			return;
		}
		if ( Usuarios::email_origen( $user->ID ) ) {
			$this->enviar( $user );
			return;
		}
		// Usuarios de WordPress "normales" (p. ej. administradores): flujo estándar.
		if ( is_email( $user->user_email ) ) {
			retrieve_password( $user->user_login );
		}
	}

	/** Enlace para elegir contraseña, al email del CRM (no a las cuentas desactivadas). */
	private function enviar( \WP_User $user ): void {
		if ( Usuarios::DESACTIVADO !== Usuarios::estado( $user ) && Invitacion::enviar( $user, Invitacion::RESET ) ) {
			Logger::log( 'recuperacion_enviada', 'user', (string) $user->ID );
		}
	}

	/** Suma una petición del dispositivo; false si ya ha hecho demasiadas en la última hora. La IP no se guarda: solo un HMAC. */
	private static function contar_peticion( string $ip ): bool {
		$clave = 'asde_ap_rec_ip_' . substr( hash_hmac( 'sha256', $ip, wp_salt( 'auth' ) ), 0, 32 );
		$v     = get_transient( $clave );
		if ( ! is_array( $v ) || $v['hasta'] <= time() ) {
			$v = array(
				'n'     => 0,
				'hasta' => time() + HOUR_IN_SECONDS,
			);
		}
		if ( $v['n'] >= self::POR_IP ) {
			return false;
		}
		++$v['n'];
		set_transient( $clave, $v, max( 1, $v['hasta'] - time() ) );
		return true;
	}
}
