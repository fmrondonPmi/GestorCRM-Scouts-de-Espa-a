<?php
namespace AsdeBalears\AreaPrivada\Frontend\Views;

use AsdeBalears\AreaPrivada\Auth\Limite;
use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Crm\Crypto;
use AsdeBalears\AreaPrivada\Domain\GrupoInvitaciones;
use AsdeBalears\AreaPrivada\Domain\GrupoSolicitudes;
use AsdeBalears\AreaPrivada\Domain\Grupos;
use AsdeBalears\AreaPrivada\Domain\Organizacion;
use AsdeBalears\AreaPrivada\Domain\VerificacionGrupo;

defined( 'ABSPATH' ) || exit;

/**
 * «Alta de grupo» (pública, v0.50, doc 50 fase 5): el formulario al que lleva una invitación de la oficina.
 *
 * - Sin una invitación vigente (token inválido, usado, anulado o caducado) se ve siempre la misma página neutra: no se
 *   revela el motivo ni si el token existió. Los intentos con tokens malos se limitan por dispositivo.
 * - «Comprobar acceso» valida la credencial del CRM **en la misma pantalla** (P-170). Si sale bien, la credencial se
 *   guarda cifrada y de corta vida, ligada a esa invitación: **nunca vuelve en el HTML** (ni en un campo oculto) y el
 *   envío usa la guardada, no lo que llegue del navegador.
 * - Al enviar, la invitación se gasta de forma atómica y se crea la solicitud (dos envíos a la vez = una sola).
 * - Si la comprobación falla, la invitación **no** se gasta.
 */
final class AltaGrupoView extends View {

	private const COMPROBAR_POR_HORA = 8;
	private const INTENTOS_POR_HORA  = 20;
	private const VERIFICACION_MIN   = 30;

	/** Estado de la petición para pintar la respuesta. */
	private static array $estado = array();

	/** Para las pruebas: vacía lo recordado de una petición a otra. */
	public static function olvidar(): void {
		self::$estado = array();
	}

	private function token(): string {
		$t = $this->post( 't' ) ?: $this->get( 't' );
		return preg_match( '/^[0-9a-f]{48}$/', $t ) ? $t : '';
	}

	/** ¿Se puede servir la página? (HTTPS obligatorio fuera de desarrollo.) */
	private function permitida(): bool {
		return Organizacion::es_oficina() && ( is_ssl() || 'production' !== wp_get_environment_type() );
	}

	private function clave_verificacion( array $invitacion ): string {
		return 'asde_ap_agv_' . substr( (string) $invitacion['hash'], 0, 32 );
	}

	/** @return array{numero:string, usuario:string, clave:string, crm_id:string}|null Lo comprobado de esta invitación. */
	private function verificacion( array $invitacion ): ?array {
		$v = get_transient( $this->clave_verificacion( $invitacion ) );
		$d = is_string( $v ) ? Crypto::decrypt( $v ) : null;
		$d = null !== $d ? json_decode( $d, true ) : null;
		return is_array( $d ) && isset( $d['numero'], $d['usuario'], $d['clave'], $d['crm_id'] ) ? $d : null;
	}

	public function render( string $content = '' ): string {
		if ( ! headers_sent() ) {
			header( 'X-Robots-Tag: noindex, nofollow' );
		}
		if ( ! empty( self::$estado['enviado'] ) ) { // Recién enviado: la invitación ya está gastada.
			return '<div class="asde-ap">' . $this->tpl( 'alta-grupo/recibido', array() ) . '</div>';
		}
		$invitacion = $this->permitida() ? GrupoInvitaciones::vigente( $this->token() ) : null;
		if ( ! $invitacion ) {
			if ( ! Limite::permitir( 'alta_grupo_token', self::INTENTOS_POR_HORA, HOUR_IN_SECONDS ) ) {
				self::$estado = array( 'limite' => true );
			}
			return '<div class="asde-ap">' . $this->tpl( 'alta-grupo/neutra', array() ) . '</div>';
		}
		$e = self::$estado;
		if ( ! empty( $e['enviado'] ) ) {
			return '<div class="asde-ap">' . $this->tpl( 'alta-grupo/recibido', array() ) . '</div>';
		}
		$verificada = $this->verificacion( $invitacion );
		$numero     = Grupos::normalizar_numero( $this->post( 'numero' ) ) ?? '';
		return '<div class="asde-ap">' . $this->tpl(
			'alta-grupo/formulario',
			array(
				'token'      => $this->token(),
				'verificada' => $verificada && $verificada['numero'] === ( '' !== $numero ? $numero : $verificada['numero'] ),
				'mensaje'    => (string) ( $e['mensaje'] ?? '' ),
				'errores'    => (array) ( $e['errores'] ?? array() ),
				'limite'     => ! empty( $e['limite'] ),
				'sello'      => self::sello(),
				'valores'    => array(
					'numero'       => $this->post( 'numero' ) ?: ( $verificada['numero'] ?? '' ),
					'nombre'       => $this->post( 'nombre' ),
					'sede'         => $this->post( 'sede' ),
					'email'        => $this->post( 'email' ) ?: (string) $invitacion['email_destino'],
					'cif'          => $this->post( 'cif' ),
					'denominacion' => $this->post( 'denominacion' ),
					'usuario'      => $verificada ? '' : $this->post( 'usuario' ),
					'dni'          => $this->post( 'dni' ),
					'persona'      => $this->post( 'persona' ),
					'rol'          => $this->post( 'rol' ),
				),
			)
		) . '</div>';
	}

