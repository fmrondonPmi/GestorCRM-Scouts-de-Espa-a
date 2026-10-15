<?php
/**
 * Pruebas de la v0.35 (entorno LOCAL): inscripciones a los eventos y su cobro (doc 43).
 * Ejecutar desde app/public del site Local:
 *
 *   wp eval-file <ruta completa>/wp-content/plugins/asde-area-privada/tests/eventos-inscripciones.php
 *
 * Usa el CRM simulado de tests/lib (fichas inventadas: ninguna petición llega al CRM
 * real), guarda las cachés solo en la memoria de este proceso y no deja salir ningún
 * email (se capturan). Al terminar se borra lo creado (usuarios, eventos, inscripciones,
 * cobros, partidas, familias, emails registrados, avisos programados y registros) y se
 * restauran la configuración de eventos del grupo y la tabla de números.
 */
defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/lib/CrmSimulado.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Auth\Ambito;
use AsdeBalears\AreaPrivada\Crm\Escritura;
use AsdeBalears\AreaPrivada\Domain\Grupo;
use AsdeBalears\AreaPrivada\Domain\Grupos;
use AsdeBalears\AreaPrivada\Eventos\Avisos;
use AsdeBalears\AreaPrivada\Eventos\Cobros;
use AsdeBalears\AreaPrivada\Eventos\Config;
use AsdeBalears\AreaPrivada\Eventos\EventosTable;
use AsdeBalears\AreaPrivada\Eventos\Inscripciones;
use AsdeBalears\AreaPrivada\Eventos\InscripcionesTable;
use AsdeBalears\AreaPrivada\Eventos\MovimientosTable;
use AsdeBalears\AreaPrivada\Eventos\Normativa;
use AsdeBalears\AreaPrivada\Eventos\PartidasTable;
use AsdeBalears\AreaPrivada\Eventos\Servicio;
use AsdeBalears\AreaPrivada\Frontend\Menu;
use AsdeBalears\AreaPrivada\Frontend\Notices;
use AsdeBalears\AreaPrivada\Frontend\Views\AreaPrivadaView;
use AsdeBalears\AreaPrivada\Frontend\Views\EventosConfigView;
use AsdeBalears\AreaPrivada\Frontend\Views\EventosView;
use AsdeBalears\AreaPrivada\Frontend\Views\MisEventosView;
use AsdeBalears\AreaPrivada\Frontend\Views\MisPagosView;
use AsdeBalears\AreaPrivada\Install\Roles;
use AsdeBalears\AreaPrivada\Socios\SociosTable;
use AsdeBalears\AreaPrivada\Tesoreria\FamiliasTable;
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
foreach ( get_users( array( 'search' => 'prueba-ins-*', 'search_columns' => array( 'user_login' ) ) ) as $u ) {
	exit( 'Ya existe el usuario ' . $u->user_login . " (¿una ejecución anterior interrumpida?). Bórralo antes de repetir la prueba.\n" );
}
wp_set_current_user( 0 );
$ip_antes               = $_SERVER['REMOTE_ADDR'] ?? null;
$_SERVER['REMOTE_ADDR'] = '192.0.2.81';

// --- Lo que se restaura al final ---------------------------------------------
$max_id       = static fn( string $t ) => (int) $wpdb->get_var( "SELECT COALESCE(MAX(id), 0) FROM $t" ); // phpcs:ignore WordPress.DB
$log_antes    = $max_id( Logger::table() );
$ev_antes     = $max_id( EventosTable::table() );
$ins_antes    = $max_id( InscripcionesTable::table() );
$par_antes    = $max_id( PartidasTable::table() );
$mov_antes    = $max_id( MovimientosTable::table() );
$fam_antes    = $max_id( FamiliasTable::table() );
$emails_antes = $max_id( RegistroEmails::table() );
$socios_antes = array_column( (array) $wpdb->get_results( 'SELECT * FROM ' . SociosTable::table(), ARRAY_A ), null, 'num_socio_asde' ); // phpcs:ignore WordPress.DB
$opcion_cfg   = 'asde_ap_eventos_' . $grupo['numero'];
$cfg_antes    = get_option( $opcion_cfg, null );

// Cachés solo en memoria; emails capturados (no sale ninguno); CRM real cortado.
$cache_externa = wp_using_ext_object_cache( true );
$capturados    = array();
$capturar      = static function ( $pre, $atts ) use ( &$capturados ) {
	$capturados[] = $atts;
	return true;
};
add_filter( 'pre_wp_mail', $capturar, 5, 2 );
$cortadas   = 0;
$cortar_crm = static function ( $pre, $args, $url ) use ( &$cortadas ) {
	if ( false === $pre && str_contains( (string) $url, '/rest.php' ) ) {
		++$cortadas;
		return new WP_Error( 'prueba', 'CRM real cortado durante la prueba' );
	}
	return $pre;
};
add_filter( 'pre_http_request', $cortar_crm, 2, 3 );
// Avisos push (enganche genérico para la app): se anotan.
$pushes = array();
$push   = static function ( $tipo, $evento, $users ) use ( &$pushes ) {
	$pushes[] = array( $tipo, (int) $evento, array_map( 'intval', (array) $users ) );
};
add_action( Avisos::PUSH, $push, 10, 3 );

$dia = static fn( string $mod ) => wp_date( 'Y-m-d', strtotime( $mod ) );
$ini = $dia( '+45 days' );
$fin = $dia( '+47 days' );

