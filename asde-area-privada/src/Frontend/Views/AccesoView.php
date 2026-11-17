<?php
namespace AsdeBalears\AreaPrivada\Frontend\Views;

use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Auth\AccessHooks;
use AsdeBalears\AreaPrivada\Auth\Identificacion;
use AsdeBalears\AreaPrivada\Auth\Intentos;
use AsdeBalears\AreaPrivada\Crm\Crypto;
use AsdeBalears\AreaPrivada\Domain\Grupo;
use AsdeBalears\AreaPrivada\Domain\Grupos;
use AsdeBalears\AreaPrivada\Frontend\Pages;
use AsdeBalears\AreaPrivada\Usuarios\Usuarios;

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

	/** Minutos que se recuerda el acceso a medias mientras se elige la cuenta. */
	private const MINUTOS_ELEGIR = 5;

	/** Paso «elegir cuenta» (v0.64.3): token del acceso a medias y sus cuentas. @var array{token:string, cuentas:array<string,string>, elegida:string}|null */
	private static ?array $elegir = null;

	public function render( string $content = '' ): string {
		if ( is_user_logged_in() ) {
			return '<div class="asde-ap">' . $this->tpl( 'activar/con-sesion', array( 'url_area' => $this->url( 'area' ) ) ) . '</div>';
		}
		if ( self::$elegir ) {
			return '<div class="asde-ap">' . $this->tpl(
				'acceso/elegir-cuenta',
				array(
					'token'        => self::$elegir['token'],
					'cuentas'      => self::$elegir['cuentas'],
					'elegida'      => self::$elegir['elegida'],
					'error'        => self::$error,
					'pedir_codigo' => self::$pedir_codigo,
					'url_acceso'   => $this->url( 'acceso' ),
				)
			) . '</div>';
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
		if ( is_user_logged_in() ) {
			return;
		}
		$accion = $this->post( 'asde_action' );
		if ( 'elegir_cuenta' === $accion ) {
			$this->elegir_cuenta();
			return;
		}
		if ( 'acceder' !== $accion ) {
			return;
		}
		if ( ! wp_verify_nonce( $this->post_nonce(), 'asde_acceso' ) ) {
			self::$error = __( 'The form session has expired. Try again.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' );
			return;
		}
		$grupo = $this->post( 'grupo' );
		if ( ! Grupos::obtener( $grupo ) ) {
			self::$error = __( 'Choose your Scout Group.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' );
			return;
		}
		$login = Identificacion::normalizar( $this->post( 'usuario' ) );
		// El límite de intentos es por cuenta, no por lo escrito: el nº de Scouts de España y el local son la misma cuenta.
		$cuenta = Identificacion::canonico( $login );
		if ( Intentos::bloqueado( $cuenta ) ) {
			self::$error = __( 'Too many failed attempts. Wait a few minutes before trying again or recover your password.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' );
			return;
		}
		$clave = $this->preparar_codigo();

		// Con un email: ¿de qué cuenta del grupo es? (con un nº de socio/a, es ese nº).
		$usuario = Identificacion::usuario_para( $login, Grupos::obtener( $grupo ), $clave );
		if ( is_wp_error( $usuario ) ) {
			$codigo = $usuario->get_error_code();
			if ( 'asde_email_varias' === $codigo ) {
				// La contraseña es correcta para varias cuentas del mismo email: se deja elegir (v0.64.3).
				$this->ofrecer_cuentas( $grupo, $login, $clave, (array) ( $usuario->get_error_data()['cuentas'] ?? array() ) );
				if ( self::$elegir ) {
					return;
				}
			}
			self::$error = in_array( $codigo, self::ERRORES_CREDENCIALES, true ) ? asde_guardado( self::MENSAJE_CREDENCIALES ) : $usuario->get_error_message();
			if ( 'asde_email_varias' !== $codigo ) { // En ese caso la contraseña era correcta.
				Intentos::fallo( $cuenta );
			}
			return;
		}

		$this->iniciar_sesion( $grupo, $login, $cuenta, $usuario, $clave, '1' === $this->post( 'recordar' ) );
	}

	/** Código de verificación: Wordfence lo lee de $_POST['wfls-token']. Si viene vacío se quita, para que pida el código en vez de darlo por erróneo. Devuelve la contraseña escrita. */
	private function preparar_codigo(): string {
		$codigo = preg_replace( '/\s+/', '', $this->post( 'wfls-token' ) );
		if ( '' === $codigo ) {
			unset( $_POST['wfls-token'] );
		} else {
			$_POST['wfls-token'] = $codigo;
		}
		return isset( $_POST['clave'] ) ? (string) wp_unslash( $_POST['clave'] ) : ''; // phpcs:ignore WordPress.Security
	}

	/**
	 * Valida la contraseña y entra con la cuenta elegida (nº de socio/a ya resuelto). Si hace falta el
	 * código de dos pasos o algo falla, deja el motivo en self::$error / self::$pedir_codigo.
	 */
	private function iniciar_sesion( string $grupo, string $login, string $cuenta, string $usuario, string $clave, bool $recordar, string $token = '' ): void {
		$user = AccessHooks::en_grupo(
			$grupo,
			fn() => wp_signon(
				array(
					'user_login'    => $usuario,
					'user_password' => $clave,
					'remember'      => $recordar,
				),
				is_ssl()
			)
		);

		if ( is_wp_error( $user ) ) {
			$codigo = $user->get_error_code();
			if ( 'wfls_twofactor_required' === $codigo ) {
				self::$pedir_codigo = true;
				self::$error        = __( 'You have two-step verification turned on: enter the 6-digit code from your app and type the password again.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' );
				return; // No es un fallo: la contraseña era correcta.
			}
			if ( AccessHooks::SIN_MEMBRESIA === $codigo ) {
				// La contraseña era correcta: no cuenta como intento fallido (doc 59, RC-NUE-2).
				self::$error = wp_strip_all_tags( $user->get_error_message() );
				Logger::log( 'acceso_sin_membresia', 'user', (string) ( get_user_by( 'login', $usuario )->ID ?? 0 ), array( 'grupo' => Grupos::obtener( $grupo )['numero'] ?? '' ) );
				return;
			}
			if ( 'wfls_twofactor_failed' === $codigo ) {
				self::$pedir_codigo = true;
				self::$error        = __( 'The verification code is incorrect or has expired. Try again.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' );
			} elseif ( in_array( $codigo, self::ERRORES_CREDENCIALES, true ) ) {
				self::$error = asde_guardado( self::MENSAJE_CREDENCIALES );
			} else {
				// Otros avisos (p. ej. de Wordfence): se muestra su texto, sin HTML.
				self::$error = wp_strip_all_tags( $user->get_error_message() ) ?: __( 'Sign-in was not possible.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' );
			}
			Intentos::fallo( $cuenta );
			return;
		}

		Intentos::exito( $cuenta );
		if ( '' !== $token ) {
			delete_transient( 'asde_acc_' . $token ); // Un solo uso: entra o vuelve a empezar (la redirección termina la petición).
		}
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

	// ---------------------------------------------------------------------
	// v0.64.3 · Una persona con varias cuentas (scouter y tutor/a…) elige con cuál entra
	// ---------------------------------------------------------------------

	/**
	 * Guarda el acceso a medias (cifrado, 5 minutos, sin la contraseña en el HTML) y prepara el paso
	 * de elegir cuenta. Si no hay al menos dos cuentas que ofrecer no hace nada.
	 *
	 * @param string[] $logins Usuarios de las cuentas cuya contraseña coincide.
	 */
	private function ofrecer_cuentas( string $grupo, string $login, string $clave, array $logins ): void {
		$cuentas = self::cuentas_con_etiqueta( $logins );
		if ( count( $cuentas ) < 2 ) {
			return;
		}
		$token = wp_generate_password( 32, false, false );
		$datos = array(
			'grupo'    => $grupo,
			'login'    => $login,
			'clave'    => $clave,
			'cuentas'  => array_keys( $cuentas ),
			'recordar' => '1' === $this->post( 'recordar' ),
		);
		set_transient( 'asde_acc_' . $token, Crypto::encrypt( (string) wp_json_encode( $datos ) ), self::MINUTOS_ELEGIR * MINUTE_IN_SECONDS );
		self::$elegir = array(
			'token'   => $token,
			'cuentas' => $cuentas,
			'elegida' => '',
		);
	}

	/** @param string[] $logins @return array<string, string> usuario => texto del desplegable (solo los que existen). */
	private static function cuentas_con_etiqueta( array $logins ): array {
		$cuentas = array();
		foreach ( $logins as $l ) {
			$u = get_user_by( 'login', (string) $l );
			if ( $u ) {
				$cuentas[ $u->user_login ] = self::etiqueta_cuenta( $u );
			}
		}
		return $cuentas;
	}

	/** Texto de una cuenta en el desplegable: su tipo (Scouter, Tutor/a…) y su usuario. */
	public static function etiqueta_cuenta( \WP_User $u ): string {
		$nombres = array();
		foreach ( Usuarios::roles_asde( $u ) as $rol ) {
			$nombres[] = translate_user_role( (string) ( wp_roles()->roles[ $rol ]['name'] ?? $rol ) );
		}
		return ( $nombres ? implode( ' + ', $nombres ) : __( 'Account', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) . ' · ' . $u->user_login;
	}

	/** @return array{grupo:string, login:string, clave:string, cuentas:string[], recordar:bool}|null */
	private static function leer_pendiente( string $token ): ?array {
		if ( '' === $token ) {
			return null;
		}
		$cifrado = get_transient( 'asde_acc_' . $token );
		$json    = is_string( $cifrado ) ? Crypto::decrypt( $cifrado ) : null;
		$datos   = null !== $json ? json_decode( $json, true ) : null;
		return is_array( $datos ) && isset( $datos['grupo'], $datos['login'], $datos['clave'], $datos['cuentas'] ) ? $datos : null;
	}

	/** Segundo paso: entra con la cuenta elegida del desplegable. */
	private function elegir_cuenta(): void {
		if ( ! wp_verify_nonce( $this->post_nonce(), 'asde_acceso_elegir' ) ) {
			self::$error = __( 'The form session has expired. Try again.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' );
			return;
		}
		$token   = (string) preg_replace( '/[^A-Za-z0-9]/', '', $this->post( 'token' ) );
		$pend    = self::leer_pendiente( $token );
		$elegida = $this->post( 'cuenta' );
		if ( ! $pend ) {
			self::$error = __( 'The sign-in has expired. Enter your details again.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' );
			return;
		}
		// La pantalla normal, si hay que volver a ella, recuerda el grupo y el email.
		$_POST['grupo']   = $pend['grupo'];
		$_POST['usuario'] = $pend['login'];
		$cuenta           = Identificacion::canonico( $pend['login'] );
		if ( ! in_array( $elegida, $pend['cuentas'], true ) ) {
			self::$error = __( 'Choose one of the accounts in the list.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' );
			self::reabrir_elegir( $token, $pend, '' );
			return;
		}
		if ( Intentos::bloqueado( $cuenta ) ) {
			delete_transient( 'asde_acc_' . $token );
			self::$error = __( 'Too many failed attempts. Wait a few minutes before trying again or recover your password.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' );
			return;
		}
		$this->preparar_codigo(); // El código de dos pasos se pide en este mismo paso; la contraseña es la guardada.
		$this->iniciar_sesion( $pend['grupo'], $pend['login'], $cuenta, $elegida, $pend['clave'], (bool) $pend['recordar'], $token );
		if ( self::$pedir_codigo ) {
			self::reabrir_elegir( $token, $pend, $elegida ); // Falta el código: se conserva el acceso a medias.
		} else {
			delete_transient( 'asde_acc_' . $token ); // Un solo uso: o ha entrado o vuelve a empezar.
		}
	}

	/** Vuelve a pintar el paso de elegir cuenta con el mismo acceso a medias. @param array<string, mixed> $pend */
	private static function reabrir_elegir( string $token, array $pend, string $elegida ): void {
		self::$elegir = array(
			'token'   => $token,
			'cuentas' => self::cuentas_con_etiqueta( (array) $pend['cuentas'] ),
			'elegida' => $elegida,
		);
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
