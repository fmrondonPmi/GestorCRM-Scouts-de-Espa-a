<?php
namespace AsdeBalears\AreaPrivada\Install;

defined( 'ABSPATH' ) || exit;

/**
 * La app móvil (PWA) es un plugin aparte desde la 0.18 (`asde-area-privada-app`, repo GestorCRM-App).
 * Si ese plugin no está activo (no se ha instalado al actualizar, o se ha desactivado), quien ya tenía
 * la app instalada sigue teniendo un service worker que pide `/asde-sw.js`. Aquí se le contesta con
 * uno que borra las cachés de la app y se da de baja: la web vuelve a funcionar como una web normal
 * en ese móvil, sin quedarse «atascada». Con el plugin de la app activo, esto no hace nada.
 */
final class AppRetirada {

	public const RUTA = 'asde-sw.js';

	public static function register(): void {
		add_action( 'init', array( self::class, 'servir' ), 1 );
	}

	/** ¿Hay que contestar en esta petición? Solo GET a /asde-sw.js y sin el plugin de la app ($app: para las pruebas). */
	public static function toca( string $uri, string $metodo = 'GET', ?bool $app = null ): bool {
		$app ??= defined( 'ASDE_APP_VERSION' );
		if ( $app || 'GET' !== $metodo ) {
			return false;
		}
		$pedida = (string) wp_parse_url( $uri, PHP_URL_PATH );
		$propia = (string) wp_parse_url( home_url( '/' . self::RUTA ), PHP_URL_PATH );
		return '' !== $propia && untrailingslashit( $pedida ) === untrailingslashit( $propia );
	}

	public static function contenido(): string {
		return (string) file_get_contents( ASDE_AP_DIR . 'assets/js/sw-retirada.js' );
	}

	public static function servir(): void {
		$uri    = (string) ( $_SERVER['REQUEST_URI'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$metodo = (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( ! self::toca( $uri, $metodo ) ) {
			return;
		}
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- constante que usan los plugins de caché para no guardar la página
		}
		do_action( 'litespeed_control_set_nocache', 'asde-area-privada' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- gancho de LiteSpeed Cache: pide no guardar esta página
		status_header( 200 );
		header( 'Content-Type: text/javascript; charset=utf-8' );
		header( 'Cache-Control: no-cache' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		echo self::contenido(); // phpcs:ignore WordPress.Security.EscapeOutput
		exit;
	}
}
