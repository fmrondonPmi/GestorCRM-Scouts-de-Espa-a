<?php
/**
 * Pruebas de la v0.40, fase A1 (entorno LOCAL, doc 49): el calendario de tareas de los eventos — cálculo de las fechas
 * (con las dos tablas de ejemplo del programador de ASDE), el ajuste de fin de semana, aplicar la plantilla,
 * recalcular, quién marca y quién cambia, el inicio y la configuración de plantillas.
 * Ejecutar desde app/public del site Local:
 *
 *   wp eval-file <ruta completa>/wp-content/plugins/asde-area-privada/tests/eventos-calendario.php
 *
 * Usa el CRM simulado de tests/lib (fichas inventadas: ninguna petición llega al CRM real). Al terminar se borra lo
 * creado (usuarios, eventos, equipo, tareas, registros) y se restauran la configuración de eventos del grupo, los
 * módulos y la tabla de números.
 */
defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/lib/CrmSimulado.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Auth\Ambito;
use AsdeBalears\AreaPrivada\Crm\Escritura;
use AsdeBalears\AreaPrivada\Domain\Grupo;
use AsdeBalears\AreaPrivada\Domain\Grupos;
use AsdeBalears\AreaPrivada\Eventos\AvisosTareas;
use AsdeBalears\AreaPrivada\Eventos\Config;
use AsdeBalears\AreaPrivada\Eventos\EventosTable;
use AsdeBalears\AreaPrivada\Eventos\InscripcionesTable;
use AsdeBalears\AreaPrivada\Eventos\PartidasTable;
use AsdeBalears\AreaPrivada\Eventos\Plantillas;
use AsdeBalears\AreaPrivada\Eventos\Presupuesto;
use AsdeBalears\AreaPrivada\Eventos\Servicio;
use AsdeBalears\AreaPrivada\Eventos\Tareas;
use AsdeBalears\AreaPrivada\Eventos\TareasTable;
use AsdeBalears\AreaPrivada\Frontend\Notices;
use AsdeBalears\AreaPrivada\Frontend\Views\AreaPrivadaView;
use AsdeBalears\AreaPrivada\Frontend\Views\EventosConfigView;
use AsdeBalears\AreaPrivada\Frontend\Views\EventosView;
use AsdeBalears\AreaPrivada\Frontend\Views\View;
use AsdeBalears\AreaPrivada\Install\Roles;
use AsdeBalears\AreaPrivada\Socios\SociosTable;
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

$grupo = Grupos::por_numero( '684' ) ?? ( array_values( Grupos::todos() )[0] ?? null );
if ( ! $grupo || '' === $grupo['crm_id'] ) {
	exit( "Hace falta un grupo con la conexión al CRM comprobada.\n" );
}
foreach ( get_users( array( 'search' => 'prueba-cal-*', 'search_columns' => array( 'user_login' ) ) ) as $u ) {
	exit( 'Ya existe el usuario ' . $u->user_login . " (¿una ejecución anterior interrumpida?). Bórralo antes de repetir la prueba.\n" );
}
wp_set_current_user( 0 );

// --- Lo que se restaura al final ---------------------------------------------
$max_id       = static fn( string $t ) => (int) $wpdb->get_var( "SELECT COALESCE(MAX(id), 0) FROM $t" ); // phpcs:ignore WordPress.DB
$log_antes    = $max_id( Logger::table() );
$ev_antes     = $max_id( EventosTable::table() );
$ins_antes    = $max_id( InscripcionesTable::table() );
$tar_antes    = $max_id( TareasTable::table() );
$par_antes    = $max_id( PartidasTable::table() );
$email_antes  = $max_id( RegistroEmails::table() );
$socios_antes = array_column( (array) $wpdb->get_results( 'SELECT * FROM ' . SociosTable::table(), ARRAY_A ), null, 'num_socio_asde' ); // phpcs:ignore WordPress.DB
$opcion_cfg   = 'asde_ap_eventos_' . $grupo['numero'];
$cfg_antes    = get_option( $opcion_cfg, null );

$cache_externa = wp_using_ext_object_cache( true );
add_filter( 'pre_wp_mail', '__return_true' );
$cortadas   = 0;
$cortar_crm = static function ( $pre, $args, $url ) use ( &$cortadas ) {
	if ( false === $pre && str_contains( (string) $url, '/rest.php' ) ) {
		++$cortadas;
		return new WP_Error( 'prueba', 'CRM real cortado durante la prueba' );
	}
	return $pre;
};
add_filter( 'pre_http_request', $cortar_crm, 2, 3 );

$P   = array( 'Asde_Prueba_Crm', 'persona' );
$crm = new Asde_Prueba_Crm(
	array(
		$P( 41, 'Ana', 'Calendario Gil', '1990-01-01', 'Scouters' ),
		$P( 42, 'Tom', 'Calendario Luz', '1985-05-05', 'Scouters' ),
		$P( 43, 'Eva', 'Calendario Coll', '1980-08-08', 'Scouters' ),
		$P( 44, 'Leo', 'Calendario Ruiz', '2016-05-10', 'Lobatos' ),
	),
	$grupo['crm_id']
);
Grupo::forzar( $grupo );
$modulos_antes = get_option( 'asde_ap_modulos', null );
\AsdeBalears\AreaPrivada\Domain\Modulos::guardar( (string) $grupo['numero'], array_keys( \AsdeBalears\AreaPrivada\Domain\Modulos::LISTA ) );
$crm->activar();
Escritura::usar_para_pruebas( $crm );

