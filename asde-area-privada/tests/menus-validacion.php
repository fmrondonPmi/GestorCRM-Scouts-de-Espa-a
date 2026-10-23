<?php
/**
 * Pruebas de la v0.41, B3 (entorno LOCAL, doc 49): la validación del menú contra la alimentación de los asistentes, los cuatro niveles,
 * las confirmaciones, quién ve nombres, la hoja de cocina (nombre y alérgeno), su envío y que la salud no se guarde en ningún sitio.
 * Ejecutar desde app/public del site Local:
 *
 *   wp eval-file <ruta completa>/wp-content/plugins/asde-area-privada/tests/menus-validacion.php
 *
 * Con el CRM simulado y datos inventados (no toca el real). Al terminar se borra lo creado.
 */
defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/lib/CrmSimulado.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Auth\Ambito;
use AsdeBalears\AreaPrivada\Crm\Escritura;
use AsdeBalears\AreaPrivada\Domain\Grupo;
use AsdeBalears\AreaPrivada\Domain\Grupos;
use AsdeBalears\AreaPrivada\Eventos\EventosTable;
use AsdeBalears\AreaPrivada\Eventos\InscripcionesTable;
use AsdeBalears\AreaPrivada\Eventos\PartidasTable;
use AsdeBalears\AreaPrivada\Eventos\Servicio;
use AsdeBalears\AreaPrivada\Eventos\TareasTable;
use AsdeBalears\AreaPrivada\Frontend\Notices;
use AsdeBalears\AreaPrivada\Frontend\Views\EventosView;
use AsdeBalears\AreaPrivada\Install\Roles;
use AsdeBalears\AreaPrivada\Menus\AvisosTable;
use AsdeBalears\AreaPrivada\Menus\HojaCocina;
use AsdeBalears\AreaPrivada\Menus\IngredientesTable;
use AsdeBalears\AreaPrivada\Menus\MenuEvento;
use AsdeBalears\AreaPrivada\Menus\MenuTable;
use AsdeBalears\AreaPrivada\Menus\RecetasTable;
use AsdeBalears\AreaPrivada\Menus\Validacion as V;
use AsdeBalears\AreaPrivada\Socios\SociosTable;
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
foreach ( get_users( array( 'search' => 'prueba-va-*', 'search_columns' => array( 'user_login' ) ) ) as $u ) {
	exit( 'Ya existe el usuario ' . $u->user_login . " (¿una ejecución anterior interrumpida?). Bórralo antes de repetir la prueba.\n" );
}
wp_set_current_user( 0 );

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
$email_antes  = $max_id( RegistroEmails::table() );
$socios_antes = array_column( (array) $wpdb->get_results( 'SELECT * FROM ' . SociosTable::table(), ARRAY_A ), null, 'num_socio_asde' ); // phpcs:ignore WordPress.DB
$modulos_antes = get_option( 'asde_ap_modulos', null );
$eventos_cfg_antes = get_option( 'asde_ap_eventos_' . $grupo['numero'], null ); // La prueba guarda la configuración de eventos del grupo (doc 60: antes no se restauraba).

$cache_externa = wp_using_ext_object_cache( true );
$correos       = 0;
add_filter(
	'pre_wp_mail',
	static function ( $r ) use ( &$correos ) {
		++$correos;
		return true;
	}
);
$cortar_crm = static function ( $pre, $args, $url ) {
	return false === $pre && str_contains( (string) $url, '/rest.php' ) ? new WP_Error( 'prueba', 'CRM real cortado durante la prueba' ) : $pre;
};
add_filter( 'pre_http_request', $cortar_crm, 2, 3 );

