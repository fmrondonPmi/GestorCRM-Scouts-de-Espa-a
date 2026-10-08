<?php
namespace AsdeBalears\AreaPrivada\Inventario;

defined( 'ABSPATH' ) || exit;

/**
 * Importar material al inventario desde un CSV (petición del usuario, 2026-10-05, doc 32).
 *
 * En dos pasos:
 * 1. El fichero entero: que se pueda leer, que no sea demasiado grande, que tenga
 *    una cabecera con las columnas obligatorias y no más filas de la cuenta. Si
 *    falla, no se importa nada.
 * 2. Fila a fila, con las mismas reglas que el formulario (MaterialesTable::validar):
 *    las correctas se importan y las demás vuelven en el informe con su nº de fila
 *    (el de la hoja de cálculo: la cabecera es la 1) y el motivo.
 *
 * Admite lo que guarda Excel en España: separador «;» (o «,»), UTF-8 con o sin BOM
 * o Windows-1252 (ANSI). La cabecera se reconoce sin mayúsculas ni acentos y con
 * varios nombres por columna («Año de compra», «Año», «Anio»…).
 */
final class Importador {

	public const MAX_BYTES = 2 * MB_IN_BYTES;
	public const MAX_FILAS = 2000;

	/** Columna => [etiqueta del ejemplo, otros nombres que se aceptan en la cabecera]. */
	public const COLUMNAS = array(
		'descripcion' => array( 'Descripción', array( 'descripcion', 'material', 'nombre', 'articulo' ) ),
		'tipo'        => array( 'Tipo', array( 'tipo', 'tipo de material', 'categoria' ) ),
		'cantidad'    => array( 'Cantidad', array( 'cantidad', 'unidades', 'uds' ) ),
		'precio'      => array( 'Precio por unidad', array( 'precio por unidad', 'precio', 'precio unidad', 'precio unitario', 'importe' ) ),
		'anio'        => array( 'Año de compra', array( 'ano de compra', 'ano', 'anio', 'anio de compra', 'ano compra' ) ),
		'num_serie'   => array( 'Nº de serie', array( 'n de serie', 'no de serie', 'numero de serie', 'num serie', 'n serie', 'serie' ) ),
		'notas'       => array( 'Notas', array( 'notas', 'observaciones', 'comentarios' ) ),
	);

	/** Sin estas columnas en la cabecera no se puede importar. */
	public const OBLIGATORIAS = array( 'descripcion', 'cantidad' );

