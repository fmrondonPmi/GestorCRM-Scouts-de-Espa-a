<?php
namespace AsdeBalears\AreaPrivada\Audit;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- tablas propias del plugin ({prefix}asde_*): no hay función de WordPress para ellas y los datos cambian en cada petición, no se cachean.

/**
 * Registro de auditoría del área privada (docs/08). Guarda identificadores,
 * nunca datos personales en claro.
 */
final class Logger {

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'asde_auditoria';
	}

	public static function install_table(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE $table (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				creado datetime NOT NULL,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				accion varchar(64) NOT NULL,
				objeto varchar(64) NOT NULL DEFAULT '',
				objeto_id varchar(64) NOT NULL DEFAULT '',
				detalles text NULL,
				ip varchar(45) NOT NULL DEFAULT '',
				PRIMARY KEY  (id),
				KEY creado (creado),
				KEY user_id (user_id),
				KEY accion (accion)
			) $charset;"
		);
	}

	/** @param array<string, mixed> $detalles Solo identificadores y valores no sensibles. */
	public static function log( string $accion, string $objeto = '', string $objeto_id = '', array $detalles = array() ): void {
		global $wpdb;
		$ip = (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$wpdb->insert(
			self::table(),
			array(
				'creado'    => current_time( 'mysql', true ),
				'user_id'   => get_current_user_id(),
				'accion'    => $accion,
				'objeto'    => $objeto,
				'objeto_id' => $objeto_id,
				'detalles'  => $detalles ? wp_json_encode( $detalles ) : null,
				'ip'        => filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '',
			)
		);
	}
}
