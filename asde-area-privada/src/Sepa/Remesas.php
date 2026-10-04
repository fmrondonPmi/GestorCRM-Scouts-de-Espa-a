<?php
namespace AsdeBalears\AreaPrivada\Sepa;

use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Crm\PersonaRepository;
use AsdeBalears\AreaPrivada\Crm\Crypto;
use AsdeBalears\AreaPrivada\Domain\Grupo;
use AsdeBalears\AreaPrivada\Tesoreria\Calculadora;
use AsdeBalears\AreaPrivada\Tesoreria\Config as TesoreriaConfig;
use AsdeBalears\AreaPrivada\Tesoreria\FamiliasTable;
use AsdeBalears\AreaPrivada\Tesoreria\MovimientosTable;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- tablas propias del plugin ({prefix}asde_*): no hay función de WordPress para ellas y los datos cambian en cada petición, no se cachean.

/**
 * Remesas de adeudos SEPA ({prefix}asde_sepa_remesas): el fichero que Tesorería
 * envía al banco para cobrar la cuota a las familias que han firmado su
 * autorización.
 *
 * Ciclo de una remesa:
 *   generada → enviada → (cobros registrados)
 *   generada → anulada
 * - «Generada»: se ha creado y descargado el fichero; aún no se ha enviado al
 *   banco. Se puede volver a descargar o anular.
 * - «Enviada»: Tesorería confirma que la ha subido al banco. Las autorizaciones
 *   usadas por primera vez pasan de FRST a RCUR.
 * - Cobros: cuando el banco ha cobrado, Tesorería registra los pagos (con la
 *   forma «Domiciliación SEPA») y marca los que se han devuelto.
 *
 * Los adeudos (titular, IBAN…) van en un JSON cifrado; se vacía 14 meses después
 * de la fecha de cobro. Tampoco se guardan si la remesa se anula.
 */
final class Remesas {

	public const GENERADA = 'generada';
	public const ENVIADA  = 'enviada';
	public const ANULADA  = 'anulada';

	public const ESTADOS = array(
		self::GENERADA => 'Generada',
		self::ENVIADA  => 'Enviada al banco',
		self::ANULADA  => 'Anulada',
	);

	/** Estado de cada adeudo dentro de la remesa. */
	public const PENDIENTE = 'pendiente';
	public const COBRADO   = 'cobrado';
	public const DEVUELTO  = 'devuelto';

