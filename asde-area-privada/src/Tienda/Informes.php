<?php
namespace AsdeBalears\AreaPrivada\Tienda;

defined( 'ABSPATH' ) || exit;

/**
 * Informes de ventas de la tienda (v0.40, doc 48, fase 5): ventas por producto, por forma de pago y pendientes de cobro.
 * Calculan a partir de los pedidos que se les pasan (la gestión ya filtra por lo que cada persona puede ver); no leen el CRM.
 * Los pedidos cancelados no cuentan como venta.
 */
final class Informes {

	/** @param array[] $pedidos @return array[] Los creados entre estas fechas (Y-m-d, ambas incluidas; '' = sin límite). */
	public static function del_periodo( array $pedidos, string $desde, string $hasta ): array {
		return array_values(
			array_filter(
				$pedidos,
				static function ( array $p ) use ( $desde, $hasta ): bool {
					$dia = wp_date( 'Y-m-d', strtotime( $p['creado'] . ' UTC' ) );
					return ( '' === $desde || $dia >= $desde ) && ( '' === $hasta || $dia <= $hasta );
				}
			)
		);
	}

	/** @param array[] $pedidos @return array[] Los que cuentan como venta (no cancelados). */
	private static function ventas( array $pedidos ): array {
		return array_values( array_filter( $pedidos, static fn( array $p ) => null === $p['cancelado_en'] ) );
	}

	/**
	 * Unidades e importe de cada artículo vendido (camisas, insignias y productos de la tienda).
	 *
	 * @return array<int, array{producto:string, variante:string, unidades:int, importe:float, pagado:float}> Por producto y variante.
	 */
	public static function por_producto( array $pedidos, array $cfg ): array {
		$filas = array();
		$suma  = static function ( string $producto, string $variante, int $unidades, ?float $importe, bool $pagado ) use ( &$filas ): void {
			$k = $producto . "\n" . $variante;
			if ( ! isset( $filas[ $k ] ) ) {
				$filas[ $k ] = array(
					'producto' => $producto,
					'variante' => $variante,
					'unidades' => 0,
					'importe'  => 0.0,
					'pagado'   => 0.0,
				);
			}
			$filas[ $k ]['unidades'] += $unidades;
			$filas[ $k ]['importe']  += (float) $importe;
			$filas[ $k ]['pagado']   += $pagado ? (float) $importe : 0.0;
		};
		foreach ( self::ventas( $pedidos ) as $p ) {
			$pagado = null !== $p['pago_en'];
			$l      = $p['lineas'];
			if ( $l['camisa'] ) {
				$suma( (string) $l['camisa']['nombre'], (string) $l['camisa']['talla'], 1, (float) ( $l['camisa']['precio'] ?? 0 ), $pagado );
			}
			foreach ( $l['insignias'] as $i ) {
				$precio = $i['precio'] ?? ( $cfg['insignias'][ $i['id'] ?? '' ]['precio'] ?? null );
				$suma( (string) $i['nombre'], '', (int) $i['cantidad'], null === $precio ? null : (int) $i['cantidad'] * (float) $precio, $pagado );
			}
			foreach ( $l['productos'] as $x ) {
				$etiqueta = (string) ( $x['etiqueta'] ?? '' );
				$suma( (string) $x['nombre'], 'Única' === $etiqueta ? '' : $etiqueta, (int) $x['cantidad'], null === ( $x['precio'] ?? null ) ? null : (int) $x['cantidad'] * (float) $x['precio'], $pagado );
			}
		}
		$filas = array_values( $filas );
		usort( $filas, static fn( $a, $b ) => array( -$a['importe'], $a['producto'], $a['variante'] ) <=> array( -$b['importe'], $b['producto'], $b['variante'] ) );
		return $filas;
	}

	/**
	 * Pedidos, importe, cobrado y pendiente de cobro de cada forma de pago.
	 *
	 * @return array<string, array{pedidos:int, total:float, cobrado:float, pendiente:float}> Por forma de pago ('' = sin indicar).
	 */
	public static function por_forma( array $pedidos ): array {
		$out = array();
		foreach ( self::ventas( $pedidos ) as $p ) {
			$f = isset( PedidosTable::FORMAS[ $p['forma_pago'] ] ) ? $p['forma_pago'] : '';
			if ( ! isset( $out[ $f ] ) ) {
				$out[ $f ] = array(
					'pedidos'   => 0,
					'total'     => 0.0,
					'cobrado'   => 0.0,
					'pendiente' => 0.0,
				);
			}
			$a_cobrar = Servicio::importe_a_cobrar( $p );
			++$out[ $f ]['pedidos'];
			$out[ $f ]['total'] += null !== $p['pago_en'] ? (float) $p['pago_importe'] : $a_cobrar;
			if ( null !== $p['pago_en'] ) {
				$out[ $f ]['cobrado'] += (float) $p['pago_importe'];
			} else {
				$out[ $f ]['pendiente'] += $a_cobrar;
			}
		}
		ksort( $out );
		return $out;
	}

	/** @return array[] Los pedidos sin pagar (ni entregar), los más antiguos primero, con los días que llevan esperando. */
	public static function pendientes( array $pedidos ): array {
		$ahora = time();
		$out   = array();
		foreach ( self::ventas( $pedidos ) as $p ) {
			if ( null === $p['pago_en'] && null === $p['entregado_en'] && Servicio::importe_a_cobrar( $p ) > 0 ) {
				$p['dias'] = (int) floor( ( $ahora - strtotime( $p['creado'] . ' UTC' ) ) / DAY_IN_SECONDS );
				$out[]     = $p;
			}
		}
		usort( $out, static fn( $a, $b ) => $b['dias'] <=> $a['dias'] );
		return $out;
	}
}
