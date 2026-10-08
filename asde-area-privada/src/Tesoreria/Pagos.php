<?php
namespace AsdeBalears\AreaPrivada\Tesoreria;

use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Tesoreria\Banco\BancoTable;

defined( 'ABSPATH' ) || exit;

/**
 * Eliminar un pago (o un ajuste) de una familia y su historial (v0.25, petición del
 * usuario: «poder eliminar un pago generando su correspondiente historial»).
 *
 * Eliminar no borra: el movimiento queda anulado con quién, cuándo y el motivo
 * (obligatorio), deja de contar en la cuota y sale en el historial de la familia.
 * Si el pago vino de conciliar un ingreso del banco, el ingreso vuelve a quedar por
 * conciliar (si no, el banco lo daría por cobrado a una familia que ya no lo tiene).
 * Si vino del cobro de una remesa SEPA, la remesa lo sigue dando por cobrado (es lo
 * que dijo el banco): se avisa.
 */
final class Pagos {

	/**
	 * @return array{mov:array, banco:?array}|\WP_Error El movimiento eliminado y, si lo había, el ingreso del banco que vuelve a quedar por conciliar.
	 */
	public static function eliminar( int $mov_id, int $familia_id, string $motivo ) {
		$m      = MovimientosTable::obtener( $mov_id );
		$motivo = trim( sanitize_text_field( $motivo ) );
		if ( ! $m || (int) $m['familia_id'] !== $familia_id || ! FamiliasTable::obtener( $familia_id ) ) {
			return new \WP_Error( 'asde_mov', asde_t( 'Ese movimiento no es de esta familia.' ) );
		}
		if ( ! empty( $m['anulado_en'] ) ) {
			return new \WP_Error( 'asde_mov', asde_t( 'Ese movimiento ya estaba eliminado.' ) );
		}
		if ( '' === $motivo ) {
			return new \WP_Error( 'asde_mov', asde_t( 'Escribe el motivo: queda en el historial de la familia.' ) );
		}
		if ( ! MovimientosTable::anular( $mov_id, $motivo ) ) {
			return new \WP_Error( 'asde_mov', asde_t( 'No se ha podido eliminar el movimiento.' ) );
		}
		$banco = null;
		if ( 'banco' === $m['origen'] ) {
			$banco = BancoTable::por_pago( $mov_id );
			if ( $banco && BancoTable::CONCILIADO === $banco['estado'] ) {
				BancoTable::marcar( (int) $banco['id'], BancoTable::PENDIENTE );
				Logger::log( 'banco_desconciliado', 'banco', (string) $banco['id'], array( 'pago_eliminado' => $mov_id ) );
			} else {
				$banco = null;
			}
		}
		Logger::log(
			'tesoreria_pago_eliminado',
			'familia',
			(string) $familia_id,
			array(
				'movimiento' => $mov_id,
				'tipo'       => $m['tipo'],
				'origen'     => $m['origen'],
				'ronda'      => $m['ronda'],
			)
		);
		return array(
			'mov'   => MovimientosTable::obtener( $mov_id ),
			'banco' => $banco,
		);
	}

	/**
	 * Historial de los movimientos de una familia en una ronda: cuándo se registró cada
	 * uno y, si se eliminó, cuándo, quién y por qué. El más reciente primero.
	 *
	 * @param array[] $movimientos MovimientosTable::de_familia() (con los anulados)
	 * @return list<array{cuando:string, accion:string, mov:array}> accion: registrado | eliminado
	 */
	public static function historial( array $movimientos ): array {
		$out = array();
		foreach ( $movimientos as $m ) {
			$out[] = array(
				'cuando' => (string) $m['creado'],
				'accion' => 'registrado',
				'mov'    => $m,
			);
			if ( ! empty( $m['anulado_en'] ) ) {
				$out[] = array(
					'cuando' => (string) $m['anulado_en'],
					'accion' => 'eliminado',
					'mov'    => $m,
				);
			}
		}
		// En el mismo segundo, la eliminación va antes que el registro y lo último registrado, antes.
		usort( $out, static fn( $a, $b ) => strcmp( $b['cuando'], $a['cuando'] ) ?: ( ( 'eliminado' === $b['accion'] ) <=> ( 'eliminado' === $a['accion'] ) ) ?: ( (int) $b['mov']['id'] <=> (int) $a['mov']['id'] ) );
		return $out;
	}
}
