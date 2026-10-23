<?php
namespace AsdeBalears\AreaPrivada\Accesos;

use AsdeBalears\AreaPrivada\Domain\Grupo;
use AsdeBalears\AreaPrivada\Domain\Grupos;
use AsdeBalears\AreaPrivada\Usuarios\Usuarios;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- tablas propias del plugin ({prefix}asde_*): no hay función de WordPress para ellas.

/**
 * Permisos sueltos de cada persona ({prefix}asde_permisos_usuario, v0.53, doc 56): lo que Coordinación,
 * Secretaría o Tesorería conceden a un scouter **además** de lo que le da su rol.
 *
 * Permisos efectivos = los del rol (con los cambios de `PermisosRol`) + las concesiones activas − las retiradas.
 * Una concesión está `activo` hasta que alguien la retira (`revocado`) o llega su fecha (`caducado`); en
 * cuanto vence deja de valer (el filtro mira la fecha en cada petición) y la tarea diaria, o la propia
 * pantalla, la marca y deja constancia en el historial.
 *
 * El filtro de `user_has_cap` de aquí es el único sitio que suma las concesiones: el resto del plugin sigue
 * llamando a `current_user_can()` / `user_can()` (en el navegador, `assets/js/accesos.js` hace el mismo cálculo
 * para enseñar el resultado antes de guardar).
 */
final class Concesiones {

	public const ACTIVO   = 'activo';
	public const REVOCADO = 'revocado';
	public const CADUCADO = 'caducado';

	/** Opción: ¿hay alguna concesión activa en el sitio? (si no, el filtro no consulta la base de datos). */
	private const BANDERA = 'asde_ap_hay_concesiones';

