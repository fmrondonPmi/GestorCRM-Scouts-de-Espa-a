<?php
namespace AsdeBalears\AreaPrivada\Menus;

use AsdeBalears\AreaPrivada\Domain\Grupo;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- tablas propias del plugin ({prefix}asde_*): no hay función de WordPress para ellas y los datos cambian en cada petición, no se cachean.

/**
 * Las confirmaciones de los avisos de alérgenos de un menú ({prefix}asde_evento_menu_avisos, doc 49, B3, P-191).
 *
 * Solo se guarda qué plato, qué alérgeno, quién confirma, cuándo y la alternativa (una de una lista cerrada). **Sin nombres de
 * asistentes, sin diagnósticos y sin nº de afectados**: la salud no se guarda nunca, se calcula en vivo cada vez que se abre el menú.
 */
final class AvisosTable {

	/** Alternativa elegida al confirmar (lista cerrada: nada de texto libre con datos de salud). */
	public const ALTERNATIVAS = array(
		'racion'  => 'Se prepara una ración aparte sin el alérgeno',
		'cambio'  => 'Se cambia el ingrediente',
		'plato'   => 'Se sustituye el plato',
		'familia' => 'Se habla con la familia',
	);

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'asde_evento_menu_avisos';
	}

	public static function install_table(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$t       = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE $t (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				grupo varchar(3) NOT NULL,
				evento_id bigint(20) unsigned NOT NULL,
				plato_id bigint(20) unsigned NOT NULL,
				alergeno varchar(20) NOT NULL,
				alternativa varchar(10) NOT NULL,
				por bigint(20) unsigned NOT NULL DEFAULT 0,
				creado datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY plato_alergeno (plato_id, alergeno),
				KEY grupo_evento (grupo, evento_id)
			) $charset;"
		);
	}

	/** @return array<string, array> «plato|alérgeno» => confirmación */
	public static function de_evento( int $evento_id ): array {
		global $wpdb;
		$filas = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE evento_id = %d AND grupo = %s', self::table(), $evento_id, Grupo::numero() ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$out   = array();
		foreach ( $filas as $f ) {
			$f['id']                                  = (int) $f['id'];
			$f['por']                                 = (int) $f['por'];
			$out[ $f['plato_id'] . '|' . $f['alergeno'] ] = $f;
		}
		return $out;
	}

	public static function confirmar( int $evento_id, int $plato_id, string $alergeno, string $alternativa ): void {
		global $wpdb;
		$wpdb->query( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				'INSERT INTO %i (grupo, evento_id, plato_id, alergeno, alternativa, por, creado) VALUES (%s, %d, %d, %s, %s, %d, %s) ON DUPLICATE KEY UPDATE alternativa = VALUES(alternativa), por = VALUES(por), creado = VALUES(creado)',
				self::table(),
				Grupo::numero(),
				$evento_id,
				$plato_id,
				$alergeno,
				$alternativa,
				get_current_user_id(),
				current_time( 'mysql', true )
			)
		);
	}

	public static function quitar( int $evento_id, int $plato_id, string $alergeno ): bool {
		global $wpdb;
		return (bool) $wpdb->delete( self::table(), array( 'evento_id' => $evento_id, 'plato_id' => $plato_id, 'alergeno' => $alergeno, 'grupo' => Grupo::numero() ) ); // phpcs:ignore WordPress.DB
	}

	public static function borrar_plato( int $plato_id ): void {
		global $wpdb;
		$wpdb->delete( self::table(), array( 'plato_id' => $plato_id, 'grupo' => Grupo::numero() ) ); // phpcs:ignore WordPress.DB
	}

	public static function borrar_evento( int $evento_id ): void {
		global $wpdb;
		$wpdb->delete( self::table(), array( 'evento_id' => $evento_id, 'grupo' => Grupo::numero() ) ); // phpcs:ignore WordPress.DB
	}
}
