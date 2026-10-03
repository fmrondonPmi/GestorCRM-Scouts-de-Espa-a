<?php
namespace AsdeBalears\AreaPrivada\Pwa;

use AsdeBalears\AreaPrivada\Admin\PwaPage;
use AsdeBalears\AreaPrivada\Idiomas\Idioma;
use AsdeBalears\AreaPrivada\Install\Entorno;

defined( 'ABSPATH' ) || exit;

/**
 * Comprobaciones de la app móvil (docs/pwa/, sección 5):
 *  - del servidor: pide a sí mismo las tres direcciones de la app y mira que respondan bien y que
 *    ninguna caché (LiteSpeed, CDN…) las esté guardando ni cambiando (lo que el manual de publicación
 *    hacía a mano con curl);
 *  - del entorno: la pieza «App móvil» de «Instalación y entorno».
 * Todo en lectura: no cambia nada del sitio.
 */
final class Comprobacion {

	public const RESULTADO = 'asde_ap_pwa_comprobacion';

	/**
	 * Las tres direcciones virtuales de la app.
	 *
	 * @return array<string, array{0:string, 1:string}> ruta => [Content-Type esperado, nombre]
	 */
	public static function rutas(): array {
		return array(
			Pwa::RUTA_MANIFIESTO             => array( 'application/manifest+json', 'Manifiesto' ),
			ServiceWorker::RUTA              => array( 'text/javascript', 'Service worker' ),
			ServiceWorker::RUTA_SIN_CONEXION => array( 'text/html', 'Página «Sin conexión»' ),
		);
	}

