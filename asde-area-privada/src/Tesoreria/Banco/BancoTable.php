<?php
namespace AsdeBalears\AreaPrivada\Tesoreria\Banco;

use AsdeBalears\AreaPrivada\Crm\Crypto;
use AsdeBalears\AreaPrivada\Domain\Grupo;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- tablas propias del plugin ({prefix}asde_*): no hay función de WordPress para ellas y los datos cambian en cada petición, no se cachean.

/**
 * Movimientos del banco importados de ficheros (Norma 43 o CSV), por grupo:
 *   {prefix}asde_banco_lotes        una fila por fichero importado
 *   {prefix}asde_banco_movimientos  los movimientos (cada uno, una sola vez)
 *
 * - El fichero no se guarda: solo sus movimientos.
 * - La cuenta se guarda enmascarada (2056 0001 ******1234).
 * - El concepto y el ordenante (nombres de personas) se guardan cifrados;
 *   del ordenante, además, un HMAC para reconocerlo en pagos siguientes.
 * - Cada movimiento tiene una huella (HMAC de sus datos): al importar un fichero
 *   que se solapa con otro ya importado, lo repetido se ignora.
 */
final class BancoTable {

	public const PENDIENTE  = 'pendiente';
	public const CONCILIADO = 'conciliado';
	public const IGNORADO   = 'ignorado';

