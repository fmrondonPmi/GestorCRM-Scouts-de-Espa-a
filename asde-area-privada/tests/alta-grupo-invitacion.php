<?php
/**
 * Pruebas de la v0.50 (entorno LOCAL, doc 50, fase 5): invitación de grupo y formulario público.
 * Ejecutar desde app/public del site Local:
 *
 *   wp eval-file <ruta completa>/wp-content/plugins/asde-area-privada/tests/alta-grupo-invitacion.php
 *
 * No toca el CRM real (la comprobación usa un cliente simulado) ni envía emails. Restaura todo al terminar.
 */
defined( 'ABSPATH' ) || exit;

use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Crm\ConnectionResult;
use AsdeBalears\AreaPrivada\Crm\Crypto;
use AsdeBalears\AreaPrivada\Crm\Settings;
use AsdeBalears\AreaPrivada\Domain\GrupoInvitaciones;
use AsdeBalears\AreaPrivada\Domain\GrupoSolicitudes;
use AsdeBalears\AreaPrivada\Domain\Grupos;
use AsdeBalears\AreaPrivada\Domain\Organizacion;
use AsdeBalears\AreaPrivada\Frontend\Pages;
use AsdeBalears\AreaPrivada\Frontend\Views\AltaGrupoView;
use AsdeBalears\AreaPrivada\Usuarios\RegistroEmails;

global $wpdb;

$fails = 0;
$ok    = function ( $cond, string $label ) use ( &$fails ) {
	if ( ! $cond ) {
		++$fails;
	}
	echo ( $cond ? 'OK   ' : 'FAIL ' ), $label, "\n";
};

/** CRM simulado de la comprobación: acepta el usuario «of-ok» con la contraseña «Clave Secreta!1» y le deja ver el grupo 901. */
final class Asde_Prueba_Cliente_Grupo {
	public function __construct( private string $usuario, private string $clave ) {}

	public function ping(): ConnectionResult {
		return 'of-ok' === $this->usuario && 'Clave Secreta!1' === $this->clave ? new ConnectionResult( true, 'ok' ) : ConnectionResult::fail( 'no' );
	}

	public function list_entries( string $modulo, string $consulta, array $campos, array $orden, int $desde, int $max ): array {
		return array(
			'entries' => array(
				array(
					'id'     => 'sg-901',
					'fields' => array( 'name' => 'Grupo 901', 'numgrupo_c' => '901', 'tipoasociacion_c' => 'GS' ),
				),
			),
		);
	}
}

$max_id = static fn( string $t ) => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(MAX(id), 0) FROM %i', $t ) ); // phpcs:ignore WordPress.DB
$inv0   = $max_id( GrupoInvitaciones::table() );
$sol0   = $max_id( GrupoSolicitudes::table() );
$log0   = $max_id( Logger::table() );
$mail0  = $max_id( RegistroEmails::table() );
$tipo0  = get_option( Organizacion::TIPO_OPCION, null );
$actual = get_current_user_id();

$url_falsa  = static fn() => array( 'url' => 'https://crm.example.invalid' );
$cliente    = static fn( $c, $url, $usuario, $clave ) => new Asde_Prueba_Cliente_Grupo( $usuario, $clave );
$entorno    = static fn() => 'local';
$sin_espera = '__return_zero';
$capturados = array();
$captura    = static function ( $pre, $atts ) use ( &$capturados ) {
	$capturados[] = $atts;
	return true;
};
add_filter( 'pre_option_' . Settings::OPTION, $url_falsa );
add_filter( 'asde_ap_alta_grupo_cliente', $cliente, 10, 4 );
add_filter( 'wp_get_environment_type', $entorno );
add_filter( 'asde_ap_solicitud_segundos_minimos', $sin_espera );
add_filter( 'pre_wp_mail', $captura, 10, 2 );

$limpiar_transients = static function () use ( $wpdb ) {
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_asde\\_ap\\_agv\\_%' OR option_name LIKE '\\_transient\\_timeout\\_asde\\_ap\\_agv\\_%' OR option_name LIKE '\\_transient\\_asde\\_ap\\_lim\\_%' OR option_name LIKE '\\_transient\\_timeout\\_asde\\_ap\\_lim\\_%'" ); // phpcs:ignore WordPress.DB
};