$P   = array( 'Asde_Prueba_Crm', 'persona' );
$crm = new Asde_Prueba_Crm(
	array(
		$P( 51, 'Leo', 'Insc Ruiz', '2016-05-10', 'Lobatos' ),
		$P( 52, 'Nora', 'Insc Ruiz', '2013-02-20', 'Scouts' ),
		$P( 53, 'Max', 'Insc Pons', '2010-03-03', 'Escultas' ),
		$P( 54, 'Rita', 'Insc Vidal', '2005-06-06', 'Rovers' ),
		$P( 55, 'Ana', 'Insc Gil', '1990-01-01', 'Scouters' ),
		$P( 56, 'Teo', 'Insc Mas', '2016-07-07', 'Lobatos' ),
		$P( 57, 'Lía', 'Insc Sol', '2016-08-08', 'Lobatos' ),
		$P( 58, 'Pau', 'Insc Roca', '2012-09-09', 'Scouts' ),
		$P( 59, 'Pol', 'Insc Teso', '1985-04-04', 'Scouters' ),
		$P( 60, 'Eva', 'Insc Coll', '1980-08-08', 'Scouters' ),
		$P( 61, 'Bru', 'Insc Fort', '2019-01-15', 'Castores' ),
		$P( 62, 'Ivo', 'Insc Pons', '2011-11-11', 'Escultas' ),
		$P( 63, 'Gil', 'Insc Esc', '1992-02-02', 'Scouters' ),
	),
	$grupo['crm_id']
);
Grupo::forzar( $grupo );
// v0.35: el módulo «Eventos» nace apagado; la prueba lo enciende para el grupo y lo deja como estaba.
$modulos_antes = get_option( 'asde_ap_modulos', null );
\AsdeBalears\AreaPrivada\Domain\Modulos::guardar( (string) $grupo['numero'], array_keys( \AsdeBalears\AreaPrivada\Domain\Modulos::LISTA ) );
$crm->activar();
Escritura::usar_para_pruebas( $crm );

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
		foreach ( (array) $v as $valor ) {
			add_user_meta( $id, $k, $valor );
		}
	}
	$ids[ $login ] = $id;
	return $id;
};
$u_lob   = $crear( 'prueba-ins-lob', array( Roles::SCOUTER ), array( Usuarios::META_SOCIO => '990055', Usuarios::META_SECCION => array( 'lobatos', 'scouts' ) ) );
$u_teso  = $crear( 'prueba-ins-teso', array( Roles::SCOUTER, Roles::TESORERIA ), array( Usuarios::META_SOCIO => '990059', Usuarios::META_SECCION => 'escultas' ) );
$u_coord = $crear( 'prueba-ins-coord', array( Roles::SCOUTER, Roles::COORDINACION ), array( Usuarios::META_SOCIO => '990060', Usuarios::META_SECCION => 'rovers' ) );
$u_esc   = $crear( 'prueba-ins-esc', array( Roles::SCOUTER ), array( Usuarios::META_SOCIO => '990063', Usuarios::META_SECCION => 'escultas' ) );
$u_fam1  = $crear( 'prueba-ins-fam1', array( Roles::TUTOR ), array( Usuarios::META_REPRESENTADO => array( '990051', '990052' ) ) );
$u_fam2  = $crear( 'prueba-ins-fam2', array( Roles::TUTOR ), array( Usuarios::META_REPRESENTADO => array( '990053', '990062' ) ) );
$u_fam3  = $crear( 'prueba-ins-fam3', array( Roles::TUTOR ), array( Usuarios::META_REPRESENTADO => '990056' ) );
$u_fam4  = $crear( 'prueba-ins-fam4', array( Roles::TUTOR ), array( Usuarios::META_REPRESENTADO => '990057' ) );
$u_max   = $crear( 'prueba-ins-max', array( Roles::PARTICIPANTE ), array( Usuarios::META_SOCIO => '990053' ) );
$u_rita  = $crear( 'prueba-ins-rita', array( Roles::PARTICIPANTE ), array( Usuarios::META_SOCIO => '990054' ) );

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
$fila   = static fn( int $evento, string $num ) => InscripcionesTable::de_socio( $evento, $num );
$asunto = static function ( string $texto ) use ( &$capturados ): int {
	return count( array_filter( $capturados, static fn( $m ) => str_contains( (string) $m['subject'], $texto ) ) );
};
$a_quien = static function ( string $texto ) use ( &$capturados ): array {
	$out = array();
	foreach ( $capturados as $m ) {
		if ( str_contains( (string) $m['subject'], $texto ) ) {
			$out[] = (string) ( is_array( $m['to'] ) ? $m['to'][0] : $m['to'] );
		}
	}
	sort( $out );
	return $out;
};
$correo = static fn( string $login ) => $login . '@example.invalid';
// La tarjeta de un evento en «Mis eventos» (el sitio puede tener otros eventos publicados: datos reales o de demostración).
$tarjeta = static function ( string $html, int $evento ): string {
	$ini = strpos( $html, 'id="evento-' . $evento . '"' );
	if ( false === $ini ) {
		return '';
	}
	$fin = strpos( $html, '</section>', $ini );
	return substr( $html, $ini, false === $fin ? null : $fin - $ini );
};
$ev     = static fn( int $id ) => EventosTable::obtener( $id );

