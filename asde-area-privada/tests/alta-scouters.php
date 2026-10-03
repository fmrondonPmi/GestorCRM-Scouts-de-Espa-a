<?php
/**
 * Pruebas de la v0.16 (entorno LOCAL): alta de scouters con el enlace del grupo (doc 31).
 * Ejecutar desde app/public del site Local:
 *
 *   wp eval-file <ruta completa>/wp-content/plugins/asde-area-privada/tests/alta-scouters.php
 *
 * Usa el CRM simulado de tests/lib (fichas inventadas: ninguna petición llega al CRM real).
 * Ningún email sale (pre_wp_mail). Al terminar se borra lo creado y se restauran el enlace del
 * grupo, la tabla de números y los contadores.
 */
defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/lib/CrmSimulado.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Auth\Ambito;
use AsdeBalears\AreaPrivada\Crm\Escritura;
use AsdeBalears\AreaPrivada\Domain\Grupo;
use AsdeBalears\AreaPrivada\Domain\Grupos;
use AsdeBalears\AreaPrivada\Domain\Secciones;
use AsdeBalears\AreaPrivada\Frontend\Pages;
use AsdeBalears\AreaPrivada\Frontend\Views\AltaScoutersView;
use AsdeBalears\AreaPrivada\Frontend\Views\AltaUsuariosView;
use AsdeBalears\AreaPrivada\Frontend\Views\SolicitudesView;
use AsdeBalears\AreaPrivada\Frontend\Views\View;
use AsdeBalears\AreaPrivada\Install\Roles;
use AsdeBalears\AreaPrivada\Socios\SociosTable;
use AsdeBalears\AreaPrivada\Tesoreria\FamiliasTable;
use AsdeBalears\AreaPrivada\Usuarios\AltaScouterService as A;
use AsdeBalears\AreaPrivada\Usuarios\EmailCuenta;
use AsdeBalears\AreaPrivada\Usuarios\EnlaceScouters;
use AsdeBalears\AreaPrivada\Usuarios\RegistroEmails;
use AsdeBalears\AreaPrivada\Usuarios\Solicitudes;
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
foreach ( get_users( array( 'search' => 'prueba-alt-*', 'search_columns' => array( 'user_login' ) ) ) as $u ) {
	exit( 'Ya existe el usuario ' . $u->user_login . " (¿una ejecución anterior interrumpida?). Bórralo antes de repetir la prueba.\n" );
}
wp_set_current_user( 0 );
$_SERVER['REMOTE_ADDR'] = '192.0.2.' . random_int( 1, 254 );

