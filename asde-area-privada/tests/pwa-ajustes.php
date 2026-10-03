<?php
/**
 * Pruebas de los ajustes de la app móvil (v0.17.3, docs/pwa/, sección 5, entorno LOCAL):
 * la opción activa/nombre, la pantalla wp-admin → App móvil, la comprobación del servidor
 * (con respuestas simuladas: ninguna petición real) y la pieza «App móvil» de
 * «Instalación y entorno». Ejecutar desde app/public del site Local:
 *
 *   wp eval-file wp-content/plugins/asde-area-privada/tests/pwa-ajustes.php
 *
 * Cada prueba deja el sitio como estaba (opción, resultado guardado, usuarios y auditoría).
 * No usa el CRM.
 */
defined( 'ABSPATH' ) || exit;

require_once ABSPATH . 'wp-admin/includes/user.php';

use AsdeBalears\AreaPrivada\Admin\PwaPage;
use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Idiomas\Idioma;
use AsdeBalears\AreaPrivada\Install\Entorno;
use AsdeBalears\AreaPrivada\Pwa\Ajustes;
use AsdeBalears\AreaPrivada\Pwa\Comprobacion;
use AsdeBalears\AreaPrivada\Pwa\Manifiesto;
use AsdeBalears\AreaPrivada\Pwa\Pwa;
use AsdeBalears\AreaPrivada\Pwa\ServiceWorker;

global $wpdb;

$fails = 0;
$ok    = function ( $cond, string $label ) use ( &$fails ) {
	if ( ! $cond ) {
		++$fails;
	}
	echo ( $cond ? 'OK   ' : 'FAIL ' ), $label, "\n";
};

$opcion_antes = get_option( Ajustes::OPCION, null );
$log_antes    = (int) $wpdb->get_var( 'SELECT MAX(id) FROM ' . Logger::table() );
$post_antes   = $_POST;
$req_antes    = $_REQUEST;
$ids_usuarios = array();
$resp         = static function ( int $codigo, array $cab = array(), string $cuerpo = '' ) {
	return array(
		'headers'  => array_change_key_case( $cab, CASE_LOWER ),
		'body'     => $cuerpo,
		'response' => array(
			'code'    => $codigo,
			'message' => 'x',
		),
		'cookies'  => array(),
		'filename' => null,
	);
};
$anula_redir  = static function ( $loc ) {
	throw new RuntimeException( 'REDIR:' . $loc );
};
$anula_die    = static function () {
	return static function ( $mensaje ) {
		throw new RuntimeException( 'DIE' );
	};
};

