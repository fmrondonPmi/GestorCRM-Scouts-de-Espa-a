<?php
namespace AsdeBalears\AreaPrivada\Inventario;

defined( 'ABSPATH' ) || exit;

/**
 * Escritor de PDF mínimo (PDF 1.4) para documentos sencillos de texto y tablas,
 * sin librerías externas: no hace falta instalar nada en el hosting.
 *
 * - A4 vertical, las 14 fuentes estándar (Helvetica y Helvetica-Bold), que todos
 *   los lectores de PDF llevan: no se incrusta ninguna fuente.
 * - Codificación WinAnsi (Windows-1252): salen bien los acentos, la ñ, la ç, la
 *   l·l, el € y las comillas «». Lo que no está en esa tabla sale como «?».
 * - Las coordenadas se dan desde arriba a la izquierda, en puntos (1/72 de pulgada).
 */
final class Pdf {

	public const ANCHO = 595.28;
	public const ALTO  = 841.89;

	/** Anchos de Helvetica y Helvetica-Bold (métricas AFM de Adobe, milésimas del tamaño) del carácter 32 al 126. */
	private const ANCHOS = array(
		false => array( 278, 278, 355, 556, 556, 889, 667, 191, 333, 333, 389, 584, 278, 333, 278, 278, 556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 278, 278, 584, 584, 584, 556, 1015, 667, 667, 722, 722, 667, 611, 778, 722, 278, 500, 667, 556, 833, 722, 778, 667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 278, 278, 278, 469, 556, 333, 556, 556, 500, 556, 556, 278, 556, 556, 222, 222, 500, 222, 833, 556, 556, 556, 556, 333, 500, 278, 556, 500, 722, 500, 500, 500, 334, 260, 334, 584 ),
		true  => array( 278, 333, 474, 556, 556, 889, 722, 238, 333, 333, 389, 584, 278, 333, 278, 278, 556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 333, 333, 584, 584, 584, 611, 975, 722, 722, 722, 722, 667, 611, 778, 722, 278, 556, 722, 611, 833, 722, 778, 667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 333, 278, 333, 584, 556, 333, 556, 611, 556, 611, 556, 333, 611, 611, 278, 278, 556, 278, 889, 611, 611, 611, 611, 389, 556, 333, 611, 556, 778, 556, 556, 500, 389, 280, 389, 584 ),
	);

	/** Caracteres de Windows-1252 por encima del 126 que no son una letra con acento. */
	private const ANCHOS_EXTRA = array(
		"\x80" => 556, // €
		"\x85" => 1000, // …
		"\x91" => 222, // ‘
		"\x92" => 222, // ’
		"\x93" => 333, // “
		"\x94" => 333, // ”
		"\x95" => 350, // •
		"\x96" => 556, // –
		"\x97" => 1000, // —
		"\xA1" => 333, // ¡
		"\xAA" => 370, // ª
		"\xAB" => 556, // «
		"\xB7" => 278, // ·
		"\xBA" => 365, // º
		"\xBB" => 556, // »
		"\xBF" => 611, // ¿
	);

	/** @var string[] Contenido de cada página (operadores de PDF). */
	private array $paginas = array();

	private int $actual = -1;

	private string $titulo;

	public function __construct( string $titulo = '' ) {
		$this->titulo = $titulo;
	}

	public function nueva_pagina(): void {
		$this->paginas[] = '';
		$this->actual    = count( $this->paginas ) - 1;
	}

	public function paginas(): int {
		return count( $this->paginas );
	}

	/** Vuelve a una página ya creada (p. ej. para poner «Página X de Y» al final). */
	public function ir_a( int $n ): void {
		if ( isset( $this->paginas[ $n ] ) ) {
			$this->actual = $n;
		}
	}

