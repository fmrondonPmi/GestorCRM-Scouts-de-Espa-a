<?php
namespace AsdeBalears\AreaPrivada\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Provincias de España con su código de dos cifras (el de los códigos postales y del INE;
 * lista de https://www.codigospostales.com/). Los dos primeros dígitos del nº de socio/a
 * local son la provincia del grupo, siempre con dos cifras: el 0 inicial NO se quita
 * (Illes Balears es «07», no «7»).
 */
final class Provincias {

	/** @return array<string, string> código de dos cifras => nombre */
	public static function todas(): array {
		return array(
			'01' => 'Araba/Álava',
			'02' => 'Albacete',
			'03' => 'Alicante/Alacant',
			'04' => 'Almería',
			'05' => 'Ávila',
			'06' => 'Badajoz',
			'07' => 'Illes Balears',
			'08' => 'Barcelona',
			'09' => 'Burgos',
			'10' => 'Cáceres',
			'11' => 'Cádiz',
			'12' => 'Castellón/Castelló',
			'13' => 'Ciudad Real',
			'14' => 'Córdoba',
			'15' => 'A Coruña',
			'16' => 'Cuenca',
			'17' => 'Girona',
			'18' => 'Granada',
			'19' => 'Guadalajara',
			'20' => 'Gipuzkoa',
			'21' => 'Huelva',
			'22' => 'Huesca',
			'23' => 'Jaén',
			'24' => 'León',
			'25' => 'Lleida',
			'26' => 'La Rioja',
			'27' => 'Lugo',
			'28' => 'Madrid',
			'29' => 'Málaga',
			'30' => 'Murcia',
			'31' => 'Navarra',
			'32' => 'Ourense',
			'33' => 'Asturias',
			'34' => 'Palencia',
			'35' => 'Las Palmas',
			'36' => 'Pontevedra',
			'37' => 'Salamanca',
			'38' => 'Santa Cruz de Tenerife',
			'39' => 'Cantabria',
			'40' => 'Segovia',
			'41' => 'Sevilla',
			'42' => 'Soria',
			'43' => 'Tarragona',
			'44' => 'Teruel',
			'45' => 'Toledo',
			'46' => 'Valencia/València',
			'47' => 'Valladolid',
			'48' => 'Bizkaia',
			'49' => 'Zamora',
			'50' => 'Zaragoza',
			'51' => 'Ceuta',
			'52' => 'Melilla',
		);
	}

	/** ¿Es un código de provincia (dos cifras, de 01 a 52)? */
	public static function valida( string $codigo ): bool {
		return isset( self::todas()[ $codigo ] );
	}

	/** Nombre de la provincia ('' si el código no es válido). */
	public static function nombre( string $codigo ): string {
		return self::todas()[ $codigo ] ?? '';
	}

	/**
	 * Código de dos cifras a partir de lo que escribe una persona o guarda un dato antiguo
	 * («7», «07», «7 »…): se rellena con el 0 inicial. null si no es una provincia.
	 */
	public static function normalizar( string $valor ): ?string {
		$v = preg_replace( '/\D/', '', $valor );
		if ( '' === $v || strlen( $v ) > 2 ) {
			return null;
		}
		$v = str_pad( $v, 2, '0', STR_PAD_LEFT );
		return self::valida( $v ) ? $v : null;
	}
}
