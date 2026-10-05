<?php
namespace AsdeBalears\AreaPrivada\Frontend\Views;

use AsdeBalears\AreaPrivada\Auth\Ambito;
use AsdeBalears\AreaPrivada\Frontend\Menu;
use AsdeBalears\AreaPrivada\Frontend\Notices;
use AsdeBalears\AreaPrivada\Frontend\Pages;
use AsdeBalears\AreaPrivada\Frontend\TemplateLoader;
use AsdeBalears\AreaPrivada\Socios\TareaNumeracion;

defined( 'ABSPATH' ) || exit;

abstract class View {

	/** Se pinta para la ventana emergente (Router, cabecera X-Asde-Modal): sin el menú del área. */
	private static bool $modal = false;

	/** @param string $content Contenido del shortcode (solo lo usan los shortcodes con cierre). */
	abstract public function render( string $content = '' ): string;

	public function handle_post(): void {}

	public static function en_modal( bool $si = true ): void {
		self::$modal = $si;
	}

	public static function es_modal(): bool {
		return self::$modal;
	}

	protected function tpl( string $name, array $vars = array() ): string {
		return TemplateLoader::render( $name, $vars );
	}

	/**
	 * Pestañas del área según los permisos (v0.10: «Dar acceso» sale de Personas y
	 * Usuarios, y «Seguridad» va junto al nombre, arriba a la derecha).
	 *
	 * @return array<string, string> clave de página => etiqueta
	 */
	public static function pestanas( ?Ambito $ambito ): array {
		return Menu::plano( $ambito ); // v0.15: el menú se separa por tipo de usuario (Frontend\Menu).
	}

	/** Envoltorio común: menú del área privada + avisos + contenido. */
	protected function layout( string $activo, string $contenido ): string {
		if ( self::$modal ) {
			return '<div class="asde-ap asde-ap--modal">' . Notices::render() . $contenido . '</div>';
		}
		$ambito = wp_get_current_user()->exists() ? Ambito::actual() : null;
		$user   = wp_get_current_user();
		$items  = self::pestanas( $ambito );
		// Páginas sin pestaña propia: se marca la de la sección a la que pertenecen.
		$marcar = array(
			'alta'             => 'usuarios',
			'emails'           => 'usuarios',
			'uniforme'         => isset( $items['uniforme'] ) ? 'uniforme' : 'uniformes',
			'uniformes_config' => 'uniformes',
			'inventario_tipos'   => 'inventario',
			'inventario_compras' => 'inventario',
		)[ $activo ] ?? $activo;
		return '<div class="asde-ap">'
			. $this->tpl(
				'comun/nav',
				array(
					'items'  => $items,
					'grupos' => Menu::grupos( $ambito ),
					'activo' => $marcar,
					'pagina' => $activo,
					'user'   => $user,
					'nombre' => $user->exists() ? Ambito::actual()->nombre() : '',
				)
			)
			. Notices::render()
			. $this->monitor_tarea( $activo )
			. $contenido
			. '</div>';
	}

	/** Corrección de números en segundo plano: su avance, en cualquier página del área. */
	private function monitor_tarea( string $activo ): string {
		$tarea = TareaNumeracion::para_usuario();
		if ( ! $tarea ) {
			return '';
		}
		return $this->tpl(
			'comun/tarea',
			array(
				'tarea'       => TareaNumeracion::monitor( $tarea ),
				'ajax'        => admin_url( 'admin-ajax.php' ),
				'nonce'       => wp_create_nonce( 'asde_ap_tarea' ),
				'personas'    => $this->url( 'personas' ),
				'en_personas' => 'personas' === $activo,
			)
		);
	}

	protected function denegado( string $mensaje = '' ): string {
		return $this->layout( '', $this->tpl( 'comun/denegado', array( 'mensaje' => $mensaje ?: asde_t( 'No tienes acceso a esta sección del área privada.' ) ) ) );
	}

	protected function error_crm( string $activo, string $mensaje ): string {
		return $this->layout( $activo, $this->tpl( 'comun/error-crm', array( 'mensaje' => $mensaje ) ) );
	}

	/** @return never */
	protected function redirect( string $url ): void {
		wp_safe_redirect( $url );
		exit;
	}

	protected function post( string $key ): string {
		return isset( $_POST[ $key ] ) && is_scalar( $_POST[ $key ] ) ? trim( (string) wp_unslash( $_POST[ $key ] ) ) : ''; // phpcs:ignore WordPress.Security
	}

	/** @return string[] */
	protected function post_array( string $key ): array {
		return isset( $_POST[ $key ] ) && is_array( $_POST[ $key ] ) ? array_map( 'strval', array_map( 'wp_unslash', $_POST[ $key ] ) ) : array(); // phpcs:ignore WordPress.Security
	}

	protected function get( string $key ): string {
		return isset( $_GET[ $key ] ) && is_scalar( $_GET[ $key ] ) ? trim( sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) ) : ''; // phpcs:ignore WordPress.Security
	}

	protected function url( string $key, array $args = array() ): string {
		return Pages::url( $key, $args );
	}
}
