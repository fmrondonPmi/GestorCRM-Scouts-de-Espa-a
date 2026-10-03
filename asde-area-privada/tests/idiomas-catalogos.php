<?php
/**
 * Pruebas de la v0.15 (entorno LOCAL): los catálogos de idiomas que trae el plugin.
 * Ejecutar desde app/public del site Local:
 *
 *   wp eval-file <ruta completa>/wp-content/plugins/asde-area-privada/tests/idiomas-catalogos.php
 *
 * Comprueba que languages/es.php está al día con el código, que catalán, euskera y gallego
 * traducen todos los textos con los mismos marcadores y etiquetas, y que los JavaScript piden sus
 * textos con asdeT(). No toca nada del sitio.
 */
defined( 'ABSPATH' ) || exit;

use AsdeBalears\AreaPrivada\Frontend\Menu;
use AsdeBalears\AreaPrivada\Frontend\Views\AccesoView;
use AsdeBalears\AreaPrivada\Idiomas\Idioma;
use AsdeBalears\AreaPrivada\Idiomas\Textos;
use AsdeBalears\AreaPrivada\Idiomas\TextosJs;
use AsdeBalears\AreaPrivada\Idiomas\Traducciones;

$fails = 0;
$ok    = function ( $cond, string $label ) use ( &$fails ) {
	if ( ! $cond ) {
		++$fails;
	}
	echo ( $cond ? 'OK   ' : 'FAIL ' ), $label, "\n";
};
$lista = static fn( array $t, int $n = 5 ): string => implode( ' | ', array_slice( array_map( static fn( $x ) => '«' . $x . '»', $t ), 0, $n ) );

$es = require ASDE_AP_DIR . 'languages/es.php';

echo "== Parte A · La lista de textos (languages/es.php) ==\n";
$codigo = Textos::todos( ASDE_AP_DIR );
$sobran = array_diff_key( $es, $codigo );
$faltan = array_diff_key( $codigo, $es );
$ok( ! $faltan, 'están todos los textos del código' . ( $faltan ? ': faltan ' . count( $faltan ) . ' (' . $lista( array_keys( $faltan ) ) . '); regenérala con herramientas/generar-es.php' : '' ) );
$ok( ! $sobran, 'y ninguno que el código ya no use' . ( $sobran ? ': sobran ' . $lista( array_keys( $sobran ) ) : '' ) );
$ok( count( $es ) > 1500, 'son más de 1.500 textos (' . count( $es ) . ')' );

echo "\n== Parte B · Catalán, euskera y gallego ==\n";
$permitidas = Traducciones::etiquetas_permitidas();
$etiquetas  = static function ( string $t ): array {
	preg_match_all( '/<\/?([a-z]+)/i', $t, $m );
	$o = array_map( 'strtolower', $m[1] );
	sort( $o );
	return $o;
};
foreach ( array( 'ca', 'eu', 'gl' ) as $cod ) {
	$fichero = ASDE_AP_DIR . "languages/$cod.php";
	$cat     = is_readable( $fichero ) ? require $fichero : null;
	$ok( is_array( $cat ), "$cod: el catálogo existe" );
	if ( ! is_array( $cat ) ) {
		continue;
	}
	$sin  = array_keys( array_filter( $es, static fn( $g, $t ) => '' === trim( (string) ( $cat[ $t ] ?? '' ) ), ARRAY_FILTER_USE_BOTH ) );
	$fuera = array_keys( array_diff_key( $cat, $es ) );
	$marca = array();
	$html  = array();
	foreach ( $cat as $orig => $trad ) {
		if ( ! Traducciones::mismos_marcadores( (string) $orig, (string) $trad ) ) {
			$marca[] = $orig;
		}
		if ( $etiquetas( (string) $orig ) !== $etiquetas( (string) $trad ) || wp_kses( (string) $trad, $permitidas ) !== (string) $trad ) {
			$html[] = $orig;
		}
	}
	$ok( ! $sin, "$cod: traduce todos los textos" . ( $sin ? ' (faltan ' . count( $sin ) . ': ' . $lista( $sin ) . ')' : ' (' . count( $cat ) . ')' ) );
	$ok( ! $fuera, "$cod: sin textos que ya no existen" . ( $fuera ? ': ' . $lista( $fuera ) : '' ) );
	$ok( ! $marca, "$cod: cada traducción conserva los marcadores (%s, %d, %1\$s…)" . ( $marca ? ': ' . $lista( $marca ) : '' ) );
	$ok( ! $html, "$cod: y las mismas etiquetas HTML, todas permitidas" . ( $html ? ': ' . $lista( $html ) : '' ) );
}

