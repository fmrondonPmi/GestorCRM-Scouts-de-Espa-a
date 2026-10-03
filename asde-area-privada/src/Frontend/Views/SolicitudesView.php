<?php
namespace AsdeBalears\AreaPrivada\Frontend\Views;

use AsdeBalears\AreaPrivada\Auth\Ambito;
use AsdeBalears\AreaPrivada\Crm\CrmException;
use AsdeBalears\AreaPrivada\Crm\Escritura;
use AsdeBalears\AreaPrivada\Frontend\Notices;
use AsdeBalears\AreaPrivada\Usuarios\Contacto;
use AsdeBalears\AreaPrivada\Usuarios\EmailCuenta;
use AsdeBalears\AreaPrivada\Usuarios\EmailRechazo;
use AsdeBalears\AreaPrivada\Usuarios\SolicitudService;
use AsdeBalears\AreaPrivada\Usuarios\Solicitudes;
use AsdeBalears\AreaPrivada\Usuarios\Usuarios;

defined( 'ABSPATH' ) || exit;

/**
 * Solicitudes de acceso (scouters, Secretaría y Coordinación): lista de las
 * pendientes de su ámbito y detalle con lo escrito junto a lo del CRM, para
 * aprobar (email al CRM, cuenta e invitación con bienvenida) o rechazar.
 */
final class SolicitudesView extends View {

	private const CAP = 'asde_aprobar_solicitudes';

	public function render( string $content = '' ): string {
		if ( ! current_user_can( self::CAP ) ) {
			return $this->denegado();
		}
		$servicio = SolicitudService::make();
		if ( ! $servicio ) {
			return $this->error_crm( 'solicitudes', asde_t( 'La conexión de tu grupo con el CRM no está configurada o no se ha podido comprobar. Un administrador puede revisarla en wp-admin → Grupos Scouts.' ) );
		}
		$ambito = Ambito::actual();
		try {
			$id = (int) $this->get( 'solicitud' );
			if ( $id ) {
				$s = Solicitudes::obtener( $id );
				if ( ! $s || ! Solicitudes::en_ambito( $s, $ambito ) ) {
					return $this->denegado( asde_t( 'No tienes acceso a esa solicitud.' ) );
				}
				return $this->layout( 'solicitudes', $this->detalle( $servicio, $s ) );
			}
			$resueltas = 'resueltas' === $this->get( 'ver' );
			$lista     = array_values(
				array_filter(
					Solicitudes::listado( $resueltas ? '' : Solicitudes::PENDIENTE ),
					static fn( array $s ) => Solicitudes::en_ambito( $s, $ambito ) && ( ! $resueltas || Solicitudes::PENDIENTE !== $s['estado'] )
				)
			);
			return $this->layout(
				'solicitudes',
				$this->tpl(
					'solicitudes/listado',
					array(
						'lista'     => $lista,
						'personas'  => $servicio->repo()->obtener_varias( array_column( $lista, 'socio' ) ),
						'resueltas' => $resueltas,
						'url'       => $this->url( 'solicitudes' ),
						'enlace'    => $this->url( 'solicitar' ),
						'contacto'  => EmailRechazo::contacto(),
					)
				)
			);
		} catch ( CrmException $e ) {
			return $this->error_crm( 'solicitudes', asde_t( 'No se ha podido consultar el CRM: ' ) . $e->getMessage() );
		}
	}

	private function detalle( SolicitudService $servicio, array $s ): string {
		$p          = $servicio->repo()->obtener( (string) $s['socio'] );
		$familiares = $p ? Usuarios::cuentas_familiares( array( $p->num_socio_asde ) ) : array();
		$propia     = $p ? Usuarios::por_socio( $p->num_socio_asde ) : null;
		$pendiente  = Solicitudes::PENDIENTE === $s['estado'];
		$email_cuenta = static function ( ?\WP_User $u ): string {
			$e = $u ? EmailCuenta::leer( $u->ID ) : null;
			return $e ? Contacto::enmascarar( $e ) : '';
		};
		$emails_familiares = array();
		foreach ( $familiares as $f ) {
			$emails_familiares[ $f->user_login ] = $email_cuenta( $f );
		}
		return $this->tpl(
			'solicitudes/detalle',
			array(
				's'            => $s,
				'p'            => $p,
				'c'            => $p ? $servicio->comparacion( $s, $p ) : null,
				'hermanos'     => $p && $pendiente && Solicitudes::TUTOR === $s['tipo'] ? $servicio->hermanos( $p ) : array(),
				'familiares'   => $familiares,
				'emails_familiares' => $emails_familiares,
				'propuesta'    => $p && $pendiente && $familiares && Solicitudes::TUTOR === $s['tipo'] ? $servicio->propuesta( $s, $p, $familiares ) : null,
				'contacto'     => EmailRechazo::contacto(),
				'propia'       => $propia,
				'escritura'    => Escritura::disponible(),
				'revisor'      => $s['revisada_por'] ? get_userdata( $s['revisada_por'] ) : null,
				'url'          => $this->url( 'solicitudes' ),
				'url_alta'     => $this->url( 'alta', array( 'socio' => (string) $s['socio'], 'solicitud' => (string) $s['id'] ) ),
				'url_ficha'    => $this->url( 'ficha', array( 'socio' => (string) $s['socio'] ) ),
			)
		);
	}

