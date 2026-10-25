<?php
namespace AsdeBalears\AreaPrivada\Domain;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- GET_LOCK/RELEASE_LOCK de MySQL: no hay función de WordPress para ellos y no se pueden cachear.

/**
 * Bloqueo con nombre de MySQL (GET_LOCK) para numerar sin repetir (nº de socio/a, de familia, de remesa).
 * Si no se consigue (otra petición lo tiene más de `$espera` segundos), NO se sigue como si se tuviera:
 * quien llama debe parar, y solo se libera lo que se ha tomado.
 */
final class Bloqueo {

	/** @return bool true si se ha conseguido. */
	public static function tomar( string $nombre, int $espera = 10 ): bool {
		global $wpdb;
		return 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $nombre, $espera ) );
	}

	public static function soltar( string $nombre ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $nombre ) );
	}
}
