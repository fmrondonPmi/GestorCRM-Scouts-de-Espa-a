<?php
/**
 * Pruebas de las notificaciones push, paso 7a (v0.17.4, docs/pwa/PUSH.md, entorno LOCAL): claves VAPID,
 * suscripciones cifradas por cuenta, categorías, operaciones de la tarjeta (suscribir, baja, categorías),
 * qué se carga y dónde, y el service worker (avisos genéricos). El envío es el paso 7b. Ejecutar desde
 * app/public del site Local:
 *
 *   wp eval-file wp-content/plugins/asde-area-privada/tests/pwa-push.php
 *
 * No hace ninguna petición de red ni toca el CRM. Cada prueba deja el sitio como estaba (opciones,
 * usuarios, usermeta y auditoría).
 */
defined( 'ABSPATH' ) || exit;

require_once ABSPATH . 'wp-admin/includes/user.php';

use AsdeBalears\AreaPrivada\Admin\PwaPage;
use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Frontend\Pages;
use AsdeBalears\AreaPrivada\Idiomas\Idioma;
use AsdeBalears\AreaPrivada\Install\Roles;
use AsdeBalears\AreaPrivada\Pwa\Ajustes;
use AsdeBalears\AreaPrivada\Pwa\Push;
use AsdeBalears\AreaPrivada\Pwa\Pwa;
use AsdeBalears\AreaPrivada\Pwa\ServiceWorker;
use AsdeBalears\AreaPrivada\Pwa\Suscripciones;
use AsdeBalears\AreaPrivada\Pwa\Vapid;

global $wpdb, $wp_query;

$fails = 0;
$ok    = function ( $cond, string $label ) use ( &$fails ) {
	if ( ! $cond ) {
		++$fails;
	}
	echo ( $cond ? 'OK   ' : 'FAIL ' ), $label, "\n";
};

$pwa_antes    = get_option( Ajustes::OPCION, null );
$vapid_antes  = get_option( Vapid::OPCION, null );
$log_antes    = (int) $wpdb->get_var( 'SELECT MAX(id) FROM ' . Logger::table() );
$post_antes   = $_POST;
$req_antes    = $_REQUEST;
$wpq_antes    = $wp_query;
$ids_usuarios = array();
$b64          = static fn( int $n ): string => Vapid::base64url( random_bytes( $n ) );
$punto        = static fn(): string => Vapid::base64url( "\x04" . random_bytes( 64 ) );
$ep           = static fn( string $host = 'fcm.googleapis.com' ): string => 'https://' . $host . '/fcm/send/' . bin2hex( random_bytes( 12 ) );
$crear        = static function ( string $rol ) use ( &$ids_usuarios ) {
	$login = 'prueba-pwa-push-' . wp_generate_password( 6, false );
	$id    = wp_insert_user(
		array(
			'user_login' => $login,
			'user_pass'  => wp_generate_password( 20 ),
			'user_email' => $login . '@example.invalid',
			'role'       => $rol,
		)
	);
	if ( is_int( $id ) ) {
		$ids_usuarios[] = $id;
	}
	return $id;
};
$meta_cruda = static function ( int $uid ): string {
	global $wpdb;
	return (string) $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key = %s", $uid, Suscripciones::META ) ); // phpcs:ignore WordPress.DB
};

