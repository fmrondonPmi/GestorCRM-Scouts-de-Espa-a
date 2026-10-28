<?php
/**
 * Convierte las constantes de etiquetas del plugin (arrays con textos en castellano) en métodos estáticos con
 * llamadas __() literales, y reescribe sus usos (doc 62). Herramienta de un solo uso; no necesita WordPress.
 *
 *   php herramientas/constantes-a-metodos.php <plugin> <es-en.json> <lista.txt> [--seco]
 *
 * <lista.txt>: una constante por línea, «Nombre\Completo\Clase::CONSTANTE». Las que no son un array se saltan
 * (y se listan): son valores que se guardan o textos sueltos que hay que mirar a mano.
 */

const DOM = 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts';
const NS  = 'AsdeBalears\\AreaPrivada\\';

$raiz  = rtrim( str_replace( '\\', '/', $argv[1] ?? '' ), '/' ) . '/';
$mapa  = json_decode( (string) file_get_contents( $argv[2] ?? '' ), true );
$lista = array_filter( array_map( 'trim', file( $argv[3] ?? '', FILE_IGNORE_NEW_LINES ) ?: array() ) );
$seco  = in_array( '--seco', $argv, true );
if ( ! is_dir( $raiz ) || ! is_array( $mapa ) || ! $lista ) {
	fwrite( STDERR, "Uso: php constantes-a-metodos.php <plugin> <es-en.json> <lista.txt> [--seco]\n" );
	exit( 1 );
}

