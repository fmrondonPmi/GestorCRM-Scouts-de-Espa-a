<?php
namespace AsdeBalears\AreaPrivada\Frontend\Views;

use AsdeBalears\AreaPrivada\Auth\Ambito;
use AsdeBalears\AreaPrivada\Frontend\Menu;
use AsdeBalears\AreaPrivada\Auth\SoloLectura;
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

	/**
	 * Hojas de la sección «Usuarios» (v0.25): página => nombre de la hoja. Las tres
	 * páginas son hojas de un mismo libro, como en una hoja de cálculo.
	 */
	public const HOJAS_USUARIOS = array(
		'usuarios' => 'Usuarios',
		'alta'     => 'Dar acceso',
		'emails'   => 'Registro de emails',
	);

	/**
	 * Pone la barra de hojas de «Usuarios» encima del contenido de una de sus páginas.
	 * Con JavaScript (assets/js/hojas.js) se cambia de hoja sin recargar la página y
	 * cada hoja conserva lo que tenía (filtros, búsqueda); sin él, son enlaces. En la
	 * ventana emergente («Dar acceso» desde otra página) no hay hojas.
	 */
	protected function libro_usuarios( string $hoja, string $contenido ): string {
		if ( self::$modal ) {
			return $contenido;
		}
		$hojas = array();
		foreach ( self::HOJAS_USUARIOS as $clave => $nombre ) {
			$hojas[ $clave ] = array(
				'nombre' => asde_t( $nombre ),
				'url'    => $this->url( $clave ),
			);
		}
		return $this->tpl(
			'usuarios/hojas',
			array(
				'hojas'     => $hojas,
				'activa'    => $hoja,
				'contenido' => $contenido,
			)
		);
	}

	/** Envoltorio común: menú del área privada + avisos + contenido. */
	protected function layout( string $activo, string $contenido ): string {
		if ( SoloLectura::activo() ) { // v0.50: la oficina no ve botones de acción.
			$contenido = SoloLectura::limpiar( $contenido );
		}
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
			'tienda_pedidos'         => isset( $items['tienda_pedidos'] ) ? 'tienda_pedidos' : 'tienda_gestion',
			'tienda_config' => 'tienda_gestion',
			'tienda_productos' => 'tienda_gestion',
			'inventario_tipos'   => 'inventario',
			'inventario_compras' => 'inventario',
			'eventos_config'     => 'eventos',
			'eventos_recetas'    => 'eventos',
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

	/** @return array<int, string> Campo del formulario con forma campo[id] => valor (ids > 0 y valores de texto). */
	protected function post_mapa( string $campo ): array {
		$v   = isset( $_POST[ $campo ] ) && is_array( $_POST[ $campo ] ) ? wp_unslash( $_POST[ $campo ] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- el nonce lo comprueba handle_post(); cada valor se sanea aquí y se valida al usarlo
		$out = array();
		foreach ( (array) $v as $id => $valor ) {
			if ( absint( $id ) && is_scalar( $valor ) ) {
				$out[ absint( $id ) ] = sanitize_text_field( (string) $valor );
			}
		}
		return $out;
	}

	/**
	 * Cantidades de la tienda (v0.45, doc 48, T2): junta `cant[variante]` (productos de una sola variante) y las
	 * filas `lin[producto][cant][]` + `lin[producto][var][]` (cantidad y talla en un desplegable) en un solo mapa
	 * variante => cantidad (si la misma talla sale en dos filas, se suman). Las filas con cantidad y sin talla no
	 * entran: su producto se anota en $sin_talla para avisar.
	 *
	 * @param string[] $sin_talla Nombres de los productos con cantidad y sin talla.
	 * @return array<int, string> id de variante => cantidad (sin validar: lo hace Tienda\Servicio).
	 */
	protected function post_cantidades( array &$sin_talla = array() ): array {
		$out = $this->post_mapa( 'cant' );
		$lin = isset( $_POST['lin'] ) && is_array( $_POST['lin'] ) ? wp_unslash( $_POST['lin'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- el nonce lo comprueba handle_post(); cada valor se sanea aquí y se valida al usarlo
		foreach ( array_slice( (array) $lin, 0, 200, true ) as $producto => $campos ) {
			if ( ! absint( $producto ) || ! is_array( $campos ) ) {
				continue;
			}
			$cants = isset( $campos['cant'] ) && is_array( $campos['cant'] ) ? array_values( $campos['cant'] ) : array();
			$vars  = isset( $campos['var'] ) && is_array( $campos['var'] ) ? array_values( $campos['var'] ) : array();
			foreach ( array_slice( $cants, 0, 20 ) as $k => $cantidad ) {
				$cantidad = is_scalar( $cantidad ) ? (int) $cantidad : 0;
				$variante = isset( $vars[ $k ] ) && is_scalar( $vars[ $k ] ) ? absint( $vars[ $k ] ) : 0;
				if ( $cantidad <= 0 ) {
					continue;
				}
				if ( ! $variante ) {
					$p           = \AsdeBalears\AreaPrivada\Tienda\ProductosTable::obtener( absint( $producto ) );
					$sin_talla[] = $p ? (string) $p['nombre'] : '';
					continue;
				}
				$out[ $variante ] = (string) ( (int) ( $out[ $variante ] ?? 0 ) + $cantidad );
			}
		}
		return $out;
	}

	protected function get( string $key ): string {
		return isset( $_GET[ $key ] ) && is_scalar( $_GET[ $key ] ) ? trim( sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) ) : ''; // phpcs:ignore WordPress.Security
	}

	protected function url( string $key, array $args = array() ): string {
		return Pages::url( $key, $args );
	}

	/**
	 * La dirección de esta misma petición, con sus filtros y su página (v0.25): adonde se
	 * vuelve después de un POST enviado desde la propia pantalla. El formulario se envía
	 * a la dirección que se está viendo (también cuando lo envía parcial.js), así que se
	 * vuelve a la misma vista. wp_get_referer() no sirve aquí: si coincide con la propia
	 * petición devuelve false y se perdían los filtros.
	 */
	protected function url_actual( string $defecto ): string {
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : ''; // v0.31: saneado (y validado abajo con wp_validate_redirect).
		$host = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';
		if ( '' === $uri || '' === $host || '/' !== $uri[0] ) {
			return $defecto;
		}
		return wp_validate_redirect( esc_url_raw( set_url_scheme( 'http://' . $host . $uri ) ), $defecto );
	}
}
