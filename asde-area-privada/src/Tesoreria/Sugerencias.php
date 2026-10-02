<?php
namespace AsdeBalears\AreaPrivada\Tesoreria;

use AsdeBalears\AreaPrivada\Domain\Persona;

defined( 'ABSPATH' ) || exit;

/**
 * Sugiere familias a partir del CRM: educandos activos sin familia que
 * comparten algún email (propio o de tutor). Solo es una ayuda: quien gestiona
 * confirma cada familia.
 */
final class Sugerencias {

	/**
	 * @param Persona[]          $activas
	 * @param array<string, int> $mapa    nº Scouts de España => familia (los que ya tienen)
	 * @param string[]           $secciones_pagan
	 * @return array<int, Persona[]> Grupos de 2 o más socios.
	 */
	public static function por_email( array $activas, array $mapa, array $secciones_pagan ): array {
		$candidatos = array_filter(
			$activas,
			static fn( Persona $p ) => ! isset( $mapa[ $p->num_socio_asde ] ) && array_intersect( $p->secciones, $secciones_pagan )
		);

		// Unión de conjuntos: dos socios van juntos si comparten un email.
		$padre = array();
		$raiz  = function ( string $x ) use ( &$padre, &$raiz ): string {
			return $padre[ $x ] === $x ? $x : ( $padre[ $x ] = $raiz( $padre[ $x ] ) );
		};
		$por_email = array();
		foreach ( $candidatos as $p ) {
			$padre[ $p->num_socio_asde ] = $p->num_socio_asde;
			foreach ( $p->emails() as $e ) {
				$por_email[ $e ][] = $p->num_socio_asde;
			}
		}
		foreach ( $por_email as $nums ) {
			for ( $i = 1; $i < count( $nums ); $i++ ) {
				$a = $raiz( $nums[0] );
				$b = $raiz( $nums[ $i ] );
				if ( $a !== $b ) {
					$padre[ $b ] = $a;
				}
			}
		}

		$grupos = array();
		foreach ( $candidatos as $p ) {
			$grupos[ $raiz( $p->num_socio_asde ) ][] = $p;
		}
		return array_values( array_filter( $grupos, static fn( $g ) => count( $g ) > 1 ) );
	}
}
