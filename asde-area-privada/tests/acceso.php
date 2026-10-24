<?php
/**
 * Pruebas del usuario de acceso: nº de socio/a o email (v0.9-v0.10, entorno LOCAL).
 * Ejecutar desde app/public del site Local:
 *
 *   wp eval-file <ruta completa>/wp-content/plugins/asde-area-privada/tests/acceso.php
 *
 * Las cuentas de prueba se crean como las de antes de la v0.10 (sin el email
 * guardado): la primera vez se busca su email en el CRM y se guarda cifrado; a
 * partir de ahí se encuentran sin el CRM. No consulta el CRM: las fichas que
 * tienen cada email se simulan con el filtro asde_ap_pre_personas_por_email y
 * cualquier otra petición al CRM se corta (pre_http_request). Ningún email sale
 * (pre_wp_mail). Los datos son inventados (@example.invalid). Crea usuarios de
 * prueba (prueba-acc-*) que se borran al terminar, junto con lo que generan:
 * registros de auditoría y de emails y los contadores de intentos.
 */
defined( 'ABSPATH' ) || exit;

use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Auth\AccessHooks;
use AsdeBalears\AreaPrivada\Auth\Identificacion;
use AsdeBalears\AreaPrivada\Auth\Intentos;
use AsdeBalears\AreaPrivada\Domain\Grupos;
use AsdeBalears\AreaPrivada\Domain\Persona;
use AsdeBalears\AreaPrivada\Frontend\Pages;
use AsdeBalears\AreaPrivada\Frontend\Views\AccesoView;
use AsdeBalears\AreaPrivada\Frontend\Views\RecuperarView;
use AsdeBalears\AreaPrivada\Install\Roles;
use AsdeBalears\AreaPrivada\Usuarios\EmailCuenta;
use AsdeBalears\AreaPrivada\Usuarios\RegistroEmails;
use AsdeBalears\AreaPrivada\Usuarios\Usuarios;

require_once ABSPATH . 'wp-admin/includes/user.php';

global $wpdb;

$fails = 0;
$ok    = function ( $cond, string $label ) use ( &$fails ) {
	if ( ! $cond ) {
		++$fails;
	}
	echo ( $cond ? 'OK   ' : 'FAIL ' ), $label, "\n";
};

$grupo = array_values( Grupos::todos() )[0] ?? null;
if ( ! $grupo ) {
	exit( "Hace falta un grupo dado de alta en el WordPress local.\n" );
}
wp_set_current_user( 0 );
// IP de documentación (TEST-NET-1), distinta en cada ejecución: los intentos de la
// prueba no cuentan para la IP real.
$_SERVER['REMOTE_ADDR'] = '192.0.2.' . random_int( 1, 254 );
$log_antes              = (int) $wpdb->get_var( 'SELECT COALESCE(MAX(id), 0) FROM ' . Logger::table() );
$transitorios           = static fn() => $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_asde\\_ap\\_int\\_%' OR option_name LIKE '\\_transient\\_asde\\_ap\\_rec\\_%'" ); // phpcs:ignore WordPress.DB
$trans_antes            = $transitorios();
$cache_externa          = wp_using_ext_object_cache( true ); // Doc 60: contadores, avisos y cachés solo en memoria (antes borraba la instantánea real del 684).

// El CRM no se toca: toda petición a su API se corta.
$cortar_crm = static fn( $pre, $args, $url ) => str_contains( (string) $url, '/rest.php' ) ? new WP_Error( 'prueba', 'CRM cortado durante la prueba' ) : $pre;
add_filter( 'pre_http_request', $cortar_crm, 1, 3 );
add_filter( 'pre_wp_mail', '__return_true' ); // Ningún email sale; el registro de emails los anota como enviados.

