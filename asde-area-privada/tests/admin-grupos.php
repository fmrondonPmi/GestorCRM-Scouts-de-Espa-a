<?php
/**
 * Pruebas de la pantalla de wp-admin «Grupos y conexión CRM» (Admin\SettingsPage; entorno LOCAL; doc 60): dar de alta,
 * editar, probar y dar de baja un grupo con su credencial del CRM. Ejecutar desde app/public:
 *
 *   wp eval-file wp-content/plugins/asde-area-privada/tests/admin-grupos.php
 *
 * (o, desde herramientas-desarrollo: ./probar-todo.sh admin-grupos)
 *
 * - A: solo un administrador (manage_options) y con nonce; quien no lo es recibe un 403 y no cambia nada.
 * - B: alta de un grupo (993) con credencial: se verifica contra el CRM SIMULADO, la contraseña se guarda cifrada y no
 *   sale nunca en la pantalla; nº de grupo mal escrito y alta repetida dan error.
 * - C: editar sin escribir la contraseña la conserva; «Probar» con el CRM caído avisa del error; baja del grupo.
 * Restaura la opción asde_ap_grupos entera en el finally, borra su usuario y su registro. Cachés en memoria.
 */
defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/lib/CrmSimulado.php';
require_once ABSPATH . 'wp-admin/includes/user.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/template.php';

use AsdeBalears\AreaPrivada\Admin\SettingsPage;
use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Domain\Grupos;
use AsdeBalears\AreaPrivada\Install\Roles;

global $wpdb;

$fails = 0;
$ok    = function ( $cond, string $label ) use ( &$fails ) {
	if ( ! $cond ) {
		++$fails;
	}
	echo ( $cond ? 'OK   ' : 'FAIL ' ), $label, "\n";
};

if ( Grupos::por_numero( '993' ) ) {
	exit( "El grupo 993 existe en el Local (¿una ejecución anterior interrumpida?). Revísalo antes de repetir la prueba.\n" );
}
foreach ( get_users( array( 'search' => 'prueba-adm-*', 'search_columns' => array( 'user_login' ) ) ) as $u ) {
	exit( 'Ya existe el usuario ' . $u->user_login . " (¿una ejecución anterior interrumpida?). Bórralo antes de repetir la prueba.\n" );
}

// --- Lo que se restaura al final ---------------------------------------------
$grupos_orig   = get_option( 'asde_ap_grupos', null );
$log_antes     = (int) $wpdb->get_var( 'SELECT COALESCE(MAX(id), 0) FROM ' . Logger::table() ); // phpcs:ignore WordPress.DB
$actual        = get_current_user_id();
$cache_externa = wp_using_ext_object_cache( true );
$cortadas      = 0;
$cortar_crm    = static function ( $pre, $args, $url ) use ( &$cortadas ) {
	if ( false === $pre && str_contains( (string) $url, '/rest.php' ) ) {
		++$cortadas;
		return new WP_Error( 'prueba', 'CRM real cortado durante la prueba' );
	}
	return $pre;
};
add_filter( 'pre_http_request', $cortar_crm, 2, 3 );
$redirigir = static function ( $destino ) {
	throw new RuntimeException( 'asde-redirect:' . $destino );
};
add_filter( 'wp_redirect', $redirigir, 1 );
$al_morir = static function ( $mensaje ) {
	throw new RuntimeException( 'asde-die:' . ( is_string( $mensaje ) ? $mensaje : '' ) );
};
$morir = static fn() => $al_morir;
add_filter( 'wp_die_handler', $morir, PHP_INT_MAX );

$crm           = new Asde_Prueba_Crm( array(), 'sg-prueba-993' );
$crm->numgrupo = '993';
$crm->activar();

$ids   = array();
$crear = static function ( string $login, string $rol ) use ( &$ids ): int {
	$id            = wp_insert_user(
		array(
			'user_login' => $login,
			'user_email' => $login . '@example.invalid',
			'user_pass'  => wp_generate_password( 24 ),
			'role'       => $rol,
		)
	);
	$ids[ $login ] = $id;
	return $id;
};
$u_admin = $crear( 'prueba-adm-admin', 'administrator' );
$u_secre = $crear( 'prueba-adm-secre', Roles::SECRETARIA );

