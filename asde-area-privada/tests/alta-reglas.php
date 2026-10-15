<?php
/**
 * Pruebas del área privada (entorno LOCAL). Ejecutar desde app/public del site Local:
 *
 *   wp eval-file wp-content/plugins/asde-area-privada/tests/alta-reglas.php
 *
 * - Parte 1 sin CRM: normalización y numeración de socios.
 * - Parte 2 contra el CRM real (solo lectura): alta, cuentas familiares, ámbito,
 *   mayoría de edad, informes y recuperación de contraseña.
 * - Los emails se leen del CRM pero van a Mailpit (mu-plugin local-mailpit.php).
 *   Al terminar se borran SOLO los emails de la prueba (no los que ya hubiera),
 *   sus usuarios, reservas de número y su registro de emails.
 * - Necesita Mailpit en :10006.
 */
defined( 'ABSPATH' ) || exit;

use AsdeBalears\AreaPrivada\Auth\AccessHooks;
use AsdeBalears\AreaPrivada\Auth\Ambito;
use AsdeBalears\AreaPrivada\Auth\Intentos;
use AsdeBalears\AreaPrivada\Crm\ClientFactory;
use AsdeBalears\AreaPrivada\Crm\PersonaRepository;
use AsdeBalears\AreaPrivada\Domain\Grupo;
use AsdeBalears\AreaPrivada\Domain\Grupos;
use AsdeBalears\AreaPrivada\Domain\NumeroSocio;
use AsdeBalears\AreaPrivada\Domain\Persona;
use AsdeBalears\AreaPrivada\Frontend\Pages;
use AsdeBalears\AreaPrivada\Frontend\Views\AccesoView;
use AsdeBalears\AreaPrivada\Frontend\Visibilidad;
use AsdeBalears\AreaPrivada\Informes\Generador;
use AsdeBalears\AreaPrivada\Informes\Informe;
use AsdeBalears\AreaPrivada\Install\Mantenimiento;
use AsdeBalears\AreaPrivada\Install\Roles;
use AsdeBalears\AreaPrivada\Socios\Numeracion;
use AsdeBalears\AreaPrivada\Socios\SociosTable;
use AsdeBalears\AreaPrivada\Tesoreria\Calculadora;
use AsdeBalears\AreaPrivada\Tesoreria\Config;
use AsdeBalears\AreaPrivada\Tesoreria\FamiliasTable;
use AsdeBalears\AreaPrivada\Tesoreria\MovimientosTable;
use AsdeBalears\AreaPrivada\Tesoreria\Sugerencias;
use AsdeBalears\AreaPrivada\Usuarios\AltaService;
use AsdeBalears\AreaPrivada\Usuarios\Invitacion;
use AsdeBalears\AreaPrivada\Usuarios\Usuarios;

require_once ABSPATH . 'wp-admin/includes/user.php';

$fails = 0;
$ok    = function ( $cond, string $label, $r = null ) use ( &$fails ) {
	if ( ! $cond ) {
		++$fails;
	}
	// Los mensajes de error pueden llevar nombres del CRM: solo se muestran si la comprobación falla.
	echo ( $cond ? 'OK   ' : 'FAIL ' ), $label, ( ! $cond && $r && isset( $r->ok ) && ! $r->ok ) ? '  → ' . implode( ' | ', $r->errores ) : '', "\n";
};
$mailpit = static fn() => (int) ( json_decode( wp_remote_retrieve_body( wp_remote_get( 'http://localhost:10006/api/v1/messages?limit=1' ) ), true )['total'] ?? 0 );
// Ids de los emails que ya había en Mailpit: al terminar solo se borran los de la prueba.
$mailpit_ids = static fn() => array_column( (array) ( json_decode( wp_remote_retrieve_body( wp_remote_get( 'http://localhost:10006/api/v1/messages?limit=1000' ) ), true )['messages'] ?? array() ), 'ID' );
$mailpit_antes = $mailpit_ids();

// ---------------------------------------------------------------------------
echo "== Parte 1 · Números de socio (sin CRM) ==\n";
$ok( '76840390' === NumeroSocio::normalizar( '76840390', '684' ), 'formato correcto se mantiene' );
$ok( '76840390' === NumeroSocio::normalizar( '076840390', '684' ), '0 inicial → se quita' );
$ok( '76840390' === NumeroSocio::normalizar( '6840390', '684' ), 'sin el 7 → se añade' );
$ok( '76840390' === NumeroSocio::normalizar( ' 7684-0390 ', '684' ), 'espacios y guiones → se limpian' );
$ok( null === NumeroSocio::normalizar( '7684036', '684' ), '768+4 dígitos (ambiguo) → inválido' );
$ok( null === NumeroSocio::normalizar( '76850390', '684' ), 'otro grupo → inválido' );
$ok( '76840390T' === NumeroSocio::usuario_tutor( '76840390' ) && '76840390' === NumeroSocio::socio_de_usuario_tutor( '76840390t' ), 'usuario de cuenta familiar' );