// Fichas inventadas: Marta es scouter y además madre de Leo (su email es el de las dos cuentas).
$ficha  = static fn( string $asde, array $campos ) => Persona::from_crm( array( 'id' => 'prueba-' . $asde, 'fields' => array( 'numerosocio_c' => $asde, 'first_name' => 'Prueba', 'stic_relationship_type_c' => '^Scouts^' ) + $campos ) );
$fichas = array(
	$ficha( '990000101', array( 'email1' => 'marta.prueba@example.invalid' ) ),
	$ficha( '990000102', array( 'tutor1_email_c' => 'Marta.Prueba@example.invalid', 'tutor2_email_c' => 'jordi.prueba@example.invalid' ) ),
	$ficha( '990000103', array( 'email1' => 'Compartido+scouts@Example.invalid' ) ),
	$ficha( '990000104', array( 'email1' => 'baja.prueba@example.invalid' ) ),
	$ficha( '990000105', array( 'email1' => 'otro.grupo@example.invalid' ) ),
	$ficha( '990000106', array( 'email1' => 'pendiente.prueba@example.invalid' ) ),
);
$crm_caido = false;
$consultas = 0;
$simulador = static function ( $pre, string $email ) use ( &$fichas, &$crm_caido, &$consultas ) {
	++$consultas;
	return $crm_caido ? new WP_Error( 'prueba', 'CRM caído' ) : array_values( array_filter( $fichas, static fn( Persona $p ) => in_array( $email, $p->emails(), true ) ) );
};
add_filter( 'asde_ap_pre_personas_por_email', $simulador, 10, 2 );

// Cuentas de prueba (contraseñas aleatorias, solo en memoria).
$ids    = array();
$claves = array();
$crear  = static function ( string $login, string $rol, string $origen, string $grupo_id, string $estado = Usuarios::ACTIVO ) use ( &$ids, &$claves ) {
	// Sin los caracteres especiales «extra»: incluyen el espacio, y WordPress recorta la contraseña al
	// cifrarla y al entrar; una clave al azar que empezara o acabara en espacio hacía fallar la prueba de vez en cuando.
	$clave = wp_generate_password( 20, true, false );
	$id    = wp_insert_user(
		array(
			'user_login' => $login,
			'user_email' => '',
			'user_pass'  => $clave,
			'role'       => $rol,
		)
	);
	if ( is_wp_error( $id ) ) {
		exit( 'No se ha podido crear el usuario de prueba ' . $login . ': ' . $id->get_error_message() . "\n" );
	}
	list( $asde, $campo ) = explode( ':', $origen );
	Usuarios::fijar_email_origen( $id, $asde, $campo );
	update_user_meta( $id, Usuarios::META_GRUPO, $grupo_id );
	update_user_meta( $id, Usuarios::META_ESTADO, $estado );
	update_user_meta( $id, Usuarios::META_ACTIVADA_EN, current_time( 'mysql', true ) );
	$ids[ $login ]    = $id;
	$claves[ $login ] = $clave;
	return $id;
};
foreach ( array( 'prueba-acc-1', 'prueba-acc-2t', 'prueba-acc-3', 'prueba-acc-4', 'prueba-acc-5', 'prueba-acc-6', 'prueba-acc-editor' ) as $login ) {
	if ( username_exists( $login ) ) {
		exit( "Ya existe el usuario $login (¿una ejecución anterior interrumpida?). Bórralo antes de repetir la prueba.\n" );
	}
}
$crear( 'prueba-acc-1', Roles::SCOUTER, '990000101:email1', $grupo['id'] );
$crear( 'prueba-acc-2t', Roles::TUTOR, '990000102:tutor1_email_c', $grupo['id'] );
$crear( 'prueba-acc-3', Roles::SCOUTER, '990000103:email1', $grupo['id'] );
$crear( 'prueba-acc-4', Roles::SCOUTER, '990000104:email1', $grupo['id'], Usuarios::DESACTIVADO );
$crear( 'prueba-acc-5', Roles::SCOUTER, '990000105:email1', '999' );
$crear( 'prueba-acc-6', Roles::SCOUTER, '990000106:email1', $grupo['id'] ); // Su email no se busca con el CRM activo: se queda sin guardar.
// Usuario de WordPress "normal" con email propio (como un administrador, pero sin permisos).
$ids['prueba-acc-editor']    = wp_insert_user(
	array(
		'user_login' => 'prueba-acc-editor',
		'user_email' => 'editor.prueba@example.invalid',
		'user_pass'  => $claves['prueba-acc-editor'] = wp_generate_password( 20, true, false ),
		'role'       => 'editor',
	)
);
$login_de = static fn( array $us ) => array_map( static fn( WP_User $u ) => $u->user_login, $us );
$marta    = 'marta.prueba@example.invalid';

