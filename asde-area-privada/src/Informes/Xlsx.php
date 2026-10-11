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
	 */
	public static function descargar( string $archivo, string $hoja, array $cabecera, array $filas ): void {
		$datos = self::generar( $hoja, $cabecera, $filas );
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
	 */
	public static function generar( string $hoja, array $cabecera, array $filas ): string {
		$hoja = self::nombre_hoja( $hoja );
		$cols = max( 1, count( $cabecera ) );
		$ult  = self::columna( $cols - 1 ) . ( count( $filas ) + 1 );

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
					. self::xml( "'" . str_replace( "'", "''", $hoja ) . "'!\$A\$1:\$" . self::columna( $cols - 1 ) . '$' . ( count( $filas ) + 1 ) )
					. '</definedName></definedNames>'
					. '</workbook>',
				'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
					. '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
					. '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
					. '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
					. '</Relationships>',
				'xl/styles.xml'              => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
					. '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
					. '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
					. '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
					. '<fill><patternFill patternType="solid"><fgColor rgb="FFEDE7F3"/><bgColor indexed="64"/></patternFill></fill></fills>'
					. '<borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border>'
					. '<border><left/><right/><top/><bottom style="thin"><color rgb="FF999999"/></bottom><diagonal/></border></borders>'
					. '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
					. '<cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
					. '<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"/></cellXfs>'
					. '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
					. '</styleSheet>',
				'xl/worksheets/sheet1.xml'   => self::hoja( $cabecera, $filas, $ult ),
			)
		);
	}

	/** El XML de la hoja: columnas, cabecera fija, filas y filtros. */
	private static function hoja( array $cabecera, array $filas, string $ult ): string {
		$anchos = array();
		foreach ( array_merge( array( $cabecera ), $filas ) as $fila ) {
			foreach ( array_values( $fila ) as $i => $v ) {
				$anchos[ $i ] = max( $anchos[ $i ] ?? 0, mb_strlen( (string) $v ) );
			}
		}
		$cols = '';
		foreach ( $anchos as $i => $n ) {
			$cols .= sprintf( '<col min="%1$d" max="%1$d" width="%2$d" customWidth="1"/>', $i + 1, min( 60, max( 8, $n + 2 ) ) );
		}

		$xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
			. '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
			. ( '' !== $cols ? '<cols>' . $cols . '</cols>' : '' )
			. '<sheetData>'
			. self::fila( 1, $cabecera, true );
		foreach ( array_values( $filas ) as $n => $fila ) {
			$xml .= self::fila( $n + 2, $fila, false );
		}
		return $xml . '</sheetData><autoFilter ref="A1:' . $ult . '"/></worksheet>';
	}

	private static function fila( int $n, array $valores, bool $cabecera ): string {
		$xml = '<row r="' . $n . '">';
		foreach ( array_values( $valores ) as $i => $v ) {
			$ref = self::columna( $i ) . $n;
			$num = $cabecera ? null : self::numero( $v );
			if ( null !== $num ) {
				$xml .= '<c r="' . $ref . '"><v>' . $num . '</v></c>';
			} elseif ( '' !== (string) $v ) {
				$xml .= '<c r="' . $ref . '" t="inlineStr"' . ( $cabecera ? ' s="1"' : '' ) . '><is><t xml:space="preserve">' . self::xml( (string) $v ) . '</t></is></c>';
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
