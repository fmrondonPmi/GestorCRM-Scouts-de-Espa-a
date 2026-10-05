<?php
namespace AsdeBalears\AreaPrivada\Inventario;

use AsdeBalears\AreaPrivada\Domain\Grupo;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- tablas propias del plugin ({prefix}asde_*): no hay función de WordPress para ellas y los datos cambian en cada petición, no se cachean.

/**
 * Listas de compra de material ({prefix}asde_inventario_compras).
 *
 * Cada línea es una copia del material en el momento de crear la lista
 * (descripción, tipo, nº de serie, cantidad que había), con la cantidad a
 * comprar y el precio estimado: aunque después se cambie o se dé de baja el
 * material, la lista sigue diciendo lo mismo.
 *
 * Estados: pendiente (espera el visto bueno de Tesorería) → aprobada (ya se
 * puede imprimir) o rechazada (con motivo); cancelada en cualquier momento antes
 * de imprimirla. Nunca se borran.
 */
final class ComprasTable {

	public const PENDIENTE = 'pendiente';
	public const APROBADA  = 'aprobada';
	public const RECHAZADA = 'rechazada';
	public const CANCELADA = 'cancelada';

	public const ESTADOS = array(
		self::PENDIENTE => 'Pendiente del visto bueno',
		self::APROBADA  => 'Con el visto bueno',
		self::RECHAZADA => 'Rechazada',
		self::CANCELADA => 'Cancelada',
	);

