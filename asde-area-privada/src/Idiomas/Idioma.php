<?php
namespace AsdeBalears\AreaPrivada\Idiomas;

defined( 'ABSPATH' ) || exit;

/**
 * Idioma del área privada (doc 62): es el de WordPress. Los textos están en inglés con gettext estándar y los
 * idiomas (castellano, gallego, euskera, catalán y valenciano) los traen los paquetes de idioma de
 * translate.wordpress.org. No hay selector propio: el sitio usa su idioma y cada persona el de su perfil.
 *
 * Aquí solo queda lo que necesitan los emails: salir en el idioma de quien los recibe.
 */
final class Idioma {

	/** Códigos de dos letras que guardaban las versiones anteriores (v0.15–v0.59) → idioma de WordPress. */
	private const ANTIGUOS = array(
		'es' => 'es_ES',
		'ca' => 'ca',
		'eu' => 'eu',
		'gl' => 'gl_ES',
	);

	private static ?string $sugerido = null;

	/** Idioma de esta petición, para el atributo `lang` de HTML («es-ES»). */
	public static function actual(): string {
		return str_replace( '_', '-', determine_locale() );
	}

	/** Idioma del sitio. */
	public static function por_defecto(): string {
		return get_locale();
	}

	/** ¿Parece un idioma de WordPress (es_ES, ca, eu…) o un código antiguo de dos letras? */
	public static function es_valido( $codigo ): bool {
		return is_string( $codigo ) && 1 === preg_match( '/^[a-z]{2,3}(?:_[A-Za-z0-9]+)?$/', $codigo );
	}

	/** Idioma de WordPress que corresponde a un código (también los antiguos de dos letras). */
	public static function normalizar( string $codigo ): string {
		return self::ANTIGUOS[ $codigo ] ?? $codigo;
	}

	/**
	 * Ejecuta algo en un idioma y deja el anterior como estaba (los textos de gettext salen en ese idioma).
	 *
	 * @return mixed
	 */
	public static function en( string $codigo, callable $fn ) {
		$cambiado = self::es_valido( $codigo ) && switch_to_locale( self::normalizar( $codigo ) );
		try {
			return $fn();
		} finally {
			if ( $cambiado ) {
				restore_previous_locale();
			}
		}
	}

	/**
	 * Idioma de una persona que NO es quien está usando la web (el destinatario de un email): el de su perfil o,
	 * si no tiene, el sugerido (el de la solicitud que se está aprobando) o el del sitio.
	 */
	public static function de_usuario( int $user_id ): string {
		$propio = $user_id ? (string) get_user_meta( $user_id, 'locale', true ) : '';
		if ( '' !== $propio ) {
			return $propio;
		}
		return null !== self::$sugerido ? self::$sugerido : self::por_defecto();
	}

	/** Idioma que se da a las cuentas que se creen ahora (null: ninguno): el de la solicitud de acceso que se aprueba. */
	public static function sugerir( ?string $codigo ): void {
		self::$sugerido = null !== $codigo && self::es_valido( $codigo ) ? self::normalizar( $codigo ) : null;
	}

	/** Pone el idioma de una cuenta (el que verá en su perfil de WordPress). */
	public static function elegir( string $codigo, int $user_id ): bool {
		if ( ! self::es_valido( $codigo ) || $user_id <= 0 ) {
			return false;
		}
		update_user_meta( $user_id, 'locale', self::normalizar( $codigo ) );
		return true;
	}
}
