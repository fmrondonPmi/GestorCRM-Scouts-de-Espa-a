<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- tabla propia; el cerrojo tiene que ser una sola sentencia atómica y sin caché
namespace AsdeBalears\AreaPrivada\Crm;

use AsdeBalears\AreaPrivada\Domain\Grupo;

defined( 'ABSPATH' ) || exit;

/**
 * Operaciones de alta en el CRM a medias o hechas (v0.22, doc 34, §3.5 y §3.6).
 *
 * - Cerrojo: mientras dura un alta (o mientras se ha quedado a medias) su fila tiene
 *   `cerrojo` = grupo + resumen de la clave (el documento o la persona) y la clave
 *   única impide que otra petición empiece la misma: dos clics o dos personas a la
 *   vez no crean dos fichas. Al terminar, `cerrojo` pasa a NULL.
 * - Reintentos: cada paso hecho se apunta en `datos` (solo ids del CRM, nunca datos
 *   personales); si un paso falla, la operación queda «fallida» y el reintento sigue
 *   desde ahí sin volver a crear la persona.
 * - La clave se guarda como HMAC (con la sal de WordPress): el documento no queda en
 *   la tabla (regla 1).
 */
final class Operaciones {

	public const EN_CURSO = 'en_curso';
	public const HECHA    = 'hecha';
	public const FALLIDA  = 'fallida';

