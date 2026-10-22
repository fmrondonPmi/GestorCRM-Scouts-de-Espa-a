<?php
/**
 * Pruebas de la v0.51 (entorno LOCAL, doc 55): la tienda sobre el inventario general.
 * Ejecutar desde app/public del site Local:
 *
 *   wp eval-file <ruta completa>/wp-content/plugins/asde-area-privada/tests/tienda-inventario.php
 *
 * No toca el CRM ni envía emails. Borra todo lo que crea y restaura la configuración de la tienda.
 */
defined( 'ABSPATH' ) || exit;

use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Domain\Grupo;
use AsdeBalears\AreaPrivada\Domain\Grupos;
use AsdeBalears\AreaPrivada\Inventario\MaterialesTable;
use AsdeBalears\AreaPrivada\Tienda\Config;
use AsdeBalears\AreaPrivada\Tienda\InventarioTienda;
use AsdeBalears\AreaPrivada\Tienda\PedidosTable;
use AsdeBalears\AreaPrivada\Tienda\ProductosTable;
use AsdeBalears\AreaPrivada\Tienda\Proveedor;
use AsdeBalears\AreaPrivada\Tienda\Servicio;
use AsdeBalears\AreaPrivada\Tienda\VariantesTable;
use AsdeBalears\AreaPrivada\Usuarios\RegistroEmails;

global $wpdb;

$fails = 0;
$ok    = function ( $cond, string $label ) use ( &$fails ) {
	if ( ! $cond ) {
		++$fails;
	}
	echo ( $cond ? 'OK   ' : 'FAIL ' ), $label, "\n";
};

$grupo = Grupos::por_numero( '684' ) ?? ( array_values( Grupos::todos() )[0] ?? null );
if ( ! $grupo ) {
	exit( "Hace falta un grupo dado de alta.\n" );
}
Grupo::forzar( $grupo );

$max_id = static fn( string $t ) => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(MAX(id), 0) FROM %i', $t ) ); // phpcs:ignore WordPress.DB
$tablas = array(
	'mat'  => MaterialesTable::table(),
	'prod' => ProductosTable::table(),
	'var'  => VariantesTable::table(),
	'ped'  => PedidosTable::table(),
	'log'  => Logger::table(),
	'mail' => RegistroEmails::table(),
);
$antes     = array_map( $max_id, $tablas );
$cfg_antes = get_option( 'asde_ap_tienda_' . $grupo['numero'], null );
add_filter( 'pre_wp_mail', '__return_true' );

$cfg            = Config::de();
$cfg['abierto'] = true;
$cfg['publica'] = true;
Config::guardar( $cfg );

$material = static function ( string $desc, string $variante, int $cantidad ): int {
	return MaterialesTable::crear(
		array(
			'descripcion'     => $desc,
			'tipo'            => 0,
			'cantidad'        => $cantidad,
			'precio'          => null,
			'anio'            => null,
			'num_serie'       => '',
			'notas'           => '',
			'modelo'          => '',
			'talla'           => '',
			'en_tienda'       => 1,
			'variante_tienda' => $variante,
		)
	);
};
$datos = array( 'nombre' => 'Ana Prueba', 'email' => 'ana-inv@example.invalid', 'telefono' => '', 'idioma' => 'es' );
$cant  = static fn( int $id ): int => (int) MaterialesTable::obtener( $id )['cantidad'];

