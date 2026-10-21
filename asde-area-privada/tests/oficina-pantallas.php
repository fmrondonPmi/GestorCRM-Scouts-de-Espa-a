<?php
/**
 * Pruebas de la v0.50 (entorno LOCAL, doc 50, fase 4): pantallas de la Oficina.
 * Ejecutar desde app/public del site Local:
 *
 *   wp eval-file <ruta completa>/wp-content/plugins/asde-area-privada/tests/oficina-pantallas.php
 *
 * No toca el CRM. Crea el usuario `prueba-op-oficina` (y `prueba-op-scouter`) y los borra; restaura el tipo de instalación.
 */
defined( 'ABSPATH' ) || exit;

require_once ABSPATH . 'wp-admin/includes/user.php';

use AsdeBalears\AreaPrivada\Auth\Ambito;
use AsdeBalears\AreaPrivada\Auth\SoloLectura;
use AsdeBalears\AreaPrivada\Domain\Oficina;
use AsdeBalears\AreaPrivada\Domain\Organizacion;
use AsdeBalears\AreaPrivada\Fichas\PermisosFicha;
use AsdeBalears\AreaPrivada\Frontend\Menu;
use AsdeBalears\AreaPrivada\Frontend\Pages;
use AsdeBalears\AreaPrivada\Frontend\Router;
use AsdeBalears\AreaPrivada\Informes\Catalogo;
use AsdeBalears\AreaPrivada\Install\Roles;

$fails = 0;
$ok    = function ( $cond, string $label ) use ( &$fails ) {
	if ( ! $cond ) {
		++$fails;
	}
	echo ( $cond ? 'OK   ' : 'FAIL ' ), $label, "\n";
};
$leer = static fn( string $f ): string => (string) file_get_contents( ASDE_AP_DIR . $f );

$antes_tipo   = get_option( Organizacion::TIPO_OPCION, null );
$antes_actual = get_current_user_id();
$uids         = array();
$crear        = static function ( string $login, string $rol ) use ( &$uids ): int {
	$viejo = get_user_by( 'login', $login );
	if ( $viejo ) {
		wp_delete_user( $viejo->ID );
	}
	$id     = wp_insert_user( array( 'user_login' => $login, 'user_pass' => wp_generate_password( 24 ), 'user_email' => $login . '@example.invalid', 'role' => $rol ) );
	$uids[] = (int) $id;
	return (int) $id;
};

