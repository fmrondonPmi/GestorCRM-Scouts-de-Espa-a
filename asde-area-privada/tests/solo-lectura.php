<?php
/**
 * Pruebas de la v0.50 (entorno LOCAL, doc 50, fase 3): solo lectura de la Oficina y selector de grupo.
 * Ejecutar desde app/public del site Local:
 *
 *   wp eval-file <ruta completa>/wp-content/plugins/asde-area-privada/tests/solo-lectura.php
 *
 * No toca el CRM. Crea usuarios `prueba-sl-*` y los borra; restaura el tipo de instalación y el grupo de trabajo.
 */
defined( 'ABSPATH' ) || exit;

require_once ABSPATH . 'wp-admin/includes/user.php';

use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Auth\SoloLectura;
use AsdeBalears\AreaPrivada\Crm\Escritura;
use AsdeBalears\AreaPrivada\Domain\Grupo;
use AsdeBalears\AreaPrivada\Domain\Grupos;
use AsdeBalears\AreaPrivada\Domain\Oficina;
use AsdeBalears\AreaPrivada\Domain\Organizacion;
use AsdeBalears\AreaPrivada\Frontend\TemplateLoader;
use AsdeBalears\AreaPrivada\Install\Roles;

$fails = 0;
$ok    = function ( $cond, string $label ) use ( &$fails ) {
	if ( ! $cond ) {
		++$fails;
	}
	echo ( $cond ? 'OK   ' : 'FAIL ' ), $label, "\n";
};

global $wpdb;
$antes_tipo    = get_option( Organizacion::TIPO_OPCION, null );
$antes_actual  = get_current_user_id();
$log_antes     = (int) $wpdb->get_var( 'SELECT COALESCE(MAX(id), 0) FROM ' . Logger::table() ); // phpcs:ignore WordPress.DB
$cache_externa = wp_using_ext_object_cache( true );
$uids         = array();
$crear        = static function ( string $login, string $rol ) use ( &$uids ): int {
	$viejo = get_user_by( 'login', $login );
	if ( $viejo ) {
		wp_delete_user( $viejo->ID );
	}
	$id = wp_insert_user( array( 'user_login' => $login, 'user_pass' => wp_generate_password( 24 ), 'user_email' => $login . '@example.invalid', 'role' => $rol ) );
	$uids[] = (int) $id;
	return (int) $id;
};

