<?php
namespace AsdeBalears\AreaPrivada\Usuarios;

use AsdeBalears\AreaPrivada\Domain\Grupo;
use AsdeBalears\AreaPrivada\Frontend\Pages;
use AsdeBalears\AreaPrivada\Frontend\TemplateLoader;
use AsdeBalears\AreaPrivada\Idiomas\Idioma;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- tablas propias del plugin ({prefix}asde_*): no hay función de WordPress para ellas y los datos cambian en cada petición, no se cachean.

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

	/** Aviso a Tesorería de una lista de compra de material pendiente de su visto bueno (v0.19, doc 32). */
	public const COMPRA = 'compra';

	/** Aviso de una baja dada desde la web, a Tesorería y a la familia (v0.23.1, doc 34). */
	public const BAJA = 'baja';

	/** Aviso a quien pidió una lista de compra: Tesorería la ha aprobado o rechazado (v0.30, doc 37). */
	public const COMPRA_REVISADA = 'compra_revisada';
	public const REINTEGRO = 'reintegro';

	/** Emails de la lista de espera: confirmar, enlaces, caducidad y mensajes del grupo (v0.32, doc 39). */
	public const LISTA_ESPERA = 'lista_espera';

	/** Avisos de los eventos (v0.35, doc 43): publicación, confirmación, plaza libre, recordatorio y cancelación. */
	public const EVENTOS = 'eventos';

	/** Avisos de la tienda (v0.39, doc 48): pedido recibido, pago validado y pedido entregado. */
	public const TIENDA = 'tienda';

	/** Invitación a dar de alta un grupo (v0.50, modo Oficina). */
	public const INVITACION_GRUPO = 'invitacion_grupo';

	public const TIPOS = array(
		Invitacion::ALTA      => 'Invitación',
		Invitacion::RESET     => 'Recuperar contraseña',
		self::RECHAZO         => 'Solicitud rechazada',
		self::SEPA            => 'Domiciliación SEPA',
		self::COMPRA          => 'Lista de compra de material',
		self::COMPRA_REVISADA => 'Lista de compra revisada',
		self::REINTEGRO       => 'Reintegro',
		self::BAJA            => 'Aviso de baja',
		self::LISTA_ESPERA    => 'Lista de espera',
		self::EVENTOS         => 'Eventos',
		self::TIENDA          => 'Tienda',
		self::INVITACION_GRUPO => 'Invitación de grupo',
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
	 * Envía un email y devuelve '' si el servidor de correo lo acepta o, si no, el motivo (lo da
	 * PHPMailer por wp_mail_failed). Lo usan todos los avisos, que después lo apuntan con registrar().
	 */
	public static function enviar( string $email, string $asunto, string $html, array $headers = array( 'Content-Type: text/html; charset=UTF-8' ) ): string {
		$error   = '';
		$captura = static function ( $e ) use ( &$error ) {
			$error = is_wp_error( $e ) ? $e->get_error_message() : asde_t( 'Error desconocido' );
		};
		add_action( 'wp_mail_failed', $captura );
		$ok = wp_mail( $email, $asunto, $html, $headers );
		remove_action( 'wp_mail_failed', $captura );
		return $ok ? '' : ( $error ?: asde_t( 'El servidor de correo no ha aceptado el email.' ) );
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
		$r = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d AND grupo = %s', self::table(), $id, Grupo::numero() ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return $r ?: null;
	}

	/** @return array<int, array> user_id => último email de cada usuario (del grupo). */
	public static function ultimos( array $user_ids ): array {
		global $wpdb;
		$ids = array_values( array_filter( wp_parse_id_list( $user_ids ) ) );
		if ( ! $ids ) {
			return array();
		}
		$in  = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$sql = 'SELECT e.* FROM %i e INNER JOIN (SELECT user_id, MAX(id) AS id FROM %i'
			. " WHERE grupo = %s AND user_id IN ($in) GROUP BY user_id) u ON u.id = e.id";
		$out = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( $sql, array_merge( array( self::table(), self::table(), Grupo::numero() ), $ids ) ), ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB
			$out[ (int) $r['user_id'] ] = $r;
		}
		return $out;
	}

	/** @return array[] Todos los del grupo (más recientes primero), opcionalmente de un usuario o un estado. */
	public static function listado( int $user_id = 0, string $estado = '' ): array {
		global $wpdb;
		// Consulta fija: cada filtro opcional va como valor (0 o '' lo dejan sin efecto) (v0.26.2, Plugin Check).
		$e = isset( self::ESTADOS[ $estado ] ) ? $estado : '';
		return (array) $wpdb->get_results( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				'SELECT * FROM %i WHERE grupo = %s'
				. ' AND ( %d = 0 OR user_id = %d )'
				. " AND ( %s = '' OR estado = %s )"
				. ' ORDER BY id DESC LIMIT 2000',
				self::table(),
				Grupo::numero(),
				$user_id,
				$user_id,
				$e,
				$e
			),
			ARRAY_A
		);
	}

	/**
	 * Vuelve a componer el email tal como se envió (misma plantilla y datos), con
	 * el nombre leído ahora del CRM y el enlace personal sustituido.
	 */
	public static function vista_previa( array $fila ): string {
		$datos = json_decode( (string) $fila['datos'], true );
		$datos = is_array( $datos ) ? $datos : array();
		$idioma = Idioma::por_defecto();
		if ( self::RECHAZO === $fila['tipo'] ) {
			$s = ! empty( $datos['solicitud'] ) ? Solicitudes::obtener( (int) $datos['solicitud'] ) : null;
			$idioma = $s && Idioma::es_valido( $s['datos']['idioma'] ?? null ) ? (string) $s['datos']['idioma'] : $idioma;
		} elseif ( ! empty( $fila['user_id'] ) ) {
			$idioma = Idioma::de_usuario( (int) $fila['user_id'] );
		}
		return Idioma::en( $idioma, static fn() => self::componer_vista_previa( $fila ) );
	}

	private static function componer_vista_previa( array $fila ): string {
		$datos = json_decode( (string) $fila['datos'], true );
		$datos = is_array( $datos ) ? $datos : array();
		if ( self::RECHAZO === $fila['tipo'] ) {
			// El nombre de quien pidió se lee de la solicitud mientras se conserve (30 días); después, sin saludo.
			$s = ! empty( $datos['solicitud'] ) ? Solicitudes::obtener( (int) $datos['solicitud'] ) : null;
			return EmailRechazo::html( $s ? EmailRechazo::saludo( $s ) : '', (string) ( $datos['grupo'] ?? '' ), (string) ( $datos['contacto'] ?? '' ) );
		}
		$user  = get_userdata( (int) $fila['user_id'] );
		if ( self::EVENTOS === $fila['tipo'] ) {
			$user = ! empty( $fila['user_id'] ) ? get_userdata( (int) $fila['user_id'] ) : false;
			return \AsdeBalears\AreaPrivada\Eventos\Avisos::html( $datos, $user ? Contacto::saludo( $user ) : '' );
		}
		if ( self::TIENDA === $fila['tipo'] ) {
			return \AsdeBalears\AreaPrivada\Tienda\Avisos::html( $datos, $user ? Contacto::saludo( $user ) : '' );
		}
		if ( self::REINTEGRO === $fila['tipo'] ) {
			return \AsdeBalears\AreaPrivada\Reintegros\Avisos::html( $datos, $user ? Contacto::saludo( $user ) : '' );
		}
		if ( self::COMPRA === $fila['tipo'] ) {
			$lista = \AsdeBalears\AreaPrivada\Inventario\ComprasTable::obtener( (int) ( $datos['lista'] ?? 0 ) );
			return $lista ? \AsdeBalears\AreaPrivada\Inventario\AvisoTesoreria::html( $lista, $user ? Contacto::saludo( $user ) : '' ) : '';
		}
		if ( self::COMPRA_REVISADA === $fila['tipo'] ) {
			return \AsdeBalears\AreaPrivada\Inventario\AvisoRevision::vista_previa( $datos, $user ? Contacto::saludo( $user ) : '' );
		}
		if ( self::BAJA === $fila['tipo'] ) {
			return AvisoBaja::vista_previa( $datos, $user ? Contacto::saludo( $user ) : '' );
		}
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