	/** Texto en una línea. $alinear: 'izquierda', 'derecha' (x es el borde derecho) o 'centro'. */
	public function texto( float $x, float $y, string $texto, float $tamano = 10, bool $negrita = false, string $alinear = 'izquierda', float $gris = 0 ): void {
		$bytes = self::a_winansi( $texto );
		if ( '' === $bytes ) {
			return;
		}
		$ancho = $this->ancho_bytes( $bytes, $tamano, $negrita );
		if ( 'derecha' === $alinear ) {
			$x -= $ancho;
		} elseif ( 'centro' === $alinear ) {
			$x -= $ancho / 2;
		}
		$this->escribir(
			sprintf( 'BT %s g /F%d %s Tf %s %s Td (%s) Tj ET', self::n( $gris ), $negrita ? 2 : 1, self::n( $tamano ), self::n( $x ), self::n( self::ALTO - $y ), self::escapar( $bytes ) )
		);
	}

	public function linea( float $x1, float $y1, float $x2, float $y2, float $grosor = 0.5, float $gris = 0 ): void {
		$this->escribir( sprintf( '%s G %s w %s %s m %s %s l S', self::n( $gris ), self::n( $grosor ), self::n( $x1 ), self::n( self::ALTO - $y1 ), self::n( $x2 ), self::n( self::ALTO - $y2 ) ) );
	}

	/** Rectángulo relleno de gris (0 = negro, 1 = blanco). */
	public function rectangulo( float $x, float $y, float $ancho, float $alto, float $gris ): void {
		$this->escribir( sprintf( '%s g %s %s %s %s re f', self::n( $gris ), self::n( $x ), self::n( self::ALTO - $y - $alto ), self::n( $ancho ), self::n( $alto ) ) );
	}

	/** Ancho del texto en puntos. */
	public function ancho( string $texto, float $tamano = 10, bool $negrita = false ): float {
		return $this->ancho_bytes( self::a_winansi( $texto ), $tamano, $negrita );
	}

	/**
	 * Parte el texto en líneas que caben en ese ancho (por palabras; una palabra
	 * más larga que la línea se corta).
	 *
	 * @return string[]
	 */
	public function cortar( string $texto, float $ancho, float $tamano = 10, bool $negrita = false ): array {
		$lineas = array();
		foreach ( preg_split( '/\R/u', $texto ) ?: array( '' ) as $parrafo ) {
			$actual = '';
			foreach ( preg_split( '/\s+/u', trim( $parrafo ), -1, PREG_SPLIT_NO_EMPTY ) ?: array() as $palabra ) {
				$prueba = '' === $actual ? $palabra : $actual . ' ' . $palabra;
				if ( $this->ancho( $prueba, $tamano, $negrita ) <= $ancho ) {
					$actual = $prueba;
					continue;
				}
				if ( '' !== $actual ) {
					$lineas[] = $actual;
				}
				// Palabra que no cabe sola: se corta a trozos.
				while ( $this->ancho( $palabra, $tamano, $negrita ) > $ancho && mb_strlen( $palabra ) > 1 ) {
					$n = mb_strlen( $palabra );
					while ( $n > 1 && $this->ancho( mb_substr( $palabra, 0, $n ), $tamano, $negrita ) > $ancho ) {
						--$n;
					}
					$lineas[] = mb_substr( $palabra, 0, $n );
					$palabra  = mb_substr( $palabra, $n );
				}
				$actual = $palabra;
			}
			$lineas[] = $actual;
		}
		return $lineas;
	}

	/** El documento entero. */
	public function salida(): string {
		if ( ! $this->paginas ) {
			$this->nueva_pagina();
		}
		$objetos = array(
			1 => '<< /Type /Catalog /Pages 2 0 R >>',
			2 => '', // Se rellena cuando se sabe el número de cada página.
			3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
			4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>',
			5 => '<< /Title (' . self::escapar( self::a_winansi( $this->titulo ) ) . ') /Producer (Gestor CRM para Grupos Scouts) /CreationDate (D:' . gmdate( 'YmdHis' ) . "Z) >>",
		);
		$hijas = array();
		$n     = 6;
		foreach ( $this->paginas as $contenido ) {
			$hijas[]             = $n . ' 0 R';
			$objetos[ $n ]       = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' . self::n( self::ANCHO ) . ' ' . self::n( self::ALTO ) . '] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents ' . ( $n + 1 ) . ' 0 R >>';
			$objetos[ $n + 1 ]   = '<< /Length ' . strlen( $contenido ) . " >>\nstream\n" . $contenido . "\nendstream";
			$n                  += 2;
		}
		$objetos[2] = '<< /Type /Pages /Kids [' . implode( ' ', $hijas ) . '] /Count ' . count( $hijas ) . ' >>';

