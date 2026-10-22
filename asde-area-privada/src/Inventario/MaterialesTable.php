<?php
namespace AsdeBalears\AreaPrivada\Inventario;

use AsdeBalears\AreaPrivada\Domain\Grupo;
use AsdeBalears\AreaPrivada\Tienda\Config;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- tablas propias del plugin ({prefix}asde_*): no hay función de WordPress para ellas y los datos cambian en cada petición, no se cachean.

/**
 * Material y equipamiento del grupo ({prefix}asde_inventario): descripción, tipo,
 * cantidad, precio por unidad, año de compra, nº de serie y notas, con quién lo
 * creó y lo cambió por última vez.
 *
 * No se borra: se da de baja (con motivo) y se puede recuperar, para que las
 * listas de compra y la auditoría sigan teniendo sentido.
 *
 * v0.29: las camisas del uniforme (tipo «Camisa uniforme») llevan modelo (chico o chica)
 * y talla; la gestión de uniformes las asigna a un pedido y las descuenta de aquí.
 *
 * v0.30: lo comprado con una lista de compra se suma aquí (sumar()).
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
				modelo varchar(20) NOT NULL DEFAULT '',
				talla varchar(10) NOT NULL DEFAULT '',
				en_tienda tinyint(1) NOT NULL DEFAULT 0,
				variante_tienda varchar(80) NOT NULL DEFAULT '',
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
	 * @param array<string, string> $d descripcion, tipo, cantidad, precio, anio, num_serie, notas (y modelo y talla, las camisas).
	 * @return array|\WP_Error Los campos listos para guardar.
	 */
	public static function validar( array $d ) {
		$errores     = array();
		$descripcion = mb_substr( trim( sanitize_text_field( (string) ( $d['descripcion'] ?? '' ) ) ), 0, 255 );
		$tipo        = (int) ( $d['tipo'] ?? 0 );
		if ( $tipo && ! TiposTable::obtener( $tipo ) ) {
			$errores[] = asde_t( 'El tipo elegido ya no existe: elige otro.' );
		}
		// Camisas del uniforme (v0.29): modelo y talla de la configuración de uniformes; sin descripción, se pone sola.
		$modelo = '';
		$talla  = '';
		if ( TiposTable::es_camisa( $tipo ) ) {
			$cfg    = Config::de();
			$modelo = Config::modelo_de( (string) ( $d['modelo'] ?? '' ), $cfg );
			$talla  = '' !== $modelo ? Config::talla_de( $cfg, $modelo, (string) ( $d['talla'] ?? '' ) ) : '';
			if ( '' === $talla ) {
				$errores[] = asde_t( 'Las camisas del uniforme necesitan el modelo (chico o chica) y una talla de ese modelo.' );
			} elseif ( '' === $descripcion ) {
				$descripcion = self::descripcion_camisa( $cfg, $modelo, $talla );
			}
		}
		if ( '' === $descripcion ) {
			$errores[] = asde_t( 'Escribe la descripción del material.' );
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
			'modelo'      => $modelo,
			'talla'       => $talla,
			// v0.51 (doc 55): se vende en la tienda, y cómo se llama allí esta fila (talla, color…).
			'en_tienda'       => ! empty( $d['en_tienda'] ) ? 1 : 0,
			'variante_tienda' => mb_substr( trim( sanitize_text_field( (string) ( $d['variante_tienda'] ?? '' ) ) ), 0, 80 ),
		);
	}

	/** «Camisa femenina (chica), talla M»: la descripción de una camisa que no la tiene. Se guarda en castellano. */
	public static function descripcion_camisa( array $cfg, string $modelo, string $talla ): string {
		$nombre = (string) ( $cfg['modelos'][ $modelo ]['nombre'] ?? $modelo );
		$quien  = Config::PARA_QUIEN[ $modelo ] ?? '';
		return '' !== $quien ? sprintf( '%s (%s), talla %s', $nombre, $quien, $talla ) : sprintf( '%s, talla %s', $nombre, $talla );
	}

	/**
	 * Camisas del uniforme que hay en el inventario (v0.29), sin las dadas de baja.
	 *
	 * @return array<string, array<string, int>> modelo => talla => unidades
	 */
	public static function camisas(): array {
		$tipo = TiposTable::camisa();
		$out  = array();
		foreach ( self::todos() as $m ) {
			if ( $tipo && $tipo === $m['tipo'] && '' !== $m['modelo'] && '' !== $m['talla'] && $m['cantidad'] > 0 ) {
				$out[ $m['modelo'] ][ $m['talla'] ] = ( $out[ $m['modelo'] ][ $m['talla'] ] ?? 0 ) + $m['cantidad'];
			}
		}
		return $out;
	}

	/**
	 * Descuenta una camisa de ese modelo y talla (v0.29), del material más antiguo que tenga.
	 * Atómico: solo si sigue quedando alguna (dos personas a la vez no se llevan la misma).
	 *
	 * @return int Id del material del que sale, o 0 si no queda ninguna.
	 */
	public static function descontar_camisa( string $modelo, string $talla ): int {
		global $wpdb;
		$tipo       = TiposTable::camisa();
		$candidatos = array_filter( self::todos(), static fn( $m ) => $tipo && $tipo === $m['tipo'] && $modelo === $m['modelo'] && $talla === $m['talla'] && $m['cantidad'] > 0 );
		ksort( $candidatos );
		foreach ( $candidatos as $id => $m ) {
			$n = $wpdb->query( // phpcs:ignore WordPress.DB
				$wpdb->prepare(
					'UPDATE %i SET cantidad = cantidad - 1, modificado = %s, modificado_por = %d WHERE id = %d AND grupo = %s AND cantidad > 0 AND baja_en IS NULL',
					self::table(),
					current_time( 'mysql', true ),
					get_current_user_id(),
					$id,
					Grupo::numero()
				)
			);
			if ( $n ) {
				return (int) $id;
			}
		}
		return 0;
	}

	/**
	 * Devuelve una camisa al inventario (v0.29): al material del que salió si sigue ahí; si
	 * no, a otro del mismo modelo y talla; y si no hay ninguno, a uno nuevo.
	 *
	 * @return int Id del material al que vuelve.
	 */
	public static function devolver_camisa( int $material, string $modelo, string $talla ): int {
		global $wpdb;
		$tipo  = TiposTable::camisa();
		$igual = static fn( $m ) => $m && null === $m['baja_en'] && $tipo === $m['tipo'] && $modelo === $m['modelo'] && $talla === $m['talla'];
		$m     = self::obtener( $material );
		if ( ! $igual( $m ) ) {
			$m = current( array_filter( self::todos(), $igual ) ) ?: null;
		}
		if ( $m ) {
			$wpdb->query( // phpcs:ignore WordPress.DB
				$wpdb->prepare(
					'UPDATE %i SET cantidad = cantidad + 1, modificado = %s, modificado_por = %d WHERE id = %d AND grupo = %s',
					self::table(),
					current_time( 'mysql', true ),
					get_current_user_id(),
					$m['id'],
					Grupo::numero()
				)
			);
			return (int) $m['id'];
		}
		return self::crear(
			array(
				'descripcion' => self::descripcion_camisa( Config::de(), $modelo, $talla ),
				'tipo'        => $tipo,
				'cantidad'    => 1,
				'precio'      => null,
				'anio'        => null,
				'num_serie'   => '',
				'notas'       => '',
				'modelo'      => $modelo,
				'talla'       => $talla,
			)
		);
	}

	// ---------------------------------------------------------------------
	// Insignias del uniforme en el inventario (v0.41)
	// ---------------------------------------------------------------------

	/**
	 * Insignias de la configuración de uniformes que hay en el inventario: se reconocen porque el material se llama igual que la
	 * insignia (sin mayúsculas ni acentos). Sin las dadas de baja.
	 *
	 * @return array<string, int> id de la insignia => unidades
	 */
	public static function insignias( array $cfg ): array {
		$ids = array();
		foreach ( (array) ( $cfg['insignias'] ?? array() ) as $id => $i ) {
			$ids[ TiposTable::clave( (string) $i['nombre'] ) ] = (string) $id;
		}
		$out = array();
		foreach ( self::todos() as $m ) {
			$k = TiposTable::clave( $m['descripcion'] );
			if ( isset( $ids[ $k ] ) && $m['cantidad'] > 0 ) {
				$out[ $ids[ $k ] ] = ( $out[ $ids[ $k ] ] ?? 0 ) + $m['cantidad'];
			}
		}
		return $out;
	}

	/**
	 * Resta unidades a un material (v0.42: lo que no volvió de un evento), sin bajar de cero. Atómico.
	 *
	 * @return int Unidades que se han quitado de verdad (puede ser menos de las pedidas si no había tantas).
	 */
	public static function restar( int $id, int $unidades ): int {
		global $wpdb;
		$m = self::obtener( $id );
		if ( $unidades < 1 || ! $m || null !== $m['baja_en'] ) {
			return 0;
		}
		$quitar = min( $unidades, (int) $m['cantidad'] );
		if ( $quitar < 1 ) {
			return 0;
		}
		$hecho = $wpdb->query( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				'UPDATE %i SET cantidad = cantidad - %d, modificado = %s, modificado_por = %d WHERE id = %d AND grupo = %s AND cantidad >= %d AND baja_en IS NULL',
				self::table(),
				$quitar,
				current_time( 'mysql', true ),
				get_current_user_id(),
				$id,
				Grupo::numero(),
				$quitar
			)
		);
		return $hecho ? $quitar : 0;
	}

	/**
	 * Descuenta N insignias de ese nombre de un solo material (el más antiguo que tenga suficientes). Atómico.
	 *
	 * @return int Id del material del que salen, o 0 si no hay suficientes en ninguno.
	 */
	public static function descontar_insignia( string $nombre, int $n ): int {
		global $wpdb;
		if ( $n < 1 ) {
			return 0;
		}
		$clave      = TiposTable::clave( $nombre );
		$candidatos = array_filter( self::todos(), static fn( $m ) => TiposTable::clave( $m['descripcion'] ) === $clave && $m['cantidad'] >= $n );
		ksort( $candidatos );
		foreach ( $candidatos as $id => $m ) {
			$hecho = $wpdb->query( // phpcs:ignore WordPress.DB
				$wpdb->prepare(
					'UPDATE %i SET cantidad = cantidad - %d, modificado = %s, modificado_por = %d WHERE id = %d AND grupo = %s AND cantidad >= %d AND baja_en IS NULL',
					self::table(),
					$n,
					current_time( 'mysql', true ),
					get_current_user_id(),
					$id,
					Grupo::numero(),
					$n
				)
			);
			if ( $hecho ) {
				return (int) $id;
			}
		}
		return 0;
	}

	/**
	 * Devuelve N insignias al inventario: al material del que salieron si sigue ahí y se llama igual; si no, a otro con ese nombre; y
	 * si no hay ninguno, a uno nuevo.
	 *
	 * @return int Id del material al que vuelven.
	 */
	public static function devolver_insignia( int $material, string $nombre, int $n ): int {
		global $wpdb;
		$clave = TiposTable::clave( $nombre );
		$igual = static fn( $m ) => $m && null === $m['baja_en'] && TiposTable::clave( $m['descripcion'] ) === $clave;
		$m     = self::obtener( $material );
		if ( ! $igual( $m ) ) {
			$m = current( array_filter( self::todos(), $igual ) ) ?: null;
		}
		if ( $m ) {
			$wpdb->query( // phpcs:ignore WordPress.DB
				$wpdb->prepare(
					'UPDATE %i SET cantidad = cantidad + %d, modificado = %s, modificado_por = %d WHERE id = %d AND grupo = %s',
					self::table(),
					$n,
					current_time( 'mysql', true ),
					get_current_user_id(),
					$m['id'],
					Grupo::numero()
				)
			);
			return (int) $m['id'];
		}
		return self::crear(
			array(
				'descripcion' => mb_substr( $nombre, 0, 255 ),
				'tipo'        => 0,
				'cantidad'    => $n,
				'precio'      => null,
				'anio'        => null,
				'num_serie'   => '',
				'notas'       => '',
				'modelo'      => '',
				'talla'       => '',
			)
		);
	}

	/**
	 * Suma unidades a un material (v0.30: lo comprado con una lista, P-66, y las filas repetidas
	 * de una importación, P-67). Atómico y solo si sigue en el inventario (no dado de baja) y no
	 * pasa de la cantidad máxima.
	 */
	public static function sumar( int $id, int $unidades ): bool {
		global $wpdb;
		if ( $unidades <= 0 ) {
			return false;
		}
		return (bool) $wpdb->query( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				'UPDATE %i SET cantidad = cantidad + %d, modificado = %s, modificado_por = %d WHERE id = %d AND grupo = %s AND baja_en IS NULL AND cantidad + %d <= %d',
				self::table(),
				$unidades,
				current_time( 'mysql', true ),
				get_current_user_id(),
				$id,
				Grupo::numero(),
				$unidades,
				self::MAX_CANTIDAD
			)
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
		$f['en_tienda']       = (int) ( $f['en_tienda'] ?? 0 );
		$f['variante_tienda'] = (string) ( $f['variante_tienda'] ?? '' );
		return $f;
	}
}
