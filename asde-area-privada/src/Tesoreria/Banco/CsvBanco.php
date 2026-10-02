<?php
namespace AsdeBalears\AreaPrivada\Tesoreria\Banco;

defined( 'ABSPATH' ) || exit;

/**
 * Lector de movimientos en CSV (p. ej. el que descarga la banca online).
 *
 * El formato de cada banco es distinto, así que:
 * - detecta el separador (; , o tabulador) y la fila de títulos (saltando las
 *   líneas informativas de antes, de donde saca la cuenta si aparece un IBAN);
 * - reconoce las columnas por su título (Fecha, Fecha valor, Concepto, Importe o
 *   Cargo/Abono…). Si no lo consigue, devuelve `necesita_mapeo` y los títulos
 *   para elegirlas a mano (se recuerda para la próxima vez);
 * - entiende importes como 1.234,56 · -45,00 · 1234.56 y fechas dd/mm/aaaa,
 *   dd-mm-aa o aaaa-mm-dd.
 */
final class CsvBanco {

	/** Campos que se pueden asignar a columnas. */
	public const CAMPOS = array(
		'fecha'       => 'Fecha de la operación',
		'fecha_valor' => 'Fecha valor',
		'concepto'    => 'Concepto',
		'importe'     => 'Importe (con signo)',
		'cargo'       => 'Cargo / debe',
		'abono'       => 'Abono / haber',
		'ordenante'   => 'Ordenante o beneficiario',
		'referencia'  => 'Referencia',
	);

	private const SINONIMOS = array(
		'fecha'       => array( 'fecha', 'fecha operacion', 'f operacion', 'fecha oper', 'fecha contable', 'f contable', 'fecha movimiento', 'fecha mov', 'data', 'data operacio' ),
		'fecha_valor' => array( 'fecha valor', 'f valor', 'valor', 'data valor' ),
		'concepto'    => array( 'concepto', 'descripcion', 'detalle', 'movimiento', 'concepte', 'observaciones', 'mas datos', 'informacion adicional', 'concepto comun' ),
		'importe'     => array( 'importe', 'import', 'cantidad', 'importe eur', 'importe euros' ),
		'cargo'       => array( 'cargo', 'cargos', 'debe', 'debito', 'salida', 'salidas' ),
		'abono'       => array( 'abono', 'abonos', 'haber', 'credito', 'entrada', 'entradas', 'ingreso', 'ingresos' ),
		'ordenante'   => array( 'ordenante', 'remitente', 'beneficiario', 'ordenante beneficiario', 'beneficiario ordenante', 'contrapartida' ),
		'referencia'  => array( 'referencia', 'ref', 'referencia 1', 'documento', 'n documento', 'num documento', 'numero documento' ),
	);

