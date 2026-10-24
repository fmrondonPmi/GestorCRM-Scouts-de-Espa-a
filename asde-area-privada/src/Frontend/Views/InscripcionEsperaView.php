<?php
namespace AsdeBalears\AreaPrivada\Frontend\Views;

use AsdeBalears\AreaPrivada\Auth\Limite;
use AsdeBalears\AreaPrivada\Domain\Grupos;
use AsdeBalears\AreaPrivada\Domain\Modulos;
use AsdeBalears\AreaPrivada\Idiomas\Idioma;
use AsdeBalears\AreaPrivada\ListaEspera\Avisos;
use AsdeBalears\AreaPrivada\ListaEspera\CamposExtra;
use AsdeBalears\AreaPrivada\ListaEspera\Enlaces;
use AsdeBalears\AreaPrivada\ListaEspera\Formulario;
use AsdeBalears\AreaPrivada\ListaEspera\Inscripciones;
use AsdeBalears\AreaPrivada\Usuarios\Contacto;
use AsdeBalears\AreaPrivada\Usuarios\EmailCuenta;

defined( 'ABSPATH' ) || exit;

/**
 * «Inscripción en la lista de espera» (pública, v0.32, doc 39). Una sola página para:
 *
 * - apuntarse con el enlace del grupo (?grupo=…&clave=…), con campo trampa, sello de tiempo
 *   y un límite por dispositivo, como «Alta de scouters»;
 * - confirmar el email (?confirmar=id&t=…): el enlace del email enseña un botón y la
 *   confirmación va por POST (así no la hace un antivirus que abre los enlaces);
 * - la página personal (?espera=id&t=…): pide el email de la inscripción (la «clave») y, si
 *   coincide, enseña los datos con «Mantener mis datos» y «Borrar mis datos»;
 * - «¿Ya estás en la lista?»: reenvía los enlaces al email escrito.
 *
 * Las respuestas no dicen si un email o una persona están en la lista.
 */
final class InscripcionEsperaView extends View {

	private const POR_HORA         = 8;
	private const ACCESOS_POR_HORA = 10;
	private const ENLACES_POR_HORA = 5;

	/** Lo que hay que pintar tras un POST (una petición). */
	private static array $estado = array();

	/** Grupo del enlace si la clave es la vigente y el módulo está activo; si no, null. */
	private function grupo_del_enlace(): ?array {
		$grupo = Grupos::obtener( $this->post( 'grupo' ) ?: $this->get( 'grupo' ) );
		$clave = $this->post( 'clave' ) ?: $this->get( 'clave' );
		return $grupo && Enlaces::valida( $grupo, $clave ) && Modulos::activo( Modulos::LISTA_ESPERA, (string) $grupo['numero'] ) ? $grupo : null;
	}

	/** Inscripción del enlace personal (id + token del uso pedido), o null. */
	private function inscripcion( string $param, string $uso ): ?array {
		$id   = absint( $this->post( 'id' ) ?: $this->get( $param ) );
		$fila = $id ? Inscripciones::por_id( $id ) : null;
		// Una «Asociado/a» ya no tiene datos ni página personal (doc 59, RC-NUE-1).
		return $fila && Inscripciones::ASOCIADA !== $fila['estado'] && Enlaces::token_valido( $fila, $uso, $this->post( 't' ) ?: $this->get( 't' ) ) ? $fila : null;
	}

	public function render( string $content = '' ): string {
		$e    = self::$estado;
		$vars = array(
			'modo'    => (string) ( $e['modo'] ?? '' ),
			'errores' => (array) ( $e['errores'] ?? array() ),
			'fila'    => $e['fila'] ?? null,
			'grupo'   => null,
			'valores' => array(),
			'campos'  => array(),
			'clave'   => '',
			'sello'   => self::sello(),
			'token'   => $this->post( 't' ) ?: $this->get( 't' ),
			'email'   => (string) ( $e['email'] ?? '' ),
			'aviso'   => (string) ( $e['aviso'] ?? '' ),
			'destino' => (string) ( $e['destino'] ?? '' ),
		);
		if ( '' === $vars['modo'] ) {
			if ( '' !== $this->get( 'confirmar' ) ) {
				$fila          = $this->inscripcion( 'confirmar', Enlaces::CONFIRMAR );
				$vars['modo']  = $fila ? ( Inscripciones::SIN_CONFIRMAR === $fila['estado'] ? 'confirmar' : 'ya_confirmada' ) : 'invalido';
				$vars['fila']  = $fila;
			} elseif ( '' !== $this->get( 'espera' ) ) {
				$fila         = $this->inscripcion( 'espera', Enlaces::ACCESO );
				$vars['modo'] = $fila ? 'acceso' : 'invalido';
				$vars['fila'] = $fila;
			} else {
				$vars['modo'] = 'formulario';
			}
		}
		if ( in_array( $vars['modo'], array( 'formulario', 'enviado' ), true ) ) {
			$vars['grupo'] = $this->grupo_del_enlace();
			$vars['clave'] = $this->post( 'clave' ) ?: $this->get( 'clave' );
			if ( ! $vars['grupo'] ) {
				$vars['modo'] = 'sin_enlace';
			}
		}
		if ( 'formulario' === $vars['modo'] ) {
			foreach ( Formulario::CAMPOS as $c ) {
				$vars['valores'][ $c ] = $this->post( $c );
			}
			$vars['campos']      = CamposExtra::del_grupo( (string) $vars['grupo']['numero'] );
			$vars['declaracion'] = CamposExtra::declaracion( (string) $vars['grupo']['numero'] );
			foreach ( $vars['campos'] as $c ) {
				$vars['valores'][ 'extra_' . $c['id'] ] = $this->post( 'extra_' . $c['id'] );
			}
		}
		if ( $vars['fila'] ) {
			$g                    = Grupos::por_numero( (string) $vars['fila']['grupo'] );
			$vars['nombre_grupo'] = $g ? Grupos::etiqueta( $g ) : '';
		}
		return '<div class="asde-ap">' . $this->tpl( 'lista-espera/publica', $vars ) . '</div>';
	}

