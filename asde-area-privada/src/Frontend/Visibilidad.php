<?php
namespace AsdeBalears\AreaPrivada\Frontend;

use AsdeBalears\AreaPrivada\Domain\Grupos;
use AsdeBalears\AreaPrivada\Domain\Modulos;
use AsdeBalears\AreaPrivada\Tienda\Config;
use AsdeBalears\AreaPrivada\Tienda\Enlace;

defined( 'ABSPATH' ) || exit;

/**
 * Las páginas del área privada no aparecen en ningún listado público:
 * - listados de páginas (wp_list_pages: el bloque "Páginas" del pie de Enfold,
 *   widgets de páginas…), buscador (también el buscador AJAX de Enfold), API REST
 *   para visitantes y sitemaps de WordPress;
 * - menús, salvo el elemento "Acceso socios" del menú superior, que al iniciar
 *   sesión pasa a "Área privada".
 * Así se entra al área SOLO desde ese elemento del menú.
 */
final class Visibilidad {

	private const OPTION_MENU = 'asde_ap_menu_item';

	public static function register(): void {
		add_filter( 'wp_list_pages_excludes', array( self::class, 'excluir' ) );
		add_filter( 'get_pages', array( self::class, 'get_pages' ) );
		add_filter( 'widget_pages_args', array( self::class, 'widget_paginas' ) );
		add_action( 'pre_get_posts', array( self::class, 'busqueda' ) );
		add_filter( 'rest_page_query', array( self::class, 'rest' ) );
		add_filter( 'wp_sitemaps_posts_query_args', array( self::class, 'sitemap' ), 10, 2 );
		add_filter( 'wp_nav_menu_objects', array( self::class, 'menu' ), 10, 2 );
		add_filter( 'login_url', array( self::class, 'login_url' ), 20, 2 );
	}

	/** @return int[] */
	public static function ids(): array {
		return array_values( array_filter( Pages::ids() ) );
	}

	public static function excluir( $exclude ): array {
		return array_values( array_unique( array_merge( array_map( 'intval', (array) $exclude ), self::ids() ) ) );
	}

	public static function widget_paginas( $args ): array {
		$args            = (array) $args;
		$args['exclude'] = implode( ',', array_unique( array_merge( wp_parse_id_list( $args['exclude'] ?? '' ), self::ids() ) ) ); // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude -- se ocultan solo las páginas privadas del plugin (unas decenas de ids)
		return $args;
	}

	/** Búsquedas de la web (incluida la AJAX del tema, que pasa por admin-ajax.php). */
	public static function busqueda( \WP_Query $q ): void {
		if ( $q->is_search() && ( ! is_admin() || wp_doing_ajax() ) ) {
			$q->set( 'post__not_in', array_merge( (array) $q->get( 'post__not_in' ), self::ids() ) ); // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in -- solo las páginas privadas del plugin (unas decenas de ids)
		}
	}

	/** /wp-json/wp/v2/pages: los editores las siguen viendo (editor de bloques). */
	public static function rest( $args ): array {
		if ( ! current_user_can( 'edit_pages' ) ) {
			$args['post__not_in'] = array_merge( (array) ( $args['post__not_in'] ?? array() ), self::ids() ); // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in -- solo las páginas privadas del plugin (unas decenas de ids)
		}
		return $args;
	}

	public static function sitemap( $args, $post_type ): array {
		if ( 'page' === $post_type ) {
			$args['post__not_in'] = array_merge( (array) ( $args['post__not_in'] ?? array() ), self::ids() ); // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in -- solo las páginas privadas del plugin (unas decenas de ids)
		}
		return $args;
	}

	/** Elemento de acceso dinámico y ninguna otra página privada en los menús. */
	public static function menu( $items, $args = null ): array {
		$acceso = Pages::id( 'acceso' );
		$ids    = self::ids();
		$out    = array();
		foreach ( (array) $items as $item ) {
			$es_privada = 'page' === $item->object && in_array( (int) $item->object_id, $ids, true );
			if ( ! $es_privada ) {
				$out[] = $item;
			} elseif ( (int) $item->object_id === $acceso ) {
				if ( is_user_logged_in() ) {
					$item->title = __( 'Área privada', 'asde-area-privada' );
					$item->url   = Pages::url( 'area' );
				}
				if ( null !== Pages::current_key() ) {
					$item->classes   = array_merge( (array) $item->classes, array( 'current-menu-item' ) );
					$item->current   = true;
				}
				$out[] = $item;
			}
		}
		$ubicacion = is_object( $args ) ? (string) ( $args->theme_location ?? '' ) : '';
		if ( '' !== $ubicacion && self::ubicacion_principal() === $ubicacion ) {
			$out = array_merge( $out, self::enlaces_tienda( count( $out ) ) );
		}
		return $out;
	}

	/**
	 * v0.49 (doc 48, T5): un enlace a la tienda pública de cada grupo que lo ha pedido en su configuración
	 * (tienda pública abierta, módulo encendido y un texto escrito). Es el acceso de quien no tiene sesión.
	 *
	 * @return object[]
	 */
	private static function enlaces_tienda( int $posicion ): array {
		$out = array();
		foreach ( Grupos::todos() as $numero => $grupo ) {
			$cfg = Config::de( (string) $numero );
			if ( ! $cfg['publica'] || '' === $cfg['menu_tienda'] || ! Modulos::activo( Modulos::TIENDA, (string) $numero ) ) {
				continue;
			}
			++$posicion;
			$out[] = (object) array(
				'ID'                     => 0,
				'db_id'                  => 0,
				'menu_item_parent'       => 0,
				'menu_order'             => $posicion,
				'object'                 => 'custom',
				'object_id'              => 0,
				'type'                   => 'custom',
				'type_label'             => '',
				'title'                  => $cfg['menu_tienda'],
				'url'                    => Enlace::url( $grupo ),
				'target'                 => '',
				'attr_title'             => '',
				'description'            => '',
				'classes'                => array( 'menu-item', 'asde-menu-tienda' ),
				'xfn'                    => '',
				'current'                => false,
				'current_item_ancestor'  => false,
				'current_item_parent'    => false,
				'post_type'              => 'nav_menu_item',
			);
		}
		return $out;
	}

