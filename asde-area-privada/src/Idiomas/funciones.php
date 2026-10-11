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

if ( ! function_exists( 'asde_js' ) ) {
	/**
	 * Pregunta traducida para confirmar un envío (sin escapar), con los marcadores %s/%d ya
	 * rellenos: <code>data-asde-confirmar="&lt;?php echo esc_attr( asde_js( '¿Borrar %s?', $x ) ); ?&gt;"</code>.
	 * v0.31: area.js la pregunta al enviar; antes era una cadena JSON para un onsubmit en línea,
	 * y WordPress pide no poner JavaScript en las plantillas.
	 *
	 * @param mixed ...$args
	 */
	function asde_js( string $es, ...$args ): string {
		$t = Traducciones::t( $es );
		return $args ? vsprintf( $t, $args ) : $t;
	}
}
if ( ! function_exists( 'asde_ta' ) ) {
	/**
	 * Traduce los valores de texto de una lista de etiquetas (las claves no se tocan): para las constantes con
	 * etiquetas, que no pueden llamar a funciones. Ej.: <code>foreach ( asde_ta( Clase::ESTADOS ) as $k => $etiqueta )</code>.
	 *
	 * @param array<array-key, mixed> $valores
	 * @return array<array-key, mixed>
	 */
	function asde_ta( array $valores ): array {
		return array_map( static fn( $v ) => is_string( $v ) ? Traducciones::t( $v ) : $v, $valores );
	}
}

if ( ! function_exists( 'asde_n' ) ) {
	/**
	 * Plantilla traducida según el número (singular si es 1, plural si no), para rellenar después con sprintf:
	 * <code>sprintf( asde_n( '%d persona', '%d personas', $n ), $n )</code>.
	 */
	function asde_n( string $uno, string $varios, int $n ): string {
		return Traducciones::t( 1 === $n ? $uno : $varios );
	}
}
