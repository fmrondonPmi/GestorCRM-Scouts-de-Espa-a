<?php
/**
 * Aparta durante la prueba a los usuarios «local-*» del acceso rápido del Local (acceso-rapido-local): son cuentas de
 * Tesorería, Secretaría, etc. de verdad y, si siguen con su rol, reciben los avisos que la prueba cuenta (p. ej. «el email
 * va a las dos cuentas de Tesorería»). Sus roles se guardan en una opción y se devuelven al terminar (también si la prueba
 * muere: register_shutdown_function). Si una ejecución se cortó antes de devolverlos, la opción sigue ahí y la próxima
 * ejecución los devuelve al acabar.
 */
defined( 'ABSPATH' ) || exit;

const ASDE_TEST_OPCION_LOCAL = 'asde_test_roles_local';

function asde_test_apartar_usuarios_local(): void {
	$guardado = get_option( ASDE_TEST_OPCION_LOCAL );
	if ( ! is_array( $guardado ) ) {
		$guardado = array();
		foreach ( get_users( array( 'search' => 'local-*', 'search_columns' => array( 'user_login' ) ) ) as $u ) {
			$guardado[ $u->ID ] = array_values( $u->roles );
		}
		update_option( ASDE_TEST_OPCION_LOCAL, $guardado, false );
	}
	foreach ( array_keys( $guardado ) as $id ) {
		$u = get_userdata( (int) $id );
		if ( $u ) {
			foreach ( (array) $u->roles as $r ) {
				$u->remove_role( $r );
			}
		}
	}
	register_shutdown_function( 'asde_test_devolver_usuarios_local' );
}

function asde_test_devolver_usuarios_local(): void {
	$guardado = get_option( ASDE_TEST_OPCION_LOCAL );
	if ( ! is_array( $guardado ) ) {
		return;
	}
	foreach ( $guardado as $id => $roles ) {
		$u = get_userdata( (int) $id );
		if ( $u ) {
			foreach ( (array) $roles as $r ) {
				$u->add_role( (string) $r );
			}
		}
	}
	delete_option( ASDE_TEST_OPCION_LOCAL );
}

/**
 * Fuerza «el usuario es el nº local» (el valor de una instalación normal) mientras dura la prueba y devuelve el que
 * hubiera al acabar. Una ejecución cortada de tests/identidad.php puede dejar guardado el nº de Scouts de España, y
 * entonces fallan las pruebas que esperan los usuarios locales (acceso, alta-reglas, organizacion, solicitudes).
 */
function asde_test_identificador_local(): void {
	$opcion = \AsdeBalears\AreaPrivada\Domain\Organizacion::OPTION;
	$antes  = get_option( $opcion );
	if ( is_array( $antes ) && ( $antes['identificador'] ?? '' ) !== \AsdeBalears\AreaPrivada\Domain\Organizacion::IDENTIFICADOR_LOCAL ) {
		$nuevo                  = $antes;
		$nuevo['identificador'] = \AsdeBalears\AreaPrivada\Domain\Organizacion::IDENTIFICADOR_LOCAL;
		update_option( $opcion, $nuevo );
		\AsdeBalears\AreaPrivada\Domain\Organizacion::olvidar();
		register_shutdown_function(
			static function () use ( $opcion, $antes ) {
				update_option( $opcion, $antes );
				\AsdeBalears\AreaPrivada\Domain\Organizacion::olvidar();
			}
		);
	}
}
