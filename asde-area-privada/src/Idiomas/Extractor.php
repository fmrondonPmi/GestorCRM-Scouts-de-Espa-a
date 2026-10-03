<?php
namespace AsdeBalears\AreaPrivada\Idiomas;

defined( 'ABSPATH' ) || exit;

/**
 * Busca en el código los textos que se pueden traducir: los literales que se pasan a
 * `asde_t()`, `asde_h()`, `asde_e()` y `asde_a()` (y a `__()` y compañía con el dominio del
 * plugin). Sirve para generar la lista de textos (languages/es.php) y para comprobar que cada
 * idioma los tiene todos (tests/idiomas-catalogos.php).
 *
 * No entra en las pantallas de wp-admin del plugin (siguen en el idioma de WordPress).
 */
final class Extractor {

	public const FUNCIONES = array( 'asde_t', 'asde_h', 'asde_e', 'asde_a', 'asde_js', 'asde_ta', 'asde_n' );
	public const GETTEXT   = array( '__', '_e', 'esc_html__', 'esc_html_e', 'esc_attr__', 'esc_attr_e' );
	public const DOMINIO   = 'asde-area-privada';

	/** Carpetas y ficheros que no se traducen (wp-admin, instalación, el propio idioma, pruebas). */
	private const EXCLUIDOS = array( 'src/Admin/', 'src/Install/Entorno.php', 'src/Install/Installer.php', 'src/Idiomas/', 'tests/', 'herramientas/', 'languages/' );

