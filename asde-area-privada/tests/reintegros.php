<?php
/**
 * Pruebas de la v0.47 (entorno LOCAL, doc 53): módulo «Reintegros». Ejecutar desde app/public del site Local:
 *
 *   wp eval-file <ruta completa>/wp-content/plugins/asde-area-privada/tests/reintegros.php
 *
 * Usa el CRM simulado de tests/lib (fichas inventadas, emails @example.invalid): ninguna petición llega al CRM real
 * y ningún email sale (pre_wp_mail). Al terminar se borra lo creado (reintegros, gastos, evento, justificantes, usuarios,
 * registros) y se restauran los módulos del grupo.
 */
defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/lib/CrmSimulado.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Auth\Ambito;
use AsdeBalears\AreaPrivada\Crm\Crypto;
use AsdeBalears\AreaPrivada\Domain\Grupo;
use AsdeBalears\AreaPrivada\Domain\Grupos;
use AsdeBalears\AreaPrivada\Domain\Modulos;
use AsdeBalears\AreaPrivada\Eventos\EventosTable;
use AsdeBalears\AreaPrivada\Eventos\GastosTable;
use AsdeBalears\AreaPrivada\Eventos\Justificantes;
use AsdeBalears\AreaPrivada\Frontend\Menu;
use AsdeBalears\AreaPrivada\Frontend\Views\ReintegrosView;
use AsdeBalears\AreaPrivada\Install\Roles;
use AsdeBalears\AreaPrivada\Reintegros\ReintegrosTable;
use AsdeBalears\AreaPrivada\Reintegros\Servicio;
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
foreach ( get_users( array( 'search' => 'prueba-rei-*', 'search_columns' => array( 'user_login' ) ) ) as $u ) {
	exit( 'Ya existe el usuario ' . $u->user_login . " (¿una ejecución anterior interrumpida?). Bórralo antes de repetir la prueba.\n" );
}
wp_set_current_user( 0 );

// --- Lo que se restaura al final ---------------------------------------------
$max_id         = static fn( string $t ) => (int) $wpdb->get_var( "SELECT COALESCE(MAX(id), 0) FROM $t" ); // phpcs:ignore WordPress.DB
$rei_antes      = $max_id( ReintegrosTable::table() );
$gas_antes      = $max_id( GastosTable::table() );
$eve_antes      = $max_id( EventosTable::table() );
$ema_antes      = $max_id( RegistroEmails::table() );
$log_antes      = $max_id( Logger::table() );
$modulos_antes  = get_option( 'asde_ap_modulos', null );
$cache_externa  = wp_using_ext_object_cache( true );
$correos        = array();
$capturar       = static function ( $pre, $atts ) use ( &$correos ) {
	$correos[] = $atts;
	return true;
};
add_filter( 'pre_wp_mail', $capturar, 10, 2 );
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
		$P( 31, 'Ana', 'Reintegro Sanz', '1990-01-01', 'Scouters', array( 'email1' => 'ana-rei@example.invalid' ) ),
		$P( 32, 'Teo', 'Reintegro Gil', '1985-05-05', 'Scouters', array( 'email1' => 'teso-rei@example.invalid' ) ),
		$P( 33, 'Cora', 'Reintegro Luz', '1980-08-08', 'Scouters', array( 'email1' => 'coord-rei@example.invalid' ) ),
		$P( 34, 'Ben', 'Reintegro Mas', '1992-02-02', 'Scouters', array( 'email1' => 'ben-rei@example.invalid' ) ),
		$P( 35, 'Pau', 'Reintegro Coll', '2012-07-07', 'Scouts' ),
	),
	$grupo['crm_id']
);
Grupo::forzar( $grupo );
$crm->activar();
Modulos::guardar( (string) $grupo['numero'], array_merge( array_keys( Modulos::LISTA ) ) ); // Todos encendidos (también Eventos y Reintegros).