try {
	// ── Ajustes ───────────────────────────────────────────────────────────────
	echo "\n── Ajustes: activa y nombre\n";
	Ajustes::restablecer();
	$ok( true === Ajustes::activa() && '' === Ajustes::nombre_corto(), 'sin guardar: activa y con el nombre de siempre (las instalaciones anteriores no cambian)' );
	$ok( true === Pwa::activa(), 'Pwa::activa() sigue la opción' );
	Ajustes::guardar( false, '  <b>Mi   App</b> larguísima  ' );
	$ok( false === Ajustes::activa(), 'se guarda apagada' );
	$ok( 'Mi App largu' === Ajustes::nombre_corto() && mb_strlen( Ajustes::nombre_corto() ) <= Ajustes::MAX_NOMBRE, 'el nombre se limpia: sin etiquetas, espacios únicos y 12 caracteres como máximo' );
	$ok( false === Pwa::activa(), 'Pwa::activa() apagada' );
	add_filter( 'asde_ap_pwa_activa', '__return_true' );
	$ok( true === Pwa::activa(), 'el filtro asde_ap_pwa_activa tiene la última palabra (encender)' );
	remove_filter( 'asde_ap_pwa_activa', '__return_true' );
	Ajustes::guardar( true, 'Mi grupo' );
	add_filter( 'asde_ap_pwa_activa', '__return_false' );
	$ok( false === Pwa::activa(), 'y también apagar' );
	remove_filter( 'asde_ap_pwa_activa', '__return_false' );
	$ok( 'Mi grupo' === Manifiesto::nombre_corto() && str_starts_with( Manifiesto::nombre(), 'Mi grupo' ), 'el manifiesto usa el nombre elegido' );
	$ok( 'Mi grupo' === Idioma::en( 'ca', static fn() => Manifiesto::nombre_corto() ), 'el nombre propio no se traduce' );
	Ajustes::guardar( true, '   ' );
	$ok( '' === Ajustes::nombre_corto() && 'Área privada' === Manifiesto::nombre_corto(), 'nombre vacío: vuelve al de siempre' );
	$ok( 'Àrea privada' === Idioma::en( 'ca', static fn() => Manifiesto::nombre_corto() ), 'y ese sí se traduce' );
	update_option( Ajustes::OPCION, 'basura', false );
	$ok( true === Ajustes::activa() && '' === Ajustes::nombre_corto(), 'una opción corrupta se trata como sin guardar' );
	Ajustes::restablecer();

	// ── Comprobación del servidor (respuestas simuladas) ──────────────────────
	echo "\n── Comprobación: cada respuesta\n";
	$m    = Pwa::RUTA_MANIFIESTO;
	$sw   = ServiceWorker::RUTA;
	$buena = $resp( 200, array( 'content-type' => 'application/manifest+json; charset=utf-8', 'cache-control' => 'no-cache' ) );
	$r     = Comprobacion::evaluar( $m, $buena );
	$ok( Entorno::OK === $r[0], 'respuesta correcta: bien' );
	$r = Comprobacion::evaluar( $m, $resp( 404 ) );
	$ok( Entorno::FALTA === $r[0] && str_contains( $r[1], '404' ) && str_contains( $r[1], 'Enlaces permanentes' ), '404: falla y da la pista de los enlaces permanentes' );
	$r = Comprobacion::evaluar( $m, $resp( 500 ) );
	$ok( Entorno::FALTA === $r[0] && ! str_contains( $r[1], 'Enlaces permanentes' ), '500: falla (sin la pista del 404)' );
	$r = Comprobacion::evaluar( $m, new WP_Error( 'x', 'cURL error 28' ) );
	$ok( Entorno::FALTA === $r[0] && str_contains( $r[1], 'cURL error 28' ), 'sin respuesta: falla y dice por qué' );
	$r = Comprobacion::evaluar( $m, $resp( 200, array( 'content-type' => 'text/html', 'cache-control' => 'no-cache' ) ) );
	$ok( Entorno::AVISO === $r[0] && str_contains( $r[1], 'application/manifest+json' ), 'tipo de contenido equivocado: aviso' );
	$r = Comprobacion::evaluar( $m, $resp( 200, array( 'content-type' => 'application/manifest+json' ) ) );
	$ok( Entorno::AVISO === $r[0] && str_contains( $r[1], 'no-cache' ), 'sin Cache-Control: aviso' );
	$r = Comprobacion::evaluar( $m, $resp( 200, array( 'content-type' => 'application/manifest+json', 'cache-control' => 'public, max-age=3600' ) ) );
	$ok( Entorno::AVISO === $r[0], 'Cache-Control con max-age y sin no-cache: aviso' );
	$r = Comprobacion::evaluar( $m, $resp( 200, array( 'content-type' => 'application/manifest+json', 'cache-control' => 'no-store' ) ) );
	$ok( Entorno::OK === $r[0], 'no-store también vale' );
	$base = array( 'content-type' => 'application/manifest+json', 'cache-control' => 'no-cache' );
	foreach (
		array(
			array( array( 'x-litespeed-cache' => 'hit' ), 'LiteSpeed' ),
			array( array( 'cf-cache-status' => 'HIT' ), 'Cloudflare' ),
			array( array( 'x-cache' => 'HIT from proxy' ), 'proxy' ),
			array( array( 'age' => '120' ), 'Age' ),
		) as list( $extra, $texto )
	) {
		$r = Comprobacion::evaluar( $m, $resp( 200, $base + $extra ) );
		$ok( Entorno::AVISO === $r[0] && str_contains( $r[1], $texto ) && str_contains( $r[1], 'apartado 6' ), "caché detectada ({$texto}): aviso con la solución" );
	}
	$r = Comprobacion::evaluar( $m, $resp( 200, $base + array( 'age' => '0', 'x-litespeed-cache' => 'miss' ) ) );
	$ok( Entorno::OK === $r[0], 'age 0 y «miss» no son caché' );
	$r = Comprobacion::evaluar( $sw, $resp( 200, array( 'content-type' => 'text/javascript; charset=utf-8', 'cache-control' => 'no-cache' ), 'AAA' ), 'AAA' );
	$ok( Entorno::OK === $r[0], 'service worker con el contenido esperado: bien' );
	$r = Comprobacion::evaluar( $sw, $resp( 200, array( 'content-type' => 'text/javascript', 'cache-control' => 'no-cache' ), 'minificado' ), 'AAA' );
	$ok( Entorno::AVISO === $r[0] && str_contains( $r[1], 'minificando' ), 'contenido distinto del que genera el plugin: aviso' );
	$r = Comprobacion::evaluar( $sw, $resp( 200, array( 'content-type' => 'text/javascript', 'cache-control' => 'no-cache' ), 'lo que sea' ) );
	$ok( Entorno::OK === $r[0], 'sin contenido esperado no se compara' );
	$ok( Entorno::FALTA === Comprobacion::peor( array( array( Entorno::OK ), array( Entorno::FALTA ), array( Entorno::AVISO ) ) ) && Entorno::AVISO === Comprobacion::peor( array( array( Entorno::OK ), array( Entorno::AVISO ), array( Entorno::INFO ) ) ) && Entorno::OK === Comprobacion::peor( array( array( Entorno::OK ), array( Entorno::INFO ) ) ), 'el peor estado manda (falla > revisar > bien)' );

	echo "\n── Comprobación: las tres direcciones (sin red)\n";
	$pedidas  = array();
	$tipos    = array(
		Pwa::RUTA_MANIFIESTO             => 'application/manifest+json; charset=utf-8',
		ServiceWorker::RUTA              => 'text/javascript; charset=utf-8',
		ServiceWorker::RUTA_SIN_CONEXION => 'text/html; charset=utf-8',
	);
	$extra_sw = array();
	$simula   = static function ( $pre, $args, $url ) use ( &$pedidas, $tipos, $resp, &$extra_sw ) {
		$pedidas[] = $url;
		foreach ( $tipos as $ruta => $tipo ) {
			if ( home_url( '/' . $ruta ) === $url ) {
				$cab = array( 'content-type' => $tipo, 'cache-control' => 'no-cache' );
				return $resp( 200, $cab + ( ServiceWorker::RUTA === $ruta ? $extra_sw : array() ), Comprobacion::esperado( $ruta ) );
			}
		}
		return $resp( 404 );
	};
	add_filter( 'pre_http_request', $simula, 1, 3 );
	delete_transient( Comprobacion::RESULTADO );
	$ok( null === Comprobacion::ultimo(), 'sin comprobar: no hay resultado guardado' );
	$res = Comprobacion::ejecutar();
	$ok( 3 === count( $pedidas ) && 3 === count( $res['filas'] ) && true === $res['activa'], 'pide las tres direcciones y las juzga' );
	$ok( Entorno::OK === Comprobacion::peor( $res['filas'] ), 'con todo bien, todo bien (también el contenido de las tres)' );
	$ok( $res === Comprobacion::ultimo(), 'el resultado queda guardado para la pantalla' );
	$extra_sw = array( 'x-litespeed-cache' => 'hit' );
	$res      = Comprobacion::ejecutar();
	$ok( Entorno::AVISO === $res['filas'][ ServiceWorker::RUTA ][0] && Entorno::OK === $res['filas'][ Pwa::RUTA_MANIFIESTO ][0], 'si una está cacheada, solo esa avisa' );
	$extra_sw = array();
	Ajustes::guardar( false, '' );
	$pedidas = array();
	$res     = Comprobacion::ejecutar();
	$ok( ! $pedidas && ! $res['filas'] && false === $res['activa'], 'con la app apagada no pide nada' );
	remove_filter( 'pre_http_request', $simula, 1 );
	Ajustes::restablecer();
	delete_transient( Comprobacion::RESULTADO );

	echo "\n── Comprobación: servidor con pocos procesos PHP (tiempo agotado)\n";
	$intentos = array();
	$lento    = static function ( $pre, $args, $url ) use ( &$intentos, $simula ) {
		$intentos[ $url ] = ( $intentos[ $url ] ?? 0 ) + 1;
		if ( home_url( '/' . ServiceWorker::RUTA ) === $url && 1 === $intentos[ $url ] ) {
			return new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out after 5001 milliseconds with 0 bytes received' );
		}
		return $simula( $pre, $args, $url );
	};
	add_filter( 'pre_http_request', $lento, 1, 3 );
	$res = Comprobacion::ejecutar();
	$ok( 2 === $intentos[ home_url( '/' . ServiceWorker::RUTA ) ] && 1 === $intentos[ home_url( '/' . Pwa::RUTA_MANIFIESTO ) ], 'si una dirección agota el tiempo, se repite solo esa, una vez' );
	$ok( Entorno::OK === Comprobacion::peor( $res['filas'] ), 'y con la segunda respuesta sale bien' );
	remove_filter( 'pre_http_request', $lento, 1 );
	$cuenta   = 0;
	$siempre  = static function ( $pre, $args, $url ) use ( &$cuenta ) {
		++$cuenta;
		return new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );
	};
	add_filter( 'pre_http_request', $siempre, 1, 3 );
	$res = Comprobacion::ejecutar();
	$ok( 6 === $cuenta && Entorno::FALTA === Comprobacion::peor( $res['filas'] ) && str_contains( $res['filas'][ Pwa::RUTA_MANIFIESTO ][2], 'muy pocos procesos PHP' ), 'si agota el tiempo dos veces, falla (dos intentos por dirección, nunca más) y explica la causa probable' );
	remove_filter( 'pre_http_request', $siempre, 1 );
	$cuenta = 0;
	$otro   = static function ( $pre, $args, $url ) use ( &$cuenta, $resp ) {
		++$cuenta;
		return new WP_Error( 'http_request_failed', 'Could not resolve host' );
	};
	add_filter( 'pre_http_request', $otro, 1, 3 );
	Comprobacion::ejecutar();
	$ok( 3 === $cuenta, 'otros errores de red no se repiten' );
	remove_filter( 'pre_http_request', $otro, 1 );
	delete_transient( Comprobacion::RESULTADO );

	// ── HTTPS ─────────────────────────────────────────────────────────────────
	echo "\n── Contexto seguro\n";
	$home = static function ( string $url ) {
		return static fn() => $url;
	};
	foreach (
		array(
			'https://baleares.scout.es'  => true,
			'http://localhost:10011'     => true,
			'http://127.0.0.1:8080'      => true,
			'http://grupo.localhost'     => true,
			'http://baleares.scout.es'   => false,
			'http://localhost.ejemplo.es' => false,
		) as $url => $esperado
	) {
		$f = $home( $url );
		add_filter( 'home_url', $f, 99 );
		$ok( $esperado === Comprobacion::contexto_seguro(), ( $esperado ? 'seguro: ' : 'no seguro: ' ) . $url );
		remove_filter( 'home_url', $f, 99 );
	}

	// ── Pieza de «Instalación y entorno» ──────────────────────────────────────
	echo "\n── Instalación y entorno: App móvil\n";
	$e = Entorno::comprobar();
	$ok( isset( $e['pwa'] ) && 4 === count( $e['pwa'] ) && 'App móvil (PWA)' === $e['pwa'][1] && isset( $e['pwa'][3]['App móvil'] ) && PwaPage::url() === $e['pwa'][3]['App móvil'], 'está entre las piezas, con el enlace a la pantalla «App móvil»' );
	$perma = static fn() => '/%postname%/';
	$sin_i = static fn() => 0;
	add_filter( 'pre_option_permalink_structure', $perma, 99 );
	add_filter( 'pre_option_site_icon', $sin_i, 99 );
	$p = Comprobacion::entorno();
	$ok( Entorno::INFO === $p[0] && str_contains( $p[2], 'icono genérico' ) && isset( $p[3]['Icono del sitio'] ), 'sin «Icono del sitio»: información, con el enlace para ponerlo' );
	remove_filter( 'pre_option_site_icon', $sin_i, 99 );
	$con_i   = static fn() => 999;
	$url_i   = static fn( $url, $size ) => 'https://ejemplo.test/icono-' . $size . '.png';
	add_filter( 'pre_option_site_icon', $con_i, 99 );
	add_filter( 'get_site_icon_url', $url_i, 99, 2 );
	$p = Comprobacion::entorno();
	$ok( Entorno::OK === $p[0] && str_contains( $p[2], 'Activa' ) && str_contains( $p[2], Manifiesto::alcance() ), 'con icono, enlaces bonitos y HTTPS/local: bien, y dice el alcance' );
	$simple = static fn() => '';
	remove_filter( 'pre_option_permalink_structure', $perma, 99 );
	add_filter( 'pre_option_permalink_structure', $simple, 99 );
	$p = Comprobacion::entorno();
	$ok( Entorno::AVISO === $p[0] && str_contains( $p[2], 'todo el sitio' ), 'enlaces simples: aviso (la app abarcaría todo el sitio)' );
	remove_filter( 'pre_option_permalink_structure', $simple, 99 );
	add_filter( 'pre_option_permalink_structure', $perma, 99 );
	$h = $home( 'http://baleares.ejemplo.test' );
	add_filter( 'home_url', $h, 99 );
	$p = Comprobacion::entorno();
	$ok( Entorno::FALTA === $p[0] && str_contains( $p[2], 'HTTPS' ), 'sin HTTPS: falla' );
	remove_filter( 'home_url', $h, 99 );
	Ajustes::guardar( false, '' );
	$p = Comprobacion::entorno();
	$ok( Entorno::INFO === $p[0] && str_contains( $p[2], 'Apagada' ), 'apagada: información' );
	Ajustes::restablecer();
	remove_filter( 'pre_option_site_icon', $con_i, 99 );
	remove_filter( 'get_site_icon_url', $url_i, 99 );
	remove_filter( 'pre_option_permalink_structure', $perma, 99 );

	// ── Pantalla wp-admin → App móvil ─────────────────────────────────────────
	echo "\n── Pantalla «App móvil» (guardar y comprobar)\n";
	$crear = static function ( string $rol ) use ( &$ids_usuarios ) {
		$login = 'prueba-pwa-aj-' . wp_generate_password( 6, false );
		$id    = wp_insert_user(
			array(
				'user_login' => $login,
				'user_pass'  => wp_generate_password( 20 ),
				'user_email' => $login . '@example.invalid',
				'role'       => $rol,
			)
		);
		if ( is_int( $id ) ) {
			$ids_usuarios[] = $id;
		}
		return $id;
	};
	$admin = $crear( 'administrator' );
	$suscr = $crear( 'subscriber' );
	$ok( is_int( $admin ) && is_int( $suscr ), 'usuarios de prueba creados (administrador y suscriptor)' );
	add_filter( 'wp_redirect', $anula_redir );
	add_filter( 'wp_die_handler', $anula_die );
	$lanzar = static function ( callable $f ) {
		try {
			$f();
		} catch ( RuntimeException $e ) {
			return $e->getMessage();
		}
		return 'SIN-SALIDA';
	};
	$pagina = new PwaPage();

	// Sin permiso.
	wp_set_current_user( (int) $suscr );
	$_POST = $_REQUEST = array( 'activa' => '', 'nombre_corto' => 'Hackeado', '_wpnonce' => wp_create_nonce( 'asde_ap_pwa' ) );
	$ok( 'DIE' === $lanzar( array( $pagina, 'handle_guardar' ) ) && true === Ajustes::activa() && '' === Ajustes::nombre_corto(), 'un suscriptor no puede guardar (aunque lleve nonce)' );
	$ok( 'DIE' === $lanzar( array( $pagina, 'handle_comprobar' ) ), 'ni comprobar' );
	ob_start();
	$pagina->render();
	$ok( '' === ob_get_clean(), 'ni ve la pantalla' );

	// Administrador sin nonce / con nonce de otra acción.
	wp_set_current_user( (int) $admin );
	$_POST = $_REQUEST = array( 'nombre_corto' => 'Sin nonce' );
	$ok( 'DIE' === $lanzar( array( $pagina, 'handle_guardar' ) ) && '' === Ajustes::nombre_corto(), 'un administrador sin nonce no guarda' );
	$_POST = $_REQUEST = array( 'nombre_corto' => 'Otro nonce', '_wpnonce' => wp_create_nonce( 'otra_accion' ) );
	$ok( 'DIE' === $lanzar( array( $pagina, 'handle_guardar' ) ) && '' === Ajustes::nombre_corto(), 'ni con el nonce de otra acción' );

	// Administrador con nonce: apagar y poner nombre.
	$_POST = $_REQUEST = array( 'nombre_corto' => '  Scouts <i>684</i> ', '_wpnonce' => wp_create_nonce( 'asde_ap_pwa' ) );
	$salida = $lanzar( array( $pagina, 'handle_guardar' ) );
	$ok( 'REDIR:' . PwaPage::url() === $salida, 'guardar redirige a la pantalla' );
	$ok( false === Ajustes::activa() && 'Scouts 684' === Ajustes::nombre_corto(), 'sin marcar «Activa» se apaga, y el nombre se limpia' );
	$filas = $wpdb->get_results( $wpdb->prepare( 'SELECT detalles FROM ' . Logger::table() . " WHERE id > %d AND accion = 'pwa_guardada'", $log_antes ), ARRAY_A ); // phpcs:ignore WordPress.DB
	$ok( 1 === count( $filas ) && str_contains( (string) $filas[0]['detalles'], '"activa":false' ) && str_contains( (string) $filas[0]['detalles'], '"nombre_propio":true' ), 'queda en la auditoría' );
	$ok( 1 === count( $filas ) && ! str_contains( (string) $filas[0]['detalles'], 'Scouts' ), 'la auditoría guarda que hay nombre propio, no el nombre' );
	// Volver a encender.
	$_POST = $_REQUEST = array( 'activa' => '1', 'nombre_corto' => '', '_wpnonce' => wp_create_nonce( 'asde_ap_pwa' ) );
	$lanzar( array( $pagina, 'handle_guardar' ) );
	$ok( true === Ajustes::activa() && '' === Ajustes::nombre_corto(), 'marcando «Activa» se enciende y el nombre vacío vuelve al de siempre' );

	// Comprobar.
	add_filter( 'pre_http_request', $simula, 1, 3 );
	delete_transient( Comprobacion::RESULTADO );
	$_POST = $_REQUEST = array( '_wpnonce' => wp_create_nonce( 'asde_ap_pwa_comprobar' ) );
	$ok( 'REDIR:' . PwaPage::url() . '#asde-pwa-servidor' === $lanzar( array( $pagina, 'handle_comprobar' ) ) && null !== Comprobacion::ultimo(), 'comprobar guarda el resultado y vuelve a la pantalla' );
	$_POST = $_REQUEST = array( '_wpnonce' => wp_create_nonce( 'asde_ap_pwa' ) );
	delete_transient( Comprobacion::RESULTADO );
	$ok( 'DIE' === $lanzar( array( $pagina, 'handle_comprobar' ) ) && null === Comprobacion::ultimo(), 'comprobar con el nonce de guardar no vale' );

	// Pantalla.
	$_POST = $_REQUEST = array( '_wpnonce' => wp_create_nonce( 'asde_ap_pwa_comprobar' ) );
	$lanzar( array( $pagina, 'handle_comprobar' ) );
	ob_start();
	$pagina->render();
	$html = (string) ob_get_clean();
	$ok( str_contains( $html, '<h1>App móvil</h1>' ) && str_contains( $html, 'name="nombre_corto"' ) && str_contains( $html, 'name="activa"' ) && str_contains( $html, 'name="_wpnonce"' ), 'la pantalla tiene el formulario con su nonce' );
	$ok( str_contains( $html, '/asde-manifest.webmanifest' ) && str_contains( $html, '/asde-sw.js' ) && str_contains( $html, '/asde-sin-conexion' ), 'enseña las tres direcciones de la app' );
	$ok( str_contains( $html, Manifiesto::alcance() ) && str_contains( $html, Manifiesto::color_tema() ) && str_contains( $html, 'Última comprobación' ), 'enseña el alcance, el color y la última comprobación' );
	$ok( str_contains( $html, '✅ Bien' ) && ! str_contains( $html, '❌' ), 'las tres direcciones salen bien con las respuestas simuladas' );
	$ok( str_contains( $html, 'checked' ), 'la casilla «Activa» sale marcada' );
	delete_transient( Comprobacion::RESULTADO );
	ob_start();
	$pagina->render();
	$ok( ! str_contains( (string) ob_get_clean(), 'Última comprobación' ), 'sin comprobar no hay resultados' );
	remove_filter( 'pre_http_request', $simula, 1 );
} finally {
	remove_filter( 'wp_redirect', $anula_redir );
	remove_filter( 'wp_die_handler', $anula_die );
	$_POST    = $post_antes;
	$_REQUEST = $req_antes;
	wp_set_current_user( 0 );
	foreach ( $ids_usuarios as $id ) {
		wp_delete_user( (int) $id );
	}
	if ( null === $opcion_antes ) {
		delete_option( Ajustes::OPCION );
	} else {
		update_option( Ajustes::OPCION, $opcion_antes, false );
	}
	delete_transient( Comprobacion::RESULTADO );
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Logger::table() . " WHERE id > %d AND accion = 'pwa_guardada'", $log_antes ) ); // phpcs:ignore WordPress.DB
}

echo "\n── Limpieza\n";
$ok( $opcion_antes === get_option( Ajustes::OPCION, null ), 'la opción vuelve a estar como estaba' );
$ok( null === Comprobacion::ultimo(), 'sin resultado guardado' );
$quedan = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users} WHERE user_login LIKE 'prueba-pwa-aj-%'" ); // phpcs:ignore WordPress.DB
$ok( 0 === $quedan, 'sin usuarios de la prueba' );
$ok( 0 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Logger::table() . " WHERE id > %d AND accion = 'pwa_guardada'", $log_antes ) ), 'sin filas de auditoría de la prueba' ); // phpcs:ignore WordPress.DB

echo $fails ? "\n$fails FALLOS\n" : "\nTODO OK (nada tocado en el CRM real; el sitio queda como estaba)\n";
