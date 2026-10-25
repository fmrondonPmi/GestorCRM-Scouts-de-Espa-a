<?php
namespace AsdeBalears\AreaPrivada\Caja;

use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Tesoreria\Banco\BancoTable;
use AsdeBalears\AreaPrivada\Tesoreria\Banco\TiposMovimiento;

defined( 'ABSPATH' ) || exit;

/**
 * Las cajas de efectivo (v0.59, doc 61): saldo y apuntes de un periodo, y lo que se hace en ellas (saldo inicial,
 * apuntes a mano, traspasos, arqueos, anular y asociar un traspaso con su movimiento del banco).
 *
 * El saldo inicial (P-208) se pone una vez, con su fecha: es lo que había ese día al empezar; lo anterior a esa
 * fecha no cuenta. Después se arrastra: el saldo al empezar un periodo es todo lo anterior (desde el inicial).
 * Los permisos se comprueban aquí (Cajas::puede): la vista solo pinta.
 */
final class Servicio {

	/**
	 * Todos los apuntes de una caja hasta una fecha (incluida), desde su saldo inicial: los propios y, en la del
	 * grupo, los automáticos. Ordenados por fecha (y, el mismo día, el saldo inicial primero).
	 *
	 * @return list<array> Cada uno con fecha, importe, clase (inicial|manual|traspaso|arqueo|auto), concepto, origen…
	 */
	public static function apuntes( string $caja, string $hasta, string $desde_auto = '0000-01-01' ): array {
		$inicial = CajaTable::inicial( $caja );
		$desde   = $inicial ? (string) $inicial['fecha'] : '0000-01-01';
		$lista   = array();
		foreach ( CajaTable::de_caja( $caja, $hasta ) as $a ) {
			if ( $a['fecha'] < $desde ) {
				continue;
			}
			if ( CajaTable::INICIAL === $a['clase'] && ( ! $inicial || $a['id'] !== $inicial['id'] ) ) {
				continue; // Un saldo inicial anterior que se cambió (el vivo es el último).
			}
			$lista[] = $a + array(
				'origen'    => $a['clase'],
				'propio'    => true,
				'orden'     => CajaTable::INICIAL === $a['clase'] ? 0 : 1,
				'origen_id' => $a['id'],
			);
		}
		if ( Cajas::GRUPO === $caja ) {
			foreach ( Automaticos::apuntes( max( $desde, $desde_auto ), $hasta ) as $a ) {
				$lista[] = $a + array(
					'clase'  => 'auto',
					'propio' => false,
					'orden'  => 1,
					'id'     => 0,
				);
			}
		}
		usort( $lista, static fn( $x, $y ) => strcmp( $x['fecha'] . $x['orden'] . sprintf( '%012d', (int) $x['origen_id'] ), $y['fecha'] . $y['orden'] . sprintf( '%012d', (int) $y['origen_id'] ) ) );
		return $lista;
	}

	/**
	 * Saldo y apuntes de una caja en un periodo, con el saldo de cada fila.
	 *
	 * @param array{desde:string, hasta:string} $periodo Periodo::de().
	 * @return array{inicial:float, entradas:float, salidas:float, final:float, filas:list<array>, saldo_inicial:?array, arqueo:?array}
	 */
	public static function extracto( string $caja, array $periodo ): array {
		$saldo    = 0.0;
		$entradas = 0.0;
		$salidas  = 0.0;
		$arqueo   = null;
		$filas    = array();
		$inicial  = null;
		foreach ( self::apuntes( $caja, $periodo['hasta'] ) as $a ) {
			if ( $a['fecha'] < $periodo['desde'] ) {
				$saldo += $a['importe'];
				continue;
			}
			if ( null === $inicial ) {
				$inicial = round( $saldo, 2 );
			}
			$saldo += $a['importe'];
			if ( CajaTable::INICIAL !== $a['clase'] ) {
				if ( $a['importe'] > 0 ) {
					$entradas += $a['importe'];
				} else {
					$salidas -= $a['importe'];
				}
			}
			if ( CajaTable::ARQUEO === $a['clase'] ) {
				$arqueo = $a;
			}
			$filas[] = $a + array( 'saldo' => round( $saldo, 2 ) );
		}
		return array(
			'inicial'       => null === $inicial ? round( $saldo, 2 ) : $inicial,
			'entradas'      => round( $entradas, 2 ),
			'salidas'       => round( $salidas, 2 ),
			'final'         => round( $saldo, 2 ),
			'filas'         => $filas,
			'saldo_inicial' => CajaTable::inicial( $caja ),
			'arqueo'        => $arqueo,
		);
	}

