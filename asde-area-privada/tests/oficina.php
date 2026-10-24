<?php
/**
 * Pruebas de la v0.50 (entorno LOCAL, doc 50, fase 2): credencial y rol de la Oficina.
 * Ejecutar desde app/public del site Local:
 *
 *   wp eval-file <ruta completa>/wp-content/plugins/asde-area-privada/tests/oficina.php
 *
 * No toca el CRM ni envía nada: solo opciones y un usuario de prueba (`prueba-oficina`), todo restaurado al terminar.
 */
defined( 'ABSPATH' ) || exit;

require_once ABSPATH . 'wp-admin/includes/user.php';

use AsdeBalears\AreaPrivada\Auth\Ambito;
use AsdeBalears\AreaPrivada\Crm\ClientFactory;
use AsdeBalears\AreaPrivada\Crm\Settings;
use AsdeBalears\AreaPrivada\Domain\Grupo;
use AsdeBalears\AreaPrivada\Domain\Oficina;
use AsdeBalears\AreaPrivada\Domain\Organizacion;
use AsdeBalears\AreaPrivada\Install\Roles;

$fails = 0;
$ok    = function ( $cond, string $label ) use ( &$fails ) {
	if ( ! $cond ) {
		++$fails;
	}
	echo ( $cond ? 'OK   ' : 'FAIL ' ), $label, "\n";
};

$antes_oficina = get_option( Oficina::OPTION, null );
$antes_tipo    = get_option( Organizacion::TIPO_OPCION, null );
$antes_user    = get_user_by( 'login', 'prueba-oficina' );
$antes_actual  = get_current_user_id();
$uid           = 0;
$uid_scouter   = 0;
$url_falsa     = static fn() => array( 'url' => 'https://crm.example.invalid' );

