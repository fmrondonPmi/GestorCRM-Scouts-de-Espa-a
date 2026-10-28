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
	/** Etiquetas traducidas al idioma de WordPress (doc 62). @return array<array-key, mixed> */
	public static function lista(): array {
		return array(
		'gluten'    => __( 'Gluten', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
		'crustaceos' => __( 'Crustaceans', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
		'huevos'    => __( 'Eggs', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
		'pescado'   => __( 'Fish', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
		'cacahuetes' => __( 'Peanuts', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
		'soja'      => __( 'Soy', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
		'lacteos'   => __( 'Milk and dairy', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
		'frutos_cascara' => __( 'Tree nuts', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
		'apio'      => __( 'Celery', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
		'mostaza'   => __( 'Mustard', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
		'sesamo'    => __( 'Sesame', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
		'sulfitos'  => __( 'Sulphites', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
		'altramuces' => __( 'Lupin', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
		'moluscos'  => __( 'Molluscs', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
	);
	}

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
		return array_values( array_intersect( array_keys( self::lista() ), $codigos ) );
	}

	/** Nombre traducido. */
	public static function nombre( string $codigo ): string {
		return isset( self::lista()[ $codigo ] ) ? ( self::lista()[ $codigo ] ) : $codigo;
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
