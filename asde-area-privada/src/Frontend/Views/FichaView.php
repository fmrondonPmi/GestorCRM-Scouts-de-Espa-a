<?php
namespace AsdeBalears\AreaPrivada\Frontend\Views;

use AsdeBalears\AreaPrivada\Auth\Ambito;
use AsdeBalears\AreaPrivada\Crm\Altas;
use AsdeBalears\AreaPrivada\Crm\CrmException;
use AsdeBalears\AreaPrivada\Crm\Escritura;
use AsdeBalears\AreaPrivada\Crm\PersonaRepository;
use AsdeBalears\AreaPrivada\Sepa\CuentaCrm;
use AsdeBalears\AreaPrivada\Domain\Persona;
use AsdeBalears\AreaPrivada\Domain\Secciones;
use AsdeBalears\AreaPrivada\Fichas\Cambios;
use AsdeBalears\AreaPrivada\Fichas\FichaService;
use AsdeBalears\AreaPrivada\Fichas\PermisosFicha;
use AsdeBalears\AreaPrivada\Frontend\Notices;
use AsdeBalears\AreaPrivada\Socios\Numeracion;
use AsdeBalears\AreaPrivada\Tesoreria\FamiliasTable;
use AsdeBalears\AreaPrivada\Usuarios\AvisoBaja;
use AsdeBalears\AreaPrivada\Usuarios\Usuarios;

defined( 'ABSPATH' ) || exit;

/**
 * Ficha de una persona con todos los datos que el usuario puede ver. Lo que
 * puede cambiar aparece editable (directo al CRM, o como propuesta si es una
 * cuenta familiar); el resto, de solo lectura.
 *
 * ?socio=NNN (nº Scouts de España). Sin él: la ficha propia o la del primer
 * menor de la cuenta familiar.
 *
 * v0.23 (doc 34, fase 3): con las altas en el CRM activadas, Secretaría y
 * Coordinación dan desde aquí una membresía nueva, la baja, el cambio de sección y el
 * documento a quien no lo tiene (?membresia=nueva|baja|seccion|documento).
 */
final class FichaView extends View {

	/** Formularios de la membresía en el CRM (?membresia=…). */
	private const MEMBRESIA = array( 'nueva', 'baja', 'seccion', 'documento' );

	/** asde_action => formulario. */
	private const ACCIONES_MEMBRESIA = array(
		'membresia_nueva'   => 'nueva',
		'membresia_baja'    => 'baja',
		'membresia_seccion' => 'seccion',
		'poner_documento'   => 'documento',
	);

	/** Formulario de la membresía que no se ha podido hacer en esta petición: se vuelve a pintar con lo escrito. */
	private static ?array $membresia = null;

