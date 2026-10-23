<?php
namespace AsdeBalears\AreaPrivada\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Nº de socio/a local/territorial.
 *
 * Desde la v0.56 (doc 58) el formato lo fija cada grupo con sus datos (Datos del grupo):
 *   provincia (2 cifras, con su 0 inicial) + nº de grupo + 4 cifras incrementales.
 * En Illes Balears (provincia 07) y el grupo 684:
 *   076840390 → provincia 07 · grupo 684 · socio 0390
 *
 * Los números de antes de la v0.56 llevaban el «código de la organización» (en Scouts de Balears,
 * 7) en lugar de la provincia, es decir, el mismo número SIN el 0 inicial (76840390). Siguen
 * siendo válidos tal cual (no se tocan los del CRM ni las cuentas que los usan como usuario);
 * los números nuevos se generan ya con la provincia completa. En el CRM pueden convivir además
 * formatos más antiguos que se normalizan al leerlos:
 *   0076840123 (ceros de más)   → 076840123
 *   6840123    (sin provincia)  → 076840123
 * Cualquier otro formato se considera inválido y hay que corregirlo en el CRM.
 * Sin la provincia del grupo no se reconoce ni se genera ningún número.
 *
 * Es el USUARIO DE ACCESO de todos: socios con su número; tutores con el nº
 * del menor + "T" (cuenta familiar), p. ej. 076840390T.
 */
final class NumeroSocio {

	public const SUFIJO_TUTOR = 'T';

	/** Provincia del grupo, dos cifras con su 0 inicial ('' si aún no se sabe). */
	public static function provincia( string $grupo ): string {
		return '' === $grupo ? '' : Grupos::provincia( $grupo );
	}

	/** ¿Se puede formar el nº de socio/a de este grupo? (hace falta su provincia) */
	public static function configurado( string $grupo ): bool {
		return '' !== self::prefijo( $grupo );
	}

	/** Provincia + nº de grupo (p. ej. «07684»), o '' si el grupo no tiene provincia. */
	public static function prefijo( string $grupo ): string {
		$provincia = self::provincia( $grupo );
		return '' === $provincia || '' === $grupo ? '' : $provincia . $grupo;
	}

	/** El mismo prefijo sin el 0 inicial de la provincia: la forma de antes de la v0.56 («7684»). */
	public static function prefijo_antiguo( string $grupo ): string {
		return ltrim( self::prefijo( $grupo ), '0' );
	}

	/**
	 * Normaliza un valor del CRM o introducido por una persona. null si no es válido.
	 * El 0 inicial de la provincia se conserva; la forma de antes de la v0.56 (sin él) vale tal cual.
	 */
	public static function normalizar( string $valor, string $grupo ): ?string {
		$prefijo = self::prefijo( $grupo );
		$d       = (string) preg_replace( '/\D/', '', $valor );
		if ( '' === $d || '' === $prefijo ) {
			return null;
		}
		if ( preg_match( '/^' . preg_quote( $prefijo, '/' ) . '\d{4}$/', $d ) ) {
			return $d;
		}
		$sin_ceros = ltrim( $d, '0' );
		$antiguo   = self::prefijo_antiguo( $grupo );
		if ( $antiguo !== $prefijo && preg_match( '/^' . preg_quote( $antiguo, '/' ) . '\d{4}$/', $d ) ) {
			return $d; // Forma de antes de la v0.56.
		}
		if ( preg_match( '/^' . preg_quote( $antiguo, '/' ) . '\d{4}$/', $sin_ceros ) ) {
			return $prefijo . substr( $sin_ceros, -4 ); // Ceros de más.
		}
		if ( preg_match( '/^' . preg_quote( $grupo, '/' ) . '\d{4}$/', $d ) || preg_match( '/^' . preg_quote( $grupo, '/' ) . '\d{4}$/', $sin_ceros ) ) {
			return $prefijo . substr( $d, -4 ); // Le faltaba la provincia.
		}
		return null;
	}

	public static function es_valido( string $numero, string $grupo ): bool {
		return self::normalizar( $numero, $grupo ) === $numero;
	}

	/**
	 * Las dos formas de escribir un mismo nº de socio/a local que pueden existir como usuario
	 * (con y sin el 0 inicial de la provincia), contando todos los grupos dados de alta.
	 *
	 * @return string[] Las otras formas (sin incluir la dada).
	 */
	public static function otras_formas( string $numero ): array {
		$otras = array();
		foreach ( array_keys( Grupos::todos() ) as $grupo ) {
			$grupo   = (string) $grupo;
			$prefijo = self::prefijo( $grupo );
			$antiguo = self::prefijo_antiguo( $grupo );
			if ( '' === $prefijo || $prefijo === $antiguo ) {
				continue;
			}
			if ( preg_match( '/^' . preg_quote( $prefijo, '/' ) . '(\d{4})$/', $numero, $m ) ) {
				$otras[] = $antiguo . $m[1];
			} elseif ( preg_match( '/^' . preg_quote( $antiguo, '/' ) . '(\d{4})$/', $numero, $m ) ) {
				$otras[] = $prefijo . $m[1];
			}
		}
		return array_values( array_unique( $otras ) );
	}

	public static function sufijo( string $numero ): int {
		return (int) substr( $numero, -4 );
	}

	/** Número nuevo de un grupo: siempre con la provincia completa (076840001). */
	public static function formatear( string $grupo, int $sufijo ): string {
		return self::prefijo( $grupo ) . str_pad( (string) $sufijo, 4, '0', STR_PAD_LEFT );
	}

	/**
	 * Usuario de la cuenta familiar (tutores) de un socio menor. La primera cuenta
	 * es «nº + T» (076840390T) y la del segundo tutor/a, «nº + T2» (076840390T2):
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
	 * dado de alta cuyo prefijo (provincia + nº de grupo, o la forma de antes de la v0.56)
	 * encaja, seguido de 4 cifras.
	 *
	 * @param string[] $grupos Números de grupo dados de alta.
	 */
	public static function grupo_de_login( string $login, array $grupos ): ?string {
		$base = self::socio_de_usuario_tutor( $login ) ?? $login;
		foreach ( $grupos as $grupo ) {
			$grupo   = (string) $grupo;
			$prefijo = self::prefijo( $grupo );
			if ( '' === $prefijo ) {
				continue;
			}
			$formas = array_unique( array( $prefijo, self::prefijo_antiguo( $grupo ) ) );
			foreach ( $formas as $f ) {
				if ( preg_match( '/^' . preg_quote( $f, '/' ) . '\d{4}$/', $base ) ) {
					return $grupo;
				}
			}
		}
		return null;
	}

	/** Ejemplo para las ayudas de las pantallas (p. ej. 118241T o 076840390T según el usuario de acceso elegido). */
	public static function ejemplo_tutor(): string {
		return Identidad::ejemplo_tutor();
	}
}
