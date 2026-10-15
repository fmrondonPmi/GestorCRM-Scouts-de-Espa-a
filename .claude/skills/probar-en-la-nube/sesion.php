<?php
/**
 * Crea (si no existen) cuentas de demostración vinculadas a las fichas inventadas de crm-demo.php y
 * devuelve sus cookies de sesión en JSON, para entrar con Chrome sin pasar por el formulario.
 * Uso: wp eval-file sesion.php > sesion.json
 */
use AsdeBalears\AreaPrivada\Install\Roles;
use AsdeBalears\AreaPrivada\Usuarios\Usuarios;

$grupo  = \AsdeBalears\AreaPrivada\Domain\Grupos::por_numero( '684' );
$cuenta = static function ( string $login, array $roles, array $meta ) use ( $grupo ): int {
	$u = get_user_by( 'login', $login );
	if ( $u ) {
		return $u->ID;
	}
	$id = wp_insert_user(
		array(
			'user_login'   => $login,
			'user_pass'    => wp_generate_password( 24 ),
			'role'         => $roles[0],
			'display_name' => $login,
		)
	);
	$u  = get_userdata( $id );
	foreach ( array_slice( $roles, 1 ) as $r ) {
		$u->add_role( $r );
	}
	update_user_meta( $id, Usuarios::META_GRUPO, $grupo['id'] );
	update_user_meta( $id, Usuarios::META_ESTADO, Usuarios::ACTIVO );
	foreach ( $meta as $k => $v ) {
		foreach ( (array) $v as $valor ) {
			add_user_meta( $id, $k, $valor );
		}
	}
	return $id;
};
// scouter de Lobatos y Scouts; Tesorería; Coordinación; familia de Lucía.
$ids = array(
	'scouter'      => $cuenta( 'demo-ana', array( Roles::SCOUTER ), array( Usuarios::META_SOCIO => '990043', Usuarios::META_SECCION => array( 'lobatos', 'scouts' ) ) ),
	'tesoreria'    => $cuenta( 'demo-pol', array( Roles::SCOUTER, Roles::TESORERIA ), array( Usuarios::META_SOCIO => '990046', Usuarios::META_SECCION => 'escultas' ) ),
	'coordinacion' => $cuenta( 'demo-eva', array( Roles::SCOUTER, Roles::COORDINACION ), array( Usuarios::META_SOCIO => '990045', Usuarios::META_SECCION => 'rovers' ) ),
	'familia'      => $cuenta( 'demo-familia', array( Roles::TUTOR ), array( Usuarios::META_REPRESENTADO => '990041' ) ),
);
$out = array();
foreach ( $ids as $k => $id ) {
	$caduca    = time() + DAY_IN_SECONDS;
	$out[ $k ] = array(
		'name'  => LOGGED_IN_COOKIE,
		'value' => wp_generate_auth_cookie( $id, $caduca, 'logged_in', WP_Session_Tokens::get_instance( $id )->create( $caduca ) ),
	);
}
echo wp_json_encode(
	array(
		'cookies' => $out,
		'area'    => \AsdeBalears\AreaPrivada\Frontend\Pages::url( 'area' ),
	)
);
