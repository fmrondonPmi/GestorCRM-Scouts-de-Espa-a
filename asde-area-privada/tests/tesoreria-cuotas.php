<?php
/**
 * Pruebas de la configuración de Tesorería (entorno LOCAL; doc 60): las pantallas «Cuotas» (CuotasView) y
 * «Coste federativo» (FederacionView) y lo que guarda Tesoreria\Config. Ejecutar desde app/public:
 *
 *   wp eval-file wp-content/plugins/asde-area-privada/tests/tesoreria-cuotas.php
 *
 * (o, desde herramientas-desarrollo: ./probar-todo.sh tesoreria-cuotas)
 *
 * - A: quién ve y quién cambia (scouter: solo lectura y 403 al enviar; familia: sin acceso; nonce).
 * - B: guardar las cuotas: importes con coma, negativos a 0, secciones que no existen, plazos ordenados y sin los
 *   incompletos, aviso si no suman el 100 % y registro.
 * - C: guardar el coste federativo: descuentos (negativos), topes, «aplica» desconocido, edad de corte.
 * - D: un grupo no ve la configuración de otro (grupo ficticio 996, solo en memoria) y una ronda mal escrita no se guarda.
 * Trabaja en la ronda 2099-2100 (no la usa nadie) y restaura la opción asde_ap_tesoreria entera. CRM simulado, cachés
 * en memoria, ningún email.
 */
defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/lib/CrmSimulado.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Auth\Ambito;
use AsdeBalears\AreaPrivada\Crm\Crypto;
use AsdeBalears\AreaPrivada\Domain\Grupo;
use AsdeBalears\AreaPrivada\Domain\Grupos;
use AsdeBalears\AreaPrivada\Frontend\Notices;
use AsdeBalears\AreaPrivada\Frontend\Views\CuotasView;
use AsdeBalears\AreaPrivada\Frontend\Views\FederacionView;
use AsdeBalears\AreaPrivada\Install\Roles;
use AsdeBalears\AreaPrivada\Tesoreria\Config;
use AsdeBalears\AreaPrivada\Usuarios\Usuarios;

global $wpdb;

$fails = 0;
$ok    = function ( $cond, string $label ) use ( &$fails ) {
	if ( ! $cond ) {
		++$fails;
	}
	echo ( $cond ? 'OK   ' : 'FAIL ' ), $label, "\n";
};

$grupo = Grupos::por_numero( '684' ) ?? ( array_values( Grupos::todos() )[0] ?? null );
if ( ! $grupo || '' === $grupo['crm_id'] ) {
	exit( "Hace falta un grupo con la conexión al CRM comprobada.\n" );
}
foreach ( get_users( array( 'search' => 'prueba-cuo-*', 'search_columns' => array( 'user_login' ) ) ) as $u ) {
	exit( 'Ya existe el usuario ' . $u->user_login . " (¿una ejecución anterior interrumpida?). Bórralo antes de repetir la prueba.\n" );
}
if ( Grupos::por_numero( '996' ) ) {
	exit( "El grupo 996 existe en el Local: esta prueba lo usa como grupo ficticio. Revisa de dónde ha salido.\n" );
}
wp_set_current_user( 0 );

// --- Lo que se restaura al final ---------------------------------------------
$max_id    = static fn( string $t ) => (int) $wpdb->get_var( "SELECT COALESCE(MAX(id), 0) FROM $t" ); // phpcs:ignore WordPress.DB
$log_antes = $max_id( Logger::table() );
$tes_antes = get_option( 'asde_ap_tesoreria', null );
$RONDA     = '2099-2100';

$cache_externa = wp_using_ext_object_cache( true );
$cortadas      = 0;
$cortar_crm    = static function ( $pre, $args, $url ) use ( &$cortadas ) {
	if ( false === $pre && str_contains( (string) $url, '/rest.php' ) ) {
		++$cortadas;
		return new WP_Error( 'prueba', 'CRM real cortado durante la prueba' );
	}
	return $pre;
};
add_filter( 'pre_http_request', $cortar_crm, 2, 3 );
add_filter( 'pre_wp_mail', '__return_true' );
add_filter( 'send_auth_cookies', '__return_false' );
$redirigir = static function ( $destino ) {
	throw new RuntimeException( 'asde-redirect:' . $destino );
};
add_filter( 'wp_redirect', $redirigir, 1 );
$al_morir = static function ( $mensaje ) {
	throw new RuntimeException( 'asde-die:' . ( is_string( $mensaje ) ? $mensaje : '' ) );
};
$morir = static fn() => $al_morir;
add_filter( 'wp_die_handler', $morir, PHP_INT_MAX );

