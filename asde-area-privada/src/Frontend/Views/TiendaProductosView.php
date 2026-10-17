<?php
namespace AsdeBalears\AreaPrivada\Frontend\Views;

use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Frontend\Notices;
use AsdeBalears\AreaPrivada\Tienda\ImportadorProductos;
use AsdeBalears\AreaPrivada\Tienda\ProductosTable;
use AsdeBalears\AreaPrivada\Tienda\Servicio;
use AsdeBalears\AreaPrivada\Tienda\VariantesTable;

defined( 'ABSPATH' ) || exit;

/**
 * Productos de la tienda (doc 48, fase 2): el catálogo que la administración (Coordinación, Tesorería y
 * Secretaría: `asde_tienda_admin`) mantiene. Lista con filtros, formulario de un producto con sus variantes
 * y existencias, archivar y recuperar, y la carga de productos desde un CSV.
 *
 * Las existencias no se escriben: se **suman o restan** (+5, -2) con un motivo, para que dos personas a la vez
 * no se pisen y quede en la auditoría (VariantesTable::ajustar).
 */
final class TiendaProductosView extends TiendaBaseView {

	/** Variantes nuevas que se pueden añadir en un solo guardado. */
	private const NUEVAS = 5;

	public function render( string $content = '' ): string {
		if ( ! current_user_can( Servicio::CAP_ADMIN ) ) {
			return $this->denegado();
		}
		if ( '1' === $this->get( 'importar' ) ) {
			return $this->layout( 'tienda_productos', $this->subnav( 'tienda_productos' ) . $this->importacion() );
		}
		$id = (int) $this->get( 'producto' );
		if ( $id || '1' === $this->get( 'nuevo' ) ) {
			$p = $id ? ProductosTable::obtener( $id ) : null;
			if ( $id && ! $p ) {
				return $this->denegado( asde_t( 'Ese producto no está en la tienda del grupo.' ) );
			}
			return $this->layout( 'tienda_productos', $this->subnav( 'tienda_productos' ) . $this->formulario( $p ) );
		}

		$archivados = '1' === $this->get( 'archivados' );
		$productos  = $this->filtrar( ProductosTable::todos( $archivados ), $this->get( 'categoria' ), $this->get( 'q' ) );
		$variantes  = VariantesTable::de_productos( array_column( $productos, 'id' ) );
		return $this->layout(
			'tienda_productos',
			$this->subnav( 'tienda_productos' ) . $this->tpl(
				'tienda/productos',
				array(
					'productos'  => $productos,
					'variantes'  => $variantes,
					'categorias' => ProductosTable::categorias(),
					'categoria'  => $this->get( 'categoria' ),
					'q'          => $this->get( 'q' ),
					'archivados' => $archivados,
					'url'        => $this->url( 'tienda_productos' ),
					'url_nuevo'  => $this->url( 'tienda_productos', array( 'nuevo' => '1' ) ),
					'url_csv'    => $this->url( 'tienda_productos', array( 'importar' => '1' ) ),
				)
			)
		);
	}

	/** Filtra por categoría y por texto (nombre, descripción o referencia de alguna variante), sin mayúsculas ni acentos. */
	private function filtrar( array $productos, string $categoria, string $q ): array {
		$buscar = \AsdeBalears\AreaPrivada\Inventario\TiposTable::clave( $q );
		if ( '' === $categoria && '' === $buscar ) {
			return $productos;
		}
		$variantes = '' !== $buscar ? VariantesTable::de_productos( array_column( $productos, 'id' ) ) : array();
		return array_values(
			array_filter(
				$productos,
				static function ( array $p ) use ( $categoria, $buscar, $variantes ): bool {
					if ( '' !== $categoria && $p['categoria'] !== $categoria ) {
						return false;
					}
					if ( '' === $buscar ) {
						return true;
					}
					$texto = $p['nombre'] . ' ' . $p['descripcion'] . ' ' . implode( ' ', array_column( $variantes[ $p['id'] ] ?? array(), 'sku' ) );
					return str_contains( \AsdeBalears\AreaPrivada\Inventario\TiposTable::clave( $texto ), $buscar );
				}
			)
		);
	}
	private function formulario( ?array $p ): string {
		$borrador = $this->borrador();
		return $this->tpl(
			'tienda/producto',
			array(
				'p'          => $p,
				'variantes'  => $p ? VariantesTable::de_producto( $p['id'] ) : array(),
				'borrador'   => $borrador,
				'categorias' => ProductosTable::categorias(),
				'nuevas'     => self::NUEVAS,
				'url'        => $this->url( 'tienda_productos', $p ? array( 'producto' => (string) $p['id'] ) : array( 'nuevo' => '1' ) ),
				'url_lista'  => $this->url( 'tienda_productos' ),
			)
		);
	}