try {
	Roles::install();
	Organizacion::guardar_tipo( Organizacion::TIPO_OFICINA );
	$of = $crear( 'prueba-op-oficina', Roles::OFICINA );

	echo "== A. Qué pantallas abre la oficina ==\n";
	$ok( array( 'area', 'personas', 'ficha', 'informes', 'usuarios', 'seguridad' ) === Oficina::PAGINAS, 'La oficina solo abre Inicio, Personas, la ficha, Informes, Usuarios y Seguridad' );
	$vistas = ( new ReflectionClassConstant( Router::class, 'VIEWS' ) )->getValue();
	foreach ( array( 'tesoreria', 'banco', 'sepa', 'cuotas', 'familias', 'federacion', 'tienda_gestion', 'tienda_config', 'tienda_proveedor', 'inventario', 'eventos', 'lista_espera', 'reintegros', 'cambios', 'solicitudes', 'alta', 'emails', 'pagos', 'mis_eventos' ) as $k ) {
		$ok( ! in_array( $k, Oficina::PAGINAS, true ) && isset( $vistas[ $k ] ), 'No abre «' . $k . '»' );
	}
	$router = $leer( 'src/Frontend/Router.php' );
	$ok( str_contains( $router, 'Oficina::PAGINAS' ) && strpos( $router, 'Oficina::PAGINAS' ) < strpos( $router, 'puede_acceder()' ), 'El Router comprueba la lista de pantallas de la oficina antes de dejar entrar' );

	echo "\n== B. Menú ==\n";
	wp_set_current_user( $of );
	Ambito::olvidar( $of );
	$a     = Ambito::de( get_userdata( $of ) );
	$items = array();
	foreach ( Menu::grupos( $a ) as $g ) {
		$items += $g['items'];
	}
	$claves = array_keys( $items );
	sort( $claves );
	$ok( array( 'informes', 'personas', 'usuarios' ) === $claves, 'El menú de la oficina solo tiene Personas, Informes y Usuarios (' . implode( ', ', $claves ) . ')' );
	$ok( true === $a->vision_global() && true === $a->es_oficina(), 'Ve a todo el grupo elegido (visión global) y es oficina' );

	echo "\n== C. Informes ==\n";
	$cols = Catalogo::permitidas( $a );
	$niveles = array_unique( array_map( static fn( $c ) => $c[4], $cols ) );
	sort( $niveles );
	$ok( ! in_array( 'pagos', $niveles, true ) && in_array( 'salud', $niveles, true ) && in_array( 'global', $niveles, true ) && in_array( 'todos', $niveles, true ), 'Informes: niveles todos, salud y global; nunca pagos (' . implode( ', ', $niveles ) . ')' );
	$con_iban = array_filter( array_keys( $cols ), static fn( $k ) => str_contains( (string) $k, 'iban' ) || str_contains( strtolower( (string) $cols[ $k ][0] ), 'iban' ) );
	$ok( ! $con_iban, 'Ninguna columna de informe lleva el IBAN' );
	$ok( false === Catalogo::ve_pagos( $a ), 'La oficina no ve la cuota ni los pagos' );

	echo "\n== D. Fichas ==\n";
	$ficha = $leer( 'src/Frontend/Views/FichaView.php' );
	$ok( str_contains( $ficha, "'oficina_ficha_consultada'" ), 'Cada ficha que abre la oficina se audita' );
	$ok( str_contains( $leer( 'src/Fichas/PermisosFicha.php' ), 'SoloLectura::activo()' ), 'La ficha de la oficina nunca ofrece editar' );
	$ok( ! str_contains( $leer( 'src/Frontend/Views/FichaView.php' ), 'iban' ) || str_contains( $ficha, '$tesoreria' ), 'Los datos bancarios de la ficha solo los ven Tesorería y la familia' );

	$ok( 'oficina' === \AsdeBalears\AreaPrivada\Frontend\Ayuda::manual_de( array( Roles::OFICINA ), false ) && is_file( ASDE_AP_DIR . 'ayuda/oficina/personas.html' ) && is_file( ASDE_AP_DIR . 'ayuda/oficina/informes.html' ), 'La oficina tiene su propio manual y su ayuda por página' );

	echo "\n== E. Sin botones de acción ==\n";
	$html = '<div><a href="https://x.example/area/alta/?socio=1" data-asde-modal>Dar acceso</a>'
		. '<p><a href="/area/personas/?socio=7">Ver ficha</a></p>'
		. '<form method="post" action="/area/personas/"><input type="hidden" name="asde_action" value="eliminar"><button>Eliminar</button></form>'
		. "<form method='POST'><button>Otro</button></form>"
		. '<form method="get" action="/area/personas/"><input name="buscar"><button>Filtrar</button></form>'
		. '<a href="/area/ficha/?socio=7&amp;membresia=baja" data-asde-modal>Dar de baja</a>'
		. '<a class="button" href="/area/personas/?alta=1">Alta de socio/a</a></div>';
	$lim  = SoloLectura::limpiar( $html );
	$ok( ! str_contains( $lim, 'Eliminar' ) && ! str_contains( $lim, 'Otro' ) && ! str_contains( $lim, 'method="post"' ), 'Se quitan los formularios de envío' );
	$ok( str_contains( $lim, 'Filtrar' ) && str_contains( $lim, 'Ver ficha' ), 'Se conservan los filtros (GET) y los enlaces de consulta' );
	$ok( ! str_contains( $lim, 'Dar de baja' ) && ! str_contains( $lim, 'Alta de socio/a' ), 'Se quitan los enlaces de membresía y de alta' );
	$editor = '<form method="post" action="/area/informes/"><input type="hidden" name="asde_action" value="guardar"><input name="nombre"><button type="submit" class="button">Guardar</button><button type="submit" class="button" formmethod="get" name="ver" value="1">Ver el resultado</button></form>';
	$lim2   = SoloLectura::limpiar( $editor );
	$ok( str_contains( $lim2, 'Ver el resultado' ) && ! str_contains( $lim2, '>Guardar<' ) && ! str_contains( $lim2, 'asde_action' ), 'El editor de informes se queda para consultar («Ver el resultado»), sin «Guardar»' );
	$view = $leer( 'src/Frontend/Views/View.php' );
	$ok( str_contains( $view, 'SoloLectura::limpiar( $contenido )' ), 'Todas las pantallas pasan por la limpieza en el envoltorio común' );
} finally {
	wp_set_current_user( $antes_actual );
	foreach ( $uids as $u ) {
		if ( $u > 0 ) {
			wp_delete_user( $u );
		}
	}
	if ( null === $antes_tipo ) {
		delete_option( Organizacion::TIPO_OPCION );
	} else {
		update_option( Organizacion::TIPO_OPCION, $antes_tipo, false );
	}
	Ambito::olvidar( 0 );
}

echo $fails ? "\nHAY $fails FALLOS\n" : "\nTODO OK\n";
