<?php
/**
 * Pasa los textos literales del plugin de asde_t() & compañía (castellano como clave) a gettext estándar con el
 * original en inglés (doc 62). Herramienta de un solo uso; no necesita WordPress.
 *
 *   php herramientas/a-gettext.php <carpeta-del-plugin> <traducciones/es-en.json> [--seco]
 *
 * Reglas:
 *   asde_t('x')   → __( 'x', DOM )          asde_a('x') → esc_attr__( 'x', DOM )
 *   asde_h('x')   → __( 'x', DOM )          asde_e('x') → esc_html_e( 'x', DOM ) (o wp_kses( __() ) si lleva HTML)
 *   asde_js('x',…) → sprintf( __( 'x', DOM ), … )
 *   asde_n('a','b',$n) → _n( 'a', 'b', $n, DOM )
 *   __('x', DOM) y compañía con texto en castellano → el mismo con el texto en inglés.
 * Los marcadores sin número pasan a numerados cuando hay dos o más, y los textos con marcadores llevan su
 * comentario «translators». Lo dinámico (asde_t( $variable ), asde_ta()) no se toca: se lista al final.
 */

const DOM = 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts';
const ASDE_FUNCS = array( 'asde_t', 'asde_h', 'asde_e', 'asde_a', 'asde_js', 'asde_n' );
const GETTEXT_FUNCS = array( '__', '_e', 'esc_html__', 'esc_html_e', 'esc_attr__', 'esc_attr_e' );
const EXCLUIDOS = array( 'src/Admin/', 'src/Install/Entorno.php', 'src/Install/Installer.php', 'src/Idiomas/', 'tests/', 'herramientas/', 'languages/' );

