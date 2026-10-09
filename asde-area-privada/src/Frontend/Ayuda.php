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
 * el enlace a su manual completo publicado (la «wiki»). Los trozos los escribe
 * herramientas/generar-manuales.php en ayuda/<manual>/<página>.html a partir de manual/fuentes
 * (cada apartado dice qué páginas documenta con data-paginas). Las capturas y los enlaces van al
 * manual (Organizacion::manual()): desde la v0.27, el que viene dentro del plugin (manual/, de su
 * misma versión), salvo que en wp-admin se ponga otro; sin ninguno, la ayuda sale sin ellos.
 */
final class Ayuda {

	/** Parámetro de la dirección que pide la ayuda de la página (?asde_ayuda=1). */
	public const PARAM = 'asde_ayuda';

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

	/** ¿Se está pidiendo la ayuda de la página? */
	public static function pedida(): bool {
		return '1' === sanitize_key( wp_unslash( $_GET[ self::PARAM ] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification -- solo lectura
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
		if ( ! preg_match( '/^[a-z_]+$/', $pagina ) ) {
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

	/** Enlace al manual completo (en su apartado, si se da), o '' si no hay manual. */
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

	/** Pone las direcciones del manual; sin él, quita las capturas y deja el texto de los enlaces. */
	private static function preparar( string $html, string $manual ): string {
		$html = (string) preg_replace( '/<!--.*?-->/s', '', $html );
		$base = Organizacion::manual();
		if ( '' === $base ) {
			$html = (string) preg_replace( '~<figure class="asde-ayuda__captura.*?</figure>~s', '', $html );
			return (string) preg_replace( '~<a href="\{\{(?:manual|base)\}\}[^"]*"[^>]*>(.*?)</a>~s', '$1', $html );
		}
		return strtr(
			$html,
			array(
				'{{capturas}}' => esc_attr( $base . 'capturas/' ),
				'{{manual}}'   => esc_attr( $base . $manual . '.html' ),
				'{{base}}'     => esc_attr( $base ),
			)
		);
	}
}
