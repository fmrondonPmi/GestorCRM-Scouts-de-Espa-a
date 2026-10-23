<?php
/**
 * Plugin Name:       ASDE Scouts de España - Gestor CRM para Grupos Scouts
 * Description:       Área privada y gestión de los Grupos Scouts (socios, usuarios, informes y tesorería) con datos leídos de SinergiaCRM. Cada grupo con su propio acceso al CRM y sin visibilidad entre grupos.
 * Version:           0.56.0
 * Requires at least: 6.4
 * Requires PHP:      8.0
 * Author:            Francisco Meri - Zorro Sabio
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       asde-area-privada
 */

defined( 'ABSPATH' ) || exit;

define( 'ASDE_AP_VERSION', '0.56.0' );
define( 'ASDE_AP_FILE', __FILE__ );
define( 'ASDE_AP_DIR', plugin_dir_path( __FILE__ ) );

spl_autoload_register(
	static function ( string $class ): void {
		$prefix = 'AsdeBalears\\AreaPrivada\\';
		if ( ! str_starts_with( $class, $prefix ) ) {
			return;
		}
		$file = ASDE_AP_DIR . 'src/' . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
		if ( is_readable( $file ) ) {
			require $file;
		}
	}
);

// asde_t(), asde_h()…: los textos del área en el idioma de cada persona (v0.15, doc 28).
require_once ASDE_AP_DIR . 'src/Idiomas/funciones.php';

register_activation_hook( __FILE__, array( \AsdeBalears\AreaPrivada\Install\Installer::class, 'install' ) );
register_deactivation_hook( __FILE__, array( \AsdeBalears\AreaPrivada\Install\Installer::class, 'deactivate' ) );
add_action( 'plugins_loaded', array( \AsdeBalears\AreaPrivada\Plugin::class, 'boot' ) );
