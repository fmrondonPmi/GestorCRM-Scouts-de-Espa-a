<?php
namespace AsdeBalears\AreaPrivada\Auth;

use AsdeBalears\AreaPrivada\Crm\CrmException;
use AsdeBalears\AreaPrivada\Crm\PersonaRepository;
use AsdeBalears\AreaPrivada\Domain\Grupo;
use AsdeBalears\AreaPrivada\Domain\Grupos;
use AsdeBalears\AreaPrivada\Domain\Identidad;
use AsdeBalears\AreaPrivada\Domain\Persona;
use AsdeBalears\AreaPrivada\Usuarios\Contacto;
use AsdeBalears\AreaPrivada\Usuarios\EmailCuenta;
use AsdeBalears\AreaPrivada\Usuarios\Usuarios;

defined( 'ABSPATH' ) || exit;

/**
 * Usuario de acceso de los socios: su nº de socio/a (las familias, el del menor
 * + "T") o el email de su cuenta (el del campo del CRM elegido en el alta o el
 * de su solicitud de acceso: al que le llegó la invitación).
 *
 * Desde la v0.10 el email de cada cuenta se guarda cifrado en la web
 * (Usuarios\EmailCuenta) y la cuenta se encuentra por la huella del email, sin
 * consultar el CRM. Solo para las cuentas de antes de la v0.10 que aún no
 * tienen el email guardado se busca en el CRM del grupo elegido en qué fichas
 * está (asde_email_origen = "nº_asde:campo"), y lo encontrado se guarda.
 *
 * Un mismo email puede ser el de varias cuentas del grupo (p. ej. la propia de
 * un scouter y la familiar de sus hijos): se entra en la única cuya contraseña
 * coincida y, si coincide en varias, se pide el nº de socio/a de la que se
 * quiere usar.
 */
final class Identificacion {

	/** Máximo de fichas del CRM que se miran por email (una familia numerosa cabe de sobra). */
	private const MAX_FICHAS = 50;

	public static function es_email( string $valor ): bool {
		return str_contains( $valor, '@' );
	}

	/**
	 * Nº de socio con ceros delante, espacios o la "t" en minúscula → formato de
	 * usuario sin ceros iniciales (76840390, 76840390T, 76840390T2; también el 0 de la
	 * provincia de los números de la v0.56: 076840390 → 76840390, y la cuenta con el 0 la
	 * encuentra después Identidad::resolver()); email → sin espacios y en minúsculas.
	 */
	public static function normalizar( string $valor ): string {
		$valor = trim( $valor );
		if ( self::es_email( $valor ) ) {
			return strtolower( (string) preg_replace( '/\s+/', '', $valor ) );
		}
		return Identidad::normalizar_numero( $valor ) ?? sanitize_user( $valor );
	}

	/**
	 * Identificador único de la cuenta a la que apunta lo escrito, para contar los intentos de
	 * acceso: el email tal cual y, con un nº de socio/a, el usuario real (da igual si se escribió
	 * el de Scouts de España o el local).
	 */
	public static function canonico( string $identificador ): string {
		return self::es_email( $identificador ) ? $identificador : Identidad::resolver( $identificador );
	}

	/**
	 * Cuentas del área privada de un grupo con este email (sin las desactivadas).
	 *
	 * @param array $grupo Grupo (Grupos::obtener).
	 * @return \WP_User[]|null null si hacía falta consultar el CRM y no ha respondido.
	 */
	public static function cuentas_con_email( string $email, array $grupo ): ?array {
		$email = self::normalizar( $email );
		if ( ! is_email( $email ) ) {
			return array();
		}
		$cuentas    = self::del_grupo( EmailCuenta::usuarios( $email ), $grupo );
		$pendientes = EmailCuenta::pendientes( $grupo['id'] );
		if ( ! $pendientes ) {
			return $cuentas;
		}
		// Cuentas de antes de la v0.10 sin email guardado: se busca en el CRM.
		$del_crm = self::cuentas_por_crm( $email, $grupo, $pendientes );
		if ( null === $del_crm ) {
			return $cuentas ?: null;
		}
		foreach ( $del_crm as $u ) {
			EmailCuenta::guardar( $u->ID, $email ); // La próxima vez ya no hace falta el CRM.
			$cuentas[] = $u;
		}
		$unicas = array();
		foreach ( $cuentas as $u ) {
			$unicas[ $u->user_login ] = $u;
		}
		ksort( $unicas, SORT_STRING );
		return self::del_grupo( array_values( $unicas ), $grupo );
	}

	/** @param \WP_User[] $usuarios @return \WP_User[] Los del grupo y no desactivados. */
	private static function del_grupo( array $usuarios, array $grupo ): array {
		return array_values(
			array_filter(
				$usuarios,
				static function ( \WP_User $u ) use ( $grupo ): bool {
					$suyo = Grupos::de_usuario( $u );
					return $suyo && $suyo['id'] === $grupo['id'] && Usuarios::DESACTIVADO !== Usuarios::estado( $u );
				}
			)
		);
	}

