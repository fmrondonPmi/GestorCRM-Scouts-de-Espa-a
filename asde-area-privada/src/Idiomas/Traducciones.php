<?php
namespace AsdeBalears\AreaPrivada\Idiomas;

defined( 'ABSPATH' ) || exit;

/**
 * Textos del área privada en cada idioma (v0.15, doc 28).
 *
 * Todos los textos del código están escritos en castellano y **ese texto es la clave**: se
 * pide con `asde_t( 'Mis datos' )` (o `asde_h`, `asde_e`…, ver funciones.php).
 * La traducción sale, por orden, de:
 *
 *   1. las **etiquetas personalizadas** de la administración (wp-admin → Idioma y etiquetas),
 *      que valen para cualquier idioma, también el castellano;
 *   2. el **catálogo** del idioma (`languages/<código>.php`: clave en castellano => traducción);
 *   3. el propio texto en castellano, si no hay traducción.
 *
 * Los textos con partes que cambian llevan marcadores de `sprintf` (`%s`, `%d`, `%1$s`) y los
 * rellena quien los pide: `sprintf( asde_t( 'Pedido %s enviado' ), $numero )`. Las traducciones
 * tienen que conservar los mismos marcadores.
 */
final class Traducciones {

	public const OPCION = 'asde_ap_etiquetas';

	/** @var array<string, array<string, string>> */
	private static array $catalogos = array();

	/** @var array<string, array<string, string>>|null idioma => [clave => texto] */
	private static ?array $personalizadas = null;

	/** @var array<string, string>|null clave en castellano => grupo */
	private static ?array $textos = null;

	/** Traducción de un texto como texto plano (sin escapar). */
	public static function t( string $es, ?string $idioma = null ): string {
		$idioma ??= Idioma::actual();
		$propia   = self::personalizadas()[ $idioma ][ $es ] ?? '';
		if ( '' !== $propia ) {
			return $propia;
		}
		if ( Idioma::DEFECTO === $idioma ) {
			return $es;
		}
		$t = self::catalogo( $idioma )[ $es ] ?? '';
		return '' !== $t ? $t : $es;
	}

	/**
	 * Traducción lista para HTML: se admiten las etiquetas básicas de texto (negrita, cursiva,
	 * código, saltos de línea y enlaces), nada más.
	 */
	public static function h( string $es, ?string $idioma = null ): string {
		$t = self::t( $es, $idioma );
		// El texto original lo escribe quien programa (de fiar); lo que viene de las etiquetas o del catálogo, se limpia.
		return $t === $es ? $es : wp_kses( $t, self::etiquetas_permitidas() );
	}

	/** @return array<string, array<string, bool>> */
	public static function etiquetas_permitidas(): array {
		return array(
			'strong' => array(),
			'b'      => array(),
			'em'     => array(),
			'i'      => array(),
			'code'   => array(),
			'br'     => array(),
			'span'   => array( 'class' => true ),
			'a'      => array(
				'href'   => true,
				'title'  => true,
				'target' => true,
				'rel'    => true,
				'class'  => true,
			),
		);
	}

	// ---------------------------------------------------------------------
	// Catálogos
	// ---------------------------------------------------------------------

	/** Textos traducidos de un idioma (clave en castellano => traducción). @return array<string, string> */
	public static function catalogo( string $idioma ): array {
		if ( ! Idioma::es_valido( $idioma ) || Idioma::DEFECTO === $idioma ) {
			return array();
		}
		if ( ! isset( self::$catalogos[ $idioma ] ) ) {
			$fichero                    = ASDE_AP_DIR . 'languages/' . $idioma . '.php';
			$datos                      = is_readable( $fichero ) ? require $fichero : array();
			// Filtro: otro plugin o las pruebas pueden añadir o cambiar traducciones.
			self::$catalogos[ $idioma ] = (array) apply_filters( 'asde_ap_catalogo', is_array( $datos ) ? $datos : array(), $idioma );
		}
		return self::$catalogos[ $idioma ];
	}

	/**
	 * Todos los textos que conoce el sistema (clave en castellano => grupo de pantallas), para la
	 * pantalla de etiquetas.
	 *
	 * @return array<string, string>
	 */
	public static function textos(): array {
		if ( null === self::$textos ) {
			$fichero      = ASDE_AP_DIR . 'languages/es.php';
			$datos        = is_readable( $fichero ) ? require $fichero : array();
			self::$textos = (array) apply_filters( 'asde_ap_textos', is_array( $datos ) ? $datos : array() );
		}
		return self::$textos;
	}

	// ---------------------------------------------------------------------
	// Etiquetas personalizadas por la administración
	// ---------------------------------------------------------------------

