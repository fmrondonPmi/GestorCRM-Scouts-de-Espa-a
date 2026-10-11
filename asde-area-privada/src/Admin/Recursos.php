<?php
namespace AsdeBalears\AreaPrivada\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Estilos y JavaScript comunes de las páginas del plugin en wp-admin (v0.31).
 *
 * WordPress pide no poner <style>, <script> ni atributos onsubmit/onclick en las páginas:
 * se encolan con admin_enqueue_scripts. Lo propio de cada página (el selector de colores,
 * los conceptos de la organización…) lo sigue encolando su clase.
 */
final class Recursos {

	public function register(): void {
		add_action( 'admin_enqueue_scripts', array( $this, 'encolar' ) );
	}

	public function encolar( string $pantalla ): void {
		if ( ! str_contains( $pantalla, 'asde-area-privada' ) ) {
			return;
		}
		wp_enqueue_style( 'asde-admin', plugins_url( 'assets/css/admin.css', ASDE_AP_FILE ), array(), ASDE_AP_VERSION );
		wp_enqueue_script( 'asde-admin', plugins_url( 'assets/js/admin.js', ASDE_AP_FILE ), array(), ASDE_AP_VERSION, true );
	}
}
