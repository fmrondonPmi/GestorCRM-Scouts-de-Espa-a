<?php
/**
 * Pruebas de la v0.64 (entorno LOCAL): el asistente para crear eventos (doc 66).
 * Ejecutar desde app/public del site Local:
 *
 *   wp eval-file <ruta completa>/wp-content/plugins/asde-area-privada/tests/eventos-asistente.php
 *
 * Usa el CRM simulado de tests/lib (fichas inventadas: ninguna petición llega al CRM real). Al terminar
 * se borra lo creado (usuarios, eventos, equipo, registros) y se restaura el módulo y la configuración.
 */
defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/lib/CrmSimulado.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Auth\Ambito;
use AsdeBalears\AreaPrivada\Crm\Escritura;
use AsdeBalears\AreaPrivada\Domain\Grupo;
use AsdeBalears\AreaPrivada\Domain\Grupos;
use AsdeBalears\AreaPrivada\Domain\Modulos;
use AsdeBalears\AreaPrivada\Eventos\Config;
use AsdeBalears\AreaPrivada\Eventos\EventosTable;
use AsdeBalears\AreaPrivada\Eventos\Formulario;
use AsdeBalears\AreaPrivada\Eventos\InscripcionesTable;
use AsdeBalears\AreaPrivada\Eventos\Normativa;
use AsdeBalears\AreaPrivada\Eventos\Servicio;
use AsdeBalears\AreaPrivada\Frontend\Notices;
use AsdeBalears\AreaPrivada\Frontend\Views\EventosView;
use AsdeBalears\AreaPrivada\Frontend\Views\View;
use AsdeBalears\AreaPrivada\Install\Roles;
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
foreach ( get_users( array( 'search' => 'prueba-asi-*', 'search_columns' => array( 'user_login' ) ) ) as $u ) {
	exit( 'Ya existe el usuario ' . $u->user_login . " (¿una ejecución anterior interrumpida?). Bórralo antes de repetir la prueba.\n" );
}
wp_set_current_user( 0 );

// --- Lo que se restaura al final ---------------------------------------------
$max_id        = static fn( string $t ) => (int) $wpdb->get_var( "SELECT COALESCE(MAX(id), 0) FROM $t" ); // phpcs:ignore WordPress.DB
$log_antes     = $max_id( Logger::table() );
$ev_antes      = $max_id( EventosTable::table() );
$ins_antes     = $max_id( InscripcionesTable::table() );
$opcion_cfg    = 'asde_ap_eventos_' . $grupo['numero'];
$cfg_antes     = get_option( $opcion_cfg, null );
$modulos_antes = get_option( 'asde_ap_modulos', null );
$cache_externa = wp_using_ext_object_cache( true );
add_filter( 'pre_wp_mail', '__return_true' );
$cortar_crm = static fn( $pre, $args, $url ) => false === $pre && str_contains( (string) $url, '/rest.php' ) ? new WP_Error( 'prueba', 'CRM real cortado durante la prueba' ) : $pre;
add_filter( 'pre_http_request', $cortar_crm, 2, 3 );

$dia = static fn( string $mod ) => wp_date( 'Y-m-d', strtotime( $mod ) );
$ini = $dia( '+60 days' );
$fin = $dia( '+62 days' );
$P   = array( 'Asde_Prueba_Crm', 'persona' );
$crm = new Asde_Prueba_Crm(
	array(
		$P( 33, 'Ana', 'Asistente Gil', '1990-01-01', 'Scouters' ),
		$P( 34, 'Tom', 'Asistente Luz', '1985-05-05', 'Scouters' ),
		$P( 35, 'Eva', 'Asistente Coll', '1980-08-08', 'Scouters' ),
		$P( 36, 'Pol', 'Asistente Mas', '1992-03-03', 'Scouters' ),
		$P( 31, 'Leo', 'Asistente Ruiz', '2016-05-10', 'Lobatos' ),
	),
	$grupo['crm_id']
);
Grupo::forzar( $grupo );
Modulos::guardar( (string) $grupo['numero'], array_keys( Modulos::lista() ) );
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
$u_lob   = $crear( 'prueba-asi-lobatos', array( Roles::SCOUTER ), array( Usuarios::META_SOCIO => '990033', Usuarios::META_SECCION => 'lobatos' ) );
$u_sco   = $crear( 'prueba-asi-scouts', array( Roles::SCOUTER ), array( Usuarios::META_SOCIO => '990034', Usuarios::META_SECCION => 'scouts' ) );
$u_otro  = $crear( 'prueba-asi-otro', array( Roles::SCOUTER ), array( Usuarios::META_SOCIO => '990036', Usuarios::META_SECCION => 'escultas' ) );
$u_coord = $crear( 'prueba-asi-coord', array( Roles::SCOUTER, Roles::COORDINACION ), array( Usuarios::META_SOCIO => '990035', Usuarios::META_SECCION => 'rovers' ) );

