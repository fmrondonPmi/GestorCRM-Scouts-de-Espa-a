<?php
namespace AsdeBalears\AreaPrivada\Eventos;

use AsdeBalears\AreaPrivada\Domain\Grupo;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- tablas propias del plugin ({prefix}asde_*): no hay función de WordPress para ellas y los datos cambian en cada petición, no se cachean.

/**
 * Gastos e ingresos reales de un evento ({prefix}asde_evento_gastos, v0.36, doc 43: apartado 4 y
 * «Tercera fase»). Los ingresos reales son los que no son cobros a las familias (una subvención
 * recibida, una aportación).
 *
 * - Un **gasto** lo propone quien gestiona el evento, con su justificante (cifrado, ver
 *   Justificantes), y Tesorería lo **valida** o lo **rechaza** con motivo. Si lo pagó alguien de su
 *   bolsillo, queda pendiente de **reembolso** hasta que Tesorería lo marca.
 * - Lo registrado por Tesorería nace validado.
 * - **Nada se borra**: lo equivocado se anula (y se ve anulado).
 */
final class GastosTable {

	public const GASTO   = 'gasto';
	public const INGRESO = 'ingreso';

	public const PROPUESTO = 'propuesto';
	public const VALIDADO  = 'validado';
	public const RECHAZADO = 'rechazado';

	public const ESTADOS = array(
		self::PROPUESTO => 'Por revisar',
		self::VALIDADO  => 'Validado',
		self::RECHAZADO => 'Rechazado',
	);

	/** Quién lo pagó. */
	public const PAGO_GRUPO    = 'grupo';
	public const PAGO_ANTICIPO = 'anticipo';
	public const PAGO_PERSONA  = 'persona';

	public const PAGADORES = array(
		self::PAGO_GRUPO    => 'El grupo (cuenta o caja)',
		self::PAGO_ANTICIPO => 'Con un anticipo de caja',
		self::PAGO_PERSONA  => 'Alguien del equipo, de su bolsillo (hay que reembolsárselo)',
	);

	public const FORMAS = array(
		'efectivo'      => 'Efectivo',
		'tarjeta'       => 'Tarjeta',
		'transferencia' => 'Transferencia',
		'bizum'         => 'Bizum',
		'otro'          => 'Otra',
	);

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'asde_evento_gastos';
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
				tipo varchar(10) NOT NULL DEFAULT 'gasto',
				fecha date NOT NULL,
				partida_id bigint(20) unsigned NOT NULL DEFAULT 0,
				concepto varchar(120) NOT NULL DEFAULT '',
				proveedor varchar(120) NOT NULL DEFAULT '',
				importe decimal(10,2) NOT NULL,
				forma varchar(20) NOT NULL DEFAULT '',
				pagado_por varchar(10) NOT NULL DEFAULT 'grupo',
				persona bigint(20) unsigned NOT NULL DEFAULT 0,
				anticipo_id bigint(20) unsigned NOT NULL DEFAULT 0,
				con_fondo tinyint(1) NOT NULL DEFAULT 0,
				estado varchar(10) NOT NULL DEFAULT 'propuesto',
				motivo varchar(255) NOT NULL DEFAULT '',
				reembolsado_en date NULL,
				reembolso_forma varchar(20) NOT NULL DEFAULT '',
				reembolsado_por bigint(20) unsigned NOT NULL DEFAULT 0,
				justificante varchar(64) NOT NULL DEFAULT '',
				justificante_tipo varchar(40) NOT NULL DEFAULT '',
				justificante_nombre varchar(120) NOT NULL DEFAULT '',
				creado datetime NOT NULL,
				creado_por bigint(20) unsigned NOT NULL DEFAULT 0,
				revisado_en datetime NULL,
				revisado_por bigint(20) unsigned NOT NULL DEFAULT 0,
				anulado_en datetime NULL,
				anulado_por bigint(20) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				KEY evento (evento_id),
				KEY grupo (grupo)
			) $charset;"
		);
	}

	/** @param array<string, mixed> $d Columnas ya validadas por Cuentas. @return int Id. */
	public static function anadir( array $d ): int {
		global $wpdb;
		$wpdb->insert(
			self::table(),
			array_merge(
				$d,
				array(
					'grupo'      => Grupo::numero(),
					'importe'    => round( (float) $d['importe'], 2 ),
					'creado'     => current_time( 'mysql', true ),
					'creado_por' => get_current_user_id(),
				)
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

	/** @return array[] Los del evento (también los anulados y rechazados), del más antiguo al más reciente. */
	public static function de_evento( int $evento_id ): array {
		global $wpdb;
		return array_map( array( self::class, 'preparar' ), (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE evento_id = %d AND grupo = %s ORDER BY fecha ASC, id ASC', self::table(), $evento_id, Grupo::numero() ), ARRAY_A ) ); // phpcs:ignore WordPress.DB
	}

	/** ¿Cuenta en las cuentas? Validado y sin anular. */
	public static function vale( array $g ): bool {
		return self::VALIDADO === $g['estado'] && null === $g['anulado_en'];
	}

	/** ¿Está esperando a Tesorería? Propuesto y sin retirar. */
	public static function por_revisar( array $g ): bool {
		return self::PROPUESTO === $g['estado'] && null === $g['anulado_en'];
	}

	/** ¿Hay que reembolsárselo a alguien? */
	public static function por_reembolsar( array $g ): bool {
		return self::vale( $g ) && self::GASTO === $g['tipo'] && self::PAGO_PERSONA === $g['pagado_por'] && null === $g['reembolsado_en'];
	}

	private static function preparar( array $f ): array {
		foreach ( array( 'id', 'evento_id', 'partida_id', 'persona', 'anticipo_id', 'reembolsado_por', 'creado_por', 'revisado_por', 'anulado_por' ) as $k ) {
			$f[ $k ] = (int) $f[ $k ];
		}
		$f['importe']   = round( (float) $f['importe'], 2 );
		$f['con_fondo'] = (bool) (int) $f['con_fondo'];
		return $f;
	}
}
