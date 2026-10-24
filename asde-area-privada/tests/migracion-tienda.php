<?php
/**
 * Pruebas de la v0.39 (entorno LOCAL): el módulo «Uniformes» pasa a «Tienda» (doc 48,
 * Install\MigracionTienda). Se deja el sitio «como estaba antes de la v0.39» (tabla,
 * opciones, marca de módulo y tres páginas con los nombres antiguos), se ejecuta la
 * migración y se comprueba que todo queda con los nombres nuevos y sin perder datos;
 * después se vuelve a ejecutar (es idempotente) y se restaura el sitio. Ejecutar desde app/public:
 *
 *   wp eval-file wp-content/plugins/asde-area-privada/tests/migracion-tienda.php
 *
 * No toca el CRM ni usa datos reales.
 */
defined( 'ABSPATH' ) || exit;

use AsdeBalears\AreaPrivada\Domain\Grupo;
use AsdeBalears\AreaPrivada\Frontend\Pages;
use AsdeBalears\AreaPrivada\Install\MigracionTienda;
use AsdeBalears\AreaPrivada\Tienda\Config;
use AsdeBalears\AreaPrivada\Tienda\PedidosTable;

global $wpdb;

$fails = 0;
$ok    = function ( $cond, string $label ) use ( &$fails ) {
	if ( ! $cond ) {
		++$fails;
	}
	echo ( $cond ? 'OK   ' : 'FAIL ' ), $label, "\n";
};

$grupo   = Grupo::actual();
$n       = (string) $grupo['numero'];
$nueva   = $wpdb->prefix . 'asde_tienda_pedidos';
$vieja   = $wpdb->prefix . 'asde_uniformes';
$existe  = static fn( string $t ): bool => $t === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $t ) ) );
$paginas = (array) get_option( 'asde_ap_pages', array() );
$ids     = array(
	'uniforme'         => (int) ( $paginas['tienda_pedidos'] ?? 0 ),
	'uniformes'        => (int) ( $paginas['tienda_gestion'] ?? 0 ),
	'uniformes_config' => (int) ( $paginas['tienda_config'] ?? 0 ),
);

$ok( PedidosTable::table() === $nueva, 'la tabla de pedidos es asde_tienda_pedidos' );
$ok( $existe( $nueva ) && ! $existe( $vieja ), 'el sitio ya está migrado: existe la nueva y no la antigua' );
$ok( ! in_array( 0, $ids, true ), 'las tres páginas existen con las claves nuevas' );

// Copia de seguridad de lo que se va a tocar.
$antes = array(
	'pages'   => get_option( 'asde_ap_pages' ),
	'modulos' => get_option( 'asde_ap_modulos', null ),
	'tienda'  => get_option( 'asde_ap_tienda_' . $n, null ),
	'posts'   => array(),
	'slugs'   => array(),
);
foreach ( $ids as $vieja_clave => $id ) {
	$p                          = get_post( $id );
	$antes['posts'][ $id ]      = array( $p->post_name, $p->post_title, $p->post_content );
	$antes['slugs'][ $id ]      = get_post_meta( $id, '_wp_old_slug' );
}

