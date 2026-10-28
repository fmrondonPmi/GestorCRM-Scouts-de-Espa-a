<?php
namespace AsdeBalears\AreaPrivada\Frontend\Views;

use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Frontend\Notices;
use AsdeBalears\AreaPrivada\Inventario\Importador;
use AsdeBalears\AreaPrivada\Inventario\MaterialesTable;
use AsdeBalears\AreaPrivada\Inventario\Servicio;
use AsdeBalears\AreaPrivada\Inventario\TiposTable;
use AsdeBalears\AreaPrivada\Tienda\Config;

defined( 'ABSPATH' ) || exit;

/**
 * Inventario de material del grupo (doc 32): la lista, con filtros que trabajan
 * en el navegador, y el formulario para añadir, cambiar, dar de baja o recuperar
 * un material. Desde la lista se marcan los materiales de una lista de compra.
 */
final class InventarioView extends InventarioBaseView {

	public function render( string $content = '' ): string {
		if ( ! current_user_can( Servicio::CAP ) ) {
			return $this->denegado();
		}
		TiposTable::camisa(); // v0.29: el tipo de las camisas del uniforme está siempre (lo usa la gestión de uniformes).
		if ( '1' === $this->get( 'importar' ) ) {
			// Importar desde CSV: se abre en la ventana emergente (sin las pestañas) o en su página.
			return $this->layout( 'inventario', ( self::es_modal() ? '' : $this->subnav( 'inventario' ) ) . $this->importacion() );
		}
		$id = (int) $this->get( 'material' );
		if ( $id || '1' === $this->get( 'nuevo' ) ) {
			$m = $id ? MaterialesTable::obtener( $id ) : null;
			if ( $id && ! $m ) {
				return $this->denegado( __( 'That equipment is not in the group\'s inventory.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
			}
			return $this->layout( 'inventario', $this->subnav( 'inventario' ) . $this->formulario( $m ) );
		}

		$bajas      = '1' === $this->get( 'bajas' );
		$materiales = MaterialesTable::todos( $bajas );
		$activos    = array_filter( $materiales, static fn( $m ) => null === $m['baja_en'] );
		return $this->layout(
			'inventario',
			$this->subnav( 'inventario' ) . $this->tpl(
				'inventario/lista',
				array(
					'materiales' => $materiales,
					'tipos'      => TiposTable::todos(),
					'tipo'       => (int) $this->get( 'tipo' ),
					'q'          => $this->get( 'q' ),
					'bajas'      => $bajas,
					'umbral'     => Servicio::umbral(),
					'unidades'   => array_sum( array_column( $activos, 'cantidad' ) ),
					'valor'      => array_sum( array_map( static fn( $m ) => null === $m['precio'] ? 0 : $m['precio'] * $m['cantidad'], $activos ) ),
					'n_activos'  => count( $activos ),
					'url'        => $this->url( 'inventario' ),
					'url_compra' => $this->url( 'inventario_compras' ),
				)
			)
		);
	}

	private function formulario( ?array $m ): string {
		$url = $this->url( 'inventario', $m ? array( 'material' => (string) $m['id'] ) : array( 'nuevo' => '1' ) );
		return $this->tpl(
			'inventario/material',
			array(
				'm'         => $m,
				'tipos'     => TiposTable::todos(),
				'camisa'    => TiposTable::camisa(),
				'cfg'       => Config::de(),
				'borrador'  => $this->borrador(),
				'url'       => $url,
				'url_lista' => $this->url( 'inventario' ),
				'url_tipos' => $this->url( 'inventario_tipos', array( 'volver' => rawurlencode( $url ) ) ),
				'usuario'   => array( Servicio::class, 'usuario' ),
			)
		);
	}

	/** Formulario para importar un CSV o, después de importarlo, el informe (?resultado=clave). */
	private function importacion(): string {
		$clave     = sanitize_key( $this->get( 'resultado' ) );
		$resultado = '' !== $clave ? get_transient( self::clave_importacion( $clave ) ) : false;
		return $this->tpl(
			'inventario/importar',
			array(
				'resultado'   => is_array( $resultado ) ? $resultado : null,
				'caducado'    => '' !== $clave && ! is_array( $resultado ),
				'url'         => $this->url( 'inventario', array( 'importar' => '1' ) ),
				'url_lista'   => $this->url( 'inventario' ),
				'url_ejemplo' => $this->url( 'inventario', array( 'formato' => 'csv-ejemplo' ) ),
				'url_errores' => is_array( $resultado ) && $resultado['errores'] ? $this->url(
					'inventario',
					array(
						'formato'   => 'csv-errores',
						'resultado' => $clave,
						'_wpnonce'  => wp_create_nonce( 'asde_inventario_errores' ),
					)
				) : '',
			)
		);
	}

	/** El informe de una importación se guarda una hora, solo para quien la hizo. */
	private static function clave_importacion( string $clave ): string {
		return 'asde_ap_inv_import_' . get_current_user_id() . '_' . $clave;
	}

	/** CSV de ejemplo para importar (se llama antes de pintar la página). */
	public function ejemplo(): void {
		if ( ! current_user_can( Servicio::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to see this.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), 403 );
		}
		$this->descargar( 'ejemplo-inventario.csv', Importador::ejemplo() );
	}

	/** Las filas que no se importaron, con su motivo, para corregirlas y volver a importarlas. */
	public function errores_csv(): void {
		$resultado = get_transient( self::clave_importacion( sanitize_key( $this->get( 'resultado' ) ) ) );
		if ( ! current_user_can( Servicio::CAP ) ) {
			wp_die( esc_html__( 'Download link not valid or expired.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), 403 );
		}
		if ( ! wp_verify_nonce( $this->get( '_wpnonce' ), 'asde_inventario_errores' ) ) {
			wp_die( esc_html__( 'Download link not valid or expired.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), 403 );
		}
		if ( ! is_array( $resultado ) ) {
			wp_die( esc_html__( 'Download link not valid or expired.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), 403 );
		}
		$filas = array( array_merge( array( __( 'Row', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ), array_map( static fn( $c ) =>  $c[0] , Importador::columnas() ), array( __( 'Reason for the error', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) ) );
		foreach ( $resultado['errores'] as $e ) {
			$datos = array();
			foreach ( array_keys( Importador::columnas() ) as $col ) {
				$datos[] = (string) ( $e['datos'][ $col ] ?? '' );
			}
			$filas[] = array_merge( array( (string) $e['fila'] ), $datos, array( $e['motivo'] ) );
		}
		$this->descargar( 'inventario-filas-con-error.csv', "\xEF\xBB\xBF" . Importador::a_csv( $filas ) );
	}

	/** @return never */
	private function descargar( string $nombre, string $csv ): void {
		nocache_headers();
		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $nombre ) . '"' );
		echo $csv; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fichero CSV que se descarga (text/csv), no HTML: escapar lo estropearía
		exit;
	}

	/** Importa el CSV subido: primero el fichero entero; si vale, las filas correctas, y guarda el informe. */
	private function importar(): void {
		check_admin_referer( 'asde_inventario_importar' );
		$aqui = $this->url( 'inventario', array( 'importar' => '1' ) );
		$f    = $_FILES['fichero'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.NonceVerification.Missing -- el nonce se comprueba arriba; del fichero solo se usa su ruta temporal, comprobada con is_uploaded_file()
		$err  = is_array( $f ) ? (int) ( $f['error'] ?? UPLOAD_ERR_NO_FILE ) : UPLOAD_ERR_NO_FILE;
		if ( UPLOAD_ERR_NO_FILE === $err ) {
			Notices::add( 'error', __( 'Choose the CSV file you want to import.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
			$this->redirect( $aqui );
		}
		$nombre = sanitize_file_name( (string) ( $f['name'] ?? '' ) );
		if ( UPLOAD_ERR_OK !== $err || ! is_uploaded_file( (string) $f['tmp_name'] ) || (int) $f['size'] > Importador::MAX_BYTES ) {
			/* translators: %d: tamaño máximo en MB */
			Notices::add( 'error', esc_html( sprintf( /* translators: %d: number */ __( 'The file could not be uploaded. It must be a CSV of up to %d MB.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), (int) ( Importador::MAX_BYTES / MB_IN_BYTES ) ) ) );
			$this->redirect( $aqui );
		}
		if ( ! preg_match( '/\.(csv|txt)$/i', $nombre ) ) {
			Notices::add( 'error', __( 'The file must be a CSV (.csv). From Excel: "Save as" → "CSV (semicolon delimited)".', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
			$this->redirect( $aqui );
		}
		$leido = Importador::leer( (string) $f['tmp_name'] );
		if ( is_wp_error( $leido ) ) {
			Notices::add( 'error', esc_html( $leido->get_error_message() . ' ' . __( 'Nothing has been imported.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) );
			$this->redirect( $aqui );
		}
		$res = Importador::importar( $leido['filas'], '1' === $this->post( 'crear_tipos' ) );
		Logger::log(
			'inventario_importado',
			'inventario',
			'',
			array(
				'filas'         => count( $leido['filas'] ),
				'importados'    => $res['importados'],
				'sumados'       => $res['sumados'],
				'errores'       => count( $res['errores'] ),
				'tipos_creados' => count( $res['tipos_creados'] ),
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

	/** Lo escrito en el formulario cuando no se pudo guardar (para no tener que repetirlo). */
	private function borrador(): array {
		$clave = 'asde_ap_inv_material_' . get_current_user_id();
		$datos = get_transient( $clave );
		delete_transient( $clave );
		return is_array( $datos ) ? $datos : array();
	}

	public function handle_post(): void {
		$this->denegar_post();
		$accion = $this->post( 'asde_action' );
		if ( 'material_importar' === $accion ) {
			$this->importar();
		}
		$id     = (int) $this->post( 'material' );
		check_admin_referer( 'asde_inventario_material_' . $id );
		$lista = $this->url( 'inventario' );
		$m     = $id ? MaterialesTable::obtener( $id ) : null;
		if ( $id && ! $m ) {
			Notices::add( 'error', __( 'That equipment is not in the group\'s inventory.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
			$this->redirect( $lista );
		}

		if ( 'material_baja' === $accion && $m ) {
			if ( null === $m['baja_en'] && MaterialesTable::dar_baja( $id, $this->post( 'motivo' ) ) ) {
				\AsdeBalears\AreaPrivada\Tienda\InventarioTienda::sincronizar( $id );
				Logger::log( 'inventario_material_baja', 'inventario', (string) $id );
				Notices::add( 'success', esc_html( sprintf( /* translators: %s: text */ __( '"%s" written off. You can recover it from "Also show the written-off ones".', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $m['descripcion'] ) ) );
			}
			$this->redirect( $lista );
		}
		if ( 'material_recuperar' === $accion && $m ) {
			if ( null !== $m['baja_en'] && MaterialesTable::recuperar( $id ) ) {
				\AsdeBalears\AreaPrivada\Tienda\InventarioTienda::sincronizar( $id );
				Logger::log( 'inventario_material_recuperado', 'inventario', (string) $id );
				Notices::add( 'success', esc_html( sprintf( /* translators: %s: text */ __( '"%s" is back in the inventory.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $m['descripcion'] ) ) );
			}
			$this->redirect( $this->url( 'inventario', array( 'material' => (string) $id ) ) );
		}
		if ( 'material_guardar' !== $accion ) {
			Notices::add( 'error', __( 'Unknown action.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
			$this->redirect( $lista );
		}

		$entrada = array();
		foreach ( array( 'descripcion', 'tipo', 'cantidad', 'precio', 'anio', 'num_serie', 'notas', 'modelo', 'talla', 'variante_tienda' ) as $campo ) {
			$entrada[ $campo ] = $this->post( $campo );
		}
		$entrada['en_tienda'] = '1' === $this->post( 'en_tienda' ) ? '1' : '';
		$datos = MaterialesTable::validar( $entrada );
		if ( is_wp_error( $datos ) ) {
			set_transient( 'asde_ap_inv_material_' . get_current_user_id(), $entrada, 5 * MINUTE_IN_SECONDS );
			Notices::add( 'error', esc_html( $datos->get_error_message() ) );
			$this->redirect( $this->url( 'inventario', $id ? array( 'material' => (string) $id ) : array( 'nuevo' => '1' ) ) );
		}
		if ( $m ) {
			MaterialesTable::actualizar( $id, $datos );
			\AsdeBalears\AreaPrivada\Tienda\InventarioTienda::sincronizar( $id ); // v0.51: la tienda sigue lo que dice el inventario.
			$cambiados = array_keys( array_filter( $datos, static fn( $v, $k ) => $v !== $m[ $k ], ARRAY_FILTER_USE_BOTH ) );
			Logger::log( 'inventario_material_modificado', 'inventario', (string) $id, array( 'campos' => $cambiados ) );
			Notices::add( 'success', esc_html( sprintf( /* translators: %s: text */ __( '"%s" saved.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $datos['descripcion'] ) ) );
		} else {
			$id = MaterialesTable::crear( $datos );
			\AsdeBalears\AreaPrivada\Tienda\InventarioTienda::sincronizar( $id );
			Logger::log( 'inventario_material_creado', 'inventario', (string) $id );
			Notices::add( 'success', esc_html( sprintf( /* translators: %s: text */ __( '"%s" added to the inventory.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $datos['descripcion'] ) ) );
		}
		$this->redirect( '1' === $this->post( 'otro' ) ? $this->url( 'inventario', array( 'nuevo' => '1' ) ) : $lista );
	}
}
