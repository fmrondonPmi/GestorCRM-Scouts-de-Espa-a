<?php
/**
 * Pruebas de la v0.36 (entorno LOCAL): las cuentas de los eventos (doc 43, «Tercera fase»):
 * gastos con justificante cifrado, revisión de Tesorería, reembolsos, ingresos, anticipos de
 * caja, fondo de emergencia, devoluciones a las familias, la remesa SEPA de un evento, el
 * balance y el cierre (solo lectura después).
 * Ejecutar desde app/public del site Local:
 *
 *   wp eval-file <ruta completa>/wp-content/plugins/asde-area-privada/tests/eventos-economia.php
 *
 * Usa el CRM simulado de tests/lib (fichas inventadas: ninguna petición llega al CRM real),
 * guarda las cachés solo en la memoria de este proceso y no deja salir ningún email. Al terminar
 * se borra lo creado (por ids: usuarios, eventos, inscripciones, partidas, cobros, gastos,
 * anticipos, justificantes, familias, autorizaciones y remesas SEPA, emails y registros) y se
 * restauran las opciones tocadas.
 */
defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/lib/CrmSimulado.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Auth\Ambito;
use AsdeBalears\AreaPrivada\Crm\Escritura;
use AsdeBalears\AreaPrivada\Domain\Grupo;
use AsdeBalears\AreaPrivada\Domain\Grupos;
use AsdeBalears\AreaPrivada\Domain\Modulos;
use AsdeBalears\AreaPrivada\Eventos\AnticiposTable;
use AsdeBalears\AreaPrivada\Eventos\Avisos;
use AsdeBalears\AreaPrivada\Eventos\Cobros;
use AsdeBalears\AreaPrivada\Eventos\Config;
use AsdeBalears\AreaPrivada\Eventos\Cuentas;
use AsdeBalears\AreaPrivada\Eventos\EventosTable;
use AsdeBalears\AreaPrivada\Eventos\GastosTable;
use AsdeBalears\AreaPrivada\Eventos\Inscripciones;
use AsdeBalears\AreaPrivada\Eventos\InscripcionesTable;
use AsdeBalears\AreaPrivada\Eventos\Justificantes;
use AsdeBalears\AreaPrivada\Eventos\MovimientosTable;
use AsdeBalears\AreaPrivada\Eventos\PartidasTable;
use AsdeBalears\AreaPrivada\Eventos\Servicio;
use AsdeBalears\AreaPrivada\Frontend\Notices;
use AsdeBalears\AreaPrivada\Frontend\Views\EventosView;
use AsdeBalears\AreaPrivada\Frontend\Views\MisPagosView;
use AsdeBalears\AreaPrivada\Frontend\Views\SepaView;
use AsdeBalears\AreaPrivada\Install\Roles;
use AsdeBalears\AreaPrivada\Sepa\Config as SepaConfig;
use AsdeBalears\AreaPrivada\Sepa\Mandatos;
use AsdeBalears\AreaPrivada\Sepa\Remesas;
use AsdeBalears\AreaPrivada\Socios\SociosTable;
use AsdeBalears\AreaPrivada\Tesoreria\FamiliasTable;
use AsdeBalears\AreaPrivada\Tesoreria\HistorialPagos;
use AsdeBalears\AreaPrivada\Frontend\Views\TesoreriaView;
use AsdeBalears\AreaPrivada\Informes\Xlsx;
use AsdeBalears\AreaPrivada\Usuarios\EmailCuenta;
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
foreach ( get_users( array( 'search' => 'prueba-eco-*', 'search_columns' => array( 'user_login' ) ) ) as $u ) {
	exit( 'Ya existe el usuario ' . $u->user_login . " (¿una ejecución anterior interrumpida?). Bórralo antes de repetir la prueba.\n" );
}
wp_set_current_user( 0 );
$ip_antes               = $_SERVER['REMOTE_ADDR'] ?? null;
$_SERVER['REMOTE_ADDR'] = '192.0.2.83';

// --- Lo que se restaura al final ---------------------------------------------
$max_id       = static fn( string $t ) => (int) $wpdb->get_var( "SELECT COALESCE(MAX(id), 0) FROM $t" ); // phpcs:ignore WordPress.DB
$log_antes    = $max_id( Logger::table() );
$ev_antes     = $max_id( EventosTable::table() );
$ins_antes    = $max_id( InscripcionesTable::table() );
$par_antes    = $max_id( PartidasTable::table() );
$mov_antes    = $max_id( MovimientosTable::table() );
$gas_antes    = $max_id( GastosTable::table() );
$ant_antes    = $max_id( AnticiposTable::table() );
$fam_antes    = $max_id( FamiliasTable::table() );
$man_antes    = $max_id( Mandatos::table() );
$rem_antes    = $max_id( Remesas::table() );
$emails_antes = $max_id( RegistroEmails::table() );
$socios_antes = array_column( (array) $wpdb->get_results( 'SELECT * FROM ' . SociosTable::table(), ARRAY_A ), null, 'num_socio_asde' ); // phpcs:ignore WordPress.DB
$opcion_cfg   = 'asde_ap_eventos_' . $grupo['numero'];
$cfg_antes    = get_option( $opcion_cfg, null );
$op_sepa      = 'asde_ap_sepa_' . $grupo['numero'];
$sepa_antes   = get_option( $op_sepa, null );
$modulos_antes = get_option( 'asde_ap_modulos', null );
$uploads      = wp_upload_dir( null, false );
$raiz_priv    = trailingslashit( $uploads['basedir'] ) . 'asde-privado';
$raiz_existia = is_dir( $raiz_priv );

// Cachés solo en memoria; emails capturados (no sale ninguno); CRM real cortado; sin instantánea.
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
add_filter( 'asde_ap_instantanea_segundos', '__return_zero' );

$dia = static fn( string $mod ) => wp_date( 'Y-m-d', strtotime( $mod ) );
$hoy = current_time( 'Y-m-d' );

$P   = array( 'Asde_Prueba_Crm', 'persona' );
$crm = new Asde_Prueba_Crm(
	array(
		$P( 81, 'Leo', 'Eco Ruiz', '2016-05-10', 'Lobatos' ),
		$P( 82, 'Nora', 'Eco Ruiz', '2013-02-20', 'Scouts' ),
		$P( 83, 'Max', 'Eco Pons', '2016-03-03', 'Lobatos' ),
		$P( 85, 'Ana', 'Eco Gil', '1990-01-01', 'Scouters' ),
		$P( 86, 'Pol', 'Eco Teso', '1985-04-04', 'Scouters' ),
		$P( 87, 'Gil', 'Eco Esc', '1992-02-02', 'Scouters' ),
		$P( 88, 'Ivo', 'Eco Mon', '1994-06-06', 'Scouters' ),
	),
	$grupo['crm_id']
);
Grupo::forzar( $grupo );
Modulos::guardar( (string) $grupo['numero'], array_keys( Modulos::LISTA ) ); // Eventos y SEPA, encendidos.
$crm->activar();
Escritura::usar_para_pruebas( $crm );

