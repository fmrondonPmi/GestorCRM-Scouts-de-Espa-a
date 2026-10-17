<?php
namespace AsdeBalears\AreaPrivada\Tienda;

use AsdeBalears\AreaPrivada\Domain\Grupo;
use AsdeBalears\AreaPrivada\Domain\Grupos;

defined( 'ABSPATH' ) || exit;

/**
 * Doc 48, fase 2: la camisa y las insignias que Uniformes tenía en su configuración pasan a ser productos
 * del catálogo de la tienda, con una variante por modelo y talla. Es idempotente: un producto que ya existe
 * (por su `ref`: `camisa`, `insignia:<id>`) no se vuelve a crear ni se toca, así que lo que la administración
 * cambie después se respeta.
 *
 * La configuración de Uniformes sigue mandando en los pedidos hasta la fase 3, cuando el pedido pase a leer
 * del catálogo; mientras, los dos conviven sin molestarse. Las variantes de lo sembrado son ilimitadas: la
 * camisa y las insignias se piden al proveedor, no salen de un almacén (las del inventario del grupo siguen
 * descontándose en el inventario, v0.29).
 */
final class Sembrado {

	/** Siembra todos los grupos. */
	public static function ejecutar(): void {
		foreach ( Grupos::todos() as $numero => $g ) {
			Grupo::con( $g, static fn() => self::grupo() );
		}
	}

	/** Siembra el grupo actual. @return int Productos creados. */
	public static function grupo(): int {
		$cfg    = Config::de();
		$creados = 0;

		if ( ! ProductosTable::por_ref( 'camisa' ) ) {
			$id = ProductosTable::crear(
				array(
					'nombre'             => 'Camisa scout',
					'descripcion'        => '',
					'categoria'          => 'Uniforme',
					'visibilidad'        => ProductosTable::VIS_SOCIOS,
					'precio'             => (float) $cfg['precio_camisa'],
					'precio_socio'       => null,
					'limite_persona'     => 0,
					'requiere_proveedor' => 1,
					'sale_inventario'    => 1,
					'activo'             => 1,
				),
				'camisa'
			);
			foreach ( $cfg['modelos'] as $modelo => $m ) {
				foreach ( $m['tallas'] as $talla ) {
					VariantesTable::crear(
						$id,
						array(
							'etiqueta'    => mb_substr( $m['nombre'] . ' · ' . $talla, 0, 80 ),
							'sku'         => '',
							'precio'      => null,
							'existencias' => 0,
							'ilimitado'   => 1,
						),
						$modelo . '|' . $talla
					);
				}
			}
			++$creados;
		}

		foreach ( $cfg['insignias'] as $iid => $ins ) {
			$ref = 'insignia:' . $iid;
			if ( ProductosTable::por_ref( $ref ) ) {
				continue;
			}
			$id = ProductosTable::crear(
				array(
					'nombre'             => mb_substr( (string) $ins['nombre'], 0, 120 ),
					'descripcion'        => '' !== (string) ( $ins['lugar'] ?? '' ) ? 'Dónde va: ' . mb_substr( (string) $ins['lugar'], 0, 60 ) : '',
					'categoria'          => 'Uniforme',
					'visibilidad'        => ProductosTable::VIS_SOCIOS,
					'precio'             => null === ( $ins['precio'] ?? null ) ? null : (float) $ins['precio'],
					'precio_socio'       => null,
					'limite_persona'     => Config::MAX_INSIGNIAS,
					'requiere_proveedor' => 1,
					'sale_inventario'    => 0,
					'activo'             => ! empty( $ins['activa'] ) ? 1 : 0,
				),
				$ref,
				(int) ( $ins['imagen'] ?? 0 )
			);
			VariantesTable::crear(
				$id,
				array(
					'etiqueta'    => 'Única',
					'sku'         => '',
					'precio'      => null,
					'existencias' => 0,
					'ilimitado'   => 1,
				),
				'unica'
			);
			++$creados;
		}
		return $creados;
	}
}
