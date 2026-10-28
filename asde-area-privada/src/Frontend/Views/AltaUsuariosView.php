<?php
namespace AsdeBalears\AreaPrivada\Frontend\Views;

use AsdeBalears\AreaPrivada\Auth\Ambito;
use AsdeBalears\AreaPrivada\Crm\CrmException;
use AsdeBalears\AreaPrivada\Crm\PersonaRepository;
use AsdeBalears\AreaPrivada\Domain\NumeroSocio;
use AsdeBalears\AreaPrivada\Domain\Persona;
use AsdeBalears\AreaPrivada\Frontend\Notices;
use AsdeBalears\AreaPrivada\Install\Roles;
use AsdeBalears\AreaPrivada\Socios\Numeracion;
use AsdeBalears\AreaPrivada\Usuarios\AltaResult;
use AsdeBalears\AreaPrivada\Usuarios\AltaService;
use AsdeBalears\AreaPrivada\Usuarios\EmailCuenta;
use AsdeBalears\AreaPrivada\Usuarios\Invitacion;
use AsdeBalears\AreaPrivada\Usuarios\RegistroEmails;
use AsdeBalears\AreaPrivada\Usuarios\SolicitudService;
use AsdeBalears\AreaPrivada\Usuarios\Solicitudes;
use AsdeBalears\AreaPrivada\Usuarios\Usuarios;

defined( 'ABSPATH' ) || exit;

/**
 * Alta de usuarios: buscar un socio activo en el CRM → ver su ficha → darle
 * acceso a él/ella o a su familia (cuenta familiar "nº + T").
 */
final class AltaUsuariosView extends View {

	private const CAP = 'asde_manage_users';

