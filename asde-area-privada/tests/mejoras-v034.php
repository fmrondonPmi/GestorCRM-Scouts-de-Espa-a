<?php
/**
 * Pruebas de la v0.34 (entorno LOCAL): buscador de Familias y «Socio/a desde» con
 * el contador de años, meses y días en la cabecera de la ficha. (El «+» para crear un tipo
 * desde «Marcar como revisado» del banco está en banco-gestion.php.) Ejecutar desde
 * app/public del site Local:
 *
 *   wp eval-file wp-content/plugins/asde-area-privada/tests/mejoras-v034.php
 *
 * Usa el CRM simulado de tests/lib (fichas inventadas: ninguna petición llega al CRM real)
 * y guarda las cachés solo en la memoria de este proceso. Al terminar se borra lo creado
 * (usuarios, familias, registros), por sus ids: nunca se vacía una tabla.
 */
defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/lib/CrmSimulado.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Auth\Ambito;
use AsdeBalears\AreaPrivada\Crm\Altas;
use AsdeBalears\AreaPrivada\Crm\Permisos;
use AsdeBalears\AreaPrivada\Domain\Grupo;
use AsdeBalears\AreaPrivada\Domain\Grupos;
use AsdeBalears\AreaPrivada\Frontend\Views\FamiliasView;
use AsdeBalears\AreaPrivada\Frontend\Views\FichaView;
use AsdeBalears\AreaPrivada\Install\Roles;
use AsdeBalears\AreaPrivada\Socios\SociosTable;
use AsdeBalears\AreaPrivada\Tesoreria\FamiliasTable;
use AsdeBalears\AreaPrivada\Usuarios\Usuarios;

global $wpdb;

$fails = 0;
$ok    = function ( $cond, string $label ) use ( &$fails ) {
	if ( ! $cond ) {
		++$fails;
	}
	echo ( $cond ? 'OK   ' : 'FAIL ' ), $label, "\n";
};

// ---------------------------------------------------------------------------
echo "\n== A. «Socio/a desde» (Altas::socio_desde) ==\n";
$m   = static fn( string $inicio, string $fin = '', string $estado = '' ): array => array(
	'start_date' => $inicio,
	'end_date'   => $fin,
	'estado_c'   => '' !== $estado ? $estado : ( '' === $fin ? 'activo' : 'baja' ),
);
$hoy = '2026-10-15';
$ok( '2018-09-12' === Altas::socio_desde( array( $m( '2018-09-12' ) ), $hoy ), 'una membresía activa: su alta' );
$ok( '2018-09-12' === Altas::socio_desde( array( $m( '2021-09-15' ), $m( '2018-09-12', '2021-09-15' ) ), $hoy ), 'un cambio de sección (cierra una y abre otra el mismo día) no reinicia la cuenta' );
$ok( '2016-09-01' === Altas::socio_desde( array( $m( '2021-09-15' ), $m( '2018-09-12', '2021-09-14' ), $m( '2016-09-01', '2018-09-11' ) ), $hoy ), 'ni si la nueva empieza al día siguiente; y se sigue hacia atrás' );
$ok( '2019-09-01' === Altas::socio_desde( array( $m( '2019-09-01' ), $m( '2015-09-01', '2017-06-30' ) ), $hoy ), 'quien se dio de baja y volvió cuenta desde la vuelta' );
$ok( '2020-01-01' === Altas::socio_desde( array( $m( '2020-01-01', '2027-01-01', 'Activo' ) ), $hoy ), 'una activa con la baja ya puesta para más adelante también cuenta' );
$ok( '' === Altas::socio_desde( array( $m( '2015-09-01', '2017-06-30' ) ), $hoy ) && '' === Altas::socio_desde( array(), $hoy ) && '' === Altas::socio_desde( array( $m( '2026-11-01' ) ), $hoy ), 'sin membresía activa (o una que aún no ha empezado): nada' );
// Fallo visto por el usuario: activa o no, como el CRM (por las fechas, no por el estado).
$ok( Altas::activa( $m( '2024-09-01', '', 'baja' ), $hoy ) && Altas::activa( $m( '2024-09-01', '2026-12-31', 'baja' ), $hoy ) && ! Altas::activa( $m( '2024-09-01', $hoy ), $hoy ) && ! Altas::activa( $m( '2024-09-01', '2026-10-01', 'activo' ), $hoy ), 'una membresía está activa por sus fechas, como en el CRM: en «baja» sin fecha (o con fecha futura) sigue activa; con la baja de hoy, no' );
$ok( '2024-09-01' === Altas::socio_desde( array( $m( '2024-09-01', '', 'baja' ) ), $hoy ), 'y «Socio/a desde» la cuenta igual' );

