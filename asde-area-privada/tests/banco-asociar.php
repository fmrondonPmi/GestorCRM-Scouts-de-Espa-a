<?php
/**
 * Pruebas de la v0.52 (entorno LOCAL): «Marcar como revisado» en el banco con evento y familia.
 * Ejecutar desde app/public del site Local:
 *
 *   wp eval-file <ruta completa>/wp-content/plugins/asde-area-privada/tests/banco-asociar.php
 *
 * Grupo de prueba 999 (ficticio), nada en el CRM. Borra todo lo que crea.
 */
defined( 'ABSPATH' ) || exit;

use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Domain\Grupo;
use AsdeBalears\AreaPrivada\Domain\Modulos;
use AsdeBalears\AreaPrivada\Eventos\EventosTable;
use AsdeBalears\AreaPrivada\Tesoreria\Banco\BancoTable;
use AsdeBalears\AreaPrivada\Tesoreria\Banco\Conciliacion;
use AsdeBalears\AreaPrivada\Tesoreria\Banco\TiposMovimiento;
use AsdeBalears\AreaPrivada\Tesoreria\FamiliasTable;
use AsdeBalears\AreaPrivada\Tesoreria\HistorialPagos;

global $wpdb;

$fails = 0;
$ok    = function ( $cond, string $label ) use ( &$fails ) {
	if ( ! $cond ) {
		++$fails;
	}
	echo ( $cond ? 'OK   ' : 'FAIL ' ), $label, "\n";
};