$ids   = array();
$crear = static function ( string $login, array $roles, array $meta ) use ( &$ids, $grupo ): int {
	$id = wp_insert_user(
		array(
			'user_login' => $login,
			'user_email' => '',
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
		foreach ( (array) $v as $valor ) {
			add_user_meta( $id, $k, $valor );
		}
	}
	$ids[ $login ] = $id;
	return $id;
};
$u_lob   = $crear( 'prueba-cal-lobatos', array( Roles::SCOUTER ), array( Usuarios::META_SOCIO => '990041', Usuarios::META_SECCION => 'lobatos' ) );
$u_sco   = $crear( 'prueba-cal-scouts', array( Roles::SCOUTER ), array( Usuarios::META_SOCIO => '990042', Usuarios::META_SECCION => 'scouts' ) );
$u_coord = $crear( 'prueba-cal-coord', array( Roles::SCOUTER, Roles::COORDINACION ), array( Usuarios::META_SOCIO => '990043', Usuarios::META_SECCION => 'rovers' ) );
$u_teso  = $crear( 'prueba-cal-teso', array( Roles::SCOUTER, Roles::TESORERIA ), array( Usuarios::META_SOCIO => '990043', Usuarios::META_SECCION => 'rovers' ) );
$u_fam   = $crear( 'prueba-cal-fam', array( Roles::TUTOR ), array( Usuarios::META_REPRESENTADO => '990044' ) );

$como = static function ( int $uid ): Ambito {
	wp_set_current_user( $uid );
	Ambito::olvidar( $uid );
	Servicio::olvidar();
	return Ambito::actual();
};
add_filter( 'send_auth_cookies', '__return_false' );
$redirigir = static function ( $destino ) {
	throw new RuntimeException( 'asde-redirect:' . $destino );
};
add_filter( 'wp_redirect', $redirigir, 1 );
$enviar = static function ( $vista, array $campos, string $nonce ): string {
	$_POST    = $campos + array( '_wpnonce' => wp_create_nonce( $nonce ) );
	$_REQUEST = $_POST;
	try {
		$vista->handle_post();
	} catch ( RuntimeException $e ) {
		// Redirige al terminar.
	} finally {
		$_POST    = array();
		$_REQUEST = array();
	}
	return Notices::render();
};
$pintar = static function ( $vista, array $get = array() ): string {
	$_GET = $get;
	try {
		return $vista->render();
	} finally {
		$_GET = array();
	}
};
$dia     = static fn( string $mod ) => wp_date( 'Y-m-d', strtotime( $mod ) );
$formulario = static fn( string $ini, string $fin, array $extra = array() ): array => $extra + array(
	'tipo'         => 'acampada',
	'nombre'       => 'Acampada del calendario',
	'ambito'       => 'secciones',
	'secciones'    => array( 'lobatos' ),
	'inicio_fecha' => $ini,
	'inicio_hora'  => '10:00',
	'fin_fecha'    => $fin,
	'fin_hora'     => '17:00',
	'lugar'        => 'Albergue de prueba',
	'previstas_lobatos' => '20',
	'con_coste'    => '1',
);
$ultimo = static fn() => EventosTable::obtener( $max_id( EventosTable::table() ) );
$ej     = require __DIR__ . '/lib/ejemplos-calendario.php';

try {
	Config::guardar( Config::defaults(), $grupo['numero'] );

	echo "== Parte A · Las fechas, con las dos tablas de ejemplo del programador ==\n";
	foreach ( $ej as $nombre => $caso ) {
		$e = array(
			'inicio' => $caso['inicio'] . ' 10:00:00',
			'fin'    => Tareas::sumar( $caso['inicio'], $caso['dias'] ) . ' 17:00:00',
		);
		$mal = array();
		foreach ( $caso['tareas'] as list( $ref, $n, $esperada, $titulo ) ) {
			$c = Tareas::calcular( $e, $ref, $n, false );
			if ( $c['fecha_calc'] !== $esperada || $c['fecha_aj'] !== $esperada ) {
				$mal[] = $titulo . ' ' . $c['fecha_calc'] . ' ≠ ' . $esperada;
			}
		}
		$ok( ! $mal, sprintf( 'ejemplo de %s (%s, %d días): las %d fechas coinciden con las del Excel', $nombre, $caso['inicio'], $caso['dias'], count( $caso['tareas'] ) ) . ( $mal ? ' · ' . implode( '; ', array_slice( $mal, 0, 3 ) ) : '' ) );
	}
	$acampada = Plantillas::incorporada();
	$ok( 31 === count( $acampada['tareas'] ), 'la plantilla de Acampada incorporada tiene las 31 tareas' );
	$mal = array();
	foreach ( $ej['acampada']['tareas'] as $i => list( $ref, $n, $esperada, $titulo ) ) {
		$t = $acampada['tareas'][ $i ];
		if ( $t['ref'] !== $ref || $t['desfase'] !== $n || $t['titulo'] !== $titulo ) {
			$mal[] = $titulo;
		}
	}
	$ok( ! $mal, 'y son, una a una, las del fichero: mismo título, desde el inicio o el fin y los mismos días' . ( $mal ? ' · ' . implode( '; ', $mal ) : '' ) );
	$ok( '2027-02-26' === Tareas::ajustar( '2027-02-27', true ) && '2027-03-05' === Tareas::ajustar( '2027-03-06', true ) && '2027-03-26' === Tareas::ajustar( '2027-03-28', true ) && '2027-03-12' === Tareas::ajustar( '2027-03-12', true ) && '2027-02-27' === Tareas::ajustar( '2027-02-27', false ), 'un sábado o un domingo se adelanta al viernes (P-185); entre semana, igual; apagado, no cambia' );
	$e   = array( 'inicio' => '2027-03-19 10:00:00', 'fin' => '2027-03-21 17:00:00' );
	$sab = Tareas::calcular( $e, 'inicio', -20, true );
	$ok( '2027-02-27' === $sab['fecha_calc'] && '2027-02-26' === $sab['fecha_aj'], 'la tarea guarda la fecha calculada (sábado 27/02) y la ajustada (viernes 26/02)' );
	$ok( '2027-03-01' === Tareas::sumar( '2027-02-28', 1 ) && '2028-02-29' === Tareas::sumar( '2028-02-28', 1 ) && '2027-10-31' === Tareas::sumar( '2027-10-30', 1 ), 'la suma de días respeta febrero, los bisiestos y el cambio de hora' );
	$ok( Tareas::fecha_valida( '2027-02-28' ) && ! Tareas::fecha_valida( '2027-02-30' ) && ! Tareas::fecha_valida( 'mañana' ), 'una fecha propia tiene que ser una fecha de verdad' );

	echo "\n== Parte B · Aplicar la plantilla a un evento ==\n";
	$a_lob = $como( $u_lob );
	$ini   = '2027-03-19';
	$fin   = '2027-03-21';
	$enviar( new EventosView(), array( 'asde_action' => 'evento_crear' ) + $formulario( $ini, $fin ), 'asde_evento_nuevo' );
	$e1 = $ultimo();
	$ok( $e1 && 'Acampada del calendario' === $e1['nombre'], 'un scouter de Lobatos crea la acampada' );
	$h = $pintar( new EventosView(), array( 'evento' => (string) $e1['id'], 'pestana' => 'calendario' ) );
	$ok( str_contains( $h, 'Calendario' ) && str_contains( $h, 'todavía no tiene tareas' ) && str_contains( $h, 'name="asde_action" value="tarea_aplicar"' ) && (bool) preg_match( '/value="acampada"\s+selected/', $h ), 'la pestaña Calendario propone la plantilla de Acampada (el tipo del evento) y aún no hay tareas' );
	$enviar( new EventosView(), array( 'asde_action' => 'tarea_aplicar', 'evento' => (string) $e1['id'], 'pestana' => 'calendario', 'plantilla' => 'acampada' ), 'asde_evento_' . $e1['id'] );
	$ts = TareasTable::de_evento( $e1['id'] );
	$ok( 31 === count( $ts ), 'se copian las 31 tareas al evento' );
	$por_titulo = array_column( $ts, null, 'titulo' );
	$mal        = array();
	foreach ( $ej['acampada']['tareas'] as list( $ref, $n, $esperada, $titulo ) ) {
		$aj = Tareas::ajustar( $esperada, true );
		if ( ( $por_titulo[ $titulo ]['fecha_calc'] ?? '' ) !== $esperada || ( $por_titulo[ $titulo ]['fecha_aj'] ?? '' ) !== $aj ) {
			$mal[] = $titulo;
		}
	}
	$ok( ! $mal, 'cada tarea queda con la fecha del Excel (calculada) y, si cae en fin de semana, adelantada al viernes' . ( $mal ? ' · ' . implode( '; ', array_slice( $mal, 0, 3 ) ) : '' ) );
	$ok( '2027-01-18' === TareasTable::fecha( $por_titulo['Ver lugar de acampada'] ) && '2027-02-26' === TareasTable::fecha( $por_titulo['Elegir cocinero, intendente y ayudantes'] ) && '2027-03-26' === TareasTable::fecha( $por_titulo['Escribir un post de la acampada en la web de grupo'] ), 'la que cuenta es la ajustada: 18/01, 26/02 (era sábado) y 26/03 (era domingo)' );
	$antes = TareasTable::contar( $e1['id'] );
	$h     = $enviar( new EventosView(), array( 'asde_action' => 'tarea_aplicar', 'evento' => (string) $e1['id'], 'pestana' => 'calendario', 'plantilla' => 'acampada' ), 'asde_evento_' . $e1['id'] );
	$ok( $antes === TareasTable::contar( $e1['id'] ) && str_contains( $h, 'ya tiene tareas' ), 'aplicarla otra vez no duplica nada' );
	$h = $pintar( new EventosView(), array( 'evento' => (string) $e1['id'], 'pestana' => 'calendario' ) );
	$ok( str_contains( $h, 'Ver lugar de acampada' ) && str_contains( $h, 'Más adelante' ) && str_contains( $h, 'name="asde_action" value="tarea_hecha"' ) && str_contains( $h, 'data-asde-imprimir-pagina' ), 'la pestaña enseña las tareas con su semáforo, el botón de hecha y el de imprimir' );

	echo "\n== Parte C · Marcar, responsables y fechas propias ==\n";
	$tarea = $por_titulo['Ver lugar de acampada'];
	$enviar( new EventosView(), array( 'asde_action' => 'tarea_hecha', 'evento' => (string) $e1['id'], 'pestana' => 'calendario', 'tarea' => (string) $tarea['id'] ), 'asde_evento_' . $e1['id'] );
	$q = TareasTable::obtener( $tarea['id'] );
	$ok( null !== $q['hecha_en'] && $u_lob === $q['hecha_por'] && 'hecha' === Tareas::estado( $q ), 'un scouter con acceso la marca como hecha (P-187), con quién y cuándo' );
	$enviar( new EventosView(), array( 'asde_action' => 'tarea_deshecha', 'evento' => (string) $e1['id'], 'pestana' => 'calendario', 'tarea' => (string) $tarea['id'] ), 'asde_evento_' . $e1['id'] );
	$ok( null === TareasTable::obtener( $tarea['id'] )['hecha_en'], 'y la puede desmarcar' );

	$como( $u_sco );
	$h = $enviar( new EventosView(), array( 'asde_action' => 'tarea_hecha', 'evento' => (string) $e1['id'], 'pestana' => 'calendario', 'tarea' => (string) $tarea['id'] ), 'asde_evento_' . $e1['id'] );
	$ok( null === TareasTable::obtener( $tarea['id'] )['hecha_en'] && str_contains( $h, 'No tienes acceso a ese evento' ), 'un scouter de otra sección (sin acceso al evento) no marca nada' );
	$a_fam = $como( $u_fam );
	$ok( ! Servicio::puede_entrar() && ! Tareas::puede_marcar( $e1, $a_fam ) && ! Tareas::puede_ver( $e1, $a_fam ), 'una familia ni entra en los eventos ni marca tareas' );
	$a_te = $como( $u_teso );
	$ok( Tareas::puede_ver( $e1, $a_te ) && Tareas::puede_marcar( $e1, $a_te ) && ! Tareas::puede_editar( $e1, $a_te ), 'Tesorería ve todos los eventos y, como scouter, marca tareas; pero no cambia el calendario de uno que no gestiona' );

	$como( $u_lob );
	$enviar( new EventosView(), array( 'asde_action' => 'evento_equipo_anadir', 'evento' => (string) $e1['id'], 'pestana' => 'equipo', 'socio' => '990041', 'papel' => 'director' ), 'asde_evento_' . $e1['id'] );
	$enviar( new EventosView(), array( 'asde_action' => 'tarea_guardar', 'evento' => (string) $e1['id'], 'pestana' => 'calendario', 'tarea' => (string) $tarea['id'], 'responsable' => '990041', 'fecha' => '2027-01-25', 'nota' => 'Visitamos el sábado' ), 'asde_evento_' . $e1['id'] );
	$q = TareasTable::obtener( $tarea['id'] );
	$ok( '990041' === $q['responsable'] && '2027-01-25' === TareasTable::fecha( $q ) && 'Visitamos el sábado' === $q['nota'], 'quien gestiona asigna un responsable del equipo, una fecha propia y una nota' );
	$h = $enviar( new EventosView(), array( 'asde_action' => 'tarea_guardar', 'evento' => (string) $e1['id'], 'pestana' => 'calendario', 'tarea' => (string) $tarea['id'], 'responsable' => '990042', 'fecha' => '' ), 'asde_evento_' . $e1['id'] );
	$ok( '990041' === TareasTable::obtener( $tarea['id'] )['responsable'] && str_contains( $h, 'del equipo del evento' ), 'el responsable tiene que estar en el equipo' );
	$h = $enviar( new EventosView(), array( 'asde_action' => 'tarea_guardar', 'evento' => (string) $e1['id'], 'pestana' => 'calendario', 'tarea' => (string) $tarea['id'], 'responsable' => '990041', 'fecha' => '2027-02-30' ), 'asde_evento_' . $e1['id'] );
	$ok( '2027-01-25' === TareasTable::fecha( TareasTable::obtener( $tarea['id'] ) ) && str_contains( $h, 'fecha no es válida' ), 'una fecha imposible → no' );

	echo "\n== Parte D · Si cambian las fechas del evento ==\n";
	$hecha = $por_titulo['Reservar el lugar de acampada'];
	$enviar( new EventosView(), array( 'asde_action' => 'tarea_hecha', 'evento' => (string) $e1['id'], 'pestana' => 'calendario', 'tarea' => (string) $hecha['id'] ), 'asde_evento_' . $e1['id'] );
	$nuevo_ini = '2027-04-09';
	$nuevo_fin = '2027-04-11';
	$h = $enviar( new EventosView(), array( 'asde_action' => 'evento_guardar', 'evento' => (string) $e1['id'], 'pestana' => 'resumen' ) + $formulario( $nuevo_ini, $nuevo_fin ), 'asde_evento_' . $e1['id'] );
	$ts = array_column( TareasTable::de_evento( $e1['id'] ), null, 'titulo' );
	$ok( '2027-02-23' === $ts['Pedir presupuesto a los autobuses y prereservar']['fecha_calc'] && '2027-04-18' === $ts['Escribir un post de la acampada en la web de grupo']['fecha_calc'], 'las tareas sin hacer se recalculan (−45 días del nuevo inicio, fin + 7)' );
	$ok( '2027-01-25' === TareasTable::fecha( $ts['Ver lugar de acampada'] ) && '2027-01-18' === $ts['Reservar el lugar de acampada']['fecha_calc'], 'la de fecha propia y la ya hecha no se mueven' );
	$ok( str_contains( $h, 'Han cambiado las fechas de' ), 'el aviso dice cuántas tareas han cambiado de fecha' );
	$antes = TareasTable::contar( $e1['id'] );
	$enviar( new EventosView(), array( 'asde_action' => 'tarea_anadir', 'evento' => (string) $e1['id'], 'pestana' => 'calendario', 'titulo' => 'Avisar al ayuntamiento', 'fecha' => '2027-03-01', 'detalle' => 'Permiso del recinto' ), 'asde_evento_' . $e1['id'] );
	$propia = array_values( array_filter( TareasTable::de_evento( $e1['id'] ), static fn( $t ) => 'Avisar al ayuntamiento' === $t['titulo'] ) )[0] ?? null;
	$ok( $propia && $antes + 1 === TareasTable::contar( $e1['id'] ) && 1 === $propia['propia'] && '2027-03-01' === TareasTable::fecha( $propia ), 'quien gestiona añade una tarea propia con su fecha' );
	$enviar( new EventosView(), array( 'asde_action' => 'evento_guardar', 'evento' => (string) $e1['id'], 'pestana' => 'resumen' ) + $formulario( '2027-05-07', '2027-05-09' ), 'asde_evento_' . $e1['id'] );
	$ok( '2027-03-01' === TareasTable::fecha( TareasTable::obtener( $propia['id'] ) ), 'una tarea propia no se mueve con las fechas del evento' );
	$enviar( new EventosView(), array( 'asde_action' => 'tarea_quitar', 'evento' => (string) $e1['id'], 'pestana' => 'calendario', 'tarea' => (string) $propia['id'] ), 'asde_evento_' . $e1['id'] );
	$ok( null === TareasTable::obtener( $propia['id'] ) && $antes === TareasTable::contar( $e1['id'] ), 'y la quita' );

	echo "\n== Parte E · Semáforo y avisos del inicio ==\n";
	$e = array( 'inicio' => $dia( '+20 days' ) . ' 10:00:00', 'fin' => $dia( '+22 days' ) . ' 17:00:00' );
	$t = array( 'hecha_en' => null, 'fecha_propia' => null, 'fecha_aj' => $dia( '-3 days' ), 'fecha_calc' => $dia( '-3 days' ) );
	$ok( 'vencida' === Tareas::estado( $t ) && -3 === Tareas::dias( $t ), 'una fecha pasada: vencida, hace 3 días' );
	$t['fecha_aj'] = $dia( 'today' );
	$ok( 'semana' === Tareas::estado( $t ) && 0 === Tareas::dias( $t ), 'la de hoy: esta semana, vence hoy' );
	$t['fecha_aj'] = $dia( '+7 days' );
	$ok( 'semana' === Tareas::estado( $t ), 'dentro de 7 días: todavía esta semana' );
	$t['fecha_aj'] = $dia( '+8 days' );
	$ok( 'pronto' === Tareas::estado( $t ), 'dentro de 8 días: más adelante' );
	$t['hecha_en'] = '2026-01-01 00:00:00';
	$t['fecha_aj'] = $dia( '-30 days' );
	$ok( 'hecha' === Tareas::estado( $t ), 'una hecha es hecha, aunque su fecha ya pasase' );

	$e2_id = TareasTable::crear( $e1['id'], 5000, array( 'titulo' => 'Tarea de prueba vencida', 'propia' => 1, 'fecha_calc' => $dia( '-5 days' ), 'fecha_aj' => $dia( '-5 days' ), 'fecha_propia' => $dia( '-5 days' ) ) );
	$a_lob = $como( $u_lob );
	$p     = Tareas::pendientes( $a_lob );
	$ok( $p['vencidas'] >= 1, 'el inicio cuenta las vencidas de los eventos que gestiona (' . $p['vencidas'] . ')' );
	$ok( 0 === array_sum( Tareas::pendientes( $como( $u_sco ) ) ) && 0 === array_sum( Tareas::pendientes( $como( $u_fam ) ) ), 'otra sección y las familias no ven tareas de ese evento' );
	$como( $u_lob );
	$html = ( new AreaPrivadaView() )->render();
	$ok( str_contains( $html, 'de evento vencida' ) || str_contains( $html, 'de eventos vencidas' ), 'y «Pendiente de revisar» del Inicio las enseña' );
	TareasTable::borrar( $e2_id );

	echo "\n== Parte F · Configuración: plantillas propias y el ajuste de fin de semana ==\n";
	$como( $u_coord );
	$cfg = Config::de();
	$ok( true === $cfg['calendario_viernes'] && array() === $cfg['plantillas'] && Plantillas::es_original( $cfg ), 'de serie: fin de semana al viernes y solo la plantilla original' );
	$enviar( new EventosConfigView(), array( 'asde_action' => 'calendario_guardar' ), 'asde_eventos_config' );
	$ok( false === Config::de()['calendario_viernes'], 'Coordinación apaga el ajuste de fin de semana' );
	$enviar( new EventosConfigView(), array( 'asde_action' => 'calendario_guardar', 'calendario_viernes' => '1', 'avisos_tareas' => '1' ), 'asde_eventos_config' );
	$enviar( new EventosConfigView(), array( 'asde_action' => 'plantilla_nueva', 'nombre' => 'Salida de día', 'copiar' => '' ), 'asde_eventos_config' );
	$ps = Plantillas::de_grupo();
	$ok( isset( $ps['salida-de-dia'] ) && array() === $ps['salida-de-dia']['tareas'], 'crea una plantilla propia, vacía' );
	$enviar(
		new EventosConfigView(),
		array(
			'asde_action'  => 'plantilla_guardar',
			'plantilla'    => 'salida-de-dia',
			'nombre'       => 'Salida de día',
			'filas'        => '0',
			'n_titulo_0'   => 'Pedir el permiso',
			'n_ref_0'      => 'inicio',
			'n_desfase_0'  => '-14',
			'n_titulo_1'   => 'Subir las fotos',
			'n_ref_1'      => 'fin',
			'n_desfase_1'  => '3',
			'n_titulo_2'   => '',
		),
		'asde_eventos_config'
	);
	$ps = Plantillas::de_grupo();
	$ok( 2 === count( $ps['salida-de-dia']['tareas'] ) && -14 === $ps['salida-de-dia']['tareas'][0]['desfase'] && 'fin' === $ps['salida-de-dia']['tareas'][1]['ref'], 'guarda sus tareas (la vacía se descarta)' );
	$enviar( new EventosConfigView(), array( 'asde_action' => 'plantilla_guardar', 'plantilla' => 'acampada', 'nombre' => 'Acampada', 'filas' => '1', 't_titulo_0' => 'Solo una', 't_ref_0' => 'inicio', 't_desfase_0' => '-1' ), 'asde_eventos_config' );
	$cfg = Config::de();
	$ok( ! Plantillas::es_original( $cfg ) && 1 === count( Plantillas::de_grupo()['acampada']['tareas'] ), 'cambia la de Acampada del grupo' );
	$enviar( new EventosConfigView(), array( 'asde_action' => 'plantilla_restaurar' ), 'asde_eventos_config' );
	$ok( Plantillas::es_original( Config::de() ) && 31 === count( Plantillas::de_grupo()['acampada']['tareas'] ), 'y la restaura a la original (31 tareas)' );
	$enviar( new EventosConfigView(), array( 'asde_action' => 'plantilla_borrar', 'plantilla' => 'acampada' ), 'asde_eventos_config' );
	$ok( 31 === count( Plantillas::de_grupo()['acampada']['tareas'] ), 'la de Acampada no se borra' );
	$enviar( new EventosConfigView(), array( 'asde_action' => 'plantilla_borrar', 'plantilla' => 'salida-de-dia' ), 'asde_eventos_config' );
	$ok( ! isset( Plantillas::de_grupo()['salida-de-dia'] ), 'una propia sí' );
	$h = $pintar( new EventosConfigView() );
	$ok( str_contains( $h, 'Calendario de tareas' ) && str_contains( $h, 'name="asde_action" value="plantilla_guardar"' ), 'la configuración enseña el calendario de tareas y el editor de la plantilla' );
	$como( $u_lob );
	$ok( ! current_user_can( Servicio::CAP_CONFIG ), 'un scouter sin permiso de configuración no entra en ella' );

	echo "\n== Parte G · Tareas que se comprueban solas ==\n";
	$a_lob = $como( $u_lob );
	$enviar( new EventosView(), array( 'asde_action' => 'evento_crear' ) + $formulario( '2027-03-19', '2027-03-21' ), 'asde_evento_nuevo' );
	$e3 = $ultimo();
	$enviar( new EventosView(), array( 'asde_action' => 'tarea_aplicar', 'evento' => (string) $e3['id'], 'pestana' => 'calendario', 'plantilla' => 'acampada' ), 'asde_evento_' . $e3['id'] );
	$t3   = array_column( TareasTable::de_evento( $e3['id'] ), null, 'titulo' );
	$auto = Tareas::automaticas( $e3, array_values( $t3 ) );
	$ok( false === ( $auto[ $t3['Estimar presupuesto de acampada']['id'] ] ?? null ) && false === ( $auto[ $t3['Elegir cocinero, intendente y ayudantes']['id'] ] ?? null ) && false === ( $auto[ $t3['Recoger autorizaciones, totales de educandos y enviar datos a las secciones']['id'] ] ?? null ), 'sin partidas, sin equipo y sin inscripciones, las tres comprobaciones salen sin cumplir' );
	$ok( ! isset( $auto[ $t3['Estimar el número de títulos disponibles / necesarios']['id'] ] ) && false === ( $auto[ $t3['Elaborar menú']['id'] ] ?? null ) && ! isset( $auto[ $t3['Enviar la ficha de la acampada a la OF (Organización Federada)']['id'] ] ), 'la de títulos (necesita leer el CRM) y la de la comunicación no se miden (las marca una persona); la del menú sí (v0.41) y, sin menú, no se cumple' );
	$ok( true === Tareas::comprobar( $e3, 'titulos', array( 'leido' => true, 'avisos' => array() ) ) && false === Tareas::comprobar( $e3, 'titulos', array( 'leido' => true, 'avisos' => array( 'Falta algo' ) ) ) && false === Tareas::comprobar( $e3, 'titulos', array( 'leido' => false, 'avisos' => array() ) ) && null === Tareas::comprobar( $e3, 'titulos' ), 'títulos: se cumple si el CRM se ha leído y la normativa no avisa de nada; sin leer, no se mide' );
	Servicio::anadir_partida( $e3, $a_lob, 'gasto', 'Albergue', 'total', '', 500.0 );
	$ok( true === Tareas::comprobar( $e3, 'presupuesto' ), 'con una partida, «Estimar presupuesto» se cumple sola' );
	$h = $pintar( new EventosView(), array( 'evento' => (string) $e3['id'], 'pestana' => 'calendario' ) );
	$ok( str_contains( $h, 'Automática' ), 'y la pestaña la marca como automática' );
	Servicio::anadir_equipo( $e3, $a_lob, '990041', 'director' );
	$ok( false === Tareas::comprobar( $e3, 'equipo' ), 'un director sin los monitores que pide la ratio: aún no' );
	Servicio::anadir_equipo( $e3, $a_lob, '990042', 'monitor' );
	Servicio::anadir_equipo( $e3, $a_lob, '990043', 'monitor' );
	$ok( true === Tareas::comprobar( $e3, 'equipo' ), 'director/a y monitores suficientes: se cumple sola' );
	$ok( false === Tareas::comprobar( $e3, 'autorizaciones' ), 'sin inscripciones no hay autorizaciones recogidas' );

	$aut = $t3['Recoger autorizaciones, totales de educandos y enviar datos a las secciones'];
	$h   = $enviar( new EventosView(), array( 'asde_action' => 'tarea_hecha', 'evento' => (string) $e3['id'], 'pestana' => 'calendario', 'tarea' => (string) $aut['id'] ), 'asde_evento_' . $e3['id'] );
	$ok( null === TareasTable::obtener( $aut['id'] )['hecha_en'] && str_contains( $h, 'se marca sola' ), 'una tarea automática que no se cumple no se marca a mano sin más' );
	$enviar( new EventosView(), array( 'asde_action' => 'tarea_hecha', 'evento' => (string) $e3['id'], 'pestana' => 'calendario', 'tarea' => (string) $aut['id'], 'nota' => 'No procede: es una salida sin menores' ), 'asde_evento_' . $e3['id'] );
	$q = TareasTable::obtener( $aut['id'] );
	$ok( null !== $q['hecha_en'] && 'No procede: es una salida sin menores' === $q['nota'], 'pero sí se cierra con una observación («no procede»)' );

	echo "\n== Parte H · Avisos por email de las tareas ==\n";
	$hoy = wp_date( 'Y-m-d' );
	$ver = $t3['Ver lugar de acampada'];
	TareasTable::actualizar( $ver['id'], array( 'fecha_propia' => Tareas::sumar( $hoy, 3 ), 'responsable' => '990041' ) );
	$n_av = AvisosTareas::diario();
	$ok( 1 === $n_av, 'a 3 días de la fecha, sale un aviso al responsable' );
	$filas = array_values( array_filter( RegistroEmails::listado(), static fn( $f ) => (int) $f['id'] > $email_antes && RegistroEmails::EVENTOS === $f['tipo'] ) );
	$ok( 1 === count( $filas ) && 'prueba-cal-lobatos' === $filas[0]['login'] && str_contains( $filas[0]['asunto'], 'vence pronto' ), 'queda en el registro de emails, a su cuenta, con el asunto «vence pronto»' );
	$ok( 0 === AvisosTareas::diario(), 'y no se repite al día siguiente' );
	TareasTable::actualizar( $ver['id'], array( 'fecha_propia' => $hoy ) );
	$ok( 1 === AvisosTareas::diario() && 0 === AvisosTareas::diario(), 'el día que vence sale otro, una sola vez' );
	$filas = array_values( array_filter( RegistroEmails::listado(), static fn( $f ) => (int) $f['id'] > $email_antes && RegistroEmails::EVENTOS === $f['tipo'] ) );
	$vista = RegistroEmails::vista_previa( $filas[0] );
	$ok( str_contains( $vista, 'Ver lugar de acampada' ) && str_contains( $vista, 'Hoy vence' ), 'la vista previa del registro vuelve a componer el texto' );
	$ok( ! str_contains( (string) $filas[0]['datos'], '990041' ) && ! str_contains( (string) $filas[0]['datos'], '@' ), 'el registro no guarda nº de socio ni emails: solo la tarea, el evento y la fecha' );
	$nuevo = TareasTable::crear( $e3['id'], 6000, array( 'titulo' => 'Sin responsable', 'propia' => 1, 'fecha_calc' => $hoy, 'fecha_aj' => $hoy, 'fecha_propia' => $hoy ) );
	$ok( 1 === AvisosTareas::diario(), 'sin responsable, el aviso va al director/a del evento' );
	$hecha = TareasTable::crear( $e3['id'], 6001, array( 'titulo' => 'Ya hecha', 'propia' => 1, 'fecha_calc' => $hoy, 'fecha_aj' => $hoy, 'fecha_propia' => $hoy ) );
	TareasTable::marcar( $hecha, true );
	$ok( 0 === AvisosTareas::diario(), 'de lo que ya está hecho no se avisa' );
	$otra = TareasTable::crear( $e3['id'], 6002, array( 'titulo' => 'Apagado', 'propia' => 1, 'fecha_calc' => $hoy, 'fecha_aj' => $hoy, 'fecha_propia' => $hoy ) );
	$cfg_av                  = Config::de();
	$cfg_av['avisos_tareas'] = false;
	Config::guardar( $cfg_av, $grupo['numero'] );
	$ok( 0 === AvisosTareas::diario(), 'con los avisos de tareas desactivados en el grupo, no sale nada' );
	$cfg_av['avisos_tareas'] = true;
	Config::guardar( $cfg_av, $grupo['numero'] );
	foreach ( array( $nuevo, $hecha, $otra ) as $id ) {
		TareasTable::borrar( $id );
	}

	echo "\n== Parte H2 · Presupuesto: partidas sugeridas y autobuses ==\n";
	$ok( 2 === Presupuesto::autobuses( 62 ) && 1 === Presupuesto::autobuses( 55 ) && 2 === Presupuesto::autobuses( 56 ) && 0 === Presupuesto::autobuses( 0 ) && 3 === Presupuesto::autobuses( 100, 40 ), 'autobuses = personas ÷ plazas, hacia arriba (55 plazas por defecto)' );
	$antes = count( PartidasTable::previstas( $e3['id'] ) );
	$id    = Servicio::anadir_autobuses( $e3, $a_lob, 62, 55, 300.0 );
	$partidas = PartidasTable::previstas( $e3['id'] );
	$ultima   = end( $partidas );
	$ok( ! is_wp_error( $id ) && $antes + 1 === count( $partidas ) && 600.0 === (float) $ultima['importe'] && 'total' === $ultima['reparto'] && str_contains( $ultima['concepto'], '2 ×' ), 'la partida de autobuses: 2 × 300 € = 600 €, repartida entre todos' );
	$ok( is_wp_error( Servicio::anadir_autobuses( $e3, $a_lob, 62, 55, 0.0 ) ) && is_wp_error( Servicio::anadir_autobuses( $e3, $a_lob, 0, 55, 300.0 ) ), 'sin precio o sin personas → error' );
	$h = $pintar( new EventosView(), array( 'evento' => (string) $e3['id'], 'pestana' => 'presupuesto' ) );
	$ok( str_contains( $h, 'asde-partidas-sugeridas' ) && str_contains( $h, 'Alimentación' ) && str_contains( $h, 'name="asde_action" value="evento_autobuses"' ), 'el presupuesto sugiere las categorías de la hoja y enseña la calculadora de autobuses' );
	echo "\n== Parte I · Datos y limpieza ==\n";
	$cols = array_column( (array) $wpdb->get_results( 'SHOW COLUMNS FROM ' . TareasTable::table(), ARRAY_A ), 'Field' ); // phpcs:ignore WordPress.DB
	$ok( ! array_intersect( $cols, array( 'nombre', 'email', 'telefono', 'dni', 'salud', 'alergias' ) ), 'la tabla de tareas no tiene columnas de datos personales (el responsable es un nº Scouts de España)' );
	$como( $u_lob );
	$enviar( new EventosView(), array( 'asde_action' => 'evento_borrar', 'evento' => (string) $e1['id'], 'pestana' => 'resumen' ), 'asde_evento_' . $e1['id'] );
	$ok( 0 === TareasTable::contar( $e1['id'] ) && null === EventosTable::obtener( $e1['id'] ), 'al borrar el borrador se borran también sus tareas' );
	$ok( $crm->llamadas > 0 && 0 === $cortadas, "todas las consultas al CRM las ha respondido el simulador ({$crm->llamadas}); ninguna ha salido hacia el CRM real" );
} finally {
	remove_filter( 'wp_redirect', $redirigir, 1 );
	remove_filter( 'send_auth_cookies', '__return_false' );
	remove_filter( 'pre_wp_mail', '__return_true' );
	$_POST = array();
	$_GET  = array();
	View::en_modal( false );
	$crm->desactivar();
	remove_filter( 'pre_http_request', $cortar_crm, 2 );
	wp_using_ext_object_cache( $cache_externa );
	wp_set_current_user( 0 );

	$lista = implode( ',', array_map( 'intval', $ids ) ) ?: '0';
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . TareasTable::table() . ' WHERE id > %d', $tar_antes ) ); // phpcs:ignore WordPress.DB
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . PartidasTable::table() . ' WHERE id > %d', $par_antes ) ); // phpcs:ignore WordPress.DB
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . RegistroEmails::table() . ' WHERE id > %d', $email_antes ) ); // phpcs:ignore WordPress.DB
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . InscripcionesTable::table() . ' WHERE id > %d', $ins_antes ) ); // phpcs:ignore WordPress.DB
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . EventosTable::table() . ' WHERE id > %d', $ev_antes ) ); // phpcs:ignore WordPress.DB
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Logger::table() . " WHERE id > %d AND ( user_id IN ($lista) OR objeto = 'evento' )", $log_antes ) ); // phpcs:ignore WordPress.DB
	foreach ( $ids as $uid ) {
		Ambito::olvidar( (int) $uid );
		wp_delete_user( (int) $uid );
	}
	if ( null === $cfg_antes ) {
		delete_option( $opcion_cfg );
	} else {
		update_option( $opcion_cfg, $cfg_antes, false );
	}
	if ( null === $modulos_antes ) {
		delete_option( 'asde_ap_modulos' );
	} else {
		update_option( 'asde_ap_modulos', $modulos_antes );
	}
	$ahora = array_column( (array) $wpdb->get_results( 'SELECT * FROM ' . SociosTable::table(), ARRAY_A ), null, 'num_socio_asde' ); // phpcs:ignore WordPress.DB
	foreach ( array_diff_key( $ahora, $socios_antes ) as $asde => $f ) {
		$wpdb->delete( SociosTable::table(), array( 'num_socio_asde' => (string) $asde ) );
	}
	foreach ( $socios_antes as $asde => $f ) {
		if ( ( $ahora[ $asde ] ?? null ) !== $f ) {
			$wpdb->replace( SociosTable::table(), $f );
		}
	}
	Grupo::forzar( null );

	$quedan = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users} WHERE user_login LIKE 'prueba-cal-%'" ); // phpcs:ignore WordPress.DB
	$ok( 0 === $quedan && 0 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . EventosTable::table() . ' WHERE id > ' . (int) $ev_antes ) && 0 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . TareasTable::table() . ' WHERE id > ' . (int) $tar_antes ) && get_option( $opcion_cfg, null ) == $cfg_antes, 'limpieza: sin usuarios, eventos ni tareas de la prueba; configuración de eventos como estaba' ); // phpcs:ignore WordPress.DB
}

echo $fails ? "\n$fails FALLOS\n" : "\nTODO OK (nada tocado en el CRM real; el sitio queda como estaba)\n";
