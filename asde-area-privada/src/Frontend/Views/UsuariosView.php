<?php
namespace AsdeBalears\AreaPrivada\Frontend\Views;

use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Frontend\Notices;
use AsdeBalears\AreaPrivada\Frontend\Router;
use AsdeBalears\AreaPrivada\Frontend\Tabla;
use AsdeBalears\AreaPrivada\Install\Roles;
use AsdeBalears\AreaPrivada\Usuarios\Invitacion;
use AsdeBalears\AreaPrivada\Usuarios\Listado;
use AsdeBalears\AreaPrivada\Usuarios\RegistroEmails;
use AsdeBalears\AreaPrivada\Usuarios\Usuarios;

defined( 'ABSPATH' ) || exit;

/**
 * Listado de usuarios del área privada: estado, reenviar invitación, desactivar.
 *
 * Desde la v0.12.2 la página se carga con todos los usuarios y el navegador
 * filtra por columnas, ordena y pagina en memoria (assets/js/usuarios.js), sin
 * recargar. Las acciones de cada fila (reenviar, desactivar) y el refresco de la
 * lista también van sin recargar: el servidor contesta en JSON (cabecera
 * X-Asde-Json). Sin JavaScript todo sigue funcionando con la página completa.
 * Los nombres se leen del CRM (WordPress solo guarda nº de socio) y se precachean
 * en Usuarios\Listado.
 */
final class UsuariosView extends View {

	private const CAP = 'asde_manage_users';

	/** Nonce del botón «Actualizar» (relee las fichas del CRM: no se hace con un enlace sin firmar). */
	private const NONCE_REFRESCAR = 'asde_refrescar_usuarios';

	public function render( string $content = '' ): string {
		if ( ! current_user_can( self::CAP ) ) {
			return $this->denegado();
		}

		$rol = $this->get( 'rol' );
		$d   = $this->datos( $this->pide_refrescar() );

		return $this->layout(
			'usuarios',
			$this->libro_usuarios(
				'usuarios',
				$this->tpl(
					'usuarios/listado',
					array(
						'total'           => count( $d['users'] ),
						'filas'           => $d['filas'],
						'aviso'           => $d['aviso'],
						'rol'             => in_array( $rol, Roles::ALL, true ) ? $rol : '',
						'por_pagina'      => Tabla::por_pagina_guardada(),
						'url'             => $this->url( 'usuarios' ),
						'url_alta'        => $this->url( 'alta' ),
						'ajax'            => admin_url( 'admin-ajax.php' ),
						'nonce_refrescar' => wp_create_nonce( self::NONCE_REFRESCAR ),
						'nonce_pagina'    => wp_create_nonce( 'asde_ap_por_pagina' ),
					)
				)
			)
		);
	}

	/** ¿Se ha pedido (con el nonce del botón) volver a leer las fichas del CRM? */
	private function pide_refrescar(): bool {
		return '1' === $this->get( 'refrescar' ) && (bool) wp_verify_nonce( $this->get( '_wpnonce' ), self::NONCE_REFRESCAR );
	}

	/**
	 * Los datos del listado, leídos una vez: usuarios, nombres (caché del CRM),
	 * último email de cada uno y el HTML de las filas.
	 *
	 * @return array{users: \WP_User[], filas: string, aviso: string}
	 */
	private function datos( bool $refrescar = false ): array {
		$users                = Listado::usuarios();
		list( $personas, $aviso ) = Listado::personas( Listado::numeros( $users ), $refrescar );
		$emails               = RegistroEmails::ultimos( wp_list_pluck( $users, 'ID' ) );
		$filas                = '';
		foreach ( $users as $u ) {
			$filas .= $this->fila( $u, $personas, $emails[ $u->ID ] ?? null );
		}
		return array(
			'users' => $users,
			'filas' => $filas,
			'aviso' => $aviso,
		);
	}

	private function fila( \WP_User $u, array $personas, ?array $email ): string {
		return $this->tpl(
			'usuarios/fila',
			array(
				'u'          => $u,
				'personas'   => $personas,
				'e'          => $email,
				'url_alta'   => $this->url( 'alta' ),
				'url_emails' => $this->url( 'emails' ),
			)
		);
	}

