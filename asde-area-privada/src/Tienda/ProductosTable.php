<?php
namespace AsdeBalears\AreaPrivada\Tienda;

use AsdeBalears\AreaPrivada\Domain\Grupo;
use AsdeBalears\AreaPrivada\Inventario\MaterialesTable;
use AsdeBalears\AreaPrivada\Inventario\TiposTable;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- tablas propias del plugin ({prefix}asde_*): no hay función de WordPress para ellas y los datos cambian en cada petición, no se cachean.

/**
 * Catálogo de la tienda (doc 48, fase 2): los productos del grupo ({prefix}asde_tienda_productos).
 * Cada producto tiene una o varias variantes ({prefix}asde_tienda_variantes, ver VariantesTable):
 * la talla de una camisa, el color de una taza… Las existencias están en la variante.
 *
 * - **Visibilidad:** `todos` o `socios`. Los de `socios` solo existen para quien ha iniciado sesión
 *   y es socio/a (visibles()).
 * - **Precio:** `null` = «por confirmar» (como las insignias sin precio de Uniformes).
 * - **Precio de socio:** opcional (P-177); si no se pone, el precio es el mismo para todos.
 * - No se borra: se archiva (`activo` = 0), para que los pedidos que lo citan sigan teniendo sentido.
 * - `ref` guarda de dónde viene un producto sembrado desde la configuración de Uniformes
 *   (`camisa`, `insignia:<id>`) para que la fase 3 sepa enlazar los pedidos antiguos.
 */
final class ProductosTable {

	public const VIS_TODOS  = 'todos';
	public const VIS_SOCIOS = 'socios';

	public const VISIBILIDADES = array(
		self::VIS_TODOS  => 'Todos (también sin iniciar sesión)',
		self::VIS_SOCIOS => 'Solo socios (con sesión iniciada)',
	);

