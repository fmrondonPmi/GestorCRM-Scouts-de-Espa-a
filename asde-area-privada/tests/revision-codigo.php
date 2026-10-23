<?php
/**
 * Pruebas de las correcciones de la revisión de código (doc 59; entorno LOCAL).
 * Ejecutar desde app/public del site Local:
 *
 *   wp eval-file wp-content/plugins/asde-area-privada/tests/revision-codigo.php
 *
 * - RC-CRM-1: list_all no devuelve una lista cortada en silencio: con más registros que el límite, CrmException.
 * Las demás correcciones con prueba van en las pruebas de su módulo (banco-gestion, tienda-fase5, sepa, eventos-inscripciones…)
 * o en las partes siguientes de esta.
 * Usa el CRM simulado (fichas inventadas) y deja el sitio como estaba.
 */
defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/lib/CrmSimulado.php';

use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Crm\ClientFactory;
use AsdeBalears\AreaPrivada\Crm\CrmException;
use AsdeBalears\AreaPrivada\Crm\Escritura;
use AsdeBalears\AreaPrivada\Domain\Grupo;
use AsdeBalears\AreaPrivada\Domain\Grupos;

global $wpdb;
$fails = 0;
$ok    = function ( $cond, string $label ) use ( &$fails ) {
	if ( ! $cond ) {
		++$fails;
	}
	echo ( $cond ? 'OK   ' : 'FAIL ' ), $label, "\n";
};

$grupo = Grupos::por_numero( '684' ) ?? ( array_values( Grupos::todos() )[0] ?? null );
if ( ! $grupo || '' === $grupo['crm_id'] ) {
	exit( "Hace falta un grupo con la conexión al CRM comprobada.\n" );
}

// --- Lo que se restaura al final ---
$log_antes = (int) $wpdb->get_var( 'SELECT COALESCE(MAX(id), 0) FROM ' . Logger::table() ); // phpcs:ignore WordPress.DB

$cache_externa = wp_using_ext_object_cache( true );
$cortar_crm    = static function ( $pre, $args, $url ) {
	if ( false === $pre && str_contains( (string) $url, '/rest.php' ) ) {
		return new WP_Error( 'prueba', 'CRM real cortado durante la prueba' );
	}
	return $pre;
};
add_filter( 'pre_http_request', $cortar_crm, 2, 3 );
add_filter( 'asde_ap_instantanea_segundos', '__return_zero' );
$P   = array( 'Asde_Prueba_Crm', 'persona' );
$crm = new Asde_Prueba_Crm(
	array(
		$P( 81, 'Ana', 'Rc Uno', '2010-01-01', 'Scouts' ),
		$P( 82, 'Bea', 'Rc Dos', '2011-01-01', 'Scouts' ),
		$P( 83, 'Ciro', 'Rc Tres', '2012-01-01', 'Lobatos' ),
		$P( 84, 'Dani', 'Rc Cuatro', '2013-01-01', 'Lobatos' ),
		$P( 85, 'Eli', 'Rc Cinco', '2014-01-01', 'Castores' ),
	),
	$grupo['crm_id']
);
Grupo::forzar( $grupo );
$crm->activar();
Escritura::usar_para_pruebas( $crm );

try {
	echo "== Parte A · RC-CRM-1: list_all no corta en silencio ==\n";
	$cliente = ClientFactory::reader();
	$ok( null !== $cliente, 'hay cliente del CRM (simulado)' );
	$todas = $cliente->list_all( 'Contacts', '', array( 'id' ), array(), '', 10 );
	$ok( 5 === count( $todas ), 'con el límite por encima del total, salen las 5 fichas' );
	$justas = $cliente->list_all( 'Contacts', '', array( 'id' ), array(), '', 5 );
	$ok( 5 === count( $justas ), 'con el límite justo en el total, también (no es un corte)' );
	$error = null;
	try {
		$cliente->list_all( 'Contacts', '', array( 'id' ), array(), '', 3 );
	} catch ( CrmException $e ) {
		$error = $e->getMessage();
	}
	$ok( null !== $error && str_contains( $error, 'más de 3 registros' ), 'con más fichas que el límite: CrmException con un mensaje claro, no una lista parcial' );
	$reg = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT detalles FROM %i WHERE id > %d AND accion = %s ORDER BY id DESC LIMIT 1', Logger::table(), $log_antes, 'crm_limite_lista' ) ); // phpcs:ignore WordPress.DB
	$ok( str_contains( $reg, '"limite":3' ) && str_contains( $reg, '"total":5' ), 'y queda en el registro (límite y total, sin datos personales)' );
	$filtradas = $cliente->list_all( 'Contacts', "contacts.last_name LIKE 'Rc C%'", array( 'id' ), array(), '', 3 );
	$ok( 2 === count( $filtradas ), 'una consulta con menos resultados que el límite no se ve afectada' );
} catch ( Throwable $e ) {
	++$fails;
	echo 'FAIL excepcion: ', $e->getMessage(), ' (', basename( $e->getFile() ), ':', $e->getLine(), ")\n";
} finally {
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id > %d AND accion = %s', Logger::table(), $log_antes, 'crm_limite_lista' ) ); // phpcs:ignore WordPress.DB
	Escritura::usar_para_pruebas( null );
	$crm->desactivar();
	remove_filter( 'pre_http_request', $cortar_crm, 2 );
	remove_filter( 'asde_ap_instantanea_segundos', '__return_zero' );
	wp_using_ext_object_cache( $cache_externa );
	Grupo::forzar( null );
}

echo $fails ? "\n{$fails} FALLOS\n" : "\nTODO OK (el sitio queda como estaba)\n";