try {
	echo "== A. Marcar una fila del inventario ==\n";
	$mM = $material( 'Camisa de prueba inventario', 'M', 3 );
	$mL = $material( 'Camisa de prueba inventario', 'L', 0 );
	InventarioTienda::sincronizar( $mM );
	InventarioTienda::sincronizar( $mL );
	$vM = VariantesTable::de_material( $mM );
	$vL = VariantesTable::de_material( $mL );
	$p  = ProductosTable::por_nombre( 'Camisa de prueba inventario' );
	$ok( $vM && $vL && $p && $vM['producto'] === $p['id'] && $vL['producto'] === $p['id'], 'Las filas con la misma descripción forman un producto, con una variante cada una' );
	$ok( 'M' === $vM['etiqueta'] && 'L' === $vL['etiqueta'], 'La variante se llama como dice «Nombre en la tienda»' );
	$ok( 3 === $vM['existencias'] && 0 === $vL['existencias'], 'Las existencias de la tienda son la cantidad de la fila del inventario' );
	$ok( 'socios' === $p['visibilidad'] && null === $p['precio'], 'El producto nace solo para socios y con el precio por confirmar' );
	$ok( 1 === count( array_filter( ProductosTable::todos( true ), static fn( $x ) => 'Camisa de prueba inventario' === $x['nombre'] ) ), 'No se duplica el producto' );
	InventarioTienda::sincronizar( $mM );
	$ok( 2 === count( VariantesTable::de_producto( $p['id'] ) ), 'Sincronizar otra vez no duplica variantes' );
	MaterialesTable::dar_baja( $mM, 'prueba' );
	InventarioTienda::sincronizar( $mM );
	$ok( 0 === VariantesTable::obtener( $vM['id'] )['activo'], 'Dar de baja la fila retira la variante de la venta' );
	MaterialesTable::recuperar( $mM );
	InventarioTienda::sincronizar( $mM );
	$ok( 1 === VariantesTable::obtener( $vM['id'] )['activo'], 'Recuperarla la vuelve a poner' );
	MaterialesTable::actualizar( $mM, array( 'en_tienda' => 0 ) );
	InventarioTienda::sincronizar( $mM );
	$ok( 0 === VariantesTable::obtener( $vM['id'] )['activo'], 'Desmarcar «Se vende en la tienda» la retira' );
	MaterialesTable::actualizar( $mM, array( 'en_tienda' => 1 ) );
	InventarioTienda::sincronizar( $mM );
	$ok( 1 === VariantesTable::obtener( $vM['id'] )['activo'], 'Y al volver a marcarla se vende otra vez' );
	$ok( ! empty( MaterialesTable::validar( array( 'descripcion' => 'x', 'tipo' => 0, 'cantidad' => '1', 'en_tienda' => '1', 'variante_tienda' => 'Z' ) )['en_tienda'] ), 'El formulario del inventario guarda la casilla y el nombre en la tienda' );

	echo "\n== B. Vender con y sin existencias ==\n";
	ProductosTable::actualizar( $p['id'], array( 'visibilidad' => 'todos', 'precio' => 5.0 ) );
	$id1 = Servicio::comprar_invitado( $datos, array( $vM['id'] => 2 ), '', 'bizum' );
	$p1  = is_int( $id1 ) ? PedidosTable::obtener( $id1 ) : null;
	$ok( is_int( $id1 ) && 1 === $cant( $mM ), 'Comprar 2 con 3 en el inventario las descuenta de la fila (queda 1)' );
	$l = $p1['lineas']['productos'][0] ?? array();
	$ok( 2 === ( $l['reservadas'] ?? -1 ) && 0 === ( $l['faltan'] ?? -1 ) && null !== $p1['pedido_en'], 'La línea no falta nada y el pedido no espera al proveedor' );
	$id2 = Servicio::comprar_invitado( $datos, array( $vM['id'] => 3 ), '', 'bizum' );
	$p2  = is_int( $id2 ) ? PedidosTable::obtener( $id2 ) : null;
	$l2  = $p2['lineas']['productos'][0] ?? array();
	$ok( is_int( $id2 ) && 0 === $cant( $mM ) && 1 === ( $l2['reservadas'] ?? -1 ) && 2 === ( $l2['faltan'] ?? -1 ), 'Comprar 3 con 1 en el inventario se vende igual: salen la que hay (0 en la fila) y 2 quedan pendientes' );
	$ok( null === $p2['pedido_en'], 'Ese pedido tiene algo que pedir al proveedor' );
	PedidosTable::marcar_pago( $id2, wp_date( 'Y-m-d' ), 'transferencia', 15.0, 'PRUEBA' );
	$id3 = Servicio::comprar_invitado( $datos, array( $vL['id'] => 2 ), '', 'bizum' );
	$ok( is_int( $id3 ) && 0 === $cant( $mL ), 'Comprar lo que no tiene existencias también se puede (sin que la fila baje de 0)' );
	PedidosTable::marcar_pago( $id3, wp_date( 'Y-m-d' ), 'transferencia', 10.0, 'PRUEBA' );
	$por_pedir = Proveedor::lineas_de( array( PedidosTable::obtener( $id2 ), PedidosTable::obtener( $id3 ) ) );
	$sumas     = array();
	foreach ( $por_pedir as $x ) {
		$sumas[ $x['etiqueta'] ] = $x['cantidad'];
	}
	$ok( array( 'L' => 2, 'M' => 2 ) === $sumas, 'El pedido al proveedor lleva solo lo que faltaba: 2 de M y 2 de L' );
	$ids = array_column( Proveedor::por_pedir(), 'id' );
	$ok( in_array( $id2, $ids, true ) && in_array( $id3, $ids, true ) && ! in_array( $id1, $ids, true ), 'En «por pedir» salen los pagados con faltantes, no el que salió entero del almacén' );
	$ok( Proveedor::unidades_a_pedir( array( 'cantidad' => 5, 'proveedor' => 1 ) ) === 5 && Proveedor::unidades_a_pedir( array( 'cantidad' => 5, 'faltan' => 2 ) ) === 2 && 0 === Proveedor::unidades_a_pedir( array( 'cantidad' => 5 ) ), 'Un producto «se encarga al proveedor» pide todo; uno enlazado, solo lo que falta' );

	echo "\n== C. Cancelar, sobrante y cambios ==\n";
	Servicio::cancelar( PedidosTable::obtener( $id2 ), 'prueba' );
	$ok( 1 === $cant( $mM ), 'Cancelar devuelve al inventario solo lo que había salido (1), no las 3 pedidas' );
	$ok( true === VariantesTable::ajustar( $vM['id'], 2, 'sobrante' ) && 3 === $cant( $mM ), 'El sobrante del proveedor suma a la fila del inventario' );
	$ok( false === VariantesTable::ajustar( $vM['id'], -9, 'demasiado' ) && 3 === $cant( $mM ), 'No se puede quitar más de lo que hay' );
	$ok( ! empty( VariantesTable::obtener( $vM['id'] )['material'] ) && 0 === VariantesTable::obtener( $vM['id'] )['ilimitado'], 'Una variante enlazada nunca es «sin límite»' );

	echo "\n== D. Lo que ya tenía la tienda pasa al inventario ==\n";
	$pid = ProductosTable::crear( ProductosTable::validar( array( 'nombre' => 'Taza antigua de prueba', 'visibilidad' => 'todos', 'requiere_proveedor' => '1' ) ) );
	$vA  = VariantesTable::crear( $pid, VariantesTable::validar( array( 'etiqueta' => 'Azul', 'existencias' => '4' ) ) );
	$vB  = VariantesTable::crear( $pid, VariantesTable::validar( array( 'etiqueta' => 'Roja', 'existencias' => 'ilimitado' ) ) );
	$n   = InventarioTienda::migrar_grupo();
	$a   = VariantesTable::obtener( $vA );
	$b   = VariantesTable::obtener( $vB );
	$ok( $n >= 2 && $a['material'] > 0 && $b['material'] > 0, 'Cada variante sin enlazar se convierte en una fila del inventario enlazada' );
	$ok( 4 === $cant( $a['material'] ) && 0 === $cant( $b['material'] ), 'Con sus existencias (y 0 las que no tenían límite)' );
	$ma = MaterialesTable::obtener( $a['material'] );
	$ok( 'Taza antigua de prueba' === $ma['descripcion'] && 'Azul' === $ma['variante_tienda'] && 1 === $ma['en_tienda'], 'La fila lleva la descripción del producto, el nombre en la tienda y «se vende»' );
	$ok( 0 === ProductosTable::obtener( $pid )['requiere_proveedor'], 'El producto ya no pide todo al proveedor: manda la cantidad del inventario' );
	$ok( 0 === InventarioTienda::migrar_grupo(), 'Pasarlo otra vez no hace nada más' );
} finally {
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id > %d', $tablas['mat'], $antes['mat'] ) ); // phpcs:ignore WordPress.DB
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id > %d', $tablas['var'], $antes['var'] ) ); // phpcs:ignore WordPress.DB
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id > %d', $tablas['prod'], $antes['prod'] ) ); // phpcs:ignore WordPress.DB
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id > %d', $tablas['ped'], $antes['ped'] ) ); // phpcs:ignore WordPress.DB
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id > %d', $tablas['log'], $antes['log'] ) ); // phpcs:ignore WordPress.DB
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id > %d', $tablas['mail'], $antes['mail'] ) ); // phpcs:ignore WordPress.DB
	if ( null === $cfg_antes ) {
		delete_option( 'asde_ap_tienda_' . $grupo['numero'] );
	} else {
		update_option( 'asde_ap_tienda_' . $grupo['numero'], $cfg_antes, false );
	}
	remove_filter( 'pre_wp_mail', '__return_true' );
	Grupo::forzar( null );
}

echo $fails ? "\nHAY $fails FALLOS\n" : "\nTODO OK\n";
