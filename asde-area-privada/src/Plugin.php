<?php
namespace AsdeBalears\AreaPrivada;

use AsdeBalears\AreaPrivada\Admin\ColoresPage;
use AsdeBalears\AreaPrivada\Admin\EntornoPage;
use AsdeBalears\AreaPrivada\Admin\IdiomasPage;
use AsdeBalears\AreaPrivada\Admin\OrganizacionPage;
use AsdeBalears\AreaPrivada\Admin\SettingsPage;
use AsdeBalears\AreaPrivada\Auth\AccessHooks;
use AsdeBalears\AreaPrivada\Crm\Instantanea;
use AsdeBalears\AreaPrivada\Frontend\Router;
use AsdeBalears\AreaPrivada\Frontend\Tabla;
use AsdeBalears\AreaPrivada\Frontend\Visibilidad;
use AsdeBalears\AreaPrivada\Install\AppRetirada;
use AsdeBalears\AreaPrivada\Install\Installer;
use AsdeBalears\AreaPrivada\Install\Mantenimiento;
use AsdeBalears\AreaPrivada\Socios\TareaNumeracion;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	public static function boot(): void {
		// Herramientas → Diagnóstico segundo plano (también se puede subir sola a un hosting).
		require_once ASDE_AP_DIR . 'herramientas/asde-diagnostico-segundo-plano.php';
		add_action( 'init', array( Installer::class, 'maybe_upgrade' ), 20 );
		( new Router() )->register();
		// La app móvil (PWA) es otro plugin desde la 0.18 (asde-area-privada-app); si no está, se retiran las ya instaladas.
		AppRetirada::register();
		Visibilidad::register();
		AccessHooks::register();
		Mantenimiento::register();
		TareaNumeracion::register();
		Tabla::register();
		Instantanea::register();
		\AsdeBalears\AreaPrivada\Domain\Modulos::register(); // v0.20: módulos que se activan o desactivan por grupo.
		\AsdeBalears\AreaPrivada\Eventos\Avisos::register(); // v0.35 (eventos, doc 43): avisos masivos de los eventos (WP-Cron).

		if ( is_admin() ) {
			( new SettingsPage() )->register();
			( new \AsdeBalears\AreaPrivada\Admin\Recursos() )->register(); // v0.31: CSS y JS comunes de wp-admin, encolados.
			( new OrganizacionPage() )->register();
			( new ColoresPage() )->register();
			( new IdiomasPage() )->register();
			( new EntornoPage() )->register();
			( new \AsdeBalears\AreaPrivada\Admin\ModulosPage() )->register();
			( new \AsdeBalears\AreaPrivada\Admin\DuplicadosPage() )->register(); // v0.24: documentos repetidos en el CRM (doc 34, §3.7).
		}
	}
}
