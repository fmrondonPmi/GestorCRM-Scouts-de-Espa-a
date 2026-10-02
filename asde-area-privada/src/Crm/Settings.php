<?php
namespace AsdeBalears\AreaPrivada\Crm;

defined( 'ABSPATH' ) || exit;

/**
 * Configuración común de la conexión con SinergiaCRM: la URL, que es la misma
 * para todos los grupos. El usuario y la contraseña son de cada grupo
 * (Domain\Grupos).
 *
 * Se guarda en la opción `asde_ap_crm`. La URL puede fijarse en wp-config.php
 * con la constante ASDE_CRM_URL, que tiene prioridad.
 */
final class Settings {

	public const OPTION       = 'asde_ap_crm';
	public const CONSTANT_URL = 'ASDE_CRM_URL';

	/** Sufijos que se eliminan si se pega la URL completa de un endpoint. */
	private const URL_SUFFIXES = array(
		'/custom/service/v4_1_SticCustom/rest.php',
		'/service/v4_1/rest.php',
		'/Api/access_token',
		'/Api/V8',
		'/index.php',
	);

	public static function stored(): array {
		$value = get_option( self::OPTION, array() );
		return is_array( $value ) ? $value : array();
	}

	public static function url_from_constant(): bool {
		return defined( self::CONSTANT_URL );
	}

	public static function url(): string {
		return self::normalize_url( self::url_from_constant() ? (string) constant( self::CONSTANT_URL ) : (string) ( self::stored()['url'] ?? '' ) );
	}

	public static function normalize_url( string $url ): string {
		$url = untrailingslashit( trim( $url ) );
		foreach ( self::URL_SUFFIXES as $suffix ) {
			if ( str_ends_with( strtolower( $url ), strtolower( $suffix ) ) ) {
				$url = substr( $url, 0, -strlen( $suffix ) );
			}
		}
		return untrailingslashit( esc_url_raw( $url, array( 'https', 'http' ) ) );
	}

	/** sanitize_callback de register_setting(). */
	public static function sanitize( $input ): array {
		$input = is_array( $input ) ? $input : array();
		return array( 'url' => self::normalize_url( (string) ( $input['url'] ?? '' ) ) );
	}
}
