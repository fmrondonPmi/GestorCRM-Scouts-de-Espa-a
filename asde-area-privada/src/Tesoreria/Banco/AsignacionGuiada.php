<?php
namespace AsdeBalears\AreaPrivada\Tesoreria\Banco;

use AsdeBalears\AreaPrivada\Domain\Grupo;
use AsdeBalears\AreaPrivada\Domain\Modulos;
use AsdeBalears\AreaPrivada\Eventos\Cobros;
use AsdeBalears\AreaPrivada\Eventos\EventosTable;
use AsdeBalears\AreaPrivada\Tesoreria\FamiliasTable;
use AsdeBalears\AreaPrivada\Tienda\ConciliacionBanco;
use AsdeBalears\AreaPrivada\Tienda\PedidosTable;
use AsdeBalears\AreaPrivada\Tienda\Servicio;

defined( 'ABSPATH' ) || exit;

/**
 * Asignación guiada de los ingresos del banco que traen un código (v0.64.17, petición del usuario: «al procesar un fichero del
 * banco, si un movimiento contiene el código de un evento, de un pedido o de una familia, que aparezca una ventana para confirmar
 * la asignación del pago»).
 *
 * Los tres códigos: familia (F6840001) → pago de cuota; pedido (U6840007) → pedido pagado; evento (E6840012) → pago del evento
 * (con la familia, si también hay código de familia; si no, la elige Tesorería en la ventana). Nada se asigna solo: la ventana
 * lista lo detectado y Tesorería confirma. Todo se recalcula al confirmar (nunca se fía de lo que manda el formulario) y se aplica
 * con las funciones de siempre (Conciliacion::conciliar/ignorar, ConciliacionBanco::asociar), que ya impiden duplicar.
 *
 * Reglas decididas con el usuario: dos códigos distintos en un movimiento (pedido + familia, evento + pedido…) salen SIN marcar,
 * con un desplegable para elegir a qué asignarlo; un importe que no cuadra sale sin marcar y avisado; el tipo de ingreso con el
 * que se anotan los pagos de evento se elige en la ventana.
 */
final class AsignacionGuiada {

	public const COMO_FAMILIA = 'familia';
	public const COMO_PEDIDO  = 'pedido';
	public const COMO_EVENTO  = 'evento';

	/** El código de evento del grupo en un texto («E684-0012», «e 6840012»…): su id, o 0. */
	public static function referencia_evento( string $texto, string $grupo ): int {
		if ( preg_match_all( '/(?<![A-Z0-9])E[\s\-.]?(\d{3})[\s\-.]?(\d{4})(?!\d)/i', $texto, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $x ) {
				if ( $x[1] === $grupo ) {
					return (int) $x[2];
				}
			}
		}
		return 0;
	}

	/** Texto de un movimiento donde puede ir un código. */
	public static function texto( array $mov ): string {
		return implode( ' ', array( $mov['concepto'], $mov['referencia1'], $mov['referencia2'], $mov['documento'] ) );
	}

