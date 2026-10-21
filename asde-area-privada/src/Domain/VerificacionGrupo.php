<?php
namespace AsdeBalears\AreaPrivada\Domain;

use AsdeBalears\AreaPrivada\Crm\CrmException;
use AsdeBalears\AreaPrivada\Crm\Settings;
use AsdeBalears\AreaPrivada\Crm\SinergiaV41Client;

defined( 'ABSPATH' ) || exit;

/**
 * Comprueba la credencial del CRM que escribe un grupo al darse de alta (v0.50, doc 50 fase 5): que entra y que
 * ve el Grupo Scout con ese nº. No guarda nada: devuelve el resultado y el id del grupo de seguridad en el CRM.
 * Es la misma comprobación que `Grupos::verificar()`, pero para una credencial que todavía no es de ningún grupo.
 */
final class VerificacionGrupo {

	/**
	 * @return array{ok:bool, mensaje:string, crm_id:string}
	 */
	public static function comprobar( string $numero, string $usuario, string $clave ): array {
		$fallo = static fn( string $m ): array => array(
			'ok'      => false,
			'mensaje' => $m,
			'crm_id'  => '',
		);
		if ( null === Grupos::normalizar_numero( $numero ) || '' === trim( $usuario ) || '' === $clave ) {
			return $fallo( asde_t( 'Escribe el nº de grupo, el usuario y la contraseña del CRM.' ) );
		}
		$url = Settings::url();
		if ( '' === $url ) {
			return $fallo( asde_t( 'Ahora mismo no se puede comprobar el acceso. Inténtalo más tarde.' ) );
		}
		/** Las pruebas automáticas ponen aquí un cliente simulado. @var object|null $cliente */
		$cliente = apply_filters( 'asde_ap_alta_grupo_cliente', null, $url, $usuario, $clave );
		$cliente = $cliente ?: new SinergiaV41Client( $url, $usuario, $clave );
		try {
			$ping = $cliente->ping();
			if ( ! $ping->ok ) {
				return $fallo( asde_t( 'El CRM no acepta ese usuario y contraseña.' ) );
			}
			$filas = $cliente->list_entries( 'SecurityGroups', '', array( 'id', 'name', 'numgrupo_c', 'tipoasociacion_c' ), array(), 0, 200 )['entries'];
		} catch ( CrmException $e ) {
			return $fallo( asde_t( 'No se ha podido comprobar el acceso con el CRM. Inténtalo más tarde.' ) );
		}
		$num = (int) $numero;
		foreach ( $filas as $f ) {
			if ( 'GS' === ( $f['fields']['tipoasociacion_c'] ?? '' ) && (int) preg_replace( '/\D/', '', (string) ( $f['fields']['numgrupo_c'] ?? '' ) ) === $num ) {
				return array(
					'ok'      => true,
					'mensaje' => asde_t( 'Acceso comprobado: el usuario ve el grupo en el CRM.' ),
					'crm_id'  => (string) $f['id'],
				);
			}
		}
		return $fallo( asde_t( 'El usuario entra, pero no ve ese Grupo Scout en el CRM. Revisa el nº de grupo.' ) );
	}
}
