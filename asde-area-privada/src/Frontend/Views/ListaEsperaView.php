<?php
namespace AsdeBalears\AreaPrivada\Frontend\Views;

use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Crm\Altas;
use AsdeBalears\AreaPrivada\Domain\Grupo;
use AsdeBalears\AreaPrivada\Domain\Grupos;
use AsdeBalears\AreaPrivada\Domain\Secciones;
use AsdeBalears\AreaPrivada\Frontend\Notices;
use AsdeBalears\AreaPrivada\ListaEspera\Avisos;
use AsdeBalears\AreaPrivada\ListaEspera\CamposExtra;
use AsdeBalears\AreaPrivada\ListaEspera\Enlaces;
use AsdeBalears\AreaPrivada\ListaEspera\Inscripciones;

defined( 'ABSPATH' ) || exit;

/**
 * Lista de espera (v0.32, doc 39) para scouters, Secretaría, Coordinación y Tesorería:
 * el enlace del grupo, la lista con la sección que toca por edad y el orden de llegada, y la
 * ficha de cada inscripción (historial, enviar un email, apuntar un contacto, cambiar el
 * estado). Secretaría y Coordinación además asignan la sección, la dan de alta en el CRM
 * (Personas → Alta de socio/a, que comprueba antes el DNI) o la borran.
 */
final class ListaEsperaView extends View {

	public const CAP      = 'asde_lista_espera';
	public const CAP_ALTA = 'asde_lista_espera_alta';

	/** Filtro de estado del listado: las que siguen en la lista (sin las descartadas ni las sin confirmar). */
	public const ACTIVAS = 'activas';

	private const ACCIONES = array( 'espera_estado', 'espera_contacto', 'espera_email', 'espera_seccion', 'espera_quitar' );

