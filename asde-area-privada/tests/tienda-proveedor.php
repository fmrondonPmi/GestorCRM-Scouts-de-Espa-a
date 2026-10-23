<?php
/**
 * Pruebas de la v0.48 (entorno LOCAL, doc 48, T4): pestaña «Pedido de camisas» (pedido al proveedor). Ejecutar desde
 * app/public del site Local:
 *
 *   wp eval-file <ruta completa>/wp-content/plugins/asde-area-privada/tests/tienda-proveedor.php
 *
 * Usa el CRM simulado de tests/lib (fichas inventadas): ninguna petición llega al CRM real. Trabaja en un grupo ficticio
 * (996, añadido solo en memoria con el filtro pre_option_asde_ap_grupos) para no ver los pedidos del Local. Al terminar se borra lo creado
 * (productos, pedidos, pedidos al proveedor, usuarios, registros).
 */
defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/lib/CrmSimulado.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Auth\Ambito;
use AsdeBalears\AreaPrivada\Domain\Grupo;
use AsdeBalears\AreaPrivada\Domain\Grupos;
use AsdeBalears\AreaPrivada\Frontend\Views\TiendaGestionView;
use AsdeBalears\AreaPrivada\Frontend\Views\TiendaProveedorView;
use AsdeBalears\AreaPrivada\Install\Roles;
use AsdeBalears\AreaPrivada\Tienda\PedidosTable;
use AsdeBalears\AreaPrivada\Tienda\ProductosTable;
use AsdeBalears\AreaPrivada\Tienda\Proveedor;
use AsdeBalears\AreaPrivada\Tienda\ProveedorTable;
use AsdeBalears\AreaPrivada\Tienda\Servicio;
use AsdeBalears\AreaPrivada\Tienda\VariantesTable;
use AsdeBalears\AreaPrivada\Usuarios\Usuarios;

global $wpdb;

$fails = 0;
$ok    = function ( $cond, string $label ) use ( &$fails ) {
	if ( ! $cond ) {
		++$fails;
	}
	echo ( $cond ? 'OK   ' : 'FAIL ' ), $label, "\n";
};

// Doc 60: la prueba trabaja en un grupo ficticio (996, solo en memoria, con su CRM simulado) para no depender de los
// pedidos y pedidos al proveedor que haya en el Local (el volcado del manual deja «Camisas de otoño» sin recibir en el 684,
// y la pestaña enseñaba su botón «Marcar como recibido»).
if ( Grupos::por_numero( '996' ) ) {
	exit( "El grupo 996 existe en el Local: esta prueba lo usa como grupo ficticio. Revisa de dónde ha salido.\n" );
}
$grupos_orig = get_option( 'asde_ap_grupos' );
$mas_grupos  = static fn() => (array) $grupos_orig + array(
	'996' => array(
		'nombre'    => 'Grupo de prueba 996',
		'usuario'   => 'prueba-996',
		'clave_enc' => \AsdeBalears\AreaPrivada\Crm\Crypto::encrypt( 'clave-de-prueba' ),
		'crm_id'    => 'sg-prueba-996',
	),
);
add_filter( 'pre_option_asde_ap_grupos', $mas_grupos );
Grupos::olvidar();
$grupo = Grupos::por_numero( '996' );
foreach ( get_users( array( 'search' => 'prueba-prv-*', 'search_columns' => array( 'user_login' ) ) ) as $u ) {
	exit( 'Ya existe el usuario ' . $u->user_login . " (¿una ejecución anterior interrumpida?). Bórralo antes de repetir la prueba.\n" );
}
wp_set_current_user( 0 );