try {
	Config::guardar( Config::defaults(), $grupo['numero'] );

	echo "== Parte A · Configuración: autorización, pago y recordatorio ==\n";
	$cfg = Config::de();
	$ok( '' === $cfg['autorizacion_texto'] && 'v1' === Config::version_autorizacion( $cfg ) && str_contains( Config::autorizacion( $cfg ), 'autorizo su participación' ) && 3 === $cfg['recordatorio_dias'], 'nace con el texto de autorización del plugin (versión v1) y el recordatorio 3 días antes' );
	$como( $u_coord );
	$h = $enviar(
		new EventosConfigView(),
		array(
			'asde_action'        => 'eventos_config',
			'margen_activo'      => '1',
			'margen_pct'         => '5',
			'tipo_activo_salida' => '1',
			'tipo_activo_acampada' => '1',
			'tipo_coste_acampada'  => '1',
			'autorizacion_texto' => 'Autorizo la participación en la actividad. Texto propio del grupo.',
			'texto_pago'         => 'Cuenta del grupo ES00 0000 0000 0000 0000 0000 o Bizum al 600 000 000.',
			'recordatorio_dias'  => '3',
		),
		'asde_eventos_config'
	);
	$cfg = Config::de();
	$ok( 'v2-propio' === Config::version_autorizacion( $cfg ) && str_contains( Config::autorizacion( $cfg ), 'Texto propio' ) && str_contains( $cfg['texto_pago'], 'Bizum' ), 'Coordinación escribe su texto de autorización: la versión sube a v2 (propio) y se guarda cómo pagar' );
	$enviar(
		new EventosConfigView(),
		array(
			'asde_action'          => 'eventos_config',
			'margen_activo'        => '1',
			'margen_pct'           => '5',
			'tipo_activo_salida'   => '1',
			'tipo_activo_acampada' => '1',
			'tipo_coste_acampada'  => '1',
			'autorizacion_texto'   => Config::AUTORIZACION,
			'texto_pago'           => $cfg['texto_pago'],
			'recordatorio_dias'    => '3',
		),
		'asde_eventos_config'
	);
	$cfg = Config::de();
	$ok( '' === $cfg['autorizacion_texto'] && 'v3' === Config::version_autorizacion( $cfg ), 'volver al texto del plugin lo guarda vacío (se sigue traduciendo) y la versión vuelve a subir (v3)' );
	$version = Config::version_autorizacion( $cfg );

	echo "\n== Parte B · Publicar: email a las familias convocadas ==\n";
	// Evento A: acampada de Lobatos (2 plazas) y Scouts, con coste. Lo crea la scouter de las dos secciones.
	$a_lob = $como( $u_lob );
	$id_a  = Servicio::crear(
		$a_lob,
		array(
			'tipo'              => 'acampada',
			'nombre'            => 'Acampada de inscripciones',
			'ambito'            => 'secciones',
			'secciones'         => array( 'lobatos', 'scouts' ),
			'inicio_fecha'      => $ini,
			'inicio_hora'       => '10:00',
			'fin_fecha'         => $fin,
			'fin_hora'          => '17:00',
			'lugar'             => 'Albergue de prueba',
			'salida'            => 'Local del grupo, 9:30',
			'previstas_lobatos' => '2',
			'maximas_lobatos'   => '2',
			'previstas_scouts'  => '5',
			'con_coste'         => '1',
			'modalidad'         => 'abierta',
		)
	);
	if ( is_wp_error( $id_a ) ) {
		throw new RuntimeException( 'No se ha podido crear el evento: ' . $id_a->get_error_message() );
	}
	$ok( 'abierta' === $ev( $id_a )['modalidad'] && null === $ev( $id_a )['inscripcion_hasta'], 'el evento guarda la modalidad (abierta) y, sin fechas, el plazo es hasta que empieza' );
	$e = $ev( $id_a );
	$r = Servicio::guardar( $e, $a_lob, array( 'tipo' => 'acampada', 'nombre' => $e['nombre'], 'ambito' => 'secciones', 'secciones' => array( 'lobatos', 'scouts' ), 'inicio_fecha' => $ini, 'inicio_hora' => '10:00', 'fin_fecha' => $fin, 'fin_hora' => '17:00', 'previstas_lobatos' => '2', 'maximas_lobatos' => '2', 'previstas_scouts' => '5', 'con_coste' => '1', 'inscripcion_hasta' => $dia( '+50 days' ) ) );
	$ok( is_wp_error( $r ) && str_contains( $r->get_error_message(), 'antes de que empiece' ), 'el plazo de inscripción no puede acabar después de que empiece el evento' );
	Servicio::anadir_partida( $e, $a_lob, 'gasto', 'Autobús', 'total', '', 140.0 );
	Servicio::anadir_partida( $e, $a_lob, 'gasto', 'Escalada', 'total_seccion', 'scouts', 75.0 );
	Servicio::pedir_validacion( $ev( $id_a ), $a_lob );
	$como( $u_teso );
	$calc = Servicio::validar( $ev( $id_a ) );
	$ok( is_array( $calc ) && 21.0 === (float) $calc['precios']['lobatos'] && 37.0 === (float) $calc['precios']['scouts'], 'precio validado: Lobatos 21 € y Scouts 37 € (140 € entre 7, más 75 € de Scouts entre 5, +5 %)' );
	$a_lob = $como( $u_lob );
	Servicio::publicar( $ev( $id_a ), $a_lob );
	$ok( false !== wp_next_scheduled( Avisos::HOOK, array( $grupo['numero'], $id_a, 'publicado' ) ), 'al publicar, el email a las familias queda programado en segundo plano (WP-Cron)' );
	$capturados = array();
	$res        = Avisos::masivo( $grupo['numero'], $id_a, 'publicado' );
	$esperados  = array( $correo( 'prueba-ins-fam1' ), $correo( 'prueba-ins-fam3' ), $correo( 'prueba-ins-fam4' ) );
	sort( $esperados );
	$ok( 3 === $res['enviados'] && $esperados === $a_quien( 'Nuevo evento' ), 'sale un email por cuenta convocada: las familias de Lobatos y Scouts (una sola para los dos hermanos); a quien no tiene cuenta, nada' );
	$ok( null !== $ev( $id_a )['avisado_en'] && 0 === Avisos::masivo( $grupo['numero'], $id_a, 'publicado' )['enviados'], 'no se repite (queda anotado que ya se avisó)' );
	$ok( (bool) array_filter( $pushes, static fn( $p ) => 'publicado' === $p[0] && $id_a === $p[1] && in_array( $u_fam1, $p[2], true ) ), 'y dispara el enganche de los avisos push (asde_ap_evento_aviso) con las cuentas avisadas' );
	$html = (string) ( $capturados[0]['message'] ?? '' );
	$ok( str_contains( $html, 'Acampada de inscripciones' ) && str_contains( $html, 'Mis eventos' ), 'el email lleva el evento y el enlace a «Mis eventos»' );

	echo "\n== Parte C · Mis eventos de la familia ==\n";
	$a_f1 = $como( $u_fam1 );
	$g    = Menu::grupos( $a_f1 );
	$ok( isset( $g[ Menu::PERSONAL ]['items']['mis_eventos'] ), 'la familia tiene «Mis eventos» en su menú' );
	$ok( ! isset( Menu::grupos( $como( $u_esc ) )[ Menu::PERSONAL ]['items']['mis_eventos'] ), 'un scouter sin eventos para él no lo tiene (no es participante)' );
	$a_f1 = $como( $u_fam1 );
	$h    = $pintar( new MisEventosView() );
	$ok( str_contains( $h, 'Acampada de inscripciones' ) && str_contains( $h, 'Inscribir a Leo' ) && str_contains( $h, 'Inscribir a Nora' ) && str_contains( $h, '21,00 €' ) && str_contains( $h, '37,00 €' ), 've el evento, a sus dos menores con su botón de inscribir y el precio de cada sección' );
	$ok( str_contains( $h, 'autorizo su participación' ) && str_contains( $h, 'name="acepto"' ), 'el formulario lleva el texto de la autorización y la casilla para firmarla' );

	echo "\n== Parte D · La familia inscribe (y firma) ==\n";
	$base = array(
		'asde_action'       => 'inscripcion_crear',
		'evento'            => (string) $id_a,
		'socio'             => '990051',
		'transporte_ida'    => 'grupo',
		'transporte_vuelta' => 'propio',
		'recoge'            => 'Abuela Rosa, DNI 00000000T',
		'observaciones'     => 'Le cuesta dormir fuera de casa',
	);
	$h = $enviar( new MisEventosView(), $base + array( 'acepto' => '1' ), 'asde_mis_eventos_' . $id_a );
	$ok( null === $fila( $id_a, '990051' ) && str_contains( $h, 'ficha de salud' ), 'sin confirmar que la ficha de salud está al día, no se inscribe' );
	$h = $enviar( new MisEventosView(), $base + array( 'salud' => '1' ), 'asde_mis_eventos_' . $id_a );
	$ok( null === $fila( $id_a, '990051' ) && str_contains( $h, 'autorización' ), 'sin aceptar la autorización, tampoco' );
	$h   = $enviar( new MisEventosView(), $base + array( 'salud' => '1', 'acepto' => '1' ), 'asde_mis_eventos_' . $id_a );
	$leo = $fila( $id_a, '990051' );
	$ok( $leo && InscripcionesTable::PENDIENTE === $leo['estado'] && 'web' === $leo['autorizacion'] && $version === $leo['autorizacion_version'] && $u_fam1 === $leo['autorizacion_por'] && 64 === strlen( $leo['autorizacion_huella'] ) && 'familia' === $leo['como'], 'inscrito: pendiente (falta pagar), con la autorización firmada en la web por la familia (versión del texto y huella de la IP, no la IP)' );
	$ok( 21.0 === $leo['precio'] && 'lobatos' === $leo['seccion'] && ! $leo['adulto'] && null !== $leo['salud_revisada'] && 'grupo' === $leo['transporte_ida'] && 'propio' === $leo['transporte_vuelta'], 'con su sección, su precio fijo (21 €), menor, la ficha de salud revisada y el transporte' );
	$ok( ! str_contains( (string) $leo['datos_enc'], 'Rosa' ) && 'Abuela Rosa, DNI 00000000T' === Inscripciones::datos( $leo )['recoge'] && 'Le cuesta dormir fuera de casa' === Inscripciones::datos( $leo )['observaciones'], 'quién recoge y las observaciones se guardan cifradas y se leen descifradas' );
	$log = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT detalles FROM ' . Logger::table() . " WHERE id > %d AND accion = 'inscripcion_creada' ORDER BY id DESC LIMIT 1", $log_antes ) ); // phpcs:ignore WordPress.DB
	$ok( '' !== $log && ! str_contains( $log, 'Rosa' ) && ! str_contains( $log, 'dormir' ) && str_contains( $log, 'datos_enc' ), 'la auditoría anota la inscripción con nombres de campos, sin sus valores' );
	$h = $enviar( new MisEventosView(), $base + array( 'salud' => '1', 'acepto' => '1' ), 'asde_mis_eventos_' . $id_a );
	$ok( str_contains( $h, 'Ya está inscrito' ), 'no se puede inscribir dos veces' );
	$h = $enviar( new MisEventosView(), array_merge( $base, array( 'socio' => '990056', 'salud' => '1', 'acepto' => '1' ) ), 'asde_mis_eventos_' . $id_a );
	$ok( null === $fila( $id_a, '990056' ) && str_contains( $h, 'No puedes inscribir a esa persona' ), 'una familia no puede inscribir al hijo de otra' );
	$h = $pintar( new MisEventosView() );
	$ok( str_contains( $h, 'Para confirmar la inscripción falta' ) && str_contains( $h, 'Bizum al 600' ), 'en «Mis eventos» ve lo que falta (pagar, con la referencia) y cómo pagar' );

	echo "\n== Parte E · Plazas y lista de espera ==\n";
	$como( $u_fam3 );
	$enviar( new MisEventosView(), array_merge( $base, array( 'socio' => '990056', 'salud' => '1', 'acepto' => '1', 'recoge' => '', 'observaciones' => '' ) ), 'asde_mis_eventos_' . $id_a );
	$teo = $fila( $id_a, '990056' );
	$ok( $teo && InscripcionesTable::PENDIENTE === $teo['estado'] && null === $teo['datos_enc'], 'Teo ocupa la segunda plaza de Lobatos (sin datos que cifrar, no se guarda nada)' );
	$a_f4 = $como( $u_fam4 );
	$h    = $enviar( new MisEventosView(), array_merge( $base, array( 'socio' => '990057', 'salud' => '1', 'acepto' => '1', 'recoge' => '', 'observaciones' => '' ) ), 'asde_mis_eventos_' . $id_a );
	$lia  = $fila( $id_a, '990057' );
	$ok( $lia && InscripcionesTable::ESPERA === $lia['estado'] && str_contains( $h, 'lista de espera' ) && 1 === Inscripciones::puesto( $lia, InscripcionesTable::participantes( $id_a ) ), 'Lía ya no tiene plaza (2 de 2): entra en la lista de espera, puesto 1' );
	$ok( str_contains( $pintar( new MisEventosView() ), 'lista de espera (puesto 1)' ), 'y su familia lo ve en «Mis eventos»' );
	$a_f3       = $como( $u_fam3 );
	$capturados = array();
	$h          = $enviar( new MisEventosView(), array( 'asde_action' => 'inscripcion_baja', 'evento' => (string) $id_a, 'fila' => (string) $teo['id'] ), 'asde_mis_eventos_' . $id_a );
	$lia        = $fila( $id_a, '990057' );
	$ok( InscripcionesTable::BAJA === $fila( $id_a, '990056' )['estado'] && Inscripciones::con_oferta( $lia ) && 'plaza' === Inscripciones::estado( $lia ), 'la familia de Teo lo da de baja: la plaza se ofrece a Lía, la primera de la lista' );
	$ok( array( $correo( 'prueba-ins-fam4' ) ) === $a_quien( 'Se ha liberado una plaza' ), 'y a su familia le llega el email para confirmarla' );
	$a_f4 = $como( $u_fam4 );
	$ok( 1 === Inscripciones::pendientes( $a_f4 )['plaza'] && str_contains( (string) Menu::grupos( $a_f4 )[ Menu::PERSONAL ]['items']['mis_eventos'], '(1)' ), 'le sale como pendiente en «Mis eventos (1)»' );
	$ok( str_contains( $pintar( new AreaPrivadaView() ), 'plaza que se ha liberado por confirmar' ), 'y en el inicio' );
	$enviar( new MisEventosView(), array( 'asde_action' => 'inscripcion_plaza', 'evento' => (string) $id_a, 'fila' => (string) $lia['id'] ), 'asde_mis_eventos_' . $id_a );
	$lia = $fila( $id_a, '990057' );
	$ok( InscripcionesTable::PENDIENTE === $lia['estado'] && null === $lia['oferta_hasta'] && 'web' === $lia['autorizacion'], 'Lía confirma la plaza: pendiente de pago, con la autorización que firmó al apuntarse' );
	$como( $u_fam3 );
	$enviar( new MisEventosView(), array_merge( $base, array( 'socio' => '990056', 'salud' => '1', 'acepto' => '1', 'recoge' => '', 'observaciones' => '' ) ), 'asde_mis_eventos_' . $id_a );
	$teo = $fila( $id_a, '990056' );
	$ok( InscripcionesTable::ESPERA === $teo['estado'] && 'web' === $teo['autorizacion'], 'Teo se vuelve a apuntar (se reutiliza su fila): lista de espera' );
	// Más plazas en Lobatos: se ofrecen solas a la lista de espera.
	$a_lob = $como( $u_lob );
	$e     = $ev( $id_a );
	$r     = Servicio::guardar( $e, $a_lob, array( 'tipo' => 'acampada', 'nombre' => $e['nombre'], 'ambito' => 'secciones', 'secciones' => array( 'lobatos', 'scouts' ), 'inicio_fecha' => $ini, 'inicio_hora' => '10:00', 'fin_fecha' => $fin, 'fin_hora' => '17:00', 'lugar' => $e['lugar'], 'salida' => $e['salida'], 'previstas_lobatos' => '2', 'maximas_lobatos' => '3', 'previstas_scouts' => '5', 'con_coste' => '1', 'modalidad' => 'abierta' ) );
	$teo   = $fila( $id_a, '990056' );
	$ok( true === $r && Inscripciones::con_oferta( $teo ), 'si el equipo sube las plazas, la nueva se ofrece a Teo' );
	Inscripciones::revisar( $ev( $id_a ), time() + 4 * DAY_IN_SECONDS );
	$teo = $fila( $id_a, '990056' );
	$ok( InscripcionesTable::BAJA === $teo['estado'] && Inscripciones::MOTIVO_CADUCADA === $teo['baja_motivo'] && 0 === $teo['baja_por'], 'si no la confirma en 3 días, la pierde (baja automática, con su motivo)' );

	echo "\n== Parte F · Preinscripción de 14 a 17, adultos y convocatoria ==\n";
	// Evento B: salida de Escultas y Rovers, sin coste, en convocatoria. La crea Coordinación.
	$a_co = $como( $u_coord );
	$id_b = Servicio::crear( $a_co, array( 'tipo' => 'salida', 'nombre' => 'Salida en convocatoria', 'ambito' => 'secciones', 'secciones' => array( 'escultas', 'rovers' ), 'inicio_fecha' => $ini, 'inicio_hora' => '09:00', 'fin_fecha' => $fin, 'fin_hora' => '18:00', 'modalidad' => 'convocatoria' ) );
	Servicio::publicar( $ev( $id_b ), $a_co );
	$ok( 'convocatoria' === $ev( $id_b )['modalidad'] && EventosTable::PUBLICADO === $ev( $id_b )['estado'], 'evento en convocatoria publicado (sin coste: no pasa por Tesorería)' );
	$a_f2 = $como( $u_fam2 );
	$ok( 2 === Inscripciones::pendientes( $a_f2 )['responder'], 'la familia de Max e Ivo tiene dos convocatorias por responder' );
	$a_max = $como( $u_max );
	$h     = $pintar( new MisEventosView() );
	$ok( str_contains( $h, 'Preinscribirme' ) && str_contains( $h, 'No voy' ) && ! str_contains( $h, 'name="acepto"' ), 'Max (16 años) puede preinscribirse o decir que no va; él no firma la autorización' );
	$enviar( new MisEventosView(), array( 'asde_action' => 'inscripcion_crear', 'evento' => (string) $id_b, 'socio' => '990053', 'transporte_ida' => 'grupo', 'transporte_vuelta' => 'grupo', 'salud' => '1' ), 'asde_mis_eventos_' . $id_b );
	$max = $fila( $id_b, '990053' );
	$ok( $max && InscripcionesTable::PENDIENTE === $max['estado'] && '' === $max['autorizacion'] && 'propia' === $max['como'], 'su preinscripción queda pendiente de la autorización de su familia' );
	$a_f2 = $como( $u_fam2 );
	$ok( 1 === Inscripciones::pendientes( $a_f2 )['firmar'] && 1 === Inscripciones::pendientes( $a_f2 )['responder'], 'la familia ve una autorización por firmar (y sigue la convocatoria de Ivo)' );
	$ok( str_contains( $pintar( new AreaPrivadaView() ), 'autorización por firmar' ), 'también en el inicio' );
	$h = $enviar( new MisEventosView(), array( 'asde_action' => 'inscripcion_firmar', 'evento' => (string) $id_b, 'fila' => (string) $max['id'] ), 'asde_mis_eventos_' . $id_b );
	$ok( '' === $fila( $id_b, '990053' )['autorizacion'], 'firmar sin marcar «la firmo», no' );
	$capturados = array();
	$enviar( new MisEventosView(), array( 'asde_action' => 'inscripcion_firmar', 'evento' => (string) $id_b, 'fila' => (string) $max['id'], 'acepto' => '1' ), 'asde_mis_eventos_' . $id_b );
	$max  = $fila( $id_b, '990053' );
	$dest = array( $correo( 'prueba-ins-fam2' ), $correo( 'prueba-ins-max' ) );
	$ok( 'web' === $max['autorizacion'] && $u_fam2 === $max['autorizacion_por'] && InscripcionesTable::CONFIRMADA === $max['estado'] && null !== $max['confirmada_en'], 'la familia firma: sin coste, la inscripción queda confirmada' );
	$ok( $dest === $a_quien( 'Inscripción confirmada' ), 'el email de confirmación llega a la familia y a Max (tiene cuenta propia)' );
	$enviar( new MisEventosView(), array( 'asde_action' => 'inscripcion_no', 'evento' => (string) $id_b, 'socio' => '990062' ), 'asde_mis_eventos_' . $id_b );
	$ok( InscripcionesTable::NO_PARTICIPA === $fila( $id_b, '990062' )['estado'] && 0 === Inscripciones::pendientes( $a_f2 )['responder'], 'responde que Ivo no va: ya no queda nada por responder' );
	$como( $u_rita );
	$enviar( new MisEventosView(), array( 'asde_action' => 'inscripcion_crear', 'evento' => (string) $id_b, 'socio' => '990054', 'salud' => '1' ), 'asde_mis_eventos_' . $id_b );
	$rita = $fila( $id_b, '990054' );
	$ok( $rita && 'no' === $rita['autorizacion'] && $rita['adulto'] && InscripcionesTable::CONFIRMADA === $rita['estado'], 'Rita (mayor de edad) se inscribe sola, sin autorización: confirmada' );
	$como( $u_max );
	$h = $enviar( new MisEventosView(), array( 'asde_action' => 'inscripcion_crear', 'evento' => (string) $id_a, 'socio' => '990053', 'salud' => '1' ), 'asde_mis_eventos_' . $id_a );
	$ok( null === $fila( $id_a, '990053' ) && str_contains( $h, 'no es para su sección' ), 'Max no se puede inscribir en un evento de otras secciones' );

	echo "\n== Parte G · El equipo inscribe y recoge las autorizaciones en papel ==\n";
	// Lo que ya hubiera por recoger en el sitio (en el Local puede haber eventos reales) no cuenta.
	$papel_antes = Inscripciones::por_recoger( $como( $u_lob ) );
	$h    = $enviar( new EventosView(), array( 'asde_action' => 'evento_inscribir', 'evento' => (string) $id_a, 'pestana' => 'inscripciones', 'socio' => '990052', 'transporte_ida' => 'grupo', 'transporte_vuelta' => 'grupo' ), 'asde_evento_' . $id_a );
	$nora = $fila( $id_a, '990052' );
	$ok( $nora && 'gestor' === $nora['como'] && '' === $nora['autorizacion'] && 37.0 === $nora['precio'] && InscripcionesTable::PENDIENTE === $nora['estado'], 'la scouter inscribe a Nora en nombre de su familia: pendiente de la autorización en papel (y de pagar 37 €)' );
	$a_lob = $como( $u_lob );
	$ok( $papel_antes + 1 === Inscripciones::por_recoger( $a_lob ) && preg_match( '/autorizaci(ón de evento|ones de eventos) por recoger en papel/u', $pintar( new AreaPrivadaView() ) ), 'le sale en el inicio: una autorización más por recoger en papel' );
	$h = $enviar( new EventosView(), array( 'asde_action' => 'evento_inscribir', 'evento' => (string) $id_a, 'pestana' => 'inscripciones', 'otro' => '990061' ), 'asde_evento_' . $id_a );
	$ok( null === $fila( $id_a, '990061' ) && str_contains( $h, 'motivo de la excepción' ), 'a alguien de otra sección (Bru, de Castores), solo con el motivo de la excepción' );
	$h   = $enviar( new EventosView(), array( 'asde_action' => 'evento_inscribir', 'evento' => (string) $id_a, 'pestana' => 'inscripciones', 'otro' => '990061', 'motivo' => 'Va con su hermana' ), 'asde_evento_' . $id_a );
	$bru = $fila( $id_a, '990061' );
	$ok( $bru && 'Va con su hermana' === $bru['motivo'] && 37.0 === $bru['precio'] && 'lobatos' === $bru['seccion'], 'con motivo, sí: ocupa plaza en la primera sección convocada y paga el precio más alto (Tesorería lo puede ajustar)' );
	$enviar( new EventosView(), array( 'asde_action' => 'evento_papel', 'evento' => (string) $id_a, 'pestana' => 'inscripciones', 'fila' => (string) $nora['id'] ), 'asde_evento_' . $id_a );
	$nora = $fila( $id_a, '990052' );
	$ok( 'papel' === $nora['autorizacion'] && $u_lob === $nora['autorizacion_por'] && null !== $nora['autorizacion_en'] && InscripcionesTable::PENDIENTE === $nora['estado'], 'marca la autorización de Nora como recibida en papel (quién y cuándo); sigue pendiente de pago' );
	$como( $u_esc );
	$h = $enviar( new EventosView(), array( 'asde_action' => 'evento_inscribir', 'evento' => (string) $id_a, 'pestana' => 'inscripciones', 'socio' => '990058' ), 'asde_evento_' . $id_a );
	$ok( null === $fila( $id_a, '990058' ), 'un scouter de otra sección no inscribe en este evento' );
	$como( $u_teso );
	$enviar( new EventosView(), array( 'asde_action' => 'evento_papel', 'evento' => (string) $id_a, 'pestana' => 'inscripciones', 'fila' => (string) $bru['id'] ), 'asde_evento_' . $id_a );
	$ok( '' === $fila( $id_a, '990061' )['autorizacion'], 'Tesorería (sin gestionar el evento) no marca autorizaciones' );

	echo "\n== Parte H · Cobros (Tesorería) ==\n";
	$como( $u_lob );
	$h = $enviar( new EventosView(), array( 'asde_action' => 'evento_pago', 'evento' => (string) $id_a, 'pestana' => 'inscripciones', 'fila' => (string) $leo['id'], 'importe' => '21', 'forma' => 'efectivo', 'fecha' => current_time( 'Y-m-d' ) ), 'asde_evento_' . $id_a );
	$ok( ! MovimientosTable::de_evento( $id_a ) && str_contains( $h, 'Tesorería' ), 'una scouter no registra pagos: lo hace Tesorería' );
	$como( $u_teso );
	$h = $enviar( new EventosView(), array( 'asde_action' => 'evento_ajuste', 'evento' => (string) $id_a, 'pestana' => 'inscripciones', 'fila' => (string) $nora['id'], 'sentido' => 'rebaja', 'importe' => '10' ), 'asde_evento_' . $id_a );
	$ok( ! MovimientosTable::de_evento( $id_a ) && str_contains( $h, 'motivo' ), 'una beca sin motivo, no' );
	$h = $enviar( new EventosView(), array( 'asde_action' => 'evento_ajuste', 'evento' => (string) $id_a, 'pestana' => 'inscripciones', 'fila' => (string) $nora['id'], 'sentido' => 'rebaja', 'importe' => '100', 'motivo' => 'Beca total' ), 'asde_evento_' . $id_a );
	$ok( ! MovimientosTable::de_evento( $id_a ) && str_contains( $h, 'mayor que el precio' ), 'ni una rebaja mayor que el precio' );
	$enviar( new EventosView(), array( 'asde_action' => 'evento_ajuste', 'evento' => (string) $id_a, 'pestana' => 'inscripciones', 'fila' => (string) $nora['id'], 'sentido' => 'rebaja', 'importe' => '10', 'motivo' => 'Beca del grupo' ), 'asde_evento_' . $id_a );
	$c_nora = Cobros::de( $nora, MovimientosTable::de_evento( $id_a )[ $nora['id'] ] ?? array() );
	$ok( 27.0 === $c_nora['total'] && 37.0 === $c_nora['precio'] && -10.0 === $c_nora['ajustes'], 'beca de 10 € a Nora con su motivo: paga 27 € (el precio de los demás no cambia)' );
	$f_id = FamiliasTable::crear( array( '990051', '990052' ) );
	$fam  = FamiliasTable::obtener( (int) $f_id );
	$ok( Cobros::referencia( $ev( $id_a ), $leo ) === EventosTable::numero( $ev( $id_a ) ) . ' ' . $fam['numero'] && str_starts_with( Cobros::referencia( $ev( $id_a ), $bru ), EventosTable::numero( $ev( $id_a ) ) . ' I' ), 'referencia de pago: nº de evento y nº de familia (o, sin familia, el de la inscripción)' );
	$familias = Cobros::familias( $ev( $id_a ) );
	$ok( isset( $familias[ $f_id ] ) && 48.0 === $familias[ $f_id ]['pendiente'] && 2 === count( $familias[ $f_id ]['filas'] ), 'la familia de Leo y Nora debe 48 € en este evento (21 + 27)' );
	$h = $enviar( new EventosView(), array( 'asde_action' => 'evento_pago_familia', 'evento' => (string) $id_a, 'pestana' => 'inscripciones', 'familia' => (string) $f_id, 'importe' => '60', 'forma' => 'bizum', 'fecha' => current_time( 'Y-m-d' ) ), 'asde_evento_' . $id_a );
	$ok( empty( MovimientosTable::de_evento( $id_a )[ $leo['id'] ] ) && str_contains( $h, 'más de lo que queda por pagar' ), 'un pago mayor que lo pendiente, no' );
	$capturados = array();
	$enviar( new EventosView(), array( 'asde_action' => 'evento_pago_familia', 'evento' => (string) $id_a, 'pestana' => 'inscripciones', 'familia' => (string) $f_id, 'importe' => '30', 'forma' => 'bizum', 'fecha' => current_time( 'Y-m-d' ), 'referencia' => 'Bizum familia' ), 'asde_evento_' . $id_a );
	$movs = MovimientosTable::de_evento( $id_a );
	$leo  = $fila( $id_a, '990051' );
	$ok( 21.0 === ( $movs[ $leo['id'] ][0]['importe'] ?? 0 ) && 9.0 === ( $movs[ $nora['id'] ][1]['importe'] ?? 0 ) && $movs[ $leo['id'] ][0]['lote'] === $movs[ $nora['id'] ][1]['lote'], 'un pago de la familia (30 €) se reparte por orden: 21 € para Leo y 9 € para Nora, en el mismo lote' );
	$ok( InscripcionesTable::CONFIRMADA === $leo['estado'] && 1 === $asunto( 'Inscripción confirmada' ), 'Leo, firmado y pagado, queda confirmado (y la familia recibe el email)' );
	$enviar( new EventosView(), array( 'asde_action' => 'evento_anular', 'evento' => (string) $id_a, 'pestana' => 'inscripciones', 'movimiento' => (string) $movs[ $nora['id'] ][1]['id'] ), 'asde_evento_' . $id_a );
	$movs = MovimientosTable::de_evento( $id_a );
	$leo  = $fila( $id_a, '990051' );
	$ok( null !== $movs[ $leo['id'] ][0]['anulado_en'] && null !== $movs[ $nora['id'] ][1]['anulado_en'] && InscripcionesTable::PENDIENTE === $leo['estado'], 'anular el pago de la familia lo anula entero (no se borra) y Leo vuelve a pendiente' );
	$enviar( new EventosView(), array( 'asde_action' => 'evento_pago', 'evento' => (string) $id_a, 'pestana' => 'inscripciones', 'fila' => (string) $leo['id'], 'importe' => '21', 'forma' => 'efectivo', 'fecha' => current_time( 'Y-m-d' ) ), 'asde_evento_' . $id_a );
	$ok( InscripcionesTable::CONFIRMADA === $fila( $id_a, '990051' )['estado'] && 1 === $asunto( 'Inscripción confirmada' ), 'pagado en efectivo, vuelve a confirmada sin repetir el email' );
	$h = $enviar( new EventosView(), array( 'asde_action' => 'evento_pago', 'evento' => (string) $id_a, 'pestana' => 'inscripciones', 'fila' => (string) $nora['id'], 'importe' => '30', 'forma' => 'efectivo', 'fecha' => current_time( 'Y-m-d' ) ), 'asde_evento_' . $id_a );
	$ok( str_contains( $h, 'más de lo que queda por pagar' ), 'no se cobra más de lo que queda (Nora debe 27 €)' );
	$h = $enviar( new EventosView(), array( 'asde_action' => 'evento_pago', 'evento' => (string) $id_a, 'pestana' => 'inscripciones', 'fila' => (string) $nora['id'], 'importe' => '5', 'forma' => 'efectivo', 'fecha' => $dia( '+2 days' ) ), 'asde_evento_' . $id_a );
	$ok( str_contains( $h, 'posterior a hoy' ), 'ni con una fecha futura' );
	$a_f1 = $como( $u_fam1 );
	$h    = $pintar( new MisPagosView() );
	$ok( str_contains( $h, 'Evento ' . EventosTable::numero( $ev( $id_a ) ) ) && str_contains( $h, '27,00 €' ) && str_contains( $h, 'Referencia para la transferencia o el Bizum: ' . EventosTable::numero( $ev( $id_a ) ) . ' ' . $fam['numero'] ) && str_contains( $h, 'Efectivo' ) && ! str_contains( $h, 'Bizum familia' ), '«Mis pagos»: el pago de Leo (no el anulado) y lo que queda de Nora, con su referencia' );
	$como( $u_teso );
	$enviar( new EventosView(), array( 'asde_action' => 'evento_pago_familia', 'evento' => (string) $id_a, 'pestana' => 'inscripciones', 'familia' => (string) $f_id, 'importe' => '27', 'forma' => 'transferencia', 'fecha' => current_time( 'Y-m-d' ) ), 'asde_evento_' . $id_a );
	$ok( InscripcionesTable::CONFIRMADA === $fila( $id_a, '990052' )['estado'] && ! isset( Cobros::familias( $ev( $id_a ) )[ $f_id ] ), 'Nora (autorización en papel) pagada: confirmada; la familia ya no debe nada' );
	$log = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT detalles FROM ' . Logger::table() . " WHERE id > %d AND accion = 'evento_ajuste' ORDER BY id DESC LIMIT 1", $log_antes ) ); // phpcs:ignore WordPress.DB
	$ok( str_contains( $log, 'rebaja' ) && ! str_contains( $log, 'Beca del grupo' ), 'la auditoría anota la beca sin su motivo' );

	echo "\n== Parte I · Plazo cerrado y bajas ==\n";
	EventosTable::guardar( $id_a, array( 'inscripcion_hasta' => $dia( '-1 day' ) ) );
	$a_f1 = $como( $u_fam1 );
	$card = $tarjeta( $pintar( new MisEventosView() ), $id_a );
	$ok( 'cerrada' === Inscripciones::plazo( $ev( $id_a ) ) && str_contains( $card, 'Leo Insc Ruiz' ) && ! str_contains( $card, 'Darse de baja' ), 'con el plazo cerrado, la familia ya no ve «Darse de baja»' );
	$h = $enviar( new MisEventosView(), array( 'asde_action' => 'inscripcion_baja', 'evento' => (string) $id_a, 'fila' => (string) $leo['id'] ), 'asde_mis_eventos_' . $id_a );
	$ok( InscripcionesTable::CONFIRMADA === $fila( $id_a, '990051' )['estado'] && str_contains( $h, 'habla con el equipo' ), 'ni se puede dar de baja: tiene que hablar con el equipo' );
	$como( $u_lob );
	$h = $enviar( new EventosView(), array( 'asde_action' => 'evento_baja', 'evento' => (string) $id_a, 'pestana' => 'inscripciones', 'fila' => (string) $bru['id'] ), 'asde_evento_' . $id_a );
	$ok( InscripcionesTable::PENDIENTE === $fila( $id_a, '990061' )['estado'] && str_contains( $h, 'motivo' ), 'el equipo sí, pero con motivo' );
	$enviar( new EventosView(), array( 'asde_action' => 'evento_baja', 'evento' => (string) $id_a, 'pestana' => 'inscripciones', 'fila' => (string) $bru['id'], 'motivo' => 'Está enfermo' ), 'asde_evento_' . $id_a );
	$bru = $fila( $id_a, '990061' );
	$ok( InscripcionesTable::BAJA === $bru['estado'] && 'Está enfermo' === $bru['baja_motivo'] && $u_lob === $bru['baja_por'], 'baja de Bru con su motivo (quién y cuándo)' );
	EventosTable::guardar( $id_a, array( 'inscripcion_hasta' => null ) );

	echo "\n== Parte J · Pantalla del equipo y normativa ==\n";
	$a_lob = $como( $u_lob );
	$h     = $pintar( new EventosView(), array( 'evento' => (string) $id_a, 'pestana' => 'inscripciones' ) );
	$ok( str_contains( $h, 'Leo Insc Ruiz' ) && str_contains( $h, 'Nora Insc Ruiz' ) && str_contains( $h, 'Abuela Rosa' ) && str_contains( $h, 'Bajas y «no va»' ) && str_contains( $h, 'Inscribir a alguien' ) && ! str_contains( $h, 'Registrar un pago' ), 'la scouter ve participantes, quién recoge, las bajas y el formulario para inscribir; no los cobros' );
	$como( $u_teso );
	$h = $pintar( new EventosView(), array( 'evento' => (string) $id_a, 'pestana' => 'inscripciones' ) );
	$ok( str_contains( $h, 'Beca o ajuste' ) && str_contains( $h, '(anulado)' ) && ! str_contains( $h, 'Abuela Rosa' ), 'Tesorería ve los cobros (también los anulados), pero no quién recoge ni las observaciones' );
	$e = $ev( $id_a );
	$e['inscritos'] = Inscripciones::recuento( $e, InscripcionesTable::participantes( $id_a ) );
	$ok( 3 === $e['inscritos']['total'] && 3 === $e['inscritos']['menores'] && 7 === Normativa::participantes( $e ), 'la normativa cuenta a los inscritos (3 menores) y, con el plazo abierto, aún usa la previsión (7) si es mayor' );
	$e['inscritos']['cerrado'] = true;
	$ok( 3 === Normativa::participantes( $e ) && 3 === Normativa::menores_previstos( $e ), 'con el plazo cerrado, solo los inscritos' );
	$ok( ! Servicio::puede_despublicar( $ev( $id_a ), $a_lob ) && ! Servicio::puede_reabrir( $ev( $id_a ) ), 'con inscripciones, ni vuelve a borrador ni se reabre el presupuesto' );

	echo "\n== Parte K · Recordatorio y cancelación ==\n";
	$capturados = array();
	$n          = Avisos::diario( EventosTable::marca( $ev( $id_a )['inicio'] ) - 2 * DAY_IN_SECONDS, array( $id_a, $id_b ) );
	$esperados  = array( $correo( 'prueba-ins-fam1' ), $correo( 'prueba-ins-fam1' ), $correo( 'prueba-ins-fam2' ), $correo( 'prueba-ins-fam4' ), $correo( 'prueba-ins-max' ), $correo( 'prueba-ins-rita' ) );
	sort( $esperados );
	$ok( 6 === $n && $esperados === $a_quien( 'Recordatorio' ), 'a 2 días del inicio sale el recordatorio a cada inscripción activa (Leo y Nora, Lía, Max a su familia y a él, Rita)' );
	$lia_mail = array_values( array_filter( $capturados, static fn( $m ) => $correo( 'prueba-ins-fam4' ) === ( is_array( $m['to'] ) ? $m['to'][0] : $m['to'] ) ) );
	$ok( isset( $lia_mail[0] ) && str_contains( (string) $lia_mail[0]['message'], 'Todavía falta' ) && str_contains( (string) $lia_mail[0]['message'], '21,00 €' ), 'el de Lía dice lo que falta: pagar 21 € con su referencia' );
	$ok( 0 === Avisos::diario( EventosTable::marca( $ev( $id_a )['inicio'] ) - DAY_IN_SECONDS, array( $id_a, $id_b ) ), 'y no se repite al día siguiente' );
	$a_lob = $como( $u_lob );
	Servicio::cancelar( $ev( $id_a ), $a_lob, 'Alerta meteorológica' );
	$ok( false !== wp_next_scheduled( Avisos::HOOK, array( $grupo['numero'], $id_a, 'cancelado' ) ), 'al cancelar un evento publicado se programa el aviso' );
	$capturados = array();
	$res        = Avisos::masivo( $grupo['numero'], $id_a, 'cancelado' );
	$ok( 2 === $res['enviados'] && array( $correo( 'prueba-ins-fam1' ), $correo( 'prueba-ins-fam4' ) ) === $a_quien( 'Evento cancelado' ) && str_contains( (string) $capturados[0]['message'], 'Alerta meteorológica' ), 'y llega a las familias con alguien inscrito (una vez por cuenta), con el motivo' );
	$como( $u_fam1 );
	$card = $tarjeta( $pintar( new MisEventosView() ), $id_a );
	$ok( str_contains( $card, 'Cancelado. Motivo: Alerta meteorológica' ) && ! str_contains( $card, 'Inscribir a' ) && ! str_contains( $card, 'Darse de baja' ), 'en «Mis eventos» la familia lo ve cancelado, sin botones' );

	echo "\n== Parte L · Registro de emails, seguridad y datos ==\n";
	$filas_email = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . RegistroEmails::table() . " WHERE id > %d AND tipo = 'eventos'", $emails_antes ), ARRAY_A ); // phpcs:ignore WordPress.DB
	$todo        = implode( ' ', array_column( $filas_email, 'datos' ) );
	$ok( count( $filas_email ) >= 10 && ! str_contains( $todo, 'Leo' ) && ! str_contains( $todo, 'Rosa' ) && ! str_contains( $todo, '@example.invalid' ), 'los emails quedan en el registro (tipo «eventos») sin nombres ni direcciones' );
	$fila_conf = current( array_filter( $filas_email, static fn( $f ) => str_contains( (string) $f['asunto'], 'confirmada' ) ) );
	$ok( $fila_conf && str_contains( RegistroEmails::vista_previa( $fila_conf ), 'está confirmada' ), 'y se pueden volver a ver (vista previa)' );
	$como( $u_fam1 );
	$_POST    = array( 'asde_action' => 'inscripcion_no', 'evento' => (string) $id_b, 'socio' => '990051', '_wpnonce' => 'nonce-falso' );
	$_REQUEST = $_POST;
	$nonce_ok = false;
	add_filter( 'wp_die_handler', $morir = static fn() => static function () {
		throw new RuntimeException( 'asde-die' );
	} );
	try {
		( new MisEventosView() )->handle_post();
	} catch ( RuntimeException $ex ) {
		$nonce_ok = 'asde-die' === $ex->getMessage();
	} finally {
		remove_filter( 'wp_die_handler', $morir );
		$_POST    = array();
		$_REQUEST = array();
	}
	$ok( $nonce_ok && null === $fila( $id_b, '990051' ), 'sin un nonce válido, la acción se rechaza y nada cambia' );
	$cols = array_keys( (array) $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . InscripcionesTable::table() . ' WHERE id > %d LIMIT 1', $ins_antes ), ARRAY_A ) ); // phpcs:ignore WordPress.DB
	$ok( $cols && ! array_intersect( $cols, array( 'nombre', 'dni', 'email', 'telefono', 'salud', 'alergias', 'ip' ) ), 'la tabla de inscripciones no tiene columnas de datos personales (ni la IP)' );
	$ok( $crm->llamadas > 0 && 0 === $cortadas, "todas las consultas al CRM las ha respondido el simulador ({$crm->llamadas}); ninguna ha salido hacia el CRM real" );
} finally {
	// ---------------------------------------------------------------------------
	// Limpieza: todo lo creado fuera; lo tocado, como estaba.
	remove_filter( 'wp_redirect', $redirigir, 1 );
	remove_filter( 'send_auth_cookies', '__return_false' );
	remove_filter( 'pre_wp_mail', $capturar, 5 );
	remove_action( Avisos::PUSH, $push, 10 );
	$_POST = $_GET = $_REQUEST = array();
	Escritura::usar_para_pruebas( null );
	$crm->desactivar();
	remove_filter( 'pre_http_request', $cortar_crm, 2 );
	wp_using_ext_object_cache( $cache_externa );
	wp_set_current_user( 0 );
	if ( null === $ip_antes ) {
		unset( $_SERVER['REMOTE_ADDR'] );
	} else {
		$_SERVER['REMOTE_ADDR'] = $ip_antes;
	}

	$creados = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . EventosTable::table() . ' WHERE id > %d', $ev_antes ) ) ); // phpcs:ignore WordPress.DB
	foreach ( $creados as $eid ) {
		foreach ( array( 'publicado', 'cancelado' ) as $tipo ) {
			wp_clear_scheduled_hook( Avisos::HOOK, array( $grupo['numero'], $eid, $tipo ) );
		}
	}
	$lista = implode( ',', array_map( 'intval', $ids ) ) ?: '0';
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . EventosTable::table() . ' WHERE id > %d', $ev_antes ) ); // phpcs:ignore WordPress.DB
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . InscripcionesTable::table() . ' WHERE id > %d', $ins_antes ) ); // phpcs:ignore WordPress.DB
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . PartidasTable::table() . ' WHERE id > %d', $par_antes ) ); // phpcs:ignore WordPress.DB
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . MovimientosTable::table() . ' WHERE id > %d', $mov_antes ) ); // phpcs:ignore WordPress.DB
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . RegistroEmails::table() . " WHERE id > %d AND user_id IN ($lista)", $emails_antes ) ); // phpcs:ignore WordPress.DB
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Logger::table() . " WHERE id > %d AND ( user_id IN ($lista) OR objeto = 'evento' )", $log_antes ) ); // phpcs:ignore WordPress.DB
	$familias = implode( ',', array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . FamiliasTable::table() . ' WHERE id > %d', $fam_antes ) ) ) ) ?: '0'; // phpcs:ignore WordPress.DB
	$wpdb->query( 'DELETE FROM ' . FamiliasTable::table_miembros() . " WHERE familia_id IN ($familias)" ); // phpcs:ignore WordPress.DB
	$wpdb->query( 'DELETE FROM ' . FamiliasTable::table() . " WHERE id IN ($familias)" ); // phpcs:ignore WordPress.DB
	foreach ( $ids as $uid ) {
		delete_transient( 'asde_ap_notices_' . $uid );
		Ambito::olvidar( (int) $uid );
		wp_delete_user( (int) $uid );
	}
	if ( null === $cfg_antes ) {
		delete_option( $opcion_cfg );
	} else {
		update_option( $opcion_cfg, $cfg_antes, false );
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
	Servicio::olvidar();
	Grupo::forzar( null );
	null === $modulos_antes ? delete_option( 'asde_ap_modulos' ) : update_option( 'asde_ap_modulos', $modulos_antes, false );
	\AsdeBalears\AreaPrivada\Domain\Modulos::olvidar();

	$quedan    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users} WHERE user_login LIKE 'prueba-ins-%'" ); // phpcs:ignore WordPress.DB
	$programados = 0;
	foreach ( $creados as $eid ) {
		$programados += (int) (bool) wp_next_scheduled( Avisos::HOOK, array( $grupo['numero'], $eid, 'publicado' ) ) + (int) (bool) wp_next_scheduled( Avisos::HOOK, array( $grupo['numero'], $eid, 'cancelado' ) );
	}
	$ok( 0 === $quedan && $ev_antes === $max_id( EventosTable::table() ) && $ins_antes === $max_id( InscripcionesTable::table() ) && $mov_antes === $max_id( MovimientosTable::table() ) && $fam_antes === $max_id( FamiliasTable::table() ) && 0 === $programados && get_option( $opcion_cfg, null ) == $cfg_antes, 'limpieza: sin usuarios, eventos, inscripciones, cobros, familias ni avisos programados de la prueba; configuración como estaba' ); // phpcs:ignore WordPress.DB
}

echo $fails ? "\n$fails FALLOS\n" : "\nTODO OK (nada tocado en el CRM real; el sitio queda como estaba)\n";
