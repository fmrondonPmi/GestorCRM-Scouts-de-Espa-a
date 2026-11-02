<?php
namespace AsdeBalears\AreaPrivada\Idiomas;

defined( 'ABSPATH' ) || exit;

/**
 * Idioma del área privada (doc 62): es el de WordPress. Los textos están en inglés con gettext estándar y los
 * idiomas (castellano, gallego, euskera, catalán y valenciano) los traen los paquetes de idioma de
 * translate.wordpress.org. No hay selector propio: el sitio usa su idioma y cada persona el de su perfil.
 *
 * **Idioma fijo (v0.60.1):** si el paquete lleva `languages/<dominio>-es_ES.mo` (el zip de instalación directa lo lleva; el de
 * WordPress.org no), el área privada sale **siempre en castellano**, sea cual sea el idioma del sitio o de la persona, y no
 * depende de que el sitio tenga instalado el paquete de idioma. Sin ese fichero (o con el filtro `asde_ap_idioma_fijo` a ''),
 * se comporta como antes: el idioma de WordPress.
 *
 * Además, lo que necesitan los emails: salir en el idioma de quien los recibe.
 */
final class Idioma {

	/** Códigos de dos letras que guardaban las versiones anteriores (v0.15–v0.59) → idioma de WordPress. */
	private const ANTIGUOS = array(
		'es' => 'es_ES',
		'ca' => 'ca',
		'eu' => 'eu',
		'gl' => 'gl_ES',
	);

	/** Idioma fijo por defecto (filtro `asde_ap_idioma_fijo`; '' = seguir el idioma de WordPress). */
	public const FIJO = 'es_ES';

	private const DOMINIO = 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts';

	/** @var TraduccionesFijas|\MO|null */
	private static $mo_fijo = null;

	private static ?string $sugerido = null;

	private static bool $forzado = false;

	/** Engancha la carga del idioma fijo (lo llama el fichero principal del plugin). */
	public static function registrar(): void {
		add_filter( 'override_load_textdomain', array( self::class, 'cargar_fijo' ), 10, 4 );
		add_filter( 'override_unload_textdomain', array( self::class, 'no_descargar' ), 10, 2 );
		self::cargar_fijo( false, self::DOMINIO ); // Ya cargado: no depende de que WordPress encuentre un paquete de idioma.
	}

	/** Filtro `override_unload_textdomain`: con idioma fijo, al cambiar de idioma no se descargan nuestros textos. */
	public static function no_descargar( $ya, $dominio ) {
		return self::DOMINIO === $dominio && self::fijo_activo() ? true : $ya;
	}

	/** Idioma fijo (p. ej. es_ES) o '' si no hay. */
	public static function fijo(): string {
		return (string) apply_filters( 'asde_ap_idioma_fijo', self::FIJO );
	}

	/** Fichero .mo del idioma fijo dentro del plugin, o '' si el paquete no lo lleva. */
	public static function archivo_fijo(): string {
		$idioma = self::fijo();
		if ( '' === $idioma || ! defined( 'ASDE_AP_DIR' ) ) {
			return '';
		}
		$f = ASDE_AP_DIR . 'languages/' . self::DOMINIO . '-' . $idioma . '.mo';
		return is_readable( $f ) ? $f : '';
	}

	/** ¿Sale todo en el idioma fijo? (hay idioma fijo y el paquete lleva su .mo). */
	public static function fijo_activo(): bool {
		return '' !== self::archivo_fijo();
	}

	/**
	 * Filtro `override_load_textdomain`: cada vez que WordPress carga (o recarga, p. ej. al cambiar de idioma para un
	 * email) las traducciones de este plugin, se cargan las del idioma fijo del propio paquete.
	 *
	 * @param bool|mixed $ya      Si otro filtro ya lo ha resuelto.
	 * @param string     $dominio Dominio de textos.
	 */
	public static function cargar_fijo( $ya, $dominio ) {
		if ( $ya || self::DOMINIO !== $dominio ) {
			return $ya;
		}
		$f = self::archivo_fijo();
		if ( '' === $f ) {
			return $ya;
		}
		if ( null === self::$mo_fijo ) {
			// WordPress 6.5 o posterior: su lector de .mo; antes, la clase MO de siempre.
			$mo = TraduccionesFijas::leer( $f );
			if ( ! $mo ) {
				require_once ABSPATH . WPINC . '/pomo/mo.php';
				$mo = new \MO();
				if ( ! $mo->import_from_file( $f ) ) {
					return $ya;
				}
			}
			self::$mo_fijo = $mo;
		}
		global $l10n, $l10n_unloaded;
		$l10n[ $dominio ] = self::$mo_fijo; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- es lo que hace load_textdomain().
		unset( $l10n_unloaded[ $dominio ] );
		return true;
	}

	/** Idioma de esta petición, para el atributo `lang` de HTML («es-ES»). */
	public static function actual(): string {
		return str_replace( '_', '-', self::fijo_activo() ? self::fijo() : determine_locale() );
	}

	/** Idioma del sitio (o el fijo del plugin). */
	public static function por_defecto(): string {
		return self::fijo_activo() ? self::fijo() : get_locale();
	}

	/** ¿Parece un idioma de WordPress (es_ES, ca, eu…) o un código antiguo de dos letras? */
	public static function es_valido( $codigo ): bool {
		return is_string( $codigo ) && 1 === preg_match( '/^[a-z]{2,3}(?:_[A-Za-z0-9]+)?$/', $codigo );
	}

	/** Idioma de WordPress que corresponde a un código (también los antiguos de dos letras). */
	public static function normalizar( string $codigo ): string {
		return self::ANTIGUOS[ $codigo ] ?? $codigo;
	}

	/** Fuerza un idioma hasta que se vuelva a llamar (null: deja de forzarlo). Lo usan las pruebas. */
	public static function forzar( ?string $codigo ): void {
		if ( self::$forzado ) {
			restore_previous_locale();
			self::$forzado = false;
		}
		if ( self::fijo_activo() ) {
			return; // Idioma fijo: no se cambia.
		}
		if ( null !== $codigo && self::es_valido( $codigo ) && switch_to_locale( self::normalizar( $codigo ) ) ) {
			self::$forzado = true;
		}
	}

	/**
	 * Ejecuta algo en un idioma y deja el anterior como estaba (los textos de gettext salen en ese idioma).
	 *
	 * @return mixed
	 */
	public static function en( string $codigo, callable $fn ) {
		$cambiado = ! self::fijo_activo() && self::es_valido( $codigo ) && switch_to_locale( self::normalizar( $codigo ) );
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
		if ( self::fijo_activo() ) {
			return self::fijo();
		}
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
