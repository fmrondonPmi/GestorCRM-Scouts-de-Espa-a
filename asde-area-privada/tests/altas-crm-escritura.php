<?php
/**
 * Pruebas de la v0.22 (entorno LOCAL): altas en el CRM, fase 2 (doc 34): crear
 * personas y membresías, bajas, cambios de sección y el documento de un menor.
 * Ejecutar desde app/public del site Local:
 *
 *   wp eval-file <ruta completa>/wp-content/plugins/asde-area-privada/tests/altas-crm-escritura.php
 *
 * Todo contra el CRM simulado de tests/lib (fichas inventadas; las «escrituras» se
 * quedan en memoria): ninguna petición llega al CRM real. Al terminar se borra lo
 * creado (operaciones, auditoría, usuarios) y se restauran la opción de altas y la
 * tabla de números.
 */
defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/lib/CrmSimulado.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Auth\Ambito;
use AsdeBalears\AreaPrivada\Crm\Altas;
use AsdeBalears\AreaPrivada\Crm\Escritura;
use AsdeBalears\AreaPrivada\Crm\Operaciones;
use AsdeBalears\AreaPrivada\Crm\Permisos;
use AsdeBalears\AreaPrivada\Crm\PersonaRepository;
use AsdeBalears\AreaPrivada\Domain\Grupo;
use AsdeBalears\AreaPrivada\Domain\Grupos;
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
foreach ( get_users( array( 'search' => 'prueba-escalta-*', 'search_columns' => array( 'user_login' ) ) ) as $u ) {
	exit( 'Ya existe el usuario ' . $u->user_login . " (¿una ejecución anterior interrumpida?). Bórralo antes de repetir la prueba.\n" );
}
wp_set_current_user( 0 );
Operaciones::install_table();

$max_id       = static fn( string $t ) => (int) $wpdb->get_var( "SELECT COALESCE(MAX(id), 0) FROM $t" ); // phpcs:ignore WordPress.DB
$log_antes    = $max_id( Logger::table() );
$ops_antes    = $max_id( Operaciones::table() );
$opcion_antes = get_option( Altas::OPCION, null );
$socios_antes = array_column( (array) $wpdb->get_results( 'SELECT * FROM ' . SociosTable::table(), ARRAY_A ), null, 'num_socio_asde' ); // phpcs:ignore WordPress.DB

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
add_filter( 'asde_ap_instantanea_segundos', '__return_zero' ); // Siempre lo que hay en el CRM simulado.

// Documentos inventados con la letra bien calculada.
$P   = array( 'Asde_Prueba_Crm', 'persona' );
$doc = static fn( string $d ): array => array( 'stic_identification_number_c' => $d );
$crm = new Asde_Prueba_Crm(
	array(
		$P( 71, 'Nuria', 'Alta Uno', '2012-03-03', 'Scouts', $doc( '12345678Z' ) ),
		$P( 72, 'Pep', 'Alta Dos', '2000-01-01', '', $doc( '87654321X' ) ),
		$P( 73, 'Mia', 'Alta Tres', '2016-06-06', 'Lobatos' ),
	),
	$grupo['crm_id']
);
$crm->otros_grupos = array(
	$P( 81, 'Otra', 'Ficha', '2010-02-02', 'Scouts', $doc( '11111111H' ) ),
	$P( 82, 'Otra', 'Ficha Dos', '2015-02-02', 'Lobatos', $doc( '66666666Q' ) ),
);
$id_de             = static fn( int $n ): string => sprintf( 'de000000-0000-4000-8000-%012d', 9900 + $n );
$crm->alta_membresia( $id_de( 71 ), 'Scouts', '2024-09-01' );
$crm->alta_membresia( $id_de( 72 ), 'Rovers', '2019-09-01', '2023-06-30' );
$crm->alta_membresia( $id_de( 73 ), 'Lobatos', '2024-09-01' );
Grupo::forzar( $grupo );
$crm->activar();
Permisos::olvidar( $grupo['numero'] );
delete_transient( 'asde_ap_recuento_federal_' . $grupo['numero'] );

$ids   = array();
$u_sec = wp_insert_user(
	array(
		'user_login' => 'prueba-escalta-sec',
		'user_email' => '',
		'user_pass'  => wp_generate_password( 24 ),
		'role'       => Roles::SECRETARIA,
	)
);
$ids[] = $u_sec;
update_user_meta( $u_sec, Usuarios::META_GRUPO, $grupo['id'] );
update_user_meta( $u_sec, Usuarios::META_ESTADO, Usuarios::ACTIVO );
wp_set_current_user( $u_sec );
Ambito::olvidar( $u_sec );

