<?php
namespace AsdeBalears\AreaPrivada\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * Colores del área privada (v0.15, doc 28): los elige la administración en
 * wp-admin → Grupos Scouts → Colores.
 *
 * El CSS del plugin (assets/css/area-privada.css) trae todos los colores como variables
 * `--asde-*` con su valor de siempre. Aquí solo se guardan los que la administración
 * ha cambiado (los que coinciden con el valor por defecto no se guardan, para que el
 * color principal siga siendo, si no se toca, el de Enfold) y se emite un bloque de CSS
 * que los sobrescribe.
 *
 * De un solo color se derivan los demás tonos, para que el conjunto quede coherente:
 * al cambiar el color principal cambian también sus fondos suaves, sus bordes y el color
 * del texto que va encima; al cambiar el de un estado (correcto, aviso, error,
 * información), su fondo y su texto.
 */
final class Colores {

	public const OPCION = 'asde_ap_colores';

	/**
	 * Colores que se pueden elegir: clave => [etiqueta, valor por defecto, grupo, ayuda].
	 *
	 * @return array<string, array{0:string, 1:string, 2:string, 3:string}>
	 */
	public static function definiciones(): array {
		return array(
			'primary'      => array( 'Principal', '#632599', 'marca', 'Enlaces, pestaña activa, números de página y acentos. De él salen los fondos suaves y los bordes si no los eliges tú.' ),
			'primary_dark' => array( 'Principal oscuro', '#421866', 'marca', 'Al pasar el ratón y textos sobre fondo suave. Si no lo eliges, se oscurece el principal.' ),
			'lila'         => array( 'Acento', '#c7a2e8', 'marca', 'Línea bajo el menú y bordes destacados.' ),
			'tint'         => array( 'Fondo suave', '#f7f3fb', 'marca', 'Cabeceras de tabla, zonas resaltadas y fondo de los avisos informativos.' ),
			'text'         => array( 'Texto', '#444444', 'texto', 'El texto normal.' ),
			'muted'        => array( 'Texto atenuado', '#666666', 'texto', 'Ayudas, textos secundarios y etiquetas neutras.' ),
			'border'       => array( 'Bordes', '#e1e1e1', 'texto', 'Líneas de tablas, tarjetas y campos.' ),
			'surface'      => array( 'Fondo de las tarjetas', '#ffffff', 'texto', 'El fondo de las tarjetas, ventanas y menús.' ),
			'ok'           => array( 'Correcto', '#5a8f00', 'estados', 'Operaciones bien hechas, pagos al día, entregas.' ),
			'warn'         => array( 'Aviso', '#c99400', 'estados', 'Cosas pendientes o que conviene revisar.' ),
			'error'        => array( 'Error', '#b32d2e', 'estados', 'Errores y atrasos.' ),
			'info'         => array( 'Información', '#3b73b9', 'estados', 'Pagos parciales y notas informativas.' ),
		);
	}

	/** @return array<string, string> Grupos de la pantalla: clave => título. */
	public static function grupos(): array {
		return array(
			'marca'   => 'Color de marca',
			'texto'   => 'Texto y fondos',
			'estados' => 'Estados',
		);
	}

	/**
	 * Combinaciones de partida: cada una cambia solo el color principal y el resto sale de él.
	 *
	 * @return array<string, array{0:string, 1:string}> clave => [nombre, principal]
	 */
	public static function modelos(): array {
		return array(
			'asde'    => array( 'Morado de siempre', '#632599' ),
			'verde'   => array( 'Verde scout', '#2e7d32' ),
			'azul'    => array( 'Azul', '#1d5fa8' ),
			'naranja' => array( 'Naranja', '#c2410c' ),
			'rojo'    => array( 'Rojo', '#a4262c' ),
			'grafito' => array( 'Gris azulado', '#455a64' ),
		);
	}

	// ---------------------------------------------------------------------
	// Lo guardado
	// ---------------------------------------------------------------------

	/** @return array<string, string> Solo los colores cambiados (clave => #rrggbb). */
	public static function guardados(): array {
		$g = get_option( self::OPCION, array() );
		return self::limpiar( is_array( $g ) ? $g : array() );
	}

	/**
	 * Guarda los colores. Los que coinciden con el valor por defecto (o vienen vacíos o mal) no se
	 * guardan: así lo no tocado sigue el valor por defecto (p. ej. el color de Enfold).
	 *
	 * @param array<string, mixed> $entrada clave => color
	 * @return array<string, string> Lo que ha quedado guardado.
	 */
	public static function guardar( array $entrada ): array {
		$g = self::limpiar( $entrada );
		if ( $g ) {
			update_option( self::OPCION, $g, false );
		} else {
			delete_option( self::OPCION );
		}
		return $g;
	}

	public static function restablecer(): void {
		delete_option( self::OPCION );
	}

	/** Colores válidos y distintos del valor por defecto. @return array<string, string> */
	public static function limpiar( array $entrada ): array {
		$out = array();
		foreach ( self::definiciones() as $clave => $def ) {
			$hex = self::normalizar( $entrada[ $clave ] ?? '' );
			if ( null !== $hex && $hex !== $def[1] ) {
				$out[ $clave ] = $hex;
			}
		}
		return $out;
	}

