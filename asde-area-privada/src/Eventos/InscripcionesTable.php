<?php
namespace AsdeBalears\AreaPrivada\Eventos;

use AsdeBalears\AreaPrivada\Domain\Grupo;

defined( 'ABSPATH' ) || exit;

/**
 * Personas de un evento ({prefix}asde_evento_inscripciones, doc 32): el equipo
 * dirigente (v0.19.0) y, desde la 0.19.1, los participantes inscritos.
 *
 * De cada persona solo se guarda su nº Scouts de España, sus secciones y si es adulta
 * (para saber quién la gestiona sin consultar el CRM) y su papel en el evento. Una
 * persona está una sola vez en cada evento.
 */
final class InscripcionesTable {

	/** Papeles del equipo (el de los participantes llega en la 0.19.1). */
	public const PAPELES = array(
		'director'  => 'Director/a',
		'monitor'   => 'Monitor/a',
		'practicas' => 'En prácticas',
		'apoyo'     => 'Apoyo (cocina, intendencia, transporte)',
	);

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'asde_evento_inscripciones';
	}

	public static function install_table(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$t       = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE $t (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				evento_id bigint(20) unsigned NOT NULL,
				grupo varchar(3) NOT NULL,
				socio varchar(20) NOT NULL,
				secciones varchar(120) NOT NULL DEFAULT '',
				adulto tinyint(1) NOT NULL DEFAULT 0,
				papel varchar(20) NOT NULL,
				creado datetime NOT NULL,
				creado_por bigint(20) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				UNIQUE KEY evento_socio (evento_id,socio),
				KEY grupo (grupo)
			) $charset;"
		);
	}

	/** @return int Id de la fila (0 si ya estaba o no se ha podido guardar). */
	public static function anadir( int $evento_id, string $socio, array $secciones, bool $adulto, string $papel ): int {
		global $wpdb;
		if ( self::de_socio( $evento_id, $socio ) ) {
			return 0;
		}
		$wpdb->insert(
			self::table(),
			array(
				'evento_id'  => $evento_id,
				'grupo'      => Grupo::numero(),
				'socio'      => $socio,
				'secciones'  => mb_substr( implode( ',', $secciones ), 0, 120 ),
				'adulto'     => $adulto ? 1 : 0,
				'papel'      => $papel,
				'creado'     => current_time( 'mysql', true ),
				'creado_por' => get_current_user_id(),
			)
		);
		return (int) $wpdb->insert_id;
	}

	public static function cambiar_papel( int $id, string $papel ): bool {
		global $wpdb;
		return false !== $wpdb->update(
			self::table(),
			array( 'papel' => $papel ),
			array(
				'id'    => $id,
				'grupo' => Grupo::numero(),
			)
		);
	}

	/** Quita a alguien del equipo (no es un justificante de nada: se borra la fila). */
	public static function quitar( int $id ): bool {
		global $wpdb;
		return (bool) $wpdb->delete(
			self::table(),
			array(
				'id'    => $id,
				'grupo' => Grupo::numero(),
			)
		);
	}

	public static function obtener( int $id ): ?array {
		global $wpdb;
		$fila = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d AND grupo = %s', $id, Grupo::numero() ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return $fila ? self::preparar( $fila ) : null;
	}

	public static function de_socio( int $evento_id, string $socio ): ?array {
		global $wpdb;
		$fila = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE evento_id = %d AND grupo = %s AND socio = %s', $evento_id, Grupo::numero(), $socio ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return $fila ? self::preparar( $fila ) : null;
	}

	/** @return array[] Equipo del evento: director/a primero, después monitores, prácticas y apoyo. */
	public static function equipo( int $evento_id ): array {
		global $wpdb;
		$papeles = array_keys( self::PAPELES );
		$marcas  = implode( ',', array_fill( 0, count( $papeles ), '%s' ) );
		$filas   = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . " WHERE evento_id = %d AND grupo = %s AND papel IN ($marcas) ORDER BY id ASC", array_merge( array( $evento_id, Grupo::numero() ), $papeles ) ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$filas   = array_map( array( self::class, 'preparar' ), $filas );
		usort( $filas, static fn( $a, $b ) => array_search( $a['papel'], $papeles, true ) <=> array_search( $b['papel'], $papeles, true ) ?: $a['id'] <=> $b['id'] );
		return $filas;
	}

	/** @return array<int, string[]> evento => nº Scouts de España de su equipo (para saber qué eventos gestiona alguien). */
	public static function equipos_del_grupo(): array {
		global $wpdb;
		$papeles = array_keys( self::PAPELES );
		$marcas  = implode( ',', array_fill( 0, count( $papeles ), '%s' ) );
		$filas   = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT evento_id, socio FROM ' . self::table() . " WHERE grupo = %s AND papel IN ($marcas)", array_merge( array( Grupo::numero() ), $papeles ) ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$out     = array();
		foreach ( $filas as $f ) {
			$out[ (int) $f['evento_id'] ][] = (string) $f['socio'];
		}
		return $out;
	}

	/** ¿Tiene el evento personas inscritas (participantes)? Mientras no, el precio se puede volver a validar. */
	public static function hay_participantes( int $evento_id ): bool {
		global $wpdb;
		$papeles = array_keys( self::PAPELES );
		$marcas  = implode( ',', array_fill( 0, count( $papeles ), '%s' ) );
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::table() . " WHERE evento_id = %d AND grupo = %s AND papel NOT IN ($marcas)", array_merge( array( $evento_id, Grupo::numero() ), $papeles ) ) ); // phpcs:ignore WordPress.DB
	}

	/** Borra todas las filas de un evento (al borrar un borrador). */
	public static function borrar_evento( int $evento_id ): void {
		global $wpdb;
		$wpdb->delete(
			self::table(),
			array(
				'evento_id' => $evento_id,
				'grupo'     => Grupo::numero(),
			)
		);
	}

	private static function preparar( array $f ): array {
		$f['id']         = (int) $f['id'];
		$f['evento_id']  = (int) $f['evento_id'];
		$f['adulto']     = (bool) (int) $f['adulto'];
		$f['secciones']  = array_values( array_filter( explode( ',', (string) $f['secciones'] ) ) );
		$f['creado_por'] = (int) $f['creado_por'];
		return $f;
	}
}
