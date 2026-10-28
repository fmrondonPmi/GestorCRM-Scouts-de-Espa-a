<?php
namespace AsdeBalears\AreaPrivada\Tienda;

use AsdeBalears\AreaPrivada\Inventario\Importador;

defined( 'ABSPATH' ) || exit;

/**
 * Cargar productos y existencias de la tienda desde un CSV (doc 48, fase 2), con el mismo método que el
 * inventario de material (Inventario\Importador): primero el fichero entero (que se pueda leer, que no sea
 * grande, que tenga cabecera con las columnas obligatorias) y después fila a fila; las correctas se cargan y
 * las demás vuelven en el informe con su nº de fila (la cabecera es la 1) y el motivo.
 *
 * Una fila es **una variante** de un producto. Varias filas con el mismo producto (sin distinguir mayúsculas,
 * acentos ni espacios de más) son variantes del mismo producto:
 *
 *   Producto;Categoría;Visibilidad;Precio;Variante;Existencias
 *   Camiseta del grupo;Ropa;socios;12,00;S;10
 *   Camiseta del grupo;;;;M;8
 *
 * - El producto que ya existe se actualiza solo con las celdas que vengan rellenas (una celda vacía no borra).
 * - La variante que ya existe cambia sus existencias: las **sustituye** o, si se pide, las **suma**.
 * - «Existencias» puede ser un número o «ilimitado».
 */
final class ImportadorProductos {

	public const MAX_BYTES = 2 * MB_IN_BYTES;
	public const MAX_FILAS = 2000;

	/** Columna => [etiqueta del ejemplo, otros nombres que se aceptan en la cabecera]. */
	/** Etiquetas traducidas al idioma de WordPress (doc 62). @return array<array-key, mixed> */
	public static function columnas(): array {
		return array(
		'producto'     => array( __( 'Product', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), array( 'producto', 'nombre', 'articulo', 'nombre del producto' ) ),
		'descripcion'  => array( __( 'Description', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), array( 'descripcion', 'detalle' ) ),
		'categoria'    => array( __( 'Category', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), array( 'categoria', 'tipo', 'familia' ) ),
		'visibilidad'  => array( __( 'Visibility', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), array( 'visibilidad', 'visible para', 'visible', 'quien lo ve' ) ),
		'precio'       => array( __( 'Price', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), array( 'precio', 'pvp', 'importe' ) ),
		'precio_socio' => array( __( 'Member price', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), array( 'precio de socio', 'precio socio', 'precio socios' ) ),
		'limite'       => array( __( 'Limit per person', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), array( 'limite por persona', 'limite', 'maximo por persona' ) ),
		'proveedor'    => array( __( 'Ordered from the supplier', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), array( 'se pide al proveedor', 'proveedor', 'pedir al proveedor' ) ),
		'variante'     => array( __( 'Variant', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), array( 'variante', 'talla', 'modelo', 'color', 'opcion' ) ),
		'sku'          => array( __( 'Reference', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), array( 'referencia', 'sku', 'codigo', 'ref' ) ),
		'existencias'  => array( __( 'Stock', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), array( 'existencias', 'stock', 'cantidad', __( 'units', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), 'uds' ) ),
	);
	}

	/** Sin estas columnas en la cabecera no se puede cargar. */
	public const OBLIGATORIAS = array( 'producto' );

	/** Cómo se escribe la visibilidad en el CSV. */
	private const VIS = array(
		'todos'         => ProductosTable::VIS_TODOS,
		'todo el mundo' => ProductosTable::VIS_TODOS,
		'publico'       => ProductosTable::VIS_TODOS,
		'todas'         => ProductosTable::VIS_TODOS,
		'tots'          => ProductosTable::VIS_TODOS,
		'denak'         => ProductosTable::VIS_TODOS,
		'totes'         => ProductosTable::VIS_TODOS,
		'socios'        => ProductosTable::VIS_SOCIOS,
		'solo socios'   => ProductosTable::VIS_SOCIOS,
		'socio'         => ProductosTable::VIS_SOCIOS,
		'socis'         => ProductosTable::VIS_SOCIOS,
		'nomes socis'   => ProductosTable::VIS_SOCIOS,
		'bazkideak'     => ProductosTable::VIS_SOCIOS,
		'bazkideak soilik' => ProductosTable::VIS_SOCIOS,
	);