		$pdf     = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
		$offsets = array();
		foreach ( $objetos as $num => $cuerpo ) {
			$offsets[ $num ] = strlen( $pdf );
			$pdf            .= $num . " 0 obj\n" . $cuerpo . "\nendobj\n";
		}
		$xref = strlen( $pdf );
		$pdf .= "xref\n0 " . ( count( $objetos ) + 1 ) . "\n0000000000 65535 f \n";
		foreach ( $offsets as $offset ) {
			$pdf .= sprintf( "%010d 00000 n \n", $offset );
		}
		$pdf .= 'trailer << /Size ' . ( count( $objetos ) + 1 ) . " /Root 1 0 R /Info 5 0 R >>\nstartxref\n" . $xref . "\n%%EOF\n";
		return $pdf;
	}

	// ---------------------------------------------------------------------

	private function escribir( string $operadores ): void {
		if ( $this->actual < 0 ) {
			$this->nueva_pagina();
		}
		$this->paginas[ $this->actual ] .= $operadores . "\n";
	}

	private function ancho_bytes( string $bytes, float $tamano, bool $negrita ): float {
		$total = 0;
		$len   = strlen( $bytes );
		for ( $i = 0; $i < $len; $i++ ) {
			$total += self::ancho_caracter( $bytes[ $i ], $negrita );
		}
		return $total * $tamano / 1000;
	}

	private static function ancho_caracter( string $byte, bool $negrita ): int {
		$c = ord( $byte );
		if ( $c >= 32 && $c <= 126 ) {
			return self::ANCHOS[ $negrita ][ $c - 32 ];
		}
		if ( isset( self::ANCHOS_EXTRA[ $byte ] ) ) {
			return self::ANCHOS_EXTRA[ $byte ];
		}
		// Letras con acento: como la letra sin él (á como a, Ñ como N).
		$base = remove_accents( (string) mb_convert_encoding( $byte, 'UTF-8', 'Windows-1252' ) );
		if ( 1 === strlen( $base ) && ord( $base ) >= 32 && ord( $base ) <= 126 ) {
			return self::ANCHOS[ $negrita ][ ord( $base ) - 32 ];
		}
		return 556;
	}

	/** UTF-8 → Windows-1252 (lo que no existe en esa tabla, «?»), sin saltos de línea ni caracteres de control. */
	private static function a_winansi( string $texto ): string {
		$texto = (string) preg_replace( '/[\x00-\x1F\x7F]+/u', ' ', $texto );
		$texto = strtr(
			$texto,
			array(
				"\u{00A0}" => ' ', // Espacio de no separación (sale en los importes de number_format_i18n).
				"\u{202F}" => ' ',
			)
		);
		$out = '';
		foreach ( mb_str_split( $texto ) as $ch ) {
			$b    = mb_convert_encoding( $ch, 'Windows-1252', 'UTF-8' );
			$out .= ( '?' === $b && '?' !== $ch ) ? '?' : $b;
		}
		return $out;
	}

	private static function escapar( string $bytes ): string {
		return strtr(
			$bytes,
			array(
				'\\' => '\\\\',
				'('  => '\\(',
				')'  => '\\)',
			)
		);
	}

	private static function n( float $v ): string {
		return rtrim( rtrim( sprintf( '%.2F', $v ), '0' ), '.' ) ?: '0';
	}
}
