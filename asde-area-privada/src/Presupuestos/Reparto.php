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
 *   cuota_i     = lo que paga la familia (cuota + ajustes) a partes iguales entre sus hijos que pagan
 *   coste_fed_i = cuota asociativa + prima de seguro + otros conceptos de esa persona
 *   neto_i      = cuota_i − coste_fed_i
 *   a_seccion_i = neto_i × (100 − % al grupo) / 100;  a_grupo_i = neto_i − a_seccion_i
 *
 * El coste federativo de quien no aporta cuota (adultos, educandos sin familia que pague) y los
 * conceptos «fijo por grupo» se restan de la bolsa del grupo. Así, siempre:
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
				'educandos' => 0,
				'cuota'     => 0.0,
				'cobrado'   => 0.0,
				'coste_fed' => 0.0,
				'neto'      => 0.0,
				'pct_grupo' => Config::pct_grupo( $cfg, $slug ),
				'a_grupo'   => 0.0,
				'a_seccion' => 0.0,
			);
		}

		$aportan   = array(); // nº de socio => true (quienes aportan cuota).
		$negativos = 0;
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
				$coste = Calculadora::coste_persona( $p, $cfg, $ref )['total'];
				$neto  = round( round( $cuota_i, 2 ) - $coste, 2 );
				$pct   = $secciones[ $slug ]['pct_grupo'];
				$a_sec = round( $neto * ( 100 - $pct ) / 100, 2 );

				$aportan[ $num ] = true;
				if ( $neto < 0 ) {
					++$negativos;
				}
				$s = &$secciones[ $slug ];
				++$s['educandos'];
				$s['cuota']     += round( $cuota_i, 2 );
				$s['cobrado']   += round( $cobrado_i, 2 );
				$s['coste_fed'] += $coste;
				$s['neto']      += $neto;
				$s['a_seccion'] += $a_sec;
				$s['a_grupo']   += round( $neto - $a_sec, 2 ); // La diferencia de redondeo va al grupo.
				unset( $s );
			}
		}

		foreach ( $secciones as &$s ) {
			foreach ( array( 'cuota', 'cobrado', 'coste_fed', 'neto', 'a_grupo', 'a_seccion' ) as $k ) {
				$s[ $k ] = round( $s[ $k ], 2 );
			}
		}
		unset( $s );

		// Costes federativos sin dueño: quien no aporta cuota y lo fijo por grupo.
		$adultos  = 0.0;
		$sin_cuota = 0.0;
		$total_fed = 0.0;
		foreach ( $activas as $p ) {
			if ( ! $p->tiene_membresia_activa() ) {
				continue;
			}
			$coste      = Calculadora::coste_persona( $p, $cfg, $ref )['total'];
			$total_fed += $coste;
			if ( isset( $aportan[ $p->num_socio_asde ] ) ) {
				continue;
			}
			if ( $p->es_participante() ) {
				$sin_cuota += $coste;
			} else {
				$adultos += $coste;
			}
		}
		$fijos     = Calculadora::coste_fijo_grupo( $cfg );
		$total_fed = round( $total_fed + $fijos, 2 );
		$adultos   = round( $adultos, 2 );
		$sin_cuota = round( $sin_cuota, 2 );
		$sin_dueno = round( $adultos + $sin_cuota + $fijos, 2 );

		$a_grupo = round( array_sum( array_column( $secciones, 'a_grupo' ) ), 2 );
		$cuotas  = round( array_sum( array_column( $secciones, 'cuota' ) ), 2 );

		return array(
			'secciones' => $secciones,
			'grupo'     => array(
				'a_grupo'    => $a_grupo,
				'adultos'    => $adultos,
				'sin_cuota'  => $sin_cuota,
				'fijos'      => $fijos,
				'sin_dueno'  => $sin_dueno,
				'disponible' => round( $a_grupo - $sin_dueno, 2 ),
			),
			'total'     => array(
				'cuota'     => $cuotas,
				'cobrado'   => round( array_sum( array_column( $secciones, 'cobrado' ) ), 2 ),
				'coste_fed' => $total_fed,
				'bolsa'     => round( $cuotas - $total_fed, 2 ),
				'negativos' => $negativos,
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
