<?php
/**
 * Pruebas de la v0.14 (entorno LOCAL): el usuario de acceso pasa a ser el nº de Scouts de
 * España (configurable), el nº territorial se sigue vigilando y se puede ver. Ejecutar
 * desde app/public del site Local:
 *
 *   wp eval-file <ruta completa>/wp-content/plugins/asde-area-privada/tests/identidad.php
 *
 * Usa un CRM simulado (tests/lib/CrmSimulado.php): ninguna petición llega al CRM real.
 * Datos inventados (@example.invalid). Ningún email sale (pre_wp_mail). Al terminar se
 * restauran la organización, la tabla de números, las cachés y se borra lo creado.
 */
defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/lib/CrmSimulado.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Auth\Ambito;
use AsdeBalears\AreaPrivada\Auth\Identificacion;
use AsdeBalears\AreaPrivada\Crm\Escritura;
use AsdeBalears\AreaPrivada\Crm\PersonaRepository;
use AsdeBalears\AreaPrivada\Domain\Grupo;
use AsdeBalears\AreaPrivada\Domain\Grupos;
use AsdeBalears\AreaPrivada\Domain\Identidad;
use AsdeBalears\AreaPrivada\Domain\NumeroSocio;
use AsdeBalears\AreaPrivada\Domain\Organizacion;
use AsdeBalears\AreaPrivada\Frontend\Views\AltaUsuariosView;
use AsdeBalears\AreaPrivada\Install\Roles;
use AsdeBalears\AreaPrivada\Socios\Numeracion;
use AsdeBalears\AreaPrivada\Socios\Numeros;
use AsdeBalears\AreaPrivada\Socios\SociosTable;
use AsdeBalears\AreaPrivada\Tesoreria\FamiliasTable;
use AsdeBalears\AreaPrivada\Usuarios\AltaService;
use AsdeBalears\AreaPrivada\Usuarios\EmailCuenta;
use AsdeBalears\AreaPrivada\Usuarios\MigracionUsuario;
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

$grupo = array_values( Grupos::todos() )[0] ?? null;
if ( ! $grupo || '' === $grupo['crm_id'] ) {
	exit( "Hace falta un grupo con la conexión al CRM comprobada.\n" );
}
foreach ( get_users( array( 'search' => 'prueba-ide-*', 'search_columns' => array( 'user_login' ) ) ) as $u ) {
	exit( 'Ya existe el usuario ' . $u->user_login . " (¿una ejecución anterior interrumpida?). Bórralo antes de repetir la prueba.\n" );
}
wp_set_current_user( 0 );
$_SERVER['REMOTE_ADDR'] = '192.0.2.' . random_int( 1, 254 );

// --- Lo que se restaura al final ---------------------------------------------
$max_id       = static fn( string $t ) => (int) $wpdb->get_var( "SELECT COALESCE(MAX(id), 0) FROM $t" ); // phpcs:ignore WordPress.DB
$log_antes    = $max_id( Logger::table() );
$emails_antes = $max_id( RegistroEmails::table() );
$fam_antes    = $max_id( FamiliasTable::table() );
$socios_antes = array();
foreach ( (array) $wpdb->get_results( 'SELECT * FROM ' . SociosTable::table(), ARRAY_A ) as $f ) { // phpcs:ignore WordPress.DB
	$socios_antes[ $f['num_socio_asde'] ] = $f;
}
$org_antes  = get_option( Organizacion::OPTION, null );
$clave_num  = 'asde_ap_numeracion_' . $grupo['numero'];
$cache_num  = get_transient( $clave_num );
delete_transient( $clave_num );
add_filter( 'pre_wp_mail', '__return_true' );

