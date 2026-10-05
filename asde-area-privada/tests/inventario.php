<?php
/**
 * Pruebas de la v0.19 (entorno LOCAL): inventario de material, tipos y listas de
 * compra con el visto bueno de Tesorería y su PDF (doc 32). Ejecutar desde
 * app/public del site Local:
 *
 *   wp eval-file wp-content/plugins/asde-area-privada/tests/inventario.php
 *
 * Usa el CRM simulado de tests/lib (fichas inventadas: ninguna petición llega al
 * CRM real), guarda las cachés solo en la memoria de este proceso y no envía
 * ningún email (se capturan). Al terminar se borra lo creado (usuarios, material,
 * tipos, listas, registros) y la tabla de números queda como estaba.
 */
defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/lib/CrmSimulado.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Auth\Ambito;
use AsdeBalears\AreaPrivada\Domain\Grupo;
use AsdeBalears\AreaPrivada\Domain\Grupos;
use AsdeBalears\AreaPrivada\Frontend\Menu;
use AsdeBalears\AreaPrivada\Frontend\Notices;
use AsdeBalears\AreaPrivada\Frontend\Pages;
use AsdeBalears\AreaPrivada\Frontend\Router;
use AsdeBalears\AreaPrivada\Frontend\Views\AreaPrivadaView;
use AsdeBalears\AreaPrivada\Frontend\Views\InventarioComprasView;
use AsdeBalears\AreaPrivada\Frontend\Views\InventarioTiposView;
use AsdeBalears\AreaPrivada\Frontend\Views\InventarioView;
use AsdeBalears\AreaPrivada\Frontend\Views\View;
use AsdeBalears\AreaPrivada\Install\Installer;
use AsdeBalears\AreaPrivada\Install\Roles;
use AsdeBalears\AreaPrivada\Inventario\ComprasTable;
use AsdeBalears\AreaPrivada\Inventario\ListaPdf;
use AsdeBalears\AreaPrivada\Inventario\MaterialesTable;
use AsdeBalears\AreaPrivada\Inventario\Pdf;
use AsdeBalears\AreaPrivada\Inventario\Servicio;
use AsdeBalears\AreaPrivada\Inventario\TiposTable;
use AsdeBalears\AreaPrivada\Socios\SociosTable;
use AsdeBalears\AreaPrivada\Usuarios\EmailCuenta;
use AsdeBalears\AreaPrivada\Usuarios\RegistroEmails;
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
foreach ( get_users( array( 'search' => 'prueba-inv-*', 'search_columns' => array( 'user_login' ) ) ) as $u ) {
	exit( 'Ya existe el usuario ' . $u->user_login . " (¿una ejecución anterior interrumpida?). Bórralo antes de repetir la prueba.\n" );
}
wp_set_current_user( 0 );

// --- Lo que se restaura al final ---------------------------------------------
$max_id       = static fn( string $t ) => (int) $wpdb->get_var( "SELECT COALESCE(MAX(id), 0) FROM $t" ); // phpcs:ignore WordPress.DB
$log_antes    = $max_id( Logger::table() );
$mat_antes    = $max_id( MaterialesTable::table() );
$tip_antes    = $max_id( TiposTable::table() );
$com_antes    = $max_id( ComprasTable::table() );
$ema_antes    = $max_id( RegistroEmails::table() );
$socios_antes = array_column( (array) $wpdb->get_results( 'SELECT * FROM ' . SociosTable::table(), ARRAY_A ), null, 'num_socio_asde' ); // phpcs:ignore WordPress.DB

$cache_externa = wp_using_ext_object_cache( true );
$correos       = array();
$capturar      = static function ( $pre, $atts ) use ( &$correos ) {
	$correos[] = $atts;
	return true; // No sale ningún email.
};
add_filter( 'pre_wp_mail', $capturar, 10, 2 );
$cortadas   = 0;
$cortar_crm = static function ( $pre, $args, $url ) use ( &$cortadas ) {
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
		$P( 41, 'Sara', 'Inventario Mir', '1990-01-01', 'Scouters' ),
		$P( 42, 'Joan', 'Inventario Pons', '1985-02-02', 'Scouters' ),
		$P( 43, 'Marta', 'Inventario Vich', '1980-03-03', 'Scouters' ),
		$P( 44, 'Pere', 'Inventario Font', '1979-04-04', 'Scouters' ),
		$P( 45, 'Laia', 'Inventario Roig', '1988-05-05', 'Scouters' ),
		$P( 46, 'Nil', 'Inventario Sala', '2014-06-06', 'Scouts' ),
	),
	$grupo['crm_id']
);
Grupo::forzar( $grupo );
$crm->activar();

