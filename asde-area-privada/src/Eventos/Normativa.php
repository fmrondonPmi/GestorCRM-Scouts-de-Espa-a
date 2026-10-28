<?php
namespace AsdeBalears\AreaPrivada\Eventos;

use AsdeBalears\AreaPrivada\Domain\Persona;
use AsdeBalears\AreaPrivada\Domain\Secciones;

defined( 'ABSPATH' ) || exit;

/**
 * Lo que pide la normativa de tiempo libre de las Illes Balears y cómo trabaja hoy el
 * grupo (doc 43, apartado 6; Ley 10/2022 y Decreto 23/2018). **Todo avisa, nada
 * bloquea**: la responsabilidad es de la entidad organizadora.
 *
 * - Equipo dirigente: un director/a titulado y monitores, todos mayores de edad y con el
 *   certificado de delitos sexuales de menos de 12 meses el día que acaba el evento; al
 *   menos la mitad de los monitores, titulados; en prácticas, no más que los titulados.
 * - Ratio: además del director/a, 1 monitor/a por cada 10 participantes y otro si sobra
 *   una fracción de 5 o más; + 1 de apoyo por cada 3 con dependencia de grado I o II y 1
 *   por participante con grado III (solo se escribe el número, sin nombres).
 * - Comunicación a ASDE Baleares 30 días antes si hay 2 o más noches y 10 o más menores.
 * - Seguro: el de la federación lo cubre todo salvo las actividades internacionales.
 *
 * La formación y el certificado se leen en vivo del CRM al mirar el equipo (no se
 * guardan): `formaciontiempolibre_c`, `certificadods_c`, `certificadods_fecha_c`.
 */
final class Normativa {

	public const DIAS_COMUNICACION    = 30;
	public const NOCHES_COMUNICACION  = 2;
	public const MENORES_COMUNICACION = 10;
	public const PARTICIPANTES_MONITOR = 10;
	public const MESES_CERTIFICADO    = 12;

	/** Campos de la ficha que se leen para comprobar el equipo. */
	public const CAMPOS_EQUIPO = array( 'formaciontiempolibre_c', 'certificadods_c', 'certificadods_fecha_c' );

	/** Secciones cuyos participantes se cuentan como menores en la previsión (los Rovers suelen ser mayores de edad). */
	public const SECCIONES_MENORES = array( 'castores', 'lobatos', 'scouts', 'escultas' );

