<?php
/**
 * Pruebas de la v0.15 (entorno LOCAL): colores del área privada.
 * Ejecutar desde app/public del site Local:
 *
 *   wp eval-file <ruta completa>/wp-content/plugins/asde-area-privada/tests/colores.php
 *
 * Al terminar se restaura la opción de colores tal y como estaba.
 */
defined( 'ABSPATH' ) || exit;

use AsdeBalears\AreaPrivada\Admin\ColoresPage;
use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Frontend\Colores;
use AsdeBalears\AreaPrivada\Frontend\TemplateLoader;

global $wpdb;

$fails = 0;
$ok    = function ( $cond, string $label ) use ( &$fails ) {
	if ( ! $cond ) {
		++$fails;
	}
	echo ( $cond ? 'OK   ' : 'FAIL ' ), $label, "\n";
};

$antes     = get_option( Colores::OPCION, null );
$log_antes = (int) $wpdb->get_var( 'SELECT COALESCE(MAX(id), 0) FROM ' . Logger::table() ); // phpcs:ignore WordPress.DB
$admin     = get_users( array( 'role' => 'administrator', 'number' => 1 ) )[0] ?? null;
if ( ! $admin ) {
	exit( "Hace falta al menos un administrador en el sitio.\n" );
}
$sin_permiso = wp_insert_user(
	array(
		'user_login' => 'prueba-color-sin',
		'user_email' => '',
		'user_pass'  => wp_generate_password( 24 ),
		'role'       => 'subscriber',
	)
);

/** Llama a un método de ColoresPage como lo haría el navegador y devuelve el aviso o la respuesta. */
$redirigir = static function ( $destino ) {
	throw new RuntimeException( 'asde-redirect:' . $destino );
};
add_filter( 'wp_redirect', $redirigir, 1 );
$enviar = static function ( array $post ): void {
	$_POST    = $post + array( '_wpnonce' => wp_create_nonce( ColoresPage::ACCION ) );
	$_REQUEST = $_POST;
	try {
		( new ColoresPage() )->handle_guardar();
	} catch ( RuntimeException $e ) {
		// Redirige al terminar.
	} finally {
		$_POST    = array();
		$_REQUEST = array();
	}
};