$p = static fn( string $asde, string $local, string $secc = '^Scouts^' ) => Persona::from_crm(
	array(
		'id'     => wp_generate_uuid4(),
		'fields' => array(
			'numerosocio_c'             => $asde,
			'numsocioantiguo_enficha_c' => $local,
			'stic_relationship_type_c'  => $secc,
		),
	)
);
$n  = new Numeracion( PersonaRepository::make() );
$an = $n->analizar( array( $p( '1', '76840100' ), $p( '2', '076840101' ), $p( '3', '76840102' ), $p( '4', '76840102' ), $p( '5', '768401' ), $p( '6', '' ) ), false );
$ok( Numeracion::OK === $an['1']['estado'], 'estado OK' );
$ok( Numeracion::CORREGIDO === $an['2']['estado'] && '76840101' === $an['2']['numero'], 'estado CORREGIDO (formato antiguo)' );
$ok( Numeracion::DUPLICADO === $an['3']['estado'] && Numeracion::DUPLICADO === $an['4']['estado'], 'estado DUPLICADO en ambas fichas' );
$ok( Numeracion::INVALIDO === $an['5']['estado'], 'estado INVALIDO' );
$ok( Numeracion::FALTA === $an['6']['estado'], 'estado FALTA' );
$sig = SociosTable::siguiente( '684', array( '76840391', '76840100' ) );
$ok( 8 === strlen( $sig ) && str_starts_with( $sig, '7684' ) && $sig >= '76840392', "siguiente número = máximo + 1 ($sig)" );

// ---------------------------------------------------------------------------
echo "\n== Parte 1b · Tesorería (sin CRM) ==\n";
$cfg = Config::defaults_ronda( '2026-2027' );
// Importes de Scouts de Balears 2026 (la prueba no depende de la organización configurada).
$cfg['federacion'] = \AsdeBalears\AreaPrivada\Domain\Organizacion::sanear_federacion(
	array(
		'cuota_asociativa' => 17,
		'seguro_promedio'  => 3,
		'edad_corte'       => 14,
		'primas'           => array( 'menores' => 1.92, 'mayores' => 2.44, 'scouters' => 6.06 ),
	)
) + array( 'anio_primas' => 2026 );
$tot = array_map( static fn( $h ) => Config::cuota( $cfg, $h )['total'], array( 1, 2, 3, 4, 5 ) );
$ok( array( 140.0, 258.0, 332.0, 362.0, 392.0 ) === $tot, 'cuotas 1-5 hijos = 140 / 258 / 332 / 362 / 392 € (' . implode( ' / ', $tot ) . ')' );
$ok( 242.0 === Config::anualidad( $cfg, 7 ), 'anualidad con tope de 242 € a partir del 3er hijo' );

$mk = static function ( string $asde, string $secc, string $nac ) {
	return Persona::from_crm( array( 'id' => wp_generate_uuid4(), 'fields' => array( 'numerosocio_c' => $asde, 'stic_relationship_type_c' => $secc, 'birthdate' => $nac ) ) );
};
$fed = array();
for ( $i = 0; $i < 46; $i++ ) {
	$fed[] = $mk( "m$i", '^Lobatos^', '2016-01-01' );
}
for ( $i = 0; $i < 42; $i++ ) {
	$fed[] = $mk( "M$i", '^Escultas^', '2010-01-01' );
}
for ( $i = 0; $i < 22; $i++ ) {
	$fed[] = $mk( "s$i", '^Scouters^', '1990-01-01' );
}
$cf = Calculadora::coste_federativo( $fed, $cfg );
$ok( 110 === $cf['total_socios'] && 46 === $cf['categorias']['menores']['n'] && 42 === $cf['categorias']['mayores']['n'] && 22 === $cf['categorias']['scouters']['n'], 'coste federativo: 46 menores / 42 mayores de 14 / 22 scouters' );
$ok( 88.32 === $cf['categorias']['menores']['importe'] && 102.48 === $cf['categorias']['mayores']['importe'] && 133.32 === $cf['categorias']['scouters']['importe'], 'primas 2026: 88,32 + 102,48 + 133,32 €' );
$ok( 324.12 === $cf['seguro_tabla'] && 1870.0 === $cf['cuota_asociativa'] && 330.0 === $cf['seguro_promedio'] && 2194.12 === $cf['total_tabla'] && 2200.0 === $cf['total_promedio'], 'total: 1.870 € cuota + 324,12 € seguro (tabla) / 330 € (promedio)' );

