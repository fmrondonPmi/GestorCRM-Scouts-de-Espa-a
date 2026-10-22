<?php
namespace AsdeBalears\AreaPrivada\Tesoreria;

use AsdeBalears\AreaPrivada\Eventos\Cobros;
use AsdeBalears\AreaPrivada\Eventos\EventosTable;
use AsdeBalears\AreaPrivada\Eventos\Inscripciones;
use AsdeBalears\AreaPrivada\Eventos\InscripcionesTable;
use AsdeBalears\AreaPrivada\Eventos\MovimientosTable as EventoMovimientos;
use AsdeBalears\AreaPrivada\Tienda\PedidosTable;

defined( 'ABSPATH' ) || exit;

/**
 * Los pagos de una familia que no son de la cuota de la ronda (v0.37, petición del usuario:
 * «todo pago asociado a una familia debe poder visualizarse en su historial de pagos»).
 *
 * La cuota, sus ajustes y los pagos del banco o de la remesa SEPA están en `asde_movimientos`
 * (ver Pagos::historial). Los **eventos** (`asde_evento_movimientos`, por inscripción) y los
 * **uniformes** (el pago va en el propio pedido) tienen su propia tabla: aquí se juntan, de las
 * personas de la familia, con la misma forma para pintarlos en el mismo historial. Solo se lee.
 */
final class HistorialPagos {

	/**
	 * @param array{id:int, miembros:string[]} $familia
	 * @return list<array{cuando:string, accion:string, modulo:string, que:string, quien:int, detalle:string}>
	 *         `cuando` en UTC como se guarda; `accion`: registrado | eliminado; `modulo`: evento | uniforme.
	 */
	public static function otros( array $familia ): array {
		return array_merge( self::eventos( $familia['miembros'] ), self::uniformes( $familia['miembros'] ), self::banco( (int) $familia['id'] ) );
	}

	/** @param string[] $socios */
	private static function eventos( array $socios ): array {
		$out   = array();
		$ins   = InscripcionesTable::de_socios( $socios );
		$movs  = EventoMovimientos::de_inscripciones( array_column( $ins, 'id' ) );
		$cache = array();
		foreach ( $ins as $i ) {
			$e = $cache[ $i['evento_id'] ] ??= EventosTable::obtener( $i['evento_id'] );
			if ( ! $e ) {
				continue;
			}
			foreach ( $movs[ $i['id'] ] ?? array() as $m ) {
				$importe = Calculadora::euros( (float) $m['importe'] );
				if ( EventoMovimientos::PAGO === $m['tipo'] ) {
					/* translators: 1: importe, 2: nombre del evento */
					$que = asde_t( 'Pago de %1$s · evento «%2$s»' );
				} elseif ( EventoMovimientos::DEVOLUCION === $m['tipo'] ) {
					/* translators: 1: importe, 2: nombre del evento */
					$que = asde_t( 'Devolución de %1$s · evento «%2$s»' );
				} else {
					/* translators: 1: importe, 2: nombre del evento */
					$que = asde_t( 'Beca o ajuste de %1$s · evento «%2$s»' );
				}
				$forma   = EventoMovimientos::FORMAS[ $m['forma'] ] ?? '';
				$detalle = trim(
					wp_date( 'd/m/Y', strtotime( $m['fecha'] . ' 12:00' ) )
					. ( '' !== $forma ? ' · ' . asde_t( $forma ) : '' )
					. ( '' !== trim( $m['referencia'] . $m['nota'] ) ? ' · ' . trim( $m['referencia'] . ( $m['referencia'] && $m['nota'] ? ' · ' : '' ) . $m['nota'] ) : '' )
				);
				$base = array(
					'modulo' => 'evento',
					'que'    => sprintf( $que, $importe, $e['nombre'] ),
				);
				$out[] = $base + array(
					'cuando'  => (string) $m['creado'],
					'accion'  => 'registrado',
					'quien'   => (int) $m['creado_por'],
					'detalle' => $detalle,
				);
				if ( null !== $m['anulado_en'] ) {
					$out[] = $base + array(
						'cuando'  => (string) $m['anulado_en'],
						'accion'  => 'eliminado',
						'quien'   => (int) $m['anulado_por'],
						'detalle' => '',
					);
				}
			}
		}
		return $out;
	}

