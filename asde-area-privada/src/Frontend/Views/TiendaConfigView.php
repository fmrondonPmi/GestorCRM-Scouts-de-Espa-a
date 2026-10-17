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
 * Configuración de los pedidos de uniforme del grupo (Tesorería y Coordinación):
 * precio de la camisa, modelos y tallas, insignias con su imagen y precio,
 * guías de tallas y de colocación, pedidos abiertos o cerrados y el texto de
 * cómo pagar. Las imágenes van a la biblioteca de medios de WordPress.
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
		$euros   = static fn( string $v ): ?float => '' === trim( $v ) ? null : max( 0.0, round( (float) str_replace( ',', '.', $v ), 2 ) );
		$lista   = static fn( string $v ): array => array_values( array_unique( array_filter( array_map( static fn( $t ) => mb_substr( trim( $t ), 0, 10 ), explode( ',', $v ) ) ) ) );

		$cfg['abierto']       = '1' === $this->post( 'abierto' );
		$cfg['primera_gratis'] = '1' === $this->post( 'primera_gratis' );
		$cfg['precio_camisa'] = $euros( $this->post( 'precio_camisa' ) ) ?? $cfg['precio_camisa'];
		$cfg['texto_pago']    = mb_substr( sanitize_textarea_field( $this->post( 'texto_pago' ) ), 0, 600 );
		$cfg['publica']       = '1' === $this->post( 'publica' );
		$cfg['dias_sin_pagar'] = min( 90, max( 0, (int) $this->post( 'dias_sin_pagar' ) ) );
		$cfg['iban']          = Config::iban( $this->post( 'iban' ) );
		$cfg['bizum']         = Config::bizum( $this->post( 'bizum' ) );
		if ( '' !== trim( $this->post( 'iban' ) ) && '' === $cfg['iban'] ) {
			$avisos[] = asde_t( 'El IBAN no parece válido; no se ha guardado.' );
		}
		if ( '' !== trim( $this->post( 'bizum' ) ) && '' === $cfg['bizum'] ) {
			$avisos[] = asde_t( 'El teléfono de Bizum no parece válido; no se ha guardado.' );
		}
		$cfg['tallas_especiales'] = $lista( $this->post( 'tallas_especiales' ) );

		foreach ( array_keys( $cfg['modelos'] ) as $id ) {
			$nombre = sanitize_text_field( $this->post( 'modelo_nombre_' . $id ) );
			$tallas = $lista( $this->post( 'modelo_tallas_' . $id ) );
			if ( '' !== $nombre ) {
				$cfg['modelos'][ $id ]['nombre'] = mb_substr( $nombre, 0, 60 );
			}
			if ( $tallas ) {
				$cfg['modelos'][ $id ]['tallas'] = $tallas;
			} else {
				$avisos[] = sprintf( asde_t( '%s: sin tallas no se puede pedir; se mantienen las anteriores.' ), $cfg['modelos'][ $id ]['nombre'] );
			}
			$cfg['modelos'][ $id ]['guia'] = $this->imagen( 'guia_' . $id, (int) $cfg['modelos'][ $id ]['guia'], $avisos );
		}
		$cfg['guia_insignias'] = $this->imagen( 'guia_insignias', (int) $cfg['guia_insignias'], $avisos );

		// Insignias que ya había: cambiar o quitar.
		foreach ( array_keys( $cfg['insignias'] ) as $id ) {
			if ( '1' === $this->post( 'insignia_quitar_' . $id ) ) {
				unset( $cfg['insignias'][ $id ] );
				continue;
			}
			$nombre = sanitize_text_field( $this->post( 'insignia_nombre_' . $id ) );
			$cfg['insignias'][ $id ] = array(
				'nombre' => '' !== $nombre ? mb_substr( $nombre, 0, 80 ) : $cfg['insignias'][ $id ]['nombre'],
				'lugar'  => mb_substr( sanitize_text_field( $this->post( 'insignia_lugar_' . $id ) ), 0, 60 ),
				'precio' => $euros( $this->post( 'insignia_precio_' . $id ) ),
				'imagen' => $this->imagen( 'insignia_imagen_' . $id, (int) $cfg['insignias'][ $id ]['imagen'], $avisos ),
				'activa' => '1' === $this->post( 'insignia_activa_' . $id ),
			);
		}
		// Nuevas.
		for ( $n = 0; $n < 2; $n++ ) {
			$nombre = sanitize_text_field( $this->post( 'nueva_nombre_' . $n ) );
			if ( '' === $nombre ) {
				continue;
			}
			$id                      = Config::id_para( $nombre, $cfg['insignias'] );
			$cfg['insignias'][ $id ] = array(
				'nombre' => mb_substr( $nombre, 0, 80 ),
				'lugar'  => mb_substr( sanitize_text_field( $this->post( 'nueva_lugar_' . $n ) ), 0, 60 ),
				'precio' => $euros( $this->post( 'nueva_precio_' . $n ) ),
				'imagen' => $this->imagen( 'nueva_imagen_' . $n, 0, $avisos ),
				'activa' => true,
			);
		}

		Config::guardar( $cfg );
		Logger::log( 'uniformes_configurados', 'grupo', \AsdeBalears\AreaPrivada\Domain\Grupo::numero(), array( 'insignias' => count( $cfg['insignias'] ), 'abierto' => $cfg['abierto'] ) );
		foreach ( $avisos as $a ) {
			Notices::add( 'warning', esc_html( $a ) );
		}
		Notices::add( 'success', asde_t( 'Configuración guardada.' ) );
		$this->redirect( $this->url( 'tienda_config' ) );
	}
}