// Estado de una familia con 2 hijos y un padre scouter; 2 plazos (uno vencido).
$fam_p = array( 'h1' => $mk( 'h1', '^Castores^', '2019-05-01' ), 'h2' => $mk( 'h2', '^Scouts^', '2013-05-01' ), 'pa' => $mk( 'pa', '^Scouters^', '1985-05-01' ), 'ex' => $mk( 'ex', '^^', '2012-05-01' ) );
$cfg2  = $cfg;
$cfg2['plazos'] = array( array( 'nombre' => '1º', 'fecha' => '2026-01-10', 'porcentaje' => 50 ), array( 'nombre' => '2º', 'fecha' => '2099-01-10', 'porcentaje' => 50 ) );
$fx    = array( 'id' => 1, 'numero' => 'F684999', 'miembros' => array( 'h1', 'h2', 'pa', 'ex' ) );
$e1    = Calculadora::familia( $fx, $fam_p, $cfg2, array() );
$ok( array( 'h1', 'h2' ) === $e1['pagan'] && array( 'pa' ) === $e1['no_pagan'] && array( 'ex' ) === $e1['fuera'] && 258.0 === $e1['a_pagar'], 'familia: pagan 2 hijos (258 €); el padre scouter no paga; sin membresía no cuenta' );
$ok( Calculadora::ATRASADO === $e1['estado'], 'sin pagos y con un plazo vencido → con plazos vencidos' );
$ok( Calculadora::PARCIAL === Calculadora::familia( $fx, $fam_p, $cfg2, array( 'pagado' => 129.0 ) )['estado'], 'pagado el 1er plazo (129 €) → pago parcial' );
$ok( Calculadora::PAGADO === Calculadora::familia( $fx, $fam_p, $cfg2, array( 'pagado' => 200.0, 'ajustes' => -58.0 ) )['estado'], 'con beca de 58 € y 200 € pagados → pagado' );
$sug = Sugerencias::por_email(
	array(
		Persona::from_crm( array( 'id' => '1', 'fields' => array( 'numerosocio_c' => 'a1', 'stic_relationship_type_c' => '^Lobatos^', 'email1' => 'fam@example.invalid' ) ) ),
		Persona::from_crm( array( 'id' => '2', 'fields' => array( 'numerosocio_c' => 'a2', 'stic_relationship_type_c' => '^Scouts^', 'tutor1_email_c' => 'FAM@example.invalid' ) ) ),
		Persona::from_crm( array( 'id' => '3', 'fields' => array( 'numerosocio_c' => 'a3', 'stic_relationship_type_c' => '^Castores^', 'email1' => 'otra@example.invalid' ) ) ),
	),
	array(),
	$cfg['secciones_pagan']
);
$ok( 1 === count( $sug ) && 2 === count( $sug[0] ), 'sugerencias: agrupa a los que comparten email (propio o de tutor)' );

// ---------------------------------------------------------------------------
echo "\n== Parte 2 · Contra el CRM (solo lectura) ==\n";
$repo = PersonaRepository::make();
$alta = AltaService::make();
$crm  = ClientFactory::reader();
$num  = $alta->numeracion();
$num->analizar_todo( true );

// Elegir fichas reales para las pruebas (sin imprimir datos personales).
$activas = $repo->todas( true );
$elige   = function ( callable $cond ) use ( $activas, $num ) {
	foreach ( $activas as $x ) {
		$e = $num->de( $x )['estado'];
		if ( in_array( $e, array( Numeracion::OK, Numeracion::CORREGIDO ), true ) && $x->email && ! Usuarios::por_socio( $x->num_socio_asde ) && ! Usuarios::tutores_de( $x->num_socio_asde ) && $cond( $x ) ) {
			return $x;
		}
	}
	return null;
};
$teen    = $elige( fn( $x ) => $x->es_participante() && $x->edad() >= 14 && $x->edad() < 18 );
$scouter = $elige( fn( $x ) => in_array( 'scouters', $x->secciones, true ) );
$scout2  = $elige( fn( $x ) => in_array( 'scouters', $x->secciones, true ) && $scouter && $x->num_socio_asde !== $scouter->num_socio_asde );
$nino    = $elige( fn( $x ) => $x->es_participante() && null !== $x->edad() && $x->edad() < 14 );
$adulto  = $elige( fn( $x ) => $x->es_participante() && $x->edad() >= 18 );
$ok( $teen && $scouter && $scout2 && $nino, 'fichas de prueba encontradas (participante 14-17, 2 scouters, menor de 14)' );
if ( ! ( $teen && $scouter && $scout2 && $nino ) ) {
	exit( "\nNo se puede continuar.\n" );
}

