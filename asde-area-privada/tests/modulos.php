<?php
/**
 * Pruebas de la v0.20 (entorno LOCAL): módulos que se activan o desactivan por grupo
 * (wp-admin → Grupos Scouts → Módulos; Domain\Modulos). Ejecutar desde app/public:
 *
 *   wp eval-file wp-content/plugins/asde-area-privada/tests/modulos.php
 *
 * Trabaja sobre todo en un grupo ficticio (999) sin CRM. Para la pantalla de
 * wp-admin y los formularios públicos usa el grupo real del Local, y deja la
 * opción de módulos exactamente como estaba.
 */
defined( 'ABSPATH' ) || exit;

require_once ABSPATH . 'wp-admin/includes/user.php';

use AsdeBalears\AreaPrivada\Admin\ModulosPage;
use AsdeBalears\AreaPrivada\Auth\Ambito;
use AsdeBalears\AreaPrivada\Crm\Altas;
use AsdeBalears\AreaPrivada\Domain\Grupo;
use AsdeBalears\AreaPrivada\Domain\Grupos;
use AsdeBalears\AreaPrivada\Domain\Modulos;
use AsdeBalears\AreaPrivada\Frontend\Menu;
use AsdeBalears\AreaPrivada\Frontend\Views\AltaScoutersView;
use AsdeBalears\AreaPrivada\Frontend\Views\BancoView;
use AsdeBalears\AreaPrivada\Frontend\Views\InventarioView;
use AsdeBalears\AreaPrivada\Frontend\Views\SepaView;
use AsdeBalears\AreaPrivada\Frontend\Views\SolicitarView;
use AsdeBalears\AreaPrivada\Install\Roles;
use AsdeBalears\AreaPrivada\Usuarios\EnlaceScouters;
use AsdeBalears\AreaPrivada\Usuarios\Usuarios;

global $wpdb;

$fails = 0;
$ok    = function ( $cond, string $label ) use ( &$fails ) {
	if ( ! $cond ) {
		++$fails;
	}
	echo ( $cond ? 'OK   ' : 'FAIL ' ), $label, "\n";
};

foreach ( get_users( array( 'search' => 'prueba-mod-*', 'search_columns' => array( 'user_login' ) ) ) as $u ) {
	exit( 'Ya existe el usuario ' . $u->user_login . " (¿una ejecución anterior interrumpida?). Bórralo antes de repetir la prueba.\n" );
}
$real = Grupos::por_numero( '684' ) ?? ( array_values( Grupos::todos() )[0] ?? null );
if ( ! $real || '' === $real['crm_id'] ) {
	exit( "Hace falta un grupo con la conexión al CRM comprobada.\n" );
}

$opcion_antes = get_option( 'asde_ap_modulos', null );
$enlace_antes = get_option( EnlaceScouters::OPCION, null );
$altas_antes  = get_option( Altas::OPCION, null ); // El formulario de wp-admin también guarda las altas en el CRM (v0.23).
$cache        = wp_using_ext_object_cache( true );
$cortadas     = 0;
$cortar_red   = static function ( $pre, $args, $url ) use ( &$cortadas ) {
	if ( false === $pre && str_contains( (string) $url, '/rest.php' ) ) {
		++$cortadas;
		return new WP_Error( 'prueba', 'CRM cortado durante la prueba' );
	}
	return $pre;
};
add_filter( 'pre_http_request', $cortar_red, 2, 3 );
add_filter( 'send_auth_cookies', '__return_false' );
$redirigir = static function ( $destino ) {
	throw new RuntimeException( 'asde-redirect:' . $destino );
};
add_filter( 'wp_redirect', $redirigir, 1 );
$falso = array(
	'id'     => '999',
	'numero' => '999',
	'nombre' => 'Grupo de prueba 999',
	'crm_id' => '',
);
delete_option( 'asde_ap_modulos' );
Modulos::olvidar();

$ids   = array();
$crear = static function ( string $login, string $rol ) use ( &$ids ): int {
	$id = wp_insert_user(
		array(
			'user_login' => $login,
			'user_pass'  => wp_generate_password( 24 ),
			'role'       => $rol,
		)
	);
	update_user_meta( $id, Usuarios::META_GRUPO, '999' );
	update_user_meta( $id, Usuarios::META_ESTADO, Usuarios::ACTIVO );
	$ids[ $login ] = $id;
	return $id;
};
$u_teso  = $crear( 'prueba-mod-teso', Roles::TESORERIA );
$u_admin = $crear( 'prueba-mod-admin', 'administrator' );
$como    = static function ( int $uid ): void {
	wp_set_current_user( $uid );
	Ambito::olvidar( $uid );
	Modulos::olvidar();
};
$todos_menos = static fn( string ...$quitar ) => array_values( array_diff( array_keys( Modulos::LISTA ), $quitar ) );
$ver         = static function ( $vista, array $get = array() ): string {
	$_GET = $get;
	try {
		return $vista->render();
	} finally {
		$_GET = array();
	}
};

