<?php
namespace AsdeBalears\AreaPrivada\Crm;

use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Domain\Grupo;
use AsdeBalears\AreaPrivada\Domain\Persona;

defined( 'ABSPATH' ) || exit;

/**
 * Única puerta de escritura en el CRM.
 *
 * - Solo si el usuario del CRM del grupo tiene permiso de edición en el módulo.
 * - Solo sobre fichas del grupo: la Persona tiene que venir de PersonaRepository,
 *   que filtra por el grupo de seguridad.
 * - Nunca borra registros; nunca toca los campos de NO_EDITABLES.
 * - Desde la v0.22 (doc 34) crea personas y membresías (crear()), cambia membresías de
 *   una persona del grupo (actualizar_membresia()) y las enlaza (relacionar(): solo con
 *   el grupo de seguridad del propio grupo o con una membresía). Lo orquesta Crm\Altas.
 *   Estas tres van siempre por el cliente del CRM (en las pruebas, el CRM simulado
 *   intercepta las peticiones); usar_para_pruebas() solo sirve para guardar().
 * - Después de guardar relee los campos: lo que el CRM no haya aceptado se
 *   devuelve como "rechazado" (p. ej. por un permiso por campo).
 * - Auditoría con los NOMBRES de los campos (nunca los valores).
 */
final class Escritura {

	public const MODULO = 'Contacts';

	/** Membresías (en ASDE, «Relaciones» reetiquetado: doc 04, §7.4). */
	public const MEMBRESIAS = 'stic_Contacts_Relationships';

	/** Enlace de una persona con sus membresías. */
	public const ENLACE_MEMBRESIAS = 'stic_contacts_relationships_contacts';

	/** Campos que nunca se escriben al crear (los pone el CRM). */
	private const NUNCA_AL_CREAR = array( 'id', 'deleted', 'date_entered', 'date_modified', 'created_by', 'modified_user_id', 'numerosocio_c', 'stic_relationship_type_c' );

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

	/** ¿Puede el usuario de API del grupo crear personas y membresías? (v0.22) */
	public static function puede_crear(): bool {
		return null !== ClientFactory::reader() && Permisos::puede( self::MODULO, 'edit' ) && Permisos::puede( self::MEMBRESIAS, 'edit' );
	}

	/**
	 * Crea una persona o una membresía (v0.22, doc 34).
	 *
	 * @param array<string, string> $valores
	 * @return string|\WP_Error Id del registro nuevo.
	 */
	public static function crear( string $modulo, array $valores, string $motivo ) {
		if ( ! in_array( $modulo, array( self::MODULO, self::MEMBRESIAS ), true ) ) {
			return new \WP_Error( 'asde_crm_escritura', asde_t( 'Desde la web solo se crean personas y membresías.' ) );
		}
		if ( ! self::puede_crear() ) {
			return new \WP_Error( 'asde_crm_escritura', asde_t( 'El usuario del CRM de tu grupo no tiene permiso para crear personas y membresías.' ) );
		}
		foreach ( array_keys( $valores ) as $campo ) {
			if ( in_array( $campo, self::NUNCA_AL_CREAR, true ) || ! preg_match( '/^[a-z0-9_]+$/', $campo ) ) {
				/* translators: %s: nombre del campo */
				return new \WP_Error( 'asde_crm_escritura', sprintf( asde_t( 'El campo %s no se puede escribir desde la web.' ), $campo ) );
			}
		}
		try {
			$id = ClientFactory::reader()->create_entry( $modulo, $valores );
		} catch ( CrmException $e ) {
			Logger::log( 'crm_creacion_error', $modulo, '', array( 'campos' => array_keys( $valores ), 'motivo' => $motivo ) );
			return new \WP_Error( 'asde_crm_escritura', asde_t( 'El CRM no ha creado el registro: ' ) . $e->getMessage() );
		}
		Logger::log( 'crm_registro_creado', $modulo, $id, array( 'campos' => array_keys( $valores ), 'motivo' => $motivo ) );
		return $id;
	}

