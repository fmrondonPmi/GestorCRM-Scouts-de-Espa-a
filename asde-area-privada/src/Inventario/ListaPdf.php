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
		$pdf   = new Pdf( sprintf( /* translators: %s: text */ __( 'Shopping list %s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $num ) );
		$fecha = static fn( ?string $f ) => $f ? wp_date( 'd/m/Y', (int) strtotime( $f . ' UTC' ) ) : '';
		$x0    = self::MARGEN;
		$x1    = Pdf::ANCHO - self::MARGEN;

		$pdf->nueva_pagina();
		$y = self::MARGEN + 10;
		$pdf->texto( $x0, $y, Grupo::nombre(), 11, true, 'izquierda', 0.35 );
		$membrete = \AsdeBalears\AreaPrivada\Domain\Grupos::membrete( Grupo::actual() ); // v0.50: denominación fiscal, CIF y sede si el grupo los dio.
		if ( '' !== $membrete ) {
			$pdf->texto( $x0, $y + 12, $membrete, 8, false, 'izquierda', 0.35 );
			$y += 8;
		}
		$y += 24;
		$pdf->texto( $x0, $y, __( 'Equipment shopping list', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), 18, true );
		$pdf->texto( $x1, $y, $num, 14, true, 'derecha' );
		$y += 20;
		$datos = array(
			sprintf( /* translators: 1: text, 2: text */ __( 'Created on %1$s by %2$s.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $fecha( $lista['creado'] ), Servicio::usuario( $lista['creado_por'] ) ?: '—' ),
			sprintf( /* translators: 1: text, 2: text */ __( 'Treasury approval: %1$s, on %2$s.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), Servicio::usuario( $lista['revisado_por'] ) ?: '—', $fecha( $lista['revisado_en'] ) ),
		);
		if ( ComprasTable::COMPRADA === $lista['estado'] ) {
			$datos[] = sprintf( /* translators: 1: text, 2: text */ __( 'Bought: marked by %1$s on %2$s. What was bought was added to the inventory.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), Servicio::usuario( (int) $lista['comprada_por'] ) ?: '—', $fecha( $lista['comprada_en'] ) );
		}
		foreach ( $datos as $linea ) {
			$pdf->texto( $x0, $y, $linea, 10 );
			$y += 14;
		}
		$y += 8;

		$cabecera = array(
			'descripcion'   => __( 'Equipment', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
			'tipo'          => __( 'Type', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
			'num_serie'     => __( 'Serial no.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
			'en_inventario' => __( 'In inventory', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
			'cantidad'      => __( 'To buy', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
			'precio'        => __( 'Price', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
			'importe'       => __( 'Amount', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
		);
		$y = self::fila( $pdf, $y, $cabecera, true );

		foreach ( $lista['lineas'] as $l ) {
			$nuevo  = empty( $l['material'] );
			$celdas = array(
				'descripcion'   => $l['descripcion'],
				'tipo'          => $nuevo ? __( 'New equipment', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) : $l['tipo'],
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
		$pdf->texto( $x0, $y, sprintf( /* translators: %d: number */ _n( '%d item of equipment', '%d items of equipment', count( $lista['lineas'] ) , 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), count( $lista['lineas'] ) ) . ' · ' . sprintf( /* translators: %d: number */ _n( '%d unit', '%d units', $unidades , 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $unidades ), 10 );
		$pdf->texto( $x1, $y, sprintf( /* translators: %s: text */ __( 'Estimated total: %s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), Calculadora::euros( $lista['total'] ) ), 12, true, 'derecha' );
		if ( ComprasTable::sin_precio( $lista ) ) {
			$y += 14;
			$pdf->texto( $x1, $y, __( 'Not counting equipment with no price.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), 8.5, false, 'derecha', 0.35 );
		}

		// Nota.
		if ( '' !== trim( $lista['nota'] ) ) {
			$y += 26;
			$pdf->texto( $x0, $y, __( 'Note', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), 10, true );
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
			$pdf->texto( $x1, Pdf::ALTO - 22, sprintf( /* translators: 1: number, 2: number */ __( 'Page %1$d of %2$d', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $i + 1, $total ), 8, false, 'derecha', 0.35 );
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
