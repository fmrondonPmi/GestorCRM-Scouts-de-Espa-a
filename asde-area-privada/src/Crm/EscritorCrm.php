<?php
namespace AsdeBalears\AreaPrivada\Crm;

defined( 'ABSPATH' ) || exit;

/**
 * Lo mínimo para escribir en el CRM y comprobar lo escrito. Lo implementa
 * SinergiaV41Client; en las pruebas se usa un doble que no toca el CRM real.
 * Solo se usa a través de Crm\Escritura (permisos, grupo y auditoría).
 */
interface EscritorCrm {

	/** Guarda campos de un registro existente. @return string Id del registro. */
	public function set_entry( string $module, string $id, array $values ): string;

	public function get_entry( string $module, string $id, array $fields, array $links = array() ): ?array;
}