	/** @return array<string, array<string, string>> idioma => [clave => texto] */
	public static function personalizadas(): array {
		if ( null === self::$personalizadas ) {
			$g                    = get_option( self::OPCION, array() );
			self::$personalizadas = self::limpiar( is_array( $g ) ? $g : array() );
		}
		return self::$personalizadas;
	}

	/**
	 * Guarda las etiquetas de UN idioma de las claves indicadas: un texto vacío (o igual a lo que
	 * saldría sin personalizar) quita la personalización. Lo demás se conserva.
	 *
	 * @param array<string, string> $cambios clave en castellano => texto
	 * @return int Cuántas etiquetas personalizadas tiene ahora ese idioma.
	 */
	public static function guardar( string $idioma, array $cambios ): int {
		if ( ! Idioma::es_valido( $idioma ) ) {
			return 0;
		}
		$todas = self::personalizadas();
		foreach ( $cambios as $clave => $texto ) {
			$clave = (string) $clave;
			$texto = self::depurar( (string) $texto );
			unset( $todas[ $idioma ][ $clave ] );
			$base = Idioma::DEFECTO === $idioma ? $clave : ( self::catalogo( $idioma )[ $clave ] ?? $clave );
			if ( '' !== $texto && $texto !== $base && self::mismos_marcadores( $clave, $texto ) ) {
				$todas[ $idioma ][ $clave ] = $texto;
			}
		}
		self::fijar( $todas );
		return count( self::personalizadas()[ $idioma ] ?? array() );
	}

	/** Quita todas las personalizaciones de un idioma (o de todos con null). */
	public static function restablecer( ?string $idioma = null ): void {
		if ( null === $idioma ) {
			self::fijar( array() );
			return;
		}
		$todas = self::personalizadas();
		unset( $todas[ $idioma ] );
		self::fijar( $todas );
	}

	/** Las personalizaciones, en JSON, para guardarlas o pasarlas a otro sitio. */
	public static function exportar(): string {
		return (string) wp_json_encode(
			array(
				'plugin'    => 'asde-area-privada',
				'etiquetas' => self::personalizadas(),
			),
			JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
		);
	}

	/**
	 * Importa personalizaciones (JSON de exportar()), sustituyendo las actuales.
	 *
	 * @return int|\WP_Error Cuántas etiquetas quedan.
	 */
	public static function importar( string $json ) {
		$d = json_decode( $json, true );
		if ( ! is_array( $d ) || ! isset( $d['etiquetas'] ) || ! is_array( $d['etiquetas'] ) ) {
			return new \WP_Error( 'asde_etiquetas', 'El fichero no es una exportación de etiquetas de este plugin.' );
		}
		$limpias = self::limpiar( $d['etiquetas'] );
		self::fijar( $limpias );
		return array_sum( array_map( 'count', $limpias ) );
	}

	/** @param array<string, array<string, string>> $todas */
	private static function fijar( array $todas ): void {
		$todas = self::limpiar( $todas );
		if ( $todas ) {
			update_option( self::OPCION, $todas, false );
		} else {
			delete_option( self::OPCION );
		}
		self::$personalizadas = $todas;
	}

	/** Solo idiomas conocidos y textos no vacíos. @return array<string, array<string, string>> */
	private static function limpiar( array $datos ): array {
		$out = array();
		foreach ( $datos as $idioma => $textos ) {
			if ( ! Idioma::es_valido( $idioma ) || ! is_array( $textos ) ) {
				continue;
			}
			foreach ( $textos as $clave => $texto ) {
				if ( is_string( $clave ) && '' !== $clave && is_string( $texto ) && '' !== self::depurar( $texto ) ) {
					$out[ $idioma ][ $clave ] = self::depurar( $texto );
				}
			}
		}
		return $out;
	}

	/** Quita lo que no puede ir en un texto de interfaz (etiquetas no permitidas, espacios de más). */
	public static function depurar( string $texto ): string {
		return trim( wp_kses( $texto, self::etiquetas_permitidas() ) );
	}

	/** ¿La traducción conserva los marcadores de sprintf (%s, %d, %1$s…) del original? */
	public static function mismos_marcadores( string $original, string $traduccion ): bool {
		$a = self::marcadores( $original );
		$b = self::marcadores( $traduccion );
		sort( $a );
		sort( $b );
		return $a === $b;
	}

	/** @return string[] */
	public static function marcadores( string $texto ): array {
		preg_match_all( '/%(?:\d+\$)?[sdfu]/', str_replace( '%%', '', $texto ), $m );
		return $m[0];
	}

	/** Se olvida lo cargado (pruebas). */
	public static function olvidar(): void {
		self::$catalogos      = array();
		self::$personalizadas = null;
		self::$textos         = null;
	}
}
