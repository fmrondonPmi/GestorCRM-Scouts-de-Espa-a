<?php
/**
 * Pruebas de la v0.7 (entorno LOCAL). Ejecutar desde app/public del site Local:
 *
 *   wp eval-file wp-content/plugins/asde-area-privada/tests/fichas-escritura.php
 *
 * ⚠️ NO TOCA EL CRM REAL: toda escritura va a un CRM simulado (Escritura::usar_para_pruebas) y, desde el doc 60,
 * también las lecturas (fichas inventadas de tests/lib/CrmSimulado.php; antes leía las fichas reales del 684).
 *
 * - Escritura: permisos, campos prohibidos, verificación y auditoría.
 * - Permisos de la ficha por rol (Secretaría, scouter, Coordinación, familia, la propia persona).
 * - Ficha: validación por tipo, guardado, conflictos y propuestas.
 * - Cola de cambios: proponer, sustituir, aprobar (con conflicto), rechazar, retirar.
 * - Numeración con escritura: asignar, corregir formato, duplicados, por tandas.
 * - «Voluntario sin sección».
 * - Corrección en segundo plano: tarea, tandas, cerrojo, detener, fallidas, monitor.
 * Deja todo como estaba (usuarios, filas, tabla de números, emails de Mailpit de la prueba).
 */
defined( 'ABSPATH' ) || exit;

use AsdeBalears\AreaPrivada\Auth\Ambito;
use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Crm\EscritorCrm;
use AsdeBalears\AreaPrivada\Crm\Escritura;
use AsdeBalears\AreaPrivada\Crm\PersonaRepository;
use AsdeBalears\AreaPrivada\Domain\Grupo;
use AsdeBalears\AreaPrivada\Domain\Grupos;
use AsdeBalears\AreaPrivada\Domain\Secciones;
use AsdeBalears\AreaPrivada\Fichas\Cambios;
use AsdeBalears\AreaPrivada\Fichas\CamposFicha;
use AsdeBalears\AreaPrivada\Fichas\FichaService;
use AsdeBalears\AreaPrivada\Fichas\PermisosFicha;
use AsdeBalears\AreaPrivada\Frontend\Notices;
use AsdeBalears\AreaPrivada\Frontend\Views\PersonasView;
use AsdeBalears\AreaPrivada\Install\Roles;
use AsdeBalears\AreaPrivada\Socios\Numeracion;
use AsdeBalears\AreaPrivada\Socios\SociosTable;
use AsdeBalears\AreaPrivada\Socios\TareaNumeracion;
use AsdeBalears\AreaPrivada\Usuarios\AltaService;
use AsdeBalears\AreaPrivada\Usuarios\RegistroEmails;
use AsdeBalears\AreaPrivada\Usuarios\Usuarios;

require_once ABSPATH . 'wp-admin/includes/user.php';
require_once __DIR__ . '/lib/CrmSimulado.php';
global $wpdb;

$fails = 0;
$ok    = function ( $cond, string $label ) use ( &$fails ) {
	if ( ! $cond ) {
		++$fails;
	}
	echo ( $cond ? 'OK   ' : 'FAIL ' ), $label, "\n";
};
$log_antes     = (int) $wpdb->get_var( 'SELECT MAX(id) FROM ' . Logger::table() );
$socios_antes  = (array) $wpdb->get_results( 'SELECT * FROM ' . SociosTable::table(), ARRAY_A );
foreach ( get_users( array( 'search' => 'prueba-fichas-*', 'search_columns' => array( 'user_login' ) ) ) as $viejo ) {
	exit( 'Ya existe el usuario ' . $viejo->user_login . " (¿una ejecución anterior interrumpida?). Bórralo antes de repetir la prueba.\n" );
}

// Doc 60: hasta ahora esta prueba LEÍA EL CRM REAL y trabajaba con fichas reales del grupo 684 (y las partes de la
// numeración se saltaban si en el Local no había fichas sin número, con formato antiguo o repetidas). Ahora todo va
// con fichas inventadas del CRM simulado (tests/lib), los emails se capturan y las cachés van en memoria.
$cache_externa = wp_using_ext_object_cache( true );
$cortadas      = 0;
$cortar_crm    = static function ( $pre, $args, $url ) use ( &$cortadas ) {
	if ( false === $pre && str_contains( (string) $url, '/rest.php' ) ) {
		++$cortadas;
		return new WP_Error( 'prueba', 'CRM real cortado durante la prueba' );
	}
	return $pre;
};
add_filter( 'pre_http_request', $cortar_crm, 2, 3 );
add_filter( 'pre_wp_mail', '__return_true' );

// CRM simulado: guarda lo "escrito" en memoria y lo devuelve al releer.
$falso = new class() implements EscritorCrm {
	public array $escrito  = array();
	public array $rechazar = array();
	public array $llamadas = array();
	public array $ids      = array(); // Todas las fichas "escritas" (no se vacía): para deshacer al final.
	public function set_entry( string $module, string $id, array $values ): string {
		$this->llamadas[] = array( $module, $id, $values );
		$this->ids[]      = $id;
		foreach ( $values as $k => $v ) {
			if ( ! in_array( $k, $this->rechazar, true ) ) {
				$this->escrito[ $id ][ $k ] = (string) $v;
			}
		}
		return $id;
	}
	public function get_entry( string $module, string $id, array $fields, array $links = array() ): ?array {
		return array(
			'id'     => $id,
			'fields' => array_intersect_key( $this->escrito[ $id ] ?? array(), array_flip( $fields ) ),
			'links'  => array(),
		);
	}
};
Escritura::usar_para_pruebas( $falso );
// Las correcciones en segundo plano de la prueba nunca salen de este proceso (ni
// petición interna ni WP-Cron): en otra petición escribirían en el CRM real.
add_filter( 'asde_ap_tarea_lanzar', '__return_false' );
$grupo = Grupos::por_numero( '684' );
if ( ! $grupo || '' === $grupo['crm_id'] ) {
	exit( "Hace falta el grupo 684 con la conexión al CRM comprobada.\n" );
}
// Fichas inventadas (nº Scouts de España 990101…; nº local del 684 en la serie 09xx, que no usa nadie).
$anio   = (int) wp_date( 'Y' );
$P      = array( 'Asde_Prueba_Crm', 'persona' );
$ficha  = static fn( int $n, string $nombre, string $nac, string $secc, string $local ) => $P( $n, $nombre, 'Fichas Prueba', $nac, $secc, array( 'numsocioantiguo_enficha_c' => $local, 'email1' => 'fichas' . $n . '@example.invalid' ) );
$fichas = array(
	$ficha( 101, 'Abel', '1980-01-01', 'Scouters', '76840901' ),
	$ficha( 102, 'Berta', '1981-01-01', 'Scouters', '76840902' ),
	$ficha( 103, 'Carles', '1982-01-01', 'Scouters', '76840903' ),
	$ficha( 104, 'Dolors', '1983-01-01', 'Scouters', '76840904' ),
	$ficha( 105, 'Elia', ( $anio - 15 ) . '-03-03', 'Scouts', '76840905' ),
	$ficha( 106, 'Fermí', ( $anio - 9 ) . '-03-03', 'Lobatos', '76840906' ),
	$ficha( 107, 'Gala', ( $anio - 12 ) . '-03-03', 'Scouts', '76840907' ),
	$ficha( 108, 'Hugo', ( $anio - 10 ) . '-03-03', 'Lobatos', '' ),         // Sin número (FALTA).
	$ficha( 109, 'Irene', ( $anio - 11 ) . '-03-03', 'Lobatos', '6840909' ), // Formato antiguo (CORREGIDO)…
	$ficha( 110, 'Joan', ( $anio - 11 ) . '-03-03', 'Lobatos', '6840910' ),
	$ficha( 111, 'Kira', ( $anio - 11 ) . '-03-03', 'Lobatos', '6840911' ),
	$ficha( 112, 'Lluc', ( $anio - 11 ) . '-03-03', 'Lobatos', '6840912' ),
	$ficha( 113, 'Marta', ( $anio - 11 ) . '-03-03', 'Lobatos', '6840913' ),
	$ficha( 114, 'Nil', ( $anio - 11 ) . '-03-03', 'Lobatos', '6840914' ),
	$ficha( 115, 'Olga', ( $anio - 13 ) . '-03-03', 'Scouts', '76840915' ),  // Repetidos (DUPLICADO).
	$ficha( 116, 'Pau', ( $anio - 13 ) . '-03-03', 'Scouts', '76840915' ),
);
$crm = new Asde_Prueba_Crm( $fichas, $grupo['crm_id'] );
$crm->metadatos = true; // La ficha necesita los tipos de los campos (get_module_fields).
Grupo::forzar( $grupo );
$crm->activar();
$repo = PersonaRepository::make();
$num  = new Numeracion( $repo );
$num->analizar_todo( true );