	/** Ingresos y gastos del banco revisados (no son cuota) que se asociaron a la familia (v0.52). */
	private static function banco( int $familia_id ): array {
		if ( $familia_id < 1 || ! \AsdeBalears\AreaPrivada\Domain\Modulos::activo( \AsdeBalears\AreaPrivada\Domain\Modulos::BANCO ) ) {
			return array();
		}
		$out   = array();
		$tipos = \AsdeBalears\AreaPrivada\Tesoreria\Banco\TiposMovimiento::todos();
		foreach ( \AsdeBalears\AreaPrivada\Tesoreria\Banco\BancoTable::revisados_de_familia( $familia_id ) as $m ) {
			$tipo   = isset( $tipos[ (int) $m['tipo_id'] ] ) ? $tipos[ (int) $m['tipo_id'] ]['nombre'] : asde_t( 'Sin tipo' );
			$evento = ! empty( $m['evento_id'] ) ? EventosTable::obtener( (int) $m['evento_id'] ) : null;
			/* translators: 1: importe, 2: tipo del movimiento */
			$que    = sprintf( $m['importe'] < 0 ? asde_t( 'Gasto de %1$s · %2$s' ) : asde_t( 'Ingreso de %1$s · %2$s' ), Calculadora::euros( abs( (float) $m['importe'] ) ), $tipo );
			$out[]  = array(
				'cuando'  => (string) ( $m['revisado_en'] ?: $m['creado'] ),
				'accion'  => 'registrado',
				'modulo'  => 'banco',
				'que'     => $que . ( $evento ? sprintf( asde_t( ' · evento «%s»' ), $evento['nombre'] ) : '' ),
				'quien'   => (int) $m['revisado_por'],
				'detalle' => trim( wp_date( 'd/m/Y', strtotime( $m['fecha'] . ' 12:00' ) ) . ( '' !== $m['nota'] ? ' · ' . $m['nota'] : '' ), ' ·' ),
			);
		}
		return $out;
	}

	/** @param string[] $socios */
	private static function uniformes( array $socios ): array {
		$out = array();
		foreach ( PedidosTable::de_socios( $socios ) as $p ) {
			if ( empty( $p['pago_en'] ) ) {
				continue;
			}
			$metodo = PedidosTable::METODOS[ $p['pago_metodo'] ] ?? '';
			/* translators: 1: importe, 2: nº del pedido */
			$que = sprintf( asde_t( 'Pago de %1$s · pedido de uniforme %2$s' ), Calculadora::euros( (float) ( $p['pago_importe'] ?? $p['total'] ) ), PedidosTable::numero( $p ) );
			$out[] = array(
				'cuando'  => (string) $p['pago_en'],
				'accion'  => 'registrado',
				'modulo'  => 'uniforme',
				'que'     => $que,
				'quien'   => (int) $p['pago_por'],
				'detalle' => trim(
					( $p['pago_fecha'] ? wp_date( 'd/m/Y', strtotime( $p['pago_fecha'] . ' 12:00' ) ) : '' )
					. ( '' !== $metodo ? ' · ' . asde_t( $metodo ) : '' )
					. ( '' !== (string) $p['pago_ref'] ? ' · ' . $p['pago_ref'] : '' ),
					' ·'
				),
			);
		}
		return $out;
	}

	/**
	 * Eventos en los que Tesorería puede apuntar un pago de esta familia: con la inscripción abierta,
	 * las cuentas sin cerrar y algo pendiente de la familia.
	 *
	 * @return list<array{evento:array, pendiente:float}>
	 */
	public static function eventos_para_cobrar( array $familia ): array {
		$out = array();
		foreach ( EventosTable::todos() as $e ) {
			if ( 'abierta' !== Inscripciones::plazo( $e ) || ! Cobros::abiertas( $e ) ) {
				continue;
			}
			$lista = Cobros::familias( $e )[ $familia['id'] ] ?? null;
			if ( $lista ) {
				$out[] = array(
					'evento'    => $e,
					'pendiente' => (float) $lista['pendiente'],
				);
			}
		}
		return $out;
	}
}