	public const MAX_LINEAS = 200;

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'asde_inventario_compras';
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
				lineas longtext NOT NULL,
				total decimal(12,2) NOT NULL DEFAULT 0,
				nota varchar(500) NOT NULL DEFAULT '',
				estado varchar(12) NOT NULL DEFAULT 'pendiente',
				creado datetime NOT NULL,
				creado_por bigint(20) unsigned NOT NULL DEFAULT 0,
				revisado_en datetime NULL,
				revisado_por bigint(20) unsigned NOT NULL DEFAULT 0,
				motivo varchar(255) NOT NULL DEFAULT '',
				avisados smallint(5) unsigned NOT NULL DEFAULT 0,
				impresiones int(11) unsigned NOT NULL DEFAULT 0,
				impresa_en datetime NULL,
				PRIMARY KEY  (id),
				KEY grupo (grupo),
				KEY estado (estado)
			) $charset;"
		);
	}

	/** Nº de la lista: LC + grupo + id (LC6840003). */
	public static function numero( array $l ): string {
		return 'LC' . $l['grupo'] . str_pad( (string) $l['id'], 4, '0', STR_PAD_LEFT );
	}

	/**
	 * @param array[] $lineas Cada una: material, descripcion, tipo, num_serie, en_inventario, cantidad, precio (float|null).
	 * @return int Id de la lista.
	 */
	public static function crear( array $lineas, string $nota ): int {
		global $wpdb;
		$wpdb->insert(
			self::table(),
			array(
				'grupo'      => Grupo::numero(),
				'lineas'     => (string) wp_json_encode( array_values( $lineas ) ),
				'total'      => self::total( $lineas ),
				'nota'       => mb_substr( sanitize_textarea_field( $nota ), 0, 500 ),
				'estado'     => self::PENDIENTE,
				'creado'     => current_time( 'mysql', true ),
				'creado_por' => get_current_user_id(),
			)
		);
		return (int) $wpdb->insert_id;
	}

	/** Total estimado: cantidad × precio de las líneas que tienen precio. */
	public static function total( array $lineas ): float {
		$total = 0.0;
		foreach ( $lineas as $l ) {
			$total += null === $l['precio'] ? 0.0 : (int) $l['cantidad'] * (float) $l['precio'];
		}
		return round( $total, 2 );
	}

	/** ¿Alguna línea sin precio? (el total es solo de las que lo tienen). */
	public static function sin_precio( array $lista ): bool {
		foreach ( $lista['lineas'] as $l ) {
			if ( null === $l['precio'] ) {
				return true;
			}
		}
		return false;
	}

	public static function obtener( int $id ): ?array {
		global $wpdb;
		$fila = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d AND grupo = %s', self::table(), $id, Grupo::numero() ), ARRAY_A );
		return $fila ? self::preparar( $fila ) : null;
	}

	/** @return array[] Las del grupo actual, las más recientes primero. */
	public static function todas(): array {
		global $wpdb;
		$filas = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE grupo = %s ORDER BY id DESC LIMIT 500', self::table(), Grupo::numero() ), ARRAY_A );
		return array_map( array( self::class, 'preparar' ), $filas );
	}

	public static function contar_pendientes(): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE grupo = %s AND estado = %s', self::table(), Grupo::numero(), self::PENDIENTE ) );
	}

	public static function fijar_avisados( int $id, int $n ): void {
		self::cambiar( $id, array( 'avisados' => max( 0, min( 65535, $n ) ) ) );
	}

	/** Visto bueno o rechazo de Tesorería (solo de una pendiente). */
	public static function revisar( int $id, bool $aprobada, string $motivo = '' ): bool {
		return self::cambiar(
			$id,
			array(
				'estado'       => $aprobada ? self::APROBADA : self::RECHAZADA,
				'revisado_en'  => current_time( 'mysql', true ),
				'revisado_por' => get_current_user_id(),
				'motivo'       => mb_substr( sanitize_text_field( $motivo ), 0, 255 ),
			),
			self::PENDIENTE
		);
	}

	/** Deshace el visto bueno (solo si aún no se ha impreso): vuelve a pendiente. */
	public static function deshacer_aprobacion( int $id ): bool {
		$l = self::obtener( $id );
		if ( ! $l || $l['impresiones'] ) {
			return false;
		}
		return self::cambiar(
			$id,
			array(
				'estado'       => self::PENDIENTE,
				'revisado_en'  => null,
				'revisado_por' => 0,
				'motivo'       => '',
			),
			self::APROBADA
		);
	}

	public static function cancelar( int $id, string $motivo ): bool {
		$l = self::obtener( $id );
		if ( ! $l || $l['impresiones'] || ! in_array( $l['estado'], array( self::PENDIENTE, self::APROBADA ), true ) ) {
			return false;
		}
		return self::cambiar(
			$id,
			array(
				'estado'       => self::CANCELADA,
				'revisado_en'  => current_time( 'mysql', true ),
				'revisado_por' => get_current_user_id(),
				'motivo'       => mb_substr( sanitize_text_field( $motivo ), 0, 255 ),
			),
			$l['estado']
		);
	}

	/** Cuenta una descarga del PDF (la primera fecha se guarda). */
	public static function marcar_impresa( int $id ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET impresiones = impresiones + 1, impresa_en = COALESCE(impresa_en, %s) WHERE id = %d AND grupo = %s', self::table(), current_time( 'mysql', true ), $id, Grupo::numero() ) );
	}

	/** Cambia la fila solo si está en el estado esperado (dos personas a la vez no pisan la decisión de la otra). */
	private static function cambiar( int $id, array $datos, string $estado = '' ): bool {
		global $wpdb;
		$donde = array(
			'id'    => $id,
			'grupo' => Grupo::numero(),
		);
		if ( '' !== $estado ) {
			$donde['estado'] = $estado;
		}
		return (int) $wpdb->update( self::table(), $datos, $donde ) > 0;
	}

	private static function preparar( array $f ): array {
		foreach ( array( 'id', 'creado_por', 'revisado_por', 'avisados', 'impresiones' ) as $k ) {
			$f[ $k ] = (int) $f[ $k ];
		}
		$f['total']  = (float) $f['total'];
		$lineas      = json_decode( (string) $f['lineas'], true );
		$f['lineas'] = array();
		foreach ( is_array( $lineas ) ? $lineas : array() as $l ) {
			if ( ! is_array( $l ) ) {
				continue;
			}
			$f['lineas'][] = array(
				'material'      => (int) ( $l['material'] ?? 0 ),
				'descripcion'   => (string) ( $l['descripcion'] ?? '' ),
				'tipo'          => (string) ( $l['tipo'] ?? '' ),
				'num_serie'     => (string) ( $l['num_serie'] ?? '' ),
				'en_inventario' => (int) ( $l['en_inventario'] ?? 0 ),
				'cantidad'      => (int) ( $l['cantidad'] ?? 0 ),
				'precio'        => isset( $l['precio'] ) && null !== $l['precio'] ? (float) $l['precio'] : null,
			);
		}
		return $f;
	}
}