	public function render( string $content = '' ): string {
		if ( ! current_user_can( self::CAP ) ) {
			return $this->denegado();
		}
		$alta = AltaService::make();
		if ( ! $alta ) {
			return $this->error_crm( 'alta', __( 'Your group\'s connection to the CRM is not set up or could not be checked. An administrator can review it in wp-admin → Scout Groups.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
		}

		try {
			$socio = $this->get( 'socio' );
			$html  = PersonaRepository::es_num_asde( $socio )
				? $this->detalle( $alta, $socio )
				: $this->buscar( $alta, $this->get( 'q' ) );
		} catch ( CrmException $e ) {
			return $this->error_crm( 'alta', __( 'The CRM could not be queried: ', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) . $e->getMessage() );
		}

		return $this->layout( 'alta', $this->libro_usuarios( 'alta', $html ) );
	}

	private function repo(): PersonaRepository {
		return PersonaRepository::make();
	}

	private function buscar( AltaService $alta, string $q ): string {
		$resultados = mb_strlen( $q ) >= 3 ? $this->repo()->buscar( $q ) : array(); // Solo socios con membresía activa.
		$analisis   = $resultados ? $alta->numeracion()->analizar( $resultados, false ) : array();
		return $this->tpl(
			'alta/buscar',
			array(
				'q'          => $q,
				'resultados' => $resultados,
				'numeros'    => $analisis,
				'usuarios'   => Usuarios::por_socios( array_map( static fn( Persona $p ) => $p->num_socio_asde, $resultados ) ),
				'url'        => $this->url( 'alta' ),
			)
		);
	}

	private function detalle( AltaService $alta, string $socio ): string {
		$repo = $this->repo();
		$p    = $repo->obtener( $socio );
		if ( ! $p || ! $p->tiene_membresia_activa() ) {
			// Se está pintando la página (cabeceras ya enviadas): aviso en línea, sin redirigir.
			return $this->tpl( 'comun/avisos', array( 'avisos' => array( array( 'error', __( 'There is no member with an active membership with that number in the group\'s CRM.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) ) ) )
				. $this->buscar( $alta, '' );
		}

		$numero     = $alta->numeracion()->de( $p );
		$motivo_rep = $alta->motivo_no_representable( $p );

		// Posibles hermanos/as: otros participantes que comparten algún email con esta ficha.
		$familia = array();
		if ( null === $motivo_rep && $p->emails() ) {
			foreach ( $repo->por_emails( $p->emails() ) as $otro ) {
				if ( $otro->num_socio_asde !== $p->num_socio_asde && null === $alta->motivo_no_representable( $otro ) ) {
					$familia[] = $otro;
				}
			}
		}

		// Cuenta familiar existente (de este menor o de un hermano) o la que se crearía.
		$cuentas = Usuarios::cuentas_familiares( array( $p->num_socio_asde ) )
			?: Usuarios::cuentas_familiares( array_map( static fn( Persona $f ) => $f->num_socio_asde, $familia ) );
		$cuenta  = $cuentas[0] ?? null;
		$base_usuario     = \AsdeBalears\AreaPrivada\Domain\Identidad::usuario_de( $p, $numero['numero'] );
		$usuario_familiar = $cuenta ? $cuenta->user_login : ( $base_usuario ? NumeroSocio::usuario_tutor( (string) $base_usuario ) : '' );

		// Emails de la ficha que aún no son de ninguna de las cuentas familiares: para la cuenta del otro tutor/a.
		$en_uso     = array_filter( array_map( static fn( \WP_User $c ) => EmailCuenta::leer( $c->ID ), $cuentas ) );
		$emails_otro = array_diff( $alta->emails_familia( $p ), $en_uso );

		return $this->tpl(
			'alta/detalle',
			array(
				'p'                => $p,
				'numero'           => $numero,
				'eleg'             => $alta->elegibilidad( $p ),
				'motivo_rep'       => $motivo_rep,
				'vinculado'        => Usuarios::por_socio( $p->num_socio_asde ),
				'cuenta_familiar'  => $cuenta,
				'cuentas_familiares' => $cuentas,
				'emails_otro'      => $emails_otro,
				'usuario_familiar' => $usuario_familiar,
				'emails_persona'   => $alta->emails_persona( $p ),
				'emails_familia'   => $alta->emails_familia( $p ),
				'email_compartido' => $p->email ? $repo->contar_email( $p->email ) : 0,
				'familia'          => $familia,
				'edad_minima'      => AltaService::EDAD_MINIMA_PARTICIPANTE,
				'url_volver'       => $this->url( 'alta' ),
				'url_usuarios'     => $this->url( 'usuarios' ),
				'url_personas'     => $this->url( 'personas' ),
				'solicitud'        => $this->solicitud( (int) $this->get( 'solicitud' ), $p->num_socio_asde ),
			)
		);
	}

	/** Solicitud de acceso pendiente de esta persona que el usuario puede revisar, o null. */
	private function solicitud( int $id, string $socio ): ?array {
		$s = $id ? Solicitudes::obtener( $id ) : null;
		return $s && Solicitudes::PENDIENTE === $s['estado'] && $s['socio'] === $socio && Solicitudes::en_ambito( $s, Ambito::actual() ) && is_email( $s['datos']['email'] ) ? $s : null;
	}

	public function handle_post(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), 403 );
		}
		$alta  = AltaService::make();
		$socio = $this->post( 'socio' );
		$ok    = PersonaRepository::es_num_asde( $socio );
		$volver = $ok ? $this->url( 'alta', array( 'socio' => $socio ) ) : $this->url( 'alta' );
		if ( ! $alta || ! $ok ) {
			Notices::add( 'error', __( 'Invalid request.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
			$this->redirect( $volver );
		}

		try {
			switch ( $this->post( 'asde_action' ) ) {
				case 'alta_persona':
					check_admin_referer( 'asde_alta_persona_' . $socio );
					$vinculado = Usuarios::por_socio( $socio );
					$s         = ( $vinculado || 'solicitud' === $this->post( 'campo_email' ) ) ? $this->solicitud( (int) $this->post( 'solicitud' ), $socio ) : null;
					if ( $s && $vinculado ) {
						// Ya tiene cuenta (v0.12.3): se cambian sus roles y secciones, su email no se toca, y la solicitud queda resuelta.
						$result = $alta->alta_persona( $socio, $this->post_array( 'roles' ), $this->post_array( 'secciones' ) );
						if ( $result->ok ) {
							$this->resolver_solicitud( $s, $result->usuario->ID );
						}
					} elseif ( $s ) {
						// Desde una solicitud de acceso: su email (al CRM si no estaba en la ficha) y la bienvenida.
						$servicio = new SolicitudService( $this->repo() );
						$p        = $this->repo()->obtener( $socio );
						$crm      = $p ? $servicio->guardar_email_en_crm( $p, 'email1', $s['datos']['email'] ) : array( 'aviso' => '' );
						if ( '' !== $crm['aviso'] ) {
							Notices::add( 'warning', esc_html( $crm['aviso'] ) );
						}
						$result = $alta->con_bienvenida()->alta_persona( $socio, $this->post_array( 'roles' ), $this->post_array( 'secciones' ), 'email1', $s['datos']['email'] );
						if ( $result->ok ) {
							$this->resolver_solicitud( $s, $result->usuario->ID );
						}
					} else {
						$result = $alta->alta_persona( $socio, $this->post_array( 'roles' ), $this->post_array( 'secciones' ), sanitize_key( $this->post( 'campo_email' ) ?: 'email1' ) );
					}
					break;

				case 'cerrar_solicitud':
					// Quien pide el acceso ya lo tiene (v0.12.3): no hay nada que dar, solo cerrar la solicitud.
					check_admin_referer( 'asde_cerrar_solicitud_' . $socio );
					$s     = $this->solicitud( (int) $this->post( 'solicitud' ), $socio );
					$cuenta = Usuarios::por_socio( $socio );
					if ( $s && $cuenta ) {
						$this->resolver_solicitud( $s, $cuenta->ID );
						Notices::add( 'success', sprintf( /* translators: %s: text */ __( 'Request resolved: user <strong>%s</strong> already had access.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), esc_html( $cuenta->user_login ) ) );
					} else {
						Notices::add( 'error', __( 'That request is no longer pending or the person has no account.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
					}
					$this->redirect( $this->url( 'solicitudes' ) );
					break;

				case 'alta_tutor':
					check_admin_referer( 'asde_alta_tutor_' . $socio );
					$result = $alta->alta_tutor( $socio, $this->post_array( 'hermanos' ), sanitize_key( $this->post( 'campo_email' ) ), null, '1' === $this->post( 'otro_tutor' ) );
					break;

				default:
					$result = AltaResult::error( __( 'Unknown action.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
			}
		} catch ( CrmException $e ) {
			$result = AltaResult::error( __( 'The CRM could not be queried: ', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) . $e->getMessage() );
		}

		$this->avisar( $result );
		$this->redirect( $volver );
	}

	/** Marca como aprobada una solicitud de acceso (con la cuenta que quedó) y lo anota en la auditoría. */
	private function resolver_solicitud( array $s, int $user_id ): void {
		Solicitudes::resolver( (int) $s['id'], Solicitudes::APROBADA, get_current_user_id(), '', $user_id );
		\AsdeBalears\AreaPrivada\Audit\Logger::log( 'solicitud_aprobada', 'solicitud', (string) $s['id'], array( 'tipo' => $s['tipo'], 'usuario' => $user_id ) );
	}

	private function avisar( AltaResult $r ): void {
		if ( ! $r->ok ) {
			foreach ( $r->errores as $error ) {
				Notices::add( 'error', esc_html( $error ) );
			}
			return;
		}

		$u     = $r->usuario;
		$roles = implode( ', ', array_map( array( Roles::class, 'label' ), Usuarios::roles_asde( $u ) ) );
		$quien = sprintf( 'usuario <strong>%s</strong>', esc_html( $u->user_login ) );

		$mensaje = AltaResult::CREADO === $r->accion
			? sprintf( /* translators: 1: text, 2: text */ __( 'Access created: %1$s. Roles: %2$s.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $quien, esc_html( $roles ) )
			: sprintf( /* translators: 1: text, 2: text */ __( 'Access updated: %1$s. Roles: %2$s.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $quien, esc_html( $roles ) );

		$email = RegistroEmails::ultimos( array( $u->ID ) )[ $u->ID ] ?? null;
		$ver   = $email ? sprintf( ' ' . /* translators: %s: text */ __( '<a href="%s">View the email</a>.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), esc_url( add_query_arg( 'ver', (int) $email['id'], $this->url( 'emails' ) ) ) ) : '';
		if ( $r->invitacion_enviada ) {
			$mensaje .= sprintf( /* translators: 1: text, 2: number */ __( ' The invitation has been sent to %1$s, the email in the CRM (valid %2$d days).', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), esc_html( $email['destino'] ?? '' ), Invitacion::DIAS ) . $ver;
		} elseif ( Usuarios::ACTIVO === Usuarios::estado( $u ) ) {
			$mensaje .= __( ' Their account was already active, so no invitation is sent.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' );
		} elseif ( AltaResult::CREADO === $r->accion ) {
			Notices::add( 'error', sprintf( /* translators: %s: text */ __( 'The invitation email could not be sent: %s. You can resend it from the user list.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), esc_html( $email['error'] ?? __( 'unknown reason', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) ) . $ver );
		}

		// Número generado ahora: recordatorio para pasarlo al CRM.
		$generados = \AsdeBalears\AreaPrivada\Socios\SociosTable::generados_pendientes();
		$numero_usuario = NumeroSocio::socio_de_usuario_tutor( $u->user_login ) ?? $u->user_login;
		if ( in_array( $numero_usuario, $generados, true ) ) {
			$mensaje .= sprintf( /* translators: %s: text */ __( ' <br>📌 Member no. <strong>%s</strong> was generated automatically: note it in the CRM ("Local/territorial member no." field).', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), esc_html( $numero_usuario ) );
		}
		Notices::add( 'success', $mensaje );
	}
}
