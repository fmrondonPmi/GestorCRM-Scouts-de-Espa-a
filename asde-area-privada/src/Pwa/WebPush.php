<?php
namespace AsdeBalears\AreaPrivada\Pwa;

defined( 'ABSPATH' ) || exit;

/**
 * Envío de un mensaje Web Push (docs/pwa/PUSH.md, paso 7b), sin librerías externas:
 *  - cifrado del mensaje, RFC 8291 (`aes128gcm`): ECDH P-256, HKDF y AES-128-GCM de OpenSSL;
 *  - identificación del servidor, RFC 8292 (VAPID): JWT firmado con ES256 y las claves de `Vapid`;
 *  - petición HTTP al servicio de avisos del navegador, con la respuesta clasificada.
 * El mensaje que se cifra es siempre `{"u": "<ruta>"}`: nunca texto ni datos de personas (el texto del aviso
 * lo pone el service worker, genérico). Los vectores del RFC 8291 están en tests/lib/Rfc8291.php.
 */
final class WebPush {

	public const OK         = 'ok';
	public const MUERTA     = 'muerta';     // El servicio de avisos dice que la suscripción ya no existe (404/410).
	public const REINTENTAR = 'reintentar'; // Fallo pasajero (red, 429, 5xx).
	public const ERROR      = 'error';      // Rechazo que no se arregla repitiendo (clave, tamaño…).

	private const REGISTRO = 4096; // Tamaño de registro (rs) del cifrado aes128gcm.

	// ---------------------------------------------------------------------
	// Cifrado (RFC 8291)
	// ---------------------------------------------------------------------

	/** PEM de una clave pública P-256 a partir del punto sin comprimir (65 bytes). */
	public static function pem_publica( string $punto ): string {
		$der = hex2bin( '3059301306072a8648ce3d020106082a8648ce3d030107034200' ) . $punto;
		return "-----BEGIN PUBLIC KEY-----\n" . chunk_split( base64_encode( $der ), 64, "\n" ) . "-----END PUBLIC KEY-----\n";
	}

	/** PEM de una clave privada P-256 (SEC1) a partir del escalar (32 bytes) y su punto público (65 bytes). */
	public static function pem_privada( string $d, string $punto ): string {
		$der = hex2bin( '30770201010420' ) . $d . hex2bin( 'a00a06082a8648ce3d030107a14403420' . '0' ) . $punto;
		return "-----BEGIN EC PRIVATE KEY-----\n" . chunk_split( base64_encode( $der ), 64, "\n" ) . "-----END EC PRIVATE KEY-----\n";
	}