	/**
	 * Los ingresos por conciliar con algún código y lo que se propone para cada uno.
	 *
	 * @return array<int, array> Por id de movimiento: mov, kinds (como => datos), como (propuesto), marcada, avisos.
	 */
	public static function propuestas(): array {
		$grupo    = Grupo::numero();
		$familias = array();
		foreach ( FamiliasTable::todas() as $f ) {
			$familias[ $f['numero'] ] = $f;
		}
		$pedidos = array();
		if ( ConciliacionBanco::disponible() ) {
			foreach ( PedidosTable::todos() as $p ) {
				if ( null === $p['pago_en'] && null === $p['cancelado_en'] ) {
					$p['a_cobrar'] = Servicio::importe_a_cobrar( $p );
					if ( $p['a_cobrar'] > 0 ) {
						$pedidos[ PedidosTable::numero( $p ) ] = $p;
					}
				}
			}
		}
		$eventos = array();
		if ( Modulos::activo( Modulos::EVENTOS ) ) {
			foreach ( EventosTable::todos() as $e ) {
				$eventos[ (int) $e['id'] ] = $e;
			}
		}
		$pendiente_evento = array();
		$out              = array();
		foreach ( BancoTable::movimientos( array( 'estado' => BancoTable::PENDIENTE, 'tipo' => 'ingresos' ) ) as $m ) {
			if ( $m['importe'] <= 0 ) {
				continue;
			}
			$texto = self::texto( $m );
			$ref_f = Conciliacion::referencia( $texto, $grupo );
			$fam   = $ref_f && isset( $familias[ $ref_f ] ) ? $familias[ $ref_f ] : null;
			$ref_u = ConciliacionBanco::referencia( $texto, $grupo );
			$ped   = $ref_u && isset( $pedidos[ $ref_u ] ) ? $pedidos[ $ref_u ] : null;
			$id_e  = self::referencia_evento( $texto, $grupo );
			$ev    = $id_e && isset( $eventos[ $id_e ] ) ? $eventos[ $id_e ] : null;

			$kinds  = array();
			$avisos = array();
			if ( $ev ) {
				// El código de familia, si lo hay, es de quien paga el evento: no cuenta como otra asignación.
				$pend = null;
				if ( $fam && EventosTable::PUBLICADO === $ev['estado'] ) {
					if ( ! isset( $pendiente_evento[ $ev['id'] ] ) ) {
						$pendiente_evento[ $ev['id'] ] = Cobros::familias( $ev );
					}
					$pend = (float) ( $pendiente_evento[ $ev['id'] ][ $fam['id'] ]['pendiente'] ?? 0 );
				}
				$kinds[ self::COMO_EVENTO ] = array(
					'evento'    => $ev,
					'familia'   => $fam,
					'pendiente' => $pend,
				);
			} elseif ( $fam ) {
				$kinds[ self::COMO_FAMILIA ] = array( 'familia' => $fam );
			}
			if ( $ped ) {
				$kinds[ self::COMO_PEDIDO ] = array(
					'pedido'   => $ped,
					'a_cobrar' => $ped['a_cobrar'],
					'coincide' => abs( $m['importe'] - $ped['a_cobrar'] ) < 0.005,
				);
			}
			if ( ! $kinds ) {
				continue;
			}
			$como    = (string) array_key_first( $kinds );
			$marcada = true;
			if ( count( $kinds ) > 1 ) {
				$marcada  = false;
				$avisos[] = __( 'It has two different codes: choose what to assign it to.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' );
			}
			foreach ( $kinds as $k => $d ) {
				if ( self::COMO_PEDIDO === $k && ! $d['coincide'] ) {
					$marcada  = false;
					$avisos[] = sprintf( /* translators: %s: amount */ __( 'The order is %s and the deposit is different.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), \AsdeBalears\AreaPrivada\Tesoreria\Calculadora::euros( $d['a_cobrar'] ) );
				}
				if ( self::COMO_EVENTO === $k ) {
					if ( EventosTable::PUBLICADO !== $d['evento']['estado'] ) {
						$marcada  = false;
						$avisos[] = __( 'The event is not published: it would only be noted as a reference.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' );
					} elseif ( ! $d['familia'] ) {
						$marcada  = false;
						$avisos[] = __( 'No family code: choose who pays.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' );
					} elseif ( $d['pendiente'] <= 0 ) {
						$marcada  = false;
						$avisos[] = __( 'That family has nothing pending in this event.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' );
					} elseif ( $m['importe'] > $d['pendiente'] + 0.001 ) {
						$marcada  = false;
						$avisos[] = sprintf( /* translators: %s: amount */ __( 'It is more than what is left to pay (%s).', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), \AsdeBalears\AreaPrivada\Tesoreria\Calculadora::euros( $d['pendiente'] ) );
					}
				}
			}
			$out[ (int) $m['id'] ] = array(
				'mov'     => $m,
				'kinds'   => $kinds,
				'como'    => $como,
				'marcada' => $marcada,
				'avisos'  => array_values( array_unique( $avisos ) ),
			);
		}
		return $out;
	}

	/**
	 * Aplica lo que Tesorería ha confirmado.
	 *
	 * @param array<int, array{ok?:string, como?:string, familia?:int}> $filas Lo marcado en la ventana, por id de movimiento.
	 * @param int                                                       $tipo  Tipo de ingreso con el que se anotan los pagos de evento.
	 * @return array{hechos:int, ya:int, errores:string[]}
	 */
	public static function aplicar( array $filas, int $tipo ): array {
		$res       = array( 'hechos' => 0, 'ya' => 0, 'errores' => array() );
		$propuesto = self::propuestas();
		$euros     = static fn( array $m ): string => wp_date( 'd/m/Y', strtotime( $m['fecha'] . ' 12:00' ) ) . ' · ' . \AsdeBalears\AreaPrivada\Tesoreria\Calculadora::euros( (float) $m['importe'] );
		foreach ( $filas as $id => $f ) {
			if ( empty( $f['ok'] ) ) {
				continue;
			}
			$id = (int) $id;
			if ( ! isset( $propuesto[ $id ] ) ) {
				++$res['ya']; // Ya está revisado (o ya no lleva código): no se toca ni se duplica.
				continue;
			}
			$p    = $propuesto[ $id ];
			$como = (string) ( $f['como'] ?? '' );
			if ( ! isset( $p['kinds'][ $como ] ) ) {
				$res['errores'][] = $euros( $p['mov'] ) . ': ' . __( 'Choose what to assign it to.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' );
				continue;
			}
			$d = $p['kinds'][ $como ];
			if ( self::COMO_FAMILIA === $como ) {
				$r = Conciliacion::conciliar( $id, (int) $d['familia']['id'] );
			} elseif ( self::COMO_PEDIDO === $como ) {
				// Quien marca la casilla de un importe distinto lo ha visto en la ventana: es la confirmación que pide asociar().
				$r = ConciliacionBanco::asociar( (int) $d['pedido']['id'], $id, true );
			} else {
				$familia = $d['familia'] ? (int) $d['familia']['id'] : (int) ( $f['familia'] ?? 0 );
				if ( ! $familia ) {
					$res['errores'][] = $euros( $p['mov'] ) . ': ' . __( 'No family code: choose who pays.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' );
					continue;
				}
				$r = Conciliacion::ignorar( $id, $tipo, sprintf( 'Evento %s', EventosTable::numero( $d['evento'] ) ), (int) $d['evento']['id'], $familia ); // Lo que se guarda va en castellano.
			}
			if ( is_wp_error( $r ) ) {
				$res['errores'][] = $euros( $p['mov'] ) . ': ' . $r->get_error_message();
			} else {
				++$res['hechos'];
			}
		}
		return $res;
	}
}