$activas = $repo->todas( true );
$por_num = array();
foreach ( $activas as $x ) {
	$por_num[ $x->num_socio_asde ] = $x;
}
$adulto  = $por_num['990101'] ?? null;
$adulto2 = $por_num['990102'] ?? null;
$adulto3 = $por_num['990103'] ?? null;
$adulto4 = $por_num['990104'] ?? null;
$teen    = $por_num['990105'] ?? null;
$nino    = $por_num['990106'] ?? null;
$otro    = $por_num['990107'] ?? null;
$ok( $adulto && $adulto2 && $adulto3 && $adulto4 && $teen && $nino && $otro && $adulto->es_adulto_voluntario() && 'lobatos' === $nino->seccion_educativa() && 'scouts' === $otro->seccion_educativa(), 'fichas de prueba (inventadas): 4 adultos, participante 14-17, menor de 14 y otra sección' );
if ( ! ( $adulto && $adulto2 && $adulto3 && $adulto4 && $teen && $nino && $otro ) ) {
	exit( "\nNo se puede continuar.\n" );
}

$uids   = array();
$crear  = static function ( string $sufijo, array $roles, array $metas ) use ( &$uids ) {
	$uid = wp_insert_user(
		array(
			'user_login' => 'prueba-fichas-' . $sufijo . '-' . wp_rand( 1000, 9999 ),
			'user_pass'  => wp_generate_password( 24 ),
			'role'       => '',
		)
	);
	foreach ( $roles as $r ) {
		( new WP_User( $uid ) )->add_role( $r );
	}
	foreach ( $metas as $k => $v ) {
		foreach ( (array) $v as $vv ) {
			add_user_meta( $uid, $k, $vv );
		}
	}
	update_user_meta( $uid, Usuarios::META_GRUPO, '684' );
	$uids[] = $uid;
	return get_userdata( $uid );
};
$u_secre = $crear( 'secre', array( Roles::SCOUTER, Roles::SECRETARIA ), array( Usuarios::META_SOCIO => $adulto->num_socio_asde, Usuarios::META_SECCION => 'rovers' ) );
$u_scout = $crear( 'scout', array( Roles::SCOUTER ), array( Usuarios::META_SOCIO => $adulto2->num_socio_asde, Usuarios::META_SECCION => $nino->seccion_educativa() ) );
$u_coord = $crear( 'coord', array( Roles::SCOUTER, Roles::COORDINACION ), array( Usuarios::META_SOCIO => $adulto3->num_socio_asde, Usuarios::META_SECCION => 'rovers' ) );
$u_tutor = $crear( 'tutor', array( Roles::TUTOR ), array( Usuarios::META_REPRESENTADO => $nino->num_socio_asde ) );
$u_teen  = $crear( 'teen', array( Roles::PARTICIPANTE ), array( Usuarios::META_SOCIO => $teen->num_socio_asde ) );
$perm    = static fn( WP_User $u, $p ) => new PermisosFicha( Ambito::de( $u ), $p );

// La tarea de corrección que ya hubiera en el grupo (parte G): la limpieza no la toca si está en marcha.
$clave_tarea = 'asde_ap_tarea_num_684';
wp_cache_delete( $clave_tarea, 'options' );
$tarea_previa = get_option( $clave_tarea, null );

// Si algo falla a media prueba, la limpieza del final se hace igualmente.
try {
// ---------------------------------------------------------------------------
echo "\n== Parte A · Escritura en el CRM (simulado) ==\n";
$ok( Escritura::disponible(), 'con el CRM simulado, la escritura está disponible' );
$ok( is_wp_error( Escritura::guardar( $nino, array( 'numerosocio_c' => '1' ), 'prueba' ) ), 'el nº Scouts de España no se puede escribir desde la web' );
$ok( is_wp_error( Escritura::guardar( $nino, array( 'numsocioantiguo_enficha_c' => '76849999' ), 'prueba' ) ), 'el nº local solo se escribe desde la numeración' );
$r = Escritura::guardar( $nino, array( 'phone_mobile' => '600000001' ), 'prueba' );
$ok( is_array( $r ) && array( 'phone_mobile' ) === $r['guardados'] && '600000001' === $falso->escrito[ $nino->id ]['phone_mobile'], 'escritura normal: se guarda y se comprueba releyendo' );
$falso->rechazar = array( 'alergias_c' );
$r               = Escritura::guardar( $nino, array( 'alergias_c' => 'Prueba', 'apodo_c' => 'Prueba' ), 'prueba' );
$falso->rechazar = array();
$ok( is_array( $r ) && array( 'apodo_c' ) === $r['guardados'] && array( 'alergias_c' ) === $r['rechazados'], 'lo que el CRM no acepta (p. ej. un permiso por campo) se detecta al releer' );
$ultimo = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Logger::table() . ' WHERE accion = %s ORDER BY id DESC LIMIT 1', 'crm_ficha_editada' ), ARRAY_A );
$ok( $ultimo && str_contains( (string) $ultimo['detalles'], 'apodo_c' ) && ! str_contains( (string) $ultimo['detalles'], 'Prueba' ), 'auditoría: nombres de los campos, nunca los valores' );
$ok( Escritura::iguales( '1', 'true' ) && Escritura::iguales( '', '0' ) && Escritura::iguales( 'si', 'Sí' ) && ! Escritura::iguales( 'a', 'b' ), 'comparación tolerante (1/true, vacío/0, si/Sí)' );
Escritura::usar_para_pruebas( null );
Grupo::forzar( array( 'id' => '999', 'numero' => '999', 'nombre' => 'Otro', 'crm_id' => '' ) );
$ok( ! Escritura::disponible() && is_wp_error( Escritura::guardar( $nino, array( 'apodo_c' => 'x' ), 'prueba' ) ), 'un grupo sin conexión verificada no puede escribir' );
Grupo::forzar( $grupo );
Escritura::usar_para_pruebas( $falso );

