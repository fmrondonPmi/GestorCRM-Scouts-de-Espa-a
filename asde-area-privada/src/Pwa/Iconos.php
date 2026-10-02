<?php
namespace AsdeBalears\AreaPrivada\Pwa;

defined( 'ABSPATH' ) || exit;

/**
 * Iconos de la PWA (docs/pwa/ARQUITECTURA.md, D4): primero el «Icono del sitio» de
 * WordPress (Apariencia → Personalizar → Identidad del sitio, 512 px como mínimo) y,
 * si no hay, unos iconos genéricos del plugin (morado, sin marca de nadie).
 */
final class Iconos {

	/** ¿Hay un icono del sitio puesto en WordPress? */
	public static function del_sitio(): bool {
		return (int) get_option( 'site_icon' ) > 0 && '' !== (string) get_site_icon_url( 512 );
	}

	/**
	 * Iconos del manifiesto.
	 *
	 * @return array<int, array{src:string, sizes:string, type:string, purpose:string}>
	 */
	public static function manifiesto(): array {
		if ( self::del_sitio() ) {
			return array(
				array(
					'src'     => (string) get_site_icon_url( 192 ),
					'sizes'   => '192x192',
					'type'    => 'image/png',
					'purpose' => 'any',
				),
				array(
					'src'     => (string) get_site_icon_url( 512 ),
					'sizes'   => '512x512',
					'type'    => 'image/png',
					'purpose' => 'any',
				),
			);
		}
		return array(
			array(
				'src'     => self::generico( 'icono-192.png' ),
				'sizes'   => '192x192',
				'type'    => 'image/png',
				'purpose' => 'any',
			),
			array(
				'src'     => self::generico( 'icono-512.png' ),
				'sizes'   => '512x512',
				'type'    => 'image/png',
				'purpose' => 'any',
			),
			array(
				'src'     => self::generico( 'icono-maskable-512.png' ),
				'sizes'   => '512x512',
				'type'    => 'image/png',
				'purpose' => 'maskable',
			),
		);
	}

	/** Icono para la pantalla de inicio del iPhone (180 px). */
	public static function apple(): string {
		if ( self::del_sitio() ) {
			return (string) get_site_icon_url( 180 );
		}
		return self::generico( 'icono-apple-180.png' );
	}

	private static function generico( string $fichero ): string {
		return plugins_url( 'assets/pwa/' . $fichero, ASDE_AP_FILE );
	}
}