$ids   = array();
$crear = static function ( string $login, array $roles, array $meta, string $email = '' ) use ( &$ids, $grupo ): int {
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
	if ( '' !== $email ) {
		EmailCuenta::guardar( $id, $email );
	}
	$ids[ $login ] = $id;
	return $id;
};
$u_scouter = $crear( 'prueba-inv-scouter', array( Roles::SCOUTER ), array( Usuarios::META_SOCIO => '990041', Usuarios::META_SECCION => 'lobatos' ) );
$u_teso    = $crear( 'prueba-inv-teso', array( Roles::TESORERIA ), array( Usuarios::META_SOCIO => '990042' ), 'teso-inv@example.invalid' ); // Voluntario sin sección.
$u_teso2   = $crear( 'prueba-inv-teso2', array( Roles::SCOUTER, Roles::TESORERIA ), array( Usuarios::META_SOCIO => '990043', Usuarios::META_SECCION => 'rovers' ), 'teso2-inv@example.invalid' );
$u_secre   = $crear( 'prueba-inv-secre', array( Roles::SECRETARIA ), array( Usuarios::META_SOCIO => '990044' ) );
$u_coord   = $crear( 'prueba-inv-coord', array( Roles::COORDINACION ), array( Usuarios::META_SOCIO => '990045' ) );
$u_fam     = $crear( 'prueba-inv-fam', array( Roles::TUTOR ), array( Usuarios::META_REPRESENTADO => '990046' ) );
$u_part    = $crear( 'prueba-inv-part', array( Roles::PARTICIPANTE ), array( Usuarios::META_SOCIO => '990046' ) );
// Una cuenta de Tesorería desactivada: no se le avisa.
$u_teso_off = $crear( 'prueba-inv-teso-off', array( Roles::TESORERIA ), array(), 'off-inv@example.invalid' );
update_user_meta( $u_teso_off, Usuarios::META_ESTADO, Usuarios::DESACTIVADO );

$como = static function ( int $uid ): Ambito {
	wp_set_current_user( $uid );
	Ambito::olvidar( $uid );
	return Ambito::actual();
};
add_filter( 'send_auth_cookies', '__return_false' );
$redirigir = static function ( $destino ) {
	throw new RuntimeException( 'asde-redirect:' . $destino );
};
add_filter( 'wp_redirect', $redirigir, 1 );
$morir = static fn() => static function ( $mensaje ) {
	throw new RuntimeException( 'asde-die:' . ( is_wp_error( $mensaje ) ? $mensaje->get_error_message() : (string) $mensaje ) );
};
add_filter( 'wp_die_handler', $morir, PHP_INT_MAX ); // Después del de WP-CLI, que si no termina el proceso sin pasar por la limpieza.
add_filter( 'wp_die_ajax_handler', $morir, PHP_INT_MAX ); // wp_send_json() en una petición AJAX (el JSON de los tipos).
/** Envía un formulario a una vista como lo haría el navegador y devuelve los avisos. */
$enviar = static function ( $vista, array $campos, string $nonce ): string {
	$_POST    = $campos + array( '_wpnonce' => wp_create_nonce( $nonce ) );
	$_REQUEST = $_POST;
	try {
		$vista->handle_post();
	} catch ( RuntimeException $e ) {
		// Redirige al terminar.
	} finally {
		$_POST    = array();
		$_REQUEST = array();
	}
	return Notices::render();
};
/** Pinta una vista con estos parámetros en la dirección. */
$ver = static function ( $vista, array $get = array() ): string {
	$_GET = $get;
	try {
		return $vista->render();
	} finally {
		$_GET = array();
	}
};
$material = static fn( array $d ): array => $d + array(
	'asde_action' => 'material_guardar',
	'material'    => '0',
	'tipo'        => '0',
	'cantidad'    => '1',
	'precio'      => '',
	'anio'        => '',
	'num_serie'   => '',
	'notas'       => '',
);
$por_desc = static fn( string $d ) => array_values( array_filter( MaterialesTable::todos( true ), static fn( $m ) => $m['descripcion'] === $d ) )[0] ?? null;

