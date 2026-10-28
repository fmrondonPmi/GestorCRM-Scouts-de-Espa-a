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
			return $fallo( __( 'Enter the group number, the CRM username and the password.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
		}
		$url = Settings::url();
		if ( '' === $url ) {
			return $fallo( __( 'Access cannot be checked right now. Try again later.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
		}
		/** Las pruebas automáticas ponen aquí un cliente simulado. @var object|null $cliente */
		$cliente = apply_filters( 'asde_ap_alta_grupo_cliente', null, $url, $usuario, $clave );
		$cliente = $cliente ?: new SinergiaV41Client( $url, $usuario, $clave );
		try {
			$ping = $cliente->ping();
			if ( ! $ping->ok ) {
				return $fallo( __( 'The CRM does not accept that username and password.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
			}
			$filas = $cliente->list_entries( 'SecurityGroups', '', array( 'id', 'name', 'numgrupo_c', 'tipoasociacion_c' ), array(), 0, 200 )['entries'];
		} catch ( CrmException $e ) {
			return $fallo( __( 'Access could not be checked with the CRM. Try again later.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
		}
		$num = (int) $numero;
		foreach ( $filas as $f ) {
			if ( 'GS' === ( $f['fields']['tipoasociacion_c'] ?? '' ) && (int) preg_replace( '/\D/', '', (string) ( $f['fields']['numgrupo_c'] ?? '' ) ) === $num ) {
				return array(
					'ok'      => true,
					'mensaje' => __( 'Access checked: the user sees the group in the CRM.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
					'crm_id'  => (string) $f['id'],
				);
			}
		}
		return $fallo( __( 'The user signs in but does not see that Scout Group in the CRM. Check the group number.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
	}
}