function sig( array $t, int $desde, int $paso = 1 ): ?int {
	for ( $i = $desde + $paso; $i >= 0 && $i < count( $t ); $i += $paso ) {
		if ( is_array( $t[ $i ] ) && in_array( $t[ $i ][0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
			continue;
		}
		return $i;
	}
	return null;
}

function lit_valor( string $token ): ?string {
	$q = $token[0] ?? '';
	$c = substr( $token, 1, -1 );
	if ( "'" === $q ) {
		return str_replace( array( '\\\\', "\\'" ), array( '\\', "'" ), $c );
	}
	if ( '"' === $q && ! preg_match( '/(?<!\\\\)[$]|\{\$/', $c ) ) {
		return stripcslashes( $c );
	}
	return null;
}

function lit( string $s ): string {
	return "'" . str_replace( array( '\\', "'" ), array( '\\\\', "\\'" ), $s ) . "'";
}

function numerar( string $en ): string {
	preg_match_all( '/%(?:(\d+)\$)?[sdfu]/', str_replace( '%%', '', $en ), $m );
	$sin = array_filter( $m[1], static fn( $x ) => '' === $x );
	if ( count( $m[0] ) < 2 || count( $sin ) !== count( $m[0] ) ) {
		return $en;
	}
	$i = 0;
	return preg_replace_callback( '/%%|%([sdfu])/', static function ( $x ) use ( &$i ) {
		return '%%' === $x[0] ? '%%' : '%' . ( ++$i ) . '$' . $x[1];
	}, $en );
}

function comentario( string $en ): string {
	preg_match_all( '/%(?:(\d+)\$)?([sdfu])/', str_replace( '%%', '', $en ), $m, PREG_SET_ORDER );
	if ( ! $m ) {
		return '';
	}
	$partes = array();
	foreach ( $m as $i => $x ) {
		$tipo     = 's' === $x[2] ? 'text' : 'number';
		$partes[] = count( $m ) > 1 ? ( $x[1] ?: ( $i + 1 ) ) . ': ' . $tipo : $x[0] . ': ' . $tipo;
	}
	return '/* translators: ' . implode( ', ', $partes ) . ' */ ';
}

function leer_tokens( string $ruta ): array {
	return token_get_all( (string) file_get_contents( $ruta ) );
}

function a_texto( array $t ): string {
	$s = '';
	foreach ( $t as $x ) {
		$s .= is_array( $x ) ? $x[1] : $x;
	}
	return $s;
}

function fichero_de( string $raiz, string $fqcn ): string {
	return $raiz . 'src/' . str_replace( '\\', '/', substr( $fqcn, strlen( NS ) ) ) . '.php';
}

/** Nombre completo al que se refiere un nombre de clase dentro de un fichero (namespace + use). */
function resolver( string $nombre, string $ns, array $usos ): string {
	if ( '\\' === $nombre[0] ) {
		return ltrim( $nombre, '\\' );
	}
	$primero = strstr( $nombre . '\\', '\\', true );
	if ( isset( $usos[ $primero ] ) ) {
		return $usos[ $primero ] . substr( $nombre, strlen( $primero ) );
	}
	return ( '' !== $ns ? $ns . '\\' : '' ) . $nombre;
}

function info_fichero( array $t ): array {
	$ns = '';
	$usos = array();
	$n = count( $t );
	for ( $i = 0; $i < $n; $i++ ) {
		if ( is_array( $t[ $i ] ) && T_NAMESPACE === $t[ $i ][0] ) {
			$j = sig( $t, $i );
			$ns = is_array( $t[ $j ] ) ? $t[ $j ][1] : '';
		}
		if ( is_array( $t[ $i ] ) && T_USE === $t[ $i ][0] ) {
			$j = sig( $t, $i );
			if ( null !== $j && is_array( $t[ $j ] ) && in_array( $t[ $j ][0], array( T_NAME_QUALIFIED, T_STRING, T_NAME_FULLY_QUALIFIED ), true ) ) {
				$completo = ltrim( $t[ $j ][1], '\\' );
				$k = sig( $t, $j );
				if ( null !== $k && is_array( $t[ $k ] ) && T_AS === $t[ $k ][0] ) {
					$l = sig( $t, $k );
					$usos[ $t[ $l ][1] ] = $completo;
				} else {
					$usos[ substr( strrchr( '\\' . $completo, '\\' ), 1 ) ] = $completo;
				}
			}
		}
		if ( is_array( $t[ $i ] ) && in_array( $t[ $i ][0], array( T_CLASS, T_INTERFACE, T_TRAIT ), true ) ) {
			break;
		}
	}
	return array( $ns, $usos );
}

// 1. Convertir cada constante en método.
$metodos = array(); // FQCN::CONSTANTE => nombre del método
$saltadas = array();
$informe = array();
foreach ( $lista as $c ) {
	[ $cls, $k ] = explode( '::', ltrim( $c, '\\' ) );
	$ruta = fichero_de( $raiz, $cls );
	if ( ! is_file( $ruta ) ) {
		$saltadas[] = "$c (sin fichero)";
		continue;
	}
	$t = leer_tokens( $ruta );
	$n = count( $t );
	$ini = null;
	$depth = 0;
	for ( $i = 0; $i < $n; $i++ ) {
		if ( '{' === $t[ $i ] || ( is_array( $t[ $i ] ) && T_CURLY_OPEN === $t[ $i ][0] ) || ( is_array( $t[ $i ] ) && T_DOLLAR_OPEN_CURLY_BRACES === $t[ $i ][0] ) ) {
			$depth++;
		} elseif ( '}' === $t[ $i ] ) {
			$depth--;
		}
		if ( 1 === $depth && is_array( $t[ $i ] ) && T_CONST === $t[ $i ][0] ) {
			$j = sig( $t, $i );
			if ( null !== $j && is_array( $t[ $j ] ) && $k === $t[ $j ][1] ) {
				$ini = $i;
				break;
			}
		}
	}
	if ( null === $ini ) {
		$saltadas[] = "$c (no encontrada)";
		continue;
	}
	// Visibilidad: tokens anteriores (public/private/protected/final).
	$vis = 'public';
	$desde = $ini;
	for ( $b = $ini - 1; $b >= 0; $b-- ) {
		if ( is_array( $t[ $b ] ) && T_WHITESPACE === $t[ $b ][0] ) {
			continue;
		}
		if ( is_array( $t[ $b ] ) && in_array( $t[ $b ][0], array( T_PUBLIC, T_PRIVATE, T_PROTECTED, T_FINAL ), true ) ) {
			if ( T_PUBLIC !== $t[ $b ][0] && T_FINAL !== $t[ $b ][0] ) {
				$vis = strtolower( $t[ $b ][1] );
			}
			$desde = $b;
			continue;
		}
		break;
	}
	$j = sig( $t, $ini ); // nombre
	$e = sig( $t, $j );    // =
	$v = sig( $t, $e );    // primer token del valor
	$es_array = null !== $v && ( ( is_array( $t[ $v ] ) && T_ARRAY === $t[ $v ][0] ) || '[' === $t[ $v ] );
	if ( ! $es_array ) {
		$saltadas[] = "$c (no es un array)";
		continue;
	}
	$fin = null;
	$p = 0;
	for ( $i = $v; $i < $n; $i++ ) {
		if ( '(' === $t[ $i ] || '[' === $t[ $i ] ) {
			$p++;
		} elseif ( ')' === $t[ $i ] || ']' === $t[ $i ] ) {
			$p--;
		} elseif ( ';' === $t[ $i ] && 0 === $p ) {
			$fin = $i;
			break;
		}
	}
	// Literales en posición de valor → __().
	$conv = 0;
	$sin = array();
	for ( $i = $v; $i < $fin; $i++ ) {
		if ( ! is_array( $t[ $i ] ) || T_CONSTANT_ENCAPSED_STRING !== $t[ $i ][0] ) {
			continue;
		}
		$s = sig( $t, $i );
		if ( null !== $s && is_array( $t[ $s ] ) && T_DOUBLE_ARROW === $t[ $s ][0] ) {
			continue; // clave
		}
		$es = lit_valor( $t[ $i ][1] );
		if ( null === $es || '' === trim( $es ) ) {
			continue;
		}
		if ( isset( $mapa[ $es ] ) && '' !== $mapa[ $es ] ) {
			$en = numerar( $mapa[ $es ] );
			$t[ $i ][1] = comentario( $en ) . '__( ' . lit( $en ) . ', ' . lit( DOM ) . ' )';
			$conv++;
		} else {
			$sin[] = $es;
		}
	}
	// Nombre del método sin choques.
	$nombre = strtolower( $k );
	$todo = a_texto( $t );
	if ( preg_match( '/function\s+' . preg_quote( $nombre, '/' ) . '\s*\(/', $todo ) ) {
		$nombre .= '_etiquetas';
	}
	$expr = trim( a_texto( array_slice( $t, $v, $fin - $v ) ) );
	$cuerpo = "/** Etiquetas traducidas al idioma de WordPress (doc 62). @return array<array-key, mixed> */\n\t{$vis} static function {$nombre}(): array {\n\t\treturn {$expr};\n\t}";
	// Sustituir desde $desde hasta $fin (el «;»).
	$nuevo = array_merge( array_slice( $t, 0, $desde ), array( $cuerpo ), array_slice( $t, $fin + 1 ) );
	$metodos[ ltrim( $c, '\\' ) ] = $nombre;
	$informe[] = sprintf( '%-60s método %s() · %d textos convertidos%s', str_replace( NS, '', $c ), $nombre, $conv, $sin ? ' · SIN MAPA: ' . implode( ' | ', array_slice( $sin, 0, 6 ) ) : '' );
	if ( ! $seco ) {
		file_put_contents( $ruta, a_texto( $nuevo ) );
	}
}

// 2. Reescribir los usos X::CONSTANTE → X::metodo() en todo el plugin (src, templates, tests).
$usos_cambiados = 0;
$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $raiz, FilesystemIterator::SKIP_DOTS ) );
foreach ( $it as $f ) {
	$ruta = str_replace( '\\', '/', $f->getPathname() );
	if ( ! str_ends_with( $ruta, '.php' ) ) {
		continue;
	}
	$t = leer_tokens( $ruta );
	$n = count( $t );
	[ $ns, $usos ] = info_fichero( $t );
	// Clase en la que está el fichero (para self:: y static::).
	$propia = null;
	for ( $i = 0; $i < $n; $i++ ) {
		if ( is_array( $t[ $i ] ) && T_CLASS === $t[ $i ][0] ) {
			$j = sig( $t, $i );
			if ( null !== $j && is_array( $t[ $j ] ) && T_STRING === $t[ $j ][0] ) {
				$propia = ( '' !== $ns ? $ns . '\\' : '' ) . $t[ $j ][1];
			}
			break;
		}
	}
	$hecho = 0;
	for ( $i = 0; $i < $n; $i++ ) {
		if ( ! is_array( $t[ $i ] ) || ! in_array( $t[ $i ][0], array( T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_STATIC ), true ) ) {
			continue;
		}
		$d = sig( $t, $i );
		if ( null === $d || ! is_array( $t[ $d ] ) || T_DOUBLE_COLON !== $t[ $d ][0] ) {
			continue;
		}
		$c = sig( $t, $d );
		if ( null === $c || ! is_array( $t[ $c ] ) || T_STRING !== $t[ $c ][0] ) {
			continue;
		}
		$sig = sig( $t, $c );
		if ( null !== $sig && '(' === $t[ $sig ] ) {
			continue; // es una llamada a método
		}
		$nom = strtolower( $t[ $i ][1] );
		if ( in_array( $nom, array( 'self', 'static' ), true ) ) {
			$fq = $propia;
		} else {
			$fq = resolver( $t[ $i ][1], $ns, $usos );
		}
		$clave = $fq . '::' . $t[ $c ][1];
		if ( null !== $fq && isset( $metodos[ $clave ] ) ) {
			$t[ $c ][1] = $metodos[ $clave ] . '()';
			$hecho++;
		}
	}
	if ( $hecho ) {
		$usos_cambiados += $hecho;
		if ( ! $seco ) {
			file_put_contents( $ruta, a_texto( $t ) );
		}
	}
}
echo implode( "\n", $informe ), "\n";
echo "\n", count( $metodos ), " constantes convertidas, $usos_cambiados usos reescritos\n";
foreach ( $saltadas as $s ) {
	echo "SALTADA: $s\n";
}
