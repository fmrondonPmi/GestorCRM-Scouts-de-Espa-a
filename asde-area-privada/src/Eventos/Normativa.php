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

	public const COMUNICACION = array(
		'auto' => 'Según las noches y los menores (2 o más noches y 10 o más menores)',
		'si'   => 'Sí, comunicarla siempre',
		'no'   => 'No (la comunica quien organiza, o no hay menores)',
	);

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
			'comunicacion'    => isset( self::COMUNICACION[ $raw['comunicacion'] ?? '' ] ) ? (string) $raw['comunicacion'] : 'auto',
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
			$nombre = $p ? $p->nombre_completo() : sprintf( asde_t( 'Nº %s' ), $m['socio'] );

			$datos[ $m['socio'] ] = array(
				'edad'        => $edad,
				'menor'       => null !== $edad && $edad < 18,
				'titulo'      => $titulo,
				'certificado' => $cert,
			);
			if ( null !== $edad && $edad < 18 ) {
				$avisos[] = sprintf( asde_t( '%s no tendrá 18 años el día que empieza: el equipo tiene que ser mayor de edad.' ), $nombre );
			}
			if ( null === $fichas ) {
				continue;
			}
			if ( 'director' === $m['papel'] && 'director' !== $titulo ) {
				$avisos[] = sprintf( asde_t( 'Según su ficha, %s no tiene la titulación de director/a de tiempo libre.' ), $nombre );
			}
			if ( in_array( $m['papel'], array( 'director', 'monitor' ), true ) && '' !== $titulo ) {
				++$titulados;
				$mon_tit += (int) ( 'monitor' === $m['papel'] );
			}
			if ( 'caducado' === $cert ) {
				$avisos[] = sprintf( asde_t( 'El certificado de delitos sexuales de %s tendrá más de 12 meses cuando acabe el evento: hay que renovarlo.' ), $nombre );
			} elseif ( 'sin_fecha' === $cert ) {
				$avisos[] = sprintf( asde_t( 'En la ficha de %s consta el certificado de delitos sexuales, pero sin fecha: no se puede saber si tiene menos de 12 meses.' ), $nombre );
			} elseif ( 'no' === $cert ) {
				$avisos[] = sprintf( asde_t( 'En la ficha de %s no consta el certificado de delitos sexuales.' ), $nombre );
			}
		}

		$directores = (int) ( $papeles['director'] ?? 0 );
		if ( 0 === $directores ) {
			$avisos[] = asde_t( 'Falta el director/a de la actividad.' );
		} elseif ( $directores > 1 ) {
			$avisos[] = asde_t( 'Hay más de un director/a: la actividad tiene un solo director/a responsable.' );
		}
		$monitores = (int) ( $papeles['monitor'] ?? 0 );
		$n         = self::participantes( $e );
		$necesita  = self::monitores_necesarios( $n, $e['normativa']['dependencia12'], $e['normativa']['dependencia3'] );
		if ( $monitores < $necesita['total'] ) {
			$avisos[] = sprintf( asde_t( 'Con %1$d participantes hacen falta %2$d monitores además del director/a, y hay %3$d.' ), $n, $necesita['total'], $monitores );
		}
		if ( null !== $fichas && $monitores > 0 && $mon_tit * 2 < $monitores ) {
			$avisos[] = sprintf( asde_t( 'Al menos la mitad de los monitores tiene que tener la titulación de tiempo libre: hay %1$d titulados de %2$d.' ), $mon_tit, $monitores );
		}
		$practicas = (int) ( $papeles['practicas'] ?? 0 );
		if ( null !== $fichas && $practicas > $titulados ) {
			$avisos[] = sprintf( asde_t( 'Hay %1$d personas en prácticas y %2$d tituladas: en prácticas no puede haber más que tituladas.' ), $practicas, $titulados );
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
				$out[] = array( 'ok', asde_t( 'Presupuesto validado por Tesorería: el precio ya es fijo.' ) );
			} elseif ( null !== $e['validacion_pedida_en'] ) {
				$out[] = array( 'aviso', asde_t( 'Presupuesto pendiente de que Tesorería lo valide.' ) );
			} else {
				$out[] = array( 'aviso', asde_t( 'Falta preparar el presupuesto y pedir a Tesorería que lo valide.' ) );
			}
		} else {
			$out[] = array( 'info', asde_t( 'Sin coste para las familias.' ) );
		}

		if ( empty( $equipo['leido'] ) ) {
			$out[] = array( 'aviso', asde_t( 'No se ha podido leer en el CRM la formación y el certificado del equipo: compruébalos más tarde.' ) );
		}
		if ( ! empty( $equipo['leido'] ) && ! $equipo['avisos'] ) {
			$out[] = array( 'ok', asde_t( 'Equipo dirigente: director/a titulado, ratio cubierta y certificados al día.' ) );
		}
		foreach ( (array) ( $equipo['avisos'] ?? array() ) as $aviso ) {
			$out[] = array( 'aviso', $aviso );
		}

		if ( self::requiere_comunicacion( $e ) ) {
			$limite = self::limite_comunicacion( $e );
			$cerca  = $limite <= wp_date( 'Y-m-d', strtotime( '+10 days' ) );
			$out[]  = array( $cerca ? 'aviso' : 'info', sprintf( asde_t( 'Hay que comunicarla a ASDE Baleares antes del %s (30 días antes del inicio).' ), wp_date( 'd/m/Y', EventosTable::marca( $limite ) ) ) );
		}

		$n = $e['normativa'];
		if ( ! $n['internacional'] ) {
			$out[] = array( 'ok', asde_t( 'Cubierta por el seguro de la federación.' ) );
		} elseif ( '' !== trim( $n['seguro_compania'] ) && '' !== trim( $n['seguro_poliza'] ) ) {
			$out[] = array( 'ok', sprintf( asde_t( 'Actividad internacional con seguro aparte: %1$s, póliza %2$s.' ), $n['seguro_compania'], $n['seguro_poliza'] ) );
		} else {
			$out[] = array( 'aviso', asde_t( 'Actividad internacional: hace falta un seguro aparte (compañía y nº de póliza).' ) );
		}
		if ( $n['riesgo'] ) {
			$out[] = $n['riesgo_titulado']
				? array( 'ok', asde_t( 'Las actividades de riesgo (montaña, barrancos, escalada, buceo, náutica) van con personal titulado en esa actividad.' ) )
				: array( 'aviso', asde_t( 'Hay actividades de riesgo especializado: necesitan personal titulado en esa actividad.' ) );
		}
		if ( $n['agua'] ) {
			$out[] = $n['socorrista']
				? array( 'ok', asde_t( 'Hay piscina o mar y habrá un socorrista.' ) )
				: array( 'aviso', asde_t( 'Hay piscina o mar: tiene que haber un socorrista presente.' ) );
		}
		return $out;
	}

	/** Secciones que pueden convocarse (las educativas, Scouters y Voluntariado). @return string[] */
	public static function secciones_convocables(): array {
		return array_keys( Secciones::all() );
	}
}
