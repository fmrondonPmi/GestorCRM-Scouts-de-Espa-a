<?php
namespace AsdeBalears\AreaPrivada\Caja;

use AsdeBalears\AreaPrivada\Domain\Grupo;
use AsdeBalears\AreaPrivada\Domain\Modulos;
use AsdeBalears\AreaPrivada\Eventos\AnticiposTable;
use AsdeBalears\AreaPrivada\Eventos\EventosTable;
use AsdeBalears\AreaPrivada\Eventos\GastosTable;
use AsdeBalears\AreaPrivada\Eventos\MovimientosTable as EventoMovimientos;
use AsdeBalears\AreaPrivada\Reintegros\ReintegrosTable;
use AsdeBalears\AreaPrivada\Tesoreria\FamiliasTable;
use AsdeBalears\AreaPrivada\Tesoreria\MovimientosTable;
use AsdeBalears\AreaPrivada\Tienda\PedidosTable;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- tablas propias del plugin ({prefix}asde_*): solo lectura, se calcula en vivo en cada petición (P-212), no se cachea.

/**
 * Lo que entra y sale en efectivo de la caja del grupo sin que nadie lo apunte en la caja (v0.59, doc 61, P-209):
 * se lee en vivo de cada módulo (P-212), así que si se anula allí, desaparece aquí. Todo va a la caja del grupo
 * (P-210b). Solo de los módulos activos en el grupo.
 *
 *   + cuotas pagadas en efectivo (Tesorería), cobros de eventos en efectivo, ingresos de eventos en efectivo,
 *     pedidos de la tienda pagados en efectivo (sin cancelar), lo devuelto de un anticipo de caja en efectivo;
 *   − devoluciones de eventos en efectivo, gastos de eventos pagados por el grupo en efectivo (P-214), reembolsos
 *     en efectivo, anticipos de caja entregados en efectivo y reintegros pagados en efectivo (con su fecha de
 *     pago, P-213).
 * Para no restar dos veces lo mismo: un reintegro con evento crea en el evento un gasto ya reembolsado
 * (Reintegros\Servicio::pagar); ese gasto no se cuenta aquí, solo el reintegro.
 */
final class Automaticos {

	/** origen => etiqueta (es también la partida del libro mayor). */
	public const ORIGENES = array(
		'cuota'          => 'Cuotas en efectivo',
		'evento_cobro'   => 'Eventos: cobros',
		'evento_devol'   => 'Eventos: devoluciones',
		'evento_gasto'   => 'Eventos: gastos',
		'evento_ingreso' => 'Eventos: ingresos',
		'reembolso'      => 'Eventos: reembolsos',
		'anticipo'       => 'Anticipos de caja',
		'tienda'         => 'Tienda',
		'reintegro'      => 'Reintegros',
	);

