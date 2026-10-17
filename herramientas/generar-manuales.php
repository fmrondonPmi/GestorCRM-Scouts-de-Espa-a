<?php
/**
 * Genera los manuales de uso por perfil (manual/*.html) a partir de los apartados de manual/fuentes/.
 *
 *   php herramientas/generar-manuales.php
 *
 * Cada apartado es un fichero con una o varias <section id="…"> con su <h2>; los que valen para varios
 * perfiles (entrar, contraseña, idioma…) se escriben una vez. El índice de cada manual sale de los <h2>
 * y de los <h3 id="…">. Atajos dentro de los apartados:
 *
 *   <captura src="03-inicio" url="/area-privada/" alt="…" larga>Pie de la captura</captura>
 *   <captura src="27-movil-inicio" movil alt="…">Pie</captura>         (marco de móvil)
 *   <moviles> … </moviles>                                               (fila de móviles con texto al lado)
 *   {{version}} · {{fecha}}
 *
 * Comprueba que las capturas existen y que los enlaces internos (#id) apuntan a algo del mismo manual.
 * No necesita WordPress.
 *
 * Ayuda de cada página (v0.26): un <section> o un <h3> con data-paginas="tesoreria banco" dice qué
 * páginas del área privada (claves de Frontend\Pages) documenta. Con eso se escriben, en el plugin,
 * los trozos asde-area-privada/ayuda/<manual>/<página>.html que salen en la ventana «Ayuda» de cada
 * página según el rol de quien entra (Frontend\Ayuda). Un <h3> con data-paginas lleva su parte (hasta
 * el siguiente <h3>) y la entrada del apartado; un <section>, el apartado entero. Desde la v0.28 la
 * ayuda del plugin es solo texto (sin capturas: el zip no puede pesar) y lleva además el manual
 * completo de cada rol (ayuda/<manual>/_completo.html), que «Ver el manual completo» abre en la
 * misma ventana; los enlaces van a él ({{completo}}, {{completo:otro}}, que pone el plugin). El
 * manual con capturas (manual/*.html) se sube a mano aparte, como ayuda extra. Al cambiar los
 * trozos cambia el plugin: hay que rehacer su zip.
 */

$raiz    = dirname( __DIR__ ) . '/manual';
$fuentes = $raiz . '/fuentes';
$plugin  = dirname( __DIR__ ) . '/asde-area-privada';
$version = '0.42';
$fecha   = 'octubre de 2026';
$errores = 0;

