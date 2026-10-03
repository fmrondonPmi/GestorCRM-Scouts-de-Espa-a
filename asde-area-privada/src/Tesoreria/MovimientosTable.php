<?php
namespace AsdeBalears\AreaPrivada\Tesoreria;

defined( 'ABSPATH' ) || exit;

/**
 * Movimientos de Tesorería por familia y ronda ({prefix}asde_movimientos):
 *   pago    Dinero recibido (reduce lo pendiente).
 *   ajuste  Cambia lo que debe pagar la familia (negativo = descuento o beca;
 *           positivo = recargo). Siempre con motivo.
 * Nunca se borran: se anulan (queda constancia de quién y cuándo).
 * Se registran a mano (origen = manual), al conciliar un ingreso del banco
 * (origen = banco, ver Tesoreria\Banco) o al registrar el cobro de una remesa de
 * adeudos SEPA (origen = sepa, ver Sepa\Remesas).
 */
final class MovimientosTable {

	public const PAGO   = 'pago';
	public const AJUSTE = 'ajuste';

	public const METODOS = array(
		'transferencia' => 'Transferencia',
		'efectivo'      => 'Efectivo',
		'bizum'         => 'Bizum',
		'tarjeta'       => 'Tarjeta',
		'domiciliacion' => 'Domiciliación SEPA',
		'otro'          => 'Otro',
	);

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'asde_movimientos';
	}

	public static function install_table(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$t       = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE $t (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				familia_id bigint(20) unsigned NOT NULL,
				ronda varchar(9) NOT NULL,
				tipo varchar(10) NOT NULL,
				fecha date NOT NULL,
				importe decimal(10,2) NOT NULL,
				metodo varchar(20) NOT NULL DEFAULT '',
				referencia varchar(120) NOT NULL DEFAULT '',
				nota varchar(255) NOT NULL DEFAULT '',
				origen varchar(20) NOT NULL DEFAULT 'manual',
				registrado_por bigint(20) unsigned NOT NULL DEFAULT 0,
				creado datetime NOT NULL,
				anulado_por bigint(20) unsigned NOT NULL DEFAULT 0,
				anulado_en datetime NULL,
				PRIMARY KEY  (id),
				KEY familia_ronda (familia_id, ronda),
				KEY ronda (ronda)
			) $charset;"
		);
	}

	/** @return int|\WP_Error Id del movimiento. */
	public static function registrar( int $familia_id, string $ronda, string $tipo, string $fecha, float $importe, string $metodo = '', string $referencia = '', string $nota = '', string $origen = 'manual' ) {
		global $wpdb;
		if ( ! in_array( $tipo, array( self::PAGO, self::AJUSTE ), true ) || ! Config::es_ronda( $ronda ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $fecha ) ) {
			return new \WP_Error( 'asde_mov', asde_t( 'Datos del movimiento no válidos.' ) );
		}
		if ( self::PAGO === $tipo && $importe <= 0 ) {
			return new \WP_Error( 'asde_mov', asde_t( 'El importe del pago debe ser mayor que 0.' ) );
		}
		if ( self::AJUSTE === $tipo && ( 0.0 === $importe || '' === trim( $nota ) ) ) {
			return new \WP_Error( 'asde_mov', asde_t( 'Un ajuste necesita un importe distinto de 0 y un motivo.' ) );
		}
		$wpdb->insert(
			self::table(),
			array(
				'familia_id'     => $familia_id,
				'ronda'          => $ronda,
				'tipo'           => $tipo,
				'fecha'          => $fecha,
				'importe'        => round( $importe, 2 ),
				'metodo'         => self::PAGO === $tipo && isset( self::METODOS[ $metodo ] ) ? $metodo : '',
				'referencia'     => mb_substr( sanitize_text_field( $referencia ), 0, 120 ),
				'nota'           => mb_substr( sanitize_text_field( $nota ), 0, 255 ),
				'origen'         => in_array( $origen, array( 'manual', 'banco', 'sepa' ), true ) ? $origen : 'manual',
				'registrado_por' => get_current_user_id(),
				'creado'         => current_time( 'mysql', true ),
			)
		);
		return (int) $wpdb->insert_id ?: new \WP_Error( 'asde_mov', asde_t( 'No se ha podido guardar el movimiento.' ) );
	}

	public static function obtener( int $id ): ?array {
		global $wpdb;
		$r = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return $r ?: null;
	}

	public static function anular( int $id ): bool {
		global $wpdb;
		return (bool) $wpdb->update(
			self::table(),
			array(
				'anulado_por' => get_current_user_id(),
				'anulado_en'  => current_time( 'mysql', true ),
			),
			array(
				'id'         => $id,
				'anulado_en' => null,
			)
		);
	}

	/** @return array[] Movimientos de una familia en una ronda (incluidos anulados), más recientes primero. */
	public static function de_familia( int $familia_id, string $ronda ): array {
		global $wpdb;
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE familia_id = %d AND ronda = %s ORDER BY fecha DESC, id DESC', $familia_id, $ronda ), ARRAY_A ); // phpcs:ignore WordPress.DB
	}

	/** @return array[] Movimientos vigentes (sin anulados) de una familia en todas las rondas, más recientes primero. */
	public static function de_familia_todas( int $familia_id ): array {
		global $wpdb;
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE familia_id = %d AND anulado_en IS NULL ORDER BY fecha DESC, id DESC', $familia_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
	}

	public static function hay_de_familia( int $familia_id ): bool {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM ' . self::table() . ' WHERE familia_id = %d LIMIT 1', $familia_id ) ); // phpcs:ignore WordPress.DB
	}

	/** @return array<int, array{pagado:float, ajustes:float}> familia_id => totales (sin anulados). */
	public static function totales( string $ronda ): array {
		global $wpdb;
		$out = array();
		// Solo familias del grupo en el que se trabaja.
		$sql = 'SELECT m.familia_id, m.tipo, SUM(m.importe) AS total FROM ' . self::table() . ' m INNER JOIN ' . FamiliasTable::table() . ' f ON f.id = m.familia_id'
			. ' WHERE m.ronda = %s AND m.anulado_en IS NULL AND f.grupo = %s GROUP BY m.familia_id, m.tipo';
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( $sql, $ronda, \AsdeBalears\AreaPrivada\Domain\Grupo::numero() ), ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB
			$id         = (int) $r['familia_id'];
			$out[ $id ] = $out[ $id ] ?? array(
				'pagado'  => 0.0,
				'ajustes' => 0.0,
			);
			$out[ $id ][ self::PAGO === $r['tipo'] ? 'pagado' : 'ajustes' ] = (float) $r['total'];
		}
		return $out;
	}
}
