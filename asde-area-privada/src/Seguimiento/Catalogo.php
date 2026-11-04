<?php
namespace AsdeBalears\AreaPrivada\Seguimiento;

defined( 'ABSPATH' ) || exit;

/**
 * Progresión personal de Scouts de España (Programa Educativo, págs. 88-100; doc 65).
 *
 * Por sección hay 8-9 aspectos y cada uno tiene su indicador en las tres etapas
 * (Integración, Participación, Animación). Los textos son los del programa, en castellano
 * (`data/progresion.json`): son contenido oficial, no textos de la interfaz.
 */
final class Catalogo {

	/** Años que dura cada sección (la edad de entrada está en Secciones::EDAD_DESDE). */
	private const ANIOS = array(
		'castores' => 3,
		'lobatos'  => 3,
		'scouts'   => 3,
		'escultas' => 3,
		'rovers'   => 4,
	);

	/** @return array<string, array<int, array{t:string, e:string[]}>> sección => aspectos */
	private static function datos(): array {
		static $datos = null;
		if ( null === $datos ) {
			$json  = file_get_contents( ASDE_AP_DIR . 'data/progresion.json' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- fichero propio del plugin
			$datos = json_decode( (string) $json, true );
			$datos = is_array( $datos ) ? $datos : array();
		}
		return $datos;
	}

	/** @return array<int, string> Etapas de progresión por número (1-3). */
	public static function etapas(): array {
		return array(
			1 => __( 'Integration', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
			2 => __( 'Participation', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
			3 => __( 'Animation', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
		);
	}

	/** @return array<int, string> Estados de un aspecto (0-2). */
	public static function estados(): array {
		return array(
			0 => __( 'Not yet', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
			1 => __( 'On the way', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
			2 => __( 'Achieved', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
		);
	}

	/** @return string[] Slugs de las secciones con seguimiento. */
	public static function secciones(): array {
		return array_keys( self::ANIOS );
	}

	/** @return array<int, array{t:string, e:string[]}> */
	public static function aspectos( string $seccion ): array {
		return self::datos()[ $seccion ] ?? array();
	}

	/**
	 * Año dentro de la sección según la edad (orientativo: los tramos se pueden flexibilizar, pág. 82).
	 *
	 * @return array{n:int, de:int, fuera:bool}|null null si no hay edad.
	 */
	public static function anio( string $seccion, ?int $edad ): ?array {
		$de = self::ANIOS[ $seccion ] ?? 0;
		if ( null === $edad || ! $de ) {
			return null;
		}
		$n = $edad - \AsdeBalears\AreaPrivada\Domain\Secciones::EDAD_DESDE[ $seccion ] + 1;
		return array(
			'n'     => max( 1, min( $de, $n ) ),
			'de'    => $de,
			'fuera' => $n < 1 || $n > $de,
		);
	}
}
