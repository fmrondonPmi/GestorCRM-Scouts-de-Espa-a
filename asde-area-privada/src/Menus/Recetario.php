<?php
namespace AsdeBalears\AreaPrivada\Menus;

use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Domain\Grupo;

defined( 'ABSPATH' ) || exit;

/**
 * El recetario del grupo (doc 49, B1): alérgenos de una receta, escalado, copia desde la biblioteca común y carga desde CSV.
 * Sin datos de personas: la comprobación contra los asistentes llega en la fase B3.
 */
final class Recetario {

	/** Capability: dar de alta recetas e ingredientes (scouters, Secretaría y Coordinación; el módulo Eventos la gobierna). */
	public const CAP = 'asde_menus_recetas';

	/** Filas como mucho de un CSV. */
	public const MAX_FILAS = 1000;

	public const MAX_BYTES = 1048576;

	/** Columnas del CSV de recetas (una fila por ingrediente de cada receta). */
	public const COLUMNAS = array( 'Receta', 'Tipo', 'Porciones', 'Ingrediente', 'Cantidad', 'Unidad' );

	/**
	 * Lo que lleva una receta, juntando lo de sus ingredientes.
	 *
	 * @param array[] $lineas Las de RecetasTable::lineas().
	 * @return array{contiene:string[], trazas:string[], animal:bool, sin_revisar:string[], por_alergeno:array<string, string[]>}
	 *         por_alergeno: alérgeno => nombres de los ingredientes que lo llevan; sin_revisar: ingredientes cuyos alérgenos nadie ha revisado.
	 */
	public static function alergenos( array $lineas ): array {
		$contiene = array();
		$trazas   = array();
		$animal   = false;
		$sin      = array();
		$por      = array();
		foreach ( $lineas as $l ) {
			$i = $l['ingrediente'] ?? null;
			if ( ! $i ) {
				continue;
			}
			$contiene[] = $i['alergenos'];
			$trazas[]   = $i['trazas'];
			$animal     = $animal || $i['animal'];
			if ( ! $i['revisado'] ) {
				$sin[] = $i['nombre'];
			}
			foreach ( $i['alergenos'] as $a ) {
				$por[ $a ][] = $i['nombre'];
			}
		}
		$contiene = Alergenos::unir( $contiene );
		return array(
			'contiene'     => $contiene,
			'trazas'       => array_values( array_diff( Alergenos::unir( $trazas ), $contiene ) ),
			'animal'       => $animal,
			'sin_revisar'  => array_values( array_unique( $sin ) ),
			'por_alergeno' => $por,
		);
	}

	/**
	 * Las líneas de una receta para este nº de comensales, con la cantidad ya escalada y legible.
	 *
	 * @return array[] Cada línea con 'escalada' («6,3 kg»).
	 */
	public static function escalar( array $receta, array $lineas, int $comensales ): array {
		foreach ( $lineas as &$l ) {
			$l['escalada'] = Escalado::linea( $l['cantidad'], $l['unidad'], $receta['porciones'], $comensales );
		}
		unset( $l );
		return $lineas;
	}

	/**
	 * Copia una receta (propia o de la biblioteca común) a las del grupo, con sus ingredientes tal cual.
	 *
	 * @return int|\WP_Error Id de la copia.
	 */
	public static function copiar( int $id ) {
		$r = RecetasTable::obtener( $id );
		if ( ! $r ) {
			return new \WP_Error( 'asde_menus', asde_t( 'Esa receta no existe.' ) );
		}
		$comun  = IngredientesTable::COMUN === $r['grupo'];
		$nombre = $comun ? asde_t( $r['nombre'] ) : $r['nombre']; // De la biblioteca: se copia en el idioma de quien la copia.
		$n      = 1;
		while ( self::propia_con_nombre( $nombre ) && $n < 50 ) {
			++$n;
			/* translators: 1: nombre de la receta, 2: nº de la copia */
			$nombre = sprintf( asde_t( '%1$s (copia %2$d)' ), $comun ? asde_t( $r['nombre'] ) : $r['nombre'], $n );
		}
		$nuevo = RecetasTable::guardar(
			array(
				'nombre'        => $nombre,
				'tipo'          => $r['tipo'],
				'porciones'     => $r['porciones'],
				'elaboracion'   => $r['elaboracion'],
				'observaciones' => Biblioteca::OBSERVACION === $r['observaciones'] ? asde_t( $r['observaciones'] ) : $r['observaciones'],
				'ejemplo'       => $r['ejemplo'],
				'origen_id'     => $r['id'],
			),
			array_map( static fn( $l ) => array( 'ingrediente_id' => $l['ingrediente_id'], 'cantidad' => $l['cantidad'], 'unidad' => $l['unidad'] ), RecetasTable::lineas( $id ) )
		);
		if ( ! is_wp_error( $nuevo ) ) {
			Logger::log( 'menu_receta_copiada', 'menus', (string) $nuevo, array( 'origen' => $id ) );
		}
		return $nuevo;
	}

	private static function propia_con_nombre( string $nombre ): bool {
		foreach ( RecetasTable::visibles( true ) as $r ) {
			if ( $r['grupo'] === Grupo::numero() && Conversion::clave( $r['nombre'] ) === Conversion::clave( $nombre ) ) {
				return true;
			}
		}
		return false;
	}

	// ---------------------------------------------------------------------
	// CSV
	// ---------------------------------------------------------------------

