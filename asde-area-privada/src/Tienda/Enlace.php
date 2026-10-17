<?php
namespace AsdeBalears\AreaPrivada\Tienda;

use AsdeBalears\AreaPrivada\Domain\ClaveDeGrupo;
use AsdeBalears\AreaPrivada\Frontend\Pages;

defined( 'ABSPATH' ) || exit;

/**
 * Enlaces de la tienda pública (v0.39, doc 48, fase 4).
 *
 * - El del grupo, para ver la tienda sin iniciar sesión: una clave aleatoria por grupo que la administración puede
 *   renovar (el anterior deja de valer), como el de alta de scouters y el de la lista de espera.
 * - El personal de cada compra sin sesión (ver el pedido y cancelarlo): id + un token HMAC con la sal del pedido y las
 *   claves del sitio. El token no se guarda en ningún sitio; borrar los datos del invitado (la sal) lo anula.
 */
final class Enlace {
	use ClaveDeGrupo; // clave(), renovar(), valida() del enlace del grupo

	public const OPCION = 'asde_ap_enlace_tienda';

	/** El enlace público de la tienda del grupo. */
	public static function url( array $grupo ): string {
		return Pages::url(
			'tienda_publica',
			array(
				'grupo' => rawurlencode( (string) $grupo['id'] ),
				'clave' => rawurlencode( self::clave( $grupo ) ),
			)
		);
	}

	public static function token( array $pedido ): string {
		return substr( hash_hmac( 'sha256', 'tienda|' . $pedido['id'] . '|' . $pedido['sal'], wp_salt( 'auth' ) ), 0, 32 );
	}

	public static function token_valido( array $pedido, string $token ): bool {
		return '' !== (string) $pedido['sal'] && '' !== $token && hash_equals( self::token( $pedido ), $token );
	}

	/** Enlace personal al pedido de una compra sin sesión. */
	public static function url_pedido( array $pedido ): string {
		return Pages::url(
			'tienda_publica',
			array(
				'pedido' => (string) $pedido['id'],
				't'      => self::token( $pedido ),
			)
		);
	}
}
