<?php
/**
 * Pruebas de la protección de los formularios públicos (entorno LOCAL; doc 60): el límite de peticiones por
 * dispositivo (Auth\Limite) y la clave secreta de los enlaces públicos de cada grupo (Domain\ClaveDeGrupo, que usan
 * Usuarios\EnlaceScouters, ListaEspera\Enlaces y Tienda\Enlace). Ejecutar desde app/public:
 *
 *   wp eval-file wp-content/plugins/asde-area-privada/tests/enlaces-publicos.php
 *
 * (o, desde herramientas-desarrollo: ./probar-todo.sh enlaces-publicos)
 *
 * - A: Limite::permitir() cuenta por acción y por IP, corta al llegar al máximo (sin contar los rechazos) y vuelve a
 *   dejar pasar cuando acaba la ventana.
 * - B: la clave del enlace de un grupo no vale en otro, renovarla invalida solo la de ese grupo y ese enlace, y las
 *   tres clases guardan claves independientes.
 * No usa el CRM. Los contadores van en la memoria del proceso (wp_using_ext_object_cache) y las tres opciones de
 * claves se restauran al terminar. Los grupos 994 y 995 son arrays ficticios (no se dan de alta).
 */
defined( 'ABSPATH' ) || exit;

use AsdeBalears\AreaPrivada\Auth\Limite;
use AsdeBalears\AreaPrivada\ListaEspera\Enlaces as EnlaceEspera;
use AsdeBalears\AreaPrivada\Tienda\Enlace as EnlaceTienda;
use AsdeBalears\AreaPrivada\Usuarios\EnlaceScouters;

global $wpdb;

$fails = 0;
$ok    = function ( $cond, string $label ) use ( &$fails ) {
	if ( ! $cond ) {
		++$fails;
	}
	echo ( $cond ? 'OK   ' : 'FAIL ' ), $label, "\n";
};

// --- Lo que se restaura al final ---------------------------------------------
$clases   = array( EnlaceScouters::class, EnlaceEspera::class, EnlaceTienda::class );
$opciones = array();
foreach ( $clases as $c ) {
	$opciones[ $c::OPCION ] = get_option( $c::OPCION, null );
}
$ip_antes      = $_SERVER['REMOTE_ADDR'] ?? null;
$cache_externa = wp_using_ext_object_cache( true );
$transients    = static fn(): int => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_asde\\_ap\\_lim\\_%'" ); // phpcs:ignore WordPress.DB
$tr_antes      = $transients();

try {
	echo "== Parte A · Límite de peticiones por dispositivo ==\n";
	$accion                 = 'prueba_limite_' . wp_generate_password( 6, false );
	$_SERVER['REMOTE_ADDR'] = '192.0.2.10';
	$pasan                  = array();
	for ( $i = 0; $i < 5; $i++ ) {
		$pasan[] = Limite::permitir( $accion, 3, HOUR_IN_SECONDS );
	}
	$ok( array( true, true, true, false, false ) === $pasan, 'con un máximo de 3: pasan las 3 primeras y se cortan las siguientes' );
	$_SERVER['REMOTE_ADDR'] = '192.0.2.11';
	$ok( true === Limite::permitir( $accion, 3, HOUR_IN_SECONDS ), 'otro dispositivo (otra IP) tiene su propio contador' );
	$_SERVER['REMOTE_ADDR'] = '192.0.2.10';
	$ok( true === Limite::permitir( $accion . '_otra', 3, HOUR_IN_SECONDS ), 'y otra acción también, desde la misma IP' );
	$ok( false === Limite::permitir( $accion, 3, HOUR_IN_SECONDS ), 'la primera IP sigue cortada en su acción' );
	$ok( $tr_antes === $transients(), 'los contadores no se han guardado en la base de datos (van en memoria durante la prueba)' );

	$corta = $accion . '_ventana';
	$ok( true === Limite::permitir( $corta, 1, 1 ) && false === Limite::permitir( $corta, 1, 1 ), 'con una ventana de 1 s y máximo 1: la segunda se corta' );
	sleep( 2 );
	$ok( true === Limite::permitir( $corta, 1, 1 ), 'acabada la ventana, vuelve a dejar pasar' );
	$ok( false === Limite::permitir( $accion . '_cero', 0, HOUR_IN_SECONDS ), 'con un máximo de 0 no pasa ninguna' );

	echo "\n== Parte B · Claves de los enlaces públicos de cada grupo ==\n";
	$g1 = array( 'id' => '994' );
	$g2 = array( 'id' => '995' );
	foreach ( $clases as $c ) {
		$corto = substr( strrchr( $c, '\\' ), 1 );
		$k1    = $c::clave( $g1 );
		$k2    = $c::clave( $g2 );
		$ok( 24 === strlen( $k1 ) && $k1 === $c::clave( $g1 ) && $k1 !== $k2, "$corto: cada grupo tiene su clave de 24 caracteres y no cambia al volver a pedirla" );
		$ok( $c::valida( $g1, $k1 ) && ! $c::valida( $g2, $k1 ) && ! $c::valida( $g1, $k2 ), "$corto: la clave de un grupo no vale en el otro" );
		$ok( ! $c::valida( $g1, '' ) && ! $c::valida( $g1, strtoupper( $k1 ) === $k1 ? strtolower( $k1 ) . 'x' : strtoupper( $k1 ) ) && ! $c::valida( array( 'id' => '993' ), '' ), "$corto: ni vacía ni cambiando mayúsculas, ni en un grupo sin clave" );
		$n1 = $c::renovar( $g1 );
		$ok( $n1 !== $k1 && ! $c::valida( $g1, $k1 ) && $c::valida( $g1, $n1 ) && $c::valida( $g2, $k2 ), "$corto: renovar invalida el enlace anterior de ese grupo y deja el del otro" );
	}
	$ok( EnlaceScouters::clave( $g1 ) !== EnlaceEspera::clave( $g1 ) && ! EnlaceEspera::valida( $g1, EnlaceScouters::clave( $g1 ) ) && ! EnlaceTienda::valida( $g1, EnlaceEspera::clave( $g1 ) ), 'la clave del alta de scouters no abre la lista de espera ni la de la lista de espera la tienda' );
	$antes_espera = EnlaceEspera::clave( $g1 );
	EnlaceScouters::renovar( $g1 );
	$ok( $antes_espera === EnlaceEspera::clave( $g1 ), 'renovar el enlace de scouters no cambia el de la lista de espera' );
} catch ( Throwable $e ) {
	++$fails;
	echo 'FAIL excepcion: ', $e->getMessage(), ' (', basename( $e->getFile() ), ':', $e->getLine(), ")\n";
} finally {
	foreach ( $opciones as $opcion => $valor ) {
		if ( null === $valor ) {
			delete_option( $opcion );
		} else {
			update_option( $opcion, $valor, false );
		}
	}
	if ( null === $ip_antes ) {
		unset( $_SERVER['REMOTE_ADDR'] );
	} else {
		$_SERVER['REMOTE_ADDR'] = $ip_antes;
	}
	wp_using_ext_object_cache( $cache_externa );
	$igual = true;
	foreach ( $opciones as $opcion => $valor ) {
		$igual = $igual && get_option( $opcion, null ) === $valor;
	}
	$ok( $igual && $tr_antes === $transients(), 'limpieza: las claves de los enlaces de los grupos reales, como estaban' );
}

echo $fails ? "\n{$fails} FALLOS\n" : "\nTODO OK (el sitio queda como estaba)\n";
