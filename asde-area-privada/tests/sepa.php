<?php
/**
 * Pruebas de la v0.12 (entorno LOCAL): domiciliación SEPA. Autorización de las
 * familias en «Mis pagos» y remesas de adeudos (fichero pain.008) en Tesorería.
 * Ejecutar desde app/public del site Local:
 *
 *   wp eval-file <ruta completa>/wp-content/plugins/asde-area-privada/tests/sepa.php
 *
 * Usa el CRM simulado de tests/lib (fichas inventadas: ninguna petición llega al
 * CRM real) y guarda las cachés solo en la memoria de este proceso. Los pagos van
 * a una ronda ficticia (2098-2099) con sus propias cuotas y el acreedor es
 * ficticio. Ningún email sale (pre_wp_mail). Al terminar se borra lo creado
 * (usuarios, familias, autorizaciones, remesas, pagos, registros) y se restauran
 * la configuración SEPA del grupo y la de Tesorería.
 */
defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/lib/CrmSimulado.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Auth\Ambito;
use AsdeBalears\AreaPrivada\Crm\Crypto;
use AsdeBalears\AreaPrivada\Crm\PersonaRepository;
use AsdeBalears\AreaPrivada\Domain\Grupo;
use AsdeBalears\AreaPrivada\Domain\Grupos;
use AsdeBalears\AreaPrivada\Frontend\Pages;
use AsdeBalears\AreaPrivada\Frontend\Router;
use AsdeBalears\AreaPrivada\Frontend\Views\MisPagosView;
use AsdeBalears\AreaPrivada\Domain\Modulos;
use AsdeBalears\AreaPrivada\Frontend\Views\InformesView;
use AsdeBalears\AreaPrivada\Frontend\Views\SepaView;
use AsdeBalears\AreaPrivada\Informes\Catalogo;
use AsdeBalears\AreaPrivada\Informes\Generador;
use AsdeBalears\AreaPrivada\Informes\Informe;
use AsdeBalears\AreaPrivada\Informes\InformesTable;
use AsdeBalears\AreaPrivada\Frontend\Views\TesoreriaBaseView;
use AsdeBalears\AreaPrivada\Install\Roles;
use AsdeBalears\AreaPrivada\Sepa\Avisos;
use AsdeBalears\AreaPrivada\Sepa\Config as SepaConfig;
use AsdeBalears\AreaPrivada\Sepa\Iban;
use AsdeBalears\AreaPrivada\Sepa\Mandatos;
use AsdeBalears\AreaPrivada\Sepa\Pain008;
use AsdeBalears\AreaPrivada\Sepa\Remesas;
use AsdeBalears\AreaPrivada\Sepa\Texto;
use AsdeBalears\AreaPrivada\Socios\SociosTable;
use AsdeBalears\AreaPrivada\Tesoreria\Calculadora;
use AsdeBalears\AreaPrivada\Tesoreria\Config as TesoreriaConfig;
use AsdeBalears\AreaPrivada\Tesoreria\FamiliasTable;
use AsdeBalears\AreaPrivada\Tesoreria\MovimientosTable;
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
foreach ( get_users( array( 'search' => 'prueba-sepa-*', 'search_columns' => array( 'user_login' ) ) ) as $u ) {
	exit( 'Ya existe el usuario ' . $u->user_login . " (¿una ejecución anterior interrumpida?). Bórralo antes de repetir la prueba.\n" );
}
wp_set_current_user( 0 );
$_SERVER['REMOTE_ADDR'] = '192.0.2.77';

// --- Lo que se restaura al final ---------------------------------------------
$max_id       = static fn( string $t ) => (int) $wpdb->get_var( "SELECT COALESCE(MAX(id), 0) FROM $t" ); // phpcs:ignore WordPress.DB
$log_antes    = $max_id( Logger::table() );
$fam_antes    = $max_id( FamiliasTable::table() );
$mov_antes    = $max_id( MovimientosTable::table() );
$man_antes    = $max_id( Mandatos::table() );
$rem_antes    = $max_id( Remesas::table() );
$emails_antes = $max_id( RegistroEmails::table() );
$socios_antes = array_column( (array) $wpdb->get_results( 'SELECT * FROM ' . SociosTable::table(), ARRAY_A ), null, 'num_socio_asde' ); // phpcs:ignore WordPress.DB
$op_sepa      = 'asde_ap_sepa_' . $grupo['numero'];
$sepa_antes   = get_option( $op_sepa, null );
$tes_antes    = get_option( 'asde_ap_tesoreria', null );

$cache_externa = wp_using_ext_object_cache( true );
$capturados    = array();
$capturar      = static function ( $pre, $atts ) use ( &$capturados ) {
	$capturados[] = $atts;
	return true;
};
add_filter( 'pre_wp_mail', $capturar, 5, 2 );
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
		$P( 31, 'Leo', 'Sepa Sanz', '2016-05-10', 'Lobatos' ),
		$P( 32, 'Nora', 'Sepa Sanz', '2014-02-20', 'Scouts' ),
		$P( 33, 'Pol', 'Sepa Mas', '2011-03-03', 'Escultas' ),
		$P( 34, 'Rex', 'Sepa Gil', '2005-01-01', 'Rovers' ),
		$P( 35, 'Eva', 'Sepa Tesorera', '1990-01-01', 'Scouters' ),
		$P( 36, 'Ana', 'Sepa Coll', '1980-08-08', 'Scouters' ),
		$P( 37, 'Iris', 'Sepa Roca', '2012-07-07', 'Scouts' ),
	),
	$grupo['crm_id']
);
$falsos = array_map( static fn( $p ) => $p['numerosocio_c'], $crm->personas );
Grupo::forzar( $grupo );
$crm->activar();

$ids   = array();
$crear = static function ( string $login, array $roles, array $meta, ?string $email = null ) use ( &$ids, $grupo ): int {
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
	if ( $email ) {
		EmailCuenta::guardar( $id, $email );
	}
	$ids[ $login ] = $id;
	return $id;
};
$u_fam    = $crear( 'prueba-sepa-fam', array( Roles::TUTOR ), array( Usuarios::META_REPRESENTADO => array( '990031', '990032' ) ), 'madre.sepa@example.invalid' );
$u_fam2   = $crear( 'prueba-sepa-fam2', array( Roles::TUTOR ), array( Usuarios::META_REPRESENTADO => array( '990031', '990032' ) ), 'padre.sepa@example.invalid' );
$u_pol    = $crear( 'prueba-sepa-pol', array( Roles::PARTICIPANTE ), array( Usuarios::META_SOCIO => '990033' ), 'pol.sepa@example.invalid' );
$u_rex    = $crear( 'prueba-sepa-rex', array( Roles::PARTICIPANTE ), array( Usuarios::META_SOCIO => '990034' ), 'rex.sepa@example.invalid' );
$u_teso   = $crear( 'prueba-sepa-teso', array( Roles::SCOUTER, Roles::TESORERIA ), array( Usuarios::META_SOCIO => '990035', Usuarios::META_SECCION => 'rovers' ), 'teso.sepa@example.invalid' );
$u_scout  = $crear( 'prueba-sepa-scouter', array( Roles::SCOUTER ), array( Usuarios::META_SOCIO => '990036', Usuarios::META_SECCION => 'lobatos' ), 'scouter.sepa@example.invalid' );
$u_otra   = $crear( 'prueba-sepa-otra', array( Roles::TUTOR ), array( Usuarios::META_REPRESENTADO => '990037' ), 'otra.sepa@example.invalid' );

// Familias de Tesorería y una ronda ficticia con sus cuotas.
$f1 = FamiliasTable::crear( array( '990031', '990032' ) ); // Leo y Nora.
$f2 = FamiliasTable::crear( array( '990033' ) );           // Pol (menor con cuenta propia).
$f3 = FamiliasTable::crear( array( '990034' ) );           // Rex (mayor de edad).
$f4 = FamiliasTable::crear( array( '990037' ) );           // Iris (sin autorización).
$ronda = '2098-2099';
TesoreriaConfig::guardar(
	$ronda,
	array(
		'cuotas' => array(
			'inscripcion' => 20,
			'seguro'      => 10,
			'anualidad'   => array( 110, 198, 242 ),
		),
		'plazos' => array(
			array( 'nombre' => 'Primer plazo', 'fecha' => '2098-10-31', 'porcentaje' => 50 ),
			array( 'nombre' => 'Segundo plazo', 'fecha' => '2099-02-28', 'porcentaje' => 50 ),
		),
	)
);

