<?php
namespace AsdeBalears\AreaPrivada\Presupuestos;

use AsdeBalears\AreaPrivada\Domain\Persona;
use AsdeBalears\AreaPrivada\Domain\Secciones;
use AsdeBalears\AreaPrivada\Tesoreria\Calculadora;
use AsdeBalears\AreaPrivada\Tesoreria\Config;

defined( 'ABSPATH' ) || exit;

/**
 * De la cuota al dinero de cada sección (v0.54, doc 57 §3). Cálculo puro, sin efectos:
 * se hace siempre en vivo con lo que hay en el CRM y en Tesorería; no se guarda nada.
 *
 * v0.57.1 (corrección del usuario): el coste federativo se paga con la INSCRIPCIÓN Y EL SEGURO
 * que cobra el grupo por cada hijo (con una cuota de 140 €, 20 + 10 = 30 €), y solo lo demás
 * (la anualidad, 110 €) se reparte entre el grupo y la sección. Antes se restaba a cada educando
 * su coste federativo real, que no es lo que se le cobra por él.
 *
 *   cuota_i     = lo que paga la familia (cuota + ajustes) a partes iguales entre sus hijos que pagan
 *   insc_i      = inscripción + seguro por hijo de la ronda; si la cuota no llega (becas), solo cuota_i
 *   neto_i      = cuota_i − insc_i (nunca negativo)
 *   a_seccion_i = neto_i × (100 − % al grupo) / 100;  a_grupo_i = neto_i − a_seccion_i
 *
 * El coste federativo de TODOS los asociados (educandos, adultos, educandos sin cuota y lo fijo por
 * grupo) lo paga el grupo con la inscripción y el seguro cobrados; lo que sobre o falte es del grupo:
 *   grupo.disponible = Σ a_grupo_i + Σ insc_i − coste federativo total
 *   Σ secciones + grupo.disponible = cuotas − coste federativo total.
 */
final class Reparto {