$grupos_orig = get_option( 'asde_ap_grupos' );
$mas_grupos  = static fn() => (array) $grupos_orig + array(
	'996' => array(
		'nombre'    => 'Grupo de prueba 996',
		'usuario'   => 'prueba-996',
		'clave_enc' => Crypto::encrypt( 'clave-de-prueba' ),
		'crm_id'    => 'sg-prueba-996',
	),
);

$P   = array( 'Asde_Prueba_Crm', 'persona' );
$crm = new Asde_Prueba_Crm(
	array(
		$P( 12, 'Lia', 'Cuota Uno', '1990-01-01', 'Scouters' ),
		$P( 13, 'Mar', 'Cuota Dos', '1991-01-01', 'Scouters' ),
		$P( 14, 'Nil', 'Cuota Tres', '2014-01-01', 'Lobatos' ),
	),
	$grupo['crm_id']
);
Grupo::forzar( $grupo );
$crm->activar();

$ids   = array();
$crear = static function ( string $login, array $roles, array $meta ) use ( &$ids, $grupo ): int {
	$id = wp_insert_user(
		array(
			'user_login' => $login,
			'user_email' => $login . '@example.invalid',
			'user_pass'  => wp_generate_password( 24 ),
			'role'       => $roles[0],
		)
	);
	$u = get_userdata( $id );
	foreach ( array_slice( $roles, 1 ) as $r ) {
		$u->add_role( $r );
	}
	update_user_meta( $id, Usuarios::META_GRUPO, $grupo['id'] );
	update_user_meta( $id, Usuarios::META_ESTADO, Usuarios::ACTIVO );
	foreach ( $meta as $k => $v ) {
		add_user_meta( $id, $k, $v );
	}
	$ids[ $login ] = $id;
	return $id;
};
$u_teso = $crear( 'prueba-cuo-teso', array( Roles::SCOUTER, Roles::TESORERIA ), array( Usuarios::META_SOCIO => '990012', Usuarios::META_SECCION => 'rovers' ) );
$u_sc   = $crear( 'prueba-cuo-sc', array( Roles::SCOUTER ), array( Usuarios::META_SOCIO => '990013', Usuarios::META_SECCION => 'lobatos' ) );
$u_fam  = $crear( 'prueba-cuo-fam', array( Roles::TUTOR ), array( Usuarios::META_REPRESENTADO => '990014' ) );
$como   = static function ( int $uid ): void {
	wp_set_current_user( $uid );
	Ambito::olvidar( $uid );
};
$ver = static function ( $vista ) use ( $RONDA ): string {
	$_GET = array( 'ronda' => $RONDA );
	try {
		return $vista->render();
	} finally {
		$_GET = array();
	}
};
/** Envía un formulario; devuelve 'redirect', 'die:<mensaje>' o '' (y deja los avisos en Notices). */
$enviar = static function ( $vista, array $campos, string $nonce ) use ( $RONDA ): string {
	$_POST    = $campos + array(
		'ronda'    => $RONDA,
		'_wpnonce' => wp_create_nonce( $nonce ),
	);
	$_REQUEST = $_POST;
	try {
		$vista->handle_post();
		return '';
	} catch ( RuntimeException $e ) {
		$m = $e->getMessage();
		return str_starts_with( $m, 'asde-redirect:' ) ? 'redirect' : ( str_starts_with( $m, 'asde-die:' ) ? 'die:' . substr( $m, 9 ) : throw $e );
	} finally {
		$_POST    = array();
		$_REQUEST = array();
	}
};
$cfg_684 = static fn() => Grupo::con( $grupo, static fn() => Config::de( $RONDA ) );

