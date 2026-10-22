<?php
namespace AsdeBalears\AreaPrivada\Tienda;

use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Inventario\MaterialesTable;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- tablas propias del plugin ({prefix}asde_*): no hay función de WordPress para ellas y los datos cambian en cada petición, no se cachean.

/**
 * Variantes de los productos de la tienda ({prefix}asde_tienda_variantes) y sus existencias
 * (doc 48, fase 2). Una variante es lo que se compra: «Camisa masculina · M», «Taza azul»…
 * Un producto sin variantes de verdad lleva una sola, «Única».
 *
 * Las existencias se cambian siempre con una sola sentencia SQL (`UPDATE … WHERE existencias >= n`),
 * nunca leyendo y escribiendo, para que dos compras a la vez no vendan lo mismo dos veces:
 *
 *   reservar()   quita unidades al comprar (falla si no quedan);
 *   devolver()   las devuelve al cancelar;
 *   ajustar()    cambio manual de la administración, con motivo (queda en la auditoría).
 *
 * v0.51 (doc 55): una variante puede estar **enlazada a una fila del inventario general** (`material`): sus existencias
 * son la cantidad de esa fila, se compra aunque no queden (lo que falta va al pedido al proveedor) y se pueden
 * cambiar solo desde el inventario general.
 *
 * `ilimitado` = no se cuentan (un servicio, un pedido que se encarga al proveedor).
 * `ref` guarda de dónde viene una variante sembrada (`masculina|M`).
 */
final class VariantesTable {