// ---------------------------------------------------------------------------
echo "\n== Parte B · Quién puede cambiar qué en la ficha ==\n";
$p = $perm( $u_secre, $nino );
$ok( PermisosFicha::EDITAR === $p->accion_rol( 'first_name' ) && PermisosFicha::EDITAR === $p->accion_rol( 'alergias_c' ) && PermisosFicha::EDITAR === $p->accion_rol( 'stic_identification_number_c' ), 'Secretaría: edita todo (también salud y DNI)' );
$ok( PermisosFicha::VER === $p->accion_rol( 'numerosocio_c' ) && PermisosFicha::VER === $p->accion_rol( 'stic_relationship_type_c' ), 'nadie edita el nº Scouts de España ni la sección (vienen del CRM)' );
$p = $perm( $u_scout, $nino );
$ok( $p->puede_ver() && PermisosFicha::EDITAR === $p->accion_rol( 'phone_mobile' ) && PermisosFicha::EDITAR === $p->accion_rol( 'tutor1_email_c' ), 'scouter: edita contacto y tutores de su sección' );
$ok( PermisosFicha::VER === $p->accion_rol( 'first_name' ) && $p->ve_salud() && PermisosFicha::VER === $p->accion_rol( 'alergias_c' ) && ! $p->ve_campo( 'stic_identification_number_c' ), 'scouter: datos personales y salud en solo lectura; sin DNI' );
$ok( ! $perm( $u_scout, $otro )->puede_ver(), 'scouter: no ve fichas de otras secciones' );
$p = $perm( $u_coord, $nino );
$ok( PermisosFicha::EDITAR === $p->accion_rol( 'alergias_c' ) && PermisosFicha::VER === $p->accion_rol( 'alergiamedicamentos_c' ) && PermisosFicha::VER === $p->accion_rol( 'first_name' ), 'Coordinación: anota en los textos de salud; el resto, solo lectura' );
$p = $perm( $u_tutor, $nino );
$ok( $p->puede_ver() && PermisosFicha::PROPONER === $p->accion_rol( 'first_name' ) && PermisosFicha::PROPONER === $p->accion_rol( 'alergias_c' ) && PermisosFicha::VER === $p->accion_rol( 'cargoeducativo_c' ), 'familia: propone cambios en la ficha de su menor (no en la membresía)' );
$ok( ! $perm( $u_tutor, $otro )->puede_ver(), 'familia: no ve otras fichas' );
$p = $perm( $u_teen, $teen );
$ok( $p->es_propia() && PermisosFicha::EDITAR === $p->accion_rol( 'email1' ) && PermisosFicha::EDITAR === $p->accion_rol( 'phone_mobile' ) && PermisosFicha::VER === $p->accion_rol( 'first_name' ) && ! $p->ve_salud(), 'participante: cambia su email y teléfonos; el resto, solo lectura' );
list( $acc, $motivo ) = $perm( $u_secre, $nino )->accion( 'stic_relationship_type_c', array( 'tipo' => 'multienum' ) );
$ok( PermisosFicha::VER === $acc && '' !== $motivo, 'los campos de solo lectura explican el motivo' );

// Qué campos se ven según la persona (v0.7.1).
$p = $perm( $u_secre, $nino );
$ok( ! $p->ve_campo( 'formacionscout_c' ) && ! $p->ve_campo( 'formaciontiempolibre_c' ) && ! $p->ve_campo( 'certificadods_c' ) && ! $p->ve_campo( 'certificadods_fecha_c' ), 'ficha de un menor: sin formación ni certificado de delitos sexuales' );
$ok( ! $p->ve_campo( 'cargoeducativo_c' ) && ! $perm( $u_secre, $teen )->ve_campo( 'cargoeducativo_c' ), 'sin membresía de Scouter ni de Voluntariado no se ve el cargo' );
$voluntaria            = clone $teen; // Misma ficha, solo con membresía de Voluntariado (no se guarda nada).
$voluntaria->secciones = array( 'voluntariado' );
$ok( $perm( $u_secre, $voluntaria )->ve_campo( 'cargoeducativo_c' ), 'con membresía de Voluntariado sí se ve el cargo' );
$scouter_p = current( array_filter( $activas, static fn( $x ) => in_array( 'scouters', $x->secciones, true ) && null !== $x->edad() && $x->edad() >= 18 ) ) ?: null;
if ( $scouter_p ) {
	$p = $perm( $u_secre, $scouter_p );
	$ok( $p->ve_campo( 'formacionscout_c' ) && $p->ve_campo( 'certificadods_c' ) && $p->ve_campo( 'cargoeducativo_c' ), 'ficha de un scouter adulto: con formación, certificado y cargo' );
}
$def = CamposFicha::todos();
$ok( 'alimentacion' === $def['alimentacion_alergia_c'][1] && 'salud' === $def['alergias_c'][1] && isset( CamposFicha::GRUPOS['alimentacion'] ), 'salud y alimentación en apartados separados' );
$ok( PermisosFicha::EDITAR === $perm( $u_coord, $nino )->accion_rol( 'alimentacion_alerg_alimentos_c' ), 'Coordinación también anota en los textos de alimentación' );