$manuales = array(
	'administracion' => array(
		'titulo'   => 'Manual de gestión del CRM y de la web',
		'perfil'   => 'Administración de WordPress',
		'entrada'  => 'Para quien instala y mantiene la web: la conexión de cada grupo con SinergiaCRM, la organización federada y el usuario de acceso, la instalación, los módulos de cada grupo (y las altas en el CRM), los documentos repetidos, el tema, los colores, los idiomas y las etiquetas, y qué datos se leen del CRM y cuáles guarda la web.',
		'apartados' => array( 'admin-perfil', 'admin-crm', 'admin-grupos', 'admin-modulos', 'admin-duplicados', 'admin-organizacion', 'admin-entorno', 'admin-app', 'admin-tema', 'admin-colores', 'admin-idiomas', 'admin-datos', 'admin-diagnostico', 'preguntas-admin' ),
	),
	'familias'      => array(
		'titulo'   => 'Manual para familias',
		'perfil'   => 'Madres, padres y tutores',
		'entrada'  => 'Cómo entrar en el área privada del grupo con la cuenta familiar, ver y corregir las fichas de tus hijos, inscribirlos en las salidas y acampadas, consultar los pagos, firmar la domiciliación SEPA y pedir el uniforme.',
		'apartados' => array( 'familias-perfil', 'entrar-familias', 'solicitar', 'primera-vez', 'instalar-app', 'familias-inicio', 'familias-fichas', 'mis-eventos', 'mis-pagos', 'mis-pagos-sepa', 'uniforme-pedir', 'idioma', 'seguridad-opcional', 'preguntas-familias' ),
	),
	'participantes' => array(
		'titulo'   => 'Manual para asociados menores de edad',
		'perfil'   => 'Educandos de 14 a 17 años con cuenta propia',
		'entrada'  => 'Si tienes entre 14 y 17 años y eres socio/a del grupo, puedes tener tu propia cuenta: consultar tu ficha, tener al día tu email y tus teléfonos, preinscribirte en las actividades, ver tus pagos y pedir tu uniforme.',
		'apartados' => array( 'participantes-perfil', 'entrar-socios', 'solicitar', 'primera-vez', 'instalar-app', 'participantes-inicio', 'participantes-ficha', 'mis-eventos', 'participantes-pagos', 'uniforme-pedir', 'idioma', 'seguridad-opcional', 'preguntas-participantes' ),
	),
	'scouters'      => array(
		'titulo'   => 'Manual para scouters',
		'perfil'   => 'Scouters y voluntariado con sección',
		'entrada'  => 'Lo que hace un scouter con su sección en el área privada: las fichas de sus educandos, los cambios que proponen las familias, las solicitudes de acceso, los informes (también quién ha pagado la cuota), los pagos y las familias, y los pedidos de uniforme. Y lo suyo: sus datos, sus pagos y su propio uniforme.',
		'apartados' => array( 'scouters-perfil', 'scouters-alta', 'entrar-socios', 'primera-vez', 'instalar-app', 'seguridad-opcional', 'scouters-inicio', 'ventana', 'scouters-fichas', 'cambios', 'scouters-solicitudes', 'informes', 'scouters-tesoreria', 'scouters-uniformes', 'eventos', 'inventario', 'lista-espera', 'idioma', 'preguntas-scouters' ),
	),
	'coordinacion'  => array(
		'titulo'   => 'Manual del equipo de coordinación',
		'perfil'   => 'Tesorería, Secretaría y Coordinación',
		'entrada'  => 'Todo lo que lleva el equipo del grupo: personas y números de socio, altas, bajas y cambios de sección en el CRM, dar acceso, solicitudes, los usuarios (con sus hojas: usuarios, dar acceso y registro de emails), fichas y cambios, informes, la Tesorería completa (cuotas, coste federativo, banco y domiciliaciones SEPA) y los uniformes, del pago a la entrega.',
		'apartados' => array( 'coordinacion-perfil', 'entrar-socios', 'primera-vez', 'instalar-app', 'seguridad-opcional', 'coordinacion-inicio', 'ventana', 'personas', 'altas-crm', 'alta', 'solicitudes', 'usuarios', 'coordinacion-fichas', 'cambios', 'informes', 'tesoreria', 'uniformes-gestion', 'eventos', 'inventario', 'lista-espera', 'idioma', 'preguntas-coordinacion' ),
	),
);

/** Ancho y alto reales de una captura (para el <img>). */
function tamano( string $raiz, string $src ): array {
	$f = "$raiz/capturas/$src.png";
	if ( ! is_file( $f ) ) {
		return array( 0, 0 );
	}
	$t = getimagesize( $f );
	return array( (int) $t[0], (int) $t[1] );
}

/** Expande <captura …>pie</captura>. */
function capturas( string $h, string $raiz, int &$errores, string $manual ): string {
	return preg_replace_callback(
		'~<captura\s+([^>]*)>(.*?)</captura>~s',
		static function ( $m ) use ( $raiz, &$errores, $manual ) {
			preg_match_all( '~(\w+)(?:="([^"]*)")?~', $m[1], $at, PREG_SET_ORDER );
			$a = array();
			foreach ( $at as $x ) {
				$a[ $x[1] ] = $x[2] ?? true;
			}
			$src = (string) ( $a['src'] ?? '' );
			list( $w, $hh ) = tamano( $raiz, $src );
			if ( ! $w ) {
				fwrite( STDERR, "[$manual] Falta la captura $src\n" );
				++$errores;
			}
			$alt   = htmlspecialchars( (string) ( $a['alt'] ?? '' ), ENT_QUOTES );
			$img   = '<img src="capturas/' . $src . '.png" width="' . $w . '" height="' . $hh . '" alt="' . $alt . '" loading="lazy">';
			$pie   = '<figcaption><span>' . trim( $m[2] ) . '</span><button class="ampliar" type="button">Ampliar</button></figcaption>';
			if ( isset( $a['movil'] ) ) {
				return '<figure class="captura movil"><div class="navegador"><div class="navegador__vista" data-ampliar><img src="capturas/' . $src . '.png" width="' . $w . '" height="' . $hh . '" alt="' . $alt . '" loading="lazy"></div></div>' . $pie . '</figure>';
			}
			$barra = isset( $a['url'] ) ? '<div class="navegador__barra"><span class="navegador__url">' . htmlspecialchars( (string) $a['url'] ) . '</span></div>' : '';
			$larga = isset( $a['larga'] );
			return '<figure class="captura' . ( $larga ? ' captura--larga' : '' ) . '"><div class="navegador">' . $barra . '<div class="navegador__vista" data-ampliar' . ( $larga ? ' tabindex="0"' : '' ) . '>' . $img . '</div></div>' . $pie . '</figure>';
		},
		$h
	);
}

