<?php
namespace AsdeBalears\AreaPrivada\Inventario;

use AsdeBalears\AreaPrivada\Domain\Grupo;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- tablas propias del plugin ({prefix}asde_*): no hay función de WordPress para ellas y los datos cambian en cada petición, no se cachean.

/**
 * Tipos de material del inventario de cada grupo ({prefix}asde_inventario_tipos).
 *
 * El nombre es único en el grupo sin distinguir mayúsculas ni acentos («Tiendas»
 * y «tiendas» son el mismo). Un tipo que usa algún material no se borra.
 *
 * v0.29: el tipo «Camisa uniforme» (especial = camisa) lo crea el plugin en cada grupo
 * y no se borra: sus materiales llevan modelo y talla, y la gestión de uniformes los
 * asigna y descuenta (MaterialesTable::descontar_camisa()).
 */
final class TiposTable {

	public const MAX_NOMBRE = 80;

	/** Marca del tipo de las camisas del uniforme (columna «especial»). */
	public const CAMISA = 'camisa';

	/** Nombre con el que se crea (se puede cambiar; lo que cuenta es la marca). */
	public const NOMBRE_CAMISA = 'Camisa uniforme';

	/** @var array<string, array> Los tipos de cada grupo en esta petición (se olvida al cambiarlos). */
	private static array $cache = array();

	public static function olvidar(): void {
		self::$cache = array();
	}

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'asde_inventario_tipos';
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
				especial varchar(20) NOT NULL DEFAULT '',
				creado datetime NOT NULL,
				creado_por bigint(20) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				KEY grupo (grupo)
			) $charset;"
		);
	}

	/** Para comparar nombres: sin mayúsculas, acentos ni espacios de más. */
	public static function clave( string $nombre ): string {
		return mb_strtolower( remove_accents( preg_replace( '/\s+/u', ' ', trim( $nombre ) ) ?? '' ) );
	}

	/** @return array<int, array{id:int, nombre:string, especial:string}> id => tipo, por orden alfabético. */
	public static function todos(): array {
		if ( isset( self::$cache[ Grupo::numero() ] ) ) {
			return self::$cache[ Grupo::numero() ];
		}
		global $wpdb;
		$filas = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT id, nombre, especial FROM %i WHERE grupo = %s', self::table(), Grupo::numero() ), ARRAY_A );
		$out   = array();
		foreach ( $filas as $f ) {
			$out[ (int) $f['id'] ] = array(
				'id'       => (int) $f['id'],
				'nombre'   => (string) $f['nombre'],
				'especial' => (string) ( $f['especial'] ?? '' ),
			);
		}
		uasort( $out, static fn( $a, $b ) => strcmp( self::clave( $a['nombre'] ), self::clave( $b['nombre'] ) ) );
		self::$cache[ Grupo::numero() ] = $out;
		return $out;
	}

	public static function obtener( int $id ): ?array {
		return self::todos()[ $id ] ?? null;
	}

	/**
	 * Id del tipo de las camisas del uniforme del grupo (v0.29). Si no existe, lo crea; si ya
	 * había un tipo con ese nombre (hecho a mano), lo marca. 0 si no se ha podido crear.
	 */
	public static function camisa(): int {
		foreach ( self::todos() as $id => $t ) {
			if ( self::CAMISA === $t['especial'] ) {
				return $id;
			}
		}
		$id = self::por_nombre( self::NOMBRE_CAMISA );
		if ( ! $id ) {
			$id = self::crear( self::NOMBRE_CAMISA );
			if ( is_wp_error( $id ) ) {
				return 0;
			}
		}
		global $wpdb;
		$wpdb->update(
			self::table(),
			array( 'especial' => self::CAMISA ),
			array(
				'id'    => $id,
				'grupo' => Grupo::numero(),
			)
		);
		self::olvidar();
		return (int) $id;
	}

	public static function es_camisa( int $id ): bool {
		return $id > 0 && self::CAMISA === ( self::obtener( $id )['especial'] ?? '' );
	}

	/** Id del tipo con ese nombre en el grupo, o 0. */
	public static function por_nombre( string $nombre, int $salvo = 0 ): int {
		$clave = self::clave( $nombre );
		foreach ( self::todos() as $id => $t ) {
			if ( $id !== $salvo && self::clave( $t['nombre'] ) === $clave ) {
				return $id;
			}
		}
		return 0;
	}

	/** @return int|\WP_Error Id del tipo creado. */
	public static function crear( string $nombre ) {
		$nombre = self::limpiar( $nombre );
		if ( '' === $nombre ) {
			return new \WP_Error( 'asde_inventario', asde_t( 'Escribe el nombre del tipo.' ) );
		}
		if ( self::por_nombre( $nombre ) ) {
			return new \WP_Error( 'asde_inventario', sprintf( asde_t( 'Ya hay un tipo «%s».' ), $nombre ) );
		}
		global $wpdb;
		$wpdb->insert(
			self::table(),
			array(
				'grupo'      => Grupo::numero(),
				'nombre'     => $nombre,
				'creado'     => current_time( 'mysql', true ),
				'creado_por' => get_current_user_id(),
			)
		);
		self::olvidar();
		return (int) $wpdb->insert_id;
	}

	/** @return true|\WP_Error */
	public static function renombrar( int $id, string $nombre ) {
		$nombre = self::limpiar( $nombre );
		if ( ! self::obtener( $id ) ) {
			return new \WP_Error( 'asde_inventario', asde_t( 'Ese tipo no existe.' ) );
		}
		if ( '' === $nombre ) {
			return new \WP_Error( 'asde_inventario', asde_t( 'Escribe el nombre del tipo.' ) );
		}
		if ( self::por_nombre( $nombre, $id ) ) {
			return new \WP_Error( 'asde_inventario', sprintf( asde_t( 'Ya hay un tipo «%s».' ), $nombre ) );
		}
		global $wpdb;
		$wpdb->update(
			self::table(),
			array( 'nombre' => $nombre ),
			array(
				'id'    => $id,
				'grupo' => Grupo::numero(),
			)
		);
		self::olvidar();
		return true;
	}

	/** @return true|\WP_Error Solo se borra si ningún material (ni de baja) lo usa. */
	public static function borrar( int $id ) {
		$tipo = self::obtener( $id );
		if ( ! $tipo ) {
			return new \WP_Error( 'asde_inventario', asde_t( 'Ese tipo no existe.' ) );
		}
		if ( self::CAMISA === $tipo['especial'] ) {
			return new \WP_Error( 'asde_inventario', asde_t( 'Es el tipo de las camisas del uniforme: no se puede borrar (lo usa la gestión de uniformes).' ) );
		}
		$usos = MaterialesTable::contar_por_tipo()[ $id ] ?? 0;
		if ( $usos ) {
			/* translators: 1: nombre del tipo, 2: número de materiales */
			return new \WP_Error( 'asde_inventario', sprintf( asde_n( 'El tipo «%1$s» lo usa %2$d material: cámbialo de tipo antes de borrarlo.', 'El tipo «%1$s» lo usan %2$d materiales: cámbialos de tipo antes de borrarlo.', $usos ), $tipo['nombre'], $usos ) );
		}
		global $wpdb;
		$wpdb->delete(
			self::table(),
			array(
				'id'    => $id,
				'grupo' => Grupo::numero(),
			)
		);
		self::olvidar();
		return true;
	}

	private static function limpiar( string $nombre ): string {
		return mb_substr( trim( preg_replace( '/\s+/u', ' ', sanitize_text_field( $nombre ) ) ?? '' ), 0, self::MAX_NOMBRE );
	}
}
