<?php
namespace AsdeBalears\AreaPrivada\Eventos;

use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Tesoreria\FamiliasTable;

defined( 'ABSPATH' ) || exit;

/**
 * Cobro de las inscripciones (v0.19.1, doc 32, apartado 4): lo registra **Tesorería**
 * (`asde_eventos_economia`).
 *
 * - Lo que paga una inscripción = el precio de su sección (fijo desde que Tesorería validó el
 *   presupuesto) + sus becas y ajustes (con motivo). Lo de los demás no cambia.
 * - Pagos en efectivo, por ingreso o transferencia y por Bizum (P-68), de una inscripción o de
 *   la **familia** (un solo pago para los hermanos, repartido entre sus inscripciones).
 * - La referencia de la transferencia o el Bizum: nº de evento y nº de familia de Tesorería
 *   («E6840012 F684001») o, sin familia, el nº de la inscripción.
 * - Cada pago o ajuste vuelve a calcular el estado: pagada y autorizada = confirmada.
 */
final class Cobros {

	/** @return array{precio:float, ajustes:float, total:float, pagado:float, pendiente:float} */
	public static function de( array $f, array $movs ): array {
		$ajustes = 0.0;
		$pagado  = 0.0;
		foreach ( $movs as $m ) {
			if ( null !== $m['anulado_en'] ) {
				continue;
			}
			if ( MovimientosTable::AJUSTE === $m['tipo'] ) {
				$ajustes += $m['importe'];
			} elseif ( MovimientosTable::PAGO === $m['tipo'] ) {
				$pagado += $m['importe'];
			}
		}
		$total = max( 0.0, round( $f['precio'] + $ajustes, 2 ) );
		return array(
			'precio'    => $f['precio'],
			'ajustes'   => round( $ajustes, 2 ),
			'total'     => $total,
			'pagado'    => round( $pagado, 2 ),
			'pendiente' => max( 0.0, round( $total - $pagado, 2 ) ),
		);
	}

	/** Referencia para pagar por transferencia o Bizum. */
	public static function referencia( array $e, array $f ): string {
		$familia = FamiliasTable::de_socio( $f['socio'] );
		return EventosTable::numero( $e ) . ' ' . ( $familia ? $familia['numero'] : InscripcionesTable::numero( $f ) );
	}

	public static function puede(): bool {
		return current_user_can( Servicio::CAP_ECONOMIA );
	}

	/** @return int|\WP_Error Id del movimiento. */
	public static function pagar( array $e, int $fila, float $importe, string $forma, string $fecha, string $referencia, string $nota ) {
		$f = self::fila( $e, $fila );
		if ( ! self::puede() || ! $f || ! in_array( $f['estado'], InscripcionesTable::ACTIVAS, true ) ) {
			return new \WP_Error( 'asde_cobro', asde_t( 'Los pagos de las inscripciones los registra Tesorería.' ) );
		}
		$error = self::validar( $importe, $forma, $fecha );
		if ( $error ) {
			return $error;
		}
		$cobro = self::de( $f, MovimientosTable::de_inscripciones( array( $f['id'] ) )[ $f['id'] ] ?? array() );
		if ( $importe > $cobro['pendiente'] + 0.001 ) {
			return new \WP_Error( 'asde_cobro', sprintf( asde_t( 'Es más de lo que queda por pagar (%s).' ), \AsdeBalears\AreaPrivada\Tesoreria\Calculadora::euros( $cobro['pendiente'] ) ) );
		}
		$id = MovimientosTable::anadir( $e['id'], $f['id'], MovimientosTable::PAGO, $fecha, $importe, $forma, self::texto( $referencia, 120 ), self::texto( $nota, 255 ) );
		Logger::log(
			'evento_pago',
			'evento',
			(string) $e['id'],
			array(
				'inscripcion' => $f['id'],
				'forma'       => $forma,
			)
		);
		Inscripciones::recalcular( $e, $f['id'] );
		return $id;
	}

	/**
	 * Un pago de la familia para todas sus inscripciones del evento: se reparte entre las que
	 * tienen algo pendiente, por orden de inscripción, en movimientos del mismo lote.
	 *
	 * @return int|\WP_Error Cuántas inscripciones ha cubierto.
	 */
	public static function pagar_familia( array $e, int $familia_id, float $importe, string $forma, string $fecha, string $referencia, string $nota ) {
		if ( ! self::puede() ) {
			return new \WP_Error( 'asde_cobro', asde_t( 'Los pagos de las inscripciones los registra Tesorería.' ) );
		}
		$familia = FamiliasTable::obtener( $familia_id );
		$lista   = $familia ? ( self::familias( $e )[ $familia_id ] ?? null ) : null;
		if ( ! $lista ) {
			return new \WP_Error( 'asde_cobro', asde_t( 'Esa familia no tiene nada pendiente en este evento.' ) );
		}
		$error = self::validar( $importe, $forma, $fecha );
		if ( $error ) {
			return $error;
		}
		if ( $importe > $lista['pendiente'] + 0.001 ) {
			return new \WP_Error( 'asde_cobro', sprintf( asde_t( 'Es más de lo que queda por pagar (%s).' ), \AsdeBalears\AreaPrivada\Tesoreria\Calculadora::euros( $lista['pendiente'] ) ) );
		}
		$lote  = substr( md5( uniqid( 'evento', true ) ), 0, 16 );
		$resto = round( $importe, 2 );
		$n     = 0;
		foreach ( $lista['filas'] as $f ) {
			if ( $resto <= 0 ) {
				break;
			}
			$parte = min( $resto, $f['cobro']['pendiente'] );
			if ( $parte <= 0 ) {
				continue;
			}
			MovimientosTable::anadir( $e['id'], $f['id'], MovimientosTable::PAGO, $fecha, $parte, $forma, self::texto( $referencia, 120 ), self::texto( $nota, 255 ), $lote );
			$resto = round( $resto - $parte, 2 );
			++$n;
		}
		Logger::log(
			'evento_pago_familia',
			'evento',
			(string) $e['id'],
			array(
				'familia'       => $familia_id,
				'inscripciones' => $n,
				'forma'         => $forma,
			)
		);
		foreach ( $lista['filas'] as $f ) {
			Inscripciones::recalcular( $e, $f['id'] );
		}
		return $n;
	}