$nombres = array_map( static fn( $m ) => $m['titulo'], $manuales );
$tarjetas = '';
foreach ( $manuales as $slug => $m ) {
	$cuerpo = '';
	foreach ( $m['apartados'] as $ap ) {
		$f = "$fuentes/$ap.html";
		if ( ! is_file( $f ) ) {
			fwrite( STDERR, "[$slug] Falta el apartado $ap\n" );
			++$errores;
			continue;
		}
		$cuerpo .= "\n" . trim( (string) file_get_contents( $f ) ) . "\n";
	}
	$cuerpo = str_replace( array( '{{version}}', '{{fecha}}' ), array( $version, $fecha ), $cuerpo );
	$cuerpo = preg_replace( '~\sdata-paginas="[^"]*"~', '', $cuerpo ); // Solo sirve para la ayuda de cada página.
	$cuerpo = preg_replace( '~<moviles>~', '<div class="moviles">', $cuerpo );
	$cuerpo = preg_replace( '~</moviles>~', '</div>', $cuerpo );
	$cuerpo = capturas( $cuerpo, $raiz, $errores, $slug );

	// Índice: <section id> + <h2> y los <h3 id> de dentro.
	$indice = '';
	$movil  = '';
	preg_match_all( '~<section id="([^"]+)">(.*?)</section>~s', $cuerpo, $secs, PREG_SET_ORDER );
	$ids = array();
	foreach ( $secs as $s ) {
		preg_match( '~<h2[^>]*>(.*?)</h2>~s', $s[2], $h2 );
		$titulo = trim( strip_tags( preg_replace( '~<span class="quien">.*?</span>~s', '', $h2[1] ?? $s[1] ) ) );
		$indice .= '<li><a href="#' . $s[1] . '">' . $titulo . "</a></li>\n";
		$movil  .= '<li><a href="#' . $s[1] . '">' . $titulo . "</a></li>\n";
		preg_match_all( '~<h3 id="([^"]+)"[^>]*>(.*?)</h3>~s', $s[2], $h3s, PREG_SET_ORDER );
		foreach ( $h3s as $h3 ) {
			$indice .= '<li class="sub"><a href="#' . $h3[1] . '">' . trim( strip_tags( $h3[2] ) ) . "</a></li>\n";
		}
	}
	preg_match_all( '~\bid="([^"]+)"~', $cuerpo, $todos );
	foreach ( array_count_values( $todos[1] ) as $id => $n ) {
		if ( $n > 1 ) {
			fwrite( STDERR, "[$slug] id repetido: $id\n" );
			++$errores;
		}
	}
	preg_match_all( '~href="#([^"]+)"~', $cuerpo, $enl );
	foreach ( array_unique( $enl[1] ) as $id ) {
		if ( ! in_array( $id, $todos[1], true ) ) {
			fwrite( STDERR, "[$slug] enlace roto: #$id\n" );
			++$errores;
		}
	}
	preg_match_all( '~href="([a-z-]+)\.html(?:#([^"]+))?"~', $cuerpo, $ext, PREG_SET_ORDER );
	foreach ( $ext as $e ) {
		if ( ! isset( $manuales[ $e[1] ] ) && 'index' !== $e[1] ) {
			fwrite( STDERR, "[$slug] enlace a un manual que no existe: {$e[1]}.html\n" );
			++$errores;
		}
	}

	$otros = '';
	foreach ( $manuales as $s2 => $m2 ) {
		if ( $s2 !== $slug ) {
			$otros .= '<li><a href="' . $s2 . '.html">' . $m2['titulo'] . '</a> <span class="otros__para">· ' . $m2['perfil'] . "</span></li>\n";
		}
	}

	$html = '<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title>' . $m['titulo'] . ' · Área privada de los Grupos Scouts</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Barlow+Semi+Condensed:wght@600;700&family=JetBrains+Mono:wght@500&family=Source+Sans+3:ital,wght@0,400;0,600;0,700;1,400&display=swap">
<link rel="stylesheet" href="manual.css">
</head>
<body>
<header class="portada">
	<div class="portada__dentro">
		<p class="antetitulo">Manual de uso · versión ' . $version . ' · ' . $fecha . ' · <a class="portada__volver" href="index.html">Todos los manuales</a></p>
		<h1>' . $m['titulo'] . '</h1>
		<p class="portada__perfil">' . $m['perfil'] . '</p>
		<p class="entrada">' . $m['entrada'] . '</p>
		<p class="demo"><strong>Todas las capturas son de un WordPress de demostración con un CRM simulado y datos inventados:</strong> el «Grupo Scout Ejemplo» (grupo 999), personas que no existen y emails <span class="mono">@example.invalid</span>. En tu grupo verás tus datos del CRM.</p>
	</div>
	<div class="secciones-franja" aria-hidden="true">
		<span style="background: var(--castores)"></span><span style="background: var(--lobatos)"></span><span style="background: var(--scouts)"></span><span style="background: var(--escultas)"></span><span style="background: var(--rovers)"></span>
	</div>
</header>

<div class="cuerpo">
	<nav class="indice" aria-label="Índice">
		<h2>En este manual</h2>
		<ol>
' . $indice . '		</ol>
	</nav>

	<main>
		<details class="indice-movil">
			<summary>Índice del manual</summary>
			<ol>
' . $movil . '			</ol>
		</details>
' . $cuerpo . '
		<section id="otros-manuales" class="otros">
			<h2>Los otros manuales</h2>
			<ul>
' . $otros . '			</ul>
		</section>

		<p class="pie">' . $m['titulo'] . ' del plugin «ASDE Scouts de España – Gestor CRM para Grupos Scouts», versión ' . $version . ' (' . $fecha . '). Capturas hechas en un WordPress de demostración con un CRM simulado y datos inventados.</p>
	</main>
</div>

<dialog id="visor" aria-label="Captura ampliada">
	<div class="visor__barra"><span id="visor-titulo">Captura</span><button type="button" id="visor-cerrar">Cerrar</button></div>
	<div class="visor__imagen"><img id="visor-img" alt=""></div>
</dialog>
<script src="manual.js"></script>
</body>
</html>
';
	file_put_contents( "$raiz/$slug.html", $html );
	echo "$slug.html: ", count( $secs ), ' apartados, ', substr_count( $cuerpo, '<figure class="captura' ), " capturas\n";
	$tarjetas .= '<li class="tarjeta"><a href="' . $slug . '.html"><span class="tarjeta__perfil">' . $m['perfil'] . '</span><strong class="tarjeta__titulo">' . $m['titulo'] . '</strong><span class="tarjeta__texto">' . $m['entrada'] . "</span></a></li>\n";
}

