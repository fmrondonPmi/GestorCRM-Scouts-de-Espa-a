<?php
namespace AsdeBalears\AreaPrivada\Informes;

defined( 'ABSPATH' ) || exit;

/**
 * Hoja de cálculo .xlsx (v0.29): el informe se descarga como Excel de verdad, no como CSV.
 *
 * Sin librerías: un .xlsx es un zip con unos pocos XML (formato Office Open XML). Una sola
 * hoja, la primera fila (cabecera) en negrita, fija al desplazarse y con filtros, el ancho de
 * cada columna según su contenido y los números como números (se pueden sumar). El texto va
 * como «texto en línea»: Excel no lo interpreta nunca como fórmula (no hace falta la comilla
 * de Generador::celda_segura del CSV).
 *
 * El zip se monta en memoria (zip()): nada pasa por el disco.
 */
final class Xlsx {

	/** Excel no admite nombres de hoja de más de 31 caracteres. */
	private const MAX_HOJA = 31;

	/**
	 * Envía la descarga y no devuelve nada (después, exit).
	 *
	 * @param string[]                          $cabecera
	 * @param array<int, array<int, mixed>>     $filas
	 * @param array                             $opciones Ver generar().
	 */
	public static function descargar( string $archivo, string $hoja, array $cabecera, array $filas, array $opciones = array() ): void {
		$datos = self::generar( $hoja, $cabecera, $filas, $opciones );
		nocache_headers();
		header( 'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' );
		header( 'Content-Disposition: attachment; filename="' . $archivo . '"' );
		header( 'Content-Length: ' . strlen( $datos ) );
		echo $datos; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- es un fichero .xlsx (binario), no HTML
	}

	/**
	 * El fichero .xlsx (binario).
	 *
	 * @param string[]                      $cabecera
	 * @param array<int, array<int, mixed>> $filas
	 * @param array{titulo?:string, subtitulo?:string, tipos?:string[], colores?:array<string,string>, totales?:array} $opciones
	 *        Sin opciones, la hoja sencilla de los informes. Con ellas (v0.37, «Excel bonito»): título y
	 *        subtítulo, cabecera de color, bordes, filas alternas, formato de moneda («euros»), enteros
	 *        («entero») y estados con color («estado» + colores texto => verde|ambar|rojo|gris), fila de
	 *        totales, ajustada a una página de ancho al imprimir. tipos: uno por columna (texto por defecto).
	 */
	public static function generar( string $hoja, array $cabecera, array $filas, array $opciones = array() ): string {
		$hoja = self::nombre_hoja( $hoja );
		$cols = max( 1, count( $cabecera ) );
		$cab  = $opciones ? 4 : 1; // Fila de la cabecera de la tabla (con título y subtítulo, la 4).
		$ult  = self::columna( $cols - 1 ) . ( count( $filas ) + $cab );

		return self::zip(
			array(
				'[Content_Types].xml'        => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
					. '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
					. '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
					. '<Default Extension="xml" ContentType="application/xml"/>'
					. '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
					. '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
					. '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
					. '</Types>',
				'_rels/.rels'                => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
					. '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
					. '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
					. '</Relationships>',
				'xl/workbook.xml'            => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
					. '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
					. '<sheets><sheet name="' . self::xml( $hoja ) . '" sheetId="1" r:id="rId1"/></sheets>'
					. '<definedNames><definedName name="_xlnm._FilterDatabase" localSheetId="0" hidden="1">'
					. self::xml( "'" . str_replace( "'", "''", $hoja ) . "'!\$A\$" . $cab . ":\$" . self::columna( $cols - 1 ) . '$' . ( count( $filas ) + $cab ) )
					. '</definedName></definedNames>'
					. '</workbook>',
				'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
					. '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
					. '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
					. '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
					. '</Relationships>',
				'xl/styles.xml'              => self::estilos(),
				'xl/worksheets/sheet1.xml'   => self::hoja( $cabecera, $filas, $ult, $opciones ),
			)
		);
	}

