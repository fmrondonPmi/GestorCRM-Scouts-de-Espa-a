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

	/**
	 * hecha | vencida | semana | pronto | sin_fecha. $auto: lo que dice la comprobación automática de la tarea
	 * (true = se cumple, v0.40 fase A2); una tarea que se cumple sola cuenta como hecha.
	 */
	public static function estado( array $t, ?string $hoy = null, ?bool $auto = null ): string {
		if ( null !== $t['hecha_en'] || true === $auto ) {
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
	// Comprobaciones automáticas (fase A2)
	// ---------------------------------------------------------------------

	/** Comprobaciones que se pueden medir con lo que ya sabe la aplicación. «comunicacion» la marca una persona (todavía no hay nada que medir). */
	public const EVALUABLES = array( 'presupuesto', 'equipo', 'titulos', 'autorizaciones', 'menu' );

	/**
	 * ¿Se cumple lo que mide esta comprobación? null = ahora no se puede medir y la marca una persona.
	 *
	 * - presupuesto: el evento tiene coste y ya tiene partidas.
	 * - equipo: un director/a y los monitores que pide la ratio.
	 * - titulos: la comprobación del equipo contra el CRM (formación y certificados) no avisa de nada; hace falta $check (se lee en vivo).
	 * - autorizaciones: hay inscripciones activas y todas tienen la autorización (web o papel, o no hace falta).
	 *
	 * @param array|null $check Normativa::comprobar_equipo, si se ha leído.
	 */
	public static function comprobar( array $e, string $auto, ?array $check = null ): ?bool {
		switch ( $auto ) {
			case 'presupuesto':
				return $e['con_coste'] ? (bool) PartidasTable::previstas( $e['id'] ) : null;
			case 'equipo':
				$filas = InscripcionesTable::participantes( $e['id'] );
				$e['inscritos'] = Inscripciones::recuento( $e, $filas );
				$papeles   = array_count_values( array_column( InscripcionesTable::equipo( $e['id'] ), 'papel' ) );
				$necesita  = Normativa::monitores_necesarios( Normativa::participantes( $e ), $e['normativa']['dependencia12'], $e['normativa']['dependencia3'] );
				return 1 === (int) ( $papeles['director'] ?? 0 ) && (int) ( $papeles['monitor'] ?? 0 ) >= $necesita['total'];
			case 'titulos':
				return null === $check ? null : ( ! empty( $check['leido'] ) && ! $check['avisos'] );
			case 'autorizaciones':
				$activas = array_filter( InscripcionesTable::participantes( $e['id'] ), static fn( array $f ) => in_array( $f['estado'], InscripcionesTable::ACTIVAS, true ) );
				if ( ! $activas ) {
					return false;
				}
				foreach ( $activas as $f ) {
					if ( '' === $f['autorizacion'] ) {
						return false;
					}
				}
				return true;
			case 'menu':
				// v0.41 (doc 49, B2): el menú está hecho si cada día del evento tiene al menos la comida y la cena.
				$dias = \AsdeBalears\AreaPrivada\Menus\MenuEvento::dias( $e );
				if ( ! $dias ) {
					return null;
				}
				$hay = array();
				foreach ( \AsdeBalears\AreaPrivada\Menus\MenuTable::de_evento( $e['id'] ) as $p ) {
					$hay[ $p['dia'] ][ $p['comida'] ] = true;
				}
				foreach ( $dias as $d ) {
					if ( empty( $hay[ $d ]['comida'] ) || empty( $hay[ $d ]['cena'] ) ) {
						return false;
					}
				}
				return true;
		}
		return null;
	}

	/**
	 * Lo que dice la comprobación automática de cada tarea que la lleva y se puede medir.
	 *
	 * @param array[]    $tareas
	 * @param array|null $check Normativa::comprobar_equipo, si se ha leído.
	 * @return array<int, bool> id de la tarea => se cumple
	 */
	public static function automaticas( array $e, array $tareas, ?array $check = null ): array {
		$memo = array();
		$out  = array();
		foreach ( $tareas as $t ) {
			if ( '' === $t['auto'] ) {
				continue;
			}
			if ( ! array_key_exists( $t['auto'], $memo ) ) {
				$memo[ $t['auto'] ] = self::comprobar( $e, $t['auto'], $check );
			}
			if ( null !== $memo[ $t['auto'] ] ) {
				$out[ $t['id'] ] = $memo[ $t['auto'] ];
			}
		}
		return $out;
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

	/**
	 * Marca o desmarca una tarea. Una tarea con comprobación automática que todavía no se cumple solo se puede cerrar a mano
	 * con una observación (por ejemplo, «no procede»).
	 *
	 * @return true|\WP_Error
	 */
	public static function marcar( array $e, array $t, bool $hecha, Ambito $a, string $nota = '' ) {
		if ( ! self::puede_marcar( $e, $a ) || $t['evento_id'] !== $e['id'] ) {
			return new \WP_Error( 'asde_tareas', asde_t( 'No puedes marcar tareas de este evento.' ) );
		}
		if ( $hecha && '' !== $t['auto'] && false === self::comprobar( $e, $t['auto'] ) ) {
			$nota = '' !== trim( $nota ) ? $nota : $t['nota'];
			if ( '' === trim( $nota ) ) {
				return new \WP_Error( 'asde_tareas', asde_t( 'Esta tarea se marca sola cuando se cumple. Si no procede, escribe una observación y ciérrala.' ) );
			}
			TareasTable::actualizar( $t['id'], array( 'nota' => mb_substr( sanitize_text_field( $nota ), 0, 255 ) ) );
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
		$vivos = array();
		foreach ( Servicio::de_agenda( $a ) as $e ) {
			if ( EventosTable::CANCELADO !== $e['estado'] && null === $e['cerrado_en'] && Servicio::en_gestion( $e, $a ) ) {
				$vivos[ $e['id'] ] = $e;
			}
		}
		$hoy = wp_date( 'Y-m-d' );
		foreach ( TareasTable::sin_hacer_de( array_keys( $vivos ) ) as $t ) {
			$estado = self::estado( $t, $hoy );
			if ( ! in_array( $estado, array( 'vencida', 'semana' ), true ) ) {
				continue;
			}
			// Lo que ya se cumple solo (presupuesto, equipo, autorizaciones) no cuenta como pendiente.
			if ( '' !== $t['auto'] && true === self::comprobar( $vivos[ $t['evento_id'] ], $t['auto'] ) ) {
				continue;
			}
			++$out[ 'vencida' === $estado ? 'vencidas' : 'semana' ];
		}
		return $out;
	}
}
