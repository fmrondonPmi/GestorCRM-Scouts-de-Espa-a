<?php
/**
 * Pruebas de la activación de cuentas y de la recuperación de la contraseña (entorno LOCAL; doc 60): el enlace de un
 * solo uso de Usuarios\Invitacion y la página pública Frontend\Views\ActivarCuentaView. Ejecutar desde app/public:
 *
 *   wp eval-file wp-content/plugins/asde-area-privada/tests/activar-cuenta.php
 *
 * (o, desde herramientas-desarrollo: ./probar-todo.sh activar-cuenta)
 *
 * - A: el email lleva el enlace con el token y en la base de datos solo se guarda su HMAC.
 * - B: validar(): token correcto, de otro usuario, mal formado, caducado.
 * - C: la página sin enlace, con enlace válido y con sesión iniciada.
 * - D: elegir la contraseña: nonce, longitud, repetición, sin el nº de socio/a; la buena activa la cuenta, deja
 *   constancia en el registro y el enlace ya no vale (un solo uso).
 * - E: recuperación (reset): una cuenta desactivada sigue desactivada y el registro dice «contraseña cambiada».
 * Usa el CRM simulado (fichas inventadas, emails @example.invalid), captura los emails (no sale ninguno), guarda las
 * cachés solo en memoria y al terminar borra sus usuarios, sus emails registrados y su parte del registro.
 */
defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/lib/CrmSimulado.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Domain\Grupo;
use AsdeBalears\AreaPrivada\Domain\Grupos;
use AsdeBalears\AreaPrivada\Frontend\Views\ActivarCuentaView;
use AsdeBalears\AreaPrivada\Install\Roles;
use AsdeBalears\AreaPrivada\Usuarios\Invitacion;
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
foreach ( get_users( array( 'search' => 'prueba-act-*', 'search_columns' => array( 'user_login' ) ) ) as $u ) {
	exit( 'Ya existe el usuario ' . $u->user_login . " (¿una ejecución anterior interrumpida?). Bórralo antes de repetir la prueba.\n" );
}
wp_set_current_user( 0 );

// --- Lo que se restaura al final ---------------------------------------------
$max_id       = static fn( string $t ) => (int) $wpdb->get_var( "SELECT COALESCE(MAX(id), 0) FROM $t" ); // phpcs:ignore WordPress.DB
$log_antes    = $max_id( Logger::table() );
$emails_antes = $max_id( RegistroEmails::table() );

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
$capturados = array();
$captura    = static function ( $pre, $atts ) use ( &$capturados ) {
	$capturados[] = $atts;
	return true;
};
add_filter( 'pre_wp_mail', $captura, 10, 2 );
add_filter( 'send_auth_cookies', '__return_false' );
$redirigir = static function ( $destino ) {
	throw new RuntimeException( 'asde-redirect:' . $destino );
};
add_filter( 'wp_redirect', $redirigir, 1 );

$P   = array( 'Asde_Prueba_Crm', 'persona' );
$crm = new Asde_Prueba_Crm(
	array(
		$P( 10, 'Iris', 'Activa Uno', '1990-01-01', 'Scouters' ),
		$P( 11, 'Joel', 'Activa Dos', '1991-01-01', 'Scouters' ),
	),
	$grupo['crm_id']
);
Grupo::forzar( $grupo );
$crm->activar();

$ids   = array();
$crear = static function ( string $login, string $socio, string $estado ) use ( &$ids, $grupo ): int {
	$id = wp_insert_user(
		array(
			'user_login' => $login,
			'user_email' => $login . '@example.invalid',
			'user_pass'  => wp_generate_password( 24 ),
			'role'       => Roles::SCOUTER,
		)
	);
	update_user_meta( $id, Usuarios::META_GRUPO, $grupo['id'] );
	update_user_meta( $id, Usuarios::META_ESTADO, $estado );
	update_user_meta( $id, Usuarios::META_SOCIO, $socio );
	$ids[ $login ] = $id;
	return $id;
};
$u_uno = $crear( 'prueba-act-uno', '990010', Usuarios::INVITADO );
$u_dos = $crear( 'prueba-act-dos', '990011', Usuarios::DESACTIVADO );