// ---------------------------------------------------------------------------
echo "\n== Parte C · Ficha: validar y guardar ==\n";
$m_fecha = array( 'tipo' => 'date' );
$m_enum  = array(
	'tipo'     => 'radioenum',
	'opciones' => array(
		'si' => 'Sí',
		'no' => 'No',
	),
);
$ok( '2010-05-01' === FichaService::normalizar( 'birthdate', '2010-05-01', $m_fecha ) && is_wp_error( FichaService::normalizar( 'birthdate', '2010-02-30', $m_fecha ) ), 'fechas válidas y no válidas' );
$ok( '1' === FichaService::normalizar( 'x', '1', array( 'tipo' => 'bool' ) ) && '0' === FichaService::normalizar( 'x', '', array( 'tipo' => 'bool' ) ), 'sí/no (casilla)' );
$ok( 'si' === FichaService::normalizar( 'x', 'si', $m_enum ) && is_wp_error( FichaService::normalizar( 'x', 'quizá', $m_enum ) ), 'desplegables: solo opciones del CRM' );
$ok( is_wp_error( FichaService::normalizar( 'email1', 'no-es-un-email', array( 'tipo' => 'varchar' ) ) ) && is_wp_error( FichaService::normalizar( 'phone_mobile', 'abc', array( 'tipo' => 'phone' ) ) ), 'email y teléfono validados' );
$ok( is_wp_error( FichaService::normalizar( 'first_name', '', array( 'tipo' => 'varchar', 'requerido' => true ) ) ) && is_wp_error( FichaService::normalizar( 'apodo_c', str_repeat( 'x', 80 ), array( 'tipo' => 'varchar', 'len' => 75 ) ) ), 'obligatorios y longitud máxima' );
$m_reg = FichaService::con_opciones( 'alimentacion_regimen_tipo_y__c', array( 'tipo' => 'varchar', 'len' => 255 ), '' );
$ok( 'enum' === $m_reg['tipo'] && 5 === count( $m_reg['opciones'] ) && isset( $m_reg['ayudas']['Dieta omnívora'], $m_reg['ayudas']['Dieta vegetariana'], $m_reg['ayudas']['Dieta vegana'], $m_reg['ayudas']['Dieta flexitariana'] ), 'tipo de régimen: desplegable con las 4 dietas y su explicación' );
$ok( 'Dieta vegana' === FichaService::normalizar( 'alimentacion_regimen_tipo_y__c', 'Dieta vegana', $m_reg ) && is_wp_error( FichaService::normalizar( 'alimentacion_regimen_tipo_y__c', 'Dieta keto', $m_reg ) ), 'tipo de régimen: solo se aceptan esas opciones' );
$m_reg2 = FichaService::con_opciones( 'alimentacion_regimen_tipo_y__c', array( 'tipo' => 'varchar' ), 'Sin gluten' );
$ok( isset( $m_reg2['opciones']['Sin gluten'] ) && str_contains( $m_reg2['opciones']['Sin gluten'], 'valor actual' ), 'un texto anterior del CRM se conserva como «valor actual»' );

$servicio        = new FichaService( $repo );
$falso->llamadas = array();
$perm_secre      = $perm( $u_secre, $nino );
$actual          = $servicio->cargar( $nino, $perm_secre );
$r               = $servicio->guardar(
	$nino,
	$perm_secre,
	array(
		'phone_mobile'  => '600 000 002',
		'first_name'    => $actual['first_name']['valor'],
		'email1'        => 'no-valido',
		'numerosocio_c' => '1',
	),
	array( 'phone_mobile' => FichaService::huella( $actual['phone_mobile']['valor'] ) )
);
$ok( array( 'Móvil' ) === $r['guardados'] && 1 === count( $falso->llamadas ) && array( 'phone_mobile' => '600 000 002' ) === $falso->llamadas[0][2], 'Secretaría guarda: solo lo que cambia va al CRM (lo no editable se ignora)' );
$ok( 1 === count( $r['errores'] ) && str_contains( $r['errores'][0], 'Email' ), 'un valor no válido no se guarda y se explica' );
$r = $servicio->guardar( $nino, $perm_secre, array( 'apodo_c' => 'Cambio' ), array( 'apodo_c' => FichaService::huella( 'otro valor anterior' ) ) );
$ok( array( 'Apodo' ) === $r['conflictos'] && ! $r['guardados'], 'si el dato cambió en el CRM mientras se editaba, no se sobrescribe' );
$falso->llamadas = array();
$perm_tutor      = $perm( $u_tutor, $nino );
$r               = $servicio->guardar( $nino, $perm_tutor, array( 'alergias_c' => 'Polen (propuesto en la prueba)' ), array() );
$ok( array( 'Alergias' ) === $r['propuestos'] && ! $falso->llamadas, 'la familia propone: va a la cola, no al CRM' );

// ---------------------------------------------------------------------------
echo "\n== Parte D · Cola de cambios ==\n";
$pend = Cambios::listado( Cambios::PENDIENTE, $nino->num_socio_asde );
$c1   = $pend[0] ?? null;
$raw  = $c1 ? $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Cambios::table() . ' WHERE id = %d', $c1['id'] ), ARRAY_A ) : null;
$ok( $c1 && 'alergias_c' === $c1['campo'] && str_starts_with( (string) $raw['nuevo_enc'], 'v1:' ) && ! str_contains( (string) $raw['nuevo_enc'], 'Polen' ), 'el cambio queda pendiente y su valor, cifrado' );
wp_set_current_user( $u_tutor->ID );
Cambios::proponer( $nino, array( 'alergias_c' => array( (string) $c1['anterior'], 'Polen y gramíneas' ) ) );
$pend = Cambios::listado( Cambios::PENDIENTE, $nino->num_socio_asde );
$ok( 1 === count( $pend ) && 'Polen y gramíneas' === $pend[0]['nuevo'] && Cambios::CANCELADO === Cambios::obtener( (int) $c1['id'] )['estado'], 'un cambio nuevo del mismo dato sustituye al anterior' );
$c2 = $pend[0];
wp_set_current_user( $u_scout->ID );
$ok( is_wp_error( Cambios::cancelar( (int) $c2['id'] ) ), 'solo quien lo propuso puede retirarlo' );
$ok( is_wp_error( Cambios::rechazar( (int) $c2['id'], '' ) ), 'rechazar exige un motivo' );
$conf = Cambios::aprobar( (int) $c2['id'], $nino, 'valor distinto del que había' );
$ok( is_wp_error( $conf ) && 'asde_cambio_conflicto' === $conf->get_error_code(), 'si el dato del CRM cambió desde la propuesta, avisa antes de aplicar' );
$falso->llamadas = array();
$ok( true === Cambios::aprobar( (int) $c2['id'], $nino, 'valor distinto del que había', true ) && Cambios::APLICADO === Cambios::obtener( (int) $c2['id'] )['estado'] && 'Polen y gramíneas' === $falso->llamadas[0][2]['alergias_c'], 'aprobado (confirmando): se escribe en el CRM y queda aplicado' );
wp_set_current_user( $u_tutor->ID );
Cambios::proponer( $nino, array( 'apodo_c' => array( '', 'Peque' ) ) );
$c3 = Cambios::listado( Cambios::PENDIENTE, $nino->num_socio_asde )[0];
wp_set_current_user( $u_secre->ID );
$ok( true === Cambios::rechazar( (int) $c3['id'], 'No es un apodo scout' ) && 'No es un apodo scout' === Cambios::obtener( (int) $c3['id'] )['motivo'], 'rechazado con motivo (lo verá la familia en la ficha)' );
wp_set_current_user( $u_tutor->ID );
Cambios::proponer( $nino, array( 'phone_home' => array( '', '971000000' ) ) );
$c4 = Cambios::listado( Cambios::PENDIENTE, $nino->num_socio_asde )[0];
$ok( true === Cambios::cancelar( (int) $c4['id'] ) && 0 === count( Cambios::listado( Cambios::PENDIENTE, $nino->num_socio_asde ) ), 'quien lo propuso lo puede retirar' );
wp_set_current_user( 0 );

