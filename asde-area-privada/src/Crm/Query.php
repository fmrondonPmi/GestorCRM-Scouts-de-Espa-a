<?php
namespace AsdeBalears\AreaPrivada\Crm;

defined( 'ABSPATH' ) || exit;

/**
 * Construcción segura de fragmentos WHERE para la API v4_1 (que recibe SQL crudo).
 *
 * Toda consulta al CRM debe montarse con estos helpers: nunca concatenar
 * valores sin escapar. El CRM además valida la consulta (SugarSQLValidate).
 */
final class Query {

	public const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

	/** Literal de texto entre comillas simples, escapado para MySQL. */
	public static function str( string $value ): string {
		return "'" . strtr(
			$value,
			array(
				'\\'   => '\\\\',
				"\0"   => '\\0',
				"\n"   => '\\n',
				"\r"   => '\\r',
				"'"    => "\\'",
				'"'    => '\\"',
				"\x1a" => '\\Z',
			)
		) . "'";
	}

	/** Literal para LIKE '%valor%' (escapa también los comodines). */
	public static function contains( string $value ): string {
		return self::str( '%' . addcslashes( $value, '%_\\' ) . '%' );
	}

	public static function is_id( string $id ): bool {
		return (bool) preg_match( self::UUID, $id );
	}

	public static function id( string $id ): string {
		if ( ! self::is_id( $id ) ) {
			throw new \InvalidArgumentException( asde_t( 'Identificador de CRM no válido.' ) );
		}
		return "'" . strtolower( $id ) . "'";
	}

	/** @param string[] $ids */
	public static function ids( array $ids ): string {
		if ( ! $ids ) {
			throw new \InvalidArgumentException( asde_t( 'Lista de identificadores vacía.' ) );
		}
		return '(' . implode( ',', array_map( array( self::class, 'id' ), array_values( array_unique( $ids ) ) ) ) . ')';
	}

	/**
	 * Lista de valores de un desplegable (se validan contra una lista blanca).
	 *
	 * @param string[] $values
	 * @param string[] $allowed
	 */
	public static function enum_in( array $values, array $allowed ): string {
		$values = array_values( array_intersect( $values, $allowed ) );
		if ( ! $values ) {
			throw new \InvalidArgumentException( asde_t( 'Ningún valor permitido.' ) );
		}
		return '(' . implode( ',', array_map( array( self::class, 'str' ), $values ) ) . ')';
	}
}