	/** Saldo de una caja al final de un día (todo lo apuntado hasta ese día, desde su saldo inicial). */
	public static function saldo( string $caja, string $fecha ): float {
		return round( array_sum( array_column( self::apuntes( $caja, $fecha ), 'importe' ) ), 2 );
	}

	// ---------------------------------------------------------------------
	// Lo que se hace en la caja
	// ---------------------------------------------------------------------

	/**
	 * El saldo inicial de una caja (o del banco, P-208): una sola vez. Para corregirlo, se anula y se pone otro.
	 *
	 * @return int|\WP_Error Id del apunte.
	 */
	public static function poner_inicial( string $caja, string $fecha, float $importe ) {
		$banco = CajaTable::BANCO === $caja;
		if ( $banco ? ! Cajas::todas_suyas() : ! Cajas::puede( $caja ) ) {
			return new \WP_Error( 'asde_caja', asde_t( 'No puedes llevar esta caja.' ) );
		}
		$fecha = self::fecha( $fecha );
		if ( is_wp_error( $fecha ) ) {
			return $fecha;
		}
		if ( ! $banco && $importe < 0 ) {
			return new \WP_Error( 'asde_caja', asde_t( 'En una caja no puede haber menos de 0 €.' ) );
		}
		if ( CajaTable::inicial( $caja ) ) {
			return new \WP_Error( 'asde_caja', asde_t( 'Esta caja ya tiene su saldo inicial: para cambiarlo, anula el que hay.' ) );
		}
		$id = CajaTable::anadir(
			array(
				'caja'    => $caja,
				'clase'   => CajaTable::INICIAL,
				'fecha'   => $fecha,
				'importe' => $importe,
			)
		);
		Logger::log( 'caja_inicial', 'caja', $caja, array( 'apunte' => $id ) );
		return $id ?: new \WP_Error( 'asde_caja', asde_t( 'No se ha podido guardar.' ) );
	}

	/**
	 * Un apunte a mano: una entrada o una salida de efectivo que no es de ningún otro módulo (sellos, un donativo…).
	 *
	 * @return int|\WP_Error
	 */
	public static function apuntar( string $caja, string $fecha, string $sentido, float $importe, int $tipo_id, string $concepto, string $nota = '', int $evento_id = 0 ) {
		if ( ! Cajas::puede( $caja ) ) {
			return new \WP_Error( 'asde_caja', asde_t( 'No puedes llevar esta caja.' ) );
		}
		$fecha = self::fecha( $fecha, $caja );
		if ( is_wp_error( $fecha ) ) {
			return $fecha;
		}
		$importe  = round( $importe, 2 );
		$concepto = mb_substr( trim( sanitize_text_field( $concepto ) ), 0, 190 );
		if ( $importe <= 0 || ! in_array( $sentido, array( 'entrada', 'salida' ), true ) ) {
			return new \WP_Error( 'asde_caja', asde_t( 'Indica si entra o sale dinero y el importe (mayor que 0).' ) );
		}
		if ( '' === $concepto ) {
			return new \WP_Error( 'asde_caja', asde_t( 'Escribe el concepto.' ) );
		}
		$con_signo = 'entrada' === $sentido ? $importe : -$importe;
		if ( $tipo_id ) {
			$t = TiposMovimiento::obtener( $tipo_id );
			if ( ! $t || ! TiposMovimiento::encaja( $t, $con_signo ) ) {
				return new \WP_Error( 'asde_caja', asde_t( 'Ese tipo no vale para este apunte (un tipo de gasto, solo en una salida; uno de ingreso, en una entrada).' ) );
			}
		}
		$id = CajaTable::anadir(
			array(
				'caja'      => $caja,
				'clase'     => CajaTable::MANUAL,
				'fecha'     => $fecha,
				'importe'   => $con_signo,
				'tipo_id'   => $tipo_id,
				'evento_id' => $evento_id,
				'concepto'  => $concepto,
				'nota'      => mb_substr( trim( sanitize_text_field( $nota ) ), 0, 255 ),
			)
		);
		Logger::log( 'caja_apunte', 'caja', $caja, array( 'apunte' => $id, 'tipo' => $tipo_id ) );
		return $id ?: new \WP_Error( 'asde_caja', asde_t( 'No se ha podido guardar.' ) );
	}