	/** Los bancos piden la remesa con antelación a la fecha de cobro (los adeudos Core, como mínimo 1 día hábil en el circuito interbancario y bastante más en la práctica). */
	public const DIAS_HABILES_MINIMOS = 3;

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'asde_sepa_remesas';
	}

	public static function install_table(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$t       = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE $t (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				grupo varchar(3) NOT NULL,
				numero varchar(20) NOT NULL,
				ronda varchar(9) NOT NULL,
				plazo varchar(60) NOT NULL DEFAULT '',
				fecha_cobro date NOT NULL,
				concepto varchar(140) NOT NULL DEFAULT '',
				estado varchar(10) NOT NULL DEFAULT 'generada',
				adeudos int(11) NOT NULL DEFAULT 0,
				total decimal(10,2) NOT NULL DEFAULT 0,
				msg_id varchar(35) NOT NULL DEFAULT '',
				lineas_enc longtext NULL,
				creada datetime NOT NULL,
				creada_por bigint(20) unsigned NOT NULL DEFAULT 0,
				avisada_en datetime NULL,
				enviada_en datetime NULL,
				enviada_por bigint(20) unsigned NOT NULL DEFAULT 0,
				cobros_en datetime NULL,
				cobros_por bigint(20) unsigned NOT NULL DEFAULT 0,
				anulada_en datetime NULL,
				anulada_por bigint(20) unsigned NOT NULL DEFAULT 0,
				descargas int(11) NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				UNIQUE KEY numero (numero),
				KEY grupo_estado (grupo, estado)
			) $charset;"
		);
	}

	// ---------------------------------------------------------------------
	// Fechas
	// ---------------------------------------------------------------------

	/** Primera fecha de cobro que se admite: dentro de N días hábiles (sin contar festivos). */
	public static function fecha_minima( ?\DateTimeImmutable $hoy = null ): string {
		$d = $hoy ?: new \DateTimeImmutable( 'today', wp_timezone() );
		for ( $n = 0; $n < self::DIAS_HABILES_MINIMOS; ) {
			$d = $d->modify( '+1 day' );
			if ( (int) $d->format( 'N' ) < 6 ) {
				++$n;
			}
		}
		return $d->format( 'Y-m-d' );
	}

	/** @return string '' si la fecha vale para cobrar; si no, el motivo. */
	public static function error_fecha( string $fecha, ?\DateTimeImmutable $hoy = null ): string {
		$d = \DateTimeImmutable::createFromFormat( '!Y-m-d', $fecha, wp_timezone() );
		if ( ! $d || $d->format( 'Y-m-d' ) !== $fecha ) {
			return asde_t( 'Elige la fecha de cobro.' );
		}
		if ( (int) $d->format( 'N' ) >= 6 ) {
			return asde_t( 'La fecha de cobro tiene que ser un día laborable (de lunes a viernes).' );
		}
		if ( $fecha < self::fecha_minima( $hoy ) ) {
			return sprintf( asde_t( 'La fecha de cobro tiene que ser, como pronto, el %s (%d días hábiles desde hoy): los bancos necesitan ese margen.' ), wp_date( 'd/m/Y', (int) strtotime( self::fecha_minima( $hoy ) ) ), self::DIAS_HABILES_MINIMOS );
		}
		return '';
	}

	// ---------------------------------------------------------------------
	// Preparar (sin guardar nada)
	// ---------------------------------------------------------------------

	/**
	 * Qué se cobraría: por cada familia con autorización vigente, lo que le falta
	 * por pagar hasta ese plazo (o todo lo pendiente).
	 *
	 * @param string $plazo '' = todo lo pendiente de la ronda; si no, el nº del plazo (0, 1…) de la configuración de cuotas.
	 * @return array{lineas:array[], omitidas:array[], total:int, plazo_nombre:string}
	 */
	public static function preparar( PersonaRepository $repo, string $ronda, string $plazo ): array {
		$cfg      = TesoreriaConfig::de( $ronda );
		$familias = FamiliasTable::todas();
		$nums     = array();
		foreach ( $familias as $f ) {
			$nums = array_merge( $nums, $f['miembros'] );
		}
		$personas = $repo->obtener_varias( $nums );
		$totales  = MovimientosTable::totales( $ronda );
		$mandatos = Mandatos::activos();
		$en_curso = self::familias_sin_cobrar( $ronda );
		$plazos   = array_values( $cfg['plazos'] );
		$nombre   = '' !== $plazo && isset( $plazos[ (int) $plazo ] ) ? (string) $plazos[ (int) $plazo ]['nombre'] : asde_t( 'Todo lo pendiente' );

		$lineas   = array();
		$omitidas = array();
		$total    = 0;
		foreach ( $familias as $id => $f ) {
			$calc = Calculadora::familia( $f, $personas, $cfg, $totales[ $id ] ?? array() );
			if ( Calculadora::EXENTO === $calc['estado'] || $calc['pendiente'] <= 0 ) {
				continue;
			}
			$objetivo = '' !== $plazo && isset( $calc['plazos'][ (int) $plazo ] ) ? (float) $calc['plazos'][ (int) $plazo ]['acumulado'] : (float) $calc['a_pagar'];
			$importe  = round( min( min( $objetivo, (float) $calc['a_pagar'] ) - (float) $calc['pagado'], (float) $calc['pendiente'] ), 2 );
			$centimos = (int) round( $importe * 100 );
			if ( $centimos <= 0 ) {
				continue; // Ya tiene pagado lo que toca hasta ese plazo.
			}
			$m = $mandatos[ $id ] ?? null;
			if ( ! $m ) {
				$omitidas[] = self::omitida( $f, $centimos, asde_t( 'Sin autorización SEPA' ) );
			} elseif ( isset( $en_curso[ $id ] ) ) {
				$omitidas[] = self::omitida( $f, $centimos, sprintf( asde_t( 'Ya está en la remesa %s (falta registrar su cobro)' ), $en_curso[ $id ] ) );
			} elseif ( ! Iban::es_valido( $m['datos']['iban'] ) ) {
				$omitidas[] = self::omitida( $f, $centimos, asde_t( 'No se puede leer la autorización (pídele que la firme de nuevo)' ) );
			} else {
				$lineas[] = array(
					'mandato'     => $m['id'],
					'familia'     => $id,
					'familia_n'   => $f['numero'],
					'umr'         => $m['referencia'],
					'fecha_firma' => $m['fecha_firma'],
					'titular'     => $m['datos']['titular'],
					'iban'        => $m['datos']['iban'],
					'iban_mask'   => $m['iban_mask'],
					'secuencia'   => Mandatos::secuencia( $m ),
					'centimos'    => $centimos,
					'e2e'         => '',
					'estado'      => self::PENDIENTE,
				);
				$total += $centimos;
			}
		}
		return array(
			'lineas'       => $lineas,
			'omitidas'     => $omitidas,
			'total'        => $total,
			'plazo_nombre' => $nombre,
		);
	}

	/** @return array<int, string> familia_id => nº de la remesa con adeudos sin cobrar de esa ronda (generada o enviada). */
	public static function familias_sin_cobrar( string $ronda ): array {
		$out = array();
		foreach ( self::listado() as $r ) {
			if ( $r['ronda'] !== $ronda || self::ANULADA === $r['estado'] ) {
				continue;
			}
			foreach ( self::lineas( $r ) as $l ) {
				if ( self::PENDIENTE === $l['estado'] ) {
					$out[ (int) $l['familia'] ] = $r['numero'];
				}
			}
		}
		return $out;
	}

	// ---------------------------------------------------------------------
	// Crear, ver y cambiar de estado
	// ---------------------------------------------------------------------

	/**
	 * Guarda la remesa con lo preparado (y el acreedor tal como está hoy).
	 *
	 * @param array $prep Salida de preparar().
	 * @return int|\WP_Error Id de la remesa.
	 */
	public static function crear( array $prep, string $ronda, string $fecha_cobro, string $concepto, int $user_id ) {
		global $wpdb;
		$cfg = Config::de();
		if ( ! Config::lista( $cfg ) ) {
			return new \WP_Error( 'asde_sepa', asde_t( 'Faltan datos del acreedor: complétalos en «Datos del acreedor».' ) );
		}
		$fecha_error = self::error_fecha( $fecha_cobro );
		if ( '' !== $fecha_error ) {
			return new \WP_Error( 'asde_sepa', $fecha_error );
		}
		if ( ! $prep['lineas'] ) {
			return new \WP_Error( 'asde_sepa', asde_t( 'No hay ningún adeudo que incluir.' ) );
		}
		$grupo = Grupo::numero();
		$wpdb->query( "SELECT GET_LOCK('asde_num_remesa', 10)" );
		try {
			// Nunca dos remesas con los mismos adeudos sin cobrar (dos tesoreros a la vez, una vista previa antigua…).
			$en_curso      = self::familias_sin_cobrar( $ronda );
			$prep['lineas'] = array_values( array_filter( $prep['lineas'], static fn( $l ) => ! isset( $en_curso[ (int) $l['familia'] ] ) ) );
			if ( ! $prep['lineas'] ) {
				return new \WP_Error( 'asde_sepa', asde_t( 'Todas esas familias ya están en otra remesa que aún no tiene registrados sus cobros.' ) );
			}
			$prep['total'] = array_sum( array_map( static fn( $l ) => (int) $l['centimos'], $prep['lineas'] ) );
			$numero = self::siguiente_numero( $grupo );
			$lineas = array();
			foreach ( $prep['lineas'] as $l ) {
				$l['e2e'] = Texto::referencia( $numero . '-' . $l['familia_n'] );
				$lineas[] = $l;
			}
			$msg = Texto::referencia( 'ASDE' . $grupo . '-' . wp_date( 'YmdHis' ) . '-' . preg_replace( '/\D/', '', $numero ) );
			$err = Pain008::errores( $cfg, $fecha_cobro, $lineas );
			if ( $err ) {
				return new \WP_Error( 'asde_sepa', implode( ' ', $err ) );
			}
			$ok = $wpdb->insert(
				self::table(),
				array(
					'grupo'       => $grupo,
					'numero'      => $numero,
					'ronda'       => $ronda,
					'plazo'       => mb_substr( $prep['plazo_nombre'], 0, 60 ),
					'fecha_cobro' => $fecha_cobro,
					'concepto'    => Texto::limpiar( $concepto, 140 ),
					'estado'      => self::GENERADA,
					'adeudos'     => count( $lineas ),
					'total'       => round( $prep['total'] / 100, 2 ),
					'msg_id'      => $msg,
					'creada'      => current_time( 'mysql', true ),
					'creada_por'  => $user_id,
				)
			);
			$id = (int) $wpdb->insert_id;
		} finally {
			$wpdb->query( "SELECT RELEASE_LOCK('asde_num_remesa')" );
		}
		if ( ! $ok || ! $id ) {
			return new \WP_Error( 'asde_sepa', asde_t( 'No se ha podido guardar la remesa. Inténtalo de nuevo.' ) );
		}
		$contenido = array(
			'acreedor' => array_intersect_key( $cfg, array_flip( array( 'nombre', 'ics', 'iban', 'bic', 'calle', 'cp', 'poblacion' ) ) ),
			'lineas'   => $lineas,
		);
		$wpdb->update( self::table(), array( 'lineas_enc' => Crypto::encrypt_contexto( (string) wp_json_encode( $contenido ), 'sepa|remesa|' . $id ) ), array( 'id' => $id ) );
		Logger::log(
			'sepa_remesa_generada',
			'remesa',
			$numero,
			array(
				'ronda'   => $ronda,
				'adeudos' => count( $lineas ),
				'total'   => round( $prep['total'] / 100, 2 ),
			)
		);
		return $id;
	}

	private static function siguiente_numero( string $grupo ): string {
		global $wpdb;
		$prefijo = 'R' . $grupo . '-';
		$max     = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(CAST(SUBSTRING(numero, %d) AS UNSIGNED)) FROM ' . self::table() . ' WHERE numero LIKE %s', strlen( $prefijo ) + 1, $wpdb->esc_like( $prefijo ) . '%' ) ); // phpcs:ignore WordPress.DB
		return $prefijo . str_pad( (string) ( $max + 1 ), 4, '0', STR_PAD_LEFT );
	}

	/** Del grupo actual. */
	public static function obtener( int $id ): ?array {
		global $wpdb;
		$r = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d AND grupo = %s', $id, Grupo::numero() ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return $r ? self::preparar_fila( $r ) : null;
	}

	/** @return array[] Del grupo actual, las más recientes primero (sin descifrar los adeudos). */
	public static function listado(): array {
		global $wpdb;
		return array_map( array( self::class, 'preparar_fila' ), (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE grupo = %s ORDER BY id DESC', Grupo::numero() ), ARRAY_A ) ); // phpcs:ignore WordPress.DB
	}

	/** @return array[] Los adeudos de la remesa (vacío si ya se borraron). */
	public static function lineas( array $remesa ): array {
		return self::contenido( $remesa )['lineas'];
	}

	/** @return array{acreedor:array, lineas:array[]} */
	public static function contenido( array $remesa ): array {
		$vacio = array(
			'acreedor' => array(),
			'lineas'   => array(),
		);
		if ( empty( $remesa['lineas_enc'] ) ) {
			return $vacio;
		}
		$json = Crypto::decrypt_contexto( (string) $remesa['lineas_enc'], 'sepa|remesa|' . (int) $remesa['id'] );
		$c    = null !== $json ? json_decode( $json, true ) : null;
		return is_array( $c ) ? array_merge( $vacio, $c ) : $vacio;
	}

	/** El fichero XML de la remesa (con el acreedor tal como estaba al generarla). @return string|\WP_Error */
	public static function xml( array $remesa ) {
		$c = self::contenido( $remesa );
		if ( ! $c['lineas'] ) {
			return new \WP_Error( 'asde_sepa', asde_t( 'Los datos de esta remesa ya no se conservan.' ) );
		}
		$cfg = array_merge( Config::defaults(), $c['acreedor'] );
		$err = Pain008::errores( $cfg, (string) $remesa['fecha_cobro'], $c['lineas'] );
		if ( $err ) {
			return new \WP_Error( 'asde_sepa', implode( ' ', $err ) );
		}
		return Pain008::generar(
			$cfg,
			array(
				'numero'      => $remesa['numero'],
				'msg_id'      => $remesa['msg_id'],
				'fecha_cobro' => $remesa['fecha_cobro'],
				'concepto'    => $remesa['concepto'],
				'creada'      => get_date_from_gmt( $remesa['creada'], 'Y-m-d\TH:i:s' ),
			),
			$c['lineas']
		);
	}

	/** «1 adeudo», «2 adeudos». */
	public static function adeudos( int $n ): string {
		return $n . ( 1 === $n ? ' adeudo' : ' adeudos' );
	}

	public static function nombre_fichero( array $remesa ): string {
		return 'adeudos-sepa-' . strtolower( $remesa['numero'] ) . '.xml';
	}

	public static function contar_descarga( int $id ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . ' SET descargas = descargas + 1 WHERE id = %d', $id ) ); // phpcs:ignore WordPress.DB
	}

	/** Tesorería confirma que la ha enviado al banco: las autorizaciones estrenadas pasan a RCUR. */
	public static function marcar_enviada( int $id, int $user_id ): bool {
		global $wpdb;
		$r = self::obtener( $id );
		if ( ! $r || self::GENERADA !== $r['estado'] ) {
			return false;
		}
		$ok = (bool) $wpdb->update(
			self::table(),
			array(
				'estado'      => self::ENVIADA,
				'enviada_en'  => current_time( 'mysql', true ),
				'enviada_por' => $user_id,
			),
			array(
				'id'     => $id,
				'estado' => self::GENERADA,
			)
		);
		if ( $ok ) {
			Mandatos::marcar_usados( array_column( array_filter( self::lineas( $r ), static fn( $l ) => 'FRST' === $l['secuencia'] ), 'mandato' ) );
			Logger::log( 'sepa_remesa_enviada', 'remesa', $r['numero'] );
		}
		return $ok;
	}

	public static function marcar_avisada( int $id ): void {
		global $wpdb;
		$wpdb->update( self::table(), array( 'avisada_en' => current_time( 'mysql', true ) ), array( 'id' => $id ) );
	}

	/** Solo mientras no se ha enviado al banco. Los datos de los adeudos se borran. */
	public static function anular( int $id, int $user_id ): bool {
		global $wpdb;
		$r = self::obtener( $id );
		if ( ! $r || self::GENERADA !== $r['estado'] ) {
			return false;
		}
		$ok = (bool) $wpdb->update(
			self::table(),
			array(
				'estado'      => self::ANULADA,
				'anulada_en'  => current_time( 'mysql', true ),
				'anulada_por' => $user_id,
				'lineas_enc'  => null,
			),
			array(
				'id'     => $id,
				'estado' => self::GENERADA,
			)
		);
		if ( $ok ) {
			Logger::log( 'sepa_remesa_anulada', 'remesa', $r['numero'] );
		}
		return $ok;
	}

	/**
	 * Registra lo que ha hecho el banco con los adeudos pendientes de una remesa
	 * enviada: los cobrados pasan a ser pagos de la familia (forma «Domiciliación
	 * SEPA») y los devueltos se marcan para que Tesorería los reclame.
	 *
	 * @param int[]  $cobradas  Familias cuyo adeudo se ha cobrado.
	 * @param int[]  $devueltas Familias cuyo adeudo se ha devuelto.
	 * @param string $fecha     Fecha del cobro (AAAA-MM-DD, no futura).
	 * @return array{cobrados:int, devueltos:int, errores:string[]}
	 */
	public static function registrar_cobros( int $id, array $cobradas, array $devueltas, string $fecha, int $user_id ): array {
		global $wpdb;
		$res = array(
			'cobrados'  => 0,
			'devueltos' => 0,
			'errores'   => array(),
		);
		$r   = self::obtener( $id );
		if ( ! $r || self::ENVIADA !== $r['estado'] ) {
			$res['errores'][] = asde_t( 'Los cobros solo se registran de una remesa enviada al banco.' );
			return $res;
		}
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $fecha ) || $fecha > current_time( 'Y-m-d' ) ) {
			$res['errores'][] = asde_t( 'La fecha del cobro no es válida (no puede ser posterior a hoy).' );
			return $res;
		}
		$cobradas  = array_map( 'intval', $cobradas );
		$devueltas = array_map( 'intval', $devueltas );
		$c         = self::contenido( $r );
		foreach ( $c['lineas'] as $i => $l ) {
			if ( self::PENDIENTE !== $l['estado'] ) {
				continue;
			}
			$familia = (int) $l['familia'];
			if ( in_array( $familia, $cobradas, true ) && ! in_array( $familia, $devueltas, true ) ) {
				$mov = MovimientosTable::registrar( $familia, $r['ronda'], MovimientosTable::PAGO, $fecha, $l['centimos'] / 100, 'domiciliacion', (string) $l['e2e'], 'Remesa SEPA ' . $r['numero'], 'sepa' );
				if ( is_wp_error( $mov ) ) {
					$res['errores'][] = sprintf( asde_t( 'Familia %s: %s' ), $l['familia_n'], $mov->get_error_message() );
					continue;
				}
				$c['lineas'][ $i ]['estado']    = self::COBRADO;
				$c['lineas'][ $i ]['movimiento'] = $mov;
				++$res['cobrados'];
			} elseif ( in_array( $familia, $devueltas, true ) ) {
				$c['lineas'][ $i ]['estado'] = self::DEVUELTO;
				++$res['devueltos'];
			}
		}
		$pendientes = array_filter( $c['lineas'], static fn( $l ) => self::PENDIENTE === $l['estado'] );
		$datos      = array( 'lineas_enc' => Crypto::encrypt_contexto( (string) wp_json_encode( $c ), 'sepa|remesa|' . $id ) );
		if ( ! $pendientes ) {
			$datos['cobros_en']  = current_time( 'mysql', true );
			$datos['cobros_por'] = $user_id;
		}
		$wpdb->update( self::table(), $datos, array( 'id' => $id ) );
		Logger::log(
			'sepa_cobros_registrados',
			'remesa',
			$r['numero'],
			array(
				'cobrados'  => $res['cobrados'],
				'devueltos' => $res['devueltos'],
			)
		);
		return $res;
	}

	/**
	 * Próximos adeudos de una familia (remesas generadas o enviadas, con cobro hoy o
	 * más adelante): para el aviso previo de «Mis pagos».
	 *
	 * @return array[] [fecha, centimos, umr, concepto, remesa]
	 */
	public static function proximos_de_familia( int $familia_id ): array {
		$hoy = current_time( 'Y-m-d' );
		$out = array();
		foreach ( self::listado() as $r ) {
			if ( self::ANULADA === $r['estado'] || $r['fecha_cobro'] < $hoy ) {
				continue;
			}
			foreach ( self::lineas( $r ) as $l ) {
				if ( (int) $l['familia'] === $familia_id && self::PENDIENTE === $l['estado'] ) {
					$out[] = array(
						'fecha'    => $r['fecha_cobro'],
						'centimos' => (int) $l['centimos'],
						'umr'      => $l['umr'],
						'iban'     => $l['iban_mask'] ?? '',
						'concepto' => $r['concepto'],
						'remesa'   => $r['numero'],
					);
				}
			}
		}
		usort( $out, static fn( $a, $b ) => strcmp( $a['fecha'], $b['fecha'] ) );
		return $out;
	}

	/** Vacía los adeudos de las remesas con cobro hace más de 14 meses (tarea diaria). @return int */
	public static function purgar(): int {
		global $wpdb;
		$limite = gmdate( 'Y-m-d', strtotime( '-' . Mandatos::MESES_CONSERVACION . ' months' ) );
		return (int) $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . ' SET lineas_enc = NULL WHERE lineas_enc IS NOT NULL AND fecha_cobro < %s', $limite ) ); // phpcs:ignore WordPress.DB
	}

	// ---------------------------------------------------------------------

	/** @return array{familia:string, familia_n:string, importe:float, motivo:string} */
	private static function omitida( array $familia, int $centimos, string $motivo ): array {
		return array(
			'familia'   => (int) $familia['id'],
			'familia_n' => $familia['numero'],
			'centimos'  => $centimos,
			'motivo'    => $motivo,
		);
	}

	private static function preparar_fila( array $fila ): array {
		foreach ( array( 'id', 'adeudos', 'creada_por', 'enviada_por', 'cobros_por', 'anulada_por', 'descargas' ) as $k ) {
			$fila[ $k ] = (int) $fila[ $k ];
		}
		$fila['total'] = (float) $fila['total'];
		return $fila;
	}
}
