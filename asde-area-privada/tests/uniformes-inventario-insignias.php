<?php
/**
 * Pruebas de la v0.41 (entorno LOCAL): servir del inventario no solo la camisa sino también las insignias del uniforme. Si lo que
 * pide un asociado está en el inventario del grupo, se sirve y se descuenta, y esa parte no va al pedido al proveedor. Ejecutar desde
 * app/public del site Local:
 *
 *   wp eval-file <ruta completa>/wp-content/plugins/asde-area-privada/tests/uniformes-inventario-insignias.php
 *
 * Sin CRM ni datos de personas. Al terminar se borra lo creado y se restaura la configuración de la tienda.
 */
defined( 'ABSPATH' ) || exit;

require_once ABSPATH . 'wp-admin/includes/user.php';

use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Auth\Ambito;
use AsdeBalears\AreaPrivada\Domain\Grupo;
use AsdeBalears\AreaPrivada\Domain\Grupos;
use AsdeBalears\AreaPrivada\Frontend\Notices;
use AsdeBalears\AreaPrivada\Frontend\Views\TiendaGestionView;
use AsdeBalears\AreaPrivada\Install\Roles;
use AsdeBalears\AreaPrivada\Inventario\MaterialesTable;
use AsdeBalears\AreaPrivada\Inventario\TiposTable;
use AsdeBalears\AreaPrivada\Tienda\Config;
use AsdeBalears\AreaPrivada\Tienda\PedidosTable;
use AsdeBalears\AreaPrivada\Tienda\Servicio;
use AsdeBalears\AreaPrivada\Usuarios\Usuarios;

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
	exit( "Hace falta un grupo.\n" );
}
foreach ( get_users( array( 'search' => 'prueba-ii-*', 'search_columns' => array( 'user_login' ) ) ) as $u ) {
	exit( 'Ya existe el usuario ' . $u->user_login . " (¿una ejecución anterior interrumpida?). Bórralo antes de repetir la prueba.\n" );
}
wp_set_current_user( 0 );
Grupo::forzar( $grupo );

$max_id     = static fn( string $t ) => (int) $wpdb->get_var( "SELECT COALESCE(MAX(id), 0) FROM $t" ); // phpcs:ignore WordPress.DB
$log_antes  = $max_id( Logger::table() );
$ped_antes  = $max_id( PedidosTable::table() );
$mat_antes  = $max_id( MaterialesTable::table() );
$opcion_cfg = 'asde_ap_tienda_' . $grupo['numero'];
$cfg_antes  = get_option( $opcion_cfg, null );
TiposTable::camisa();

$redirigido = '';
add_filter(
	'wp_redirect',
	$redirigir = static function ( $url ) use ( &$redirigido ) {
		$redirigido = (string) $url;
		throw new RuntimeException( 'asde-redirect:' . $url );
	},
	1
);
$ids   = array();
$crear = static function ( string $login, array $roles ) use ( &$ids, $grupo ): int {
	$id = wp_insert_user( array( 'user_login' => $login, 'user_email' => '', 'user_pass' => wp_generate_password( 24 ), 'role' => $roles[0] ) );
	foreach ( array_slice( $roles, 1 ) as $r ) {
		get_userdata( $id )->add_role( $r );
	}
	update_user_meta( $id, Usuarios::META_GRUPO, $grupo['id'] );
	update_user_meta( $id, Usuarios::META_ESTADO, Usuarios::ACTIVO );
	$ids[ $login ] = $id;
	return $id;
};
$u_coord = $crear( 'prueba-ii-coord', array( Roles::SCOUTER, Roles::COORDINACION ) );
$u_sc    = $crear( 'prueba-ii-sc', array( Roles::SCOUTER ) );
$como    = static function ( int $uid ): void {
	wp_set_current_user( $uid );
	Ambito::olvidar( $uid );
};
$enviar = static function ( array $campos, string $nonce ): string {
	$_POST    = $campos + array( '_wpnonce' => wp_create_nonce( $nonce ) );
	$_REQUEST = $_POST;
	try {
		( new TiendaGestionView() )->handle_post();
	} catch ( RuntimeException $e ) {
		// Redirige.
	} finally {
		$_POST    = array();
		$_REQUEST = array();
	}
	return Notices::render();
};
$pintar = static function ( array $get ): string {
	$_GET = $get;
	try {
		return ( new TiendaGestionView() )->render();
	} finally {
		$_GET = array();
	}
};

