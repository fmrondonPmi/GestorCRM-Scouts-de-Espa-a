<?php
namespace AsdeBalears\AreaPrivada\Frontend\Views;

use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Crm\Metadatos;
use AsdeBalears\AreaPrivada\Domain\Grupo;
use AsdeBalears\AreaPrivada\Eventos\Config;
use AsdeBalears\AreaPrivada\Eventos\Plantillas;
use AsdeBalears\AreaPrivada\Eventos\TareasTable;
use AsdeBalears\AreaPrivada\Eventos\Servicio;
use AsdeBalears\AreaPrivada\Frontend\Notices;

defined( 'ABSPATH' ) || exit;

/**
 * Configuración de los eventos del grupo (Coordinación y Tesorería): margen del fondo de
 * emergencia, tipos de actividad y qué valores de la formación de tiempo libre del CRM
 * cuentan como título de director/a o de monitor/a.
 */
final class EventosConfigView extends EventosBaseView {

	public function render( string $content = '' ): string {
		if ( ! current_user_can( Servicio::CAP_CONFIG ) ) {
			return $this->denegado();
		}
		$cfg        = Config::de();
		$plantillas = Plantillas::de_grupo( $cfg );
		$elegida    = isset( $plantillas[ $this->get( 'plantilla' ) ] ) ? $this->get( 'plantilla' ) : Plantillas::ACAMPADA;
		return $this->layout(
			'eventos_config',
			$this->subnav( 'eventos_config' ) . $this->tpl(
				'eventos/config',
				array(
					'cfg'      => $cfg,
					'plantillas' => $plantillas, // v0.40: calendario de tareas (doc 49).
					'plantilla'  => $elegida,
					'original'   => Plantillas::es_original( $cfg ),
					// Las opciones del campo, si el CRM las da (sin datos de personas; se guardan 12 horas).
					'opciones' => (array) ( Metadatos::campos( 'Contacts', array( 'formaciontiempolibre_c' ) )['formaciontiempolibre_c']['opciones'] ?? array() ),
					'url'      => $this->url( 'eventos_config' ),
				)
			)
		);
	}

