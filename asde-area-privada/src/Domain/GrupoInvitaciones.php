<?php
namespace AsdeBalears\AreaPrivada\Domain;

use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Crm\Crypto;
use AsdeBalears\AreaPrivada\Frontend\Pages;
use AsdeBalears\AreaPrivada\Frontend\TemplateLoader;
use AsdeBalears\AreaPrivada\Idiomas\Idioma;
use AsdeBalears\AreaPrivada\Usuarios\Contacto;
use AsdeBalears\AreaPrivada\Usuarios\RegistroEmails;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- tabla propia del plugin ({prefix}asde_*): no hay función de WordPress para ella.

/**
 * Invitaciones para que un grupo dé de alta su acceso (v0.50, doc 50 fase 5; solo en la web de la oficina).
 *
 * - El enlace es de **un solo uso** y caduca a los 14 días (P-169). El token (192 bits) solo viaja en el enlace: en
 *   la base de datos se guarda su HMAC (con la sal del sitio), como en `Usuarios\Invitacion`.
 * - Gastarlo es atómico (`consumir()`): dos envíos a la vez no crean dos solicitudes.
 * - El estado «caducada» se calcula por la fecha; «usada» y «anulada» se guardan.
 */
final class GrupoInvitaciones {

	public const PENDIENTE = 'pendiente';
	public const USADA     = 'usada';
	public const ANULADA   = 'anulada';
	public const CADUCADA  = 'caducada';

	public const ESTADOS = array(
		self::PENDIENTE => 'Pendiente',
		self::USADA     => 'Usada',
		self::ANULADA   => 'Anulada',
		self::CADUCADA  => 'Caducada',
	);

