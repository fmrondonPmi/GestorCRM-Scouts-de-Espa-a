<?php
/**
 * Pruebas de «Instalación y entorno» (v0.8, entorno LOCAL). Ejecutar desde app/public del site Local:
 *
 *   wp eval-file wp-content/plugins/asde-area-privada/tests/entorno.php
 *
 * Sobre el WordPress local ya configurado: comprueba que no cambia nada si todo
 * está bien, que repara páginas (papelera, sin shortcode) y que los ajustes del
 * sitio solo se tocan si se marcan y si hacían falta. Todo lo que cambia la
 * prueba se deja como estaba. No toca el menú ni el .htaccess (eso se probó en
 * un WordPress nuevo, doc 19). No usa el CRM.
 */
defined( 'ABSPATH' ) || exit;

use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Frontend\Pages;
use AsdeBalears\AreaPrivada\Frontend\Visibilidad;
use AsdeBalears\AreaPrivada\Install\Entorno;

global $wpdb;

$fails = 0;
$ok    = function ( $cond, string $label ) use ( &$fails ) {
	if ( ! $cond ) {
		++$fails;
	}
	echo ( $cond ? 'OK   ' : 'FAIL ' ), $label, "\n";
};
$log_antes = (int) $wpdb->get_var( 'SELECT MAX(id) FROM ' . Logger::table() );
$ajustes   = array(
	'users_can_register' => get_option( 'users_can_register' ),
	'timezone_string'    => get_option( 'timezone_string' ),
	'gmt_offset'         => get_option( 'gmt_offset' ),
	'permalink'          => get_option( 'permalink_structure' ),
);
$cambios_id        = Pages::id( 'cambios' );
$cambios_contenido = (string) get_post_field( 'post_content', $cambios_id );