/** Pinta la página (GET) como visitante. */
$ver = static function ( string $token ) {
	AltaGrupoView::olvidar();
	$_GET     = array( 't' => $token );
	$_POST    = array();
	$_REQUEST = $_GET;
	return ( new AltaGrupoView() )->render();
};
/** Envía un formulario (POST) y pinta la respuesta. */
$enviar = static function ( string $token, array $campos ) {
	AltaGrupoView::olvidar();
	$_GET     = array();
	$_POST    = array_merge( array( 't' => $token, '_wpnonce' => wp_create_nonce( 'asde_alta_grupo' ) ), $campos );
	$_REQUEST = $_POST;
	$v        = new AltaGrupoView();
	$v->handle_post();
	return $v->render();
};
$sello_de = static function ( string $html ): string {
	return preg_match( '/name="sello" value="([^"]+)"/', $html, $m ) ? $m[1] : '';
};

try {
	wp_set_current_user( 0 );
	Organizacion::guardar_tipo( Organizacion::TIPO_OFICINA );
	$limpiar_transients();

	echo "== A. Invitaciones ==\n";
	$a = GrupoInvitaciones::crear( 'grupo@example.invalid' );
	$ok( 48 === strlen( $a['token'] ) && str_contains( $a['url'], 't=' . $a['token'] ) && str_contains( $a['url'], '/alta-grupo' ), 'Se crea un enlace con token de 192 bits en la página «alta-grupo»' );
	$fila = GrupoInvitaciones::obtener( $a['id'] );
	$ok( $fila && ! str_contains( (string) wp_json_encode( $fila ), $a['token'] ) && 64 === strlen( $fila['hash'] ), 'En la base de datos solo queda el hash, nunca el token' );
	$ok( GrupoInvitaciones::vigente( $a['token'] ) && null === GrupoInvitaciones::vigente( str_repeat( '0', 48 ) ) && null === GrupoInvitaciones::vigente( 'corto' ), 'Solo vale el token verdadero' );
	$ok( 'pendiente' === GrupoInvitaciones::estado_de( $fila ) && strtotime( $fila['expira'] . ' UTC' ) - time() > 13 * DAY_IN_SECONDS, 'Nace pendiente y caduca en 14 días' );
	$ok( in_array( 'alta_grupo', Pages::PUBLICAS, true ), 'La página es pública' );

	echo "\n== B. Enlace que no vale: siempre la misma página ==\n";
	$malo       = $ver( str_repeat( 'a', 48 ) );
	$sin_token  = $ver( '' );
	$anulada    = GrupoInvitaciones::crear();
	GrupoInvitaciones::anular( $anulada['id'] );
	$caducada   = GrupoInvitaciones::crear();
	$wpdb->update( GrupoInvitaciones::table(), array( 'expira' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ), array( 'id' => $caducada['id'] ) ); // phpcs:ignore WordPress.DB
	$ok( str_contains( $malo, 'no es válido' ) && $malo === $sin_token, 'Un token falso y la falta de token dan la misma página' );
	$ok( $malo === $ver( $anulada['token'] ) && $malo === $ver( $caducada['token'] ), 'Anulada y caducada dan exactamente la misma página: no se revela el motivo' );
	$ok( 'caducada' === GrupoInvitaciones::estado_de( GrupoInvitaciones::obtener( $caducada['id'] ) ) && 'anulada' === GrupoInvitaciones::estado_de( GrupoInvitaciones::obtener( $anulada['id'] ) ), 'La lista sí distingue caducada y anulada' );
	Organizacion::guardar_tipo( Organizacion::TIPO_GRUPO );
	$ok( $malo === $ver( $a['token'] ), 'En una web de grupo (no de oficina) el enlace tampoco vale' );
	Organizacion::guardar_tipo( Organizacion::TIPO_OFICINA );

	echo "\n== C. Formulario y comprobación del acceso ==\n";
	$h = $ver( $a['token'] );
	$ok( str_contains( $h, 'Comprobar acceso' ) && str_contains( $h, 'name="web"' ) && str_contains( $h, 'name="sello"' ) && str_contains( $h, 'name="_wpnonce"' ) && str_contains( $h, 'value="grupo@example.invalid"' ), 'El formulario lleva campo trampa, sello, nonce y el email de la invitación' );
	$ok( 1 === preg_match( '/<button[^>]*value="alta_grupo_enviar"[^>]*disabled/', $h ), '«Enviar el alta» está desactivado hasta comprobar el acceso' );
	$base = array( 'numero' => '901', 'usuario' => 'of-ok', 'clave' => 'mala' );
	$h    = $enviar( $a['token'], array( 'asde_action' => 'alta_grupo_comprobar' ) + $base );
	$ok( str_contains( $h, 'no acepta' ) && GrupoInvitaciones::vigente( $a['token'] ), 'Credencial incorrecta: error y la invitación no se gasta' );
	$h = $enviar( $a['token'], array( 'asde_action' => 'alta_grupo_comprobar', 'numero' => '902', 'usuario' => 'of-ok', 'clave' => 'Clave Secreta!1' ) );
	$ok( str_contains( $h, 'no ve ese Grupo Scout' ), 'Si el usuario no ve ese nº de grupo en el CRM, se rechaza' );
	$h = $enviar( $a['token'], array( 'asde_action' => 'alta_grupo_comprobar', 'numero' => '901', 'usuario' => 'of-ok', 'clave' => 'Clave Secreta!1' ) );
	$ok( str_contains( $h, 'Acceso comprobado' ), 'Credencial correcta: acceso comprobado' );
	$ok( ! str_contains( $h, 'Clave Secreta!1' ) && ! str_contains( $h, 'Clave+Secreta' ) && ! str_contains( $h, 'name="clave"' ), 'La contraseña no vuelve en el HTML ni en un campo' );
	$guardado = (string) wp_json_encode( $wpdb->get_results( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE '%asde\\_ap\\_agv\\_%'", ARRAY_A ) ); // phpcs:ignore WordPress.DB
	$ok( '' !== $guardado && ! str_contains( $guardado, 'Clave Secreta' ), 'Lo comprobado se guarda cifrado y con caducidad' );

	echo "\n== D. Envío ==\n";
	$ok( null === Grupos::por_numero( '901' ), 'Control: no existe ya un grupo 901 en el sitio' );
	$campos = array(
		'asde_action'  => 'alta_grupo_enviar',
		'numero'       => '901',
		'nombre'       => 'Grupo de prueba 901',
		'sede'         => 'Calle Inventada 1',
		'email'        => 'grupo901@example.invalid',
		'cif'          => 'g-12345678',
		'denominacion' => 'Asociación Inventada',
		'persona'      => 'Persona Inventada',
		'dni'          => '00000000t',
		'rol'          => 'secretaria',
		'web'          => '',
	);
	$h       = $ver( $a['token'] );
	$sello   = $sello_de( $h );
	$trampa  = $enviar( $a['token'], array_merge( $campos, array( 'sello' => $sello, 'web' => 'http://spam' ) ) );
	$ok( str_contains( $trampa, 'Recibido' ) && 0 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE id > %d', GrupoSolicitudes::table(), $sol0 ) ), 'Campo trampa lleno: se contesta como a todos pero no se crea nada' ); // phpcs:ignore WordPress.DB
	$ok( null !== GrupoInvitaciones::vigente( $a['token'] ), 'Y la invitación no se gasta' );
	$h = $enviar( $a['token'], array_merge( $campos, array( 'sello' => $sello, 'dni' => '' ) ) );
	$ok( str_contains( $h, 'primera persona' ) && GrupoInvitaciones::vigente( $a['token'] ), 'Faltan datos de la primera persona: error y no se gasta' );
	$h = $enviar( $a['token'], array_merge( $campos, array( 'sello' => $sello, 'numero' => '684' ) ) );
	$ok( str_contains( $h, 'ya está dado de alta' ) || str_contains( $h, 'Comprueba primero' ), 'Un nº de grupo que ya existe (o sin comprobar) se rechaza' );
	$h = $enviar( $a['token'], array_merge( $campos, array( 'sello' => $sello, 'numero' => '903' ) ) );
	$ok( str_contains( $h, 'Comprueba primero' ), 'Cambiar el nº de grupo después de comprobar obliga a comprobar otra vez' );
	$h = $enviar( $a['token'], array_merge( $campos, array( 'sello' => $sello ) ) );
	$ok( str_contains( $h, 'Recibido' ), 'Envío correcto: recibido' );
	$ok( null === GrupoInvitaciones::vigente( $a['token'] ) && 'usada' === GrupoInvitaciones::obtener( $a['id'] )['estado'], 'La invitación queda gastada' );
	$sol = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE id > %d', GrupoSolicitudes::table(), $sol0 ), ARRAY_A ); // phpcs:ignore WordPress.DB
	$ok( 1 === count( $sol ) && '901' === $sol[0]['numero'] && 'sg-901' === $sol[0]['crm_id'] && 'pendiente' === $sol[0]['estado'] && 'G12345678' === $sol[0]['cif'], 'Se crea una única solicitud pendiente, con el id del grupo en el CRM' );
	$crudo = (string) wp_json_encode( $sol[0] );
	$ok( ! str_contains( $crudo, 'Clave Secreta' ) && ! str_contains( $crudo, 'of-ok' ) && ! str_contains( $crudo, '00000000T' ) && ! str_contains( $crudo, 'Persona Inventada' ), 'Usuario, contraseña, DNI y nombre de la persona están cifrados en la tabla' );
	$ok( 'Clave Secreta!1' === Crypto::decrypt( $sol[0]['clave_enc'] ) && '00000000T' === Crypto::decrypt( $sol[0]['dni_enc'] ), 'Y se pueden descifrar' );
	$lim = GrupoSolicitudes::lista()[0] ?? array();
	$ok( ! str_contains( (string) wp_json_encode( $lim ), 'Clave Secreta' ) && '1' === $lim['clave_enc'], 'La lista nunca saca los secretos' );
	$h = $enviar( $a['token'], array_merge( $campos, array( 'sello' => $sello ) ) );
	$ok( str_contains( $h, 'no es válido' ) && 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE id > %d', GrupoSolicitudes::table(), $sol0 ) ), 'Un segundo envío con el mismo enlace no crea otra solicitud' ); // phpcs:ignore WordPress.DB
	$ok( true === GrupoSolicitudes::pendiente_con_numero( '901' ), 'El nº queda como pendiente: otro grupo no puede pedirlo' );

	echo "\n== E. Un solo gasto aunque lleguen dos envíos ==\n";
	$b = GrupoInvitaciones::crear();
	$ok( true === GrupoInvitaciones::consumir( $b['id'] ) && false === GrupoInvitaciones::consumir( $b['id'] ), 'consumir() solo lo consigue el primero' );
	GrupoInvitaciones::devolver( $b['id'] );
	$ok( null !== GrupoInvitaciones::vigente( $b['token'] ), 'devolver() deja la invitación como estaba si no se pudo guardar la solicitud' );

	echo "\n== F. Email y pantalla de la oficina ==\n";
	$capturados = array();
	$c          = GrupoInvitaciones::crear();
	$err        = GrupoInvitaciones::enviar_por_email( 'destino@example.invalid', $c['url'] );
	$ok( '' === $err && 1 === count( $capturados ) && str_contains( (string) $capturados[0]['message'], $c['url'] ) && str_contains( (string) $capturados[0]['message'], '14' ), 'El email lleva el enlace y los días de validez' );
	$reg = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE id > %d', RegistroEmails::table(), $mail0 ), ARRAY_A ); // phpcs:ignore WordPress.DB
	$ok( $reg && ! str_contains( (string) wp_json_encode( $reg ), $c['token'] ) && ! str_contains( (string) wp_json_encode( $reg ), 'destino@example' ), 'El registro de emails no guarda el enlace ni el destino en claro' );
	$ok( true === GrupoInvitaciones::anular( $c['id'] ) && false === GrupoInvitaciones::anular( $c['id'] ), 'Se anula una pendiente y no se vuelve a anular' );
	GrupoInvitaciones::recordar_para_mostrar( 'https://x.example/alta-grupo/?t=abc' );
	$ok( 'https://x.example/alta-grupo/?t=abc' === GrupoInvitaciones::tomar_para_mostrar() && '' === GrupoInvitaciones::tomar_para_mostrar(), 'El enlace se enseña una sola vez' );
} finally {
	remove_filter( 'pre_option_' . Settings::OPTION, $url_falsa );
	remove_filter( 'asde_ap_alta_grupo_cliente', $cliente, 10 );
	remove_filter( 'wp_get_environment_type', $entorno );
	remove_filter( 'asde_ap_solicitud_segundos_minimos', $sin_espera );
	remove_filter( 'pre_wp_mail', $captura, 10 );
	$_GET     = array();
	$_POST    = array();
	$_REQUEST = array();
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id > %d', GrupoInvitaciones::table(), $inv0 ) ); // phpcs:ignore WordPress.DB
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id > %d', GrupoSolicitudes::table(), $sol0 ) ); // phpcs:ignore WordPress.DB
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id > %d', Logger::table(), $log0 ) ); // phpcs:ignore WordPress.DB
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id > %d', RegistroEmails::table(), $mail0 ) ); // phpcs:ignore WordPress.DB
	$limpiar_transients();
	if ( null === $tipo0 ) {
		delete_option( Organizacion::TIPO_OPCION );
	} else {
		update_option( Organizacion::TIPO_OPCION, $tipo0, false );
	}
	wp_set_current_user( $actual );
}

echo $fails ? "\nHAY $fails FALLOS\n" : "\nTODO OK\n";