	public const ESTADOS = array(
		self::PENDIENTE  => 'Pendiente',
		self::CONCILIADO => 'Conciliado',
		self::IGNORADO   => 'Revisado (no es cuota)',
	);

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'asde_banco_movimientos';
	}

	public static function table_lotes(): string {
		global $wpdb;
		return $wpdb->prefix . 'asde_banco_lotes';
	}

	public static function install_table(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$lotes   = self::table_lotes();
		$movs    = self::table();
		dbDelta(
			"CREATE TABLE $lotes (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				grupo varchar(3) NOT NULL,
				origen varchar(10) NOT NULL,
				fichero varchar(190) NOT NULL DEFAULT '',
				fichero_hash char(64) NOT NULL DEFAULT '',
				cuentas varchar(255) NOT NULL DEFAULT '',
				desde date NULL,
				hasta date NULL,
				saldo_final decimal(12,2) NULL,
				leidos int(10) unsigned NOT NULL DEFAULT 0,
				nuevos int(10) unsigned NOT NULL DEFAULT 0,
				duplicados int(10) unsigned NOT NULL DEFAULT 0,
				importado_por bigint(20) unsigned NOT NULL DEFAULT 0,
				creado datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY grupo_creado (grupo, creado),
				KEY fichero_hash (fichero_hash)
			) $charset;"
		);
		dbDelta(
			"CREATE TABLE $movs (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				grupo varchar(3) NOT NULL,
				lote_id bigint(20) unsigned NOT NULL,
				cuenta varchar(40) NOT NULL DEFAULT '',
				fecha date NOT NULL,
				fecha_valor date NULL,
				importe decimal(12,2) NOT NULL,
				concepto_comun varchar(2) NOT NULL DEFAULT '',
				concepto_propio varchar(3) NOT NULL DEFAULT '',
				documento varchar(20) NOT NULL DEFAULT '',
				referencia1 varchar(20) NOT NULL DEFAULT '',
				referencia2 varchar(20) NOT NULL DEFAULT '',
				concepto_enc text NULL,
				ordenante_enc text NULL,
				ordenante_hmac char(64) NOT NULL DEFAULT '',
				huella char(64) NOT NULL,
				estado varchar(12) NOT NULL DEFAULT 'pendiente',
				familia_id bigint(20) unsigned NULL,
				pago_id bigint(20) unsigned NULL,
				tipo_id bigint(20) unsigned NULL,
				nota varchar(255) NOT NULL DEFAULT '',
				revisado_por bigint(20) unsigned NOT NULL DEFAULT 0,
				revisado_en datetime NULL,
				creado datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY grupo_huella (grupo, huella),
				KEY grupo_fecha (grupo, fecha),
				KEY grupo_estado (grupo, estado),
				KEY grupo_tipo (grupo, tipo_id),
				KEY ordenante_hmac (ordenante_hmac)
			) $charset;"
		);
	}

	// ---------------------------------------------------------------------
	// Importación
	// ---------------------------------------------------------------------

	/**
	 * Añade a cada movimiento su huella y si ya está importado (`duplicado`) o hay
	 * otro con la misma cuenta, fecha e importe (`posible_duplicado`, p. ej. el
	 * mismo movimiento importado antes desde un CSV y ahora desde Norma 43).
	 */
	public static function clasificar( array $movs ): array {
		global $wpdb;
		$vistos = array();
		foreach ( $movs as &$m ) {
			$base            = implode( '|', array( $m['cuenta'], $m['fecha'], (string) $m['fecha_valor'], (string) (int) round( $m['importe'] * 100 ), $m['concepto_comun'], $m['concepto_propio'], $m['documento'], $m['referencia1'], $m['referencia2'], mb_strtoupper( $m['concepto'] ), mb_strtoupper( $m['ordenante'] ) ) );
			$vistos[ $base ] = ( $vistos[ $base ] ?? 0 ) + 1; // Dos movimientos idénticos el mismo día son dos.
			$m['huella']     = Crypto::hmac( 'banco|' . $base . '|' . $vistos[ $base ] );
		}
		unset( $m );

		$existentes = array();
		foreach ( array_chunk( array_column( $movs, 'huella' ), 200 ) as $lote ) {
			$in         = implode( ',', array_fill( 0, count( $lote ), '%s' ) );
			$existentes = array_merge( $existentes, (array) $wpdb->get_col( $wpdb->prepare( 'SELECT huella FROM ' . self::table() . " WHERE grupo = %s AND huella IN ($in)", array_merge( array( Grupo::numero() ), $lote ) ) ) ); // phpcs:ignore WordPress.DB
		}
		$existentes = array_flip( $existentes );

		$similares = array();
		if ( $movs ) {
			$fechas = array_column( $movs, 'fecha' );
			$filas  = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT cuenta, fecha, importe, huella FROM ' . self::table() . ' WHERE grupo = %s AND fecha BETWEEN %s AND %s', Grupo::numero(), min( $fechas ), max( $fechas ) ), ARRAY_A ); // phpcs:ignore WordPress.DB
			foreach ( $filas as $f ) {
				$similares[ $f['cuenta'] . '|' . $f['fecha'] . '|' . (int) round( (float) $f['importe'] * 100 ) ] = true;
			}
		}
		foreach ( $movs as &$m ) {
			$m['duplicado']         = isset( $existentes[ $m['huella'] ] );
			$m['posible_duplicado'] = ! $m['duplicado'] && isset( $similares[ $m['cuenta'] . '|' . $m['fecha'] . '|' . (int) round( $m['importe'] * 100 ) ] );
		}
		unset( $m );
		return $movs;
	}

	/**
	 * Guarda un fichero leído (y clasificado): crea el lote e inserta los
	 * movimientos nuevos. Todo o nada.
	 *
	 * @return array{lote_id:int, nuevos:int, duplicados:int}|\WP_Error
	 */
	public static function importar( array $lectura, string $origen, string $fichero, string $hash ) {
		global $wpdb;
		$wpdb->query( 'START TRANSACTION' );
		try {
			return self::guardar_lote( $lectura, $origen, $fichero, $hash );
		} catch ( \Throwable $e ) {
			// Un fallo inesperado (p. ej. al cifrar) no deja la transacción abierta ni el lote a medias.
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'asde_banco', asde_t( 'Error al guardar los movimientos: no se ha importado nada.' ) );
		}
	}

	/** Dentro de la transacción de importar(): lote y movimientos; hace COMMIT o ROLLBACK según el resultado. @return array|\WP_Error */
	private static function guardar_lote( array $lectura, string $origen, string $fichero, string $hash ) {
		global $wpdb;
		$movs   = $lectura['movimientos'];
		$ahora  = current_time( 'mysql', true );
		$cuentas = array_values( array_unique( array_column( $lectura['cuentas'], 'cuenta' ) ) );
		$finales = array_filter( array_column( $lectura['cuentas'], 'saldo_final' ), static fn( $v ) => null !== $v );

		$ok = $wpdb->insert(
			self::table_lotes(),
			array(
				'grupo'         => Grupo::numero(),
				'origen'        => $origen,
				'fichero'       => mb_substr( sanitize_file_name( $fichero ), 0, 190 ),
				'fichero_hash'  => $hash,
				'cuentas'       => mb_substr( implode( ', ', $cuentas ), 0, 255 ),
				'desde'         => $movs ? min( array_column( $movs, 'fecha' ) ) : null,
				'hasta'         => $movs ? max( array_column( $movs, 'fecha' ) ) : null,
				'saldo_final'   => 1 === count( $finales ) ? (float) reset( $finales ) : null,
				'leidos'        => count( $movs ),
				'importado_por' => get_current_user_id(),
				'creado'        => $ahora,
			)
		);
		$lote = (int) $wpdb->insert_id;
		if ( ! $ok || ! $lote ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'asde_banco', asde_t( 'No se ha podido guardar la importación.' ) );
		}

		$nuevos = 0;
		foreach ( $movs as $m ) {
			if ( ! empty( $m['duplicado'] ) ) {
				continue;
			}
			$ordenante = trim( $m['ordenante'] );
			$r         = $wpdb->query(
				$wpdb->prepare(
					'INSERT IGNORE INTO %i (grupo, lote_id, cuenta, fecha, fecha_valor, importe, concepto_comun, concepto_propio, documento, referencia1, referencia2, concepto_enc, ordenante_enc, ordenante_hmac, huella, estado, creado)'
					. ' VALUES (%s, %d, %s, %s, ' . ( $m['fecha_valor'] ? '%s' : 'NULL' ) . ', %f, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)',
					array_merge(
						array( self::table() ),
						array_values(
						array_filter(
							array(
								Grupo::numero(),
								$lote,
								mb_substr( $m['cuenta'], 0, 40 ),
								$m['fecha'],
								$m['fecha_valor'] ?: false,
								round( (float) $m['importe'], 2 ),
								mb_substr( $m['concepto_comun'], 0, 2 ),
								mb_substr( $m['concepto_propio'], 0, 3 ),
								mb_substr( $m['documento'], 0, 20 ),
								mb_substr( $m['referencia1'], 0, 20 ),
								mb_substr( $m['referencia2'], 0, 20 ),
								'' !== $m['concepto'] ? Crypto::encrypt( $m['concepto'] ) : '',
								'' !== $ordenante ? Crypto::encrypt( $ordenante ) : '',
								'' !== $ordenante ? self::hmac_ordenante( $ordenante ) : '',
								$m['huella'],
								self::PENDIENTE,
								$ahora,
							),
							static fn( $v ) => false !== $v
						)
						)
					)
				)
			); // phpcs:ignore WordPress.DB
			if ( false === $r ) {
				$wpdb->query( 'ROLLBACK' );
				return new \WP_Error( 'asde_banco', asde_t( 'Error al guardar los movimientos: no se ha importado nada.' ) );
			}
			$nuevos += (int) $r;
		}
		$duplicados = count( $movs ) - $nuevos;
		$wpdb->update(
			self::table_lotes(),
			array(
				'nuevos'     => $nuevos,
				'duplicados' => $duplicados,
			),
			array( 'id' => $lote )
		);
		$wpdb->query( 'COMMIT' );
		return array(
			'lote_id'    => $lote,
			'nuevos'     => $nuevos,
			'duplicados' => $duplicados,
		);
	}

	public static function hmac_ordenante( string $nombre ): string {
		return Crypto::hmac( 'ordenante|' . mb_strtoupper( remove_accents( (string) preg_replace( '/\s+/u', ' ', trim( $nombre ) ) ) ) );
	}

	/** Importación anterior del mismo fichero (mismo contenido), si la hay. */
	public static function lote_por_hash( string $hash ): ?array {
		global $wpdb;
		$r = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table_lotes() . ' WHERE grupo = %s AND fichero_hash = %s ORDER BY id DESC LIMIT 1', Grupo::numero(), $hash ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return $r ?: null;
	}

	/** @return array[] Últimas importaciones del grupo. */
	public static function lotes( int $limite = 10 ): array {
		global $wpdb;
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table_lotes() . ' WHERE grupo = %s ORDER BY id DESC LIMIT %d', Grupo::numero(), $limite ), ARRAY_A ); // phpcs:ignore WordPress.DB
	}

	// ---------------------------------------------------------------------
	// Consulta y conciliación
	// ---------------------------------------------------------------------

	/**
	 * Movimientos del grupo, con el concepto y el ordenante descifrados.
	 *
	 * @param array{estado?:string, tipo?:string, q?:string, clase?:string, ids?:int[]} $filtros
	 *        tipo: ingresos | gastos; clase: id de un tipo de movimiento (TiposMovimiento) o «sin» (revisados sin tipo); ids: solo esos.
	 * @return array[]
	 */
	public static function movimientos( array $filtros = array() ): array {
		global $wpdb;
		$sql  = 'SELECT * FROM ' . self::table() . ' WHERE grupo = %s';
		$args = array( Grupo::numero() );
		if ( isset( self::ESTADOS[ $filtros['estado'] ?? '' ] ) ) {
			$sql   .= ' AND estado = %s';
			$args[] = $filtros['estado'];
		}
		$clase = (string) ( $filtros['clase'] ?? '' );
		if ( 'sin' === $clase ) {
			$sql   .= ' AND estado = %s AND tipo_id IS NULL';
			$args[] = self::IGNORADO;
		} elseif ( ctype_digit( $clase ) && (int) $clase > 0 ) {
			$sql   .= ' AND tipo_id = %d';
			$args[] = (int) $clase;
		}
		if ( isset( $filtros['ids'] ) ) {
			$ids = array_values( array_filter( array_map( 'intval', (array) $filtros['ids'] ) ) );
			if ( ! $ids ) {
				return array();
			}
			$sql .= ' AND id IN (' . implode( ',', $ids ) . ')';
		}
		if ( 'ingresos' === ( $filtros['tipo'] ?? '' ) ) {
			$sql .= ' AND importe > 0';
		} elseif ( 'gastos' === ( $filtros['tipo'] ?? '' ) ) {
			$sql .= ' AND importe < 0';
		}
		$filas = array_map( array( self::class, 'descifrar' ), (array) $wpdb->get_results( $wpdb->prepare( $sql . ' ORDER BY fecha DESC, id DESC LIMIT 10000', $args ), ARRAY_A ) ); // phpcs:ignore WordPress.DB

		$q = mb_strtolower( remove_accents( trim( (string) ( $filtros['q'] ?? '' ) ) ) );
		if ( '' !== $q ) {
			$filas = array_values(
				array_filter(
					$filas,
					static fn( $f ) => str_contains( mb_strtolower( remove_accents( $f['concepto'] . ' ' . $f['ordenante'] . ' ' . $f['referencia1'] . ' ' . $f['referencia2'] . ' ' . $f['documento'] . ' ' . $f['nota'] ) ), $q )
				)
			);
		}
		return $filas;
	}

	public static function obtener( int $id ): ?array {
		global $wpdb;
		$r = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d AND grupo = %s', $id, Grupo::numero() ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return $r ? self::descifrar( $r ) : null;
	}

	/** El movimiento del banco con el que se registró un pago (al conciliarlo), o null (v0.25). */
	public static function por_pago( int $pago_id ): ?array {
		global $wpdb;
		$r = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE pago_id = %d AND grupo = %s ORDER BY id DESC LIMIT 1', $pago_id, Grupo::numero() ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return $r ? self::descifrar( $r ) : null;
	}

	/** Cambia el estado. El tipo y la nota (de los revisados) se quitan: para ponerlos, revisar(). */
	public static function marcar( int $id, string $estado, ?int $familia_id = null, ?int $pago_id = null ): bool {
		global $wpdb;
		return false !== $wpdb->update(
			self::table(),
			array(
				'estado'       => $estado,
				'familia_id'   => $familia_id,
				'pago_id'      => $pago_id,
				'tipo_id'      => null,
				'nota'         => '',
				'revisado_por' => self::PENDIENTE === $estado ? 0 : get_current_user_id(),
				'revisado_en'  => self::PENDIENTE === $estado ? null : current_time( 'mysql', true ),
			),
			array(
				'id'    => $id,
				'grupo' => Grupo::numero(),
			)
		);
	}

	/** Revisado (no es cuota) con su tipo y una nota; también para cambiar el tipo de uno ya revisado. */
	public static function revisar( int $id, int $tipo_id, string $nota ): bool {
		global $wpdb;
		return false !== $wpdb->update(
			self::table(),
			array(
				'estado'       => self::IGNORADO,
				'familia_id'   => null,
				'pago_id'      => null,
				'tipo_id'      => $tipo_id ?: null,
				'nota'         => mb_substr( sanitize_text_field( $nota ), 0, 255 ),
				'revisado_por' => get_current_user_id(),
				'revisado_en'  => current_time( 'mysql', true ),
			),
			array(
				'id'    => $id,
				'grupo' => Grupo::numero(),
			)
		);
	}

	/** Familia a la que se asignó el último pago conciliado de este ordenante. */
	public static function familia_por_ordenante( string $hmac ): ?int {
		global $wpdb;
		if ( '' === $hmac ) {
			return null;
		}
		$id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT familia_id FROM ' . self::table() . ' WHERE grupo = %s AND ordenante_hmac = %s AND estado = %s AND familia_id IS NOT NULL ORDER BY revisado_en DESC LIMIT 1', Grupo::numero(), $hmac, self::CONCILIADO ) ); // phpcs:ignore WordPress.DB
		return $id ?: null;
	}

	/** @return array{pendientes:int, pendientes_importe:float, saldo:?float, saldo_fecha:?string} */
	public static function resumen(): array {
		global $wpdb;
		$p     = $wpdb->get_row( $wpdb->prepare( 'SELECT COUNT(*) AS n, COALESCE(SUM(importe), 0) AS total FROM ' . self::table() . ' WHERE grupo = %s AND estado = %s AND importe > 0', Grupo::numero(), self::PENDIENTE ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$saldo = $wpdb->get_row( $wpdb->prepare( 'SELECT saldo_final, hasta FROM ' . self::table_lotes() . ' WHERE grupo = %s AND saldo_final IS NOT NULL ORDER BY hasta DESC, id DESC LIMIT 1', Grupo::numero() ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return array(
			'pendientes'         => (int) ( $p['n'] ?? 0 ),
			'pendientes_importe' => (float) ( $p['total'] ?? 0 ),
			'saldo'              => $saldo ? (float) $saldo['saldo_final'] : null,
			'saldo_fecha'        => $saldo['hasta'] ?? null,
		);
	}

	private static function descifrar( array $r ): array {
		$r['concepto']  = '' !== (string) $r['concepto_enc'] ? (string) Crypto::decrypt( (string) $r['concepto_enc'] ) : '';
		$r['ordenante'] = '' !== (string) $r['ordenante_enc'] ? (string) Crypto::decrypt( (string) $r['ordenante_enc'] ) : '';
		unset( $r['concepto_enc'], $r['ordenante_enc'] );
		$r['importe'] = (float) $r['importe'];
		$r['tipo_id'] = null === ( $r['tipo_id'] ?? null ) ? null : (int) $r['tipo_id'];
		$r['nota']    = (string) ( $r['nota'] ?? '' );
		return $r;
	}
}
