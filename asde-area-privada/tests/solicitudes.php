<?php
/**
 * Pruebas de la v0.10 (entorno LOCAL): solicitudes de acceso, email cifrado de
 * las cuentas, ventana emergente y pestañas. Ejecutar desde app/public del site Local:
 *
 *   wp eval-file <ruta completa>/wp-content/plugins/asde-area-privada/tests/solicitudes.php
 *
 * Usa un CRM simulado (tests/lib/CrmSimulado.php): ninguna petición llega al CRM
 * real y lo que se «escribe» en él se queda en memoria. Datos inventados
 * (@example.invalid). Ningún email sale (pre_wp_mail). Al terminar se borra lo
 * creado (usuarios, solicitudes, familias, registros) y se restauran la tabla de
 * números, la caché de la numeración, las sesiones del CRM y el email del grupo.
 */
defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/lib/CrmSimulado.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Auth\Ambito;
use AsdeBalears\AreaPrivada\Auth\Identificacion;
use AsdeBalears\AreaPrivada\Crm\Crypto;
use AsdeBalears\AreaPrivada\Crm\Escritura;
use AsdeBalears\AreaPrivada\Crm\PersonaRepository;
use AsdeBalears\AreaPrivada\Domain\Grupo;
use AsdeBalears\AreaPrivada\Domain\Grupos;
use AsdeBalears\AreaPrivada\Domain\NumeroSocio;
use AsdeBalears\AreaPrivada\Domain\Secciones;
use AsdeBalears\AreaPrivada\Frontend\Router;
use AsdeBalears\AreaPrivada\Frontend\Views\AltaUsuariosView;
use AsdeBalears\AreaPrivada\Frontend\Views\PersonasView;
use AsdeBalears\AreaPrivada\Frontend\Views\SolicitarView;
use AsdeBalears\AreaPrivada\Frontend\Views\SolicitudesView;
use AsdeBalears\AreaPrivada\Frontend\Views\View;
use AsdeBalears\AreaPrivada\Install\Mantenimiento;
use AsdeBalears\AreaPrivada\Install\Roles;
use AsdeBalears\AreaPrivada\Socios\Numeracion;
use AsdeBalears\AreaPrivada\Socios\SociosTable;
use AsdeBalears\AreaPrivada\Tesoreria\FamiliasTable;
use AsdeBalears\AreaPrivada\Usuarios\Contacto;
use AsdeBalears\AreaPrivada\Usuarios\EmailCuenta;
use AsdeBalears\AreaPrivada\Usuarios\RegistroEmails;
use AsdeBalears\AreaPrivada\Usuarios\SolicitudService as S;
use AsdeBalears\AreaPrivada\Usuarios\Solicitudes;
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
foreach ( get_users( array( 'search' => 'prueba-sol-*', 'search_columns' => array( 'user_login' ) ) ) as $u ) {
	exit( 'Ya existe el usuario ' . $u->user_login . " (¿una ejecución anterior interrumpida?). Bórralo antes de repetir la prueba.\n" );
}
wp_set_current_user( 0 );
$_SERVER['REMOTE_ADDR'] = '192.0.2.' . random_int( 1, 254 );

