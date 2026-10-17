<?php
namespace AsdeBalears\AreaPrivada\Install;

use AsdeBalears\AreaPrivada\Domain\Grupos;
use AsdeBalears\AreaPrivada\Frontend\Pages;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- renombrar la tabla propia del plugin ({prefix}asde_*): no hay función de WordPress para ello y ocurre una sola vez.

/**
 * v0.39 (doc 48): el módulo «Uniformes» pasa a ser «Tienda». Esta migración mueve lo que ya
 * existía a los nombres nuevos, sin perder nada y sin romper las direcciones antiguas:
 *
 * - la tabla `asde_uniformes` pasa a `asde_tienda_pedidos` (se renombra, no se copia);
 * - la configuración de cada grupo `asde_ap_uniformes_<grupo>` pasa a `asde_ap_tienda_<grupo>`;
 * - la marca del módulo en «Módulos» (`uniformes`) pasa a `tienda`;
 * - las tres páginas conservan su id (y por tanto sus enlaces: WordPress redirige las
 *   direcciones antiguas) pero toman la clave, el título, la dirección y el shortcode nuevos;
 * - a los administradores de WordPress se les quitan las capabilities `asde_uniformes_*`
 *   (las de los roles las sincroniza Roles::install()).
 *
 * Es idempotente: se puede ejecutar cuantas veces haga falta.
 */
final class MigracionTienda {

	/** Clave de página antigua => nueva. */
	public const PAGINAS = array(
		'uniforme'         => 'tienda_pedidos',
		'uniformes'        => 'tienda_gestion',
		'uniformes_config' => 'tienda_config',
	);

	/** Título y dirección que tenían, para no pisar los que alguien haya cambiado a mano. */
	private const ANTIGUAS = array(
		'uniforme'         => array( 'uniforme', 'Uniforme' ),
		'uniformes'        => array( 'gestion-pedidos', 'Gestión de pedidos de uniforme' ),
		'uniformes_config' => array( 'configuracion', 'Configuración de uniformes' ),
	);

	/** Capabilities antiguas que ya no existen. */
	public const CAPS_ANTIGUAS = array(
		'asde_uniformes_pedir',
		'asde_uniformes_gestion',
		'asde_uniformes_pago',
		'asde_uniformes_pedido',
		'asde_uniformes_entrega',
		'asde_uniformes_config',
	);

	public static function ejecutar(): void {
		self::tabla();
		self::opciones();
		self::modulos();
		self::paginas();
		self::permisos();
	}

	private static function tabla(): void {
		global $wpdb;
		$vieja = $wpdb->prefix . 'asde_uniformes';
		$nueva = $wpdb->prefix . 'asde_tienda_pedidos';
		$existe = static fn( string $t ): bool => $t === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $t ) ) );
		if ( $existe( $vieja ) && ! $existe( $nueva ) ) {
			$wpdb->query( $wpdb->prepare( 'RENAME TABLE %i TO %i', $vieja, $nueva ) );
		}
	}

	private static function opciones(): void {
		foreach ( Grupos::todos() as $numero => $g ) {
			$n     = (string) ( $g['numero'] ?? $numero );
			$vieja = get_option( 'asde_ap_uniformes_' . $n, null );
			if ( '' === $n || null === $vieja ) {
				continue;
			}
			if ( null === get_option( 'asde_ap_tienda_' . $n, null ) ) {
				update_option( 'asde_ap_tienda_' . $n, $vieja, false );
			}
			delete_option( 'asde_ap_uniformes_' . $n );
		}
	}

	private static function modulos(): void {
		$todos = get_option( 'asde_ap_modulos', array() );
		if ( ! is_array( $todos ) ) {
			return;
		}
		$cambio = false;
		foreach ( $todos as $grupo => $fila ) {
			if ( is_array( $fila ) && array_key_exists( 'uniformes', $fila ) ) {
				if ( ! array_key_exists( 'tienda', $fila ) ) {
					$fila['tienda'] = $fila['uniformes'];
				}
				unset( $fila['uniformes'] );
				$todos[ $grupo ] = $fila;
				$cambio          = true;
			}
		}
		if ( $cambio ) {
			update_option( 'asde_ap_modulos', $todos, false );
		}
	}

	private static function paginas(): void {
		$ids = get_option( 'asde_ap_pages', array() );
		if ( ! is_array( $ids ) ) {
			return;
		}
		$cambio = false;
		foreach ( self::PAGINAS as $vieja => $nueva ) {
			$id = (int) ( $ids[ $vieja ] ?? 0 );
			if ( ! $id ) {
				continue;
			}
			unset( $ids[ $vieja ] );
			$cambio = true;
			if ( ! empty( $ids[ $nueva ] ) || 'page' !== get_post_type( $id ) ) {
				continue; // Ya hay una nueva (o la antigua se borró): se queda lo que hay.
			}
			$ids[ $nueva ] = $id;
			list( $slug, $titulo, $contenido ) = Pages::DEFINICIONES[ $nueva ];
			$post    = get_post( $id );
			$cambios = array( 'ID' => $id );
			if ( self::ANTIGUAS[ $vieja ][0] === $post->post_name ) {
				$cambios['post_name'] = $slug; // WordPress guarda la dirección antigua y redirige.
			}
			if ( self::ANTIGUAS[ $vieja ][1] === $post->post_title ) {
				$cambios['post_title'] = $titulo;
			}
			$tags = array(
				'[asde_uniformes_config' => '[asde_tienda_config',
				'[asde_uniformes'        => '[asde_tienda_gestion',
				'[asde_uniforme'         => '[asde_tienda_pedidos',
			);
			$nuevo_contenido = str_replace( array_keys( $tags ), array_values( $tags ), (string) $post->post_content );
			if ( $nuevo_contenido !== $post->post_content ) {
				$cambios['post_content'] = $nuevo_contenido;
			}
			wp_update_post( wp_slash( $cambios ) );
		}
		if ( $cambio ) {
			update_option( 'asde_ap_pages', $ids, false );
		}
	}

	/**
	 * Dirección nueva de una ruta antigua de Uniformes (`…/uniforme/…` pasa a `…/mis-pedidos/…`), o null
	 * si no es una de ellas. Las páginas son jerárquicas: WordPress no guarda su dirección antigua.
	 */
	public static function ruta_nueva( string $ruta ): ?string {
		$nueva = preg_replace( '#/uniforme(/|$)#', '/mis-pedidos$1', $ruta, 1, $cuantas );
		return $cuantas ? (string) $nueva : null;
	}

	/** 301 de las direcciones antiguas (solo si la página nueva existe). */
	public static function redirigir(): void {
		if ( ! is_404() ) {
			return;
		}
		$ruta  = (string) wp_parse_url( esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) ), PHP_URL_PATH );
		$nueva = self::ruta_nueva( $ruta );
		if ( null !== $nueva && url_to_postid( home_url( $nueva ) ) ) {
			wp_safe_redirect( home_url( $nueva ), 301 );
			exit;
		}
	}

	private static function permisos(): void {
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			foreach ( self::CAPS_ANTIGUAS as $cap ) {
				$admin->remove_cap( $cap );
			}
		}
	}
}