	/**
	 * Ficheros PHP de los que salen textos (rutas relativas al plugin, con «/»).
	 *
	 * @return string[]
	 */
	public static function ficheros( string $raiz ): array {
		$raiz = rtrim( str_replace( '\\', '/', $raiz ), '/' ) . '/';
		$out  = array();
		$it   = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $raiz, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $f ) {
			$ruta = str_replace( '\\', '/', $f->getPathname() );
			$rel  = substr( $ruta, strlen( $raiz ) );
			if ( ! str_ends_with( $rel, '.php' ) || self::excluido( $rel ) ) {
				continue;
			}
			$out[] = $rel;
		}
		sort( $out );
		return $out;
	}

	public static function excluido( string $rel ): bool {
		foreach ( self::EXCLUIDOS as $e ) {
			if ( str_starts_with( $rel, $e ) ) {
				return true;
			}
		}
		return str_ends_with( $rel, 'uninstall.php' ) || ! str_contains( $rel, '/' );
	}

	/**
	 * Textos de todo el plugin: texto en castellano => grupo de pantallas del primer fichero donde sale.
	 *
	 * @return array<string, string>
	 */
	public static function textos( string $raiz ): array {
		$raiz = rtrim( str_replace( '\\', '/', $raiz ), '/' ) . '/';
		$out  = array();
		foreach ( self::ficheros( $raiz ) as $rel ) {
			foreach ( array_keys( self::de_codigo( (string) file_get_contents( $raiz . $rel ) ) ) as $texto ) {
				$out[ $texto ] ??= self::grupo( $rel );
			}
		}
		ksort( $out, SORT_STRING );
		return $out;
	}

	/**
	 * Textos que pide un trozo de código PHP.
	 *
	 * @return array<string, int> texto => veces que sale
	 */
	public static function de_codigo( string $php ): array {
		$tokens = token_get_all( $php );
		$n      = count( $tokens );
		$out    = array();
		for ( $i = 0; $i < $n; $i++ ) {
			$t = $tokens[ $i ];
			if ( ! is_array( $t ) || T_STRING !== $t[0] ) {
				continue;
			}
			$nombre = $t[1];
			$es_asde = in_array( $nombre, self::FUNCIONES, true );
			if ( ! $es_asde && ! in_array( $nombre, self::GETTEXT, true ) ) {
				continue;
			}
			// No es un método ni una definición: «->asde_t(» o «function asde_t(».
			$antes = self::anterior( $tokens, $i );
			if ( null !== $antes && in_array( $antes, array( T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW ), true ) ) {
				continue;
			}
			$j = self::siguiente( $tokens, $i );
			if ( null === $j || '(' !== $tokens[ $j ] ) {
				continue;
			}
			$k = self::siguiente( $tokens, $j );
			if ( null === $k || ! is_array( $tokens[ $k ] ) || T_CONSTANT_ENCAPSED_STRING !== $tokens[ $k ][0] ) {
				continue;
			}
			$texto = self::literal( $tokens[ $k ][1] );
			$m     = self::siguiente( $tokens, $k );
			if ( null === $texto || null === $m ) {
				continue;
			}
			if ( $es_asde ) {
				if ( ')' !== $tokens[ $m ] && ',' !== $tokens[ $m ] ) {
					continue; // Es una concatenación o algo dinámico: no se puede extraer.
				}
			} else {
				// __( 'texto', 'asde-area-privada' )
				if ( ',' !== $tokens[ $m ] ) {
					continue;
				}
				$d = self::siguiente( $tokens, $m );
				if ( null === $d || ! is_array( $tokens[ $d ] ) || T_CONSTANT_ENCAPSED_STRING !== $tokens[ $d ][0] || self::DOMINIO !== self::literal( $tokens[ $d ][1] ) ) {
					continue;
				}
			}
			if ( '' !== trim( $texto ) ) {
				$out[ $texto ] = ( $out[ $texto ] ?? 0 ) + 1;
			}
			// asde_n( 'singular', 'plural', $n ): el plural también es un texto.
			if ( 'asde_n' === $nombre && ',' === $tokens[ $m ] ) {
				$p      = self::siguiente( $tokens, $m );
				$plural = null !== $p && is_array( $tokens[ $p ] ) && T_CONSTANT_ENCAPSED_STRING === $tokens[ $p ][0] ? self::literal( $tokens[ $p ][1] ) : null;
				if ( null !== $plural && '' !== trim( $plural ) ) {
					$out[ $plural ] = ( $out[ $plural ] ?? 0 ) + 1;
				}
			}
		}
		return $out;
	}

	/** Valor de un literal PHP entre comillas simples o dobles (sin variables); null si no se puede leer. */
	public static function literal( string $token ): ?string {
		$q = $token[0] ?? '';
		$c = substr( $token, 1, -1 );
		if ( "'" === $q ) {
			return str_replace( array( "\\\\", "\\'" ), array( '\\', "'" ), $c );
		}
		if ( '"' === $q && ! preg_match( '/(?<!\\\\)[$]|\{\$/', $c ) ) {
			return stripcslashes( $c );
		}
		return null;
	}

	/** Grupo de pantallas que se enseña en la pantalla de etiquetas, según el fichero. */
	public static function grupo( string $rel ): string {
		$grupos = array(
			'templates/comun/'       => 'Menú y avisos',
			'templates/pwa/'         => 'App móvil',
			'src/Pwa/'               => 'App móvil',
			'templates/area/'        => 'Inicio',
			'templates/uniformes/'   => 'Uniformes',
			'templates/tesoreria/'   => 'Tesorería',
			'templates/pagos/'       => 'Mis pagos',
			'templates/usuarios/'    => 'Usuarios',
			'templates/alta/'        => 'Dar acceso',
			'templates/personas/'    => 'Personas',
			'templates/solicitudes/' => 'Solicitudes de acceso',
			'templates/alta-scouters/' => 'Alta de scouters',
			'templates/fichas/'      => 'Fichas',
			'templates/informes/'    => 'Informes',
			'templates/acceso/'      => 'Acceso',
			'templates/activar/'     => 'Activar cuenta',
			'templates/recuperar/'   => 'Recuperar contraseña',
			'templates/seguridad/'   => 'Seguridad',
			'templates/email/'       => 'Emails',
			'src/Uniformes/'         => 'Uniformes',
			'src/Tesoreria/'         => 'Tesorería',
			'src/Sepa/'              => 'Domiciliaciones SEPA',
			'src/Usuarios/'          => 'Usuarios',
			'src/Fichas/'            => 'Fichas',
			'src/Informes/'          => 'Informes',
			'src/Auth/'              => 'Acceso',
			'src/Socios/'            => 'Números de socio',
			'src/Domain/'            => 'Datos del grupo',
			'src/Crm/'               => 'CRM',
			'src/Frontend/Views/'    => 'Pantallas',
			'src/Frontend/'          => 'Menú y avisos',
		);
		foreach ( $grupos as $prefijo => $nombre ) {
			if ( str_starts_with( $rel, $prefijo ) ) {
				return $nombre;
			}
		}
		return 'Otros';
	}

	// ---------------------------------------------------------------------

	/** @param array<int, mixed> $tokens */
	private static function siguiente( array $tokens, int $desde ): ?int {
		$n = count( $tokens );
		for ( $i = $desde + 1; $i < $n; $i++ ) {
			if ( is_array( $tokens[ $i ] ) && in_array( $tokens[ $i ][0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			}
			return $i;
		}
		return null;
	}

	/** @param array<int, mixed> $tokens */
	private static function anterior( array $tokens, int $hasta ): ?int {
		for ( $i = $hasta - 1; $i >= 0; $i-- ) {
			if ( is_array( $tokens[ $i ] ) && in_array( $tokens[ $i ][0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			}
			return is_array( $tokens[ $i ] ) ? $tokens[ $i ][0] : null;
		}
		return null;
	}
}