	/**
	 * @param array<string, int>|null $mapeo campo => índice de columna; null = automático.
	 * @return array{cuentas: array[], movimientos: array[], errores: string[], avisos: string[], columnas: string[], mapeo: array<string,int>, necesita_mapeo: bool, firma: string, codificacion: string}
	 */
	public static function leer( string $contenido, ?array $mapeo = null, string $codificacion = 'auto' ): array {
		$cod   = 'auto' === $codificacion ? Codificacion::detectar( $contenido ) : $codificacion;
		$texto = Codificacion::texto_utf8( $contenido, $cod );
		$res   = array(
			'cuentas'        => array(),
			'movimientos'    => array(),
			'errores'        => array(),
			'avisos'         => array(),
			'columnas'       => array(),
			'mapeo'          => array(),
			'necesita_mapeo' => false,
			'firma'          => '',
			'codificacion'   => $cod,
		);

		$lineas = array_values( array_filter( preg_split( '/\r\n|\r|\n/', $texto ), static fn( $l ) => '' !== trim( $l ) ) );
		if ( ! $lineas ) {
			$res['errores'][] = 'El fichero está vacío.';
			return $res;
		}
		$sep = self::separador( array_slice( $lineas, 0, 30 ) );

		// Fila de títulos: la primera con al menos dos columnas reconocibles.
		$cab = null;
		foreach ( array_slice( $lineas, 0, 30, true ) as $i => $l ) {
			$celdas = str_getcsv( $l, $sep, '"', '' );
			if ( count( array_filter( array_map( array( self::class, 'campo_de' ), $celdas ) ) ) >= 2 ) {
				$cab = $i;
				break;
			}
		}
		if ( null === $cab ) {
			// Títulos desconocidos: la primera fila con varias columnas seguida de una con fecha.
			foreach ( array_slice( $lineas, 0, 30, true ) as $i => $l ) {
				$siguiente = str_getcsv( (string) ( $lineas[ $i + 1 ] ?? '' ), $sep, '"', '' );
				if ( count( str_getcsv( $l, $sep, '"', '' ) ) >= 2 && array_filter( $siguiente, static fn( $c ) => null !== self::fecha( (string) $c ) ) ) {
					$cab = $i;
					break;
				}
			}
		}
		if ( null === $cab ) {
			$res['errores'][] = 'No se encuentra la fila de títulos de las columnas (Fecha, Concepto, Importe…). ¿Es el CSV de movimientos del banco?';
			return $res;
		}

		$titulos         = array_map( static fn( $t ) => trim( (string) $t ), str_getcsv( $lineas[ $cab ], $sep, '"', '' ) );
		$res['columnas'] = $titulos;
		$res['firma']    = md5( implode( '|', array_map( array( self::class, 'normalizar' ), $titulos ) ) );

		// Cuenta: IBAN en las líneas informativas de antes de los títulos.
		$cuenta = 'CSV';
		foreach ( array_slice( $lineas, 0, $cab ) as $l ) {
			// IBAN español: ES + control + entidad (4) + oficina (4) + DC (2) + cuenta (10).
			if ( preg_match( '/ES\d{2}(\d{4})(\d{4})\d{2}(\d{10})/i', (string) preg_replace( '/[\s-]/', '', $l ), $m ) ) {
				$cuenta = Norma43::mascara( $m[1], $m[2], $m[3] );
				break;
			}
		}

		$mapeo        = null === $mapeo ? self::mapeo_automatico( $titulos ) : array_filter( $mapeo, static fn( $i ) => is_int( $i ) && $i >= 0 && $i < count( $titulos ) );
		$res['mapeo'] = $mapeo;
		if ( ! isset( $mapeo['fecha'] ) || ! ( isset( $mapeo['importe'] ) || isset( $mapeo['cargo'] ) || isset( $mapeo['abono'] ) ) ) {
			$res['necesita_mapeo'] = true;
			$res['errores'][]      = 'No se han reconocido todas las columnas necesarias (al menos la fecha y el importe, o cargo y abono). Elige a qué corresponde cada columna.';
			return $res;
		}
		$extra_concepto = array_keys( array_filter( array_map( array( self::class, 'campo_de' ), $titulos ), static fn( $c ) => 'concepto' === $c ) );

		$saltadas = 0;
		foreach ( array_slice( $lineas, $cab + 1, null, true ) as $i => $l ) {
			$celdas = str_getcsv( $l, $sep, '"', '' );
			$celda  = static fn( string $campo ) => isset( $mapeo[ $campo ] ) ? trim( (string) ( $celdas[ $mapeo[ $campo ] ] ?? '' ) ) : '';
			$fecha  = self::fecha( $celda( 'fecha' ) );
			if ( null === $fecha ) {
				++$saltadas; // Líneas de totales, saldos o pie.
				continue;
			}
			if ( isset( $mapeo['importe'] ) ) {
				$importe = self::importe( $celda( 'importe' ) );
			} else {
				$cargo   = self::importe( $celda( 'cargo' ) );
				$abono   = self::importe( $celda( 'abono' ) );
				$importe = ( null === $cargo && null === $abono ) ? null : (float) $abono - abs( (float) $cargo );
			}
			if ( null === $importe || 0.0 === $importe ) {
				$res['avisos'][] = sprintf( 'Línea %d: sin importe; no se tiene en cuenta.', $i + 1 );
				continue;
			}
			// Concepto: la columna elegida más otras columnas de concepto (p. ej. «Más datos»).
			$columnas = array_unique( array_merge( isset( $mapeo['concepto'] ) ? array( $mapeo['concepto'] ) : array(), $extra_concepto ) );
			$concepto = trim( implode( ' ', array_filter( array_map( static fn( $c ) => trim( (string) ( $celdas[ $c ] ?? '' ) ), $columnas ) ) ) );

			$res['movimientos'][] = array(
				'cuenta'          => $cuenta,
				'fecha'           => $fecha,
				'fecha_valor'     => self::fecha( $celda( 'fecha_valor' ) ),
				'importe'         => round( $importe, 2 ),
				'concepto_comun'  => '',
				'concepto_propio' => '',
				'documento'       => '',
				'referencia1'     => mb_substr( $celda( 'referencia' ), 0, 20 ),
				'referencia2'     => '',
				'concepto'        => (string) preg_replace( '/\s+/u', ' ', $concepto ),
				'ordenante'       => (string) preg_replace( '/\s+/u', ' ', $celda( 'ordenante' ) ),
				'linea'           => $i + 1,
			);
		}

		if ( ! $res['movimientos'] ) {
			$res['errores'][] = 'No se ha encontrado ningún movimiento con fecha e importe válidos. Revisa las columnas elegidas.';
		} elseif ( $saltadas > count( $res['movimientos'] ) ) {
			$res['avisos'][] = sprintf( '%d líneas no tienen una fecha válida en la columna de fecha y no se han tenido en cuenta.', $saltadas );
		}

		if ( $res['movimientos'] ) {
			$fechas           = array_column( $res['movimientos'], 'fecha' );
			$res['cuentas'][] = array(
				'cuenta'        => $cuenta,
				'titular'       => '',
				'desde'         => min( $fechas ),
				'hasta'         => max( $fechas ),
				'saldo_inicial' => null,
				'saldo_final'   => null,
				'abonos'        => round( array_sum( array_filter( array_column( $res['movimientos'], 'importe' ), static fn( $v ) => $v > 0 ) ), 2 ),
				'cargos'        => round( -array_sum( array_filter( array_column( $res['movimientos'], 'importe' ), static fn( $v ) => $v < 0 ) ), 2 ),
				'movimientos'   => count( $res['movimientos'] ),
				'modalidad'     => '',
				'divisa'        => 'EUR',
			);
		}
		return $res;
	}

