<?php
namespace AsdeBalears\AreaPrivada\Frontend\Views;

use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Crm\CrmException;
use AsdeBalears\AreaPrivada\Crm\PersonaRepository;
use AsdeBalears\AreaPrivada\Frontend\Notices;
use AsdeBalears\AreaPrivada\Tesoreria\Calculadora;
use AsdeBalears\AreaPrivada\Tesoreria\Config;

defined( 'ABSPATH' ) || exit;

/**
 * Coste que paga el grupo a su organización federada: configuración de la ronda
 * (cuota asociativa, seguro medio, primas por categoría y otros conceptos; empieza
 * con los de la organización) y cálculo con los socios activos del CRM,
 * comparado con lo que se espera ingresar de las familias.
 */
final class FederacionView extends TesoreriaBaseView {

	public function render( string $content = '' ): string {
		if ( ! current_user_can( self::CAP ) ) {
			return $this->denegado();
		}
		$repo  = PersonaRepository::make();
		$ronda = $this->ronda();
		$cfg   = Config::de( $ronda );

		$coste    = null;
		$ingresos = null;
		$error    = '';
		if ( $repo ) {
			try {
				$coste = Calculadora::coste_federativo( $repo->todas( true ), $cfg );
				list( $filas ) = TesoreriaView::estados( $repo, $ronda );
				$ingresos      = array_sum( array_map( static fn( $f ) => $f['calc']['a_pagar'], $filas ) );
			} catch ( CrmException $e ) {
				$error = 'No se ha podido consultar el CRM: ' . $e->getMessage();
			}
		}

		return $this->layout(
			'tesoreria',
			$this->subnav( 'federacion', $ronda ) . $this->tpl(
				'tesoreria/federacion',
				array(
					'ronda'    => $ronda,
					'cfg'      => $cfg,
					'coste'    => $coste,
					'ingresos' => $ingresos,
					'error'    => $error,
					'editable' => current_user_can( self::CAP_CONFIG ),
					'url'      => $this->url( 'federacion' ),
				)
			)
		);
	}

	public function handle_post(): void {
		if ( ! current_user_can( self::CAP_CONFIG ) ) {
			wp_die( esc_html__( 'No tienes permiso para cambiar la configuración de Tesorería.', 'asde-area-privada' ), 403 );
		}
		check_admin_referer( 'asde_federacion' );
		$ronda = $this->ronda();
		Config::guardar( $ronda, array( 'federacion' => (array) ( wp_unslash( $_POST )['federacion'] ?? array() ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
		Logger::log( 'tesoreria_federacion_guardada', 'ronda', $ronda );
		Notices::add( 'success', sprintf( 'Coste federativo de la ronda %s guardado.', esc_html( $ronda ) ) );
		$this->redirect( $this->url( 'federacion', array( 'ronda' => $ronda ) ) );
	}
}
