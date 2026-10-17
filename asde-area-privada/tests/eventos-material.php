<?php
/**
 * Pruebas de la v0.42 (entorno LOCAL, doc 43 apartado 5): el material de un evento — reservar del inventario, aviso de conflicto con otros
 * eventos que coinciden en fechas, material habitual de cada tipo, salida, devolución con faltas, descontar del inventario, permisos y
 * pestaña. Ejecutar desde app/public del site Local:
 *
 *   wp eval-file <ruta completa>/wp-content/plugins/asde-area-privada/tests/eventos-material.php
 *
 * Con el CRM simulado (no toca el real). Al terminar se borra lo creado.
 */
defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/lib/CrmSimulado.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Auth\Ambito;
use AsdeBalears\AreaPrivada\Crm\Escritura;
use AsdeBalears\AreaPrivada\Domain\Grupo;
use AsdeBalears\AreaPrivada\Domain\Grupos;
use AsdeBalears\AreaPrivada\Eventos\Config;
use AsdeBalears\AreaPrivada\Eventos\EventosTable;
use AsdeBalears\AreaPrivada\Eventos\InscripcionesTable;
use AsdeBalears\AreaPrivada\Eventos\Material;
use AsdeBalears\AreaPrivada\Eventos\MaterialTable;
use AsdeBalears\AreaPrivada\Eventos\PartidasTable;
use AsdeBalears\AreaPrivada\Eventos\Plantillas;
use AsdeBalears\AreaPrivada\Eventos\Servicio;
use AsdeBalears\AreaPrivada\Eventos\TareasTable;
use AsdeBalears\AreaPrivada\Frontend\Notices;
use AsdeBalears\AreaPrivada\Frontend\Views\EventosConfigView;
use AsdeBalears\AreaPrivada\Frontend\Views\EventosView;
use AsdeBalears\AreaPrivada\Install\Roles;
use AsdeBalears\AreaPrivada\Inventario\MaterialesTable;
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
foreach ( get_users( array( 'search' => 'prueba-ma-*', 'search_columns' => array( 'user_login' ) ) ) as $u ) {
	exit( 'Ya existe el usuario ' . $u->user_login . " (¿una ejecución anterior interrumpida?). Bórralo antes de repetir la prueba.\n" );
}
wp_set_current_user( 0 );

$max_id       = static fn( string $t ) => (int) $wpdb->get_var( "SELECT COALESCE(MAX(id), 0) FROM $t" ); // phpcs:ignore WordPress.DB
$log_antes    = $max_id( Logger::table() );
$ev_antes     = $max_id( EventosTable::table() );
$ins_antes    = $max_id( InscripcionesTable::table() );
$tar_antes    = $max_id( TareasTable::table() );
$par_antes    = $max_id( PartidasTable::table() );
$mat_antes    = $max_id( MaterialTable::table() );
$inv_antes    = $max_id( MaterialesTable::table() );
$socios_antes = array_column( (array) $wpdb->get_results( 'SELECT * FROM ' . SociosTable::table(), ARRAY_A ), null, 'num_socio_asde' ); // phpcs:ignore WordPress.DB
$modulos_antes = get_option( 'asde_ap_modulos', null );
$opcion_cfg    = 'asde_ap_eventos_' . $grupo['numero'];
$cfg_antes     = get_option( $opcion_cfg, null );

$cache_externa = wp_using_ext_object_cache( true );
add_filter( 'pre_wp_mail', '__return_true' );
$cortar_crm = static function ( $pre, $args, $url ) {
	return false === $pre && str_contains( (string) $url, '/rest.php' ) ? new WP_Error( 'prueba', 'CRM real cortado durante la prueba' ) : $pre;
};
add_filter( 'pre_http_request', $cortar_crm, 2, 3 );

