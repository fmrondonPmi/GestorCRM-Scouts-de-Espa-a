<?php
/**
 * Pruebas de la v0.12.2 (entorno LOCAL): listado de usuarios que filtra, ordena y
 * pagina en el navegador con los datos en memoria. Ejecutar desde app/public del site Local:
 *
 *   wp eval-file <ruta completa>/wp-content/plugins/asde-area-privada/tests/usuarios-listado.php
 *
 * Comprueba la parte del servidor (los nombres de las personas, las filas con
 * los datos para filtrar, la lista y las acciones en JSON y el tamaño de página);
 * el comportamiento del navegador se revisa a mano (docs/06).
 * Usa un CRM simulado (tests/lib/CrmSimulado.php): ninguna petición llega al CRM
 * real. Datos inventados (@example.invalid). Ningún email sale (pre_wp_mail).
 * Al terminar se borra lo creado y se restauran las cachés.
 */
defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/lib/CrmSimulado.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Auth\Ambito;
use AsdeBalears\AreaPrivada\Crm\Escritura;
use AsdeBalears\AreaPrivada\Domain\Grupo;
use AsdeBalears\AreaPrivada\Domain\Grupos;
use AsdeBalears\AreaPrivada\Frontend\Router;
use AsdeBalears\AreaPrivada\Frontend\Tabla;
use AsdeBalears\AreaPrivada\Frontend\Views\UsuariosView;
use AsdeBalears\AreaPrivada\Install\Roles;
use AsdeBalears\AreaPrivada\Usuarios\EmailCuenta;
use AsdeBalears\AreaPrivada\Usuarios\Listado;
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

$grupo = array_values( Grupos::todos() )[0] ?? null;
if ( ! $grupo || '' === $grupo['crm_id'] ) {
	exit( "Hace falta un grupo con la conexión al CRM comprobada.\n" );
}
foreach ( get_users( array( 'search' => 'prueba-lis-*', 'search_columns' => array( 'user_login' ) ) ) as $u ) {
	exit( 'Ya existe el usuario ' . $u->user_login . " (¿una ejecución anterior interrumpida?). Bórralo antes de repetir la prueba.\n" );
}
wp_set_current_user( 0 );

$max_id       = static fn( string $t ) => (int) $wpdb->get_var( "SELECT COALESCE(MAX(id), 0) FROM $t" ); // phpcs:ignore WordPress.DB
$log_antes    = $max_id( Logger::table() );
$emails_antes = $max_id( RegistroEmails::table() );
$socios_antes = array();
foreach ( (array) $wpdb->get_results( 'SELECT * FROM ' . \AsdeBalears\AreaPrivada\Socios\SociosTable::table(), ARRAY_A ) as $f ) { // phpcs:ignore WordPress.DB
	$socios_antes[ $f['num_socio_asde'] ] = $f; // El listado analiza la numeración y sincroniza esta tabla con el CRM simulado.
}

add_filter( 'pre_wp_mail', '__return_true' );

$P   = array( 'Asde_Prueba_Crm', 'persona' );
$crm = new Asde_Prueba_Crm(
	array(
		$P( 1, 'Leo', 'Prueba Sanz', '2016-05-10', 'Lobatos' ),
		$P( 2, 'Nora', 'Prueba Sanz', '2014-02-20', 'Scouts' ),
		$P( 3, 'Pol', 'Prueba Mas', '2011-03-03', 'Escultas' ),
		$P( 4, 'Eva', 'Prueba Gil', '1990-01-01', 'Scouters' ),
		$P( 5, 'Iris', 'Prueba Roca', '2012-07-07', 'Scouts' ),
		// v0.25.1: un cargo del CRM que no está en la lista (daba error 500 en «Dar acceso») y uno que sí.
		$P( 6, 'Tomás', 'Prueba Cargo', '1985-04-04', 'Scouters', array( 'cargoeducativo_c' => 'cargoinventadoGS' ) ),
		$P( 7, 'Clara', 'Prueba Cargo', '1986-06-06', 'Scouters', array( 'cargoeducativo_c' => 'secretariogrupoGS' ) ),
	),
	$grupo['crm_id']
);
$cortar_crm = static function ( $pre, $args, $url ) {
	return ( false === $pre && str_contains( (string) $url, '/rest.php' ) ) ? new WP_Error( 'prueba', 'CRM real cortado durante la prueba' ) : $pre;
};
add_filter( 'pre_http_request', $cortar_crm, 2, 3 );
$cache_externa = wp_using_ext_object_cache( true ); // Las cachés de la prueba, solo en la memoria de este proceso.
Grupo::forzar( $grupo );
$crm->activar();
Escritura::usar_para_pruebas( $crm );

