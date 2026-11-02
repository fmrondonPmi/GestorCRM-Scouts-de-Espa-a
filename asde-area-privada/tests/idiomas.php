<?php
/**
 * Pruebas del idioma del área privada (doc 62): los textos usan gettext estándar con el original en inglés y los
 * idiomas son los de WordPress (translate.wordpress.org). Aquí se comprueba el motor que queda:
 * Idioma (cambio de idioma para los emails), asde_guardado() (datos guardados en castellano), los métodos de
 * etiquetas, el diccionario de los JavaScript y que ya no existe el selector ni las etiquetas propias.
 *
 *   wp eval-file <ruta completa>/wp-content/plugins/asde-area-privada/tests/idiomas.php
 *
 * Necesita las traducciones compiladas (herramientas/generar-po.php --mo) y el mu-plugin de desarrollo
 * (herramientas/mu-traducciones-local.php) en el Local. No cambia nada del sitio.
 */
defined( 'ABSPATH' ) || exit;

use AsdeBalears\AreaPrivada\Idiomas\Guardados;
use AsdeBalears\AreaPrivada\Idiomas\Idioma;
use AsdeBalears\AreaPrivada\Idiomas\TextosJs;
use AsdeBalears\AreaPrivada\Tienda\PedidosTable;

const ASDE_T_DOM = 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts';

$fails = 0;
$ok    = function ( $cond, string $label ) use ( &$fails ) {
	if ( ! $cond ) {
		++$fails;
	}
	echo ( $cond ? 'OK   ' : 'FAIL ' ), $label, "\n";
};

