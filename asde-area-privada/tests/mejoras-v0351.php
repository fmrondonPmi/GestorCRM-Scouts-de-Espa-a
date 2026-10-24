<?php
/**
 * Pruebas de la v0.35.1 (entorno LOCAL): las bajas del CRM se ven ya (las secciones salen
 * de las membresías vigentes, no de «Secciones actuales», que el CRM recalcula más tarde),
 * «Eliminar de la web» a quien no tiene membresía activa, «Eliminar» en la lista de Familias, la
 * verificación en dos pasos opcional para todos los roles y su código plegado en «Acceso socios».
 * Ejecutar desde app/public del site Local:
 *
 *   wp eval-file wp-content/plugins/asde-area-privada/tests/mejoras-v0351.php
 *
 * Usa el CRM simulado de tests/lib (fichas inventadas: ninguna petición llega al CRM real)
 * y guarda las cachés solo en la memoria de este proceso. Al terminar se borra lo creado
 * (usuarios, familias, pagos, registros), por sus ids: nunca se vacía una tabla.
 */
defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/lib/CrmSimulado.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Auth\Ambito;
use AsdeBalears\AreaPrivada\Crm\Escritura;
use AsdeBalears\AreaPrivada\Crm\Permisos;
use AsdeBalears\AreaPrivada\Crm\PersonaRepository;
use AsdeBalears\AreaPrivada\Domain\Grupo;
use AsdeBalears\AreaPrivada\Domain\Grupos;
use AsdeBalears\AreaPrivada\Frontend\TemplateLoader;
use AsdeBalears\AreaPrivada\Frontend\Views\FamiliasView;
use AsdeBalears\AreaPrivada\Frontend\Views\FichaView;
use AsdeBalears\AreaPrivada\Frontend\Views\PersonasView;
use AsdeBalears\AreaPrivada\Install\Roles;
use AsdeBalears\AreaPrivada\Integraciones\Wordfence;
use AsdeBalears\AreaPrivada\Socios\Eliminadas;
use AsdeBalears\AreaPrivada\Socios\SociosTable;
use AsdeBalears\AreaPrivada\Tesoreria\Config;
use AsdeBalears\AreaPrivada\Tesoreria\FamiliasTable;
use AsdeBalears\AreaPrivada\Tesoreria\MovimientosTable;
use AsdeBalears\AreaPrivada\Usuarios\AltaService;
use AsdeBalears\AreaPrivada\Usuarios\Usuarios;

global $wpdb;

$fails = 0;
$ok    = function ( $cond, string $label ) use ( &$fails ) {
	if ( ! $cond ) {
		++$fails;
	}
	echo ( $cond ? 'OK   ' : 'FAIL ' ), $label, "\n";
};

// ---------------------------------------------------------------------------
echo "\n== A. Secciones de las membresías vigentes (PersonaRepository::con_secciones_vigentes) ==\n";
$hoy     = wp_date( 'Y-m-d' );
$antes   = wp_date( 'Y-m-d', strtotime( '-200 days' ) );
$luego   = wp_date( 'Y-m-d', strtotime( '+30 days' ) );
$entrada = static fn( string $actuales, array $membresias ): array => array(
	'id'     => 'x',
	'fields' => array( 'stic_relationship_type_c' => $actuales ),
	'links'  => array( Escritura::ENLACE_MEMBRESIAS => $membresias ),
);
$memb    = static fn( string $tipo, string $inicio, string $fin = '' ): array => array(
	'id'                => 'm',
	'relationship_type' => $tipo,
	'start_date'        => $inicio,
	'end_date'          => $fin,
);
$r = PersonaRepository::con_secciones_vigentes(
	array(
		$entrada( '^Castores^', array( $memb( 'Castores', $antes, $hoy ) ) ),
		$entrada( '^^', array( $memb( 'Lobatos', $antes ) ) ),
		$entrada( '^Scouts^', array() ),
		$entrada( '^Rovers^', array( $memb( 'Rovers', $antes, $luego ), $memb( 'Scouters', $antes ) ) ),
	)
);
$ok( '' === $r[0]['fields']['stic_relationship_type_c'], 'el caso del usuario: baja con la fecha de hoy y «Secciones actuales» aún con Castores → sin secciones' );
$ok( '^Lobatos^' === $r[1]['fields']['stic_relationship_type_c'], 'y al revés: una membresía vigente que el CRM aún no ha puesto en la ficha cuenta' );
$ok( '^Scouts^' === $r[2]['fields']['stic_relationship_type_c'], 'sin ninguna membresía visible se deja lo que dice el CRM (puede que el grupo no las vea)' );
$ok( '^Rovers^,^Scouters^' === $r[3]['fields']['stic_relationship_type_c'], 'con la baja puesta para más adelante sigue activa; y caben varias' );

