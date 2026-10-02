<?php
namespace AsdeBalears\AreaPrivada\Usuarios;

use AsdeBalears\AreaPrivada\Domain\Grupo;
use AsdeBalears\AreaPrivada\Frontend\Pages;
use AsdeBalears\AreaPrivada\Frontend\TemplateLoader;

defined( 'ABSPATH' ) || exit;

/**
 * Registro de los emails que envía el área privada ({prefix}asde_emails): cuándo,
 * a qué usuario, de qué tipo y si salió o no (con el error del servidor de correo).
 *
 * Sin datos personales en claro: el destino se guarda enmascarado (ma***@gm***.com)
 * y del contenido solo lo necesario para reconstruirlo (usuario, roles, grupo…);
 * el nombre se vuelve a leer del CRM al verlo. El enlace personal NO se guarda
 * nunca: da acceso a la cuenta.
 *
 * "Enviado" significa que el servidor de correo lo aceptó; no garantiza que no
 * acabe en la carpeta de spam del destinatario.
 */
final class RegistroEmails {

	public const ENVIADO   = 'enviado';
	public const ERROR     = 'error';
	public const SIN_EMAIL = 'sin_email';

	public const ESTADOS = array(
		self::ENVIADO   => 'Enviado',
		self::ERROR     => 'Error al enviar',
		self::SIN_EMAIL => 'Sin email en el CRM',
	);

	/** Aviso a quien pidió el acceso cuando se rechaza su solicitud (no tiene usuario). */
	public const RECHAZO = 'rechazo';

	/** Confirmación de una autorización SEPA o aviso previo de un cargo. */
	public const SEPA = 'sepa';

