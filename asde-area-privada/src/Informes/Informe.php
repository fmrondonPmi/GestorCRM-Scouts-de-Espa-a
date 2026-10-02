<?php
namespace AsdeBalears\AreaPrivada\Informes;

use AsdeBalears\AreaPrivada\Domain\Secciones;

defined( 'ABSPATH' ) || exit;

/**
 * Definición de un informe: columnas + filtros. No contiene resultados.
 *
 * Filtros admitidos:
 *   secciones  string[]  slugs (vacío = todas las del ámbito)
 *   edad_min   ?int
 *   edad_max   ?int
 *   salud      bool      solo quien tenga datos de salud relevantes
 *   orden      string    apellidos | nombre | edad | seccion
 */
final class Informe {

	public const ORDENES = array(
		'apellidos' => 'Apellidos',
		'nombre'    => 'Nombre',
		'edad'      => 'Edad',
		'seccion'   => 'Sección',
	);

	/** @param string[] $columnas */
	public function __construct(
		public int $id,
		public string $nombre,
		public string $descripcion,
		public array $columnas,
		public array $filtros,
		public int $autor_id,
		public bool $compartido,
		public bool $sistema = false
	) {
		$this->columnas = array_values( array_intersect( array_keys( Catalogo::columnas() ), $this->columnas ) );
		$this->filtros  = self::limpiar_filtros( $this->filtros );
	}

	public static function from_row( array $row ): self {
		$def = json_decode( (string) $row['definicion'], true ) ?: array();
		return new self(
			(int) $row['id'],
			(string) $row['nombre'],
			(string) $row['descripcion'],
			(array) ( $def['columnas'] ?? array() ),
			(array) ( $def['filtros'] ?? array() ),
			(int) $row['autor_id'],
			(bool) $row['compartido'],
			(bool) $row['sistema']
		);
	}

	public static function limpiar_filtros( array $f ): array {
		$edad = static fn( $v ) => ( '' === $v || null === $v ) ? null : max( 0, min( 120, (int) $v ) );
		return array(
			'secciones' => array_values( array_intersect( array_keys( Secciones::all() ), array_map( 'strval', (array) ( $f['secciones'] ?? array() ) ) ) ),
			'edad_min'  => $edad( $f['edad_min'] ?? null ),
			'edad_max'  => $edad( $f['edad_max'] ?? null ),
			'salud'     => ! empty( $f['salud'] ),
			'orden'     => isset( self::ORDENES[ $f['orden'] ?? '' ] ) ? (string) $f['orden'] : 'apellidos',
		);
	}

	public function puede_editar( \WP_User $user ): bool {
		return ! $this->sistema && ( $this->autor_id === $user->ID || $user->has_cap( 'asde_manage_users' ) );
	}
}
