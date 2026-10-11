<?php
namespace AsdeBalears\AreaPrivada\Frontend\Views;

use AsdeBalears\AreaPrivada\Auth\Ambito;
use AsdeBalears\AreaPrivada\Uniformes\Servicio;

defined( 'ABSPATH' ) || exit;

/** Base de las páginas de uniformes: pestañas «Mis pedidos · Gestión · Configuración». */
abstract class UniformesBaseView extends View {

	protected function subnav( string $activo ): string {
		$items = array();
		if ( current_user_can( Servicio::CAP_PEDIR ) && Servicio::propios( Ambito::actual() ) ) {
			$items['uniforme'] = asde_t( 'Mis pedidos' );
		}
		if ( current_user_can( Servicio::CAP_GESTION ) ) {
			$items['uniformes'] = asde_t( 'Gestión de pedidos' );
		}
		if ( current_user_can( Servicio::CAP_CONFIG ) ) {
			$items['uniformes_config'] = asde_t( 'Configuración' );
		}
		return count( $items ) > 1 ? $this->tpl(
			'uniformes/subnav',
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
}
