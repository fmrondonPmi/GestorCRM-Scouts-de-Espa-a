<?php
/**
 * Pruebas de la v0.43 (entorno LOCAL, doc 43 y 49): el rol Intendente (responsable de material) y el aviso por email de los alérgenos
 * del menú que siguen sin resolver (P-192). Ejecutar desde app/public del site Local:
 *
 *   wp eval-file <ruta completa>/wp-content/plugins/asde-area-privada/tests/eventos-intendente.php
 *
 * Con el CRM simulado y datos inventados (no toca el real). Al terminar se borra lo creado.
 */
defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/lib/CrmSimulado.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Auth\Ambito;
use AsdeBalears\AreaPrivada\Crm\Escritura;
use AsdeBalears\AreaPrivada\Crm\PersonaRepository;
use AsdeBalears\AreaPrivada\Domain\Grupo;
use AsdeBalears\AreaPrivada\Domain\Grupos;
use AsdeBalears\AreaPrivada\Eventos\Config;
use AsdeBalears\AreaPrivada\Eventos\Cuentas;
use AsdeBalears\AreaPrivada\Eventos\EventosTable;
use AsdeBalears\AreaPrivada\Eventos\InscripcionesTable;
use AsdeBalears\AreaPrivada\Eventos\Material;
use AsdeBalears\AreaPrivada\Eventos\MaterialTable;
use AsdeBalears\AreaPrivada\Eventos\PartidasTable;
use AsdeBalears\AreaPrivada\Eventos\Servicio;
use AsdeBalears\AreaPrivada\Eventos\TareasTable;
use AsdeBalears\AreaPrivada\Frontend\Notices;
use AsdeBalears\AreaPrivada\Frontend\Views\EventosView;
use AsdeBalears\AreaPrivada\Install\Roles;
use AsdeBalears\AreaPrivada\Inventario\MaterialesTable;
use AsdeBalears\AreaPrivada\Menus\AvisoAlergenos;
use AsdeBalears\AreaPrivada\Menus\AvisosTable;
use AsdeBalears\AreaPrivada\Menus\IngredientesTable;
use AsdeBalears\AreaPrivada\Menus\MenuEvento;
use AsdeBalears\AreaPrivada\Menus\MenuTable;
use AsdeBalears\AreaPrivada\Menus\RecetasTable;
use AsdeBalears\AreaPrivada\Menus\Validacion as V;
use AsdeBalears\AreaPrivada\Socios\SociosTable;
use AsdeBalears\AreaPrivada\Usuarios\AltaService;
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
foreach ( get_users( array( 'search' => 'prueba-in-*', 'search_columns' => array( 'user_login' ) ) ) as $u ) {
	exit( 'Ya existe el usuario ' . $u->user_login . " (¿una ejecución anterior interrumpida?). Bórralo antes de repetir la prueba.\n" );
}
wp_set_current_user( 0 );
Roles::install();

$max_id       = static fn( string $t ) => (int) $wpdb->get_var( "SELECT COALESCE(MAX(id), 0) FROM $t" ); // phpcs:ignore WordPress.DB
$log_antes    = $max_id( Logger::table() );
$ev_antes     = $max_id( EventosTable::table() );
$ins_antes    = $max_id( InscripcionesTable::table() );
$tar_antes    = $max_id( TareasTable::table() );
$par_antes    = $max_id( PartidasTable::table() );
$men_antes    = $max_id( MenuTable::table() );
$avi_antes    = $max_id( AvisosTable::table() );
$rec_antes    = $max_id( RecetasTable::table() );
$lin_antes    = $max_id( RecetasTable::table_lineas() );
$ing_antes    = $max_id( IngredientesTable::table() );
$mat_antes    = $max_id( MaterialTable::table() );
$inv_antes    = $max_id( MaterialesTable::table() );
$email_antes  = $max_id( RegistroEmails::table() );
$socios_antes = array_column( (array) $wpdb->get_results( 'SELECT * FROM ' . SociosTable::table(), ARRAY_A ), null, 'num_socio_asde' ); // phpcs:ignore WordPress.DB
$modulos_antes = get_option( 'asde_ap_modulos', null );
$eventos_cfg_antes = get_option( 'asde_ap_eventos_' . $grupo['numero'], null ); // La prueba guarda la configuración de eventos del grupo (doc 60: antes no se restauraba).

