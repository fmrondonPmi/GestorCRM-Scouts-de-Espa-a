<?php
namespace AsdeBalears\AreaPrivada\Frontend\Views;

use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Frontend\Notices;
use AsdeBalears\AreaPrivada\Uniformes\Config;
use AsdeBalears\AreaPrivada\Uniformes\Servicio;

defined( 'ABSPATH' ) || exit;

/**
 * Configuración de los pedidos de uniforme del grupo (Tesorería y Coordinación):
 * precio de la camisa, modelos y tallas, insignias con su imagen y precio,
 * guías de tallas y de colocación, pedidos abiertos o cerrados y el texto de
 * cómo pagar. Las imágenes van a la biblioteca de medios de WordPress.
 */
final class UniformesConfigView extends UniformesBaseView {

	private const MAX_IMAGEN = 3 * MB_IN_BYTES;

	private const MIMES = array(
		'jpg|jpeg|jpe' => 'image/jpeg',
		'png'          => 'image/png',
		'webp'         => 'image/webp',
		'gif'          => 'image/gif',
	);

	public function render( string $content = '' ): string {
		if ( ! current_user_can( Servicio::CAP_CONFIG ) ) {
			return $this->denegado();
		}
		return $this->layout(
			'uniformes_config',
			$this->subnav( 'uniformes_config' ) . $this->tpl(
				'uniformes/config',
				array(
					'cfg' => Config::de(),
					'url' => $this->url( 'uniformes_config' ),
				)
			)
		);
	}

	public function handle_post(): void {
		if ( ! current_user_can( Servicio::CAP_CONFIG ) ) {
			wp_die( esc_html__( 'No tienes permiso para hacer esto.', 'asde-area-privada' ), 403 );
		}
		check_admin_referer( 'asde_uniformes_config' );
		$cfg     = Config::de();
		$avisos  = array();
		$euros   = static fn( string $v ): ?float => '' === trim( $v ) ? null : max( 0.0, round( (float) str_replace( ',', '.', $v ), 2 ) );
		$lista   = static fn( string $v ): array => array_values( array_unique( array_filter( array_map( static fn( $t ) => mb_substr( trim( $t ), 0, 10 ), explode( ',', $v ) ) ) ) );

		$cfg['abierto']       = '1' === $this->post( 'abierto' );
		$cfg['precio_camisa'] = $euros( $this->post( 'precio_camisa' ) ) ?? $cfg['precio_camisa'];
		$cfg['texto_pago']    = mb_substr( sanitize_textarea_field( $this->post( 'texto_pago' ) ), 0, 600 );
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
				$avisos[] = sprintf( '%s: sin tallas no se puede pedir; se mantienen las anteriores.', $cfg['modelos'][ $id ]['nombre'] );
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
		Notices::add( 'success', 'Configuración guardada.' );
		$this->redirect( $this->url( 'uniformes_config' ) );
	}

	/**
	 * Imagen subida en ese campo (a la biblioteca de medios), la actual, o ninguna
	 * si se ha marcado «Quitar». @param string[] $avisos
	 */
	private function imagen( string $campo, int $actual, array &$avisos ): int {
		if ( '1' === $this->post( $campo . '_quitar' ) ) {
			$actual = 0;
		}
		$f = $_FILES[ $campo ] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( ! is_array( $f ) || UPLOAD_ERR_NO_FILE === (int) ( $f['error'] ?? UPLOAD_ERR_NO_FILE ) ) {
			return $actual;
		}
		if ( UPLOAD_ERR_OK !== (int) $f['error'] || (int) $f['size'] > self::MAX_IMAGEN ) {
			$avisos[] = 'Una imagen no se ha podido subir: tiene que ser JPG, PNG, WebP o GIF de hasta 3 MB.';
			return $actual;
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$id = media_handle_upload(
			$campo,
			0,
			array(),
			array(
				'test_form' => false,
				'mimes'     => self::MIMES,
			)
		);
		if ( is_wp_error( $id ) ) {
			$avisos[] = 'Una imagen no se ha podido subir: ' . $id->get_error_message();
			return $actual;
		}
		return (int) $id;
	}
}
