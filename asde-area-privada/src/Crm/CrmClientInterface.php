<?php
namespace AsdeBalears\AreaPrivada\Crm;

defined( 'ABSPATH' ) || exit;

/**
 * Cliente de SinergiaCRM.
 *
 * v1 es de SOLO LECTURA: esta interfaz no tiene (ni debe tener) métodos de
 * escritura. Los métodos de lectura (list/get/related) se añadirán al
 * construir el índice local — ver docs/04-integracion-sinergiacrm.md.
 */
interface CrmClientInterface {

	/** Comprueba credenciales y conectividad sin leer datos personales. */
	public function ping(): ConnectionResult;
}
