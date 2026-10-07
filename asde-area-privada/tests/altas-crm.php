<?php
/**
 * Pruebas de la v0.21 (entorno LOCAL): altas en el CRM, fase 1 (doc 34):
 * la comprobación previa de solo lectura («¿Está ya en el CRM?»). Desde la v0.23.1
 * solo está dentro de «Alta de socio/a» (decisión del usuario): la parte C lo comprueba.
 * Ejecutar desde app/public del site Local:
 *
 *   wp eval-file <ruta completa>/wp-content/plugins/asde-area-privada/tests/altas-crm.php
 *
 * Usa el CRM simulado de tests/lib con fichas inventadas en el grupo y en «otros
 * grupos» (las que get_entry_list no devuelve y get_entries_count sí cuenta):
 * ninguna petición llega al CRM real. Al terminar se borra lo creado y se
 * restaura la tabla de números.
 */
defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/lib/CrmSimulado.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Auth\Ambito;
use AsdeBalears\AreaPrivada\Crm\Altas;
use AsdeBalears\AreaPrivada\Crm\Duplicados;
use AsdeBalears\AreaPrivada\Crm\Permisos;
use AsdeBalears\AreaPrivada\Domain\Grupo;
use AsdeBalears\AreaPrivada\Domain\Grupos;
use AsdeBalears\AreaPrivada\Frontend\Router;
use AsdeBalears\AreaPrivada\Frontend\Views\PersonasView;
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
foreach ( get_users( array( 'search' => 'prueba-altas-*', 'search_columns' => array( 'user_login' ) ) ) as $u ) {
	exit( 'Ya existe el usuario ' . $u->user_login . " (¿una ejecución anterior interrumpida?). Bórralo antes de repetir la prueba.\n" );
}
wp_set_current_user( 0 );

$log_antes    = (int) $wpdb->get_var( 'SELECT COALESCE(MAX(id), 0) FROM ' . Logger::table() ); // phpcs:ignore WordPress.DB
$socios_antes = array_column( (array) $wpdb->get_results( 'SELECT * FROM ' . SociosTable::table(), ARRAY_A ), null, 'num_socio_asde' ); // phpcs:ignore WordPress.DB
$altas_antes  = get_option( Altas::OPCION, null );

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

// Documentos inventados con la letra bien calculada (12345678Z, 87654321X, 11111111H,
// 22222222J, X1234567L, 01234567L).
$P   = array( 'Asde_Prueba_Crm', 'persona' );
$doc = static fn( string $d ): array => array( 'stic_identification_number_c' => $d );
$crm = new Asde_Prueba_Crm(
	array(
		$P( 51, 'Lia', 'Altas Roig', '2010-02-02', 'Scouts', $doc( '12345678Z' ) ),
		$P( 52, 'Marc', 'Altas Pons', '1990-05-05', '', $doc( '87654321-x' ) ), // Sin membresía activa y escrito a la antigua.
		$P( 53, 'Ona', 'Altas Vidal', '2016-01-01', 'Lobatos' ),
	),
	$grupo['crm_id']
);
$crm->otros_grupos = array(
	$P( 61, 'Otra', 'Ficha Uno', '2010-02-02', 'Scouts', $doc( '12345678Z' ) ), // La misma persona, también en otro grupo.
	$P( 62, 'Otra', 'Ficha Dos', '2005-03-03', 'Rovers', $doc( '11111111H' ) ),
	$P( 63, 'Otra', 'Ficha Tres', '1985-04-04', 'Scouters', $doc( 'X-1234567-L' ) ),
	$P( 64, 'Otra', 'Ficha Cuatro', '1970-06-06', 'Scouters', $doc( '1234567L' ) ),
);
Grupo::forzar( $grupo );
$crm->activar();
$canario = 'asde_ap_recuento_federal_' . $grupo['numero'];
delete_transient( $canario );

