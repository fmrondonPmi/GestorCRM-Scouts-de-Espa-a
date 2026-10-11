<?php
namespace AsdeBalears\AreaPrivada\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Catálogo de secciones de ASDE (docs/05-secciones-educativas.md).
 *
 * `crm` es el valor que usa el CRM de ASDE en la membresía
 * (stic_Contacts_Relationships.relationship_type) y en "Secciones actuales"
 * (Contacts.stic_relationship_type_c).
 */
final class Secciones {

	public const EDUCATIVAS = array( 'castores', 'lobatos', 'scouts', 'escultas', 'rovers' );
	public const ADULTAS    = array( 'scouters', 'voluntariado' );

	/**
	 * Solo de la web (no es una sección del CRM): voluntario que colabora sin
	 * sección, solo como Tesorería, Secretaría o Coordinación (sin rol de Scouter).
	 */
	public const SIN_SECCION = 'sin_seccion';

	/** @return array<string, array{nombre:string, unidad:string, edad:string, color:string, texto:string, crm:string}> */
	public static function all(): array {
		return array(
			'castores'     => array( 'nombre' => asde_t( 'Castores' ), 'unidad' => asde_t( 'Colonia' ), 'edad' => '6–8', 'color' => '#5bc0eb', 'texto' => '#1a1a1a', 'crm' => 'Castores' ),
			'lobatos'      => array( 'nombre' => asde_t( 'Lobatos' ), 'unidad' => asde_t( 'Manada' ), 'edad' => '8–11', 'color' => '#f2c500', 'texto' => '#1a1a1a', 'crm' => 'Lobatos' ),
			'scouts'       => array( 'nombre' => asde_t( 'Scouts' ), 'unidad' => asde_t( 'Sección Scout' ), 'edad' => '11–14', 'color' => '#23712f', 'texto' => '#ffffff', 'crm' => 'Scouts' ),
			'escultas'     => array( 'nombre' => asde_t( 'Escultas' ), 'unidad' => asde_t( 'Unidad' ), 'edad' => '14–17', 'color' => '#7a4a26', 'texto' => '#ffffff', 'crm' => 'Escultas' ),
			'rovers'       => array( 'nombre' => asde_t( 'Rovers' ), 'unidad' => asde_t( 'Clan' ), 'edad' => '17–21', 'color' => '#c62828', 'texto' => '#ffffff', 'crm' => 'Rovers' ),
			'scouters'     => array( 'nombre' => asde_t( 'Scouters' ), 'unidad' => asde_t( 'Kraal' ), 'edad' => '+21', 'color' => '#632599', 'texto' => '#ffffff', 'crm' => 'Scouters' ),
			'voluntariado' => array( 'nombre' => asde_t( 'Voluntariado de apoyo' ), 'unidad' => '', 'edad' => '', 'color' => '#5f5f5f', 'texto' => '#ffffff', 'crm' => 'Voluntariado' ),
		);
	}

	public static function from_crm( string $value ): ?string {
		foreach ( self::all() as $slug => $s ) {
			if ( 0 === strcasecmp( $s['crm'], trim( $value ) ) ) {
				return $slug;
			}
		}
		return null;
	}

	public static function exists( string $slug ): bool {
		return isset( self::all()[ $slug ] );
	}

	public static function nombre( string $slug ): string {
		if ( self::SIN_SECCION === $slug ) {
			return asde_t( 'Voluntario sin sección' );
		}
		return self::all()[ $slug ]['nombre'] ?? $slug;
	}

	public static function is_educativa( string $slug ): bool {
		return in_array( $slug, self::EDUCATIVAS, true );
	}

	public static function is_adulta( string $slug ): bool {
		return in_array( $slug, self::ADULTAS, true );
	}

	/**
	 * Secciones a las que se puede pasar desde $actual en un cambio de sección (v0.31, petición
	 * del usuario): un educando, solo a la siguiente por edad (de Rovers, a Scouters o a Voluntariado,
	 * P-119); un adulto
	 * (Scouters o Voluntariado), a cualquier otra.
	 *
	 * @return list<string>
	 */
	public static function destinos_cambio( string $actual ): array {
		if ( self::is_educativa( $actual ) ) {
			$orden = array_merge( self::EDUCATIVAS, array( 'scouters' ) );
			return 'rovers' === $actual ? self::ADULTAS : array( $orden[ (int) array_search( $actual, $orden, true ) + 1 ] );
		}
		return array_values( array_diff( array_keys( self::all() ), array( $actual ) ) );
	}

	/** Etiqueta de color de la sección (HTML escapado). */
	public static function badge( string $slug ): string {
		if ( self::SIN_SECCION === $slug ) {
			return '<span class="asde-badge asde-badge--sin-seccion">' . esc_html( asde_t( 'Voluntario sin sección' ) ) . '</span>';
		}
		$s = self::all()[ $slug ] ?? null;
		if ( ! $s ) {
			return '';
		}
		return sprintf(
			'<span class="asde-badge" style="background:%1$s;color:%2$s;">%3$s</span>',
			esc_attr( $s['color'] ),
			esc_attr( $s['texto'] ),
			esc_html( $s['nombre'] )
		);
	}
}
