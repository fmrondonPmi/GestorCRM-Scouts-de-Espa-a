<?php
/**
 * Pruebas de la lista de espera (v0.32, doc 39; entorno LOCAL). Ejecutar desde app/public:
 *
 *   wp eval-file wp-content/plugins/asde-area-privada/tests/lista-espera.php
 *
 * CRM simulado (tests/lib), ningún email sale (se guardan en memoria) y las cachés solo en
 * la memoria de este proceso. Al terminar se borra lo creado (inscripciones, usuarios,
 * registros) y los módulos, las altas en el CRM y el enlace del grupo quedan como estaban.
 */
defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/lib/CrmSimulado.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Auth\Ambito;
use AsdeBalears\AreaPrivada\Crm\Altas;
use AsdeBalears\AreaPrivada\Crm\Operaciones;
use AsdeBalears\AreaPrivada\Crm\Permisos;
use AsdeBalears\AreaPrivada\Domain\Grupo;
use AsdeBalears\AreaPrivada\Domain\Grupos;
use AsdeBalears\AreaPrivada\Domain\Modulos;
use AsdeBalears\AreaPrivada\Domain\Secciones;
use AsdeBalears\AreaPrivada\Frontend\Menu;
use AsdeBalears\AreaPrivada\Frontend\Notices;
use AsdeBalears\AreaPrivada\Frontend\Views\InscripcionEsperaView;
use AsdeBalears\AreaPrivada\Frontend\Views\ListaEsperaView;
use AsdeBalears\AreaPrivada\Frontend\Views\PersonasView;
use AsdeBalears\AreaPrivada\Frontend\Views\View;
use AsdeBalears\AreaPrivada\Install\Roles;
use AsdeBalears\AreaPrivada\ListaEspera\Enlaces;
use AsdeBalears\AreaPrivada\ListaEspera\Formulario;
use AsdeBalears\AreaPrivada\ListaEspera\Inscripciones;
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
foreach ( get_users( array( 'search' => 'prueba-espera-*', 'search_columns' => array( 'user_login' ) ) ) as $u ) {
	exit( 'Ya existe el usuario ' . $u->user_login . " (¿una ejecución anterior interrumpida?). Bórralo antes de repetir la prueba.\n" );
}
wp_set_current_user( 0 );
Inscripciones::install_table();
Operaciones::install_table();

$max_id        = static fn( string $t ) => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(MAX(id), 0) FROM %i', $t ) );
$log_antes     = $max_id( Logger::table() );
$ops_antes     = $max_id( Operaciones::table() );
$ema_antes     = $max_id( RegistroEmails::table() );
$ins_antes     = $max_id( Inscripciones::table() );
$altas_antes   = get_option( Altas::OPCION, null );
$modulos_antes = get_option( 'asde_ap_modulos', null );
$enlace_antes  = get_option( Enlaces::OPCION, null );
$socios_antes  = array_column( (array) $wpdb->get_results( 'SELECT * FROM ' . SociosTable::table(), ARRAY_A ), null, 'num_socio_asde' ); // phpcs:ignore WordPress.DB

// v0.35.1: la prueba cuenta con «Altas en el CRM» apagadas en el grupo (el Local puede tenerlas encendidas).
$altas_antes = Altas::activo( (string) $grupo['numero'] );
Altas::activar( (string) $grupo['numero'], false );

$cache_externa = wp_using_ext_object_cache( true );
$cortar_crm    = static fn( $pre, $args, $url ) => false === $pre && str_contains( (string) $url, '/rest.php' ) ? new WP_Error( 'prueba', 'CRM real cortado durante la prueba' ) : $pre;
add_filter( 'pre_http_request', $cortar_crm, 2, 3 );
add_filter( 'asde_ap_instantanea_segundos', '__return_zero' );
add_filter( 'asde_ap_solicitud_segundos_minimos', '__return_zero' ); // El sello de tiempo, sin esperar 3 s.
add_filter( 'send_auth_cookies', '__return_false' );
$redirigir = static function ( $destino ) {
	throw new RuntimeException( 'asde-redirect:' . $destino );
};
add_filter( 'wp_redirect', $redirigir, 1 );
$morir = static fn() => static function ( $mensaje ) {
	throw new RuntimeException( 'asde-die:' . ( is_wp_error( $mensaje ) ? $mensaje->get_error_message() : (string) $mensaje ) );
};
add_filter( 'wp_die_handler', $morir, PHP_INT_MAX );
$correos  = array();
$capturar = static function ( $pre, $atts ) use ( &$correos ) {
	$correos[] = $atts;
	return true;
};
add_filter( 'pre_wp_mail', $capturar, 10, 2 );
$ultimo = static function () use ( &$correos ): array {
	return $correos ? end( $correos ) : array(
		'to'      => '',
		'subject' => '',
		'message' => '',
	);
};
// Primer enlace del email que contenga $param (los & van como &amp; en el HTML).
$enlace_de = static function ( array $correo, string $param ): array {
	if ( ! preg_match( '/href="([^"]*[?&](?:amp;)?' . preg_quote( $param, '/' ) . '=[^"]*)"/', (string) $correo['message'], $m ) ) {
		return array();
	}
	wp_parse_str( (string) wp_parse_url( html_entity_decode( $m[1] ), PHP_URL_QUERY ), $q );
	return $q;
};

