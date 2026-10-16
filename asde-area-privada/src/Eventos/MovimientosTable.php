<?php
namespace AsdeBalears\AreaPrivada\Eventos;

use AsdeBalears\AreaPrivada\Domain\Grupo;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- tablas propias del plugin ({prefix}asde_*): no hay función de WordPress para ellas y los datos cambian en cada petición, no se cachean.

/**
 * Cobros de los eventos ({prefix}asde_evento_movimientos, doc 43, apartado 4): los pagos
 * de cada inscripción y las becas y ajustes de Tesorería. No cuentan en la cuota de la
 * ronda (P-145): van en su propia tabla, como los del uniforme.
 *
 * - Un **pago** de la familia (un solo pago para los hermanos) se reparte al guardarlo en un
 *   movimiento por inscripción, con el mismo «lote»: así cada inscripción sabe lo suyo.
 * - Una **beca o ajuste** cambia lo que paga una inscripción (en negativo, una rebaja),
 *   siempre con su motivo. El precio de los demás no cambia.
 * - **Nada se borra**: un movimiento equivocado se anula (y se ve anulado).
 *
 * - v0.36: una **devolución** a la familia (importe positivo, lo que se le devuelve), siempre con
 *   motivo, y los cobros de la **remesa SEPA** del evento (forma «domiciliacion»).
 */
final class MovimientosTable {

	public const PAGO       = 'pago';
	public const AJUSTE     = 'ajuste';
	public const DEVOLUCION = 'devolucion';

	/** Formas de pago de un evento (P-146). La domiciliación solo la pone la remesa SEPA (v0.36). */
	public const FORMAS = array(
		'efectivo'      => 'Efectivo',
		'transferencia' => 'Ingreso o transferencia',
		'bizum'         => 'Bizum',
		'domiciliacion' => 'Domiciliación SEPA',
	);

	/** Las que Tesorería elige a mano (sin la domiciliación). */
	public const FORMAS_MANUALES = array( 'efectivo', 'transferencia', 'bizum' );

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'asde_evento_movimientos';
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
				inscripcion_id bigint(20) unsigned NOT NULL,
				tipo varchar(10) NOT NULL,
				fecha date NOT NULL,
				importe decimal(10,2) NOT NULL,
				forma varchar(20) NOT NULL DEFAULT '',
				referencia varchar(120) NOT NULL DEFAULT '',
				nota varchar(255) NOT NULL DEFAULT '',
				lote varchar(20) NOT NULL DEFAULT '',
				creado datetime NOT NULL,
				creado_por bigint(20) unsigned NOT NULL DEFAULT 0,
				anulado_por bigint(20) unsigned NOT NULL DEFAULT 0,
				anulado_en datetime NULL,
				PRIMARY KEY  (id),
				KEY evento (evento_id),
				KEY inscripcion (inscripcion_id),
				KEY grupo (grupo)
			) $charset;"
		);
	}

	/** @return int Id del movimiento. */
	public static function anadir( int $evento_id, int $inscripcion_id, string $tipo, string $fecha, float $importe, string $forma = '', string $referencia = '', string $nota = '', string $lote = '' ): int {
		global $wpdb;
		$wpdb->insert(
			self::table(),
			array(
				'evento_id'      => $evento_id,
				'grupo'          => Grupo::numero(),
				'inscripcion_id' => $inscripcion_id,
				'tipo'           => $tipo,
				'fecha'          => $fecha,
				'importe'        => round( $importe, 2 ),
				'forma'          => self::AJUSTE === $tipo ? '' : $forma,
				'referencia'     => mb_substr( $referencia, 0, 120 ),
				'nota'           => mb_substr( $nota, 0, 255 ),
				'lote'           => $lote,
				'creado'         => current_time( 'mysql', true ),
				'creado_por'     => get_current_user_id(),
			)
		);
		return (int) $wpdb->insert_id;
	}

	public static function obtener( int $id ): ?array {
		global $wpdb;
		$fila = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d AND grupo = %s', self::table(), $id, Grupo::numero() ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return $fila ? self::preparar( $fila ) : null;
	}

	/** Anula un movimiento (y los de su mismo lote: un pago de la familia se anula entero). @return int[] Inscripciones afectadas. */
	public static function anular( int $id ): array {
		global $wpdb;
		$m = self::obtener( $id );
		if ( ! $m || null !== $m['anulado_en'] ) {
			return array();
		}
		$ids = '' !== $m['lote']
			? array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i WHERE grupo = %s AND evento_id = %d AND lote = %s AND anulado_en IS NULL', self::table(), Grupo::numero(), $m['evento_id'], $m['lote'] ) ) ) // phpcs:ignore WordPress.DB
			: array( $id );
		$afectadas = array();
		foreach ( $ids as $mid ) {
			$f = self::obtener( $mid );
			$wpdb->update(
				self::table(),
				array(
					'anulado_por' => get_current_user_id(),
					'anulado_en'  => current_time( 'mysql', true ),
				),
				array(
					'id'    => $mid,
					'grupo' => Grupo::numero(),
				)
			);
			$afectadas[] = (int) $f['inscripcion_id'];
		}
		return array_values( array_unique( $afectadas ) );
	}

	/** @return array<int, array[]> inscripción => sus movimientos (también los anulados), del más antiguo al más reciente. */
	public static function de_evento( int $evento_id ): array {
		global $wpdb;
		$filas = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE evento_id = %d AND grupo = %s ORDER BY fecha ASC, id ASC', self::table(), $evento_id, Grupo::numero() ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$out   = array();
		foreach ( $filas as $f ) {
			$f                               = self::preparar( $f );
			$out[ $f['inscripcion_id'] ][] = $f;
		}
		return $out;
	}

	/** @return array<int, array[]> Lo mismo para estas inscripciones (de varios eventos: «Mis pagos»). */
	public static function de_inscripciones( array $ids ): array {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( ! $ids ) {
			return array();
		}
		$marcas = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$filas  = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE grupo = %s AND inscripcion_id IN ($marcas) ORDER BY fecha ASC, id ASC", array_merge( array( self::table(), Grupo::numero() ), $ids ) ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$out    = array();
		foreach ( $filas as $f ) {
			$f                               = self::preparar( $f );
			$out[ $f['inscripcion_id'] ][] = $f;
		}
		return $out;
	}

	private static function preparar( array $f ): array {
		foreach ( array( 'id', 'evento_id', 'inscripcion_id', 'creado_por', 'anulado_por' ) as $k ) {
			$f[ $k ] = (int) $f[ $k ];
		}
		$f['importe'] = round( (float) $f['importe'], 2 );
		return $f;
	}
}