/** El token del último email capturado para ese usuario (solo viaja en el email). */
$token_de = static function ( int $uid ) use ( &$capturados ): string {
	foreach ( array_reverse( $capturados ) as $c ) {
		if ( preg_match( '~[?&](?:amp;)?u=' . $uid . '(?:&|&amp;)t=([0-9a-f]{48})~', (string) $c['message'], $m ) ) {
			return $m[1];
		}
	}
	return '';
};
$ver = static function ( array $get ): string {
	$_GET = $get;
	try {
		return ( new ActivarCuentaView() )->render();
	} finally {
		$_GET = array();
	}
};
/** Envía el formulario como el navegador; devuelve [redirigido, html de la página en la misma petición]. */
$enviar = static function ( int $uid, string $token, string $p1, string $p2, ?string $nonce = null ): array {
	$_POST    = array(
		'asde_action' => 'activar',
		'u'           => (string) $uid,
		't'           => $token,
		'pass1'       => $p1,
		'pass2'       => $p2,
		'_wpnonce'    => $nonce ?? wp_create_nonce( 'asde_activar_' . $uid ),
	);
	$_REQUEST = $_POST;
	$destino  = '';
	try {
		( new ActivarCuentaView() )->handle_post();
		$html = ( new ActivarCuentaView() )->render();
	} catch ( RuntimeException $e ) {
		$destino = str_starts_with( $e->getMessage(), 'asde-redirect:' ) ? $e->getMessage() : '';
		$html    = '';
		if ( '' === $destino ) {
			throw $e;
		}
	} finally {
		$_POST    = array();
		$_REQUEST = array();
	}
	return array( $destino, $html );
};
$hash_guardado = static fn( int $uid ): string => (string) get_user_meta( $uid, 'asde_invitacion_hash', true );

