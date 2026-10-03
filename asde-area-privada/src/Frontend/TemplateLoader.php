<?php
namespace AsdeBalears\AreaPrivada\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * Plantillas sobreescribibles desde el tema:
 *   {tema-hijo}/asde-area-privada/{nombre}.php  →  {tema}/…  →  plugin/templates/{nombre}.php
 * Otro plugin (la app móvil) puede poner sus plantillas con el filtro `asde_ap_plantilla`.
 */
final class TemplateLoader {

	public static function locate( string $name ): string {
		if ( ! preg_match( '#^[a-z0-9_-]+(/[a-z0-9_-]+)*$#', $name ) ) {
			return '';
		}
		$theme = locate_template( 'asde-area-privada/' . $name . '.php' );
		return $theme ?: (string) apply_filters( 'asde_ap_plantilla', ASDE_AP_DIR . 'templates/' . $name . '.php', $name );
	}

	/** @param array<string, mixed> $vars Variables disponibles en la plantilla. */
	public static function render( string $name, array $vars = array() ): string {
		$file = self::locate( $name );
		if ( '' === $file || ! is_readable( $file ) ) {
			return '';
		}
		ob_start();
		( static function ( string $__file, array $__vars ): void {
			extract( $__vars, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract
			include $__file;
		} )( $file, $vars );
		return (string) ob_get_clean();
	}
}
