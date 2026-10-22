<?php
namespace AsdeBalears\AreaPrivada\Tienda;

use AsdeBalears\AreaPrivada\Domain\Grupo;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- tablas propias del plugin ({prefix}asde_*): no hay función de WordPress para ellas.

/**
 * Pedidos del grupo al proveedor (v0.48, doc 48, T4): cada fila es un pedido que Coordinación ha hecho con lo que los
 * clientes han pagado y la tienda no tiene en existencias. Guarda una copia de las líneas (producto, talla y cantidad
 * pedida y recibida) y los pasos: **pedido hecho → recibido en el grupo**; la entrega al cliente la sigue marcando cada
 * pedido de la tienda.
 */
final class ProveedorTable {

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'asde_tienda_proveedor';
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
				nombre varchar(60) NOT NULL DEFAULT '',
				lineas longtext NOT NULL,
				pedidos int(10) unsigned NOT NULL DEFAULT 0,
				creado datetime NOT NULL,
				creado_por bigint(20) unsigned NOT NULL DEFAULT 0,
				recibido_en datetime NULL,
				recibido_por bigint(20) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				KEY grupo (grupo)
			) $charset;"
		);
	}

	/** @param array[] $lineas Copia de lo pedido (clave, producto, variante, nombre, etiqueta, cantidad, recibida). @return int Id. */
	public static function crear( string $nombre, array $lineas, int $pedidos ): int {
		global $wpdb;
		$wpdb->insert(
			self::table(),
			array(
				'grupo'      => Grupo::numero(),
				'nombre'     => mb_substr( sanitize_text_field( $nombre ), 0, 60 ),
				'lineas'     => (string) wp_json_encode( array_values( $lineas ) ),
				'pedidos'    => $pedidos,
				'creado'     => current_time( 'mysql', true ),
				'creado_por' => get_current_user_id(),
			)
		);
		return (int) $wpdb->insert_id;
	}

	/** Pedido del grupo actual, o null. */
	public static function obtener( int $id ): ?array {
		global $wpdb;
		$fila = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d AND grupo = %s', self::table(), $id, Grupo::numero() ), ARRAY_A );
		return $fila ? self::preparar( $fila ) : null;
	}

	/** @return array[] Los del grupo, los más recientes primero. */
	public static function todos(): array {
		global $wpdb;
		$filas = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE grupo = %s ORDER BY creado DESC, id DESC', self::table(), Grupo::numero() ), ARRAY_A );
		return array_map( array( self::class, 'preparar' ), $filas );
	}

	/** Ids de los pedidos al proveedor que ya han llegado (para no consultar uno por uno). @return array<int, true> */
	public static function llegados(): array {
		global $wpdb;
		$ids = (array) $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i WHERE grupo = %s AND recibido_en IS NOT NULL', self::table(), Grupo::numero() ) );
		return array_fill_keys( array_map( 'intval', $ids ), true );
	}

	/**
	 * Marca que ha llegado, guardando lo recibido de cada línea. Solo si seguía pendiente (una sentencia).
	 *
	 * @param array[] $lineas Las líneas con `recibida` rellenada.
	 */
	public static function recibir( int $id, array $lineas ): bool {
		global $wpdb;
		return 1 === (int) $wpdb->query( $wpdb->prepare( 'UPDATE %i SET lineas = %s, recibido_en = %s, recibido_por = %d WHERE id = %d AND grupo = %s AND recibido_en IS NULL', self::table(), (string) wp_json_encode( array_values( $lineas ) ), current_time( 'mysql', true ), get_current_user_id(), $id, Grupo::numero() ) );
	}

	private static function preparar( array $f ): array {
		$f['id']           = (int) $f['id'];
		$f['pedidos']      = (int) $f['pedidos'];
		$f['creado_por']   = (int) $f['creado_por'];
		$f['recibido_por'] = (int) $f['recibido_por'];
		$lineas            = json_decode( (string) $f['lineas'], true );
		$f['lineas']       = is_array( $lineas ) ? array_values( array_filter( $lineas, 'is_array' ) ) : array();
		return $f;
	}
}
