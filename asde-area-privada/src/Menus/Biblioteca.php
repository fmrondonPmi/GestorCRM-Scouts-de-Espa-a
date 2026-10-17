<?php
namespace AsdeBalears\AreaPrivada\Menus;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- tablas propias del plugin ({prefix}asde_*).

/**
 * La biblioteca básica común (doc 49, P-195): unas pocas recetas de ejemplo con sus ingredientes y alérgenos, incluidas en el
 * plugin. No llevan datos de personas ni están asociadas a ningún grupo ni evento. Un alérgeno mal anotado es un riesgo real:
 * por eso son pocas, van marcadas «de ejemplo: revísalas» y cada grupo las copia y las edita sin que cambie la común.
 *
 * Se siembran una sola vez (idempotente: lo que ya existe por nombre no se toca).
 */
final class Biblioteca {

	/** Aviso que llevan las recetas de ejemplo. */
	public const OBSERVACION = 'Receta de ejemplo: comprueba los alérgenos de las marcas que uses antes de fiarte de ella.';

	/** Ingredientes: nombre => [unidad, alérgenos que contiene, trazas, ¿de origen animal?]. */
	private const INGREDIENTES = array(
		'Lentejas'        => array( 'g', array(), array(), false ),
		'Zanahoria'       => array( 'g', array(), array(), false ),
		'Cebolla'         => array( 'g', array(), array(), false ),
		'Patata'          => array( 'g', array(), array(), false ),
		'Ajo'             => array( 'g', array(), array(), false ),
		'Pimentón'        => array( 'cda', array(), array(), false ),
		'Aceite de oliva' => array( 'ml', array(), array(), false ),
		'Sal'             => array( 'g', array(), array(), false ),
		'Macarrones'      => array( 'g', array( 'gluten' ), array(), false ),
		'Tomate frito'    => array( 'g', array(), array(), false ),
		'Queso rallado'   => array( 'g', array( 'lacteos' ), array(), true ),
		'Arroz'           => array( 'g', array(), array(), false ),
		'Huevo'           => array( 'ud', array( 'huevos' ), array(), true ),
		'Muslos de pollo' => array( 'g', array(), array(), true ),
		'Ternera picada'  => array( 'g', array(), array(), true ),
		'Pan rallado'     => array( 'g', array( 'gluten' ), array(), false ),
		'Merluza'         => array( 'g', array( 'pescado' ), array(), true ),
		'Limón'           => array( 'ud', array(), array(), false ),
		'Calabaza'        => array( 'g', array(), array(), false ),
		'Puerro'          => array( 'g', array(), array(), false ),
		'Pan'             => array( 'ud', array( 'gluten' ), array( 'sesamo' ), false ),
		'Queso en lonchas' => array( 'g', array( 'lacteos' ), array(), true ),
		'Leche'           => array( 'ml', array( 'lacteos' ), array(), true ),
		'Cacao en polvo'  => array( 'g', array(), array( 'lacteos', 'frutos_cascara' ), false ),
		'Galletas'        => array( 'g', array( 'gluten' ), array( 'huevos', 'lacteos' ), false ),
		'Yogur'           => array( 'ud', array( 'lacteos' ), array(), true ),
		'Fruta del tiempo' => array( 'g', array(), array(), false ),
	);

