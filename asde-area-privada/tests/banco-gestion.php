<?php
/**
 * Pruebas de la v0.20 (entorno LOCAL): gestión del banco en Tesorería (doc 16).
 * Ejecutar desde app/public del site Local:
 *
 *   wp eval-file wp-content/plugins/asde-area-privada/tests/banco-gestion.php
 *
 * Todo en un grupo ficticio (999) que no tiene CRM: movimientos sintéticos con
 * datos inventados. Al terminar se borra lo creado en el grupo 999 (movimientos,
 * lotes, tipos, familias, pagos) y los usuarios de la prueba, y se restauran las
 * opciones tocadas.
 */
defined( 'ABSPATH' ) || exit;

require_once ABSPATH . 'wp-admin/includes/user.php';

use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Auth\Ambito;
use AsdeBalears\AreaPrivada\Domain\Grupo;
use AsdeBalears\AreaPrivada\Frontend\Notices;
use AsdeBalears\AreaPrivada\Frontend\Router;
use AsdeBalears\AreaPrivada\Frontend\Views\BancoView;
use AsdeBalears\AreaPrivada\Frontend\Views\View;
use AsdeBalears\AreaPrivada\Install\Roles;
use AsdeBalears\AreaPrivada\Tesoreria\Banco\BancoTable;
use AsdeBalears\AreaPrivada\Tesoreria\Banco\Conciliacion;
use AsdeBalears\AreaPrivada\Tesoreria\Banco\TiposMovimiento;
use AsdeBalears\AreaPrivada\Tesoreria\FamiliasTable;
use AsdeBalears\AreaPrivada\Tesoreria\MovimientosTable;
use AsdeBalears\AreaPrivada\Usuarios\Usuarios;

global $wpdb;

$fails = 0;
$ok    = function ( $cond, string $label ) use ( &$fails ) {
	if ( ! $cond ) {
		++$fails;
	}
	echo ( $cond ? 'OK   ' : 'FAIL ' ), $label, "\n";
};