// ---------------------------------------------------------------------------
echo "\n== Parte E · Números de socio corregidos en el CRM (simulado) ==\n";
$num->analizar_todo( true );
$todas = $repo->todas( false );
$por_estado = array();
foreach ( $todas as $x ) {
	$por_estado[ $num->de( $x )['estado'] ][] = $x;
}
$falta = current( array_filter( $por_estado[ Numeracion::FALTA ] ?? array(), static fn( $x ) => $x->tiene_membresia_activa() ) ) ?: null;
if ( $falta ) {
	$falso->llamadas = array();
	$r               = $num->asignar_y_guardar( $falta );
	$ok( is_array( $r ) && $r['crm'] && $r['numero'] === ( $falso->llamadas[0][2]['numsocioantiguo_enficha_c'] ?? '' ) && SociosTable::CRM === ( SociosTable::todas()[ $falta->num_socio_asde ]['origen'] ?? '' ), 'asignar nº: se reserva y se guarda en el CRM (ya no queda «pendiente»)' );
} else {
	$ok( false, 'asignar nº: tendría que haber una ficha activa sin número (la inventada 990108)' );
}
$corr = $por_estado[ Numeracion::CORREGIDO ][0] ?? null;
if ( $corr ) {
	$falso->llamadas = array();
	$esperado        = $num->de( $corr )['numero'];
	$ok( true === $num->corregir_formato( $corr ) && $esperado === $falso->llamadas[0][2]['numsocioantiguo_enficha_c'], 'formato antiguo: se escribe en el CRM el formato correcto' );
} else {
	$ok( false, 'formato antiguo: tendría que haber una ficha con el formato antiguo (las inventadas 990109-990114)' );
}
// Por tandas (lo usa la corrección en segundo plano, parte G).
$pend = count( array_filter( $todas, static fn( $x ) => Numeracion::CORREGIDO === $num->de( $x )['estado'] ) );
if ( $pend > 3 ) {
	$falso->llamadas = array();
	$r1              = $num->guardar_todos( $todas, Numeracion::CORREGIDO, microtime( true ) - 1 );
	$ok( 1 === $r1['resueltos'] && $pend - 1 === $r1['pendientes'] && 1 === count( $falso->llamadas ), 'por tandas: acabado el tiempo no se empieza otra ficha (siempre avanza una); el resto queda pendiente' );
	$r2 = $num->guardar_todos( $todas, Numeracion::CORREGIDO, null, array(), static fn() => false );
	$ok( 1 === $r2['resueltos'] && $pend - 2 === $r2['pendientes'], 'por tandas: si la tarea se detiene, no se empieza otra ficha' );
	$salta           = current( array_filter( $todas, static fn( $x ) => Numeracion::CORREGIDO === $num->de( $x )['estado'] ) );
	$falso->llamadas = array();
	$r3              = $num->guardar_todos( $todas, Numeracion::CORREGIDO, null, array( $salta->num_socio_asde ) );
	$ok( $pend - 3 === $r3['resueltos'] && 0 === $r3['pendientes'] && ! $r3['fallidas'] && ! in_array( $salta->id, array_column( $falso->llamadas, 1 ), true ), 'por tandas: las fichas que ya fallaron se saltan (no se reintentan en bucle)' );
} else {
	$ok( false, 'por tandas: tendría que haber más de 3 fichas con el formato antiguo (las inventadas 990109-990114)' );
}
$dups = $por_estado[ Numeracion::DUPLICADO ] ?? array();
if ( count( $dups ) >= 2 ) {
	$d      = $dups[0];
	$numero = $num->de( $d )['numero'];
	if ( ! get_user_by( 'login', $numero ) && ! Usuarios::por_socio( $d->num_socio_asde ) ) {
		// Usuario temporal que entra en la web con el número repetido.
		$u_dup = $crear( 'dup', array(), array( Usuarios::META_SOCIO => $d->num_socio_asde ) );
		$wpdb->update( $wpdb->users, array( 'user_login' => $numero ), array( 'ID' => $u_dup->ID ) );
		clean_user_cache( $u_dup->ID );
		$ok( is_wp_error( $num->resolver_duplicado( $d ) ), 'duplicado: nunca se cambia el número de quien entra en la web con él' );
		$wpdb->update( $wpdb->users, array( 'user_login' => 'prueba-fichas-dup-' . wp_rand( 1000, 9999 ) ), array( 'ID' => $u_dup->ID ) );
		clean_user_cache( $u_dup->ID );
		delete_user_meta( $u_dup->ID, Usuarios::META_SOCIO );
	}
	$falso->llamadas = array();
	$r               = $num->resolver_duplicados( $todas );
	$nuevos          = array_map( static fn( $l ) => $l[2]['numsocioantiguo_enficha_c'], $falso->llamadas );
	$ok( $r['resueltos'] >= 1 && ! $r['fallidas'] && count( array_unique( $nuevos ) ) === count( $nuevos ) && ! in_array( $numero, $nuevos, true ), 'duplicados: una ficha de cada número repetido recibe un número nuevo y libre (' . $r['resueltos'] . ')' );
} else {
	$ok( false, 'duplicados: tendría que haber un número repetido (las inventadas 990115 y 990116)' );
}

