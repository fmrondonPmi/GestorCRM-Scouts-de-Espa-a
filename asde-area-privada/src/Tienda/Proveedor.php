<?php
namespace AsdeBalears\AreaPrivada\Tienda;

use AsdeBalears\AreaPrivada\Audit\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Pedido de camisas (y de lo demás que se encarga al proveedor) (v0.48, doc 52, T4): Coordinación reúne lo que los
 * clientes ya han pagado y la tienda no tiene en existencias, hace un pedido al proveedor y, cuando llega, lo marca
 * como recibido. Lo que llega de más que lo pedido para clientes **suma a las existencias** de la tienda (P-181).
 *
 * - Ve la pestaña Coordinación, Tesorería y la administración de la tienda; hace y recibe pedidos Coordinación
 *   (`asde_tienda_pedido`).
 * - Un pedido de cliente que va en un pedido al proveedor **no se puede entregar hasta que ese pedido llega**.
 */
final class Proveedor {

	/** Pedidos de clientes que ya han llegado (se lee una vez por petición). @var array<int, true>|null */
	private static ?array $llegados = null;

	public static function puede_ver(): bool {
		return current_user_can( Servicio::CAP_PEDIDO ) || current_user_can( Servicio::CAP_PAGO ) || current_user_can( Servicio::CAP_ADMIN );
	}

	public static function puede_gestionar(): bool {
		return current_user_can( Servicio::CAP_PEDIDO );
	}

	/** Vacía lo guardado en la petición (pruebas y tras recibir un pedido). */
	public static function olvidar(): void {
		self::$llegados = null;
	}

	/** ¿Va en un pedido al proveedor que todavía no ha llegado? Entonces no se puede entregar. */
	public static function esperando( array $p ): bool {
		if ( (int) ( $p['proveedor_id'] ?? 0 ) <= 0 ) {
			return false;
		}
		self::$llegados ??= ProveedorTable::llegados();
		return ! isset( self::$llegados[ (int) $p['proveedor_id'] ] );
	}

	/** ¿Tiene algo que se pide al proveedor? (productos marcados «se encarga al proveedor» o, en los antiguos, camisa o insignias.) */
	private static function lleva_proveedor( array $p ): bool {
		if ( $p['lineas']['camisa'] || $p['lineas']['insignias'] ) {
			return true;
		}
		foreach ( $p['lineas']['productos'] as $l ) {
			if ( ! empty( $l['proveedor'] ) ) {
				return true;
			}
		}
		return false;
	}

	/** Pedidos ya pagados que están esperando entrar en un pedido al proveedor. @return array[] */
	public static function por_pedir(): array {
		return array_values(
			array_filter(
				PedidosTable::todos(),
				static fn( array $p ) => null !== $p['pago_en'] && null === $p['cancelado_en'] && null === $p['pedido_en'] && null === $p['entregado_en'] && self::lleva_proveedor( $p )
			)
		);
	}

	/** Cuántos pedidos sin pagar llevarían algo al proveedor (se avisa de que aún no entran). */
	public static function sin_pagar(): int {
		return count(
			array_filter(
				PedidosTable::todos(),
				static fn( array $p ) => null === $p['pago_en'] && null === $p['cancelado_en'] && null === $p['pedido_en'] && self::lleva_proveedor( $p )
			)
		);
	}

	/**
	 * Lo que hay que pedir para estos pedidos, junto por producto y talla.
	 *
	 * @param array[] $pedidos
	 * @return array<int, array{clave:string, producto:int, variante:int, nombre:string, etiqueta:string, cantidad:int, recibida:?int}>
	 */
	public static function lineas_de( array $pedidos ): array {
		$out = array();
		$sum = static function ( string $clave, int $producto, int $variante, string $nombre, string $etiqueta, int $cantidad ) use ( &$out ): void {
			if ( ! isset( $out[ $clave ] ) ) {
				$out[ $clave ] = array(
					'clave'    => $clave,
					'producto' => $producto,
					'variante' => $variante,
					'nombre'   => $nombre,
					'etiqueta' => $etiqueta,
					'cantidad' => 0,
					'recibida' => null,
				);
			}
			$out[ $clave ]['cantidad'] += $cantidad;
		};
		foreach ( $pedidos as $p ) {
			$c = $p['lineas']['camisa'];
			if ( $c ) { // Pedidos anteriores a la v0.44.
				$sum( 'camisa|' . $c['modelo'] . '|' . $c['talla'], 0, 0, (string) $c['nombre'], (string) $c['talla'], 1 );
			}
			foreach ( $p['lineas']['insignias'] as $i ) {
				$sum( 'insignia|' . ( $i['id'] ?? $i['nombre'] ), 0, 0, (string) $i['nombre'], '', (int) $i['cantidad'] );
			}
			foreach ( $p['lineas']['productos'] as $l ) {
				if ( ! empty( $l['proveedor'] ) ) {
					$etiqueta = 'Única' === ( $l['etiqueta'] ?? '' ) ? '' : (string) ( $l['etiqueta'] ?? '' );
					$sum( 'p|' . $l['producto'] . '|' . $l['variante'], (int) $l['producto'], (int) $l['variante'], (string) $l['nombre'], $etiqueta, (int) $l['cantidad'] );
				}
			}
		}
		uasort( $out, static fn( array $a, array $b ) => strcmp( $a['nombre'] . $a['etiqueta'], $b['nombre'] . $b['etiqueta'] ) );
		return array_values( $out );
	}

