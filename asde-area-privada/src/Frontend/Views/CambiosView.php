<?php
namespace AsdeBalears\AreaPrivada\Frontend\Views;

use AsdeBalears\AreaPrivada\Auth\Ambito;
use AsdeBalears\AreaPrivada\Crm\CrmException;
use AsdeBalears\AreaPrivada\Crm\Escritura;
use AsdeBalears\AreaPrivada\Crm\Metadatos;
use AsdeBalears\AreaPrivada\Crm\PersonaRepository;
use AsdeBalears\AreaPrivada\Fichas\Cambios;
use AsdeBalears\AreaPrivada\Fichas\CamposFicha;
use AsdeBalears\AreaPrivada\Fichas\PermisosFicha;
use AsdeBalears\AreaPrivada\Frontend\Notices;

defined( 'ABSPATH' ) || exit;

/**
 * Bandeja de cambios propuestos por las cuentas familiares. La revisan
 * Secretaría y Coordinación (todo el grupo) y los scouters (sus secciones).
 * Nadie aprueba sus propias propuestas.
 */
final class CambiosView extends View {

	private const CAP = 'asde_approve_changes';

	public function render( string $content = '' ): string {
		if ( ! current_user_can( self::CAP ) ) {
			return $this->denegado();
		}
		$repo = PersonaRepository::make();
		if ( ! $repo ) {
			return $this->error_crm( 'cambios', asde_t( 'La conexión de tu grupo con el CRM no está configurada o no se ha podido comprobar. Un administrador puede revisarla en wp-admin → Grupos Scouts.' ) );
		}
		$ver     = 'resueltos' === $this->get( 'ver' ) ? 'resueltos' : 'pendientes';
		$cambios = 'pendientes' === $ver ? Cambios::listado( Cambios::PENDIENTE ) : array_filter( Cambios::listado( '' ), static fn( $c ) => Cambios::PENDIENTE !== $c['estado'] );
		$ambito  = Ambito::actual();
		$filas   = array();
		try {
			$personas = $repo->obtener_varias( array_unique( array_column( $cambios, 'socio' ) ) );
			$actuales = array();
			foreach ( $cambios as $c ) {
				$p = $personas[ $c['socio'] ] ?? null;
				if ( ! $p || ! $this->puede_revisar( $ambito, $p ) ) {
					continue;
				}
				if ( 'pendientes' === $ver && ! isset( $actuales[ $c['socio'] ] ) ) {
					$actuales[ $c['socio'] ] = $repo->valores( $p, array_unique( array_column( array_filter( $cambios, static fn( $x ) => $x['socio'] === $c['socio'] ), 'campo' ) ) );
				}
				$c['persona'] = $p;
				$c['actual']  = (string) ( $actuales[ $c['socio'] ][ $c['campo'] ] ?? '' );
				$filas[]      = $c;
			}
		} catch ( CrmException $e ) {
			return $this->error_crm( 'cambios', asde_t( 'No se ha podido consultar el CRM: ' ) . $e->getMessage() );
		}

		return $this->layout(
			'cambios',
			$this->tpl(
				'fichas/cambios',
				array(
					'filas'     => $filas,
					'ver'       => $ver,
					'meta'      => Metadatos::campos( Escritura::MODULO, array_keys( CamposFicha::todos() ) ),
					'escritura' => Escritura::disponible(),
					'url'       => $this->url( 'cambios' ),
					'url_ficha' => $this->url( 'ficha' ),
				)
			)
		);
	}

	public function handle_post(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'No tienes permiso para hacer esto.', 'asde-area-privada' ), 403 );
		}
		$id     = (int) $this->post( 'cambio' );
		$volver = $this->url( 'cambios' );
		check_admin_referer( 'asde_revisar_cambio_' . $id );
		$c    = Cambios::obtener( $id );
		$repo = PersonaRepository::make();
		try {
			$p = $c && $repo ? $repo->obtener( $c['socio'] ) : null;
		} catch ( CrmException $e ) {
			$p = null;
		}
		if ( ! $c || ! $p || ! $this->puede_revisar( Ambito::actual(), $p ) || (int) $c['propuesto_por'] === get_current_user_id() ) {
			Notices::add( 'error', asde_t( 'No puedes revisar ese cambio.' ) );
			$this->redirect( $volver );
		}

		if ( 'rechazar' === $this->post( 'asde_action' ) ) {
			$r = Cambios::rechazar( $id, $this->post( 'motivo' ) );
			is_wp_error( $r ) ? Notices::add( 'error', esc_html( $r->get_error_message() ) ) : Notices::add( 'success', asde_t( 'Cambio rechazado. Quien lo propuso verá el motivo en la ficha.' ) );
			$this->redirect( $volver );
		}
		try {
			$actual = (string) ( $repo->valores( $p, array( $c['campo'] ) )[ $c['campo'] ] ?? '' );
		} catch ( CrmException $e ) {
			Notices::add( 'error', esc_html( asde_t( 'No se ha podido consultar el CRM: ' ) . $e->getMessage() ) );
			$this->redirect( $volver );
		}
		$r = Cambios::aprobar( $id, $p, $actual, '1' === $this->post( 'forzar' ) );
		is_wp_error( $r )
			? Notices::add( 'asde_cambio_conflicto' === $r->get_error_code() ? 'warning' : 'error', esc_html( $r->get_error_message() ) )
			: Notices::add( 'success', asde_t( 'Cambio aprobado y guardado en el CRM.' ) );
		$this->redirect( $volver );
	}

	/** Secretaría y Coordinación: todo el grupo; scouters: los socios de sus secciones. */
	private function puede_revisar( Ambito $a, $p ): bool {
		$perm = new PermisosFicha( $a, $p );
		return $a->vision_global() || $perm->de_su_seccion();
	}
}
