<?php
namespace AsdeBalears\AreaPrivada\Frontend\Views;

use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Frontend\Notices;
use AsdeBalears\AreaPrivada\Tesoreria\Config;

defined( 'ABSPATH' ) || exit;

/**
 * Configuración de las cuotas que paga cada familia (por ronda): inscripción y
 * seguro por hijo, anualidad por nº de hijos con tope, secciones que pagan y
 * plazos. La ven todos los scouters; la cambian Secretaría, Coordinación y Tesorería.
 */
final class CuotasView extends TesoreriaBaseView {

	public function render( string $content = '' ): string {
		if ( ! current_user_can( self::CAP ) ) {
			return $this->denegado();
		}
		$ronda = $this->ronda();
		return $this->layout(
			'tesoreria',
			$this->subnav( 'cuotas', $ronda ) . $this->tpl(
				'tesoreria/cuotas',
				array(
					'ronda'    => $ronda,
					'cfg'      => Config::de( $ronda ),
					'editable' => current_user_can( self::CAP_CONFIG ),
					'url'      => $this->url( 'cuotas' ),
				)
			)
		);
	}

	public function handle_post(): void {
		if ( ! current_user_can( self::CAP_CONFIG ) ) {
			wp_die( esc_html__( 'No tienes permiso para cambiar la configuración de Tesorería.', 'asde-area-privada' ), 403 );
		}
		check_admin_referer( 'asde_cuotas' );
		$ronda = $this->ronda();
		$datos = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification

		Config::guardar(
			$ronda,
			array(
				'cuotas'          => (array) ( $datos['cuotas'] ?? array() ),
				'secciones_pagan' => (array) ( $datos['secciones_pagan'] ?? array() ),
				'plazos'          => (array) ( $datos['plazos'] ?? array() ),
			)
		);
		Logger::log( 'tesoreria_cuotas_guardadas', 'ronda', $ronda );

		$suma = Config::suma_plazos( Config::de( $ronda ) );
		Notices::add( 'success', sprintf( 'Cuotas de la ronda %s guardadas.', esc_html( $ronda ) ) );
		if ( abs( $suma - 100 ) > 0.01 ) {
			Notices::add( 'warning', sprintf( 'Los plazos suman un %s %%: deberían sumar 100 %%.', esc_html( number_format_i18n( $suma, 2 ) ) ) );
		}
		$this->redirect( $this->url( 'cuotas', array( 'ronda' => $ronda ) ) );
	}
}