try {
	delete_option( Colores::OPCION );

	echo "== Parte A · Colores válidos y valores por defecto ==\n";
	$ok( '#aabbcc' === Colores::normalizar( 'AABBCC' ) && '#aabbcc' === Colores::normalizar( '#abc' ) && null === Colores::normalizar( 'rojo' ) && null === Colores::normalizar( '#12345' ) && null === Colores::normalizar( array() ) && null === Colores::normalizar( 'url(x)' ), 'se aceptan #rgb, #rrggbb y rrggbb; lo demás no (nada de inyectar CSS)' );
	$ok( 12 === count( Colores::definiciones() ) && array() === Colores::guardados() && '' === Colores::css() && array() === Colores::variables(), 'sin elegir nada: 12 colores, ninguno guardado y ningún CSS añadido (el área se ve como siempre)' );
	$g = Colores::guardar( array( 'primary' => '#632599', 'text' => '#444444', 'ok' => '' ) );
	$ok( array() === $g && null === get_option( Colores::OPCION, null ), 'un color igual al de siempre (o vacío) no se guarda: así el principal sigue siendo el de Enfold' );
	$g = Colores::guardar( array( 'primary' => '#2E7D32', 'ok' => 'no-es-color', 'zzz' => '#ffffff', 'muted' => '#555' ) );
	$ok( array( 'primary' => '#2e7d32', 'muted' => '#555555' ) === $g && $g === Colores::guardados(), 'solo se guardan los colores conocidos y válidos, normalizados' );

	echo "\n== Parte B · De un color salen los demás ==\n";
	$v = Colores::variables( array( 'primary' => '#2e7d32' ) );
	$ok( '#2e7d32' === $v['--asde-primary'] && isset( $v['--asde-primary-dark'], $v['--asde-lila'], $v['--asde-tint'], $v['--asde-tint-strong'], $v['--asde-tint-line'], $v['--asde-on-primary'] ), 'al elegir el principal salen su oscuro, su acento, sus tres fondos y el color del texto encima' );
	$ok( Colores::luminancia( $v['--asde-primary-dark'] ) < Colores::luminancia( '#2e7d32' ) && Colores::luminancia( $v['--asde-tint'] ) > 0.85, 'el oscuro es más oscuro y el fondo suave es casi blanco' );
	$v2 = Colores::variables( array( 'primary' => '#2e7d32', 'primary_dark' => '#102010', 'tint' => '#ffffee' ) );
	$ok( '#102010' === $v2['--asde-primary-dark'] && '#ffffee' === $v2['--asde-tint'], 'lo que se elige a mano manda sobre lo derivado' );
	$ok( '#ffffff' === Colores::sobre( '#1d5fa8' ) && '#1a1a1a' === Colores::sobre( '#f4d35e' ), 'sobre un principal oscuro el texto es blanco; sobre uno claro, casi negro' );
	$ve = Colores::variables( array( 'error' => '#d00000' ) );
	$ok( isset( $ve['--asde-error-bg'], $ve['--asde-error-text'] ) && ! isset( $ve['--asde-primary'] ) && Colores::contraste( $ve['--asde-error-text'], $ve['--asde-error-bg'] ) >= 4.5, 'al elegir un estado salen su fondo y su texto, y el texto se lee sobre el fondo (4,5:1)' );
	$ok( isset( Colores::variables( array( 'ok' => '#00a000' ) )['--asde-ok-strong'] ) && isset( Colores::variables( array( 'border' => '#999999' ) )['--asde-neutral-bg'] ), 'el verde saca también su tono fuerte y los bordes, el fondo neutro' );
	$ok( abs( Colores::contraste( '#000000', '#ffffff' ) - 21.0 ) < 0.01 && abs( Colores::contraste( '#777777', '#777777' ) - 1.0 ) < 0.001, 'el cálculo de contraste (WCAG) da 21:1 para negro sobre blanco y 1:1 entre iguales' );

	echo "\n== Parte C · El CSS que se emite ==\n";
	$css = Colores::css( array( 'primary' => '#2e7d32' ) );
	$ok( str_starts_with( $css, ':root,.asde-ap{' ) && str_contains( $css, '--asde-primary:#2e7d32;' ) && str_contains( $css, '.asde-ap .button' ), 'sobrescribe las variables en :root y .asde-ap, y recolorea los botones del área con el principal' );
	$ok( ! str_contains( Colores::css( array( 'text' => '#222222' ) ), '.button' ) && ! str_contains( $css, '<' ) && ! str_contains( $css, 'url(' ), 'sin color principal no toca los botones; el CSS no lleva nada raro' );
	$def_css = str_replace( "\r\n", "\n", (string) file_get_contents( ASDE_AP_DIR . 'assets/css/area-privada.css' ) ); // Con finales de línea de Windows (git en Windows) también.
	$vars_css = array();
	preg_match_all( '/--(asde-[a-z-]+):/', substr( $def_css, 0, (int) strpos( $def_css, "\n}\n" ) ), $vars_css );
	$conocidas = array_unique( $vars_css[1] );
	$todas     = array();
	foreach ( array( 'primary', 'primary_dark', 'lila', 'tint', 'text', 'muted', 'border', 'surface', 'ok', 'warn', 'error', 'info' ) as $k ) {
		$todas = array_merge( $todas, array_keys( Colores::variables( array( $k => '#123456' ) ) ) );
	}
	$ok( ! array_diff( array_map( static fn( $n ) => ltrim( $n, '-' ), array_unique( $todas ) ), $conocidas ), 'todas las variables que se sobrescriben existen en el CSS del plugin (' . count( $conocidas ) . ' definidas)' );
	preg_match_all( '/#[0-9a-fA-F]{3,6}\b/', substr( $def_css, (int) strpos( $def_css, "\n}\n" ) ), $sueltos );
	$sueltos = array_diff( array_map( 'strtolower', $sueltos[0] ), array( '#ffffff', '#000', '#2b1a3d', '#999999' ) );
	$ok( ! $sueltos, 'el CSS ya no tiene colores fijos de marca ni de estado fuera de las variables' . ( $sueltos ? ': ' . implode( ' ', array_unique( $sueltos ) ) : '' ) );

	echo "\n== Parte D · Avisos de contraste ==\n";
	$ok( array() === Colores::avisos( array() ), 'los colores de siempre no dan ningún aviso' );
	$av = Colores::avisos( array( 'primary' => '#ffff99' ) );
	$ok( 1 <= count( $av ) && str_contains( $av[0], 'color principal' ) && str_contains( $av[0], ':1' ), 'un principal muy claro sobre fondo blanco avisa de que costará leerlo' );
	$av = Colores::avisos( array( 'text' => '#cccccc' ) );
	$ok( 1 === count( $av ) && str_contains( $av[0], 'texto normal' ), 'un texto gris claro también' );
	$ok( array() === Colores::avisos( array( 'primary' => '#2e7d32' ) ), 'un verde oscuro razonable no da avisos (el texto de encima se ajusta solo)' );

	echo "\n== Parte E · Pantalla de administración ==\n";
	wp_set_current_user( $sin_permiso );
	ob_start();
	( new ColoresPage() )->render();
	$ok( '' === trim( (string) ob_get_clean() ), 'quien no es administrador no ve la pantalla' );
	wp_set_current_user( $admin->ID );
	ob_start();
	( new ColoresPage() )->render();
	$h = (string) ob_get_clean();
	$ok( 12 === substr_count( $h, 'class="asde-color"' ) && str_contains( $h, 'name="colores[primary]"' ) && 6 === substr_count( $h, 'asde-colores__modelo"' ), 'la pantalla trae los 12 selectores de color y los 6 modelos' );
	$ok( str_contains( $h, 'asde-nav__tabs' ) && str_contains( $h, 'asde-notice--warning' ) && str_contains( $h, 'asde-badge--pago-atrasado' ) && str_contains( $h, 'asde-table' ), 'con una vista previa hecha con las clases reales del área (menú, avisos, etiquetas y tabla)' );

	echo "\n== Parte F · Guardar, modelos y restablecer ==\n";
	$enviar( array( 'accion' => 'guardar', 'colores' => array( 'primary' => '#1d5fa8', 'ok' => '', 'text' => '#222222' ) ) );
	$ok( array( 'primary' => '#1d5fa8', 'text' => '#222222' ) === Colores::guardados(), 'guardar desde el formulario deja solo lo elegido' );
	$enviar( array( 'accion' => 'modelo', 'modelo' => 'verde' ) );
	$ok( array( 'primary' => '#2e7d32' ) === Colores::guardados(), 'un modelo sustituye lo anterior por su color principal (el resto sale de él)' );
	$enviar( array( 'accion' => 'modelo', 'modelo' => 'no-existe' ) );
	$ok( array( 'primary' => '#2e7d32' ) === Colores::guardados(), 'un modelo que no existe no cambia nada' );
	$enviar( array( 'accion' => 'restablecer' ) );
	$ok( array() === Colores::guardados() && null === get_option( Colores::OPCION, null ), 'restablecer vuelve a los colores de siempre (borra la opción)' );
	wp_set_current_user( $sin_permiso );
	$cortar = static fn() => static function () {
		throw new RuntimeException( 'wp_die' );
	};
	add_filter( 'wp_die_handler', $cortar );
	$enviar( array( 'accion' => 'guardar', 'colores' => array( 'primary' => '#ff0000' ) ) );
	remove_filter( 'wp_die_handler', $cortar );
	$ok( array() === Colores::guardados(), 'quien no es administrador no puede guardar (se corta antes de tocar nada)' );
	wp_set_current_user( $admin->ID );
	$acciones = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Logger::table() . " WHERE id > %d AND accion LIKE 'colores\\_%%'", $log_antes ) ); // phpcs:ignore WordPress.DB
	$ok( $acciones >= 3, "cada cambio queda en el registro de auditoría ($acciones)" );

	echo "\n== Parte G · Emails ==\n";
	$ok( '#632599' === Colores::para_email()['primary'] && '#f4f1f7' === Colores::para_email()['fondo'] && '#ffffff' === Colores::para_email()['on_primary'], 'por defecto, los emails llevan los colores de siempre' );
	$vars_email = array(
		'tipo'       => 'invitacion',
		'nombre'     => 'Aina',
		'grupo'      => 'Grupo Prueba',
		'login'      => '990001',
		'roles'      => array(),
		'autor'      => '',
		'url'        => 'https://example.invalid/x',
		'dias'       => 2,
		'bienvenida' => false,
	);
	$h0 = TemplateLoader::render( 'email/invitacion', $vars_email );
	Colores::guardar( array( 'primary' => '#2e7d32' ) );
	$h1 = TemplateLoader::render( 'email/invitacion', $vars_email );
	$ok( str_contains( $h0, 'background:#632599' ) && ! str_contains( $h0, '#2e7d32' ) && str_contains( $h1, 'background:#2e7d32' ) && ! str_contains( $h1, '#632599' ), 'el email de invitación usa el color elegido (y ya no el morado)' );
	$ok( str_contains( $h1, 'Aina' ) && str_contains( $h1, 'Grupo Prueba' ), 'y el resto del email sigue igual' );
	Colores::restablecer();
} catch ( Throwable $e ) {
	++$fails;
	echo 'FAIL excepcion: ', $e->getMessage(), ' (', basename( $e->getFile() ), ':', $e->getLine(), ")\n";
} finally {
	remove_filter( 'wp_redirect', $redirigir, 1 );
	wp_set_current_user( 0 );
	$_POST = array();
	if ( null === $antes ) {
		delete_option( Colores::OPCION );
	} else {
		update_option( Colores::OPCION, $antes, false );
	}
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Logger::table() . " WHERE id > %d AND accion LIKE 'colores\\_%%'", $log_antes ) ); // phpcs:ignore WordPress.DB
	require_once ABSPATH . 'wp-admin/includes/user.php';
	wp_delete_user( $sin_permiso );
	$ok( get_option( Colores::OPCION, null ) == $antes && ! get_user_by( 'login', 'prueba-color-sin' ), 'limpieza: la opción de colores, como estaba, y sin usuarios de la prueba' );
}

echo $fails ? "\n$fails FALLOS\n" : "\nTODO OK (el sitio queda como estaba)\n";
