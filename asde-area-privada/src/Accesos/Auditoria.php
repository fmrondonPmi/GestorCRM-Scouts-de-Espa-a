<?php
namespace AsdeBalears\AreaPrivada\Accesos;

use AsdeBalears\AreaPrivada\Domain\Grupo;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- tablas propias del plugin ({prefix}asde_*): no hay función de WordPress para ellas.

/**
 * Historial de accesos ({prefix}asde_auditoria_accesos, v0.53, doc 56): quién concedió o retiró qué, a quién y
 * cuándo. Solo se guardan identificadores y códigos de permiso (nunca datos personales); los nombres se
 * buscan al mostrarlo. **Nada se borra ni se edita**; no hay avisos cruzados, solo este historial.
 */
final class Auditoria {

	public const CONCEDE         = 'concede';
	public const REVOCA          = 'revoca';
	public const CADUCA          = 'caduca';
	public const ROL_ANADE       = 'rol_anade';
	public const ROL_QUITA       = 'rol_quita';
	public const ROL_RESTAURA    = 'rol_restaura';

	public const ACCIONES = array(
		self::CONCEDE      => 'Concede un permiso',
		self::REVOCA       => 'Retira un permiso',
		self::CADUCA       => 'Caduca un permiso',
		self::ROL_ANADE    => 'Añade un permiso a un rol',
		self::ROL_QUITA    => 'Quita un permiso a un rol',
		self::ROL_RESTAURA => 'Restaura los permisos de un rol',
	);

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'asde_auditoria_accesos';
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
				creado datetime NOT NULL,
				actor bigint(20) unsigned NOT NULL DEFAULT 0,
				accion varchar(16) NOT NULL,
				objetivo bigint(20) unsigned NOT NULL DEFAULT 0,
				rol varchar(32) NOT NULL DEFAULT '',
				permiso varchar(48) NOT NULL DEFAULT '',
				caduca date NULL,
				motivo varchar(255) NOT NULL DEFAULT '',
				PRIMARY KEY  (id),
				KEY grupo (grupo),
				KEY creado (creado),
				KEY objetivo (objetivo)
			) $charset;"
		);
	}

	/**
	 * @param array{objetivo?:int, rol?:string, permiso?:string, caduca?:?string, motivo?:string, actor?:int, grupo?:string} $d
	 */
	public static function registrar( string $accion, array $d ): void {
		global $wpdb;
		$wpdb->insert(
			self::table(),
			array(
				'grupo'    => (string) ( $d['grupo'] ?? Grupo::id() ),
				'creado'   => current_time( 'mysql', true ),
				'actor'    => (int) ( $d['actor'] ?? get_current_user_id() ),
				'accion'   => $accion,
				'objetivo' => (int) ( $d['objetivo'] ?? 0 ),
				'rol'      => (string) ( $d['rol'] ?? '' ),
				'permiso'  => (string) ( $d['permiso'] ?? '' ),
				'caduca'   => ! empty( $d['caduca'] ) ? $d['caduca'] : null,
				'motivo'   => mb_substr( (string) ( $d['motivo'] ?? '' ), 0, 255 ),
			)
		);
	}

	/**
	 * Filas del historial del grupo, de la más nueva a la más antigua.
	 *
	 * @param array{accion?:string, objetivo?:int, permiso?:string, desde?:string, hasta?:string} $f Filtros (vacío = sin filtrar); fechas Y-m-d (hora del sitio).
	 * @return array[]
	 */
	public static function listado( array $f = array(), int $limite = 2000 ): array {
		global $wpdb;
		$desde = ! empty( $f['desde'] ) ? get_gmt_from_date( $f['desde'] . ' 00:00:00' ) : '';
		$hasta = ! empty( $f['hasta'] ) ? get_gmt_from_date( $f['hasta'] . ' 23:59:59' ) : '';
		// Filtros opcionales como valores de una consulta fija: nada de montar SQL en variables.
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE grupo = %s'
				. " AND ( %s = '' OR accion = %s )"
				. ' AND ( %d = 0 OR objetivo = %d )'
				. " AND ( %s = '' OR permiso = %s )"
				. " AND ( %s = '' OR creado >= NULLIF( %s, '' ) )"
				. " AND ( %s = '' OR creado <= NULLIF( %s, '' ) )"
				. ' ORDER BY id DESC LIMIT %d',
				self::table(),
				Grupo::id(),
				(string) ( $f['accion'] ?? '' ),
				(string) ( $f['accion'] ?? '' ),
				(int) ( $f['objetivo'] ?? 0 ),
				(int) ( $f['objetivo'] ?? 0 ),
				(string) ( $f['permiso'] ?? '' ),
				(string) ( $f['permiso'] ?? '' ),
				$desde,
				$desde,
				$hasta,
				$hasta,
				$limite
			),
			ARRAY_A
		);
	}
}
