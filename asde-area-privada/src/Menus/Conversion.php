<?php
namespace AsdeBalears\AreaPrivada\Menus;

defined( 'ABSPATH' ) || exit;

/** Utilidades pequeñas de texto de los menús. */
final class Conversion {

	/** Clave para comparar nombres: minúsculas, sin acentos y solo letras y cifras separadas por un espacio. */
	public static function clave( string $texto ): string {
		return trim( (string) preg_replace( '/[^a-z0-9]+/', ' ', strtolower( remove_accents( $texto ) ) ) );
	}

	/** Número escrito con coma o punto decimal. */
	public static function numero( string $texto ): float {
		$t = trim( str_replace( ' ', '', $texto ) );
		if ( str_contains( $t, ',' ) ) {
			$t = str_replace( '.', '', $t );
			$t = str_replace( ',', '.', $t );
		}
		return is_numeric( $t ) ? (float) $t : 0.0;
	}
}
