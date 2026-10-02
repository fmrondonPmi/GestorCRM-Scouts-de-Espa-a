<?php
namespace AsdeBalears\AreaPrivada\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * El tema de WordPress y el área privada (v0.15, doc 28).
 *
 * El plugin **no depende de ningún tema**: las páginas son páginas normales con un
 * shortcode, y el CSS y los textos son propios. Solo está *diseñado* para Enfold
 * (el tema de https://baleares.scout.es), y con él hace tres cosas más:
 *
 * - toma de Enfold el color principal y el del texto (si la persona administradora
 *   no ha elegido colores propios; ver Colores),
 * - deja las páginas a ancho completo y sin barra de título (metadatos de Enfold),
 * - ofrece plegar la cabecera del tema para ganar espacio.
 *
 * Con cualquier otro tema todo eso se omite sin más: ni errores ni cosas rotas.
 */
final class Tema {

	/** Tema para el que está diseñado el área (slug de la plantilla). */
	public const IDEAL = 'enfold';

	/** Slug de la plantilla activa (el del tema padre si hay un tema hijo). */
	public static function slug(): string {
		return (string) apply_filters( 'asde_ap_tema_slug', get_template() );
	}

	public static function es_ideal(): bool {
		return self::IDEAL === self::slug();
	}

	public static function nombre(): string {
		$nombre = (string) wp_get_theme()->get( 'Name' );
		return '' !== $nombre ? $nombre : self::slug();
	}

	public static function es_de_bloques(): bool {
		return function_exists( 'wp_is_block_theme' ) && wp_is_block_theme();
	}

	/**
	 * ¿Se ofrece el botón «Ocultar cabecera»? Solo tiene sentido con un tema cuya cabecera
	 * sabemos plegar (Enfold). Otro tema puede pedirlo con el filtro `asde_ap_cabecera_plegable`
	 * si su cabecera es `#header`.
	 */
	public static function cabecera_plegable(): bool {
		return (bool) apply_filters( 'asde_ap_cabecera_plegable', self::es_ideal() );
	}

	/**
	 * Ajustes de página que solo entiende Enfold (ancho completo, sin barra de título).
	 * Con otro tema no se toca nada; al cambiar a Enfold se aplican (Plugin → after_switch_theme).
	 *
	 * @param int[] $paginas Ids de las páginas del área.
	 */
	public static function ajustar_paginas( array $paginas ): void {
		if ( ! self::es_ideal() ) {
			return;
		}
		foreach ( $paginas as $id ) {
			if ( ! $id || 'page' !== get_post_type( $id ) ) {
				continue;
			}
			if ( 'fullsize' !== get_post_meta( $id, 'layout', true ) ) {
				update_post_meta( $id, 'layout', 'fullsize' ); // Enfold: ancho completo, sin barra lateral.
			}
			if ( 'hidden_title_bar' !== get_post_meta( $id, 'header_title_bar', true ) ) {
				update_post_meta( $id, 'header_title_bar', 'hidden_title_bar' ); // Enfold: sin barra de título ni migas.
			}
		}
	}

	/**
	 * Lo que se enseña en «Instalación y entorno»: cómo está el tema actual y cuál conviene.
	 *
	 * @return array{ideal:bool, nivel:string, titulo:string, estado:string, consejo:string}
	 */
	public static function resumen(): array {
		$nombre = self::nombre();
		if ( self::es_ideal() ) {
			$estado = $nombre . ': es el tema para el que está diseñado el área privada. Se aprovechan sus colores, el ancho completo de las páginas y la cabecera plegable.';
			$nivel  = 'ok';
		} else {
			$estado = $nombre . ( self::es_de_bloques() ? ' (de bloques)' : '' ) . '. El área privada funciona con cualquier tema: no es obligatorio usar Enfold. '
				. 'Con este tema no están la cabecera plegable ni los ajustes de página de Enfold, y los colores son los de la pantalla «Colores» (no los del tema).';
			$nivel  = 'info';
		}
		return array(
			'ideal'   => self::es_ideal(),
			'nivel'   => $nivel,
			'titulo'  => 'Tema',
			'estado'  => $estado,
			'consejo' => self::consejo(),
		);
	}

	/** Qué tema es el mejor para el área privada. */
	public static function consejo(): string {
		return 'El tema ideal es Enfold (el de baleares.scout.es), para el que está diseñada. '
			. 'Sirve cualquier otro que permita páginas a ancho completo, sin barra lateral y sin barra de título, con el menú principal editable en Apariencia → Menús '
			. '(por ejemplo Astra, Kadence o GeneratePress, aunque no se han probado todos). '
			. 'Con un tema de bloques (como Twenty Twenty-Five) también funciona: pon las páginas del área en una plantilla de ancho completo y añade «Acceso socios» desde el Editor del sitio. '
			. 'Conviene evitar los temas que obligan a una barra lateral o que estrechan el contenido: los listados son tablas anchas.';
	}
}
