<?php
/**
 * Pruebas de la v0.12.3 (entorno LOCAL): rendimiento. La instantánea cifrada de las
 * fichas del grupo (cuántas consultas al CRM hace cada lectura), la sesión del CRM
 * que se renueva mientras se usa y que los resultados son los mismos que leyendo
 * siempre del CRM. Ejecutar desde app/public del site Local:
 *
 *   wp eval-file <ruta completa>/wp-content/plugins/asde-area-privada/tests/rendimiento.php
 *
 * Usa un CRM simulado (tests/lib/CrmSimulado.php): ninguna petición llega al CRM
 * real. Datos inventados. Las cachés de la prueba, solo en la memoria de este
 * proceso. Al terminar se borra lo creado.
 */
defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/lib/CrmSimulado.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Auth\Ambito;
use AsdeBalears\AreaPrivada\Crm\ClientFactory;
use AsdeBalears\AreaPrivada\Crm\Crypto;
use AsdeBalears\AreaPrivada\Crm\Escritura;
use AsdeBalears\AreaPrivada\Crm\Instantanea;
use AsdeBalears\AreaPrivada\Crm\PersonaRepository;
use AsdeBalears\AreaPrivada\Crm\Settings;
use AsdeBalears\AreaPrivada\Domain\Grupo;
use AsdeBalears\AreaPrivada\Domain\Grupos;
use AsdeBalears\AreaPrivada\Install\Roles;
use AsdeBalears\AreaPrivada\Usuarios\Usuarios;

global $wpdb;

$fails = 0;
$ok    = function ( $cond, string $label ) use ( &$fails ) {
	if ( ! $cond ) {
		++$fails;
	}
	echo ( $cond ? 'OK   ' : 'FAIL ' ), $label, "\n";
};

$grupo = array_values( Grupos::todos() )[0] ?? null;
if ( ! $grupo || '' === $grupo['crm_id'] ) {
	exit( "Hace falta un grupo con la conexión al CRM comprobada.\n" );
}
foreach ( get_users( array( 'search' => 'prueba-ren-*', 'search_columns' => array( 'user_login' ) ) ) as $u ) {
	exit( 'Ya existe el usuario ' . $u->user_login . " (¿una ejecución anterior interrumpida?). Bórralo antes de repetir la prueba.\n" );
}
wp_set_current_user( 0 );

$max_id    = static fn( string $t ) => (int) $wpdb->get_var( "SELECT COALESCE(MAX(id), 0) FROM $t" ); // phpcs:ignore WordPress.DB
$log_antes = $max_id( Logger::table() );
$num_grupo = (string) $grupo['numero'];

$P   = array( 'Asde_Prueba_Crm', 'persona' );
$crm = new Asde_Prueba_Crm(
	array(
		$P( 1, 'Leo', 'Prueba Sanz', '2016-05-10', 'Lobatos', array( 'tutor1_nombre_c' => 'Ana', 'tutor1_apellidos_c' => 'Prueba Sanz', 'tutor1_email_c' => 'ana.prueba@example.invalid' ) ),
		$P( 2, 'Nora', 'Prueba Sanz', '2014-02-20', 'Scouts' ),
		$P( 3, 'Pol', 'Prueba Mas', '2011-03-03', 'Escultas', array( 'email1' => 'pol.prueba@example.invalid' ) ),
		$P( 4, 'Eva', 'Prueba Gil', '1990-01-01', 'Scouters', array( 'email1' => 'eva.prueba@example.invalid' ) ),
		$P( 5, 'Iris', 'Baja Roca', '2012-07-07', '' ), // Sin membresía activa.
	),
	$grupo['crm_id']
);
$cortar_crm = static function ( $pre, $args, $url ) {
	return ( false === $pre && str_contains( (string) $url, '/rest.php' ) ) ? new WP_Error( 'prueba', 'CRM real cortado durante la prueba' ) : $pre;
};
add_filter( 'pre_http_request', $cortar_crm, 2, 3 );
$cache_externa = wp_using_ext_object_cache( true );
Grupo::forzar( $grupo );
$crm->activar();
Escritura::usar_para_pruebas( $crm );
Instantanea::olvidar( $num_grupo );
$con_instantanea = '__return_true';
$ids             = array();

