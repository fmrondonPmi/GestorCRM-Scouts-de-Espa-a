<?php
namespace AsdeBalears\AreaPrivada\Frontend\Views;

use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Frontend\Notices;
use AsdeBalears\AreaPrivada\Inventario\MaterialesTable;
use AsdeBalears\AreaPrivada\Inventario\Servicio;
use AsdeBalears\AreaPrivada\Inventario\TiposTable;

defined( 'ABSPATH' ) || exit;

/**
 * Tipos de material (doc 32): crear, renombrar y borrar los que no se usan.
 *
 * Se abre también en la ventana emergente desde el «+» del formulario de un
 * material; para refrescar ese desplegable sin recargar, la página contesta en
 * JSON con la lista de tipos (cabecera X-Asde-Json, como el listado de usuarios).
 */
final class InventarioTiposView extends InventarioBaseView {

	public function render( string $content = '' ): string {
		if ( ! current_user_can( Servicio::CAP ) ) {
			return $this->denegado();
		}
		$volver = $this->volver( '' );
		return $this->layout(
			'inventario_tipos',
			( self::es_modal() ? '' : $this->subnav( 'inventario_tipos' ) ) . $this->tpl(
				'inventario/tipos',
				array(
					'tipos'  => TiposTable::todos(),
					'usos'   => MaterialesTable::contar_por_tipo(),
					'url'    => $this->url( 'inventario_tipos', '' !== $volver ? array( 'volver' => rawurlencode( $volver ) ) : array() ),
					'volver' => $volver,
				)
			)
		);
	}

	/** Los tipos del grupo para el desplegable: [{id, nombre}], por orden alfabético. */
	public function json(): void {
		if ( ! current_user_can( Servicio::CAP ) ) {
			wp_send_json_error( array( 'mensaje' => asde_t( 'No tienes permiso para ver esto.' ) ), 403 );
		}
		wp_send_json_success( array( 'tipos' => array_values( TiposTable::todos() ) ) );
	}

	public function handle_post(): void {
		$this->denegar_post();
		check_admin_referer( 'asde_inventario_tipos' );
		$accion = $this->post( 'asde_action' );
		$id     = (int) $this->post( 'tipo' );
		$tipo   = $id ? TiposTable::obtener( $id ) : null;
		$volver = $this->volver( '' );
		$aqui   = $this->url( 'inventario_tipos', '' !== $volver ? array( 'volver' => rawurlencode( $volver ) ) : array() );

		switch ( $accion ) {
			case 'tipo_crear':
				$r = TiposTable::crear( $this->post( 'nombre' ) );
				if ( is_wp_error( $r ) ) {
					Notices::add( 'error', esc_html( $r->get_error_message() ) );
					break;
				}
				Logger::log( 'inventario_tipo_creado', 'inventario_tipo', (string) $r );
				Notices::add( 'success', esc_html( sprintf( asde_t( 'Tipo «%s» creado.' ), TiposTable::obtener( $r )['nombre'] ?? '' ) ) );
				break;

			case 'tipo_renombrar':
				$r = TiposTable::renombrar( $id, $this->post( 'nombre' ) );
				if ( is_wp_error( $r ) ) {
					Notices::add( 'error', esc_html( $r->get_error_message() ) );
					break;
				}
				Logger::log( 'inventario_tipo_renombrado', 'inventario_tipo', (string) $id );
				Notices::add( 'success', esc_html( sprintf( asde_t( 'Tipo «%1$s» renombrado a «%2$s».' ), $tipo['nombre'] ?? '', TiposTable::obtener( $id )['nombre'] ?? '' ) ) );
				break;

			case 'tipo_borrar':
				$r = TiposTable::borrar( $id );
				if ( is_wp_error( $r ) ) {
					Notices::add( 'error', esc_html( $r->get_error_message() ) );
					break;
				}
				Logger::log( 'inventario_tipo_borrado', 'inventario_tipo', (string) $id );
				Notices::add( 'success', esc_html( sprintf( asde_t( 'Tipo «%s» borrado.' ), $tipo['nombre'] ?? '' ) ) );
				break;

			default:
				Notices::add( 'error', asde_t( 'Acción desconocida.' ) );
		}
		$this->redirect( $aqui );
	}
}
