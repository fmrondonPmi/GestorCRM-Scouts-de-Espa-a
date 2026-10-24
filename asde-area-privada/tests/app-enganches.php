<?php
/**
 * Pruebas de la v0.18 (entorno LOCAL): la app móvil (PWA) ya no está en este plugin, sino en
 * `asde-area-privada-app` (repo GestorCRM-App). Aquí se comprueba lo que queda en el principal:
 * los enganches genéricos que usa la app y la retirada de las apps instaladas si la app no está.
 *
 *   wp eval-file <ruta completa>/wp-content/plugins/asde-area-privada/tests/app-enganches.php
 *
 * No cambia nada del sitio (los filtros que añade los quita al terminar).
 */
defined( 'ABSPATH' ) || exit;

use AsdeBalears\AreaPrivada\Frontend\TemplateLoader;
use AsdeBalears\AreaPrivada\Install\AppRetirada;
use AsdeBalears\AreaPrivada\Install\Entorno;
use AsdeBalears\AreaPrivada\Idiomas\Traducciones;

$fails = 0;
$ok    = function ( $cond, string $label ) use ( &$fails ) {
	if ( ! $cond ) {
		++$fails;
	}
	echo ( $cond ? 'OK   ' : 'FAIL ' ), $label, "\n";
};

echo "== Parte A · La PWA ya no está aquí ==\n";
$ok( ! is_dir( ASDE_AP_DIR . 'src/Pwa' ) && ! is_dir( ASDE_AP_DIR . 'assets/pwa' ) && ! is_dir( ASDE_AP_DIR . 'templates/pwa' ) && ! file_exists( ASDE_AP_DIR . 'src/Admin/PwaPage.php' ), 'sin src/Pwa, assets/pwa, templates/pwa ni la pantalla «App móvil»' );
$plugin = (string) file_get_contents( ASDE_AP_DIR . 'src/Plugin.php' );
$ok( ! str_contains( $plugin, 'Pwa' ) && str_contains( $plugin, 'AppRetirada::register()' ), 'Plugin.php no arranca la PWA; arranca la retirada' );
$extractor = (string) file_get_contents( ASDE_AP_DIR . 'src/Idiomas/Extractor.php' );
$ok( ! str_contains( $extractor, 'App móvil' ), 'el extractor de textos ya no tiene el grupo «App móvil» (lo pone la app)' );
$es = require ASDE_AP_DIR . 'languages/es.php';
$ok( ! in_array( 'App móvil', $es, true ), 'languages/es.php del principal sin textos de la app' );

echo "\n== Parte B · Enganche de plantillas (asde_ap_plantilla) ==\n";
$falsa = static fn( string $f, string $n ) => 'pwa/prueba-enganche' === $n ? __FILE__ : $f;
add_filter( 'asde_ap_plantilla', $falsa, 10, 2 );
try {
	$ok( __FILE__ === TemplateLoader::locate( 'pwa/prueba-enganche' ), 'otro plugin puede poner la plantilla de un nombre' );
	$ok( ASDE_AP_DIR . 'templates/acceso/formulario.php' === TemplateLoader::locate( 'acceso/formulario' ), 'las demás plantillas no cambian' );
	$ok( '' === TemplateLoader::locate( '../wp-config' ), 'un nombre con «..» sigue sin valer' );
} catch ( Throwable $e ) {
	++$fails;
	echo 'FAIL excepcion: ', $e->getMessage(), ' (', basename( $e->getFile() ), ':', $e->getLine(), ")\n";
} finally {
	remove_filter( 'asde_ap_plantilla', $falsa, 10 );
}

echo "\n== Parte C · Enganche de «Instalación y entorno» (asde_ap_entorno) ==\n";
$pieza = static function ( array $c ): array {
	$c['prueba_enganche'] = array( Entorno::INFO, 'Pieza de prueba', 'Añadida por un filtro.', array() );
	return $c;
};
add_filter( 'asde_ap_entorno', $pieza );
try {
	$e      = Entorno::comprobar();
	$claves = array_keys( $e );
	$ok( isset( $e['prueba_enganche'] ) && 'Pieza de prueba' === $e['prueba_enganche'][1], 'otro plugin puede añadir una pieza' );
	$ok( array_search( 'prueba_enganche', $claves, true ) < array_search( 'segundo_plano', $claves, true ), 'sale en su sitio (antes de «Tareas en segundo plano»), donde estaba la pieza de la app' );
} finally {
	remove_filter( 'asde_ap_entorno', $pieza );
}
$ok( defined( 'ASDE_APP_VERSION' ) === isset( Entorno::comprobar()['pwa'] ), 'la pieza «App móvil» está solo si el plugin de la app está activo' );

echo "\n== Parte D · Catálogos de otro plugin (asde_ap_catalogo / asde_ap_textos) ==\n";
if ( defined( 'ASDE_APP_VERSION' ) ) {
	$ok( false !== has_filter( 'asde_ap_catalogo' ) && false !== has_filter( 'asde_ap_textos' ), 'la app activa se engancha a los dos filtros de catálogos' );
	$ok( 'App móvil' === ( Traducciones::textos()['Instalar la app'] ?? '' ), '«Idioma y etiquetas» ve los textos de la app (grupo «App móvil»)' );
	$propio = \AsdeBalears\AreaPrivadaApp\Idiomas::propio( 'ca' )['Instalar la app'] ?? '';
	$ok( '' !== $propio && 'Instalar la app' !== $propio && Traducciones::t( 'Instalar la app', 'ca' ) === $propio, 'su traducción al catalán llega por el filtro' );
} else {
	$ok( ! isset( Traducciones::textos()['Instalar la app'] ), 'sin la app, sus textos no salen en «Idioma y etiquetas»' );
}

echo "\n== Parte E · Retirada de las apps instaladas sin el plugin de la app ==\n";
$sw = home_url( '/asde-sw.js' );
$ok( AppRetirada::toca( $sw, 'GET', false ), 'sin la app, /asde-sw.js lo contesta el principal' );
$ok( AppRetirada::toca( $sw . '?v=1', 'GET', false ), '… también con parámetros' );
$ok( ! AppRetirada::toca( $sw, 'GET', true ), 'con la app activa no se mete (lo sirve la app)' );
$ok( ! AppRetirada::toca( $sw, 'POST', false ), 'solo GET' );
$ok( ! AppRetirada::toca( home_url( '/otra-cosa.js' ), 'GET', false ) && ! AppRetirada::toca( home_url( '/asde-manifest.webmanifest' ), 'GET', false ), 'ninguna otra dirección' );
$ok( AppRetirada::toca( $sw, 'GET' ) === ! defined( 'ASDE_APP_VERSION' ), 'sin decir nada, mira si la app está activa' );
$js = AppRetirada::contenido();
$ok( str_contains( $js, "addEventListener( 'activate'" ) && str_contains( $js, 'caches.delete' ) && str_contains( $js, "'asde-pwa-'" ) && str_contains( $js, 'unsubscribe()' ) && str_contains( $js, 'registration.unregister()' ), 'el service worker de retirada borra las cachés de la app, da de baja los avisos y se da de baja' );
$ok( ! str_contains( $js, 'fetch' ) && ! str_contains( $js, 'importScripts' ), 'y no pide nada a la red' );

echo $fails ? "\n$fails FALLOS\n" : "\nTODO OK (el sitio queda como estaba)\n";
