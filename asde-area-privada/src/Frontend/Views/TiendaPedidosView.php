<?php
namespace AsdeBalears\AreaPrivada\Frontend\Views;

use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Auth\Ambito;
use AsdeBalears\AreaPrivada\Frontend\Notices;
use AsdeBalears\AreaPrivada\Tienda\Config;
use AsdeBalears\AreaPrivada\Tienda\PedidosTable;
use AsdeBalears\AreaPrivada\Tienda\Servicio;

defined( 'ABSPATH' ) || exit;

/**
 * «Mis pedidos» para los asociados: sus pedidos (y los de sus menores), el catálogo
 * de la tienda para comprar y la confirmación de que se ha recibido. Ver Tienda\Servicio.
 *
 * v0.44 (doc 48): el uniforme es una venta más; ya no hay formulario propio de camisa e
 * insignias ni pedidos de un scouter para su sección.
 */
final class TiendaPedidosView extends TiendaBaseView {

	public function render( string $content = '' ): string {
		if ( ! current_user_can( Servicio::CAP_PEDIR ) ) {
			return current_user_can( Servicio::CAP_GESTION ) ? $this->redirigir_gestion() : $this->denegado();
		}
		$a    = Ambito::actual();
		$para = Servicio::para_quien( $a );
		if ( ! $para && current_user_can( Servicio::CAP_GESTION ) ) {
			return $this->redirigir_gestion();
		}
		if ( ! $para ) {
			// v0.39: una cuenta sin ninguna persona con membresía activa no tiene a quién pedirle nada.
			return $this->layout( 'tienda_pedidos', $this->subnav( 'tienda_pedidos' ) . '<section class="asde-card"><p>' . esc_html( __( 'Right now nobody in your account has an active membership, so no order can be placed.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) . '</p></section>' );
		}
		$cfg   = Config::de();
		$todos = PedidosTable::de_socios( array_keys( $para ) ); // Una consulta para todas las personas.
		// «Tus pedidos»: los suyos y los de su familia y los que pidió esta cuenta (pedidos anteriores de un scouter).
		$pedidos = array_values( array_filter( $todos, static fn( array $p ) => Servicio::es_propio( $p, $a ) || Servicio::lo_pidio( $p ) ) );
		$es_socio = (bool) array_filter( $para, static fn( $persona ) => $persona->tiene_membresia_activa() );
		return $this->layout(
			'tienda_pedidos',
			$this->subnav( 'tienda_pedidos' ) . $this->tpl(
				'tienda/mis-pedidos',
				array(
					'cfg'      => $cfg,
					'para'     => $para,
					'catalogo' => Servicio::catalogo( $es_socio ), // v0.39: lo que ve esta cuenta (lo «solo socios», solo si hay un socio/a entre sus personas).
					'es_socio' => $es_socio,
					'pedidos'  => $pedidos,
					'ambito'   => $a,
					'url'      => $this->url( 'tienda_pedidos' ),
					'url_pagos' => $this->url( 'pagos' ),
				)
			)
		);
	}

	private function redirigir_gestion(): string {
		return $this->layout( 'tienda_pedidos', $this->subnav( 'tienda_pedidos' ) . '<section class="asde-card"><p>' . sprintf( /* translators: %s: text */ __( 'The group\'s orders are managed from here: %s.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), '<a href="' . esc_url( $this->url( 'tienda_gestion' ) ) . '">' . esc_html( __( 'Order management', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) . '</a>' ) . '</p></section>' );
	}

	public function handle_post(): void {
		if ( ! current_user_can( Servicio::CAP_PEDIR ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), 403 );
		}
		$a      = Ambito::actual();
		$volver = $this->url( 'tienda_pedidos' );

		if ( 'tienda_comprar' === $this->post( 'asde_action' ) ) {
			check_admin_referer( 'asde_tienda_comprar' );
			$sin_talla = array();
			$cantidades = $this->post_cantidades( $sin_talla );
			$r = $sin_talla
				? new \WP_Error( 'asde_tienda', sprintf( /* translators: %s: text */ __( 'Choose the size of "%s".', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), implode( '», «', $sin_talla ) ) )
				: Servicio::comprar( $a, $this->post( 'socio' ), $cantidades, $this->post( 'nota' ), $this->post( 'forma_pago' ) );
			if ( is_wp_error( $r ) ) {
				Notices::add( 'error', esc_html( $r->get_error_message() ) );
			} else {
				$p = PedidosTable::obtener( $r );
				Notices::add( 'success', sprintf( /* translators: 1: text, 2: text */ __( 'Order <strong>%1$s</strong> sent: %2$s. It now has to be paid: in "Your orders" you can see how.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), esc_html( PedidosTable::numero( $p ) ), esc_html( Servicio::importe( $p ) ) ) );
			}
			$this->redirect( $volver );
		}

		$p = PedidosTable::obtener( (int) $this->post( 'pedido' ) );
		check_admin_referer( 'asde_uniforme_' . ( $p['id'] ?? 0 ) );
		// v0.30: también quien lo pidió (un scouter para alguien de su sección).
		if ( ! $p || ! ( Servicio::es_propio( $p, $a ) || Servicio::lo_pidio( $p ) ) ) {
			Notices::add( 'error', __( 'You do not have access to that order.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
			$this->redirect( $volver );
		}
		switch ( $this->post( 'asde_action' ) ) {
			case 'uniforme_cancelar':
				if ( Servicio::puede_cancelar_propio( $p, $a ) && Servicio::cancelar( $p, 'Cancelado por el socio/a o su familia' ) ) {
					Logger::log( 'uniforme_cancelado', 'uniforme', (string) $p['id'], array( 'por' => 'socio' ) );
					Notices::add( 'success', __( 'Order cancelled.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
				} else {
					Notices::add( 'error', __( 'That order can no longer be cancelled from here: the payment is already recorded or it is in the group\'s order. Talk to the group.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
				}
				break;
			case 'uniforme_recibido':
				if ( Servicio::puede_confirmar( $p, $a ) && PedidosTable::marcar_recibido( $p['id'] ) ) {
					Logger::log( 'uniforme_recibido', 'uniforme', (string) $p['id'] );
					Notices::add( 'success', __( 'Thank you! It is confirmed that you have received it.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
				} else {
					Notices::add( 'error', __( 'That order is not yet recorded as delivered.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
				}
				break;
			default:
				Notices::add( 'error', __( 'Unknown action.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
		}
		$this->redirect( $volver );
	}
}