	/**
	 * Traspaso desde una caja: a otra caja (dos apuntes enlazados), al banco (ingresar el efectivo) o, con $hacia =
	 * 'desde_banco', del banco a esta caja (sacar del cajero). Con el banco solo quien lleva todas las cajas (P-219).
	 *
	 * @return int|\WP_Error Id del apunte de esta caja.
	 */
	public static function traspasar( string $caja, string $hacia, string $fecha, float $importe, string $concepto = '' ) {
		if ( ! Cajas::puede( $caja ) ) {
			return new \WP_Error( 'asde_caja', asde_t( 'No puedes llevar esta caja.' ) );
		}
		$fecha = self::fecha( $fecha, $caja );
		if ( is_wp_error( $fecha ) ) {
			return $fecha;
		}
		$importe  = round( $importe, 2 );
		$concepto = mb_substr( trim( sanitize_text_field( $concepto ) ), 0, 190 );
		if ( $importe <= 0 ) {
			return new \WP_Error( 'asde_caja', asde_t( 'El importe tiene que ser mayor que 0.' ) );
		}
		$con_banco = in_array( $hacia, array( CajaTable::BANCO, 'desde_banco' ), true );
		if ( $con_banco && ! Cajas::todas_suyas() ) {
			return new \WP_Error( 'asde_caja', asde_t( 'Los traspasos con el banco los hace Tesorería.' ) );
		}
		if ( ! $con_banco && ( ! Cajas::existe( $hacia ) || $hacia === $caja ) ) {
			return new \WP_Error( 'asde_caja', asde_t( 'Elige a qué caja va el dinero.' ) );
		}
		if ( ! $con_banco && is_wp_error( self::fecha( $fecha, $hacia ) ) ) {
			return self::fecha( $fecha, $hacia ); // Tampoco antes del saldo inicial de la caja a la que va.
		}
		if ( 'desde_banco' !== $hacia && $importe > self::saldo( $caja, $fecha ) + 0.004 ) {
			return new \WP_Error( 'asde_caja', asde_t( 'En la caja no hay tanto dinero ese día.' ) );
		}
		$base = array(
			'clase'    => CajaTable::TRASPASO,
			'fecha'    => $fecha,
			'concepto' => $concepto,
		);
		if ( $con_banco ) {
			$id = CajaTable::anadir(
				$base + array(
					'caja'    => $caja,
					'importe' => 'desde_banco' === $hacia ? $importe : -$importe,
					'destino' => CajaTable::BANCO,
				)
			);
		} else {
			$id   = CajaTable::anadir( $base + array( 'caja' => $caja, 'importe' => -$importe, 'destino' => $hacia ) );
			$otro = CajaTable::anadir( $base + array( 'caja' => $hacia, 'importe' => $importe, 'destino' => $caja ) );
			CajaTable::enlazar_par( $id, $otro );
		}
		Logger::log( 'caja_traspaso', 'caja', $caja, array( 'apunte' => $id, 'hacia' => $hacia ) );
		return $id ?: new \WP_Error( 'asde_caja', asde_t( 'No se ha podido guardar.' ) );
	}

