<?php
namespace AsdeBalears\AreaPrivada\Tesoreria\Banco;

use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Domain\Grupo;
use AsdeBalears\AreaPrivada\Tesoreria\Config;
use AsdeBalears\AreaPrivada\Tesoreria\FamiliasTable;
use AsdeBalears\AreaPrivada\Tesoreria\MovimientosTable;

defined( 'ABSPATH' ) || exit;

/**
 * Conciliación de los ingresos del banco con las familias:
 * - sugerencia por la referencia de familia en el concepto (F684001, «F-684-001»…);
 * - o por el ordenante, si un pago anterior suyo ya se concilió con una familia.
 * Siempre la confirma una persona. Al conciliar se crea el pago en el control de
 * pagos de Tesorería (origen «banco»); al deshacer, ese pago se anula.
 */
final class Conciliacion {

	/** Referencia de familia del grupo en un texto: F + nº de grupo + 3 cifras. */
	public static function referencia( string $texto, string $grupo ): ?string {
		if ( preg_match_all( '/(?<![A-Z0-9])F[\s\-.]?(\d{3})[\s\-.]?(\d{3})(?!\d)/i', $texto, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $x ) {
				if ( $x[1] === $grupo ) {
					return 'F' . $x[1] . $x[2];
				}
			}
		}
		return null;
	}

	/**
	 * @param array<string, array> $familias nº de familia => familia (FamiliasTable::todas())
	 * @return array{familia:array, motivo:string, fiable:bool}|null
	 */
	public static function sugerencia( array $mov, array $familias ): ?array {
		if ( $mov['importe'] <= 0 ) {
			return null;
		}
		$ref = self::referencia( implode( ' ', array( $mov['concepto'], $mov['referencia1'], $mov['referencia2'], $mov['documento'] ) ), Grupo::numero() );
		if ( $ref && isset( $familias[ $ref ] ) ) {
			return array(
				'familia' => $familias[ $ref ],
				'motivo'  => sprintf( asde_t( 'Referencia %s en el concepto' ), $ref ),
				'fiable'  => true,
			);
		}
		$id = BancoTable::familia_por_ordenante( (string) ( $mov['ordenante_hmac'] ?? '' ) );
		if ( $id ) {
			foreach ( $familias as $f ) {
				if ( $f['id'] === $id ) {
					return array(
						'familia' => $f,
						'motivo'  => asde_t( 'Mismo ordenante que un pago ya conciliado' ),
						'fiable'  => false,
					);
				}
			}
		}
		return null;
	}

	/** @return int|\WP_Error Id del pago creado en Tesorería. */
	public static function conciliar( int $mov_id, int $familia_id, string $ronda = '' ) {
		$m = BancoTable::obtener( $mov_id );
		if ( ! $m ) {
			return new \WP_Error( 'asde_banco', asde_t( 'Ese movimiento no existe.' ) );
		}
		if ( BancoTable::PENDIENTE !== $m['estado'] ) {
			return new \WP_Error( 'asde_banco', asde_t( 'Ese movimiento ya está revisado.' ) );
		}
		if ( $m['importe'] <= 0 ) {
			return new \WP_Error( 'asde_banco', asde_t( 'Solo se concilian ingresos con las familias.' ) );
		}
		$f = FamiliasTable::obtener( $familia_id );
		if ( ! $f ) {
			return new \WP_Error( 'asde_banco', asde_t( 'Esa familia no existe.' ) );
		}
		$ronda = Config::es_ronda( $ronda ) ? $ronda : Config::ronda_de_fecha( $m['fecha'] );
		$pago  = MovimientosTable::registrar(
			$familia_id,
			$ronda,
			MovimientosTable::PAGO,
			$m['fecha'],
			$m['importe'],
			'transferencia',
			$m['documento'] ?: $f['numero'],
			sprintf( 'Banco: movimiento del %s', wp_date( 'd/m/Y', strtotime( $m['fecha'] . ' 12:00' ) ) ),
			'banco'
		);
		if ( is_wp_error( $pago ) ) {
			return $pago;
		}
		BancoTable::marcar( $mov_id, BancoTable::CONCILIADO, $familia_id, $pago );
		Logger::log(
			'banco_conciliado',
			'banco',
			(string) $mov_id,
			array(
				'familia' => $f['numero'],
				'pago'    => $pago,
				'ronda'   => $ronda,
			)
		);
		return $pago;
	}

	/** No es una cuota (donación, subvención, gasto…): queda revisado sin familia. */
	public static function ignorar( int $mov_id ) {
		$m = BancoTable::obtener( $mov_id );
		if ( ! $m || BancoTable::PENDIENTE !== $m['estado'] ) {
			return new \WP_Error( 'asde_banco', asde_t( 'Ese movimiento no está pendiente.' ) );
		}
		BancoTable::marcar( $mov_id, BancoTable::IGNORADO );
		Logger::log( 'banco_revisado', 'banco', (string) $mov_id );
		return true;
	}

	/** Vuelve a pendiente; si estaba conciliado, anula el pago que se creó. */
	public static function deshacer( int $mov_id ) {
		$m = BancoTable::obtener( $mov_id );
		if ( ! $m || BancoTable::PENDIENTE === $m['estado'] ) {
			return new \WP_Error( 'asde_banco', asde_t( 'Ese movimiento no está revisado.' ) );
		}
		if ( BancoTable::CONCILIADO === $m['estado'] && $m['pago_id'] ) {
			MovimientosTable::anular( (int) $m['pago_id'] );
		}
		BancoTable::marcar( $mov_id, BancoTable::PENDIENTE );
		Logger::log( 'banco_deshecho', 'banco', (string) $mov_id, array( 'pago_anulado' => (int) $m['pago_id'] ) );
		return true;
	}
}
