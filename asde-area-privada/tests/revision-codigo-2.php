<?php
/**
 * Pruebas de la segunda tanda de la revisión de código (doc 59, decisiones del 2026-10-23; entorno LOCAL).
 * Ejecutar desde app/public del site Local:
 *
 *   wp eval-file wp-content/plugins/asde-area-privada/tests/revision-codigo-2.php
 *
 * (o, desde herramientas-desarrollo: ./probar-todo.sh revision-codigo-2)
 *
 * - Parte A · RC-DEC-2: las solicitudes de acceso pendientes de más de 90 días se rechazan solas, con el email de rechazo,
 *   y purgar() vacía lo escrito 30 días después; las recientes no se tocan.
 * - Parte B · RC-USU-3: los textos de «sin sección» nombran también Intendencia (y están traducidos).
 * Usa un grupo ficticio (994) añadido solo en memoria, sin CRM (las solicitudes se crean con una persona inventada), y no
 * deja salir ningún email (se capturan). Al terminar se borran las solicitudes, los emails registrados y el registro del
 * grupo 994.
 */
defined( 'ABSPATH' ) || exit;

use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Domain\Grupo;
use AsdeBalears\AreaPrivada\Domain\Grupos;
use AsdeBalears\AreaPrivada\Domain\Persona;
use AsdeBalears\AreaPrivada\Frontend\TemplateLoader;
use AsdeBalears\AreaPrivada\Idiomas\Idioma;
use AsdeBalears\AreaPrivada\Usuarios\RegistroEmails;
use AsdeBalears\AreaPrivada\Usuarios\Solicitudes;

global $wpdb;

$fails = 0;
$ok    = function ( $cond, string $label ) use ( &$fails ) {
	if ( ! $cond ) {
		++$fails;
	}
	echo ( $cond ? 'OK   ' : 'FAIL ' ), $label, "\n";
};