try {
	Grupo::forzar( $falso );
	echo "== Parte A · Por defecto, todo activo ==\n";
	$como( $u_teso );
	$ok( ! array_filter( array_keys( Modulos::LISTA ), static fn( $m ) => Modulos::activo( $m ) === in_array( $m, Modulos::POR_DEFECTO_APAGADOS, true ) ), 'sin guardar nada, todos los módulos están activos (una instalación anterior no cambia) salvo los que nacen apagados (v0.32: la lista de espera)' );
	$ok( current_user_can( 'asde_inventario' ) && current_user_can( 'asde_tesoreria_config' ) && current_user_can( 'asde_mis_pagos' ), 'Tesorería tiene sus permisos de siempre' );

	echo "\n== Parte B · Apagar un módulo ==\n";
	Modulos::guardar( '999', $todos_menos( Modulos::INVENTARIO ) );
	$como( $u_teso );
	$ok( ! Modulos::activo( Modulos::INVENTARIO ) && ! current_user_can( 'asde_inventario' ) && ! current_user_can( 'asde_inventario_aprobar' ), 'con el inventario apagado, nadie del grupo tiene sus permisos' );
	$ok( ! isset( Menu::plano( Ambito::actual() )['inventario'] ) && str_contains( $ver( new InventarioView() ), 'No tienes acceso' ), 'y desaparece del menú y su pantalla no se abre' );
	$ok( current_user_can( 'asde_tesoreria_config' ) && current_user_can( 'asde_uniformes_pago' ), 'los demás módulos siguen igual' );
	$como( $u_admin );
	$ok( ! current_user_can( 'asde_inventario' ) && current_user_can( 'manage_options' ), 'también para un administrador mientras trabaja en ese grupo (wp-admin sigue igual)' );
	$ok( Modulos::activo( Modulos::INVENTARIO, '998' ) && array_merge( Modulos::LISTA[ Modulos::LISTA_ESPERA ][2], Modulos::LISTA[ Modulos::EVENTOS ][2] ) === Modulos::caps_quitadas( '998' ), 'otro grupo no se ve afectado (solo le falta lo que nace apagado: lista de espera y, desde la v0.35, eventos)' );
	$stored = get_option( 'asde_ap_modulos' );
	$ok( array( '999' => array( 'inventario' => false, 'lista_espera' => true, 'eventos' => true ) ) === $stored, 'solo se guarda lo que no es el valor por defecto (lo apagado y lo encendido que nace apagado)' );

	echo "\n== Parte C · Banco y Domiciliaciones, dentro de Tesorería ==\n";
	Modulos::guardar( '999', $todos_menos( Modulos::BANCO ) );
	$como( $u_teso );
	$ok( current_user_can( 'asde_tesoreria_config' ) && ! Modulos::activo( Modulos::BANCO ) && str_contains( $ver( new BancoView() ), 'El banco no está activo en tu grupo' ), 'el banco se apaga solo (Tesorería sigue) y su pantalla lo dice' );
	$ok( Modulos::activo( Modulos::SEPA ) && ! str_contains( $ver( new SepaView() ), 'no están activas' ), 'las domiciliaciones siguen' );
	Modulos::guardar( '999', $todos_menos( Modulos::TESORERIA ) );
	$como( $u_teso );
	$ok( ! Modulos::activo( Modulos::BANCO ) && ! Modulos::activo( Modulos::SEPA ) && ! current_user_can( 'asde_tesoreria_acceso' ) && ! current_user_can( 'asde_sepa_autorizar' ), 'sin Tesorería tampoco hay banco ni domiciliaciones (aunque sus casillas sigan marcadas)' );
	$ok( current_user_can( 'asde_mis_pagos' ), '«Mis pagos» se queda mientras haya uniformes' );
	Modulos::guardar( '999', $todos_menos( Modulos::TESORERIA, Modulos::UNIFORMES ) );
	$como( $u_teso );
	$ok( current_user_can( 'asde_mis_pagos' ) && ! current_user_can( 'asde_uniformes_pedir' ), '«Mis pagos» se queda mientras haya eventos (v0.35: enseña sus pagos)' );
	Modulos::guardar( '999', $todos_menos( Modulos::TESORERIA, Modulos::UNIFORMES, Modulos::EVENTOS ) );
	$como( $u_teso );
	$ok( ! current_user_can( 'asde_mis_pagos' ) && ! current_user_can( 'asde_uniformes_pedir' ), 'sin Tesorería, Uniformes ni Eventos, «Mis pagos» también se va' );
	Modulos::guardar( '999', array_keys( Modulos::LISTA ) );
	$como( $u_teso );
	$ok( current_user_can( 'asde_tesoreria_acceso' ) && current_user_can( 'asde_inventario' ) && array( 'lista_espera' => true, 'eventos' => true ) === ( get_option( 'asde_ap_modulos', array() )['999'] ?? null ), 'al activarlos de nuevo vuelve todo, y del grupo solo queda en la opción lo que nace apagado y se ha encendido (lista de espera y eventos)' );

	echo "\n== Parte D · Formularios públicos ==\n";
	Grupo::forzar( null );
	wp_set_current_user( 0 );
	$h = $ver( new SolicitarView(), array( 'grupo' => $real['id'] ) );
	$ok( str_contains( $h, esc_html( $real['nombre'] ) ), 'con las solicitudes activas, el grupo sale en «Solicitar acceso»' );
	Modulos::guardar( (string) $real['numero'], $todos_menos( Modulos::SOLICITUDES ) );
	Modulos::olvidar();
	$h = $ver( new SolicitarView(), array( 'grupo' => $real['id'] ) );
	$ok( ! str_contains( $h, 'value="' . esc_attr( $real['id'] ) . '"' ), 'apagadas, el grupo no se puede elegir para pedir el acceso' );
	Modulos::guardar( (string) $real['numero'], array_keys( Modulos::LISTA ) );
	$clave = EnlaceScouters::clave( $real );
	$h     = $ver( new AltaScoutersView(), array( 'grupo' => $real['id'], 'clave' => $clave ) );
	$ok( str_contains( $h, 'name="documento"' ), 'con el alta de scouters activa, el enlace del grupo abre el formulario' );
	Modulos::guardar( (string) $real['numero'], $todos_menos( Modulos::ALTA_SCOUTERS ) );
	$h = $ver( new AltaScoutersView(), array( 'grupo' => $real['id'], 'clave' => $clave ) );
	$ok( ! str_contains( $h, 'name="documento"' ), 'apagada, el mismo enlace ya no sirve' );
	Modulos::guardar( (string) $real['numero'], array_keys( Modulos::LISTA ) );

	echo "\n== Parte E · Pantalla de wp-admin ==\n";
	$como( $u_admin );
	ob_start();
	( new ModulosPage() )->render();
	$h = (string) ob_get_clean();
	$ok( str_contains( $h, 'Módulos del área privada' ) && str_contains( $h, 'name="activos[' . $real['numero'] . '][]" value="inventario"' ) && str_contains( $h, '(dentro de Tesorería)' ), 'una fila por grupo y una casilla por módulo (Banco y SEPA, dentro de Tesorería)' );
	$_POST    = array(
		'_wpnonce' => wp_create_nonce( ModulosPage::ACCION ),
		'activos'  => array( (string) $real['numero'] => $todos_menos( Modulos::INFORMES ) ),
	);
	$_REQUEST = $_POST;
	$destino  = '';
	try {
		( new ModulosPage() )->handle_guardar();
	} catch ( RuntimeException $e ) {
		$destino = $e->getMessage();
	} finally {
		$_POST    = array();
		$_REQUEST = array();
	}
	$ok( str_contains( $destino, ModulosPage::SLUG ) && ! Modulos::activo( Modulos::INFORMES, (string) $real['numero'] ) && Modulos::activo( Modulos::INVENTARIO, (string) $real['numero'] ), 'al guardar, se apaga lo desmarcado y lo demás sigue activo' );
	$como( $u_teso );
	$ok( ! current_user_can( 'manage_options' ), 'la pantalla es solo para administradores' );
	$ok( 0 === $cortadas, 'ninguna petición ha salido hacia el CRM' );
} finally {
	remove_filter( 'wp_redirect', $redirigir, 1 );
	remove_filter( 'send_auth_cookies', '__return_false' );
	remove_filter( 'pre_http_request', $cortar_red, 2 );
	$_GET  = array();
	$_POST = array();
	null === $opcion_antes ? delete_option( 'asde_ap_modulos' ) : update_option( 'asde_ap_modulos', $opcion_antes, false );
	null === $enlace_antes ? delete_option( EnlaceScouters::OPCION ) : update_option( EnlaceScouters::OPCION, $enlace_antes, false );
	null === $altas_antes ? delete_option( Altas::OPCION ) : update_option( Altas::OPCION, $altas_antes, false );
	Modulos::olvidar();
	wp_using_ext_object_cache( $cache );
	wp_set_current_user( 0 );
	$lista = implode( ',', array_map( 'intval', $ids ) ) ?: '0';
	$wpdb->query( "DELETE FROM {$wpdb->prefix}asde_auditoria WHERE user_id IN ($lista)" ); // phpcs:ignore WordPress.DB
	foreach ( $ids as $uid ) {
		delete_transient( 'asde_ap_modulos_aviso_' . $uid );
		Ambito::olvidar( (int) $uid );
		wp_delete_user( (int) $uid );
	}
	Grupo::forzar( null );
	$ok( get_option( 'asde_ap_modulos', null ) === $opcion_antes && ! get_users( array( 'search' => 'prueba-mod-*', 'search_columns' => array( 'user_login' ) ) ), 'limpieza: la opción de módulos como estaba y sin usuarios de la prueba' );
}

echo $fails ? "\n$fails FALLOS\n" : "\nTODO OK (la opción de módulos queda como estaba)\n";