	/** Validez del enlace (P-169). */
	public const DIAS = 14;

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'asde_grupo_invitaciones';
	}

	public static function install_table(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$t       = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE $t (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				hash char(64) NOT NULL,
				email_destino varchar(190) NOT NULL DEFAULT '',
				estado varchar(10) NOT NULL DEFAULT 'pendiente',
				creada datetime NOT NULL,
				expira datetime NOT NULL,
				creada_por bigint(20) unsigned NOT NULL DEFAULT 0,
				usada_en datetime NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY hash (hash),
				KEY estado (estado)
			) $charset;"
		);
	}

	private static function hash( string $token ): string {
		return hash_hmac( 'sha256', 'alta-grupo|' . $token, wp_salt( 'auth' ) );
	}

	/**
	 * Crea una invitación. El token en claro solo se devuelve aquí.
	 *
	 * @return array{id:int, token:string, url:string}
	 */
	public static function crear( string $email_destino = '' ): array {
		global $wpdb;
		$token = bin2hex( random_bytes( 24 ) );
		$wpdb->insert(
			self::table(),
			array(
				'hash'          => self::hash( $token ),
				'email_destino' => is_email( $email_destino ) ? mb_substr( strtolower( $email_destino ), 0, 190 ) : '',
				'estado'        => self::PENDIENTE,
				'creada'        => current_time( 'mysql', true ),
				'expira'        => gmdate( 'Y-m-d H:i:s', time() + self::DIAS * DAY_IN_SECONDS ),
				'creada_por'    => get_current_user_id(),
			)
		);
		$id = (int) $wpdb->insert_id;
		Logger::log( 'grupo_invitacion_creada', 'invitacion', (string) $id );
		return array(
			'id'    => $id,
			'token' => $token,
			'url'   => self::url( $token ),
		);
	}

	/**
	 * Envía el enlace por email. El registro de emails guarda solo el destino enmascarado (nunca el enlace).
	 *
	 * @return string '' si salió bien, o el error.
	 */
	public static function enviar_por_email( string $email, string $url ): string {
		$organizacion = Organizacion::nombre();
		list( $asunto, $html ) = Idioma::en(
			Idioma::por_defecto(),
			static fn() => array(
				sprintf( asde_t( '[%s] Invitación para dar de alta tu grupo' ), $organizacion ),
				TemplateLoader::render(
					'email/invitacion-grupo',
					array(
						'organizacion' => $organizacion,
						'url'          => $url,
						'dias'         => self::DIAS,
					)
				),
			)
		);
		$error = RegistroEmails::enviar( $email, $asunto, $html );
		RegistroEmails::registrar( null, RegistroEmails::INVITACION_GRUPO, Contacto::enmascarar( $email ), $asunto, '' === $error ? RegistroEmails::ENVIADO : RegistroEmails::ERROR, $error, array( 'dias' => self::DIAS ) );
		return $error;
	}

	/** Enlace público de una invitación. */
	public static function url( string $token ): string {
		return Pages::url( 'alta_grupo', array( 't' => $token ) );
	}

	/** Invitación vigente (pendiente y sin caducar) de un token, o null. */
	public static function vigente( string $token ): ?array {
		global $wpdb;
		if ( ! preg_match( '/^[0-9a-f]{48}$/', $token ) ) {
			return null;
		}
		$fila = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE hash = %s', self::table(), self::hash( $token ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		if ( ! $fila || self::PENDIENTE !== $fila['estado'] || strtotime( $fila['expira'] . ' UTC' ) <= time() ) {
			return null;
		}
		return $fila;
	}

	/** Gasta la invitación de forma atómica: true solo para el primero que lo consigue. */
	public static function consumir( int $id ): bool {
		global $wpdb;
		$n = $wpdb->query( $wpdb->prepare( 'UPDATE %i SET estado = %s, usada_en = %s WHERE id = %d AND estado = %s AND expira > %s', self::table(), self::USADA, current_time( 'mysql', true ), $id, self::PENDIENTE, current_time( 'mysql', true ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		return 1 === (int) $n;
	}

	/** Devuelve a pendiente una invitación que se acaba de gastar (si después falla guardar la solicitud). */
	public static function devolver( int $id ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET estado = %s, usada_en = NULL WHERE id = %d AND estado = %s', self::table(), self::PENDIENTE, $id, self::USADA ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	public static function anular( int $id ): bool {
		global $wpdb;
		$n = $wpdb->query( $wpdb->prepare( 'UPDATE %i SET estado = %s WHERE id = %d AND estado = %s', self::table(), self::ANULADA, $id, self::PENDIENTE ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		if ( 1 === (int) $n ) {
			Logger::log( 'grupo_invitacion_anulada', 'invitacion', (string) $id );
		}
		return 1 === (int) $n;
	}

	/** Estado que se enseña: una pendiente vencida es «caducada». */
	public static function estado_de( array $fila ): string {
		if ( self::PENDIENTE === $fila['estado'] && strtotime( $fila['expira'] . ' UTC' ) <= time() ) {
			return self::CADUCADA;
		}
		return (string) $fila['estado'];
	}

	/** @return array<int, array> Las últimas, más nuevas primero, con su estado ya calculado. */
	public static function lista( int $limite = 100 ): array {
		global $wpdb;
		$filas = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id DESC LIMIT %d', self::table(), $limite ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		foreach ( $filas as &$f ) {
			$f['estado_real'] = self::estado_de( $f );
		}
		return $filas;
	}

	public static function obtener( int $id ): ?array {
		global $wpdb;
		$f = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', self::table(), $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		return $f ?: null;
	}

	/**
	 * Enlace recién creado para enseñarlo **una sola vez** a quien lo creó (en la base solo está el hash).
	 * Se guarda cifrado 5 minutos y se borra al leerlo.
	 */
	public static function recordar_para_mostrar( string $url ): void {
		set_transient( 'asde_ap_ginv_' . get_current_user_id(), Crypto::encrypt( $url ), 5 * MINUTE_IN_SECONDS );
	}

	public static function tomar_para_mostrar(): string {
		$clave = 'asde_ap_ginv_' . get_current_user_id();
		$v     = get_transient( $clave );
		delete_transient( $clave );
		return is_string( $v ) ? (string) Crypto::decrypt( $v ) : '';
	}
}