	/** @var array<int, string[]> Permisos concedidos y vigentes por usuario, en esta petición. */
	private static array $cache = array();

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'asde_permisos_usuario';
	}

	public static function install_table(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$t       = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE $t (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				grupo varchar(32) NOT NULL DEFAULT '',
				user_id bigint(20) unsigned NOT NULL,
				permiso varchar(48) NOT NULL,
				concedido_por bigint(20) unsigned NOT NULL DEFAULT 0,
				concedido datetime NOT NULL,
				caduca date NULL,
				motivo varchar(255) NOT NULL DEFAULT '',
				estado varchar(10) NOT NULL DEFAULT 'activo',
				cerrado_por bigint(20) unsigned NOT NULL DEFAULT 0,
				cerrado datetime NULL,
				PRIMARY KEY  (id),
				KEY user_id (user_id),
				KEY grupo (grupo),
				KEY estado (estado)
			) $charset;"
		);
	}

	public static function register(): void {
		add_filter( 'user_has_cap', array( self::class, 'sumar_concesiones' ), 10, 4 );
		// Si a alguien le cambian el rol o la cuenta desaparece, sus permisos sueltos se revisan.
		add_action( 'set_user_role', array( self::class, 'revisar_persona' ) );
		add_action( 'add_user_role', array( self::class, 'revisar_persona' ) );
		add_action( 'remove_user_role', array( self::class, 'revisar_persona' ) );
		add_action( 'delete_user', array( self::class, 'olvidar_usuario' ) );
	}

	/** Olvida lo calculado en esta petición y recalcula si queda alguna concesión activa. */
	public static function olvidar(): void {
		global $wpdb;
		self::$cache = array();
		PermisosRol::olvidar();
		$hay         = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE estado = %s', self::table(), self::ACTIVO ) );
		update_option( self::BANDERA, $hay ? '1' : '0', true );
	}

	private static function hoy(): string {
		return wp_date( 'Y-m-d' );
	}

	/** @return string[] Permisos concedidos a una persona y vigentes hoy (sin mirar requisitos). */
	public static function vigentes( int $user_id ): array {
		global $wpdb;
		if ( isset( self::$cache[ $user_id ] ) ) {
			return self::$cache[ $user_id ];
		}
		$filas = (array) $wpdb->get_col(
			$wpdb->prepare(
				'SELECT permiso FROM %i WHERE user_id = %d AND estado = %s AND ( caduca IS NULL OR caduca >= %s )',
				self::table(),
				$user_id,
				self::ACTIVO,
				self::hoy()
			)
		);
		return self::$cache[ $user_id ] = array_values( array_filter( $filas, array( Catalogo::class, 'existe' ) ) );
	}

	/**
	 * Filtro user_has_cap: suma a las capabilities del rol los permisos concedidos que siguen vigentes y cuyo
	 * «ver» se tiene. No hace falta repetirlo en cada comprobación del plugin.
	 *
	 * @param array<string, bool> $todas
	 * @return array<string, bool>
	 */
	public static function sumar_concesiones( $todas, $pedidas, $args, $user ) {
		if ( ! is_array( $todas ) || ! $user instanceof \WP_User || ! $user->ID || '1' !== get_option( self::BANDERA, '0' ) ) {
			return $todas;
		}
		$pendientes = self::vigentes( (int) $user->ID );
		do {
			$entra = false;
			foreach ( $pendientes as $k => $p ) {
				if ( Catalogo::cumple_requisitos( $p, $todas ) ) {
					$todas[ $p ] = true;
					unset( $pendientes[ $k ] );
					$entra = true;
				}
			}
		} while ( $entra && $pendientes );
		return $todas;
	}

	/** @return array<string, bool> Permisos que le da el rol (o los roles) a una persona, con los cambios de la matriz. */
	public static function del_rol( \WP_User $user ): array {
		$cambios = PermisosRol::cambios();
		$tiene   = array();
		foreach ( array_intersect( Catalogo::ROLES, (array) $user->roles ) as $rol ) {
			foreach ( array_keys( array_filter( PermisosRol::de_rol( $rol, $cambios ) ) ) as $p ) {
				$tiene[ $p ] = true;
			}
		}
		return $tiene;
	}

	/**
	 * Qué tiene una persona y de dónde le viene, para la pantalla «Accesos por persona».
	 *
	 * @return array<string, array{origen:string, caduca:?string, id:int, motivo:string, por:int}> permiso => datos (solo los que tiene);
	 *         origen = 'rol' o 'extra'
	 */
	public static function mapa( \WP_User $user ): array {
		$out = array();
		foreach ( array_keys( self::del_rol( $user ) ) as $p ) {
			$out[ $p ] = array( 'origen' => 'rol', 'caduca' => null, 'id' => 0, 'motivo' => '', 'por' => 0 );
		}
		foreach ( self::activas_de( (int) $user->ID ) as $c ) {
			if ( isset( $out[ $c['permiso'] ] ) ) {
				continue;
			}
			$out[ $c['permiso'] ] = array(
				'origen' => 'extra',
				'caduca' => $c['caduca'],
				'id'     => (int) $c['id'],
				'motivo' => $c['motivo'],
				'por'    => (int) $c['concedido_por'],
			);
		}
		return $out;
	}

	/** @return array[] Concesiones activas y vigentes de una persona. */
	public static function activas_de( int $user_id ): array {
		global $wpdb;
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE user_id = %d AND estado = %s AND ( caduca IS NULL OR caduca >= %s ) ORDER BY id',
				self::table(),
				$user_id,
				self::ACTIVO,
				self::hoy()
			),
			ARRAY_A
		);
	}

	/** @return array<int, int> user_id => nº de concesiones vigentes, en el grupo de trabajo. */
	public static function contar_por_persona(): array {
		global $wpdb;
		$out = array();
		foreach ( (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT user_id, COUNT(*) AS n FROM %i WHERE grupo = %s AND estado = %s AND ( caduca IS NULL OR caduca >= %s ) GROUP BY user_id',
				self::table(),
				Grupo::id(),
				self::ACTIVO,
				self::hoy()
			),
			ARRAY_A
		) as $f ) {
			$out[ (int) $f['user_id'] ] = (int) $f['n'];
		}
		return $out;
	}

	/** @return array<string, mixed>|null */
	public static function obtener( int $id ): ?array {
		global $wpdb;
		$f = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d AND grupo = %s', self::table(), $id, Grupo::id() ), ARRAY_A );
		return $f ?: null;
	}

	/** ¿Puede recibir permisos sueltos? Del grupo de trabajo, con un rol de gestión y la cuenta activa. */
	public static function es_destino( \WP_User $user ): bool {
		if ( ! array_intersect( Catalogo::ROLES_DESTINO, (array) $user->roles ) || Usuarios::DESACTIVADO === Usuarios::estado( $user ) ) {
			return false;
		}
		$grupo = Grupos::de_usuario( $user );
		return $grupo && (string) $grupo['id'] === Grupo::id();
	}

	/**
	 * Concede un permiso suelto (o renueva la caducidad de uno vigente).
	 *
	 * Reglas: lo hace una cuenta con `asde_accesos`; **nunca a sí misma**; solo a personas del grupo con rol de
	 * gestión; solo permisos del catálogo que la persona no tenga ya por su rol; con su «ver» delante; los
	 * sensibles llevan motivo; la fecha, si la hay, no puede ser pasada.
	 *
	 * @param string|null $caduca Y-m-d o null (sin caducidad)
	 * @return true|\WP_Error
	 */
	public static function conceder( \WP_User $actor, int $user_id, string $permiso, ?string $caduca, string $motivo ) {
		global $wpdb;
		if ( ! user_can( $actor, Catalogo::CAP_GESTION ) ) {
			return new \WP_Error( 'asde_acc', asde_t( 'No tienes permiso para repartir accesos.' ) );
		}
		if ( $actor->ID === $user_id ) {
			return new \WP_Error( 'asde_acc', asde_t( 'No puedes concederte permisos a ti mismo: tiene que hacerlo otra persona de Coordinación, Secretaría o Tesorería.' ) );
		}
		if ( ! Catalogo::existe( $permiso ) ) {
			return new \WP_Error( 'asde_acc', asde_t( 'Ese permiso no se puede conceder.' ) );
		}
		$destino = get_userdata( $user_id );
		if ( ! $destino || ! self::es_destino( $destino ) ) {
			return new \WP_Error( 'asde_acc', asde_t( 'Esa persona no puede recibir permisos sueltos: tiene que ser de gestión, de tu grupo y con la cuenta activa.' ) );
		}
		$motivo = trim( mb_substr( sanitize_text_field( $motivo ), 0, 255 ) );
		if ( Catalogo::sensible( $permiso ) && '' === $motivo ) {
			return new \WP_Error( 'asde_acc', asde_t( 'Un permiso sensible necesita un motivo, para que quede en el historial.' ) );
		}
		$caduca = '' === (string) $caduca ? null : (string) $caduca;
		if ( null !== $caduca ) {
			$d = \DateTime::createFromFormat( 'Y-m-d', $caduca );
			if ( ! $d || $d->format( 'Y-m-d' ) !== $caduca ) {
				return new \WP_Error( 'asde_acc', asde_t( 'La fecha de caducidad no es válida.' ) );
			}
			if ( $caduca < self::hoy() ) {
				return new \WP_Error( 'asde_acc', asde_t( 'La fecha de caducidad ya ha pasado.' ) );
			}
		}
		$rol = self::del_rol( $destino );
		if ( ! empty( $rol[ $permiso ] ) ) {
			return new \WP_Error( 'asde_acc', asde_t( 'Esa persona ya tiene ese permiso por su rol.' ) );
		}
		$tiene = $rol;
		foreach ( self::vigentes( $user_id ) as $p ) {
			$tiene[ $p ] = true;
		}
		if ( ! Catalogo::cumple_requisitos( $permiso, $tiene ) ) {
			/* translators: %s: lo que hace falta antes */
			return new \WP_Error( 'asde_acc', sprintf( asde_t( 'Antes necesita: %s.' ), Catalogo::texto_requisitos( $permiso ) ) );
		}

		$ahora   = current_time( 'mysql', true );
		$vigente = $wpdb->get_row( $wpdb->prepare( 'SELECT id FROM %i WHERE user_id = %d AND permiso = %s AND estado = %s', self::table(), $user_id, $permiso, self::ACTIVO ), ARRAY_A );
		if ( $vigente ) {
			$wpdb->update( self::table(), array( 'caduca' => $caduca, 'motivo' => $motivo, 'concedido_por' => $actor->ID, 'concedido' => $ahora ), array( 'id' => (int) $vigente['id'] ) );
		} else {
			$wpdb->insert(
				self::table(),
				array(
					'grupo'         => Grupo::id(),
					'user_id'       => $user_id,
					'permiso'       => $permiso,
					'concedido_por' => $actor->ID,
					'concedido'     => $ahora,
					'caduca'        => $caduca,
					'motivo'        => $motivo,
					'estado'        => self::ACTIVO,
				)
			);
		}
		Auditoria::registrar( Auditoria::CONCEDE, array( 'actor' => $actor->ID, 'objetivo' => $user_id, 'permiso' => $permiso, 'caduca' => $caduca, 'motivo' => $motivo ) );
		self::olvidar();
		return true;
	}

	/**
	 * Retira una concesión. Si otra se apoyaba en ella (una acción que necesitaba ese «ver»), se retira también.
	 *
	 * @return true|\WP_Error
	 */
	public static function revocar( \WP_User $actor, int $id, string $motivo = '' ) {
		global $wpdb;
		if ( ! user_can( $actor, Catalogo::CAP_GESTION ) ) {
			return new \WP_Error( 'asde_acc', asde_t( 'No tienes permiso para repartir accesos.' ) );
		}
		$c = self::obtener( $id );
		if ( ! $c || self::ACTIVO !== $c['estado'] ) {
			return new \WP_Error( 'asde_acc', asde_t( 'Esa concesión ya no está activa.' ) );
		}
		self::cerrar( $c, self::REVOCADO, $actor->ID, Auditoria::REVOCA, $motivo );
		self::depurar_persona( (int) $c['user_id'], $actor->ID, Auditoria::REVOCA, self::REVOCADO );
		self::olvidar();
		return true;
	}

	/** Marca una concesión como cerrada y deja constancia. @param array<string, mixed> $c */
	private static function cerrar( array $c, string $estado, int $por, string $accion, string $motivo ): void {
		global $wpdb;
		$wpdb->update( self::table(), array( 'estado' => $estado, 'cerrado_por' => $por, 'cerrado' => current_time( 'mysql', true ) ), array( 'id' => (int) $c['id'] ) );
		Auditoria::registrar(
			$accion,
			array(
				'actor'    => $por,
				'grupo'    => (string) $c['grupo'],
				'objetivo' => (int) $c['user_id'],
				'permiso'  => (string) $c['permiso'],
				'caduca'   => $c['caduca'],
				'motivo'   => $motivo,
			)
		);
	}

	/** Cierra las concesiones de una persona que se han quedado sin su «ver» (por rol o por otra concesión que se fue). */
	private static function depurar_persona( int $user_id, int $por, string $accion, string $estado ): void {
		$user = get_userdata( $user_id );
		$base = $user ? self::del_rol( $user ) : array();
		$ok   = Catalogo::depurar( $base + array_fill_keys( array_column( self::activas_de( $user_id ), 'permiso' ), true ) );
		foreach ( self::activas_de( $user_id ) as $c ) {
			if ( empty( $ok[ $c['permiso'] ] ) ) {
				self::cerrar( $c, $estado, $por, $accion, asde_t( 'Se retira porque le falta el permiso del que depende.' ) );
			}
		}
	}

	/**
	 * Marca las concesiones cuya fecha ya ha pasado (y las que dependían de ellas) y las anota en el historial.
	 * Lo hace la tarea diaria; también al abrir «Gestión de accesos», por si el cron no ha corrido.
	 *
	 * @return int Cuántas se han cerrado.
	 */
	public static function caducar_vencidas(): int {
		global $wpdb;
		$vencidas = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE estado = %s AND caduca IS NOT NULL AND caduca < %s', self::table(), self::ACTIVO, self::hoy() ), ARRAY_A );
		$n        = 0;
		foreach ( $vencidas as $c ) {
			self::$cache = array();
			self::cerrar( $c, self::CADUCADO, 0, Auditoria::CADUCA, '' );
			++$n;
		}
		foreach ( array_unique( array_map( 'intval', array_column( $vencidas, 'user_id' ) ) ) as $uid ) {
			self::depurar_persona( $uid, 0, Auditoria::CADUCA, self::CADUCADO );
		}
		if ( $n ) {
			self::olvidar();
		}
		return $n;
	}

	/** Si cambian los roles de una persona, las concesiones que ya no le caben (por ejemplo, ya lo trae su nuevo rol) se mantienen; las huérfanas de «ver» se cierran. */
	public static function revisar_persona( int $user_id ): void {
		self::depurar_persona( $user_id, 0, Auditoria::REVOCA, self::REVOCADO );
		self::olvidar();
	}

	/** Vacía los permisos sueltos de personas que dejan de existir. */
	public static function olvidar_usuario( int $user_id ): void {
		global $wpdb;
		$wpdb->delete( self::table(), array( 'user_id' => $user_id ) );
		self::olvidar();
	}
}
