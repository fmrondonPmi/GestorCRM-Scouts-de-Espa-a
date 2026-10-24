<?php
/**
 * Pruebas de la v0.15 (entorno LOCAL): idiomas y gestión de etiquetas (motor y pantallas).
 * Ejecutar desde app/public del site Local:
 *
 *   wp eval-file <ruta completa>/wp-content/plugins/asde-area-privada/tests/idiomas.php
 *
 * Los catálogos de prueba se inyectan con los filtros asde_ap_catalogo y asde_ap_textos (no se
 * tocan los ficheros de languages/). Al terminar se restauran las opciones.
 */
defined( 'ABSPATH' ) || exit;

require_once ABSPATH . 'wp-admin/includes/user.php';

use AsdeBalears\AreaPrivada\Admin\IdiomasPage;
use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Frontend\Router;
use AsdeBalears\AreaPrivada\Frontend\TemplateLoader;
use AsdeBalears\AreaPrivada\Idiomas\Idioma;
use AsdeBalears\AreaPrivada\Idiomas\Traducciones;

global $wpdb;

$fails = 0;
$ok    = function ( $cond, string $label ) use ( &$fails ) {
	if ( ! $cond ) {
		++$fails;
	}
	echo ( $cond ? 'OK   ' : 'FAIL ' ), $label, "\n";
};

$ajustes_antes   = get_option( Idioma::OPCION, null );
$etiquetas_antes = get_option( Traducciones::OPCION, null );
$log_antes       = (int) $wpdb->get_var( 'SELECT COALESCE(MAX(id), 0) FROM ' . Logger::table() ); // phpcs:ignore WordPress.DB
$admin           = get_users( array( 'role' => 'administrator', 'number' => 1 ) )[0] ?? null;
if ( ! $admin ) {
	exit( "Hace falta al menos un administrador en el sitio.\n" );
}
$u_ca = wp_insert_user( array( 'user_login' => 'prueba-idioma-ca', 'user_email' => '', 'user_pass' => wp_generate_password( 24 ), 'role' => 'subscriber' ) );
$u_sin = wp_insert_user( array( 'user_login' => 'prueba-idioma-sin', 'user_email' => '', 'user_pass' => wp_generate_password( 24 ), 'role' => 'subscriber' ) );

// Textos y catálogos de prueba (inyectados por filtro).
// Sustituyen a los reales (languages/): así la prueba no depende de lo que traigan los catálogos del plugin.
$f_textos = static fn( array $t ) => array(
	'Mis datos'                    => 'Menú',
	'Pedido %s enviado: %s.'      => 'Uniformes',
	'Tu <strong>pedido</strong>'  => 'Uniformes',
	'Guardar'                      => 'Común',
	'Texto sin traducir en euskera' => 'Común',
);
$f_cat    = static function ( array $c, string $idioma ) {
	$tr = array(
		'ca' => array( 'Mis datos' => 'Les meves dades', 'Pedido %s enviado: %s.' => 'Comanda %s enviada: %s.', 'Tu <strong>pedido</strong>' => 'La teva <strong>comanda</strong>', 'Guardar' => 'Desa' ),
		'eu' => array( 'Mis datos' => 'Nire datuak', 'Guardar' => 'Gorde' ),
		'gl' => array( 'Mis datos' => 'Os meus datos', 'Guardar' => 'Gardar' ),
	);
	return $tr[ $idioma ] ?? array();
};
add_filter( 'asde_ap_textos', $f_textos );
add_filter( 'asde_ap_catalogo', $f_cat, 10, 2 );
Traducciones::olvidar();

$redirigir = static function ( $destino ) {
	throw new RuntimeException( 'asde-redirect:' . $destino );
};
add_filter( 'wp_redirect', $redirigir, 1 );
$enviar = static function ( array $post ): void {
	$_POST    = $post + array( '_wpnonce' => wp_create_nonce( IdiomasPage::ACCION ) );
	$_REQUEST = $_POST;
	try {
		( new IdiomasPage() )->handle();
	} catch ( RuntimeException $e ) {
		// Redirige al terminar.
	} finally {
		$_POST    = array();
		$_REQUEST = array();
	}
};