foreach ( get_users( array( 'search' => 'prueba-bg-*', 'search_columns' => array( 'user_login' ) ) ) as $u ) {
	exit( 'Ya existe el usuario ' . $u->user_login . " (¿una ejecución anterior interrumpida?). Bórralo antes de repetir la prueba.\n" );
}
foreach ( array( BancoTable::table(), TiposMovimiento::table(), FamiliasTable::table() ) as $t ) {
	if ( (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE grupo = '999'", $t ) ) ) {
		exit( "Quedan datos del grupo de prueba 999 en $t (¿una ejecución anterior interrumpida?).\n" );
	}
}

// --- Lo que se restaura al final ---------------------------------------------
$max_id         = static fn( string $t ) => (int) $wpdb->get_var( "SELECT COALESCE(MAX(id), 0) FROM $t" ); // phpcs:ignore WordPress.DB
$log_antes      = $max_id( Logger::table() );
$opcion_semilla = 'asde_ap_banco_tipos_sembrados';
$semilla_antes  = get_option( $opcion_semilla, null );
$cache_externa  = wp_using_ext_object_cache( true );
$cortadas       = 0;
$cortar_red     = static function ( $pre, $args, $url ) use ( &$cortadas ) {
	if ( false === $pre && str_contains( (string) $url, '/rest.php' ) ) {
		++$cortadas;
		return new WP_Error( 'prueba', 'CRM cortado durante la prueba' );
	}
	return $pre;
};
add_filter( 'pre_http_request', $cortar_red, 2, 3 );
Grupo::forzar(
	array(
		'id'     => '999',
		'numero' => '999',
		'nombre' => 'Grupo de prueba 999',
		'crm_id' => '',
	)
);

$ids   = array();
$crear = static function ( string $login, string $rol ) use ( &$ids ): int {
	$id = wp_insert_user(
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
$u_teso    = $crear( 'prueba-bg-teso', Roles::TESORERIA );
$u_scouter = $crear( 'prueba-bg-scouter', Roles::SCOUTER );
$como      = static function ( int $uid ): void {
	wp_set_current_user( $uid );
	Ambito::olvidar( $uid );
};
add_filter( 'send_auth_cookies', '__return_false' );
$redirigir = static function ( $destino ) {
	throw new RuntimeException( 'asde-redirect:' . $destino );
};
add_filter( 'wp_redirect', $redirigir, 1 );
$morir = static fn() => static function ( $mensaje ) {
	throw new RuntimeException( 'asde-die:' . ( is_wp_error( $mensaje ) ? $mensaje->get_error_message() : (string) $mensaje ) );
};
add_filter( 'wp_die_handler', $morir, PHP_INT_MAX ); // Después del de WP-CLI, que si no termina el proceso sin pasar por la limpieza.
add_filter( 'wp_die_ajax_handler', $morir, PHP_INT_MAX );
/** Envía un formulario a una vista; devuelve [avisos, adónde redirige]. */
$enviar = static function ( $vista, array $campos, string $nonce, bool $ventana = false ): array {
	$_POST    = $campos + array( '_wpnonce' => wp_create_nonce( $nonce ) );
	$_REQUEST = $_POST;
	$destino  = '';
	if ( $ventana ) {
		$_SERVER['HTTP_X_ASDE_MODAL'] = '1';
	}
	try {
		$vista->handle_post();
	} catch ( RuntimeException $e ) {
		$destino = str_starts_with( $e->getMessage(), 'asde-redirect:' ) ? substr( $e->getMessage(), 14 ) : $e->getMessage();
	} finally {
		$_POST    = array();
		$_REQUEST = array();
		unset( $_SERVER['HTTP_X_ASDE_MODAL'] );
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
// Movimientos sintéticos (como si vinieran de un fichero del banco).
$importar = static function ( array $movs ): array {
	$base = array(
		'cuenta'          => '9999 0001 ******0001',
		'fecha_valor'     => null,
		'concepto_comun'  => '',
		'concepto_propio' => '',
		'documento'       => '',
		'referencia1'     => '',
		'referencia2'     => '',
		'ordenante'       => '',
	);
	$movs = BancoTable::clasificar( array_map( static fn( $m ) => $m + $base, $movs ) );
	return BancoTable::importar(
		array(
			'movimientos' => $movs,
			'cuentas'     => array(
				array(
					'cuenta'      => '9999 0001 ******0001',
					'saldo_final' => null,
				),
			),
		),
		'csv',
		'prueba.csv',
		hash( 'sha256', wp_json_encode( $movs ) )
	);
};
$mov = static fn( string $concepto ) => current( BancoTable::movimientos( array( 'q' => $concepto ) ) ) ?: null;

try {
	echo "== Parte A · Tipos de gasto e ingreso ==\n";
	$como( $u_teso );
	$sembrados = (array) get_option( $opcion_semilla, array() );
	update_option( $opcion_semilla, array_values( array_diff( $sembrados, array( '999' ) ) ), false );
	TiposMovimiento::sembrar();
	$tipos = TiposMovimiento::todos();
	$ok( count( TiposMovimiento::INICIALES ) === count( $tipos ) && in_array( 'Comisiones bancarias', array_column( $tipos, 'nombre' ), true ) && in_array( 'Donativos', array_column( $tipos, 'nombre' ), true ), 'la primera vez se crean los tipos de partida (' . count( $tipos ) . ')' );
	$ok( TiposMovimiento::GASTO === reset( $tipos )['naturaleza'] && TiposMovimiento::AMBOS === end( $tipos )['naturaleza'], 'salen ordenados: gastos, ingresos y «gasto o ingreso»' );
	$borrar_todos = array_keys( $tipos );
	foreach ( $borrar_todos as $tid ) {
		TiposMovimiento::borrar( $tid );
	}
	TiposMovimiento::sembrar();
	$ok( ! TiposMovimiento::todos(), 'si se borran todos, no vuelven a crearse solos' );
	foreach ( array( array( 'Material', 'gasto' ), array( 'Comisiones', 'gasto' ), array( 'Donativos', 'ingreso' ), array( 'Devoluciones', 'ambos' ) ) as list( $n, $c ) ) {
		TiposMovimiento::crear( $n, $c );
	}
	$t_por = array_column( TiposMovimiento::todos(), 'id', 'nombre' );
	$ok( is_wp_error( TiposMovimiento::crear( ' material ', 'gasto' ) ) && is_wp_error( TiposMovimiento::crear( 'Rifas', 'otra' ) ) && is_wp_error( TiposMovimiento::crear( '', 'gasto' ) ), 'no se repiten nombres (sin mayúsculas ni espacios), la clase tiene que ser válida y el nombre no puede ir vacío' );
	$ok( TiposMovimiento::encaja( TiposMovimiento::obtener( $t_por['Material'] ), -10 ) && ! TiposMovimiento::encaja( TiposMovimiento::obtener( $t_por['Material'] ), 10 ) && TiposMovimiento::encaja( TiposMovimiento::obtener( $t_por['Devoluciones'] ), 10 ) && TiposMovimiento::encaja( TiposMovimiento::obtener( $t_por['Devoluciones'] ), -10 ), 'un tipo de gasto solo encaja en los cargos; «gasto o ingreso», en los dos' );

	echo "\n== Parte B · Marcar como revisado con su tipo ==\n";
	$r = $importar(
		array(
			array( 'fecha' => '2026-10-01', 'importe' => -45.5, 'concepto' => 'PRUEBA-BG compra de cuerdas' ),
			array( 'fecha' => '2026-10-02', 'importe' => -3.0, 'concepto' => 'PRUEBA-BG comisión mantenimiento' ),
			array( 'fecha' => '2026-10-03', 'importe' => 200.0, 'concepto' => 'PRUEBA-BG donativo de una empresa' ),
			array( 'fecha' => '2026-10-04', 'importe' => -12.0, 'concepto' => 'PRUEBA-BG gasolina furgoneta' ),
			array( 'fecha' => '2026-10-05', 'importe' => 70.0, 'concepto' => 'PRUEBA-BG cuota primer plazo', 'ordenante' => 'Familia Inventada' ),
		)
	);
	$ok( is_array( $r ) && 5 === $r['nuevos'], 'movimientos de prueba importados' );
	$cuerdas  = $mov( 'cuerdas' );
	$comision = $mov( 'comisión' );
	$donativo = $mov( 'donativo' );
	$gasolina = $mov( 'gasolina' );
	$cuota    = $mov( 'primer plazo' );

	$e = Conciliacion::ignorar( (int) $cuerdas['id'], (int) $t_por['Donativos'] );
	$ok( is_wp_error( $e ) && str_contains( $e->get_error_message(), 'es un tipo de ingreso y este movimiento es un gasto' ), 'un tipo de ingreso no vale para un gasto (y se dice por qué)' );
	$ok( is_wp_error( Conciliacion::ignorar( (int) $cuerdas['id'], 0 ) ) && BancoTable::PENDIENTE === BancoTable::obtener( (int) $cuerdas['id'] )['estado'], 'sin tipo no se marca como revisado' );
	$ok( true === Conciliacion::ignorar( (int) $cuerdas['id'], (int) $t_por['Material'], 'Factura 2026/031' ), 'con un tipo de gasto, sí' );
	$c = BancoTable::obtener( (int) $cuerdas['id'] );
	$ok( BancoTable::IGNORADO === $c['estado'] && $t_por['Material'] === $c['tipo_id'] && 'Factura 2026/031' === $c['nota'] && $u_teso === (int) $c['revisado_por'], 'queda revisado, con su tipo, la nota y quién lo hizo' );
	$ok( array( (int) $cuerdas['id'] ) === array_map( 'intval', array_column( BancoTable::movimientos( array( 'q' => 'factura 2026' ) ), 'id' ) ), 'la búsqueda mira también la nota' );
	$ok( true === Conciliacion::ignorar( (int) $cuerdas['id'], (int) $t_por['Devoluciones'] ) && $t_por['Devoluciones'] === BancoTable::obtener( (int) $cuerdas['id'] )['tipo_id'], 'a uno ya revisado se le puede cambiar el tipo' );
	$ok( true === Conciliacion::deshacer( (int) $cuerdas['id'] ) && null === BancoTable::obtener( (int) $cuerdas['id'] )['tipo_id'] && '' === BancoTable::obtener( (int) $cuerdas['id'] )['nota'], 'deshacer lo devuelve a pendiente y le quita el tipo y la nota' );

	$fam  = FamiliasTable::crear( array( '999901' ) );
	$pago = Conciliacion::conciliar( (int) $cuota['id'], (int) $fam );
	$ok( is_int( $pago ) && is_wp_error( Conciliacion::ignorar( (int) $cuota['id'], (int) $t_por['Donativos'] ) ), 'uno conciliado con una familia no se puede marcar como revisado (hay que deshacer antes)' );

	// Varios a la vez, desde la ventana: uno mal (tipo de ingreso en un gasto).
	list( $avisos, $destino ) = $enviar(
		new BancoView(),
		array(
			'asde_action' => 'revisar',
			'tipo'        => array(
				$cuerdas['id']  => (string) $t_por['Material'],
				$comision['id'] => (string) $t_por['Comisiones'],
				$gasolina['id'] => (string) $t_por['Donativos'],
				$donativo['id'] => (string) $t_por['Donativos'],
			),
			'nota'        => 'Revisión de octubre',
			'volver'      => home_url( '/?prueba=1' ),
		),
		'asde_banco_revisar',
		true
	);
	$estados = array_map( static fn( $m ) => BancoTable::obtener( (int) $m['id'] )['estado'], array( $cuerdas, $comision, $gasolina, $donativo ) );
	$ok( array( BancoTable::IGNORADO, BancoTable::IGNORADO, BancoTable::PENDIENTE, BancoTable::IGNORADO ) === $estados, 'en grupo: se marcan los que encajan y el que no, se queda pendiente' );
	$ok( str_contains( $avisos, '3 movimientos marcados como revisados' ) && str_contains( $avisos, '04/10/2026' ) && str_contains( $avisos, 'tipo de ingreso' ), 'el aviso dice cuántos y, del que falla, cuál es y por qué' );
	$ok( str_contains( $destino, 'revisar=hecho' ), 'desde la ventana emergente, vuelve a la ventana con «Movimientos revisados»' );
	list( , $destino ) = $enviar( new BancoView(), array( 'asde_action' => 'revisar', 'tipo' => array( $gasolina['id'] => (string) $t_por['Material'] ), 'volver' => home_url( '/?prueba=1' ) ), 'asde_banco_revisar' );
	$ok( str_contains( $destino, 'prueba=1' ) && BancoTable::IGNORADO === BancoTable::obtener( (int) $gasolina['id'] )['estado'], 'sin la ventana (sin JavaScript), vuelve al listado del que se venía' );
	$ok( is_wp_error( TiposMovimiento::borrar( (int) $t_por['Material'] ) ) && is_wp_error( TiposMovimiento::cambiar( (int) $t_por['Material'], 'Material', 'ingreso' ) ) && true === TiposMovimiento::cambiar( (int) $t_por['Material'], 'Material y equipamiento', 'gasto' ), 'un tipo en uso no se borra ni pasa a ser de ingreso, pero sí se renombra' );
	$usos = TiposMovimiento::usos();
	$ok( 2 === $usos[ $t_por['Material'] ]['gastos'] && -57.5 === $usos[ $t_por['Material'] ]['total'] && 200.0 === $usos[ $t_por['Donativos'] ]['total'], 'lo que suma cada tipo (2 gastos de material: -57,50 €; donativos: +200 €)' );

	echo "\n== Parte C · Pantallas ==\n";
	$como( $u_scouter );
	$ok( str_contains( $ver( new BancoView() ), 'solo lo ven Secretaría, Coordinación y Tesorería' ), 'un scouter no entra en el banco' );
	$como( $u_teso );
	Conciliacion::deshacer( (int) $comision['id'] );
	$h = $ver( new BancoView(), array( 'estado' => '' ) );
	$ok( str_contains( $h, 'data-asde-parcial="banco-movs"' ) && str_contains( $h, 'data-asde-parcial="banco-resumen"' ), 'el listado y el resumen son zonas que se actualizan sin recargar' );
	$ok( 1 === preg_match( '/name="m\[\]" value="' . $comision['id'] . '" form="asde-banco-lote"/', $h ) && ! preg_match( '/name="m\[\]" value="' . $cuerdas['id'] . '"/', $h ) && ! preg_match( '/name="m\[\]" value="' . $cuota['id'] . '"/', $h ), 'la casilla de la selección múltiple sale solo en los pendientes' );
	$ok( str_contains( $h, 'id="asde-banco-lote"' ) && str_contains( $h, 'Marcar como revisados…' ) && str_contains( $h, 'data-asde-banco-todos' ), 'botón «Marcar como revisados…» y casilla para marcar todos' );
	$ok( str_contains( $h, 'Material y equipamiento' ) && str_contains( $h, 'Revisión de octubre' ) && str_contains( $h, 'Cambiar el tipo' ), 'los revisados enseñan su tipo y su nota, con «Cambiar el tipo»' );
	$ok( 1 === preg_match( '/revisar=1[^"]*m%5B0%5D=' . $comision['id'] . '[^"]*" data-asde-modal/', $h ), '«Marcar revisado…» de una fila abre la ventana con ese movimiento' );
	$h = $ver( new BancoView(), array( 'estado' => '', 'clase' => (string) $t_por['Donativos'] ) );
	$ok( str_contains( $h, 'PRUEBA-BG donativo' ) && ! str_contains( $h, 'PRUEBA-BG compra de cuerdas' ), 'filtro por tipo' );
	View::en_modal( true );
	$h = $ver( new BancoView(), array( 'revisar' => '1', 'm' => array( (string) $comision['id'], (string) $cuota['id'] ) ) );
	View::en_modal( false );
	$ok( str_contains( $h, 'asde-ap--modal' ) && str_contains( $h, 'name="tipo[' . $comision['id'] . ']"' ) && ! str_contains( $h, 'name="tipo[' . $cuota['id'] . ']"' ) && str_contains( $h, 'ya está conciliado' ), 'la ventana enseña los elegidos que se pueden revisar y avisa del conciliado' );
	preg_match( '/name="tipo\[' . $comision['id'] . '\]"[^>]*>(.*?)<\/select>/s', $h, $sel );
	$ok( isset( $sel[1] ) && str_contains( $sel[1], 'Comisiones' ) && str_contains( $sel[1], 'Devoluciones' ) && ! str_contains( $sel[1], 'Donativos' ), 'en un gasto, el desplegable solo tiene tipos de gasto (y «gasto o ingreso»)' );
	$ok( in_array( 'banco', Router::MODALES, true ), 'el banco se puede abrir en la ventana emergente' );
	$h = $ver( new BancoView(), array( 'tipos' => '1' ) );
	$ok( str_contains( $h, 'Tipos de gasto e ingreso' ) && str_contains( $h, 'Crear tipo' ) && 1 === preg_match( '/-57,50/', $h ), 'la pantalla de tipos, con lo que suma cada uno' );
	list( $avisos ) = $enviar( new BancoView(), array( 'asde_action' => 'tipo_crear', 'nombre' => 'Lotería', 'naturaleza' => 'ingreso' ), 'asde_banco_tipos' );
	$ok( str_contains( $avisos, 'Tipo creado' ) && isset( array_column( TiposMovimiento::todos(), 'id', 'nombre' )['Lotería'] ), 'se crean tipos desde su pantalla' );
	$ok( 0 === $cortadas, 'ninguna petición ha salido hacia un CRM' );
} finally {
	remove_filter( 'wp_redirect', $redirigir, 1 );
	remove_filter( 'wp_die_handler', $morir, PHP_INT_MAX );
	remove_filter( 'wp_die_ajax_handler', $morir, PHP_INT_MAX );
	remove_filter( 'send_auth_cookies', '__return_false' );
	remove_filter( 'pre_http_request', $cortar_red, 2 );
	$_POST = array();
	$_GET  = array();
	View::en_modal( false );
	wp_using_ext_object_cache( $cache_externa );
	wp_set_current_user( 0 );
	foreach ( (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM %i WHERE grupo = '999'", FamiliasTable::table() ) ) as $fid ) {
		$wpdb->delete( MovimientosTable::table(), array( 'familia_id' => (int) $fid ) );
		$wpdb->delete( FamiliasTable::table_miembros(), array( 'familia_id' => (int) $fid ) );
		$wpdb->delete( FamiliasTable::table(), array( 'id' => (int) $fid ) );
	}
	foreach ( array( BancoTable::table(), BancoTable::table_lotes(), TiposMovimiento::table() ) as $t ) {
		$wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE grupo = '999'", $t ) );
	}
	$lista = implode( ',', array_map( 'intval', $ids ) ) ?: '0';
	$wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE id > %d AND ( user_id IN ($lista) OR objeto IN ('banco', 'banco_tipo') )", Logger::table(), $log_antes ) ); // phpcs:ignore WordPress.DB
	foreach ( $ids as $uid ) {
		delete_transient( 'asde_ap_notices_' . $uid );
		Ambito::olvidar( (int) $uid );
		wp_delete_user( (int) $uid );
	}
	null === $semilla_antes ? delete_option( $opcion_semilla ) : update_option( $opcion_semilla, $semilla_antes, false );
	Grupo::forzar( null );
	$quedan = 0;
	foreach ( array( BancoTable::table(), TiposMovimiento::table(), FamiliasTable::table() ) as $t ) {
		$quedan += (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE grupo = '999'", $t ) );
	}
	$ok( 0 === $quedan && ! get_users( array( 'search' => 'prueba-bg-*', 'search_columns' => array( 'user_login' ) ) ), 'limpieza: nada del grupo 999 ni usuarios de la prueba' );
}

echo $fails ? "\n$fails FALLOS\n" : "\nTODO OK (ningún dato real tocado; el sitio queda como estaba)\n";