	public function handle_post(): void {
		$accion = $this->post( 'asde_action' );
		if ( ! in_array( $accion, array( 'espera_inscribir', 'espera_confirmar', 'espera_entrar', 'espera_mantener', 'espera_borrar', 'espera_enlaces' ), true ) ) {
			return;
		}
		if ( ! wp_verify_nonce( $this->post( '_wpnonce' ), 'asde_' . $accion ) ) {
			self::$estado = array(
				'modo'    => 'espera_enlaces' === $accion || 'espera_inscribir' === $accion ? 'formulario' : 'invalido',
				'errores' => array( asde_t( 'La sesión del formulario ha caducado. Vuelve a intentarlo.' ) ),
			);
			return;
		}
		switch ( $accion ) {
			case 'espera_inscribir':
				$this->inscribir();
				return;
			case 'espera_enlaces':
				$this->reenviar();
				return;
			case 'espera_confirmar':
				$this->confirmar();
				return;
			default:
				$this->personal( $accion );
		}
	}

	private function inscribir(): void {
		$grupo = $this->grupo_del_enlace();
		if ( ! $grupo ) {
			return; // La pantalla ya dice que el enlace no vale.
		}
		// Campo trampa o enviado en menos de 3 segundos: un programa. Se le contesta como a todos.
		if ( '' !== $this->post( 'web' ) || ! self::sello_valido( $this->post( 'sello' ) ) ) {
			self::$estado = array( 'modo' => 'enviado' );
			return;
		}
		$in = array();
		foreach ( Formulario::CAMPOS as $c ) {
			$in[ $c ] = 'observaciones' === $c && isset( $_POST[ $c ] ) && is_string( $_POST[ $c ] ) ? sanitize_textarea_field( wp_unslash( $_POST[ $c ] ) ) : $this->post( $c ); // phpcs:ignore WordPress.Security.NonceVerification -- comprobado en handle_post()
		}
		$r      = Formulario::validar( $in );
		$campos = CamposExtra::del_grupo( (string) $grupo['numero'] );
		$extra  = CamposExtra::validar( $campos, $this->extras_enviados( $campos ) );
		if ( $r['errores'] || $extra['errores'] ) {
			self::$estado = array(
				'modo'    => 'formulario',
				'errores' => array_merge( $r['errores'], $extra['errores'] ),
			);
			return;
		}
		if ( ! Limite::permitir( 'lista_espera', self::POR_HORA, HOUR_IN_SECONDS ) ) {
			self::$estado = array(
				'modo'    => 'formulario',
				'errores' => array( asde_t( 'Has hecho varios intentos seguidos. Espera una hora antes de volver a intentarlo.' ) ),
			);
			return;
		}
		$d           = $r['datos'];
		$d['idioma'] = Idioma::actual();
		$numero      = (string) $grupo['numero'];
		$repetida    = Inscripciones::repetida( $numero, $d );
		if ( $repetida ) {
			// La misma persona con el mismo email (P-129): no se repite; se le reenvía lo suyo.
			Inscripciones::SIN_CONFIRMAR === $repetida['estado'] ? Avisos::confirmar( $repetida ) : Avisos::enlaces( array( $repetida ) );
		} else {
			$id   = Inscripciones::crear( $numero, $d, $extra['respuestas'] );
			$fila = $id ? Inscripciones::por_id( $id ) : null;
			if ( $fila ) {
				Avisos::confirmar( $fila );
			}
		}
		self::$estado = array(
			'modo'    => 'enviado',
			'destino' => Contacto::enmascarar( $d['email'] ),
		);
	}