$ids   = array();
$crear = static function ( string $login, string $rol ) use ( &$ids, $grupo ): int {
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
	$ids[ $login ] = $id;
	return $id;
};
$u_sec = $crear( 'prueba-altas-sec', Roles::SECRETARIA );
$u_sco = $crear( 'prueba-altas-sco', Roles::SCOUTER );
$como  = static function ( int $uid ): void {
	wp_set_current_user( $uid );
	Ambito::olvidar( $uid );
};
/** Pinta Personas con estos parámetros, en la ventana emergente o en su página. */
$ver = static function ( array $get, bool $ventana = true ): string {
	$_GET = $get;
	View::en_modal( $ventana );
	try {
		return ( new PersonasView() )->render();
	} finally {
		$_GET = array();
		View::en_modal( false );
	}
};
/** Envía el primer paso de «Alta de socio/a» (el documento) y pinta la respuesta (la misma petición). */
$comprobar = static function ( string $documento ) use ( $ver ): string {
	$_POST    = array(
		'asde_action' => 'alta_documento',
		'documento'   => $documento,
		'_wpnonce'    => wp_create_nonce( 'asde_alta_socio' ),
	);
	$_REQUEST = $_POST;
	try {
		( new PersonasView() )->handle_post();
		return $ver( array( 'alta' => '1' ) );
	} finally {
		$_POST    = array();
		$_REQUEST = array();
	}
};