// Portada con los cinco manuales.
$portada = '<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title>Manuales del área privada de los Grupos Scouts</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Barlow+Semi+Condensed:wght@600;700&family=JetBrains+Mono:wght@500&family=Source+Sans+3:ital,wght@0,400;0,600;0,700;1,400&display=swap">
<link rel="stylesheet" href="manual.css">
</head>
<body>
<header class="portada">
	<div class="portada__dentro">
		<p class="antetitulo">Manuales de uso · versión ' . $version . ' · ' . $fecha . '</p>
		<h1>Área privada de los Grupos Scouts</h1>
		<p class="entrada">Un manual para cada persona, con solo lo que le toca. Elige el tuyo.</p>
	</div>
	<div class="secciones-franja" aria-hidden="true">
		<span style="background: var(--castores)"></span><span style="background: var(--lobatos)"></span><span style="background: var(--scouts)"></span><span style="background: var(--escultas)"></span><span style="background: var(--rovers)"></span>
	</div>
</header>
<main class="portada-lista">
	<ul class="tarjetas">
' . $tarjetas . '	</ul>
	<p class="pie">Plugin «ASDE Scouts de España – Gestor CRM para Grupos Scouts», versión ' . $version . ' (' . $fecha . '). ¿Tienes varios papeles (por ejemplo, scouter y madre o padre)? Mira los dos manuales: cada cuenta ve lo suyo.</p>
