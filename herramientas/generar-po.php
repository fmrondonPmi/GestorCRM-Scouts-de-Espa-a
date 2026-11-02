<?php
/**
 * Genera traducciones/*.pot, los .po de cada idioma y, con --mo, los .mo (doc 62). No necesita WordPress.
 *
 *   php herramientas/generar-po.php [--mo]
 *
 * - Extrae los textos del plugin (funciones gettext con el dominio del plugin) con su referencia y su comentario
 *   «translators».
 * - Las traducciones salen de traducciones/es-en.json (castellano de cada original) y de los catálogos archivados
 *   (idiomas-archivo/ca.php, eu.php, gl.php: clave en castellano). El valenciano parte del catalán.
 * - Lo que ya está en traducciones/<idioma>.po (editado a mano o importado de translate.wordpress.org) se respeta:
 *   solo se rellenan los textos que no tienen traducción.
 */

const DOM = 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts';
const IDIOMAS = array(
	'es_ES'       => array( 'es', 'Spanish (Spain)' ),
	'gl_ES'       => array( 'gl', 'Galician' ),
	'eu'          => array( 'eu', 'Basque' ),
	'ca'          => array( 'ca', 'Catalan' ),
	'ca_valencia' => array( 'ca', 'Valencian' ),
);

$repo   = rtrim( str_replace( '\\', '/', dirname( __DIR__ ) ), '/' ) . '/';
$plugin = $repo . 'asde-area-privada/';
$salida = $repo . 'traducciones/';
$mapa   = json_decode( (string) file_get_contents( $salida . 'es-en.json' ), true );
$con_mo = in_array( '--mo', $argv, true );

function sig( array $t, int $d, int $p = 1 ): ?int {
	for ( $i = $d + $p; $i >= 0 && $i < count( $t ); $i += $p ) {
		if ( is_array( $t[ $i ] ) && in_array( $t[ $i ][0], array( T_WHITESPACE ), true ) ) { continue; }
		return $i;
	}
	return null;
}
function lv( string $token ): ?string {
	$q = $token[0] ?? ''; $c = substr( $token, 1, -1 );
	if ( "'" === $q ) { return str_replace( array( '\\\\', "\\'" ), array( '\\', "'" ), $c ); }
	if ( '"' === $q && ! preg_match( '/(?<!\\\\)[$]|\{\$/', $c ) ) { return stripcslashes( $c ); }
	return null;
}
function numerar( string $s ): string {
	preg_match_all( '/%(?:(\d+)\$)?[sdfu]/', str_replace( '%%', '', $s ), $m );
	$sin = array_filter( $m[1], static fn( $x ) => '' === $x );
	if ( count( $m[0] ) < 2 || count( $sin ) !== count( $m[0] ) ) { return $s; }
	$i = 0;
	return preg_replace_callback( '/%%|%([sdfu])/', static function ( $x ) use ( &$i ) { return '%%' === $x[0] ? '%%' : '%' . ( ++$i ) . '$' . $x[1]; }, $s );
}
function po_cita( string $s ): string {
	return '"' . str_replace( array( '\\', '"', "\n", "\t", "\r" ), array( '\\\\', '\\"', '\\n', '\\t', '\\r' ), $s ) . '"';
}
function po_campo( string $clave, string $valor ): string {
	if ( ! str_contains( $valor, "\n" ) || strlen( $valor ) < 3 ) { return $clave . ' ' . po_cita( $valor ) . "\n"; }
	$partes = preg_split( '/(?<=\n)/', $valor, -1, PREG_SPLIT_NO_EMPTY );
	$s = $clave . " \"\"\n";
	foreach ( $partes as $p ) { $s .= po_cita( $p ) . "\n"; }
	return $s;
}

