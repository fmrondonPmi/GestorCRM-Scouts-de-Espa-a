<?php
namespace AsdeBalears\AreaPrivada\Caja;

use AsdeBalears\AreaPrivada\Domain\Modulos;
use AsdeBalears\AreaPrivada\Tesoreria\Banco\BancoTable;
use AsdeBalears\AreaPrivada\Tesoreria\Banco\Norma43;
use AsdeBalears\AreaPrivada\Tesoreria\Banco\TiposMovimiento;

defined( 'ABSPATH' ) || exit;

/**
 * Libro mayor (v0.59, doc 61, P-206): una cuenta por medio de pago —el Banco (los movimientos importados) y cada
 * caja de efectivo— con su saldo al empezar el periodo, sus apuntes por fecha y el saldo acumulado, y un resumen
 * por partida (el tipo de gasto o ingreso, o de dónde viene el apunte automático). Solo lectura: se calcula en vivo.
 *
 * Los traspasos (entre cajas o con el banco) mueven dinero de una cuenta a otra del grupo: salen en las cuentas pero
 * no en el resumen. En el banco, un movimiento asociado a un traspaso de caja (P-219) también es traspaso.
 */
final class LibroMayor {

	public const TRASPASOS = 'Traspasos';

	/**
	 * @param array{desde:string, hasta:string} $periodo
	 * @return array{cuentas: array<string, array{nombre:string, inicial:float, entradas:float, salidas:float, final:float, filas:list<array>, sin_inicial:bool}>, resumen: array<string, array{entradas:float, salidas:float}>}
	 */
	public static function calcular( array $periodo ): array {
		$cuentas = array();
		if ( Modulos::activo( Modulos::BANCO ) ) {
			$cuentas[ CajaTable::BANCO ] = self::banco( $periodo );
		}
		$tipos = TiposMovimiento::todos();
		foreach ( Cajas::todas() as $caja ) {
			$e     = Servicio::extracto( $caja, $periodo );
			$filas = array();
			foreach ( $e['filas'] as $a ) {
				if ( CajaTable::INICIAL === $a['clase'] ) {
					continue;
				}
				$filas[] = array(
					'fecha'    => $a['fecha'],
					'concepto' => self::concepto_caja( $a ),
					'partida'  => self::partida_caja( $a, $tipos ),
					'importe'  => $a['importe'],
					'saldo'    => $a['saldo'],
				);
			}
			// Una caja de sección sin nada ni saldo no se enseña.
			if ( Cajas::GRUPO !== $caja && ! $filas && abs( $e['final'] ) < 0.005 && ! $e['saldo_inicial'] ) {
				continue;
			}
			$cuentas[ $caja ] = array(
				'nombre'      => Cajas::nombre( $caja ),
				'inicial'     => $e['inicial'],
				'entradas'    => $e['entradas'],
				'salidas'     => $e['salidas'],
				'final'       => $e['final'],
				'filas'       => $filas,
				'sin_inicial' => ! $e['saldo_inicial'],
			);
		}

		$resumen = array();
		foreach ( $cuentas as $c ) {
			foreach ( $c['filas'] as $f ) {
				if ( self::TRASPASOS === $f['partida'] ) {
					continue;
				}
				$r = &$resumen[ $f['partida'] ];
				$r = $r ?? array(
					'entradas' => 0.0,
					'salidas'  => 0.0,
				);
				if ( $f['importe'] > 0 ) {
					$r['entradas'] += $f['importe'];
				} else {
					$r['salidas'] -= $f['importe'];
				}
				unset( $r );
			}
		}
		foreach ( $resumen as &$r ) {
			$r['entradas'] = round( $r['entradas'], 2 );
			$r['salidas']  = round( $r['salidas'], 2 );
		}
		unset( $r );
		uksort( $resumen, static fn( $a, $b ) => strcmp( remove_accents( mb_strtolower( $a ) ), remove_accents( mb_strtolower( $b ) ) ) );

		return array(
			'cuentas' => $cuentas,
			'resumen' => $resumen,
		);
	}

