<?php
namespace AsdeBalears\AreaPrivada\Install;

use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Crm\Settings;
use AsdeBalears\AreaPrivada\Domain\Grupos;
use AsdeBalears\AreaPrivada\Domain\Organizacion;
use AsdeBalears\AreaPrivada\Fichas\Cambios;
use AsdeBalears\AreaPrivada\Frontend\Pages;
use AsdeBalears\AreaPrivada\Frontend\Tema;
use AsdeBalears\AreaPrivada\Frontend\Visibilidad;
use AsdeBalears\AreaPrivada\Informes\InformesTable;
use AsdeBalears\AreaPrivada\Integraciones\Wordfence;
use AsdeBalears\AreaPrivada\Socios\SociosTable;
use AsdeBalears\AreaPrivada\Tesoreria\Banco\BancoTable;
use AsdeBalears\AreaPrivada\Tesoreria\FamiliasTable;
use AsdeBalears\AreaPrivada\Tesoreria\MovimientosTable;
use AsdeBalears\AreaPrivada\Usuarios\RegistroEmails;

defined( 'ABSPATH' ) || exit;

/**
 * «Generar el entorno»: deja un WordPress (nuevo o no) listo para el área privada.
 *
 * - Siempre (sin preguntar, porque solo afecta al plugin): tablas, roles, páginas
 *   (crea, recupera o repara), elemento «Acceso socios» del menú, tareas
 *   programadas y configuración de Wordfence si está instalado.
 * - Ajustes generales del sitio, SOLO si se marcan y si siguen con el valor de un
 *   WordPress recién instalado: enlaces permanentes, registro
 *   público, crear el menú principal y la regla «noabort» del .htaccess.
 * - Se puede repetir las veces que haga falta: nunca borra nada ni toca el
 *   contenido de la web. Lo que no se puede automatizar (el grupo y su usuario
 *   del CRM, instalar Wordfence, un SMTP…) se indica como siguiente paso.
 */
final class Entorno {

	public const OK    = 'ok';
	public const AVISO = 'aviso';
	public const FALTA = 'falta';
	public const INFO  = 'info';


	// ---------------------------------------------------------------------
	// Qué se puede cambiar de los ajustes del sitio
	// ---------------------------------------------------------------------

	/**
	 * Ajustes generales que solo se tocan si se marcan, y solo se ofrecen cuando
	 * están como en un WordPress recién instalado.
	 *
	 * @return array<string, array{0:string, 1:string}> clave => [etiqueta, explicación]
	 */
	public static function opciones(): array {
		$op = array();
		if ( '' === (string) get_option( 'permalink_structure' ) ) {
			$op['enlaces'] = array( 'Enlaces permanentes «Nombre de la entrada» (/%postname%/)', 'Ahora son los de por defecto (?page_id=123). Con ellos las direcciones del área quedan legibles (/area-privada/acceso/).' );
		}
		if ( get_option( 'users_can_register' ) ) {
			$op['registro'] = array( 'Desactivar el registro público de usuarios', 'El alta en el área privada es solo por invitación; con el registro abierto, cualquiera puede crearse una cuenta en WordPress.' );
		}
		if ( ! wp_is_block_theme() && '' !== Visibilidad::ubicacion_principal() && ! Visibilidad::menu_principal() ) {
			$op['menu'] = array( 'Crear el menú principal (Inicio + Acceso socios)', 'El tema no tiene ningún menú asignado a su ubicación principal, así que no hay dónde poner «Acceso socios».' );
		}
		if ( self::litespeed() && ! self::tiene_noabort() && self::htaccess_escribible() ) {
			$op['noabort'] = array( 'Añadir la regla «noabort» al .htaccess (LiteSpeed)', 'Para que el servidor no corte las tareas en segundo plano. Se guarda una copia del fichero y, si la web dejara de responder, se deshace sola.' );
		}
		return $op;
	}

	// ---------------------------------------------------------------------
	// Comprobación
	// ---------------------------------------------------------------------