	/** Una operación «en curso» sin moverse más de esto se da por abandonada (la petición murió). */
	public const ABANDONADA = 600;

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'asde_crm_operaciones';
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
				tipo varchar(20) NOT NULL,
				cerrojo varchar(80) NULL,
				estado varchar(12) NOT NULL DEFAULT 'en_curso',
				paso varchar(20) NOT NULL DEFAULT '',
				datos text NOT NULL,
				usuario bigint(20) unsigned NOT NULL DEFAULT 0,
				creado datetime NOT NULL,
				actualizado datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY cerrojo (cerrojo),
				KEY grupo (grupo, estado)
			) $charset;"
		);
	}

	/** Cerrojo de una clave en el grupo: HMAC, nunca la clave en claro. */
	public static function cerrojo( string $clave ): string {
		return Grupo::numero() . ':' . hash_hmac( 'sha256', strtoupper( $clave ), wp_salt( 'auth' ) );
	}

	/**
	 * Operación sin terminar (en curso o fallida) de esta clave, o null.
	 *
	 * @return array{id:int, tipo:string, estado:string, paso:string, datos:array, actualizado:string}|null
	 */
	public static function pendiente( string $clave ): ?array {
		global $wpdb;
		$fila = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE cerrojo = %s', self::table(), self::cerrojo( $clave ) ), ARRAY_A );
		return $fila ? self::fila( $fila ) : null;
	}

	/**
	 * Empieza una operación o sigue una que se quedó a medias (fallida o abandonada).
	 *
	 * @return array{id:int, tipo:string, estado:string, paso:string, datos:array, reanudada:bool}|\WP_Error
	 */
	public static function tomar( string $tipo, string $clave ) {
		global $wpdb;
		$cerrojo = self::cerrojo( $clave );
		$ahora   = current_time( 'mysql', true );
		$nueva   = (int) $wpdb->query(
			$wpdb->prepare(
				'INSERT IGNORE INTO %i (grupo, tipo, cerrojo, estado, paso, datos, usuario, creado, actualizado) VALUES (%s, %s, %s, %s, %s, %s, %d, %s, %s)',
				self::table(),
				Grupo::numero(),
				$tipo,
				$cerrojo,
				self::EN_CURSO,
				'',
				'{}',
				get_current_user_id(),
				$ahora,
				$ahora
			)
		);
		if ( $nueva ) {
			return array(
				'id'        => (int) $wpdb->insert_id,
				'tipo'      => $tipo,
				'estado'    => self::EN_CURSO,
				'paso'      => '',
				'datos'     => array(),
				'reanudada' => false,
			);
		}
		$antes = self::pendiente( $clave );
		if ( ! $antes ) {
			return new \WP_Error( 'asde_operacion', asde_t( 'No se ha podido empezar la operación. Vuelve a intentarlo.' ) );
		}
		if ( $antes['tipo'] !== $tipo ) {
			return new \WP_Error( 'asde_operacion_otra', asde_t( 'Hay otra operación a medias con esta persona. Termínala antes.' ) );
		}
		$abandonada = self::EN_CURSO === $antes['estado'] && time() - strtotime( $antes['actualizado'] . ' UTC' ) > self::ABANDONADA;
		if ( self::FALLIDA !== $antes['estado'] && ! $abandonada ) {
			return new \WP_Error( 'asde_operacion_en_curso', asde_t( 'Ya hay un alta en curso para esta persona. Espera a que termine.' ) );
		}
		// Se reanuda solo si nadie la ha tomado entretanto (comparar y cambiar en una sentencia).
		$tomada = (int) $wpdb->query( $wpdb->prepare( 'UPDATE %i SET estado = %s, usuario = %d, actualizado = %s WHERE id = %d AND estado = %s AND actualizado = %s', self::table(), self::EN_CURSO, get_current_user_id(), $ahora, $antes['id'], $antes['estado'], $antes['actualizado'] ) );
		if ( ! $tomada ) {
			return new \WP_Error( 'asde_operacion_en_curso', asde_t( 'Ya hay un alta en curso para esta persona. Espera a que termine.' ) );
		}
		return array(
			'id'        => $antes['id'],
			'tipo'      => $tipo,
			'estado'    => self::EN_CURSO,
			'paso'      => $antes['paso'],
			'datos'     => $antes['datos'],
			'reanudada' => true,
		);
	}

	/** Apunta un paso hecho (y los ids que ha dado el CRM). */
	public static function paso( int $id, string $paso, array $datos ): void {
		self::cambiar( $id, array( 'paso' => $paso, 'datos' => wp_json_encode( $datos ) ) );
	}

	/** Terminada: suelta el cerrojo (la fila se queda como historia, sin datos personales). */
	public static function terminar( int $id, array $datos ): void {
		self::cambiar( $id, array( 'estado' => self::HECHA, 'cerrojo' => null, 'datos' => wp_json_encode( $datos ) ) );
	}

	/** A medias: conserva el cerrojo para que el reintento siga desde el último paso hecho. */
	public static function fallar( int $id, array $datos ): void {
		self::cambiar( $id, array( 'estado' => self::FALLIDA, 'datos' => wp_json_encode( $datos ) ) );
	}

	/** Sin nada hecho en el CRM: se borra (no hay nada que reanudar). */
	public static function descartar( int $id ): void {
		global $wpdb;
		$wpdb->delete( self::table(), array( 'id' => $id ) );
	}

	/** @return array<int, array> Operaciones a medias del grupo (para enseñarlas y reintentarlas). */
	public static function fallidas(): array {
		global $wpdb;
		$filas = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE grupo = %s AND estado <> %s ORDER BY id DESC', self::table(), Grupo::numero(), self::HECHA ), ARRAY_A );
		return array_map( array( self::class, 'fila' ), $filas );
	}

	private static function cambiar( int $id, array $campos ): void {
		global $wpdb;
		$wpdb->update( self::table(), $campos + array( 'actualizado' => current_time( 'mysql', true ) ), array( 'id' => $id ) );
	}

	private static function fila( array $f ): array {
		return array(
			'id'          => (int) $f['id'],
			'tipo'        => (string) $f['tipo'],
			'estado'      => (string) $f['estado'],
			'paso'        => (string) $f['paso'],
			'datos'       => (array) ( json_decode( (string) $f['datos'], true ) ?: array() ),
			'actualizado' => (string) $f['actualizado'],
		);
	}
}
