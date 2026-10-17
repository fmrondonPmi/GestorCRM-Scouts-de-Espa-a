<?php
namespace AsdeBalears\AreaPrivada\Eventos;

use AsdeBalears\AreaPrivada\Domain\Secciones;

defined( 'ABSPATH' ) || exit;

/**
 * El precio de un evento sale de su coste (P-145, doc 43, apartado 4):
 *
 * 1. Cada gasto previsto dice cómo se reparte (REPARTOS). Lo que cuesta el equipo
 *    (scouters y voluntariado) se reparte entre los participantes: el equipo no paga.
 * 2. Los ingresos previstos (subvención, aportación del grupo) se restan de lo común.
 * 3. Coste por participante de cada sección = lo común ÷ participantes previstos de todas
 *    las secciones + lo de su sección ÷ participantes previstos de esa sección + lo que
 *    se paga por persona.
 * 4. Precio = coste × (1 + margen del fondo de emergencia), redondeado al euro hacia
 *    arriba. Lo que sobra (margen y redondeo) es el fondo de emergencia de la actividad.
 *
 * Se calcula con los participantes previstos y, al validarlo Tesorería, queda fijo
 * (P-145 bis): lo que sobre o falte lo asume el grupo.
 */
final class Presupuesto {

	/** Cómo se reparte un gasto. */
	public const REPARTOS = array(
		'total'               => 'Importe total, entre todos los participantes',
		'total_seccion'       => 'Importe total, solo entre los de una sección',
		'participante'        => 'Por persona: cada participante',
		'participante_equipo' => 'Por persona: participantes y equipo',
		'equipo'              => 'Por persona: solo el equipo',
	);

	/** Categorías de gasto de la hoja de presupuestos de ASDE: se sugieren al escribir el concepto de una partida. */
	public const SUGERIDAS = array( 'Desplazamientos', 'Alimentación', 'Material y actividades', 'Gastos de voluntarios', 'Instalaciones', 'Otros' );

	/** Plazas de un autobús grande (la hoja de presupuestos de ASDE usa 55). */
	public const PLAZAS_AUTOBUS = 55;

	/** Autobuses que hacen falta para tanta gente: personas ÷ plazas, hacia arriba. */
	public static function autobuses( int $personas, int $plazas = self::PLAZAS_AUTOBUS ): int {
		return $personas > 0 && $plazas > 0 ? (int) ceil( $personas / $plazas ) : 0;
	}

	/** Repartos que necesitan una sección (en «participante», es opcional). */
	public const CON_SECCION = array( 'total_seccion' );