	/**
	 * Hoja de estilos. Los dos primeros son los de siempre (celda normal y cabecera sencilla); del 2 en
	 * adelante los del «Excel bonito» (v0.37): ver los comentarios de cada uno.
	 */
	private static function estilos(): string {
		$borde   = '<border><left style="thin"><color rgb="FFD9D9D9"/></left><right style="thin"><color rgb="FFD9D9D9"/></right><top style="thin"><color rgb="FFD9D9D9"/></top><bottom style="thin"><color rgb="FFD9D9D9"/></bottom><diagonal/></border>';
		$relleno = static fn( string $rgb ) => '<fill><patternFill patternType="solid"><fgColor rgb="FF' . $rgb . '"/><bgColor indexed="64"/></patternFill></fill>';
		$xf      = static fn( int $fmt, int $fuente, int $fondo, int $marco, string $alin = '' ) => '<xf numFmtId="' . $fmt . '" fontId="' . $fuente . '" fillId="' . $fondo . '" borderId="' . $marco . '" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1"' . ( '' !== $alin ? ' applyAlignment="1">' . $alin . '</xf>' : '/>' );
		$izq     = '<alignment vertical="top" wrapText="1"/>';
		$der     = '<alignment horizontal="right" vertical="top"/>';
		$cen     = '<alignment horizontal="center" vertical="top"/>';
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
			. '<numFmts count="1"><numFmt numFmtId="164" formatCode="#,##0.00\ &quot;€&quot;;[Red]\-#,##0.00\ &quot;€&quot;"/></numFmts>'
			. '<fonts count="6">'
			. '<font><sz val="11"/><name val="Calibri"/></font>'
			. '<font><b/><sz val="11"/><name val="Calibri"/></font>'
			. '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'
			. '<font><b/><sz val="16"/><color rgb="FF4B2E83"/><name val="Calibri"/></font>'
			. '<font><i/><sz val="10"/><color rgb="FF666666"/><name val="Calibri"/></font>'
			. '<font><b/><sz val="10"/><color rgb="FF333333"/><name val="Calibri"/></font>'
			. '</fonts>'
			. '<fills count="9"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
			. $relleno( 'EDE7F3' ) . $relleno( '4B2E83' ) . $relleno( 'F7F4FA' ) . $relleno( 'DDF1E1' ) . $relleno( 'FFF0C7' ) . $relleno( 'F8D7D7' ) . $relleno( 'E7E7E7' )
			. '</fills>'
			. '<borders count="4"><border><left/><right/><top/><bottom/><diagonal/></border>'
			. '<border><left/><right/><top/><bottom style="thin"><color rgb="FF999999"/></bottom><diagonal/></border>'
			. $borde
			. '<border><left style="thin"><color rgb="FFD9D9D9"/></left><right style="thin"><color rgb="FFD9D9D9"/></right><top style="medium"><color rgb="FF4B2E83"/></top><bottom style="thin"><color rgb="FFD9D9D9"/></bottom><diagonal/></border>'
			. '</borders>'
			. '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
			. '<cellXfs count="18">'
			. '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'                                  // 0 normal
			. '<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"/>' // 1 cabecera sencilla
			. $xf( 0, 2, 3, 2, '<alignment horizontal="center" vertical="center" wrapText="1"/>' )              // 2 cabecera
			. $xf( 0, 3, 0, 0 )                                                                                 // 3 título
			. $xf( 0, 4, 0, 0 )                                                                                 // 4 subtítulo
			. $xf( 0, 0, 0, 2, $izq )                                                                           // 5 texto
			. $xf( 0, 0, 4, 2, $izq )                                                                           // 6 texto, fila alterna
			. $xf( 164, 0, 0, 2, $der )                                                                         // 7 euros
			. $xf( 164, 0, 4, 2, $der )                                                                         // 8 euros, alterna
			. $xf( 1, 0, 0, 2, $cen )                                                                           // 9 entero
			. $xf( 1, 0, 4, 2, $cen )                                                                           // 10 entero, alterna
			. $xf( 0, 5, 5, 2, $cen )                                                                           // 11 estado verde
			. $xf( 0, 5, 6, 2, $cen )                                                                           // 12 estado ámbar
			. $xf( 0, 5, 7, 2, $cen )                                                                           // 13 estado rojo
			. $xf( 0, 5, 8, 2, $cen )                                                                           // 14 estado gris
			. $xf( 0, 1, 2, 3, $izq )                                                                           // 15 total, texto
			. $xf( 164, 1, 2, 3, $der )                                                                         // 16 total, euros
			. $xf( 1, 1, 2, 3, $cen )                                                                           // 17 total, entero
			. '</cellXfs>'
			. '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
			. '</styleSheet>';
	}

	/** Estilo de una celda del «Excel bonito». */
	private static function estilo_celda( string $tipo, bool $alterna, $valor, array $colores ): int {
		switch ( $tipo ) {
			case 'euros':
				return $alterna ? 8 : 7;
			case 'entero':
				return $alterna ? 10 : 9;
			case 'estado':
				return array(
					'verde' => 11,
					'ambar' => 12,
					'rojo'  => 13,
					'gris'  => 14,
				)[ $colores[ (string) $valor ] ?? 'gris' ] ?? 14;
			default:
				return $alterna ? 6 : 5;
		}
	}