	/**
	 * Paso 1: lee el fichero y comprueba que es un CSV que se puede cargar.
	 *
	 * @return array{filas:array<int, array<string, string>>, columnas:string[]}|\WP_Error
	 */
	public static function leer( string $ruta ) {
		$tam = is_readable( $ruta ) ? (int) filesize( $ruta ) : 0;
		if ( $tam <= 0 ) {
			return new \WP_Error( 'asde_importar', __( 'The file is empty or could not be read.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
		}
		if ( $tam > self::MAX_BYTES ) {
			/* translators: %d: tamaño máximo en MB */
			return new \WP_Error( 'asde_importar', sprintf( /* translators: %d: number */ __( 'The file is too large (at most %d MB).', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), (int) ( self::MAX_BYTES / MB_IN_BYTES ) ) );
		}
		$texto = (string) file_get_contents( $ruta ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- fichero subido (temporal del servidor), no una URL
		if ( str_contains( substr( $texto, 0, 4096 ), "\0" ) ) {
			return new \WP_Error( 'asde_importar', __( 'The file is not a CSV (it looks like an Excel sheet or another format). Save it as "CSV (semicolon delimited)" and try again.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
		}
		$lineas = Importador::filas_csv( Importador::a_utf8( $texto ) );
		if ( ! $lineas ) {
			return new \WP_Error( 'asde_importar', __( 'The file is empty or could not be read.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
		}
		$mapa   = self::mapa( array_shift( $lineas ) );
		$faltan = array_diff( self::OBLIGATORIAS, $mapa );
		if ( $faltan ) {
			$nombres = array_map( static fn( $c ) => '«' . ( self::columnas()[ $c ][0] ) . '»', $faltan );
			/* translators: %s: columnas que faltan */
			return new \WP_Error( 'asde_importar', sprintf( /* translators: %s: text */ __( 'The first row must be the header with the column names, and these are missing: %s. Download the example to see what it must look like.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), implode( ', ', $nombres ) ) );
		}
		$filas = array();
		foreach ( $lineas as $i => $celdas ) {
			if ( ! array_filter( $celdas, static fn( $c ) => '' !== trim( (string) $c ) ) ) {
				continue; // Fila vacía (al final de lo que guarda Excel, sobre todo).
			}
			$fila = array_fill_keys( array_keys( self::columnas() ), '' );
			foreach ( $mapa as $pos => $col ) {
				$fila[ $col ] = trim( (string) ( $celdas[ $pos ] ?? '' ) );
			}
			$filas[ $i + 2 ] = $fila; // +1 por la cabecera y +1 porque la hoja empieza en la fila 1.
		}
		if ( ! $filas ) {
			return new \WP_Error( 'asde_importar', __( 'The file has only the header: there is no product to load.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
		}
		if ( count( $filas ) > self::MAX_FILAS ) {
			/* translators: %d: número máximo de filas */
			return new \WP_Error( 'asde_importar', sprintf( /* translators: %d: number */ __( 'The file has too many rows (at most %d). Split it into several.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), self::MAX_FILAS ) );
		}
		return array(
			'filas'    => $filas,
			'columnas' => array_values( $mapa ),
		);
	}

	/**
	 * Paso 2: carga las filas correctas.
	 *
	 * @param array<int, array<string, string>> $filas  Lo que devuelve leer().
	 * @param bool                              $sumar  Sumar a las existencias que ya hay (si no, se sustituyen).
	 * @return array{productos_creados:int, productos_actualizados:int, variantes_creadas:int, variantes_actualizadas:int,
	 *               errores:array<int, array{fila:int, datos:array<string, string>, motivo:string}>}
	 */
	public static function importar( array $filas, bool $sumar = false ): array {
		$res = array(
			'productos_creados'      => 0,
			'productos_actualizados' => 0,
			'variantes_creadas'      => 0,
			'variantes_actualizadas' => 0,
			'errores'                => array(),
		);
		foreach ( $filas as $n => $fila ) {
			$fila += array_fill_keys( array_keys( self::columnas() ), '' );
			$fallo = static function ( string $motivo ) use ( &$res, $n, $fila ): void {
				$res['errores'][] = array(
					'fila'   => (int) $n,
					'datos'  => $fila,
					'motivo' => $motivo,
				);
			};

			// Visibilidad: «solo socios», «socios», «todos»…; vacía = la del producto (o «todos» si es nuevo).
			$vis = '';
			if ( '' !== $fila['visibilidad'] ) {
				$vis = self::VIS[ mb_strtolower( remove_accents( $fila['visibilidad'] ) ) ] ?? '';
				if ( '' === $vis ) {
					$fallo( __( 'Visibility must be "everyone" or "members".', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
					continue;
				}
			}
			$actual = '' !== trim( $fila['producto'] ) ? ProductosTable::por_nombre( $fila['producto'] ) : null;

			// Producto: lo que ya tiene, con las celdas rellenas por encima.
			$entrada = array(
				'nombre'             => $fila['producto'],
				'descripcion'        => $actual['descripcion'] ?? '',
				'categoria'          => $actual['categoria'] ?? '',
				'visibilidad'        => $actual['visibilidad'] ?? ProductosTable::VIS_TODOS,
				'precio'             => null === ( $actual['precio'] ?? null ) ? '' : (string) $actual['precio'],
				'precio_socio'       => null === ( $actual['precio_socio'] ?? null ) ? '' : (string) $actual['precio_socio'],
				'limite_persona'     => (string) ( $actual['limite_persona'] ?? 0 ),
				'requiere_proveedor' => (string) ( $actual['requiere_proveedor'] ?? 0 ),
				'sale_inventario'    => (string) ( $actual['sale_inventario'] ?? 0 ),
				'activo'             => '1',
			);
			foreach ( array( 'descripcion', 'categoria', 'precio', 'precio_socio' ) as $c ) {
				if ( '' !== $fila[ $c ] ) {
					$entrada[ $c ] = $fila[ $c ];
				}
			}
			if ( '' !== $vis ) {
				$entrada['visibilidad'] = $vis;
			}
			if ( '' !== $fila['limite'] ) {
				$entrada['limite_persona'] = $fila['limite'];
			}
			if ( '' !== $fila['proveedor'] ) {
				$entrada['requiere_proveedor'] = $fila['proveedor'];
			}
			$datos = ProductosTable::validar( $entrada );
			if ( is_wp_error( $datos ) ) {
				$fallo( $datos->get_error_message() );
				continue;
			}

			// Variante: validada antes de escribir nada, para no dejar un producto a medias.
			$etiqueta = '' !== $fila['variante'] ? $fila['variante'] : 'Única';
			$previa   = $actual ? VariantesTable::por_etiqueta( $actual['id'], $etiqueta ) : null;
			$cant     = $fila['existencias'];
			$var      = VariantesTable::validar(
				array(
					'etiqueta'    => $etiqueta,
					'sku'         => '' !== $fila['sku'] ? $fila['sku'] : ( $previa['sku'] ?? '' ),
					'precio'      => null === ( $previa['precio'] ?? null ) ? '' : (string) $previa['precio'],
					'existencias' => '' !== $cant ? $cant : (string) ( $previa['existencias'] ?? 0 ),
					'ilimitado'   => '' === $cant ? (string) ( $previa['ilimitado'] ?? 0 ) : '',
				)
			);
			if ( is_wp_error( $var ) ) {
				$fallo( $var->get_error_message() );
				continue;
			}
			if ( $sumar && $previa && '' !== $cant && 0 === $var['ilimitado'] && 0 === $previa['ilimitado'] ) {
				$var['existencias'] = $previa['existencias'] + $var['existencias'];
				if ( $var['existencias'] > VariantesTable::MAX_EXISTENCIAS ) {
					/* translators: %d: cantidad máxima */
					$fallo( sprintf( /* translators: %d: number */ __( 'Adding this stock would exceed %d units.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), VariantesTable::MAX_EXISTENCIAS ) );
					continue;
				}
			}

			// Escribir.
			if ( $actual ) {
				$id = $actual['id'];
				if ( self::cambia( $actual, $datos ) ) {
					ProductosTable::actualizar( $id, $datos );
					++$res['productos_actualizados'];
				}
			} else {
				$id = ProductosTable::crear( $datos );
				++$res['productos_creados'];
			}
			if ( $previa ) {
				VariantesTable::actualizar( $previa['id'], $var );
				++$res['variantes_actualizadas'];
			} else {
				VariantesTable::crear( $id, $var );
				++$res['variantes_creadas'];
			}
		}
		return $res;
	}

	/** ¿Algún campo del producto es distinto de lo guardado? */
	private static function cambia( array $actual, array $datos ): bool {
		foreach ( $datos as $c => $v ) {
			if ( 'activo' === $c ) {
				continue; // Cargar un producto no lo archiva ni lo recupera.
			}
			if ( $actual[ $c ] !== $v ) {
				return true;
			}
		}
		return false;
	}

	/** Fichero de ejemplo (con BOM y «;», como lo abre bien Excel). */
	public static function ejemplo(): string {
		$cab   = array_map( static fn( $c ) =>  $c[0] , self::columnas() );
		$filas = array(
			$cab,
			array( __( 'Group T-shirt', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), __( 'Cotton T-shirt with the badge', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), __( 'Clothing', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), 'socios', '12,00', '10,00', '3', 'sí', 'S', 'CAM-S', '10' ),
			array( __( 'Group T-shirt', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), '', '', '', '', '', '', '', 'M', 'CAM-M', '8' ),
			array( __( 'Group T-shirt', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), '', '', '', '', '', '', '', 'L', 'CAM-L', '5' ),
			array( __( 'Group mug', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), '', __( 'Gifts', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), 'todos', '6,50', '', '', '', '', '', '24' ),
			array( __( 'Neckerchief', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), '', __( 'Uniform', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), 'socios', '5,00', '', '', 'sí', '', '', 'ilimitado' ),
		);
		return "\xEF\xBB\xBF" . Importador::a_csv( $filas );
	}

	/** @return array<int, string> posición en el CSV => columna reconocida. */
	private static function mapa( array $cabecera ): array {
		$mapa = array();
		foreach ( $cabecera as $pos => $nombre ) {
			$clave = self::normalizar( (string) $nombre );
			foreach ( self::columnas() as $col => list( $etiqueta, $alias ) ) {
				if ( ! in_array( $col, $mapa, true ) && ( in_array( $clave, $alias, true ) || self::normalizar(  $etiqueta  ) === $clave ) ) {
					$mapa[ $pos ] = $col;
					break;
				}
			}
		}
		return $mapa;
	}

	private static function normalizar( string $t ): string {
		return trim( (string) preg_replace( '/[^a-z0-9]+/', ' ', mb_strtolower( remove_accents( $t ) ) ) );
	}
}
