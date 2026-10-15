<?php
namespace AsdeBalears\AreaPrivada\Usuarios;

use AsdeBalears\AreaPrivada\Crm\PersonaRepository;
use AsdeBalears\AreaPrivada\Domain\Grupo;
use AsdeBalears\AreaPrivada\Domain\Grupos;
use AsdeBalears\AreaPrivada\Frontend\Pages;
use AsdeBalears\AreaPrivada\Frontend\TemplateLoader;
use AsdeBalears\AreaPrivada\Install\Roles;
use AsdeBalears\AreaPrivada\Idiomas\Idioma;

defined( 'ABSPATH' ) || exit;

/**
 * Enlaces de un solo uso para activar la cuenta (invitación) o elegir una
 * contraseña nueva (recuperación).
 *
 * El token (192 bits) solo viaja en el email; en la base de datos se guarda su
 * HMAC. El email de destino se lee del CRM en el momento de enviar.
 */
final class Invitacion {

	public const ALTA  = 'alta';
	public const RESET = 'reset';

	/** Validez en días. */
	public const DIAS       = 7;
	public const DIAS_RESET = 1;

	private const META_HASH   = 'asde_invitacion_hash';
	private const META_EXPIRA = 'asde_invitacion_expira';
	private const META_TIPO   = 'asde_invitacion_tipo';

	/**
	 * Siempre desde el grupo del destinatario: el email se busca en ese grupo y el
	 * asunto lleva su nombre (también al recuperar la contraseña sin sesión).
	 */
	/**
	 * @param bool $bienvenida Con mensaje de bienvenida: la cuenta viene de una solicitud de acceso aprobada.
	 */
	public static function enviar( \WP_User $user, string $tipo = self::ALTA, ?PersonaRepository $repo = null, bool $bienvenida = false ): bool {
		$grupo = Grupos::de_usuario( $user );
		if ( $grupo && $grupo['numero'] !== Grupo::numero() ) {
			$repo = null; // El repositorio recibido lee el CRM con las credenciales de otro grupo.
		}
		// El email sale en el idioma de quien lo recibe.
		return Idioma::en( Idioma::de_usuario( $user->ID ), static fn() => Grupo::con( $grupo, static fn() => self::componer_y_enviar( $user, $tipo, $repo, $bienvenida ) ) );
	}

	private static function componer_y_enviar( \WP_User $user, string $tipo, ?PersonaRepository $repo, bool $bienvenida ): bool {
		$dias   = self::RESET === $tipo ? self::DIAS_RESET : self::DIAS;
		$asunto = self::RESET === $tipo
			? sprintf( $bienvenida ? asde_t( '[%s] Bienvenida al área privada: elige tu contraseña' ) : asde_t( '[%s] Elige una contraseña nueva' ), Grupo::nombre() )
			: sprintf( $bienvenida ? asde_t( '[%s] Bienvenida al área privada: activa tu acceso' ) : asde_t( '[%s] Activa tu acceso al área privada' ), Grupo::nombre() );
		$autor  = wp_get_current_user();
		$roles  = array_map( array( Roles::class, 'label' ), Usuarios::roles_asde( $user ) );
		// Para el registro: lo necesario para reconstruir el email, sin datos personales ni el enlace.
		$datos = array(
			'roles'      => $roles,
			'grupo'      => Grupo::nombre(),
			'dias'       => $dias,
			'autor_id'   => $autor->exists() && self::ALTA === $tipo ? $autor->ID : 0,
			'bienvenida' => $bienvenida,
		);

		$email = Contacto::email( $user, $repo );
		if ( ! $email ) {
			$origen = Usuarios::email_origen( $user->ID );
			RegistroEmails::registrar(
				$user,
				$tipo,
				'',
				$asunto,
				RegistroEmails::SIN_EMAIL,
				$origen
					? sprintf( asde_t( 'La ficha del CRM no tiene email en el campo elegido (%s), o el CRM no ha respondido.' ), $origen['campo'] )
					: asde_t( 'El usuario no tiene un email del CRM asociado.' ),
				$datos
			);
			return false;
		}

		$token = bin2hex( random_bytes( 24 ) );
		update_user_meta( $user->ID, self::META_HASH, self::hash( $token ) );
		update_user_meta( $user->ID, self::META_EXPIRA, time() + $dias * DAY_IN_SECONDS );
		update_user_meta( $user->ID, self::META_TIPO, $tipo );

		$html = TemplateLoader::render(
			'email/invitacion',
			array(
				'tipo'   => $tipo,
				'nombre' => Contacto::saludo( $user, $repo ),
				'login'  => $user->user_login,
				'roles'  => $roles,
				'grupo'  => Grupo::nombre(),
				'autor'  => $datos['autor_id'] ? Contacto::saludo( $autor, $repo ) : '',
				'url'    => add_query_arg(
					array(
						'u' => $user->ID,
						't' => $token,
					),
					Pages::url( 'activar' )
				),
				'dias'   => $dias,
				'bienvenida' => $bienvenida,
			)
		);

		$error = RegistroEmails::enviar( $email, $asunto, $html );
		$ok    = '' === $error;
		RegistroEmails::registrar( $user, $tipo, Contacto::enmascarar( $email ), $asunto, $ok ? RegistroEmails::ENVIADO : RegistroEmails::ERROR, $error, $datos );
		return $ok;
	}

	public static function validar( int $user_id, string $token ): ?\WP_User {
		if ( ! preg_match( '/^[0-9a-f]{48}$/', $token ) ) {
			return null;
		}
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return null;
		}
		$hash   = (string) get_user_meta( $user_id, self::META_HASH, true );
		$expira = (int) get_user_meta( $user_id, self::META_EXPIRA, true );
		if ( '' === $hash || $expira < time() || ! hash_equals( $hash, self::hash( $token ) ) ) {
			return null;
		}
		return $user;
	}

	public static function tipo( int $user_id ): string {
		return self::RESET === get_user_meta( $user_id, self::META_TIPO, true ) ? self::RESET : self::ALTA;
	}

	public static function consumir( int $user_id ): void {
		delete_user_meta( $user_id, self::META_HASH );
		delete_user_meta( $user_id, self::META_EXPIRA );
		delete_user_meta( $user_id, self::META_TIPO );
	}

	/** Timestamp de caducidad de la invitación pendiente, o null. */
	public static function expira( int $user_id ): ?int {
		if ( self::RESET === self::tipo( $user_id ) ) {
			return null;
		}
		$expira = (int) get_user_meta( $user_id, self::META_EXPIRA, true );
		return $expira ?: null;
	}

	private static function hash( string $token ): string {
		return hash_hmac( 'sha256', $token, wp_salt( 'auth' ) );
	}
}