	public const MAX_PRECIO = 9999.99;
	public const MAX_LIMITE = 99;

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'asde_tienda_productos';
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
				descripcion text NOT NULL,
				categoria varchar(60) NOT NULL DEFAULT '',
				imagen bigint(20) unsigned NOT NULL DEFAULT 0,
				visibilidad varchar(10) NOT NULL DEFAULT 'todos',
				precio decimal(10,2) NULL,
				precio_socio decimal(10,2) NULL,
				limite_persona smallint(5) unsigned NOT NULL DEFAULT 0,
				requiere_proveedor tinyint(1) NOT NULL DEFAULT 0,
				sale_inventario tinyint(1) NOT NULL DEFAULT 0,
				activo tinyint(1) NOT NULL DEFAULT 1,
				orden int(11) NOT NULL DEFAULT 0,
				ref varchar(60) NOT NULL DEFAULT '',
				creado datetime NOT NULL,
				creado_por bigint(20) unsigned NOT NULL DEFAULT 0,
				modificado datetime NULL,
				modificado_por bigint(20) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				KEY grupo (grupo),
				KEY ref (ref)
			) $charset;"
		);
		VariantesTable::install_table();
	}

	// ---------------------------------------------------------------------
	// Validación
	// ---------------------------------------------------------------------

	/**
	 * Valida y limpia lo que llega del formulario (o del CSV).
	 *
	 * @param array<string, string> $d nombre, descripcion, categoria, visibilidad, precio, precio_socio, limite_persona,
	 *                                 requiere_proveedor, sale_inventario, activo.
	 * @return array|\WP_Error Los campos listos para guardar.
	 */
	public static function validar( array $d ) {
		$errores = array();
		$nombre  = mb_substr( trim( sanitize_text_field( (string) ( $d['nombre'] ?? '' ) ) ), 0, 120 );
		if ( '' === $nombre ) {
			$errores[] = asde_t( 'Escribe el nombre del producto.' );
		}
		$vis = (string) ( $d['visibilidad'] ?? self::VIS_TODOS );
		$vis = '' === $vis ? self::VIS_TODOS : $vis;
		if ( ! isset( self::VISIBILIDADES[ $vis ] ) ) {
			$errores[] = asde_t( 'La visibilidad tiene que ser «todos» o «socios».' );
		}
		$precio = self::euros( (string) ( $d['precio'] ?? '' ) );
		if ( false === $precio ) {
			$errores[] = asde_t( 'El precio no es válido (por ejemplo: 12,50).' );
		}
		$precio_socio = self::euros( (string) ( $d['precio_socio'] ?? '' ) );
		if ( false === $precio_socio ) {
			$errores[] = asde_t( 'El precio de socio no es válido (por ejemplo: 10,00).' );
		}
		$limite = trim( (string) ( $d['limite_persona'] ?? '' ) );
		if ( '' !== $limite && ( ! preg_match( '/^\d{1,2}$/', $limite ) ) ) {
			/* translators: %d: límite máximo */
			$errores[] = sprintf( asde_t( 'El límite por persona tiene que ser un número de 0 a %d (0 = sin límite).' ), self::MAX_LIMITE );
		}
		if ( $errores ) {
			return new \WP_Error( 'asde_tienda', implode( ' ', $errores ) );
		}
		return array(
			'nombre'             => $nombre,
			'descripcion'        => mb_substr( trim( sanitize_textarea_field( (string) ( $d['descripcion'] ?? '' ) ) ), 0, 2000 ),
			'categoria'          => mb_substr( trim( sanitize_text_field( (string) ( $d['categoria'] ?? '' ) ) ), 0, 60 ),
			'visibilidad'        => $vis,
			'precio'             => $precio,
			'precio_socio'       => $precio_socio,
			'limite_persona'     => '' === $limite ? 0 : (int) $limite,
			'requiere_proveedor' => self::marca( $d['requiere_proveedor'] ?? '' ),
			'sale_inventario'    => self::marca( $d['sale_inventario'] ?? '' ),
			'activo'             => array_key_exists( 'activo', $d ) ? self::marca( $d['activo'] ) : 1,
		);
	}

	/** 1 si el valor es «sí» (1, sí, si, true, x), 0 si no. */
	private static function marca( $v ): int {
		return in_array( mb_strtolower( remove_accents( trim( (string) $v ) ) ), array( '1', 'si', 's', 'true', 'x', 'yes', 'bai' ), true ) ? 1 : 0;
	}

	/** Euros con coma o punto; null si está vacío y false si no vale (como el inventario, pero con otro máximo). @return float|null|false */
	public static function euros( string $v ) {
		$e = MaterialesTable::euros( $v );
		return ( is_float( $e ) && $e > self::MAX_PRECIO ) ? false : $e;
	}

	// ---------------------------------------------------------------------
	// Consultas
	// ---------------------------------------------------------------------

	/** Producto del grupo actual, o null. */
	public static function obtener( int $id ): ?array {
		global $wpdb;
		$fila = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d AND grupo = %s', self::table(), $id, Grupo::numero() ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return $fila ? self::preparar( $fila ) : null;
	}

	/**
	 * @param bool $archivados También los archivados.
	 * @return array[] Los del grupo actual, por categoría y nombre.
	 */
	public static function todos( bool $archivados = false ): array {
		global $wpdb;
		if ( $archivados ) {
			$filas = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE grupo = %s ORDER BY categoria ASC, nombre ASC, id ASC', self::table(), Grupo::numero() ), ARRAY_A ); // phpcs:ignore WordPress.DB
		} else {
			$filas = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE grupo = %s AND activo = 1 ORDER BY categoria ASC, nombre ASC, id ASC', self::table(), Grupo::numero() ), ARRAY_A ); // phpcs:ignore WordPress.DB
		}
		return array_map( array( self::class, 'preparar' ), $filas );
	}

	/**
	 * Los productos que ve alguien: los activos «para todos» y, si es socio/a con sesión, también los «solo socios».
	 * El filtro está en la consulta, no en la plantilla (doc 48 §4.6): un producto de socios no sale por ningún camino.
	 *
	 * @return array[]
	 */
	public static function visibles( bool $es_socio ): array {
		return array_values( array_filter( self::todos(), static fn( array $p ) => self::visible_para( $p, $es_socio ) ) );
	}

	/** ¿Lo puede ver (y comprar) alguien que es o no es socio/a? */
	public static function visible_para( array $p, bool $es_socio ): bool {
		return 1 === (int) $p['activo'] && ( self::VIS_TODOS === $p['visibilidad'] || $es_socio );
	}

	/** Producto por su clave de origen (`camisa`, `insignia:<id>`…), o null. */
	public static function por_ref( string $ref ): ?array {
		global $wpdb;
		if ( '' === $ref ) {
			return null;
		}
		$fila = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE ref = %s AND grupo = %s ORDER BY id ASC LIMIT 1', self::table(), $ref, Grupo::numero() ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return $fila ? self::preparar( $fila ) : null;
	}

	/** Producto con ese nombre (sin mayúsculas ni acentos ni espacios de más), o null. */
	public static function por_nombre( string $nombre ): ?array {
		$clave = TiposTable::clave( $nombre );
		foreach ( self::todos( true ) as $p ) {
			if ( TiposTable::clave( $p['nombre'] ) === $clave ) {
				return $p;
			}
		}
		return null;
	}

	/** @return string[] Categorías que ya se usan en el grupo (para sugerirlas). */
	public static function categorias(): array {
		return array_values( array_unique( array_filter( array_column( self::todos( true ), 'categoria' ) ) ) );
	}

	// ---------------------------------------------------------------------
	// Escritura
	// ---------------------------------------------------------------------

	/** @param array $datos Lo que devuelve validar(). @return int Id del producto. */
	public static function crear( array $datos, string $ref = '', int $imagen = 0 ): int {
		global $wpdb;
		$wpdb->insert(
			self::table(),
			array(
				'grupo'      => Grupo::numero(),
				'imagen'     => $imagen,
				'ref'        => mb_substr( $ref, 0, 60 ),
				'creado'     => current_time( 'mysql', true ),
				'creado_por' => get_current_user_id(),
			) + self::columnas( $datos )
		);
		return (int) $wpdb->insert_id;
	}

	/** @param array $datos Lo que devuelve validar() (o parte). */
	public static function actualizar( int $id, array $datos ): bool {
		global $wpdb;
		$extra = array(
			'modificado'     => current_time( 'mysql', true ),
			'modificado_por' => get_current_user_id(),
		);
		if ( isset( $datos['imagen'] ) ) {
			$extra['imagen'] = (int) $datos['imagen'];
		}
		return false !== $wpdb->update( self::table(), self::columnas( $datos ) + $extra, array( 'id' => $id, 'grupo' => Grupo::numero() ) );
	}

	public static function archivar( int $id, bool $archivar = true ): bool {
		return self::actualizar( $id, array( 'activo' => $archivar ? 0 : 1 ) );
	}

	/** Solo las columnas que están en $datos y son del producto. */
	private static function columnas( array $datos ): array {
		$out = array();
		foreach ( array( 'nombre', 'descripcion', 'categoria', 'visibilidad', 'precio', 'precio_socio', 'limite_persona', 'requiere_proveedor', 'sale_inventario', 'activo' ) as $c ) {
			if ( array_key_exists( $c, $datos ) ) {
				$out[ $c ] = $datos[ $c ];
			}
		}
		return $out;
	}

	private static function preparar( array $f ): array {
		$f['id']                 = (int) $f['id'];
		$f['imagen']             = (int) $f['imagen'];
		$f['precio']             = null === $f['precio'] ? null : (float) $f['precio'];
		$f['precio_socio']       = null === $f['precio_socio'] ? null : (float) $f['precio_socio'];
		$f['limite_persona']     = (int) $f['limite_persona'];
		$f['requiere_proveedor'] = (int) $f['requiere_proveedor'];
		$f['sale_inventario']    = (int) $f['sale_inventario'];
		$f['activo']             = (int) $f['activo'];
		return $f;
	}

	/** Precio que paga quien compra: el de socio si lo hay y es socio/a; si no, el general. null = por confirmar. */
	public static function precio_para( array $p, bool $es_socio ): ?float {
		return ( $es_socio && null !== $p['precio_socio'] ) ? $p['precio_socio'] : $p['precio'];
	}
}