	/**
	 * La cuenta del banco: el saldo inicial (a mano, P-208) y los movimientos importados desde esa fecha.
	 *
	 * @return array{nombre:string, inicial:float, entradas:float, salidas:float, final:float, filas:list<array>, sin_inicial:bool}
	 */
	private static function banco( array $periodo ): array {
		$ini    = CajaTable::inicial( CajaTable::BANCO );
		$base   = $ini ? (string) $ini['fecha'] : '0000-01-01';
		$tipos  = TiposMovimiento::todos();
		$movs   = array_values( array_filter( BancoTable::movimientos(), static fn( $m ) => $m['fecha'] >= $base && $m['fecha'] <= $periodo['hasta'] ) );
		$caja   = CajaTable::por_banco( array_column( $movs, 'id' ) );
		usort( $movs, static fn( $a, $b ) => strcmp( $a['fecha'] . sprintf( '%012d', $a['id'] ), $b['fecha'] . sprintf( '%012d', $b['id'] ) ) );
		$saldo    = $ini ? (float) $ini['importe'] : 0.0;
		$inicial  = null;
		$entradas = 0.0;
		$salidas  = 0.0;
		$filas    = array();
		foreach ( $movs as $m ) {
			if ( $m['fecha'] < $periodo['desde'] ) {
				$saldo += $m['importe'];
				continue;
			}
			$inicial = $inicial ?? round( $saldo, 2 );
			$saldo  += $m['importe'];
			if ( $m['importe'] > 0 ) {
				$entradas += $m['importe'];
			} else {
				$salidas -= $m['importe'];
			}
			$texto   = trim( $m['ordenante'] . ' ' . ( '' !== $m['concepto'] ? $m['concepto'] : asde_t( Norma43::CONCEPTOS[ $m['concepto_comun'] ] ?? '' ) ) );
			$filas[] = array(
				'fecha'    => $m['fecha'],
				'concepto' => $texto,
				'partida'  => isset( $caja[ (int) $m['id'] ] ) ? self::TRASPASOS : self::partida_banco( $m, $tipos ),
				'importe'  => round( $m['importe'], 2 ),
				'saldo'    => round( $saldo, 2 ),
			);
		}
		return array(
			'nombre'      => asde_t( 'Banco' ),
			'inicial'     => $inicial ?? round( $saldo, 2 ),
			'entradas'    => round( $entradas, 2 ),
			'salidas'     => round( $salidas, 2 ),
			'final'       => round( $saldo, 2 ),
			'filas'       => $filas,
			'sin_inicial' => ! $ini,
		);
	}

	/** Partida de un movimiento del banco: cuotas (conciliado), su tipo (revisado) o sin revisar. */
	public static function partida_banco( array $m, array $tipos ): string {
		if ( BancoTable::CONCILIADO === $m['estado'] ) {
			return asde_t( 'Cuotas (banco)' );
		}
		if ( BancoTable::IGNORADO === $m['estado'] ) {
			return isset( $tipos[ (int) $m['tipo_id'] ] ) ? (string) $tipos[ (int) $m['tipo_id'] ]['nombre'] : asde_t( 'Revisado sin tipo' );
		}
		return asde_t( 'Sin revisar' );
	}

	/** Partida de un apunte de caja: traspasos, diferencias de arqueo, su tipo (a mano) o su origen (automático). */
	public static function partida_caja( array $a, array $tipos ): string {
		switch ( $a['clase'] ) {
			case CajaTable::TRASPASO:
				return self::TRASPASOS;
			case CajaTable::ARQUEO:
				return asde_t( 'Diferencias de arqueo' );
			case CajaTable::MANUAL:
				return isset( $tipos[ (int) $a['tipo_id'] ] ) ? (string) $tipos[ (int) $a['tipo_id'] ]['nombre'] : asde_t( 'Otros (caja)' );
			default:
				return asde_t( Automaticos::ORIGENES[ $a['origen'] ] ?? 'Otros (caja)' );
		}
	}

	/** Concepto de un apunte de caja para el listado y el Excel. */
	public static function concepto_caja( array $a ): string {
		switch ( $a['clase'] ) {
			case CajaTable::TRASPASO:
				$con = CajaTable::BANCO === $a['destino'] ? asde_t( 'Banco' ) : Cajas::nombre( (string) $a['destino'] );
				/* translators: %s: la caja o el banco del otro lado del traspaso */
				$texto = $a['importe'] < 0 ? sprintf( asde_t( 'Traspaso a %s' ), $con ) : sprintf( asde_t( 'Traspaso desde %s' ), $con );
				return trim( $texto . ( '' !== $a['concepto'] ? ' · ' . $a['concepto'] : '' ) );
			case CajaTable::ARQUEO:
				/* translators: %s: importe contado */
				return trim( sprintf( asde_t( 'Arqueo: contado %s' ), \AsdeBalears\AreaPrivada\Tesoreria\Calculadora::euros( (float) $a['recuento'] ) ) . ( '' !== $a['nota'] ? ' · ' . $a['nota'] : '' ) );
			case CajaTable::INICIAL:
				return asde_t( 'Saldo inicial' );
			default:
				return (string) $a['concepto'];
		}
	}
}