$P   = array( 'Asde_Prueba_Crm', 'persona' );
$crm = new Asde_Prueba_Crm(
	array(
		$P( 71, 'Clara', 'Espera Secretaria', '1990-01-01', 'Scouters', array( 'stic_identification_number_c' => '33333333P' ) ),
		$P( 72, 'Biel', 'Espera Scouter', '1995-01-01', 'Scouters' ),
		$P( 73, 'Laia', 'Espera Ya Socia', '2015-05-05', 'Lobatos', array( 'stic_identification_number_c' => '12345678Z' ) ),
	),
	$grupo['crm_id']
);
$id_de = static fn( int $n ): string => sprintf( 'de000000-0000-4000-8000-%012d', 9900 + $n );
$crm->alta_membresia( $id_de( 71 ), 'Scouters', '2015-09-01' );
$crm->alta_membresia( $id_de( 72 ), 'Scouters', '2020-09-01' );
$crm->alta_membresia( $id_de( 73 ), 'Lobatos', '2024-09-01' );
Grupo::forzar( $grupo );
$crm->activar();
Permisos::olvidar( $grupo['numero'] );
delete_transient( 'asde_ap_recuento_federal_' . $grupo['numero'] );

$ids   = array();
$crear = static function ( string $login, string $rol, array $meta = array() ) use ( &$ids, $grupo ): int {
	$id    = wp_insert_user(
		array(
			'user_login' => $login,
			'user_email' => '',
			'user_pass'  => wp_generate_password( 24 ),
			'role'       => $rol,
		)
	);
	$ids[] = $id;
	update_user_meta( $id, Usuarios::META_GRUPO, $grupo['id'] );
	update_user_meta( $id, Usuarios::META_ESTADO, Usuarios::ACTIVO );
	foreach ( $meta as $k => $v ) {
		add_user_meta( $id, $k, $v );
	}
	return $id;
};
$u_sec = $crear( 'prueba-espera-sec', Roles::SECRETARIA, array( Usuarios::META_SOCIO => '990071' ) );
$u_sco = $crear( 'prueba-espera-sco', Roles::SCOUTER, array( Usuarios::META_SOCIO => '990072' ) );
$u_tes = $crear( 'prueba-espera-tes', Roles::TESORERIA );
$u_fam = $crear( 'prueba-espera-fam', Roles::TUTOR, array( Usuarios::META_REPRESENTADO => '990073' ) );
$como  = static function ( int $uid ): void {
	wp_set_current_user( $uid );
	Ambito::olvidar( $uid );
	Modulos::olvidar();
};

$reset  = static function (): void {
	foreach ( array( array( PersonasView::class, 'alta' ), array( InscripcionEsperaView::class, 'estado' ) ) as list( $clase, $prop ) ) {
		( new ReflectionProperty( $clase, $prop ) )->setValue( null, 'alta' === $prop ? null : array() );
	}
};
$pintar = static function ( View $vista, array $get = array() ) use ( $reset ): string {
	$_GET = $get;
	try {
		return $vista->render();
	} finally {
		$_GET = array();
	}
};
/** Pinta una vista sin nada recordado de un POST anterior (cada petición del sitio empieza de cero). */
$ver = static function ( View $vista, array $get = array() ) use ( $reset, $pintar ): string {
	$reset();
	return $pintar( $vista, $get );
};
/** Envía un formulario: [avisos, destino si redirige, lo que pinta después con $get]. */
$enviar = static function ( View $vista, array $campos, string $nonce, array $get = array() ) use ( $reset, $pintar ): array {
	$reset();
	$_POST    = $campos + array( '_wpnonce' => wp_create_nonce( $nonce ) );
	$_REQUEST = $_POST;
	$destino  = '';
	$html     = '';
	try {
		$vista->handle_post();
		$html = $pintar( $vista, $get );
	} catch ( RuntimeException $e ) {
		$destino = $e->getMessage();
	} finally {
		$_POST    = array();
		$_REQUEST = array();
	}
	return array( Notices::render(), $destino, $html );
};
$publica = static fn( array $campos, string $accion ) => $enviar( new InscripcionEsperaView(), $campos + array( 'asde_action' => $accion ), 'asde_' . $accion );
$equipo  = static fn( int $id, array $campos ) => $enviar( new ListaEsperaView(), $campos + array( 'inscripcion' => (string) $id ), 'asde_espera_' . $id );
$hace    = static fn( int $anios, string $mas = '' ): string => wp_date( 'Y-m-d', strtotime( "-$anios years $mas" ) );
$sello   = static fn(): string => (string) preg_replace( '/^.*name="sello" value="([^"]+)".*$/s', '$1', $ver( new InscripcionEsperaView(), array( 'grupo' => $grupo['id'], 'clave' => Enlaces::clave( $grupo ) ) ) );
$form    = static function ( array $mas = array() ) use ( $grupo, $hace ): array {
	return array_replace(
		array(
			'grupo'              => $grupo['id'],
			'clave'              => Enlaces::clave( $grupo ),
			'nombre'             => 'Pau',
			'apellidos'          => 'Prova Espera',
			'nacimiento'         => $hace( 9 ),
			'documento'          => '',
			'contacto_nombre'    => 'Marta',
			'contacto_apellidos' => 'Espera Mare',
			'telefono'           => '600 11 22 33',
			'email'              => 'marta.espera@example.invalid',
			'observaciones'      => "Tiene un hermano en el grupo.\nPrefiere sábados.",
			'acepto'             => '1',
		),
		$mas
	);
};
$fila_de = static fn( string $nombre ) => array_values( array_filter( Inscripciones::listado(), static fn( $f ) => $nombre === $f['datos']['nombre'] ) )[0] ?? null;

