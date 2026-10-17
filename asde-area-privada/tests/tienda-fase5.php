<?php
/**
 * Pruebas de la v0.40, fase 5 (entorno LOCAL, doc 48): pedidos de socios sin pagar (recordatorio y cancelación automática),
 * conciliación de la tienda con el banco (sugerencias y confirmar, siempre por Tesorería) e informes de ventas (en pantalla y en Excel).
 * Ejecutar desde app/public del site Local:
 *
 *   wp eval-file <ruta completa>/wp-content/plugins/asde-area-privada/tests/tienda-fase5.php
 *
 * No usa el CRM. Al terminar se borra lo creado (productos, pedidos, movimientos del banco, usuarios, registros) y se restaura la
 * configuración de la tienda.
 */
defined( 'ABSPATH' ) || exit;

require_once ABSPATH . 'wp-admin/includes/user.php';

use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Auth\Ambito;
use AsdeBalears\AreaPrivada\Domain\Grupo;
use AsdeBalears\AreaPrivada\Domain\Grupos;
use AsdeBalears\AreaPrivada\Frontend\Views\TiendaConfigView;
use AsdeBalears\AreaPrivada\Frontend\Views\TiendaGestionView;
use AsdeBalears\AreaPrivada\Install\Roles;
use AsdeBalears\AreaPrivada\Tesoreria\Banco\BancoTable;
use AsdeBalears\AreaPrivada\Tienda\Config;
use AsdeBalears\AreaPrivada\Tienda\ConciliacionBanco;
use AsdeBalears\AreaPrivada\Tienda\Informes;
use AsdeBalears\AreaPrivada\Tienda\PedidosTable;
use AsdeBalears\AreaPrivada\Tienda\ProductosTable;
use AsdeBalears\AreaPrivada\Tienda\Servicio;
use AsdeBalears\AreaPrivada\Tienda\VariantesTable;
use AsdeBalears\AreaPrivada\Usuarios\RegistroEmails;
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
foreach ( get_users( array( 'search' => 'prueba-f5-*', 'search_columns' => array( 'user_login' ) ) ) as $u ) {
	exit( 'Ya existe el usuario ' . $u->user_login . " (¿una ejecución anterior interrumpida?). Bórralo antes de repetir la prueba.\n" );
}
wp_set_current_user( 0 );
Grupo::forzar( $grupo );
PedidosTable::install_table(); // La columna de la fase 5 (el plugin no cambia de versión entre pruebas).
BancoTable::install_table();

// --- Lo que se restaura al final ---------------------------------------------
$max_id      = static fn( string $t ) => (int) $wpdb->get_var( "SELECT COALESCE(MAX(id), 0) FROM $t" ); // phpcs:ignore WordPress.DB
$log_antes   = $max_id( Logger::table() );
$ped_antes   = $max_id( PedidosTable::table() );
$prod_antes  = $max_id( ProductosTable::table() );
$var_antes   = $max_id( VariantesTable::table() );
$email_antes = $max_id( RegistroEmails::table() );
$mov_antes   = $max_id( BancoTable::table() );
$lote_antes  = $max_id( BancoTable::table_lotes() );
$opcion_cfg  = 'asde_ap_tienda_' . $grupo['numero'];
$cfg_antes   = get_option( $opcion_cfg, null );

add_filter( 'pre_wp_mail', '__return_true' );
$cache_externa = wp_using_ext_object_cache( true );
$destino       = '';
$redirigir     = static function ( $url ) use ( &$destino ) {
	$destino = (string) $url;
	throw new RuntimeException( 'asde-redirect:' . $url );
};
add_filter( 'wp_redirect', $redirigir, 1 );

$ids   = array();
$crear = static function ( string $login, array $roles ) use ( &$ids, $grupo ): int {
	$id = wp_insert_user( array( 'user_login' => $login, 'user_email' => '', 'user_pass' => wp_generate_password( 24 ), 'role' => $roles[0] ) );
	$u  = get_userdata( $id );
	foreach ( array_slice( $roles, 1 ) as $r ) {
		$u->add_role( $r );
	}
	update_user_meta( $id, Usuarios::META_GRUPO, $grupo['id'] );
	update_user_meta( $id, Usuarios::META_ESTADO, Usuarios::ACTIVO );
	$ids[ $login ] = $id;
	return $id;
};
$u_teso  = $crear( 'prueba-f5-teso', array( Roles::SCOUTER, Roles::TESORERIA ) );
$u_coord = $crear( 'prueba-f5-coord', array( Roles::SCOUTER, Roles::COORDINACION ) );
$u_sc    = $crear( 'prueba-f5-sc', array( Roles::SCOUTER ) );
$u_fam   = $crear( 'prueba-f5-fam', array( Roles::TUTOR ) );
$como    = static function ( int $uid ): void {
	wp_set_current_user( $uid );
	Ambito::olvidar( $uid );
};

