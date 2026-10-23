<?php
namespace AsdeBalears\AreaPrivada\Tesoreria;

use AsdeBalears\AreaPrivada\Domain\Grupo;
use AsdeBalears\AreaPrivada\Domain\Organizacion;
use AsdeBalears\AreaPrivada\Domain\Secciones;

defined( 'ABSPATH' ) || exit;

/**
 * Configuración de Tesorería por ronda solar (septiembre → agosto), opción
 * `asde_ap_tesoreria`. Todo es configurable desde el área privada.
 *
 * Cuotas del grupo a las familias (2026-09-26):
 *   - Inscripción y seguro: importe por hijo.
 *   - Anualidad: importe total según nº de hijos; a partir del último tramo no
 *     aumenta (tope). Por defecto 1 → 110 €, 2 → 198 €, 3 o más → 242 €.
 *   - Pagan los educandos (Castores a Rovers); scouters y voluntariado no pagan.
 *   - Plazos configurables (nombre, fecha límite y % de la cuota).
 * Coste que paga el grupo a su organización federada (v0.9: configurable):
 *   - Cuota asociativa + seguro, que se calcula con la tabla de primas por
 *     categoría (menores y mayores de la edad de corte, adultos) o con un promedio.
 *   - Otros conceptos: importe por asociado, por educando, por adulto o fijo por grupo.
 *   Cada ronda empieza con los valores de la organización (Domain\Organizacion) y
 *   la Tesorería del grupo los puede ajustar.
 */
final class Config {

	private const OPTION = 'asde_ap_tesoreria';

	/**
	 * Valores por defecto que se GUARDAN en la configuración: en castellano, sin traducir (se traducen al mostrarlos con
	 * asde_t(); están en Textos::extras()). Antes se guardaban en el idioma de quien guardaba la configuración (doc 59,
	 * RC-TES-4).
	 */
	public const PLAZO_DEFECTO = 'Cuota anual';

	/** Proyecto de recaudación de cada sección (v0.54). */
	public const PROYECTOS_DEFECTO = array(
		'castores' => 'Dique',
		'lobatos'  => 'Cacería',
		'scouts'   => 'Aventura',
		'escultas' => 'Empresa',
		'rovers'   => 'Descubierta e Intervención',
	);

	/** Categorías de los gastos de los presupuestos (v0.55; el grupo puede cambiarlas). */
	public const CATEGORIAS_DEFECTO = array( 'Campamento de verano', 'Acampada', 'Excursión', 'Salida de día', 'Material', 'Formación', 'Actividad especial', 'Otros' );

	public static function defaults_ronda( string $ronda ): array {
		$anio = (int) substr( $ronda, 0, 4 );
		return array(
			'cuotas'          => array(
				'inscripcion' => 20.0,
				'seguro'      => 10.0,
				'anualidad'   => array( 110.0, 198.0, 242.0 ),
			),
			'secciones_pagan' => Secciones::EDUCATIVAS,
			'plazos'          => array(
				array(
					'nombre'     => self::PLAZO_DEFECTO,
					'fecha'      => $anio . '-10-31',
					'porcentaje' => 100,
				),
			),
			// Lo que marca la organización federada; el año de las primas es de cada ronda.
			'federacion'      => Organizacion::federacion() + array( 'anio_primas' => $anio ),
			// v0.54 (doc 57): reparto de la cuota entre el grupo y las secciones y proyecto de cada una.
			'presupuesto'     => self::presupuesto_defecto(),
		);
	}

	/** Presupuestos (v0.54): % que va al grupo (por defecto y por sección) y proyecto de recaudación de cada sección. */
	public static function presupuesto_defecto(): array {
		return array(
			'pct_grupo'   => 40.0,
			'pct_seccion' => array(),
			'proyectos'   => self::PROYECTOS_DEFECTO,
			// v0.55: categorías de los gastos de los presupuestos (el grupo puede cambiarlas).
			'categorias'  => self::CATEGORIAS_DEFECTO,
		);
	}

	/** Sanea la parte de presupuestos sobre una base (lo no indicado se queda como estaba). */
	public static function sanear_presupuesto( array $in, array $base ): array {
		$pct = static fn( $v ) => round( max( 0, min( 100, (float) str_replace( ',', '.', (string) $v ) ) ), 2 );
		$out = $base;
		if ( isset( $in['pct_grupo'] ) && '' !== trim( (string) $in['pct_grupo'] ) ) {
			$out['pct_grupo'] = $pct( $in['pct_grupo'] );
		}
		if ( isset( $in['pct_seccion'] ) ) {
			$out['pct_seccion'] = array();
			foreach ( Secciones::EDUCATIVAS as $slug ) {
				$v = $in['pct_seccion'][ $slug ] ?? '';
				if ( '' !== trim( (string) $v ) ) {
					$out['pct_seccion'][ $slug ] = $pct( $v ); // Vacío = el del grupo.
				}
			}
		}
		if ( isset( $in['proyectos'] ) ) {
			$defecto = self::presupuesto_defecto()['proyectos'];
			foreach ( Secciones::EDUCATIVAS as $slug ) {
				$n                     = sanitize_text_field( (string) ( $in['proyectos'][ $slug ] ?? '' ) );
				$out['proyectos'][ $slug ] = '' !== $n ? $n : $defecto[ $slug ];
			}
		}
		if ( isset( $in['categorias'] ) ) {
			$lineas = is_array( $in['categorias'] ) ? $in['categorias'] : preg_split( '/\R/', (string) $in['categorias'] );
			$cats   = array();
			foreach ( (array) $lineas as $l ) {
				$n = mb_substr( sanitize_text_field( (string) $l ), 0, 80 );
				if ( '' !== $n && ! in_array( $n, $cats, true ) ) {
					$cats[] = $n;
				}
			}
			$out['categorias'] = array_slice( $cats, 0, 30 ) ?: self::presupuesto_defecto()['categorias'];
		}
		return $out;
	}

