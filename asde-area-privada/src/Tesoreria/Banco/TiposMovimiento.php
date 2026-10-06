<?php
namespace AsdeBalears\AreaPrivada\Tesoreria\Banco;

use AsdeBalears\AreaPrivada\Domain\Grupo;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- tablas propias del plugin ({prefix}asde_*): no hay función de WordPress para ellas y los datos cambian en cada petición, no se cachean.

/**
 * Tipos de los movimientos del banco que no son cuotas (v0.20, doc 16): gastos
 * (material, transporte, comisiones…) e ingresos (donativos, subvenciones…).
 * Cada grupo tiene los suyos ({prefix}asde_banco_tipos); la primera vez se crean
 * unos de partida, que se pueden cambiar o borrar.
 *
 * Un tipo que usa algún movimiento no se borra (se puede renombrar).
 */
final class TiposMovimiento {

	public const GASTO   = 'gasto';
	public const INGRESO = 'ingreso';
	public const AMBOS   = 'ambos';

	public const NATURALEZAS = array(
		self::GASTO   => 'Gasto',
		self::INGRESO => 'Ingreso',
		self::AMBOS   => 'Gasto o ingreso',
	);

	/** Los de partida (se crean una sola vez por grupo; si se borran, no vuelven). */
	public const INICIALES = array(
		array( 'Material y equipamiento', self::GASTO ),
		array( 'Actividades y campamentos', self::GASTO ),
		array( 'Alimentación', self::GASTO ),
		array( 'Transporte', self::GASTO ),
		array( 'Local: alquiler y suministros', self::GASTO ),
		array( 'Seguros', self::GASTO ),
		array( 'Cuotas federativas', self::GASTO ),
		array( 'Uniformes e insignias', self::GASTO ),
		array( 'Comisiones bancarias', self::GASTO ),
		array( 'Otros gastos', self::GASTO ),
		array( 'Donativos', self::INGRESO ),
		array( 'Subvenciones', self::INGRESO ),
		array( 'Lotería y rifas', self::INGRESO ),
		array( 'Otros ingresos', self::INGRESO ),
		array( 'Devoluciones', self::AMBOS ),
	);