$cache_externa = wp_using_ext_object_cache( true );
$correos       = array();
add_filter(
	'pre_wp_mail',
	static function ( $r, $atts ) use ( &$correos ) {
		$correos[] = array( 'to' => implode( ',', (array) $atts['to'] ), 'message' => (string) $atts['message'], 'subject' => (string) $atts['subject'] );
		return true;
	},
	10,
	2
);
$cortar_crm = static function ( $pre, $args, $url ) {
	return false === $pre && str_contains( (string) $url, '/rest.php' ) ? new WP_Error( 'prueba', 'CRM real cortado durante la prueba' ) : $pre;
};
add_filter( 'pre_http_request', $cortar_crm, 2, 3 );

$P   = array( 'Asde_Prueba_Crm', 'persona' );
$sn  = static fn( string $alergia, string $intol, string $reg ) => array( 'alimentacion_alergia_c' => $alergia, 'alimentacion_intolerancia_c' => $intol, 'alimentacion_regimen_c' => $reg );
$crm = new Asde_Prueba_Crm(
	array(
		$P( 71, 'Ana', 'Intendencia Gil', '1990-01-01', 'Scouters', $sn( 'No', 'No', 'No' ) ),
		$P( 72, 'Leo', 'Intendencia Luz', '2016-05-10', 'Lobatos', $sn( 'Sí', 'No', 'No' ) + array( 'alimentacion_alerg_alimentos_c' => 'Gluten' ) ),
		$P( 73, 'Eva', 'Intendencia Coll', '2016-06-10', 'Lobatos', $sn( 'Sí', 'No', 'No' ) + array( 'alimentacion_alerg_alimentos_c' => 'Huevos' ) ),
		$P( 77, 'Cris', 'Intendencia Cocina', '1985-01-01', 'Scouters', $sn( 'No', 'No', 'No' ) ),
		$P( 78, 'Marta', 'Intendencia Coord', '1980-01-01', 'Scouters', $sn( 'No', 'No', 'No' ) ),
		$P( 79, 'Ian', 'Intendencia Material', '1982-01-01', 'Scouters', $sn( 'No', 'No', 'No' ) ),
	),
	$grupo['crm_id']
);
Grupo::forzar( $grupo );
\AsdeBalears\AreaPrivada\Domain\Modulos::guardar( (string) $grupo['numero'], array_keys( \AsdeBalears\AreaPrivada\Domain\Modulos::LISTA ) );
$crm->activar();
Escritura::usar_para_pruebas( $crm );
\AsdeBalears\AreaPrivada\Menus\Biblioteca::sembrar();

