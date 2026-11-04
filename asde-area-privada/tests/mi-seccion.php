<?php
/**
 * Pruebas de «Mi sección» (v0.63, doc 65; entorno LOCAL). Ejecutar desde app/public del site Local:
 *
 *   wp eval-file <ruta completa>/wp-content/plugins/asde-area-privada/tests/mi-seccion.php
 *
 * CRM simulado (tests/lib, fichas inventadas): ninguna petición llega al CRM real. Al terminar se borra lo
 * creado (revisiones, usuarios, registros) y se restauran los módulos del grupo.
 */
defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/lib/CrmSimulado.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Auth\Ambito;
use AsdeBalears\AreaPrivada\Crm\Crypto;
use AsdeBalears\AreaPrivada\Domain\Grupo;
use AsdeBalears\AreaPrivada\Domain\Grupos;
use AsdeBalears\AreaPrivada\Domain\Modulos;
use AsdeBalears\AreaPrivada\Frontend\Views\MiSeccionView;
use AsdeBalears\AreaPrivada\Install\Roles;
use AsdeBalears\AreaPrivada\Seguimiento\Catalogo;
use AsdeBalears\AreaPrivada\Seguimiento\Revisiones;
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
if ( ! $grupo || '' === $grupo['crm_id'] ) {
	exit( "Hace falta un grupo con la conexión al CRM comprobada.\n" );
}
foreach ( get_users( array( 'search' => 'prueba-seg-*', 'search_columns' => array( 'user_login' ) ) ) as $u ) {
	exit( 'Ya existe el usuario ' . $u->user_login . " (¿una ejecución anterior interrumpida?). Bórralo antes de repetir la prueba.\n" );
}
wp_set_current_user( 0 );
Roles::install();
Revisiones::install_table();

// --- Lo que se restaura al final ---------------------------------------------
$max_id        = static fn( string $t ) => (int) $wpdb->get_var( "SELECT COALESCE(MAX(id), 0) FROM $t" ); // phpcs:ignore WordPress.DB
$rev_antes     = $max_id( Revisiones::table() );
$log_antes     = $max_id( Logger::table() );
$modulos_antes = get_option( 'asde_ap_modulos', null );
$cache_externa = wp_using_ext_object_cache( true );
$cortar_crm    = static fn( $pre, $args, $url ) => false === $pre && str_contains( (string) $url, '/rest.php' ) ? new WP_Error( 'prueba', 'CRM real cortado durante la prueba' ) : $pre;
add_filter( 'pre_http_request', $cortar_crm, 2, 3 );

$P   = array( 'Asde_Prueba_Crm', 'persona' );
$crm = new Asde_Prueba_Crm(
	array(
		$P( 41, 'Pau', 'Seguimiento Coll', '2013-07-07', 'Scouts' ),
		$P( 42, 'Eli', 'Seguimiento Rey', '2012-03-03', 'Scouts' ),
		$P( 43, 'Leo', 'Seguimiento Gil', '2016-05-05', 'Lobatos' ),
		$P( 90, 'Sol', 'Seguimiento Scouter', '1990-01-01', 'Scouters' ),
		$P( 91, 'Cora', 'Seguimiento Coord', '1985-05-05', 'Scouters' ),
		$P( 92, 'Sara', 'Seguimiento Secre', '1980-08-08', 'Scouters' ),
	),
	$grupo['crm_id']
);
Grupo::forzar( $grupo );
$crm->activar();
Modulos::guardar( (string) $grupo['numero'], array_keys( Modulos::lista() ) ); // Todos encendidos.

$ids   = array();
$crear = static function ( string $login, string $rol, array $meta = array() ) use ( &$ids, $grupo ): int {
	$id = wp_insert_user(
		array(
			'user_login' => $login,
			'user_email' => $login . '@example.invalid',
			'user_pass'  => wp_generate_password( 24 ),
			'role'       => $rol,
		)
	);
	update_user_meta( $id, Usuarios::META_GRUPO, $grupo['id'] );
	update_user_meta( $id, Usuarios::META_ESTADO, Usuarios::ACTIVO );
	foreach ( $meta as $k => $v ) {
		add_user_meta( $id, $k, $v );
	}
	$ids[ $login ] = $id;
	return $id;
};
$u_sc    = $crear( 'prueba-seg-sc', Roles::SCOUTER, array( Usuarios::META_SOCIO => '990090', Usuarios::META_SECCION => 'scouts' ) );
$u_coord = $crear( 'prueba-seg-coord', Roles::COORDINACION, array( Usuarios::META_SOCIO => '990091' ) );
$u_secre = $crear( 'prueba-seg-secre', Roles::SECRETARIA, array( Usuarios::META_SOCIO => '990092' ) );
$u_fam   = $crear( 'prueba-seg-fam', Roles::TUTOR, array( Usuarios::META_REPRESENTADO => '990041' ) );

