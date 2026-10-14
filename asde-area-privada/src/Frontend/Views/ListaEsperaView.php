<?php
namespace AsdeBalears\AreaPrivada\Frontend\Views;

use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Crm\Altas;
use AsdeBalears\AreaPrivada\Domain\Grupo;
use AsdeBalears\AreaPrivada\Domain\Grupos;
use AsdeBalears\AreaPrivada\Domain\Secciones;
use AsdeBalears\AreaPrivada\Frontend\Notices;
use AsdeBalears\AreaPrivada\ListaEspera\Avisos;
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
				return $this->denegado( asde_t( 'Esa inscripción ya no está en la lista de espera.' ) );
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
					'renovar' => current_user_can( self::CAP_ALTA ),
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
				'altas_motivo' => Altas::activo() ? asde_t( 'El usuario del CRM de tu grupo no tiene permiso para crear personas y membresías.' ) : asde_t( 'Las altas en el CRM no están activadas para tu grupo (wp-admin → Grupos Scouts → Módulos).' ),
				'asunto'      => sprintf( asde_t( 'Lista de espera del %s' ), $grupo ? Grupos::etiqueta( $grupo ) : Grupo::nombre() ),
				'url'         => $this->url( 'lista_espera', array( 'inscripcion' => (string) $fila['id'] ) ),
				'volver'      => $this->url( 'lista_espera' ),
				'url_alta'    => $this->url( 'personas', array( 'alta' => '1' ) ),
			)
		);
	}

	public function handle_post(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'No tienes permiso para hacer esto.', 'asde-area-privada' ), 403 );
		}
		$accion = $this->post( 'asde_action' );
		if ( 'renovar_enlace_espera' === $accion ) {
			check_admin_referer( 'asde_enlace_espera' );
			if ( current_user_can( self::CAP_ALTA ) && Grupo::actual() ) {
				Enlaces::renovar( Grupo::actual() );
				Logger::log( 'enlace_espera_renovado', 'grupo', Grupo::numero() );
				Notices::add( 'success', asde_t( 'Enlace de la lista de espera renovado: el anterior ya no sirve.' ) );
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
			Notices::add( 'error', asde_t( 'Esa inscripción ya no está en la lista de espera.' ) );
			$this->redirect( $this->url( 'lista_espera' ) );
		}
		$gestiona = current_user_can( self::CAP_ALTA );
		switch ( $accion ) {
			case 'espera_estado':
				Inscripciones::cambiar_estado( $fila, sanitize_key( $this->post( 'estado' ) ), $this->texto( 'nota' ) )
					? Notices::add( 'success', asde_t( 'Estado cambiado.' ) )
					: Notices::add( 'error', asde_t( 'No se ha podido cambiar el estado.' ) );
				break;

			case 'espera_contacto':
				Inscripciones::registrar_contacto( $fila, sanitize_key( $this->post( 'medio' ) ), $this->texto( 'nota' ) )
					? Notices::add( 'success', asde_t( 'Contacto apuntado.' ) )
					: Notices::add( 'error', asde_t( 'No se ha podido apuntar el contacto.' ) );
				break;

			case 'espera_email':
				$asunto = mb_substr( sanitize_text_field( $this->post( 'asunto' ) ), 0, 150 );
				$texto  = $this->texto( 'mensaje' );
				if ( '' === $asunto || '' === $texto ) {
					Notices::add( 'error', asde_t( 'Escribe el asunto y el mensaje.' ) );
					break;
				}
				$r = Avisos::mensaje( $fila, $asunto, $texto );
				if ( $r['ok'] ) {
					Inscripciones::registrar_contacto( $fila, Inscripciones::POR_EMAIL, $texto, $asunto );
					Notices::add( 'success', sprintf( asde_t( 'Email enviado a %s.' ), esc_html( $r['destino'] ) ) );
				} else {
					Notices::add( 'error', esc_html( asde_t( 'No se ha podido enviar el email: ' ) . $r['error'] ) );
				}
				break;

			case 'espera_seccion':
				if ( $gestiona ) {
					$s = sanitize_key( $this->post( 'seccion' ) );
					Inscripciones::asignar_seccion( $fila, $s )
						? Notices::add( 'success', '' === $s ? asde_t( 'Sección: la de su edad.' ) : sprintf( asde_t( 'Sección asignada: %s.' ), esc_html( Secciones::nombre( $s ) ) ) )
						: Notices::add( 'error', asde_t( 'No se ha podido asignar la sección.' ) );
				}
				break;

			case 'espera_quitar':
				if ( $gestiona && Inscripciones::borrar( $fila['id'], 'equipo', array( 'user' => get_current_user_id() ) ) ) {
					Notices::add( 'success', asde_t( 'Inscripción borrada de la lista de espera.' ) );
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