$creados = array();
$mail0   = $mailpit();
wp_set_current_user( 10 ); // prueba-secretaria (asde_manage_users)

// Participante 14-17.
$r = $alta->alta_persona( $teen->num_socio_asde, array( Roles::PARTICIPANTE ) );
$ok( $r->ok && 'creado' === $r->accion && $r->invitacion_enviada, 'participante 14-17 creado + invitación', $r );
if ( $r->ok ) {
	$u         = $r->usuario;
	$creados[] = $u->ID;
	$ok( $u->user_login === $num->de( $teen )['numero'], 'usuario = nº de socio local' );
	$ok( '' === $u->user_email && '' === $u->first_name && '' === $u->last_name && $u->display_name === $u->user_login, 'sin datos personales en WordPress (sin email ni nombre)' );
	$ok( Usuarios::socio( $u->ID ) === $teen->num_socio_asde && array( 'asde' => $teen->num_socio_asde, 'campo' => 'email1' ) === Usuarios::email_origen( $u->ID ), 'vínculo por nº Scouts de España + origen del email' );
	$ok( Ambito::de( $u )->puede_acceder() && array( Roles::PARTICIPANTE ) === Ambito::de( $u )->roles_vigentes(), 'ámbito: el participante puede entrar' );
}
$r = $alta->alta_persona( $nino->num_socio_asde, array( Roles::PARTICIPANTE ) );
$ok( ! $r->ok, 'menor de 14 no puede tener cuenta propia', $r );

// Scouter + responsabilidades.
$r = $alta->alta_persona( $scouter->num_socio_asde, array( Roles::SECRETARIA ), array(), 'email1' );
$ok( ! $r->ok, 'Secretaría sin Scouter → error (es responsabilidad de un scouter)', $r );
$r = $alta->alta_persona( $scouter->num_socio_asde, array( Roles::SCOUTER, Roles::SECRETARIA ), array( 'scouts' ) );
$ok( $r->ok && ! array_diff( array( Roles::SCOUTER, Roles::SECRETARIA ), $r->usuario->roles ), 'Scouter + Secretaría a la vez', $r );
if ( $r->ok ) {
	$creados[] = $r->usuario->ID;
	$uid_sct   = $r->usuario->ID;
}
$r = $alta->alta_persona( $scout2->num_socio_asde, array( Roles::SCOUTER ), array( 'escultas' ) );
$ok( $r->ok, 'scouter de Escultas creado', $r );
if ( $r->ok ) {
	$creados[] = $r->usuario->ID;
	$uid_sct2  = $r->usuario->ID;

	// Informes: un scouter solo ve su sección; DNI solo visión global; salud visible (solo lectura).
	$inf  = new Informe( 0, 'Prueba', '', array( 'nombre', 'seccion', 'dni', 'alergias' ), array(), 10, false );
	$res  = ( new Generador( $crm ) )->ejecutar( $inf, Ambito::de( get_userdata( $uid_sct2 ) ) );
	$secs = array_values( array_unique( array_column( $res['filas'], 'seccion' ) ) );
	$ok( array( 'Escultas' ) === $secs && count( $res['filas'] ) > 0, 'informe de scouter: solo socios de su sección (' . count( $res['filas'] ) . ')' );
	$ok( ! isset( $res['columnas']['dni'] ) && isset( $res['columnas']['alergias'] ), 'scouter: sin DNI (solo visión global) y con salud (solo lectura)' );

	$r2 = $alta->alta_persona( $scout2->num_socio_asde, array( Roles::COORDINACION ), array( 'escultas' ) );
	$ok( $r2->ok && in_array( Roles::COORDINACION, $r2->usuario->roles, true ), 'añadir Coordinación a un scouter existente', $r2 );
	Ambito::olvidar( $uid_sct2 );
	$ok( Ambito::de( get_userdata( $uid_sct2 ) )->vision_global(), 'con Coordinación pasa a visión global' );
}

