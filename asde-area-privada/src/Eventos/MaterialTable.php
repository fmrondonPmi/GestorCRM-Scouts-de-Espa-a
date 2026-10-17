<?php
namespace AsdeBalears\AreaPrivada\Eventos;

use AsdeBalears\AreaPrivada\Domain\Grupo;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- tablas propias del plugin ({prefix}asde_*): no hay función de WordPress para ellas y los datos cambian en cada petición, no se cachean.

/**
 * El material de un evento ({prefix}asde_evento_material, v0.42): lo que se lleva del inventario del grupo, cuándo sale y cómo vuelve.
 *
 * Cada línea reserva unidades de un material del inventario (que sigue siendo permanente: reservar no lo descuenta). Guarda una copia
 * de la descripción por si luego se da de baja o se cambia. Sin datos personales: de quién lo saca o lo devuelve, solo el id de la cuenta.
 */
final class MaterialTable {

	/** Líneas como mucho por evento. */
	public const MAX = 150;

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'asde_evento_material';
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
				material_id bigint(20) unsigned NOT NULL,
				descripcion varchar(255) NOT NULL DEFAULT '',
				cantidad int(11) NOT NULL DEFAULT 1,
				salida_en datetime NULL,
				salida_por bigint(20) unsigned NOT NULL DEFAULT 0,
				devueltas int(11) NULL,
				devuelta_en datetime NULL,
				devuelta_por bigint(20) unsigned NOT NULL DEFAULT 0,
				incidencia varchar(200) NOT NULL DEFAULT '',
				descontado tinyint(1) NOT NULL DEFAULT 0,
				creado datetime NOT NULL,
				creado_por bigint(20) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				UNIQUE KEY evento_material (evento_id, material_id),
				KEY grupo_evento (grupo, evento_id),
				KEY material (material_id)
			) $charset;"
		);
	}

	/** @return array[] Las líneas del evento, por descripción. */
	public static function de_evento( int $evento_id ): array {
		global $wpdb;
		$filas = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE evento_id = %d AND grupo = %s ORDER BY descripcion, id', self::table(), $evento_id, Grupo::numero() ), ARRAY_A ); // phpcs:ignore WordPress.DB
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

	/** Reserva (o suma a lo ya reservado). */
	public static function reservar( int $evento_id, int $material_id, string $descripcion, int $cantidad ): void {
		global $wpdb;
		$wpdb->query( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				'INSERT INTO %i (grupo, evento_id, material_id, descripcion, cantidad, creado, creado_por) VALUES (%s, %d, %d, %s, %d, %s, %d) ON DUPLICATE KEY UPDATE cantidad = cantidad + VALUES(cantidad)',
				self::table(),
				Grupo::numero(),
				$evento_id,
				$material_id,
				mb_substr( $descripcion, 0, 255 ),
				$cantidad,
				current_time( 'mysql', true ),
				get_current_user_id()
			)
		);
	}

	public static function actualizar( int $id, array $datos ): bool {
		global $wpdb;
		return false !== $wpdb->update( self::table(), $datos, array( 'id' => $id, 'grupo' => Grupo::numero() ) ); // phpcs:ignore WordPress.DB
	}

	public static function quitar( int $id, int $evento_id ): bool {
		global $wpdb;
		return (bool) $wpdb->delete( self::table(), array( 'id' => $id, 'evento_id' => $evento_id, 'grupo' => Grupo::numero() ) ); // phpcs:ignore WordPress.DB
	}

	public static function borrar_evento( int $evento_id ): void {
		global $wpdb;
		$wpdb->delete( self::table(), array( 'evento_id' => $evento_id, 'grupo' => Grupo::numero() ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Unidades de este material que reservan OTROS eventos que coinciden en fechas con este y aún no las han devuelto (los cancelados
	 * no cuentan).
	 *
	 * @param array $e Evento (inicio y fin).
	 * @return array<int, int> material_id => unidades
	 */
	public static function reservado_por_otros( array $e ): array {
		global $wpdb;
		$filas = (array) $wpdb->get_results( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				'SELECT m.material_id, SUM(m.cantidad) AS n FROM %i m INNER JOIN %i ev ON ev.id = m.evento_id WHERE m.grupo = %s AND m.evento_id <> %d AND m.devuelta_en IS NULL AND ev.estado <> %s AND ev.inicio <= %s AND ev.fin >= %s GROUP BY m.material_id',
				self::table(),
				EventosTable::table(),
				Grupo::numero(),
				$e['id'],
				EventosTable::CANCELADO,
				$e['fin'],
				$e['inicio']
			),
			ARRAY_A
		);
		$out = array();
		foreach ( $filas as $f ) {
			$out[ (int) $f['material_id'] ] = (int) $f['n'];
		}
		return $out;
	}

	private static function preparar( array $f ): array {
		foreach ( array( 'id', 'evento_id', 'material_id', 'cantidad', 'salida_por', 'devuelta_por' ) as $k ) {
			$f[ $k ] = (int) $f[ $k ];
		}
		$f['devueltas']  = null === $f['devueltas'] ? null : (int) $f['devueltas'];
		$f['descontado'] = (bool) (int) $f['descontado'];
		return $f;
	}
}