// ---------------------------------------------------------------------------
echo "\n== Parte F · «Voluntario sin sección» ==\n";
$alta = AltaService::make();
$r    = $alta->alta_persona( $adulto4->num_socio_asde, array( Roles::SCOUTER ), array( Secciones::SIN_SECCION ) );
$ok( ! $r->ok, 'sin sección no puede ser Scouter' );
$r = $alta->alta_persona( $adulto4->num_socio_asde, array( Roles::SECRETARIA ), array( Secciones::SIN_SECCION, 'scouts' ) );
$ok( ! $r->ok, 'sin sección no se combina con secciones educativas' );
$r = $alta->alta_persona( $adulto4->num_socio_asde, array( Roles::SECRETARIA ), array() );
$ok( ! $r->ok, 'Secretaría sin Scouter ni «sin sección» → error' );
$r = $alta->alta_persona( $adulto4->num_socio_asde, array( Roles::TESORERIA ), array( Secciones::SIN_SECCION ) );
$ok( $r->ok && array( Roles::TESORERIA ) === Usuarios::roles_asde( $r->usuario ) && array( Secciones::SIN_SECCION ) === Usuarios::secciones( $r->usuario->ID ), 'Tesorería como voluntario sin sección (sin rol de Scouter)' );
if ( $r->ok ) {
	$uids[] = $r->usuario->ID;
	$ok( array() === Ambito::de( get_userdata( $r->usuario->ID ) )->secciones_scouter(), 'sin sección: no ve fichas por sección (no es scouter)' );
}
$ok( str_contains( Secciones::badge( Secciones::SIN_SECCION ), 'Voluntario sin sección' ), 'etiqueta «Voluntario sin sección»' );