	/**
	 * @param array[]            $partidas  Las previstas (PartidasTable::previstas).
	 * @param array<string, int> $previstos sección => participantes previstos (solo las convocadas).
	 * @param int                $equipo    Personas del equipo previstas.
	 * @param float              $margen    Porcentaje del fondo de emergencia (0 si está apagado).
	 * @return array{ok:bool, errores:string[], precios:array<string,float>, costes:array<string,float>, previstos:array<string,int>, participantes:int, equipo:int, margen:float, gastos:float, ingresos:float, recaudacion:float, fondo:float}
	 */
	public static function calcular( array $partidas, array $previstos, int $equipo, float $margen ): array {
		$previstos = array_filter( array_map( 'intval', $previstos ), static fn( int $n ) => $n > 0 );
		$total_n   = array_sum( $previstos );
		$errores   = array();
		$comun     = 0.0;
		$ingresos  = 0.0;
		$gastos    = 0.0;
		$por_seccion  = array_fill_keys( array_keys( $previstos ), 0.0 ); // Total que se reparte solo en esa sección.
		$por_persona  = array_fill_keys( array_keys( $previstos ), 0.0 ); // Lo que paga cada participante de esa sección.

		if ( $total_n <= 0 ) {
			$errores[] = asde_t( 'Indica cuántos participantes se esperan en cada sección convocada: el precio se calcula con ellos.' );
		}
		foreach ( $partidas as $p ) {
			$importe = (float) $p['importe'];
			if ( PartidasTable::INGRESO === $p['tipo'] ) {
				$ingresos += $importe;
				$comun    -= $importe;
				continue;
			}
			switch ( $p['reparto'] ) {
				case 'total_seccion':
					if ( ! isset( $previstos[ $p['seccion'] ] ) ) {
						$errores[] = sprintf( asde_t( 'La partida «%1$s» es solo de %2$s, pero no hay participantes previstos de esa sección.' ), $p['concepto'], Secciones::nombre( (string) $p['seccion'] ) );
						break;
					}
					$por_seccion[ $p['seccion'] ] += $importe;
					$gastos                       += $importe;
					break;
				case 'participante':
					if ( '' !== (string) $p['seccion'] ) {
						if ( ! isset( $previstos[ $p['seccion'] ] ) ) {
							$errores[] = sprintf( asde_t( 'La partida «%1$s» es solo de %2$s, pero no hay participantes previstos de esa sección.' ), $p['concepto'], Secciones::nombre( (string) $p['seccion'] ) );
							break;
						}
						$por_persona[ $p['seccion'] ] += $importe;
						$gastos                       += $importe * $previstos[ $p['seccion'] ];
						break;
					}
					foreach ( array_keys( $previstos ) as $s ) {
						$por_persona[ $s ] += $importe;
					}
					$gastos += $importe * $total_n;
					break;
				case 'participante_equipo':
					foreach ( array_keys( $previstos ) as $s ) {
						$por_persona[ $s ] += $importe;
					}
					$comun  += $importe * $equipo;
					$gastos += $importe * ( $total_n + $equipo );
					break;
				case 'equipo':
					$comun  += $importe * $equipo;
					$gastos += $importe * $equipo;
					break;
				default: // total
					$comun  += $importe;
					$gastos += $importe;
			}
		}

		$costes  = array();
		$precios = array();
		$fondo   = 0.0;
		$recauda = 0.0;
		if ( $total_n > 0 ) {
			$comun_cabeza = $comun / $total_n;
			foreach ( $previstos as $s => $n ) {
				$coste        = max( 0.0, $comun_cabeza + $por_seccion[ $s ] / $n + $por_persona[ $s ] );
				$costes[ $s ] = round( $coste, 2 );
				// round() antes de ceil(): 45,0000001 por la coma flotante no debe pasar a 46.
				$precios[ $s ] = (float) ceil( round( $coste * ( 1 + $margen / 100 ), 2 ) );
				$recauda      += $precios[ $s ] * $n;
				$fondo        += ( $precios[ $s ] - $coste ) * $n;
			}
		}
		return array(
			'ok'            => ! $errores,
			'errores'       => $errores,
			'precios'       => $precios,
			'costes'        => $costes,
			'previstos'     => $previstos,
			'participantes' => $total_n,
			'equipo'        => $equipo,
			'margen'        => round( $margen, 2 ),
			'gastos'        => round( $gastos, 2 ),
			'ingresos'      => round( $ingresos, 2 ),
			'recaudacion'   => round( $recauda, 2 ),
			'fondo'         => round( $fondo, 2 ),
		);
	}

	/**
	 * Lo que suma una partida en el presupuesto (v0.36, para el balance): las «por persona» por
	 * cuántos se esperaban, con las mismas reglas que calcular().
	 *
	 * @param array<string, int> $previstos sección => participantes previstos.
	 */
	public static function total_partida( array $p, array $previstos, int $equipo ): float {
		$importe = (float) $p['importe'];
		$total_n = array_sum( array_map( 'intval', $previstos ) );
		if ( PartidasTable::INGRESO === $p['tipo'] ) {
			return round( $importe, 2 );
		}
		switch ( $p['reparto'] ) {
			case 'participante':
				return round( $importe * ( '' !== (string) $p['seccion'] ? (int) ( $previstos[ $p['seccion'] ] ?? 0 ) : $total_n ), 2 );
			case 'participante_equipo':
				return round( $importe * ( $total_n + $equipo ), 2 );
			case 'equipo':
				return round( $importe * $equipo, 2 );
			default: // total, total_seccion
				return round( $importe, 2 );
		}
	}

	/** Participantes previstos por sección convocada (lo que se escribe en el evento). @return array<string, int> */
	public static function previstos( array $e ): array {
		$out = array();
		foreach ( $e['secciones'] as $s ) {
			$out[ $s ] = (int) ( $e['plazas'][ $s ]['previstas'] ?? 0 );
		}
		return $out;
	}

	/** Margen del evento en porcentaje (0 si está apagado). */
	public static function margen( array $e ): float {
		return $e['margen_activo'] ? (float) $e['margen_pct'] : 0.0;
	}

	/** Cálculo con los datos de hoy del evento. */
	public static function del_evento( array $e ): array {
		return self::calcular( PartidasTable::previstas( $e['id'] ), self::previstos( $e ), (int) $e['equipo_previsto'], self::margen( $e ) );
	}
}
