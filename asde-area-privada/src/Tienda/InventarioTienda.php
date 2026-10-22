<?php
namespace AsdeBalears\AreaPrivada\Tienda;

use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Domain\Grupo;
use AsdeBalears\AreaPrivada\Domain\Grupos;
use AsdeBalears\AreaPrivada\Inventario\MaterialesTable;

defined( 'ABSPATH' ) || exit;

/**
 * La tienda sobre el inventario general (v0.51, doc 55): una fila del inventario marcada «Se vende en la tienda» es una
 * variante de la tienda (agrupada por descripción en un producto) y sus existencias son la cantidad de la fila.
 * El precio, a quién se vende y el límite por persona se siguen poniendo en el producto de la tienda.
 */
final class InventarioTienda {

	/** Cómo se llama en la tienda la fila: su variante («M», «Azul»…), su talla o «Única». */
	public static function etiqueta_de( array $m ): string {
		$e = trim( (string) ( $m['variante_tienda'] ?? '' ) );
		if ( '' === $e ) {
			$e = trim( (string) ( $m['talla'] ?? '' ) );
		}
		return '' === $e ? 'Única' : mb_substr( $e, 0, 80 );
	}

	/**
	 * Deja la tienda como dice la fila del inventario: marcada, crea (o encuentra) el producto y su variante enlazada;
	 * sin marcar o dada de baja, la variante deja de venderse. Los pedidos hechos no cambian.
	 */
	public static function sincronizar( int $id ): void {
		$m = MaterialesTable::obtener( $id );
		if ( ! $m ) {
			return;
		}
		$v = VariantesTable::de_material( $id );
		if ( empty( $m['en_tienda'] ) || null !== $m['baja_en'] ) {
			if ( $v && 1 === (int) ( self::cruda( $v['id'] )['activo'] ?? 0 ) ) {
				VariantesTable::activar( $v['id'], false );
			}
			return;
		}
		$producto = ProductosTable::por_nombre( $m['descripcion'] );
		if ( ! $producto ) {
			$datos = ProductosTable::validar(
				array(
					'nombre'      => $m['descripcion'],
					'visibilidad' => ProductosTable::VIS_SOCIOS, // Hasta que la administración decida, solo la ven los socios.
				)
			);
			if ( is_wp_error( $datos ) ) {
				return;
			}
			$pid      = ProductosTable::crear( $datos );
			$producto = ProductosTable::obtener( $pid );
		} elseif ( 0 === (int) $producto['activo'] ) {
			ProductosTable::archivar( $producto['id'], false );
		}
		if ( ! $producto ) {
			return;
		}
		$etiqueta = self::etiqueta_de( $m );
		if ( $v ) {
			global $wpdb;
			$wpdb->update( VariantesTable::table(), array( 'producto' => $producto['id'], 'etiqueta' => $etiqueta, 'activo' => 1 ), array( 'id' => $v['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		} else {
			VariantesTable::crear(
				$producto['id'],
				array(
					'etiqueta'    => $etiqueta,
					'sku'         => '',
					'precio'      => null,
					'existencias' => 0,
					'ilimitado'   => 0,
					'activo'      => 1,
					'material'    => $id,
				)
			);
		}
	}

	/** Fila de la variante sin el reajuste del inventario (lo que dice su propia tabla). */
	private static function cruda( int $id ): array {
		global $wpdb;
		return (array) $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', VariantesTable::table(), $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Pasa al inventario general lo que ya tenía la tienda, una sola vez por grupo: cada variante sin enlazar se
	 * convierte en una fila del inventario (con sus existencias, marcada «se vende») y queda enlazada. Las ilimitadas
	 * (las que se encargaban al proveedor) pasan con cantidad 0: todo lo que se venda irá al pedido al proveedor.
	 */
	public static function migrar(): void {
		foreach ( Grupos::todos() as $numero => $grupo ) {
			if ( get_option( 'asde_ap_tienda_inv_' . $numero ) ) {
				continue;
			}
			Grupo::con( $grupo, array( self::class, 'migrar_grupo' ) );
			update_option( 'asde_ap_tienda_inv_' . $numero, 1, false );
		}
	}

	/** Lo anterior, para el grupo en el que se trabaja. */
	public static function migrar_grupo(): int {
		$hechas = 0;
		foreach ( ProductosTable::todos( true ) as $p ) {
			foreach ( VariantesTable::de_producto( $p['id'] ) as $v ) {
				if ( $v['material'] > 0 ) {
					continue;
				}
				$id = MaterialesTable::crear(
					array(
						'descripcion'     => mb_substr( $p['nombre'], 0, 255 ),
						'tipo'            => 0,
						'cantidad'        => 1 === $v['ilimitado'] ? 0 : max( 0, (int) $v['existencias'] ),
						'precio'          => null,
						'anio'            => null,
						'num_serie'       => '',
						'notas'           => 'Pasado desde la tienda',
						'modelo'          => '',
						'talla'           => '',
						'en_tienda'       => 1,
						'variante_tienda' => 'Única' === $v['etiqueta'] ? '' : mb_substr( $v['etiqueta'], 0, 80 ),
					)
				);
				if ( ! $id ) {
					continue;
				}
				VariantesTable::actualizar( $v['id'], array( 'material' => $id, 'ilimitado' => 0, 'existencias' => 0 ) );
				if ( 1 === $v['ilimitado'] && ! empty( $p['requiere_proveedor'] ) ) {
					ProductosTable::actualizar( $p['id'], array( 'requiere_proveedor' => 0 ) ); // Ahora manda la cantidad del inventario.
				}
				++$hechas;
			}
		}
		if ( $hechas ) {
			Logger::log( 'tienda_pasada_al_inventario', 'tienda', Grupo::numero(), array( 'variantes' => $hechas ) );
		}
		return $hechas;
	}
}
