<?php
/**
 * Plugin Name: ASDE · traducciones del repo (solo desarrollo)
 * Description: En el WordPress de Local carga los .mo que genera herramientas/generar-po.php (traducciones/mo/) en lugar
 *              de los paquetes de idioma de translate.wordpress.org, también al cambiar de idioma (switch_to_locale).
 *              Se copia a wp-content/mu-plugins/ (doc 62). No va en el zip.
 */

defined( 'ABSPATH' ) || exit;

add_filter(
	'lang_dir_for_domain',
	static function ( $ruta, $dominio, $idioma ) {
		if ( 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' !== $dominio ) {
			return $ruta;
		}
		$mo = rtrim( getenv( 'ASDE_REPO_TRADUCCIONES' ) ?: 'C:/Proyectos/GestorCRM/traducciones', '/\\' ) . '/mo/';
		return is_readable( $mo . $dominio . '-' . $idioma . '.mo' ) ? $mo : $ruta;
	},
	10,
	3
);

// switch_to_locale() solo cambia a idiomas «instalados»: en Local se dan por instalados los del plugin.
add_filter(
	'get_available_languages',
	static function ( $idiomas ) {
		return array_values( array_unique( array_merge( (array) $idiomas, array( 'es_ES', 'gl_ES', 'eu', 'ca', 'ca_valencia' ) ) ) );
	}
);
