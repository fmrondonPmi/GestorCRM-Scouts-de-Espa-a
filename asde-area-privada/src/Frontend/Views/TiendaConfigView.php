<?php
namespace AsdeBalears\AreaPrivada\Frontend\Views;

use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Frontend\Notices;
use AsdeBalears\AreaPrivada\Domain\Grupo;
use AsdeBalears\AreaPrivada\Tienda\Config;
use AsdeBalears\AreaPrivada\Tienda\Enlace;
use AsdeBalears\AreaPrivada\Tienda\Servicio;

defined( 'ABSPATH' ) || exit;

/**
 * Configuración de la tienda del grupo (Coordinación, Tesorería y Secretaría): pedidos abiertos o
 * cerrados, cómo pagar (IBAN, Bizum y texto), la tienda pública y los días para recordar o cancelar
 * lo que no se paga. Los productos y sus precios están en «Productos» (v0.44: ya no hay modelos de
 * camisa ni insignias propios).
 */
final class TiendaConfigView extends TiendaBaseView {

	public function render( string $content = '' ): string {
		if ( ! current_user_can( Servicio::CAP_CONFIG ) ) {
			return $this->denegado();
		}
		return $this->layout(
			'tienda_config',
			$this->subnav( 'tienda_config' ) . $this->tpl(
				'tienda/config',
				array(
					'cfg' => Config::de(),
					'enlace' => Grupo::actual() ? Enlace::url( Grupo::actual() ) : '', // v0.39 (fase 4): la tienda pública.
					'url' => $this->url( 'tienda_config' ),
				)
			)
		);
	}

	public function handle_post(): void {
		if ( ! current_user_can( Servicio::CAP_CONFIG ) ) {
			wp_die( esc_html__( 'No tienes permiso para hacer esto.', 'asde-area-privada' ), 403 );
		}
		check_admin_referer( 'asde_tienda_admin' );
		if ( 'tienda_enlace_renovar' === $this->post( 'asde_action' ) ) {
			if ( Grupo::actual() ) {
				Enlace::renovar( Grupo::actual() );
				Logger::log( 'tienda_enlace_renovado', 'grupo', Grupo::numero() );
				Notices::add( 'success', asde_t( 'Enlace nuevo creado. El anterior ya no vale.' ) );
			}
			$this->redirect( $this->url( 'tienda_config' ) );
		}
		$cfg     = Config::de();
		$avisos  = array();
		$cfg['abierto']       = '1' === $this->post( 'abierto' );
		$cfg['texto_pago']    = mb_substr( sanitize_textarea_field( $this->post( 'texto_pago' ) ), 0, 600 );
		$cfg['publica']       = '1' === $this->post( 'publica' );
		$cfg['datafono']      = '1' === $this->post( 'datafono' );
		$cfg['menu_tienda']   = mb_substr( sanitize_text_field( $this->post( 'menu_tienda' ) ), 0, 40 );
		$cfg['dias_sin_pagar'] = min( 90, max( 0, (int) $this->post( 'dias_sin_pagar' ) ) );
		$cfg['dias_aviso_socios']    = min( 90, max( 0, (int) $this->post( 'dias_aviso_socios' ) ) );
		$cfg['dias_cancelar_socios'] = min( 180, max( 0, (int) $this->post( 'dias_cancelar_socios' ) ) );
		$cfg['iban']          = Config::iban( $this->post( 'iban' ) );
		$cfg['bizum']         = Config::bizum( $this->post( 'bizum' ) );
		if ( '' !== trim( $this->post( 'iban' ) ) && '' === $cfg['iban'] ) {
			$avisos[] = asde_t( 'El IBAN no parece válido; no se ha guardado.' );
		}
		if ( '' !== trim( $this->post( 'bizum' ) ) && '' === $cfg['bizum'] ) {
			$avisos[] = asde_t( 'El teléfono de Bizum no parece válido; no se ha guardado.' );
		}

		Config::guardar( $cfg );
		Logger::log( 'uniformes_configurados', 'grupo', \AsdeBalears\AreaPrivada\Domain\Grupo::numero(), array( 'abierto' => $cfg['abierto'], 'publica' => $cfg['publica'] ) );
		foreach ( $avisos as $a ) {
			Notices::add( 'warning', esc_html( $a ) );
		}
		Notices::add( 'success', asde_t( 'Configuración guardada.' ) );
		$this->redirect( $this->url( 'tienda_config' ) );
	}
}
