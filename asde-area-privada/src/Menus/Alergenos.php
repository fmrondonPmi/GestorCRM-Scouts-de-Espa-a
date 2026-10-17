<?php
namespace AsdeBalears\AreaPrivada\Menus;

defined( 'ABSPATH' ) || exit;

/**
 * Los 14 alérgenos de declaración obligatoria (Reglamento (UE) 1169/2011) y lo que se deriva de ellos (doc 49, B1).
 *
 * Es lo único que sabe la aplicación de alimentación: los alérgenos de cada ingrediente los anota una persona del grupo,
 * y los de una receta se calculan juntando los de sus ingredientes (nunca se guardan en la receta).
 */
final class Alergenos {

	/** Código interno => nombre (en castellano; se traduce al mostrarlo). */
	public const LISTA = array(
		'gluten'    => 'Gluten',
		'crustaceos' => 'Crustáceos',
		'huevos'    => 'Huevos',
		'pescado'   => 'Pescado',
		'cacahuetes' => 'Cacahuetes',
		'soja'      => 'Soja',
		'lacteos'   => 'Leche y lácteos',
		'frutos_cascara' => 'Frutos de cáscara',
		'apio'      => 'Apio',
		'mostaza'   => 'Mostaza',
		'sesamo'    => 'Sésamo',
		'sulfitos'  => 'Sulfitos',
		'altramuces' => 'Altramuces',
		'moluscos'  => 'Moluscos',
	);

	/** Intolerancias o dietas habituales => alérgeno que evitan (para la validación de la fase B3). */
	public const INTOLERANCIAS = array(
		'celiaquia' => 'gluten',
		'lactosa'   => 'lacteos',
	);

	/** @return string[] Solo los códigos válidos, sin repetir y en el orden del catálogo. */
	public static function limpiar( $codigos ): array {
		if ( is_string( $codigos ) ) {
			$codigos = explode( ',', $codigos );
		}
		$codigos = array_map( static fn( $c ) => sanitize_key( (string) $c ), (array) $codigos );
		return array_values( array_intersect( array_keys( self::LISTA ), $codigos ) );
	}

	/** Nombre traducido. */
	public static function nombre( string $codigo ): string {
		return isset( self::LISTA[ $codigo ] ) ? asde_t( self::LISTA[ $codigo ] ) : $codigo;
	}

	/** «Gluten, Huevos» (traducidos), o '' si no hay. @param string[] $codigos */
	public static function texto( array $codigos ): string {
		return implode( ', ', array_map( array( self::class, 'nombre' ), $codigos ) );
	}

	/** Une varias listas de códigos, en el orden del catálogo. @param string[][] $listas @return string[] */
	public static function unir( array $listas ): array {
		$todos = array();
		foreach ( $listas as $l ) {
			$todos = array_merge( $todos, (array) $l );
		}
		return self::limpiar( $todos );
	}
}
