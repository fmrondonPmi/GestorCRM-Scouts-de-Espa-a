<?php
namespace AsdeBalears\AreaPrivada\Menus;

use AsdeBalears\AreaPrivada\Domain\Grupo;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- tablas propias del plugin ({prefix}asde_*): no hay función de WordPress para ellas y los datos cambian en cada petición, no se cachean.

/**
 * Ingredientes ({prefix}asde_menu_ingredientes, doc 49, B1). Llevan sus alérgenos (que contiene) y sus trazas (que puede
 * contener); las recetas los heredan. Los de la biblioteca común (grupo «*») los ven todos los grupos y no se editan;
 * los de cada grupo son suyos. No guarda datos de personas.
 */
final class IngredientesTable {

	/** «Grupo» de lo que viene incluido en el plugin (biblioteca común). */
	public const COMUN = '*';

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'asde_menu_ingredientes';
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
				nombre varchar(120) NOT NULL,
				unidad varchar(6) NOT NULL DEFAULT 'g',
				alergenos varchar(200) NOT NULL DEFAULT '',
				trazas varchar(200) NOT NULL DEFAULT '',
				animal tinyint(1) NOT NULL DEFAULT 0,
				coste decimal(10,4) NULL,
				revisado tinyint(1) NOT NULL DEFAULT 0,
				activo tinyint(1) NOT NULL DEFAULT 1,
				creado datetime NOT NULL,
				creado_por bigint(20) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				KEY grupo_nombre (grupo, nombre)
			) $charset;"
		);
	}

	/** @return array[] Los del grupo y los comunes (activos o también archivados), por nombre. */
	public static function visibles( bool $archivados = false ): array {
		global $wpdb;
		$filas = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE grupo IN ( %s, %s ) AND ( activo = 1 OR %d = 1 ) ORDER BY nombre', self::table(), Grupo::numero(), self::COMUN, $archivados ? 1 : 0 ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return array_map( array( self::class, 'preparar' ), $filas );
	}

	/** Ingrediente visible para el grupo actual (propio o común), o null. */
	public static function obtener( int $id ): ?array {
		global $wpdb;
		$f = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d AND grupo IN ( %s, %s )', self::table(), $id, Grupo::numero(), self::COMUN ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return $f ? self::preparar( $f ) : null;
	}

	/** @param int[] $ids @return array<int, array> id => ingrediente (los visibles). */
	public static function varios( array $ids ): array {
		$out = array();
		foreach ( array_unique( array_filter( array_map( 'intval', $ids ) ) ) as $id ) {
			$i = self::obtener( $id );
			if ( $i ) {
				$out[ $id ] = $i;
			}
		}
		return $out;
	}

	/** El ingrediente de este nombre (sin mayúsculas ni acentos): primero el del grupo y, si no, el común. */
	public static function por_nombre( string $nombre ): ?array {
		$clave = Conversion::clave( $nombre );
		$mio   = null;
		$comun = null;
		foreach ( self::visibles( true ) as $i ) {
			if ( Conversion::clave( $i['nombre'] ) === $clave ) {
				if ( self::COMUN === $i['grupo'] ) {
					$comun = $comun ?? $i;
				} else {
					$mio = $mio ?? $i;
				}
			}
		}
		return $mio ?? $comun;
	}

	/**
	 * Crea o actualiza un ingrediente del grupo (los comunes no se tocan).
	 *
	 * @return int|\WP_Error Id.
	 */
	public static function guardar( array $d, int $id = 0, string $grupo = '' ) {
		global $wpdb;
		$grupo  = '' !== $grupo ? $grupo : Grupo::numero();
		$nombre = mb_substr( trim( sanitize_text_field( (string) ( $d['nombre'] ?? '' ) ) ), 0, 120 );
		if ( '' === $nombre ) {
			return new \WP_Error( 'asde_menus', asde_t( 'Escribe el nombre del ingrediente.' ) );
		}
		$unidad = Escalado::unidad_valida( (string) ( $d['unidad'] ?? '' ) ) ? (string) $d['unidad'] : 'g';
		$coste  = isset( $d['coste'] ) && '' !== $d['coste'] && null !== $d['coste'] ? max( 0.0, round( (float) $d['coste'], 4 ) ) : null;
		$fila   = array(
			'nombre'    => $nombre,
			'unidad'    => $unidad,
			'alergenos' => implode( ',', Alergenos::limpiar( $d['alergenos'] ?? array() ) ),
			'trazas'    => implode( ',', Alergenos::limpiar( $d['trazas'] ?? array() ) ),
			'animal'    => ! empty( $d['animal'] ) ? 1 : 0,
			'coste'     => $coste,
			'revisado'  => ! empty( $d['revisado'] ) ? 1 : 0,
		);
		if ( $id ) {
			$actual = self::obtener( $id );
			if ( ! $actual || $actual['grupo'] !== $grupo ) {
				return new \WP_Error( 'asde_menus', asde_t( 'Ese ingrediente no es del grupo y no se puede cambiar.' ) );
			}
			$wpdb->update( self::table(), $fila, array( 'id' => $id ) ); // phpcs:ignore WordPress.DB
			return $id;
		}
		$otro = self::por_nombre( $nombre );
		if ( $otro && $otro['grupo'] === $grupo ) {
			return new \WP_Error( 'asde_menus', asde_t( 'Ya hay un ingrediente con ese nombre.' ) );
		}
		$wpdb->insert( self::table(), $fila + array( 'grupo' => $grupo, 'creado' => current_time( 'mysql', true ), 'creado_por' => get_current_user_id() ) ); // phpcs:ignore WordPress.DB
		return (int) $wpdb->insert_id;
	}

	/** Archiva o recupera un ingrediente del grupo. */
	public static function activar( int $id, bool $activo ): bool {
		global $wpdb;
		return (bool) $wpdb->update( self::table(), array( 'activo' => $activo ? 1 : 0 ), array( 'id' => $id, 'grupo' => Grupo::numero() ) ); // phpcs:ignore WordPress.DB
	}

	/** ¿Lo usa alguna receta? */
	public static function en_uso( int $id ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(DISTINCT receta_id) FROM %i WHERE ingrediente_id = %d', RecetasTable::table_lineas(), $id ) ); // phpcs:ignore WordPress.DB
	}

	private static function preparar( array $f ): array {
		$f['id']        = (int) $f['id'];
		$f['alergenos'] = Alergenos::limpiar( (string) $f['alergenos'] );
		$f['trazas']    = Alergenos::limpiar( (string) $f['trazas'] );
		$f['animal']    = (bool) (int) $f['animal'];
		$f['revisado']  = (bool) (int) $f['revisado'];
		$f['activo']    = (bool) (int) $f['activo'];
		$f['coste']     = null === $f['coste'] ? null : (float) $f['coste'];
		return $f;
	}
}