try {
	delete_option( Idioma::OPCION );
	delete_option( Traducciones::OPCION );
	Traducciones::olvidar();
	Idioma::forzar( null );
	wp_set_current_user( 0 );

	echo "== Parte A · Qué idioma toca ==\n";
	$ok( array( 'es', 'ca', 'eu', 'gl' ) === array_keys( Idioma::disponibles() ) && 'Català' === Idioma::nombre( 'ca' ) && 'Euskara' === Idioma::nombre( 'eu' ) && 'Galego' === Idioma::nombre( 'gl' ), 'los idiomas oficiales de España con lengua propia: castellano, catalán, euskera y gallego (con su nombre en su idioma)' );
	$ok( Idioma::es_valido( 'ca' ) && ! Idioma::es_valido( 'fr' ) && ! Idioma::es_valido( null ) && ! Idioma::es_valido( array() ), 'solo valen esos cuatro códigos' );
	$ok( 'es' === Idioma::por_defecto() && Idioma::se_puede_elegir() && 'es' === Idioma::actual(), 'sin configurar: castellano y cada persona puede elegir' );
	$ok( ! Idioma::guardar_ajustes( 'fr', true ) && 'es' === Idioma::por_defecto() && Idioma::guardar_ajustes( 'ca', true ) && 'ca' === Idioma::por_defecto() && 'ca' === Idioma::actual(), 'se guarda el idioma por defecto (y uno que no existe se rechaza)' );
	Idioma::elegir( 'eu', $u_ca, false );
	wp_set_current_user( $u_ca );
	Idioma::olvidar();
	$ok( 'eu' === Idioma::actual() && 'eu' === get_user_meta( $u_ca, Idioma::META, true ), 'quien ha elegido idioma lo ve en el suyo (se guarda en su usuario), aunque el del sitio sea otro' );
	wp_set_current_user( $u_sin );
	Idioma::olvidar();
	$ok( 'ca' === Idioma::actual(), 'otra persona que no ha elegido ve el del sitio' );
	$_COOKIE[ Idioma::COOKIE ] = 'gl';
	Idioma::olvidar();
	$ok( 'gl' === Idioma::actual(), 'sin usuario o sin elección, vale la cookie (pantallas de acceso y de solicitud)' );
	$_COOKIE[ Idioma::COOKIE ] = 'fr';
	Idioma::olvidar();
	$ok( 'ca' === Idioma::actual(), 'una cookie con un idioma que no existe no vale' );
	unset( $_COOKIE[ Idioma::COOKIE ] );
	Idioma::forzar( 'gl' );
	wp_set_current_user( $u_ca );
	$ok( 'gl' === Idioma::actual() && 'es' === Idioma::en( 'es', static fn() => Idioma::actual() ) && 'gl' === Idioma::actual(), 'se puede forzar un idioma (emails, pruebas) y volver al anterior' );
	Idioma::forzar( null );
	Idioma::guardar_ajustes( 'ca', false );
	Idioma::olvidar();
	$ok( 'ca' === Idioma::actual() && ! Idioma::elegir( 'gl', $u_ca, false ), 'si la administración no deja elegir, vale siempre el del sitio y no se puede cambiar' );
	Idioma::guardar_ajustes( 'es', true );
	wp_set_current_user( 0 );
	Idioma::olvidar();

	echo "\n== Parte B · Traducciones y etiquetas personalizadas ==\n";
	$ok( 'Mis datos' === Traducciones::t( 'Mis datos' ) && 'Les meves dades' === Traducciones::t( 'Mis datos', 'ca' ) && 'Nire datuak' === Traducciones::t( 'Mis datos', 'eu' ) && 'Os meus datos' === Traducciones::t( 'Mis datos', 'gl' ), 'cada idioma da su traducción; el castellano, el propio texto' );
	$ok( 'Texto sin traducir en euskera' === Traducciones::t( 'Texto sin traducir en euskera', 'eu' ) && 'No existe esto' === Traducciones::t( 'No existe esto', 'ca' ), 'lo que no tiene traducción se queda en castellano (nunca sale vacío ni da error)' );
	$ok( 'Comanda 7 enviada: 25,00 €.' === sprintf( Traducciones::t( 'Pedido %s enviado: %s.', 'ca' ), '7', '25,00 €' ), 'los textos con marcadores se rellenan con sprintf' );
	$n = Traducciones::guardar( 'ca', array( 'Mis datos' => 'Les meves fitxes' ) );
	$ok( 1 === $n && 'Les meves fitxes' === Traducciones::t( 'Mis datos', 'ca' ) && 'Nire datuak' === Traducciones::t( 'Mis datos', 'eu' ), 'una etiqueta personalizada manda sobre el catálogo y solo en su idioma' );
	Traducciones::guardar( 'es', array( 'Mis datos' => 'Mi ficha' ) );
	$ok( 'Mi ficha' === Traducciones::t( 'Mis datos', 'es' ), 'también se puede personalizar el castellano' );
	Traducciones::guardar( 'ca', array( 'Mis datos' => '' ) );
	$ok( 'Les meves dades' === Traducciones::t( 'Mis datos', 'ca' ) && empty( Traducciones::personalizadas()['ca'] ), 'dejarla vacía vuelve a la del plugin' );
	Traducciones::guardar( 'ca', array( 'Mis datos' => 'Les meves dades' ) );
	$ok( empty( Traducciones::personalizadas()['ca'] ), 'un texto igual a la traducción del plugin no se guarda como personalizado' );
	Traducciones::guardar( 'ca', array( 'Pedido %s enviado: %s.' => 'Comanda enviada' ) );
	$ok( empty( Traducciones::personalizadas()['ca'] ), 'una traducción que pierde los marcadores %s se rechaza (reventaría el texto)' );
	Traducciones::guardar( 'ca', array( 'Pedido %s enviado: %s.' => 'Hem rebut la comanda %2$s (%1$s)' ) );
	$ok( empty( Traducciones::personalizadas()['ca'] ), 'y una que cambia el tipo de marcadores, también' );
	Traducciones::guardar( 'ca', array( 'Pedido %s enviado: %s.' => 'Enviada: %s — comanda %s' ) );
	$ok( 'Enviada: %s — comanda %s' === Traducciones::t( 'Pedido %s enviado: %s.', 'ca' ), 'pero se pueden cambiar de sitio' );
	Traducciones::restablecer( 'ca' );
	Traducciones::guardar( 'ca', array( 'Guardar' => 'Desa <script>alert(1)</script><strong>ara</strong>' ) );
	$ok( 'Desa alert(1)<strong>ara</strong>' === Traducciones::t( 'Guardar', 'ca' ), 'se limpian las etiquetas HTML no permitidas (nada de scripts)' );
	$ok( 'Desa alert(1)<strong>ara</strong>' === Traducciones::h( 'Guardar', 'ca' ), 'para HTML solo sale lo permitido' );
	Traducciones::restablecer();
	$ok( null === get_option( Traducciones::OPCION, null ) && 'Desa' === Traducciones::t( 'Guardar', 'ca' ), 'restablecer del todo borra la opción y vuelve al catálogo' );
	$ok( ! Traducciones::mismos_marcadores( 'a %s b %d', 'a %s' ) && Traducciones::mismos_marcadores( '100%% %s', '100%% %s' ) && Traducciones::mismos_marcadores( '%1$s y %2$s', '%2$s y %1$s' ), 'la comprobación de marcadores (también %%, %1$s y el orden)' );
	Traducciones::guardar( 'eu', array( 'Guardar' => 'Biltegiratu' ) );
	Traducciones::guardar( 'gl', array( 'Mis datos' => 'As miñas datas' ) );
	$json = Traducciones::exportar();
	Traducciones::restablecer();
	$r = Traducciones::importar( $json );
	$ok( 2 === $r && 'Biltegiratu' === Traducciones::t( 'Guardar', 'eu' ) && 'As miñas datas' === Traducciones::t( 'Mis datos', 'gl' ), 'exportar e importar: las etiquetas viajan en un JSON y vuelven iguales' );
	$ok( is_wp_error( Traducciones::importar( 'esto no es json' ) ) && is_wp_error( Traducciones::importar( '{"otra":"cosa"}' ) ) && 2 === array_sum( array_map( 'count', Traducciones::personalizadas() ) ), 'un fichero que no es una exportación se rechaza sin tocar lo que hay' );
	Traducciones::importar( '{"etiquetas":{"fr":{"a":"b"},"ca":{"Guardar":"Desa-ho","":"x","Mis datos":""}}}' );
	$ok( array( 'ca' => array( 'Guardar' => 'Desa-ho' ) ) === Traducciones::personalizadas(), 'al importar se descartan idiomas que no existen, claves vacías y textos vacíos' );
	Traducciones::restablecer();

	echo "\n== Parte C · Funciones asde_t / asde_h / asde_e / asde_a ==\n";
	Idioma::forzar( 'ca' );
	$ok( 'Les meves dades' === asde_t( 'Mis datos' ) && 'La teva <strong>comanda</strong>' === asde_h( 'Tu <strong>pedido</strong>' ), 'con catalán forzado, asde_t y asde_h dan el catalán' );
	ob_start();
	asde_e( 'Guardar' );
	$ok( 'Desa' === ob_get_clean() && 'Desa' === asde_a( 'Guardar' ) && 'a&quot;b' === ( Traducciones::guardar( 'ca', array( 'Guardar' => 'a"b' ) ) ? asde_a( 'Guardar' ) : '' ), 'asde_e imprime y asde_a escapa para atributos' );
	Traducciones::restablecer();
	Idioma::forzar( 'es' );
	$ok( 'Tu <strong>pedido</strong>' === asde_h( 'Tu <strong>pedido</strong>' ) && 'Mis datos' === asde_t( 'Mis datos' ), 'en castellano, los textos salen tal cual' );
	Idioma::forzar( null );

	echo "\n== Parte D · El área: lang y selector ==\n";
	$router = new Router();
	Idioma::forzar( 'eu' );
	$h = $router->marcar_idioma( '<div class="asde-ap"><p>x</p></div>', 'asde_area_privada' );
	$ok( str_starts_with( $h, '<div lang="eu" class="asde-ap">' ), 'el contenedor del área lleva lang con el idioma de la persona' );
	$ok( '<p>otro</p>' === $router->marcar_idioma( '<p>otro</p>', 'shortcode_ajeno' ) && 7 === $router->marcar_idioma( 7, 'asde_area_privada' ), 'los shortcodes que no son del área no se tocan' );
	$p = $router->marcar_idioma( '<div class="asde-ap">acceso</div>', 'asde_acceso' );
	$ok( str_starts_with( $p, '<div class="asde-ap asde-ap--idiomas" lang="eu">' ) && str_contains( $p, 'asde-idiomas' ) && str_ends_with( $p, '<div lang="eu" class="asde-ap">acceso</div>' ), 'las pantallas públicas (acceso, activar, recuperar, solicitar) llevan el selector de idioma' );
	$ok( ! str_contains( $h, 'asde-idiomas' ), 'las demás, no (el selector va en su menú)' );
	$sel = TemplateLoader::render( 'comun/idiomas' );
	$ok( 3 === substr_count( $sel, '<a ' ) && 1 === substr_count( $sel, '<strong ' ) && str_contains( $sel, '<strong lang="eu" aria-current="true">Euskara</strong>' ) && str_contains( $sel, '>Català</a>' ) && str_contains( $sel, 'asde_lang=ca' ) && str_contains( $sel, '_wpnonce=' ), 'el selector: tres enlaces con nonce y el idioma actual marcado' );
	Idioma::guardar_ajustes( 'es', false );
	Idioma::forzar( null );
	$ok( '' === trim( TemplateLoader::render( 'comun/idiomas' ) ) && ! str_contains( $router->marcar_idioma( '<div class="asde-ap">a</div>', 'asde_acceso' ), 'asde-idiomas' ), 'si no se deja elegir, no hay selector' );
	Idioma::guardar_ajustes( 'es', true );

	echo "\n== Parte E · Cambiar de idioma con enlace ==\n";
	wp_set_current_user( $u_sin );
	$_GET = array( Idioma::PARAM => 'gl', '_wpnonce' => wp_create_nonce( Idioma::NONCE ) );
	$ok( Idioma::atender_peticion() && 'gl' === get_user_meta( $u_sin, Idioma::META, true ), 'el enlace con nonce cambia el idioma y lo guarda en el usuario' );
	$_GET = array( Idioma::PARAM => 'ca', '_wpnonce' => 'falso' );
	$ok( ! Idioma::atender_peticion() && 'gl' === get_user_meta( $u_sin, Idioma::META, true ), 'sin un nonce válido no cambia nada (es un ajuste personal: nada de cambios por un enlace ajeno)' );
	$_GET = array( Idioma::PARAM => 'fr', '_wpnonce' => wp_create_nonce( Idioma::NONCE ) );
	$ok( ! Idioma::atender_peticion(), 'un idioma que no existe, tampoco' );
	$_GET = array();
	$url = Idioma::url_cambio( 'ca', 'https://example.invalid/area/?x=1' );
	$ok( str_contains( $url, 'x=1' ) && str_contains( $url, 'asde_lang=ca' ) && str_contains( $url, '_wpnonce=' ), 'la dirección de cambio conserva la página y lo que llevara' );
	wp_set_current_user( 0 );

	echo "\n== Parte F · gettext: lo que ya llevaba __() ==\n";
	Idioma::forzar( 'ca' );
	$ok( 'Les meves dades' === $router->traducir_gettext( 'Mis datos', 'Mis datos', 'asde-area-privada' ) && 'X' === $router->traducir_gettext( 'X', 'Otro texto', 'asde-area-privada' ) && 'Y' === $router->traducir_gettext( 'Y', 'Mis datos', 'otro-plugin' ), 'los textos con __() del plugin se traducen; los de otro dominio y los que no tienen traducción, no' );
	Idioma::forzar( null );

	echo "\n== Parte G · Pantalla de administración ==\n";
	wp_set_current_user( $u_sin );
	ob_start();
	( new IdiomasPage() )->render();
	$ok( '' === trim( (string) ob_get_clean() ), 'quien no es administrador no ve la pantalla' );
	wp_set_current_user( $admin->ID );
	$_GET = array( 'idioma' => 'eu' );
	ob_start();
	( new IdiomasPage() )->render();
	$h = (string) ob_get_clean();
	$_GET = array();
	$ok( str_contains( $h, '<option value="es"' ) && str_contains( $h, '<option value="ca"' ) && str_contains( $h, '<option value="eu"' ) && str_contains( $h, '<option value="gl"' ) && str_contains( $h, 'name="por_defecto"' ) && str_contains( $h, 'name="elegir"' ), 'la pantalla trae el idioma por defecto (cuatro idiomas) y la casilla de elegir' );
	$ok( 4 === substr_count( $h, '<a class="nav-tab' ) && str_contains( $h, 'Castellano' ) && str_contains( $h, 'Català' ) && str_contains( $h, 'Euskara' ) && str_contains( $h, 'Galego' ), 'con una pestaña por idioma' );
	$ok( str_contains( $h, 'name="et[0][clave]"' ) && str_contains( $h, 'Nire datuak' ) && str_contains( $h, 'sin traducir: se ve en castellano' ), 'y la tabla de etiquetas: original, traducción del plugin y un campo para el texto propio' );
	$l = IdiomasPage::listado( 'eu', '', 'sin_traducir', 1 );
	$ok( 3 === $l['total'] && array( 'Pedido %s enviado: %s.', 'Tu <strong>pedido</strong>', 'Texto sin traducir en euskera' ) === array_column( $l['filas'], 'clave' ), 'el filtro «sin traducción» saca solo lo que falta en euskera' );
	$l = IdiomasPage::listado( 'ca', 'comanda', 'todas', 1 );
	$ok( 2 === $l['total'], 'la búsqueda mira también en la traducción (comanda)' );
	$l = IdiomasPage::listado( 'es', '', 'sin_traducir', 1 );
	$ok( 0 === $l['total'], 'en castellano no hay nada «sin traducir»' );
	$cuatro = IdiomasPage::listado( 'ca', '', 'todas', 1 );
	$ok( 5 === $cuatro['total'] && 1 === $cuatro['paginas'], 'la paginación' );

	echo "\n== Parte H · Guardar desde la pantalla ==\n";
	$enviar( array( 'accion' => 'ajustes', 'por_defecto' => 'gl' ) );
	$ok( 'gl' === Idioma::por_defecto() && ! Idioma::se_puede_elegir(), 'guardar ajustes: el idioma por defecto y, sin marcar la casilla, que nadie elige' );
	$enviar( array( 'accion' => 'ajustes', 'por_defecto' => 'fr', 'elegir' => '1' ) );
	$ok( 'gl' === Idioma::por_defecto(), 'un idioma que no existe no se guarda' );
	$enviar( array( 'accion' => 'ajustes', 'por_defecto' => 'es', 'elegir' => '1' ) );
	$enviar( array( 'accion' => 'etiquetas', 'idioma' => 'ca', 'et' => array(
		array( 'clave' => 'Mis datos', 'texto' => 'Fitxa personal' ),
		array( 'clave' => 'Pedido %s enviado: %s.', 'texto' => 'Comanda enviada' ),
		array( 'clave' => 'No es una clave conocida', 'texto' => 'x' ),
		array( 'clave' => 'Guardar', 'texto' => '' ),
	) ) );
	$ok( array( 'Mis datos' => 'Fitxa personal' ) === ( Traducciones::personalizadas()['ca'] ?? null ), 'guardar etiquetas: se guarda lo bueno; lo que pierde marcadores, lo que no es una clave conocida y lo vacío, no' );
	$enviar( array( 'accion' => 'restablecer', 'idioma' => 'ca' ) );
	$ok( ! empty( Traducciones::personalizadas()['ca'] ), 'quitar las personalizadas pide confirmación' );
	$enviar( array( 'accion' => 'restablecer', 'idioma' => 'ca', 'confirmar' => '1' ) );
	$ok( empty( Traducciones::personalizadas()['ca'] ), 'y con ella las quita' );
	wp_set_current_user( $u_sin );
	$cortar = static fn() => static function () {
		throw new RuntimeException( 'wp_die' );
	};
	add_filter( 'wp_die_handler', $cortar );
	$enviar( array( 'accion' => 'etiquetas', 'idioma' => 'ca', 'et' => array( array( 'clave' => 'Guardar', 'texto' => 'Hackeado' ) ) ) );
	remove_filter( 'wp_die_handler', $cortar );
	$ok( empty( Traducciones::personalizadas()['ca'] ), 'quien no es administrador no puede guardar etiquetas' );
	wp_set_current_user( $admin->ID );
	$acciones = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Logger::table() . " WHERE id > %d AND ( accion LIKE 'idioma\\_%%' OR accion LIKE 'etiquetas\\_%%' )", $log_antes ) ); // phpcs:ignore WordPress.DB
	$ok( $acciones >= 4, "cada cambio queda en la auditoría ($acciones)" );
} catch ( Throwable $e ) {
	++$fails;
	echo 'FAIL excepcion: ', $e->getMessage(), ' (', basename( $e->getFile() ), ':', $e->getLine(), ")\n";
} finally {
	remove_filter( 'wp_redirect', $redirigir, 1 );
	remove_filter( 'asde_ap_textos', $f_textos );
	remove_filter( 'asde_ap_catalogo', $f_cat, 10 );
	Idioma::forzar( null );
	wp_set_current_user( 0 );
	$_POST = array();
	$_GET  = array();
	unset( $_COOKIE[ Idioma::COOKIE ] );
	null === $ajustes_antes ? delete_option( Idioma::OPCION ) : update_option( Idioma::OPCION, $ajustes_antes, false );
	null === $etiquetas_antes ? delete_option( Traducciones::OPCION ) : update_option( Traducciones::OPCION, $etiquetas_antes, false );
	Traducciones::olvidar();
	Idioma::olvidar();
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Logger::table() . " WHERE id > %d AND ( accion LIKE 'idioma\\_%%' OR accion LIKE 'etiquetas\\_%%' )", $log_antes ) ); // phpcs:ignore WordPress.DB
	wp_delete_user( $u_ca );
	wp_delete_user( $u_sin );
	$quedan = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users} WHERE user_login LIKE 'prueba-idioma-%'" ); // phpcs:ignore WordPress.DB
	$ok( 0 === $quedan && get_option( Idioma::OPCION, null ) == $ajustes_antes && get_option( Traducciones::OPCION, null ) == $etiquetas_antes, 'limpieza: opciones como estaban y sin usuarios de la prueba' );
}

echo $fails ? "\n$fails FALLOS\n" : "\nTODO OK (el sitio queda como estaba)\n";
