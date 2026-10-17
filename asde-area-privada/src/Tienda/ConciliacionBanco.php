<?php
namespace AsdeBalears\AreaPrivada\Tienda;

use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Domain\Grupo;
use AsdeBalears\AreaPrivada\Domain\Modulos;
use AsdeBalears\AreaPrivada\Tesoreria\Banco\BancoTable;
use AsdeBalears\AreaPrivada\Tesoreria\Banco\Conciliacion;

defined( 'ABSPATH' ) || exit;

/**
 * Pedidos de la tienda y banco (v0.40, doc 48, fase 5): los ingresos del banco por conciliar que llevan en el concepto el
 * nº de un pedido sin pagar (U6840007) se le proponen a Tesorería como «pagado». Nunca marca nada sola: Tesorería lo confirma.
 *
 * Al confirmar, el movimiento queda revisado (con la nota «Pedido U…») y el pedido, pagado con la fecha y el importe del
 * ingreso. Si luego se deshace el pago del pedido, el movimiento vuelve a estar por conciliar.
 */
final class ConciliacionBanco {

	/** Nota (en castellano, como lo guardado) que lleva el movimiento revisado por un pedido. */
	private const NOTA = 'Pedido de la tienda %s';

	/** ¿Puede esta persona usar el banco aquí? Tesorería (pago de pedidos y configuración de Tesorería) y el módulo Banco encendido. */
	public static function disponible(): bool {
		return current_user_can( Servicio::CAP_PAGO ) && current_user_can( 'asde_tesoreria_config' ) && Modulos::activo( Modulos::BANCO );
	}

	/** El nº de pedido del grupo que aparece en un texto («U684-0007», «u 6840007»…), o null. */
	public static function referencia( string $texto, string $grupo ): ?string {
		if ( preg_match_all( '/(?<![A-Z0-9])U[\s\-.]?(\d{3})[\s\-.]?(\d{4})(?!\d)/i', $texto, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $x ) {
				if ( $x[1] === $grupo ) {
					return 'U' . $x[1] . $x[2];
				}
			}
		}
		return null;
	}

	/** Texto de un movimiento donde puede ir el nº de pedido. */
	private static function texto( array $mov ): string {
		return implode( ' ', array( $mov['concepto'], $mov['referencia1'], $mov['referencia2'], $mov['documento'] ) );
	}

	/**
	 * Ingresos por conciliar que mencionan un pedido sin pagar del grupo.
	 *
	 * @param array[] $pedidos Pedidos (PedidosTable) entre los que buscar; solo cuentan los que siguen sin pagar.
	 * @return array[] Cada uno: pedido, mov, coincide (¿el importe es el del pedido?), a_cobrar.
	 */
	public static function sugerencias( array $pedidos ): array {
		if ( ! self::disponible() ) {
			return array();
		}
		$por_numero = array();
		foreach ( $pedidos as $p ) {
			if ( null === $p['pago_en'] && null === $p['cancelado_en'] && null === $p['entregado_en'] ) {
				$por_numero[ PedidosTable::numero( $p ) ] = $p;
			}
		}
		if ( ! $por_numero ) {
			return array();
		}
		$out = array();
		foreach ( BancoTable::movimientos( array( 'estado' => BancoTable::PENDIENTE, 'tipo' => 'ingresos' ) ) as $m ) {
			$ref = self::referencia( self::texto( $m ), Grupo::numero() );
			if ( $ref && isset( $por_numero[ $ref ] ) ) {
				$p     = $por_numero[ $ref ];
				$cobro = Servicio::importe_a_cobrar( $p );
				$out[] = array(
					'pedido'   => $p,
					'mov'      => $m,
					'coincide' => abs( $m['importe'] - $cobro ) < 0.005,
					'a_cobrar' => $cobro,
				);
			}
		}
		return $out;
	}

	/**
	 * Marca el pedido como pagado con este ingreso. Se comprueba todo otra vez aquí: que el movimiento siga por conciliar,
	 * que sea un ingreso y que mencione este pedido (nunca se fía de lo que manda el formulario).
	 *
	 * @return true|\WP_Error
	 */
	public static function confirmar( int $pedido_id, int $mov_id ) {
		if ( ! self::disponible() ) {
			return new \WP_Error( 'asde_tienda', asde_t( 'El banco solo lo usan Tesorería y quien lleve la configuración de Tesorería, con el módulo Banco activo.' ) );
		}
		$p = PedidosTable::obtener( $pedido_id );
		$m = BancoTable::obtener( $mov_id );
		if ( ! $p || ! $m ) {
			return new \WP_Error( 'asde_tienda', asde_t( 'Ese pedido o ese movimiento ya no existen.' ) );
		}
		if ( null !== $p['pago_en'] || null !== $p['cancelado_en'] ) {
			return new \WP_Error( 'asde_tienda', asde_t( 'Ese pedido ya está pagado o cancelado.' ) );
		}
		if ( BancoTable::PENDIENTE !== $m['estado'] || $m['importe'] <= 0 || PedidosTable::numero( $p ) !== self::referencia( self::texto( $m ), Grupo::numero() ) ) {
			return new \WP_Error( 'asde_tienda', asde_t( 'Ese ingreso ya está revisado o no corresponde a este pedido.' ) );
		}
		$metodo = PedidosTable::METODO_DE_FORMA[ $p['forma_pago'] ] ?? 'transferencia';
		PedidosTable::marcar_pago( $p['id'], $m['fecha'], isset( PedidosTable::METODOS[ $metodo ] ) ? $metodo : 'transferencia', (float) $m['importe'], 'Banco' . ( '' !== $m['documento'] ? ' ' . $m['documento'] : '' ) );
		BancoTable::revisar( $mov_id, 0, sprintf( self::NOTA, PedidosTable::numero( $p ) ) );
		Logger::log( 'tienda_banco_pagado', 'tienda', (string) $p['id'], array( 'movimiento' => $mov_id, 'importe' => $m['importe'] ) );
		$nuevo = PedidosTable::obtener( $p['id'] );
		if ( $nuevo ) {
			Avisos::enviar( $nuevo, Avisos::PAGADO );
		}
		return true;
	}

	/** Si el pago de este pedido salió de un ingreso del banco, ese ingreso vuelve a estar por conciliar. @return int Movimientos devueltos. */
	public static function deshacer( array $p ): int {
		if ( ! Modulos::activo( Modulos::BANCO ) || ! current_user_can( 'asde_tesoreria_config' ) ) {
			return 0;
		}
		$nota = sprintf( self::NOTA, PedidosTable::numero( $p ) );
		$n    = 0;
		foreach ( BancoTable::movimientos( array( 'estado' => BancoTable::IGNORADO, 'q' => $nota ) ) as $m ) {
			if ( $m['nota'] === $nota && ! is_wp_error( Conciliacion::deshacer( (int) $m['id'] ) ) ) {
				++$n;
			}
		}
		return $n;
	}
}