$ids   = array();
$crear = static function ( string $login, array $roles, array $meta, ?string $email = null ) use ( &$ids, $grupo ): int {
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
	if ( $email ) {
		EmailCuenta::guardar( $id, $email );
	}
	$ids[ $login ] = $id;
	return $id;
};
$u_lob  = $crear( 'prueba-eco-lob', array( Roles::SCOUTER ), array( Usuarios::META_SOCIO => '990085', Usuarios::META_SECCION => array( 'lobatos', 'scouts' ) ) );
$u_teso = $crear( 'prueba-eco-teso', array( Roles::SCOUTER, Roles::TESORERIA ), array( Usuarios::META_SOCIO => '990086', Usuarios::META_SECCION => 'rovers' ) );
$u_esc  = $crear( 'prueba-eco-esc', array( Roles::SCOUTER ), array( Usuarios::META_SOCIO => '990087', Usuarios::META_SECCION => 'escultas' ) );
$u_mon  = $crear( 'prueba-eco-mon', array( Roles::SCOUTER ), array( Usuarios::META_SOCIO => '990088', Usuarios::META_SECCION => 'lobatos' ) );
$u_fam1 = $crear( 'prueba-eco-fam1', array( Roles::TUTOR ), array( Usuarios::META_REPRESENTADO => array( '990081', '990082' ) ), 'familia1.eco@example.invalid' );
$u_fam3 = $crear( 'prueba-eco-fam3', array( Roles::TUTOR ), array( Usuarios::META_REPRESENTADO => '990083' ), 'familia3.eco@example.invalid' );

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
$ev   = static fn( int $id ) => EventosTable::obtener( $id );
$fila = static fn( int $evento, string $num ) => InscripcionesTable::de_socio( $evento, $num );
$cobro = static function ( int $evento, string $num ): array {
	$f = InscripcionesTable::de_socio( $evento, $num );
	return Cobros::de( $f, MovimientosTable::de_inscripciones( array( $f['id'] ) )[ $f['id'] ] ?? array() );
};
// Justificantes de prueba: ficheros temporales (se borran al final).
$temporales = array();
$fichero    = static function ( string $contenido, string $nombre ) use ( &$temporales ): array {
	$ruta = wp_tempnam( $nombre );
	file_put_contents( $ruta, $contenido ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	$temporales[] = $ruta;
	return array(
		'ruta'   => $ruta,
		'nombre' => $nombre,
	);
};
$PNG = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
$PDF = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";
$gasto = static fn( array $extra = array() ) => $extra + array(
	'fecha'      => current_time( 'Y-m-d' ),
	'importe'    => '40,50',
	'forma'      => 'tarjeta',
	'concepto'   => 'Compra de comida',
	'proveedor'  => 'Súper de prueba',
	'pagado_por' => GastosTable::PAGO_GRUPO,
);

try {
	Config::guardar( Config::defaults(), $grupo['numero'] );

	echo "== Parte A · Evento con presupuesto, publicado y con inscripciones ==\n";
	$a_lob = $como( $u_lob );
	$id_e  = Servicio::crear(
		$a_lob,
		array(
			'tipo'              => 'acampada',
			'nombre'            => 'Acampada de cuentas',
			'ambito'            => 'secciones',
			'secciones'         => array( 'lobatos', 'scouts' ),
			'inicio_fecha'      => $dia( '+30 days' ),
			'inicio_hora'       => '10:00',
			'fin_fecha'         => $dia( '+31 days' ),
			'fin_hora'          => '17:00',
			'lugar'             => 'Albergue de prueba',
			'previstas_lobatos' => '2',
			'previstas_scouts'  => '1',
			'con_coste'         => '1',
			'modalidad'         => 'abierta',
		)
	);
	if ( is_wp_error( $id_e ) ) {
		throw new RuntimeException( 'No se ha podido crear el evento: ' . $id_e->get_error_message() );
	}
	Servicio::anadir_equipo( $ev( $id_e ), $a_lob, '990085', 'director' );
	Servicio::anadir_equipo( $ev( $id_e ), $a_lob, '990088', 'monitor' );
	Servicio::anadir_partida( $ev( $id_e ), $a_lob, 'gasto', 'Autobús', 'total', '', 90.0 );
	Servicio::anadir_partida( $ev( $id_e ), $a_lob, 'gasto', 'Comida', 'participante', '', 20.0 );
	Servicio::anadir_partida( $ev( $id_e ), $a_lob, 'ingreso', 'Subvención', 'total', '', 30.0 );
	Servicio::pedir_validacion( $ev( $id_e ), $a_lob );
	$como( $u_teso );
	$calc = Servicio::validar( $ev( $id_e ) );
	$ok( is_array( $calc ) && $calc['precios']['lobatos'] > 0, 'Tesorería valida el presupuesto (precio por sección fijo)' );
	$a_lob = $como( $u_lob );
	Servicio::publicar( $ev( $id_e ), $a_lob );
	foreach ( array( '990081', '990082', '990083' ) as $num ) {
		Inscripciones::inscribir_gestor( $ev( $id_e ), $a_lob, $num, array( 'transporte_ida' => 'grupo', 'transporte_vuelta' => 'grupo', 'recoge' => 'Su abuela (dato inventado)', 'observaciones' => 'Observación inventada' ) );
	}
	$e = $ev( $id_e );
	$ok( EventosTable::PUBLICADO === $e['estado'] && $fila( $id_e, '990081' ) && $fila( $id_e, '990083' ) && ! empty( $fila( $id_e, '990081' )['datos_enc'] ), 'evento publicado con tres inscripciones (con quién recoge y observaciones, cifrados)' );
	$precio_leo = (float) $fila( $id_e, '990081' )['precio'];

	echo "\n== Parte B · Quién ve las cuentas ==\n";
	$ok( Cuentas::puede_ver( $e, $como( $u_lob ) ) && Cuentas::puede_ver( $e, $como( $u_teso ) ) && ! Cuentas::puede_ver( $e, $como( $u_esc ) ), 'las ven quien gestiona el evento y Tesorería; un scouter de otra sección, no' );
	$como( $u_lob );
	$h = $pintar( new EventosView(), array( 'evento' => (string) $id_e, 'pestana' => 'cuentas' ) );
	$ok( str_contains( $h, 'pestana=cuentas' ) && str_contains( $h, 'Apuntar un gasto' ) && str_contains( $h, 'Enviar a Tesorería' ) && ! str_contains( $h, 'Dar un anticipo' ) && str_contains( $h, 'enctype="multipart/form-data"' ), 'quien gestiona ve la pestaña «Cuentas» y el formulario para proponer gastos (con fichero); no da anticipos' );
	$como( $u_esc );
	$h = $pintar( new EventosView(), array( 'evento' => (string) $id_e, 'pestana' => 'cuentas' ) );
	$ok( ! str_contains( $h, 'pestana=cuentas' ) && ! str_contains( $h, 'Apuntar un gasto' ), 'quien no gestiona el evento no tiene la pestaña ni las cuentas (aunque la pida en la dirección)' );

	echo "\n== Parte C · Gastos con justificante (cifrado) ==\n";
	$a_lob = $como( $u_lob );
	$r     = Cuentas::proponer( $e, $a_lob, $gasto(), null );
	$ok( is_wp_error( $r ) && str_contains( $r->get_error_message(), 'justificante' ) && $gas_antes === $max_id( GastosTable::table() ), 'sin justificante no se puede proponer un gasto (P-158)' );
	$r = Cuentas::proponer( $e, $a_lob, $gasto(), $fichero( 'esto no es una imagen', 'tique.png' ) );
	$ok( is_wp_error( $r ) && str_contains( $r->get_error_message(), 'imagen' ), 'un fichero que dice ser PNG pero no lo es se rechaza (se mira el contenido, no el nombre)' );
	$r = Cuentas::proponer( $e, $a_lob, $gasto( array( 'fecha' => $dia( '+2 days' ) ) ), $fichero( $PNG, 'tique.png' ) );
	$ok( is_wp_error( $r ) && str_contains( $r->get_error_message(), 'posterior' ), 'la fecha del gasto no puede ser futura' );
	$partidas = array_column( PartidasTable::previstas( $id_e ), 'id', 'concepto' );
	$g1       = Cuentas::proponer( $e, $a_lob, $gasto( array( 'partida' => (string) $partidas['Comida'], 'pagado_por' => GastosTable::PAGO_PERSONA ) ), $fichero( $PNG, 'tique comida.png' ) );
	$gg1      = GastosTable::obtener( (int) $g1 );
	$ok( is_int( $g1 ) && GastosTable::PROPUESTO === $gg1['estado'] && $u_lob === $gg1['persona'] && $partidas['Comida'] === $gg1['partida_id'] && 40.5 === $gg1['importe'] && 'image/png' === $gg1['justificante_tipo'], 'quien gestiona propone un gasto pagado de su bolsillo: «por revisar», a su nombre, con su partida y el justificante PNG' );
	$ruta = Justificantes::carpeta() . '/' . $gg1['justificante'];
	$crudo = (string) file_get_contents( $ruta ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	$ok( preg_match( '/^[0-9a-f]{32}\.bin$/', $gg1['justificante'] ) && is_file( $ruta ) && ! str_contains( $crudo, 'PNG' ) && $PNG === Justificantes::leer( $gg1 ), 'el justificante se guarda con nombre aleatorio y cifrado (no se ve el PNG), y se descifra igual' );
	$ok( is_file( Justificantes::carpeta() . '/.htaccess' ) && str_contains( (string) file_get_contents( $raiz_priv . '/.htaccess' ), 'Require all denied' ) && is_file( $raiz_priv . '/index.php' ) && is_file( Justificantes::carpeta() . '/web.config' ), 'la carpeta privada está cerrada a la web (.htaccess, web.config e index.php)' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	$g2 = Cuentas::proponer( $e, $a_lob, $gasto( array( 'concepto' => 'Gas del hornillo', 'importe' => '12', 'forma' => 'efectivo' ) ), $fichero( $PDF, 'factura.pdf' ) );
	$g3 = Cuentas::proponer( $e, $a_lob, $gasto( array( 'concepto' => 'Repetido por error', 'importe' => '5' ) ), $fichero( $PNG, 'x.png' ) );
	$ok( is_int( $g2 ) && 'application/pdf' === GastosTable::obtener( $g2 )['justificante_tipo'] && is_int( $g3 ), 'un PDF también vale como justificante' );
	$b = Cuentas::balance( $ev( $id_e ) );
	$ok( 3 === $b['por_revisar'] && 0.0 === $b['gastos'], 'lo propuesto no cuenta en el balance hasta que se valida (3 por revisar, 0 € de gastos)' );
	$ok( is_wp_error( Cuentas::validar( $e, $g1 ) ), 'quien gestiona no puede validar sus gastos' );
	$h = $enviar( new EventosView(), array( 'asde_action' => 'evento_gasto', 'evento' => (string) $id_e, 'pestana' => 'cuentas' ) + $gasto(), 'asde_evento_' . $id_e );
	$ok( str_contains( $h, 'justificante' ) && 3 === count( GastosTable::de_evento( $id_e ) ), 'desde la pantalla, sin fichero subido, se explica que falta el justificante y no se guarda' );

	echo "\n== Parte D · Tesorería revisa, reembolsa y anula ==\n";
	$como( $u_teso );
	$ok( true === Cuentas::validar( $e, $g1 ) && GastosTable::VALIDADO === GastosTable::obtener( $g1 )['estado'] && GastosTable::por_reembolsar( GastosTable::obtener( $g1 ) ), 'Tesorería valida el gasto: cuenta y queda pendiente de reembolso a quien lo pagó' );
	// Doc 59, RC-DEC-3: los ficheros de los justificantes antes de rechazar y retirar.
	$fich = static fn( int $gid ): string => Justificantes::carpeta() . '/' . (string) GastosTable::obtener( $gid )['justificante'];
	$f_g1 = $fich( (int) $g1 );
	$f_g2 = $fich( (int) $g2 );
	$f_g3 = $fich( (int) $g3 );
	$ok( is_file( $f_g1 ) && is_file( $f_g2 ) && is_file( $f_g3 ), 'los tres gastos tienen su fichero de justificante' );
	$r = Cuentas::rechazar( $e, $g2, '' );
	$ok( is_wp_error( $r ) && str_contains( $r->get_error_message(), 'motivo' ), 'rechazar pide el motivo' );
	$ok( true === Cuentas::rechazar( $e, $g2, 'No es de este evento' ) && 'No es de este evento' === GastosTable::obtener( $g2 )['motivo'] && GastosTable::RECHAZADO === GastosTable::obtener( $g2 )['estado'], 'rechazado con su motivo' );
	$como( $u_mon );
	$ok( is_wp_error( Cuentas::retirar( $e, $g3 ) ), 'otra persona del equipo no retira un gasto que no propuso' );
	$como( $u_lob );
	$retirado = \AsdeBalears\AreaPrivada\Idiomas\Idioma::en( 'ca', static fn() => Cuentas::retirar( $e, $g3 ) ); // En catalán: el motivo se guarda igualmente en castellano (doc 59, RC-EVE-3).
	$ok( true === $retirado && null !== GastosTable::obtener( $g3 )['anulado_en'] && is_wp_error( Cuentas::retirar( $e, $g1 ) ), 'quien lo propuso lo retira mientras está por revisar (uno ya validado, no)' );
	$ok( Cuentas::MOTIVO_RETIRADO === GastosTable::obtener( $g3 )['motivo'], 'el motivo «Retirado» se guarda en castellano aunque quien lo retira use otro idioma (RC-EVE-3)' );
	$ok( ! is_file( $f_g2 ) && '' === GastosTable::obtener( $g2 )['justificante'], 'al rechazar un gasto se borra su justificante (fichero y referencia) (RC-DEC-3)' );
	$ok( ! is_file( $f_g3 ) && '' === GastosTable::obtener( $g3 )['justificante'], 'al retirarlo, también' );
	$ok( is_file( $f_g1 ) && $PNG === Justificantes::leer( GastosTable::obtener( $g1 ) ), 'el del gasto válido se conserva' );
	$como( $u_teso );
	$b = Cuentas::balance( $ev( $id_e ) );
	$ok( 0 === $b['por_revisar'] && 40.5 === $b['gastos'] && 1 === $b['reembolsos']['n'] && 40.5 === $b['reembolsos']['importe'], 'balance: 40,50 € de gastos y un reembolso pendiente de 40,50 €' );
	$ok( is_wp_error( Cuentas::reembolsar( $e, $g1, $dia( '+1 day' ), 'transferencia' ) ) && is_wp_error( Cuentas::reembolsar( $e, $g1, $hoy, 'cheque' ) ), 'el reembolso pide una fecha que no sea futura y una forma de pago válida' );
	$ok( true === Cuentas::reembolsar( $e, $g1, $hoy, 'transferencia' ) && $hoy === GastosTable::obtener( $g1 )['reembolsado_en'] && 0 === Cuentas::balance( $ev( $id_e ) )['reembolsos']['n'], 'reembolsado: ya no está pendiente' );
	$r = Cuentas::anular( $e, $g1, 'Error' );
	$ok( is_wp_error( $r ), 'un gasto ya reembolsado no se anula' );
	$g4 = Cuentas::proponer( $e, $como( $u_teso ), $gasto( array( 'concepto' => 'Botiquín', 'importe' => '8', 'con_fondo' => '1' ) ), $fichero( $PNG, 'botiquin.png' ) );
	$ok( is_int( $g4 ) && GastosTable::VALIDADO === GastosTable::obtener( $g4 )['estado'] && GastosTable::obtener( $g4 )['con_fondo'], 'el gasto que apunta Tesorería queda validado; este, con cargo al fondo de emergencia' );
	$g5 = Cuentas::proponer( $e, $como( $u_teso ), $gasto( array( 'concepto' => 'Duplicado', 'importe' => '3' ) ), $fichero( $PNG, 'dup.png' ) );
	$ok( is_wp_error( Cuentas::anular( $e, $g5, '' ) ) && true === Cuentas::anular( $e, $g5, 'Apuntado dos veces' ) && ! GastosTable::vale( GastosTable::obtener( $g5 ) ), 'Tesorería anula un gasto validado, siempre con motivo' );
	$i1 = Cuentas::ingreso( $e, array( 'fecha' => $hoy, 'importe' => '30', 'forma' => 'transferencia', 'concepto' => 'Subvención del ayuntamiento', 'partida' => (string) $partidas['Subvención'] ) );
	$ok( is_int( $i1 ) && GastosTable::INGRESO === GastosTable::obtener( $i1 )['tipo'] && '' === GastosTable::obtener( $i1 )['justificante'], 'Tesorería apunta un ingreso real (sin justificante: P-158) en su partida' );
	$ok( is_wp_error( Cuentas::ingreso( $e, array() ) ) && is_wp_error( ( static function () use ( $e, $como, $u_lob ) {
		$como( $u_lob );
		return Cuentas::ingreso( $e, array( 'fecha' => current_time( 'Y-m-d' ), 'importe' => '10', 'forma' => 'efectivo', 'concepto' => 'x' ) );
	} )() ), 'un ingreso sin datos no se guarda; quien gestiona no apunta ingresos' );

	echo "\n== Parte E · Anticipos de caja ==\n";
	$como( $u_teso );
	$ok( is_wp_error( Cuentas::anticipo( $e, $u_esc, 50, $hoy, 'efectivo', '' ) ), 'un anticipo solo a alguien del equipo del evento con cuenta' );
	$an1 = Cuentas::anticipo( $e, $u_mon, 50, $hoy, 'efectivo', 'Para la compra del sábado' );
	$ok( is_int( $an1 ) && AnticiposTable::abierto( AnticiposTable::obtener( $an1 ) ) && 1 === Cuentas::balance( $ev( $id_e ) )['anticipos']['n'], 'Tesorería da un anticipo de 50 € a un monitor del equipo' );
	$como( $u_mon );
	$g6 = Cuentas::proponer( $e, Ambito::actual(), $gasto( array( 'concepto' => 'Compra del sábado', 'importe' => '35', 'pagado_por' => GastosTable::PAGO_ANTICIPO, 'anticipo' => (string) $an1 ) ), $fichero( $PNG, 'sabado.png' ) );
	$ok( is_int( $g6 ) && $an1 === GastosTable::obtener( $g6 )['anticipo_id'], 'el monitor propone el gasto pagado con el anticipo' );
	$como( $u_teso );
	$r = Cuentas::liquidar( $e, $an1, 10, '' );
	$ok( is_wp_error( $r ) && str_contains( $r->get_error_message(), 'por revisar' ), 'no se liquida un anticipo con gastos por revisar' );
	Cuentas::validar( $e, $g6 );
	$r = Cuentas::liquidar( $e, $an1, 10, '' );
	$ok( is_wp_error( $r ) && str_contains( $r->get_error_message(), 'no suman' ), 'gastos (35 €) + devuelto (10 €) ≠ anticipo (50 €): pide una nota con el motivo (P-159)' );
	$r = Cuentas::liquidar( $e, $an1, 10, 'Faltan 5 € de un tique perdido' );
	$a1 = AnticiposTable::obtener( $an1 );
	$ok( 5.0 === $r && AnticiposTable::LIQUIDADO === $a1['estado'] && 10.0 === $a1['devuelto'] && str_contains( $a1['liquidacion_nota'], 'tique perdido' ), 'con la nota se liquida y se devuelve la diferencia (5 €)' );
	$an2 = Cuentas::anticipo( $e, $u_lob, 20, $hoy, 'efectivo', '' );
	$ok( true === Cuentas::anular_anticipo( $e, $an2 ) && ! AnticiposTable::abierto( AnticiposTable::obtener( $an2 ) ) && 0 === Cuentas::balance( $ev( $id_e ) )['anticipos']['n'], 'un anticipo dado por error (sin gastos) se anula' );

	echo "\n== Parte F · Devoluciones a las familias ==\n";
	$como( $u_teso );
	$f_leo = $fila( $id_e, '990081' );
	Cobros::pagar( $e, $f_leo['id'], $precio_leo, 'efectivo', $hoy, '', '' );
	$ok( 0.0 === $cobro( $id_e, '990081' )['pendiente'], 'Leo lo tiene pagado' );
	$r = Cobros::devolver( $e, $f_leo['id'], $precio_leo + 1, 'transferencia', $hoy, 'Baja por enfermedad' );
	$ok( is_wp_error( $r ) && str_contains( $r->get_error_message(), 'más de lo pagado' ), 'no se devuelve más de lo pagado (P-160)' );
	$ok( is_wp_error( Cobros::devolver( $e, $f_leo['id'], 5, 'transferencia', $hoy, '' ) ), 'la devolución pide el motivo' );
	$ok( is_wp_error( Cobros::devolver( $e, $f_leo['id'], 5, 'domiciliacion', $hoy, 'x' ) ), 'la forma «Domiciliación SEPA» no se elige a mano' );
	$h = $enviar( new EventosView(), array( 'asde_action' => 'evento_devolucion', 'evento' => (string) $id_e, 'pestana' => 'inscripciones', 'fila' => (string) $f_leo['id'], 'importe' => '5,00', 'forma' => 'bizum', 'fecha' => $hoy, 'motivo' => 'No hizo la excursión del domingo' ), 'asde_evento_' . $id_e );
	$c = $cobro( $id_e, '990081' );
	$ok( str_contains( $h, 'Devolución de 5,00' ) && 5.0 === $c['devuelto'] && round( $precio_leo - 5, 2 ) === $c['neto'] && 5.0 === $c['pendiente'], 'Tesorería anota la devolución desde «Inscripciones»: neto pagado = precio − 5 € y vuelven a quedar 5 € pendientes' );
	$como( $u_lob );
	$ok( is_wp_error( Cobros::devolver( $e, $f_leo['id'], 1, 'bizum', $hoy, 'x' ) ), 'quien gestiona el evento no hace devoluciones (solo Tesorería)' );
	$como( $u_teso );
	$h = $pintar( new EventosView(), array( 'evento' => (string) $id_e, 'pestana' => 'inscripciones' ) );
	$ok( str_contains( $h, 'devolución de 5,00' ) && str_contains( $h, 'Devolver a la familia' ), 'la inscripción enseña la devolución y Tesorería tiene «Devolver a la familia»' );

	echo "\n== Parte G · Remesa SEPA del evento ==\n";
	$f1 = FamiliasTable::crear( array( '990081', '990082' ) );
	$f3 = FamiliasTable::crear( array( '990083' ) );
	echo "\n== Parte G0 · Pago de un evento desde «Registrar pago» y el historial de la familia (v0.37) ==\n";
	$como( $u_teso );
	$fam1  = FamiliasTable::obtener( $f1 );
	$ronda_t = \AsdeBalears\AreaPrivada\Tesoreria\Config::ronda_actual();
	$pend0 = round( $cobro( $id_e, '990081' )['pendiente'] + $cobro( $id_e, '990082' )['pendiente'], 2 );
	$para  = HistorialPagos::eventos_para_cobrar( $fam1 );
	$ok( 1 === count( $para ) && $id_e === $para[0]['evento']['id'] && abs( $para[0]['pendiente'] - $pend0 ) < 0.001, '«Registrar pago» ofrece el evento con la inscripción abierta y lo pendiente de la familia' );
	$h = $pintar( new TesoreriaView(), array( 'familia' => (string) $f1 ) );
	$ok( str_contains( $h, 'name="evento"' ) && str_contains( $h, 'Acampada de cuentas' ) && str_contains( $h, 'Historial de pagos' ), 'la ficha de la familia enseña el selector de evento y el historial de pagos' );
	$ok( str_contains( $h, 'Devolución de 5,00' ), 'el historial de pagos ya trae lo del evento (la devolución de antes)' );
	$pago = array( 'asde_action' => 'registrar_pago', 'familia' => (string) $f1, 'ronda' => $ronda_t, 'evento' => (string) $id_e, 'fecha' => $hoy, 'importe' => '10', 'metodo' => 'efectivo', 'referencia' => 'PRUEBA-V037', 'nota' => '' );
	$av   = $enviar( new TesoreriaView(), array( 'metodo' => 'tarjeta' ) + $pago, 'asde_mov_' . $f1 );
	$ok( str_contains( $av, 'efectivo, transferencia o Bizum' ) && abs( round( $cobro( $id_e, '990081' )['pendiente'] + $cobro( $id_e, '990082' )['pendiente'], 2 ) - $pend0 ) < 0.001, 'para un evento solo vale efectivo, transferencia o Bizum' );
	$av = $enviar( new TesoreriaView(), array( 'banco' => '1' ) + $pago, 'asde_mov_' . $f1 );
	$ok( str_contains( $av, 'solo se asocia a la cuota' ), 'el ingreso del banco no se asocia a un evento' );
	$av = $enviar( new TesoreriaView(), array( 'importe' => (string) ( $pend0 + 50 ) ) + $pago, 'asde_mov_' . $f1 );
	$ok( str_contains( $av, 'más de lo que queda por pagar' ), 'no se paga más de lo pendiente del evento' );
	$av = $enviar( new TesoreriaView(), array( 'evento' => '999999' ) + $pago, 'asde_mov_' . $f1 );
	$ok( str_contains( $av, 'no está abierto a inscripciones' ), 'un evento que no existe o no está abierto, no' );
	$av = $enviar( new TesoreriaView(), $pago, 'asde_mov_' . $f1 );
	$ok( str_contains( $av, 'registrado en el evento' ) && abs( round( $cobro( $id_e, '990081' )['pendiente'] + $cobro( $id_e, '990082' )['pendiente'], 2 ) - ( $pend0 - 10 ) ) < 0.001, 'el pago se apunta al evento (reparte entre sus inscripciones) y baja lo pendiente' );
	$ok( ! \AsdeBalears\AreaPrivada\Tesoreria\MovimientosTable::de_familia( $f1, $ronda_t ), 'y no toca la cuota de la ronda' );
	$otros = HistorialPagos::otros( FamiliasTable::obtener( $f1 ) );
	$hay   = array_values( array_filter( $otros, static fn( $o ) => 'registrado' === $o['accion'] && str_contains( $o['que'], 'Pago de' ) && str_contains( $o['que'], 'Acampada de cuentas' ) && str_contains( $o['detalle'], 'PRUEBA-V037' ) && 'evento' === $o['modulo'] ) );
	$ok( $hay && $u_teso === $hay[0]['quien'], 'el historial de la familia incluye el pago del evento, con quién lo registró' );
	$h = $pintar( new TesoreriaView(), array( 'familia' => (string) $f1 ) );
	$ok( str_contains( $h, 'PRUEBA-V037' ) && str_contains( $h, 'evento «Acampada de cuentas»' ), 'y la ficha lo enseña' );
	// Se anula (queda en el historial como eliminado) y el evento vuelve a lo de antes.
	$mov = 0;
	foreach ( MovimientosTable::de_evento( $id_e ) as $lista ) {
		foreach ( $lista as $m ) {
			if ( 'PRUEBA-V037' === $m['referencia'] && null === $m['anulado_en'] ) {
				$mov = $m['id'];
			}
		}
	}
	$ok( $mov && true === Cobros::anular( $ev( $id_e ), $mov ), 'Tesorería anula ese pago' );
	$otros = HistorialPagos::otros( FamiliasTable::obtener( $f1 ) );
	$ok( count( array_filter( $otros, static fn( $o ) => 'eliminado' === $o['accion'] && str_contains( $o['que'], 'Acampada de cuentas' ) ) ) >= 1 && abs( round( $cobro( $id_e, '990081' )['pendiente'] + $cobro( $id_e, '990082' )['pendiente'], 2 ) - $pend0 ) < 0.001, 'lo anulado sale como eliminado en el historial y deja de contar' );
	$como( $u_lob );
	$ok( ! Cobros::puede(), 'quien gestiona el evento no apunta pagos (sin selector de evento en su ficha)' );
	$como( $u_teso );

	// v0.37.1 (P-167): Tesorería a secas ve informes de pagos de todo el grupo, pero solo identidad y pagos.
	$u_solo = $crear( 'prueba-eco-solo', array( Roles::TESORERIA ), array( Usuarios::META_SOCIO => '990086' ) );
	$a_solo = $como( $u_solo );
	$perm   = \AsdeBalears\AreaPrivada\Informes\Catalogo::permitidas( $a_solo );
	$ok( user_can( $u_solo, 'asde_reports' ) && \AsdeBalears\AreaPrivada\Informes\Catalogo::solo_tesoreria( $a_solo ) && isset( $perm['pago_pendiente'], $perm['nombre'] ) && ! isset( $perm['email'], $perm['movil'], $perm['tutor1_movil'] ), 'Tesorería a secas: tiene informes, de todo el grupo, con identidad y pagos y sin contactos ni salud' );
	$como( $u_teso );
	$bin = Xlsx::generar(
		'Control de pagos',
		array( 'Nº familia', 'Socios', 'Pagado', 'Estado' ),
		array( array( 'F1', 'Ana, Leo', 12.5, 'Pagada' ), array( 'F2', 'Bea', 0.0, 'Pendiente' ) ),
		array(
			'titulo'    => 'Control de pagos · Prueba',
			'subtitulo' => 'Ronda 2026-2027',
			'tipos'     => array( 'texto', 'texto', 'euros', 'estado' ),
			'colores'   => array( 'Pagada' => 'verde', 'Pendiente' => 'ambar' ),
			'totales'   => array( 'Total', '2 familias', 12.5, '' ),
		)
	);
	$zip = new ZipArchive();
	$tmp = wp_tempnam( 'xlsx-v037' );
	file_put_contents( $tmp, $bin ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	$abre = true === $zip->open( $tmp );
	$hoja = $abre ? (string) $zip->getFromName( 'xl/worksheets/sheet1.xml' ) : '';
	$est  = $abre ? (string) $zip->getFromName( 'xl/styles.xml' ) : '';
	$wb   = $abre ? (string) $zip->getFromName( 'xl/workbook.xml' ) : '';
	if ( $abre ) {
		$zip->close();
	}
	wp_delete_file( $tmp );
	$dom = new DOMDocument();
	$ok( $abre && $dom->loadXML( $hoja ) && $dom->loadXML( $est ) && $dom->loadXML( $wb ), 'Excel bonito: el .xlsx abre y su XML está bien formado' );
	$ok( str_contains( $hoja, '<mergeCells count="2">' ) && str_contains( $hoja, 'Control de pagos · Prueba' ) && str_contains( $hoja, 'r="A4"' ) && str_contains( $hoja, '<autoFilter ref="A4:D6"/>' ) && str_contains( $wb, '$A$4:$D$6' ), 'con título, subtítulo, la cabecera en la fila 4 y el filtro sobre los datos' );
	$ok( str_contains( $hoja, '<c r="C5" s="7"><v>12.5</v></c>' ) && str_contains( $hoja, '<c r="D5" t="inlineStr" s="11">' ) && str_contains( $hoja, '<c r="D6" t="inlineStr" s="12">' ) && str_contains( $hoja, '<c r="C7" s="16"><v>12.5</v></c>' ), 'importes en moneda, estados con su color y fila de totales' );
	$plano = Xlsx::generar( 'Hoja', array( 'A', 'B' ), array( array( 'x', '1' ) ) );
	$zip2  = new ZipArchive();
	$tmp2  = wp_tempnam( 'xlsx-v037b' );
	file_put_contents( $tmp2, $plano ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	$zip2->open( $tmp2 );
	$hoja2 = (string) $zip2->getFromName( 'xl/worksheets/sheet1.xml' );
	$zip2->close();
	wp_delete_file( $tmp2 );
	$ok( str_contains( $hoja2 , '<c r="A1" t="inlineStr" s="1">' ) && str_contains( $hoja2, '<autoFilter ref="A1:B2"/>' ) && ! str_contains( $hoja2, 'mergeCells' ), 'sin opciones, la hoja sencilla de los informes sigue igual' );

	SepaConfig::guardar(
		array(
			'activo'     => true,
			'nombre'     => 'Grupo Scout Prueba 999',
			'ics'        => 'ES26ZZZG12345678',
			'iban'       => 'ES7921000813610123456789',
			'bic'        => '',
			'calle'      => 'Calle Falsa 123',
			'cp'         => '07001',
			'poblacion'  => 'Palma',
			'provincia'  => 'Illes Balears',
			'dias_aviso' => 5,
			'concepto'   => 'Cuota {grupo} {ronda}',
		)
	);
	$como( $u_fam1 );
	$_POST    = array(
		'asde_action' => 'sepa_firmar',
		'familia'     => (string) $f1,
		'titular'     => 'María Eco Ruiz',
		'iban'        => 'ES66 2100 0418 4012 3456 7891',
		'calle'       => 'Calle Mayor 5',
		'cp'          => '07002',
		'poblacion'   => 'Palma',
		'provincia'   => 'Illes Balears',
		'pais'        => 'España',
		'acepto'      => '1',
		'_wpnonce'    => wp_create_nonce( 'asde_sepa_firmar_' . $f1 ),
	);
	$_REQUEST = $_POST;
	try {
		( new MisPagosView() )->handle_post();
	} catch ( RuntimeException $ex ) {
		// Redirige.
	} finally {
		$_POST    = array();
		$_REQUEST = array();
	}
	$ok( (bool) Mandatos::activo_de_familia( $f1 ), 'la familia de Leo y Nora firma su autorización SEPA' );
	$como( $u_teso );
	$repo = \AsdeBalears\AreaPrivada\Crm\PersonaRepository::make();
	$pend = round( $cobro( $id_e, '990081' )['pendiente'] + $cobro( $id_e, '990082' )['pendiente'], 2 );
	$prep = Remesas::preparar_evento( $repo, $ev( $id_e ) );
	$lin  = array_values( array_filter( $prep['lineas'], static fn( $l ) => $f1 === (int) $l['familia'] ) );
	$omit = array_values( array_filter( $prep['omitidas'], static fn( $o ) => str_contains( (string) $o['motivo'], 'Sin autorización' ) ) );
	$ok( 1 === count( $prep['lineas'] ) && $lin && (int) round( $pend * 100 ) === (int) $lin[0]['centimos'] && 1 === count( $omit ), 'vista previa: un adeudo para la familia con autorización, con todo lo pendiente de Leo y Nora juntos; la otra familia, fuera (sin autorización)' );
	$h = $pintar( new SepaView(), array( 'preparar_evento' => '1', 'evento_sepa' => (string) $id_e, 'fecha_evento' => Remesas::fecha_minima() ) );
	$ok( str_contains( $h, 'Cobrar un evento' ) && str_contains( $h, 'Acampada de cuentas' ) && str_contains( $h, 'sepa_generar_evento' ), 'en «Domiciliaciones», «Cobrar un evento» lista el evento y su vista previa con el botón de crear la remesa' );
	$h = $enviar( new SepaView(), array( 'asde_action' => 'sepa_generar_evento', 'evento' => (string) $id_e, 'fecha' => Remesas::fecha_minima(), 'concepto' => '' ), 'asde_sepa_generar_evento' );
	$rem = Remesas::obtener( $max_id( Remesas::table() ) );
	$ok( $rem && $rem['id'] > $rem_antes && $id_e === (int) $rem['evento_id'] && str_contains( $rem['concepto'], 'Acampada de cuentas' ) && str_contains( $rem['plazo'], 'Evento' ) && abs( $rem['total'] - $pend ) < 0.001, 'se crea la remesa del evento (con su evento, concepto por defecto y el total pendiente)' );
	$ok( isset( Remesas::familias_sin_cobrar_evento( $id_e )[ $f1 ] ) && ! isset( Remesas::familias_sin_cobrar( $rem['ronda'] )[ $f1 ] ), 'la familia queda «en una remesa del evento sin cobrar», sin afectar a las remesas de la cuota' );
	$prep2 = Remesas::preparar_evento( $repo, $ev( $id_e ) );
	$ok( ! $prep2['lineas'] && array_filter( $prep2['omitidas'], static fn( $o ) => str_contains( (string) $o['motivo'], 'Ya está en la remesa' ) ), 'no entra dos veces en una remesa del mismo evento sin cobrar' );
	$ok( in_array( 'Hay una remesa SEPA del evento sin registrar sus cobros.', Cuentas::por_cerrar( $ev( $id_e ) ), true ), 'con la remesa sin registrar, las cuentas no se pueden cerrar' );
	Remesas::marcar_enviada( (int) $rem['id'], $u_teso );
	// Doc 59, RC-EVE-2: entre la remesa y el registro de su cobro, la familia paga algo a mano (hasta 5 € de lo de Leo).
	$extra  = min( 5.0, $cobro( $id_e, '990081' )['pendiente'] );
	$manual = Cobros::pagar( $ev( $id_e ), (int) $fila( $id_e, '990081' )['id'], $extra, 'efectivo', $hoy, '', 'Pago a mano antes del cobro de la remesa' );
	$res    = Remesas::registrar_cobros( (int) $rem['id'], array( $f1 ), array(), $hoy, $u_teso );
	$ok( $extra > 0 && is_int( $manual ) && 1 === $res['cobrados'] && ! $res['errores'] && 1 === count( $res['avisos'] ) && str_contains( $res['avisos'][0], \AsdeBalears\AreaPrivada\Tesoreria\Calculadora::euros( $extra ) ) && str_contains( $res['avisos'][0], 'saldo a devolver' ), 'si la familia pagó algo a mano entretanto, el cobro del banco se apunta igualmente (ya está cobrado), con un aviso para Tesorería (RC-EVE-2)' );
	$movs = array_filter( MovimientosTable::de_evento( $id_e )[ $fila( $id_e, '990082' )['id'] ] ?? array(), static fn( $m ) => 'domiciliacion' === $m['forma'] );
	$ok( 0.0 === $cobro( $id_e, '990081' )['pendiente'] && 0.0 === $cobro( $id_e, '990082' )['pendiente'] && $movs, 'al registrar los cobros, el importe se reparte entre Leo y Nora como pagos «Domiciliación SEPA»: nada pendiente' );
	$nora = $cobro( $id_e, '990082' );
	$ok( abs( $nora['neto'] - $nora['total'] - $extra ) < 0.001, 'lo cobrado de más queda en la última inscripción (Nora) como saldo a devolver' );
	$ok( is_int( Cobros::devolver( $ev( $id_e ), (int) $fila( $id_e, '990082' )['id'], $extra, 'transferencia', $hoy, 'Cobrado de más por la remesa' ) ), 'y Tesorería se lo devuelve' );
	$ok( ! Remesas::familias_sin_cobrar_evento( $id_e ), 'cobrada: ya no queda nada «sin cobrar» del evento' );
	$como( $u_fam1 );
	$h = $pintar( new MisPagosView() );
	$ok( str_contains( $h, 'devolución' ) && str_contains( $h, 'Domiciliación SEPA' ), '«Mis pagos» de la familia enseña la devolución y los cobros por domiciliación' );

	echo "\n== Parte H · Fondo, balance y cierre ==\n";
	$como( $u_teso );
	$b = Cuentas::balance( $ev( $id_e ) );
	$ok( 8.0 === $b['fondo']['usado'] && $b['fondo']['recaudado'] >= 0 && round( $b['fondo']['recaudado'] - 8, 2 ) === $b['fondo']['disponible'], 'fondo de emergencia: usado 8 € (el botiquín) y lo que queda' );
	$ok( 83.5 === $b['gastos'] && 30.0 === $b['ingresos'] && round( $b['cobros']['neto'] + 30 - 83.5, 2 ) === $b['resultado'], 'balance: gastos 83,50 € (comida, botiquín y compra con el anticipo), ingresos 30 € y resultado = cobrado neto + ingresos − gastos' );
	$linea = array_values( array_filter( $b['lineas'], static fn( $l ) => 'Comida' === $l['concepto'] ) )[0] ?? null;
	$ok( $linea && 40.5 === $linea['real'] && $linea['previsto'] > 0, 'cada partida, con lo previsto y lo real (Comida: 40,50 €)' );
	$csv = Cuentas::filas_csv( $b );
	$ok( in_array( 'Resultado (lo asume el grupo)', array_column( $csv, 0 ), true ) && in_array( 'Comida', array_column( $csv, 0 ), true ), 'el informe en CSV lleva las partidas y el resultado' );
	$faltan = Cuentas::por_cerrar( $ev( $id_e ) );
	$ok( in_array( 'El evento todavía no ha terminado.', $faltan, true ), 'no se cierra antes de que termine el evento' );
	// El evento pasa a haber terminado.
	$wpdb->update( EventosTable::table(), array( 'inicio' => $dia( '-3 days' ) . ' 10:00:00', 'fin' => $dia( '-2 days' ) . ' 17:00:00' ), array( 'id' => $id_e ) ); // phpcs:ignore WordPress.DB
	$como( $u_lob );
	$g7 = Cuentas::proponer( $ev( $id_e ), Ambito::actual(), $gasto( array( 'concepto' => 'Tique de última hora', 'importe' => '2' ) ), $fichero( $PNG, 'ultima.png' ) );
	$como( $u_teso );
	$ok( in_array( 'Hay 1 gasto(s) por revisar.', Cuentas::por_cerrar( $ev( $id_e ) ), true ), 'con un gasto por revisar, todavía no' );
	Cuentas::rechazar( $ev( $id_e ), $g7, 'Sin relación con el evento' );
	$ok( array() === Cuentas::por_cerrar( $ev( $id_e ) ), 'terminado, todo revisado, reembolsado, liquidado y cobrado por SEPA: se puede cerrar' );
	$como( $u_lob );
	$r = Cuentas::cerrar( $ev( $id_e ) );
	$ok( is_wp_error( $r ) && null === $ev( $id_e )['cerrado_en'], 'quien gestiona el evento no cierra las cuentas' );
	$como( $u_teso );
	$h = $enviar( new EventosView(), array( 'asde_action' => 'evento_cerrar', 'evento' => (string) $id_e, 'pestana' => 'cuentas' ), 'asde_evento_' . $id_e );
	$e = $ev( $id_e );
	$datos = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . InscripcionesTable::table() . ' WHERE evento_id = %d AND datos_enc IS NOT NULL', $id_e ) ); // phpcs:ignore WordPress.DB
	$ok( str_contains( $h, 'cerradas' ) && null !== $e['cerrado_en'] && $u_teso === $e['cerrado_por'] && is_array( $e['balance'] ) && $b['resultado'] === $e['balance']['resultado'] && 'cerrado' === EventosTable::estado_visible( $e ), 'Tesorería cierra: se guarda el balance como informe final y el evento sale como «Cuentas cerradas»' );
	$ok( 0 === $datos, 'al cerrar se borran quién recoge y las observaciones de todas las inscripciones (P-150)' );
	$ok( is_file( $f_g1 ), 'y el justificante del gasto válido se conserva también con las cuentas cerradas (RC-DEC-3)' );

	echo "\n== Parte I · Cerrado: solo lectura ==\n";
	$ok( is_wp_error( Cuentas::proponer( $e, $como( $u_teso ), $gasto(), $fichero( $PNG, 'tarde.png' ) ) ) && is_wp_error( Cobros::pagar( $e, $f_leo['id'], 1, 'efectivo', $hoy, '', '' ) ) && is_wp_error( Cobros::devolver( $e, $f_leo['id'], 1, 'efectivo', $hoy, 'x' ) ) && is_wp_error( Cuentas::anticipo( $e, $u_mon, 5, $hoy, 'efectivo', '' ) ), 'con las cuentas cerradas no se apuntan gastos, pagos, devoluciones ni anticipos' );
	$ok( is_wp_error( Cuentas::cerrar( $e ) ), 'no se cierra dos veces' );
	$a_lob = $como( $u_lob );
	$ok( ! Servicio::puede_editar( $e, $a_lob ), 'ya no se pueden editar los datos del evento' );
	$antes = $e['nombre'];
	$h     = $enviar( new EventosView(), array( 'asde_action' => 'evento_equipo_quitar', 'evento' => (string) $id_e, 'pestana' => 'equipo', 'fila' => '1' ), 'asde_evento_' . $id_e );
	$ok( str_contains( $h, 'cerradas' ) && $antes === $ev( $id_e )['nombre'], 'cualquier acción sobre el evento cerrado se rechaza en la pantalla' );
	$como( $u_teso );
	$h = $pintar( new EventosView(), array( 'evento' => (string) $id_e, 'pestana' => 'cuentas' ) );
	$ok( str_contains( $h, 'Informe final' ) && str_contains( $h, 'Cuentas cerradas el' ) && ! str_contains( $h, 'Apuntar un gasto' ) && ! str_contains( $h, 'name="asde_action" value="evento_cerrar"' ) && str_contains( $h, 'Ver justificante' ) && str_contains( $h, 'Descargar CSV' ), 'la pestaña enseña el informe final, sin formularios para cambiar nada; se siguen viendo los justificantes y el CSV' );
	$h = $pintar( new SepaView() );
	$ok( ! str_contains( $h, 'Acampada de cuentas' ), 'un evento cerrado ya no sale en «Cobrar un evento»' );
	$h = $enviar( new EventosView(), array( 'asde_action' => 'evento_justificante', 'evento' => (string) $id_e, 'pestana' => 'cuentas', 'gasto' => '999999' ), 'asde_evento_' . $id_e );
	$ok( str_contains( $h, 'no existe o no puedes verlo' ), 'pedir un justificante que no es de este evento: no se envía nada' );

	echo "\n== Parte I bis · Reabrir las cuentas (v0.36.1, P-162) ==\n";
	$como( $u_teso );
	$h = $pintar( new EventosView(), array( 'evento' => (string) $id_e, 'pestana' => 'cuentas' ) );
	$ok( str_contains( $h, 'value="evento_reabrir_cuentas"' ) && str_contains( $h, 'Tesorería tiene que reabrirlas' ), 'con las cuentas cerradas, Tesorería tiene «Reabrir las cuentas»' );
	$como( $u_lob );
	$h = $pintar( new EventosView(), array( 'evento' => (string) $id_e, 'pestana' => 'cuentas' ) );
	$ok( ! str_contains( $h, 'value="evento_reabrir_cuentas"' ) && is_wp_error( Cuentas::reabrir( $ev( $id_e ), 'Falta un tique' ) ) && null !== $ev( $id_e )['cerrado_en'], 'quien gestiona el evento no las reabre (ni ve el botón)' );
	$como( $u_teso );
	$r = Cuentas::reabrir( $ev( $id_e ), '' );
	$ok( is_wp_error( $r ) && str_contains( $r->get_error_message(), 'motivo' ) && null !== $ev( $id_e )['cerrado_en'], 'reabrir pide el motivo' );
	$h = $enviar( new EventosView(), array( 'asde_action' => 'evento_reabrir_cuentas', 'evento' => (string) $id_e, 'pestana' => 'cuentas', 'motivo' => 'Ha llegado una factura tarde' ), 'asde_evento_' . $id_e );
	$e = $ev( $id_e );
	$reg = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT detalles FROM ' . Logger::table() . " WHERE id > %d AND accion = 'evento_cuentas_reabiertas' AND objeto_id = %s ORDER BY id DESC LIMIT 1", $log_antes, (string) $id_e ) ); // phpcs:ignore WordPress.DB
	$ok( str_contains( $h, 'reabiertas' ) && null === $e['cerrado_en'] && 'cerrado' !== EventosTable::estado_visible( $e ) && is_array( $e['balance'] ) && str_contains( $reg, 'factura tarde' ), 'Tesorería las reabre desde la pestaña: el evento vuelve a poder cambiarse, el último informe se conserva y el motivo queda en el registro' );
	$ok( is_wp_error( Cuentas::reabrir( $e, 'otra vez' ) ), 'unas cuentas abiertas no se «reabren»' );
	$g8    = Cuentas::proponer( $e, Ambito::actual(), $gasto( array( 'concepto' => 'Factura tardía', 'importe' => '6' ) ), $fichero( $PNG, 'factura-tarde.png' ) );
	$datos = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . InscripcionesTable::table() . ' WHERE evento_id = %d AND datos_enc IS NOT NULL', $id_e ) ); // phpcs:ignore WordPress.DB
	$ok( is_int( $g8 ) && 0 === $datos, 'reabiertas, se puede apuntar la factura que faltaba; quién recoge y las observaciones no vuelven' );
	$b2 = Cuentas::cerrar( $ev( $id_e ) );
	$e  = $ev( $id_e );
	$ok( is_array( $b2 ) && null !== $e['cerrado_en'] && round( $b['gastos'] + 6, 2 ) === $e['balance']['gastos'], 'se vuelven a cerrar con el informe al día (con la factura nueva)' );

	echo "\n== Parte J · Seguridad ==\n";
	$cols = array_keys( (array) $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . GastosTable::table() . ' WHERE id > %d LIMIT 1', $gas_antes ), ARRAY_A ) ); // phpcs:ignore WordPress.DB
	$ok( $cols && ! array_intersect( $cols, array( 'dni', 'email', 'telefono', 'iban', 'ip' ) ), 'la tabla de gastos no guarda datos personales (solo quién, por su cuenta de la web)' );
	$ok( $crm->llamadas > 0 && 0 === $cortadas, "todas las consultas al CRM las ha respondido el simulador ({$crm->llamadas}); ninguna ha salido hacia el CRM real" );
} catch ( Throwable $e ) {
	++$fails;
	echo 'FAIL excepcion: ', $e->getMessage(), ' (', basename( $e->getFile() ), ':', $e->getLine(), ")\n";
} finally {
	// ---------------------------------------------------------------------------
	// Limpieza: todo lo creado fuera; lo tocado, como estaba.
	remove_filter( 'wp_redirect', $redirigir, 1 );
	remove_filter( 'send_auth_cookies', '__return_false' );
	remove_filter( 'pre_wp_mail', $capturar, 5 );
	remove_filter( 'asde_ap_instantanea_segundos', '__return_zero' );
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

	// Justificantes de la prueba (y la carpeta privada, si la ha creado la prueba).
	$dir = Justificantes::carpeta();
	foreach ( (array) $wpdb->get_col( $wpdb->prepare( 'SELECT justificante FROM ' . GastosTable::table() . " WHERE id > %d AND justificante <> ''", $gas_antes ) ) as $j ) { // phpcs:ignore WordPress.DB
		if ( preg_match( '/^[0-9a-f]{32}\.bin$/', (string) $j ) && '' !== $dir ) {
			wp_delete_file( $dir . '/' . $j );
		}
	}
	if ( ! $raiz_existia && is_dir( $raiz_priv ) ) {
		foreach ( array( $dir, $raiz_priv ) as $d ) {
			foreach ( array( '.htaccess', 'web.config', 'index.php' ) as $n ) {
				wp_delete_file( $d . '/' . $n );
			}
			@rmdir( $d ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions
		}
	}
	foreach ( $temporales as $t ) {
		wp_delete_file( $t );
	}

	$creados = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . EventosTable::table() . ' WHERE id > %d', $ev_antes ) ) ); // phpcs:ignore WordPress.DB
	foreach ( $creados as $eid ) {
		foreach ( array( 'publicado', 'cancelado' ) as $tipo ) {
			wp_clear_scheduled_hook( Avisos::HOOK, array( $grupo['numero'], $eid, $tipo ) );
			wp_unschedule_hook( Avisos::HOOK ); // v0.52: el aviso de publicar lleva también los destinatarios.
		}
	}
	$lista = implode( ',', array_map( 'intval', $ids ) ) ?: '0';
	foreach ( array( EventosTable::table() => $ev_antes, InscripcionesTable::table() => $ins_antes, PartidasTable::table() => $par_antes, MovimientosTable::table() => $mov_antes, GastosTable::table() => $gas_antes, AnticiposTable::table() => $ant_antes, Mandatos::table() => $man_antes, Remesas::table() => $rem_antes ) as $tabla => $antes_id ) {
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id > %d', $tabla, $antes_id ) ); // phpcs:ignore WordPress.DB
	}
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . RegistroEmails::table() . " WHERE id > %d AND user_id IN ($lista)", $emails_antes ) ); // phpcs:ignore WordPress.DB
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Logger::table() . " WHERE id > %d AND ( user_id IN ($lista) OR objeto IN ( 'evento', 'remesa', 'familia', 'mandato' ) )", $log_antes ) ); // phpcs:ignore WordPress.DB
	$familias = implode( ',', array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . FamiliasTable::table() . ' WHERE id > %d', $fam_antes ) ) ) ) ?: '0'; // phpcs:ignore WordPress.DB
	$wpdb->query( 'DELETE FROM ' . FamiliasTable::table_miembros() . " WHERE familia_id IN ($familias)" ); // phpcs:ignore WordPress.DB
	$wpdb->query( 'DELETE FROM ' . FamiliasTable::table() . " WHERE id IN ($familias)" ); // phpcs:ignore WordPress.DB
	foreach ( $ids as $uid ) {
		delete_transient( 'asde_ap_notices_' . $uid );
		Ambito::olvidar( (int) $uid );
		wp_delete_user( (int) $uid );
	}
	foreach ( array( $opcion_cfg => $cfg_antes, $op_sepa => $sepa_antes, 'asde_ap_modulos' => $modulos_antes ) as $op => $valor ) {
		null === $valor ? delete_option( $op ) : update_option( $op, $valor, false );
	}
	Modulos::olvidar();
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

	$quedan = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users} WHERE user_login LIKE 'prueba-eco-%'" ); // phpcs:ignore WordPress.DB
	$ok( 0 === $quedan && $ev_antes === $max_id( EventosTable::table() ) && $gas_antes === $max_id( GastosTable::table() ) && $ant_antes === $max_id( AnticiposTable::table() ) && $rem_antes === $max_id( Remesas::table() ) && $man_antes === $max_id( Mandatos::table() ) && $fam_antes === $max_id( FamiliasTable::table() ) && get_option( $op_sepa, null ) == $sepa_antes && ( $raiz_existia || ! is_dir( $raiz_priv ) ), 'limpieza: sin usuarios, eventos, gastos, anticipos, justificantes, remesas, autorizaciones ni familias de la prueba; opciones como estaban' ); // phpcs:ignore WordPress.DB
}

echo $fails ? "\n$fails FALLOS\n" : "\nTODO OK (nada tocado en el CRM real; el sitio queda como estaba)\n";
