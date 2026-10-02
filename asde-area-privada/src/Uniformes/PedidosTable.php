<?php
namespace AsdeBalears\AreaPrivada\Uniformes;

use AsdeBalears\AreaPrivada\Domain\Grupo;

defined( 'ABSPATH' ) || exit;

/**
 * Pedidos de uniforme ({prefix}asde_uniformes): una camisa (y/o insignias) para
 * un socio/a, con sus cuatro comprobaciones, cada una con quién y cuándo:
 *
 *   pago      Tesorería ha recibido el pago (efectivo o ingreso en cuenta).
 *   pedido    Coordinación la ha incluido en el pedido del grupo al proveedor.
 *   entrega   El grupo se la ha entregado.
 *   recibido  El socio/a o su familia confirma que la ha recibido.
 *
 * Del socio/a solo se guarda su nº Scouts de España (y sus secciones y si es
 * adulto, para saber quién lo gestiona sin consultar el CRM). Nunca se borran:
 * se cancelan.
 */
final class PedidosTable {

	public const METODOS = array(
		'efectivo' => 'Efectivo',
		'ingreso'  => 'Ingreso en cuenta',
	);

	/** Filtros de la gestión. */
	public const SIN_PAGAR     = 'sin_pagar';
	public const POR_PEDIR     = 'por_pedir';
	public const POR_ENTREGAR  = 'por_entregar';
	public const POR_CONFIRMAR = 'por_confirmar';
	public const RECIBIDOS     = 'recibidos';
	public const CANCELADOS    = 'cancelados';

