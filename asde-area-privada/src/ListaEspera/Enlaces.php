<?php
namespace AsdeBalears\AreaPrivada\ListaEspera;

use AsdeBalears\AreaPrivada\Frontend\Pages;

defined( 'ABSPATH' ) || exit;

/**
 * Enlaces de la lista de espera (v0.32, doc 39).
 *
 * - El del grupo, para apuntarse: una clave aleatoria por grupo que Secretaría o Coordinación
 *   pueden renovar (el anterior deja de valer), como el de alta de scouters (doc 31).
 * - Los personales de cada inscripción (confirmar el email y ver sus datos): id + un token
 *   HMAC con la sal de la fila y las claves del sitio. El token no se guarda en ningún sitio;
 *   cambiar la sal (o borrar la fila) los anula.
 */
final class Enlaces {

	public const OPCION = 'asde_ap_enlace_espera';

	public const CONFIRMAR = 'confirmar';
	public const ACCESO    = 'acceso';

	/** @return array<string, string> id del grupo => clave */
	private static function claves(): array {
		$c = get_option( self::OPCION, array() );
		return is_array( $c ) ? $c : array();
	}

	/** Clave actual del grupo (se crea la primera vez). */
	public static function clave( array $grupo ): string {
		$claves = self::claves();
		$id     = (string) $grupo['id'];
		if ( empty( $claves[ $id ] ) ) {
			$claves[ $id ] = wp_generate_password( 24, false, false );
			update_option( self::OPCION, $claves, false );
		}
		return $claves[ $id ];
	}

	/** Genera una clave nueva: el enlace anterior deja de valer. */
	public static function renovar( array $grupo ): string {
		$claves                          = self::claves();
		$claves[ (string) $grupo['id'] ] = wp_generate_password( 24, false, false );
		update_option( self::OPCION, $claves, false );
		return $claves[ (string) $grupo['id'] ];
	}

	public static function valida( array $grupo, string $clave ): bool {
		$actual = self::claves()[ (string) $grupo['id'] ] ?? '';
		return '' !== $actual && '' !== $clave && hash_equals( $actual, $clave );
	}

	/** El enlace para apuntarse a la lista del grupo. */
	public static function url( array $grupo ): string {
		return Pages::url(
			'inscripcion_espera',
			array(
				'grupo' => rawurlencode( (string) $grupo['id'] ),
				'clave' => rawurlencode( self::clave( $grupo ) ),
			)
		);
	}

	public static function token( array $fila, string $uso ): string {
		return substr( hash_hmac( 'sha256', $uso . '|' . $fila['id'] . '|' . $fila['sal'], wp_salt( 'auth' ) ), 0, 32 );
	}

	public static function token_valido( array $fila, string $uso, string $token ): bool {
		return '' !== (string) $fila['sal'] && '' !== $token && hash_equals( self::token( $fila, $uso ), $token );
	}

	/** Enlace del email de confirmación. */
	public static function url_confirmar( array $fila ): string {
		return Pages::url(
			'inscripcion_espera',
			array(
				'confirmar' => (string) $fila['id'],
				't'         => self::token( $fila, self::CONFIRMAR ),
			)
		);
	}

	/** Enlace personal para ver sus datos, mantenerlos o borrarlos (pide además su email). */
	public static function url_acceso( array $fila ): string {
		return Pages::url(
			'inscripcion_espera',
			array(
				'espera' => (string) $fila['id'],
				't'      => self::token( $fila, self::ACCESO ),
			)
		);
	}
}
