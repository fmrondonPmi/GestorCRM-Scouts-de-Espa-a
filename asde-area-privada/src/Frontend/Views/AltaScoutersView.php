<?php
namespace AsdeBalears\AreaPrivada\Frontend\Views;

use AsdeBalears\AreaPrivada\Auth\Limite;
use AsdeBalears\AreaPrivada\Crm\CrmException;
use AsdeBalears\AreaPrivada\Domain\Grupo;
use AsdeBalears\AreaPrivada\Domain\Grupos;
use AsdeBalears\AreaPrivada\Usuarios\AltaScouterService;
use AsdeBalears\AreaPrivada\Usuarios\EnlaceScouters;

defined( 'ABSPATH' ) || exit;

/**
 * «Alta de scouters» (pública, v0.16, doc 31): el enlace que el equipo de coordinación pasa a sus
 * scouters (?grupo=…&clave=…). Sin la clave vigente del grupo no se ve el formulario.
 *
 * Contra abusos, como «Solicitar acceso»: 8 intentos por dispositivo y hora, un campo trampa y un
 * tiempo mínimo para rellenarlo. La respuesta es siempre la misma, sea cual sea el DNI.
 */
final class AltaScoutersView extends View {

	private const POR_HORA = 8;

	/** Estado del envío para pintar la respuesta (una petición). */
	private static array $estado = array();

	/** Grupo del enlace si la clave es la vigente; si no, null. */
	private function grupo_del_enlace(): ?array {
		$grupo = Grupos::obtener( $this->post( 'grupo' ) ?: $this->get( 'grupo' ) );
		$clave = $this->post( 'clave' ) ?: $this->get( 'clave' );
		return $grupo && '' !== $grupo['crm_id'] && EnlaceScouters::valida( $grupo, $clave ) && \AsdeBalears\AreaPrivada\Domain\Modulos::activo( \AsdeBalears\AreaPrivada\Domain\Modulos::ALTA_SCOUTERS, (string) $grupo['numero'] ) ? $grupo : null;
	}

	public function render( string $content = '' ): string {
		if ( is_user_logged_in() ) {
			return '<div class="asde-ap">' . $this->tpl( 'activar/con-sesion', array( 'url_area' => $this->url( 'area' ) ) ) . '</div>';
		}
		$grupo = $this->grupo_del_enlace();
		$e     = self::$estado;
		return '<div class="asde-ap">' . $this->tpl(
			'alta-scouters/formulario',
			array(
				'grupo'         => $grupo,
				'enviado'       => ! empty( $e['enviado'] ),
				'limite'        => ! empty( $e['limite'] ),
				'sin_crm'       => ! empty( $e['sin_crm'] ),
				'errores'       => (array) ( $e['errores'] ?? array() ),
				'valores'       => array(
					'documento'         => $this->post( 'documento' ),
					'modo'              => $this->post( 'modo' ) ?: AltaScouterService::MODO_SCOUTER,
					'secciones'         => $this->post_array( 'secciones' ),
					'responsabilidades' => $this->post_array( 'responsabilidades' ),
					'email'             => $this->post( 'email' ),
				),
				'clave'         => $this->post( 'clave' ) ?: $this->get( 'clave' ),
				'sello'         => self::sello(),
				'contacto'      => $grupo ? Grupos::email_contacto( $grupo ) : '',
				'url_login'     => $this->url( 'acceso' ),
				'url_recuperar' => $this->url( 'recuperar' ),
			)
		) . '</div>';
	}

	public function handle_post(): void {
		if ( 'alta_scouter' !== $this->post( 'asde_action' ) || is_user_logged_in() ) {
			return;
		}
		if ( ! wp_verify_nonce( $this->post( '_wpnonce' ), 'asde_alta_scouter' ) ) {
			self::$estado = array( 'errores' => array( asde_t( 'La sesión del formulario ha caducado. Vuelve a intentarlo.' ) ) );
			return;
		}
		$grupo = $this->grupo_del_enlace();
		if ( ! $grupo ) {
			return; // La pantalla ya dice que el enlace no vale.
		}
		// Campo trampa o enviado en menos de 3 segundos: un programa. Se le contesta como a todos.
		if ( '' !== $this->post( 'web' ) || ! self::sello_valido( $this->post( 'sello' ) ) ) {
			self::$estado = array( 'enviado' => true );
			return;
		}
		$d = array(
			'documento'         => mb_substr( $this->post( 'documento' ), 0, 20 ),
			'modo'              => $this->post( 'modo' ),
			'secciones'         => $this->post_array( 'secciones' ),
			'responsabilidades' => $this->post_array( 'responsabilidades' ),
			'email'             => strtolower( $this->post( 'email' ) ),
		);
		$errores = AltaScouterService::errores( $d['modo'], $d['secciones'], $d['responsabilidades'], $d['email'] );
		if ( '' === trim( $d['documento'] ) ) {
			array_unshift( $errores, asde_t( 'Escribe tu DNI o NIE.' ) );
		}
		if ( $errores ) {
			self::$estado = array( 'errores' => $errores );
			return;
		}
		if ( ! Limite::permitir( 'alta_scouter', self::POR_HORA, HOUR_IN_SECONDS ) ) {
			self::$estado = array( 'limite' => true );
			return;
		}
		$r = Grupo::con(
			$grupo,
			static function () use ( $d ): array {
				$servicio = AltaScouterService::make();
				if ( ! $servicio ) {
					return array( 'resultado' => 'sin_crm' );
				}
				try {
					return $servicio->registrar( $d );
				} catch ( CrmException $e ) {
					return array( 'resultado' => 'sin_crm' );
				}
			}
		);
		self::$estado = 'sin_crm' === $r['resultado'] ? array( 'sin_crm' => true ) : array( 'enviado' => true );
	}

	/** Sello con la hora de carga del formulario (firmado): para exigir un tiempo mínimo. */
	private static function sello(): string {
		$t = (string) time();
		return $t . '.' . substr( hash_hmac( 'sha256', 'alta_scouter|' . $t, wp_salt( 'nonce' ) ), 0, 16 );
	}

	private static function sello_valido( string $sello ): bool {
		if ( ! preg_match( '/^(\d{10})\.([0-9a-f]{16})$/', $sello, $m ) ) {
			return false;
		}
		$edad   = time() - (int) $m[1];
		$minimo = (int) apply_filters( 'asde_ap_solicitud_segundos_minimos', 3 );
		return hash_equals( substr( hash_hmac( 'sha256', 'alta_scouter|' . $m[1], wp_salt( 'nonce' ) ), 0, 16 ), $m[2] ) && $edad >= $minimo && $edad <= 2 * HOUR_IN_SECONDS;
	}
}
