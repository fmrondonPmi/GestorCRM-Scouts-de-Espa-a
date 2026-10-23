<?php
namespace AsdeBalears\AreaPrivada\Tesoreria;

use AsdeBalears\AreaPrivada\Domain\Persona;
use AsdeBalears\AreaPrivada\Domain\Secciones;

defined( 'ABSPATH' ) || exit;

/**
 * Cálculos de Tesorería (sin efectos secundarios):
 * - Cuota de cada familia en una ronda y su estado de pago.
 * - Coste que paga el grupo a Scouts de Balears.
 * Los miembros se leen del CRM en el momento: solo cuentan los socios con
 * membresía activa, y pagan los de las secciones configuradas (educandos).
 */
final class Calculadora {

	public const PAGADO    = 'pagado';
	public const PARCIAL   = 'parcial';
	public const PENDIENTE = 'pendiente';
	public const ATRASADO  = 'atrasado';
	public const EXENTO    = 'exento';

	public const ETIQUETAS = array(
		self::PAGADO    => 'Pagado',
		self::PARCIAL   => 'Pago parcial',
		self::PENDIENTE => 'Pendiente',
		self::ATRASADO  => 'Con plazos vencidos',
		self::EXENTO    => 'Sin cuota',
	);

	/**
	 * Estado de una familia en una ronda.
	 *
	 * @param array{id:int, numero:string, miembros:string[]} $familia
	 * @param array<string, Persona> $personas Personas del CRM por nº Scouts de España.
	 * @param array{pagado:float, ajustes:float} $totales
	 */
	public static function familia( array $familia, array $personas, array $cfg, array $totales, ?\DateTimeImmutable $hoy = null ): array {
		$hoy      = $hoy ?: new \DateTimeImmutable( 'today', wp_timezone() );
		$pagan    = array();
		$no_pagan = array();
		$fuera    = array();
		foreach ( $familia['miembros'] as $num ) {
			$p = $personas[ $num ] ?? null;
			if ( ! $p || ! $p->tiene_membresia_activa() ) {
				$fuera[] = $num; // Sin membresía activa (o fuera del grupo): no cuenta.
			} elseif ( array_intersect( $p->secciones, $cfg['secciones_pagan'] ) ) {
				$pagan[] = $num;
			} else {
				$no_pagan[] = $num; // Scouters y voluntariado.
			}
		}

		$cuota     = Config::cuota( $cfg, count( $pagan ) );
		$ajustes   = round( (float) ( $totales['ajustes'] ?? 0 ), 2 );
		$pagado    = round( (float) ( $totales['pagado'] ?? 0 ), 2 );
		$a_pagar   = round( max( 0, $cuota['total'] + $ajustes ), 2 );
		$pendiente = round( $a_pagar - $pagado, 2 );

		// Plazos: lo que debería estar pagado a fecha de hoy.
		$plazos    = array();
		$acumulado = 0.0;
		$exigible  = 0.0;
		// Si los plazos suman el 100 % (con el redondeo de los porcentajes: 3 × 33,33), el último es lo que falta hasta
		// a_pagar: redondear cada plazo por separado podía dejar el total en 50,01 € y el último plazo «sin cubrir» a una
		// familia que lo ha pagado todo (doc 59, RC-TES-2).
		$cierra = abs( Config::suma_plazos( $cfg ) - 100 ) < 0.02;
		$ultimo = count( $cfg['plazos'] ) - 1;
		foreach ( array_values( $cfg['plazos'] ) as $i => $pl ) {
			$importe   = $cierra && $i === $ultimo ? round( max( 0, $a_pagar - $acumulado ), 2 ) : round( $a_pagar * (float) $pl['porcentaje'] / 100, 2 );
			$acumulado = round( $acumulado + $importe, 2 );
			$vencido   = $pl['fecha'] < $hoy->format( 'Y-m-d' );
			if ( $vencido ) {
				$exigible = $acumulado;
			}
			$plazos[] = $pl + array(
				'importe'   => $importe,
				'acumulado' => $acumulado,
				'vencido'   => $vencido,
				'cubierto'  => $pagado + 0.001 >= $acumulado,
			);
		}

		if ( $a_pagar <= 0 && $pagado <= 0 ) {
			$estado = self::EXENTO;
		} elseif ( $pendiente <= 0 ) {
			$estado = self::PAGADO;
		} elseif ( $exigible > 0 && $pagado + 0.001 < $exigible ) {
			$estado = self::ATRASADO;
		} elseif ( $pagado > 0 ) {
			$estado = self::PARCIAL;
		} else {
			$estado = self::PENDIENTE;
		}

		return array(
			'pagan'     => $pagan,
			'no_pagan'  => $no_pagan,
			'fuera'     => $fuera,
			'cuota'     => $cuota,
			'ajustes'   => $ajustes,
			'a_pagar'   => $a_pagar,
			'pagado'    => $pagado,
			'pendiente' => $pendiente,
			'plazos'    => $plazos,
			'estado'    => $estado,
		);
	}