	public function render( string $content = '' ): string {
		$ambito = Ambito::actual();
		$repo   = PersonaRepository::make();
		if ( ! $repo ) {
			return $this->error_crm( 'ficha', __( 'Your group\'s connection to the CRM is not set up or could not be checked. An administrator can review it in wp-admin → Scout Groups.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
		}
		$socio = $this->socio( $ambito );
		try {
			$p = '' !== $socio ? $repo->obtener( $socio ) : null;
			if ( ! $p ) {
				return $this->denegado( __( 'That record cannot be found in the group\'s CRM.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
			}
			$perm = new PermisosFicha( $ambito, $p );
			if ( ! $perm->puede_ver() ) {
				return $this->denegado( __( 'You do not have access to this record.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
			}
			$accion = $this->get( 'membresia' );
			if ( in_array( $accion, self::MEMBRESIA, true ) ) {
				return current_user_can( 'asde_manage_users' ) && ! \AsdeBalears\AreaPrivada\Auth\SoloLectura::activo() ? $this->layout( 'personas', $this->membresia( $p, $accion ) ) : $this->denegado();
			}
			if ( $ambito->es_oficina() ) { // v0.50: cada ficha que consulta la oficina queda en la auditoría.
				\AsdeBalears\AreaPrivada\Audit\Logger::log( 'oficina_ficha_consultada', 'persona', $socio );
			}
			$campos = ( new FichaService( $repo ) )->cargar( $p, $perm );
			$numero = ( new Numeracion( $repo ) )->de( $p );
			$sepa   = $this->sepa( $repo, $ambito, $p, $perm );
			$desde  = $this->socio_desde( $repo, $p, $perm );
		} catch ( CrmException $e ) {
			return $this->error_crm( 'ficha', __( 'The CRM could not be queried: ', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) . $e->getMessage() );
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
					'altas'         => current_user_can( 'asde_manage_users' ) && Altas::disponible(),
					// v0.35.1: «Eliminar de la web» (sin membresía activa; en el CRM no cambia nada).
					'eliminar'      => current_user_can( 'asde_manage_users' ) && ! $perm->es_propia() && ! $p->tiene_membresia_activa() && ! \AsdeBalears\AreaPrivada\Socios\Eliminadas::esta( $p->num_socio_asde ),
					'representados' => $ambito->representados,
					'ambito'        => $ambito,
					'url'           => $this->url( 'ficha' ),
					'url_personas'  => $this->url( 'personas' ),
					'url_cambios'   => $this->url( 'cambios' ),
					'url_alta'      => $this->url( 'alta' ),
					'sepa'          => $sepa,
					'socio_desde'   => $desde,
				)
			)
		);
	}

	/**
	 * v0.34 (petición del usuario): «Socio/a desde» de la cabecera. Solo desde Personas (ni en la
	 * ficha propia ni en la de la familia), porque cuesta una consulta más al CRM; si falla, no se
	 * enseña y la ficha sale igual.
	 */
	private function socio_desde( PersonaRepository $repo, Persona $p, PermisosFicha $perm ): string {
		if ( $perm->es_propia() || $perm->es_representado() || ! $p->tiene_membresia_activa() ) {
			return '';
		}
		try {
			return Altas::socio_desde( ( new Altas( $repo->crm() ) )->membresias( $p->id ), wp_date( 'Y-m-d' ) );
		} catch ( CrmException | \InvalidArgumentException $e ) {
			return '';
		}
	}

	/**
	 * v0.33 (P-131): los datos bancarios de la ficha (del CRM, en vivo; no se guardan) y la
	 * autorización SEPA de su familia. Los ven Tesorería (y quien configura la Tesorería), la
	 * familia y el propio asociado mayor de edad; '' para el resto o con el módulo apagado.
	 */
	private function sepa( PersonaRepository $repo, Ambito $a, \AsdeBalears\AreaPrivada\Domain\Persona $p, PermisosFicha $perm ): string {
		if ( ! \AsdeBalears\AreaPrivada\Domain\Modulos::activo( \AsdeBalears\AreaPrivada\Domain\Modulos::SEPA ) ) {
			return '';
		}
		$familiar  = isset( $a->representados[ $p->num_socio_asde ] ) || ( $perm->es_propia() && null !== $p->edad() && $p->edad() >= 18 );
		$tesoreria = current_user_can( 'asde_tesoreria_config' );
		if ( ! $familiar && ! $tesoreria ) {
			return '';
		}
		$v       = $repo->valores( $p, array( CuentaCrm::IBAN, CuentaCrm::TITULAR, CuentaCrm::ENTIDAD ) );
		$iban    = \AsdeBalears\AreaPrivada\Sepa\Iban::normalizar( (string) ( $v[ CuentaCrm::IBAN ] ?? '' ) );
		$cuenta  = '' !== $iban ? array(
			'iban'    => $iban,
			'titular' => trim( (string) ( $v[ CuentaCrm::TITULAR ] ?? '' ) ),
			'entidad' => trim( (string) ( $v[ CuentaCrm::ENTIDAD ] ?? '' ) ),
		) : null;
		$familia = \AsdeBalears\AreaPrivada\Tesoreria\FamiliasTable::de_socio( $p->num_socio_asde );
		$mandato = $familia ? \AsdeBalears\AreaPrivada\Sepa\Mandatos::activo_de_familia( (int) $familia['id'] ) : null;
		return $this->tpl(
			'fichas/sepa',
			array(
				'cuenta'        => $cuenta,
				'familia'       => $familia,
				'mandato'       => $mandato,
				'coincide'      => $mandato && ( null !== \AsdeBalears\AreaPrivada\Sepa\Mandatos::cuenta_local( $mandato ) || ( $cuenta && hash_equals( (string) $mandato['iban_huella'], CuentaCrm::huella( $iban ) ) ) ),
				'url_gestion'   => $familiar ? $this->url( 'pagos' ) . ( $familia ? '#sepa-' . (int) $familia['id'] : '' ) : $this->url( 'sepa' ),
				'texto_gestion' => $familiar ? __( 'Authorise collection or change the account in "My payments"', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) : __( 'View the direct debits (Treasury)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
			)
		);
	}

	public function handle_post(): void {
		$ambito = Ambito::actual();
		$socio  = $this->post( 'socio' );
		$volver = $this->url( 'ficha', array( 'socio' => $socio ) );

		if ( isset( self::ACCIONES_MEMBRESIA[ $this->post( 'asde_action' ) ] ) ) {
			$this->membresia_post( $this->post( 'asde_action' ), $socio );
			return;
		}
		if ( 'eliminar_de_la_web' === $this->post( 'asde_action' ) ) {
			$this->eliminar_post( $socio );
			return;
		}
		if ( 'cancelar_cambio' === $this->post( 'asde_action' ) ) {
			$id = (int) $this->post( 'cambio' );
			check_admin_referer( 'asde_cancelar_cambio_' . $id );
			$r = Cambios::cancelar( $id );
			is_wp_error( $r ) ? Notices::add( 'error', esc_html( $r->get_error_message() ) ) : Notices::add( 'success', __( 'Proposal withdrawn.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
			$this->redirect( $volver );
		}
		if ( 'guardar_ficha' !== $this->post( 'asde_action' ) || ! PersonaRepository::es_num_asde( $socio ) ) {
			Notices::add( 'error', __( 'Invalid request.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
			$this->redirect( $this->url( 'ficha' ) );
		}
		check_admin_referer( 'asde_ficha_' . $socio );

		$repo = PersonaRepository::make();
		try {
			$p = $repo ? $repo->obtener( $socio ) : null;
			if ( ! $p ) {
				Notices::add( 'error', __( 'That record cannot be found in the group\'s CRM.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
				$this->redirect( $this->url( 'ficha' ) );
			}
			$perm = new PermisosFicha( $ambito, $p );
			if ( ! $perm->puede_ver() ) {
				wp_die( esc_html__( 'You do not have permission to do this.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), 403 );
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
			Notices::add( 'error', esc_html( __( 'The CRM could not be queried: ', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) . $e->getMessage() ) );
			$this->redirect( $volver );
		}

		$lista = static fn( array $a ) => esc_html( implode( ', ', $a ) );
		if ( $r['guardados'] ) {
			Notices::add( 'success', sprintf( /* translators: %s: text */ __( 'Saved in the CRM: %s.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $lista( $r['guardados'] ) ) );
		}
		if ( $r['propuestos'] ) {
			Notices::add( 'success', sprintf( /* translators: %s: text */ __( 'Changes sent for approval: %s. We will notify you on this record when they are reviewed.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $lista( $r['propuestos'] ) ) );
		}
		if ( $r['rechazados'] ) {
			Notices::add( 'error', sprintf( /* translators: %s: text */ __( 'The CRM did not accept: %s. The CRM user may not have permission on those fields.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $lista( $r['rechazados'] ) ) );
		}
		if ( $r['conflictos'] ) {
			Notices::add( 'warning', sprintf( /* translators: %s: text */ __( '%s has not been changed because someone modified it in the CRM while you were editing. Check the current value and try again.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $lista( $r['conflictos'] ) ) );
		}
		foreach ( $r['errores'] as $e ) {
			Notices::add( 'error', esc_html( $e ) );
		}
		if ( ! array_filter( $r ) ) {
			Notices::add( 'info', __( 'There was no change to save.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
		}
		$this->redirect( $volver );
	}

	/**
	 * Formulario de la membresía en el CRM. Lee del CRM lo que hace falta enseñar: las
	 * membresías activas (baja y cambio de sección) o si ya tiene documento.
	 *
	 * @throws CrmException
	 */
	private function membresia( Persona $p, string $accion ): string {
		$altas = Altas::disponible() ? Altas::make() : null;
		$vars  = array(
			'p'         => $p,
			'accion'    => $accion,
			'altas'     => null !== $altas,
			'motivo'    => Altas::activo() ? __( 'Your group\'s CRM user does not have permission to create people and memberships. An administrator can review it in wp-admin → Scout Groups.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) : __( 'Registrations in the CRM are not enabled for your group.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
			'estado'    => self::$membresia ?? array(
				'error'     => '',
				'valores'   => array(),
				'confirmar' => false,
				'otros'     => 0,
			),
			'activas'   => array(),
			'documento' => null,
			'cuenta'    => '',
			'familias'  => array(),
			'familia'   => '',
			'url'       => $this->url(
				'ficha',
				array(
					'socio'     => $p->num_socio_asde,
					'membresia' => $accion,
				)
			),
			'url_ficha' => $this->url( 'ficha', array( 'socio' => $p->num_socio_asde ) ),
		);
		if ( ! $altas ) {
			return $this->tpl( 'fichas/membresia', $vars );
		}
		if ( in_array( $accion, array( 'baja', 'seccion' ), true ) ) {
			$vars['activas'] = $altas->activas( $p->id );
		}
		if ( 'baja' === $accion ) {
			// Lo que cambia con la baja: las cuentas de acceso y la cuota de su familia.
			$vars['cuenta']   = Usuarios::por_socio( $p->num_socio_asde )?->user_login ?? '';
			$vars['familias'] = array_map( static fn( \WP_User $u ) => $u->user_login, Usuarios::tutores_de( $p->num_socio_asde ) );
			$vars['familia']  = self::familia( $p );
		}
		if ( 'documento' === $accion ) {
			$vars['documento'] = $altas->tiene_documento( $p->id );
		}
		return $this->tpl( 'fichas/membresia', $vars );
	}

	/** Nº de la familia de Tesorería en la que está la persona ('' si no está en ninguna). */
	private static function familia( Persona $p ): string {
		$id = FamiliasTable::mapa_socios()[ $p->num_socio_asde ] ?? 0;
		$f  = $id ? FamiliasTable::obtener( $id ) : null;
		return $f ? (string) $f['numero'] : '';
	}

	/** Una acción sobre la membresía: si sale bien, vuelve a la ficha con el aviso; si no, se vuelve a pintar el formulario. */
	private function membresia_post( string $accion, string $socio ): void {
		if ( ! current_user_can( 'asde_manage_users' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), 403 );
		}
		if ( ! PersonaRepository::es_num_asde( $socio ) ) {
			Notices::add( 'error', __( 'Invalid request.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
			$this->redirect( $this->url( 'personas' ) );
		}
		check_admin_referer( 'asde_membresia_' . $socio );
		$valores = array(
			'seccion'   => sanitize_key( $this->post( 'seccion' ) ),
			'fecha'     => $this->post( 'fecha' ),
			'motivo'    => sanitize_key( $this->post( 'motivo' ) ),
			'documento' => sanitize_text_field( $this->post( 'documento' ) ),
		);
		$fallo   = static function ( string $error, string $codigo = '', $datos = null ) use ( $valores ): void {
			self::$membresia = array(
				'error'     => $error,
				'valores'   => $valores,
				'confirmar' => 'asde_documento_confirmar' === $codigo,
				'otros'     => is_array( $datos ) && array_key_exists( 'otros', $datos ) ? $datos['otros'] : 0,
			);
		};
		$repo  = PersonaRepository::make();
		$altas = Altas::disponible() ? Altas::make() : null;
		if ( ! $repo || ! $altas ) {
			$fallo( Altas::activo() ? __( 'The connection to the CRM is not available.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) : __( 'Registrations in the CRM are not enabled for your group.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
			return;
		}
		try {
			$p = $repo->obtener( $socio );
		} catch ( CrmException $e ) {
			$fallo( __( 'The CRM could not be queried: ', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) . $e->getMessage() );
			return;
		}
		if ( ! $p ) {
			Notices::add( 'error', __( 'That record cannot be found in the group\'s CRM.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
			$this->redirect( $this->url( 'personas' ) );
		}
		$nombre = esc_html( $p->nombre_completo() );
		$fecha  = esc_html( self::dma( '' !== $valores['fecha'] ? $valores['fecha'] : wp_date( 'Y-m-d' ) ) );
		switch ( $accion ) {
			case 'membresia_nueva':
				$r = $altas->nueva_membresia( $p, $valores['seccion'], $valores['fecha'] );
				/* translators: 1: name, 2: section, 3: date */
				$ok = sprintf( __( 'New membership in the CRM: %1$s, in %2$s from %3$s.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $nombre, esc_html( Secciones::nombre( $valores['seccion'] ) ), $fecha );
				break;
			case 'membresia_baja':
				$familia = self::familia( $p );
				$cuenta  = null !== Usuarios::por_socio( $p->num_socio_asde );
				$tutores = (bool) Usuarios::tutores_de( $p->num_socio_asde );
				$r       = $altas->baja( $p, $valores['fecha'], $valores['motivo'] );
				/* translators: 1: name, 2: date */
				$ok = sprintf( __( '%1$s has been withdrawn in the CRM as of %2$s.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $nombre, $fecha );
				if ( $cuenta ) {
					$ok .= ' ' . __( 'Their website sign-in account can no longer sign in.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' );
				}
				if ( $tutores ) {
					$ok .= ' ' . __( 'The family accounts that represent them no longer see them; if they do not represent anyone else with an active membership, they cannot sign in either.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' );
				}
				if ( '' !== $familia ) {
					/* translators: %s: family number */
					$ok .= ' ' . sprintf( __( 'They stop counting in the fee of family no. %s: Treasury will see it in "Recent withdrawals".', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), esc_html( $familia ) );
				}
				if ( is_array( $r ) ) {
					// P-90: aviso por email a Tesorería y a la familia (nunca bloquea la baja ya hecha).
					$av  = AvisoBaja::enviar( $p, '' !== $valores['fecha'] ? $valores['fecha'] : wp_date( 'Y-m-d' ), $valores['motivo'], $familia );
					/* translators: 1: emails to Treasury, 2: emails to the family */
					$ok .= ' ' . sprintf( __( 'Email notices: %1$d to Treasury and %2$d to the family.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $av['tesoreria'], $av['familia'] );
					if ( $av['familia_omitida'] ) {
						$ok .= ' ' . __( 'Because of the reason for the withdrawal, the family has not been written to.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' );
					} elseif ( $av['sin_email_familia'] ) {
						$ok .= ' ' . __( 'There is no family email address to notify.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' );
					}
					if ( $av['errores'] ) {
						/* translators: %d: emails that were not sent */
						$ok .= ' ' . sprintf( __( '%d could not be sent (see Users → Emails).', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $av['errores'] );
					}
				}
				break;
			case 'membresia_seccion':
				$r = $altas->cambio_seccion( $p, $valores['seccion'], $valores['fecha'] );
				/* translators: 1: name, 2: new section, 3: date */
				$ok = sprintf( __( '%1$s moves to %2$s as of %3$s. The previous membership is closed with the reason "change of section".', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $nombre, esc_html( Secciones::nombre( $valores['seccion'] ) ), $fecha );
				break;
			default: // poner_documento
				$r = $altas->poner_documento( $p, $valores['documento'], '1' === $this->post( 'confirmo' ) );
				/* translators: %s: name */
				$ok = sprintf( __( 'Document saved on %s\'s record.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $nombre );
				if ( is_array( $r ) && ( null === $r['otros'] || $r['otros'] > 0 ) ) {
					$ok .= ' ' . __( 'That document is also registered in another Scouts de España group or organisation.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' );
				}
		}
		if ( is_wp_error( $r ) ) {
			$fallo( $r->get_error_message(), (string) $r->get_error_code(), $r->get_error_data() );
			return;
		}
		Notices::add( 'success', $ok );
		$this->redirect( $this->url( 'ficha', array( 'socio' => $socio ) ) );
	}

	/** «Eliminar de la web» (v0.35.1): solo Secretaría y Coordinación, y a quien no tiene membresía activa. */
	private function eliminar_post( string $socio ): void {
		if ( ! current_user_can( 'asde_manage_users' ) || ! PersonaRepository::es_num_asde( $socio ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), 403 );
		}
		check_admin_referer( 'asde_eliminar_' . $socio );
		$repo = PersonaRepository::make();
		try {
			$p = $repo ? $repo->obtener( $socio ) : null;
		} catch ( CrmException $e ) {
			Notices::add( 'error', esc_html( __( 'The CRM could not be queried: ', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) . $e->getMessage() ) );
			$this->redirect( $this->url( 'ficha', array( 'socio' => $socio ) ) );
		}
		if ( ! $p ) {
			Notices::add( 'error', __( 'That record cannot be found in the group\'s CRM.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
			$this->redirect( $this->url( 'personas' ) );
		}
		$r = \AsdeBalears\AreaPrivada\Socios\Eliminadas::eliminar( $p, get_current_user_id() );
		if ( is_wp_error( $r ) ) {
			Notices::add( 'error', esc_html( $r->get_error_message() ) );
			$this->redirect( $this->url( 'ficha', array( 'socio' => $socio ) ) );
		}
		/* translators: %s: name */
		$ok = sprintf( __( '%s no longer appears on the website. Nothing has changed in the CRM.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), esc_html( $p->nombre_completo() ) );
		if ( '' !== $r['familia'] ) {
			/* translators: %s: family number */
			$ok .= ' ' . sprintf( __( 'Has left family no. %s.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), esc_html( $r['familia'] ) );
		}
		if ( $r['cuenta'] ) {
			$ok .= ' ' . __( 'Their sign-in account has been deactivated.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' );
		}
		if ( $r['familiares'] ) {
			/* translators: 1: family accounts, 2: of which, deactivated */
			$ok .= ' ' . sprintf( __( 'No longer in %1$d family account(s); %2$d were deactivated because they do not represent anyone else.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $r['familiares'], $r['desactivadas'] );
		}
		Notices::add( 'success', $ok );
		$this->redirect( $this->url( 'personas', array( 'ver' => 'inactivas' ) ) );
	}

	private static function dma( string $fecha ): string {
		$f = \DateTime::createFromFormat( '!Y-m-d', $fecha );
		return $f ? $f->format( 'd/m/Y' ) : $fecha;
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
