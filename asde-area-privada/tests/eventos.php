<?php
/**
 * Pruebas de la v0.35 (entorno LOCAL): eventos, equipo dirigente y presupuesto (doc 43).
 * Ejecutar desde app/public del site Local:
 *
 *   wp eval-file <ruta completa>/wp-content/plugins/asde-area-privada/tests/eventos.php
 *
 * Usa el CRM simulado de tests/lib (fichas inventadas: ninguna petición llega al
 * CRM real) y guarda las cachés solo en la memoria de este proceso, así que la
 * web no las ve. Al terminar se borra lo creado (usuarios, eventos, equipo,
 * partidas, registros) y se restauran la configuración de eventos del grupo y la
 * tabla de números.
 */
defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/lib/CrmSimulado.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Auth\Ambito;
use AsdeBalears\AreaPrivada\Crm\Escritura;
use AsdeBalears\AreaPrivada\Domain\Grupo;
use AsdeBalears\AreaPrivada\Domain\Grupos;
use AsdeBalears\AreaPrivada\Domain\Persona;
use AsdeBalears\AreaPrivada\Eventos\Config;
use AsdeBalears\AreaPrivada\Eventos\EventosTable;
use AsdeBalears\AreaPrivada\Eventos\InscripcionesTable;
use AsdeBalears\AreaPrivada\Eventos\Normativa;
use AsdeBalears\AreaPrivada\Eventos\PartidasTable;
use AsdeBalears\AreaPrivada\Eventos\Presupuesto;
use AsdeBalears\AreaPrivada\Eventos\Servicio;
use AsdeBalears\AreaPrivada\Frontend\Menu;
use AsdeBalears\AreaPrivada\Frontend\Views\AreaPrivadaView;
use AsdeBalears\AreaPrivada\Frontend\Views\EventosConfigView;
use AsdeBalears\AreaPrivada\Frontend\Views\EventosView;
use AsdeBalears\AreaPrivada\Frontend\Views\View;
use AsdeBalears\AreaPrivada\Install\Roles;
use AsdeBalears\AreaPrivada\Socios\SociosTable;
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
foreach ( get_users( array( 'search' => 'prueba-ev-*', 'search_columns' => array( 'user_login' ) ) ) as $u ) {
	exit( 'Ya existe el usuario ' . $u->user_login . " (¿una ejecución anterior interrumpida?). Bórralo antes de repetir la prueba.\n" );
}
wp_set_current_user( 0 );

// --- Lo que se restaura al final ---------------------------------------------
$max_id     = static fn( string $t ) => (int) $wpdb->get_var( "SELECT COALESCE(MAX(id), 0) FROM $t" ); // phpcs:ignore WordPress.DB
$log_antes  = $max_id( Logger::table() );
$ev_antes   = $max_id( EventosTable::table() );
$ins_antes  = $max_id( InscripcionesTable::table() );
$par_antes  = $max_id( PartidasTable::table() );
$socios_antes = array_column( (array) $wpdb->get_results( 'SELECT * FROM ' . SociosTable::table(), ARRAY_A ), null, 'num_socio_asde' ); // phpcs:ignore WordPress.DB
$opcion_cfg = 'asde_ap_eventos_' . $grupo['numero'];
$cfg_antes  = get_option( $opcion_cfg, null );

// Cachés (transients) solo en la memoria de este proceso: la web real no ve las del CRM simulado.
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

// Fechas relativas a hoy: el evento empieza dentro de 45 días y dura 2 noches.
$dia     = static fn( string $mod ) => wp_date( 'Y-m-d', strtotime( $mod ) );
$ini     = $dia( '+45 days' );
$fin     = $dia( '+47 days' );
$cert_ok = $dia( '-2 months' );
$cert_ko = wp_date( 'Y-m-d', strtotime( $fin . ' -13 months' ) );

