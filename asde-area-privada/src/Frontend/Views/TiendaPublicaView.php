<?php
namespace AsdeBalears\AreaPrivada\Frontend\Views;

use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Auth\Limite;
use AsdeBalears\AreaPrivada\Domain\Grupo;
use AsdeBalears\AreaPrivada\Domain\Grupos;
use AsdeBalears\AreaPrivada\Domain\Modulos;
use AsdeBalears\AreaPrivada\Idiomas\Idioma;
use AsdeBalears\AreaPrivada\Tienda\Config;
use AsdeBalears\AreaPrivada\Tienda\Enlace;
use AsdeBalears\AreaPrivada\Tienda\PedidosTable;
use AsdeBalears\AreaPrivada\Tienda\Servicio;

defined( 'ABSPATH' ) || exit;

/**
 * La tienda pública (v0.39, doc 48, fase 4, P-174): comprar sin iniciar sesión. Una sola página para:
 *
 * - ver los productos «para todos» y comprar con el enlace del grupo (?grupo=…&clave=…): nombre, email, forma de pago
 *   (transferencia o Bizum), con campo trampa, sello de tiempo y un límite por dispositivo, como «Alta de scouters»;
 * - ver el pedido con el enlace personal del email (?pedido=id&t=…) y cancelarlo mientras no esté pagado.
 *
 * Lo «solo socios» no existe aquí: el filtro está en la consulta (ProductosTable::visibles) y quien llega sin sesión
 * se trata siempre como no socio/a. Si el módulo está apagado o la tienda pública no está abierta en el grupo, el
 * enlace no vale. Las respuestas no dicen si un email ya compró.
 */
final class TiendaPublicaView extends View {

	private const POR_HORA = 6;

	/** Lo que hay que pintar tras un POST fallido (una petición). */
	private static array $estado = array();

	/** Grupo del enlace si la clave es la vigente, el módulo está activo y la tienda pública abierta; si no, null. */
	private function grupo_del_enlace(): ?array {
		$grupo = Grupos::obtener( $this->post( 'grupo' ) ?: $this->get( 'grupo' ) );
		$clave = $this->post( 'clave' ) ?: $this->get( 'clave' );
		if ( ! $grupo || ! Enlace::valida( $grupo, $clave ) || ! Modulos::activo( Modulos::TIENDA, (string) $grupo['numero'] ) ) {
			return null;
		}
		return Grupo::con( $grupo, static fn() => Config::de()['publica'] ) ? $grupo : null;
	}

	/** Pedido del enlace personal (id + token), o null. */
	private function pedido(): ?array {
		$id = absint( $this->post( 'pedido' ) ?: $this->get( 'pedido' ) );
		$p  = $id ? PedidosTable::por_id( $id ) : null;
		if ( ! $p || '' !== $p['socio'] || ! Enlace::token_valido( $p, $this->post( 't' ) ?: $this->get( 't' ) ) ) {
			return null;
		}
		$g = Grupos::por_numero( (string) $p['grupo'] );
		return $g && Modulos::activo( Modulos::TIENDA, (string) $g['numero'] ) ? $p : null;
	}

	public function render( string $content = '' ): string {
		$e    = self::$estado;
		$vars = array(
			'modo'     => 'catalogo',
			'errores'  => (array) ( $e['errores'] ?? array() ),
			'valores'  => (array) ( $e['valores'] ?? array() ),
			'sello'    => self::sello(),
			'clave'    => $this->post( 'clave' ) ?: $this->get( 'clave' ),
			'grupo'    => null,
			'token'    => $this->post( 't' ) ?: $this->get( 't' ),
			'nuevo'    => '1' === $this->get( 'nuevo' ),
			'catalogo' => array(),
			'cfg'      => array(),
			'pedido'   => null,
			'comprar'  => array(),
			'pagar'    => array(),
			'nombre_grupo' => '',
		);
		if ( '' !== $this->get( 'pedido' ) || '' !== $this->post( 'pedido' ) ) {
			$p = $this->pedido();
			if ( ! $p ) {
				$vars['modo'] = 'invalido';
			} else {
				$g                    = Grupos::por_numero( (string) $p['grupo'] );
				$vars['modo']         = 'pedido';
				$vars['nombre_grupo'] = $g ? Grupos::etiqueta( $g ) : '';
				Grupo::con(
					$g,
					function () use ( &$vars, $p ): void {
						$vars['cfg']    = Config::de();
						$vars['pedido'] = $p;
						$vars['pagar']  = Servicio::instrucciones_pago( $p, $vars['cfg'] );
					}
				);
			}
		} else {
			$g = $this->grupo_del_enlace();
			if ( ! $g ) {
				$vars['modo'] = 'sin_enlace';
			} else {
				$vars['grupo']        = $g;
				$vars['nombre_grupo'] = Grupos::etiqueta( $g );
				Grupo::con(
					$g,
					static function () use ( &$vars ): void {
						$vars['cfg']      = Config::de();
						$vars['catalogo'] = Servicio::catalogo( false ); // Sin sesión: nunca lo «solo socios».
					}
				);
			}
		}
		return '<div class="asde-ap">' . $this->tpl( 'tienda/publica', $vars ) . '</div>';
	}

