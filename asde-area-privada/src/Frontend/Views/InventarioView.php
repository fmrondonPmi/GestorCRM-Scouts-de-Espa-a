<?php
namespace AsdeBalears\AreaPrivada\Frontend\Views;

use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Frontend\Notices;
use AsdeBalears\AreaPrivada\Inventario\MaterialesTable;
use AsdeBalears\AreaPrivada\Inventario\Servicio;
use AsdeBalears\AreaPrivada\Inventario\TiposTable;

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
		$id = (int) $this->get( 'material' );
		if ( $id || '1' === $this->get( 'nuevo' ) ) {
			$m = $id ? MaterialesTable::obtener( $id ) : null;
			if ( $id && ! $m ) {
				return $this->denegado( asde_t( 'Ese material no está en el inventario del grupo.' ) );
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
				'borrador'  => $this->borrador(),
				'url'       => $url,
				'url_lista' => $this->url( 'inventario' ),
				'url_tipos' => $this->url( 'inventario_tipos', array( 'volver' => rawurlencode( $url ) ) ),
				'usuario'   => array( Servicio::class, 'usuario' ),
			)
		);
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
		$id     = (int) $this->post( 'material' );
		check_admin_referer( 'asde_inventario_material_' . $id );
		$lista = $this->url( 'inventario' );
		$m     = $id ? MaterialesTable::obtener( $id ) : null;
		if ( $id && ! $m ) {
			Notices::add( 'error', asde_t( 'Ese material no está en el inventario del grupo.' ) );
			$this->redirect( $lista );
		}

		if ( 'material_baja' === $accion && $m ) {
			if ( null === $m['baja_en'] && MaterialesTable::dar_baja( $id, $this->post( 'motivo' ) ) ) {
				Logger::log( 'inventario_material_baja', 'inventario', (string) $id );
				Notices::add( 'success', esc_html( sprintf( asde_t( '«%s» dado de baja. Lo puedes recuperar desde «Ver también los dados de baja».' ), $m['descripcion'] ) ) );
			}
			$this->redirect( $lista );
		}
		if ( 'material_recuperar' === $accion && $m ) {
			if ( null !== $m['baja_en'] && MaterialesTable::recuperar( $id ) ) {
				Logger::log( 'inventario_material_recuperado', 'inventario', (string) $id );
				Notices::add( 'success', esc_html( sprintf( asde_t( '«%s» vuelve a estar en el inventario.' ), $m['descripcion'] ) ) );
			}
			$this->redirect( $this->url( 'inventario', array( 'material' => (string) $id ) ) );
		}
		if ( 'material_guardar' !== $accion ) {
			Notices::add( 'error', asde_t( 'Acción desconocida.' ) );
			$this->redirect( $lista );
		}

		$entrada = array();
		foreach ( array( 'descripcion', 'tipo', 'cantidad', 'precio', 'anio', 'num_serie', 'notas' ) as $campo ) {
			$entrada[ $campo ] = $this->post( $campo );
		}
		$datos = MaterialesTable::validar( $entrada );
		if ( is_wp_error( $datos ) ) {
			set_transient( 'asde_ap_inv_material_' . get_current_user_id(), $entrada, 5 * MINUTE_IN_SECONDS );
			Notices::add( 'error', esc_html( $datos->get_error_message() ) );
			$this->redirect( $this->url( 'inventario', $id ? array( 'material' => (string) $id ) : array( 'nuevo' => '1' ) ) );
		}
		if ( $m ) {
			MaterialesTable::actualizar( $id, $datos );
			$cambiados = array_keys( array_filter( $datos, static fn( $v, $k ) => $v !== $m[ $k ], ARRAY_FILTER_USE_BOTH ) );
			Logger::log( 'inventario_material_modificado', 'inventario', (string) $id, array( 'campos' => $cambiados ) );
			Notices::add( 'success', esc_html( sprintf( asde_t( '«%s» guardado.' ), $datos['descripcion'] ) ) );
		} else {
			$id = MaterialesTable::crear( $datos );
			Logger::log( 'inventario_material_creado', 'inventario', (string) $id );
			Notices::add( 'success', esc_html( sprintf( asde_t( '«%s» añadido al inventario.' ), $datos['descripcion'] ) ) );
		}
		$this->redirect( '1' === $this->post( 'otro' ) ? $this->url( 'inventario', array( 'nuevo' => '1' ) ) : $lista );
	}
}