$P   = array( 'Asde_Prueba_Crm', 'persona' );
$crm = new Asde_Prueba_Crm(
	array(
		$P( 31, 'Leo', 'Evento Ruiz', '2016-05-10', 'Lobatos' ),
		$P( 32, 'Nora', 'Evento Ruiz', '2013-02-20', 'Scouts' ),
		$P( 33, 'Ana', 'Evento Gil', '1990-01-01', 'Scouters', array( 'formaciontiempolibre_c' => 'Monitor/a de tiempo libre', 'certificadods_c' => '1', 'certificadods_fecha_c' => $cert_ok ) ),
		$P( 34, 'Tom', 'Evento Luz', '1985-05-05', 'Scouters', array( 'formaciontiempolibre_c' => '', 'certificadods_c' => '1', 'certificadods_fecha_c' => $cert_ko ) ),
		$P( 35, 'Eva', 'Evento Coll', '1980-08-08', 'Scouters', array( 'formaciontiempolibre_c' => 'Director/a de tiempo libre', 'certificadods_c' => '1', 'certificadods_fecha_c' => $cert_ok ) ),
		$P( 36, 'Pol', 'Evento Mas', '1992-03-03', 'Scouters', array( 'certificadods_c' => '1' ) ),
		$P( 37, 'Iris', 'Evento Sanz', '1988-07-07', 'Scouters' ),
		$P( 38, 'Rex', 'Evento Vidal', '2008-05-05', 'Rovers' ),
		$P( 39, 'Gil', 'Evento Pons', '1995-09-09', 'Voluntariado' ),
	),
	$grupo['crm_id']
);
Grupo::forzar( $grupo );
// v0.35: el módulo «Eventos» nace apagado; la prueba lo enciende para el grupo y lo deja como estaba.
$modulos_antes = get_option( 'asde_ap_modulos', null );
\AsdeBalears\AreaPrivada\Domain\Modulos::guardar( (string) $grupo['numero'], array_keys( \AsdeBalears\AreaPrivada\Domain\Modulos::LISTA ) );
$crm->activar();
// La instantánea de fichas, apagada (como en las demás pruebas): cada lectura va al CRM simulado.
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
$u_lob   = $crear( 'prueba-ev-lobatos', array( Roles::SCOUTER ), array( Usuarios::META_SOCIO => '990033', Usuarios::META_SECCION => 'lobatos' ) );
$u_sco   = $crear( 'prueba-ev-scouts', array( Roles::SCOUTER ), array( Usuarios::META_SOCIO => '990034', Usuarios::META_SECCION => 'scouts' ) );
$u_coord = $crear( 'prueba-ev-coord', array( Roles::SCOUTER, Roles::COORDINACION ), array( Usuarios::META_SOCIO => '990035', Usuarios::META_SECCION => 'rovers' ) );
$u_teso  = $crear( 'prueba-ev-teso', array( Roles::SCOUTER, Roles::TESORERIA ), array( Usuarios::META_SOCIO => '990036', Usuarios::META_SECCION => 'escultas' ) );
$u_secre = $crear( 'prueba-ev-secre', array( Roles::SCOUTER, Roles::SECRETARIA ), array( Usuarios::META_SOCIO => '990037', Usuarios::META_SECCION => 'castores' ) );
$u_fam   = $crear( 'prueba-ev-fam', array( Roles::TUTOR ), array( Usuarios::META_REPRESENTADO => '990031' ) );

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
/** Envía un formulario a una vista como lo haría el navegador y devuelve los avisos. */
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
	return \AsdeBalears\AreaPrivada\Frontend\Notices::render();
};
/** Pinta una vista con estos parámetros en la dirección. */
$pintar = static function ( $vista, array $get = array() ): string {
	$_GET = $get;
	try {
		return $vista->render();
	} finally {
		$_GET = array();
	}
};
$formulario = static fn( array $extra = array() ): array => $extra + array(
	'tipo'         => 'acampada',
	'nombre'       => 'Acampada de prueba',
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

try {
	Config::guardar( Config::defaults(), $grupo['numero'] );

	echo "== Parte A · Configuración y tipos de actividad ==\n";
	$cfg = Config::de();
	$ok( 8 === count( $cfg['tipos'] ) && isset( $cfg['tipos']['acampada'], $cfg['tipos']['campamento'], $cfg['tipos']['externo'], $cfg['tipos']['formacion'] ) && ! isset( $cfg['tipos']['reunion'] ), 'ocho tipos de actividad, sin reuniones (P-140)' );
	$ok( $cfg['margen_activo'] && 5.0 === $cfg['margen_pct'], 'el margen del fondo de emergencia nace activo y al 5 % (P-145)' );
	$ok( $cfg['tipos']['acampada']['coste'] && ! $cfg['tipos']['salida']['coste'] && 'no' === $cfg['tipos']['externo']['comunicacion'] && 'auto' === $cfg['tipos']['campamento']['comunicacion'], 'una acampada nace con coste; un externo no se comunica a ASDE Baleares (lo hace quien organiza)' );
	$malo                  = Config::defaults();
	$malo['margen_pct']    = 80;
	$malo['tipos']['raro'] = array( 'nombre' => '' );
	Config::guardar( $malo, $grupo['numero'] );
	$ok( 50.0 === Config::de()['margen_pct'] && ! isset( Config::de()['tipos']['raro'] ), 'al leer, el margen se limita al 50 % y un tipo sin nombre no cuenta' );
	Config::guardar( Config::defaults(), $grupo['numero'] );

	echo "\n== Parte B · El precio sale del coste (P-145) ==\n";
	// El ejemplo del plan (doc 43): 20 Lobatos y 15 Scouts, 6 del equipo.
	$partidas = array(
		array( 'tipo' => 'gasto', 'concepto' => 'Autobús', 'reparto' => 'total', 'seccion' => '', 'importe' => 420.0 ),
		array( 'tipo' => 'gasto', 'concepto' => 'Albergue', 'reparto' => 'participante_equipo', 'seccion' => '', 'importe' => 12.0 ),
		array( 'tipo' => 'gasto', 'concepto' => 'Comida', 'reparto' => 'participante_equipo', 'seccion' => '', 'importe' => 15.0 ),
		array( 'tipo' => 'gasto', 'concepto' => 'Escalada', 'reparto' => 'total_seccion', 'seccion' => 'scouts', 'importe' => 150.0 ),
	);
	$c = Presupuesto::calcular( $partidas, array( 'lobatos' => 20, 'scouts' => 15 ), 6, 5.0 );
	$ok( $c['ok'] && 46.0 === $c['precios']['lobatos'] && 57.0 === $c['precios']['scouts'], 'precio del ejemplo del plan: Lobatos 46 € y Scouts 57 €' );
	$ok( 43.63 === $c['costes']['lobatos'] && 53.63 === $c['costes']['scouts'], 'coste por participante: 43,63 € y 53,63 € (el del equipo, repartido)' );
	$ok( 1677.0 === $c['gastos'] && 1775.0 === $c['recaudacion'] && 35 === $c['participantes'] && 6 === $c['equipo'], 'gastos previstos 1.677 € (con el albergue y la comida de los 6 scouters) y recaudación de 1.775 €' );
	$ok( 98.0 === $c['fondo'], 'el fondo de emergencia es lo recaudado por encima del coste: 1.775 − 1.677 = 98 € (margen y redondeo)' );
	$sin = Presupuesto::calcular( $partidas, array( 'lobatos' => 20, 'scouts' => 15 ), 6, 0.0 );
	$ok( 44.0 === $sin['precios']['lobatos'] && 54.0 === $sin['precios']['scouts'], 'sin margen, solo el redondeo al euro hacia arriba: 44 € y 54 €' );
	$con_ingreso = Presupuesto::calcular( array_merge( $partidas, array( array( 'tipo' => 'ingreso', 'concepto' => 'Subvención', 'reparto' => 'total', 'seccion' => '', 'importe' => 350.0 ) ) ), array( 'lobatos' => 20, 'scouts' => 15 ), 6, 0.0 );
	$ok( 34.0 === $con_ingreso['precios']['lobatos'] && 350.0 === $con_ingreso['ingresos'], 'una subvención de 350 € se resta de lo común: 10 € menos por cabeza' );
	$exacto = Presupuesto::calcular( array( array( 'tipo' => 'gasto', 'concepto' => 'Cuota', 'reparto' => 'participante', 'seccion' => '', 'importe' => 45.0 ) ), array( 'lobatos' => 10 ), 0, 0.0 );
	$ok( 45.0 === $exacto['precios']['lobatos'], 'un coste exacto no sube al euro siguiente por la coma flotante (45 € → 45 €)' );
	$ok( ! Presupuesto::calcular( $partidas, array( 'lobatos' => 0 ), 6, 5.0 )['ok'], 'sin participantes previstos no hay precio' );
	$err = Presupuesto::calcular( $partidas, array( 'lobatos' => 20 ), 6, 5.0 );
	$ok( ! $err['ok'] && str_contains( implode( ' ', $err['errores'] ), 'Escalada' ), 'una partida solo de Scouts sin Scouts previstos es un error con su nombre' );
	$solo_equipo = Presupuesto::calcular( array( array( 'tipo' => 'gasto', 'concepto' => 'Plaza del scouter', 'reparto' => 'equipo', 'seccion' => '', 'importe' => 30.0 ) ), array( 'scouts' => 10 ), 2, 0.0 );
	$ok( 6.0 === $solo_equipo['precios']['scouts'] && 60.0 === $solo_equipo['gastos'], '«solo el equipo»: 2 scouters a 30 € se reparten entre los 10 participantes (6 € cada uno)' );

	echo "\n== Parte C · Normativa de tiempo libre (doc 43, apartado 6) ==\n";
	$ok( array( 0, 1, 1, 2, 4, 3 ) === array( Normativa::monitores_necesarios( 0 )['base'], Normativa::monitores_necesarios( 3 )['base'], Normativa::monitores_necesarios( 14 )['base'], Normativa::monitores_necesarios( 15 )['base'], Normativa::monitores_necesarios( 35 )['base'], Normativa::monitores_necesarios( 34 )['base'] ), 'ratio: 1 monitor por cada 10 y otro si sobran 5 o más (0→0, 3→1, 14→1, 15→2, 35→4, 34→3)' );
	$dep = Normativa::monitores_necesarios( 20, 4, 1 );
	$ok( 2 === $dep['base'] && 3 === $dep['apoyo'] && 5 === $dep['total'], 'dependencia: 4 de grado I-II piden 2 de apoyo y 1 de grado III, otro (5 en total con 20 participantes)' );
	$base_e = array(
		'inicio'     => $ini . ' 10:00:00',
		'fin'        => $fin . ' 17:00:00',
		'secciones'  => array( 'lobatos', 'rovers' ),
		'plazas'     => array(
			'lobatos' => array( 'previstas' => 10, 'maximas' => 0 ),
			'rovers'  => array( 'previstas' => 6, 'maximas' => 0 ),
		),
		'plazas_max' => 0,
		'normativa'  => Normativa::datos( array() ),
	);
	$ok( 2 === Normativa::noches( $base_e ) && 10 === Normativa::menores_previstos( $base_e ) && Normativa::requiere_comunicacion( $base_e ), '2 noches y 10 menores previstos (los Rovers no cuentan como menores): hay que comunicarlo a ASDE Baleares' );
	$una = array_merge( $base_e, array( 'fin' => $dia( '+46 days' ) . ' 12:00:00' ) );
	$ok( 1 === Normativa::noches( $una ) && ! Normativa::requiere_comunicacion( $una ), 'con 1 noche, no' );
	$nueve                                   = $base_e;
	$nueve['plazas']['lobatos']['previstas'] = 9;
	$ok( ! Normativa::requiere_comunicacion( $nueve ), 'con 9 menores, no' );
	$forzada = array_merge( $una, array( 'normativa' => Normativa::datos( array( 'comunicacion' => 'si' ) ) ) );
	$nunca   = array_merge( $base_e, array( 'normativa' => Normativa::datos( array( 'comunicacion' => 'no' ) ) ) );
	$ok( Normativa::requiere_comunicacion( $forzada ) && ! Normativa::requiere_comunicacion( $nunca ), '«sí, siempre» y «no» mandan sobre la regla' );
	$ok( $dia( '+15 days' ) === Normativa::limite_comunicacion( $base_e ), 'la fecha límite de la comunicación es 30 días antes del inicio' );
	$ok( 'director' === Normativa::titulacion( 'Director/a de tiempo libre', $cfg ) && 'monitor' === Normativa::titulacion( '^monitor^', $cfg ) && '' === Normativa::titulacion( '', $cfg ) && '' === Normativa::titulacion( 'Primeros auxilios', $cfg ), 'titulación sin configurar: se deduce del texto (director, monitor)' );
	$cfg_tit                 = $cfg;
	$cfg_tit['titulaciones'] = array(
		'director' => array( 'dtl' ),
		'monitor'  => array( 'mtl' ),
	);
	$ok( 'director' === Normativa::titulacion( '^mtl^,^dtl^', $cfg_tit ) && 'monitor' === Normativa::titulacion( 'mtl', $cfg_tit ) && '' === Normativa::titulacion( 'Director de tiempo libre', $cfg_tit ), 'con los valores elegidos en la configuración, solo cuentan esos' );
	$ok( 'ok' === Normativa::certificado( '1', $cert_ok, $base_e ) && 'caducado' === Normativa::certificado( '1', $cert_ko, $base_e ) && 'sin_fecha' === Normativa::certificado( '1', '', $base_e ) && 'no' === Normativa::certificado( '0', '', $base_e ), 'certificado de delitos sexuales el día que acaba: al día, caducado, sin fecha o no consta' );

	echo "\n== Parte D · Quién crea y gestiona (P-141) ==\n";
	$a_lob = $como( $u_lob );
	$ok( Servicio::puede_entrar() && Servicio::puede_crear( $a_lob ) && array( 'lobatos' ) === Servicio::secciones_para( $a_lob ) && array( 'secciones' ) === Servicio::ambitos_para( $a_lob ), 'un scouter de Lobatos entra, crea eventos y solo convoca a Lobatos' );
	$h = $enviar( new EventosView(), array( 'asde_action' => 'evento_crear' ) + $formulario(), 'asde_evento_nuevo' );
	$e = $ultimo();
	$ok( $e && $e['id'] > $ev_antes && 'Acampada de prueba' === $e['nombre'] && EventosTable::BORRADOR === $e['estado'] && array( 'lobatos' ) === $e['secciones'] && 20 === $e['plazas']['lobatos']['previstas'] && $e['con_coste'], 'crea la acampada de Lobatos como borrador, con 20 previstos y coste' );
	$ok( $e && str_starts_with( EventosTable::numero( $e ), 'E' . $grupo['numero'] ) && 8 === strlen( EventosTable::numero( $e ) ) && str_contains( $h, EventosTable::numero( $e ) ), 'nº de evento E + grupo + 4 cifras, en el aviso' );
	$ok( $e && 5.0 === $e['margen_pct'] && $e['margen_activo'] && 'auto' === $e['normativa']['comunicacion'], 'nace con el margen y la comunicación del tipo y de la configuración' );
	$id_lob = $e ? $e['id'] : 0;
	$antes  = $max_id( EventosTable::table() );
	$h      = $enviar( new EventosView(), array( 'asde_action' => 'evento_crear' ) + $formulario( array( 'secciones' => array( 'lobatos', 'scouts' ) ) ), 'asde_evento_nuevo' );
	$ok( $antes === $max_id( EventosTable::table() ) && str_contains( $h, 'Solo puedes convocar a las secciones en las que colaboras' ), 'no puede convocar a Scouts (no es de su sección)' );
	$h = $enviar( new EventosView(), array( 'asde_action' => 'evento_crear' ) + $formulario( array( 'ambito' => 'grupo' ) ), 'asde_evento_nuevo' );
	$ok( $antes === $max_id( EventosTable::table() ) && str_contains( $h, 'Coordinación' ), 'ni crear un evento de grupo' );
	$h    = $enviar( new EventosView(), array( 'asde_action' => 'evento_crear' ) + $formulario( array( 'nombre' => 'Mal fechado', 'fin_fecha' => $dia( '+40 days' ) ) ), 'asde_evento_nuevo' );
	$html = $pintar( new EventosView(), array( 'nuevo' => '1' ) );
	$ok( $antes === $max_id( EventosTable::table() ) && str_contains( $h, 'no puede acabar antes de empezar' ) && str_contains( $html, 'value="Mal fechado"' ), 'si acaba antes de empezar, no se guarda y el formulario vuelve con lo escrito' );

	$a_sco = $como( $u_sco );
	$e     = EventosTable::obtener( $id_lob );
	$ok( ! Servicio::ve( $e, $a_sco ) && ! Servicio::en_gestion( $e, $a_sco ) && ! in_array( $id_lob, array_column( Servicio::de_agenda( $a_sco ), 'id' ), true ), 'el scouter de Scouts no ve el evento de Lobatos' );
	$ok( str_contains( $pintar( new EventosView(), array( 'evento' => (string) $id_lob ) ), 'No tienes acceso a ese evento' ), 'ni entrando con la dirección' );
	$a_secre = $como( $u_secre );
	$ok( Servicio::ve( $e, $a_secre ) && Servicio::en_gestion( $e, $a_secre ) && array( 'secciones' ) === Servicio::ambitos_para( $a_secre ), 'Secretaría ve y gestiona los de sección de todo el grupo, pero no crea eventos de grupo' );
	$a_teso = $como( $u_teso );
	$ok( Servicio::ve( $e, $a_teso ) && ! Servicio::en_gestion( $e, $a_teso ) && Servicio::puede_entrar() && array( 'escultas' ) === Servicio::secciones_para( $a_teso ), 'Tesorería ve todos (la parte económica), pero como scouter solo gestiona los de su sección' );
	$a_fam = $como( $u_fam );
	$ok( ! Servicio::puede_entrar() && str_contains( $pintar( new EventosView() ), 'No tienes acceso' ), 'una familia no entra en la gestión de eventos' );
	$a_coord = $como( $u_coord );
	$enviar( new EventosView(), array( 'asde_action' => 'evento_crear' ) + $formulario( array( 'tipo' => 'grupo', 'nombre' => 'Fiesta de inicio de ronda', 'ambito' => 'grupo', 'secciones' => array(), 'con_coste' => '' ) ), 'asde_evento_nuevo' );
	$eg = $ultimo();
	$ok( $eg && 'grupo' === $eg['ambito'] && \AsdeBalears\AreaPrivada\Domain\Secciones::EDUCATIVAS === $eg['secciones'] && ! $eg['con_coste'], 'Coordinación crea un evento de grupo: sin secciones marcadas, se convoca a las cinco educativas' );
	$a_lob = $como( $u_lob );
	$ok( $eg && ! Servicio::en_gestion( $eg, $a_lob ) && ! Servicio::ve( $eg, $a_lob ), 'el de grupo no lo gestiona un scouter de una sección' );
	// Otro grupo: no se ve aunque se sepa su número.
	$wpdb->insert( EventosTable::table(), array( 'grupo' => '999', 'tipo' => 'salida', 'nombre' => 'De otro grupo', 'inicio' => $ini . ' 10:00:00', 'fin' => $ini . ' 18:00:00', 'creado' => current_time( 'mysql', true ) ) );
	$otro = (int) $wpdb->insert_id;
	$ok( null === EventosTable::obtener( $otro ) && ! in_array( $otro, array_column( EventosTable::todos(), 'id' ), true ), 'un evento de otro grupo no se lee ni sale en la agenda' );

	echo "\n== Parte E · Editar los datos ==\n";
	$a_lob = $como( $u_lob );
	$h     = $enviar( new EventosView(), array( 'asde_action' => 'evento_guardar', 'evento' => (string) $id_lob ) + $formulario( array( 'lugar' => 'Otro albergue', 'previstas_lobatos' => '22', 'mapa' => 'javascript:alert(1)' ) ), 'asde_evento_' . $id_lob );
	$ok( str_contains( $h, 'dirección web' ) && 'Albergue de prueba' === EventosTable::obtener( $id_lob )['lugar'], 'un enlace al mapa que no es una web se rechaza y no se guarda nada' );
	$h = $enviar( new EventosView(), array( 'asde_action' => 'evento_guardar', 'evento' => (string) $id_lob ) + $formulario( array( 'lugar' => 'Otro albergue', 'previstas_lobatos' => '20', 'maximas_lobatos' => '24', 'dependencia12' => '1' ) ), 'asde_evento_' . $id_lob );
	$e = EventosTable::obtener( $id_lob );
	$ok( 'Otro albergue' === $e['lugar'] && 24 === $e['plazas']['lobatos']['maximas'] && 1 === $e['normativa']['dependencia12'] && str_contains( $h, 'guardado' ), 'cambia el lugar, las plazas y la dependencia (solo el número)' );
	$log = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Logger::table() . " WHERE id > %d AND accion = 'evento_editado' ORDER BY id DESC LIMIT 1", $log_antes ), ARRAY_A ); // phpcs:ignore WordPress.DB
	$ok( $log && str_contains( (string) $log['detalles'], 'lugar' ) && ! str_contains( (string) $log['detalles'], 'Otro albergue' ), 'la auditoría anota qué campos cambian, no sus valores' );

	echo "\n== Parte F · Equipo dirigente ==\n";
	$cand = Servicio::candidatos();
	$claves = array_map( 'strval', array_keys( $cand ) );
	sort( $claves );
	$ok( array( '990033', '990034', '990035', '990036', '990037', '990039' ) === $claves && 'Ana Evento Gil' === reset( $cand )->nombre_completo(), 'pueden estar en el equipo los scouters y el voluntariado del grupo (no los Rovers ni los educandos), por nombre' );
	$h = $enviar( new EventosView(), array( 'asde_action' => 'evento_equipo_anadir', 'evento' => (string) $id_lob, 'socio' => '990038', 'papel' => 'monitor', 'pestana' => 'equipo' ), 'asde_evento_' . $id_lob );
	$ok( str_contains( $h, 'Elige a un scouter' ) && ! InscripcionesTable::equipo( $id_lob ), 'un Rover no entra en el equipo' );
	$enviar( new EventosView(), array( 'asde_action' => 'evento_equipo_anadir', 'evento' => (string) $id_lob, 'socio' => '990035', 'papel' => 'director', 'pestana' => 'equipo' ), 'asde_evento_' . $id_lob );
	$enviar( new EventosView(), array( 'asde_action' => 'evento_equipo_anadir', 'evento' => (string) $id_lob, 'socio' => '990033', 'papel' => 'monitor', 'pestana' => 'equipo' ), 'asde_evento_' . $id_lob );
	$enviar( new EventosView(), array( 'asde_action' => 'evento_equipo_anadir', 'evento' => (string) $id_lob, 'socio' => '990034', 'papel' => 'monitor', 'pestana' => 'equipo' ), 'asde_evento_' . $id_lob );
	$h      = $enviar( new EventosView(), array( 'asde_action' => 'evento_equipo_anadir', 'evento' => (string) $id_lob, 'socio' => '990033', 'papel' => 'apoyo', 'pestana' => 'equipo' ), 'asde_evento_' . $id_lob );
	$equipo = InscripcionesTable::equipo( $id_lob );
	$ok( 3 === count( $equipo ) && 'director' === $equipo[0]['papel'] && '990035' === $equipo[0]['socio'] && str_contains( $h, 'ya está en este evento' ), 'director/a y dos monitores; nadie está dos veces' );
	$fila = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . InscripcionesTable::table() . ' WHERE evento_id = %d LIMIT 1', $id_lob ), ARRAY_A ); // phpcs:ignore WordPress.DB
	$ok( $fila && ! array_intersect( array_keys( $fila ), array( 'nombre', 'apellidos', 'email', 'telefono', 'dni' ) ) && ! str_contains( implode( '|', $fila ), 'Evento' ), 'del equipo solo se guarda el nº Scouts de España y el papel (ni nombres ni datos de la ficha)' );
	$a_sco = $como( $u_sco );
	$e     = EventosTable::obtener( $id_lob );
	$ok( Servicio::en_gestion( $e, $a_sco ) && Servicio::ve( $e, $a_sco ), 'el scouter de Scouts, al estar en el equipo, ya gestiona el evento' );
	$a_lob = $como( $u_lob );
	$antes_llamadas = $crm->llamadas;
	$check          = Servicio::comprobar_equipo( $e, $equipo, \AsdeBalears\AreaPrivada\Crm\PersonaRepository::make()->obtener_varias( array_column( $equipo, 'socio' ) ) );
	$ok( $check['leido'] && 'director' === $check['personas']['990035']['titulo'] && 'monitor' === $check['personas']['990033']['titulo'] && '' === $check['personas']['990034']['titulo'], 'la formación de tiempo libre se lee de la ficha: Eva directora, Ana monitora, Tom sin título' );
	$ok( 'ok' === $check['personas']['990035']['certificado'] && 'caducado' === $check['personas']['990034']['certificado'], 'el certificado de Tom tendrá más de 12 meses al acabar' );
	$avisos = implode( ' | ', $check['avisos'] );
	$ok( str_contains( $avisos, 'Evento Luz' ) && str_contains( $avisos, 'renovarlo' ) && str_contains( $avisos, 'hacen falta 3 monitores' ) && ! str_contains( $avisos, 'mitad' ), 'avisa del certificado de Tom y de la ratio (24 plazas → 2, + 1 de apoyo por dependencia = 3; hay 2); la mitad de los monitores está titulada' );
	$ok( $crm->llamadas - $antes_llamadas <= 3, 'la comprobación lee el CRM en una sola consulta para todo el equipo (más la de los nombres), no una por persona (' . ( $crm->llamadas - $antes_llamadas ) . ')' );
	$enviar( new EventosView(), array( 'asde_action' => 'evento_equipo_anadir', 'evento' => (string) $id_lob, 'socio' => '990039', 'papel' => 'practicas', 'pestana' => 'equipo' ), 'asde_evento_' . $id_lob );
	$enviar( new EventosView(), array( 'asde_action' => 'evento_equipo_anadir', 'evento' => (string) $id_lob, 'socio' => '990036', 'papel' => 'practicas', 'pestana' => 'equipo' ), 'asde_evento_' . $id_lob );
	$enviar( new EventosView(), array( 'asde_action' => 'evento_equipo_anadir', 'evento' => (string) $id_lob, 'socio' => '990037', 'papel' => 'practicas', 'pestana' => 'equipo' ), 'asde_evento_' . $id_lob );
	$equipo = InscripcionesTable::equipo( $id_lob );
	$check  = Normativa::comprobar_equipo( $e, $equipo, array(), \AsdeBalears\AreaPrivada\Crm\PersonaRepository::make()->campos_varias( array_column( $equipo, 'socio' ), Normativa::CAMPOS_EQUIPO ), Config::de() );
	$ok( str_contains( implode( ' | ', $check['avisos'] ), '3 personas en prácticas y 2 tituladas' ), '3 en prácticas con 2 tituladas: aviso' );
	$ok( str_contains( implode( ' | ', $check['avisos'] ), 'sin fecha' ) && str_contains( implode( ' | ', $check['avisos'] ), 'no consta el certificado' ), 'Pol, con certificado sin fecha, e Iris, sin certificado: avisos' );
	$quitar = array_values( array_filter( $equipo, static fn( $m ) => 'practicas' === $m['papel'] ) );
	foreach ( $quitar as $m ) {
		$enviar( new EventosView(), array( 'asde_action' => 'evento_equipo_quitar', 'evento' => (string) $id_lob, 'fila' => (string) $m['id'], 'pestana' => 'equipo' ), 'asde_evento_' . $id_lob );
	}
	$tom = array_values( array_filter( InscripcionesTable::equipo( $id_lob ), static fn( $m ) => '990034' === $m['socio'] ) )[0] ?? null;
	$enviar( new EventosView(), array( 'asde_action' => 'evento_equipo_papel', 'evento' => (string) $id_lob, 'fila' => (string) ( $tom['id'] ?? 0 ), 'papel' => 'apoyo', 'pestana' => 'equipo' ), 'asde_evento_' . $id_lob );
	$equipo = InscripcionesTable::equipo( $id_lob );
	$ok( 3 === count( $equipo ) && 'apoyo' === ( array_column( $equipo, 'papel', 'socio' )['990034'] ?? '' ), 'se quitan los de prácticas y Tom pasa a apoyo' );
	$ok( in_array( 'Falta el director/a de la actividad.', Normativa::comprobar_equipo( EventosTable::obtener( $id_lob ), array(), array(), array(), Config::de() )['avisos'], true ), 'sin nadie en el equipo: «Falta el director/a»' );

	echo "\n== Parte G · Presupuesto y validación de Tesorería (P-147) ==\n";
	$a_lob = $como( $u_lob );
	foreach ( array( array( 'gasto', 'Autobús', 'total', '', '300' ), array( 'gasto', 'Albergue', 'participante_equipo', '', '12' ), array( 'gasto', 'Comida', 'participante_equipo', '', '15' ), array( 'ingreso', 'Aportación del grupo', 'total', '', '100' ) ) as list( $tipo, $concepto, $reparto, $sec, $imp ) ) {
		$enviar( new EventosView(), array( 'asde_action' => 'evento_partida_anadir', 'evento' => (string) $id_lob, 'tipo' => $tipo, 'concepto' => $concepto, 'reparto' => $reparto, 'seccion' => $sec, 'importe' => $imp, 'pestana' => 'presupuesto' ), 'asde_evento_' . $id_lob );
	}
	$h = $enviar( new EventosView(), array( 'asde_action' => 'evento_partida_anadir', 'evento' => (string) $id_lob, 'tipo' => 'gasto', 'concepto' => 'Escalada', 'reparto' => 'total_seccion', 'seccion' => 'scouts', 'importe' => '50', 'pestana' => 'presupuesto' ), 'asde_evento_' . $id_lob );
	$ok( 4 === count( PartidasTable::previstas( $id_lob ) ) && str_contains( $h, 'no está convocada' ), 'cuatro partidas; una de Scouts (no convocada) se rechaza' );
	$enviar( new EventosView(), array( 'asde_action' => 'evento_prevision', 'evento' => (string) $id_lob, 'equipo_previsto' => '3', 'margen_activo' => '1', 'margen_pct' => '5', 'pestana' => 'presupuesto' ), 'asde_evento_' . $id_lob );
	$e = EventosTable::obtener( $id_lob );
	$c = Presupuesto::del_evento( $e );
	// (300 − 100 + 27 × 3) / 20 + 27 = 41,05 → × 1,05 = 43,10 → 44 €.
	$ok( 3 === $e['equipo_previsto'] && $c['ok'] && 44.0 === $c['precios']['lobatos'], 'con 3 del equipo y 20 Lobatos: (300 − 100 + 27 × 3) / 20 + 27 = 41,05 € → con el 5 %, 44 €' );
	$ok( ! Servicio::puede_validar( $e ) && ! Servicio::puede_publicar( $e, $a_lob ), 'el scouter no valida el presupuesto ni puede publicar un evento con coste sin validar' );
	$h = $enviar( new EventosView(), array( 'asde_action' => 'evento_publicar', 'evento' => (string) $id_lob ), 'asde_evento_' . $id_lob );
	$ok( EventosTable::BORRADOR === EventosTable::obtener( $id_lob )['estado'] && str_contains( $h, 'Tesorería tiene que validar' ), 'si lo intenta, se le explica que falta la validación de Tesorería' );
	// Lo que ya hubiera pendiente en el sitio (otros eventos) no cuenta: se compara con lo de antes.
	$pend_antes = Servicio::pendientes( $como( $u_teso ) )['validar'];
	$a_lob      = $como( $u_lob );
	$enviar( new EventosView(), array( 'asde_action' => 'evento_pedir_validacion', 'evento' => (string) $id_lob, 'pestana' => 'presupuesto' ), 'asde_evento_' . $id_lob );
	$e = EventosTable::obtener( $id_lob );
	$ok( null !== $e['validacion_pedida_en'] && 'por_validar' === EventosTable::estado_visible( $e ), 'lo envía a Tesorería: «pendiente de Tesorería»' );
	$a_teso = $como( $u_teso );
	$ok( $pend_antes + 1 === Servicio::pendientes( $a_teso )['validar'] && 'Eventos (' . ( $pend_antes + 1 ) . ')' === ( Menu::grupos( $a_teso )[ Menu::GESTION ]['items']['eventos'] ?? '' ), 'Tesorería lo tiene pendiente: «Eventos (n)» en el menú, uno más que antes' );
	$ok( 1 === preg_match( '/<strong>' . ( $pend_antes + 1 ) . '<\/strong> presupuestos? de eventos? por validar/', ( new AreaPrivadaView() )->render() ), 'y en el inicio, en «Pendiente de revisar»' );
	$h = $enviar( new EventosView(), array( 'asde_action' => 'evento_validar', 'evento' => (string) $id_lob, 'pestana' => 'presupuesto' ), 'asde_evento_' . $id_lob );
	$e = EventosTable::obtener( $id_lob );
	$ok( null !== $e['validado_en'] && 44.0 === (float) ( $e['presupuesto']['precios']['lobatos'] ?? 0 ) && 'validado' === EventosTable::estado_visible( $e ) && str_contains( $h, 'ya es fijo' ), 'Tesorería lo valida: el precio de 44 € queda guardado' );
	$ok( $pend_antes === Servicio::pendientes( $a_teso )['validar'], 'y deja de estar pendiente' );
	$a_lob = $como( $u_lob );
	$h     = $enviar( new EventosView(), array( 'asde_action' => 'evento_partida_anadir', 'evento' => (string) $id_lob, 'tipo' => 'gasto', 'concepto' => 'Tarde', 'reparto' => 'total', 'seccion' => '', 'importe' => '10', 'pestana' => 'presupuesto' ), 'asde_evento_' . $id_lob );
	$ok( 3 + 1 === count( PartidasTable::previstas( $id_lob ) ) && str_contains( $h, 'no se puede cambiar' ), 'validado, ya no se añaden partidas' );
	$h = $enviar( new EventosView(), array( 'asde_action' => 'evento_guardar', 'evento' => (string) $id_lob ) + $formulario( array( 'lugar' => 'Otro albergue', 'previstas_lobatos' => '30', 'maximas_lobatos' => '24' ) ), 'asde_evento_' . $id_lob );
	$ok( 20 === EventosTable::obtener( $id_lob )['plazas']['lobatos']['previstas'] && str_contains( $h, 'reabra el presupuesto' ), 'ni se cambian los participantes previstos (el precio es fijo)' );
	$h = $enviar( new EventosView(), array( 'asde_action' => 'evento_guardar', 'evento' => (string) $id_lob ) + $formulario( array( 'lugar' => 'Albergue definitivo', 'maximas_lobatos' => '24' ) ), 'asde_evento_' . $id_lob );
	$ok( 'Albergue definitivo' === EventosTable::obtener( $id_lob )['lugar'], 'pero el lugar y lo demás sí' );
	$a_teso = $como( $u_teso );
	$enviar( new EventosView(), array( 'asde_action' => 'evento_reabrir', 'evento' => (string) $id_lob, 'pestana' => 'presupuesto' ), 'asde_evento_' . $id_lob );
	$e = EventosTable::obtener( $id_lob );
	$ok( null === $e['validado_en'] && null === $e['presupuesto'] && null === $e['validacion_pedida_en'] && Servicio::presupuesto_abierto( $e ), 'Tesorería lo reabre (no hay nadie inscrito): vuelve a ser editable' );
	$enviar( new EventosView(), array( 'asde_action' => 'evento_validar', 'evento' => (string) $id_lob, 'pestana' => 'presupuesto' ), 'asde_evento_' . $id_lob );
	$ok( null !== EventosTable::obtener( $id_lob )['validado_en'], 'y lo vuelve a validar (también sin que se lo pidan)' );

	echo "\n== Parte H · Publicar, volver a borrador, cancelar y borrar ==\n";
	$a_lob = $como( $u_lob );
	$ventana = \AsdeBalears\AreaPrivada\Frontend\TemplateLoader::render( 'eventos/resumen', array( 'e' => EventosTable::obtener( $id_lob ), 'cfg' => \AsdeBalears\AreaPrivada\Eventos\Config::de(), 'ambito' => $a_lob, 'lista' => array(), 'url' => '' ) );
	$ok( str_contains( $ventana, 'data-asde-publicar-dialogo' ) && str_contains( $ventana, 'name="avisar[]" value="familias"' ) && str_contains( $ventana, 'name="avisar[]" value="scouters"' ) && str_contains( $ventana, 'name="avisar[]" value="todos"' ) && str_contains( $ventana, '<noscript>' ), 'al publicar se ofrece una ventana con Familias, Scouters y Todos (y el mismo formulario sin JavaScript)' );
	$ok( ! str_contains( $ventana, ' checked' ), 'y no viene nada marcado de antemano' );
	$h     = $enviar( new EventosView(), array( 'asde_action' => 'evento_publicar', 'evento' => (string) $id_lob ), 'asde_evento_' . $id_lob );
	$e     = EventosTable::obtener( $id_lob );
	$ok( EventosTable::PUBLICADO === $e['estado'] && 'publicado' === EventosTable::estado_visible( $e ) && str_contains( $h, 'publicado' ), 'con el presupuesto validado, se publica' );
	$ok( str_contains( $h, 'No se ha enviado ningún aviso por email' ), 'si no se marca a nadie en la ventana de publicar, se dice que no se ha enviado ningún aviso' );
	$ok( 'en_curso' === EventosTable::estado_visible( $e, EventosTable::marca( $ini . ' 12:00:00' ) ) && 'celebrado' === EventosTable::estado_visible( $e, EventosTable::marca( $fin . ' 18:00:00' ) ), 'publicado se ve «en curso» durante el evento y «celebrado» después' );
	$enviar( new EventosView(), array( 'asde_action' => 'evento_despublicar', 'evento' => (string) $id_lob ), 'asde_evento_' . $id_lob );
	$ok( EventosTable::BORRADOR === EventosTable::obtener( $id_lob )['estado'], 'vuelve a borrador (nadie inscrito)' );
	$enviar( new EventosView(), array( 'asde_action' => 'evento_publicar', 'evento' => (string) $id_lob ), 'asde_evento_' . $id_lob );
	$h = $enviar( new EventosView(), array( 'asde_action' => 'evento_cancelar', 'evento' => (string) $id_lob, 'motivo' => '' ), 'asde_evento_' . $id_lob );
	$ok( EventosTable::PUBLICADO === EventosTable::obtener( $id_lob )['estado'] && str_contains( $h, 'motivo' ), 'cancelar sin motivo, no' );
	$h = $enviar( new EventosView(), array( 'asde_action' => 'evento_borrar', 'evento' => (string) $id_lob ), 'asde_evento_' . $id_lob );
	$ok( null !== EventosTable::obtener( $id_lob ) && str_contains( $h, 'Solo se puede borrar un evento en borrador' ), 'publicado, no se borra' );
	$_POST = array( 'asde_action' => 'evento_publicar', 'evento' => (string) $id_lob, '_wpnonce' => 'nonce-falso' );
	$_REQUEST = $_POST;
	$nonce_ok = false;
	add_filter( 'wp_die_handler', $morir = static fn() => static function () {
		throw new RuntimeException( 'asde-die' );
	} );
	try {
		( new EventosView() )->handle_post();
	} catch ( RuntimeException $ex ) {
		$nonce_ok = 'asde-die' === $ex->getMessage();
	} finally {
		remove_filter( 'wp_die_handler', $morir );
		$_POST    = array();
		$_REQUEST = array();
	}
	$ok( $nonce_ok && EventosTable::PUBLICADO === EventosTable::obtener( $id_lob )['estado'], 'sin un nonce válido, la acción se rechaza y nada cambia' );
	$h = $enviar( new EventosView(), array( 'asde_action' => 'evento_cancelar', 'evento' => (string) $id_lob, 'motivo' => 'Alerta meteorológica' ), 'asde_evento_' . $id_lob );
	$e = EventosTable::obtener( $id_lob );
	$ok( EventosTable::CANCELADO === $e['estado'] && 'Alerta meteorológica' === $e['cancelado_motivo'] && ! Servicio::puede_editar( $e, $a_lob ), 'cancelado con su motivo; ya no se edita' );
	$a_coord = $como( $u_coord );
	$enviar( new EventosView(), array( 'asde_action' => 'evento_equipo_anadir', 'evento' => (string) $eg['id'], 'socio' => '990035', 'papel' => 'director' ), 'asde_evento_' . $eg['id'] );
	$enviar( new EventosView(), array( 'asde_action' => 'evento_borrar', 'evento' => (string) $eg['id'] ), 'asde_evento_' . $eg['id'] );
	$ok( null === EventosTable::obtener( $eg['id'] ) && ! InscripcionesTable::equipo( $eg['id'] ), 'un borrador se borra con su equipo' );

	echo "\n== Parte I · Pantallas ==\n";
	$a_lob = $como( $u_lob );
	$enviar( new EventosView(), array( 'asde_action' => 'evento_crear' ) + $formulario( array( 'nombre' => 'Campamento de verano', 'tipo' => 'campamento' ) ), 'asde_evento_nuevo' );
	$ec = $ultimo();
	$enviar( new EventosView(), array( 'asde_action' => 'evento_equipo_anadir', 'evento' => (string) $ec['id'], 'socio' => '990033', 'papel' => 'director' ), 'asde_evento_' . $ec['id'] );
	$h = $pintar( new EventosView(), array( 'estado' => 'todos' ) );
	$ok( str_contains( $h, 'Campamento de verano' ) && str_contains( $h, 'Acampada de prueba' ) && str_contains( $h, 'asde-badge--evento-cancelado' ) && str_contains( $h, 'Nuevo evento' ) && str_contains( $h, 'data-label=' ), 'la agenda: los eventos con su estado, «Nuevo evento» y tablas en tarjetas en el móvil' );
	$h = $pintar( new EventosView(), array( 'evento' => (string) $ec['id'] ) );
	$ok( str_contains( $h, 'Lista de comprobación' ) && str_contains( $h, 'ASDE Baleares' ) && str_contains( $h, 'seguro de la federación' ) && str_contains( $h, 'Falta preparar el presupuesto' ), 'el resumen: comunicación a ASDE Baleares (2 noches, 20 Lobatos), seguro y presupuesto pendiente' );
	$ok( str_contains( $h, 'titulación de director/a' ), 'y avisa de que Ana no tiene título de directora' );
	$h = $pintar(
		new EventosView(),
		array(
			'evento'  => (string) $ec['id'],
			'pestana' => 'equipo',
		)
	);
	$ok( str_contains( $h, 'Equipo dirigente' ) && str_contains( $h, 'Evento Gil' ) && str_contains( $h, 'name="asde_action" value="evento_equipo_anadir"' ) && ! str_contains( $h, 'Evento Vidal' ), 'la pestaña Equipo: Ana como directora y el formulario para añadir (sin el Rover)' );
	$h = $pintar(
		new EventosView(),
		array(
			'evento'  => (string) $ec['id'],
			'pestana' => 'presupuesto',
		)
	);
	$ok( str_contains( $h, 'Añadir una partida' ) && ! str_contains( $h, 'Validar el presupuesto' ), 'la pestaña Presupuesto del scouter: añadir partidas, sin el botón de validar' );
	$h = $pintar(
		new EventosView(),
		array(
			'evento' => (string) $ec['id'],
			'editar' => '1',
		)
	);
	$ok( str_contains( $h, 'name="asde_action" value="evento_guardar"' ) && str_contains( $h, 'value="Campamento de verano"' ), 'el formulario de edición trae los datos del evento' );
	$crm->caido = true;
	$h          = $pintar(
		new EventosView(),
		array(
			'evento'  => (string) $ec['id'],
			'pestana' => 'equipo',
		)
	);
	$crm->caido = false;
	$ok( str_contains( $h, 'No se ha podido leer en el CRM' ) && str_contains( $h, 'No quedan scouters ni voluntariado' ), 'con el CRM caído, la pestaña Equipo avisa y no ofrece a nadie (no se rompe)' );
	$a_teso = $como( $u_teso );
	$h      = $pintar(
		new EventosView(),
		array(
			'evento'  => (string) $ec['id'],
			'pestana' => 'presupuesto',
		)
	);
	$ok( str_contains( $h, 'Validar el presupuesto' ), 'Tesorería ve el botón de validar' );
	$ok( str_contains( $pintar( new EventosConfigView() ), 'Fondo de emergencia' ), 'Tesorería entra en la configuración (margen)' );
	$a_lob = $como( $u_lob );
	$ok( str_contains( $pintar( new EventosConfigView() ), 'No tienes acceso' ) && 'Eventos' === ( Menu::grupos( $a_lob )[ Menu::GESTION ]['items']['eventos'] ?? '' ), 'el scouter no configura; en su menú, «Eventos»' );
	$a_coord = $como( $u_coord );
	$enviar(
		new EventosConfigView(),
		array(
			'asde_action'           => 'eventos_config',
			'margen_activo'         => '1',
			'margen_pct'            => '7,5',
			'nuevo_tipo'            => 'Intercambio',
			'nuevo_coste'           => '1',
			'titulacion_director_texto' => "dtl\n",
			'tipo_activo_salida'    => '1',
			'tipo_coste_acampada'   => '1',
			'tipo_activo_acampada'  => '1',
		),
		'asde_eventos_config'
	);
	$cfg = Config::de();
	$ok( 7.5 === $cfg['margen_pct'] && isset( $cfg['tipos']['intercambio'] ) && $cfg['tipos']['intercambio']['coste'] && ! $cfg['tipos']['campamento']['activo'] && $cfg['tipos']['acampada']['activo'] && array( 'dtl' ) === $cfg['titulaciones']['director'], 'Coordinación configura: margen 7,5 %, tipo nuevo, tipos sin marcar desactivados y titulaciones' );
	$a_fam = $como( $u_fam );
	$ok( ! isset( Menu::grupos( $a_fam )[ Menu::GESTION ] ) && ! str_contains( ( new AreaPrivadaView() )->render(), '<strong>Eventos</strong>' ), 'la familia no tiene «Eventos» (la gestión) ni en el menú ni en el inicio: lo suyo es «Mis eventos» (v0.35)' );

	echo "\n== Parte J · Sin datos personales y sin el CRM real ==\n";
	$cols = array_keys( (array) $wpdb->get_row( 'SELECT * FROM ' . EventosTable::table() . ' LIMIT 1', ARRAY_A ) ); // phpcs:ignore WordPress.DB
	$ok( ! array_intersect( $cols, array( 'dni', 'email', 'telefono', 'salud' ) ), 'la tabla de eventos no tiene columnas de datos personales' );
	$ok( $crm->llamadas > 0 && 0 === $cortadas, "todas las consultas al CRM las ha respondido el simulador ({$crm->llamadas}); ninguna ha salido hacia el CRM real" );
} finally {
	// ---------------------------------------------------------------------------
	// Limpieza: todo lo creado fuera; lo tocado, como estaba.
	remove_filter( 'wp_redirect', $redirigir, 1 );
	remove_filter( 'send_auth_cookies', '__return_false' );
	remove_filter( 'pre_wp_mail', '__return_true' );
	$_POST = array();
	$_GET  = array();
	View::en_modal( false );
	Escritura::usar_para_pruebas( null );
	$crm->desactivar();
	remove_filter( 'pre_http_request', $cortar_crm, 2 );
	wp_using_ext_object_cache( $cache_externa );
	wp_set_current_user( 0 );

	// Los avisos que publicar o cancelar deja programados (v0.35), fuera.
	foreach ( array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . EventosTable::table() . ' WHERE id > %d', $ev_antes ) ) ) as $eid ) { // phpcs:ignore WordPress.DB
		foreach ( array( 'publicado', 'cancelado' ) as $tipo ) {
			wp_clear_scheduled_hook( \AsdeBalears\AreaPrivada\Eventos\Avisos::HOOK, array( $grupo['numero'], $eid, $tipo ) );
			wp_unschedule_hook( \AsdeBalears\AreaPrivada\Eventos\Avisos::HOOK ); // v0.52: el aviso de publicar lleva también los destinatarios.
		}
	}
	$lista = implode( ',', array_map( 'intval', $ids ) ) ?: '0';
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . EventosTable::table() . ' WHERE id > %d', $ev_antes ) ); // phpcs:ignore WordPress.DB
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . InscripcionesTable::table() . ' WHERE id > %d', $ins_antes ) ); // phpcs:ignore WordPress.DB
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . PartidasTable::table() . ' WHERE id > %d', $par_antes ) ); // phpcs:ignore WordPress.DB
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
	// Tabla de números: como estaba.
	$ahora = array_column( (array) $wpdb->get_results( 'SELECT * FROM ' . SociosTable::table(), ARRAY_A ), null, 'num_socio_asde' ); // phpcs:ignore WordPress.DB
	foreach ( array_diff_key( $ahora, $socios_antes ) as $asde => $f ) {
		$wpdb->delete( SociosTable::table(), array( 'num_socio_asde' => (string) $asde ) );
	}
	foreach ( $socios_antes as $asde => $f ) {
		if ( ( $ahora[ $asde ] ?? null ) !== $f ) {
			$wpdb->replace( SociosTable::table(), $f );
		}
	}
	Servicio::olvidar();
	Grupo::forzar( null );
	null === $modulos_antes ? delete_option( 'asde_ap_modulos' ) : update_option( 'asde_ap_modulos', $modulos_antes, false );
	\AsdeBalears\AreaPrivada\Domain\Modulos::olvidar();

	$quedan = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users} WHERE user_login LIKE 'prueba-ev-%'" ); // phpcs:ignore WordPress.DB
	$ok( 0 === $quedan && 0 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . EventosTable::table() . ' WHERE id > ' . (int) $ev_antes ) && 0 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . PartidasTable::table() . ' WHERE id > ' . (int) $par_antes ) && get_option( $opcion_cfg, null ) == $cfg_antes, 'limpieza: sin usuarios, eventos ni partidas de la prueba; configuración de eventos como estaba' ); // phpcs:ignore WordPress.DB
}

echo $fails ? "\n$fails FALLOS\n" : "\nTODO OK (nada tocado en el CRM real; el sitio queda como estaba)\n";