	/** % del dinero de las cuotas (después del coste federativo) que va a las arcas del grupo, para una sección. */
	public static function pct_grupo( array $cfg, string $seccion ): float {
		$p = $cfg['presupuesto'];
		return (float) ( $p['pct_seccion'][ $seccion ] ?? $p['pct_grupo'] );
	}

	/** Categorías del seguro de una ronda (dependen de su edad de corte). */
	public static function categorias_seguro( array $cfg ): array {
		return Organizacion::categorias_seguro( (int) $cfg['federacion']['edad_corte'] );
	}

	/** Ronda en curso: de septiembre a agosto (p. ej. 2026-2027). */
	public static function ronda_actual(): string {
		$hoy  = date_create( 'now', wp_timezone() );
		$anio = (int) $hoy->format( 'Y' );
		$ini  = (int) $hoy->format( 'n' ) >= 9 ? $anio : $anio - 1;
		return $ini . '-' . ( $ini + 1 );
	}

	/** Ronda a la que pertenece una fecha (AAAA-MM-DD): de septiembre a agosto. */
	public static function ronda_de_fecha( string $fecha ): string {
		$anio = (int) substr( $fecha, 0, 4 );
		$ini  = (int) substr( $fecha, 5, 2 ) >= 9 ? $anio : $anio - 1;
		return $ini . '-' . ( $ini + 1 );
	}

	public static function es_ronda( string $ronda ): bool {
		return (bool) preg_match( '/^(\d{4})-(\d{4})$/', $ronda, $m ) && (int) $m[2] === (int) $m[1] + 1;
	}

	/** @return string[] Rondas configuradas (y la actual), de más reciente a más antigua. */
	public static function rondas(): array {
		$rondas = array_keys( self::todo() );
		$rondas[] = self::ronda_actual();
		$rondas = array_values( array_unique( array_filter( $rondas, array( self::class, 'es_ronda' ) ) ) );
		rsort( $rondas );
		return $rondas;
	}

	/** Configuración de una ronda (valores guardados sobre los de por defecto; las listas se sustituyen enteras). */
	public static function de( string $ronda ): array {
		$d = self::defaults_ronda( $ronda );
		$c = self::todo()[ $ronda ] ?? array();

		if ( isset( $c['cuotas'] ) ) {
			$d['cuotas']['inscripcion'] = (float) ( $c['cuotas']['inscripcion'] ?? $d['cuotas']['inscripcion'] );
			$d['cuotas']['seguro']      = (float) ( $c['cuotas']['seguro'] ?? $d['cuotas']['seguro'] );
			if ( ! empty( $c['cuotas']['anualidad'] ) ) {
				$d['cuotas']['anualidad'] = array_map( 'floatval', array_values( (array) $c['cuotas']['anualidad'] ) );
			}
		}
		if ( isset( $c['secciones_pagan'] ) ) {
			$d['secciones_pagan'] = array_values( (array) $c['secciones_pagan'] );
		}
		if ( ! empty( $c['plazos'] ) ) {
			$d['plazos'] = array_values( (array) $c['plazos'] );
		}
		if ( isset( $c['federacion'] ) ) {
			// Lo guardado en la ronda manda; lo que no se guardó (p. ej. los otros
			// conceptos en rondas anteriores a la v0.9) sale de la organización.
			$f               = (array) $c['federacion'];
			$anio            = isset( $f['anio_primas'] ) ? (int) $f['anio_primas'] : (int) $d['federacion']['anio_primas'];
			$d['federacion'] = Organizacion::sanear_federacion( $f + $d['federacion'] ) + array( 'anio_primas' => $anio );
		}
		if ( isset( $c['presupuesto'] ) ) {
			$d['presupuesto'] = self::sanear_presupuesto( (array) $c['presupuesto'], $d['presupuesto'] );
		}
		return $d;
	}

