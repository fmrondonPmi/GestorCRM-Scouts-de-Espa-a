<?php
namespace AsdeBalears\AreaPrivada\Presupuestos;

use AsdeBalears\AreaPrivada\Domain\Grupo;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- tablas propias del plugin ({prefix}asde_*): no hay función de WordPress para ellas y los datos cambian en cada petición, no se cachean.

/**
 * Estado del presupuesto de cada sección (y del grupo) en una ronda
 * ({prefix}asde_presupuesto_estados, v0.55, doc 57 §5.3): borrador → enviado → aprobado.
 * Sin fila, es un borrador.
 */
final class EstadosTable {

	public const BORRADOR = 'borrador';
	public const ENVIADO  = 'enviado';
	public const APROBADO = 'aprobado';

	public const ETIQUETAS = array(
		self::BORRADOR => 'Borrador',
		self::ENVIADO  => 'Enviado a Tesorería',
		self::APROBADO => 'Aprobado',
	);

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'asde_presupuesto_estados';
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
				ronda varchar(9) NOT NULL,
				seccion varchar(20) NOT NULL,
				estado varchar(10) NOT NULL DEFAULT 'borrador',
				enviado_en datetime DEFAULT NULL,
				aprobado_en datetime DEFAULT NULL,
				aprobado_por bigint(20) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				UNIQUE KEY ambito (grupo,ronda,seccion)
			) $charset;"
		);
	}

	/** @return array{estado:string, enviado_en:?string, aprobado_en:?string, aprobado_por:int} */
	public static function de( string $ronda, string $seccion ): array {
		global $wpdb;
		$f = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE grupo = %s AND ronda = %s AND seccion = %s', self::table(), Grupo::numero(), $ronda, $seccion ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return array(
			'estado'       => $f ? (string) $f['estado'] : self::BORRADOR,
			'enviado_en'   => $f['enviado_en'] ?? null,
			'aprobado_en'  => $f['aprobado_en'] ?? null,
			'aprobado_por' => $f ? (int) $f['aprobado_por'] : 0,
		);
	}

	public static function poner( string $ronda, string $seccion, string $estado ): void {
		global $wpdb;
		$ahora = current_time( 'mysql', true );
		$datos = array(
			'estado'       => $estado,
			'enviado_en'   => self::BORRADOR === $estado ? null : ( self::de( $ronda, $seccion )['enviado_en'] ?? $ahora ),
			'aprobado_en'  => self::APROBADO === $estado ? $ahora : null,
			'aprobado_por' => self::APROBADO === $estado ? get_current_user_id() : 0,
		);
		if ( self::ENVIADO === $estado ) {
			$datos['enviado_en'] = $ahora;
		}
		$existe = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE grupo = %s AND ronda = %s AND seccion = %s', self::table(), Grupo::numero(), $ronda, $seccion ) ); // phpcs:ignore WordPress.DB
		if ( $existe ) {
			$wpdb->update( self::table(), $datos, array( 'id' => (int) $existe ) );
		} else {
			$wpdb->insert( self::table(), $datos + array( 'grupo' => Grupo::numero(), 'ronda' => $ronda, 'seccion' => $seccion ) );
		}
	}

	public static function vaciar( string $ronda, string $seccion ): void {
		global $wpdb;
		$wpdb->delete( self::table(), array( 'grupo' => Grupo::numero(), 'ronda' => $ronda, 'seccion' => $seccion ) );
	}
}
