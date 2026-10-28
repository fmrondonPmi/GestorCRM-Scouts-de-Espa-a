<?php
namespace AsdeBalears\AreaPrivada\Frontend\Views;

use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Domain\Grupo;
use AsdeBalears\AreaPrivada\Frontend\Notices;
use AsdeBalears\AreaPrivada\Informes\Xlsx;
use AsdeBalears\AreaPrivada\Tienda\Proveedor;
use AsdeBalears\AreaPrivada\Tienda\ProveedorTable;

defined( 'ABSPATH' ) || exit;

/**
 * «Pedido de camisas» (v0.48, doc 48, T4): lo que los clientes han pagado y hay que pedir al proveedor, los pedidos ya
 * hechos con su Excel para enviarlo y el paso «recibido en el grupo». Lo ven Coordinación, Tesorería y la administración
 * de la tienda; lo hace y lo recibe Coordinación.
 */
final class TiendaProveedorView extends TiendaBaseView {

	public function render( string $content = '' ): string {
		if ( ! Proveedor::puede_ver() ) {
			return $this->denegado();
		}
		$por_pedir = Proveedor::por_pedir();
		return $this->layout(
			'tienda_proveedor',
			$this->subnav( 'tienda_proveedor' ) . $this->tpl(
				'tienda/proveedor',
				array(
					'puede'     => Proveedor::puede_gestionar(),
					'por_pedir' => $por_pedir,
					'lineas'    => Proveedor::lineas_de( $por_pedir ),
					'sin_pagar' => Proveedor::sin_pagar(),
					'hechos'    => ProveedorTable::todos(),
					'url'       => $this->url( 'tienda_proveedor' ),
					'excel'     => function ( int $id ): string {
						return $this->url(
							'tienda_proveedor',
							array(
								'formato'  => 'xlsx',
								'pedido'   => (string) $id,
								'_wpnonce' => wp_create_nonce( 'asde_proveedor_excel_' . $id ),
							)
						);
					},
				)
			)
		);
	}

	/** El pedido al proveedor en Excel, para enviárselo (se llama antes de pintar la página). */
	public function exportar(): void {
		$id = absint( $this->get( 'pedido' ) );
		if ( ! wp_verify_nonce( $this->get( '_wpnonce' ), 'asde_proveedor_excel_' . $id ) ) {
			wp_die( esc_html__( 'Download link not valid or expired.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), 403 );
		}
		if ( ! Proveedor::puede_ver() ) {
			wp_die( esc_html__( 'Download link not valid or expired.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), 403 );
		}
		$pp = ProveedorTable::obtener( $id );
		if ( ! $pp ) {
			wp_die( esc_html__( 'That order does not exist.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), 404 );
		}
		Logger::log( 'tienda_proveedor_excel', 'tienda', (string) $id );
		$titulo = $pp['nombre'] . ' · ' . Grupo::nombre();
		Xlsx::descargar(
			'pedido-proveedor-' . $id . '-' . wp_date( 'Ymd' ) . '.xlsx',
			__( 'Order', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
			array( __( 'Product', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), __( 'Size or model', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), __( 'Quantity', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ),
			Proveedor::filas_excel( $pp ),
			array(
				'titulo'    => $titulo,
				'subtitulo' => sprintf( /* translators: %s: text */ __( 'Generated on %s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), wp_date( 'd/m/Y' ) ),
				'tipos'     => array( 'texto', 'texto', 'entero' ),
				'totales'   => array( __( 'Total', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), '', array_sum( array_column( $pp['lineas'], 'cantidad' ) ) ),
			)
		);
		exit;
	}

	public function handle_post(): void {
		if ( ! Proveedor::puede_ver() ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), 403 );
		}
		$accion = $this->post( 'asde_action' );
		$volver = $this->url( 'tienda_proveedor' );
		if ( 'proveedor_crear' === $accion ) {
			check_admin_referer( 'asde_proveedor_crear' );
			$r = Proveedor::crear( $this->post( 'nombre' ), array_map( 'intval', $this->post_array( 'pedidos' ) ) );
			is_wp_error( $r ) ? Notices::add( 'error', esc_html( $r->get_error_message() ) ) : Notices::add( 'success', esc_html( __( 'Supplier order created. Download it in Excel to send it to them.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) );
			$this->redirect( $volver );
		}
		if ( 'proveedor_recibir' === $accion ) {
			$id = (int) $this->post( 'pedido_proveedor' );
			check_admin_referer( 'asde_proveedor_' . $id );
			$recibidas = array();
			foreach ( (array) $this->post_texto_mapa( 'recibida' ) as $clave => $valor ) {
				$recibidas[ $clave ] = $valor;
			}
			$r = Proveedor::recibir( $id, $recibidas );
			if ( is_wp_error( $r ) ) {
				Notices::add( 'error', esc_html( $r->get_error_message() ) );
			} else {
				$m = __( 'Order marked as received: customers\' orders can now be delivered.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' );
				if ( $r['sobrante'] > 0 ) {
					$m .= ' ' . sprintf( /* translators: %d: number */ _n( '%d extra unit has arrived and has been added to the stock.', '%d extra units have arrived and have been added to the stock.', $r['sobrante'] , 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $r['sobrante'] );
				}
				if ( $r['faltan'] > 0 ) {
					$m .= ' ' . sprintf( /* translators: %d: number */ _n( '%d unit still to arrive.', '%d units still to arrive.', $r['faltan'] , 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $r['faltan'] );
				}
				Notices::add( 'success', esc_html( $m ) );
			}
			$this->redirect( $volver );
		}
		Notices::add( 'error', __( 'Unknown action.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
		$this->redirect( $volver );
	}

	/** Un campo `nombre[clave]` con cantidades: clave => texto (las claves son las de las líneas: «p|3|7», «camisa|masculina|M»…). @return array<string, string> */
	private function post_texto_mapa( string $campo ): array {
		$v   = isset( $_POST[ $campo ] ) && is_array( $_POST[ $campo ] ) ? wp_unslash( $_POST[ $campo ] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- el nonce lo comprueba handle_post(); cada valor se sanea aquí y se valida al usarlo
		$out = array();
		foreach ( array_slice( (array) $v, 0, 500, true ) as $clave => $valor ) {
			if ( is_scalar( $valor ) && preg_match( '/^[A-Za-z0-9_|\-]{1,80}$/', (string) $clave ) ) {
				$out[ (string) $clave ] = sanitize_text_field( (string) $valor );
			}
		}
		return $out;
	}
}
