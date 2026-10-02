<?php
namespace AsdeBalears\AreaPrivada\Frontend\Views;

use AsdeBalears\AreaPrivada\Auth\Limite;
use AsdeBalears\AreaPrivada\Crm\CrmException;
use AsdeBalears\AreaPrivada\Domain\Grupo;
use AsdeBalears\AreaPrivada\Domain\Grupos;
use AsdeBalears\AreaPrivada\Usuarios\SolicitudService;
use AsdeBalears\AreaPrivada\Usuarios\Solicitudes;

defined( 'ABSPATH' ) || exit;

/**
 * «Solicitar acceso» (pública): el enlace que puede usar cualquier asociado o su
 * familia (?grupo=684 elige el grupo). Ver Usuarios\SolicitudService.
 *
 * Contra abusos: 8 intentos por dispositivo y hora, un campo trampa invisible y
 * un tiempo mínimo para rellenar el formulario. A quien pide solo se le dice si
 * se ha podido comprobar o no: nunca se le enseña nada del CRM.
 */
final class SolicitarView extends View {

	private const POR_HORA = 8;

	/** Estado del envío para pintar la respuesta (una petición). */
	private static array $estado = array();

	public function render( string $content = '' ): string {
		if ( is_user_logged_in() ) {
			return '<div class="asde-ap">' . $this->tpl( 'activar/con-sesion', array( 'url_area' => $this->url( 'area' ) ) ) . '</div>';
		}
		$grupos = array_filter( Grupos::todos(), static fn( array $g ) => '' !== $g['crm_id'] );
		$pedido = $this->post( 'grupo' ) ?: $this->get( 'grupo' );
		$grupo  = isset( $grupos[ $pedido ] ) ? $pedido : ( 1 === count( $grupos ) ? (string) array_key_first( $grupos ) : '' );
		$e      = self::$estado;
		return '<div class="asde-ap">' . $this->tpl(
			'solicitudes/formulario',
			array(
				'grupos'    => $grupos,
				'grupo'     => $grupo,
				'resultado' => (string) ( $e['resultado'] ?? '' ),
				'contacto'  => Grupos::email_contacto( Grupos::obtener( $grupo ) ),
				'valores'   => array(
					'tipo'         => $e['tipo'] ?? ( $this->post( 'tipo' ) ?: Solicitudes::TUTOR ),
					'documento'    => $this->post( 'documento' ),
					'nacimiento'   => $this->post( 'nacimiento' ),
					'email'        => $this->post( 'email' ),
					'nombre'       => $this->post( 'nombre' ),
					'nombre_menor' => $this->post( 'nombre_menor' ),
				),
				'errores'   => (array) ( $e['errores'] ?? array() ),
				'sello'     => self::sello(),
				'url_login' => $this->url( 'acceso' ),
				'url_recuperar' => $this->url( 'recuperar' ),
			)
		) . '</div>';
	}

	public function handle_post(): void {
		if ( 'solicitar' !== $this->post( 'asde_action' ) || is_user_logged_in() ) {
			return;
		}
		if ( ! wp_verify_nonce( $this->post( '_wpnonce' ), 'asde_solicitar' ) ) {
			self::$estado = array( 'errores' => array( 'La sesión del formulario ha caducado. Vuelve a intentarlo.' ) );
			return;
		}
		// Campo trampa o enviado en menos de 3 segundos: un programa, no una persona.
		if ( '' !== $this->post( 'web' ) || ! self::sello_valido( $this->post( 'sello' ) ) ) {
			self::$estado = array( 'resultado' => SolicitudService::NO_EXISTE );
			return;
		}

		$grupo = Grupos::obtener( $this->post( 'grupo' ) );
		$d     = array(
			'tipo'         => Solicitudes::SOCIO === $this->post( 'tipo' ) ? Solicitudes::SOCIO : Solicitudes::TUTOR,
			'documento'    => mb_substr( $this->post( 'documento' ), 0, 20 ),
			'nacimiento'   => $this->post( 'nacimiento' ),
			'email'        => strtolower( $this->post( 'email' ) ),
			'nombre'       => mb_substr( sanitize_text_field( $this->post( 'nombre' ) ), 0, 120 ),
			'nombre_menor' => mb_substr( sanitize_text_field( $this->post( 'nombre_menor' ) ), 0, 120 ),
		);
		$errores = array();
		if ( ! $grupo || '' === $grupo['crm_id'] ) {
			$errores[] = 'Elige tu Grupo Scout.';
		}
		$fecha = \DateTimeImmutable::createFromFormat( '!Y-m-d', $d['nacimiento'] );
		if ( ! $fecha || $fecha->format( 'Y-m-d' ) !== $d['nacimiento'] || $fecha > new \DateTimeImmutable( 'today' ) || (int) $fecha->format( 'Y' ) < 1920 ) {
			$errores[] = 'Escribe la fecha de nacimiento del socio/a.';
		}
		if ( ! is_email( $d['email'] ) ) {
			$errores[] = 'Escribe un email válido: ahí te llegará la invitación.';
		}
		if ( mb_strlen( $d['nombre'] ) < 3 ) {
			$errores[] = 'Escribe tu nombre y apellidos.';
		}
		if ( $errores ) {
			self::$estado = array(
				'errores' => $errores,
				'tipo'    => $d['tipo'],
			);
			return;
		}
		if ( ! Limite::permitir( 'solicitar', self::POR_HORA, HOUR_IN_SECONDS ) ) {
			self::$estado = array( 'resultado' => 'limite' );
			return;
		}

		$r = Grupo::con(
			$grupo,
			static function () use ( $d ): array {
				$servicio = SolicitudService::make();
				if ( ! $servicio ) {
					return array( 'resultado' => 'sin_crm' );
				}
				try {
					return $servicio->solicitar( $d );
				} catch ( CrmException $e ) {
					return array( 'resultado' => 'sin_crm' );
				}
			}
		);
		self::$estado = array(
			'resultado' => (string) $r['resultado'],
			// Si el tipo no cuadraba, el formulario vuelve con el que toca.
			'tipo'      => match ( $r['resultado'] ) {
				SolicitudService::MENOR_14 => Solicitudes::TUTOR,
				SolicitudService::ADULTO   => Solicitudes::SOCIO,
				default                    => $d['tipo'],
			},
		);
	}

	/** Sello con la hora de carga del formulario (firmado): para exigir un tiempo mínimo. */
	private static function sello(): string {
		$t = (string) time();
		return $t . '.' . substr( hash_hmac( 'sha256', 'solicitar|' . $t, wp_salt( 'nonce' ) ), 0, 16 );
	}

	private static function sello_valido( string $sello ): bool {
		if ( ! preg_match( '/^(\d{10})\.([0-9a-f]{16})$/', $sello, $m ) ) {
			return false;
		}
		$edad   = time() - (int) $m[1];
		$minimo = (int) apply_filters( 'asde_ap_solicitud_segundos_minimos', 3 );
		return hash_equals( substr( hash_hmac( 'sha256', 'solicitar|' . $m[1], wp_salt( 'nonce' ) ), 0, 16 ), $m[2] ) && $edad >= $minimo && $edad <= 2 * HOUR_IN_SECONDS;
	}
}
