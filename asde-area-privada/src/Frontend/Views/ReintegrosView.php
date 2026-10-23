<?php
namespace AsdeBalears\AreaPrivada\Frontend\Views;

use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Eventos\Justificantes;
use AsdeBalears\AreaPrivada\Frontend\Notices;
use AsdeBalears\AreaPrivada\Reintegros\ReintegrosTable;
use AsdeBalears\AreaPrivada\Reintegros\Servicio;

defined( 'ABSPATH' ) || exit;

/**
 * Reintegros (v0.47, doc 53): un scouter o Coordinación pide que le devuelvan un gasto (con el justificante, un
 * evento si lo hay y cómo prefiere cobrar); Tesorería lo aprueba y Tesorería o Coordinación lo marcan pagado.
 */
final class ReintegrosView extends View {

	public function render( string $content = '' ): string {
		if ( ! current_user_can( Servicio::CAP_PEDIR ) && ! Servicio::ve_todos() ) {
			return $this->denegado();
		}
		$todos = Servicio::ve_todos() ? ReintegrosTable::todos() : array();
		$mios  = current_user_can( Servicio::CAP_PEDIR ) ? ReintegrosTable::de_usuario( get_current_user_id() ) : array();
		return $this->layout(
			'reintegros',
			$this->tpl(
				'reintegros/index',
				array(
					'puede_pedir' => current_user_can( Servicio::CAP_PEDIR ),
					'eventos'     => Servicio::eventos(),
					'mios'        => $mios,
					'todos'       => array_values( array_filter( $todos, static fn( array $r ) => in_array( $r['estado'], array( ReintegrosTable::SOLICITADO, ReintegrosTable::APROBADO ), true ) ) ),
					'historial'   => array_values( array_filter( $todos, static fn( array $r ) => ! in_array( $r['estado'], array( ReintegrosTable::SOLICITADO, ReintegrosTable::APROBADO ), true ) ) ),
					'url'         => $this->url( 'reintegros' ),
					'hoy'         => wp_date( 'Y-m-d' ),
				)
			)
		);
	}

	public function handle_post(): void {
		if ( ! current_user_can( Servicio::CAP_PEDIR ) && ! Servicio::ve_todos() ) {
			wp_die( esc_html__( 'No tienes permiso para hacer esto.', 'asde-area-privada' ), 403 );
		}
		$accion = $this->post( 'asde_action' );
		$volver = $this->url( 'reintegros' );

		if ( 'reintegro_pedir' === $accion ) {
			check_admin_referer( 'asde_reintegro_pedir' );
			$fichero = Justificantes::subido();
			$r       = is_wp_error( $fichero ) ? $fichero : Servicio::solicitar(
				array(
					'concepto' => $this->post( 'concepto' ),
					'fecha'    => $this->post( 'fecha' ),
					'importe'  => $this->post( 'importe' ),
					'forma'    => $this->post( 'forma' ),
					'iban'     => $this->post( 'iban' ),
					'evento'   => $this->post( 'evento' ),
				),
				$fichero
			);
			is_wp_error( $r ) ? Notices::add( 'error', esc_html( $r->get_error_message() ) ) : Notices::add( 'success', esc_html( asde_t( 'Reintegro pedido. Tesorería lo revisará y te avisaremos.' ) ) );
			$this->redirect( $volver );
		}

		$r = ReintegrosTable::obtener( (int) $this->post( 'reintegro' ) );
		check_admin_referer( 'asde_reintegro_' . ( $r['id'] ?? 0 ) );
		if ( ! $r || ! Servicio::puede_ver( $r ) ) {
			Notices::add( 'error', asde_t( 'No tienes acceso a ese reintegro.' ) );
			$this->redirect( $volver );
		}
		$res   = null;
		$hecho = '';
		switch ( $accion ) {
			case 'reintegro_justificante':
				$res = $this->ver_justificante( $r ); // Si se puede ver, lo envía y no vuelve.
				break;
			case 'reintegro_cancelar':
				$res   = Servicio::cancelar( $r );
				$hecho = asde_t( 'Reintegro cancelado.' );
				break;
			case 'reintegro_aprobar':
				$res   = Servicio::aprobar( $r );
				$hecho = asde_t( 'Reintegro aprobado. Queda pendiente de pagar.' );
				break;
			case 'reintegro_rechazar':
				$res   = Servicio::rechazar( $r, $this->post( 'motivo' ) );
				$hecho = asde_t( 'Reintegro rechazado.' );
				break;
			case 'reintegro_pagar':
				$res = Servicio::pagar( $r, $this->post( 'fecha' ), $this->post( 'referencia' ) );
				if ( is_array( $res ) ) {
					$hecho = asde_t( 'Reintegro marcado como pagado.' );
					if ( $res['gasto'] ) {
						$hecho .= ' ' . asde_t( 'Se ha añadido como gasto a las cuentas del evento.' );
					} elseif ( $res['evento'] ) {
						$hecho .= ' ' . asde_t( 'No se ha podido añadir a las cuentas del evento (están cerradas): apúntalo a mano si hace falta.' );
					}
				}
				break;
			default:
				$res = new \WP_Error( 'asde_reintegro', asde_t( 'Acción desconocida.' ) );
		}
		is_wp_error( $res ) ? Notices::add( 'error', esc_html( $res->get_error_message() ) ) : Notices::add( 'success', esc_html( $hecho ) );
		$this->redirect( $volver );
	}

	/**
	 * El justificante subido: solo su ruta temporal (comprobada con is_uploaded_file()) y su nombre; null si no se ha subido ninguno.
	 *
	 * @return array{ruta:string, nombre:string}|\WP_Error|null
	 */
	/** Envía el justificante (descifrado) al navegador (Justificantes::enviar()): solo quien pidió el reintegro, Tesorería y Coordinación. */
	private function ver_justificante( array $r ): \WP_Error {
		$contenido = '' !== $r['justificante'] ? Justificantes::leer( $r ) : null;
		if ( null === $contenido || ! isset( Justificantes::TIPOS[ $r['justificante_tipo'] ] ) ) {
			return new \WP_Error( 'asde_reintegro', asde_t( 'No se ha podido leer el justificante.' ) );
		}
		Logger::log( 'reintegro_justificante_visto', 'reintegro', (string) $r['id'] );
		Justificantes::enviar( $contenido, $r['justificante_tipo'], 'justificante-reintegro-' . $r['id'] );
		exit; // Justificantes::enviar() ya termina.
	}
}
