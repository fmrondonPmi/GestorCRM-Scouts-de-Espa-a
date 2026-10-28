<?php
namespace AsdeBalears\AreaPrivada\Frontend\Views;

use AsdeBalears\AreaPrivada\Auth\Ambito;
use AsdeBalears\AreaPrivada\Tienda\Servicio;

defined( 'ABSPATH' ) || exit;

/** Base de las páginas de la tienda: pestañas «Mis pedidos · Gestión · Inventario de la tienda · Configuración». */
abstract class TiendaBaseView extends View {

	protected const MAX_IMAGEN = 3 * MB_IN_BYTES;

	protected const MIMES = array(
		'jpg|jpeg|jpe' => 'image/jpeg',
		'png'          => 'image/png',
		'webp'         => 'image/webp',
		'gif'          => 'image/gif',
	);

	protected function subnav( string $activo ): string {
		$items = array();
		if ( current_user_can( Servicio::CAP_PEDIR ) && Servicio::propios( Ambito::actual() ) ) {
			$items['tienda_pedidos'] = __( 'My orders', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' );
		}
		if ( current_user_can( Servicio::CAP_GESTION ) ) {
			$items['tienda_gestion'] = __( 'Order management', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' );
		}
		if ( \AsdeBalears\AreaPrivada\Tienda\Proveedor::puede_ver() ) {
			$items['tienda_proveedor'] = __( 'Shirt order', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); // v0.48 (doc 48, T4).
		}
		if ( current_user_can( Servicio::CAP_ADMIN ) ) {
			$items['tienda_productos'] = __( 'Shop inventory', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' );
		}
		if ( current_user_can( Servicio::CAP_CONFIG ) ) {
			$items['tienda_config'] = __( 'Settings', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' );
		}
		return count( $items ) > 1 ? $this->tpl(
			'tienda/subnav',
			array(
				'items'  => $items,
				'activo' => $activo,
			)
		) : '';
	}

	/** Nombres del CRM de los socios de estos pedidos (si el CRM no responde, se queda con el número). @return array<string, \AsdeBalears\AreaPrivada\Domain\Persona> */
	protected function personas( array $pedidos ): array {
		$repo = \AsdeBalears\AreaPrivada\Crm\PersonaRepository::make();
		if ( ! $repo || ! $pedidos ) {
			return array();
		}
		try {
			return $repo->obtener_varias( array_column( $pedidos, 'socio' ) );
		} catch ( \AsdeBalears\AreaPrivada\Crm\CrmException $e ) {
			return array();
		}
	}

	/**
	 * Imagen subida en ese campo (a la biblioteca de medios), la actual, o ninguna
	 * si se ha marcado «Quitar». @param string[] $avisos
	 */
	protected function imagen( string $campo, int $actual, array &$avisos ): int {
		if ( '1' === $this->post( $campo . '_quitar' ) ) {
			$actual = 0;
		}
		$f = $_FILES[ $campo ] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.NonceVerification.Missing -- el nonce se comprueba antes (check_admin_referer en handle_post); del fichero solo se usan datos validados con is_uploaded_file()
		if ( ! is_array( $f ) || UPLOAD_ERR_NO_FILE === (int) ( $f['error'] ?? UPLOAD_ERR_NO_FILE ) ) {
			return $actual;
		}
		if ( UPLOAD_ERR_OK !== (int) $f['error'] || (int) $f['size'] > self::MAX_IMAGEN ) {
			$avisos[] = __( 'An image could not be uploaded: it must be JPG, PNG, WebP or GIF of up to 3 MB.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' );
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
			$avisos[] = __( 'An image could not be uploaded: ', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) . $id->get_error_message();
			return $actual;
		}
		return (int) $id;
	}
}
