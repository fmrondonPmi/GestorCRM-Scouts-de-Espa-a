<?php
/**
 * Pruebas de las traducciones del repo (doc 62): el .pot recoge todos los textos del código, y los .po de es_ES,
 * gl_ES, eu, ca y ca_valencia conservan los marcadores y las etiquetas de cada original.
 *
 *   wp eval-file <ruta completa>/wp-content/plugins/asde-area-privada/tests/idiomas-catalogos.php
 *
 * Lee traducciones/ del repo (C:/Proyectos/GestorCRM/traducciones; otra ruta con ASDE_REPO_TRADUCCIONES). Si no está,
 * no comprueba nada. Solo lee: no cambia nada del sitio.
 */
defined( 'ABSPATH' ) || exit;

$fails = 0;
$ok    = function ( $cond, string $label ) use ( &$fails ) {
	if ( ! $cond ) {
		++$fails;
	}
	echo ( $cond ? 'OK   ' : 'FAIL ' ), $label, "\n";
};

$dom = 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts';
$dir = rtrim( (string) ( getenv( 'ASDE_REPO_TRADUCCIONES' ) ?: 'C:/Proyectos/GestorCRM/traducciones' ), '/\\' ) . '/';

/** @return array<string, array{0:string, 1:string[]}> msgid => [msgid_plural, msgstr[]] */
$leer = static function ( string $ruta ): array {
	$out = array();
	foreach ( preg_split( '/\n\n+/', str_replace( "\r\n", "\n", (string) file_get_contents( $ruta ) ) ) as $bloque ) {
		if ( ! preg_match( '/^msgid\s+((?:".*"\n)+)(?:msgid_plural\s+((?:".*"\n)+))?((?:msgstr(?:\[\d\])?\s+(?:".*"\n?)+)+)/m', $bloque . "\n", $m ) ) {
			continue;
		}
		$dec = static fn( string $x ): string => (string) json_decode( '"' . implode( '', array_map( static fn( $l ) => substr( trim( $l ), 1, -1 ), explode( "\n", trim( $x ) ) ) ) . '"' );
		$id  = $dec( $m[1] );
		if ( '' === $id ) {
			continue;
		}
		preg_match_all( '/msgstr(?:\[\d\])?\s+((?:".*"\n?)+)/', $m[3], $mm );
		$out[ $id ] = array( '' !== $m[2] ? $dec( $m[2] ) : '', array_map( $dec, $mm[1] ) );
	}
	return $out;
};
$marcadores = static function ( string $s ): array {
	preg_match_all( '/%(?:(\d+)\$)?([sdfu])/', str_replace( '%%', '', $s ), $m, PREG_SET_ORDER );
	$r = array();
	foreach ( $m as $i => $x ) {
		$r[] = ( '' !== $x[1] ? $x[1] : (string) ( $i + 1 ) ) . $x[2];
	}
	sort( $r );
	return $r;
};
$etiquetas = static function ( string $s ): array {
	preg_match_all( '/<[^>]+>/', $s, $m );
	sort( $m[0] );
	return $m[0];
};

if ( ! is_file( $dir . $dom . '.pot' ) ) {
	echo "OK   (sin traducciones/ en esta máquina: nada que comprobar)\n";
	echo "\nTODO OK (el sitio queda como estaba)\n";
	return;
}