try {
	echo "== A · Sección por edad y módulo apagado por defecto ==\n";
	$ok( 'castores' === Secciones::por_edad( '2020-11-30', '2026-10-14' ) && '' === Secciones::por_edad( '2021-01-01', '2026-10-14' ), 'Castores con los 6 que cumple en el año en que empieza la ronda (aunque los cumpla en noviembre); con 5, aún sin edad' );
	$ok( 'lobatos' === Secciones::por_edad( '2018-03-01', '2026-10-14' ) && 'scouts' === Secciones::por_edad( '2015-01-01', '2026-10-14' ) && 'escultas' === Secciones::por_edad( '2012-12-31', '2026-10-14' ) && 'rovers' === Secciones::por_edad( '2009-06-01', '2026-10-14' ) && 'scouters' === Secciones::por_edad( '2005-01-01', '2026-10-14' ), 'cortes 8 / 11 / 14 / 17 / 21 (P-123)' );
	$ok( 'castores' === Secciones::por_edad( '2019-01-01', '2027-08-31' ) && 'lobatos' === Secciones::por_edad( '2019-01-01', '2027-09-01' ), 'cambia de sección al empezar la ronda (septiembre), no a mitad' );

	delete_option( 'asde_ap_modulos' );
	Modulos::olvidar();
	$como( $u_sco );
	$ok( ! Modulos::activo( Modulos::LISTA_ESPERA, (string) $grupo['numero'] ) && ! current_user_can( 'asde_lista_espera' ) && ! isset( Menu::grupos( Ambito::actual() )[ Menu::GESTION ]['items']['lista_espera'] ), 'nace apagado: sin el permiso ni el menú (regla 5)' );
	$ok( Modulos::activo( Modulos::INVENTARIO, (string) $grupo['numero'] ), 'los demás módulos siguen activos por defecto' );
	$h = $ver( new InscripcionEsperaView(), array( 'grupo' => $grupo['id'], 'clave' => Enlaces::clave( $grupo ) ) );
	$ok( str_contains( $h, 'Este enlace no es válido' ) && ! str_contains( $h, 'name="nombre"' ), 'con el módulo apagado, el enlace del grupo no enseña el formulario' );
	Modulos::guardar( (string) $grupo['numero'], array_keys( Modulos::LISTA ) );
	$como( $u_sco );
	$ok( true === ( Modulos::apagados()[ $grupo['numero'] ][ Modulos::LISTA_ESPERA ] ?? null ) && Modulos::activo( Modulos::LISTA_ESPERA, (string) $grupo['numero'] ) && current_user_can( 'asde_lista_espera' ) && ! current_user_can( 'asde_lista_espera_alta' ), 'encendido en Módulos: el scouter ve la lista, pero no da de alta' );
	$ok( isset( Menu::grupos( Ambito::actual() )[ Menu::GESTION ]['items']['lista_espera'] ), 'y le sale en el menú' );
	Modulos::guardar( (string) $grupo['numero'], array_diff( array_keys( Modulos::LISTA ), array( Modulos::INVENTARIO ) ) );
	$ok( ! Modulos::activo( Modulos::INVENTARIO, (string) $grupo['numero'] ) && Modulos::activo( Modulos::LISTA_ESPERA, (string) $grupo['numero'] ), 'apagar otro módulo no apaga la lista de espera' );
	Modulos::guardar( (string) $grupo['numero'], array_keys( Modulos::LISTA ) );

	echo "\n== B · Validación del formulario ==\n";
	$r = Formulario::validar( array() );
	$ok( count( $r['errores'] ) >= 5, 'vacío: faltan nombre, apellidos, nacimiento, teléfono, email y la casilla' );
	$r = Formulario::validar( $form( array( 'nacimiento' => $hace( 15 ) ) ) );
	$ok( in_array( 'El documento es obligatorio a partir de los 14 años.', $r['errores'], true ), 'con 15 años el documento es obligatorio (P-82)' );
	$r = Formulario::validar( $form( array( 'documento' => '12345678A' ) ) );
	$ok( (bool) array_filter( $r['errores'], static fn( $e ) => str_contains( $e, 'letra del DNI' ) ), 'la letra del DNI se comprueba' );
	$r = Formulario::validar( $form( array( 'contacto_nombre' => '' ) ) );
	$ok( (bool) array_filter( $r['errores'], static fn( $e ) => str_contains( $e, 'madre, el padre o el tutor' ) ), 'por debajo de 18, la persona de contacto es obligatoria (la pide el alta, P-125)' );
	$r = Formulario::validar( $form( array( 'contacto_nombre' => '', 'contacto_apellidos' => '', 'nacimiento' => $hace( 30 ), 'documento' => '22222222-j' ) ) );
	$ok( ! $r['errores'] && '22222222J' === $r['datos']['documento'] && '600112233' === $r['datos']['telefono'], 'un adulto sin contacto aparte vale; documento y teléfono normalizados' );
	$r = Formulario::validar( $form( array( 'telefono' => '123', 'email' => 'no-es-email', 'acepto' => '' ) ) );
	$ok( 3 === count( $r['errores'] ), 'teléfono corto, email no válido y sin aceptar: tres errores' );
	$r = Formulario::validar( $form( array( 'nacimiento' => wp_date( 'Y-m-d', time() + 2 * DAY_IN_SECONDS ) ) ) );
	$ok( in_array( 'La fecha de nacimiento no es válida.', $r['errores'], true ), 'sin fechas de nacimiento futuras' );

	echo "\n== C · Apuntarse, confirmar y la página personal ==\n";
	wp_set_current_user( 0 );
	$h = $ver( new InscripcionEsperaView(), array( 'grupo' => $grupo['id'], 'clave' => 'otra' ) );
	$ok( str_contains( $h, 'Este enlace no es válido' ) && ! str_contains( $h, 'name="nombre"' ), 'con una clave que no es la del grupo, no hay formulario' );
	$h = $ver( new InscripcionEsperaView(), array( 'grupo' => $grupo['id'], 'clave' => Enlaces::clave( $grupo ) ) );
	$ok( str_contains( $h, 'name="nombre"' ) && str_contains( $h, 'name="nacimiento"' ) && str_contains( $h, 'name="documento"' ) && str_contains( $h, 'name="telefono"' ) && str_contains( $h, 'name="email"' ) && str_contains( $h, 'name="observaciones"' ) && str_contains( $h, 'name="acepto"' ) && str_contains( $h, 'name="web"' ), 'con el enlace del grupo: el formulario con los datos mínimos, el consentimiento y el campo trampa' );

	$correos = array();
	list( , , $h ) = $publica( $form( array( 'web' => 'http://spam.example.invalid', 'sello' => $sello() ) ), 'espera_inscribir' );
	$ok( str_contains( $h, 'Gracias.' ) && $ins_antes === $max_id( Inscripciones::table() ) && ! $correos, 'campo trampa relleno: la misma respuesta, pero no se guarda ni se envía nada' );
	list( , , $h ) = $publica( $form( array( 'email' => 'malo', 'sello' => $sello() ) ), 'espera_inscribir' );
	$ok( str_contains( $h, 'Escribe un email válido.' ) && str_contains( $h, 'value="Prova Espera"' ) && $ins_antes === $max_id( Inscripciones::table() ), 'con errores: se dicen y lo escrito se conserva' );

	list( , , $h ) = $publica( $form( array( 'sello' => $sello() ) ), 'espera_inscribir' );
	$pau = $fila_de( 'Pau' );
	$c   = $ultimo();
	$ok( str_contains( $h, 'Te hemos enviado un email a' ) && $pau && Inscripciones::SIN_CONFIRMAR === $pau['estado'] && 'marta.espera@example.invalid' === $c['to'] && str_contains( (string) $c['subject'], 'Confirma la inscripción' ), 'apuntado: queda sin confirmar y le llega el email para confirmarlo' );
	$crudo = (array) $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', Inscripciones::table(), $pau['id'] ?? 0 ), ARRAY_A );
	$ok( $crudo && ! str_contains( (string) wp_json_encode( $crudo ), 'Prova Espera' ) && ! str_contains( (string) wp_json_encode( $crudo ), 'marta.espera' ) && ! str_contains( (string) wp_json_encode( $crudo ), '600112233' ), 'en la base de datos todo va cifrado (ni el nombre, ni el email, ni el teléfono en claro)' );
	$ok( 'lobatos' === $pau['seccion_edad'] && 'lobatos' === $pau['seccion_final'], 'su sección por edad sale sola (9 años: Lobatos)' );
	$reg = (array) $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id > %d AND tipo = %s ORDER BY id DESC LIMIT 1', RegistroEmails::table(), $ema_antes, RegistroEmails::LISTA_ESPERA ), ARRAY_A );
	$ok( $reg && ! str_contains( (string) $reg['destino'], 'marta.espera@' ) && str_contains( (string) $reg['destino'], '*' ), 'queda en el registro de emails con el destino enmascarado' );

	$antes = $max_id( Inscripciones::table() );
	list( , , $h ) = $publica( $form( array( 'sello' => $sello() ) ), 'espera_inscribir' );
	$ok( $antes === $max_id( Inscripciones::table() ) && str_contains( (string) $ultimo()['subject'], 'Confirma la inscripción' ), 'la misma persona con el mismo email: no se repite, se reenvía la confirmación (P-129)' );

	$q = $enlace_de( $ultimo(), 'confirmar' );
	$h = $ver( new InscripcionEsperaView(), $q );
	$ok( str_contains( $h, 'value="espera_confirmar"' ) && Inscripciones::SIN_CONFIRMAR === Inscripciones::por_id( (int) $pau['id'] )['estado'], 'abrir el enlace del email enseña el botón, sin confirmar todavía (un antivirus que abre enlaces no confirma)' );
	list( , , $h ) = $publica( array( 'id' => $q['confirmar'] ?? '', 't' => 'x' . substr( (string) ( $q['t'] ?? '' ), 1 ) ), 'espera_confirmar' );
	$ok( str_contains( $h, 'no es válido' ) && Inscripciones::SIN_CONFIRMAR === Inscripciones::por_id( (int) $pau['id'] )['estado'], 'con un token cambiado no se confirma' );
	list( , , $h ) = $publica( array( 'id' => $q['confirmar'] ?? '', 't' => $q['t'] ?? '' ), 'espera_confirmar' );
	$pau = Inscripciones::por_id( (int) $pau['id'] );
	$ok( str_contains( $h, 'Inscripción confirmada.' ) && Inscripciones::EN_ESPERA === $pau['estado'] && abs( strtotime( $pau['caduca'] . ' UTC' ) - strtotime( '+12 months' ) ) < 2 * DAY_IN_SECONDS, 'confirmada: entra en la lista y sus datos caducan en 12 meses' );
	$ok( str_contains( (string) $ultimo()['subject'], 'Inscripción confirmada' ) && $enlace_de( $ultimo(), 'espera' ), 'y le llega el email con su enlace personal' );

	$q = $enlace_de( $ultimo(), 'espera' );
	$h = $ver( new InscripcionEsperaView(), $q );
	$ok( str_contains( $h, 'name="email"' ) && ! str_contains( $h, 'Prova Espera' ), 'el enlace personal pide el email antes de enseñar nada' );
	list( , , $h ) = $publica( array( 'id' => $q['espera'], 't' => $q['t'], 'email' => 'otro@example.invalid' ), 'espera_entrar' );
	$ok( str_contains( $h, 'Ese email no es el de esta inscripción.' ) && ! str_contains( $h, 'Prova Espera' ), 'con otro email no se ven los datos' );
	list( , , $h ) = $publica( array( 'id' => $q['espera'], 't' => $q['t'], 'email' => 'MARTA.espera@example.invalid' ), 'espera_entrar' );
	$ok( str_contains( $h, 'Pau Prova Espera' ) && str_contains( $h, 'Prefiere sábados.' ) && str_contains( $h, 'value="espera_mantener"' ) && str_contains( $h, 'value="espera_borrar"' ), 'con su email (sin importar mayúsculas): sus datos, «Mantener» y «Borrar»' );
	$wpdb->update( Inscripciones::table(), array( 'caduca' => gmdate( 'Y-m-d H:i:s', time() + 5 * DAY_IN_SECONDS ), 'avisada' => 1 ), array( 'id' => $pau['id'] ) );
	list( , , $h ) = $publica( array( 'id' => $q['espera'], 't' => $q['t'], 'email' => 'marta.espera@example.invalid' ), 'espera_mantener' );
	$pau = Inscripciones::por_id( (int) $pau['id'] );
	$ok( str_contains( $h, 'guardamos tus datos hasta' ) && strtotime( $pau['caduca'] . ' UTC' ) > time() + 300 * DAY_IN_SECONDS && ! $pau['avisada'], '«Mantener mis datos»: 12 meses más desde hoy, y otro aviso cuando toque' );

	$correos = array();
	list( , , $h ) = $publica( array( 'email_enlaces' => 'nadie@example.invalid' ), 'espera_enlaces' );
	list( , , $h2 ) = $publica( array( 'email_enlaces' => 'marta.espera@example.invalid' ), 'espera_enlaces' );
	$ok( str_contains( $h, 'te hemos enviado un email con tus enlaces' ) && str_contains( $h2, 'te hemos enviado un email con tus enlaces' ) && 1 === count( $correos ) && $enlace_de( $correos[0], 'espera' ), '«¿Ya estás en la lista?»: misma respuesta siempre; solo llega email si está' );

	echo "\n== D · La lista para el equipo ==\n";
	// Otra inscripción (adulta, con documento) para el alta, y una de alguien que ya es del grupo.
	wp_set_current_user( 0 );
	$publica( $form( array( 'nombre' => 'Joana', 'apellidos' => 'Prova Adulta', 'nacimiento' => $hace( 30 ), 'documento' => '22222222J', 'contacto_nombre' => '', 'contacto_apellidos' => '', 'email' => 'joana.espera@example.invalid', 'sello' => $sello() ) ), 'espera_inscribir' );
	$publica( $form( array( 'nombre' => 'Laia', 'apellidos' => 'Espera Ya Socia', 'nacimiento' => '2015-05-05', 'documento' => '12345678Z', 'email' => 'laia.espera@example.invalid', 'sello' => $sello() ) ), 'espera_inscribir' );
	foreach ( array( 'Joana', 'Laia' ) as $n ) {
		Inscripciones::confirmar( (int) $fila_de( $n )['id'] );
	}
	$joana = $fila_de( 'Joana' );
	$laia  = $fila_de( 'Laia' );

	$como( $u_fam );
	$h = $ver( new ListaEsperaView() );
	$ok( str_contains( $h, 'No tienes acceso' ) && ! str_contains( $h, 'Prova Espera' ), 'una familia no ve la lista' );
	$como( $u_tes );
	$ok( str_contains( $pintar( new ListaEsperaView() ), 'Pau Prova Espera' ), 'Tesorería sí la ve' );
	$como( $u_sco );
	$h = $pintar( new ListaEsperaView() );
	$ok( str_contains( $h, 'Pau Prova Espera' ) && str_contains( $h, 'Joana Prova Adulta' ) && str_contains( $h, 'asde-badge' ) && str_contains( $h, 'asde-enlace-espera' ) && ! str_contains( $h, 'renovar_enlace_espera' ), 'el scouter ve la lista con la sección por edad y el enlace, pero no lo renueva' );
	$h = $pintar( new ListaEsperaView(), array( 'inscripcion' => (string) $joana['id'] ) );
	$ok( ! str_contains( $h, '22222222J' ) && str_contains( $h, 'Sí (lo ven Secretaría y Coordinación)' ) && ! str_contains( $h, 'value="alta_documento"' ) && ! str_contains( $h, 'espera_seccion' ), 'en la ficha, el scouter no ve el documento ni puede asignar sección o dar de alta' );
	$h = $pintar( new ListaEsperaView(), array( 'seccion' => 'scouters' ) );
	$ok( str_contains( $h, 'Joana Prova Adulta' ) && ! str_contains( $h, 'Pau Prova Espera' ), 'filtro por sección' );

	list( , $destino ) = $equipo( (int) $pau['id'], array( 'asde_action' => 'espera_contacto', 'medio' => 'telefono', 'nota' => 'Llamada: quiere plaza en enero.' ) );
	$pau = Inscripciones::obtener( (int) $pau['id'] );
	$ok( Inscripciones::CONTACTADA === $pau['estado'] && 'telefono' === $pau['contacto_medio'] && 'Llamada: quiere plaza en enero.' === ( end( $pau['historial'] )['nota'] ?? '' ), 'apuntar una llamada: pasa a «Contactada» y queda en el historial' );
	$crudo = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT historial_enc FROM %i WHERE id = %d', Inscripciones::table(), $pau['id'] ) );
	$ok( '' !== $crudo && ! str_contains( $crudo, 'quiere plaza' ), 'el historial también va cifrado' );
	$correos = array();
	$equipo( (int) $joana['id'], array( 'asde_action' => 'espera_email', 'asunto' => 'Hay plaza', 'mensaje' => "Hola Joana,\nhay plaza en el Kraal." ) );
	$joana = Inscripciones::obtener( (int) $joana['id'] );
	$ok( 1 === count( $correos ) && 'joana.espera@example.invalid' === $correos[0]['to'] && str_contains( (string) $correos[0]['subject'], 'Hay plaza' ) && str_contains( (string) $correos[0]['message'], 'hay plaza en el Kraal.' ) && Inscripciones::CONTACTADA === $joana['estado'] && 'email' === $joana['contacto_medio'], 'enviar un email desde la ficha: le llega, pasa a «Contactada» por email' );
	$equipo( (int) $pau['id'], array( 'asde_action' => 'espera_estado', 'estado' => 'plaza_ofrecida' ) );
	$ok( Inscripciones::PLAZA_OFRECIDA === Inscripciones::obtener( (int) $pau['id'] )['estado'], 'cambiar el estado (plaza ofrecida)' );
	$equipo( (int) $pau['id'], array( 'asde_action' => 'espera_estado', 'estado' => 'sin_confirmar' ) );
	$ok( Inscripciones::PLAZA_OFRECIDA === Inscripciones::obtener( (int) $pau['id'] )['estado'], 'no se puede volver a «sin confirmar» a mano' );
	$equipo( (int) $pau['id'], array( 'asde_action' => 'espera_seccion', 'seccion' => 'castores' ) );
	$ok( '' === Inscripciones::obtener( (int) $pau['id'] )['seccion'], 'el scouter no puede asignar la sección' );
	$enlace = Enlaces::clave( $grupo );
	$enviar( new ListaEsperaView(), array( 'asde_action' => 'renovar_enlace_espera' ), 'asde_enlace_espera' );
	$ok( $enlace === Enlaces::clave( $grupo ), 'ni renovar el enlace' );

	$como( $u_sec );
	$h = $pintar( new ListaEsperaView(), array( 'inscripcion' => (string) $joana['id'] ) );
	$ok( str_contains( $h, '22222222J' ) && str_contains( $h, 'espera_seccion' ) && str_contains( $h, 'no están activadas' ), 'Secretaría ve el documento y la sección; sin las altas en el CRM, explica por qué no puede dar de alta' );
	$equipo( (int) $pau['id'], array( 'asde_action' => 'espera_seccion', 'seccion' => 'castores' ) );
	$ok( 'castores' === Inscripciones::obtener( (int) $pau['id'] )['seccion_final'], 'Secretaría asigna la sección' );
	$enviar( new ListaEsperaView(), array( 'asde_action' => 'renovar_enlace_espera' ), 'asde_enlace_espera' );
	$ok( $enlace !== Enlaces::clave( $grupo ) && ! Enlaces::valida( $grupo, $enlace ), 'y renueva el enlace: el anterior deja de valer' );

	echo "\n== E · Dar de alta en el CRM (comprueba antes el DNI) ==\n";
	Altas::activar( (string) $grupo['numero'], true );
	$h = $pintar( new ListaEsperaView(), array( 'inscripcion' => (string) $joana['id'] ) );
	$ok( str_contains( $h, 'value="alta_documento"' ) && str_contains( $h, 'name="espera" value="' . $joana['id'] . '"' ), 'con las altas activas, «Dar de alta en el CRM»' );
	$alta = static fn( array $campos ) => $enviar( new PersonasView(), $campos, 'asde_alta_socio', array( 'alta' => '1' ) );
	list( , , $h ) = $alta( array( 'asde_action' => 'alta_documento', 'documento' => '12345678Z', 'espera' => (string) $laia['id'] ) );
	$ok( str_contains( $h, 'Ya tiene ficha en tu grupo' ) && null !== Inscripciones::obtener( (int) $laia['id'] ), 'si el DNI ya tiene ficha en el grupo, el alta se para y la inscripción se queda' );
	list( , , $h ) = $alta( array( 'asde_action' => 'alta_documento', 'documento' => '22222222J', 'espera' => (string) $joana['id'] ) );
	$ok( str_contains( $h, 'value="alta_revisar"' ) && str_contains( $h, 'value="Joana"' ) && str_contains( $h, 'value="Prova Adulta"' ) && str_contains( $h, 'value="joana.espera@example.invalid"' ) && str_contains( $h, 'name="espera" value="' . $joana['id'] . '"' ) && str_contains( $h, 'Viene de la lista de espera' ), 'DNI nuevo: pasa a los datos, rellenos con lo de la lista de espera' );
	$d = array(
		'first_name'                   => 'Joana',
		'last_name'                    => 'Prova Adulta',
		'birthdate'                    => $hace( 30 ),
		'stic_gender_c'                => 'femenino',
		'stic_identification_number_c' => '22222222J',
		'email1'                       => 'joana.espera@example.invalid',
	);
	$base = array(
		'd'          => $d,
		'seccion'    => 'scouters',
		'inicio'     => wp_date( 'Y-m-d' ),
		'comprobado' => '22222222J',
		'otros'      => '0',
		'espera'     => (string) $joana['id'],
	);
	list( , , $h ) = $alta( $base + array( 'asde_action' => 'alta_revisar' ) );
	$ok( str_contains( $h, 'value="alta_crear"' ) && str_contains( $h, 'name="espera" value="' . $joana['id'] . '"' ), 'revisar: la inscripción sigue viajando con el alta' );
	// El simulado no sabe contar en otros grupos: se confirma que se sigue igualmente.
	list( , , $h ) = $alta( $base + array( 'asde_action' => 'alta_crear', 'confirmo' => '1' ) );
	$nueva = array_values( array_filter( $crm->personas, static fn( $p ) => '22222222J' === $p['stic_identification_number_c'] ) )[0] ?? null;
	$ok( str_contains( $h, 'Alta hecha en el CRM.' ) && $nueva && null === Inscripciones::obtener( (int) $joana['id'] ) && str_contains( $h, 'Sus datos de la lista de espera se han borrado.' ), 'alta hecha: ficha en el CRM y la inscripción borrada en el momento' );
	$ok( 0 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE id = %d', Inscripciones::table(), $joana['id'] ) ), '(ni rastro de la fila)' );
	$como( $u_sco );
	list( , , $h ) = $alta( array( 'asde_action' => 'alta_documento', 'documento' => '33333333P', 'espera' => (string) $laia['id'] ) );
	$ok( null !== Inscripciones::obtener( (int) $laia['id'] ), 'un scouter no puede usar el alta (ni tocar la inscripción)' );
	$como( $u_sec );
	list( , $destino ) = $equipo( (int) $laia['id'], array( 'asde_action' => 'espera_quitar' ) );
	$ok( null === Inscripciones::obtener( (int) $laia['id'] ) && str_starts_with( $destino, 'asde-redirect:' ), 'Secretaría la borra de la lista (ya era del grupo)' );

	echo "\n== F · Caducidad, sin confirmar y la persona que borra sus datos ==\n";
	wp_set_current_user( 0 );
	$publica( $form( array( 'nombre' => 'Rita', 'apellidos' => 'Prova Sense', 'email' => 'rita.espera@example.invalid', 'sello' => $sello() ) ), 'espera_inscribir' );
	$rita = $fila_de( 'Rita' );
	$wpdb->update( Inscripciones::table(), array( 'creada' => gmdate( 'Y-m-d H:i:s', time() - 8 * DAY_IN_SECONDS ) ), array( 'id' => $rita['id'] ) );
	$wpdb->update( Inscripciones::table(), array( 'caduca' => gmdate( 'Y-m-d H:i:s', time() + 10 * DAY_IN_SECONDS ) ), array( 'id' => $pau['id'] ) );
	$avisadas = array();
	$r        = Inscripciones::mantenimiento(
		static function ( array $f ) use ( &$avisadas ): bool {
			$avisadas[] = $f['id'];
			return true;
		}
	);
	$ok( null === Inscripciones::por_id( (int) $rita['id'] ) && $r['borradas'] >= 1, 'sin confirmar en 7 días: se borra sola' );
	$ok( in_array( (int) $pau['id'], $avisadas, true ) && Inscripciones::por_id( (int) $pau['id'] )['avisada'], 'caduca en 10 días: se avisa (una vez)' );
	$avisadas = array();
	Inscripciones::mantenimiento(
		static function ( array $f ) use ( &$avisadas ): bool {
			$avisadas[] = $f['id'];
			return true;
		}
	);
	$ok( ! in_array( (int) $pau['id'], $avisadas, true ), 'al día siguiente no se repite el aviso' );
	$correos = array();
	$ok( \AsdeBalears\AreaPrivada\ListaEspera\Avisos::caducidad( Inscripciones::por_id( (int) $pau['id'] ) ) && str_contains( (string) $ultimo()['subject'], 'caducan pronto' ) && $enlace_de( $ultimo(), 'espera' ), 'el aviso de caducidad lleva su enlace para mantener los datos' );

	$q = $enlace_de( $ultimo(), 'espera' );
	list( , , $h ) = $publica( array( 'id' => $q['espera'], 't' => $q['t'], 'email' => 'marta.espera@example.invalid' ), 'espera_borrar' );
	$ok( str_contains( $h, 'Marca la casilla' ) && null !== Inscripciones::por_id( (int) $pau['id'] ), 'borrar sin marcar la casilla: no se borra' );
	list( , , $h ) = $publica( array( 'id' => $q['espera'], 't' => $q['t'], 'email' => 'marta.espera@example.invalid', 'seguro' => '1' ), 'espera_borrar' );
	$ok( str_contains( $h, 'Tus datos se han borrado' ) && null === Inscripciones::por_id( (int) $pau['id'] ), '«Borrar mis datos»: fuera de la lista' );
	$h = $ver( new InscripcionEsperaView(), $q );
	$ok( str_contains( $h, 'no es válido' ), 'después, su enlace ya no lleva a nada' );

	$logs = (string) wp_json_encode( $wpdb->get_results( $wpdb->prepare( "SELECT accion, detalles FROM %i WHERE id > %d AND accion LIKE 'lista\\_espera%%'", Logger::table(), $log_antes ), ARRAY_A ) );
	$ok( str_contains( $logs, 'lista_espera_inscripcion' ) && ! str_contains( $logs, 'Prova' ) && ! str_contains( $logs, 'example.invalid' ) && ! str_contains( $logs, '22222222J' ), 'la auditoría guarda las acciones, sin nombres, emails ni documentos' );
} finally {
	$crm->desactivar();
	remove_filter( 'pre_http_request', $cortar_crm, 2 );
	remove_filter( 'asde_ap_instantanea_segundos', '__return_zero' );
	remove_filter( 'asde_ap_solicitud_segundos_minimos', '__return_zero' );
	remove_filter( 'send_auth_cookies', '__return_false' );
	remove_filter( 'wp_redirect', $redirigir, 1 );
	remove_filter( 'wp_die_handler', $morir, PHP_INT_MAX );
	remove_filter( 'pre_wp_mail', $capturar, 10 );
	$_GET  = array();
	$_POST = array();
	View::en_modal( false );
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id > %d', Inscripciones::table(), $ins_antes ) );
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id > %d', RegistroEmails::table(), $ema_antes ) );
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id > %d', Operaciones::table(), $ops_antes ) );
	null === $altas_antes ? delete_option( Altas::OPCION ) : update_option( Altas::OPCION, $altas_antes, false );
	null === $modulos_antes ? delete_option( 'asde_ap_modulos' ) : update_option( 'asde_ap_modulos', $modulos_antes, false );
	Altas::activar( (string) $grupo['numero'], $altas_antes );
	null === $enlace_antes ? delete_option( Enlaces::OPCION ) : update_option( Enlaces::OPCION, $enlace_antes, false );
	Modulos::olvidar();
	Permisos::olvidar( $grupo['numero'] );
	delete_transient( 'asde_ap_recuento_federal_' . $grupo['numero'] );
	wp_using_ext_object_cache( $cache_externa );
	wp_set_current_user( 0 );
	$lista = implode( ',', array_map( 'intval', $ids ) ) ?: '0';
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Logger::table() . " WHERE id > %d AND ( user_id IN ($lista) OR accion LIKE 'crm\\_%%' OR accion LIKE 'lista\\_espera%%' OR accion = 'enlace_espera_renovado' )", $log_antes ) ); // phpcs:ignore WordPress.DB
	foreach ( $ids as $uid ) {
		delete_transient( 'asde_ap_comprobaciones_' . $uid );
		delete_transient( 'asde_ap_notices_' . $uid );
		Ambito::olvidar( (int) $uid );
		wp_delete_user( (int) $uid );
	}
	$ahora = array_column( (array) $wpdb->get_results( 'SELECT * FROM ' . SociosTable::table(), ARRAY_A ), null, 'num_socio_asde' ); // phpcs:ignore WordPress.DB
	foreach ( array_diff_key( $ahora, $socios_antes ) as $asde => $f ) {
		$wpdb->delete( SociosTable::table(), array( 'num_socio_asde' => (string) $asde ) );
	}
	Grupo::forzar( null );

	$quedan = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users} WHERE user_login LIKE 'prueba-espera-%'" ); // phpcs:ignore WordPress.DB
	$filas  = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE id > %d', Inscripciones::table(), $ins_antes ) );
	$ok( 0 === $quedan && 0 === $filas, 'limpieza: sin usuarios ni inscripciones de la prueba' );
}

echo "\n", $fails ? "FALLOS: $fails\n" : "TODO OK\n";
