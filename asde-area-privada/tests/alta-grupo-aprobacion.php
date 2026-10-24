<?php
/**
 * Pruebas de la v0.50 (entorno LOCAL, doc 50, fase 6): aprobar o rechazar el alta de un grupo.
 * Ejecutar desde app/public del site Local:
 *
 *   wp eval-file <ruta completa>/wp-content/plugins/asde-area-privada/tests/alta-grupo-aprobacion.php
 *
 * El CRM es simulado (nada sale a la red) y no se envía ningún email. Restaura todo al terminar.
 */
defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/lib/CrmSimulado.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

use AsdeBalears\AreaPrivada\Admin\GruposAltasPage;
use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Crm\ClientFactory;
use AsdeBalears\AreaPrivada\Crm\Crypto;
use AsdeBalears\AreaPrivada\Domain\GrupoAltas;
use AsdeBalears\AreaPrivada\Domain\GrupoSolicitudes;
use AsdeBalears\AreaPrivada\Domain\Grupos;
use AsdeBalears\AreaPrivada\Domain\Organizacion;
use AsdeBalears\AreaPrivada\Sepa\Config as SepaConfig;
use AsdeBalears\AreaPrivada\Usuarios\RegistroEmails;

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
$tipo0  = get_option( Organizacion::TIPO_OPCION, null );
$actual = get_current_user_id();
$sepa0  = get_option( 'asde_ap_sepa_901', null );
$admin  = 0;
// Doc 60: el finally borra los grupos 901, 902 y 903; si alguno existe (otra prueba cortada o uno real), no se sigue.
foreach ( array( '901', '902', '903' ) as $g ) {
	if ( Grupos::por_numero( $g ) ) {
		exit( "El grupo $g existe en el Local (¿una ejecución anterior interrumpida?). Revísalo antes de repetir la prueba.\n" );
	}
}
$sepa_684_antes = SepaConfig::de( '684' ); // Para comprobar que aprobar otro grupo no cambia nada del 684.
$cache_externa  = wp_using_ext_object_cache( true ); // La sesión del CRM simulado y las cachés, solo en memoria.

$capturados = array();
$captura    = static function ( $pre, $atts ) use ( &$capturados ) {
	$capturados[] = $atts;
	return true;
};
add_filter( 'pre_wp_mail', $captura, 10, 2 );

$crm = new Asde_Prueba_Crm( array(), 'sg-901' );
$crm->numgrupo = '901';
$crm->activar();

$nueva = static function ( string $numero, string $nombre ): int {
	return GrupoSolicitudes::crear(
		0,
		array(
			'numero'       => $numero,
			'nombre'       => $nombre,
			'sede'         => 'Calle Inventada 1',
			'email'        => 'grupo' . $numero . '@example.invalid',
			'cif'          => 'G12345678',
			'denominacion' => 'Asociación Inventada ' . $numero,
			'rol'          => 'coordinacion',
			'crm_id'       => 'sg-' . $numero,
			'usuario'      => 'usuario-' . $numero,
			'clave'        => 'Clave Secreta ' . $numero,
			'dni'          => '00000000T',
			'persona'      => 'Persona Inventada',
		)
	);
};

