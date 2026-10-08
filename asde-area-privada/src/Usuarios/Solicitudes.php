<?php
namespace AsdeBalears\AreaPrivada\Usuarios;

use AsdeBalears\AreaPrivada\Auth\Ambito;
use AsdeBalears\AreaPrivada\Crm\Crypto;
use AsdeBalears\AreaPrivada\Domain\Grupo;
use AsdeBalears\AreaPrivada\Domain\Persona;
use AsdeBalears\AreaPrivada\Idiomas\Idioma;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- tablas propias del plugin ({prefix}asde_*): no hay función de WordPress para ellas y los datos cambian en cada petición, no se cachean.

/**
 * Solicitudes de acceso al área privada (tabla {prefix}asde_solicitudes).
 *
 * Las hace cualquier asociado o su familia desde el enlace público «Solicitar
 * acceso» (Frontend\Views\SolicitarView) y las aprueban los scouters
 * (SolicitudService). Lo escrito por quien pide (nombre, email, documento,
 * fecha de nacimiento, nombre del menor) se guarda cifrado y se borra 30 días
 * después de resolver la solicitud; queda solo el resultado.
 */
final class Solicitudes {

	public const PENDIENTE = 'pendiente';
	public const APROBADA  = 'aprobada';
	public const RECHAZADA = 'rechazada';

	public const ESTADOS = array(
		self::PENDIENTE => 'Pendiente',
		self::APROBADA  => 'Aprobada',
		self::RECHAZADA => 'Rechazada',
	);

	/** Quién la pide: la propia persona o su familia. */
	public const SOCIO = 'socio';
	public const TUTOR = 'tutor';

	/** Cómo se encontró la ficha: por documento + nacimiento, o (menores de 14 sin documento) por nacimiento + nombre. */
	public const POR_DOCUMENTO = 'documento';
	public const POR_NOMBRE    = 'nombre';
	/** v0.16 (doc 31): alta de un scouter con el enlace para scouters, comprobada solo con el DNI. */
	public const POR_ENLACE    = 'enlace';