echo "\n== Parte C · JavaScript ==\n";
$en_js = array();
$sueltos = array();
foreach ( glob( ASDE_AP_DIR . 'assets/js/*.js' ) as $f ) {
	$js = (string) file_get_contents( $f );
	preg_match_all( "/asdeT\(\s*'((?:[^'\\\\]|\\\\.)*)'/", $js, $m );
	foreach ( $m[1] as $t ) {
		$en_js[ stripslashes( $t ) ] = basename( $f );
	}
	// Literales con letras de castellano que no pasan por asdeT() (sin contar comentarios).
	$sin_coment = preg_replace( array( '#/\*.*?\*/#s', '#^\s*//.*$#m' ), '', $js );
	$sin_asdet  = preg_replace( "/asdeT\(\s*'(?:[^'\\\\]|\\\\.)*'/", '', $sin_coment );
	preg_match_all( "/'([^'\\n]*[áéíóúñ¿¡][^'\\n]*)'/u", $sin_asdet, $s );
	foreach ( $s[1] as $t ) {
		$sueltos[] = basename( $f ) . ': ' . $t;
	}
}
$no_lista = array_diff( array_keys( $en_js ), TextosJs::LISTA );
$sin_uso  = array_diff( TextosJs::LISTA, array_keys( $en_js ) );
$ok( count( $en_js ) > 25 && ! $no_lista, 'todo lo que piden los JS con asdeT() está en TextosJs::LISTA' . ( $no_lista ? ': ' . $lista( $no_lista ) : '' ) );
$ok( ! $sin_uso, 'y la lista no tiene textos que ningún JS use' . ( $sin_uso ? ': ' . $lista( $sin_uso ) : '' ) );
$ok( ! $sueltos, 'no quedan textos en castellano escritos a mano en los JS' . ( $sueltos ? ': ' . $lista( $sueltos ) : '' ) );

echo "\n== Parte D · En uso ==\n";
try {
	Traducciones::olvidar();
	Idioma::forzar( 'ca' );
	$ok( 'Les meves dades' === asde_t( 'Mis datos' ) && 'Inici' === Menu::plano( null )['area'], 'en catalán: «Mis datos» es «Les meves dades» y el menú empieza por «Inici»' );
	$ok( '1 persona' === sprintf( asde_n( '%d persona', '%d personas', 1 ), 1 ) && '3 persones' === sprintf( asde_n( '%d persona', '%d personas', 3 ), 3 ), 'los plurales: «1 persona», «3 persones»' );
	Idioma::forzar( 'eu' );
	$js = TextosJs::script();
	$ok( str_contains( $js, '"Cerrar":"Itxi"' ) && str_contains( $js, 'window.asdeT=' ), 'los JS reciben sus textos en euskera («Cerrar» → «Itxi»)' );
	Idioma::forzar( 'gl' );
	wp_set_current_user( 0 );
	$h = ( new AccesoView() )->render();
	$ok( str_contains( $h, 'Contrasinal' ) && str_contains( $h, 'Entrar' ) && ! str_contains( $h, '>Contraseña<' ), 'la pantalla de acceso sale en gallego («Contrasinal»)' );
	Idioma::forzar( 'es' );
	$ok( 'Mis datos' === asde_t( 'Mis datos' ), 'y en castellano, el texto de siempre' );
} finally {
	Idioma::forzar( null );
	Traducciones::olvidar();
}

echo $fails ? "\n$fails FALLOS\n" : "\nTODO OK (no se ha tocado nada del sitio)\n";
