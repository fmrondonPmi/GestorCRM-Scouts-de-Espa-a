<?php
namespace AsdeBalears\AreaPrivada\Usuarios;

use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Domain\Grupos;
use AsdeBalears\AreaPrivada\Domain\Identidad;
use AsdeBalears\AreaPrivada\Domain\NumeroSocio;
use AsdeBalears\AreaPrivada\Domain\Organizacion;
use AsdeBalears\AreaPrivada\Socios\SociosTable;

defined( 'ABSPATH' ) || exit;

/**
 * Pasa el usuario de las cuentas existentes al número que se ha elegido como usuario
 * de acceso (v0.14, doc 27): del nº local al de Scouts de España o al revés.
 *
 * Cada cuenta de una persona se renombra con SU número; una cuenta familiar, con el del
 * menor «base» (el de su nombre de usuario) más el sufijo (T, T2). El vínculo con la ficha
 * (asde_socio / asde_representado) no cambia: lo único que cambia es el nombre de usuario.
 * Las que no se pueden cambiar (no hay equivalencia, el nombre ya existe, no es una cuenta
 * de socio/a…) se listan con el motivo y siguen funcionando: al entrar valen los dos números.
 *
 * El plan se calcula con UNA lectura de la tabla de equivalencias y de los nombres de
 * usuario existentes, no con consultas por cuenta (la pantalla de configuración lo
 * calcula cada vez que se abre).
 */
final class MigracionUsuario {