	/**
	 * Estado de cada pieza del entorno.
	 *
	 * @return array<string, array{0:string, 1:string, 2:string, 3:array}> clave => [estado, pieza, detalle, [texto del enlace => url]]
	 */
	public static function comprobar(): array {
		global $wpdb;
		$c = array();

		// Requisitos.
		$req = array();
		if ( version_compare( PHP_VERSION, '8.0', '<' ) ) {
			$req[] = 'PHP ' . PHP_VERSION . ' (hace falta 8.0 o superior)';
		}
		if ( ! function_exists( 'sodium_crypto_secretbox' ) ) {
			$req[] = 'falta la extensión sodium de PHP (cifra las contraseñas del CRM)';
		}
		if ( version_compare( get_bloginfo( 'version' ), '6.4', '<' ) ) {
			$req[] = 'WordPress ' . get_bloginfo( 'version' ) . ' (hace falta 6.4 o superior)';
		}
		$c['requisitos'] = $req
			? array( self::FALTA, 'Requisitos del servidor', implode( '; ', $req ) . '. Consúltalo con el hosting.', array() )
			: array( self::OK, 'Requisitos del servidor', sprintf( 'PHP %s con sodium, WordPress %s.', PHP_VERSION, get_bloginfo( 'version' ) ), array() );

		// Organización federada (nombre en los textos, código del nº de socio, coste federativo).
		$org_url           = \AsdeBalears\AreaPrivada\Admin\OrganizacionPage::url();
		$c['organizacion'] = Organizacion::configurada()
			? array( self::OK, 'Organización federada', sprintf( '%s (código %s en el nº de socio local).', Organizacion::nombre(), Organizacion::codigo() ), array( 'Organización' => $org_url ) )
			: array( self::FALTA, 'Organización federada', 'Sin elegir: hace falta para los textos, el nº de socio local (código de la organización) y el coste federativo.', array( 'Elegirla' => $org_url ) );

		// Tablas.
		$faltan = array();
		foreach ( self::tablas() as $tabla ) {
			if ( $tabla !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $tabla ) ) ) ) { // phpcs:ignore WordPress.DB
				$faltan[] = $tabla;
			}
		}
		$c['tablas'] = $faltan
			? array( self::FALTA, 'Tablas de la base de datos', sprintf( 'Faltan %d de %d: %s.', count( $faltan ), count( self::tablas() ), implode( ', ', $faltan ) ), array() )
			: array( self::OK, 'Tablas de la base de datos', sprintf( 'Las %d tablas del área privada.', count( self::tablas() ) ), array() );

		// Roles.
		$sin_rol = array_filter( Roles::ALL, static fn( $r ) => ! get_role( $r ) );
		$admin   = get_role( 'administrator' );
		$sin_cap = $admin ? array_filter( Roles::all_caps(), static fn( $cap ) => ! $admin->has_cap( $cap ) ) : array();
		$c['roles'] = $sin_rol || $sin_cap
			? array( self::FALTA, 'Roles y permisos', trim( ( $sin_rol ? 'Faltan roles: ' . implode( ', ', array_map( array( Roles::class, 'label' ), $sin_rol ) ) . '. ' : '' ) . ( $sin_cap ? 'A los administradores les faltan ' . count( $sin_cap ) . ' permisos del área.' : '' ) ), array() )
			: array( self::OK, 'Roles y permisos', 'Scouter, Secretaría, Coordinación, Tesorería, cuenta familiar y participante; los administradores pueden con todo.', array() );

		// Páginas.
		$p = Pages::revisar();
		$c['paginas'] = $p['problemas']
			? array( self::FALTA, 'Páginas del área privada', sprintf( '%d de %d bien. %s.', $p['bien'], $p['total'], implode( '; ', array_slice( $p['problemas'], 0, 6 ) ) . ( count( $p['problemas'] ) > 6 ? '…' : '' ) ), array() )
			: array( self::OK, 'Páginas del área privada', sprintf( 'Las %d páginas, en %s', $p['total'], Pages::url( 'area' ) ), array( 'Ver el área privada' => Pages::url( 'area' ) ) );

		// Menú.
		if ( Visibilidad::menu_visible() ) {
			$c['menu'] = array( self::OK, '«Acceso socios» en el menú', 'Está en el menú principal (con la sesión iniciada pasa a «Área privada»).', array( 'Menús' => admin_url( 'nav-menus.php' ) ) );
		} elseif ( wp_is_block_theme() ) {
			$c['menu'] = array( self::INFO, '«Acceso socios» en el menú', 'El tema es de bloques y sus menús se editan en el Editor del sitio: añade a la navegación un enlace a la página «Acceso socios» (' . Pages::url( 'acceso' ) . ').', array( 'Editor del sitio' => admin_url( 'site-editor.php' ) ) );
		} elseif ( '' === Visibilidad::ubicacion_principal() ) {
			$c['menu'] = array( self::AVISO, '«Acceso socios» en el menú', 'El tema no tiene ubicaciones de menú: pon un enlace a ' . Pages::url( 'acceso' ) . ' donde se vea (por ejemplo, en un widget).', array() );
		} elseif ( ! Visibilidad::menu_principal() ) {
			$c['menu'] = array( self::FALTA, '«Acceso socios» en el menú', 'El tema no tiene ningún menú asignado: márcalo abajo para crear el menú principal con «Acceso socios».', array( 'Menús' => admin_url( 'nav-menus.php' ) ) );
		} else {
			$c['menu'] = array( self::FALTA, '«Acceso socios» en el menú', 'No está en el menú principal: se añade al generar el entorno.', array( 'Menús' => admin_url( 'nav-menus.php' ) ) );
		}

		// Tareas programadas.
		$c['cron'] = wp_next_scheduled( Mantenimiento::HOOK )
			? array( self::OK, 'Tarea diaria de mantenimiento', 'Programada (números de socio, cuentas familiares al cumplir 18…).', array() )
			: array( self::FALTA, 'Tarea diaria de mantenimiento', 'No está programada.', array() );

		// Wordfence (verificación en dos pasos).
		if ( Wordfence::disponible() ) {
			$c['wordfence'] = (int) get_option( 'asde_ap_wordfence_config' ) >= 2
				? array( self::OK, 'Wordfence (verificación en dos pasos)', 'Obligatoria para Scouter, Secretaría, Coordinación y Tesorería; opcional para familias y participantes.', array() )
				: array( self::FALTA, 'Wordfence (verificación en dos pasos)', 'Instalado, pero sin la configuración del área privada.', array() );
		} elseif ( file_exists( WP_PLUGIN_DIR . '/wordfence/wordfence.php' ) ) {
			$c['wordfence'] = array( self::AVISO, 'Wordfence (verificación en dos pasos)', 'Está instalado pero desactivado. Actívalo y vuelve a generar el entorno.', array( 'Plugins' => admin_url( 'plugins.php' ) ) );
		} else {
			$c['wordfence'] = array( self::AVISO, 'Wordfence (verificación en dos pasos)', 'No está instalado. El área privada funciona sin él, pero sin verificación en dos pasos ni cortafuegos. Instálalo, actívalo y vuelve a generar el entorno.', array( 'Instalar Wordfence' => admin_url( 'plugin-install.php?s=wordfence&tab=search&type=term' ) ) );
		}

		// Ajustes generales.
		$c['enlaces'] = '' !== (string) get_option( 'permalink_structure' )
			? array( self::OK, 'Enlaces permanentes', 'Direcciones legibles (' . get_option( 'permalink_structure' ) . ').', array() )
			: array( self::FALTA, 'Enlaces permanentes', 'Son los de por defecto (?page_id=123): funciona, pero las direcciones no son legibles.', array( 'Ajustes → Enlaces permanentes' => admin_url( 'options-permalink.php' ) ) );
		$c['registro'] = get_option( 'users_can_register' )
			? array( self::FALTA, 'Registro público de usuarios', 'Está abierto: cualquiera puede crearse una cuenta en WordPress. El área privada es solo por invitación.', array( 'Ajustes → Generales' => admin_url( 'options-general.php' ) ) )
			: array( self::OK, 'Registro público de usuarios', 'Cerrado (el alta es solo por invitación).', array() );
		$c['idioma'] = str_starts_with( get_locale(), 'es' )
			? array( self::OK, 'Idioma de WordPress', get_locale() . '.', array() )
			: array( self::INFO, 'Idioma de WordPress', 'Está en ' . get_locale() . '. El área privada está en español, pero los textos de WordPress (y de Wordfence) saldrán en ese idioma.', array( 'Ajustes → Generales' => admin_url( 'options-general.php' ) ) );
		$c['https'] = str_starts_with( home_url(), 'https://' )
			? array( self::OK, 'HTTPS', 'La web va cifrada.', array() )
			: array( self::AVISO, 'HTTPS', 'La web no usa HTTPS: las contraseñas viajarían sin cifrar. (En un entorno local es normal.)', array() );

		// Email.
		$smtp = self::smtp();
		$c['email'] = '' !== $smtp
			? array( self::OK, 'Envío de emails', 'Configurado: ' . $smtp . '. Envíate un email de prueba (abajo) para comprobarlo.', array() )
			: array( self::AVISO, 'Envío de emails', 'Las invitaciones y la recuperación de contraseña saldrían con el correo del servidor, que suele acabar en spam. Recomendado: un plugin SMTP (p. ej. WP Mail SMTP) con una cuenta de correo del grupo.', array( 'Buscar un plugin SMTP' => admin_url( 'plugin-install.php?s=smtp&tab=search&type=term' ) ) );

		// Seguridad del cifrado.
		$c['clave'] = defined( 'ASDE_AP_ENCRYPTION_KEY' ) && '' !== (string) ASDE_AP_ENCRYPTION_KEY
			? array( self::OK, 'Clave de cifrado propia', 'Definida en wp-config.php (ASDE_AP_ENCRYPTION_KEY).', array() )
			: array( self::INFO, 'Clave de cifrado propia', 'Las contraseñas del CRM se cifran con las «salts» de WordPress. Se recomienda una clave propia en wp-config.php (ASDE_AP_ENCRYPTION_KEY) antes de dar de alta los grupos: si se añade después, hay que volver a escribir sus contraseñas.', array() );

		// CRM y grupos (no se pueden generar: hacen falta las credenciales).
		$grupos    = Grupos::todos();
		$ajustes   = admin_url( 'admin.php?page=asde-area-privada' );
		$c['crm']  = '' !== Settings::url()
			? array( self::OK, 'Dirección del CRM', Settings::url() . ( Settings::url_from_constant() ? ' (fijada en wp-config.php).' : '.' ), array() )
			: array( self::FALTA, 'Dirección del CRM', 'Falta la dirección de SinergiaCRM (la misma para todos los grupos).', array( 'Configurarla' => $ajustes ) );
		$bien      = count( array_filter( $grupos, static fn( $g ) => Grupos::OK === $g['estado'] && '' !== (string) $g['crm_id'] ) );
		$c['grupos'] = ! $grupos
			? array( self::FALTA, 'Grupos Scouts', 'No hay ninguno: sin grupo nadie puede entrar al área privada. Da de alta el tuyo con su usuario y contraseña del CRM.', array( 'Añadir el grupo' => add_query_arg( 'nuevo', '1', $ajustes ) ) )
			: array( $bien === count( $grupos ) ? self::OK : self::AVISO, 'Grupos Scouts', sprintf( '%d dados de alta; %d con la conexión al CRM comprobada.', count( $grupos ), $bien ), array( 'Grupos' => $ajustes ) );

		// Email de contacto de cada grupo (configuración del grupo): lo ve quien no puede comprobar sus datos al pedir el acceso y lo lleva el aviso de una solicitud rechazada.
		$sin_contacto = array_filter( $grupos, static fn( $g ) => '' === Grupos::email_contacto( $g ) );
		if ( $grupos ) {
			$c['contacto'] = $sin_contacto
				? array( self::AVISO, 'Email de contacto del grupo', sprintf( 'Sin poner en: %s. Es el que ve quien no puede comprobar sus datos al pedir el acceso y el que lleva el aviso de una solicitud rechazada (se pone en la ficha de cada grupo).', implode( ', ', array_map( static fn( $g ) => Grupos::etiqueta( $g ), $sin_contacto ) ) ), array( 'Grupos' => $ajustes ) )
				: array( self::OK, 'Email de contacto del grupo', 'Puesto en todos los grupos.', array( 'Grupos' => $ajustes ) );
		}

		// Piezas de otros plugins (la app móvil añade «pwa»: HTTPS, icono y alcance).
		$c = (array) apply_filters( 'asde_ap_entorno', $c );

		// Tareas en segundo plano.
		$diag = get_option( 'asde_diag_sp' );
		$ver  = is_array( $diag ) && ! empty( $diag['resultado']['global'] ) ? $diag['resultado']['global'] : null;
		$url  = class_exists( '\Asde_Diagnostico_Segundo_Plano', false ) ? \Asde_Diagnostico_Segundo_Plano::url() : admin_url( 'tools.php' );
		if ( self::litespeed() && ! self::tiene_noabort() ) {
			$c['segundo_plano'] = array( self::FALTA, 'Tareas en segundo plano', 'El servidor es LiteSpeed y el .htaccess no tiene «noabort»: cortaría las tareas en segundo plano. ' . ( self::htaccess_escribible() ? 'Márcalo abajo para añadir la regla.' : 'El .htaccess no se puede escribir desde aquí: añade la regla a mano (la muestra el diagnóstico).' ), array( 'Diagnóstico' => $url ) );
		} elseif ( $ver ) {
			$c['segundo_plano'] = array( 'ok' === $ver[0] ? self::OK : self::AVISO, 'Tareas en segundo plano', 'Último diagnóstico: ' . $ver[1], array( 'Volver a comprobar' => $url ) );
		} else {
			$c['segundo_plano'] = array( self::INFO, 'Tareas en segundo plano', 'Sin comprobar todavía. Pasa el diagnóstico (tarda menos de un minuto).', array( 'Diagnóstico' => $url ) );
		}

		// Tema.
		// No es obligatorio ningún tema (v0.15, doc 28): se sugiere el ideal y se explica qué sirve.
		$tema            = Tema::resumen();
		$c['tema']       = array( $tema['ideal'] ? self::OK : self::INFO, $tema['titulo'], $tema['estado'], array( 'Apariencia → Temas' => admin_url( 'themes.php' ) ) );
		$c['tema_consejo'] = array( self::INFO, 'Qué tema conviene', $tema['consejo'], array() );

		return $c;
	}

	// ---------------------------------------------------------------------
	// Generación
	// ---------------------------------------------------------------------

	/**
	 * Crea o repara todo lo del plugin y aplica los ajustes marcados.
	 *
	 * @param array<string, mixed> $marcadas clave de opciones() => true
	 * @return array{hecho:string[], avisos:string[], errores:string[]}
	 */
	public static function generar( array $marcadas ): array {
		$r = array(
			'hecho'   => array(),
			'avisos'  => array(),
			'errores' => array(),
		);
		$ofrecidas = self::opciones(); // Solo se aplica lo que de verdad hacía falta.
		$quiere    = static fn( string $k ) => ! empty( $marcadas[ $k ] ) && isset( $ofrecidas[ $k ] );

		$antes = self::comprobar();

		// 1. Lo del plugin: primero se reparan las páginas (para recuperar, no duplicar,
		// las que están en la papelera) y luego lo mismo que al activarlo.
		foreach ( Pages::reparar() as $linea ) {
			$r['hecho'][] = $linea;
		}
		Installer::install();
		if ( self::FALTA === $antes['tablas'][0] ) {
			$r['hecho'][] = 'Tablas de la base de datos creadas.';
		}
		if ( self::FALTA === $antes['roles'][0] ) {
			$r['hecho'][] = 'Roles y permisos creados.';
		}
		if ( self::FALTA === $antes['cron'][0] && wp_next_scheduled( Mantenimiento::HOOK ) ) {
			$r['hecho'][] = 'Tarea diaria de mantenimiento programada.';
		}
		if ( Wordfence::disponible() && self::FALTA === $antes['wordfence'][0] ) {
			$r[ Wordfence::configurar() || (int) get_option( 'asde_ap_wordfence_config' ) >= 2 ? 'hecho' : 'errores' ][] = 'Wordfence: verificación en dos pasos configurada para los roles del área privada.';
		}

		// 2. Ajustes del sitio marcados.
		if ( $quiere( 'enlaces' ) ) {
			require_once ABSPATH . 'wp-admin/includes/misc.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			global $wp_rewrite;
			$wp_rewrite->set_permalink_structure( '/%postname%/' );
			flush_rewrite_rules( true );
			$r['hecho'][] = 'Enlaces permanentes: «Nombre de la entrada» (/%postname%/).';
		}
		if ( $quiere( 'registro' ) ) {
			update_option( 'users_can_register', 0 );
			$r['hecho'][] = 'Registro público de usuarios desactivado.';
		}
		if ( $quiere( 'menu' ) ) {
			$menu = self::crear_menu();
			if ( is_wp_error( $menu ) ) {
				$r['errores'][] = 'No se ha podido crear el menú principal: ' . $menu->get_error_message();
			} else {
				$r['hecho'][] = 'Menú principal creado y asignado a la ubicación «' . Visibilidad::ubicacion_principal() . '» del tema.';
			}
		}

		// 3. «Acceso socios» en el menú (también si lo habían borrado).
		$tenia_menu = Visibilidad::menu_visible();
		Visibilidad::asegurar_menu( true );
		if ( ! $tenia_menu && Visibilidad::menu_visible() ) {
			$r['hecho'][] = '«Acceso socios» añadido al menú principal.';
		} elseif ( ! Visibilidad::menu_visible() ) {
			$r['avisos'][] = wp_is_block_theme()
				? 'El tema es de bloques: añade «Acceso socios» a la navegación desde el Editor del sitio.'
				: '«Acceso socios» no se ha podido poner en el menú: ' . ( Visibilidad::menu_principal() ? 'revisa Apariencia → Menús.' : 'el tema no tiene ningún menú asignado (márcalo para crearlo).' );
		}

		// 4. «noabort» (LiteSpeed).
		if ( $quiere( 'noabort' ) ) {
			$h = self::poner_noabort();
			$r[ true === $h ? 'hecho' : 'errores' ][] = true === $h ? 'Regla «noabort» añadida al principio del .htaccess (hay una copia del fichero anterior). Pasa el diagnóstico para confirmarlo.' : $h;
		}

		// 5. Lo que queda en manos de una persona.
		$despues = self::comprobar();
		foreach ( array( 'organizacion', 'crm', 'grupos', 'contacto', 'wordfence', 'email', 'requisitos' ) as $k ) {
			if ( isset( $despues[ $k ] ) && in_array( $despues[ $k ][0], array( self::FALTA, self::AVISO ), true ) ) {
				$r['avisos'][] = $despues[ $k ][1] . ': ' . $despues[ $k ][2];
			}
		}
		if ( ! $r['hecho'] && ! $r['errores'] ) {
			$r['hecho'][] = 'Todo lo del área privada ya estaba en orden: no ha hecho falta cambiar nada.';
		}
		Logger::log(
			'entorno_generado',
			'sitio',
			'',
			array(
				'cambios' => count( $r['hecho'] ),
				'errores' => count( $r['errores'] ),
				'ajustes' => array_values( array_filter( array_keys( $ofrecidas ), $quiere ) ),
			)
		);
		return $r;
	}

	/**
	 * Menú «Menú principal» con «Inicio», asignado a la ubicación principal del
	 * tema («Acceso socios» lo añade después Visibilidad).
	 *
	 * @return int|\WP_Error
	 */
	private static function crear_menu() {
		$ubicacion = Visibilidad::ubicacion_principal();
		if ( '' === $ubicacion ) {
			return new \WP_Error( 'asde_menu', 'el tema no tiene ubicaciones de menú' );
		}
		$existente = wp_get_nav_menu_object( 'Menú principal' );
		$menu      = $existente ? (int) $existente->term_id : wp_create_nav_menu( 'Menú principal' );
		if ( is_wp_error( $menu ) ) {
			return $menu;
		}
		if ( ! $existente ) {
			wp_update_nav_menu_item(
				$menu,
				0,
				array(
					'menu-item-title'  => 'Inicio',
					'menu-item-url'    => home_url( '/' ),
					'menu-item-type'   => 'custom',
					'menu-item-status' => 'publish',
				)
			);
		}
		$ubicaciones               = get_nav_menu_locations();
		$ubicaciones[ $ubicacion ] = $menu;
		set_theme_mod( 'nav_menu_locations', $ubicaciones );
		return $menu;
	}

	/**
	 * Añade la regla «noabort» al principio del .htaccess, con copia del fichero.
	 * Si después la web responde con error, se deshace.
	 *
	 * @return true|string true o el motivo del error.
	 */
	public static function poner_noabort() {
		if ( self::tiene_noabort() ) {
			return true;
		}
		if ( ! self::htaccess_escribible() ) {
			return 'El .htaccess no se puede escribir desde WordPress: añade la regla a mano (la muestra el diagnóstico).';
		}
		$fichero = self::ruta_htaccess();
		$existe  = file_exists( $fichero );
		$actual  = $existe ? (string) file_get_contents( $fichero ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions
		$copia   = $fichero . '.asde-copia-' . gmdate( 'Ymd-His' );
		if ( $existe && ! copy( $fichero, $copia ) ) {
			return 'No se ha podido guardar la copia del .htaccess: no se ha tocado.';
		}
		$bloque = class_exists( '\Asde_Diagnostico_Segundo_Plano', false ) ? \Asde_Diagnostico_Segundo_Plano::htaccess() : '';
		if ( '' === $bloque || false === file_put_contents( $fichero, $bloque . "\n\n" . $actual, LOCK_EX ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
			return 'No se ha podido escribir el .htaccess.';
		}
		// Si la web deja de responder (error 500), se vuelve a dejar como estaba.
		$r = wp_remote_get(
			home_url( '/' ),
			array(
				'timeout'   => 15,
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
			)
		);
		if ( ! is_wp_error( $r ) && (int) wp_remote_retrieve_response_code( $r ) >= 500 ) {
			$existe ? copy( $copia, $fichero ) : wp_delete_file( $fichero );
			return 'Con la regla, la web respondía con error ' . (int) wp_remote_retrieve_response_code( $r ) . ': se ha deshecho. Consúltalo con el hosting.';
		}
		return true;
	}

	// ---------------------------------------------------------------------
	// Ayudas
	// ---------------------------------------------------------------------

	/** @return string[] */
	public static function tablas(): array {
		return array(
			Logger::table(),
			SociosTable::table(),
			InformesTable::table(),
			FamiliasTable::table(),
			FamiliasTable::table_miembros(),
			MovimientosTable::table(),
			RegistroEmails::table(),
			BancoTable::table(),
			BancoTable::table_lotes(),
			Cambios::table(),
			\AsdeBalears\AreaPrivada\Usuarios\Solicitudes::table(),
			\AsdeBalears\AreaPrivada\Uniformes\PedidosTable::table(),
			\AsdeBalears\AreaPrivada\Sepa\Mandatos::table(),
			\AsdeBalears\AreaPrivada\Sepa\Remesas::table(),
		);
	}

	/** Plugin SMTP o configuración propia del envío (lo que hacen casi todos: phpmailer_init). */
	private static function smtp(): string {
		$conocidos = array(
			'wp-mail-smtp/wp_mail_smtp.php'       => 'WP Mail SMTP',
			'fluent-smtp/fluent-smtp.php'         => 'FluentSMTP',
			'post-smtp/postman-smtp.php'          => 'Post SMTP',
			'easy-wp-smtp/easy-wp-smtp.php'       => 'Easy WP SMTP',
			'wp-smtp/wp-smtp.php'                 => 'WP SMTP',
			'smtp-mailer/main.php'                => 'SMTP Mailer',
			'mailpoet/mailpoet.php'               => 'MailPoet',
			'suremails/suremails.php'             => 'SureMail',
		);
		$activos = (array) get_option( 'active_plugins', array() );
		foreach ( $conocidos as $fichero => $nombre ) {
			if ( in_array( $fichero, $activos, true ) ) {
				return $nombre;
			}
		}
		if ( has_action( 'phpmailer_init' ) || has_filter( 'pre_wp_mail' ) ) {
			return 'configuración propia del envío (phpmailer_init)';
		}
		// Entornos locales (Local, etc.): PHP manda el correo a Mailpit/MailHog.
		$sendmail = strtolower( (string) ini_get( 'sendmail_path' ) );
		$puerto   = (string) ini_get( 'smtp_port' );
		if ( str_contains( $sendmail, 'mailpit' ) || str_contains( $sendmail, 'mailhog' ) || ( 'Windows' === PHP_OS_FAMILY && in_array( $puerto, array( '1025', '10007' ), true ) ) ) {
			return 'Mailpit de un entorno local (los emails se quedan ahí y no salen a Internet)';
		}
		try {
			$origen = ( new \ReflectionFunction( 'wp_mail' ) )->getFileName();
			if ( $origen && ! str_ends_with( wp_normalize_path( $origen ), '/wp-includes/pluggable.php' ) ) {
				return 'un plugin que sustituye el envío de WordPress';
			}
		} catch ( \ReflectionException $e ) {
			return '';
		}
		return '';
	}

	public static function litespeed(): bool {
		$software = isset( $_SERVER['SERVER_SOFTWARE'] ) ? (string) $_SERVER['SERVER_SOFTWARE'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		return false !== stripos( $software, 'litespeed' ) || 'litespeed' === PHP_SAPI || isset( $_SERVER['LSWS_EDITION'] );
	}

	private static function ruta_htaccess(): string {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		return get_home_path() . '.htaccess';
	}

	private static function tiene_noabort(): bool {
		$fichero = self::ruta_htaccess();
		return is_readable( $fichero ) && false !== stripos( (string) file_get_contents( $fichero ), 'noabort' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}

	private static function htaccess_escribible(): bool {
		$fichero = self::ruta_htaccess();
		return file_exists( $fichero ) ? is_writable( $fichero ) : is_writable( dirname( $fichero ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}
}