$P   = array( 'Asde_Prueba_Crm', 'persona' );
$crm = new Asde_Prueba_Crm(
	array(
		$P( 1, 'Leo', 'Ide Sanz', '2016-05-10', 'Lobatos', array( 'tutor1_nombre_c' => 'Ana', 'tutor1_apellidos_c' => 'Ide Sanz', 'tutor1_email_c' => 'ana.ide@example.invalid', 'tutor2_nombre_c' => 'Luis', 'tutor2_apellidos_c' => 'Sanz Ide', 'tutor2_email_c' => 'luis.ide@example.invalid' ) ),
		$P( 2, 'Nora', 'Ide Sanz', '2014-02-20', 'Scouts', array( 'tutor1_email_c' => 'ana.ide@example.invalid' ) ),
		$P( 3, 'Eva', 'Ide Gil', '1990-01-01', 'Scouters', array( 'email1' => 'eva.ide@example.invalid' ) ),
		$P( 4, 'Tom', 'Ide Luz', '1985-05-05', 'Scouters', array( 'email1' => 'tom.ide@example.invalid', 'numsocioantiguo_enficha_c' => '12345' ) ), // Nº territorial no válido.
		$P( 5, 'Marta', 'Ide Vila', '1992-02-02', 'Scouters', array( 'email1' => 'marta.ide@example.invalid', 'numsocioantiguo_enficha_c' => '76849903' ) ), // Duplicado con el de Eva.
		$P( 6, 'Pau', 'Ide Roca', '1991-03-03', 'Scouters', array( 'email1' => 'pau.ide@example.invalid', 'numsocioantiguo_enficha_c' => '' ) ), // Sin nº territorial.
		$P( 7, 'Iris', 'Ide Mas', '1993-04-04', 'Scouters', array( 'email1' => 'iris.ide@example.invalid', 'numsocioantiguo_enficha_c' => '6849907' ) ), // Formato antiguo (sin la provincia).
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

$poner_modo = static function ( string $modo ) use ( $org_antes ) {
	$v                  = is_array( $org_antes ) ? $org_antes : array( 'organizacion' => 'balears', 'codigo' => '7', 'federacion' => array() );
	$v['identificador'] = $modo;
	update_option( Organizacion::OPTION, $v, false );
	Organizacion::olvidar();
};
$ids      = array();
$secre_id = wp_insert_user( array( 'user_login' => 'prueba-ide-secre', 'user_pass' => wp_generate_password( 24 ), 'role' => Roles::SECRETARIA ) );
$ids[]    = $secre_id;
get_userdata( $secre_id )->add_role( Roles::SCOUTER );
update_user_meta( $secre_id, Usuarios::META_GRUPO, $grupo['id'] );
update_user_meta( $secre_id, Usuarios::META_ESTADO, Usuarios::ACTIVO );
add_user_meta( $secre_id, Usuarios::META_SOCIO, '990003' );
add_user_meta( $secre_id, Usuarios::META_SECCION, 'lobatos' );
$tutor_id = wp_insert_user( array( 'user_login' => 'prueba-ide-familia', 'user_pass' => wp_generate_password( 24 ), 'role' => Roles::TUTOR ) );
$ids[]    = $tutor_id;

try {
	echo "== Parte A · El parámetro «Usuario de acceso» ==\n";
	update_option( Organizacion::OPTION, array( 'organizacion' => 'balears', 'codigo' => '7', 'federacion' => array() ), false ); // Como una instalación de antes de la v0.14.
	Organizacion::olvidar();
	$ok( Organizacion::IDENTIFICADOR_LOCAL === Organizacion::identificador() && ! Identidad::es_asde(), 'una instalación anterior (sin el parámetro guardado) sigue con el nº local: no se rompe ningún acceso al actualizar' );
	$ok( true === Organizacion::guardar( 'balears', '7', array(), Organizacion::IDENTIFICADOR_ASDE ) && Identidad::es_asde(), 'se guarda el nº de Scouts de España como usuario de acceso' );
	$ok( true === Organizacion::guardar( 'balears', '7', array() ) && Identidad::es_asde(), 'guardar sin indicarlo conserva el que había' );
	$ok( is_wp_error( Organizacion::guardar( 'balears', '7', array(), 'otro' ) ), 'un valor que no es ninguno de los dos se rechaza' );
	$ok( 'nº de socio/a de Scouts de España' === Identidad::etiqueta() && '118241' === Identidad::ejemplo() && '118241T' === Identidad::ejemplo_tutor(), 'textos y ejemplos según el modo (118241 y 118241T)' );
	$poner_modo( Organizacion::IDENTIFICADOR_LOCAL );
	$ok( 'nº de socio/a local' === Identidad::etiqueta() && str_ends_with( Identidad::ejemplo_tutor(), 'T' ) && str_starts_with( Identidad::ejemplo(), '07684' ), 'y el de siempre con el nº local (con la provincia: 07684…)' );

	echo "\n== Parte B · Reconocer los números ==\n";
	$ok( '118241' === Identidad::normalizar_numero( ' 0118241 ' ) && '118241T' === Identidad::normalizar_numero( '118241t' ) && '118241T2' === Identidad::normalizar_numero( '118.241-t2' ) && '76840390T' === Identidad::normalizar_numero( '076840390t' ), 'nº de Scouts de España o local, con ceros delante, puntos o la T en minúscula → formato de usuario' );
	$ok( null === Identidad::normalizar_numero( 'abc' ) && null === Identidad::normalizar_numero( '123' ) && null === Identidad::normalizar_numero( '' ), 'lo que no es un nº de socio/a no se reconoce' );
	$ok( 'ana@example.invalid' === Identificacion::normalizar( ' Ana@Example.invalid ' ) && '118241T' === Identificacion::normalizar( '118241t' ) && 'admin' === Identificacion::normalizar( 'admin' ), 'al entrar: emails en minúsculas, nº de socio/a normalizado y los usuarios de WordPress como están' );
	$ok( 118241 === (int) NumeroSocio::socio_de_usuario_tutor( '118241T2' ) && 2 === NumeroSocio::orden_tutor( '118241T2' ) && 1 === NumeroSocio::orden_tutor( '118241T' ) && 0 === NumeroSocio::orden_tutor( '118241' ), 'las cuentas familiares T y T2 se reconocen también con nº de Scouts de España' );

	echo "\n== Parte C · Alta con el nº de Scouts de España ==\n";
	$poner_modo( Organizacion::IDENTIFICADOR_ASDE );
	wp_set_current_user( $secre_id );
	Ambito::olvidar( $secre_id );
	$alta = AltaService::make();
	$num  = new Numeracion( PersonaRepository::make() );
	$analisis = $num->analizar_todo( true );
	$ok( Numeracion::INVALIDO === $analisis['990004']['estado'] && Numeracion::DUPLICADO === $analisis['990005']['estado'] && Numeracion::FALTA === $analisis['990006']['estado'] && Numeracion::CORREGIDO === $analisis['990007']['estado'], 'el nº territorial se sigue analizando: no válido, duplicado, sin número y formato antiguo' );
	$r = $alta->alta_persona( '990004', array( Roles::SCOUTER ), array( 'lobatos' ), 'email1' );
	$ok( $r->ok && '990004' === $r->usuario->user_login, 'con el nº territorial NO válido se da el alta igualmente: el usuario es el nº de Scouts de España' );
	$ids[] = $r->usuario->ID;
	$r = $alta->alta_persona( '990005', array( Roles::SCOUTER ), array( 'lobatos' ), 'email1' );
	$ok( $r->ok && '990005' === $r->usuario->user_login, 'con el nº territorial duplicado, también' );
	$ids[] = $r->usuario->ID;
	$r = $alta->alta_persona( '990006', array( Roles::SCOUTER ), array( 'lobatos' ), 'email1' );
	$ok( $r->ok && '990006' === $r->usuario->user_login && '' === $crm->campo( '990006', 'numsocioantiguo_enficha_c' ), 'sin nº territorial, también; y no se le genera ni se escribe nada en el CRM' );
	$ids[] = $r->usuario->ID;
	$u4 = get_user_by( 'login', '990004' );
	$ok( $u4 && '990004' === Usuarios::socio( $u4->ID ) && $grupo['id'] === get_user_meta( $u4->ID, Usuarios::META_GRUPO, true ) && Grupos::de_usuario( $u4 )['numero'] === $grupo['numero'], 'el grupo de la cuenta sale de su vínculo, no del prefijo del usuario (que ya no lo lleva)' );
	$f = $alta->alta_tutor( '990001', array( '990002' ), 'tutor1_email_c' );
	$ok( $f->ok && '990001T' === $f->usuario->user_login && array( '990001', '990002' ) === array_map( 'strval', Usuarios::representados( $f->usuario->ID ) ), 'familia: cuenta «nº de Scouts de España del menor + T» que representa a los hermanos' );
	$ids[] = $f->usuario->ID;
	$f2 = $alta->alta_tutor( '990001', array( '990002' ), 'tutor2_email_c', null, true );
	$ok( $f2->ok && '990001T2' === $f2->usuario->user_login, 'el segundo tutor/a: «…T2»' );
	$ids[] = $f2->usuario->ID;

	echo "\n== Parte D · Entrar con cualquiera de los dos números ==\n";
	$analisis = $num->analizar_todo( true ); // Rellena la tabla de equivalencias.
	$ok( '990004' === Identidad::resolver( '990004' ), 'con el nº de Scouts de España entra en su cuenta' );
	$cuenta_nora = wp_insert_user( array( 'user_login' => '990002', 'user_pass' => wp_generate_password( 24 ), 'role' => Roles::PARTICIPANTE ) );
	$ids[]       = $cuenta_nora;
	update_user_meta( $cuenta_nora, Usuarios::META_SOCIO, '990002' );
	$ok( '76849902' === SociosTable::local_de( '990002' ) && '990002' === Identidad::resolver( '76849902' ), 'con el nº local de una ficha válida y única entra en la cuenta de su nº de Scouts de España' );
	$ok( null === SociosTable::local_de( '990004' ) && '12345' === Identidad::resolver( '12345' ) && null === SociosTable::asde_de( '76849903' ) && '76849903' === Identidad::resolver( '76849903' ), 'un nº local no válido o repetido no lleva a ninguna cuenta (se deja como está)' );
	$ok( '990001T2' === Identidad::resolver( SociosTable::local_de( '990001' ) . 'T2' ), 'con el nº local de siempre (y la T2) entra en la cuenta familiar nueva' );
	$ok( '990001T' === Identidad::resolver( SociosTable::local_de( '990001' ) . 'T' ), '…y en la del primer tutor/a' );
	$ok( '990001T' === Identificacion::usuario_para( SociosTable::local_de( '990001' ) . 'T', $grupo, 'x' ), 'la pantalla de acceso (Identificacion::usuario_para) resuelve el otro número' );
	$ok( 'nadie' === Identidad::resolver( 'nadie' ) && '999999' === Identidad::resolver( '999999' ), 'un nº que no es de nadie se deja como está (el acceso falla como una contraseña incorrecta)' );

	echo "\n== Parte E · Pantallas: el nº territorial se vigila y se puede ver ==\n";
	$persona3 = PersonaRepository::make()->obtener( '990003' );
	$persona4 = PersonaRepository::make()->obtener( '990004' );
	Numeros::olvidar();
	update_user_meta( $secre_id, Numeros::META_VER, '0' );
	$persona1 = PersonaRepository::make()->obtener( '990001' );
	$h        = Numeros::html( $persona1 );
	$ok( str_contains( $h, '<strong>990001</strong>' ) && ! str_contains( $h, 'terr.' ), 'con el nº de Scouts de España como usuario, sin pedirlo, el nº territorial no se ve' );
	$h4 = Numeros::html( $persona4 );
	$ok( str_contains( $h4, '⚠ nº territorial: no válido' ) && str_contains( $h4, 'terr.' ), 'pero si tiene un problema, la advertencia (y el número) salen siempre' );
	$h5 = Numeros::html( PersonaRepository::make()->obtener( '990005' ) );
	$h6 = Numeros::html( PersonaRepository::make()->obtener( '990006' ) );
	$h7 = Numeros::html( PersonaRepository::make()->obtener( '990007' ) );
	$ok( str_contains( $h5, 'duplicado' ) && str_contains( $h6, 'sin número' ) && str_contains( $h7, 'formato antiguo' ), 'avisos de duplicado, sin número y formato antiguo' );
	Numeros::fijar_ver_local( true );
	$ok( Numeros::ver_local() && str_contains( Numeros::html( $persona1 ), 'terr. 76849901' ), '«Mostrar nº territorial»: sale junto al nº de Scouts de España' );
	Numeros::fijar_ver_local( false );
	$ok( ! Numeros::ver_local() && '' !== Numeros::compacto( $persona4 ), 'y al ocultarlo, los problemas siguen avisando en los listados' );
	wp_set_current_user( $tutor_id );
	Numeros::olvidar();
	$ok( '' === Numeros::compacto( $persona4 ) && '' === Numeros::aviso( $persona4 ), 'una familia no ve las advertencias ni los números de los listados de gestión' );
	wp_set_current_user( $secre_id );
	$inactiva = new \AsdeBalears\AreaPrivada\Domain\Persona( 'x', '990099', '', 'Inactiva', 'Ide', '1990-01-01', '', '', array(), '', array() );
	$ok( '' === Numeros::aviso( $inactiva, array( 'numero' => null, 'estado' => Numeracion::FALTA ) ), 'sin membresía activa no se avisa (no cuenta)' );
	$poner_modo( Organizacion::IDENTIFICADOR_LOCAL );
	Numeros::olvidar();
	$hl = Numeros::html( $persona3 );
	$ok( str_contains( $hl, '<strong>76849903</strong>' ) && str_contains( $hl, 'ASDE 990003' ), 'con el nº local como usuario, el nº territorial va en negrita y siempre se ve' );
	$poner_modo( Organizacion::IDENTIFICADOR_ASDE );

	$_GET = array( 'socio' => '990004' );
	$html = ( new AltaUsuariosView() )->render();
	$_GET = array();
	$ok( ! str_contains( $html, 'no se puede usar como usuario' ) && str_contains( $html, '⚠ nº territorial: no válido' ) && 1 === substr_count( $html, 'no válido</span>' ) && str_contains( $html, 'Scouts de España (usuario)' ), 'dar acceso a alguien con el nº territorial no válido: aviso en su ficha, sin bloquearlo, y el usuario es el nº de Scouts de España' );
	$poner_modo( Organizacion::IDENTIFICADOR_LOCAL );
	Numeros::olvidar();
	$_GET = array( 'socio' => '990005' );
	$html = ( new AltaUsuariosView() )->render();
	$_GET = array();
	$ok( str_contains( $html, 'no se puede usar como usuario' ), 'con el nº local como usuario, un duplicado sigue bloqueando el alta (como siempre)' );
	$poner_modo( Organizacion::IDENTIFICADOR_ASDE );

	echo "\n== Parte F · Renombrar las cuentas existentes ==\n";
	// Cuentas «de antes»: usuario = nº local.
	$vieja = wp_insert_user( array( 'user_login' => '76849907', 'user_pass' => wp_generate_password( 24 ), 'role' => Roles::SCOUTER ) );
	$ids[] = $vieja;
	update_user_meta( $vieja, Usuarios::META_SOCIO, '990007' );
	$u_old = get_userdata( $vieja );
	$antes_grupo = get_user_meta( $vieja, Usuarios::META_GRUPO, true );
	$vieja_fam = wp_insert_user( array( 'user_login' => '76849902T', 'user_pass' => wp_generate_password( 24 ), 'role' => Roles::TUTOR ) );
	$ids[]     = $vieja_fam;
	add_user_meta( $vieja_fam, Usuarios::META_REPRESENTADO, '990002' );
	$huerfana = wp_insert_user( array( 'user_login' => '76849999', 'user_pass' => wp_generate_password( 24 ), 'role' => Roles::SCOUTER ) );
	$ids[]    = $huerfana;
	update_user_meta( $huerfana, Usuarios::META_SOCIO, '990444' ); // Una ficha que no está en la tabla.
	$plan = MigracionUsuario::plan( Organizacion::IDENTIFICADOR_ASDE );
	$cambios = array_column( $plan['cambios'], 'nuevo', 'actual' );
	$ok( '990007' === ( $cambios['76849907'] ?? null ) && '990002T' === ( $cambios['76849902T'] ?? null ), 'el plan: la cuenta de una persona pasa a su nº de Scouts de España y la familiar, al del menor más T' );
	$ok( '990444' === ( $cambios['76849999'] ?? null ), 'el vínculo con la ficha manda: aunque la ficha no esté en la tabla de equivalencias, su cuenta pasa a su nº de Scouts de España' );
	$ok( ! isset( $cambios['990004'] ) && $plan['iguales'] >= 1, 'las que ya tienen el formato no se tocan' );
	$conflicto = wp_insert_user( array( 'user_login' => 'prueba-ide-choque', 'user_pass' => wp_generate_password( 24 ), 'role' => Roles::SCOUTER ) );
	$ids[]     = $conflicto;
	$plan_local = MigracionUsuario::plan( Organizacion::IDENTIFICADOR_LOCAL );
	$cambios_l  = array_column( $plan_local['cambios'], 'nuevo', 'actual' );
	$imp_l      = array_column( $plan_local['imposibles'], 'motivo', 'actual' );
	$ok( '76849904' !== ( $cambios_l['990004'] ?? '' ) && isset( $imp_l['990004'] ) && str_contains( $imp_l['990004'], 'nº local válido' ), 'volver al nº local: no se puede si la ficha no tiene un nº local válido (se explica por qué)' );
	$ok( '76849901T' === ( $cambios_l['990001T'] ?? null ) && '76849901T2' === ( $cambios_l['990001T2'] ?? null ), 'y las familiares vuelven al nº local del menor + T / T2 si lo tiene' );
	$res = MigracionUsuario::aplicar( Organizacion::IDENTIFICADOR_ASDE );
	$ok( $res['hechos'] >= 3 && 0 === $res['fallidos'], 'aplicar: renombra las cuentas del plan' );
	$u_nuevo = get_userdata( $vieja );
	$ok( '990007' === $u_nuevo->user_login && '990007' === $u_nuevo->display_name && '990007' === $u_nuevo->user_nicename && '990007' === Usuarios::socio( $vieja ), 'el usuario, el nombre visible y la ruta cambian; el vínculo con la ficha, no' );
	$ok( '' !== (string) get_user_meta( $vieja, Usuarios::META_GRUPO, true ), 'se guarda el grupo de la cuenta antes de perder el prefijo del usuario' );
	$ok( '990002T' === get_userdata( $vieja_fam )->user_login && '990444' === get_userdata( $huerfana )->user_login, 'la familiar pasa a «990002T» y la otra a su nº de Scouts de España' );
	$ok( $vieja === get_user_by( 'login', Identidad::resolver( '76849907' ) )->ID, 'tras renombrar, el nº local de siempre sigue sirviendo para entrar en esa cuenta' );
	$ok( false === get_user_by( 'login', '76849907' ), 'el usuario antiguo ya no existe como tal' );
	$otra = MigracionUsuario::aplicar( Organizacion::IDENTIFICADOR_ASDE );
	$ok( 0 === $otra['hechos'], 'repetirlo no cambia nada más' );

	echo "\n== Parte G · Configuración (wp-admin) ==\n";
	$admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) )[0] ?? null;
	if ( $admin ) {
		wp_set_current_user( $admin->ID );
		ob_start();
		( new \AsdeBalears\AreaPrivada\Admin\OrganizacionPage() )->render();
		$pagina = (string) ob_get_clean();
		$ok( str_contains( $pagina, 'name="identificador"' ) && str_contains( $pagina, 'Renombrar las cuentas existentes' ) && str_contains( $pagina, 'Cuentas que no se pueden cambiar' ), 'la ventana de configuración tiene el selector del usuario de acceso y la herramienta para renombrar las cuentas existentes' );
		$ok( preg_match( '#value="asde"[^>]*checked#', $pagina ) === 1, 'con el parámetro en «Scouts de España», esa opción sale marcada' );
		$ok( str_contains( $pagina, 'Al entrar valen los dos números' ), 'y explica que al entrar valen los dos números' );
	} else {
		echo "OMITIDO  No hay un administrador para probar la ventana de configuración.\n";
	}

	echo "\n== Parte H · Correcciones de la revisión (v0.14.1) ==\n";
	// Límite de intentos: la misma cuenta, escrita de las dos formas, cuenta como una.
	$ok( '990002' === Identificacion::canonico( '76849902' ) && '990002' === Identificacion::canonico( '990002' ) && 'ana@example.invalid' === Identificacion::canonico( 'ana@example.invalid' ) && 'nadie' === Identificacion::canonico( 'nadie' ), 'el límite de intentos usa la cuenta real: el nº local y el de Scouts de España son la misma' );
	$_SERVER['REMOTE_ADDR'] = '192.0.2.' . random_int( 1, 254 );
	foreach ( array( '76849902', '990002', '76849902', '990002', '76849902' ) as $escrito ) {
		\AsdeBalears\AreaPrivada\Auth\Intentos::fallo( Identificacion::canonico( $escrito ) );
	}
	$ok( \AsdeBalears\AreaPrivada\Auth\Intentos::bloqueado( Identificacion::canonico( '990002' ) ) && \AsdeBalears\AreaPrivada\Auth\Intentos::bloqueado( Identificacion::canonico( '76849902' ) ), '5 fallos alternando los dos números bloquean la cuenta (antes hacían falta 5 con cada uno)' );
	\AsdeBalears\AreaPrivada\Auth\Intentos::exito( '990002' );
	// Migración: una familia cuyo nº base no está en la tabla no se da por migrada.
	$fam_sin = wp_insert_user( array( 'user_login' => '76849955T', 'user_pass' => wp_generate_password( 24 ), 'role' => Roles::TUTOR ) );
	$ids[]   = $fam_sin;
	add_user_meta( $fam_sin, Usuarios::META_REPRESENTADO, '990055' );
	$fam_asde = wp_insert_user( array( 'user_login' => '990055T', 'user_pass' => wp_generate_password( 24 ), 'role' => Roles::TUTOR ) );
	$ids[]    = $fam_asde;
	add_user_meta( $fam_asde, Usuarios::META_REPRESENTADO, '990055' );
	$plan_h = MigracionUsuario::plan( Organizacion::IDENTIFICADOR_ASDE );
	$imp_h  = array_column( $plan_h['imposibles'], 'motivo', 'actual' );
	$cam_h  = array_column( $plan_h['cambios'], 'nuevo', 'actual' );
	$ok( isset( $imp_h['76849955T'] ) && str_contains( $imp_h['76849955T'], 'Scouts de España' ) && ! isset( $cam_h['76849955T'] ), 'una cuenta familiar con nº local que no está en la tabla de equivalencias se lista como imposible (no como «ya migrada»)' );
	$ok( ! isset( $imp_h['990055T'] ) && ! isset( $cam_h['990055T'] ), 'y la que ya tiene el nº de Scouts de España no se toca ni se marca como problema' );
	$base_t = SociosTable::table();
	$ok( $plan_h === MigracionUsuario::plan( Organizacion::IDENTIFICADOR_ASDE ), 'el plan es estable (misma entrada, mismo resultado)' );
	// Descarga SEPA: el aviso de carga no se activa en el formulario que descarga un fichero.
	$ok( str_contains( (string) file_get_contents( ASDE_AP_DIR . 'templates/tesoreria/sepa-remesa.php' ), 'value="sepa_descargar"' ) && preg_match( '#<form method="post" data-asde-sin-carga>\s*<\?php wp_nonce_field\( .asde_sepa_remesa_.[^\n]*\n\s*<input type="hidden" name="asde_action" value="sepa_descargar">#', (string) file_get_contents( ASDE_AP_DIR . 'templates/tesoreria/sepa-remesa.php' ) ) === 1 && str_contains( (string) file_get_contents( ASDE_AP_DIR . 'assets/js/area.js' ), "hasAttribute( 'data-asde-sin-carga' )" ), 'el formulario que descarga el XML SEPA lo declara y area.js no muestra la barra de carga en él' );
	// CSV: los números (también negativos) se dejan como están; lo que parece fórmula, no.
	$g = \AsdeBalears\AreaPrivada\Informes\Generador::class;
	$ok( '-12,50' === $g::celda_segura( '-12,50' ) && '0,5' === $g::celda_segura( '0,5' ) && '12.5' === $g::celda_segura( '12.5' ) && '' === $g::celda_segura( '' ) && "'=1+1" === $g::celda_segura( '=1+1' ) && "'-1+1" === $g::celda_segura( '-1+1' ) && "'@SUMA(A1)" === $g::celda_segura( '@SUMA(A1)' ) && "'+34600111222" === $g::celda_segura( '+34600111222' ), 'CSV: un importe negativo sigue siendo un número; lo que parece una fórmula se neutraliza' );
	// El botón de «Actualizar» del listado de usuarios va firmado.
	$_GET = array( 'refrescar' => '1' );
	$metodo = new ReflectionMethod( \AsdeBalears\AreaPrivada\Frontend\Views\UsuariosView::class, 'pide_refrescar' );
	$metodo->setAccessible( true );
	$sin_firma = $metodo->invoke( new \AsdeBalears\AreaPrivada\Frontend\Views\UsuariosView() );
	$_GET      = array( 'refrescar' => '1', '_wpnonce' => wp_create_nonce( 'asde_refrescar_usuarios' ) );
	$con_firma = $metodo->invoke( new \AsdeBalears\AreaPrivada\Frontend\Views\UsuariosView() );
	$_GET      = array( 'refrescar' => '1', '_wpnonce' => 'mala' );
	$mala      = $metodo->invoke( new \AsdeBalears\AreaPrivada\Frontend\Views\UsuariosView() );
	$_GET      = array();
	$ok( ! $sin_firma && $con_firma && ! $mala, '?refrescar=1 solo vale con el nonce del botón (un enlace de otra web no fuerza la lectura del CRM)' );
	$ok( str_contains( (string) file_get_contents( ASDE_AP_DIR . 'templates/personas/listado.php' ), "wp_nonce_url( add_query_arg( 'actualizar'" ) && str_contains( (string) file_get_contents( ASDE_AP_DIR . 'src/Frontend/Views/PersonasView.php' ), "'asde_actualizar_personas'" ), 'y «Actualizar ahora» de Personas también' );
} finally {
	wp_set_current_user( 0 );
	Numeros::olvidar();
	$lista = implode( ',', array_map( 'intval', array_filter( $ids ) ) ) ?: '0';
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . RegistroEmails::table() . " WHERE id > %d AND user_id IN ($lista)", $emails_antes ) ); // phpcs:ignore WordPress.DB
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Logger::table() . " WHERE id > %d AND ( user_id IN ($lista) OR ( objeto = 'user' AND objeto_id IN ($lista) ) OR accion = 'organizacion_guardada' OR ( objeto = 'socio' AND objeto_id LIKE '9900%%' ) )", $log_antes ) ); // phpcs:ignore WordPress.DB
	foreach ( array_unique( array_filter( $ids ) ) as $uid ) {
		delete_transient( 'asde_ap_notices_' . $uid );
		Ambito::olvidar( (int) $uid );
		wp_delete_user( (int) $uid );
	}
	$familias = implode( ',', array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . FamiliasTable::table() . ' WHERE id > %d', $fam_antes ) ) ) ) ?: '0'; // phpcs:ignore WordPress.DB
	$wpdb->query( 'DELETE FROM ' . FamiliasTable::table_miembros() . " WHERE familia_id IN ($familias)" ); // phpcs:ignore WordPress.DB
	$wpdb->query( 'DELETE FROM ' . FamiliasTable::table() . " WHERE id IN ($familias)" ); // phpcs:ignore WordPress.DB
	// Tabla de números: como estaba.
	$falsos = "'" . implode( "','", array_map( static fn( $p ) => $p['numerosocio_c'], $crm->personas ) ) . "'";
	$wpdb->query( 'DELETE FROM ' . SociosTable::table() . " WHERE num_socio_asde IN ($falsos)" ); // phpcs:ignore WordPress.DB
	foreach ( $socios_antes as $asde => $fila ) {
		$ahora = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . SociosTable::table() . ' WHERE num_socio_asde = %s', $asde ), ARRAY_A ); // phpcs:ignore WordPress.DB
		if ( $ahora !== $fila ) {
			$wpdb->replace( SociosTable::table(), $fila );
		}
	}
	delete_transient( $clave_num );
	if ( false !== $cache_num ) {
		set_transient( $clave_num, $cache_num, 10 * MINUTE_IN_SECONDS );
	}
	null === $org_antes ? delete_option( Organizacion::OPTION ) : update_option( Organizacion::OPTION, $org_antes, false );
	Organizacion::olvidar();
	$crm->desactivar();
	Escritura::usar_para_pruebas( null );
	wp_using_ext_object_cache( $cache_externa );
	remove_filter( 'pre_http_request', $cortar_crm, 2 );
	Grupos::olvidar();
	Grupo::forzar( null );
	$quedan = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users} WHERE user_login LIKE 'prueba-ide-%' OR user_login IN ('990001T','990001T2','990002T','990002','990055T','76849955T','990003','990004','990005','990006','990007','990444','76849907','76849902T','76849999')" ); // phpcs:ignore WordPress.DB
	$ok( 0 === $quedan && $org_antes === get_option( Organizacion::OPTION, null ), 'limpieza: sin usuarios de la prueba y con la organización como estaba' );
}

echo $fails ? "\n$fails FALLOS\n" : "\nTODO OK (nada tocado en el CRM real; el sitio queda como estaba)\n";
