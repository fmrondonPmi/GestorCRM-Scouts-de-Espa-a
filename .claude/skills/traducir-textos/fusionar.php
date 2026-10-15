<?php
/**
 * Añade al final de languages/ca.php, eu.php y gl.php, en un bloque «// <Grupo>», las traducciones de
 * <carpeta>/trad-ca.txt, trad-eu.txt y trad-gl.txt («N|traducción», en el orden de faltan.txt).
 *
 * Uso: php fusionar.php <ruta-al-plugin> <carpeta de trabajo> "<Grupo>"
 *
 * Comprueba las tres antes de escribir nada (todo o nada): mismo número de líneas, numeración seguida,
 * mismos huecos (%s, %d, %1$s, %%) y mismas etiquetas HTML que el castellano, y ninguna vacía.
 * Un texto que ya esté en un catálogo no se repite. Si el catálogo escrito no se puede leer, se restaura.
 */
define( 'ABSPATH', '/' );
if ( $argc < 4 ) {
	fwrite( STDERR, "Uso: php fusionar.php <ruta-al-plugin> <carpeta de trabajo> \"<Grupo>\"\n" );
	exit( 2 );
}
list( , $dir, $sdir, $grupo ) = $argv;
$dir    = rtrim( $dir, '/' );
$claves = json_decode( (string) file_get_contents( "$sdir/faltan.json" ), true );
if ( ! is_array( $claves ) ) {
	fwrite( STDERR, "No se puede leer $sdir/faltan.json (lanza antes faltan.php)\n" );
	exit( 2 );
}
$marcas    = static function ( string $t ): array {
	preg_match_all( '/%(?:\d+\$)?[sdf]|%%/', $t, $m );
	sort( $m[0] );
	return $m[0];
};
$etiquetas = static function ( string $t ): array {
	preg_match_all( '/<\/?([a-z]+)/i', $t, $m );
	$o = array_map( 'strtolower', $m[1] );
	sort( $o );
	return $o;
};
$errores = 0;
$nuevas  = array();
foreach ( array( 'ca', 'eu', 'gl' ) as $cod ) {
	$fichero = "$sdir/trad-$cod.txt";
	if ( ! is_readable( $fichero ) ) {
		echo "$cod: falta $fichero\n";
		++$errores;
		continue;
	}
	$lineas = array_values( array_filter( explode( "\n", str_replace( "\r", '', file_get_contents( $fichero ) ) ), 'strlen' ) );
	if ( count( $lineas ) !== count( $claves ) ) {
		echo "$cod: " . count( $lineas ) . ' líneas para ' . count( $claves ) . " textos\n";
		++$errores;
		continue;
	}
	foreach ( $lineas as $i => $l ) {
		$partes = explode( '|', $l, 2 );
		if ( 2 !== count( $partes ) || (string) (int) $partes[0] !== $partes[0] || (int) $partes[0] !== $i ) {
			echo "$cod: la línea $i no empieza por «$i|»\n";
			++$errores;
			continue;
		}
		$es = $claves[ $i ];
		$t  = trim( $partes[1] );
		if ( false !== strpos( $es, "\n" ) ) {
			$t = str_replace( '\n', "\n", $t );
		}
		if ( '' === $t ) {
			echo "$cod $i: vacía\n";
			++$errores;
		} elseif ( $marcas( $es ) !== $marcas( $t ) ) {
			echo "$cod $i: huecos distintos: «{$es}» → «{$t}»\n";
			++$errores;
		} elseif ( $etiquetas( $es ) !== $etiquetas( $t ) ) {
			echo "$cod $i: etiquetas HTML distintas: «{$es}» → «{$t}»\n";
			++$errores;
		}
		$nuevas[ $cod ][ $es ] = $t;
	}
}
if ( $errores ) {
	echo "$errores errores: no se ha escrito nada\n";
	exit( 1 );
}
foreach ( $nuevas as $cod => $lista ) {
	$fichero = "$dir/languages/$cod.php";
	$antes   = file_get_contents( $fichero );
	$cat     = include $fichero;
	$bloque  = "\n\t// " . str_replace( array( "\r", "\n" ), ' ', $grupo ) . "\n";
	$n       = 0;
	foreach ( $lista as $es => $t ) {
		if ( isset( $cat[ $es ] ) ) {
			continue;
		}
		$bloque .= "\t" . var_export( $es, true ) . ' => ' . var_export( $t, true ) . ",\n";
		++$n;
	}
	if ( ! $n ) {
		echo "$cod: nada que añadir\n";
		continue;
	}
	$fin = strrpos( $antes, ');' );
	if ( false === $fin ) {
		echo "$cod: no encuentro el cierre «);» de $fichero\n";
		exit( 1 );
	}
	$php = rtrim( substr( $antes, 0, $fin ) ) . "\n" . $bloque . ");\n";
	file_put_contents( $fichero, $php );
	// Comprobar que el catálogo sigue siendo PHP válido y tiene lo que se ha añadido.
	exec( 'php -l ' . escapeshellarg( $fichero ) . ' 2>&1', $salida, $rc );
	$despues = 0 === $rc ? include $fichero : null;
	if ( ! is_array( $despues ) || count( $despues ) !== count( $cat ) + $n ) {
		file_put_contents( $fichero, $antes );
		echo "$cod: el catálogo resultante no es válido; restaurado\n";
		exit( 1 );
	}
	echo "$cod: $n añadidas, " . count( $despues ) . " en total\n";
}