// ---------------------------------------------------------------------------
echo "\n== Parte G · Corrección en segundo plano (CRM simulado) ==\n";
if ( is_array( $tarea_previa ) && TareaNumeracion::EN_CURSO === ( $tarea_previa['estado'] ?? '' ) ) {
	$ok( true, 'segundo plano: hay una corrección real en marcha en el grupo (se omite para no interferir)' );
} else {
	// Desde el 2026-09-28 el CRM real ya no tiene números en formato antiguo: la
	// tarea trabaja con 4 fichas inventadas (nº 684 + 99nn, sin la provincia: formato antiguo) en el CRM simulado de
	// tests/lib, que atiende también las lecturas. Al acabar, todo como estaba.
	require_once __DIR__ . '/lib/CrmSimulado.php';
	$sim_fichas = array();
	for ( $n = 41; $n <= 44; $n++ ) {
		$sim_fichas[] = Asde_Prueba_Crm::persona( $n, 'Prueba', 'Tanda ' . $n, '2012-01-01', 'Scouts', array( 'numsocioantiguo_enficha_c' => sprintf( '68499%02d', $n ) ) );
	}
	$sim           = new Asde_Prueba_Crm( $sim_fichas, (string) $grupo['crm_id'] );
	$sim_inicial   = $sim->personas;
	$sim_socios    = (array) $wpdb->get_results( 'SELECT * FROM ' . SociosTable::table(), ARRAY_A );
	$sim_cache     = get_transient( 'asde_ap_numeracion_684' );
	$sim_sesiones  = (array) $wpdb->get_results( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_asde\\_ap\\_crm\\_sess\\_%'", OBJECT_K ); // phpcs:ignore WordPress.DB
	// Las cachés (transients) del CRM simulado, solo en la memoria de este proceso: la web real no las ve.
	$sim_cache_ext = wp_using_ext_object_cache( true );
	$crm->desactivar(); // El CRM simulado de esta parte, solo (los dos atenderían las mismas peticiones).
	$sim->activar();
	Escritura::usar_para_pruebas( $sim );
	Numeracion::olvidar_cache();
	try {
		wp_set_current_user( $u_secre->ID );
		( new ReflectionMethod( PersonasView::class, 'iniciar_tarea' ) )->invoke( new PersonasView(), 'corregir_todos', new Numeracion( PersonaRepository::make() ) );
		$t = TareaNumeracion::actual( '684', true );
		$ok( $t && TareaNumeracion::EN_CURSO === $t['estado'] && $t['total'] >= 2 && ! empty( $t['prueba'] ) && str_contains( Notices::render(), 'segundo plano' ), 'botón «Corregir…»: crea la tarea y la pantalla vuelve enseguida (aviso «en segundo plano»)' );
		$ok( ! wp_next_scheduled( TareaNumeracion::HOOK_CRON, array( '684' ) ), 'en las pruebas no se lanza nada fuera de este proceso (ni petición interna ni WP-Cron)' );
		$ok( is_wp_error( TareaNumeracion::crear( 'corregir_todos', '684', $u_secre->ID, 1 ) ), 'solo una tarea a la vez por grupo' );
		$ok( $t && ! TareaNumeracion::ejecutable( $t, false ) && TareaNumeracion::ejecutable( $t, true ), 'una tarea de las pruebas nunca la ejecuta una petición normal (escribiría en el CRM real)' );
		$ok( $t && ! TareaNumeracion::token_valido( '684', 'token-falso' ) && TareaNumeracion::token_valido( '684', $t['token'] ), 'la petición interna solo vale con el token de la tarea' );

		wp_set_current_user( 0 ); // El trabajador no tiene sesión: actúa en nombre de quien la inició.
		$log_g = (int) $wpdb->get_var( 'SELECT MAX(id) FROM ' . Logger::table() );
		$t1    = TareaNumeracion::trabajar( '684', 0 );
		$autor = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT user_id FROM ' . Logger::table() . ' WHERE id > %d AND accion = %s ORDER BY id LIMIT 1', $log_g, 'crm_ficha_editada' ) );
		$ok( 1 === $t1['hechas'] && $t1['total'] - 1 === $t1['pendientes'] && 1 === $t1['tandas'] && TareaNumeracion::EN_CURSO === $t1['estado'], 'una tanda sin tiempo corrige una ficha y deja el resto pendiente' );
		$ok( $u_secre->ID === $autor, 'en la auditoría consta quien inició la tarea' );
		$m = TareaNumeracion::monitor( $t1 );
		$ok( array( 'estado', 'accion', 'total', 'hechas', 'fallidas', 'pendientes', 'avance', 'hace', 'texto' ) === array_keys( $m ) && is_int( $m['fallidas'] ) && str_contains( $m['texto'], 'Quedan ' . $t1['pendientes'] ), 'monitor: contadores y «Quedan N», sin nombres ni listas de personas' );

		$wpdb->insert( $wpdb->options, array( 'option_name' => 'asde_ap_tarea_lock_684', 'option_value' => (string) ( time() + 300 ), 'autoload' => 'off' ) );
		$t2 = TareaNumeracion::trabajar( '684', 30 );
		$ok( $t2['hechas'] === $t1['hechas'], 'con otra tanda en marcha (cerrojo) no se trabaja a la vez' );
		$wpdb->update( $wpdb->options, array( 'option_value' => (string) ( time() - 5 ) ), array( 'option_name' => 'asde_ap_tarea_lock_684' ) );
		$t3 = TareaNumeracion::trabajar( '684', 0 );
		$ok( $t3['hechas'] === $t1['hechas'] + 1 && ! $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name = 'asde_ap_tarea_lock_684'" ), 'un cerrojo caducado (tanda que murió) se recupera, y se suelta al acabar' );

		TareaNumeracion::detener( '684' );
		$t4 = TareaNumeracion::trabajar( '684', 30 );
		$ok( TareaNumeracion::CANCELADA === $t4['estado'] && $t4['hechas'] === $t3['hechas'] && str_starts_with( TareaNumeracion::monitor( $t4 )['texto'], 'Detenida' ), 'detenida: no se corrige nada más' );
		$ok( TareaNumeracion::cerrar( '684' ) && ! TareaNumeracion::actual( '684', true ), 'al cerrarla desaparece del monitor' );

		TareaNumeracion::crear( 'corregir_todos', '684', $u_secre->ID, $t['total'] );
		$sim->personas = $sim_inicial; // Las 4 fichas otra vez con el formato antiguo.
		$sim->rechazar = array( 'numsocioantiguo_enficha_c' ); // Y el CRM no acepta el número: al releer, no aparece guardado.
		Numeracion::olvidar_cache();
		$tf            = TareaNumeracion::trabajar( '684', 120 );
		$sim->rechazar = array();
		$ok( TareaNumeracion::TERMINADA === $tf['estado'] && 0 === $tf['hechas'] && count( $tf['fallidas'] ) === $tf['total'] && 0 === $tf['pendientes'], 'si el CRM no acepta ninguna, termina con la lista de fallidas (sin reintentar en bucle)' );
		$ok( str_contains( TareaNumeracion::monitor( $tf )['texto'], 'no se pudieron corregir' ), 'monitor: avisa de las que fallaron (el detalle, con nombres, en Personas)' );
		TareaNumeracion::cerrar( '684' );

		TareaNumeracion::crear( 'corregir_todos', '684', $u_secre->ID, $t['total'] );
		$log_g = (int) $wpdb->get_var( 'SELECT MAX(id) FROM ' . Logger::table() );
		$tc    = TareaNumeracion::trabajar( '684', 120 );
		$fin   = json_decode( (string) $wpdb->get_var( $wpdb->prepare( 'SELECT detalles FROM ' . Logger::table() . ' WHERE id > %d AND accion = %s', $log_g, 'numeros_corregir_todos' ) ), true ) ?: array();
		$ok( TareaNumeracion::TERMINADA === $tc['estado'] && $tc['hechas'] === $tc['total'] && 'terminada' === ( $fin['estado'] ?? '' ) && $tc['total'] === (int) ( $fin['resueltos'] ?? -1 ), 'terminada: todas corregidas y resumen en la auditoría (' . $tc['total'] . ' fichas, ' . $tc['tandas'] . ' tanda)' );
		$ok( 4 === count( array_filter( $sim->personas, static fn( $p ) => (bool) preg_match( '/^0768499\d{2}$/', $p['numsocioantiguo_enficha_c'] ) ) ), 'en el CRM (simulado) las 4 fichas quedan con el formato correcto' );
		TareaNumeracion::cerrar( '684' );
	} finally {
		// Fuera el CRM simulado: tabla de números, caché y sesiones del CRM como estaban.
		wp_using_ext_object_cache( $sim_cache_ext );
		wp_set_current_user( 0 );
		$sim->desactivar();
		Escritura::usar_para_pruebas( $falso );
		$sim_antes = array_column( $sim_socios, null, 'num_socio_asde' );
		$sim_ahora = array_column( (array) $wpdb->get_results( 'SELECT * FROM ' . SociosTable::table(), ARRAY_A ), null, 'num_socio_asde' );
		foreach ( array_diff_key( $sim_ahora, $sim_antes ) as $asde => $fila ) {
			$wpdb->delete( SociosTable::table(), array( 'num_socio_asde' => (string) $asde ) );
		}
		foreach ( $sim_antes as $asde => $fila ) {
			if ( ( $sim_ahora[ $asde ] ?? null ) !== $fila ) {
				$wpdb->replace( SociosTable::table(), $fila );
			}
		}
		Numeracion::olvidar_cache();
		if ( false !== $sim_cache ) {
			set_transient( 'asde_ap_numeracion_684', $sim_cache, 10 * MINUTE_IN_SECONDS );
		}
		foreach ( (array) $wpdb->get_results( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_asde\\_ap\\_crm\\_sess\\_%'", OBJECT_K ) as $nombre => $fila ) { // phpcs:ignore WordPress.DB
			if ( ! isset( $sim_sesiones[ $nombre ] ) ) {
				delete_transient( substr( $nombre, strlen( '_transient_' ) ) );
			} elseif ( $sim_sesiones[ $nombre ]->option_value !== $fila->option_value ) {
				update_option( $nombre, maybe_unserialize( $sim_sesiones[ $nombre ]->option_value ), false );
			}
		}
	}
	$ok( ! array_diff_key( array_column( (array) $wpdb->get_results( 'SELECT * FROM ' . SociosTable::table(), ARRAY_A ), null, 'num_socio_asde' ), $sim_antes ), 'CRM simulado retirado: la tabla de números, como estaba' );
}

// ---------------------------------------------------------------------------
echo "\n== Parte H · Diagnóstico del hosting (marcas de tiempo simuladas) ==\n";
$diag   = array(
	'inicio'  => 1000.0,
	'directa' => array( 'codigo' => 200, 'error' => '', 'pong' => true, 'ms' => 300 ),
	'entorno' => array( 'litespeed' => true, 'noabort' => false, 'cron_desactivado' => false, 'atrasadas' => 0 ),
);
$veredicto = array( Asde_Diagnostico_Segundo_Plano::class, 'veredicto' );
$v         = $veredicto( $diag, array( 'llega' => 1000.5, 'vivo' => 1006.5, 'termina' => 1006.6, 'cron' => 1002.0 ), 1008.0 );
$ok( $v['terminado'] && 'ok' === $v['global'][0] && '' === $v['htaccess'], 'diagnóstico: todo funciona → las tareas se relanzan solas (no propone cambios)' );
$v = $veredicto( $diag, array( 'llega' => 1000.5, 'vivo' => 1001.5 ), 1003.0 );
$ok( ! $v['terminado'] && 'esperando' === $v['global'][0], 'diagnóstico: mientras dura, «comprobando»' );
$v = $veredicto( $diag, array( 'llega' => 1000.5, 'vivo' => 1001.5 ), 1080.0 );
$ok( $v['terminado'] && 'fallo' === $v['pruebas']['corte'][0] && str_contains( $v['pruebas']['corte'][2], 'LiteSpeed' ) && str_contains( $v['htaccess'], 'noabort' ), 'diagnóstico: LiteSpeed corta la tarea a mitad → lo detecta y propone «noabort»' );
$v = $veredicto( $diag, array(), 1080.0 );
$ok( 'ok' === $v['pruebas']['bucle'][0] && 'fallo' === $v['pruebas']['corte'][0] && 'fallo' === $v['global'][0] && '' !== $v['htaccess'], 'diagnóstico: la petición sin esperar ni llega a trabajar (cortada al instante) → también «noabort»' );
$bloq = array( 'directa' => array( 'codigo' => 403, 'error' => '', 'pong' => false, 'ms' => 90 ) ) + $diag;
$v    = $veredicto( $bloq, array(), 1080.0 );
$ok( 'fallo' === $v['pruebas']['bucle'][0] && str_contains( $v['pruebas']['bucle'][2], 'cortafuegos' ) && str_contains( $v['global'][1], 'Ni la petición' ) && '' === $v['htaccess'], 'diagnóstico: petición interna bloqueada (403) → explica el cortafuegos' );
$v = $veredicto( $bloq, array( 'cron' => 1003.0 ), 1080.0 );
$ok( 'aviso' === $v['global'][0], 'diagnóstico: sin petición interna pero con WP-Cron → avanza más despacio (aviso)' );
$sin_cron = array( 'entorno' => array( 'cron_desactivado' => true, 'atrasadas' => 4 ) + $diag['entorno'] ) + $diag;
$v1       = $veredicto( $sin_cron, array( 'llega' => 1000.5, 'termina' => 1006.6 ), 1008.0 );
$sin_cron['entorno']['atrasadas'] = 0;
$v2       = $veredicto( $sin_cron, array( 'llega' => 1000.5, 'termina' => 1006.6 ), 1008.0 );
$ok( 'fallo' === $v1['pruebas']['cron'][0] && $v1['cron_servidor'] && 'ok' === $v2['pruebas']['cron'][0], 'diagnóstico: DISABLE_WP_CRON → mira si hay tareas atrasadas (cron del servidor)' );
$v = $veredicto( $diag, array( 'llega' => 1000.5, 'vivo' => 1002.0 ), 1095.0 );
$ok( $v['terminado'] && ! in_array( 'esperando', array_column( $v['pruebas'], 0 ), true ), 'diagnóstico: pasado el tiempo máximo, termina (nada se queda «comprobando»)' );
$regla = array();
preg_match( '/RewriteCond %\{QUERY_STRING\} (\S+)/', Asde_Diagnostico_Segundo_Plano::htaccess(), $regla );
$ok( isset( $regla[1] ) && preg_match( '/' . $regla[1] . '/', 'action=asde_ap_tarea' ) && preg_match( '/' . $regla[1] . '/', 'action=asde_diag_ping' ) && ! preg_match( '/' . $regla[1] . '/', 'action=heartbeat' ), 'la regla «noabort» cubre justo las peticiones de las tareas y del diagnóstico' );

// ---------------------------------------------------------------------------
} catch ( Throwable $e ) {
	++$fails;
	echo 'FAIL excepcion: ', $e->getMessage(), ' (', basename( $e->getFile() ), ':', $e->getLine(), ")\n";
} finally {
	// Limpieza.
	if ( ! is_array( $tarea_previa ) || TareaNumeracion::EN_CURSO !== ( $tarea_previa['estado'] ?? '' ) ) {
		delete_option( $clave_tarea );
		if ( is_array( $tarea_previa ) ) {
			add_option( $clave_tarea, $tarea_previa, '', false ); // La tarea (acabada) que ya había.
		}
		$wpdb->delete( $wpdb->options, array( 'option_name' => 'asde_ap_tarea_lock_684' ) );
	}
	Escritura::usar_para_pruebas( null );
	Grupo::forzar( null );
	foreach ( $uids as $id ) {
		wp_delete_user( $id );
	}
	$wpdb->query( 'DELETE FROM ' . RegistroEmails::table() . ' WHERE user_id IN (' . implode( ',', array_map( 'intval', $uids ?: array( 0 ) ) ) . ')' ); // phpcs:ignore WordPress.DB
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Cambios::table() . ' WHERE socio = %s', $nino->num_socio_asde ) ); // phpcs:ignore WordPress.DB
	// La tabla de números vuelve a como estaba SOLO para las fichas que tocó la prueba
	// (las escrituras eran simuladas); lo demás no se toca.
	$por_id = array();
	foreach ( $todas ?? $activas as $x ) {
		$por_id[ $x->id ] = $x->num_socio_asde;
	}
	$tocados         = array_values( array_filter( array_map( static fn( $id ) => $por_id[ $id ] ?? null, array_unique( $falso->ids ) ) ) );
	$antes_por_socio = array_column( $socios_antes, null, 'num_socio_asde' );
	foreach ( $tocados as $asde ) {
		$wpdb->delete( SociosTable::table(), array( 'num_socio_asde' => $asde ) );
		if ( isset( $antes_por_socio[ $asde ] ) ) {
			$wpdb->insert( SociosTable::table(), $antes_por_socio[ $asde ] );
		}
	}
	Numeracion::olvidar_cache();
	// Las escrituras eran simuladas: su rastro en la auditoría confundiría a quien la revise.
	// Solo se borra lo de la prueba (sus usuarios, o la consola en las acciones que ella hace),
	// nunca acciones reales de la web hechas mientras tanto.
	$quienes = implode( ',', array_map( 'intval', $uids ?: array( -1 ) ) );
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Logger::table() . " WHERE id > %d AND ( user_id IN ($quienes) OR ( user_id = 0 AND ( accion IN ('crm_ficha_editada', 'crm_escritura_error', 'alta_usuario', 'tutela_retirada_mayoria_edad') OR accion LIKE 'cambio\\_%%' OR accion LIKE 'numero%%' OR accion LIKE 'usuario\\_%%' ) ) )", $log_antes ) ); // phpcs:ignore WordPress.DB
	// Las fichas son inventadas (9901xx): toda fila nueva de la tabla de números es de la prueba.
	$ahora_socios = array_column( (array) $wpdb->get_results( 'SELECT * FROM ' . SociosTable::table(), ARRAY_A ), null, 'num_socio_asde' ); // phpcs:ignore WordPress.DB
	foreach ( array_diff_key( $ahora_socios, $antes_por_socio ) as $asde => $fila ) {
		$wpdb->delete( SociosTable::table(), array( 'num_socio_asde' => (string) $asde ) );
	}
	Numeracion::olvidar_cache();
	$crm->desactivar();
	remove_filter( 'pre_http_request', $cortar_crm, 2 );
	remove_filter( 'pre_wp_mail', '__return_true' );
	wp_using_ext_object_cache( $cache_externa );
	$ahora_socios = array_column( (array) $wpdb->get_results( 'SELECT * FROM ' . SociosTable::table(), ARRAY_A ), null, 'num_socio_asde' ); // phpcs:ignore WordPress.DB
	$ok( $ahora_socios == $antes_por_socio && 0 === $cortadas, 'limpieza: la tabla de números, exactamente como estaba; ninguna consulta al CRM real' ); // phpcs:ignore Universal.Operators.StrictComparisons
}

echo $fails ? "\n$fails FALLOS\n" : "\nTODO OK (nada escrito en el CRM real; usuarios, cambios y números de prueba restaurados)\n";