foreach ( array( BancoTable::table(), TiposMovimiento::table(), FamiliasTable::table(), EventosTable::table() ) as $t ) {
	if ( (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE grupo = '999'", $t ) ) ) { // phpcs:ignore WordPress.DB
		exit( "Quedan datos del grupo de prueba 999 en $t (¿una ejecución anterior interrumpida?).\n" );
	}
}
$log_antes = (int) $wpdb->get_var( 'SELECT COALESCE(MAX(id), 0) FROM ' . Logger::table() ); // phpcs:ignore WordPress.DB
$mods_antes = get_option( 'asde_ap_modulos', null );
Grupo::forzar( array( 'id' => '999', 'numero' => '999', 'nombre' => 'Grupo de prueba 999', 'crm_id' => '' ) );
$cache_externa = wp_using_ext_object_cache( true );

$importar = static function ( array $movs ): array {
	$base = array(
		'cuenta'          => '9999 0001 ******0001',
		'fecha_valor'     => null,
		'concepto_comun'  => '',
		'concepto_propio' => '',
		'documento'       => '',
		'referencia1'     => '',
		'referencia2'     => '',
		'ordenante'       => '',
	);
	$movs = BancoTable::clasificar( array_map( static fn( $m ) => $m + $base, $movs ) );
	return BancoTable::importar(
		array(
			'movimientos' => $movs,
			'cuentas'     => array( array( 'cuenta' => '9999 0001 ******0001', 'saldo_final' => null ) ),
		),
		'csv',
		'prueba.csv',
		hash( 'sha256', wp_json_encode( $movs ) )
	);
};
$mov = static fn( string $c ) => current( BancoTable::movimientos( array( 'q' => $c ) ) ) ?: null;

try {
	$t_don = TiposMovimiento::crear( 'Donativos de prueba', 'ingreso' );
	$t_gas = TiposMovimiento::crear( 'Material de prueba', 'gasto' );
	$evento = EventosTable::crear(
		array(
			'nombre' => 'Campamento de prueba banco',
			'tipo'   => 'campamento',
			'ambito' => 'grupo',
			'inicio' => wp_date( 'Y-m-d', time() + 10 * DAY_IN_SECONDS ),
			'fin'    => wp_date( 'Y-m-d', time() + 12 * DAY_IN_SECONDS ),
		)
	);
	$fam = FamiliasTable::crear( array( '990901' ) );
	$ok( $evento > 0 && is_int( $fam ), 'Preparación: evento y familia de prueba' );

	$r = $importar(
		array(
			array( 'fecha' => '2026-10-10', 'importe' => 150.0, 'concepto' => 'PRUEBA-BA ingreso de una familia' ),
			array( 'fecha' => '2026-10-11', 'importe' => -60.0, 'concepto' => 'PRUEBA-BA gasto del campamento' ),
			array( 'fecha' => '2026-10-12', 'importe' => 40.0, 'concepto' => 'PRUEBA-BA otro ingreso' ),
		)
	);
	$ok( 3 === $r['nuevos'], 'Movimientos de prueba importados' );
	$ing = $mov( 'ingreso de una familia' );
	$gas = $mov( 'gasto del campamento' );
	$otr = $mov( 'otro ingreso' );

	echo "== A. Revisar con evento y con familia ==\n";
	$ok( true === Conciliacion::ignorar( (int) $ing['id'], $t_don, 'Donativo de la familia', 0, $fam ), 'Un ingreso que no es cuota se revisa y se asocia a una familia' );
	$a = BancoTable::obtener( (int) $ing['id'] );
	$ok( BancoTable::IGNORADO === $a['estado'] && $fam === (int) $a['familia_id'] && null === $a['evento_id'] && $t_don === (int) $a['tipo_id'], 'Queda revisado, con su tipo y la familia, y sin evento' );
	$ok( null === $a['pago_id'], 'No crea ningún pago de la cuota' );
	$ok( true === Conciliacion::ignorar( (int) $gas['id'], $t_gas, '', $evento, 0 ), 'Un gasto se revisa y se asocia a un evento' );
	$b = BancoTable::obtener( (int) $gas['id'] );
	$ok( $evento === (int) $b['evento_id'] && null === $b['familia_id'], 'Queda con el evento y sin familia' );
	$ok( true === Conciliacion::ignorar( (int) $otr['id'], $t_don, '', $evento, $fam ), 'Se pueden asociar las dos cosas a la vez' );
	$c = BancoTable::obtener( (int) $otr['id'] );
	$ok( $evento === (int) $c['evento_id'] && $fam === (int) $c['familia_id'], 'Con evento y con familia' );
	$ok( is_wp_error( Conciliacion::ignorar( (int) $otr['id'], $t_don, '', 999999, 0 ) ) && is_wp_error( Conciliacion::ignorar( (int) $otr['id'], $t_don, '', 0, 999999 ) ), 'Un evento o una familia que no existen se rechazan' );
	$ok( $evento === (int) BancoTable::obtener( (int) $otr['id'] )['evento_id'], 'Y lo rechazado no cambia lo guardado' );
	$ok( true === Conciliacion::ignorar( (int) $otr['id'], $t_don, '', 0, 0 ) && null === BancoTable::obtener( (int) $otr['id'] )['evento_id'] && null === BancoTable::obtener( (int) $otr['id'] )['familia_id'], 'Volver a revisarlo sin evento ni familia los quita' );

	echo "\n== B. Dónde se ve ==\n";
	$de_fam = BancoTable::revisados_de_familia( $fam );
	$ok( 1 === count( $de_fam ) && (int) $ing['id'] === (int) $de_fam[0]['id'], 'La familia tiene sus movimientos revisados' );
	$de_ev = BancoTable::revisados_de_evento( $evento );
	$ok( 1 === count( $de_ev ) && (int) $gas['id'] === (int) $de_ev[0]['id'], 'El evento tiene sus movimientos revisados' );
	Modulos::guardar( '999', array_keys( Modulos::LISTA ) );
	$hist = HistorialPagos::otros( FamiliasTable::obtener( $fam ) );
	$banco_h = array_values( array_filter( $hist, static fn( $h ) => 'banco' === $h['modulo'] ) );
	$ok( 1 === count( $banco_h ) && str_contains( $banco_h[0]['que'], 'Donativos de prueba' ), 'En el historial de la familia sale el ingreso con su tipo' );
	$ok( '' !== FamiliasTable::por_que_no_borrar( FamiliasTable::obtener( $fam ) ), 'Una familia con movimientos asociados no se puede eliminar' );

	echo "\n== C. Deshacer ==\n";
	$ok( true === Conciliacion::deshacer( (int) $ing['id'] ), 'Deshacer lo devuelve a pendiente' );
	$d = BancoTable::obtener( (int) $ing['id'] );
	$ok( BancoTable::PENDIENTE === $d['estado'] && null === $d['familia_id'] && null === $d['evento_id'], 'Y le quita la familia y el evento' );
	$ok( ! BancoTable::revisados_de_familia( $fam ), 'La familia ya no lo tiene' );
} catch ( Throwable $e ) {
	++$fails;
	echo 'FAIL excepcion: ', $e->getMessage(), ' (', basename( $e->getFile() ), ':', $e->getLine(), ")\n";
} finally {
	$wpdb->query( "DELETE FROM " . BancoTable::table() . " WHERE grupo = '999'" ); // phpcs:ignore WordPress.DB
	$wpdb->query( "DELETE FROM " . BancoTable::table_lotes() . " WHERE grupo = '999'" ); // phpcs:ignore WordPress.DB
	$wpdb->query( "DELETE FROM " . TiposMovimiento::table() . " WHERE grupo = '999'" ); // phpcs:ignore WordPress.DB
	$wpdb->query( "DELETE FROM " . EventosTable::table() . " WHERE grupo = '999'" ); // phpcs:ignore WordPress.DB
	$wpdb->query( "DELETE m FROM " . FamiliasTable::table_miembros() . " m INNER JOIN " . FamiliasTable::table() . " f ON f.id = m.familia_id WHERE f.grupo = '999'" ); // phpcs:ignore WordPress.DB
	$wpdb->query( "DELETE FROM " . FamiliasTable::table() . " WHERE grupo = '999'" ); // phpcs:ignore WordPress.DB
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id > %d', Logger::table(), $log_antes ) ); // phpcs:ignore WordPress.DB
	if ( null === $mods_antes ) {
		delete_option( 'asde_ap_modulos' );
	} else {
		update_option( 'asde_ap_modulos', $mods_antes, false );
	}
	Modulos::olvidar();
	wp_using_ext_object_cache( $cache_externa );
	Grupo::forzar( null );
}

echo $fails ? "\nHAY $fails FALLOS\n" : "\nTODO OK\n";
