<?php
namespace AsdeBalears\AreaPrivada\Frontend;

use AsdeBalears\AreaPrivada\Auth\Ambito;
use AsdeBalears\AreaPrivada\Frontend\Views\AccesoView;
use AsdeBalears\AreaPrivada\Frontend\Views\ActivarCuentaView;
use AsdeBalears\AreaPrivada\Frontend\Views\AltaUsuariosView;
use AsdeBalears\AreaPrivada\Frontend\Views\AreaPrivadaView;
use AsdeBalears\AreaPrivada\Frontend\Views\BancoView;
use AsdeBalears\AreaPrivada\Frontend\Views\CambiosView;
use AsdeBalears\AreaPrivada\Frontend\Views\FichaView;
use AsdeBalears\AreaPrivada\Frontend\Views\CuotasView;
use AsdeBalears\AreaPrivada\Frontend\Views\EmailsView;
use AsdeBalears\AreaPrivada\Frontend\Views\FamiliasView;
use AsdeBalears\AreaPrivada\Frontend\Views\FederacionView;
use AsdeBalears\AreaPrivada\Frontend\Views\InformesView;
use AsdeBalears\AreaPrivada\Frontend\Views\MisPagosView;
use AsdeBalears\AreaPrivada\Frontend\Views\TesoreriaView;
use AsdeBalears\AreaPrivada\Frontend\Views\UniformeView;
use AsdeBalears\AreaPrivada\Frontend\Views\UniformesConfigView;
use AsdeBalears\AreaPrivada\Frontend\Views\UniformesGestionView;
use AsdeBalears\AreaPrivada\Frontend\Views\PersonasView;
use AsdeBalears\AreaPrivada\Frontend\Views\RecuperarView;
use AsdeBalears\AreaPrivada\Frontend\Views\SepaView;
use AsdeBalears\AreaPrivada\Frontend\Views\SeguridadView;
use AsdeBalears\AreaPrivada\Frontend\Views\SolicitarView;
use AsdeBalears\AreaPrivada\Frontend\Views\SolicitudesView;
use AsdeBalears\AreaPrivada\Frontend\Views\UsuariosView;
use AsdeBalears\AreaPrivada\Frontend\Views\View;
use AsdeBalears\AreaPrivada\Idiomas\Idioma;
use AsdeBalears\AreaPrivada\Idiomas\TextosJs;
use AsdeBalears\AreaPrivada\Idiomas\Traducciones;
use AsdeBalears\AreaPrivada\Socios\TareaNumeracion;

defined( 'ABSPATH' ) || exit;

final class Router {

	/** Páginas que se pueden abrir en la ventana emergente. */
	public const MODALES = array( 'ficha', 'alta', 'solicitudes', 'uniformes' );