try {
	echo "== Parte A · Quién ve y quién cambia ==\n";
	$como( $u_teso );
	$h = $ver( new CuotasView() );
	$ok( str_contains( $h, 'Guardar cuotas de la ronda' ) && str_contains( $h, 'name="cuotas[inscripcion]"' ) && ! preg_match( '~name="cuotas\[inscripcion\]"[^>]*disabled~', $h ), 'Tesorería ve las cuotas con el botón de guardar y los campos activos' );
	$como( $u_sc );
	$h = $ver( new CuotasView() );
	$ok( str_contains( $h, 'name="cuotas[inscripcion]"' ) && preg_match( '~name="cuotas\[inscripcion\]"[^>]*disabled~', $h ) && ! str_contains( $h, 'Guardar cuotas de la ronda' ), 'un scouter las ve, con los campos desactivados y sin botón' );
	$antes = Config::de( $RONDA );
	$r     = $enviar( new CuotasView(), array( 'asde_action' => 'guardar_cuotas', 'cuotas' => array( 'inscripcion' => '99' ) ), 'asde_cuotas' );
	$ok( str_starts_with( $r, 'die:' ) && $antes === Config::de( $RONDA ), 'si un scouter envía el formulario: 403 y no cambia nada' );
	$r = $enviar( new FederacionView(), array( 'federacion' => array( 'cuota_asociativa' => '99' ) ), 'asde_federacion' );
	$ok( str_starts_with( $r, 'die:' ) && $antes === Config::de( $RONDA ), 'tampoco el coste federativo' );
	$como( $u_fam );
	$ok( str_contains( $ver( new CuotasView() ), 'No tienes acceso' ) && str_contains( $ver( new FederacionView() ), 'No tienes acceso' ), 'una familia no ve ninguna de las dos pantallas' );
	$como( $u_teso );
	$_POST    = array( 'asde_action' => 'guardar_cuotas', 'ronda' => $RONDA, 'cuotas' => array( 'inscripcion' => '77' ), '_wpnonce' => 'falso' );
	$_REQUEST = $_POST;
	$muerto   = false;
	try {
		( new CuotasView() )->handle_post();
	} catch ( RuntimeException $e ) {
		$muerto = str_starts_with( $e->getMessage(), 'asde-die:' );
	} finally {
		$_POST    = array();
		$_REQUEST = array();
	}
	$ok( $muerto && 77.0 !== Config::de( $RONDA )['cuotas']['inscripcion'], 'Tesorería con un nonce falso: se para y no se guarda' );

	echo "\n== Parte B · Guardar las cuotas ==\n";
	Notices::render(); // Vacía los avisos que hubiera.
	$r = $enviar(
		new CuotasView(),
		array(
			'asde_action'     => 'guardar_cuotas',
			'cuotas'          => array(
				'inscripcion' => '12,50',
				'seguro'      => '-5',
				'anualidad'   => array( '100', '', '80,5' ),
			),
			'secciones_pagan' => array( 'lobatos', 'piratas', 'scouts' ),
			'plazos'          => array(
				array( 'nombre' => 'Segundo', 'fecha' => '2100-02-01', 'porcentaje' => '40' ),
				array( 'nombre' => 'Primero', 'fecha' => '2099-10-01', 'porcentaje' => '50' ),
				array( 'nombre' => '', 'fecha' => '2099-11-01', 'porcentaje' => '10' ),
				array( 'nombre' => 'Sin fecha', 'fecha' => '1/12/2099', 'porcentaje' => '10' ),
				array( 'nombre' => 'Cero', 'fecha' => '2099-12-01', 'porcentaje' => '0' ),
			),
		),
		'asde_cuotas'
	);
	$c = Config::de( $RONDA );
	$ok( 'redirect' === $r, 'Tesorería guarda y vuelve a la pantalla' );
	$ok( 12.5 === $c['cuotas']['inscripcion'] && 0.0 === $c['cuotas']['seguro'] && array( 100.0, 80.5 ) === $c['cuotas']['anualidad'], 'importes: «12,50» → 12,50; un negativo → 0; los tramos vacíos se quitan' );
	$ok( array( 'lobatos', 'scouts' ) === $c['secciones_pagan'], 'secciones que pagan: solo las que existen' );
	$ok( array( 'Primero', 'Segundo' ) === array_column( $c['plazos'], 'nombre' ) && array( 50.0, 40.0 ) === array_column( $c['plazos'], 'porcentaje' ), 'plazos: ordenados por fecha y sin los que no tienen nombre, fecha válida o porcentaje' );
	$avisos = Notices::render();
	$ok( str_contains( $avisos, 'Cuotas de la ronda 2099-2100 guardadas' ) && str_contains( $avisos, 'deberían sumar 100' ), 'avisa de que se ha guardado y de que los plazos suman un 90 %' );
	$ok( 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE id > %d AND accion = %s AND objeto_id = %s AND user_id = %d', Logger::table(), $log_antes, 'tesoreria_cuotas_guardadas', $RONDA, $u_teso ) ), 'queda en el registro quién lo cambió' ); // phpcs:ignore WordPress.DB
	// El formulario manda siempre todo (cuotas, secciones y plazos): lo que no llega se guarda vacío.
	$form   = static fn( array $plazos ): array => array(
		'asde_action'     => 'guardar_cuotas',
		'cuotas'          => array(
			'inscripcion' => (string) $c['cuotas']['inscripcion'],
			'seguro'      => (string) $c['cuotas']['seguro'],
			'anualidad'   => array_map( 'strval', $c['cuotas']['anualidad'] ),
		),
		'secciones_pagan' => $c['secciones_pagan'],
		'plazos'          => $plazos,
	);
	$enviar( new CuotasView(), $form( array( array( 'nombre' => 'Único', 'fecha' => '2099-10-01', 'porcentaje' => '150' ) ) ), 'asde_cuotas' );
	$c2     = Config::de( $RONDA );
	$avisos = Notices::render();
	$ok( array( 100.0 ) === array_map( 'floatval', array_column( $c2['plazos'], 'porcentaje' ) ) && ! str_contains( $avisos, 'deberían sumar 100' ), 'un porcentaje de más de 100 se queda en 100 y entonces no hay aviso' );
	$ok( $c['cuotas'] === $c2['cuotas'] && $c['secciones_pagan'] === $c2['secciones_pagan'], 'volver a guardar el formulario con otros plazos deja las cuotas y las secciones como estaban' );
	$enviar( new CuotasView(), $form( array( array( 'nombre' => '', 'fecha' => '', 'porcentaje' => '' ) ) ), 'asde_cuotas' );
	$ok( $c2['plazos'] === Config::de( $RONDA )['plazos'], 'si no queda ningún plazo válido, se conservan los que había' );

	echo "\n== Parte C · Guardar el coste federativo ==\n";
	$r = $enviar(
		new FederacionView(),
		array(
			'federacion' => array(
				'cuota_asociativa' => '18,75',
				'seguro_promedio'  => '-3',
				'edad_corte'       => '99',
				'primas'           => array( 'menores' => '4', 'mayores' => '6,5', 'scouters' => 'x' ),
				'conceptos'        => array(
					array( 'nombre' => 'Descuento hermanos', 'importe' => '-10', 'aplica' => 'asociado' ),
					array( 'nombre' => 'Tope', 'importe' => '123456', 'aplica' => 'inventado' ),
					array( 'nombre' => '', 'importe' => '5', 'aplica' => 'asociado' ),
				),
				'anio_primas'      => '3000',
			),
		),
		'asde_federacion'
	);
	$f = Config::de( $RONDA )['federacion'];
	$ok( 'redirect' === $r && 18.75 === $f['cuota_asociativa'] && 0.0 === $f['seguro_promedio'] && 30 === $f['edad_corte'], 'cuota con coma, seguro negativo a 0 y edad de corte con tope (30)' );
	$ok( 4.0 === $f['primas']['menores'] && 6.5 === $f['primas']['mayores'] && 0.0 === $f['primas']['scouters'], 'primas: importes con coma y un texto que no es número → 0' );
	$ok( 2 === count( $f['conceptos'] ) && -10.0 === (float) $f['conceptos'][0]['importe'] && 99999.0 === (float) $f['conceptos'][1]['importe'] && 'asociado' === $f['conceptos'][1]['aplica'], 'conceptos: un descuento negativo vale, el importe tiene tope, «aplica» desconocido → asociado y sin nombre no entra' );
	$ok( 2100 === $f['anio_primas'], 'el año de las primas también tiene tope' );
	$enviar( new FederacionView(), array( 'federacion' => array( 'cuota_asociativa' => '18,75' ) ), 'asde_federacion' );
	$ok( array() === Config::de( $RONDA )['federacion']['conceptos'], 'guardar sin conceptos los quita todos' );
	$h = $ver( new FederacionView() );
	$ok( str_contains( $h, 'Guardar coste federativo' ) && 0 === $cortadas, 'la pantalla del coste federativo se pinta con el CRM simulado (ninguna consulta al real)' );

	echo "\n== Parte D · Un grupo no ve la configuración de otro ==\n";
	add_filter( 'pre_option_asde_ap_grupos', $mas_grupos );
	Grupos::olvidar();
	$del_684 = $cfg_684();
	$otro    = Grupos::por_numero( '996' );
	$en_996  = Grupo::con( $otro, static fn() => Config::de( $RONDA ) );
	$ok( 12.5 === $del_684['cuotas']['inscripcion'] && $en_996 === Config::defaults_ronda( $RONDA ), 'el grupo 996 no ve las cuotas del 684: tiene las de por defecto' );
	Grupo::con( $otro, static fn() => Config::guardar( $RONDA, array( 'cuotas' => array( 'inscripcion' => '1', 'seguro' => '1', 'anualidad' => array( '1' ) ) ) ) );
	$ok( 1.0 === Grupo::con( $otro, static fn() => Config::de( $RONDA ) )['cuotas']['inscripcion'] && $del_684 === $cfg_684(), 'guardar en el 996 no cambia nada del 684' );
	$opcion = (array) get_option( 'asde_ap_tesoreria' );
	$ok( isset( $opcion['996'][ $RONDA ], $opcion[ $grupo['numero'] ][ $RONDA ] ), 'cada grupo guarda lo suyo bajo su número' );
	Config::guardar( '2099/2100', array( 'cuotas' => array( 'inscripcion' => '5' ) ) );
	Config::guardar( 'ronda', array( 'cuotas' => array( 'inscripcion' => '5' ) ) );
	$opcion2 = (array) get_option( 'asde_ap_tesoreria' );
	$ok( ! isset( $opcion2[ $grupo['numero'] ]['2099/2100'] ) && ! isset( $opcion2[ $grupo['numero'] ]['ronda'] ) && $opcion === $opcion2, 'una ronda mal escrita no se guarda' );
} catch ( Throwable $e ) {
	++$fails;
	echo 'FAIL excepcion: ', $e->getMessage(), ' (', basename( $e->getFile() ), ':', $e->getLine(), ")\n";
} finally {
	$crm->desactivar();
	remove_filter( 'pre_http_request', $cortar_crm, 2 );
	remove_filter( 'pre_wp_mail', '__return_true' );
	remove_filter( 'send_auth_cookies', '__return_false' );
	remove_filter( 'wp_redirect', $redirigir, 1 );
	remove_filter( 'wp_die_handler', $morir, PHP_INT_MAX );
	remove_filter( 'pre_option_asde_ap_grupos', $mas_grupos );
	Grupos::olvidar();
	$_GET  = array();
	$_POST = array();
	wp_set_current_user( 0 );

	if ( null === $tes_antes ) {
		delete_option( 'asde_ap_tesoreria' );
	} else {
		update_option( 'asde_ap_tesoreria', $tes_antes, false );
	}
	$lista = implode( ',', array_map( 'intval', $ids ) ) ?: '0';
	$wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE id > %d AND user_id IN ($lista)", Logger::table(), $log_antes ) ); // phpcs:ignore WordPress.DB
	foreach ( $ids as $uid ) {
		Ambito::olvidar( (int) $uid );
		wp_delete_user( (int) $uid );
	}
	wp_using_ext_object_cache( $cache_externa );
	Grupo::forzar( null );

	$quedan = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users} WHERE user_login LIKE 'prueba-cuo-%'" ); // phpcs:ignore WordPress.DB
	$ok( 0 === $quedan && get_option( 'asde_ap_tesoreria', null ) === $tes_antes, 'limpieza: sin usuarios y con la configuración de Tesorería como estaba' );
}

echo $fails ? "\n{$fails} FALLOS\n" : "\nTODO OK (nada tocado en el CRM real; el sitio queda como estaba)\n";