try {
	echo "== Parte A · El idioma es el de WordPress ==\n";
	$ok( 'es_ES' === get_locale(), 'el Local está en es_ES (WPLANG)' );
	$ok( 'Entrar' === __( 'Sign in', ASDE_T_DOM ), 'un texto en inglés sale en castellano con las traducciones de es_ES' );
	$ok( 'es-ES' === Idioma::actual(), 'Idioma::actual() da el idioma para el atributo lang' );
	$ok( 'es_ES' === Idioma::por_defecto(), 'Idioma::por_defecto() es el idioma del sitio' );

	echo "\n== Parte B · Cambiar de idioma (emails) ==\n";
	$ca = Idioma::en( 'ca', static fn() => __( 'Save changes', ASDE_T_DOM ) );
	$eu = Idioma::en( 'eu', static fn() => __( 'Save changes', ASDE_T_DOM ) );
	$gl = Idioma::en( 'gl', static fn() => __( 'Save changes', ASDE_T_DOM ) );
	$ok( 'Guardar cambios' === __( 'Save changes', ASDE_T_DOM ), 'en el idioma del sitio: castellano' );
	$ok( '' !== $ca && 'Guardar cambios' !== $ca && 'Save changes' !== $ca, "en catalán otra cosa: «$ca»" );
	$ok( '' !== $eu && 'Guardar cambios' !== $eu && 'Save changes' !== $eu, "en euskera otra cosa: «$eu»" );
	$ok( '' !== $gl && 'Guardar cambios' !== $gl && 'Save changes' !== $gl, "en gallego otra cosa: «$gl»" );
	$ok( 'es_ES' === determine_locale(), 'y al terminar se vuelve al idioma de antes' );
	$ok( 'gl_ES' === Idioma::normalizar( 'gl' ) && 'es_ES' === Idioma::normalizar( 'es' ) && 'eu' === Idioma::normalizar( 'eu' ), 'los códigos de dos letras de las versiones anteriores valen (gl → gl_ES)' );
	$ok( 7 === Idioma::en( 'ca', static fn() => 7 ), 'Idioma::en devuelve lo que devuelve la función' );
	$res = null;
	try {
		Idioma::en( 'ca', static function () {
			throw new RuntimeException( 'fallo' );
		} );
	} catch ( RuntimeException $e ) {
		$res = determine_locale();
	}
	$ok( 'es_ES' === $res, 'si la función falla, el idioma se restaura igualmente' );
	$ok( 'Entrar' === Idioma::en( 'idioma-raro', static fn() => __( 'Sign in', ASDE_T_DOM ) ), 'un código que no es de WordPress se ignora (se queda en el del sitio)' );

	echo "\n== Parte C · Idioma de una persona (destinatario de un email) ==\n";
	$u = wp_insert_user( array( 'user_login' => 'prueba-idioma-1', 'user_pass' => wp_generate_password(), 'user_email' => 'idioma1@example.invalid' ) );
	$ok( ! is_wp_error( $u ), 'usuario de prueba creado' );
	try {
		$ok( 'es_ES' === Idioma::de_usuario( (int) $u ), 'sin idioma propio: el del sitio' );
		Idioma::sugerir( 'ca' );
		$ok( 'ca' === Idioma::de_usuario( (int) $u ), 'sugerido (el de la solicitud que se aprueba): ese' );
		Idioma::sugerir( null );
		$ok( Idioma::elegir( 'eu', (int) $u ) && 'eu' === Idioma::de_usuario( (int) $u ) && 'eu' === get_user_meta( $u, 'locale', true ), 'si la persona tiene idioma en su perfil de WordPress: ese' );
		Idioma::elegir( 'gl', (int) $u );
		$ok( 'gl_ES' === Idioma::de_usuario( (int) $u ), 'y los códigos antiguos se guardan como idioma de WordPress' );
		$ok( ! Idioma::elegir( 'no válido!', (int) $u ) && 'gl_ES' === get_user_meta( $u, 'locale', true ), 'un código mal escrito no se guarda' );
	} finally {
		Idioma::sugerir( null );
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( (int) $u );
	}

	echo "\n== Parte D · Datos guardados en castellano (asde_guardado) ==\n";
	Guardados::olvidar();
	$ok( 'Tesorería' === asde_guardado( 'Tesorería' ), 'en castellano se queda igual' );
	$en = Idioma::en( 'en_US', static fn() => asde_guardado( 'Tesorería' ) );
	$ok( 'Treasury' === $en, "en inglés: «$en»" );
	$ca = Idioma::en( 'ca', static fn() => asde_guardado( 'Tesorería' ) );
	$ok( 'Tresoreria' === $ca, "en catalán: «$ca»" );
	$ok( 'Un texto que escribió una persona' === asde_guardado( 'Un texto que escribió una persona' ), 'un texto que no es de los conocidos se muestra tal cual' );
	$ok( 'Tesorería' === asde_guardado( 'Tesorería' ), 'y tras cambiar de idioma y volver, otra vez en castellano' );

	echo "\n== Parte E · Etiquetas de las constantes (métodos) ==\n";
	$es_estados = PedidosTable::estados();
	$ca_estados = Idioma::en( 'ca', static fn() => PedidosTable::estados() );
	$ok( array_keys( $es_estados ) === array_keys( $ca_estados ), 'mismas claves en cualquier idioma' );
	$ok( $es_estados !== $ca_estados, 'pero las etiquetas cambian de idioma' );
	$ok( array() !== array_filter( $es_estados, static fn( $v ) => is_string( $v ) && '' !== $v ), 'y están rellenas' );

	echo "\n== Parte F · Plurales ==\n";
	$uno    = sprintf( _n( '%d person', '%d people', 1, ASDE_T_DOM ), 1 );
	$varios = sprintf( _n( '%d person', '%d people', 3, ASDE_T_DOM ), 3 );
	$ok( '1 persona' === $uno && '3 personas' === $varios, 'singular y plural en castellano' );

	echo "\n== Parte G · Textos de los JavaScript ==\n";
	$dic = TextosJs::diccionario();
	$ok( count( $dic ) > 40 && 'Copiado' === ( $dic['Copied'] ?? '' ), 'el diccionario va del original en inglés a la traducción' );
	$script = TextosJs::script();
	$ok( str_contains( $script, 'window.asdeI18n=' ) && str_contains( $script, '"Copied":"Copiado"' ), 'window.asdeI18n lleva las traducciones' );
	$ok( str_contains( $script, '%(?:(\\d+)\\$)?s' ), 'asdeT admite marcadores numerados (%1$s)' );
	$js_sin_clave = array();
	foreach ( glob( ASDE_AP_DIR . 'assets/js/*.js' ) as $f ) {
		if ( preg_match_all( '/\basdeT\(\s*\'((?:[^\'\\\\]|\\\\.)*)\'/', (string) file_get_contents( $f ), $m ) ) {
			foreach ( $m[1] as $t ) {
				$t = str_replace( "\\'", "'", $t );
				if ( ! isset( $dic[ $t ] ) ) {
					$js_sin_clave[] = basename( $f ) . ': ' . $t;
				}
			}
		}
	}
	$ok( ! $js_sin_clave, 'todos los asdeT() de los JS están en TextosJs::diccionario()' . ( $js_sin_clave ? ' — faltan: ' . implode( ' | ', array_slice( $js_sin_clave, 0, 4 ) ) : '' ) );

	echo "\n== Parte H · Ya no existe el motor propio ==\n";
	foreach ( array( 'src/Idiomas/Traducciones.php', 'src/Idiomas/Textos.php', 'src/Idiomas/Extractor.php', 'src/Admin/IdiomasPage.php', 'templates/comun/idiomas.php' ) as $f ) {
		// El despliegue con «cp -ru» no borra en el Local lo que se quita del repo: solo se mira si el repo lo tiene.
		$ok( ! file_exists( 'C:/Proyectos/GestorCRM/asde-area-privada/' . $f ), "el repo ya no tiene $f" );
	}
	$ok( ! function_exists( 'asde_t' ) && ! function_exists( 'asde_e' ) && ! function_exists( 'asde_n' ), 'sin asde_t(), asde_e() ni asde_n()' );
	$ok( function_exists( 'asde_guardado' ) && function_exists( 'asde_html_permitido' ), 'quedan asde_guardado() y asde_html_permitido()' );
	$ok( ! method_exists( \AsdeBalears\AreaPrivada\Frontend\Router::class, 'traducir_gettext' ), 'el filtro gettext propio ya no existe' );
	$con_ficheros = array();
	foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( ASDE_AP_DIR, FilesystemIterator::SKIP_DOTS ) ) as $f ) {
		if ( preg_match( '/\.(po|pot|mo)$/', $f->getFilename() ) ) {
			$con_ficheros[] = $f->getFilename();
		}
	}
	$ok( ! $con_ficheros, 'el paquete del plugin no lleva ficheros de traducción (los pone translate.wordpress.org)' . ( $con_ficheros ? ': ' . implode( ', ', $con_ficheros ) : '' ) );

		echo "\n== Parte E · Idioma fijo en castellano (v0.60.1) ==\n";
		$ok( ! Idioma::fijo_activo() && 'Entrar' === __( 'Sign in', ASDE_T_DOM ), 'sin languages/*.mo en el paquete (WordPress.org) se sigue el idioma de WordPress' );
		$carpeta = ASDE_AP_DIR . 'languages/';
		$mo      = $carpeta . ASDE_T_DOM . '-es_ES.mo';
		$creada  = ! is_dir( $carpeta );
		if ( $creada ) {
			mkdir( $carpeta );
		}
		copy( 'C:/Proyectos/GestorCRM/traducciones/mo/' . ASDE_T_DOM . '-es_ES.mo', $mo );
		$en_ingles = static fn() => 'en_US';
		try {
			$ok( Idioma::fijo_activo() && 'es_ES' === Idioma::fijo(), 'con el .mo del castellano en el paquete, el idioma fijo está activo' );
			// Aunque el sitio esté en otro idioma, el área sale en castellano.
			Idioma::registrar(); // Como al cargar el plugin: engancha los filtros y carga el .mo del paquete.
			add_filter( 'locale', $en_ingles, 99 );
			unload_textdomain( ASDE_T_DOM ); // Con idioma fijo no descarga.
			$ok( 'Entrar' === __( 'Sign in', ASDE_T_DOM ) && 'es-ES' === Idioma::actual(), 'con el sitio en inglés, el área sale en castellano' );
			$ca = Idioma::en( 'ca', static fn() => __( 'Sign in', ASDE_T_DOM ) );
			$ok( 'Entrar' === $ca, 'un email «en catalán» sale en castellano (no se cambia de idioma)' );
			$ok( 'es_ES' === Idioma::de_usuario( 0 ), 'el idioma de un destinatario es el fijo' );
			add_filter( 'asde_ap_idioma_fijo', '__return_empty_string' );
			$ok( '' === Idioma::fijo() && ! Idioma::fijo_activo(), 'con el filtro asde_ap_idioma_fijo a vacío, se vuelve al idioma de WordPress' );
			remove_filter( 'asde_ap_idioma_fijo', '__return_empty_string' );
		} finally {
			remove_filter( 'locale', $en_ingles, 99 );
			remove_filter( 'asde_ap_idioma_fijo', '__return_empty_string' );
			unlink( $mo );
			remove_filter( 'override_load_textdomain', array( Idioma::class, 'cargar_fijo' ), 10 );
			remove_filter( 'override_unload_textdomain', array( Idioma::class, 'no_descargar' ), 10 );
			if ( $creada ) {
				rmdir( $carpeta );
			}
			unload_textdomain( ASDE_T_DOM );
			load_textdomain( ASDE_T_DOM, 'C:/Proyectos/GestorCRM/traducciones/mo/' . ASDE_T_DOM . '-es_ES.mo' ); // Como estaba (el Local carga el .mo del repo).
		}
		$ok( ! Idioma::fijo_activo() && 'Entrar' === __( 'Sign in', ASDE_T_DOM ), 'al quitar el .mo del paquete, vuelve el comportamiento normal' );
} catch ( Throwable $e ) {
	++$fails;
	echo 'FAIL excepcion: ', $e->getMessage(), ' (', basename( $e->getFile() ), ':', $e->getLine(), ")\n";
} finally {
	Idioma::forzar( null );
	Idioma::sugerir( null );
	Guardados::olvidar();
}

echo $fails ? "\n$fails FALLOS\n" : "\nTODO OK (el sitio queda como estaba)\n";
