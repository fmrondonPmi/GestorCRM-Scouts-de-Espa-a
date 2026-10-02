<?php
namespace AsdeBalears\AreaPrivada\Crm;

use AsdeBalears\AreaPrivada\Domain\Grupo;

defined( 'ABSPATH' ) || exit;

/**
 * Definición de los campos de un módulo según el propio CRM (tipo, opciones de
 * los desplegables, longitud, obligatorio, calculado). Sin datos de personas.
 * Se guarda 12 horas por grupo.
 */
final class Metadatos {

	private const TRANSIENT = 'asde_ap_meta_';

	/**
	 * @param string[] $nombres
	 * @return array<string, array{tipo:string, etiqueta:string, opciones:array<string,string>, len:int, requerido:bool, calculado:bool}>
	 */
	public static function campos( string $modulo, array $nombres ): array {
		$grupo = Grupo::actual();
		$clave = self::TRANSIENT . ( $grupo['numero'] ?? '' ) . '_' . md5( $modulo . '|' . implode( ',', $nombres ) );
		$datos = get_transient( $clave );
		if ( is_array( $datos ) ) {
			return $datos;
		}
		$crm = ClientFactory::reader();
		if ( ! $crm ) {
			return array();
		}
		try {
			$raw = (array) ( $crm->module_fields( $modulo, array_values( $nombres ) )['module_fields'] ?? array() );
		} catch ( CrmException $e ) {
			return array();
		}
		$datos = array();
		foreach ( $raw as $nombre => $d ) {
			$opciones = array();
			if ( in_array( $d['type'] ?? '', array( 'enum', 'dynamicenum', 'radioenum', 'multienum' ), true ) ) {
				foreach ( (array) ( $d['options'] ?? array() ) as $o ) {
					if ( is_array( $o ) && isset( $o['name'] ) ) {
						$opciones[ (string) $o['name'] ] = html_entity_decode( (string) $o['value'], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
					}
				}
			}
			$datos[ (string) $nombre ] = array(
				'tipo'      => (string) ( $d['type'] ?? '' ),
				'etiqueta'  => rtrim( html_entity_decode( (string) ( $d['label'] ?? $nombre ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ), ': ' ),
				'opciones'  => $opciones,
				'len'       => (int) ( $d['len'] ?? 0 ),
				'requerido' => ! empty( $d['required'] ) && 'false' !== $d['required'],
				'calculado' => ! empty( $d['calculated'] ) && 'false' !== $d['calculated'],
			);
		}
		set_transient( $clave, $datos, 12 * HOUR_IN_SECONDS );
		return $datos;
	}
}