$P   = array( 'Asde_Prueba_Crm', 'persona' );
$sn  = static fn( string $alergia, string $intol, string $reg ) => array( 'alimentacion_alergia_c' => $alergia, 'alimentacion_intolerancia_c' => $intol, 'alimentacion_regimen_c' => $reg );
$crm = new Asde_Prueba_Crm(
	array(
		$P( 61, 'Ana', 'Valida Gil', '1990-01-01', 'Scouters', $sn( 'No', 'No', 'No' ) ),
		$P( 62, 'Leo', 'Valida Luz', '2016-05-10', 'Lobatos', $sn( 'Sí', 'No', 'No' ) + array( 'alimentacion_alerg_alimentos_c' => 'Gluten, Huevos' ) ),
		$P( 63, 'Eva', 'Valida Coll', '2016-06-10', 'Lobatos', $sn( 'No', 'Sí', 'No' ) + array( 'alimentacion_intol_alimentos_c' => 'Lactosa' ) ),
		$P( 64, 'Tom', 'Valida Ruiz', '2016-07-10', 'Lobatos', $sn( 'No', 'No', 'Sí' ) + array( 'alimentacion_regimen_tipo_y__c' => 'Dieta vegana' ) ),
		$P( 65, 'Pau', 'Valida Mas', '2016-08-10', 'Lobatos', $sn( 'Sí', 'No', 'No' ) + array( 'alimentacion_alerg_alimentos_c' => 'pimiento' ) ),
		$P( 66, 'Mia', 'Valida Sol', '2016-09-10', 'Lobatos', array() ),
		$P( 67, 'Cris', 'Valida Cocina', '1985-01-01', 'Scouters', $sn( 'No', 'No', 'No' ) ),
	),
	$grupo['crm_id']
);
Grupo::forzar( $grupo );
\AsdeBalears\AreaPrivada\Domain\Modulos::guardar( (string) $grupo['numero'], array_keys( \AsdeBalears\AreaPrivada\Domain\Modulos::LISTA ) );
$crm->activar();
Escritura::usar_para_pruebas( $crm );
\AsdeBalears\AreaPrivada\Menus\Biblioteca::sembrar();

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
$u_lob   = $crear( 'prueba-va-lobatos', array( Roles::SCOUTER ), array( Usuarios::META_SOCIO => '990061', Usuarios::META_SECCION => 'lobatos' ) );
$u_coord = $crear( 'prueba-va-coord', array( Roles::SCOUTER, Roles::COORDINACION ), array( Usuarios::META_SOCIO => '990061', Usuarios::META_SECCION => 'rovers' ) );
$u_cocina = $crear( 'prueba-va-cocina', array( Roles::SCOUTER ), array( Usuarios::META_SOCIO => '990067', Usuarios::META_SECCION => 'rovers' ) );
wp_update_user( array( 'ID' => $u_cocina, 'user_email' => 'cris.valida@example.invalid' ) );
$u_otro  = $crear( 'prueba-va-otro', array( Roles::SCOUTER ), array( Usuarios::META_SOCIO => '990060', Usuarios::META_SECCION => 'scouts' ) );
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
	\AsdeBalears\AreaPrivada\Eventos\Config::guardar( \AsdeBalears\AreaPrivada\Eventos\Config::defaults(), $grupo['numero'] );
	$a_lob = $como( $u_lob );
	$ini   = wp_date( 'Y-m-d', strtotime( '+30 days' ) );
	$fin   = wp_date( 'Y-m-d', strtotime( '+31 days' ) );
	$enviar(
		array( 'asde_action' => 'evento_crear', 'tipo' => 'acampada', 'nombre' => 'Acampada de la validación', 'ambito' => 'secciones', 'secciones' => array( 'lobatos' ), 'inicio_fecha' => $ini, 'inicio_hora' => '18:00', 'fin_fecha' => $fin, 'fin_hora' => '16:00', 'lugar' => 'Albergue de prueba', 'previstas_lobatos' => '20', 'con_coste' => '1' ),
		'asde_evento_nuevo'
	);
	$e = EventosTable::obtener( $max_id( EventosTable::table() ) );
	foreach ( array( array( '990061', 'director' ), array( '990067', 'apoyo' ) ) as list( $socio, $papel ) ) {
		$enviar( array( 'asde_action' => 'evento_equipo_anadir', 'evento' => (string) $e['id'], 'pestana' => 'equipo', 'socio' => $socio, 'papel' => $papel ), 'asde_evento_' . $e['id'] );
	}
	foreach ( array( '990062', '990063', '990064', '990065', '990066' ) as $socio ) {
		InscripcionesTable::inscribir( $e['id'], $socio, array( 'secciones' => array( 'lobatos' ), 'adulto' => 0, 'estado' => 'confirmada', 'salud_revisada' => '990066' === $socio ? null : current_time( 'mysql', true ) ) );
	}
	$ok( 7 === count( V::asistentes( $e ) ) && 'equipo' === V::asistentes( $e )['990067']['rol'] && ! V::asistentes( $e )['990066']['revisada'], 'siete asistentes: cinco inscritos y dos del equipo (uno sin la ficha de salud revisada)' );

	echo "== Parte A · Qué afecta a cada persona (lo que dice la ficha) ==\n";
	$p = V::de_persona( array( 'alimentacion_alergia_c' => 'Sí', 'alimentacion_alerg_alimentos_c' => 'Gluten, Huevos' ) );
	$ok( array( 'gluten', 'huevos' ) === $p['codigos'] && '' === $p['revisar'] && $p['completa'], 'alergia a gluten y huevos' );
	$p = V::de_persona( array( 'alimentacion_intolerancia_c' => 'Sí', 'alimentacion_intol_alimentos_c' => 'Lactosa' ) );
	$ok( array( 'lacteos' ) === $p['codigos'], 'intolerancia a la lactosa → lácteos' );
	$p = V::de_persona( array( 'alimentacion_alergia_c' => 'Sí', 'alimentacion_alerg_alimentos_c' => 'pimiento' ) );
	$ok( array() === $p['codigos'] && 'pimiento' === $p['revisar'], 'un texto que no se entiende → a revisar a mano, con el texto original' );
	$p = V::de_persona( array( 'alimentacion_alergia_c' => 'Sí' ) );
	$ok( array() === $p['codigos'] && '' !== $p['revisar'], 'dice «Sí» pero no indica a qué → a revisar a mano' );
	$p = V::de_persona( array( 'alimentacion_alergia_c' => 'No', 'alimentacion_intolerancia_c' => 'No', 'alimentacion_regimen_c' => 'No' ) );
	$ok( array() === $p['codigos'] && '' === $p['revisar'] && $p['completa'], 'todo «No»: nada que mirar y ficha completa' );
	$p = V::de_persona( array() );
	$ok( ! $p['completa'], 'ficha vacía: sin rellenar' );
	$ok( 'vegana' === V::de_persona( array( 'alimentacion_regimen_c' => 'Sí', 'alimentacion_regimen_tipo_y__c' => 'Dieta vegana' ) )['dieta'] && 'vegetariana' === V::de_persona( array( 'alimentacion_regimen_c' => 'Sí', 'alimentacion_regimen_tipo_y__c' => 'Dieta vegetariana' ) )['dieta'] && '' === V::de_persona( array( 'alimentacion_regimen_c' => 'No', 'alimentacion_regimen_tipo_y__c' => 'Dieta vegana' ) )['dieta'], 'dietas vegana y vegetariana (y «No» las apaga)' );

	echo "\n== Parte B · Menú sin y con asistentes ==\n";
	$r = V::evaluar( $e );
	$ok( 'sin_menu' === $r['estado'], 'sin platos no hay nada que validar' );
	$mac = $comun( 'Macarrones con tomate' ); // Gluten y lácteos, origen animal.
	$lent = $comun( 'Lentejas estofadas' );   // Sin alérgenos, vegana.
	$alb  = $comun( 'Albóndigas en salsa de tomate' ); // Gluten, huevos.
	$p_mac  = MenuEvento::anadir( $e, $a_lob, $ini, 'comida', $mac['id'] );
	$p_lent = MenuEvento::anadir( $e, $a_lob, $ini, 'cena', $lent['id'] );
	$p_alb  = MenuEvento::anadir( $e, $a_lob, $fin, 'comida', $alb['id'] );

	echo "\n== Parte C · Los cuatro niveles ==\n";
	$r = V::evaluar( $e );
	$ok( 'ok' === $r['estado'] && V::CONFLICTO === $r['nivel'] && 7 === $r['asistentes'], 'con los macarrones (gluten y lácteos) y los alérgicos inscritos: CONFLICTO' );
	$ok( 1 === $r['platos'][ $p_mac ]['conflictos']['gluten']['n'] && 1 === $r['platos'][ $p_mac ]['conflictos']['lacteos']['n'], 'macarrones: 1 persona con gluten (Leo) y 1 con lácteos (Eva)' );
	$ok( 'Macarrones' === $r['platos'][ $p_mac ]['conflictos']['gluten']['ingredientes'][0] && 'Queso rallado' === $r['platos'][ $p_mac ]['conflictos']['lacteos']['ingredientes'][0], 'y dice qué ingrediente lo causa' );
	$ok( 1 === $r['platos'][ $p_alb ]['conflictos']['gluten']['n'], 'albóndigas: gluten (Leo)' );
	$ok( 1 === $r['platos'][ $p_alb ]['conflictos']['huevos']['n'], 'albóndigas: huevos (Leo)' );
	$ok( ! $r['platos'][ $p_lent ]['conflictos'] && ! $r['platos'][ $p_lent ]['dieta'], 'lentejas: sin conflictos ni problema de dieta' );
	$ok( 1 === $r['incompletos'] && 1 === $r['revisar'], 'una ficha incompleta (Mia, sin rellenar y sin revisar) y una a revisar a mano (Pau)' );
	$ok( array() === $r['nombres_incompletos'] && array() === $r['textos_revisar'] && array() === $r['platos'][ $p_mac ]['conflictos']['gluten']['nombres'], 'sin permiso para ver quiénes: ningún nombre ni texto de ficha en el resultado' );
	$json = (string) wp_json_encode( $r );
	$ok( ! str_contains( $json, 'Leo' ) && ! str_contains( $json, 'Eva' ) && ! str_contains( $json, 'pimiento' ) && ! str_contains( $json, 'Lactosa' ), 'el resultado en bruto no lleva nombres, textos ni diagnósticos' );
	$rn = V::evaluar( $e, true );
	$ok( array( 'Leo Valida Luz' ) === $rn['platos'][ $p_mac ]['conflictos']['gluten']['nombres'] && array( 'Eva Valida Coll' ) === $rn['platos'][ $p_mac ]['conflictos']['lacteos']['nombres'], 'con permiso, salen los nombres: Leo (gluten) y Eva (lácteos)' );
	$ok( array( 'pimiento' ) === array_values( $rn['textos_revisar'] ) && in_array( 'Mia Valida Sol', $rn['nombres_incompletos'], true ), 'y el texto a revisar (pimiento, de Pau) y quién tiene la ficha incompleta' );
	$ok( ! isset( $rn['platos'][ $p_lent ]['dieta']['vegana'] ), 'las lentejas no llevan nada de origen animal: Tom (vegano) no sale en ellas' );
	$ok( 1 === $rn['platos'][ $p_mac ]['dieta']['vegana']['n'] && array( 'Tom Valida Ruiz' ) === $rn['platos'][ $p_mac ]['dieta']['vegana']['nombres'], 'los macarrones llevan queso (origen animal): Tom, vegano, queda marcado' );

	MenuTable::quitar( $p_mac, $e['id'] );
	MenuTable::quitar( $p_alb, $e['id'] );
	AvisosTable::borrar_plato( $p_mac );
	$r = V::evaluar( $e );
	$ok( V::RIESGO === $r['nivel'] && V::RIESGO === V::nivel_con_confirmaciones( $r, array() ), 'solo las lentejas: sin conflicto, pero queda el texto sin entender de Pau → POSIBLE RIESGO' );

	echo "\n== Parte D · Confirmar un aviso ==\n";
	$p_mac = MenuEvento::anadir( $e, $a_lob, $ini, 'comida', $mac['id'] );
	$r     = V::evaluar( $e );
	$ok( V::CONFLICTO === V::nivel_con_confirmaciones( $r, AvisosTable::de_evento( $e['id'] ) ), 'sin confirmar, sigue en conflicto' );
	$ok( is_wp_error( V::confirmar( $e, $a_lob, $p_mac, 'gluten', 'inventada' ) ) && is_wp_error( V::confirmar( $e, $a_lob, $p_mac, 'sulfitos', 'racion' ) ) && is_wp_error( V::confirmar( $e, $a_lob, 999999, 'gluten', 'racion' ) ), 'una alternativa inventada, un alérgeno que el plato no lleva o un plato que no existe se rechazan' );
	$ok( true === V::confirmar( $e, $a_lob, $p_mac, 'gluten', 'racion' ) && true === V::confirmar( $e, $a_lob, $p_mac, 'lacteos', 'cambio' ), 'se confirman el gluten y los lácteos de los macarrones' );
	$conf = AvisosTable::de_evento( $e['id'] );
	$ok( 2 === count( $conf ) && 'racion' === $conf[ $p_mac . '|gluten' ]['alternativa'] && $u_lob === $conf[ $p_mac . '|gluten' ]['por'], 'queda quién y qué alternativa' );
	$ok( V::RIESGO === V::nivel_con_confirmaciones( $r, $conf ), 'confirmados los conflictos, el menú sigue en riesgo solo por lo que no se ha entendido' );
	$columnas = array_column( (array) $wpdb->get_results( 'SHOW COLUMNS FROM ' . AvisosTable::table(), ARRAY_A ), 'Field' ); // phpcs:ignore WordPress.DB
	$ok( array( 'id', 'grupo', 'evento_id', 'plato_id', 'alergeno', 'alternativa', 'por', 'creado' ) === $columnas, 'la tabla de confirmaciones no tiene columnas para nombres, diagnósticos ni nº de afectados' );
	$a_otro = $como( $u_otro );
	$ok( is_wp_error( V::confirmar( $e, $a_otro, $p_mac, 'gluten', 'racion' ) ), 'un scouter de otra sección que no está en el equipo no confirma' );
	$a_lob = $como( $u_lob );
	$enviar( array( 'asde_action' => 'menu_desconfirmar', 'evento' => (string) $e['id'], 'pestana' => 'menu', 'plato' => (string) $p_mac, 'alergeno' => 'gluten' ), 'asde_evento_' . $e['id'] );
	$ok( 1 === count( AvisosTable::de_evento( $e['id'] ) ), 'se puede quitar una confirmación' );
	MenuTable::quitar( $p_mac, $e['id'] );
	AvisosTable::borrar_plato( $p_mac );
	$ok( array() === AvisosTable::de_evento( $e['id'] ), 'al quitar el plato, sus confirmaciones se van' );

	echo "\n== Parte E · Datos incompletos y sin conflictos ==\n";
	// Un evento aparte con solo Ana (completa) y Cris (completa): sin conflictos.
	$e2 = $e;
	$enviar( array( 'asde_action' => 'evento_crear', 'tipo' => 'acampada', 'nombre' => 'Acampada limpia', 'ambito' => 'secciones', 'secciones' => array( 'lobatos' ), 'inicio_fecha' => $ini, 'inicio_hora' => '18:00', 'fin_fecha' => $fin, 'fin_hora' => '16:00', 'lugar' => 'Albergue', 'previstas_lobatos' => '5' ), 'asde_evento_nuevo' );
	$e2 = EventosTable::obtener( $max_id( EventosTable::table() ) );
	$enviar( array( 'asde_action' => 'evento_equipo_anadir', 'evento' => (string) $e2['id'], 'pestana' => 'equipo', 'socio' => '990061', 'papel' => 'director' ), 'asde_evento_' . $e2['id'] );
	MenuEvento::anadir( $e2, $a_lob, $ini, 'comida', $mac['id'] );
	$r2 = V::evaluar( $e2 );
	$ok( V::SIN === $r2['nivel'] && 0 === $r2['incompletos'] && 1 === $r2['asistentes'], 'solo una persona sin alergias y con la ficha completa: SIN CONFLICTOS' );
	InscripcionesTable::inscribir( $e2['id'], '990066', array( 'secciones' => array( 'lobatos' ), 'adulto' => 0, 'estado' => 'confirmada', 'salud_revisada' => null ) );
	$r2 = V::evaluar( $e2 );
	$ok( V::INCOMPLETO === $r2['nivel'] && 1 === $r2['incompletos'], 'entra alguien con la ficha sin rellenar ni revisar: DATOS INCOMPLETOS (nunca «sin conflictos»)' );

	echo "\n== Parte F · Si el CRM no responde ==\n";
	$crm->desactivar();
	add_filter( 'pre_http_request', static fn( $pre ) => new WP_Error( 'prueba', 'CRM caído' ), 5 );
	$r = V::evaluar( $e );
	$ok( 'crm' === $r['estado'] && V::INCOMPLETO === V::nivel_con_confirmaciones( $r, array() ), 'sin CRM: no se dice que todo esté bien, se avisa' );
	remove_all_filters( 'pre_http_request', 5 );
	$crm->activar();

	echo "\n== Parte G · La hoja de cocina ==\n";
	$p_mac = MenuEvento::anadir( $e, $a_lob, $ini, 'comida', $mac['id'] );
	$hoja  = HojaCocina::componer( $e );
	$mac_h = array_values( array_filter( $hoja['platos'], static fn( $x ) => 'Macarrones con tomate' === $x['plato'] ) )[0] ?? array( 'personas' => array() );
	$ok( 'ok' === $hoja['estado'] && isset( $mac_h['personas']['Leo Valida Luz'], $mac_h['personas']['Eva Valida Coll'] ) && array( 'Gluten' ) === $mac_h['personas']['Leo Valida Luz']['alergenos'] && array( 'Leche y lácteos' ) === $mac_h['personas']['Eva Valida Coll']['alergenos'], 'la hoja dice el nombre y el alérgeno: Leo → Gluten, Eva → Leche y lácteos' );
	$ok( ! isset( $mac_h['personas']['Pau Valida Mas'] ) && ! isset( $mac_h['personas']['Ana Valida Gil'] ), 'solo salen los que cruzan con el menú (ni Ana, sin alergias, ni Pau, sin entender)' );
	$lent_h = array_values( array_filter( $hoja['platos'], static fn( $x ) => 'Lentejas estofadas' === $x['plato'] ) )[0];
	$ok( ! $lent_h['personas'] && isset( $mac_h['personas']['Tom Valida Ruiz']['dieta'] ), 'la dieta se anota si el plato la incumple (macarrones sí, lentejas no)' );
	$ok( array( 'Pau Valida Mas' => 'pimiento' ) === $hoja['revisar'], 'lo que no se entiende sale aparte, para mirarlo a mano' );
	$crudo = (string) wp_json_encode( $hoja );
	$ok( ! str_contains( $crudo, 'Alergia' ) && ! str_contains( $crudo, 'diagn' ), 'sin diagnósticos ni el texto libre de la ficha (salvo lo que no se entendió)' );
	$ok( null === HojaCocina::estado( $e, $hoja ), 'todavía no se ha entregado ninguna hoja' );

	$h = $pintar( array( 'evento' => (string) $e['id'], 'pestana' => 'menu', 'hoja' => '1' ) );
	$ok( str_contains( $h, 'Hoja de cocina' ) && str_contains( $h, 'Leo Valida Luz' ) && str_contains( $h, 'datos de salud' ) && str_contains( $h, 'Válida a fecha' ), 'la hoja impresa lleva nombres, la fecha y el aviso de datos de salud' );
	$est = HojaCocina::estado( $e, HojaCocina::componer( $e ) );
	$ok( $est && ! $est['desfasada'] && 'impresa' === $est['como'], 'tras imprimirla consta, y no está desfasada' );
	$opcion = (string) wp_json_encode( get_option( 'asde_ap_hoja_cocina_' . $e['id'] ) );
	$ok( ! str_contains( $opcion, 'Leo' ) && ! str_contains( $opcion, 'Gluten' ), 'de la hoja entregada solo se guarda una huella y cuándo: ni nombres ni alérgenos' );
	$wpdb->update( $wpdb->prefix . 'asde_evento_inscripciones', array( 'estado' => 'baja' ), array( 'evento_id' => $e['id'], 'socio' => '990062' ) ); // phpcs:ignore WordPress.DB
	$est = HojaCocina::estado( $e, HojaCocina::componer( $e ) );
	$ok( $est && $est['desfasada'], 'si luego Leo se da de baja, la hoja entregada queda DESFASADA' );
	$wpdb->update( $wpdb->prefix . 'asde_evento_inscripciones', array( 'estado' => 'confirmada' ), array( 'evento_id' => $e['id'], 'socio' => '990062' ) ); // phpcs:ignore WordPress.DB

	$a_otro = $como( $u_otro );
	$ok( ! HojaCocina::puede_generar( $e, $a_otro ) && is_wp_error( HojaCocina::enviar( $e, $a_otro ) ), 'quien no gestiona el evento no genera ni envía la hoja' );
	$a_lob = $como( $u_lob );
	$antes_correos = $correos;
	$r = HojaCocina::enviar( $e, $a_lob );
	$ok( is_array( $r ) && 1 === $r['enviados'] && 0 === $r['sin_cuenta'], 'se envía al equipo de apoyo con cuenta (1 persona)' );
	$ok( $correos === $antes_correos + 1, 'sale por el servidor de correo' );
	$filas = array_values( array_filter( RegistroEmails::listado(), static fn( $f ) => (int) $f['id'] > $email_antes && RegistroEmails::EVENTOS === $f['tipo'] && str_contains( (string) $f['asunto'], 'Hoja de cocina' ) ) );
	$ok( 1 === count( $filas ) && $u_cocina === (int) $filas[0]['user_id'], 'el registro de emails anota el envío' );
	$reg = (string) wp_json_encode( $filas[0] );
	$ok( ! str_contains( $reg, 'Leo' ) && ! str_contains( $reg, 'Gluten' ) && ! str_contains( $reg, 'Eva' ), 'y no guarda el contenido (ni nombres ni alérgenos)' );
	$ok( ! str_contains( RegistroEmails::vista_previa( $filas[0] ), 'Leo' ), 'la vista previa del registro tampoco lo vuelve a componer' );
	$est = HojaCocina::estado( $e, HojaCocina::componer( $e ) );
	$ok( $est && 'email' === $est['como'] && ! $est['desfasada'], 'tras enviarla consta como enviada por email' );

	echo "\n== Parte H · Pantalla del menú y auditoría ==\n";
	$a_coord = $como( $u_coord );
	$h = $pintar( array( 'evento' => (string) $e['id'], 'pestana' => 'menu' ) );
	$ok( str_contains( $h, 'Alérgenos del menú' ) && str_contains( $h, 'Conflicto' ) && str_contains( $h, 'Macarrones con tomate' ), 'la pantalla enseña el nivel y los avisos' );
	$a_lob = $como( $u_lob );
	$h = $pintar( array( 'evento' => (string) $e['id'], 'pestana' => 'menu' ) );
	$ok( str_contains( $h, 'Alérgenos del menú' ) && str_contains( $h, 'name="alternativa"' ) && str_contains( $h, 'menu_confirmar' ) && str_contains( $h, 'Hoja de cocina' ), 'quien gestiona el evento ve los avisos, el botón de confirmar y la hoja de cocina' );
	$ok( user_can( $u_lob, 'asde_view_health' ) ? str_contains( $h, 'Leo Valida Luz' ) : ! str_contains( $h, 'Leo Valida Luz' ), 'los nombres solo salen si quien consulta ya ve la salud' );
	$ok( ! str_contains( $h, 'pimiento' ) || user_can( $u_lob, 'asde_view_health' ), 'el texto de la ficha solo sale con permiso de salud' );
	$acciones = (array) $wpdb->get_col( $wpdb->prepare( 'SELECT accion FROM ' . Logger::table() . ' WHERE id > %d', $log_antes ) ); // phpcs:ignore WordPress.DB
	$ok( in_array( 'menu_validado', $acciones, true ) && in_array( 'menu_aviso_confirmado', $acciones, true ) && in_array( 'menu_hoja_generada', $acciones, true ) && in_array( 'menu_hoja_enviada', $acciones, true ), 'queda auditoría de validar, confirmar, generar y enviar la hoja' );
	$det = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GROUP_CONCAT(detalle) FROM ' . Logger::table() . " WHERE id > %d AND accion LIKE 'menu_%%'", $log_antes ) ); // phpcs:ignore WordPress.DB
	$ok( ! str_contains( $det, 'Leo' ) && ! str_contains( $det, 'Eva' ) && ! str_contains( $det, 'pimiento' ), 'y la auditoría no lleva nombres ni salud' );

	echo "
