<?php
namespace AsdeBalears\AreaPrivada\Tesoreria\Banco;

defined( 'ABSPATH' ) || exit;

/**
 * Juego de caracteres de los ficheros del banco. La Norma 43 recomienda la
 * página de códigos 850 (T1000850); muchos bancos exportan en Windows-1252 o
 * UTF-8, sobre todo en CSV.
 */
final class Codificacion {

	public const UTF8   = 'UTF-8';
	public const CP850  = 'CP850';
	public const WIN    = 'Windows-1252';
	public const LISTA  = array(
		'auto'      => 'Automática',
		self::UTF8  => 'UTF-8',
		self::CP850 => 'CP850 (la de la Norma 43)',
		self::WIN   => 'Windows-1252 / ISO-8859-1',
	);

	public static function detectar( string $s ): string {
		if ( str_starts_with( $s, "\xEF\xBB\xBF" ) ) {
			return self::UTF8;
		}
		if ( ! preg_match( '/[\x80-\xFF]/', $s ) ) {
			return self::CP850; // Solo ASCII: da igual.
		}
		if ( mb_check_encoding( $s, 'UTF-8' ) ) {
			return self::UTF8;
		}
		// Letras españolas más habituales en cada juego (Ñ ñ á é í ó ú Á É Í Ó Ú ü Ç ç ª º).
		$win   = preg_match_all( '/[\xD1\xF1\xE1\xE9\xED\xF3\xFA\xC1\xC9\xCD\xD3\xDA\xFC\xC7\xE7\xAA\xBA]/', $s );
		$cp850 = preg_match_all( '/[\xA5\xA4\xA0\x82\xA1\xA2\xA3\xB5\x90\xD6\xE0\xE9\x81\x80\x87\xA6\xA7]/', $s );
		return $cp850 > $win ? self::CP850 : self::WIN;
	}

	public static function a_utf8( string $s, string $desde ): string {
		if ( self::UTF8 === $desde || ! preg_match( '/[\x80-\xFF]/', $s ) ) {
			return $s;
		}
		$r = @mb_convert_encoding( $s, 'UTF-8', $desde ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( ! is_string( $r ) || '' === $r ) {
			$r = function_exists( 'iconv' ) ? (string) @iconv( $desde, 'UTF-8//TRANSLIT', $s ) : ''; // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		return '' !== $r ? $r : $s;
	}

	/** Texto de un fichero en UTF-8 (sin BOM). */
	public static function texto_utf8( string $s, string $cod = 'auto' ): string {
		$cod = 'auto' === $cod ? self::detectar( $s ) : $cod;
		if ( str_starts_with( $s, "\xEF\xBB\xBF" ) ) {
			$s = substr( $s, 3 );
		}
		return self::a_utf8( $s, $cod );
	}

	/** Parece un fichero binario (p. ej. un Excel .xlsx) y no de texto. */
	public static function es_binario( string $s ): bool {
		return str_contains( substr( $s, 0, 4096 ), "\0" ) || str_starts_with( $s, "PK\x03\x04" );
	}
}