	public function handle_post(): void {
		$accion = $this->post( 'asde_action' );
		if ( ! in_array( $accion, array( 'alta_grupo_comprobar', 'alta_grupo_enviar' ), true ) || is_user_logged_in() ) {
			return;
		}
		if ( ! $this->permitida() || ! wp_verify_nonce( $this->post( '_wpnonce' ), 'asde_alta_grupo' ) ) {
			self::$estado = array( 'errores' => array( asde_t( 'La sesión del formulario ha caducado. Vuelve a intentarlo.' ) ) );
			return;
		}
		$invitacion = GrupoInvitaciones::vigente( $this->token() );
		if ( ! $invitacion ) {
			return; // La pantalla dice que el enlace no vale, igual que con cualquier otro token.
		}
		if ( 'alta_grupo_comprobar' === $accion ) {
			$this->comprobar( $invitacion );
		} else {
			$this->enviar( $invitacion );
		}
	}

	private function comprobar( array $invitacion ): void {
		$numero = Grupos::normalizar_numero( $this->post( 'numero' ) );
		if ( null === $numero ) {
			self::$estado = array( 'errores' => array( asde_t( 'El nº de grupo no es válido (hasta tres cifras).' ) ) );
			return;
		}
		// Contra el uso del formulario para probar credenciales ajenas: límite por dispositivo y por invitación.
		if ( ! Limite::permitir( 'alta_grupo_comprobar', self::COMPROBAR_POR_HORA, HOUR_IN_SECONDS ) || ! Limite::permitir( 'alta_grupo_comprobar_' . substr( (string) $invitacion['hash'], 0, 16 ), self::COMPROBAR_POR_HORA, HOUR_IN_SECONDS ) ) {
			self::$estado = array( 'limite' => true );
			return;
		}
		$previa = $this->verificacion( $invitacion );
		$usuario = $this->post( 'usuario' );
		$clave   = $this->post_cruda( 'clave' );
		// Ya comprobada con este nº y sin escribir otra cosa: no hace falta repetirlo.
		if ( $previa && $previa['numero'] === $numero && '' === $clave ) {
			self::$estado = array( 'mensaje' => asde_t( 'Acceso comprobado: el usuario ve el grupo en el CRM.' ) );
			return;
		}
		$r = VerificacionGrupo::comprobar( $numero, $usuario, $clave );
		if ( ! $r['ok'] ) {
			delete_transient( $this->clave_verificacion( $invitacion ) );
			self::$estado = array( 'errores' => array( $r['mensaje'] ) );
			return;
		}
		set_transient(
			$this->clave_verificacion( $invitacion ),
			Crypto::encrypt(
				(string) wp_json_encode(
					array(
						'numero'  => $numero,
						'usuario' => $usuario,
						'clave'   => $clave,
						'crm_id'  => $r['crm_id'],
					)
				)
			),
			self::VERIFICACION_MIN * MINUTE_IN_SECONDS
		);
		self::$estado = array( 'mensaje' => $r['mensaje'] );
	}

