<?php
namespace AsdeBalears\AreaPrivada\Menus;

use AsdeBalears\AreaPrivada\Domain\Grupo;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- tablas propias del plugin ({prefix}asde_*): no hay función de WordPress para ellas y los datos cambian en cada petición, no se cachean.

/**
 * Recetas ({prefix}asde_menu_recetas) y sus líneas ({prefix}asde_menu_receta_lineas), doc 49, B1.
 *
 * Una receta no está asociada a ninguna persona ni a ningún evento: solo lleva sus ingredientes y, de ahí, sus alérgenos
 * (que se calculan, no se guardan). Las de la biblioteca común (grupo «*») las ven todos los grupos; cada grupo las copia
 * a las suyas y las edita sin que cambie la común.
 */
final class RecetasTable {

	public const TIPOS = array(
		'desayuno' => 'Desayuno',
		'entrante' => 'Entrante',
		'primero'  => 'Primer plato',
		'segundo'  => 'Segundo plato',
		'postre'   => 'Postre',
		'merienda' => 'Merienda',
		'otro'     => 'Otro',
	);

	/** Líneas como mucho por receta. */
	public const MAX_LINEAS = 40;

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'asde_menu_recetas';
	}

	public static function table_lineas(): string {
		global $wpdb;
		return $wpdb->prefix . 'asde_menu_receta_lineas';
	}

	public static function install_table(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$t       = self::table();
		$l       = self::table_lineas();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE $t (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				grupo varchar(3) NOT NULL,
				nombre varchar(160) NOT NULL,
				tipo varchar(12) NOT NULL DEFAULT 'otro',
				porciones smallint(5) unsigned NOT NULL DEFAULT 10,
				elaboracion text NULL,
				observaciones text NULL,
				ejemplo tinyint(1) NOT NULL DEFAULT 0,
				origen_id bigint(20) unsigned NOT NULL DEFAULT 0,
				activa tinyint(1) NOT NULL DEFAULT 1,
				creado datetime NOT NULL,
				creado_por bigint(20) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				KEY grupo_nombre (grupo, nombre)
			) $charset;"
		);
		dbDelta(
			"CREATE TABLE $l (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				receta_id bigint(20) unsigned NOT NULL,
				ingrediente_id bigint(20) unsigned NOT NULL,
				cantidad decimal(10,3) NOT NULL DEFAULT 0,
				unidad varchar(6) NOT NULL DEFAULT 'g',
				orden smallint(5) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				KEY receta (receta_id),
				KEY ingrediente (ingrediente_id)
			) $charset;"
		);
	}

	/** @return array[] Las del grupo y las comunes (activas o también archivadas), por nombre. */
	public static function visibles( bool $archivadas = false ): array {
		global $wpdb;
		$filas = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE grupo IN ( %s, %s ) AND ( activa = 1 OR %d = 1 ) ORDER BY nombre', self::table(), Grupo::numero(), IngredientesTable::COMUN, $archivadas ? 1 : 0 ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return array_map( array( self::class, 'preparar' ), $filas );
	}

	/** Receta visible para el grupo actual (propia o común), o null. */
	public static function obtener( int $id ): ?array {
		global $wpdb;
		$f = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d AND grupo IN ( %s, %s )', self::table(), $id, Grupo::numero(), IngredientesTable::COMUN ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return $f ? self::preparar( $f ) : null;
	}

	/** @return array[] Las líneas de una receta, con su ingrediente (clave «ingrediente»; null si ya no es visible). */
	public static function lineas( int $receta_id ): array {
		global $wpdb;
		$filas = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE receta_id = %d ORDER BY orden, id', self::table_lineas(), $receta_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$out   = array();
		foreach ( $filas as $f ) {
			$out[] = array(
				'id'             => (int) $f['id'],
				'ingrediente_id' => (int) $f['ingrediente_id'],
				'cantidad'       => (float) $f['cantidad'],
				'unidad'         => (string) $f['unidad'],
				'ingrediente'    => IngredientesTable::obtener( (int) $f['ingrediente_id'] ),
			);
		}
		return $out;
	}

	/**
	 * Crea o actualiza una receta del grupo con sus líneas (las comunes no se tocan).
	 *
	 * @param array[] $lineas Cada una: ingrediente_id, cantidad, unidad.
	 * @return int|\WP_Error Id.
	 */
	public static function guardar( array $d, array $lineas, int $id = 0, string $grupo = '' ) {
		global $wpdb;
		$grupo  = '' !== $grupo ? $grupo : Grupo::numero();
		$nombre = mb_substr( trim( sanitize_text_field( (string) ( $d['nombre'] ?? '' ) ) ), 0, 160 );
		if ( '' === $nombre ) {
			return new \WP_Error( 'asde_menus', asde_t( 'Escribe el nombre de la receta.' ) );
		}
		$porciones = (int) ( $d['porciones'] ?? 0 );
		if ( $porciones < 1 || $porciones > 500 ) {
			return new \WP_Error( 'asde_menus', asde_t( 'Las porciones base tienen que estar entre 1 y 500.' ) );
		}
		$limpias = array();
		foreach ( $lineas as $l ) {
			$ing      = (int) ( $l['ingrediente_id'] ?? 0 );
			$cantidad = round( (float) ( $l['cantidad'] ?? 0 ), 3 );
			if ( ! $ing && $cantidad <= 0 ) {
				continue; // Fila vacía del formulario.
			}
			if ( ! IngredientesTable::obtener( $ing ) ) {
				return new \WP_Error( 'asde_menus', asde_t( 'Un ingrediente de la lista ya no existe. Elige otro.' ) );
			}
			if ( $cantidad <= 0 || $cantidad > 99999 ) {
				return new \WP_Error( 'asde_menus', asde_t( 'Cada ingrediente necesita una cantidad mayor que cero.' ) );
			}
			$limpias[] = array(
				'ingrediente_id' => $ing,
				'cantidad'       => $cantidad,
				'unidad'         => Escalado::unidad_valida( (string) ( $l['unidad'] ?? '' ) ) ? (string) $l['unidad'] : 'g',
			);
		}
		if ( ! $limpias ) {
			return new \WP_Error( 'asde_menus', asde_t( 'Añade al menos un ingrediente.' ) );
		}
		if ( count( $limpias ) > self::MAX_LINEAS ) {
			return new \WP_Error( 'asde_menus', sprintf( asde_t( 'Una receta puede llevar como mucho %d ingredientes.' ), self::MAX_LINEAS ) );
		}
		$fila = array(
			'nombre'        => $nombre,
			'tipo'          => isset( self::TIPOS[ $d['tipo'] ?? '' ] ) ? (string) $d['tipo'] : 'otro',
			'porciones'     => $porciones,
			'elaboracion'   => mb_substr( sanitize_textarea_field( (string) ( $d['elaboracion'] ?? '' ) ), 0, 4000 ),
			'observaciones' => mb_substr( sanitize_textarea_field( (string) ( $d['observaciones'] ?? '' ) ), 0, 1000 ),
		);
		if ( $id ) {
			$actual = self::obtener( $id );
			if ( ! $actual || $actual['grupo'] !== $grupo ) {
				return new \WP_Error( 'asde_menus', asde_t( 'Esa receta es de la biblioteca común: cópiala a tu grupo para editarla.' ) );
			}
			$wpdb->update( self::table(), $fila, array( 'id' => $id ) ); // phpcs:ignore WordPress.DB
		} else {
			$wpdb->insert( self::table(), $fila + array( 'grupo' => $grupo, 'ejemplo' => ! empty( $d['ejemplo'] ) ? 1 : 0, 'origen_id' => (int) ( $d['origen_id'] ?? 0 ), 'creado' => current_time( 'mysql', true ), 'creado_por' => get_current_user_id() ) ); // phpcs:ignore WordPress.DB
			$id = (int) $wpdb->insert_id;
		}
		$wpdb->delete( self::table_lineas(), array( 'receta_id' => $id ) ); // phpcs:ignore WordPress.DB
		foreach ( $limpias as $orden => $l ) {
			$wpdb->insert( self::table_lineas(), $l + array( 'receta_id' => $id, 'orden' => $orden ) ); // phpcs:ignore WordPress.DB
		}
		return $id;
	}

	/** Archiva o recupera una receta del grupo. */
	public static function activar( int $id, bool $activa ): bool {
		global $wpdb;
		return (bool) $wpdb->update( self::table(), array( 'activa' => $activa ? 1 : 0 ), array( 'id' => $id, 'grupo' => Grupo::numero() ) ); // phpcs:ignore WordPress.DB
	}

	private static function preparar( array $f ): array {
		$f['id']            = (int) $f['id'];
		$f['porciones']     = (int) $f['porciones'];
		$f['ejemplo']       = (bool) (int) $f['ejemplo'];
		$f['activa']        = (bool) (int) $f['activa'];
		$f['origen_id']     = (int) $f['origen_id'];
		$f['elaboracion']   = (string) $f['elaboracion'];
		$f['observaciones'] = (string) $f['observaciones'];
		return $f;
	}
}