	public function handle_post(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'No tienes permiso para hacer esto.', 'asde-area-privada' ), 403 );
		}
		$id     = (int) $this->post( 'solicitud' );
		$accion = $this->post( 'asde_action' );
		$volver = $this->url( 'solicitudes', array( 'solicitud' => (string) $id ) );
		check_admin_referer( 'asde_solicitud_' . $id );

		$s = Solicitudes::obtener( $id );
		if ( ! $s || ! Solicitudes::en_ambito( $s, Ambito::actual() ) ) {
			Notices::add( 'error', asde_t( 'No tienes acceso a esa solicitud.' ) );
			$this->redirect( $this->url( 'solicitudes' ) );
		}
		$servicio = SolicitudService::make();
		if ( ! $servicio ) {
			Notices::add( 'error', asde_t( 'La conexión con el CRM no está disponible.' ) );
			$this->redirect( $volver );
		}

		try {
			if ( 'rechazar_solicitud' === $accion ) {
				$avisar = '1' === $this->post( 'avisar' );
				if ( $servicio->rechazar( $s, get_current_user_id(), $this->post( 'motivo' ), $avisar ) ) {
					$aviso = $servicio->aviso;
					if ( EmailRechazo::ENVIADO === $aviso['estado'] ) {
						Notices::add( 'success', sprintf( asde_t( 'Solicitud rechazada. Se ha avisado por email a %s.' ), esc_html( $aviso['destino'] ) ) );
					} else {
						Notices::add( 'success', asde_t( 'Solicitud rechazada.' ) . ( $avisar ? '' : asde_t( ' No se ha enviado ningún aviso.' ) ) );
						if ( $avisar ) {
							Notices::add( 'warning', esc_html( asde_t( 'No se ha podido avisar por email a quien la pidió: ' ) . $aviso['error'] ) );
						}
					}
				} else {
					Notices::add( 'error', asde_t( 'Esta solicitud ya estaba resuelta.' ) );
				}
				$this->redirect( $this->url( 'solicitudes' ) );
			}
			if ( 'aprobar_solicitud' !== $accion ) {
				Notices::add( 'error', asde_t( 'Petición no válida.' ) );
				$this->redirect( $volver );
			}
			$r = $servicio->aprobar(
				$s,
				get_current_user_id(),
				array(
					'campo'     => sanitize_key( $this->post( 'campo' ) ),
					'hermanos'  => $this->post_array( 'hermanos' ),
					'modo'      => in_array( $this->post( 'modo' ), array( SolicitudService::MODO_NUEVA, SolicitudService::MODO_SUSTITUIR ), true ) ? $this->post( 'modo' ) : ( '1' === $this->post( 'sustituir' ) ? SolicitudService::MODO_SUSTITUIR : '' ),
					'cuenta'    => sanitize_text_field( $this->post( 'cuenta' ) ),
				)
			);
		} catch ( CrmException $e ) {
			Notices::add( 'error', esc_html( asde_t( 'No se ha podido consultar el CRM: ' ) . $e->getMessage() ) );
			$this->redirect( $volver );
		}

		foreach ( $r['errores'] as $e ) {
			Notices::add( 'error', esc_html( $e ) );
		}
		foreach ( $r['avisos'] as $a ) {
			Notices::add( 'warning', esc_html( $a ) );
		}
		if ( $r['ok'] ) {
			Notices::add(
				'success',
				sprintf(
					asde_t( 'Solicitud aprobada: cuenta <strong>%s</strong>. Se ha enviado la invitación, con la bienvenida, a %s.%s' ),
					esc_html( $r['usuario']->user_login ),
					esc_html( Contacto::enmascarar( (string) $s['datos']['email'] ) ),
					$r['email_crm'] ? asde_t( ' El email nuevo ya está en la ficha del CRM.' ) : ''
				)
			);
			$this->redirect( $this->url( 'solicitudes' ) );
		}
		$this->redirect( $volver );
	}
}
