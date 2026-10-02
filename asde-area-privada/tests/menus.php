<?php
/**
 * Pruebas de la v0.15 (entorno LOCAL): menús separados por tipo de usuario.
 * Ejecutar desde app/public del site Local:
 *
 *   wp eval-file <ruta completa>/wp-content/plugins/asde-area-privada/tests/menus.php
 *
 * Usa el CRM simulado de tests/lib (fichas inventadas: ninguna petición llega al
 * CRM real). Al terminar se borra lo creado y se restaura la tabla de números.
 */
defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/lib/CrmSimulado.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Auth\Ambito;
use AsdeBalears\AreaPrivada\Domain\Grupo;
use AsdeBalears\AreaPrivada\Domain\Grupos;
use AsdeBalears\AreaPrivada\Frontend\Menu;
use AsdeBalears\AreaPrivada\Frontend\Views\AreaPrivadaView;
use AsdeBalears\AreaPrivada\Frontend\Views\UniformeView;
use AsdeBalears\AreaPrivada\Frontend\Views\View;
use AsdeBalears\AreaPrivada\Install\Roles;
use AsdeBalears\AreaPrivada\Socios\SociosTable;
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
foreach ( get_users( array( 'search' => 'prueba-menu-*', 'search_columns' => array( 'user_login' ) ) ) as $u ) {
	exit( 'Ya existe el usuario ' . $u->user_login . " (¿una ejecución anterior interrumpida?). Bórralo antes de repetir la prueba.\n" );
}
wp_set_current_user( 0 );

$log_antes    = (int) $wpdb->get_var( 'SELECT COALESCE(MAX(id), 0) FROM ' . Logger::table() ); // phpcs:ignore WordPress.DB
$socios_antes = array_column( (array) $wpdb->get_results( 'SELECT * FROM ' . SociosTable::table(), ARRAY_A ), null, 'num_socio_asde' ); // phpcs:ignore WordPress.DB

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

$P   = array( 'Asde_Prueba_Crm', 'persona' );
$crm = new Asde_Prueba_Crm(
	array(
		$P( 31, 'Leo', 'Menu Sanz', '2016-05-10', 'Lobatos' ),
		$P( 32, 'Pol', 'Menu Mas', '2011-03-03', 'Escultas' ),
		$P( 33, 'Eva', 'Menu Gil', '1990-01-01', 'Scouters' ),
		$P( 34, 'Ana', 'Menu Coll', '1980-08-08', 'Scouters' ),
	),
	$grupo['crm_id']
);
$falsos = array_map( static fn( $p ) => $p['numerosocio_c'], $crm->personas );
Grupo::forzar( $grupo );
$crm->activar();

$ids   = array();
$crear = static function ( string $login, array $roles, array $meta ) use ( &$ids, $grupo ): int {
	$id = wp_insert_user(
		array(
			'user_login' => $login,
			'user_email' => '',
			'user_pass'  => wp_generate_password( 24 ),
			'role'       => $roles[0],
		)
	);
	$u = get_userdata( $id );
	foreach ( array_slice( $roles, 1 ) as $r ) {
		$u->add_role( $r );
	}
	update_user_meta( $id, Usuarios::META_GRUPO, $grupo['id'] );
	update_user_meta( $id, Usuarios::META_ESTADO, Usuarios::ACTIVO );
	foreach ( $meta as $k => $v ) {
		foreach ( (array) $v as $valor ) {
			add_user_meta( $id, $k, $valor );
		}
	}
	$ids[ $login ] = $id;
	return $id;
};
$u_fam   = $crear( 'prueba-menu-fam', array( Roles::TUTOR ), array( Usuarios::META_REPRESENTADO => '990031' ) );
$u_pol   = $crear( 'prueba-menu-pol', array( Roles::PARTICIPANTE ), array( Usuarios::META_SOCIO => '990032' ) );
$u_sco   = $crear( 'prueba-menu-sco', array( Roles::SCOUTER ), array( Usuarios::META_SOCIO => '990033', Usuarios::META_SECCION => 'lobatos' ) );
$u_coord = $crear( 'prueba-menu-coord', array( Roles::SCOUTER, Roles::COORDINACION ), array( Usuarios::META_SOCIO => '990034', Usuarios::META_SECCION => 'rovers' ) );
$u_mixto = $crear( 'prueba-menu-mixto', array( Roles::SCOUTER, Roles::TUTOR ), array( Usuarios::META_SOCIO => '990033', Usuarios::META_REPRESENTADO => '990031', Usuarios::META_SECCION => 'lobatos' ) );
$u_admin = $crear( 'prueba-menu-admin', array( 'administrator' ), array() );

$como = static function ( int $uid ): Ambito {
	wp_set_current_user( $uid );
	Ambito::olvidar( $uid );
	return Ambito::actual();
};
$claves = static fn( array $items ): array => array_keys( $items );

