<?php
namespace AsdeBalears\AreaPrivada\Frontend\Views;

use AsdeBalears\AreaPrivada\Auth\Ambito;
use AsdeBalears\AreaPrivada\Crm\CrmException;
use AsdeBalears\AreaPrivada\Crm\Escritura;
use AsdeBalears\AreaPrivada\Crm\PersonaRepository;
use AsdeBalears\AreaPrivada\Fichas\Cambios;
use AsdeBalears\AreaPrivada\Fichas\FichaService;
use AsdeBalears\AreaPrivada\Fichas\PermisosFicha;
use AsdeBalears\AreaPrivada\Frontend\Notices;
use AsdeBalears\AreaPrivada\Socios\Numeracion;

defined( 'ABSPATH' ) || exit;

/**
 * Ficha de una persona con todos los datos que el usuario puede ver. Lo que
 * puede cambiar aparece editable (directo al CRM, o como propuesta si es una
 * cuenta familiar); el resto, de solo lectura.
 *
 * ?socio=NNN (nº Scouts de España). Sin él: la ficha propia o la del primer
 * menor de la cuenta familiar.
 */
final class FichaView extends View {

	public function render( string $content = '' ): string {
		$ambito = Ambito::actual();
		$repo   = PersonaRepository::make();
		if ( ! $repo ) {
			return $this->error_crm( 'ficha', 'La conexión de tu grupo con el CRM no está configurada o no se ha podido comprobar. Un administrador puede revisarla en wp-admin → Grupos Scouts.' );
		}
		$socio = $this->socio( $ambito );
		try {
			$p = '' !== $socio ? $repo->obtener( $socio ) : null;
			if ( ! $p ) {
				return $this->denegado( 'No se encuentra esa ficha en el CRM del grupo.' );
			}
			$perm = new PermisosFicha( $ambito, $p );
			if ( ! $perm->puede_ver() ) {
				return $this->denegado( 'No tienes acceso a esta ficha.' );
			}
			$campos = ( new FichaService( $repo ) )->cargar( $p, $perm );
			$numero = ( new Numeracion( $repo ) )->de( $p );
		} catch ( CrmException $e ) {
			return $this->error_crm( 'ficha', 'No se ha podido consultar el CRM: ' . $e->getMessage() );
		}

		return $this->layout(
			$perm->es_propia() ? 'ficha' : ( current_user_can( 'asde_manage_users' ) ? 'personas' : 'ficha' ),
			$this->tpl(
				'fichas/ficha',
				array(
					'p'             => $p,
					'perm'          => $perm,
					'campos'        => $campos,
					'numero'        => $numero,
					'cambios'       => Cambios::listado( '', $p->num_socio_asde ),
					'escritura'     => Escritura::disponible(),
					'representados' => $ambito->representados,
					'ambito'        => $ambito,
					'url'           => $this->url( 'ficha' ),
					'url_personas'  => $this->url( 'personas' ),
					'url_cambios'   => $this->url( 'cambios' ),
					'url_alta'      => $this->url( 'alta' ),
				)
			)
		);
	}

	public function handle_post(): void {
		$ambito = Ambito::actual();
		$socio  = $this->post( 'socio' );
		$volver = $this->url( 'ficha', array( 'socio' => $socio ) );

		if ( 'cancelar_cambio' === $this->post( 'asde_action' ) ) {
			$id = (int) $this->post( 'cambio' );
			check_admin_referer( 'asde_cancelar_cambio_' . $id );
			$r = Cambios::cancelar( $id );
			is_wp_error( $r ) ? Notices::add( 'error', esc_html( $r->get_error_message() ) ) : Notices::add( 'success', 'Propuesta retirada.' );
			$this->redirect( $volver );
		}
		if ( 'guardar_ficha' !== $this->post( 'asde_action' ) || ! PersonaRepository::es_num_asde( $socio ) ) {
			Notices::add( 'error', 'Petición no válida.' );
			$this->redirect( $this->url( 'ficha' ) );
		}
		check_admin_referer( 'asde_ficha_' . $socio );

		$repo = PersonaRepository::make();
		try {
			$p = $repo ? $repo->obtener( $socio ) : null;
			if ( ! $p ) {
				Notices::add( 'error', 'No se encuentra esa ficha en el CRM del grupo.' );
				$this->redirect( $this->url( 'ficha' ) );
			}
			$perm = new PermisosFicha( $ambito, $p );
			if ( ! $perm->puede_ver() ) {
				wp_die( esc_html__( 'No tienes permiso para hacer esto.', 'asde-area-privada' ), 403 );
			}
			$enviado = array();
			foreach ( $this->post_array( 'campo' ) as $campo => $valor ) {
				$enviado[ sanitize_key( (string) $campo ) ] = (string) $valor;
			}
			$huellas = array();
			foreach ( $this->post_array( 'huella' ) as $campo => $h ) {
				$huellas[ sanitize_key( (string) $campo ) ] = (string) $h;
			}
			$r = ( new FichaService( $repo ) )->guardar( $p, $perm, $enviado, $huellas );
		} catch ( CrmException $e ) {
			Notices::add( 'error', esc_html( 'No se ha podido consultar el CRM: ' . $e->getMessage() ) );
			$this->redirect( $volver );
		}

		$lista = static fn( array $a ) => esc_html( implode( ', ', $a ) );
		if ( $r['guardados'] ) {
			Notices::add( 'success', 'Guardado en el CRM: ' . $lista( $r['guardados'] ) . '.' );
		}
		if ( $r['propuestos'] ) {
			Notices::add( 'success', 'Cambios enviados para su aprobación: ' . $lista( $r['propuestos'] ) . '. Te avisaremos en esta ficha cuando se revisen.' );
		}
		if ( $r['rechazados'] ) {
			Notices::add( 'error', 'El CRM no ha aceptado: ' . $lista( $r['rechazados'] ) . '. Puede que el usuario del CRM no tenga permiso sobre esos campos.' );
		}
		if ( $r['conflictos'] ) {
			Notices::add( 'warning', 'No se ha cambiado ' . $lista( $r['conflictos'] ) . ' porque alguien lo modificó en el CRM mientras editabas. Revisa el valor actual y vuelve a intentarlo.' );
		}
		foreach ( $r['errores'] as $e ) {
			Notices::add( 'error', esc_html( $e ) );
		}
		if ( ! array_filter( $r ) ) {
			Notices::add( 'info', 'No había ningún cambio que guardar.' );
		}
		$this->redirect( $volver );
	}

	/** Ficha pedida, o por defecto la propia o la del primer menor a cargo. */
	private function socio( Ambito $a ): string {
		$pedido = $this->get( 'socio' );
		if ( PersonaRepository::es_num_asde( $pedido ) ) {
			return $pedido;
		}
		if ( $a->persona ) {
			return $a->persona->num_socio_asde;
		}
		return (string) ( array_key_first( $a->representados ) ?? '' );
	}
}
