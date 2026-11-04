<?php
namespace AsdeBalears\AreaPrivada\Seguimiento;

use AsdeBalears\AreaPrivada\Crm\Crypto;

defined( 'ABSPATH' ) || exit;

/**
 * Revisiones de la progresión personal (doc 65): una fila por revisión, nunca se borra nada
 * (el histórico es la propia tabla; el estado actual es la última fila de cada persona).
 *
 * La persona se guarda como huella (HMAC del nº de socio/a y el grupo), sin dato en claro;
 * el objetivo personal y las notas, cifrados.
 */
final class Revisiones {

	/** Estados de un aspecto: 0 aún no, 1 en camino, 2 lo logra. */
	public const MAX_ESTADO = 2;
	public const MAX_NOTA   = 1000;

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'asde_seguimiento';
	}

	public static function install_table(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$t = self::table();
		dbDelta(
			"CREATE TABLE $t (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				grupo varchar(3) NOT NULL,
				persona varchar(64) NOT NULL,
				seccion varchar(20) NOT NULL,
				etapa tinyint(1) unsigned NOT NULL DEFAULT 1,
				estados varchar(32) NOT NULL DEFAULT '',
				propuesta tinyint(1) unsigned NOT NULL DEFAULT 0,
				datos_enc text NULL,
				creada datetime NOT NULL,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				KEY persona (grupo, persona, seccion)
			) {$wpdb->get_charset_collate()};"
		);
	}

	public static function huella( string $grupo, string $socio ): string {
		return Crypto::hmac( $grupo . '|' . $socio );
	}

	/** @return array<string, array> huella => última revisión (ya preparada) de la sección. */
	public static function actuales( string $grupo, string $seccion ): array {
		global $wpdb;
		$filas = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT r.* FROM %i r JOIN ( SELECT MAX(id) id FROM %i WHERE grupo = %s AND seccion = %s GROUP BY persona ) u ON u.id = r.id', self::table(), self::table(), $grupo, $seccion ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$out   = array();
		foreach ( $filas as $f ) {
			$out[ $f['persona'] ] = self::preparar( $f );
		}
		return $out;
	}

	/** @return array[] Todas las revisiones de la persona en la sección, la más reciente primero. */
	public static function historial( string $grupo, string $huella, string $seccion ): array {
		global $wpdb;
		$filas = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE grupo = %s AND persona = %s AND seccion = %s ORDER BY id DESC LIMIT 50', self::table(), $grupo, $huella, $seccion ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return array_map( array( self::class, 'preparar' ), $filas );
	}

	/**
	 * Apunta una revisión nueva.
	 *
	 * @param int[]    $estados Un estado (0-2) por aspecto.
	 * @param string[] $notas   Nota por aspecto (índice = aspecto).
	 */
	public static function guardar( string $grupo, string $huella, string $seccion, int $etapa, array $estados, bool $propuesta, string $objetivo, array $notas ): bool {
		global $wpdb;
		$datos = array(
			'objetivo' => $objetivo,
			'notas'    => array_filter( array_map( 'trim', $notas ) ),
		);
		return (bool) $wpdb->insert(
			self::table(),
			array(
				'grupo'     => $grupo,
				'persona'   => $huella,
				'seccion'   => $seccion,
				'etapa'     => $etapa,
				'estados'   => implode( ',', array_map( 'intval', $estados ) ),
				'propuesta' => (int) $propuesta,
				'datos_enc' => Crypto::encrypt( (string) wp_json_encode( $datos ) ),
				'creada'    => current_time( 'mysql', true ),
				'user_id'   => get_current_user_id(),
			)
		);
	}

	/** Descifra y convierte los estados en números. */
	private static function preparar( array $f ): array {
		$datos        = json_decode( (string) Crypto::decrypt( (string) $f['datos_enc'] ), true );
		$f['datos']   = array_merge( array( 'objetivo' => '', 'notas' => array() ), is_array( $datos ) ? $datos : array() );
		$f['estados'] = '' === $f['estados'] ? array() : array_map( 'intval', explode( ',', $f['estados'] ) );
		return $f;
	}
}