try {
	// 1. El sitio «como estaba antes»: tabla, opción, marca de módulo y páginas con los nombres antiguos.
	$wpdb->query( $wpdb->prepare( 'RENAME TABLE %i TO %i', $nueva, $vieja ) );
	update_option( 'asde_ap_uniformes_' . $n, array( 'precio_camisa' => 31.0 ), false );
	delete_option( 'asde_ap_tienda_' . $n );
	$modulos = is_array( $antes['modulos'] ) ? $antes['modulos'] : array();
	$modulos['998'] = array( 'uniformes' => false );
	update_option( 'asde_ap_modulos', $modulos, false );
	$viejas = array();
	foreach ( (array) $antes['pages'] as $k => $id ) {
		if ( ! in_array( $k, array( 'tienda_pedidos', 'tienda_gestion', 'tienda_config' ), true ) ) {
			$viejas[ $k ] = $id;
		}
	}
	$viejas['uniforme']         = $ids['uniforme'];
	$viejas['uniformes']        = $ids['uniformes'];
	$viejas['uniformes_config'] = $ids['uniformes_config'];
	update_option( 'asde_ap_pages', $viejas, false );
	wp_update_post( array( 'ID' => $ids['uniforme'], 'post_name' => 'uniforme', 'post_title' => 'Uniforme', 'post_content' => '[asde_uniforme]' ) );
	wp_update_post( array( 'ID' => $ids['uniformes'], 'post_name' => 'gestion-pedidos', 'post_title' => 'Gestión de pedidos de uniforme', 'post_content' => '[asde_uniformes]' ) );
	wp_update_post( array( 'ID' => $ids['uniformes_config'], 'post_name' => 'configuracion', 'post_title' => 'Una configuración que el grupo retituló', 'post_content' => "Texto del grupo\n\n[asde_uniformes_config]" ) );

	// 2. Migrar.
	MigracionTienda::ejecutar();

	$ok( $existe( $nueva ) && ! $existe( $vieja ), 'la tabla se ha renombrado (no copiado)' );
	$ok( array( 'precio_camisa' => 31.0 ) === get_option( 'asde_ap_tienda_' . $n ) && null === get_option( 'asde_ap_uniformes_' . $n, null ), 'la configuración del grupo pasa a asde_ap_tienda_<grupo>' );
	$ok( 31.0 === Config::de()['precio_camisa'], 'Config lee la configuración migrada' );
	$m = get_option( 'asde_ap_modulos' );
	$ok( array( 'tienda' => false ) === ( $m['998'] ?? null ), 'la marca «uniformes» del módulo pasa a «tienda» (sigue apagado)' );
	$pg = Pages::ids();
	$ok( $ids['uniforme'] === ( $pg['tienda_pedidos'] ?? 0 ) && $ids['uniformes'] === ( $pg['tienda_gestion'] ?? 0 ) && $ids['uniformes_config'] === ( $pg['tienda_config'] ?? 0 ), 'las páginas conservan su id con la clave nueva' );
	$ok( ! isset( $pg['uniforme'] ) && ! isset( $pg['uniformes'] ) && ! isset( $pg['uniformes_config'] ), 'ya no quedan las claves antiguas' );
	$a = get_post( $ids['uniforme'] );
	$b = get_post( $ids['uniformes'] );
	$c = get_post( $ids['uniformes_config'] );
	$ok( 'mis-pedidos' === $a->post_name && 'Mis pedidos' === $a->post_title && '[asde_tienda_pedidos]' === $a->post_content, 'Mis pedidos: dirección, título y shortcode nuevos' );
	$ok( 'Gestión de la tienda' === $b->post_title && '[asde_tienda_gestion]' === $b->post_content, 'Gestión de la tienda: título y shortcode nuevos' );
	$ok( 'Una configuración que el grupo retituló' === $c->post_title && str_contains( $c->post_content, "Texto del grupo\n\n[asde_tienda_config]" ), 'un título cambiado a mano no se pisa y el texto del grupo se conserva' );
	$ok( '/nuestro-grupo-scout/area-privada/mis-pedidos/' === MigracionTienda::ruta_nueva( '/nuestro-grupo-scout/area-privada/uniforme/' ) && '/a/mis-pedidos/gestion-pedidos/' === MigracionTienda::ruta_nueva( '/a/uniforme/gestion-pedidos/' ) && '/a/mis-pedidos' === MigracionTienda::ruta_nueva( '/a/uniforme' ), 'las direcciones antiguas /uniforme/… se llevan a /mis-pedidos/… (301)' );
	$ok( null === MigracionTienda::ruta_nueva( '/a/uniformes-viejos/' ) && null === MigracionTienda::ruta_nueva( '/a/mis-pagos/' ), 'otras direcciones no se tocan' );

	// 3. Idempotente.
	$antes_pg = Pages::ids();
	MigracionTienda::ejecutar();
	$ok( $antes_pg === Pages::ids() && $existe( $nueva ) && array( 'precio_camisa' => 31.0 ) === get_option( 'asde_ap_tienda_' . $n ), 'ejecutarla otra vez no cambia nada' );

	// 4. Las capabilities antiguas se quitan al administrador de WordPress.
	$admin = get_role( 'administrator' );
	$admin->add_cap( 'asde_uniformes_pago' );
	MigracionTienda::ejecutar();
	$ok( ! $admin->has_cap( 'asde_uniformes_pago' ), 'el administrador pierde las capabilities asde_uniformes_*' );
} catch ( Throwable $e ) {
	++$fails;
	echo 'FAIL excepcion: ', $e->getMessage(), ' (', basename( $e->getFile() ), ':', $e->getLine(), ")\n";
} finally {
	if ( ! $existe( $nueva ) && $existe( $vieja ) ) {
		$wpdb->query( $wpdb->prepare( 'RENAME TABLE %i TO %i', $vieja, $nueva ) );
	}
	delete_option( 'asde_ap_uniformes_' . $n );
	if ( null === $antes['tienda'] ) {
		delete_option( 'asde_ap_tienda_' . $n );
	} else {
		update_option( 'asde_ap_tienda_' . $n, $antes['tienda'], false );
	}
	if ( null === $antes['modulos'] ) {
		delete_option( 'asde_ap_modulos' );
	} else {
		update_option( 'asde_ap_modulos', $antes['modulos'], false );
	}
	update_option( 'asde_ap_pages', $antes['pages'], false );
	foreach ( $antes['posts'] as $id => list( $slug, $titulo, $contenido ) ) {
		wp_update_post( wp_slash( array( 'ID' => $id, 'post_name' => $slug, 'post_title' => $titulo, 'post_content' => $contenido ) ) );
		delete_post_meta( $id, '_wp_old_slug' );
		foreach ( $antes['slugs'][ $id ] as $s ) {
			add_post_meta( $id, '_wp_old_slug', $s );
		}
	}
	$ok( $existe( $nueva ) && ! $existe( $vieja ) && $antes['pages'] === get_option( 'asde_ap_pages' ) && null === get_option( 'asde_ap_uniformes_' . $n, null ), 'limpieza: el sitio queda como estaba' );
}

echo $fails ? "\n$fails FALLOS\n" : "\nTODO OK (el sitio queda como estaba)\n";
