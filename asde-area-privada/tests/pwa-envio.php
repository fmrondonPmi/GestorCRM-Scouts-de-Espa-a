<?php
/**
 * Pruebas del envío de avisos push, paso 7b (v0.17.5, docs/pwa/PUSH.md, entorno LOCAL): cifrado del RFC 8291
 * (con sus vectores de prueba, tests/lib/Rfc8291.php), firma VAPID del RFC 8292, clasificación de las
 * respuestas, la cola de envío y el «aviso de prueba» de la pantalla. Ejecutar desde app/public del site Local:
 *
 *   wp eval-file wp-content/plugins/asde-area-privada/tests/pwa-envio.php
 *
 * No hace ninguna petición de red (se simula con pre_http_request) ni toca el CRM. Cada prueba deja el sitio
 * como estaba (opciones, cola, usuarios, usermeta y auditoría).
 */
defined( 'ABSPATH' ) || exit;

require_once ABSPATH . 'wp-admin/includes/user.php';

use AsdeBalears\AreaPrivada\Admin\PwaPage;
use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Pwa\Ajustes;
use AsdeBalears\AreaPrivada\Pwa\Push;
use AsdeBalears\AreaPrivada\Pwa\Suscripciones;
use AsdeBalears\AreaPrivada\Pwa\Vapid;
use AsdeBalears\AreaPrivada\Pwa\WebPush;

global $wpdb;

$fails = 0;
$ok    = function ( $cond, string $label ) use ( &$fails ) {
	if ( ! $cond ) {
		++$fails;
	}
	echo ( $cond ? 'OK   ' : 'FAIL ' ), $label, "\n";
};

$pwa_antes    = get_option( Ajustes::OPCION, null );
$vapid_antes  = get_option( Vapid::OPCION, null );
$cola_antes   = get_option( Push::COLA, null );
$log_antes    = (int) $wpdb->get_var( 'SELECT MAX(id) FROM ' . Logger::table() );
$post_antes   = $_POST;
$req_antes    = $_REQUEST;
$ids_usuarios = array();
$b            = static fn( string $s ): string => Vapid::de_base64url( $s );
$v            = require __DIR__ . '/lib/Rfc8291.php';
$ep           = static fn( string $host = 'fcm.googleapis.com' ): string => 'https://' . $host . '/fcm/send/' . bin2hex( random_bytes( 12 ) );