$P   = array( 'Asde_Prueba_Crm', 'persona' );
$crm = new Asde_Prueba_Crm( array( $P( 71, 'Ana', 'Material Gil', '1990-01-01', 'Scouters' ), $P( 72, 'Tom', 'Material Luz', '1985-05-05', 'Scouters' ) ), $grupo['crm_id'] );
Grupo::forzar( $grupo );
\AsdeBalears\AreaPrivada\Domain\Modulos::guardar( (string) $grupo['numero'], array_keys( \AsdeBalears\AreaPrivada\Domain\Modulos::LISTA ) );
$crm->activar();
Escritura::usar_para_pruebas( $crm );

$ids   = array();
$crear = static function ( string $login, array $roles, array $meta ) use ( &$ids, $grupo ): int {
	$id = wp_insert_user( array( 'user_login' => $login, 'user_email' => '', 'user_pass' => wp_generate_password( 24 ), 'role' => $roles[0] ) );
	$u  = get_userdata( $id );
	foreach ( array_slice( $roles, 1 ) as $r ) {
		$u->add_role( $r );
	}
	update_user_meta( $id, Usuarios::META_GRUPO, $grupo['id'] );
	update_user_meta( $id, Usuarios::META_ESTADO, Usuarios::ACTIVO );
	foreach ( $meta as $k => $v ) {
		add_user_meta( $id, $k, $v );
	}
	$ids[ $login ] = $id;
	return $id;
};
$u_lob   = $crear( 'prueba-ma-lobatos', array( Roles::SCOUTER ), array( Usuarios::META_SOCIO => '990071', Usuarios::META_SECCION => 'lobatos' ) );
$u_sco   = $crear( 'prueba-ma-scouts', array( Roles::SCOUTER ), array( Usuarios::META_SOCIO => '990072', Usuarios::META_SECCION => 'scouts' ) );
$u_coord = $crear( 'prueba-ma-coord', array( Roles::SCOUTER, Roles::COORDINACION ), array( Usuarios::META_SOCIO => '990072', Usuarios::META_SECCION => 'rovers' ) );
$u_teso  = $crear( 'prueba-ma-teso', array( Roles::TESORERIA ), array() );
$como    = static function ( int $uid ): Ambito {
	wp_set_current_user( $uid );
	Ambito::olvidar( $uid );
	Servicio::olvidar();
	return Ambito::actual();
};
add_filter( 'send_auth_cookies', '__return_false' );
$redirigir = static function ( $destino ) {
	throw new RuntimeException( 'asde-redirect:' . $destino );
};
add_filter( 'wp_redirect', $redirigir, 1 );
$enviar = static function ( $vista, array $campos, string $nonce ): string {
	$_POST    = $campos + array( '_wpnonce' => wp_create_nonce( $nonce ) );
	$_REQUEST = $_POST;
	try {
		$vista->handle_post();
	} catch ( RuntimeException $e ) {
		// Redirige.
	} finally {
		$_POST    = array();
		$_REQUEST = array();
	}
	return Notices::render();
};
$pintar = static function ( array $get ): string {
	$_GET = $get;
	try {
		return ( new EventosView() )->render();
	} finally {
		$_GET = array();
	}
};
$evento = static function ( string $nombre, string $ini, string $fin, string $estado = '' ) use ( $enviar, $max_id ): array {
	$enviar( new EventosView(), array( 'asde_action' => 'evento_crear', 'tipo' => 'acampada', 'nombre' => $nombre, 'ambito' => 'secciones', 'secciones' => array( 'lobatos' ), 'inicio_fecha' => $ini, 'inicio_hora' => '18:00', 'fin_fecha' => $fin, 'fin_hora' => '16:00', 'lugar' => 'Albergue', 'previstas_lobatos' => '10', 'con_coste' => '1' ), 'asde_evento_nuevo' );
	return EventosTable::obtener( $max_id( EventosTable::table() ) );
};
$stock = static fn( int $id ): int => (int) ( MaterialesTable::obtener( $id )['cantidad'] ?? 0 );

