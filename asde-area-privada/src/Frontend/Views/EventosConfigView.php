<?php
namespace AsdeBalears\AreaPrivada\Frontend\Views;

use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Crm\Metadatos;
use AsdeBalears\AreaPrivada\Domain\Grupo;
use AsdeBalears\AreaPrivada\Eventos\Config;
use AsdeBalears\AreaPrivada\Eventos\Servicio;
use AsdeBalears\AreaPrivada\Frontend\Notices;

defined( 'ABSPATH' ) || exit;

/**
 * Configuración de los eventos del grupo (Coordinación y Tesorería): margen del fondo de
 * emergencia, tipos de actividad y qué valores de la formación de tiempo libre del CRM
 * cuentan como título de director/a o de monitor/a.
 */
final class EventosConfigView extends EventosBaseView {

	public function render( string $content = '' ): string {
		if ( ! current_user_can( Servicio::CAP_CONFIG ) ) {
			return $this->denegado();
		}
		return $this->layout(
			'eventos_config',
			$this->subnav( 'eventos_config' ) . $this->tpl(
				'eventos/config',
				array(
					'cfg'      => Config::de(),
					// Las opciones del campo, si el CRM las da (sin datos de personas; se guardan 12 horas).
					'opciones' => (array) ( Metadatos::campos( 'Contacts', array( 'formaciontiempolibre_c' ) )['formaciontiempolibre_c']['opciones'] ?? array() ),
					'url'      => $this->url( 'eventos_config' ),
				)
			)
		);
	}

	public function handle_post(): void {
		if ( ! current_user_can( Servicio::CAP_CONFIG ) ) {
			wp_die( esc_html__( 'No tienes permiso para hacer esto.', 'asde-area-privada' ), 403 );
		}
		check_admin_referer( 'asde_eventos_config' );
		$cfg = Config::de();

		$cfg['margen_activo'] = '1' === $this->post( 'margen_activo' );
		$pct                  = (float) str_replace( ',', '.', $this->post( 'margen_pct' ) );
		if ( $pct < 0 || $pct > 50 ) {
			Notices::add( 'error', asde_t( 'El margen del fondo de emergencia tiene que estar entre 0 y 50 %.' ) );
			$this->redirect( $this->url( 'eventos_config' ) );
		}
		$cfg['margen_pct'] = round( $pct, 2 );

		foreach ( array_keys( $cfg['tipos'] ) as $id ) {
			$nombre = trim( sanitize_text_field( $this->post( 'tipo_nombre_' . $id ) ) );
			if ( '' !== $nombre ) {
				$cfg['tipos'][ $id ]['nombre'] = mb_substr( $nombre, 0, 60 );
			}
			$cfg['tipos'][ $id ]['coste']        = '1' === $this->post( 'tipo_coste_' . $id );
			$cfg['tipos'][ $id ]['comunicacion'] = 'no' === $this->post( 'tipo_comunicacion_' . $id ) ? 'no' : 'auto';
			$cfg['tipos'][ $id ]['activo']       = '1' === $this->post( 'tipo_activo_' . $id );
		}
		$nuevo = trim( sanitize_text_field( $this->post( 'nuevo_tipo' ) ) );
		if ( '' !== $nuevo ) {
			if ( count( $cfg['tipos'] ) >= Config::MAX_TIPOS ) {
				Notices::add( 'error', sprintf( asde_t( 'Como mucho %d tipos de actividad.' ), Config::MAX_TIPOS ) );
			} else {
				$cfg['tipos'][ Config::id_para( $nuevo, $cfg['tipos'] ) ] = array(
					'nombre'       => mb_substr( $nuevo, 0, 60 ),
					'coste'        => '1' === $this->post( 'nuevo_coste' ),
					'comunicacion' => 'no' === $this->post( 'nuevo_comunicacion' ) ? 'no' : 'auto',
					'crm'          => 'jornada',
					'activo'       => true,
				);
			}
		}
		if ( ! Config::tipos_activos( $cfg ) ) {
			Notices::add( 'error', asde_t( 'Deja al menos un tipo de actividad activo.' ) );
			$this->redirect( $this->url( 'eventos_config' ) );
		}

		// Titulaciones: casillas (si el CRM dio las opciones) o una por línea.
		foreach ( array( 'director', 'monitor' ) as $k ) {
			$marcadas = $this->post_array( 'titulacion_' . $k );
			if ( ! $marcadas && '' !== $this->post( 'titulacion_' . $k . '_texto' ) ) {
				$marcadas = preg_split( '/\R/', $this->post( 'titulacion_' . $k . '_texto' ) ) ?: array();
			}
			$cfg['titulaciones'][ $k ] = array_values( array_unique( array_filter( array_map( static fn( $v ) => mb_substr( trim( sanitize_text_field( (string) $v ) ), 0, 100 ), $marcadas ) ) ) );
		}

		Config::guardar( $cfg );
		Logger::log( 'eventos_configurados', 'grupo', Grupo::numero(), array( 'campos' => array( 'margen', 'tipos', 'titulaciones' ) ) );
		Notices::add( 'success', asde_t( 'Configuración de eventos guardada.' ) );
		$this->redirect( $this->url( 'eventos_config' ) );
	}
}
