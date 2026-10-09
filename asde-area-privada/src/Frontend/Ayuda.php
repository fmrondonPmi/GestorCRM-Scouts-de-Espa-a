<?php
namespace AsdeBalears\AreaPrivada\Frontend;

use AsdeBalears\AreaPrivada\Auth\Ambito;
use AsdeBalears\AreaPrivada\Domain\Organizacion;
use AsdeBalears\AreaPrivada\Install\Roles;
use AsdeBalears\AreaPrivada\Usuarios\Usuarios;

defined( 'ABSPATH' ) || exit;

/**
 * Ayuda de cada página del área privada (v0.26, petición del usuario: «según el rol que se
 * loguea deberá ver su manual; en cada página su ayuda y después un enlace a una wiki»).
 *
 * «Ayuda», junto al nombre de quien entra, abre en la ventana emergente el apartado de esa
 * página en el manual de su rol (familias, participantes, scouters o coordinación) y, al final,
 * «Ver el manual completo» (la «wiki»). Los trozos los escribe herramientas/generar-manuales.php
 * en ayuda/<manual>/<página>.html a partir de manual/fuentes (cada apartado dice qué páginas
 * documenta con data-paginas).
 *
 * v0.28 (petición del usuario: el zip con el manual y sus capturas pesaba más de 10 MB; «solo con
 * texto explicativo, siempre en ventanas modales»): la ayuda es solo texto (el pie de cada captura
 * queda como una nota) y el manual completo del rol también viene en el plugin, solo texto
 * (ayuda/<manual>/_completo.html): «Ver el manual completo» y los enlaces de la ayuda lo abren en
 * la misma ventana (?asde_ayuda=manual). El manual con capturas se sube a mano aparte, como ayuda
 * extra: si en wp-admin está su dirección (Organizacion::manual()), sale el enlace «Manual con
 * capturas», que se abre en otra pestaña.
 */
final class Ayuda {

	/** Parámetro de la dirección que pide la ayuda: de la página (?asde_ayuda=1) o el manual completo (=manual). */
	public const PARAM = 'asde_ayuda';

	/** Con ?asde_ayuda=manual, el manual que se quiere (por defecto, el del rol de quien entra). */
	public const PARAM_MANUAL = 'asde_manual';

	/** Prefijo de los id del manual completo (no chocan con los de la página de detrás). */
	public const PREFIJO_ID = 'asde-manual-';

	/** Manuales con ayuda en el área privada (el de administración es de wp-admin). */
	public const MANUALES = array( 'familias', 'participantes', 'scouters', 'coordinacion' );

	/** Si el manual del rol no tiene ayuda de una página, se busca en estos, por este orden. */
	private const RESERVA = array( 'coordinacion', 'scouters', 'familias', 'participantes' );

	/**
	 * Manual de unos roles: coordinación (Secretaría, Coordinación, Tesorería o quien administra
	 * la web), scouters, familias o participantes; '' si no tiene ninguno.
	 *
	 * @param string[] $roles
	 */
	public static function manual_de( array $roles, bool $admin ): string {
		if ( $admin || array_intersect( array( Roles::SECRETARIA, Roles::COORDINACION, Roles::TESORERIA ), $roles ) ) {
			return 'coordinacion';
		}
		if ( in_array( Roles::SCOUTER, $roles, true ) ) {
			return 'scouters';
		}
		if ( in_array( Roles::TUTOR, $roles, true ) ) {
			return 'familias';
		}
		return in_array( Roles::PARTICIPANTE, $roles, true ) ? 'participantes' : '';
	}

	/** Manual de quien ha entrado (por los roles de su cuenta). */
	public static function manual_para( Ambito $ambito ): string {
		return self::manual_de( Usuarios::roles_asde( $ambito->user ), $ambito->es_admin() );
	}

	/** ¿Se está pidiendo la ayuda (de la página o el manual completo)? */
	public static function pedida(): bool {
		return in_array( self::valor(), array( '1', 'manual' ), true );
	}

	/** ¿Se está pidiendo el manual completo (v0.28)? */
	public static function pide_completo(): bool {
		return 'manual' === self::valor();
	}

