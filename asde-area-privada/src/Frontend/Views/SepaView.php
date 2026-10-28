<?php
namespace AsdeBalears\AreaPrivada\Frontend\Views;

use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Crm\CrmException;
use AsdeBalears\AreaPrivada\Crm\PersonaRepository;
use AsdeBalears\AreaPrivada\Domain\Modulos;
use AsdeBalears\AreaPrivada\Eventos\Cobros as EventosCobros;
use AsdeBalears\AreaPrivada\Eventos\EventosTable;
use AsdeBalears\AreaPrivada\Frontend\Notices;
use AsdeBalears\AreaPrivada\Sepa\Avisos;
use AsdeBalears\AreaPrivada\Sepa\Config as SepaConfig;
use AsdeBalears\AreaPrivada\Sepa\Iban;
use AsdeBalears\AreaPrivada\Sepa\CuentaCrm;
use AsdeBalears\AreaPrivada\Sepa\Mandatos;
use AsdeBalears\AreaPrivada\Sepa\Remesas;
use AsdeBalears\AreaPrivada\Tesoreria\Calculadora;
use AsdeBalears\AreaPrivada\Tesoreria\Config;
use AsdeBalears\AreaPrivada\Tesoreria\FamiliasTable;

defined( 'ABSPATH' ) || exit;

/**
 * Tesorería · Domiciliaciones SEPA (Secretaría, Coordinación y Tesorería):
 * - los datos del acreedor (nombre, identificador, IBAN);
 * - las autorizaciones de las familias;
 * - nueva remesa: qué se cobraría a cada familia y el fichero para el banco;
 * - las remesas hechas: descargar, avisar a las familias, marcar como enviada
 *   al banco y registrar los cobros y las devoluciones;
 * - v0.36: «Cobrar un evento», la remesa de lo pendiente de las inscripciones de un evento.
 */
final class SepaView extends TesoreriaBaseView {

