<?php
namespace AsdeBalears\AreaPrivada\Frontend\Views;

use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Crm\CrmException;
use AsdeBalears\AreaPrivada\Crm\PersonaRepository;
use AsdeBalears\AreaPrivada\Domain\Modulos;
use AsdeBalears\AreaPrivada\Frontend\Notices;
use AsdeBalears\AreaPrivada\Presupuestos\Reparto;
use AsdeBalears\AreaPrivada\Tesoreria\Config;

defined( 'ABSPATH' ) || exit;

/**
 * Presupuestos (v0.54, doc 57, fase 1): de la cuota anual al dinero de cada sección.
 * Cuota − coste federativo = neto; un porcentaje configurable va a las arcas del grupo y el
 * resto a la sección de cada asociado. Resumen de la ronda con el reparto en euros y los
 * porcentajes (por defecto y por sección) que cambian Secretaría, Coordinación y Tesorería.
 */
final class PresupuestosView extends TesoreriaBaseView {

	public function render( string $content = '' ): string {
		if ( ! current_user_can( self::CAP_CONFIG ) || ! Modulos::activo( Modulos::PRESUPUESTOS ) ) {
			return $this->denegado();
		}
		$ronda = $this->ronda();
		$cfg   = Config::de( $ronda );
		$repo  = PersonaRepository::make();

		$reparto = null;
		$error   = '';
		if ( $repo ) {
			try {
				list( $filas, $personas ) = TesoreriaView::estados( $repo, $ronda );
				$reparto                  = Reparto::calcular( $repo->todas( true ), $filas, $personas, $cfg );
			} catch ( CrmException $e ) {
				$error = asde_t( 'No se ha podido consultar el CRM: ' ) . $e->getMessage();
			}
		}

		return $this->layout(
			'tesoreria',
			$this->subnav( 'presupuestos', $ronda ) . $this->tpl(
				'tesoreria/presupuestos',
				array(
					'ronda'   => $ronda,
					'cfg'     => $cfg,
					'reparto' => $reparto,
					'error'   => $error,
					'url'     => $this->url( 'presupuestos' ),
				)
			)
		);
	}

	public function handle_post(): void {
		if ( ! current_user_can( self::CAP_CONFIG ) || ! Modulos::activo( Modulos::PRESUPUESTOS ) ) {
			wp_die( esc_html__( 'No tienes permiso para cambiar los presupuestos.', 'asde-area-privada' ), 403 );
		}
		check_admin_referer( 'asde_presupuestos' );
		$ronda = $this->ronda();
		$antes = Config::de( $ronda )['presupuesto'];
		Config::guardar( $ronda, array( 'presupuesto' => (array) ( wp_unslash( $_POST )['presupuesto'] ?? array() ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$despues = Config::de( $ronda )['presupuesto'];
		if ( $antes !== $despues ) {
			Logger::log( 'presupuestos_ajustes_guardados', 'ronda', $ronda . ' · % grupo ' . $antes['pct_grupo'] . ' → ' . $despues['pct_grupo'] );
		}
		Notices::add( 'success', sprintf( asde_t( 'Reparto de la ronda %s guardado.' ), esc_html( $ronda ) ) );
		$this->redirect( $this->url( 'presupuestos', array( 'ronda' => $ronda ) ) );
	}
}
