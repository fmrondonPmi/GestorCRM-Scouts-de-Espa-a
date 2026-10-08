<?php
namespace AsdeBalears\AreaPrivada\Usuarios;

use AsdeBalears\AreaPrivada\Install\Roles;
use AsdeBalears\AreaPrivada\Integraciones\Wordfence;

defined( 'ABSPATH' ) || exit;

/**
 * Vínculo entre usuarios de WordPress y el CRM.
 *
 * En WordPress no se guardan nombres ni teléfonos. El email de cada cuenta se
 * guarda cifrado desde la v0.10 (EmailCuenta). El usuario de acceso es el nº de
 * socio local (o el del menor + "T" en las cuentas familiares) o ese email, y el
 * resto son identificadores:
 *
 * - asde_socio         Nº de socio Scouts de España de la propia persona (clave del CRM).
 * - asde_representado  (varias filas) Nº Scouts de España de los menores de una cuenta familiar.
 * - asde_email_origen  "nº_asde:campo" → en qué ficha y campo del CRM está su email
 *                      (si lo hay, el email guardado sigue a ese campo del CRM).
 * - asde_seccion       (varias filas) Secciones educativas de un scouter (el CRM no lo guarda).
 */
final class Usuarios {

	public const META_SOCIO        = 'asde_socio';
	public const META_REPRESENTADO = 'asde_representado';
	public const META_EMAIL_ORIGEN = 'asde_email_origen';
	public const META_GRUPO        = 'asde_grupo_id';
	public const META_SECCION      = 'asde_seccion';
	public const META_ESTADO       = 'asde_estado';
	public const META_ALTA_POR     = 'asde_alta_por';
	public const META_ALTA_EN      = 'asde_alta_en';
	public const META_ACTIVADA_EN  = 'asde_activada_en';

	public const CAMPOS_EMAIL = array( 'email1', 'tutor1_email_c', 'tutor2_email_c' );

	/** Cuentas familiares por familia: una por tutor/a (v0.12; el CRM guarda dos tutores por ficha). */
	public const MAX_CUENTAS_FAMILIARES = 2;

	public const INVITADO    = 'invitado';
	public const ACTIVO      = 'activo';
	public const DESACTIVADO = 'desactivado';

	public static function por_socio( string $num_asde ): ?\WP_User {
		$users = get_users(
			array(
				'meta_key'   => self::META_SOCIO, // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value' => $num_asde, // phpcs:ignore WordPress.DB.SlowDBQuery
				'number'     => 1,
			)
		);
		return $users[0] ?? null;
	}