	/** «#ABC» o «#aabbcc» (con o sin #) → «#aabbcc»; null si no es un color. */
	public static function normalizar( $valor ): ?string {
		if ( ! is_string( $valor ) ) {
			return null;
		}
		$v = strtolower( trim( $valor ) );
		if ( preg_match( '/^#?([0-9a-f]{3})$/', $v, $m ) ) {
			return '#' . $m[1][0] . $m[1][0] . $m[1][1] . $m[1][1] . $m[1][2] . $m[1][2];
		}
		return preg_match( '/^#?([0-9a-f]{6})$/', $v, $m ) ? '#' . $m[1] : null;
	}

	// ---------------------------------------------------------------------
	// Qué se pinta
	// ---------------------------------------------------------------------

	/**
	 * Variables CSS que hay que sobrescribir para estos colores (los elegidos más los derivados).
	 *
	 * @param array<string, string>|null $colores Colores cambiados; null: los guardados.
	 * @return array<string, string> «--asde-x» => valor
	 */
	public static function variables( ?array $colores = null ): array {
		$c = self::limpiar( $colores ?? self::guardados() );
		$v = array();
		foreach ( $c as $clave => $hex ) {
			$v[ '--asde-' . str_replace( '_', '-', $clave ) ] = $hex;
		}

		// Del principal salen sus tonos, salvo los que se hayan elegido a mano.
		if ( isset( $c['primary'] ) ) {
			$p = $c['primary'];
			$v += array(
				'--asde-primary-dark' => self::oscurecer( $p, 0.35 ),
				'--asde-lila'         => self::mezclar( $p, '#ffffff', 0.6 ),
				'--asde-on-primary'   => self::sobre( $p ),
			);
			if ( ! isset( $c['tint'] ) ) {
				$v['--asde-tint'] = self::mezclar( $p, '#ffffff', 0.95 );
			}
			$v['--asde-tint-strong'] = self::mezclar( $p, '#ffffff', 0.88 );
			$v['--asde-tint-line']   = self::mezclar( $p, '#ffffff', 0.82 );
		}
		if ( isset( $c['tint'] ) ) {
			$v['--asde-tint-strong'] ??= self::oscurecer( $c['tint'], 0.04 );
			$v['--asde-tint-line']   ??= self::oscurecer( $c['tint'], 0.10 );
		}
		if ( isset( $c['border'] ) ) {
			$v['--asde-neutral-bg'] = self::mezclar( $c['border'], '#ffffff', 0.45 );
		}

		// De cada estado, su fondo y su texto (el texto se oscurece hasta que se lea bien sobre el fondo).
		foreach ( array( 'ok', 'warn', 'error', 'info' ) as $e ) {
			if ( ! isset( $c[ $e ] ) ) {
				continue;
			}
			$base = $c[ $e ];
			$bg   = self::mezclar( $base, '#ffffff', 0.86 );
			$v[ "--asde-$e-bg" ]   = $bg;
			if ( in_array( $e, array( 'ok', 'warn' ), true ) ) {
				$v[ "--asde-$e-soft" ] = self::mezclar( $base, '#ffffff', 0.92 );
			}
			$v[ "--asde-$e-text" ] = self::texto_legible( $base, $bg );
			if ( 'ok' === $e ) {
				$v['--asde-ok-strong'] = self::mezclar( $base, '#ffffff', 0.78 );
			}
		}
		return $v;
	}

	/**
	 * Bloque de CSS que se añade después del de siempre ('' si no se ha cambiado nada).
	 *
	 * @param array<string, string>|null $colores Colores cambiados; null: los guardados.
	 */
	public static function css( ?array $colores = null ): string {
		$c = self::limpiar( $colores ?? self::guardados() );
		$v = self::variables( $c );
		if ( ! $v ) {
			return '';
		}
		$css = ':root,.asde-ap{';
		foreach ( $v as $nombre => $valor ) {
			$css .= $nombre . ':' . $valor . ';';
		}
		$css .= '}';
		// Con otro color principal, los botones del tema del área (.button) también lo siguen.
		if ( isset( $c['primary'] ) ) {
			$no   = ':not(.asde-btn-link):not(.asde-btn-danger):not(.asde-button-secundario)';
			$css .= ".asde-ap .button$no,.asde-ap input[type=submit]$no,.asde-ap button[type=submit]$no{"
				. 'background:var(--asde-primary);border-color:var(--asde-primary);color:var(--asde-on-primary);}'
				. ".asde-ap .button$no:hover,.asde-ap input[type=submit]$no:hover,.asde-ap button[type=submit]$no:hover{"
				. 'background:var(--asde-primary-dark);border-color:var(--asde-primary-dark);color:var(--asde-on-primary);}';
		}
		return $css;
	}