$ids   = array();
$crear = static function ( string $login, array $roles, array $meta, string $email = '' ) use ( &$ids, $grupo ): int {
	$id = wp_insert_user(
		array(
			'user_login' => $login,
			'user_email' => $email,
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
$u_sc    = $crear( 'prueba-rei-sc', array( Roles::SCOUTER ), array( Usuarios::META_SOCIO => '990031', Usuarios::META_SECCION => 'rovers' ), 'ana-rei@example.invalid' );
$u_teso  = $crear( 'prueba-rei-teso', array( Roles::SCOUTER, Roles::TESORERIA ), array( Usuarios::META_SOCIO => '990032', Usuarios::META_SECCION => 'rovers' ), 'teso-rei@example.invalid' );
$u_coord = $crear( 'prueba-rei-coord', array( Roles::SCOUTER, Roles::COORDINACION ), array( Usuarios::META_SOCIO => '990033', Usuarios::META_SECCION => 'rovers' ), 'coord-rei@example.invalid' );
$u_sc2   = $crear( 'prueba-rei-sc2', array( Roles::SCOUTER ), array( Usuarios::META_SOCIO => '990034', Usuarios::META_SECCION => 'rovers' ) );
$u_fam   = $crear( 'prueba-rei-fam', array( Roles::TUTOR ), array( Usuarios::META_REPRESENTADO => '990035' ) );
update_user_meta( $u_sc, 'nickname', 'Ana Reintegro' );
wp_update_user( array( 'ID' => $u_sc, 'display_name' => 'Ana Reintegro' ) );

$como = static function ( int $uid ): Ambito {
	wp_set_current_user( $uid );
	Ambito::olvidar( $uid );
	Modulos::olvidar();
	return Ambito::actual();
};
add_filter( 'send_auth_cookies', '__return_false' );
$redirigir = static function ( $destino ) {
	throw new RuntimeException( 'asde-redirect:' . $destino );
};
add_filter( 'wp_redirect', $redirigir, 1 );
/** Envía un formulario a la vista como lo haría el navegador y devuelve los avisos. */
$enviar = static function ( array $campos, string $nonce ): string {
	$_POST    = $campos + array( '_wpnonce' => wp_create_nonce( $nonce ) );
	$_REQUEST = $_POST;
	try {
		( new ReintegrosView() )->handle_post();
	} catch ( RuntimeException $e ) {
		// Redirige al terminar.
	} finally {
		$_POST    = array();
		$_REQUEST = array();
	}
	return \AsdeBalears\AreaPrivada\Frontend\Notices::render();
};

// Un PNG de 1×1 como justificante.
$ruta_png = tempnam( sys_get_temp_dir(), 'rei' );
file_put_contents( $ruta_png, base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
$fichero = array(
	'ruta'   => $ruta_png,
	'nombre' => 'ticket.png',
);
$hoy   = wp_date( 'Y-m-d' );
$valido = array(
	'concepto' => 'Pilas para el campamento',
	'fecha'    => $hoy,
	'importe'  => '12,50',
	'forma'    => 'efectivo',
);
$ultimo = static fn() => ReintegrosTable::obtener( $max_id( ReintegrosTable::table() ) );
$evento = 0;

try {
	echo "== Parte A · Quién puede qué ==\n";
	$como( $u_sc );
	$ok( current_user_can( Servicio::CAP_PEDIR ) && ! current_user_can( Servicio::CAP_APROBAR ) && ! current_user_can( Servicio::CAP_PAGAR ), 'un scouter pide, pero no aprueba ni paga' );
	$como( $u_coord );
	$ok( current_user_can( Servicio::CAP_PEDIR ) && current_user_can( Servicio::CAP_PAGAR ) && ! current_user_can( Servicio::CAP_APROBAR ), 'Coordinación pide y marca pagado, pero no aprueba' );
	$como( $u_teso );
	$ok( current_user_can( Servicio::CAP_APROBAR ) && current_user_can( Servicio::CAP_PAGAR ), 'Tesorería aprueba y marca pagado' );
	$como( $u_fam );
	$ok( ! current_user_can( Servicio::CAP_PEDIR ) && ! Servicio::ve_todos() && str_contains( ( new ReintegrosView() )->render(), 'No tienes acceso' ), 'una familia no pide ni ve reintegros' );
	$como( $u_sc );
	$ok( isset( Menu::plano( Ambito::actual() )['reintegros'] ), 'el scouter tiene «Reintegros» en el menú' );
	Modulos::guardar( (string) $grupo['numero'], array_diff( array_keys( Modulos::LISTA ), array( Modulos::REINTEGROS ) ) );
	$como( $u_sc );
	$ok( ! current_user_can( Servicio::CAP_PEDIR ) && is_wp_error( Servicio::solicitar( $valido, $fichero ) ), 'con el módulo apagado nadie pide reintegros' );
	Modulos::guardar( (string) $grupo['numero'], array_keys( Modulos::LISTA ) );
	$como( $u_sc );

	echo "\n== Parte B · Pedir: lo que se comprueba ==\n";
	$evento = EventosTable::crear(
		array(
			'nombre' => 'Campamento de prueba',
			'tipo'   => 'campamento',
			'ambito' => 'grupo',
			'inicio' => wp_date( 'Y-m-d', time() + 10 * DAY_IN_SECONDS ),
			'fin'    => wp_date( 'Y-m-d', time() + 12 * DAY_IN_SECONDS ),
		)
	);
	$antes = $max_id( ReintegrosTable::table() );
	$malos = array(
		'sin concepto'          => array( array( 'concepto' => '' ) + $valido, $fichero ),
		'fecha futura'          => array( array( 'fecha' => wp_date( 'Y-m-d', time() + 3 * DAY_IN_SECONDS ) ) + $valido, $fichero ),
		'fecha de hace 2 años'  => array( array( 'fecha' => wp_date( 'Y-m-d', time() - 800 * DAY_IN_SECONDS ) ) + $valido, $fichero ),
		'importe cero'          => array( array( 'importe' => '0' ) + $valido, $fichero ),
		'forma inventada'       => array( array( 'forma' => 'bizum' ) + $valido, $fichero ),
		'transferencia sin IBAN' => array( array( 'forma' => 'transferencia', 'iban' => '' ) + $valido, $fichero ),
		'IBAN que no cuadra'    => array( array( 'forma' => 'transferencia', 'iban' => 'ES00 0000 0000 0000 0000 0000' ) + $valido, $fichero ),
		'evento inexistente'    => array( array( 'evento' => '999999' ) + $valido, $fichero ),
		'sin justificante'      => array( $valido, null ),
	);
	foreach ( $malos as $que => $caso ) {
		$ok( is_wp_error( Servicio::solicitar( $caso[0], $caso[1] ) ), "no se admite: $que" );
	}
	$ok( $antes === $max_id( ReintegrosTable::table() ), 'y no se crea ningún reintegro' );
	$h = $enviar( array( 'asde_action' => 'reintegro_pedir' ) + $valido, 'asde_reintegro_pedir' );
	$ok( $antes === $max_id( ReintegrosTable::table() ) && str_contains( $h, 'Adjunta el justificante' ), 'desde el formulario, sin fichero adjunto, tampoco' );

	echo "\n== Parte C · Pedir en efectivo y por transferencia ==\n";
	$correos = array();
	$id_efe  = Servicio::solicitar( $valido, $fichero );
	$r_efe   = ReintegrosTable::obtener( (int) $id_efe );
	$ok( ! is_wp_error( $id_efe ) && $r_efe && ReintegrosTable::SOLICITADO === $r_efe['estado'] && $u_sc === $r_efe['solicitante'] && 12.5 === $r_efe['importe'] && '' === $r_efe['iban_enc'] && '' !== $r_efe['justificante'], 'un reintegro en efectivo queda por aprobar, sin IBAN y con su justificante' );
	$ok( 'image/png' === $r_efe['justificante_tipo'] && null !== Justificantes::leer( $r_efe ), 'el justificante se guarda cifrado y se puede leer' );
	$ok( 0 === count( $correos ), 'en efectivo no sale email a Tesorería (solo lo pide la transferencia)' );
	$iban_ok = 'ES91 2100 0418 4502 0005 1332';
	$id_tra  = Servicio::solicitar( array( 'forma' => 'transferencia', 'iban' => $iban_ok, 'evento' => (string) $evento, 'concepto' => 'Gasolina del campamento', 'importe' => '40' ) + $valido, $fichero );
	$r_tra   = ReintegrosTable::obtener( (int) $id_tra );
	$ok( ! is_wp_error( $id_tra ) && $r_tra && '' !== $r_tra['iban_enc'] && ! str_contains( $r_tra['iban_enc'], '2100' ) && $iban_ok === Servicio::iban( $r_tra ) && $evento === $r_tra['evento_id'], 'por transferencia: el IBAN se guarda cifrado (no en claro) y el evento queda asociado' );
	$a_teso = array_values( array_filter( $correos, static fn( $c ) => in_array( 'teso-rei@example.invalid', (array) $c['to'], true ) ) );
	$ok( 1 === count( $a_teso ) && str_contains( (string) $a_teso[0]['subject'], ReintegrosTable::numero( $r_tra ) ) && str_contains( (string) $a_teso[0]['message'], '2100 0418 4502 0005 1332' ) && str_contains( (string) $a_teso[0]['message'], 'Gasolina del campamento' ) && str_contains( (string) $a_teso[0]['message'], '40,00' ) && str_contains( (string) $a_teso[0]['message'], 'Campamento de prueba' ) && str_contains( (string) $a_teso[0]['message'], 'Ana Reintegro' ), 'por transferencia sale un email a Tesorería con todo: quién, concepto, importe, evento e IBAN' );
	$ok( ! array_filter( $correos, static fn( $c ) => in_array( 'ana-rei@example.invalid', (array) $c['to'], true ) ), 'y no se manda a quien lo pidió' );
	$reg = array_values( array_filter( RegistroEmails::listado(), static fn( $f ) => (int) $f['id'] > $ema_antes && RegistroEmails::REINTEGRO === $f['tipo'] ) );
	$ok( 1 === count( $reg ) && ! str_contains( (string) $reg[0]['datos'], '2100' ) && ! str_contains( (string) $reg[0]['datos'], '@' ), 'el registro de emails guarda el tipo y el id, nunca el IBAN ni la dirección entera' );

	echo "\n== Parte D · Quién ve qué ==\n";
	$como( $u_sc2 );
	$ok( ! Servicio::puede_ver( $r_efe ) && '' === Servicio::iban( $r_tra ), 'otro scouter no ve el reintegro de Ana ni su IBAN' );
	$h = ( new ReintegrosView() )->render();
	$ok( ! str_contains( $h, ReintegrosTable::numero( $r_efe ) ) && str_contains( $h, 'Pedir un reintegro' ), 'y en su pantalla solo salen los suyos' );
	$como( $u_sc );
	$h = ( new ReintegrosView() )->render();
	$ok( str_contains( $h, ReintegrosTable::numero( $r_efe ) ) && str_contains( $h, 'Pilas para el campamento' ) && str_contains( $h, 'Cancelar' ) && ! str_contains( $h, 'Aprobar' ), 'Ana ve los suyos, con «Cancelar» y sin «Aprobar»' );
	$como( $u_teso );
	$h = ( new ReintegrosView() )->render();
	$ok( str_contains( $h, 'Por aprobar y por pagar' ) && str_contains( $h, ReintegrosTable::numero( $r_tra ) ) && str_contains( $h, '2100 0418 4502 0005 1332' ) && str_contains( $h, 'Aprobar' ) && str_contains( $h, 'Rechazar' ), 'Tesorería ve todos, con el IBAN, «Aprobar» y «Rechazar»' );
	$ok( 2 === Servicio::pendientes(), 'y el menú le cuenta 2 por aprobar' );
	$como( $u_coord );
	$h = ( new ReintegrosView() )->render();
	$ok( str_contains( $h, ReintegrosTable::numero( $r_tra ) ) && ! str_contains( $h, '>Aprobar<' ), 'Coordinación ve todos, pero no los aprueba' );
	$ok( 0 === Servicio::pendientes(), 'y no tiene nada por pagar todavía' );

	echo "\n== Parte E · Aprobar y rechazar ==\n";
	$como( $u_sc );
	$ok( is_wp_error( Servicio::aprobar( $r_tra ) ), 'un scouter no aprueba' );
	$como( $u_coord );
	$ok( is_wp_error( Servicio::aprobar( $r_tra ) ), 'Coordinación tampoco' );
	// Un reintegro de Tesorería no lo aprueba ella misma.
	$como( $u_teso );
	$id_teso = Servicio::solicitar( array( 'concepto' => 'Cinta americana' ) + $valido, $fichero );
	$ok( ! is_wp_error( $id_teso ) && is_wp_error( Servicio::aprobar( ReintegrosTable::obtener( (int) $id_teso ) ) ) && is_wp_error( Servicio::rechazar( ReintegrosTable::obtener( (int) $id_teso ), 'No' ) ), 'nadie aprueba ni rechaza el suyo (aunque sea de Tesorería)' );
	$h = $enviar( array( 'asde_action' => 'reintegro_rechazar', 'reintegro' => (string) $r_efe['id'], 'motivo' => '' ), 'asde_reintegro_' . $r_efe['id'] );
	$ok( ReintegrosTable::SOLICITADO === ReintegrosTable::obtener( $r_efe['id'] )['estado'] && str_contains( $h, 'motivo' ), 'rechazar pide el motivo' );
	$correos = array();
	$enviar( array( 'asde_action' => 'reintegro_rechazar', 'reintegro' => (string) $r_efe['id'], 'motivo' => 'Eso lo paga el grupo' ), 'asde_reintegro_' . $r_efe['id'] );
	$q = ReintegrosTable::obtener( $r_efe['id'] );
	$ok( ReintegrosTable::RECHAZADO === $q['estado'] && 'Eso lo paga el grupo' === $q['motivo'] && $u_teso === $q['revisado_por'], 'Tesorería lo rechaza con motivo' );
	$a_ana = array_values( array_filter( $correos, static fn( $c ) => in_array( 'ana-rei@example.invalid', (array) $c['to'], true ) ) );
	$ok( 1 === count( $a_ana ) && str_contains( (string) $a_ana[0]['message'], 'Eso lo paga el grupo' ), 'y Ana recibe un email con el motivo' );
	$enviar( array( 'asde_action' => 'reintegro_aprobar', 'reintegro' => (string) $r_efe['id'] ), 'asde_reintegro_' . $r_efe['id'] );
	$ok( ReintegrosTable::RECHAZADO === ReintegrosTable::obtener( $r_efe['id'] )['estado'], 'uno rechazado no se puede aprobar después' );
	$correos = array();
	$enviar( array( 'asde_action' => 'reintegro_aprobar', 'reintegro' => (string) $r_tra['id'] ), 'asde_reintegro_' . $r_tra['id'] );
	$q = ReintegrosTable::obtener( $r_tra['id'] );
	$ok( ReintegrosTable::APROBADO === $q['estado'] && $u_teso === $q['revisado_por'] && '' !== $q['iban_enc'], 'Tesorería aprueba el de la transferencia (y conserva el IBAN hasta pagarlo)' );
	$ok( 1 === count( array_filter( $correos, static fn( $c ) => in_array( 'ana-rei@example.invalid', (array) $c['to'], true ) ) ), 'y Ana recibe el aviso de aprobado' );
	$ok( is_wp_error( Servicio::aprobar( ReintegrosTable::obtener( $r_tra['id'] ) ) ), 'no se aprueba dos veces' );
	$ok( '' === ReintegrosTable::obtener( $r_efe['id'] )['iban_enc'], 'el rechazado no guarda IBAN (en efectivo no tenía)' );

	echo "\n== Parte F · Cancelar ==\n";
	$como( $u_sc );
	$id_c = Servicio::solicitar( array( 'forma' => 'transferencia', 'iban' => $iban_ok, 'concepto' => 'Se canceló' ) + $valido, $fichero );
	$r_c  = ReintegrosTable::obtener( (int) $id_c );
	$como( $u_sc2 );
	$ok( is_wp_error( Servicio::cancelar( $r_c ) ), 'otro scouter no cancela el reintegro de Ana' );
	$como( $u_sc );
	$ok( true === Servicio::cancelar( $r_c ) && ReintegrosTable::CANCELADO === ReintegrosTable::obtener( $r_c['id'] )['estado'] && '' === ReintegrosTable::obtener( $r_c['id'] )['iban_enc'], 'Ana cancela el suyo mientras está por aprobar, y se borra su IBAN' );
	$ok( is_wp_error( Servicio::cancelar( ReintegrosTable::obtener( $r_tra['id'] ) ) ), 'uno aprobado ya no se cancela' );

	echo "\n== Parte G · Pagar ==\n";
	$como( $u_sc );
	$h = $enviar( array( 'asde_action' => 'reintegro_pagar', 'reintegro' => (string) $r_tra['id'], 'fecha' => $hoy ), 'asde_reintegro_' . $r_tra['id'] );
	$ok( ReintegrosTable::APROBADO === ReintegrosTable::obtener( $r_tra['id'] )['estado'] && str_contains( $h, 'No puedes' ), 'un scouter no marca pagado' );
	$como( $u_coord );
	$ok( 1 === Servicio::pendientes(), 'a Coordinación el menú le cuenta 1 por pagar' );
	$h = $enviar( array( 'asde_action' => 'reintegro_pagar', 'reintegro' => (string) $r_tra['id'], 'fecha' => wp_date( 'Y-m-d', time() + 3 * DAY_IN_SECONDS ) ), 'asde_reintegro_' . $r_tra['id'] );
	$ok( ReintegrosTable::APROBADO === ReintegrosTable::obtener( $r_tra['id'] )['estado'] && str_contains( $h, 'no posterior a hoy' ), 'una fecha de pago futura no vale' );
	$ok( is_wp_error( Servicio::pagar( ReintegrosTable::obtener( (int) $id_teso ), $hoy, '' ) ), 'uno sin aprobar no se puede pagar' );
	$correos = array();
	$h = $enviar( array( 'asde_action' => 'reintegro_pagar', 'reintegro' => (string) $r_tra['id'], 'fecha' => $hoy, 'referencia' => 'Transferencia 1' ), 'asde_reintegro_' . $r_tra['id'] );
	$q = ReintegrosTable::obtener( $r_tra['id'] );
	$ok( ReintegrosTable::PAGADO === $q['estado'] && $hoy === $q['pago_fecha'] && 'Transferencia 1' === $q['pago_ref'] && $u_coord === $q['pagado_por'] && '' === $q['iban_enc'], 'Coordinación lo marca pagado (con fecha y referencia) y el IBAN se borra' );
	$ok( $q['gasto_id'] > 0 && str_contains( $h, 'cuentas del evento' ), 'con evento, se añade el gasto a sus cuentas y se dice' );
	$g = GastosTable::obtener( $q['gasto_id'] );
	$ok( $g && GastosTable::VALIDADO === $g['estado'] && GastosTable::PAGO_PERSONA === $g['pagado_por'] && $u_sc === $g['persona'] && 40.0 === $g['importe'] && $hoy === $g['reembolsado_en'] && 'transferencia' === $g['reembolso_forma'] && $g['evento_id'] === $evento && $g['justificante'] === $q['justificante'] && ! GastosTable::por_reembolsar( $g ), 'el gasto del evento: validado, de su bolsillo, ya reembolsado y con el mismo justificante' );
	$ok( 1 === count( array_filter( $correos, static fn( $c ) => in_array( 'ana-rei@example.invalid', (array) $c['to'], true ) ) ), 'y Ana recibe el aviso de pagado' );
	$ok( is_wp_error( Servicio::pagar( $q, $hoy, '' ) ), 'no se paga dos veces' );
	$como( $u_sc );
	$ok( '' === Servicio::iban( $q ), 'ya nadie ve el IBAN' );

	// Un reintegro con evento cuyas cuentas están cerradas: se paga igual, sin gasto.
	$como( $u_sc );
	$id_cer = Servicio::solicitar( array( 'evento' => (string) $evento, 'concepto' => 'Con las cuentas cerradas' ) + $valido, $fichero );
	$como( $u_teso );
	Servicio::aprobar( ReintegrosTable::obtener( (int) $id_cer ) );
	$wpdb->update( EventosTable::table(), array( 'cerrado_en' => current_time( 'mysql', true ) ), array( 'id' => $evento ) );
	$res = Servicio::pagar( ReintegrosTable::obtener( (int) $id_cer ), $hoy, '' );
	$ok( is_array( $res ) && $res['evento'] && ! $res['gasto'] && 0 === ReintegrosTable::obtener( (int) $id_cer )['gasto_id'] && ReintegrosTable::PAGADO === ReintegrosTable::obtener( (int) $id_cer )['estado'], 'con las cuentas del evento cerradas se paga igual, sin gasto, y se avisa' );
	$wpdb->update( EventosTable::table(), array( 'cerrado_en' => null ), array( 'id' => $evento ) );
	$como( $u_sc );
	$wpdb->update( EventosTable::table(), array( 'cerrado_en' => current_time( 'mysql', true ) ), array( 'id' => $evento ) );
	$ok( array() === Servicio::eventos(), 'cerrado el evento, ya no se puede asociar un reintegro nuevo' );
	$wpdb->update( EventosTable::table(), array( 'cerrado_en' => null ), array( 'id' => $evento ) );

	echo "\n== Parte H · Justificante, aislamiento y auditoría ==\n";
	$como( $u_sc2 );
	$h = $enviar( array( 'asde_action' => 'reintegro_justificante', 'reintegro' => (string) $r_tra['id'] ), 'asde_reintegro_' . $r_tra['id'] );
	$ok( str_contains( $h, 'No tienes acceso' ), 'otro scouter no abre el justificante de Ana' );
	$logs = (array) $wpdb->get_col( $wpdb->prepare( "SELECT detalles FROM %i WHERE id > %d AND objeto = 'reintegro'", Logger::table(), $log_antes ) ); // phpcs:ignore WordPress.DB
	$ok( count( $logs ) >= 6 && ! str_contains( implode( ' ', $logs ), '2100' ) && ! str_contains( implode( ' ', $logs ), 'ES91' ), 'la auditoría anota lo que pasó (' . count( $logs ) . ' registros) y nunca el IBAN' );
	$otro = '685';
	$fila = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', ReintegrosTable::table(), $r_tra['id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB
	$wpdb->update( ReintegrosTable::table(), array( 'grupo' => $otro ), array( 'id' => $r_tra['id'] ) );
	$ok( null === ReintegrosTable::obtener( $r_tra['id'] ), 'un reintegro de otro grupo no se ve' );
	$wpdb->update( ReintegrosTable::table(), array( 'grupo' => $fila['grupo'] ), array( 'id' => $r_tra['id'] ) );
	$ok( $crm->llamadas > 0 && 0 === $cortadas, "todas las consultas al CRM las ha respondido el simulador ({$crm->llamadas}); ninguna ha salido hacia el CRM real" );
} catch ( Throwable $e ) {
	++$fails;
	echo 'FAIL excepcion: ', $e->getMessage(), ' (', basename( $e->getFile() ), ':', $e->getLine(), ")\n";
} finally {
	remove_filter( 'wp_redirect', $redirigir, 1 );
	remove_filter( 'send_auth_cookies', '__return_false' );
	remove_filter( 'pre_wp_mail', $capturar, 10 );
	$_POST = array();
	$crm->desactivar();
	remove_filter( 'pre_http_request', $cortar_crm, 2 );
	wp_using_ext_object_cache( $cache_externa );
	wp_set_current_user( 0 );

	// Los justificantes (ficheros) de lo creado.
	$dir = Justificantes::carpeta();
	foreach ( (array) $wpdb->get_col( $wpdb->prepare( 'SELECT justificante FROM %i WHERE id > %d', ReintegrosTable::table(), $rei_antes ) ) as $f ) { // phpcs:ignore WordPress.DB
		if ( '' !== $dir && preg_match( '/^[0-9a-f]{32}\.bin$/', (string) $f ) && is_file( $dir . '/' . $f ) ) {
			wp_delete_file( $dir . '/' . $f );
		}
	}
	if ( is_file( $ruta_png ) ) {
		wp_delete_file( $ruta_png );
	}
	$lista = implode( ',', array_map( 'intval', $ids ) ) ?: '0';
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id > %d', ReintegrosTable::table(), $rei_antes ) ); // phpcs:ignore WordPress.DB
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id > %d', GastosTable::table(), $gas_antes ) ); // phpcs:ignore WordPress.DB
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id > %d', EventosTable::table(), $eve_antes ) ); // phpcs:ignore WordPress.DB
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id > %d', RegistroEmails::table(), $ema_antes ) ); // phpcs:ignore WordPress.DB
	$wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE id > %d AND ( user_id IN ($lista) OR objeto = 'reintegro' )", Logger::table(), $log_antes ) ); // phpcs:ignore WordPress.DB
	foreach ( $ids as $uid ) {
		Ambito::olvidar( (int) $uid );
		wp_delete_user( (int) $uid );
	}
	if ( null === $modulos_antes ) {
		delete_option( 'asde_ap_modulos' );
	} else {
		update_option( 'asde_ap_modulos', $modulos_antes, false );
	}
	Modulos::olvidar();
	Grupo::forzar( null );

	$quedan = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users} WHERE user_login LIKE 'prueba-rei-%'" ); // phpcs:ignore WordPress.DB
	$ok( 0 === $quedan && 0 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . ReintegrosTable::table() . ' WHERE id > ' . (int) $rei_antes ) && 0 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . GastosTable::table() . ' WHERE id > ' . (int) $gas_antes ), 'limpieza: sin usuarios, reintegros, gastos ni eventos de la prueba; módulos como estaban' ); // phpcs:ignore WordPress.DB
}

echo $fails ? "\nHAY $fails FALLOS\n" : "\nTODO OK (nada tocado en el CRM real; el sitio queda como estaba)\n";