$cfg                = Config::defaults();
$cfg['insignias']   = array(
	'grupo'   => array( 'nombre' => 'Insignia de grupo', 'lugar' => 'Manga', 'precio' => 3.0, 'imagen' => 0, 'activa' => true ),
	'mundial' => array( 'nombre' => 'Insignia mundial', 'lugar' => 'Pecho', 'precio' => null, 'imagen' => 0, 'activa' => true ),
);
Config::guardar( $cfg, $grupo['numero'] );
$cfg   = Config::de();
$cm    = static fn( string $modelo, string $talla ): array => array( 'modelo' => $modelo, 'nombre' => $cfg['modelos'][ $modelo ]['nombre'], 'talla' => $talla, 'especial' => false, 'precio' => 25.0 );
$ins   = static fn( string $id, int $n ): array => array( 'id' => $id, 'nombre' => $cfg['insignias'][ $id ]['nombre'], 'cantidad' => $n, 'precio' => $cfg['insignias'][ $id ]['precio'] );
$nuevo = static fn( string $socio, ?array $camisa, array $insignias ): int => PedidosTable::crear( $socio, array( 'lobatos' ), false, array( 'camisa' => $camisa, 'insignias' => $insignias, 'productos' => array() ), 10.0, false, '' );

try {
	$como( $u_coord );
	$m_ins  = MaterialesTable::crear( MaterialesTable::validar( array( 'descripcion' => 'insignia DE grupo', 'tipo' => '0', 'cantidad' => '5' ) ) );
	$m_cam  = MaterialesTable::crear( MaterialesTable::validar( array( 'descripcion' => '', 'tipo' => (string) TiposTable::camisa(), 'cantidad' => '1', 'modelo' => 'chica', 'talla' => 'm' ) ) );
	$stock  = static fn() => MaterialesTable::insignias( Config::de() );
	$ok( 5 === ( $stock()['grupo'] ?? 0 ) && ! isset( $stock()['mundial'] ), 'el inventario reconoce las insignias por su nombre (sin mayúsculas): 5 de grupo y ninguna mundial' );

	echo "\n== Parte A · Un pedido solo de insignias ==\n";
	$a = $nuevo( '990081', null, array( $ins( 'grupo', 2 ) ) );
	$p = PedidosTable::obtener( $a );
	$ok( Servicio::se_puede_servir( $p ) && Servicio::hay_en_inventario( $p ), 'se puede servir: hay en el inventario' );
	$ok( true === Servicio::servir( $p ), 'se sirve' );
	$p = PedidosTable::obtener( $a );
	$ok( 3 === $stock()['grupo'] && $m_ins === (int) $p['lineas']['insignias'][0]['inventario'], 'se descuentan las 2 (quedan 3) y la línea recuerda de qué material salieron' );
	$ok( PedidosTable::REF_INVENTARIO === $p['pedido_ref'] && null !== $p['pedido_en'], 'como solo era eso, el pedido no va al proveedor: queda «del inventario del grupo»' );
	$res = Servicio::resumen( array( $p ), Config::de() );
	$ok( ! $res['insignias'] && 0 === $res['total_camisas'], 'y no sale en «Para el próximo pedido al proveedor»' );
	$ok( ! Servicio::hay_en_inventario( $p ) && ! Servicio::se_puede_servir( $p ), 'ya no se puede servir otra vez' );
	$ok( true === Servicio::devolver( $p ), 'devolverlo' );
	$p = PedidosTable::obtener( $a );
	$ok( 5 === $stock()['grupo'] && empty( $p['lineas']['insignias'][0]['inventario'] ) && null === $p['pedido_en'], 'vuelven al inventario (5) y el pedido vuelve a estar pendiente de pedir' );

	echo "\n== Parte B · Camisa e insignias, y lo que no hay ==\n";
	$b = $nuevo( '990082', $cm( 'femenina', 'M' ), array( $ins( 'grupo', 1 ), $ins( 'mundial', 1 ) ) );
	$p = PedidosTable::obtener( $b );
	$inv = Servicio::inventario_de( $p );
	$ok( 1 === $inv['camisa']['quedan'] && 5 === $inv['insignias'][0]['quedan'] && 0 === $inv['insignias'][1]['quedan'], 'se ve lo que queda de cada cosa: 1 camisa, 5 de grupo, 0 mundiales' );
	$ok( true === Servicio::servir( $p ), 'se sirve lo que hay' );
	$p = PedidosTable::obtener( $b );
	$ok( $m_cam === $p['inventario_id'] && $m_ins === (int) $p['lineas']['insignias'][0]['inventario'] && empty( $p['lineas']['insignias'][1]['inventario'] ), 'salen la camisa y la insignia de grupo; la mundial no' );
	$ok( null === $p['pedido_en'], 'el pedido sigue pendiente de pedir (falta la mundial)' );
	$res = Servicio::resumen( array( $p ), Config::de() );
	$ok( 0 === $res['total_camisas'] && array( 'mundial' ) === array_keys( $res['insignias'] ), 'al proveedor solo va la insignia mundial' );
	$ok( 4 === $stock()['grupo'] && 0 === ( MaterialesTable::camisas()['femenina']['M'] ?? 0 ), 'inventario: 4 de grupo y ninguna camisa' );
	$ok( Servicio::se_puede_devolver( $p ) && ! Servicio::hay_en_inventario( $p ), 'se puede devolver; no queda nada más que servir' );
	$ok( true === Servicio::cancelar( $p, 'Prueba' ), 'cancelar el pedido' );
	$ok( 5 === $stock()['grupo'] && 1 === ( MaterialesTable::camisas()['femenina']['M'] ?? 0 ), 'cancelarlo devuelve al inventario la camisa y la insignia' );
	$ok( true !== Servicio::cancelar( PedidosTable::obtener( $b ), 'Otra vez' ), 'cancelar dos veces no devuelve dos veces' );
	$ok( 5 === $stock()['grupo'] && 1 === ( MaterialesTable::camisas()['femenina']['M'] ?? 0 ), 'inventario sin cambios' );

	echo "\n== Parte C · Cuando no alcanza ==\n";
	$c = $nuevo( '990083', null, array( $ins( 'grupo', 10 ) ) );
	$p = PedidosTable::obtener( $c );
	$ok( ! Servicio::hay_en_inventario( $p ), '10 pedidas y solo hay 5: no se ofrece' );
	$r = Servicio::servir( $p );
	$ok( is_wp_error( $r ) && 5 === $stock()['grupo'] && empty( PedidosTable::obtener( $c )['lineas']['insignias'][0]['inventario'] ), 'ni se sirve a medias: error y el inventario no cambia' );
	$d1 = $nuevo( '990084', null, array( $ins( 'grupo', 5 ) ) );
	$d2 = $nuevo( '990085', null, array( $ins( 'grupo', 5 ) ) );
	$r1 = Servicio::servir( PedidosTable::obtener( $d1 ) );
	$r2 = Servicio::servir( PedidosTable::obtener( $d2 ) );
	$ok( true === $r1 && is_wp_error( $r2 ) && 0 === ( $stock()['grupo'] ?? 0 ), 'dos pedidos para las últimas 5: se lo lleva el primero y el segundo no (nunca queda negativo)' );
	Servicio::devolver( PedidosTable::obtener( $d1 ) );
	$ok( 5 === $stock()['grupo'], 'se devuelve' );
	$solo_camisa = $nuevo( '990086', $cm( 'masculina', 'S' ), array() );
	$r = Servicio::servir( PedidosTable::obtener( $solo_camisa ) );
	$ok( is_wp_error( $r ) && str_contains( $r->get_error_message(), 'No queda ninguna camisa' ), 'un pedido solo de camisa sin camisas dice lo de siempre' );

	echo "\n== Parte D · Material dado de baja y otros nombres ==\n";
	MaterialesTable::dar_baja( $m_ins, 'Prueba' );
	$ok( ! isset( $stock()['grupo'] ), 'el material dado de baja no cuenta' );
	MaterialesTable::recuperar( $m_ins );
	$otro = MaterialesTable::crear( MaterialesTable::validar( array( 'descripcion' => 'Insignia de grupo (antigua)', 'tipo' => '0', 'cantidad' => '9' ) ) );
	$ok( 5 === $stock()['grupo'], 'un material con otro nombre no se confunde con la insignia' );

	echo "\n== Parte E · Permisos y pantallas ==\n";
	$e = $nuevo( '990087', null, array( $ins( 'grupo', 1 ) ) );
	$como( $u_sc );
	$ok( ! Servicio::hay_en_inventario( PedidosTable::obtener( $e ) ) && is_wp_error( Servicio::servir( PedidosTable::obtener( $e ) ) ) && array( 'camisa' => null, 'insignias' => array() ) === Servicio::inventario_de( PedidosTable::obtener( $e ) ), 'un scouter sin permisos de Coordinación o Tesorería no sirve ni ve el inventario' );
	$como( $u_coord );
	$h = $pintar( array() );
	$ok( str_contains( $h, 'Hay en el inventario' ), 'la gestión marca «Hay en el inventario» en los pedidos con insignias que se pueden servir' );
	$h = $pintar( array( 'pedido' => (string) $e ) );
	$ok( str_contains( $h, 'Servir del inventario' ) && str_contains( $h, 'insignia «Insignia de grupo» (quedan 5)' ), 'el detalle ofrece «Servir del inventario» y dice qué queda' );
	$enviar( array( 'asde_action' => 'uniforme_servir', 'pedido' => (string) $e, 'desde' => 'detalle' ), 'asde_uniforme_' . $e );
	$p = PedidosTable::obtener( $e );
	$ok( ! empty( $p['lineas']['insignias'][0]['inventario'] ) && 4 === $stock()['grupo'] && null !== $p['pedido_en'], 'el botón sirve las insignias' );
	$h = $pintar( array( 'pedido' => (string) $e ) );
	$ok( str_contains( $h, 'Insignias del inventario' ) && str_contains( $h, 'Devolver al inventario' ), 'el detalle enseña que salieron del inventario y deja devolverlas' );
	$h = $pintar( array() );
	$ok( str_contains( $h, 'Insignias del inventario' ), 'y la lista también' );
	$enviar( array( 'asde_action' => 'uniforme_devolver', 'pedido' => (string) $e, 'desde' => 'detalle' ), 'asde_uniforme_' . $e );
	$ok( 5 === $stock()['grupo'] && null === PedidosTable::obtener( $e )['pedido_en'], 'devolver desde la pantalla' );
	$acciones = (array) $wpdb->get_col( $wpdb->prepare( 'SELECT accion FROM ' . Logger::table() . ' WHERE id > %d', $log_antes ) ); // phpcs:ignore WordPress.DB
	$ok( in_array( 'uniforme_servido_inventario', $acciones, true ) && in_array( 'uniforme_devuelto_inventario', $acciones, true ), 'queda en la auditoría' );
} finally {
	remove_filter( 'wp_redirect', $redirigir, 1 );
	$_POST = array();
	$_GET  = array();
	wp_set_current_user( 0 );

	$lista = implode( ',', array_map( 'intval', $ids ) ) ?: '0';
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . PedidosTable::table() . ' WHERE id > %d', $ped_antes ) ); // phpcs:ignore WordPress.DB
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . MaterialesTable::table() . ' WHERE id > %d', $mat_antes ) ); // phpcs:ignore WordPress.DB
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Logger::table() . " WHERE id > %d AND ( user_id IN ($lista) OR objeto IN ( 'uniforme', 'inventario' ) )", $log_antes ) ); // phpcs:ignore WordPress.DB
	foreach ( $ids as $uid ) {
		delete_transient( 'asde_ap_notices_' . $uid );
		Ambito::olvidar( (int) $uid );
		wp_delete_user( (int) $uid );
	}
	if ( null === $cfg_antes ) {
		delete_option( $opcion_cfg );
	} else {
		update_option( $opcion_cfg, $cfg_antes, false );
	}
	Grupo::forzar( null );

	$quedan = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users} WHERE user_login LIKE 'prueba-ii-%'" ); // phpcs:ignore WordPress.DB
	$ok( 0 === $quedan && 0 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . PedidosTable::table() . ' WHERE id > ' . (int) $ped_antes ) && 0 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . MaterialesTable::table() . ' WHERE id > ' . (int) $mat_antes ), 'limpieza: sin usuarios, pedidos ni materiales de la prueba; configuración como estaba' ); // phpcs:ignore WordPress.DB
}

echo $fails ? "\n$fails FALLOS\n" : "\nTODO OK (sin CRM ni datos personales; el sitio queda como estaba)\n";