	public const TIPOS = array(
		Invitacion::ALTA  => 'Invitación',
		Invitacion::RESET => 'Recuperar contraseña',
		self::RECHAZO     => 'Solicitud rechazada',
		self::SEPA        => 'Domiciliación SEPA',
	);

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'asde_emails';
	}

	public static function install_table(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$t       = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE $t (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				grupo varchar(3) NOT NULL DEFAULT '',
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				login varchar(60) NOT NULL DEFAULT '',
				tipo varchar(20) NOT NULL,
				destino varchar(100) NOT NULL DEFAULT '',
				asunto varchar(200) NOT NULL DEFAULT '',
				estado varchar(12) NOT NULL,
				error varchar(255) NOT NULL DEFAULT '',
				datos text NULL,
				autor_id bigint(20) unsigned NOT NULL DEFAULT 0,
				creado datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY grupo_creado (grupo, creado),
				KEY user_id (user_id)
			) $charset;"
		);
	}

	/**
	 * @param \WP_User|null        $user  Null en los avisos a quien aún no tiene cuenta (solicitud rechazada).
	 * @param array<string, mixed> $datos Solo lo no personal: roles, grupo, días, autor_id.
	 */
	public static function registrar( ?\WP_User $user, string $tipo, string $destino, string $asunto, string $estado, string $error = '', array $datos = array() ): int {
		global $wpdb;
		$wpdb->insert(
			self::table(),
			array(
				'grupo'    => Grupo::numero(),
				'user_id'  => $user ? $user->ID : 0,
				'login'    => $user ? $user->user_login : ( isset( $datos['solicitud'] ) ? 'solicitud ' . (int) $datos['solicitud'] : '' ),
				'tipo'     => $tipo,
				'destino'  => mb_substr( $destino, 0, 100 ),
				'asunto'   => mb_substr( $asunto, 0, 200 ),
				'estado'   => $estado,
				'error'    => mb_substr( wp_strip_all_tags( $error ), 0, 255 ),
				'datos'    => wp_json_encode( $datos ),
				'autor_id' => get_current_user_id(),
				'creado'   => current_time( 'mysql', true ),
			)
		);
		return (int) $wpdb->insert_id;
	}

	/** Del grupo en el que se trabaja. */
	public static function obtener( int $id ): ?array {
		global $wpdb;
		$r = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d AND grupo = %s', $id, Grupo::numero() ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return $r ?: null;
	}

	/** @return array<int, array> user_id => último email de cada usuario (del grupo). */
	public static function ultimos( array $user_ids ): array {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'intval', $user_ids ) ) );
		if ( ! $ids ) {
			return array();
		}
		$in  = implode( ',', $ids );
		$sql = 'SELECT e.* FROM ' . self::table() . ' e INNER JOIN (SELECT user_id, MAX(id) AS id FROM ' . self::table()
			. " WHERE grupo = %s AND user_id IN ($in) GROUP BY user_id) u ON u.id = e.id";
		$out = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( $sql, Grupo::numero() ), ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB
			$out[ (int) $r['user_id'] ] = $r;
		}
		return $out;
	}

	/** @return array[] Todos los del grupo (más recientes primero), opcionalmente de un usuario o un estado. */
	public static function listado( int $user_id = 0, string $estado = '' ): array {
		global $wpdb;
		$sql  = 'SELECT * FROM ' . self::table() . ' WHERE grupo = %s';
		$args = array( Grupo::numero() );
		if ( $user_id ) {
			$sql   .= ' AND user_id = %d';
			$args[] = $user_id;
		}
		if ( isset( self::ESTADOS[ $estado ] ) ) {
			$sql   .= ' AND estado = %s';
			$args[] = $estado;
		}
		return (array) $wpdb->get_results( $wpdb->prepare( $sql . ' ORDER BY id DESC LIMIT 2000', $args ), ARRAY_A ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Vuelve a componer el email tal como se envió (misma plantilla y datos), con
	 * el nombre leído ahora del CRM y el enlace personal sustituido.
	 */
	public static function vista_previa( array $fila ): string {
		$datos = json_decode( (string) $fila['datos'], true );
		$datos = is_array( $datos ) ? $datos : array();
		if ( self::RECHAZO === $fila['tipo'] ) {
			// El nombre de quien pidió se lee de la solicitud mientras se conserve (30 días); después, sin saludo.
			$s = ! empty( $datos['solicitud'] ) ? Solicitudes::obtener( (int) $datos['solicitud'] ) : null;
			return EmailRechazo::html( $s ? EmailRechazo::saludo( $s ) : '', (string) ( $datos['grupo'] ?? '' ), (string) ( $datos['contacto'] ?? '' ) );
		}
		$user  = get_userdata( (int) $fila['user_id'] );
		if ( self::SEPA === $fila['tipo'] ) {
			return \AsdeBalears\AreaPrivada\Sepa\Avisos::html( $datos, $user ? Contacto::saludo( $user ) : '' );
		}
		$autor = ! empty( $datos['autor_id'] ) ? get_userdata( (int) $datos['autor_id'] ) : null;
		$tipo  = isset( self::TIPOS[ $fila['tipo'] ] ) ? $fila['tipo'] : Invitacion::ALTA;
		return TemplateLoader::render(
			'email/invitacion',
			array(
				'tipo'   => $tipo,
				'nombre' => $user ? Contacto::saludo( $user ) : '',
				'login'  => (string) $fila['login'],
				'roles'  => array_map( 'strval', (array) ( $datos['roles'] ?? array() ) ),
				'grupo'  => (string) ( $datos['grupo'] ?? '' ),
				'autor'  => $autor && Invitacion::ALTA === $tipo ? Contacto::saludo( $autor ) : '',
				'url'    => Pages::url(
					'activar',
					array(
						'u' => (int) $fila['user_id'],
						't' => 'ENLACE-PERSONAL-NO-GUARDADO',
					)
				),
				'dias'   => (int) ( $datos['dias'] ?? Invitacion::DIAS ),
				'bienvenida' => ! empty( $datos['bienvenida'] ),
			)
		);
	}
}
