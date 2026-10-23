<?php
/**
 * Pruebas de la v0.41, B2 (entorno LOCAL, doc 49): el menú de un evento — platos por día y comida, comensales, lista de la compra
 * con sumas y conversión de unidades, coste, propuesta de partida de alimentación, CSV, permisos y pestaña. Ejecutar desde app/public
 * del site Local:
 *
 *   wp eval-file <ruta completa>/wp-content/plugins/asde-area-privada/tests/menus-evento.php
 *
 * Con el CRM simulado (no toca el real). Al terminar se borra lo creado.
 */
defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/lib/CrmSimulado.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Auth\Ambito;
use AsdeBalears\AreaPrivada\Crm\Escritura;
use AsdeBalears\AreaPrivada\Domain\Grupo;
use AsdeBalears\AreaPrivada\Domain\Grupos;
use AsdeBalears\AreaPrivada\Eventos\EventosTable;
use AsdeBalears\AreaPrivada\Eventos\InscripcionesTable;
use AsdeBalears\AreaPrivada\Eventos\PartidasTable;
use AsdeBalears\AreaPrivada\Eventos\Servicio;
use AsdeBalears\AreaPrivada\Eventos\TareasTable;
use AsdeBalears\AreaPrivada\Frontend\Notices;
use AsdeBalears\AreaPrivada\Frontend\Views\EventosView;
use AsdeBalears\AreaPrivada\Install\Roles;
use AsdeBalears\AreaPrivada\Menus\IngredientesTable;
use AsdeBalears\AreaPrivada\Menus\MenuEvento;
use AsdeBalears\AreaPrivada\Menus\MenuTable;
use AsdeBalears\AreaPrivada\Menus\RecetasTable;
use AsdeBalears\AreaPrivada\Socios\SociosTable;
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
foreach ( get_users( array( 'search' => 'prueba-me-*', 'search_columns' => array( 'user_login' ) ) ) as $u ) {
	exit( 'Ya existe el usuario ' . $u->user_login . " (¿una ejecución anterior interrumpida?). Bórralo antes de repetir la prueba.\n" );
}
wp_set_current_user( 0 );

$max_id       = static fn( string $t ) => (int) $wpdb->get_var( "SELECT COALESCE(MAX(id), 0) FROM $t" ); // phpcs:ignore WordPress.DB
$log_antes    = $max_id( Logger::table() );
$ev_antes     = $max_id( EventosTable::table() );
$ins_antes    = $max_id( InscripcionesTable::table() );
$tar_antes    = $max_id( TareasTable::table() );
$par_antes    = $max_id( PartidasTable::table() );
$men_antes    = $max_id( MenuTable::table() );
$rec_antes    = $max_id( RecetasTable::table() );
$lin_antes    = $max_id( RecetasTable::table_lineas() );
$ing_antes    = $max_id( IngredientesTable::table() );
$socios_antes = array_column( (array) $wpdb->get_results( 'SELECT * FROM ' . SociosTable::table(), ARRAY_A ), null, 'num_socio_asde' ); // phpcs:ignore WordPress.DB
$modulos_antes = get_option( 'asde_ap_modulos', null );
$eventos_cfg_antes = get_option( 'asde_ap_eventos_' . $grupo['numero'], null ); // La prueba guarda la configuración de eventos del grupo (doc 60: antes no se restauraba).

$cache_externa = wp_using_ext_object_cache( true );
add_filter( 'pre_wp_mail', '__return_true' );
$cortar_crm = static function ( $pre, $args, $url ) {
	return false === $pre && str_contains( (string) $url, '/rest.php' ) ? new WP_Error( 'prueba', 'CRM real cortado durante la prueba' ) : $pre;
};
add_filter( 'pre_http_request', $cortar_crm, 2, 3 );

$P   = array( 'Asde_Prueba_Crm', 'persona' );
$crm = new Asde_Prueba_Crm(
	array(
		$P( 51, 'Ana', 'Menu Gil', '1990-01-01', 'Scouters' ),
		$P( 52, 'Tom', 'Menu Luz', '1985-05-05', 'Scouters' ),
	),
	$grupo['crm_id']
);
Grupo::forzar( $grupo );
\AsdeBalears\AreaPrivada\Domain\Modulos::guardar( (string) $grupo['numero'], array_keys( \AsdeBalears\AreaPrivada\Domain\Modulos::LISTA ) );
$crm->activar();
Escritura::usar_para_pruebas( $crm );
\AsdeBalears\AreaPrivada\Menus\Biblioteca::sembrar();

