<?php
namespace AsdeBalears\AreaPrivada\Tienda;

use AsdeBalears\AreaPrivada\Crm\Crypto;
use AsdeBalears\AreaPrivada\Domain\Grupo;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- tablas propias del plugin ({prefix}asde_*): no hay función de WordPress para ellas y los datos cambian en cada petición, no se cachean.

/**
 * Pedidos de uniforme ({prefix}asde_tienda_pedidos): una camisa (y/o insignias) para
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
		'bizum'    => 'Bizum',
	);

	/**
	 * v0.39 (doc 48, fase 3): cómo elige pagar quien compra y, entre paréntesis, el método con el que Tesorería
	 * lo valida. «En físico» (a Tesorería) solo lo puede elegir un socio/a (P-174).
	 */
	public const FORMAS = array(
		'transferencia' => 'Transferencia',
		'bizum'         => 'Bizum',
		'fisico'        => 'En físico (a Tesorería)',
	);

	public const METODO_DE_FORMA = array(
		'transferencia' => 'ingreso',
		'bizum'         => 'bizum',
		'fisico'        => 'efectivo',
	);

	/** v0.39: referencia del pago de un pedido que no cuesta nada. */
	public const REF_GRATIS = 'Sin coste';

	/** v0.39: «pedido del grupo» de los pedidos de la tienda que salen de las existencias (no van al proveedor). */
	public const REF_TIENDA = 'De las existencias de la tienda';

	/**
	 * v0.29: «pedido del grupo» de los pedidos cuya camisa sale del inventario (no van al
	 * proveedor). Se guarda en castellano, como el resto de lo guardado.
	 */
	public const REF_INVENTARIO = 'Del inventario del grupo';

	/** v0.29: referencia del pago de la primera camisa de un scouter, que es gratis (queda pagada sola). */
	public const REF_SIN_COSTE = 'Sin coste: primera camisa de scouter';

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
		return $wpdb->prefix . 'asde_tienda_pedidos';
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
				forma_pago varchar(14) NOT NULL DEFAULT '',
				invitado_enc longtext NULL,
				sal varchar(32) NOT NULL DEFAULT '',
				aviso_en datetime NULL,
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
				inventario_id bigint(20) unsigned NOT NULL DEFAULT 0,
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
	public static function crear( string $socio, array $secciones, bool $adulto, array $lineas, float $total, bool $provisional, string $nota, string $forma_pago = '', array $invitado = array() ): int {
		global $wpdb;
		// v0.39 (fase 4): una compra sin sesión guarda los datos de quien compra cifrados y una sal para su enlace personal.
		$extra = $invitado ? array(
			'invitado_enc' => Crypto::encrypt( (string) wp_json_encode( $invitado ) ),
			'sal'          => wp_generate_password( 32, false, false ),
		) : array();
		$wpdb->insert(
			self::table(),
			$extra + array(
				'grupo'       => Grupo::numero(),
				'socio'       => $socio,
				'secciones'   => mb_substr( implode( ',', $secciones ), 0, 120 ),
				'adulto'      => $adulto ? 1 : 0,
				'lineas'      => (string) wp_json_encode( $lineas ),
				'total'       => round( $total, 2 ),
				'provisional' => $provisional ? 1 : 0,
				'nota'        => mb_substr( sanitize_text_field( $nota ), 0, 255 ),
				'forma_pago'  => isset( self::FORMAS[ $forma_pago ] ) ? $forma_pago : '',
				'creado'      => current_time( 'mysql', true ),
				'creado_por'  => get_current_user_id(),
			)
		);
		return (int) $wpdb->insert_id;
	}

	/** Pedido por su id, de cualquier grupo (el enlace personal de quien compró sin sesión), o null. */
	public static function por_id( int $id ): ?array {
		global $wpdb;
		$fila = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', self::table(), $id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return $fila ? self::preparar( $fila ) : null;
	}

	/** Los datos de quien compró sin sesión (nombre, email, teléfono, idioma), o null si no es una compra de invitado o ya se borraron. */
	public static function invitado( array $p ): ?array {
		if ( empty( $p['invitado_enc'] ) ) {
			return null;
		}
		$d = json_decode( (string) Crypto::decrypt( (string) $p['invitado_enc'] ), true );
		return is_array( $d ) ? $d : null;
	}

	/** Días que se guardan los datos de quien compró sin sesión después de entregar o cancelar el pedido. */
	public const DIAS_DATOS_INVITADO = 90;

	/**
	 * Borra los datos personales de las compras sin sesión ya entregadas o canceladas hace más de DIAS_DATOS_INVITADO días.
	 * El pedido queda (cantidades e importes); lo personal, no.
	 *
	 * @return int Pedidos limpiados.
	 */
	public static function purgar_invitados(): int {
		global $wpdb;
		$limite = gmdate( 'Y-m-d H:i:s', time() - self::DIAS_DATOS_INVITADO * DAY_IN_SECONDS );
		$filas  = $wpdb->query( $wpdb->prepare( "UPDATE %i SET invitado_enc = NULL, sal = '' WHERE invitado_enc IS NOT NULL AND ( ( entregado_en IS NOT NULL AND entregado_en < %s ) OR ( cancelado_en IS NOT NULL AND cancelado_en < %s ) )", self::table(), $limite, $limite ) ); // phpcs:ignore WordPress.DB
		return (int) $filas;
	}

	/**
	 * Las compras sin sesión, sin pagar ni cancelar, hechas hace más de $dias días (de todos los grupos).
	 *
	 * @return array[]
	 */
	public static function invitados_sin_pagar( int $dias, string $grupo ): array {
		global $wpdb;
		$limite = gmdate( 'Y-m-d H:i:s', time() - $dias * DAY_IN_SECONDS );
		$filas  = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE grupo = %s AND invitado_enc IS NOT NULL AND pago_en IS NULL AND cancelado_en IS NULL AND entregado_en IS NULL AND creado < %s', self::table(), $grupo, $limite ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return array_map( array( self::class, 'preparar' ), $filas );
	}

	/**
	 * Pedidos de la tienda de socios y familias sin pagar, ni entregar ni cancelar, hechos hace más de N días (v0.40, fase 5).
	 * Solo los que tienen forma de pago (los de la tienda) y cuestan algo.
	 */
	public static function socios_sin_pagar( int $dias, string $grupo ): array {
		global $wpdb;
		$limite = gmdate( 'Y-m-d H:i:s', time() - $dias * DAY_IN_SECONDS );
		$filas  = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE grupo = %s AND invitado_enc IS NULL AND socio <> '' AND forma_pago <> '' AND total > 0 AND pago_en IS NULL AND cancelado_en IS NULL AND entregado_en IS NULL AND creado < %s ORDER BY id", self::table(), $grupo, $limite ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return array_map( array( self::class, 'preparar' ), $filas );
	}

	/** Apunta que se ha mandado el recordatorio; false si ya constaba (así no se manda dos veces). */
	public static function marcar_aviso( int $id ): bool {
		global $wpdb;
		return 1 === (int) $wpdb->query( $wpdb->prepare( 'UPDATE %i SET aviso_en = %s WHERE id = %d AND aviso_en IS NULL', self::table(), current_time( 'mysql', true ), $id ) ); // phpcs:ignore WordPress.DB
	}

	/** Pedido del grupo actual, o null. */
	public static function obtener( int $id ): ?array {
		global $wpdb;
		$fila = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d AND grupo = %s', self::table(), $id, Grupo::numero() ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return $fila ? self::preparar( $fila ) : null;
	}

	/** @return array[] Todos los del grupo actual, los más recientes primero. */
	public static function todos(): array {
		global $wpdb;
		$filas = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE grupo = %s ORDER BY creado DESC, id DESC', self::table(), Grupo::numero() ), ARRAY_A ); // phpcs:ignore WordPress.DB
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
		$filas  = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE grupo = %s AND socio IN ($marcas) ORDER BY creado DESC, id DESC", array_merge( array( self::table(), Grupo::numero() ), $socios ) ), ARRAY_A ); // phpcs:ignore WordPress.DB
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

	/** Cancela un pedido en curso, con una sola sentencia. @return bool false si no existe o ya estaba cancelado. */
	public static function cancelar( int $id, string $motivo ): bool {
		global $wpdb;
		$filas = $wpdb->query( $wpdb->prepare( 'UPDATE %i SET cancelado_por = %d, cancelado_en = %s, cancelado_motivo = %s WHERE id = %d AND grupo = %s AND cancelado_en IS NULL', self::table(), get_current_user_id(), current_time( 'mysql', true ), mb_substr( sanitize_text_field( $motivo ), 0, 255 ), $id, Grupo::numero() ) ); // phpcs:ignore WordPress.DB
		return 1 === (int) $filas;
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

	/**
	 * La camisa del pedido sale del inventario (material $material, v0.29). Sin insignias, el
	 * pedido ya no va al proveedor: queda como incluido «del inventario del grupo».
	 */
	public static function servir_camisa( int $id, int $material, bool $sin_proveedor ): bool {
		$datos = array( 'inventario_id' => $material );
		if ( $sin_proveedor ) {
			$datos += array(
				'pedido_ref' => self::REF_INVENTARIO,
				'pedido_por' => get_current_user_id(),
				'pedido_en'  => current_time( 'mysql', true ),
			);
		}
		return self::actualizar( $id, $datos );
	}

	/** Lo contrario de servir_camisa(): la camisa vuelve al inventario (eso lo hace quien llama). */
	public static function quitar_camisa_servida( int $id, bool $quitar_pedido ): bool {
		$datos = array( 'inventario_id' => 0 );
		if ( $quitar_pedido ) {
			$datos += array(
				'pedido_ref' => '',
				'pedido_por' => 0,
				'pedido_en'  => null,
			);
		}
		return self::actualizar( $id, $datos );
	}

	/** Guarda las líneas del pedido (camisa, insignias y productos) tal como están; las insignias servidas del inventario llevan «inventario». */
	public static function guardar_lineas( int $id, array $lineas ): bool {
		return self::actualizar(
			$id,
			array(
				'lineas' => (string) wp_json_encode(
					array(
						'camisa'    => $lineas['camisa'],
						'insignias' => array_values( $lineas['insignias'] ),
						'productos' => array_values( $lineas['productos'] ?? array() ),
					)
				),
			)
		);
	}

	/** Todo el pedido sale del inventario del grupo: no va al proveedor, queda como incluido «del inventario» (v0.41). */
	public static function incluir_del_inventario( int $id ): bool {
		return self::actualizar(
			$id,
			array(
				'pedido_ref' => self::REF_INVENTARIO,
				'pedido_por' => get_current_user_id(),
				'pedido_en'  => current_time( 'mysql', true ),
			)
		);
	}

	/** Lo contrario: el pedido deja de constar como incluido (solo si lo estaba «del inventario»). */
	public static function quitar_inclusion_inventario( int $id ): bool {
		return self::actualizar(
			$id,
			array(
				'pedido_ref' => '',
				'pedido_por' => 0,
				'pedido_en'  => null,
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
			'productos' => array_values( array_filter( (array) ( $lineas['productos'] ?? array() ), 'is_array' ) ), // v0.39: lo del catálogo.
		);
		$f['forma_pago']  = (string) ( $f['forma_pago'] ?? '' );
		$f['sal']         = (string) ( $f['sal'] ?? '' );
		$f['invitado']    = ! empty( $f['invitado_enc'] ); // Compra sin sesión (sus datos siguen guardados).
		$f['pago_importe'] = null === $f['pago_importe'] ? null : (float) $f['pago_importe'];
		foreach ( array( 'creado_por', 'pago_por', 'pedido_por', 'entregado_por', 'recibido_por', 'cancelado_por' ) as $k ) {
			$f[ $k ] = (int) $f[ $k ];
		}
		$f['inventario_id'] = (int) ( $f['inventario_id'] ?? 0 );
		return $f;
	}
}