$como = static function ( int $uid ): Ambito {
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
$enviar = static function ( array $campos ): string {
	$_POST    = $campos + array( '_wpnonce' => wp_create_nonce( 'asde_evento_nuevo' ), 'asde_action' => 'evento_crear' );
	$_REQUEST = $_POST;
	try {
		( new EventosView() )->handle_post();
	} catch ( RuntimeException $e ) {
		// Redirige al terminar.
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
$campos = static fn( array $extra = array() ): array => $extra + array(
	'tipo'              => 'acampada',
	'nombre'            => 'Acampada del asistente',
	'ambito'            => 'secciones',
	'secciones'         => array( 'lobatos' ),
	'inicio_fecha'      => $ini,
	'inicio_hora'       => '18:00',
	'fin_fecha'         => $fin,
	'fin_hora'          => '13:00',
	'lugar'             => 'Casa de colonias de prueba',
	'descripcion'       => 'Una acampada para probar',
	'previstas_lobatos' => '24',
	'con_coste'         => '1',
);
$ultimo = static fn() => EventosTable::obtener( $max_id( EventosTable::table() ) );

try {
	Config::guardar( Config::defaults(), $grupo['numero'] );

	echo "== Parte A · La pantalla «Nuevo evento» ==\n";
	$como( $u_lob );
	$h = $pintar( array( 'nuevo' => '1' ) );
	$ok( str_contains( $h, 'data-asde-asistente' ) && 6 === substr_count( $h, ' data-paso=' ) && str_contains( $h, 'name="asde_action" value="evento_crear"' ), 'el asistente tiene los 6 pasos y envía a la misma acción de crear' );
	$ok( 6 === substr_count( $h, 'data-asde-no-plegable' ), 'ninguno de los pasos es un cuadro plegable' );
	$ok( str_contains( $h, 'value="lobatos"' ) && ! str_contains( $h, 'value="scouts"' ) && ! str_contains( $h, 'name="ambito" value="grupo"' ), 'un scouter solo ve sus secciones y no elige el alcance' );
	$ok( str_contains( $h, 'name="director"' ) && str_contains( $h, 'Eva Asistente Coll' ) && ! str_contains( $h, 'Leo Asistente Ruiz' ), 'el equipo se elige entre los adultos del grupo (no entre los menores)' );
	$ok( preg_match( '/value="acampada"[^>]*data-coste="1"/', $h ) && preg_match( '/value="salida"[^>]*data-coste="0"/', $h ), 'cada tipo lleva si nace con coste (el JS marca la casilla al elegirlo)' );
	$ok( ! str_contains( $h, 'asde-asistente__copiar' ), 'sin eventos anteriores no se ofrece copiar' );
	$ok( str_contains( $h, 'data-inicial="1"' ) && str_contains( $h, '<button type="button" data-ir="1"' ), 'empieza en el paso 1 y los pasos de arriba son botones (se manejan con teclado)' );
	$como( $u_coord );
	$h = $pintar( array( 'nuevo' => '1' ) );
	$ok( str_contains( $h, 'name="ambito" value="grupo"' ) && str_contains( $h, 'name="ambito" value="externo"' ), 'Coordinación elige también grupo y externo' );

	echo "\n== Parte B · Crear con el asistente ==\n";
	$como( $u_lob );
	$avisos = $enviar( $campos( array( 'director' => '990035', 'monitor' => array( '990034', '990035' ) ) ) );
	$e      = $ultimo();
	$ok( $e && 'Acampada del asistente' === $e['nombre'] && EventosTable::BORRADOR === $e['estado'] && array( 'lobatos' ) === $e['secciones'] && 24 === $e['plazas']['lobatos']['previstas'], 'se crea el borrador con los datos del asistente' );
	$equipo = $e ? InscripcionesTable::equipo( $e['id'] ) : array();
	$papeles = array_column( $equipo, 'papel', 'socio' );
	$ok( 2 === count( $equipo ) && 'director' === ( $papeles['990035'] ?? '' ) && 'monitor' === ( $papeles['990034'] ?? '' ), 'el equipo elegido entra con el evento: Eva director/a y Tom monitor (a Eva no se la repite como monitor)' );
	$ok( str_contains( $avisos, 'created as a draft' ) || str_contains( $avisos, 'creado como borrador' ), 'el aviso dice que hay un borrador y lo que falta' );
	$ok( ! str_contains( $avisos, 'asde-notice--warning' ), 'quien es director/a y está marcado también como monitor no da ningún aviso' );
	$log = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Logger::table() . " WHERE id > %d AND accion IN ( 'evento_creado', 'evento_equipo_anadido' )", $log_antes ) ); // phpcs:ignore WordPress.DB
	$ok( $log >= 3, 'la auditoría anota el evento y cada miembro del equipo' );

	$antes   = $max_id( EventosTable::table() );
	$enviar( $campos( array( 'nombre' => 'Sin equipo' ) ) );
	$ok( $max_id( EventosTable::table() ) === $antes + 1 && 0 === count( InscripcionesTable::equipo( $max_id( EventosTable::table() ) ) ), 'el equipo es opcional: sin director ni monitores se crea igual, sin equipo' );

	$antes = $max_id( EventosTable::table() );
	$enviar( $campos( array( 'nombre' => 'Con un socio que no existe', 'director' => '999999' ) ) );
	$ok( $max_id( EventosTable::table() ) === $antes + 1 && 0 === count( InscripcionesTable::equipo( $max_id( EventosTable::table() ) ) ), 'una persona que no es del grupo no entra al equipo, pero el evento se crea' );

	// El equipo entra en lote: los candidatos se leen y se ordenan una vez, no una por persona.
	$enviar( $campos( array( 'nombre' => 'Equipo en lote' ) ) );
	$lote    = $ultimo();
	$ambito  = $como( $u_lob );
	$antes   = $crm->llamadas;
	Servicio::candidatos();
	$una     = $crm->llamadas - $antes;
	$antes   = $crm->llamadas;
	$fallos  = Servicio::anadir_equipo_varios( $lote, $ambito, array( 'director' => array( '990035', '990035' ), 'monitor' => array( '990035', '990034', '999999' ) ) );
	$lotes   = $crm->llamadas - $antes;
	$papeles = array_column( InscripcionesTable::equipo( $lote['id'] ), 'papel', 'socio' );
	$ok( array( '990035' => 'director', '990034' => 'monitor' ) === $papeles && 1 === count( $fallos ), 'en lote: Eva queda solo de directora (no se repite como monitora), Tom de monitor y quien no es del grupo da un solo error' );
	$ok( $una > 0 && $lotes === $una, "añadir 3 personas al equipo lee a los candidatos una sola vez ($lotes lectura frente a $una de una sola consulta)" );

	echo "\n== Parte C · Errores: no se crea nada y se conserva lo escrito ==\n";
	$antes   = $max_id( EventosTable::table() );
	$avisos3 = $enviar( $campos( array( 'ambito' => 'grupo', 'nombre' => 'Un evento de grupo' ) ) );
	$ok( $max_id( EventosTable::table() ) === $antes && ( str_contains( $avisos3, 'Coordination' ) || str_contains( $avisos3, 'Coordinación' ) ), 'un scouter no crea eventos de grupo' );
	$enviar( $campos( array( 'secciones' => array(), 'nombre' => 'Sin sección escrita', 'director' => '990035', 'monitor' => array( '990034' ) ) ) );
	$h = $pintar( array( 'nuevo' => '1' ) );
	$ok( $max_id( EventosTable::table() ) === $antes && str_contains( $h, 'value="Sin sección escrita"' ) && str_contains( $h, 'value="Casa de colonias de prueba"' ), 'sin sección no se crea, y al volver a enseñar el asistente están los datos escritos' );
	$ok( preg_match( '/<option value="990035"\s+selected/', $h ) && preg_match( '/value="990034"\s+checked/', $h ) && ! preg_match( '/value="990033"\s+checked/', $h ), 'tras el error siguen elegidos el director/a y los monitores' );
	$ok( str_contains( $h, 'data-inicial="6"' ), 'tras el error el asistente se abre en el último paso, con el aviso encima' );

	echo "\n== Parte D · Copiar un evento anterior ==\n";
	( new ReflectionProperty( EventosView::class, 'valores' ) )->setValue( null, null ); // En la web cada petición empieza limpia.
	$como( $u_lob );
	$h    = $pintar( array( 'nuevo' => '1' ) );
	$ok( str_contains( $h, 'asde-asistente__copiar' ) && str_contains( $h, 'copiar=' . $e['id'] ), 'con eventos anteriores se ofrece copiar uno' );
	$h    = $pintar( array( 'nuevo' => '1', 'copiar' => (string) $e['id'] ) );
	$ini2 = wp_date( 'Y-m-d', strtotime( $ini . ' +1 year' ) );
	$ok( str_contains( $h, 'value="Acampada del asistente"' ) && str_contains( $h, 'Una acampada para probar' ), 'la copia trae el nombre y los textos' );
	$ok( str_contains( $h, 'name="previstas_lobatos" value="24"' ), 'la copia trae los participantes previstos' );
	$ok( str_contains( $h, 'name="inicio_fecha" value="' . $ini2 . '"' ), 'la copia lleva las fechas un año después' );
	$ok( str_contains( $h, 'name="director"' ) && ! preg_match( '/<option value="\d+"\s+selected/', $h ) && ! preg_match( '/name="monitor\[\]"[^>]*checked/', $h ) && 1 === preg_match( '/data-inicial="1"/', $h ), 'el equipo no se copia: nadie queda elegido y se empieza en el paso 1' );
	$como( $u_otro );
	$h = $pintar( array( 'nuevo' => '1', 'copiar' => (string) $e['id'] ) );
	$ok( ! str_contains( $h, 'value="Acampada del asistente"' ), 'no se copia un evento que quien crea no puede ver (alguien de otra sección y fuera del equipo)' );

	echo "\n== Parte E · Valores del formulario y JavaScript ==\n";
	$cfg = Config::de();
	$d0  = Formulario::valores( null, null, $cfg, array( 'lobatos' ), 'campamento' );
	$ok( 'campamento' === $d0['tipo'] && ! empty( $cfg['tipos']['campamento']['coste'] ) === $d0['con_coste'] && array( 'lobatos' ) === $d0['secciones'] && '' === $d0['inicio_fecha'], 'en blanco: el tipo elegido, su coste por defecto y la única sección ya marcada' );
	$d1 = Formulario::valores( $e, null, $cfg, array( 'lobatos' ) );
	$d2 = Formulario::valores( $e, null, $cfg, array( 'lobatos' ), '', true );
	$ok( $ini === $d1['inicio_fecha'] && $ini2 === $d2['inicio_fecha'] && $d1['nombre'] === $d2['nombre'], 'editar conserva las fechas; copiar las pasa un año después' );
	$d3 = Formulario::valores( $e, array( 'nombre' => 'Escrito', 'agua' => '1', 'secciones' => array( 'scouts' ) ), $cfg, array( 'lobatos' ) );
	$ok( 'Escrito' === $d3['nombre'] && true === $d3['agua'] && false === $d3['con_coste'] && array( 'scouts' ) === $d3['secciones'], 'lo escrito gana al evento; las casillas que no llegan valen «no»' );

	// El JS usa las cifras de Normativa (data-*): su fórmula, con esas cifras, coincide con la del servidor.
	$h = $pintar( array( 'nuevo' => '1' ) );
	preg_match( '/data-por-monitor="(\d+)" data-resto-monitor="(\d+)" data-dependencia12="(\d+)"/', $h, $c );
	$ok( array( Normativa::PARTICIPANTES_MONITOR, Normativa::RESTO_MONITOR, Normativa::DEPENDENCIA12_POR_APOYO ) === array_map( 'intval', array_slice( $c, 1 ) ), 'las cifras de la regla de monitores salen de Normativa y llegan al JS' );
	$igual = true;
	for ( $p = 0; $p <= 300; $p++ ) {
		for ( $d = 0; $d <= 9; $d++ ) {
			$base  = $p > 0 ? max( 1, intdiv( $p, (int) $c[1] ) + ( $p % (int) $c[1] >= (int) $c[2] ? 1 : 0 ) ) : 0;
			$igual = $igual && $base + (int) ceil( $d / (int) $c[3] ) + 2 === Normativa::monitores_necesarios( $p, $d, 2 )['total'];
		}
	}
	$ok( $igual, 'la fórmula del JS con las cifras de data-* da lo mismo que Normativa::monitores_necesarios (0 a 300 participantes, 0 a 9 con dependencia)' );
	$como( $u_lob );
	$h = $pintar( array( 'evento' => (string) $e['id'], 'editar' => '1' ) );
	$ok( str_contains( $h, 'value="evento_guardar"' ) && str_contains( $h, 'name="evento" value="' . $e['id'] . '"' ) && ! str_contains( $h, 'evento_crear' ) && ! str_contains( $h, 'asde_evento_nuevo' ), 'el formulario de editar solo edita: ya no lleva la rama de crear' );

	$js  = (string) file_get_contents( ASDE_AP_DIR . 'assets/js/asistente.js' );
	$tpl = (string) file_get_contents( ASDE_AP_DIR . 'templates/eventos/asistente.php' );
	foreach ( array( 'coste', 'comunicacion', 'internacional', 'agua', 'riesgo', 'dependencia' ) as $a ) {
		$ok( str_contains( $tpl, 'data-aviso="' . $a . '"' ) && str_contains( $js, $a . ':' ), "el aviso «$a» que enciende el JS existe en la plantilla (traducido en el servidor)" );
	}
	foreach ( array( 'evento', 'secciones', 'fechas', 'lugar', 'participantes', 'coste' ) as $r ) {
		$ok( str_contains( $tpl, 'data-r="' . $r . '"' ), "el resumen tiene su casilla «$r»" );
	}
	$ok( ! preg_match( '/\/ 3|\/ 2\b|>= 5/', $js ), 'el JS no repite cifras de la regla (monitores, dependencia): las lee de data-*' );
	$ok( str_contains( $js, 'c.disabled = ! respuesta( r )' ) && str_contains( $js, 'aria-current' ) && str_contains( $js, 'if ( desplazar )' ), 'un «No» desactiva sus datos (no se envían), el paso actual se marca para lectores de pantalla y la carga no desplaza la página' );
	$ok( ! preg_match( '/<script|onclick=|<style/', $tpl ) && ! str_contains( $js, 'innerHTML' ), 'sin script, onclick ni style en la plantilla, ni innerHTML en el JS' );
	$ok( str_contains( (string) file_get_contents( ASDE_AP_DIR . 'src/Frontend/Router.php' ), 'asistente.js' ), 'el JS se carga en «Nuevo evento»' );
} finally {
	// ---------------------------------------------------------------------------
	// Limpieza: todo lo creado fuera; lo tocado, como estaba.
	remove_filter( 'wp_redirect', $redirigir, 1 );
	remove_filter( 'send_auth_cookies', '__return_false' );
	remove_filter( 'pre_wp_mail', '__return_true' );
	$_POST = array();
	$_GET  = array();
	View::en_modal( false );
	Escritura::usar_para_pruebas( null );
	$crm->desactivar();
	remove_filter( 'pre_http_request', $cortar_crm, 2 );
	wp_using_ext_object_cache( $cache_externa );
	wp_set_current_user( 0 );

	$lista = implode( ',', array_map( 'intval', $ids ) ) ?: '0';
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . EventosTable::table() . ' WHERE id > %d', $ev_antes ) ); // phpcs:ignore WordPress.DB
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . InscripcionesTable::table() . ' WHERE id > %d', $ins_antes ) ); // phpcs:ignore WordPress.DB
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Logger::table() . " WHERE id > %d AND ( user_id IN ($lista) OR objeto = 'evento' )", $log_antes ) ); // phpcs:ignore WordPress.DB
	foreach ( $ids as $uid ) {
		Ambito::olvidar( (int) $uid );
		wp_delete_user( (int) $uid );
	}
	null === $cfg_antes ? delete_option( $opcion_cfg ) : update_option( $opcion_cfg, $cfg_antes, false );
	Servicio::olvidar();
	Grupo::forzar( null );
	null === $modulos_antes ? delete_option( 'asde_ap_modulos' ) : update_option( 'asde_ap_modulos', $modulos_antes, false );
	Modulos::olvidar();

	$quedan = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users} WHERE user_login LIKE 'prueba-asi-%'" ); // phpcs:ignore WordPress.DB
	$ok( 0 === $quedan && 0 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . EventosTable::table() . ' WHERE id > ' . (int) $ev_antes ), 'limpieza: sin usuarios ni eventos de la prueba; módulos y configuración como estaban' ); // phpcs:ignore WordPress.DB
}

echo $fails ? "\n$fails FALLOS\n" : "\nTODO OK (nada tocado en el CRM real; el sitio queda como estaba)\n";
