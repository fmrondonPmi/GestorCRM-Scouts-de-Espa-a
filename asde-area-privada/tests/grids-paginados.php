<?php
/**
 * Grids paginados (v0.61, doc 64, skill «grids-area-privada»): TODA tabla de datos del área privada va paginada.
 *   A. Cada <table> de templates/ (menos los emails) lleva exactamente UNA marca: data-asde-paginar (la pagina grids.js),
 *      data-asde-paginada="servidor" (la plantilla usa Tabla::paginacion()), data-asde-tabla (listado de Usuarios, JS propio)
 *      o data-asde-sin-paginar="motivo" (excepción con motivo escrito: tabla fija, editor de filas, versión para imprimir).
 *   B. Las que dicen paginar en el servidor llaman de verdad a paginacion() en su plantilla.
 *   C. Las excepciones se cuentan y no pueden crecer sin pensarlo (tope = las del doc 64).
 *   D. El paginador del navegador existe, se encola en el Router y ofrece 5 y 10 filas por página (10 por defecto).
 *   E. Ninguna clase de src/ imprime una <table> propia (las tablas viven en plantillas, donde se auditan).
 * Solo lee ficheros. Ejecutar desde app/public del site Local:
 *
 *   wp eval-file wp-content/plugins/asde-area-privada/tests/grids-paginados.php
 */
defined( 'ABSPATH' ) || exit;

$fails = 0;
$ok    = function ( $cond, string $label ) use ( &$fails ) {
	if ( ! $cond ) {
		++$fails;
	}
	echo ( $cond ? 'OK   ' : 'FAIL ' ), $label, "\n";
};

$raiz = rtrim( str_replace( chr( 92 ), '/', ASDE_AP_DIR ), '/' );
$leer = static fn( string $p ): string => (string) file_get_contents( $p );

/** @return string[] Rutas de los .php de un directorio, recursivo. */
$php_de = static function ( string $dir ): array {
	$out = array();
	foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) ) as $f ) {
		if ( 'php' === $f->getExtension() ) {
			$out[] = str_replace( chr( 92 ), '/', $f->getPathname() );
		}
	}
	sort( $out );
	return $out;
};

$sin_marca  = array();
$doble      = array();
$sin_motivo = array();
$srv_falso  = array();
$excepciones = 0;
$tablas     = 0;
foreach ( $php_de( $raiz . '/templates' ) as $p ) {
	$rel = substr( $p, strlen( $raiz ) + 1 );
	if ( str_starts_with( $rel, 'templates/email/' ) ) {
		continue; // Los emails llevan tablas para el cliente de correo, no son pantallas.
	}
	$src = $leer( $p );
	if ( ! preg_match_all( '/<table\b[^>]*>/', $src, $m ) ) {
		continue;
	}
	foreach ( $m[0] as $i => $tag ) {
		++$tablas;
		$marcas = (int) (bool) preg_match( '/\sdata-asde-paginar(\s|>|=)/', $tag )
			+ (int) (bool) preg_match( '/\sdata-asde-paginada="servidor"/', $tag )
			+ (int) (bool) preg_match( '/\sdata-asde-tabla(\s|>)/', $tag )
			+ (int) (bool) preg_match( '/\sdata-asde-sin-paginar=/', $tag );
		$nombre = $rel . ' #' . ( $i + 1 );
		if ( 0 === $marcas ) {
			$sin_marca[] = $nombre;
		} elseif ( $marcas > 1 ) {
			$doble[] = $nombre;
		}
		if ( preg_match( '/\sdata-asde-sin-paginar="([^"]*)"/', $tag, $mm ) ) {
			++$excepciones;
			if ( strlen( trim( $mm[1] ) ) < 8 ) {
				$sin_motivo[] = $nombre;
			}
		}
		if ( preg_match( '/\sdata-asde-paginada="servidor"/', $tag ) && ! str_contains( $src, 'paginacion()' ) ) {
			$srv_falso[] = $nombre;
		}
	}
}
$ok( $tablas > 80, "se han encontrado las tablas de las plantillas ($tablas)" );
$ok( ! $sin_marca, 'toda tabla de una pantalla está paginada o es una excepción con motivo' . ( $sin_marca ? ': ' . implode( ', ', $sin_marca ) : '' ) );
$ok( ! $doble, 'ninguna tabla lleva dos marcas de paginación' . ( $doble ? ': ' . implode( ', ', $doble ) : '' ) );
$ok( ! $sin_motivo, 'las excepciones (data-asde-sin-paginar) explican el motivo' . ( $sin_motivo ? ': ' . implode( ', ', $sin_motivo ) : '' ) );
$ok( ! $srv_falso, 'las tablas «paginada=servidor» llaman a Tabla::paginacion() en su plantilla' . ( $srv_falso ? ': ' . implode( ', ', $srv_falso ) : '' ) );
$ok( $excepciones <= 29, "las excepciones no crecen sin revisarlas con el usuario ($excepciones, tope 29; ver docs/64)" );

// D. El paginador del navegador.
$js = $raiz . '/assets/js/grids.js';
$ok( is_file( $js ), 'existe assets/js/grids.js' );
$codigo = is_file( $js ) ? $leer( $js ) : '';
$ok( str_contains( $codigo, 'table[data-asde-paginar]' ) && str_contains( $codigo, 'asde_ap_por_pagina' ), 'grids.js pagina table[data-asde-paginar] y recuerda el tamaño elegido' );
$ok( str_contains( $codigo, '? 5 : 10' ), 'grids.js: 10 filas por defecto y 5 en pantallas estrechas' );
$router = $leer( $raiz . '/src/Frontend/Router.php' );
$ok( str_contains( $router, "'asde-grids'" ) && str_contains( $router, 'assets/js/grids.js' ), 'el Router encola grids.js' );
$ok( in_array( 5, \AsdeBalears\AreaPrivada\Frontend\Tabla::POR_PAGINA, true ) && in_array( 10, \AsdeBalears\AreaPrivada\Frontend\Tabla::POR_PAGINA, true ) && 10 === \AsdeBalears\AreaPrivada\Frontend\Tabla::POR_PAGINA_DEFECTO, 'Tabla ofrece 5 y 10 filas por página y 10 por defecto' );
$css = $leer( $raiz . '/assets/css/area-privada.css' );
$ok( str_contains( $css, 'tr.asde-grid-oculta' ), 'el CSS oculta las filas fuera de la página (también en tarjetas)' );

// E. Sin tablas escritas a mano en las clases.
$propias = array();
foreach ( $php_de( $raiz . '/src' ) as $p ) {
	if ( str_contains( $p, '/src/Admin/' ) ) {
		continue; // wp-admin no es el área privada.
	}
	if ( preg_match( '/<table\b/', $leer( $p ) ) ) {
		$propias[] = substr( $p, strlen( $raiz ) + 1 );
	}
}
$ok( ! $propias, 'ninguna clase de src/ imprime una <table> (van en plantillas)' . ( $propias ? ': ' . implode( ', ', $propias ) : '' ) );

echo $fails ? "\n$fails FALLOS\n" : "\nTODO OK (solo lectura de ficheros)\n";