	public const FILTROS = array(
		''                  => 'En curso',
		self::SIN_PAGAR     => 'Pendientes de pago',
		self::POR_PEDIR     => 'Pendientes de incluir en el pedido',
		self::POR_ENTREGAR  => 'Pendientes de entregar',
		self::POR_CONFIRMAR => 'Entregados sin confirmar',
		self::RECIBIDOS     => 'Recibidos',
		self::CANCELADOS    => 'Cancelados',
		'todos'             => 'Todos',
	);

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'asde_uniformes';
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
				socio varchar(20) NOT NULL,
				secciones varchar(120) NOT NULL DEFAULT '',
				adulto tinyint(1) NOT NULL DEFAULT 0,
				lineas text NOT NULL,
				total decimal(10,2) NOT NULL DEFAULT 0,
				provisional tinyint(1) NOT NULL DEFAULT 0,
				nota varchar(255) NOT NULL DEFAULT '',
				creado datetime NOT NULL,
				creado_por bigint(20) unsigned NOT NULL DEFAULT 0,
				pago_fecha date NULL,
				pago_metodo varchar(20) NOT NULL DEFAULT '',
				pago_importe decimal(10,2) NULL,
				pago_ref varchar(120) NOT NULL DEFAULT '',
				pago_por bigint(20) unsigned NOT NULL DEFAULT 0,
				pago_en datetime NULL,
				pedido_ref varchar(60) NOT NULL DEFAULT '',
				pedido_por bigint(20) unsigned NOT NULL DEFAULT 0,
				pedido_en datetime NULL,
				entregado_por bigint(20) unsigned NOT NULL DEFAULT 0,
				entregado_en datetime NULL,
				recibido_por bigint(20) unsigned NOT NULL DEFAULT 0,
				recibido_en datetime NULL,
				cancelado_por bigint(20) unsigned NOT NULL DEFAULT 0,
				cancelado_en datetime NULL,
				cancelado_motivo varchar(255) NOT NULL DEFAULT '',
				PRIMARY KEY  (id),
				KEY grupo (grupo),
				KEY socio (socio)
			) $charset;"
		);
	}

	/** Nº del pedido (referencia para el ingreso): U + grupo + id (U6840007). */
	public static function numero( array $p ): string {
		return 'U' . $p['grupo'] . str_pad( (string) $p['id'], 4, '0', STR_PAD_LEFT );
	}

	/**
	 * @param array{camisa:?array, insignias:array} $lineas
	 * @return int Id del pedido.
	 */
	public static function crear( string $socio, array $secciones, bool $adulto, array $lineas, float $total, bool $provisional, string $nota ): int {
		global $wpdb;
		$wpdb->insert(
			self::table(),
			array(
				'grupo'       => Grupo::numero(),
				'socio'       => $socio,
				'secciones'   => mb_substr( implode( ',', $secciones ), 0, 120 ),
				'adulto'      => $adulto ? 1 : 0,
				'lineas'      => (string) wp_json_encode( $lineas ),
				'total'       => round( $total, 2 ),
				'provisional' => $provisional ? 1 : 0,
				'nota'        => mb_substr( sanitize_text_field( $nota ), 0, 255 ),
				'creado'      => current_time( 'mysql', true ),
				'creado_por'  => get_current_user_id(),
			)
		);
		return (int) $wpdb->insert_id;
	}

	/** Pedido del grupo actual, o null. */
	public static function obtener( int $id ): ?array {
		global $wpdb;
		$fila = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d AND grupo = %s', $id, Grupo::numero() ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return $fila ? self::preparar( $fila ) : null;
	}

	/** @return array[] Todos los del grupo actual, los más recientes primero. */
	public static function todos(): array {
		global $wpdb;
		$filas = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE grupo = %s ORDER BY creado DESC, id DESC', Grupo::numero() ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return array_map( array( self::class, 'preparar' ), $filas );
	}

	/**
	 * @param string[] $socios
	 * @return array[] Los de estos socios (grupo actual), los más recientes primero.
	 */
	public static function de_socios( array $socios ): array {
		global $wpdb;
		$socios = array_values( array_unique( array_filter( array_map( 'strval', $socios ) ) ) );
		if ( ! $socios ) {
			return array();
		}
		$marcas = implode( ',', array_fill( 0, count( $socios ), '%s' ) );
		$filas  = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . " WHERE grupo = %s AND socio IN ($marcas) ORDER BY creado DESC, id DESC", array_merge( array( Grupo::numero() ), $socios ) ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return array_map( array( self::class, 'preparar' ), $filas );
	}

	/** ¿Cumple el filtro de la gestión? ('' = en curso: ni recibido ni cancelado). */
	public static function cumple( array $p, string $filtro ): bool {
		$cancelado = null !== $p['cancelado_en'];
		switch ( $filtro ) {
			case 'todos':
				return true;
			case self::CANCELADOS:
				return $cancelado;
			case self::SIN_PAGAR:
				return ! $cancelado && null === $p['pago_en'];
			case self::POR_PEDIR:
				return ! $cancelado && null === $p['pedido_en'] && null === $p['entregado_en'];
			case self::POR_ENTREGAR:
				// Ya en un pedido del grupo (lo que se puede repartir) y sin entregar.
				return ! $cancelado && null !== $p['pedido_en'] && null === $p['entregado_en'];
			case self::POR_CONFIRMAR:
				return ! $cancelado && null !== $p['entregado_en'] && null === $p['recibido_en'];
			case self::RECIBIDOS:
				return ! $cancelado && null !== $p['recibido_en'];
			default:
				return ! $cancelado && null === $p['recibido_en'];
		}
	}

	/** Paso en el que está (para la etiqueta): cancelado, recibido, entregado, pedido, pagado o solicitado. */
	public static function estado( array $p ): string {
		foreach ( array( 'cancelado', 'recibido', 'entregado', 'pedido', 'pago' ) as $paso ) {
			if ( null !== $p[ $paso . '_en' ] ) {
				return 'pago' === $paso ? 'pagado' : $paso;
			}
		}
		return 'solicitado';
	}

	public const ESTADOS = array(
		'solicitado' => 'Solicitado',
		'pagado'     => 'Pagado',
		'pedido'     => 'En el pedido',
		'entregado'  => 'Entregado',
		'recibido'   => 'Recibido',
		'cancelado'  => 'Cancelado',
	);

	public static function marcar_pago( int $id, string $fecha, string $metodo, float $importe, string $ref ): bool {
		return self::actualizar(
			$id,
			array(
				'pago_fecha'   => $fecha,
				'pago_metodo'  => $metodo,
				'pago_importe' => round( $importe, 2 ),
				'pago_ref'     => mb_substr( sanitize_text_field( $ref ), 0, 120 ),
				'pago_por'     => get_current_user_id(),
				'pago_en'      => current_time( 'mysql', true ),
			)
		);
	}

	public static function deshacer_pago( int $id ): bool {
		return self::actualizar(
			$id,
			array(
				'pago_fecha'   => null,
				'pago_metodo'  => '',
				'pago_importe' => null,
				'pago_ref'     => '',
				'pago_por'     => 0,
				'pago_en'      => null,
			)
		);
	}

	/** @param int[] $ids @return int Cuántos se han incluido. */
	public static function marcar_pedido( array $ids, string $ref ): int {
		$n = 0;
		foreach ( array_unique( array_map( 'intval', $ids ) ) as $id ) {
			$p = self::obtener( $id );
			if ( $p && null === $p['cancelado_en'] && null === $p['pedido_en'] ) {
				$n += (int) self::actualizar(
					$id,
					array(
						'pedido_ref' => mb_substr( sanitize_text_field( $ref ), 0, 60 ),
						'pedido_por' => get_current_user_id(),
						'pedido_en'  => current_time( 'mysql', true ),
					)
				);
			}
		}
		return $n;
	}

	public static function deshacer_pedido( int $id ): bool {
		return self::actualizar(
			$id,
			array(
				'pedido_ref' => '',
				'pedido_por' => 0,
				'pedido_en'  => null,
			)
		);
	}

	public static function marcar_entregado( int $id ): bool {
		return self::actualizar(
			$id,
			array(
				'entregado_por' => get_current_user_id(),
				'entregado_en'  => current_time( 'mysql', true ),
			)
		);
	}

	public static function deshacer_entrega( int $id ): bool {
		return self::actualizar(
			$id,
			array(
				'entregado_por' => 0,
				'entregado_en'  => null,
				'recibido_por'  => 0,
				'recibido_en'   => null,
			)
		);
	}

	public static function marcar_recibido( int $id ): bool {
		return self::actualizar(
			$id,
			array(
				'recibido_por' => get_current_user_id(),
				'recibido_en'  => current_time( 'mysql', true ),
			)
		);
	}

	public static function cancelar( int $id, string $motivo ): bool {
		return self::actualizar(
			$id,
			array(
				'cancelado_por'    => get_current_user_id(),
				'cancelado_en'     => current_time( 'mysql', true ),
				'cancelado_motivo' => mb_substr( sanitize_text_field( $motivo ), 0, 255 ),
			)
		);
	}

	public static function recalcular( int $id, float $total, bool $provisional ): bool {
		return self::actualizar(
			$id,
			array(
				'total'       => round( $total, 2 ),
				'provisional' => $provisional ? 1 : 0,
			)
		);
	}

	private static function actualizar( int $id, array $datos ): bool {
		global $wpdb;
		return false !== $wpdb->update(
			self::table(),
			$datos,
			array(
				'id'    => $id,
				'grupo' => Grupo::numero(),
			)
		);
	}

	private static function preparar( array $f ): array {
		$f['id']          = (int) $f['id'];
		$f['adulto']      = (bool) (int) $f['adulto'];
		$f['provisional'] = (bool) (int) $f['provisional'];
		$f['total']       = (float) $f['total'];
		$f['secciones']   = array_values( array_filter( explode( ',', (string) $f['secciones'] ) ) );
		$lineas           = json_decode( (string) $f['lineas'], true );
		$f['lineas']      = array(
			'camisa'    => is_array( $lineas['camisa'] ?? null ) ? $lineas['camisa'] : null,
			'insignias' => array_values( array_filter( (array) ( $lineas['insignias'] ?? array() ), 'is_array' ) ),
		);
		$f['pago_importe'] = null === $f['pago_importe'] ? null : (float) $f['pago_importe'];
		foreach ( array( 'creado_por', 'pago_por', 'pedido_por', 'entregado_por', 'recibido_por', 'cancelado_por' ) as $k ) {
			$f[ $k ] = (int) $f[ $k ];
		}
		return $f;
	}
}
