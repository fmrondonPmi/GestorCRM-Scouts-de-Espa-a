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
			return $this->error_crm( 'alta', asde_t( 'La conexión de tu grupo con el CRM no está configurada o no se ha podido comprobar. Un administrador puede revisarla en wp-admin → Grupos Scouts.' ) );
		}

		try {
			$socio = $this->get( 'socio' );
			$html  = PersonaRepository::es_num_asde( $socio )
				? $this->detalle( $alta, $socio )
				: $this->buscar( $alta, $this->get( 'q' ) );
		} catch ( CrmException $e ) {
			return $this->error_crm( 'alta', asde_t( 'No se ha podido consultar el CRM: ' ) . $e->getMessage() );
		}

		return $this->layout( 'alta', $html );
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
			return $this->tpl( 'comun/avisos', array( 'avisos' => array( array( 'error', asde_t( 'No hay ningún socio con membresía activa con ese número en el CRM del grupo.' ) ) ) ) )
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
			wp_die( esc_html__( 'No tienes permiso para hacer esto.', 'asde-area-privada' ), 403 );
		}
		$alta  = AltaService::make();
		$socio = $this->post( 'socio' );
		$ok    = PersonaRepository::es_num_asde( $socio );
		$volver = $ok ? $this->url( 'alta', array( 'socio' => $socio ) ) : $this->url( 'alta' );
		if ( ! $alta || ! $ok ) {
			Notices::add( 'error', asde_t( 'Petición no válida.' ) );
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
						Notices::add( 'success', sprintf( asde_t( 'Solicitud resuelta: el usuario <strong>%s</strong> ya tenía acceso.' ), esc_html( $cuenta->user_login ) ) );
					} else {
						Notices::add( 'error', asde_t( 'Esa solicitud ya no está pendiente o la persona no tiene cuenta.' ) );
					}
					$this->redirect( $this->url( 'solicitudes' ) );
					break;

				case 'alta_tutor':
					check_admin_referer( 'asde_alta_tutor_' . $socio );
					$result = $alta->alta_tutor( $socio, $this->post_array( 'hermanos' ), sanitize_key( $this->post( 'campo_email' ) ), null, '1' === $this->post( 'otro_tutor' ) );
					break;

				default:
					$result = AltaResult::error( asde_t( 'Acción desconocida.' ) );
			}
		} catch ( CrmException $e ) {
			$result = AltaResult::error( asde_t( 'No se ha podido consultar el CRM: ' ) . $e->getMessage() );
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
			? sprintf( asde_t( 'Acceso creado: %s. Roles: %s.' ), $quien, esc_html( $roles ) )
			: sprintf( asde_t( 'Acceso actualizado: %s. Roles: %s.' ), $quien, esc_html( $roles ) );

		$email = RegistroEmails::ultimos( array( $u->ID ) )[ $u->ID ] ?? null;
		$ver   = $email ? sprintf( ' ' . asde_h( '<a href="%s">Ver el email</a>.' ), esc_url( add_query_arg( 'ver', (int) $email['id'], $this->url( 'emails' ) ) ) ) : '';
		if ( $r->invitacion_enviada ) {
			$mensaje .= sprintf( asde_t( ' Se ha enviado la invitación a %s, el email del CRM (válida %d días).' ), esc_html( $email['destino'] ?? '' ), Invitacion::DIAS ) . $ver;
		} elseif ( Usuarios::ACTIVO === Usuarios::estado( $u ) ) {
			$mensaje .= asde_t( ' Su cuenta ya estaba activa, así que no se envía invitación.' );
		} elseif ( AltaResult::CREADO === $r->accion ) {
			Notices::add( 'error', sprintf( asde_t( 'No se ha podido enviar el email de invitación: %s. Puedes reenviarlo desde el listado de usuarios.' ), esc_html( $email['error'] ?? asde_t( 'motivo desconocido' ) ) ) . $ver );
		}

		// Número generado ahora: recordatorio para pasarlo al CRM.
		$generados = \AsdeBalears\AreaPrivada\Socios\SociosTable::generados_pendientes();
		$numero_usuario = NumeroSocio::socio_de_usuario_tutor( $u->user_login ) ?? $u->user_login;
		if ( in_array( $numero_usuario, $generados, true ) ) {
			$mensaje .= sprintf( asde_t( ' <br>📌 El nº de socio/a <strong>%s</strong> se ha generado automáticamente: anótalo en el CRM (campo «Nº de socio/a local/territorial»).' ), esc_html( $numero_usuario ) );
		}
		Notices::add( 'success', $mensaje );
	}
}