	/**
	 * @param string[] $nums
	 * @return array<string, \WP_User> nº Scouts de España => usuario
	 */
	public static function por_socios( array $nums ): array {
		$nums = array_values( array_unique( array_filter( array_map( 'strval', $nums ) ) ) );
		if ( ! $nums ) {
			return array();
		}
		$out   = array();
		$users = get_users(
			array(
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery
					array(
						'key'     => self::META_SOCIO,
						'value'   => $nums,
						'compare' => 'IN',
					),
				),
			)
		);
		foreach ( $users as $user ) {
			$out[ self::socio( $user->ID ) ] = $user;
		}
		return $out;
	}

	/**
	 * @param string[] $nums
	 * @return array<string, \WP_User> nº Scouts de España => cuenta familiar que lo representa (la primera por usuario)
	 */
	public static function familiares_de( array $nums ): array {
		$nums = array_values( array_unique( array_filter( array_map( 'strval', $nums ) ) ) );
		if ( ! $nums ) {
			return array();
		}
		$out   = array();
		$users = get_users(
			array(
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery
					array(
						'key'     => self::META_REPRESENTADO,
						'value'   => $nums,
						'compare' => 'IN',
					),
				),
				'orderby'    => 'login',
			)
		);
		foreach ( $users as $user ) {
			foreach ( array_intersect( self::representados( $user->ID ), $nums ) as $n ) {
				$out[ $n ] ??= $user;
			}
		}
		return $out;
	}

	/** @return \WP_User[] Cuentas familiares que representan a este socio (la del primer tutor/a, «…T», primero). */
	public static function tutores_de( string $num_asde ): array {
		return get_users(
			array(
				'meta_key'   => self::META_REPRESENTADO, // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value' => $num_asde, // phpcs:ignore WordPress.DB.SlowDBQuery
				'orderby'    => 'login',
			)
		);
	}

	/**
	 * Cuentas familiares de una familia: las que representan a alguno de estos
	 * menores, sin repetir y con la del primer tutor/a (T) antes que la del segundo (T2).
	 *
	 * @param string[] $nums Nº Scouts de España.
	 * @return \WP_User[]
	 */
	public static function cuentas_familiares( array $nums ): array {
		$out = array();
		foreach ( $nums as $n ) {
			foreach ( self::tutores_de( (string) $n ) as $u ) {
				$out[ $u->ID ] = $u;
			}
		}
		$out = array_values( $out );
		usort( $out, static fn( \WP_User $a, \WP_User $b ) => strcmp( $a->user_login, $b->user_login ) );
		return $out;
	}

	public static function socio( int $user_id ): string {
		return (string) get_user_meta( $user_id, self::META_SOCIO, true );
	}

	/** @return string[] */
	public static function secciones( int $user_id ): array {
		return array_values( array_unique( array_map( 'strval', (array) get_user_meta( $user_id, self::META_SECCION ) ) ) );
	}

	/** @return string[] Nº Scouts de España de los representados. */
	public static function representados( int $user_id ): array {
		return array_values( array_unique( array_map( 'strval', (array) get_user_meta( $user_id, self::META_REPRESENTADO ) ) ) );
	}

	/** Añade valores a un meta de varias filas sin duplicar. */
	public static function anadir_meta_multiple( int $user_id, string $key, array $values ): void {
		$actuales = array_map( 'strval', (array) get_user_meta( $user_id, $key ) );
		foreach ( array_unique( array_map( 'strval', $values ) ) as $value ) {
			if ( ! in_array( $value, $actuales, true ) ) {
				add_user_meta( $user_id, $key, $value );
			}
		}
	}

	public static function quitar_representado( int $user_id, string $num_asde ): void {
		delete_user_meta( $user_id, self::META_REPRESENTADO, $num_asde );
	}

	/** @return array{asde:string, campo:string}|null */
	public static function email_origen( int $user_id ): ?array {
		$raw = (string) get_user_meta( $user_id, self::META_EMAIL_ORIGEN, true );
		if ( ! preg_match( '/^(\d{1,12}):([a-z0-9_]+)$/', $raw, $m ) || ! in_array( $m[2], self::CAMPOS_EMAIL, true ) ) {
			return null;
		}
		return array(
			'asde'  => $m[1],
			'campo' => $m[2],
		);
	}

	public static function fijar_email_origen( int $user_id, string $num_asde, string $campo ): void {
		if ( in_array( $campo, self::CAMPOS_EMAIL, true ) ) {
			update_user_meta( $user_id, self::META_EMAIL_ORIGEN, $num_asde . ':' . $campo );
		}
	}

	/** @return string[] Roles del área privada del usuario. */
	public static function roles_asde( \WP_User $user ): array {
		return array_values( array_intersect( Roles::ALL, (array) $user->roles ) );
	}

	public static function estado( \WP_User $user ): string {
		$estado = (string) get_user_meta( $user->ID, self::META_ESTADO, true );
		if ( '' !== $estado ) {
			return $estado;
		}
		return self::roles_asde( $user ) ? self::ACTIVO : self::DESACTIVADO;
	}

	/** ¿Ha activado alguna vez su cuenta (tiene contraseña propia)? */
	public static function ha_activado( \WP_User $user ): bool {
		return '' !== (string) get_user_meta( $user->ID, self::META_ACTIVADA_EN, true );
	}

	/**
	 * Desactiva una cuenta: le quita los roles del área, la deja «desactivada», invalida la
	 * invitación pendiente y cierra sus sesiones. Desde la v0.26.1 (petición del usuario) borra
	 * además lo que servía para entrar: la contraseña (se cambia por una aleatoria que nadie
	 * conoce), las contraseñas de aplicación de WordPress y la verificación en dos pasos y las
	 * llaves de acceso de Wordfence. También olvida que la activó: si vuelve, al darle acceso de
	 * nuevo le llega una invitación para elegir otra contraseña (AltaService).
	 *
	 * @return array{roles:string[], dos_pasos:bool, llaves:int} Roles quitados y lo quitado de Wordfence.
	 */
	public static function desactivar( \WP_User $user ): array {
		$roles = self::roles_asde( $user );
		foreach ( $roles as $r ) {
			$user->remove_role( $r );
		}
		update_user_meta( $user->ID, self::META_ESTADO, self::DESACTIVADO );
		delete_user_meta( $user->ID, self::META_ACTIVADA_EN );
		Invitacion::consumir( $user->ID );
		wp_set_password( wp_generate_password( 64, true, true ), $user->ID );
		if ( class_exists( '\WP_Application_Passwords' ) ) {
			\WP_Application_Passwords::delete_all_application_passwords( $user->ID );
		}
		$wordfence = Wordfence::quitar_acceso( $user );
		\WP_Session_Tokens::get_instance( $user->ID )->destroy_all();
		return array( 'roles' => $roles ) + $wordfence;
	}

	public static function etiqueta_estado( string $estado ): string {
		return array(
			self::INVITADO    => asde_t( 'Invitación pendiente' ),
			self::ACTIVO      => asde_t( 'Activo' ),
			self::DESACTIVADO => asde_t( 'Desactivado' ),
		)[ $estado ] ?? $estado;
	}

	public static function etiqueta_campo_email( string $campo ): string {
		return array(
			'email1'         => asde_t( 'Email de la ficha' ),
			'tutor1_email_c' => asde_t( 'Email del Tutor/a 1' ),
			'tutor2_email_c' => asde_t( 'Email del Tutor/a 2' ),
		)[ $campo ] ?? $campo;
	}
}
