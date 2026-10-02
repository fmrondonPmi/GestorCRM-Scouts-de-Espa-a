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
			'castores'     => array( 'nombre' => 'Castores', 'unidad' => 'Colonia', 'edad' => '6–8', 'color' => '#5bc0eb', 'texto' => '#1a1a1a', 'crm' => 'Castores' ),
			'lobatos'      => array( 'nombre' => 'Lobatos', 'unidad' => 'Manada', 'edad' => '8–11', 'color' => '#f2c500', 'texto' => '#1a1a1a', 'crm' => 'Lobatos' ),
			'scouts'       => array( 'nombre' => 'Scouts', 'unidad' => 'Sección Scout', 'edad' => '11–14', 'color' => '#23712f', 'texto' => '#ffffff', 'crm' => 'Scouts' ),
			'escultas'     => array( 'nombre' => 'Escultas', 'unidad' => 'Unidad', 'edad' => '14–17', 'color' => '#7a4a26', 'texto' => '#ffffff', 'crm' => 'Escultas' ),
			'rovers'       => array( 'nombre' => 'Rovers', 'unidad' => 'Clan', 'edad' => '17–21', 'color' => '#c62828', 'texto' => '#ffffff', 'crm' => 'Rovers' ),
			'scouters'     => array( 'nombre' => 'Scouters', 'unidad' => 'Kraal', 'edad' => '+21', 'color' => '#632599', 'texto' => '#ffffff', 'crm' => 'Scouters' ),
			'voluntariado' => array( 'nombre' => 'Voluntariado de apoyo', 'unidad' => '', 'edad' => '', 'color' => '#5f5f5f', 'texto' => '#ffffff', 'crm' => 'Voluntariado' ),
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
			return 'Voluntario sin sección';
		}
		return self::all()[ $slug ]['nombre'] ?? $slug;
	}

	public static function is_educativa( string $slug ): bool {
		return in_array( $slug, self::EDUCATIVAS, true );
	}

	public static function is_adulta( string $slug ): bool {
		return in_array( $slug, self::ADULTAS, true );
	}

	/** Etiqueta de color de la sección (HTML escapado). */
	public static function badge( string $slug ): string {
		if ( self::SIN_SECCION === $slug ) {
			return '<span class="asde-badge asde-badge--sin-seccion">Voluntario sin sección</span>';
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
