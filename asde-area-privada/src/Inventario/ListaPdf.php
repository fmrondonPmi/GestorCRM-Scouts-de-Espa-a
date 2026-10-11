<?php
namespace AsdeBalears\AreaPrivada\Inventario;

use AsdeBalears\AreaPrivada\Domain\Grupo;
use AsdeBalears\AreaPrivada\Tesoreria\Calculadora;

defined( 'ABSPATH' ) || exit;

/**
 * PDF para imprimir una lista de compra con el visto bueno de Tesorería:
 * cabecera (grupo, nº, fecha, quién la pide y quién le dio el visto bueno),
 * tabla del material, total estimado, nota y «Página X de Y».
 *
 * v0.30: el material nuevo (que no estaba en el inventario, P-63) lo dice en la columna
 * del tipo; y una lista comprada, quién la marcó y cuándo.
 */
final class ListaPdf {

	private const MARGEN = 40;
	private const PIE    = 50;

	/** Columnas: clave => [ancho en puntos, alineación]. Suman el ancho útil (515). */
	private const COLUMNAS = array(
		'descripcion'   => array( 165, 'izquierda' ),
		'tipo'          => array( 80, 'izquierda' ),
		'num_serie'     => array( 75, 'izquierda' ),
		'en_inventario' => array( 48, 'derecha' ),
		'cantidad'      => array( 47, 'derecha' ),
		'precio'        => array( 50, 'derecha' ),
		'importe'       => array( 50, 'derecha' ),
	);

	public static function generar( array $lista ): string {
		$num   = ComprasTable::numero( $lista );
		$pdf   = new Pdf( sprintf( asde_t( 'Lista de compra %s' ), $num ) );
		$fecha = static fn( ?string $f ) => $f ? wp_date( 'd/m/Y', (int) strtotime( $f . ' UTC' ) ) : '';
		$x0    = self::MARGEN;
		$x1    = Pdf::ANCHO - self::MARGEN;

		$pdf->nueva_pagina();
		$y = self::MARGEN + 10;
		$pdf->texto( $x0, $y, Grupo::nombre(), 11, true, 'izquierda', 0.35 );
		$y += 24;
		$pdf->texto( $x0, $y, asde_t( 'Lista de compra de material' ), 18, true );
		$pdf->texto( $x1, $y, $num, 14, true, 'derecha' );
		$y += 20;
		$datos = array(
			sprintf( asde_t( 'Creada el %1$s por %2$s.' ), $fecha( $lista['creado'] ), Servicio::usuario( $lista['creado_por'] ) ?: '—' ),
			sprintf( asde_t( 'Visto bueno de Tesorería: %1$s, el %2$s.' ), Servicio::usuario( $lista['revisado_por'] ) ?: '—', $fecha( $lista['revisado_en'] ) ),
		);
		if ( ComprasTable::COMPRADA === $lista['estado'] ) {
			$datos[] = sprintf( asde_t( 'Comprada: la marcó %1$s el %2$s. Lo comprado se sumó al inventario.' ), Servicio::usuario( (int) $lista['comprada_por'] ) ?: '—', $fecha( $lista['comprada_en'] ) );
		}
		foreach ( $datos as $linea ) {
			$pdf->texto( $x0, $y, $linea, 10 );
			$y += 14;
		}
		$y += 8;

		$cabecera = array(
			'descripcion'   => asde_t( 'Material' ),
			'tipo'          => asde_t( 'Tipo' ),
			'num_serie'     => asde_t( 'Nº de serie' ),
			'en_inventario' => asde_t( 'En inventario' ),
			'cantidad'      => asde_t( 'A comprar' ),
			'precio'        => asde_t( 'Precio' ),
			'importe'       => asde_t( 'Importe' ),
		);
		$y = self::fila( $pdf, $y, $cabecera, true );

		foreach ( $lista['lineas'] as $l ) {
			$nuevo  = empty( $l['material'] );
			$celdas = array(
				'descripcion'   => $l['descripcion'],
				'tipo'          => $nuevo ? asde_t( 'Material nuevo' ) : $l['tipo'],
				'num_serie'     => $l['num_serie'],
				'en_inventario' => $nuevo ? '—' : (string) $l['en_inventario'],
				'cantidad'      => (string) $l['cantidad'],
				'precio'        => null === $l['precio'] ? '—' : Calculadora::euros( $l['precio'] ),
				'importe'       => null === $l['precio'] ? '—' : Calculadora::euros( $l['precio'] * $l['cantidad'] ),
			);
			if ( $y + self::alto( $pdf, $celdas, false ) > Pdf::ALTO - self::PIE ) {
				$pdf->nueva_pagina();
				$y = self::fila( $pdf, self::MARGEN, $cabecera, true ); // La cabecera de la tabla se repite en cada página.
			}
			$y = self::fila( $pdf, $y, $celdas, false );
		}

		// Total.
		if ( $y + 60 > Pdf::ALTO - self::PIE ) {
			$pdf->nueva_pagina();
			$y = self::MARGEN;
		}
		$y += 16;
		$unidades = array_sum( array_column( $lista['lineas'], 'cantidad' ) );
		$pdf->texto( $x0, $y, sprintf( asde_n( '%d material', '%d materiales', count( $lista['lineas'] ) ), count( $lista['lineas'] ) ) . ' · ' . sprintf( asde_n( '%d unidad', '%d unidades', $unidades ), $unidades ), 10 );
		$pdf->texto( $x1, $y, sprintf( asde_t( 'Total estimado: %s' ), Calculadora::euros( $lista['total'] ) ), 12, true, 'derecha' );
		if ( ComprasTable::sin_precio( $lista ) ) {
			$y += 14;
			$pdf->texto( $x1, $y, asde_t( 'Sin contar el material que no tiene precio.' ), 8.5, false, 'derecha', 0.35 );
		}

		// Nota.
		if ( '' !== trim( $lista['nota'] ) ) {
			$y += 26;
			$pdf->texto( $x0, $y, asde_t( 'Nota' ), 10, true );
			foreach ( $pdf->cortar( $lista['nota'], $x1 - $x0, 10 ) as $linea ) {
				$y += 13;
				if ( $y > Pdf::ALTO - self::PIE ) {
					$pdf->nueva_pagina();
					$y = self::MARGEN;
				}
				$pdf->texto( $x0, $y, $linea, 10 );
			}
		}

		// Pie de cada página: nº de la lista y «Página X de Y».
		$total = $pdf->paginas();
		for ( $i = 0; $i < $total; $i++ ) {
			$pdf->ir_a( $i );
			$pdf->linea( $x0, Pdf::ALTO - 34, $x1, Pdf::ALTO - 34, 0.4, 0.6 );
			$pdf->texto( $x0, Pdf::ALTO - 22, $num . ' · ' . Grupo::nombre(), 8, false, 'izquierda', 0.35 );
			$pdf->texto( $x1, Pdf::ALTO - 22, sprintf( asde_t( 'Página %1$d de %2$d' ), $i + 1, $total ), 8, false, 'derecha', 0.35 );
		}
		return $pdf->salida();
	}

