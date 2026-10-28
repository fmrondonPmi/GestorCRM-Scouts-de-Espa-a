<?php
namespace AsdeBalears\AreaPrivada\Frontend\Views;

use AsdeBalears\AreaPrivada\Inventario\ComprasTable;
use AsdeBalears\AreaPrivada\Inventario\Servicio;

defined( 'ABSPATH' ) || exit;

/** Base de las páginas del inventario: pestañas «Material · Tipos · Listas de compra» (doc 32). */
abstract class InventarioBaseView extends View {

	protected function subnav( string $activo ): string {
		$pendientes = current_user_can( Servicio::CAP_APROBAR ) ? ComprasTable::contar_pendientes() : 0;
		return $this->tpl(
			'tienda/subnav',
			array(
				'items'  => array(
					'inventario'         => __( 'Equipment', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
					'inventario_tipos'   => __( 'Equipment types', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
					'inventario_compras' => __( 'Shopping lists', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) . ( $pendientes ? ' (' . $pendientes . ')' : '' ),
				),
				'activo' => $activo,
			)
		);
	}

	protected function denegar_post(): void {
		if ( ! current_user_can( Servicio::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), 403 );
		}
	}

	/** Dirección a la que volver que llega en el formulario (solo de este sitio). */
	protected function volver( string $defecto ): string {
		$volver = $this->post( 'volver' ) ?: $this->get( 'volver' );
		return '' !== $volver ? wp_validate_redirect( esc_url_raw( $volver ), $defecto ) : $defecto;
	}
}
