<?php
namespace AsdeBalears\AreaPrivada\Eventos;

use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Auth\Ambito;

defined( 'ABSPATH' ) || exit;

/**
 * Calendario de tareas de un evento (doc 49, fase A1): aplica una plantilla, calcula las fechas límite, las
 * recalcula si cambian las fechas del evento y dice cómo va cada tarea (hecha, vencida, esta semana, más adelante).
 *
 * - **Fecha límite** = inicio o fin del evento más o menos N días (`desfase`). Si cae en sábado o domingo y el grupo
 *   lo tiene activado (por defecto, sí, P-185), se adelanta al viernes anterior. Cada tarea guarda la calculada y la
 *   ajustada; una **fecha propia** gana a las dos y no se toca al recalcular.
 * - **Quién la marca (P-187):** cualquier scouter con acceso al evento. Cambiar fechas, responsables o quitar tareas:
 *   quien lo gestiona.
 */
final class Tareas {

	/** «Esta semana»: hasta dentro de este nº de días. */
	public const SEMANA = 7;

	// ---------------------------------------------------------------------
	// Fechas
	// ---------------------------------------------------------------------

	/** La fecha (Y-m-d) de inicio o de fin del evento, tal como se escribió (hora local). */
	public static function base( array $e, string $ref ): string {
		return substr( 'fin' === $ref ? (string) $e['fin'] : (string) $e['inicio'], 0, 10 );
	}

	/** Suma (o resta) días a una fecha Y-m-d, sin que influya el horario de verano. */
	public static function sumar( string $fecha, int $dias ): string {
		$d = new \DateTimeImmutable( $fecha . ' 12:00:00', new \DateTimeZone( 'UTC' ) );
		return $d->modify( sprintf( '%+d days', $dias ) )->format( 'Y-m-d' );
	}

	/** Si cae en sábado o domingo, el viernes anterior (cuando $viernes). */
	public static function ajustar( string $fecha, bool $viernes ): string {
		if ( ! $viernes ) {
			return $fecha;
		}
		$dia = (int) ( new \DateTimeImmutable( $fecha . ' 12:00:00', new \DateTimeZone( 'UTC' ) ) )->format( 'N' ); // 6 = sábado, 7 = domingo.
		return $dia >= 6 ? self::sumar( $fecha, 5 - $dia ) : $fecha;
	}

	/**
	 * La fecha límite calculada y la ajustada de una tarea de plantilla.
	 *
	 * @return array{fecha_calc:string, fecha_aj:string}
	 */
	public static function calcular( array $e, string $ref, int $desfase, bool $viernes ): array {
		$calc = self::sumar( self::base( $e, $ref ), $desfase );
		return array(
			'fecha_calc' => $calc,
			'fecha_aj'   => self::ajustar( $calc, $viernes ),
		);
	}

	/** Días que faltan (negativo = ya pasó) desde hoy hasta la fecha de la tarea. */
	public static function dias( array $t, ?string $hoy = null ): ?int {
		$f = TareasTable::fecha( $t );
		if ( '' === $f ) {
			return null;
		}
		$hoy = $hoy ?? wp_date( 'Y-m-d' );
		return (int) round( ( strtotime( $f . ' 12:00:00 UTC' ) - strtotime( $hoy . ' 12:00:00 UTC' ) ) / DAY_IN_SECONDS );
	}

	/** hecha | vencida | semana | pronto | sin_fecha */
	public static function estado( array $t, ?string $hoy = null ): string {
		if ( null !== $t['hecha_en'] ) {
			return 'hecha';
		}
		$d = self::dias( $t, $hoy );
		if ( null === $d ) {
			return 'sin_fecha';
		}
		return $d < 0 ? 'vencida' : ( $d <= self::SEMANA ? 'semana' : 'pronto' );
	}

	// ---------------------------------------------------------------------
	// Quién
	// ---------------------------------------------------------------------

	/** Ver el calendario: quien ve el evento. */
	public static function puede_ver( array $e, Ambito $a ): bool {
		return Servicio::ve( $e, $a );
	}

	/** P-187: marcar una tarea como hecha lo hace cualquier scouter con acceso al evento (si está abierto). */
	public static function puede_marcar( array $e, Ambito $a ): bool {
		return current_user_can( Servicio::CAP_GESTION ) && Servicio::ve( $e, $a ) && null === $e['cerrado_en'];
	}