// ---------------------------------------------------------------------------
// Preparación: grupo, CRM simulado y cuentas.
// ---------------------------------------------------------------------------
$grupo = Grupos::por_numero( '684' ) ?? ( array_values( Grupos::todos() )[0] ?? null );
if ( ! $grupo || '' === $grupo['crm_id'] ) {
	exit( "Hace falta un grupo con la conexión al CRM comprobada.\n" );
}
foreach ( get_users( array( 'search' => 'prueba-v0351-*', 'search_columns' => array( 'user_login' ) ) ) as $u ) {
	exit( 'Ya existe el usuario ' . $u->user_login . " (¿una ejecución anterior interrumpida?). Bórralo antes de repetir la prueba.\n" );
}
$max_id       = static fn( string $t ) => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(MAX(id), 0) FROM %i', $t ) );
$log_antes    = $max_id( Logger::table() );
$movs_antes   = $max_id( MovimientosTable::table() );
$socios_antes = array_column( (array) $wpdb->get_results( 'SELECT * FROM ' . SociosTable::table(), ARRAY_A ), null, 'num_socio_asde' ); // phpcs:ignore WordPress.DB
$opcion_elim  = 'asde_ap_eliminadas_' . $grupo['numero'];
$elim_antes   = get_option( $opcion_elim, null );

$cache_externa = wp_using_ext_object_cache( true );
$cortar_crm    = static function ( $pre, $args, $url ) {
	return false === $pre && str_contains( (string) $url, '/rest.php' ) ? new WP_Error( 'prueba', 'CRM real cortado durante la prueba' ) : $pre;
};
add_filter( 'pre_http_request', $cortar_crm, 2, 3 );
add_filter( 'pre_wp_mail', '__return_true' ); // No sale ningún email.

$P   = array( 'Asde_Prueba_Crm', 'persona' );
$crm = new Asde_Prueba_Crm(
	array(
		$P( 81, 'Ana', 'Bajahoy Font', '2019-03-03', '' ),
		$P( 82, 'Bru', 'Sigue Mas', '2016-04-04', '' ),
		$P( 83, 'Cai', 'Sinmembresias Roig', '2012-05-05', 'Scouts' ),
		$P( 84, 'Dani', 'Antigua Pons', '2008-06-06', '' ),
		$P( 85, 'Eva', 'Secretaria Vidal', '1990-01-01', '' ),
	),
	$grupo['crm_id']
);
$id_de = static fn( int $n ): string => sprintf( 'de000000-0000-4000-8000-%012d', 9900 + $n );
$crm->alta_membresia( $id_de( 81 ), 'Castores', $antes, $hoy );
$crm->alta_membresia( $id_de( 82 ), 'Lobatos', $antes );
$crm->alta_membresia( $id_de( 84 ), 'Scouts', '2015-09-01', '2017-06-30' );
$crm->alta_membresia( $id_de( 85 ), 'Scouters', '2015-09-01' );
// El CRM aún no ha recalculado «Secciones actuales» de Ana tras su baja de hoy (lo que vio el usuario).
foreach ( $crm->personas as $i => $fila ) {
	if ( $id_de( 81 ) === $fila['id'] ) {
		$crm->personas[ $i ]['stic_relationship_type_c'] = '^Castores^';
	}
}
Grupo::forzar( $grupo );
$crm->activar();
add_filter( 'asde_ap_instantanea_segundos', '__return_zero' ); // Siempre lo que hay en el CRM simulado (y la instantánea del grupo, sin tocar).
Permisos::olvidar( $grupo['numero'] );