	/**
	 * Los colores que se usan de verdad (los elegidos o, si no, los de siempre), para los sitios donde
	 * el CSS no llega, como los emails.
	 *
	 * @return array{primary:string, on_primary:string, fondo:string, texto:string, atenuado:string, tarjeta:string}
	 */
	public static function para_email(): array {
		$v   = self::variables();
		$def = self::definiciones();
		return array(
			'primary'    => $v['--asde-primary'] ?? $def['primary'][1],
			'on_primary' => $v['--asde-on-primary'] ?? '#ffffff',
			'fondo'      => $v['--asde-tint'] ?? '#f4f1f7',
			'texto'      => $v['--asde-text'] ?? $def['text'][1],
			'atenuado'   => $v['--asde-muted'] ?? '#555555',
			'tarjeta'    => $v['--asde-surface'] ?? $def['surface'][1],
		);
	}

	// ---------------------------------------------------------------------
	// Legibilidad
	// ---------------------------------------------------------------------

	/**
	 * Avisos de contraste (WCAG AA, 4,5:1) de los colores que se van a usar.
	 *
	 * @param array<string, string>|null $colores Colores cambiados; null: los guardados.
	 * @return string[]
	 */
	public static function avisos( ?array $colores = null ): array {
		$c   = self::limpiar( $colores ?? self::guardados() );
		$v   = self::variables( $c );
		$def = self::definiciones();
		$ef  = static fn( string $clave, string $css ): string => $v[ $css ] ?? $c[ $clave ] ?? $def[ $clave ][1];

		$tarjeta = $ef( 'surface', '--asde-surface' );
		$pares   = array(
			array( 'El texto normal', $ef( 'text', '--asde-text' ), $tarjeta ),
			array( 'El texto atenuado', $ef( 'muted', '--asde-muted' ), $tarjeta ),
			array( 'El color principal (enlaces y pestaña activa)', $ef( 'primary', '--asde-primary' ), $tarjeta ),
			array( 'El texto sobre el color principal (números de página y pasos)', $v['--asde-on-primary'] ?? '#ffffff', $ef( 'primary', '--asde-primary' ) ),
		);
		$out = array();
		foreach ( $pares as list( $que, $color, $fondo ) ) {
			$r = self::contraste( $color, $fondo );
			if ( $r < 4.5 ) {
				$out[] = sprintf( '%s tiene poco contraste con su fondo (%s:1; lo recomendable son 4,5:1 o más): costará leerlo.', $que, number_format( $r, 1, ',', '' ) );
			}
		}
		return $out;
	}

	// ---------------------------------------------------------------------
	// Cálculo de colores
	// ---------------------------------------------------------------------

	/** @return int[] [r, g, b] */
	public static function rgb( string $hex ): array {
		$h = ltrim( self::normalizar( $hex ) ?? '#000000', '#' );
		return array( hexdec( substr( $h, 0, 2 ) ), hexdec( substr( $h, 2, 2 ) ), hexdec( substr( $h, 4, 2 ) ) );
	}

	/** @param int[] $rgb */
	private static function hex( array $rgb ): string {
		return sprintf( '#%02x%02x%02x', max( 0, min( 255, (int) round( $rgb[0] ) ) ), max( 0, min( 255, (int) round( $rgb[1] ) ) ), max( 0, min( 255, (int) round( $rgb[2] ) ) ) );
	}

	/** Mezcla $a con $b: $parte = 1 deja solo $b; 0, solo $a. */
	public static function mezclar( string $a, string $b, float $parte ): string {
		$x = self::rgb( $a );
		$y = self::rgb( $b );
		return self::hex( array( $x[0] + ( $y[0] - $x[0] ) * $parte, $x[1] + ( $y[1] - $x[1] ) * $parte, $x[2] + ( $y[2] - $x[2] ) * $parte ) );
	}

	public static function oscurecer( string $hex, float $parte ): string {
		return self::mezclar( $hex, '#000000', $parte );
	}

	/** Luminancia relativa (WCAG). */
	public static function luminancia( string $hex ): float {
		$l = array_map(
			static function ( int $c ): float {
				$c /= 255;
				return $c <= 0.03928 ? $c / 12.92 : pow( ( $c + 0.055 ) / 1.055, 2.4 );
			},
			self::rgb( $hex )
		);
		return 0.2126 * $l[0] + 0.7152 * $l[1] + 0.0722 * $l[2];
	}

	/** Relación de contraste (1 a 21). */
	public static function contraste( string $a, string $b ): float {
		$la = self::luminancia( $a );
		$lb = self::luminancia( $b );
		return ( max( $la, $lb ) + 0.05 ) / ( min( $la, $lb ) + 0.05 );
	}

	/** Blanco o casi negro, el que mejor se lea sobre este fondo. */
	public static function sobre( string $fondo ): string {
		return self::contraste( '#ffffff', $fondo ) >= self::contraste( '#1a1a1a', $fondo ) ? '#ffffff' : '#1a1a1a';
	}

	/** El color base oscurecido hasta que se lea bien (4,5:1) sobre el fondo. */
	public static function texto_legible( string $base, string $fondo ): string {
		$c = $base;
		for ( $i = 1; $i <= 20 && self::contraste( $c, $fondo ) < 4.5; $i++ ) {
			$c = self::oscurecer( $base, $i * 0.05 );
		}
		return $c;
	}
}
