<?php
namespace AsdeBalears\AreaPrivada\Idiomas;

defined( 'ABSPATH' ) || exit;

/**
 * Idiomas del área privada (v0.15, doc 28): castellano, catalán, euskera y gallego.
 *
 * Qué idioma se usa en cada petición, por orden:
 *   1. el que se fuerza desde el código (emails, pruebas) — `forzar()`;
 *   2. el que ha elegido la persona (si la administración deja elegir): en su usuario si ha
 *      entrado, o en una cookie si no (pantallas de acceso y de solicitud);
 *   3. el idioma por defecto del sitio (wp-admin → Grupos Scouts → Idioma y etiquetas).
 *
 * Los textos del código están escritos en castellano y ese texto es la clave de traducción
 * (ver Traducciones): con el idioma «es» no se traduce nada.
 */
final class Idioma {

	public const OPCION  = 'asde_ap_idioma';
	public const META    = 'asde_ap_idioma';
	public const COOKIE  = 'asde_ap_lang';
	public const DEFECTO = 'es';
	/** Parámetro de las direcciones que cambian el idioma (con nonce). */
	public const PARAM   = 'asde_lang';
	public const NONCE   = 'asde_lang';

	private static ?string $forzado  = null;
	private static ?string $sugerido = null;
	private static ?string $cache   = null;

	/**
	 * Idiomas oficiales de España con lengua propia.
	 *
	 * @return array<string, string> código => nombre en su propia lengua
	 */
	public static function disponibles(): array {
		return array(
			'es' => 'Castellano',
			'ca' => 'Català',
			'eu' => 'Euskara',
			'gl' => 'Galego',
		);
	}

	public static function es_valido( $codigo ): bool {
		return is_string( $codigo ) && isset( self::disponibles()[ $codigo ] );
	}

	public static function nombre( string $codigo ): string {
		return self::disponibles()[ $codigo ] ?? $codigo;
	}

	// ---------------------------------------------------------------------
	// Ajustes del sitio
	// ---------------------------------------------------------------------

	/** @return array{por_defecto:string, elegir:bool} */
	public static function ajustes(): array {
		$a = get_option( self::OPCION, array() );
		$a = is_array( $a ) ? $a : array();
		return array(
			'por_defecto' => self::es_valido( $a['por_defecto'] ?? null ) ? $a['por_defecto'] : self::DEFECTO,
			'elegir'      => array_key_exists( 'elegir', $a ) ? (bool) $a['elegir'] : true,
		);
	}

	public static function por_defecto(): string {
		return self::ajustes()['por_defecto'];
	}

	/** ¿Cada persona puede elegir su idioma? */
	public static function se_puede_elegir(): bool {
		return self::ajustes()['elegir'];
	}

	public static function guardar_ajustes( string $por_defecto, bool $elegir ): bool {
		if ( ! self::es_valido( $por_defecto ) ) {
			return false;
		}
		update_option(
			self::OPCION,
			array(
				'por_defecto' => $por_defecto,
				'elegir'      => $elegir,
			),
			false
		);
		self::$cache = null;
		return true;
	}

	// ---------------------------------------------------------------------
	// Qué idioma toca ahora
	// ---------------------------------------------------------------------

	/** Fuerza un idioma (null: deja de forzarlo). Sirve para los emails y para las pruebas. */
	public static function forzar( ?string $codigo ): void {
		self::$forzado = self::es_valido( $codigo ) ? $codigo : null;
		self::$cache   = null;
	}

	/** Ejecuta algo en un idioma y deja el anterior como estaba. @return mixed */
	public static function en( string $codigo, callable $fn ) {
		$antes = self::$forzado;
		self::forzar( $codigo );
		try {
			return $fn();
		} finally {
			self::forzar( $antes );
		}
	}

	public static function actual(): string {
		if ( null !== self::$forzado ) {
			return self::$forzado;
		}
		if ( null !== self::$cache ) {
			return self::$cache;
		}
		$codigo = self::por_defecto();
		if ( self::se_puede_elegir() ) {
			$elegido = self::elegido();
			if ( null !== $elegido ) {
				$codigo = $elegido;
			}
		}
		return self::$cache = $codigo;
	}

	/** Idioma elegido por la persona (usuario o cookie), o null. */
	public static function elegido( ?int $user_id = null ): ?string {
		$user_id = $user_id ?? (int) get_current_user_id();
		if ( $user_id ) {
			$meta = get_user_meta( $user_id, self::META, true );
			if ( self::es_valido( $meta ) ) {
				return $meta;
			}
		}
		$cookie = $_COOKIE[ self::COOKIE ] ?? null; // phpcs:ignore WordPress.Security
		return self::es_valido( $cookie ) ? $cookie : null;
	}

	/**
	 * Guarda el idioma elegido: en el usuario si ha entrado y, si no, en una cookie de un año.
	 * Devuelve false si el idioma no existe o no se deja elegir.
	 */
	public static function elegir( string $codigo, ?int $user_id = null, bool $poner_cookie = true ): bool {
		if ( ! self::es_valido( $codigo ) || ! self::se_puede_elegir() ) {
			return false;
		}
		$user_id = $user_id ?? (int) get_current_user_id();
		if ( $user_id ) {
			update_user_meta( $user_id, self::META, $codigo );
		}
		if ( $poner_cookie ) {
			if ( ! headers_sent() ) {
				setcookie( self::COOKIE, $codigo, time() + YEAR_IN_SECONDS, COOKIEPATH ?: '/', COOKIE_DOMAIN, is_ssl(), true );
			}
			$_COOKIE[ self::COOKIE ] = $codigo; // Para el resto de esta misma petición.
		}
		self::$cache = null;
		return true;
	}

	/** Dirección que cambia el idioma y vuelve a esta misma página (con nonce: cambia un ajuste personal). */
	public static function url_cambio( string $codigo, string $volver = '' ): string {
		$volver = '' !== $volver ? $volver : remove_query_arg( array( self::PARAM, '_wpnonce' ) );
		return add_query_arg(
			array(
				self::PARAM => $codigo,
				'_wpnonce'  => wp_create_nonce( self::NONCE ),
			),
			$volver
		);
	}

	/**
	 * Atiende ?asde_lang=xx (Router::template_redirect). Devuelve true si ha cambiado el idioma, para
	 * que quien llama redirija a la misma página sin el parámetro.
	 */
	public static function atender_peticion(): bool {
		if ( ! isset( $_GET[ self::PARAM ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return false;
		}
		$codigo = sanitize_key( wp_unslash( (string) $_GET[ self::PARAM ] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ?? '' ) ), self::NONCE ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return false;
		}
		return self::elegir( $codigo );
	}

	/**
	 * Idioma de una persona que NO es quien está usando la web (el destinatario de un email): el que
	 * eligió, o el sugerido (el de la solicitud que se está aprobando) o el del sitio.
	 */
	public static function de_usuario( int $user_id ): string {
		$meta = $user_id ? get_user_meta( $user_id, self::META, true ) : '';
		if ( self::es_valido( $meta ) ) {
			return $meta;
		}
		return self::es_valido( self::$sugerido ) ? (string) self::$sugerido : self::por_defecto();
	}

	/** Idioma que se da a las cuentas que se creen ahora (null: ninguno): el de la solicitud de acceso que se aprueba. */
	public static function sugerir( ?string $codigo ): void {
		self::$sugerido = self::es_valido( $codigo ) ? $codigo : null;
	}

	/** Se reinicia lo calculado (cambio de usuario en una misma petición, pruebas). */
	public static function olvidar(): void {
		self::$cache = null;
	}
}