	public function handle_post(): void {
		$accion = $this->post( 'asde_action' );
		if ( ! in_array( $accion, array( 'tienda_invitado_comprar', 'tienda_invitado_cancelar' ), true ) ) {
			return;
		}
		if ( ! wp_verify_nonce( $this->post( '_wpnonce' ), 'asde_' . $accion ) ) {
			self::$estado = array( 'errores' => array( asde_t( 'La sesión del formulario ha caducado. Vuelve a intentarlo.' ) ) );
			return;
		}
		'tienda_invitado_cancelar' === $accion ? $this->cancelar() : $this->comprar();
	}

	private function comprar(): void {
		$g = $this->grupo_del_enlace();
		if ( ! $g ) {
			return; // La pantalla ya dice que el enlace no vale.
		}
		// Campo trampa o enviado en menos de 3 segundos: un programa. Se le contesta como si no hubiera pasado nada.
		if ( '' !== $this->post( 'web' ) || ! self::sello_valido( $this->post( 'sello' ) ) ) {
			self::$estado = array( 'errores' => array( asde_t( 'No se ha podido enviar el pedido. Vuelve a intentarlo.' ) ) );
			return;
		}
		$valores = array(
			'nombre'     => $this->post( 'nombre' ),
			'email'      => $this->post( 'email' ),
			'telefono'   => $this->post( 'telefono' ),
			'nota'       => $this->post( 'nota' ),
			'forma_pago' => $this->post( 'forma_pago' ),
			'cant'       => $this->post_mapa( 'cant' ),
		);
		if ( ! Limite::permitir( 'tienda_publica', self::POR_HORA, HOUR_IN_SECONDS ) ) {
			self::$estado = array(
				'errores' => array( asde_t( 'Has hecho varios intentos seguidos. Espera una hora antes de volver a intentarlo.' ) ),
				'valores' => $valores,
			);
			return;
		}
		$r = Grupo::con(
			$g,
			static fn() => Servicio::comprar_invitado(
				array(
					'nombre'   => $valores['nombre'],
					'email'    => $valores['email'],
					'telefono' => $valores['telefono'],
					'idioma'   => Idioma::actual(),
				),
				$valores['cant'],
				$valores['nota'],
				$valores['forma_pago']
			)
		);
		if ( is_wp_error( $r ) ) {
			self::$estado = array(
				'errores' => array( $r->get_error_message() ),
				'valores' => $valores,
			);
			return;
		}
		$p = PedidosTable::por_id( (int) $r );
		if ( $p ) {
			$this->redirect( add_query_arg( 'nuevo', '1', Enlace::url_pedido( $p ) ) ); // Se ve el pedido; recargar no lo repite.
		}
	}

	private function cancelar(): void {
		$p = $this->pedido();
		if ( ! $p ) {
			return;
		}
		$g  = Grupos::por_numero( (string) $p['grupo'] );
		$ok = Grupo::con( $g, static fn() => null === $p['pago_en'] && null === $p['entregado_en'] && Servicio::cancelar( $p, 'Cancelado por quien compró' ) );
		if ( $ok ) {
			Grupo::con( $g, static fn() => Logger::log( 'tienda_cancelado', 'tienda', (string) $p['id'], array( 'por' => 'invitado' ) ) );
		} else {
			self::$estado = array( 'errores' => array( asde_t( 'Ese pedido ya no se puede cancelar desde aquí. Escribe al grupo.' ) ) );
			return;
		}
		$this->redirect( Enlace::url_pedido( $p ) );
	}

	private static function sello(): string {
		$t = (string) time();
		return $t . '.' . substr( hash_hmac( 'sha256', 'tienda|' . $t, wp_salt( 'nonce' ) ), 0, 16 );
	}

	private static function sello_valido( string $sello ): bool {
		if ( ! preg_match( '/^(\d{10})\.([0-9a-f]{16})$/', $sello, $m ) ) {
			return false;
		}
		$edad   = time() - (int) $m[1];
		$minimo = (int) apply_filters( 'asde_ap_solicitud_segundos_minimos', 3 );
		return hash_equals( substr( hash_hmac( 'sha256', 'tienda|' . $m[1], wp_salt( 'nonce' ) ), 0, 16 ), $m[2] ) && $edad >= $minimo && $edad <= 2 * HOUR_IN_SECONDS;
	}
}