	/** Alto de una fila de la tabla (la celda con más líneas manda). */
	private static function alto( Pdf $pdf, array $celdas, bool $cabecera ): float {
		$tam    = $cabecera ? 8.5 : 9;
		$lineas = 1;
		foreach ( self::COLUMNAS as $k => list( $ancho ) ) {
			$lineas = max( $lineas, count( $pdf->cortar( (string) ( $celdas[ $k ] ?? '' ), $ancho - 6, $tam, $cabecera ) ) );
		}
		return $lineas * ( $tam + 2.5 ) + 7;
	}

	/** Pinta una fila y devuelve dónde empieza la siguiente. */
	private static function fila( Pdf $pdf, float $y, array $celdas, bool $cabecera ): float {
		$tam  = $cabecera ? 8.5 : 9;
		$alto = self::alto( $pdf, $celdas, $cabecera );
		$x    = self::MARGEN;
		if ( $cabecera ) {
			$pdf->rectangulo( $x, $y, Pdf::ANCHO - 2 * self::MARGEN, $alto, 0.9 );
		}
		foreach ( self::COLUMNAS as $k => list( $ancho, $alinear ) ) {
			$ly = $y + $tam + 2;
			foreach ( $pdf->cortar( (string) ( $celdas[ $k ] ?? '' ), $ancho - 6, $tam, $cabecera ) as $linea ) {
				$pdf->texto( 'derecha' === $alinear ? $x + $ancho - 3 : $x + 3, $ly, $linea, $tam, $cabecera, $alinear );
				$ly += $tam + 2.5;
			}
			$x += $ancho;
		}
		$pdf->linea( self::MARGEN, $y + $alto, Pdf::ANCHO - self::MARGEN, $y + $alto, $cabecera ? 0.8 : 0.3, $cabecera ? 0 : 0.7 );
		return $y + $alto;
	}
}