	/**
	 * Cambia campos de una membresía de una persona del grupo (fin, motivo, estado).
	 * Antes comprueba que la membresía es de esa persona; después relee lo guardado.
	 *
	 * @param array<string, string> $valores
	 * @return true|\WP_Error
	 */
	public static function actualizar_membresia( Persona $p, string $membresia, array $valores, string $motivo ) {
		if ( ! self::puede_crear() ) {
			return new \WP_Error( 'asde_crm_escritura', asde_t( 'El usuario del CRM de tu grupo no tiene permiso para cambiar membresías.' ) );
		}
		$crm = ClientFactory::reader();
		try {
			$suyas = array_column( $crm->related( self::MODULO, $p->id, self::ENLACE_MEMBRESIAS, '', array( 'id' ) ), 'id' );
			if ( ! in_array( $membresia, $suyas, true ) ) {
				return new \WP_Error( 'asde_crm_escritura', asde_t( 'Esa membresía no es de esta persona.' ) );
			}
			$crm->set_entry( self::MEMBRESIAS, $membresia, $valores );
			$leido = $crm->related( self::MODULO, $p->id, self::ENLACE_MEMBRESIAS, '', array_merge( array( 'id' ), array_keys( $valores ) ) );
		} catch ( CrmException | \InvalidArgumentException $e ) {
			Logger::log( 'crm_escritura_error', self::MEMBRESIAS, $membresia, array( 'campos' => array_keys( $valores ), 'motivo' => $motivo ) );
			return new \WP_Error( 'asde_crm_escritura', asde_t( 'El CRM no ha guardado los cambios: ' ) . $e->getMessage() );
		}
		$fila = array_values( array_filter( $leido, static fn( $e ) => $e['id'] === $membresia ) )[0]['fields'] ?? array();
		$mal  = array_keys( array_filter( $valores, static fn( $v, $c ) => ! self::iguales( (string) ( $fila[ $c ] ?? '' ), (string) $v ), ARRAY_FILTER_USE_BOTH ) );
		Logger::log( 'crm_membresia_cambiada', self::MEMBRESIAS, $membresia, array( 'campos' => array_keys( $valores ), 'rechazados' => $mal, 'motivo' => $motivo ) );
		if ( $mal ) {
			/* translators: %s: nombres de los campos */
			return new \WP_Error( 'asde_crm_escritura', sprintf( asde_t( 'El CRM no ha aceptado: %s.' ), implode( ', ', $mal ) ) );
		}
		return true;
	}

	/**
	 * Enlaza una persona con el grupo de seguridad del PROPIO grupo (para que el grupo la
	 * vea) o con una membresía. Ningún otro enlace se puede hacer desde la web.
	 *
	 * @param string[] $ids
	 * @return true|\WP_Error
	 */
	public static function relacionar( string $persona, string $enlace, array $ids, string $motivo ) {
		$propio = (string) ( Grupo::actual()['crm_id'] ?? '' );
		$valido = ( 'SecurityGroups' === $enlace && '' !== $propio && array( $propio ) === array_values( $ids ) ) || ( self::ENLACE_MEMBRESIAS === $enlace && 1 === count( $ids ) );
		if ( ! $valido ) {
			return new \WP_Error( 'asde_crm_escritura', asde_t( 'Ese enlace no se puede hacer desde la web.' ) );
		}
		if ( ! self::puede_crear() ) {
			return new \WP_Error( 'asde_crm_escritura', asde_t( 'El usuario del CRM de tu grupo no tiene permiso para crear personas y membresías.' ) );
		}
		try {
			$hecho = ClientFactory::reader()->set_relationship( self::MODULO, $persona, $enlace, $ids );
		} catch ( CrmException | \InvalidArgumentException $e ) {
			$hecho = false;
		}
		Logger::log( $hecho ? 'crm_enlace_creado' : 'crm_escritura_error', self::MODULO, $persona, array( 'enlace' => $enlace, 'motivo' => $motivo ) );
		return $hecho ? true : new \WP_Error( 'asde_crm_escritura', asde_t( 'El CRM no ha hecho el enlace.' ) );
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
