<?php
namespace AsdeBalears\AreaPrivada\Eventos;

use AsdeBalears\AreaPrivada\Domain\Grupo;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- tablas propias del plugin ({prefix}asde_*): no hay función de WordPress para ellas y los datos cambian en cada petición, no se cachean.

/**
 * Anticipos de caja de un evento ({prefix}asde_evento_anticipos, v0.36, doc 43, apartado 4):
 * dinero que Tesorería da a alguien del equipo antes del evento. Al volver se **liquida**: los
 * gastos validados pagados con él más lo devuelto tienen que sumar el anticipo; si no, se avisa
 * de la diferencia y se liquida con una nota (P-159).
 */
final class AnticiposTable {

	public const ENTREGADO = 'entregado';
	public const LIQUIDADO = 'liquidado';

	public const ESTADOS = array(
		self::ENTREGADO => 'Entregado, por liquidar',
		self::LIQUIDADO => 'Liquidado',
	);

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'asde_evento_anticipos';
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
				persona bigint(20) unsigned NOT NULL,
				importe decimal(10,2) NOT NULL,
				fecha date NOT NULL,
				forma varchar(20) NOT NULL DEFAULT '',
				nota varchar(255) NOT NULL DEFAULT '',
				estado varchar(10) NOT NULL DEFAULT 'entregado',
				devuelto decimal(10,2) NOT NULL DEFAULT 0.00,
				liquidacion_nota varchar(255) NOT NULL DEFAULT '',
				liquidado_en datetime NULL,
				liquidado_por bigint(20) unsigned NOT NULL DEFAULT 0,
				creado datetime NOT NULL,
				creado_por bigint(20) unsigned NOT NULL DEFAULT 0,
				anulado_en datetime NULL,
				anulado_por bigint(20) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				KEY evento (evento_id),
				KEY grupo (grupo)
			) $charset;"
		);
	}

	/** @return int Id. */
	public static function anadir( int $evento_id, int $persona, float $importe, string $fecha, string $forma, string $nota ): int {
		global $wpdb;
		$wpdb->insert(
			self::table(),
			array(
				'evento_id'  => $evento_id,
				'grupo'      => Grupo::numero(),
				'persona'    => $persona,
				'importe'    => round( $importe, 2 ),
				'fecha'      => $fecha,
				'forma'      => $forma,
				'nota'       => mb_substr( $nota, 0, 255 ),
				'estado'     => self::ENTREGADO,
				'creado'     => current_time( 'mysql', true ),
				'creado_por' => get_current_user_id(),
			)
		);
		return (int) $wpdb->insert_id;
	}

	public static function obtener( int $id ): ?array {
		global $wpdb;
		$fila = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d AND grupo = %s', self::table(), $id, Grupo::numero() ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return $fila ? self::preparar( $fila ) : null;
	}

	/** @param array<string, mixed> $d */
	public static function actualizar( int $id, array $d ): bool {
		global $wpdb;
		return false !== $wpdb->update(
			self::table(),
			$d,
			array(
				'id'    => $id,
				'grupo' => Grupo::numero(),
			)
		);
	}

	/** @return array[] Los del evento (también los anulados), del más antiguo al más reciente. */
	public static function de_evento( int $evento_id ): array {
		global $wpdb;
		return array_map( array( self::class, 'preparar' ), (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE evento_id = %d AND grupo = %s ORDER BY fecha ASC, id ASC', self::table(), $evento_id, Grupo::numero() ), ARRAY_A ) ); // phpcs:ignore WordPress.DB
	}

	/** ¿Está pendiente de liquidar? */
	public static function abierto( array $a ): bool {
		return self::ENTREGADO === $a['estado'] && null === $a['anulado_en'];
	}

	private static function preparar( array $f ): array {
		foreach ( array( 'id', 'evento_id', 'persona', 'liquidado_por', 'creado_por', 'anulado_por' ) as $k ) {
			$f[ $k ] = (int) $f[ $k ];
		}
		$f['importe']  = round( (float) $f['importe'], 2 );
		$f['devuelto'] = round( (float) $f['devuelto'], 2 );
		return $f;
	}
}
