<?php
/**
 * Pruebas de la v0.50 (entorno LOCAL, doc 50, fase 7): primer usuario de acceso de un grupo aprobado.
 * Ejecutar desde app/public del site Local:
 *
 *   wp eval-file <ruta completa>/wp-content/plugins/asde-area-privada/tests/alta-grupo-primer-usuario.php
 *
 * El CRM es simulado (nada sale a la red) y los emails se capturan. Borra las cuentas y los grupos que crea.
 */
defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/lib/CrmSimulado.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Crm\ClientFactory;
use AsdeBalears\AreaPrivada\Domain\GrupoAltas;
use AsdeBalears\AreaPrivada\Domain\GrupoSolicitudes;
use AsdeBalears\AreaPrivada\Domain\Grupos;
use AsdeBalears\AreaPrivada\Domain\Organizacion;
use AsdeBalears\AreaPrivada\Install\Roles;
use AsdeBalears\AreaPrivada\Usuarios\RegistroEmails;
use AsdeBalears\AreaPrivada\Usuarios\Usuarios;

global $wpdb;

$fails = 0;
$ok    = function ( $cond, string $label ) use ( &$fails ) {
	if ( ! $cond ) {
		++$fails;
	}
	echo ( $cond ? 'OK   ' : 'FAIL ' ), $label, "\n";
};

$max_id = static fn( string $t ) => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(MAX(id), 0) FROM %i', $t ) ); // phpcs:ignore WordPress.DB
$sol0   = $max_id( GrupoSolicitudes::table() );
$log0   = $max_id( Logger::table() );
$mail0  = $max_id( RegistroEmails::table() );
$user0  = (int) $wpdb->get_var( "SELECT COALESCE(MAX(ID), 0) FROM {$wpdb->users}" ); // phpcs:ignore WordPress.DB
$tipo0  = get_option( Organizacion::TIPO_OPCION, null );

$capturados = array();
$captura    = static function ( $pre, $atts ) use ( &$capturados ) {
	$capturados[] = $atts;
	return true;
};
add_filter( 'pre_wp_mail', $captura, 10, 2 );

$P   = array( 'Asde_Prueba_Crm', 'persona' );
$crm = new Asde_Prueba_Crm(
	array(
		$P( 61, 'Ana', 'Prueba Uno', '1985-01-01', 'Scouters', array( 'stic_identification_number_c' => '11111111H', 'numsocioantiguo_enficha_c' => '79040001' ) ),
		$P( 62, 'Biel', 'Prueba Dos', '1990-02-02', 'Scouters', array( 'stic_identification_number_c' => '22222222J' ) ),
	),
	'sg-901'
);
$crm->activar();

$nueva = static function ( string $numero, string $dni, string $rol ): int {
	return GrupoSolicitudes::crear(
		0,
		array(
			'numero'       => $numero,
			'nombre'       => 'Grupo de prueba ' . $numero,
			'sede'         => '',
			'email'        => 'grupo' . $numero . '@example.invalid',
			'cif'          => '',
			'denominacion' => '',
			'rol'          => $rol,
			'crm_id'       => 'sg-901',
			'usuario'      => 'usuario-' . $numero,
			'clave'        => 'Clave Secreta ' . $numero,
			'dni'          => $dni,
			'persona'      => 'Persona Inventada',
		)
	);
};