try {
	// ── RFC 8291: vectores de prueba ──────────────────────────────────────────
	echo "\n── Cifrado: vectores del RFC 8291 (apéndice A)\n";
	$as_priv = WebPush::pem_privada( $b( $v['as_private'] ), $b( $v['as_public'] ) );
	$ua_priv = WebPush::pem_privada( $b( $v['ua_private'] ), $b( $v['ua_public'] ) );
	$k1      = openssl_pkey_get_private( $as_priv );
	$d1      = $k1 ? openssl_pkey_get_details( $k1 ) : null;
	$ok( $k1 && is_array( $d1 ) && "\x04" . $d1['ec']['x'] . $d1['ec']['y'] === $b( $v['as_public'] ) && str_pad( $d1['ec']['d'], 32, "\0", STR_PAD_LEFT ) === $b( $v['as_private'] ), 'la clave privada reconstruida desde el escalar del RFC corresponde a su punto público' );
	$k = WebPush::derivar( $b( $v['ua_public'] ), $b( $v['auth_secret'] ), $as_priv, $b( $v['as_public'] ), $b( $v['salt'] ) );
	$ok( is_array( $k ), 'se derivan las claves con las entradas del RFC' );
	$ok( $k['ecdh'] === $b( $v['ecdh_secret'] ), 'secreto compartido ECDH = ecdh_secret del RFC' );
	$ok( $k['prk_key'] === $b( $v['prk_key'] ), 'PRK_key = el del RFC' );
	$ok( $k['ikm'] === $b( $v['ikm'] ), 'IKM = el del RFC (incluye el key_info con las dos claves públicas)' );
	$ok( $k['prk'] === $b( $v['prk'] ), 'PRK = el del RFC' );
	$ok( $k['cek'] === $b( $v['cek'] ), 'clave de cifrado (CEK) = la del RFC' );
	$ok( $k['nonce'] === $b( $v['nonce'] ), 'nonce = el del RFC' );
	$texto = $b( $v['plaintext'] );
	$ok( 'When I grow up, I want to be a watermelon' === $texto, 'el texto en claro del ejemplo es «When I grow up…»' );
	$cuerpo = WebPush::cifrar( $texto, $b( $v['ua_public'] ), $b( $v['auth_secret'] ), array( 'privada_pem' => $as_priv, 'publica' => $b( $v['as_public'] ) ), $b( $v['salt'] ) );
	$ok( is_string( $cuerpo ) && substr( $cuerpo, 0, 86 ) === $b( $v['cabecera'] ), 'la cabecera de 86 bytes (sal, tamaño de registro 4096, clave pública) = la del RFC' );
	$ok( is_string( $cuerpo ) && substr( $cuerpo, 86 ) === $b( $v['cifrado'] ), 'el texto cifrado (con su etiqueta GCM) = el del RFC' );
	$ok( is_string( $cuerpo ) && $cuerpo === $b( $v['cabecera'] ) . $b( $v['cifrado'] ), 'el cuerpo entero = cabecera + cifrado del RFC, byte a byte' );

	echo "\n── Cifrado: con claves nuevas y descifrando como el navegador\n";
	$descifrar = static function ( string $cuerpo, string $ua_priv_pem, string $ua_pub, string $auth ): ?string {
		$sal   = substr( $cuerpo, 0, 16 );
		$rs    = unpack( 'N', substr( $cuerpo, 16, 4 ) )[1];
		$idlen = ord( $cuerpo[20] );
		$as    = substr( $cuerpo, 21, $idlen );
		$resto = substr( $cuerpo, 21 + $idlen );
		$ecdh  = openssl_pkey_derive( openssl_pkey_get_public( WebPush::pem_publica( $as ) ), openssl_pkey_get_private( $ua_priv_pem ), 32 );
		$ikm   = hash_hkdf( 'sha256', $ecdh, 32, "WebPush: info\0" . $ua_pub . $as, $auth );
		$cek   = hash_hkdf( 'sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $sal );
		$nonce = hash_hkdf( 'sha256', $ikm, 12, "Content-Encoding: nonce\0", $sal );
		$claro = openssl_decrypt( substr( $resto, 0, -16 ), 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, substr( $resto, -16 ) );
		if ( false === $claro || 4096 !== $rs ) {
			return null;
		}
		return rtrim( $claro, "\0" ) !== '' && "\x02" === substr( rtrim( $claro, "\0" ), -1 ) ? substr( rtrim( $claro, "\0" ), 0, -1 ) : null;
	};
	$ua   = Vapid::generar(); // Un navegador cualquiera: su par P-256 y su secreto.
	$ua_p = $b( $ua['publica'] );
	$au   = random_bytes( 16 );
	$msg  = '{"u":"/nuestro-grupo-scout/area-privada/"}';
	$c1   = WebPush::cifrar( $msg, $ua_p, $au );
	$c2   = WebPush::cifrar( $msg, $ua_p, $au );
	$ok( is_string( $c1 ) && is_string( $c2 ) && $c1 !== $c2, 'cada mensaje lleva su propia sal y su propia clave efímera (nunca se repite el cifrado)' );
	$ok( $msg === $descifrar( $c1, $ua['pem'], $ua_p, $au ) && $msg === $descifrar( $c2, $ua['pem'], $ua_p, $au ), 'el navegador (con su clave privada) descifra el mensaje' );
	$ok( ! str_contains( $c1, 'nuestro-grupo' ) && ! str_contains( $c1, '"u"' ), 'el mensaje no aparece en claro en el cuerpo que viaja' );
	$otro = Vapid::generar();
	$ok( null === $descifrar( $c1, $otro['pem'], $ua_p, $au ), 'otro navegador no puede descifrarlo' );
	$ok( null === WebPush::cifrar( $msg, 'corta', $au ) && null === WebPush::cifrar( $msg, $ua_p, 'corta' ) && null === WebPush::cifrar( str_repeat( 'x', 5000 ), $ua_p, $au ), 'claves de tamaño incorrecto o mensaje demasiado grande: no cifra' );
	$ok( 86 + strlen( $msg ) + 1 + 16 === strlen( $c1 ), 'tamaño: cabecera 86 + mensaje + delimitador 1 + etiqueta 16' );

	// ── VAPID: firma ──────────────────────────────────────────────────────────
	echo "\n── VAPID (RFC 8292): el JWT\n";
	Vapid::asegurar();
	$e_ejemplo = 'https://fcm.googleapis.com/fcm/send/abc123';
	$jwt       = WebPush::jwt( $e_ejemplo );
	$ok( is_string( $jwt ) && 3 === count( explode( '.', (string) $jwt ) ), 'el JWT tiene sus tres partes' );
	[ $h64, $c64, $s64 ] = explode( '.', (string) $jwt ) + array( '', '', '' );
	$cab = json_decode( $b( $h64 ), true );
	$cla = json_decode( $b( $c64 ), true );
	$ok( array( 'typ' => 'JWT', 'alg' => 'ES256' ) === $cab, 'cabecera: JWT con ES256' );
	$ok( 'https://fcm.googleapis.com' === ( $cla['aud'] ?? '' ), 'audiencia: el origen del servicio de avisos (sin ruta)' );
	$ok( isset( $cla['exp'] ) && $cla['exp'] > time() + HOUR_IN_SECONDS && $cla['exp'] <= time() + DAY_IN_SECONDS, 'caduca en menos de 24 horas (el máximo del estándar)' );
	$ok( isset( $cla['sub'] ) && ( str_starts_with( $cla['sub'], 'mailto:' ) || str_starts_with( $cla['sub'], 'https://' ) ), 'lleva un contacto (mailto: o https://)' );
	$cruda = $b( $s64 );
	$ok( 64 === strlen( $cruda ), 'la firma es R‖S de 64 bytes (no DER)' );
	$der_desde = static function ( string $raw ): string {
		$un = static function ( string $n ): string {
			$n = ltrim( $n, "\0" );
			$n = '' === $n ? "\0" : $n;
			return ( ord( $n[0] ) & 0x80 ? "\0" : '' ) . $n;
		};
		$r = $un( substr( $raw, 0, 32 ) );
		$s = $un( substr( $raw, 32 ) );
		$c = "\x02" . chr( strlen( $r ) ) . $r . "\x02" . chr( strlen( $s ) ) . $s;
		return "\x30" . chr( strlen( $c ) ) . $c;
	};
	$pub_pem = WebPush::pem_publica( $b( Vapid::publica() ) );
	$ok( 1 === openssl_verify( $h64 . '.' . $c64, $der_desde( $cruda ), openssl_pkey_get_public( $pub_pem ), OPENSSL_ALGO_SHA256 ), 'la firma se verifica con la clave pública del servidor (la que recibe el navegador)' );
	$ok( 0 === openssl_verify( $h64 . '.' . $c64 . 'x', $der_desde( $cruda ), openssl_pkey_get_public( $pub_pem ), OPENSSL_ALGO_SHA256 ), 'y no con un contenido alterado' );
	$otro_par = Vapid::generar();
	$ok( 0 === openssl_verify( $h64 . '.' . $c64, $der_desde( $cruda ), openssl_pkey_get_public( WebPush::pem_publica( $b( $otro_par['publica'] ) ) ), OPENSSL_ALGO_SHA256 ), 'ni con la clave pública de otro servidor' );
	$ok( null === WebPush::jwt( 'no es una dirección' ), 'sin dirección válida no hay JWT' );
	$ok( is_string( WebPush::jwt( 'https://web.push.apple.com/x', time() + 60 ) ) && 'https://web.push.apple.com' === ( json_decode( $b( explode( '.', (string) WebPush::jwt( 'https://web.push.apple.com/x' ) )[1] ), true )['aud'] ?? '' ), 'la audiencia cambia con el servicio (Apple)' );
	$filtro = static fn() => 'avisos@example.invalid';
	add_filter( 'asde_ap_push_contacto', $filtro );
	$ok( 'mailto:avisos@example.invalid' === WebPush::contacto(), 'el contacto se puede cambiar con el filtro asde_ap_push_contacto' );
	remove_filter( 'asde_ap_push_contacto', $filtro );
	$mal = static fn() => 'no-es-un-email';
	add_filter( 'asde_ap_push_contacto', $mal );
	$ok( str_starts_with( WebPush::contacto(), 'http' ), 'si el contacto no es un email, se usa la dirección de la web' );
	remove_filter( 'asde_ap_push_contacto', $mal );
	$ok( 64 === strlen( (string) WebPush::firma_cruda( $der_desde( str_repeat( "\x01", 64 ) ) ) ) && null === WebPush::firma_cruda( 'basura' ), 'conversión DER → R‖S: bien con firmas válidas y rechaza basura' );

	// ── Clasificación y envío (sin red) ───────────────────────────────────────
	echo "\n── Envío: respuestas del servicio de avisos\n";
	$ok( WebPush::OK === WebPush::clasificar( 201 ) && WebPush::OK === WebPush::clasificar( 200 ), '2xx: bien' );
	$ok( WebPush::MUERTA === WebPush::clasificar( 404 ) && WebPush::MUERTA === WebPush::clasificar( 410 ), '404 y 410: la suscripción ya no existe' );
	$ok( WebPush::REINTENTAR === WebPush::clasificar( 429 ) && WebPush::REINTENTAR === WebPush::clasificar( 500 ) && WebPush::REINTENTAR === WebPush::clasificar( 503 ) && WebPush::REINTENTAR === WebPush::clasificar( 0 ), '429, 5xx y errores de red: reintentar' );
	$ok( WebPush::ERROR === WebPush::clasificar( 400 ) && WebPush::ERROR === WebPush::clasificar( 401 ) && WebPush::ERROR === WebPush::clasificar( 403 ) && WebPush::ERROR === WebPush::clasificar( 413 ), '400, 401, 403 y 413: no se arreglan repitiendo' );
	$disp = array(
		'endpoint' => $ep(),
		'p256dh'   => $ua['publica'],
		'auth'     => Vapid::base64url( $au ),
	);
	$peticiones = array();
	$respuesta  = array( 'response' => array( 'code' => 201, 'message' => 'Created' ), 'headers' => array(), 'body' => '' );
	$simula     = static function ( $pre, $args, $url ) use ( &$peticiones, &$respuesta ) {
		$peticiones[] = array( 'url' => $url, 'args' => $args );
		return $respuesta;
	};
	add_filter( 'pre_http_request', $simula, 1, 3 );
	$r = WebPush::enviar( $disp, '/nuestro-grupo-scout/area-privada/' );
	$ok( WebPush::OK === $r['estado'] && 201 === $r['codigo'] && 1 === count( $peticiones ) && $peticiones[0]['url'] === $disp['endpoint'], 'envía una petición POST a la dirección del dispositivo y la da por buena con 201' );
	$h = $peticiones[0]['args']['headers'];
	$ok( 'aes128gcm' === $h['Content-Encoding'] && 'application/octet-stream' === $h['Content-Type'] && str_starts_with( $h['Authorization'], 'vapid t=' ) && str_contains( $h['Authorization'], ', k=' . Vapid::publica() ), 'cabeceras: aes128gcm, octet-stream y Authorization vapid con la clave pública' );
	$ok( (string) DAY_IN_SECONDS === $h['TTL'] && 'normal' === $h['Urgency'], 'TTL de un día y urgencia normal' );
	$ok( 'POST' === ( $peticiones[0]['args']['method'] ?? 'POST' ) && 0 === $peticiones[0]['args']['redirection'] && $peticiones[0]['args']['timeout'] <= 15, 'POST, sin seguir redirecciones y con límite de tiempo' );
	$ok( '{"u":"/nuestro-grupo-scout/area-privada/"}' === $descifrar( $peticiones[0]['args']['body'], $ua['pem'], $ua_p, $au ), 'el navegador descifra del cuerpo enviado: solo la ruta de destino, nada más' );
	$claro_json = json_decode( (string) $descifrar( $peticiones[0]['args']['body'], $ua['pem'], $ua_p, $au ), true );
	$ok( array( 'u' ) === array_keys( (array) $claro_json ), 'el mensaje solo tiene el campo «u» (ninguna otra información)' );
	foreach ( array( 404 => WebPush::MUERTA, 410 => WebPush::MUERTA, 429 => WebPush::REINTENTAR, 503 => WebPush::REINTENTAR, 403 => WebPush::ERROR ) as $codigo => $esperado ) {
		$respuesta = array( 'response' => array( 'code' => $codigo, 'message' => 'x' ), 'headers' => array(), 'body' => '' );
		$ok( $esperado === WebPush::enviar( $disp, '/x/' )['estado'], "respuesta {$codigo} → {$esperado}" );
	}
	$respuesta = array( 'response' => array( 'code' => 429, 'message' => 'x' ), 'headers' => array( 'retry-after' => '120' ), 'body' => '' );
	$ok( 120 === WebPush::enviar( $disp, '/x/' )['espera'], 'Retry-After se respeta' );
	$respuesta = array( 'response' => array( 'code' => 429, 'message' => 'x' ), 'headers' => array( 'retry-after' => '999999' ), 'body' => '' );
	$ok( HOUR_IN_SECONDS === WebPush::enviar( $disp, '/x/' )['espera'], '…con un tope de una hora' );
	remove_filter( 'pre_http_request', $simula, 1 );
	$err = static fn() => new WP_Error( 'http_request_failed', 'cURL error 28: timed out' );
	add_filter( 'pre_http_request', $err, 1 );
	$ok( WebPush::REINTENTAR === WebPush::enviar( $disp, '/x/' )['estado'], 'error de red: reintentar' );
	remove_filter( 'pre_http_request', $err, 1 );
	$peticiones = array();
	add_filter( 'pre_http_request', $simula, 1, 3 );
	$respuesta = array( 'response' => array( 'code' => 201, 'message' => 'x' ), 'headers' => array(), 'body' => '' );
	foreach ( array( 'https://interno.local/x', 'http://fcm.googleapis.com/x', 'https://169.254.169.254/x' ) as $malo ) {
		$r = WebPush::enviar( array( 'endpoint' => $malo ) + $disp, '/x/' );
		$ok( WebPush::ERROR === $r['estado'], "nunca se hace una petición a {$malo} (SSRF)" );
	}
	$ok( ! $peticiones, 'y no salió ninguna petición' );
	$r = WebPush::enviar( array( 'p256dh' => 'corta' ) + $disp, '/x/' );
	$ok( WebPush::ERROR === $r['estado'] && ! $peticiones, 'claves del dispositivo corruptas: error sin enviar nada' );
	remove_filter( 'pre_http_request', $simula, 1 );

	// ── Cola ──────────────────────────────────────────────────────────────────
	echo "\n── Cola de envío\n";
	$crear = static function ( string $rol ) use ( &$ids_usuarios ) {
		$login = 'prueba-pwa-env-' . wp_generate_password( 6, false );
		$id    = wp_insert_user( array( 'user_login' => $login, 'user_pass' => wp_generate_password( 20 ), 'user_email' => $login . '@example.invalid', 'role' => $rol ) );
		if ( is_int( $id ) ) {
			$ids_usuarios[] = $id;
		}
		return $id;
	};
	$nuevo_disp = static function ( int $uid ) use ( $ep ) {
		$k = Vapid::generar();
		Suscripciones::agregar( $uid, $ep(), $k['publica'], Vapid::base64url( random_bytes( 16 ) ) );
	};
	$u1 = $crear( 'subscriber' );
	$u2 = $crear( 'subscriber' );
	delete_option( Push::COLA );
	Ajustes::guardar( true, '', false );
	$nuevo_disp( $u1 );
	$ok( 0 === Push::encolar( $u1, 'uniforme' ) && array() === Push::cola(), 'con los avisos apagados no se encola nada' );
	Ajustes::guardar( true, '', true );
	$ok( 0 === Push::encolar( $u2, 'uniforme' ), 'una cuenta sin móviles: nada que encolar' );
	$ok( 0 === Push::encolar( $u1, 'inventada' ), 'categoría que no existe: nada' );
	Suscripciones::guardar_desactivadas( $u1, array( 'pagos' ) );
	$ok( 0 === Push::encolar( $u1, 'pagos' ), 'categoría desactivada por la persona: no se encola' );
	$nuevo_disp( $u1 );
	$ok( 2 === Push::encolar( $u1, 'uniforme', '/nuestro-grupo-scout/area-privada/uniforme/' ), 'uniforme activado y dos móviles: dos avisos en cola' );
	$cola = Push::cola();
	$ok( 2 === count( $cola ) && '/nuestro-grupo-scout/area-privada/uniforme/' === $cola[0]['r'] && 0 === $cola[0]['n'] && $cola[0]['u'] === $u1 && ! array_key_exists( 'texto', $cola[0] ), 'en la cola solo hay cuenta, móvil (huella), categoría y ruta: ningún texto' );
	$ok( false !== wp_next_scheduled( Push::HOOK_COLA ), 'queda programada una pasada del WP-Cron' );
	wp_clear_scheduled_hook( Push::HOOK_COLA );

	$pet = array();
	$cod = 201;
	$sim = static function ( $pre, $args, $url ) use ( &$pet, &$cod ) {
		$pet[] = $url;
		return array( 'response' => array( 'code' => $cod, 'message' => 'x' ), 'headers' => array(), 'body' => '' );
	};
	add_filter( 'pre_http_request', $sim, 1, 3 );
	$res = Push::procesar_cola();
	$ok( 2 === $res['enviados'] && 0 === $res['pendientes'] && 2 === count( $pet ) && array() === Push::cola(), 'procesar la cola: dos enviados y la cola queda vacía' );
	$ok( false === get_option( Push::COLA, false ), 'sin pendientes no queda ni la opción' );
	$ok( false === get_transient( 'asde_ap_push_lock' ), 'y se libera el cerrojo' );

	// Muertas: se quitan.
	Push::encolar( $u1, 'uniforme' );
	$cod = 410;
	$res = Push::procesar_cola();
	$ok( 2 === $res['muertas'] && 0 === Suscripciones::cuantos( $u1 ), 'si el servicio dice 410, el móvil se da de baja y no se reintenta' );
	// Reintentos.
	$nuevo_disp( $u1 );
	Push::encolar( $u1, 'uniforme' );
	$cod = 503;
	$res = Push::procesar_cola();
	$cola = Push::cola();
	$ok( 1 === $res['reintentos'] && 1 === count( $cola ) && 1 === $cola[0]['n'] && $cola[0]['t'] > time() + 100, 'con 503 se reintenta más tarde (con espera) y se cuenta el intento' );
	$cola[0]['t'] = time() - 1;
	update_option( Push::COLA, $cola, false );
	Push::procesar_cola();
	$cola = Push::cola();
	$cola[0]['t'] = time() - 1;
	update_option( Push::COLA, $cola, false );
	$res = Push::procesar_cola();
	$ok( 1 === $res['fallos'] && array() === Push::cola(), 'tras tres intentos fallidos se abandona (no se reintenta para siempre)' );
	$cod = 403;
	Push::encolar( $u1, 'uniforme' );
	$res = Push::procesar_cola();
	$ok( 1 === $res['fallos'] && 0 === $res['reintentos'] && array() === Push::cola(), 'un 403 no se reintenta' );
	// Aún no toca.
	$cod = 201;
	Push::encolar( $u1, 'uniforme' );
	$cola = Push::cola();
	$cola[0]['t'] = time() + 600;
	update_option( Push::COLA, $cola, false );
	$pet = array();
	$res = Push::procesar_cola();
	$ok( 0 === $res['enviados'] && 1 === $res['pendientes'] && ! $pet && false !== wp_next_scheduled( Push::HOOK_COLA ), 'un aviso que aún no toca espera y reprograma la pasada' );
	wp_clear_scheduled_hook( Push::HOOK_COLA );
	// Desactivó la categoría mientras esperaba.
	$cola[0]['t'] = time() - 1;
	update_option( Push::COLA, $cola, false );
	Suscripciones::guardar_desactivadas( $u1, array( 'uniforme' ) );
	$res = Push::procesar_cola();
	$ok( 0 === $res['enviados'] && ! $pet && array() === Push::cola(), 'si la persona desactiva la categoría mientras espera, no se envía' );
	Suscripciones::guardar_desactivadas( $u1, array() );
	// Móvil dado de baja mientras esperaba / cuenta borrada.
	Push::encolar( $u1, 'uniforme' );
	Suscripciones::quitar_todas( $u1 );
	$res = Push::procesar_cola();
	$ok( 0 === $res['enviados'] && ! $pet && array() === Push::cola(), 'si el móvil se da de baja mientras espera, no se envía' );
	// Apagado con cola.
	$nuevo_disp( $u1 );
	Push::encolar( $u1, 'uniforme' );
	Ajustes::guardar( true, '', false );
	$res = Push::procesar_cola();
	$ok( 0 === $res['enviados'] && ! $pet && array() === Push::cola(), 'si se apagan los avisos con avisos en cola, la cola se vacía sin enviar' );
	Ajustes::guardar( true, '', true );
	// Lotes.
	Suscripciones::quitar_todas( $u1 );
	for ( $i = 0; $i < 5; $i++ ) {
		$nuevo_disp( $u1 );
	}
	Push::encolar( $u1, 'uniforme' );
	$pet = array();
	$res = Push::procesar_cola( 3 );
	$ok( 3 === $res['enviados'] && 2 === $res['pendientes'] && 3 === count( $pet ), 'se envía por lotes (aquí, 3 de 5) y el resto espera' );
	$res = Push::procesar_cola( 3 );
	$ok( 2 === $res['enviados'] && 0 === $res['pendientes'], 'la siguiente pasada termina' );
	wp_clear_scheduled_hook( Push::HOOK_COLA );
	// Cerrojo.
	Push::encolar( $u1, 'uniforme' );
	set_transient( 'asde_ap_push_lock', 1, 60 );
	$pet = array();
	$res = Push::procesar_cola();
	$ok( ! $pet && 0 === $res['enviados'] && 5 === count( Push::cola() ), 'con otra pasada en marcha (cerrojo), no se envía dos veces' );
	delete_transient( 'asde_ap_push_lock' );
	delete_option( Push::COLA );
	wp_clear_scheduled_hook( Push::HOOK_COLA );
	// Tope.
	$gran = array();
	for ( $i = 0; $i < 500; $i++ ) {
		$gran[] = array( 'u' => 1, 'h' => 'x', 'c' => 'pagos', 'r' => '/', 'n' => 0, 't' => time() + 9999 );
	}
	update_option( Push::COLA, $gran, false );
	$ok( 0 === Push::encolar( $u1, 'uniforme' ) && 500 === count( Push::cola() ), 'la cola tiene un tope (500): si se llena, no crece' );
	delete_option( Push::COLA );
	wp_clear_scheduled_hook( Push::HOOK_COLA );
	$auditoria = $wpdb->get_results( $wpdb->prepare( 'SELECT detalles FROM ' . Logger::table() . " WHERE id > %d AND accion = 'push_lote'", $log_antes ), ARRAY_A ); // phpcs:ignore WordPress.DB
	$ok( count( $auditoria ) >= 3 && ! array_filter( $auditoria, static fn( $f ) => str_contains( (string) $f['detalles'], 'https' ) || str_contains( (string) $f['detalles'], 'fcm' ) ), 'cada pasada con envíos deja un resumen en la auditoría, sin direcciones' );
	remove_filter( 'pre_http_request', $sim, 1 );

	// ── Aviso de prueba ───────────────────────────────────────────────────────
	echo "\n── Pantalla «App móvil»: aviso de prueba\n";
	$admin = $crear( 'administrator' );
	add_filter( 'wp_redirect', static function ( $loc ) {
		throw new RuntimeException( 'REDIR:' . $loc );
	}, 1 );
	add_filter( 'wp_die_handler', static function () {
		return static function () {
			throw new RuntimeException( 'DIE' );
		};
	} );
	$lanzar = static function () {
		try {
			( new PwaPage() )->handle_prueba();
		} catch ( RuntimeException $e ) {
			return $e->getMessage();
		}
		return 'SIN-SALIDA';
	};
	$aviso_de = static function ( int $uid ) {
		$a = get_transient( 'asde_ap_pwa_aviso_' . $uid );
		delete_transient( 'asde_ap_pwa_aviso_' . $uid );
		return is_array( $a ) ? $a : array( '', '' );
	};
	wp_set_current_user( $u1 );
	$_POST = $_REQUEST = array( '_wpnonce' => wp_create_nonce( 'asde_ap_pwa_prueba' ) );
	$ok( 'DIE' === $lanzar(), 'un suscriptor no puede mandar avisos de prueba' );
	wp_set_current_user( $admin );
	$_POST = $_REQUEST = array();
	$ok( 'DIE' === $lanzar(), 'sin nonce, tampoco' );
	$_POST = $_REQUEST = array( '_wpnonce' => wp_create_nonce( 'asde_ap_pwa_comprobar' ) );
	$ok( 'DIE' === $lanzar(), 'con el nonce de otra acción, tampoco' );
	$_POST = $_REQUEST = array( '_wpnonce' => wp_create_nonce( 'asde_ap_pwa_prueba' ) );
	$pet = array();
	add_filter( 'pre_http_request', $sim, 1, 3 );
	$salida = $lanzar();
	$a      = $aviso_de( $admin );
	$ok( 'REDIR:' . PwaPage::url() . '#asde-pwa-push' === $salida && 'error' === $a[0] && str_contains( $a[1], 'ningún móvil' ) && ! $pet, 'sin móviles suscritos: lo explica y no envía nada' );
	$nuevo_disp( $admin );
	$nuevo_disp( $admin );
	$_POST = $_REQUEST = array( '_wpnonce' => wp_create_nonce( 'asde_ap_pwa_prueba' ) );
	$cod   = 201;
	$lanzar();
	$a = $aviso_de( $admin );
	$ok( 'success' === $a[0] && str_contains( $a[1], '2 móvil' ) && str_contains( $a[1], 'Tienes una novedad' ) && 2 === count( $pet ), 'con dos móviles: envía a los dos al momento y lo dice' );
	$cod = 410;
	$_POST = $_REQUEST = array( '_wpnonce' => wp_create_nonce( 'asde_ap_pwa_prueba' ) );
	$lanzar();
	$a = $aviso_de( $admin );
	$ok( 'error' === $a[0] && str_contains( $a[1], '0 de 2' ) && str_contains( $a[1], '410' ) && 0 === Suscripciones::cuantos( $admin ), 'si el servicio dice 410: avisa, da el código y quita los móviles muertos' );
	Ajustes::guardar( true, '', false );
	$_POST = $_REQUEST = array( '_wpnonce' => wp_create_nonce( 'asde_ap_pwa_prueba' ) );
	$lanzar();
	$a = $aviso_de( $admin );
	$ok( 'error' === $a[0] && str_contains( $a[1], 'no están en marcha' ), 'con los avisos apagados: lo dice' );
	Ajustes::guardar( true, '', true );
	ob_start();
	( new PwaPage() )->render();
	$html = (string) ob_get_clean();
	$ok( str_contains( $html, 'Enviar aviso de prueba a mi móvil' ) && str_contains( $html, 'asde_ap_pwa_prueba' ) && ! str_contains( $html, 'envío llega en la siguiente versión' ), 'la pantalla tiene el botón y ya no dice que el envío «llega después»' );
	$acc = $wpdb->get_var( $wpdb->prepare( 'SELECT detalles FROM ' . Logger::table() . " WHERE id > %d AND accion = 'push_prueba' ORDER BY id DESC LIMIT 1", $log_antes ) ); // phpcs:ignore WordPress.DB
	$ok( is_string( $acc ) && str_contains( $acc, '"moviles"' ) && ! str_contains( $acc, 'https' ), 'la auditoría anota la prueba (cuántos móviles), sin direcciones' );
	remove_filter( 'pre_http_request', $sim, 1 );
} finally {
	remove_all_filters( 'wp_redirect', 1 );
	remove_all_filters( 'wp_die_handler' );
	remove_filter( 'pre_http_request', $simula ?? '__return_false', 1 );
	remove_filter( 'pre_http_request', $sim ?? '__return_false', 1 );
	$_POST    = $post_antes;
	$_REQUEST = $req_antes;
	wp_set_current_user( 0 );
	foreach ( $ids_usuarios as $id ) {
		wp_delete_user( (int) $id );
	}
	null === $pwa_antes ? delete_option( Ajustes::OPCION ) : update_option( Ajustes::OPCION, $pwa_antes, false );
	null === $vapid_antes ? delete_option( Vapid::OPCION ) : update_option( Vapid::OPCION, $vapid_antes, false );
	null === $cola_antes ? delete_option( Push::COLA ) : update_option( Push::COLA, $cola_antes, false );
	delete_transient( 'asde_ap_push_lock' );
	wp_clear_scheduled_hook( Push::HOOK_COLA );
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Logger::table() . " WHERE id > %d AND accion IN ( 'push_lote', 'push_prueba' )", $log_antes ) ); // phpcs:ignore WordPress.DB
}

echo "\n── Limpieza\n";
$ok( $pwa_antes === get_option( Ajustes::OPCION, null ) && $vapid_antes === get_option( Vapid::OPCION, null ) && $cola_antes === get_option( Push::COLA, null ), 'las opciones y la cola vuelven a estar como estaban' );
$ok( 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users} WHERE user_login LIKE 'prueba-pwa-env-%'" ), 'sin usuarios de la prueba' ); // phpcs:ignore WordPress.DB
$ok( false === wp_next_scheduled( Push::HOOK_COLA ), 'sin pasadas programadas' );

echo $fails ? "\n$fails FALLOS\n" : "\nTODO OK (ninguna petición de red; el sitio queda como estaba)\n";
