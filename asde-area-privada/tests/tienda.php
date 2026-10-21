<?php
/**
 * Pruebas de la tienda sobre los pedidos anteriores a la v0.44 (camisa e insignias en sus líneas), la gestión,
 * «Mis pagos» y lo que se retiró del uniforme (doc 52, T1). Las compras del catálogo, en tienda-pedidos.php.
 * Ejecutar desde app/public del site Local:
 *
 *   wp eval-file <ruta completa>/wp-content/plugins/asde-area-privada/tests/tienda.php
 *
 * Usa el CRM simulado de tests/lib (fichas inventadas: ninguna petición llega al
 * CRM real) y guarda las cachés solo en la memoria de este proceso, así que la
 * web no las ve. Al terminar se borra lo creado (usuarios, pedidos, familia,
 * movimientos, registros) y se restauran la configuración de uniformes del grupo
 * y la tabla de números.
 */
defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/lib/CrmSimulado.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Auth\Ambito;
use AsdeBalears\AreaPrivada\Domain\Grupo;
use AsdeBalears\AreaPrivada\Domain\Grupos;
use AsdeBalears\AreaPrivada\Frontend\Router;
use AsdeBalears\AreaPrivada\Frontend\Views\MisPagosView;
use AsdeBalears\AreaPrivada\Frontend\Views\TiendaPedidosView;
use AsdeBalears\AreaPrivada\Frontend\Views\TiendaConfigView;
use AsdeBalears\AreaPrivada\Frontend\Views\TiendaGestionView;
use AsdeBalears\AreaPrivada\Frontend\Views\View;
use AsdeBalears\AreaPrivada\Install\Roles;
use AsdeBalears\AreaPrivada\Socios\SociosTable;
use AsdeBalears\AreaPrivada\Tesoreria\Config as TesoreriaConfig;
use AsdeBalears\AreaPrivada\Tesoreria\FamiliasTable;
use AsdeBalears\AreaPrivada\Tesoreria\MovimientosTable;
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
if ( ! $grupo || '' === $grupo['crm_id'] ) {
	exit( "Hace falta un grupo con la conexión al CRM comprobada.\n" );
}
foreach ( get_users( array( 'search' => 'prueba-uni-*', 'search_columns' => array( 'user_login' ) ) ) as $u ) {
	exit( 'Ya existe el usuario ' . $u->user_login . " (¿una ejecución anterior interrumpida?). Bórralo antes de repetir la prueba.\n" );
}
wp_set_current_user( 0 );

// --- Lo que se restaura al final ---------------------------------------------
$max_id     = static fn( string $t ) => (int) $wpdb->get_var( "SELECT COALESCE(MAX(id), 0) FROM $t" ); // phpcs:ignore WordPress.DB
$log_antes  = $max_id( Logger::table() );
$ped_antes  = $max_id( PedidosTable::table() );
$fam_antes  = $max_id( FamiliasTable::table() );
$mov_antes  = $max_id( MovimientosTable::table() );
$socios_antes = array_column( (array) $wpdb->get_results( 'SELECT * FROM ' . SociosTable::table(), ARRAY_A ), null, 'num_socio_asde' ); // phpcs:ignore WordPress.DB
$opcion_cfg = 'asde_ap_tienda_' . $grupo['numero'];
$cfg_antes  = get_option( $opcion_cfg, null );

// Cachés (transients) solo en la memoria de este proceso: la web real no ve las del CRM simulado.
$cache_externa = wp_using_ext_object_cache( true );
add_filter( 'pre_wp_mail', '__return_true' );
$cortadas   = 0;
$cortar_crm = static function ( $pre, $args, $url ) use ( &$cortadas ) {
	if ( false === $pre && str_contains( (string) $url, '/rest.php' ) ) {
		++$cortadas;
		return new WP_Error( 'prueba', 'CRM real cortado durante la prueba' );
	}
	return $pre;
};
add_filter( 'pre_http_request', $cortar_crm, 2, 3 );