try {
	echo "== Parte A · Qué se escribe en «Nº de socio/a o email» ==\n";
	$ok( '76840390T' === Identificacion::normalizar( ' 076840390t ' ) && '76840390' === Identificacion::normalizar( '7684 0390' ), 'nº de socio/a: sin ceros delante ni espacios, con la T en mayúscula' );
	$ok( 'marta.prueba+scouts@example.invalid' === Identificacion::normalizar( '  Marta.Prueba+Scouts@Example.INVALID ' ), 'email: en minúsculas y sin espacios, conservando el «+»' );
	$ok( Identificacion::es_email( $marta ) && ! Identificacion::es_email( '76840390T' ), 'distingue email de nº de socio/a' );

	echo "\n== Parte B · Cuentas que usan un email ==\n";
	$ok( ! EmailCuenta::tiene( $ids['prueba-acc-1'] ) && ! EmailCuenta::tiene( $ids['prueba-acc-2t'] ), 'cuentas de antes de la v0.10: sin el email guardado' );
	$ok( array( 'prueba-acc-1', 'prueba-acc-2t' ) === $login_de( Identificacion::cuentas_con_email( $marta, $grupo ) ), 'el email de Marta es el de su cuenta y el de la cuenta familiar (tutor/a 1 de Leo), según el CRM' );
	$ok( $marta === EmailCuenta::leer( $ids['prueba-acc-1'] ) && $marta === EmailCuenta::leer( $ids['prueba-acc-2t'] ) && ! str_contains( (string) get_user_meta( $ids['prueba-acc-1'], EmailCuenta::META_CIFRADO, true ), 'marta' ), 'el email encontrado se guarda cifrado en las dos cuentas' );
	$ok( array( 'prueba-acc-3' ) === $login_de( Identificacion::cuentas_con_email( ' COMPARTIDO+scouts@example.INVALID', $grupo ) ), 'da igual mayúsculas y espacios (el CRM guarda el email con mayúsculas)' );
	$ok( array() === Identificacion::cuentas_con_email( 'jordi.prueba@example.invalid', $grupo ), 'un email de la ficha que no es el elegido para ninguna cuenta (tutor/a 2) no sirve' );
	$ok( array() === Identificacion::cuentas_con_email( 'baja.prueba@example.invalid', $grupo ), 'las cuentas desactivadas no cuentan' );
	$ok( array() === Identificacion::cuentas_con_email( 'otro.grupo@example.invalid', $grupo ), 'las cuentas de otro grupo no cuentan' );
	$ok( array() === Identificacion::cuentas_con_email( 'nadie@example.invalid', $grupo ) && array() === Identificacion::cuentas_con_email( 'no-es-un-email@', $grupo ), 'email que no está en el CRM (o mal escrito): ninguna' );
	$crm_caido = true;
	$ok( array( 'prueba-acc-1', 'prueba-acc-2t' ) === $login_de( Identificacion::cuentas_con_email( $marta, $grupo ) ), 'con el CRM caído, las cuentas con el email guardado se siguen encontrando' );
	$ok( null === Identificacion::cuentas_con_email( 'pendiente.prueba@example.invalid', $grupo ), 'si hace falta el CRM (cuenta sin email guardado) y no responde, se sabe (null), no se confunde con «ninguna»' );
	$crm_caido = false;

	echo "\n== Parte C · Con qué cuenta se entra ==\n";
	$ok( '76840390T' === Identificacion::usuario_para( '76840390T', $grupo, 'x' ), 'con un nº de socio/a no se consulta nada: es ese usuario' );
	$ok( 'prueba-acc-3' === Identificacion::usuario_para( 'compartido+scouts@example.invalid', $grupo, 'x' ), 'email de una sola cuenta → esa cuenta' );
	$ok( 'prueba-acc-1' === Identificacion::usuario_para( $marta, $grupo, $claves['prueba-acc-1'] ) && 'prueba-acc-2t' === Identificacion::usuario_para( $marta, $grupo, $claves['prueba-acc-2t'] ), 'email de dos cuentas → la de la contraseña escrita (la suya o la familiar)' );
	$r = Identificacion::usuario_para( $marta, $grupo, 'contraseña-incorrecta' );
	$ok( in_array( $r, array( 'prueba-acc-1', 'prueba-acc-2t' ), true ), 'email de dos cuentas y contraseña incorrecta → una de ellas (el acceso fallará como contraseña incorrecta)' );
	wp_set_password( $claves['prueba-acc-1'], $ids['prueba-acc-2t'] );
	$r = Identificacion::usuario_para( $marta, $grupo, $claves['prueba-acc-1'] );
	$ok( is_wp_error( $r ) && 'asde_email_varias' === $r->get_error_code() && str_contains( $r->get_error_message(), 'prueba-acc-1 o prueba-acc-2t' ), 'misma contraseña en las dos → pide el nº de socio/a y dice cuáles son' );
	wp_set_password( $claves['prueba-acc-2t'], $ids['prueba-acc-2t'] );
	$r = Identificacion::usuario_para( 'nadie@example.invalid', $grupo, 'x' );
	$ok( is_wp_error( $r ) && 'invalid_email' === $r->get_error_code(), 'email que no es de nadie → error genérico (no se llama a wp_signon con el email)' );
	$ok( 'prueba-acc-editor' === Identificacion::usuario_para( 'Editor.Prueba@example.invalid', $grupo, 'x' ), 'usuario de WordPress con email propio (administradores) → su usuario' );
	$crm_caido = true;
	$r         = Identificacion::usuario_para( 'pendiente.prueba@example.invalid', $grupo, 'x' );
	$r_marta   = Identificacion::usuario_para( 'compartido+scouts@example.invalid', $grupo, 'x' );
	$crm_caido = false;
	$ok( is_wp_error( $r ) && 'asde_email_crm' === $r->get_error_code() && str_contains( $r->get_error_message(), 'nº de socio/a' ), 'CRM caído y email sin guardar → lo dice y sugiere entrar con el nº de socio/a' );
	$ok( 'prueba-acc-3' === $r_marta, 'CRM caído y email ya guardado → se entra igual' );

	$entrar = static fn( string $escrito, string $clave ) => AccessHooks::en_grupo(
		$grupo['id'],
		static function () use ( $escrito, $clave, $grupo ) {
			$u = Identificacion::usuario_para( Identificacion::normalizar( $escrito ), $grupo, $clave );
			return is_wp_error( $u ) ? $u : wp_authenticate( $u, $clave );
		}
	);
	$r1 = $entrar( 'MARTA.prueba@example.invalid', $claves['prueba-acc-1'] );
	$r2 = $entrar( $marta, $claves['prueba-acc-2t'] );
	$ok( $r1 instanceof WP_User && $ids['prueba-acc-1'] === $r1->ID && $r2 instanceof WP_User && $ids['prueba-acc-2t'] === $r2->ID, 'acceso completo con email: cada contraseña abre su cuenta' );
	$r3 = $entrar( 'Editor.Prueba@example.invalid', $claves['prueba-acc-editor'] );
	$ok( is_wp_error( $r3 ) && 'asde_grupo' === $r3->get_error_code(), 'un usuario de WordPress sin grupo tampoco entra con su email (mismo mensaje genérico)' );

	echo "\n== Parte D · Pantalla de acceso ==\n";
	add_filter( 'send_auth_cookies', '__return_false' );
	$redirigir = static function ( $destino ) {
		throw new RuntimeException( 'asde-redirect:' . $destino );
	};
	add_filter( 'wp_redirect', $redirigir, 1 );
	$error_prop = new ReflectionProperty( AccesoView::class, 'error' );
	$enviar     = static function ( string $usuario, string $clave ) use ( $grupo, $error_prop ): string {
		wp_set_current_user( 0 );
		$error_prop->setValue( null, '' );
		$_POST = array(
			'asde_action' => 'acceder',
			'_wpnonce'    => wp_create_nonce( 'asde_acceso' ),
			'grupo'       => $grupo['id'],
			'usuario'     => $usuario,
			'clave'       => $clave,
		);
		try {
			( new AccesoView() )->handle_post();
		} catch ( RuntimeException $e ) {
			return $e->getMessage();
		} finally {
			$_POST = array();
		}
		return (string) $error_prop->getValue();
	};
	$res = $enviar( ' Marta.Prueba@EXAMPLE.invalid ', $claves['prueba-acc-2t'] );
	$ok( str_starts_with( $res, 'asde-redirect:' . Pages::url( 'area' ) ) && get_current_user_id() === $ids['prueba-acc-2t'], 'email + contraseña de la cuenta familiar → entra en la cuenta familiar y va al área privada' );
	$fila = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Logger::table() . ' WHERE id > %d AND accion = %s AND objeto_id = %s ORDER BY id DESC LIMIT 1', $log_antes, 'acceso', (string) $ids['prueba-acc-2t'] ), ARRAY_A ); // phpcs:ignore WordPress.DB
	$ok( $fila && 'email' === ( json_decode( (string) $fila['detalles'], true )['con'] ?? '' ) && ! str_contains( (string) $fila['detalles'], '@' ), 'auditoría: consta que entró con el email, sin guardar el email' );
	wp_set_current_user( 0 );
	$ok( AccesoView::MENSAJE_CREDENCIALES === $enviar( $marta, 'contraseña-incorrecta' ), 'contraseña incorrecta → «Nº de socio/a o email, contraseña o grupo incorrectos.»' );
	$ok( AccesoView::MENSAJE_CREDENCIALES === $enviar( 'nadie@example.invalid', 'x' ), 'email desconocido → el mismo mensaje (no revela si existe)' );
	wp_set_password( $claves['prueba-acc-1'], $ids['prueba-acc-2t'] );
	$varias = $enviar( $marta, $claves['prueba-acc-1'] );
	wp_set_password( $claves['prueba-acc-2t'], $ids['prueba-acc-2t'] );
	$ok( str_contains( $varias, 'varias cuentas' ) && str_contains( $varias, 'prueba-acc-1 o prueba-acc-2t' ) && 0 === get_current_user_id(), 'misma contraseña en las dos cuentas → no entra y pide el nº de socio/a' );
	$crm_caido = true;
	$ok( str_contains( $enviar( 'pendiente.prueba@example.invalid', 'x' ), 'el CRM no responde' ), 'CRM caído (y hacía falta) → mensaje claro' );
	$crm_caido = false;
	$res = $enviar( 'prueba-acc-3', $claves['prueba-acc-3'] );
	$ok( str_starts_with( $res, 'asde-redirect:' ) && get_current_user_id() === $ids['prueba-acc-3'], 'con el usuario de siempre se sigue entrando igual' );
	// 5 contraseñas incorrectas con ese email: el 6.º intento, aunque sea con la buena, se bloquea.
	for ( $i = 0; $i < 5; $i++ ) {
		$enviar( 'compartido+scouts@example.invalid', 'mala-' . $i );
	}
	$antes = $consultas;
	$ok( str_contains( $enviar( 'COMPARTIDO+scouts@example.invalid', $claves['prueba-acc-3'] ), 'Demasiados intentos' ) && $antes === $consultas, 'límite de intentos también por email (5 fallos): bloquea sin consultar el CRM' );
	remove_filter( 'wp_redirect', $redirigir, 1 );
	remove_filter( 'send_auth_cookies', '__return_false' );
	wp_set_current_user( 0 );
	$html = ( new AccesoView() )->render();
	$ok( str_contains( $html, 'Nº de socio/a o email' ) && str_contains( $html, 'email al que te llegó la invitación' ) && str_contains( $html, 'autocapitalize="none"' ), 'formulario: «Nº de socio/a o email», con la explicación y sin mayúsculas automáticas' );

	echo "\n== Parte E · ¿Has olvidado tu contraseña? ==\n";
	$_SERVER['REMOTE_ADDR'] = '192.0.2.' . random_int( 1, 254 );
	$filas_rec              = static fn( string $login ) => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . RegistroEmails::table() . ' WHERE user_id = %d AND tipo = %s', $ids[ $login ], 'reset' ) ); // phpcs:ignore WordPress.DB
	$pedir                  = static function ( string $usuario, string $grupo_id ) {
		$_POST = array(
			'asde_action' => 'recuperar',
			'_wpnonce'    => wp_create_nonce( 'asde_recuperar' ),
			'grupo'       => $grupo_id,
			'usuario'     => $usuario,
		);
		( new RecuperarView() )->handle_post();
		$_POST = array();
	};
	$pedir( 'Marta.Prueba@example.invalid', $grupo['id'] );
	$ok( 1 === $filas_rec( 'prueba-acc-1' ) && 1 === $filas_rec( 'prueba-acc-2t' ), 'con el email: se envía un enlace a cada cuenta que lo usa (la suya y la familiar)' );
	$pedir( 'baja.prueba@example.invalid', $grupo['id'] );
	$pedir( 'otro.grupo@example.invalid', $grupo['id'] );
	$ok( 0 === $filas_rec( 'prueba-acc-4' ) && 0 === $filas_rec( 'prueba-acc-5' ), 'a las cuentas desactivadas o de otro grupo, nada' );
	$pedir( 'prueba-acc-3', '' );
	$ok( 1 === $filas_rec( 'prueba-acc-3' ), 'con el nº de socio/a sigue funcionando (sin elegir grupo)' );
	$pedir( 'marta.prueba@example.invalid', $grupo['id'] );
	$ok( 1 === $filas_rec( 'prueba-acc-1' ), 'la misma petición repetida en 5 minutos no reenvía' );
	for ( $i = 0; $i < 7; $i++ ) {
		$pedir( "nadie$i@example.invalid", $grupo['id'] );
	}
	$pedir( 'compartido+scouts@example.invalid', $grupo['id'] );
	$ok( 0 === $filas_rec( 'prueba-acc-3' ) - 1, 'máximo 10 peticiones por hora desde un mismo dispositivo (la 11ª no consulta el CRM ni envía)' );
	$html = ( new RecuperarView() )->render();
	$ok( str_contains( $html, 'Si los datos son correctos' ) && str_contains( $html, 'un enlace para cada una' ), 'la respuesta es siempre la misma y avisa de que puede llegar un enlace por cuenta' );
	$grupos_orig = get_option( 'asde_ap_grupos' );
	$mas_grupos  = static fn() => $grupos_orig + array(
		'998' => array(
			'nombre'  => 'Grupo de prueba 998',
			'usuario' => '',
			'crm_id'  => '',
		),
	);
	add_filter( 'pre_option_asde_ap_grupos', $mas_grupos );
	Grupos::olvidar();
	$prop_env = new ReflectionProperty( RecuperarView::class, 'enviado' );
	$prop_env->setValue( null, false );
	$html_varios = ( new RecuperarView() )->render();
	remove_filter( 'pre_option_asde_ap_grupos', $mas_grupos );
	Grupos::olvidar();
	$html_uno = ( new RecuperarView() )->render();
	$ok( ! str_contains( $html_varios, 'name="grupo"' ) && ! str_contains( $html_uno, 'name="grupo"' ) && str_contains( $html_uno, 'Nº de socio/a o email' ) && str_contains( $html_uno, 'Solicita el acceso' ), 'formulario: ya no pregunta el grupo (el email guardado es de su cuenta, sea del grupo que sea) y enlaza con «Solicitar acceso»' );
} catch ( Throwable $e ) {
	++$fails;
	echo 'FAIL excepcion: ', $e->getMessage(), ' (', basename( $e->getFile() ), ':', $e->getLine(), ")\n";
} finally {
	// -----------------------------------------------------------------------
	// Limpieza: usuarios de prueba y todo lo que han generado.
	remove_filter( 'asde_ap_pre_personas_por_email', $simulador, 10 );
	remove_filter( 'pre_http_request', $cortar_crm, 1 );
	remove_filter( 'pre_wp_mail', '__return_true' );
	wp_set_current_user( 0 );
	wp_using_ext_object_cache( $cache_externa );
	$lista = implode( ',', array_map( 'intval', array_filter( $ids, 'is_int' ) ) );
	if ( '' !== $lista ) {
		$wpdb->query( 'DELETE FROM ' . RegistroEmails::table() . " WHERE user_id IN ($lista)" ); // phpcs:ignore WordPress.DB
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Logger::table() . " WHERE id > %d AND ( user_id IN ($lista) OR ( objeto = 'user' AND objeto_id IN ($lista) ) )", $log_antes ) ); // phpcs:ignore WordPress.DB
		foreach ( array_filter( $ids, 'is_int' ) as $id ) {
			wp_delete_user( $id );
		}
	}
	foreach ( array_diff( $transitorios(), $trans_antes ) as $opcion ) {
		delete_transient( substr( $opcion, strlen( '_transient_' ) ) );
	}
	$quedan = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users} WHERE user_login LIKE 'prueba-acc-%'" ); // phpcs:ignore WordPress.DB
	$ok( 0 === $quedan && ! array_diff( $transitorios(), $trans_antes ), 'limpieza: sin usuarios ni contadores de la prueba' );
}

echo $fails ? "\n$fails FALLOS\n" : "\nTODO OK (el sitio queda como estaba)\n";