	/** Aplicar la plantilla, cambiar fechas y responsables, añadir y quitar tareas: quien gestiona el evento. */
	public static function puede_editar( array $e, Ambito $a ): bool {
		return Servicio::en_gestion( $e, $a ) && null === $e['cerrado_en'];
	}

	// ---------------------------------------------------------------------
	// Acciones
	// ---------------------------------------------------------------------

	/**
	 * Copia las tareas de una plantilla al evento, con sus fechas límite. Solo si el evento aún no tiene tareas.
	 *
	 * @return int|\WP_Error Cuántas tareas se han creado.
	 */
	public static function aplicar( array $e, string $plantilla, Ambito $a ) {
		if ( ! self::puede_editar( $e, $a ) ) {
			return new \WP_Error( 'asde_tareas', asde_t( 'No puedes cambiar el calendario de este evento.' ) );
		}
		$p = Plantillas::de_grupo()[ $plantilla ] ?? null;
		if ( ! $p || ! $p['tareas'] ) {
			return new \WP_Error( 'asde_tareas', asde_t( 'Esa plantilla no existe o no tiene tareas.' ) );
		}
		if ( TareasTable::contar( $e['id'] ) > 0 ) {
			return new \WP_Error( 'asde_tareas', asde_t( 'Este evento ya tiene tareas. Quita las que no quieras o añade las que falten una a una.' ) );
		}
		$viernes = (bool) Config::de()['calendario_viernes'];
		$n       = 0;
		foreach ( $p['tareas'] as $t ) {
			TareasTable::crear( $e['id'], $n + 1, $t + self::calcular( $e, $t['ref'], $t['desfase'], $viernes ) + array( 'propia' => 0 ) );
			++$n;
		}
		Logger::log(
			'evento_tareas_aplicadas',
			'evento',
			(string) $e['id'],
			array(
				'plantilla' => $plantilla,
				'tareas'    => $n,
			)
		);
		return $n;
	}

	/**
	 * Recalcula las fechas de las tareas de plantilla que no están hechas ni tienen fecha propia (las fechas del
	 * evento han cambiado).
	 *
	 * @return int Cuántas tareas han cambiado de fecha.
	 */
	public static function recalcular( array $e ): int {
		$viernes = (bool) Config::de()['calendario_viernes'];
		$movidas = 0;
		foreach ( TareasTable::de_evento( $e['id'] ) as $t ) {
			if ( $t['propia'] || null !== $t['hecha_en'] || null !== $t['fecha_propia'] ) {
				continue;
			}
			$nuevas = self::calcular( $e, $t['ref'], $t['desfase'], $viernes );
			if ( $nuevas['fecha_calc'] === $t['fecha_calc'] && $nuevas['fecha_aj'] === $t['fecha_aj'] ) {
				continue;
			}
			TareasTable::actualizar( $t['id'], $nuevas );
			++$movidas;
		}
		if ( $movidas ) {
			Logger::log( 'evento_tareas_recalculadas', 'evento', (string) $e['id'], array( 'tareas' => $movidas ) );
		}
		return $movidas;
	}

	/** Marca o desmarca una tarea. @return true|\WP_Error */
	public static function marcar( array $e, array $t, bool $hecha, Ambito $a ) {
		if ( ! self::puede_marcar( $e, $a ) || $t['evento_id'] !== $e['id'] ) {
			return new \WP_Error( 'asde_tareas', asde_t( 'No puedes marcar tareas de este evento.' ) );
		}
		TareasTable::marcar( $t['id'], $hecha );
		Logger::log( $hecha ? 'evento_tarea_hecha' : 'evento_tarea_deshecha', 'evento', (string) $e['id'], array( 'tarea' => $t['id'] ) );
		return true;
	}

