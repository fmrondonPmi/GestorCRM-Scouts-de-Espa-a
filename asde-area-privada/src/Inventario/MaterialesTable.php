<?php
namespace AsdeBalears\AreaPrivada\Inventario;

use AsdeBalears\AreaPrivada\Domain\Grupo;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- tablas propias del plugin ({prefix}asde_*): no hay función de WordPress para ellas y los datos cambian en cada petición, no se cachean.

/**
 * Material y equipamiento del grupo ({prefix}asde_inventario): descripción, tipo,
 * cantidad, precio por unidad, año de compra, nº de serie y notas, con quién lo
 * creó y lo cambió por última vez.
 *
 * No se borra: se da de baja (con motivo) y se puede recuperar, para que las
 * listas de compra y la auditoría sigan teniendo sentido.
 */
final class MaterialesTable {

	public const MAX_CANTIDAD = 99999;
	public const MAX_PRECIO   = 999999.99;
	public const ANIO_MINIMO  = 1950;

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'asde_inventario';
	}

	public static function install_table(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$t       = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE $t (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				grupo varchar(3) NOT NULL,
				descripcion varchar(255) NOT NULL,
				tipo bigint(20) unsigned NOT NULL DEFAULT 0,
				cantidad int(11) NOT NULL DEFAULT 0,
				precio decimal(10,2) NULL,
				anio smallint(5) unsigned NULL,
				num_serie varchar(120) NOT NULL DEFAULT '',
				notas varchar(500) NOT NULL DEFAULT '',
				creado datetime NOT NULL,
				creado_por bigint(20) unsigned NOT NULL DEFAULT 0,
				modificado datetime NULL,
				modificado_por bigint(20) unsigned NOT NULL DEFAULT 0,
				baja_en datetime NULL,
				baja_por bigint(20) unsigned NOT NULL DEFAULT 0,
				baja_motivo varchar(255) NOT NULL DEFAULT '',
				PRIMARY KEY  (id),
				KEY grupo (grupo),
				KEY tipo (tipo)
			) $charset;"
		);
	}

	/**
	 * Valida y limpia lo que llega del formulario.
	 *
	 * @param array<string, string> $d descripcion, tipo, cantidad, precio, anio, num_serie, notas.
	 * @return array|\WP_Error Los campos listos para guardar.
	 */
	public static function validar( array $d ) {
		$errores     = array();
		$descripcion = mb_substr( trim( sanitize_text_field( (string) ( $d['descripcion'] ?? '' ) ) ), 0, 255 );
		if ( '' === $descripcion ) {
			$errores[] = asde_t( 'Escribe la descripción del material.' );
		}
		$tipo = (int) ( $d['tipo'] ?? 0 );
		if ( $tipo && ! TiposTable::obtener( $tipo ) ) {
			$errores[] = asde_t( 'El tipo elegido ya no existe: elige otro.' );
		}
		$cantidad = trim( (string) ( $d['cantidad'] ?? '' ) );
		if ( ! preg_match( '/^\d{1,5}$/', $cantidad ) ) {
			/* translators: %d: cantidad máxima */
			$errores[] = sprintf( asde_t( 'La cantidad tiene que ser un número entero de 0 a %d.' ), self::MAX_CANTIDAD );
		}
		$precio = self::euros( (string) ( $d['precio'] ?? '' ) );
		if ( false === $precio ) {
			$errores[] = asde_t( 'El precio no es válido (por ejemplo: 12,50).' );
		}
		$anio = trim( (string) ( $d['anio'] ?? '' ) );
		$hoy  = (int) wp_date( 'Y' );
		if ( '' !== $anio && ( ! preg_match( '/^\d{4}$/', $anio ) || (int) $anio < self::ANIO_MINIMO || (int) $anio > $hoy ) ) {
			/* translators: 1: primer año admitido, 2: año actual */
			$errores[] = sprintf( asde_t( 'El año de compra tiene que estar entre %1$d y %2$d (o dejarlo en blanco).' ), self::ANIO_MINIMO, $hoy );
		}
		if ( $errores ) {
			return new \WP_Error( 'asde_inventario', implode( ' ', $errores ) );
		}
		return array(
			'descripcion' => $descripcion,
			'tipo'        => $tipo,
			'cantidad'    => (int) $cantidad,
			'precio'      => $precio,
			'anio'        => '' === $anio ? null : (int) $anio,
			'num_serie'   => mb_substr( trim( sanitize_text_field( (string) ( $d['num_serie'] ?? '' ) ) ), 0, 120 ),
			'notas'       => mb_substr( trim( sanitize_textarea_field( (string) ( $d['notas'] ?? '' ) ) ), 0, 500 ),
		);
	}

	/** «12,50», «12.5», «1.234,50» → 12.5; '' → null; algo que no es un importe → false. @return float|null|false */
	public static function euros( string $v ) {
		$v = str_replace( array( ' ', '€' ), '', trim( $v ) );
		if ( '' === $v ) {
			return null;
		}
		if ( str_contains( $v, ',' ) ) {
			$v = str_replace( array( '.', ',' ), array( '', '.' ), $v ); // Formato español: el punto separa miles.
		}
		if ( ! preg_match( '/^\d+(\.\d{1,2})?$/', $v ) || (float) $v > self::MAX_PRECIO ) {
			return false;
		}
		return round( (float) $v, 2 );
	}

	/** @param array $datos Lo que devuelve validar(). @return int Id del material. */
	public static function crear( array $datos ): int {
		global $wpdb;
		$wpdb->insert(
			self::table(),
			$datos + array(
				'grupo'      => Grupo::numero(),
				'creado'     => current_time( 'mysql', true ),
				'creado_por' => get_current_user_id(),
			)
		);
		return (int) $wpdb->insert_id;
	}

	/** @param array $datos Lo que devuelve validar(). */
	public static function actualizar( int $id, array $datos ): bool {
		return self::cambiar(
			$id,
			$datos + array(
				'modificado'     => current_time( 'mysql', true ),
				'modificado_por' => get_current_user_id(),
			)
		);
	}

	public static function dar_baja( int $id, string $motivo ): bool {
		return self::cambiar(
			$id,
			array(
				'baja_en'     => current_time( 'mysql', true ),
				'baja_por'    => get_current_user_id(),
				'baja_motivo' => mb_substr( sanitize_text_field( $motivo ), 0, 255 ),
			)
		);
	}

	public static function recuperar( int $id ): bool {
		return self::cambiar(
			$id,
			array(
				'baja_en'     => null,
				'baja_por'    => 0,
				'baja_motivo' => '',
			)
		);
	}

	/** Material del grupo actual, o null. */
	public static function obtener( int $id ): ?array {
		global $wpdb;
		$fila = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d AND grupo = %s', self::table(), $id, Grupo::numero() ), ARRAY_A );
		return $fila ? self::preparar( $fila ) : null;
	}

	/** @return array<int, array> id => material del grupo actual (los dados de baja, solo si se piden), por descripción. */
	public static function todos( bool $con_bajas = false ): array {
		global $wpdb;
		// Consulta fija: «con bajas» va como valor (v0.26.2, Plugin Check).
		$filas = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE grupo = %s AND ( %d = 1 OR baja_en IS NULL ) ORDER BY descripcion ASC, id ASC', self::table(), Grupo::numero(), (int) $con_bajas ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$out   = array();
		foreach ( $filas as $f ) {
			$m               = self::preparar( $f );
			$out[ $m['id'] ] = $m;
		}
		return $out;
	}

	/** @return array<int, int> id de tipo => cuántos materiales (también los de baja) lo usan. */
	public static function contar_por_tipo(): array {
		global $wpdb;
		$filas = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT tipo, COUNT(*) AS n FROM %i WHERE grupo = %s GROUP BY tipo', self::table(), Grupo::numero() ), ARRAY_A );
		$out   = array();
		foreach ( $filas as $f ) {
			$out[ (int) $f['tipo'] ] = (int) $f['n'];
		}
		return $out;
	}

	private static function cambiar( int $id, array $datos ): bool {
		global $wpdb;
		return false !== $wpdb->update(
			self::table(),
			$datos,
			array(
				'id'    => $id,
				'grupo' => Grupo::numero(),
			)
		);
	}

	private static function preparar( array $f ): array {
		foreach ( array( 'id', 'tipo', 'cantidad', 'creado_por', 'modificado_por', 'baja_por' ) as $k ) {
			$f[ $k ] = (int) $f[ $k ];
		}
		$f['precio'] = null === $f['precio'] ? null : (float) $f['precio'];
		$f['anio']   = null === $f['anio'] ? null : (int) $f['anio'];
		return $f;
	}
}
