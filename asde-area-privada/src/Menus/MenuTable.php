<?php
namespace AsdeBalears\AreaPrivada\Menus;

use AsdeBalears\AreaPrivada\Domain\Grupo;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- tablas propias del plugin ({prefix}asde_*): no hay función de WordPress para ellas y los datos cambian en cada petición, no se cachean.

/**
 * Las comidas del menú de un evento ({prefix}asde_evento_menu, doc 49, B2): para cada día y cada comida, una o varias recetas
 * y, si hace falta, un nº de comensales propio. Sin datos de personas.
 */
final class MenuTable {

	public const COMIDAS = array(
		'desayuno' => 'Desayuno',
		'comida'   => 'Comida',
		'merienda' => 'Merienda',
		'cena'     => 'Cena',
	);

	/** Platos como mucho por evento. */
	public const MAX = 300;

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'asde_evento_menu';
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
				evento_id bigint(20) unsigned NOT NULL,
				dia date NOT NULL,
				comida varchar(10) NOT NULL,
				receta_id bigint(20) unsigned NOT NULL,
				comensales smallint(5) unsigned NULL,
				creado datetime NOT NULL,
				creado_por bigint(20) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				KEY grupo_evento (grupo, evento_id)
			) $charset;"
		);
	}

	/** @return array[] Los platos del evento, por día y comida. */
	public static function de_evento( int $evento_id ): array {
		global $wpdb;
		$filas = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE evento_id = %d AND grupo = %s ORDER BY dia, FIELD( comida, %s, %s, %s, %s ), id', self::table(), $evento_id, Grupo::numero(), 'desayuno', 'comida', 'merienda', 'cena' ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return array_map( array( self::class, 'preparar' ), $filas );
	}

	public static function obtener( int $id ): ?array {
		global $wpdb;
		$f = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d AND grupo = %s', self::table(), $id, Grupo::numero() ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return $f ? self::preparar( $f ) : null;
	}

	public static function contar( int $evento_id ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE evento_id = %d AND grupo = %s', self::table(), $evento_id, Grupo::numero() ) ); // phpcs:ignore WordPress.DB
	}

	public static function anadir( int $evento_id, string $dia, string $comida, int $receta_id, ?int $comensales ): int {
		global $wpdb;
		$wpdb->insert( // phpcs:ignore WordPress.DB
			self::table(),
			array(
				'grupo'      => Grupo::numero(),
				'evento_id'  => $evento_id,
				'dia'        => $dia,
				'comida'     => $comida,
				'receta_id'  => $receta_id,
				'comensales' => $comensales,
				'creado'     => current_time( 'mysql', true ),
				'creado_por' => get_current_user_id(),
			)
		);
		return (int) $wpdb->insert_id;
	}

	public static function quitar( int $id, int $evento_id ): bool {
		global $wpdb;
		return (bool) $wpdb->delete( self::table(), array( 'id' => $id, 'evento_id' => $evento_id, 'grupo' => Grupo::numero() ) ); // phpcs:ignore WordPress.DB
	}

	public static function comensales( int $id, int $evento_id, ?int $comensales ): bool {
		global $wpdb;
		return false !== $wpdb->update( self::table(), array( 'comensales' => $comensales ), array( 'id' => $id, 'evento_id' => $evento_id, 'grupo' => Grupo::numero() ) ); // phpcs:ignore WordPress.DB
	}

	public static function borrar_evento( int $evento_id ): void {
		global $wpdb;
		$wpdb->delete( self::table(), array( 'evento_id' => $evento_id, 'grupo' => Grupo::numero() ) ); // phpcs:ignore WordPress.DB
	}

	private static function preparar( array $f ): array {
		$f['id']         = (int) $f['id'];
		$f['receta_id']  = (int) $f['receta_id'];
		$f['comensales'] = null === $f['comensales'] ? null : (int) $f['comensales'];
		return $f;
	}
}
