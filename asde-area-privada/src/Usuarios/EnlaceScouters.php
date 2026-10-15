<?php
namespace AsdeBalears\AreaPrivada\Usuarios;

use AsdeBalears\AreaPrivada\Domain\ClaveDeGrupo;
use AsdeBalears\AreaPrivada\Frontend\Pages;

defined( 'ABSPATH' ) || exit;

/**
 * Enlace de alta para scouters (v0.16, doc 31): uno por grupo, con una clave aleatoria que
 * Secretaría o Coordinación pueden renovar (la anterior deja de valer). Quien no tiene el
 * enlace no ve el formulario.
 */
final class EnlaceScouters {
	use ClaveDeGrupo; // clave(), renovar(), valida()

	public const OPCION = 'asde_ap_enlace_scouters';

	public static function url( array $grupo ): string {
		return add_query_arg(
			array(
				'grupo' => rawurlencode( (string) $grupo['id'] ),
				'clave' => rawurlencode( self::clave( $grupo ) ),
			),
			Pages::url( 'alta_scouters' )
		);
	}
}
