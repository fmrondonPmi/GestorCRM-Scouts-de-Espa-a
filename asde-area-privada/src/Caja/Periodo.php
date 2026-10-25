<?php
namespace AsdeBalears\AreaPrivada\Caja;

use AsdeBalears\AreaPrivada\Tesoreria\Config;

defined( 'ABSPATH' ) || exit;

/**
 * Periodo de la caja y del libro mayor (v0.59, P-207): una ronda (septiembre–agosto, por defecto la actual) o un
 * año natural (enero–diciembre). En la dirección va como `periodo=r2026-2027` o `periodo=a2026`.
 */
final class Periodo {

	/**
	 * @return array{clave:string, desde:string, hasta:string, etiqueta:string}
	 */
	public static function de( string $clave ): array {
		if ( preg_match( '/^a(\d{4})$/', $clave, $m ) && (int) $m[1] >= 2000 && (int) $m[1] <= 2100 ) {
			return array(
				'clave'    => $clave,
				'desde'    => $m[1] . '-01-01',
				'hasta'    => $m[1] . '-12-31',
				/* translators: %s: año */
				'etiqueta' => sprintf( asde_t( 'Año %s' ), $m[1] ),
			);
		}
		$ronda = 'r' === substr( $clave, 0, 1 ) && Config::es_ronda( substr( $clave, 1 ) ) ? substr( $clave, 1 ) : Config::ronda_actual();
		$ini   = (int) substr( $ronda, 0, 4 );
		return array(
			'clave'    => 'r' . $ronda,
			'desde'    => $ini . '-09-01',
			'hasta'    => ( $ini + 1 ) . '-08-31',
			/* translators: %s: ronda (2026-2027) */
			'etiqueta' => sprintf( asde_t( 'Ronda %s' ), $ronda ),
		);
	}

	/**
	 * Lo que se puede elegir en el desplegable: las rondas de Tesorería y los años naturales que tocan.
	 *
	 * @return array<string, string> clave => etiqueta
	 */
	public static function opciones(): array {
		$rondas = Config::rondas();
		$out    = array();
		foreach ( $rondas as $r ) {
			$out[ 'r' . $r ] = self::de( 'r' . $r )['etiqueta'];
		}
		$anios = array();
		foreach ( $rondas as $r ) {
			$anios[] = (int) substr( $r, 5, 4 );
			$anios[] = (int) substr( $r, 0, 4 );
		}
		$anios[] = (int) wp_date( 'Y' );
		$anios   = array_unique( $anios );
		rsort( $anios );
		foreach ( $anios as $a ) {
			$out[ 'a' . $a ] = self::de( 'a' . $a )['etiqueta'];
		}
		return $out;
	}
}
