<?php
namespace AsdeBalears\AreaPrivada\Pwa;

use AsdeBalears\AreaPrivada\Frontend\Colores;
use AsdeBalears\AreaPrivada\Frontend\Pages;
use AsdeBalears\AreaPrivada\Idiomas\Idioma;

defined( 'ABSPATH' ) || exit;

/**
 * Manifiesto de la aplicación web (docs/pwa/ARQUITECTURA.md, D2 y D5).
 * Es el mismo para todo el mundo y no lleva ningún dato personal: los navegadores
 * lo piden sin sesión.
 */
final class Manifiesto {

	/** Morado de siempre (doc 06): el que se usa si el grupo no ha elegido otro color. */
	public const COLOR_TEMA  = '#632599';
	public const COLOR_FONDO = '#ffffff';

	/**
	 * Colores del grupo (pantalla «Colores», v0.15): los elegidos o los de siempre, siempre en #rrggbb.
	 *
	 * @return array{primary:string, on_primary:string, texto:string, atenuado:string}
	 */
	public static function colores(): array {
		$c   = Colores::para_email();
		$def = array(
			'primary'    => self::COLOR_TEMA,
			'on_primary' => '#ffffff',
			'texto'      => '#444444',
			'atenuado'   => '#555555',
		);
		$out = array();
		foreach ( $def as $clave => $valor ) {
			$out[ $clave ] = ( isset( $c[ $clave ] ) && 1 === preg_match( '/^#[0-9a-f]{6}$/i', (string) $c[ $clave ] ) ) ? (string) $c[ $clave ] : $valor;
		}
		return $out;
	}

	/** Color del tema: la barra del navegador y la de estado del móvil. */
	public static function color_tema(): string {
		return self::colores()['primary'];
	}

	/** Nombre corto (bajo el icono). */
	public static function nombre_corto(): string {
		return (string) apply_filters( 'asde_ap_pwa_nombre_corto', asde_t( 'Área privada' ) );
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
		$lang    = Idioma::actual();

		return array(
			'id'               => $alcance,
			'name'             => self::nombre(),
			'short_name'       => self::nombre_corto(),
			'description'      => asde_t( 'Área privada del grupo scout: fichas, pagos y gestión del grupo.' ),
			'lang'             => '' === $lang ? 'es' : $lang,
			'dir'              => 'ltr',
			'start_url'        => $inicio,
			'scope'            => $alcance,
			'display'          => 'standalone',
			'background_color' => self::COLOR_FONDO,
			'theme_color'      => self::color_tema(),
			'icons'            => Iconos::manifiesto(),
		);
	}
}
