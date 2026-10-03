<?php
namespace AsdeBalears\AreaPrivada\Frontend\Views;

use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Auth\Ambito;
use AsdeBalears\AreaPrivada\Frontend\Notices;
use AsdeBalears\AreaPrivada\Uniformes\Config;
use AsdeBalears\AreaPrivada\Uniformes\PedidosTable;
use AsdeBalears\AreaPrivada\Uniformes\Servicio;

defined( 'ABSPATH' ) || exit;

/**
 * «Uniforme» para los asociados: sus pedidos (y los de sus menores), el
 * formulario para pedir la camisa y las insignias, y la confirmación de que se
 * ha recibido. Ver Uniformes\Servicio.
 */
final class UniformeView extends UniformesBaseView {

	public function render( string $content = '' ): string {
		if ( ! current_user_can( Servicio::CAP_PEDIR ) ) {
			return current_user_can( Servicio::CAP_GESTION ) ? $this->redirigir_gestion() : $this->denegado();
		}
		$a    = Ambito::actual();
		$para = Servicio::para_quien( $a );
		if ( ! $para && current_user_can( Servicio::CAP_GESTION ) ) {
			return $this->redirigir_gestion();
		}
		$cfg     = Config::de();
		$pedidos = PedidosTable::de_socios( array_keys( $para ) );
		$guias   = array();
		foreach ( $cfg['modelos'] as $id => $m ) {
			$url = Config::guia_medidas( $cfg, (string) $id );
			if ( '' !== $url ) {
				$guias[ $id ] = array(
					'nombre' => $m['nombre'],
					'url'    => $url,
				);
			}
		}
		return $this->layout(
			'uniforme',
			$this->subnav( 'uniforme' ) . $this->tpl(
				'uniformes/mis-pedidos',
				array(
					'cfg'      => $cfg,
					'para'     => $para,
					'pedidos'  => $pedidos,
					'ambito'   => $a,
					'guias'    => $guias,
					'colocar'  => Config::imagen( $cfg['guia_insignias'], 'large' ),
					'url'      => $this->url( 'uniforme' ),
					'url_pagos' => $this->url( 'pagos' ),
				)
			)
		);
	}

	private function redirigir_gestion(): string {
		return $this->layout( 'uniforme', $this->subnav( 'uniforme' ) . '<section class="asde-card"><p>' . sprintf( asde_t( 'Desde aquí se gestionan los pedidos del grupo: %s.' ), '<a href="' . esc_url( $this->url( 'uniformes' ) ) . '">' . esc_html( asde_t( 'Gestión de pedidos' ) ) . '</a>' ) . '</p></section>' );
	}

	public function handle_post(): void {
		if ( ! current_user_can( Servicio::CAP_PEDIR ) ) {
			wp_die( esc_html__( 'No tienes permiso para hacer esto.', 'asde-area-privada' ), 403 );
		}
		$a      = Ambito::actual();
		$volver = $this->url( 'uniforme' );

		if ( 'uniforme_pedir' === $this->post( 'asde_action' ) ) {
			check_admin_referer( 'asde_uniforme_pedir' );
			$cfg        = Config::de();
			$cantidades = array();
			foreach ( Config::insignias_activas( $cfg ) as $id => $i ) {
				$cantidades[ $id ] = (int) $this->post( 'insignia_' . $id );
			}
			$lineas = Servicio::lineas( $cfg, '1' === $this->post( 'con_camisa' ) ? $this->post( 'camisa' ) : '', $cantidades );
			$r      = is_wp_error( $lineas ) ? $lineas : Servicio::pedir( $a, $this->post( 'socio' ), $lineas, $this->post( 'nota' ) );
			if ( is_wp_error( $r ) ) {
				Notices::add( 'error', esc_html( $r->get_error_message() ) );
			} else {
				$p = PedidosTable::obtener( $r );
				Notices::add( 'success', sprintf( asde_t( 'Pedido <strong>%s</strong> enviado: %s. Ahora falta pagarlo; cuando Tesorería lo reciba, lo verás aquí como pagado.' ), esc_html( PedidosTable::numero( $p ) ), esc_html( Servicio::importe( $p ) ) ) );
			}
			$this->redirect( $volver );
		}

		$p = PedidosTable::obtener( (int) $this->post( 'pedido' ) );
		check_admin_referer( 'asde_uniforme_' . ( $p['id'] ?? 0 ) );
		if ( ! $p || ! Servicio::es_propio( $p, $a ) ) {
			Notices::add( 'error', asde_t( 'No tienes acceso a ese pedido.' ) );
			$this->redirect( $volver );
		}
		switch ( $this->post( 'asde_action' ) ) {
			case 'uniforme_cancelar':
				if ( Servicio::puede_cancelar_propio( $p, $a ) && PedidosTable::cancelar( $p['id'], 'Cancelado por el socio/a o su familia' ) ) {
					Logger::log( 'uniforme_cancelado', 'uniforme', (string) $p['id'], array( 'por' => 'socio' ) );
					Notices::add( 'success', asde_t( 'Pedido cancelado.' ) );
				} else {
					Notices::add( 'error', asde_t( 'Ese pedido ya no se puede cancelar desde aquí: ya consta el pago o está en el pedido del grupo. Habla con el grupo.' ) );
				}
				break;
			case 'uniforme_recibido':
				if ( Servicio::puede_confirmar( $p, $a ) && PedidosTable::marcar_recibido( $p['id'] ) ) {
					Logger::log( 'uniforme_recibido', 'uniforme', (string) $p['id'] );
					Notices::add( 'success', asde_t( '¡Gracias! Queda confirmado que lo has recibido.' ) );
				} else {
					Notices::add( 'error', asde_t( 'Ese pedido aún no consta como entregado.' ) );
				}
				break;
			default:
				Notices::add( 'error', asde_t( 'Acción desconocida.' ) );
		}
		$this->redirect( $volver );
	}
}
