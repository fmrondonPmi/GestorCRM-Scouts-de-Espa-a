<?php
/**
 * Pruebas de la v0.11 (entorno LOCAL): pedidos de uniforme y «Mis pagos».
 * Ejecutar desde app/public del site Local:
 *
 *   wp eval-file <ruta completa>/wp-content/plugins/asde-area-privada/tests/uniformes.php
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
use AsdeBalears\AreaPrivada\Frontend\Views\UniformeView;
use AsdeBalears\AreaPrivada\Frontend\Views\UniformesConfigView;
use AsdeBalears\AreaPrivada\Frontend\Views\UniformesGestionView;
use AsdeBalears\AreaPrivada\Frontend\Views\View;
use AsdeBalears\AreaPrivada\Install\Roles;
use AsdeBalears\AreaPrivada\Socios\SociosTable;
use AsdeBalears\AreaPrivada\Tesoreria\Config as TesoreriaConfig;
use AsdeBalears\AreaPrivada\Tesoreria\FamiliasTable;
use AsdeBalears\AreaPrivada\Tesoreria\MovimientosTable;
use AsdeBalears\AreaPrivada\Uniformes\Config;
use AsdeBalears\AreaPrivada\Uniformes\PedidosTable;
use AsdeBalears\AreaPrivada\Uniformes\Servicio;
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
$opcion_cfg = 'asde_ap_uniformes_' . $grupo['numero'];
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
	echo "== Parte A · Qué se pide y cuánto cuesta ==\n";
	$c = Config::de( $grupo['numero'] );
	$ok( 25.0 === $c['precio_camisa'] && 13 === count( $c['modelos']['masculina']['tallas'] ) && 10 === count( $c['modelos']['femenina']['tallas'] ) && Config::es_especial( $c, '3XL' ), 'camisa a 25 €; masculina de la 6 a la 3XL (13 tallas), femenina de la 12 a la 3XL (10); la 3XL es talla especial' );
	$l = Servicio::lineas( $c, 'masculina|M', array( 'parche' => 2 ) );
	$ok( is_array( $l ) && 'M' === $l['camisa']['talla'] && 30.0 === Servicio::total( $l, $c )['total'] && ! Servicio::total( $l, $c )['provisional'], 'camisa M + 2 parches de 2,50 € = 30 €' );
	$ok( 'Camisa masculina, talla M · Parche de prueba ×2' === Servicio::descripcion( $l ), 'descripción legible del pedido' );
	$ok( is_wp_error( Servicio::lineas( $c, 'femenina|6', array() ) ) && is_wp_error( Servicio::lineas( $c, '', array() ) ), 'talla que no existe en ese modelo, o nada pedido → error' );
	$ok( is_wp_error( Servicio::lineas( $c, '', array( 'parche' => 11 ) ) ) && is_wp_error( Servicio::lineas( $c, '', array( 'vieja' => 1 ) ) ), 'más de 10 insignias o una retirada → error' );
	$l2 = Servicio::lineas( $c, '', array( 'mundial' => 1 ) );
	$ok( is_array( $l2 ) && ! $l2['camisa'] && Servicio::total( $l2, $c )['provisional'] && 0.0 === Servicio::total( $l2, $c )['total'], 'solo insignias, con el precio por confirmar → importe provisional' );

	echo "\n== Parte B · Pedir (asociados) ==\n";
	$a_fam = $como( $u_fam );
	$ok( array( '990021', '990022' ) === array_map( 'strval', array_keys( Servicio::para_quien( $a_fam ) ) ), 'la familia pide para sus dos menores' );
	$h = $enviar( new UniformeView(), array( 'asde_action' => 'uniforme_pedir', 'socio' => '990021', 'con_camisa' => '1', 'camisa' => 'masculina|10', 'insignia_parche' => '1', 'insignia_mundial' => '1', 'nota' => 'Prueba' ), 'asde_uniforme_pedir' );
	$p_leo = $ultimo();
	$ok( $p_leo && '990021' === $p_leo['socio'] && array( 'lobatos' ) === $p_leo['secciones'] && ! $p_leo['adulto'] && 27.5 === $p_leo['total'] && $p_leo['provisional'] && str_contains( $h, PedidosTable::numero( $p_leo ) ), 'pedido de Leo: camisa 10 + parche + mundial (por confirmar) = 27,50 € provisional, con su nº en el aviso' );
	$enviar( new UniformeView(), array( 'asde_action' => 'uniforme_pedir', 'socio' => '990022', 'con_camisa' => '1', 'camisa' => 'femenina|12' ), 'asde_uniforme_pedir' );
	$p_nora = $ultimo();
	$ok( $p_nora && '990022' === $p_nora['socio'] && 25.0 === $p_nora['total'], 'pedido de Nora: camisa femenina 12' );
	$antes = $ultimo()['id'];
	$h     = $enviar( new UniformeView(), array( 'asde_action' => 'uniforme_pedir', 'socio' => '990023', 'con_camisa' => '1', 'camisa' => 'masculina|M' ), 'asde_uniforme_pedir' );
	$ok( $ultimo()['id'] === $antes && str_contains( $h, 'No puedes hacer un pedido para esa persona' ), 'para alguien que no es de su familia → no' );

	$a_pol = $como( $u_pol );
	$enviar( new UniformeView(), array( 'asde_action' => 'uniforme_pedir', 'socio' => '990023', 'con_camisa' => '1', 'camisa' => 'masculina|S' ), 'asde_uniforme_pedir' );
	$p_pol = $ultimo();
	$ok( $p_pol && '990023' === $p_pol['socio'] && array( '990023' ) === array_map( 'strval', array_keys( Servicio::para_quien( $a_pol ) ) ), 'un participante de 15 pide la suya' );
	$enviar( new UniformeView(), array( 'asde_action' => 'uniforme_pedir', 'socio' => '990023', 'con_camisa' => '1', 'camisa' => 'masculina|L' ), 'asde_uniforme_pedir' );
	$p_pol2 = $ultimo();
	$enviar( new UniformeView(), array( 'asde_action' => 'uniforme_cancelar', 'pedido' => (string) $p_pol2['id'] ), 'asde_uniforme_' . $p_pol2['id'] );
	$ok( null !== PedidosTable::obtener( $p_pol2['id'] )['cancelado_en'], 'y cancela uno mientras nadie lo ha validado' );

	$como( $u_teso );
	$enviar( new UniformeView(), array( 'asde_action' => 'uniforme_pedir', 'socio' => '990024', 'con_camisa' => '1', 'camisa' => 'femenina|M' ), 'asde_uniforme_pedir' );
	$p_eva = $ultimo();
	$ok( $p_eva && $p_eva['adulto'], 'los scouters también piden la suya (Tesorería, adulta)' );
	$como( $u_scouter );
	$enviar( new UniformeView(), array( 'asde_action' => 'uniforme_pedir', 'socio' => '990025', 'con_camisa' => '1', 'camisa' => 'masculina|L' ), 'asde_uniforme_pedir' );
	$p_tom = $ultimo();
	$ok( $p_tom && '990025' === $p_tom['socio'] && $p_tom['adulto'], 'un scouter (solo scouter, de Lobatos) pide su camisa' );
	// v0.29 (petición del usuario): la primera camisa de un scouter es gratis y queda pagada sola.
	$ok( 0.0 === $p_eva['total'] && 0.0 === $p_tom['total'] && null !== $p_tom['pago_en'] && PedidosTable::REF_SIN_COSTE === $p_tom['pago_ref'] && 25.0 === Servicio::precio_camisa( '990025', true, Config::de() ), 'la primera camisa de un scouter, 0 € y pagada «sin coste»; la segunda ya costaría 25 €' );
	// v0.30 (P-48, decisión del usuario): un scouter pide también para los socios/as de su sección, no para los de otras.
	$enviar( new UniformeView(), array( 'asde_action' => 'uniforme_pedir', 'socio' => '990022', 'con_camisa' => '1', 'camisa' => 'femenina|12' ), 'asde_uniforme_pedir' );
	$ok( $ultimo()['id'] === $p_tom['id'], 'el scouter de Lobatos no pide para alguien de otra sección (Nora, de Scouts)' );
	$enviar( new UniformeView(), array( 'asde_action' => 'uniforme_pedir', 'socio' => '990021', 'con_camisa' => '1', 'camisa' => 'masculina|S' ), 'asde_uniforme_pedir' );
	$p_leo2 = $ultimo();
	$ok( $p_leo2['id'] > $p_tom['id'] && '990021' === $p_leo2['socio'] && $u_scouter === $p_leo2['creado_por'] && 25.0 === $p_leo2['total'] && ! $p_leo2['adulto'], 'pero sí para alguien de su sección (Leo, de Lobatos), con el precio de Leo (P-48, v0.30)' );
	$enviar( new UniformeView(), array( 'asde_action' => 'uniforme_cancelar', 'pedido' => (string) $p_leo2['id'] ), 'asde_uniforme_' . $p_leo2['id'] );
	$ok( null !== PedidosTable::obtener( $p_leo2['id'] )['cancelado_en'], 'y lo puede cancelar (lo pidió él y nadie lo ha validado)' );

	$cerrado            = $cfg;
	$cerrado['abierto'] = false;
	Config::guardar( $cerrado, $grupo['numero'] );
	$como( $u_fam );
	$antes = $ultimo()['id'];
	$h     = $enviar( new UniformeView(), array( 'asde_action' => 'uniforme_pedir', 'socio' => '990021', 'con_camisa' => '1', 'camisa' => 'masculina|12' ), 'asde_uniforme_pedir' );
	$ok( $ultimo()['id'] === $antes && str_contains( $h, 'no se aceptan pedidos' ) && str_contains( ( new UniformeView() )->render(), 'no se aceptan pedidos' ), 'con los pedidos cerrados no se puede pedir, y se dice' );
	Config::guardar( $cfg, $grupo['numero'] );

	echo "\n== Parte C · Quién gestiona qué ==\n";
	$a_sc = $como( $u_scouter );
	$ve   = array_column( Servicio::de_gestion( $a_sc ), 'socio' );
	$ok( in_array( '990021', $ve, true ) && ! array_intersect( array( '990022', '990023', '990024' ), $ve ), 'un scouter de Lobatos gestiona los de Lobatos (no los de otras secciones ni los de adultos)' );
	$a_te = $como( $u_teso );
	$a_co = $como( $u_coord );
	$ok( count( Servicio::de_gestion( $a_te ) ) >= 5 && count( Servicio::de_gestion( $a_co ) ) >= 5, 'Tesorería y Coordinación ven todos los del grupo' );
	$como( $u_fam );
	$ok( ! current_user_can( Servicio::CAP_GESTION ) && str_contains( ( new UniformesGestionView() )->render(), 'No tienes acceso' ), 'una familia no entra en la gestión' );
	$pt = Servicio::pendientes( $como( $u_teso ) );
	$pc = Servicio::pendientes( $como( $u_coord ) );
	$ok( $pt['pago'] >= 2 && 0 === $pt['pedido'] && $pc['pedido'] >= 4 && 0 === $pc['pago'], 'pendientes: Tesorería, los de pago; Coordinación, los de incluir en el pedido' );
	$tabs = View::pestanas( $como( $u_scouter ) );
	$ok( isset( $tabs['uniformes'], $tabs['uniforme'], $tabs['pagos'] ) && str_starts_with( $tabs['uniforme'], 'Mi uniforme' ) && str_starts_with( $tabs['uniformes'], 'Gestión de uniformes' ), 'pestañas del scouter: «Mi uniforme» (pedir), «Gestión de uniformes» y «Mis pagos»' );
	$como( $u_scouter );
	$h = ( new UniformeView() )->render();
	$ok( str_contains( $h, 'name="asde_action" value="uniforme_pedir"' ) && ! str_contains( $h, 'Desde aquí se gestionan los pedidos del grupo' ), 'el scouter ve el formulario para pedir su uniforme (no solo la gestión)' );
	$tabs = View::pestanas( $como( $u_scouter ) );
	$tabs = View::pestanas( $como( $u_fam ) );
	$ok( isset( $tabs['uniforme'], $tabs['pagos'] ) && ! isset( $tabs['uniformes'] ), 'pestañas de la familia: «Uniforme» y «Mis pagos»' );

	echo "\n== Parte D · Pago, pedido del grupo, entrega y recibido ==\n";
	$como( $u_scouter );
	$h = $enviar( new UniformesGestionView(), array( 'asde_action' => 'uniforme_pago', 'pedido' => (string) $p_leo['id'], 'metodo' => 'efectivo', 'fecha' => wp_date( 'Y-m-d' ), 'importe' => '27,50' ), 'asde_uniforme_' . $p_leo['id'] );
	$ok( null === PedidosTable::obtener( $p_leo['id'] )['pago_en'] && str_contains( $h, 'No tienes permiso' ), 'un scouter no valida pagos: es de Tesorería' );
	$como( $u_teso );
	$ok( 27.5 === Servicio::importe_a_cobrar( PedidosTable::obtener( $p_leo['id'] ) ), 'importe propuesto: el total con los precios de hoy (27,50 €; la insignia sin precio aún no cuenta)' );
	$h = $enviar( new UniformesGestionView(), array( 'asde_action' => 'uniforme_pago', 'pedido' => (string) $p_leo['id'], 'metodo' => 'efectivo', 'fecha' => wp_date( 'Y-m-d' ), 'importe' => '29,00', 'referencia' => 'Recibo 1' ), 'asde_uniforme_' . $p_leo['id'] );
	$q = PedidosTable::obtener( $p_leo['id'] );
	$ok( null !== $q['pago_en'] && 'efectivo' === $q['pago_metodo'] && 29.0 === $q['pago_importe'] && 'Recibo 1' === $q['pago_ref'] && $u_teso === $q['pago_por'] && str_contains( $h, 'validado' ), 'Tesorería valida el pago en efectivo (con el importe real y quién lo hizo)' );
	$h = $enviar( new UniformesGestionView(), array( 'asde_action' => 'uniforme_pago', 'pedido' => (string) $p_nora['id'], 'metodo' => 'ingreso', 'fecha' => '2099-01-01', 'importe' => '25' ), 'asde_uniforme_' . $p_nora['id'] );
	$ok( null === PedidosTable::obtener( $p_nora['id'] )['pago_en'] && str_contains( $h, 'no posterior a hoy' ), 'fecha futura → no' );
	$enviar( new UniformesGestionView(), array( 'asde_action' => 'uniforme_pago', 'pedido' => (string) $p_nora['id'], 'metodo' => 'ingreso', 'fecha' => wp_date( 'Y-m-d' ), 'importe' => '25' ), 'asde_uniforme_' . $p_nora['id'] );
	$enviar( new UniformesGestionView(), array( 'asde_action' => 'uniforme_deshacer_pago', 'pedido' => (string) $p_nora['id'] ), 'asde_uniforme_' . $p_nora['id'] );
	$ok( null === PedidosTable::obtener( $p_nora['id'] )['pago_en'], 'y lo puede deshacer' );
	$enviar( new UniformesGestionView(), array( 'asde_action' => 'uniforme_pago', 'pedido' => (string) $p_nora['id'], 'metodo' => 'ingreso', 'fecha' => wp_date( 'Y-m-d' ), 'importe' => '25' ), 'asde_uniforme_' . $p_nora['id'] );
	$ok( 'ingreso' === PedidosTable::obtener( $p_nora['id'] )['pago_metodo'], 'pago por ingreso en cuenta' );
	$como( $u_fam );
	$h = $enviar( new UniformeView(), array( 'asde_action' => 'uniforme_cancelar', 'pedido' => (string) $p_leo['id'] ), 'asde_uniforme_' . $p_leo['id'] );
	$ok( null === PedidosTable::obtener( $p_leo['id'] )['cancelado_en'] && str_contains( $h, 'ya no se puede cancelar' ), 'la familia ya no cancela un pedido pagado: habla con el grupo' );

	$a_co = $como( $u_coord );
	$ok( 0 < count( ( $r = Servicio::resumen( array_filter( Servicio::de_gestion( $a_co ), static fn( $p ) => PedidosTable::cumple( $p, PedidosTable::POR_PEDIR ) && null !== $p['pago_en'] ), Config::de() ) )['camisas'] ) && 1 === $r['camisas']['masculina']['tallas']['10'] && 1 === $r['camisas']['femenina']['tallas']['12'] && 1 === $r['insignias']['parche']['cantidad'], 'resumen para el proveedor: lo pagado por pedir, por modelo y talla, e insignias' );
	$como( $u_scouter );
	$h = $enviar( new UniformesGestionView(), array( 'asde_action' => 'uniforme_incluir_lote', 'pedidos' => array( (string) $p_leo['id'] ), 'referencia_pedido' => 'Pedido prueba' ), 'asde_uniformes_lote' );
	$ok( null === PedidosTable::obtener( $p_leo['id'] )['pedido_en'] && str_contains( $h, 'lo hace Coordinación' ), 'un scouter no incluye en el pedido: es de Coordinación' );
	$como( $u_coord );
	$h = $enviar( new UniformesGestionView(), array( 'asde_action' => 'uniforme_incluir_lote', 'pedidos' => array( (string) $p_leo['id'], (string) $p_nora['id'] ), 'referencia_pedido' => 'Pedido prueba' ), 'asde_uniformes_lote' );
	$ok( 'Pedido prueba' === PedidosTable::obtener( $p_leo['id'] )['pedido_ref'] && null !== PedidosTable::obtener( $p_nora['id'] )['pedido_en'] && str_contains( $h, '2 pedidos incluidos' ), 'Coordinación incluye varios a la vez en «Pedido prueba»' );
	$pe = Servicio::pendientes( $como( $u_scouter ) );
	$ok( 1 === $pe['entrega'], 'al scouter de Lobatos le queda 1 por entregar (el de Leo)' );
	$h = $enviar( new UniformesGestionView(), array( 'asde_action' => 'uniforme_entregar', 'pedido' => (string) $p_nora['id'] ), 'asde_uniforme_' . $p_nora['id'] );
	$ok( null === PedidosTable::obtener( $p_nora['id'] )['entregado_en'] && str_contains( $h, 'No tienes acceso' ), 'no entrega los de otra sección' );
	$enviar( new UniformesGestionView(), array( 'asde_action' => 'uniforme_entregar', 'pedido' => (string) $p_leo['id'] ), 'asde_uniforme_' . $p_leo['id'] );
	$ok( null !== PedidosTable::obtener( $p_leo['id'] )['entregado_en'] && $u_scouter === PedidosTable::obtener( $p_leo['id'] )['entregado_por'], 'el scouter de Lobatos entrega el de Leo' );

	$como( $u_fam2 );
	$h = $enviar( new UniformeView(), array( 'asde_action' => 'uniforme_recibido', 'pedido' => (string) $p_leo['id'] ), 'asde_uniforme_' . $p_leo['id'] );
	$ok( null === PedidosTable::obtener( $p_leo['id'] )['recibido_en'] && str_contains( $h, 'No tienes acceso' ), 'otra familia no puede confirmarlo' );
	$a_fam = $como( $u_fam );
	$ok( 1 === Servicio::pendientes( $a_fam )['confirmar'] && str_contains( ( new \AsdeBalears\AreaPrivada\Frontend\Views\AreaPrivadaView() )->render(), 'Confirma que lo has recibido' ), 'a la familia se le avisa en el inicio de que lo confirme' );
	$enviar( new UniformeView(), array( 'asde_action' => 'uniforme_recibido', 'pedido' => (string) $p_leo['id'] ), 'asde_uniforme_' . $p_leo['id'] );
	$ok( null !== PedidosTable::obtener( $p_leo['id'] )['recibido_en'] && 'recibido' === PedidosTable::estado( PedidosTable::obtener( $p_leo['id'] ) ), 'la familia confirma que lo ha recibido' );

	$como( $u_coord );
	$h = $enviar( new UniformesGestionView(), array( 'asde_action' => 'uniforme_cancelar', 'pedido' => (string) $p_eva['id'], 'motivo' => '' ), 'asde_uniforme_' . $p_eva['id'] );
	$ok( null === PedidosTable::obtener( $p_eva['id'] )['cancelado_en'] && str_contains( $h, 'motivo' ), 'Coordinación cancela solo con motivo' );
	$enviar( new UniformesGestionView(), array( 'asde_action' => 'uniforme_cancelar', 'pedido' => (string) $p_eva['id'], 'motivo' => 'Prueba' ), 'asde_uniforme_' . $p_eva['id'] );
	$enviar( new UniformesGestionView(), array( 'asde_action' => 'uniforme_cancelar', 'pedido' => (string) $p_leo['id'], 'motivo' => 'Prueba' ), 'asde_uniforme_' . $p_leo['id'] );
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
	$ok( str_contains( $h, PedidosTable::numero( $p_leo ) ) && str_contains( $h, '29,00 €' ) && str_contains( $h, 'Efectivo' ), 'y el pago del uniforme de Leo (29 € en efectivo)' );
	$ok( ! str_contains( $h, 'Nota interna' ) && ! str_contains( $h, 'Beca interna' ) && ! str_contains( $h, '999,00' ), 'sin las notas internas de Tesorería ni los pagos anulados' );
	$ok( str_contains( $h, 'Hasta el' ) && str_contains( $h, 'F684' ), 'con los plazos y la referencia de la familia para la transferencia' );
	$como( $u_pol );
	$h = ( new MisPagosView() )->render();
	$ok( ! str_contains( $h, 'Cuota del grupo' ) && str_contains( $h, 'Pendiente de pago' ) && str_contains( $h, PedidosTable::numero( $p_pol ) ), 'Pol (sin familia en Tesorería): su uniforme pendiente de pago' );
	$como( $u_fam2 );
	$h = ( new MisPagosView() )->render();
	$ok( ! str_contains( $h, 'REF-PRUEBA' ) && str_contains( $h, 'Todavía no hay pagos registrados' ), 'otra familia no ve los pagos de esta' );

	echo "\n== Parte F · Pantallas y configuración ==\n";
	$como( $u_fam );
	$h = ( new UniformeView() )->render();
	$ok( str_contains( $h, '<optgroup label="Camisa masculina">' ) && str_contains( $h, 'masculina|3XL' ) && str_contains( $h, '(talla especial)' ) && str_contains( $h, 'Precio por confirmar' ) && ! str_contains( $h, 'Retirada' ), 'formulario: tallas por modelo (3XL especial), insignias con su precio o «por confirmar», sin las retiradas' );
	// v0.12.5: las tablas de medidas de la camisa van dentro del plugin y se abren encima del pedido.
	$cfg_t = Config::de();
	$f_url = Config::guia_medidas( $cfg_t, 'femenina' );
	$m_url = Config::guia_medidas( $cfg_t, 'masculina' );
	$ok( str_ends_with( $f_url, 'assets/img/medidas-camisa-femenina.png' ) && str_ends_with( $m_url, 'assets/img/medidas-camisa-masculina.png' ) && '' === Config::guia_medidas( $cfg_t, 'otro' ), 'sin imagen propia en la configuración, cada modelo usa la tabla de medidas de ASDE que va en el plugin' );
	foreach ( array( 'femenina', 'masculina' ) as $mod ) {
		$info = getimagesize( ASDE_AP_DIR . 'assets/img/medidas-camisa-' . $mod . '.png' );
		$ok( $info && 'image/png' === $info['mime'] && $info[0] >= 800, "la imagen de la camisa $mod es un PNG legible (" . ( $info ? $info[0] . '×' . $info[1] : '?' ) . ')' );
	}
	$propia = static fn( $imagen, $id ) => 999999 === (int) $id ? array( 'https://ejemplo.invalid/medidas-propia.png', 900, 800, false ) : $imagen;
	add_filter( 'wp_get_attachment_image_src', $propia, 10, 2 );
	$cfg_p                                = $cfg_t;
	$cfg_p['modelos']['masculina']['guia'] = 999999;
	$ok( 'https://ejemplo.invalid/medidas-propia.png' === Config::guia_medidas( $cfg_p, 'masculina' ) && $f_url === Config::guia_medidas( $cfg_p, 'femenina' ), 'si el grupo sube su propia imagen, esa manda (y el otro modelo sigue con la de ASDE)' );
	remove_filter( 'wp_get_attachment_image_src', $propia, 10 );
	$ok( 1 === preg_match_all( '#<a [^>]*href="[^"]*medidas-camisa-femenina\.png"[^>]*data-asde-imagen[^>]*data-modelo="femenina"#', $h ) && 1 === preg_match_all( '#<a [^>]*href="[^"]*medidas-camisa-masculina\.png"[^>]*data-asde-imagen[^>]*data-modelo="masculina"#', $h ) && str_contains( $h, 'Tabla de medidas:' ), 'el formulario del pedido lleva un enlace a cada tabla de medidas (se abre encima del pedido, sin salir de la página)' );
	$ok( str_contains( $h, 'target="_blank"' ) && ! str_contains( $h, 'asde-guia">' ), 'sin JavaScript el enlace abre la imagen en otra pestaña (no un desplegable que ocupe el formulario)' );
	$js = (string) file_get_contents( ASDE_AP_DIR . 'assets/js/uniforme.js' );
	$ok( str_contains( $js, 'a[data-asde-imagen]' ) && str_contains( $js, 'showModal' ) && str_contains( $js, "'#asde-visor-titulo'" ) === false, 'uniforme.js abre esos enlaces en una ventana (dialog) encima del pedido' );
	$ok( str_contains( $h, 'Lo he recibido' ) === false && str_contains( $h, PedidosTable::numero( $p_leo ) ) && str_contains( $h, 'Recibido' ), 'sus pedidos, con los pasos (el de Leo, recibido)' );
	$como( $u_teso );
	$h = ( new UniformesGestionView() )->render();
	$ok( str_contains( $h, PedidosTable::numero( $p_pol ) ) && str_contains( $h, 'data-asde-modal>Validar</a>' ) && preg_match( '#<strong>(?:1</strong> pendiente|(?:0|[2-9]|[1-9]\d+)</strong> pendientes) de pago#', $h ), 'gestión de Tesorería: la lista con «Validar» (en la ventana) y los pendientes (en singular si es uno)' );
	View::en_modal( true );
	$_GET['pedido'] = (string) $p_pol['id'];
	$h              = ( new UniformesGestionView() )->render();
	unset( $_GET['pedido'] );
	View::en_modal( false );
	$ok( in_array( 'uniformes', Router::MODALES, true ) && str_contains( $h, 'asde-ap--modal' ) && str_contains( $h, 'Validar el pago' ) && str_contains( $h, 'name="metodo" value="ingreso"' ), 'detalle en la ventana emergente, con el formulario del pago (efectivo o ingreso)' );
	$como( $u_scouter );
	$ok( str_contains( ( new UniformesConfigView() )->render(), 'No tienes acceso' ), 'la configuración es de Tesorería y Coordinación' );
	$como( $u_coord );
	$enviar(
		new UniformesConfigView(),
		array(
			'asde_action'           => 'uniformes_config',
			'abierto'               => '1',
			'precio_camisa'         => '26,00',
			'texto_pago'            => 'En efectivo los sábados.',
			'modelo_nombre_masculina' => 'Camisa masculina',
			'modelo_tallas_masculina' => '6, 8, 10, 12, 14, 16, XS, S, M, L, XL, XXL, 3XL',
			'modelo_nombre_femenina'  => 'Camisa femenina',
			'modelo_tallas_femenina'  => '12, 14, 16, XS, S, M, L, XL, XXL, 3XL',
			'tallas_especiales'     => '3XL',
			'insignia_nombre_parche' => 'Parche de prueba',
			'insignia_lugar_parche'  => 'Brazo derecho',
			'insignia_precio_parche' => '2,50',
			'insignia_activa_parche' => '1',
			'insignia_nombre_mundial' => 'Mundial de prueba',
			'insignia_precio_mundial' => '3',
			'insignia_activa_mundial' => '1',
			'insignia_quitar_vieja'   => '1',
			'nueva_nombre_0'        => 'Insignia de sección',
			'nueva_precio_0'        => '',
		),
		'asde_uniformes_config'
	);
	$c = Config::de( $grupo['numero'] );
	$ok( 26.0 === $c['precio_camisa'] && 3.0 === $c['insignias']['mundial']['precio'] && ! isset( $c['insignias']['vieja'] ) && isset( $c['insignias']['insignia-de-seccion'] ) && null === $c['insignias']['insignia-de-seccion']['precio'] && 'En efectivo los sábados.' === $c['texto_pago'], 'Coordinación guarda la configuración: precio, precio de una insignia, quitar una, añadir otra (por confirmar) y el texto de cómo pagar' );
	$ok( 30.5 === Servicio::importe_a_cobrar( PedidosTable::obtener( $p_leo['id'] ) ), 'con el precio ya puesto, el importe propuesto de un pedido «por confirmar» lo incluye (27,50 + 3 €)' );
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