	/** Lo escrito en el formulario cuando no se pudo guardar (para no tener que repetirlo). */
	private function borrador(): array {
		$clave = 'asde_ap_tienda_producto_' . get_current_user_id();
		$datos = get_transient( $clave );
		delete_transient( $clave );
		return is_array( $datos ) ? $datos : array();
	}

	// ---------------------------------------------------------------------
	// Cargar desde CSV
	// ---------------------------------------------------------------------

	private function importacion(): string {
		$clave     = sanitize_key( $this->get( 'resultado' ) );
		$resultado = '' !== $clave ? get_transient( self::clave_importacion( $clave ) ) : false;
		return $this->tpl(
			'tienda/productos-importar',
			array(
				'resultado'   => is_array( $resultado ) ? $resultado : null,
				'caducado'    => '' !== $clave && ! is_array( $resultado ),
				'url'         => $this->url( 'tienda_productos', array( 'importar' => '1' ) ),
				'url_lista'   => $this->url( 'tienda_productos' ),
				'url_ejemplo' => $this->url( 'tienda_productos', array( 'formato' => 'csv-ejemplo' ) ),
				'url_errores' => is_array( $resultado ) && $resultado['errores'] ? $this->url(
					'tienda_productos',
					array(
						'formato'   => 'csv-errores',
						'resultado' => $clave,
						'_wpnonce'  => wp_create_nonce( 'asde_tienda_errores' ),
					)
				) : '',
			)
		);
	}

	/** El informe de una carga se guarda una hora, solo para quien la hizo. */
	private static function clave_importacion( string $clave ): string {
		return 'asde_ap_tienda_import_' . get_current_user_id() . '_' . $clave;
	}

	/** CSV de ejemplo (se llama antes de pintar la página). */
	public function ejemplo(): void {
		if ( ! current_user_can( Servicio::CAP_ADMIN ) ) {
			wp_die( esc_html__( 'No tienes permiso para ver esto.', 'asde-area-privada' ), 403 );
		}
		$this->descargar( 'ejemplo-productos.csv', ImportadorProductos::ejemplo() );
	}

	/** Las filas que no se cargaron, con su motivo, para corregirlas y volver a cargarlas. */
	public function errores_csv(): void {
		$resultado = get_transient( self::clave_importacion( sanitize_key( $this->get( 'resultado' ) ) ) );
		if ( ! current_user_can( Servicio::CAP_ADMIN ) || ! wp_verify_nonce( $this->get( '_wpnonce' ), 'asde_tienda_errores' ) || ! is_array( $resultado ) ) {
			wp_die( esc_html__( 'Enlace de descarga no válido o caducado.', 'asde-area-privada' ), 403 );
		}
		$filas = array( array_merge( array( asde_t( 'Fila' ) ), array_map( static fn( $c ) => asde_t( $c[0] ), ImportadorProductos::COLUMNAS ), array( asde_t( 'Motivo del error' ) ) ) );
		foreach ( $resultado['errores'] as $e ) {
			$datos = array();
			foreach ( array_keys( ImportadorProductos::COLUMNAS ) as $col ) {
				$datos[] = (string) ( $e['datos'][ $col ] ?? '' );
			}
			$filas[] = array_merge( array( (string) $e['fila'] ), $datos, array( $e['motivo'] ) );
		}
		$this->descargar( 'productos-filas-con-error.csv', "\xEF\xBB\xBF" . \AsdeBalears\AreaPrivada\Inventario\Importador::a_csv( $filas ) );
	}

