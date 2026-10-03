<?php
namespace AsdeBalears\AreaPrivada\Crm;

use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Domain\Grupo;
use AsdeBalears\AreaPrivada\Domain\Persona;

defined( 'ABSPATH' ) || exit;

/**
 * Única puerta de escritura en el CRM (fichas de personas, módulo Contacts).
 *
 * - Solo si el usuario del CRM del grupo tiene permiso de edición en Contacts.
 * - Solo sobre fichas del grupo: la Persona tiene que venir de PersonaRepository,
 *   que filtra por el grupo de seguridad.
 * - Nunca crea ni borra registros; nunca toca los campos de NO_EDITABLES.
 * - Después de guardar relee los campos: lo que el CRM no haya aceptado se
 *   devuelve como "rechazado" (p. ej. por un permiso por campo).
 * - Auditoría con los NOMBRES de los campos (nunca los valores).
 */
final class Escritura {

	public const MODULO = 'Contacts';

	/** Los asigna la federación, los calcula el CRM o tienen su propio flujo (nº local → Numeración). */
	public const NO_EDITABLES = array( 'id', 'numerosocio_c', 'stic_relationship_type_c', 'deleted', 'date_entered', 'date_modified', 'assigned_user_id', 'created_by', 'modified_user_id' );

	private static ?EscritorCrm $pruebas = null;

	/** Solo para las pruebas automáticas: un doble que no toca el CRM real. */
	public static function usar_para_pruebas( ?EscritorCrm $cliente ): void {
		self::$pruebas = $cliente;
	}

	/** ¿Está activo el CRM simulado de las pruebas? */
	public static function en_pruebas(): bool {
		return null !== self::$pruebas;
	}

	public static function disponible(): bool {
		return null !== self::$pruebas || ( null !== ClientFactory::reader() && Permisos::puede( self::MODULO, 'edit' ) );
	}

	/**
	 * @param array<string, string> $valores campo => valor nuevo (solo lo que cambia)
	 * @param bool                  $interno  Permite campos con flujo propio (nº local desde Numeración).
	 * @return array{guardados: string[], rechazados: string[]}|\WP_Error
	 */
	public static function guardar( Persona $p, array $valores, string $motivo, bool $interno = false ) {
		if ( ! $valores ) {
			return array(
				'guardados'  => array(),
				'rechazados' => array(),
			);
		}
		if ( ! self::disponible() ) {
			return new \WP_Error( 'asde_crm_escritura', asde_t( 'El usuario del CRM de tu grupo no tiene permiso para editar fichas.' ) );
		}
		if ( '' === $p->id ) {
			return new \WP_Error( 'asde_crm_escritura', asde_t( 'Ficha sin identificador del CRM.' ) );
		}
		foreach ( array_keys( $valores ) as $campo ) {
			if ( in_array( $campo, self::NO_EDITABLES, true ) || ( 'numsocioantiguo_enficha_c' === $campo && ! $interno ) || ! preg_match( '/^[a-z0-9_]+$/', $campo ) ) {
				return new \WP_Error( 'asde_crm_escritura', sprintf( asde_t( 'El campo %s no se puede editar desde la web.' ), $campo ) );
			}
		}

		$cliente = self::$pruebas ?? ClientFactory::reader();
		try {
			$cliente->set_entry( self::MODULO, $p->id, $valores );
			$leido = $cliente->get_entry( self::MODULO, $p->id, array_keys( $valores ) );
		} catch ( CrmException $e ) {
			Logger::log( 'crm_escritura_error', 'socio', $p->num_socio_asde, array( 'campos' => array_keys( $valores ), 'motivo' => $motivo ) );
			return new \WP_Error( 'asde_crm_escritura', asde_t( 'El CRM no ha guardado los cambios: ' ) . $e->getMessage() );
		}

		$guardados  = array();
		$rechazados = array();
		foreach ( $valores as $campo => $valor ) {
			if ( null !== $leido && self::iguales( (string) ( $leido['fields'][ $campo ] ?? '' ), (string) $valor ) ) {
				$guardados[] = $campo;
			} else {
				$rechazados[] = $campo;
			}
		}
		// La instantánea de las fichas (v0.12.3) ve al momento lo que el CRM ha aceptado.
		if ( $guardados && null !== $leido ) {
			Instantanea::parchear( Grupo::numero(), $p->id, array_intersect_key( array_map( 'strval', $leido['fields'] ), array_flip( $guardados ) ) );
		}
		Logger::log(
			'crm_ficha_editada',
			'socio',
			$p->num_socio_asde,
			array(
				'campos'     => $guardados,
				'rechazados' => $rechazados,
				'motivo'     => $motivo,
			)
		);
		return array(
			'guardados'  => $guardados,
			'rechazados' => $rechazados,
		);
	}

	/** Compara lo guardado con lo leído (el CRM puede devolver 0/1, espacios o entidades HTML). */
	public static function iguales( string $a, string $b ): bool {
		$norm = static function ( string $v ): string {
			$v = trim( html_entity_decode( $v, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
			return match ( strtolower( $v ) ) {
				'true', 'on', 'si', 'sí' => '1',
				'false', 'off'          => '0',
				default                 => $v,
			};
		};
		return $norm( $a ) === $norm( $b ) || ( '' === $norm( $a ) && '0' === $norm( $b ) ) || ( '0' === $norm( $a ) && '' === $norm( $b ) );
	}
}
