<?php
/**
 * Funciones para pedir un texto del área privada en el idioma de la persona (v0.15, doc 28).
 * El texto que se pasa está en castellano y es también la clave de traducción.
 *
 * Con textos con partes que cambian, se rellenan después con sprintf:
 *   sprintf( asde_t( 'Pedido %s enviado' ), $numero )
 */

use AsdeBalears\AreaPrivada\Idiomas\Traducciones;

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'asde_t' ) ) {
	/** Texto plano traducido (sin escapar: para escapar tú, o para emails de texto). */
	function asde_t( string $es ): string {
		return Traducciones::t( $es );
	}
}

if ( ! function_exists( 'asde_h' ) ) {
	/** Texto traducido para HTML (admite negrita, cursiva, código, saltos de línea y enlaces). */
	function asde_h( string $es ): string {
		return Traducciones::h( $es );
	}
}

if ( ! function_exists( 'asde_e' ) ) {
	/** Imprime el texto traducido para HTML. */
	function asde_e( string $es ): void {
		echo Traducciones::h( $es ); // phpcs:ignore WordPress.Security.EscapeOutput
	}
}

if ( ! function_exists( 'asde_a' ) ) {
	/** Texto traducido listo para un atributo HTML (title, placeholder…). */
	function asde_a( string $es ): string {
		return esc_attr( Traducciones::t( $es ) );
	}
}