// Cuenta familiar.
$r = $alta->alta_tutor( $nino->num_socio_asde, array(), 'email1' );
$ok( $r->ok && 'creado' === $r->accion && $r->usuario->user_login === NumeroSocio::usuario_tutor( $num->de( $nino )['numero'] ), 'cuenta familiar creada con usuario nº del menor + T', $r );
if ( $r->ok ) {
	$fam       = $r->usuario;
	$creados[] = $fam->ID;
	$ok( array( $nino->num_socio_asde ) === Usuarios::representados( $fam->ID ) && '' === $fam->user_email, 'la cuenta familiar representa al menor y no guarda email' );
	$fam_tes = FamiliasTable::de_socio( $nino->num_socio_asde );
	$ok( $fam_tes && preg_match( '/^F684\d{3}$/', $fam_tes['numero'] ), 'al crear la cuenta familiar se crea su familia de Tesorería (' . ( $fam_tes['numero'] ?? '-' ) . ')' );
	if ( $fam_tes ) {
		$r3 = FamiliasTable::crear( array( $nino->num_socio_asde ) );
		$ok( is_wp_error( $r3 ), 'un socio no puede estar en dos familias' );
		$m1 = MovimientosTable::registrar( $fam_tes['id'], '2026-2027', MovimientosTable::PAGO, '2026-10-01', 70, 'transferencia', $fam_tes['numero'] );
		$m2 = MovimientosTable::registrar( $fam_tes['id'], '2026-2027', MovimientosTable::AJUSTE, '2026-10-01', -10, '', '', 'Beca de prueba' );
		$m3 = MovimientosTable::registrar( $fam_tes['id'], '2026-2027', MovimientosTable::AJUSTE, '2026-10-01', -5, '', '', '' );
		$t  = MovimientosTable::totales( '2026-2027' )[ $fam_tes['id'] ] ?? array();
		$ok( is_int( $m1 ) && is_int( $m2 ) && is_wp_error( $m3 ) && 70.0 === $t['pagado'] && -10.0 === $t['ajustes'], 'pagos y ajustes (un ajuste sin motivo se rechaza)' );
		MovimientosTable::anular( $m1 );
		$ok( 0.0 === ( MovimientosTable::totales( '2026-2027' )[ $fam_tes['id'] ]['pagado'] ?? 0.0 ), 'un pago anulado deja de contar' );
		$ok( is_wp_error( FamiliasTable::borrar( $fam_tes['id'] ) ), 'no se borra una familia con socios o pagos' );
	}
	$ok( Ambito::de( $fam )->puede_acceder() && isset( Ambito::de( $fam )->representados[ $nino->num_socio_asde ] ), 'ámbito: la familia ve al menor' );
	$r2 = $alta->alta_tutor( $nino->num_socio_asde, array(), 'email1' );
	$ok( ! $r2->ok, 'misma cuenta y mismo menor → nada que cambiar', $r2 );

	// Mayoría de edad: si se vincula a un adulto (a mano), no cuenta y el mantenimiento lo retira.
	if ( $adulto ) {
		add_user_meta( $fam->ID, Usuarios::META_REPRESENTADO, $adulto->num_socio_asde );
		Ambito::olvidar( $fam->ID );
		$amb = Ambito::de( get_userdata( $fam->ID ) );
		$ok( ! isset( $amb->representados[ $adulto->num_socio_asde ] ) && in_array( $adulto->num_socio_asde, $amb->retirados, true ), 'ámbito: un mayor de edad no cuenta como representado' );
		$retiradas = Mantenimiento::revisar_tutelas( $repo );
		$ok( $retiradas >= 1 && ! in_array( $adulto->num_socio_asde, Usuarios::representados( $fam->ID ), true ), 'mantenimiento: retira al mayor de edad de la cuenta familiar' );

		// v0.26.1: una cuenta familiar que se queda sin nadie a quien representar se desactiva como a mano: sin contraseña.
		$clave_sola = wp_generate_password( 24 );
		$solo_id    = wp_insert_user(
			array(
				'user_login' => 'prueba-reglas-solo-adulto',
				'user_email' => '',
				'user_pass'  => $clave_sola,
				'role'       => Roles::TUTOR,
			)
		);
		if ( ! is_wp_error( $solo_id ) ) {
			$creados[] = $solo_id;
			add_user_meta( $solo_id, Usuarios::META_REPRESENTADO, $adulto->num_socio_asde );
			update_user_meta( $solo_id, Usuarios::META_GRUPO, Grupo::id() );
			update_user_meta( $solo_id, Usuarios::META_ESTADO, Usuarios::ACTIVO );
			update_user_meta( $solo_id, Usuarios::META_ACTIVADA_EN, (string) time() );
			Mantenimiento::revisar_tutelas( $repo );
			clean_user_cache( $solo_id );
			$solo = get_userdata( $solo_id );
			$ok( Usuarios::DESACTIVADO === Usuarios::estado( $solo ) && array() === Usuarios::roles_asde( $solo ) && ! wp_check_password( $clave_sola, $solo->user_pass, $solo_id ) && ! Usuarios::ha_activado( $solo ), 'mantenimiento: la cuenta familiar sin menores queda desactivada y sin su contraseña (v0.26.1)' );
		}
	}
}
$r = $alta->alta_tutor( $teen->num_socio_asde, array( $scouter->num_socio_asde ), 'email1' );
$ok( ! $r->ok, 'una cuenta familiar no puede incluir a un adulto scouter', $r );

