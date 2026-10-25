<?php
namespace AsdeBalears\AreaPrivada\Caja;

use AsdeBalears\AreaPrivada\Crm\Crypto;
use AsdeBalears\AreaPrivada\Domain\Grupo;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- tabla propia del plugin ({prefix}asde_caja): no hay función de WordPress para ella y los datos cambian en cada petición, no se cachean.

/**
 * Apuntes propios de las cajas de efectivo (v0.59, doc 61): {prefix}asde_caja.
 *
 * Solo lo que no está en ningún otro módulo: el saldo inicial (de cada caja y del banco), los apuntes a mano, los
 * traspasos (entre cajas o con el banco) y los arqueos. Los cobros y pagos en efectivo de cuotas, eventos, tienda y
 * reintegros NO se copian aquí: se leen en vivo de su módulo (Automaticos, P-212).
 *
 * - `caja`: 'grupo', el slug de una sección educativa o 'banco' (solo para el saldo inicial del banco).
 * - `clase`: inicial | manual | traspaso | arqueo.
 * - `importe`: con signo (+ entra, − sale). En un arqueo, la diferencia entre lo contado y lo calculado.
 * - Traspaso entre cajas: dos filas (una sale y otra entra) enlazadas con `par_id`. Con el banco, una fila con
 *   `destino` = 'banco' y, cuando llega el extracto, `banco_id` = el movimiento del banco asociado (P-219).
 * - El concepto y la nota van cifrados (pueden llevar nombres). No se borra nada: se anula con motivo.
 */
final class CajaTable {

	public const INICIAL  = 'inicial';
	public const MANUAL   = 'manual';
	public const TRASPASO = 'traspaso';
	public const ARQUEO   = 'arqueo';