try {
	Organizacion::guardar_tipo( Organizacion::TIPO_OFICINA );
	$ok( array( 'secretaria', 'coordinacion' ) === array_keys( GrupoSolicitudes::ROLES ), 'El primer usuario solo puede ser de Secretaría o Coordinación' );

	echo "== A. La ficha existe ==\n";
	$crm->numgrupo = '904';
	$id            = $nueva( '904', '11111111h', 'coordinacion' );
	$capturados    = array();
	$r             = GrupoAltas::aprobar( $id );
	$ok( is_array( $r ), 'Se aprueba el grupo' );
	$s = GrupoSolicitudes::obtener( $id );
	$ok( GrupoSolicitudes::USUARIO_INVITADO === $s['usuario_estado'] && '' === $s['dni_enc'] && '' === $s['persona_enc'], 'Con la ficha encontrada por su DNI queda invitado, y se olvida el DNI y el nombre' );
	$u = Usuarios::por_socio( '990061' );
	$ok( $u && in_array( Roles::COORDINACION, (array) $u->roles, true ) && '904' === (string) get_user_meta( $u->ID, Usuarios::META_GRUPO, true ), 'Existe la cuenta, con el rol Coordinación y en el grupo nuevo' );
	$para = array_map( static fn( $c ) => $c['to'], $capturados );
	$ok( in_array( 'grupo904@example.invalid', $para, true ), 'La invitación se manda al email del grupo (no al de la ficha)' );
	$invit = array_values( array_filter( $capturados, static fn( $c ) => str_contains( (string) $c['subject'], 'Bienvenida' ) ) );
	$ok( 1 === count( $invit ) && ! str_contains( (string) $invit[0]['message'], 'Clave Secreta' ), 'Es la invitación de bienvenida, sin la credencial del CRM' );
	$capturados = array();
	$ok( GrupoSolicitudes::USUARIO_INVITADO === GrupoAltas::primer_usuario( $id ) && ! $capturados, 'Pedirlo otra vez no manda otra invitación ni duplica la cuenta' );
	$ok( 1 === count( get_users( array( 'meta_key' => Usuarios::META_SOCIO, 'meta_value' => '990061' ) ) ), 'Una sola cuenta para esa ficha' ); // phpcs:ignore WordPress.DB.SlowDBQuery

	echo "\n== B. La ficha no existe todavía ==\n";
	$crm->numgrupo = '905';
	$id2           = $nueva( '905', '99999999R', 'secretaria' );
	$capturados    = array();
	$r2            = GrupoAltas::aprobar( $id2 );
	$s2            = GrupoSolicitudes::obtener( $id2 );
	$ok( is_array( $r2 ) && null !== Grupos::por_numero( '905' ) && GrupoSolicitudes::APROBADA === $s2['estado'], 'El grupo queda aprobado igualmente' );
	$ok( GrupoSolicitudes::USUARIO_SIN_FICHA === $s2['usuario_estado'] && '' !== $s2['dni_enc'] && '' !== $s2['persona_enc'], 'Sin ficha por ese DNI: sin usuario y se conserva lo necesario para reintentar' );
	$ok( ! array_filter( $capturados, static fn( $c ) => str_contains( (string) $c['subject'], 'Bienvenida' ) ), 'No se manda ninguna invitación de cuenta' );
	// Se corrige el CRM: aparece la ficha.
	$crm->personas[] = $P( 63, 'Cai', 'Prueba Tres', '1988-03-03', 'Scouters', array( 'stic_identification_number_c' => '99999999R', 'numsocioantiguo_enficha_c' => '79050003' ) );
	$capturados      = array();
	$estado          = GrupoAltas::primer_usuario( $id2 );
	$u2              = Usuarios::por_socio( '990063' );
	$ok( GrupoSolicitudes::USUARIO_INVITADO === $estado && $u2 && in_array( Roles::SECRETARIA, (array) $u2->roles, true ), 'Reintentar tras corregir el CRM crea la cuenta de Secretaría' );
	$ok( in_array( 'grupo905@example.invalid', array_map( static fn( $c ) => $c['to'], $capturados ), true ), 'Y manda la invitación al email del grupo' );

	echo "\n== C. Casos que no deben crear nada ==\n";
	$id3 = $nueva( '906', '', 'secretaria' );
	$crm->numgrupo = '906';
	GrupoAltas::aprobar( $id3 );
	$ok( GrupoSolicitudes::USUARIO_ERROR === GrupoSolicitudes::obtener( $id3 )['usuario_estado'], 'Sin DNI no se crea ningún usuario' );
	$ok( '' === GrupoAltas::primer_usuario( 999999 ), 'Una solicitud que no existe no hace nada' );
	$pend = $nueva( '907', '22222222J', 'secretaria' );
	$ok( GrupoSolicitudes::PENDIENTE === GrupoSolicitudes::obtener( $pend )['estado'] && '' === GrupoAltas::primer_usuario( $pend ), 'Una solicitud sin aprobar no crea usuarios' );
	$ok( null === Usuarios::por_socio( '990062' ), 'La ficha del DNI de una solicitud pendiente no tiene cuenta' );
} finally {
	$crm->desactivar();
	remove_filter( 'pre_wp_mail', $captura, 10 );
	foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->users} WHERE ID > %d", $user0 ) ) as $uid ) { // phpcs:ignore WordPress.DB
		wp_delete_user( (int) $uid );
	}
	foreach ( array( '904', '905', '906', '907' ) as $g ) {
		Grupos::borrar( $g );
	}
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id > %d', GrupoSolicitudes::table(), $sol0 ) ); // phpcs:ignore WordPress.DB
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id > %d', Logger::table(), $log0 ) ); // phpcs:ignore WordPress.DB
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id > %d', RegistroEmails::table(), $mail0 ) ); // phpcs:ignore WordPress.DB
	if ( null === $tipo0 ) {
		delete_option( Organizacion::TIPO_OPCION );
	} else {
		update_option( Organizacion::TIPO_OPCION, $tipo0, false );
	}
	ClientFactory::olvidar();
}

echo $fails ? "\nHAY $fails FALLOS\n" : "\nTODO OK\n";