// 1. Extracción.
$textos = array(); // clave => array(msgid, plural|null, refs[], comentario)
$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $plugin, FilesystemIterator::SKIP_DOTS ) );
$ficheros = array();
foreach ( $it as $f ) {
	$rel = substr( str_replace( '\\', '/', $f->getPathname() ), strlen( $plugin ) );
	if ( str_ends_with( $rel, '.php' ) && ! preg_match( '#^(tests|herramientas)/#', $rel ) ) { $ficheros[] = $rel; }
}
sort( $ficheros );
$fns = array( '__' => 1, '_e' => 1, 'esc_html__' => 1, 'esc_html_e' => 1, 'esc_attr__' => 1, 'esc_attr_e' => 1, '_n' => 2 );
foreach ( $ficheros as $rel ) {
	$t = token_get_all( (string) file_get_contents( $plugin . $rel ) );
	for ( $i = 0; $i < count( $t ); $i++ ) {
		if ( ! is_array( $t[ $i ] ) || T_STRING !== $t[ $i ][0] || ! isset( $fns[ $t[ $i ][1] ] ) ) { continue; }
		$nombre = $t[ $i ][1];
		$o = sig( $t, $i ); if ( null === $o || '(' !== $t[ $o ] ) { continue; }
		$a = sig( $t, $o ); if ( null === $a || ! is_array( $t[ $a ] ) || T_CONSTANT_ENCAPSED_STRING !== $t[ $a ][0] ) { continue; }
		$msgid = lv( $t[ $a ][1] ); if ( null === $msgid ) { continue; }
		$plural = null; $c = sig( $t, $a ); $dom = null;
		if ( '_n' === $nombre ) {
			$b = null !== $c ? sig( $t, $c ) : null;
			if ( null === $b || ! is_array( $t[ $b ] ) || T_CONSTANT_ENCAPSED_STRING !== $t[ $b ][0] ) { continue; }
			$plural = lv( $t[ $b ][1] );
		}
		// El dominio es el último literal de la llamada: se comprueba buscando el cierre.
		$d = 0; $fin = null;
		for ( $j = $o; $j < count( $t ); $j++ ) {
			if ( '(' === $t[ $j ] ) { $d++; } elseif ( ')' === $t[ $j ] ) { $d--; if ( 0 === $d ) { $fin = $j; break; } } elseif ( 0 === $d - 1 && is_array( $t[ $j ] ) && T_CONSTANT_ENCAPSED_STRING === $t[ $j ][0] && DOM === lv( $t[ $j ][1] ) ) { $dom = true; }
		}
		if ( ! $dom ) { continue; }
		// Comentario «translators» inmediatamente anterior (en los tokens antes del nombre).
		$coment = '';
		for ( $k = $i - 1; $k >= 0 && $k > $i - 6; $k-- ) {
			if ( is_array( $t[ $k ] ) && T_COMMENT === $t[ $k ][0] && preg_match( '#/\*\s*translators:\s*(.*?)\s*\*/#s', $t[ $k ][1], $m ) ) { $coment = 'translators: ' . trim( preg_replace( '/\s+/', ' ', $m[1] ) ); break; }
			if ( is_array( $t[ $k ] ) && in_array( $t[ $k ][0], array( T_WHITESPACE, T_OPEN_TAG, T_ECHO ), true ) ) { continue; }
			if ( is_array( $t[ $k ] ) && T_COMMENT === $t[ $k ][0] ) { continue; }
			if ( is_string( $t[ $k ] ) && '(' === $t[ $k ] ) { continue; }
			break;
		}
		$clave = $msgid . "\0" . ( $plural ?? '' );
		if ( ! isset( $textos[ $clave ] ) ) { $textos[ $clave ] = array( $msgid, $plural, array(), $coment ); }
		$textos[ $clave ][2][] = $rel . ':' . $t[ $i ][2];
		if ( '' === $textos[ $clave ][3] && '' !== $coment ) { $textos[ $clave ][3] = $coment; }
	}
}
ksort( $textos, SORT_STRING );

// 2. Del inglés al castellano de cada original.
$es_de = array(); // inglés (numerado) => [castellano...]
foreach ( $mapa as $es => $en ) { $es_de[ numerar( $en ) ][] = $es; }
// Con varios castellanos para un mismo inglés (género, número…), manda el más usado en el código original (uso-es.json).
$uso = is_file( $salida . 'uso-es.json' ) ? json_decode( (string) file_get_contents( $salida . 'uso-es.json' ), true ) : array();
foreach ( $es_de as $en => $lista ) {
	if ( count( $lista ) > 1 ) {
		usort( $lista, static fn( $x, $y ) => ( $uso[ $y ] ?? 0 ) <=> ( $uso[ $x ] ?? 0 ) );
		$es_de[ $en ] = $lista;
	}
}
$cat = array();
foreach ( array( 'ca', 'eu', 'gl' ) as $l ) {
	$f = $repo . 'idiomas-archivo/' . $l . '.php';
	if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', 1 ); }
	$cat[ $l ] = is_file( $f ) ? ( require $f ) : array();
}
$buscar = static function ( string $en, string $lang ) use ( $es_de, $cat ): ?string {
	foreach ( $es_de[ $en ] ?? array() as $es ) {
		$t = 'es' === $lang ? $es : ( $cat[ $lang ][ $es ] ?? '' );
		if ( '' !== $t ) { return numerar_como( $t, $en ); }
	}
	return null;
};
/** La traducción lleva los marcadores numerados si el original los lleva. */
function numerar_como( string $t, string $en ): string {
	return preg_match( '/%\d+\$[sdfu]/', $en ) ? numerar( $t ) : $t;
}

