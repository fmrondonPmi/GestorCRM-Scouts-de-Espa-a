<?php
namespace AsdeBalears\AreaPrivada\Frontend\Views;

use AsdeBalears\AreaPrivada\Eventos\Servicio;

defined( 'ABSPATH' ) || exit;

/** Base de las páginas de eventos: pestañas «Agenda · Configuración». */
abstract class EventosBaseView extends View {

	protected function subnav( string $activo ): string {
		$items = array( 'eventos' => asde_t( 'Agenda' ) );
		if ( current_user_can( Servicio::CAP_CONFIG ) ) {
			$items['eventos_config'] = asde_t( 'Configuración' );
		}
		return count( $items ) > 1 ? $this->tpl(
			'eventos/subnav',
			array(
				'items'  => $items,
				'activo' => $activo,
			)
		) : '';
	}

	/** Nombres del CRM (instantánea) de estas personas; si el CRM no responde, se quedan con el número. @return array<string, \AsdeBalears\AreaPrivada\Domain\Persona> */
	protected function personas( array $nums ): array {
		$repo = \AsdeBalears\AreaPrivada\Crm\PersonaRepository::make();
		if ( ! $repo || ! $nums ) {
			return array();
		}
		try {
			return $repo->obtener_varias( $nums );
		} catch ( \AsdeBalears\AreaPrivada\Crm\CrmException $e ) {
			return array();
		}
	}
}