$P   = array( 'Asde_Prueba_Crm', 'persona' );
$crm = new Asde_Prueba_Crm(
	array(
		$P( 21, 'Leo', 'Uniforme Sanz', '2016-05-10', 'Lobatos' ),
		$P( 22, 'Nora', 'Uniforme Sanz', '2014-02-20', 'Scouts' ),
		$P( 23, 'Pol', 'Uniforme Mas', '2011-03-03', 'Escultas' ),
		$P( 24, 'Eva', 'Uniforme Gil', '1990-01-01', 'Scouters' ),
		$P( 25, 'Tom', 'Uniforme Luz', '1985-05-05', 'Scouters' ),
		$P( 26, 'Ana', 'Uniforme Coll', '1980-08-08', 'Scouters' ),
		$P( 27, 'Iris', 'Uniforme Roca', '2012-07-07', 'Scouts' ),
	),
	$grupo['crm_id']
);
$falsos = array_map( static fn( $p ) => $p['numerosocio_c'], $crm->personas );
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
		foreach ( (array) $v as $valor ) {
			add_user_meta( $id, $k, $valor );
		}
	}
	$ids[ $login ] = $id;
	return $id;
};
$u_fam     = $crear( 'prueba-uni-fam', array( Roles::TUTOR ), array( Usuarios::META_REPRESENTADO => array( '990021', '990022' ) ) );
$u_pol     = $crear( 'prueba-uni-pol', array( Roles::PARTICIPANTE ), array( Usuarios::META_SOCIO => '990023' ) );
$u_teso    = $crear( 'prueba-uni-teso', array( Roles::SCOUTER, Roles::TESORERIA ), array( Usuarios::META_SOCIO => '990024', Usuarios::META_SECCION => 'rovers' ) );
$u_scouter = $crear( 'prueba-uni-scouter', array( Roles::SCOUTER ), array( Usuarios::META_SOCIO => '990025', Usuarios::META_SECCION => 'lobatos' ) );
$u_coord   = $crear( 'prueba-uni-coord', array( Roles::SCOUTER, Roles::COORDINACION ), array( Usuarios::META_SOCIO => '990026', Usuarios::META_SECCION => 'rovers' ) );
$u_fam2    = $crear( 'prueba-uni-fam2', array( Roles::TUTOR ), array( Usuarios::META_REPRESENTADO => '990027' ) );

// Configuración de prueba (la del grupo se restaura al final).
$cfg                   = Config::defaults();
$cfg['insignias']      = array(
	'parche'  => array( 'nombre' => 'Parche de prueba', 'lugar' => 'Brazo derecho', 'precio' => 2.5, 'imagen' => 0, 'activa' => true ),
	'mundial' => array( 'nombre' => 'Mundial de prueba', 'lugar' => 'Pecho izquierdo', 'precio' => null, 'imagen' => 0, 'activa' => true ),
	'vieja'   => array( 'nombre' => 'Retirada', 'lugar' => '', 'precio' => 1.0, 'imagen' => 0, 'activa' => false ),
);
Config::guardar( $cfg, $grupo['numero'] );

$como = static function ( int $uid ): Ambito {
	wp_set_current_user( $uid );
	Ambito::olvidar( $uid );
	return Ambito::actual();
};
add_filter( 'send_auth_cookies', '__return_false' );
$redirigir = static function ( $destino ) {
	throw new RuntimeException( 'asde-redirect:' . $destino );
};
add_filter( 'wp_redirect', $redirigir, 1 );
/** Envía un formulario a una vista como lo haría el navegador y devuelve los avisos. */
$enviar = static function ( $vista, array $campos, string $nonce ): string {
	$_POST    = $campos + array( '_wpnonce' => wp_create_nonce( $nonce ) );
	$_REQUEST = $_POST;
	try {
		$vista->handle_post();
	} catch ( RuntimeException $e ) {
		// Redirige al terminar.
	} finally {
		$_POST    = array();
		$_REQUEST = array();
	}
	return \AsdeBalears\AreaPrivada\Frontend\Notices::render();
};
$ultimo = static fn() => PedidosTable::obtener( $max_id( PedidosTable::table() ) );