// --- Lo que se restaura al final ---------------------------------------------
$max_id       = static fn( string $t ) => (int) $wpdb->get_var( "SELECT COALESCE(MAX(id), 0) FROM $t" ); // phpcs:ignore WordPress.DB
$log_antes    = $max_id( Logger::table() );
$sol_antes    = $max_id( Solicitudes::table() );
$emails_antes = $max_id( RegistroEmails::table() );
$fam_antes    = $max_id( FamiliasTable::table() );
$socios_antes = array();
foreach ( (array) $wpdb->get_results( 'SELECT * FROM ' . SociosTable::table(), ARRAY_A ) as $f ) { // phpcs:ignore WordPress.DB
	$socios_antes[ $f['num_socio_asde'] ] = $f;
}
$enlace_antes = get_option( EnlaceScouters::OPCION, null );
$clave_num    = 'asde_ap_numeracion_' . $grupo['numero'];
$cache_num    = get_transient( $clave_num );
delete_transient( $clave_num );
$opciones   = static fn( string $patron ) => (array) $wpdb->get_results( $wpdb->prepare( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s", $patron ), OBJECT_K ); // phpcs:ignore WordPress.DB
$contadores = array_keys( $opciones( '\_transient\_asde\_ap\_lim\_%' ) );

add_filter( 'pre_wp_mail', '__return_true' );
add_filter( 'asde_ap_solicitud_segundos_minimos', '__return_zero' );

// --- CRM simulado con fichas inventadas -------------------------------------
$P   = array( 'Asde_Prueba_Crm', 'persona' );
$crm = new Asde_Prueba_Crm(
	array(
		$P( 41, 'Eva', 'Alta Gil', '1990-01-01', 'Scouters', array( 'stic_identification_number_c' => '99999141A', 'email1' => 'eva.ficha@example.invalid' ) ),
		$P( 42, 'Tom', 'Alta Luz', '1985-05-05', 'Scouters', array( 'stic_identification_number_c' => '99999142B' ) ), // Sin email en la ficha.
		$P( 43, 'Sara', 'Alta Ros', '1992-02-02', 'Voluntariado', array( 'stic_identification_number_c' => '99999143C', 'email1' => 'sara.ficha@example.invalid' ) ),
		$P( 44, 'Leo', 'Alta Sanz', '2016-05-10', 'Lobatos', array( 'stic_identification_number_c' => '99999144D' ) ), // Educando: no vale.
		$P( 45, 'Marc', 'Alta Vila', '2003-09-09', 'Rovers', array( 'stic_identification_number_c' => '99999145E', 'email1' => 'marc.ficha@example.invalid' ) ), // Rover mayor de edad: no es scouter.
		$P( 47, 'Marta', 'Alta Riera', '1980-03-03', 'Scouters', array( 'email1' => 'marta.ficha@example.invalid' ) ),
		$P( 48, 'Jordi', 'Alta Coll', '1981-04-04', 'Scouters', array( 'email1' => 'jordi.ficha@example.invalid' ) ),
	),
	$grupo['crm_id']
);
$falsos     = array_map( static fn( $p ) => $p['numerosocio_c'], $crm->personas );
$cortadas   = 0;
$cortar_crm = static function ( $pre, $args, $url ) use ( &$cortadas ) {
	if ( false === $pre && str_contains( (string) $url, '/rest.php' ) ) {
		++$cortadas;
		return new WP_Error( 'prueba', 'CRM real cortado durante la prueba' );
	}
	return $pre;
};
add_filter( 'pre_http_request', $cortar_crm, 2, 3 );
$cache_externa = wp_using_ext_object_cache( true );
Grupo::forzar( $grupo );
$crm->activar();
Escritura::usar_para_pruebas( $crm );

$ids   = array();
$crear = static function ( string $login, array $roles, array $meta ) use ( &$ids, $grupo ): int {
	$id = wp_insert_user( array( 'user_login' => $login, 'user_email' => '', 'user_pass' => wp_generate_password( 24 ), 'role' => $roles[0] ) );
	$u  = get_userdata( $id );
	foreach ( array_slice( $roles, 1 ) as $r ) {
		$u->add_role( $r );
	}
	update_user_meta( $id, Usuarios::META_GRUPO, $grupo['id'] );
	update_user_meta( $id, Usuarios::META_ESTADO, Usuarios::ACTIVO );
	update_user_meta( $id, Usuarios::META_ACTIVADA_EN, current_time( 'mysql', true ) );
	foreach ( $meta as $k => $v ) {
		foreach ( (array) $v as $valor ) {
			add_user_meta( $id, $k, $valor );
		}
	}
	$ids[ $login ] = $id;
	return $id;
};
$redirigir = static function ( $destino ) {
	throw new RuntimeException( 'r:' . $destino );
};

try {
	$u_sec = $crear( 'prueba-alt-sec', array( Roles::SCOUTER, Roles::SECRETARIA ), array( Usuarios::META_SOCIO => '990047', Usuarios::META_SECCION => 'rovers' ) );
	$u_sco = $crear( 'prueba-alt-sco', array( Roles::SCOUTER ), array( Usuarios::META_SOCIO => '990048', Usuarios::META_SECCION => 'scouts' ) );

	echo "== Parte A · El enlace del grupo ==\n";
	$clave = EnlaceScouters::clave( $grupo );
	$url   = EnlaceScouters::url( $grupo );
	$ok( 24 === strlen( $clave ) && str_contains( $url, 'clave=' . $clave ) && str_contains( $url, 'alta-scouters' ) && in_array( 'alta_scouters', Pages::PUBLICAS, true ), 'cada grupo tiene su enlace, con una clave de 24 caracteres, en una página pública' );
	$ok( EnlaceScouters::valida( $grupo, $clave ) && ! EnlaceScouters::valida( $grupo, 'otra' ) && ! EnlaceScouters::valida( $grupo, '' ), 'solo vale la clave vigente' );

	// Formulario público (sin sesión).
	wp_set_current_user( 0 );
	$estado = new ReflectionProperty( AltaScoutersView::class, 'estado' );
	$sello  = new ReflectionMethod( AltaScoutersView::class, 'sello' );
	$form   = static function ( array $campos, string $clave_usada ) use ( $estado, $sello, $grupo ): string {
		$estado->setValue( null, array() );
		$_POST = array_merge(
			array(
				'asde_action'       => 'alta_scouter',
				'_wpnonce'          => wp_create_nonce( 'asde_alta_scouter' ),
				'sello'             => $sello->invoke( null ),
				'grupo'             => $grupo['id'],
				'clave'             => $clave_usada,
				'modo'              => A::MODO_SCOUTER,
				'secciones'         => array( 'scouts' ),
				'responsabilidades' => array(),
				'email'             => 'escrito@example.invalid',
				'web'               => '',
			),
			$campos
		);
		( new AltaScoutersView() )->handle_post();
		$html  = ( new AltaScoutersView() )->render();
		$_POST = array();
		return $html;
	};
	$_GET = array( 'grupo' => $grupo['id'], 'clave' => 'mala' );
	$h    = ( new AltaScoutersView() )->render();
	$ok( str_contains( $h, 'no es válido' ) && ! str_contains( $h, 'name="documento"' ), 'con una clave que no vale: el formulario no se enseña' );
	$_GET = array( 'grupo' => $grupo['id'], 'clave' => $clave );
	$h    = ( new AltaScoutersView() )->render();
	$ok( str_contains( $h, 'name="documento"' ) && str_contains( $h, 'name="secciones[]"' ) && str_contains( $h, 'value="' . Roles::TESORERIA . '"' ) && str_contains( $h, 'value="' . A::MODO_SIN_SECCION . '"' ), 'con la clave vigente: DNI, secciones, voluntario sin sección y responsabilidades' );
	$_GET = array();
	$antes = count( Solicitudes::listado() );
	$form( array( 'documento' => '99999141A' ), 'mala' );
	$ok( count( Solicitudes::listado() ) === $antes && ! Usuarios::por_socio( '990041' ), 'enviado con una clave que no vale: no se hace nada' );

	echo "\n== Parte B · Lo que se comprueba antes de mirar el CRM ==\n";
	$h = $form( array( 'documento' => '99999141A', 'secciones' => array() ), $clave );
	$ok( str_contains( $h, 'Elige al menos una sección' ), 'scouter sin secciones: se piden' );
	$h = $form( array( 'documento' => '99999143C', 'modo' => A::MODO_SIN_SECCION, 'secciones' => array() ), $clave );
	$ok( str_contains( $h, 'elige al menos una responsabilidad' ), 'sin sección y sin responsabilidad: se pide una' );
	$h = $form( array( 'documento' => '' ), $clave );
	$ok( str_contains( $h, 'Escribe tu DNI o NIE' ), 'sin DNI: se pide' );
	$h = $form( array( 'documento' => '99999141A', 'email' => 'no-es-email' ), $clave );
	$ok( str_contains( $h, 'Escribe un email válido' ) && ! Usuarios::por_socio( '990041' ), 'email no válido: se pide, sin crear nada' );
	$h = $form( array( 'documento' => '99999141A', 'web' => 'http://spam.example' ), $clave );
	$ok( str_contains( $h, 'Gracias.' ) && ! Usuarios::por_socio( '990041' ), 'campo trampa relleno (un programa): se le contesta igual, sin crear nada' );

	echo "\n== Parte C · Scouter con email en su ficha: cuenta al momento ==\n";
	$h_ok = $form( array( 'documento' => '9999 9141-a', 'secciones' => array( 'scouts', 'lobatos' ), 'responsabilidades' => array( Roles::TESORERIA ), 'email' => 'otro.email@example.invalid' ), $clave );
	$eva  = Usuarios::por_socio( '990041' );
	$ok( $eva && in_array( Roles::SCOUTER, (array) $eva->roles, true ) && ! in_array( Roles::TESORERIA, (array) $eva->roles, true ), 'se crea su cuenta de Scouter; la Tesorería que pide aún no (la confirma coordinación)' );
	$ok( $eva && array( 'lobatos', 'scouts' ) === ( static function ( $s ) { sort( $s ); return $s; } )( Usuarios::secciones( $eva->ID ) ), 'con las secciones que ha elegido' );
	$ok( $eva && 'eva.ficha@example.invalid' === EmailCuenta::leer( $eva->ID ) && Usuarios::INVITADO === Usuarios::estado( $eva ), 'la activación va al email de su ficha, no al que escribió' );
	$inv = $eva ? $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . RegistroEmails::table() . ' WHERE id > %d AND user_id = %d', $emails_antes, $eva->ID ), ARRAY_A ) : null; // phpcs:ignore WordPress.DB
	$ok( $inv && RegistroEmails::ENVIADO === $inv['estado'], 'y queda en el registro de emails' );
	$sols = array_values( array_filter( Solicitudes::listado( Solicitudes::PENDIENTE ), static fn( $s ) => '990041' === $s['socio'] ) );
	$s_eva = $sols[0] ?? null;
	$ok( $s_eva && Solicitudes::POR_ENLACE === $s_eva['via'] && $s_eva['adulto'] && array( Roles::SCOUTER, Roles::TESORERIA ) === $s_eva['datos']['roles_pedidos'] && (int) $s_eva['datos']['cuenta_creada'] === (int) $eva->ID, 'y una solicitud de adulto para coordinación: lo que pide y que la cuenta ya está creada' );
	$ok( str_contains( $h_ok, 'Gracias.' ) && ! str_contains( $h_ok, 'Eva' ) && ! str_contains( $h_ok, 'eva.ficha' ), 'la respuesta no enseña nada de la ficha' );

	echo "\n== Parte D · Lo que va a revisión o no vale ==\n";
	$h_tom = $form( array( 'documento' => '99999142B', 'email' => 'tom.escrito@example.invalid' ), $clave );
	$s_tom = array_values( array_filter( Solicitudes::listado( Solicitudes::PENDIENTE ), static fn( $s ) => '990042' === $s['socio'] ) )[0] ?? null;
	$ok( ! Usuarios::por_socio( '990042' ) && $s_tom && 'tom.escrito@example.invalid' === $s_tom['datos']['email'] && empty( $s_tom['datos']['cuenta_creada'] ), 'scouter sin email en la ficha: no hay cuenta; solicitud con el email que escribió, para revisar' );
	$form( array( 'documento' => '99999143C', 'modo' => A::MODO_SIN_SECCION, 'secciones' => array(), 'responsabilidades' => array( Roles::SECRETARIA ) ), $clave );
	$s_sara = array_values( array_filter( Solicitudes::listado( Solicitudes::PENDIENTE ), static fn( $s ) => '990043' === $s['socio'] ) )[0] ?? null;
	$ok( ! Usuarios::por_socio( '990043' ) && $s_sara && array( Roles::SECRETARIA ) === $s_sara['datos']['roles_pedidos'] && array( Secciones::SIN_SECCION ) === $s_sara['datos']['secciones_pedidas'] && 'sara.ficha@example.invalid' === $s_sara['datos']['email'], 'voluntaria sin sección: todo a revisión (con el email de su ficha)' );
	$antes = count( Solicitudes::listado() );
	$h_leo  = $form( array( 'documento' => '99999144D' ), $clave );
	$h_marc = $form( array( 'documento' => '99999145E' ), $clave );
	$h_nadie = $form( array( 'documento' => '11111111H' ), $clave );
	$ok( count( Solicitudes::listado() ) === $antes && ! Usuarios::por_socio( '990044' ) && ! Usuarios::por_socio( '990045' ), 'un educando, un rover mayor de edad o un DNI que no es del grupo: no se crea nada' );
	$quitar = static fn( string $h ) => preg_replace( '/name="(_wpnonce|sello)" value="[^"]*"/', '', $h );
	$ok( str_contains( $h_leo, 'Gracias.' ) && $quitar( $h_leo ) === $quitar( $h_ok ) && $quitar( $h_nadie ) === $quitar( $h_ok ) && $quitar( $h_marc ) === $quitar( $h_tom ), 'y la pantalla contesta exactamente igual que cuando sí vale: no se puede averiguar si un DNI es del grupo' );
	$antes = count( Solicitudes::listado() );
	$form( array( 'documento' => '99999141A' ), $clave );
	$form( array( 'documento' => '99999142B', 'email' => 'tom.escrito@example.invalid' ), $clave );
	$ok( count( Solicitudes::listado() ) === $antes, 'repetir el alta (ya tiene cuenta, o ya hay una solicitud): no se duplica nada' );
	$h = $form( array( 'documento' => '99999141A' ), $clave );
	$ok( str_contains( $h, 'varios intentos seguidos' ), 'a partir del 9.º intento en una hora desde el mismo dispositivo, se para' );

	echo "\n== Parte E · Lo que ve el equipo de coordinación ==\n";
	wp_set_current_user( $u_sec );
	Ambito::olvidar( $u_sec );
	$h = ( new SolicitudesView() )->render();
	$ok( str_contains( $h, 'Enlace para scouters' ) && str_contains( $h, esc_html( $url ) ) && str_contains( $h, 'renovar_enlace_scouters' ), 'Secretaría ve el enlace para scouters, con «Copiar» y «Generar enlace nuevo»' );
	$ok( str_contains( $h, 'Alta con el enlace para scouters' ) && str_contains( $h, 'cuenta ya creada' ), 'y en la lista, de dónde viene cada alta y si la cuenta ya está creada' );
	wp_set_current_user( $u_sco );
	Ambito::olvidar( $u_sco );
	$h = ( new SolicitudesView() )->render();
	$ok( ! str_contains( $h, 'Enlace para scouters' ) && ! str_contains( $h, 'Alta Gil' ), 'un scouter no ve el enlace ni las altas de adultos' );
	add_filter( 'wp_redirect', $redirigir, 1 );
	$_POST    = array( 'asde_action' => 'renovar_enlace_scouters', '_wpnonce' => wp_create_nonce( 'asde_enlace_scouters' ) );
	$_REQUEST = $_POST;
	try {
		( new SolicitudesView() )->handle_post();
	} catch ( RuntimeException $e ) {
		// Redirige al terminar.
	}
	$ok( EnlaceScouters::valida( $grupo, $clave ), 'un scouter no puede renovar el enlace' );
	wp_set_current_user( $u_sec );
	$_POST    = array( 'asde_action' => 'renovar_enlace_scouters', '_wpnonce' => wp_create_nonce( 'asde_enlace_scouters' ) );
	$_REQUEST = $_POST;
	try {
		( new SolicitudesView() )->handle_post();
	} catch ( RuntimeException $e ) {
		// Redirige al terminar.
	}
	$_POST    = array();
	$_REQUEST = array();
	$ok( ! EnlaceScouters::valida( $grupo, $clave ) && EnlaceScouters::valida( $grupo, EnlaceScouters::clave( $grupo ) ), 'Secretaría lo renueva: el enlace anterior deja de valer' );
	delete_transient( 'asde_ap_notices_' . $u_sec );

	// «Dar acceso» desde la solicitud: viene marcado lo que pidió.
	$_GET = array( 'socio' => '990043', 'solicitud' => (string) $s_sara['id'] );
	$h    = ( new AltaUsuariosView() )->render();
	$_GET = array();
	$ok( (bool) preg_match( '/value="' . Roles::SECRETARIA . '"[^>]*checked/', $h ) && (bool) preg_match( '/value="' . Secciones::SIN_SECCION . '"[^>]*checked/', $h ), '«Dar acceso» de la voluntaria viene con Secretaría y «sin sección» ya marcados' );
	// Confirmar la Tesorería que pidió Eva: su cuenta ya existe, la solicitud queda aprobada.
	$_POST    = array(
		'asde_action' => 'alta_persona',
		'_wpnonce'    => wp_create_nonce( 'asde_alta_persona_990041' ),
		'socio'       => '990041',
		'roles'       => array( Roles::TESORERIA ),
		'secciones'   => array( 'scouts', 'lobatos' ),
		'solicitud'   => (string) $s_eva['id'],
	);
	$_REQUEST = $_POST;
	try {
		( new AltaUsuariosView() )->handle_post();
	} catch ( RuntimeException $e ) {
		// Redirige al terminar.
	}
	$_POST    = array();
	$_REQUEST = array();
	remove_filter( 'wp_redirect', $redirigir, 1 );
	$eva = get_userdata( $eva->ID );
	$ok( in_array( Roles::TESORERIA, (array) $eva->roles, true ) && Solicitudes::APROBADA === Solicitudes::obtener( $s_eva['id'] )['estado'] && 'eva.ficha@example.invalid' === EmailCuenta::leer( $eva->ID ), 'coordinación confirma la Tesorería: se añade, la solicitud queda aprobada y el email no cambia' );

	$ok( $crm->llamadas > 0 && 0 === $cortadas, "todas las consultas al CRM las ha respondido el simulador ({$crm->llamadas}); ninguna ha salido hacia el CRM real" );
} finally {
	remove_filter( 'pre_wp_mail', '__return_true' );
	remove_filter( 'asde_ap_solicitud_segundos_minimos', '__return_zero' );
	remove_filter( 'wp_redirect', $redirigir, 1 );
	$_POST = $_GET = $_REQUEST = array();
	View::en_modal( false );
	Escritura::usar_para_pruebas( null );
	$crm->desactivar();
	remove_filter( 'pre_http_request', $cortar_crm, 2 );
	wp_using_ext_object_cache( $cache_externa );
	wp_set_current_user( 0 );

	foreach ( $falsos as $asde ) {
		foreach ( array_filter( array( Usuarios::por_socio( $asde ) ) ) as $u ) {
			$ids[ 'cuenta-' . $u->ID ] = $u->ID;
		}
	}
	$lista     = implode( ',', array_map( 'intval', array_unique( $ids ) ) ) ?: '0';
	$nuevas    = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . Solicitudes::table() . ' WHERE id > %d', $sol_antes ) ) ); // phpcs:ignore WordPress.DB
	$en_sol    = "'" . implode( "','", array_map( 'strval', $nuevas ?: array( 0 ) ) ) . "'";
	$en_falsos = "'" . implode( "','", array_map( 'esc_sql', $falsos ) ) . "'";
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Solicitudes::table() . ' WHERE id > %d', $sol_antes ) ); // phpcs:ignore WordPress.DB
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . RegistroEmails::table() . " WHERE id > %d AND user_id IN ($lista)", $emails_antes ) ); // phpcs:ignore WordPress.DB
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Logger::table() . " WHERE id > %d AND ( user_id IN ($lista) OR ( objeto = 'user' AND objeto_id IN ($lista) ) OR ( objeto = 'solicitud' AND objeto_id IN ($en_sol) ) OR ( objeto = 'socio' AND ( objeto_id IN ($en_falsos) OR objeto_id = '' ) AND accion = 'alta_scouter_enlace' ) )", $log_antes ) ); // phpcs:ignore WordPress.DB
	foreach ( array_unique( $ids ) as $uid ) {
		delete_transient( 'asde_ap_notices_' . $uid );
		Ambito::olvidar( (int) $uid );
		wp_delete_user( (int) $uid );
	}
	$familias = implode( ',', array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . FamiliasTable::table() . ' WHERE id > %d', $fam_antes ) ) ) ) ?: '0'; // phpcs:ignore WordPress.DB
	$wpdb->query( 'DELETE FROM ' . FamiliasTable::table_miembros() . " WHERE familia_id IN ($familias) OR num_socio_asde IN ($en_falsos)" ); // phpcs:ignore WordPress.DB
	$wpdb->query( 'DELETE FROM ' . FamiliasTable::table() . " WHERE id IN ($familias)" ); // phpcs:ignore WordPress.DB
	$wpdb->query( 'DELETE FROM ' . SociosTable::table() . " WHERE num_socio_asde IN ($en_falsos)" ); // phpcs:ignore WordPress.DB
	foreach ( $socios_antes as $asde => $f ) {
		$ahora = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . SociosTable::table() . ' WHERE num_socio_asde = %s', $asde ), ARRAY_A ); // phpcs:ignore WordPress.DB
		if ( $ahora !== $f ) {
			$wpdb->replace( SociosTable::table(), $f );
		}
	}
	null === $enlace_antes ? delete_option( EnlaceScouters::OPCION ) : update_option( EnlaceScouters::OPCION, $enlace_antes, false );
	delete_transient( $clave_num );
	if ( false !== $cache_num ) {
		set_transient( $clave_num, $cache_num, 10 * MINUTE_IN_SECONDS );
	}
	foreach ( array_keys( $opciones( '\_transient\_asde\_ap\_lim\_%' ) ) as $nombre ) {
		if ( ! in_array( $nombre, $contadores, true ) ) {
			delete_transient( substr( $nombre, strlen( '_transient_' ) ) );
		}
	}
	Grupo::forzar( null );

	$quedan = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users} WHERE user_login LIKE 'prueba-alt-%'" ); // phpcs:ignore WordPress.DB
	$ok( 0 === $quedan && 0 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Solicitudes::table() . ' WHERE id > ' . (int) $sol_antes ) && get_option( EnlaceScouters::OPCION, null ) === $enlace_antes, 'limpieza: sin usuarios ni solicitudes de la prueba; el enlace del grupo como estaba' ); // phpcs:ignore WordPress.DB
}

echo $fails ? "\n$fails FALLOS\n" : "\nTODO OK (nada tocado en el CRM real; el sitio queda como estaba)\n";
