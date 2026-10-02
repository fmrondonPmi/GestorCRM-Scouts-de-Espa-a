<?php
namespace AsdeBalears\AreaPrivada\Tesoreria\Banco;

defined( 'ABSPATH' ) || exit;

/**
 * Lector de ficheros Norma 43 (Cuaderno 43 de AEB, CECA y UNACC, junio de 2012):
 * información normalizada de cuenta corriente.
 *
 * Registros de 80 posiciones:
 *   11  cabecera de cuenta (entidad, oficina, cuenta, periodo, saldo inicial, modalidad)
 *   22  movimiento (fechas, concepto común y propio, debe/haber, importe, documento, referencias)
 *   23  complementarios de concepto (hasta 5; en la modalidad 3, datos SEPA: 01 = ordenante)
 *   24  equivalencia de divisa (se ignora)
 *   33  final de cuenta (nº y total de cargos y abonos, saldo final)
 *   88  fin de fichero (nº de registros)
 *
 * Comprueba que cuadren los totales del 33 y el recuento del 88: si no, el
 * fichero está incompleto o dañado y no se debe importar.
 */
final class Norma43 {

	/** Conceptos comunes (posiciones 23-24 del registro 22). */
	public const CONCEPTOS = array(
		'01' => 'Talones · reintegros',
		'02' => 'Abonarés · entregas · ingresos',
		'03' => 'Domiciliados · recibos · letras',
		'04' => 'Giros · transferencias · traspasos · cheques',
		'05' => 'Amortizaciones de préstamos y créditos',
		'06' => 'Remesas de efectos',
		'07' => 'Suscripciones · dividendos pasivos · canjes',
		'08' => 'Dividendos · cupones · amortizaciones',
		'09' => 'Operaciones de bolsa y valores',
		'10' => 'Cheques de gasolina',
		'11' => 'Cajero automático',
		'12' => 'Tarjetas de crédito o débito',
		'13' => 'Operaciones con el extranjero',
		'14' => 'Devoluciones e impagados',
		'15' => 'Nóminas · seguros sociales',
		'16' => 'Timbres · corretaje · póliza',
		'17' => 'Intereses · comisiones · custodia · gastos e impuestos',
		'98' => 'Anulaciones · correcciones de asiento',
		'99' => 'Varios',
	);

	/** Parece Norma 43: la primera línea con datos es una cabecera de cuenta (11) de unas 80 posiciones. */
	public static function parece( string $contenido ): bool {
		foreach ( preg_split( '/\r\n|\r|\n/', $contenido, 20 ) as $l ) {
			if ( '' !== trim( $l ) ) {
				return str_starts_with( $l, '11' ) && strlen( rtrim( $l ) ) <= 80 && strlen( rtrim( $l ) ) >= 50 && ctype_digit( substr( $l, 2, 30 ) );
			}
		}
		return false;
	}