	/** La pide la ventana emergente del área (assets/js/area.js). */
	public static function pide_modal(): bool {
		return '1' === ( $_SERVER['HTTP_X_ASDE_MODAL'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	}

	/** La pide el listado de usuarios para trabajar sin recargar (assets/js/usuarios.js): el servidor contesta en JSON. */
	public static function pide_json(): bool {
		return '1' === ( $_SERVER['HTTP_X_ASDE_JSON'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	}

	/** clave de página => [shortcode, vista] */
	private const VIEWS = array(
		'area'      => array( 'asde_area_privada', AreaPrivadaView::class ),
		'alta'      => array( 'asde_alta_usuarios', AltaUsuariosView::class ),
		'usuarios'  => array( 'asde_usuarios', UsuariosView::class ),
		'emails'    => array( 'asde_emails', EmailsView::class ),
		'personas'  => array( 'asde_personas', PersonasView::class ),
		'ficha'     => array( 'asde_ficha', FichaView::class ),
		'cambios'   => array( 'asde_cambios', CambiosView::class ),
		'informes'  => array( 'asde_informes', InformesView::class ),
		'tesoreria' => array( 'asde_tesoreria', TesoreriaView::class ),
		'familias'  => array( 'asde_familias', FamiliasView::class ),
		'cuotas'    => array( 'asde_cuotas', CuotasView::class ),
		'federacion' => array( 'asde_coste_federativo', FederacionView::class ),
		'banco'     => array( 'asde_banco', BancoView::class ),
		'sepa'      => array( 'asde_sepa', SepaView::class ),
		'seguridad' => array( 'asde_seguridad', SeguridadView::class ),
		'acceso'    => array( 'asde_acceso', AccesoView::class ),
		'activar'   => array( 'asde_activar_cuenta', ActivarCuentaView::class ),
		'recuperar' => array( 'asde_recuperar', RecuperarView::class ),
		'solicitar' => array( 'asde_solicitar', SolicitarView::class ),
		'solicitudes' => array( 'asde_solicitudes', SolicitudesView::class ),
		'uniforme'         => array( 'asde_uniforme', UniformeView::class ),
		'uniformes'        => array( 'asde_uniformes', UniformesGestionView::class ),
		'uniformes_config' => array( 'asde_uniformes_config', UniformesConfigView::class ),
		'pagos'            => array( 'asde_mis_pagos', MisPagosView::class ),
	);

	public function register(): void {
		foreach ( self::VIEWS as list( $shortcode, $class ) ) {
			add_shortcode( $shortcode, static fn( $atts = array(), $content = '' ) => ( new $class() )->render( (string) $content ) );
		}
		add_action( 'template_redirect', array( $this, 'template_redirect' ), 5 );
		add_action( 'wp_enqueue_scripts', array( $this, 'assets' ) );
		add_filter( 'wp_robots', array( $this, 'robots' ) );
		add_filter( 'body_class', array( $this, 'body_class' ) );
		// Idioma de cada persona (v0.15): atributo lang del área y selector en las pantallas públicas.
		add_filter( 'do_shortcode_tag', array( $this, 'marcar_idioma' ), 10, 2 );
		add_filter( 'gettext', array( $this, 'traducir_gettext' ), 10, 3 );
		add_action( 'wp_head', array( $this, 'cabecera_guardada' ), 1 );
		// Al pasar a Enfold, las páginas del área reciben sus ajustes (ancho completo, sin barra de título).
		add_action( 'after_switch_theme', static fn() => Tema::ajustar_paginas( array_values( Pages::ids() ) ) );
	}

	/**
	 * Marca el idioma del área (`lang` en su contenedor, para lectores de pantalla y para el navegador)
	 * y, en las pantallas públicas (acceso, activar cuenta, recuperar, solicitar), pone el selector de idioma.
	 *
	 * @param mixed $salida Lo que ha pintado el shortcode.
	 */
	public function marcar_idioma( $salida, string $etiqueta ) {
		$shortcodes = array_column( self::VIEWS, 0 );
		if ( ! is_string( $salida ) || ! in_array( $etiqueta, $shortcodes, true ) ) {
			return $salida;
		}
		$salida = preg_replace( '/<div class="asde-ap/', '<div lang="' . esc_attr( Idioma::actual() ) . '" class="asde-ap', $salida, 1 ) ?? $salida;
		$publica = array_map( static fn( string $k ) => self::VIEWS[ $k ][0], Pages::PUBLICAS );
		if ( in_array( $etiqueta, $publica, true ) && Idioma::se_puede_elegir() ) {
			$salida = '<div class="asde-ap asde-ap--idiomas" lang="' . esc_attr( Idioma::actual() ) . '">' . TemplateLoader::render( 'comun/idiomas' ) . '</div>' . $salida;
		}
		return $salida;
	}

	/**
	 * Los textos del plugin que ya estaban marcados con __() (dominio asde-area-privada) también salen en el
	 * idioma de la persona, salvo en las pantallas de wp-admin, que siguen en el idioma de WordPress.
	 *
	 * @param mixed $traduccion Lo que ha dado WordPress.
	 */
	public function traducir_gettext( $traduccion, string $original, string $dominio ) {
		if ( 'asde-area-privada' !== $dominio || ( is_admin() && ! wp_doing_ajax() ) ) {
			return $traduccion;
		}
		$t = Traducciones::t( $original );
		return $t !== $original ? $t : $traduccion;
	}
	public function body_class( array $clases ): array {
		if ( null !== Pages::current_key() ) {
			$clases[] = 'asde-ap-pagina';
		}
		return $clases;
	}

	/**
	 * Cabecera del tema plegada (solo con un tema cuya cabecera sabemos plegar, ver Tema): se
	 * aplica antes de pintar la página para que no se vea abrirse y cerrarse (el estado se
	 * guarda en el navegador de cada persona).
	 */
	public function cabecera_guardada(): void {
		$key = Pages::current_key();
		if ( null === $key || in_array( $key, Pages::PUBLICAS, true ) || ! Tema::cabecera_plegable() ) {
			return;
		}
		echo "<script>try{if(localStorage.getItem('asde_cabecera_oculta')==='1'){document.documentElement.classList.add('asde-cabecera-oculta');}}catch(e){}</script>\n";
	}

	public function template_redirect(): void {
		$key = Pages::current_key();
		if ( null === $key ) {
			return;
		}

		// El área privada nunca se cachea (LiteSpeed Cache en producción).
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		do_action( 'litespeed_control_set_nocache', 'asde-area-privada' );
		nocache_headers();

		// Cambio de idioma (?asde_lang=xx con nonce): se guarda y se vuelve a esta página sin el parámetro.
		if ( Idioma::atender_peticion() ) {
			wp_safe_redirect( remove_query_arg( array( Idioma::PARAM, '_wpnonce' ) ) );
			exit;
		}

		// Con la sesión ya iniciada, "Acceso socios" lleva directamente al área.
		if ( 'acceso' === $key && is_user_logged_in() ) {
			wp_safe_redirect( Pages::url( 'area' ) );
			exit;
		}

		if ( ! in_array( $key, Pages::PUBLICAS, true ) ) {
			if ( ! is_user_logged_in() ) {
				auth_redirect(); // Redirige al login y vuelve aquí después.
			}
			// Sin membresía activa (o sin menores a cargo) no se entra: al inicio se explica el motivo.
			if ( ! in_array( $key, Pages::SIN_AMBITO, true ) && ! Ambito::actual()->puede_acceder() ) {
				wp_safe_redirect( Pages::url( 'area' ) );
				exit;
			}
		}

		// «Mostrar / Ocultar nº territorial» (v0.14, preferencia de cada persona, doc 27).
		if ( isset( $_GET['asde_ver_local'] ) && is_user_logged_in() && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ?? '' ) ), 'asde_ver_local' ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			\AsdeBalears\AreaPrivada\Socios\Numeros::fijar_ver_local( '1' === $_GET['asde_ver_local'] ); // phpcs:ignore WordPress.Security
			wp_safe_redirect( remove_query_arg( array( 'asde_ver_local', '_wpnonce' ) ) );
			exit;
		}

		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && isset( $_POST['asde_action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$class = self::VIEWS[ $key ][1];
			( new $class() )->handle_post();
		}

		// Listado de usuarios: la lista entera en JSON, para actualizarla en el navegador.
		if ( 'usuarios' === $key && self::pide_json() && 'GET' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			( new UsuariosView() )->json();
		}

		// Ventana emergente (v0.10): fichas, dar acceso y solicitudes se abren sobre la
		// página en la que se está. Se devuelve solo su contenido, sin el tema.
		if ( self::pide_modal() && in_array( $key, self::MODALES, true ) ) {
			View::en_modal();
			$class = self::VIEWS[ $key ][1];
			header( 'Content-Type: text/html; charset=utf-8' );
			header( 'X-Robots-Tag: noindex, nofollow' );
			echo ( new $class() )->render(); // phpcs:ignore WordPress.Security.EscapeOutput
			exit;
		}

		// Descargas CSV (antes de enviar nada al navegador).
		if ( 'csv' === ( $_GET['formato'] ?? '' ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			if ( 'informes' === $key ) {
				( new InformesView() )->exportar();
			} elseif ( 'tesoreria' === $key ) {
				( new TesoreriaView() )->exportar();
			} elseif ( 'uniformes' === $key ) {
				( new UniformesGestionView() )->exportar();
			}
		}
	}

	public function assets(): void {
		$key = Pages::current_key();
		if ( null === $key ) {
			return;
		}
		wp_enqueue_style( 'asde-area-privada', plugins_url( 'assets/css/area-privada.css', ASDE_AP_FILE ), array(), ASDE_AP_VERSION );
		// Colores elegidos en wp-admin → Grupos Scouts → Colores (después del CSS de siempre, para que mande).
		$colores = Colores::css();
		if ( '' !== $colores ) {
			wp_add_inline_style( 'asde-area-privada', $colores );
		}
		// Textos de los JavaScript en el idioma de la persona (asdeT() y asdeI18n).
		wp_register_script( 'asde-i18n', false, array(), ASDE_AP_VERSION, true );
		wp_add_inline_script( 'asde-i18n', TextosJs::script() );
		if ( 'informes' === $key ) {
			wp_enqueue_script( 'asde-informes', plugins_url( 'assets/js/informes.js', ASDE_AP_FILE ), array( 'asde-i18n' ), ASDE_AP_VERSION, true );
		}
		if ( ! in_array( $key, Pages::PUBLICAS, true ) ) {
			wp_enqueue_script( 'asde-cabecera', plugins_url( 'assets/js/cabecera.js', ASDE_AP_FILE ), array( 'asde-i18n' ), ASDE_AP_VERSION, true );
			// En cualquier página se puede abrir una ficha o «Dar acceso» en la ventana emergente.
			wp_enqueue_script( 'asde-ficha', plugins_url( 'assets/js/ficha.js', ASDE_AP_FILE ), array( 'asde-i18n' ), ASDE_AP_VERSION, true );
			wp_enqueue_script( 'asde-alta', plugins_url( 'assets/js/alta.js', ASDE_AP_FILE ), array( 'asde-i18n' ), ASDE_AP_VERSION, true );
			wp_enqueue_script( 'asde-area', plugins_url( 'assets/js/area.js', ASDE_AP_FILE ), array( 'asde-i18n', 'asde-ficha', 'asde-alta' ), ASDE_AP_VERSION, true );
		}
		if ( in_array( $key, array( 'familias', 'banco' ), true ) ) {
			wp_enqueue_script( 'asde-combobox', plugins_url( 'assets/js/combobox.js', ASDE_AP_FILE ), array( 'asde-i18n' ), ASDE_AP_VERSION, true );
		}
		if ( 'usuarios' === $key ) {
			wp_enqueue_script( 'asde-usuarios', plugins_url( 'assets/js/usuarios.js', ASDE_AP_FILE ), array( 'asde-i18n' ), ASDE_AP_VERSION, true );
		}
		if ( 'federacion' === $key ) {
			wp_enqueue_script( 'asde-conceptos', plugins_url( 'assets/js/conceptos.js', ASDE_AP_FILE ), array( 'asde-i18n' ), ASDE_AP_VERSION, true );
		}
		if ( 'uniforme' === $key ) {
			wp_enqueue_script( 'asde-uniforme', plugins_url( 'assets/js/uniforme.js', ASDE_AP_FILE ), array( 'asde-i18n' ), ASDE_AP_VERSION, true );
		}
		// Monitor de la corrección de números en segundo plano (si hay una tarea que ver).
		if ( ! in_array( $key, Pages::PUBLICAS, true ) && TareaNumeracion::para_usuario() ) {
			wp_enqueue_script( 'asde-tarea', plugins_url( 'assets/js/tarea.js', ASDE_AP_FILE ), array( 'asde-i18n' ), ASDE_AP_VERSION, true );
		}
	}

	public function robots( array $robots ): array {
		if ( null !== Pages::current_key() ) {
			$robots['noindex']  = true;
			$robots['nofollow'] = true;
		}
		return $robots;
	}
}