</main>
</body>
</html>
';
file_put_contents( "$raiz/index.html", $portada );
echo "index.html: portada\n";

// --- Ayuda dentro del plugin (v0.26; solo texto desde la v0.28) -------------------------------
/**
 * Texto para la ayuda del plugin. Desde la v0.28 (petición del usuario: el zip con el manual y sus
 * capturas pesaba más de 10 MB) va sin imágenes: el pie de cada captura queda como una nota sobre
 * lo que se ve en pantalla. Sin la etiqueta de «quién», y con los enlaces dentro de la ventana:
 *   #id del mismo manual        → {{completo}}#asde-manual-id (el manual completo, en la ventana)
 *   otro.html#id                → {{completo:otro}}#asde-manual-id
 *   index.html, administracion.html → solo su texto (no están en el área privada)
 * Con $completo (el manual entero del rol) los id llevan el prefijo asde-manual- y los #id del
 * mismo manual saltan dentro de la misma ventana; sin él (el trozo de una página) se quitan los id,
 * que chocarían con los de la página. Las direcciones las pone el plugin (Frontend\Ayuda).
 */
function para_ayuda( string $h, string $manual, bool $completo = false ): string {
	global $manuales;
	$h = preg_replace( '~<span class="quien">.*?</span>~s', '', $h );
	$h = str_replace( array( '<moviles>', '</moviles>' ), '', $h );
	$h = preg_replace_callback(
		'~<captura\s+[^>]*>(.*?)</captura>~s',
		static fn( $m ) => '' === trim( $m[1] ) ? '' : '<p class="asde-ayuda__pantalla">' . trim( $m[1] ) . '</p>',
		$h
	);
	$h = preg_replace_callback(
		'~<a href="([^"]*)"([^>]*)>(.*?)</a>~s',
		static function ( $m ) use ( $manual, $completo, $manuales ) {
			list( , $href, $resto, $texto ) = $m;
			if ( preg_match( '~^#(.+)$~', $href, $x ) ) {
				return $completo ? '<a href="#asde-manual-' . $x[1] . '"' . $resto . '>' . $texto . '</a>' : '<a href="{{completo}}#asde-manual-' . $x[1] . '" data-asde-modal' . $resto . '>' . $texto . '</a>';
			}
			if ( preg_match( '~^([a-z-]+)\.html(?:#(.+))?$~', $href, $x ) ) {
				if ( 'index' === $x[1] || 'administracion' === $x[1] || ! isset( $manuales[ $x[1] ] ) ) {
					return $texto;
				}
				$ancla = isset( $x[2] ) ? '#asde-manual-' . $x[2] : '';
				if ( $completo && $x[1] === $manual ) {
					return '<a href="' . ( '' !== $ancla ? $ancla : '#asde-manual-indice' ) . '"' . $resto . '>' . $texto . '</a>';
				}
				return '<a href="' . ( $x[1] === $manual ? '{{completo}}' : '{{completo:' . $x[1] . '}}' ) . $ancla . '" data-asde-modal' . $resto . '>' . $texto . '</a>';
			}
			return '<a href="' . $href . '"' . ( str_contains( $resto, 'target=' ) ? '' : ' target="_blank" rel="noopener"' ) . $resto . '>' . $texto . '</a>'; // Fuera del área privada.
		},
		$h
	);
	$h = preg_replace( '~\sdata-paginas="[^"]*"~', '', $h );
	return $completo ? preg_replace( '~\sid="([^"]+)"~', ' id="asde-manual-$1"', $h ) : preg_replace( '~\sid="[^"]*"~', '', $h );
}