$ids   = array();
$crear = static function ( string $login, string $rol, array $meta ) use ( &$ids, $grupo ): int {
	$id = wp_insert_user(
		array(
			'user_login' => $login,
			'user_email' => '',
			'user_pass'  => wp_generate_password( 24 ),
			'role'       => $rol,
		)
	);
	update_user_meta( $id, Usuarios::META_GRUPO, $grupo['id'] );
	update_user_meta( $id, Usuarios::META_ESTADO, Usuarios::ACTIVO );
	foreach ( $meta as $k => $v ) {
		foreach ( (array) $v as $uno ) {
			add_user_meta( $id, $k, $uno );
		}
	}
	$ids[] = $id;
	return $id;
};
$u_secre  = $crear( 'prueba-v0351-secre', Roles::SECRETARIA, array( Usuarios::META_SOCIO => '990085' ) );
$u_propia = $crear( 'prueba-v0351-dani', Roles::PARTICIPANTE, array( Usuarios::META_SOCIO => '990084' ) );
$u_solo   = $crear( 'prueba-v0351-fam1', Roles::TUTOR, array( Usuarios::META_REPRESENTADO => '990084' ) );
$u_dos    = $crear( 'prueba-v0351-fam2', Roles::TUTOR, array( Usuarios::META_REPRESENTADO => array( '990084', '990082' ) ) );
$como     = static function ( int $uid ): void {
	wp_set_current_user( $uid );
	Ambito::olvidar( $uid );
};
$ver = static function ( $vista, array $get ): string {
	$_GET = $get;
	try {
		return $vista->render();
	} finally {
		$_GET = array();
	}
};
$familias    = array();
$grupos_orig = get_option( 'asde_ap_grupos' );
$mas_grupos  = static fn() => (array) $grupos_orig + array(
	'998' => array(
		'nombre'  => 'Grupo de prueba 998',
		'usuario' => '',
		'crm_id'  => '',
	),
);