	/** Beca o ajuste de una inscripción (negativo = rebaja), siempre con motivo. @return int|\WP_Error */
	public static function ajustar( array $e, int $fila, float $importe, string $motivo ) {
		$f = self::fila( $e, $fila );
		if ( ! self::puede() || ! $f || ! in_array( $f['estado'], array( InscripcionesTable::PENDIENTE, InscripcionesTable::CONFIRMADA, InscripcionesTable::ESPERA ), true ) ) {
			return new \WP_Error( 'asde_cobro', asde_t( 'Las becas y los ajustes los hace Tesorería.' ) );
		}
		$motivo  = self::texto( $motivo, 255 );
		$importe = round( $importe, 2 );
		if ( 0.0 === $importe || '' === $motivo ) {
			return new \WP_Error( 'asde_cobro', asde_t( 'Escribe el importe del ajuste y su motivo.' ) );
		}
		$cobro = self::de( $f, MovimientosTable::de_inscripciones( array( $f['id'] ) )[ $f['id'] ] ?? array() );
		if ( $f['precio'] + $cobro['ajustes'] + $importe < -0.001 ) {
			return new \WP_Error( 'asde_cobro', asde_t( 'La rebaja no puede ser mayor que el precio.' ) );
		}
		$id = MovimientosTable::anadir( $e['id'], $f['id'], MovimientosTable::AJUSTE, current_time( 'Y-m-d' ), $importe, '', '', $motivo );
		Logger::log(
			'evento_ajuste',
			'evento',
			(string) $e['id'],
			array(
				'inscripcion' => $f['id'],
				'sentido'     => $importe < 0 ? 'rebaja' : 'recargo',
			)
		);
		Inscripciones::recalcular( $e, $f['id'] );
		return $id;
	}

	/** Anula un pago o un ajuste (el pago de una familia, entero). @return true|\WP_Error */
	public static function anular( array $e, int $movimiento ) {
		$m = MovimientosTable::obtener( $movimiento );
		if ( ! self::puede() || ! $m || $m['evento_id'] !== $e['id'] || null !== $m['anulado_en'] ) {
			return new \WP_Error( 'asde_cobro', asde_t( 'Ese movimiento no se puede anular.' ) );
		}
		$afectadas = MovimientosTable::anular( $movimiento );
		Logger::log(
			'evento_movimiento_anulado',
			'evento',
			(string) $e['id'],
			array(
				'movimiento' => $movimiento,
				'tipo'       => $m['tipo'],
			)
		);
		foreach ( $afectadas as $fila ) {
			Inscripciones::recalcular( $e, $fila );
		}
		return true;
	}

	/**
	 * Familias de Tesorería con algo pendiente en el evento (para el pago de la familia).
	 *
	 * @return array<int, array{familia:array, filas:array[], pendiente:float}>
	 */
	public static function familias( array $e ): array {
		$movs = MovimientosTable::de_evento( $e['id'] );
		$out  = array();
		foreach ( InscripcionesTable::participantes( $e['id'] ) as $f ) {
			if ( ! in_array( $f['estado'], InscripcionesTable::ACTIVAS, true ) ) {
				continue;
			}
			$cobro = self::de( $f, $movs[ $f['id'] ] ?? array() );
			if ( $cobro['pendiente'] <= 0 ) {
				continue;
			}
			$familia = FamiliasTable::de_socio( $f['socio'] );
			if ( ! $familia ) {
				continue;
			}
			$out[ $familia['id'] ]['familia']    = $familia;
			$out[ $familia['id'] ]['filas'][]    = $f + array( 'cobro' => $cobro );
			$out[ $familia['id'] ]['pendiente'] = round( ( $out[ $familia['id'] ]['pendiente'] ?? 0 ) + $cobro['pendiente'], 2 );
		}
		return $out;
	}

	private static function validar( float $importe, string $forma, string $fecha ): ?\WP_Error {
		if ( $importe <= 0 ) {
			return new \WP_Error( 'asde_cobro', asde_t( 'Escribe un importe mayor que cero.' ) );
		}
		if ( ! isset( MovimientosTable::FORMAS[ $forma ] ) ) {
			return new \WP_Error( 'asde_cobro', asde_t( 'Elige la forma de pago.' ) );
		}
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $fecha ) || ! date_create( $fecha ) || $fecha > current_time( 'Y-m-d' ) ) {
			return new \WP_Error( 'asde_cobro', asde_t( 'La fecha del pago no puede ser posterior a hoy.' ) );
		}
		return null;
	}

	private static function fila( array $e, int $fila ): ?array {
		$f = InscripcionesTable::obtener( $fila );
		return $f && $f['evento_id'] === $e['id'] && InscripcionesTable::PARTICIPANTE === $f['papel'] ? $f : null;
	}

	private static function texto( string $t, int $max ): string {
		return mb_substr( trim( sanitize_text_field( $t ) ), 0, $max );
	}
}
