<?php
namespace AsdeBalears\AreaPrivada\Accesos;

use AsdeBalears\AreaPrivada\Install\Roles;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- tablas propias del plugin ({prefix}asde_*): no hay función de WordPress para ellas.

/**
 * Permisos de cada rol ({prefix}asde_permisos_rol, v0.53, doc 56).
 *
 * Los permisos de fábrica de un rol son los de `Install\Roles::definitions()`. Esta tabla solo guarda lo que
 * los gestores han **cambiado** respecto a ellos (una fila por rol y permiso: 1 = lo tiene, 0 = no lo tiene);
 * si una casilla vuelve al valor de fábrica, la fila se borra. Los cambios se aplican a las capabilities del
 * rol de WordPress (así `current_user_can()` sigue siendo la única comprobación) y `Roles::install()` los
 * vuelve a aplicar tras cada actualización, para que una versión nueva no deshaga lo que se eligió.
 *
 * Los roles de WordPress son de todo el sitio: lo que se cambia aquí vale para todos los grupos de la instalación.
 */
final class PermisosRol {

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'asde_permisos_rol';
	}

	public static function install_table(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$t       = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE $t (
				rol varchar(32) NOT NULL,
				permiso varchar(48) NOT NULL,
				valor tinyint(1) NOT NULL DEFAULT 0,
				cambiado_por bigint(20) unsigned NOT NULL DEFAULT 0,
				cambiado datetime NOT NULL,
				PRIMARY KEY  (rol,permiso)
			) $charset;"
		);
	}

	/** @return array<string, array<string, bool>> rol => [permiso => valor cambiado] */
	public static function cambios(): array {
		global $wpdb;
		$out = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT rol, permiso, valor FROM %i', self::table() ), ARRAY_A ) as $f ) {
			$out[ $f['rol'] ][ $f['permiso'] ] = (bool) $f['valor'];
		}
		return $out;
	}

	/** Permisos de fábrica de un rol (solo los del catálogo). @return array<string, bool> */
	public static function de_fabrica( string $rol ): array {
		$caps = Roles::definitions()[ $rol ][1] ?? array();
		$out  = array();
		foreach ( Catalogo::todos() as $p ) {
			$out[ $p ] = ! empty( $caps[ $p ] );
		}
		return $out;
	}

	/** Permisos de un rol: los de fábrica con los cambios encima. @return array<string, bool> */
	public static function de_rol( string $rol, ?array $cambios = null ): array {
		$tiene   = self::de_fabrica( $rol );
		$cambios = ( $cambios ?? self::cambios() )[ $rol ] ?? array();
		foreach ( $cambios as $p => $v ) {
			if ( isset( $tiene[ $p ] ) ) {
				$tiene[ $p ] = $v;
			}
		}
		return $tiene;
	}

	/** ¿Es distinta del valor de fábrica? */
	public static function cambiado( string $rol, string $permiso, ?array $cambios = null ): bool {
		return isset( ( $cambios ?? self::cambios() )[ $rol ][ $permiso ] );
	}

	/**
	 * Aplica los cambios a los roles de WordPress. Se llama desde `Roles::install()` (después de poner los de
	 * fábrica) y al guardar la matriz.
	 */
	public static function aplicar( ?array $cambios = null ): void {
		foreach ( ( $cambios ?? self::cambios() ) as $rol => $permisos ) {
			$wp = get_role( $rol );
			if ( ! $wp || ! in_array( $rol, Catalogo::ROLES, true ) ) {
				continue;
			}
			foreach ( $permisos as $p => $valor ) {
				if ( ! Catalogo::existe( $p ) ) {
					continue;
				}
				$valor ? $wp->add_cap( $p ) : $wp->remove_cap( $p );
			}
		}
	}

	/** Pone en el rol de WordPress el valor efectivo de un permiso (también cuando vuelve al de fábrica). */
	private static function fijar_en_wp( string $rol, string $permiso, bool $valor ): void {
		$wp = get_role( $rol );
		if ( $wp ) {
			$valor ? $wp->add_cap( $permiso ) : $wp->remove_cap( $permiso );
		}
	}

	/**
	 * Guarda la matriz que ha enviado un gestor.
	 *
	 * Reglas: (1) solo roles y permisos del catálogo; (2) **nadie añade un permiso a un rol que él mismo
	 * tiene** (sería dárselo a sí mismo; sí puede quitarlo); (3) toda acción exige el de ver: al quitar un «ver» se
	 * quitan las acciones que se apoyaban en él, y una acción sin «ver» no se añade.
	 *
	 * @param array<string, array<string, bool>> $deseado rol => [permiso => true/false] (solo lo enviado)
	 * @return array{cambios:int, avisos:string[]}
	 */
	public static function guardar( array $deseado, \WP_User $actor ): array {
		global $wpdb;
		$avisos = array();
		$n      = 0;
		$mis    = array_values( array_intersect( Catalogo::ROLES, (array) $actor->roles ) );
		$cambio = self::cambios();

		foreach ( Catalogo::ROLES as $rol ) {
			if ( ! isset( $deseado[ $rol ] ) ) {
				continue;
			}
			$antes   = self::de_rol( $rol, $cambio );
			$despues = $antes;
			foreach ( Catalogo::todos() as $p ) {
				if ( isset( $deseado[ $rol ][ $p ] ) ) {
					$despues[ $p ] = (bool) $deseado[ $rol ][ $p ];
				}
			}
			// (2) No se añade nada al rol propio.
			if ( in_array( $rol, $mis, true ) && ! $actor->has_cap( 'manage_options' ) ) {
				foreach ( Catalogo::todos() as $p ) {
					if ( $despues[ $p ] && ! $antes[ $p ] ) {
						$despues[ $p ] = false;
						/* translators: 1: permiso, 2: rol */
						$avisos[] = sprintf( asde_t( 'No puedes añadir «%1$s» al rol %2$s: es el tuyo y sería darte el permiso a ti mismo.' ), Catalogo::descripcion( $p ), Roles::label( $rol ) );
					}
				}
			}
			// (3) Lo que se añade sin su «ver» no entra; lo que se queda sin «ver» se va.
			foreach ( Catalogo::todos() as $p ) {
				if ( $despues[ $p ] && ! $antes[ $p ] ) {
					$prueba = Catalogo::depurar( array_filter( $despues ) );
					if ( empty( $prueba[ $p ] ) ) {
						$despues[ $p ] = false;
						/* translators: 1: permiso, 2: lo que hace falta antes */
						$avisos[] = sprintf( asde_t( '«%1$s» necesita antes: %2$s.' ), Catalogo::descripcion( $p ), Catalogo::texto_requisitos( $p ) );
					}
				}
			}
			$depurado = Catalogo::depurar( array_filter( $despues ) );
			foreach ( Catalogo::todos() as $p ) {
				$despues[ $p ] = ! empty( $depurado[ $p ] );
			}

			$fabrica = self::de_fabrica( $rol );
			foreach ( Catalogo::todos() as $p ) {
				if ( $despues[ $p ] === $antes[ $p ] ) {
					continue;
				}
				if ( $despues[ $p ] === $fabrica[ $p ] ) {
					$wpdb->delete( self::table(), array( 'rol' => $rol, 'permiso' => $p ) );
				} else {
					$wpdb->replace(
						self::table(),
						array(
							'rol'          => $rol,
							'permiso'      => $p,
							'valor'        => $despues[ $p ] ? 1 : 0,
							'cambiado_por' => $actor->ID,
							'cambiado'     => current_time( 'mysql', true ),
						)
					);
				}
				self::fijar_en_wp( $rol, $p, $despues[ $p ] );
				Auditoria::registrar(
					$despues[ $p ] ? Auditoria::ROL_ANADE : Auditoria::ROL_QUITA,
					array(
						'actor'   => $actor->ID,
						'rol'     => $rol,
						'permiso' => $p,
					)
				);
				++$n;
			}
		}
		Concesiones::olvidar();
		return array( 'cambios' => $n, 'avisos' => $avisos );
	}

	/**
	 * Devuelve un rol a sus permisos de fábrica.
	 *
	 * @return int Casillas que han cambiado, o -1 si no se puede: restaurar el rol propio volvería a poner
	 *             permisos que se habían quitado (darse permisos a sí mismo).
	 */
	public static function restaurar( string $rol, \WP_User $actor ): int {
		global $wpdb;
		if ( ! in_array( $rol, Catalogo::ROLES, true ) ) {
			return 0;
		}
		$cambios = self::cambios()[ $rol ] ?? array();
		$fabrica = self::de_fabrica( $rol );
		$propio  = in_array( $rol, (array) $actor->roles, true ) && ! $actor->has_cap( 'manage_options' );
		foreach ( $cambios as $p => $valor ) {
			if ( $propio && ! $valor && ! empty( $fabrica[ $p ] ) ) {
				return -1;
			}
		}
		foreach ( $cambios as $p => $valor ) {
			self::fijar_en_wp( $rol, $p, $fabrica[ $p ] ?? false );
		}
		$wpdb->delete( self::table(), array( 'rol' => $rol ) );
		if ( $cambios ) {
			Auditoria::registrar( Auditoria::ROL_RESTAURA, array( 'actor' => $actor->ID, 'rol' => $rol ) );
		}
		Concesiones::olvidar();
		return count( $cambios );
	}
}
