<?php
namespace AsdeBalears\AreaPrivada\Eventos;

use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Auth\Ambito;
use AsdeBalears\AreaPrivada\Usuarios\Usuarios;

defined( 'ABSPATH' ) || exit;

/**
 * Las cuentas de un evento (v0.36, doc 43: apartado 4 y «Tercera fase»): gastos e ingresos
 * reales, reembolsos, anticipos de caja, fondo de emergencia, balance y cierre.
 *
 * - Quien gestiona el evento **propone** gastos con su justificante (P-158); **Tesorería**
 *   (`asde_eventos_economia`) los valida o los rechaza, reembolsa, registra los ingresos reales,
 *   da y liquida los anticipos y **cierra** las cuentas.
 * - Con las cuentas cerradas no se cambia nada; Tesorería puede reabrirlas con un motivo
 *   (v0.36.1, P-162).
 */
final class Cuentas {

	public static function puede_ver( array $e, Ambito $a ): bool {
		return Cobros::puede() || Servicio::en_gestion( $e, $a );
	}

	public static function abiertas( array $e ): bool {
		return Cobros::abiertas( $e );
	}

	/**
	 * A quién se le puede reembolsar o dar un anticipo: quien está en el equipo del evento y tiene
	 * cuenta en la web.
	 *
	 * @return array<int, string> user_id => nº de Scouts de España
	 */
	public static function personas( array $e ): array {
		$out = array();
		foreach ( InscripcionesTable::equipo( $e['id'] ) as $m ) {
			$u = Usuarios::por_socio( (string) $m['socio'] );
			if ( $u ) {
				$out[ (int) $u->ID ] = (string) $m['socio'];
			}
		}
		return $out;
	}

	// ---------------------------------------------------------------------
	// Gastos e ingresos reales
	// ---------------------------------------------------------------------