	/**
	 * Claves derivadas del cifrado (para cifrar y para las pruebas con los vectores del RFC).
	 *
	 * @param string $ua_publica Clave pública del navegador (65 bytes).
	 * @param string $auth       Secreto de autenticación del navegador (16 bytes).
	 * @param string $as_privada PEM de la clave privada efímera del servidor.
	 * @param string $as_publica Clave pública efímera del servidor (65 bytes).
	 * @param string $sal        Sal aleatoria (16 bytes).
	 * @return array{ecdh:string, prk_key:string, ikm:string, prk:string, cek:string, nonce:string}|null
	 */
	public static function derivar( string $ua_publica, string $auth, string $as_privada, string $as_publica, string $sal ): ?array {
		$par = openssl_pkey_get_public( self::pem_publica( $ua_publica ) );
		$yo  = openssl_pkey_get_private( $as_privada );
		if ( ! $par || ! $yo ) {
			return null;
		}
		$ecdh = openssl_pkey_derive( $par, $yo, 32 );
		if ( ! is_string( $ecdh ) || 32 !== strlen( $ecdh ) ) {
			return null;
		}
		$prk_key = hash_hmac( 'sha256', $ecdh, $auth, true ); // HKDF-Extract(salt = auth_secret, IKM = ecdh_secret).
		$ikm     = hash_hkdf( 'sha256', $ecdh, 32, "WebPush: info\0" . $ua_publica . $as_publica, $auth );
		$prk     = hash_hmac( 'sha256', $ikm, $sal, true ); // HKDF-Extract(salt, IKM).
		return array(
			'ecdh'    => $ecdh,
			'prk_key' => $prk_key,
			'ikm'     => $ikm,
			'prk'     => $prk,
			'cek'     => hash_hkdf( 'sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $sal ),
			'nonce'   => hash_hkdf( 'sha256', $ikm, 12, "Content-Encoding: nonce\0", $sal ),
		);
	}

	/**
	 * Cifra un mensaje para un navegador (cuerpo `aes128gcm`: cabecera de 86 bytes + texto cifrado).
	 *
	 * @param string                                             $mensaje   Texto en claro (menos de unos 4 000 bytes).
	 * @param array{privada_pem:string, publica:string}|null     $servidor  Par efímero del servidor; null = uno nuevo (lo normal).
	 * @param string|null                                        $sal       Sal; null = aleatoria (lo normal; fija solo en las pruebas).
	 */
	public static function cifrar( string $mensaje, string $ua_publica, string $auth, ?array $servidor = null, ?string $sal = null ): ?string {
		if ( 65 !== strlen( $ua_publica ) || 16 !== strlen( $auth ) || strlen( $mensaje ) > self::REGISTRO - 17 - 86 ) {
			return null;
		}
		if ( null === $servidor ) {
			$par = Vapid::generar();
			if ( ! $par ) {
				return null;
			}
			$servidor = array(
				'privada_pem' => $par['pem'],
				'publica'     => Vapid::de_base64url( $par['publica'] ),
			);
		}
		$sal = $sal ?? random_bytes( 16 );
		$k   = self::derivar( $ua_publica, $auth, $servidor['privada_pem'], $servidor['publica'], $sal );
		if ( ! $k ) {
			return null;
		}
		$etiqueta = '';
		$cifrado  = openssl_encrypt( $mensaje . "\x02", 'aes-128-gcm', $k['cek'], OPENSSL_RAW_DATA, $k['nonce'], $etiqueta, '', 16 ); // 0x02: último registro, sin relleno.
		if ( false === $cifrado || 16 !== strlen( $etiqueta ) ) {
			return null;
		}
		return $sal . pack( 'N', self::REGISTRO ) . chr( 65 ) . $servidor['publica'] . $cifrado . $etiqueta;
	}

	// ---------------------------------------------------------------------
	// VAPID (RFC 8292)
	// ---------------------------------------------------------------------

	/** Contacto que se declara al servicio de avisos (para que puedan escribir si algo va mal). */
	public static function contacto(): string {
		$email = (string) apply_filters( 'asde_ap_push_contacto', (string) get_option( 'admin_email' ) );
		return is_email( $email ) ? 'mailto:' . $email : home_url( '/' );
	}

	/** Firma ES256 en formato R‖S (64 bytes) a partir de la firma DER de OpenSSL. */
	public static function firma_cruda( string $der ): ?string {
		if ( strlen( $der ) < 8 || "\x30" !== $der[0] ) {
			return null;
		}
		$o = 2;
		if ( ord( $der[1] ) & 0x80 ) { // Longitud en dos bytes.
			$o = 2 + ( ord( $der[1] ) & 0x7f );
		}
		$partes = array();
		for ( $i = 0; $i < 2; $i++ ) {
			if ( ! isset( $der[ $o + 1 ] ) || "\x02" !== $der[ $o ] ) {
				return null;
			}
			$n        = ord( $der[ $o + 1 ] );
			$partes[] = str_pad( ltrim( substr( $der, $o + 2, $n ), "\0" ), 32, "\0", STR_PAD_LEFT );
			$o       += 2 + $n;
		}
		return 32 === strlen( $partes[0] ) && 32 === strlen( $partes[1] ) ? $partes[0] . $partes[1] : null;
	}

	/** JWT de VAPID para el servicio de avisos de un endpoint (audiencia = su origen). */
	public static function jwt( string $endpoint, ?int $exp = null, ?string $clave_pem = null ): ?string {
		$p = wp_parse_url( $endpoint );
		if ( ! is_array( $p ) || empty( $p['scheme'] ) || empty( $p['host'] ) ) {
			return null;
		}
		$pem = $clave_pem ?? Vapid::privada_pem();
		$k   = $pem ? openssl_pkey_get_private( $pem ) : false;
		if ( ! $k ) {
			return null;
		}
		$cab     = Vapid::base64url( (string) wp_json_encode( array( 'typ' => 'JWT', 'alg' => 'ES256' ) ) );
		$cuerpo  = Vapid::base64url(
			(string) wp_json_encode(
				array(
					'aud' => $p['scheme'] . '://' . $p['host'],
					'exp' => $exp ?? time() + 12 * HOUR_IN_SECONDS, // El máximo permitido es 24 h.
					'sub' => self::contacto(),
				)
			)
		);
		$der = '';
		if ( ! openssl_sign( $cab . '.' . $cuerpo, $der, $k, OPENSSL_ALGO_SHA256 ) ) {
			return null;
		}
		$cruda = self::firma_cruda( $der );
		return null === $cruda ? null : $cab . '.' . $cuerpo . '.' . Vapid::base64url( $cruda );
	}

	// ---------------------------------------------------------------------
	// Envío
	// ---------------------------------------------------------------------

	/** Clasifica la respuesta del servicio de avisos. */
	public static function clasificar( int $codigo ): string {
		if ( $codigo >= 200 && $codigo < 300 ) {
			return self::OK;
		}
		if ( 404 === $codigo || 410 === $codigo ) {
			return self::MUERTA;
		}
		if ( 429 === $codigo || $codigo >= 500 || 0 === $codigo ) {
			return self::REINTENTAR;
		}
		return self::ERROR;
	}

	/**
	 * Manda un aviso a un dispositivo. El mensaje lleva solo la ruta de destino.
	 *
	 * @param array{endpoint:string, p256dh:string, auth:string} $sub  Dispositivo (descifrado).
	 * @param string                                             $ruta Ruta o dirección dentro de la app a la que llevar al pulsar el aviso.
	 * @return array{estado:string, codigo:int, espera:int} `espera`: segundos que pide el servicio antes de reintentar (0 si no dice).
	 */
	public static function enviar( array $sub, string $ruta ): array {
		$fallo = array(
			'estado' => self::ERROR,
			'codigo' => -1,
			'espera' => 0,
		);
		if ( ! Suscripciones::endpoint_valido( $sub['endpoint'] ) ) {
			return $fallo; // Nunca se hace una petición a una dirección que no sea de un servicio de avisos conocido (SSRF).
		}
		$cuerpo = self::cifrar( (string) wp_json_encode( array( 'u' => $ruta ), JSON_UNESCAPED_SLASHES ), Vapid::de_base64url( $sub['p256dh'] ), Vapid::de_base64url( $sub['auth'] ) );
		$jwt    = self::jwt( $sub['endpoint'] );
		if ( null === $cuerpo || null === $jwt ) {
			return $fallo;
		}
		$r = wp_safe_remote_post(
			$sub['endpoint'],
			array(
				'timeout'     => 10,
				'redirection' => 0,
				'headers'     => array(
					'Authorization'    => 'vapid t=' . $jwt . ', k=' . Vapid::publica(),
					'Content-Encoding' => 'aes128gcm',
					'Content-Type'     => 'application/octet-stream',
					'TTL'              => (string) DAY_IN_SECONDS,
					'Urgency'          => 'normal',
				),
				'body'        => $cuerpo,
			)
		);
		if ( is_wp_error( $r ) ) {
			return array(
				'estado' => self::REINTENTAR,
				'codigo' => 0,
				'espera' => 0,
			);
		}
		$codigo = (int) wp_remote_retrieve_response_code( $r );
		$espera = (int) wp_remote_retrieve_header( $r, 'retry-after' );
		return array(
			'estado' => self::clasificar( $codigo ),
			'codigo' => $codigo,
			'espera' => max( 0, min( $espera, HOUR_IN_SECONDS ) ),
		);
	}
}
