<?php
/**
 * Pruebas de la v0.59 (entorno LOCAL): caja de efectivo y libro mayor (doc 61). Ejecutar desde app/public del site Local:
 *
 *   wp eval-file wp-content/plugins/asde-area-privada/tests/caja.php
 *
 * Todo en un grupo ficticio (999) sin CRM: cuotas, cobros y gastos de eventos, anticipos, pedidos de la tienda,
 * reintegros y movimientos del banco inventados, insertados en sus tablas y borrados al acabar por sus ids. El
 * ámbito del scouter (sin CRM) se prepara a mano. Se restauran los módulos del grupo y se borran los usuarios.
 */
defined( 'ABSPATH' ) || exit;

require_once ABSPATH . 'wp-admin/includes/user.php';

use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Auth\Ambito;
use AsdeBalears\AreaPrivada\Caja\Automaticos;
use AsdeBalears\AreaPrivada\Caja\CajaTable;
use AsdeBalears\AreaPrivada\Caja\Cajas;
use AsdeBalears\AreaPrivada\Caja\LibroMayor;
use AsdeBalears\AreaPrivada\Caja\Periodo;
use AsdeBalears\AreaPrivada\Caja\Servicio;
use AsdeBalears\AreaPrivada\Domain\Grupo;
use AsdeBalears\AreaPrivada\Domain\Modulos;
use AsdeBalears\AreaPrivada\Domain\Persona;
use AsdeBalears\AreaPrivada\Eventos\AnticiposTable;
use AsdeBalears\AreaPrivada\Eventos\GastosTable;
use AsdeBalears\AreaPrivada\Eventos\MovimientosTable as EventoMovimientos;
use AsdeBalears\AreaPrivada\Frontend\Notices;
use AsdeBalears\AreaPrivada\Frontend\Views\CajaView;
use AsdeBalears\AreaPrivada\Frontend\Views\LibroMayorView;
use AsdeBalears\AreaPrivada\Install\Roles;
use AsdeBalears\AreaPrivada\Reintegros\ReintegrosTable;
use AsdeBalears\AreaPrivada\Tesoreria\Banco\BancoTable;
use AsdeBalears\AreaPrivada\Tesoreria\Banco\TiposMovimiento;
use AsdeBalears\AreaPrivada\Tesoreria\FamiliasTable;
use AsdeBalears\AreaPrivada\Tesoreria\MovimientosTable;
use AsdeBalears\AreaPrivada\Tienda\PedidosTable;
use AsdeBalears\AreaPrivada\Usuarios\Usuarios;

global $wpdb;

$fails = 0;
$ok    = function ( $cond, string $label ) use ( &$fails ) {
	if ( ! $cond ) {
		++$fails;
	}
	echo ( $cond ? 'OK   ' : 'FAIL ' ), $label, "\n";
};
$igual = static fn( $a, $b ) => abs( (float) $a - (float) $b ) < 0.001;