	/** Lo que debería devolver cada ruta, en el idioma por defecto (la petición a sí mismo no lleva cookies). */
	public static function esperado( string $ruta ): string {
		return (string) Idioma::en(
			Idioma::por_defecto(),
			static function () use ( $ruta ) {
				if ( Pwa::RUTA_MANIFIESTO === $ruta ) {
					return (string) wp_json_encode( Manifiesto::construir(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
				}
				if ( ServiceWorker::RUTA === $ruta ) {
					return ServiceWorker::contenido();
				}
				return ServiceWorker::pagina_sin_conexion();
			}
		);
	}

	/**
	 * Juzga la respuesta de una ruta (sin hacer ninguna petición: se le pasa la respuesta).
	 *
	 * @param array<string, mixed>|\WP_Error $r        Respuesta de wp_remote_get().
	 * @param string                         $esperado Contenido que debería tener ('' = no se compara).
	 * @return array{0:string, 1:string} [estado de Entorno, explicación]
	 */
	public static function evaluar( string $ruta, $r, string $esperado = '' ): array {
		$tipo = self::rutas()[ $ruta ][0] ?? '';
		if ( is_wp_error( $r ) ) {
			return array( Entorno::FALTA, 'El sitio no responde a esta petición (' . $r->get_error_message() . '). Puede ser el cortafuegos o el hosting (hay que permitir que el sitio se pida a sí mismo) o un servidor con muy pocos procesos PHP: vuelve a pulsar «Comprobar el servidor».' );
		}
		$codigo = (int) wp_remote_retrieve_response_code( $r );
		if ( 200 !== $codigo ) {
			$pista = 404 === $codigo ? ' Suele ser porque el plugin no es la versión con la app móvil, o por los enlaces permanentes: prueba Ajustes → Enlaces permanentes → Guardar.' : '';
			return array( Entorno::FALTA, sprintf( 'Responde %d en vez de 200.%s', $codigo, $pista ) );
		}
		$problemas = array();
		$ct        = strtolower( (string) wp_remote_retrieve_header( $r, 'content-type' ) );
		if ( '' !== $tipo && ! str_starts_with( $ct, $tipo ) ) {
			$problemas[] = sprintf( 'El tipo de contenido es «%s» y debería ser «%s»', $ct ?: 'ninguno', $tipo );
		}
		$cc = strtolower( (string) wp_remote_retrieve_header( $r, 'cache-control' ) );
		if ( ! str_contains( $cc, 'no-cache' ) && ! str_contains( $cc, 'no-store' ) ) {
			$problemas[] = sprintf( 'Cache-Control es «%s» y debería llevar no-cache (si no, los móviles no se enterarían de las actualizaciones)', $cc ?: 'ninguno' );
		}
		$cache = self::cache_que_responde( $r );
		if ( '' !== $cache ) {
			$problemas[] = 'Lo está sirviendo una caché (' . $cache . '): excluye esta dirección de la caché del plugin o del CDN (manual de publicación, apartado 6)';
		}
		if ( '' !== $esperado && ! $problemas && (string) wp_remote_retrieve_body( $r ) !== $esperado ) {
			$problemas[] = 'El contenido que llega no es el que genera el plugin (¿lo está minificando o guardando una caché?)';
		}
		if ( $problemas ) {
			return array( Entorno::AVISO, implode( '. ', $problemas ) . '.' );
		}
		return array( Entorno::OK, sprintf( '200, %s, sin caché.', $tipo ) );
	}

	/** Qué caché ha contestado (cadena vacía si ninguna): cabeceras típicas de LiteSpeed, de CDN y de proxies. */
	private static function cache_que_responde( $r ): string {
		$h = static fn( string $k ): string => strtolower( trim( (string) wp_remote_retrieve_header( $r, $k ) ) );
		if ( 'hit' === $h( 'x-litespeed-cache' ) ) {
			return 'LiteSpeed Cache';
		}
		if ( 'hit' === $h( 'cf-cache-status' ) ) {
			return 'Cloudflare';
		}
		if ( str_contains( $h( 'x-cache' ), 'hit' ) || str_contains( $h( 'x-proxy-cache' ), 'hit' ) ) {
			return 'un proxy o CDN';
		}
		if ( ctype_digit( $h( 'age' ) ) && (int) $h( 'age' ) > 0 ) {
			return 'cabecera Age de ' . (int) $h( 'age' ) . ' s';
		}
		return '';
	}

	/**
	 * Pide las tres direcciones y las juzga. Guarda el resultado diez minutos para enseñarlo en la pantalla.
	 *
	 * @return array{hora:int, activa:bool, filas:array<string, array{0:string, 1:string, 2:string}>} ruta => [estado, nombre, explicación]
	 */
	public static function ejecutar(): array {
		$res = array(
			'hora'   => time(),
			'activa' => Pwa::activa(),
			'filas'  => array(),
		);
		if ( $res['activa'] ) {
			if ( function_exists( 'set_time_limit' ) ) {
				@set_time_limit( 60 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,Squiz.PHP.DiscouragedFunctions
			}
			foreach ( self::rutas() as $ruta => list( , $nombre ) ) {
				$r = self::pedir( home_url( '/' . $ruta ) );
				if ( is_wp_error( $r ) && str_contains( strtolower( $r->get_error_message() ), 'timed out' ) ) {
					// Con pocos procesos PHP (p. ej. Local), la petición a uno mismo puede caer en el proceso ocupado con esta pantalla: se repite una vez.
					$r = self::pedir( home_url( '/' . $ruta ) );
				}
				list( $estado, $texto ) = self::evaluar( $ruta, $r, self::esperado( $ruta ) );
				$res['filas'][ $ruta ]  = array( $estado, $nombre, $texto );
			}
		}
		set_transient( self::RESULTADO, $res, 10 * MINUTE_IN_SECONDS );
		return $res;
	}

	/** Petición a una de las direcciones de la app (sin seguir redirecciones: una redirección ya es un fallo). */
	private static function pedir( string $url ) {
		return wp_remote_get(
			$url,
			array(
				'timeout'     => 5,
				'redirection' => 0,
				'sslverify'   => (bool) apply_filters( 'https_local_ssl_verify', false ),
				'headers'     => array( 'Cache-Control' => 'no-cache' ),
			)
		);
	}

	/** Último resultado guardado, o null. */
	public static function ultimo(): ?array {
		$r = get_transient( self::RESULTADO );
		return is_array( $r ) && isset( $r['filas'] ) ? $r : null;
	}

	/** El peor estado de una lista de filas (falta > aviso > ok). */
	public static function peor( array $filas ): string {
		$estados = array_map( static fn( $f ) => (string) $f[0], $filas );
		foreach ( array( Entorno::FALTA, Entorno::AVISO ) as $malo ) {
			if ( in_array( $malo, $estados, true ) ) {
				return $malo;
			}
		}
		return Entorno::OK;
	}

	/** ¿Se abre el sitio de forma segura? HTTPS o un servidor local (donde los navegadores también dejan usar service workers). */
	public static function contexto_seguro(): bool {
		$url = home_url();
		if ( str_starts_with( $url, 'https://' ) ) {
			return true;
		}
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		return in_array( $host, array( 'localhost', '127.0.0.1', '[::1]', '::1' ), true ) || str_ends_with( $host, '.localhost' );
	}

	/**
	 * La pieza «App móvil» de «Instalación y entorno».
	 *
	 * @return array{0:string, 1:string, 2:string, 3:array<string, string>}
	 */
	public static function entorno(): array {
		$enlaces = array( 'App móvil' => PwaPage::url() );
		if ( ! Pwa::activa() ) {
			return array( Entorno::INFO, 'App móvil (PWA)', 'Apagada: no se ofrece la instalación y las apps ya instaladas se retiran solas. Se enciende en la pantalla «App móvil».', $enlaces );
		}
		if ( ! self::contexto_seguro() ) {
			return array( Entorno::FALTA, 'App móvil (PWA)', 'La web no usa HTTPS: sin HTTPS los móviles no dejan instalar la app ni usar el modo sin conexión.', $enlaces );
		}
		$notas = array();
		$nivel = Entorno::OK;
		if ( '' === (string) get_option( 'permalink_structure' ) ) {
			$nivel   = Entorno::AVISO;
			$notas[] = 'Con enlaces permanentes simples la app abarca todo el sitio, no solo el área privada (Ajustes → Enlaces permanentes).';
		}
		if ( ! Iconos::del_sitio() ) {
			$nivel   = Entorno::OK === $nivel ? Entorno::INFO : $nivel;
			$notas[] = 'Sin «Icono del sitio»: la app lleva el icono genérico (una tienda morada). Para poner el del grupo: Apariencia → Personalizar → Identidad del sitio, imagen cuadrada de 512 × 512 px.';
			$enlaces['Icono del sitio'] = admin_url( 'customize.php?autofocus[section]=title_tagline' );
		}
		$notas[] = 'Para ver que ninguna caché toca la app, pulsa «Comprobar el servidor» en la pantalla «App móvil».';
		$base    = sprintf( 'Activa: se puede instalar en el móvil (alcance %s).', Manifiesto::alcance() );
		return array( $nivel, 'App móvil (PWA)', $base . ' ' . implode( ' ', $notas ), $enlaces );
	}
}
