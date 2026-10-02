<?php
namespace AsdeBalears\AreaPrivada\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Nº de socio/a local/territorial.
 *
 * Formato: código de la organización federada + nº de grupo + 4 dígitos
 * incrementales. El código se configura en wp-admin → Grupos Scouts →
 * Organización federada (Domain\Organizacion); en Scouts de Balears es 7:
 *   76840390 → código 7 · grupo 684 · socio 0390
 *
 * En el CRM (campo numsocioantiguo_enficha_c) conviven formatos antiguos que se
 * normalizan al leerlos:
 *   076840123 (0 inicial)       → 76840123
 *   6840123   (sin el código)   → 76840123
 * Cualquier otro formato se considera inválido y hay que corregirlo en el CRM.
 * Sin código configurado no se reconoce ni se genera ningún número.
 *
 * Es el USUARIO DE ACCESO de todos: socios con su número; tutores con el nº
 * del menor + "T" (cuenta familiar), p. ej. 76840390T.
 */
final class NumeroSocio {

	public const SUFIJO_TUTOR = 'T';

	/** Código de la organización federada ('' si aún no se ha configurado). */
	public static function codigo(): string {
		return Organizacion::codigo();
	}

	public static function prefijo( string $grupo ): string {
		return self::codigo() . $grupo;
	}

	/** Normaliza un valor del CRM o introducido por una persona. null si no es válido. */
	public static function normalizar( string $valor, string $grupo ): ?string {
		$digitos = ltrim( (string) preg_replace( '/\D/', '', $valor ), '0' );
		if ( '' === $digitos || '' === $grupo || '' === self::codigo() ) {
			return null;
		}
		if ( preg_match( '/^' . preg_quote( self::prefijo( $grupo ), '/' ) . '\d{4}$/', $digitos ) ) {
			return $digitos;
		}
		if ( preg_match( '/^' . preg_quote( $grupo, '/' ) . '\d{4}$/', $digitos ) ) {
			return self::codigo() . $digitos; // Le faltaba el código de la organización.
		}
		return null;
	}

	public static function es_valido( string $numero, string $grupo ): bool {
		return self::normalizar( $numero, $grupo ) === $numero;
	}

	public static function sufijo( string $numero ): int {
		return (int) substr( $numero, -4 );
	}

	public static function formatear( string $grupo, int $sufijo ): string {
		return self::prefijo( $grupo ) . str_pad( (string) $sufijo, 4, '0', STR_PAD_LEFT );
	}

	/**
	 * Usuario de la cuenta familiar (tutores) de un socio menor. La primera cuenta
	 * es «nº + T» (76840390T) y la del segundo tutor/a, «nº + T2» (76840390T2):
	 * cada tutor/a entra con su propia cuenta y su propio email (v0.12).
	 */
	public static function usuario_tutor( string $numero, int $orden = 1 ): string {
		return $numero . self::SUFIJO_TUTOR . ( $orden > 1 ? (string) $orden : '' );
	}

	/** ¿Es un usuario de cuenta familiar (T o T2)? Devuelve el nº del socio base o null. */
	public static function socio_de_usuario_tutor( string $login ): ?string {
		return preg_match( '/^(\d{4,12})' . self::SUFIJO_TUTOR . '[2-9]?$/i', $login, $m ) ? $m[1] : null;
	}

	/** Qué tutor/a es una cuenta familiar: 1 («…T»), 2 («…T2»)… o 0 si no es de una familia. */
	public static function orden_tutor( string $login ): int {
		return preg_match( '/^\d{4,12}' . self::SUFIJO_TUTOR . '([2-9]?)$/i', $login, $m ) ? ( '' === $m[1] ? 1 : (int) $m[1] ) : 0;
	}

	/**
	 * Grupo de un usuario por su nº de socio (con T en las familias): el grupo
	 * dado de alta cuyo prefijo (código + nº de grupo) encaja, seguido de 4 cifras.
	 *
	 * @param string[] $grupos Números de grupo dados de alta.
	 */
	public static function grupo_de_login( string $login, array $grupos ): ?string {
		if ( '' === self::codigo() ) {
			return null;
		}
		$base = self::socio_de_usuario_tutor( $login ) ?? $login;
		foreach ( $grupos as $grupo ) {
			if ( preg_match( '/^' . preg_quote( self::prefijo( (string) $grupo ), '/' ) . '\d{4}$/', $base ) ) {
				return (string) $grupo;
			}
		}
		return null;
	}

	/** Ejemplo para las ayudas de las pantallas (p. ej. 118241T o 76840390T según el usuario de acceso elegido). */
	public static function ejemplo_tutor(): string {
		return Identidad::ejemplo_tutor();
	}
}
