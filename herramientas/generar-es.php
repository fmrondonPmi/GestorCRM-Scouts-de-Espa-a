<?php
/**
 * Regenera languages/es.php: la lista de todos los textos traducibles del plugin (texto en castellano =>
 * grupo de pantallas). Se ejecuta con WP-CLI en un WordPress que tenga el plugin activo:
 *
 *   wp eval-file <ruta>/herramientas/generar-es.php <ruta-al-plugin>
 *
 * Después hay que completar languages/ca.php, eu.php y gl.php con los textos nuevos
 * (tests/idiomas-catalogos.php avisa de los que faltan).
 */

use AsdeBalears\AreaPrivada\Idiomas\Textos;

$raiz = isset( $args[0] ) ? $args[0] : ASDE_AP_DIR;
$todo = Textos::todos( $raiz );
$por  = array();
foreach ( $todo as $texto => $grupo ) {
	$por[ $grupo ][ $texto ] = $grupo;
}
ksort( $por, SORT_STRING );

$php  = "<?php\n";
$php .= "// Generado por herramientas/generar-es.php: no se edita a mano. Texto en castellano => grupo de pantallas.\n";
$php .= "defined( 'ABSPATH' ) || exit;\n\nreturn array(\n";
foreach ( $por as $grupo => $textos ) {
	$php .= "\n\t// " . str_replace( array( "\r", "\n" ), ' ', (string) $grupo ) . "\n";
	foreach ( array_keys( $textos ) as $texto ) {
		$php .= "\t" . var_export( (string) $texto, true ) . ' => ' . var_export( (string) $grupo, true ) . ",\n";
	}
}
$php .= ");\n";
file_put_contents( str_replace( '\\', '/', rtrim( $raiz, '/' . chr( 92 ) ) ) . '/languages/es.php', $php );
echo count( $todo ) . ' textos en ' . count( $por ) . " grupos\n";
