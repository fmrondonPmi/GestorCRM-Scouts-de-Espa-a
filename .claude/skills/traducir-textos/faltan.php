<?php
/**
 * Lista los textos de languages/es.php que aún no están en ca.php, eu.php o gl.php (y los que sobran),
 * y los deja numerados para traducirlos.
 *
 * Uso: php faltan.php <ruta-al-plugin> <carpeta de trabajo>
 *   Escribe <carpeta>/faltan.json (las claves, en orden) y <carpeta>/faltan.txt («N|texto», una por línea).
 *   No necesita WordPress. Antes hay que regenerar es.php (herramientas/generar-es.php).
 */
define( 'ABSPATH', '/' );
if ( $argc < 3 ) {
	fwrite( STDERR, "Uso: php faltan.php <ruta-al-plugin> <carpeta de trabajo>\n" );
	exit( 2 );
}
list( , $dir, $sal ) = $argv;
$dir = rtrim( $dir, '/' );
$es  = include $dir . '/languages/es.php';
if ( ! is_array( $es ) ) {
	fwrite( STDERR, "No se puede leer $dir/languages/es.php\n" );
	exit( 2 );
}
$union = array();
foreach ( array( 'ca', 'eu', 'gl' ) as $cod ) {
	$cat   = include "$dir/languages/$cod.php";
	$falta = array_diff_key( $es, $cat );
	$sobra = array_diff_key( $cat, $es );
	echo "$cod: faltan " . count( $falta ) . ', sobran ' . count( $sobra ) . "\n";
	foreach ( array_keys( $sobra ) as $k ) {
		// Lo que sobra es un texto que ya no está en el código: se quita a mano del catálogo (o se deja,
		// si se va a volver a usar), pero idiomas-catalogos.php lo cuenta como fallo.
		echo "   SOBRA: $k\n";
	}
	$union += $falta;
}
// Mismo orden que es.php, para que las tres listas coincidan línea a línea.
$claves = array_values( array_filter( array_keys( $es ), static fn( $k ) => isset( $union[ $k ] ) ) );
$txt    = '';
foreach ( $claves as $i => $k ) {
	if ( false !== strpos( $k, "\n" ) ) {
		echo "   AVISO: el texto $i lleva saltos de línea; en la traducción, escríbelos como \\n\n";
	}
	$txt .= $i . '|' . str_replace( "\n", '\n', $k ) . "\n";
}
if ( ! is_dir( $sal ) ) {
	mkdir( $sal, 0777, true );
}
file_put_contents( "$sal/faltan.json", json_encode( $claves, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
file_put_contents( "$sal/faltan.txt", $txt );
echo count( $claves ) . " textos por traducir en $sal/faltan.txt\n";