// 3. Lo que ya hay en los .po (se respeta).
function leer_po( string $ruta ): array {
	if ( ! is_file( $ruta ) ) { return array(); }
	$s = (string) file_get_contents( $ruta );
	$out = array();
	foreach ( preg_split( '/\n\n+/', $s ) as $bloque ) {
		if ( ! preg_match( '/^msgid\s+(".*?")\s*\n(?:msgid_plural\s+(".*?")\s*\n)?(msgstr(?:\[0\])?\s+.*)$/ms', $bloque, $m ) ) { continue; }
		$id = json_decode( preg_replace( '/"\s*\n\s*"/', '', $m[1] ) ) ?? '';
		if ( '' === $id ) { continue; }
		if ( preg_match( '/msgstr(?:\[0\])?\s+((?:".*?"\s*\n?)+)/s', $m[3], $x ) ) {
			$val = json_decode( preg_replace( '/"\s*\n\s*"/', '', trim( $x[1] ) ) ) ?? '';
			if ( '' !== $val ) { $out[ $id ] = $val; }
		}
		// La forma plural también se respeta (clave «[1]texto»): así no se pierden los plurales corregidos a mano.
		if ( preg_match( '/msgstr\[1\]\s+((?:".*?"\s*
?)+)/s', $m[3], $x1 ) ) {
			$val1 = json_decode( preg_replace( '/"\s*
\s*"/', '', trim( $x1[1] ) ) ) ?? '';
			if ( '' !== $val1 ) { $out[ '[1]' . $id ] = $val1; }
		}
	}
	return $out;
}

// 4. Escritura.
if ( ! is_dir( $salida ) ) { mkdir( $salida, 0777, true ); }
$cabecera = static function ( string $idioma, string $nombre, string $lang ): string {
	$h  = "msgid \"\"\nmsgstr \"\"\n";
	$h .= "\"Project-Id-Version: ASDE Scouts de España - Gestor CRM para Grupos Scouts\\n\"\n";
	$h .= "\"Report-Msgid-Bugs-To: https://wordpress.org/support/plugin/" . DOM . "\\n\"\n";
	$h .= "\"MIME-Version: 1.0\\n\"\n\"Content-Type: text/plain; charset=UTF-8\\n\"\n\"Content-Transfer-Encoding: 8bit\\n\"\n";
	if ( '' !== $idioma ) {
		$h .= "\"Language: " . str_replace( '_valencia', '@valencia', $idioma ) . "\\n\"\n";
		$h .= "\"Plural-Forms: nplurals=2; plural=(n != 1);\\n\"\n";
	}
	$h .= "\"X-Domain: " . DOM . "\\n\"\n\n";
	return $h;
};
// .pot
$pot = $cabecera( '', '', '' );
foreach ( $textos as [ $msgid, $plural, $refs, $coment ] ) {
	if ( '' !== $coment ) { $pot .= '#. ' . $coment . "\n"; }
	$pot .= '#: ' . implode( ' ', array_slice( array_unique( $refs ), 0, 4 ) ) . "\n";
	if ( preg_match( '/%\d*\$?[sdfu]/', $msgid . ( $plural ?? '' ) ) ) { $pot .= "#, php-format\n"; }
	$pot .= po_campo( 'msgid', $msgid );
	if ( null !== $plural ) { $pot .= po_campo( 'msgid_plural', $plural ) . "msgstr[0] \"\"\nmsgstr[1] \"\"\n\n"; } else { $pot .= "msgstr \"\"\n\n"; }
}
file_put_contents( $salida . DOM . '.pot', $pot );
echo count( $textos ) . " textos en el .pot\n";

$resumen = array();
foreach ( IDIOMAS as $idioma => [ $lang, $nombre ] ) {
	$base = 'ca_valencia' === $idioma ? 'ca' : $lang;
	$previo = leer_po( $salida . DOM . '-' . $idioma . '.po' );
	$po = $cabecera( $idioma, $nombre, $lang );
	$traducidos = 0; $conflictos = 0;
	foreach ( $textos as [ $msgid, $plural, $refs, $coment ] ) {
		$t0 = $previo[ $msgid ] ?? $buscar( $msgid, $base );
		$t1 = null;
		if ( null !== $plural ) {
			$t1 = $buscar( $plural, $base );
			// Singular y plural iguales en inglés pero distintos en castellano («Queda 1» / «Quedan 2»): se toman por orden.
			if ( $plural === $msgid && count( $es_de[ $msgid ] ?? array() ) >= 2 && ! isset( $previo[ $msgid ] ) ) {
				$par = $es_de[ $msgid ];
				sort( $par, SORT_STRING );
				$f0 = 'es' === $base ? $par[0] : ( $cat[ $base ][ $par[0] ] ?? '' );
				$f1 = 'es' === $base ? $par[1] : ( $cat[ $base ][ $par[1] ] ?? '' );
				if ( '' !== $f0 && '' !== $f1 ) { $t0 = numerar_como( $f0, $msgid ); $t1 = numerar_como( $f1, $msgid ); }
			}
		}
		if ( null !== $plural && isset( $previo[ '[1]' . $msgid ] ) ) { $t1 = $previo[ '[1]' . $msgid ]; } // El plural ya escrito en el .po se respeta.
		if ( count( $es_de[ $msgid ] ?? array() ) > 1 ) { $conflictos++; }
		if ( '' !== $coment ) { $po .= '#. ' . $coment . "\n"; }
		$po .= '#: ' . implode( ' ', array_slice( array_unique( $refs ), 0, 4 ) ) . "\n";
		if ( preg_match( '/%\d*\$?[sdfu]/', $msgid . ( $plural ?? '' ) ) ) { $po .= "#, php-format\n"; }
		$po .= po_campo( 'msgid', $msgid );
		if ( null !== $plural ) {
			$po .= po_campo( 'msgid_plural', $plural );
			$po .= po_campo( 'msgstr[0]', $t0 ?? '' ) . po_campo( 'msgstr[1]', $t1 ?? ( $t0 ?? '' ) ) . "\n";
		} else {
			$po .= po_campo( 'msgstr', $t0 ?? '' ) . "\n";
		}
		if ( null !== $t0 ) { $traducidos++; }
	}
	file_put_contents( $salida . DOM . '-' . $idioma . '.po', $po );
	$resumen[] = sprintf( '%-12s %d/%d traducidos (%d con varios castellanos)', $idioma, $traducidos, count( $textos ), $conflictos );
}
echo implode( "\n", $resumen ), "\n";

// 5. .mo (para pruebas y para quien quiera probar sin translate.wordpress.org).
if ( $con_mo ) {
	foreach ( array_keys( IDIOMAS ) as $idioma ) {
		$po = (string) file_get_contents( $salida . DOM . '-' . $idioma . '.po' );
		$entradas = array();
		foreach ( preg_split( '/\n\n+/', $po ) as $bloque ) {
			if ( ! preg_match( '/^msgid\s+((?:".*?"\s*\n)+)(?:msgid_plural\s+((?:".*?"\s*\n)+))?((?:msgstr(?:\[\d\])?\s+(?:".*?"\s*\n?)+)+)/ms', $bloque . "\n", $m ) ) { continue; }
			$dec = static fn( string $x ): string => (string) json_decode( '"' . implode( '', array_map( static fn( $l ) => substr( trim( $l ), 1, -1 ), preg_split( '/\n/', trim( $x ) ) ) ) . '"' );
			$id = $dec( $m[1] );
			$strs = array();
			preg_match_all( '/msgstr(?:\[\d\])?\s+((?:".*?"\s*\n?)+)/s', $m[3], $mm );
			foreach ( $mm[1] as $x ) { $strs[] = $dec( $x ); }
			if ( '' !== $m[2] ) { $id .= "\0" . $dec( $m[2] ); }
			if ( '' === implode( '', $strs ) && '' !== $id ) { continue; }
			$entradas[ $id ] = implode( "\0", $strs );
		}
		ksort( $entradas, SORT_STRING );
		$n = count( $entradas );
		$ids = ''; $strs = ''; $oi = array(); $os = array();
		foreach ( $entradas as $id => $str ) { $oi[] = array( strlen( $id ), strlen( $ids ) ); $ids .= $id . "\0"; $os[] = array( strlen( $str ), strlen( $strs ) ); $strs .= $str . "\0"; }
		$ini_tabla_o = 28; $ini_tabla_t = $ini_tabla_o + 8 * $n; $ini_ids = $ini_tabla_t + 8 * $n; $ini_strs = $ini_ids + strlen( $ids );
		$mo = pack( 'Iiiiiii', 0x950412de, 0, $n, $ini_tabla_o, $ini_tabla_t, 0, 0 );
		foreach ( $oi as $x ) { $mo .= pack( 'II', $x[0], $ini_ids + $x[1] ); }
		foreach ( $os as $x ) { $mo .= pack( 'II', $x[0], $ini_strs + $x[1] ); }
		$mo .= $ids . $strs;
		if ( ! is_dir( $salida . 'mo' ) ) { mkdir( $salida . 'mo', 0777, true ); }
		file_put_contents( $salida . 'mo/' . DOM . '-' . $idioma . '.mo', $mo );
		echo "mo/$idioma: $n entradas\n";
	}
}
