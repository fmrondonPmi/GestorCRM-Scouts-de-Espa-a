<?php
namespace AsdeBalears\AreaPrivada\Frontend\Views;

use AsdeBalears\AreaPrivada\Auth\Ambito;
use AsdeBalears\AreaPrivada\Eventos\Cobros;
use AsdeBalears\AreaPrivada\Eventos\Config;
use AsdeBalears\AreaPrivada\Eventos\EventosTable;
use AsdeBalears\AreaPrivada\Eventos\Inscripciones;
use AsdeBalears\AreaPrivada\Eventos\InscripcionesTable;
use AsdeBalears\AreaPrivada\Eventos\MovimientosTable;
use AsdeBalears\AreaPrivada\Frontend\Notices;

defined( 'ABSPATH' ) || exit;

/**
 * «Mis eventos» (v0.35, doc 43): los eventos a los que están convocadas la persona y los
 * menores que representa, con su estado, lo que falta (autorización, pago) y la información
 * práctica; inscribir (la familia firma la autorización al inscribir), preinscribirse (de 14
 * a 17), responder «no va» a una convocatoria, firmar una autorización pendiente, confirmar
 * una plaza que se ha liberado y darse de baja mientras el plazo está abierto.
 *
 * Sin JavaScript: cada acción es un formulario (el de inscribir, dentro de un desplegable).
 */
final class MisEventosView extends View {

	/** Lo escrito en el formulario de inscripción que no se ha podido guardar: evento|socio y datos. */
	private static ?array $valores = null;

	public function render( string $content = '' ): string {
		$a = Ambito::actual();
		if ( ! current_user_can( Inscripciones::CAP ) || ! Inscripciones::para_quien( $a ) ) {
			return $this->denegado();
		}
		$lista = Inscripciones::mis_eventos( $a );
		foreach ( $lista as $i => $item ) {
			// La lista de espera, al día al mirarla (plazas que caducan o se liberan).
			Inscripciones::revisar( $item['evento'] );
		}
		$lista = Inscripciones::mis_eventos( $a );
		$ids   = array();
		foreach ( $lista as $item ) {
			foreach ( $item['personas'] as $x ) {
				if ( $x['fila'] ) {
					$ids[] = $x['fila']['id'];
				}
			}
		}
		$movs   = MovimientosTable::de_inscripciones( $ids );
		$cobros = array();
		$refs   = array();
		$puestos = array();
		foreach ( $lista as $item ) {
			$filas = null;
			foreach ( $item['personas'] as $x ) {
				$f = $x['fila'];
				if ( ! $f ) {
					continue;
				}
				$cobros[ $f['id'] ] = Cobros::de( $f, $movs[ $f['id'] ] ?? array() );
				$refs[ $f['id'] ]   = Cobros::referencia( $item['evento'], $f );
				if ( InscripcionesTable::ESPERA === $f['estado'] ) {
					$filas               = $filas ?? InscripcionesTable::participantes( $item['evento']['id'] );
					$puestos[ $f['id'] ] = Inscripciones::puesto( $f, $filas );
				}
			}
		}
		$cfg = Config::de();
		return $this->layout(
			'mis_eventos',
			$this->tpl(
				'eventos/mis-eventos',
				array(
					'lista'       => $lista,
					'ambito'      => $a,
					'cfg'         => $cfg,
					'cobros'      => $cobros,
					'refs'        => $refs,
					'puestos'     => $puestos,
					'autorizacion' => Config::autorizacion( $cfg ),
					'valores'     => self::$valores,
					'url'         => $this->url( 'mis_eventos' ),
					'url_ficha'   => fn( string $num ) => isset( $a->representados[ $num ] ) ? $this->url( 'ficha', array( 'socio' => $num ) ) : $this->url( 'ficha' ),
					'url_pagos'   => $this->url( 'pagos' ),
				)
			)
		);
	}

	public function handle_post(): void {
		$a = Ambito::actual();
		if ( ! current_user_can( Inscripciones::CAP ) || ! Inscripciones::para_quien( $a ) ) {
			wp_die( esc_html__( 'No tienes permiso para hacer esto.', 'asde-area-privada' ), 403 );
		}
		$e = EventosTable::obtener( (int) $this->post( 'evento' ) );
		check_admin_referer( 'asde_mis_eventos_' . ( $e['id'] ?? 0 ) );
		$volver = $this->url( 'mis_eventos' );
		if ( ! $e ) {
			Notices::add( 'error', asde_t( 'Ese evento no existe.' ) );
			$this->redirect( $volver );
		}
		$in     = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification -- comprobado arriba.
		$accion = $this->post( 'asde_action' );
		$socio  = $this->post( 'socio' );
		$fila   = (int) $this->post( 'fila' );
		$hecho  = '';
		$r      = new \WP_Error( 'asde_inscripcion', asde_t( 'Acción desconocida.' ) );
		switch ( $accion ) {
			case 'inscripcion_crear':
				$r = Inscripciones::inscribir( $e, $a, $socio, $in );
				if ( is_wp_error( $r ) ) {
					self::$valores = array(
						'clave' => $e['id'] . '|' . $socio,
						'datos' => $in,
					);
					Notices::add( 'error', esc_html( $r->get_error_message() ) );
					return; // Se vuelve a enseñar el formulario con lo escrito.
				}
				$hecho = InscripcionesTable::ESPERA === $r['estado']
					? asde_t( 'No quedaban plazas: queda en la lista de espera. Si se libera una, te avisaremos por email.' )
					: ( InscripcionesTable::CONFIRMADA === $r['estado'] ? asde_t( 'Inscripción confirmada.' ) : asde_t( 'Inscripción guardada. Mira abajo lo que falta para confirmarla.' ) );
				break;
			case 'inscripcion_no':
				$r     = Inscripciones::no_participa( $e, $a, $socio );
				$hecho = asde_t( 'Respuesta guardada: no va a este evento.' );
				break;
			case 'inscripcion_firmar':
				$r     = Inscripciones::firmar( $e, $a, $fila, '1' === $this->post( 'acepto' ) );
				$hecho = asde_t( 'Autorización firmada.' );
				break;
			case 'inscripcion_plaza':
				$r     = Inscripciones::confirmar_plaza( $e, $a, $fila );
				$hecho = asde_t( 'Plaza confirmada.' );
				break;
			case 'inscripcion_baja':
				$r     = Inscripciones::baja( $e, $a, $fila, '', false );
				$hecho = asde_t( 'Inscripción dada de baja.' );
				break;
		}
		is_wp_error( $r ) ? Notices::add( 'error', esc_html( $r->get_error_message() ) ) : Notices::add( 'success', esc_html( $hecho ) );
		$this->redirect( $volver . '#evento-' . $e['id'] );
	}
}