try {
	// -----------------------------------------------------------------------
	echo "\n== B. Las bajas del CRM se ven ya ==\n";
	$repo  = PersonaRepository::make();
	$nums  = array_map( static fn( $p ) => $p->num_socio_asde, $repo->todas( true ) );
	$ok( ! in_array( '990081', $nums, true ) && in_array( '990082', $nums, true ) && in_array( '990083', $nums, true ), 'Ana (baja de hoy, «Secciones actuales» sin recalcular) ya no sale entre las activas; Bru y Cai sí' );
	$ana = $repo->obtener( '990081' );
	$ok( $ana && ! $ana->tiene_membresia_activa() && $repo->obtener( '990082' )->tiene_membresia_activa(), 'su ficha dice que no tiene membresía activa (la de Bru, sí)' );
	$ok( ! in_array( '990081', array_map( static fn( $p ) => $p->num_socio_asde, $repo->buscar( 'Bajahoy' ) ), true ) && in_array( '990081', array_map( static fn( $p ) => $p->num_socio_asde, $repo->buscar( 'Bajahoy', 30, false ) ), true ), 'el buscador tampoco la da por activa (y sí la encuentra entre todas)' );
	$como( $u_secre );
	$h = $ver( new PersonasView(), array( 'ver' => 'inactivas' ) );
	$ok( str_contains( $h, 'Bajahoy Font' ) && ! str_contains( $h, 'Sigue Mas' ), 'en Personas sale en «Sin membresía activa»' );

	// -----------------------------------------------------------------------
	echo "\n== C. «Eliminar de la web» (sin membresía activa) ==\n";
	$fam_dani   = (int) FamiliasTable::crear( array( '990084', '990082' ) );
	$familias[] = $fam_dani;
	$h          = $ver( new FichaView(), array( 'socio' => '990084' ) );
	$ok( str_contains( $h, 'name="asde_action" value="eliminar_de_la_web"' ) && str_contains( $h, 'data-asde-confirmar="¿Eliminar a Dani Antigua Pons de la web?' ), 'en la ficha de quien no tiene membresía activa, «Eliminar de la web» (con confirmación)' );
	$h = $ver( new FichaView(), array( 'socio' => '990082' ) );
	$ok( ! str_contains( $h, 'eliminar_de_la_web' ), 'en la de quien la tiene, no' );
	$r = Eliminadas::eliminar( $repo->obtener( '990082' ), $u_secre );
	$ok( is_wp_error( $r ), 'y tampoco se deja por detrás: ' . ( is_wp_error( $r ) ? $r->get_error_message() : '' ) );
	// Doc 59, RC-USU-1: otro grupo de la instalación (998) con una cuenta familiar de Dani y la cuenta propia de Cai.
	add_filter( 'pre_option_asde_ap_grupos', $mas_grupos );
	Grupos::olvidar();
	$u_otro_fam = $crear( 'prueba-v0351-otrofam', Roles::TUTOR, array( Usuarios::META_REPRESENTADO => '990084' ) );
	$u_otro_cai = $crear( 'prueba-v0351-otrocai', Roles::PARTICIPANTE, array( Usuarios::META_SOCIO => '990083' ) );
	update_user_meta( $u_otro_fam, Usuarios::META_GRUPO, '998' );
	update_user_meta( $u_otro_cai, Usuarios::META_GRUPO, '998' );
	$ok( ! Usuarios::del_grupo_actual( get_userdata( $u_otro_fam ) ) && Usuarios::del_grupo_actual( get_userdata( $u_dos ) ), 'se distingue una cuenta del grupo 998 de una del grupo actual' );
	$r = Eliminadas::eliminar( $repo->obtener( '990084' ), $u_secre );
	$ok( is_array( $r ) && $r['cuenta'] && 2 === $r['familiares'] && 1 === $r['desactivadas'] && '' !== $r['familia'], 'Dani: sale de su familia, su cuenta se desactiva, sale de las 2 cuentas familiares y la que no representa a nadie más se desactiva' );
	$ok( Usuarios::ACTIVO === Usuarios::estado( get_userdata( $u_otro_fam ) ) && array( '990084' ) === array_values( Usuarios::representados( $u_otro_fam ) ), 'la cuenta familiar del otro grupo no se toca: sigue activa y con Dani (RC-USU-1)' );
	$alta = AltaService::make()->alta_persona( '990083', array( Roles::SCOUTER ), array( 'scouts' ) );
	$ok( ! $alta->ok && str_contains( implode( ' ', $alta->errores ), 'es de otro grupo' ) && '998' === get_user_meta( $u_otro_cai, Usuarios::META_GRUPO, true ) && ! in_array( Roles::SCOUTER, (array) get_userdata( $u_otro_cai )->roles, true ), 'dar acceso desde este grupo a quien tiene la cuenta en otro: error, y la cuenta sigue en su grupo y sin el rol nuevo (RC-USU-1)' );
	remove_filter( 'pre_option_asde_ap_grupos', $mas_grupos );
	Grupos::olvidar();
	$ok( array( '990082' ) === FamiliasTable::obtener( $fam_dani )['miembros'], 'en su familia se queda Bru' );
	$ok( Usuarios::DESACTIVADO === Usuarios::estado( get_userdata( $u_propia ) ) && Usuarios::DESACTIVADO === Usuarios::estado( get_userdata( $u_solo ) ), 'su cuenta y la familiar que solo la representaba a ella, desactivadas' );
	$ok( Usuarios::ACTIVO === Usuarios::estado( get_userdata( $u_dos ) ) && array( '990082' ) === array_values( Usuarios::representados( $u_dos ) ), 'la familiar que también representa a Bru sigue, ya sin Dani' );
	$h = $ver( new PersonasView(), array( 'ver' => 'todas' ) );
	$ok( ! str_contains( $h, 'Antigua Pons' ) && str_contains( $h, 'Bajahoy Font' ), 'ya no sale en Personas (ni en «Todas»)' );
	$ok( str_contains( (string) $wpdb->get_var( $wpdb->prepare( 'SELECT accion FROM %i WHERE id > %d AND accion = %s AND objeto_id = %s', Logger::table(), $log_antes, 'persona_eliminada_web', '990084' ) ), 'persona_eliminada_web' ), 'queda en el registro' );
	$crm->alta_membresia( $id_de( 84 ), 'Rovers', $antes );
	$h = $ver( new PersonasView(), array( 'ver' => 'activas' ) );
	$ok( str_contains( $h, 'Antigua Pons' ) && ! Eliminadas::esta( '990084' ), 'si vuelve a tener una membresía activa en el CRM, vuelve a salir (y se le quita la marca)' );

	// -----------------------------------------------------------------------
	echo "\n== D. Familias: «Eliminar» en la lista ==\n";
	$vacia      = (int) FamiliasTable::crear( array( '990083' ) );
	$con_pago   = (int) FamiliasTable::crear( array( '990081' ) );
	$familias[] = $vacia;
	$familias[] = $con_pago;
	FamiliasTable::quitar( $vacia, '990083' );
	MovimientosTable::registrar( $con_pago, Config::ronda_actual(), MovimientosTable::PAGO, $hoy, 10.0, 'efectivo' );
	FamiliasTable::quitar( $con_pago, '990081' );
	$n_vacia = (string) FamiliasTable::obtener( $vacia )['numero'];
	$n_pago  = (string) FamiliasTable::obtener( $con_pago )['numero'];
	$h       = $ver( new FamiliasView(), array() );
	$ok( 1 === preg_match( '/name="familia" value="' . $vacia . '">\s*<input type="hidden" name="desde" value="lista">/', $h ) && str_contains( $h, esc_attr( '¿Eliminar la familia ' . $n_vacia . '? No tiene socios.' ) ), 'la familia sin socios ni nada que cuelgue de ella tiene «Eliminar» en la lista' );
	$ok( ! preg_match( '/name="familia" value="' . $con_pago . '">\s*<input type="hidden" name="desde"/', $h ) && str_contains( $h, 'Tiene pagos registrados: no se puede eliminar.' ), 'la que tiene pagos no: dice por qué' );
	$ok( ! preg_match( '/name="familia" value="' . $fam_dani . '">\s*<input type="hidden" name="desde"/', $h ), 'la que tiene socios, tampoco' );
	$ok( is_wp_error( FamiliasTable::borrar( $con_pago ) ) && is_wp_error( FamiliasTable::borrar( $fam_dani ) ), 'y no se deja borrar por detrás' );
	$ok( true === FamiliasTable::borrar( $vacia ) && null === FamiliasTable::obtener( $vacia ), 'la vacía se elimina' );
	$h = $ver( new FamiliasView(), array( 'familia' => $con_pago ) );
	$ok( str_contains( $h, 'Tiene pagos registrados: no se puede eliminar.' ) && ! str_contains( $h, 'Eliminar esta familia' ), 'en su página, lo mismo' );

	// -----------------------------------------------------------------------
	echo "\n== E. Verificación en dos pasos: opcional para todos ==\n";
	if ( Wordfence::disponible() ) {
		$ajustes = \WordfenceLS\Controller_Settings::shared();
		$roles   = array( Roles::SCOUTER, Roles::SECRETARIA, Roles::COORDINACION, Roles::TESORERIA, Roles::TUTOR, Roles::PARTICIPANTE );
		// Wordfence guarda «obligatoria» como la fecha desde la que lo es y «permitida» como una capability del rol.
		$permitida = static fn( string $rol ): bool => ! empty( ( get_option( $wpdb->prefix . 'user_roles' )[ $rol ]['capabilities'] ?? array() )[ \WordfenceLS\Controller_Permissions::CAP_ACTIVATE_2FA_SELF ] );
		$estado    = static fn( string $rol ): string => false !== $ajustes->get_required_2fa_role_activation_time( $rol ) ? 'required' : ( $permitida( $rol ) ? 'optional' : 'disabled' );
		$previos   = array();
		foreach ( $roles as $rol ) {
			$previos[ 'enabled-roles.' . $rol ] = $estado( $rol );
		}
		$version = get_option( 'asde_ap_wordfence_config' );
		try {
			$ajustes->set_multiple( array( 'enabled-roles.' . Roles::SCOUTER => 'required' ) );
			update_option( 'asde_ap_wordfence_config', 2, false );
			$ok( Wordfence::configurar() && Wordfence::VERSION === (int) get_option( 'asde_ap_wordfence_config' ), 'al actualizar se vuelve a aplicar la configuración (versión ' . Wordfence::VERSION . ')' );
			$ok( array() === array_filter( $roles, static fn( $rol ) => 'optional' !== $estado( $rol ) ), 'opcional para Scouter, Secretaría, Coordinación, Tesorería, familias y participantes (permitida y no obligatoria)' );
			$ok( ! Wordfence::configurar(), 'y solo una vez (después la puede cambiar el administrador en Wordfence)' );
		} finally {
			$ajustes->set_multiple( $previos );
			update_option( 'asde_ap_wordfence_config', $version, false );
		}
	} else {
		echo "     (Wordfence no está activo en este sitio: se salta)\n";
	}

	// -----------------------------------------------------------------------
	echo "\n== F. «Acceso socios»: el código, plegado ==\n";
	$vars = array(
		'grupos'        => array( 'g' => array( 'id' => 'g', 'numero' => '999', 'nombre' => 'Grupo Scout Ejemplo' ) ),
		'grupo'         => 'g',
		'usuario'       => '',
		'error'         => '',
		'activada'      => false,
		'redirect_to'   => '',
		'url_recuperar' => '#',
		'url_solicitar' => '#',
	);
	$h = TemplateLoader::render( 'acceso/formulario', $vars + array( 'pedir_codigo' => false ) );
	$ok( 1 === preg_match( '/<details class="asde-acceso__codigo">\s*<summary>Código de verificación <span class="asde-help asde-help--inline">\(solo si tienes activada la verificación en dos pasos\)<\/span><\/summary>/', $h ) && 1 === preg_match( '/name="wfls-token"[^>]*maxlength="24"\s*>/', $h ), 'normalmente, plegado (sin «open») y el campo sin «required»' );
	$h = TemplateLoader::render( 'acceso/formulario', $vars + array( 'pedir_codigo' => true ) );
	$ok( str_contains( $h, '<details class="asde-acceso__codigo asde-destacado" open>' ) && str_contains( $h, 'required autofocus' ), 'cuando Wordfence pide el código, abierto, destacado y obligatorio' );
} catch ( Throwable $e ) {
	++$fails;
	echo 'FAIL excepcion: ', $e->getMessage(), ' (', basename( $e->getFile() ), ':', $e->getLine(), ")\n";
} finally {
	remove_filter( 'pre_option_asde_ap_grupos', $mas_grupos );
	Grupos::olvidar();
	$crm->desactivar();
	remove_filter( 'asde_ap_instantanea_segundos', '__return_zero' );
	remove_filter( 'pre_http_request', $cortar_crm, 2 );
	remove_filter( 'pre_wp_mail', '__return_true' );
	wp_using_ext_object_cache( $cache_externa );
	wp_set_current_user( 0 );
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id > %d', MovimientosTable::table(), $movs_antes ) ); // phpcs:ignore WordPress.DB
	foreach ( array_filter( $familias ) as $fid ) {
		$wpdb->delete( FamiliasTable::table_miembros(), array( 'familia_id' => $fid ) );
		$wpdb->delete( FamiliasTable::table(), array( 'id' => $fid ) );
	}
	null === $elim_antes ? delete_option( $opcion_elim ) : update_option( $opcion_elim, $elim_antes, false );
	$lista = implode( ',', array_map( 'intval', $ids ) ) ?: '0';
	$wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE id > %d AND ( user_id IN ($lista) OR objeto_id IN ('990081','990082','990083','990084','990085') OR objeto = 'familia' )", Logger::table(), $log_antes ) ); // phpcs:ignore WordPress.DB
	foreach ( $ids as $uid ) {
		delete_transient( 'asde_ap_notices_' . $uid );
		Ambito::olvidar( (int) $uid );
		wp_delete_user( (int) $uid );
	}
	$ahora = array_column( (array) $wpdb->get_results( 'SELECT * FROM ' . SociosTable::table(), ARRAY_A ), null, 'num_socio_asde' ); // phpcs:ignore WordPress.DB
	foreach ( array_diff_key( $ahora, $socios_antes ) as $asde => $f ) {
		$wpdb->delete( SociosTable::table(), array( 'num_socio_asde' => (string) $asde ) );
	}
	Permisos::olvidar( $grupo['numero'] );
	Grupo::forzar( null );

	$quedan = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users} WHERE user_login LIKE 'prueba-v0351-%'" ); // phpcs:ignore WordPress.DB
	$filas  = $familias ? (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . FamiliasTable::table() . ' WHERE id IN (' . implode( ',', array_map( 'intval', $familias ) ) . ')' ) : 0; // phpcs:ignore WordPress.DB
	$ok( 0 === $quedan && 0 === $filas && $movs_antes === $max_id( MovimientosTable::table() ), 'limpieza: sin usuarios, familias ni pagos de la prueba' );
}

echo "\n", ( $fails ? "$fails FALLOS" : 'TODO OK (nada tocado en el CRM real; el sitio queda como estaba)' ), "\n";
