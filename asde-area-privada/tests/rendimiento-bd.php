<?php
/**
 * Pruebas de las tres mejoras de consultas a la base de datos de WordPress (rama «optimizacion»):
 * `EventosTable::todos()` en memoria por petición (y su invalidación), `EventosTable::por_ids()`,
 * `Cuentas::personas()` con una sola consulta de cuentas y `Remesas::proximos_de_familias()`.
 * Ejecutar desde app/public del site Local:
 *
 *   wp eval-file <ruta completa>/wp-content/plugins/asde-area-privada/tests/rendimiento-bd.php
 *
 * No toca el CRM. Al terminar se borra lo creado.
 */
defined( 'ABSPATH' ) || exit;

use AsdeBalears\AreaPrivada\Domain\Grupos;
use AsdeBalears\AreaPrivada\Eventos\Cuentas;
use AsdeBalears\AreaPrivada\Eventos\EventosTable;
use AsdeBalears\AreaPrivada\Eventos\InscripcionesTable;
use AsdeBalears\AreaPrivada\Sepa\Remesas;
use AsdeBalears\AreaPrivada\Usuarios\Usuarios;

global $wpdb;

$fails = 0;
$ok    = function ( $cond, string $label ) use ( &$fails ) {
	if ( ! $cond ) {
		++$fails;
	}
	echo ( $cond ? 'OK   ' : 'FAIL ' ), $label, "\n";
};

$grupo = Grupos::por_numero( '684' ) ?? ( array_values( Grupos::todos() )[0] ?? null );
if ( ! $grupo ) {
	exit( "Hace falta un grupo.\n" );
}
foreach ( get_users( array( 'search' => 'prueba-rbd-*', 'search_columns' => array( 'user_login' ) ) ) as $u ) {
	exit( 'Ya existe el usuario ' . $u->user_login . " (¿una ejecución anterior interrumpida?). Bórralo antes de repetir la prueba.\n" );
}

$max_id    = static fn( string $t ) => (int) $wpdb->get_var( "SELECT COALESCE(MAX(id), 0) FROM $t" ); // phpcs:ignore WordPress.DB
$ev_antes  = $max_id( EventosTable::table() );
$ins_antes = $max_id( InscripcionesTable::table() );
$uids      = array();

try {
	// EventosTable::todos() en memoria.
	EventosTable::olvidar();
	$id1 = EventosTable::crear( array( 'tipo' => 'salida', 'nombre' => 'RBD uno', 'inicio' => '2999-05-01 10:00:00', 'fin' => '2999-05-01 18:00:00' ) );
	$id2 = EventosTable::crear( array( 'tipo' => 'salida', 'nombre' => 'RBD dos', 'inicio' => '2999-05-02 10:00:00', 'fin' => '2999-05-02 18:00:00' ) );
	EventosTable::todos(); // Calienta la memoria.
	$antes = $wpdb->num_queries;
	$todos = EventosTable::todos();
	$ok( $wpdb->num_queries === $antes && in_array( $id1, array_column( $todos, 'id' ), true ), 'todos(): la segunda lectura de la petición no consulta la base de datos' );

	EventosTable::guardar( $id1, array( 'nombre' => 'RBD uno cambiado' ) );
	$n = array_column( EventosTable::todos(), 'nombre', 'id' );
	$ok( 'RBD uno cambiado' === ( $n[ $id1 ] ?? '' ), 'guardar() vacía la memoria: se ve el cambio' );
	EventosTable::publicar( $id2 );
	$e = array_column( EventosTable::todos(), 'estado', 'id' );
	$ok( EventosTable::PUBLICADO === ( $e[ $id2 ] ?? '' ), 'los cambios de estado (publicar) vacían la memoria' );
	EventosTable::despublicar( $id2 );
	$id3 = EventosTable::crear( array( 'tipo' => 'salida', 'nombre' => 'RBD tres', 'inicio' => '2999-05-03 10:00:00', 'fin' => '2999-05-03 18:00:00' ) );
	$ok( in_array( $id3, array_column( EventosTable::todos(), 'id' ), true ), 'crear() vacía la memoria' );
	$ok( EventosTable::borrar( $id3 ) && ! in_array( $id3, array_column( EventosTable::todos(), 'id' ), true ), 'borrar() vacía la memoria' );

	// por_ids().
	$p = EventosTable::por_ids( array( $id2, $id1, $id1, 0 ) );
	$ok( array( $id1, $id2 ) === array_column( $p, 'id' ), 'por_ids(): solo los pedidos, sin repetir y en el orden de todos()' );
	EventosTable::olvidar();
	$antes = $wpdb->num_queries;
	$p2    = EventosTable::por_ids( array( $id1 ) );
	$ok( 1 === count( $p2 ) && $id1 === $p2[0]['id'] && $wpdb->num_queries - $antes <= 2, 'por_ids() sin memoria hace una sola consulta' );
	$ok( array() === EventosTable::por_ids( array() ), 'por_ids() sin ids no consulta ni devuelve nada' );

	// Cuentas::personas(): una cuenta con socio en el equipo, otro socio sin cuenta.
	$uid = wp_insert_user( array( 'user_login' => 'prueba-rbd-1', 'user_pass' => wp_generate_password(), 'user_email' => 'prueba-rbd-1@example.invalid' ) );
	if ( is_wp_error( $uid ) ) {
		exit( 'No se pudo crear el usuario: ' . $uid->get_error_message() . "\n" );
	}
	$uids[] = (int) $uid;
	update_user_meta( $uid, Usuarios::META_SOCIO, '990771' );
	InscripcionesTable::anadir( $id1, '990771', array( 'scouters' ), true, 'director' );
	InscripcionesTable::anadir( $id1, '990772', array( 'scouters' ), true, 'monitor' );
	$ok( array( (int) $uid => '990771' ) === Cuentas::personas( EventosTable::obtener( $id1 ) ), 'Cuentas::personas(): user_id => nº de socio solo de quien tiene cuenta' );
	$ok( array() === Cuentas::personas( EventosTable::obtener( $id2 ) ), 'Cuentas::personas(): un equipo vacío da una lista vacía' );

	// Remesas::proximos_de_familias(): sin remesas del grupo con adeudos de estas familias, vacías pero presentes.
	$lote = Remesas::proximos_de_familias( array( 987001, 987002 ) );
	$ok( array( 987001 => array(), 987002 => array() ) === $lote, 'proximos_de_familias(): cada familia pedida sale, aunque sin adeudos' );
	$ok( $lote[987001] === Remesas::proximos_de_familia( 987001 ) && array() === Remesas::proximos_de_familias( array() ), 'proximos_de_familia() delega en la versión en lote' );
} finally {
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . InscripcionesTable::table() . ' WHERE id > %d', $ins_antes ) ); // phpcs:ignore WordPress.DB
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . EventosTable::table() . ' WHERE id > %d', $ev_antes ) ); // phpcs:ignore WordPress.DB
	EventosTable::olvidar();
	require_once ABSPATH . 'wp-admin/includes/user.php';
	foreach ( $uids as $uid ) {
		wp_delete_user( (int) $uid );
	}
}

echo $fails ? "\nHAY FALLOS: $fails\n" : "\nTODO OK\n";
