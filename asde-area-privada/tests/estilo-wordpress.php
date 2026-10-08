<?php
/**
 * Pruebas de estilo de WordPress (v0.18.1, entorno LOCAL): lo que pide el revisor de plugins (Plugin Check /
 * WordPress Coding Standards) y que se rompe con facilidad al añadir código:
 *   A. Todo texto traducido que se imprime va escapado (esc_html/esc_attr/wp_kses_post alrededor de asde_t, asde_a,
 *      asde_h y asde_js cuando se hace echo).
 *   B. Los textos de __()/_n()… con marcadores llevan su comentario «translators:» y, con dos o más, marcadores numerados.
 *   C. Los mensajes de las excepciones no llevan texto sin escapar.
 *   D. Ningún fichero PHP del plugin mezcla finales de línea CRLF y LF.
 * Solo lee ficheros: no toca la base de datos ni el CRM. Ejecutar desde app/public del site Local:
 *
 *   wp eval-file wp-content/plugins/asde-area-privada/tests/estilo-wordpress.php
 */
defined( 'ABSPATH' ) || exit;

$fails = 0;
$ok    = function ( $cond, string $label ) use ( &$fails ) {
	if ( ! $cond ) {
		++$fails;
	}
	echo ( $cond ? 'OK   ' : 'FAIL ' ), $label, "\n";
};

/** @return string[] Rutas de los .php del plugin (sin tests ni herramientas). */
$ficheros = static function (): array {
	$out = array();
	$raiz = rtrim( str_replace( chr( 92 ), '/', ASDE_AP_DIR ), '/' );
	$it   = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $raiz, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $it as $f ) {
		$p = str_replace( chr( 92 ), '/', $f->getPathname() );
		if ( 'php' === $f->getExtension() && ! str_contains( $p, '/tests/' ) && ! str_contains( $p, '/herramientas/' ) && ! str_contains( $p, '/languages/' ) ) {
			$out[] = $p;
		}
	}
	sort( $out );
	return $out;
};
$rel = static fn( string $p ): string => ltrim( substr( $p, strlen( rtrim( str_replace( chr( 92 ), '/', ASDE_AP_DIR ), '/' ) ) ), '/' );