	/**
	 * Apuntes automáticos de la caja del grupo entre dos fechas (incluidas), sin ordenar.
	 *
	 * @return list<array{fecha:string, importe:float, origen:string, origen_id:int, concepto:string, evento_id:int, pagina:string, args:array}>
	 */
	public static function apuntes( string $desde, string $hasta ): array {
		global $wpdb;
		$g   = Grupo::numero();
		$out = array();
		$add = static function ( string $fecha, float $importe, string $origen, int $id, string $concepto, int $evento = 0, string $pagina = '', array $args = array() ) use ( &$out ) {
			if ( abs( $importe ) < 0.005 ) {
				return;
			}
			$out[] = array(
				'fecha'     => substr( $fecha, 0, 10 ),
				'importe'   => round( $importe, 2 ),
				'origen'    => $origen,
				'origen_id' => $id,
				'concepto'  => $concepto,
				'evento_id' => $evento,
				'pagina'    => $pagina,
				'args'      => $args,
			);
		};

		if ( Modulos::activo( Modulos::TESORERIA ) ) {
			$filas = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT m.id, m.fecha, m.importe, m.familia_id, m.ronda, f.numero FROM %i m INNER JOIN %i f ON f.id = m.familia_id WHERE f.grupo = %s AND m.tipo = %s AND m.metodo = %s AND m.anulado_en IS NULL AND m.fecha BETWEEN %s AND %s', MovimientosTable::table(), FamiliasTable::table(), $g, MovimientosTable::PAGO, 'efectivo', $desde, $hasta ), ARRAY_A );
			foreach ( $filas as $f ) {
				/* translators: %s: nº de familia */
				$add( $f['fecha'], (float) $f['importe'], 'cuota', (int) $f['id'], sprintf( asde_t( 'Cuota de la familia %s' ), $f['numero'] ), 0, 'tesoreria', array( 'familia' => (int) $f['familia_id'], 'ronda' => $f['ronda'] ) );
			}
		}

		if ( Modulos::activo( Modulos::EVENTOS ) ) {
			$nombres = array_column( EventosTable::todos(), 'nombre', 'id' );
			$ev      = static fn( int $id ) => (string) ( $nombres[ $id ] ?? '' );
			$enlace  = static fn( int $id ) => array(
				'evento'  => $id,
				'pestana' => 'cuentas',
			);

			$filas = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT id, evento_id, tipo, fecha, importe FROM %i WHERE grupo = %s AND forma = %s AND anulado_en IS NULL AND tipo IN ( %s, %s ) AND fecha BETWEEN %s AND %s', EventoMovimientos::table(), $g, 'efectivo', EventoMovimientos::PAGO, EventoMovimientos::DEVOLUCION, $desde, $hasta ), ARRAY_A );
			foreach ( $filas as $f ) {
				$cobro = EventoMovimientos::PAGO === $f['tipo'];
				/* translators: %s: nombre del evento */
				$add( $f['fecha'], ( $cobro ? 1 : -1 ) * abs( (float) $f['importe'] ), $cobro ? 'evento_cobro' : 'evento_devol', (int) $f['id'], trim( sprintf( $cobro ? asde_t( 'Cobro · %s' ) : asde_t( 'Devolución · %s' ), $ev( (int) $f['evento_id'] ) ), ' ·' ), (int) $f['evento_id'], 'eventos', $enlace( (int) $f['evento_id'] ) );
			}

			// Gastos e ingresos validados que pagó o cobró el grupo en efectivo; los reembolsos en efectivo, con su fecha.
			$de_reintegros = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT gasto_id FROM %i WHERE grupo = %s AND gasto_id > 0', ReintegrosTable::table(), $g ) ) );
			$filas         = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT id, evento_id, tipo, fecha, concepto, importe, forma, pagado_por, reembolsado_en, reembolso_forma FROM %i WHERE grupo = %s AND estado = %s AND anulado_en IS NULL AND ( ( pagado_por = %s AND forma = %s AND fecha BETWEEN %s AND %s ) OR ( pagado_por = %s AND reembolso_forma = %s AND reembolsado_en BETWEEN %s AND %s ) )', GastosTable::table(), $g, GastosTable::VALIDADO, GastosTable::PAGO_GRUPO, 'efectivo', $desde, $hasta, GastosTable::PAGO_PERSONA, 'efectivo', $desde, $hasta ), ARRAY_A );
			foreach ( $filas as $f ) {
				if ( in_array( (int) $f['id'], $de_reintegros, true ) ) {
					continue; // Lo cuenta el reintegro.
				}
				$texto = trim( $f['concepto'] . ' · ' . $ev( (int) $f['evento_id'] ), ' ·' );
				if ( GastosTable::PAGO_PERSONA === $f['pagado_por'] ) {
					$add( (string) $f['reembolsado_en'], -abs( (float) $f['importe'] ), 'reembolso', (int) $f['id'], $texto, (int) $f['evento_id'], 'eventos', $enlace( (int) $f['evento_id'] ) );
				} elseif ( GastosTable::INGRESO === $f['tipo'] ) {
					$add( $f['fecha'], abs( (float) $f['importe'] ), 'evento_ingreso', (int) $f['id'], $texto, (int) $f['evento_id'], 'eventos', $enlace( (int) $f['evento_id'] ) );
				} else {
					$add( $f['fecha'], -abs( (float) $f['importe'] ), 'evento_gasto', (int) $f['id'], $texto, (int) $f['evento_id'], 'eventos', $enlace( (int) $f['evento_id'] ) );
				}
			}

			// Anticipos de caja en efectivo: salen al darlos y vuelve lo devuelto al liquidarlos.
			$filas = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT id, evento_id, fecha, importe, estado, devuelto, liquidado_en FROM %i WHERE grupo = %s AND forma = %s AND anulado_en IS NULL AND ( fecha BETWEEN %s AND %s OR ( estado = %s AND devuelto > 0 AND DATE( liquidado_en ) BETWEEN %s AND %s ) )', AnticiposTable::table(), $g, 'efectivo', $desde, $hasta, AnticiposTable::LIQUIDADO, $desde, $hasta ), ARRAY_A );
			foreach ( $filas as $f ) {
				/* translators: %s: nombre del evento */
				$texto = trim( sprintf( asde_t( 'Anticipo de caja · %s' ), $ev( (int) $f['evento_id'] ) ), ' ·' );
				if ( $f['fecha'] >= $desde && $f['fecha'] <= $hasta ) {
					$add( $f['fecha'], -abs( (float) $f['importe'] ), 'anticipo', (int) $f['id'], $texto, (int) $f['evento_id'], 'eventos', $enlace( (int) $f['evento_id'] ) );
				}
				$liq = $f['liquidado_en'] ? wp_date( 'Y-m-d', strtotime( $f['liquidado_en'] . ' UTC' ) ) : '';
				if ( AnticiposTable::LIQUIDADO === $f['estado'] && (float) $f['devuelto'] > 0 && '' !== $liq && $liq >= $desde && $liq <= $hasta ) {
					/* translators: %s: nombre del evento */
					$add( $liq, abs( (float) $f['devuelto'] ), 'anticipo', (int) $f['id'], trim( sprintf( asde_t( 'Devuelto del anticipo · %s' ), $ev( (int) $f['evento_id'] ) ), ' ·' ), (int) $f['evento_id'], 'eventos', $enlace( (int) $f['evento_id'] ) );
				}
			}
		}

		if ( Modulos::activo( Modulos::TIENDA ) ) {
			$filas = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT id, pago_fecha, pago_importe, total FROM %i WHERE grupo = %s AND pago_metodo = %s AND pago_fecha IS NOT NULL AND cancelado_en IS NULL AND pago_fecha BETWEEN %s AND %s', PedidosTable::table(), $g, 'efectivo', $desde, $hasta ), ARRAY_A );
			foreach ( $filas as $f ) {
				/* translators: %d: nº de pedido */
				$add( (string) $f['pago_fecha'], (float) ( null !== $f['pago_importe'] ? $f['pago_importe'] : $f['total'] ), 'tienda', (int) $f['id'], sprintf( asde_t( 'Pedido de la tienda nº %d' ), (int) $f['id'] ), 0, 'tienda_gestion', array( 'pedido' => (int) $f['id'] ) );
			}
		}

		if ( Modulos::activo( Modulos::REINTEGROS ) ) {
			$filas = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT id, concepto, importe, evento_id, pago_fecha FROM %i WHERE grupo = %s AND estado = %s AND forma = %s AND pago_fecha BETWEEN %s AND %s', ReintegrosTable::table(), $g, ReintegrosTable::PAGADO, ReintegrosTable::EFECTIVO, $desde, $hasta ), ARRAY_A );
			foreach ( $filas as $f ) {
				/* translators: %s: concepto del reintegro */
				$add( (string) $f['pago_fecha'], -abs( (float) $f['importe'] ), 'reintegro', (int) $f['id'], sprintf( asde_t( 'Reintegro · %s' ), $f['concepto'] ), (int) $f['evento_id'], 'reintegros', array() );
			}
		}

		return $out;
	}
}