$ids   = array();
$crear = static function ( string $login, array $roles, array $meta ) use ( &$ids, $grupo ): int {
	$id = wp_insert_user( array( 'user_login' => $login, 'user_email' => '', 'user_pass' => wp_generate_password( 24 ), 'role' => $roles[0] ) );
	$u  = get_userdata( $id );
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
$u_lob  = $crear( 'prueba-me-lobatos', array( Roles::SCOUTER ), array( Usuarios::META_SOCIO => '990051', Usuarios::META_SECCION => 'lobatos' ) );
$u_sco  = $crear( 'prueba-me-scouts', array( Roles::SCOUTER ), array( Usuarios::META_SOCIO => '990052', Usuarios::META_SECCION => 'scouts' ) );
$u_teso = $crear( 'prueba-me-teso', array( Roles::TESORERIA ), array() );
$como   = static function ( int $uid ): Ambito {
	wp_set_current_user( $uid );
	Ambito::olvidar( $uid );
	Servicio::olvidar();
	return Ambito::actual();
};
add_filter( 'send_auth_cookies', '__return_false' );
$redirigir = static function ( $destino ) {
	throw new RuntimeException( 'asde-redirect:' . $destino );
};
add_filter( 'wp_redirect', $redirigir, 1 );
$enviar = static function ( array $campos, string $nonce ): string {
	$_POST    = $campos + array( '_wpnonce' => wp_create_nonce( $nonce ) );
	$_REQUEST = $_POST;
	try {
		( new EventosView() )->handle_post();
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
		return ( new EventosView() )->render();
	} finally {
		$_GET = array();
	}
};
$comun = static function ( string $nombre ): array {
	foreach ( RecetasTable::visibles() as $r ) {
		if ( $r['nombre'] === $nombre && IngredientesTable::COMUN === $r['grupo'] ) {
			return $r;
		}
	}
	return array();
};

try {
	\AsdeBalears\AreaPrivada\Eventos\Config::guardar( \AsdeBalears\AreaPrivada\Eventos\Config::defaults(), $grupo['numero'] );
	$como( $u_lob );
	$ini = wp_date( 'Y-m-d', strtotime( '+30 days' ) );
	$fin = wp_date( 'Y-m-d', strtotime( '+32 days' ) );
	$enviar(
		array( 'asde_action' => 'evento_crear', 'tipo' => 'acampada', 'nombre' => 'Acampada del menú', 'ambito' => 'secciones', 'secciones' => array( 'lobatos' ), 'inicio_fecha' => $ini, 'inicio_hora' => '18:00', 'fin_fecha' => $fin, 'fin_hora' => '16:00', 'lugar' => 'Albergue de prueba', 'previstas_lobatos' => '20', 'con_coste' => '1' ),
		'asde_evento_nuevo'
	);
	$e = EventosTable::obtener( $max_id( EventosTable::table() ) );
	$ok( $e && 'Acampada del menú' === $e['nombre'], 'evento de prueba creado (3 días, con coste)' );
	$a = Ambito::actual();

	echo "== Parte A · Días y comensales ==\n";
	$ok( array( $ini, wp_date( 'Y-m-d', strtotime( '+31 days' ) ), $fin ) === MenuEvento::dias( $e ), 'los días del menú son los del evento, del inicio al fin' );
	$ok( 1 === count( MenuEvento::dias( array( 'inicio' => $ini . ' 10:00:00', 'fin' => $ini . ' 12:00:00' ) ) ), 'un evento de un solo día tiene un día' );
	$ok( 45 === count( MenuEvento::dias( array( 'inicio' => $ini . ' 10:00:00', 'fin' => wp_date( 'Y-m-d', strtotime( '+200 days' ) ) . ' 10:00:00' ) ) ), 'y nunca pasa de 45 días' );
	$ok( 0 === MenuEvento::comensales( $e ), 'sin inscritos ni equipo, 0 comensales por defecto' );
	$enviar( array( 'asde_action' => 'evento_equipo_anadir', 'evento' => (string) $e['id'], 'pestana' => 'equipo', 'socio' => '990051', 'papel' => 'director' ), 'asde_evento_' . $e['id'] );
	$ok( 1 === MenuEvento::comensales( $e ), 'el equipo cuenta como comensales' );

	echo "\n== Parte B · Añadir platos ==\n";
	$lent = $comun( 'Lentejas estofadas' );
	$mac  = $comun( 'Macarrones con tomate' );
	$d1   = $ini;
	$d2   = wp_date( 'Y-m-d', strtotime( '+31 days' ) );
	$ok( is_int( MenuEvento::anadir( $e, $a, $d1, 'comida', $lent['id'], '42' ) ), 'se añade un plato de la biblioteca con 42 comensales' );
	$ok( is_wp_error( MenuEvento::anadir( $e, $a, wp_date( 'Y-m-d', strtotime( '+60 days' ) ), 'comida', $lent['id'] ) ), 'un día fuera del evento se rechaza' );
	$ok( is_wp_error( MenuEvento::anadir( $e, $a, $d1, 'brunch', $lent['id'] ) ), 'una comida inventada se rechaza' );
	$ok( is_wp_error( MenuEvento::anadir( $e, $a, $d1, 'cena', 999999 ) ), 'una receta que no existe se rechaza' );
	$ok( is_wp_error( MenuEvento::anadir( $e, $a, $d1, 'cena', $lent['id'], '0' ) ) && is_wp_error( MenuEvento::anadir( $e, $a, $d1, 'cena', $lent['id'], '9000' ) ), 'los comensales van de 1 a 5000' );
	$propia = RecetasTable::guardar( array( 'nombre' => 'Archivada de prueba', 'porciones' => 4 ), array( array( 'ingrediente_id' => IngredientesTable::por_nombre( 'Sal' )['id'], 'cantidad' => 1, 'unidad' => 'g' ) ) );
	RecetasTable::activar( (int) $propia, false );
	$ok( is_wp_error( MenuEvento::anadir( $e, $a, $d1, 'cena', (int) $propia ) ), 'una receta archivada no se puede añadir' );
	$a_sco = $como( $u_sco );
	$ok( ! MenuEvento::puede_ver( $e, $a_sco ) && is_wp_error( MenuEvento::anadir( $e, $a_sco, $d1, 'cena', $lent['id'] ) ), 'un scouter de otra sección no ve ni cambia el menú' );
	$a_teso = $como( $u_teso );
	$ok( ! MenuEvento::puede_editar( $e, $a_teso ), 'Tesorería tampoco lo cambia' );
	$a = $como( $u_lob );

	echo "\n== Parte C · Lista de la compra ==\n";
	MenuEvento::anadir( $e, $a, $d2, 'comida', $mac['id'], '42' );
	$c   = MenuEvento::compra( $e );
	$por = array_column( $c['filas'], 'texto', 'nombre' );
	$ok( '3,36 kg' === $por['Lentejas'] && '2,52 kg' === $por['Tomate frito'] && '4,2 cucharadas' === $por['Pimentón'], 'lentejas 3,36 kg, tomate 2,52 kg y pimentón 4,2 cucharadas (800 g × 42 ÷ 10…)' );
	$ok( '546 ml' === $por['Aceite de oliva'], 'el aceite suma las dos recetas: 420 ml + 126 ml = 546 ml' );
	$ok( 1 === count( array_filter( $c['filas'], static fn( $f ) => 'Aceite de oliva' === $f['nombre'] ) ), 'y sale una sola vez' );
	$solo1 = array_column( MenuEvento::compra( $e, $d1 )['filas'], 'texto', 'nombre' );
	$ok( '420 ml' === $solo1['Aceite de oliva'] && ! isset( $solo1['Tomate frito'] ), 'la lista de un día solo lleva lo de ese día' );
	MenuEvento::anadir( $e, $a, $d2, 'cena', $mac['id'], '42' );
	$por = array_column( MenuEvento::compra( $e )['filas'], 'texto', 'nombre' );
	$ok( '5,04 kg' === $por['Tomate frito'] && '1,26 kg' === $por['Queso rallado'], 'el mismo plato dos veces suma: tomate 5,04 kg y queso 2 × 630 g = 1,26 kg (g y kg se juntan)' );

	echo "\n== Parte D · Coste ==\n";
	$i_a = IngredientesTable::guardar( array( 'nombre' => 'Coste A de prueba', 'unidad' => 'kg', 'coste' => '2', 'revisado' => true ) );
	$i_b = IngredientesTable::guardar( array( 'nombre' => 'Coste B de prueba', 'unidad' => 'ud', 'coste' => '0,2', 'revisado' => true ) );
	$i_c = IngredientesTable::guardar( array( 'nombre' => 'Sin coste de prueba', 'unidad' => 'g', 'revisado' => true ) );
	$i_e = IngredientesTable::guardar( array( 'nombre' => 'Coste en litros de prueba', 'unidad' => 'l', 'coste' => '1,5', 'revisado' => true ) );
	$i_x = IngredientesTable::guardar( array( 'nombre' => 'Coste incompatible de prueba', 'unidad' => 'kg', 'coste' => '3', 'revisado' => true ) );
	$rc  = RecetasTable::guardar(
		array( 'nombre' => 'Receta de coste de prueba', 'porciones' => 10 ),
		array(
			array( 'ingrediente_id' => (int) $i_a, 'cantidad' => 500, 'unidad' => 'g' ),
			array( 'ingrediente_id' => (int) $i_b, 'cantidad' => 4, 'unidad' => 'ud' ),
			array( 'ingrediente_id' => (int) $i_c, 'cantidad' => 100, 'unidad' => 'g' ),
			array( 'ingrediente_id' => (int) $i_e, 'cantidad' => 250, 'unidad' => 'ml' ),
			array( 'ingrediente_id' => (int) $i_x, 'cantidad' => 2, 'unidad' => 'ud' ),
		)
	);
	$d3 = $fin;
	MenuEvento::anadir( $e, $a, $d3, 'cena', (int) $rc, '20' );
	$c3 = MenuEvento::compra( $e, $d3 );
	$ok( abs( $c3['total'] - 4.35 ) < 0.001, 'coste del día: A 1 kg × 2 + B 8 ud × 0,2 + 0,5 l × 1,5 = 4,35 €' );
	$ok( 2 === count( $c3['sin_coste'] ) && in_array( 'Sin coste de prueba', $c3['sin_coste'], true ) && in_array( 'Coste incompatible de prueba', $c3['sin_coste'], true ), 'lo que no tiene coste (o está en otra unidad) no suma y se avisa' );
	$dias = MenuEvento::coste_por_dia( $e );
	$ok( isset( $dias[ $d3 ] ) && abs( $dias[ $d3 ] - 4.35 ) < 0.001 && array_key_exists( $d1, $dias ), 'coste por día' );
	$ok( 0.0 === $dias[ $d1 ], 'un día con platos sin coste anotado sale a 0' );

	echo "\n== Parte E · Partida del presupuesto ==\n";
	$a_sco = $como( $u_sco );
	$ok( is_wp_error( MenuEvento::proponer_partida( $e, $a_sco ) ), 'quien no gestiona el evento no propone partidas' );
	$a = $como( $u_lob );
	$antes_partidas = count( PartidasTable::previstas( $e['id'] ) );
	$r = MenuEvento::proponer_partida( $e, $a );
	$partidas = PartidasTable::previstas( $e['id'] );
	$ok( is_int( $r ) && count( $partidas ) === $antes_partidas + 1, 'se propone la partida de alimentación' );
	$ultima = end( $partidas );
	$ok( 'Alimentación (menú del evento)' === $ultima['concepto'] && abs( (float) $ultima['importe'] - MenuEvento::compra( $e )['total'] ) < 0.01 && 'gasto' === $ultima['tipo'], 'es un gasto con el coste de todo el menú' );
	$ok( is_wp_error( MenuEvento::proponer_partida( $e, $a ) ) && count( PartidasTable::previstas( $e['id'] ) ) === $antes_partidas + 1, 'no se duplica: hay que quitar la anterior' );
	$ok( is_wp_error( MenuEvento::proponer_partida( array_merge( $e, array( 'id' => 999999 ) ), $a ) ), 'un evento sin menú no propone nada' );

	echo "\n== Parte F · CSV ==\n";
	$csv = MenuEvento::csv( $e );
	$ok( str_starts_with( $csv, "\xEF\xBB\xBF" ) && str_contains( $csv, '"Ingrediente";"Cantidad";"Unidad";"Coste"' ) && str_contains( $csv, '"Lentejas";"3,36";"kg"' ), 'el CSV lleva la cabecera y las filas (lentejas 3,36 kg)' );
	$ok( ! str_contains( MenuEvento::csv( $e, $d1 ), 'Tomate frito' ), 'el CSV de un día solo lleva lo de ese día' );

	echo "\n== Parte G · Pestaña y formularios ==\n";
	$h = $pintar( array( 'evento' => (string) $e['id'], 'pestana' => 'menu' ) );
	$ok( str_contains( $h, 'Menú del evento' ) && str_contains( $h, 'Lista de la compra' ) && str_contains( $h, 'Lentejas estofadas' ) && str_contains( $h, 'Descargar CSV' ) && str_contains( $h, 'menu_anadir' ), 'quien gestiona ve el menú, la lista de la compra y los formularios' );
	$ok( str_contains( $h, 'Contiene: Gluten' ), 'cada plato enseña los alérgenos de la receta' );
	$ok( ! str_contains( $h, 'Hugo' ) && ! preg_match( '/alergia|intolerancia/i', $h ) , 'no sale nada de salud de personas' );
	$a_sco = $como( $u_sco );
	$h2 = $pintar( array( 'evento' => (string) $e['id'], 'pestana' => 'menu' ) );
	$ok( ! str_contains( $h2, 'Lista de la compra' ), 'quien no gestiona el evento no ve el menú' );
	$como( $u_teso );
	$h3 = $pintar( array( 'evento' => (string) $e['id'], 'pestana' => 'menu' ) );
	$ok( ! str_contains( $h3, 'Lista de la compra' ), 'Tesorería ve el evento pero no el menú' );
	$como( $u_lob );
	$n_antes = count( MenuTable::de_evento( $e['id'] ) );
	$enviar( array( 'asde_action' => 'menu_anadir', 'evento' => (string) $e['id'], 'pestana' => 'menu', 'dia' => $d3, 'comida' => 'desayuno', 'receta' => (string) $comun( 'Yogur con fruta' )['id'], 'comensales' => '' ), 'asde_evento_' . $e['id'] );
	$platos = MenuTable::de_evento( $e['id'] );
	$yogur  = $comun( 'Yogur con fruta' )['id'];
	$nuevo  = array_values( array_filter( $platos, static fn( $p ) => $p['receta_id'] === $yogur ) )[0] ?? array( 'id' => 0, 'comensales' => 1, 'comida' => '' );
	$ok( count( $platos ) === $n_antes + 1 && null === $nuevo['comensales'] && 'desayuno' === $nuevo['comida'], 'el formulario añade un plato (comensales por defecto)' );
	$enviar( array( 'asde_action' => 'menu_comensales', 'evento' => (string) $e['id'], 'pestana' => 'menu', 'plato' => (string) $nuevo['id'], 'comensales' => '15' ), 'asde_evento_' . $e['id'] );
	$ok( 15 === MenuTable::obtener( $nuevo['id'] )['comensales'], 'cambiar los comensales de un plato' );
	$enviar( array( 'asde_action' => 'menu_quitar', 'evento' => (string) $e['id'], 'pestana' => 'menu', 'plato' => (string) $nuevo['id'] ), 'asde_evento_' . $e['id'] );
	$ok( null === MenuTable::obtener( $nuevo['id'] ), 'quitar un plato' );
	$como( $u_sco );
	$enviar( array( 'asde_action' => 'menu_anadir', 'evento' => (string) $e['id'], 'pestana' => 'menu', 'dia' => $d3, 'comida' => 'cena', 'receta' => (string) $lent['id'] ), 'asde_evento_' . $e['id'] );
	$ok( count( MenuTable::de_evento( $e['id'] ) ) === $n_antes, 'un formulario enviado por quien no gestiona el evento no cambia nada' );
	$como( $u_lob );

	echo "\n== Parte H · Borrar el evento ==\n";
	$ok( MenuTable::contar( $e['id'] ) > 0, 'el evento tiene platos' );
	foreach ( PartidasTable::previstas( $e['id'] ) as $p ) {
		PartidasTable::quitar( $p['id'], $e['id'] );
	}
	$r = Servicio::borrar( EventosTable::obtener( $e['id'] ), $a );
	$ok( true === $r && 0 === MenuTable::contar( $e['id'] ), 'al borrar el borrador se borra su menú' );
} finally {
	remove_filter( 'wp_redirect', $redirigir, 1 );
	remove_filter( 'send_auth_cookies', '__return_false' );
	remove_filter( 'pre_wp_mail', '__return_true' );
	remove_filter( 'pre_http_request', $cortar_crm, 2 );
	$_POST = array();
	$_GET  = array();
	$crm->desactivar();
	wp_using_ext_object_cache( $cache_externa );
	wp_set_current_user( 0 );

	$lista = implode( ',', array_map( 'intval', $ids ) ) ?: '0';
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . MenuTable::table() . ' WHERE id > %d', $men_antes ) ); // phpcs:ignore WordPress.DB
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . RecetasTable::table_lineas() . ' WHERE id > %d', $lin_antes ) ); // phpcs:ignore WordPress.DB
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . RecetasTable::table() . ' WHERE id > %d', $rec_antes ) ); // phpcs:ignore WordPress.DB
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . IngredientesTable::table() . ' WHERE id > %d', $ing_antes ) ); // phpcs:ignore WordPress.DB
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . TareasTable::table() . ' WHERE id > %d', $tar_antes ) ); // phpcs:ignore WordPress.DB
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . PartidasTable::table() . ' WHERE id > %d', $par_antes ) ); // phpcs:ignore WordPress.DB
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . InscripcionesTable::table() . ' WHERE id > %d', $ins_antes ) ); // phpcs:ignore WordPress.DB
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . EventosTable::table() . ' WHERE id > %d', $ev_antes ) ); // phpcs:ignore WordPress.DB
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Logger::table() . " WHERE id > %d AND ( user_id IN ($lista) OR objeto IN ( 'evento', 'menus' ) )", $log_antes ) ); // phpcs:ignore WordPress.DB
	foreach ( $ids as $uid ) {
		delete_transient( 'asde_ap_notices_' . $uid );
		Ambito::olvidar( (int) $uid );
		wp_delete_user( (int) $uid );
	}
	if ( null === $modulos_antes ) {
		delete_option( 'asde_ap_modulos' );
	} else {
		update_option( 'asde_ap_modulos', $modulos_antes );
	}
	$ahora = array_column( (array) $wpdb->get_results( 'SELECT * FROM ' . SociosTable::table(), ARRAY_A ), null, 'num_socio_asde' ); // phpcs:ignore WordPress.DB
	foreach ( array_diff_key( $ahora, $socios_antes ) as $asde => $f ) {
		$wpdb->delete( SociosTable::table(), array( 'num_socio_asde' => (string) $asde ) );
	}
	foreach ( $socios_antes as $asde => $f ) {
		if ( ( $ahora[ $asde ] ?? null ) !== $f ) {
			$wpdb->replace( SociosTable::table(), $f );
		}
	}
	if ( null === $eventos_cfg_antes ) {
		delete_option( 'asde_ap_eventos_' . $grupo['numero'] );
	} else {
		update_option( 'asde_ap_eventos_' . $grupo['numero'], $eventos_cfg_antes, false );
	}
	Grupo::forzar( null );

	$quedan = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users} WHERE user_login LIKE 'prueba-me-%'" ); // phpcs:ignore WordPress.DB
	$ok( 0 === $quedan && 0 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . EventosTable::table() . ' WHERE id > ' . (int) $ev_antes ) && 0 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . MenuTable::table() . ' WHERE id > ' . (int) $men_antes ), 'limpieza: sin usuarios, eventos ni menús de la prueba' ); // phpcs:ignore WordPress.DB
}

echo $fails ? "\n$fails FALLOS\n" : "\nTODO OK (nada tocado en el CRM real; el sitio queda como estaba)\n";
