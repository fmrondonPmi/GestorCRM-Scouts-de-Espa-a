<?php
namespace AsdeBalears\AreaPrivada\Pwa;

use AsdeBalears\AreaPrivada\Frontend\Pages;

defined( 'ABSPATH' ) || exit;

/**
 * PWA del área privada (docs/pwa/). Sección 1: manifiesto, iconos y etiquetas del
 * <head>. Las rutas son virtuales (D1): se sirven interceptando la petición en `init`,
 * sin reglas de reescritura que vaciar.
 */
final class Pwa {

	public const RUTA_MANIFIESTO = 'asde-manifest.webmanifest';

	/** Páginas donde sale la invitación a instalar: el acceso y el inicio del área. */
	public const PAGINAS_INSTALAR = array( 'acceso', 'area' );

	/** ¿Está activa la PWA? (La sección 5 lo convierte en un ajuste.) */
	public static function activa(): bool {
		return (bool) apply_filters( 'asde_ap_pwa_activa', true );
	}

	public static function url_manifiesto(): string {
		return home_url( '/' . self::RUTA_MANIFIESTO );
	}

	public function register(): void {
		add_action( 'init', array( $this, 'servir_ruta' ), 1 );
		add_action( 'wp_head', array( $this, 'etiquetas' ), 2 );
		add_action( 'wp_enqueue_scripts', array( $this, 'registro' ) );
	}

	/** Script que registra el service worker, en las páginas del área (D3). */
	public function registro(): void {
		if ( ! self::activa() || null === Pages::current_key() ) {
			return;
		}
		wp_enqueue_script( 'asde-pwa', plugins_url( 'assets/pwa/registro.js', ASDE_AP_FILE ), array(), ASDE_AP_VERSION, true );
		$datos = array(
			'sw'      => home_url( '/' . ServiceWorker::RUTA ),
			'alcance' => Manifiesto::alcance(),
		);
		// Invitación a instalar (sección 3): solo en el acceso y en el inicio del área.
		if ( in_array( Pages::current_key(), self::PAGINAS_INSTALAR, true ) ) {
			$datos['textos'] = self::textos_instalar();
			wp_enqueue_style( 'asde-pwa-instalar', plugins_url( 'assets/pwa/instalar.css', ASDE_AP_FILE ), array(), ASDE_AP_VERSION );
			wp_enqueue_script( 'asde-pwa-instalar', plugins_url( 'assets/pwa/instalar.js', ASDE_AP_FILE ), array( 'asde-pwa' ), ASDE_AP_VERSION, true );
		}
		wp_add_inline_script( 'asde-pwa', 'window.asdePwa=' . wp_json_encode( $datos, JSON_UNESCAPED_SLASHES ) . ';', 'before' );
	}

	/**
	 * Textos del aviso de instalación, ya en el idioma de la persona (el JS no lleva textos propios).
	 *
	 * @return array<string, string>
	 */
	public static function textos_instalar(): array {
		return array(
			'titulo'     => asde_t( 'Instala la app en tu móvil' ),
			'texto'      => asde_t( 'Entra con un toque desde la pantalla de inicio, sin escribir la dirección.' ),
			'instalar'   => asde_t( 'Instalar la app' ),
			'ahora_no'   => asde_t( 'Ahora no' ),
			'ios_titulo' => asde_t( 'Añade el área privada a tu pantalla de inicio' ),
			'ios_paso1'  => asde_t( 'Pulsa el botón Compartir de Safari (el cuadrado con la flecha hacia arriba).' ),
			'ios_paso2'  => asde_t( 'Elige «Añadir a pantalla de inicio» y pulsa «Añadir».' ),
			'entendido'  => asde_t( 'Entendido' ),
		);
	}

	/** ¿La petición actual es la ruta virtual dada (sea cual sea la carpeta del WordPress)? */
	public static function es_ruta( string $ruta, string $uri = '' ): bool {
		$uri    = '' !== $uri ? $uri : (string) ( $_SERVER['REQUEST_URI'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$pedida = (string) wp_parse_url( $uri, PHP_URL_PATH );
		$propia = (string) wp_parse_url( home_url( '/' . $ruta ), PHP_URL_PATH );
		return '' !== $propia && untrailingslashit( $pedida ) === untrailingslashit( $propia );
	}

	public function servir_ruta(): void {
		if ( 'GET' !== ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) {
			return;
		}
		if ( ! self::activa() ) {
			// PWA apagada: quien ya la tenía instalada recibe un service worker que se retira solo.
			if ( self::es_ruta( ServiceWorker::RUTA ) ) {
				$this->responder( 'text/javascript', ServiceWorker::contenido_apagado() );
			}
			return;
		}
		if ( self::es_ruta( ServiceWorker::RUTA_SIN_CONEXION ) ) {
			$this->responder( 'text/html', ServiceWorker::pagina_sin_conexion() );
		}
		if ( self::es_ruta( self::RUTA_MANIFIESTO ) ) {
			$this->responder( 'application/manifest+json', (string) wp_json_encode( Manifiesto::construir(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) );
		}
		if ( self::es_ruta( ServiceWorker::RUTA ) ) {
			// El navegador compara este fichero byte a byte con el que tiene: si cambia la versión del plugin, se actualiza solo.
			$this->responder( 'text/javascript', ServiceWorker::contenido() );
		}
	}

	/** Sirve una ruta virtual (sin caché, sin indexar) y termina. */
	private function responder( string $tipo, string $cuerpo ): void {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		do_action( 'litespeed_control_set_nocache', 'asde-area-privada' );
		status_header( 200 );
		header( 'Content-Type: ' . $tipo . '; charset=utf-8' );
		header( 'Cache-Control: no-cache' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		echo $cuerpo; // phpcs:ignore WordPress.Security.EscapeOutput
		exit;
	}

	/** Etiquetas del <head> de las páginas del área (D3). */
	public function etiquetas(): void {
		if ( ! self::activa() || null === Pages::current_key() ) {
			return;
		}
		echo '<link rel="manifest" href="' . esc_url( self::url_manifiesto() ) . "\">\n";
		echo '<meta name="theme-color" content="' . esc_attr( Manifiesto::color_tema() ) . "\">\n";
		echo "<meta name=\"mobile-web-app-capable\" content=\"yes\">\n";
		echo "<meta name=\"apple-mobile-web-app-capable\" content=\"yes\">\n";
		echo '<meta name="apple-mobile-web-app-title" content="' . esc_attr( Manifiesto::nombre_corto() ) . "\">\n";
		echo "<meta name=\"apple-mobile-web-app-status-bar-style\" content=\"default\">\n";
		echo '<link rel="apple-touch-icon" href="' . esc_url( Iconos::apple() ) . "\">\n";
	}
}