$ids   = array();
$crear = static function ( string $login, array $roles, array $meta, string $email = '' ) use ( &$ids, $grupo ): int {
	$id = wp_insert_user( array( 'user_login' => $login, 'user_email' => $email, 'user_pass' => wp_generate_password( 24 ), 'role' => $roles[0] ) );
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
$u_dir   = $crear( 'prueba-in-dir', array( Roles::SCOUTER ), array( Usuarios::META_SOCIO => '990071', Usuarios::META_SECCION => 'lobatos' ), 'dir.in@example.invalid' );
$u_coord = $crear( 'prueba-in-coord', array( Roles::SCOUTER, Roles::COORDINACION ), array( Usuarios::META_SOCIO => '990078', Usuarios::META_SECCION => 'rovers' ), 'coord.in@example.invalid' );
$u_cocina = $crear( 'prueba-in-cocina', array( Roles::SCOUTER ), array( Usuarios::META_SOCIO => '990077', Usuarios::META_SECCION => 'rovers' ), 'cocina.in@example.invalid' );
$u_int   = $crear( 'prueba-in-intendente', array( Roles::INTENDENTE ), array( Usuarios::META_SOCIO => '990079' ), 'intendente.in@example.invalid' );
$u_otro  = $crear( 'prueba-in-otro', array( Roles::SCOUTER ), array( Usuarios::META_SOCIO => '990070', Usuarios::META_SECCION => 'scouts' ) );
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
$enviar = static function ( array $campos, string $nonce ): string {
	$_POST    = $campos + array( '_wpnonce' => wp_create_nonce( $nonce ) );
	$_REQUEST = $_POST;
	try {
		( new EventosView() )->handle_post();
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
$comun = static function ( string $nombre ): array {
	foreach ( RecetasTable::visibles() as $r ) {
		if ( $r['nombre'] === $nombre && IngredientesTable::COMUN === $r['grupo'] ) {
			return $r;
		}
	}
	return array();
};

try {
	Config::guardar( Config::defaults(), $grupo['numero'] );

	echo "== Parte A · El rol Intendente ==\n";
	$def = Roles::definitions()[ Roles::INTENDENTE ] ?? null;
	$ok( null !== $def && in_array( Roles::INTENDENTE, Roles::ALL, true ) && get_role( Roles::INTENDENTE ) instanceof WP_Role, 'el rol existe, está en la lista de roles del área privada y se instala en WordPress' );
	$ok( ! empty( $def[1]['asde_eventos_material'] ) && ! empty( $def[1]['asde_inventario'] ) && empty( $def[1]['asde_eventos_gestion'] ) && empty( $def[1]['asde_view_health'] ) && empty( $def[1]['asde_inventario_aprobar'] ), 'lleva el material y el inventario, pero no gestiona eventos, ni ve la salud, ni da el visto bueno a las compras' );
	$ok( 'Intendente (responsable de material)' === $def[0] && '' !== Roles::label( Roles::INTENDENTE ), 'tiene etiqueta' );
	$ok( in_array( 'asde_eventos_material', \AsdeBalears\AreaPrivada\Domain\Modulos::LISTA[ \AsdeBalears\AreaPrivada\Domain\Modulos::EVENTOS ][2], true ), 'la capacidad se da y se quita con el módulo de Eventos' );
	$ok( AltaService::ROL_POR_CARGO['responsablematerialGS'] === Roles::INTENDENTE && in_array( Roles::INTENDENTE, AltaService::ROLES_RESPONSABILIDAD, true ) && in_array( Roles::INTENDENTE, AltaService::ROLES_PERSONA, true ), 'en el alta, el cargo «Responsable de Material» del CRM sugiere el rol, que es una responsabilidad de un scouter' );
	$repo = PersonaRepository::make();
	$ana  = $repo->obtener( '990071' );
	$eleg = ( new AltaService( $repo, new \AsdeBalears\AreaPrivada\Socios\Numeracion( $repo ) ) )->elegibilidad( $ana );
	$ok( array_key_exists( Roles::INTENDENTE, $eleg ) && null === $eleg[ Roles::INTENDENTE ], 'un adulto con membresía de scouter puede ser Intendente' );
	$ok( 'scouters' === \AsdeBalears\AreaPrivada\Frontend\Ayuda::manual_de( array( Roles::INTENDENTE ), false ), 'su ayuda es el manual de scouters' );

	echo "\n== Parte B · Qué ve y qué hace el Intendente ==\n";
	$a_dir = $como( $u_dir );
	$ini   = wp_date( 'Y-m-d', strtotime( '+30 days' ) );
	$fin   = wp_date( 'Y-m-d', strtotime( '+31 days' ) );
	$enviar(
		array( 'asde_action' => 'evento_crear', 'tipo' => 'acampada', 'nombre' => 'Acampada del intendente', 'ambito' => 'secciones', 'secciones' => array( 'lobatos' ), 'inicio_fecha' => $ini, 'inicio_hora' => '18:00', 'fin_fecha' => $fin, 'fin_hora' => '16:00', 'lugar' => 'Albergue de prueba', 'previstas_lobatos' => '20', 'con_coste' => '1' ),
		'asde_evento_nuevo'
	);
	$e = EventosTable::obtener( $max_id( EventosTable::table() ) );
	foreach ( array( array( '990071', 'director' ), array( '990078', 'monitor' ), array( '990077', 'apoyo' ) ) as list( $socio, $papel ) ) {
		$enviar( array( 'asde_action' => 'evento_equipo_anadir', 'evento' => (string) $e['id'], 'pestana' => 'equipo', 'socio' => $socio, 'papel' => $papel ), 'asde_evento_' . $e['id'] );
	}
	foreach ( array( '990072', '990073' ) as $socio ) {
		InscripcionesTable::inscribir( $e['id'], $socio, array( 'secciones' => array( 'lobatos' ), 'adulto' => 0, 'estado' => 'confirmada', 'salud_revisada' => current_time( 'mysql', true ) ) );
	}

	$a_int = $como( $u_int );
	$ok( Servicio::puede_entrar() && Servicio::ve( $e, $a_int ) && Servicio::solo_material( $e, $a_int ), 'entra en Eventos, ve el evento (aunque no sea de su sección ni esté en su equipo) y solo por el material' );
	$ok( ! Servicio::en_gestion( $e, $a_int ) && ! Servicio::puede_editar( $e, $a_int ) && ! Servicio::puede_crear( $a_int ), 'no gestiona el evento: no lo edita ni crea otros' );
	$ok( in_array( $e['id'], array_column( Servicio::de_agenda( $a_int ), 'id' ), true ), 'el evento sale en su agenda' );
	$ok( Material::puede_ver( $e, $a_int ) && Material::puede_editar( $e, $a_int ), 'puede ver y cambiar el material del evento' );
	$ok( ! Cuentas::puede_ver( $e, $a_int ) && ! MenuEvento::puede_ver( $e, $a_int ) && ! V::puede_ver_quienes( $e, $a_int ), 'no ve las cuentas, ni el menú, ni quién tiene alergias' );
	$tienda = MaterialesTable::crear( MaterialesTable::validar( array( 'descripcion' => 'Tienda del intendente de prueba', 'tipo' => '0', 'cantidad' => '5' ) ) );
	$r = Material::anadir( $e, $a_int, $tienda, 3 );
	$ok( true === $r && 1 === MaterialTable::contar( $e['id'] ), 'reserva material para el evento' );
	$lin = MaterialTable::de_evento( $e['id'] )[0];
	$r   = Material::salida( $e, $a_int, $lin['id'] );
	$ok( ! is_wp_error( $r ), 'anota la salida' );
	$r = Material::devolver( $e, $a_int, $lin['id'], 2, 'Una tienda rota' );
	$ok( ! is_wp_error( $r ), 'anota la devolución (con una incidencia)' );
	$r = Material::descontar_faltantes( $e, $a_int, $lin['id'] );
	$ok( ! is_wp_error( $r ) && 4 === (int) MaterialesTable::obtener( $tienda )['cantidad'], 'y descuenta del inventario lo que no volvió' );
	$h = $pintar( array( 'evento' => (string) $e['id'] ) );
	$ok( str_contains( $h, 'pestana=material' ) && ! str_contains( $h, 'pestana=inscripciones' ) && ! str_contains( $h, 'pestana=menu' ) && ! str_contains( $h, 'pestana=cuentas' ) && ! str_contains( $h, 'pestana=presupuesto' ) && ! str_contains( $h, 'pestana=equipo' ) && ! str_contains( $h, 'pestana=calendario' ), 'en la ficha del evento solo tiene las pestañas Resumen y Material' );
	$h = $pintar( array( 'evento' => (string) $e['id'], 'pestana' => 'inscripciones' ) );
	$ok( ! str_contains( $h, 'Leo Intendencia' ) && ! str_contains( $h, 'name="asde_action" value="evento_inscribir' ), 'si pide otra pestaña por la dirección, le sale el resumen (sin inscritos)' );
	$h = $pintar( array( 'evento' => (string) $e['id'], 'pestana' => 'material' ) );
	$ok( str_contains( $h, 'Tienda del intendente de prueba' ), 'y en el Material ve lo reservado' );

	$a_otro = $como( $u_otro );
	$ok( ! Servicio::ve( $e, $a_otro ) && ! Material::puede_ver( $e, $a_otro ) && ! Servicio::solo_material( $e, $a_otro ), 'un scouter de otra sección no ve ese evento ni su material' );
	$a_dir = $como( $u_dir );
	$ok( Material::puede_ver( $e, $a_dir ) && ! Servicio::solo_material( $e, $a_dir ), 'quien gestiona el evento sigue viendo el material con todas sus pestañas' );

	echo "\n== Parte C · Aviso de alérgenos sin resolver (P-192) ==\n";
	$enviar( array( 'asde_action' => 'tarea_aplicar', 'evento' => (string) $e['id'], 'pestana' => 'calendario', 'plantilla' => 'acampada' ), 'asde_evento_' . $e['id'] );
	$revisar = null;
	foreach ( TareasTable::de_evento( $e['id'] ) as $t ) {
		if ( 'menu' === $t['enlace'] && '' === $t['auto'] ) {
			$revisar = $t;
		}
	}
	$ok( null !== $revisar, 'el calendario de la acampada tiene la tarea «Revisar alergias…», con enlace al menú y sin medirse sola' );
	$toca = TareasTable::fecha( $revisar );
	$antes_de = wp_date( 'Y-m-d', strtotime( $toca . ' 12:00 UTC' ) - DAY_IN_SECONDS );

	$a_dir = $como( $u_dir );
	$mac  = $comun( 'Macarrones con tomate' ); // Gluten y lácteos.
	$alb  = $comun( 'Albóndigas en salsa de tomate' ); // Gluten y huevos.
	$lent = $comun( 'Lentejas estofadas' );
	$p_mac  = MenuEvento::anadir( $e, $a_dir, $ini, 'comida', $mac['id'] );
	$p_lent = MenuEvento::anadir( $e, $a_dir, $ini, 'cena', $lent['id'] );
	$pend = AvisoAlergenos::pendientes( $e );
	$ok( 1 === count( $pend ) && 'gluten' === $pend[0]['codigo'] && 1 === $pend[0]['n'], 'los macarrones llevan el gluten de Leo: un alérgeno pendiente' );
	$dest = array_map( static fn( $u ) => $u->ID, AvisoAlergenos::destinatarios( $e ) );
	$ok( 2 === count( $dest ) && in_array( $u_dir, $dest, true ) && in_array( $u_coord, $dest, true ), 'lo reciben el director y el monitor del equipo, no el apoyo (que recibe la hoja de cocina)' );
	$ok( 0 === AvisoAlergenos::diario( $antes_de, array( $e['id'] ) ) && ! $correos, 'antes de la fecha de la tarea no se avisa' );

	$n = AvisoAlergenos::diario( $toca, array( $e['id'] ) );
	$ok( 2 === $n && 2 === count( $correos ), 'llegada la fecha de la tarea, salen dos emails (director y monitor)' );
	$a_dir_mail = array_values( array_filter( $correos, static fn( $c ) => str_contains( $c['to'], 'dir.in@' ) ) )[0] ?? array( 'message' => '', 'subject' => '' );
	$a_coord_mail = array_values( array_filter( $correos, static fn( $c ) => str_contains( $c['to'], 'coord.in@' ) ) )[0] ?? array( 'message' => '', 'subject' => '' );
	$ok( str_contains( $a_dir_mail['message'], 'Macarrones con tomate' ) && str_contains( $a_dir_mail['message'], 'Gluten' ) && str_contains( $a_dir_mail['message'], '1 persona' ), 'el director (que no ve la salud de todo el grupo) recibe el plato, el alérgeno y cuántas personas' );
	$ok( ! str_contains( $a_dir_mail['message'], 'Leo' ), '…pero ningún nombre' );
	$ok( str_contains( $a_coord_mail['message'], 'Leo Intendencia Luz' ) && str_contains( $a_coord_mail['message'], 'Macarrones con tomate' ), 'la coordinación, que ve la salud de todos, recibe también el nombre' );
	$ok( ! str_contains( $a_dir_mail['message'] . $a_coord_mail['message'], 'Eva Intendencia' ) && str_contains( $a_dir_mail['subject'], 'Alérgenos sin resolver' ), 'no aparece quien no está afectado y el asunto lo dice' );
	$filas = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . RegistroEmails::table() . ' WHERE id > %d', $email_antes ), ARRAY_A ); // phpcs:ignore WordPress.DB
	$json  = (string) wp_json_encode( $filas );
	$ok( 2 === count( $filas ) && ! str_contains( $json, 'Leo' ) && ! str_contains( $json, 'Gluten' ) && ! str_contains( $json, 'dir.in@example' ), 'el registro de emails solo guarda el aviso: ni nombres, ni alérgenos, ni el email completo' );
	$prev = \AsdeBalears\AreaPrivada\Eventos\Avisos::html( array( 'tipo' => AvisoAlergenos::TIPO, 'grupo' => 'G', 'evento' => 'Acampada', 'numero' => 'E1', 'url' => 'https://example.invalid/' ), '' );
	$ok( ! str_contains( $prev, 'Leo' ) && ! str_contains( $prev, 'Macarrones' ), 'la vista previa del registro no vuelve a componer los datos de salud' );

	$correos = array();
	$ok( 0 === AvisoAlergenos::diario( $toca, array( $e['id'] ) ) && ! $correos, 'al día siguiente, con lo mismo pendiente, no se repite' );
	$p_alb = MenuEvento::anadir( $e, $a_dir, $fin, 'comida', $alb['id'] );
	$ok( 2 === AvisoAlergenos::diario( $toca, array( $e['id'] ) ), 'pero si el menú cambia y hay algo nuevo sin resolver, se vuelve a avisar' );
	$correos = array();

	foreach ( array( array( $p_mac, 'gluten' ), array( $p_alb, 'gluten' ), array( $p_alb, 'huevos' ) ) as list( $plato, $cod ) ) {
		V::confirmar( $e, $a_dir, $plato, $cod, 'racion' );
	}
	$ok( ! AvisoAlergenos::pendientes( $e ) && 0 === AvisoAlergenos::diario( $toca, array( $e['id'] ) ) && ! $correos, 'con todo confirmado (la alternativa de cada plato) ya no hay nada que avisar' );
	$ok( false === get_option( 'asde_ap_aviso_alerg_' . $e['id'], false ), 'y se olvida lo avisado' );

	AvisosTable::borrar_evento( $e['id'] );
	$cfg = Config::de();
	Config::guardar( array( 'avisos_tareas' => false ) + $cfg, $grupo['numero'] );
	$ok( 0 === AvisoAlergenos::diario( $toca, array( $e['id'] ) ) && ! $correos, 'con los avisos del calendario desactivados en la configuración, no sale nada' );
	Config::guardar( $cfg, $grupo['numero'] );
	$ok( 2 === AvisoAlergenos::diario( $toca, array( $e['id'] ) ), 'al activarlos otra vez, vuelve a avisar de lo pendiente' );
	$correos = array();
	AvisoAlergenos::olvidar( $e['id'] );
	$wpdb->update( EventosTable::table(), array( 'estado' => EventosTable::CANCELADO ), array( 'id' => $e['id'] ) ); // phpcs:ignore WordPress.DB
	$ok( 0 === AvisoAlergenos::diario( $toca, array( $e['id'] ) ) && ! $correos, 'un evento cancelado no genera avisos' );
	$wpdb->update( EventosTable::table(), array( 'estado' => $e['estado'] ), array( 'id' => $e['id'] ) ); // phpcs:ignore WordPress.DB
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . TareasTable::table() . ' WHERE evento_id = %d AND enlace = %s', $e['id'], 'menu' ) ); // phpcs:ignore WordPress.DB
	$ok( 0 === AvisoAlergenos::diario( $toca, array( $e['id'] ) ), 'sin la tarea «Revisar alergias…» en el calendario del evento, no se avisa' );
} catch ( Throwable $e ) {
	++$fails;
	echo 'FAIL excepcion: ', $e->getMessage(), ' (', basename( $e->getFile() ), ':', $e->getLine(), ")\n";
} finally {
	remove_filter( 'wp_redirect', $redirigir, 1 );
	remove_filter( 'send_auth_cookies', '__return_false' );
	remove_all_filters( 'pre_wp_mail' );
	remove_filter( 'pre_http_request', $cortar_crm, 2 );
	$_POST = array();
	$_GET  = array();
	$crm->desactivar();
	wp_using_ext_object_cache( $cache_externa );
	wp_set_current_user( 0 );

	foreach ( (array) $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . EventosTable::table() . ' WHERE id > %d', $ev_antes ) ) as $evid ) { // phpcs:ignore WordPress.DB
		AvisoAlergenos::olvidar( (int) $evid );
	}
	$lista = implode( ',', array_map( 'intval', $ids ) ) ?: '0';
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . MaterialTable::table() . ' WHERE id > %d', $mat_antes ) ); // phpcs:ignore WordPress.DB
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . MaterialesTable::table() . ' WHERE id > %d', $inv_antes ) ); // phpcs:ignore WordPress.DB
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . AvisosTable::table() . ' WHERE id > %d', $avi_antes ) ); // phpcs:ignore WordPress.DB
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . MenuTable::table() . ' WHERE id > %d', $men_antes ) ); // phpcs:ignore WordPress.DB
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . RecetasTable::table_lineas() . ' WHERE id > %d', $lin_antes ) ); // phpcs:ignore WordPress.DB
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . RecetasTable::table() . ' WHERE id > %d', $rec_antes ) ); // phpcs:ignore WordPress.DB
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . IngredientesTable::table() . ' WHERE id > %d', $ing_antes ) ); // phpcs:ignore WordPress.DB
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . TareasTable::table() . ' WHERE id > %d', $tar_antes ) ); // phpcs:ignore WordPress.DB
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . PartidasTable::table() . ' WHERE id > %d', $par_antes ) ); // phpcs:ignore WordPress.DB
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . InscripcionesTable::table() . ' WHERE id > %d', $ins_antes ) ); // phpcs:ignore WordPress.DB
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . EventosTable::table() . ' WHERE id > %d', $ev_antes ) ); // phpcs:ignore WordPress.DB
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . RegistroEmails::table() . ' WHERE id > %d', $email_antes ) ); // phpcs:ignore WordPress.DB
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Logger::table() . " WHERE id > %d AND ( user_id IN ($lista) OR objeto IN ( 'evento', 'menus', 'inventario' ) )", $log_antes ) ); // phpcs:ignore WordPress.DB
	foreach ( $ids as $uid ) {
		delete_transient( 'asde_ap_notices_' . $uid );
		Ambito::olvidar( (int) $uid );
		wp_delete_user( (int) $uid );
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
	if ( null === $eventos_cfg_antes ) {
		delete_option( 'asde_ap_eventos_' . $grupo['numero'] );
	} else {
		update_option( 'asde_ap_eventos_' . $grupo['numero'], $eventos_cfg_antes, false );
	}
	Grupo::forzar( null );

	$quedan = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users} WHERE user_login LIKE 'prueba-in-%'" ); // phpcs:ignore WordPress.DB
	$ok( 0 === $quedan && 0 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . EventosTable::table() . ' WHERE id > ' . (int) $ev_antes ), 'limpieza: sin usuarios ni eventos de la prueba' ); // phpcs:ignore WordPress.DB
}

echo $fails ? "\n$fails FALLOS\n" : "\nTODO OK (nada tocado en el CRM real; el sitio queda como estaba)\n";