	/**
	 * Entre las cuentas sin email guardado, las que tienen este email en su campo
	 * de origen del CRM.
	 *
	 * @param \WP_User[] $pendientes
	 * @return \WP_User[]|null null si el CRM no responde.
	 */
	private static function cuentas_por_crm( string $email, array $grupo, array $pendientes ): ?array {
		$personas = self::personas_con_email( $email, $grupo );
		if ( null === $personas ) {
			return null;
		}
		$origenes = array();
		foreach ( $personas as $p ) {
			foreach ( $p->emails_por_campo() as $campo => $valor ) {
				if ( $valor === $email ) {
					$origenes[] = $p->num_socio_asde . ':' . $campo;
				}
			}
		}
		return array_values(
			array_filter(
				$pendientes,
				static function ( \WP_User $u ) use ( $origenes ): bool {
					$o = Usuarios::email_origen( $u->ID );
					return $o && in_array( $o['asde'] . ':' . $o['campo'], $origenes, true );
				}
			)
		);
	}

	/**
	 * Usuario de WordPress con el que validar el acceso desde la pantalla de acceso.
	 *
	 * Con un nº de socio se devuelve tal cual. Con un email:
	 * - de una sola cuenta del grupo → esa cuenta;
	 * - de varias → la única cuya contraseña coincida (si no coincide ninguna, la
	 *   primera: el acceso fallará como una contraseña incorrecta);
	 * - de ninguna → un usuario de WordPress con ese email (administradores).
	 *
	 * @param string $identificador Ya normalizado (normalizar()).
	 * @param array  $grupo         Grupo elegido en la pantalla de acceso.
	 * @return string|\WP_Error Usuario para wp_signon(), o error:
	 *   invalid_email (no es de nadie: mismo mensaje que una contraseña incorrecta),
	 *   asde_email_crm (el CRM no responde) o
	 *   asde_email_varias (la contraseña vale para varias cuentas: se dicen sus números).
	 */
	public static function usuario_para( string $identificador, array $grupo, string $clave ) {
		if ( ! self::es_email( $identificador ) ) {
			// El nº de socio/a escrito vale en cualquiera de sus dos formas (Scouts de España o local).
			return Identidad::resolver( $identificador );
		}
		$cuentas = self::cuentas_con_email( $identificador, $grupo );
		if ( null === $cuentas ) {
			return new \WP_Error( 'asde_email_crm', asde_t( 'Ahora no se puede comprobar el email porque el CRM no responde. Entra con tu nº de socio/a o inténtalo más tarde.' ) );
		}
		if ( ! $cuentas ) {
			// Sin cuentas del área con ese email: solo queda un usuario de WordPress que
			// lo tenga como propio. Si no hay, no se llama a wp_signon() con el email
			// (se registraría el intento fallido con el email escrito).
			$wp = is_email( $identificador ) ? get_user_by( 'email', $identificador ) : false;
			return $wp && ! Usuarios::email_origen( $wp->ID ) ? $wp->user_login : new \WP_Error( 'invalid_email', asde_t( 'Email desconocido.' ) );
		}
		if ( 1 === count( $cuentas ) ) {
			return $cuentas[0]->user_login;
		}
		$validas = array_values( array_filter( $cuentas, static fn( \WP_User $u ) => wp_check_password( $clave, $u->user_pass, $u->ID ) ) );
		if ( count( $validas ) > 1 ) {
			return new \WP_Error(
				'asde_email_varias',
				sprintf(
					asde_t( 'Ese email es el de varias cuentas y la contraseña vale para todas. Escribe el nº de socio/a de la cuenta con la que quieres entrar: %s.' ),
					Contacto::lista( array_map( static fn( \WP_User $u ) => $u->user_login, $validas ), 'o' )
				)
			);
		}
		return ( $validas[0] ?? $cuentas[0] )->user_login;
	}

	/**
	 * Fichas del grupo con este email (propio o de tutor), leídas del CRM.
	 * El filtro `asde_ap_pre_personas_por_email` permite dar la respuesta sin
	 * consultar el CRM (pruebas): Persona[] o un WP_Error para simular un fallo.
	 *
	 * @return Persona[]|null
	 */
	private static function personas_con_email( string $email, array $grupo ): ?array {
		$pre = apply_filters( 'asde_ap_pre_personas_por_email', null, $email, $grupo );
		if ( is_wp_error( $pre ) ) {
			return null;
		}
		if ( is_array( $pre ) ) {
			return array_values( array_filter( $pre, static fn( $p ) => $p instanceof Persona ) );
		}
		return Grupo::con(
			$grupo,
			static function () use ( $email ): ?array {
				$repo = PersonaRepository::make();
				if ( ! $repo ) {
					return null;
				}
				try {
					return $repo->por_emails( array( $email ), self::MAX_FICHAS, false );
				} catch ( CrmException $e ) {
					return null;
				}
			}
		);
	}
}
