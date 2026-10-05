<?php
namespace AsdeBalears\AreaPrivada\Inventario;

use AsdeBalears\AreaPrivada\Crm\PersonaRepository;
use AsdeBalears\AreaPrivada\Domain\Grupo;
use AsdeBalears\AreaPrivada\Frontend\Pages;
use AsdeBalears\AreaPrivada\Frontend\TemplateLoader;
use AsdeBalears\AreaPrivada\Idiomas\Idioma;
use AsdeBalears\AreaPrivada\Install\Roles;
use AsdeBalears\AreaPrivada\Usuarios\Contacto;
use AsdeBalears\AreaPrivada\Usuarios\EmailRechazo;
use AsdeBalears\AreaPrivada\Usuarios\RegistroEmails;
use AsdeBalears\AreaPrivada\Usuarios\Usuarios;

defined( 'ABSPATH' ) || exit;

/**
 * Email a Tesorería cuando alguien crea una lista de compra de material: tiene
 * que darle el visto bueno para que se pueda imprimir (doc 32).
 *
 * - Va a cada cuenta activa del grupo con el rol Tesorería (menos quien la ha
 *   creado), en su idioma. Si no hay ninguna con email, al email de contacto del grupo.
 * - Lleva el nº de la lista, quién la pide, el material y el total estimado (no
 *   hay datos personales) y el enlace para revisarla (pide iniciar sesión).
 * - Queda en el registro de emails (tipo «compra») con el destino enmascarado.
 */
final class AvisoTesoreria {

	/** @return \WP_User[] Cuentas activas de Tesorería del grupo, sin quien crea la lista. */
	public static function destinatarios(): array {
		$users = get_users(
			array(
				'role'       => Roles::TESORERIA,
				'meta_key'   => Usuarios::META_GRUPO, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- pocas cuentas por grupo
				'meta_value' => Grupo::id(), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- pocas cuentas por grupo
			)
		);
		return array_values(
			array_filter(
				$users,
				static fn( \WP_User $u ) => $u->ID !== get_current_user_id() && Usuarios::ACTIVO === Usuarios::estado( $u )
			)
		);
	}

	public static function asunto( array $lista ): string {
		/* translators: 1: nombre del grupo, 2: nº de la lista */
		return sprintf( asde_t( '[%1$s] Lista de compra %2$s pendiente de tu visto bueno' ), Grupo::nombre(), ComprasTable::numero( $lista ) );
	}

	/** HTML del email (el envío y la vista previa del registro usan la misma plantilla). */
	public static function html( array $lista, string $nombre ): string {
		return TemplateLoader::render(
			'email/compra-material',
			array(
				'nombre' => $nombre,
				'grupo'  => Grupo::nombre(),
				'lista'  => $lista,
				'autor'  => Servicio::usuario( $lista['creado_por'] ),
				'url'    => Pages::url( 'inventario_compras', array( 'lista' => (string) $lista['id'] ) ),
			)
		);
	}

	/**
	 * Avisa de una lista nueva.
	 *
	 * @return array{enviados:int, errores:int, contacto:bool} contacto: se ha enviado al email del grupo porque no había nadie de Tesorería con email.
	 */
	public static function enviar( array $lista ): array {
		$res   = array(
			'enviados' => 0,
			'errores'  => 0,
			'contacto' => false,
		);
		$repo  = PersonaRepository::make();
		$hecho = array();
		foreach ( self::destinatarios() as $u ) {
			$email = Contacto::email( $u, $repo );
			if ( ! $email || isset( $hecho[ strtolower( $email ) ] ) ) {
				continue;
			}
			$hecho[ strtolower( $email ) ] = true;
			$ok = Idioma::en(
				Idioma::de_usuario( $u->ID ),
				static fn() => self::mandar( $u, $email, self::asunto( $lista ), self::html( $lista, Contacto::saludo( $u, $repo ) ), $lista )
			);
			$ok ? ++$res['enviados'] : ++$res['errores'];
		}
		if ( ! $hecho ) {
			$contacto = EmailRechazo::contacto();
			if ( '' !== $contacto ) {
				$res['contacto'] = true;
				$ok              = Idioma::en( Idioma::por_defecto(), static fn() => self::mandar( null, $contacto, self::asunto( $lista ), self::html( $lista, '' ), $lista ) );
				$ok ? ++$res['enviados'] : ++$res['errores'];
			}
		}
		ComprasTable::fijar_avisados( $lista['id'], $res['enviados'] );
		return $res;
	}

	private static function mandar( ?\WP_User $user, string $email, string $asunto, string $html, array $lista ): bool {
		$error   = '';
		$captura = static function ( $e ) use ( &$error ) {
			$error = is_wp_error( $e ) ? $e->get_error_message() : asde_t( 'Error desconocido' );
		};
		add_action( 'wp_mail_failed', $captura );
		$ok = wp_mail( $email, $asunto, $html, array( 'Content-Type: text/html; charset=UTF-8' ) );
		remove_action( 'wp_mail_failed', $captura );
		RegistroEmails::registrar(
			$user,
			RegistroEmails::COMPRA,
			Contacto::enmascarar( $email ),
			$asunto,
			$ok ? RegistroEmails::ENVIADO : RegistroEmails::ERROR,
			$ok ? '' : ( $error ?: asde_t( 'El servidor de correo no ha aceptado el email.' ) ),
			array(
				'lista' => (int) $lista['id'],
				'grupo' => Grupo::nombre(),
			)
		);
		return $ok;
	}
}