try {
	echo "== Parte A · Familias y participantes: solo su espacio ==\n";
	$g = Menu::grupos( $como( $u_fam ) );
	$ok( array( Menu::PERSONAL ) === array_keys( $g ), 'una familia (tutor) tiene un solo grupo de menú: lo personal' );
	$ok( array( 'ficha', 'pagos', 'uniforme' ) === $claves( $g[ Menu::PERSONAL ]['items'] ) && 'Mi familia' === $g[ Menu::PERSONAL ]['titulo'] && 'Mi familia' === $g[ Menu::PERSONAL ]['items']['ficha'] && 'Uniforme' === $g[ Menu::PERSONAL ]['items']['uniforme'], 'sus entradas: Mi familia, Mis pagos y Uniforme' );
	$g = Menu::grupos( $como( $u_pol ) );
	$ok( array( Menu::PERSONAL ) === array_keys( $g ) && 'Mi espacio' === $g[ Menu::PERSONAL ]['titulo'] && 'Mis datos' === $g[ Menu::PERSONAL ]['items']['ficha'], 'un participante: Mi espacio con Mis datos, pagos y uniforme' );
	$tabs = View::pestanas( $como( $u_fam ) );
	$ok( array( 'area', 'ficha', 'pagos', 'uniforme' ) === $claves( $tabs ), 'la lista plana de pestañas de la familia (Inicio delante) no trae nada de gestión' );

	echo "\n== Parte B · Scouters: su espacio y la gestión, por separado ==\n";
	$g = Menu::grupos( $como( $u_sco ) );
	$ok( array( Menu::PERSONAL, Menu::GESTION ) === array_keys( $g ), 'un scouter tiene dos grupos: lo suyo y la gestión del grupo' );
	$ok( 'Mi uniforme' === $g[ Menu::PERSONAL ]['items']['uniforme'] && isset( $g[ Menu::PERSONAL ]['items']['ficha'], $g[ Menu::PERSONAL ]['items']['pagos'] ), 'en lo suyo: Mis datos, Mis pagos y Mi uniforme (pide el suyo)' );
	$gi = $g[ Menu::GESTION ]['items'];
	$ok( 'Gestión del grupo' === $g[ Menu::GESTION ]['titulo'] && isset( $gi['solicitudes'], $gi['cambios'], $gi['informes'], $gi['tesoreria'], $gi['uniformes'] ) && str_starts_with( $gi['uniformes'], 'Gestión de uniformes' ), 'en la gestión: Solicitudes, Cambios, Informes, Tesorería y Gestión de uniformes' );
	$ok( ! isset( $gi['personas'] ) && ! isset( $gi['usuarios'] ) && ! isset( $g[ Menu::PERSONAL ]['items']['personas'] ), 'sin Personas ni Usuarios (eso es de Secretaría y Coordinación) y sin mezclar gestión en lo personal' );
	$g = Menu::grupos( $como( $u_coord ) );
	$ok( isset( $g[ Menu::GESTION ]['items']['personas'], $g[ Menu::GESTION ]['items']['usuarios'] ) && ! isset( $g[ Menu::PERSONAL ]['items']['personas'] ), 'Coordinación añade Personas y Usuarios, siempre dentro de la gestión' );
	$g = Menu::grupos( $como( $u_mixto ) );
	$ok( 'Mi espacio' === $g[ Menu::PERSONAL ]['titulo'] && isset( $g[ Menu::GESTION ] ), 'un scouter que además es tutor tiene su espacio y la gestión' );

	echo "\n== Parte C · Sin ficha (administrador): solo la gestión ==\n";
	$g = Menu::grupos( $como( $u_admin ) );
	$ok( array( Menu::GESTION ) === array_keys( $g ) && isset( $g[ Menu::GESTION ]['items']['personas'], $g[ Menu::GESTION ]['items']['usuarios'], $g[ Menu::GESTION ]['items']['tesoreria'] ), 'un administrador sin ficha ve solo la gestión del grupo (no tiene «Mis datos» ni uniforme propio)' );
	wp_set_current_user( 0 );
	$ok( ! Menu::grupos( null ) && array( 'area' => 'Inicio' ) === Menu::plano( null ), 'sin usuario: ningún grupo y solo «Inicio»' );

	echo "\n== Parte D · Cómo se pinta ==\n";
	$como( $u_fam );
	$h = ( new AreaPrivadaView() )->render();
	$ok( ! str_contains( $h, 'asde-nav__grupo' ) && ! str_contains( $h, 'asde-tiles__titulo' ), 'la familia no ve rótulos de grupo (solo hay uno) ni títulos de bloque en el inicio' );
	$ok( str_contains( $h, 'Mi familia' ) && ! str_contains( $h, 'Gestión del grupo' ) && ! str_contains( $h, 'Solicitudes de acceso' ) && ! str_contains( $h, '<strong>Informes</strong>' ) && ! str_contains( $h, '<strong>Tesorería</strong>' ) && ! preg_match( '/>\s*(Informes|Tesorería|Personas|Usuarios)\s*</', $h ), 'ni pestañas ni atajos de gestión en su inicio' );
	$como( $u_sco );
	$h = ( new AreaPrivadaView() )->render();
	$ok( 2 === substr_count( $h, 'class="asde-nav__grupo"' ) && str_contains( $h, '>Mi espacio<' ) && str_contains( $h, '>Gestión del grupo<' ), 'el scouter ve el menú con los dos rótulos: Mi espacio y Gestión del grupo' );
	$ok( str_contains( $h, '>Para ti</h3>' ) && str_contains( $h, '>Gestión del grupo</h3>' ) && str_contains( $h, '<strong>Mi uniforme</strong>' ) && str_contains( $h, '<strong>Gestión de uniformes</strong>' ), 'y su inicio, dos bloques: «Para ti» (con Mi uniforme) y «Gestión del grupo» (con Gestión de uniformes)' );
	$posicion_nav = strpos( $h, 'asde-nav__tabs' );
	$p_datos      = strpos( $h, 'Mis datos', (int) $posicion_nav );
	$p_gestion    = strpos( $h, '<span>Gestión del grupo</span>', (int) $posicion_nav );
	$p_informes   = strpos( $h, 'Informes', (int) $p_gestion );
	$ok( false !== $posicion_nav && false !== $p_datos && false !== $p_gestion && false !== $p_informes && $p_datos < $p_gestion && $p_gestion < $p_informes, 'en el menú, lo personal va antes que la gestión' );
	$como( $u_admin );
	$h = ( new AreaPrivadaView() )->render();
	$ok( str_contains( $h, 'Usuarios del área privada' ) || str_contains( $h, 'Dar acceso' ), 'el administrador ve las pantallas de gestión en su inicio' );
	$ok( ! str_contains( $h, 'asde-nav__grupo' ) && ! str_contains( $h, 'asde-tiles__titulo' ), 'y, al tener un solo grupo, tampoco rótulos' );

	echo "\n== Parte E · Las páginas no cambian de permisos ==\n";
	$como( $u_fam );
	$ok( str_contains( ( new \AsdeBalears\AreaPrivada\Frontend\Views\UniformesGestionView() )->render(), 'No tienes acceso' ), 'una familia sigue sin entrar en la gestión de uniformes aunque se sepa la dirección' );
	$como( $u_sco );
	$ok( str_contains( ( new UniformeView() )->render(), 'name="asde_action" value="uniforme_pedir"' ), 'el scouter entra en «Mi uniforme» y ve el formulario del pedido' );
	$ok( $crm->llamadas > 0 && 0 === $cortadas, "todas las consultas al CRM las ha respondido el simulador ({$crm->llamadas}); ninguna ha salido hacia el CRM real" );
} finally {
	// Limpieza: todo lo creado fuera; lo tocado, como estaba.
	$_POST = array();
	View::en_modal( false );
	$crm->desactivar();
	remove_filter( 'pre_http_request', $cortar_crm, 2 );
	wp_using_ext_object_cache( $cache_externa );
	wp_set_current_user( 0 );

	$lista = implode( ',', array_map( 'intval', $ids ) ) ?: '0';
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Logger::table() . " WHERE id > %d AND user_id IN ($lista)", $log_antes ) ); // phpcs:ignore WordPress.DB
	foreach ( $ids as $uid ) {
		Ambito::olvidar( (int) $uid );
		wp_delete_user( (int) $uid );
	}
	$ahora = array_column( (array) $wpdb->get_results( 'SELECT * FROM ' . SociosTable::table(), ARRAY_A ), null, 'num_socio_asde' ); // phpcs:ignore WordPress.DB
	foreach ( array_diff_key( $ahora, $socios_antes ) as $asde => $f ) {
		$wpdb->delete( SociosTable::table(), array( 'num_socio_asde' => (string) $asde ) );
	}
	foreach ( $socios_antes as $asde => $f ) {
		if ( ( $ahora[ $asde ] ?? null ) !== $f ) {
			$wpdb->replace( SociosTable::table(), $f );
		}
	}
	Grupo::forzar( null );

	$quedan = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users} WHERE user_login LIKE 'prueba-menu-%'" ); // phpcs:ignore WordPress.DB
	$ok( 0 === $quedan, 'limpieza: sin usuarios de la prueba' );
}

echo $fails ? "\n$fails FALLOS\n" : "\nTODO OK (nada tocado en el CRM real; el sitio queda como estaba)\n";
