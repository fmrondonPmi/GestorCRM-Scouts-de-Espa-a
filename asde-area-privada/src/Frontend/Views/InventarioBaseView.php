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
					'inventario'         => asde_t( 'Material' ),
					'inventario_tipos'   => asde_t( 'Tipos de material' ),
					'inventario_compras' => asde_t( 'Listas de compra' ) . ( $pendientes ? ' (' . $pendientes . ')' : '' ),
				),
				'activo' => $activo,
			)
		);
	}

	protected function denegar_post(): void {
		if ( ! current_user_can( Servicio::CAP ) ) {
			wp_die( esc_html__( 'No tienes permiso para hacer esto.', 'asde-area-privada' ), 403 );
		}
	}

	/** Dirección a la que volver que llega en el formulario (solo de este sitio). */
	protected function volver( string $defecto ): string {
		$volver = $this->post( 'volver' ) ?: $this->get( 'volver' );
		return '' !== $volver ? wp_validate_redirect( esc_url_raw( $volver ), $defecto ) : $defecto;
	}
}
