<?php
namespace AsdeBalears\AreaPrivada\Idiomas;

defined( 'ABSPATH' ) || exit;

/**
 * Traducciones del idioma fijo (v0.60.1) leídas del `languages/*.mo` del paquete con el lector de WordPress
 * (`WP_Translation_File`, desde WordPress 6.5). Se guarda en `$l10n[ dominio ]` como lo haría `load_textdomain()`:
 * `__()`, `_x()` y `_n()` llaman a `translate()` y `translate_plural()`.
 */
final class TraduccionesFijas {

	/** @var array<string, string> original (con «contexto\4» delante si lo hay) => traducciones separadas por \0 */
	private array $entradas;

	private \WP_Translation_File $archivo;

	public function __construct( \WP_Translation_File $archivo ) {
		$this->archivo  = $archivo;
		$this->entradas = $archivo->entries();
	}

	/** ¿Se ha podido leer el fichero? */
	public static function leer( string $ruta ): ?self {
		if ( ! class_exists( '\WP_Translation_File' ) ) {
			return null;
		}
		$archivo = \WP_Translation_File::create( $ruta );
		return $archivo && ! $archivo->error() ? new self( $archivo ) : null;
	}

	/** @param string|null $singular @param string $context */
	public function translate( $singular, $context = '' ) {
		if ( null === $singular ) {
			return null;
		}
		$clave = '' !== (string) $context ? $context . "\4" . $singular : $singular;
		if ( ! isset( $this->entradas[ $clave ] ) ) {
			return $singular;
		}
		$formas = explode( "\0", $this->entradas[ $clave ] );
		return '' !== $formas[0] ? $formas[0] : $singular;
	}

	public function translate_plural( $singular, $plural, $count = 1, $context = '' ) {
		if ( null === $singular || null === $plural ) {
			return $singular;
		}
		$clave = '' !== (string) $context ? $context . "\4" . $singular : $singular;
		if ( isset( $this->entradas[ $clave ] ) ) {
			$formas = explode( "\0", $this->entradas[ $clave ] );
			$forma  = $formas[ $this->archivo->get_plural_form( (int) $count ) ] ?? $formas[0];
			if ( '' !== $forma ) {
				return $forma;
			}
		}
		return 1 === (int) $count ? $singular : $plural;
	}
}