/** Siguiente/anterior token significativo (sin espacios ni comentarios). */
$sig = static function ( array $tk, int $i, int $dir ): int {
	$n = count( $tk );
	for ( $j = $i + $dir; $j >= 0 && $j < $n; $j += $dir ) {
		if ( is_array( $tk[ $j ] ) && in_array( $tk[ $j ][0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
			continue;
		}
		return $j;
	}
	return -1;
};
/** Posición del paréntesis que cierra el que se abre en $q. */
$cierre = static function ( array $tk, int $q ): int {
	$prof = 0;
	for ( $k = $q, $n = count( $tk ); $k < $n; $k++ ) {
		if ( '(' === $tk[ $k ] ) {
			++$prof;
		} elseif ( ')' === $tk[ $k ] ) {
			--$prof;
			if ( 0 === $prof ) {
				return $k;
			}
		}
	}
	return -1;
};

$todos = $ficheros();
$ok( count( $todos ) > 150, 'se revisan los ficheros del plugin (' . count( $todos ) . ')' );
$readme = (string) file_get_contents( ASDE_AP_DIR . 'readme.txt' );
$ok( '' !== $readme && 1 === preg_match( '/^Stable tag:\s*(\S+)/mi', $readme, $st ) && ASDE_AP_VERSION === $st[1] && 1 === preg_match( '/^License:\s*MIT/mi', $readme ), 'readme.txt existe, su «Stable tag» es la versión del plugin y la licencia coincide con la cabecera' );
$dominio_cab = get_file_data( ASDE_AP_FILE, array( 'd' => 'Text Domain' ) )['d'];
$ok( basename( rtrim( ASDE_AP_DIR, '/' . chr( 92 ) ) ) === $dominio_cab, 'el dominio de textos de la cabecera es el nombre de la carpeta del plugin' );

// ── A. Salida escapada ───────────────────────────────────────────────────────
echo "\n── A. Textos traducidos que se imprimen\n";
$inseguros = array();
$escapan   = array_flip( array( 'esc_html', 'esc_attr', 'esc_js', 'esc_url', 'esc_textarea', 'esc_url_raw', 'wp_kses_post', 'wp_kses', 'absint', 'intval', 'number_format_i18n', 'wp_json_encode', 'sanitize_text_field', 'sanitize_key' ) );
$objetivo  = array_flip( array( 'asde_t', 'asde_a', 'asde_h', 'asde_js' ) );
foreach ( $todos as $fichero ) {
	if ( str_contains( $fichero, '/templates/email/' ) ) {
		continue; // Los emails llevan su propio phpcs:ignore (HTML de correo con estilos en línea).
	}
	$tk = token_get_all( (string) file_get_contents( $fichero ) );
	$n  = count( $tk );
	for ( $i = 0; $i < $n; $i++ ) {
		$t   = $tk[ $i ];
		$ini = is_array( $t ) && ( T_ECHO === $t[0] || T_OPEN_TAG_WITH_ECHO === $t[0] || T_PRINT === $t[0] );
		if ( ! $ini && is_array( $t ) && T_STRING === $t[0] && in_array( strtolower( $t[1] ), array( 'printf', 'vprintf' ), true ) ) {
			$p   = $sig( $tk, $i, -1 );
			$q   = $sig( $tk, $i, 1 );
			$ini = $q >= 0 && '(' === $tk[ $q ] && ! ( $p >= 0 && is_array( $tk[ $p ] ) && in_array( $tk[ $p ][0], array( T_FUNCTION, T_DOUBLE_COLON, T_OBJECT_OPERATOR ), true ) );
		}
		if ( ! $ini ) {
			continue;
		}
		$pila = array();
		for ( $j = $i + 1; $j < $n; $j++ ) {
			$x = $tk[ $j ];
			if ( is_array( $x ) && T_CLOSE_TAG === $x[0] ) {
				break;
			}
			if ( '(' === $x ) {
				$p      = $sig( $tk, $j, -1 );
				$pila[] = ( $p >= 0 && is_array( $tk[ $p ] ) && T_STRING === $tk[ $p ][0] ) ? strtolower( $tk[ $p ][1] ) : '';
				continue;
			}
			if ( ')' === $x ) {
				array_pop( $pila );
				continue;
			}
			if ( ';' === $x && ! $pila ) {
				break;
			}
			if ( is_array( $x ) && T_STRING === $x[0] && isset( $objetivo[ $x[1] ] ) ) {
				$q = $sig( $tk, $j, 1 );
				$p = $sig( $tk, $j, -1 );
				if ( $q < 0 || '(' !== $tk[ $q ] || ( $p >= 0 && is_array( $tk[ $p ] ) && in_array( $tk[ $p ][0], array( T_FUNCTION, T_DOUBLE_COLON, T_OBJECT_OPERATOR ), true ) ) ) {
					continue;
				}
				if ( ! array_intersect_key( array_flip( $pila ), $escapan ) ) {
					$inseguros[] = $rel( $fichero ) . ':' . $x[2] . ' ' . $x[1] . '()';
				}
			}
		}
	}
}
$ok( ! $inseguros, 'ningún asde_t/asde_a/asde_h/asde_js se imprime sin escapar (esc_html, esc_attr, wp_kses_post…)' . ( $inseguros ? ': ' . implode( ', ', array_slice( $inseguros, 0, 8 ) ) : '' ) );

// ── B. Comentarios «translators:» y marcadores numerados ─────────────────────
echo "\n── B. Marcadores en los textos traducibles de WordPress\n";
$sin_comentario = array();
$sin_orden      = array();
$funciones_i18n = array_flip( array( '__', '_e', '_x', '_ex', '_n', 'esc_html__', 'esc_html_e', 'esc_html_x', 'esc_attr__', 'esc_attr_e', 'esc_attr_x' ) );
foreach ( $todos as $fichero ) {
	$tk = token_get_all( (string) file_get_contents( $fichero ) );
	$n  = count( $tk );
	for ( $i = 0; $i < $n; $i++ ) {
		$t = $tk[ $i ];
		if ( ! is_array( $t ) || T_STRING !== $t[0] || ! isset( $funciones_i18n[ $t[1] ] ) ) {
			continue;
		}
		$q = $sig( $tk, $i, 1 );
		$p = $sig( $tk, $i, -1 );
		if ( $q < 0 || '(' !== $tk[ $q ] || ( $p >= 0 && is_array( $tk[ $p ] ) && in_array( $tk[ $p ][0], array( T_FUNCTION, T_DOUBLE_COLON, T_OBJECT_OPERATOR ), true ) ) ) {
			continue;
		}
		$a = $sig( $tk, $q, 1 );
		if ( $a < 0 || ! is_array( $tk[ $a ] ) || T_CONSTANT_ENCAPSED_STRING !== $tk[ $a ][0] ) {
			continue;
		}
		$texto = (string) $tk[ $a ][1];
		$cantidad = (int) preg_match_all( '/%(?:\d+\$)?[sdfFuoxXeEgG]/', str_replace( '%%', '', $texto ) );
		if ( $cantidad < 1 ) {
			continue;
		}
		// ¿Hay un comentario «translators:» justo antes (misma línea o la de arriba)?
		$linea = (int) $t[2];
		$hay   = false;
		for ( $k = $i - 1; $k >= 0 && $k >= $i - 40; $k-- ) {
			if ( is_array( $tk[ $k ] ) && in_array( $tk[ $k ][0], array( T_COMMENT, T_DOC_COMMENT ), true ) && stripos( $tk[ $k ][1], 'translators' ) !== false ) {
				$hay = $linea - (int) $tk[ $k ][2] <= 1 + substr_count( $tk[ $k ][1], "\n" );
				break;
			}
			if ( is_array( $tk[ $k ] ) && isset( $tk[ $k ][2] ) && $linea - (int) $tk[ $k ][2] > 2 ) {
				break;
			}
		}
		if ( ! $hay ) {
			$sin_comentario[] = $rel( $fichero ) . ':' . $linea;
		}
		if ( $cantidad >= 2 && $cantidad !== preg_match_all( '/%\d+\$[sdfFuoxXeEgG]/', $texto ) ) {
			$sin_orden[] = $rel( $fichero ) . ':' . $linea;
		}
	}
}
$ok( ! $sin_comentario, 'todo texto con marcadores lleva su comentario «translators:» encima' . ( $sin_comentario ? ': ' . implode( ', ', array_slice( $sin_comentario, 0, 8 ) ) : '' ) );
$ok( ! $sin_orden, 'con dos o más marcadores, van numerados (%1$s, %2$d…)' . ( $sin_orden ? ': ' . implode( ', ', array_slice( $sin_orden, 0, 8 ) ) : '' ) );

// ── C. Excepciones ───────────────────────────────────────────────────────────
echo "\n── C. Mensajes de excepciones\n";
$sin_escapar = array();
foreach ( $todos as $fichero ) {
	$tk = token_get_all( (string) file_get_contents( $fichero ) );
	$n  = count( $tk );
	for ( $i = 0; $i < $n; $i++ ) {
		if ( ! is_array( $tk[ $i ] ) || T_THROW !== $tk[ $i ][0] ) {
			continue;
		}
		$nuevo = $sig( $tk, $i, 1 );
		if ( $nuevo < 0 || ! is_array( $tk[ $nuevo ] ) || T_NEW !== $tk[ $nuevo ][0] ) {
			continue;
		}
		$q = -1;
		for ( $k = $nuevo + 1; $k < $n && $k < $nuevo + 8; $k++ ) {
			if ( '(' === $tk[ $k ] ) {
				$q = $k;
				break;
			}
			if ( ';' === $tk[ $k ] ) {
				break;
			}
		}
		if ( $q < 0 ) {
			continue;
		}
		$f = $cierre( $tk, $q );
		$a = $sig( $tk, $q, 1 );
		if ( $f < 0 || $a < 0 || $a >= $f ) {
			continue; // Sin argumentos.
		}
		$primero = $tk[ $a ];
		if ( is_array( $primero ) && T_CONSTANT_ENCAPSED_STRING === $primero[0] ) {
			continue; // Texto fijo escrito en el código.
		}
		if ( is_array( $primero ) && T_STRING === $primero[0] && 'esc_html' === strtolower( $primero[1] ) ) {
			continue;
		}
		$sin_escapar[] = $rel( $fichero ) . ':' . $tk[ $i ][2];
	}
}
$ok( ! $sin_escapar, 'el mensaje de cada excepción es un texto fijo o va dentro de esc_html()' . ( $sin_escapar ? ': ' . implode( ', ', array_slice( $sin_escapar, 0, 8 ) ) : '' ) );

// ── D. Finales de línea ──────────────────────────────────────────────────────
echo "\n── D. Finales de línea\n";
$mixtos = array();
foreach ( $todos as $fichero ) {
	$s    = (string) file_get_contents( $fichero );
	$crlf = substr_count( $s, "\r\n" );
	$lf   = substr_count( $s, "\n" ) - $crlf;
	if ( $crlf > 0 && $lf > 0 ) {
		$mixtos[] = $rel( $fichero ) . " ({$crlf} CRLF, {$lf} LF)";
	}
}
$ok( ! $mixtos, 'ningún fichero mezcla CRLF y LF' . ( $mixtos ? ': ' . implode( ', ', array_slice( $mixtos, 0, 8 ) ) : '' ) );

// ── E. Plugin Check (v0.26.2): consultas, plantillas y uninstall.php ─────────
// Lo que corrigió la v0.26.2 a partir del informe de Plugin Check (skill plugin-check-area-privada).
echo "\n── E. Plugin Check: consultas, plantillas y uninstall.php\n";
$concatenadas = array();
$montadas     = array();
foreach ( $todos as $fichero ) {
	$s = (string) file_get_contents( $fichero );
	if ( preg_match_all( '/\b(?:FROM|JOIN|INTO|UPDATE)\s*[\'"]\s*\.\s*(?:self|static|[A-Z]\w*)::table\w*\s*\(/i', $s, $m, PREG_OFFSET_CAPTURE ) ) {
		foreach ( $m[0] as $hit ) {
			$concatenadas[] = $rel( $fichero ) . ':' . ( 1 + substr_count( substr( $s, 0, $hit[1] ), "\n" ) );
		}
	}
	if ( preg_match_all( '/\$sql\s*\.=/', $s, $m, PREG_OFFSET_CAPTURE ) ) {
		foreach ( $m[0] as $hit ) {
			$montadas[] = $rel( $fichero ) . ':' . ( 1 + substr_count( substr( $s, 0, $hit[1] ), "\n" ) );
		}
	}
}
$ok( ! $concatenadas, 'ninguna tabla propia se concatena en el SQL (va con %i en $wpdb->prepare())' . ( $concatenadas ? ': ' . implode( ', ', array_slice( $concatenadas, 0, 8 ) ) : '' ) );
$ok( ! $montadas, 'ninguna consulta se monta con «$sql .=» (filtros opcionales como valores en una consulta fija)' . ( $montadas ? ': ' . implode( ', ', array_slice( $montadas, 0, 8 ) ) : '' ) );

/** Variables asignadas fuera de funciones (nivel superior del fichero): nombre => línea. */
$globales = static function ( string $fichero ) use ( $sig ): array {
	$tk      = token_get_all( (string) file_get_contents( $fichero ) );
	$out     = array();
	$prof    = 0;     // Profundidad de llaves.
	$dentro  = array(); // Profundidades en las que empieza el cuerpo de una función.
	$espera  = false;   // Se ha visto «function» y falta su «{».
	foreach ( $tk as $i => $t ) {
		if ( is_array( $t ) && T_FUNCTION === $t[0] ) {
			$espera = true;
		} elseif ( '{' === $t || ( is_array( $t ) && in_array( $t[0], array( T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ), true ) ) ) {
			++$prof;
			if ( $espera ) {
				$dentro[] = $prof;
				$espera   = false;
			}
		} elseif ( '}' === $t ) {
			if ( $dentro && end( $dentro ) === $prof ) {
				array_pop( $dentro );
			}
			--$prof;
		} elseif ( ! $dentro && ! $espera && is_array( $t ) && T_VARIABLE === $t[0] && '$this' !== $t[1] ) {
			$q = $sig( $tk, $i, 1 );
			$p = $sig( $tk, $i, -1 );
			$asigna = $q >= 0 && '=' === $tk[ $q ];
			$bucle  = $p >= 0 && is_array( $tk[ $p ] ) && T_AS === $tk[ $p ][0];
			$clave  = $q >= 0 && is_array( $tk[ $q ] ) && T_DOUBLE_ARROW === $tk[ $q ][0] && $p >= 0 && is_array( $tk[ $p ] ) && T_AS === $tk[ $p ][0];
			if ( $asigna || $bucle || $clave ) {
				$out[ $t[1] ] ??= (int) $t[2];
			}
		}
	}
	return $out;
};
$sin_linea = array();
$plantillas = 0;
foreach ( $todos as $fichero ) {
	if ( ! str_contains( $fichero, '/templates/' ) ) {
		continue;
	}
	++$plantillas;
	if ( $globales( $fichero ) && ! str_contains( (string) file_get_contents( $fichero ), 'phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Plantilla' ) ) {
		$sin_linea[] = $rel( $fichero );
	}
}
$ok( $plantillas > 60 && ! $sin_linea, 'las plantillas con variables propias llevan la línea «phpcs:disable … PrefixAllGlobals» con su motivo (' . $plantillas . ' plantillas)' . ( $sin_linea ? ': ' . implode( ', ', array_slice( $sin_linea, 0, 8 ) ) : '' ) );
$sin_prefijo = array_filter( array_keys( $globales( ASDE_AP_DIR . 'uninstall.php' ) ), static fn( $v ) => ! str_starts_with( $v, '$asde_' ) && '$wpdb' !== $v );
$ok( ! $sin_prefijo, 'uninstall.php (ámbito global) solo usa variables con el prefijo $asde_' . ( $sin_prefijo ? ': ' . implode( ', ', $sin_prefijo ) : '' ) );

echo $fails ? "\n$fails FALLOS\n" : "\nTODO OK (solo lectura de ficheros)\n";