	/** @return never */
	private function descargar( string $nombre, string $csv ): void {
		nocache_headers();
		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $nombre ) . '"' );
		echo $csv; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- es un fichero CSV, no HTML (las celdas van a salvo de fórmulas: Generador::celda_segura)
		exit;
	}

	/** Carga el CSV subido: primero el fichero entero; si vale, las filas correctas, y guarda el informe. */
	private function importar(): void {
		check_admin_referer( 'asde_tienda_importar' );
		$aqui = $this->url( 'tienda_productos', array( 'importar' => '1' ) );
		$f    = $_FILES['fichero'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.NonceVerification.Missing -- el nonce se comprueba arriba; del fichero solo se usa su ruta temporal, comprobada con is_uploaded_file()
		$err  = is_array( $f ) ? (int) ( $f['error'] ?? UPLOAD_ERR_NO_FILE ) : UPLOAD_ERR_NO_FILE;
		if ( UPLOAD_ERR_NO_FILE === $err ) {
			Notices::add( 'error', asde_t( 'Elige el fichero CSV que quieres importar.' ) );
			$this->redirect( $aqui );
		}
		$nombre = sanitize_file_name( (string) ( $f['name'] ?? '' ) );
		if ( UPLOAD_ERR_OK !== $err || ! is_uploaded_file( (string) $f['tmp_name'] ) || (int) $f['size'] > ImportadorProductos::MAX_BYTES ) {
			/* translators: %d: tamaño máximo en MB */
			Notices::add( 'error', esc_html( sprintf( asde_t( 'El fichero no se ha podido subir. Tiene que ser un CSV de hasta %d MB.' ), (int) ( ImportadorProductos::MAX_BYTES / MB_IN_BYTES ) ) ) );
			$this->redirect( $aqui );
		}
		if ( ! preg_match( '/\.(csv|txt)$/i', $nombre ) ) {
			Notices::add( 'error', asde_t( 'El fichero tiene que ser un CSV (.csv). Desde Excel: «Guardar como» → «CSV (delimitado por punto y coma)».' ) );
			$this->redirect( $aqui );
		}
		$leido = ImportadorProductos::leer( (string) $f['tmp_name'] );
		if ( is_wp_error( $leido ) ) {
			Notices::add( 'error', esc_html( $leido->get_error_message() . ' ' . asde_t( 'No se ha cargado nada.' ) ) );
			$this->redirect( $aqui );
		}
		$res = ImportadorProductos::importar( $leido['filas'], '1' === $this->post( 'sumar' ) );
		Logger::log(
			'tienda_productos_importados',
			'tienda',
			'',
			array(
				'filas'      => count( $leido['filas'] ),
				'creados'    => $res['productos_creados'],
				'actualizados' => $res['productos_actualizados'],
				'variantes'  => $res['variantes_creadas'] + $res['variantes_actualizadas'],
				'errores'    => count( $res['errores'] ),
				'sumar'      => '1' === $this->post( 'sumar' ),
			)
		);
		$clave = strtolower( wp_generate_password( 12, false ) );
		set_transient(
			self::clave_importacion( $clave ),
			$res + array(
				'fichero' => $nombre,
				'filas'   => count( $leido['filas'] ),
			),
			HOUR_IN_SECONDS
		);
		$this->redirect( add_query_arg( 'resultado', $clave, $aqui ) );
	}

	// ---------------------------------------------------------------------
	// Guardar
	// ---------------------------------------------------------------------

	public function handle_post(): void {
			if ( ! current_user_can( Servicio::CAP_ADMIN ) ) {
			wp_die( esc_html__( 'No tienes permiso para hacer esto.', 'asde-area-privada' ), 403 );
		}
		$accion = $this->post( 'asde_action' );
		if ( 'productos_importar' === $accion ) {
			$this->importar();
		}
		$id = (int) $this->post( 'producto' );
		check_admin_referer( 'asde_tienda_producto_' . $id );
		$lista = $this->url( 'tienda_productos' );
		$p     = $id ? ProductosTable::obtener( $id ) : null;
		if ( $id && ! $p ) {
			Notices::add( 'error', asde_t( 'Ese producto no está en la tienda del grupo.' ) );
			$this->redirect( $lista );
		}

		if ( 'producto_archivar' === $accion && $p ) {
			ProductosTable::archivar( $id );
			Logger::log( 'tienda_producto_archivado', 'tienda', (string) $id );
			Notices::add( 'success', esc_html( sprintf( asde_t( '«%s» archivado. Ya no se vende; lo puedes recuperar desde «Ver también los archivados».' ), $p['nombre'] ) ) );
			$this->redirect( $lista );
		}
		if ( 'producto_recuperar' === $accion && $p ) {
			ProductosTable::archivar( $id, false );
			Logger::log( 'tienda_producto_recuperado', 'tienda', (string) $id );
			Notices::add( 'success', esc_html( sprintf( asde_t( '«%s» vuelve a estar en la tienda.' ), $p['nombre'] ) ) );
			$this->redirect( $this->url( 'tienda_productos', array( 'producto' => (string) $id ) ) );
		}
		if ( 'producto_guardar' !== $accion ) {
			Notices::add( 'error', asde_t( 'Acción desconocida.' ) );
			$this->redirect( $lista );
		}
		$this->guardar( $id, $p );
	}

	/** Valida todo antes de escribir nada: el producto y cada variante. */
	private function guardar( int $id, ?array $p ): void {
		$aqui    = $this->url( 'tienda_productos', $id ? array( 'producto' => (string) $id ) : array( 'nuevo' => '1' ) );
		$entrada = array();
		foreach ( array( 'nombre', 'descripcion', 'categoria', 'visibilidad', 'precio', 'precio_socio', 'limite_persona' ) as $c ) {
			$entrada[ $c ] = $this->post( $c );
		}
		$entrada['requiere_proveedor'] = '1' === $this->post( 'requiere_proveedor' ) ? '1' : '';
		$entrada['sale_inventario']    = '1' === $this->post( 'sale_inventario' ) ? '1' : '';
		$entrada['activo']             = $p ? (string) $p['activo'] : '1';
		$datos                         = ProductosTable::validar( $entrada );
		$errores                       = is_wp_error( $datos ) ? array( $datos->get_error_message() ) : array();

		// Variantes que ya tiene.
		$etiquetas = $this->post_mapa( 'v_etiqueta' );
		$skus      = $this->post_mapa( 'v_sku' );
		$precios   = $this->post_mapa( 'v_precio' );
		$ajustes   = $this->post_mapa( 'v_ajuste' );
		$ilimit    = $this->post_mapa( 'v_ilimitado' );
		$activas   = $this->post_mapa( 'v_activa' );
		$validas   = array();
		$ajustar   = array();
		if ( $p ) {
			foreach ( VariantesTable::de_producto( $id ) as $v ) {
				$vid = $v['id'];
				$var = VariantesTable::validar(
					array(
						'etiqueta'    => $etiquetas[ $vid ] ?? $v['etiqueta'],
						'sku'         => $skus[ $vid ] ?? $v['sku'],
						'precio'      => $precios[ $vid ] ?? '',
						'existencias' => (string) $v['existencias'], // No se escriben: se ajustan.
						'ilimitado'   => isset( $ilimit[ $vid ] ) ? '1' : '',
					)
				);
				if ( is_wp_error( $var ) ) {
					$errores[] = $var->get_error_message();
					continue;
				}
				$var['activo']  = isset( $activas[ $vid ] ) ? 1 : 0;
				$var['existencias'] = $v['existencias'];
				$validas[ $vid ] = $var;
				$delta = trim( $ajustes[ $vid ] ?? '' );
				if ( '' !== $delta && 0 === $var['ilimitado'] ) {
					if ( ! preg_match( '/^[+-]?\d{1,5}$/', $delta ) || 0 === (int) $delta ) {
						/* translators: %s: etiqueta de la variante */
						$errores[] = sprintf( asde_t( 'El cambio de existencias de «%s» tiene que ser un número entero (por ejemplo +5 o -2).' ), $var['etiqueta'] );
					} elseif ( $v['existencias'] + (int) $delta < 0 ) {
						/* translators: 1: etiqueta de la variante, 2: existencias actuales */
						$errores[] = sprintf( asde_t( 'No se pueden quitar tantas unidades de «%1$s»: solo hay %2$d.' ), $var['etiqueta'], $v['existencias'] );
					} else {
						$ajustar[ $vid ] = (int) $delta;
					}
				}
			}
		}
		// Variantes nuevas.
		$nuevas = array();
		for ( $i = 1; $i <= self::NUEVAS; $i++ ) {
			$et = $this->post( 'nueva_etiqueta_' . $i );
			$ex = $this->post( 'nueva_existencias_' . $i );
			if ( '' === $et && '' === $ex && '' === $this->post( 'nueva_sku_' . $i ) && '' === $this->post( 'nueva_precio_' . $i ) ) {
				continue;
			}
			$var = VariantesTable::validar(
				array(
					'etiqueta'    => $et,
					'sku'         => $this->post( 'nueva_sku_' . $i ),
					'precio'      => $this->post( 'nueva_precio_' . $i ),
					'existencias' => $ex,
					'ilimitado'   => '1' === $this->post( 'nueva_ilimitado_' . $i ) ? '1' : '',
				)
			);
			if ( is_wp_error( $var ) ) {
				$errores[] = $var->get_error_message();
			} else {
				$nuevas[] = $var;
			}
		}
		if ( ! $p && ! $nuevas ) {
			$nuevas[] = VariantesTable::validar( array( 'etiqueta' => 'Única', 'existencias' => '0' ) ); // Un producto siempre tiene al menos una variante.
		}
		if ( $errores ) {
			set_transient( 'asde_ap_tienda_producto_' . get_current_user_id(), $_POST, 5 * MINUTE_IN_SECONDS ); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- el nonce se comprueba en handle_post(); se guarda tal cual para repintar el formulario, donde se escapa al mostrarlo
			Notices::add( 'error', esc_html( implode( ' ', array_unique( $errores ) ) ) );
			$this->redirect( $aqui );
		}

		// Imagen (a la biblioteca de medios).
		$avisos = array();
		$imagen = $this->imagen( 'imagen', $p ? $p['imagen'] : 0, $avisos );

		if ( $p ) {
			$datos['imagen'] = $imagen;
			ProductosTable::actualizar( $id, $datos );
			$cambiados = array_keys( array_filter( $datos, static fn( $v, $k ) => $v !== ( $p[ $k ] ?? null ), ARRAY_FILTER_USE_BOTH ) );
			Logger::log( 'tienda_producto_modificado', 'tienda', (string) $id, array( 'campos' => $cambiados ) );
			$motivo = $this->post( 'motivo_ajuste' );
			foreach ( $validas as $vid => $var ) {
				VariantesTable::actualizar( $vid, $var );
				if ( isset( $ajustar[ $vid ] ) ) {
					VariantesTable::ajustar( $vid, $ajustar[ $vid ], '' !== $motivo ? $motivo : 'Cambio desde la ficha del producto' );
				}
			}
		} else {
			$id = ProductosTable::crear( $datos, '', $imagen );
			Logger::log( 'tienda_producto_creado', 'tienda', (string) $id );
		}
		foreach ( $nuevas as $var ) {
			VariantesTable::crear( $id, $var );
		}
		foreach ( $avisos as $a ) {
			Notices::add( 'warning', esc_html( $a ) );
		}
		Notices::add( 'success', esc_html( sprintf( asde_t( '«%s» guardado.' ), $datos['nombre'] ) ) );
		$this->redirect( '1' === $this->post( 'otro' ) ? $this->url( 'tienda_productos', array( 'nuevo' => '1' ) ) : $this->url( 'tienda_productos', array( 'producto' => (string) $id ) ) );
	}
}