	/** Días que se guardan los datos escritos después de resolver la solicitud. */
	public const DIAS_DATOS = 30;

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'asde_solicitudes';
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
				estado varchar(12) NOT NULL DEFAULT 'pendiente',
				tipo varchar(10) NOT NULL,
				via varchar(12) NOT NULL DEFAULT 'documento',
				socio varchar(20) NOT NULL DEFAULT '',
				datos_enc text NULL,
				email_huella varchar(64) NOT NULL DEFAULT '',
				secciones varchar(120) NOT NULL DEFAULT '',
				adulto tinyint(1) NOT NULL DEFAULT 0,
				creada datetime NOT NULL,
				revisada_por bigint(20) unsigned NOT NULL DEFAULT 0,
				revisada_en datetime NULL,
				motivo varchar(255) NOT NULL DEFAULT '',
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				KEY grupo_estado (grupo, estado),
				KEY socio (socio)
			) $charset;"
		);
	}

	/**
	 * @param Persona $p     Ficha del CRM verificada (de ella se guardan solo su nº, sus secciones y si es adulta).
	 * @param array{nombre:string, email:string, documento:string, nacimiento:string, nombre_menor?:string} $datos Lo escrito por quien pide.
	 */
	public static function crear( string $tipo, string $via, Persona $p, array $datos ): int {
		global $wpdb;
		$datos['idioma'] ??= Idioma::actual(); // Para contestarle, y para su cuenta, en su idioma.
		$wpdb->insert(
			self::table(),
			array(
				'grupo'        => Grupo::numero(),
				'estado'       => self::PENDIENTE,
				'tipo'         => self::TUTOR === $tipo ? self::TUTOR : self::SOCIO,
				'via'          => in_array( $via, array( self::POR_NOMBRE, self::POR_ENLACE ), true ) ? $via : self::POR_DOCUMENTO,
				'socio'        => $p->num_socio_asde,
				'datos_enc'    => Crypto::encrypt( (string) wp_json_encode( $datos ) ),
				'email_huella' => EmailCuenta::huella( (string) ( $datos['email'] ?? '' ) ),
				'secciones'    => mb_substr( implode( ',', $p->secciones ), 0, 120 ),
				'adulto'       => $p->es_adulto_voluntario() ? 1 : 0,
				'creada'       => current_time( 'mysql', true ),
			)
		);
		return (int) $wpdb->insert_id;
	}

	/** Pendientes del grupo que puede revisar este ámbito (sin consultar el CRM: con las secciones guardadas en la solicitud). */
	public static function contar_pendientes( Ambito $a ): int {
		global $wpdb;
		$filas = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT secciones, adulto FROM %i WHERE grupo = %s AND estado = %s', self::table(), Grupo::numero(), self::PENDIENTE ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return count( array_filter( $filas, static fn( array $s ) => self::en_ambito( $s, $a ) ) );
	}

	/**
	 * ¿Puede revisarla? Secretaría y Coordinación (y administradores), todas.
	 * Un scouter, las de socios de sus secciones que no sean adultos: las cuentas
	 * de scouters y voluntariado las da Secretaría o Coordinación.
	 */
	public static function en_ambito( array $s, Ambito $a ): bool {
		if ( ! current_user_can( 'asde_aprobar_solicitudes' ) ) {
			return false;
		}
		if ( $a->vision_global() ) {
			return true;
		}
		if ( ! empty( $s['adulto'] ) ) {
			return false;
		}
		return (bool) array_intersect( array_filter( explode( ',', (string) $s['secciones'] ) ), $a->secciones_scouter() );
	}

	/** ¿Hay ya una solicitud pendiente de esta persona, de este tipo y con este email? */
	public static function hay_pendiente( string $socio, string $tipo, string $email ): bool {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE grupo = %s AND estado = %s AND socio = %s AND tipo = %s AND email_huella = %s LIMIT 1', self::table(), Grupo::numero(), self::PENDIENTE, $socio, $tipo, EmailCuenta::huella( $email ) ) ); // phpcs:ignore WordPress.DB
	}

	/** Solicitud del grupo actual, con los datos escritos descifrados (vacíos si ya se borraron). */
	public static function obtener( int $id ): ?array {
		global $wpdb;
		$fila = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d AND grupo = %s', self::table(), $id, Grupo::numero() ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return $fila ? self::preparar( $fila ) : null;
	}

	/** @return array[] Del grupo actual, las más recientes primero. */
	public static function listado( string $estado = self::PENDIENTE, int $limite = 200 ): array {
		global $wpdb;
		// Consulta fija: el estado va como valor («'' = ''» lo deja sin efecto) (v0.26.2, Plugin Check).
		$filas = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE grupo = %s AND ( %s = '' OR estado = %s ) ORDER BY creada DESC, id DESC LIMIT %d", self::table(), Grupo::numero(), $estado, $estado, $limite ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return array_map( array( self::class, 'preparar' ), $filas );
	}

	public static function resolver( int $id, string $estado, int $revisor, string $motivo = '', int $user_id = 0 ): bool {
		global $wpdb;
		if ( ! in_array( $estado, array( self::APROBADA, self::RECHAZADA ), true ) ) {
			return false;
		}
		return (bool) $wpdb->update(
			self::table(),
			array(
				'estado'       => $estado,
				'revisada_por' => $revisor,
				'revisada_en'  => current_time( 'mysql', true ),
				'motivo'       => mb_substr( sanitize_text_field( $motivo ), 0, 250 ),
				'user_id'      => $user_id,
			),
			array(
				'id'     => $id,
				'grupo'  => Grupo::numero(),
				'estado' => self::PENDIENTE,
			)
		);
	}

	/** Borra lo escrito de las solicitudes resueltas hace más de DIAS_DATOS días (tarea diaria). @return int */
	public static function purgar(): int {
		global $wpdb;
		$limite = gmdate( 'Y-m-d H:i:s', time() - self::DIAS_DATOS * DAY_IN_SECONDS );
		return (int) $wpdb->query( $wpdb->prepare( "UPDATE %i SET datos_enc = NULL WHERE estado <> %s AND datos_enc IS NOT NULL AND revisada_en < %s", self::table(), self::PENDIENTE, $limite ) ); // phpcs:ignore WordPress.DB
	}

	private static function preparar( array $fila ): array {
		$datos        = null !== $fila['datos_enc'] ? json_decode( (string) Crypto::decrypt( (string) $fila['datos_enc'] ), true ) : null;
		$fila['datos'] = wp_parse_args(
			is_array( $datos ) ? $datos : array(),
			array(
				'nombre'       => '',
				'email'        => '',
				'documento'    => '',
				'nacimiento'   => '',
				'nombre_menor' => '',
			)
		);
		unset( $fila['datos_enc'] );
		$fila['id']           = (int) $fila['id'];
		$fila['revisada_por'] = (int) $fila['revisada_por'];
		$fila['user_id']      = (int) $fila['user_id'];
		$fila['adulto']       = (bool) (int) $fila['adulto'];
		return $fila;
	}
}