// Acreedor ficticio (ICS con sus cifras de control correctas).
$ICS_OK = 'ES26ZZZG12345678';
$IBAN_A = 'ES7921000813610123456789'; // Acreedor.
$IBAN_1 = 'ES6621000418401234567891'; // Familia 1.
$IBAN_3 = 'ES9121000418450200051332'; // Rex.
$cfg_ok = array(
	'activo'     => true,
	'nombre'     => 'Grupo Scout Prueba 999',
	'ics'        => $ICS_OK,
	'iban'       => $IBAN_A,
	'bic'        => '',
	'calle'      => 'Calle Falsa 123',
	'cp'         => '07001',
	'poblacion'  => 'Palma',
	'provincia'  => 'Illes Balears',
	'dias_aviso' => 5,
	'concepto'   => 'Cuota {grupo} {ronda}',
);

$como = static function ( int $uid ): Ambito {
	wp_set_current_user( $uid );
	Ambito::olvidar( $uid );
	return Ambito::actual();
};
add_filter( 'send_auth_cookies', '__return_false' );
$redirigir = static function ( $destino ) {
	throw new RuntimeException( 'asde-redirect:' . $destino );
};
add_filter( 'wp_redirect', $redirigir, 1 );
/** Envía un formulario a una vista como lo haría el navegador. Devuelve el destino de la redirección ('' si no redirige). */
$enviar = static function ( $vista, array $campos, string $nonce ): string {
	$_POST    = $campos + array( '_wpnonce' => wp_create_nonce( $nonce ) );
	$_REQUEST = $_POST;
	$destino  = '';
	try {
		$vista->handle_post();
	} catch ( RuntimeException $e ) {
		$destino = str_replace( 'asde-redirect:', '', $e->getMessage() );
	} finally {
		$_POST    = array();
		$_REQUEST = array();
	}
	return $destino;
};
/** Con ASDE_VOLCAR=<carpeta>, guarda el HTML de las pantallas para verlas en un navegador (solo para revisar el diseño). */
$volcar = static function ( string $nombre, string $html ): void {
	$dir = getenv( "ASDE_VOLCAR" );
	if ( $dir ) {
		file_put_contents( $dir . "/" . $nombre . ".html", str_replace( "<details class=\"asde-rechazar\"", "<details open class=\"asde-rechazar\"", $html ) );
	}
};
$avisos = static function ( int $uid ): string {
	$t = (string) wp_json_encode( get_transient( 'asde_ap_notices_' . $uid ), JSON_UNESCAPED_UNICODE );
	delete_transient( 'asde_ap_notices_' . $uid );
	return $t;
};
$form_mandato = static fn( int $familia, array $extra = array() ) => $extra + array(
	'asde_action' => 'sepa_firmar',
	'familia'     => (string) $familia,
	'titular'     => 'María Núñez Pérez',
	'iban'        => 'es66 2100 0418 4012 3456 7891',
	'calle'       => 'Calle Mayor 5, 2.º',
	'cp'          => '07002',
	'poblacion'   => 'Palma',
	'provincia'   => 'Illes Balears',
	'pais'        => 'España',
	'acepto'      => '1',
);

