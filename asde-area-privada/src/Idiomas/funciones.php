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
		echo wp_kses( Traducciones::h( $es ), asde_html_permitido() );
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

if ( ! function_exists( 'asde_html_permitido' ) ) {
	/**
	 * Etiquetas y atributos que pueden salir en el HTML que arma el plugin (los de una entrada de WordPress más
	 * formularios, tablas, <template>, <details> y SVG), para pasarlos a wp_kses() en cada salida.
	 *
	 * @return array<string, array<string, bool>>
	 */
	function asde_html_permitido(): array {
		static $permitido = null;
		if ( null !== $permitido ) {
			return $permitido;
		}
		$comunes   = array(
			'class'    => true,
			'id'       => true,
			'style'    => true,
			'title'    => true,
			'role'     => true,
			'lang'     => true,
			'dir'      => true,
			'hidden'   => true,
			'tabindex' => true,
			'data-*'   => true,
			'aria-*'   => true,
		);
		$etiquetas = array(
			'a'        => array( 'href' => true, 'target' => true, 'rel' => true, 'download' => true ),
			'abbr'     => array(),
			'b'        => array(),
			'br'       => array(),
			'article'  => array(),
			'aside'    => array(),
			'button'   => array( 'type' => true, 'name' => true, 'value' => true, 'disabled' => true, 'form' => true ),
			'caption'  => array(),
			'code'     => array(),
			'col'      => array( 'span' => true, 'width' => true ),
			'colgroup' => array( 'span' => true ),
			'dd'       => array(),
			'datalist' => array(),
			'details'  => array( 'open' => true, 'name' => true ),
			'dialog'   => array( 'open' => true ),
			'div'      => array(),
			'dl'       => array(),
			'dt'       => array(),
			'em'       => array(),
			'fieldset' => array( 'disabled' => true, 'form' => true, 'name' => true ),
			'footer'   => array(),
			'figure'   => array(),
			'figcaption' => array(),
			'form'     => array( 'action' => true, 'method' => true, 'enctype' => true, 'novalidate' => true, 'target' => true, 'autocomplete' => true ),
			'h1'       => array(),
			'h2'       => array(),
			'h3'       => array(),
			'h4'       => array(),
			'h5'       => array(),
			'h6'       => array(),
			'header'   => array(),
			'main'     => array(),
			'hr'       => array(),
			'i'        => array(),
			'img'      => array( 'src' => true, 'alt' => true, 'width' => true, 'height' => true, 'loading' => true ),
			'input'    => array(
				'type' => true, 'name' => true, 'value' => true, 'checked' => true, 'disabled' => true, 'readonly' => true,
				'required' => true, 'placeholder' => true, 'min' => true, 'max' => true, 'step' => true, 'maxlength' => true,
				'minlength' => true, 'size' => true, 'pattern' => true, 'autocomplete' => true, 'accept' => true,
				'multiple' => true, 'list' => true, 'form' => true, 'inputmode' => true, 'autofocus' => true,
			),
			'label'    => array( 'for' => true ),
			'legend'   => array(),
			'li'       => array(),
			'mark'     => array(),
			'nav'      => array(),
			'noscript' => array(),
			'ol'       => array( 'start' => true, 'type' => true ),
			'optgroup' => array( 'label' => true, 'disabled' => true ),
			'option'   => array( 'value' => true, 'selected' => true, 'disabled' => true, 'label' => true ),
			'output'   => array( 'for' => true, 'name' => true ),
			'p'        => array(),
			'pre'      => array(),
			'progress' => array( 'value' => true, 'max' => true ),
			'section'  => array(),
			'select'   => array( 'name' => true, 'multiple' => true, 'disabled' => true, 'required' => true, 'size' => true, 'form' => true, 'autocomplete' => true ),
			'small'    => array(),
			'span'     => array(),
			'strong'   => array(),
			'sub'      => array(),
			'summary'  => array(),
			'sup'      => array(),
			'table'    => array( 'border' => true, 'cellpadding' => true, 'cellspacing' => true, 'width' => true, 'align' => true, 'bgcolor' => true ),
			'tbody'    => array(),
			'td'       => array( 'colspan' => true, 'rowspan' => true, 'width' => true, 'align' => true, 'valign' => true, 'bgcolor' => true, 'headers' => true ),
			'template' => array(),
			'textarea' => array( 'name' => true, 'rows' => true, 'cols' => true, 'placeholder' => true, 'disabled' => true, 'readonly' => true, 'required' => true, 'maxlength' => true, 'form' => true ),
			'tfoot'    => array(),
			'th'       => array( 'colspan' => true, 'rowspan' => true, 'scope' => true, 'width' => true, 'align' => true, 'abbr' => true ),
			'thead'    => array(),
			'time'     => array( 'datetime' => true ),
			'tr'       => array( 'align' => true, 'valign' => true, 'bgcolor' => true ),
			'u'        => array(),
			'ul'       => array(),
			// SVG de los iconos.
			'svg'      => array( 'xmlns' => true, 'viewbox' => true, 'width' => true, 'height' => true, 'fill' => true, 'stroke' => true, 'stroke-width' => true, 'stroke-linecap' => true, 'stroke-linejoin' => true, 'focusable' => true ),
			'path'     => array( 'd' => true, 'fill' => true, 'stroke' => true, 'stroke-width' => true, 'fill-rule' => true, 'clip-rule' => true ),
			'circle'   => array( 'cx' => true, 'cy' => true, 'r' => true, 'fill' => true, 'stroke' => true ),
			'rect'     => array( 'x' => true, 'y' => true, 'width' => true, 'height' => true, 'rx' => true, 'fill' => true, 'stroke' => true ),
			'line'     => array( 'x1' => true, 'y1' => true, 'x2' => true, 'y2' => true, 'stroke' => true, 'stroke-width' => true ),
			'polyline' => array( 'points' => true, 'fill' => true, 'stroke' => true ),
		);
		foreach ( $etiquetas as $nombre => $atributos ) {
			$permitido[ $nombre ] = $atributos + $comunes;
		}
		return $permitido;
	}
}
