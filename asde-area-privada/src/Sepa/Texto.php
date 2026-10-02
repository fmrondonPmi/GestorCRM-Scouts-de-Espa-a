<?php
namespace AsdeBalears\AreaPrivada\Sepa;

defined( 'ABSPATH' ) || exit;

/**
 * Texto para los ficheros SEPA: el juego de caracteres del esquema es solo
 *   a-z A-Z 0-9 / - ? : ( ) . , ' + y espacio
 * (sin tildes ni ñ). Se quitan los acentos, lo que no vale se cambia por un
 * espacio y se corta a la longitud máxima del campo.
 */
final class Texto {

	public static function limpiar( string $texto, int $max = 70 ): string {
		$t = remove_accents( $texto );
		$t = (string) preg_replace( "/[^A-Za-z0-9\/\-?:().,'+ ]+/", ' ', $t );
		$t = trim( (string) preg_replace( '/\s+/', ' ', $t ) );
		return rtrim( substr( $t, 0, $max ) );
	}

	/** Referencias e identificadores (mandato, extremo a extremo…): sin espacios y en mayúsculas. */
	public static function referencia( string $texto, int $max = 35 ): string {
		return substr( strtoupper( (string) preg_replace( "/[^A-Za-z0-9\/\-?:().,'+]+/", '', remove_accents( $texto ) ) ), 0, $max );
	}

	/** Un importe en céntimos (entero) como «12.34», con punto y dos decimales. */
	public static function importe( int $centimos ): string {
		return number_format( $centimos / 100, 2, '.', '' );
	}
}