$raiz  = rtrim( str_replace( '\\', '/', $argv[1] ?? '' ), '/' ) . '/';
$mapa  = json_decode( (string) file_get_contents( $argv[2] ?? '' ), true );
$seco  = in_array( '--seco', $argv, true );
if ( ! is_dir( $raiz ) || ! is_array( $mapa ) ) {
	fwrite( STDERR, "Uso: php a-gettext.php <plugin> <es-en.json> [--seco]\n" );
	exit( 1 );
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

/** Numera los marcadores sin número cuando hay dos o más. */
function numerar( string $en ): string {
	preg_match_all( '/%(?:(\d+)\$)?[sdfu]/', str_replace( '%%', '', $en ), $m );
	$sin = array_filter( $m[1], static fn( $x ) => '' === $x );
	if ( count( $m[0] ) < 2 || count( $sin ) !== count( $m[0] ) ) {
		return $en;
	}
	$i = 0;
	return preg_replace_callback(
		'/%%|%([sdfu])/',
		static function ( $x ) use ( &$i ) {
			if ( '%%' === $x[0] ) {
				return '%%';
			}
			return '%' . ( ++$i ) . '$' . $x[1];
		},
		$en
	);
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

function sig( array $t, int $desde, int $paso = 1 ): ?int {
	for ( $i = $desde + $paso; $i >= 0 && $i < count( $t ); $i += $paso ) {
		if ( is_array( $t[ $i ] ) && in_array( $t[ $i ][0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
			continue;
		}
		return $i;
	}
	return null;
}

function cierre( array $t, int $abre ): ?int {
	$n = 0;
	for ( $i = $abre; $i < count( $t ); $i++ ) {
		if ( '(' === $t[ $i ] ) {
			$n++;
		} elseif ( ')' === $t[ $i ] ) {
			$n--;
			if ( 0 === $n ) {
				return $i;
			}
		}
	}
	return null;
}

function es_literal( $tok ): bool {
	return is_array( $tok ) && T_CONSTANT_ENCAPSED_STRING === $tok[0];
}

$ficheros = array();
$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $raiz, FilesystemIterator::SKIP_DOTS ) );
foreach ( $it as $f ) {
	$rel = substr( str_replace( '\\', '/', $f->getPathname() ), strlen( $raiz ) );
	if ( ! str_ends_with( $rel, '.php' ) || ! str_contains( $rel, '/' ) || str_ends_with( $rel, 'uninstall.php' ) ) {
		continue;
	}
	foreach ( EXCLUIDOS as $e ) {
		if ( str_starts_with( $rel, $e ) ) {
			continue 2;
		}
	}
	$ficheros[] = $rel;
}
sort( $ficheros );

$cambios  = 0;
$sinmapa  = array();
$dinamico = array();
foreach ( $ficheros as $rel ) {
	$php = (string) file_get_contents( $raiz . $rel );
	$t   = token_get_all( $php );
	$n   = count( $t );
	$hecho = 0;
	$en_de = static function ( string $es, string $donde ) use ( $mapa, &$sinmapa ): ?string {
		if ( ! isset( $mapa[ $es ] ) || '' === $mapa[ $es ] ) {
			$sinmapa[ $es ] = $donde;
			return null;
		}
		return $mapa[ $es ];
	};
	for ( $i = 0; $i < $n; $i++ ) {
		if ( ! is_array( $t[ $i ] ) || T_STRING !== $t[ $i ][0] ) {
			continue;
		}
		$nombre = $t[ $i ][1];
		$asde   = in_array( $nombre, ASDE_FUNCS, true );
		if ( ! $asde && ! in_array( $nombre, GETTEXT_FUNCS, true ) && 'asde_ta' !== $nombre ) {
			continue;
		}
		$antes = sig( $t, $i, -1 );
		if ( null !== $antes && is_array( $t[ $antes ] ) && in_array( $t[ $antes ][0], array( T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW, T_NULLSAFE_OBJECT_OPERATOR ), true ) ) {
			continue;
		}
		$p = sig( $t, $i );
		if ( null === $p || '(' !== $t[ $p ] ) {
			continue;
		}
		$donde = $rel . ':' . $t[ $i ][2];
		if ( 'asde_ta' === $nombre ) {
			$dinamico[] = "$donde asde_ta(…)";
			continue;
		}
		$a = sig( $t, $p );
		if ( null === $a || ! es_literal( $t[ $a ] ) ) {
			$dinamico[] = "$donde $nombre( no literal )";
			continue;
		}
		$es = lit_valor( $t[ $a ][1] );
		if ( null === $es ) {
			$dinamico[] = "$donde $nombre( literal con variables )";
			continue;
		}
		if ( ! $asde ) {
			// __( 'castellano', DOM ): solo si el dominio es el del plugin y el texto está en el mapa.
			$c = sig( $t, $a );
			$d = null !== $c && ',' === $t[ $c ] ? sig( $t, $c ) : null;
			if ( null === $d || ! es_literal( $t[ $d ] ) || DOM !== lit_valor( $t[ $d ][1] ) || ! isset( $mapa[ $es ] ) ) {
				continue;
			}
			$en = numerar( $mapa[ $es ] );
			$t[ $a ][1] = comentario( $en ) ? lit( $en ) : lit( $en );
			if ( comentario( $en ) ) {
				$t[ $i ][1] = comentario( $en ) . $nombre;
			}
			$hecho++;
			continue;
		}
		$en = $en_de( $es, $donde );
		if ( null === $en ) {
			continue;
		}
		$en  = numerar( $en );
		$com = comentario( $en );
		$fin = cierre( $t, $p );
		if ( null === $fin ) {
			continue;
		}
		switch ( $nombre ) {
			case 'asde_t':
			case 'asde_h':
				$t[ $i ][1] = $com . '__';
				$t[ $a ][1] = lit( $en ) . ', ' . lit( DOM );
				break;
			case 'asde_a':
				$t[ $i ][1] = $com . 'esc_attr__';
				$t[ $a ][1] = lit( $en ) . ', ' . lit( DOM );
				break;
			case 'asde_e':
				if ( str_contains( $en, '<' ) ) {
					$t[ $i ][1] = 'echo wp_kses( ' . $com . '__';
					$t[ $a ][1] = lit( $en ) . ', ' . lit( DOM );
					$t[ $fin ]  = ' ), asde_html_permitido() )';
				} else {
					$t[ $i ][1] = $com . 'esc_html_e';
					$t[ $a ][1] = lit( $en ) . ', ' . lit( DOM );
				}
				break;
			case 'asde_js':
				$c = sig( $t, $a );
				if ( null !== $c && ',' === $t[ $c ] ) {
					$t[ $i ][1] = 'sprintf';
					$t[ $a ][1] = $com . '__( ' . lit( $en ) . ', ' . lit( DOM ) . ' )';
				} else {
					$t[ $i ][1] = $com . '__';
					$t[ $a ][1] = lit( $en ) . ', ' . lit( DOM );
				}
				break;
			case 'asde_n':
				$c = sig( $t, $a );
				$b = null !== $c && ',' === $t[ $c ] ? sig( $t, $c ) : null;
				if ( null === $b || ! es_literal( $t[ $b ] ) || null === lit_valor( $t[ $b ][1] ) ) {
					$dinamico[] = "$donde asde_n( plural no literal )";
					continue 2;
				}
				$en2 = $en_de( lit_valor( $t[ $b ][1] ), $donde );
				if ( null === $en2 ) {
					continue 2;
				}
				$en2  = numerar( $en2 );
				$com2 = comentario( $en ) ?: comentario( $en2 );
				$t[ $i ][1] = $com2 . '_n';
				$t[ $a ][1] = lit( $en );
				$t[ $b ][1] = lit( $en2 );
				$t[ $fin ]  = ', ' . lit( DOM ) . ' )';
				break;
		}
		$hecho++;
	}
	if ( $hecho ) {
		$cambios += $hecho;
		if ( ! $seco ) {
			$salida = '';
			foreach ( $t as $tok ) {
				$salida .= is_array( $tok ) ? $tok[1] : $tok;
			}
			if ( '' === $salida ) {
				fwrite( STDERR, "VACÍO: $rel\n" );
				exit( 1 );
			}
			file_put_contents( $raiz . $rel, $salida );
		}
	}
}
echo "$cambios llamadas convertidas en " . count( $ficheros ) . " ficheros\n";
echo count( $sinmapa ) . " textos sin traducción en el mapa\n";
foreach ( array_slice( $sinmapa, 0, 40, true ) as $es => $donde ) {
	echo "  [$donde] $es\n";
}
echo count( $dinamico ) . " llamadas dinámicas sin tocar\n";
file_put_contents( sys_get_temp_dir() . '/asde-dinamico.txt', implode( "\n", $dinamico ) . "\n" );