== Parte I · El calendario de tareas ==
";
	$ok( false === \AsdeBalears\AreaPrivada\Eventos\Tareas::comprobar( $e2, 'menu' ), 'con solo una comida en dos días, la tarea «Elaborar menú» no se da por hecha' );
	foreach ( array( $ini, $fin ) as $d ) {
		foreach ( array( 'comida', 'cena' ) as $c ) {
			MenuEvento::anadir( $e2, $a_lob, $d, $c, $lent['id'] );
		}
	}
	$ok( true === \AsdeBalears\AreaPrivada\Eventos\Tareas::comprobar( $e2, 'menu' ), 'con comida y cena todos los días, se da por hecha sola' );
	$por_titulo = array_column( \AsdeBalears\AreaPrivada\Eventos\Plantillas::incorporada()['tareas'], null, 'titulo' );
	$ok( 'menu' === $por_titulo['Elaborar menú']['auto'] && 'menu' === $por_titulo['Elaborar menú']['enlace'], '«Elaborar menú» es automática y lleva a la pestaña Menú' );
	$ok( '' === $por_titulo['Revisar alergias médicas de asistentes, medicinas y menú']['auto'] && 'menu' === $por_titulo['Revisar alergias médicas de asistentes, medicinas y menú']['enlace'], '«Revisar alergias…» la hace una persona (con el menú delante) y lleva a la pestaña Menú' );
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
		HojaCocina::olvidar( (int) $evid );
	}
	$lista = implode( ',', array_map( 'intval', $ids ) ) ?: '0';
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
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Logger::table() . " WHERE id > %d AND ( user_id IN ($lista) OR objeto IN ( 'evento', 'menus' ) )", $log_antes ) ); // phpcs:ignore WordPress.DB
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

	$quedan = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users} WHERE user_login LIKE 'prueba-va-%'" ); // phpcs:ignore WordPress.DB
	$ok( 0 === $quedan && 0 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . EventosTable::table() . ' WHERE id > ' . (int) $ev_antes ) && 0 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . AvisosTable::table() . ' WHERE id > ' . (int) $avi_antes ), 'limpieza: sin usuarios, eventos ni confirmaciones de la prueba' ); // phpcs:ignore WordPress.DB
}

echo $fails ? "\n$fails FALLOS\n" : "\nTODO OK (nada tocado en el CRM real; el sitio queda como estaba)\n";