/** Llama a una acción de admin-post.php como el formulario; devuelve 'redirect', 'die' o ''. */
$accion = static function ( string $metodo, string $nonce, array $campos, ?string $nonce_valor = null ): string {
	$_POST    = $campos + array( '_wpnonce' => $nonce_valor ?? wp_create_nonce( $nonce ) );
	$_REQUEST = $_POST;
	try {
		( new SettingsPage() )->$metodo();
		return '';
	} catch ( RuntimeException $e ) {
		return str_starts_with( $e->getMessage(), 'asde-redirect:' ) ? 'redirect' : ( str_starts_with( $e->getMessage(), 'asde-die:' ) ? 'die' : throw $e );
	} finally {
		$_POST    = array();
		$_REQUEST = array();
	}
};
$aviso   = static fn(): array => (array) get_transient( 'asde_ap_admin_aviso_' . get_current_user_id() );
$pintar  = static function ( array $get ): string {
	$_GET = $get;
	ob_start();
	try {
		( new SettingsPage() )->render();
	} finally {
		$_GET = array();
	}
	return (string) ob_get_clean();
};
$alta    = array(
	'alta'           => '1',
	'numero'         => '993',
	'nombre'         => 'Grupo de prueba 993',
	'email_contacto' => 'grupo993@example.invalid',
	'usuario'        => 'usuario-prueba-993',
	'clave'          => 'Clave Secreta 993!',
);

