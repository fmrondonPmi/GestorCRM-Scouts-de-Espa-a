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

	/** Se ejecuta en `init`: aplica la instalación cuando cambia la versión del plugin. */
	public static function maybe_upgrade(): void {
		if ( get_option( self::OPTION_VERSION ) !== ASDE_AP_VERSION ) {
			self::install();
		}
	}

	public static function install(): void {
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
		\AsdeBalears\AreaPrivada\Uniformes\PedidosTable::install_table();
		\AsdeBalears\AreaPrivada\Sepa\Mandatos::install_table();
		\AsdeBalears\AreaPrivada\Sepa\Remesas::install_table();
		// v0.19: eventos (doc 32).
		\AsdeBalears\AreaPrivada\Eventos\EventosTable::install_table();
		\AsdeBalears\AreaPrivada\Eventos\InscripcionesTable::install_table();
		\AsdeBalears\AreaPrivada\Eventos\PartidasTable::install_table();
		\AsdeBalears\AreaPrivada\Eventos\MovimientosTable::install_table(); // v0.19.1: cobros de los eventos.
		Grupos::migrar();
		\AsdeBalears\AreaPrivada\Domain\Organizacion::migrar(); // Instalaciones anteriores: Scouts de Balears, código 7.
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