try {
	echo "== Parte A · IBAN, texto SEPA e identificador del acreedor ==\n";
	$ok( Iban::es_valido( 'ES91 2100 0418 4502 0005 1332' ) && Iban::es_valido( 'de89 3704 0044 0532 0130 00' ) && Iban::es_valido( 'FR1420041010050500013M02606' ), 'IBAN válidos (España, Alemania y Francia, con espacios y minúsculas)' );
	$ok( str_contains( Iban::error( 'ES91 2100 0418 4502 0005 1333' ), 'no cuadran' ) && str_contains( Iban::error( 'ES91210004184502000513' ), '24 caracteres' ) && str_contains( Iban::error( 'US64SVBKUS6S3300958879' ), 'zona SEPA' ) && '' !== Iban::error( '' ) && '' !== Iban::error( 'ES91' ), 'IBAN con un dígito cambiado, de longitud errónea, de fuera de la zona SEPA, vacío o cortado → no valen, con su motivo' );
	$ok( 'ES91 **** **** **** **** 1332' === Iban::enmascarar( 'ES9121000418450200051332' ) && 'ES91 2100 0418 4502 0005 1332' === Iban::formatear( 'es9121000418450200051332' ), 'enmascarar (solo país, control y 4 últimas cifras) y dar formato' );
	$ok( 'Maria Nunez Perez' === Texto::limpiar( 'María Núñez Pérez' ) && 'Pena Cia S.L.' === Texto::limpiar( 'Peña & Cía S.L.', 70 ) && 10 === strlen( Texto::limpiar( 'abcdefghijklmnop', 10 ) ) && '123.45' === Texto::importe( 12345 ) && '0.05' === Texto::importe( 5 ), 'texto SEPA: sin tildes ni ñ, sin símbolos raros, cortado a su longitud; importes con punto y dos decimales' );
	$ok( preg_match( "/^[A-Za-z0-9\/\-?:().,'+ ]*$/", Texto::limpiar( "Ç Ø <b>x</b> \"y\" ; _ | # @ ñ" ) ) === 1, 'todo lo que sale de limpiar() está en el juego de caracteres SEPA' );
	$ok( SepaConfig::ics_formato( $ICS_OK ) && SepaConfig::ics_control_correcto( $ICS_OK ) && ! SepaConfig::ics_control_correcto( 'ES27ZZZG12345678' ) && SepaConfig::ics_control_correcto( 'DE98ZZZ09999999999' ) && ! SepaConfig::ics_formato( 'ES26ZZZG1234' ) && ! SepaConfig::ics_formato( '' ), 'identificador del acreedor: formato (16 caracteres en España) y cifras de control (ejemplo de la norma: DE98ZZZ09999999999)' );
	$defecto = SepaConfig::defaults();
	$ok( count( SepaConfig::errores( $defecto ) ) >= 3 && ! SepaConfig::lista( $defecto ) && array() === SepaConfig::errores( $cfg_ok ) && SepaConfig::lista( $cfg_ok ) && ! SepaConfig::lista( array_merge( $cfg_ok, array( 'activo' => false ) ) ), 'sin nombre, ICS ni IBAN no se puede domiciliar; con todo y activado, sí' );
	SepaConfig::guardar( $cfg_ok );
	$crudo = (array) get_option( $op_sepa );
	$ok( ! isset( $crudo['iban'] ) && ! str_contains( (string) ( $crudo['iban_enc'] ?? '' ), '2100' ) && $IBAN_A === SepaConfig::de()['iban'] && str_contains( SepaConfig::concepto( SepaConfig::de(), $ronda ), $ronda ) && ! str_contains( SepaConfig::concepto( SepaConfig::de(), $ronda ), '{' ), 'el IBAN del acreedor se guarda cifrado (no está en claro en la opción) y el concepto usa {grupo} y {ronda}' );
	SepaConfig::guardar( array_merge( $cfg_ok, array( 'iban' => '' ) ) );
	$ok( $IBAN_A === SepaConfig::de()['iban'], 'guardar con el IBAN vacío conserva el que había' );

	echo "\n== Parte B · Fechas de cobro ==\n";
	$miercoles = new DateTimeImmutable( '2026-09-30', wp_timezone() );
	$viernes   = new DateTimeImmutable( '2026-10-02', wp_timezone() );
	$ok( '2026-10-05' === Remesas::fecha_minima( $miercoles ) && '2026-10-07' === Remesas::fecha_minima( $viernes ), 'fecha mínima: 3 días hábiles desde hoy (sin contar sábados ni domingos)' );
	$ok( '' === Remesas::error_fecha( '2026-10-05', $miercoles ) && str_contains( Remesas::error_fecha( '2026-10-03', $miercoles ), 'laborable' ) && str_contains( Remesas::error_fecha( '2026-10-02', $miercoles ), 'como pronto' ) && '' !== Remesas::error_fecha( '', $miercoles ) && '' !== Remesas::error_fecha( '2026-13-45', $miercoles ), 'la fecha de cobro tiene que ser laborable y con margen; vacía o imposible no vale' );

	echo "\n== Parte C · Quién puede firmar la autorización ==\n";
	$roles_caps = Roles::definitions();
	$ok( ! empty( $roles_caps[ Roles::TUTOR ][1]['asde_sepa_autorizar'] ) && ! empty( $roles_caps[ Roles::PARTICIPANTE ][1]['asde_sepa_autorizar'] ) && empty( $roles_caps[ Roles::SCOUTER ][1]['asde_sepa_autorizar'] ) && in_array( 'asde_sepa_autorizar', Roles::all_caps(), true ), 'permiso nuevo: cuenta familiar y participante (y los administradores); un scouter no' );
	$fam1 = FamiliasTable::obtener( $f1 );
	$fam2 = FamiliasTable::obtener( $f2 );
	$fam3 = FamiliasTable::obtener( $f3 );
	$fam4 = FamiliasTable::obtener( $f4 );
	$ok( true === Mandatos::puede_firmar( $como( $u_fam ), $fam1 ) && true === Mandatos::puede_firmar( $como( $u_fam2 ), $fam1 ), 'las dos cuentas de tutor/a de una familia pueden firmar la autorización de su familia' );
	$ok( true === Mandatos::puede_firmar( $como( $u_rex ), $fam3 ), 'un asociado mayor de edad firma la suya' );
	$ok( str_contains( (string) Mandatos::puede_firmar( $como( $u_pol ), $fam2 ), 'menor de edad' ), 'un asociado menor de edad (15 años) no puede: lo firma su familia' );
	$ok( str_contains( (string) Mandatos::puede_firmar( $como( $u_otra ), $fam1 ), 'no es la tuya' ) && str_contains( (string) Mandatos::puede_firmar( $como( $u_fam ), $fam4 ), 'no es la tuya' ), 'no se firma por una familia que no es la tuya' );
	$ok( str_contains( (string) Mandatos::puede_firmar( $como( $u_scout ), $fam1 ), 'no puede firmar' ), 'una cuenta sin el permiso (scouter) no firma' );

	echo "\n== Parte D · Firmar en «Mis pagos» ==\n";
	$como( $u_fam );
	$html = ( new MisPagosView() )->render();
	$volcar( "mis-pagos-formulario", $html );
	$ok( str_contains( $html, 'Domiciliación SEPA' ) && str_contains( $html, 'Rellenar la autorización' ) && str_contains( $html, 'Grupo Scout Prueba 999' ) && str_contains( $html, 'ocho semanas' ) && str_contains( $html, 'al menos 5 días' ), 'en «Mis pagos» sale la autorización con el acreedor, el texto legal y los días de aviso' );
	$antes = $max_id( Mandatos::table() );
	$destino = $enviar( new MisPagosView(), $form_mandato( $f1, array( 'iban' => 'ES66 2100 0418 4012 3456 7892' ) ), 'asde_sepa_firmar_' . $f1 );
	$html    = ( new MisPagosView() )->render();
	$ok( '' === $destino && $max_id( Mandatos::table() ) === $antes && str_contains( $html, 'no cuadran' ) && str_contains( $html, 'María Núñez Pérez' ) && str_contains( $html, 'ES6621000418401234567892' ), 'IBAN mal escrito → no se guarda nada, se explica y el formulario conserva lo escrito' );
	$enviar( new MisPagosView(), $form_mandato( $f1, array( 'acepto' => '' ) ), 'asde_sepa_firmar_' . $f1 );
	$ok( $max_id( Mandatos::table() ) === $antes && str_contains( $avisos( $u_fam ), 'casilla' ), 'sin marcar la casilla de la firma → no se guarda' );
	$enviar( new MisPagosView(), $form_mandato( $f1, array( 'titular' => 'Maria', 'calle' => '' ) ), 'asde_sepa_firmar_' . $f1 );
	$ok( $max_id( Mandatos::table() ) === $antes, 'sin apellidos del titular ni dirección → no se guarda' );
	$avisos( $u_fam );
	$capturados = array();
	$destino    = $enviar( new MisPagosView(), $form_mandato( $f1 ), 'asde_sepa_firmar_' . $f1 );
	$m1         = Mandatos::activo_de_familia( $f1 );
	$ok( str_contains( $destino, 'mis-pagos' ) && $m1 && 'M' . $grupo['numero'] . '-' . str_pad( (string) $m1['id'], 6, '0', STR_PAD_LEFT ) === $m1['referencia'] && Mandatos::ACTIVO === $m1['estado'] && $u_fam === $m1['firmado_por'] && current_time( 'Y-m-d' ) === $m1['fecha_firma'], 'firma correcta → autorización vigente con su referencia (M684-…), quién firmó y la fecha' );
	$ok( 'ES66 **** **** **** **** 7891' === $m1['iban_mask'] && $IBAN_1 === $m1['datos']['iban'] && 'María Núñez Pérez' === $m1['datos']['titular'] && 'FRST' === Mandatos::secuencia( $m1 ), 'guarda el IBAN (limpio y sin espacios), el titular y la dirección; la primera vez irá como FRST' );
	$crudo = (array) $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Mandatos::table() . ' WHERE id = %d', $m1['id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB
	$todo  = implode( '|', $crudo );
	$ok( ! str_contains( $todo, '6621000418' ) && ! str_contains( $todo, 'Núñez' ) && ! str_contains( $todo, 'Calle Mayor' ) && ! str_contains( $todo, '192.0.2.77' ) && null === Crypto::decrypt_contexto( (string) $crudo['datos_enc'], 'sepa|mandato|' . ( $m1['id'] + 1 ) ) && ! isset( json_decode( (string) Crypto::decrypt_contexto( (string) $crudo['datos_enc'], 'sepa|mandato|' . $m1['id'] ), true )['ip'] ), 'en la base de datos no hay IBAN, nombre, dirección ni IP en claro: todo cifrado con una clave ligada al mandato' );
	$confirmacion = array_values( array_filter( $capturados, static fn( $c ) => str_contains( (string) $c['subject'], 'autorización de domiciliación' ) ) )[0] ?? null;
	$ok( $confirmacion && 'madre.sepa@example.invalid' === ( is_array( $confirmacion['to'] ) ? $confirmacion['to'][0] : $confirmacion['to'] ) && str_contains( (string) $confirmacion['message'], $m1['referencia'] ) && str_contains( (string) $confirmacion['message'], 'ES66 **** **** **** **** 7891' ) && ! str_contains( (string) $confirmacion['message'], '6621000418' ), 'a quien firma le llega un email de confirmación (con la referencia y el IBAN enmascarado)' );
	$html = ( new MisPagosView() )->render();
	$volcar( "mis-pagos-firmada", $html );
	$ok( str_contains( $html, 'Autorizada' ) && str_contains( $html, 'ES66 **** **** **** **** 7891' ) && ! str_contains( $html, '2100 0418 4012' ) && ! str_contains( $html, 'es66' ) && str_contains( $html, 'Anular la autorización' ), '«Mis pagos» con la autorización vigente: solo enseña el IBAN enmascarado y deja anularla' );
	$_GET['autorizacion'] = (string) $m1['id'];
	$imprimir             = ( new MisPagosView() )->render();
	unset( $_GET['autorizacion'] );
	$volcar( "autorizacion", $imprimir );
	$ok( str_contains( $imprimir, 'Orden de domiciliación de adeudo directo SEPA' ) && str_contains( $imprimir, $m1['referencia'] ) && str_contains( $imprimir, $ICS_OK ) && str_contains( $imprimir, 'ES66 2100 0418 4012 3456 7891' ) && str_contains( $imprimir, 'Recurrente' ) && str_contains( $imprimir, 'Firmada electrónicamente' ) && str_contains( $imprimir, 'data-asde-imprimir-pagina' ), 'la autorización para imprimir lleva todos sus datos (referencia, acreedor, deudor, IBAN entero para quien firma, tipo de pago y firma electrónica)' );
	$como( $u_otra );
	$_GET['autorizacion'] = (string) $m1['id'];
	$ajena                = ( new MisPagosView() )->render();
	unset( $_GET['autorizacion'] );
	$ok( str_contains( $ajena, 'No tienes acceso a esa autorización' ) && ! str_contains( $ajena, $m1['referencia'] ), 'la autorización de otra familia no se puede ver' );
	$antes = $max_id( Mandatos::table() );
	$enviar( new MisPagosView(), $form_mandato( $f1 ), 'asde_sepa_firmar_' . $f1 );
	$ok( $max_id( Mandatos::table() ) === $antes && str_contains( $avisos( $u_otra ), 'no es la tuya' ), 'firmar por una familia que no es la tuya → no' );
	$como( $u_pol );
	$enviar( new MisPagosView(), $form_mandato( $f2 ), 'asde_sepa_firmar_' . $f2 );
	$html_pol = ( new MisPagosView() )->render();
	$ok( $max_id( Mandatos::table() ) === $antes && str_contains( $avisos( $u_pol ) . $html_pol, 'menor de edad' ) && ! str_contains( $html_pol, 'Rellenar la autorización' ), 'un menor de edad no puede firmar (y su pantalla no le ofrece el formulario)' );
	$como( $u_rex );
	$enviar( new MisPagosView(), $form_mandato( $f3, array( 'titular' => 'Rex Sepa Gil', 'iban' => $IBAN_3, 'calle' => 'Plaza 1', 'cp' => '07003' ) ), 'asde_sepa_firmar_' . $f3 );
	$m3 = Mandatos::activo_de_familia( $f3 );
	$ok( $m3 && $u_rex === $m3['firmado_por'] && $IBAN_3 === $m3['datos']['iban'], 'un asociado mayor de edad firma la autorización de su familia' );

	echo "\n== Parte E · Sustituir y anular ==\n";
	$como( $u_fam );
	$enviar( new MisPagosView(), $form_mandato( $f1, array( 'iban' => $IBAN_3, 'titular' => 'María Núñez Pérez' ) ), 'asde_sepa_firmar_' . $f1 );
	$m1b = Mandatos::activo_de_familia( $f1 );
	$ok( $m1b && $m1b['id'] !== $m1['id'] && Mandatos::REVOCADO === Mandatos::obtener( $m1['id'] )['estado'] && str_contains( Mandatos::obtener( $m1['id'] )['motivo'], 'Sustituida' ) && 1 === count( array_filter( Mandatos::de_familia( $f1 ), static fn( $m ) => Mandatos::ACTIVO === $m['estado'] ) ), 'firmar otra autorización sustituye a la anterior: solo hay una vigente por familia' );
	$como( $u_fam2 );
	$enviar( new MisPagosView(), array( 'asde_action' => 'sepa_anular', 'mandato' => (string) $m1b['id'] ), 'asde_sepa_anular_' . $m1b['id'] );
	$ok( null === Mandatos::activo_de_familia( $f1 ) && Mandatos::REVOCADO === Mandatos::obtener( $m1b['id'] )['estado'], 'el otro tutor/a (la segunda cuenta de la familia) puede anular la autorización' );
	$como( $u_otra );
	$enviar( new MisPagosView(), array( 'asde_action' => 'sepa_anular', 'mandato' => (string) $m3['id'] ), 'asde_sepa_anular_' . $m3['id'] );
	$ok( Mandatos::ACTIVO === Mandatos::obtener( $m3['id'] )['estado'] && str_contains( $avisos( $u_otra ), 'No puedes anular' ), 'nadie anula la autorización de otra familia' );
	$como( $u_fam );
	$enviar( new MisPagosView(), $form_mandato( $f1 ), 'asde_sepa_firmar_' . $f1 );
	$m1 = Mandatos::activo_de_familia( $f1 );
	$ok( $m1 && $IBAN_1 === $m1['datos']['iban'], 'la familia vuelve a firmar (cuenta de la familia 1)' );

	echo "\n== Parte F · Qué se cobra (vista previa) ==\n";
	$repo = PersonaRepository::make();
	$fecha_cobro = Remesas::fecha_minima();
	$c2  = TesoreriaConfig::de( $ronda );
	$ok( 258.0 === TesoreriaConfig::cuota( $c2, 2 )['total'] && 140.0 === TesoreriaConfig::cuota( $c2, 1 )['total'], 'la ronda de prueba: 258 € con 2 hijos y 140 € con 1' );
	MovimientosTable::registrar( $f1, $ronda, MovimientosTable::PAGO, current_time( 'Y-m-d' ), 58.0, 'transferencia', 'F1', '' );
	$prep = Remesas::preparar( $repo, $ronda, '' );
	$por_familia = array_column( $prep['lineas'], null, 'familia' );
	$ok( 2 === count( $prep['lineas'] ) && 20000 === $por_familia[ $f1 ]['centimos'] && 14000 === $por_familia[ $f3 ]['centimos'] && 34000 === $prep['total'] && 'Todo lo pendiente' === $prep['plazo_nombre'], 'todo lo pendiente: familia 1 (258 − 58 ya pagados = 200 €) y Rex (140 €)' );
	$ok( 'FRST' === $por_familia[ $f1 ]['secuencia'] && 'FRST' === $por_familia[ $f3 ]['secuencia'] && $m1['referencia'] === $por_familia[ $f1 ]['umr'] && $IBAN_1 === $por_familia[ $f1 ]['iban'], 'las autorizaciones estrenadas van como FRST, con su referencia y su cuenta' );
	$omitidas = array_column( $prep['omitidas'], 'motivo', 'familia' );
	$ok( 2 === count( $omitidas ) && 'Sin autorización SEPA' === ( $omitidas[ $f2 ] ?? '' ) && 'Sin autorización SEPA' === ( $omitidas[ $f4 ] ?? '' ), 'las familias con algo pendiente y sin autorización salen aparte («Sin autorización SEPA»)' );
	$p0 = Remesas::preparar( $repo, $ronda, '0' );
	$ok( 7100 === array_column( $p0['lineas'], null, 'familia' )[ $f1 ]['centimos'] && 7000 === array_column( $p0['lineas'], null, 'familia' )[ $f3 ]['centimos'] && 'Primer plazo' === $p0['plazo_nombre'], 'hasta el primer plazo (50 %): 129 − 58 = 71 € y 70 €' );
	$p1 = Remesas::preparar( $repo, $ronda, '1' );
	$ok( 20000 === array_column( $p1['lineas'], null, 'familia' )[ $f1 ]['centimos'], 'hasta el segundo plazo: todo lo que falta' );
	MovimientosTable::registrar( $f1, $ronda, MovimientosTable::PAGO, current_time( 'Y-m-d' ), 100.0, 'efectivo', 'F1', '' );
	$p0b = Remesas::preparar( $repo, $ronda, '0' );
	$ok( ! isset( array_column( $p0b['lineas'], null, 'familia' )[ $f1 ] ) && isset( array_column( $p0b['lineas'], null, 'familia' )[ $f3 ] ), 'una familia que ya ha pagado lo del plazo no se incluye' );
	$pago_extra = (int) $wpdb->get_var( 'SELECT MAX(id) FROM ' . MovimientosTable::table() ); // phpcs:ignore WordPress.DB
	MovimientosTable::anular( $pago_extra );
	$prep = Remesas::preparar( $repo, $ronda, '' );

	echo "\n== Parte G · Crear la remesa y el fichero para el banco ==\n";
	$e1 = Remesas::crear( $prep, $ronda, '2000-01-03', 'Cuota de prueba', 0 );
	$ok( is_wp_error( $e1 ) && str_contains( $e1->get_error_message(), 'como pronto' ), 'una fecha de cobro sin margen no vale' );
	$sin = Remesas::crear( array_merge( $prep, array( 'lineas' => array() ) ), $ronda, $fecha_cobro, 'x', 0 );
	$ok( is_wp_error( $sin ), 'una remesa sin adeudos no se crea' );
	$id_r = Remesas::crear( $prep, $ronda, $fecha_cobro, SepaConfig::concepto( SepaConfig::de(), $ronda ), 0 );
	$r    = is_int( $id_r ) ? Remesas::obtener( $id_r ) : null;
	$ok( $r && preg_match( '/^R' . $grupo['numero'] . '-\d{4}$/', $r['numero'] ) && Remesas::GENERADA === $r['estado'] && 2 === $r['adeudos'] && 340.0 === $r['total'] && $fecha_cobro === $r['fecha_cobro'] && $ronda === $r['ronda'], 'remesa creada: nº R684-…, generada, 2 adeudos, 340,00 €' );
	$crudo_r = (array) $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Remesas::table() . ' WHERE id = %d', $id_r ), ARRAY_A ); // phpcs:ignore WordPress.DB
	$ok( ! str_contains( implode( '|', $crudo_r ), '6621000418' ) && ! str_contains( implode( '|', $crudo_r ), 'Núñez' ) && 2 === count( Remesas::lineas( $r ) ), 'los adeudos (titular e IBAN) van cifrados en la base de datos' );
	$xml = Remesas::xml( $r );
	$dom = new DOMDocument();
	$ok( is_string( $xml ) && $dom->loadXML( $xml ), 'el fichero es un XML bien formado' );
	$xp = new DOMXPath( $dom );
	$xp->registerNamespace( 'p', Pain008::NAMESPACE );
	$val = static fn( string $q ) => (string) ( $xp->query( $q )->item( 0 )->textContent ?? '' );
	$ok( 'urn:iso:std:iso:20022:tech:xsd:pain.008.001.02' === $dom->documentElement->namespaceURI && 'Document' === $dom->documentElement->localName && '2' === $val( '//p:GrpHdr/p:NbOfTxs' ) && '340.00' === $val( '//p:GrpHdr/p:CtrlSum' ) && $r['msg_id'] === $val( '//p:GrpHdr/p:MsgId' ) && $ICS_OK === $val( '//p:GrpHdr/p:InitgPty/p:Id/p:OrgId/p:Othr/p:Id' ), 'cabecera: pain.008.001.02, 2 adeudos, suma 340.00, identificador del mensaje y del acreedor' );
	$ok( 1 === $xp->query( '//p:PmtInf' )->length && 'FRST' === $val( '//p:PmtInf/p:PmtTpInf/p:SeqTp' ) && 'CORE' === $val( '//p:PmtInf/p:PmtTpInf/p:LclInstrm/p:Cd' ) && 'SEPA' === $val( '//p:PmtInf/p:PmtTpInf/p:SvcLvl/p:Cd' ) && 'DD' === $val( '//p:PmtInf/p:PmtMtd' ) && $fecha_cobro === $val( '//p:PmtInf/p:ReqdColltnDt' ) && $IBAN_A === $val( '//p:PmtInf/p:CdtrAcct/p:Id/p:IBAN' ) && 'SLEV' === $val( '//p:PmtInf/p:ChrgBr' ) && 'SEPA' === $val( '//p:PmtInf/p:CdtrSchmeId/p:Id/p:PrvtId/p:Othr/p:SchmeNm/p:Prtry' ) && 'NOTPROVIDED' === $val( '//p:PmtInf/p:CdtrAgt/p:FinInstnId/p:Othr/p:Id' ), 'bloque de pago: adeudo Core, secuencia FRST, fecha de cobro, cuenta del acreedor, gastos compartidos y esquema SEPA' );
	$tx = $xp->query( '//p:DrctDbtTxInf' );
	$t1 = null;
	foreach ( $tx as $nodo ) {
		if ( 'Maria Nunez Perez' === (string) $xp->query( 'p:Dbtr/p:Nm', $nodo )->item( 0 )->textContent ) {
			$t1 = $nodo;
		}
	}
	$q = static fn( string $c ) => (string) ( $t1 ? ( $xp->query( $c, $t1 )->item( 0 )->textContent ?? '' ) : '' );
	$ok( 2 === $tx->length && $t1 && '200.00' === $q( 'p:InstdAmt' ) && 'EUR' === $xp->query( 'p:InstdAmt', $t1 )->item( 0 )->getAttribute( 'Ccy' ) && $m1['referencia'] === $q( 'p:DrctDbtTx/p:MndtRltdInf/p:MndtId' ) && current_time( 'Y-m-d' ) === $q( 'p:DrctDbtTx/p:MndtRltdInf/p:DtOfSgntr' ) && $IBAN_1 === $q( 'p:DbtrAcct/p:Id/p:IBAN' ) && $r['numero'] . '-' . $fam1['numero'] === $q( 'p:PmtId/p:EndToEndId' ) && str_contains( $q( 'p:RmtInf/p:Ustrd' ), $fam1['numero'] ) && 'NOTPROVIDED' === $q( 'p:DbtrAgt/p:FinInstnId/p:Othr/p:Id' ), 'cada adeudo: importe, referencia y fecha de la autorización, IBAN del deudor, nombre limpio (Maria Nunez Perez), referencia extremo a extremo con el nº de familia y concepto con el nº de familia' );
	$ok( ! preg_match( '/[^\x00-\x7F]/', (string) $xml ), 'el fichero es todo ASCII: sin tildes ni ñ' );
	$largo = array( 'lineas' => Remesas::lineas( $r ) );
	$mal   = $largo['lineas'];
	$mal[0]['iban'] = 'ES6621000418401234567892';
	$ok( array() !== Pain008::errores( SepaConfig::de(), $fecha_cobro, $mal ) && array() === Pain008::errores( SepaConfig::de(), $fecha_cobro, $largo['lineas'] ), 'un IBAN mal en un adeudo impide generar el fichero, con su motivo' );
	$rec = Remesas::preparar( $repo, $ronda, '' );
	$ok( 0 === count( $rec['lineas'] ) && str_contains( implode( ' ', array_column( $rec['omitidas'], 'motivo' ) ), 'Ya está en la remesa ' . $r['numero'] ), 'mientras una remesa no tiene sus cobros registrados, sus familias no entran en otra (no se cobra dos veces)' );
	$otra_id = Remesas::crear( $prep, $ronda, $fecha_cobro, 'x', 0 );
	$ok( is_wp_error( $otra_id ) && str_contains( $otra_id->get_error_message(), 'ya están en otra remesa' ), 'aunque se use una vista previa antigua, crear() no repite adeudos que ya están en otra remesa sin cobrar' );

	echo "\n== Parte H · Avisar, enviar al banco y registrar cobros ==\n";
	$capturados = array();
	$av         = Avisos::preaviso( $r );
	$para_todos = array_map( static fn( $c ) => is_array( $c['to'] ) ? $c['to'][0] : $c['to'], $capturados );
	sort( $para_todos );
	$ok( 3 === $av['enviados'] && 2 === $av['familias'] && 0 === $av['sin_email'] && array( 'madre.sepa@example.invalid', 'padre.sepa@example.invalid', 'rex.sepa@example.invalid' ) === $para_todos, 'aviso previo: llega a las dos cuentas de la familia 1 (madre y padre) y a Rex, sin repetir' );
	$msg = (string) ( $capturados[0]['message'] ?? '' );
	$ok( str_contains( $msg, wp_date( 'd/m/Y', strtotime( $fecha_cobro ) ) ) && preg_match( '/2\d\d[.,]00/', $msg ) === 1 && str_contains( $msg, 'ES66 **** **** **** **** 7891' ) && ! str_contains( $msg, '6621000418' ) && str_contains( $msg, 'M' . $grupo['numero'] . '-' ), 'el aviso lleva la fecha, el importe, el IBAN enmascarado y la referencia de la autorización' );
	$r = Remesas::obtener( (int) $id_r );
	$ok( ! empty( $r['avisada_en'] ), 'la remesa recuerda que se ha avisado a las familias' );
	$fila_sepa = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . RegistroEmails::table() . ' WHERE tipo = %s AND id > %d ORDER BY id DESC LIMIT 1', RegistroEmails::SEPA, $emails_antes ), ARRAY_A ); // phpcs:ignore WordPress.DB
	$ok( $fila_sepa && ! str_contains( $fila_sepa['destino'] . $fila_sepa['datos'], '@example.invalid' ), 'el registro de los avisos SEPA no guarda emails en claro (destino enmascarado)' );
	$ok( $fila_sepa && ! str_contains( $fila_sepa['datos'], '6621000418' ), 'ni cuentas bancarias en claro' );
	$ok( $fila_sepa && str_contains( RegistroEmails::vista_previa( $fila_sepa ), 'ES' ), 'y se ven como eran (la vista previa del email)' );
	$ok( ! Remesas::marcar_enviada( 999999, 0 ) && Mandatos::secuencia( Mandatos::obtener( $m1['id'] ) ) === 'FRST', 'antes de enviarla, las autorizaciones siguen siendo FRST' );
	$ok( Remesas::marcar_enviada( (int) $id_r, 0 ) && Remesas::ENVIADA === Remesas::obtener( (int) $id_r )['estado'] && 'RCUR' === Mandatos::secuencia( Mandatos::obtener( $m1['id'] ) ) && 'RCUR' === Mandatos::secuencia( Mandatos::obtener( $m3['id'] ) ), 'marcarla como enviada al banco: las autorizaciones estrenadas pasan a RCUR' );
	$ok( ! Remesas::marcar_enviada( (int) $id_r, 0 ) && ! Remesas::anular( (int) $id_r, 0 ), 'una remesa enviada no se vuelve a enviar ni se anula' );
	$sin_cobrar = Remesas::proximos_de_familia( $f1 );
	$ok( 1 === count( $sin_cobrar ) && 20000 === $sin_cobrar[0]['centimos'] && $fecha_cobro === $sin_cobrar[0]['fecha'], 'la familia ve su próximo cargo (fecha e importe) en «Mis pagos»' );
	$como( $u_fam );
	$html = ( new MisPagosView() )->render();
	$ok( str_contains( $html, 'Próximo cargo' ) && str_contains( $html, wp_date( 'd/m/Y', strtotime( $fecha_cobro ) ) ), '«Mis pagos» enseña el aviso del próximo cargo' );

	$c_ko = Remesas::registrar_cobros( (int) $id_r, array( $f1 ), array(), '2999-01-01', 0 );
	$ok( $c_ko['errores'] && 0 === $c_ko['cobrados'], 'la fecha del cobro no puede ser futura' );
	$res = Remesas::registrar_cobros( (int) $id_r, array( $f1 ), array( $f3 ), current_time( 'Y-m-d' ), 0 );
	$tot = MovimientosTable::totales( $ronda );
	$r   = Remesas::obtener( (int) $id_r );
	$ls  = array_column( Remesas::lineas( $r ), null, 'familia' );
	$ok( 1 === $res['cobrados'] && 1 === $res['devueltos'] && 258.0 === $tot[ $f1 ]['pagado'] && Remesas::COBRADO === $ls[ $f1 ]['estado'] && Remesas::DEVUELTO === $ls[ $f3 ]['estado'] && ! empty( $r['cobros_en'] ), 'cobros: la familia 1 queda pagada (58 + 200) y la de Rex, marcada como devuelta; la remesa se da por cerrada' );
	$pago = (array) $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . MovimientosTable::table() . ' WHERE familia_id = %d AND origen = %s', $f1, 'sepa' ), ARRAY_A ); // phpcs:ignore WordPress.DB
	$ok( $pago && 'domiciliacion' === $pago['metodo'] && $r['numero'] . '-' . $fam1['numero'] === $pago['referencia'] && 200.0 === (float) $pago['importe'] && str_contains( $pago['nota'], $r['numero'] ), 'el cobro es un pago de la familia: forma «Domiciliación SEPA», origen sepa y la referencia de la remesa' );
	$ok( 'Domiciliación SEPA' === MovimientosTable::METODOS['domiciliacion'] && Calculadora::PAGADO === Calculadora::familia( $fam1, $repo->obtener_varias( $fam1['miembros'] ), $c2, $tot[ $f1 ] )['estado'], 'la familia 1 sale como «pagada» en el control de pagos' );
	$como( $u_fam );
	$html = ( new MisPagosView() )->render();
	$ok( str_contains( $html, 'Domiciliación SEPA' ) && ! str_contains( $html, 'Próximo cargo' ), '«Mis pagos» lista el pago con su forma de pago y ya no anuncia el cargo' );
	$again = Remesas::registrar_cobros( (int) $id_r, array( $f1 ), array(), current_time( 'Y-m-d' ), 0 );
	$ok( 0 === $again['cobrados'] && 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . MovimientosTable::table() . ' WHERE familia_id = %d AND origen = %s', $f1, 'sepa' ) ), 'registrar dos veces el mismo cobro no duplica el pago' ); // phpcs:ignore WordPress.DB

	echo "\n== Parte I · Volver a cobrar lo devuelto, anular y borrar datos antiguos ==\n";
	$prep2 = Remesas::preparar( $repo, $ronda, '' );
	$l2    = array_column( $prep2['lineas'], null, 'familia' );
	$ok( 1 === count( $l2 ) && isset( $l2[ $f3 ] ) && 'RCUR' === $l2[ $f3 ]['secuencia'] && 14000 === $l2[ $f3 ]['centimos'], 'lo devuelto vuelve a poder cobrarse, ya como RCUR (la autorización se usó antes)' );
	$id2 = Remesas::crear( $prep2, $ronda, $fecha_cobro, 'Reclamación', 0 );
	$x2  = new DOMDocument();
	$x2->loadXML( (string) Remesas::xml( Remesas::obtener( (int) $id2 ) ) );
	$xp2 = new DOMXPath( $x2 );
	$xp2->registerNamespace( 'p', Pain008::NAMESPACE );
	$ok( is_int( $id2 ) && 'RCUR' === $xp2->query( '//p:PmtInf/p:PmtTpInf/p:SeqTp' )->item( 0 )->textContent && 1 === $xp2->query( '//p:PmtInf' )->length && '140.00' === $xp2->query( '//p:GrpHdr/p:CtrlSum' )->item( 0 )->textContent, 'el fichero de una familia con autorización ya usada lleva la secuencia RCUR' );
	$ok( Remesas::anular( (int) $id2, 0 ) && Remesas::ANULADA === Remesas::obtener( (int) $id2 )['estado'] && array() === Remesas::lineas( Remesas::obtener( (int) $id2 ) ) && is_wp_error( Remesas::xml( Remesas::obtener( (int) $id2 ) ) ), 'anular una remesa sin enviar: borra sus datos y ya no se puede descargar' );
	$ok( 1 === count( Remesas::preparar( $repo, $ronda, '' )['lineas'] ), 'anulada, su familia vuelve a poder incluirse en otra' );
	$wpdb->update( Mandatos::table(), array( 'revocado_en' => gmdate( 'Y-m-d H:i:s', strtotime( '-15 months' ) ) ), array( 'id' => $m1b['id'] ) );
	$purga = Mandatos::purgar();
	$ok( $purga >= 1 && '' === Mandatos::obtener( $m1b['id'] )['datos']['iban'] && $IBAN_1 === Mandatos::activo_de_familia( $f1 )['datos']['iban'], 'un mandato revocado hace más de 14 meses pierde sus datos; los vigentes no se tocan' );
	$wpdb->update( Remesas::table(), array( 'fecha_cobro' => gmdate( 'Y-m-d', strtotime( '-15 months' ) ) ), array( 'id' => $id_r ) );
	Remesas::purgar();
	$ok( array() === Remesas::lineas( Remesas::obtener( (int) $id_r ) ), 'los adeudos de una remesa con cobro hace más de 14 meses se borran (queda el resumen)' );

	echo "\n== Parte J · Pantalla de Tesorería ==\n";
	$como( $u_scout );
	$ok( str_contains( ( new SepaView() )->render(), 'No tienes acceso' ), 'un scouter sin permiso de configuración no entra en Domiciliaciones' );
	$como( $u_teso );
	$tabs = ( new class() extends TesoreriaBaseView {
		public function render( string $content = '' ): string {
			return $this->subnav( 'sepa', TesoreriaConfig::ronda_actual() );
		}
	} )->render();
	$ok( str_contains( $tabs, 'Domiciliaciones' ) && str_contains( $tabs, 'domiciliaciones' ), 'la pestaña «Domiciliaciones» sale en Tesorería para quien puede configurar' );
	$pagina = ( new SepaView() )->render();
	$volcar( "tesoreria-panel", $pagina );
	$ok( str_contains( $pagina, 'Datos del acreedor' ) && str_contains( $pagina, 'Nueva remesa' ) && str_contains( $pagina, 'Autorizaciones de las familias' ) && str_contains( $pagina, 'ES79 **** **** **** **** 6789' ), 'la pantalla: datos del acreedor (con su IBAN enmascarado), nueva remesa y autorizaciones' );
	$_GET['ronda']    = $ronda;
	$_GET['preparar'] = '1';
	$_GET['plazo']    = '0';
	$_GET['fecha']    = $fecha_cobro;
	$prev             = ( new SepaView() )->render();
	unset( $_GET['ronda'], $_GET['preparar'], $_GET['plazo'], $_GET['fecha'] );
	$volcar( "tesoreria-previa", $prev );
	$ok( str_contains( $prev, 'Vista previa' ) && str_contains( $prev, 'Rex Sepa Gil' ) && str_contains( $prev, 'RCUR' ) && str_contains( $prev, 'Crear la remesa' ) && ! str_contains( $prev, '2100 0418 4012' ), 'la vista previa lista a quién se cobra (con la cuenta enmascarada) y ofrece crear la remesa' );
	$dest = $enviar( new SepaView(), array( 'asde_action' => 'sepa_generar', 'ronda' => $ronda, 'plazo' => '', 'fecha' => $fecha_cobro, 'concepto' => 'Cuota desde la pantalla' ), 'asde_sepa_generar' );
	$nueva_id = $max_id( Remesas::table() );
	$nueva    = Remesas::obtener( $nueva_id );
	$ok( str_contains( $dest, 'remesa=' . $nueva_id ) && $nueva && Remesas::GENERADA === $nueva['estado'] && 1 === $nueva['adeudos'] && 'Cuota desde la pantalla' === $nueva['concepto'], 'crear la remesa desde la pantalla: se abre su ficha' );
	$_GET['remesa'] = (string) $nueva_id;
	$ficha          = ( new SepaView() )->render();
	unset( $_GET['remesa'] );
	$volcar( "remesa", $ficha );
	$ok( str_contains( $ficha, 'Descargar el fichero XML' ) && str_contains( $ficha, 'Avisar a las familias' ) && str_contains( $ficha, 'Marcar como enviada' ) && str_contains( $ficha, $nueva['numero'] ), 'la ficha de la remesa: descargar, avisar y marcar como enviada' );
	$dest = $enviar( new SepaView(), array( 'asde_action' => 'sepa_config', 'ronda' => $ronda, 'nombre' => 'Otro Nombre SL', 'ics' => $ICS_OK, 'iban' => '', 'calle' => 'Calle 1', 'cp' => '07001', 'poblacion' => 'Palma', 'provincia' => '', 'dias_aviso' => '7', 'concepto' => 'Cuota {ronda}', 'activo' => '1' ), 'asde_sepa_config' );
	$cfg_n = SepaConfig::de();
	$ok( 'Otro Nombre SL' === $cfg_n['nombre'] && $IBAN_A === $cfg_n['iban'] && 7 === $cfg_n['dias_aviso'] && $cfg_n['activo'], 'guardar los datos del acreedor desde la pantalla (con el IBAN vacío se conserva el guardado)' );
	$dest = $enviar( new SepaView(), array( 'asde_action' => 'sepa_config', 'ronda' => $ronda, 'nombre' => 'Otro Nombre SL', 'ics' => 'ES00', 'iban' => '', 'activo' => '1' ), 'asde_sepa_config' );
	$ok( str_contains( $avisos( $u_teso ), 'todavía falta' ) && ! SepaConfig::lista( SepaConfig::de() ), 'con datos incompletos se avisa de lo que falta y no se ofrece a las familias' );
	$como( $u_fam );
	$sin_datos = ( new MisPagosView() )->render();
	$ok( str_contains( $sin_datos, 'Autorizada' ) && ! str_contains( $sin_datos, 'Cambiar de cuenta o de titular' ), 'sin datos del acreedor completos, «Mis pagos» sigue enseñando la autorización vigente pero no ofrece firmar otra' );
	$como( $u_teso );
	SepaConfig::guardar( $cfg_ok );
	$dest = $enviar( new SepaView(), array( 'asde_action' => 'sepa_revocar', 'ronda' => $ronda, 'mandato' => (string) $m3['id'], 'motivo' => 'Cambio de banco' ), 'asde_sepa_revocar' );
	$ok( Mandatos::REVOCADO === Mandatos::obtener( $m3['id'] )['estado'] && str_contains( Mandatos::obtener( $m3['id'] )['motivo'], 'Cambio de banco' ), 'Tesorería puede anular la autorización de una familia' );
	$ok( shortcode_exists( 'asde_sepa' ) && '' !== Pages::url( 'sepa' ) && str_contains( Pages::url( 'sepa' ), 'domiciliaciones' ), 'la página «Domiciliaciones SEPA» existe con su shortcode' );

	echo "\n== Informe de pagos por socio (v0.25) ==\n";
	$repo     = PersonaRepository::make();
	$familias = FamiliasTable::todas();
	$calc     = static fn( int $fid ) => Calculadora::familia( $familias[ $fid ], $repo->obtener_varias( $familias[ $fid ]['miembros'] ), TesoreriaConfig::de( $ronda ), MovimientosTable::totales( $ronda )[ $fid ] ?? array() );
	$cols_p   = array( 'nombre', 'seccion', 'pago_familia', 'pago_estado', 'pago_a_pagar', 'pago_pagado', 'pago_pendiente', 'pago_vencido', 'pago_hechos' );
	$informe  = static fn( array $filtros, array $cols = array() ) => new Informe( 0, 'Prueba', '', $cols ?: $cols_p, $filtros + array( 'ronda' => $ronda ), 0, false );
	$amb      = $como( $u_scout );
	$ok( Catalogo::ve_pagos( $amb ) && isset( Catalogo::permitidas( $amb )['pago_estado'] ), 'un scouter (con acceso a Tesorería) tiene las columnas de la cuota y los pagos' );
	$res = ( new Generador( $repo->crm() ) )->ejecutar( $informe( array() ), $amb );
	$c1  = $calc( $f1 );
	$leo = $res['filas'][0] ?? array();
	$ok( 1 === count( $res['filas'] ) && 'Leo' === $leo['nombre'] && $familias[ $f1 ]['numero'] === $leo['pago_familia'] && $ronda === $res['ronda'], 'el scouter de Lobatos solo ve a los de su sección (Leo), con su familia' );
	$ok( asde_t( Calculadora::ETIQUETAS[ $c1['estado'] ] ) === $leo['pago_estado'] && Calculadora::euros( $c1['a_pagar'] ) === $leo['pago_a_pagar'] && Calculadora::euros( $c1['pagado'] ) === $leo['pago_pagado'] && Calculadora::euros( max( 0, $c1['pendiente'] ) ) === $leo['pago_pendiente'] && 2 === count( $c1['pagan'] ), 'estado, cuota, pagado y pendiente como en el control de pagos: la cuota es la de la familia (Leo y su hermana Nora, de otra sección)' );
	$hechos = array_filter( MovimientosTable::de_familia( $f1, $ronda ), static fn( $m ) => MovimientosTable::PAGO === $m['tipo'] && empty( $m['anulado_en'] ) );
	$ok( $hechos && count( $hechos ) === count( explode( '; ', $leo['pago_hechos'] ) ) && str_contains( $leo['pago_hechos'], Calculadora::euros( (float) current( $hechos )['importe'] ) ), 'y los pagos hechos (fecha e importe), sin los eliminados' );

	// Visión global: los filtros por lo pagado.
	get_userdata( $u_teso )->add_role( Roles::COORDINACION );
	$amb    = $como( $u_teso );
	$todos  = ( new Generador( $repo->crm() ) )->ejecutar( $informe( array() ), $amb );
	$estado = array_column( $todos['filas'], 'pago_estado', 'nombre' );
	$filtra = static fn( string $f ) => array_column( ( new Generador( $repo->crm() ) )->ejecutar( $informe( array( 'pago' => $f ) ), $amb )['filas'], 'nombre' );
	$deben  = array_keys( array_filter( $estado, static fn( $e ) => in_array( $e, array( asde_t( 'Pendiente' ), asde_t( 'Pago parcial' ), asde_t( 'Con plazos vencidos' ) ), true ) ) );
	$ok( count( $todos['filas'] ) === count( $crm->personas ) && asde_t( 'Sin familia en Tesorería' ) === $estado['Eva'] && '' === array_column( $todos['filas'], 'pago_pagado', 'nombre' )['Eva'], 'Coordinación ve a todo el grupo; quien no está en ninguna familia lo dice' );
	$ok( $deben && $filtra( 'debe' ) === array_values( array_intersect( array_keys( $estado ), $deben ) ) && ! in_array( 'Eva', $filtra( 'debe' ), true ), '«Les falta algo por pagar»: solo los de familias con algo pendiente (' . implode( ', ', $deben ) . ')' );
	$ok( $filtra( 'pagado' ) === array_keys( array_filter( $estado, static fn( $e ) => asde_t( 'Pagado' ) === $e ) ) && array( 'Eva', 'Ana' ) === array_values( array_intersect( array( 'Eva', 'Ana' ), $filtra( 'sin_familia' ) ) ), '«Han pagado toda la cuota» y «Sin familia en Tesorería»' );
	get_userdata( $u_teso )->remove_role( Roles::COORDINACION );

	$avisos_php = array();
	set_error_handler( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler
		static function ( $n, $texto ) use ( &$avisos_php ) {
			$avisos_php[] = $texto;
			return true;
		}
	);
	$antiguo = Informe::from_row( array( 'id' => 0, 'nombre' => 'Antiguo', 'descripcion' => '', 'definicion' => '{"columnas":["nombre"],"filtros":{"secciones":[],"orden":"apellidos"}}', 'autor_id' => 0, 'compartido' => 0, 'sistema' => 0 ) );
	restore_error_handler();
	$ok( ! $avisos_php && '' === $antiguo->filtros['pago'] && '' === $antiguo->filtros['ronda'], 'un informe guardado antes de la v0.25 (sin los filtros de pagos) se lee sin avisos de PHP' );

	// La pantalla: el informe predefinido, el editor y el resultado.
	InformesTable::install_table();
	$ok( 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . InformesTable::table() . ' WHERE sistema = 1 AND nombre = %s', 'Pagos de la cuota' ) ), 'el informe predefinido «Pagos de la cuota» existe una sola vez (se añade al actualizar, sin repetirse)' ); // phpcs:ignore WordPress.DB
	$como( $u_scout );
	$lista_inf = ( new InformesView() )->render();
	$_GET      = array( 'nuevo' => '1' );
	$editor    = ( new InformesView() )->render();
	$_GET      = array( 'ver' => '1', 'cols' => array( 'nombre', 'pago_estado', 'pago_pendiente' ), 'pago' => 'debe', 'ronda' => $ronda );
	$resultado = ( new InformesView() )->render();
	$_GET      = array();
	$ok( str_contains( $lista_inf, 'Pagos de la cuota' ) && str_contains( $editor, 'Cuota y pagos' ) && str_contains( $editor, 'name="pago"' ) && str_contains( $editor, '<option value="' . $ronda . '"' ), 'el scouter ve el informe «Pagos de la cuota» y, en el editor, las columnas y los filtros de pagos (y la ronda)' );
	$ok( str_contains( $resultado, 'Cuota de la ronda ' . $ronda ) && str_contains( $resultado, '· les falta algo por pagar' ), 'el resultado dice de qué ronda es la cuota y el filtro aplicado' );
	$mod_antes = get_option( 'asde_ap_modulos', null );
	Modulos::guardar( $grupo['numero'], array_values( array_diff( array_keys( Modulos::LISTA ), array( Modulos::TESORERIA ) ) ) );
	$amb       = $como( $u_scout );
	$sin       = ( new Generador( $repo->crm() ) )->ejecutar( $informe( array( 'pago' => 'debe' ), array( 'nombre', 'pago_estado' ) ), $amb );
	$lista_sin = ( new InformesView() )->render();
	null === $mod_antes ? delete_option( 'asde_ap_modulos' ) : update_option( 'asde_ap_modulos', $mod_antes, false );
	Modulos::olvidar();
	$ok( array( 'nombre' ) === array_keys( $sin['columnas'] ) && null === $sin['ronda'] && 2 === count( $sin['avisos'] ) && ! str_contains( $lista_sin, 'Pagos de la cuota' ), 'si el grupo no usa Tesorería: ni columnas ni filtro de pagos (con aviso), y el informe predefinido no sale' );
	$como( $u_teso );

	$ok( $crm->llamadas > 0 && 0 === $cortadas, "todas las consultas al CRM las ha respondido el simulador ({$crm->llamadas}); ninguna ha salido hacia el CRM real" );
} finally {
	if ( isset( $mod_antes ) && get_option( 'asde_ap_modulos', null ) !== $mod_antes ) {
		null === $mod_antes ? delete_option( 'asde_ap_modulos' ) : update_option( 'asde_ap_modulos', $mod_antes, false );
	}
	// ---------------------------------------------------------------------------
	// Limpieza: todo lo creado fuera; lo tocado, como estaba.
	remove_filter( 'pre_wp_mail', $capturar, 5 );
	remove_filter( 'pre_wp_mail', '__return_true' );
	remove_filter( 'send_auth_cookies', '__return_false' );
	remove_filter( 'wp_redirect', $redirigir, 1 );
	$_POST = $_GET = $_REQUEST = array();
	$crm->desactivar();
	remove_filter( 'pre_http_request', $cortar_crm, 2 );
	wp_using_ext_object_cache( $cache_externa );
	wp_set_current_user( 0 );

	$lista = implode( ',', array_map( 'intval', array_unique( $ids ) ) ) ?: '0';
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Remesas::table() . ' WHERE id > %d', $rem_antes ) ); // phpcs:ignore WordPress.DB
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Mandatos::table() . ' WHERE id > %d', $man_antes ) ); // phpcs:ignore WordPress.DB
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . MovimientosTable::table() . ' WHERE id > %d AND ronda = %s', $mov_antes, '2098-2099' ) ); // phpcs:ignore WordPress.DB
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . RegistroEmails::table() . " WHERE id > %d AND user_id IN ($lista)", $emails_antes ) ); // phpcs:ignore WordPress.DB
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Logger::table() . " WHERE id > %d AND ( user_id IN ($lista) OR ( user_id = 0 AND accion LIKE 'sepa\\_%%' ) OR ( objeto = 'user' AND objeto_id IN ($lista) ) )", $log_antes ) ); // phpcs:ignore WordPress.DB
	foreach ( array_unique( $ids ) as $uid ) {
		delete_transient( 'asde_ap_notices_' . $uid );
		Ambito::olvidar( (int) $uid );
		wp_delete_user( (int) $uid );
	}
	$familias = implode( ',', array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . FamiliasTable::table() . ' WHERE id > %d', $fam_antes ) ) ) ) ?: '0'; // phpcs:ignore WordPress.DB
	$en_falsos = "'" . implode( "','", array_map( 'esc_sql', $falsos ) ) . "'";
	$wpdb->query( 'DELETE FROM ' . FamiliasTable::table_miembros() . " WHERE familia_id IN ($familias) OR num_socio_asde IN ($en_falsos)" ); // phpcs:ignore WordPress.DB
	$wpdb->query( 'DELETE FROM ' . FamiliasTable::table() . " WHERE id IN ($familias)" ); // phpcs:ignore WordPress.DB
	$wpdb->query( 'DELETE FROM ' . SociosTable::table() . " WHERE num_socio_asde IN ($en_falsos)" ); // phpcs:ignore WordPress.DB
	foreach ( $socios_antes as $asde => $f ) {
		$ahora = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . SociosTable::table() . ' WHERE num_socio_asde = %s', $asde ), ARRAY_A ); // phpcs:ignore WordPress.DB
		if ( $ahora !== $f ) {
			$wpdb->replace( SociosTable::table(), $f );
		}
	}
	null === $sepa_antes ? delete_option( $op_sepa ) : update_option( $op_sepa, $sepa_antes, false );
	null === $tes_antes ? delete_option( 'asde_ap_tesoreria' ) : update_option( 'asde_ap_tesoreria', $tes_antes, false );
	Grupo::forzar( null );

	$quedan = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users} WHERE user_login LIKE 'prueba-sepa-%'" ); // phpcs:ignore WordPress.DB
	$ok( 0 === $quedan && $man_antes === $max_id( Mandatos::table() ) && $rem_antes === $max_id( Remesas::table() ) && $mov_antes === (int) $wpdb->get_var( 'SELECT COALESCE(MAX(id), 0) FROM ' . MovimientosTable::table() ) && get_option( $op_sepa, null ) === $sepa_antes && get_option( 'asde_ap_tesoreria', null ) === $tes_antes, 'limpieza: sin usuarios, familias, autorizaciones, remesas ni pagos de la prueba; configuración SEPA y de Tesorería como estaban' ); // phpcs:ignore WordPress.DB
}

echo $fails ? "\n$fails FALLOS\n" : "\nTODO OK (nada tocado en el CRM real; el sitio queda como estaba)\n";