if ( isset( $uid_sct ) ) {
	$inf = new Informe( 0, 'Prueba', '', array( 'nombre', 'seccion', 'dni' ), array( 'edad_min' => 14, 'edad_max' => 17 ), 10, false );
	$res = ( new Generador( $crm ) )->ejecutar( $inf, Ambito::de( get_userdata( $uid_sct ) ) );
	$ok( isset( $res['columnas']['dni'] ) && count( $res['filas'] ) > 0, 'Secretaría: visión global con DNI y filtro de edad (' . count( $res['filas'] ) . ')' );
}

// Recuperación de contraseña: enlace al email del CRM.
if ( isset( $fam ) ) {
	$m = $mailpit();
	$ok( Invitacion::enviar( get_userdata( $fam->ID ), Invitacion::RESET ) && Invitacion::RESET === Invitacion::tipo( $fam->ID ) && $mailpit() === $m + 1, 'recuperación: email enviado al email del CRM' );
}
$ok( $mailpit() - $mail0 >= 5, 'emails a Mailpit: ' . ( $mailpit() - $mail0 ) );

// ---------------------------------------------------------------------------
echo "\n== Parte 3 · Acceso socios, grupos y visibilidad ==\n";
// IP de documentación (TEST-NET-1), distinta en cada ejecución: los fallos de estas
// pruebas no cuentan para los bloqueos de Wordfence de la IP real.
$_SERVER['REMOTE_ADDR'] = '192.0.2.' . random_int( 1, 254 );
$grupo                  = Grupo::actual();
$ok( $grupo && Grupos::obtener( $grupo['id'] ), 'grupo detectado en el CRM: ' . ( $grupo ? Grupos::etiqueta( $grupo ) : '-' ) );

if ( isset( $u ) && $grupo ) {
	$u      = get_userdata( $u->ID );
	$clave  = wp_generate_password( 20, true, true ); // Solo en memoria durante la prueba.
	wp_set_password( $clave, $u->ID );
	$entrar = static fn( string $gid, string $pass ) => AccessHooks::en_grupo( $gid, static fn() => wp_authenticate( $u->user_login, $pass ) );

	$r1 = $entrar( $grupo['id'], $clave );
	$ok( $r1 instanceof WP_User && $r1->ID === $u->ID, 'acceso: su grupo + nº de socio + contraseña → entra' );
	$r2 = $entrar( 'otro-grupo', $clave );
	$ok( is_wp_error( $r2 ) && 'asde_grupo' === $r2->get_error_code(), 'acceso: otro grupo → rechazado (mismo mensaje que una contraseña incorrecta)' );
	$r3 = $entrar( $grupo['id'], $clave . 'x' );
	$ok( is_wp_error( $r3 ) && 'incorrect_password' === $r3->get_error_code(), 'acceso: contraseña incorrecta → rechazado' );
	$r4 = wp_authenticate( $u->user_login, $clave );
	$r5 = wp_authenticate( $u->user_login, $clave . 'x' );
	$ok( is_wp_error( $r4 ) && 'asde_usar_acceso' === $r4->get_error_code() && is_wp_error( $r5 ) && 'asde_usar_acceso' === $r5->get_error_code(), 'wp-login.php/XML-RPC: un socio no entra, y la respuesta es igual con contraseña buena o mala' );
	$ok( ! wp_is_application_passwords_available_for_user( $u ), 'socios sin contraseñas de aplicación' );

	// Verificación en dos pasos de Wordfence desde la pantalla de acceso (campo wfls-token).
	if ( class_exists( '\WordfenceLS\Controller_TOTP' ) ) {
		$totp    = static function ( string $hex, int $t ): string {
			$h = hash_hmac( 'sha1', pack( 'J', intdiv( $t, 30 ) ), hex2bin( $hex ), true );
			$o = ord( $h[19] ) & 0xf;
			$n = ( ( ord( $h[ $o ] ) & 0x7f ) << 24 ) | ( ord( $h[ $o + 1 ] ) << 16 ) | ( ord( $h[ $o + 2 ] ) << 8 ) | ord( $h[ $o + 3 ] );
			return str_pad( (string) ( $n % 1000000 ), 6, '0', STR_PAD_LEFT );
		};
		$secreto = bin2hex( random_bytes( 20 ) );
		\WordfenceLS\Controller_TOTP::shared()->activate_2fa( $u, $secreto, array( bin2hex( random_bytes( 8 ) ) ), time() - 120 );
		unset( $_POST['wfls-token'] );
		$r6                  = $entrar( $grupo['id'], $clave );
		$bueno               = $totp( $secreto, time() );
		$_POST['wfls-token'] = str_pad( (string) ( ( (int) $bueno + 1 ) % 1000000 ), 6, '0', STR_PAD_LEFT );
		$r7                  = $entrar( $grupo['id'], $clave );
		$_POST['wfls-token'] = $bueno;
		$r8                  = $entrar( $grupo['id'], $clave );
		unset( $_POST['wfls-token'] );
		\WordfenceLS\Controller_Users::shared()->deactivate_2fa( $u );
		$ok( is_wp_error( $r6 ) && 'wfls_twofactor_required' === $r6->get_error_code(), '2FA: sin código → pide el código' );
		$ok( is_wp_error( $r7 ) && 'wfls_twofactor_failed' === $r7->get_error_code(), '2FA: código erróneo → rechazado' );
		$ok( $r8 instanceof WP_User, '2FA: código correcto → entra' );
	}
}