try {
	Roles::install();
	Organizacion::guardar_tipo( Organizacion::TIPO_OFICINA );
	$of   = $crear( 'prueba-sl-oficina', Roles::OFICINA );
	$scou = $crear( 'prueba-sl-scouter', Roles::SCOUTER );

	// La lista blanca y el bloqueo.
	wp_set_current_user( 0 );
	$ok( false === SoloLectura::activo() && false === SoloLectura::bloquea( 'cualquiera' ), 'Sin sesión no hay cuenta de solo lectura' );
	wp_set_current_user( $scou );
	$ok( false === SoloLectura::activo() && false === SoloLectura::bloquea( 'guardar_ficha' ), 'Un scouter no es de solo lectura' );
	wp_set_current_user( $of );
	$ok( true === SoloLectura::activo(), 'La oficina es de solo lectura' );
	$ok( false === SoloLectura::bloquea( 'oficina_cambiar_grupo' ), 'La oficina puede elegir grupo' );
	$bloqueadas = array( 'guardar_ficha', 'aprobar', 'validar_pago', 'tienda_config', 'registrar_ajuste', 'crear_usuario', 'cualquier-otra-cosa', '' );
	$pasan      = array_filter( $bloqueadas, static fn( $a ) => ! SoloLectura::bloquea( $a ) );
	$ok( ! $pasan, 'Cualquier otra acción de formulario queda bloqueada para la oficina' );

	// Todas las acciones reales del área (las que escriben las plantillas) están bloqueadas salvo la lista blanca.
	$acciones = array();
	foreach ( glob( ASDE_AP_DIR . 'templates/{,*/}*.php', GLOB_BRACE ) as $f ) {
		if ( preg_match_all( '/name="asde_action"\s+value="([a-z_0-9]+)"/', (string) file_get_contents( $f ), $m ) ) {
			$acciones = array_merge( $acciones, $m[1] );
		}
	}
	$acciones = array_values( array_unique( $acciones ) );
	$libres   = array_filter( $acciones, static fn( $a ) => ! SoloLectura::bloquea( $a ) && ! in_array( $a, SoloLectura::PERMITIDAS, true ) );
	$ok( count( $acciones ) > 50 && ! $libres, 'Las ' . count( $acciones ) . ' acciones de formulario de las plantillas están bloqueadas para la oficina' );

	// El Router aplica el bloqueo antes de entregar el envío a la vista.
	$router = (string) file_get_contents( ASDE_AP_DIR . 'src/Frontend/Router.php' );
	$p_gate = strpos( $router, 'SoloLectura::bloquea(' );
	$p_post = strpos( $router, '->handle_post()' );
	$ok( false !== $p_gate && false !== $p_post && $p_gate < $p_post, 'El Router rechaza el envío de una cuenta de solo lectura antes de llamar a handle_post()' );

	// Escritura en el CRM: se niega, incluso con el CRM simulado de las pruebas.
	require_once ASDE_AP_DIR . 'tests/lib/CrmSimulado.php';
	$crm = new Asde_Prueba_Crm( array(), 'crm-de-prueba' );
	Escritura::usar_para_pruebas( $crm );
	$ok( false === Escritura::disponible() && false === Escritura::puede_crear(), 'La oficina no puede escribir en el CRM (ni con el CRM simulado)' );
	wp_set_current_user( $scou );
	$ok( true === Escritura::disponible(), 'Control: con el CRM simulado un scouter sí está disponible para escribir' );
	Escritura::usar_para_pruebas( null );

	// Selector de grupo.
	wp_set_current_user( $of );
	$grupos = array_keys( Grupos::todos() );
	if ( $grupos ) {
		$g = (string) $grupos[ count( $grupos ) - 1 ];
		$ok( true === Oficina::cambiar_grupo( $of, $g ) && $g === (string) get_user_meta( $of, Grupo::META_CONTEXTO, true ), 'La oficina cambia de grupo y se guarda' );
		$ok( $g === Grupo::numero(), 'Grupo::actual() pasa a ser el elegido' );
	} else {
		echo "INFO No hay grupos dados de alta en este sitio: se omiten las pruebas con un grupo real\n";
	}
	$ok( false === Oficina::cambiar_grupo( $of, '000' ) && false === Oficina::cambiar_grupo( $of, 'no-existe' ), 'Un grupo que no existe no se acepta' );
	$ok( false === Oficina::cambiar_grupo( $scou, $grupos ? (string) $grupos[0] : '684' ), 'Un scouter no puede usar el selector' );
	Organizacion::guardar_tipo( Organizacion::TIPO_GRUPO );
	$ok( false === Oficina::cambiar_grupo( $of, $grupos ? (string) $grupos[0] : '684' ), 'En una web de grupo el selector no funciona' );
	Organizacion::guardar_tipo( Organizacion::TIPO_OFICINA );

	// El contexto manipulado cae en un grupo que existe.
	update_user_meta( $of, Grupo::META_CONTEXTO, '999-manipulado' );
	$actual = Grupo::actual();
	$ok( null === $actual || isset( Grupos::todos()[ $actual['numero'] ] ), 'Un contexto manipulado no da acceso a un grupo que no existe' );

	// El menú: el selector solo lo ve la oficina.
	if ( $grupos ) {
		wp_set_current_user( $of );
		$html = TemplateLoader::render( 'comun/nav', array( 'items' => array( 'area' => 'Inicio' ), 'activo' => 'area', 'pagina' => 'area', 'user' => get_userdata( $of ), 'nombre' => '' ) );
		$ok( str_contains( $html, 'name="grupo"' ) && str_contains( $html, 'oficina_cambiar_grupo' ) && str_contains( $html, 'name="_wpnonce"' ), 'La oficina ve el selector de grupo, con nonce' );
		wp_set_current_user( $scou );
		$html = TemplateLoader::render( 'comun/nav', array( 'items' => array( 'area' => 'Inicio' ), 'activo' => 'area', 'pagina' => 'area', 'user' => get_userdata( $scou ), 'nombre' => '' ) );
		$ok( ! str_contains( $html, 'oficina_cambiar_grupo' ), 'Un scouter no ve el selector de grupo' );
	}
} catch ( Throwable $e ) {
	++$fails;
	echo 'FAIL excepcion: ', $e->getMessage(), ' (', basename( $e->getFile() ), ':', $e->getLine(), ")\n";
} finally {
	Escritura::usar_para_pruebas( null );
	wp_set_current_user( $antes_actual );
	// Doc 60: también el registro de lo que hicieron sus usuarios (el cambio de grupo de la oficina quedaba).
	$lista = implode( ',', array_map( 'intval', array_filter( $uids ) ?: array( 0 ) ) );
	$wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE id > %d AND user_id IN ($lista)", Logger::table(), $log_antes ) ); // phpcs:ignore WordPress.DB
	foreach ( $uids as $u ) {
		if ( $u > 0 ) {
			wp_delete_user( $u );
		}
	}
	if ( null === $antes_tipo ) {
		delete_option( Organizacion::TIPO_OPCION );
	} else {
		update_option( Organizacion::TIPO_OPCION, $antes_tipo, false );
	}
	wp_using_ext_object_cache( $cache_externa );
	$ok( 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE id > %d AND user_id IN ($lista)", Logger::table(), $log_antes ) ) && 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users} WHERE user_login LIKE 'prueba-sl-%'" ), 'limpieza: sin usuarios ni registro de la prueba' ); // phpcs:ignore WordPress.DB
}

echo $fails ? "\nHAY $fails FALLOS\n" : "\nTODO OK (el sitio queda como estaba)\n";