	public const MAX_EXISTENCIAS = 99999;

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'asde_tienda_variantes';
	}

	public static function install_table(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$t       = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE $t (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				producto bigint(20) unsigned NOT NULL,
				etiqueta varchar(80) NOT NULL,
				sku varchar(60) NOT NULL DEFAULT '',
				precio decimal(10,2) NULL,
				existencias int(11) NOT NULL DEFAULT 0,
				ilimitado tinyint(1) NOT NULL DEFAULT 0,
				activo tinyint(1) NOT NULL DEFAULT 1,
				orden int(11) NOT NULL DEFAULT 0,
				ref varchar(60) NOT NULL DEFAULT '',
				material bigint(20) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				KEY producto (producto)
			) $charset;"
		);
	}

	/**
	 * Valida una variante.
	 *
	 * @param array<string, string> $d etiqueta, sku, precio, existencias, ilimitado.
	 * @return array|\WP_Error
	 */
	public static function validar( array $d ) {
		$errores  = array();
		$etiqueta = mb_substr( trim( sanitize_text_field( (string) ( $d['etiqueta'] ?? '' ) ) ), 0, 80 );
		if ( '' === $etiqueta ) {
			$etiqueta = 'Única'; // Se guarda en castellano; se traduce al mostrarla.
		}
		$ilimitado = in_array( mb_strtolower( remove_accents( trim( (string) ( $d['ilimitado'] ?? '' ) ) ) ), array( '1', 'si', 's', 'true', 'x', 'yes', 'ilimitado', 'sin limite' ), true ) ? 1 : 0;
		$cantidad  = trim( (string) ( $d['existencias'] ?? '' ) );
		if ( in_array( mb_strtolower( remove_accents( $cantidad ) ), array( 'ilimitado', 'sin limite' ), true ) ) {
			$ilimitado = 1;
			$cantidad  = '0';
		}
		if ( '' === $cantidad ) {
			$cantidad = '0';
		}
		if ( ! preg_match( '/^\d{1,5}$/', $cantidad ) ) {
			/* translators: %d: cantidad máxima */
			$errores[] = sprintf( asde_t( 'Las existencias tienen que ser un número entero de 0 a %d (o «ilimitado»).' ), self::MAX_EXISTENCIAS );
		}
		$precio = ProductosTable::euros( (string) ( $d['precio'] ?? '' ) );
		if ( false === $precio ) {
			$errores[] = asde_t( 'El precio de la variante no es válido (por ejemplo: 12,50).' );
		}
		if ( $errores ) {
			return new \WP_Error( 'asde_tienda', implode( ' ', $errores ) );
		}
		return array(
			'etiqueta'    => $etiqueta,
			'sku'         => mb_substr( trim( sanitize_text_field( (string) ( $d['sku'] ?? '' ) ) ), 0, 60 ),
			'precio'      => $precio,
			'existencias' => (int) $cantidad,
			'ilimitado'   => $ilimitado,
		);
	}

	public static function obtener( int $id ): ?array {
		global $wpdb;
		$fila = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', self::table(), $id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return $fila ? self::preparar( $fila ) : null;
	}

	/** @return array[] Las variantes de un producto (activas y no), por orden. */
	public static function de_producto( int $producto, bool $inactivas = true ): array {
		global $wpdb;
		if ( $inactivas ) {
			$filas = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE producto = %d ORDER BY orden ASC, id ASC', self::table(), $producto ), ARRAY_A ); // phpcs:ignore WordPress.DB
		} else {
			$filas = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE producto = %d AND activo = 1 ORDER BY orden ASC, id ASC', self::table(), $producto ), ARRAY_A ); // phpcs:ignore WordPress.DB
		}
		return array_map( array( self::class, 'preparar' ), $filas );
	}

	/**
	 * Variantes de varios productos de una vez (una consulta).
	 *
	 * @param int[] $productos
	 * @return array<int, array[]> producto => variantes
	 */
	public static function de_productos( array $productos ): array {
		global $wpdb;
		$productos = array_values( array_unique( array_filter( array_map( 'intval', $productos ) ) ) );
		if ( ! $productos ) {
			return array();
		}
		$marcas = implode( ',', array_fill( 0, count( $productos ), '%d' ) );
		$filas  = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE producto IN ($marcas) ORDER BY orden ASC, id ASC", array_merge( array( self::table() ), $productos ) ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$out    = array();
		foreach ( $filas as $f ) {
			$f                    = self::preparar( $f );
			$out[ $f['producto'] ][] = $f;
		}
		return $out;
	}

	/** La variante enlazada a una fila del inventario general, o null. */
	public static function de_material( int $material ): ?array {
		global $wpdb;
		$fila = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE material = %d ORDER BY id ASC LIMIT 1', self::table(), $material ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return $fila ? self::preparar( $fila ) : null;
	}

	/** Variante de un producto por su etiqueta (sin mayúsculas ni acentos), o null. */
	public static function por_etiqueta( int $producto, string $etiqueta ): ?array {
		$clave = \AsdeBalears\AreaPrivada\Inventario\TiposTable::clave( $etiqueta );
		foreach ( self::de_producto( $producto ) as $v ) {
			if ( \AsdeBalears\AreaPrivada\Inventario\TiposTable::clave( $v['etiqueta'] ) === $clave ) {
				return $v;
			}
		}
		return null;
	}

	public static function por_ref( int $producto, string $ref ): ?array {
		foreach ( self::de_producto( $producto ) as $v ) {
			if ( '' !== $ref && $v['ref'] === $ref ) {
				return $v;
			}
		}
		return null;
	}

	/** @param array $datos Lo que devuelve validar(). @return int Id de la variante. */
	public static function crear( int $producto, array $datos, string $ref = '' ): int {
		global $wpdb;
		$orden = 1 + (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(MAX(orden),0) FROM %i WHERE producto = %d', self::table(), $producto ) ); // phpcs:ignore WordPress.DB
		$wpdb->insert(
			self::table(),
			array(
				'producto' => $producto,
				'orden'    => $orden,
				'ref'      => mb_substr( $ref, 0, 60 ),
			) + self::columnas( $datos )
		);
		return (int) $wpdb->insert_id;
	}

	public static function actualizar( int $id, array $datos ): bool {
		global $wpdb;
		return false !== $wpdb->update( self::table(), self::columnas( $datos ), array( 'id' => $id ) );
	}

	public static function activar( int $id, bool $activo ): bool {
		return self::actualizar( $id, array( 'activo' => $activo ? 1 : 0 ) );
	}

	private static function columnas( array $datos ): array {
		$out = array();
		foreach ( array( 'etiqueta', 'sku', 'precio', 'existencias', 'ilimitado', 'activo', 'material' ) as $c ) {
			if ( array_key_exists( $c, $datos ) ) {
				$out[ $c ] = $datos[ $c ];
			}
		}
		return $out;
	}

	private static function preparar( array $f ): array {
		$f['id']          = (int) $f['id'];
		$f['producto']    = (int) $f['producto'];
		$f['precio']      = null === $f['precio'] ? null : (float) $f['precio'];
		$f['existencias'] = (int) $f['existencias'];
		$f['ilimitado']   = (int) $f['ilimitado'];
		$f['activo']      = (int) $f['activo'];
		$f['material']    = (int) ( $f['material'] ?? 0 );
		if ( $f['material'] > 0 ) {
			// Enlazada al inventario general: la cantidad es la de la fila y, si la fila sale de la tienda o se da de baja, no se vende.
			$m                = MaterialesTable::obtener( $f['material'] );
			$f['existencias'] = $m ? (int) $m['cantidad'] : 0;
			$f['ilimitado']   = 0;
			if ( ! $m || null !== $m['baja_en'] || empty( $m['en_tienda'] ) ) {
				$f['activo'] = 0;
			}
		}
		return $f;
	}

	/** Etiqueta para mostrar («Única» se traduce; las demás son del grupo y se dejan como están). */
	public static function etiqueta( array $v ): string {
		return 'Única' === $v['etiqueta'] ? asde_t( 'Única' ) : $v['etiqueta'];
	}

	// ---------------------------------------------------------------------
	// Existencias
	// ---------------------------------------------------------------------

	/** ¿Hay al menos n unidades? (siempre, si es ilimitada). */
	public static function hay( array $v, int $n = 1 ): bool {
		if ( $v['material'] > 0 ) { // Enlazada al inventario: se vende aunque no quede; lo que falta se pide al proveedor.
			return 1 === $v['activo'];
		}
		return 1 === $v['activo'] && ( 1 === $v['ilimitado'] || $v['existencias'] >= $n );
	}

	/**
	 * Saca del almacén las unidades que haya (hasta n) al comprar una variante enlazada al inventario; el resto
	 * quedará pendiente de pedir al proveedor. En las no enlazadas, reservar() de siempre: todo o nada.
	 *
	 * @return int Unidades que salen de las existencias.
	 */
	public static function tomar( int $id, int $n ): int {
		$v = self::obtener( $id );
		if ( ! $v || $n < 1 ) {
			return 0;
		}
		if ( $v['material'] > 0 ) {
			return MaterialesTable::restar( $v['material'], $n );
		}
		return self::reservar( $id, $n ) ? $n : 0;
	}

	/**
	 * Quita n unidades al comprar, con una sola sentencia: si no quedan, no cambia nada.
	 *
	 * @return bool true si había existencias y se han reservado.
	 */
	public static function reservar( int $id, int $n = 1 ): bool {
		global $wpdb;
		if ( $n < 1 ) {
			return false;
		}
		$filas = $wpdb->query( $wpdb->prepare( 'UPDATE %i SET existencias = IF(ilimitado = 1, existencias, existencias - %d) WHERE id = %d AND activo = 1 AND (ilimitado = 1 OR existencias >= %d)', self::table(), $n, $id, $n ) ); // phpcs:ignore WordPress.DB
		return 1 === (int) $filas || ( 0 === (int) $filas && self::es_ilimitada( $id ) );
	}

	/** Devuelve n unidades (al cancelar un pedido). Las ilimitadas no cuentan. */
	public static function devolver( int $id, int $n = 1 ): bool {
		global $wpdb;
		if ( $n < 1 ) {
			return false;
		}
		$enlazada = self::obtener( $id );
		if ( $enlazada && $enlazada['material'] > 0 ) {
			return MaterialesTable::sumar( $enlazada['material'], $n );
		}
		return false !== $wpdb->query( $wpdb->prepare( 'UPDATE %i SET existencias = LEAST(%d, existencias + %d) WHERE id = %d AND ilimitado = 0', self::table(), self::MAX_EXISTENCIAS, $n, $id ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Cambio manual de la administración: suma (o resta, con un número negativo) unidades. No deja bajar de 0 ni
	 * pasar del máximo. Queda en la auditoría con el motivo.
	 *
	 * @return bool false si la variante no existe, es ilimitada o el cambio dejaría menos de 0.
	 */
	public static function ajustar( int $id, int $delta, string $motivo ): bool {
		global $wpdb;
		$antes = self::obtener( $id );
		if ( ! $antes || 1 === $antes['ilimitado'] || 0 === $delta ) {
			return false;
		}
		if ( $antes['material'] > 0 ) { // Las existencias de lo enlazado se cambian en el inventario; aquí solo llegan los sobrantes del proveedor.
			$hecho = $delta > 0 ? MaterialesTable::sumar( $antes['material'], $delta ) : ( $antes['existencias'] + $delta >= 0 && $delta === -MaterialesTable::restar( $antes['material'], -$delta ) );
			if ( ! $hecho ) {
				return false;
			}
			Logger::log( 'tienda_existencias', 'tienda', (string) $id, array( 'antes' => $antes['existencias'], 'cambio' => $delta, 'motivo' => mb_substr( sanitize_text_field( $motivo ), 0, 200 ), 'inventario' => $antes['material'] ) );
			return true;
		}
		$filas = $wpdb->query( $wpdb->prepare( 'UPDATE %i SET existencias = existencias + %d WHERE id = %d AND ilimitado = 0 AND existencias + %d >= 0 AND existencias + %d <= %d', self::table(), $delta, $id, $delta, $delta, self::MAX_EXISTENCIAS ) ); // phpcs:ignore WordPress.DB
		if ( 1 !== (int) $filas ) {
			return false;
		}
		Logger::log(
			'tienda_existencias',
			'tienda',
			(string) $id,
			array(
				'antes'  => $antes['existencias'],
				'cambio' => $delta,
				'motivo' => mb_substr( sanitize_text_field( $motivo ), 0, 200 ),
			)
		);
		return true;
	}

	private static function es_ilimitada( int $id ): bool {
		$v = self::obtener( $id );
		return $v && 1 === $v['ilimitado'] && 1 === $v['activo'];
	}

	/** Existencias totales de un producto (null si alguna variante activa es ilimitada). */
	public static function total( array $variantes ): ?int {
		$t = 0;
		foreach ( $variantes as $v ) {
			if ( 1 !== $v['activo'] ) {
				continue;
			}
			if ( 1 === $v['ilimitado'] ) {
				return null;
			}
			$t += $v['existencias'];
		}
		return $t;
	}
}