	/**
	 * Coste que paga el grupo a su organización federada por sus asociados activos:
	 * cuota asociativa + seguro (tabla de primas o promedio) + otros conceptos.
	 *
	 * @param Persona[] $activas Socios con membresía activa.
	 * @return array{categorias: array<string, array{n:int, prima:float, importe:float}>, total_socios:int, cuota_asociativa:float, seguro_tabla:float, seguro_promedio:float, conceptos: array<int, array{nombre:string, aplica:string, n:int, unidad:float, importe:float}>, total_conceptos:float, total_tabla:float, total_promedio:float}
	 */
	public static function coste_federativo( array $activas, array $cfg, ?\DateTimeImmutable $referencia = null ): array {
		$fed    = $cfg['federacion'];
		$corte  = (int) ( $fed['edad_corte'] ?? 14 );
		$ref    = $referencia ?: new \DateTimeImmutable( 'today', wp_timezone() );
		$conteo = array_fill_keys( array_keys( Config::categorias_seguro( $cfg ) ), 0 );

		foreach ( $activas as $p ) {
			if ( ! $p->tiene_membresia_activa() ) {
				continue;
			}
			if ( $p->es_participante() ) {
				$nac  = $p->fecha_nacimiento ? date_create( $p->fecha_nacimiento, wp_timezone() ) : false;
				$edad = $nac ? (int) date_diff( $nac, $ref )->y : 99;
				++$conteo[ $edad < $corte ? 'menores' : 'mayores' ];
			} else {
				++$conteo['scouters']; // Scouters y voluntariado de apoyo (adultos).
			}
		}

		$categorias = array();
		$seguro     = 0.0;
		foreach ( $conteo as $cat => $n ) {
			$prima              = (float) $fed['primas'][ $cat ];
			$categorias[ $cat ] = array(
				'n'       => $n,
				'prima'   => $prima,
				'importe' => round( $n * $prima, 2 ),
			);
			$seguro            += $n * $prima;
		}

		$total  = array_sum( $conteo );
		$cuota  = round( $total * (float) $fed['cuota_asociativa'], 2 );
		$seguro = round( $seguro, 2 );
		$medio  = round( $total * (float) $fed['seguro_promedio'], 2 );

		// Otros conceptos: por asociado, por educando, por adulto o una vez por grupo.
		$cuantos   = array(
			'asociado' => $total,
			'educando' => $conteo['menores'] + $conteo['mayores'],
			'adulto'   => $conteo['scouters'],
			'grupo'    => 1,
		);
		$conceptos = array();
		foreach ( (array) ( $fed['conceptos'] ?? array() ) as $c ) {
			$n           = $cuantos[ $c['aplica'] ] ?? $total;
			$conceptos[] = array(
				'nombre'  => (string) $c['nombre'],
				'aplica'  => (string) $c['aplica'],
				'n'       => $n,
				'unidad'  => (float) $c['importe'],
				'importe' => round( $n * (float) $c['importe'], 2 ),
			);
		}
		$otros = round( (float) array_sum( array_column( $conceptos, 'importe' ) ), 2 );

		return array(
			'categorias'       => $categorias,
			'total_socios'     => $total,
			'cuota_asociativa' => $cuota,
			'seguro_tabla'     => $seguro,
			'seguro_promedio'  => $medio,
			'conceptos'        => $conceptos,
			'total_conceptos'  => $otros,
			'total_tabla'      => round( $cuota + $seguro + $otros, 2 ),
			'total_promedio'   => round( $cuota + $medio + $otros, 2 ),
		);
	}

	/**
	 * Coste federativo de UNA persona con membresía activa (v0.54, doc 57): su cuota asociativa,
	 * la prima de su categoría y los otros conceptos «por asociado / educando / adulto».
	 * Los conceptos «fijo por grupo» no son de nadie: ver coste_fijo_grupo().
	 * La suma de todas las personas más lo fijo da exactamente el total de coste_federativo().
	 *
	 * @return array{categoria:string, cuota_asociativa:float, seguro:float, conceptos:float, total:float}
	 */
	public static function coste_persona( Persona $p, array $cfg, ?\DateTimeImmutable $referencia = null ): array {
		$fed   = $cfg['federacion'];
		$corte = (int) ( $fed['edad_corte'] ?? 14 );
		$ref   = $referencia ?: new \DateTimeImmutable( 'today', wp_timezone() );

		if ( $p->es_participante() ) {
			$nac       = $p->fecha_nacimiento ? date_create( $p->fecha_nacimiento, wp_timezone() ) : false;
			$edad      = $nac ? (int) date_diff( $nac, $ref )->y : 99;
			$categoria = $edad < $corte ? 'menores' : 'mayores';
			$aplica    = array( 'asociado', 'educando' );
		} else {
			$categoria = 'scouters';
			$aplica    = array( 'asociado', 'adulto' );
		}

		$conceptos = 0.0;
		foreach ( (array) ( $fed['conceptos'] ?? array() ) as $c ) {
			if ( in_array( (string) $c['aplica'], $aplica, true ) ) {
				$conceptos += (float) $c['importe'];
			}
		}
		$cuota  = (float) $fed['cuota_asociativa'];
		$seguro = (float) $fed['primas'][ $categoria ];

		return array(
			'categoria'        => $categoria,
			'cuota_asociativa' => round( $cuota, 2 ),
			'seguro'           => round( $seguro, 2 ),
			'conceptos'        => round( $conceptos, 2 ),
			'total'            => round( $cuota + $seguro + $conceptos, 2 ),
		);
	}

	/** Conceptos del coste federativo que se pagan una vez por grupo (no son de ninguna persona). */
	public static function coste_fijo_grupo( array $cfg ): float {
		$total = 0.0;
		foreach ( (array) ( $cfg['federacion']['conceptos'] ?? array() ) as $c ) {
			if ( 'grupo' === (string) $c['aplica'] ) {
				$total += (float) $c['importe'];
			}
		}
		return round( $total, 2 );
	}

	public static function euros( float $v ): string {
		return number_format_i18n( $v, 2 ) . ' €';
	}
}