try {
	echo "== Parte A · Con la instantánea, leer no llama al CRM ==\n";
	$ok( 0 === Instantanea::segundos(), 'en las pruebas automáticas la instantánea está desactivada (así no tapa lo que comprueban las demás)' );
	add_filter( 'asde_ap_instantanea_en_pruebas', $con_instantanea );
	$ok( Instantanea::segundos() > 0, 'y una prueba como esta la puede activar' );
	$repo = PersonaRepository::make();

	// Lo que da el CRM directamente, para comparar.
	remove_filter( 'asde_ap_instantanea_en_pruebas', $con_instantanea );
	$vivo_todas  = $repo->todas( false );
	$vivo_activas = $repo->todas( true );
	$vivo_una    = $repo->obtener( '990002' );
	$vivo_varias = $repo->obtener_varias( array( '990003', '990001', '990099', '990004' ) );
	add_filter( 'asde_ap_instantanea_en_pruebas', $con_instantanea );
	$ok( null === Instantanea::leer( $num_grupo ), 'con la instantánea desactivada no se guarda nada' );

	$antes = $crm->llamadas;
	$todas = $repo->todas( false );
	$ok( $crm->llamadas > $antes && null !== Instantanea::leer( $num_grupo ), 'la primera lectura va al CRM y deja la instantánea' );
	$antes = $crm->llamadas;
	$activas = $repo->todas( true );
	$una     = $repo->obtener( '990002' );
	$varias  = $repo->obtener_varias( array( '990003', '990001', '990099', '990004' ) );
	$ok( $crm->llamadas === $antes + 1, 'todas(true), obtener y obtener_varias no llaman al CRM (solo el nº que no existe, 990099, se pregunta por si es nuevo)' );

	$nums = static fn( array $l ) => array_map( static fn( $p ) => $p->num_socio_asde, $l );
	$ok( $nums( $todas ) === $nums( $vivo_todas ) && 5 === count( $todas ), 'todas(false): las mismas fichas y en el mismo orden que leyendo del CRM' );
	$ok( $nums( $activas ) === $nums( $vivo_activas ) && 4 === count( $activas ), 'todas(true): solo las de membresía activa, igual que el CRM' );
	$ok( $una && $vivo_una && $una->nombre_completo() === $vivo_una->nombre_completo() && $una->fecha_nacimiento === $vivo_una->fecha_nacimiento, 'obtener: la misma ficha' );
	$ok( array_keys( $varias ) === array_keys( $vivo_varias ) && array( '990003', '990001', '990004' ) === array_map( 'strval', array_keys( $varias ) ), 'obtener_varias: las mismas, en el orden pedido y sin las que no existen' );
	$ok( $todas[0]->tutores === $vivo_todas[0]->tutores && $todas[0]->secciones === $vivo_todas[0]->secciones, 'los tutores y las secciones llegan igual' );
	$ok( null === $repo->obtener( '12abc' ) && array() === $repo->obtener_varias( array() ), 'números que no son válidos: nada, sin preguntar' );

	echo "\n== Parte B · Cifrada, sin salud, y solo por un rato ==\n";
	$crudo = get_transient( 'asde_ap_snap_' . $num_grupo );
	$ok( is_string( $crudo ) && str_starts_with( $crudo, 'v1:' ) && ! str_contains( $crudo, 'Prueba' ) && ! str_contains( $crudo, 'example' ), 'lo guardado va cifrado: no se ve ningún nombre ni email en claro' );
	$snap  = Instantanea::leer( $num_grupo );
	$campos = array();
	foreach ( $snap['entries'] as $e ) {
		$campos = array_merge( $campos, array_keys( $e['fields'] ) );
	}
	$ok( $snap && 5 === count( $snap['entries'] ) && ! array_diff( array_unique( $campos ), PersonaRepository::CAMPOS ), 'solo contiene los campos de la lista blanca (sin salud ni dietas)' );
	$ok( 'fresca' === Instantanea::estado( $snap ) && Instantanea::edad( $num_grupo ) < 5, 'recién leída está fresca' );

	// Con el tiempo: antigua (se sirve y se refresca en segundo plano) y caducada (se lee del CRM).
	Instantanea::guardar( $num_grupo, $snap['entries'], time() - ( Instantanea::segundos() + 60 ) );
	$ok( 'gracia' === Instantanea::estado( Instantanea::leer( $num_grupo ) ), 'pasado su tiempo queda «antigua»' );
	wp_clear_scheduled_hook( Instantanea::HOOK, array( $num_grupo ) );
	$antes = $crm->llamadas;
	$repo->todas( true );
	$ok( $crm->llamadas === $antes && false !== wp_next_scheduled( Instantanea::HOOK, array( $num_grupo ) ), 'antigua: se sirve sin esperar al CRM y se programa su refresco en segundo plano' );
	$crm->personas[0]['first_name'] = 'Leonel'; // Alguien cambia el CRM por otro lado.
	Instantanea::refrescar_en_segundo_plano( $num_grupo );
	wp_clear_scheduled_hook( Instantanea::HOOK, array( $num_grupo ) );
	$ok( 'Leonel' === $repo->obtener( '990001' )->nombre && 'fresca' === Instantanea::estado( Instantanea::leer( $num_grupo ) ), 'el refresco en segundo plano trae lo que cambió en el CRM' );
	Instantanea::guardar( $num_grupo, $snap['entries'], time() - 2 * Instantanea::segundos() - 10 );
	$ok( 'caducada' === Instantanea::estado( Instantanea::leer( $num_grupo ) ), 'a los dos tiempos ya no se sirve' );
	$antes = $crm->llamadas;
	$ok( 'Leonel' === $repo->obtener( '990001' )->nombre && $crm->llamadas > $antes, 'caducada: se vuelve a leer del CRM, sin esperar al refresco' );

	echo "\n== Parte C · Cambios en el CRM ==\n";
	// Una ficha recién creada en el CRM no está aún en la instantánea: se pregunta por ella.
	$crm->personas[] = $P( 6, 'Nuevo', 'Prueba Vila', '2015-01-01', 'Lobatos' );
	$antes           = $crm->llamadas;
	$nuevo           = $repo->obtener( '990006' );
	$ok( $nuevo && 'Nuevo' === $nuevo->nombre && $crm->llamadas === $antes + 1, 'una ficha que aún no está se pregunta al CRM (así nadie se queda sin entrar)' );
	$ok( isset( $repo->obtener_varias( array( '990001', '990006' ) )['990006'] ), 'obtener_varias también la encuentra' );
	$repo->refrescar_instantanea();
	$antes = $crm->llamadas;
	$repo->obtener( '990006' );
	$ok( $crm->llamadas === $antes, 'tras refrescar ya está en la instantánea' );

	// Lo que se guarda desde la web se ve al momento, sin volver a leer.
	$ficha = $repo->obtener( '990002' );
	$edad  = Instantanea::edad( $num_grupo );
	$r     = Escritura::guardar( $ficha, array( 'phone_mobile' => '600111222' ), 'prueba' );
	$antes = $crm->llamadas;
	$ok( is_array( $r ) && array( 'phone_mobile' ) === $r['guardados'] && '600111222' === $repo->obtener( '990002' )->movil && $crm->llamadas === $antes, 'lo que se guarda en el CRM desde la web se ve al momento en la instantánea' );
	$ok( Instantanea::edad( $num_grupo ) >= $edad && 'fresca' === Instantanea::estado( Instantanea::leer( $num_grupo ) ), 'y no la hace parecer más nueva de lo que es' );
	$r2 = Escritura::guardar( $ficha, array( 'numerosocio_c' => '1' ), 'prueba' );
	$ok( is_wp_error( $r2 ), 'lo que no se puede editar sigue sin poderse' );

	// Desactivarla.
	add_filter( 'asde_ap_instantanea_segundos', '__return_zero' );
	$antes = $crm->llamadas;
	$repo->obtener( '990001' );
	$repo->todas( true );
	$ok( 0 === Instantanea::segundos() && $crm->llamadas === $antes + 2, 'con el filtro asde_ap_instantanea_segundos a 0 se vuelve a leer siempre del CRM' );
	remove_filter( 'asde_ap_instantanea_segundos', '__return_zero' );

	Instantanea::olvidar( $num_grupo );
	$ok( null === Instantanea::leer( $num_grupo ) && false === get_transient( 'asde_ap_snap_' . $num_grupo ), 'olvidar() la borra' );
	$crm->caido = true;
	$error      = false;
	try {
		$repo->todas( true );
	} catch ( \AsdeBalears\AreaPrivada\Crm\CrmException $e ) {
		$error = true;
	}
	$crm->caido = false;
	$ok( $error && null === Instantanea::leer( $num_grupo ), 'con el CRM caído y sin instantánea se avisa del error y no se guarda nada' );

	echo "\n== Parte D · El área de una persona no espera al CRM ==\n";
	$uid = wp_insert_user(
		array(
			'user_login' => 'prueba-ren-scouter',
			'user_email' => '',
			'user_pass'  => wp_generate_password( 24 ),
			'role'       => Roles::SCOUTER,
		)
	);
	$ids[] = $uid;
	update_user_meta( $uid, Usuarios::META_GRUPO, $grupo['id'] );
	update_user_meta( $uid, Usuarios::META_ESTADO, Usuarios::ACTIVO );
	add_user_meta( $uid, Usuarios::META_SOCIO, '990004' );
	add_user_meta( $uid, Usuarios::META_SECCION, 'lobatos' );
	$repo->refrescar_instantanea();
	$antes = $crm->llamadas;
	Ambito::olvidar( $uid );
	$ambito = Ambito::de( get_userdata( $uid ) );
	$ok( $crm->llamadas === $antes && $ambito->puede_acceder() && 'Eva' === $ambito->nombre(), 'comprobar quién es y si puede entrar (en cada página) ya no consulta el CRM' );
	$crm->personas[3]['stic_relationship_type_c'] = ''; // Se da de baja en el CRM.
	Ambito::olvidar( $uid );
	$ok( Ambito::de( get_userdata( $uid ) )->puede_acceder(), 'la baja llega a la web en unos minutos, no al instante (hasta entonces sigue la instantánea)' );
	$repo->refrescar_instantanea();
	Ambito::olvidar( $uid );
	$ok( ! Ambito::de( get_userdata( $uid ) )->puede_acceder(), 'al refrescar (o al pasar el tiempo) deja de poder entrar' );

	echo "\n== Parte E · La sesión del CRM se renueva mientras se usa ==\n";
	$cliente = ClientFactory::reader();
	$clave   = ( new ReflectionMethod( $cliente, 'session_key' ) );
	$clave->setAccessible( true );
	$clave = $clave->invoke( $cliente );
	$repo->refrescar_instantanea(); // Hace una llamada y deja la sesión guardada.
	$sesion = get_transient( $clave );
	$ok( is_array( $sesion ) && ! empty( $sesion['s'] ) && isset( $sesion['t'] ) && time() - $sesion['t'] <= 2, 'la sesión del CRM se guarda cifrada con la hora de su último uso' );
	$vieja      = $sesion;
	$vieja['t'] = time() - 400; // Lleva más de 5 minutos sin renovarse.
	set_transient( $clave, $vieja, 15 * MINUTE_IN_SECONDS );
	ClientFactory::olvidar(); // Otra petición: un cliente nuevo que lee la sesión guardada.
	$hechas = $crm->llamadas;
	PersonaRepository::make()->refrescar_instantanea();
	$nueva = get_transient( $clave );
	$ok( $crm->llamadas > $hechas && Crypto::decrypt( $nueva['s'] ) === Crypto::decrypt( $sesion['s'] ) && time() - $nueva['t'] <= 2, 'tras usarla se alarga su tiempo (antes caducaba a los 15 minutos aunque se usase)' );
	$reciente = $nueva;
	ClientFactory::olvidar();
	PersonaRepository::make()->refrescar_instantanea();
	$ok( get_transient( $clave )['t'] === $reciente['t'], 'y no se vuelve a guardar en cada llamada' );

	echo "\n== Parte F · Cerrojo, índice y límite de lecturas forzadas (v0.14.1) ==\n";
	delete_option( 'asde_ap_snaplock_' . $num_grupo );
	$ok( Instantanea::tomar_cerrojo( $num_grupo ) && ! Instantanea::tomar_cerrojo( $num_grupo ), 'cerrojo: el primero lo toma y el segundo no puede mientras siga puesto' );
	Instantanea::soltar_cerrojo( $num_grupo );
	$ok( Instantanea::tomar_cerrojo( $num_grupo ), 'soltado, se puede volver a tomar' );
	update_option( 'asde_ap_snaplock_' . $num_grupo, time() - 120, false );
	$ok( Instantanea::tomar_cerrojo( $num_grupo ), 'un cerrojo olvidado (la petición murió hace más de 60 s) no bloquea para siempre' );
	Instantanea::soltar_cerrojo( $num_grupo );

	// Caducada con otra petición leyendo el CRM: se espera su resultado y, si no llega, se lee igualmente (nadie se queda sin página).
	add_filter( 'asde_ap_instantanea_espera', static fn() => 0.3 );
	$repo->refrescar_instantanea();
	$snap_f = Instantanea::leer( $num_grupo );
	Instantanea::guardar( $num_grupo, $snap_f['entries'], time() - 2 * Instantanea::segundos() - 10 );
	Instantanea::tomar_cerrojo( $num_grupo ); // «Otra petición» está leyendo.
	$antes = $crm->llamadas;
	$t0    = microtime( true );
	$lista = $repo->todas( true );
	$ok( count( $lista ) >= 4 && $crm->llamadas > $antes && ( microtime( true ) - $t0 ) < 3, 'con otra petición leyendo y sin resultado a tiempo, se lee del CRM igualmente en vez de quedarse esperando' );
	Instantanea::soltar_cerrojo( $num_grupo );
	$ok( 'fresca' === Instantanea::estado( Instantanea::leer( $num_grupo ) ), 'y la instantánea queda fresca' );
	// Si la otra petición termina mientras se espera, se usa lo suyo sin tocar el CRM.
	Instantanea::guardar( $num_grupo, $snap_f['entries'], time() - 2 * Instantanea::segundos() - 10 );
	Instantanea::tomar_cerrojo( $num_grupo );
	add_filter(
		'asde_ap_instantanea_espera',
		static function () use ( $num_grupo, $snap_f ) {
			Instantanea::guardar( $num_grupo, $snap_f['entries'] ); // La otra petición deja lista la instantánea.
			return 1.0;
		},
		20
	);
	$antes = $crm->llamadas;
	$lista = $repo->todas( true );
	$ok( count( $lista ) >= 4 && $crm->llamadas === $antes, 'esperar el resultado de la otra petición no vuelve a leer el CRM' );
	Instantanea::soltar_cerrojo( $num_grupo );
	remove_all_filters( 'asde_ap_instantanea_espera' );

	// Índice por nº de Scouts de España.
	$repo->refrescar_instantanea();
	$por = Instantanea::por_numeros( $num_grupo, array( '990003', '990001', '999999' ) );
	$ok( 2 === count( $por ) && '990003' === $por[0]['fields']['numerosocio_c'] && array() === Instantanea::por_numeros( $num_grupo, array( '12abc' ) ), 'el índice encuentra las fichas pedidas, en el orden pedido, y nada de lo que no existe' );
	$ok( 'Leonel' === $repo->obtener( '990001' )->nombre, 'obtener() usa el índice (y ve lo ya cambiado)' );
	$crm->personas[1]['first_name'] = 'Nora2';
	$repo->refrescar_instantanea();
	$ok( 'Nora2' === $repo->obtener( '990002' )->nombre, 'al refrescar, el índice se reconstruye con lo nuevo' );

	// «Actualizar» no se puede forzar más de una vez cada pocos segundos.
	$ok( ! Instantanea::puede_forzar( $num_grupo ), 'recién leída del CRM no se deja forzar otra lectura (evita machacar el CRM)' );
	Instantanea::guardar( $num_grupo, Instantanea::leer( $num_grupo )['entries'], time() - Instantanea::MIN_FORZAR - 5 );
	$ok( Instantanea::puede_forzar( $num_grupo ), 'pasado el tiempo mínimo, sí' );
	Instantanea::olvidar( $num_grupo );
	$ok( Instantanea::puede_forzar( $num_grupo ), 'y sin instantánea también (hay que leerla)' );
} finally {
	remove_filter( 'asde_ap_instantanea_en_pruebas', $con_instantanea );
	delete_option( 'asde_ap_snaplock_' . $num_grupo );
	Instantanea::olvidar( $num_grupo );
	wp_clear_scheduled_hook( Instantanea::HOOK, array( $num_grupo ) );
	$lista = implode( ',', array_map( 'intval', $ids ) ) ?: '0';
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Logger::table() . " WHERE id > %d AND ( user_id IN ($lista) OR ( objeto = 'user' AND objeto_id IN ($lista) ) OR ( objeto = 'socio' AND objeto_id LIKE '9900%%' ) )", $log_antes ) ); // phpcs:ignore WordPress.DB
	foreach ( $ids as $uid ) {
		Ambito::olvidar( (int) $uid );
		wp_delete_user( (int) $uid );
	}
	$crm->desactivar();
	Escritura::usar_para_pruebas( null );
	wp_using_ext_object_cache( $cache_externa );
	remove_filter( 'pre_http_request', $cortar_crm, 2 );
	Grupos::olvidar();
	Grupo::forzar( null );
	$quedan = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users} WHERE user_login LIKE 'prueba-ren-%'" ); // phpcs:ignore WordPress.DB
	$ok( 0 === $quedan, 'limpieza: sin usuarios de la prueba' );
}

echo $fails ? "\n$fails FALLOS\n" : "\nTODO OK (nada tocado en el CRM real; el sitio queda como estaba)\n";
