<?php
namespace AsdeBalears\AreaPrivada\Install;

use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Domain\Grupos;
use AsdeBalears\AreaPrivada\Fichas\Cambios;
use AsdeBalears\AreaPrivada\Frontend\Pages;
use AsdeBalears\AreaPrivada\Frontend\Visibilidad;
use AsdeBalears\AreaPrivada\Informes\InformesTable;
use AsdeBalears\AreaPrivada\Integraciones\Wordfence;
use AsdeBalears\AreaPrivada\Socios\SociosTable;
use AsdeBalears\AreaPrivada\Tesoreria\Banco\BancoTable;
use AsdeBalears\AreaPrivada\Tesoreria\FamiliasTable;
use AsdeBalears\AreaPrivada\Tesoreria\MovimientosTable;
use AsdeBalears\AreaPrivada\Usuarios\RegistroEmails;

defined( 'ABSPATH' ) || exit;

final class Installer {

	private const OPTION_VERSION = 'asde_ap_version';

	/** Cerrojo de la instalación (v0.19): mientras existe, otra petición no instala. */
	public const OPTION_CERROJO = 'asde_ap_instalando';

	/** Un cerrojo más antiguo se da por abandonado (la petición que instalaba murió a medias). */
	private const CERROJO_SEGUNDOS = 300;

	/** Se ejecuta en `init`: aplica la instalación cuando cambia la versión del plugin. */
	public static function maybe_upgrade(): void {
		if ( get_option( self::OPTION_VERSION ) === ASDE_AP_VERSION ) {
			return;
		}
		// Justo después de actualizar llegan varias peticiones a la vez (visitas, cron, el
		// propio navegador): sin cerrojo instalaban todas y las páginas nuevas salían
		// repetidas (pasó en Local con las del inventario, 2026-10-05). Las demás siguen sin esperar.
		if ( ! self::tomar_cerrojo() ) {
			return;
		}
		try {
			wp_cache_delete( self::OPTION_VERSION, 'options' );
			wp_cache_delete( 'alloptions', 'options' );
			if ( get_option( self::OPTION_VERSION ) !== ASDE_AP_VERSION ) {
				self::install(); // Otra petición pudo terminar entre la primera comprobación y el cerrojo.
			}
		} finally {
			self::soltar_cerrojo();
		}
	}

	/**
	 * Toma el cerrojo con un INSERT IGNORE sobre la clave única de wp_options, que es
	 * atómico (add_option() no lo es: hace INSERT … ON DUPLICATE KEY UPDATE y, con dos
	 * peticiones a la vez, devuelve «hecho» a las dos).
	 */
	public static function tomar_cerrojo(): bool {
		global $wpdb;
		$ahora = time();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- tiene que ser una sola sentencia atómica; no hay función de WordPress que lo haga
		$tomado = (int) $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, %s, 'off')", $wpdb->options, self::OPTION_CERROJO, (string) $ahora ) );
		if ( ! $tomado ) {
			// Abandonado: se toma solo si sigue teniendo el valor viejo (dos peticiones no lo toman a la vez).
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- lectura sin caché: el valor lo acaba de escribir otra petición
			$desde = (int) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM %i WHERE option_name = %s", $wpdb->options, self::OPTION_CERROJO ) );
			if ( $ahora - $desde > self::CERROJO_SEGUNDOS ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- comparar y cambiar en una sola sentencia
				$tomado = (int) $wpdb->query( $wpdb->prepare( "UPDATE %i SET option_value = %s WHERE option_name = %s AND option_value = %s", $wpdb->options, (string) $ahora, self::OPTION_CERROJO, (string) $desde ) );
			}
		}
		wp_cache_delete( self::OPTION_CERROJO, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		return $tomado > 0;
	}

	public static function soltar_cerrojo(): void {
		delete_option( self::OPTION_CERROJO );
	}

	public static function install(): void {
		MigracionTienda::ejecutar(); // v0.39 (doc 48): Uniformes pasa a Tienda.
		Roles::install();
		Logger::install_table();
		SociosTable::install_table();
		InformesTable::install_table();
		FamiliasTable::install_table();
		MovimientosTable::install_table();
		RegistroEmails::install_table();
		BancoTable::install_table();
		Cambios::install_table();
		\AsdeBalears\AreaPrivada\Usuarios\Solicitudes::install_table();
		\AsdeBalears\AreaPrivada\Tienda\PedidosTable::install_table();
		\AsdeBalears\AreaPrivada\Tienda\ProductosTable::install_table(); // v0.39 (doc 48, fase 2): productos y variantes de la tienda.
		\AsdeBalears\AreaPrivada\Sepa\Mandatos::install_table();
		\AsdeBalears\AreaPrivada\Sepa\Remesas::install_table();
		\AsdeBalears\AreaPrivada\Inventario\TiposTable::install_table();
		\AsdeBalears\AreaPrivada\Inventario\MaterialesTable::install_table();
		\AsdeBalears\AreaPrivada\Inventario\ComprasTable::install_table();
		\AsdeBalears\AreaPrivada\Tesoreria\Banco\TiposMovimiento::install_table();
		\AsdeBalears\AreaPrivada\Crm\Operaciones::install_table();
		\AsdeBalears\AreaPrivada\ListaEspera\Inscripciones::install_table();
		// v0.35: eventos (doc 43).
		\AsdeBalears\AreaPrivada\Eventos\EventosTable::install_table();
		\AsdeBalears\AreaPrivada\Eventos\InscripcionesTable::install_table();
		\AsdeBalears\AreaPrivada\Eventos\PartidasTable::install_table();
		\AsdeBalears\AreaPrivada\Eventos\MovimientosTable::install_table();
		// v0.36: las cuentas de los eventos (gastos e ingresos reales, anticipos de caja).
		\AsdeBalears\AreaPrivada\Eventos\GastosTable::install_table();
		\AsdeBalears\AreaPrivada\Eventos\AnticiposTable::install_table();
		Grupos::migrar();
		\AsdeBalears\AreaPrivada\Domain\Organizacion::migrar(); // Instalaciones anteriores: Scouts de Balears, código 7.
		\AsdeBalears\AreaPrivada\Tienda\Sembrado::ejecutar(); // v0.39: la camisa y las insignias de Uniformes, como productos (idempotente).
		Pages::install();
		Visibilidad::asegurar_menu();
		Wordfence::configurar();
		Mantenimiento::programar();
		// v0.10: el email de las cuentas se guarda cifrado; las que ya existían lo guardan en segundo plano.
		if ( \AsdeBalears\AreaPrivada\Usuarios\EmailCuenta::pendientes() && ! wp_next_scheduled( Mantenimiento::HOOK_EMAILS ) ) {
			wp_schedule_single_event( time() + 60, Mantenimiento::HOOK_EMAILS );
		}
		update_option( self::OPTION_VERSION, ASDE_AP_VERSION, false );
	}

	public static function deactivate(): void {
		Mantenimiento::desprogramar();
		wp_clear_scheduled_hook( Mantenimiento::HOOK_EMAILS );
		// Una corrección en segundo plano a medias se queda «en curso» y sigue al reactivar (la mueve el monitor).
		wp_unschedule_hook( \AsdeBalears\AreaPrivada\Socios\TareaNumeracion::HOOK_CRON );
		wp_unschedule_hook( 'asde_diag_sp_cron' ); // Diagnóstico de tareas en segundo plano.
		Visibilidad::ocultar_menu();
	}
}