$ids   = array();
$crear = static function ( string $login, array $roles, array $meta, bool $mismo_grupo = true ) use ( &$ids, $grupo ): int {
	$id = wp_insert_user(
		array(
			'user_login' => $login,
			'user_email' => '',
			'user_pass'  => wp_generate_password( 24 ),
			'role'       => $roles[0] ?? '',
		)
	);
	$u = get_userdata( $id );
	foreach ( array_slice( $roles, 1 ) as $r ) {
		$u->add_role( $r );
	}
	update_user_meta( $id, Usuarios::META_GRUPO, $mismo_grupo ? $grupo['id'] : 'otro-grupo' );
	foreach ( $meta as $k => $v ) {
		foreach ( (array) $v as $valor ) {
			add_user_meta( $id, $k, $valor );
		}
	}
	$ids[ $login ] = $id;
	return $id;
};
$u_secre = $crear( 'prueba-lis-secre', array( Roles::SCOUTER, Roles::SECRETARIA ), array( Usuarios::META_SOCIO => '990004', Usuarios::META_SECCION => 'rovers', Usuarios::META_ESTADO => Usuarios::ACTIVO ) );
$u_scout = $crear( 'prueba-lis-a', array( Roles::SCOUTER ), array( Usuarios::META_SOCIO => '990001', Usuarios::META_SECCION => 'lobatos', Usuarios::META_ESTADO => Usuarios::ACTIVO ) );
$u_fam   = $crear( 'prueba-lis-b', array( Roles::TUTOR ), array( Usuarios::META_REPRESENTADO => array( '990002', '990003' ), Usuarios::META_ESTADO => Usuarios::ACTIVO ) );
$u_inv   = $crear( 'prueba-lis-c', array( Roles::SCOUTER ), array( Usuarios::META_SOCIO => '990005', Usuarios::META_SECCION => 'scouts', Usuarios::META_ESTADO => Usuarios::INVITADO ) );
$u_baja  = $crear( 'prueba-lis-d', array(), array( Usuarios::META_SOCIO => '990001', Usuarios::META_ESTADO => Usuarios::DESACTIVADO ) );
$u_otro  = $crear( 'prueba-lis-z', array( Roles::SCOUTER ), array( Usuarios::META_SOCIO => '990001' ), false );
EmailCuenta::guardar( $u_inv, 'invitada.prueba@example.invalid' );

// Captura de wp_send_json_*: termina con wp_die() en AJAX, y aquí se convierte en excepción.
$json = static function ( callable $fn ): ?array {
	$muere = static fn() => static function () {
		throw new RuntimeException( 'asde-fin' );
	};
	add_filter( 'wp_doing_ajax', '__return_true' );
	add_filter( 'wp_die_ajax_handler', $muere );
	ob_start();
	try {
		$fn();
	} catch ( RuntimeException $e ) {
		unset( $e );
	}
	$salida = (string) ob_get_clean();
	remove_filter( 'wp_doing_ajax', '__return_true' );
	remove_filter( 'wp_die_ajax_handler', $muere );
	$d = json_decode( $salida, true );
	return is_array( $d ) ? $d : null;
};

