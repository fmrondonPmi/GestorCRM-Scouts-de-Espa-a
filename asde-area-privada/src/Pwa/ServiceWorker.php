<?php
namespace AsdeBalears\AreaPrivada\Pwa;

defined( 'ABSPATH' ) || exit;

/**
 * Service worker de la PWA (docs/pwa/ARQUITECTURA.md, D1 y D6). El JS está en
 * assets/pwa/sw.js; aquí se le pone la configuración de este WordPress.
 */
final class ServiceWorker {

	public const RUTA = 'asde-sw.js';

	/** Página «sin conexión»: estática y sin datos de nadie. */
	public static function url_sin_conexion(): string {
		return plugins_url( 'assets/pwa/sin-conexion.html', ASDE_AP_FILE );
	}

	/** Carpeta (ruta) de los ficheros estáticos del plugin: lo único que se guarda además de «sin conexión». */
	public static function ruta_estaticos(): string {
		return (string) wp_parse_url( plugins_url( 'assets/', ASDE_AP_FILE ), PHP_URL_PATH );
	}

	/** @return array{version:string, offline:string, precache:string[], estaticos:string} */
	public static function config(): array {
		return array(
			'version'   => ASDE_AP_VERSION,
			'offline'   => self::url_sin_conexion(),
			'precache'  => array( self::url_sin_conexion() ),
			'estaticos' => self::ruta_estaticos(),
		);
	}

	/** El JS listo para servir (con la configuración puesta). */
	public static function contenido(): string {
		$js = (string) file_get_contents( ASDE_AP_DIR . 'assets/pwa/sw.js' );
		return str_replace( '__ASDE_CONFIG__', (string) wp_json_encode( self::config(), JSON_UNESCAPED_SLASHES ), $js );
	}
}