	public function render( string $content = '' ): string {
		if ( ! current_user_can( self::CAP ) ) {
			return $this->denegado();
		}
		$id = absint( $this->get( 'inscripcion' ) );
		if ( $id ) {
			$fila = Inscripciones::obtener( $id );
			if ( ! $fila ) {
				return $this->denegado( __( 'That sign-up is no longer on the waiting list.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
			}
			return $this->layout( 'lista_espera', $this->detalle( $fila ) );
		}
		$todas   = Inscripciones::listado();
		$estado  = $this->get( 'estado' );
		$estado  = '' === $estado || ( self::ACTIVAS !== $estado && 'todas' !== $estado && ! isset( Inscripciones::ESTADOS[ $estado ] ) ) ? self::ACTIVAS : $estado;
		$seccion = sanitize_key( $this->get( 'seccion' ) );
		// Orden de llegada dentro de cada sección, solo entre las que siguen en la lista.
		$orden = array();
		$cont  = array();
		foreach ( $todas as $f ) {
			if ( self::sigue( $f ) ) {
				$s                  = '' !== $f['seccion_final'] ? $f['seccion_final'] : '-';
				$cont[ $s ]         = ( $cont[ $s ] ?? 0 ) + 1;
				$orden[ $f['id'] ] = $cont[ $s ];
			}
		}
		$lista = array_values(
			array_filter(
				$todas,
				static fn( array $f ): bool => ( 'todas' === $estado || ( self::ACTIVAS === $estado ? self::sigue( $f ) : $estado === $f['estado'] ) )
					&& ( '' === $seccion || ( 'sin' === $seccion ? '' === $f['seccion_final'] : $seccion === $f['seccion_final'] ) )
			)
		);
		$grupo = Grupo::actual();
		return $this->layout(
			'lista_espera',
			$this->tpl(
				'lista-espera/listado',
				array(
					'lista'   => $lista,
					'orden'   => $orden,
					'total'   => count( array_filter( $todas, array( self::class, 'sigue' ) ) ),
					'estado'  => $estado,
					'seccion' => $seccion,
					'url'     => $this->url( 'lista_espera' ),
					'enlace'  => $grupo ? Enlaces::url( $grupo ) : '',
				// Doc 59, RC-NUE-1: «Se incorpora como asociado/a» también en cada fila.
				'altas'    => current_user_can( self::CAP_ALTA ) && current_user_can( 'asde_manage_users' ) && Altas::disponible(),
				'url_alta' => $this->url( 'personas', array( 'alta' => '1' ) ),
					'renovar' => current_user_can( self::CAP_ALTA ),
					'campos'  => current_user_can( self::CAP_ALTA ) ? CamposExtra::del_grupo( Grupo::numero() ) : null,
					'declaracion' => CamposExtra::declaracion_propia( Grupo::numero() ),
				)
			)
		);
	}

	/** ¿Sigue en la lista? (confirmada y ni descartada). */
	public static function sigue( array $f ): bool {
		return in_array( $f['estado'], array( Inscripciones::EN_ESPERA, Inscripciones::CONTACTADA, Inscripciones::PLAZA_OFRECIDA ), true );
	}

	private function detalle( array $fila ): string {
		$autores = array();
		foreach ( $fila['historial'] as $h ) {
			$uid = (int) ( $h['user'] ?? 0 );
			if ( $uid && ! isset( $autores[ $uid ] ) ) {
				$u               = get_userdata( $uid );
				$autores[ $uid ] = $u ? $u->display_name : '';
			}
		}
		$grupo = Grupos::por_numero( (string) $fila['grupo'] );
		return $this->tpl(
			'lista-espera/detalle',
			array(
				'f'           => $fila,
				'autores'     => $autores,
				'gestiona'    => current_user_can( self::CAP_ALTA ),
				'altas'       => current_user_can( self::CAP_ALTA ) && current_user_can( 'asde_manage_users' ) && Altas::disponible(),
				'altas_motivo' => Altas::activo() ? __( 'Your group\'s CRM user does not have permission to create people and memberships.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) : __( 'Registrations in the CRM are not enabled for your group (wp-admin → Scout Groups → Modules).', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
				'asunto'      => sprintf( /* translators: %s: text */ __( 'Waiting list of %s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $grupo ? Grupos::etiqueta( $grupo ) : Grupo::nombre() ),
				'url'         => $this->url( 'lista_espera', array( 'inscripcion' => (string) $fila['id'] ) ),
				'volver'      => $this->url( 'lista_espera' ),
				'url_alta'    => $this->url( 'personas', array( 'alta' => '1' ) ),
			)
		);
	}

	public function handle_post(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), 403 );
		}
		$accion = $this->post( 'asde_action' );
		if ( 'renovar_enlace_espera' === $accion ) {
			check_admin_referer( 'asde_enlace_espera' );
			if ( current_user_can( self::CAP_ALTA ) && Grupo::actual() ) {
				Enlaces::renovar( Grupo::actual() );
				Logger::log( 'enlace_espera_renovado', 'grupo', Grupo::numero() );
				Notices::add( 'success', __( 'Waiting list link renewed: the previous one no longer works.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
			}
			$this->redirect( $this->url( 'lista_espera' ) );
		}
		if ( 'espera_campos' === $accion ) {
			check_admin_referer( 'asde_espera_campos' );
			if ( current_user_can( self::CAP_ALTA ) && Grupo::actual() ) {
				$filas = array();
				// phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput -- comprobado arriba; CamposExtra::guardar() sanea cada valor.
				$bruto = isset( $_POST['campo'] ) && is_array( $_POST['campo'] ) ? wp_unslash( $_POST['campo'] ) : array();
				foreach ( array_slice( $bruto, 0, CamposExtra::MAX_CAMPOS + 4 ) as $c ) {
					if ( is_array( $c ) ) {
						$filas[] = array(
							'id'          => (string) ( $c['id'] ?? '' ),
							'etiqueta'    => (string) ( $c['etiqueta'] ?? '' ),
							'tipo'        => (string) ( $c['tipo'] ?? '' ),
							'obligatorio' => '1' === (string) ( $c['obligatorio'] ?? '' ),
							'quitar'      => '1' === (string) ( $c['quitar'] ?? '' ),
						);
					}
				}
				$errores = CamposExtra::guardar( Grupo::numero(), $filas );
				if ( $errores ) {
					Notices::add( 'error', esc_html( implode( ' ', $errores ) ) );
				} else {
					CamposExtra::guardar_declaracion( Grupo::numero(), $this->post( 'declaracion' ) );
					Logger::log( 'lista_espera_campos', 'grupo', Grupo::numero() );
					Notices::add( 'success', __( 'Form fields saved.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
				}
			}
			$this->redirect( $this->url( 'lista_espera' ) );
		}
		if ( ! in_array( $accion, self::ACCIONES, true ) ) {
			return;
		}
		$id = absint( $this->post( 'inscripcion' ) );
		check_admin_referer( 'asde_espera_' . $id );
		$fila   = Inscripciones::obtener( $id );
		$volver = $this->url( 'lista_espera', array( 'inscripcion' => (string) $id ) );
		if ( ! $fila ) {
			Notices::add( 'error', __( 'That sign-up is no longer on the waiting list.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
			$this->redirect( $this->url( 'lista_espera' ) );
		}
		$gestiona = current_user_can( self::CAP_ALTA );
		switch ( $accion ) {
			case 'espera_estado':
				Inscripciones::cambiar_estado( $fila, sanitize_key( $this->post( 'estado' ) ), $this->texto( 'nota' ) )
					? Notices::add( 'success', __( 'Status changed.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) )
					: Notices::add( 'error', __( 'The status could not be changed.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
				break;

			case 'espera_contacto':
				Inscripciones::registrar_contacto( $fila, sanitize_key( $this->post( 'medio' ) ), $this->texto( 'nota' ) )
					? Notices::add( 'success', __( 'Contact recorded.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) )
					: Notices::add( 'error', __( 'The contact could not be recorded.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
				break;

			case 'espera_email':
				$asunto = mb_substr( sanitize_text_field( $this->post( 'asunto' ) ), 0, 150 );
				$texto  = $this->texto( 'mensaje' );
				if ( '' === $asunto || '' === $texto ) {
					Notices::add( 'error', __( 'Enter the subject and the message.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
					break;
				}
				$r = Avisos::mensaje( $fila, $asunto, $texto );
				if ( $r['ok'] ) {
					Inscripciones::registrar_contacto( $fila, Inscripciones::POR_EMAIL, $texto, $asunto );
					Notices::add( 'success', sprintf( /* translators: %s: text */ __( 'Email sent to %s.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), esc_html( $r['destino'] ) ) );
				} else {
					Notices::add( 'error', esc_html( __( 'The email could not be sent: ', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) . $r['error'] ) );
				}
				break;

			case 'espera_seccion':
				if ( $gestiona ) {
					$s = sanitize_key( $this->post( 'seccion' ) );
					Inscripciones::asignar_seccion( $fila, $s )
						? Notices::add( 'success', '' === $s ? __( 'Section: the one for their age.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) : sprintf( /* translators: %s: text */ __( 'Assigned section: %s.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), esc_html( Secciones::nombre( $s ) ) ) )
						: Notices::add( 'error', __( 'The section could not be assigned.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
				}
				break;

			case 'espera_quitar':
				if ( $gestiona && Inscripciones::borrar( $fila['id'], 'equipo', array( 'user' => get_current_user_id() ) ) ) {
					Notices::add( 'success', __( 'Sign-up deleted from the waiting list.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
					$this->redirect( $this->url( 'lista_espera' ) );
				}
				break;
		}
		$this->redirect( $volver );
	}

	/** Texto de varias líneas (notas, mensajes). */
	private function texto( string $campo ): string {
		return isset( $_POST[ $campo ] ) && is_string( $_POST[ $campo ] ) ? mb_substr( trim( sanitize_textarea_field( wp_unslash( $_POST[ $campo ] ) ) ), 0, 4000 ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- comprobado en handle_post()
	}
}