foreach ( get_users( array( 'search' => 'prueba-caja-*', 'search_columns' => array( 'user_login' ) ) ) as $u ) {
	exit( 'Ya existe el usuario ' . $u->user_login . " (¿una ejecución anterior interrumpida?). Bórralo antes de repetir la prueba.\n" );
}
foreach ( array( CajaTable::table(), BancoTable::table(), FamiliasTable::table(), ReintegrosTable::table(), PedidosTable::table(), GastosTable::table(), AnticiposTable::table(), EventoMovimientos::table() ) as $t ) {
	if ( (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE grupo = '999'", $t ) ) ) { // phpcs:ignore WordPress.DB
		exit( "Quedan datos del grupo de prueba 999 en $t (¿una ejecución anterior interrumpida?).\n" );
	}
}

$max_id        = static fn( string $t ) => (int) $wpdb->get_var( "SELECT COALESCE(MAX(id), 0) FROM $t" ); // phpcs:ignore WordPress.DB
$log_antes     = $max_id( Logger::table() );
$mov_antes     = $max_id( MovimientosTable::table() );
$modulos_antes = get_option( 'asde_ap_modulos', null );
$cache_externa = wp_using_ext_object_cache( true );
$cortar_red    = static fn( $pre, $args, $url ) => ( false === $pre && str_contains( (string) $url, '/rest.php' ) ) ? new WP_Error( 'prueba', 'CRM cortado durante la prueba' ) : $pre;
add_filter( 'pre_http_request', $cortar_red, 2, 3 );
Grupo::forzar(
	array(
		'id'     => '999',
		'numero' => '999',
		'nombre' => 'Grupo de prueba 999',
		'crm_id' => '',
	)
);
$modulos          = is_array( $modulos_antes ) ? $modulos_antes : array();
$modulos['999']   = array(
	'caja'       => true,
	'eventos'    => true,
	'reintegros' => true,
);
update_option( 'asde_ap_modulos', $modulos, false );
Modulos::olvidar();

$ids   = array();
$crear = static function ( string $login, string $rol ) use ( &$ids ): int {
	$id = (int) wp_insert_user(
		array(
			'user_login' => $login,
			'user_pass'  => wp_generate_password( 24 ),
			'role'       => $rol,
		)
	);
	update_user_meta( $id, Usuarios::META_GRUPO, '999' );
	update_user_meta( $id, Usuarios::META_ESTADO, Usuarios::ACTIVO );
	$ids[ $login ] = $id;
	return $id;
};
$u_teso    = $crear( 'prueba-caja-teso', Roles::TESORERIA );
$u_scouter = $crear( 'prueba-caja-scouter', Roles::SCOUTER );
add_user_meta( $u_scouter, Usuarios::META_SECCION, 'lobatos' );
// Ámbito sin CRM: la ficha del scouter, con membresía activa, preparada a mano.
$preparar = static function ( int $uid ): void {
	$r = new ReflectionClass( Ambito::class );
	$a = $r->newInstanceWithoutConstructor();
	$r->getProperty( 'user' )->setValue( $a, get_userdata( $uid ) );
	$a->persona = Persona::from_crm( array( 'id' => wp_generate_uuid4(), 'fields' => array( 'numerosocio_c' => '999999', 'first_name' => 'Prueba', 'stic_relationship_type_c' => '^Scouters^,^Lobatos^' ) ) );
	$c = $r->getProperty( 'cache' );
	$c->setAccessible( true );
	$c->setValue( null, array( $uid => $a ) + (array) $c->getValue() );
};
$como = static function ( int $uid ) use ( $preparar ): void {
	wp_set_current_user( $uid );
	Ambito::olvidar( $uid );
	$preparar( $uid );
};
add_filter( 'send_auth_cookies', '__return_false' );
$redirigir = static function ( $destino ) {
	throw new RuntimeException( 'asde-redirect:' . $destino );
};
add_filter( 'wp_redirect', $redirigir, 1 );
$morir = static fn() => static function ( $mensaje ) {
	throw new RuntimeException( 'asde-die:' . ( is_wp_error( $mensaje ) ? $mensaje->get_error_message() : (string) $mensaje ) );
};
add_filter( 'wp_die_handler', $morir, PHP_INT_MAX );
$enviar = static function ( $vista, array $campos ): array {
	$_POST    = $campos + array( '_wpnonce' => wp_create_nonce( 'asde_caja' ) );
	$_REQUEST = $_POST;
	$destino  = '';
	try {
		$vista->handle_post();
	} catch ( RuntimeException $e ) {
		$destino = $e->getMessage();
	} finally {
		$_POST    = array();
		$_REQUEST = array();
	}
	return array( Notices::render(), $destino );
};
$ver = static function ( $vista, array $get = array() ): string {
	$_GET = $get;
	try {
		return $vista->render();
	} finally {
		$_GET = array();
	}
};
$insertados = array();
$insertar   = static function ( string $tabla, array $fila ) use ( $wpdb, &$insertados ): int {
	$wpdb->insert( $tabla, $fila + array( 'creado' => current_time( 'mysql', true ) ) );
	$insertados[] = array( $tabla, (int) $wpdb->insert_id );
	return (int) $wpdb->insert_id;
};
$tipo_gasto = 0;

try {
	echo "== A · Cajas y periodos ==\n";
	$ok( array( 'grupo', 'castores', 'lobatos', 'scouts', 'escultas', 'rovers' ) === Cajas::todas(), 'la caja del grupo y una por sección educativa, desde el principio (P-220)' );
	$ok( 'Caja del grupo' === Cajas::nombre( 'grupo' ) && str_contains( Cajas::nombre( 'lobatos' ), 'Lobatos' ), 'nombres de las cajas' );
	$p = Periodo::de( 'r2025-2026' );
	$ok( '2025-09-01' === $p['desde'] && '2026-08-31' === $p['hasta'] && 'r2025-2026' === $p['clave'], 'una ronda va de septiembre a agosto (P-207)' );
	$p = Periodo::de( 'a2026' );
	$ok( '2026-01-01' === $p['desde'] && '2026-12-31' === $p['hasta'], 'un año natural, de enero a diciembre' );
	$ok( 'r' . \AsdeBalears\AreaPrivada\Tesoreria\Config::ronda_actual() === Periodo::de( 'xx' )['clave'], 'un periodo raro: la ronda actual' );
	$op = Periodo::opciones();
	$ok( isset( $op[ 'r' . \AsdeBalears\AreaPrivada\Tesoreria\Config::ronda_actual() ], $op[ 'a' . wp_date( 'Y' ) ] ), 'el desplegable tiene las rondas y los años naturales' );

	echo "\n== B · Quién ve qué caja ==\n";
	$como( $u_teso );
	$ok( Cajas::todas() === Cajas::visibles() && Cajas::todas_suyas(), 'Tesorería ve y lleva todas las cajas y el libro mayor' );
	$como( $u_scouter );
	$ok( array( 'lobatos' ) === Cajas::visibles() && ! Cajas::puede( 'grupo' ) && ! Cajas::todas_suyas(), 'el scouter de Lobatos solo ve la caja de Lobatos (P-210c)' );
	$como( $u_teso );
	$modulos['999']['caja'] = false;
	update_option( 'asde_ap_modulos', $modulos, false );
	Modulos::olvidar();
	$como( $u_teso );
	$ok( array() === Cajas::visibles() && ! Cajas::todas_suyas(), 'con el módulo apagado no ve ninguna (nace apagado, regla 5)' );
	$modulos['999']['caja'] = true;
	update_option( 'asde_ap_modulos', $modulos, false );
	Modulos::olvidar();
	$como( $u_teso );

	echo "\n== C · Saldo inicial y apuntes a mano ==\n";
	$ok( is_wp_error( Servicio::apuntar( 'grupo', '2099-01-01', 'salida', 5, 0, 'x' ) ), 'nada con fecha futura' );
	$ini = Servicio::poner_inicial( 'grupo', '2026-01-10', 50 );
	$ok( is_int( $ini ) && $ini > 0, 'saldo inicial de la caja del grupo: 50 € el 10/01/2026 (P-208)' );
	$ok( is_wp_error( Servicio::poner_inicial( 'grupo', '2026-01-11', 60 ) ), 'solo una vez (para cambiarlo, se anula)' );
	$ok( is_wp_error( Servicio::poner_inicial( 'castores', '2026-01-11', -5 ) ), 'una caja no empieza en negativo' );
	$ok( is_wp_error( Servicio::apuntar( 'grupo', '2026-01-05', 'salida', 5, 0, 'Antes' ) ), 'nada antes del saldo inicial (no contaría)' );
	$a1 = Servicio::apuntar( 'grupo', '2026-02-01', 'salida', 10, 0, 'Sellos prueba-caja' );
	$a2 = Servicio::apuntar( 'grupo', '2026-02-02', 'entrada', 20.5, 0, 'Donativo en mano', 'nota de prueba' );
	$ok( is_int( $a1 ) && is_int( $a2 ), 'una salida (10 €) y una entrada (20,50 €)' );
	$ok( is_wp_error( Servicio::apuntar( 'grupo', '2026-02-01', 'salida', 0, 0, 'x' ) ) && is_wp_error( Servicio::apuntar( 'grupo', '2026-02-01', 'salida', 3, 0, '  ' ) ) && is_wp_error( Servicio::apuntar( 'grupo', '2026-02-01', 'otro', 3, 0, 'x' ) ), 'sin importe, sin concepto o sin sentido: no' );
	$tipo_gasto = (int) TiposMovimiento::crear( 'Prueba caja gasto', TiposMovimiento::GASTO );
	$ok( is_wp_error( Servicio::apuntar( 'grupo', '2026-02-03', 'entrada', 3, $tipo_gasto, 'x' ) ), 'un tipo de gasto no vale para una entrada' );
	$a3 = Servicio::apuntar( 'grupo', '2026-02-03', 'salida', 4, $tipo_gasto, 'Pilas' );
	$ok( is_int( $a3 ), 'y sí para una salida' );
	$crudo = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT concepto_enc FROM %i WHERE id = %d', CajaTable::table(), $a2 ) ); // phpcs:ignore WordPress.DB
	$ok( '' !== $crudo && ! str_contains( $crudo, 'Donativo' ) && 'Donativo en mano' === CajaTable::obtener( $a2 )['concepto'], 'el concepto se guarda cifrado' );
	$ok( $igual( 56.5, Servicio::saldo( 'grupo', '2026-12-31' ) ), 'saldo: 50 − 10 + 20,50 − 4 = 56,50 €' );
	$e = Servicio::extracto( 'grupo', Periodo::de( 'a2026' ) );
	$ok( $igual( 0, $e['inicial'] ) && $igual( 20.5, $e['entradas'] ) && $igual( 14, $e['salidas'] ) && $igual( 56.5, $e['final'] ) && 4 === count( $e['filas'] ), 'extracto de 2026: empieza en 0, el saldo inicial es la primera fila y no cuenta como entrada' );
	$ok( $igual( 50, $e['filas'][0]['saldo'] ) && $igual( 40, $e['filas'][1]['saldo'] ) && $igual( 56.5, $e['filas'][3]['saldo'] ), 'saldo acumulado en cada fila' );
	$e27 = Servicio::extracto( 'grupo', Periodo::de( 'a2027' ) );
	$ok( $igual( 56.5, $e27['inicial'] ) && ! $e27['filas'], 'el periodo siguiente empieza con lo que había (se arrastra)' );

	echo "\n== D · Traspasos entre cajas ==\n";
	$t1 = Servicio::traspasar( 'grupo', 'lobatos', '2026-03-01', 15, 'Para la salida' );
	$ok( is_int( $t1 ) && $igual( 41.5, Servicio::saldo( 'grupo', '2026-12-31' ) ) && $igual( 15, Servicio::saldo( 'lobatos', '2026-12-31' ) ), 'del grupo a Lobatos 15 €: sale de una y entra en la otra' );
	$par = CajaTable::obtener( (int) CajaTable::obtener( $t1 )['par_id'] );
	$ok( $par && 'lobatos' === $par['caja'] && $t1 === $par['par_id'], 'los dos apuntes quedan enlazados' );
	$ok( is_wp_error( Servicio::traspasar( 'grupo', 'lobatos', '2026-03-02', 999, '' ) ), 'no se traspasa más de lo que hay' );
	$ok( is_wp_error( Servicio::traspasar( 'grupo', 'grupo', '2026-03-02', 1, '' ) ), 'ni a la misma caja' );
	$como( $u_scouter );
	$ok( is_wp_error( Servicio::apuntar( 'grupo', '2026-03-02', 'salida', 1, 0, 'x' ) ), 'el scouter no apunta en la caja del grupo' );
	$s1 = Servicio::apuntar( 'lobatos', '2026-03-03', 'salida', 6, 0, 'Material de la salida' );
	$ok( is_int( $s1 ) && $igual( 9, Servicio::saldo( 'lobatos', '2026-12-31' ) ), 'pero sí en la de su sección' );
	$ok( is_wp_error( Servicio::traspasar( 'lobatos', CajaTable::BANCO, '2026-03-04', 2, '' ) ), 'los traspasos con el banco no los hace un scouter (P-219)' );
	$t2 = Servicio::traspasar( 'lobatos', 'grupo', '2026-03-04', 4, 'Sobrante' );
	$ok( is_int( $t2 ) && $igual( 5, Servicio::saldo( 'lobatos', '2026-12-31' ) ) && $igual( 45.5, Servicio::saldo( 'grupo', '2026-12-31' ) ), 'devuelve 4 € a la caja del grupo' );
	$como( $u_teso );
	$ok( is_wp_error( Servicio::anular( $t2, '' ) ), 'anular pide motivo' );
	$ok( true === Servicio::anular( $t2, 'Prueba' ) && $igual( 9, Servicio::saldo( 'lobatos', '2026-12-31' ) ) && $igual( 41.5, Servicio::saldo( 'grupo', '2026-12-31' ) ), 'anular un traspaso anula los dos apuntes' );
	$ok( is_wp_error( Servicio::anular( $t2, 'Otra vez' ) ), 'lo anulado no se vuelve a anular' );

	echo "\n== E · Arqueo ==\n";
	$hoy = current_time( 'Y-m-d' );
	$saldo_hoy = Servicio::saldo( 'grupo', $hoy );
	$q1 = Servicio::arquear( 'grupo', $hoy, $saldo_hoy, '' );
	$ok( is_array( $q1 ) && $igual( 0, $q1['diferencia'] ), 'si cuadra, se guarda sin motivo (P-211)' );
	$q2 = Servicio::arquear( 'grupo', $hoy, $saldo_hoy - 2, '' );
	$ok( is_wp_error( $q2 ) && 'asde_caja_motivo' === $q2->get_error_code(), 'si no cuadra, pide el motivo' );
	$q3 = Servicio::arquear( 'grupo', $hoy, $saldo_hoy - 2, 'Faltan 2 € del cambio' );
	$ok( is_array( $q3 ) && $igual( -2, $q3['diferencia'] ) && $igual( $saldo_hoy - 2, Servicio::saldo( 'grupo', $hoy ) ), 'con motivo, la diferencia queda como ajuste y el saldo es lo contado' );
	$ok( 'Diferencias de arqueo' === LibroMayor::partida_caja( CajaTable::obtener( $q3['id'] ), array() ), 'y su partida es «Diferencias de arqueo»' );
	Servicio::anular( $q3['id'], 'Prueba' );
	Servicio::anular( $q1['id'], 'Prueba' );

	echo "\n== F · Lo cobrado y pagado en efectivo en los demás módulos (en vivo, P-209/P-212) ==\n";
	$fam = $insertar( FamiliasTable::table(), array( 'numero' => 'F999901', 'grupo' => '999', 'actualizado' => current_time( 'mysql', true ) ) );
	$cuota = $insertar( MovimientosTable::table(), array( 'familia_id' => $fam, 'ronda' => '2025-2026', 'tipo' => MovimientosTable::PAGO, 'fecha' => '2026-04-01', 'importe' => 30, 'metodo' => 'efectivo' ) );
	$insertar( MovimientosTable::table(), array( 'familia_id' => $fam, 'ronda' => '2025-2026', 'tipo' => MovimientosTable::PAGO, 'fecha' => '2026-04-01', 'importe' => 70, 'metodo' => 'transferencia' ) );
	$insertar( MovimientosTable::table(), array( 'familia_id' => $fam, 'ronda' => '2025-2026', 'tipo' => MovimientosTable::PAGO, 'fecha' => '2026-04-01', 'importe' => 99, 'metodo' => 'efectivo', 'anulado_en' => current_time( 'mysql', true ) ) );
	$insertar( EventoMovimientos::table(), array( 'evento_id' => 0, 'grupo' => '999', 'inscripcion_id' => 0, 'tipo' => EventoMovimientos::PAGO, 'fecha' => '2026-04-02', 'importe' => 25, 'forma' => 'efectivo' ) );
	$insertar( EventoMovimientos::table(), array( 'evento_id' => 0, 'grupo' => '999', 'inscripcion_id' => 0, 'tipo' => EventoMovimientos::DEVOLUCION, 'fecha' => '2026-04-03', 'importe' => 5, 'forma' => 'efectivo' ) );
	$insertar( EventoMovimientos::table(), array( 'evento_id' => 0, 'grupo' => '999', 'inscripcion_id' => 0, 'tipo' => EventoMovimientos::PAGO, 'fecha' => '2026-04-02', 'importe' => 40, 'forma' => 'bizum' ) );
	$gasto = array( 'evento_id' => 0, 'grupo' => '999', 'tipo' => GastosTable::GASTO, 'fecha' => '2026-04-04', 'concepto' => 'Pan', 'importe' => 8, 'forma' => 'efectivo', 'pagado_por' => GastosTable::PAGO_GRUPO, 'estado' => GastosTable::VALIDADO );
	$insertar( GastosTable::table(), $gasto );
	$insertar( GastosTable::table(), array( 'importe' => 9, 'forma' => 'tarjeta' ) + $gasto );
	$insertar( GastosTable::table(), array( 'importe' => 11, 'estado' => GastosTable::PROPUESTO ) + $gasto );
	$insertar( GastosTable::table(), array( 'tipo' => GastosTable::INGRESO, 'concepto' => 'Rifa', 'importe' => 14 ) + $gasto );
	$insertar( GastosTable::table(), array( 'pagado_por' => GastosTable::PAGO_PERSONA, 'forma' => 'otro', 'importe' => 6, 'reembolsado_en' => '2026-04-06', 'reembolso_forma' => 'efectivo' ) + $gasto );
	$del_reintegro = $insertar( GastosTable::table(), array( 'pagado_por' => GastosTable::PAGO_PERSONA, 'forma' => 'otro', 'importe' => 12, 'reembolsado_en' => '2026-04-07', 'reembolso_forma' => 'efectivo' ) + $gasto );
	$insertar( AnticiposTable::table(), array( 'evento_id' => 0, 'grupo' => '999', 'persona' => $u_teso, 'importe' => 40, 'fecha' => '2026-04-05', 'forma' => 'efectivo', 'estado' => AnticiposTable::LIQUIDADO, 'devuelto' => 10, 'liquidado_en' => '2026-04-10 10:00:00' ) );
	$insertar( PedidosTable::table(), array( 'grupo' => '999', 'socio' => '999999', 'lineas' => '{}', 'total' => 25, 'pago_fecha' => '2026-04-08', 'pago_metodo' => 'efectivo', 'pago_importe' => 25 ) );
	$insertar( PedidosTable::table(), array( 'grupo' => '999', 'socio' => '999999', 'lineas' => '{}', 'total' => 30, 'pago_fecha' => '2026-04-08', 'pago_metodo' => 'efectivo', 'pago_importe' => 30, 'cancelado_en' => current_time( 'mysql', true ) ) );
	$insertar( ReintegrosTable::table(), array( 'grupo' => '999', 'solicitante' => $u_scouter, 'concepto' => 'Gasolina', 'fecha' => '2026-04-01', 'importe' => 12, 'forma' => ReintegrosTable::EFECTIVO, 'estado' => ReintegrosTable::PAGADO, 'pago_fecha' => '2026-04-07', 'gasto_id' => $del_reintegro ) );
	$insertar( ReintegrosTable::table(), array( 'grupo' => '999', 'solicitante' => $u_scouter, 'concepto' => 'Peajes', 'fecha' => '2026-04-01', 'importe' => 7, 'forma' => ReintegrosTable::TRANSFERENCIA, 'estado' => ReintegrosTable::PAGADO, 'pago_fecha' => '2026-04-07' ) );

	$auto   = Automaticos::apuntes( '2026-01-01', '2026-12-31' );
	$por    = static function ( string $origen ) use ( $auto ): array {
		return array_values( array_filter( $auto, static fn( $a ) => $origen === $a['origen'] ) );
	};
	$suma   = static fn( array $l ) => round( array_sum( array_column( $l, 'importe' ) ), 2 );
	$ok( 1 === count( $por( 'cuota' ) ) && $igual( 30, $suma( $por( 'cuota' ) ) ) && $cuota === $por( 'cuota' )[0]['origen_id'] && 'tesoreria' === $por( 'cuota' )[0]['pagina'], 'cuotas: solo la pagada en efectivo y sin anular (+30), con enlace a la familia' );
	$ok( $igual( 25, $suma( $por( 'evento_cobro' ) ) ) && $igual( -5, $suma( $por( 'evento_devol' ) ) ), 'eventos: el cobro en efectivo (+25) y la devolución en efectivo (−5); el Bizum no' );
	$ok( $igual( -8, $suma( $por( 'evento_gasto' ) ) ) && $igual( 14, $suma( $por( 'evento_ingreso' ) ) ), 'gastos de eventos: el validado que pagó el grupo en efectivo (−8, no el de tarjeta ni el propuesto, P-214) y el ingreso en efectivo (+14)' );
	$ok( $igual( -6, $suma( $por( 'reembolso' ) ) ) && '2026-04-06' === $por( 'reembolso' )[0]['fecha'], 'reembolso en efectivo, con su fecha (−6); el gasto que creó un reintegro no se cuenta dos veces' );
	$ok( $igual( -30, $suma( $por( 'anticipo' ) ) ) && 2 === count( $por( 'anticipo' ) ), 'anticipo de caja: sale al darlo (−40) y vuelve lo devuelto al liquidarlo (+10)' );
	$ok( $igual( 25, $suma( $por( 'tienda' ) ) ), 'tienda: el pedido pagado en efectivo (+25), no el cancelado' );
	$ok( $igual( -12, $suma( $por( 'reintegro' ) ) ) && '2026-04-07' === $por( 'reintegro' )[0]['fecha'], 'reintegros: el pagado en efectivo, con su fecha de pago (−12, P-213); el de transferencia no' );
	$ok( $igual( 41.5 + 30 + 25 - 5 - 8 + 14 - 6 - 30 + 25 - 12, Servicio::saldo( 'grupo', '2026-12-31' ) ), 'todo eso entra solo en la caja del grupo (P-210b)' );
	$ok( $igual( 9, Servicio::saldo( 'lobatos', '2026-12-31' ) ), 'y no en la de una sección' );
	$wpdb->update( MovimientosTable::table(), array( 'anulado_en' => current_time( 'mysql', true ) ), array( 'id' => $cuota ) );
	$ok( ! $por( 'cuota' ) || ! array_filter( Automaticos::apuntes( '2026-01-01', '2026-12-31' ), static fn( $a ) => 'cuota' === $a['origen'] ), 'si se anula la cuota en Tesorería, desaparece de la caja (en vivo)' );
	$wpdb->update( MovimientosTable::table(), array( 'anulado_en' => null ), array( 'id' => $cuota ) );

	echo "\n== G · Traspaso con el banco y libro mayor ==\n";
	Servicio::apuntar( 'grupo', '2026-05-01', 'entrada', 200, 0, 'Lotería prueba-caja' );
	$tb = Servicio::traspasar( 'grupo', CajaTable::BANCO, '2026-05-02', 100, 'Ingreso en el banco' );
	$ok( is_int( $tb ) && CajaTable::BANCO === CajaTable::obtener( $tb )['destino'], 'ingresar 100 € en el banco: traspaso de la caja' );
	$bm  = $insertar( BancoTable::table(), array( 'grupo' => '999', 'lote_id' => 0, 'cuenta' => 'PRUEBA-CAJA', 'fecha' => '2026-05-03', 'importe' => 100, 'huella' => hash( 'sha256', wp_generate_uuid4() ), 'estado' => BancoTable::PENDIENTE ) );
	$bm2 = $insertar( BancoTable::table(), array( 'grupo' => '999', 'lote_id' => 0, 'cuenta' => 'PRUEBA-CAJA', 'fecha' => '2026-05-04', 'importe' => -30, 'huella' => hash( 'sha256', wp_generate_uuid4() ), 'estado' => BancoTable::IGNORADO, 'tipo_id' => $tipo_gasto ) );
	$cand = Servicio::candidatos_banco( CajaTable::obtener( $tb ) );
	$ok( $cand && $bm === (int) $cand[0]['id'] && $cand[0]['igual'], 'para asociarlo, primero sale el ingreso de 100 € del banco' );
	$ok( true === Servicio::asociar_banco( $tb, $bm ) && $bm === CajaTable::obtener( $tb )['banco_id'], 'se asocia con su movimiento del banco (P-219)' );
	$tb2 = Servicio::traspasar( 'grupo', CajaTable::BANCO, '2026-05-02', 100, 'Otro' );
	$ok( is_wp_error( Servicio::asociar_banco( $tb2, $bm ) ), 'ese movimiento del banco ya no se asocia con otro traspaso' );
	$ok( is_wp_error( Servicio::asociar_banco( $tb2, $bm2 ) ), 'ni uno del mismo signo que el traspaso' );
	Servicio::anular( $tb2, 'Prueba' );
	$ok( isset( CajaTable::por_banco( array( $bm ) )[ $bm ] ), 'el banco sabe qué movimiento es un traspaso de caja' );
	$ok( is_wp_error( Servicio::asociar_banco( (int) $a1, $bm ) ), 'un apunte que no es traspaso con el banco no se asocia' );

	$ok( is_int( Servicio::poner_inicial( CajaTable::BANCO, '2026-01-01', 1000 ) ), 'saldo inicial del banco: 1.000 € el 01/01/2026' );
	$libro = LibroMayor::calcular( Periodo::de( 'a2026' ) );
	$b     = $libro['cuentas'][ CajaTable::BANCO ] ?? null;
	$ok( $b && $igual( 1000, $b['inicial'] ) && $igual( 1070, $b['final'] ) && 2 === count( $b['filas'] ), 'libro mayor · banco: 1.000 + 100 − 30 = 1.070 €' );
	$ok( $b && LibroMayor::TRASPASOS === $b['filas'][0]['partida'] && 'Prueba caja gasto' === $b['filas'][1]['partida'], 'el ingreso del traspaso es «Traspasos»; el gasto revisado, su tipo' );
	$ok( isset( $libro['cuentas']['grupo'], $libro['cuentas']['lobatos'] ) && ! isset( $libro['cuentas']['castores'] ), 'salen las cajas con algo (la de una sección vacía, no)' );
	$r = $libro['resumen'];
	$ok( ! isset( $r[ LibroMayor::TRASPASOS ] ) && $igual( 30, $r['Cuotas en efectivo']['entradas'] ?? 0 ) && $igual( 12, $r['Reintegros']['salidas'] ?? 0 ) && $igual( 34, $r['Prueba caja gasto']['salidas'] ?? 0 ), 'resumen por partida: sin traspasos; cuotas en efectivo, reintegros y el tipo de gasto (4 € de la caja + 30 € del banco)' );
	// El saldo inicial de la caja del grupo (50 €) cae dentro del periodo: cuenta en la cuenta, no en el resumen.
	$neto_cuentas = array_sum( array_map( static fn( $c ) => $c['final'] - $c['inicial'], $libro['cuentas'] ) ) - 50;
	$neto_resumen = array_sum( array_map( static fn( $x ) => $x['entradas'] - $x['salidas'], $r ) );
	$ok( $igual( $neto_cuentas, $neto_resumen ), 'cuadra: lo que varían las cuentas (sin los saldos iniciales) es el neto del resumen, porque los traspasos se compensan' );

	echo "\n== H · Pantallas, formularios y Excel ==\n";
	$h = $ver( new CajaView(), array( 'caja' => 'grupo', 'periodo' => 'a2026' ) );
	$ok( str_contains( $h, 'Caja del grupo' ) && str_contains( $h, 'Cuota de la familia F999901' ) && str_contains( $h, 'Asociar con el banco' ) === false && str_contains( $h, 'Banco:' ) && str_contains( $h, 'Guardar el arqueo' ), 'la caja del grupo: apuntes automáticos, el traspaso ya asociado y el arqueo' );
	$ok( str_contains( $h, 'name="hacia"' ) && str_contains( $h, 'Ingresarlo en el banco' ), 'Tesorería puede traspasar al banco' );
	list( $avisos, $destino ) = $enviar(
		new CajaView(),
		array(
			'asde_action' => 'apuntar',
			'caja'        => 'grupo',
			'periodo'     => 'a2026',
			'fecha'       => '2026-06-01',
			'sentido'     => 'salida',
			'importe'     => '3,50',
			'tipo'        => '0',
			'concepto'    => 'Desde el formulario',
		)
	);
	$ok( str_contains( $destino, 'asde-redirect:' ) && str_contains( $avisos, 'Apunte guardado' ), 'el formulario «Apuntar» guarda (con coma decimal) y vuelve a la caja' );
	$como( $u_scouter );
	$hs = $ver( new CajaView(), array( 'caja' => 'grupo' ) );
	$ok( str_contains( $hs, 'Caja de Lobatos' ) && ! str_contains( $hs, 'Caja del grupo</h2>' ) && ! str_contains( $hs, 'Ingresarlo en el banco' ), 'el scouter que pide la del grupo ve la suya y no puede traspasar al banco' );
	$ok( str_contains( $ver( new LibroMayorView() ), 'El libro mayor lo ven' ) && ! str_contains( $ver( new LibroMayorView() ), 'Resumen por partida' ), 'el scouter no ve el libro mayor' );
	$como( $u_teso );
	$hl = $ver( new LibroMayorView(), array( 'periodo' => 'a2026' ) );
	// Para revisar las pantallas en el navegador (datos inventados): ASDE_VOLCAR=<carpeta>.
	if ( getenv( 'ASDE_VOLCAR' ) ) {
		file_put_contents( getenv( 'ASDE_VOLCAR' ) . '/caja.html', $h );
		file_put_contents( getenv( 'ASDE_VOLCAR' ) . '/libro-mayor.html', $hl );
	}
	$ok( str_contains( $hl, 'Resumen por partida' ) && str_contains( $hl, 'Cuotas en efectivo' ) && str_contains( $hl, 'Saldo inicial del banco' ), 'el libro mayor: cuentas, resumen y el saldo inicial del banco' );
	list( $cab, $filas, $opc ) = CajaView::excel( 'grupo', Periodo::de( 'a2026' ), Servicio::extracto( 'grupo', Periodo::de( 'a2026' ) ) );
	$ok( 6 === count( $cab ) && count( $filas ) === count( Servicio::extracto( 'grupo', Periodo::de( 'a2026' ) )['filas'] ) && 'euros' === $opc['tipos'][5], 'Excel de la caja: una fila por apunte, con el saldo' );
	list( , $res ) = LibroMayorView::excel_resumen( LibroMayor::calcular( Periodo::de( 'a2026' ) ), Periodo::de( 'a2026' ) );
	$ok( count( $res ) === count( LibroMayor::calcular( Periodo::de( 'a2026' ) )['resumen'] ), 'Excel del resumen: una fila por partida' );
	$ok( Modulos::activo( Modulos::CAJA, '999' ) && ! in_array( Modulos::CAJA, array_keys( (array) ( get_option( 'asde_ap_modulos' )['998'] ?? array() ) ), true ) && in_array( Modulos::CAJA, Modulos::POR_DEFECTO_APAGADOS, true ), 'el módulo nace apagado y se enciende por grupo' );
} catch ( Throwable $e ) {
	++$fails;
	echo 'FAIL excepcion: ', $e->getMessage(), ' (', basename( $e->getFile() ), ':', $e->getLine(), ")\n";
} finally {
	$_GET  = array();
	$_POST = array();
	remove_filter( 'wp_redirect', $redirigir, 1 );
	remove_filter( 'wp_die_handler', $morir, PHP_INT_MAX );
	remove_filter( 'send_auth_cookies', '__return_false' );
	wp_set_current_user( 0 );
	foreach ( $insertados as list( $tabla, $id ) ) {
		$wpdb->delete( $tabla, array( 'id' => $id ) );
	}
	$wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE grupo = '999'", CajaTable::table() ) ); // phpcs:ignore WordPress.DB
	if ( $tipo_gasto ) {
		$wpdb->delete( TiposMovimiento::table(), array( 'id' => $tipo_gasto ) );
	}
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id > %d AND ( objeto = %s OR user_id IN ( %d, %d ) )', Logger::table(), $log_antes, 'caja', (int) $u_teso, (int) $u_scouter ) ); // phpcs:ignore WordPress.DB
	foreach ( $ids as $uid ) {
		Ambito::olvidar( (int) $uid );
		wp_delete_user( (int) $uid );
	}
	if ( null === $modulos_antes ) {
		delete_option( 'asde_ap_modulos' );
	} else {
		update_option( 'asde_ap_modulos', $modulos_antes, false );
	}
	Modulos::olvidar();
	Grupo::forzar( null );
	remove_filter( 'pre_http_request', $cortar_red, 2 );
	wp_using_ext_object_cache( $cache_externa );
	$quedan = 0;
	foreach ( array( CajaTable::table(), BancoTable::table(), FamiliasTable::table(), ReintegrosTable::table(), PedidosTable::table(), GastosTable::table(), AnticiposTable::table(), EventoMovimientos::table() ) as $t ) {
		$quedan += (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE grupo = '999'", $t ) ); // phpcs:ignore WordPress.DB
	}
	$ok( 0 === $quedan && ! get_user_by( 'login', 'prueba-caja-teso' ) && ! get_user_by( 'login', 'prueba-caja-scouter' ) && $max_id( MovimientosTable::table() ) <= max( $mov_antes, $max_id( MovimientosTable::table() ) ), 'limpieza: nada del grupo 999 ni usuarios de la prueba' );
}

echo $fails ? "\n$fails FALLOS\n" : "\nTODO OK (nada tocado en el CRM; el sitio queda como estaba)\n";
