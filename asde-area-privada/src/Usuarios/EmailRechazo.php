<?php
namespace AsdeBalears\AreaPrivada\Usuarios;

use AsdeBalears\AreaPrivada\Domain\Grupo;
use AsdeBalears\AreaPrivada\Domain\Grupos;
use AsdeBalears\AreaPrivada\Frontend\TemplateLoader;

defined( 'ABSPATH' ) || exit;

/**
 * Aviso por email a quien pidió el acceso cuando se rechaza su solicitud
 * (P-36, decidido el 2026-09-30).
 *
 * - Va al email escrito en la solicitud (aún guardado, cifrado, hasta 30 días
 *   después de resolverla).
 * - El texto es genérico: el motivo que anota quien la revisa es solo para el
 *   grupo y no se envía.
 * - Lleva el email de contacto del grupo (configuración del grupo, wp-admin →
 *   Grupos Scouts) para volver a intentarlo, y como «Responder a».
 * - Queda en el registro de emails con el destino enmascarado, sin datos personales.
 */
final class EmailRechazo {

	public const ENVIADO   = 'enviado';
	public const ERROR     = 'error';
	public const SIN_EMAIL = 'sin_email';

	/** Email de contacto del grupo en el que se trabaja ('' si no está configurado). */
	public static function contacto(): string {
		return Grupos::email_contacto( Grupos::por_numero( Grupo::numero() ) );
	}

	/** Primer nombre de quien pidió, para el saludo. */
	public static function saludo( array $solicitud ): string {
		$partes = preg_split( '/\s+/', trim( (string) ( $solicitud['datos']['nombre'] ?? '' ) ) ) ?: array();
		return mb_substr( (string) ( $partes[0] ?? '' ), 0, 40 );
	}

	public static function asunto(): string {
		return sprintf( '[%s] Sobre tu solicitud de acceso al área privada', Grupo::nombre() );
	}

	/** @return string HTML del email (la vista previa del registro y el envío usan la misma plantilla). */
	public static function html( string $nombre, string $grupo, string $contacto ): string {
		return TemplateLoader::render(
			'email/solicitud-rechazada',
			array(
				'nombre'   => $nombre,
				'grupo'    => $grupo,
				'contacto' => $contacto,
			)
		);
	}

	/**
	 * Envía el aviso de una solicitud rechazada.
	 *
	 * @param array $solicitud Solicitud (Solicitudes::obtener) con lo escrito descifrado.
	 * @return array{estado:string, destino:string, error:string}
	 */
	public static function enviar( array $solicitud ): array {
		$email = EmailCuenta::normalizar( (string) ( $solicitud['datos']['email'] ?? '' ) );
		if ( ! is_email( $email ) ) {
			return array(
				'estado'  => self::SIN_EMAIL,
				'destino' => '',
				'error'   => 'La solicitud no tiene un email válido al que avisar.',
			);
		}
		$contacto = self::contacto();
		$asunto   = self::asunto();
		$html     = self::html( self::saludo( $solicitud ), Grupo::nombre(), $contacto );
		$headers  = array( 'Content-Type: text/html; charset=UTF-8' );
		if ( '' !== $contacto ) {
			$headers[] = 'Reply-To: ' . $contacto;
		}

		// El motivo de un fallo lo da PHPMailer a través de wp_mail_failed.
		$error   = '';
		$captura = static function ( $e ) use ( &$error ) {
			$error = is_wp_error( $e ) ? $e->get_error_message() : 'Error desconocido';
		};
		add_action( 'wp_mail_failed', $captura );
		$ok = wp_mail( $email, $asunto, $html, $headers );
		remove_action( 'wp_mail_failed', $captura );

		$destino = Contacto::enmascarar( $email );
		RegistroEmails::registrar(
			null,
			RegistroEmails::RECHAZO,
			$destino,
			$asunto,
			$ok ? RegistroEmails::ENVIADO : RegistroEmails::ERROR,
			$ok ? '' : ( $error ?: 'El servidor de correo no ha aceptado el email.' ),
			array(
				'solicitud' => (int) $solicitud['id'],
				'grupo'     => Grupo::nombre(),
				'contacto'  => $contacto,
			)
		);
		return array(
			'estado'  => $ok ? self::ENVIADO : self::ERROR,
			'destino' => $destino,
			'error'   => $ok ? '' : ( $error ?: 'El servidor de correo no ha aceptado el email.' ),
		);
	}
}
