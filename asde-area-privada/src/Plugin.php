<?php
namespace AsdeBalears\AreaPrivada;

use AsdeBalears\AreaPrivada\Admin\ColoresPage;
use AsdeBalears\AreaPrivada\Admin\EntornoPage;
use AsdeBalears\AreaPrivada\Admin\IdiomasPage;
use AsdeBalears\AreaPrivada\Admin\OrganizacionPage;
use AsdeBalears\AreaPrivada\Admin\PwaPage;
use AsdeBalears\AreaPrivada\Admin\SettingsPage;
use AsdeBalears\AreaPrivada\Auth\AccessHooks;
use AsdeBalears\AreaPrivada\Crm\Instantanea;
use AsdeBalears\AreaPrivada\Frontend\Router;
use AsdeBalears\AreaPrivada\Frontend\Tabla;
use AsdeBalears\AreaPrivada\Frontend\Visibilidad;
use AsdeBalears\AreaPrivada\Install\Installer;
use AsdeBalears\AreaPrivada\Install\Mantenimiento;
use AsdeBalears\AreaPrivada\Pwa\Pwa;
use AsdeBalears\AreaPrivada\Socios\TareaNumeracion;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	public static function boot(): void {
		// Herramientas → Diagnóstico segundo plano (también se puede subir sola a un hosting).
		require_once ASDE_AP_DIR . 'herramientas/asde-diagnostico-segundo-plano.php';
		add_action( 'init', array( Installer::class, 'maybe_upgrade' ), 20 );
		( new Router() )->register();
		( new Pwa() )->register();
		Visibilidad::register();
		AccessHooks::register();
		Mantenimiento::register();
		TareaNumeracion::register();
		Tabla::register();
		Instantanea::register();

		if ( is_admin() ) {
			( new SettingsPage() )->register();
			( new OrganizacionPage() )->register();
			( new ColoresPage() )->register();
			( new IdiomasPage() )->register();
			( new PwaPage() )->register();
			( new EntornoPage() )->register();
		}
	}
}