try {
	echo "== Parte A · Datos del listado ==\n";
	$usuarios = Listado::usuarios();
	$logins   = wp_list_pluck( $usuarios, 'user_login' );
	$mios     = array_values( array_filter( $logins, static fn( $l ) => str_starts_with( $l, 'prueba-lis-' ) ) );
	$ok( in_array( 'prueba-lis-a', $logins, true ) && in_array( 'prueba-lis-b', $logins, true ) && in_array( 'prueba-lis-c', $logins, true ), 'el listado trae los usuarios del grupo: con roles y con invitación pendiente' );
	$ok( in_array( 'prueba-lis-d', $logins, true ), 'también los desactivados (sin roles)' );
	$ok( ! in_array( 'prueba-lis-z', $logins, true ), 'no salen los de otro grupo' );
	$ok( array( 'prueba-lis-a', 'prueba-lis-b', 'prueba-lis-c', 'prueba-lis-secre', 'prueba-lis-d' ) === $mios, 'sin repetidos: primero los que tienen roles, por usuario, y al final los desactivados (el navegador reordena a su gusto)' );
	$nums = Listado::numeros( $usuarios );
	$ok( in_array( '990001', $nums, true ) && in_array( '990002', $nums, true ) && in_array( '990003', $nums, true ) && count( $nums ) === count( array_unique( $nums ) ), 'números a consultar: los de las cuentas y los de las personas representadas, sin repetir' );

	echo "\n== Parte B · Nombres de las personas del listado ==\n";
	list( $per, $aviso ) = Listado::personas( array( '990001', '990002', '990003' ) );
	$ok( '' === $aviso && 3 === count( $per ) && '' !== $per['990001']['nombre'] && true === $per['990001']['activa'], 'nombre y membresía de cada persona' );
	list( $per2 ) = Listado::personas( array( '990001', '990002' ) );
	$ok( array( '990001', '990002' ) === array_map( 'strval', array_keys( $per2 ) ), 'solo devuelve las personas pedidas' );
	$antes = $crm->llamadas;
	Listado::personas( array( '990001' ), true );
	$ok( $crm->llamadas > $antes, 'con la instantánea desactivada (pruebas) se lee siempre del CRM; «Actualizar» no rompe nada' );
	$crm->caido = true;
	list( $per4, $aviso4 ) = Listado::personas( array( '990005' ) );
	$ok( array() === $per4 && '' !== $aviso4, 'con el CRM caído: avisa y no inventa nombres' );
	$crm->caido = false;

	echo "\n== Parte C · La página ==\n";
	wp_set_current_user( $u_secre );
	Ambito::olvidar( $u_secre );
	$_GET = array();
	$html = ( new UsuariosView() )->render();
	$ok( str_contains( $html, 'data-asde-usuarios' ) && str_contains( $html, 'data-asde-tabla' ), 'la página lleva el listado con sus datos para el navegador' );
	$pestana = static fn( string $hoja, string $nombre, bool $activa ) => 'class="asde-hojas__pestana' . ( $activa ? ' is-active' : '' ) . '" href="' . esc_url( \AsdeBalears\AreaPrivada\Frontend\Pages::url( $hoja ) ) . '" data-asde-hoja-ir="' . $hoja . '"' . ( $activa ? ' aria-current="page"' : '' ) . '>' . $nombre . '</a>';
	$ok( str_contains( $html, '<div class="asde-libro" data-asde-libro>' ) && str_contains( $html, '<div class="asde-hoja" data-asde-hoja="usuarios">' ) && str_contains( $html, $pestana( 'usuarios', 'Usuarios', true ) ) && str_contains( $html, $pestana( 'alta', 'Dar acceso', false ) ) && str_contains( $html, $pestana( 'emails', 'Registro de emails', false ) ), 'la sección «Usuarios» es un libro con tres hojas (Usuarios, Dar acceso y Registro de emails), con la de Usuarios a la vista (v0.25)' );
	$ok( ! str_contains( $html, '+ Dar acceso' ) && substr_count( $html, 'href="' . esc_url( \AsdeBalears\AreaPrivada\Frontend\Pages::url( 'emails' ) ) . '"' ) === 1, 'ya sin los enlaces sueltos a «Dar acceso» y al registro de emails: son hojas' );
	$alta_h = ( new \AsdeBalears\AreaPrivada\Frontend\Views\AltaUsuariosView() )->render();
	$mail_h = ( new \AsdeBalears\AreaPrivada\Frontend\Views\EmailsView() )->render();
	\AsdeBalears\AreaPrivada\Frontend\Views\View::en_modal();
	$alta_m = ( new \AsdeBalears\AreaPrivada\Frontend\Views\AltaUsuariosView() )->render();
	\AsdeBalears\AreaPrivada\Frontend\Views\View::en_modal( false );
	$ok( str_contains( $alta_h, '<div class="asde-hoja" data-asde-hoja="alta">' ) && str_contains( $alta_h, $pestana( 'alta', 'Dar acceso', true ) ) && str_contains( $alta_h, 'Busca al socio en el CRM' ) && str_contains( $mail_h, '<div class="asde-hoja" data-asde-hoja="emails">' ) && str_contains( $mail_h, $pestana( 'emails', 'Registro de emails', true ) ), '«Dar acceso» y «Registro de emails» son las otras hojas del libro, cada una a la vista en su página' );
	$ok( str_contains( $alta_m, 'Busca al socio en el CRM' ) && ! str_contains( $alta_m, 'data-asde-libro' ), '«Dar acceso» en la ventana emergente (desde otras páginas) va sin hojas' );
	$_GET   = array( 'socio' => '990006' );
	$raro_p = ( new \AsdeBalears\AreaPrivada\Frontend\Views\AltaUsuariosView() )->render();
	\AsdeBalears\AreaPrivada\Frontend\Views\View::en_modal();
	$raro_v = ( new \AsdeBalears\AreaPrivada\Frontend\Views\AltaUsuariosView() )->render();
	\AsdeBalears\AreaPrivada\Frontend\Views\View::en_modal( false );
	$_GET     = array( 'socio' => '990007' );
	$conocido = ( new \AsdeBalears\AreaPrivada\Frontend\Views\AltaUsuariosView() )->render();
	$_GET     = array();
	$ok( str_contains( $raro_p, 'Cargo en el CRM' ) && str_contains( $raro_p, '<dd>cargoinventadoGS</dd>' ) && str_contains( $raro_v, '<dd>cargoinventadoGS</dd>' ) && str_contains( $conocido, '<dd>Secretario/a de Grupo</dd>' ), '«Dar acceso» de alguien con un cargo del CRM que no está en la lista lo enseña tal cual (antes, error 500); uno de la lista, con su nombre (v0.25.1)' );
	$ok( substr_count( $html, 'data-asde-fila="' ) >= 5 && str_contains( $html, 'data-usuario="prueba-lis-a"' ) && str_contains( $html, 'data-asde-fila="' . $u_baja . '"' ), 'una fila por usuario (con roles, invitados y desactivados)' );
	$ok( ! str_contains( $html, 'data-asde-fila="' . $u_otro . '"' ), 'y ninguna de otro grupo' );
	$ok( str_contains( $html, 'data-roles="|' . Roles::SCOUTER . '|"' ) && str_contains( $html, 'data-ambito="|lobatos|"' ) && str_contains( $html, 'data-estado="invitado"' ) && str_contains( $html, 'data-estado="desactivado"' ), 'cada fila lleva rol, ámbito y estado para filtrar' );
	$ok( str_contains( $html, 'data-persona="Familia de ' ), 'las cuentas familiares se encuentran por el nombre de los menores' );
	foreach ( array( 'usuario', 'persona', 'roles', 'ambito', 'estado' ) as $col ) {
		$ok( str_contains( $html, 'data-filtro="' . $col . '"' ) && str_contains( $html, 'data-col="' . $col . '"' ), "columna $col: encabezado y control de filtro" );
	}
	$ok( str_contains( $html, 'class="asde-filtros" hidden' ) && str_contains( $html, 'data-asde-paginacion' ), 'los filtros y la paginación los activa el navegador (sin JavaScript se ve la lista entera)' );
	$ok( ! str_contains( $html, 'onchange="this.form.submit()"' ), 'ningún control recarga la página' );
	$ok( str_contains( $html, 'data-por-pagina="' . Tabla::por_pagina_guardada() . '"' ) && str_contains( $html, 'data-opciones="25,50,75,100"' ), 'el tamaño de página es el que la persona tenía elegido (25, 50, 75 o 100)' );
	$_GET = array( 'rol' => Roles::TUTOR );
	$ok( str_contains( ( new UsuariosView() )->render(), 'data-rol="' . Roles::TUTOR . '"' ), 'un enlace antiguo con ?rol= abre el filtro de rol puesto' );
	$_GET = array( 'rol' => '<script>' );
	$ok( ! str_contains( ( new UsuariosView() )->render(), '<script>' ), 'un ?rol= inventado no entra en la página' );
	$_GET = array();
	wp_set_current_user( $u_scout );
	$ok( str_contains( ( new UsuariosView() )->render(), 'No tienes acceso' ) || ! str_contains( ( new UsuariosView() )->render(), 'data-asde-tabla' ), 'sin permiso para gestionar usuarios no se ve el listado' );
	wp_set_current_user( $u_secre );

	echo "\n== Parte D · Lista y acciones sin recargar (JSON) ==\n";
	$_SERVER['HTTP_X_ASDE_JSON'] = '1';
	$ok( Router::pide_json(), 'la cabecera X-Asde-Json hace que el servidor conteste en JSON' );
	$d = $json( static fn() => ( new UsuariosView() )->json() );
	$ok( $d && true === $d['success'] && $d['data']['total'] >= 5 && str_contains( $d['data']['filas'], 'data-usuario="prueba-lis-c"' ) && ! str_contains( $d['data']['filas'], 'prueba-lis-z' ), 'la lista entera en JSON, para actualizarla en el navegador' );
	wp_set_current_user( $u_scout );
	$d = $json( static fn() => ( new UsuariosView() )->json() );
	$ok( $d && false === $d['success'], 'sin permiso, el JSON no entrega la lista' );
	wp_set_current_user( $u_secre );

	// Reenviar invitación.
	$peticion = static function ( string $accion, int $uid ) {
		$_POST    = array( 'asde_action' => $accion, 'user_id' => (string) $uid, '_wpnonce' => wp_create_nonce( 'asde_' . ( 'reenviar' === $accion ? 'reenviar_' : 'desactivar_' ) . $uid ) );
		$_REQUEST = $_POST;
	};
	$peticion( 'reenviar', $u_inv );
	$d = $json( static fn() => ( new UsuariosView() )->handle_post() );
	$ok( $d && true === $d['success'] && str_contains( $d['data']['fila'], 'data-asde-fila="' . $u_inv . '"' ) && str_contains( $d['data']['avisos'], 'Invitación reenviada' ), 'reenviar la invitación: devuelve la fila actualizada y el aviso, sin redirigir' );
	$ok( str_contains( $d['data']['fila'], 'Último email' ), 'la fila ya muestra el email que acaba de salir' );
	$peticion( 'reenviar', $u_fam );
	$d = $json( static fn() => ( new UsuariosView() )->handle_post() );
	$ok( $d && true === $d['success'] && str_contains( $d['data']['avisos'], 'no tiene una invitación pendiente' ), 'reenviar a quien no tiene invitación pendiente: aviso de error' );

	// Desactivar.
	$peticion( 'desactivar', $u_scout );
	$d = $json( static fn() => ( new UsuariosView() )->handle_post() );
	$u = get_userdata( $u_scout );
	$ok( $d && true === $d['success'] && Usuarios::DESACTIVADO === Usuarios::estado( $u ) && array() === Usuarios::roles_asde( $u ), 'desactivar: el usuario pierde los roles' );
	$ok( str_contains( $d['data']['fila'], 'data-estado="desactivado"' ) && ! str_contains( $d['data']['fila'], 'Desactivar</button>' ) && str_contains( $d['data']['avisos'], 'Acceso desactivado' ), 'y la fila llega ya como desactivada, sin el botón, con su aviso' );
	$peticion( 'desactivar', $u_secre );
	$d = $json( static fn() => ( new UsuariosView() )->handle_post() );
	$ok( $d && str_contains( $d['data']['avisos'], 'ni a ti mismo' ) && Usuarios::ACTIVO === Usuarios::estado( get_userdata( $u_secre ) ), 'no se puede desactivar a uno mismo' );
	$_POST    = array( 'asde_action' => 'desactivar', 'user_id' => (string) $u_fam, '_wpnonce' => 'mala' );
	$_REQUEST = $_POST;
	$ok( 'desactivado' !== Usuarios::estado( get_userdata( $u_fam ) ), 'sin un nonce válido no se ejecuta nada' );
	$peticion( 'reenviar', 999999 );
	$d = $json( static fn() => ( new UsuariosView() )->handle_post() );
	$ok( $d && true === $d['success'] && '' === $d['data']['fila'] && str_contains( $d['data']['avisos'], 'Usuario no válido' ), 'un usuario que no existe: sin fila y con aviso' );

	// Sin JavaScript: redirige como siempre.
	unset( $_SERVER['HTTP_X_ASDE_JSON'] );
	$redirigir = static function ( $destino ) {
		throw new RuntimeException( 'asde-redirect:' . $destino );
	};
	add_filter( 'wp_redirect', $redirigir, 1 );
	$peticion( 'reenviar', $u_inv );
	$destino = '';
	try {
		( new UsuariosView() )->handle_post();
	} catch ( RuntimeException $e ) {
		$destino = $e->getMessage();
	}
	remove_filter( 'wp_redirect', $redirigir, 1 );
	$ok( str_starts_with( $destino, 'asde-redirect:' ), 'sin la cabecera (sin JavaScript) la acción vuelve a la página con un redirect, como antes' );
	delete_transient( 'asde_ap_notices_' . $u_secre );

	echo "\n== Parte E · Tamaño de página (se recuerda entre listados) ==\n";
	$pref = get_user_meta( $u_secre, 'asde_por_pagina', true );
	$_POST    = array( 'nonce' => wp_create_nonce( 'asde_ap_por_pagina' ), 'por_pagina' => '50' );
	$_REQUEST = $_POST;
	$d        = $json( static fn() => Tabla::ajax_por_pagina() );
	$ok( $d && true === $d['success'] && 50 === Tabla::por_pagina_guardada(), 'elegir 50 por página se guarda para la persona' );
	$_POST    = array( 'nonce' => wp_create_nonce( 'asde_ap_por_pagina' ), 'por_pagina' => '33' );
	$_REQUEST = $_POST;
	$json( static fn() => Tabla::ajax_por_pagina() );
	$ok( 50 === Tabla::por_pagina_guardada(), 'un tamaño que no es 25, 50, 75 ni 100 se ignora' );
	$_POST    = array( 'nonce' => 'mala', 'por_pagina' => '100' );
	$_REQUEST = $_POST;
	$json( static fn() => Tabla::ajax_por_pagina() );
	$ok( 50 === Tabla::por_pagina_guardada(), 'sin nonce válido no se guarda' );
	$ok( str_contains( ( new UsuariosView() )->render(), 'data-por-pagina="50"' ), 'la página de usuarios abre con el tamaño elegido' );

	echo "\n== Parte F · Por la página real (Router) ==\n";
	global $wp_query;
	$pagina_usuarios = \AsdeBalears\AreaPrivada\Frontend\Pages::id( 'usuarios' );
	if ( $pagina_usuarios ) {
		wp_set_current_user( $u_secre );
		Ambito::olvidar( $u_secre );
		$wp_query->query( array( 'page_id' => $pagina_usuarios ) );
		$ok( 'usuarios' === \AsdeBalears\AreaPrivada\Frontend\Pages::current_key(), 'la página «Usuarios» se reconoce' );
		$router = new Router();
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_SERVER['HTTP_X_ASDE_JSON'] = '1';
		$d = $json( static fn() => $router->template_redirect() );
		$ok( $d && true === $d['success'] && str_contains( $d['data']['filas'], 'data-usuario="prueba-lis-b"' ), 'GET con X-Asde-Json: el Router contesta con la lista en JSON' );
		unset( $_SERVER['HTTP_X_ASDE_JSON'] );
		$salio = false;
		try {
			ob_start();
			$router->template_redirect();
			$salio = '' === ob_get_clean();
		} catch ( Throwable $e ) {
			ob_end_clean();
		}
		$ok( $salio, 'GET normal: el Router no interviene y la página se pinta como siempre' );
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_SERVER['HTTP_X_ASDE_JSON'] = '1';
		$_POST    = array( 'asde_action' => 'reenviar', 'user_id' => (string) $u_inv, '_wpnonce' => wp_create_nonce( 'asde_reenviar_' . $u_inv ) );
		$_REQUEST = $_POST;
		$d = $json( static fn() => $router->template_redirect() );
		$ok( $d && true === $d['success'] && str_contains( $d['data']['fila'], 'data-asde-fila="' . $u_inv . '"' ), 'POST con X-Asde-Json: el Router entrega la acción a la vista y devuelve la fila' );
		$_SERVER['REQUEST_METHOD'] = 'GET';
		unset( $_SERVER['HTTP_X_ASDE_JSON'] );
		$_POST = $_REQUEST = array();
		$router->assets();
		$ok( wp_script_is( 'asde-usuarios', 'enqueued' ) && wp_script_is( 'asde-area', 'enqueued' ), 'la página carga usuarios.js (y area.js, que avisa al cerrar la ventana emergente)' );
		$ok( wp_script_is( 'asde-hojas', 'enqueued' ), 'y hojas.js: las hojas de la sección se abren sin recargar la página (v0.25)' );
		wp_dequeue_script( 'asde-usuarios' );
		wp_dequeue_script( 'asde-hojas' );
		$pagina_alta = \AsdeBalears\AreaPrivada\Frontend\Pages::id( 'alta' );
		if ( $pagina_alta ) {
			$wp_query->query( array( 'page_id' => $pagina_alta ) );
			$router->assets();
			$ok( wp_script_is( 'asde-hojas', 'enqueued' ) && wp_script_is( 'asde-usuarios', 'enqueued' ), '«Dar acceso» también carga las hojas y el listado de usuarios (para abrir su hoja sin recargar)' );
			wp_dequeue_script( 'asde-usuarios' );
			wp_dequeue_script( 'asde-hojas' );
		}
		$wp_query->init();
	} else {
		echo "OMITIDO  La página «Usuarios» no existe en este sitio.\n";
	}
} finally {
	$_POST = $_GET = $_REQUEST = array();
	unset( $_SERVER['HTTP_X_ASDE_JSON'] );
	$lista = implode( ',', array_map( 'intval', $ids ) ) ?: '0';
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . RegistroEmails::table() . " WHERE id > %d AND user_id IN ($lista)", $emails_antes ) ); // phpcs:ignore WordPress.DB
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Logger::table() . " WHERE id > %d AND ( user_id IN ($lista) OR ( objeto = 'user' AND objeto_id IN ($lista) ) )", $log_antes ) ); // phpcs:ignore WordPress.DB
	foreach ( $ids as $uid ) {
		delete_transient( 'asde_ap_notices_' . $uid );
		Ambito::olvidar( (int) $uid );
		wp_delete_user( (int) $uid );
	}
	if ( isset( $pref ) ) {
		if ( '' === $pref ) {
			delete_user_meta( $u_secre, 'asde_por_pagina' );
		}
	}
	$tabla_socios = \AsdeBalears\AreaPrivada\Socios\SociosTable::table();
	$wpdb->query( "DELETE FROM $tabla_socios WHERE num_socio_asde LIKE '9900%'" ); // phpcs:ignore WordPress.DB
	foreach ( $socios_antes as $asde => $fila ) {
		$ahora = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $tabla_socios WHERE num_socio_asde = %s", $asde ), ARRAY_A ); // phpcs:ignore WordPress.DB
		if ( $ahora !== $fila ) {
			$wpdb->replace( $tabla_socios, $fila );
		}
	}
	delete_transient( 'asde_ap_numeracion_' . $grupo['numero'] );
	wp_using_ext_object_cache( $cache_externa );
	remove_filter( 'pre_http_request', $cortar_crm, 2 );
	Grupos::olvidar();
	Grupo::forzar( null );
	wp_set_current_user( 0 );
	$quedan = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users} WHERE user_login LIKE 'prueba-lis-%'" ); // phpcs:ignore WordPress.DB
	$ok( 0 === $quedan, 'limpieza: sin usuarios de la prueba' );
}

echo $fails ? "\n$fails FALLOS\n" : "\nTODO OK (nada tocado en el CRM real; el sitio queda como estaba)\n";