	private function enviar( array $invitacion ): void {
		// Campo trampa o enviado demasiado deprisa: un programa. Se le contesta como a todos.
		if ( '' !== $this->post( 'web' ) || ! self::sello_valido( $this->post( 'sello' ) ) ) {
			self::$estado = array( 'enviado' => true );
			return;
		}
		$numero = Grupos::normalizar_numero( $this->post( 'numero' ) );
		$v      = $this->verificacion( $invitacion );
		$d      = array(
			'numero'       => (string) $numero,
			'nombre'       => $this->post( 'nombre' ),
			'sede'         => $this->post( 'sede' ),
			'email'        => strtolower( $this->post( 'email' ) ),
			'cif'          => strtoupper( preg_replace( '/[\s-]/', '', $this->post( 'cif' ) ) ),
			'denominacion' => $this->post( 'denominacion' ),
			'rol'          => $this->post( 'rol' ),
			'dni'          => strtoupper( preg_replace( '/[\s.-]/', '', $this->post( 'dni' ) ) ),
			'persona'      => $this->post( 'persona' ),
		);
		$errores = array();
		if ( null === $numero ) {
			$errores[] = asde_t( 'El nº de grupo no es válido (hasta tres cifras).' );
		} elseif ( Grupos::por_numero( $numero ) || GrupoSolicitudes::pendiente_con_numero( $numero ) ) {
			$errores[] = asde_t( 'Ese nº de grupo ya está dado de alta o pendiente de revisar. Si es el vuestro, habla con la oficina.' );
		}
		if ( ! $v || $v['numero'] !== $numero ) {
			$errores[] = asde_t( 'Comprueba primero el acceso al CRM con el botón «Comprobar acceso».' );
		}
		if ( '' === trim( $d['nombre'] ) ) {
			$errores[] = asde_t( 'Escribe el nombre del grupo.' );
		}
		if ( ! is_email( $d['email'] ) ) {
			$errores[] = asde_t( 'Escribe un email válido para el grupo.' );
		}
		if ( ! isset( GrupoSolicitudes::ROLES[ $d['rol'] ] ) ) {
			$errores[] = asde_t( 'Elige el rol de la primera persona con acceso.' );
		}
		if ( '' === trim( $d['persona'] ) || ! preg_match( '/^[A-Z0-9]{6,12}$/', $d['dni'] ) ) {
			$errores[] = asde_t( 'Escribe el nombre y el DNI o NIE de la primera persona con acceso.' );
		}
		if ( $errores ) {
			self::$estado = array( 'errores' => $errores );
			return;
		}
		// El gasto de la invitación y la solicitud van juntos: gana el primero; si falla guardar, se devuelve la invitación.
		if ( ! GrupoInvitaciones::consumir( (int) $invitacion['id'] ) ) {
			self::$estado = array( 'errores' => array( asde_t( 'Este enlace ya se ha usado o ha caducado.' ) ) );
			return;
		}
		$id = GrupoSolicitudes::crear( (int) $invitacion['id'], $d + array( 'crm_id' => $v['crm_id'], 'usuario' => $v['usuario'], 'clave' => $v['clave'] ) );
		if ( ! $id ) {
			GrupoInvitaciones::devolver( (int) $invitacion['id'] );
			self::$estado = array( 'errores' => array( asde_t( 'No se ha podido guardar la solicitud. Inténtalo de nuevo.' ) ) );
			return;
		}
		delete_transient( $this->clave_verificacion( $invitacion ) );
		Logger::log( 'grupo_solicitud_recibida', 'solicitud_grupo', (string) $id, array( 'grupo' => $d['numero'] ) );
		self::$estado = array( 'enviado' => true );
	}

	/** La contraseña tal cual se escribió (sin recortar ni limpiar: puede llevar espacios o símbolos). */
	private function post_cruda( string $campo ): string {
		return isset( $_POST[ $campo ] ) && is_scalar( $_POST[ $campo ] ) ? (string) wp_unslash( $_POST[ $campo ] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput -- contraseña: no se debe limpiar; el nonce se comprueba en handle_post()
	}

	/** Sello con la hora de carga del formulario (firmado): para exigir un tiempo mínimo. */
	private static function sello(): string {
		$t = (string) time();
		return $t . '.' . substr( hash_hmac( 'sha256', 'alta_grupo|' . $t, wp_salt( 'nonce' ) ), 0, 16 );
	}

	private static function sello_valido( string $sello ): bool {
		if ( ! preg_match( '/^(\d{10})\.([0-9a-f]{16})$/', $sello, $m ) ) {
			return false;
		}
		$edad   = time() - (int) $m[1];
		$minimo = (int) apply_filters( 'asde_ap_solicitud_segundos_minimos', 3 );
		return hash_equals( substr( hash_hmac( 'sha256', 'alta_grupo|' . $m[1], wp_salt( 'nonce' ) ), 0, 16 ), $m[2] ) && $edad >= $minimo && $edad <= 2 * HOUR_IN_SECONDS;
	}
}