preg_match_all( "~^\s*'([a-z_]+)'\s*=>\s*array\(~m", (string) file_get_contents( "$plugin/src/Frontend/Pages.php" ), $pk );
$paginas_validas = $pk[1];
$ayuda           = array(); // manual => página => [ [html, ancla], … ]
foreach ( $manuales as $slug => $m ) {
	if ( 'administracion' === $slug ) {
		continue; // Su manual es de wp-admin: en el área privada, un administrador ve la ayuda de coordinación.
	}
	foreach ( $m['apartados'] as $ap ) {
		$texto = str_replace( array( '{{version}}', '{{fecha}}' ), array( $version, $fecha ), (string) @file_get_contents( "$fuentes/$ap.html" ) );
		preg_match_all( '~<section id="([^"]+)"([^>]*)>(.*?)</section>~s', $texto, $secciones, PREG_SET_ORDER );
		foreach ( $secciones as list( , $sec_id, $sec_attrs, $dentro ) ) {
			$de_seccion = preg_match( '~data-paginas="([^"]*)"~', $sec_attrs, $dp ) ? preg_split( '~\s+~', trim( $dp[1] ) ) : array();
			$partes     = preg_split( '~(?=<h3\b)~', $dentro );
			$entrada    = array_shift( $partes );
			$por_pagina = array();
			foreach ( $de_seccion as $p ) {
				$por_pagina[ $p ] = array( $dentro, $sec_id );
			}
			foreach ( $partes as $parte ) {
				if ( ! preg_match( '~^<h3\b([^>]*)>~', $parte, $h3 ) || ! preg_match( '~data-paginas="([^"]*)"~', $h3[1], $dp ) ) {
					continue;
				}
				preg_match( '~id="([^"]+)"~', $h3[1], $hid );
				foreach ( preg_split( '~\s+~', trim( $dp[1] ) ) as $p ) {
					if ( in_array( $p, $de_seccion, true ) ) {
						continue; // Ya va el apartado entero.
					}
					$por_pagina[ $p ] = isset( $por_pagina[ $p ] ) ? array( $por_pagina[ $p ][0] . $parte, $por_pagina[ $p ][1] ) : array( $entrada . $parte, $hid[1] ?? $sec_id );
				}
			}
			foreach ( $por_pagina as $p => list( $html, $ancla ) ) {
				if ( ! in_array( $p, $paginas_validas, true ) ) {
					fwrite( STDERR, "[$ap] data-paginas con una página que no existe: $p\n" );
					++$errores;
					continue;
				}
				$ayuda[ $slug ][ $p ][] = array( para_ayuda( $html, $slug ), $ancla );
			}
		}
	}
}
foreach ( (array) glob( "$plugin/ayuda/*/*.html" ) as $viejo ) {
	unlink( $viejo ); // Lo que ya no tiene ayuda no se queda con la anterior.
}
foreach ( $ayuda as $slug => $paginas ) {
	@mkdir( "$plugin/ayuda/$slug", 0777, true );
	ksort( $paginas );
	foreach ( $paginas as $p => $trozos ) {
		$html  = '<!-- Generado por herramientas/generar-manuales.php (manual «' . $slug . '», versión ' . $version . ') a partir de manual/fuentes: no se edita a mano. -->' . "\n";
		$html .= '<div class="asde-ayuda__texto" data-manual="' . $slug . '" data-ancla="' . $trozos[0][1] . '">' . "\n";
		foreach ( $trozos as list( $t ) ) {
			$html .= '<div class="asde-ayuda__apartado">' . trim( $t ) . "</div>\n";
		}
		file_put_contents( "$plugin/ayuda/$slug/$p.html", $html . "</div>\n" );
	}
	echo "ayuda/$slug: ", implode( ', ', array_keys( $paginas ) ), "\n";
}