$emails = Pages::id( 'emails' );
try {
	echo "== Parte A · Estado del entorno ==\n";
	$e       = Entorno::comprobar();
	$claves  = array( 'requisitos', 'organizacion', 'tablas', 'roles', 'paginas', 'menu', 'cron', 'wordfence', 'enlaces', 'registro', 'idioma', 'https', 'email', 'clave', 'crm', 'grupos', 'contacto', 'segundo_plano', 'tema' );
	if ( defined( 'ASDE_APP_VERSION' ) ) {
		$claves[] = 'pwa'; // La pieza de la app móvil, si su plugin está activo (filtro asde_ap_entorno).
	}
	$validos = array( Entorno::OK, Entorno::AVISO, Entorno::FALTA, Entorno::INFO );
	$ok( array() === array_diff( $claves, array_keys( $e ) ) && ! isset( $e['zona'] ) && ! array_filter( $e, static fn( $x ) => 4 !== count( $x ) || ! in_array( $x[0], $validos, true ) || '' === $x[1] || '' === $x[2] ), 'comprueba las ' . count( $claves ) . ' piezas (la hora no: es cosa de WordPress), cada una con estado, nombre y explicación' );
	$ok( Entorno::OK === $e['organizacion'][0] && str_contains( $e['organizacion'][2], 'Scouts de Balears' ), 'organización federada configurada (Scouts de Balears, código 7)' );
	$ok( Entorno::OK === $e['tablas'][0] && 29 === count( Entorno::tablas() ), 'tablas: las 29 existen (v0.36: gastos y anticipos de los eventos; v0.39: productos y variantes de la tienda; v0.40: tareas de los eventos; v0.41: ingredientes, recetas y sus líneas; v0.41 B2: el menú del evento; B3: sus confirmaciones; v0.42: el material de los eventos)' );
	$ok( Entorno::OK === $e['roles'][0] && Entorno::OK === $e['paginas'][0] && Entorno::OK === $e['cron'][0], 'roles, páginas y tarea diaria en orden' );
	$ok( Entorno::OK === $e['menu'][0] && Visibilidad::menu_visible(), '«Acceso socios» está en el menú principal (ubicación ' . Visibilidad::ubicacion_principal() . ')' );
	$ok( ! Entorno::opciones(), 'en un sitio ya configurado no propone cambiar ajustes del sitio' );
	$ids = Pages::ids();
	$ok( ! array_filter( get_pages(), static fn( $p ) => in_array( (int) $p->ID, $ids, true ) ), 'las páginas del área no salen en get_pages() (lista de páginas de los temas de bloques)' );

	echo "\n== Parte B · Generar sin nada que hacer ==\n";
	$r = Entorno::generar( array() );
	$ok( ! $r['errores'] && 1 === count( $r['hecho'] ) && str_contains( $r['hecho'][0], 'ya estaba en orden' ), 'si todo está bien, no cambia nada (se puede pulsar las veces que haga falta)' );
	$ok( $ajustes['permalink'] === get_option( 'permalink_structure' ), 'los enlaces permanentes no se tocan' );

	echo "\n== Parte C · Reparar páginas ==\n";
	$emails = Pages::id( 'emails' );
	wp_trash_post( $emails );
	$ok( Entorno::FALTA === Entorno::comprobar()['paginas'][0], 'una página en la papelera se detecta' );
	$r = Entorno::generar( array() );
	$ok( $emails === Pages::id( 'emails' ) && 'publish' === get_post_status( $emails ) && 'emails' === get_post_field( 'post_name', $emails ) && (bool) array_filter( $r['hecho'], static fn( $l ) => str_contains( $l, 'recuperada de la papelera' ) ), 'se recupera la misma página (con su dirección), no se crea otra' );
	wp_update_post( array( 'ID' => $cambios_id, 'post_content' => 'Texto añadido por alguien.' ) );
	$r = Entorno::generar( array() );
	$nuevo = (string) get_post_field( 'post_content', $cambios_id );
	$ok( has_shortcode( $nuevo, 'asde_cambios' ) && str_contains( $nuevo, 'Texto añadido por alguien.' ), 'si le quitaron el shortcode, se vuelve a poner y el texto se conserva' );
	wp_update_post( array( 'ID' => $cambios_id, 'post_content' => $cambios_contenido ) );
	$ok( Entorno::OK === Entorno::comprobar()['paginas'][0], 'páginas en orden otra vez' );

	echo "\n== Parte D · Ajustes del sitio: solo si se marcan y si hacían falta ==\n";
	update_option( 'users_can_register', 1 );
	$ok( isset( Entorno::opciones()['registro'] ) && Entorno::FALTA === Entorno::comprobar()['registro'][0], 'registro público abierto: lo detecta y lo propone' );
	Entorno::generar( array() );
	$ok( 1 === (int) get_option( 'users_can_register' ), 'sin marcarlo, no se toca' );
	Entorno::generar( array( 'registro' => true ) );
	$ok( 0 === (int) get_option( 'users_can_register' ), 'marcado: se desactiva el registro público' );

	update_option( 'timezone_string', '' );
	update_option( 'gmt_offset', 0 );
	Entorno::generar( array( 'zona' => true, 'zona_valor' => 'Atlantic/Canary' ) );
	$ok( ! isset( Entorno::opciones()['zona'] ) && '' === get_option( 'timezone_string' ), 'la zona horaria no la toca el plugin (es cosa de WordPress)' );

	Entorno::generar( array( 'enlaces' => true, 'menu' => true, 'noabort' => true ) );
	$ok( $ajustes['permalink'] === get_option( 'permalink_structure' ), 'lo que no hacía falta no se aplica aunque llegue marcado (enlaces, menú, noabort)' );

} catch ( Throwable $e ) {
	++$fails;
	echo 'FAIL excepcion: ', $e->getMessage(), ' (', basename( $e->getFile() ), ':', $e->getLine(), ")\n";
} finally {
	// Limpieza: ajustes como estaban y fuera los registros de la prueba.
	update_option( 'users_can_register', $ajustes['users_can_register'] );
	update_option( 'timezone_string', $ajustes['timezone_string'] );
	update_option( 'gmt_offset', $ajustes['gmt_offset'] );
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Logger::table() . ' WHERE id > %d AND user_id = 0 AND accion = %s', $log_antes, 'entorno_generado' ) ); // phpcs:ignore WordPress.DB
	if ( $emails && 'trash' === get_post_status( $emails ) ) {
		wp_untrash_post( $emails ); // Doc 60: si se corta tras mandarla a la papelera.
	}
	if ( $cambios_contenido !== (string) get_post_field( 'post_content', $cambios_id ) ) {
		wp_update_post( array( 'ID' => $cambios_id, 'post_content' => $cambios_contenido ) );
	}
	$ok( $ajustes['timezone_string'] === get_option( 'timezone_string' ) && (string) $ajustes['gmt_offset'] === (string) get_option( 'gmt_offset' ) && $cambios_contenido === get_post_field( 'post_content', $cambios_id ), 'limpieza: ajustes y páginas como estaban' );
}

echo $fails ? "\n$fails FALLOS\n" : "\nTODO OK (el sitio queda como estaba)\n";