try {
	delete_option( Oficina::OPTION );
	$ok( ! Oficina::tiene_credencial() && null === Oficina::credenciales(), 'Sin guardar no hay credencial' );
	$ok( false === Oficina::guardar( '', 'x' ) && false === Oficina::guardar( 'of', '' ), 'No se guarda sin usuario ni (la primera vez) sin contraseña' );

	// Guardar y descifrar.
	$ok( true === Oficina::guardar( 'usuario-of', 'clave-secreta-123' ), 'Se guarda la credencial' );
	$cred = Oficina::credenciales();
	$ok( $cred && 'usuario-of' === $cred['usuario'] && 'clave-secreta-123' === $cred['clave'], 'Se descifra la contraseña' );
	$crudo = (string) wp_json_encode( get_option( Oficina::OPTION ) );
	$ok( ! str_contains( $crudo, 'clave-secreta-123' ), 'En la base de datos la contraseña va cifrada' );
	$ok( ! str_contains( (string) wp_json_encode( Oficina::datos() ), 'clave-secreta-123' ) && true === Oficina::datos()['tiene_clave'], 'datos() nunca lleva la contraseña' );
	global $wpdb;
	$autoload = $wpdb->get_var( $wpdb->prepare( 'SELECT autoload FROM %i WHERE option_name = %s', $wpdb->options, Oficina::OPTION ) );
	$ok( in_array( $autoload, array( 'off', 'no', 'auto-off' ), true ), 'La opción no se carga automáticamente' );

	// Cambiar solo el usuario conserva la contraseña; la última prueba se invalida.
	update_option( Oficina::OPTION, array_merge( (array) get_option( Oficina::OPTION ), array( 'estado' => 'ok', 'probado' => 5 ) ), false );
	Oficina::guardar( 'otro-usuario', '' );
	$cred = Oficina::credenciales();
	$ok( $cred && 'otro-usuario' === $cred['usuario'] && 'clave-secreta-123' === $cred['clave'], 'Una contraseña vacía conserva la guardada' );
	$ok( 'sin_probar' === Oficina::datos()['estado'] && 0 === Oficina::datos()['probado'], 'Cambiar la credencial invalida la última prueba' );

	// El cliente de la oficina.
	add_filter( 'pre_option_' . Settings::OPTION, $url_falsa );
	$ok( ! Settings::url_from_constant() ? '' !== Settings::url() : true, 'La prueba dispone de una URL de CRM falsa' );
	$ok( null !== ClientFactory::para_oficina(), 'Con credencial y URL hay cliente de la oficina' );
	Oficina::olvidar_credencial();
	$ok( null === ClientFactory::para_oficina(), 'Sin credencial no hay cliente de la oficina (falla cerrado)' );
	Oficina::guardar( 'usuario-of', 'clave-secreta-123' );

	// El rol: exactamente lo previsto y nada de edición.
	Roles::install();
	$rol = get_role( Roles::OFICINA );
	$ok( null !== $rol && in_array( Roles::OFICINA, Roles::ALL, true ), 'Existe el rol Oficina y es un rol del área privada' );
	foreach ( array( 'asde_access_area_privada', 'asde_multigrupo', 'asde_solo_lectura', 'asde_manage_users', 'asde_reports', 'asde_view_health' ) as $cap ) {
		$ok( $rol && ! empty( $rol->capabilities[ $cap ] ), 'El rol Oficina tiene ' . $cap );
	}
	$prohibidas = array( 'asde_tesoreria_acceso', 'asde_tesoreria_config', 'asde_tienda_comprar', 'asde_tienda_admin', 'asde_inventario', 'asde_eventos_gestion', 'asde_lista_espera', 'asde_reintegros', 'asde_edit_section_contact', 'asde_approve_changes', 'asde_crm_sync', 'asde_mis_pagos' );
	$sobra      = array_filter( $prohibidas, static fn( $c ) => $rol && ! empty( $rol->capabilities[ $c ] ) );
	$ok( ! $sobra, 'El rol Oficina no tiene ninguna capability de edición, tesorería, tienda, inventario ni eventos' . ( $sobra ? ' (' . implode( ', ', $sobra ) . ')' : '' ) );
	$ok( ! empty( get_role( Roles::SCOUTER )->capabilities['asde_reports'] ) && empty( get_role( Roles::SCOUTER )->capabilities['asde_multigrupo'] ), 'Los demás roles no tienen la capability multigrupo' );
	foreach ( Roles::ALL as $r ) {
		if ( Roles::OFICINA !== $r ) {
			$ok( empty( get_role( $r )->capabilities['asde_multigrupo'] ), 'Ningún otro rol (' . $r . ') puede elegir cualquier grupo' );
		}
	}

	// Usuarios de prueba.
	if ( $antes_user ) {
		wp_delete_user( $antes_user->ID );
	}
	$uid = wp_insert_user( array( 'user_login' => 'prueba-oficina', 'user_pass' => wp_generate_password( 24 ), 'user_email' => 'prueba-oficina@example.invalid', 'role' => Roles::OFICINA ) );
	$ok( is_int( $uid ), 'Se crea un usuario de prueba con el rol Oficina' );
	$uid_scouter = wp_insert_user( array( 'user_login' => 'prueba-oficina-scouter', 'user_pass' => wp_generate_password( 24 ), 'user_email' => 'prueba-oficina-scouter@example.invalid', 'role' => Roles::SCOUTER ) );

	$grupo_verificado = array( 'id' => '999', 'numero' => '999', 'nombre' => 'Grupo de prueba', 'usuario' => '', 'crm_id' => 'crm-de-prueba', 'filtrar' => true );
	$grupo_sin_crm    = array_merge( $grupo_verificado, array( 'crm_id' => '' ) );

	// reader(): web de grupo → no usa la credencial de la oficina; web de oficina → sí, solo con la capability y con el grupo verificado.
	Organizacion::guardar_tipo( Organizacion::TIPO_GRUPO );
	ClientFactory::olvidar();
	wp_set_current_user( $uid );
	Grupo::forzar( $grupo_verificado );
	$ok( null === ClientFactory::reader(), 'En una web de grupo, un usuario Oficina no lee con la credencial de la oficina' );

	Organizacion::guardar_tipo( Organizacion::TIPO_OFICINA );
	ClientFactory::olvidar();
	$ok( null !== ClientFactory::reader(), 'En la web de la oficina, el rol Oficina lee con la credencial de la oficina' );
	Grupo::forzar( $grupo_sin_crm );
	ClientFactory::olvidar();
	$ok( null === ClientFactory::reader(), 'Si el grupo elegido no está verificado, no se lee (falla cerrado)' );
	Grupo::forzar( $grupo_verificado );
	wp_set_current_user( $uid_scouter );
	ClientFactory::olvidar();
	$ok( null === ClientFactory::reader(), 'Un scouter no usa la credencial de la oficina ni siquiera en la web de la oficina' );
	wp_set_current_user( $uid );
	Oficina::olvidar_credencial();
	$ok( null === ClientFactory::reader(), 'Sin credencial de la oficina no hay lectura' );
	Oficina::guardar( 'usuario-of', 'clave-secreta-123' );

	// Ámbito: la oficina entra sin ficha, solo en una web de oficina.
	Ambito::olvidar( $uid );
	$a = Ambito::de( get_userdata( $uid ) );
	$ok( true === $a->es_oficina() && true === $a->puede_acceder(), 'En la web de la oficina, el rol Oficina puede acceder sin ficha' );
	Organizacion::guardar_tipo( Organizacion::TIPO_GRUPO );
	Ambito::olvidar( $uid );
	$a = Ambito::de( get_userdata( $uid ) );
	$ok( false === $a->es_oficina() && false === $a->puede_acceder(), 'En una web de grupo, el rol Oficina no entra' );
	Organizacion::guardar_tipo( Organizacion::TIPO_OFICINA );
	Ambito::olvidar( $uid_scouter );
	$ok( false === Ambito::de( get_userdata( $uid_scouter ) )->es_oficina(), 'Un scouter no es Oficina' );

	// Nadie desde un grupo puede asignar el rol Oficina al dar de alta a alguien.
	$ok( ! in_array( Roles::OFICINA, \AsdeBalears\AreaPrivada\Usuarios\AltaService::ROLES_PERSONA, true ), 'El alta de personas del grupo no ofrece el rol Oficina' );
} catch ( Throwable $e ) {
	++$fails;
	echo 'FAIL excepcion: ', $e->getMessage(), ' (', basename( $e->getFile() ), ':', $e->getLine(), ")\n";
} finally {
	wp_set_current_user( $antes_actual );
	Grupo::forzar( null );
	remove_filter( 'pre_option_' . Settings::OPTION, $url_falsa );
	foreach ( array( $uid, $uid_scouter ) as $u ) {
		if ( is_int( $u ) && $u > 0 ) {
			wp_delete_user( $u );
		}
	}
	if ( null === $antes_oficina ) {
		delete_option( Oficina::OPTION );
	} else {
		update_option( Oficina::OPTION, $antes_oficina, false );
	}
	if ( null === $antes_tipo ) {
		delete_option( Organizacion::TIPO_OPCION );
	} else {
		update_option( Organizacion::TIPO_OPCION, $antes_tipo, false );
	}
	ClientFactory::olvidar();
	Ambito::olvidar( 0 );
}

echo $fails ? "\nHAY $fails FALLOS\n" : "\nTODO OK\n";