// --- Lo que se restaura al final ---------------------------------------------
$max_id       = static fn( string $t ) => (int) $wpdb->get_var( "SELECT COALESCE(MAX(id), 0) FROM $t" ); // phpcs:ignore WordPress.DB
$log_antes    = $max_id( Logger::table() );
$sol_antes    = $max_id( Solicitudes::table() );
$emails_antes = $max_id( RegistroEmails::table() );
$fam_antes    = $max_id( FamiliasTable::table() );
$socios_antes = array();
foreach ( (array) $wpdb->get_results( 'SELECT * FROM ' . SociosTable::table(), ARRAY_A ) as $f ) { // phpcs:ignore WordPress.DB
	$socios_antes[ $f['num_socio_asde'] ] = $f;
}
$clave_num = 'asde_ap_numeracion_' . $grupo['numero'];
$cache_num = get_transient( $clave_num );
delete_transient( $clave_num ); // La numeración se calcula con el CRM simulado.
$opciones  = static fn( string $patron ) => (array) $wpdb->get_results( $wpdb->prepare( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s", $patron ), OBJECT_K ); // phpcs:ignore WordPress.DB
$sesiones  = $opciones( '\_transient\_asde\_ap\_crm\_sess\_%' );
$contadores = array_keys( $opciones( '\_transient\_asde\_ap\_lim\_%' ) + $opciones( '\_transient\_asde\_ap\_int\_%' ) );
$contacto   = Grupos::email_contacto( $grupo );

add_filter( 'pre_wp_mail', '__return_true' ); // Ningún email sale; el registro de emails los anota como enviados.
add_filter( 'asde_ap_solicitud_segundos_minimos', '__return_zero' );

// --- CRM simulado con fichas inventadas -------------------------------------
$P   = array( 'Asde_Prueba_Crm', 'persona' );
$crm = new Asde_Prueba_Crm(
	array(
		$P( 1, 'Leo', 'Prueba Sanz', '2016-05-10', 'Lobatos', array( 'tutor1_nombre_c' => 'Ana', 'tutor1_apellidos_c' => 'Prueba Sanz', 'tutor1_email_c' => 'ana.prueba@example.invalid', 'tutor2_nombre_c' => 'Luis', 'tutor2_apellidos_c' => 'Sanz Prueba' ) ),
		$P( 2, 'Nora', 'Prueba Sanz', '2014-02-20', 'Scouts', array( 'stic_identification_number_c' => '99999001R', 'tutor1_nombre_c' => 'Ana', 'tutor1_apellidos_c' => 'Prueba Sanz', 'tutor1_email_c' => 'ana.prueba@example.invalid' ) ),
		$P( 3, 'Pol', 'Prueba Mas', '2011-03-03', 'Escultas', array( 'stic_identification_number_c' => '99999002-W', 'email1' => 'pol.prueba@example.invalid' ) ),
		$P( 4, 'Eva', 'Prueba Gil', '1990-01-01', 'Scouters', array( 'stic_identification_number_c' => '99999003A', 'email1' => 'eva.prueba@example.invalid' ) ),
		$P( 5, 'Iris', 'Prueba Roca', '2012-07-07', 'Scouts', array( 'stic_identification_number_c' => '99999004G', 'tutor1_nombre_c' => 'Joan', 'tutor1_apellidos_c' => 'Prueba Roca', 'tutor1_email_c' => 'joan.prueba@example.invalid' ) ),
		$P( 6, 'Leo', 'Otro García', '2016-05-10', 'Lobatos', array( 'tutor1_nombre_c' => 'Rosa', 'tutor1_apellidos_c' => 'García', 'tutor1_email_c' => 'rosa.prueba@example.invalid' ) ),
		$P( 7, 'Marc', 'Prueba Vila', '2010-09-09', 'Escultas', array( 'stic_identification_number_c' => '99999005M' ) ),
		$P( 8, 'Tom', 'Prueba Luz', '1985-05-05', 'Scouters', array( 'email1' => 'tom.prueba@example.invalid' ) ),
		$P( 9, 'Sara', 'Prueba Ros', '1992-02-02', 'Voluntariado', array( 'stic_identification_number_c' => '99999006T' ) ),
	),
	$grupo['crm_id']
);
$falsos = array_map( static fn( $p ) => $p['numerosocio_c'], $crm->personas );
// Red de seguridad: lo que no responda el simulador y vaya a la API de un CRM, se corta (y se cuenta).
$cortadas   = 0;
$cortar_crm = static function ( $pre, $args, $url ) use ( &$cortadas ) {
	if ( false === $pre && str_contains( (string) $url, '/rest.php' ) ) {
		++$cortadas;
		return new WP_Error( 'prueba', 'CRM real cortado durante la prueba' );
	}
	return $pre;
};
add_filter( 'pre_http_request', $cortar_crm, 2, 3 );
// Las cachés (transients) de la prueba, solo en la memoria de este proceso: la web
// real no ve nunca la numeración ni la sesión del CRM simulado (2026-09-28: una
// caché compartida hizo que una ficha real saliera «Sin número»).
$cache_externa = wp_using_ext_object_cache( true );
Grupo::forzar( $grupo );
$crm->activar();
Escritura::usar_para_pruebas( $crm );

// --- Cuentas de prueba ------------------------------------------------------------
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
	update_user_meta( $id, Usuarios::META_ACTIVADA_EN, current_time( 'mysql', true ) );
	foreach ( $meta as $k => $v ) {
		foreach ( (array) $v as $valor ) {
			add_user_meta( $id, $k, $valor );
		}
	}
	$ids[ $login ] = $id;
	return $id;
};
$u_scouter = $crear( 'prueba-sol-scouter', array( Roles::SCOUTER ), array( Usuarios::META_SOCIO => '990008', Usuarios::META_SECCION => 'lobatos' ) );
$u_secre   = $crear( 'prueba-sol-secre', array( Roles::SCOUTER, Roles::SECRETARIA ), array( Usuarios::META_SOCIO => '990004', Usuarios::META_SECCION => 'rovers' ) );
$u_fam5    = $crear( 'prueba-sol-fam5t', array( Roles::TUTOR ), array( Usuarios::META_REPRESENTADO => '990005', Usuarios::META_EMAIL_ORIGEN => '990005:tutor1_email_c' ) );
EmailCuenta::guardar( $u_fam5, 'joan.prueba@example.invalid' );

$serv = S::make();
$d    = static fn( string $tipo, string $doc, string $nac, string $email, string $nombre, string $menor = '' ) => array(
	'tipo'         => $tipo,
	'documento'    => $doc,
	'nacimiento'   => $nac,
	'email'        => $email,
	'nombre'       => $nombre,
	'nombre_menor' => $menor,
);
$resultado = static fn( array $v ) => $v['resultado'] . ( $v['persona'] ? ':' . $v['persona']->num_socio_asde : '' );

try {
	echo "== Parte A · Comprobar lo escrito con el CRM ==\n";
	$ok( 'ok:990002' === $resultado( $serv->verificar( '99999001R', '2014-02-20' ) ), 'documento y fecha de la misma ficha → encontrada' );
	$ok( 'ok:990002' === $resultado( $serv->verificar( ' 99.999.001-r ', '2014-02-20' ) ) && 'ok:990003' === $resultado( $serv->verificar( '99999002W', '2011-03-03' ) ), 'da igual cómo se escriba el documento (puntos, guion, minúscula), aquí o en el CRM' );
	$ok( S::NO_COINCIDE === $serv->verificar( '99999001R', '2014-02-21' )['resultado'], 'documento de una ficha con otra fecha → no coincide' );
	$ok( S::PEDIR_NOMBRE === $serv->verificar( '', '2016-05-10' )['resultado'] && S::PEDIR_NOMBRE === $serv->verificar( '12345678Z', '2016-05-10' )['resultado'], 'sin documento (o uno que no existe) y la fecha de un menor de 14 → pide su nombre' );
	$ok( 'ok:990001' === $resultado( $serv->verificar( '', '2016-05-10', 'leo prueba' ) ) && 'ok:990006' === $resultado( $serv->verificar( '', '2016-05-10', 'Leo Otro' ) ), 'con el nombre, entre dos menores nacidos el mismo día, el que toca' );
	$v = $serv->verificar( '', '2016-05-10', 'Leo Pérez' );
	$ok( S::NO_COINCIDE === $v['resultado'] && Solicitudes::POR_NOMBRE === $serv->verificar( '', '2016-05-10', 'Leo Prueba Sanz' )['via'], 'nombre que no es el de ningún menor → no coincide; el que sí, se marca «por nombre»' );
	$ok( S::NO_EXISTE === $serv->verificar( '12345678Z', '2001-01-01' )['resultado'] && S::NO_EXISTE === $serv->verificar( '', '2010-09-09' )['resultado'], 'sin ficha con ese documento ni menor de 14 con esa fecha → no existe (pedirlo por email)' );
	$ok( S::mismo_nombre( 'HUGO martín', 'Hugo', 'Martín Ferrer' ) && ! S::mismo_nombre( 'Hugo Ferrer', 'Hugo', 'Martín Ferrer' ) && ! S::mismo_nombre( 'Hugo Martín Ruiz', 'Hugo', 'Martín Ferrer' ), 'nombre: primer nombre y primer apellido, sin tildes ni mayúsculas, y nada que no sea suyo' );

	echo "\n== Parte B · Crear la solicitud ==\n";
	$r1 = $serv->solicitar( $d( Solicitudes::TUTOR, '99999001R', '2014-02-20', 'ana.nueva@example.invalid', 'Ana Prueba Sanz' ) );
	$fila = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Solicitudes::table() . ' WHERE id = %d', $r1['id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB
	$ok( S::OK === $r1['resultado'] && $fila && '990002' === $fila['socio'] && 'scouts' === $fila['secciones'] && '0' === (string) $fila['adulto'], 'familia de Nora: solicitud creada con su nº y su sección' );
	$ok( ! str_contains( (string) $fila['datos_enc'], 'ana.nueva' ) && ! str_contains( (string) $fila['datos_enc'], '99999001' ) && 'ana.nueva@example.invalid' === Solicitudes::obtener( $r1['id'] )['datos']['email'], 'lo escrito se guarda cifrado (ni el email ni el documento se ven en la base de datos)' );
	$ok( S::YA_PEDIDA === $serv->solicitar( $d( Solicitudes::TUTOR, '99999001R', '2014-02-20', 'ANA.nueva@example.invalid', 'Ana Prueba Sanz' ) )['resultado'], 'la misma solicitud otra vez → ya la teníamos' );
	$ok( S::MENOR_14 === $serv->solicitar( $d( Solicitudes::SOCIO, '', '2016-05-10', 'leo@example.invalid', 'Leo Prueba Sanz', 'Leo Prueba Sanz' ) )['resultado'], 'un menor de 14 no pide cuenta propia: la pide su familia' );
	$ok( S::ADULTO === $serv->solicitar( $d( Solicitudes::TUTOR, '99999003A', '1990-01-01', 'x@example.invalid', 'Alguien' ) )['resultado'], 'para un adulto no hay cuenta familiar' );
	$ok( S::YA_TIENE === $serv->solicitar( $d( Solicitudes::SOCIO, '99999003A', '1990-01-01', 'eva.prueba@example.invalid', 'Eva Prueba Gil' ) )['resultado'], 'quien ya tiene cuenta propia activa → se le dice que entre o recupere la contraseña' );
	$r6 = $serv->solicitar( $d( Solicitudes::SOCIO, '99999002W', '2011-03-03', 'pol.prueba@example.invalid', 'Pol Prueba Mas' ) );
	$r7 = $serv->solicitar( $d( Solicitudes::TUTOR, '', '2016-05-10', 'ana.prueba@example.invalid', 'Ana Prueba Sanz', 'Leo Prueba Sanz' ) );
	$r8 = $serv->solicitar( $d( Solicitudes::TUTOR, '99999004G', '2012-07-07', 'joan.nuevo@example.invalid', 'Joan Prueba Roca' ) );
	$r9 = $serv->solicitar( $d( Solicitudes::SOCIO, '99999006T', '1992-02-02', 'sara.prueba@example.invalid', 'Sara Prueba Ros' ) );
	$ok( S::OK === $r6['resultado'] && S::OK === $r7['resultado'] && S::OK === $r8['resultado'] && S::OK === $r9['resultado'] && ! empty( Solicitudes::obtener( $r9['id'] )['adulto'] ), 'participante de 15, menor sin DNI (por nombre), familia con cuenta ya creada y voluntaria adulta: solicitudes creadas' );

	echo "\n== Parte C · Formulario público «Solicitar acceso» ==\n";
	$_SERVER['REMOTE_ADDR'] = '192.0.2.' . random_int( 1, 254 );
	Grupos::fijar_email_contacto( $grupo['numero'], 'contacto.prueba@example.invalid' );
	Grupos::olvidar();
	Grupo::forzar( Grupos::obtener( $grupo['id'] ) );
	$estado = new ReflectionProperty( SolicitarView::class, 'estado' );
	$sello  = new ReflectionMethod( SolicitarView::class, 'sello' );
	$form   = static function ( array $campos ) use ( $estado, $sello, $grupo ): string {
		$estado->setValue( null, array() );
		$_POST = array_merge(
			array(
				'asde_action' => 'solicitar',
				'_wpnonce'    => wp_create_nonce( 'asde_solicitar' ),
				'sello'       => $sello->invoke( null ),
				'grupo'       => $grupo['id'],
				'tipo'        => Solicitudes::TUTOR,
				'web'         => '',
			),
			$campos
		);
		( new SolicitarView() )->handle_post();
		$html  = ( new SolicitarView() )->render();
		$_POST = array();
		return $html;
	};
	$antes = count( Solicitudes::listado() );
	$h     = $form( array( 'web' => 'http://spam.example', 'documento' => '99999005M', 'nacimiento' => '2010-09-09', 'email' => 'marc.familia@example.invalid', 'nombre' => 'Familia Vila' ) );
	$ok( str_contains( $h, 'No hemos podido comprobar' ) && count( Solicitudes::listado() ) === $antes, 'campo trampa relleno (un programa) → respuesta genérica y no se crea nada' );
	$h = $form( array( 'documento' => '99999005M', 'nacimiento' => '2010-09-09', 'email' => 'no-es-un-email', 'nombre' => 'Familia Vila' ) );
	$ok( str_contains( $h, 'Escribe un email válido' ), 'email no válido → se pide otra vez, sin consultar el CRM' );
	$h = $form( array( 'documento' => '99999005M', 'nacimiento' => '2010-09-09', 'email' => 'marc.familia@example.invalid', 'nombre' => 'Familia Vila' ) );
	$ok( str_contains( $h, 'Solicitud enviada' ) && str_contains( $h, 'marc.familia@example.invalid' ) && count( Solicitudes::listado() ) === $antes + 1, 'datos correctos → «Solicitud enviada», con el email al que llegará la invitación' );
	$h = $form( array( 'documento' => '11111111H', 'nacimiento' => '2001-01-01', 'email' => 'nadie@example.invalid', 'nombre' => 'Nadie' ) );
	$ok( str_contains( $h, 'No hemos podido comprobar' ) && str_contains( $h, 'mailto:contacto.prueba@example.invalid' ), 'no cuadra → se le da el email de contacto del grupo para pedirlo por email' );
	$h = $form( array( 'documento' => '', 'nacimiento' => '2016-05-10', 'email' => 'rosa.prueba@example.invalid', 'nombre' => 'Rosa García' ) );
	$ok( str_contains( $h, 'name="nombre_menor"' ) && str_contains( $h, 'menor de 14 años' ) && str_contains( $h, 'Sin nº de documento' ), 'sin documento y fecha de un menor de 14 → aparece el campo del nombre del socio/a' );
	$h = $form( array( 'tipo' => Solicitudes::SOCIO, 'documento' => '', 'nacimiento' => '2016-05-10', 'email' => 'rosa.prueba@example.invalid', 'nombre' => 'Rosa García', 'nombre_menor' => 'Leo Otro García' ) );
	$ok( str_contains( $h, 'entran con la cuenta de su familia' ) && preg_match( '/value="' . Solicitudes::TUTOR . '"\s+checked/', $h ), '«soy el socio» con un menor de 14 → se le explica y se marca «soy su madre, padre o tutor/a»' );
	for ( $i = 0; $i < 4; $i++ ) {
		$form( array( 'documento' => '2222222' . $i . 'X', 'nacimiento' => '2002-02-02', 'email' => "nadie$i@example.invalid", 'nombre' => 'Nadie' ) );
	}
	$h = $form( array( 'documento' => '99999005M', 'nacimiento' => '2010-09-09', 'email' => 'otra@example.invalid', 'nombre' => 'Otra' ) );
	$ok( str_contains( $h, 'varias solicitudes seguidas' ), 'más de 8 intentos por hora desde un dispositivo → se para' );
	wp_set_current_user( 0 );
	$ok( str_contains( ( new SolicitarView() )->render(), 'name="nacimiento"' ), 'la página «Solicitar acceso» es pública' );

	echo "\n== Parte D · Quién ve cada solicitud ==\n";
	wp_set_current_user( $u_secre );
	Ambito::olvidar( $u_secre );
	$pendientes_secre = Solicitudes::contar_pendientes( Ambito::actual() );
	$ok( $pendientes_secre >= 6, "Secretaría ve todas las pendientes del grupo ($pendientes_secre)" );
	$ok( Solicitudes::en_ambito( Solicitudes::obtener( $r9['id'] ), Ambito::actual() ), 'Secretaría ve también la de una voluntaria adulta' );
	wp_set_current_user( $u_scouter );
	Ambito::olvidar( $u_scouter );
	$a_scouter = Ambito::actual();
	$ok( 1 === Solicitudes::contar_pendientes( $a_scouter ) && Solicitudes::en_ambito( Solicitudes::obtener( $r7['id'] ), $a_scouter ) && ! Solicitudes::en_ambito( Solicitudes::obtener( $r1['id'] ), $a_scouter ), 'un scouter de Lobatos ve solo la del lobato (no la de Scouts)' );
	$ok( ! Solicitudes::en_ambito( Solicitudes::obtener( $r9['id'] ), $a_scouter ), 'las de scouters y voluntariado no las ve un scouter: las da Secretaría o Coordinación' );
	$tabs = View::pestanas( $a_scouter );
	$ok( isset( $tabs['solicitudes'] ) && str_contains( $tabs['solicitudes'], '(1)' ) && ! isset( $tabs['personas'] ) && ! isset( $tabs['alta'] ) && ! isset( $tabs['seguridad'] ), 'pestañas del scouter: «Solicitudes (1)», sin Personas ni Alta; Seguridad va junto al nombre' );
	$lista = ( new SolicitudesView() )->render();
	$ok( str_contains( $lista, 'Leo Prueba Sanz' ) && ! str_contains( $lista, 'Nora Prueba Sanz' ) && str_contains( $lista, 'grupo=' . $grupo['numero'] ), 'su lista: la del lobato y el enlace para las familias con el grupo' );
	wp_set_current_user( $u_secre );
	$tabs = View::pestanas( Ambito::actual() );
	$ok( isset( $tabs['personas'], $tabs['solicitudes'], $tabs['usuarios'] ) && ! isset( $tabs['alta'] ) && ! isset( $tabs['seguridad'] ), 'pestañas de Secretaría: Personas, Solicitudes y Usuarios; «Dar acceso» está dentro de Personas y Usuarios' );

	echo "\n== Parte E · Aprobar ==\n";
	$crm_escrito = count( $crm->escrituras );
	$a1 = $serv->aprobar( Solicitudes::obtener( $r1['id'] ), $u_secre );
	$c1 = $a1['usuario'];
	$ok( $a1['ok'] && $c1 && str_ends_with( $c1->user_login, 'T' ) && 'ana.nueva@example.invalid' === $crm->campo( '990002', 'tutor1_email_c' ), 'familia de Nora con otro email: se guarda en el CRM (tutor/a 1, por su nombre) y se crea la cuenta familiar' );
	$ok( 'ana.nueva@example.invalid' === EmailCuenta::leer( $c1->ID ) && 'tutor1_email_c' === ( Usuarios::email_origen( $c1->ID )['campo'] ?? '' ) && $a1['email_crm'], 'la cuenta entra con el email nuevo, que queda ligado al campo del CRM' );
	$e1 = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . RegistroEmails::table() . ' WHERE user_id = %d ORDER BY id DESC LIMIT 1', $c1->ID ), ARRAY_A ); // phpcs:ignore WordPress.DB
	$ok( $e1 && 'alta' === $e1['tipo'] && RegistroEmails::ENVIADO === $e1['estado'] && ! empty( json_decode( (string) $e1['datos'], true )['bienvenida'] ) && str_contains( RegistroEmails::vista_previa( $e1 ), 'Te damos la bienvenida' ), 'le llega la invitación con la bienvenida' );
	$s1 = Solicitudes::obtener( $r1['id'] );
	$ok( Solicitudes::APROBADA === $s1['estado'] && $c1->ID === $s1['user_id'] && $u_secre === $s1['revisada_por'], 'la solicitud queda aprobada, con quién y qué cuenta' );
	$ok( $c1->user_login === Identificacion::usuario_para( 'ANA.nueva@example.invalid', $grupo, 'x' ), 'ya se puede entrar con ese email (por su huella, sin consultar el CRM)' );

	$a6 = $serv->aprobar( Solicitudes::obtener( $r6['id'] ), $u_secre );
	$ok( $a6['ok'] && ! $a6['email_crm'] && count( $crm->escrituras ) === $crm_escrito + 1 && 'email1' === ( Usuarios::email_origen( $a6['usuario']->ID )['campo'] ?? '' ) && in_array( Roles::PARTICIPANTE, (array) $a6['usuario']->roles, true ), 'participante con el mismo email que el CRM: sin tocar el CRM, cuenta de participante' );

	$s8 = Solicitudes::obtener( $r8['id'] );
	$a8 = $serv->aprobar( $s8, $u_secre );
	$ok( ! $a8['ok'] && str_contains( implode( ' ', $a8['errores'] ), 'Sustituir' ), 'ya hay cuenta familiar: sin marcar «Sustituir» no se toca' );
	$a8 = $serv->aprobar( $s8, $u_secre, array( 'sustituir' => true ) );
	$e8 = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . RegistroEmails::table() . ' WHERE user_id = %d ORDER BY id DESC LIMIT 1', $u_fam5 ), ARRAY_A ); // phpcs:ignore WordPress.DB
	$ok( $a8['ok'] && 'joan.nuevo@example.invalid' === EmailCuenta::leer( $u_fam5 ) && 'joan.nuevo@example.invalid' === $crm->campo( '990005', 'tutor1_email_c' ) && $e8 && 'reset' === $e8['tipo'] && ! empty( json_decode( (string) $e8['datos'], true )['bienvenida'] ), 'marcado «Sustituir»: la cuenta familiar pasa al email nuevo (también en el CRM) y le llega el enlace para elegir contraseña, con la bienvenida' );

	$a9 = $serv->aprobar( Solicitudes::obtener( $r9['id'] ), $u_secre );
	$ok( ! $a9['ok'] && str_contains( implode( ' ', $a9['errores'] ), 'Dar acceso' ), 'voluntaria adulta: se aprueba desde «Dar acceso», con sus roles' );

	$a7 = $serv->aprobar( Solicitudes::obtener( $r7['id'] ), $u_secre );
	$ok( $a7['ok'] && 'ana.prueba@example.invalid' === EmailCuenta::leer( $a7['usuario']->ID ) && '990001' === Usuarios::representados( $a7['usuario']->ID )[0], 'lobato sin DNI (por nombre): cuenta familiar con el email que ya tenía la ficha' );

	$marc = array_values( array_filter( Solicitudes::listado(), static fn( $s ) => '990007' === $s['socio'] ) )[0] ?? null;
	$ok( $marc && $serv->rechazar( $marc, $u_secre, 'Prueba' ) && Solicitudes::RECHAZADA === Solicitudes::obtener( $marc['id'] )['estado'], 'rechazar: queda rechazada, con el motivo' );

	echo "\n== Parte F · Voluntaria adulta desde «Dar acceso» ==\n";
	add_filter( 'send_auth_cookies', '__return_false' );
	$redirigir = static function ( $destino ) {
		throw new RuntimeException( 'asde-redirect:' . $destino );
	};
	add_filter( 'wp_redirect', $redirigir, 1 );
	$_POST = array(
		'asde_action' => 'alta_persona',
		'_wpnonce'    => wp_create_nonce( 'asde_alta_persona_990009' ),
		'socio'       => '990009',
		'roles'       => array( Roles::TESORERIA ),
		'secciones'   => array( Secciones::SIN_SECCION ),
		'campo_email' => 'solicitud',
		'solicitud'   => (string) $r9['id'],
	);
	$_REQUEST = $_POST;
	try {
		( new AltaUsuariosView() )->handle_post();
	} catch ( RuntimeException $e ) {
		// Redirige al terminar.
	}
	$_POST    = array();
	$_REQUEST = array();
	remove_filter( 'wp_redirect', $redirigir, 1 );
	$sara = Usuarios::por_socio( '990009' );
	$ok( $sara && 'sara.prueba@example.invalid' === EmailCuenta::leer( $sara->ID ) && 'sara.prueba@example.invalid' === $crm->campo( '990009', 'email1' ) && Solicitudes::APROBADA === Solicitudes::obtener( $r9['id'] )['estado'] && in_array( Roles::TESORERIA, (array) $sara->roles, true ), 'con el email de la solicitud: se guarda en su ficha del CRM, cuenta de Tesorería y solicitud aprobada' );

	echo "\n== Parte F2 · Solicitud de quien ya tiene cuenta (v0.12.3) ==\n";
	wp_set_current_user( $u_secre );
	Ambito::olvidar( $u_secre );
	add_filter( 'wp_redirect', $redirigir, 1 );
	$p9    = PersonaRepository::make()->obtener( '990009' );
	$sara0 = Usuarios::por_socio( '990009' );
	$id_a  = Solicitudes::crear( Solicitudes::SOCIO, Solicitudes::POR_DOCUMENTO, $p9, $d( Solicitudes::SOCIO, '99999006T', '1992-02-02', 'sara.otra@example.invalid', 'Sara Prueba Ros' ) );
	$id_b  = Solicitudes::crear( Solicitudes::SOCIO, Solicitudes::POR_DOCUMENTO, $p9, $d( Solicitudes::SOCIO, '99999006T', '1992-02-02', 'sara.otra2@example.invalid', 'Sara Prueba Ros' ) );
	$_GET = array( 'socio' => '990009', 'solicitud' => (string) $id_a );
	$html = ( new AltaUsuariosView() )->render();
	$_GET = array();
	$ok( $sara0 && str_contains( $html, 'solicitud de acceso pendiente' ) && str_contains( $html, 'name="solicitud" value="' . $id_a . '"' ) && str_contains( $html, 'cerrar_solicitud' ), 'quien ya tiene cuenta: la pantalla de «Dar acceso» avisa de su solicitud pendiente y la lleva en el formulario' );
	$posta = static function ( array $campos ) use ( $redirigir ) {
		$_POST    = $campos;
		$_REQUEST = $_POST;
		$destino  = '';
		try {
			( new AltaUsuariosView() )->handle_post();
		} catch ( RuntimeException $e ) {
			$destino = $e->getMessage();
		}
		$_POST    = array();
		$_REQUEST = array();
		return $destino;
	};
	$posta(
		array(
			'asde_action' => 'alta_persona',
			'_wpnonce'    => wp_create_nonce( 'asde_alta_persona_990009' ),
			'socio'       => '990009',
			'roles'       => array( Roles::TESORERIA, Roles::SECRETARIA ),
			'secciones'   => array( Secciones::SIN_SECCION ),
			'solicitud'   => (string) $id_a,
		)
	);
	$sa = Solicitudes::obtener( $id_a );
	$ok( Solicitudes::APROBADA === $sa['estado'] && (int) $sara0->ID === (int) $sa['user_id'] && in_array( Roles::SECRETARIA, (array) get_userdata( $sara0->ID )->roles, true ), 'guardar sus roles desde la solicitud: la solicitud queda aprobada (antes se quedaba pendiente) y la cuenta es la suya' );
	$ok( 'sara.prueba@example.invalid' === EmailCuenta::leer( $sara0->ID ) && 'sara.prueba@example.invalid' === $crm->campo( '990009', 'email1' ), 'y ni el email de su cuenta ni el de la ficha del CRM cambian' );
	$destino = $posta(
		array(
			'asde_action' => 'cerrar_solicitud',
			'_wpnonce'    => wp_create_nonce( 'asde_cerrar_solicitud_990009' ),
			'socio'       => '990009',
			'solicitud'   => (string) $id_b,
		)
	);
	$sb = Solicitudes::obtener( $id_b );
	$ok( Solicitudes::APROBADA === $sb['estado'] && (int) $sara0->ID === (int) $sb['user_id'] && str_contains( $destino, 'asde-redirect:' ), 'si ya tiene el acceso que pide: «cerrar la solicitud» la resuelve sin cambiar nada' );
	$id_c = Solicitudes::crear( Solicitudes::SOCIO, Solicitudes::POR_DOCUMENTO, $p9, $d( Solicitudes::SOCIO, '99999006T', '1992-02-02', 'sara.otra3@example.invalid', 'Sara Prueba Ros' ) );
	$posta(
		array(
			'asde_action' => 'cerrar_solicitud',
			'_wpnonce'    => wp_create_nonce( 'asde_cerrar_solicitud_990001' ),
			'socio'       => '990001',
			'solicitud'   => (string) $id_c,
		)
	);
	$ok( Solicitudes::PENDIENTE === Solicitudes::obtener( $id_c )['estado'], 'cerrar con otro nº de socio/a (o sin cuenta) no resuelve nada' );
	remove_filter( 'wp_redirect', $redirigir, 1 );
	delete_transient( 'asde_ap_notices_' . $u_secre );

	echo "\n== Parte I · Dos tutores, dos cuentas (v0.12, P-35) ==\n";
	$capturados = array();
	$capturar   = static function ( $pre, $atts ) use ( &$capturados ) {
		$capturados[] = $atts;
		return true;
	};
	add_filter( 'pre_wp_mail', $capturar, 5, 2 );
	$ok( '76849905T2' === Identificacion::normalizar( '0 76849905 t2' ) && '76849905' === NumeroSocio::socio_de_usuario_tutor( '76849905T2' ) && 2 === NumeroSocio::orden_tutor( '76849905T2' ) && 1 === NumeroSocio::orden_tutor( '76849905T' ) && 0 === NumeroSocio::orden_tutor( '76849905' ) && '76849905T2' === NumeroSocio::usuario_tutor( '76849905', 2 ) && $grupo['numero'] === NumeroSocio::grupo_de_login( '76849905T2', array( $grupo['numero'] ) ), 'el usuario del 2.º tutor/a es nº + T2 (76849905T2): se reconoce, se normaliza al entrar y se le asigna su grupo' );

	$r10  = $serv->solicitar( $d( Solicitudes::TUTOR, '99999004G', '2012-07-07', 'marta.prueba@example.invalid', 'Marta Prueba Roca' ) );
	$s10  = Solicitudes::obtener( $r10['id'] );
	$p5   = PersonaRepository::make()->obtener( '990005' );
	$prop = $serv->propuesta( $s10, $p5, Usuarios::cuentas_familiares( array( '990005' ) ) );
	$ok( S::OK === $r10['resultado'] && S::MODO_NUEVA === $prop['modo'] && $prop['puede_nueva'] && 'tutor2_email_c' === $prop['campo'], 'otro tutor/a de una familia que ya tiene cuenta: se propone crearle su propia cuenta, con su email en el otro campo del CRM' );
	$sin = $serv->aprobar( $s10, $u_secre );
	$ok( ! $sin['ok'] && str_contains( implode( ' ', $sin['errores'] ), 'otro tutor' ) && 1 === count( Usuarios::cuentas_familiares( array( '990005' ) ) ), 'sin elegir entre «otro tutor/a» y «la misma persona con otro email», no se toca nada' );
	$nueva = $serv->aprobar( $s10, $u_secre, array( 'modo' => S::MODO_NUEVA ) );
	$c2    = $nueva['usuario'];
	$ok( $nueva['ok'] && $c2 && '76849905T2' === $c2->user_login && in_array( Roles::TUTOR, (array) $c2->roles, true ) && array( '990005' ) === Usuarios::representados( $c2->ID ), 'otro tutor/a: se crea su propia cuenta 76849905T2, que representa al mismo menor' );
	$ok( 'marta.prueba@example.invalid' === $crm->campo( '990005', 'tutor2_email_c' ) && 'joan.nuevo@example.invalid' === $crm->campo( '990005', 'tutor1_email_c' ) && 'tutor2_email_c' === ( Usuarios::email_origen( $c2->ID )['campo'] ?? '' ), 'su email va al campo del tutor/a 2 del CRM, sin tocar el del primero' );
	$ok( 'marta.prueba@example.invalid' === EmailCuenta::leer( $c2->ID ) && 'joan.nuevo@example.invalid' === EmailCuenta::leer( $u_fam5 ) && $c2->user_login === Identificacion::usuario_para( 'marta.prueba@example.invalid', $grupo, 'x' ) && 'prueba-sol-fam5t' === Identificacion::usuario_para( 'joan.nuevo@example.invalid', $grupo, 'x' ), 'cada tutor/a entra con su email, a su cuenta' );
	$e2 = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . RegistroEmails::table() . ' WHERE user_id = %d ORDER BY id DESC LIMIT 1', $c2->ID ), ARRAY_A ); // phpcs:ignore WordPress.DB
	$ok( $e2 && 'alta' === $e2['tipo'] && ! empty( json_decode( (string) $e2['datos'], true )['bienvenida'] ), 'al 2.º tutor/a le llega su invitación con la bienvenida' );
	$fam5 = FamiliasTable::de_socio( '990005' );
	Ambito::olvidar( $c2->ID );
	$ok( $fam5 && array( '990005' ) === array_map( 'strval', array_keys( Ambito::de( $c2 )->representados ) ) && array( '76849905T2', 'prueba-sol-fam5t' ) === array_map( static fn( $u ) => $u->user_login, Usuarios::cuentas_familiares( array( '990005' ) ) ), 'las dos cuentas comparten la familia de Tesorería y ven al mismo menor' );

	$r11 = $serv->solicitar( $d( Solicitudes::TUTOR, '99999004G', '2012-07-07', 'tercero.prueba@example.invalid', 'Tercero Prueba Roca' ) );
	$s11 = Solicitudes::obtener( $r11['id'] );
	$p5  = PersonaRepository::make()->obtener( '990005' );
	$pr3 = $serv->propuesta( $s11, $p5, Usuarios::cuentas_familiares( array( '990005' ) ) );
	$e3  = $serv->aprobar( $s11, $u_secre, array( 'modo' => S::MODO_NUEVA ) );
	$ok( ! $pr3['puede_nueva'] && S::MODO_SUSTITUIR === $pr3['modo'] && ! $e3['ok'] && str_contains( implode( ' ', $e3['errores'] ), 'dos cuentas' ), 'con las dos cuentas ya creadas no se crea una tercera: solo se puede sustituir el email de una' );
	$e4 = $serv->aprobar( $s11, $u_secre, array( 'modo' => S::MODO_SUSTITUIR, 'cuenta' => $c2->user_login, 'campo' => 'tutor1_email_c' ) );
	$ok( ! $e4['ok'] && str_contains( implode( ' ', $e4['errores'] ), 'email de otra cuenta' ) && 'joan.nuevo@example.invalid' === $crm->campo( '990005', 'tutor1_email_c' ), 'nunca se pisa en el CRM el email de otra cuenta de la familia' );
	$sus = $serv->aprobar( $s11, $u_secre, array( 'modo' => S::MODO_SUSTITUIR, 'cuenta' => $c2->user_login ) );
	$ok( $sus['ok'] && 'tercero.prueba@example.invalid' === EmailCuenta::leer( $c2->ID ) && 'tercero.prueba@example.invalid' === $crm->campo( '990005', 'tutor2_email_c' ) && 'joan.nuevo@example.invalid' === $crm->campo( '990005', 'tutor1_email_c' ) && 'joan.nuevo@example.invalid' === EmailCuenta::leer( $u_fam5 ), 'sustituir el email de la 2.ª cuenta: cambia el suyo (y su campo del CRM) y el de la 1.ª cuenta no se toca' );
	$misma = $serv->aprobar( Solicitudes::obtener( $serv->solicitar( $d( Solicitudes::TUTOR, '99999004G', '2012-07-07', 'JOAN.nuevo@example.invalid', 'Joan Prueba Roca' ) )['id'] ), $u_secre );
	$ok( $misma['ok'] && $misma['usuario']->ID === $u_fam5 && 2 === count( Usuarios::cuentas_familiares( array( '990005' ) ) ), 'si el email ya es el de una de las cuentas, es la misma persona: se le vuelve a mandar el acceso, sin crear otra cuenta' );

	// Desde «Dar acceso» (Secretaría): la cuenta del otro tutor/a de la familia de Nora.
	$alta   = \AsdeBalears\AreaPrivada\Usuarios\AltaService::make();
	$igual  = $alta->alta_tutor( '990002', array(), 'tutor1_email_c', null, true );
	$sin_em = $alta->alta_tutor( '990002', array(), 'tutor2_email_c', null, true );
	$ok( ! $igual->ok && str_contains( implode( ' ', $igual->errores ), 'misma persona' ) && ! $sin_em->ok && str_contains( implode( ' ', $sin_em->errores ), 'Elige un email' ), 'Dar acceso: el otro tutor/a no puede usar el email de la primera cuenta, ni ninguno si la ficha no tiene otro' );
	$crm->set_entry( 'Contacts', $crm->personas[1]['id'], array( 'tutor2_nombre_c' => 'Pau', 'tutor2_apellidos_c' => 'Prueba Sanz', 'tutor2_email_c' => 'pau.prueba@example.invalid' ) );
	$otro = $alta->alta_tutor( '990002', array(), 'tutor2_email_c', null, true );
	$ok( $otro->ok && '76849902T2' === $otro->usuario->user_login && 'pau.prueba@example.invalid' === EmailCuenta::leer( $otro->usuario->ID ) && $otro->invitacion_enviada && array( '990002' ) === Usuarios::representados( $otro->usuario->ID ), 'Dar acceso: con otro email en la ficha, se crea la cuenta 76849902T2 y se le envía la invitación' );
	$mas = $alta->alta_tutor( '990002', array(), 'tutor2_email_c', null, true );
	$ok( ! $mas->ok && str_contains( implode( ' ', $mas->errores ), 'ya tiene las 2 cuentas' ), 'no hay más de dos cuentas por familia' );
	remove_filter( 'pre_wp_mail', $capturar, 5 );

	echo "\n== Parte J · Avisar por email cuando se rechaza (v0.12, P-36) ==\n";
	$capturados = array();
	add_filter( 'pre_wp_mail', $capturar, 5, 2 );
	unset( $_GET['solicitud'] );
	$rj = $serv->solicitar( $d( Solicitudes::TUTOR, '', '2016-05-10', 'rosa.rechazo@example.invalid', 'Rosa García', 'Leo Otro García' ) );
	$sj = Solicitudes::obtener( $rj['id'] );
	$rr = $serv->rechazar( $sj, $u_secre, 'No es su familia' );
	$mail = $capturados[0] ?? array();
	$para = is_array( $mail['to'] ?? null ) ? ( $mail['to'][0] ?? '' ) : ( $mail['to'] ?? '' );
	$ok( S::OK === $rj['resultado'] && $rr && 'enviado' === $serv->aviso['estado'] && 'rosa.rechazo@example.invalid' === $para && str_contains( (string) ( $mail['subject'] ?? '' ), 'solicitud de acceso' ), 'rechazar avisa por email a quien la pidió, al email escrito en la solicitud' );
	$ok( str_contains( (string) ( $mail['message'] ?? '' ), 'no hemos podido darte acceso' ) && str_contains( (string) ( $mail['message'] ?? '' ), 'contacto.prueba@example.invalid' ) && str_contains( (string) ( $mail['message'] ?? '' ), 'Hola, Rosa' ) && ! str_contains( (string) ( $mail['message'] ?? '' ), 'No es su familia' ) && in_array( 'Reply-To: contacto.prueba@example.invalid', (array) ( $mail['headers'] ?? array() ), true ), 'texto genérico: sin el motivo (es solo para el grupo), con el email de contacto del grupo, y se le puede responder' );
	$fila_r = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . RegistroEmails::table() . ' WHERE tipo = %s AND login = %s ORDER BY id DESC LIMIT 1', RegistroEmails::RECHAZO, 'solicitud ' . $rj['id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB
	$ok( $fila_r && RegistroEmails::ENVIADO === $fila_r['estado'] && 0 === (int) $fila_r['user_id'] && ! str_contains( $fila_r['destino'] . $fila_r['datos'] . $fila_r['asunto'], 'rosa.rechazo' ) && str_contains( RegistroEmails::vista_previa( $fila_r ), 'Hola, Rosa' ), 'queda en el registro de emails (destino enmascarado, sin el email ni el motivo) y se puede ver cómo era' );
	$rj2 = $serv->solicitar( $d( Solicitudes::TUTOR, '99999001R', '2014-02-20', 'ana.sinaviso@example.invalid', 'Ana Prueba Sanz' ) );
	$capturados = array();
	$ok( $serv->rechazar( Solicitudes::obtener( $rj2['id'] ), $u_secre, '', false ) && ! $capturados && '' === $serv->aviso['estado'], 'con la casilla «Avisar» sin marcar, no se envía nada' );
	$rj3 = $serv->solicitar( $d( Solicitudes::TUTOR, '99999001R', '2014-02-20', 'no-es-un-email', 'Ana Prueba Sanz' ) );
	$ok( $serv->rechazar( Solicitudes::obtener( $rj3['id'] ), $u_secre, 'Sin email' ) && ! $capturados && 'sin_email' === $serv->aviso['estado'], 'sin un email válido en la solicitud: se rechaza y se dice que no se ha podido avisar' );
	$rj4 = $serv->solicitar( $d( Solicitudes::TUTOR, '99999001R', '2014-02-20', 'ana.rechazo4@example.invalid', 'Ana Prueba Sanz' ) );
	add_filter( 'wp_redirect', $redirigir, 1 );
	wp_set_current_user( $u_secre );
	$_POST = array( 'asde_action' => 'rechazar_solicitud', '_wpnonce' => wp_create_nonce( 'asde_solicitud_' . $rj4['id'] ), 'solicitud' => (string) $rj4['id'], 'motivo' => 'Desde la pantalla', 'avisar' => '1' );
	$_REQUEST = $_POST;
	try {
		( new SolicitudesView() )->handle_post();
	} catch ( RuntimeException $e ) {
		// Redirige al terminar.
	}
	$_POST    = array();
	$_REQUEST = array();
	remove_filter( 'wp_redirect', $redirigir, 1 );
	$avisos_r = (string) wp_json_encode( get_transient( 'asde_ap_notices_' . $u_secre ), JSON_UNESCAPED_UNICODE );
	delete_transient( 'asde_ap_notices_' . $u_secre );
	$ok( Solicitudes::RECHAZADA === Solicitudes::obtener( $rj4['id'] )['estado'] && 1 === count( $capturados ) && str_contains( $avisos_r, 'avisado por email' ), 'la pantalla de revisión (casilla marcada) rechaza, envía el aviso y lo dice' );
	$rj5 = $serv->solicitar( $d( Solicitudes::TUTOR, '99999001R', '2014-02-20', 'ana.detalle@example.invalid', 'Ana Prueba Sanz' ) );
	View::en_modal( true );
	$_GET['solicitud'] = (string) $rj5['id'];
	$detalle           = ( new SolicitudesView() )->render();
	unset( $_GET['solicitud'] );
	View::en_modal( false );
	if ( getenv( 'ASDE_VOLCAR' ) ) { // Solo para revisar el diseño en un navegador.
		file_put_contents( getenv( 'ASDE_VOLCAR' ) . '/solicitud-detalle.html', str_replace( '<details class="asde-rechazar"', '<details open class="asde-rechazar"', $detalle ) );
	}
	$ok( str_contains( $detalle, 'name="avisar"' ) && str_contains( $detalle, 'contacto.prueba@example.invalid' ) && str_contains( $detalle, 'name="modo"' ), 'el detalle de una solicitud tiene la casilla «Avisar por email» (marcada) y, si la familia ya tiene cuenta, la elección entre otro tutor/a y la misma persona' );
	Grupos::fijar_email_contacto( $grupo['numero'], '' );
	Grupos::olvidar();
	Grupo::forzar( Grupos::obtener( $grupo['id'] ) );
	$capturados = array();
	$rj6 = $serv->solicitar( $d( Solicitudes::TUTOR, '99999001R', '2014-02-20', 'ana.sincontacto@example.invalid', 'Ana Prueba Sanz' ) );
	$serv->rechazar( Solicitudes::obtener( $rj6['id'] ), $u_secre, '' );
	$ok( str_contains( (string) ( $capturados[0]['message'] ?? '' ), 'Habla con la Secretaría' ) && ! in_array( 'Reply-To', array_map( static fn( $h ) => strtok( (string) $h, ':' ), (array) ( $capturados[0]['headers'] ?? array() ) ), true ), 'sin email de contacto en el grupo, el aviso dice que hable con la Secretaría' );
	Grupos::fijar_email_contacto( $grupo['numero'], 'contacto.prueba@example.invalid' );
	Grupos::olvidar();
	Grupo::forzar( Grupos::obtener( $grupo['id'] ) );
	remove_filter( 'pre_wp_mail', $capturar, 5 );

	echo "\n== Parte G · Email guardado cifrado ==\n";
	$raw = (string) get_user_meta( $c1->ID, EmailCuenta::META_CIFRADO, true );
	$ok( '' !== $raw && ! str_contains( $raw, 'ana.nueva' ) && null === Crypto::decrypt_contexto( $raw, 'email|' . $u_secre ) && 'ana.nueva@example.invalid' === Crypto::decrypt_contexto( $raw, 'email|' . $c1->ID ), 'cifrado con una clave ligada al id del usuario: con el id de otro no se descifra' );
	$ok( EmailCuenta::huella( 'Ana.Nueva@Example.INVALID ' ) === EmailCuenta::huella( 'ana.nueva@example.invalid' ) && array( $c1->ID ) === array_map( static fn( $u ) => $u->ID, EmailCuenta::usuarios( 'ANA.NUEVA@example.invalid' ) ), 'se encuentra por su huella, sin distinguir mayúsculas' );
	$crm->set_entry( 'Contacts', $crm->personas[1]['id'], array( 'tutor1_email_c' => 'ana.otra@example.invalid' ) );
	$ok( 'ana.otra@example.invalid' === Contacto::email( get_userdata( $c1->ID ) ) && 'ana.otra@example.invalid' === EmailCuenta::leer( $c1->ID ), 'si cambia en el CRM, al escribirle se usa el nuevo y se guarda (el CRM manda)' );
	$crm->set_entry( 'Contacts', $crm->personas[2]['id'], array( 'email1' => 'pol.nuevo@example.invalid' ) );
	$n_sync = Mantenimiento::sincronizar_emails( PersonaRepository::make() );
	$ok( $n_sync >= 1 && 'pol.nuevo@example.invalid' === EmailCuenta::leer( $a6['usuario']->ID ), "la tarea diaria pone al día los emails que han cambiado en el CRM ($n_sync)" );
	$crm->caido = true;
	$ok( 'pol.nuevo@example.invalid' === Contacto::email( get_userdata( $a6['usuario']->ID ) ) && $a6['usuario']->user_login === Identificacion::usuario_para( 'pol.nuevo@example.invalid', $grupo, 'x' ), 'con el CRM caído: el email guardado sirve para escribirle y para entrar' );
	$crm->caido = false;
	EmailCuenta::guardar( $u_fam5, null );
	$ok( ! EmailCuenta::tiene( $u_fam5 ) && null === EmailCuenta::leer( $u_fam5 ), 'se puede borrar el email de una cuenta' );
	$wpdb->update( Solicitudes::table(), array( 'revisada_en' => gmdate( 'Y-m-d H:i:s', time() - 40 * DAY_IN_SECONDS ) ), array( 'id' => $marc['id'] ) );
	Solicitudes::purgar();
	$ok( '' === Solicitudes::obtener( $marc['id'] )['datos']['email'] && '' !== Solicitudes::obtener( $r1['id'] )['datos']['email'], 'a los 30 días de resolverla se borra lo escrito (queda el resultado)' );

	echo "\n== Parte H · Ventana emergente ==\n";
	$ok( in_array( 'ficha', Router::MODALES, true ) && in_array( 'alta', Router::MODALES, true ) && in_array( 'solicitudes', Router::MODALES, true ), 'se abren en ventana: fichas, «Dar acceso» y solicitudes' );
	$_SERVER['HTTP_X_ASDE_MODAL'] = '1';
	$si_modal                     = Router::pide_modal();
	unset( $_SERVER['HTTP_X_ASDE_MODAL'] );
	$ok( $si_modal && ! Router::pide_modal(), 'la ventana se pide con la cabecera X-Asde-Modal' );
	View::en_modal( true );
	$_GET['solicitud'] = (string) $r1['id'];
	$modal             = ( new SolicitudesView() )->render();
	View::en_modal( false );
	$pagina = ( new SolicitudesView() )->render();
	unset( $_GET['solicitud'] );
	$ok( str_contains( $modal, 'asde-ap--modal' ) && ! str_contains( $modal, 'asde-nav__tabs' ) && str_contains( $pagina, 'asde-nav__tabs' ), 'en la ventana, el contenido sin el menú del área; en la página, con él' );
	$ok( str_contains( $modal, 'Tutor/a 1' ) && str_contains( $modal, 'Aprobada' ), 'el detalle de una solicitud compara con el CRM y dice cómo quedó' );
	$personas = ( new PersonasView() )->render();
	$ok( str_contains( $personas, 'data-asde-modal' ) && str_contains( $personas, 'Dar acceso' ), 'Personas: el nombre abre la ficha en la ventana y «Dar acceso» está en la fila' );
	$ok( str_contains( $personas, $a7['usuario']->user_login . '</a>' ) && str_contains( $personas, 'Cuenta familiar' ), 'Personas: el menor con cuenta familiar muestra esa cuenta (no «Dar acceso»)' );

	// Caché de la numeración sin una ficha (de otro momento): la ficha no debe salir «Sin número».
	set_transient( $clave_num, array( 'analisis' => array(), 'numeros_crm' => array() ), 10 * MINUTE_IN_SECONDS );
	$pol = PersonaRepository::make()->obtener( '990003' );
	$ok( '76849903' === ( ( new Numeracion( PersonaRepository::make() ) )->de( $pol )['numero'] ?? '' ), 'si la caché de la numeración no tiene la ficha, se rehace (no sale «Sin número»)' );
	$ok( $crm->llamadas > 0 && 0 === $cortadas, "todas las consultas al CRM las ha respondido el simulador ({$crm->llamadas}); ninguna ha salido hacia el CRM real" );
} finally {
	// ---------------------------------------------------------------------------
	// Limpieza: todo lo creado fuera; lo tocado, como estaba.
	remove_filter( 'pre_wp_mail', '__return_true' );
	remove_filter( 'asde_ap_solicitud_segundos_minimos', '__return_zero' );
	remove_filter( 'send_auth_cookies', '__return_false' );
	$_POST = array();
	View::en_modal( false );
	Escritura::usar_para_pruebas( null );
	$crm->desactivar();
	remove_filter( 'pre_http_request', $cortar_crm, 2 );
	wp_using_ext_object_cache( $cache_externa );
	wp_set_current_user( 0 );

	$nuevas = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . Solicitudes::table() . ' WHERE id > %d', $sol_antes ) ) ); // phpcs:ignore WordPress.DB
	foreach ( (array) $wpdb->get_col( $wpdb->prepare( 'SELECT user_id FROM ' . Solicitudes::table() . ' WHERE id > %d AND user_id > 0', $sol_antes ) ) as $uid ) { // phpcs:ignore WordPress.DB
		$ids[ 'cuenta-' . $uid ] = (int) $uid;
	}
	foreach ( $falsos as $asde ) {
		foreach ( array_merge( Usuarios::tutores_de( $asde ), array_filter( array( Usuarios::por_socio( $asde ) ) ) ) as $u ) {
			$ids[ 'cuenta-' . $u->ID ] = $u->ID;
		}
	}
	$lista = implode( ',', array_map( 'intval', array_unique( $ids ) ) ) ?: '0';
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Solicitudes::table() . ' WHERE id > %d', $sol_antes ) ); // phpcs:ignore WordPress.DB
	$en_falsos = "'" . implode( "','", array_map( 'esc_sql', $falsos ) ) . "'";
	$en_sol    = "'" . implode( "','", array_map( 'strval', $nuevas ?: array( 0 ) ) ) . "'";
	$en_login  = "'" . implode( "','", array_map( static fn( $n ) => 'solicitud ' . (int) $n, $nuevas ?: array( 0 ) ) ) . "'"; // Avisos de rechazo: no tienen usuario.
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . RegistroEmails::table() . " WHERE id > %d AND ( user_id IN ($lista) OR ( tipo = 'rechazo' AND login IN ($en_login) ) )", $emails_antes ) ); // phpcs:ignore WordPress.DB
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Logger::table() . " WHERE id > %d AND ( user_id IN ($lista) OR ( objeto = 'user' AND objeto_id IN ($lista) ) OR ( objeto = 'solicitud' AND objeto_id IN ($en_sol) ) OR ( objeto = 'socio' AND objeto_id IN ($en_falsos) ) OR ( objeto = 'grupo' AND accion = 'emails_sincronizados' AND user_id = 0 ) )", $log_antes ) ); // phpcs:ignore WordPress.DB
	foreach ( array_unique( $ids ) as $uid ) {
		delete_transient( 'asde_ap_notices_' . $uid );
		Ambito::olvidar( (int) $uid );
		wp_delete_user( (int) $uid );
	}
	// Familias de Tesorería creadas al dar las cuentas familiares.
	$familias = implode( ',', array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . FamiliasTable::table() . ' WHERE id > %d', $fam_antes ) ) ) ) ?: '0'; // phpcs:ignore WordPress.DB
	$wpdb->query( 'DELETE FROM ' . FamiliasTable::table_miembros() . " WHERE familia_id IN ($familias) OR num_socio_asde IN ($en_falsos)" ); // phpcs:ignore WordPress.DB
	$wpdb->query( 'DELETE FROM ' . FamiliasTable::table() . " WHERE id IN ($familias)" ); // phpcs:ignore WordPress.DB
	// Tabla de números: como estaba.
	$wpdb->query( 'DELETE FROM ' . SociosTable::table() . " WHERE num_socio_asde IN ($en_falsos)" ); // phpcs:ignore WordPress.DB
	$ahora = array();
	foreach ( (array) $wpdb->get_results( 'SELECT * FROM ' . SociosTable::table(), ARRAY_A ) as $f ) { // phpcs:ignore WordPress.DB
		$ahora[ $f['num_socio_asde'] ] = $f;
	}
	foreach ( $socios_antes as $asde => $f ) {
		if ( ( $ahora[ $asde ] ?? null ) !== $f ) {
			$wpdb->replace( SociosTable::table(), $f );
		}
	}
	// Cachés, sesiones del CRM y contadores.
	delete_transient( $clave_num );
	if ( false !== $cache_num ) {
		set_transient( $clave_num, $cache_num, 10 * MINUTE_IN_SECONDS );
	}
	foreach ( $opciones( '\_transient\_asde\_ap\_crm\_sess\_%' ) as $nombre => $fila ) {
		if ( ! isset( $sesiones[ $nombre ] ) ) {
			delete_transient( substr( $nombre, strlen( '_transient_' ) ) );
		} elseif ( $sesiones[ $nombre ]->option_value !== $fila->option_value ) {
			update_option( $nombre, maybe_unserialize( $sesiones[ $nombre ]->option_value ), false );
		}
	}
	foreach ( array_keys( $opciones( '\_transient\_asde\_ap\_lim\_%' ) + $opciones( '\_transient\_asde\_ap\_int\_%' ) ) as $nombre ) {
		if ( ! in_array( $nombre, $contadores, true ) ) {
			delete_transient( substr( $nombre, strlen( '_transient_' ) ) );
		}
	}
	Grupos::fijar_email_contacto( $grupo['numero'], $contacto );
	Grupos::olvidar();
	Grupo::forzar( null );

	$quedan = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users} WHERE user_login LIKE 'prueba-sol-%' OR user_login LIKE '768499%'" ); // phpcs:ignore WordPress.DB
	$ok( 0 === $quedan && 0 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . SociosTable::table() . " WHERE num_socio_asde IN ($en_falsos)" ) && Grupos::email_contacto( Grupos::obtener( $grupo['id'] ) ) === $contacto, 'limpieza: sin usuarios, números ni solicitudes de la prueba; email del grupo como estaba' ); // phpcs:ignore WordPress.DB
}

echo $fails ? "\n$fails FALLOS\n" : "\nTODO OK (nada tocado en el CRM real; el sitio queda como estaba)\n";