// El manual completo de cada rol, solo texto (v0.28): «Ver el manual completo» lo abre en la ventana.
// ayuda/<manual>/_completo.html (el «_» no puede ser una página: sus claves no lo llevan).
$tamano_ayuda = 0;
foreach ( $manuales as $slug => $m ) {
	if ( 'administracion' === $slug ) {
		continue;
	}
	$cuerpo = '';
	foreach ( $m['apartados'] as $ap ) {
		$cuerpo .= "\n" . trim( (string) @file_get_contents( "$fuentes/$ap.html" ) ) . "\n";
	}
	$texto = para_ayuda( str_replace( array( '{{version}}', '{{fecha}}' ), array( $version, $fecha ), $cuerpo ), $slug, true );
	$indice = '';
	preg_match_all( '~<section id="asde-manual-([^"]+)"[^>]*>(.*?)</section>~s', $texto, $secs, PREG_SET_ORDER );
	foreach ( $secs as $s ) {
		preg_match( '~<h2[^>]*>(.*?)</h2>~s', $s[2], $h2 );
		$indice .= '<li><a href="#asde-manual-' . $s[1] . '">' . trim( strip_tags( $h2[1] ?? $s[1] ) ) . '</a>';
		preg_match_all( '~<h3 id="asde-manual-([^"]+)"[^>]*>(.*?)</h3>~s', $s[2], $h3s, PREG_SET_ORDER );
		if ( $h3s ) {
			$indice .= '<ol>';
			foreach ( $h3s as $h3 ) {
				$indice .= '<li><a href="#asde-manual-' . $h3[1] . '">' . trim( strip_tags( $h3[2] ) ) . '</a></li>';
			}
			$indice .= '</ol>';
		}
		$indice .= "</li>\n";
	}
	preg_match_all( '~\sid="(asde-manual-[^"]+)"~', $texto, $ids_t );
	$ids_t = array_merge( $ids_t[1], array( 'asde-manual-indice' ) );
	preg_match_all( '~href="#(asde-manual-[^"]+)"~', $texto, $enl_t );
	foreach ( array_unique( $enl_t[1] ) as $id ) {
		if ( ! in_array( $id, $ids_t, true ) ) {
			fwrite( STDERR, "[$slug, manual completo de la ayuda] enlace roto: #$id\n" );
			++$errores;
		}
	}
	$html  = '<!-- Generado por herramientas/generar-manuales.php (manual «' . $slug . '» completo, solo texto, versión ' . $version . ') a partir de manual/fuentes: no se edita a mano. -->' . "\n";
	$html .= '<div class="asde-ayuda__texto asde-ayuda__completo" data-manual="' . $slug . '">' . "\n";
	$html .= '<h2>' . $m['titulo'] . "</h2>\n" . '<p class="resumen">' . $m['entrada'] . "</p>\n";
	$html .= '<nav class="asde-ayuda__indice" id="asde-manual-indice" aria-label="Índice del manual"><h3>En este manual</h3><ol>' . "\n" . $indice . "</ol></nav>\n";
	$html .= trim( $texto ) . "\n</div>\n";
	file_put_contents( "$plugin/ayuda/$slug/_completo.html", $html );
	echo "ayuda/$slug/_completo.html: ", count( $secs ), ' apartados, ', round( strlen( $html ) / 1024 ), " KB\n";
}
foreach ( (array) glob( "$plugin/ayuda/*/*.html" ) as $f ) {
	$tamano_ayuda += (int) filesize( $f );
	if ( preg_match( '~<img\b|\{\{capturas\}\}~', (string) file_get_contents( $f ) ) ) {
		fwrite( STDERR, 'La ayuda del plugin lleva una imagen: ' . basename( dirname( $f ) ) . '/' . basename( $f ) . "\n" );
		++$errores;
	}
}
echo 'Ayuda del plugin: ', round( $tamano_ayuda / 1024 ), " KB, solo texto\n";
echo $errores ? "$errores ERRORES\n" : "Sin errores\n";
exit( $errores ? 1 : 0 );
