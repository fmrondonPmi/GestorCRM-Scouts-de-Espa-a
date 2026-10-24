<?php
/**
 * Pruebas de la v0.44 (entorno LOCAL, doc 50, fase 1): tipo de instalación Grupo / Oficina.
 * Ejecutar desde app/public del site Local:
 *
 *   wp eval-file <ruta completa>/wp-content/plugins/asde-area-privada/tests/modo-instalacion.php
 *
 * No toca el CRM. Al terminar restaura la opción tal como estaba.
 */
defined( 'ABSPATH' ) || exit;

use AsdeBalears\AreaPrivada\Domain\Organizacion;

$fails = 0;
$ok    = function ( $cond, string $label ) use ( &$fails ) {
	if ( ! $cond ) {
		++$fails;
	}
	echo ( $cond ? 'OK   ' : 'FAIL ' ), $label, "\n";
};

$antes = get_option( Organizacion::TIPO_OPCION, null );

try {
	// Sin guardar: lo de siempre (regla 5 del CLAUDE.md).
	delete_option( Organizacion::TIPO_OPCION );
	$ok( ! defined( Organizacion::TIPO_CONST ), 'La prueba corre sin la constante ASDE_AP_MODO' );
	$ok( null === Organizacion::tipo_fijado_en_codigo(), 'Sin constante, el tipo no lo fija el código' );
	$ok( Organizacion::TIPO_GRUPO === Organizacion::tipo_instalacion(), 'Sin guardar, la instalación es de Grupo' );
	$ok( false === Organizacion::es_oficina(), 'Sin guardar, no es de Oficina' );

	// Cambiar a Oficina y volver.
	$ok( true === Organizacion::guardar_tipo( Organizacion::TIPO_OFICINA ), 'Se puede guardar Oficina' );
	$ok( Organizacion::TIPO_OFICINA === Organizacion::tipo_instalacion(), 'Ahora es de Oficina' );
	$ok( true === Organizacion::es_oficina(), 'es_oficina() lo dice' );
	$ok( true === Organizacion::guardar_tipo( Organizacion::TIPO_GRUPO ), 'Se puede volver a Grupo' );
	$ok( false === Organizacion::es_oficina(), 'Vuelve a no ser de Oficina' );

	// Valores no válidos: no cambian nada.
	Organizacion::guardar_tipo( Organizacion::TIPO_OFICINA );
	foreach ( array( '', 'OFICINA', 'federacion', 'grupo ' ) as $malo ) {
		$ok( false === Organizacion::guardar_tipo( $malo ), 'Valor no válido rechazado: «' . $malo . '»' );
	}
	$ok( Organizacion::TIPO_OFICINA === Organizacion::tipo_instalacion(), 'Un valor no válido no cambia el tipo guardado' );

	// Un valor corrupto en la base de datos cae en Grupo (nunca en Oficina por error).
	update_option( Organizacion::TIPO_OPCION, 'cualquier-cosa', false );
	$ok( Organizacion::TIPO_GRUPO === Organizacion::tipo_instalacion(), 'Un valor corrupto cuenta como Grupo' );
	update_option( Organizacion::TIPO_OPCION, array( 'oficina' ), false );
	$ok( Organizacion::TIPO_GRUPO === Organizacion::tipo_instalacion(), 'Un valor que no es texto cuenta como Grupo' );

	// No se guarda con autoload (no pesa en cada petición) ni toca la opción de la organización.
	global $wpdb;
	Organizacion::guardar_tipo( Organizacion::TIPO_GRUPO );
	$autoload = $wpdb->get_var( $wpdb->prepare( 'SELECT autoload FROM %i WHERE option_name = %s', $wpdb->options, Organizacion::TIPO_OPCION ) );
	$ok( in_array( $autoload, array( 'off', 'no', 'auto-off' ), true ), 'La opción no se carga automáticamente (' . $autoload . ')' );
} catch ( Throwable $e ) {
	++$fails;
	echo 'FAIL excepcion: ', $e->getMessage(), ' (', basename( $e->getFile() ), ':', $e->getLine(), ")\n";
} finally {
	if ( null === $antes ) {
		delete_option( Organizacion::TIPO_OPCION );
	} else {
		update_option( Organizacion::TIPO_OPCION, $antes, false );
	}
}

echo $fails ? "\nHAY $fails FALLOS\n" : "\nTODO OK\n";