	/** Guarda (saneando) la configuración de una ronda. Se pueden pasar solo algunas partes. */
	public static function guardar( string $ronda, array $datos ): void {
		if ( ! self::es_ronda( $ronda ) ) {
			return;
		}
		$actual = self::de( $ronda );
		$euros  = static fn( $v ) => round( max( 0, (float) str_replace( ',', '.', (string) $v ) ), 2 );

		if ( isset( $datos['cuotas'] ) ) {
			$anualidad = array();
			foreach ( (array) ( $datos['cuotas']['anualidad'] ?? array() ) as $v ) {
				if ( '' !== trim( (string) $v ) ) {
					$anualidad[] = $euros( $v );
				}
			}
			$actual['cuotas'] = array(
				'inscripcion' => $euros( $datos['cuotas']['inscripcion'] ?? 0 ),
				'seguro'      => $euros( $datos['cuotas']['seguro'] ?? 0 ),
				'anualidad'   => $anualidad ?: array( 0.0 ),
			);
		}
		if ( isset( $datos['secciones_pagan'] ) ) {
			$actual['secciones_pagan'] = array_values( array_intersect( array_keys( Secciones::all() ), (array) $datos['secciones_pagan'] ) );
		}
		if ( isset( $datos['plazos'] ) ) {
			$plazos = array();
			foreach ( (array) $datos['plazos'] as $p ) {
				$nombre = sanitize_text_field( (string) ( $p['nombre'] ?? '' ) );
				$fecha  = preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) ( $p['fecha'] ?? '' ) ) ? (string) $p['fecha'] : '';
				$pct    = max( 0, min( 100, (float) str_replace( ',', '.', (string) ( $p['porcentaje'] ?? 0 ) ) ) );
				if ( '' !== $nombre && '' !== $fecha && $pct > 0 ) {
					$plazos[] = array(
						'nombre'     => $nombre,
						'fecha'      => $fecha,
						'porcentaje' => $pct,
					);
				}
			}
			usort( $plazos, static fn( $a, $b ) => strcmp( $a['fecha'], $b['fecha'] ) );
			$actual['plazos'] = $plazos ?: $actual['plazos'];
		}
		if ( isset( $datos['federacion'] ) ) {
			$f                    = (array) $datos['federacion'];
			$f['conceptos']       = array_values( (array) ( $f['conceptos'] ?? array() ) ); // Sin conceptos: se quitan todos.
			$actual['federacion'] = Organizacion::sanear_federacion( $f ) + array(
				'anio_primas' => max( 2000, min( 2100, (int) ( $f['anio_primas'] ?? substr( $ronda, 0, 4 ) ) ) ),
			);
		}

		if ( isset( $datos['presupuesto'] ) ) {
			$actual['presupuesto'] = self::sanear_presupuesto( (array) $datos['presupuesto'], $actual['presupuesto'] );
		}

		$todo           = self::todo();
		$todo[ $ronda ] = $actual;
		$todas          = self::todas_las_de_grupos();
		$todas[ Grupo::numero() ] = $todo;
		update_option( self::OPTION, $todas, false );
	}

	/** Suma de porcentajes de los plazos (debería ser 100). */
	public static function suma_plazos( array $cfg ): float {
		return (float) array_sum( array_column( $cfg['plazos'], 'porcentaje' ) );
	}

	/** Anualidad total para N hijos (a partir del último tramo no aumenta). */
	public static function anualidad( array $cfg, int $hijos ): float {
		$tramos = array_values( $cfg['cuotas']['anualidad'] );
		if ( $hijos <= 0 || ! $tramos ) {
			return 0.0;
		}
		return (float) $tramos[ min( $hijos, count( $tramos ) ) - 1 ];
	}

	/** @return array{inscripcion:float, seguro:float, anualidad:float, total:float} */
	public static function cuota( array $cfg, int $hijos ): array {
		$inscripcion = $hijos * (float) $cfg['cuotas']['inscripcion'];
		$seguro      = $hijos * (float) $cfg['cuotas']['seguro'];
		$anualidad   = self::anualidad( $cfg, $hijos );
		return array(
			'inscripcion' => round( $inscripcion, 2 ),
			'seguro'      => round( $seguro, 2 ),
			'anualidad'   => round( $anualidad, 2 ),
			'total'       => round( $inscripcion + $seguro + $anualidad, 2 ),
		);
	}

	/** Configuración de todas las rondas del grupo en el que se trabaja (cada grupo tiene sus cuotas). */
	private static function todo(): array {
		$v = self::todas_las_de_grupos()[ Grupo::numero() ] ?? array();
		return is_array( $v ) ? $v : array();
	}

	/** @return array<string, array> nº de grupo => [ronda => configuración] */
	private static function todas_las_de_grupos(): array {
		$v = get_option( self::OPTION, array() );
		if ( ! is_array( $v ) ) {
			return array();
		}
		// Formato de la v0.4.0 (rondas en la raíz): se asignan al grupo actual.
		if ( $v && self::es_ronda( (string) array_key_first( $v ) ) ) {
			$v = array( Grupo::numero() => $v );
			update_option( self::OPTION, $v, false );
		}
		return $v;
	}
}
