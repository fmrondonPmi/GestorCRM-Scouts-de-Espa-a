<?php
/**
 * Pruebas de la v0.60.2 (entorno LOCAL): receta nueva desde la pestaña «Menú» de un evento (opción «Nuevo» del desplegable de
 * platos): se crea en la biblioteca del grupo, se añade al menú, con permisos, nonce y sin dejar nada a medias. Ejecutar desde
 * app/public del site Local:
 *
 *   wp eval-file <ruta completa>/wp-content/plugins/asde-area-privada/tests/menus-receta-nueva.php
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
\AsdeBalears\AreaPrivada\Domain\Modulos::guardar( (string) $grupo['numero'], array_keys( \AsdeBalears\AreaPrivada\Domain\Modulos::lista() ) );
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
	$fin = wp_date( 'Y-m-d', strtotime( '+31 days' ) );
	$enviar(
		array( 'asde_action' => 'evento_crear', 'tipo' => 'acampada', 'nombre' => 'Acampada receta nueva', 'ambito' => 'secciones', 'secciones' => array( 'lobatos' ), 'inicio_fecha' => $ini, 'inicio_hora' => '18:00', 'fin_fecha' => $fin, 'fin_hora' => '16:00', 'lugar' => 'Albergue de prueba', 'previstas_lobatos' => '20', 'con_coste' => '1' ),
		'asde_evento_nuevo'
	);
	$e = EventosTable::obtener( $max_id( EventosTable::table() ) );
	$ok( $e && 'Acampada receta nueva' === $e['nombre'], 'evento de prueba creado' );
	$a   = Ambito::actual();
	$sal = IngredientesTable::por_nombre( 'Sal' );
	$ok( ! empty( $sal['id'] ), 'la biblioteca trae el ingrediente «Sal»' );
	$contar_recetas = static fn(): int => (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . RecetasTable::table() ); // phpcs:ignore WordPress.DB
	$contar_platos  = static fn(): int => (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . MenuTable::table() ); // phpcs:ignore WordPress.DB
	$lineas_ok      = array( array( 'ingrediente_id' => (int) $sal['id'], 'cantidad' => 5, 'unidad' => 'g' ) );
	$datos          = array( 'nombre' => 'Sopa nueva de prueba', 'tipo' => 'primero', 'porciones' => 10, 'elaboracion' => 'Hervir.', 'observaciones' => '' );

	echo "== Parte A · crear_y_anadir ==\n";
	$antes = $contar_recetas();
	$plato = MenuEvento::crear_y_anadir( $e, $a, $ini, 'cena', $datos, $lineas_ok );
	$ok( is_int( $plato ) && $plato > 0, 'crea la receta y devuelve el id del plato' );
	$ok( $contar_recetas() === $antes + 1, 'hay una receta más en la biblioteca' );
	$nueva = null;
	foreach ( RecetasTable::visibles() as $r ) {
		if ( 'Sopa nueva de prueba' === $r['nombre'] ) {
			$nueva = $r;
		}
	}
	$ok( $nueva && Grupo::numero() === $nueva['grupo'] && 10 === (int) $nueva['porciones'], 'la receta es del grupo y guarda las porciones' );
	$ok( $nueva && 1 === count( RecetasTable::lineas( $nueva['id'] ) ), 'con su ingrediente' );
	$platos = MenuEvento::platos( $e, $ini );
	$ok( 1 === count( $platos ) && 'Sopa nueva de prueba' === $platos[0]['receta']['nombre'] && 'cena' === $platos[0]['plato']['comida'], 'el plato está en el menú, en la comida elegida' );

	echo "\n== Parte B · Errores: no queda nada a medias ==\n";
	$antes = $contar_recetas();
	$men   = $contar_platos();
	$ok( is_wp_error( MenuEvento::crear_y_anadir( $e, $a, wp_date( 'Y-m-d', strtotime( '+90 days' ) ), 'cena', $datos, $lineas_ok ) ), 'un día fuera del evento se rechaza' );
	$ok( is_wp_error( MenuEvento::crear_y_anadir( $e, $a, $ini, 'brunch', $datos, $lineas_ok ) ), 'una comida inventada se rechaza' );
	$ok( is_wp_error( MenuEvento::crear_y_anadir( $e, $a, $ini, 'cena', array( 'nombre' => '', 'porciones' => 10 ), $lineas_ok ) ), 'sin nombre se rechaza' );
	$ok( is_wp_error( MenuEvento::crear_y_anadir( $e, $a, $ini, 'cena', $datos, array() ) ), 'sin ingredientes se rechaza' );
	$ok( is_wp_error( MenuEvento::crear_y_anadir( $e, $a, $ini, 'cena', $datos, array( array( 'ingrediente_id' => (int) $sal['id'], 'cantidad' => 0, 'unidad' => 'g' ) ) ) ), 'con cantidad cero se rechaza' );
	$ok( $contar_recetas() === $antes && $contar_platos() === $men, 'y no se crea ninguna receta ni plato' );

	echo "\n== Parte C · Permisos ==\n";
	$a_sco = $como( $u_sco );
	$ok( is_wp_error( MenuEvento::crear_y_anadir( $e, $a_sco, $ini, 'cena', $datos, $lineas_ok ) ) && ! MenuEvento::puede_crear_receta( $e, $a_sco ), 'un scouter de otra sección no puede' );
	$a_teso = $como( $u_teso );
	$ok( is_wp_error( MenuEvento::crear_y_anadir( $e, $a_teso, $ini, 'cena', $datos, $lineas_ok ) ), 'Tesorería no puede cambiar el menú' );
	$obj_lob = get_userdata( $u_lob ); // El mismo objeto para quitar la denegación (con caché de objetos, otro sería una copia vieja).
	$obj_lob->add_cap( \AsdeBalears\AreaPrivada\Menus\Recetario::CAP, false );
	wp_set_current_user( 0 ); // Si no, WordPress conserva el objeto del usuario actual con los permisos de antes.
	$a = $como( $u_lob );
	$ok( ! MenuEvento::puede_crear_receta( $e, $a ) && is_wp_error( MenuEvento::crear_y_anadir( $e, $a, $ini, 'cena', $datos, $lineas_ok ) ), 'sin el permiso del recetario no se crea (aunque pueda editar el menú)' );
	$obj_lob->remove_cap( \AsdeBalears\AreaPrivada\Menus\Recetario::CAP );
	wp_set_current_user( 0 );
	$a = $como( $u_lob );
	$ok( MenuEvento::puede_crear_receta( $e, $a ), 'con el permiso vuelve a poder' );

	echo "\n== Parte D · Pantalla y acción POST ==\n";
	$h = $pintar( array( 'evento' => (string) $e['id'], 'pestana' => 'menu' ) );
	$ok( str_contains( $h, 'value="nuevo"' ) && str_contains( $h, 'data-asde-menu-nueva' ), 'el desplegable de platos tiene «Nuevo» y la ventana' );
	$ok( 1 === substr_count( $h, '<dialog class="asde-modal asde-modal--mini asde-menu-nueva"' ), 'con una sola ventana para todas las comidas' );
	$ok( str_contains( $h, 'name="ing[]"' ) && str_contains( $h, 'name="cant[]"' ) && str_contains( $h, 'name="uni[]"' ), 'la ventana pide ingredientes con cantidad y unidad' );
	$ok( str_contains( $h, 'value="menu_receta_nueva"' ), 'y envía la acción menu_receta_nueva' );
	$antes = $contar_recetas();
	$notas = $enviar(
		array( 'asde_action' => 'menu_receta_nueva', 'evento' => (string) $e['id'], 'pestana' => 'menu', 'dia' => $ini, 'comida' => 'comida', 'nombre' => 'Arroz por POST de prueba', 'tipo' => 'segundo', 'porciones' => '12', 'ing' => array( (string) $sal['id'], '0' ), 'cant' => array( '2,5', '' ), 'uni' => array( 'g', 'g' ) ),
		'asde_evento_' . $e['id']
	);
	$ok( $contar_recetas() === $antes + 1 && str_contains( $notas, 'Recipe created and added to the menu.' ), 'por POST con su nonce se crea y se avisa' );
	$ok( 2 === count( MenuEvento::platos( $e, $ini ) ), 'el plato nuevo está en el menú (con el anterior)' );
	$cant = null;
	foreach ( RecetasTable::visibles() as $r ) {
		if ( 'Arroz por POST de prueba' === $r['nombre'] ) {
			$cant = RecetasTable::lineas( $r['id'] )[0]['cantidad'] ?? null;
		}
	}
	$ok( null !== $cant && abs( (float) $cant - 2.5 ) < 0.001, 'la cantidad con coma decimal se entiende (2,5)' );
	$h = $pintar( array( 'evento' => (string) $e['id'], 'pestana' => 'menu' ) );
	$ok( str_contains( $h, 'Arroz por POST de prueba' ) && str_contains( $h, 'Sopa nueva de prueba' ), 'el desplegable ya incluye las recetas nuevas' );

	// Nonce malo: wp_die se convierte en excepción para poder comprobarlo.
	$morir = static fn() => static function () {
		throw new RuntimeException( 'asde-die' );
	};
	add_filter( 'wp_die_handler', $morir );
	$antes = $contar_recetas();
	$enviar( array( 'asde_action' => 'menu_receta_nueva', 'evento' => (string) $e['id'], 'pestana' => 'menu', 'dia' => $ini, 'comida' => 'cena', 'nombre' => 'Sin nonce de prueba', 'porciones' => '5', 'ing' => array( (string) $sal['id'] ), 'cant' => array( '1' ), 'uni' => array( 'g' ) ), 'nonce-equivocado' );
	remove_filter( 'wp_die_handler', $morir );
	$ok( $contar_recetas() === $antes, 'con un nonce que no vale no se crea nada' );

	// Sin JavaScript, «Nuevo» manda al recetario.
	$notas = $enviar( array( 'asde_action' => 'menu_anadir', 'evento' => (string) $e['id'], 'pestana' => 'menu', 'dia' => $ini, 'comida' => 'cena', 'receta' => 'nuevo' ), 'asde_evento_' . $e['id'] );
	$ok( $contar_recetas() === $antes && str_contains( $notas, 'Create the recipe here' ), 'sin JavaScript, «Nuevo» no añade nada y avisa de ir al recetario' );

	$como( $u_teso );
	$h = $pintar( array( 'evento' => (string) $e['id'], 'pestana' => 'menu' ) );
	$ok( ! str_contains( $h, 'value="nuevo"' ), 'quien no edita el menú no ve «Nuevo»' );

	echo "\n== Parte E · Nuevo ingrediente desde la receta (v0.60.2) ==\n";
	$a = $como( $u_lob );
	$nonce_menus = wp_create_nonce( 'asde_menus' );
	$ing_antes_n = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . IngredientesTable::table() ); // phpcs:ignore WordPress.DB
	$r = \AsdeBalears\AreaPrivada\Frontend\Views\RecetasView::respuesta_ingrediente( $nonce_menus, array( 'nombre' => 'Calabacín de prueba', 'unidad' => 'kg', 'alergenos' => array( 'gluten', 'huevos' ), 'animal' => '1', 'revisado' => '1', 'coste' => '1,25' ) );
	$ok( 200 === $r['status'] && true === $r['cuerpo']['success'] && $r['cuerpo']['data']['id'] > 0, 'crear el ingrediente responde con su id' );
	$ing = IngredientesTable::por_nombre( 'Calabacín de prueba' );
	$ok( $ing && Grupo::numero() === $ing['grupo'] && 'kg' === $ing['unidad'] && array( 'gluten', 'huevos' ) === $ing['alergenos'] && $ing['animal'] && $ing['revisado'] && abs( (float) $ing['coste'] - 1.25 ) < 0.0001, 'queda en la biblioteca del grupo con unidad, alérgenos, origen, revisado y coste' );
	$ok( ( $r['cuerpo']['data']['nombre'] ?? '' ) === 'Calabacín de prueba' && in_array( \AsdeBalears\AreaPrivada\Menus\Alergenos::nombre( 'gluten' ), (array) $r['cuerpo']['data']['alergenos'], true ), 'la respuesta lleva el nombre y los alérgenos (para el desplegable)' );
	$r2 = \AsdeBalears\AreaPrivada\Frontend\Views\RecetasView::respuesta_ingrediente( $nonce_menus, array( 'nombre' => 'Calabacín de prueba', 'unidad' => 'g' ) );
	$ok( false === $r2['cuerpo']['success'] && '' !== $r2['cuerpo']['data']['mensaje'], 'el mismo nombre dos veces se rechaza con su mensaje' );
	$r3 = \AsdeBalears\AreaPrivada\Frontend\Views\RecetasView::respuesta_ingrediente( $nonce_menus, array( 'nombre' => '  ', 'unidad' => 'g' ) );
	$ok( false === $r3['cuerpo']['success'], 'sin nombre se rechaza' );
	$r4 = \AsdeBalears\AreaPrivada\Frontend\Views\RecetasView::respuesta_ingrediente( 'nonce-equivocado', array( 'nombre' => 'Sin nonce de prueba', 'unidad' => 'g' ) );
	$ok( 403 === $r4['status'] && false === $r4['cuerpo']['success'] && ! IngredientesTable::por_nombre( 'Sin nonce de prueba' ), 'con un nonce que no vale: 403 y no se crea nada' );
	$obj_lob->add_cap( \AsdeBalears\AreaPrivada\Menus\Recetario::CAP, false );
	wp_set_current_user( 0 );
	$a = $como( $u_lob );
	$r5 = \AsdeBalears\AreaPrivada\Frontend\Views\RecetasView::respuesta_ingrediente( wp_create_nonce( 'asde_menus' ), array( 'nombre' => 'Sin permiso de prueba', 'unidad' => 'g' ) );
	$ok( 403 === $r5['status'] && ! IngredientesTable::por_nombre( 'Sin permiso de prueba' ), 'sin el permiso del recetario: 403 y no se crea nada' );
	$obj_lob->remove_cap( \AsdeBalears\AreaPrivada\Menus\Recetario::CAP );
	wp_set_current_user( 0 );
	$a = $como( $u_lob );
	$ok( (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . IngredientesTable::table() ) === $ing_antes_n + 1, 'en total, un solo ingrediente nuevo' ); // phpcs:ignore WordPress.DB
	$con_nuevo = MenuEvento::crear_y_anadir( $e, $a, $ini, 'desayuno', array( 'nombre' => 'Receta con ingrediente nuevo de prueba', 'porciones' => 4 ), array( array( 'ingrediente_id' => (int) $ing['id'], 'cantidad' => 2, 'unidad' => 'kg' ) ) );
	$ok( is_int( $con_nuevo ), 'el ingrediente nuevo se puede usar en una receta del menú' );
	$h = $pintar( array( 'evento' => (string) $e['id'], 'pestana' => 'menu' ) );
	$ok( 1 === substr_count( $h, 'data-asde-ing-dialogo' ) && str_contains( $h, 'value="ingrediente_crear_json"' ) && str_contains( $h, 'data-asde-ing-nuevo' ), 'la ventana «Nuevo plato» trae el botón y la ventana de ingrediente (una sola)' );
	$ok( str_contains( $h, 'Calabacín de prueba' ), 'y el desplegable de ingredientes ya incluye el nuevo' );
	$_GET = array( 'receta' => 'nueva' );
	try {
		$hr = ( new \AsdeBalears\AreaPrivada\Frontend\Views\RecetasView() )->render();
	} finally {
		$_GET = array();
	}
	$ok( str_contains( $hr, 'data-asde-ing-nuevo' ) && str_contains( $hr, 'data-asde-ing-dialogo' ), 'el formulario de receta del recetario también trae el botón y la ventana' );
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
