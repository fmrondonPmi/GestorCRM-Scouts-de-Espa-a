<?php
namespace AsdeBalears\AreaPrivada\Crm;

use AsdeBalears\AreaPrivada\Domain\Grupo;

defined( 'ABSPATH' ) || exit;

/**
 * Qué puede hacer en el CRM el usuario de API del grupo en el que se trabaja
 * (ACL por módulo de get_available_modules). Se guarda 1 hora por grupo y se
 * renueva al probar la conexión.
 *
 * El CRM no informa de permisos por campo: los campos que no admita se detectan
 * al releer tras guardar (Crm\Escritura).
 */
final class Permisos {

	private const TRANSIENT = 'asde_ap_permisos_';

	/** @return array<string, array<string, bool>> módulo => acción => permitido */
	public static function modulos( bool $refrescar = false ): array {
		$grupo = Grupo::actual();
		if ( ! $grupo || '' === $grupo['crm_id'] ) {
			return array();
		}
		$clave = self::TRANSIENT . $grupo['numero'];
		$datos = $refrescar ? false : get_transient( $clave );
		if ( is_array( $datos ) ) {
			return $datos;
		}
		$crm = ClientFactory::reader();
		if ( ! $crm ) {
			return array();
		}
		try {
			$datos = $crm->acls();
		} catch ( CrmException $e ) {
			set_transient( $clave, array(), 5 * MINUTE_IN_SECONDS ); // Sin respuesta: se reintenta en 5 minutos.
			return array();
		}
		set_transient( $clave, $datos, HOUR_IN_SECONDS );
		return $datos;
	}

	public static function puede( string $modulo, string $accion = 'edit' ): bool {
		return ! empty( self::modulos()[ $modulo ][ $accion ] );
	}

	public static function olvidar( string $numero ): void {
		delete_transient( self::TRANSIENT . $numero );
	}
}
