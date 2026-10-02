<?php
/**
 * Pruebas de la v0.15 (entorno LOCAL): el plugin no depende del tema.
 * Ejecutar desde app/public del site Local:
 *
 *   wp eval-file <ruta completa>/wp-content/plugins/asde-area-privada/tests/tema.php
 *
 * Se simula otro tema con el filtro `asde_ap_tema_slug` (no se cambia el tema del sitio).
 * Los metadatos de página que se tocan se restauran al terminar.
 */
defined( 'ABSPATH' ) || exit;

use AsdeBalears\AreaPrivada\Frontend\Pages;
use AsdeBalears\AreaPrivada\Frontend\Tema;
use AsdeBalears\AreaPrivada\Install\Entorno;

$fails = 0;
$ok    = function ( $cond, string $label ) use ( &$fails ) {
	if ( ! $cond ) {
		++$fails;
	}
	echo ( $cond ? 'OK   ' : 'FAIL ' ), $label, "\n";
};

$ids   = array_values( Pages::ids() );
$antes = array();
foreach ( $ids as $id ) {
	$antes[ $id ] = array(
		'layout'           => get_post_meta( $id, 'layout', true ),
		'header_title_bar' => get_post_meta( $id, 'header_title_bar', true ),
	);
}
$como_tema = static function ( string $slug ): callable {
	$f = static fn() => $slug;
	add_filter( 'asde_ap_tema_slug', $f );
	return static function () use ( $f ) {
		remove_filter( 'asde_ap_tema_slug', $f );
	};
};
$admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) )[0] ?? null;
if ( ! $admin ) {
	exit( "Hace falta al menos un administrador en el sitio.\n" );
}
$nav = static function () use ( $admin ): string {
	return \AsdeBalears\AreaPrivada\Frontend\TemplateLoader::render(
		'comun/nav',
		array(
			'items'  => array( 'area' => 'Inicio' ),
			'grupos' => array(),
			'activo' => 'area',
			'pagina' => 'area',
			'user'   => $admin,
			'nombre' => 'Prueba',
		)
	);
};