	// ---------------------------------------------------------------------

	/** @return array<string, int> */
	public static function mapeo_automatico( array $titulos ): array {
		$mapeo = array();
		foreach ( $titulos as $i => $t ) {
			$campo = self::campo_de( $t );
			if ( $campo && ! isset( $mapeo[ $campo ] ) ) {
				$mapeo[ $campo ] = (int) $i;
			}
		}
		// Si hay importe con signo no hacen falta cargo y abono (y al revés).
		if ( isset( $mapeo['importe'] ) ) {
			unset( $mapeo['cargo'], $mapeo['abono'] );
		}
		return $mapeo;
	}

	private static function campo_de( $titulo ): ?string {
		$t = self::normalizar( (string) $titulo );
		if ( '' === $t ) {
			return null;
		}
		foreach ( self::SINONIMOS as $campo => $nombres ) {
			if ( in_array( $t, $nombres, true ) ) {
				return $campo;
			}
		}
		return null;
	}

	/** "Fecha Operación:" → "fecha operacion"; "Importe (€)" → "importe". */
	private static function normalizar( string $s ): string {
		$s = mb_strtolower( remove_accents( trim( $s ) ) );
		$s = (string) preg_replace( '/\((?:eur|euros|€)\)|€|eur\b/u', '', $s );
		$s = (string) preg_replace( '/[^a-z0-9]+/', ' ', $s );
		return trim( (string) preg_replace( '/\bn[o]?\b/', 'n', $s ) );
	}

	/** El separador que da más líneas con 3 o más columnas. */
	private static function separador( array $lineas ): string {
		$mejor = ';';
		$max   = -1;
		foreach ( array( ';', ',', "\t" ) as $sep ) {
			$n = count( array_filter( $lineas, static fn( $l ) => count( str_getcsv( $l, $sep, '"', '' ) ) >= 3 ) );
			if ( $n > $max ) {
				$max   = $n;
				$mejor = $sep;
			}
		}
		return $mejor;
	}

	public static function fecha( string $s ): ?string {
		$s = trim( $s );
		if ( preg_match( '/^(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})/', $s, $m ) ) {
			list( , $a, $mes, $d ) = $m;
		} elseif ( preg_match( '/^(\d{1,2})[-\/.](\d{1,2})[-\/.](\d{2}|\d{4})\b/', $s, $m ) ) {
			list( , $d, $mes, $a ) = $m;
			$a = 2 === strlen( $a ) ? ( (int) $a < 80 ? 2000 + (int) $a : 1900 + (int) $a ) : (int) $a;
		} else {
			return null;
		}
		return checkdate( (int) $mes, (int) $d, (int) $a ) ? sprintf( '%04d-%02d-%02d', $a, $mes, $d ) : null;
	}

	/** "1.234,56" · "-45,00" · "1234.56" · "1,234.56" · "+30 €" → float; null si no es un importe. */
	public static function importe( string $s ): ?float {
		$s = str_replace( array( '€', 'EUR', ' ', "\xC2\xA0", '+' ), '', trim( $s ) );
		if ( '' === $s ) {
			return null;
		}
		$negativo = false;
		if ( preg_match( '/^\((.*)\)$/', $s, $m ) ) {
			$s        = $m[1];
			$negativo = true;
		}
		if ( str_starts_with( $s, '-' ) ) {
			$s        = substr( $s, 1 );
			$negativo = true;
		} elseif ( str_ends_with( $s, '-' ) ) {
			$s        = substr( $s, 0, -1 );
			$negativo = true;
		}
		$coma  = strrpos( $s, ',' );
		$punto = strrpos( $s, '.' );
		if ( false !== $coma && false !== $punto ) {
			$decimal = $coma > $punto ? ',' : '.';
		} elseif ( false !== $coma ) {
			$decimal = ',';
		} elseif ( false !== $punto ) {
			// "1.234" con grupos de 3 cifras = miles; "12.50" = decimales.
			$decimal = preg_match( '/^\d{1,3}(\.\d{3})+$/', $s ) ? '' : '.';
		} else {
			$decimal = '';
		}
		$miles = ',' === $decimal ? '.' : ',';
		$s     = str_replace( '' === $decimal ? array( '.', ',' ) : $miles, '', $s );
		if ( ',' === $decimal ) {
			$s = str_replace( ',', '.', $s );
		}
		if ( ! preg_match( '/^\d+(\.\d+)?$/', $s ) ) {
			return null;
		}
		return ( $negativo ? -1 : 1 ) * (float) $s;
	}
}