	/**
	 * Arqueo (P-211): lo que se ha contado en la caja ese día. Si no cuadra con el saldo calculado, pide un motivo y
	 * la diferencia queda como apunte de ajuste.
	 *
	 * @return array{id:int, diferencia:float}|\WP_Error
	 */
	public static function arquear( string $caja, string $fecha, float $recuento, string $motivo ) {
		if ( ! Cajas::puede( $caja ) ) {
			return new \WP_Error( 'asde_caja', asde_t( 'No puedes llevar esta caja.' ) );
		}
		$fecha = self::fecha( $fecha, $caja );
		if ( is_wp_error( $fecha ) ) {
			return $fecha;
		}
		if ( $recuento < 0 ) {
			return new \WP_Error( 'asde_caja', asde_t( 'Lo contado no puede ser menos de 0 €.' ) );
		}
		$recuento   = round( $recuento, 2 );
		$diferencia = round( $recuento - self::saldo( $caja, $fecha ), 2 );
		$motivo     = mb_substr( trim( sanitize_text_field( $motivo ) ), 0, 255 );
		if ( abs( $diferencia ) >= 0.01 && '' === $motivo ) {
			/* translators: %s: diferencia en euros */
			return new \WP_Error( 'asde_caja_motivo', sprintf( asde_t( 'No cuadra: hay una diferencia de %s. Escribe el motivo para guardar el arqueo.' ), \AsdeBalears\AreaPrivada\Tesoreria\Calculadora::euros( $diferencia ) ) );
		}
		$id = CajaTable::anadir(
			array(
				'caja'     => $caja,
				'clase'    => CajaTable::ARQUEO,
				'fecha'    => $fecha,
				'importe'  => $diferencia,
				'recuento' => $recuento,
				'nota'     => $motivo,
			)
		);
		Logger::log( 'caja_arqueo', 'caja', $caja, array( 'apunte' => $id, 'cuadra' => abs( $diferencia ) < 0.01 ) );
		return $id ? array(
			'id'         => $id,
			'diferencia' => $diferencia,
		) : new \WP_Error( 'asde_caja', asde_t( 'No se ha podido guardar.' ) );
	}

	/** Anula un apunte propio (y su pareja, si es un traspaso entre cajas), con motivo. @return true|\WP_Error */
	public static function anular( int $id, string $motivo ) {
		$a = CajaTable::obtener( $id );
		$ok = $a && ( CajaTable::BANCO === $a['caja'] ? Cajas::todas_suyas() : Cajas::puede( $a['caja'] ) );
		if ( ! $ok || null !== $a['anulado_en'] ) {
			return new \WP_Error( 'asde_caja', asde_t( 'Ese apunte no existe o ya está anulado.' ) );
		}
		$motivo = mb_substr( trim( sanitize_text_field( $motivo ) ), 0, 255 );
		if ( '' === $motivo ) {
			return new \WP_Error( 'asde_caja', asde_t( 'Escribe el motivo para anular el apunte.' ) );
		}
		if ( ! CajaTable::anular( $id, $motivo ) ) {
			return new \WP_Error( 'asde_caja', asde_t( 'Ese apunte no existe o ya está anulado.' ) );
		}
		Logger::log( 'caja_anulado', 'caja', (string) $a['caja'], array( 'apunte' => $id ) );
		return true;
	}

	// ---------------------------------------------------------------------
	// Traspasos con el banco (P-219)
	// ---------------------------------------------------------------------