try {
	echo "== Parte A · Qué tema hay y cuál conviene ==\n";
	$ok( 'enfold' === Tema::IDEAL && Tema::es_ideal() === ( 'enfold' === get_template() ), 'el tema ideal es Enfold; se detecta por la plantilla activa (también con un tema hijo)' );
	$quitar = $como_tema( 'twentytwentyfive' );
	$ok( ! Tema::es_ideal() && 'twentytwentyfive' === Tema::slug(), 'con otro tema (simulado) ya no es el ideal' );
	$r = Tema::resumen();
	$ok( 'info' === $r['nivel'] && ! $r['ideal'] && str_contains( $r['estado'], 'no es obligatorio usar Enfold' ) && str_contains( $r['estado'], 'Colores' ), 'el resumen con otro tema es informativo (no un error) y dice que no es obligatorio Enfold' );
	$quitar();
	$quitar = $como_tema( 'enfold' );
	$r      = Tema::resumen();
	$ok( 'ok' === $r['nivel'] && $r['ideal'] && str_contains( $r['estado'], 'diseñado' ), 'con Enfold: todo en orden' );
	$quitar();
	$c = Tema::consejo();
	$ok( str_contains( $c, 'Enfold' ) && str_contains( $c, 'ancho completo' ) && str_contains( $c, 'barra lateral' ) && str_contains( $c, 'de bloques' ), 'el consejo dice cuál es el ideal y qué tipo de tema sirve (ancho completo, sin barra lateral; también los de bloques)' );

	echo "\n== Parte B · Ajustes de página: solo con Enfold ==\n";
	foreach ( $ids as $id ) {
		delete_post_meta( $id, 'layout' );
		delete_post_meta( $id, 'header_title_bar' );
	}
	$quitar = $como_tema( 'twentytwentyfive' );
	Tema::ajustar_paginas( $ids );
	$tocadas = array_filter( $ids, static fn( $id ) => '' !== get_post_meta( $id, 'layout', true ) || '' !== get_post_meta( $id, 'header_title_bar', true ) );
	$ok( ! $tocadas, 'con otro tema no se escribe ningún metadato de Enfold en las páginas' );
	$quitar();
	$quitar = $como_tema( 'enfold' );
	Tema::ajustar_paginas( $ids );
	$bien = array_filter( $ids, static fn( $id ) => 'fullsize' === get_post_meta( $id, 'layout', true ) && 'hidden_title_bar' === get_post_meta( $id, 'header_title_bar', true ) );
	$ok( count( $bien ) === count( $ids ) && $ids, 'con Enfold, las páginas del área quedan a ancho completo y sin barra de título' );
	$quitar();
	foreach ( $ids as $id ) {
		delete_post_meta( $id, 'layout' );
		delete_post_meta( $id, 'header_title_bar' );
	}
	$quitar = $como_tema( 'enfold' );
	do_action( 'after_switch_theme' );
	$quitar();
	$ok( 'fullsize' === get_post_meta( $ids[0], 'layout', true ), 'al cambiar a Enfold (after_switch_theme) se aplican solos' );

	echo "\n== Parte C · La cabecera plegable es de Enfold ==\n";
	$quitar = $como_tema( 'twentytwentyfive' );
	$ok( ! Tema::cabecera_plegable() && ! str_contains( $nav(), 'asde-nav__cabecera' ), 'con otro tema no sale el botón «Ocultar cabecera»' );
	add_filter( 'asde_ap_cabecera_plegable', '__return_true' );
	$ok( Tema::cabecera_plegable() && str_contains( $nav(), 'asde-nav__cabecera' ), 'un tema con la cabecera en #header puede pedirla con el filtro asde_ap_cabecera_plegable' );
	remove_filter( 'asde_ap_cabecera_plegable', '__return_true' );
	$quitar();
	$quitar = $como_tema( 'enfold' );
	$ok( Tema::cabecera_plegable() && str_contains( $nav(), 'asde-nav__cabecera' ) && ! str_contains( $nav(), 'morada' ), 'con Enfold el botón sale (y no habla de «morada», que es del diseño de un sitio concreto)' );
	$quitar();

	echo "\n== Parte D · «Instalación y entorno» ==\n";
	$quitar = $como_tema( 'twentytwentyfive' );
	$e      = Entorno::comprobar();
	$ok( Entorno::INFO === $e['tema'][0] && str_contains( $e['tema'][2], 'no es obligatorio' ) && Entorno::INFO === $e['tema_consejo'][0] && 4 === count( $e['tema'] ) && 4 === count( $e['tema_consejo'] ), 'con otro tema: aviso informativo + una fila «Qué tema conviene», sin dar el entorno por roto' );
	$quitar();
	$quitar = $como_tema( 'enfold' );
	$e      = Entorno::comprobar();
	$ok( Entorno::OK === $e['tema'][0], 'con Enfold: OK' );
	$quitar();

	echo "\n== Parte E · Nada más de Enfold en el código que se ejecuta ==\n";
	$fuentes = array( 'src/Frontend/Pages.php', 'src/Frontend/Router.php', 'src/Install/Entorno.php', 'templates/comun/nav.php' );
	$sueltos = array();
	foreach ( $fuentes as $f ) {
		$texto = (string) file_get_contents( ASDE_AP_DIR . $f );
		if ( preg_match( "/(update_post_meta|get_template\\(\\))[^;]*['\"](layout|header_title_bar|enfold)['\"]/", $texto ) ) {
			$sueltos[] = $f;
		}
	}
	$ok( ! $sueltos, 'las páginas, el enrutado, el entorno y el menú no tocan metadatos ni detectan Enfold por su cuenta: todo pasa por Tema' );
} finally {
	foreach ( $antes as $id => $meta ) {
		foreach ( $meta as $clave => $valor ) {
			'' === $valor ? delete_post_meta( $id, $clave ) : update_post_meta( $id, $clave, $valor );
		}
	}
	$quedan = array_filter( $ids, static fn( $id ) => get_post_meta( $id, 'layout', true ) !== $antes[ $id ]['layout'] || get_post_meta( $id, 'header_title_bar', true ) !== $antes[ $id ]['header_title_bar'] );
	$ok( ! $quedan, 'limpieza: los metadatos de las páginas, como estaban' );
}

echo $fails ? "\n$fails FALLOS\n" : "\nTODO OK (el sitio queda como estaba)\n";
