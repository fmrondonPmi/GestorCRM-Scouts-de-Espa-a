<?php
namespace AsdeBalears\AreaPrivada\Frontend;

use AsdeBalears\AreaPrivada\Auth\Ambito;
use AsdeBalears\AreaPrivada\Frontend\Views\AccesoView;
use AsdeBalears\AreaPrivada\Frontend\Views\ActivarCuentaView;
use AsdeBalears\AreaPrivada\Frontend\Views\AltaScoutersView;
use AsdeBalears\AreaPrivada\Frontend\Views\EventosConfigView;
use AsdeBalears\AreaPrivada\Frontend\Views\EventosView;
use AsdeBalears\AreaPrivada\Frontend\Views\InscripcionEsperaView;
use AsdeBalears\AreaPrivada\Frontend\Views\TiendaPublicaView;
use AsdeBalears\AreaPrivada\Frontend\Views\MisEventosView;
use AsdeBalears\AreaPrivada\Frontend\Views\RecetasView;
use AsdeBalears\AreaPrivada\Frontend\Views\ListaEsperaView;
use AsdeBalears\AreaPrivada\Frontend\Views\AltaUsuariosView;
use AsdeBalears\AreaPrivada\Frontend\Views\AreaPrivadaView;
use AsdeBalears\AreaPrivada\Frontend\Views\AyudaView;
use AsdeBalears\AreaPrivada\Frontend\Views\BancoView;
use AsdeBalears\AreaPrivada\Frontend\Views\CambiosView;
use AsdeBalears\AreaPrivada\Frontend\Views\FichaView;
use AsdeBalears\AreaPrivada\Frontend\Views\CuotasView;
use AsdeBalears\AreaPrivada\Frontend\Views\EmailsView;
use AsdeBalears\AreaPrivada\Frontend\Views\FamiliasView;
use AsdeBalears\AreaPrivada\Frontend\Views\FederacionView;
use AsdeBalears\AreaPrivada\Frontend\Views\InformesView;
use AsdeBalears\AreaPrivada\Frontend\Views\InventarioComprasView;
use AsdeBalears\AreaPrivada\Frontend\Views\InventarioTiposView;
use AsdeBalears\AreaPrivada\Frontend\Views\InventarioView;
use AsdeBalears\AreaPrivada\Frontend\Views\MisPagosView;
use AsdeBalears\AreaPrivada\Frontend\Views\TesoreriaView;
use AsdeBalears\AreaPrivada\Frontend\Views\TiendaPedidosView;
use AsdeBalears\AreaPrivada\Frontend\Views\TiendaProductosView;
use AsdeBalears\AreaPrivada\Frontend\Views\TiendaConfigView;
use AsdeBalears\AreaPrivada\Frontend\Views\TiendaGestionView;
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
	public const MODALES = array( 'ficha', 'alta', 'solicitudes', 'tienda_gestion', 'inventario', 'inventario_tipos', 'inventario_compras', 'banco', 'tesoreria', 'personas', 'cambios' );

	/** La pide la ventana emergente del área (assets/js/area.js). */
	public static function pide_modal(): bool {
		return isset( $_SERVER['HTTP_X_ASDE_MODAL'] ) && '1' === sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_ASDE_MODAL'] ) );
	}

	/** La pide el listado de usuarios para trabajar sin recargar (assets/js/usuarios.js): el servidor contesta en JSON. */
	public static function pide_json(): bool {
		return isset( $_SERVER['HTTP_X_ASDE_JSON'] ) && '1' === sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_ASDE_JSON'] ) );
	}

	/** La vista de una página o, si se pide y se ha entrado (v0.26), su ayuda. */
	private static function vista( string $key, string $class ): View {
		return Ayuda::pedida() && is_user_logged_in() && ! in_array( $key, Pages::PUBLICAS, true ) ? new AyudaView( $key ) : new $class();
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
		'tienda_pedidos'         => array( 'asde_tienda_pedidos', TiendaPedidosView::class ),
		'tienda_gestion'        => array( 'asde_tienda_gestion', TiendaGestionView::class ),
		'tienda_config' => array( 'asde_tienda_config', TiendaConfigView::class ),
		'tienda_productos' => array( 'asde_tienda_productos', TiendaProductosView::class ),
		'pagos'            => array( 'asde_mis_pagos', MisPagosView::class ),
		'alta_scouters'    => array( 'asde_alta_scouters', AltaScoutersView::class ),
		// v0.19: inventario de material y listas de compra (doc 32).
		'inventario'         => array( 'asde_inventario', InventarioView::class ),
		'inventario_tipos'   => array( 'asde_inventario_tipos', InventarioTiposView::class ),
		'inventario_compras' => array( 'asde_inventario_compras', InventarioComprasView::class ),
		'lista_espera'       => array( 'asde_lista_espera', ListaEsperaView::class ),
		'inscripcion_espera' => array( 'asde_inscripcion_espera', InscripcionEsperaView::class ),
		'tienda_publica'     => array( 'asde_tienda_publica', TiendaPublicaView::class ),
		// v0.35: eventos (doc 43).
		'eventos'            => array( 'asde_eventos', EventosView::class ),
		'eventos_config'     => array( 'asde_eventos_config', EventosConfigView::class ),
		'eventos_recetas'    => array( 'asde_eventos_recetas', RecetasView::class ),
		'mis_eventos'        => array( 'asde_mis_eventos', MisEventosView::class ),
	);

	public function register(): void {
		foreach ( self::VIEWS as $key => list( $shortcode, $class ) ) {
			add_shortcode( $shortcode, static fn( $atts = array(), $content = '' ) => self::vista( (string) $key, $class )->render( (string) $content ) );
		}
		add_action( 'template_redirect', array( $this, 'template_redirect' ), 5 );
		add_action( 'wp_enqueue_scripts', array( $this, 'assets' ) );
		add_filter( 'wp_robots', array( $this, 'robots' ) );
		add_filter( 'body_class', array( $this, 'body_class' ) );
		// Idioma de cada persona (v0.15): atributo lang del área y selector en las pantallas públicas.
		add_filter( 'do_shortcode_tag', array( $this, 'marcar_idioma' ), 10, 2 );
		add_filter( 'gettext', array( $this, 'traducir_gettext' ), 10, 3 );
		add_action( 'wp_enqueue_scripts', array( $this, 'cabecera_guardada' ), 1 );
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
	 * guarda en el navegador de cada persona). v0.31: con wp_add_inline_script en la cabecera
	 * (WordPress pide no imprimir <script> a mano).
	 */
	public function cabecera_guardada(): void {
		$key = Pages::current_key();
		if ( null === $key || in_array( $key, Pages::PUBLICAS, true ) || ! Tema::cabecera_plegable() ) {
			return;
		}
		wp_register_script( 'asde-cabecera-guardada', false, array(), ASDE_AP_VERSION, false );
		wp_enqueue_script( 'asde-cabecera-guardada' );
		wp_add_inline_script( 'asde-cabecera-guardada', "try{if(localStorage.getItem('asde_cabecera_oculta')==='1'){document.documentElement.classList.add('asde-cabecera-oculta');}}catch(e){}" );
	}

	public function template_redirect(): void {
		$key = Pages::current_key();
		if ( null === $key ) {
			return;
		}

		// El área privada nunca se cachea (LiteSpeed Cache en producción).
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- constante que usan los plugins de caché para no guardar la página
		}
		do_action( 'litespeed_control_set_nocache', 'asde-area-privada' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- gancho de LiteSpeed Cache: pide no guardar esta página
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
			\AsdeBalears\AreaPrivada\Socios\Numeros::fijar_ver_local( '1' === sanitize_text_field( wp_unslash( $_GET['asde_ver_local'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification -- comprobado arriba
			wp_safe_redirect( remove_query_arg( array( 'asde_ver_local', '_wpnonce' ) ) );
			exit;
		}

		if ( 'POST' === strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) && isset( $_POST['asde_action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$class = self::VIEWS[ $key ][1];
			( new $class() )->handle_post();
		}

		// Listado de usuarios: la lista entera en JSON, para actualizarla en el navegador.
		if ( 'usuarios' === $key && self::pide_json() && 'GET' === strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) ) {
			( new UsuariosView() )->json();
		}
		// Tipos de material: la lista en JSON, para refrescar el desplegable del formulario sin recargar.
		if ( 'inventario_tipos' === $key && self::pide_json() && 'GET' === strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) ) {
			( new InventarioTiposView() )->json();
		}

		// Ayuda de la página (v0.26) o manual completo del rol (v0.28, ?asde_ayuda=manual), en la ventana emergente.
		if ( self::pide_modal() && Ayuda::pedida() && ! in_array( $key, Pages::PUBLICAS, true ) ) {
			View::en_modal();
			header( 'Content-Type: text/html; charset=utf-8' );
			header( 'X-Robots-Tag: noindex, nofollow' );
			echo ( new AyudaView( $key ) )->render(); // phpcs:ignore WordPress.Security.EscapeOutput
			exit;
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

		// Descargas (antes de enviar nada al navegador). Informes (v0.29): Excel y la versión para imprimir.
		$formato = sanitize_key( wp_unslash( $_GET['formato'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification -- cada descarga comprueba su nonce
		if ( 'informes' === $key && in_array( $formato, InformesView::FORMATOS, true ) ) {
			( new InformesView() )->exportar( $formato );
		}
		if ( 'tesoreria' === $key && in_array( $formato, array( 'xlsx', 'csv' ), true ) ) {
			( new TesoreriaView() )->exportar(); // Siempre Excel (v0.37): el enlace «csv» de antes sigue valiendo.
		}
		if ( 'csv' === $formato && 'eventos' === $key ) {
			( new EventosView() )->exportar_compra(); // v0.41 (B2): la lista de la compra del menú.
		}
		if ( 'xlsx' === $formato && 'tienda_gestion' === $key ) {
			( new TiendaGestionView() )->exportar_informe(); // v0.40 (fase 5): informes de ventas en Excel.
		}
		if ( 'csv' === $formato ) {
			if ( 'tienda_gestion' === $key ) {
				( new TiendaGestionView() )->exportar();
			}
		}
		// Importar material: el CSV de ejemplo y las filas que no se importaron (el de las filas con error comprueba su nonce; el ejemplo no tiene datos).
		if ( 'inventario' === $key ) {
			if ( 'csv-ejemplo' === $formato ) {
				( new InventarioView() )->ejemplo();
			} elseif ( 'csv-errores' === $formato ) {
				( new InventarioView() )->errores_csv();
			}
		}
		// Cargar productos: el CSV de ejemplo y las filas que no se cargaron.
		if ( 'tienda_productos' === $key ) {
			if ( 'csv-ejemplo' === $formato ) {
				( new TiendaProductosView() )->ejemplo();
			} elseif ( 'csv-errores' === $formato ) {
				( new TiendaProductosView() )->errores_csv();
			}
		}
		// PDF de una lista de compra (solo con el visto bueno de Tesorería).
		if ( 'inventario_compras' === $key && 'pdf' === $formato ) {
			( new InventarioComprasView() )->pdf();
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
		if ( 'tesoreria' === $key ) {
			wp_enqueue_script( 'asde-tesoreria', plugins_url( 'assets/js/tesoreria.js', ASDE_AP_FILE ), array( 'asde-i18n' ), ASDE_AP_VERSION, true );
			// v0.25: la ficha de una familia se actualiza sin recargar la página (pagos, conciliar, eliminar).
			wp_enqueue_script( 'asde-parcial', plugins_url( 'assets/js/parcial.js', ASDE_AP_FILE ), array( 'asde-i18n', 'asde-area', 'asde-tesoreria' ), ASDE_AP_VERSION, true );
		}
		// v0.20: zonas que se actualizan sin recargar (listado del banco).
		if ( 'banco' === $key ) {
			wp_enqueue_script( 'asde-parcial', plugins_url( 'assets/js/parcial.js', ASDE_AP_FILE ), array( 'asde-i18n', 'asde-combobox', 'asde-area' ), ASDE_AP_VERSION, true );
			wp_enqueue_script( 'asde-banco', plugins_url( 'assets/js/banco.js', ASDE_AP_FILE ), array( 'asde-i18n', 'asde-area' ), ASDE_AP_VERSION, true );
		}
		// v0.29 Personas; v0.31 todos los listados (petición del usuario): lo que está en una zona
		// data-asde-parcial (la lista) se filtra y se pagina sin recargar la página entera.
		if ( ! in_array( $key, Pages::PUBLICAS, true ) && ! wp_script_is( 'asde-parcial', 'enqueued' ) ) {
			wp_enqueue_script( 'asde-parcial', plugins_url( 'assets/js/parcial.js', ASDE_AP_FILE ), array( 'asde-i18n', 'asde-area' ), ASDE_AP_VERSION, true );
		}
		// v0.25: Usuarios, Dar acceso y Registro de emails son hojas de la sección «Usuarios»:
		// desde cualquiera se abren las otras sin recargar (también el listado de usuarios).
		if ( isset( View::HOJAS_USUARIOS[ $key ] ) ) {
			wp_enqueue_script( 'asde-usuarios', plugins_url( 'assets/js/usuarios.js', ASDE_AP_FILE ), array( 'asde-i18n' ), ASDE_AP_VERSION, true );
			wp_enqueue_script( 'asde-hojas', plugins_url( 'assets/js/hojas.js', ASDE_AP_FILE ), array( 'asde-i18n', 'asde-area', 'asde-usuarios' ), ASDE_AP_VERSION, true );
		}
		if ( 'federacion' === $key ) {
			wp_enqueue_script( 'asde-conceptos', plugins_url( 'assets/js/conceptos.js', ASDE_AP_FILE ), array( 'asde-i18n' ), ASDE_AP_VERSION, true );
		}
		if ( 'tienda_pedidos' === $key ) {
			wp_enqueue_script( 'asde-uniforme', plugins_url( 'assets/js/uniforme.js', ASDE_AP_FILE ), array( 'asde-i18n' ), ASDE_AP_VERSION, true );
		}
		if ( in_array( $key, array( 'inventario', 'inventario_compras' ), true ) ) {
			wp_enqueue_script( 'asde-inventario', plugins_url( 'assets/js/inventario.js', ASDE_AP_FILE ), array( 'asde-i18n', 'asde-area' ), ASDE_AP_VERSION, true );
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
