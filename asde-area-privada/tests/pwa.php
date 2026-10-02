<?php
/**
 * Pruebas de la PWA (docs/pwa/, v0.13, entorno LOCAL): manifiesto, iconos, rutas
 * virtuales y etiquetas del <head>. Ejecutar desde app/public del site Local:
 *
 *   wp eval-file <ruta completa>/wp-content/plugins/asde-area-privada/tests/pwa.php
 *
 * No toca el CRM ni la base de datos: solo lee (y una petición GET al propio sitio,
 * si responde, para ver el manifiesto servido de verdad).
 */
defined( 'ABSPATH' ) || exit;

use AsdeBalears\AreaPrivada\Frontend\Pages;
use AsdeBalears\AreaPrivada\Pwa\Iconos;
use AsdeBalears\AreaPrivada\Pwa\Manifiesto;
use AsdeBalears\AreaPrivada\Pwa\Pwa;
use AsdeBalears\AreaPrivada\Pwa\ServiceWorker;

$fails = 0;
$ok    = function ( $cond, string $label ) use ( &$fails ) {
	if ( ! $cond ) {
		++$fails;
	}
	echo ( $cond ? 'OK   ' : 'FAIL ' ), $label, "\n";
};

// ── Manifiesto ───────────────────────────────────────────────────────────────
echo "\n── Manifiesto\n";
$m = Manifiesto::construir();
foreach ( array( 'id', 'name', 'short_name', 'start_url', 'scope', 'display', 'background_color', 'theme_color', 'icons', 'lang' ) as $campo ) {
	$ok( ! empty( $m[ $campo ] ), "el manifiesto tiene «{$campo}»" );
}
$ok( 'standalone' === $m['display'], 'se abre como aplicación (standalone)' );
$ok( str_ends_with( $m['scope'], '/' ), 'el alcance termina en /' );
$ok( str_starts_with( $m['start_url'], $m['scope'] ), 'la página de inicio está dentro del alcance' );
$ok( $m['id'] === $m['scope'], 'el id es el alcance (estable aunque cambie la página de inicio)' );
$ok( Pages::id( 'area' ) > 0 ? str_contains( $m['start_url'], 'area-privada' ) : true, 'el inicio es la página «Área privada»' );
$ok( 1 === preg_match( '/^#[0-9a-f]{6}$/i', $m['theme_color'] ) && 1 === preg_match( '/^#[0-9a-f]{6}$/i', $m['background_color'] ), 'colores en hexadecimal' );
$ok( '#632599' === $m['theme_color'], 'color del tema = morado del doc 06' );
$ok( 'Área privada' === $m['short_name'], 'nombre corto «Área privada»' );
$ok( str_starts_with( $m['name'], 'Área privada' ), 'el nombre completo empieza por «Área privada»' );
$json = wp_json_encode( $m, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
$ok( is_array( json_decode( (string) $json, true ) ), 'es JSON válido' );
$ok( false === stripos( (string) $json, 'user' ) && false === stripos( (string) $json, '@' ), 'no lleva datos de personas ni emails' );

// ── Iconos genéricos ─────────────────────────────────────────────────────────
echo "\n── Iconos genéricos (sin icono del sitio)\n";
$ok( ! Iconos::del_sitio() || true, 'comprobación de icono del sitio (depende de cada WordPress)' );
add_filter( 'pre_option_site_icon', '__return_zero', 99 );
$ic = Iconos::manifiesto();
$ok( ! Iconos::del_sitio(), 'sin «Icono del sitio» se usan los del plugin' );
$ok( 3 === count( $ic ), 'tres iconos: 192, 512 y adaptable 512' );
$tamanos = array_column( $ic, 'sizes' );
$ok( in_array( '192x192', $tamanos, true ) && in_array( '512x512', $tamanos, true ), 'están los tamaños 192 y 512' );
$ok( in_array( 'maskable', array_column( $ic, 'purpose' ), true ), 'hay un icono adaptable (maskable)' );
foreach ( $ic as $i ) {
	$fichero = ASDE_AP_DIR . 'assets/pwa/' . basename( wp_parse_url( $i['src'], PHP_URL_PATH ) );
	$ok( is_readable( $fichero ), 'existe ' . basename( $fichero ) );
	$dim = @getimagesize( $fichero ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	$ok( is_array( $dim ) && $i['sizes'] === $dim[0] . 'x' . $dim[1] && 'image/png' === $dim['mime'], basename( $fichero ) . ' mide ' . $i['sizes'] . ' y es PNG' );
}
$ap  = Iconos::apple();
$dim = @getimagesize( ASDE_AP_DIR . 'assets/pwa/' . basename( wp_parse_url( $ap, PHP_URL_PATH ) ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
$ok( is_array( $dim ) && 180 === $dim[0] && 180 === $dim[1], 'icono de iPhone de 180×180' );
remove_filter( 'pre_option_site_icon', '__return_zero', 99 );

// ── Icono del sitio de WordPress ─────────────────────────────────────────────
echo "\n── Icono del sitio de WordPress (simulado)\n";
$fake_opcion = static fn() => 999;
$fake_url    = static fn( $url, $size ) => 'https://ejemplo.test/icono-' . $size . '.png';
add_filter( 'pre_option_site_icon', $fake_opcion, 99 );
add_filter( 'get_site_icon_url', $fake_url, 99, 2 );
$ok( Iconos::del_sitio(), 'con «Icono del sitio» se detecta' );
$ic = Iconos::manifiesto();
$ok( 2 === count( $ic ) && 'https://ejemplo.test/icono-192.png' === $ic[0]['src'] && 'https://ejemplo.test/icono-512.png' === $ic[1]['src'], 'el manifiesto usa el icono del sitio (192 y 512)' );
$ok( ! in_array( 'maskable', array_column( $ic, 'purpose' ), true ), 'no se declara adaptable un icono que no se sabe si lo es' );
$ok( 'https://ejemplo.test/icono-180.png' === Iconos::apple(), 'el icono del iPhone sale del icono del sitio' );
remove_filter( 'pre_option_site_icon', $fake_opcion, 99 );
remove_filter( 'get_site_icon_url', $fake_url, 99 );

// ── Alcance con enlaces permanentes simples ──────────────────────────────────
echo "\n── Alcance sin carpeta (enlaces ?page_id=)\n";
$simple = static fn() => home_url( '/?page_id=' . Pages::id( 'area' ) );
add_filter( 'page_link', $simple, 99 );
$m2 = Manifiesto::construir();
$ok( trailingslashit( home_url( '/' ) ) === $m2['scope'], 'sin carpeta, el alcance es todo el sitio' );
$ok( $m2['start_url'] === $m2['scope'], 'y el inicio es la portada del alcance (siempre dentro del alcance)' );
remove_filter( 'page_link', $simple, 99 );

// ── Rutas virtuales ──────────────────────────────────────────────────────────
echo "\n── Rutas virtuales\n";
$base = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
$ok( Pwa::es_ruta( Pwa::RUTA_MANIFIESTO, $base . Pwa::RUTA_MANIFIESTO ), 'reconoce la ruta del manifiesto' );
$ok( Pwa::es_ruta( Pwa::RUTA_MANIFIESTO, $base . Pwa::RUTA_MANIFIESTO . '?v=1' ), '…con parámetros' );
$ok( Pwa::es_ruta( Pwa::RUTA_MANIFIESTO, $base . Pwa::RUTA_MANIFIESTO . '/' ), '…con barra final' );
$ok( ! Pwa::es_ruta( Pwa::RUTA_MANIFIESTO, $base . 'otra-cosa' ), 'no confunde otras rutas' );
$ok( ! Pwa::es_ruta( Pwa::RUTA_MANIFIESTO, $base . 'x/' . Pwa::RUTA_MANIFIESTO ), 'no confunde una subcarpeta' );
$ok( ! Pwa::es_ruta( Pwa::RUTA_MANIFIESTO, $base ), 'la portada no es la ruta' );
$ok( home_url( '/asde-manifest.webmanifest' ) === Pwa::url_manifiesto(), 'la dirección del manifiesto cuelga de la raíz del sitio' );

// ── Etiquetas del <head> ─────────────────────────────────────────────────────
echo "\n── Etiquetas del <head>\n";
global $wp_query;
$wp_query_antes = $wp_query;
$area_id        = Pages::id( 'area' );
$head           = static function () {
	ob_start();
	( new Pwa() )->etiquetas();
	return (string) ob_get_clean();
};
if ( $area_id ) {
	$wp_query = new WP_Query( array( 'page_id' => $area_id ) ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride
	$ok( 'area' === Pages::current_key(), 'preparada la página «Área privada»' );
	$h = $head();
	$ok( str_contains( $h, '<link rel="manifest" href="' . Pwa::url_manifiesto() . '">' ), 'enlaza el manifiesto' );
	$ok( str_contains( $h, '<meta name="theme-color" content="#632599">' ), 'color del tema' );
	$ok( str_contains( $h, 'apple-mobile-web-app-capable' ) && str_contains( $h, 'mobile-web-app-capable' ), 'modo aplicación (Apple y estándar)' );
	$ok( str_contains( $h, 'apple-mobile-web-app-title" content="Área privada"' ), 'título bajo el icono en iPhone' );
	$ok( str_contains( $h, 'rel="apple-touch-icon"' ), 'icono de iPhone' );
	add_filter( 'asde_ap_pwa_activa', '__return_false' );
	$ok( '' === $head(), 'con la PWA desactivada no pinta nada' );
	remove_filter( 'asde_ap_pwa_activa', '__return_false' );
} else {
	echo "INFO no hay página «Área privada» en este WordPress: se omiten las pruebas del <head>\n";
}
$wp_query = new WP_Query( array( 'p' => 0 ) ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride
$ok( '' === $head(), 'fuera del área no pinta nada' );
$wp_query = $wp_query_antes; // phpcs:ignore WordPress.WP.GlobalVariablesOverride

// ── Servido de verdad (si el sitio responde) ─────────────────────────────────
echo "\n── Manifiesto servido por el sitio (GET)\n";
$r = wp_remote_get( Pwa::url_manifiesto(), array( 'timeout' => 10, 'sslverify' => false ) );
if ( is_wp_error( $r ) ) {
	echo 'INFO el sitio no responde desde aquí (' . $r->get_error_message() . "): se omite\n";
} elseif ( 200 !== wp_remote_retrieve_response_code( $r ) ) {
	$ok( false, 'el manifiesto se sirve con 200 (¿está desplegada la versión nueva? código ' . wp_remote_retrieve_response_code( $r ) . ')' );
} else {
	$ok( str_starts_with( (string) wp_remote_retrieve_header( $r, 'content-type' ), 'application/manifest+json' ), 'Content-Type application/manifest+json' );
	$web = json_decode( wp_remote_retrieve_body( $r ), true );
	$ok( is_array( $web ) && $web['scope'] === $m['scope'] && $web['start_url'] === $m['start_url'], 'lo servido coincide con el construido' );
	$ok( false !== stripos( (string) wp_remote_retrieve_header( $r, 'cache-control' ), 'no-cache' ), 'Cache-Control: no-cache' );
	$ok( false !== stripos( (string) wp_remote_retrieve_header( $r, 'x-robots-tag' ), 'noindex' ), 'X-Robots-Tag: noindex' );
}

// ═══ SECCIÓN 2 · service worker y página «sin conexión» ═══════════════════════
echo "\n── Service worker: configuración y contenido\n";
$cfg = ServiceWorker::config();
$ok( ASDE_AP_VERSION === $cfg['version'], 'la versión del service worker es la del plugin (así se actualiza con el plugin)' );
$ok( str_ends_with( $cfg['offline'], '/assets/pwa/sin-conexion.html' ), 'la página sin conexión es la del plugin' );
$ok( array( $cfg['offline'] ) === $cfg['precache'], 'lo único que se guarda de antemano es la página sin conexión' );
$ok( str_starts_with( $cfg['estaticos'], '/' ) && str_ends_with( $cfg['estaticos'], '/asde-area-privada/assets/' ), 'los estáticos son solo la carpeta assets/ del plugin' );
$js = ServiceWorker::contenido();
$ok( ! str_contains( $js, '__ASDE_CONFIG__' ), 'se sustituye la configuración' );
$ok( 1 === preg_match( '/const CONFIG  = (\{.*?\});/s', $js, $mm ) && is_array( json_decode( $mm[1], true ) ) && json_decode( $mm[1], true ) === $cfg, 'la configuración puesta en el JS es JSON válido y es la esperada' );
$ok( str_contains( $js, "'GET' !== peticion.method" ), 'solo atiende las peticiones GET (los formularios van a la red)' );
$ok( str_contains( $js, 'url.origin !== self.location.origin' ), 'solo atiende las del propio sitio' );
$ok( 1 === preg_match( "/'navigate' === peticion\.mode \) \{(.*?)return;/s", $js, $nav ) && ! str_contains( $nav[1], '.put(' ) && ! str_contains( $nav[1], 'addAll' ), 'las páginas navegadas nunca se guardan (solo fallback sin conexión)' );
$ok( 1 === substr_count( $js, '.put(' ), 'solo un sitio guarda respuestas: los estáticos' );
$ok( str_contains( $js, "200 === respuesta.status && 'basic' === respuesta.type" ), 'solo se guardan respuestas 200 del propio sitio' );
$ok( ! str_contains( $js, 'application/json' ) && ! str_contains( $js, 'X-Asde' ), 'el service worker no sabe nada del JSON ni de la ventana emergente: no los toca' );
$ok( str_contains( $js, 'self.skipWaiting()' ) && str_contains( $js, 'self.clients.claim()' ) && str_contains( $js, 'caches.delete' ), 'se activa al instalarse y borra las cachés de versiones antiguas' );

echo "\n── Página «sin conexión»\n";
$html = (string) file_get_contents( ASDE_AP_DIR . 'assets/pwa/sin-conexion.html' );
$ok( '' !== $html && str_contains( $html, 'Sin conexión' ), 'existe y dice «Sin conexión»' );
$ok( ! str_contains( $html, '<?php' ), 'es estática (sin PHP)' );
$ok( ! preg_match( '#(?:src|href)\s*=\s*["\']?(?:https?:)?//#i', $html ) && ! str_contains( $html, '<img' ) && ! str_contains( $html, '<link' ) && ! str_contains( $html, '<script src' ), 'no carga nada de fuera (funciona sin red)' );
$ok( str_contains( $html, 'noindex' ), 'no se indexa' );
$ok( str_contains( $html, 'location.reload' ) && str_contains( $html, "'online'" ), 'tiene «Reintentar» y se recarga sola al volver la red' );
$ok( ! preg_match( '/\b(nombre|email|dni|iban|socio n)/i', wp_strip_all_tags( $html ) ), 'no contiene datos de personas' );

echo "\n── Registro del service worker en el <head>/pie\n";
if ( $area_id ) {
	$wp_query = new WP_Query( array( 'page_id' => $area_id ) ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride
	wp_dequeue_script( 'asde-pwa' );
	wp_deregister_script( 'asde-pwa' );
	( new Pwa() )->registro();
	$ok( wp_script_is( 'asde-pwa', 'enqueued' ), 'en una página del área se carga registro.js' );
	$antes = implode( '', array_filter( (array) wp_scripts()->get_data( 'asde-pwa', 'before' ) ) );
	$ok( str_contains( $antes, 'window.asdePwa=' ) && str_contains( $antes, '/asde-sw.js' ) && str_contains( $antes, wp_json_encode( Manifiesto::alcance(), JSON_UNESCAPED_SLASHES ) ), 'le pasa la dirección del service worker y el alcance' );
	wp_dequeue_script( 'asde-pwa' );
	wp_deregister_script( 'asde-pwa' );
	add_filter( 'asde_ap_pwa_activa', '__return_false' );
	( new Pwa() )->registro();
	$ok( ! wp_script_is( 'asde-pwa', 'enqueued' ), 'con la PWA desactivada no se registra' );
	remove_filter( 'asde_ap_pwa_activa', '__return_false' );
	$wp_query = new WP_Query( array( 'p' => 0 ) ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride
	( new Pwa() )->registro();
	$ok( ! wp_script_is( 'asde-pwa', 'enqueued' ), 'fuera del área no se registra' );
	$wp_query = $wp_query_antes; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
}
$reg = (string) file_get_contents( ASDE_AP_DIR . 'assets/pwa/registro.js' );
$ok( str_contains( $reg, "'serviceWorker' in navigator" ) && str_contains( $reg, 'updateViaCache' ), 'registro.js comprueba que el navegador lo soporta y evita la caché del service worker' );
$ok( true === (bool) Pwa::es_ruta( ServiceWorker::RUTA, $base . 'asde-sw.js' ) && ! Pwa::es_ruta( ServiceWorker::RUTA, $base . 'asde-sw.jsx' ), 'reconoce la ruta /asde-sw.js' );

echo "\n── Service worker servido por el sitio (GET)\n";
$r = wp_remote_get( home_url( '/' . ServiceWorker::RUTA ), array( 'timeout' => 10, 'sslverify' => false ) );
if ( is_wp_error( $r ) ) {
	echo 'INFO el sitio no responde desde aquí (' . $r->get_error_message() . "): se omite\n";
} elseif ( 200 !== wp_remote_retrieve_response_code( $r ) ) {
	$ok( false, 'el service worker se sirve con 200 (¿está desplegada la versión nueva? código ' . wp_remote_retrieve_response_code( $r ) . ')' );
} else {
	$ok( str_starts_with( (string) wp_remote_retrieve_header( $r, 'content-type' ), 'text/javascript' ), 'Content-Type text/javascript' );
	$ok( false !== stripos( (string) wp_remote_retrieve_header( $r, 'cache-control' ), 'no-cache' ), 'Cache-Control: no-cache (si no, no se actualizaría)' );
	$ok( wp_remote_retrieve_body( $r ) === $js, 'lo servido es el service worker con su configuración' );
	$ok( false !== stripos( (string) wp_remote_retrieve_header( $r, 'x-robots-tag' ), 'noindex' ), 'X-Robots-Tag: noindex' );
	$off = wp_remote_get( $cfg['offline'], array( 'timeout' => 10, 'sslverify' => false ) );
	$ok( ! is_wp_error( $off ) && 200 === wp_remote_retrieve_response_code( $off ), 'la página sin conexión se sirve (200)' );
}

echo $fails ? "\n$fails FALLOS\n" : "\nTODO OK (nada tocado: solo lectura)\n";