	public function render( string $content = '' ): string {
		if ( ! current_user_can( self::CAP_CONFIG ) ) {
			return $this->denegado();
		}
		if ( ! \AsdeBalears\AreaPrivada\Domain\Modulos::activo( \AsdeBalears\AreaPrivada\Domain\Modulos::SEPA ) ) {
			return $this->denegado( __( 'SEPA direct debits are not active in your group.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
		}
		$repo = PersonaRepository::make();
		if ( ! $repo ) {
			return $this->error_crm( 'tesoreria', __( 'Your group\'s connection to the CRM is not set up or could not be checked. An administrator can review it in wp-admin → Scout Groups.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
		}
		$ronda = $this->ronda();
		try {
			$id = (int) $this->get( 'remesa' );
			if ( $id && ( $r = Remesas::obtener( $id ) ) ) {
				$ronda = $r['ronda']; // Las pestañas se marcan con la ronda de la remesa.
			}
			$html = $id ? $this->remesa( $repo, $id, $ronda ) : $this->panel( $repo, $ronda );
		} catch ( CrmException $e ) {
			return $this->error_crm( 'tesoreria', __( 'The CRM could not be queried: ', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) . $e->getMessage() );
		}
		return $this->layout( 'tesoreria', $this->subnav( 'sepa', $ronda ) . $html );
	}

	private function panel( PersonaRepository $repo, string $ronda ): string {
		$cfg      = SepaConfig::de();
		$tcfg     = Config::de( $ronda );
		$familias = FamiliasTable::todas();
		$nums     = array();
		foreach ( $familias as $f ) {
			$nums = array_merge( $nums, $f['miembros'] );
		}
		$personas = $repo->obtener_varias( $nums );

		// Vista previa de la remesa (sin guardar nada), si se ha pedido.
		$prep    = null;
		$errores = array();
		$plazo   = $this->get( 'plazo' );
		$fecha   = $this->get( 'fecha' );
		$concepto = $this->get( 'concepto' ) ?: SepaConfig::concepto( $cfg, $ronda );
		if ( '1' === $this->get( 'preparar' ) && SepaConfig::lista( $cfg ) ) {
			$errores = array_filter( array( Remesas::error_fecha( $fecha ) ) );
			$prep    = Remesas::preparar( $repo, $ronda, $plazo );
		}

		// v0.36: remesa de un evento (todo lo pendiente de sus inscripciones, P-163).
		$eventos  = $this->eventos_sepa();
		$ev_id    = (int) $this->get( 'evento_sepa' );
		$ev_prep  = null;
		$ev_err   = array();
		$ev_fecha = $this->get( 'fecha_evento' );
		if ( '1' === $this->get( 'preparar_evento' ) && isset( $eventos[ $ev_id ] ) && SepaConfig::lista( $cfg ) ) {
			$ev_err  = array_filter( array( Remesas::error_fecha( $ev_fecha ) ) );
			$ev_prep = Remesas::preparar_evento( $repo, $eventos[ $ev_id ] );
		}

		// v0.33: la cuenta de cada autorización está en las fichas del CRM (titular y si sigue siendo la autorizada).
		$mandatos = Mandatos::activos();
		$cuentas  = array();
		$socios_m = array();
		foreach ( array_intersect_key( $familias, $mandatos ) as $f ) {
			$socios_m = array_merge( $socios_m, $f['miembros'] );
		}
		try {
			$leidas = $socios_m ? CuentaCrm::leer( $repo, $socios_m ) : array();
			foreach ( $mandatos as $fid => $m ) {
				$cuentas[ $fid ] = Mandatos::cuenta_local( $m ) ?? ( isset( $familias[ $fid ] ) ? CuentaCrm::de_familia( $familias[ $fid ], (string) $m['iban_huella'], $leidas ) : null );
			}
		} catch ( CrmException $e ) {
			$cuentas = null; // Sin CRM no se puede saber: no se avisa de nada.
		}

		return $this->tpl(
			'tesoreria/sepa',
			array(
				'ronda'      => $ronda,
				'cfg'        => $cfg,
				'errores_cfg' => SepaConfig::errores( $cfg ),
				'ics_aviso'  => '' !== $cfg['ics'] && SepaConfig::ics_formato( $cfg['ics'] ) && ! SepaConfig::ics_control_correcto( $cfg['ics'] ),
				'lista'      => SepaConfig::lista( $cfg ),
				'plazos'     => array_values( $tcfg['plazos'] ),
				'familias'   => $familias,
				'personas'   => $personas,
				'mandatos'   => $mandatos,
				'cuentas'    => $cuentas,
				'locales'    => Mandatos::hay_cuentas_locales(),
				'remesas'    => Remesas::listado(),
				'prep'       => $prep,
				'errores'    => $errores,
				'plazo'      => $plazo,
				'fecha'      => $fecha ?: Remesas::fecha_minima(),
				'fecha_min'  => Remesas::fecha_minima(),
				'concepto'   => $concepto,
				'eventos'    => $eventos,
				'ev_id'      => $ev_id,
				'ev_prep'    => $ev_prep,
				'ev_errores' => $ev_err,
				'ev_fecha'   => $ev_fecha ?: Remesas::fecha_minima(),
				'ev_concepto' => $this->get( 'concepto_evento' ) ?: ( isset( $eventos[ $ev_id ] ) ? self::concepto_evento( $eventos[ $ev_id ] ) : '' ),
				'url'        => $this->url( 'sepa' ),
				'url_ronda'  => $this->url( 'sepa', array( 'ronda' => $ronda ) ),
			)
		);
	}

	/**
	 * Eventos que se pueden cobrar por domiciliación (v0.36): publicados, con coste y con las
	 * cuentas abiertas; solo con el módulo de eventos y para quien lleva sus cobros.
	 *
	 * @return array<int, array> id => evento
	 */
	private function eventos_sepa(): array {
		if ( ! Modulos::activo( Modulos::EVENTOS ) || ! EventosCobros::puede() ) {
			return array();
		}
		$out = array();
		foreach ( EventosTable::todos() as $e ) {
			if ( EventosTable::PUBLICADO === $e['estado'] && $e['con_coste'] && EventosCobros::abiertas( $e ) ) {
				$out[ (int) $e['id'] ] = $e;
			}
		}
		return $out;
	}

	private static function concepto_evento( array $e ): string {
		return mb_substr( sprintf( /* translators: 1: text, 2: text */ __( 'Event %1$s · %2$s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), EventosTable::numero( $e ), $e['nombre'] ), 0, 140 );
	}

	private function remesa( PersonaRepository $repo, int $id, string $ronda ): string {
		$r = Remesas::obtener( $id );
		if ( ! $r ) {
			return $this->tpl( 'comun/avisos', array( 'avisos' => array( array( 'error', __( 'That batch does not exist.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) ) ) ) . $this->panel( $repo, $ronda );
		}
		$lineas   = Remesas::con_cuentas( Remesas::lineas( $r ) )['lineas']; // v0.33: el titular, de la ficha del CRM.
		$familias = array();
		foreach ( $lineas as $l ) {
			$f = FamiliasTable::obtener( (int) $l['familia'] );
			if ( $f ) {
				$familias[ (int) $l['familia'] ] = $f;
			}
		}
		$nums = array();
		foreach ( $familias as $f ) {
			$nums = array_merge( $nums, $f['miembros'] );
		}
		return $this->tpl(
			'tesoreria/sepa-remesa',
			array(
				'r'        => $r,
				'lineas'   => $lineas,
				'familias' => $familias,
				'personas' => $repo->obtener_varias( $nums ),
				'url'      => $this->url( 'sepa', array( 'ronda' => $r['ronda'] ) ),
				'url_fam'  => $this->url( 'tesoreria' ),
			)
		);
	}

	public function handle_post(): void {
		if ( ! current_user_can( self::CAP_CONFIG ) || ! \AsdeBalears\AreaPrivada\Domain\Modulos::activo( \AsdeBalears\AreaPrivada\Domain\Modulos::SEPA ) ) {
			wp_die( esc_html__( 'You do not have permission to change the direct debits.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), 403 );
		}
		$ronda  = Config::es_ronda( $this->post( 'ronda' ) ) ? $this->post( 'ronda' ) : Config::ronda_actual();
		$volver = $this->url( 'sepa', array( 'ronda' => $ronda ) );
		$accion = $this->post( 'asde_action' );
		$id     = (int) $this->post( 'remesa' );

		switch ( $accion ) {
			case 'sepa_config':
				check_admin_referer( 'asde_sepa_config' );
				$this->guardar_config();
				$this->redirect( $volver );
				break;

			case 'sepa_cuentas_crm':
				// v0.33 (P-131): las cuentas de las autorizaciones de antes, a las fichas del CRM.
				check_admin_referer( 'asde_sepa_cuentas_crm' );
				$repo = PersonaRepository::make();
				if ( ! $repo ) {
					Notices::add( 'error', __( 'The connection to the CRM is not available.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
					$this->redirect( $volver );
				}
				$res = Mandatos::pasar_al_crm( $repo );
				/* translators: 1: authorisations moved, 2: cancelled ones cleaned */
				Notices::add( $res['errores'] ? 'warning' : 'success', esc_html( sprintf( /* translators: 1: number, 2: number */ __( 'Accounts moved to the CRM records: %1$d. Authorisations cancelled whose account was deleted: %2$d.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $res['pasadas'], $res['borradas'] ) ) );
				foreach ( $res['errores'] as $e ) {
					Notices::add( 'error', esc_html( $e ) );
				}
				$this->redirect( $volver );
				break;

			case 'sepa_generar':
				check_admin_referer( 'asde_sepa_generar' );
				$repo = PersonaRepository::make();
				if ( ! $repo ) {
					Notices::add( 'error', __( 'The connection to the CRM is not available.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
					$this->redirect( $volver );
				}
				try {
					$prep = Remesas::preparar( $repo, $ronda, $this->post( 'plazo' ) );
				} catch ( CrmException $e ) {
					Notices::add( 'error', esc_html( __( 'The CRM could not be queried: ', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) . $e->getMessage() ) );
					$this->redirect( $volver );
				}
				$nueva = Remesas::crear( $prep, $ronda, $this->post( 'fecha' ), $this->post( 'concepto' ), get_current_user_id() );
				if ( is_wp_error( $nueva ) ) {
					Notices::add( 'error', esc_html( $nueva->get_error_message() ) );
					$this->redirect( add_query_arg( array( 'preparar' => '1', 'plazo' => $this->post( 'plazo' ), 'fecha' => $this->post( 'fecha' ), 'concepto' => $this->post( 'concepto' ) ), $volver ) );
				}
				$r = Remesas::obtener( $nueva );
				Notices::add( 'success', sprintf( /* translators: 1: text, 2: text, 3: text */ __( 'Batch <strong>%1$s</strong> created: %2$s for %3$s. Download the file and upload it to the bank; then mark it as sent.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), esc_html( $r['numero'] ), Remesas::adeudos( (int) $r['adeudos'] ), esc_html( Calculadora::euros( $r['total'] ) ) ) );
				$this->redirect( $this->url( 'sepa', array( 'ronda' => $ronda, 'remesa' => (string) $nueva ) ) );
				break;

			case 'sepa_generar_evento':
				// v0.36: la remesa de un evento.
				check_admin_referer( 'asde_sepa_generar_evento' );
				$ev   = $this->eventos_sepa()[ (int) $this->post( 'evento' ) ] ?? null;
				$repo = PersonaRepository::make();
				if ( ! $ev ) {
					Notices::add( 'error', __( 'That event cannot be collected by direct debit.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
					$this->redirect( $volver );
				}
				if ( ! $repo ) {
					Notices::add( 'error', __( 'The connection to the CRM is not available.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
					$this->redirect( $volver );
				}
				try {
					$prep = Remesas::preparar_evento( $repo, $ev );
				} catch ( CrmException $e ) {
					Notices::add( 'error', esc_html( __( 'The CRM could not be queried: ', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) . $e->getMessage() ) );
					$this->redirect( $volver );
				}
				$concepto = '' !== $this->post( 'concepto' ) ? $this->post( 'concepto' ) : self::concepto_evento( $ev );
				$nueva    = Remesas::crear( $prep, $ronda, $this->post( 'fecha' ), $concepto, get_current_user_id(), (int) $ev['id'] );
				if ( is_wp_error( $nueva ) ) {
					Notices::add( 'error', esc_html( $nueva->get_error_message() ) );
					$this->redirect( add_query_arg( array( 'preparar_evento' => '1', 'evento_sepa' => (string) $ev['id'], 'fecha_evento' => $this->post( 'fecha' ), 'concepto_evento' => $this->post( 'concepto' ) ), $volver ) );
				}
				$r = Remesas::obtener( $nueva );
				Notices::add( 'success', sprintf( /* translators: 1: text, 2: text, 3: text */ __( 'Batch <strong>%1$s</strong> created: %2$s for %3$s. Download the file and upload it to the bank; then mark it as sent.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), esc_html( $r['numero'] ), Remesas::adeudos( (int) $r['adeudos'] ), esc_html( Calculadora::euros( $r['total'] ) ) ) );
				$this->redirect( $this->url( 'sepa', array( 'ronda' => $ronda, 'remesa' => (string) $nueva ) ) );
				break;

			case 'sepa_descargar':
				check_admin_referer( 'asde_sepa_remesa_' . $id );
				$this->descargar( $id );
				break;

			case 'sepa_avisar':
				check_admin_referer( 'asde_sepa_remesa_' . $id );
				$r = Remesas::obtener( $id );
				if ( ! $r || Remesas::ANULADA === $r['estado'] ) {
					Notices::add( 'error', __( 'That batch does not exist or is cancelled.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
				} else {
					$a = Avisos::preaviso( $r );
					Logger::log( 'sepa_preaviso_enviado', 'remesa', $r['numero'], $a );
					Notices::add( 'success', sprintf( /* translators: 1: number, 2: number */ __( 'Advance notice sent: %1$d emails to %2$d families.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), (int) $a['enviados'], (int) $a['familias'] ) );
					if ( $a['sin_email'] ) {
						Notices::add( 'warning', sprintf( /* translators: %d: number */ __( '%d families could not be notified by email (no email or no account): notify them by other means.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), (int) $a['sin_email'] ) );
					}
				}
				$this->redirect( $this->url( 'sepa', array( 'ronda' => $ronda, 'remesa' => (string) $id ) ) );
				break;

			case 'sepa_enviada':
				check_admin_referer( 'asde_sepa_remesa_' . $id );
				Remesas::marcar_enviada( $id, get_current_user_id() )
					? Notices::add( 'success', __( 'Batch marked as sent to the bank. The authorisations used for the first time become "recurrent" (RCUR) for the next one. When the bank collects, record the collections here.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) )
					: Notices::add( 'error', __( 'That batch cannot be marked as sent.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
				$this->redirect( $this->url( 'sepa', array( 'ronda' => $ronda, 'remesa' => (string) $id ) ) );
				break;

			case 'sepa_anular':
				check_admin_referer( 'asde_sepa_remesa_' . $id );
				Remesas::anular( $id, get_current_user_id() )
					? Notices::add( 'success', __( 'Batch cancelled. Its families can be included in another one again.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) )
					: Notices::add( 'error', __( 'Only a batch that has not yet been sent to the bank can be cancelled.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
				$this->redirect( $volver );
				break;

			case 'sepa_cobros':
				check_admin_referer( 'asde_sepa_remesa_' . $id );
				$res = Remesas::registrar_cobros( $id, array_map( 'intval', $this->post_array( 'cobrada' ) ), array_map( 'intval', $this->post_array( 'devuelta' ) ), $this->post( 'fecha' ), get_current_user_id() );
				foreach ( $res['errores'] as $e ) {
					Notices::add( 'error', esc_html( $e ) );
				}
				foreach ( $res['avisos'] as $a ) {
					Notices::add( 'warning', esc_html( $a ) );
				}
				if ( $res['cobrados'] || $res['devueltos'] ) {
					Notices::add( 'success', sprintf( /* translators: 1: number, 2: text */ __( '%1$d collections recorded as families\' payments%2$s.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), (int) $res['cobrados'], $res['devueltos'] ? sprintf( /* translators: %d: number */ __( ' and %d refunds', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), (int) $res['devueltos'] ) : '' ) );
				}
				$this->redirect( $this->url( 'sepa', array( 'ronda' => $ronda, 'remesa' => (string) $id ) ) );
				break;

			case 'sepa_revocar':
				check_admin_referer( 'asde_sepa_revocar' );
				$m = Mandatos::obtener( (int) $this->post( 'mandato' ) );
				if ( $m && Mandatos::revocar( $m['id'], get_current_user_id(), 'Anulada por Tesorería. ' . $this->post( 'motivo' ) ) ) {
					Notices::add( 'success', sprintf( /* translators: %s: text */ __( 'Authorisation %s cancelled.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), esc_html( $m['referencia'] ) ) );
				} else {
					Notices::add( 'error', __( 'That authorisation could not be cancelled.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
				}
				$this->redirect( $volver );
				break;

			default:
				Notices::add( 'error', __( 'Invalid request.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
				$this->redirect( $volver );
		}
	}

	private function guardar_config(): void {
		$actual = SepaConfig::de();
		$texto  = fn( string $k, int $max ) => mb_substr( sanitize_text_field( $this->post( $k ) ), 0, $max );
		$nueva  = array(
			'activo'     => '1' === $this->post( 'activo' ),
			'nombre'     => $texto( 'nombre', 70 ),
			'ics'        => SepaConfig::normalizar_ics( $this->post( 'ics' ) ),
			'iban'       => Iban::normalizar( $this->post( 'iban' ) ),
			'bic'        => strtoupper( (string) preg_replace( '/[^A-Za-z0-9]/', '', $this->post( 'bic' ) ) ),
			// v0.56 (doc 58): la dirección del acreedor se cambia en «Datos del grupo»; aquí se conserva lo que haya.
			'calle'      => $actual['calle'],
			'cp'         => $actual['cp'],
			'poblacion'  => $actual['poblacion'],
			'provincia'  => $actual['provincia'],
			'dias_aviso' => max( 1, min( 30, (int) $this->post( 'dias_aviso' ) ) ),
			'concepto'   => $texto( 'concepto', 90 ),
		);
		if ( '' === $nueva['iban'] ) {
			$nueva['iban'] = $actual['iban']; // Vacío: se conserva el guardado.
		}
		SepaConfig::guardar( $nueva );
		Logger::log( 'sepa_configurada', 'grupo', \AsdeBalears\AreaPrivada\Domain\Grupo::numero(), array( 'activo' => $nueva['activo'] ) );
		$errores = SepaConfig::errores( SepaConfig::de() );
		if ( $errores ) {
			Notices::add( 'warning', __( 'Saved, but something is still missing before direct debit can be used: ', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) . esc_html( implode( ' ', $errores ) ) );
		} else {
			Notices::add( 'success', $nueva['activo'] ? __( 'Creditor details saved. Families can now sign their authorisation in "My payments".', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) : __( 'Creditor details saved. Families do not see the option yet: tick "Offer direct debit to families" when you want.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
		}
	}

	/** Envía el XML de la remesa al navegador (se llama antes de pintar la página). */
	private function descargar( int $id ): void {
		$r = Remesas::obtener( $id );
		if ( ! $r || Remesas::ANULADA === $r['estado'] ) {
			Notices::add( 'error', __( 'That batch does not exist or is cancelled.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
			$this->redirect( $this->url( 'sepa' ) );
		}
		$xml = Remesas::xml( $r );
		if ( is_wp_error( $xml ) ) {
			Notices::add( 'error', esc_html( $xml->get_error_message() ) );
			$this->redirect( $this->url( 'sepa', array( 'ronda' => $r['ronda'], 'remesa' => (string) $id ) ) );
		}
		Remesas::contar_descarga( $id );
		Logger::log( 'sepa_remesa_descargada', 'remesa', $r['numero'] );
		nocache_headers();
		header( 'Content-Type: application/xml; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="' . Remesas::nombre_fichero( $r ) . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		echo $xml; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- descarga XML SEPA, no HTML
		exit;
	}
}
