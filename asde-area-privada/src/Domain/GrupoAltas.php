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
		self::primer_usuario( $id ); // Si falla, el grupo queda aprobado y la pantalla de altas lo avisa para reintentarlo.
		return $grupo;
	}

	/**
	 * Primer usuario de acceso del grupo (fase 7): busca en el CRM del grupo la ficha por el DNI que se escribió, con
	 * membresía activa, y le da el rol elegido (Secretaría o Coordinación) enviando la invitación **al email del grupo**.
	 * Si no hay ficha, el grupo queda aprobado pero sin usuario, y se puede reintentar. Una sola invitación por solicitud.
	 *
	 * @return string Estado: `invitado`, `sin_ficha` o `error`.
	 */
	public static function primer_usuario( int $id ): string {
		global $wpdb;
		$s = GrupoSolicitudes::obtener( $id );
		if ( ! $s || GrupoSolicitudes::APROBADA !== $s['estado'] || GrupoSolicitudes::USUARIO_INVITADO === $s['usuario_estado'] ) {
			return $s ? (string) $s['usuario_estado'] : '';
		}
		$grupo = Grupos::por_numero( (string) $s['numero'] );
		$dni   = (string) Crypto::decrypt( (string) $s['dni_enc'] );
		$rol   = array(
			'secretaria'   => \AsdeBalears\AreaPrivada\Install\Roles::SECRETARIA,
			'coordinacion' => \AsdeBalears\AreaPrivada\Install\Roles::COORDINACION,
		)[ $s['rol'] ] ?? '';
		$estado = GrupoSolicitudes::USUARIO_ERROR;
		if ( $grupo && '' !== $dni && '' !== $rol ) {
			$estado = Grupo::con(
				$grupo,
				static function () use ( $dni, $rol, $s ): string {
					$servicio = \AsdeBalears\AreaPrivada\Usuarios\AltaService::make();
					if ( ! $servicio ) {
						return GrupoSolicitudes::USUARIO_ERROR;
					}
					try {
						$fichas = \AsdeBalears\AreaPrivada\Crm\PersonaRepository::make()->por_documento( $dni );
						if ( 1 !== count( $fichas ) ) {
							return GrupoSolicitudes::USUARIO_SIN_FICHA;
						}
						$r = $servicio->con_bienvenida()->alta_persona( $fichas[0]->num_socio_asde, array( $rol ), array( Secciones::SIN_SECCION ), 'email1', (string) $s['email'] );
						return $r->ok ? GrupoSolicitudes::USUARIO_INVITADO : GrupoSolicitudes::USUARIO_SIN_FICHA;
					} catch ( \AsdeBalears\AreaPrivada\Crm\CrmException $e ) {
						return GrupoSolicitudes::USUARIO_ERROR;
					}
				}
			);
		}
		$cambios = array( 'usuario_estado' => $estado );
		if ( GrupoSolicitudes::USUARIO_INVITADO === $estado ) {
			$cambios['dni_enc']     = ''; // Ya no hace falta guardar el DNI ni el nombre.
			$cambios['persona_enc'] = '';
		}
		$wpdb->update( GrupoSolicitudes::table(), $cambios, array( 'id' => $id ) );
		Logger::log( 'grupo_primer_usuario', 'solicitud_grupo', (string) $id, array( 'resultado' => $estado ) );
		return $estado;
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