// --- Lo que se restaura al final ---------------------------------------------
$max_id      = static fn( string $t ) => (int) $wpdb->get_var( "SELECT COALESCE(MAX(id), 0) FROM $t" ); // phpcs:ignore WordPress.DB
$ped_antes   = $max_id( PedidosTable::table() );
$prv_antes   = $max_id( ProveedorTable::table() );
$prod_antes  = $max_id( ProductosTable::table() );
$var_antes   = $max_id( VariantesTable::table() );
$log_antes   = $max_id( Logger::table() );
$cache_externa = wp_using_ext_object_cache( true );
$cortadas    = 0;
$cortar_crm  = static function ( $pre, $args, $url ) use ( &$cortadas ) {
	if ( false === $pre && str_contains( (string) $url, '/rest.php' ) ) {
		++$cortadas;
		return new WP_Error( 'prueba', 'CRM real cortado durante la prueba' );
	}
	return $pre;
};
add_filter( 'pre_http_request', $cortar_crm, 2, 3 );
add_filter( 'pre_wp_mail', '__return_true' );

$P   = array( 'Asde_Prueba_Crm', 'persona' );
$crm = new Asde_Prueba_Crm(
	array(
		$P( 41, 'Eli', 'Proveedor Sanz', '1990-01-01', 'Scouters' ),
		$P( 42, 'Teo', 'Proveedor Gil', '1985-05-05', 'Scouters' ),
		$P( 43, 'Cora', 'Proveedor Luz', '1980-08-08', 'Scouters' ),
		$P( 44, 'Leo', 'Proveedor Mas', '2014-02-02', 'Lobatos' ),
	),
	$grupo['crm_id']
);
Grupo::forzar( $grupo );
$crm->activar();

$ids   = array();
$crear = static function ( string $login, array $roles, array $meta ) use ( &$ids, $grupo ): int {
	$id = wp_insert_user(
		array(
			'user_login' => $login,
			'user_email' => '',
			'user_pass'  => wp_generate_password( 24 ),
			'role'       => $roles[0],
		)
	);
	$u = get_userdata( $id );
	foreach ( array_slice( $roles, 1 ) as $r ) {
		$u->add_role( $r );
	}
	update_user_meta( $id, Usuarios::META_GRUPO, $grupo['id'] );
	update_user_meta( $id, Usuarios::META_ESTADO, Usuarios::ACTIVO );
	foreach ( $meta as $k => $v ) {
		add_user_meta( $id, $k, $v );
	}
	$ids[ $login ] = $id;
	return $id;
};
$u_sc    = $crear( 'prueba-prv-sc', array( Roles::SCOUTER ), array( Usuarios::META_SOCIO => '990041', Usuarios::META_SECCION => 'rovers' ) );
$u_teso  = $crear( 'prueba-prv-teso', array( Roles::SCOUTER, Roles::TESORERIA ), array( Usuarios::META_SOCIO => '990042', Usuarios::META_SECCION => 'rovers' ) );
$u_coord = $crear( 'prueba-prv-coord', array( Roles::SCOUTER, Roles::COORDINACION ), array( Usuarios::META_SOCIO => '990043', Usuarios::META_SECCION => 'rovers' ) );

$como = static function ( int $uid ): Ambito {
	wp_set_current_user( $uid );
	Ambito::olvidar( $uid );
	Proveedor::olvidar();
	return Ambito::actual();
};
add_filter( 'send_auth_cookies', '__return_false' );
$redirigir = static function ( $destino ) {
	throw new RuntimeException( 'asde-redirect:' . $destino );
};
add_filter( 'wp_redirect', $redirigir, 1 );
/** Envía un formulario a la vista como lo haría el navegador y devuelve los avisos. */
$enviar = static function ( array $campos, string $nonce ): string {
	$_POST    = $campos + array( '_wpnonce' => wp_create_nonce( $nonce ) );
	$_REQUEST = $_POST;
	try {
		( new TiendaProveedorView() )->handle_post();
	} catch ( RuntimeException $e ) {
		// Redirige al terminar.
	} finally {
		$_POST    = array();
		$_REQUEST = array();
	}
	return \AsdeBalears\AreaPrivada\Frontend\Notices::render();
};