	/** El manual completo que se pide (?asde_manual=…), si es uno de los de la ayuda; si no, ''. */
	public static function manual_pedido(): string {
		$m = sanitize_key( wp_unslash( $_GET[ self::PARAM_MANUAL ] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification -- solo lectura
		return in_array( $m, self::MANUALES, true ) ? $m : '';
	}

	private static function valor(): string {
		return sanitize_key( wp_unslash( $_GET[ self::PARAM ] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification -- solo lectura
	}

	/** Dirección de la ayuda de una página (se abre en la ventana emergente). */
	public static function url( string $pagina ): string {
		return add_query_arg( self::PARAM, '1', Pages::url( $pagina ) );
	}

	/**
	 * La ayuda de una página: el trozo del manual del rol o, si ese manual no la tiene, del
	 * primero de RESERVA que la tenga.
	 *
	 * @return array{html:string, manual:string, ancla:string}|null
	 */
	public static function de_pagina( string $pagina, string $manual ): ?array {
		if ( ! preg_match( '/^[a-z][a-z_]*$/', $pagina ) ) { // Sin «_» delante: ayuda/<manual>/_completo.html no es una página.
			return null;
		}
		$orden = in_array( $manual, self::MANUALES, true ) ? array_unique( array_merge( array( $manual ), self::RESERVA ) ) : self::RESERVA;
		foreach ( $orden as $m ) {
			$f = self::dir() . $m . '/' . $pagina . '.html';
			if ( is_readable( $f ) ) {
				$html = (string) file_get_contents( $f ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- fichero del propio plugin
				return array(
					'html'   => self::preparar( $html, $m ),
					'manual' => $m,
					'ancla'  => preg_match( '/data-ancla="([a-z0-9-]*)"/', $html, $a ) ? $a[1] : '',
				);
			}
		}
		return null;
	}

	/**
	 * El manual completo de un rol, solo texto (v0.28), con sus enlaces puestos.
	 *
	 * @return array{html:string, manual:string}|null
	 */
	public static function completo( string $manual ): ?array {
		if ( ! in_array( $manual, self::MANUALES, true ) ) {
			return null;
		}
		$f = self::dir() . $manual . '/_completo.html';
		if ( ! is_readable( $f ) ) {
			return null;
		}
		$html = (string) file_get_contents( $f ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- fichero del propio plugin
		return array(
			'html'   => self::preparar( $html, $manual ),
			'manual' => $manual,
		);
	}

	/** Dirección del manual completo de un rol (se abre en la ventana), en su apartado si se da. */
	public static function url_completo( string $manual, string $ancla = '' ): string {
		$url = add_query_arg(
			array(
				self::PARAM        => 'manual',
				self::PARAM_MANUAL => in_array( $manual, self::MANUALES, true ) ? $manual : 'coordinacion',
			),
			Pages::url( 'area' )
		);
		return $url . ( '' !== $ancla ? '#' . self::PREFIJO_ID . $ancla : '' );
	}

	/**
	 * Enlace al manual con capturas (el que se sube a mano aparte, ayuda extra; en su apartado,
	 * si se da), o '' si no hay su dirección en wp-admin.
	 */
	public static function url_manual( string $manual, string $ancla = '' ): string {
		$base = Organizacion::manual();
		if ( '' === $base ) {
			return '';
		}
		return $base . ( in_array( $manual, self::MANUALES, true ) ? $manual . '.html' : 'index.html' ) . ( '' !== $ancla ? '#' . $ancla : '' );
	}

	/** Carpeta de los trozos de ayuda (dentro del plugin). */
	public static function dir(): string {
		return ASDE_AP_DIR . 'ayuda/';
	}

	/** Pone las direcciones del manual completo de cada rol (en la ventana) en los enlaces del texto. */
	private static function preparar( string $html, string $manual ): string {
		$html = (string) preg_replace( '/<!--.*?-->/s', '', $html );
		$html = (string) preg_replace_callback(
			'~\{\{completo(?::([a-z]+))?\}\}~',
			static fn( array $m ): string => esc_attr( self::url_completo( '' !== ( $m[1] ?? '' ) ? $m[1] : $manual ) ),
			$html
		);
		// Una marca que no se conoce (un trozo generado con otra versión) no llega a la página: su enlace se queda en texto.
		return (string) preg_replace( '~<a href="\{\{[^"]*"[^>]*>(.*?)</a>~s', '$1', $html );
	}
}
