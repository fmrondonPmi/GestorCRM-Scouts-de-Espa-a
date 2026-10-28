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
 *
 * v0.29: agrupada por persona. «Revisar» abre en la ventana emergente todos los
 * cambios pendientes de esa persona, con una casilla en cada uno y un solo botón
 * para aprobar (o rechazar) los marcados, que se guardan en el CRM de una vez.
 * «Resueltos» enseña también los cambios hechos directamente en las fichas (de la
 * auditoría: qué datos, quién y cuándo; los valores no se guardan).
 */
final class CambiosView extends View {

	private const CAP = 'asde_approve_changes';

	/** Días de cambios hechos directamente en las fichas que se ven en «Resueltos». */
	public const DIAS_DIRECTOS = 30; // Decisión del usuario (P-98).

	public function render( string $content = '' ): string {
		if ( ! current_user_can( self::CAP ) ) {
			return $this->denegado();
		}
		$repo = PersonaRepository::make();
		if ( ! $repo ) {
			return $this->error_crm( 'cambios', __( 'Your group\'s connection to the CRM is not set up or could not be checked. An administrator can review it in wp-admin → Scout Groups.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
		}
		$ver   = 'resueltos' === $this->get( 'ver' ) ? 'resueltos' : 'pendientes';
		$socio = preg_match( '/^\d{1,20}$/', $this->get( 'socio' ) ) ? $this->get( 'socio' ) : '';

		$propuestas = 'pendientes' === $ver
			? Cambios::listado( Cambios::PENDIENTE, $socio )
			: array_filter( Cambios::listado( '', $socio ), static fn( $c ) => Cambios::PENDIENTE !== $c['estado'] );
		$directos   = 'resueltos' === $ver ? Cambios::directos( self::DIAS_DIRECTOS, $socio ) : array();
		$ambito     = Ambito::actual();
		$grupos     = array();
		try {
			$personas = $repo->obtener_varias( array_unique( array_merge( array_column( $propuestas, 'socio' ), array_column( $directos, 'socio' ) ) ) );
			foreach ( array( 'cambios' => $propuestas, 'directos' => $directos ) as $tipo => $filas ) {
				foreach ( $filas as $c ) {
					$p = $personas[ $c['socio'] ] ?? null;
					if ( ! $p || ! $this->puede_revisar( $ambito, $p ) ) {
						continue;
					}
					$grupos[ $c['socio'] ] ??= array(
						'persona'  => $p,
						'cambios'  => array(),
						'directos' => array(),
						'ultimo'   => '',
					);
					$grupos[ $c['socio'] ][ $tipo ][] = $c;
					// Para ordenar: lo último que pasó (la revisión en «Resueltos»; si no, cuándo se propuso o se hizo).
					if ( 'directos' === $tipo ) {
						$fecha = (string) $c['creado'];
					} else {
						$fecha = 'resueltos' === $ver && ! empty( $c['revisado_en'] ) ? (string) $c['revisado_en'] : (string) $c['propuesto_en'];
					}
					$grupos[ $c['socio'] ]['ultimo'] = max( $grupos[ $c['socio'] ]['ultimo'], $fecha );
				}
			}
			// El valor de ahora en el CRM (para avisar si cambió desde la propuesta), solo en la ventana de una persona.
			if ( 'pendientes' === $ver && '' !== $socio && isset( $grupos[ $socio ] ) ) {
				$actuales = $repo->valores( $grupos[ $socio ]['persona'], array_column( $grupos[ $socio ]['cambios'], 'campo' ) );
				foreach ( $grupos[ $socio ]['cambios'] as $i => $c ) {
					$grupos[ $socio ]['cambios'][ $i ]['actual'] = (string) ( $actuales[ $c['campo'] ] ?? '' );
				}
			}
		} catch ( CrmException $e ) {
			return $this->error_crm( 'cambios', __( 'The CRM could not be queried: ', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) . $e->getMessage() );
		}
		uasort( $grupos, static fn( $a, $b ) => strcmp( $b['ultimo'], $a['ultimo'] ) );

		return $this->layout(
			'cambios',
			$this->tpl(
				'fichas/cambios',
				array(
					'grupos'    => $grupos,
					'ver'       => $ver,
					'socio'     => $socio,
					'dias'      => self::DIAS_DIRECTOS,
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
			wp_die( esc_html__( 'You do not have permission to do this.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), 403 );
		}
		$socio = preg_match( '/^\d{1,20}$/', $this->post( 'socio' ) ) ? $this->post( 'socio' ) : '';
		$lista = $this->url( 'cambios' );
		check_admin_referer( 'asde_revisar_socio_' . $socio );
		$volver = add_query_arg( 'socio', $socio, $lista );
		$repo   = PersonaRepository::make();
		try {
			$p = '' !== $socio && $repo ? $repo->obtener( $socio ) : null;
		} catch ( CrmException $e ) {
			$p = null;
		}
		if ( ! $p || ! $this->puede_revisar( Ambito::actual(), $p ) ) {
			Notices::add( 'error', __( 'You cannot review that person\'s changes.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
			$this->redirect( $lista );
		}

		// Solo los pendientes de esta persona que no propuso quien revisa.
		$yo  = get_current_user_id();
		$ids = array_values(
			array_filter(
				array_unique( array_map( 'intval', $this->post_array( 'cambios' ) ) ),
				static function ( int $id ) use ( $socio, $yo ): bool {
					$c = Cambios::obtener( $id );
					return $c && $c['socio'] === $socio && Cambios::PENDIENTE === $c['estado'] && (int) $c['propuesto_por'] !== $yo;
				}
			)
		);
		if ( ! $ids ) {
			Notices::add( 'error', __( 'Tick the changes you want to approve or reject.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
			$this->redirect( $volver );
		}

		if ( 'rechazar_varios' === $this->post( 'asde_action' ) ) {
			$motivo = trim( sanitize_text_field( $this->post( 'motivo' ) ) );
			if ( '' === $motivo ) {
				Notices::add( 'error', __( 'State the reason for the rejection: it will be shown to whoever proposed it.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
				$this->redirect( $volver );
			}
			$n = 0;
			foreach ( $ids as $id ) {
				$n += true === Cambios::rechazar( $id, $motivo ) ? 1 : 0;
			}
			Notices::add( 'success', esc_html( sprintf( /* translators: %d: number */ _n( '%d change rejected. Whoever proposed it will see the reason on the record.', '%d changes rejected. Whoever proposed them will see the reason on the record.', $n , 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $n ) ) );
			$this->redirect( $volver );
		}

		if ( ! Escritura::disponible() ) {
			Notices::add( 'error', __( 'The group\'s CRM user cannot edit records right now: changes cannot be approved until it can.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
			$this->redirect( $volver );
		}
		try {
			$campos   = array_map( static fn( int $id ) => (string) Cambios::obtener( $id )['campo'], $ids );
			$actuales = $repo->valores( $p, $campos );
		} catch ( CrmException $e ) {
			Notices::add( 'error', esc_html( __( 'The CRM could not be queried: ', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) . $e->getMessage() ) );
			$this->redirect( $volver );
		}
		// Un cambio cuyo dato del CRM había cambiado ya se avisó al revisarlo ($visto): marcarlo es aplicarlo igualmente.
		$r = Cambios::aprobar_varios( $ids, $p, $actuales, array_map( 'intval', $this->post_array( 'visto' ) ) );
		if ( $r['aprobados'] ) {
			$n = count( $r['aprobados'] );
			Notices::add( 'success', esc_html( sprintf( /* translators: %d: number */ _n( '%d change approved and saved in the CRM.', '%d changes approved and saved in the CRM.', $n , 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $n ) ) );
		}
		if ( $r['conflictos'] ) {
			$n = count( $r['conflictos'] );
			Notices::add( 'warning', esc_html( sprintf( /* translators: %d: number */ _n( '%d change was not applied: the CRM value has changed since it was proposed. Review it and tick it again if you want to apply it anyway.', '%d changes were not applied: the CRM value has changed since they were proposed. Review them and tick them again if you want to apply them anyway.', $n , 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $n ) ) );
		}
		foreach ( array_unique( $r['errores'] ) as $error ) {
			Notices::add( 'error', esc_html( $error ) );
		}
		$this->redirect( $volver );
	}

	/** Secretaría y Coordinación: todo el grupo; scouters: los socios de sus secciones. */
	private function puede_revisar( Ambito $a, $p ): bool {
		$perm = new PermisosFicha( $a, $p );
		return $a->vision_global() || $perm->de_su_seccion();
	}
}
