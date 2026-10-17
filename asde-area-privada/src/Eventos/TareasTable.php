<?php
namespace AsdeBalears\AreaPrivada\Eventos;

use AsdeBalears\AreaPrivada\Domain\Grupo;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- tablas propias del plugin ({prefix}asde_*): no hay función de WordPress para ellas y los datos cambian en cada petición, no se cachean.

/**
 * Calendario de tareas de un evento ({prefix}asde_evento_tareas, doc 49, fase A1): lo que hay que hacer y
 * para cuándo (reservar el lugar, la carta a las familias, la reunión de padres…).
 *
 * Cada tarea guarda tres fechas: la **calculada** (la del evento más o menos N días), la **ajustada** (si cae en
 * fin de semana, el viernes anterior, P-185) y una **propia** opcional que gana a las otras. La que cuenta es
 * la propia, si la hay; si no, la ajustada.
 *
 * Sin datos personales: el responsable es un nº Scouts de España (el nombre sale del CRM al enseñarlo) y de
 * quien la marca como hecha solo se guarda el id de su cuenta.
 */
final class TareasTable {

	public const REFS = array( 'inicio', 'fin' );

	/** Tareas como mucho por evento. */
	public const MAX = 80;

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'asde_evento_tareas';
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
				evento_id bigint(20) unsigned NOT NULL,
				orden int(11) NOT NULL DEFAULT 0,
				titulo varchar(200) NOT NULL,
				detalle varchar(400) NOT NULL DEFAULT '',
				ref varchar(6) NOT NULL DEFAULT 'inicio',
				desfase smallint(6) NOT NULL DEFAULT 0,
				auto varchar(30) NOT NULL DEFAULT '',
				enlace varchar(30) NOT NULL DEFAULT '',
				propia tinyint(1) NOT NULL DEFAULT 0,
				responsable varchar(20) NOT NULL DEFAULT '',
				fecha_calc date NULL,
				fecha_aj date NULL,
				fecha_propia date NULL,
				hecha_por bigint(20) unsigned NOT NULL DEFAULT 0,
				hecha_en datetime NULL,
				nota varchar(255) NOT NULL DEFAULT '',
				aviso_previo_en datetime NULL,
				aviso_vence_en datetime NULL,
				creado datetime NOT NULL,
				creado_por bigint(20) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				KEY grupo_evento (grupo, evento_id)
			) $charset;"
		);
	}

	/** Fecha que cuenta: la propia, si la hay; si no, la ajustada. */
	public static function fecha( array $t ): string {
		return (string) ( $t['fecha_propia'] ?? $t['fecha_aj'] ?? $t['fecha_calc'] ?? '' );
	}

	/** @return array|null La tarea del grupo actual. */
	public static function obtener( int $id ): ?array {
		global $wpdb;
		$fila = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d AND grupo = %s', self::table(), $id, Grupo::numero() ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return $fila ? self::preparar( $fila ) : null;
	}

	/** @return array[] Las tareas de un evento, por fecha y orden. */
	public static function de_evento( int $evento_id ): array {
		global $wpdb;
		$filas = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE grupo = %s AND evento_id = %d', self::table(), Grupo::numero(), $evento_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$filas = array_map( array( self::class, 'preparar' ), $filas );
		usort(
			$filas,
			static fn( array $a, array $b ) => array( self::fecha( $a ), $a['orden'], $a['id'] ) <=> array( self::fecha( $b ), $b['orden'], $b['id'] )
		);
		return $filas;
	}

	/**
	 * Las tareas sin hacer de estos eventos (para los contadores del inicio, sin consultar el CRM).
	 *
	 * @param int[] $eventos
	 * @return array[]
	 */
	public static function sin_hacer_de( array $eventos ): array {
		global $wpdb;
		$eventos = array_values( array_unique( array_filter( array_map( 'intval', $eventos ) ) ) );
		if ( ! $eventos ) {
			return array();
		}
		$marcas = implode( ',', array_fill( 0, count( $eventos ), '%d' ) );
		$filas  = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE grupo = %s AND hecha_en IS NULL AND evento_id IN ($marcas)", array_merge( array( self::table(), Grupo::numero() ), $eventos ) ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return array_map( array( self::class, 'preparar' ), $filas );
	}

	/** @return int Cuántas tareas tiene el evento. */
	public static function contar( int $evento_id ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE grupo = %s AND evento_id = %d', self::table(), Grupo::numero(), $evento_id ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * @param array $datos titulo, detalle, ref, desfase, auto, enlace, propia, responsable, fecha_calc, fecha_aj, fecha_propia, nota
	 * @return int Id de la tarea.
	 */
	public static function crear( int $evento_id, int $orden, array $datos ): int {
		global $wpdb;
		$wpdb->insert(
			self::table(),
			array(
				'grupo'      => Grupo::numero(),
				'evento_id'  => $evento_id,
				'orden'      => $orden,
				'creado'     => current_time( 'mysql', true ),
				'creado_por' => get_current_user_id(),
			) + self::columnas( $datos )
		);
		return (int) $wpdb->insert_id;
	}

	public static function actualizar( int $id, array $datos ): bool {
		global $wpdb;
		return false !== $wpdb->update( self::table(), self::columnas( $datos ), array( 'id' => $id, 'grupo' => Grupo::numero() ) );
	}

	public static function marcar( int $id, bool $hecha ): bool {
		global $wpdb;
		return false !== $wpdb->update(
			self::table(),
			array(
				'hecha_por' => $hecha ? get_current_user_id() : 0,
				'hecha_en'  => $hecha ? current_time( 'mysql', true ) : null,
			),
			array( 'id' => $id, 'grupo' => Grupo::numero() )
		);
	}

	public static function borrar( int $id ): bool {
		global $wpdb;
		return false !== $wpdb->delete( self::table(), array( 'id' => $id, 'grupo' => Grupo::numero() ) );
	}

	public static function borrar_de_evento( int $evento_id ): void {
		global $wpdb;
		$wpdb->delete( self::table(), array( 'evento_id' => $evento_id, 'grupo' => Grupo::numero() ) );
	}

	/** Solo las columnas que están en $datos y son de la tarea. */
	private static function columnas( array $datos ): array {
		$out = array();
		foreach ( array( 'titulo', 'detalle', 'ref', 'desfase', 'auto', 'enlace', 'propia', 'responsable', 'fecha_calc', 'fecha_aj', 'fecha_propia', 'nota', 'aviso_previo_en', 'aviso_vence_en' ) as $c ) {
			if ( array_key_exists( $c, $datos ) ) {
				$out[ $c ] = $datos[ $c ];
			}
		}
		return $out;
	}

	private static function preparar( array $f ): array {
		$f['id']        = (int) $f['id'];
		$f['evento_id'] = (int) $f['evento_id'];
		$f['orden']     = (int) $f['orden'];
		$f['desfase']   = (int) $f['desfase'];
		$f['propia']    = (int) $f['propia'];
		$f['hecha_por'] = (int) $f['hecha_por'];
		return $f;
	}
}
