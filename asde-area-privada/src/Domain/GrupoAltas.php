<?php
namespace AsdeBalears\AreaPrivada\Domain;

use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Crm\Crypto;
use AsdeBalears\AreaPrivada\Frontend\TemplateLoader;
use AsdeBalears\AreaPrivada\Idiomas\Idioma;
use AsdeBalears\AreaPrivada\Usuarios\Contacto;
use AsdeBalears\AreaPrivada\Usuarios\RegistroEmails;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- tabla propia del plugin ({prefix}asde_*): no hay función de WordPress para ella.

/**
 * Revisión de las altas de grupo (v0.50, doc 50 fase 6; solo en la web de la oficina): aprobar crea el grupo, rechazar
 * borra lo que se había guardado. En ninguno de los dos casos sale la credencial por pantalla ni por email.
 */
final class GrupoAltas {

	/**
	 * Aprueba una solicitud: crea el grupo con la credencial ya cifrada y la comprueba otra vez. Si falla, no se aprueba.
	 *
	 * @return array|\WP_Error El grupo creado.
	 */
	public static function aprobar( int $id ) {
		global $wpdb;
		$s = GrupoSolicitudes::obtener( $id );
		if ( ! $s || GrupoSolicitudes::PENDIENTE !== $s['estado'] ) {
			return new \WP_Error( 'asde_alta', asde_t( 'Esa solicitud ya no está pendiente.' ) );
		}
		$usuario = (string) Crypto::decrypt( (string) $s['usuario_enc'] );
		if ( '' === $usuario || null === Crypto::decrypt( (string) $s['clave_enc'] ) ) {
			return new \WP_Error( 'asde_alta', asde_t( 'No se puede descifrar la credencial guardada. Pide al grupo que repita el alta con una invitación nueva.' ) );
		}
		$grupo = Grupos::alta_desde_solicitud( $s, $usuario );
		if ( is_wp_error( $grupo ) ) {
			return $grupo;
		}
		// La credencial ya vive en el grupo: se borra de la solicitud. DNI y nombre se conservan hasta crear el primer acceso.
		$wpdb->update(
			GrupoSolicitudes::table(),
			array(
				'estado'       => GrupoSolicitudes::APROBADA,
				'usuario_enc'  => '',
				'clave_enc'    => '',
				'resuelta'     => current_time( 'mysql', true ),
				'resuelta_por' => get_current_user_id(),
			),
			array( 'id' => $id )
		);
		Logger::log( 'grupo_alta_aprobada', 'solicitud_grupo', (string) $id, array( 'grupo' => $grupo['numero'] ) );
		self::avisar( $s, true );
		return $grupo;
	}

	/** Rechaza una solicitud: borra todo lo secreto guardado y avisa (sin motivo) al grupo. */
	public static function rechazar( int $id ): bool {
		global $wpdb;
		$s = GrupoSolicitudes::obtener( $id );
		if ( ! $s || GrupoSolicitudes::PENDIENTE !== $s['estado'] ) {
			return false;
		}
		$wpdb->update(
			GrupoSolicitudes::table(),
			array(
				'estado'       => GrupoSolicitudes::RECHAZADA,
				'usuario_enc'  => '',
				'clave_enc'    => '',
				'dni_enc'      => '',
				'persona_enc'  => '',
				'resuelta'     => current_time( 'mysql', true ),
				'resuelta_por' => get_current_user_id(),
			),
			array( 'id' => $id )
		);
		Logger::log( 'grupo_alta_rechazada', 'solicitud_grupo', (string) $id, array( 'grupo' => $s['numero'] ) );
		self::avisar( $s, false );
		return true;
	}

	/** Aviso por email al grupo: genérico, sin motivo del rechazo. @return string '' si salió bien, o el error. */
	private static function avisar( array $s, bool $aprobada ): string {
		if ( ! is_email( (string) $s['email'] ) ) {
			return asde_t( 'La solicitud no tiene un email válido al que avisar.' );
		}
		$organizacion = Organizacion::nombre();
		list( $asunto, $html ) = Idioma::en(
			Idioma::por_defecto(),
			static fn() => array(
				$aprobada
					? sprintf( asde_t( '[%s] Tu grupo ya está dado de alta' ), $organizacion )
					: sprintf( asde_t( '[%s] Sobre el alta de tu grupo' ), $organizacion ),
				TemplateLoader::render(
					'email/alta-grupo-resultado',
					array(
						'organizacion' => $organizacion,
						'grupo'        => (string) $s['nombre'],
						'aprobada'     => $aprobada,
					)
				),
			)
		);
		$error = RegistroEmails::enviar( (string) $s['email'], $asunto, $html );
		RegistroEmails::registrar( null, RegistroEmails::ALTA_GRUPO, Contacto::enmascarar( (string) $s['email'] ), $asunto, '' === $error ? RegistroEmails::ENVIADO : RegistroEmails::ERROR, $error, array( 'aprobada' => $aprobada ) );
		return $error;
	}
}
