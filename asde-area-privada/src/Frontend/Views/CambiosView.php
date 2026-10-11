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
	public const DIAS_DIRECTOS = 90;

	public function render( string $content = '' ): string {
		if ( ! current_user_can( self::CAP ) ) {
			return $this->denegado();
		}
		$repo = PersonaRepository::make();
		if ( ! $repo ) {
			return $this->error_crm( 'cambios', asde_t( 'La conexión de tu grupo con el CRM no está configurada o no se ha podido comprobar. Un administrador puede revisarla en wp-admin → Grupos Scouts.' ) );
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
			return $this->error_crm( 'cambios', asde_t( 'No se ha podido consultar el CRM: ' ) . $e->getMessage() );
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
			wp_die( esc_html__( 'No tienes permiso para hacer esto.', 'asde-area-privada' ), 403 );
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
			Notices::add( 'error', asde_t( 'No puedes revisar los cambios de esa persona.' ) );
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
			Notices::add( 'error', asde_t( 'Marca los cambios que quieres aprobar o rechazar.' ) );
			$this->redirect( $volver );
		}

		if ( 'rechazar_varios' === $this->post( 'asde_action' ) ) {
			$motivo = trim( sanitize_text_field( $this->post( 'motivo' ) ) );
			if ( '' === $motivo ) {
				Notices::add( 'error', asde_t( 'Indica el motivo del rechazo: se le mostrará a quien lo propuso.' ) );
				$this->redirect( $volver );
			}
			$n = 0;
			foreach ( $ids as $id ) {
				$n += true === Cambios::rechazar( $id, $motivo ) ? 1 : 0;
			}
			Notices::add( 'success', esc_html( sprintf( asde_n( '%d cambio rechazado. Quien lo propuso verá el motivo en la ficha.', '%d cambios rechazados. Quien los propuso verá el motivo en la ficha.', $n ), $n ) ) );
			$this->redirect( $volver );
		}

		try {
			$campos   = array_map( static fn( int $id ) => (string) Cambios::obtener( $id )['campo'], $ids );
			$actuales = $repo->valores( $p, $campos );
		} catch ( CrmException $e ) {
			Notices::add( 'error', esc_html( asde_t( 'No se ha podido consultar el CRM: ' ) . $e->getMessage() ) );
			$this->redirect( $volver );
		}
		// Un cambio cuyo dato del CRM había cambiado ya se avisó al revisarlo ($visto): marcarlo es aplicarlo igualmente.
		$r = Cambios::aprobar_varios( $ids, $p, $actuales, array_map( 'intval', $this->post_array( 'visto' ) ) );
		if ( $r['aprobados'] ) {
			$n = count( $r['aprobados'] );
			Notices::add( 'success', esc_html( sprintf( asde_n( '%d cambio aprobado y guardado en el CRM.', '%d cambios aprobados y guardados en el CRM.', $n ), $n ) ) );
		}
		if ( $r['conflictos'] ) {
			$n = count( $r['conflictos'] );
			Notices::add( 'warning', esc_html( sprintf( asde_n( '%d cambio no se ha aplicado: el dato del CRM ha cambiado desde que se propuso. Revísalo y márcalo otra vez si quieres aplicarlo igualmente.', '%d cambios no se han aplicado: el dato del CRM ha cambiado desde que se propusieron. Revísalos y márcalos otra vez si quieres aplicarlos igualmente.', $n ), $n ) ) );
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
