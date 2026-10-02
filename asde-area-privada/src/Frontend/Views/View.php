<?php
namespace AsdeBalears\AreaPrivada\Frontend\Views;

use AsdeBalears\AreaPrivada\Auth\Ambito;
use AsdeBalears\AreaPrivada\Frontend\Notices;
use AsdeBalears\AreaPrivada\Frontend\Pages;
use AsdeBalears\AreaPrivada\Frontend\TemplateLoader;
use AsdeBalears\AreaPrivada\Socios\TareaNumeracion;
use AsdeBalears\AreaPrivada\Uniformes\Servicio;
use AsdeBalears\AreaPrivada\Usuarios\Solicitudes;

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
		$items = array( 'area' => 'Inicio' );
		if ( $ambito && ( $ambito->persona || $ambito->representados ) ) {
			$items['ficha'] = $ambito->persona ? 'Mis datos' : 'Mi familia';
			if ( current_user_can( MisPagosView::CAP ) ) {
				$items['pagos'] = 'Mis pagos';
			}
		}
		// Uniforme (v0.11; v0.15): pedir y gestionar son cosas distintas. Quien pide (también los
		// scouters, que además gestionan) tiene «Mi uniforme»; quien gestiona, la gestión con lo pendiente.
		if ( $ambito ) {
			$p        = Servicio::pendientes( $ambito );
			$pide     = current_user_can( Servicio::CAP_PEDIR ) && Servicio::para_quien( $ambito );
			$gestiona = current_user_can( Servicio::CAP_GESTION );
			if ( $pide ) {
				$items['uniforme'] = ( $gestiona ? 'Mi uniforme' : 'Uniforme' ) . ( $p['confirmar'] ? ' (' . $p['confirmar'] . ')' : '' );
			}
			if ( $gestiona ) {
				$n                  = $p['pago'] + $p['pedido'] + $p['entrega'];
				$items['uniformes'] = 'Gestión de uniformes' . ( $n ? ' (' . $n . ')' : '' );
			}
		}
		if ( current_user_can( 'asde_manage_users' ) ) {
			$items['personas'] = 'Personas';
		}
		if ( $ambito && current_user_can( 'asde_aprobar_solicitudes' ) ) {
			$solicitudes          = Solicitudes::contar_pendientes( $ambito );
			$items['solicitudes'] = 'Solicitudes' . ( $solicitudes ? ' (' . $solicitudes . ')' : '' );
		}
		if ( current_user_can( 'asde_approve_changes' ) ) {
			$pendientes       = $ambito && $ambito->vision_global() ? \AsdeBalears\AreaPrivada\Fichas\Cambios::contar_pendientes() : 0;
			$items['cambios'] = 'Cambios' . ( $pendientes ? ' (' . $pendientes . ')' : '' );
		}
		if ( current_user_can( 'asde_reports' ) ) {
			$items['informes'] = 'Informes';
		}
		if ( current_user_can( 'asde_tesoreria_acceso' ) ) {
			$items['tesoreria'] = 'Tesorería';
		}
		if ( current_user_can( 'asde_manage_users' ) ) {
			$items['usuarios'] = 'Usuarios';
		}
		return $items;
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
		)[ $activo ] ?? $activo;
		return '<div class="asde-ap">'
			. $this->tpl(
				'comun/nav',
				array(
					'items'  => $items,
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
		return $this->layout( '', $this->tpl( 'comun/denegado', array( 'mensaje' => $mensaje ?: 'No tienes acceso a esta sección del área privada.' ) ) );
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