	/**
	 * Un gasto real con su justificante. De quien gestiona el evento, «por revisar»; de Tesorería,
	 * ya validado.
	 *
	 * @param array<string, mixed>                  $in      Lo escrito en el formulario.
	 * @param array{ruta:string, nombre:string}|null $fichero El justificante subido (ya comprobado con is_uploaded_file()).
	 * @return int|\WP_Error Id del gasto.
	 */
	public static function proponer( array $e, Ambito $a, array $in, ?array $fichero ) {
		$teso = Cobros::puede();
		if ( ! $teso && ! Servicio::en_gestion( $e, $a ) ) {
			return new \WP_Error( 'asde_cuentas', __( 'You cannot record expenses in this event.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
		}
		if ( ! self::abiertas( $e ) ) {
			return self::cerradas();
		}
		$d = self::leer( $e, $in, GastosTable::GASTO, $teso );
		if ( is_wp_error( $d ) ) {
			return $d;
		}
		if ( ! $fichero || '' === (string) $fichero['ruta'] ) {
			return new \WP_Error( 'asde_cuentas', __( 'Attach the receipt for the expense (a photo of the ticket or invoice, or the PDF).', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
		}
		$j = Justificantes::guardar( (string) $fichero['ruta'], (string) $fichero['nombre'] );
		if ( is_wp_error( $j ) ) {
			return $j;
		}
		$id = GastosTable::anadir( $d + $j + self::revision( $teso ) );
		Logger::log(
			'evento_gasto',
			'evento',
			(string) $e['id'],
			array(
				'gasto'  => $id,
				'estado' => $teso ? GastosTable::VALIDADO : GastosTable::PROPUESTO,
			)
		);
		return $id;
	}

	/**
	 * Un ingreso real que no es un cobro a las familias (una subvención recibida): lo apunta
	 * Tesorería, sin justificante.
	 *
	 * @param array<string, mixed> $in
	 * @return int|\WP_Error
	 */
	public static function ingreso( array $e, array $in ) {
		if ( ! Cobros::puede() ) {
			return new \WP_Error( 'asde_cuentas', __( 'Income is recorded by Treasury.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
		}
		if ( ! self::abiertas( $e ) ) {
			return self::cerradas();
		}
		$d = self::leer( $e, $in, GastosTable::INGRESO, true );
		if ( is_wp_error( $d ) ) {
			return $d;
		}
		$id = GastosTable::anadir( $d + self::revision( true ) );
		Logger::log( 'evento_ingreso', 'evento', (string) $e['id'], array( 'gasto' => $id ) );
		return $id;
	}

	/** Quien lo propuso lo retira mientras Tesorería no lo ha revisado. @return true|\WP_Error */
	public static function retirar( array $e, int $id ) {
		$g = self::gasto( $e, $id );
		if ( ! $g || ! GastosTable::por_revisar( $g ) || ( get_current_user_id() !== $g['creado_por'] && ! Cobros::puede() ) ) {
			return new \WP_Error( 'asde_cuentas', __( 'That expense can no longer be withdrawn.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
		}
		if ( ! self::abiertas( $e ) ) {
			return self::cerradas();
		}
		// El motivo se guarda en castellano (doc 59, RC-EVE-3); el justificante se borra si nadie más lo usa (RC-DEC-3).
		GastosTable::actualizar( $id, self::anulacion( self::MOTIVO_RETIRADO ) + Justificantes::SIN_JUSTIFICANTE );
		$borrado = Justificantes::borrar_si_libre( (string) $g['justificante'] );
		Logger::log( 'evento_gasto_retirado', 'evento', (string) $e['id'], array( 'gasto' => $id, 'justificante_borrado' => $borrado ) );
		return true;
	}

	/** @return true|\WP_Error */
	public static function validar( array $e, int $id ) {
		$g = self::gasto( $e, $id );
		if ( ! Cobros::puede() || ! $g || ! GastosTable::por_revisar( $g ) ) {
			return new \WP_Error( 'asde_cuentas', __( 'That expense is not pending review.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
		}
		if ( ! self::abiertas( $e ) ) {
			return self::cerradas();
		}
		GastosTable::actualizar( $id, self::revision( true ) );
		Logger::log( 'evento_gasto_validado', 'evento', (string) $e['id'], array( 'gasto' => $id ) );
		return true;
	}

	/** @return true|\WP_Error */
	public static function rechazar( array $e, int $id, string $motivo ) {
		$g      = self::gasto( $e, $id );
		$motivo = self::texto( $motivo, 255 );
		if ( ! Cobros::puede() || ! $g || ! GastosTable::por_revisar( $g ) ) {
			return new \WP_Error( 'asde_cuentas', __( 'That expense is not pending review.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
		}
		if ( '' === $motivo ) {
			return new \WP_Error( 'asde_cuentas', __( 'Enter the reason for the rejection.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
		}
		if ( ! self::abiertas( $e ) ) {
			return self::cerradas();
		}
		GastosTable::actualizar(
			$id,
			array(
				'estado'       => GastosTable::RECHAZADO,
				'motivo'       => $motivo,
				'revisado_en'  => current_time( 'mysql', true ),
				'revisado_por' => get_current_user_id(),
			) + Justificantes::SIN_JUSTIFICANTE
		);
		$borrado = Justificantes::borrar_si_libre( (string) $g['justificante'] ); // Doc 59, RC-DEC-3.
		Logger::log( 'evento_gasto_rechazado', 'evento', (string) $e['id'], array( 'gasto' => $id, 'justificante_borrado' => $borrado ) );
		return true;
	}

	/** Tesorería devuelve el dinero a quien pagó el gasto de su bolsillo. @return true|\WP_Error */
	public static function reembolsar( array $e, int $id, string $fecha, string $forma ) {
		$g = self::gasto( $e, $id );
		if ( ! Cobros::puede() || ! $g || ! GastosTable::por_reembolsar( $g ) ) {
			return new \WP_Error( 'asde_cuentas', __( 'That expense is not pending reimbursement.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
		}
		if ( ! self::abiertas( $e ) ) {
			return self::cerradas();
		}
		$error = self::fecha( $fecha ) ?? self::forma( $forma );
		if ( $error ) {
			return $error;
		}
		GastosTable::actualizar(
			$id,
			array(
				'reembolsado_en'  => $fecha,
				'reembolso_forma' => $forma,
				'reembolsado_por' => get_current_user_id(),
			)
		);
		Logger::log( 'evento_gasto_reembolsado', 'evento', (string) $e['id'], array( 'gasto' => $id ) );
		return true;
	}

	/** Anula un gasto o un ingreso validado (no uno ya reembolsado), con motivo. @return true|\WP_Error */
	public static function anular( array $e, int $id, string $motivo ) {
		$g      = self::gasto( $e, $id );
		$motivo = self::texto( $motivo, 255 );
		if ( ! Cobros::puede() || ! $g || ! GastosTable::vale( $g ) || null !== $g['reembolsado_en'] ) {
			return new \WP_Error( 'asde_cuentas', __( 'That transaction cannot be cancelled.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
		}
		if ( '' === $motivo ) {
			return new \WP_Error( 'asde_cuentas', __( 'Enter the reason for the cancellation.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
		}
		if ( ! self::abiertas( $e ) ) {
			return self::cerradas();
		}
		GastosTable::actualizar( $id, self::anulacion( $motivo ) );
		Logger::log( 'evento_gasto_anulado', 'evento', (string) $e['id'], array( 'gasto' => $id ) );
		return true;
	}

	// ---------------------------------------------------------------------
	// Anticipos de caja
	// ---------------------------------------------------------------------

	/** @return int|\WP_Error Id del anticipo. */
	public static function anticipo( array $e, int $persona, float $importe, string $fecha, string $forma, string $nota ) {
		if ( ! Cobros::puede() ) {
			return new \WP_Error( 'asde_cuentas', __( 'Advances are given by Treasury.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
		}
		if ( ! self::abiertas( $e ) ) {
			return self::cerradas();
		}
		if ( ! isset( self::personas( $e )[ $persona ] ) ) {
			return new \WP_Error( 'asde_cuentas', __( 'Choose someone from the event team (with an account on the website).', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
		}
		$error = self::importe( $importe ) ?? self::fecha( $fecha ) ?? self::forma( $forma );
		if ( $error ) {
			return $error;
		}
		$id = AnticiposTable::anadir( $e['id'], $persona, $importe, $fecha, $forma, self::texto( $nota, 255 ) );
		Logger::log( 'evento_anticipo', 'evento', (string) $e['id'], array( 'anticipo' => $id ) );
		return $id;
	}

	/**
	 * Liquida un anticipo con lo devuelto. Si los gastos validados pagados con él más lo devuelto no
	 * suman el anticipo, hace falta una nota con el motivo de la diferencia (P-159).
	 *
	 * @return float|\WP_Error La diferencia (positiva: falta dinero; negativa: sobra).
	 */
	public static function liquidar( array $e, int $id, float $devuelto, string $nota ) {
		$an = AnticiposTable::obtener( $id );
		if ( ! Cobros::puede() || ! $an || $an['evento_id'] !== $e['id'] || ! AnticiposTable::abierto( $an ) ) {
			return new \WP_Error( 'asde_cuentas', __( 'That advance is not pending settlement.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
		}
		if ( ! self::abiertas( $e ) ) {
			return self::cerradas();
		}
		if ( $devuelto < 0 ) {
			return new \WP_Error( 'asde_cuentas', __( 'The amount returned cannot be negative.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
		}
		$gastos = array_filter( GastosTable::de_evento( $e['id'] ), static fn( $g ) => $g['anticipo_id'] === $id && GastosTable::PAGO_ANTICIPO === $g['pagado_por'] );
		if ( array_filter( $gastos, array( GastosTable::class, 'por_revisar' ) ) ) {
			return new \WP_Error( 'asde_cuentas', __( 'There are expenses of this advance to review: validate or reject them before settling it.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
		}
		$gastado    = round( array_sum( array_column( array_filter( $gastos, array( GastosTable::class, 'vale' ) ), 'importe' ) ), 2 );
		$diferencia = round( $an['importe'] - $gastado - $devuelto, 2 );
		$nota       = self::texto( $nota, 255 );
		if ( abs( $diferencia ) >= 0.01 && '' === $nota ) {
			return new \WP_Error(
				'asde_cuentas',
				/* translators: 1: expenses, 2: returned, 3: advance */
				sprintf( /* translators: 1: text, 2: text, 3: text */ __( 'The expenses (%1$s) and what was returned (%2$s) do not add up to the advance (%3$s): write a note with the reason for the difference.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), self::euros( $gastado ), self::euros( $devuelto ), self::euros( $an['importe'] ) )
			);
		}
		AnticiposTable::actualizar(
			$id,
			array(
				'estado'           => AnticiposTable::LIQUIDADO,
				'devuelto'         => round( $devuelto, 2 ),
				'liquidacion_nota' => $nota,
				'liquidado_en'     => current_time( 'mysql', true ),
				'liquidado_por'    => get_current_user_id(),
			)
		);
		Logger::log(
			'evento_anticipo_liquidado',
			'evento',
			(string) $e['id'],
			array(
				'anticipo'   => $id,
				'diferencia' => $diferencia,
			)
		);
		return $diferencia;
	}

	/** Anula un anticipo sin gastos (dado por error). @return true|\WP_Error */
	public static function anular_anticipo( array $e, int $id ) {
		$an = AnticiposTable::obtener( $id );
		if ( ! Cobros::puede() || ! $an || $an['evento_id'] !== $e['id'] || ! AnticiposTable::abierto( $an ) ) {
			return new \WP_Error( 'asde_cuentas', __( 'That advance cannot be cancelled.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
		}
		if ( ! self::abiertas( $e ) ) {
			return self::cerradas();
		}
		foreach ( GastosTable::de_evento( $e['id'] ) as $g ) {
			if ( $g['anticipo_id'] === $id && null === $g['anulado_en'] && GastosTable::RECHAZADO !== $g['estado'] ) {
				return new \WP_Error( 'asde_cuentas', __( 'The advance has expenses: settle it.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
			}
		}
		AnticiposTable::actualizar(
			$id,
			array(
				'anulado_en'  => current_time( 'mysql', true ),
				'anulado_por' => get_current_user_id(),
			)
		);
		Logger::log( 'evento_anticipo_anulado', 'evento', (string) $e['id'], array( 'anticipo' => $id ) );
		return true;
	}

	// ---------------------------------------------------------------------
	// Fondo, balance y cierre
	// ---------------------------------------------------------------------

	/**
	 * Fondo de emergencia (P-161): lo que aportan el margen y el redondeo de las inscripciones
	 * activas (precio de su sección menos su coste), lo usado (gastos validados «con cargo al
	 * fondo») y lo que queda.
	 *
	 * @param array[] $filas  Inscripciones del evento.
	 * @param array[] $gastos GastosTable::de_evento().
	 * @return array{recaudado:float, usado:float, disponible:float}
	 */
	public static function fondo( array $e, array $filas, array $gastos ): array {
		$costes    = (array) ( $e['presupuesto']['costes'] ?? array() );
		$recaudado = 0.0;
		foreach ( $filas as $f ) {
			if ( InscripcionesTable::PARTICIPANTE === $f['papel'] && in_array( $f['estado'], InscripcionesTable::ACTIVAS, true ) && isset( $costes[ $f['seccion'] ] ) ) {
				$recaudado += max( 0.0, (float) $f['precio'] - (float) $costes[ $f['seccion'] ] );
			}
		}
		$usado = 0.0;
		foreach ( $gastos as $g ) {
			if ( GastosTable::vale( $g ) && GastosTable::GASTO === $g['tipo'] && $g['con_fondo'] ) {
				$usado += $g['importe'];
			}
		}
		return array(
			'recaudado'  => round( $recaudado, 2 ),
			'usado'      => round( $usado, 2 ),
			'disponible' => round( $recaudado - $usado, 2 ),
		);
	}

	/**
	 * Balance del evento: previsto y real por partida, cobros a las familias, gastos e ingresos
	 * reales, fondo y resultado (lo asume el grupo, P-145 bis). Es también el informe final que se
	 * guarda al cerrar.
	 *
	 * @return array<string, mixed>
	 */
	public static function balance( array $e ): array {
		$filas    = InscripcionesTable::participantes( $e['id'] );
		$movs     = MovimientosTable::de_evento( $e['id'] );
		$gastos   = GastosTable::de_evento( $e['id'] );
		$partidas = PartidasTable::previstas( $e['id'] );

		$cobros = array(
			'pagado'    => 0.0,
			'devuelto'  => 0.0,
			'neto'      => 0.0,
			'pendiente' => 0.0,
			'activas'   => 0,
		);
		foreach ( $filas as $f ) {
			$c                   = Cobros::de( $f, $movs[ $f['id'] ] ?? array() );
			$cobros['pagado']   += $c['pagado'];
			$cobros['devuelto'] += $c['devuelto'];
			if ( in_array( $f['estado'], InscripcionesTable::ACTIVAS, true ) ) {
				$cobros['pendiente'] += $c['pendiente'];
				++$cobros['activas'];
			}
		}
		$cobros['neto'] = $cobros['pagado'] - $cobros['devuelto'];
		$cobros         = array_map( static fn( $v ) => is_float( $v ) ? round( $v, 2 ) : $v, $cobros );

		// Real por partida (y lo que no tiene partida).
		$real = array();
		foreach ( $gastos as $g ) {
			if ( GastosTable::vale( $g ) ) {
				$real[ $g['partida_id'] ][ $g['tipo'] ] = round( ( $real[ $g['partida_id'] ][ $g['tipo'] ] ?? 0 ) + $g['importe'], 2 );
			}
		}
		// Lo previsto, con los participantes y el equipo del presupuesto validado (o los de hoy).
		$previstos = (array) ( $e['presupuesto']['previstos'] ?? Presupuesto::previstos( $e ) );
		$equipo    = (int) ( $e['presupuesto']['equipo'] ?? $e['equipo_previsto'] );
		$lineas    = array();
		foreach ( $partidas as $p ) {
			$lineas[] = array(
				'concepto' => (string) $p['concepto'],
				'tipo'     => (string) $p['tipo'],
				'previsto' => Presupuesto::total_partida( $p, $previstos, $equipo ),
				'real'     => (float) ( $real[ (int) $p['id'] ][ $p['tipo'] ] ?? 0 ),
			);
		}
		foreach ( array( GastosTable::GASTO, GastosTable::INGRESO ) as $tipo ) {
			if ( ! empty( $real[0][ $tipo ] ) ) {
				$lineas[] = array(
					'concepto' => GastosTable::GASTO === $tipo ? __( 'Expenses with no budget item', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) : __( 'Income with no budget item', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
					'tipo'     => $tipo,
					'previsto' => 0.0,
					'real'     => (float) $real[0][ $tipo ],
				);
			}
		}
		$total = static fn( string $tipo, string $col ): float => round( array_sum( array_column( array_filter( $lineas, static fn( $l ) => $tipo === $l['tipo'] ), $col ) ), 2 );

		$reembolsos = array_filter( $gastos, array( GastosTable::class, 'por_reembolsar' ) );
		$anticipos  = array_filter( AnticiposTable::de_evento( $e['id'] ), array( AnticiposTable::class, 'abierto' ) );
		$gastos_r   = $total( GastosTable::GASTO, 'real' );
		$ingresos_r = $total( GastosTable::INGRESO, 'real' );
		return array(
			'evento'      => EventosTable::numero( $e ),
			'nombre'      => (string) $e['nombre'],
			'fecha'       => current_time( 'Y-m-d H:i' ),
			'lineas'      => $lineas,
			'previsto'    => array(
				'gastos'      => $total( GastosTable::GASTO, 'previsto' ),
				'ingresos'    => $total( GastosTable::INGRESO, 'previsto' ),
				'recaudacion' => round( (float) ( $e['presupuesto']['recaudacion'] ?? 0 ), 2 ),
			),
			'gastos'      => $gastos_r,
			'ingresos'    => $ingresos_r,
			'cobros'      => $cobros,
			'fondo'       => self::fondo( $e, $filas, $gastos ),
			'resultado'   => round( $cobros['neto'] + $ingresos_r - $gastos_r, 2 ),
			'por_revisar' => count( array_filter( $gastos, array( GastosTable::class, 'por_revisar' ) ) ),
			'reembolsos'  => array(
				'n'       => count( $reembolsos ),
				'importe' => round( array_sum( array_column( $reembolsos, 'importe' ) ), 2 ),
			),
			'anticipos'   => array(
				'n'       => count( $anticipos ),
				'importe' => round( array_sum( array_column( $anticipos, 'importe' ) ), 2 ),
			),
		);
	}

	/** @return string[] Por qué no se pueden cerrar todavía las cuentas ([] = se pueden cerrar). */
	public static function por_cerrar( array $e, ?array $balance = null ): array {
		if ( ! Cobros::puede() ) {
			return array( __( 'The accounts are closed by Treasury.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
		}
		if ( ! self::abiertas( $e ) ) {
			return array( __( 'The accounts are already closed.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
		}
		$b   = $balance ?? self::balance( $e );
		$out = array();
		if ( EventosTable::CANCELADO !== $e['estado'] && ( EventosTable::PUBLICADO !== $e['estado'] || current_datetime()->getTimestamp() <= EventosTable::marca( $e['fin'] ) ) ) {
			$out[] = __( 'The event has not finished yet.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' );
		}
		if ( $b['por_revisar'] ) {
			/* translators: %d: expenses */
			$out[] = sprintf( /* translators: %d: number */ __( 'There are %d expense(s) to review.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $b['por_revisar'] );
		}
		if ( $b['reembolsos']['n'] ) {
			/* translators: %d: expenses */
			$out[] = sprintf( /* translators: %d: number */ __( 'There are %d reimbursement(s) pending.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $b['reembolsos']['n'] );
		}
		if ( $b['anticipos']['n'] ) {
			/* translators: %d: advances */
			$out[] = sprintf( /* translators: %d: number */ __( 'There are %d advance(s) to settle.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $b['anticipos']['n'] );
		}
		if ( \AsdeBalears\AreaPrivada\Sepa\Remesas::familias_sin_cobrar_evento( $e['id'] ) ) {
			$out[] = __( 'There is a SEPA batch for the event whose collections have not been recorded.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' );
		}
		return $out;
	}

	/**
	 * Cierra las cuentas: guarda el balance como informe final y borra lo cifrado de las
	 * inscripciones (quién recoge, observaciones: P-150). Se pueden reabrir (P-162), pero lo borrado
	 * no vuelve.
	 *
	 * @return array<string, mixed>|\WP_Error El balance guardado.
	 */
	public static function cerrar( array $e ) {
		$balance = self::balance( $e );
		$faltan  = self::por_cerrar( $e, $balance );
		if ( $faltan ) {
			return new \WP_Error( 'asde_cuentas', implode( ' ', $faltan ) );
		}
		if ( ! EventosTable::cerrar( $e['id'], $balance ) ) {
			return new \WP_Error( 'asde_cuentas', __( 'The accounts could not be closed. Try again.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
		}
		$borrados = InscripcionesTable::borrar_datos_evento( $e['id'] );
		Logger::log(
			'evento_cerrado',
			'evento',
			(string) $e['id'],
			array(
				'resultado'      => $balance['resultado'],
				'datos_borrados' => $borrados,
			)
		);
		return $balance;
	}

	/**
	 * Reabre las cuentas cerradas (v0.36.1, P-162, cambiado por el usuario: «una cuenta puede
	 * volver a abrirse»). Lo hace Tesorería, siempre con motivo. Lo que se borró al cerrar (quién
	 * recoge, observaciones) no vuelve; el último informe se conserva hasta el próximo cierre.
	 *
	 * @return true|\WP_Error
	 */
	public static function reabrir( array $e, string $motivo ) {
		if ( ! Cobros::puede() ) {
			return new \WP_Error( 'asde_cuentas', __( 'The accounts are reopened by Treasury.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
		}
		if ( self::abiertas( $e ) ) {
			return new \WP_Error( 'asde_cuentas', __( 'This event\'s accounts are not closed.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
		}
		$motivo = self::texto( $motivo, 255 );
		if ( '' === $motivo ) {
			return new \WP_Error( 'asde_cuentas', __( 'Enter the reason for reopening the accounts.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
		}
		if ( ! EventosTable::reabrir_cuentas( $e['id'] ) ) {
			return new \WP_Error( 'asde_cuentas', __( 'The accounts could not be reopened. Try again.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
		}
		Logger::log(
			'evento_cuentas_reabiertas',
			'evento',
			(string) $e['id'],
			array(
				'motivo'             => $motivo,
				'cerradas_en'        => $e['cerrado_en'],
				'resultado_anterior' => $e['balance']['resultado'] ?? null,
			)
		);
		return true;
	}

	/** @return array<int, string[]> Las filas del informe final en CSV (sin cabecera de columnas: van en cada bloque). */
	public static function filas_csv( array $b ): array {
		$n    = static fn( $v ): string => number_format( (float) $v, 2, ',', '' );
		$rows = array(
			array( __( 'Event', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $b['evento'] . ' · ' . $b['nombre'] ),
			array( __( 'Report date', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $b['fecha'] ),
			array(),
			array( __( 'Item', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), __( 'Type', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), __( 'Planned', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), __( 'Actual', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ),
		);
		foreach ( $b['lineas'] as $l ) {
			$rows[] = array( $l['concepto'], GastosTable::GASTO === $l['tipo'] ? __( 'Expense', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) : __( 'Income', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $n( $l['previsto'] ), $n( $l['real'] ) );
		}
		return array_merge(
			$rows,
			array(
				array(),
				array( __( 'Collected from families', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $n( $b['cobros']['pagado'] ) ),
				array( __( 'Refunded to families', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $n( $b['cobros']['devuelto'] ) ),
				array( __( 'Pending collection', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $n( $b['cobros']['pendiente'] ) ),
				array( __( 'Other income', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $n( $b['ingresos'] ) ),
				array( __( 'Expenses', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $n( $b['gastos'] ) ),
				array( __( 'Emergency fund: collected', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $n( $b['fondo']['recaudado'] ) ),
				array( __( 'Emergency fund: used', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $n( $b['fondo']['usado'] ) ),
				array( __( 'Result (borne by the group)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $n( $b['resultado'] ) ),
			)
		);
	}

	// ---------------------------------------------------------------------

	private static function gasto( array $e, int $id ): ?array {
		$g = GastosTable::obtener( $id );
		return $g && $g['evento_id'] === $e['id'] ? $g : null;
	}

	/**
	 * Lo escrito en el formulario de un gasto o un ingreso, comprobado.
	 *
	 * @param array<string, mixed> $in
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function leer( array $e, array $in, string $tipo, bool $teso ) {
		$fecha    = (string) ( $in['fecha'] ?? '' );
		$importe  = (float) str_replace( ',', '.', (string) ( $in['importe'] ?? '' ) );
		$forma    = sanitize_key( (string) ( $in['forma'] ?? '' ) );
		$concepto = self::texto( (string) ( $in['concepto'] ?? '' ), 120 );
		$error    = self::fecha( $fecha ) ?? self::importe( $importe ) ?? self::forma( $forma );
		if ( $error ) {
			return $error;
		}
		if ( '' === $concepto ) {
			return new \WP_Error( 'asde_cuentas', __( 'Enter the description.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
		}
		$partida = (int) ( $in['partida'] ?? 0 );
		if ( $partida && ! in_array( $partida, array_map( 'intval', array_column( array_filter( PartidasTable::previstas( $e['id'] ), static fn( $p ) => $tipo === $p['tipo'] ), 'id' ) ), true ) ) {
			$partida = 0;
		}
		$d = array(
			'evento_id'  => $e['id'],
			'tipo'       => $tipo,
			'fecha'      => $fecha,
			'partida_id' => $partida,
			'concepto'   => $concepto,
			'proveedor'  => self::texto( (string) ( $in['proveedor'] ?? '' ), 120 ),
			'importe'    => $importe,
			'forma'      => $forma,
		);
		if ( GastosTable::INGRESO === $tipo ) {
			return $d + array( 'pagado_por' => GastosTable::PAGO_GRUPO );
		}
		$pagado = sanitize_key( (string) ( $in['pagado_por'] ?? GastosTable::PAGO_GRUPO ) );
		if ( ! isset( GastosTable::pagadores()[ $pagado ] ) ) {
			return new \WP_Error( 'asde_cuentas', __( 'Choose who paid the expense.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
		}
		$d['pagado_por'] = $pagado;
		$d['con_fondo']  = ! empty( $in['con_fondo'] ) ? 1 : 0;
		if ( GastosTable::PAGO_PERSONA === $pagado ) {
			// Quien gestiona el evento apunta lo que pagó él; Tesorería elige a quién reembolsar.
			$persona = $teso ? (int) ( $in['persona'] ?? 0 ) : get_current_user_id();
			if ( $teso && ! isset( self::personas( $e )[ $persona ] ) ) {
				return new \WP_Error( 'asde_cuentas', __( 'Choose who must be reimbursed for the expense (someone from the team with an account on the website).', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
			}
			$d['persona'] = $persona;
		} elseif ( GastosTable::PAGO_ANTICIPO === $pagado ) {
			$an = AnticiposTable::obtener( (int) ( $in['anticipo'] ?? 0 ) );
			if ( ! $an || $an['evento_id'] !== $e['id'] || ! AnticiposTable::abierto( $an ) ) {
				return new \WP_Error( 'asde_cuentas', __( 'Choose the advance it was paid with (one given and not yet settled).', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
			}
			$d['anticipo_id'] = $an['id'];
		}
		return $d;
	}

	/** @return array<string, mixed> Estado inicial: de Tesorería, validado. */
	private static function revision( bool $teso ): array {
		return $teso
			? array(
				'estado'       => GastosTable::VALIDADO,
				'revisado_en'  => current_time( 'mysql', true ),
				'revisado_por' => get_current_user_id(),
			)
			: array( 'estado' => GastosTable::PROPUESTO );
	}

	/** @return array<string, mixed> */
	/** Motivo que se guarda al retirar un gasto: en castellano; se traduce al mostrarlo (está en Textos::extras()). */
	public const MOTIVO_RETIRADO = 'Retirado';

	private static function anulacion( string $motivo ): array {
		return array(
			'motivo'      => mb_substr( $motivo, 0, 255 ),
			'anulado_en'  => current_time( 'mysql', true ),
			'anulado_por' => get_current_user_id(),
		);
	}

	private static function fecha( string $fecha ): ?\WP_Error {
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $fecha ) || ! date_create( $fecha ) || $fecha > current_time( 'Y-m-d' ) ) {
			return new \WP_Error( 'asde_cuentas', __( 'The date cannot be later than today.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
		}
		return null;
	}

	private static function importe( float $importe ): ?\WP_Error {
		return $importe > 0 && $importe < 100000 ? null : new \WP_Error( 'asde_cuentas', __( 'Enter an amount greater than zero.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
	}

	private static function forma( string $forma ): ?\WP_Error {
		return isset( GastosTable::formas()[ $forma ] ) ? null : new \WP_Error( 'asde_cuentas', __( 'Choose the payment method.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
	}

	private static function cerradas(): \WP_Error {
		return new \WP_Error( 'asde_cuentas', __( 'This event\'s accounts are closed: they can no longer be changed.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
	}

	private static function texto( string $t, int $max ): string {
		return mb_substr( trim( sanitize_text_field( $t ) ), 0, $max );
	}

	private static function euros( float $n ): string {
		return \AsdeBalears\AreaPrivada\Tesoreria\Calculadora::euros( $n );
	}
}