$como = static function ( int $uid ): void {
	wp_set_current_user( $uid );
	Ambito::olvidar( $uid );
	Modulos::olvidar();
};
add_filter( 'wp_redirect', static fn( $d ) => throw new RuntimeException( 'asde-redirect:' . $d ), 1 );
$morir = static fn() => static fn() => throw new RuntimeException( 'asde-die' ); // wp_die() no corta la prueba.
add_filter( 'wp_die_handler', $morir );
$ver = static function ( array $get = array() ): string {
	$_GET = $_REQUEST = $get;
	return ( new MiSeccionView() )->render();
};
$enviar = static function ( array $campos ): void {
	$_POST    = $campos + array( 'asde_action' => 'guardar_seguimiento', '_wpnonce' => wp_create_nonce( 'asde_seguimiento' ) );
	$_REQUEST = $_POST;
	try {
		( new MiSeccionView() )->handle_post();
	} catch ( RuntimeException $e ) {
		// Redirige al terminar.
	} finally {
		$_POST = $_REQUEST = array();
	}
};
$filas = static fn(): int => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE id > %d', Revisiones::table(), $rev_antes ) ); // phpcs:ignore WordPress.DB
$pau   = '990041';

try {
	echo "== Parte A · Quién puede qué ==\n";
	$como( $u_sc );
	$ok( current_user_can( MiSeccionView::CAP ) && current_user_can( MiSeccionView::CAP_EDITAR ), 'un scouter ve y revisa' );
	$como( $u_coord );
	$ok( current_user_can( MiSeccionView::CAP ) && ! current_user_can( MiSeccionView::CAP_EDITAR ), 'Coordinación solo ve' );
	$como( $u_secre );
	$ok( ! current_user_can( MiSeccionView::CAP ), 'Secretaría no entra' );
	$como( $u_fam );
	$ok( ! current_user_can( MiSeccionView::CAP ), 'una familia no entra' );

	echo "== Parte B · Catálogo del programa ==\n";
	$cuentan = array( 'castores' => 9, 'lobatos' => 8, 'scouts' => 8, 'escultas' => 8, 'rovers' => 9 );
	foreach ( $cuentan as $s => $n ) {
		$a = Catalogo::aspectos( $s );
		$ok( count( $a ) === $n && ! array_filter( $a, static fn( $x ) => 3 !== count( array_filter( $x['e'] ) ) ), "$s: $n aspectos con sus 3 etapas" );
	}
	$lobato = Catalogo::anio( 'lobatos', 8 );
	$ok( 1 === $lobato['n'] && 3 === $lobato['de'] && ! $lobato['fuera'], 'Lobatos con 8 años: 1.º de 3' );
	$ok( 3 === Catalogo::anio( 'lobatos', 10 )['n'], 'Lobatos con 10 años: 3.º' );
	$ok( Catalogo::anio( 'lobatos', 13 )['fuera'] && 3 === Catalogo::anio( 'lobatos', 13 )['n'], 'adelantada o retrasada: año acotado y marcada' );
	$ok( 4 === Catalogo::anio( 'rovers', 20 )['n'] && null === Catalogo::anio( 'rovers', null ), 'Rovers: 4 años; sin edad no se calcula' );

	echo "== Parte C · Pantallas ==\n";
	$como( $u_sc );
	$html = $ver();
	$ok( str_contains( $html, 'Pau Seguimiento Coll' ) && str_contains( $html, 'Eli Seguimiento Rey' ), 'el listado enseña a su sección' );
	$ok( ! str_contains( $html, 'Leo Seguimiento Gil' ), 'no enseña a otra sección' );
	$ok( ! str_contains( $ver( array( 'seccion' => 'lobatos' ) ), 'Leo Seguimiento Gil' ), 'pedir otra sección no la abre' );
	$ficha = $ver( array( 'persona' => $pau ) );
	$ok( 8 === substr_count( $ficha, 'asde-seguimiento__aspecto"' ) && str_contains( $ficha, 'button-primary' ), 'la ficha lleva sus 8 aspectos y el botón de guardar' );
	$ok( ! str_contains( $ver( array( 'persona' => '990043' ) ), 'Leo Seguimiento Gil' ), 'la ficha de otra sección no se abre' );
	$como( $u_coord );
	$ficha = $ver( array( 'persona' => $pau, 'seccion' => 'scouts' ) );
	$ok( str_contains( $ficha, 'Pau Seguimiento Coll' ) && ! str_contains( $ficha, 'button-primary' ), 'Coordinación abre la ficha de cualquier sección, sin botón de guardar' );

	echo "== Parte D · Guardar ==\n";
	$como( $u_coord );
	$enviar( array( 'persona' => $pau, 'seccion' => 'scouts', 'etapa' => '1', 'estado' => array( 0 => '2' ) ) );
	$ok( 0 === $filas(), 'Coordinación no puede guardar' );
	$como( $u_sc );
	$enviar( array( 'persona' => '990043', 'seccion' => 'lobatos', 'etapa' => '1', 'estado' => array( 0 => '2' ) ) );
	$ok( 0 === $filas(), 'no se guarda a alguien de otra sección' );
	$enviar( array( 'persona' => $pau, 'seccion' => 'scouts', 'etapa' => '1', 'estado' => array( 0 => '2', 1 => '1', 2 => '9' ), 'nota' => array( 1 => 'Le cuesta el campamento' ), 'objetivo' => 'Especialidad de cocina', 'propuesta' => '1' ) );
	$h   = Revisiones::huella( (string) Grupo::numero(), $pau );
	$act = Revisiones::actuales( (string) Grupo::numero(), 'scouts' )[ $h ] ?? null;
	$ok( 1 === $filas() && $act, 'se guarda la revisión' );
	$ok( array( 2, 1, 2, 0, 0, 0, 0, 0 ) === ( $act['estados'] ?? array() ), 'estados guardados (un 9 se queda en el máximo)' );
	$ok( 'Especialidad de cocina' === ( $act['datos']['objetivo'] ?? '' ) && 'Le cuesta el campamento' === ( $act['datos']['notas'][1] ?? '' ) && 1 === (int) $act['propuesta'], 'objetivo, nota y propuesta' );
	$fila = (array) $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id > %d', Revisiones::table(), $rev_antes ), ARRAY_A ); // phpcs:ignore WordPress.DB
	$ok( ! str_contains( implode( '|', $fila ), $pau ) && ! str_contains( (string) $fila['datos_enc'], 'cocina' ) && Crypto::decrypt( (string) $fila['datos_enc'] ), 'sin el nº de socio ni el texto en claro: huella y cifrado' );
	$enviar( array( 'persona' => $pau, 'seccion' => 'scouts', 'etapa' => '2', 'estado' => array( 0 => '2', 1 => '2' ) ) );
	$act = Revisiones::actuales( (string) Grupo::numero(), 'scouts' )[ $h ];
	$ok( 2 === $filas() && 2 === (int) $act['etapa'] && 0 === array_sum( $act['estados'] ), 'al cambiar de etapa los aspectos empiezan de cero' );
	$ok( 2 === count( Revisiones::historial( (string) Grupo::numero(), $h, 'scouts' ) ), 'el histórico conserva las dos revisiones' );
	$ok( 1 === count( Revisiones::actuales( (string) Grupo::numero(), 'scouts' ) ) && ! isset( Revisiones::actuales( 'xxx', 'scouts' )[ $h ] ), 'lo actual es lo último y no cruza de grupo' );

	echo "== Parte E · Módulo apagado ==\n";
	Modulos::guardar( (string) $grupo['numero'], array_diff( array_keys( Modulos::lista() ), array( Modulos::MI_SECCION ) ) );
	$como( $u_sc );
	$ok( ! current_user_can( MiSeccionView::CAP ) && ! current_user_can( MiSeccionView::CAP_EDITAR ), 'apagado, nadie entra' );
} catch ( Throwable $e ) {
	++$fails;
	echo 'FAIL excepcion: ', $e->getMessage(), ' (', basename( $e->getFile() ), ':', $e->getLine(), ")\n";
} finally {
	remove_all_filters( 'wp_redirect' );
	remove_filter( 'wp_die_handler', $morir );
	$_GET = $_POST = $_REQUEST = array();
	$crm->desactivar();
	remove_filter( 'pre_http_request', $cortar_crm, 2 );
	wp_using_ext_object_cache( $cache_externa );
	wp_set_current_user( 0 );

	$lista = implode( ',', array_map( 'intval', $ids ) ) ?: '0';
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id > %d', Revisiones::table(), $rev_antes ) ); // phpcs:ignore WordPress.DB
	$wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE id > %d AND ( user_id IN ($lista) OR objeto = 'seguimiento' )", Logger::table(), $log_antes ) ); // phpcs:ignore WordPress.DB
	foreach ( $ids as $uid ) {
		Ambito::olvidar( (int) $uid );
		wp_delete_user( (int) $uid );
	}
	if ( null === $modulos_antes ) {
		delete_option( 'asde_ap_modulos' );
	} else {
		update_option( 'asde_ap_modulos', $modulos_antes, false );
	}
	Modulos::olvidar();
	Grupo::forzar( null );

	$quedan = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users} WHERE user_login LIKE 'prueba-seg-%'" ); // phpcs:ignore WordPress.DB
	$ok( 0 === $quedan && 0 === $filas(), 'el sitio queda como estaba' );
}

echo $fails ? "\nHAY $fails FALLOS\n" : "\nTODO OK (nada tocado en el CRM real; el sitio queda como estaba)\n";