try {
	echo "== Parte A · El enlace va en el email; en la base de datos, solo su HMAC ==\n";
	$ok( true === Invitacion::enviar( get_userdata( $u_uno ), Invitacion::ALTA ), 'se envía la invitación de alta' );
	$mail  = end( $capturados );
	$token = $token_de( $u_uno );
	$ok( is_array( $mail ) && 'prueba-act-uno@example.invalid' === $mail['to'], 'al email de la cuenta (el capturado, no sale ninguno)' );
	$ok( 48 === strlen( $token ), 'el email lleva el enlace de activar con u= y un token de 48 cifras hexadecimales' );
	$ok( '' !== $hash_guardado( $u_uno ) && $token !== $hash_guardado( $u_uno ) && 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_value LIKE %s", '%' . $wpdb->esc_like( $token ) . '%' ) ), 'en la base de datos no está el token, solo su HMAC' ); // phpcs:ignore WordPress.DB
	$exp = Invitacion::expira( $u_uno );
	$ok( null !== $exp && abs( $exp - ( time() + Invitacion::DIAS * DAY_IN_SECONDS ) ) < 120, 'la invitación de alta caduca a los ' . Invitacion::DIAS . ' días' );
	$fila = (array) $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id > %d AND user_id = %d ORDER BY id DESC LIMIT 1', RegistroEmails::table(), $emails_antes, $u_uno ), ARRAY_A ); // phpcs:ignore WordPress.DB
	$ok( $fila && RegistroEmails::ENVIADO === $fila['estado'] && ! str_contains( (string) $fila['destino'], 'prueba-act-uno@' ) && ! str_contains( wp_json_encode( $fila ), $token ), 'queda en el registro de emails con el destino enmascarado y sin el enlace' );

	echo "\n== Parte B · validar() ==\n";
	$ok( Invitacion::validar( $u_uno, $token ) instanceof WP_User && $u_uno === Invitacion::validar( $u_uno, $token )->ID, 'el token correcto da la cuenta' );
	$ok( null === Invitacion::validar( $u_dos, $token ), 'el mismo token con otro usuario no vale' );
	$ok( null === Invitacion::validar( $u_uno, strrev( $token ) ), 'otro token del mismo formato no vale' );
	$ok( null === Invitacion::validar( $u_uno, strtoupper( $token ) ) && null === Invitacion::validar( $u_uno, $token . '0' ) && null === Invitacion::validar( $u_uno, '' ), 'un token mal formado (mayúsculas, largo, vacío) no vale' );
	$ok( null === Invitacion::validar( 999999999, $token ), 'un usuario que no existe no vale' );
	$expira = (int) get_user_meta( $u_uno, 'asde_invitacion_expira', true );
	update_user_meta( $u_uno, 'asde_invitacion_expira', time() - 1 );
	$ok( null === Invitacion::validar( $u_uno, $token ), 'caducado no vale' );
	update_user_meta( $u_uno, 'asde_invitacion_expira', $expira );

	echo "\n== Parte C · La página ==\n";
	$h = $ver( array() );
	$ok( str_contains( $h, 'Enlace no válido' ) && ! str_contains( $h, 'name="pass1"' ), 'sin enlace: «Enlace no válido», sin formulario' );
	$h = $ver( array( 'u' => (string) $u_dos, 't' => $token ) );
	$ok( str_contains( $h, 'Enlace no válido' ) && ! str_contains( $h, 'prueba-act-dos' ) && ! str_contains( $h, 'prueba-act-uno' ), 'con el token de otro: el mismo «no válido», sin decir de quién es ninguna cuenta' );
	$h = $ver( array( 'u' => (string) $u_uno, 't' => $token ) );
	$ok( str_contains( $h, 'name="pass1"' ) && str_contains( $h, 'Activar mi cuenta' ) && str_contains( $h, 'prueba-act-uno' ) && str_contains( $h, 'name="_wpnonce"' ), 'con el enlace bueno: el formulario de activar, con su usuario y su nonce' );
	wp_set_current_user( $u_dos );
	$h = $ver( array( 'u' => (string) $u_uno, 't' => $token ) );
	wp_set_current_user( 0 );
	$ok( str_contains( $h, 'Ya has iniciado sesión' ) && ! str_contains( $h, 'name="pass1"' ), 'con una sesión iniciada no se enseña el formulario' );

	echo "\n== Parte D · Elegir la contraseña ==\n";
	$pass_antes = get_userdata( $u_uno )->user_pass;
	list( $dest, $h ) = $enviar( $u_uno, $token, 'Una frase larga 2026', 'Una frase larga 2026', 'nonce-falso' );
	$ok( '' === $dest && str_contains( $h, 'La sesión del formulario ha caducado' ), 'nonce falso: error y nada cambia' );
	list( $dest, $h ) = $enviar( $u_uno, $token, 'corta', 'corta' );
	$ok( '' === $dest && str_contains( $h, 'al menos ' . ActivarCuentaView::LONGITUD_MINIMA . ' caracteres' ), 'contraseña corta: error' );
	list( $dest, $h ) = $enviar( $u_uno, $token, 'Una frase larga 2026', 'Otra frase larga 2026' );
	$ok( '' === $dest && str_contains( $h, 'no coinciden' ), 'las dos no coinciden: error' );
	list( $dest, $h ) = $enviar( $u_uno, $token, 'xxprueba-act-uno2026', 'xxprueba-act-uno2026' );
	$ok( '' === $dest && str_contains( $h, 'no puede contener tu nº de socio/a' ), 'con el usuario dentro: error' );
	$ok( $pass_antes === get_userdata( $u_uno )->user_pass && Usuarios::INVITADO === get_user_meta( $u_uno, Usuarios::META_ESTADO, true ) && '' !== $hash_guardado( $u_uno ), 'tras los errores la contraseña y el estado no han cambiado y el enlace sigue valiendo' );
	list( $dest ) = $enviar( $u_uno, $token, 'Una frase larga 2026', 'Una frase larga 2026' );
	$cuenta = get_userdata( $u_uno );
	$ok( str_contains( $dest, 'asde_activada=1' ), 'la buena redirige a entrar con «cuenta activada»' );
	$ok( wp_check_password( 'Una frase larga 2026', $cuenta->user_pass, $u_uno ), 'la contraseña nueva queda puesta' );
	$ok( Usuarios::ACTIVO === get_user_meta( $u_uno, Usuarios::META_ESTADO, true ) && '' !== (string) get_user_meta( $u_uno, Usuarios::META_ACTIVADA_EN, true ), 'la cuenta queda activa y con su fecha de activación' );
	$ok( null === Invitacion::validar( $u_uno, $token ) && '' === $hash_guardado( $u_uno ) && null === Invitacion::expira( $u_uno ), 'el enlace se consume: ya no vale (un solo uso)' );
	$ok( 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE id > %d AND accion = %s AND objeto_id = %s', Logger::table(), $log_antes, 'cuenta_activada', (string) $u_uno ) ), 'queda en el registro «cuenta activada»' ); // phpcs:ignore WordPress.DB
	list( $dest, $h ) = $enviar( $u_uno, $token, 'Otra frase larga 2027', 'Otra frase larga 2027' );
	$ok( '' === $dest && str_contains( $h, 'Enlace no válido' ) && wp_check_password( 'Una frase larga 2026', get_userdata( $u_uno )->user_pass, $u_uno ), 'el mismo enlace otra vez: «no válido» y la contraseña no cambia' );

	echo "\n== Parte E · Recuperación de una cuenta desactivada ==\n";
	$ok( true === Invitacion::enviar( get_userdata( $u_dos ), Invitacion::RESET ), 'se envía el enlace para elegir contraseña' );
	$t2 = $token_de( $u_dos );
	$ok( 48 === strlen( $t2 ) && Invitacion::RESET === Invitacion::tipo( $u_dos ) && null === Invitacion::expira( $u_dos ), 'es de tipo recuperación (que no cuenta como invitación pendiente)' );
	$caduca = (int) get_user_meta( $u_dos, 'asde_invitacion_expira', true );
	$ok( abs( $caduca - ( time() + Invitacion::DIAS_RESET * DAY_IN_SECONDS ) ) < 120, 'y caduca en ' . Invitacion::DIAS_RESET . ' día' );
	$h = $ver( array( 'u' => (string) $u_dos, 't' => $t2 ) );
	$ok( str_contains( $h, 'Guardar contraseña' ) && ! str_contains( $h, 'Activar mi cuenta' ), 'la página dice «Guardar contraseña»' );
	list( $dest ) = $enviar( $u_dos, $t2, 'Frase de recuperar 2026', 'Frase de recuperar 2026' );
	$ok( '' !== $dest && wp_check_password( 'Frase de recuperar 2026', get_userdata( $u_dos )->user_pass, $u_dos ), 'se cambia la contraseña' );
	$ok( Usuarios::DESACTIVADO === get_user_meta( $u_dos, Usuarios::META_ESTADO, true ), 'pero una cuenta desactivada sigue desactivada' );
	$ok( 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE id > %d AND accion = %s AND objeto_id = %s', Logger::table(), $log_antes, 'contrasena_cambiada', (string) $u_dos ) ), 'y el registro dice «contraseña cambiada»' ); // phpcs:ignore WordPress.DB
	$ok( 0 === $cortadas, 'ninguna consulta ha salido hacia el CRM real' );
} catch ( Throwable $e ) {
	++$fails;
	echo 'FAIL excepcion: ', $e->getMessage(), ' (', basename( $e->getFile() ), ':', $e->getLine(), ")\n";
} finally {
	$crm->desactivar();
	remove_filter( 'pre_http_request', $cortar_crm, 2 );
	remove_filter( 'pre_wp_mail', $captura, 10 );
	remove_filter( 'send_auth_cookies', '__return_false' );
	remove_filter( 'wp_redirect', $redirigir, 1 );
	$_GET  = array();
	$_POST = array();
	wp_set_current_user( 0 );

	$lista = implode( ',', array_map( 'intval', $ids ) ) ?: '0';
	$wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE id > %d AND ( user_id IN ($lista) OR ( objeto = 'user' AND objeto_id IN ($lista) ) )", Logger::table(), $log_antes ) ); // phpcs:ignore WordPress.DB
	$wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE id > %d AND user_id IN ($lista)", RegistroEmails::table(), $emails_antes ) ); // phpcs:ignore WordPress.DB
	foreach ( $ids as $uid ) {
		wp_delete_user( (int) $uid );
	}
	wp_using_ext_object_cache( $cache_externa );
	Grupo::forzar( null );

	$quedan = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users} WHERE user_login LIKE 'prueba-act-%'" ); // phpcs:ignore WordPress.DB
	$emails = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE id > %d AND user_id IN ($lista)", RegistroEmails::table(), $emails_antes ) ); // phpcs:ignore WordPress.DB
	$ok( 0 === $quedan && 0 === $emails, 'limpieza: sin usuarios ni emails registrados de la prueba' );
}

echo $fails ? "\n{$fails} FALLOS\n" : "\nTODO OK (nada tocado en el CRM real; el sitio queda como estaba)\n";