try {
	Config::guardar( Config::defaults(), $grupo['numero'] );
	$a_lob = $como( $u_lob );
	$d     = static fn( int $n ) => wp_date( 'Y-m-d', strtotime( "+$n days" ) );
	$e1    = $evento( 'Acampada con material', $d( 30 ), $d( 32 ) );
	$e2    = $evento( 'Otra acampada, mismas fechas', $d( 31 ), $d( 33 ) );
	$e3    = $evento( 'Acampada lejana', $d( 60 ), $d( 62 ) );
	$tienda = MaterialesTable::crear( MaterialesTable::validar( array( 'descripcion' => 'Tienda canadiense de prueba', 'tipo' => '0', 'cantidad' => '4' ) ) );
	$cuerda = MaterialesTable::crear( MaterialesTable::validar( array( 'descripcion' => 'Cuerda de 20 m de prueba', 'tipo' => '0', 'cantidad' => '10' ) ) );
	$baja   = MaterialesTable::crear( MaterialesTable::validar( array( 'descripcion' => 'Mástil de baja de prueba', 'tipo' => '0', 'cantidad' => '2' ) ) );
	MaterialesTable::dar_baja( $baja, 'Prueba' );

	echo "== Parte A · Reservar ==\n";
	$ok( Material::puede_ver( $e1, $a_lob ) && Material::puede_editar( $e1, $a_lob ), 'quien gestiona el evento y usa el inventario ve y edita su material' );
	$ok( true === Material::anadir( $e1, $a_lob, $tienda, 3 ), 'se reservan 3 tiendas' );
	$ok( true === Material::anadir( $e1, $a_lob, $cuerda, 4 ), 'y 4 cuerdas' );
	$ok( 4 === $stock( $tienda ), 'reservar no descuenta del inventario' );
	$ok( is_wp_error( Material::anadir( $e1, $a_lob, $baja, 1 ) ) && is_wp_error( Material::anadir( $e1, $a_lob, 999999, 1 ) ), 'lo dado de baja o que no existe no se reserva' );
	$ok( is_wp_error( Material::anadir( $e1, $a_lob, $tienda, 0 ) ) && is_wp_error( Material::anadir( $e1, $a_lob, $tienda, 100000 ) ), 'la cantidad tiene que ser entre 1 y 99999' );
	Material::anadir( $e1, $a_lob, $cuerda, 2 );
	$ls  = array_column( Material::lineas( $e1 ), null, 'material_id' );
	$ok( 6 === $ls[ $cuerda ]['cantidad'] && 2 === count( $ls ), 'reservar lo ya reservado suma (4 + 2 = 6 cuerdas), sin repetir línea' );
	$ok( 3 === $ls[ $tienda ]['cantidad'] && 4 === $ls[ $tienda ]['existencias'] && 0 === $ls[ $tienda ]['faltan'] && $ls[ $tienda ]['disponible'] >= 3, 'tiendas: 3 reservadas de 4, alcanzan' );
	$ok( 2 === count( array_filter( Material::reservable(), static fn( $m ) => in_array( $m['id'], array( $tienda, $cuerda ), true ) ) ) && empty( array_filter( Material::reservable(), static fn( $m ) => $m['id'] === $baja ) ), 'el selector ofrece el material con existencias, sin las bajas' );

	echo "\n== Parte B · Conflictos entre eventos ==\n";
	Material::anadir( $e2, $a_lob, $tienda, 3 );
	Material::anadir( $e3, $a_lob, $tienda, 4 );
	$ls = array_column( Material::lineas( $e1 ), null, 'material_id' );
	$ok( 3 === $ls[ $tienda ]['otros'] && 1 === $ls[ $tienda ]['disponible'] && 2 === $ls[ $tienda ]['faltan'], 'otro evento que coincide reserva 3 tiendas: quedan 1 y a este le faltan 2' );
	$ok( 0 === $ls[ $cuerda ]['otros'] && 0 === $ls[ $cuerda ]['faltan'], 'lo que nadie más reserva no avisa' );
	$ok( 0 === array_column( Material::lineas( $e3 ), null, 'material_id' )[ $tienda ]['otros'], 'el evento lejano (otras fechas) no cuenta' );
	$wpdb->update( EventosTable::table(), array( 'estado' => EventosTable::CANCELADO ), array( 'id' => $e2['id'] ) ); // phpcs:ignore WordPress.DB
	$ls = array_column( Material::lineas( $e1 ), null, 'material_id' );
	$ok( 0 === $ls[ $tienda ]['otros'] && 0 === $ls[ $tienda ]['faltan'], 'un evento cancelado deja de reservar' );
	$wpdb->update( EventosTable::table(), array( 'estado' => 'borrador' ), array( 'id' => $e2['id'] ) ); // phpcs:ignore WordPress.DB
	$ls = array_column( Material::lineas( $e1 ), null, 'material_id' );
	$ok( 3 === $ls[ $tienda ]['otros'], 'y al volver, vuelve a contar' );

	echo "\n== Parte C · Cambiar y quitar ==\n";
	$l_tienda = $ls[ $tienda ]['id'];
	$ok( true === Material::cambiar_cantidad( $e1, $a_lob, $l_tienda, 2 ) && 2 === MaterialTable::obtener( $l_tienda )['cantidad'], 'se cambia la cantidad antes de sacarlo' );
	$ok( is_wp_error( Material::cambiar_cantidad( $e1, $a_lob, $l_tienda, 0 ) ), 'no se admite cero' );
	$l_cuerda = $ls[ $cuerda ]['id'];
	$ok( true === Material::quitar( $e1, $a_lob, $l_cuerda ) && 1 === count( Material::lineas( $e1 ) ), 'se quita una línea antes de sacarla' );
	Material::anadir( $e1, $a_lob, $cuerda, 5 );

	echo "\n== Parte D · Material habitual ==\n";
	$r = Material::cargar_habitual( $e1, $a_lob );
	$ok( is_wp_error( $r ), 'sin material habitual escrito en la configuración, no hay qué cargar' );
	$cfg                      = Config::de();
	$cfg['material_por_tipo'] = array( 'acampada' => "2; Tienda canadiense de prueba\n3 x Cuerda de 20 m DE PRUEBA\nesto no vale\n1; Hornillo que no existe", 'tipo_inventado' => '1; Cosa' );
	Config::guardar( $cfg, $grupo['numero'] );
	$ok( array( 'acampada' ) === array_keys( Config::de()['material_por_tipo'] ), 'la configuración guarda el de los tipos que existen y descarta los inventados' );
	$ok( array( array( 'cantidad' => 2, 'descripcion' => 'Tienda canadiense de prueba' ), array( 'cantidad' => 3, 'descripcion' => 'Cuerda de 20 m DE PRUEBA' ), array( 'cantidad' => 1, 'descripcion' => 'Hornillo que no existe' ) ) === Material::partir_habitual( Config::de()['material_por_tipo']['acampada'] ), 'se leen las líneas «cantidad; nombre» (o «3 x nombre») y se ignora lo demás' );
	$r = Material::cargar_habitual( $e1, $a_lob );
	$ok( is_array( $r ) && 2 === $r['anadidas'] && array( 'Hornillo que no existe' ) === $r['no_encontradas'], 'se cargan 2 materiales y avisa del que no está en el inventario' );
	$ls = array_column( Material::lineas( $e1 ), null, 'material_id' );
	$ok( 4 === $ls[ $tienda ]['cantidad'] && 8 === $ls[ $cuerda ]['cantidad'], 'lo habitual se suma a lo ya reservado (2 + 2 tiendas; 5 + 3 cuerdas)' );
	Material::cambiar_cantidad( $e1, $a_lob, $ls[ $tienda ]['id'], 3 );

	echo "\n== Parte E · Salida y devolución ==\n";
	$ls = array_column( Material::lineas( $e1 ), null, 'material_id' );
	$ok( true === ( 1 === Material::salida( $e1, $a_lob, $ls[ $tienda ]['id'] ) ), 'se marca la salida de una línea' );
	$ok( is_wp_error( Material::quitar( $e1, $a_lob, $ls[ $tienda ]['id'] ) ) && is_wp_error( Material::cambiar_cantidad( $e1, $a_lob, $ls[ $tienda ]['id'], 1 ) ), 'lo que ya salió no se cambia ni se quita' );
	$ok( is_wp_error( Material::devolver( $e1, $a_lob, $ls[ $cuerda ]['id'], 8, '' ) ), 'no se devuelve lo que no ha salido' );
	$ok( 1 === Material::salida( $e1, $a_lob ), 'la salida de todo marca solo lo que faltaba' );
	$ok( is_wp_error( Material::salida( $e1, $a_lob ) ), 'y no repite');
	$res = Material::resumen( $e1 );
	$ok( 2 === $res['lineas'] && 0 === $res['sin_sacar'] && 2 === $res['por_devolver'], 'resumen: dos materiales, todo fuera' );
	$ok( is_wp_error( Material::devolver( $e1, $a_lob, $ls[ $tienda ]['id'], 9, '' ) ) && is_wp_error( Material::devolver( $e1, $a_lob, $ls[ $tienda ]['id'], -1, '' ) ), 'las devueltas van de 0 a lo reservado' );
	$ok( true === Material::devolver( $e1, $a_lob, $ls[ $tienda ]['id'], 2, 'Una tienda con el palo roto' ), 'se devuelven 2 de 3 tiendas con una incidencia' );
	$ok( true === Material::devolver( $e1, $a_lob, $ls[ $cuerda ]['id'], 8, '' ), 'las cuerdas vuelven enteras' );
	$ls = array_column( Material::lineas( $e1 ), null, 'material_id' );
	$ok( 1 === $ls[ $tienda ]['sin_devolver'] && 0 === $ls[ $cuerda ]['sin_devolver'] && 'Una tienda con el palo roto' === $ls[ $tienda ]['incidencia'], 'queda 1 tienda sin devolver, con su incidencia' );
	$ok( 1 === Material::resumen( $e1 )['con_faltas'], 'el resumen cuenta 1 con faltas' );
	$ok( 0 === array_column( Material::lineas( $e2 ), null, 'material_id' )[ $tienda ]['otros'], 'lo devuelto deja de contar como reservado para los demás eventos' );
	$ok( true === Material::devolver( $e1, $a_lob, $ls[ $tienda ]['id'], 3, 'Resultó que sí estaba' ), 'la devolución se puede corregir' );
	$ok( is_wp_error( Material::descontar_faltantes( $e1, $a_lob, $ls[ $tienda ]['id'] ) ), 'si no falta nada, no hay nada que descontar' );
	Material::devolver( $e1, $a_lob, $ls[ $tienda ]['id'], 2, 'Una tienda perdida' );
	$q = Material::descontar_faltantes( $e1, $a_lob, $ls[ $tienda ]['id'] );
	$ok( 1 === $q && 3 === $stock( $tienda ), 'descontar quita del inventario lo que no volvió (4 → 3)' );
	$ok( is_wp_error( Material::descontar_faltantes( $e1, $a_lob, $ls[ $tienda ]['id'] ) ) && 3 === $stock( $tienda ), 'una sola vez: no se descuenta dos veces' );
	$ok( is_wp_error( Material::devolver( $e1, $a_lob, $ls[ $tienda ]['id'], 3, '' ) ), 'y ya no se cambia la devolución' );
	$acciones = (array) $wpdb->get_col( $wpdb->prepare( 'SELECT accion FROM ' . Logger::table() . ' WHERE id > %d', $log_antes ) ); // phpcs:ignore WordPress.DB
	$ok( in_array( 'evento_material_salida', $acciones, true ) && in_array( 'evento_material_devuelto', $acciones, true ) && in_array( 'evento_material_descontado', $acciones, true ), 'todo queda en la auditoría' );
	$ok( 0 === MaterialesTable::restar( $baja, 1 ) && 10 === MaterialesTable::restar( $cuerda, 99 ) && 0 === $stock( $cuerda ) && 0 === MaterialesTable::restar( $cuerda, 1 ), 'restar nunca baja de cero ni toca lo dado de baja' );

	echo "\n== Parte F · Permisos y pantalla ==\n";
	$a_sco = $como( $u_sco );
	$ok( ! Material::puede_ver( $e1, $a_sco ) && is_wp_error( Material::anadir( $e1, $a_sco, $cuerda, 1 ) ) && is_wp_error( Material::salida( $e1, $a_sco ) ), 'un scouter de otra sección no ve ni cambia el material' );
	$a_teso = $como( $u_teso );
	$ok( ! Material::puede_ver( $e1, $a_teso ), 'Tesorería ve el evento pero no el material' );
	$a_lob = $como( $u_lob );
	$h = $pintar( array( 'evento' => (string) $e1['id'], 'pestana' => 'material' ) );
	$ok( str_contains( $h, 'Material del evento' ) && str_contains( $h, 'Tienda canadiense de prueba' ) && str_contains( $h, 'Reservar material' ) && str_contains( $h, 'Descontado del inventario' ), 'la pestaña enseña el material, las incidencias y el formulario' );
	$como( $u_teso );
	$h2 = $pintar( array( 'evento' => (string) $e1['id'], 'pestana' => 'material' ) );
	$ok( ! str_contains( $h2, 'Material del evento' ), 'quien no gestiona el evento ve el resumen, no el material' );
	$como( $u_lob );
	$antes = count( MaterialTable::de_evento( $e3['id'] ) );
	$enviar( new EventosView(), array( 'asde_action' => 'material_anadir', 'evento' => (string) $e3['id'], 'pestana' => 'material', 'material' => (string) $cuerda, 'cantidad' => '2' ), 'asde_evento_' . $e3['id'] );
	$ok( count( MaterialTable::de_evento( $e3['id'] ) ) === $antes + 1, 'el formulario reserva material' );
	$linea = array_values( array_filter( MaterialTable::de_evento( $e3['id'] ), static fn( $l ) => $l['material_id'] === $cuerda ) )[0];
	$enviar( new EventosView(), array( 'asde_action' => 'material_salida', 'evento' => (string) $e3['id'], 'pestana' => 'material', 'linea' => (string) $linea['id'] ), 'asde_evento_' . $e3['id'] );
	$ok( null !== MaterialTable::obtener( $linea['id'] )['salida_en'], 'y marca la salida' );
	$enviar( new EventosView(), array( 'asde_action' => 'material_devolver', 'evento' => (string) $e3['id'], 'pestana' => 'material', 'linea' => (string) $linea['id'], 'devueltas' => '1', 'incidencia' => 'Una se perdió' ), 'asde_evento_' . $e3['id'] );
	$ok( 1 === MaterialTable::obtener( $linea['id'] )['devueltas'], 'y la devolución' );
	$como( $u_sco );
	$enviar( new EventosView(), array( 'asde_action' => 'material_quitar', 'evento' => (string) $e3['id'], 'pestana' => 'material', 'linea' => (string) $linea['id'] ), 'asde_evento_' . $e3['id'] );
	$ok( null !== MaterialTable::obtener( $linea['id'] ), 'un formulario de quien no gestiona el evento no hace nada' );
	$como( $u_lob );

	echo "\n== Parte G · Configuración, calendario y borrado ==\n";
	$como( $u_coord );
	$_POST    = array( 'asde_action' => 'material_guardar', '_wpnonce' => wp_create_nonce( 'asde_eventos_config' ), 'material_acampada' => "5; Tienda canadiense de prueba\n" );
	$_REQUEST = $_POST;
	try {
		( new EventosConfigView() )->handle_post();
	} catch ( RuntimeException $x ) {
		// Redirige.
	}
	$_POST = array();
	$_REQUEST = array();
	$ok( "5; Tienda canadiense de prueba" === trim( Config::de()['material_por_tipo']['acampada'] ?? '' ), 'Coordinación guarda el material habitual desde la configuración' );
	$cfg_html = ( new EventosConfigView() )->render();
	$ok( str_contains( $cfg_html, 'Material habitual de cada tipo de evento' ), 'la configuración tiene su cuadro' );
	$ok( in_array( 'material', Plantillas::ENLACES, true ), 'las tareas del calendario pueden enlazar a la pestaña Material' );
	$por = array_column( Plantillas::incorporada()['tareas'], null, 'titulo' );
	$ok( 'material' === $por['Preparar las intendencias y secciones el material de acampada']['enlace'], '«Preparar el material de acampada» lleva a ella' );
	$como( $u_lob );
	$ok( MaterialTable::contar( $e3['id'] ) > 0, 'el evento tiene material' );
	$ok( true === Servicio::borrar( EventosTable::obtener( $e3['id'] ), $a_lob ) && 0 === MaterialTable::contar( $e3['id'] ), 'al borrar el borrador se borra su material' );
} finally {
	remove_filter( 'wp_redirect', $redirigir, 1 );
	remove_filter( 'send_auth_cookies', '__return_false' );
	remove_filter( 'pre_wp_mail', '__return_true' );
	remove_filter( 'pre_http_request', $cortar_crm, 2 );
	$_POST = array();
	$_GET  = array();
	$crm->desactivar();
	wp_using_ext_object_cache( $cache_externa );
	wp_set_current_user( 0 );

	$lista = implode( ',', array_map( 'intval', $ids ) ) ?: '0';
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . MaterialTable::table() . ' WHERE id > %d', $mat_antes ) ); // phpcs:ignore WordPress.DB
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . MaterialesTable::table() . ' WHERE id > %d', $inv_antes ) ); // phpcs:ignore WordPress.DB
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . TareasTable::table() . ' WHERE id > %d', $tar_antes ) ); // phpcs:ignore WordPress.DB
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . PartidasTable::table() . ' WHERE id > %d', $par_antes ) ); // phpcs:ignore WordPress.DB
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . InscripcionesTable::table() . ' WHERE id > %d', $ins_antes ) ); // phpcs:ignore WordPress.DB
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . EventosTable::table() . ' WHERE id > %d', $ev_antes ) ); // phpcs:ignore WordPress.DB
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Logger::table() . " WHERE id > %d AND ( user_id IN ($lista) OR objeto IN ( 'evento', 'inventario', 'grupo' ) )", $log_antes ) ); // phpcs:ignore WordPress.DB
	foreach ( $ids as $uid ) {
		delete_transient( 'asde_ap_notices_' . $uid );
		Ambito::olvidar( (int) $uid );
		wp_delete_user( (int) $uid );
	}
	if ( null === $cfg_antes ) {
		delete_option( $opcion_cfg );
	} else {
		update_option( $opcion_cfg, $cfg_antes, false );
	}
	if ( null === $modulos_antes ) {
		delete_option( 'asde_ap_modulos' );
	} else {
		update_option( 'asde_ap_modulos', $modulos_antes );
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

	$quedan = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users} WHERE user_login LIKE 'prueba-ma-%'" ); // phpcs:ignore WordPress.DB
	$ok( 0 === $quedan && 0 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . EventosTable::table() . ' WHERE id > ' . (int) $ev_antes ) && 0 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . MaterialTable::table() . ' WHERE id > ' . (int) $mat_antes ), 'limpieza: sin usuarios, eventos ni material de la prueba' ); // phpcs:ignore WordPress.DB
}

echo $fails ? "\n$fails FALLOS\n" : "\nTODO OK (nada tocado en el CRM real; el sitio queda como estaba)\n";