$admin = get_users(
	array(
		'role'   => 'administrator',
		'number' => 1,
	)
)[0] ?? null;
if ( $admin ) {
	$ra = AccessHooks::en_grupo( 'cualquier-grupo', static fn() => AccessHooks::comprobar_acceso( $admin, $admin->user_login, '' ) );
	$ok( $ra instanceof WP_User, 'administradores: entran eligiendo cualquier grupo (pasa a ser su grupo de trabajo)' );
}

for ( $i = 0; $i < 5; $i++ ) {
	Intentos::fallo( 'PRUEBA-LIMITE' );
}
$ok( Intentos::bloqueado( 'PRUEBA-LIMITE' ) && ! Intentos::bloqueado( 'PRUEBA-OTRO' ), 'límite: 5 fallos bloquean ese nº de socio en ese dispositivo (15 min), no a los demás' );
Intentos::exito( 'PRUEBA-LIMITE' );
$ok( ! Intentos::bloqueado( 'PRUEBA-LIMITE' ), 'límite: al entrar bien se reinicia' );

$ok(
	'999' === Grupo::con( array( 'id' => 'zzz', 'numero' => '999', 'nombre' => 'Grupo de prueba' ), static fn() => Grupo::numero() ) && Grupo::numero() === $grupo['numero'],
	'Grupo::con trabaja en otro grupo y después restaura el anterior'
);

// Sin visibilidad entre grupos: con un 2º grupo (ficticio) cada uno ve solo a sus socios.
$grupos_orig = get_option( 'asde_ap_grupos' );
update_option(
	'asde_ap_grupos',
	$grupos_orig + array(
		'999' => array(
			'nombre'  => 'Grupo de prueba 999',
			'usuario' => '',
			'crm_id'  => 'grupo-de-seguridad-inexistente',
		),
	),
	false
);
Grupos::olvidar();
$en_suyo = count( $repo->todas( true ) );
$en_otro = Grupo::con( Grupos::obtener( '999' ), static fn() => count( $repo->todas( true ) ) );
$ok( count( Grupos::todos() ) > 1 && $en_suyo === count( $activas ) && 0 === $en_otro, "varios grupos: el grupo real ve sus $en_suyo socios activos y el otro, $en_otro" );
wp_set_current_user( 0 );
$form = ( new AccesoView() )->render();
$ok( str_contains( $form, 'name="grupo"' ) && str_contains( $form, 'Elige tu grupo' ) && str_contains( $form, 'name="usuario"' ) && str_contains( $form, 'name="clave"' ) && str_contains( $form, 'name="wfls-token"' ), 'pantalla de acceso: desplegable de grupo + nº de socio + contraseña + código' );
update_option( 'asde_ap_grupos', $grupos_orig, false );
Grupos::olvidar();

