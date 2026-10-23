<?php
namespace AsdeBalears\AreaPrivada\Socios;

use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Domain\Grupo;
use AsdeBalears\AreaPrivada\Domain\Persona;
use AsdeBalears\AreaPrivada\Tesoreria\FamiliasTable;
use AsdeBalears\AreaPrivada\Usuarios\Usuarios;

defined( 'ABSPATH' ) || exit;

/**
 * «Eliminar de la web» a una persona SIN membresía activa (v0.35.1, petición del usuario).
 *
 * En el CRM no se toca nada: la ficha y su historia siguen allí. En la web deja de salir en
 * Personas (la única pantalla con personas sin membresía activa), sale de su familia, su cuenta
 * propia se desactiva (Usuarios::desactivar()) y deja de estar en las cuentas familiares; la que
 * se queda sin nadie a quien representar, también se desactiva. Si vuelve a tener una membresía
 * activa en el CRM, vuelve a salir sola (y se le puede dar acceso de nuevo).
 *
 * Se guarda en la opción asde_ap_eliminadas_<grupo>: nº de socio/a Scouts de España => cuándo y
 * quién (solo identificadores, como el resto de lo que guarda la web de cada persona).
 */
final class Eliminadas {

	private const OPTION = 'asde_ap_eliminadas_';

	/** @return array<string, array{en:string, por:int}> Las del grupo actual: nº => cuándo (UTC) y quién. */
	public static function todas(): array {
		$grupo = Grupo::numero();
		return '' === $grupo ? array() : (array) get_option( self::OPTION . $grupo, array() );
	}

	public static function esta( string $num_asde ): bool {
		return isset( self::todas()[ $num_asde ] );
	}

	/** Vuelve a tener una membresía activa: se le quita la marca. */
	public static function quitar_marca( string $num_asde ): void {
		$todas = self::todas();
		if ( isset( $todas[ $num_asde ] ) ) {
			unset( $todas[ $num_asde ] );
			update_option( self::OPTION . Grupo::numero(), $todas, false );
		}
	}

	/**
	 * @return array{familia:string, cuenta:bool, familiares:int, desactivadas:int}|\WP_Error
	 *         El nº de la familia de la que sale (o ''), si se ha desactivado su cuenta propia, de
	 *         cuántas cuentas familiares sale y cuántas de ellas se han desactivado.
	 */
	public static function eliminar( Persona $p, int $user_id ) {
		if ( '' === Grupo::numero() ) {
			return new \WP_Error( 'asde_eliminar', asde_t( 'No hay ningún grupo elegido.' ) );
		}
		if ( $p->tiene_membresia_activa() ) {
			return new \WP_Error( 'asde_eliminar', asde_t( 'Solo se puede eliminar de la web a quien no tiene ninguna membresía activa. Antes, dale de baja.' ) );
		}
		$num = $p->num_socio_asde;
		$out = array(
			'familia'      => '',
			'cuenta'       => false,
			'familiares'   => 0,
			'desactivadas' => 0,
		);

		$familia = FamiliasTable::de_socio( $num );
		if ( $familia ) {
			FamiliasTable::quitar( (int) $familia['id'], $num );
			$out['familia'] = (string) $familia['numero'];
		}
		// Solo las cuentas de este grupo: la misma ficha puede estar en otro grupo de la instalación (doc 34) y allí sigue
		// (doc 59, RC-USU-1).
		$cuentas = get_users(
			array(
				'meta_key'   => Usuarios::META_SOCIO, // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value' => $num, // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
		foreach ( array_filter( $cuentas, array( Usuarios::class, 'del_grupo_actual' ) ) as $cuenta ) {
			if ( Usuarios::ACTIVO === Usuarios::estado( $cuenta ) ) {
				Usuarios::desactivar( $cuenta );
				$out['cuenta'] = true;
			}
		}
		foreach ( array_filter( Usuarios::tutores_de( $num ), array( Usuarios::class, 'del_grupo_actual' ) ) as $tutor ) {
			Usuarios::quitar_representado( $tutor->ID, $num );
			++$out['familiares'];
			if ( ! Usuarios::representados( $tutor->ID ) && ! Usuarios::socio( $tutor->ID ) && Usuarios::ACTIVO === Usuarios::estado( $tutor ) ) {
				Usuarios::desactivar( $tutor );
				++$out['desactivadas'];
			}
		}

		$todas         = self::todas();
		$todas[ $num ] = array(
			'en'  => current_time( 'mysql', true ),
			'por' => $user_id,
		);
		update_option( self::OPTION . Grupo::numero(), $todas, false );
		Logger::log( 'persona_eliminada_web', 'socio', $num, $out );
		return $out;
	}
}