	/**
	 * @param Persona[]             $activas  Socios con membresía activa (todo el grupo).
	 * @param array<int, array>     $filas    Estado de cada familia: [ ['calc' => Calculadora::familia(), …], … ].
	 * @param array<string, Persona> $personas Personas de las familias por nº Scouts de España.
	 * @param array                 $cfg      Config::de( ronda ).
	 * @return array{secciones: array<string, array>, grupo: array, total: array}
	 */
	public static function calcular( array $activas, array $filas, array $personas, array $cfg, ?\DateTimeImmutable $referencia = null ): array {
		$ref = $referencia ?: new \DateTimeImmutable( 'today', wp_timezone() );

		$secciones = array();
		foreach ( Secciones::EDUCATIVAS as $slug ) {
			$secciones[ $slug ] = array(
				'educandos'   => 0,
				'cuota'       => 0.0,
				'cobrado'     => 0.0,
				'inscripcion' => 0.0, // Lo que se queda para el coste federativo.
				'neto'        => 0.0,
				'pct_grupo'   => Config::pct_grupo( $cfg, $slug ),
				'a_grupo'     => 0.0,
				'a_seccion'   => 0.0,
			);
		}

		$aportan   = array(); // nº de socio => true (quienes aportan cuota).
		$costes    = array(); // nº de socio => coste federativo (se calcula una vez por persona: doc 59, RC-PRE-7).
		$no_cubren = 0;
		$insc      = round( (float) $cfg['cuotas']['inscripcion'] + (float) $cfg['cuotas']['seguro'], 2 );
		foreach ( $filas as $fila ) {
			$calc  = $fila['calc'];
			$pagan = array_values( array_filter( (array) $calc['pagan'], static fn( $n ) => isset( $personas[ $n ] ) ) );
			if ( ! $pagan ) {
				continue;
			}
			$cuota_i   = (float) $calc['a_pagar'] / count( $pagan );
			$cobrado_i = (float) $calc['pagado'] / count( $pagan );
			foreach ( $pagan as $num ) {
				$p     = $personas[ $num ];
				$slug  = self::seccion_de( $p, $cfg );
				if ( null === $slug ) {
					continue; // Cuota de alguien sin sección educativa que pague: no es de ninguna sección.
				}
				$cuota = round( $cuota_i, 2 );
				// Con una beca o un descuento, la rebaja sale primero de la anualidad; si la cuota ni siquiera
				// cubre la inscripción y el seguro, a la sección no le llega nada y lo que falta lo pone el grupo.
				$a_fed = round( min( max( 0.0, $cuota ), $insc ), 2 );
				$neto  = round( $cuota - $a_fed, 2 );
				$pct   = $secciones[ $slug ]['pct_grupo'];
				$a_sec = round( $neto * ( 100 - $pct ) / 100, 2 );

				$aportan[ $num ] = true;
				if ( $cuota < $insc ) {
					++$no_cubren;
				}
				$s = &$secciones[ $slug ];
				++$s['educandos'];
				$s['cuota']       += $cuota;
				$s['cobrado']     += round( $cobrado_i, 2 );
				$s['inscripcion'] += $a_fed;
				$s['neto']        += $neto;
				$s['a_seccion']   += $a_sec;
				$s['a_grupo']     += round( $neto - $a_sec, 2 ); // La diferencia de redondeo va al grupo.
				unset( $s );
			}
		}

		foreach ( $secciones as &$s ) {
			foreach ( array( 'cuota', 'cobrado', 'inscripcion', 'neto', 'a_grupo', 'a_seccion' ) as $k ) {
				$s[ $k ] = round( $s[ $k ], 2 );
			}
		}
		unset( $s );

		// Coste federativo de todos, que el grupo paga con la inscripción y el seguro (desglosado).
		$educandos = 0.0;
		$adultos   = 0.0;
		$sin_cuota = 0.0;
		$total_fed = 0.0;
		foreach ( $activas as $p ) {
			if ( ! $p->tiene_membresia_activa() ) {
				continue;
			}
			$coste      = $costes[ $p->num_socio_asde ] ??= Calculadora::coste_persona( $p, $cfg, $ref )['total'];
			$total_fed += $coste;
			if ( isset( $aportan[ $p->num_socio_asde ] ) ) {
				$educandos += $coste;
			} elseif ( $p->es_participante() ) {
				$sin_cuota += $coste;
			} else {
				$adultos += $coste;
			}
		}
		$fijos     = Calculadora::coste_fijo_grupo( $cfg );
		$total_fed = round( $total_fed + $fijos, 2 );

		$a_grupo     = round( array_sum( array_column( $secciones, 'a_grupo' ) ), 2 );
		$cuotas      = round( array_sum( array_column( $secciones, 'cuota' ) ), 2 );
		$inscripcion = round( array_sum( array_column( $secciones, 'inscripcion' ) ), 2 );

		return array(
			'secciones' => $secciones,
			'grupo'     => array(
				'a_grupo'     => $a_grupo,
				'inscripcion' => $inscripcion,
				'educandos'   => round( $educandos, 2 ),
				'adultos'     => round( $adultos, 2 ),
				'sin_cuota'   => round( $sin_cuota, 2 ),
				'fijos'       => $fijos,
				'coste_fed'   => $total_fed,
				'disponible'  => round( $a_grupo + $inscripcion - $total_fed, 2 ),
			),
			'total'     => array(
				'cuota'       => $cuotas,
				'cobrado'     => round( array_sum( array_column( $secciones, 'cobrado' ) ), 2 ),
				'inscripcion' => $inscripcion,
				'coste_fed'   => $total_fed,
				'bolsa'       => round( $cuotas - $inscripcion, 2 ), // Lo que se reparte entre el grupo y las secciones.
				'no_cubren'   => $no_cubren,
			),
		);
	}

	/** Sección educativa de la que cuenta una persona: la primera que paga cuota. */
	public static function seccion_de( Persona $p, array $cfg ): ?string {
		foreach ( Secciones::EDUCATIVAS as $slug ) {
			if ( in_array( $slug, $p->secciones, true ) && in_array( $slug, (array) $cfg['secciones_pagan'], true ) ) {
				return $slug;
			}
		}
		return null;
	}
}