	/**
	 * Lo enviado en los campos propios del grupo (`extra_<id>`).
	 *
	 * @param array[] $campos Definiciones del grupo.
	 * @return array<string, string>
	 */
	private function extras_enviados( array $campos ): array {
		$out = array();
		foreach ( $campos as $c ) {
			$k = 'extra_' . $c['id'];
			// phpcs:ignore WordPress.Security.NonceVerification -- comprobado en handle_post()
			$out[ $k ] = isset( $_POST[ $k ] ) && is_string( $_POST[ $k ] ) ? sanitize_textarea_field( wp_unslash( $_POST[ $k ] ) ) : '';
		}
		return $out;
	}

	private function reenviar(): void {
		$email = EmailCuenta::normalizar( $this->post( 'email_enlaces' ) );
		if ( ! is_email( $email ) ) {
			self::$estado = array(
				'modo'    => (string) ( $this->post( 'volver' ) ?: 'formulario' ),
				'errores' => array( asde_t( 'Escribe un email válido.' ) ),
			);
			return;
		}
		if ( Limite::permitir( 'lista_espera_enlaces', self::ENLACES_POR_HORA, HOUR_IN_SECONDS ) ) {
			$filas = Inscripciones::por_email( $email );
			if ( $filas ) {
				Avisos::enlaces( $filas );
			}
		}
		// La misma respuesta exista o no (y aunque se haya pasado del límite).
		self::$estado = array(
			'modo'    => 'enlaces',
			'destino' => Contacto::enmascarar( $email ),
		);
	}

	private function confirmar(): void {
		$fila = $this->inscripcion( 'confirmar', Enlaces::CONFIRMAR );
		if ( ! $fila ) {
			self::$estado = array( 'modo' => 'invalido' );
			return;
		}
		if ( Inscripciones::SIN_CONFIRMAR === $fila['estado'] && Inscripciones::confirmar( $fila['id'] ) ) {
			$fila = Inscripciones::por_id( $fila['id'] );
			if ( $fila ) {
				Avisos::confirmada( $fila );
			}
		}
		self::$estado = array(
			'modo' => 'confirmada',
			'fila' => $fila,
		);
	}

	/** Página personal: entrar con el email, mantener o borrar. */
	private function personal( string $accion ): void {
		$fila  = $this->inscripcion( 'espera', Enlaces::ACCESO );
		$email = EmailCuenta::normalizar( $this->post( 'email' ) );
		if ( ! $fila ) {
			self::$estado = array( 'modo' => 'invalido' );
			return;
		}
		if ( 'espera_entrar' === $accion && ! Limite::permitir( 'lista_espera_acceso', self::ACCESOS_POR_HORA, HOUR_IN_SECONDS ) ) {
			self::$estado = array(
				'modo'    => 'acceso',
				'fila'    => $fila,
				'errores' => array( asde_t( 'Has hecho varios intentos seguidos. Espera una hora antes de volver a intentarlo.' ) ),
			);
			return;
		}
		if ( '' === $email || ! hash_equals( (string) $fila['email_huella'], EmailCuenta::huella( $email ) ) ) {
			self::$estado = array(
				'modo'    => 'acceso',
				'fila'    => $fila,
				'errores' => array( asde_t( 'Ese email no es el de esta inscripción.' ) ),
			);
			return;
		}
		$aviso = '';
		if ( 'espera_mantener' === $accion && Inscripciones::SIN_CONFIRMAR !== $fila['estado'] ) {
			Inscripciones::renovar( $fila['id'] );
			$fila  = Inscripciones::por_id( $fila['id'] ) ?? $fila;
			$aviso = 'mantenidos';
		} elseif ( 'espera_borrar' === $accion ) {
			if ( '1' !== $this->post( 'seguro' ) ) {
				self::$estado = array(
					'modo'    => 'dentro',
					'fila'    => $fila,
					'email'   => $email,
					'errores' => array( asde_t( 'Marca la casilla para confirmar que quieres borrar tus datos.' ) ),
				);
				return;
			}
			Inscripciones::borrar( $fila['id'], 'persona' );
			self::$estado = array( 'modo' => 'borrada' );
			return;
		}
		self::$estado = array(
			'modo'  => 'dentro',
			'fila'  => $fila,
			'email' => $email,
			'aviso' => $aviso,
		);
	}

	/** Sello con la hora de carga del formulario (firmado): para exigir un tiempo mínimo. */
	private static function sello(): string {
		$t = (string) time();
		return $t . '.' . substr( hash_hmac( 'sha256', 'lista_espera|' . $t, wp_salt( 'nonce' ) ), 0, 16 );
	}

	private static function sello_valido( string $sello ): bool {
		if ( ! preg_match( '/^(\d{10})\.([0-9a-f]{16})$/', $sello, $m ) ) {
			return false;
		}
		$edad   = time() - (int) $m[1];
		$minimo = (int) apply_filters( 'asde_ap_solicitud_segundos_minimos', 3 );
		return hash_equals( substr( hash_hmac( 'sha256', 'lista_espera|' . $m[1], wp_salt( 'nonce' ) ), 0, 16 ), $m[2] ) && $edad >= $minimo && $edad <= 2 * HOUR_IN_SECONDS;
	}
}
