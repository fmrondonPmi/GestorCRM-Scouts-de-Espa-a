<?php
namespace AsdeBalears\AreaPrivada\Informes;

use AsdeBalears\AreaPrivada\Domain\Grupo;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- tablas propias del plugin ({prefix}asde_*): no hay función de WordPress para ellas y los datos cambian en cada petición, no se cachean.

/**
 * Definiciones de informes guardados ({prefix}asde_informes). Solo guarda la
 * definición (columnas y filtros), nunca resultados ni datos personales.
 */
final class InformesTable {

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'asde_informes';
	}

	public static function install_table(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE $table (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				nombre varchar(120) NOT NULL,
				descripcion varchar(255) NOT NULL DEFAULT '',
				definicion longtext NOT NULL,
				autor_id bigint(20) unsigned NOT NULL DEFAULT 0,
				compartido tinyint(1) NOT NULL DEFAULT 0,
				sistema tinyint(1) NOT NULL DEFAULT 0,
				grupo varchar(10) NOT NULL DEFAULT '',
				creado datetime NOT NULL,
				actualizado datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY autor_id (autor_id)
			) $charset;"
		);
		self::sembrar_predefinidos();
	}

	/**
	 * Informes de ejemplo (nombre, descripción, columnas y, si los tiene, filtros), en castellano:
	 * así se guardan; se traducen al mostrarlos.
	 *
	 * @return array<int, array{0: string, 1: string, 2: string[], 3?: array}>
	 */
	public static function predefinidos(): array {
		return array(
			array( 'Listado de sección', 'Nombre, edad y contacto de los socios.', array( 'num_local', 'nombre', 'apellidos', 'edad', 'seccion', 'movil', 'email' ) ),
			array( 'Contactos de las familias', 'Tutores y sus teléfonos y emails.', array( 'nombre', 'apellidos', 'seccion', 'tutor1', 'tutor1_movil', 'tutor1_email', 'tutor2', 'tutor2_movil', 'tutor2_email' ) ),
			array( 'Alergias y alimentación', 'Para campamentos y actividades con comida.', array( 'nombre', 'apellidos', 'seccion', 'alergias', 'alim_alergia_que', 'alim_intol_que', 'alim_regimen_que', 'alerg_medic_cual', 'medicacion_cual' ) ),
			array( 'Autorizaciones', 'Cesión de imagen y consentimiento de medicación.', array( 'nombre', 'apellidos', 'seccion', 'edad', 'cesion_imagen', 'consent_medic' ) ),
			// v0.25: para que cada scouter vea quién de su sección ha pagado la cuota y quién no.
			array( 'Pagos de la cuota', 'Quién ha pagado la cuota de la ronda y cuánto le falta a cada familia.', array( 'num_local', 'nombre', 'apellidos', 'seccion', 'pago_familia', 'pago_estado', 'pago_a_pagar', 'pago_pagado', 'pago_pendiente', 'pago_vencido' ), array( 'orden' => 'seccion' ) ),
		);
	}

	/**
	 * Informes de ejemplo, compartidos con todos (no se pueden borrar). Al actualizar el
	 * plugin se añaden los que falten (desde la v0.25, «Pagos de la cuota»).
	 */
	private static function sembrar_predefinidos(): void {
		global $wpdb;
		$hay = array_map( 'strval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT nombre FROM %i WHERE sistema = 1', self::table() ) ) ); // phpcs:ignore WordPress.DB
		foreach ( self::predefinidos() as $p ) {
			list( $nombre, $desc, $cols ) = $p;
			if ( in_array( $nombre, $hay, true ) ) {
				continue;
			}
			self::guardar(
				new Informe(
					0,
					$nombre,
					$desc,
					$cols,
					( $p[3] ?? array() ) + array( 'salud' => 'alergias y alimentación' === mb_strtolower( $nombre ) ),
					0,
					true,
					true
				)
			);
		}
	}

	public static function obtener( int $id ): ?Informe {
		global $wpdb;
		// Predefinidos (comunes) o del grupo en el que se trabaja.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d AND ( sistema = 1 OR grupo = %s )', self::table(), $id, Grupo::numero() ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return $row ? Informe::from_row( $row ) : null;
	}

	/** Informes visibles para un usuario: predefinidos, compartidos y los suyos. @return Informe[] */
	public static function visibles( int $user_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE sistema = 1 OR ( grupo = %s AND ( compartido = 1 OR autor_id = %d ) ) ORDER BY sistema DESC, nombre ASC', self::table(), Grupo::numero(), $user_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return array_map( array( Informe::class, 'from_row' ), (array) $rows );
	}

	public static function guardar( Informe $i ): int {
		global $wpdb;
		$ahora = current_time( 'mysql', true );
		$datos = array(
			'nombre'      => mb_substr( $i->nombre, 0, 120 ),
			'descripcion' => mb_substr( $i->descripcion, 0, 255 ),
			'definicion'  => wp_json_encode(
				array(
					'columnas' => $i->columnas,
					'filtros'  => $i->filtros,
				)
			),
			'compartido'  => $i->compartido ? 1 : 0,
			'actualizado' => $ahora,
		);
		if ( $i->id ) {
			$wpdb->update( self::table(), $datos, array( 'id' => $i->id ) );
			return $i->id;
		}
		$wpdb->insert(
			self::table(),
			$datos + array(
				'autor_id' => $i->autor_id,
				'grupo'    => $i->sistema ? '' : Grupo::numero(),
				'sistema'  => $i->sistema ? 1 : 0,
				'creado'   => $ahora,
			)
		);
		return (int) $wpdb->insert_id;
	}

	public static function borrar( int $id ): void {
		global $wpdb;
		$wpdb->delete( self::table(), array( 'id' => $id, 'sistema' => 0 ) );
	}
}