	/** Recetas: nombre => [tipo, porciones, [[ingrediente, cantidad, unidad], …]]. */
	private const RECETAS = array(
		'Lentejas estofadas'            => array( 'primero', 10, array( array( 'Lentejas', 800, 'g' ), array( 'Zanahoria', 500, 'g' ), array( 'Cebolla', 300, 'g' ), array( 'Patata', 700, 'g' ), array( 'Ajo', 20, 'g' ), array( 'Pimentón', 1, 'cda' ), array( 'Aceite de oliva', 100, 'ml' ), array( 'Sal', 20, 'g' ) ) ),
		'Macarrones con tomate'         => array( 'primero', 10, array( array( 'Macarrones', 1000, 'g' ), array( 'Tomate frito', 600, 'g' ), array( 'Queso rallado', 150, 'g' ), array( 'Aceite de oliva', 30, 'ml' ) ) ),
		'Arroz con tomate y huevo'      => array( 'primero', 10, array( array( 'Arroz', 800, 'g' ), array( 'Tomate frito', 600, 'g' ), array( 'Huevo', 10, 'ud' ), array( 'Aceite de oliva', 60, 'ml' ) ) ),
		'Crema de calabaza'             => array( 'entrante', 10, array( array( 'Calabaza', 2000, 'g' ), array( 'Puerro', 500, 'g' ), array( 'Patata', 500, 'g' ), array( 'Aceite de oliva', 60, 'ml' ), array( 'Sal', 20, 'g' ) ) ),
		'Pollo al horno con patatas'    => array( 'segundo', 10, array( array( 'Muslos de pollo', 2500, 'g' ), array( 'Patata', 2000, 'g' ), array( 'Ajo', 30, 'g' ), array( 'Aceite de oliva', 100, 'ml' ), array( 'Sal', 20, 'g' ) ) ),
		'Tortilla de patatas'           => array( 'segundo', 10, array( array( 'Huevo', 20, 'ud' ), array( 'Patata', 1500, 'g' ), array( 'Cebolla', 300, 'g' ), array( 'Aceite de oliva', 200, 'ml' ), array( 'Sal', 15, 'g' ) ) ),
		'Albóndigas en salsa de tomate' => array( 'segundo', 10, array( array( 'Ternera picada', 1500, 'g' ), array( 'Pan rallado', 200, 'g' ), array( 'Huevo', 3, 'ud' ), array( 'Tomate frito', 800, 'g' ), array( 'Ajo', 20, 'g' ), array( 'Aceite de oliva', 100, 'ml' ) ) ),
		'Merluza al horno con limón'    => array( 'segundo', 10, array( array( 'Merluza', 2000, 'g' ), array( 'Patata', 1500, 'g' ), array( 'Limón', 3, 'ud' ), array( 'Aceite de oliva', 80, 'ml' ), array( 'Ajo', 20, 'g' ) ) ),
		'Bocadillo de queso'            => array( 'merienda', 10, array( array( 'Pan', 10, 'ud' ), array( 'Queso en lonchas', 500, 'g' ) ) ),
		'Leche con cacao y galletas'    => array( 'desayuno', 10, array( array( 'Leche', 2000, 'ml' ), array( 'Cacao en polvo', 100, 'g' ), array( 'Galletas', 500, 'g' ) ) ),
		'Tostadas con aceite y tomate'  => array( 'desayuno', 10, array( array( 'Pan', 10, 'ud' ), array( 'Aceite de oliva', 60, 'ml' ), array( 'Tomate frito', 300, 'g' ) ) ),
		'Yogur con fruta'               => array( 'postre', 10, array( array( 'Yogur', 10, 'ud' ), array( 'Fruta del tiempo', 1500, 'g' ) ) ),
	);

	/** Los textos de la biblioteca, para traducirlos con el resto (se guardan en castellano). @return string[] */
	public static function textos(): array {
		return array_merge( array_keys( self::INGREDIENTES ), array_keys( self::RECETAS ), array( self::OBSERVACION ) );
	}

	/** Siembra la biblioteca común si falta. Se puede repetir sin efecto. @return int Recetas creadas. */
	public static function sembrar(): int {
		global $wpdb;
		$nuevas = 0;
		$ids    = array();
		foreach ( self::INGREDIENTES as $nombre => $d ) {
			$id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE grupo = %s AND nombre = %s LIMIT 1', IngredientesTable::table(), IngredientesTable::COMUN, $nombre ) ); // phpcs:ignore WordPress.DB
			if ( ! $id ) {
				$wpdb->insert( // phpcs:ignore WordPress.DB
					IngredientesTable::table(),
					array(
						'grupo'     => IngredientesTable::COMUN,
						'nombre'    => $nombre,
						'unidad'    => $d[0],
						'alergenos' => implode( ',', $d[1] ),
						'trazas'    => implode( ',', $d[2] ),
						'animal'    => $d[3] ? 1 : 0,
						'revisado'  => 1,
						'creado'    => current_time( 'mysql', true ),
					)
				);
				$id = (int) $wpdb->insert_id;
			}
			$ids[ $nombre ] = $id;
		}
		foreach ( self::RECETAS as $nombre => $d ) {
			$existe = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE grupo = %s AND nombre = %s LIMIT 1', RecetasTable::table(), IngredientesTable::COMUN, $nombre ) ); // phpcs:ignore WordPress.DB
			if ( $existe ) {
				continue;
			}
			$wpdb->insert( // phpcs:ignore WordPress.DB
				RecetasTable::table(),
				array(
					'grupo'         => IngredientesTable::COMUN,
					'nombre'        => $nombre,
					'tipo'          => $d[0],
					'porciones'     => $d[1],
					'elaboracion'   => '',
					'observaciones' => self::OBSERVACION,
					'ejemplo'       => 1,
					'creado'        => current_time( 'mysql', true ),
				)
			);
			$receta = (int) $wpdb->insert_id;
			foreach ( $d[2] as $orden => $l ) {
				$wpdb->insert( RecetasTable::table_lineas(), array( 'receta_id' => $receta, 'ingrediente_id' => $ids[ $l[0] ], 'cantidad' => $l[1], 'unidad' => $l[2], 'orden' => $orden ) ); // phpcs:ignore WordPress.DB
			}
			++$nuevas;
		}
		return $nuevas;
	}
}
