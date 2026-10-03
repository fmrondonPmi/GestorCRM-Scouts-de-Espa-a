<?php
namespace AsdeBalears\AreaPrivada\Tesoreria;

use AsdeBalears\AreaPrivada\Domain\Grupo;

defined( 'ABSPATH' ) || exit;

/**
 * Familias: agrupan socios (por nº de socio Scouts de España) y tienen un nº de
 * gestión propio "F + grupo + correlativo" (F684001), pensado para ponerlo en el
 * concepto de la transferencia. Solo identificadores: los nombres están en el CRM.
 *
 *   {prefix}asde_familias          id, numero, grupo, creado, actualizado
 *   {prefix}asde_familia_miembros  num_socio_asde (único: un socio, una familia), familia_id
 */
final class FamiliasTable {

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'asde_familias';
	}

	public static function table_miembros(): string {
		global $wpdb;
		return $wpdb->prefix . 'asde_familia_miembros';
	}

	public static function install_table(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$t       = self::table();
		$m       = self::table_miembros();
		dbDelta(
			"CREATE TABLE $t (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				numero varchar(20) NOT NULL,
				grupo varchar(10) NOT NULL DEFAULT '',
				creado datetime NOT NULL,
				actualizado datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY numero (numero)
			) $charset;"
		);
		dbDelta(
			"CREATE TABLE $m (
				num_socio_asde varchar(20) NOT NULL,
				familia_id bigint(20) unsigned NOT NULL,
				creado datetime NOT NULL,
				PRIMARY KEY  (num_socio_asde),
				KEY familia_id (familia_id)
			) $charset;"
		);
	}

	/** @return array{id:int, numero:string, miembros:string[]}|null */
	public static function obtener( int $id ): ?array {
		global $wpdb;
		// Solo familias del grupo en el que se trabaja (sin visibilidad entre grupos).
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT id, numero FROM ' . self::table() . ' WHERE id = %d AND grupo = %s', $id, Grupo::numero() ), ARRAY_A ); // phpcs:ignore WordPress.DB
		if ( ! $row ) {
			return null;
		}
		return array(
			'id'       => (int) $row['id'],
			'numero'   => (string) $row['numero'],
			'miembros' => array_map( 'strval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT num_socio_asde FROM ' . self::table_miembros() . ' WHERE familia_id = %d ORDER BY creado', $id ) ) ), // phpcs:ignore WordPress.DB
		);
	}

	public static function por_numero( string $numero ): ?array {
		global $wpdb;
		$id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::table() . ' WHERE numero = %s AND grupo = %s', strtoupper( trim( $numero ) ), Grupo::numero() ) ); // phpcs:ignore WordPress.DB
		return $id ? self::obtener( $id ) : null;
	}

	/** Familia a la que pertenece un socio, o null. */
	public static function de_socio( string $num_asde ): ?array {
		global $wpdb;
		$id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT familia_id FROM ' . self::table_miembros() . ' WHERE num_socio_asde = %s', $num_asde ) ); // phpcs:ignore WordPress.DB
		return $id ? self::obtener( $id ) : null;
	}

	/** @return array<int, array{id:int, numero:string, miembros:string[]}> Todas, por número. */
	public static function todas(): array {
		global $wpdb;
		$familias = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT id, numero FROM ' . self::table() . ' WHERE grupo = %s ORDER BY numero', Grupo::numero() ), ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB
			$familias[ (int) $r['id'] ] = array(
				'id'       => (int) $r['id'],
				'numero'   => (string) $r['numero'],
				'miembros' => array(),
			);
		}
		foreach ( (array) $wpdb->get_results( 'SELECT familia_id, num_socio_asde FROM ' . self::table_miembros() . ' ORDER BY creado', ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB
			if ( isset( $familias[ (int) $r['familia_id'] ] ) ) {
				$familias[ (int) $r['familia_id'] ]['miembros'][] = (string) $r['num_socio_asde'];
			}
		}
		return $familias;
	}

	/** @return array<string, int> nº Scouts de España => id de familia */
	public static function mapa_socios(): array {
		global $wpdb;
		$out = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT m.num_socio_asde, m.familia_id FROM ' . self::table_miembros() . ' m INNER JOIN ' . self::table() . ' f ON f.id = m.familia_id WHERE f.grupo = %s', Grupo::numero() ), ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB
			$out[ (string) $r['num_socio_asde'] ] = (int) $r['familia_id'];
		}
		return $out;
	}

	/**
	 * Crea una familia con estos socios (que no pueden estar en otra).
	 *
	 * @param string[] $nums
	 * @return int|\WP_Error Id de la familia.
	 */
	public static function crear( array $nums ) {
		global $wpdb;
		$nums = array_values( array_unique( array_filter( array_map( 'strval', $nums ) ) ) );
		if ( ! $nums ) {
			return new \WP_Error( 'asde_familia', asde_t( 'Elige al menos un socio.' ) );
		}
		foreach ( $nums as $n ) {
			if ( self::de_socio( $n ) ) {
				return new \WP_Error( 'asde_familia', sprintf( asde_t( 'El socio con nº Scouts de España %s ya pertenece a otra familia.' ), $n ) );
			}
		}
		$grupo = Grupo::numero();
		if ( '' === $grupo ) {
			return new \WP_Error( 'asde_familia', asde_t( 'No se sabe en qué Grupo Scout se está trabajando.' ) );
		}
		$wpdb->query( "SELECT GET_LOCK('asde_num_familia', 10)" );
		try {
			$ahora = current_time( 'mysql', true );
			for ( $i = 0; $i < 5; $i++ ) {
				$ok = $wpdb->insert(
					self::table(),
					array(
						'numero'      => self::siguiente_numero( $grupo ),
						'grupo'       => $grupo,
						'creado'      => $ahora,
						'actualizado' => $ahora,
					)
				);
				if ( $ok ) {
					break;
				}
			}
			$id = (int) $wpdb->insert_id;
		} finally {
			$wpdb->query( "SELECT RELEASE_LOCK('asde_num_familia')" );
		}
		if ( ! $id ) {
			return new \WP_Error( 'asde_familia', asde_t( 'No se ha podido crear la familia. Inténtalo de nuevo.' ) );
		}
		foreach ( $nums as $n ) {
			self::anadir( $id, $n );
		}
		return $id;
	}

	/** Añade un socio a una familia. @return true|\WP_Error */
	public static function anadir( int $familia_id, string $num_asde ) {
		global $wpdb;
		$actual = self::de_socio( $num_asde );
		if ( $actual ) {
			return $actual['id'] === $familia_id ? true : new \WP_Error( 'asde_familia', sprintf( asde_t( 'Ese socio ya pertenece a la familia %s.' ), $actual['numero'] ) );
		}
		$wpdb->insert(
			self::table_miembros(),
			array(
				'num_socio_asde' => $num_asde,
				'familia_id'     => $familia_id,
				'creado'         => current_time( 'mysql', true ),
			)
		);
		self::tocar( $familia_id );
		return true;
	}

	public static function quitar( int $familia_id, string $num_asde ): void {
		global $wpdb;
		$wpdb->delete(
			self::table_miembros(),
			array(
				'num_socio_asde' => $num_asde,
				'familia_id'     => $familia_id,
			)
		);
		self::tocar( $familia_id );
	}

	/** Borra una familia vacía y sin movimientos. @return true|\WP_Error */
	public static function borrar( int $familia_id ) {
		global $wpdb;
		$f = self::obtener( $familia_id );
		if ( ! $f ) {
			return new \WP_Error( 'asde_familia', asde_t( 'Esa familia no existe.' ) );
		}
		if ( $f['miembros'] ) {
			return new \WP_Error( 'asde_familia', asde_t( 'Solo se puede borrar una familia sin socios.' ) );
		}
		if ( MovimientosTable::hay_de_familia( $familia_id ) ) {
			return new \WP_Error( 'asde_familia', asde_t( 'La familia tiene pagos registrados: no se puede borrar.' ) );
		}
		$wpdb->delete( self::table(), array( 'id' => $familia_id ) );
		return true;
	}

	/**
	 * Familia para estos socios: si alguno ya tiene, se amplía esa; si no, se crea.
	 * (Se usa al dar de alta una cuenta familiar.)
	 *
	 * @return int|\WP_Error
	 */
	public static function asegurar( array $nums ) {
		foreach ( $nums as $n ) {
			$f = self::de_socio( (string) $n );
			if ( $f ) {
				foreach ( $nums as $otro ) {
					$r = self::anadir( $f['id'], (string) $otro );
					if ( is_wp_error( $r ) ) {
						return $r;
					}
				}
				return $f['id'];
			}
		}
		return self::crear( $nums );
	}

	/** F + grupo + correlativo de 3 cifras (F684001). */
	public static function siguiente_numero( string $grupo ): string {
		global $wpdb;
		$prefijo = 'F' . $grupo;
		$max     = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(CAST(SUBSTRING(numero, %d) AS UNSIGNED)) FROM ' . self::table() . ' WHERE numero LIKE %s', strlen( $prefijo ) + 1, $wpdb->esc_like( $prefijo ) . '%' ) ); // phpcs:ignore WordPress.DB
		return $prefijo . str_pad( (string) ( $max + 1 ), 3, '0', STR_PAD_LEFT );
	}

	private static function tocar( int $familia_id ): void {
		global $wpdb;
		$wpdb->update( self::table(), array( 'actualizado' => current_time( 'mysql', true ) ), array( 'id' => $familia_id ) );
	}
}