try {
	// ── Claves VAPID ──────────────────────────────────────────────────────────
	echo "\n── Claves VAPID\n";
	$par = Vapid::generar();
	$ok( is_array( $par ), 'OpenSSL de este servidor genera un par P-256' );
	$pub = Vapid::de_base64url( (string) ( $par['publica'] ?? '' ) );
	$ok( 65 === strlen( $pub ) && "\x04" === $pub[0], 'la clave pública es un punto sin comprimir de 65 bytes (lo que pide el navegador)' );
	$ok( ! str_contains( (string) ( $par['publica'] ?? '' ), '=' ) && ! str_contains( (string) ( $par['publica'] ?? '' ), '+' ) && ! str_contains( (string) ( $par['publica'] ?? '' ), '/' ), 'en base64url (sin =, + ni /)' );
	$k = isset( $par['pem'] ) ? openssl_pkey_get_private( $par['pem'] ) : false;
	$d = $k ? openssl_pkey_get_details( $k ) : null;
	$ok( $k && is_array( $d ) && "\x04" . str_pad( $d['ec']['x'], 32, "\0", STR_PAD_LEFT ) . str_pad( $d['ec']['y'], 32, "\0", STR_PAD_LEFT ) === $pub, 'la clave privada guardada corresponde a la pública' );
	$otro = Vapid::generar();
	$ok( is_array( $otro ) && $otro['publica'] !== $par['publica'], 'cada par es distinto' );
	$ok( 'abc' === Vapid::de_base64url( Vapid::base64url( 'abc' ) ) && '' === Vapid::de_base64url( '***' ), 'base64url de ida y vuelta; basura no se traga' );

	delete_option( Vapid::OPCION );
	$ok( ! Vapid::existe() && '' === Vapid::publica() && null === Vapid::privada_pem(), 'sin claves: no existen' );
	$ok( true === Vapid::asegurar() && Vapid::existe(), 'asegurar() las crea' );
	$guardado = (array) get_option( Vapid::OPCION );
	$ok( str_starts_with( (string) $guardado['privada'], 'v1:' ) && ! str_contains( (string) $guardado['privada'], 'PRIVATE KEY' ), 'la privada se guarda cifrada (nunca en claro)' );
	$ok( str_contains( (string) Vapid::privada_pem(), 'BEGIN PRIVATE KEY' ), 'y se descifra para firmar' );
	$publica = Vapid::publica();
	Vapid::asegurar();
	$ok( Vapid::publica() === $publica, 'asegurar() no cambia unas claves que ya existen (si no, se perderían todas las suscripciones)' );

	// ── Endpoints y claves ────────────────────────────────────────────────────
	echo "\n── Suscripciones: qué se admite\n";
	foreach ( array( 'fcm.googleapis.com', 'updates.push.services.mozilla.com', 'web.push.apple.com', 'wns2-par02p.notify.windows.com', 'x.fcm.googleapis.com' ) as $h ) {
		$ok( Suscripciones::endpoint_valido( $ep( $h ) ), "endpoint válido: {$h}" );
	}
	foreach (
		array(
			'http://fcm.googleapis.com/x'                         => 'sin https',
			'https://localhost/x'                                 => 'localhost (SSRF)',
			'https://169.254.169.254/latest/meta-data'            => 'dirección interna (SSRF)',
			'https://fcm.googleapis.com.atacante.net/x'           => 'dominio que solo empieza igual',
			'https://atacantefcm.googleapis.com.es/x'             => 'dominio que contiene el servicio',
			'https://fcm.googleapis.com:8443/x'                   => 'puerto distinto del 443',
			'https://usuario:clave@fcm.googleapis.com/x'          => 'con usuario y clave',
			'https://evilfcm-googleapis.com/x'                    => 'parecido',
			'ftp://fcm.googleapis.com/x'                          => 'otro protocolo',
			''                                                    => 'vacío',
		) as $malo => $motivo
	) {
		$ok( ! Suscripciones::endpoint_valido( $malo ), "endpoint rechazado: {$motivo}" );
	}
	$ok( ! Suscripciones::endpoint_valido( 'https://fcm.googleapis.com/' . str_repeat( 'a', 1100 ) ), 'endpoint demasiado largo: rechazado' );
	$permite = static fn( $l ) => array_merge( $l, array( 'push.ejemplo.test' ) );
	add_filter( 'asde_ap_push_hosts', $permite );
	$ok( Suscripciones::endpoint_valido( 'https://push.ejemplo.test/x' ), 'el filtro asde_ap_push_hosts amplía la lista' );
	remove_filter( 'asde_ap_push_hosts', $permite );
	$ok( ! Suscripciones::endpoint_valido( 'https://push.ejemplo.test/x' ), '…y sin el filtro, vuelve a rechazarse' );
	$ok( Suscripciones::claves_validas( $punto(), $b64( 16 ) ), 'claves válidas: punto de 65 bytes y auth de 16' );
	$ok( ! Suscripciones::claves_validas( $b64( 64 ), $b64( 16 ) ) && ! Suscripciones::claves_validas( $punto(), $b64( 15 ) ) && ! Suscripciones::claves_validas( '', '' ), 'claves de otro tamaño o vacías: rechazadas' );
	$ok( 16 === strlen( Suscripciones::huella( 'https://x' ) ) && Suscripciones::huella( 'https://x' ) === Suscripciones::huella( 'https://x' ) && Suscripciones::huella( 'https://x' ) !== Suscripciones::huella( 'https://y' ), 'la huella es corta, estable y distinta por endpoint' );

	// ── Suscripciones por cuenta ──────────────────────────────────────────────
	echo "\n── Suscripciones: guardar, cifrar y borrar\n";
	$u1 = $crear( 'subscriber' );
	$u2 = $crear( 'subscriber' );
	$ok( is_int( $u1 ) && is_int( $u2 ), 'cuentas de prueba creadas' );
	$e1 = $ep();
	$p1 = $punto();
	$a1 = $b64( 16 );
	$ok( true === Suscripciones::agregar( $u1, $e1, $p1, $a1 ) && 1 === Suscripciones::cuantos( $u1 ), 'se guarda un dispositivo' );
	$cruda = $meta_cruda( $u1 );
	$ok( '' !== $cruda && ! str_contains( $cruda, 'fcm.googleapis' ) && ! str_contains( $cruda, $p1 ) && ! str_contains( $cruda, $a1 ), 'en la base de datos no hay ni la dirección ni las claves en claro (cifrado)' );
	$l = Suscripciones::de_usuario( $u1 );
	$ok( 1 === count( $l ) && $l[0]['endpoint'] === $e1 && $l[0]['p256dh'] === $p1 && $l[0]['auth'] === $a1 && Suscripciones::huella( $e1 ) === $l[0]['huella'], 'se descifra tal cual (para enviar)' );
	// Lo cifrado de una cuenta no se lee desde otra.
	update_user_meta( $u2, Suscripciones::META, get_user_meta( $u1, Suscripciones::META, true ) );
	$ok( array() === Suscripciones::de_usuario( $u2 ), 'copiar lo cifrado a otra cuenta no sirve: la clave es de cada cuenta' );
	Suscripciones::quitar_todas( $u2 );
	$ok( true === Suscripciones::agregar( $u1, $e1, $p1, $a1 ) && 1 === Suscripciones::cuantos( $u1 ), 'el mismo dispositivo dos veces no se duplica' );
	$ok( false === Suscripciones::agregar( $u1, 'http://fcm.googleapis.com/x', $p1, $a1 ) && false === Suscripciones::agregar( $u1, $ep(), 'zz', $a1 ) && false === Suscripciones::agregar( 0, $ep(), $p1, $a1 ) && 1 === Suscripciones::cuantos( $u1 ), 'datos no válidos o sin cuenta: no se guarda nada' );
	$eps = array( $e1 );
	for ( $i = 0; $i < 6; $i++ ) {
		$e    = $ep();
		$eps[] = $e;
		Suscripciones::agregar( $u1, $e, $punto(), $b64( 16 ) );
	}
	$ok( Suscripciones::MAX === Suscripciones::cuantos( $u1 ), 'como mucho ' . Suscripciones::MAX . ' dispositivos por cuenta' );
	$guardados = array_column( Suscripciones::de_usuario( $u1 ), 'endpoint' );
	$ok( ! in_array( $e1, $guardados, true ) && in_array( end( $eps ), $guardados, true ), 'si se pasa, se van los más antiguos' );
	$ok( true === Suscripciones::quitar( $u1, end( $eps ) ) && false === Suscripciones::quitar( $u1, end( $eps ) ) && 4 === Suscripciones::cuantos( $u1 ), 'quitar un dispositivo: la primera vez sí, la segunda no' );
	$primero = Suscripciones::de_usuario( $u1 )[0];
	Suscripciones::quitar_huella( $u1, $primero['huella'] );
	$ok( 3 === Suscripciones::cuantos( $u1 ), 'quitar por huella (las que el servicio de avisos da por muertas)' );
	Suscripciones::agregar( $u2, $ep(), $punto(), $b64( 16 ) );
	$con = Suscripciones::usuarios_con_suscripcion();
	$ok( in_array( $u1, $con, true ) && in_array( $u2, $con, true ), 'la lista de cuentas con avisos las incluye' );
	Suscripciones::quitar_todas( $u1 );
	$ok( 0 === Suscripciones::cuantos( $u1 ) && '' === $meta_cruda( $u1 ) && ! in_array( $u1, Suscripciones::usuarios_con_suscripcion(), true ), 'quitar_todas() no deja nada' );
	wp_delete_user( $u2 );
	$ok( 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key = %s", $u2, Suscripciones::META ) ), 'al borrar la cuenta, WordPress borra sus suscripciones' ); // phpcs:ignore WordPress.DB
	$ids_usuarios = array_values( array_diff( $ids_usuarios, array( $u2 ) ) );

	echo "\n── Categorías\n";
	$ok( array() === Suscripciones::desactivadas( $u1 ) && true === Suscripciones::categoria_activa( $u1, 'pagos' ), 'sin guardar, están todas activadas' );
	Suscripciones::guardar_desactivadas( $u1, array( 'pagos', 'inventada', 'pagos', 'equipo' ) );
	$ok( array( 'pagos', 'equipo' ) === Suscripciones::desactivadas( $u1 ), 'se guardan solo las categorías que existen (y sin repetir)' );
	$ok( false === Suscripciones::categoria_activa( $u1, 'pagos' ) && true === Suscripciones::categoria_activa( $u1, 'uniforme' ), 'categoria_activa() lo respeta' );
	Suscripciones::guardar_desactivadas( $u1, array() );
	$ok( '' === (string) get_user_meta( $u1, Suscripciones::META_CATS, true ), 'vaciar las desactivadas borra la preferencia' );
	$ok( array( 'uniforme', 'pagos', 'fichas', 'equipo' ) === Push::CLAVES, 'las cuatro categorías decididas (P-PUSH-1)' );
	$tutor = $crear( Roles::TUTOR );
	$scout = $crear( Roles::SCOUTER );
	$ok( array( 'uniforme', 'pagos', 'fichas' ) === array_keys( Push::categorias_de( $tutor ) ), 'una cuenta familiar no ve «Avisos del equipo»' );
	$ok( array( 'uniforme', 'pagos', 'fichas', 'equipo' ) === array_keys( Push::categorias_de( $scout ) ), 'un scouter sí' );
	$ok( 'Pagaments i càrrecs' === Idioma::en( 'ca', static fn() => Push::categorias_de( $tutor )['pagos'] ), 'las categorías salen en el idioma de la persona' );

	// ── Ajuste «push» ─────────────────────────────────────────────────────────
	echo "\n── Ajuste de administración\n";
	Ajustes::restablecer();
	$ok( false === Ajustes::push() && false === Ajustes::datos()['push'], 'sin guardar nada, los avisos push están apagados (las instalaciones anteriores no cambian)' );
	Ajustes::guardar( true, '' );
	$ok( false === Ajustes::push(), 'guardar sin decir nada de push lo deja apagado' );
	Ajustes::guardar( true, '', true );
	$ok( true === Ajustes::push(), 'se enciende' );
	Ajustes::guardar( false, 'X' );
	$ok( true === Ajustes::datos()['push'] && false === Ajustes::push(), 'apagar la app deja de ofrecer los avisos (aunque la casilla siga)' );
	Ajustes::guardar( true, '' );
	$ok( true === Ajustes::push(), 'y guardar sin tocar push conserva lo que había' );
	Ajustes::guardar( true, '', false );
	$ok( false === Ajustes::push(), 'se apaga' );

	// ── Qué recibe el JS ──────────────────────────────────────────────────────
	echo "\n── Datos para la tarjeta\n";
	$ok( ! Push::activo() && null === Push::datos_js( $tutor ), 'con los avisos apagados no se manda nada' );
	Ajustes::guardar( true, '', true );
	$ok( Push::activo(), 'push encendido, app activa y claves creadas: en marcha' );
	$dj = Push::datos_js( $tutor );
	$ok( is_array( $dj ) && Vapid::publica() === $dj['vapid'] && str_ends_with( $dj['ajax'], 'admin-ajax.php' ) && array( 'uniforme', 'pagos', 'fichas' ) === array_keys( $dj['categorias'] ), 'clave pública, dirección de AJAX y las categorías de esa cuenta' );
	$ok( ! isset( $dj['categorias']['equipo'] ) && ! str_contains( (string) wp_json_encode( $dj ), 'PRIVATE' ), 'la clave privada nunca sale al navegador' );
	wp_set_current_user( $tutor );
	$dj = Push::datos_js( $tutor );
	$ok( false !== wp_verify_nonce( $dj['nonce'], Push::AJAX ), 'el nonce vale para la operación de los avisos' );
	Suscripciones::guardar_desactivadas( $tutor, array( 'pagos' ) );
	$dj = Push::datos_js( $tutor );
	$ok( true === $dj['categorias']['uniforme']['activa'] && false === $dj['categorias']['pagos']['activa'], 'cada categoría dice si está activada' );
	$ok( null === Push::datos_js( 0 ), 'sin cuenta, nada' );
	Ajustes::guardar( false, '', true );
	$ok( null === Push::datos_js( $tutor ), 'con la app apagada, nada' );
	Ajustes::guardar( true, '', true );
	add_filter( 'asde_ap_pwa_activa', '__return_false' );
	$ok( null === Push::datos_js( $tutor ), 'con el filtro que apaga la app, nada' );
	remove_filter( 'asde_ap_pwa_activa', '__return_false' );

	// ── Operaciones de la tarjeta (AJAX) ──────────────────────────────────────
	echo "\n── Operaciones de la tarjeta\n";
	$admin = $crear( 'administrator' );
	add_filter( 'wp_doing_ajax', '__return_true' );
	$muere = static function () {
		return static function () {
			throw new RuntimeException( 'DIE' );
		};
	};
	add_filter( 'wp_die_ajax_handler', $muere );
	$llamar = static function ( array $post ) {
		$_POST = $_REQUEST = $post;
		ob_start();
		try {
			( new Push() )->ajax();
		} catch ( RuntimeException $e ) { // wp_send_json_* termina con wp_die().
			unset( $e );
		}
		$j = json_decode( (string) ob_get_clean(), true );
		return is_array( $j ) ? $j : array( 'success' => null );
	};
	$nonce = static fn() => wp_create_nonce( Push::AJAX );
	wp_set_current_user( $admin );
	$j = $llamar( array( 'op' => 'suscribir', 'endpoint' => $ep(), 'p256dh' => $punto(), 'auth' => $b64( 16 ) ) );
	$ok( false === $j['success'] && 'nonce' === ( $j['data']['mensaje'] ?? '' ), 'sin nonce: rechazado' );
	$j = $llamar( array( 'op' => 'suscribir', 'nonce' => 'falso', 'endpoint' => $ep(), 'p256dh' => $punto(), 'auth' => $b64( 16 ) ) );
	$ok( false === $j['success'], 'con un nonce falso: rechazado' );
	$j = $llamar( array( 'op' => 'suscribir', 'nonce' => wp_create_nonce( 'otra_accion' ), 'endpoint' => $ep(), 'p256dh' => $punto(), 'auth' => $b64( 16 ) ) );
	$ok( false === $j['success'], 'con el nonce de otra acción: rechazado' );
	wp_set_current_user( $tutor );
	$j = $llamar( array( 'op' => 'suscribir', 'nonce' => $nonce(), 'endpoint' => $ep(), 'p256dh' => $punto(), 'auth' => $b64( 16 ) ) );
	$ok( false === $j['success'] && 'sin-acceso' === ( $j['data']['mensaje'] ?? '' ) && 0 === Suscripciones::cuantos( $tutor ), 'una cuenta sin acceso al área (sin ficha en el CRM) no puede suscribirse' );
	wp_set_current_user( $admin );
	Ajustes::guardar( true, '', false );
	$j = $llamar( array( 'op' => 'suscribir', 'nonce' => $nonce(), 'endpoint' => $ep(), 'p256dh' => $punto(), 'auth' => $b64( 16 ) ) );
	$ok( false === $j['success'] && 'apagado' === ( $j['data']['mensaje'] ?? '' ) && 0 === Suscripciones::cuantos( $admin ), 'con los avisos apagados no se guarda nada' );
	Ajustes::guardar( true, '', true );
	$e_ok = $ep();
	$j    = $llamar( array( 'op' => 'suscribir', 'nonce' => $nonce(), 'endpoint' => $e_ok, 'p256dh' => $punto(), 'auth' => $b64( 16 ) ) );
	$ok( true === $j['success'] && 1 === ( $j['data']['dispositivos'] ?? 0 ) && 1 === Suscripciones::cuantos( $admin ), 'suscribir: se guarda' );
	foreach (
		array(
			'endpoint de otro servidor' => array( 'endpoint' => 'https://interno.local/x' ),
			'endpoint sin https'        => array( 'endpoint' => 'http://fcm.googleapis.com/x' ),
			'p256dh con basura'         => array( 'p256dh' => '***' ),
			'auth de otro tamaño'       => array( 'auth' => $b64( 5 ) ),
		) as $motivo => $cambio
	) {
		$post = array( 'op' => 'suscribir', 'nonce' => $nonce(), 'endpoint' => $ep(), 'p256dh' => $punto(), 'auth' => $b64( 16 ) );
		$j    = $llamar( $cambio + $post );
		$ok( false === $j['success'] && 'datos' === ( $j['data']['mensaje'] ?? '' ), "suscribir con {$motivo}: rechazado" );
	}
	$ok( 1 === Suscripciones::cuantos( $admin ), 'las peticiones malas no han guardado nada: sigue habiendo un solo dispositivo' );
	$j = $llamar( array( 'op' => 'categorias', 'nonce' => $nonce(), 'desactivadas' => array( 'pagos', 'inventada', 'equipo' ) ) );
	$ok( true === $j['success'] && array( 'pagos', 'equipo' ) === Suscripciones::desactivadas( $admin ), 'categorías: se guardan las que existen y ve el administrador' );
	wp_set_current_user( $tutor );
	$post_cat = array( 'op' => 'categorias', 'nonce' => $nonce(), 'desactivadas' => array( 'equipo', 'fichas' ) );
	// El tutor no tiene acceso al área: no llega a guardar.
	$j = $llamar( $post_cat );
	$ok( false === $j['success'] && array( 'pagos' ) === Suscripciones::desactivadas( $tutor ), 'sin acceso, las categorías no cambian' );
	wp_set_current_user( $admin );
	$j = $llamar( array( 'op' => 'categorias', 'nonce' => $nonce() ) );
	$ok( true === $j['success'] && array() === Suscripciones::desactivadas( $admin ), 'categorías vacías: todo activado' );
	$j = $llamar( array( 'op' => 'baja', 'nonce' => $nonce(), 'endpoint' => $e_ok ) );
	$ok( true === $j['success'] && 0 === ( $j['data']['dispositivos'] ?? -1 ) && 0 === Suscripciones::cuantos( $admin ), 'baja: quita ese dispositivo' );
	$j = $llamar( array( 'op' => 'baja', 'nonce' => $nonce(), 'endpoint' => $e_ok ) );
	$ok( true === $j['success'], 'baja repetida: sin error' );
	$j = $llamar( array( 'op' => 'inventada', 'nonce' => $nonce() ) );
	$ok( false === $j['success'] && 'op' === ( $j['data']['mensaje'] ?? '' ), 'operación desconocida: rechazada' );
	$auditoria = $wpdb->get_results( $wpdb->prepare( 'SELECT accion, detalles FROM ' . Logger::table() . ' WHERE id > %d AND accion LIKE %s', $log_antes, 'push\\_%' ), ARRAY_A ); // phpcs:ignore WordPress.DB
	$acciones  = array_column( $auditoria, 'accion' );
	$ok( in_array( 'push_activado', $acciones, true ) && in_array( 'push_desactivado', $acciones, true ) && in_array( 'push_categorias', $acciones, true ), 'activar, desactivar y cambiar categorías quedan en la auditoría' );
	$ok( ! array_filter( $auditoria, static fn( $f ) => str_contains( (string) $f['detalles'], 'fcm.googleapis' ) || str_contains( (string) $f['detalles'], 'https' ) ), 'la auditoría no guarda ninguna dirección de dispositivo' );
	remove_filter( 'wp_die_ajax_handler', $muere );
	remove_filter( 'wp_doing_ajax', '__return_true' );

	// ── Qué se carga y dónde ──────────────────────────────────────────────────
	echo "\n── Dónde se carga la tarjeta\n";
	$pagina = static function ( string $clave ) {
		$id = Pages::id( $clave );
		return $id ? new WP_Query( array( 'page_id' => $id ) ) : null;
	};
	$limpiar = static function () {
		foreach ( array( 'asde-pwa', 'asde-pwa-push', 'asde-pwa-instalar', 'asde-pwa-app' ) as $h ) {
			wp_dequeue_script( $h );
			wp_deregister_script( $h );
		}
		wp_dequeue_style( 'asde-pwa-instalar' );
		wp_deregister_style( 'asde-pwa-instalar' );
		wp_dequeue_style( 'asde-pwa-app' );
		wp_deregister_style( 'asde-pwa-app' );
	};
	$datos = static fn() => implode( '', array_filter( (array) wp_scripts()->get_data( 'asde-pwa', 'before' ) ) );
	wp_set_current_user( $admin );
	Ajustes::guardar( true, '', true );
	if ( $pagina( 'area' ) ) {
		$wp_query = $pagina( 'area' ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride
		$limpiar();
		( new Pwa() )->registro();
		$ok( wp_script_is( 'asde-pwa-push', 'enqueued' ) && str_contains( $datos(), '"push":{"vapid":"' . Vapid::publica() . '"' ), 'en el inicio del área, con sesión y avisos en marcha: se carga push.js con sus datos' );
		$ok( ! str_contains( $datos(), 'PRIVATE' ), 'y sin la clave privada' );
		$wp_query = $pagina( 'personas' ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride
		$limpiar();
		( new Pwa() )->registro();
		$ok( ! wp_script_is( 'asde-pwa-push', 'enqueued' ) && ! str_contains( $datos(), '"push"' ), 'en otras páginas del área no sale' );
		$wp_query = $pagina( 'area' ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride
		$limpiar();
		wp_set_current_user( 0 );
		( new Pwa() )->registro();
		$ok( ! wp_script_is( 'asde-pwa-push', 'enqueued' ), 'sin sesión no sale' );
		wp_set_current_user( $admin );
		$limpiar();
		Ajustes::guardar( true, '', false );
		( new Pwa() )->registro();
		$ok( ! wp_script_is( 'asde-pwa-push', 'enqueued' ), 'con los avisos apagados no sale' );
		Ajustes::guardar( true, '', true );
		$wp_query = $wpq_antes; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
		$limpiar();
	}

	// ── Pantalla de administración ────────────────────────────────────────────
	echo "\n── Pantalla «App móvil»: avisos push\n";
	add_filter( 'wp_redirect', static function ( $loc ) {
		throw new RuntimeException( 'REDIR:' . $loc );
	}, 1 );
	Ajustes::guardar( true, '', false );
	delete_option( Vapid::OPCION );
	wp_set_current_user( $admin );
	$_POST = $_REQUEST = array( 'activa' => '1', 'push' => '1', 'nombre_corto' => '', '_wpnonce' => wp_create_nonce( 'asde_ap_pwa' ) );
	try {
		( new PwaPage() )->handle_guardar();
	} catch ( RuntimeException $e ) {
		unset( $e );
	}
	$ok( true === Ajustes::push() && Vapid::existe(), 'al encender los avisos se crean las claves del servidor' );
	$fila = $wpdb->get_var( $wpdb->prepare( 'SELECT detalles FROM ' . Logger::table() . " WHERE id > %d AND accion = 'pwa_guardada' ORDER BY id DESC LIMIT 1", $log_antes ) ); // phpcs:ignore WordPress.DB
	$ok( is_string( $fila ) && str_contains( $fila, '"push":true' ) && str_contains( $fila, '"push_antes":false' ), 'la auditoría guarda el cambio de push' );
	$_POST = $_REQUEST = array( 'activa' => '1', 'nombre_corto' => '', '_wpnonce' => wp_create_nonce( 'asde_ap_pwa' ) );
	try {
		( new PwaPage() )->handle_guardar();
	} catch ( RuntimeException $e ) {
		unset( $e );
	}
	$ok( false === Ajustes::push() && Vapid::existe(), 'sin marcar «push» se apagan, y las claves se conservan (para no perder las suscripciones al volver a encender)' );
	ob_start();
	( new PwaPage() )->render();
	$html = (string) ob_get_clean();
	$ok( str_contains( $html, 'name="push"' ) && str_contains( $html, 'Avisos push' ) && str_contains( $html, 'Claves del servidor: creadas' ), 'la pantalla tiene la casilla de los avisos push y el estado de las claves' );
	$ok( str_contains( $html, 'móviles suscritos' ), 'y cuántos móviles hay suscritos' );

	// ── Service worker ────────────────────────────────────────────────────────
	echo "\n── Service worker: avisos genéricos\n";
	$cfg = ServiceWorker::config();
	$ok( isset( $cfg['push']['titulo'], $cfg['push']['texto'], $cfg['push']['icono'], $cfg['push']['alcance'], $cfg['push']['inicio'] ), 'la configuración del service worker lleva los datos del aviso' );
	$ok( 'Tienes una novedad en el área privada' === $cfg['push']['texto'] && str_starts_with( $cfg['push']['inicio'], $cfg['push']['alcance'] ), 'texto genérico (P-PUSH-2) y la página de inicio dentro del alcance' );
	$ok( 'Tens una novetat a l\'àrea privada' === Idioma::en( 'ca', static fn() => ServiceWorker::config()['push']['texto'] ), 'el texto del aviso sale en el idioma de la persona' );
	$js = ServiceWorker::contenido();
	$ok( str_contains( $js, "addEventListener( 'push'" ) && str_contains( $js, "addEventListener( 'notificationclick'" ), 'el service worker atiende los avisos y sus pulsaciones' );
	$ok( str_contains( $js, 'body: P.texto' ) && str_contains( $js, 'P.titulo' ) && ! preg_match( '/datos\.(t|b|texto|titulo|body|title)\b/', $js ), 'el texto del aviso sale solo de la configuración: el mensaje no puede cambiarlo (nada de datos personales en la pantalla de bloqueo)' );
	$ok( str_contains( $js, "0 === url.href.indexOf( P.alcance )" ) && str_contains( $js, 'url.origin === self.location.origin' ), 'el destino del aviso solo puede ser una página dentro de la app' );
	$ok( str_contains( $js, 'showNotification' ) && 1 === substr_count( $js, 'showNotification(' ), 'siempre se enseña un aviso al recibir un push (Chrome lo exige)' );
	$ok( 1 === substr_count( $js, '.put(' ), 'el service worker sigue sin guardar nada más que los estáticos' );
	$off = ServiceWorker::contenido_apagado();
	$ok( str_contains( $off, 'pushManager' ) && str_contains( $off, 'unsubscribe()' ) && str_contains( $off, 'self.registration.unregister()' ), 'el service worker de apagado también da de baja los avisos' );

	// ── La tarjeta (JS) ───────────────────────────────────────────────────────
	echo "\n── La tarjeta: el JS\n";
	$push_js = (string) file_get_contents( ASDE_AP_DIR . 'assets/pwa/push.js' );
	preg_match_all( '/\bT\.([a-z0-9_]+)/', $push_js, $usados );
	$usados = array_values( array_unique( $usados[1] ) );
	$dados  = array_keys( Push::textos() );
	$ok( ! array_diff( $usados, $dados ) && ! array_diff( $dados, $usados ), 'el JS usa exactamente los textos que le manda PHP (' . implode( ', ', $usados ) . ')' );
	$ok( 11 === count( Push::textos() ) && 11 === count( array_filter( Push::textos() ) ), 'once textos, ninguno vacío' );
	$ok( 'Activa els avisos' === Idioma::en( 'ca', static fn() => Push::textos()['activar'] ) && 'Aktibatu abisuak' === Idioma::en( 'eu', static fn() => Push::textos()['activar'] ) && 'Activar os avisos' === Idioma::en( 'gl', static fn() => Push::textos()['activar'] ), 'los textos están en catalán, euskera y gallego' );
	$ok( str_contains( $push_js, 'requestPermission' ) && str_contains( $push_js, 'userVisibleOnly: true' ) && str_contains( $push_js, 'applicationServerKey' ), 'pide el permiso y se suscribe con la clave pública del servidor' );
	$ok( 1 === preg_match( '/boton\.addEventListener\( \'click\'[\s\S]*?activar\( reg \)/', $push_js ), 'el permiso se pide al pulsar el botón, no al abrir la página' );
	$ok( 1 === substr_count( $push_js, '.requestPermission()' ), 'el permiso se pide en un solo sitio' );
	$ok( str_contains( $push_js, 'esIos() && ! enApp()' ) && str_contains( $push_js, "'denied' === w.Notification.permission" ) && str_contains( $push_js, 'soportado()' ), 'iPhone sin instalar, permiso bloqueado y navegador sin soporte tienen su mensaje' );
	$ok( ! str_contains( $push_js, 'innerHTML' ) && 1 === substr_count( $push_js, 'w.fetch(' ) && str_contains( $push_js, 'w.fetch( cfg.ajax' ), 'pinta con textContent y solo hace una petición: la de la operación, a su dirección' );
	$ok( ! str_contains( $push_js, 'localStorage' ) && ! str_contains( $push_js, 'sessionStorage' ), 'no guarda nada en el navegador' );
	$sin_com = (string) preg_replace( array( '#/\*.*?\*/#s', '#(^|\s)//[^\n]*#' ), '', $push_js );
	$ok( '' !== $sin_com && ! preg_match( '/[áéíóúñ¿¡«»]/u', $sin_com ), 'sin textos en castellano escritos a mano (fuera de los comentarios)' );
} finally {
	remove_filter( 'wp_doing_ajax', '__return_true' );
	if ( isset( $muere ) ) {
		remove_filter( 'wp_die_ajax_handler', $muere );
	}
	remove_all_filters( 'wp_redirect', 1 );
	$_POST    = $post_antes;
	$_REQUEST = $req_antes;
	$wp_query = $wpq_antes; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
	wp_set_current_user( 0 );
	foreach ( $ids_usuarios as $id ) {
		wp_delete_user( (int) $id );
	}
	null === $pwa_antes ? delete_option( Ajustes::OPCION ) : update_option( Ajustes::OPCION, $pwa_antes, false );
	null === $vapid_antes ? delete_option( Vapid::OPCION ) : update_option( Vapid::OPCION, $vapid_antes, false );
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Logger::table() . " WHERE id > %d AND ( accion LIKE %s OR accion = 'pwa_guardada' )", $log_antes, 'push\\_%' ) ); // phpcs:ignore WordPress.DB
}

echo "\n── Limpieza\n";
$ok( $pwa_antes === get_option( Ajustes::OPCION, null ) && $vapid_antes === get_option( Vapid::OPCION, null ), 'las opciones vuelven a estar como estaban' );
$ok( 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users} WHERE user_login LIKE 'prueba-pwa-push-%'" ), 'sin usuarios de la prueba' ); // phpcs:ignore WordPress.DB
$ok( 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key IN ( %s, %s ) AND user_id IN ( SELECT ID FROM {$wpdb->users} WHERE user_login LIKE 'prueba-pwa-push-%' )", Suscripciones::META, Suscripciones::META_CATS ) ), 'sin suscripciones ni preferencias de la prueba' ); // phpcs:ignore WordPress.DB
$ok( 0 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Logger::table() . " WHERE id > %d AND ( accion LIKE %s OR accion = 'pwa_guardada' )", $log_antes, 'push\\_%' ) ), 'sin filas de auditoría de la prueba' ); // phpcs:ignore WordPress.DB

echo $fails ? "\n$fails FALLOS\n" : "\nTODO OK (nada tocado en el CRM real ni en la red; el sitio queda como estaba)\n";
