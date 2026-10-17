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
 * «Uniforme» para los asociados: sus pedidos (y los de sus menores), el
 * formulario para pedir la camisa y las insignias, y la confirmación de que se
 * ha recibido. Ver Tienda\Servicio.
 *
 * v0.30 (P-48): un scouter pide también para los socios/as de sus secciones; con muchas
 * personas, «¿Para quién?» es un desplegable agrupado por sección.
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
			return $this->layout( 'tienda_pedidos', $this->subnav( 'tienda_pedidos' ) . '<section class="asde-card"><p>' . esc_html( asde_t( 'Ahora mismo ninguna persona de tu cuenta tiene la membresía activa, así que no se puede hacer ningún pedido.' ) ) . '</p></section>' );
		}
		$cfg   = Config::de();
		$todos = PedidosTable::de_socios( array_keys( $para ) ); // Una consulta para todas las personas.
		// v0.29: lo que le cuesta la camisa a cada uno (la primera de un scouter, gratis); v0.30, sin una consulta por persona.
		$precios = Servicio::precios_camisa( $para, $cfg, $todos );
		// «Tus pedidos»: los suyos y los de su familia y, v0.30, los que ha pedido para alguien de su sección.
		$pedidos = array_values( array_filter( $todos, static fn( array $p ) => Servicio::es_propio( $p, $a ) || Servicio::lo_pidio( $p ) ) );
		$es_socio = (bool) array_filter( $para, static fn( $persona ) => $persona->tiene_membresia_activa() );
		$guias    = array();
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
			'tienda_pedidos',
			$this->subnav( 'tienda_pedidos' ) . $this->tpl(
				'tienda/mis-pedidos',
				array(
					'cfg'      => $cfg,
					'para'     => $para,
					'grupos'   => count( $para ) > Servicio::MAX_BOTONES_PARA ? Servicio::agrupar_para_quien( $a, $para ) : array(),
					'otros'    => count( $para ) > count( Servicio::propios( $a ) ),
					'catalogo' => Servicio::catalogo( $es_socio ), // v0.39: lo que ve esta cuenta (lo «solo socios», solo si hay un socio/a entre sus personas).
					'es_socio' => $es_socio,
					'precios'  => $precios,
					'pedidos'  => $pedidos,
					'ambito'   => $a,
					'guias'    => $guias,
					'colocar'  => Config::imagen( $cfg['guia_insignias'], 'large' ),
					'url'      => $this->url( 'tienda_pedidos' ),
					'url_pagos' => $this->url( 'pagos' ),
				)
			)
		);
	}

	private function redirigir_gestion(): string {
		return $this->layout( 'tienda_pedidos', $this->subnav( 'tienda_pedidos' ) . '<section class="asde-card"><p>' . sprintf( asde_t( 'Desde aquí se gestionan los pedidos del grupo: %s.' ), '<a href="' . esc_url( $this->url( 'tienda_gestion' ) ) . '">' . esc_html( asde_t( 'Gestión de pedidos' ) ) . '</a>' ) . '</p></section>' );
	}

	public function handle_post(): void {
		if ( ! current_user_can( Servicio::CAP_PEDIR ) ) {
			wp_die( esc_html__( 'No tienes permiso para hacer esto.', 'asde-area-privada' ), 403 );
		}
		$a      = Ambito::actual();
		$volver = $this->url( 'tienda_pedidos' );

		if ( 'tienda_comprar' === $this->post( 'asde_action' ) ) {
			check_admin_referer( 'asde_tienda_comprar' );
			$r = Servicio::comprar( $a, $this->post( 'socio' ), $this->post_mapa( 'cant' ), $this->post( 'nota' ), $this->post( 'forma_pago' ) );
			if ( is_wp_error( $r ) ) {
				Notices::add( 'error', esc_html( $r->get_error_message() ) );
			} else {
				$p = PedidosTable::obtener( $r );
				Notices::add( 'success', sprintf( asde_t( 'Pedido <strong>%1$s</strong> enviado: %2$s. Ahora falta pagarlo: en «Tus pedidos» tienes cómo hacerlo.' ), esc_html( PedidosTable::numero( $p ) ), esc_html( Servicio::importe( $p ) ) ) );
			}
			$this->redirect( $volver );
		}

		if ( 'uniforme_pedir' === $this->post( 'asde_action' ) ) {
			check_admin_referer( 'asde_uniforme_pedir' );
			$cfg        = Config::de();
			$cantidades = array();
			foreach ( Config::insignias_activas( $cfg ) as $id => $i ) {
				$cantidades[ $id ] = (int) $this->post( 'insignia_' . $id );
			}
			$lineas = Servicio::lineas( $cfg, '1' === $this->post( 'con_camisa' ) ? $this->post( 'camisa' ) : '', $cantidades );
			$r      = is_wp_error( $lineas ) ? $lineas : Servicio::pedir( $a, $this->post( 'socio' ), $lineas, $this->post( 'nota' ), $this->post( 'forma_pago' ) );
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
		// v0.30: también quien lo pidió (un scouter para alguien de su sección).
		if ( ! $p || ! ( Servicio::es_propio( $p, $a ) || Servicio::lo_pidio( $p ) ) ) {
			Notices::add( 'error', asde_t( 'No tienes acceso a ese pedido.' ) );
			$this->redirect( $volver );
		}
		switch ( $this->post( 'asde_action' ) ) {
			case 'uniforme_cancelar':
				if ( Servicio::puede_cancelar_propio( $p, $a ) && Servicio::cancelar( $p, 'Cancelado por el socio/a o su familia' ) ) {
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