	/** El XML de la hoja: columnas, cabecera fija, filas y filtros. */
	private static function hoja( array $cabecera, array $filas, string $ult, array $op = array() ): string {
		$bonito  = (bool) $op;
		$tipos   = array_values( (array) ( $op['tipos'] ?? array() ) );
		$totales = $bonito ? array_values( (array) ( $op['totales'] ?? array() ) ) : array();
		$anchos  = array();
		foreach ( array_merge( array( $cabecera ), $filas, $totales ? array( $totales ) : array() ) as $fila ) {
			foreach ( array_values( $fila ) as $i => $v ) {
				$largo        = is_float( $v ) ? 12 : mb_strlen( (string) $v );
				$anchos[ $i ] = max( $anchos[ $i ] ?? 0, $largo );
			}
		}
		$cols = '';
		foreach ( $anchos as $i => $n ) {
			$cols .= sprintf( '<col min="%1$d" max="%1$d" width="%2$d" customWidth="1"/>', $i + 1, min( 60, max( $bonito ? 12 : 8, $n + 2 ) ) );
		}

		$cab = $bonito ? 4 : 1; // Fila de la cabecera de la tabla (con título y subtítulo, la 4).
		$xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
			. ( $bonito ? '<sheetPr><pageSetUpPr fitToPage="1"/></sheetPr>' : '' )
			. '<sheetViews><sheetView workbookViewId="0"' . ( $bonito ? ' showGridLines="0"' : '' ) . '><pane ySplit="' . $cab . '" topLeftCell="A' . ( $cab + 1 ) . '" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
			. ( '' !== $cols ? '<cols>' . $cols . '</cols>' : '' )
			. '<sheetData>';
		if ( $bonito ) {
			$xml .= '<row r="1" ht="26" customHeight="1">' . self::texto_celda( 'A1', (string) ( $op['titulo'] ?? '' ), 3 ) . '</row>'
				. '<row r="2">' . self::texto_celda( 'A2', (string) ( $op['subtitulo'] ?? '' ), 4 ) . '</row>'
				. '<row r="3"/>'
				. self::fila( $cab, $cabecera, true, 2, 32 );
		} else {
			$xml .= self::fila( 1, $cabecera, true );
		}
		foreach ( array_values( $filas ) as $n => $fila ) {
			if ( $bonito ) {
				$estilos = array();
				foreach ( array_values( $fila ) as $i => $v ) {
					$estilos[ $i ] = self::estilo_celda( $tipos[ $i ] ?? 'texto', 1 === $n % 2, $v, (array) ( $op['colores'] ?? array() ) );
				}
				$xml .= self::fila( $n + $cab + 1, $fila, false, $estilos );
			} else {
				$xml .= self::fila( $n + 2, $fila, false );
			}
		}
		if ( $totales ) {
			$estilos = array();
			foreach ( $totales as $i => $v ) {
				$estilos[ $i ] = array(
					'euros'  => 16,
					'entero' => 17,
				)[ $tipos[ $i ] ?? 'texto' ] ?? 15;
			}
			$xml .= self::fila( count( $filas ) + $cab + 1, $totales, false, $estilos );
		}
		$xml .= '</sheetData><autoFilter ref="A' . $cab . ':' . $ult . '"/>';
		if ( $bonito ) {
			$ultima = self::columna( max( 0, count( $cabecera ) - 1 ) );
			$xml   .= '<mergeCells count="2"><mergeCell ref="A1:' . $ultima . '1"/><mergeCell ref="A2:' . $ultima . '2"/></mergeCells>'
				. '<pageMargins left="0.5" right="0.5" top="0.6" bottom="0.6" header="0.3" footer="0.3"/>'
				. '<pageSetup paperSize="9" orientation="landscape" fitToWidth="1" fitToHeight="0"/>';
		}
		return $xml . '</worksheet>';
	}

	private static function texto_celda( string $ref, string $texto, int $estilo ): string {
		return '' === $texto ? '' : '<c r="' . $ref . '" s="' . $estilo . '" t="inlineStr"><is><t xml:space="preserve">' . self::xml( $texto ) . '</t></is></c>';
	}