	/**
	 * Cambia el responsable (un nº Scouts de España del equipo), la fecha propia ('' = vuelve a la calculada) y la nota.
	 *
	 * @param string[] $equipo Nºs Scouts de España del equipo del evento.
	 * @return true|\WP_Error
	 */
	public static function guardar( array $e, array $t, string $responsable, string $fecha, string $nota, array $equipo, Ambito $a ) {
		if ( ! self::puede_editar( $e, $a ) || $t['evento_id'] !== $e['id'] ) {
			return new \WP_Error( 'asde_tareas', asde_t( 'No puedes cambiar el calendario de este evento.' ) );
		}
		if ( '' !== $responsable && ! in_array( $responsable, $equipo, true ) ) {
			return new \WP_Error( 'asde_tareas', asde_t( 'El responsable tiene que ser del equipo del evento.' ) );
		}
		if ( '' !== $fecha && ! self::fecha_valida( $fecha ) ) {
			return new \WP_Error( 'asde_tareas', asde_t( 'La fecha no es válida.' ) );
		}
		TareasTable::actualizar(
			$t['id'],
			array(
				'responsable'  => $responsable,
				'fecha_propia' => '' === $fecha ? null : $fecha,
				'nota'         => mb_substr( sanitize_text_field( $nota ), 0, 255 ),
			)
		);
		Logger::log( 'evento_tarea_cambiada', 'evento', (string) $e['id'], array( 'tarea' => $t['id'] ) );
		return true;
	}

	/** Añade una tarea propia con su fecha. @return int|\WP_Error Id de la tarea. */
	public static function anadir( array $e, string $titulo, string $fecha, string $detalle, Ambito $a ) {
		if ( ! self::puede_editar( $e, $a ) ) {
			return new \WP_Error( 'asde_tareas', asde_t( 'No puedes cambiar el calendario de este evento.' ) );
		}
		$titulo = mb_substr( trim( sanitize_text_field( $titulo ) ), 0, 200 );
		if ( '' === $titulo || ! self::fecha_valida( $fecha ) ) {
			return new \WP_Error( 'asde_tareas', asde_t( 'Escribe el título de la tarea y su fecha.' ) );
		}
		if ( TareasTable::contar( $e['id'] ) >= TareasTable::MAX ) {
			/* translators: %d: máximo de tareas */
			return new \WP_Error( 'asde_tareas', sprintf( asde_t( 'Como mucho %d tareas por evento.' ), TareasTable::MAX ) );
		}
		$id = TareasTable::crear(
			$e['id'],
			1000 + TareasTable::contar( $e['id'] ),
			array(
				'titulo'       => $titulo,
				'detalle'      => mb_substr( trim( sanitize_text_field( $detalle ) ), 0, 400 ),
				'propia'       => 1,
				'fecha_calc'   => $fecha,
				'fecha_aj'     => $fecha,
				'fecha_propia' => $fecha,
			)
		);
		Logger::log( 'evento_tarea_anadida', 'evento', (string) $e['id'], array( 'tarea' => $id ) );
		return $id;
	}

	/** Quita una tarea. @return true|\WP_Error */
	public static function quitar( array $e, array $t, Ambito $a ) {
		if ( ! self::puede_editar( $e, $a ) || $t['evento_id'] !== $e['id'] ) {
			return new \WP_Error( 'asde_tareas', asde_t( 'No puedes cambiar el calendario de este evento.' ) );
		}
		TareasTable::borrar( $t['id'] );
		Logger::log( 'evento_tarea_quitada', 'evento', (string) $e['id'], array( 'tarea' => $t['id'] ) );
		return true;
	}

	/** Y-m-d de verdad (no 2027-02-30). */
	public static function fecha_valida( string $fecha ): bool {
		$d = \DateTimeImmutable::createFromFormat( '!Y-m-d', $fecha, new \DateTimeZone( 'UTC' ) );
		return $d && $d->format( 'Y-m-d' ) === $fecha;
	}

	// ---------------------------------------------------------------------
	// Lo que le toca a cada uno (inicio)
	// ---------------------------------------------------------------------

	/**
	 * Tareas vencidas y de esta semana de los eventos que este ámbito gestiona (sin consultar el CRM).
	 *
	 * @return array{vencidas:int, semana:int}
	 */
	public static function pendientes( Ambito $a ): array {
		$out = array(
			'vencidas' => 0,
			'semana'   => 0,
		);
		if ( ! current_user_can( Servicio::CAP_GESTION ) ) {
			return $out;
		}
		$vivos = array_filter( Servicio::de_agenda( $a ), static fn( array $e ) => EventosTable::CANCELADO !== $e['estado'] && null === $e['cerrado_en'] && Servicio::en_gestion( $e, $a ) );
		$hoy   = wp_date( 'Y-m-d' );
		foreach ( TareasTable::sin_hacer_de( array_column( $vivos, 'id' ) ) as $t ) {
			$estado = self::estado( $t, $hoy );
			if ( 'vencida' === $estado ) {
				++$out['vencidas'];
			} elseif ( 'semana' === $estado ) {
				++$out['semana'];
			}
		}
		return $out;
	}
}
