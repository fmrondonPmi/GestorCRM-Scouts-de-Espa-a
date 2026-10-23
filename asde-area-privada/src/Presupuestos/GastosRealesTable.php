<?php
namespace AsdeBalears\AreaPrivada\Presupuestos;

use AsdeBalears\AreaPrivada\Domain\Grupo;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- tablas propias del plugin ({prefix}asde_*): no hay función de WordPress para ellas y los datos cambian en cada petición, no se cachean.

/**
 * Gasto real imputado a una partida de gasto del presupuesto ({prefix}asde_presupuesto_gastos,
 * v0.55, doc 57 fase 3): «presupuestado / gastado / queda». Solo importes y conceptos: sin datos personales.
 */
final class GastosRealesTable {

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'asde_presupuesto_gastos';
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
				ronda varchar(9) NOT NULL,
				seccion varchar(20) NOT NULL,
				partida_id bigint(20) unsigned NOT NULL,
				fecha date NOT NULL,
				concepto varchar(160) NOT NULL DEFAULT '',
				importe decimal(10,2) NOT NULL DEFAULT 0,
				creado datetime NOT NULL,
				creado_por bigint(20) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				KEY ambito (grupo,ronda,seccion)
			) $charset;"
		);
	}

	public static function anadir( string $ronda, string $seccion, int $partida_id, string $fecha, string $concepto, float $importe ): int {
		global $wpdb;
		$wpdb->insert(
			self::table(),
			array(
				'grupo'      => Grupo::numero(),
				'ronda'      => $ronda,
				'seccion'    => $seccion,
				'partida_id' => $partida_id,
				'fecha'      => $fecha,
				'concepto'   => mb_substr( sanitize_text_field( $concepto ), 0, 160 ),
				'importe'    => round( $importe, 2 ),
				'creado'     => current_time( 'mysql', true ),
				'creado_por' => get_current_user_id(),
			)
		);
		return (int) $wpdb->insert_id;
	}

	public static function quitar( int $id, string $ronda, string $seccion ): bool {
		global $wpdb;
		return (bool) $wpdb->delete(
			self::table(),
			array(
				'id'      => $id,
				'grupo'   => Grupo::numero(),
				'ronda'   => $ronda,
				'seccion' => $seccion,
			)
		);
	}

	/** @return array[] Gastos reales de una sección y ronda, del más reciente al más antiguo. */
	public static function de( string $ronda, string $seccion ): array {
		global $wpdb;
		$filas = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE grupo = %s AND ronda = %s AND seccion = %s ORDER BY fecha DESC, id DESC', self::table(), Grupo::numero(), $ronda, $seccion ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return array_map(
			static function ( array $f ): array {
				$f['id']         = (int) $f['id'];
				$f['partida_id'] = (int) $f['partida_id'];
				$f['importe']    = (float) $f['importe'];
				return $f;
			},
			$filas
		);
	}

	/** @return array<int, float> Suma gastada por partida. */
	public static function por_partida( string $ronda, string $seccion ): array {
		$sumas = array();
		foreach ( self::de( $ronda, $seccion ) as $g ) {
			$sumas[ $g['partida_id'] ] = round( ( $sumas[ $g['partida_id'] ] ?? 0.0 ) + $g['importe'], 2 );
		}
		return $sumas;
	}

	/** Borra los gastos reales de una partida que se quita. */
	public static function borrar_de_partida( int $partida_id ): void {
		global $wpdb;
		$wpdb->delete(
			self::table(),
			array(
				'grupo'      => Grupo::numero(),
				'partida_id' => $partida_id,
			)
		);
	}
}