	/** La «caja» en la que se guarda el saldo inicial del banco (P-208). */
	public const BANCO = 'banco';

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'asde_caja';
	}

	public static function install_table(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$t       = self::table();
		dbDelta(
			"CREATE TABLE $t (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				grupo varchar(3) NOT NULL,
				caja varchar(20) NOT NULL,
				clase varchar(10) NOT NULL,
				fecha date NOT NULL,
				importe decimal(12,2) NOT NULL,
				recuento decimal(12,2) NULL,
				tipo_id bigint(20) unsigned NULL,
				evento_id bigint(20) unsigned NULL,
				destino varchar(20) NOT NULL DEFAULT '',
				par_id bigint(20) unsigned NULL,
				banco_id bigint(20) unsigned NULL,
				concepto_enc text NULL,
				nota_enc text NULL,
				creado_por bigint(20) unsigned NOT NULL DEFAULT 0,
				creado datetime NOT NULL,
				anulado_por bigint(20) unsigned NOT NULL DEFAULT 0,
				anulado_en datetime NULL,
				anulado_motivo varchar(255) NOT NULL DEFAULT '',
				PRIMARY KEY  (id),
				KEY grupo_caja_fecha (grupo, caja, fecha),
				KEY grupo_banco (grupo, banco_id)
			) $charset;"
		);
	}

	/**
	 * Añade un apunte en el grupo actual. Devuelve su id (0 si no se ha podido).
	 *
	 * @param array{caja:string, clase:string, fecha:string, importe:float, recuento?:?float, tipo_id?:int, evento_id?:int, destino?:string, par_id?:int, concepto?:string, nota?:string} $d
	 */
	public static function anadir( array $d ): int {
		global $wpdb;
		$ok = $wpdb->insert(
			self::table(),
			array(
				'grupo'        => Grupo::numero(),
				'caja'         => (string) $d['caja'],
				'clase'        => (string) $d['clase'],
				'fecha'        => (string) $d['fecha'],
				'importe'      => round( (float) $d['importe'], 2 ),
				'recuento'     => isset( $d['recuento'] ) ? round( (float) $d['recuento'], 2 ) : null,
				'tipo_id'      => ! empty( $d['tipo_id'] ) ? (int) $d['tipo_id'] : null,
				'evento_id'    => ! empty( $d['evento_id'] ) ? (int) $d['evento_id'] : null,
				'destino'      => (string) ( $d['destino'] ?? '' ),
				'par_id'       => ! empty( $d['par_id'] ) ? (int) $d['par_id'] : null,
				'concepto_enc' => '' !== (string) ( $d['concepto'] ?? '' ) ? Crypto::encrypt( (string) $d['concepto'] ) : null,
				'nota_enc'     => '' !== (string) ( $d['nota'] ?? '' ) ? Crypto::encrypt( (string) $d['nota'] ) : null,
				'creado_por'   => get_current_user_id(),
				'creado'       => current_time( 'mysql', true ),
			)
		);
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	public static function obtener( int $id ): ?array {
		global $wpdb;
		$r = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d AND grupo = %s', self::table(), $id, Grupo::numero() ), ARRAY_A );
		return $r ? self::leer( $r ) : null;
	}

	/**
	 * Apuntes vivos (sin anular) de una caja del grupo, por fecha. Con $hasta, solo hasta ese día (incluido).
	 *
	 * @return array[]
	 */
	public static function de_caja( string $caja, string $hasta = '9999-12-31' ): array {
		global $wpdb;
		$filas = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE grupo = %s AND caja = %s AND anulado_en IS NULL AND fecha <= %s ORDER BY fecha, id', self::table(), Grupo::numero(), $caja, $hasta ), ARRAY_A );
		return array_map( array( self::class, 'leer' ), $filas );
	}

	/** El saldo inicial vivo de una caja (o del banco), o null si aún no se ha puesto. */
	public static function inicial( string $caja ): ?array {
		global $wpdb;
		$r = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE grupo = %s AND caja = %s AND clase = %s AND anulado_en IS NULL ORDER BY id DESC LIMIT 1', self::table(), Grupo::numero(), $caja, self::INICIAL ), ARRAY_A );
		return $r ? self::leer( $r ) : null;
	}

	/** Anula un apunte vivo del grupo (y su pareja, en un traspaso entre cajas). Devuelve cuántos se han anulado. */
	public static function anular( int $id, string $motivo ): int {
		global $wpdb;
		$a = self::obtener( $id );
		if ( ! $a || null !== $a['anulado_en'] ) {
			return 0;
		}
		$n = 0;
		foreach ( array_filter( array( $id, (int) $a['par_id'] ) ) as $cual ) {
			$n += (int) $wpdb->query( $wpdb->prepare( 'UPDATE %i SET anulado_en = %s, anulado_por = %d, anulado_motivo = %s WHERE id = %d AND grupo = %s AND anulado_en IS NULL', self::table(), current_time( 'mysql', true ), get_current_user_id(), mb_substr( $motivo, 0, 255 ), $cual, Grupo::numero() ) );
		}
		return $n;
	}

	public static function enlazar_par( int $a, int $b ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET par_id = %d WHERE id = %d AND grupo = %s', self::table(), $b, $a, Grupo::numero() ) );
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET par_id = %d WHERE id = %d AND grupo = %s', self::table(), $a, $b, Grupo::numero() ) );
	}

	/**
	 * Asocia un traspaso con el banco a su movimiento del banco (0 = quitar). Solo si ese movimiento no está ya
	 * asociado a otro apunte vivo de la caja (una sola sentencia con NOT EXISTS: dos peticiones a la vez no lo enlazan dos veces).
	 */
	public static function poner_banco( int $id, int $banco_id ): bool {
		global $wpdb;
		if ( ! $banco_id ) {
			return false !== $wpdb->query( $wpdb->prepare( 'UPDATE %i SET banco_id = NULL WHERE id = %d AND grupo = %s', self::table(), $id, Grupo::numero() ) );
		}
		$t = self::table();
		$n = $wpdb->query( $wpdb->prepare( 'UPDATE %i c LEFT JOIN %i o ON o.grupo = c.grupo AND o.banco_id = %d AND o.anulado_en IS NULL AND o.id <> c.id SET c.banco_id = %d WHERE c.id = %d AND c.grupo = %s AND c.banco_id IS NULL AND c.anulado_en IS NULL AND o.id IS NULL', $t, $t, $banco_id, $banco_id, $id, Grupo::numero() ) );
		return 1 === (int) $n;
	}

	/**
	 * Apuntes vivos de la caja asociados a estos movimientos del banco (para enseñarlo en el banco y no contarlos
	 * como gasto o ingreso en el libro mayor).
	 *
	 * @param int[] $ids
	 * @return array<int, array> id del movimiento del banco => apunte
	 */
	public static function por_banco( array $ids ): array {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( ! $ids ) {
			return array();
		}
		$lista = implode( ',', $ids );
		$filas = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE grupo = %s AND anulado_en IS NULL AND FIND_IN_SET( banco_id, %s ) > 0', self::table(), Grupo::numero(), $lista ), ARRAY_A );
		$out   = array();
		foreach ( $filas as $f ) {
			$out[ (int) $f['banco_id'] ] = self::leer( $f );
		}
		return $out;
	}

	private static function leer( array $r ): array {
		$r['concepto'] = '' !== (string) $r['concepto_enc'] ? (string) Crypto::decrypt( (string) $r['concepto_enc'] ) : '';
		$r['nota']     = '' !== (string) $r['nota_enc'] ? (string) Crypto::decrypt( (string) $r['nota_enc'] ) : '';
		unset( $r['concepto_enc'], $r['nota_enc'] );
		$r['id']       = (int) $r['id'];
		$r['importe']  = (float) $r['importe'];
		$r['recuento'] = null === $r['recuento'] ? null : (float) $r['recuento'];
		foreach ( array( 'tipo_id', 'evento_id', 'par_id', 'banco_id' ) as $k ) {
			$r[ $k ] = empty( $r[ $k ] ) ? null : (int) $r[ $k ];
		}
		return $r;
	}
}