	/** El login de las páginas del área privada es nuestra pantalla de acceso (con el grupo). */
	public static function login_url( $url, $redirect = '' ) {
		if ( ! Pages::id( 'acceso' ) ) {
			return $url;
		}
		$area = Pages::url( 'area' );
		if ( is_string( $redirect ) && '' !== $redirect && str_starts_with( $redirect, $area ) ) {
			return add_query_arg( 'redirect_to', rawurlencode( $redirect ), Pages::url( 'acceso' ) );
		}
		if ( '' === $redirect && did_action( 'wp' ) && null !== Pages::current_key() ) {
			return Pages::url( 'acceso' );
		}
		return $url;
	}

	/**
	 * Añade UNA vez "Acceso socios" al final del menú principal del tema (Enfold,
	 * ubicación `avia`). Si después se quita o se mueve desde Apariencia → Menús,
	 * se respeta; salvo con $forzar («Generar el entorno»), que lo vuelve a poner.
	 */
	public static function asegurar_menu( bool $forzar = false ): void {
		$item = (int) get_option( self::OPTION_MENU );
		if ( $item ) {
			$estado = 'nav_menu_item' === get_post_type( $item ) ? get_post_status( $item ) : false;
			// Reactivación del plugin: se vuelve a mostrar si se había ocultado al desactivarlo.
			if ( 'draft' === $estado ) {
				wp_update_post(
					array(
						'ID'          => $item,
						'post_status' => 'publish',
					)
				);
				return;
			}
			if ( 'publish' === $estado || ! $forzar ) {
				return;
			}
			delete_option( self::OPTION_MENU ); // Lo borraron: se crea otra vez.
		}
		if ( ! Pages::id( 'acceso' ) ) {
			return;
		}
		$menu = self::menu_principal();
		if ( ! $menu ) {
			return;
		}
		$id = wp_update_nav_menu_item(
			$menu,
			0,
			array(
				'menu-item-title'     => __( 'Acceso socios', 'asde-area-privada' ),
				'menu-item-object'    => 'page',
				'menu-item-object-id' => Pages::id( 'acceso' ),
				'menu-item-type'      => 'post_type',
				'menu-item-status'    => 'publish',
				'menu-item-classes'   => 'asde-menu-acceso av-menu-button av-menu-button-bordered',
				'menu-item-position'  => 999,
			)
		);
		if ( ! is_wp_error( $id ) && $id ) {
			update_option( self::OPTION_MENU, (int) $id, false );
		}
	}

	/** Ubicación del menú principal del tema: la de Enfold (`avia`) o la más habitual. */
	public static function ubicacion_principal(): string {
		$registradas = array_keys( get_registered_nav_menus() );
		foreach ( array( 'avia', 'primary', 'main', 'main-menu', 'menu-1', 'header', 'header-menu', 'top' ) as $clave ) {
			if ( in_array( $clave, $registradas, true ) ) {
				return $clave;
			}
		}
		return (string) ( $registradas[0] ?? '' );
	}

	/** Menú asignado a la ubicación principal (o, si no, al primero que tenga ubicación). */
	public static function menu_principal(): int {
		$ubicaciones = get_nav_menu_locations();
		$menu        = (int) ( $ubicaciones[ self::ubicacion_principal() ] ?? 0 );
		return $menu ? $menu : (int) ( array_values( array_filter( $ubicaciones ) )[0] ?? 0 );
	}

	/**
	 * ¿Se ve «Acceso socios»? El elemento existe, está publicado y su menú está
	 * en alguna ubicación del tema.
	 */
	public static function menu_visible(): bool {
		$item = (int) get_option( self::OPTION_MENU );
		if ( ! $item || 'nav_menu_item' !== get_post_type( $item ) || 'publish' !== get_post_status( $item ) ) {
			return false;
		}
		$menus = wp_get_object_terms( $item, 'nav_menu', array( 'fields' => 'ids' ) );
		return ! is_wp_error( $menus ) && array_intersect( array_map( 'intval', $menus ), array_map( 'intval', get_nav_menu_locations() ) );
	}

	/**
	 * Listas de páginas hechas con get_pages() (bloque «Lista de páginas» de los
	 * temas de bloques, algunos widgets): tampoco muestran las del área.
	 */
	public static function get_pages( $pages ) {
		if ( is_admin() || ! is_array( $pages ) ) {
			return $pages;
		}
		$ids = self::ids();
		return array_values( array_filter( $pages, static fn( $p ) => ! in_array( (int) ( $p->ID ?? 0 ), $ids, true ) ) );
	}

	/** Al desactivar el plugin el elemento del menú se oculta (llevaría a una página sin contenido). */
	public static function ocultar_menu(): void {
		$item = (int) get_option( self::OPTION_MENU );
		if ( $item && 'publish' === get_post_status( $item ) ) {
			wp_update_post(
				array(
					'ID'          => $item,
					'post_status' => 'draft',
				)
			);
		}
	}
}
