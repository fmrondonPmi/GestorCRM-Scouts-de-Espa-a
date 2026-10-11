<?php
namespace AsdeBalears\AreaPrivada\Inventario;

use AsdeBalears\AreaPrivada\Crm\PersonaRepository;
use AsdeBalears\AreaPrivada\Domain\Grupo;
use AsdeBalears\AreaPrivada\Frontend\Pages;
use AsdeBalears\AreaPrivada\Frontend\TemplateLoader;
use AsdeBalears\AreaPrivada\Idiomas\Idioma;
use AsdeBalears\AreaPrivada\Usuarios\Contacto;
use AsdeBalears\AreaPrivada\Usuarios\RegistroEmails;
use AsdeBalears\AreaPrivada\Usuarios\Usuarios;

defined( 'ABSPATH' ) || exit;

/**
 * Email a quien pidió una lista de compra cuando Tesorería le da el visto bueno o la
 * rechaza (v0.30, P-65; doc 37).
 *
 * - Va a la cuenta que creó la lista, si sigue activa y tiene email (el de la cuenta,
 *   Contacto::email: el del CRM o el guardado cifrado en la web), en su idioma.
 * - Lleva el estado, el motivo si se rechaza, el material y el enlace a la lista (pide
 *   iniciar sesión). No se envía si quien la revisa es quien la pidió (ya lo sabe).
 * - Queda en el registro de emails (tipo «compra_revisada») con el destino enmascarado;
 *   una cuenta sin email queda anotada como «Sin email».
 */
final class AvisoRevision {

	public static function asunto( array $lista ): string {
		return ComprasTable::RECHAZADA === $lista['estado']
			/* translators: 1: nombre del grupo, 2: nº de la lista */
			? sprintf( asde_t( '[%1$s] Tesorería ha rechazado tu lista de compra %2$s' ), Grupo::nombre(), ComprasTable::numero( $lista ) )
			/* translators: 1: nombre del grupo, 2: nº de la lista */
			: sprintf( asde_t( '[%1$s] Tu lista de compra %2$s tiene el visto bueno de Tesorería' ), Grupo::nombre(), ComprasTable::numero( $lista ) );
	}

	/** HTML del email (el envío y la vista previa del registro usan la misma plantilla). */
	public static function html( array $lista, string $nombre ): string {
		return TemplateLoader::render(
			'email/compra-revisada',
			array(
				'nombre'  => $nombre,
				'grupo'   => Grupo::nombre(),
				'lista'   => $lista,
				'revisor' => Servicio::usuario( (int) $lista['revisado_por'] ),
				'url'     => Pages::url( 'inventario_compras', array( 'lista' => (string) $lista['id'] ) ),
			)
		);
	}

	/**
	 * Avisa a quien la pidió de que se ha aprobado o rechazado (la lista, ya revisada).
	 *
	 * @return string RegistroEmails::ENVIADO, ::ERROR o ::SIN_EMAIL; '' si no toca enviarlo
	 *                (la ha revisado quien la pidió, o su cuenta ya no existe o no está activa).
	 */
	public static function enviar( array $lista ): string {
		if ( ! in_array( $lista['estado'], array( ComprasTable::APROBADA, ComprasTable::RECHAZADA ), true ) ) {
			return '';
		}
		$u = $lista['creado_por'] ? get_userdata( (int) $lista['creado_por'] ) : false;
		if ( ! $u || $u->ID === get_current_user_id() || Usuarios::ACTIVO !== Usuarios::estado( $u ) ) {
			return '';
		}
		$datos = array(
			'lista'  => (int) $lista['id'],
			'estado' => (string) $lista['estado'],
			'grupo'  => Grupo::nombre(),
		);
		$repo  = PersonaRepository::make();
		$email = Contacto::email( $u, $repo );
		return (string) Idioma::en(
			Idioma::de_usuario( $u->ID ),
			static function () use ( $u, $email, $lista, $repo, $datos ): string {
				$asunto = self::asunto( $lista );
				if ( ! $email ) {
					RegistroEmails::registrar( $u, RegistroEmails::COMPRA_REVISADA, '', $asunto, RegistroEmails::SIN_EMAIL, asde_t( 'La cuenta no tiene un email al que escribir.' ), $datos );
					return RegistroEmails::SIN_EMAIL;
				}
				$error   = '';
				$captura = static function ( $e ) use ( &$error ) {
					$error = is_wp_error( $e ) ? $e->get_error_message() : asde_t( 'Error desconocido' );
				};
				add_action( 'wp_mail_failed', $captura );
				$ok = wp_mail( $email, $asunto, self::html( $lista, Contacto::saludo( $u, $repo ) ), array( 'Content-Type: text/html; charset=UTF-8' ) );
				remove_action( 'wp_mail_failed', $captura );
				RegistroEmails::registrar(
					$u,
					RegistroEmails::COMPRA_REVISADA,
					Contacto::enmascarar( $email ),
					$asunto,
					$ok ? RegistroEmails::ENVIADO : RegistroEmails::ERROR,
					$ok ? '' : ( $error ?: asde_t( 'El servidor de correo no ha aceptado el email.' ) ),
					$datos
				);
				return $ok ? RegistroEmails::ENVIADO : RegistroEmails::ERROR;
			}
		);
	}

	/** Vista previa del registro de emails: la lista con el estado que tenía al enviarlo. */
	public static function vista_previa( array $datos, string $nombre ): string {
		$lista = ComprasTable::obtener( (int) ( $datos['lista'] ?? 0 ) );
		if ( ! $lista ) {
			return '';
		}
		if ( isset( $datos['estado'] ) && in_array( $datos['estado'], array( ComprasTable::APROBADA, ComprasTable::RECHAZADA ), true ) ) {
			$lista['estado'] = (string) $datos['estado'];
		}
		return self::html( $lista, $nombre );
	}
}
