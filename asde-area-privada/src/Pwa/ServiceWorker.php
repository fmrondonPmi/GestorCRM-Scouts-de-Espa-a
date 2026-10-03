<?php
namespace AsdeBalears\AreaPrivada\Pwa;

use AsdeBalears\AreaPrivada\Frontend\TemplateLoader;
use AsdeBalears\AreaPrivada\Idiomas\Idioma;

defined( 'ABSPATH' ) || exit;

/**
 * Service worker de la PWA (docs/pwa/ARQUITECTURA.md, D1 y D6). El JS está en
 * assets/pwa/sw.js; aquí se le pone la configuración de este WordPress.
 */
final class ServiceWorker {

	public const RUTA              = 'asde-sw.js';
	public const RUTA_SIN_CONEXION = 'asde-sin-conexion';

	/** Dirección de la página «sin conexión» (la genera PHP: color del grupo e idioma de quien la pide). */
	public static function url_sin_conexion(): string {
		return home_url( '/' . self::RUTA_SIN_CONEXION );
	}

	/** HTML de la página «sin conexión»: sin datos de nadie y sin recursos externos. */
	public static function pagina_sin_conexion(): string {
		$c = Manifiesto::colores();
		return TemplateLoader::render(
			'pwa/sin-conexion',
			array(
				'lang'     => Idioma::actual(),
				'primario' => $c['primary'],
				'sobre'    => $c['on_primary'],
				'texto'    => $c['texto'],
				'atenuado' => $c['atenuado'],
			)
		);
	}

	/** Carpeta (ruta) de los ficheros estáticos del plugin: lo único que se guarda además de «sin conexión». */
	public static function ruta_estaticos(): string {
		return (string) wp_parse_url( plugins_url( 'assets/', ASDE_AP_FILE ), PHP_URL_PATH );
	}

	/**
	 * La versión lleva una huella de la página «sin conexión»: si cambian los colores o el idioma,
	 * el service worker cambia, el navegador lo actualiza y vuelve a guardar la página nueva.
	 *
	 * @return array{version:string, offline:string, precache:string[], estaticos:string}
	 */
	public static function config(): array {
		return array(
			'version'   => ASDE_AP_VERSION . '-' . substr( md5( self::pagina_sin_conexion() ), 0, 8 ),
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

	/** Con la PWA apagada: un service worker que borra sus cachés y se da de baja. */
	public static function contenido_apagado(): string {
		return (string) file_get_contents( ASDE_AP_DIR . 'assets/pwa/sw-apagado.js' );
	}
}