	/**
	 * @return array{cuentas: array[], movimientos: array[], errores: string[], avisos: string[], codificacion: string}
	 */
	public static function leer( string $contenido, string $codificacion = 'auto' ): array {
		$cod = 'auto' === $codificacion ? Codificacion::detectar( $contenido ) : $codificacion;
		if ( Codificacion::UTF8 === $cod ) {
			// Las posiciones son de bytes: se pasa a un juego de un byte por carácter.
			$contenido = (string) mb_convert_encoding( str_starts_with( $contenido, "\xEF\xBB\xBF" ) ? substr( $contenido, 3 ) : $contenido, Codificacion::WIN, 'UTF-8' );
			$cod       = Codificacion::WIN;
		}

		$res       = array(
			'cuentas'      => array(),
			'movimientos'  => array(),
			'errores'      => array(),
			'avisos'       => array(),
			'codificacion' => $cod,
		);
		$cuenta    = null;
		$mov       = null;
		$registros = 0;
		$fin       = false;

		foreach ( preg_split( '/\r\n|\r|\n/', $contenido ) as $i => $linea ) {
			$n = $i + 1;
			if ( '' === trim( $linea ) ) {
				continue;
			}
			if ( $fin ) {
				$res['errores'][] = sprintf( 'Línea %d: hay datos después del registro 88 (fin de fichero).', $n );
				break;
			}
			if ( strlen( $linea ) > 80 ) {
				if ( '' !== trim( substr( $linea, 80 ) ) ) {
					$res['errores'][] = sprintf( 'Línea %d: tiene más de 80 posiciones.', $n );
					continue;
				}
				$linea = substr( $linea, 0, 80 );
			}
			$linea = str_pad( $linea, 80 );
			$tipo  = substr( $linea, 0, 2 );

			if ( '88' === $tipo ) {
				if ( $cuenta ) {
					$res['errores'][] = sprintf( 'Línea %d: fin de fichero sin el registro 33 de la cuenta %s.', $n, $cuenta['cuenta'] );
				}
				$declarados = self::campo( $linea, 21, 26 );
				// La norma no cuenta el propio 88; algunos bancos sí lo cuentan.
				if ( ! ctype_digit( $declarados ) || ! in_array( (int) $declarados, array( $registros, $registros + 1 ), true ) ) {
					$res['errores'][] = sprintf( 'El registro 88 indica %s registros y el fichero tiene %d: está incompleto o dañado.', ltrim( $declarados, '0' ) ?: '0', $registros );
				}
				$fin = true;
				continue;
			}

			++$registros;
			switch ( $tipo ) {
				case '11':
					if ( $cuenta ) {
						$res['errores'][] = sprintf( 'Línea %d: empieza una cuenta nueva sin el registro 33 de la anterior.', $n );
					}
					$cuenta = self::cabecera( $linea, $cod );
					if ( null === $cuenta['saldo_inicial'] || null === $cuenta['desde'] || null === $cuenta['hasta'] ) {
						$res['errores'][] = sprintf( 'Línea %d: cabecera de cuenta (11) con fechas o saldo no válidos.', $n );
					}
					break;

				case '22':
					if ( ! $cuenta ) {
						$res['errores'][] = sprintf( 'Línea %d: movimiento (22) fuera de una cuenta.', $n );
						break;
					}
					if ( $mov ) {
						$res['movimientos'][] = self::cerrar( $mov, $cuenta );
					}
					$mov = self::movimiento( $linea, $n );
					if ( null === $mov['fecha'] || null === $mov['importe'] || ! in_array( $mov['_signo'], array( '1', '2' ), true ) ) {
						$res['errores'][] = sprintf( 'Línea %d: movimiento con fecha, importe o clave debe/haber no válidos.', $n );
						$mov = null;
						break;
					}
					if ( '1' === $mov['_signo'] ) {
						++$cuenta['debe_n'];
						$cuenta['debe_cent'] += $mov['_cent'];
					} else {
						++$cuenta['haber_n'];
						$cuenta['haber_cent'] += $mov['_cent'];
					}
					break;

				case '23':
					if ( ! $mov ) {
						$res['errores'][] = sprintf( 'Línea %d: complementario (23) sin movimiento.', $n );
						break;
					}
					$codigo = self::campo( $linea, 3, 4 );
					$mov['_c'][ $codigo ] = array(
						'texto'  => self::unir( self::texto( self::campo( $linea, 5, 42 ), $cod ), self::texto( self::campo( $linea, 43, 80 ), $cod ) ),
						// Modalidad 3, dato 01: nombre del ordenante en las 66 primeras posiciones.
						'nombre' => self::texto( self::campo( $linea, 5, 70 ), $cod ),
					);
					break;

				case '24':
					break; // Equivalencia de divisa: no se usa.

				case '33':
					if ( ! $cuenta ) {
						$res['errores'][] = sprintf( 'Línea %d: final de cuenta (33) sin cabecera (11).', $n );
						break;
					}
					if ( $mov ) {
						$res['movimientos'][] = self::cerrar( $mov, $cuenta );
						$mov                  = null;
					}
					$res['errores']     = array_merge( $res['errores'], self::cuadrar( $linea, $cuenta, $n ) );
					$cuenta['saldo_final'] = self::importe_con_signo( self::campo( $linea, 59, 59 ), self::campo( $linea, 60, 73 ) );
					$res['cuentas'][]   = self::resumen( $cuenta );
					$cuenta             = null;
					break;

				default:
					$res['errores'][] = sprintf( 'Línea %d: tipo de registro «%s» desconocido. ¿Es un fichero Norma 43?', $n, $tipo );
			}
			if ( count( $res['errores'] ) > 20 ) {
				$res['errores'][] = 'Demasiados errores: se deja de leer el fichero.';
				break;
			}
		}

		if ( $cuenta ) {
			$res['errores'][] = sprintf( 'El fichero termina sin el registro 33 (final) de la cuenta %s.', $cuenta['cuenta'] );
		}
		if ( ! $fin && ! $res['errores'] ) {
			$res['avisos'][] = 'Falta el registro 88 (fin de fichero); los totales de cada cuenta sí cuadran.';
		}
		if ( ! $res['cuentas'] && ! $res['errores'] ) {
			$res['errores'][] = 'No se ha encontrado ninguna cuenta (registro 11).';
		}
		return $res;
	}

