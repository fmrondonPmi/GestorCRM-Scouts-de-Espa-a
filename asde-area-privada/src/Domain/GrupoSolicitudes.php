<?php
namespace AsdeBalears\AreaPrivada\Domain;

use AsdeBalears\AreaPrivada\Crm\Crypto;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- tabla propia del plugin ({prefix}asde_*): no hay función de WordPress para ella.

/**
 * Altas de grupo recibidas por el formulario de una invitación (v0.50, doc 50 fases 5 y 6; solo en la web de la oficina).
 *
 * Lo secreto va **cifrado** (`Crypto`): el usuario y la contraseña del CRM, el DNI y el nombre de la primera persona de
 * acceso. Rechazar o aprobar borra la credencial guardada. Nada de esto sale por la interfaz.
 */
final class GrupoSolicitudes {

	public const PENDIENTE = 'pendiente';
	public const APROBADA  = 'aprobada';
	public const RECHAZADA = 'rechazada';

	public const ESTADOS = array(
		self::PENDIENTE => 'Pendiente',
		self::APROBADA  => 'Aprobada',
		self::RECHAZADA => 'Rechazada',
	);

	/** Roles del primer usuario de acceso (doc 50, P-171). */
	public const ROLES = array(
		'secretaria'   => 'Secretaría',
		'coordinacion' => 'Coordinación',
	);

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'asde_grupo_solicitudes';
	}

	public static function install_table(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$t       = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE $t (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				invitacion_id bigint(20) unsigned NOT NULL DEFAULT 0,
				numero varchar(3) NOT NULL DEFAULT '',
				nombre varchar(120) NOT NULL DEFAULT '',
				sede varchar(255) NOT NULL DEFAULT '',
				email varchar(190) NOT NULL DEFAULT '',
				cif varchar(20) NOT NULL DEFAULT '',
				denominacion varchar(190) NOT NULL DEFAULT '',
				rol varchar(20) NOT NULL DEFAULT '',
				crm_id varchar(64) NOT NULL DEFAULT '',
				usuario_enc text NULL,
				clave_enc text NULL,
				dni_enc text NULL,
				persona_enc text NULL,
				estado varchar(10) NOT NULL DEFAULT 'pendiente',
				verificada datetime NULL,
				creada datetime NOT NULL,
				resuelta datetime NULL,
				resuelta_por bigint(20) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				KEY estado (estado),
				KEY numero (numero)
			) $charset;"
		);
	}

	/**
	 * Guarda una solicitud. Los cuatro secretos se cifran aquí.
	 *
	 * @param array{numero:string, nombre:string, sede:string, email:string, cif:string, denominacion:string, rol:string, crm_id:string, usuario:string, clave:string, dni:string, persona:string} $d
	 * @return int Id de la solicitud, 0 si no se pudo guardar.
	 */
	public static function crear( int $invitacion_id, array $d ): int {
		global $wpdb;
		$ok = $wpdb->insert(
			self::table(),
			array(
				'invitacion_id' => $invitacion_id,
				'numero'        => $d['numero'],
				'nombre'        => mb_substr( $d['nombre'], 0, 120 ),
				'sede'          => mb_substr( $d['sede'], 0, 255 ),
				'email'         => mb_substr( strtolower( $d['email'] ), 0, 190 ),
				'cif'           => mb_substr( $d['cif'], 0, 20 ),
				'denominacion'  => mb_substr( $d['denominacion'], 0, 190 ),
				'rol'           => isset( self::ROLES[ $d['rol'] ] ) ? $d['rol'] : 'secretaria',
				'crm_id'        => $d['crm_id'],
				'usuario_enc'   => Crypto::encrypt( $d['usuario'] ),
				'clave_enc'     => Crypto::encrypt( $d['clave'] ),
				'dni_enc'       => Crypto::encrypt( $d['dni'] ),
				'persona_enc'   => Crypto::encrypt( $d['persona'] ),
				'estado'        => self::PENDIENTE,
				'verificada'    => current_time( 'mysql', true ),
				'creada'        => current_time( 'mysql', true ),
			)
		);
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/** ¿Hay ya una solicitud pendiente con este nº de grupo? */
	public static function pendiente_con_numero( string $numero ): bool {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE numero = %s AND estado = %s', self::table(), $numero, self::PENDIENTE ) ) > 0; // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/** Fila tal cual (con los secretos cifrados). */
	public static function obtener( int $id ): ?array {
		global $wpdb;
		$f = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', self::table(), $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		return $f ?: null;
	}

	/** @return array<int, array> Sin los secretos, para listar (más nuevas primero). */
	public static function lista( string $estado = '' ): array {
		global $wpdb;
		$filas = '' !== $estado
			? (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE estado = %s ORDER BY id DESC LIMIT 200', self::table(), $estado ), ARRAY_A ) // phpcs:ignore WordPress.DB.PreparedSQL
			: (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id DESC LIMIT %d', self::table(), 200 ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		return array_map( array( self::class, 'sin_secretos' ), $filas );
	}

	/** Quita los campos cifrados de una fila (lo único que la interfaz llega a ver). */
	public static function sin_secretos( array $f ): array {
		foreach ( array( 'usuario_enc', 'clave_enc', 'dni_enc', 'persona_enc' ) as $c ) {
			$f[ $c ] = '' !== (string) ( $f[ $c ] ?? '' ) ? '1' : '';
		}
		return $f;
	}

	public static function pendientes(): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE estado = %s', self::table(), self::PENDIENTE ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}
}
