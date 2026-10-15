<?php
namespace AsdeBalears\AreaPrivada\Eventos;

use AsdeBalears\AreaPrivada\Domain\Grupo;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- tablas propias del plugin ({prefix}asde_*): no hay función de WordPress para ellas y los datos cambian en cada petición, no se cachean.

/**
 * Partidas del presupuesto de un evento ({prefix}asde_evento_partidas, doc 43,
 * apartado 4). En la v0.35, solo lo previsto (gastos e ingresos); los gastos reales,
 * con su justificante, llegan en la v0.36.
 *
 * Cada gasto dice cómo se reparte entre los participantes (Presupuesto::REPARTOS).
 * Un ingreso (subvención, aportación del grupo) se resta de lo común.
 */
final class PartidasTable {

	public const GASTO   = 'gasto';
	public const INGRESO = 'ingreso';

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'asde_evento_partidas';
	}

	public static function install_table(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$t       = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE $t (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				evento_id bigint(20) unsigned NOT NULL,
				grupo varchar(3) NOT NULL,
				clase varchar(10) NOT NULL DEFAULT 'previsto',
				tipo varchar(10) NOT NULL DEFAULT 'gasto',
				concepto varchar(160) NOT NULL,
				reparto varchar(30) NOT NULL DEFAULT 'total',
				seccion varchar(20) NOT NULL DEFAULT '',
				importe decimal(10,2) NOT NULL DEFAULT 0,
				creado datetime NOT NULL,
				creado_por bigint(20) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				KEY evento (evento_id),
				KEY grupo (grupo)
			) $charset;"
		);
	}

	/** @return int Id de la partida. */
	public static function anadir( int $evento_id, string $tipo, string $concepto, string $reparto, string $seccion, float $importe ): int {
		global $wpdb;
		$wpdb->insert(
			self::table(),
			array(
				'evento_id'  => $evento_id,
				'grupo'      => Grupo::numero(),
				'clase'      => 'previsto',
				'tipo'       => self::INGRESO === $tipo ? self::INGRESO : self::GASTO,
				'concepto'   => mb_substr( sanitize_text_field( $concepto ), 0, 160 ),
				'reparto'    => $reparto,
				'seccion'    => $seccion,
				'importe'    => round( $importe, 2 ),
				'creado'     => current_time( 'mysql', true ),
				'creado_por' => get_current_user_id(),
			)
		);
		return (int) $wpdb->insert_id;
	}

	public static function quitar( int $id, int $evento_id ): bool {
		global $wpdb;
		return (bool) $wpdb->delete(
			self::table(),
			array(
				'id'        => $id,
				'evento_id' => $evento_id,
				'grupo'     => Grupo::numero(),
			)
		);
	}

	/** @return array[] Partidas previstas del evento: primero los gastos, en el orden en que se añadieron. */
	public static function previstas( int $evento_id ): array {
		global $wpdb;
		$filas = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE evento_id = %d AND grupo = %s AND clase = 'previsto' ORDER BY id ASC", self::table(), $evento_id, Grupo::numero() ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$filas = array_map( array( self::class, 'preparar' ), $filas );
		usort( $filas, static fn( $a, $b ) => ( self::INGRESO === $a['tipo'] ) <=> ( self::INGRESO === $b['tipo'] ) ?: $a['id'] <=> $b['id'] );
		return $filas;
	}

	public static function borrar_evento( int $evento_id ): void {
		global $wpdb;
		$wpdb->delete(
			self::table(),
			array(
				'evento_id' => $evento_id,
				'grupo'     => Grupo::numero(),
			)
		);
	}

	private static function preparar( array $f ): array {
		$f['id']        = (int) $f['id'];
		$f['evento_id'] = (int) $f['evento_id'];
		$f['importe']   = (float) $f['importe'];
		return $f;
	}
}