// --- Catálogo de prueba: una camisa que se encarga al proveedor y una taza que no ---
$nuevo = static function ( string $nombre, int $proveedor, array $variantes ): array {
	$id = ProductosTable::crear(
		array(
			'nombre'             => $nombre,
			'descripcion'        => '',
			'categoria'          => 'Prueba proveedor',
			'visibilidad'        => 'todos',
			'precio'             => 10.0,
			'precio_socio'       => null,
			'limite_persona'     => 0,
			'requiere_proveedor' => $proveedor,
			'sale_inventario'    => 0,
			'activo'             => 1,
		)
	);
	$vs = array();
	foreach ( $variantes as $etiqueta => $existencias ) {
		$vs[ $etiqueta ] = VariantesTable::crear(
			$id,
			array(
				'etiqueta'    => $etiqueta,
				'sku'         => '',
				'precio'      => null,
				'existencias' => $existencias,
				'ilimitado'   => 0,
			)
		);
	}
	return array( $id, $vs );
};
list( $camisa, $v_cam ) = $nuevo( 'Camisa de prueba', 1, array( 'M' => 0, 'L' => 0 ) );
list( $taza, $v_taza )  = $nuevo( 'Taza de prueba', 0, array( 'Única' => 5 ) );
$existencias = static fn( int $v ) => VariantesTable::obtener( $v )['existencias'];

/** Un pedido de cliente con estas líneas (producto, variante, nombre, etiqueta, cantidad, proveedor). */
$pedido = static function ( array $lineas, bool $pagado, string $socio = '990044' ): int {
	$productos = array_map(
		static fn( array $l ) => array(
			'producto'  => $l[0],
			'variante'  => $l[1],
			'nombre'    => $l[2],
			'etiqueta'  => $l[3],
			'cantidad'  => $l[4],
			'precio'    => 10.0,
			'proveedor' => $l[5],
			'reservado' => false,
		),
		$lineas
	);
	$total = array_sum( array_map( static fn( array $l ) => 10.0 * $l[4], $lineas ) );
	$id    = (int) PedidosTable::crear( $socio, array( 'lobatos' ), false, array( 'camisa' => null, 'insignias' => array(), 'productos' => $productos ), $total, false, '', 'bizum' );
	if ( $pagado ) {
		PedidosTable::marcar_pago( $id, wp_date( 'Y-m-d' ), 'bizum', $total, 'Prueba' );
	}
	return $id;
};

