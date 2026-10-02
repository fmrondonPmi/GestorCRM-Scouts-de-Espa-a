<?php
namespace AsdeBalears\AreaPrivada\Pwa;

use AsdeBalears\AreaPrivada\Frontend\Pages;

defined( 'ABSPATH' ) || exit;

/**
 * Manifiesto de la aplicación web (docs/pwa/ARQUITECTURA.md, D2 y D5).
 * Es el mismo para todo el mundo y no lleva ningún dato personal: los navegadores
 * lo piden sin sesión.
 */
final class Manifiesto {

	public const COLOR_TEMA  = '#632599';
	public const COLOR_FONDO = '#ffffff';

	/** Nombre corto (bajo el icono). */
	public static function nombre_corto(): string {
		return (string) apply_filters( 'asde_ap_pwa_nombre_corto', 'Área privada' );
	}

	/** Nombre completo. */
	public static function nombre(): string {
		$sitio  = trim( wp_strip_all_tags( (string) get_bloginfo( 'name' ) ) );
		$nombre = '' === $sitio ? self::nombre_corto() : self::nombre_corto() . ' · ' . $sitio;
		return (string) apply_filters( 'asde_ap_pwa_nombre', $nombre );
	}

	/** Dirección de la página «Área privada» (inicio de la app). */
	public static function inicio(): string {
		return Pages::url( 'area' );
	}

	/**
	 * Alcance: la carpeta de la página «Área privada», de la que cuelgan todas las del área.
	 * Con enlaces permanentes «simples» (?page_id=) no hay carpeta: se usa todo el sitio.
	 */
	public static function alcance(): string {
		$inicio = self::inicio();
		if ( str_contains( $inicio, '?' ) ) {
			return trailingslashit( home_url( '/' ) );
		}
		return trailingslashit( $inicio );
	}

	/** @return array<string, mixed> */
	public static function construir(): array {
		$alcance = self::alcance();
		$inicio  = str_contains( self::inicio(), '?' ) ? $alcance : self::inicio();
		$lang    = str_replace( '_', '-', (string) get_bloginfo( 'language' ) );

		return array(
			'id'               => $alcance,
			'name'             => self::nombre(),
			'short_name'       => self::nombre_corto(),
			'description'      => 'Área privada del grupo scout: fichas, pagos y gestión del grupo.',
			'lang'             => '' === $lang ? 'es' : $lang,
			'dir'              => 'ltr',
			'start_url'        => $inicio,
			'scope'            => $alcance,
			'display'          => 'standalone',
			'background_color' => self::COLOR_FONDO,
			'theme_color'      => self::COLOR_TEMA,
			'icons'            => Iconos::manifiesto(),
		);
	}
}