	/** CSV de ejemplo para descargar. */
	public static function ejemplo(): string {
		$filas = array(
			self::COLUMNAS,
			array( 'Macarrones con tomate', 'primero', '10', 'Macarrones', '1', 'kg' ),
			array( 'Macarrones con tomate', 'primero', '10', 'Tomate frito', '500', 'g' ),
			array( 'Macarrones con tomate', 'primero', '10', 'Queso rallado', '150', 'g' ),
		);
		$csv = '';
		foreach ( $filas as $f ) {
			$csv .= implode( ';', array_map( static fn( $c ) => '"' . str_replace( '"', '""', (string) $c ) . '"', $f ) ) . "\r\n";
		}
		return "\xEF\xBB\xBF" . $csv;
	}

	/**
	 * Carga recetas de un CSV (receta; tipo; porciones; ingrediente; cantidad; unidad). Los ingredientes que no existen se crean
	 * **sin alérgenos y sin revisar**: la receta lo avisa hasta que alguien los anote.
	 *
	 * @return array{recetas:int, ingredientes_nuevos:int, errores:array<int, array{fila:int, motivo:string}>}|\WP_Error
	 */
	public static function importar( string $ruta ) {
		$texto = (string) file_get_contents( $ruta ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- fichero temporal subido, ya comprobado
		$texto = (string) preg_replace( '/^\xEF\xBB\xBF/', '', $texto );
		if ( '' === trim( $texto ) ) {
			return new \WP_Error( 'asde_menus', asde_t( 'El fichero está vacío.' ) );
		}
		$lineas = preg_split( '/\r\n|\r|\n/', $texto );
		$sep    = substr_count( (string) $lineas[0], ';' ) >= substr_count( (string) $lineas[0], ',' ) ? ';' : ',';
		$filas  = array();
		foreach ( $lineas as $n => $l ) {
			if ( '' !== trim( $l ) ) {
				$filas[ $n + 1 ] = str_getcsv( $l, $sep, '"', '' );
			}
		}
		$cabecera = array_shift( $filas );
		if ( count( $cabecera ) < 6 || Conversion::clave( (string) $cabecera[0] ) !== Conversion::clave( self::COLUMNAS[0] ) ) {
			return new \WP_Error( 'asde_menus', asde_t( 'La primera fila tiene que ser la de las columnas: Receta; Tipo; Porciones; Ingrediente; Cantidad; Unidad.' ) );
		}
		if ( count( $filas ) > self::MAX_FILAS ) {
			return new \WP_Error( 'asde_menus', sprintf( asde_t( 'El fichero tiene demasiadas filas (máximo %d).' ), self::MAX_FILAS ) );
		}
		$errores = array();
		$grupos  = array();
		foreach ( $filas as $n => $c ) {
			$c      = array_pad( $c, 6, '' );
			$nombre = trim( (string) $c[0] );
			if ( '' === $nombre ) {
				$errores[] = array( 'fila' => $n, 'motivo' => asde_t( 'Falta el nombre de la receta.' ) );
				continue;
			}
			$clave = Conversion::clave( $nombre );
			if ( ! isset( $grupos[ $clave ] ) ) {
				$tipo            = Conversion::clave( (string) $c[1] );
				$grupos[ $clave ] = array( 'nombre' => $nombre, 'tipo' => isset( RecetasTable::TIPOS[ $tipo ] ) ? $tipo : 'otro', 'porciones' => (int) $c[2], 'lineas' => array(), 'fila' => $n );
			}
			$unidad = strtolower( trim( (string) $c[5] ) );
			$ing    = trim( (string) $c[3] );
			$cant   = Conversion::numero( (string) $c[4] );
			if ( '' === $ing || $cant <= 0 || ! Escalado::unidad_valida( $unidad ) ) {
				$errores[] = array( 'fila' => $n, 'motivo' => asde_t( 'Cada fila necesita ingrediente, una cantidad mayor que cero y una unidad válida (g, kg, ml, l, ud, cda, pizca).' ) );
				continue;
			}
			$grupos[ $clave ]['lineas'][] = array( 'ingrediente' => $ing, 'cantidad' => $cant, 'unidad' => $unidad );
		}
		$recetas = 0;
		$nuevos  = 0;
		foreach ( $grupos as $g ) {
			if ( self::propia_con_nombre( $g['nombre'] ) ) {
				$errores[] = array( 'fila' => $g['fila'], 'motivo' => sprintf( asde_t( 'Ya hay una receta «%s» en el grupo: no se ha cargado.' ), $g['nombre'] ) );
				continue;
			}
			if ( $g['porciones'] < 1 || $g['porciones'] > 500 || ! $g['lineas'] ) {
				$errores[] = array( 'fila' => $g['fila'], 'motivo' => sprintf( asde_t( 'La receta «%s» necesita porciones (1 a 500) y al menos un ingrediente válido.' ), $g['nombre'] ) );
				continue;
			}
			$lineas = array();
			foreach ( $g['lineas'] as $l ) {
				$i = IngredientesTable::por_nombre( $l['ingrediente'] );
				if ( ! $i ) {
					$id = IngredientesTable::guardar( array( 'nombre' => $l['ingrediente'], 'unidad' => $l['unidad'], 'revisado' => 0 ) );
					if ( is_wp_error( $id ) ) {
						continue;
					}
					++$nuevos;
					$i = IngredientesTable::obtener( (int) $id );
				}
				$lineas[] = array( 'ingrediente_id' => $i['id'], 'cantidad' => $l['cantidad'], 'unidad' => $l['unidad'] );
			}
			$r = RecetasTable::guardar( $g, $lineas );
			if ( is_wp_error( $r ) ) {
				$errores[] = array( 'fila' => $g['fila'], 'motivo' => $r->get_error_message() );
				continue;
			}
			++$recetas;
		}
		Logger::log( 'menu_receta_importadas', 'menus', Grupo::numero(), array( 'recetas' => $recetas, 'errores' => count( $errores ) ) );
		return array(
			'recetas'              => $recetas,
			'ingredientes_nuevos'  => $nuevos,
			'errores'              => $errores,
		);
	}
}