try {
	echo "== Parte A · El .pot recoge los textos del código ==\n";
	$pot = $leer( $dir . $dom . '.pot' );
	$ok( count( $pot ) > 4000, 'el .pot tiene más de 4.000 textos (' . count( $pot ) . ')' );
	$en_codigo = array();
	$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( ASDE_AP_DIR, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $it as $f ) {
		$p = str_replace( '\\', '/', $f->getPathname() );
		if ( ! str_ends_with( $p, '.php' ) || str_contains( $p, '/tests/' ) || str_contains( $p, '/herramientas/' ) ) {
			continue;
		}
		$tk = token_get_all( (string) file_get_contents( $p ) );
		for ( $i = 0, $n = count( $tk ); $i < $n; $i++ ) {
			if ( ! is_array( $tk[ $i ] ) || T_STRING !== $tk[ $i ][0] || ! in_array( $tk[ $i ][1], array( '__', '_e', 'esc_html__', 'esc_html_e', 'esc_attr__', 'esc_attr_e', '_n' ), true ) ) {
				continue;
			}
			$j = $i + 1;
			while ( isset( $tk[ $j ] ) && is_array( $tk[ $j ] ) && T_WHITESPACE === $tk[ $j ][0] ) {
				++$j;
			}
			++$j;
			while ( isset( $tk[ $j ] ) && is_array( $tk[ $j ] ) && T_WHITESPACE === $tk[ $j ][0] ) {
				++$j;
			}
			if ( isset( $tk[ $j ] ) && is_array( $tk[ $j ] ) && T_CONSTANT_ENCAPSED_STRING === $tk[ $j ][0] && "'" === $tk[ $j ][1][0] ) {
				$en_codigo[ str_replace( array( '\\\\', "\\'" ), array( '\\', "'" ), substr( $tk[ $j ][1], 1, -1 ) ) ] = $p;
			}
		}
	}
	$faltan = array_diff_key( $en_codigo, $pot );
	$ok( ! $faltan, 'todo texto del código está en el .pot (se regenera con herramientas/generar-po.php)' . ( $faltan ? ': ' . implode( ' | ', array_slice( array_keys( $faltan ), 0, 3 ) ) : '' ) );

	echo "\n== Parte B · Los .po conservan marcadores y etiquetas ==\n";
	foreach ( array( 'es_ES' => 0.99, 'gl_ES' => 0.95, 'eu' => 0.95, 'ca' => 0.95, 'ca_valencia' => 0.95 ) as $idioma => $minimo ) {
		$ruta = $dir . $dom . '-' . $idioma . '.po';
		if ( ! is_file( $ruta ) ) {
			$ok( false, "existe $idioma.po" );
			continue;
		}
		$po = $leer( $ruta );
		$traducidos = 0;
		$mal        = array();
		foreach ( $po as $id => $d ) {
			[ $plural, $strs ] = $d;
			if ( '' === implode( '', $strs ) ) {
				continue;
			}
			++$traducidos;
			foreach ( $strs as $k => $t ) {
				if ( '' === $t ) {
					continue;
				}
				$origen = ( 1 === $k && '' !== $plural ) ? $plural : $id;
				if ( $marcadores( $origen ) !== $marcadores( $t ) || $etiquetas( $origen ) !== $etiquetas( $t ) ) {
					$mal[] = $id;
				}
			}
		}
		$ok( count( $po ) === count( $pot ), "$idioma.po tiene los mismos textos que el .pot" );
		$ok( $traducidos / max( 1, count( $po ) ) >= $minimo, sprintf( '%s.po: %d%% traducido (mínimo %d%%)', $idioma, 100 * $traducidos / max( 1, count( $po ) ), 100 * $minimo ) );
		$ok( ! $mal, "$idioma.po: mismos marcadores y mismas etiquetas HTML que el original" . ( $mal ? ' — mal: ' . implode( ' | ', array_slice( $mal, 0, 3 ) ) : '' ) );
	}

	echo "\n== Parte C · Los .po del repo no se cuelan en el paquete ==\n";
	$ok( ! is_dir( ASDE_AP_DIR . 'languages' ) || array() === glob( ASDE_AP_DIR . 'languages/*.{po,mo,pot}', GLOB_BRACE ), 'languages/ del plugin sin ficheros de traducción' );
} catch ( Throwable $e ) {
	++$fails;
	echo 'FAIL excepcion: ', $e->getMessage(), ' (', basename( $e->getFile() ), ':', $e->getLine(), ")\n";
}

echo $fails ? "\n$fails FALLOS\n" : "\nTODO OK (el sitio queda como estaba)\n";