	/**
	 * Hace un pedido al proveedor con estos pedidos de clientes (pagados y sin pedir).
	 *
	 * @param int[] $ids
	 * @return int|\WP_Error Id del pedido al proveedor.
	 */
	public static function crear( string $nombre, array $ids ) {
		if ( ! self::puede_gestionar() ) {
			return new \WP_Error( 'asde_proveedor', asde_t( 'Los pedidos al proveedor los hace Coordinación.' ) );
		}
		$ids      = array_map( 'intval', $ids );
		$elegidos = array_values( array_filter( self::por_pedir(), static fn( array $p ) => in_array( $p['id'], $ids, true ) ) );
		if ( ! $elegidos ) {
			return new \WP_Error( 'asde_proveedor', asde_t( 'Marca los pedidos que quieres incluir (los pagados que aún no están en un pedido).' ) );
		}
		$nombre = trim( sanitize_text_field( $nombre ) );
		if ( '' === $nombre ) {
			/* translators: %s: fecha */
			$nombre = sprintf( asde_t( 'Pedido %s' ), wp_date( 'd/m/Y' ) );
		}
		$id = ProveedorTable::crear( $nombre, self::lineas_de( $elegidos ), count( $elegidos ) );
		if ( ! $id ) {
			return new \WP_Error( 'asde_proveedor', asde_t( 'No se ha podido guardar el pedido.' ) );
		}
		$ids_ok = array_column( $elegidos, 'id' );
		PedidosTable::marcar_pedido( $ids_ok, $nombre );
		PedidosTable::marcar_proveedor( $ids_ok, $id );
		Logger::log( 'tienda_proveedor_pedido', 'tienda', (string) $id, array( 'pedidos' => count( $ids_ok ) ) );
		return $id;
	}

	/**
	 * Marca que ha llegado. Lo recibido de más que lo pedido suma a las existencias; si falta algo, se anota.
	 *
	 * @param array<string, int|string> $recibidas clave de la línea => unidades recibidas (vacío = las pedidas).
	 * @return array{sobrante:int, faltan:int}|\WP_Error
	 */
	public static function recibir( int $id, array $recibidas ) {
		if ( ! self::puede_gestionar() ) {
			return new \WP_Error( 'asde_proveedor', asde_t( 'Los pedidos al proveedor los hace Coordinación.' ) );
		}
		$pp = ProveedorTable::obtener( $id );
		if ( ! $pp || null !== $pp['recibido_en'] ) {
			return new \WP_Error( 'asde_proveedor', asde_t( 'Ese pedido ya está marcado como recibido.' ) );
		}
		$sobrante = 0;
		$faltan   = 0;
		$lineas   = array();
		foreach ( $pp['lineas'] as $l ) {
			$r             = $recibidas[ $l['clave'] ] ?? '';
			$recibida      = '' === (string) $r ? (int) $l['cantidad'] : max( 0, min( 99999, (int) $r ) );
			$l['recibida'] = $recibida;
			$faltan       += max( 0, (int) $l['cantidad'] - $recibida );
			$lineas[]      = $l;
		}
		if ( ! ProveedorTable::recibir( $id, $lineas ) ) {
			return new \WP_Error( 'asde_proveedor', asde_t( 'Ese pedido ya está marcado como recibido.' ) );
		}
		foreach ( $lineas as $l ) {
			$extra = (int) $l['recibida'] - (int) $l['cantidad'];
			if ( $extra > 0 && (int) $l['variante'] > 0 && VariantesTable::ajustar( (int) $l['variante'], $extra, sprintf( 'Sobrante del pedido al proveedor «%s»', $pp['nombre'] ) ) ) {
				$sobrante += $extra;
			}
		}
		self::olvidar();
		Logger::log( 'tienda_proveedor_recibido', 'tienda', (string) $id, array( 'sobrante' => $sobrante, 'faltan' => $faltan ) );
		return array(
			'sobrante' => $sobrante,
			'faltan'   => $faltan,
		);
	}

	/**
	 * Filas para enviar el pedido al proveedor: producto, talla y cantidad.
	 *
	 * @param array $pp Pedido al proveedor (ProveedorTable).
	 * @return array<int, array{0:string, 1:string, 2:int}>
	 */
	public static function filas_excel( array $pp ): array {
		return array_map( static fn( array $l ) => array( (string) $l['nombre'], (string) $l['etiqueta'], (int) $l['cantidad'] ), $pp['lineas'] );
	}
}