	// ---------------------------------------------------------------------

	private static function cabecera( string $l, string $cod ): array {
		$entidad = self::campo( $l, 3, 6 );
		$oficina = self::campo( $l, 7, 10 );
		$numero  = self::campo( $l, 11, 20 );
		return array(
			'entidad'       => $entidad,
			'oficina'       => $oficina,
			'numero'        => $numero,
			'cuenta'        => self::mascara( $entidad, $oficina, $numero ),
			'desde'         => self::fecha( self::campo( $l, 21, 26 ) ),
			'hasta'         => self::fecha( self::campo( $l, 27, 32 ) ),
			'saldo_inicial' => self::importe_con_signo( self::campo( $l, 33, 33 ), self::campo( $l, 34, 47 ) ),
			'divisa'        => self::campo( $l, 48, 50 ),
			'modalidad'     => self::campo( $l, 51, 51 ),
			'titular'       => self::texto( self::campo( $l, 52, 77 ), $cod ),
			'debe_n'        => 0,
			'debe_cent'     => 0,
			'haber_n'       => 0,
			'haber_cent'    => 0,
			'movimientos'   => 0,
			'saldo_final'   => null,
		);
	}

	private static function movimiento( string $l, int $n ): array {
		$digitos = self::campo( $l, 29, 42 );
		$cent    = ctype_digit( $digitos ) ? (int) $digitos : null;
		return array(
			'fecha'           => self::fecha( self::campo( $l, 11, 16 ) ),
			'fecha_valor'     => self::fecha( self::campo( $l, 17, 22 ) ),
			'concepto_comun'  => self::campo( $l, 23, 24 ),
			'concepto_propio' => trim( self::campo( $l, 25, 27 ) ),
			'_signo'          => self::campo( $l, 28, 28 ),
			'_cent'           => (int) $cent,
			'importe'         => null === $cent ? null : $cent / 100,
			'documento'       => trim( self::campo( $l, 43, 52 ) ),
			'referencia1'     => trim( self::campo( $l, 53, 64 ) ),
			'referencia2'     => trim( self::campo( $l, 65, 80 ) ),
			'linea'           => $n,
			'_c'              => array(),
		);
	}

	/** Movimiento final: importe con signo y concepto (en la modalidad 3, el ordenante aparte). */
	private static function cerrar( array $mov, array &$cuenta ): array {
		++$cuenta['movimientos'];
		$c         = $mov['_c'];
		$ordenante = '';
		if ( '3' === $cuenta['modalidad'] && isset( $c['01'] ) && in_array( $mov['concepto_comun'], array( '02', '04' ), true ) ) {
			// Transferencias SEPA (Anexo 4): 23-01 = nombre del ordenante (66 posiciones) + su código.
			$ordenante = $c['01']['nombre'];
			unset( $c['01'] );
		}
		ksort( $c );
		return array(
			'cuenta'          => $cuenta['cuenta'],
			'fecha'           => $mov['fecha'],
			'fecha_valor'     => $mov['fecha_valor'],
			'importe'         => round( ( '1' === $mov['_signo'] ? -1 : 1 ) * $mov['importe'], 2 ),
			'concepto_comun'  => $mov['concepto_comun'],
			'concepto_propio' => $mov['concepto_propio'],
			'documento'       => $mov['documento'],
			'referencia1'     => $mov['referencia1'],
			'referencia2'     => $mov['referencia2'],
			'concepto'        => self::limpiar( implode( ' ', array_filter( array_column( $c, 'texto' ) ) ) ),
			'ordenante'       => $ordenante,
			'linea'           => $mov['linea'],
		);
	}