	/** Toda la lista otra vez, para actualizarla en el navegador sin recargar la página. */
	public function json(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( array( 'mensaje' => asde_t( 'No tienes permiso para ver esto.' ) ), 403 );
		}
		$d = $this->datos( $this->pide_refrescar() );
		wp_send_json_success(
			array(
				'total'  => count( $d['users'] ),
				'filas'  => $d['filas'],
				'aviso'  => $d['aviso'],
				'avisos' => Notices::render(),
			)
		);
	}

	/**
	 * Fin de una acción: con JavaScript se devuelve la fila actualizada (y los avisos);
	 * sin él, se vuelve a la página como siempre.
	 */
	private function terminar( string $volver, int $user_id = 0 ): void {
		if ( ! Router::pide_json() ) {
			$this->redirect( $volver );
		}
		$fila = '';
		$user = $user_id ? get_userdata( $user_id ) : false;
		if ( $user && ( Usuarios::roles_asde( $user ) || Usuarios::DESACTIVADO === Usuarios::estado( $user ) ) ) {
			list( $personas ) = Listado::personas( Listado::numeros( array( $user ) ) );
			$fila             = $this->fila( $user, $personas, RegistroEmails::ultimos( array( $user_id ) )[ $user_id ] ?? null );
		}
		wp_send_json_success(
			array(
				'fila'   => $fila,
				'avisos' => Notices::render(),
			)
		);
	}

	public function handle_post(): void {
		if ( ! current_user_can( self::CAP ) ) {
			if ( Router::pide_json() ) {
				wp_send_json_error( array( 'mensaje' => asde_t( 'No tienes permiso para hacer esto.' ) ), 403 );
			}
			wp_die( esc_html__( 'No tienes permiso para hacer esto.', 'asde-area-privada' ), 403 );
		}
		$user_id = (int) $this->post( 'user_id' );
		$user    = get_userdata( $user_id );
		$volver  = $this->url( 'usuarios' );

		if ( ! $user || ( ! Usuarios::roles_asde( $user ) && Usuarios::DESACTIVADO !== Usuarios::estado( $user ) ) ) {
			Notices::add( 'error', asde_t( 'Usuario no válido.' ) );
			$this->terminar( $volver );
		}

		switch ( $this->post( 'asde_action' ) ) {
			case 'reenviar':
				check_admin_referer( 'asde_reenviar_' . $user_id );
				if ( Usuarios::INVITADO !== Usuarios::estado( $user ) ) {
					Notices::add( 'error', asde_t( 'Ese usuario no tiene una invitación pendiente.' ) );
					break;
				}
				$ok    = Invitacion::enviar( $user );
				$email = RegistroEmails::ultimos( array( $user_id ) )[ $user_id ] ?? null;
				$ver   = $email ? sprintf( ' ' . asde_h( '<a href="%s">Ver el email</a>.' ), esc_url( add_query_arg( 'ver', (int) $email['id'], $this->url( 'emails' ) ) ) ) : '';
				if ( $ok ) {
					Logger::log( 'invitacion_reenviada', 'user', (string) $user_id );
					Notices::add( 'success', sprintf( asde_t( 'Invitación reenviada al usuario %s: enviada a %s, el email del CRM.' ), esc_html( $user->user_login ), esc_html( $email['destino'] ?? '' ) ) . $ver );
				} else {
					Notices::add( 'error', asde_t( 'No se ha podido enviar el email: ' ) . esc_html( $email['error'] ?? asde_t( 'motivo desconocido' ) ) . $ver );
				}
				break;

			case 'desactivar':
				check_admin_referer( 'asde_desactivar_' . $user_id );
				if ( $user->has_cap( 'manage_options' ) || get_current_user_id() === $user_id ) {
					Notices::add( 'error', asde_t( 'No se puede desactivar a un administrador ni a ti mismo.' ) );
					break;
				}
				// v0.26.1: también se borran su contraseña y su verificación en dos pasos (Usuarios::desactivar).
				$quitado = Usuarios::desactivar( $user );
				Logger::log(
					'usuario_desactivado',
					'user',
					(string) $user_id,
					array(
						'roles'              => $quitado['roles'],
						'contrasena_borrada' => true,
						'dos_pasos'          => $quitado['dos_pasos'],
						'llaves'             => $quitado['llaves'],
					)
				);
				Notices::add( 'success', sprintf( asde_t( 'Acceso desactivado para el usuario %s: se han borrado su contraseña y su verificación en dos pasos. Para devolvérselo, dale de alta de nuevo: le llegará una invitación para elegir otra contraseña.' ), esc_html( $user->user_login ) ) );
				break;

			default:
				Notices::add( 'error', asde_t( 'Acción desconocida.' ) );
		}

		$this->terminar( $volver, $user_id );
	}
}