// ---------------------------------------------------------------------------
// Preparación: grupo, CRM simulado y cuentas.
// ---------------------------------------------------------------------------
$grupo = Grupos::por_numero( '684' ) ?? ( array_values( Grupos::todos() )[0] ?? null );
if ( ! $grupo || '' === $grupo['crm_id'] ) {
	exit( "Hace falta un grupo con la conexión al CRM comprobada.\n" );
}
foreach ( get_users( array( 'search' => 'prueba-v034-*', 'search_columns' => array( 'user_login' ) ) ) as $u ) {
	exit( 'Ya existe el usuario ' . $u->user_login . " (¿una ejecución anterior interrumpida?). Bórralo antes de repetir la prueba.\n" );
}
$max_id       = static fn( string $t ) => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(MAX(id), 0) FROM %i', $t ) );
$log_antes    = $max_id( Logger::table() );
$socios_antes = array_column( (array) $wpdb->get_results( 'SELECT * FROM ' . SociosTable::table(), ARRAY_A ), null, 'num_socio_asde' ); // phpcs:ignore WordPress.DB

$cache_externa = wp_using_ext_object_cache( true );
$cortar_crm    = static function ( $pre, $args, $url ) {
	return false === $pre && str_contains( (string) $url, '/rest.php' ) ? new WP_Error( 'prueba', 'CRM real cortado durante la prueba' ) : $pre;
};
add_filter( 'pre_http_request', $cortar_crm, 2, 3 );
add_filter( 'pre_wp_mail', '__return_true' ); // No sale ningún email.

$P     = array( 'Asde_Prueba_Crm', 'persona' );
$crm   = new Asde_Prueba_Crm(
	array(
		$P( 71, 'Nil', 'Antiguo Sala', '2010-05-05', 'Scouts' ),
		$P( 72, 'Ona', 'Buscadora Font', '2015-07-07', 'Lobatos' ),
		$P( 73, 'Pau', 'Buscadora Font', '2017-08-08', 'Castores' ),
		$P( 75, 'Sara', 'Secretaria Mir', '1990-01-01', 'Scouters' ),
		$P( 76, 'Joan', 'Tesorero Pons', '1985-02-02', 'Scouters' ),
	),
	$grupo['crm_id']
);
$id_de = static fn( int $n ): string => sprintf( 'de000000-0000-4000-8000-%012d', 9900 + $n );
// Nil: Lobatos del 12/09/2018 al 15/09/2021 y Scouts desde ese día (cambio de sección).
$crm->alta_membresia( $id_de( 71 ), 'Lobatos', '2018-09-12', '2021-09-15' );
$crm->alta_membresia( $id_de( 71 ), 'Scouts', '2021-09-15' );
$crm->alta_membresia( $id_de( 72 ), 'Lobatos', '2023-09-01' );
$crm->alta_membresia( $id_de( 73 ), 'Castores', '2024-09-01' );
$crm->alta_membresia( $id_de( 75 ), 'Scouters', '2015-09-01' );
$crm->alta_membresia( $id_de( 76 ), 'Scouters', '2016-09-01' );
Grupo::forzar( $grupo );
$crm->activar();
add_filter( 'asde_ap_instantanea_segundos', '__return_zero' ); // v0.35.1: sin la instantánea, para no dejar en la del grupo las fichas simuladas.
Permisos::olvidar( $grupo['numero'] );