try {
	echo "== Parte A · El documento ==\n";
	$v = Duplicados::validar( ' 12.345.678-z ' );
	$ok( $v['ok'] && '12345678Z' === $v['documento'] && Duplicados::DNI === $v['tipo'], 'un DNI con puntos, guion, espacios y minúscula se normaliza y su letra se comprueba' );
	$v = Duplicados::validar( '12345678A' );
	$ok( ! $v['ok'] && str_contains( $v['error'], 'letra del DNI' ), 'un DNI con la letra mal no se acepta' );
	$ok( str_contains( Duplicados::validar( '12345678' )['error'], 'falta la letra' ), 'a un DNI sin letra se le dice que falta' );
	$v = Duplicados::validar( 'x1234567l' );
	$ok( $v['ok'] && 'X1234567L' === $v['documento'] && Duplicados::NIE === $v['tipo'] && ! Duplicados::validar( 'X1234567A' )['ok'], 'el NIE también: letra bien, aceptado; letra mal, no' );
	$ok( '01234567L' === Duplicados::validar( '1234567-L' )['documento'], 'un DNI de siete cifras se completa con un cero delante' );
	$v = Duplicados::validar( 'PAB123456' );
	$ok( $v['ok'] && Duplicados::OTRO === $v['tipo'] && ! Duplicados::validar( 'AB1' )['ok'], 'otros documentos (pasaporte) se aceptan tal cual; uno de menos de 5 caracteres, no' );
	$f = Duplicados::formas( '01234567L', Duplicados::DNI );
	$ok( in_array( '01234567-L', $f, true ) && in_array( '1234567L', $f, true ) && in_array( '1234567 L', $f, true ), 'para el recuento se cuentan las formas en que puede estar escrito (con guion, con espacio, sin el cero)' );
	$hace = static fn( int $anios, string $mas = '' ): string => wp_date( 'Y-m-d', strtotime( "-$anios years $mas" ) );
	$ok( Duplicados::documento_obligatorio( $hace( 14 ) ) && ! Duplicados::documento_obligatorio( $hace( 14, '+1 day' ) ) && ! Duplicados::documento_obligatorio( $hace( 9 ) ) && Duplicados::documento_obligatorio( $hace( 40 ) ), 'el documento es obligatorio a partir de los 14 años (el día que los cumple, sí; el día antes, no)' );

	echo "\n== Parte B · ¿Está ya en el CRM? ==\n";
	$como( $u_sec );
	$dup = Duplicados::make();
	$r   = $dup->comprobar( '12345678z' );
	$ok( 1 === count( $r['en_grupo'] ) && 'Lia Altas Roig' === $r['en_grupo'][0]['persona']->nombre_completo() && $r['en_grupo'][0]['activa'] && 1 === $r['otros'], 'está en el grupo (con membresía activa) y también en otro grupo: se dicen las dos cosas' );
	$r = $dup->comprobar( '87654321X' );
	$ok( 1 === count( $r['en_grupo'] ) && ! $r['en_grupo'][0]['activa'] && 0 === $r['otros'], 'una ficha del grupo sin membresía activa y escrita «87654321-x» se encuentra, y no se cuenta además como «otro grupo»' );
	$r = $dup->comprobar( '11111111H' );
	$ok( ! $r['en_grupo'] && 1 === $r['otros'], 'en otro grupo y no en el tuyo: el grupo no ve la ficha, pero el recuento federal la cuenta' );
	$ok( 1 === $dup->comprobar( 'X1234567L' )['otros'] && 1 === $dup->comprobar( '01234567L' )['otros'], 'también si en el CRM está escrito de otra forma (NIE con guiones, DNI sin el cero)' );
	$r = $dup->comprobar( '22222222J' );
	$ok( ! $r['en_grupo'] && 0 === $r['otros'], 'un documento que no está en ningún sitio: ni en el grupo ni en otros' );
	$ok( ! $dup->comprobar( '12345678A' )['ok'], 'un documento no válido no se busca' );
	$ultimo = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT detalles FROM %i WHERE accion = %s AND id > %d ORDER BY id DESC LIMIT 1', Logger::table(), 'crm_documento_comprobado', $log_antes ) );
	$ok( '' !== $ultimo && ! preg_match( '/22222222|12345678|11111111/', $ultimo ), 'la auditoría apunta la comprobación sin el documento' );

	$antes = $crm->llamadas;
	$dup->comprobar( '22222222J' );
	$ok( 2 === $crm->llamadas - $antes, 'el canario se guarda un rato: cada comprobación son solo dos consultas (grupo y recuento)' );
	delete_transient( $canario );
	$crm->recuento_por_grupo = true;
	$r                       = $dup->comprobar( '11111111H' );
	$ok( null === $r['otros'] && ! $r['en_grupo'], 'si el recuento deja de ser federal (cuenta lo mismo que ve el grupo), no se da por bueno: «no se ha podido comprobar»' );
	$ok( (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE accion = %s AND id > %d', Logger::table(), 'crm_recuento_no_federal', $log_antes ) ), 'y queda en la auditoría' );
	$crm->recuento_por_grupo = false;
	delete_transient( $canario );

	echo "\n== Parte C · La comprobación, solo dentro del alta (v0.23.1) ==\n";
	$ok( in_array( 'personas', Router::MODALES, true ), 'Personas se puede abrir en la ventana emergente' );
	Altas::activar( $grupo['numero'], true );
	Permisos::olvidar( $grupo['numero'] );
	$como( $u_sec );
	$h = $ver( array(), false );
	$ok( ! str_contains( $h, '¿Está ya en el CRM?' ) && ! str_contains( $h, 'comprobar=1' ) && str_contains( $h, 'Alta de socio/a' ), 'Personas ya no tiene el botón suelto «¿Está ya en el CRM?»: la comprobación va en «Alta de socio/a»' );
	$h = $ver( array( 'comprobar' => '1' ) );
	$ok( ! str_contains( $h, 'name="documento"' ) && ! str_contains( $h, 'comprobar_documento' ), 'ni la pantalla suelta de la comprobación' );
	$como( $u_sco );
	$h = $ver( array( 'alta' => '1' ) );
	$ok( ! str_contains( $h, 'alta_documento' ), 'un scouter (sin Personas) no tiene el alta ni la comprobación' );
	$como( $u_sec );
	$h = $ver( array( 'alta' => '1' ) );
	$ok( str_contains( $h, 'asde-ap--modal' ) && str_contains( $h, 'name="asde_action" value="alta_documento"' ) && str_contains( $h, 'data-asde-solo-consulta' ) && str_contains( $h, 'name="_wpnonce"' ), 'Secretaría abre el alta en la ventana: el primer paso es el documento (firmado y de solo consulta)' );
	$ok( str_contains( $h, 'a partir de los 14 años' ), 'recuerda que el documento es obligatorio a partir de los 14 años' );

	$h = $comprobar( '12345678-Z' );
	$ok( str_contains( $h, 'Ya tiene ficha en tu grupo: no se crea otra.' ) && str_contains( $h, 'Lia Altas Roig' ) && str_contains( $h, 'membresía activa: Scouts' ) && str_contains( $h, 'no hay que crear otra ficha ni otra membresía' ) && ! str_contains( $h, 'alta_revisar' ), 'con el documento de alguien del grupo con membresía activa: el alta se para y enseña su ficha (enlazada)' );
	$ok( str_contains( $h, 'socio=990051' ), 'y lleva a su ficha' );
	$h = $comprobar( '87654321x' );
	$ok( str_contains( $h, 'Marc Altas Pons' ) && str_contains( $h, 'sin membresía activa' ) && str_contains( $h, 'Darle una membresía nueva' ) && str_contains( $h, 'solo necesita una membresía nueva' ), 'quien vuelve: su ficha sin membresía activa y «Darle una membresía nueva»' );
	$h = $comprobar( '11111111H' );
	$ok( str_contains( $h, 'ya está dada de alta en otro grupo u organización' ) && str_contains( $h, 'se puede dar de alta también en tu grupo' ) && str_contains( $h, 'value="alta_revisar"' ), 'solo en otro grupo: el aviso, y el alta sigue con los datos' );
	$h = $comprobar( '22222222J' );
	$ok( str_contains( $h, 'value="alta_revisar"' ) && str_contains( $h, 'value="22222222J"' ) && ! str_contains( $h, 'otro grupo u organización de Scouts de España.' ), 'en ningún sitio: el alta sigue con los datos, sin aviso' );
	$h = $comprobar( '12345678A' );
	$ok( str_contains( $h, 'asde-notice--error' ) && str_contains( $h, 'letra del DNI' ), 'un documento mal escrito: el error, sin buscar' );
	$ok( 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_value LIKE '%12345678Z%' OR option_value LIKE '%11111111H%'" ), 'el documento no se guarda en ninguna opción ni transient' ); // phpcs:ignore WordPress.DB

	set_transient( 'asde_ap_comprobaciones_' . $u_sec, Duplicados::MAX_POR_HORA, HOUR_IN_SECONDS );
	$antes = $crm->llamadas;
	$h     = $comprobar( '22222222J' );
	$ok( str_contains( $h, 'muchas comprobaciones seguidas' ) && $antes === $crm->llamadas, 'con muchas comprobaciones en una hora se para (y no se pregunta al CRM)' );
	delete_transient( 'asde_ap_comprobaciones_' . $u_sec );

	$ok( ! $crm->escrituras, 'nada escrito en el CRM (simulado)' );
	$ok( 0 === $cortadas, 'ninguna petición ha salido hacia un CRM real' );
} finally {
	// Limpieza: todo lo creado fuera; lo tocado, como estaba.
	$_POST    = array();
	$_REQUEST = array();
	$_GET     = array();
	View::en_modal( false );
	delete_transient( $canario );
	$crm->desactivar();
	remove_filter( 'pre_http_request', $cortar_crm, 2 );
	null === $altas_antes ? delete_option( Altas::OPCION ) : update_option( Altas::OPCION, $altas_antes, false );
	Permisos::olvidar( $grupo['numero'] );
	foreach ( $ids as $uid ) {
		delete_transient( 'asde_ap_comprobaciones_' . $uid );
	}
	wp_using_ext_object_cache( $cache_externa );
	wp_set_current_user( 0 );

	$lista = implode( ',', array_map( 'intval', $ids ) ) ?: '0';
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Logger::table() . " WHERE id > %d AND ( user_id IN ($lista) OR accion IN ('crm_documento_comprobado', 'crm_recuento_no_federal') )", $log_antes ) ); // phpcs:ignore WordPress.DB
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

	$quedan = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users} WHERE user_login LIKE 'prueba-altas-%'" ); // phpcs:ignore WordPress.DB
	$ok( 0 === $quedan, 'limpieza: sin usuarios de la prueba' );
}

echo $fails ? "\n$fails FALLOS\n" : "\nTODO OK (nada tocado en el CRM real; el sitio queda como estaba)\n";