	private const OPCION_SEMBRADOS = 'asde_ap_banco_tipos_sembrados';

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'asde_banco_tipos';
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
				nombre varchar(80) NOT NULL,
				naturaleza varchar(8) NOT NULL DEFAULT 'gasto',
				creado datetime NOT NULL,
				creado_por bigint(20) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				KEY grupo (grupo)
			) $charset;"
		);
	}

	/** Crea los tipos de partida la primera vez que el grupo usa el banco. */
	public static function sembrar(): void {
		$grupo     = Grupo::numero();
		$sembrados = (array) get_option( self::OPCION_SEMBRADOS, array() );
		if ( '' === $grupo || in_array( $grupo, $sembrados, true ) ) {
			return;
		}
		$sembrados[] = $grupo;
		update_option( self::OPCION_SEMBRADOS, array_values( array_unique( $sembrados ) ), false );
		if ( self::todos() ) {
			return;
		}
		foreach ( self::INICIALES as list( $nombre, $naturaleza ) ) {
			self::crear( asde_t( $nombre ), $naturaleza );
		}
	}

	/** Para comparar nombres: sin mayúsculas, acentos ni espacios de más. */
	public static function clave( string $nombre ): string {
		return mb_strtolower( remove_accents( (string) preg_replace( '/\s+/u', ' ', trim( $nombre ) ) ) );
	}

	/** @return array<int, array{id:int, nombre:string, naturaleza:string}> id => tipo, gastos primero y por nombre. */
	public static function todos(): array {
		global $wpdb;
		$out = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT id, nombre, naturaleza FROM %i WHERE grupo = %s', self::table(), Grupo::numero() ), ARRAY_A ) as $f ) {
			$out[ (int) $f['id'] ] = array(
				'id'         => (int) $f['id'],
				'nombre'     => (string) $f['nombre'],
				'naturaleza' => isset( self::NATURALEZAS[ $f['naturaleza'] ] ) ? (string) $f['naturaleza'] : self::GASTO,
			);
		}
		$orden = array_flip( array_keys( self::NATURALEZAS ) );
		uasort( $out, static fn( $a, $b ) => array( $orden[ $a['naturaleza'] ], self::clave( $a['nombre'] ) ) <=> array( $orden[ $b['naturaleza'] ], self::clave( $b['nombre'] ) ) );
		return $out;
	}

	public static function obtener( int $id ): ?array {
		return self::todos()[ $id ] ?? null;
	}

	/** ¿Sirve este tipo para un movimiento de este importe? (gasto: negativo; ingreso: positivo). */
	public static function encaja( array $tipo, float $importe ): bool {
		return self::AMBOS === $tipo['naturaleza'] || ( $importe < 0 ? self::GASTO : self::INGRESO ) === $tipo['naturaleza'];
	}

	/** @return array<int, array> Los tipos que sirven para un movimiento de este importe. */
	public static function para( float $importe ): array {
		return array_filter( self::todos(), static fn( $t ) => self::encaja( $t, $importe ) );
	}

	/** @return int|\WP_Error */
	public static function crear( string $nombre, string $naturaleza ) {
		$nombre = self::limpiar( $nombre );
		if ( '' === $nombre ) {
			return new \WP_Error( 'asde_banco', asde_t( 'Escribe el nombre del tipo.' ) );
		}
		if ( ! isset( self::NATURALEZAS[ $naturaleza ] ) ) {
			return new \WP_Error( 'asde_banco', asde_t( 'Elige si es un gasto, un ingreso o las dos cosas.' ) );
		}
		if ( self::por_nombre( $nombre ) ) {
			return new \WP_Error( 'asde_banco', sprintf( asde_t( 'Ya hay un tipo «%s».' ), $nombre ) );
		}
		global $wpdb;
		$wpdb->insert(
			self::table(),
			array(
				'grupo'      => Grupo::numero(),
				'nombre'     => $nombre,
				'naturaleza' => $naturaleza,
				'creado'     => current_time( 'mysql', true ),
				'creado_por' => get_current_user_id(),
			)
		);
		return (int) $wpdb->insert_id;
	}

	/** @return true|\WP_Error Cambia el nombre y la naturaleza (esta, solo si ningún movimiento deja de encajar). */
	public static function cambiar( int $id, string $nombre, string $naturaleza ) {
		$tipo   = self::obtener( $id );
		$nombre = self::limpiar( $nombre );
		if ( ! $tipo ) {
			return new \WP_Error( 'asde_banco', asde_t( 'Ese tipo no existe.' ) );
		}
		if ( '' === $nombre ) {
			return new \WP_Error( 'asde_banco', asde_t( 'Escribe el nombre del tipo.' ) );
		}
		if ( ! isset( self::NATURALEZAS[ $naturaleza ] ) ) {
			return new \WP_Error( 'asde_banco', asde_t( 'Elige si es un gasto, un ingreso o las dos cosas.' ) );
		}
		if ( self::por_nombre( $nombre, $id ) ) {
			return new \WP_Error( 'asde_banco', sprintf( asde_t( 'Ya hay un tipo «%s».' ), $nombre ) );
		}
		$usos = self::usos()[ $id ] ?? array( 'gastos' => 0, 'ingresos' => 0 );
		if ( ( self::INGRESO === $naturaleza && $usos['gastos'] ) || ( self::GASTO === $naturaleza && $usos['ingresos'] ) ) {
			return new \WP_Error( 'asde_banco', asde_t( 'Hay movimientos de la otra clase con este tipo: déjalo como «Gasto o ingreso» o cámbiales antes el tipo.' ) );
		}
		global $wpdb;
		$wpdb->update(
			self::table(),
			array(
				'nombre'     => $nombre,
				'naturaleza' => $naturaleza,
			),
			array(
				'id'    => $id,
				'grupo' => Grupo::numero(),
			)
		);
		return true;
	}

	/** @return true|\WP_Error Solo si ningún movimiento lo usa. */
	public static function borrar( int $id ) {
		$tipo = self::obtener( $id );
		if ( ! $tipo ) {
			return new \WP_Error( 'asde_banco', asde_t( 'Ese tipo no existe.' ) );
		}
		$usos = self::usos()[ $id ] ?? array( 'gastos' => 0, 'ingresos' => 0 );
		$n    = $usos['gastos'] + $usos['ingresos'];
		if ( $n ) {
			/* translators: 1: nombre del tipo, 2: número de movimientos */
			return new \WP_Error( 'asde_banco', sprintf( asde_n( 'El tipo «%1$s» lo usa %2$d movimiento del banco: cámbiale el tipo antes de borrarlo.', 'El tipo «%1$s» lo usan %2$d movimientos del banco: cámbiales el tipo antes de borrarlo.', $n ), $tipo['nombre'], $n ) );
		}
		global $wpdb;
		$wpdb->delete(
			self::table(),
			array(
				'id'    => $id,
				'grupo' => Grupo::numero(),
			)
		);
		return true;
	}

	/** @return array<int, array{gastos:int, ingresos:int, total:float}> id de tipo => movimientos que lo usan (y su suma). */
	public static function usos(): array {
		global $wpdb;
		$out = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT tipo_id, SUM(importe < 0) AS gastos, SUM(importe > 0) AS ingresos, COALESCE(SUM(importe), 0) AS total FROM %i WHERE grupo = %s AND tipo_id IS NOT NULL GROUP BY tipo_id', BancoTable::table(), Grupo::numero() ), ARRAY_A ) as $f ) {
			$out[ (int) $f['tipo_id'] ] = array(
				'gastos'   => (int) $f['gastos'],
				'ingresos' => (int) $f['ingresos'],
				'total'    => (float) $f['total'],
			);
		}
		return $out;
	}

	private static function por_nombre( string $nombre, int $salvo = 0 ): int {
		foreach ( self::todos() as $id => $t ) {
			if ( $id !== $salvo && self::clave( $t['nombre'] ) === self::clave( $nombre ) ) {
				return $id;
			}
		}
		return 0;
	}

	private static function limpiar( string $nombre ): string {
		return mb_substr( trim( (string) preg_replace( '/\s+/u', ' ', sanitize_text_field( $nombre ) ) ), 0, 80 );
	}
}
