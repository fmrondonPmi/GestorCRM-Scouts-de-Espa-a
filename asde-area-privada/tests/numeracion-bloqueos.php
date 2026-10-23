<?php
/**
 * Pruebas de la numeración (entorno LOCAL): bloqueos con nombre y números de familia.
 * Ejecutar desde app/public del site Local:
 *
 *   wp eval-file wp-content/plugins/asde-area-privada/tests/numeracion-bloqueos.php
 *
 * - Bloqueo: se toma y se suelta; si otra conexión lo tiene, tomar() devuelve false (no se sigue sin bloqueo).
 * - FamiliasTable::siguiente_numero cuenta solo las familias del propio grupo (el grupo 6841 no altera al 684).
 * - FamiliasTable::crear devuelve error (y no el id de otra fila) si no se consigue el bloqueo.
 * Usa grupos ficticios (9684 / 96841) y borra lo que crea.
 */
defined( 'ABSPATH' ) || exit;

use AsdeBalears\AreaPrivada\Domain\Bloqueo;
use AsdeBalears\AreaPrivada\Domain\Grupo;
use AsdeBalears\AreaPrivada\Tesoreria\FamiliasTable;

global $wpdb;
$fails = 0;
$ok    = function ( $cond, string $label ) use ( &$fails ) {
	if ( ! $cond ) {
		++$fails;
	}
	echo ( $cond ? 'OK   ' : 'FAIL ' ), $label, "\n";
};

$tabla = FamiliasTable::table();
if ( (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE grupo IN (%s, %s)', $tabla, '9684', '96841' ) ) > 0 ) { // phpcs:ignore WordPress.DB
	exit( "Quedan familias de los grupos ficticios 9684/96841 (¿una ejecución anterior interrumpida?). Bórralas antes de repetir la prueba.\n" );
}
$conexion_ajena = null;

try {
	// --- Bloqueo ---
	$ok( true === Bloqueo::tomar( 'asde_prueba_bloqueo', 1 ), 'se consigue un bloqueo libre' );
	Bloqueo::soltar( 'asde_prueba_bloqueo' );
	$ok( true === Bloqueo::tomar( 'asde_prueba_bloqueo', 1 ), 'tras soltarlo se puede volver a tomar' );
	Bloqueo::soltar( 'asde_prueba_bloqueo' );

	// --- Número de familia: cada grupo cuenta lo suyo ---
	$ahora = current_time( 'mysql', true );
	foreach ( array( array( 'F96841001', '96841' ), array( 'F96841002', '96841' ), array( 'F9684001', '9684' ) ) as $f ) {
		$wpdb->insert( $tabla, array( 'numero' => $f[0], 'grupo' => $f[1], 'creado' => $ahora, 'actualizado' => $ahora ) ); // phpcs:ignore WordPress.DB
	}
	$ok( 'F9684002' === FamiliasTable::siguiente_numero( '9684' ), 'el grupo 9684 sigue en F9684002 aunque el 96841 tenga F96841001 y F96841002' );
	$ok( 'F96841003' === FamiliasTable::siguiente_numero( '96841' ), 'el grupo 96841 sigue en su propia cuenta' );

	// --- crear() sin bloqueo: error, no el id de otra fila ---
	$conexion_ajena = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST ); // Otra conexión a la base de datos: el bloqueo es de conexión.
	$conexion_ajena->query( "SELECT GET_LOCK('asde_num_familia', 5)" );
	Grupo::forzar( array( 'numero' => '9684' ) );
	$filas_antes = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $tabla ) ); // phpcs:ignore WordPress.DB
	$wpdb->insert( $tabla, array( 'numero' => 'F96841099', 'grupo' => '96841', 'creado' => $ahora, 'actualizado' => $ahora ) ); // phpcs:ignore WordPress.DB — deja un insert_id previo
	++$filas_antes;
	$r = FamiliasTable::crear( array( '9684-PRUEBA-1' ) );
	$ok( is_wp_error( $r ), 'si otra conexión tiene el bloqueo, crear() devuelve error (no sigue sin bloqueo)' );
	$ok( $filas_antes === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $tabla ) ), 'y no crea ninguna familia' ); // phpcs:ignore WordPress.DB
} catch ( Throwable $e ) {
	++$fails;
	echo "FAIL excepcion: ", $e->getMessage(), " (", basename( $e->getFile() ), ":", $e->getLine(), ")
";
} finally {
	if ( $conexion_ajena ) {
		$conexion_ajena->query( "SELECT RELEASE_LOCK('asde_num_familia')" );
		$conexion_ajena->close();
	}
	Grupo::forzar( null );
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE grupo IN (%s, %s)', $tabla, '9684', '96841' ) ); // phpcs:ignore WordPress.DB
}

echo $fails ? "\n{$fails} FALLOS\n" : "\nTODO OK (el sitio queda como estaba)\n";