	/**
	 * Movimientos del banco con los que se puede asociar un traspaso: los de importe contrario (si sale de la caja,
	 * un ingreso en el banco), sin otro traspaso asociado; primero el mismo importe y la fecha más cercana.
	 *
	 * @return array[]
	 */
	public static function candidatos_banco( array $apunte, int $limite = 50 ): array {
		$cent  = (int) round( abs( $apunte['importe'] ) * 100 );
		$signo = $apunte['importe'] < 0 ? 1 : -1; // En el banco, al revés que en la caja.
		$dia   = (int) ( strtotime( $apunte['fecha'] . ' 12:00' ) / DAY_IN_SECONDS );
		$lista = array_values( array_filter( BancoTable::movimientos( array( 'tipo' => $signo > 0 ? 'ingresos' : 'gastos' ) ), static fn( $m ) => BancoTable::CONCILIADO !== $m['estado'] ) );
		$usados = CajaTable::por_banco( array_column( $lista, 'id' ) );
		$lista  = array_values( array_filter( $lista, static fn( $m ) => ! isset( $usados[ (int) $m['id'] ] ) ) );
		foreach ( $lista as &$m ) {
			$m['igual'] = (int) round( abs( $m['importe'] ) * 100 ) === $cent;
			$m['orden'] = sprintf( '%d%08d', $m['igual'] ? 0 : 1, abs( (int) ( strtotime( $m['fecha'] . ' 12:00' ) / DAY_IN_SECONDS ) - $dia ) );
		}
		unset( $m );
		usort( $lista, static fn( $x, $y ) => strcmp( $x['orden'], $y['orden'] ) );
		return array_slice( $lista, 0, $limite );
	}

	/** Asocia (o, con 0, desasocia) un traspaso con el banco y su movimiento del banco. @return true|\WP_Error */
	public static function asociar_banco( int $id, int $banco_id ) {
		$a = CajaTable::obtener( $id );
		if ( ! $a || CajaTable::TRASPASO !== $a['clase'] || CajaTable::BANCO !== $a['destino'] || null !== $a['anulado_en'] || ! Cajas::todas_suyas() ) {
			return new \WP_Error( 'asde_caja', asde_t( 'Solo un traspaso con el banco se asocia con un movimiento del banco.' ) );
		}
		if ( ! $banco_id ) {
			CajaTable::poner_banco( $id, 0 );
			Logger::log( 'caja_banco_quitado', 'caja', (string) $a['caja'], array( 'apunte' => $id ) );
			return true;
		}
		$m = BancoTable::obtener( $banco_id );
		if ( ! $m || ( $m['importe'] > 0 ) === ( $a['importe'] > 0 ) ) {
			return new \WP_Error( 'asde_caja', asde_t( 'Elige un movimiento del banco de signo contrario (si sale de la caja, un ingreso en el banco).' ) );
		}
		if ( ! CajaTable::poner_banco( $id, $banco_id ) ) {
			return new \WP_Error( 'asde_caja', asde_t( 'Ese movimiento del banco ya está asociado con otro traspaso (o este ya tiene uno).' ) );
		}
		Logger::log( 'caja_banco_asociado', 'caja', (string) $a['caja'], array( 'apunte' => $id, 'banco' => $banco_id ) );
		return true;
	}

	/**
	 * Fecha válida, no posterior a hoy y, con $caja, no anterior a su saldo inicial (lo anterior no cuenta: el apunte
	 * desaparecería del saldo).
	 *
	 * @return string|\WP_Error
	 */
	private static function fecha( string $fecha, string $caja = '' ) {
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $fecha ) || ! date_create( $fecha ) || $fecha > current_time( 'Y-m-d' ) ) {
			return new \WP_Error( 'asde_caja', asde_t( 'Indica la fecha (no posterior a hoy).' ) );
		}
		$inicial = '' !== $caja ? CajaTable::inicial( $caja ) : null;
		if ( $inicial && $fecha < $inicial['fecha'] ) {
			/* translators: %s: fecha del saldo inicial */
			return new \WP_Error( 'asde_caja', sprintf( asde_t( 'La fecha no puede ser anterior al saldo inicial de la caja (%s).' ), wp_date( 'd/m/Y', strtotime( $inicial['fecha'] . ' 12:00' ) ) ) );
		}
		return $fecha;
	}
}