$ids   = array();
$crear = static function ( string $login, string $rol, array $meta ) use ( &$ids, $grupo ): int {
	$id = wp_insert_user(
		array(
			'user_login' => $login,
			'user_email' => '',
			'user_pass'  => wp_generate_password( 24 ),
			'role'       => $rol,
		)
	);
	update_user_meta( $id, Usuarios::META_GRUPO, $grupo['id'] );
	update_user_meta( $id, Usuarios::META_ESTADO, Usuarios::ACTIVO );
	foreach ( $meta as $k => $v ) {
		add_user_meta( $id, $k, $v );
	}
	$ids[] = $id;
	return $id;
};
$u_secre = $crear( 'prueba-v034-secre', Roles::SECRETARIA, array( Usuarios::META_SOCIO => '990075' ) );
$u_teso  = $crear( 'prueba-v034-teso', Roles::TESORERIA, array( Usuarios::META_SOCIO => '990076' ) );
$u_fam   = $crear( 'prueba-v034-fam', Roles::TUTOR, array( Usuarios::META_REPRESENTADO => '990071' ) );
$como    = static function ( int $uid ): void {
	wp_set_current_user( $uid );
	Ambito::olvidar( $uid );
};
$ver = static function ( $vista, array $get ): string {
	$_GET = $get;
	try {
		return $vista->render();
	} finally {
		$_GET = array();
	}
};
$familias = array();

