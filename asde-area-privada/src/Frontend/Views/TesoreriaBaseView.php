<?php
namespace AsdeBalears\AreaPrivada\Frontend\Views;

use AsdeBalears\AreaPrivada\Tesoreria\Config;

defined( 'ABSPATH' ) || exit;

/** Base de las páginas de Tesorería: pestañas y ronda seleccionada. */
abstract class TesoreriaBaseView extends View {

	public const CAP        = 'asde_tesoreria_acceso';
	public const CAP_CONFIG = 'asde_tesoreria_config';

	protected function ronda(): string {
		$r = $this->get( 'ronda' ) ?: $this->post( 'ronda' );
		return Config::es_ronda( $r ) ? $r : Config::ronda_actual();
	}

	protected function subnav( string $activo, string $ronda ): string {
		$items = array(
			'tesoreria'  => asde_t( 'Control de pagos' ),
			'familias'   => asde_t( 'Familias' ),
			'cuotas'     => asde_t( 'Cuotas y plazos' ),
			'federacion' => asde_t( 'Coste federativo' ),
		);
		if ( current_user_can( self::CAP_CONFIG ) ) {
			$items['banco'] = asde_t( 'Banco' );
			$items['sepa']  = asde_t( 'Domiciliaciones' );
		}
		return $this->tpl(
			'tesoreria/subnav',
			array(
				'items'  => $items,
				'activo' => $activo,
				'ronda'  => $ronda,
				'rondas' => Config::rondas(),
			)
		);
	}
}
