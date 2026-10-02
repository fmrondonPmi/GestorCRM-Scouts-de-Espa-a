<?php
namespace AsdeBalears\AreaPrivada\Frontend\Views;

use AsdeBalears\AreaPrivada\Integraciones\Wordfence;

defined( 'ABSPATH' ) || exit;

/**
 * Seguridad de la cuenta: verificación en dos pasos (Wordfence) desde el área
 * privada, porque estos usuarios no entran en wp-admin.
 * El contenido es el shortcode de Wordfence que va dentro del nuestro.
 */
final class SeguridadView extends View {

	public function render( string $content = '' ): string {
		$user   = wp_get_current_user();
		$limite = null;
		return $this->layout(
			'seguridad',
			$this->tpl(
				'seguridad/pagina',
				array(
					'disponible' => Wordfence::disponible(),
					'activo'     => Wordfence::activo( $user ),
					'pendiente'  => Wordfence::pendiente( $user, $limite ),
					'limite'     => $limite,
					'wordfence'  => do_shortcode( $content ),
				)
			)
		);
	}
}
