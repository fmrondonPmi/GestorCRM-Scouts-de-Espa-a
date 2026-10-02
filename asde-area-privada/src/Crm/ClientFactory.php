<?php
namespace AsdeBalears\AreaPrivada\Crm;

use AsdeBalears\AreaPrivada\Domain\Grupo;
use AsdeBalears\AreaPrivada\Domain\Grupos;

defined( 'ABSPATH' ) || exit;

/**
 * Clientes del CRM: la URL es común y las credenciales, de cada grupo.
 */
final class ClientFactory {

	/** @var array<string, SinergiaV41Client|null> nº de grupo => cliente */
	private static array $clientes = array();

	/** Cliente con las credenciales de un grupo; null si falta la URL o las credenciales. */
	public static function para_grupo( string $numero ): ?SinergiaV41Client {
		$url  = Settings::url();
		$cred = Grupos::credenciales( $numero );
		if ( '' === $url || ! $cred || '' === $cred['usuario'] || null === $cred['clave'] ) {
			return null;
		}
		return new SinergiaV41Client( $url, $cred['usuario'], $cred['clave'] );
	}

	/**
	 * Cliente de lectura del grupo en el que se trabaja. Solo si el grupo está
	 * verificado (se conoce su grupo de seguridad en el CRM), para poder filtrar
	 * siempre por él y no leer nunca fichas de otro grupo.
	 */
	public static function reader(): ?SinergiaV41Client {
		$grupo = Grupo::actual();
		if ( ! $grupo || '' === $grupo['crm_id'] ) {
			return null;
		}
		if ( ! array_key_exists( $grupo['numero'], self::$clientes ) ) {
			self::$clientes[ $grupo['numero'] ] = self::para_grupo( $grupo['numero'] );
		}
		return self::$clientes[ $grupo['numero'] ];
	}

	/** Tras cambiar las credenciales de un grupo. */
	public static function olvidar(): void {
		self::$clientes = array();
	}
}
