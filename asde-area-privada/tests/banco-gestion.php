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
use AsdeBalears\AreaPrivada\Domain\Persona;
use AsdeBalears\AreaPrivada\Frontend\Notices;
use AsdeBalears\AreaPrivada\Frontend\Router;
use AsdeBalears\AreaPrivada\Frontend\Views\BancoView;
use AsdeBalears\AreaPrivada\Frontend\Views\View;
use AsdeBalears\AreaPrivada\Install\Roles;
use AsdeBalears\AreaPrivada\Frontend\Pages;
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
			array( 'fecha' => '2026-10-04', 'importe' => -12.0, 'concepto' => 'PRUEBA-BG gasolina furgoneta', 'fecha_valor' => '2026-10-06' ),
			array( 'fecha' => '2026-10-05', 'importe' => 70.0, 'concepto' => 'PRUEBA-BG cuota primer plazo', 'ordenante' => 'Familia Inventada' ),
		)
	);
	$ok( is_array( $r ) && 5 === $r['nuevos'], 'movimientos de prueba importados' );
	$cuerdas  = $mov( 'cuerdas' );
	$comision = $mov( 'comisión' );
	$donativo = $mov( 'donativo' );
	$gasolina = $mov( 'gasolina' );
	$ok( null === $cuerdas['fecha_valor'] && '2026-10-06' === $gasolina['fecha_valor'], 'la fecha valor se guarda, y sin ella queda vacía (NULL) (v0.26.2: NULLIF en la consulta)' );
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

	// Doc 59, RC-TES-1: el mismo ingreso conciliado dos veces (doble clic o dos personas a la vez) da un solo pago.
	$vigentes = static fn( int $f ) => count( array_filter( MovimientosTable::de_familia_todas( $f ), static fn( $x ) => MovimientosTable::PAGO === $x['tipo'] ) );
	$segundo  = Conciliacion::conciliar( (int) $cuota['id'], (int) $fam );
	$ok( is_wp_error( $segundo ) && 1 === $vigentes( (int) $fam ) && $pago === (int) BancoTable::obtener( (int) $cuota['id'] )['pago_id'], 'conciliar otra vez el mismo ingreso: error y la familia sigue con un solo pago (RC-TES-1)' );
	// La otra petición ya leyó «pendiente» antes de que esta lo conciliara: su toma condicionada falla y no registra nada.
	$ok( false === BancoTable::marcar( (int) $cuota['id'], BancoTable::CONCILIADO, (int) $fam, null, BancoTable::PENDIENTE ) && $pago === (int) BancoTable::obtener( (int) $cuota['id'] )['pago_id'], 'la toma condicionada (UPDATE … WHERE estado = pendiente) no pisa un ingreso ya conciliado' );
	$ok( true === Conciliacion::deshacer( (int) $cuota['id'] ) && is_wp_error( Conciliacion::deshacer( (int) $cuota['id'] ) ) && 0 === $vigentes( (int) $fam ), 'deshacer dos veces: la segunda da error y el pago queda anulado una sola vez' );
	// Otra petición (que también había leído «pendiente») lo concilia justo entre las comprobaciones y la escritura de esta.
	$adelantada = false;
	$adelantar  = static function ( $q ) use ( &$adelantada, $wpdb, $cuota ) {
		if ( ! $adelantada && str_starts_with( ltrim( (string) $q ), 'UPDATE' ) && str_contains( (string) $q, BancoTable::table() ) && preg_match( "/WHERE .*`estado` = 'pendiente'/", (string) $q ) ) {
			$adelantada = true;
			$wpdb->update( BancoTable::table(), array( 'estado' => BancoTable::IGNORADO, 'nota' => 'Otra petición' ), array( 'id' => (int) $cuota['id'] ) ); // phpcs:ignore WordPress.DB
		}
		return $q;
	};
	add_filter( 'query', $adelantar );
	try {
		$r = Conciliacion::conciliar( (int) $cuota['id'], (int) $fam );
	} finally {
		remove_filter( 'query', $adelantar );
	}
	$ok( $adelantada && is_wp_error( $r ) && 0 === $vigentes( (int) $fam ) && 'Otra petición' === BancoTable::obtener( (int) $cuota['id'] )['nota'], 'si otra petición se lleva el ingreso a la vez, esta da error y no registra ningún pago' );
	BancoTable::marcar( (int) $cuota['id'], BancoTable::PENDIENTE );
	$pago = Conciliacion::conciliar( (int) $cuota['id'], (int) $fam );
	$ok( is_int( $pago ) && 1 === $vigentes( (int) $fam ), 'y se puede volver a conciliar' );

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

	// v0.34 (petición del usuario): en «Marcar como revisado», un «+» junto al tipo de cada fila
	// crea un tipo nuevo en una ventana pequeña (JSON) o, sin JavaScript, va a los tipos y vuelve.
	View::en_modal( true );
	$h = $ver( new BancoView(), array( 'revisar' => '1', 'm' => array( (string) $comision['id'] ) ) );
	View::en_modal( false );
	$ok( 1 === preg_match( '/data-asde-signo="gasto">.*?<a class="[^"]*asde-banco-mas" href="([^"]+)" data-asde-banco-tipo-nuevo/s', $h, $mas ) && str_contains( html_entity_decode( $mas[1] ), 'tipos=1' ) && str_contains( html_entity_decode( $mas[1] ), 'volver=' ), 'cada fila lleva su «+», que sin JavaScript va a los tipos con la vuelta a la ventana' );
	$ok( 1 === preg_match( '/<template data-asde-banco-tipo-plantilla>.*name="asde_action" value="tipo_crear".*name="naturaleza".*<\/template>/s', $h ) && str_contains( $h, 'data-naturaleza="gasto"' ), 'la ventana pequeña va en un <template> (fuera del formulario) y los grupos del desplegable dicen su clase' );
	// Con wp_doing_ajax, wp_send_json() acaba en el wp_die_ajax_handler de la prueba (lanza y $enviar lo recoge).
	$json_banco = static function ( array $campos ) use ( $enviar ): array {
		$_SERVER['HTTP_X_ASDE_JSON'] = '1';
		add_filter( 'wp_doing_ajax', '__return_true' );
		ob_start();
		try {
			list( $avisos ) = $enviar( new BancoView(), $campos, 'asde_banco_tipos' );
		} finally {
			$salida = (string) ob_get_clean();
			remove_filter( 'wp_doing_ajax', '__return_true' );
			unset( $_SERVER['HTTP_X_ASDE_JSON'] );
		}
		return array( json_decode( $salida, true ), $avisos );
	};
	list( $r, $avisos_json ) = $json_banco( array( 'asde_action' => 'tipo_crear', 'nombre' => 'Subvención municipal', 'naturaleza' => 'ingreso' ) );
	$t = TiposMovimiento::obtener( (int) ( $r['data']['id'] ?? 0 ) );
	$ok( ! empty( $r['success'] ) && $t && 'Subvención municipal' === $r['data']['nombre'] && 'ingreso' === $r['data']['naturaleza'] && '' === $avisos_json, 'con X-Asde-Json, crear un tipo devuelve el tipo en JSON (y no deja el aviso para la página siguiente)' );
	list( $r ) = $json_banco( array( 'asde_action' => 'tipo_crear', 'nombre' => 'subvención MUNICIPAL', 'naturaleza' => 'ingreso' ) );
	$ok( isset( $r['success'] ) && false === $r['success'] && str_contains( (string) ( $r['data']['mensaje'] ?? '' ), 'Ya hay un tipo' ), 'un tipo repetido devuelve el motivo en JSON' );
	// Sin JavaScript: la página de tipos con la vuelta (los m[] de la dirección no se pierden).
	$vuelta = Pages::url( 'banco', array( 'revisar' => '1' ) ) . '&m%5B0%5D=' . $comision['id'];
	$h      = $ver( new BancoView(), array( 'tipos' => '1', 'volver' => $vuelta ) );
	$ok( str_contains( $h, '← Volver a «Marcar como revisado»' ) && str_contains( $h, 'href="' . esc_url( $vuelta ) . '"' ), 'la página de tipos enseña «← Volver a «Marcar como revisado»» con los elegidos' );
	$_GET = array( 'tipos' => '1', 'volver' => $vuelta );
	list( , $destino ) = $enviar( new BancoView(), array( 'asde_action' => 'tipo_crear', 'nombre' => 'Rifa', 'naturaleza' => 'ingreso' ), 'asde_banco_tipos' );
	$_GET = array( 'tipos' => '1', 'volver' => 'https://otra-web.example.invalid/' );
	list( , $destino_fuera ) = $enviar( new BancoView(), array( 'asde_action' => 'tipo_crear', 'nombre' => 'Rifa 2', 'naturaleza' => 'ingreso' ), 'asde_banco_tipos' );
	$_GET = array();
	Notices::render();
	$ok( str_contains( $destino, 'tipos=1' ) && str_contains( rawurldecode( $destino ), 'm%5B0%5D=' . $comision['id'] ) && ! str_contains( $destino_fuera, 'otra-web' ), 'tras crear un tipo se sigue con la vuelta (de este sitio; una dirección de fuera no)' );
	// v0.25: después de deshacer o conciliar se vuelve a la misma vista (filtros y página), no a la del principio.
	$filtrada    = Pages::url( 'banco', array( 'estado' => 'todos', 'q' => 'PRUEBA-BG', 'pag' => '1' ) );
	$partes      = wp_parse_url( $filtrada );
	$uri_antes   = $_SERVER['REQUEST_URI'] ?? null;
	$host_antes  = $_SERVER['HTTP_HOST'] ?? null;
	$_SERVER['REQUEST_URI'] = ( $partes['path'] ?? '/' ) . '?' . ( $partes['query'] ?? '' );
	$_SERVER['HTTP_HOST']   = $partes['host'] . ( isset( $partes['port'] ) ? ':' . $partes['port'] : '' );
	$fam_cuota   = (int) BancoTable::obtener( (int) $cuota['id'] )['familia_id'];
	list( , $destino ) = $enviar( new BancoView(), array( 'asde_action' => 'deshacer', 'mov' => (string) $cuota['id'] ), 'asde_banco_mov_' . $cuota['id'] );
	list( , $destino2 ) = $enviar( new BancoView(), array( 'asde_action' => 'conciliar', 'mov' => (string) $cuota['id'], 'familia' => (string) $fam_cuota, 'ronda_pago' => '2026-2027' ), 'asde_banco_mov_' . $cuota['id'] );
	if ( null === $uri_antes ) {
		unset( $_SERVER['REQUEST_URI'] );
	} else {
		$_SERVER['REQUEST_URI'] = $uri_antes;
	}
	if ( null === $host_antes ) {
		unset( $_SERVER['HTTP_HOST'] );
	} else {
		$_SERVER['HTTP_HOST'] = $host_antes;
	}
	$ok( str_contains( $destino, 'estado=todos' ) && str_contains( $destino, 'q=PRUEBA-BG' ) && str_contains( $destino2, 'estado=todos' ) && BancoTable::CONCILIADO === BancoTable::obtener( (int) $cuota['id'] )['estado'], 'deshacer y conciliar vuelven a la misma vista filtrada (v0.25)' );
	echo "\n== Parte D · Control de pagos: registrar un pago con un ingreso del banco ==\n";
	$fam2 = FamiliasTable::obtener( (int) FamiliasTable::crear( array( '999902' ) ) );
	$importar(
		array(
			array( 'fecha' => '2026-10-07', 'importe' => 120.0, 'concepto' => 'PRUEBA-BG transferencia cuota ' . $fam2['numero'] ),
			array( 'fecha' => '2026-10-08', 'importe' => 55.0, 'concepto' => 'PRUEBA-BG ingreso sin referencia' ),
			array( 'fecha' => '2026-10-09', 'importe' => -8.0, 'concepto' => 'PRUEBA-BG otro gasto' ),
		)
	);
	$con_ref = $mov( 'transferencia cuota' );
	$sin_ref = $mov( 'ingreso sin referencia' );
	$lista   = Conciliacion::pendientes_para( $fam2 );
	$ids_l   = array_map( 'intval', array_column( $lista, 'id' ) );
	$ok( $ids_l && (int) $con_ref['id'] === $ids_l[0] && $lista[0]['sugerido'] && in_array( (int) $sin_ref['id'], $ids_l, true ), 'la lista de ingresos por conciliar pone primero el que lleva la referencia de la familia (sugerido)' );
	$ok( ! array_filter( $lista, static fn( $m ) => $m['importe'] < 0 || BancoTable::PENDIENTE !== $m['estado'] ), 'solo ingresos pendientes: ni gastos ni conciliados' );

	$como( $u_scouter );
	list( $avisos ) = $enviar( new \AsdeBalears\AreaPrivada\Frontend\Views\TesoreriaView(), array( 'asde_action' => 'registrar_pago', 'familia' => (string) $fam2['id'], 'ronda' => '2026-2027', 'banco' => (string) $con_ref['id'], 'fecha' => '2026-10-07', 'importe' => '120' ), 'asde_mov_' . $fam2['id'] );
	$ok( str_contains( $avisos, 'solo los usan Secretaría, Coordinación y Tesorería' ) && BancoTable::PENDIENTE === BancoTable::obtener( (int) $con_ref['id'] )['estado'] && ! MovimientosTable::de_familia( (int) $fam2['id'], '2026-2027' ), 'un scouter (que registra pagos pero no ve el banco) no puede usar un movimiento del banco' );
	$como( $u_teso );
	list( $avisos ) = $enviar( new \AsdeBalears\AreaPrivada\Frontend\Views\TesoreriaView(), array( 'asde_action' => 'registrar_pago', 'familia' => (string) $fam2['id'], 'ronda' => '2026-2027', 'banco' => (string) $con_ref['id'], 'fecha' => '2026-01-01', 'importe' => '1', 'metodo' => 'efectivo' ), 'asde_mov_' . $fam2['id'] );
	$pagos = MovimientosTable::de_familia( (int) $fam2['id'], '2026-2027' );
	$mb    = BancoTable::obtener( (int) $con_ref['id'] );
	$ok( 1 === count( $pagos ) && 120.0 === (float) $pagos[0]['importe'] && '2026-10-07' === $pagos[0]['fecha'] && 'banco' === $pagos[0]['origen'], 'Tesorería registra el pago con el ingreso del banco: con su fecha y su importe (no los escritos a mano)' );
	$ok( BancoTable::CONCILIADO === $mb['estado'] && (int) $fam2['id'] === (int) $mb['familia_id'] && (int) $pagos[0]['id'] === (int) $mb['pago_id'] && str_contains( $avisos, 'queda conciliado con esta familia' ), 'y el movimiento queda conciliado con la familia y enlazado al pago' );
	list( $avisos ) = $enviar( new \AsdeBalears\AreaPrivada\Frontend\Views\TesoreriaView(), array( 'asde_action' => 'registrar_pago', 'familia' => (string) $fam2['id'], 'ronda' => '2026-2027', 'banco' => (string) $con_ref['id'], 'fecha' => '2026-10-07', 'importe' => '120' ), 'asde_mov_' . $fam2['id'] );
	$ok( str_contains( $avisos, 'ya está revisado' ) && 1 === count( MovimientosTable::de_familia( (int) $fam2['id'], '2026-2027' ) ), 'el mismo ingreso no se puede usar dos veces' );
	list( $avisos ) = $enviar( new \AsdeBalears\AreaPrivada\Frontend\Views\TesoreriaView(), array( 'asde_action' => 'registrar_pago', 'familia' => (string) $fam2['id'], 'ronda' => '2026-2027', 'banco' => '', 'fecha' => '2026-10-10', 'importe' => '15,50', 'metodo' => 'efectivo' ), 'asde_mov_' . $fam2['id'] );
	$ok( 2 === count( MovimientosTable::de_familia( (int) $fam2['id'], '2026-2027' ) ) && str_contains( $avisos, '15,50' ), 'sin elegir movimiento, el pago a mano sigue como siempre' );

	$cfg  = \AsdeBalears\AreaPrivada\Tesoreria\Config::de( '2026-2027' );
	$vars = array(
		'familia'     => $fam2,
		'personas'    => array(),
		'calc'        => \AsdeBalears\AreaPrivada\Tesoreria\Calculadora::familia( $fam2, array(), $cfg, MovimientosTable::totales( '2026-2027' )[ $fam2['id'] ] ?? array() ),
		'cfg'         => $cfg,
		'ronda'       => '2026-2027',
		'movimientos' => array(),
		'banco'       => null,
		'cuenta'      => null,
		'url'         => home_url( '/' ),
		'url_editar'  => home_url( '/' ),
	);
	$h = \AsdeBalears\AreaPrivada\Frontend\TemplateLoader::render( 'tesoreria/familia', $vars );
	$ok( str_contains( $h, '<details class="asde-plegable" open data-asde-recordar="tesoreria-cuota-ronda">' ) && str_contains( $h, 'asde-plegable__resumen asde-plegable__resumen--cerrado' ), '«Cuota de la ronda» se puede plegar (abierto de entrada; plegado enseña el resumen)' );

	echo "\n== Parte E · Buscar el ingreso del banco en una ventana (v0.20.1) ==\n";
	$importar(
		array(
			array( 'fecha' => '2026-10-11', 'importe' => 60.0, 'concepto' => 'PRUEBA-BG cuota octubre', 'ordenante' => 'GARCIA LOPEZ MARIA' ),
			array( 'fecha' => '2026-10-12', 'importe' => 30.0, 'concepto' => 'PRUEBA-BG campamento Pablo García' ),
			array( 'fecha' => '2026-10-13', 'importe' => 45.0, 'concepto' => 'PRUEBA-BG aportacion', 'ordenante' => 'MARIANO GARCIAS PONS' ),
		)
	);
	$por_tutor = $mov( 'cuota octubre' );
	$por_socio = $mov( 'campamento Pablo' );
	$parecido  = $mov( 'aportacion' );
	$personas  = array(
		new Persona( 'p-bg', '999902', '', 'PABLO', 'García López', '2014-01-01', '', '', array(), '', array( 1 => array( 'nombre' => 'María José', 'apellidos' => 'García López', 'email' => '' ), 2 => array( 'nombre' => 'Juan', 'apellidos' => 'de la Fuente Ruiz', 'email' => '' ) ) ),
	);
	$claves = Conciliacion::claves( $personas );
	$ok( array( 'Pablo García', 'María García', 'Juan Fuente' ) === $claves, 'los nombres para buscar: nombre y primer apellido del socio y de sus tutores, sin partículas («de la»)' );
	$lista  = Conciliacion::pendientes_para( $fam2, $claves );
	$por_id = array_column( $lista, null, 'id' );
	$ok( $por_id[ $por_tutor['id'] ]['sugerido'] && str_contains( $por_id[ $por_tutor['id'] ]['motivo'], 'María García' ), 'sugiere el ingreso cuyo ordenante es un tutor (apellidos delante, en mayúsculas y sin acentos)' );
	$ok( $por_id[ $por_socio['id'] ]['sugerido'] && str_contains( $por_id[ $por_socio['id'] ]['motivo'], 'Pablo García' ), 'y el que lleva el nombre del socio en el concepto' );
	$ok( ! $por_id[ $parecido['id'] ]['sugerido'] && ! $por_id[ $sin_ref['id'] ]['sugerido'], 'pero no uno con palabras parecidas («Mariano Garcías») ni uno sin nombre' );
	$sug = array_column( $lista, 'sugerido' );
	$ok( array_values( array_merge( array_filter( $sug ), array_filter( $sug, static fn( $s ) => ! $s ) ) ) === $sug, 'los sugeridos salen primero' );
	$t = $por_id[ $por_tutor['id'] ]['texto'];
	$ok( Conciliacion::coincide( $t, 'maría garcía' ) && Conciliacion::coincide( $t, 'Garcia MARIA' ) && Conciliacion::coincide( $t, 'garc' ) && Conciliacion::coincide( $t, '60,00' ) && Conciliacion::coincide( $t, '11/10/2026' ) && Conciliacion::coincide( $t, '' ), 'el buscador encuentra por nombre (en cualquier orden, sin acentos ni mayúsculas, o un trozo), por importe y por fecha' );
	$ok( ! Conciliacion::coincide( $t, 'maria pablo' ), 'y tienen que estar todas las palabras' );

	// La ficha: un botón que abre la ventana, y el ingreso elegido.
	$vista      = new \AsdeBalears\AreaPrivada\Frontend\Views\TesoreriaView();
	$banco_para = new ReflectionMethod( $vista, 'banco_para' );
	$_GET       = array( 'banco' => (string) $por_tutor['id'] );
	$b          = $banco_para->invoke( $vista, $fam2, $personas, '2026-2027' );
	$_GET       = array( 'banco' => (string) $con_ref['id'] );
	$b_conc     = $banco_para->invoke( $vista, $fam2, $personas, '2026-2027' );
	$_GET       = array();
	$ok( $b['elegido'] && (int) $por_tutor['id'] === (int) $b['elegido']['id'] && null === $b_conc['elegido'] && $b['sugeridos'] >= 2 && $b['pendientes'] >= 4 && str_contains( $b['url'], 'elegir_banco=1' ), 'la ficha sabe cuántos ingresos hay y cuántos se sugieren, y trae el elegido con ?banco= (si sigue pendiente)' );
	$vars['banco'] = array(
		'pendientes' => 4,
		'sugeridos'  => 2,
		'elegido'    => null,
		'url'        => home_url( '/?familia=1&elegir_banco=1' ),
		'url_quitar' => home_url( '/?familia=1' ),
	);
	$h = \AsdeBalears\AreaPrivada\Frontend\TemplateLoader::render( 'tesoreria/familia', $vars );
	$ok( 1 === preg_match( '/<a class="button asde-button-secundario" href="[^"]*elegir_banco=1[^"]*" data-asde-modal>/', $h ) && str_contains( $h, 'Buscar en el banco…' ) && str_contains( $h, '<input type="hidden" name="banco" value=""' ) && ! str_contains( $h, '<select id="asde-pago-banco"' ), 'en «Registrar pago», un botón que abre la ventana para buscar el ingreso (ya no un desplegable)' );
	$ok( str_contains( $h, '4 ingresos por conciliar' ) && str_contains( $h, '2 sugeridos para esta familia' ), 'con cuántos hay por conciliar y cuántos se sugieren para la familia' );
	$ok( str_contains( $h, '<div data-asde-parcial="familia">' ), 'la ficha de la familia es una zona que se actualiza sin recargar la página: registrar o conciliar un pago solo la cambia a ella (v0.25)' );
	$vars['banco']['elegido'] = BancoTable::obtener( (int) $por_tutor['id'] );
	$h = \AsdeBalears\AreaPrivada\Frontend\TemplateLoader::render( 'tesoreria/familia', $vars );
	$ok( str_contains( $h, 'name="banco" value="' . $por_tutor['id'] . '"' ) && 1 === preg_match( '/name="fecha" value="2026-10-11" required readonly>/', $h ) && 1 === preg_match( '/name="importe"[^>]*value="60.00" required readonly>/', $h ) && 1 === preg_match( '/name="metodo" disabled>/', $h ) && str_contains( $h, 'GARCIA LOPEZ MARIA' ), 'sin JavaScript, al volver de la ventana con un ingreso, la ficha lo trae puesto (fecha e importe fijos, transferencia)' );
	$vars['banco'] = null;
	$ok( ! str_contains( \AsdeBalears\AreaPrivada\Frontend\TemplateLoader::render( 'tesoreria/familia', $vars ), 'name="banco"' ), 'quien no ve el banco no tiene el botón' );

	// La ventana: buscador, atajos con los nombres, sugeridos primero y «Elegir».
	$tv = array(
		'familia' => $fam2,
		'ronda'   => '2026-2027',
		'movs'    => $lista,
		'claves'  => $claves,
		'q'       => '',
		'url'     => home_url( '/' ),
		'volver'  => home_url( '/?familia=' . $fam2['id'] ),
	);
	$h = \AsdeBalears\AreaPrivada\Frontend\TemplateLoader::render( 'tesoreria/familia-banco', $tv );
	$ok( str_contains( $h, 'data-asde-banco-q' ) && str_contains( $h, 'name="elegir_banco" value="1"' ) && str_contains( $h, 'data-asde-banco-atajo="María García"' ) && str_contains( $h, 'data-asde-banco-atajo="Pablo García"' ), 'la ventana tiene el buscador y un atajo con el nombre de cada socio y tutor' );
	$ok( 1 === preg_match( '/Sugeridos para esta familia.*GARCIA LOPEZ MARIA.*Otros ingresos por conciliar.*ingreso sin referencia/s', $h ) && str_contains( $h, 'banco=' . $por_tutor['id'] ) && ! preg_match( '/<tr data-asde-banco-fila[^>]* hidden>/', $h ), 'primero los sugeridos y después los demás, todos a la vista, y «Elegir» vuelve a la ficha con el ingreso' );
	$tv['q'] = 'pablo';
	$h       = \AsdeBalears\AreaPrivada\Frontend\TemplateLoader::render( 'tesoreria/familia-banco', $tv );
	preg_match_all( '/<tr data-asde-banco-fila[^>]*>/', $h, $filas );
	$vistas = array_filter( $filas[0], static fn( $f ) => ! str_ends_with( $f, ' hidden>' ) );
	$ok( 1 === count( $vistas ) && str_contains( (string) current( $vistas ), 'pablo' ) && str_contains( $h, 'data-asde-banco-grupo hidden' ) && str_contains( $h, '<p data-asde-banco-vacio hidden>' ), 'sin JavaScript, buscar «pablo» deja a la vista solo ese ingreso (los demás y su grupo, ocultos)' );
	$tv['q'] = 'zzzz';
	$h       = \AsdeBalears\AreaPrivada\Frontend\TemplateLoader::render( 'tesoreria/familia-banco', $tv );
	$ok( str_contains( $h, 'Ningún ingreso coincide con la búsqueda.' ) && ! str_contains( $h, '<p data-asde-banco-vacio hidden>' ), 'si nada coincide, lo dice' );
	$ok( in_array( 'tesoreria', Router::MODALES, true ), 'el control de pagos se puede abrir en la ventana emergente (para elegir el ingreso)' );
	$ve_banco = new ReflectionMethod( $vista, 've_banco' );
	$como( $u_scouter );
	$no_ve = ! $ve_banco->invoke( null );
	$como( $u_teso );
	$ok( $no_ve && $ve_banco->invoke( null ), 'la ventana solo la abre quien ve el banco (Tesorería sí, un scouter no)' );

	echo "\n== Parte F · Eliminar un pago con su motivo y el historial (v0.25) ==\n";
	$pago_banco = (int) BancoTable::obtener( (int) $con_ref['id'] )['pago_id'];
	$pagado     = static fn() => (float) ( MovimientosTable::totales( '2026-2027' )[ $fam2['id'] ]['pagado'] ?? 0 );
	$antes      = $pagado();
	list( $avisos ) = $enviar( new \AsdeBalears\AreaPrivada\Frontend\Views\TesoreriaView(), array( 'asde_action' => 'eliminar', 'familia' => (string) $fam2['id'], 'ronda' => '2026-2027', 'movimiento' => (string) $pago_banco, 'motivo' => '   ' ), 'asde_mov_' . $fam2['id'] );
	$ok( str_contains( $avisos, 'Escribe el motivo' ) && empty( MovimientosTable::obtener( $pago_banco )['anulado_en'] ), 'sin motivo no se elimina' );
	$ajena = \AsdeBalears\AreaPrivada\Tesoreria\Pagos::eliminar( $pago_banco, (int) $fam2['id'] + 100000, 'Prueba' );
	$ok( is_wp_error( $ajena ) && str_contains( $ajena->get_error_message(), 'no es de esta familia' ) && empty( MovimientosTable::obtener( $pago_banco )['anulado_en'] ), 'ni desde la ficha de otra familia' );
	list( $avisos ) = $enviar( new \AsdeBalears\AreaPrivada\Frontend\Views\TesoreriaView(), array( 'asde_action' => 'eliminar', 'familia' => (string) $fam2['id'], 'ronda' => '2026-2027', 'movimiento' => (string) $pago_banco, 'motivo' => 'Prueba: registrado dos veces' ), 'asde_mov_' . $fam2['id'] );
	$elim = MovimientosTable::obtener( $pago_banco );
	$mb   = BancoTable::obtener( (int) $con_ref['id'] );
	$ok( ! empty( $elim['anulado_en'] ) && 'Prueba: registrado dos veces' === $elim['anulado_motivo'] && $u_teso === (int) $elim['anulado_por'], 'Tesorería elimina el pago: no se borra, queda anulado con quién, cuándo y el motivo' );
	$ok( abs( $antes - 120.0 - $pagado() ) < 0.001, 'y deja de contar en lo pagado por la familia' );
	$ok( BancoTable::PENDIENTE === $mb['estado'] && empty( $mb['pago_id'] ) && empty( $mb['familia_id'] ) && str_contains( $avisos, 'eliminado. Queda en el historial de la familia.' ) && str_contains( $avisos, 'El ingreso del banco del 07/10/2026 vuelve a estar por conciliar.' ), 'el ingreso del banco con el que se registró vuelve a estar por conciliar (y el aviso lo dice)' );
	$ok( (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE id > %d AND accion = 'tesoreria_pago_eliminado' AND objeto_id = %s", Logger::table(), $log_antes, (string) $fam2['id'] ) ) >= 1, 'queda en el registro de actividad' );
	list( $avisos ) = $enviar( new \AsdeBalears\AreaPrivada\Frontend\Views\TesoreriaView(), array( 'asde_action' => 'eliminar', 'familia' => (string) $fam2['id'], 'ronda' => '2026-2027', 'movimiento' => (string) $pago_banco, 'motivo' => 'otra vez' ), 'asde_mov_' . $fam2['id'] );
	$ok( str_contains( $avisos, 'ya estaba eliminado' ) && 'Prueba: registrado dos veces' === MovimientosTable::obtener( $pago_banco )['anulado_motivo'], 'eliminarlo otra vez no cambia nada' );
	$sepa = (int) MovimientosTable::registrar( (int) $fam2['id'], '2026-2027', MovimientosTable::PAGO, '2026-10-20', 30.0, 'transferencia', 'PRUEBA-BG remesa', '', 'sepa' );
	list( $avisos ) = $enviar( new \AsdeBalears\AreaPrivada\Frontend\Views\TesoreriaView(), array( 'asde_action' => 'eliminar', 'familia' => (string) $fam2['id'], 'ronda' => '2026-2027', 'movimiento' => (string) $sepa, 'motivo' => 'Prueba: devuelto' ), 'asde_mov_' . $fam2['id'] );
	$ok( ! empty( MovimientosTable::obtener( $sepa )['anulado_en'] ) && str_contains( $avisos, 'Venía del cobro de una remesa SEPA' ) && ! str_contains( $avisos, 'vuelve a estar por conciliar' ), 'un pago del cobro de una remesa SEPA se elimina con un aviso (la remesa lo sigue dando por cobrado)' );

	$movs = MovimientosTable::de_familia( (int) $fam2['id'], '2026-2027' );
	$hist = \AsdeBalears\AreaPrivada\Tesoreria\Pagos::historial( $movs );
	$n_hist = static fn( string $accion ) => count( array_filter( $hist, static fn( $x ) => $accion === $x['accion'] ) );
	$ok( 5 === count( $hist ) && 'eliminado' === $hist[0]['accion'] && 3 === $n_hist( 'registrado' ) && 2 === $n_hist( 'eliminado' ) && $hist === array_values( wp_list_sort( $hist, 'cuando', 'DESC', true ) ), 'el historial: cada registro y cada eliminación, lo más reciente primero' );
	$vars['movimientos'] = $movs;
	$vars['historial']   = $hist;
	$h                   = \AsdeBalears\AreaPrivada\Frontend\TemplateLoader::render( 'tesoreria/familia', $vars );
	$vivo                = (int) current( wp_list_filter( $movs, array( 'anulado_en' => null ) ) )['id'];
	$ok( 1 === preg_match( '/name="movimiento" value="' . $vivo . '">.*name="motivo" maxlength="255" required/s', $h ) && ! str_contains( $h, 'name="movimiento" value="' . $pago_banco . '"' ) && ! str_contains( $h, 'name="movimiento" value="' . $sepa . '"' ), 'en la ficha, «Eliminar…» pide el motivo, y los eliminados ya no están entre los movimientos' );
	$ok( str_contains( $h, 'Historial de pagos' ) && str_contains( $h, '5 anotaciones · 2 eliminados' ) && str_contains( $h, 'Pago de 120,00 € eliminado' ) && str_contains( $h, 'Motivo: Prueba: registrado dos veces' ) && str_contains( $h, 'con un ingreso del banco' ) && str_contains( $h, 'con el cobro de una remesa SEPA' ), 'y el historial de la ronda cuenta qué se registró y qué se eliminó, con el motivo' );

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