	/** Calendario de tareas (v0.40): fin de semana, y crear, cambiar, restaurar y borrar plantillas. Siempre redirige. */
	private function calendario_post( array $cfg ): void {
		$accion  = $this->post( 'asde_action' );
		$id      = sanitize_key( $this->post( 'plantilla' ) );
		$plantillas = Plantillas::de_grupo( $cfg );
		$volver  = $this->url( 'eventos_config', isset( $plantillas[ $id ] ) ? array( 'plantilla' => $id ) : array() );
		$guardadas = (array) $cfg['plantillas'];

		switch ( $accion ) {
			case 'calendario_guardar':
				$cfg['calendario_viernes'] = '1' === $this->post( 'calendario_viernes' );
				$cfg['avisos_tareas']      = '1' === $this->post( 'avisos_tareas' );
				$aviso = __( 'Calendar saved.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' );
				break;

			case 'plantilla_nueva':
				$nombre = mb_substr( trim( sanitize_text_field( $this->post( 'nombre' ) ) ), 0, 60 );
				if ( '' === $nombre || count( $plantillas ) >= Plantillas::MAX_PLANTILLAS ) {
					Notices::add( 'error', __( 'Enter the template name (there is a maximum number of templates per group).', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
					$this->redirect( $volver );
				}
				$nuevo              = Plantillas::id_para( $nombre, $plantillas );
				$copia              = '1' === $this->post( 'copiar' ) ? $plantillas[ Plantillas::ACAMPADA ]['tareas'] : array();
				$guardadas[ $nuevo ] = array(
					'nombre' => $nombre,
					'tareas' => $copia,
				);
				$volver = $this->url( 'eventos_config', array( 'plantilla' => $nuevo ) );
				$aviso  = __( 'Template created.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' );
				break;

			case 'plantilla_restaurar':
				unset( $guardadas[ Plantillas::ACAMPADA ] );
				$volver = $this->url( 'eventos_config' );
				$aviso  = __( 'Camp template restored to the original.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' );
				break;

			case 'plantilla_borrar':
				if ( Plantillas::ACAMPADA === $id || ! isset( $guardadas[ $id ] ) ) {
					Notices::add( 'error', __( 'That template cannot be deleted.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
					$this->redirect( $volver );
				}
				unset( $guardadas[ $id ] );
				$volver = $this->url( 'eventos_config' );
				$aviso  = __( 'Template deleted.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' );
				break;

			case 'plantilla_guardar':
				if ( ! isset( $plantillas[ $id ] ) ) {
					Notices::add( 'error', __( 'That template does not exist.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
					$this->redirect( $this->url( 'eventos_config' ) );
				}
				$tareas = array();
				$n      = min( TareasTable::MAX, (int) $this->post( 'filas' ) );
				for ( $i = 0; $i < $n + 3; $i++ ) {
					$p = $i < $n ? 't' : 'n'; // Las que había (t) y las tres nuevas (n).
					$k = $i < $n ? $i : $i - $n;
					if ( 't' === $p && '1' === $this->post( 't_quitar_' . $k ) ) {
						continue;
					}
					$limpia = Plantillas::limpiar_tarea(
						array(
							'titulo'  => $this->post( $p . '_titulo_' . $k ),
							'detalle' => $this->post( $p . '_detalle_' . $k ),
							'ref'     => $this->post( $p . '_ref_' . $k ),
							'desfase' => $this->post( $p . '_desfase_' . $k ),
							'auto'    => $this->post( $p . '_auto_' . $k ),
							'enlace'  => $this->post( $p . '_enlace_' . $k ),
						)
					);
					if ( $limpia ) {
						$tareas[] = $limpia;
					}
				}
				$nombre = mb_substr( trim( sanitize_text_field( $this->post( 'nombre' ) ) ), 0, 60 );
				$guardadas[ $id ] = array(
					'nombre' => '' !== $nombre ? $nombre : $plantillas[ $id ]['nombre'],
					'tareas' => $tareas,
				);
				$aviso = __( 'Template saved. Events that already applied it do not change.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' );
				break;

			default:
				$this->redirect( $volver );
		}

		$cfg['plantillas'] = $guardadas;
		Config::guardar( $cfg );
		Logger::log( 'eventos_configurados', 'grupo', Grupo::numero(), array( 'campos' => array( $accion ) ) );
		Notices::add( 'success', $aviso );
		$this->redirect( $volver );
	}

	public function handle_post(): void {
		if ( ! current_user_can( Servicio::CAP_CONFIG ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), 403 );
		}
		check_admin_referer( 'asde_eventos_config' );
		$cfg = Config::de();

		// v0.42: el material habitual de cada tipo de evento.
		if ( 'material_guardar' === $this->post( 'asde_action' ) ) {
			$material = array();
			foreach ( array_keys( $cfg['tipos'] ) as $tipo ) {
				$material[ $tipo ] = $this->post( 'material_' . $tipo );
			}
			$cfg['material_por_tipo'] = $material;
			Config::guardar( $cfg );
			Logger::log( 'eventos_configurados', 'grupo', Grupo::numero(), array( 'campos' => array( 'material_por_tipo' ) ) );
			Notices::add( 'success', __( 'Usual equipment saved.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
			$this->redirect( $this->url( 'eventos_config' ) );
		}

		if ( str_starts_with( $this->post( 'asde_action' ), 'plantilla_' ) || 'calendario_guardar' === $this->post( 'asde_action' ) ) {
			$this->calendario_post( $cfg );
			return;
		}

		$cfg['margen_activo'] = '1' === $this->post( 'margen_activo' );
		$pct                  = (float) str_replace( ',', '.', $this->post( 'margen_pct' ) );
		if ( $pct < 0 || $pct > 50 ) {
			Notices::add( 'error', __( 'The emergency fund margin must be between 0 and 50 %.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
			$this->redirect( $this->url( 'eventos_config' ) );
		}
		$cfg['margen_pct'] = round( $pct, 2 );

		foreach ( array_keys( $cfg['tipos'] ) as $id ) {
			$nombre = trim( sanitize_text_field( $this->post( 'tipo_nombre_' . $id ) ) );
			if ( '' !== $nombre ) {
				$cfg['tipos'][ $id ]['nombre'] = mb_substr( $nombre, 0, 60 );
			}
			$cfg['tipos'][ $id ]['coste']        = '1' === $this->post( 'tipo_coste_' . $id );
			$cfg['tipos'][ $id ]['comunicacion'] = 'no' === $this->post( 'tipo_comunicacion_' . $id ) ? 'no' : 'auto';
			$cfg['tipos'][ $id ]['activo']       = '1' === $this->post( 'tipo_activo_' . $id );
		}
		$nuevo = trim( sanitize_text_field( $this->post( 'nuevo_tipo' ) ) );
		if ( '' !== $nuevo ) {
			if ( count( $cfg['tipos'] ) >= Config::MAX_TIPOS ) {
				Notices::add( 'error', sprintf( /* translators: %d: number */ __( 'At most %d activity types.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), Config::MAX_TIPOS ) );
			} else {
				$cfg['tipos'][ Config::id_para( $nuevo, $cfg['tipos'] ) ] = array(
					'nombre'       => mb_substr( $nuevo, 0, 60 ),
					'coste'        => '1' === $this->post( 'nuevo_coste' ),
					'comunicacion' => 'no' === $this->post( 'nuevo_comunicacion' ) ? 'no' : 'auto',
					'crm'          => 'jornada',
					'activo'       => true,
				);
			}
		}
		if ( ! Config::tipos_activos( $cfg ) ) {
			Notices::add( 'error', __( 'Leave at least one activity type active.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
			$this->redirect( $this->url( 'eventos_config' ) );
		}

		// Titulaciones: casillas (si el CRM dio las opciones) o una por línea.
		foreach ( array( 'director', 'monitor' ) as $k ) {
			$marcadas = $this->post_array( 'titulacion_' . $k );
			if ( ! $marcadas && '' !== $this->post( 'titulacion_' . $k . '_texto' ) ) {
				$marcadas = preg_split( '/\R/', $this->post( 'titulacion_' . $k . '_texto' ) ) ?: array();
			}
			$cfg['titulaciones'][ $k ] = array_values( array_unique( array_filter( array_map( static fn( $v ) => mb_substr( trim( sanitize_text_field( (string) $v ) ), 0, 100 ), $marcadas ) ) ) );
		}

		// Inscripción (v0.35): texto de la autorización (vacío = el del plugin), cómo pagar y recordatorio.
		$campos = array( 'margen', 'tipos', 'titulaciones' );
		if ( isset( $_POST['autorizacion_texto'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- comprobado arriba.
			$texto = mb_substr( trim( sanitize_textarea_field( $this->post( 'autorizacion_texto' ) ) ), 0, Config::MAX_AUTORIZACION );
			// Si es el texto del plugin tal cual, se guarda vacío: así se sigue traduciendo.
			$cfg['autorizacion_texto'] = trim( Config::autorizacion( array( 'autorizacion_texto' => '' ) ) ) === $texto ? '' : $texto;
			$campos[]                  = 'autorizacion';
		}
		if ( isset( $_POST['texto_pago'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$cfg['texto_pago'] = mb_substr( trim( wp_kses_post( $this->post( 'texto_pago' ) ) ), 0, 1000 );
			$campos[]          = 'texto_pago';
		}
		if ( isset( $_POST['recordatorio_dias'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$cfg['recordatorio_dias'] = min( 30, max( 0, (int) $this->post( 'recordatorio_dias' ) ) );
			$campos[]                 = 'recordatorio_dias';
		}
		// Avisos (v0.39): cuáles se mandan, si hay aviso push y los días de la oferta de plaza.
		if ( isset( $_POST['avisos_enviado'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$marcados = $this->post_array( 'avisos' );
			foreach ( Config::AVISOS as $a ) {
				$cfg['avisos'][ $a ] = in_array( $a, $marcados, true );
			}
			$cfg['push']        = '1' === $this->post( 'push' );
			$cfg['dias_oferta'] = min( Config::MAX_DIAS_OFERTA, max( 1, (int) $this->post( 'dias_oferta' ) ) );
			$cfg['recordatorio_extra'] = max( 0, (int) $this->post( 'recordatorio_extra' ) );
			$cfg['edad_cuenta_propia'] = min( Config::EDAD_MAX, max( Config::EDAD_MIN, (int) $this->post( 'edad_cuenta_propia' ) ) );
			foreach ( Config::AVISOS as $a ) {
				foreach ( array( 'intro', 'pie' ) as $k ) {
					$cfg['textos'][ $a ][ $k ] = mb_substr( trim( sanitize_textarea_field( $this->post( 'texto_' . $k . '_' . $a ) ) ), 0, Config::MAX_TEXTO_AVISO );
				}
			}
			$campos[] = 'avisos';
		}

		Config::guardar( $cfg );
		Logger::log( 'eventos_configurados', 'grupo', Grupo::numero(), array( 'campos' => $campos ) );
		Notices::add( 'success', __( 'Event settings saved.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
		$this->redirect( $this->url( 'eventos_config' ) );
	}
}
