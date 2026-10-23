<?php
namespace AsdeBalears\AreaPrivada\Presupuestos;

use AsdeBalears\AreaPrivada\Domain\Grupo;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- tablas propias del plugin ({prefix}asde_*): no hay función de WordPress para ellas y los datos cambian en cada petición, no se cachean.

/**
 * Partidas del presupuesto de una sección o del grupo en una ronda
 * ({prefix}asde_presupuesto_partidas, v0.55, doc 57 §5). Solo importes: sin datos personales.
 * Total de la partida = veces × importe (p. ej. «Excursiones: 6 × 35 €»).
 */
final class PartidasTable {

	public const GASTO   = 'gasto';
	public const INGRESO = 'ingreso';

	/** Categoría de la partida de ingreso que es el objetivo de recaudación del proyecto de la sección. */
	public const PROYECTO = '_proyecto';

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'asde_presupuesto_partidas';
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
				tipo varchar(10) NOT NULL DEFAULT 'gasto',
				categoria varchar(80) NOT NULL DEFAULT '',
				concepto varchar(160) NOT NULL DEFAULT '',
				veces int(10) unsigned NOT NULL DEFAULT 1,
				importe decimal(10,2) NOT NULL DEFAULT 0,
				nota varchar(255) NOT NULL DEFAULT '',
				orden int(10) unsigned NOT NULL DEFAULT 0,
				creado datetime NOT NULL,
				creado_por bigint(20) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				KEY ambito (grupo,ronda,seccion)
			) $charset;"
		);
	}

	/** @return int Id de la partida. */
	public static function anadir( string $ronda, string $seccion, string $tipo, string $categoria, string $concepto, int $veces, float $importe, string $nota = '' ): int {
		global $wpdb;
		$wpdb->insert(
			self::table(),
			array(
				'grupo'      => Grupo::numero(),
				'ronda'      => $ronda,
				'seccion'    => $seccion,
				'tipo'       => self::INGRESO === $tipo ? self::INGRESO : self::GASTO,
				'categoria'  => mb_substr( sanitize_text_field( $categoria ), 0, 80 ),
				'concepto'   => mb_substr( sanitize_text_field( $concepto ), 0, 160 ),
				'veces'      => max( 1, $veces ),
				'importe'    => round( $importe, 2 ),
				'nota'       => mb_substr( sanitize_text_field( $nota ), 0, 255 ),
				'orden'      => count( self::de( $ronda, $seccion ) ) + 1,
				'creado'     => current_time( 'mysql', true ),
				'creado_por' => get_current_user_id(),
			)
		);
		return (int) $wpdb->insert_id;
	}

	/** Quita una partida (siempre dentro del grupo, la ronda y la sección dadas). */
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

	/** @return array[] Partidas de una sección en una ronda: gastos primero, en el orden en que se añadieron. */
	public static function de( string $ronda, string $seccion ): array {
		global $wpdb;
		$filas = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE grupo = %s AND ronda = %s AND seccion = %s ORDER BY orden ASC, id ASC', self::table(), Grupo::numero(), $ronda, $seccion ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$filas = array_map( array( self::class, 'preparar' ), $filas );
		usort( $filas, static fn( $a, $b ) => ( self::INGRESO === $a['tipo'] ) <=> ( self::INGRESO === $b['tipo'] ) ?: $a['id'] <=> $b['id'] );
		return $filas;
	}

	/** Cambia el importe de una partida (el objetivo de recaudación del proyecto se rellena así). */
	public static function cambiar_importe( int $id, string $ronda, string $seccion, int $veces, float $importe ): bool {
		global $wpdb;
		return false !== $wpdb->update(
			self::table(),
			array(
				'veces'   => max( 1, $veces ),
				'importe' => round( $importe, 2 ),
			),
			array(
				'id'      => $id,
				'grupo'   => Grupo::numero(),
				'ronda'   => $ronda,
				'seccion' => $seccion,
			)
		);
	}

	/** Borra todas las partidas de una sección y ronda (solo para pruebas y para copiar encima). */
	public static function vaciar( string $ronda, string $seccion ): void {
		global $wpdb;
		$wpdb->delete(
			self::table(),
			array(
				'grupo'   => Grupo::numero(),
				'ronda'   => $ronda,
				'seccion' => $seccion,
			)
		);
	}

	private static function preparar( array $f ): array {
		$f['id']      = (int) $f['id'];
		$f['veces']   = (int) $f['veces'];
		$f['importe'] = (float) $f['importe'];
		$f['total']   = round( $f['veces'] * $f['importe'], 2 );
		return $f;
	}
}
