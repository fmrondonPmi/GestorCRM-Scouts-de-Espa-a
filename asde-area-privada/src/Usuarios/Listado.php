<?php
namespace AsdeBalears\AreaPrivada\Usuarios;

use AsdeBalears\AreaPrivada\Crm\CrmException;
use AsdeBalears\AreaPrivada\Crm\Instantanea;
use AsdeBalears\AreaPrivada\Crm\PersonaRepository;
use AsdeBalears\AreaPrivada\Domain\Grupo;
use AsdeBalears\AreaPrivada\Install\Roles;

defined( 'ABSPATH' ) || exit;

/**
 * Datos del listado de usuarios del área privada (v0.12.2).
 *
 * El listado se carga entero una sola vez y el navegador filtra, ordena y pagina
 * con esos datos en memoria (assets/js/usuarios.js), sin volver al servidor. Los
 * nombres salen de la instantánea cifrada de las fichas del grupo (Crm\Instantanea,
 * v0.12.3): abrir el listado no espera al CRM, y «Actualizar» la vuelve a leer.
 * WordPress sigue sin guardar nombres en claro: lo temporal va cifrado y caduca solo.
 */
final class Listado {

	/**
	 * Usuarios del grupo en el que se trabaja (sin visibilidad entre grupos): los
	 * que tienen algún rol del área y los desactivados.
	 *
	 * @return \WP_User[]
	 */
	public static function usuarios(): array {
		$del_grupo    = array(
			'key'   => Usuarios::META_GRUPO,
			'value' => Grupo::id(),
		);
		$users        = get_users(
			array(
				'role__in'   => Roles::ALL,
				'orderby'    => 'login',
				'order'      => 'ASC',
				'meta_query' => array( $del_grupo ), // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
		$desactivados = get_users(
			array(
				'orderby'    => 'login',
				'order'      => 'ASC',
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery
					$del_grupo,
					array(
						'key'   => Usuarios::META_ESTADO,
						'value' => Usuarios::DESACTIVADO,
					),
				),
			)
		);
		$ids          = wp_list_pluck( $users, 'ID' );
		return array_merge( $users, array_values( array_filter( $desactivados, static fn( $u ) => ! in_array( $u->ID, $ids, true ) ) ) );
	}

	/** Nº Scouts de España de las personas que salen en el listado (la de la cuenta y sus representadas). */
	public static function numeros( array $users ): array {
		$nums = array();
		foreach ( $users as $u ) {
			$nums[] = Usuarios::socio( $u->ID );
			foreach ( Usuarios::representados( $u->ID ) as $n ) {
				$nums[] = $n;
			}
		}
		return array_values( array_unique( array_filter( $nums ) ) );
	}

	/**
	 * Nombres (y si tienen membresía activa) de esas personas.
	 *
	 * @param string[] $nums      Nº Scouts de España.
	 * @param bool     $refrescar Volver a leer las fichas del CRM, sin fiarse de lo guardado.
	 * @return array{0: array<string, array{nombre:string, activa:bool}>, 1: string} Personas y, si el CRM no ha respondido, un aviso.
	 */
	public static function personas( array $nums, bool $refrescar = false ): array {
		$repo = PersonaRepository::make();
		if ( ! $repo || ! $nums ) {
			return array( array(), '' );
		}
		try {
			if ( $refrescar && Instantanea::segundos() > 0 && Instantanea::puede_forzar() ) { // Como mucho una lectura forzada cada pocos segundos.
				$repo->refrescar_instantanea();
			}
			$out = array();
			foreach ( $repo->obtener_varias( $nums ) as $num => $p ) {
				$out[ (string) $num ] = array(
					'nombre' => $p->nombre_completo(),
					'activa' => $p->tiene_membresia_activa(),
				);
			}
			return array( $out, '' );
		} catch ( CrmException $e ) {
			return array( array(), 'No se han podido leer los nombres del CRM: se muestran solo los números de socio.' );
		}
	}
}