	/**
	 * Paso 1: lee el fichero y comprueba que es un CSV que se puede importar.
	 *
	 * @return array{filas:array<int, array<string, string>>, columnas:string[]}|\WP_Error
	 *         filas: nº de fila (la cabecera es la 1) => columna => valor.
	 */
	public static function leer( string $ruta ) {
		$tam = is_readable( $ruta ) ? (int) filesize( $ruta ) : 0;
		if ( $tam <= 0 ) {
			return new \WP_Error( 'asde_importar', asde_t( 'El fichero está vacío o no se ha podido leer.' ) );
		}
		if ( $tam > self::MAX_BYTES ) {
			/* translators: %d: tamaño máximo en MB */
			return new \WP_Error( 'asde_importar', sprintf( asde_t( 'El fichero es demasiado grande (como mucho %d MB).' ), (int) ( self::MAX_BYTES / MB_IN_BYTES ) ) );
		}
		$texto = (string) file_get_contents( $ruta ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- fichero subido (temporal del servidor), no una URL
		if ( str_contains( substr( $texto, 0, 4096 ), "\0" ) ) {
			return new \WP_Error( 'asde_importar', asde_t( 'El fichero no es un CSV (parece una hoja de Excel u otro formato). Guárdalo como «CSV (delimitado por punto y coma)» y vuelve a intentarlo.' ) );
		}
		$texto = self::a_utf8( $texto );

		$lineas = self::filas_csv( $texto );
		if ( ! $lineas ) {
			return new \WP_Error( 'asde_importar', asde_t( 'El fichero está vacío o no se ha podido leer.' ) );
		}
		$cabecera = array_shift( $lineas );
		$mapa     = self::mapa( $cabecera );
		$faltan   = array_diff( self::OBLIGATORIAS, $mapa );
		if ( $faltan ) {
			$nombres = array_map( static fn( $c ) => '«' . asde_t( self::COLUMNAS[ $c ][0] ) . '»', $faltan );
			/* translators: %s: columnas que faltan */
			return new \WP_Error( 'asde_importar', sprintf( asde_t( 'La primera fila tiene que ser la cabecera con los nombres de las columnas, y faltan: %s. Descarga el ejemplo para ver cómo tiene que ser.' ), implode( ', ', $nombres ) ) );
		}

		$filas = array();
		foreach ( $lineas as $i => $celdas ) {
			if ( ! array_filter( $celdas, static fn( $c ) => '' !== trim( (string) $c ) ) ) {
				continue; // Fila vacía (al final de lo que guarda Excel, sobre todo).
			}
			$fila = array();
			foreach ( $mapa as $pos => $col ) {
				$fila[ $col ] = trim( (string) ( $celdas[ $pos ] ?? '' ) );
			}
			$filas[ $i + 2 ] = $fila; // +1 por la cabecera y +1 porque la hoja empieza en la fila 1.
		}
		if ( ! $filas ) {
			return new \WP_Error( 'asde_importar', asde_t( 'El fichero solo tiene la cabecera: no hay ningún material que importar.' ) );
		}
		if ( count( $filas ) > self::MAX_FILAS ) {
			/* translators: %d: número máximo de filas */
			return new \WP_Error( 'asde_importar', sprintf( asde_t( 'El fichero tiene demasiadas filas (como mucho %d). Pártelo en varios.' ), self::MAX_FILAS ) );
		}
		return array(
			'filas'    => $filas,
			'columnas' => array_values( $mapa ),
		);
	}

	/**
	 * Paso 2: importa las filas correctas.
	 *
	 * @param array<int, array<string, string>> $filas       Lo que devuelve leer().
	 * @param bool                              $crear_tipos Crear los tipos que no existan (si no, esa fila es un error).
	 * @return array{importados:int, errores:array<int, array{fila:int, datos:array<string, string>, motivo:string}>, tipos_creados:string[]}
	 */
	public static function importar( array $filas, bool $crear_tipos ): array {
		$res    = array(
			'importados'    => 0,
			'errores'       => array(),
			'tipos_creados' => array(),
		);
		$series = array();
		foreach ( MaterialesTable::todos( true ) as $m ) {
			if ( '' !== $m['num_serie'] ) {
				$series[ mb_strtolower( $m['num_serie'] ) ] = 0; // 0: ya estaba en el inventario.
			}
		}
		$fallo = static function ( int $n, array $fila, string $motivo ) use ( &$res ): void {
			$res['errores'][] = array(
				'fila'   => $n,
				'datos'  => $fila,
				'motivo' => $motivo,
			);
		};

		foreach ( $filas as $n => $fila ) {
			$fila += array_fill_keys( array_keys( self::COLUMNAS ), '' );

			// Tipo: por su nombre (sin mayúsculas ni acentos); el que no existe se crea o es un error.
			$tipo   = 0;
			$nombre = trim( $fila['tipo'] );
			if ( '' !== $nombre ) {
				$tipo = TiposTable::por_nombre( $nombre );
				if ( ! $tipo && ! $crear_tipos ) {
					/* translators: %s: nombre del tipo */
					$fallo( $n, $fila, sprintf( asde_t( 'El tipo «%s» no existe.' ), $nombre ) );
					continue;
				}
			}

			$serie = mb_strtolower( trim( $fila['num_serie'] ) );
			if ( '' !== $serie && isset( $series[ $serie ] ) ) {
				$fallo(
					$n,
					$fila,
					$series[ $serie ]
						/* translators: 1: nº de serie, 2: nº de fila */
						? sprintf( asde_t( 'El nº de serie «%1$s» ya está en la fila %2$d del fichero.' ), $fila['num_serie'], $series[ $serie ] )
						/* translators: %s: nº de serie */
						: sprintf( asde_t( 'Ya hay un material con el nº de serie «%s» en el inventario.' ), $fila['num_serie'] )
				);
				continue;
			}

			// Mismas reglas que el formulario (sin el tipo, que se resuelve después para no crearlo si la fila falla).
			$datos = MaterialesTable::validar( array( 'tipo' => '0' ) + $fila );
			if ( is_wp_error( $datos ) ) {
				$fallo( $n, $fila, $datos->get_error_message() );
				continue;
			}
			if ( '' !== $nombre && ! $tipo ) {
				$tipo = TiposTable::crear( $nombre );
				if ( is_wp_error( $tipo ) ) {
					$fallo( $n, $fila, $tipo->get_error_message() );
					continue;
				}
				$res['tipos_creados'][] = TiposTable::obtener( $tipo )['nombre'] ?? $nombre;
			}
			$datos['tipo'] = $tipo;
			MaterialesTable::crear( $datos );
			if ( '' !== $serie ) {
				$series[ $serie ] = $n;
			}
			++$res['importados'];
		}
		return $res;
	}

	/** Fichero de ejemplo (con BOM y «;», como lo abre bien Excel). */
	public static function ejemplo(): string {
		$filas = array(
			array_map( static fn( $c ) => asde_t( $c[0] ), self::COLUMNAS ),
			array( asde_t( 'Tienda canadiense 4 plazas' ), asde_t( 'Tiendas' ), '3', '120,00', '2019', 'TC-0001', asde_t( 'En el local, estantería 2' ) ),
			array( asde_t( 'Hornillo de gas' ), asde_t( 'Cocina' ), '6', '35,50', '', '', '' ),
			array( asde_t( 'Cuerda de 20 m' ), '', '10', '', '', '', '' ),
		);
		return "\xEF\xBB\xBF" . self::a_csv( $filas );
	}

	/** CSV con «;» de estas filas (las celdas, a salvo de fórmulas al abrirlo en una hoja de cálculo). */
	public static function a_csv( array $filas ): string {
		$h = fopen( 'php://temp', 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- php://temp, en memoria
		foreach ( $filas as $f ) {
			fputcsv( $h, array_map( array( \AsdeBalears\AreaPrivada\Informes\Generador::class, 'celda_segura' ), array_values( $f ) ), ';', '"', '' );
		}
		rewind( $h );
		$csv = (string) stream_get_contents( $h );
		fclose( $h ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- php://temp, en memoria
		return $csv;
	}

	// ---------------------------------------------------------------------

	/** UTF-8 (sin el BOM) o, si no lo es, Windows-1252, que es lo que guarda Excel como «CSV». */
	private static function a_utf8( string $texto ): string {
		if ( str_starts_with( $texto, "\xEF\xBB\xBF" ) ) {
			$texto = substr( $texto, 3 );
		}
		return mb_check_encoding( $texto, 'UTF-8' ) ? $texto : (string) mb_convert_encoding( $texto, 'UTF-8', 'Windows-1252' );
	}

	/** @return array<int, string[]> Las filas del CSV, con el separador que más aparezca en la cabecera («;», «,» o tabulador). */
	private static function filas_csv( string $texto ): array {
		$primera = strtok( $texto, "\r\n" );
		$sep     = ';';
		$max     = -1;
		foreach ( array( ';', ',', "\t" ) as $s ) {
			$n = substr_count( (string) $primera, $s );
			if ( $n > $max ) {
				$max = $n;
				$sep = $s;
			}
		}
		$h = fopen( 'php://temp', 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- php://temp, en memoria
		fwrite( $h, $texto ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- php://temp, en memoria
		rewind( $h );
		$filas = array();
		while ( false !== ( $f = fgetcsv( $h, 0, $sep, '"', '' ) ) ) {
			$filas[] = array_map( static fn( $c ) => (string) $c, (array) $f );
		}
		fclose( $h ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- php://temp, en memoria
		return $filas;
	}

	/** @return array<int, string> posición en el CSV => columna reconocida. */
	private static function mapa( array $cabecera ): array {
		$mapa = array();
		foreach ( $cabecera as $pos => $nombre ) {
			$clave = trim( (string) preg_replace( '/[^a-z0-9]+/', ' ', mb_strtolower( remove_accents( str_replace( array( 'º', 'ª', '°' ), '', (string) $nombre ) ) ) ) );
			foreach ( self::COLUMNAS as $col => list( $etiqueta, $alias ) ) {
				$propia = trim( (string) preg_replace( '/[^a-z0-9]+/', ' ', mb_strtolower( remove_accents( str_replace( 'º', '', asde_t( $etiqueta ) ) ) ) ) );
				if ( ! in_array( $col, $mapa, true ) && ( in_array( $clave, $alias, true ) || $clave === $propia ) ) {
					$mapa[ $pos ] = $col;
					break;
				}
			}
		}
		return $mapa;
	}
}