$existencias = static fn( int $v ) => VariantesTable::obtener( $v )['existencias'];
$prod_id     = ProductosTable::crear(
	array(
		'nombre' => 'Taza fase 5', 'descripcion' => '', 'categoria' => 'Prueba fase 5', 'visibilidad' => 'todos', 'precio' => 10.0,
		'precio_socio' => 10.0, 'limite_persona' => 0, 'requiere_proveedor' => 0, 'sale_inventario' => 0, 'activo' => 1,
	)
);
$var_id = VariantesTable::crear( $prod_id, array( 'etiqueta' => 'Única', 'sku' => '', 'precio' => null, 'existencias' => 20, 'ilimitado' => 0 ) );

/** Un pedido de la tienda de un socio inventado, de hace N días, hecho por este usuario. */
$pedido = static function ( int $uid, string $socio, int $dias, string $forma, float $total, bool $reservar ) use ( $prod_id, $var_id, $wpdb, $como ): int {
	$como( $uid );
	if ( $reservar ) {
		VariantesTable::reservar( $var_id, 1 );
	}
	$lineas = array(
		'camisa'    => null,
		'insignias' => array(),
		'productos' => array( array( 'producto' => $prod_id, 'variante' => $var_id, 'nombre' => 'Taza fase 5', 'etiqueta' => 'Única', 'cantidad' => 1, 'precio' => $total, 'proveedor' => 0, 'reservado' => $reservar ) ),
	);
	$id = PedidosTable::crear( $socio, array( 'lobatos' ), false, $lineas, $total, false, '', $forma );
	$wpdb->update( PedidosTable::table(), array( 'creado' => gmdate( 'Y-m-d H:i:s', time() - $dias * DAY_IN_SECONDS ) ), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB
	return $id;
};
$emails = static fn() => array_values( array_filter( RegistroEmails::listado(), static fn( $f ) => (int) $f['id'] > $GLOBALS['email_antes'] && RegistroEmails::TIENDA === $f['tipo'] ) );
$GLOBALS['email_antes'] = $email_antes;
$cuenta_asunto          = static fn( string $texto ): int => count( array_filter( $emails(), static fn( $f ) => str_contains( (string) $f['asunto'], $texto ) ) );

$cfg                        = Config::defaults();
$cfg['iban']                = 'ES91 2100 0418 4502 0005 1332';
$cfg['bizum']               = '600123456';
$cfg['dias_aviso_socios']   = 7;
$cfg['dias_cancelar_socios'] = 0;
Config::guardar( $cfg, $grupo['numero'] );

try {
	echo "== Parte A · Configuración ==\n";
	$d = Config::defaults();
	$ok( 7 === $d['dias_aviso_socios'] && 0 === $d['dias_cancelar_socios'], 'por defecto: recordar a los 7 días y no cancelar nunca (nadie pierde un pedido sin avisar)' );
	$mala                         = $cfg;
	$mala['dias_aviso_socios']    = 500;
	$mala['dias_cancelar_socios'] = -3;
	Config::guardar( $mala, $grupo['numero'] );
	$leida = Config::de();
	$ok( 90 === $leida['dias_aviso_socios'] && 0 === $leida['dias_cancelar_socios'], 'los días se limitan (recordar hasta 90; cancelar de 0 a 180)' );
	Config::guardar( $cfg, $grupo['numero'] );
	$como( $u_coord );
	$h = ( new TiendaConfigView() )->render();
	$ok( str_contains( $h, 'name="dias_aviso_socios"' ) && str_contains( $h, 'name="dias_cancelar_socios"' ) && str_contains( $h, 'Pedidos sin pagar' ), 'la configuración enseña los dos plazos' );
	$_POST    = array( 'asde_action' => 'tienda_config', '_wpnonce' => wp_create_nonce( 'asde_tienda_admin' ), 'dias_aviso_socios' => '5', 'dias_cancelar_socios' => '30', 'abierto' => '1', 'precio_camisa' => '25', 'iban' => 'ES91 2100 0418 4502 0005 1332', 'bizum' => '600123456' );
	$_REQUEST = $_POST;
	try {
		( new TiendaConfigView() )->handle_post();
	} catch ( RuntimeException $e ) {
		// Redirige.
	}
	$_POST    = array();
	$_REQUEST = array();
	$guardada = Config::de();
	$ok( 5 === $guardada['dias_aviso_socios'] && 30 === $guardada['dias_cancelar_socios'], 'Coordinación guarda los plazos desde la pantalla' );
	Config::guardar( $cfg, $grupo['numero'] );

	echo "\n== Parte B · Recordatorio de los pedidos sin pagar ==\n";
	$a = $pedido( $u_fam, '990001', 8, 'bizum', 10.0, false );
	$b = $pedido( $u_fam, '990002', 20, 'transferencia', 10.0, true );
	$c = $pedido( $u_fam, '990003', 20, 'transferencia', 10.0, false );
	PedidosTable::marcar_pago( $c, wp_date( 'Y-m-d' ), 'transferencia', 10.0, 'x' );
	$d_ = $pedido( $u_fam, '990004', 20, '', 10.0, false ); // El formulario antiguo de uniforme: sin forma de pago.
	$e  = $pedido( $u_fam, '990005', 3, 'bizum', 10.0, false ); // Muy reciente.
	$f  = $pedido( $u_fam, '990006', 20, 'fisico', 0.0, false ); // No cuesta nada.
	$como( 0 );
	$ok( array( $a, $b ) === array_column( PedidosTable::socios_sin_pagar( 7, $grupo['numero'] ), 'id' ), 'los candidatos son los de la tienda sin pagar y con importe (no los pagados, los antiguos, los recientes ni los gratis)' );
	$r = Servicio::recordar_y_cancelar_socios();
	$ok( 2 === $r['recordados'] && 0 === $r['cancelados'], 'con «cancelar: 0» solo se recuerda (2 pedidos) y nada se cancela' );
	$ok( 2 === $cuenta_asunto( 'Recordatorio' ), 'salen dos emails de recordatorio' );
	$vista = '';
	foreach ( array_filter( $emails(), static fn( $x ) => str_contains( (string) $x['asunto'], 'Recordatorio' ) ) as $fila ) {
		$vista .= RegistroEmails::vista_previa( $fila );
	}
	$ok( str_contains( $vista, 'sigue sin pagar' ) && str_contains( $vista, '600123456' ) && str_contains( $vista, 'ES91 2100' ) && ! str_contains( $vista, 'se cancelará' ), 'el recordatorio lleva cómo pagar y, sin cancelación automática, no amenaza con cancelar' );
	$r = Servicio::recordar_y_cancelar_socios();
	$ok( 0 === $r['recordados'] && 2 === $cuenta_asunto( 'Recordatorio' ), 'al día siguiente no se repite: cada pedido recibe un solo recordatorio' );
	$ok( null === PedidosTable::por_id( $a )['cancelado_en'] && null === PedidosTable::por_id( $b )['cancelado_en'], 'y no se cancela nada' );

	echo "\n== Parte C · Cancelación automática ==\n";
	$cfg15                        = $cfg;
	$cfg15['dias_cancelar_socios'] = 15;
	Config::guardar( $cfg15, $grupo['numero'] );
	$antes = $existencias( $var_id );
	$r     = Servicio::recordar_y_cancelar_socios();
	$ok( 1 === $r['cancelados'] && null !== PedidosTable::por_id( $b )['cancelado_en'] && null === PedidosTable::por_id( $a )['cancelado_en'], 'con 15 días se cancela el de 20 días (el de 8, no)' );
	$ok( $antes + 1 === $existencias( $var_id ), 'lo que se había reservado vuelve a las existencias' );
	$ok( str_contains( (string) PedidosTable::por_id( $b )['cancelado_motivo'], '15 días' ), 'el motivo queda escrito' );
	$ok( 1 === $cuenta_asunto( 'Pedido cancelado' ), 'a quien lo pidió le llega el aviso de cancelación' );
	$ok( null === PedidosTable::por_id( $c )['cancelado_en'] && null === PedidosTable::por_id( $d_ )['cancelado_en'] && null === PedidosTable::por_id( $f )['cancelado_en'], 'el pagado, el del formulario antiguo y el gratuito no se tocan' );
	$r2 = Servicio::recordar_y_cancelar_socios();
	$ok( 0 === $r2['cancelados'] && $antes + 1 === $existencias( $var_id ), 'repetirlo no devuelve las existencias dos veces' );

	$mismo                        = $cfg;
	$mismo['dias_aviso_socios']   = 15;
	$mismo['dias_cancelar_socios'] = 15;
	Config::guardar( $mismo, $grupo['numero'] );
	$g = $pedido( $u_fam, '990007', 10, 'bizum', 10.0, false );
	$r = Servicio::recordar_y_cancelar_socios();
	$ok( 0 === $r['recordados'], 'si el recordatorio no llega antes que la cancelación, no se manda' );
	$wpdb->update( PedidosTable::table(), array( 'creado' => gmdate( 'Y-m-d H:i:s', time() - 12 * DAY_IN_SECONDS ) ), array( 'id' => $g ) ); // phpcs:ignore WordPress.DB
	$cfg_aviso                        = $cfg;
	$cfg_aviso['dias_aviso_socios']    = 10;
	$cfg_aviso['dias_cancelar_socios'] = 15;
	Config::guardar( $cfg_aviso, $grupo['numero'] );
	Servicio::recordar_y_cancelar_socios();
	$vista = RegistroEmails::vista_previa( array_values( array_filter( $emails(), static fn( $x ) => str_contains( (string) $x['asunto'], 'Recordatorio' ) ) )[0] ?? array() );
	$limite = wp_date( 'd/m/Y', time() - 12 * DAY_IN_SECONDS + 15 * DAY_IN_SECONDS );
	$ok( str_contains( $vista, 'se cancelará' ) && str_contains( $vista, $limite ), 'con cancelación automática, el recordatorio dice hasta qué día (' . $limite . ')' );
	Config::guardar( $cfg, $grupo['numero'] );
	Config::guardar( array_merge( $cfg, array( 'dias_aviso_socios' => 0 ) ), $grupo['numero'] );
	$h = $pedido( $u_fam, '990008', 30, 'bizum', 10.0, false );
	$r = Servicio::recordar_y_cancelar_socios();
	$ok( 0 === $r['recordados'] && 0 === $r['cancelados'] && null === PedidosTable::por_id( $h )['aviso_en'], 'con «0» en los dos plazos no se hace nada' );
	Config::guardar( $cfg, $grupo['numero'] );

	echo "\n== Parte D · Conciliación con el banco ==\n";
	$num_x = PedidosTable::numero( PedidosTable::por_id( $a ) );
	$num_y = PedidosTable::numero( PedidosTable::por_id( $g ) );
	$num_z = PedidosTable::numero( PedidosTable::por_id( $c ) ); // Ya pagado.
	$ok( 'U6840007' === ConciliacionBanco::referencia( 'Pago pedido U684-0007 gracias', '684' ) && 'U6840007' === ConciliacionBanco::referencia( 'bizum u 684 0007', '684' ) && null === ConciliacionBanco::referencia( 'pedido U999-0007', '684' ) && null === ConciliacionBanco::referencia( 'pedido XU6840007', '684' ), 'se reconoce el nº de pedido escrito de varias formas, y solo el del grupo' );
	$movs = array(
		array( 'fecha' => '2026-10-10', 'importe' => 10.0, 'concepto' => 'PRUEBA-F5 Bizum ' . $num_x . ' taza', 'ordenante' => 'Familia Inventada' ),
		array( 'fecha' => '2026-10-11', 'importe' => 8.0, 'concepto' => 'PRUEBA-F5 transferencia ' . $num_y ),
		array( 'fecha' => '2026-10-12', 'importe' => 10.0, 'concepto' => 'PRUEBA-F5 pago ' . $num_z . ' (ya pagado)' ),
		array( 'fecha' => '2026-10-13', 'importe' => 10.0, 'concepto' => 'PRUEBA-F5 sin ninguna referencia' ),
		array( 'fecha' => '2026-10-14', 'importe' => -10.0, 'concepto' => 'PRUEBA-F5 devolución ' . $num_x ),
	);
	$base = array( 'cuenta' => '9999 0001 ******0001', 'fecha_valor' => null, 'concepto_comun' => '', 'concepto_propio' => '', 'documento' => '', 'referencia1' => '', 'referencia2' => '', 'ordenante' => '' );
	$movs = BancoTable::clasificar( array_map( static fn( $m ) => $m + $base, $movs ) );
	$res  = BancoTable::importar( array( 'movimientos' => $movs, 'cuentas' => array( array( 'cuenta' => '9999 0001 ******0001', 'saldo_final' => null ) ) ), 'csv', 'prueba-f5.csv', hash( 'sha256', wp_json_encode( $movs ) ) );
	$ok( is_array( $res ) && 5 === $res['nuevos'], 'movimientos de prueba importados' );
	$mov = static function ( string $texto ) {
		foreach ( BancoTable::movimientos( array( 'q' => $texto ) ) as $m ) {
			return $m;
		}
		return null;
	};
	$todos = PedidosTable::todos();

	$como( $u_sc );
	$ok( array() === ConciliacionBanco::sugerencias( $todos ) && ! ConciliacionBanco::disponible(), 'un scouter sin permisos de Tesorería no ve nada del banco' );
	$ok( is_wp_error( ConciliacionBanco::confirmar( $a, (int) $mov( 'Bizum ' )['id'] ) ), 'ni puede confirmar un pago aunque conozca los números' );
	$como( $u_teso );
	$sug = ConciliacionBanco::sugerencias( $todos );
	$por = array();
	foreach ( $sug as $s ) {
		$por[ PedidosTable::numero( $s['pedido'] ) ] = $s;
	}
	$ok( 2 === count( $sug ) && isset( $por[ $num_x ], $por[ $num_y ] ), 'Tesorería ve 2 sugerencias: solo los ingresos con el nº de un pedido sin pagar (ni el ya pagado, ni el cargo, ni el que no cita nada)' );
	$ok( $por[ $num_x ]['coincide'] && ! $por[ $num_y ]['coincide'] && 10.0 === $por[ $num_y ]['a_cobrar'], 'se avisa cuando el importe del ingreso (8 €) no es el del pedido (10 €)' );
	$ok( array() === array_filter( $sug, static fn( $s ) => PedidosTable::numero( $s['pedido'] ) === $num_z ), 'el pedido ya pagado no se sugiere' );

	$h = ( new TiendaGestionView() )->render();
	$ok( str_contains( $h, 'parecen pagos de pedidos' ) && str_contains( $h, 'Marcar pagado con este ingreso' ) && str_contains( $h, $num_x ) && str_contains( $h, 'El pedido es de' ), 'la gestión enseña el cuadro, con el aviso del importe distinto' );
	$como( $u_coord );
	$ok( ! str_contains( ( new TiendaGestionView() )->render(), 'parecen pagos de pedidos' ), 'Coordinación (sin Tesorería) no ve el cuadro del banco' );
	$como( $u_teso );

	$m_x = $mov( 'Bizum ' . $num_x );
	// Un movimiento que no es el del pedido no vale, aunque se mande a mano.
	$m_otro = $mov( 'sin ninguna referencia' );
	$ok( is_wp_error( ConciliacionBanco::confirmar( $a, (int) $m_otro['id'] ) ) && null === PedidosTable::por_id( $a )['pago_en'], 'un ingreso que no cita ese pedido no lo marca pagado' );
	$m_cargo = $mov( 'devolución' );
	$ok( is_wp_error( ConciliacionBanco::confirmar( $a, (int) $m_cargo['id'] ) ), 'un cargo del banco tampoco' );
	$antes_pagados = $cuenta_asunto( 'Pago recibido' );
	$_POST    = array( 'asde_action' => 'tienda_banco_pagar', 'pedido' => (string) $a, 'movimiento' => (string) $m_x['id'], '_wpnonce' => wp_create_nonce( 'asde_tienda_banco_' . $a . '_' . $m_x['id'] ) );
	$_REQUEST = $_POST;
	try {
		( new TiendaGestionView() )->handle_post();
	} catch ( RuntimeException $e ) {
		// Redirige.
	}
	$_POST    = array();
	$_REQUEST = array();
	$pa = PedidosTable::por_id( $a );
	$ok( null !== $pa['pago_en'] && 'bizum' === $pa['pago_metodo'] && 10.0 === $pa['pago_importe'] && '2026-10-10' === $pa['pago_fecha'] && str_contains( $pa['pago_ref'], 'Banco' ), 'Tesorería confirma: el pedido queda pagado por Bizum con la fecha y el importe del ingreso' );
	$mx = BancoTable::obtener( (int) $m_x['id'] );
	$ok( BancoTable::IGNORADO === $mx['estado'] && str_contains( $mx['nota'], $num_x ), 'y el ingreso queda revisado, con una nota que dice de qué pedido es' );
	$ok( $cuenta_asunto( 'Pago recibido' ) === $antes_pagados + 1, 'a quien lo pidió le llega el aviso de pago recibido' );
	$ok( 1 === count( ConciliacionBanco::sugerencias( PedidosTable::todos() ) ), 'ya solo queda una sugerencia' );
	$_POST    = array( 'asde_action' => 'tienda_banco_pagar', 'pedido' => (string) $a, 'movimiento' => (string) $m_x['id'], '_wpnonce' => wp_create_nonce( 'asde_tienda_banco_' . $a . '_' . $m_x['id'] ) );
	$_REQUEST = $_POST;
	try {
		( new TiendaGestionView() )->handle_post();
	} catch ( RuntimeException $e ) {
		// Redirige.
	}
	$_POST    = array();
	$_REQUEST = array();
	$ok( $cuenta_asunto( 'Pago recibido' ) === $antes_pagados + 1, 'repetir la confirmación (doble clic) no marca ni avisa dos veces' );

	// Deshacer el pago devuelve el ingreso a «por conciliar».
	$_POST    = array( 'asde_action' => 'uniforme_deshacer_pago', 'pedido' => (string) $a, '_wpnonce' => wp_create_nonce( 'asde_uniforme_' . $a ), 'desde' => 'detalle' );
	$_REQUEST = $_POST;
	try {
		( new TiendaGestionView() )->handle_post();
	} catch ( RuntimeException $e ) {
		// Redirige.
	}
	$_POST    = array();
	$_REQUEST = array();
	$ok( null === PedidosTable::por_id( $a )['pago_en'] && BancoTable::PENDIENTE === BancoTable::obtener( (int) $m_x['id'] )['estado'], 'si se deshace el pago del pedido, el ingreso del banco vuelve a estar por conciliar' );
	$ok( 2 === count( ConciliacionBanco::sugerencias( PedidosTable::todos() ) ), 'y vuelve a sugerirse' );
	$como( 0 );

	echo "\n== Parte E · Informes de ventas ==\n";
	// Ventas del periodo: a (10 €, sin pagar tras deshacer), b cancelado, c pagado 10 €, d_ (10 €, sin forma), e (10 €), f (0 €), g (10 €), h (10 €).
	$todos = PedidosTable::todos();
	$mios  = array_values( array_filter( $todos, static fn( $p ) => $p['id'] > $ped_antes ) );
	$prod  = Informes::por_producto( $mios, Config::de() );
	$taza  = array_values( array_filter( $prod, static fn( $x ) => 'Taza fase 5' === $x['producto'] ) )[0] ?? null;
	$ok( $taza && 7 === $taza['unidades'] && 60.0 === $taza['importe'] && 10.0 === $taza['pagado'], 'por producto: 7 tazas vendidas (el pedido cancelado no cuenta), 60 € (uno es gratis) y 10 € de ellos pagados' );
	$formas = Informes::por_forma( $mios );
	$ok( isset( $formas['bizum'], $formas['transferencia'], $formas['fisico'], $formas[''] ), 'por forma de pago: aparecen Bizum, transferencia, en físico y «sin indicar»' );
	$ok( 10.0 === $formas['transferencia']['cobrado'] && 0.0 === $formas['transferencia']['pendiente'] && 1 === $formas['transferencia']['pedidos'], 'transferencia: el pedido pagado (el cancelado no cuenta)' );
	$ok( 4 === $formas['bizum']['pedidos'] && 0.0 === $formas['bizum']['cobrado'] && 40.0 === $formas['bizum']['pendiente'], 'Bizum: 4 pedidos, todo por cobrar' );
	$pend = Informes::pendientes( $mios );
	$ok( 5 === count( $pend ) && $pend[0]['dias'] >= $pend[ count( $pend ) - 1 ]['dias'], 'pendientes de cobro: los 5 sin pagar (con importe), el que más espera primero' );
	$ok( 0 === count( Informes::del_periodo( $mios, wp_date( 'Y-m-d', time() + 3 * DAY_IN_SECONDS ), '' ) ) && count( $mios ) === count( Informes::del_periodo( $mios, '', '' ) ), 'el periodo filtra por la fecha del pedido' );
	$ok( count( Informes::del_periodo( $mios, wp_date( 'Y-m-d', time() - 9 * DAY_IN_SECONDS ), '' ) ) < count( $mios ), 'y deja fuera los más antiguos' );

	$como( $u_teso );
	$_GET = array( 'informe' => '1' );
	$h    = ( new TiendaGestionView() )->render();
	$ok( str_contains( $h, 'Ventas por producto' ) && str_contains( $h, 'Ventas por forma de pago' ) && str_contains( $h, 'Pendientes de cobro' ) && str_contains( $h, 'Taza fase 5' ) && str_contains( $h, 'Descargar Excel' ), 'la pantalla «Ventas e informes» enseña los tres informes' );
	$_GET = array();
	$h    = ( new TiendaGestionView() )->render();
	$ok( str_contains( $h, 'informe=1' ) && str_contains( $h, 'Ventas e informes' ) && str_contains( $h, 'Pedidos de la tienda' ), 'la gestión enlaza a los informes y se llama «Pedidos de la tienda»' );
	$como( $u_sc );
	$_GET = array( 'informe' => '1' );
	$h    = ( new TiendaGestionView() )->render();
	$ok( ! str_contains( $h, 'Ventas por producto' ), 'un scouter sin permisos no ve los informes de ventas' );
	$_GET = array();
	$como( $u_teso );
	$xlsx = \AsdeBalears\AreaPrivada\Informes\Xlsx::generar( 'Ventas', array( 'Producto', 'Unidades' ), array( array( 'Taza', 3 ) ) );
	$ok( 'PK' === substr( $xlsx, 0, 2 ), 'el generador de Excel funciona con los datos del informe' );
	$como( 0 );
} finally {
	remove_filter( 'wp_redirect', $redirigir, 1 );
	remove_filter( 'pre_wp_mail', '__return_true' );
	$_POST = array();
	$_GET  = array();
	wp_using_ext_object_cache( $cache_externa );
	wp_set_current_user( 0 );

	$lista = implode( ',', array_map( 'intval', $ids ) ) ?: '0';
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . PedidosTable::table() . ' WHERE id > %d', $ped_antes ) ); // phpcs:ignore WordPress.DB
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . VariantesTable::table() . ' WHERE id > %d', $var_antes ) ); // phpcs:ignore WordPress.DB
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . ProductosTable::table() . ' WHERE id > %d', $prod_antes ) ); // phpcs:ignore WordPress.DB
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . RegistroEmails::table() . ' WHERE id > %d', $email_antes ) ); // phpcs:ignore WordPress.DB
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . BancoTable::table() . ' WHERE id > %d', $mov_antes ) ); // phpcs:ignore WordPress.DB
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . BancoTable::table_lotes() . ' WHERE id > %d', $lote_antes ) ); // phpcs:ignore WordPress.DB
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Logger::table() . " WHERE id > %d AND ( user_id IN ($lista) OR objeto IN ( 'uniforme', 'tienda', 'banco' ) )", $log_antes ) ); // phpcs:ignore WordPress.DB
	foreach ( $ids as $uid ) {
		Ambito::olvidar( (int) $uid );
		wp_delete_user( (int) $uid );
	}
	if ( null === $cfg_antes ) {
		delete_option( $opcion_cfg );
	} else {
		update_option( $opcion_cfg, $cfg_antes, false );
	}
	Grupo::forzar( null );

	$quedan = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users} WHERE user_login LIKE 'prueba-f5-%'" ); // phpcs:ignore WordPress.DB
	$ok( 0 === $quedan && 0 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . PedidosTable::table() . ' WHERE id > ' . (int) $ped_antes ) && 0 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . BancoTable::table() . ' WHERE id > ' . (int) $mov_antes ) && get_option( $opcion_cfg, null ) == $cfg_antes, 'limpieza: sin usuarios, pedidos ni movimientos de la prueba; configuración como estaba' ); // phpcs:ignore WordPress.DB
}

echo $fails ? "\n$fails FALLOS\n" : "\nTODO OK (nada tocado en el CRM real; el sitio queda como estaba)\n";