// Visibilidad: páginas del área privada fuera de listados, búsquedas, sitemaps y menús.
$privadas = array_values( array_filter( Pages::ids() ) );
$lista    = wp_list_pages(
	array(
		'title_li' => '',
		'depth'    => -1,
		'echo'     => 0,
	)
);
$fugas    = array_filter( $privadas, static fn( $id ) => str_contains( $lista, 'href="' . get_permalink( $id ) . '"' ) );
$ok( '' !== $lista && ! $fugas, 'pie "Páginas" (wp_list_pages): ninguna de las ' . count( $privadas ) . ' páginas del área privada' );
$busqueda = new WP_Query(
	array(
		's'              => 'asde_',
		'post_type'      => 'page',
		'fields'         => 'ids',
		'posts_per_page' => 50,
	)
);
$ok( ! array_intersect( $privadas, $busqueda->posts ), 'buscador: no encuentra páginas del área privada' );
$ok( ! array_diff( $privadas, apply_filters( 'wp_sitemaps_posts_query_args', array(), 'page' )['post__not_in'] ?? array() ), 'sitemap: excluye las páginas del área privada' );

$items           = wp_get_nav_menu_items( get_nav_menu_locations()['avia'] );
$falso           = clone $items[0];
$falso->object   = 'page';
$falso->object_id = Pages::id( 'informes' );
$falso->title    = 'Informes';
$fuera           = wp_list_pluck( Visibilidad::menu( array_merge( $items, array( $falso ) ) ), 'title' );
$ok( in_array( 'Acceso socios', $fuera, true ) && ! in_array( 'Informes', $fuera, true ), 'menú sin sesión: "Acceso socios" sí, el resto de páginas privadas no' );
wp_set_current_user( 10 );
$dentro = Visibilidad::menu( wp_get_nav_menu_items( get_nav_menu_locations()['avia'] ) );
$acc    = array_values( array_filter( $dentro, static fn( $i ) => (int) $i->object_id === Pages::id( 'acceso' ) ) )[0] ?? null;
$ok( $acc && 'Área privada' === $acc->title && $acc->url === Pages::url( 'area' ), 'menú con sesión: el elemento pasa a "Área privada"' );
$ok( str_starts_with( wp_login_url( Pages::url( 'informes' ) ), Pages::url( 'acceso' ) ) && str_contains( wp_login_url( admin_url() ), 'wp-login.php' ), 'login: páginas privadas → Acceso socios; wp-admin → wp-login.php' );
unset( $_SERVER['REMOTE_ADDR'] );

// ---------------------------------------------------------------------------
// Limpieza.
foreach ( $creados as $id ) {
	wp_delete_user( $id );
}
global $wpdb;
foreach ( array( $teen, $scouter, $scout2, $nino ) as $x ) {
	$wpdb->delete( SociosTable::table(), array( 'num_socio_asde' => $x->num_socio_asde, 'origen' => SociosTable::GENERADO ) );
	$f = FamiliasTable::de_socio( $x->num_socio_asde );
	if ( $f ) {
		$wpdb->delete( MovimientosTable::table(), array( 'familia_id' => $f['id'] ) );
		$wpdb->delete( FamiliasTable::table_miembros(), array( 'familia_id' => $f['id'] ) );
		$wpdb->delete( FamiliasTable::table(), array( 'id' => $f['id'] ) );
	}
}
$de_la_prueba = array_values( array_diff( $mailpit_ids(), $mailpit_antes ) );
if ( $de_la_prueba ) {
	wp_remote_request(
		'http://localhost:10006/api/v1/messages',
		array(
			'method'  => 'DELETE',
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode( array( 'IDs' => $de_la_prueba ) ),
		)
	);
}
// Registro de emails de los usuarios de prueba (ya borrados).
$wpdb->query( 'DELETE FROM ' . \AsdeBalears\AreaPrivada\Usuarios\RegistroEmails::table() . ' WHERE user_id IN (' . implode( ',', array_map( 'intval', $creados ?: array( 0 ) ) ) . ')' ); // phpcs:ignore WordPress.DB
Numeracion::olvidar_cache();
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_asde\\_ap\\_int\\_%' OR option_name LIKE '\\_transient\\_timeout\\_asde\\_ap\\_int\\_%'" ); // phpcs:ignore WordPress.DB

echo $fails ? "\n$fails FALLOS\n" : "\nTODO OK (usuarios, reservas y emails de prueba borrados)\n";