try {
	echo "== Parte A · Quién entra ==\n";
	foreach ( array( 'scouter de sección' => $u_scouter, 'voluntario sin sección de Tesorería' => $u_teso, 'Secretaría' => $u_secre, 'Coordinación' => $u_coord ) as $quien => $uid ) {
		$a = $como( $uid );
		$ok( current_user_can( Servicio::CAP ) && isset( Menu::plano( $a )['inventario'] ) && ! str_contains( $ver( new InventarioView() ), 'No tienes acceso' ), "$quien: entra en el inventario y lo tiene en el menú" );
	}
	foreach ( array( 'una familia' => $u_fam, 'un participante' => $u_part ) as $quien => $uid ) {
		$a = $como( $uid );
		$ok( ! current_user_can( Servicio::CAP ) && ! isset( Menu::plano( $a )['inventario'] ) && str_contains( $ver( new InventarioView() ), 'No tienes acceso' ) && str_contains( $ver( new InventarioComprasView() ), 'No tienes acceso' ) && str_contains( $ver( new InventarioTiposView() ), 'No tienes acceso' ), "$quien: no entra (ni en tipos ni en listas de compra)" );
	}
	$como( $u_scouter );
	$ok( ! current_user_can( Servicio::CAP_APROBAR ), 'un scouter no da el visto bueno a las listas' );
	$como( $u_teso );
	$ok( current_user_can( Servicio::CAP_APROBAR ), 'Tesorería sí' );
	$como( $u_coord );
	$ok( ! current_user_can( Servicio::CAP_APROBAR ), 'Coordinación no' );
	$ok( in_array( 'inventario_tipos', Router::MODALES, true ) && in_array( 'inventario_compras', Router::MODALES, true ) && isset( Pages::DEFINICIONES['inventario'] ), 'las páginas existen y los tipos y las listas se abren en la ventana emergente' );

	echo "\n== Parte B · Tipos de material ==\n";
	$como( $u_scouter );
	$h = $enviar( new InventarioTiposView(), array( 'asde_action' => 'tipo_crear', 'nombre' => 'Tiendas' ), 'asde_inventario_tipos' );
	$ok( str_contains( $h, 'creado' ) && TiposTable::por_nombre( 'Tiendas' ) > 0, 'se crea un tipo' );
	$h = $enviar( new InventarioTiposView(), array( 'asde_action' => 'tipo_crear', 'nombre' => '  TIENDÁS ' ), 'asde_inventario_tipos' );
	$ok( str_contains( $h, 'Ya hay un tipo' ) && 1 === count( array_filter( TiposTable::todos(), static fn( $t ) => 'tiendas' === TiposTable::clave( $t['nombre'] ) ) ), 'no se repite un nombre (sin distinguir mayúsculas, acentos ni espacios)' );
	$enviar( new InventarioTiposView(), array( 'asde_action' => 'tipo_crear', 'nombre' => 'Cocina' ), 'asde_inventario_tipos' );
	$enviar( new InventarioTiposView(), array( 'asde_action' => 'tipo_crear', 'nombre' => 'Sin usar' ), 'asde_inventario_tipos' );
	$t_tiendas = TiposTable::por_nombre( 'Tiendas' );
	$t_cocina  = TiposTable::por_nombre( 'Cocina' );
	$t_sinusar = TiposTable::por_nombre( 'Sin usar' );
	$h         = $enviar( new InventarioTiposView(), array( 'asde_action' => 'tipo_renombrar', 'tipo' => (string) $t_cocina, 'nombre' => 'Cocina y menaje' ), 'asde_inventario_tipos' );
	$ok( 'Cocina y menaje' === ( TiposTable::obtener( $t_cocina )['nombre'] ?? '' ) && str_contains( $h, 'renombrado' ), 'se renombra' );
	$h = $enviar( new InventarioTiposView(), array( 'asde_action' => 'tipo_renombrar', 'tipo' => (string) $t_cocina, 'nombre' => 'tiendas' ), 'asde_inventario_tipos' );
	$ok( 'Cocina y menaje' === ( TiposTable::obtener( $t_cocina )['nombre'] ?? '' ) && str_contains( $h, 'Ya hay un tipo' ), 'y no se puede renombrar a uno que ya existe' );
	$ok( array( 'Cocina y menaje', 'Sin usar', 'Tiendas' ) === array_values( array_column( array_filter( TiposTable::todos(), static fn( $t ) => in_array( $t['id'], array( $t_tiendas, $t_cocina, $t_sinusar ), true ) ), 'nombre' ) ), 'los tipos salen por orden alfabético (lo que recibe el desplegable)' );
	// El «+» refresca el desplegable con la lista en JSON (wp_send_json termina con wp_die si es AJAX: se captura).
	$pedir_json = static function (): array {
		add_filter( 'wp_doing_ajax', '__return_true' );
		ob_start();
		try {
			( new InventarioTiposView() )->json();
		} catch ( RuntimeException $e ) {
			// Fin de la respuesta.
		} finally {
			$json = json_decode( (string) ob_get_clean(), true );
			remove_filter( 'wp_doing_ajax', '__return_true' );
		}
		return is_array( $json ) ? $json : array();
	};
	$json    = $pedir_json();
	$nombres = array_column( (array) ( $json['data']['tipos'] ?? array() ), 'nombre' );
	$ok( ! empty( $json['success'] ) && in_array( 'Cocina y menaje', $nombres, true ) && array_search( 'Cocina y menaje', $nombres, true ) < array_search( 'Tiendas', $nombres, true ) && is_int( $json['data']['tipos'][0]['id'] ?? null ), 'los tipos en JSON para el desplegable: id y nombre, por orden alfabético' );
	$como( $u_fam );
	$json = $pedir_json();
	$como( $u_scouter );
	$ok( isset( $json['success'] ) && false === $json['success'] && ! isset( $json['data']['tipos'] ), 'y sin permiso, el JSON no da la lista' );
	View::en_modal( true );
	$h = $ver( new InventarioTiposView(), array( 'volver' => Pages::url( 'inventario', array( 'nuevo' => '1' ) ) ) );
	View::en_modal( false );
	$ok( str_contains( $h, 'asde-ap--modal' ) && ! str_contains( $h, 'asde-subnav' ) && str_contains( $h, 'asde-solo-pagina' ) && str_contains( $h, 'Crear tipo' ), 'los tipos se pintan para la ventana emergente (sin pestañas; «volver» solo en la página)' );

	echo "\n== Parte C · Material ==\n";
	$errores = array(
		'sin descripción'      => array( 'descripcion' => '' ),
		'cantidad negativa'    => array( 'cantidad' => '-1' ),
		'cantidad con letras'  => array( 'cantidad' => 'tres' ),
		'precio que no es'     => array( 'precio' => '12,5,0' ),
		'año antiguo'          => array( 'anio' => '1900' ),
		'año que no ha llegado' => array( 'anio' => (string) ( (int) wp_date( 'Y' ) + 1 ) ),
		'tipo de otro grupo'   => array( 'tipo' => '999999' ),
	);
	foreach ( $errores as $caso => $d ) {
		$ok( is_wp_error( MaterialesTable::validar( $d + array( 'descripcion' => 'X', 'cantidad' => '1' ) ) ), "no se acepta: $caso" );
	}
	$v = MaterialesTable::validar( array( 'descripcion' => ' Mesa ', 'cantidad' => '0', 'precio' => '1.234,50 €', 'anio' => '', 'num_serie' => 'A-1' ) );
	$ok( ! is_wp_error( $v ) && 'Mesa' === $v['descripcion'] && 0 === $v['cantidad'] && 1234.5 === $v['precio'] && null === $v['anio'] && 0 === $v['tipo'], 'sí: cantidad 0, precio con miles y €, año en blanco (opcional), sin tipo' );
	$ok( 12.5 === MaterialesTable::euros( '12.5' ) && null === MaterialesTable::euros( '' ) && false === MaterialesTable::euros( '-3' ), 'importes: «12.5», vacío (sin precio) y negativo (no vale)' );

	$h = $enviar( new InventarioView(), $material( array( 'descripcion' => 'Tienda canadiense «4 plazas» (verde)', 'tipo' => (string) $t_tiendas, 'cantidad' => '3', 'precio' => '120,00', 'anio' => '2019', 'num_serie' => 'TC-0001' ) ), 'asde_inventario_material_0' );
	$tienda = $por_desc( 'Tienda canadiense «4 plazas» (verde)' );
	$ok( $tienda && 3 === $tienda['cantidad'] && 120.0 === $tienda['precio'] && 2019 === $tienda['anio'] && 'TC-0001' === $tienda['num_serie'] && $t_tiendas === $tienda['tipo'] && $u_scouter === $tienda['creado_por'] && str_contains( $h, 'añadido' ), 'un scouter añade material con todos los campos' );
	$enviar( new InventarioView(), $material( array( 'descripcion' => 'Hornillo de gas', 'tipo' => (string) $t_cocina, 'cantidad' => '12', 'precio' => '35' ) ), 'asde_inventario_material_0' );
	$enviar( new InventarioView(), $material( array( 'descripcion' => 'Cuerda 20 m', 'cantidad' => '0' ) ), 'asde_inventario_material_0' );
	$hornillo = $por_desc( 'Hornillo de gas' );
	$cuerda   = $por_desc( 'Cuerda 20 m' );
	$ok( $hornillo && $cuerda && null === $cuerda['precio'] && null === $cuerda['anio'] && 0 === $cuerda['tipo'], 'más material: sin precio, sin año y sin tipo también vale' );

	$h = $enviar( new InventarioView(), $material( array( 'descripcion' => 'Pala plegable', 'cantidad' => 'muchas' ) ), 'asde_inventario_material_0' );
	$f = $ver( new InventarioView(), array( 'nuevo' => '1' ) );
	$ok( str_contains( $h, 'número entero' ) && null === $por_desc( 'Pala plegable' ) && str_contains( $f, 'value="Pala plegable"' ) && str_contains( $f, 'value="muchas"' ), 'si no se puede guardar, se dice por qué y el formulario conserva lo escrito' );
	$ok( str_contains( $f, 'data-asde-inv-mas' ) && str_contains( $f, 'data-asde-modal' ) && str_contains( $f, 'tipos-de-material' ) && str_contains( $f, 'Cocina y menaje' ), 'el formulario tiene el desplegable de tipos con el «+» que abre los tipos en la ventana emergente' );

	$h     = $enviar( new InventarioView(), $material( array( 'material' => (string) $hornillo['id'], 'descripcion' => 'Hornillo de gas', 'tipo' => (string) $t_cocina, 'cantidad' => '10', 'precio' => '35' ) ), 'asde_inventario_material_' . $hornillo['id'] );
	$log   = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE accion = %s AND objeto_id = %s ORDER BY id DESC LIMIT 1', Logger::table(), 'inventario_material_modificado', (string) $hornillo['id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB
	$ok( 10 === MaterialesTable::obtener( $hornillo['id'] )['cantidad'] && $log && str_contains( (string) $log['detalles'], 'cantidad' ) && ! str_contains( (string) $log['detalles'], 'precio' ), 'se cambia la cantidad, y la auditoría anota qué campo (sin valores)' );

	$h = $enviar( new InventarioTiposView(), array( 'asde_action' => 'tipo_borrar', 'tipo' => (string) $t_tiendas ), 'asde_inventario_tipos' );
	$ok( TiposTable::obtener( $t_tiendas ) && str_contains( $h, 'lo usa 1 material' ), 'un tipo en uso no se borra (y se dice cuántos lo usan)' );
	$h = $enviar( new InventarioTiposView(), array( 'asde_action' => 'tipo_borrar', 'tipo' => (string) $t_sinusar ), 'asde_inventario_tipos' );
	$ok( ! TiposTable::obtener( $t_sinusar ) && str_contains( $h, 'borrado' ), 'uno sin usar, sí' );

	$enviar( new InventarioView(), $material( array( 'descripcion' => 'Mochila rota' ) ), 'asde_inventario_material_0' );
	$rota = $por_desc( 'Mochila rota' );
	$enviar( new InventarioView(), array( 'asde_action' => 'material_baja', 'material' => (string) $rota['id'], 'motivo' => 'Se rompió en el campamento' ), 'asde_inventario_material_' . $rota['id'] );
	$ok( null !== MaterialesTable::obtener( $rota['id'] )['baja_en'] && ! isset( MaterialesTable::todos()[ $rota['id'] ] ) && isset( MaterialesTable::todos( true )[ $rota['id'] ] ), 'dar de baja: no se borra, deja de salir en el inventario y se ve con «dados de baja»' );
	$enviar( new InventarioView(), array( 'asde_action' => 'material_recuperar', 'material' => (string) $rota['id'] ), 'asde_inventario_material_' . $rota['id'] );
	$ok( null === MaterialesTable::obtener( $rota['id'] )['baja_en'] && '' === MaterialesTable::obtener( $rota['id'] )['baja_motivo'], 'y se recupera' );

	$wpdb->insert( MaterialesTable::table(), array( 'grupo' => '999', 'descripcion' => 'Material de otro grupo', 'cantidad' => 1, 'creado' => current_time( 'mysql', true ) ) );
	$ajeno = (int) $wpdb->insert_id;
	$ok( null === MaterialesTable::obtener( $ajeno ) && ! isset( MaterialesTable::todos( true )[ $ajeno ] ) && str_contains( $ver( new InventarioView(), array( 'material' => (string) $ajeno ) ), 'no está en el inventario del grupo' ), 'un grupo no ve el material de otro' );

	$h = $ver( new InventarioView() );
	$ok( 1 === preg_match( '/data-cantidad="3"[^>]*data-nombre="Tienda canadiense/', $h ) && str_contains( $h, 'asde-badge--inv-pocas' ) && str_contains( $h, 'data-umbral="5"' ), 'la lista lleva la cantidad de cada material (para el aviso) y marca los que tienen menos de 5' );
	$h = $ver( new InventarioView(), array( 'q' => 'HORNILLO' ) );
	$ok( 1 === preg_match( '/<tr data-tipo="\d+" data-texto="tienda canadiense[^"]*" hidden>/', $h ) && 1 === preg_match( '/<tr data-tipo="\d+" data-texto="hornillo de gas[^"]*">/', $h ), 'sin JavaScript, el buscador esconde las filas que no coinciden (sin acentos ni mayúsculas)' );

	echo "\n== Parte D · Nueva lista de compra y aviso a Tesorería ==\n";
	$como( $u_scouter );
	$h = $ver( new InventarioComprasView(), array( 'nueva' => '1', 'm' => array( (string) $tienda['id'], (string) $hornillo['id'], (string) $cuerda['id'], (string) $ajeno ) ) );
	$ok( str_contains( $h, 'solo hay 3 unidades' ) && str_contains( $h, 'no queda ninguna unidad en el inventario' ) && ! str_contains( $h, 'solo hay 10 unidades' ) && ! str_contains( $h, 'Material de otro grupo' ) && str_contains( $h, 'ya no está en el inventario' ), 'el formulario avisa de los que tienen menos de 5 (no bloquea) y no admite material de otro grupo' );

	$enviar( new InventarioComprasView(), array( 'asde_action' => 'compra_crear', 'cantidad' => array( $tienda['id'] => '0', $hornillo['id'] => '0' ), 'precio' => array() ), 'asde_inventario_compra_nueva' );
	$ok( $com_antes === $max_id( ComprasTable::table() ), 'una lista vacía no se crea' );

	$correos = array();
	$h       = $enviar(
		new InventarioComprasView(),
		array(
			'asde_action' => 'compra_crear',
			'cantidad'    => array( $tienda['id'] => '2', $hornillo['id'] => '1', $cuerda['id'] => '3' ),
			'precio'      => array( $tienda['id'] => '120,00', $hornillo['id'] => '30', $cuerda['id'] => '' ),
			'nota'        => 'Para el campamento de verano',
		),
		'asde_inventario_compra_nueva'
	);
	$l1 = ComprasTable::obtener( $max_id( ComprasTable::table() ) );
	$ok( $l1 && ComprasTable::PENDIENTE === $l1['estado'] && 3 === count( $l1['lineas'] ) && 270.0 === $l1['total'] && ComprasTable::sin_precio( $l1 ) && $u_scouter === $l1['creado_por'] && 'Para el campamento de verano' === $l1['nota'], 'se crea pendiente, con el total de lo que tiene precio (2 × 120 + 30 = 270 €)' );
	$ok( 3 === $l1['lineas'][0]['en_inventario'] && 'Tiendas' === $l1['lineas'][0]['tipo'] && 'TC-0001' === $l1['lineas'][0]['num_serie'], 'cada línea guarda cómo estaba el material (cantidad, tipo y nº de serie)' );
	$destinos = array_map( static fn( $c ) => (string) $c['to'], $correos );
	sort( $destinos );
	$ok( array( 'teso-inv@example.invalid', 'teso2-inv@example.invalid' ) === $destinos && 2 === $l1['avisados'], 'el email va a las dos cuentas activas de Tesorería (no a la desactivada)' );
	$cuerpo = (string) ( $correos[0]['message'] ?? '' );
	$ok( str_contains( (string) $correos[0]['subject'], ComprasTable::numero( $l1 ) ) && str_contains( $cuerpo, 'visto bueno' ) && str_contains( $cuerpo, 'Hornillo de gas' ) && str_contains( $cuerpo, 'lista=' . $l1['id'] ), 'el email dice el nº, el material y lleva el enlace para revisarla' );
	$ok( str_contains( $h, 'enviada a 2 personas de Tesorería' ), 'quien la crea ve a cuántas personas se ha enviado' );
	$reg = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE id > %d AND tipo = %s', RegistroEmails::table(), $ema_antes, RegistroEmails::COMPRA ), ARRAY_A ); // phpcs:ignore WordPress.DB
	$ok( 2 === count( $reg ) && ! str_contains( (string) $reg[0]['destino'], 'teso-inv@' ) && str_contains( RegistroEmails::vista_previa( $reg[0] ), ComprasTable::numero( $l1 ) ), 'queda en el registro de emails con el destino enmascarado, y se puede ver cómo era' );

	$correos = array();
	$como( $u_teso2 );
	$h  = $enviar( new InventarioComprasView(), array( 'asde_action' => 'compra_crear', 'cantidad' => array( $hornillo['id'] => '4' ), 'precio' => array( $hornillo['id'] => '30' ) ), 'asde_inventario_compra_nueva' );
	$l2 = ComprasTable::obtener( $max_id( ComprasTable::table() ) );
	$ok( 1 === count( $correos ) && 'teso-inv@example.invalid' === $correos[0]['to'], 'si la crea alguien de Tesorería, el email va al resto de Tesorería' );

	echo "\n== Parte E · Visto bueno de Tesorería y PDF ==\n";
	$como( $u_scouter );
	$d = $ver( new InventarioComprasView(), array( 'lista' => (string) $l1['id'] ) );
	$ok( ! Servicio::puede_imprimir( $l1 ) && ! str_contains( $d, 'formato=pdf' ) && str_contains( $d, 'asde-inv-compra--sin-visto-bueno' ) && ! str_contains( $d, 'Dar el visto bueno' ), 'sin visto bueno: no hay PDF, la página no se imprime y el scouter no puede aprobarla' );
	$_GET = array( 'lista' => (string) $l1['id'], '_wpnonce' => wp_create_nonce( 'asde_inventario_pdf_' . $l1['id'] ) );
	$msg  = '';
	try {
		( new InventarioComprasView() )->pdf();
	} catch ( RuntimeException $e ) {
		$msg = $e->getMessage();
	} finally {
		$_GET = array();
	}
	$ok( str_contains( $msg, 'asde-die:' ) && str_contains( $msg, 'visto bueno' ), 'y aunque se pida el PDF a mano, el servidor lo niega' );
	$enviar( new InventarioComprasView(), array( 'asde_action' => 'compra_aprobar', 'lista' => (string) $l1['id'] ), 'asde_inventario_compra_' . $l1['id'] );
	$ok( ComprasTable::PENDIENTE === ComprasTable::obtener( $l1['id'] )['estado'], 'un scouter que envía el visto bueno a mano no consigue nada' );

	$a = $como( $u_teso );
	$ok( 'Inventario (2)' === ( Menu::plano( $a )['inventario'] ?? '' ) && str_contains( ( new AreaPrivadaView() )->render(), 'listas de compra de material pendientes de tu visto bueno' ), 'Tesorería ve en el menú y en el inicio las listas que esperan su visto bueno' );
	$d = $ver( new InventarioComprasView(), array( 'lista' => (string) $l1['id'] ) );
	$ok( str_contains( $d, 'Dar el visto bueno' ) && str_contains( $d, 'Rechazar' ), 'y en el detalle puede darlo o rechazarla' );
	$h = $enviar( new InventarioComprasView(), array( 'asde_action' => 'compra_rechazar', 'lista' => (string) $l2['id'], 'motivo' => '  ' ), 'asde_inventario_compra_' . $l2['id'] );
	$ok( ComprasTable::PENDIENTE === ComprasTable::obtener( $l2['id'] )['estado'] && str_contains( $h, 'motivo' ), 'rechazar pide un motivo' );
	$enviar( new InventarioComprasView(), array( 'asde_action' => 'compra_rechazar', 'lista' => (string) $l2['id'], 'motivo' => 'Ya hay hornillos de sobra' ), 'asde_inventario_compra_' . $l2['id'] );
	$r2 = ComprasTable::obtener( $l2['id'] );
	$ok( ComprasTable::RECHAZADA === $r2['estado'] && 'Ya hay hornillos de sobra' === $r2['motivo'] && ! Servicio::puede_imprimir( $r2 ), 'rechazada, con su motivo, y sin PDF' );
	$h  = $enviar( new InventarioComprasView(), array( 'asde_action' => 'compra_aprobar', 'lista' => (string) $l1['id'] ), 'asde_inventario_compra_' . $l1['id'] );
	$a1 = ComprasTable::obtener( $l1['id'] );
	$ok( ComprasTable::APROBADA === $a1['estado'] && $u_teso === $a1['revisado_por'] && str_contains( $h, 'Ya se puede imprimir' ), 'Tesorería le da el visto bueno' );
	$enviar( new InventarioComprasView(), array( 'asde_action' => 'compra_aprobar', 'lista' => (string) $l2['id'] ), 'asde_inventario_compra_' . $l2['id'] );
	$ok( ComprasTable::RECHAZADA === ComprasTable::obtener( $l2['id'] )['estado'], 'una lista ya rechazada no se puede aprobar' );

	$como( $u_scouter );
	$d = $ver( new InventarioComprasView(), array( 'lista' => (string) $l1['id'] ) );
	$ok( Servicio::puede_imprimir( $a1 ) && str_contains( $d, 'formato=pdf' ) && str_contains( $d, 'Descargar PDF para imprimir' ), 'con el visto bueno, quien la pidió ya tiene el PDF' );
	$_GET = array( 'lista' => (string) $l1['id'], '_wpnonce' => 'falso' );
	$msg  = '';
	try {
		( new InventarioComprasView() )->pdf();
	} catch ( RuntimeException $e ) {
		$msg = $e->getMessage();
	} finally {
		$_GET = array();
	}
	$ok( str_contains( $msg, 'no válido' ), 'el enlace del PDF va firmado (sin firma, no)' );

	$pdf = ListaPdf::generar( $a1 );
	$ok( str_starts_with( $pdf, '%PDF-1.4' ) && str_ends_with( rtrim( $pdf ), '%%EOF' ), 'el PDF tiene cabecera y final' );
	preg_match( '/startxref\n(\d+)\n%%EOF/', $pdf, $sx );
	$xref = (int) ( $sx[1] ?? 0 );
	preg_match( '/^xref\n0 (\d+)\n/', substr( $pdf, $xref ), $xn );
	$bien = isset( $xn[1] ) && (int) $xn[1] > 5;
	for ( $i = 1; $bien && $i < (int) $xn[1]; $i++ ) {
		$off  = (int) substr( $pdf, $xref + strlen( $xn[0] ) + 20 * $i, 10 );
		$bien = str_starts_with( substr( $pdf, $off ), $i . ' 0 obj' );
	}
	$ok( $bien, 'la tabla de referencias apunta a cada objeto (el PDF se abre sin repararlo)' );
	$ok( str_contains( $pdf, '(Lista de compra de material)' ) && str_contains( $pdf, '(' . ComprasTable::numero( $a1 ) . ')' ) && str_contains( $pdf, "\xAB4 plazas\xBB" ) && str_contains( $pdf, '\\(verde\\)' ) && str_contains( $pdf, 'Hornillo de gas' ) && str_contains( $pdf, 'Para el campamento de verano' ) && str_contains( $pdf, '(P' . "\xE1" . 'gina 1 de 1)' ), 'el PDF lleva el título, el nº, el material (con «» y paréntesis bien escritos), la nota y «Página 1 de 1»' );
	$ok( 1 === preg_match( '/Visto bueno de Tesorer\xEDa: [^)]+\)/', $pdf ), 'y quién le dio el visto bueno' );

	$larga           = $a1;
	$larga['lineas'] = array_fill( 0, 70, $a1['lineas'][1] );
	$pdf_largo       = ListaPdf::generar( $larga );
	$ok( str_contains( $pdf_largo, '/Count 2' ) || str_contains( $pdf_largo, '/Count 3' ), 'una lista larga ocupa varias páginas' );
	$ok( str_contains( $pdf_largo, "(P\xE1gina 2 de " ) && substr_count( $pdf_largo, '(Material)' ) >= 2, 'con «Página 2 de N» y la cabecera de la tabla repetida' );
	$p = new Pdf();
	$ok( array( 'uno dos', 'tres' ) === $p->cortar( 'uno dos tres', $p->ancho( 'uno dos', 10 ) + 1, 10 ) && abs( $p->ancho( 'á', 10 ) - $p->ancho( 'a', 10 ) ) < 0.001 && count( $p->cortar( str_repeat( 'W', 40 ), 100, 10 ) ) >= 3, 'las líneas se parten por palabras, las letras con acento miden como sin él y una palabra muy larga se corta' );

	$como( $u_teso );
	$ok( Servicio::puede_deshacer( $a1 ), 'antes de imprimirla, Tesorería puede deshacer el visto bueno' );
	ComprasTable::marcar_impresa( $a1['id'] );
	$i1 = ComprasTable::obtener( $a1['id'] );
	$ok( 1 === $i1['impresiones'] && null !== $i1['impresa_en'] && ! Servicio::puede_deshacer( $i1 ) && ! Servicio::puede_cancelar( $i1 ) && ! ComprasTable::deshacer_aprobacion( $a1['id'] ), 'una vez descargado el PDF, ni se deshace el visto bueno ni se cancela' );

	echo "\n== Parte F · Cancelar ==\n";
	$como( $u_secre );
	$enviar( new InventarioComprasView(), array( 'asde_action' => 'compra_crear', 'cantidad' => array( $cuerda['id'] => '2' ), 'precio' => array() ), 'asde_inventario_compra_nueva' );
	$l3 = ComprasTable::obtener( $max_id( ComprasTable::table() ) );
	$como( $u_scouter );
	$enviar( new InventarioComprasView(), array( 'asde_action' => 'compra_cancelar', 'lista' => (string) $l3['id'] ), 'asde_inventario_compra_' . $l3['id'] );
	$ok( ComprasTable::PENDIENTE === ComprasTable::obtener( $l3['id'] )['estado'], 'un scouter no cancela la lista de otra persona' );
	$como( $u_secre );
	$enviar( new InventarioComprasView(), array( 'asde_action' => 'compra_cancelar', 'lista' => (string) $l3['id'], 'motivo' => 'Me equivoqué' ), 'asde_inventario_compra_' . $l3['id'] );
	$c3 = ComprasTable::obtener( $l3['id'] );
	$ok( ComprasTable::CANCELADA === $c3['estado'] && 'Me equivoqué' === $c3['motivo'], 'quien la creó la cancela mientras está pendiente' );
	$h = $ver( new InventarioComprasView(), array( 'estado' => 'cancelada' ) );
	$ok( str_contains( $h, ComprasTable::numero( $c3 ) ) && ! str_contains( $h, ComprasTable::numero( $a1 ) ), 'el listado filtra por estado' );

	$acciones = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT accion FROM %i WHERE id > %d AND accion LIKE 'inventario\\_%%'", Logger::table(), $log_antes ) ); // phpcs:ignore WordPress.DB
	$ok( ! array_diff( array( 'inventario_tipo_creado', 'inventario_tipo_renombrado', 'inventario_tipo_borrado', 'inventario_material_creado', 'inventario_material_modificado', 'inventario_material_baja', 'inventario_material_recuperado', 'inventario_compra_creada', 'inventario_compra_aprobada', 'inventario_compra_rechazada', 'inventario_compra_cancelada' ), $acciones ), 'todo queda en la auditoría' );
	echo "\n== Parte G · Instalación de la versión (páginas nuevas) ==\n";
	$cerrojo_antes = get_option( Installer::OPTION_CERROJO, null );
	delete_option( Installer::OPTION_CERROJO );
	$primero = Installer::tomar_cerrojo();
	$segundo = Installer::tomar_cerrojo();
	Installer::soltar_cerrojo();
	$tercero = Installer::tomar_cerrojo();
	update_option( Installer::OPTION_CERROJO, (string) ( time() - 3600 ), false ); // Abandonado hace una hora.
	$abandonado = Installer::tomar_cerrojo();
	Installer::soltar_cerrojo();
	if ( null !== $cerrojo_antes ) {
		update_option( Installer::OPTION_CERROJO, $cerrojo_antes, false );
	}
	$ok( $primero && ! $segundo && $tercero && $abandonado, 'la instalación tiene cerrojo: mientras una petición instala, otra no (y uno abandonado se recupera)' );
	$repetidas = array();
	foreach ( array( 'inventario', 'inventario_tipos', 'inventario_compras' ) as $clave ) {
		$id    = Pages::id( $clave );
		$misma = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE post_type = 'page' AND post_status <> 'trash' AND post_name = %s AND post_parent = %d", $wpdb->posts, get_post_field( 'post_name', $id ), (int) get_post_field( 'post_parent', $id ) ) ); // phpcs:ignore WordPress.DB
		if ( ! $id || 1 !== $misma ) {
			$repetidas[] = $clave;
		}
	}
	$ok( ! $repetidas, 'las tres páginas del inventario existen y no están repetidas' . ( $repetidas ? ': ' . implode( ', ', $repetidas ) : '' ) );
	$ok( $crm->llamadas >= 0 && 0 === $cortadas, 'ninguna consulta ha salido hacia el CRM real' );
} finally {
	// ---------------------------------------------------------------------------
	// Limpieza: todo lo creado fuera; lo tocado, como estaba.
	remove_filter( 'wp_redirect', $redirigir, 1 );
	remove_filter( 'wp_die_handler', $morir, PHP_INT_MAX );
	remove_filter( 'wp_die_ajax_handler', $morir, PHP_INT_MAX );
	remove_filter( 'send_auth_cookies', '__return_false' );
	remove_filter( 'pre_wp_mail', $capturar, 10 );
	$_POST = array();
	$_GET  = array();
	View::en_modal( false );
	$crm->desactivar();
	remove_filter( 'pre_http_request', $cortar_crm, 2 );
	wp_using_ext_object_cache( $cache_externa );
	wp_set_current_user( 0 );

	$lista = implode( ',', array_map( 'intval', $ids ) ) ?: '0';
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id > %d', MaterialesTable::table(), $mat_antes ) );
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id > %d', TiposTable::table(), $tip_antes ) );
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id > %d', ComprasTable::table(), $com_antes ) );
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id > %d', RegistroEmails::table(), $ema_antes ) );
	$wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE id > %d AND ( user_id IN ($lista) OR accion LIKE 'inventario\\_%%' )", Logger::table(), $log_antes ) ); // phpcs:ignore WordPress.DB
	foreach ( $ids as $uid ) {
		delete_transient( 'asde_ap_inv_material_' . $uid );
		delete_transient( 'asde_ap_inv_compra_' . $uid );
		delete_transient( 'asde_ap_notices_' . $uid );
		Ambito::olvidar( (int) $uid );
		wp_delete_user( (int) $uid );
	}
	// Tabla de números: como estaba.
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

	$quedan = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users} WHERE user_login LIKE 'prueba-inv-%'" ); // phpcs:ignore WordPress.DB
	$quedan_filas = 0;
	foreach ( array( array( MaterialesTable::table(), $mat_antes ), array( TiposTable::table(), $tip_antes ), array( ComprasTable::table(), $com_antes ) ) as list( $tabla, $antes ) ) {
		$quedan_filas += (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE id > %d', $tabla, $antes ) );
	}
	$ok( 0 === $quedan && 0 === $quedan_filas, 'limpieza: sin usuarios, material, tipos ni listas de la prueba' );
}

echo $fails ? "\n$fails FALLOS\n" : "\nTODO OK (nada tocado en el CRM real; el sitio queda como estaba)\n";