$hoy      = wp_date( 'Y-m-d' );
$hace     = static fn( int $anios ): string => wp_date( 'Y-m-d', strtotime( "-$anios years" ) );
$persona  = static function ( array $extra = array() ) use ( $hace ): array {
	return $extra + array(
		'first_name'         => 'Laia',
		'last_name'          => 'Nueva Prueba',
		'birthdate'          => $hace( 15 ),
		'stic_gender_c'      => 'femenino',
		'tutor1_nombre_c'    => 'Rosa', // Menores de 18: el tutor/a 1 es obligatorio (P-88).
		'tutor1_apellidos_c' => 'Nueva Prueba',
		'tutor1_email_c'     => 'rosa.prueba@example.invalid',
	);
};
$ficha    = static function ( string $id ) use ( $crm ): ?array {
	foreach ( array_merge( $crm->personas, $crm->sin_grupo ) as $p ) {
		if ( $p['id'] === $id ) {
			return $p;
		}
	}
	return null;
};
$de       = static fn( string $persona_id ) => array_values( array_filter( $crm->membresias, static fn( $m ) => $m['persona'] === $persona_id ) );
$con_doc  = static fn( string $d ) => count( array_filter( array_merge( $crm->personas, $crm->sin_grupo ), static fn( $p ) => $p['stic_identification_number_c'] === $d ) );
$escritas = static fn() => count( $crm->escrituras );
$codigo   = static fn( $r ) => is_wp_error( $r ) ? $r->get_error_code() : 'ok';

