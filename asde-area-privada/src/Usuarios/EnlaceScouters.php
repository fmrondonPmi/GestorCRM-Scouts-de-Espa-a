<?php
namespace AsdeBalears\AreaPrivada\Usuarios;

use AsdeBalears\AreaPrivada\Frontend\Pages;

defined( 'ABSPATH' ) || exit;

/**
 * Enlace de alta para scouters (v0.16, doc 31): uno por grupo, con una clave aleatoria que
 * Secretaría o Coordinación pueden renovar (la anterior deja de valer). Quien no tiene el
 * enlace no ve el formulario.
 */
final class EnlaceScouters {

	public const OPCION = 'asde_ap_enlace_scouters';

	/** @return array<string, string> id del grupo => clave */
	private static function claves(): array {
		$c = get_option( self::OPCION, array() );
		return is_array( $c ) ? $c : array();
	}

	/** Clave actual del grupo (se crea la primera vez). */
	public static function clave( array $grupo ): string {
		$claves = self::claves();
		$id     = (string) $grupo['id'];
		if ( empty( $claves[ $id ] ) ) {
			$claves[ $id ] = wp_generate_password( 24, false, false );
			update_option( self::OPCION, $claves, false );
		}
		return $claves[ $id ];
	}

	/** Genera una clave nueva: el enlace anterior deja de valer. */
	public static function renovar( array $grupo ): string {
		$claves                          = self::claves();
		$claves[ (string) $grupo['id'] ] = wp_generate_password( 24, false, false );
		update_option( self::OPCION, $claves, false );
		return $claves[ (string) $grupo['id'] ];
	}

	public static function valida( array $grupo, string $clave ): bool {
		$actual = self::claves()[ (string) $grupo['id'] ] ?? '';
		return '' !== $actual && '' !== $clave && hash_equals( $actual, $clave );
	}

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