	/** Etiquetas traducidas al idioma de WordPress (doc 62). @return array<array-key, mixed> */
	public static function comunicacion(): array {
		return array(
		'auto' => __( 'Depending on nights and minors (2 or more nights and 10 or more minors)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
		'si'   => __( 'Yes, always report it', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
		'no'   => __( 'No (the organiser reports it, or there are no minors)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
	);
	}

	/** Datos de normativa del evento, con sus valores por defecto. */
	public static function datos( array $raw ): array {
		$entero = static fn( $v ): int => min( 999, max( 0, (int) $v ) );
		return array(
			'internacional'   => ! empty( $raw['internacional'] ),
			'seguro_compania' => mb_substr( (string) ( $raw['seguro_compania'] ?? '' ), 0, 100 ),
			'seguro_poliza'   => mb_substr( (string) ( $raw['seguro_poliza'] ?? '' ), 0, 60 ),
			'riesgo'          => ! empty( $raw['riesgo'] ),
			'riesgo_titulado' => ! empty( $raw['riesgo_titulado'] ),
			'agua'            => ! empty( $raw['agua'] ),
			'socorrista'      => ! empty( $raw['socorrista'] ),
			'dependencia12'   => $entero( $raw['dependencia12'] ?? 0 ),
			'dependencia3'    => $entero( $raw['dependencia3'] ?? 0 ),
			'comunicacion'    => isset( self::comunicacion()[ $raw['comunicacion'] ?? '' ] ) ? (string) $raw['comunicacion'] : 'auto',
		);
	}

	/** Noches del evento: los días que hay entre la fecha de inicio y la de fin. */
	public static function noches( array $e ): int {
		$ini = date_create( substr( $e['inicio'], 0, 10 ), wp_timezone() );
		$fin = date_create( substr( $e['fin'], 0, 10 ), wp_timezone() );
		return $ini && $fin && $fin > $ini ? (int) $ini->diff( $fin )->days : 0;
	}

	/**
	 * Participantes para la ratio: las plazas máximas si las hay; si no, los previstos. Con
	 * inscripciones (v0.35, $e['inscritos'] de Inscripciones::recuento), mientras el plazo está
	 * abierto cuenta lo mayor de las dos cosas y, cerrado, los inscritos (pendientes y confirmados).
	 */
	public static function participantes( array $e ): int {
		$previstos = array_sum( Presupuesto::previstos( $e ) );
		$maximas   = $e['plazas_max'] > 0 ? $e['plazas_max'] : array_sum( array_map( static fn( $s ) => (int) ( $e['plazas'][ $s ]['maximas'] ?? 0 ), $e['secciones'] ) );
		$base      = max( $previstos, $maximas );
		$inscritos = $e['inscritos'] ?? null;
		if ( ! $inscritos || ! $inscritos['total'] ) {
			return $base;
		}
		return $inscritos['cerrado'] ? $inscritos['total'] : max( $base, $inscritos['total'] );
	}

	/**
	 * Menores para la comunicación: los previstos de Castores a Escultas y, con inscripciones,
	 * los inscritos que no tienen 18 años el día que empieza (igual que en la ratio).
	 */
	public static function menores_previstos( array $e ): int {
		$n = 0;
		foreach ( Presupuesto::previstos( $e ) as $s => $previstos ) {
			$n += in_array( $s, self::SECCIONES_MENORES, true ) ? $previstos : 0;
		}
		$inscritos = $e['inscritos'] ?? null;
		if ( ! $inscritos || ! $inscritos['total'] ) {
			return $n;
		}
		return $inscritos['cerrado'] ? $inscritos['menores'] : max( $n, $inscritos['menores'] );
	}

	/**
	 * Monitores que hacen falta, además del director/a.
	 *
	 * @return array{base:int, apoyo:int, total:int}
	 */
	public static function monitores_necesarios( int $participantes, int $dependencia12 = 0, int $dependencia3 = 0 ): array {
		$base  = $participantes > 0 ? max( 1, intdiv( $participantes, self::PARTICIPANTES_MONITOR ) + ( $participantes % self::PARTICIPANTES_MONITOR >= 5 ? 1 : 0 ) ) : 0;
		$apoyo = (int) ceil( $dependencia12 / 3 ) + $dependencia3;
		return array(
			'base'  => $base,
			'apoyo' => $apoyo,
			'total' => $base + $apoyo,
		);
	}

	/** ¿Hay que comunicar el evento a ASDE Baleares? */
	public static function requiere_comunicacion( array $e, ?int $menores = null ): bool {
		$modo = $e['normativa']['comunicacion'];
		if ( 'si' === $modo || 'no' === $modo ) {
			return 'si' === $modo;
		}
		return self::noches( $e ) >= self::NOCHES_COMUNICACION && ( $menores ?? self::menores_previstos( $e ) ) >= self::MENORES_COMUNICACION;
	}

	/** Último día para comunicarlo (Y-m-d): 30 días antes del inicio. */
	public static function limite_comunicacion( array $e ): string {
		$d = date_create( substr( $e['inicio'], 0, 10 ), wp_timezone() );
		return $d ? $d->modify( '-' . self::DIAS_COMUNICACION . ' days' )->format( 'Y-m-d' ) : '';
	}

	/**
	 * Qué titulación de tiempo libre tiene una persona según el valor de su ficha: 'director'
	 * (vale también como monitor/a), 'monitor' o ''. Los valores que cuentan se eligen en la
	 * configuración; si no se ha elegido ninguno, se deduce del texto («director», «monitor»).
	 */
	public static function titulacion( string $valor, array $cfg ): string {
		$valores = array_filter( array_map( 'trim', preg_split( '/[\^,|;]+/', $valor ) ?: array() ) );
		if ( ! $valores ) {
			return '';
		}
		$director = $cfg['titulaciones']['director'];
		$monitor  = $cfg['titulaciones']['monitor'];
		if ( $director || $monitor ) {
			if ( array_intersect( $valores, $director ) ) {
				return 'director';
			}
			return array_intersect( $valores, $monitor ) ? 'monitor' : '';
		}
		$texto = mb_strtolower( remove_accents( implode( ' ', $valores ) ) );
		if ( str_contains( $texto, 'direct' ) ) {
			return 'director';
		}
		return str_contains( $texto, 'monit' ) ? 'monitor' : '';
	}

	/**
	 * Estado del certificado de delitos sexuales el día que acaba el evento: 'ok' (de menos
	 * de 12 meses), 'caducado', 'sin_fecha' (consta, pero sin fecha) o 'no' (no consta).
	 */
	public static function certificado( string $tiene, string $fecha, array $e ): string {
		$fecha = substr( trim( $fecha ), 0, 10 );
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $fecha ) ) {
			$fin    = date_create( substr( $e['fin'], 0, 10 ), wp_timezone() );
			$limite = $fin ? $fin->modify( '-' . self::MESES_CERTIFICADO . ' months' )->format( 'Y-m-d' ) : '';
			return $fecha > $limite ? 'ok' : 'caducado';
		}
		$tiene = mb_strtolower( trim( $tiene ) );
		return '' !== $tiene && ! in_array( $tiene, array( '0', 'no', 'false', 'off' ), true ) ? 'sin_fecha' : 'no';
	}

	/** Edad el día de inicio del evento, o null si no se sabe. */
	public static function edad_en( Persona $p, array $e ): ?int {
		$nacimiento = $p->fecha_nacimiento ? date_create( $p->fecha_nacimiento, wp_timezone() ) : false;
		$inicio     = date_create( substr( $e['inicio'], 0, 10 ), wp_timezone() );
		return $nacimiento && $inicio ? (int) $nacimiento->diff( $inicio )->y : null;
	}

	/**
	 * Comprueba el equipo dirigente.
	 *
	 * @param array[]                               $equipo   Filas del equipo (InscripcionesTable::equipo).
	 * @param array<string, Persona>                $personas nº => ficha (instantánea).
	 * @param array<string, array<string, string>>|null $fichas nº => formación y certificado (en vivo); null si el CRM no ha respondido.
	 * @return array{personas: array<string, array{edad:?int, menor:bool, titulo:string, certificado:string}>, avisos: string[], ratio: array{participantes:int, necesarios:int, apoyo:int, monitores:int}, leido:bool}
	 */
	public static function comprobar_equipo( array $e, array $equipo, array $personas, ?array $fichas, array $cfg ): array {
		$avisos    = array();
		$datos     = array();
		$papeles   = array_count_values( array_column( $equipo, 'papel' ) );
		$titulados = 0;
		$mon_tit   = 0;
		foreach ( $equipo as $m ) {
			$p      = $personas[ $m['socio'] ] ?? null;
			$edad   = $p ? self::edad_en( $p, $e ) : null;
			$ficha  = $fichas[ $m['socio'] ] ?? null;
			$titulo = null !== $ficha ? self::titulacion( (string) ( $ficha['formaciontiempolibre_c'] ?? '' ), $cfg ) : '';
			$cert   = null !== $ficha ? self::certificado( (string) ( $ficha['certificadods_c'] ?? '' ), (string) ( $ficha['certificadods_fecha_c'] ?? '' ), $e ) : '';
			$nombre = $p ? $p->nombre_completo() : sprintf( /* translators: %s: text */ __( 'No. %s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $m['socio'] );

			$datos[ $m['socio'] ] = array(
				'edad'        => $edad,
				'menor'       => null !== $edad && $edad < 18,
				'titulo'      => $titulo,
				'certificado' => $cert,
			);
			if ( null !== $edad && $edad < 18 ) {
				$avisos[] = sprintf( /* translators: %s: text */ __( '%s will not be 18 on the day it starts: the team must be of legal age.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $nombre );
			}
			if ( null === $fichas ) {
				continue;
			}
			if ( 'director' === $m['papel'] && 'director' !== $titulo ) {
				$avisos[] = sprintf( /* translators: %s: text */ __( 'According to their record, %s does not have the leisure-time director qualification.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $nombre );
			}
			if ( in_array( $m['papel'], array( 'director', 'monitor' ), true ) && '' !== $titulo ) {
				++$titulados;
				$mon_tit += (int) ( 'monitor' === $m['papel'] );
			}
			if ( 'caducado' === $cert ) {
				$avisos[] = sprintf( /* translators: %s: text */ __( '%s\'s sexual offences certificate will be more than 12 months old when the event ends: it must be renewed.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $nombre );
			} elseif ( 'sin_fecha' === $cert ) {
				$avisos[] = sprintf( /* translators: %s: text */ __( 'On %s\'s record the sexual offences certificate is shown but without a date: it cannot be known whether it is less than 12 months old.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $nombre );
			} elseif ( 'no' === $cert ) {
				$avisos[] = sprintf( /* translators: %s: text */ __( 'On %s\'s record there is no sexual offences certificate.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $nombre );
			}
		}

		$directores = (int) ( $papeles['director'] ?? 0 );
		if ( 0 === $directores ) {
			$avisos[] = __( 'The activity director is missing.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' );
		} elseif ( $directores > 1 ) {
			$avisos[] = __( 'There is more than one director: the activity has a single responsible director.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' );
		}
		$monitores = (int) ( $papeles['monitor'] ?? 0 );
		$n         = self::participantes( $e );
		$necesita  = self::monitores_necesarios( $n, $e['normativa']['dependencia12'], $e['normativa']['dependencia3'] );
		if ( $monitores < $necesita['total'] ) {
			$avisos[] = sprintf( /* translators: 1: number, 2: number, 3: number */ __( 'With %1$d participants, %2$d monitors are needed besides the director, and there are %3$d.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $n, $necesita['total'], $monitores );
		}
		if ( null !== $fichas && $monitores > 0 && $mon_tit * 2 < $monitores ) {
			$avisos[] = sprintf( /* translators: 1: number, 2: number */ __( 'At least half of the monitors must hold the leisure-time qualification: there are %1$d qualified out of %2$d.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $mon_tit, $monitores );
		}
		$practicas = (int) ( $papeles['practicas'] ?? 0 );
		if ( null !== $fichas && $practicas > $titulados ) {
			$avisos[] = sprintf( /* translators: 1: number, 2: number */ __( 'There are %1$d people in training and %2$d qualified: there cannot be more in training than qualified.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $practicas, $titulados );
		}
		return array(
			'personas' => $datos,
			'avisos'   => $avisos,
			'ratio'    => array(
				'participantes' => $n,
				'necesarios'    => $necesita['total'],
				'apoyo'         => $necesita['apoyo'],
				'monitores'     => $monitores,
			),
			'leido'    => null !== $fichas,
		);
	}

	/**
	 * Lista de comprobación del evento (resumen): cada punto con su estado.
	 *
	 * @param array $equipo Resultado de comprobar_equipo.
	 * @return array<int, array{0:string, 1:string}> [estado ('ok', 'aviso' o 'info'), texto]
	 */
	public static function lista( array $e, array $equipo ): array {
		$out = array();
		if ( $e['con_coste'] ) {
			if ( null !== $e['validado_en'] ) {
				$out[] = array( 'ok', __( 'Budget validated by Treasury: the price is now fixed.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
			} elseif ( null !== $e['validacion_pedida_en'] ) {
				$out[] = array( 'aviso', __( 'Budget pending validation by Treasury.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
			} else {
				$out[] = array( 'aviso', __( 'The budget still has to be prepared and Treasury asked to validate it.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
			}
		} else {
			$out[] = array( 'info', __( 'No cost for families.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
		}

		if ( empty( $equipo['leido'] ) ) {
			$out[] = array( 'aviso', __( 'The team\'s training and certificate could not be read in the CRM: check them later.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
		}
		if ( ! empty( $equipo['leido'] ) && ! $equipo['avisos'] ) {
			$out[] = array( 'ok', __( 'Leadership team: qualified director, ratio covered and certificates up to date.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
		}
		foreach ( (array) ( $equipo['avisos'] ?? array() ) as $aviso ) {
			$out[] = array( 'aviso', $aviso );
		}

		if ( self::requiere_comunicacion( $e ) ) {
			$limite = self::limite_comunicacion( $e );
			$cerca  = $limite <= wp_date( 'Y-m-d', strtotime( '+10 days' ) );
			$out[]  = array( $cerca ? 'aviso' : 'info', sprintf( /* translators: %s: text */ __( 'It must be reported to ASDE Baleares before %s (30 days before the start).', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), wp_date( 'd/m/Y', EventosTable::marca( $limite ) ) ) );
		}

		$n = $e['normativa'];
		if ( ! $n['internacional'] ) {
			$out[] = array( 'ok', __( 'Covered by the federation\'s insurance.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
		} elseif ( '' !== trim( $n['seguro_compania'] ) && '' !== trim( $n['seguro_poliza'] ) ) {
			$out[] = array( 'ok', sprintf( /* translators: 1: text, 2: text */ __( 'International activity with separate insurance: %1$s, policy %2$s.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $n['seguro_compania'], $n['seguro_poliza'] ) );
		} else {
			$out[] = array( 'aviso', __( 'International activity: separate insurance is required (company and policy no.).', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
		}
		if ( $n['riesgo'] ) {
			$out[] = $n['riesgo_titulado']
				? array( 'ok', __( 'Risk activities (mountain, canyoning, climbing, diving, sailing) go with staff qualified in that activity.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) )
				: array( 'aviso', __( 'There are specialised-risk activities: they need staff qualified in that activity.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
		}
		if ( $n['agua'] ) {
			$out[] = $n['socorrista']
				? array( 'ok', __( 'There is a swimming pool or sea and there will be a lifeguard.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) )
				: array( 'aviso', __( 'There is a swimming pool or sea: a lifeguard must be present.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
		}
		return $out;
	}

	/** Secciones que pueden convocarse (las educativas, Scouters y Voluntariado). @return string[] */
	public static function secciones_convocables(): array {
		return array_keys( Secciones::all() );
	}
}
