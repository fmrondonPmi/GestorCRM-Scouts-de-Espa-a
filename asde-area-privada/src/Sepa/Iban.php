<?php
namespace AsdeBalears\AreaPrivada\Sepa;

defined( 'ABSPATH' ) || exit;

/**
 * IBAN: normalizar, validar (longitud del país y dígitos de control, ISO 13616 /
 * módulo 97), dar formato y enmascarar. Solo países de la zona SEPA.
 */
final class Iban {

	/** Longitud del IBAN de cada país de la zona SEPA. */
	private const LONGITUD = array(
		'AD' => 24, 'AT' => 20, 'BE' => 16, 'BG' => 22, 'CH' => 21, 'CY' => 28, 'CZ' => 24, 'DE' => 22,
		'DK' => 18, 'EE' => 20, 'ES' => 24, 'FI' => 18, 'FR' => 27, 'GB' => 22, 'GI' => 23, 'GR' => 27,
		'HR' => 21, 'HU' => 28, 'IE' => 22, 'IS' => 26, 'IT' => 27, 'LI' => 21, 'LT' => 20, 'LU' => 20,
		'LV' => 21, 'MC' => 27, 'MT' => 31, 'NL' => 18, 'NO' => 15, 'PL' => 28, 'PT' => 25, 'RO' => 24,
		'SE' => 24, 'SI' => 19, 'SK' => 24, 'SM' => 27, 'VA' => 22,
	);

	/** Sin espacios ni guiones y en mayúsculas. */
	public static function normalizar( string $iban ): string {
		return strtoupper( (string) preg_replace( '/[^0-9A-Za-z]/', '', $iban ) );
	}

	public static function es_valido( string $iban ): bool {
		return '' === self::error( $iban );
	}

	/** Por qué no vale, o '' si vale. */
	public static function error( string $iban ): string {
		$i = self::normalizar( $iban );
		if ( '' === $i ) {
			return 'Escribe el IBAN.';
		}
		if ( ! preg_match( '/^[A-Z]{2}\d{2}[A-Z0-9]+$/', $i ) ) {
			return 'El IBAN debe empezar por dos letras del país y dos cifras de control (por ejemplo ES91…).';
		}
		$pais = substr( $i, 0, 2 );
		if ( ! isset( self::LONGITUD[ $pais ] ) ) {
			return 'El IBAN no es de un país de la zona SEPA.';
		}
		if ( strlen( $i ) !== self::LONGITUD[ $pais ] ) {
			return sprintf( 'Un IBAN de %s tiene %d caracteres y este tiene %d.', $pais, self::LONGITUD[ $pais ], strlen( $i ) );
		}
		if ( 1 !== self::resto97( substr( $i, 4 ) . substr( $i, 0, 4 ) ) ) {
			return 'Los dígitos de control del IBAN no cuadran: revisa que esté bien escrito.';
		}
		return '';
	}

	/** «ES91 2100 0418 4502 0005 1332» (grupos de 4). */
	public static function formatear( string $iban ): string {
		return trim( chunk_split( self::normalizar( $iban ), 4, ' ' ) );
	}

	/** «ES91 **** **** ** ******1332»: solo el país, el control y las 4 últimas cifras. */
	public static function enmascarar( string $iban ): string {
		$i = self::normalizar( $iban );
		if ( strlen( $i ) < 8 ) {
			return '';
		}
		return trim( chunk_split( substr( $i, 0, 4 ) . str_repeat( '*', strlen( $i ) - 8 ) . substr( $i, -4 ), 4, ' ' ) );
	}

	/** Resto de dividir entre 97 un texto alfanumérico, con las letras como 10..35 (sin bcmath). */
	public static function resto97( string $reordenado ): int {
		$resto = 0;
		foreach ( str_split( $reordenado ) as $c ) {
			$valor = ctype_alpha( $c ) ? (string) ( ord( $c ) - 55 ) : $c;
			foreach ( str_split( $valor ) as $d ) {
				$resto = ( $resto * 10 + (int) $d ) % 97;
			}
		}
		return $resto;
	}
}
