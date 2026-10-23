<?php
namespace AsdeBalears\AreaPrivada\Frontend\Views;

use AsdeBalears\AreaPrivada\Auth\Ambito;
use AsdeBalears\AreaPrivada\Domain\Modulos;
use AsdeBalears\AreaPrivada\Informes\Catalogo;
use AsdeBalears\AreaPrivada\Informes\InformesTable;
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
		// v0.55 (doc 57): quien prepara el presupuesto de su sección ve la pestaña, aunque no configure Tesorería.
		if ( ! current_user_can( self::CAP_CONFIG ) && current_user_can( 'asde_presupuestos_seccion' ) && Modulos::activo( Modulos::PRESUPUESTOS ) ) {
			$items['presupuestos'] = asde_t( 'Presupuestos' );
		}
		if ( current_user_can( self::CAP_CONFIG ) ) {
			// v0.20: Banco y Domiciliaciones se pueden desactivar por grupo (wp-admin → Grupos Scouts → Módulos).
			if ( Modulos::activo( Modulos::BANCO ) ) {
				$items['banco'] = asde_t( 'Banco' );
			}
			// v0.54 (doc 57): el reparto de las cuotas entre el grupo y las secciones.
			if ( Modulos::activo( Modulos::PRESUPUESTOS ) ) {
				$items['presupuestos'] = asde_t( 'Presupuestos' );
			}
			if ( Modulos::activo( Modulos::SEPA ) ) {
				$items['sepa'] = asde_t( 'Domiciliaciones' );
			}
		}
		// v0.37: acceso directo a los informes de pagos (quien puede ver informes y los pagos).
		$enlaces = array();
		if ( current_user_can( 'asde_reports' ) && Catalogo::ve_pagos( Ambito::actual() ) ) {
			$cuota = InformesTable::predefinido( 'Pagos de la cuota' );
			if ( $cuota ) {
				$enlaces[ asde_t( 'Informe de pagos' ) ] = $this->url( 'informes', array( 'informe' => $cuota->id ) );
			}
			$enlaces[ asde_t( 'Todos los informes' ) ] = $this->url( 'informes' );
		}
		return $this->tpl(
			'tesoreria/subnav',
			array(
				'enlaces' => $enlaces,
				'items'  => $items,
				'activo' => $activo,
				'ronda'  => $ronda,
				'rondas' => Config::rondas(),
			)
		);
	}
}