try {
	Organizacion::guardar_tipo( Organizacion::TIPO_OFICINA );
	$ok( null === Grupos::por_numero( '901' ) && null === Grupos::por_numero( '902' ), 'Control: no existen ya los grupos 901 y 902' );

	echo "== A. Aprobar ==\n";
	$id = $nueva( '901', 'Grupo de prueba 901' );
	$ok( $id > 0 && GrupoSolicitudes::pendiente_con_numero( '901' ), 'Hay una solicitud pendiente' );
	// El CRM no responde: no se aprueba y no queda nada a medias.
	$crm->caido = true;
	$r          = GrupoAltas::aprobar( $id );
	$ok( is_wp_error( $r ) && null === Grupos::por_numero( '901' ) && 'pendiente' === GrupoSolicitudes::obtener( $id )['estado'], 'Si la credencial ya no funciona en ese momento, no se aprueba ni se crea el grupo' );
	$crm->caido = false;
	$capturados = array();
	$r          = GrupoAltas::aprobar( $id );
	$g          = Grupos::por_numero( '901' );
	$ok( is_array( $r ) && $g && Grupos::OK === $g['estado'] && 'sg-901' === $g['crm_id'], 'Aprobar crea el grupo verificado, con el id de su grupo de seguridad' );
	$ok( 'Calle Inventada 1' === $g['sede'] && 'G12345678' === $g['cif'] && 'Asociación Inventada 901' === $g['denominacion'] && 'grupo901@example.invalid' === $g['email_contacto'], 'Con sede, CIF, denominación fiscal y email de contacto' );
	$ok( 'usuario-901' === Grupos::credenciales( '901' )['usuario'] && 'Clave Secreta 901' === Grupos::credenciales( '901' )['clave'], 'La credencial del grupo funciona (descifrable solo por el sistema)' );
	$ok( ! str_contains( (string) wp_json_encode( get_option( 'asde_ap_grupos' ) ), 'Clave Secreta 901' ) && ! isset( Grupos::todos()['901']['clave_enc'] ), 'La contraseña va cifrada en la opción y no sale en la lista de grupos' );
	$s = GrupoSolicitudes::obtener( $id );
	$ok( 'aprobada' === $s['estado'] && '' === $s['usuario_enc'] && '' === $s['clave_enc'] && '' !== $s['dni_enc'] && '' !== $s['persona_enc'], 'La solicitud pasa a aprobada y suelta la credencial (DNI y nombre se conservan para el primer acceso)' );
	$ok( 1 === count( $capturados ) && 'grupo901@example.invalid' === $capturados[0]['to'] && ! str_contains( (string) $capturados[0]['message'], 'Clave Secreta' ) && ! str_contains( (string) $capturados[0]['message'], 'usuario-901' ), 'El grupo recibe un aviso que no lleva la credencial' );
	$ok( is_wp_error( GrupoAltas::aprobar( $id ) ), 'No se puede aprobar dos veces' );
	$id_dup = $nueva( '901', 'Otro grupo con el mismo nº' );
	$ok( is_wp_error( GrupoAltas::aprobar( $id_dup ) ) && 'pendiente' === GrupoSolicitudes::obtener( $id_dup )['estado'], 'Un nº de grupo que ya existe no se aprueba' );
	GrupoAltas::rechazar( $id_dup );

	echo "\n== B. Rechazar ==\n";
	$id2        = $nueva( '902', 'Grupo de prueba 902' );
	$capturados = array();
	$ok( true === GrupoAltas::rechazar( $id2 ), 'Se rechaza una pendiente' );
	$s = GrupoSolicitudes::obtener( $id2 );
	$ok( 'rechazada' === $s['estado'] && '' === $s['usuario_enc'] && '' === $s['clave_enc'] && '' === $s['dni_enc'] && '' === $s['persona_enc'], 'Rechazar borra todo lo secreto guardado' );
	$ok( null === Grupos::por_numero( '902' ) && false === GrupoAltas::rechazar( $id2 ), 'No crea el grupo y no se rechaza otra vez' );
	$ok( 1 === count( $capturados ) && str_contains( (string) $capturados[0]['message'], 'no ha podido' ) && ! str_contains( (string) $capturados[0]['message'], 'Clave Secreta' ), 'El aviso es genérico, sin motivo ni credencial' );
	$fila_reg = (array) $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id > %d AND tipo = %s ORDER BY id DESC LIMIT 1', RegistroEmails::table(), $mail0, RegistroEmails::ALTA_GRUPO ), ARRAY_A ); // phpcs:ignore WordPress.DB
	$vista    = $fila_reg ? RegistroEmails::vista_previa( $fila_reg ) : '';
	$ok( str_contains( $vista, 'no ha podido' ) && str_contains( $vista, 'Grupo de prueba 902' ) && ! str_contains( $vista, 'Activar mi cuenta' ), 'v0.57: la vista previa del registro es el aviso del rechazo (no la invitación de acceso)' );
	$ok( false === GrupoSolicitudes::pendiente_con_numero( '902' ), 'El nº queda libre para volver a intentarlo' );

	echo "\n== C. Pantalla de la oficina: nunca la credencial ==\n";
	$id3   = $nueva( '903', 'Grupo de prueba 903' );
	$viejo = get_user_by( 'login', 'prueba-ga-admin' );
	if ( $viejo ) {
		wp_delete_user( $viejo->ID );
	}
	$admin = wp_insert_user( array( 'user_login' => 'prueba-ga-admin', 'user_pass' => wp_generate_password( 24 ), 'user_email' => 'prueba-ga-admin@example.invalid', 'role' => 'administrator' ) );
	wp_set_current_user( $admin );
	$_GET = array( 'alta' => (string) $id3 );
	ob_start();
	( new GruposAltasPage() )->render();
	$detalle = (string) ob_get_clean();
	$_GET    = array();
	ob_start();
	( new GruposAltasPage() )->render();
	$lista = (string) ob_get_clean();
	$ok( str_contains( $detalle, 'Grupo de prueba 903' ) && str_contains( $detalle, 'Aprobar y dar de alta' ) && str_contains( $detalle, 'Credencial guardada y comprobada' ), 'El detalle enseña los datos y dice que la credencial está comprobada' );
	$ok( ! str_contains( $detalle . $lista, 'Clave Secreta' ) && ! str_contains( $detalle . $lista, 'usuario-903' ) && ! str_contains( $detalle . $lista, '00000000T' ) && ! str_contains( $detalle . $lista, 'Persona Inventada' ), 'Ni el detalle ni la lista muestran usuario, contraseña, DNI ni el nombre de la primera persona' );
	$ok( str_contains( $lista, 'Grupo de prueba 903' ), 'La lista enseña la solicitud pendiente' );
	GrupoAltas::rechazar( $id3 );

	echo "\n== D. Los datos del grupo se usan ==\n";
	$ok( 'Asociación Inventada 901 · CIF G12345678 · Calle Inventada 1' === Grupos::membrete( Grupos::por_numero( '901' ) ) && '' === Grupos::membrete( array( 'numero' => '1' ) ), 'Membrete para documentos: denominación, CIF y sede (vacío si no hay)' );
	delete_option( 'asde_ap_sepa_901' );
	$c = SepaConfig::de( '901' );
	$ok( 'Asociación Inventada 901' === $c['nombre'] && 'Calle Inventada 1' === $c['calle'], 'SEPA: el acreedor y la calle empiezan con la denominación fiscal y la sede' );
	// Doc 60: antes era un OR que se cumplía siempre ('' !== nombre); ahora se compara con lo que había al empezar.
	$ok( $sepa_684_antes === SepaConfig::de( '684' ), 'el acreedor SEPA de otro grupo (684) queda exactamente como estaba' );
} catch ( Throwable $e ) {
	++$fails;
	echo 'FAIL excepcion: ', $e->getMessage(), ' (', basename( $e->getFile() ), ':', $e->getLine(), ")\n";
} finally {
	$crm->desactivar();
	remove_filter( 'pre_wp_mail', $captura, 10 );
	wp_set_current_user( $actual );
	if ( $admin ) {
		wp_delete_user( $admin );
	}
	Grupos::borrar( '901' );
	Grupos::borrar( '902' );
	Grupos::borrar( '903' );
	if ( null === $sepa0 ) {
		delete_option( 'asde_ap_sepa_901' );
	} else {
		update_option( 'asde_ap_sepa_901', $sepa0, false );
	}
	// Solo lo de la prueba (doc 60; antes borraba todo el registro y todos los emails posteriores al inicio).
	$solic   = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i WHERE id > %d', GrupoSolicitudes::table(), $sol0 ) ) ); // phpcs:ignore WordPress.DB
	$l_solic = "'" . implode( "','", $solic ?: array( 0 ) ) . "'";
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id > %d', GrupoSolicitudes::table(), $sol0 ) ); // phpcs:ignore WordPress.DB
	$wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE id > %d AND ( user_id = %d OR ( objeto = 'solicitud_grupo' AND objeto_id IN ($l_solic) ) )", Logger::table(), $log0, (int) $admin ) ); // phpcs:ignore WordPress.DB
	$wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE id > %d AND ( grupo IN ('901','902','903') OR destino LIKE %s )", RegistroEmails::table(), $mail0, '%.invalid' ) ); // phpcs:ignore WordPress.DB
	if ( null === $tipo0 ) {
		delete_option( Organizacion::TIPO_OPCION );
	} else {
		update_option( Organizacion::TIPO_OPCION, $tipo0, false );
	}
	ClientFactory::olvidar();
	wp_using_ext_object_cache( $cache_externa );
	Grupos::olvidar();
	$_GET = array();
	$ok( null === Grupos::por_numero( '901' ) && null === Grupos::por_numero( '902' ) && null === Grupos::por_numero( '903' ) && false === get_user_by( 'login', 'prueba-ga-admin' ), 'limpieza: sin los grupos ni la cuenta de la prueba' );
}

echo $fails ? "\nHAY $fails FALLOS\n" : "\nTODO OK\n";