try {
	echo "== Parte A · Solo un administrador y con nonce ==\n";
	wp_set_current_user( $u_secre );
	$ok( 'die' === $accion( 'handle_guardar', 'asde_ap_grupo_guardar', $alta ) && null === Grupos::por_numero( '993' ), 'Secretaría (sin manage_options): 403 y no se crea el grupo' );
	$ok( 'die' === $accion( 'handle_borrar', 'asde_ap_grupo_borrar', array( 'numero' => '684' ) ) && null !== Grupos::por_numero( '684' ), 'ni puede dar de baja un grupo' );
	$ok( '' === $pintar( array() ), 'y la pantalla no le enseña nada' );
	wp_set_current_user( $u_admin );
	$ok( 'die' === $accion( 'handle_guardar', 'asde_ap_grupo_guardar', $alta, 'nonce-falso' ) && null === Grupos::por_numero( '993' ), 'un administrador con un nonce falso: se para y no se crea' );

	echo "\n== Parte B · Alta con la credencial del CRM ==\n";
	$ok( 'redirect' === $accion( 'handle_guardar', 'asde_ap_grupo_guardar', $alta ), 'el administrador da de alta el grupo 993' );
	$g = Grupos::por_numero( '993' );
	$ok( $g && Grupos::OK === $g['estado'] && 'sg-prueba-993' === $g['crm_id'] && 'grupo993@example.invalid' === $g['email_contacto'], 'se verifica en el acto contra el CRM (simulado): queda con su grupo de seguridad y el email de contacto' );
	$a = $aviso();
	$ok( in_array( $a[0] ?? '', array( 'success', 'warning' ), true ) && str_contains( (string) ( $a[1] ?? '' ), 'dado de alta' ) && str_contains( (string) ( $a[1] ?? '' ), 'Conexión correcta' ), 'y avisa de que está dado de alta y la conexión es correcta (con los avisos del CRM, si los hay)' );
	$raw = (string) wp_json_encode( get_option( 'asde_ap_grupos' ) );
	$ok( ! str_contains( $raw, 'Clave Secreta 993!' ) && 'Clave Secreta 993!' === Grupos::credenciales( '993' )['clave'], 'la contraseña se guarda cifrada (no está en claro en la opción) y se puede descifrar' );
	$h = $pintar( array( 'editar' => '993' ) );
	$ok( str_contains( $h, 'usuario-prueba-993' ) && ! str_contains( $h, 'Clave Secreta 993!' ) && str_contains( $h, 'déjalo vacío para no cambiarla' ), 'al editar: se ve el usuario, nunca la contraseña' );
	$ok( ! str_contains( $pintar( array() ), 'Clave Secreta 993!' ), 'tampoco en la lista de grupos' );
	$ok( 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE id > %d AND accion = %s AND objeto_id = %s AND detalles NOT LIKE %s', Logger::table(), $log_antes, 'grupo_alta', '993', '%Clave%' ) ), 'queda en el registro el alta, sin la credencial' ); // phpcs:ignore WordPress.DB
	$antes = get_option( 'asde_ap_grupos' );
	$ok( 'redirect' === $accion( 'handle_guardar', 'asde_ap_grupo_guardar', $alta ) && $antes === get_option( 'asde_ap_grupos' ) && 'error' === ( $aviso()[0] ?? '' ), 'dar de alta otra vez el mismo nº: error y no cambia nada' );
	$ok( 'redirect' === $accion( 'handle_guardar', 'asde_ap_grupo_guardar', array( 'numero' => '1234' ) + $alta ) && $antes === get_option( 'asde_ap_grupos' ) && 'error' === ( $aviso()[0] ?? '' ), 'un nº de grupo de 4 cifras: error (ojo: «99a9» se acepta como 999, normalizar_numero quita lo que no es cifra)' );

	echo "\n== Parte C · Editar, probar y dar de baja ==\n";
	$ok( 'redirect' === $accion( 'handle_guardar', 'asde_ap_grupo_guardar', array( 'alta' => '0', 'clave' => '', 'nombre' => 'Grupo 993 renombrado' ) + $alta ), 'editar el nombre sin escribir la contraseña' );
	$ok( 'Grupo 993 renombrado' === Grupos::por_numero( '993' )['nombre'] && 'Clave Secreta 993!' === Grupos::credenciales( '993' )['clave'], 'cambia el nombre y la contraseña guardada se conserva' );
	$crm->caido = true;
	$ok( 'redirect' === $accion( 'handle_probar', 'asde_ap_grupo_probar', array( 'numero' => '993' ) ) && 'error' === ( $aviso()[0] ?? '' ) && Grupos::ERROR === Grupos::por_numero( '993' )['estado'], '«Probar» con el CRM sin responder: aviso de error y el grupo queda con error' );
	$crm->caido = false;
	$ok( 'redirect' === $accion( 'handle_probar', 'asde_ap_grupo_probar', array( 'numero' => '993' ) ) && Grupos::OK === Grupos::por_numero( '993' )['estado'], '«Probar» otra vez, ya bien' );
	$ok( 'redirect' === $accion( 'handle_borrar', 'asde_ap_grupo_borrar', array( 'numero' => '993' ) ) && null === Grupos::por_numero( '993' ) && 'success' === ( $aviso()[0] ?? '' ), 'baja del grupo' );
	$ok( 'redirect' === $accion( 'handle_borrar', 'asde_ap_grupo_borrar', array( 'numero' => '993' ) ) && 'error' === ( $aviso()[0] ?? '' ), 'dar de baja un grupo que no existe: error' );
	$ok( 0 === $cortadas && $crm->llamadas > 0, "todas las consultas al CRM las ha respondido el simulador ({$crm->llamadas})" );
} catch ( Throwable $e ) {
	++$fails;
	echo 'FAIL excepcion: ', $e->getMessage(), ' (', basename( $e->getFile() ), ':', $e->getLine(), ")\n";
} finally {
	$crm->desactivar();
	remove_filter( 'pre_http_request', $cortar_crm, 2 );
	remove_filter( 'wp_redirect', $redirigir, 1 );
	remove_filter( 'wp_die_handler', $morir, PHP_INT_MAX );
	$_GET  = array();
	$_POST = array();
	if ( null === $grupos_orig ) {
		delete_option( 'asde_ap_grupos' );
	} else {
		update_option( 'asde_ap_grupos', $grupos_orig, false );
	}
	Grupos::olvidar();
	$lista = implode( ',', array_map( 'intval', $ids ) ) ?: '0';
	$wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE id > %d AND user_id IN ($lista)", Logger::table(), $log_antes ) ); // phpcs:ignore WordPress.DB
	wp_set_current_user( $actual );
	foreach ( $ids as $uid ) {
		wp_delete_user( (int) $uid );
	}
	wp_using_ext_object_cache( $cache_externa );
	$ok( get_option( 'asde_ap_grupos', null ) === $grupos_orig && 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users} WHERE user_login LIKE 'prueba-adm-%'" ), 'limpieza: grupos como estaban y sin usuarios de la prueba' ); // phpcs:ignore WordPress.DB
}

echo $fails ? "\n{$fails} FALLOS\n" : "\nTODO OK (nada tocado en el CRM real; el sitio queda como estaba)\n";
