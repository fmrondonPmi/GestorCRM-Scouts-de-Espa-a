<?php
namespace AsdeBalears\AreaPrivada\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Clave secreta de un enlace público del grupo (alta de scouters, lista de espera): una por grupo,
 * aleatoria, que Secretaría o Coordinación pueden renovar (la anterior deja de valer). Quien no
 * tiene el enlace no ve el formulario. La clase que lo usa define `OPCION` (dónde se guardan).
 * v0.33.2: las dos clases tenían copias de estas cuatro funciones (revisión con ponytail).
 */
trait ClaveDeGrupo {

	/** @return array<string, string> id del grupo => clave */
	private static function claves(): array {
		$c = get_option( self::OPCION, array() );
		return is_array( $c ) ? $c : array();
	}

	/** Clave actual del grupo (se crea la primera vez). */
	public static function clave( array $grupo ): string {
		$actual = self::claves()[ (string) $grupo['id'] ] ?? '';
		return '' !== $actual ? $actual : self::renovar( $grupo );
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
}