try {
	echo "== Parte A · Quién ve y quién hace ==\n";
	$como( $u_coord );
	$ok( Proveedor::puede_ver() && Proveedor::puede_gestionar(), 'Coordinación ve la pestaña y hace los pedidos' );
	$como( $u_teso );
	$ok( Proveedor::puede_ver() && ! Proveedor::puede_gestionar(), 'Tesorería la ve, pero no hace pedidos' );
	$como( $u_sc );
	$ok( ! Proveedor::puede_ver() && str_contains( ( new TiendaProveedorView() )->render(), 'No tienes acceso' ), 'un scouter no la ve' );
	$como( $u_coord );

	echo "\n== Parte B · Lo que hay que pedir ==\n";
	$o1 = $pedido( array( array( $camisa, $v_cam['M'], 'Camisa de prueba', 'M', 2, 1 ) ), true );
	$o2 = $pedido( array( array( $camisa, $v_cam['L'], 'Camisa de prueba', 'L', 1, 1 ), array( $camisa, $v_cam['M'], 'Camisa de prueba', 'M', 1, 1 ) ), true );
	$o3 = $pedido( array( array( $camisa, $v_cam['M'], 'Camisa de prueba', 'M', 1, 1 ) ), false );
	$o4 = $pedido( array( array( $taza, $v_taza['Única'], 'Taza de prueba', 'Única', 1, 0 ) ), true );
	$ok( array( $o1, $o2 ) === array_reverse( array_column( Proveedor::por_pedir(), 'id' ) ) || array( $o2, $o1 ) === array_column( Proveedor::por_pedir(), 'id' ), 'solo entran los pagados con algo que va al proveedor (ni el sin pagar ni el de la taza)' );
	$ok( 1 === Proveedor::sin_pagar(), 'y se cuenta 1 pedido sin pagar que aún no entra' );
	$l = Proveedor::lineas_de( Proveedor::por_pedir() );
	$por = array();
	foreach ( $l as $x ) {
		$por[ $x['etiqueta'] ] = $x['cantidad'];
	}
	$ok( 2 === count( $l ) && 3 === $por['M'] && 1 === $por['L'], 'las líneas se juntan por producto y talla: M × 3 y L × 1' );
	$h = ( new TiendaProveedorView() )->render();
	$ok( str_contains( $h, 'Camisa de prueba' ) && str_contains( $h, 'Crear el pedido al proveedor' ) && str_contains( $h, 'De 2 pedidos de clientes' ) && str_contains( $h, 'no entra hasta que se pague' ), 'la pestaña lo enseña, con el botón de crear y el aviso del sin pagar' );
	$ok( str_contains( $h, 'asde-form__fila--una-linea' ), 'y el nombre y el botón van en una línea (regla de las pantallas)' );
	$como( $u_teso );
	$h = ( new TiendaProveedorView() )->render();
	$ok( ! str_contains( $h, 'Crear el pedido al proveedor' ), 'Tesorería lo ve sin el botón de crear' );
	$como( $u_coord );
	$h = ( new TiendaGestionView() )->render();
	$ok( ! str_contains( $h, 'Para el próximo pedido al proveedor' ) && str_contains( $h, 'Pedido de camisas' ), 'la gestión ya no lleva el resumen: remite a la pestaña' );

	echo "\n== Parte C · Hacer el pedido ==\n";
	$como( $u_teso );
	$ok( is_wp_error( Proveedor::crear( 'No', array( $o1 ) ) ), 'Tesorería no lo puede hacer' );
	$como( $u_coord );
	$h = $enviar( array( 'asde_action' => 'proveedor_crear', 'nombre' => 'Camisas octubre', 'pedidos' => array( (string) $o1, (string) $o2, (string) $o3, (string) $o4, '999999' ) ), 'asde_proveedor_crear' );
	$pp = ProveedorTable::obtener( $max_id( ProveedorTable::table() ) );
	$ok( $pp && $pp['id'] > $prv_antes && 'Camisas octubre' === $pp['nombre'] && 2 === $pp['pedidos'] && null === $pp['recibido_en'] && str_contains( $h, 'Pedido al proveedor creado' ), 'Coordinación crea el pedido: solo entran los dos pagados' );
	$q1 = PedidosTable::obtener( $o1 );
	$q3 = PedidosTable::obtener( $o3 );
	$q4 = PedidosTable::obtener( $o4 );
	$ok( $q1['proveedor_id'] === $pp['id'] && 'Camisas octubre' === $q1['pedido_ref'] && null !== $q1['pedido_en'] && 0 === $q3['proveedor_id'] && null === $q3['pedido_en'] && 0 === $q4['proveedor_id'], 'los pedidos de clientes quedan «en el pedido»; el sin pagar y el de la taza, fuera' );
	$ok( array() === Proveedor::por_pedir() && is_wp_error( Proveedor::crear( 'Otra vez', array( $o1, $o2 ) ) ), 'ya no queda nada por pedir, ni se puede repetir' );
	$cant = array();
	foreach ( $pp['lineas'] as $x ) {
		$cant[ $x['etiqueta'] ] = $x['cantidad'];
	}
	$ok( 3 === $cant['M'] && 1 === $cant['L'], 'el pedido guarda una copia de lo pedido' );
	$filas = Proveedor::filas_excel( $pp );
	$ok( 2 === count( $filas ) && array( 'Camisa de prueba', 'L', 1 ) === $filas[0], 'las filas del Excel: producto, talla y cantidad' );

	echo "\n== Parte D · Hasta que llegue no se entrega ==\n";
	$a = $como( $u_coord );
	$ok( true === Proveedor::esperando( PedidosTable::obtener( $o1 ) ) && ! Servicio::puede_entregar( PedidosTable::obtener( $o1 ), $a ), 'un pedido que va en el pedido al proveedor no se entrega todavía' );
	$ok( 0 === Servicio::pendientes( $a )['entrega'], 'y no cuenta como pendiente de entregar' );
	$_GET['pedido'] = (string) $o1;
	$h              = ( new TiendaGestionView() )->render();
	unset( $_GET['pedido'] );
	$ok( str_contains( $h, 'Todavía no ha llegado del proveedor' ), 'su ficha dice por qué' );

	echo "\n== Parte E · Recibirlo ==\n";
	$como( $u_teso );
	$ok( is_wp_error( Proveedor::recibir( $pp['id'], array() ) ), 'Tesorería no lo marca como recibido' );
	$como( $u_coord );
	$m_antes = $existencias( $v_cam['M'] );
	$l_antes = $existencias( $v_cam['L'] );
	$claves  = array_column( $pp['lineas'], 'clave', 'etiqueta' );
	$h = $enviar( array( 'asde_action' => 'proveedor_recibir', 'pedido_proveedor' => (string) $pp['id'], 'recibida' => array( $claves['M'] => '5', $claves['L'] => '0' ) ), 'asde_proveedor_' . $pp['id'] );
	$rp = ProveedorTable::obtener( $pp['id'] );
	$rec = array();
	foreach ( $rp['lineas'] as $x ) {
		$rec[ $x['etiqueta'] ] = $x['recibida'];
	}
	$ok( null !== $rp['recibido_en'] && $u_coord === $rp['recibido_por'] && 5 === $rec['M'] && 0 === $rec['L'], 'Coordinación lo marca recibido, con lo que llegó de cada talla' );
	$ok( $m_antes + 2 === $existencias( $v_cam['M'] ) && $l_antes === $existencias( $v_cam['L'] ), 'lo que llegó de más (2 de la M) suma a las existencias; de la L no llegó nada y no se toca' );
	$ok( str_contains( $h, 'Han llegado 2 unidades de más' ) && str_contains( $h, 'Falta 1 unidad por llegar' ), 'y se dice' );
	$a = $como( $u_coord );
	$ok( false === Proveedor::esperando( PedidosTable::obtener( $o1 ) ) && Servicio::puede_entregar( PedidosTable::obtener( $o1 ), $a ) && 2 === Servicio::pendientes( $a )['entrega'], 'ya se pueden entregar los pedidos de los clientes (2 pendientes)' );
	$ok( is_wp_error( Proveedor::recibir( $pp['id'], array() ) ) && $m_antes + 2 === $existencias( $v_cam['M'] ), 'no se recibe dos veces (y no se suma dos veces)' );
	$h   = ( new TiendaProveedorView() )->render();
	$art = preg_match( '~<article class="asde-pedido">(?:(?!</article>).)*Camisas octubre(?:(?!</article>).)*</article>~s', $h, $m ) ? $m[0] : '';
	$ok( '' !== $art && str_contains( $art, 'Recibido el' ) && ! str_contains( $art, 'Marcar como recibido' ) && ! str_contains( $art, 'name="recibida[' ), 'la pestaña lo enseña recibido, sin botón ni casillas' );
	$ok( ! str_contains( $h, 'Marcar como recibido' ), 'y no queda ningún pedido al proveedor por recibir en el grupo' );

	echo "\n== Parte F · Un pedido sin diferencias y la descarga ==\n";
	$o5 = $pedido( array( array( $camisa, $v_cam['L'], 'Camisa de prueba', 'L', 2, 1 ) ), true );
	$id5 = Proveedor::crear( '', array( $o5 ) );
	$pp5 = ProveedorTable::obtener( (int) $id5 );
	$ok( ! is_wp_error( $id5 ) && str_starts_with( $pp5['nombre'], 'Pedido ' ), 'sin nombre, se le pone «Pedido» y la fecha' );
	$l_antes = $existencias( $v_cam['L'] );
	$res = Proveedor::recibir( (int) $id5, array() );
	$ok( is_array( $res ) && 0 === $res['sobrante'] && 0 === $res['faltan'] && $l_antes === $existencias( $v_cam['L'] ), 'sin escribir cantidades se da por recibido lo pedido, sin tocar las existencias' );
	$como( $u_sc );
	$_GET   = array( 'formato' => 'xlsx', 'pedido' => (string) $pp['id'], '_wpnonce' => wp_create_nonce( 'asde_proveedor_excel_' . $pp['id'] ) );
	$muerto = false;
	$al_morir = static function () use ( &$muerto ) {
		$muerto = true;
		throw new RuntimeException( 'asde-die' );
	};
	$morir     = static fn() => $al_morir;
	add_filter( 'wp_die_handler', $morir, PHP_INT_MAX );
	try {
		( new TiendaProveedorView() )->exportar();
	} catch ( RuntimeException $e ) {
		// wp_die.
	}
	remove_filter( 'wp_die_handler', $morir, PHP_INT_MAX );
	$_GET = array();
	$ok( true === $muerto, 'un scouter no descarga el Excel del pedido' );
	$ok( $crm->llamadas > 0 && 0 === $cortadas, "todas las consultas al CRM las ha respondido el simulador ({$crm->llamadas}); ninguna ha salido hacia el CRM real" );
} catch ( Throwable $e ) {
	++$fails;
	echo 'FAIL excepcion: ', $e->getMessage(), ' (', basename( $e->getFile() ), ':', $e->getLine(), ")\n";
} finally {
	remove_filter( 'wp_redirect', $redirigir, 1 );
	remove_filter( 'send_auth_cookies', '__return_false' );
	remove_filter( 'pre_wp_mail', '__return_true' );
	$_POST = array();
	$_GET  = array();
	$crm->desactivar();
	remove_filter( 'pre_http_request', $cortar_crm, 2 );
	wp_using_ext_object_cache( $cache_externa );
	wp_set_current_user( 0 );
	Proveedor::olvidar();

	$lista = implode( ',', array_map( 'intval', $ids ) ) ?: '0';
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id > %d', PedidosTable::table(), $ped_antes ) ); // phpcs:ignore WordPress.DB
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id > %d', ProveedorTable::table(), $prv_antes ) ); // phpcs:ignore WordPress.DB
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id > %d', VariantesTable::table(), $var_antes ) ); // phpcs:ignore WordPress.DB
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id > %d', ProductosTable::table(), $prod_antes ) ); // phpcs:ignore WordPress.DB
	$wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE id > %d AND user_id IN ($lista)", Logger::table(), $log_antes ) ); // phpcs:ignore WordPress.DB
	foreach ( $ids as $uid ) {
		Ambito::olvidar( (int) $uid );
		wp_delete_user( (int) $uid );
	}
	Grupo::forzar( null );
	remove_filter( 'pre_option_asde_ap_grupos', $mas_grupos );
	Grupos::olvidar();

	$quedan = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users} WHERE user_login LIKE 'prueba-prv-%'" ); // phpcs:ignore WordPress.DB
	$ok( 0 === $quedan && 0 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . PedidosTable::table() . ' WHERE id > ' . (int) $ped_antes ) && 0 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . ProveedorTable::table() . ' WHERE id > ' . (int) $prv_antes ), 'limpieza: sin usuarios, pedidos, productos ni pedidos al proveedor de la prueba' ); // phpcs:ignore WordPress.DB
}

echo $fails ? "\nHAY $fails FALLOS\n" : "\nTODO OK (nada tocado en el CRM real; el sitio queda como estaba)\n";