try {
	$altas = Altas::make();

	echo "== Parte A · Apagado, permisos y datos ==\n";
	Altas::activar( $grupo['numero'], false );
	$antes = $escritas();
	$ok( ! Altas::activo() && 'asde_altas_apagadas' === $codigo( $altas->crear_persona( $persona( $doc( '22222222J' ) ), 'escultas' ) ) && $antes === $escritas(), 'nace apagado: sin activarlo para el grupo no se escribe nada' );
	Altas::activar( $grupo['numero'], true );
	$crm->acls['stic_Contacts_Relationships']['edit'] = false;
	Permisos::olvidar( $grupo['numero'] );
	$ok( 'asde_altas_permiso' === $codigo( $altas->crear_persona( $persona( $doc( '22222222J' ) ), 'escultas' ) ), 'sin permiso del usuario del CRM para crear membresías, no se empieza' );
	$crm->acls['stic_Contacts_Relationships']['edit'] = true;
	Permisos::olvidar( $grupo['numero'] );

	$err = static fn( array $d ) => is_wp_error( $r = Altas::limpiar_persona( $d ) ) ? $r->get_error_message() : '';
	$ok( str_contains( $err( array_diff_key( $persona( $doc( '22222222J' ) ), array( 'first_name' => 1 ) ) ), 'Falta el nombre' ) && str_contains( $err( $persona( $doc( '22222222J' ) + array( 'stic_gender_c' => 'otro' ) ) ), 'género' ) && str_contains( $err( $persona( $doc( '22222222J' ) + array( 'birthdate' => '2999-01-01' ) ) ), 'fecha de nacimiento' ), 'obligatorios: nombre, apellidos, nacimiento (válido) y género (masculino, femenino o no binario)' );
	$sin_tutor = array_diff_key( $persona( $doc( '22222222J' ) ), array( 'tutor1_email_c' => 1 ) );
	$ok( str_contains( $err( $sin_tutor ), 'tutor/a 1' ) && '' === $err( array( 'birthdate' => $hace( 20 ) ) + $sin_tutor ) && '' === $err( array_diff_key( $persona( array( 'birthdate' => $hace( 30 ) ) + $doc( '22222222J' ) ), array( 'tutor1_nombre_c' => 1, 'tutor1_apellidos_c' => 1, 'tutor1_email_c' => 1 ) ) ), 'menores de 18: hace falta el tutor/a 1 con nombre, apellidos y email; a un adulto no se le pide (P-88)' );
	$ok( str_contains( $err( $persona() ), 'a partir de los 14 años' ) && ! is_wp_error( Altas::limpiar_persona( $persona( array( 'birthdate' => $hace( 13 ) ) ) ) ), 'el documento es obligatorio desde los 14 años; a los 13 se puede dar de alta sin él' );
	$limpios = Altas::limpiar_persona( $persona( $doc( ' 22.222.222-j ' ) + array( 'numerosocio_c' => '1', 'email1' => '' ) ) );
	$ok( '22222222J' === $limpios['stic_identification_number_c'] && ! isset( $limpios['numerosocio_c'] ) && ! isset( $limpios['email1'] ), 'el documento se guarda normalizado, se quitan los campos vacíos y los que no son de un alta (el nº de socio lo pone el CRM)' );
	$ok( str_contains( $err( $persona( $doc( '22222222A' ) ) ), 'letra del DNI' ) && str_contains( $err( $persona( $doc( '22222222J' ) + array( 'email1' => 'no-es-email' ) ) ), 'email' ), 'un documento o un email mal escritos no pasan' );

	echo "\n== Parte B · Persona nueva ==\n";
	$r = $altas->crear_persona( $persona( $doc( '22222222J' ) ), 'escultas' );
	$f = is_array( $r ) ? $ficha( $r['persona_id'] ) : null;
	$m = is_array( $r ) ? $de( $r['persona_id'] ) : array();
	$ok( is_array( $r ) && $f && '995001' === $r['numero'] && 0 === $r['otros'] && ! $r['reanudada'], 'se crea la persona y el CRM le pone el nº de Scouts de España, que se devuelve' );
	$ok( $f && 'Laia' === $f['first_name'] && '22222222J' === $f['stic_identification_number_c'] && 'femenino' === $f['stic_gender_c'] && '^Escultas^' === $f['stic_relationship_type_c'], 'la ficha lleva sus datos, el documento normalizado y «Secciones actuales» (la recalcula el CRM)' );
	$ok( 1 === count( $m ) && 'Escultas' === $m[0]['relationship_type'] && $hoy === $m[0]['start_date'] && 'activo' === $m[0]['estado_c'] && '1' === $m[0]['active'] && '995001' === $m[0]['numsocio_c'], 'y su membresía: sección, fecha de hoy, activa y con el nº de socio' );
	$creacion = array_values( array_filter( $crm->escrituras, static fn( $e ) => ! empty( $e['nueva'] ) && 'Contacts' === $e['modulo'] ) );
	$ok( $creacion && ! isset( $creacion[0]['campos']['numerosocio_c'] ) && ! isset( $creacion[0]['campos']['stic_relationship_type_c'] ), 'al crear no se envían ni el nº de socio ni «Secciones actuales» (los pone el CRM)' );
	$op = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id > %d ORDER BY id DESC LIMIT 1', Operaciones::table(), $ops_antes ), ARRAY_A );
	$ok( $op && Operaciones::HECHA === $op['estado'] && null === $op['cerrojo'] && ! str_contains( (string) $op['datos'], '22222222' ) && str_contains( (string) $op['datos'], $r['persona_id'] ), 'la operación queda hecha, sin cerrojo y solo con ids del CRM (sin el documento)' );
	$log = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT detalles FROM %i WHERE accion = %s AND id > %d ORDER BY id DESC LIMIT 1', Logger::table(), 'crm_alta_persona', $log_antes ) );
	$ok( '' !== $log && ! preg_match( '/Laia|22222222/', $log ), 'la auditoría apunta el alta sin datos personales' );

	$antes = $escritas();
	$ok( 'asde_alta_ya_en_grupo' === $codigo( $altas->crear_persona( $persona( $doc( '22222222J' ) ), 'escultas' ) ) && 'asde_alta_ya_en_grupo' === $codigo( $altas->crear_persona( $persona( $doc( '12345678Z' ) ), 'scouts' ) ) && $antes === $escritas(), 'si ya tiene ficha en el grupo no se crea otra (ni la recién creada ni una que ya estaba)' );
	$sin = $altas->crear_persona( $persona( $doc( '11111111H' ) ), 'escultas' );
	$ok( 'asde_alta_confirmar' === $codigo( $sin ) && 1 === ( $sin->get_error_data()['otros'] ?? null ) && $antes === $escritas(), 'si está en otro grupo u organización, primero hay que confirmarlo (y no se escribe nada)' );
	$r2 = $altas->crear_persona( $persona( $doc( '11111111H' ) + array( 'first_name' => 'Otra' ) ), 'escultas', '', true );
	$ok( is_array( $r2 ) && 1 === $r2['otros'] && 1 === $con_doc( '11111111H' ), 'confirmado, se da de alta también en el grupo (P-71, P-81)' );
	$r3 = $altas->crear_persona( $persona( array( 'first_name' => 'Pau', 'birthdate' => $hace( 9 ), 'stic_gender_c' => 'masculino' ) ), 'lobatos', '2026-09-15' );
	$ok( is_array( $r3 ) && '' === $ficha( $r3['persona_id'] )['stic_identification_number_c'] && '2026-09-15' === $de( $r3['persona_id'] )[0]['start_date'], 'un menor de 14 sin documento se da de alta, con la fecha de alta elegida' );

	echo "\n== Parte C · El grupo de seguridad y el enlace de la membresía ==\n";
	$crm->heredar_grupo        = false;
	$crm->enlazar_en_set_entry = false;
	$r4                        = $altas->crear_persona( $persona( $doc( '44444444A' ) + array( 'first_name' => 'Ona' ) ), 'rovers' );
	$crm->heredar_grupo        = true;
	$crm->enlazar_en_set_entry = true;
	$ok( is_array( $r4 ) && in_array( $r4['persona_id'], array_column( $crm->personas, 'id' ), true ) && ! $crm->sin_grupo, 'si el CRM no pone la ficha nueva en el grupo de quien la crea, se enlaza a su grupo de seguridad (P-73)' );
	$ok( is_array( $r4 ) && 1 === count( $de( $r4['persona_id'] ) ) && '^Rovers^' === $ficha( $r4['persona_id'] )['stic_relationship_type_c'], 'y si no guarda el enlace de la membresía en el mismo set_entry, se enlaza después' );

	echo "\n== Parte D · A medias, reintentos y dos a la vez ==\n";
	$crm->fallos['set_entry:stic_Contacts_Relationships'] = 1;
	$r5 = $altas->crear_persona( $persona( $doc( '55555555K' ) + array( 'first_name' => 'Max' ) ), 'escultas' );
	$id5 = (string) ( array_values( array_filter( $crm->personas, static fn( $p ) => '55555555K' === $p['stic_identification_number_c'] ) )[0]['id'] ?? '' );
	$ok( 'asde_alta_a_medias' === $codigo( $r5 ) && 1 === $con_doc( '55555555K' ) && ! $de( $id5 ) && Operaciones::FALLIDA === ( Operaciones::pendiente( 'doc:55555555K' )['estado'] ?? '' ), 'si falla la membresía, la persona queda creada y el alta, a medias' );
	$r5 = $altas->crear_persona( $persona( $doc( '55555555K' ) + array( 'first_name' => 'Max' ) ), 'escultas' );
	$ok( is_array( $r5 ) && $r5['reanudada'] && $id5 === $r5['persona_id'] && 1 === $con_doc( '55555555K' ) && 1 === count( $de( $id5 ) ) && null === Operaciones::pendiente( 'doc:55555555K' ), 'el reintento sigue donde se quedó: la misma ficha, sin crear otra, y con su membresía' );

	$otra = Operaciones::tomar( 'alta_persona', 'doc:33333333P' );
	$ok( 'asde_operacion_en_curso' === $codigo( $altas->crear_persona( $persona( $doc( '33333333P' ) ), 'escultas' ) ) && 0 === $con_doc( '33333333P' ), 'dos altas a la vez del mismo documento: la segunda espera (no se crean dos fichas)' );
	$wpdb->update( Operaciones::table(), array( 'actualizado' => gmdate( 'Y-m-d H:i:s', time() - 2 * Operaciones::ABANDONADA ) ), array( 'id' => $otra['id'] ) );
	$r6 = $altas->crear_persona( $persona( $doc( '33333333P' ) ), 'escultas' );
	$ok( is_array( $r6 ) && $r6['reanudada'] && 1 === $con_doc( '33333333P' ), 'una que se quedó «en curso» (la petición murió) se da por abandonada y se sigue' );

	echo "\n== Parte E · Quien vuelve, bajas y cambios de sección ==\n";
	$repo = PersonaRepository::make();
	$pep  = $repo->obtener( '990072' );
	$r7   = $altas->nueva_membresia( $pep, 'scouters' );
	$ok( is_array( $r7 ) && '^Scouters^' === $ficha( $id_de( 72 ) )['stic_relationship_type_c'] && 2 === count( $de( $id_de( 72 ) ) ), 'quien vuelve recibe una membresía nueva en su ficha (P-79)' );
	$nuria = $repo->obtener( '990071' );
	$ok( 'asde_alta' === $codigo( $altas->nueva_membresia( $nuria, 'rovers' ) ), 'a quien ya tiene una activa no se le da otra' );

	$mia = $repo->obtener( '990073' );
	$ok( 'asde_baja' === $codigo( $altas->baja( $mia, $hoy, 'inventado' ) ), 'la baja pide un motivo de la lista' );
	$r8 = $altas->baja( $mia, $hoy, 'personal' );
	$mm = $de( $id_de( 73 ) )[0] ?? array();
	$ok( is_array( $r8 ) && 'baja' === $mm['estado_c'] && $hoy === $mm['end_date'] && 'personal' === $mm['end_reason'] && '0' === $mm['active'] && '^^' === $ficha( $id_de( 73 ) )['stic_relationship_type_c'], 'la baja cierra la membresía (fecha, motivo y estado) sin borrar nada, y la ficha se queda sin sección' );
	$ok( 'asde_baja' === $codigo( $altas->baja( $repo->obtener( '990073' ) ?? $mia, $hoy, 'personal' ) ), 'sin membresía activa no hay nada que dar de baja' );
	// v0.34 (fallo visto por el usuario): una baja hecha a mano en el CRM poniendo la membresía en
	// «baja» sin fecha de baja. Para el CRM sigue activa (calcula `active` y «Secciones actuales» por
	// las fechas, P-74): la web la enseñaba de alta y «Dar de baja» decía que no tenía ninguna activa.
	$altas->nueva_membresia( $repo->obtener( '990073' ) ?? $mia, 'lobatos' );
	foreach ( $crm->membresias as $i => $mb ) {
		if ( $mb['persona'] === $id_de( 73 ) && '' === (string) $mb['end_date'] ) {
			$crm->membresias[ $i ]['estado_c'] = 'baja'; // Lo que cambió alguien en el CRM, sin fecha.
		}
	}
	$mia = $repo->obtener( '990073' );
	$ok( $mia && $mia->tiene_membresia_activa() && 1 === count( $altas->activas( $id_de( 73 ) ) ), 'en «baja» sin fecha de baja: para el CRM (y la web) sigue activa, y «Dar de baja» la encuentra' );
	$r10 = $mia ? $altas->baja( $mia, $hoy, 'personal' ) : null;
	$ab  = array_values( array_filter( $de( $id_de( 73 ) ), static fn( $m ) => 'personal' === $m['end_reason'] && $hoy === $m['end_date'] ) );
	$ok( is_array( $r10 ) && 2 === count( $ab ) && '0' === $ab[1]['active'] && '^^' === $ficha( $id_de( 73 ) )['stic_relationship_type_c'], 'y la baja desde la web le pone la fecha: el CRM la quita de «Secciones actuales»' );

	$r9 = $altas->cambio_seccion( $nuria, 'escultas', $hoy );
	$mn = $de( $id_de( 71 ) );
	$ok( is_array( $r9 ) && 2 === count( $mn ) && 'cambioseccion' === $mn[0]['end_reason'] && $hoy === $mn[0]['end_date'] && 'Escultas' === $mn[1]['relationship_type'] && $hoy === $mn[1]['start_date'] && '^Escultas^' === $ficha( $id_de( 71 ) )['stic_relationship_type_c'], 'cambio de sección: baja con motivo «cambioseccion» y alta en la nueva, el mismo día' );
	$ok( 'asde_cambio' === $codigo( $altas->cambio_seccion( $repo->obtener( '990071' ), 'escultas' ) ), 'a la misma sección no se cambia' );
	$ok( 'asde_cambio' === $codigo( $altas->cambio_seccion( $repo->obtener( '990071' ), 'castores' ) ), 'v0.31: un educando solo pasa a la siguiente sección por edad' );
	$ok( array( 'lobatos' ) === \AsdeBalears\AreaPrivada\Domain\Secciones::destinos_cambio( 'castores' ) && array( 'scouters', 'voluntariado' ) === \AsdeBalears\AreaPrivada\Domain\Secciones::destinos_cambio( 'rovers' ) && 6 === count( \AsdeBalears\AreaPrivada\Domain\Secciones::destinos_cambio( 'scouters' ) ), 'v0.31: de Castores, a Lobatos; de Rovers, a Scouters o Voluntariado; un scouter, a cualquier otra' );
	$crm->fallos['set_entry:stic_Contacts_Relationships'] = 1;
	$pep = $repo->obtener( '990072' );
	$ok( 'asde_alta_a_medias' === $codigo( $altas->cambio_seccion( $pep, 'voluntariado' ) ), 'si falla el alta del cambio, se queda a medias (con la baja hecha)' );
	$r10 = $altas->cambio_seccion( $pep, 'voluntariado' );
	$mp  = $de( $id_de( 72 ) );
	$ok( is_array( $r10 ) && 3 === count( $mp ) && 1 === count( array_filter( $mp, static fn( $x ) => 'cambioseccion' === $x['end_reason'] ) ) && '^Voluntariado^' === $ficha( $id_de( 72 ) )['stic_relationship_type_c'], 'el reintento no vuelve a cerrar la vieja: solo crea la nueva' );

	echo "\n== Parte F · El documento de un menor (P-80) ==\n";
	$pau = $repo->obtener( (string) $ficha( $r3['persona_id'] )['numerosocio_c'] );
	$ok( $pau && 'asde_documento_en_grupo' === $codigo( $altas->poner_documento( $pau, '12345678Z' ) ), 'si otra ficha del grupo ya tiene ese documento, no se guarda (sería la misma persona dos veces)' );
	$ok( 'asde_documento_confirmar' === $codigo( $altas->poner_documento( $pau, '66666666Q' ) ) && '' === $ficha( $r3['persona_id'] )['stic_identification_number_c'], 'si está en otro grupo u organización, primero hay que confirmarlo (y no se guarda)' );
	$r11 = $altas->poner_documento( $pau, '77777777B' );
	$ok( is_array( $r11 ) && '77777777B' === $ficha( $r3['persona_id'] )['stic_identification_number_c'] && 'asde_documento' === $codigo( $altas->poner_documento( $repo->obtener( $pau->num_socio_asde ), '88888888Y' ) ), 'si no está en ningún sitio, se guarda; y a quien ya tiene documento no se le cambia desde aquí' );

	echo "\n== Parte G · Lo que la web no puede hacer ==\n";
	$ok( is_wp_error( Escritura::crear( 'Accounts', array( 'name' => 'x' ), 'prueba' ) ) && is_wp_error( Escritura::crear( 'Contacts', array( 'numerosocio_c' => '1' ), 'prueba' ) ), 'solo se crean personas y membresías, y nunca con el nº de socio' );
	$ok( is_wp_error( Escritura::relacionar( $id_de( 71 ), 'SecurityGroups', array( 'de000000-0000-4000-8000-000000000001' ), 'prueba' ) ), 'una ficha solo se enlaza con el grupo de seguridad del PROPIO grupo' );
	$ajena = $de( $id_de( 72 ) )[0]['id'];
	$ok( is_wp_error( Escritura::actualizar_membresia( $repo->obtener( '990071' ), $ajena, array( 'estado_c' => 'baja' ), 'prueba' ) ), 'una membresía solo se cambia a través de su persona' );
	$ok( 0 === $cortadas, 'ninguna petición ha salido hacia un CRM real' );
} catch ( Throwable $e ) {
	++$fails;
	echo 'FAIL excepcion: ', $e->getMessage(), ' (', basename( $e->getFile() ), ':', $e->getLine(), ")\n";
} finally {
	// Limpieza: todo lo creado fuera; lo tocado, como estaba.
	$crm->desactivar();
	remove_filter( 'pre_http_request', $cortar_crm, 2 );
	remove_filter( 'asde_ap_instantanea_segundos', '__return_zero' );
	null === $opcion_antes ? delete_option( Altas::OPCION ) : update_option( Altas::OPCION, $opcion_antes, false );
	Permisos::olvidar( $grupo['numero'] );
	delete_transient( 'asde_ap_recuento_federal_' . $grupo['numero'] );
	wp_using_ext_object_cache( $cache_externa );
	wp_set_current_user( 0 );

	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id > %d', Operaciones::table(), $ops_antes ) );
	$lista = implode( ',', array_map( 'intval', $ids ) ) ?: '0';
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Logger::table() . " WHERE id > %d AND ( user_id IN ($lista) OR accion LIKE 'crm\\_%%' )", $log_antes ) ); // phpcs:ignore WordPress.DB
	foreach ( $ids as $uid ) {
		Ambito::olvidar( (int) $uid );
		wp_delete_user( (int) $uid );
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
	Grupo::forzar( null );

	$ok( 0 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE id > %d', Operaciones::table(), $ops_antes ) ) && 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users} WHERE user_login LIKE 'prueba-escalta-%'" ), 'limpieza: sin operaciones ni usuarios de la prueba' ); // phpcs:ignore WordPress.DB
}

echo $fails ? "\n$fails FALLOS\n" : "\nTODO OK (nada tocado en el CRM real; el sitio queda como estaba)\n";