try {
	echo "== Parte A · Pedidos anteriores a la v0.44 (camisa e insignias en sus líneas) ==\n";
	$c = Config::de( $grupo['numero'] );
	$linea_camisa = static fn( string $modelo, string $talla, float $precio ) => array(
		'modelo'   => $modelo,
		'nombre'   => 'masculina' === $modelo ? 'Camisa masculina' : 'Camisa femenina',
		'talla'    => $talla,
		'especial' => false,
		'precio'   => $precio,
	);
	$insignia = static fn( string $id, string $nombre, ?float $precio, int $n ) => array(
		'id'       => $id,
		'nombre'   => $nombre,
		'cantidad' => $n,
		'precio'   => $precio,
	);
	$l = array( 'camisa' => $linea_camisa( 'masculina', 'M', 25.0 ), 'insignias' => array( $insignia( 'parche', 'Parche de prueba', 2.5, 2 ) ), 'productos' => array() );
	$ok( 30.0 === Servicio::total( $l, $c )['total'] && ! Servicio::total( $l, $c )['provisional'], 'una camisa M + 2 parches de 2,50 € (pedido antiguo) siguen sumando 30 €' );
	$ok( 'Camisa masculina, talla M · Parche de prueba ×2' === Servicio::descripcion( $l ), 'y se describen igual' );
	$l2 = array( 'camisa' => null, 'insignias' => array( $insignia( 'mundial', 'Mundial de prueba', null, 1 ) ), 'productos' => array() );
	$ok( Servicio::total( $l2, $c )['provisional'] && 0.0 === Servicio::total( $l2, $c )['total'], 'una insignia sin precio sigue dando un importe provisional' );
	$ok( ! method_exists( Servicio::class, 'lineas' ) && ! method_exists( Servicio::class, 'pedir' ) && ! method_exists( Servicio::class, 'servir' ) && ! method_exists( Servicio::class, 'devolver' ) && ! method_exists( Servicio::class, 'asignar' ) && ! method_exists( Servicio::class, 'precio_camisa' ), 'ya no hay pedidos de uniforme ni camisas que salgan del Inventario general (v0.44)' );
	$ok( ! array_key_exists( 'primera_gratis', Config::defaults() ), 'ya no existe la regla «primera camisa de scouter gratis»' );
	$ok( ! file_exists( ASDE_AP_DIR . 'assets/js/uniforme.js' ) && ! file_exists( ASDE_AP_DIR . 'templates/tienda/asignar.php' ), 'el formulario de uniforme y la asignación de camisas se han retirado' );

	// Pedidos de antes, creados a mano (el formulario ya no existe).
	$crear_pedido = static function ( string $socio, array $secciones, bool $adulto, array $lineas ) use ( $c ): int {
		$t = Servicio::total( $lineas, $c );
		return (int) PedidosTable::crear( $socio, $secciones, $adulto, $lineas, $t['total'], $t['provisional'], '' );
	};
	$id_leo  = $crear_pedido( '990021', array( 'lobatos' ), false, array( 'camisa' => $linea_camisa( 'masculina', '10', 25.0 ), 'insignias' => array( $insignia( 'parche', 'Parche de prueba', 2.5, 1 ), $insignia( 'mundial', 'Mundial de prueba', null, 1 ) ), 'productos' => array() ) );
	$id_nora = $crear_pedido( '990022', array( 'scouts' ), false, array( 'camisa' => $linea_camisa( 'femenina', '12', 25.0 ), 'insignias' => array(), 'productos' => array() ) );
	$id_pol  = $crear_pedido( '990023', array( 'escultas' ), false, array( 'camisa' => $linea_camisa( 'masculina', 'S', 25.0 ), 'insignias' => array(), 'productos' => array() ) );
	$id_eva  = $crear_pedido( '990024', array( 'rovers' ), true, array( 'camisa' => $linea_camisa( 'femenina', 'M', 25.0 ), 'insignias' => array(), 'productos' => array() ) );
	$id_tom  = $crear_pedido( '990025', array( 'lobatos' ), true, array( 'camisa' => $linea_camisa( 'masculina', 'L', 25.0 ), 'insignias' => array(), 'productos' => array() ) );
	$p_leo   = PedidosTable::obtener( $id_leo );
	$p_nora  = PedidosTable::obtener( $id_nora );
	$p_pol   = PedidosTable::obtener( $id_pol );
	$p_eva   = PedidosTable::obtener( $id_eva );
	$p_tom   = PedidosTable::obtener( $id_tom );
	$ok( $p_leo && '990021' === $p_leo['socio'] && 27.5 === $p_leo['total'] && $p_leo['provisional'] && 'Camisa masculina, talla 10 · Parche de prueba · Mundial de prueba' === Servicio::descripcion( $p_leo['lineas'] ), 'el pedido antiguo de Leo se lee: camisa 10 + parche + mundial (por confirmar) = 27,50 € provisional' );

	echo "\n== Parte B · Comprar (cada cuenta compra para sí y su familia) ==\n";
	$a_fam = $como( $u_fam );
	$ok( array( '990021', '990022' ) === array_map( 'strval', array_keys( Servicio::para_quien( $a_fam ) ) ), 'la familia compra para sus dos menores' );
	$a_pol = $como( $u_pol );
	$ok( array( '990023' ) === array_map( 'strval', array_keys( Servicio::para_quien( $a_pol ) ) ), 'un participante de 15 compra para sí' );
	$a_sc = $como( $u_scouter );
	$ok( array( '990025' ) === array_map( 'strval', array_keys( Servicio::para_quien( $a_sc ) ) ), 'un scouter solo compra para sí: ya no para los socios/as de su sección (P-177)' );
	$h = ( new TiendaPedidosView() )->render();
	$ok( ! str_contains( $h, 'uniforme_pedir' ) && ! str_contains( $h, 'Quiero la camisa' ) && str_contains( $h, 'Tienda' ), 'la pantalla «Mis pedidos» no tiene formulario de uniforme' );
	$ok( ! str_contains( $h, 'Hay en el inventario' ), 'y no habla del inventario del grupo' );

	$cerrado            = $cfg;
	$cerrado['abierto'] = false;
	Config::guardar( $cerrado, $grupo['numero'] );
	$como( $u_fam );
	$ok( ! str_contains( ( new TiendaPedidosView() )->render(), 'name="asde_action" value="tienda_comprar"' ), 'con los pedidos cerrados no hay formulario de compra' );
	Config::guardar( $cfg, $grupo['numero'] );

	echo "\n== Parte C · Quién gestiona qué ==\n";
	$a_sc = $como( $u_scouter );
	$ve   = array_column( Servicio::de_gestion( $a_sc ), 'socio' );
	$ok( in_array( '990021', $ve, true ) && ! array_intersect( array( '990022', '990023', '990024' ), $ve ), 'un scouter de Lobatos gestiona los de Lobatos (no los de otras secciones ni los de adultos)' );
	$a_te = $como( $u_teso );
	$a_co = $como( $u_coord );
	$ok( count( Servicio::de_gestion( $a_te ) ) >= 5 && count( Servicio::de_gestion( $a_co ) ) >= 5, 'Tesorería y Coordinación ven todos los del grupo' );
	$como( $u_fam );
	$ok( ! current_user_can( Servicio::CAP_GESTION ) && str_contains( ( new TiendaGestionView() )->render(), 'No tienes acceso' ), 'una familia no entra en la gestión' );
	$pt = Servicio::pendientes( $como( $u_teso ) );
	$pc = Servicio::pendientes( $como( $u_coord ) );
	$ok( $pt['pago'] >= 2 && 0 === $pt['pedido'] && $pc['pedido'] >= 4 && 0 === $pc['pago'], 'pendientes: Tesorería, los de pago; Coordinación, los de incluir en el pedido' );
	$tabs = View::pestanas( $como( $u_scouter ) );
	$ok( isset( $tabs['tienda_gestion'], $tabs['tienda_pedidos'], $tabs['pagos'] ) && str_starts_with( $tabs['tienda_pedidos'], 'Mis pedidos' ) && str_starts_with( $tabs['tienda_gestion'], 'Gestión de la tienda' ), 'pestañas del scouter: «Mis pedidos», «Gestión de la tienda» y «Mis pagos»' );
	$tabs = View::pestanas( $como( $u_fam ) );
	$ok( isset( $tabs['tienda_pedidos'], $tabs['pagos'] ) && ! isset( $tabs['tienda_gestion'] ), 'pestañas de la familia: «Tienda» y «Mis pagos»' );

	echo "\n== Parte D · Pago, pedido del grupo, entrega y recibido ==\n";
	$como( $u_scouter );
	$h = $enviar( new TiendaGestionView(), array( 'asde_action' => 'uniforme_pago', 'pedido' => (string) $p_leo['id'], 'metodo' => 'efectivo', 'fecha' => wp_date( 'Y-m-d' ), 'importe' => '27,50' ), 'asde_uniforme_' . $p_leo['id'] );
	$ok( null === PedidosTable::obtener( $p_leo['id'] )['pago_en'] && str_contains( $h, 'No tienes permiso' ), 'un scouter no valida pagos: es de Tesorería' );
	$como( $u_teso );
	$ok( 27.5 === Servicio::importe_a_cobrar( PedidosTable::obtener( $p_leo['id'] ) ), 'importe propuesto: el total con los precios de hoy (27,50 €; la insignia sin precio aún no cuenta)' );
	$h = $enviar( new TiendaGestionView(), array( 'asde_action' => 'uniforme_pago', 'pedido' => (string) $p_leo['id'], 'metodo' => 'efectivo', 'fecha' => wp_date( 'Y-m-d' ), 'importe' => '29,00', 'referencia' => 'Recibo 1' ), 'asde_uniforme_' . $p_leo['id'] );
	$q = PedidosTable::obtener( $p_leo['id'] );
	$ok( null !== $q['pago_en'] && 'efectivo' === $q['pago_metodo'] && 29.0 === $q['pago_importe'] && 'Recibo 1' === $q['pago_ref'] && $u_teso === $q['pago_por'] && str_contains( $h, 'validado' ), 'Tesorería valida el pago en efectivo (con el importe real y quién lo hizo)' );
	$h = $enviar( new TiendaGestionView(), array( 'asde_action' => 'uniforme_pago', 'pedido' => (string) $p_nora['id'], 'metodo' => 'ingreso', 'fecha' => '2099-01-01', 'importe' => '25' ), 'asde_uniforme_' . $p_nora['id'] );
	$ok( null === PedidosTable::obtener( $p_nora['id'] )['pago_en'] && str_contains( $h, 'no posterior a hoy' ), 'fecha futura → no' );
	$enviar( new TiendaGestionView(), array( 'asde_action' => 'uniforme_pago', 'pedido' => (string) $p_nora['id'], 'metodo' => 'ingreso', 'fecha' => wp_date( 'Y-m-d' ), 'importe' => '25' ), 'asde_uniforme_' . $p_nora['id'] );
	$enviar( new TiendaGestionView(), array( 'asde_action' => 'uniforme_deshacer_pago', 'pedido' => (string) $p_nora['id'] ), 'asde_uniforme_' . $p_nora['id'] );
	$ok( null === PedidosTable::obtener( $p_nora['id'] )['pago_en'], 'y lo puede deshacer' );
	$enviar( new TiendaGestionView(), array( 'asde_action' => 'uniforme_pago', 'pedido' => (string) $p_nora['id'], 'metodo' => 'ingreso', 'fecha' => wp_date( 'Y-m-d' ), 'importe' => '25' ), 'asde_uniforme_' . $p_nora['id'] );
	$ok( 'ingreso' === PedidosTable::obtener( $p_nora['id'] )['pago_metodo'], 'pago por ingreso en cuenta' );
	$como( $u_fam );
	$h = $enviar( new TiendaPedidosView(), array( 'asde_action' => 'uniforme_cancelar', 'pedido' => (string) $p_leo['id'] ), 'asde_uniforme_' . $p_leo['id'] );
	$ok( null === PedidosTable::obtener( $p_leo['id'] )['cancelado_en'] && str_contains( $h, 'ya no se puede cancelar' ), 'la familia ya no cancela un pedido pagado: habla con el grupo' );

	$a_co = $como( $u_coord );
	$ok( 0 < count( ( $r = Servicio::resumen( array_filter( Servicio::de_gestion( $a_co ), static fn( $p ) => PedidosTable::cumple( $p, PedidosTable::POR_PEDIR ) && null !== $p['pago_en'] ), Config::de() ) )['camisas'] ) && 1 === $r['camisas']['masculina']['tallas']['10'] && 1 === $r['camisas']['femenina']['tallas']['12'] && 1 === $r['insignias']['parche']['cantidad'], 'resumen para el proveedor: lo pagado por pedir, por modelo y talla, e insignias (pedidos de antes)' );
	$como( $u_scouter );
	$h = $enviar( new TiendaGestionView(), array( 'asde_action' => 'uniforme_incluir_lote', 'pedidos' => array( (string) $p_leo['id'] ), 'referencia_pedido' => 'Pedido prueba' ), 'asde_uniformes_lote' );
	$ok( null === PedidosTable::obtener( $p_leo['id'] )['pedido_en'] && str_contains( $h, 'lo hace Coordinación' ), 'un scouter no incluye en el pedido: es de Coordinación' );
	$como( $u_coord );
	$h = $enviar( new TiendaGestionView(), array( 'asde_action' => 'uniforme_incluir_lote', 'pedidos' => array( (string) $p_leo['id'], (string) $p_nora['id'] ), 'referencia_pedido' => 'Pedido prueba' ), 'asde_uniformes_lote' );
	$ok( 'Pedido prueba' === PedidosTable::obtener( $p_leo['id'] )['pedido_ref'] && null !== PedidosTable::obtener( $p_nora['id'] )['pedido_en'] && str_contains( $h, '2 pedidos incluidos' ), 'Coordinación incluye varios a la vez en «Pedido prueba»' );
	$pe = Servicio::pendientes( $como( $u_scouter ) );
	$ok( 1 === $pe['entrega'], 'al scouter de Lobatos le queda 1 por entregar (el de Leo)' );
	$h = $enviar( new TiendaGestionView(), array( 'asde_action' => 'uniforme_entregar', 'pedido' => (string) $p_nora['id'] ), 'asde_uniforme_' . $p_nora['id'] );
	$ok( null === PedidosTable::obtener( $p_nora['id'] )['entregado_en'] && str_contains( $h, 'No tienes acceso' ), 'no entrega los de otra sección' );
	$enviar( new TiendaGestionView(), array( 'asde_action' => 'uniforme_entregar', 'pedido' => (string) $p_leo['id'] ), 'asde_uniforme_' . $p_leo['id'] );
	$ok( null !== PedidosTable::obtener( $p_leo['id'] )['entregado_en'] && $u_scouter === PedidosTable::obtener( $p_leo['id'] )['entregado_por'], 'el scouter de Lobatos entrega el de Leo' );

	$como( $u_fam2 );
	$h = $enviar( new TiendaPedidosView(), array( 'asde_action' => 'uniforme_recibido', 'pedido' => (string) $p_leo['id'] ), 'asde_uniforme_' . $p_leo['id'] );
	$ok( null === PedidosTable::obtener( $p_leo['id'] )['recibido_en'] && str_contains( $h, 'No tienes acceso' ), 'otra familia no puede confirmarlo' );
	$a_fam = $como( $u_fam );
	$ok( 1 === Servicio::pendientes( $a_fam )['confirmar'] && str_contains( ( new \AsdeBalears\AreaPrivada\Frontend\Views\AreaPrivadaView() )->render(), 'Confirma que lo has recibido' ), 'a la familia se le avisa en el inicio de que lo confirme' );
	$enviar( new TiendaPedidosView(), array( 'asde_action' => 'uniforme_recibido', 'pedido' => (string) $p_leo['id'] ), 'asde_uniforme_' . $p_leo['id'] );
	$ok( null !== PedidosTable::obtener( $p_leo['id'] )['recibido_en'] && 'recibido' === PedidosTable::estado( PedidosTable::obtener( $p_leo['id'] ) ), 'la familia confirma que lo ha recibido' );

	$como( $u_coord );
	$h = $enviar( new TiendaGestionView(), array( 'asde_action' => 'uniforme_cancelar', 'pedido' => (string) $p_eva['id'], 'motivo' => '' ), 'asde_uniforme_' . $p_eva['id'] );
	$ok( null === PedidosTable::obtener( $p_eva['id'] )['cancelado_en'] && str_contains( $h, 'motivo' ), 'Coordinación cancela solo con motivo' );
	$enviar( new TiendaGestionView(), array( 'asde_action' => 'uniforme_cancelar', 'pedido' => (string) $p_eva['id'], 'motivo' => 'Prueba' ), 'asde_uniforme_' . $p_eva['id'] );
	$enviar( new TiendaGestionView(), array( 'asde_action' => 'uniforme_cancelar', 'pedido' => (string) $p_leo['id'], 'motivo' => 'Prueba' ), 'asde_uniforme_' . $p_leo['id'] );
	$ok( 'Prueba' === PedidosTable::obtener( $p_eva['id'] )['cancelado_motivo'] && null === PedidosTable::obtener( $p_leo['id'] )['cancelado_en'], 'cancelado con motivo; uno ya entregado no se cancela' );

	echo "\n== Parte E · Mis pagos ==\n";
	$fam = FamiliasTable::crear( array( '990021', '990022' ) );
	$ronda = TesoreriaConfig::ronda_actual();
	MovimientosTable::registrar( (int) $fam, $ronda, MovimientosTable::PAGO, wp_date( 'Y-m-d' ), 100.0, 'transferencia', 'REF-PRUEBA', 'Nota interna de Tesorería' );
	MovimientosTable::registrar( (int) $fam, $ronda, MovimientosTable::AJUSTE, wp_date( 'Y-m-d' ), -30.0, '', '', 'Beca interna de prueba' );
	$anulado = MovimientosTable::registrar( (int) $fam, $ronda, MovimientosTable::PAGO, wp_date( 'Y-m-d' ), 999.0, 'efectivo', '', 'Error' );
	MovimientosTable::anular( (int) $anulado );
	$como( $u_fam );
	$h = ( new MisPagosView() )->render();
	$ok( ! is_wp_error( $fam ) && str_contains( $h, 'Cuota del grupo · ronda ' . $ronda ) && str_contains( $h, '100,00 €' ) && str_contains( $h, 'Transferencia' ) && str_contains( $h, 'REF-PRUEBA' ), 'la familia ve la cuota de la ronda y el pago de 100 € por transferencia' );
	$ok( str_contains( $h, PedidosTable::numero( $p_leo ) ) && str_contains( $h, '29,00 €' ) && str_contains( $h, 'Efectivo' ), 'y el pago del pedido de Leo (29 € en efectivo)' );
	$ok( ! str_contains( $h, 'Nota interna' ) && ! str_contains( $h, 'Beca interna' ) && ! str_contains( $h, '999,00' ), 'sin las notas internas de Tesorería ni los pagos anulados' );
	$ok( str_contains( $h, 'Hasta el' ) && str_contains( $h, 'F684' ), 'con los plazos y la referencia de la familia para la transferencia' );
	$como( $u_pol );
	$h = ( new MisPagosView() )->render();
	$ok( ! str_contains( $h, 'Cuota del grupo' ) && str_contains( $h, 'Pendiente de pago' ) && str_contains( $h, PedidosTable::numero( $p_pol ) ), 'Pol (sin familia en Tesorería): su pedido pendiente de pago' );
	$como( $u_fam2 );
	$h = ( new MisPagosView() )->render();
	$ok( ! str_contains( $h, 'REF-PRUEBA' ) && str_contains( $h, 'Todavía no hay pagos registrados' ), 'otra familia no ve los pagos de esta' );

	echo "\n== Parte F · Pantallas y configuración ==\n";
	$como( $u_fam );
	$h = ( new TiendaPedidosView() )->render();
	$ok( str_contains( $h, 'Lo he recibido' ) === false && str_contains( $h, PedidosTable::numero( $p_leo ) ) && str_contains( $h, 'Recibido' ), 'sus pedidos, con los pasos (el de Leo, recibido)' );
	$como( $u_teso );
	$h = ( new TiendaGestionView() )->render();
	$ok( str_contains( $h, PedidosTable::numero( $p_pol ) ) && str_contains( $h, 'data-asde-modal>Validar</a>' ) && preg_match( '#<strong>(?:1</strong> pendiente|(?:0|[2-9]|[1-9]\d+)</strong> pendientes) de pago#', $h ), 'gestión de Tesorería: la lista con «Validar» (en la ventana) y los pendientes (en singular si es uno)' );
	$ok( ! str_contains( $h, 'Asignar una camisa' ) && ! str_contains( $h, 'Camisas del inventario' ), 'la gestión ya no asigna camisas ni enseña las del Inventario general' );
	View::en_modal( true );
	$_GET['pedido'] = (string) $p_pol['id'];
	$h              = ( new TiendaGestionView() )->render();
	unset( $_GET['pedido'] );
	View::en_modal( false );
	$ok( in_array( 'tienda_gestion', Router::MODALES, true ) && str_contains( $h, 'asde-ap--modal' ) && str_contains( $h, 'Validar el pago' ) && str_contains( $h, '<select name="metodo">' ) && str_contains( $h, 'value="ingreso"' ) && ! str_contains( $h, 'Servir del inventario' ), 'detalle en la ventana emergente, con el formulario del pago (efectivo o ingreso) y sin «servir del inventario»' );
	$como( $u_scouter );
	$ok( str_contains( ( new TiendaConfigView() )->render(), 'No tienes acceso' ), 'la configuración es de la administración de la tienda' );
	$como( $u_coord );
	$enviar(
		new TiendaConfigView(),
		array(
			'asde_action'    => 'tienda_config',
			'abierto'        => '1',
			'texto_pago'     => 'En efectivo los sábados.',
			'precio_camisa'  => '99',
			'primera_gratis' => '1',
		),
		'asde_tienda_admin'
	);
	$c = Config::de( $grupo['numero'] );
	$ok( 'En efectivo los sábados.' === $c['texto_pago'] && 25.0 === $c['precio_camisa'] && isset( $c['insignias']['mundial'] ), 'Coordinación guarda la configuración (cómo pagar); un precio de camisa que llegue a mano ya no se toma' );
	$h = ( new TiendaConfigView() )->render();
	$ok( ! str_contains( $h, 'Modelos y tallas' ) && ! str_contains( $h, 'primera_gratis' ) && ! str_contains( $h, 'Nueva insignia' ) && str_contains( $h, 'name="texto_pago"' ), 'y la pantalla de configuración ya no tiene modelos, tallas, insignias ni precio de camisa' );
	$cfg_precio = $c;
	$cfg_precio['insignias']['mundial']['precio'] = 3.0;
	Config::guardar( $cfg_precio, $grupo['numero'] );
	$ok( 30.5 === Servicio::importe_a_cobrar( PedidosTable::obtener( $p_leo['id'] ) ), 'con el precio de la insignia ya puesto, el importe propuesto de un pedido antiguo «por confirmar» lo incluye (27,50 + 3 €)' );
	$ok( $crm->llamadas > 0 && 0 === $cortadas, "todas las consultas al CRM las ha respondido el simulador ({$crm->llamadas}); ninguna ha salido hacia el CRM real" );
} finally {
	// ---------------------------------------------------------------------------
	// Limpieza: todo lo creado fuera; lo tocado, como estaba.
	remove_filter( 'wp_redirect', $redirigir, 1 );
	remove_filter( 'send_auth_cookies', '__return_false' );
	remove_filter( 'pre_wp_mail', '__return_true' );
	$_POST = array();
	View::en_modal( false );
	$crm->desactivar();
	remove_filter( 'pre_http_request', $cortar_crm, 2 );
	wp_using_ext_object_cache( $cache_externa );
	wp_set_current_user( 0 );

	$lista = implode( ',', array_map( 'intval', $ids ) ) ?: '0';
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . PedidosTable::table() . ' WHERE id > %d', $ped_antes ) ); // phpcs:ignore WordPress.DB
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . MovimientosTable::table() . ' WHERE id > %d', $mov_antes ) ); // phpcs:ignore WordPress.DB
	$familias = implode( ',', array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . FamiliasTable::table() . ' WHERE id > %d', $fam_antes ) ) ) ) ?: '0'; // phpcs:ignore WordPress.DB
	$en_falsos = "'" . implode( "','", array_map( 'esc_sql', $falsos ) ) . "'";
	$wpdb->query( 'DELETE FROM ' . FamiliasTable::table_miembros() . " WHERE familia_id IN ($familias) OR num_socio_asde IN ($en_falsos)" ); // phpcs:ignore WordPress.DB
	$wpdb->query( 'DELETE FROM ' . FamiliasTable::table() . " WHERE id IN ($familias)" ); // phpcs:ignore WordPress.DB
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Logger::table() . " WHERE id > %d AND ( user_id IN ($lista) OR objeto = 'uniforme' OR ( objeto = 'grupo' AND accion LIKE 'uniformes\\_%%' AND user_id IN ($lista) ) )", $log_antes ) ); // phpcs:ignore WordPress.DB
	foreach ( $ids as $uid ) {
		Ambito::olvidar( (int) $uid );
		wp_delete_user( (int) $uid );
	}
	if ( null === $cfg_antes ) {
		delete_option( $opcion_cfg );
	} else {
		update_option( $opcion_cfg, $cfg_antes, false );
	}
	// Tabla de números: como estaba.
	$ahora = array_column( (array) $wpdb->get_results( 'SELECT * FROM ' . SociosTable::table(), ARRAY_A ), null, 'num_socio_asde' ); // phpcs:ignore WordPress.DB
	foreach ( array_diff_key( $ahora, $socios_antes ) as $asde => $f ) {
		$wpdb->delete( SociosTable::table(), array( 'num_socio_asde' => (string) $asde ) );
	}
	foreach ( $socios_antes as $asde => $f ) {
		if ( ( $ahora[ $asde ] ?? null ) !== $f ) {
			$wpdb->replace( SociosTable::table(), $f );
		}
	}
	Grupo::forzar( null );

	$quedan = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users} WHERE user_login LIKE 'prueba-uni-%'" ); // phpcs:ignore WordPress.DB
	$ok( 0 === $quedan && (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . PedidosTable::table() . ' WHERE id > ' . (int) $ped_antes ) === 0 && get_option( $opcion_cfg, null ) == $cfg_antes, 'limpieza: sin usuarios, pedidos ni familias de la prueba; configuración de uniformes como estaba' ); // phpcs:ignore WordPress.DB
}

echo $fails ? "\n$fails FALLOS\n" : "\nTODO OK (nada tocado en el CRM real; el sitio queda como estaba)\n";
