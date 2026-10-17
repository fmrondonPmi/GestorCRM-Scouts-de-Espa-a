<?php
namespace AsdeBalears\AreaPrivada\Menus;

defined( 'ABSPATH' ) || exit;

/**
 * Unidades y escalado de las recetas (doc 49, B1).
 *
 * Solo se convierten g↔kg y ml↔l (P-decidido): «unidades», «cucharadas» y «pizcas» no se convierten ni se mezclan con otras.
 * Las cantidades se calculan en la unidad base (g, ml o la propia) y se enseñan en la mayor que quede entera (1500 g → 1,5 kg).
 */
final class Escalado {

	/** Código => [nombre, familia, factor a la unidad base]. */
	public const UNIDADES = array(
		'g'    => array( 'g', 'masa', 1 ),
		'kg'   => array( 'kg', 'masa', 1000 ),
		'ml'   => array( 'ml', 'volumen', 1 ),
		'l'    => array( 'l', 'volumen', 1000 ),
		'ud'   => array( 'unidades', 'ud', 1 ),
		'cda'  => array( 'cucharadas', 'cda', 1 ),
		'pizca' => array( 'pizcas', 'pizca', 1 ),
	);

	public static function unidad_valida( string $u ): bool {
		return isset( self::UNIDADES[ $u ] );
	}

	/** Nombre de la unidad, traducido. */
	public static function nombre( string $u ): string {
		return isset( self::UNIDADES[ $u ] ) ? asde_t( self::UNIDADES[ $u ][0] ) : $u;
	}

	/**
	 * Cantidad de una receta para este nº de comensales: cantidad × comensales ÷ porciones base.
	 */
	public static function escalar( float $cantidad, int $porciones, int $comensales ): float {
		return $porciones > 0 ? round( $cantidad * $comensales / $porciones, 2 ) : 0.0;
	}

	/**
	 * Pasa una cantidad a la unidad base de su familia (1,5 kg → 1500 g).
	 *
	 * @return array{0:string, 1:float} [clave de la familia + unidad base, cantidad]
	 */
	public static function a_base( float $cantidad, string $unidad ): array {
		$u = self::UNIDADES[ $unidad ] ?? self::UNIDADES['ud'];
		return array( $u[1], $cantidad * $u[2] );
	}

	/**
	 * Cantidad y unidad para enseñar: la mayor unidad de su familia en que la cantidad sea al menos 1 (1500 g → 1,5 kg; 400 g se queda).
	 *
	 * @return array{0:float, 1:string} [cantidad, código de unidad]
	 */
	public static function legible( float $base, string $familia ): array {
		switch ( $familia ) {
			case 'masa':
				return $base >= 1000 ? array( round( $base / 1000, 2 ), 'kg' ) : array( round( $base, 2 ), 'g' );
			case 'volumen':
				return $base >= 1000 ? array( round( $base / 1000, 2 ), 'l' ) : array( round( $base, 2 ), 'ml' );
			default:
				return array( round( $base, 2 ), $familia );
		}
	}

	/** «6,3 kg», «2 unidades». */
	public static function texto( float $cantidad, string $unidad ): string {
		return rtrim( rtrim( number_format( $cantidad, 2, ',', '.' ), '0' ), ',' ) . ' ' . self::nombre( $unidad );
	}

	/** «Cantidad ya escalada y pasada a lo legible» de una línea. */
	public static function linea( float $cantidad, string $unidad, int $porciones, int $comensales ): string {
		list( $familia, $base ) = self::a_base( self::escalar( $cantidad, $porciones, $comensales ), $unidad );
		list( $c, $u )          = self::legible( $base, $familia );
		return self::texto( $c, $u );
	}
}