$g994 = '994';
if ( Grupos::por_numero( $g994 ) ) {
	exit( "El grupo 994 existe en el Local: esta prueba lo usa como grupo ficticio. Revisa de dónde ha salido.\n" );
}
if ( (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE grupo = %s', Solicitudes::table(), $g994 ) ) ) { // phpcs:ignore WordPress.DB
	exit( "Quedan solicitudes del grupo 994 (¿una ejecución anterior interrumpida?). Bórralas antes de repetir la prueba.\n" );
}
wp_set_current_user( 0 );

// --- Lo que se restaura al final ---------------------------------------------
$max_id      = static fn( string $t ) => (int) $wpdb->get_var( "SELECT COALESCE(MAX(id), 0) FROM $t" ); // phpcs:ignore WordPress.DB
$log_antes   = $max_id( Logger::table() );
$email_antes = $max_id( RegistroEmails::table() );

$cache_externa = wp_using_ext_object_cache( true ); // Cachés y transients solo en memoria.
$cortadas      = 0;
$cortar_crm    = static function ( $pre, $args, $url ) use ( &$cortadas ) {
	if ( false === $pre && str_contains( (string) $url, '/rest.php' ) ) {
		++$cortadas;
		return new WP_Error( 'prueba', 'CRM real cortado durante la prueba' );
	}
	return $pre;
};
add_filter( 'pre_http_request', $cortar_crm, 2, 3 );
$capturados = array();
$captura    = static function ( $pre, $atts ) use ( &$capturados ) {
	$capturados[] = $atts;
	return true;
};
add_filter( 'pre_wp_mail', $captura, 10, 2 );
$grupos_orig = get_option( 'asde_ap_grupos' );
$mas_grupos  = static fn() => (array) $grupos_orig + array(
	$g994 => array(
		'nombre'         => 'Grupo de prueba 994',
		'usuario'        => '',
		'crm_id'         => '',
		'email_contacto' => 'contacto994@example.invalid',
	),
);
add_filter( 'pre_option_asde_ap_grupos', $mas_grupos );
Grupos::olvidar();
Grupo::forzar( Grupos::por_numero( $g994 ) );

$sol_ids = array();

try {
	echo "== Parte A · RC-DEC-2: solicitudes pendientes de más de 90 días ==\n";
	$persona = new Persona( 'de000000-0000-4000-8000-000000009997', '990097', '', 'Nora', 'Rc Dos Pruebas', '2012-02-02', '', '', array( 'scouts' ), '', array() );
	$nueva   = static function ( string $email, int $dias ) use ( $persona, $wpdb, &$sol_ids ): int {
		$id = Solicitudes::crear( Solicitudes::TUTOR, Solicitudes::POR_DOCUMENTO, $persona, array( 'nombre' => 'Madre Inventada', 'email' => $email, 'documento' => '00000000T', 'nacimiento' => '2012-02-02', 'idioma' => 'es' ) );
		$sol_ids[] = $id;
		$wpdb->update( Solicitudes::table(), array( 'creada' => gmdate( 'Y-m-d H:i:s', time() - $dias * DAY_IN_SECONDS ) ), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB
		return $id;
	};
	$vieja    = $nueva( 'vieja.rc2@example.invalid', 100 );
	$reciente = $nueva( 'reciente.rc2@example.invalid', 10 );
	$ok( $vieja > 0 && $reciente > 0, 'dos solicitudes del grupo 994: una de hace 100 días y otra de hace 10' );

	$n = Solicitudes::caducar_pendientes( null, $g994 );
	$v = Solicitudes::obtener( $vieja );
	$r = Solicitudes::obtener( $reciente );
	$ok( 1 === $n && Solicitudes::RECHAZADA === $v['estado'] && Solicitudes::MOTIVO_CADUCADA === $v['motivo'] && null !== $v['revisada_en'], 'la de hace 100 días se rechaza sola, con su motivo (en castellano) y la fecha' );
	$ok( Solicitudes::PENDIENTE === $r['estado'], 'la de hace 10 días sigue pendiente' );
	$para = array_map( static fn( $c ) => is_array( $c['to'] ) ? $c['to'][0] : $c['to'], $capturados );
	$ok( array( 'vieja.rc2@example.invalid' ) === $para && str_contains( (string) ( $capturados[0]['subject'] ?? '' ), 'Sobre tu solicitud de acceso' ), 'le llega el email de rechazo de siempre (solo a ella)' );
	$ok( str_contains( (string) ( $capturados[0]['message'] ?? '' ), 'contacto994@example.invalid' ), 'con el email de contacto de su grupo' );
	$reg = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT detalles FROM %i WHERE id > %d AND accion = %s AND objeto_id = %s', Logger::table(), $log_antes, 'solicitud_caducada', (string) $vieja ) ); // phpcs:ignore WordPress.DB
	$ok( str_contains( $reg, '"aviso":"enviado"' ) && ! str_contains( $reg, 'example.invalid' ), 'queda en el registro (con el resultado del aviso, sin el email)' );
	$ok( 0 === Solicitudes::caducar_pendientes( null, $g994 ) && 1 === count( $capturados ), 'repetirlo no la vuelve a rechazar ni a avisar' );
	$ok( 'vieja.rc2@example.invalid' === $v['datos']['email'], 'los datos se conservan todavía (purgar() los vacía 30 días después)' );
	$wpdb->update( Solicitudes::table(), array( 'revisada_en' => gmdate( 'Y-m-d H:i:s', time() - 31 * DAY_IN_SECONDS ) ), array( 'id' => $vieja ) ); // phpcs:ignore WordPress.DB
	Solicitudes::purgar();
	$ok( '' === Solicitudes::obtener( $vieja )['datos']['email'] && 'reciente.rc2@example.invalid' === Solicitudes::obtener( $reciente )['datos']['email'], 'a los 30 días de rechazarla, purgar() vacía lo escrito (la pendiente reciente conserva lo suyo)' );
	$ok( 'Nadie la revisó en 90 días: rechazada automáticamente.' !== Idioma::en( 'ca', static fn() => asde_t( Solicitudes::MOTIVO_CADUCADA ) ), 'el motivo se traduce al mostrarlo' );

	echo "\n== Parte B · RC-USU-3: textos de «sin sección» con Intendencia ==\n";
	$f = (string) file_get_contents( dirname( __DIR__ ) . '/templates/alta-scouters/formulario.php' );
	$l = (string) file_get_contents( dirname( __DIR__ ) . '/templates/solicitudes/listado.php' );
	$ok( str_contains( $f, "asde_e( 'Soy voluntario/a sin sección (Secretaría, Coordinación, Tesorería o Intendencia)' )" ), 'la etiqueta del formulario de alta de scouters nombra Intendencia' );
	$ok( str_contains( $l, '(Secretaría, Coordinación, Tesorería, Intendencia)' ), 'la ayuda del listado de solicitudes también' );
	foreach ( array( 'ca', 'eu', 'gl' ) as $idioma ) {
		$t = Idioma::en( $idioma, static fn() => asde_t( 'Soy voluntario/a sin sección (Secretaría, Coordinación, Tesorería o Intendencia)' ) );
		$ok( 'Soy voluntario/a sin sección (Secretaría, Coordinación, Tesorería o Intendencia)' !== $t, "y está traducida ($idioma)" );
	}

	$ok( 0 === $cortadas, 'ninguna consulta ha salido hacia el CRM real' );
} catch ( Throwable $e ) {
	++$fails;
	echo 'FAIL excepcion: ', $e->getMessage(), ' (', basename( $e->getFile() ), ':', $e->getLine(), ")\n";
} finally {
	remove_filter( 'pre_http_request', $cortar_crm, 2 );
	remove_filter( 'pre_wp_mail', $captura, 10 );
	wp_set_current_user( 0 );
	foreach ( $sol_ids as $sid ) {
		$wpdb->delete( Solicitudes::table(), array( 'id' => (int) $sid ) ); // phpcs:ignore WordPress.DB
	}
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id > %d AND grupo = %s', RegistroEmails::table(), $email_antes, $g994 ) ); // phpcs:ignore WordPress.DB
	$lista = implode( ',', array_map( 'intval', $sol_ids ) ) ?: '0';
	$wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE id > %d AND objeto = 'solicitud' AND objeto_id IN ($lista)", Logger::table(), $log_antes ) ); // phpcs:ignore WordPress.DB
	remove_filter( 'pre_option_asde_ap_grupos', $mas_grupos );
	Grupos::olvidar();
	wp_using_ext_object_cache( $cache_externa );
	Grupo::forzar( null );

	$quedan = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE grupo = %s', Solicitudes::table(), $g994 ) ); // phpcs:ignore WordPress.DB
	$ok( 0 === $quedan, 'limpieza: no queda ninguna solicitud del grupo 994' );
}

echo $fails ? "\n{$fails} FALLOS\n" : "\nTODO OK (nada tocado en el CRM real; el sitio queda como estaba)\n";