try {
	// -----------------------------------------------------------------------
	echo "\n== B. Ficha: «Socio/a desde» con el contador ==\n";
	$como( $u_secre );
	$h      = $ver( new FichaView(), array( 'socio' => '990071' ) );
	$t      = date_diff( date_create( '2018-09-12' ), date_create( wp_date( 'Y-m-d' ) ) );
	$cuenta = sprintf( '(%1$s, %2$s y %3$s)', sprintf( 1 === $t->y ? '%d año' : '%d años', $t->y ), sprintf( 1 === $t->m ? '%d mes' : '%d meses', $t->m ), sprintf( 1 === $t->d ? '%d día' : '%d días', $t->d ) );
	$ok( 1 === preg_match( '/asde-ficha__cabecera.*<dt>Socio\/a desde<\/dt>\s*<dd>\s*12\/09\/2018\s*<span class="asde-muted asde-ficha__antiguedad">' . preg_quote( esc_html( $cuenta ), '/' ) . '<\/span>/s', $h ), 'desde Personas, en la cabecera: «Socio/a desde 12/09/2018» (contando la sección anterior) y ' . $cuenta );
	$como( $u_fam );
	$h = $ver( new FichaView(), array( 'socio' => '990071' ) );
	$ok( str_contains( $h, 'Antiguo Sala' ) && ! str_contains( $h, 'Socio/a desde' ), 'en la ficha que ve la familia no sale (cuesta una consulta más al CRM y no se ha pedido)' );
	$como( $u_secre );
	$h = $ver( new FichaView(), array( 'socio' => '990075' ) );
	$ok( ! str_contains( $h, 'Socio/a desde' ), 'ni en la ficha propia' );

	// -----------------------------------------------------------------------
	echo "\n== C. Familias: buscador ==\n";
	$familias[] = (int) FamiliasTable::crear( array( '990071' ) );
	$familias[] = (int) FamiliasTable::crear( array( '990072', '990073' ) );
	$n1         = (string) FamiliasTable::obtener( $familias[0] )['numero'];
	$n2         = (string) FamiliasTable::obtener( $familias[1] )['numero'];
	$como( $u_teso );
	$h = $ver( new FamiliasView(), array() );
	$ok( str_contains( $h, 'data-asde-parcial-form="familias-lista"' ) && str_contains( $h, 'data-asde-parcial="familias-lista"' ) && 1 === preg_match( '/<input type="search" id="asde-q-familias" name="q" value=""[^>]*data-asde-buscar/', $h ), 'hay buscador, y solo cambia la lista (parcial.js, desde la 3.ª letra)' );
	$ok( str_contains( $h, $n1 ) && str_contains( $h, $n2 ) && ! str_contains( $h, ' de ' . count( FamiliasTable::todas() ) . ' familias' ), 'sin buscar salen todas (sin contador)' );
	$h = $ver( new FamiliasView(), array( 'q' => 'buscadora' ) );
	$ok( str_contains( $h, $n2 ) && ! str_contains( $h, '>' . $n1 . '<' ) && 1 === preg_match( '/1 de \d+ familias/', $h ), 'por nombre (sin mayúsculas): solo la familia de Ona y Pau, con «1 de N familias»' );
	$h = $ver( new FamiliasView(), array( 'q' => strtolower( $n1 ) ) );
	$ok( str_contains( $h, '>' . $n1 . '<' ) && ! str_contains( $h, '>' . $n2 . '<' ), 'por nº de familia' );
	$h = $ver( new FamiliasView(), array( 'q' => '990073' ) );
	$ok( str_contains( $h, '>' . $n2 . '<' ) && ! str_contains( $h, '>' . $n1 . '<' ), 'por nº de socio de un miembro' );
	$h = $ver( new FamiliasView(), array( 'q' => 'Antiguo Sala' ) );
	$ok( str_contains( $h, '>' . $n1 . '<' ) && ! str_contains( $h, '>' . $n2 . '<' ), 'con acentos o sin ellos y apellidos completos' );
	$h = $ver( new FamiliasView(), array( 'q' => 'zzzz-nadie' ) );
	$ok( str_contains( $h, 'Ninguna familia coincide con «zzzz-nadie».' ) && str_contains( $h, 'value="zzzz-nadie"' ), 'sin resultados: lo dice y conserva lo buscado' );
} catch ( Throwable $e ) {
	++$fails;
	echo 'FAIL excepcion: ', $e->getMessage(), ' (', basename( $e->getFile() ), ':', $e->getLine(), ")\n";
} finally {
	$crm->desactivar();
	remove_filter( 'asde_ap_instantanea_segundos', '__return_zero' );
	remove_filter( 'pre_http_request', $cortar_crm, 2 );
	remove_filter( 'pre_wp_mail', '__return_true' );
	wp_using_ext_object_cache( $cache_externa );
	wp_set_current_user( 0 );
	foreach ( array_filter( $familias ) as $fid ) {
		$wpdb->delete( FamiliasTable::table_miembros(), array( 'familia_id' => $fid ) );
		$wpdb->delete( FamiliasTable::table(), array( 'id' => $fid ) );
	}
	$lista = implode( ',', array_map( 'intval', $ids ) ) ?: '0';
	$wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE id > %d AND ( user_id IN ($lista) OR objeto_id IN ('990071','990072','990073','990075','990076') )", Logger::table(), $log_antes ) ); // phpcs:ignore WordPress.DB
	foreach ( $ids as $uid ) {
		delete_transient( 'asde_ap_notices_' . $uid );
		Ambito::olvidar( (int) $uid );
		wp_delete_user( (int) $uid );
	}
	$ahora = array_column( (array) $wpdb->get_results( 'SELECT * FROM ' . SociosTable::table(), ARRAY_A ), null, 'num_socio_asde' ); // phpcs:ignore WordPress.DB
	foreach ( array_diff_key( $ahora, $socios_antes ) as $asde => $f ) {
		$wpdb->delete( SociosTable::table(), array( 'num_socio_asde' => (string) $asde ) );
	}
	Permisos::olvidar( $grupo['numero'] );
	Grupo::forzar( null );

	$quedan = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users} WHERE user_login LIKE 'prueba-v034-%'" ); // phpcs:ignore WordPress.DB
	$filas  = $familias ? (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . FamiliasTable::table() . ' WHERE id IN (' . implode( ',', array_map( 'intval', $familias ) ) . ')' ) : 0; // phpcs:ignore WordPress.DB
	$ok( 0 === $quedan && 0 === $filas, 'limpieza: sin usuarios ni familias de la prueba' );
}

echo $fails ? "\n$fails FALLOS\n" : "\nTODO OK (nada tocado en el CRM real; el sitio queda como estaba)\n";