	/** @return string[] Errores si los totales del registro 33 no cuadran con los movimientos leídos. */
	private static function cuadrar( string $l, array $cuenta, int $n ): array {
		$errores = array();
		if ( self::campo( $l, 3, 20 ) !== $cuenta['entidad'] . $cuenta['oficina'] . $cuenta['numero'] ) {
			$errores[] = sprintf( 'Línea %d: el registro 33 es de otra cuenta.', $n );
		}
		$debe_n  = (int) self::campo( $l, 21, 25 );
		$debe    = (int) self::campo( $l, 26, 39 );
		$haber_n = (int) self::campo( $l, 40, 44 );
		$haber   = (int) self::campo( $l, 45, 58 );
		if ( $debe_n !== $cuenta['debe_n'] || $debe !== $cuenta['debe_cent'] ) {
			$errores[] = sprintf( 'Cuenta %s: el registro 33 indica %d cargos por %s € y se han leído %d por %s €.', $cuenta['cuenta'], $debe_n, self::euros( $debe ), $cuenta['debe_n'], self::euros( $cuenta['debe_cent'] ) );
		}
		if ( $haber_n !== $cuenta['haber_n'] || $haber !== $cuenta['haber_cent'] ) {
			$errores[] = sprintf( 'Cuenta %s: el registro 33 indica %d abonos por %s € y se han leído %d por %s €.', $cuenta['cuenta'], $haber_n, self::euros( $haber ), $cuenta['haber_n'], self::euros( $cuenta['haber_cent'] ) );
		}
		$final = self::importe_con_signo( self::campo( $l, 59, 59 ), self::campo( $l, 60, 73 ) );
		if ( null !== $final && null !== $cuenta['saldo_inicial'] ) {
			$esperado = (int) round( $cuenta['saldo_inicial'] * 100 ) + $cuenta['haber_cent'] - $cuenta['debe_cent'];
			if ( (int) round( $final * 100 ) !== $esperado ) {
				$errores[] = sprintf( 'Cuenta %s: el saldo final (%s €) no es el saldo inicial más abonos menos cargos (%s €).', $cuenta['cuenta'], self::euros( (int) round( $final * 100 ) ), self::euros( $esperado ) );
			}
		}
		return $errores;
	}

	private static function resumen( array $c ): array {
		return array(
			'cuenta'        => $c['cuenta'],
			'titular'       => $c['titular'],
			'desde'         => $c['desde'],
			'hasta'         => $c['hasta'],
			'saldo_inicial' => $c['saldo_inicial'],
			'saldo_final'   => $c['saldo_final'],
			'abonos'        => $c['haber_cent'] / 100,
			'cargos'        => $c['debe_cent'] / 100,
			'movimientos'   => $c['movimientos'],
			'modalidad'     => $c['modalidad'],
			'divisa'        => '978' === $c['divisa'] ? 'EUR' : $c['divisa'],
		);
	}

	// ---------------------------------------------------------------------

	/** Posiciones de la norma (empiezan en 1, ambas incluidas). */
	private static function campo( string $l, int $desde, int $hasta ): string {
		return substr( $l, $desde - 1, $hasta - $desde + 1 );
	}

	private static function fecha( string $aammdd ): ?string {
		if ( ! preg_match( '/^(\d{2})(\d{2})(\d{2})$/', $aammdd, $m ) ) {
			return null;
		}
		$anio = (int) $m[1] < 80 ? 2000 + (int) $m[1] : 1900 + (int) $m[1];
		return checkdate( (int) $m[2], (int) $m[3], $anio ) ? sprintf( '%04d-%02d-%02d', $anio, $m[2], $m[3] ) : null;
	}

	/** Clave 1 = deudor/debe (negativo), 2 = acreedor/haber (positivo); 2 decimales implícitos. */
	private static function importe_con_signo( string $clave, string $digitos ): ?float {
		if ( ! ctype_digit( $digitos ) || ! in_array( $clave, array( '1', '2' ), true ) ) {
			return null;
		}
		return ( '1' === $clave ? -1 : 1 ) * ( (int) $digitos ) / 100;
	}

	private static function texto( string $s, string $cod ): string {
		return self::limpiar( Codificacion::a_utf8( $s, $cod ) );
	}

	private static function limpiar( string $s ): string {
		return trim( (string) preg_replace( '/\s+/u', ' ', $s ) );
	}

	private static function unir( string $a, string $b ): string {
		return trim( $a . ( '' !== $a && '' !== $b ? ' ' : '' ) . $b );
	}

	/** Solo entidad, oficina y las 4 últimas cifras: 2056 0001 ******1234. */
	public static function mascara( string $entidad, string $oficina, string $numero ): string {
		return sprintf( '%s %s ******%s', $entidad, $oficina, substr( $numero, -4 ) );
	}

	private static function euros( int $cent ): string {
		return number_format( $cent / 100, 2, ',', '.' );
	}
}