	/**
	 * @param int|int[] $estilo Para la cabecera, su estilo (por defecto el sencillo: 1); para una fila, el
	 *                          estilo de cada celda (por posición), o vacío para la hoja sencilla.
	 */
	private static function fila( int $n, array $valores, bool $cabecera, $estilo = array(), int $alto = 0 ): string {
		$xml = '<row r="' . $n . '"' . ( $alto ? ' ht="' . $alto . '" customHeight="1"' : '' ) . '>';
		foreach ( array_values( $valores ) as $i => $v ) {
			$ref = self::columna( $i ) . $n;
			$s   = $cabecera ? ( is_int( $estilo ) ? $estilo : 1 ) : ( is_array( $estilo ) ? ( $estilo[ $i ] ?? null ) : null );
			$at  = null !== $s ? ' s="' . $s . '"' : '';
			$num = $cabecera ? null : self::numero( $v );
			if ( null !== $num ) {
				$xml .= '<c r="' . $ref . '"' . $at . '><v>' . $num . '</v></c>';
			} elseif ( '' !== (string) $v ) {
				$xml .= '<c r="' . $ref . '" t="inlineStr"' . $at . '><is><t xml:space="preserve">' . self::xml( (string) $v ) . '</t></is></c>';
			} elseif ( null !== $s ) {
				$xml .= '<c r="' . $ref . '"' . $at . '/>'; // Vacía, pero con su borde y su color.
			}
		}
		return $xml . '</row>';
	}

	/**
	 * El valor como número de Excel, o null si es texto. Solo los que son números de verdad:
	 * «12», «-3,50», «1234.5». Un cero delante («07001», un código postal) o más de 15 cifras
	 * (Excel las pierde) los deja como texto, igual que los teléfonos con prefijo «+34».
	 */
	public static function numero( $v ): ?string {
		if ( is_int( $v ) || is_float( $v ) ) {
			return (string) $v;
		}
		$v = trim( (string) $v );
		if ( ! preg_match( '/^-?(0|[1-9]\d{0,14})([.,]\d{1,6})?$/', $v ) ) {
			return null;
		}
		return str_replace( ',', '.', $v );
	}

	/** 0 → A, 25 → Z, 26 → AA. */
	public static function columna( int $i ): string {
		$letras = '';
		for ( ++$i; $i > 0; $i = intdiv( $i - 1, 26 ) ) {
			$letras = chr( 65 + ( $i - 1 ) % 26 ) . $letras;
		}
		return $letras;
	}

	/** Nombre de hoja válido para Excel: sin []:*?/\, sin comillas en los extremos, 31 caracteres. */
	public static function nombre_hoja( string $nombre ): string {
		$nombre = trim( (string) preg_replace( array( '/[\[\]:*?\/\\\\]+/u', '/\s+/u' ), ' ', $nombre ), " '" );
		$nombre = mb_substr( '' !== $nombre ? $nombre : 'Informe', 0, self::MAX_HOJA );
		return trim( $nombre, " '" ) ?: 'Informe';
	}

	/** Texto para el XML: escapado y sin los caracteres de control que el XML no admite. */
	private static function xml( string $s ): string {
		$s = (string) preg_replace( '/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', wp_check_invalid_utf8( $s, true ) );
		return htmlspecialchars( $s, ENT_XML1 | ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * El zip, en memoria (revisión de la v0.29): sin fichero temporal, para que los datos del
	 * informe (también los de salud) no pasen por el disco del servidor (regla 1 del CLAUDE.md),
	 * y sin depender de ZipArchive. Cada fichero va comprimido con DEFLATE (o sin comprimir si
	 * el servidor no tiene zlib), con su CRC-32, como pide el formato zip.
	 *
	 * @param array<string, string> $ficheros ruta dentro del zip => contenido
	 */
	private static function zip( array $ficheros ): string {
		$zip     = '';
		$central = '';
		foreach ( $ficheros as $nombre => $datos ) {
			$comprimido = function_exists( 'gzdeflate' ) ? gzdeflate( $datos, 6 ) : false;
			$metodo     = false === $comprimido ? 0 : 8;
			$cuerpo     = false === $comprimido ? $datos : $comprimido;
			// Versión, marcas, método, hora y fecha (1/1/1980), CRC-32, tamaños y longitud del nombre.
			$cabecera = pack( 'vvvvvVVVvv', 20, 0, $metodo, 0, 0x21, crc32( $datos ), strlen( $cuerpo ), strlen( $datos ), strlen( (string) $nombre ), 0 );
			$central .= pack( 'Vv', 0x02014b50, 20 ) . $cabecera . pack( 'vvvVV', 0, 0, 0, 0, strlen( $zip ) ) . $nombre;
			$zip     .= pack( 'V', 0x04034b50 ) . $cabecera . $nombre . $cuerpo;
		}
		$n = count( $ficheros );
		return $zip . $central . pack( 'VvvvvVVv', 0x06054b50, 0, 0, $n, $n, strlen( $central ), strlen( $zip ), 0 );
	}
}