	/**
	 * Qué cambiaría.
	 *
	 * @param string|null $destino Organizacion::IDENTIFICADOR_* (por defecto, el elegido ahora).
	 * @return array{cambios: list<array{id:int, actual:string, nuevo:string}>, iguales:int, imposibles: list<array{id:int, actual:string, motivo:string}>}
	 */
	public static function plan( ?string $destino = null ): array {
		global $wpdb;
		$destino = $destino ?? Organizacion::identificador();
		$out     = array(
			'cambios'    => array(),
			'iguales'    => 0,
			'imposibles' => array(),
		);
		$mapas = self::mapas();
		// Nombres de usuario que ya existen (en minúsculas, como los compara MySQL) → id.
		$existentes = array();
		foreach ( (array) $wpdb->get_results( "SELECT ID, user_login FROM {$wpdb->users}", ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB
			$existentes[ strtolower( (string) $r['user_login'] ) ] = (int) $r['ID'];
		}
		$usados = array();
		foreach ( self::cuentas() as $u ) {
			$actual = (string) $u->user_login;
			$nuevo  = self::nuevo_login( $u, $destino, $mapas, $motivo );
			if ( null === $nuevo ) {
				$out['imposibles'][] = array(
					'id'     => (int) $u->ID,
					'actual' => $actual,
					'motivo' => $motivo,
				);
				continue;
			}
			if ( $nuevo === $actual ) {
				++$out['iguales'];
				continue;
			}
			$otro = $existentes[ strtolower( $nuevo ) ] ?? null;
			if ( ( null !== $otro && $otro !== (int) $u->ID ) || isset( $usados[ $nuevo ] ) ) {
				$out['imposibles'][] = array(
					'id'     => (int) $u->ID,
					'actual' => $actual,
					'motivo' => sprintf( 'Ya existe otro usuario %s.', $nuevo ),
				);
				continue;
			}
			$usados[ $nuevo ] = true;
			$out['cambios'][] = array(
				'id'     => (int) $u->ID,
				'actual' => $actual,
				'nuevo'  => $nuevo,
			);
		}
		return $out;
	}

	/**
	 * Renombra las cuentas del plan. Cierra sus sesiones (la cookie de WordPress lleva el
	 * nombre de usuario) y lo anota en la auditoría.
	 *
	 * @return array{hechos:int, fallidos:int}
	 */
	public static function aplicar( ?string $destino = null ): array {
		global $wpdb;
		$plan = self::plan( $destino );
		$res  = array(
			'hechos'   => 0,
			'fallidos' => 0,
		);
		foreach ( $plan['cambios'] as $c ) {
			$user = get_userdata( $c['id'] );
			if ( ! $user || $user->user_login !== $c['actual'] ) {
				++$res['fallidos'];
				continue;
			}
			// Antes de perder el prefijo del nombre (que indicaba el grupo), se guarda el grupo.
			if ( '' === (string) get_user_meta( $user->ID, Usuarios::META_GRUPO, true ) ) {
				$grupo = Grupos::de_usuario( $user );
				if ( $grupo ) {
					update_user_meta( $user->ID, Usuarios::META_GRUPO, $grupo['id'] );
				}
			}
			$datos = array(
				'user_login'    => $c['nuevo'],
				'user_nicename' => sanitize_title( $c['nuevo'] ),
			);
			if ( $user->display_name === $c['actual'] ) {
				$datos['display_name'] = $c['nuevo'];
			}
			$hecho = $wpdb->update( $wpdb->users, $datos, array( 'ID' => $user->ID ) ); // phpcs:ignore WordPress.DB
			if ( false === $hecho ) {
				++$res['fallidos'];
				continue;
			}
			if ( (string) get_user_meta( $user->ID, 'nickname', true ) === $c['actual'] ) {
				update_user_meta( $user->ID, 'nickname', $c['nuevo'] );
			}
			clean_user_cache( $user->ID );
			\WP_Session_Tokens::get_instance( $user->ID )->destroy_all();
			Logger::log( 'usuario_renombrado', 'user', (string) $user->ID, array( 'de' => $c['actual'], 'a' => $c['nuevo'] ) );
			++$res['hechos'];
		}
		return $res;
	}

	/** Las cuentas de socios/as y familias (con vínculo a una ficha), de cualquier grupo. @return \WP_User[] */
	private static function cuentas(): array {
		$vistas = array();
		foreach ( array( Usuarios::META_SOCIO, Usuarios::META_REPRESENTADO ) as $clave ) {
			foreach ( get_users( array( 'meta_key' => $clave, 'meta_compare' => 'EXISTS', 'orderby' => 'login', 'order' => 'ASC' ) ) as $u ) { // phpcs:ignore WordPress.DB.SlowDBQuery
				$vistas[ $u->ID ] = $u;
			}
		}
		return array_values( $vistas );
	}

	/**
	 * La tabla de equivalencias, leída una vez, en los dos sentidos.
	 *
	 * @return array{asde_a_local: array<string,string>, local_a_asde: array<string,string>}
	 */
	private static function mapas(): array {
		$m = array(
			'asde_a_local' => array(),
			'local_a_asde' => array(),
		);
		foreach ( SociosTable::todas() as $asde => $fila ) {
			$m['asde_a_local'][ (string) $asde ]      = (string) $fila['local'];
			$m['local_a_asde'][ (string) $fila['local'] ] = (string) $asde;
		}
		return $m;
	}

	/**
	 * Usuario que le correspondería con el destino, o null con el motivo.
	 *
	 * @param array{asde_a_local: array<string,string>, local_a_asde: array<string,string>} $mapas
	 * @param string|null $motivo Se rellena si no se puede.
	 */
	private static function nuevo_login( \WP_User $u, string $destino, array $mapas, ?string &$motivo ): ?string {
		$partes = Identidad::partes( (string) $u->user_login );
		if ( ! $partes ) {
			$motivo = 'Su nombre de usuario no es un nº de socio/a: no se toca.';
			return null;
		}
		list( $base, $sufijo ) = $partes;
		$grupo = Grupos::de_usuario( $u );

		$base_asde  = null;
		$base_local = null;
		if ( isset( $mapas['local_a_asde'][ $base ] ) ) {            // El nombre es un nº local.
			$base_asde  = $mapas['local_a_asde'][ $base ];
			$base_local = $base;
		} elseif ( isset( $mapas['asde_a_local'][ $base ] ) ) {      // El nombre es un nº de Scouts de España.
			$base_asde  = $base;
			$base_local = $mapas['asde_a_local'][ $base ];
		} elseif ( '' !== $sufijo ) {
			// Cuenta familiar: el menor «base» es uno de los que representa (también sirve si la tabla no lo tiene).
			foreach ( Usuarios::representados( $u->ID ) as $menor ) {
				$menor = (string) $menor;
				if ( $menor === $base ) {
					$base_asde = $base;
					break;
				}
				if ( ( $mapas['asde_a_local'][ $menor ] ?? null ) === $base ) {
					$base_asde  = $menor;
					$base_local = $base;
					break;
				}
			}
		}
		// Sin equivalencia: si el nombre tiene la forma de un nº local, no se sabe de qué ficha es; si no, ya es de Scouts de España.
		$parece_local = $grupo && '' !== NumeroSocio::codigo() && NumeroSocio::es_valido( $base, (string) $grupo['numero'] );
		if ( null === $base_asde && null === $base_local ) {
			if ( $parece_local ) {
				$base_local = $base;
			} else {
				$base_asde = $base;
			}
		}

		// La cuenta de una persona se sabe por su vínculo con la ficha, no por su nombre.
		$vinculo = '' === $sufijo ? Usuarios::socio( $u->ID ) : '';
		if ( '' !== $vinculo ) {
			$base_asde  = $vinculo;
			$base_local = $mapas['asde_a_local'][ $vinculo ] ?? null;
		}

		if ( Organizacion::IDENTIFICADOR_ASDE === $destino ) {
			if ( null === $base_asde ) {
				$motivo = 'No se encuentra el nº de Scouts de España de su ficha (comprueba que está en Personas y vuelve a intentarlo).';
				return null;
			}
			return $base_asde . $sufijo;
		}
		if ( null === $base_local || ! $grupo || ! NumeroSocio::es_valido( $base_local, (string) $grupo['numero'] ) ) {
			$motivo = 'Su ficha no tiene un nº local válido y único (corrígelo en Personas y vuelve a intentarlo).';
			return null;
		}
		return $base_local . $sufijo;
	}
}
