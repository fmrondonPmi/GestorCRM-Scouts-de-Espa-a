<?php
/**
 * Pruebas de los presupuestos, fase 2 (v0.55, doc 57; entorno LOCAL). Ejecutar desde app/public del site Local:
 *
 *   wp eval-file wp-content/plugins/asde-area-privada/tests/presupuestos.php
 *
 * - Partidas (veces × importe), el objetivo de recaudación del proyecto (se crea una sola vez),
 *   saldo y «saldo sin la recaudación».
 * - Permisos: un scouter solo en sus secciones; Tesorería en todas y en el grupo; familias fuera;
 *   un grupo no ve las partidas de otro; módulo apagado.
 * - Estados: borrador → enviado → aprobado (bloqueado), reabrir con motivo, copiar de la ronda anterior.
 * - POST: sin nonce o sin permiso, nada cambia.
 * No usa el CRM (los importes se prueban con la parte pura) y deja el sitio como estaba.
 */
defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/lib/CrmSimulado.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Auth\Ambito;
use AsdeBalears\AreaPrivada\Crm\Escritura;
use AsdeBalears\AreaPrivada\Socios\SociosTable;
use AsdeBalears\AreaPrivada\Domain\Grupo;
use AsdeBalears\AreaPrivada\Domain\Grupos;
use AsdeBalears\AreaPrivada\Domain\Modulos;
use AsdeBalears\AreaPrivada\Install\Roles;
use AsdeBalears\AreaPrivada\Presupuestos\EstadosTable;
use AsdeBalears\AreaPrivada\Presupuestos\GastosRealesTable;
use AsdeBalears\AreaPrivada\Presupuestos\PartidasTable;
use AsdeBalears\AreaPrivada\Presupuestos\Servicio;
use AsdeBalears\AreaPrivada\Tesoreria\Config;
use AsdeBalears\AreaPrivada\Usuarios\Usuarios;

global $wpdb;
$fails = 0;
$ok    = function ( $cond, string $label ) use ( &$fails ) {
	if ( ! $cond ) {
		++$fails;
	}
	echo ( $cond ? 'OK   ' : 'FAIL ' ), $label, "\n";
};
$igual = static fn( $a, $b ) => abs( (float) $a - (float) $b ) < 0.001;

$grupo = Grupos::por_numero( '684' ) ?? ( array_values( Grupos::todos() )[0] ?? null );
if ( ! $grupo ) {
	exit( "Hace falta un grupo.\n" );
}
foreach ( get_users( array( 'search' => 'prueba-pre-*', 'search_columns' => array( 'user_login' ) ) ) as $u ) {
	exit( 'Ya existe el usuario ' . $u->user_login . " (¿una ejecución anterior interrumpida?). Bórralo antes de repetir la prueba.\n" );
}

$max_id       = static fn( string $t ) => (int) $wpdb->get_var( "SELECT COALESCE(MAX(id), 0) FROM $t" ); // phpcs:ignore WordPress.DB
$par_antes    = $max_id( PartidasTable::table() );
$gas_antes    = $max_id( GastosRealesTable::table() );
$est_antes    = $max_id( EstadosTable::table() );
$log_antes    = $max_id( Logger::table() );
$tes_antes    = get_option( 'asde_ap_tesoreria', null );
$modulos_antes = get_option( 'asde_ap_modulos', null );
$ids          = array();
$socios_antes = array_column( (array) $wpdb->get_results( 'SELECT * FROM ' . SociosTable::table(), ARRAY_A ), null, 'num_socio_asde' ); // phpcs:ignore WordPress.DB
Modulos::guardar( (string) $grupo['numero'], array_keys( Modulos::LISTA ) );
Modulos::olvidar();

// CRM simulado (fichas inventadas): el ámbito de cada persona se lee de él; el CRM real se corta.
$cache_externa = wp_using_ext_object_cache( true );
$cortar_crm    = static function ( $pre, $args, $url ) {
	if ( false === $pre && str_contains( (string) $url, '/rest.php' ) ) {
		return new WP_Error( 'prueba', 'CRM real cortado durante la prueba' );
	}
	return $pre;
};
add_filter( 'pre_http_request', $cortar_crm, 2, 3 );
add_filter( 'asde_ap_instantanea_segundos', '__return_zero' );
$P   = array( 'Asde_Prueba_Crm', 'persona' );
$crm = new Asde_Prueba_Crm(
	array(
		$P( 91, 'Eva', 'Pre Lobos', '1990-01-01', 'Scouters' ),
		$P( 92, 'Ian', 'Pre Scouts', '1991-01-01', 'Scouters' ),
		$P( 93, 'Pol', 'Pre Teso', '1985-04-04', 'Scouters' ),
		$P( 94, 'Leo', 'Pre Hijo', '2016-05-10', 'Lobatos' ),
	),
	$grupo['crm_id']
);
Grupo::forzar( $grupo );
$crm->activar();
Escritura::usar_para_pruebas( $crm );

$crear = static function ( string $login, array $roles, array $meta ) use ( &$ids, $grupo ): int {
	$id = wp_insert_user( array( 'user_login' => $login, 'user_email' => $login . '@example.invalid', 'user_pass' => wp_generate_password( 24 ), 'role' => $roles[0] ) );
	$u  = get_userdata( $id );
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
$como = static function ( int $uid ): Ambito {
	wp_set_current_user( $uid );
	Ambito::olvidar( $uid );
	Modulos::olvidar();
	return Ambito::actual();
};

try {
	$u_lob  = $crear( 'prueba-pre-lob', array( Roles::SCOUTER ), array( Usuarios::META_SOCIO => '990091', Usuarios::META_SECCION => array( 'lobatos' ) ) );
	$u_sc   = $crear( 'prueba-pre-sc', array( Roles::SCOUTER ), array( Usuarios::META_SOCIO => '990092', Usuarios::META_SECCION => array( 'scouts' ) ) );
	$u_teso = $crear( 'prueba-pre-teso', array( Roles::TESORERIA ), array( Usuarios::META_SOCIO => '990093' ) );
	$u_fam  = $crear( 'prueba-pre-fam', array( Roles::TUTOR ), array( Usuarios::META_REPRESENTADO => '990094' ) );
	$ronda  = '2098-2099';
	$antes  = '2097-2098';

	echo "== Parte A · Permisos ==\n";
	$a_lob = $como( $u_lob );
	$ok( array( 'lobatos' ) === Servicio::secciones_para( $a_lob ), 'un scouter de Lobatos abre solo el presupuesto de Lobatos' );
	$ok( '' === Servicio::error_edicion( $ronda, 'lobatos', $a_lob ), 'y lo puede editar' );
	$ok( '' !== Servicio::error_edicion( $ronda, 'scouts', $a_lob ), 'el de otra sección, no' );
	$ok( '' !== Servicio::error_edicion( $ronda, 'grupo', $a_lob ), 'el del grupo, no' );
	$ok( '' !== Servicio::error_edicion( 'mal', 'lobatos', $a_lob ) && '' !== Servicio::error_edicion( $ronda, 'inventada', $a_lob ), 'ronda o sección inventadas, no' );
	$a_teso = $como( $u_teso );
	$ok( 6 === count( Servicio::secciones_para( $a_teso ) ) && in_array( 'grupo', Servicio::secciones_para( $a_teso ), true ), 'Tesorería abre las cinco secciones y el grupo' );
	$ok( '' === Servicio::error_edicion( $ronda, 'grupo', $a_teso ), 'y edita el del grupo' );
	$a_fam = $como( $u_fam );
	$ok( array() === Servicio::secciones_para( $a_fam ) && ! Servicio::puede_entrar(), 'una familia no entra' );
	$ok( '' !== Servicio::anadir_partida( $ronda, 'lobatos', $a_fam, 'gasto', 'Material', 'x', 1, 1 ), 'ni añade partidas' );

	echo "== Parte B · Partidas ==\n";
	$como( $u_lob );
	$cats = Config::de( $ronda )['presupuesto']['categorias'];
	$ok( in_array( 'Excursión', $cats, true ) && 8 === count( $cats ), 'ocho categorías de gasto de partida' );
	$ok( '' === Servicio::anadir_partida( $ronda, 'lobatos', $a_lob, 'gasto', 'Excursión', '', 6, '35,50', 'Autobús incluido' ), 'gasto: Excursión, 6 × 35,50 (coma decimal)' );
	$ok( '' === Servicio::anadir_partida( $ronda, 'lobatos', $a_lob, 'gasto', 'Material', 'Pegamento', 1, 12 ), 'gasto con concepto propio' );
	$ok( '' !== Servicio::anadir_partida( $ronda, 'lobatos', $a_lob, 'gasto', 'Inventada', '', 1, 1 ), 'categoría desconocida: rechazada' );
	$ok( '' !== Servicio::anadir_partida( $ronda, 'lobatos', $a_lob, 'gasto', 'Material', '', 0, 1 ), '«¿Cuántos?» = 0: rechazado' );
	$ok( '' !== Servicio::anadir_partida( $ronda, 'lobatos', $a_lob, 'gasto', 'Material', '', 1000, 1 ), '«¿Cuántos?» > 999: rechazado' );
	$ok( '' !== Servicio::anadir_partida( $ronda, 'lobatos', $a_lob, 'gasto', 'Material', '', 1, -5 ), 'importe negativo: rechazado' );
	$ok( '' !== Servicio::anadir_partida( $ronda, 'lobatos', $a_lob, 'gasto', 'Material', '', 1, 'abc' ), 'importe que no es un número: rechazado' );
	$ok( '' === Servicio::anadir_partida( $ronda, 'lobatos', $a_lob, 'ingreso', '', 'Subvención del ayuntamiento', 1, 100 ), 'ingreso con concepto' );
	$ok( '' !== Servicio::anadir_partida( $ronda, 'lobatos', $a_lob, 'ingreso', '', '', 1, 100 ), 'ingreso sin concepto: rechazado' );

	Servicio::asegurar_proyecto( $ronda, 'lobatos' );
	Servicio::asegurar_proyecto( $ronda, 'lobatos' );
	$pr = array_values( array_filter( PartidasTable::de( $ronda, 'lobatos' ), static fn( $p ) => PartidasTable::PROYECTO === $p['categoria'] ) );
	$ok( 1 === count( $pr ) && 'Cacería' === $pr[0]['concepto'] && PartidasTable::INGRESO === $pr[0]['tipo'], 'el proyecto de Lobatos (Cacería) se crea una sola vez, como ingreso' );
	$ok( '' === Servicio::fijar_proyecto( $ronda, 'lobatos', $a_lob, '500' ) && '' !== Servicio::fijar_proyecto( $ronda, 'lobatos', $a_lob, '-1' ), 'el objetivo de recaudación se fija (y no admite negativos)' );
	$ok( '' !== Servicio::quitar_partida( $ronda, 'lobatos', $a_lob, $pr[0]['id'] ), 'el proyecto no se quita' );

	$partidas = PartidasTable::de( $ronda, 'lobatos' );
	$ok( PartidasTable::GASTO === $partidas[0]['tipo'] && 213.0 === $partidas[0]['total'], 'gastos primero; Excursión: 6 × 35,50 = 213 €' );
	$res = Servicio::resultado( 1000.0, $partidas );
	$ok( $igual( 225, $res['gastos'] ) && $igual( 600, $res['ingresos'] ) && $igual( 500, $res['recaudacion'] ), 'gastos 225 (213 + 12), ingresos 600 (500 + 100), recaudación 500' );
	$ok( $igual( 1375, $res['saldo'] ) && $igual( 875, $res['sin_recaudacion'] ), 'saldo = 1000 + 600 − 225 = 1375; sin la recaudación, 875' );
	$solo_gastos = array_filter( $partidas, static fn( $p ) => PartidasTable::GASTO === $p['tipo'] );
	$ok( $igual( -225, Servicio::resultado( 0.0, $solo_gastos )['saldo'] ), 'sin dinero ni ingresos el saldo sale negativo (−225 €)' );
	$ok( Servicio::resultado( 0.0, array() )['saldo'] === 0.0, 'un presupuesto vacío: saldo 0' );

	$gasto = $partidas[0];
	$ok( '' !== Servicio::quitar_partida( $ronda, 'lobatos', $a_lob, 99999999 ), 'quitar una partida que no existe: error' );
	$ok( '' !== Servicio::quitar_partida( $ronda, 'scouts', $a_lob, $gasto['id'] ), 'quitar desde otra sección: error' );
	$ok( '' === Servicio::quitar_partida( $ronda, 'lobatos', $a_lob, $gasto['id'] ), 'quitar la partida' );
	$ok( 0 === count( array_filter( PartidasTable::de( $ronda, 'lobatos' ), static fn( $p ) => $p['id'] === $gasto['id'] ) ), 'ya no está' );

	echo "== Parte C · Un grupo no ve las partidas de otro ==\n";
	// Una fila de otro grupo (999) en la misma ronda y sección: no se ve, no se quita y no cuenta.
	$wpdb->insert( PartidasTable::table(), array( 'grupo' => '999', 'ronda' => $ronda, 'seccion' => 'lobatos', 'tipo' => 'gasto', 'categoria' => 'Material', 'concepto' => 'De otro grupo', 'veces' => 1, 'importe' => 777, 'creado' => current_time( 'mysql', true ) ) );
	$ajena = (int) $wpdb->insert_id;
	$wpdb->insert( EstadosTable::table(), array( 'grupo' => '999', 'ronda' => $ronda, 'seccion' => 'rovers', 'estado' => 'aprobado' ) );
	$ok( 0 === count( array_filter( PartidasTable::de( $ronda, 'lobatos' ), static fn( $p ) => 'De otro grupo' === $p['concepto'] ) ), 'las partidas de otro grupo no salen' );
	$ok( '' !== Servicio::quitar_partida( $ronda, 'lobatos', $a_lob, $ajena ), 'y no se pueden quitar' );
	$ok( 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE id = %d', PartidasTable::table(), $ajena ) ), 'sigue en su sitio' ); // phpcs:ignore WordPress.DB
	$ok( EstadosTable::BORRADOR === EstadosTable::de( $ronda, 'rovers' )['estado'], 'el estado «aprobado» de otro grupo no es el nuestro' );

	echo "== Parte D · Estados ==\n";
	$ok( EstadosTable::BORRADOR === EstadosTable::de( $ronda, 'lobatos' )['estado'], 'sin fila es un borrador' );
	$ok( '' === Servicio::enviar( $ronda, 'lobatos', $a_lob ) && EstadosTable::ENVIADO === EstadosTable::de( $ronda, 'lobatos' )['estado'], 'la sección lo envía' );
	$ok( '' !== Servicio::error_edicion( $ronda, 'lobatos', $a_lob ) && '' !== Servicio::anadir_partida( $ronda, 'lobatos', $a_lob, 'gasto', 'Material', '', 1, 1 ), 'enviado: bloqueado para el scouter' );
	$ok( '' !== Servicio::aprobar( $ronda, 'lobatos' ), 'el scouter no aprueba' );
	$ok( '' !== Servicio::reabrir( $ronda, 'lobatos', 'quiero' ), 'ni reabre' );
	$como( $u_teso );
	$ok( '' === Servicio::aprobar( $ronda, 'lobatos' ) && EstadosTable::APROBADO === EstadosTable::de( $ronda, 'lobatos' )['estado'] && EstadosTable::de( $ronda, 'lobatos' )['aprobado_por'] === $u_teso, 'Tesorería aprueba (queda quién)' );
	$ok( '' !== Servicio::aprobar( $ronda, 'lobatos' ), 'aprobar dos veces: aviso' );
	$ok( '' !== Servicio::error_edicion( $ronda, 'lobatos', $a_teso ), 'aprobado: bloqueado también para Tesorería' );
	$ok( '' !== Servicio::reabrir( $ronda, 'lobatos', '   ' ), 'reabrir sin motivo: rechazado' );
	$ok( '' === Servicio::reabrir( $ronda, 'lobatos', 'Se añade un gasto' ) && EstadosTable::BORRADOR === EstadosTable::de( $ronda, 'lobatos' )['estado'], 'reabrir con motivo: vuelve a ser un borrador' );
	$motivo = (string) ( json_decode( (string) $wpdb->get_var( $wpdb->prepare( 'SELECT detalles FROM ' . Logger::table() . " WHERE id > %d AND accion = 'presupuesto_reabierto' ORDER BY id DESC LIMIT 1", $log_antes ) ), true )['motivo'] ?? '' ); // phpcs:ignore WordPress.DB
	$ok( false !== strpos( $motivo, 'Se añade un gasto' ), 'el motivo queda en el registro' );
	$ok( '' !== Servicio::reabrir( $ronda, 'lobatos', 'otra vez' ), 'reabrir un borrador: aviso' );
	$ok( '' === Servicio::aprobar( $ronda, 'grupo' ) && '' === Servicio::reabrir( $ronda, 'grupo', 'x' ), 'el presupuesto del grupo también se aprueba y reabre' );

	echo "== Parte E · Copiar de la ronda anterior ==\n";
	$como( $u_lob );
	$ok( $antes === Servicio::ronda_anterior( $ronda ), 'la ronda anterior de 2098-2099 es 2097-2098' );
	$a_sc = $como( $u_sc );
	$ok( '' !== Servicio::copiar_anterior( $ronda, 'scouts', $a_sc ), 'Scouts: no hay presupuesto de la ronda anterior' );
	PartidasTable::anadir( $antes, 'scouts', 'gasto', 'Excursión', 'Excursiones', 4, 40.0 );
	PartidasTable::anadir( $antes, 'scouts', 'ingreso', PartidasTable::PROYECTO, 'Aventura', 1, 300.0 );
	PartidasTable::anadir( $antes, 'scouts', 'ingreso', 'Otros ingresos', 'Rifa', 1, 50.0 );
	EstadosTable::poner( $antes, 'scouts', EstadosTable::APROBADO );
	$ok( '' === Servicio::copiar_anterior( $ronda, 'scouts', $a_sc ), 'copiar: ya hay presupuesto de la ronda anterior' );
	$nuevas = PartidasTable::de( $ronda, 'scouts' );
	$ok( 3 === count( $nuevas ), 'se copian las 3 partidas (el proyecto no se duplica)' );
	$ok( 1 === count( array_filter( $nuevas, static fn( $p ) => PartidasTable::PROYECTO === $p['categoria'] && $igual( 300, $p['importe'] ) ) ), 'el objetivo del proyecto se copia (300 €)' );
	$ok( EstadosTable::BORRADOR === EstadosTable::de( $ronda, 'scouts' )['estado'], 'el estado no se copia: borrador' );
	$ok( '' !== Servicio::copiar_anterior( $ronda, 'scouts', $a_sc ), 'copiar sobre un presupuesto con partidas: rechazado' );

	echo "== Parte E2 · Gasto real ==\n";
	$a_lob  = $como( $u_lob );
	$ps     = PartidasTable::de( $ronda, 'lobatos' );
	$gastos_l = array_values( array_filter( $ps, static fn( $p ) => PartidasTable::GASTO === $p['tipo'] ) );
	$pid    = $gastos_l[0]['id']; // Pegamento, 12 €.
	$ok( '' === Servicio::anadir_gasto_real( $ronda, 'lobatos', $a_lob, $pid, '2098-10-05', 'Compra en la papelería', '7,50' ), 'anotar un gasto real de 7,50 € contra una partida' );
	$ok( '' === Servicio::anadir_gasto_real( $ronda, 'lobatos', $a_lob, $pid, '2098-10-09', '', 2 ), 'otro de 2 € (sin concepto: usa el de la partida)' );
	$ok( '' !== Servicio::anadir_gasto_real( $ronda, 'lobatos', $a_lob, $pid, '2098-02-30', 'x', 1 ), 'fecha inexistente: rechazada' );
	$ok( '' !== Servicio::anadir_gasto_real( $ronda, 'lobatos', $a_lob, $pid, '2098-10-09', 'x', 0 ) && '' !== Servicio::anadir_gasto_real( $ronda, 'lobatos', $a_lob, $pid, '2098-10-09', 'x', -3 ), 'importe 0 o negativo: rechazado' );
	$pr_l = array_values( array_filter( $ps, static fn( $p ) => PartidasTable::PROYECTO === $p['categoria'] ) );
	$ok( '' !== Servicio::anadir_gasto_real( $ronda, 'lobatos', $a_lob, $pr_l[0]['id'], '2098-10-09', 'x', 1 ), 'no se imputa a un ingreso' );
	$ok( '' !== Servicio::anadir_gasto_real( $ronda, 'lobatos', $a_lob, 99999999, '2098-10-09', 'x', 1 ), 'ni a una partida que no existe' );
	$ok( '' !== Servicio::anadir_gasto_real( $ronda, 'scouts', $a_lob, $pid, '2098-10-09', 'x', 1 ), 'ni en la sección de otro' );
	$por = GastosRealesTable::por_partida( $ronda, 'lobatos' );
	$ok( $igual( 9.5, $por[ $pid ] ?? 0 ), 'gastado en la partida: 9,50 €' );
	$ej = Servicio::ejecucion( $ps, $por );
	$ok( $igual( 12, $ej['presupuestado'] ) && $igual( 9.5, $ej['gastado'] ) && $igual( 2.5, $ej['queda'] ), 'presupuestado 12 − gastado 9,50 = queda 2,50 €' );
	$ok( $igual( -3, Servicio::ejecucion( $ps, array( $pid => 15.0 ) )['queda'] ), 'si se gasta más de lo presupuestado, queda en negativo' );
	$primero = GastosRealesTable::de( $ronda, 'lobatos' )[0];
	$ok( '' !== Servicio::quitar_gasto_real( $ronda, 'lobatos', $a_lob, 99999999 ), 'quitar un gasto que no existe: error' );
	$ok( '' === Servicio::quitar_gasto_real( $ronda, 'lobatos', $a_lob, $primero['id'] ) && 1 === count( GastosRealesTable::de( $ronda, 'lobatos' ) ), 'quitar un gasto real' );
	$como( $u_sc );
	$ok( '' !== Servicio::quitar_gasto_real( $ronda, 'lobatos', Ambito::actual(), GastosRealesTable::de( $ronda, 'lobatos' )[0]['id'] ), 'otro scouter no quita gastos de Lobatos' );
	$como( $u_lob );
	$ok( '' === Servicio::enviar( $ronda, 'lobatos', $a_lob ) && '' === Servicio::anadir_gasto_real( $ronda, 'lobatos', $a_lob, $pid, '2098-11-01', 'Tras enviar', 1 ), 'con el presupuesto enviado se sigue anotando el gasto real' );
	$como( $u_teso );
	Servicio::reabrir( $ronda, 'lobatos', 'seguir' );
	$como( $u_lob );
	$ok( '' === Servicio::quitar_partida( $ronda, 'lobatos', $a_lob, $pid ) && array() === GastosRealesTable::por_partida( $ronda, 'lobatos' ), 'al quitar la partida se van sus gastos reales' );
	$wpdb->insert( GastosRealesTable::table(), array( 'grupo' => '999', 'ronda' => $ronda, 'seccion' => 'lobatos', 'partida_id' => 1, 'fecha' => '2098-10-01', 'concepto' => 'De otro grupo', 'importe' => 50, 'creado' => current_time( 'mysql', true ) ) );
	$ok( array() === GastosRealesTable::de( $ronda, 'lobatos' ), 'los gastos reales de otro grupo no salen' );

	echo "== Parte E3 · Pantallas ==\n";
	$vista_obj = new \AsdeBalears\AreaPrivada\Frontend\Views\PresupuestosView();
	$pintar    = static function ( int $uid, array $get ) use ( $vista_obj, $como ): string {
		$como( $uid );
		$_GET = $_REQUEST = $get;
		try {
			return $vista_obj->render();
		} finally {
			$_GET = $_REQUEST = array();
		}
	};
	$html = $pintar( $u_teso, array( 'ronda' => $ronda ) );
	$ok( false !== strpos( $html, 'Reparto por sección' ) && false !== strpos( $html, 'Presupuesto de cada sección y del grupo' ) && false !== strpos( $html, 'Categorías de gasto' ), 'Tesorería ve el resumen: reparto, presupuestos y categorías' );
	$ok( 1 === preg_match( '/<option value="resumen"\s+selected/', $html ) && false === strpos( $html, 'Warning' ) && false === strpos( $html, 'Fatal' ) && false === strpos( $html, 'Notice:' ), 'con el selector en «Resumen» y sin avisos de PHP' );
	$html = $pintar( $u_teso, array( 'ronda' => $ronda, 'vista' => 'grupo' ) );
	$ok( false !== strpos( $html, 'Presupuesto: Grupo' ) && false !== strpos( $html, 'Añadir una partida' ) && false !== strpos( $html, 'Gasto real' ), 'Tesorería abre el presupuesto del grupo, con su formulario' );
	$html = $pintar( $u_lob, array( 'ronda' => $ronda, 'vista' => 'resumen' ) );
	$ok( false === strpos( $html, 'Reparto por sección' ) && false !== strpos( $html, 'Presupuesto: Lobatos' ), 'un scouter no ve el resumen del grupo: cae en su sección' );
	$ok( false === strpos( $html, 'Arcas del grupo' ) && false === strpos( $html, 'name="presupuesto[pct_grupo]"' ), 'y sin el desglose del grupo ni los porcentajes' );
	$html = $pintar( $u_lob, array( 'ronda' => $ronda, 'vista' => 'scouts' ) );
	$ok( false !== strpos( $html, 'Presupuesto: Lobatos' ) && false === strpos( $html, 'Presupuesto: Scouts' ), 'pedir la sección de otro enseña la suya' );
	$html = $pintar( $u_fam, array( 'ronda' => $ronda ) );
	$ok( false === strpos( $html, 'Reparto por sección' ) && false === strpos( $html, 'Añadir una partida' ), 'una familia no ve nada' );

	echo "== Parte F · Módulo apagado ==\n";
	$otros = array_diff( array_keys( Modulos::LISTA ), array( Modulos::PRESUPUESTOS ) );
	Modulos::guardar( (string) $grupo['numero'], $otros );
	$como( $u_teso );
	$ok( ! Servicio::puede_entrar() && array() === Servicio::secciones_para( Ambito::actual() ), 'con el módulo apagado nadie entra' );
	$ok( ! user_can( $u_lob, Servicio::CAP_SECCION ), 'y la capability desaparece' );
	Modulos::guardar( (string) $grupo['numero'], array_keys( Modulos::LISTA ) );
	$como( $u_teso );
	$ok( Servicio::puede_entrar() && user_can( $u_lob, Servicio::CAP_SECCION ), 'al encenderlo vuelve' );

	echo "== Parte G · Permisos por rol y catálogo ==\n";
	foreach ( array( Roles::SCOUTER, Roles::SECRETARIA, Roles::COORDINACION, Roles::TESORERIA ) as $rol ) {
		$ok( ! empty( get_role( $rol )->capabilities[ Servicio::CAP_SECCION ] ), "el rol $rol tiene asde_presupuestos_seccion" );
	}
	$ok( empty( get_role( Roles::TUTOR )->capabilities[ Servicio::CAP_SECCION ] ) && empty( get_role( Roles::PARTICIPANTE )->capabilities[ Servicio::CAP_SECCION ] ), 'las familias y los participantes no la tienen' );
	$ok( \AsdeBalears\AreaPrivada\Accesos\Catalogo::existe( Servicio::CAP_SECCION ) && 'tesoreria' === \AsdeBalears\AreaPrivada\Accesos\Catalogo::modulo( Servicio::CAP_SECCION ), 'está en el gestor de permisos (módulo Tesorería)' );

	echo "== Parte H · Formularios (POST) ==\n";
	$vista = new \AsdeBalears\AreaPrivada\Frontend\Views\PresupuestosView();
	$redirigir = static function ( $location ) {
		throw new \RuntimeException( 'redirect:' . $location );
	};
	add_filter( 'wp_redirect', $redirigir, 1 );
	$morir = static fn() => static function () {
		throw new \RuntimeException( 'die' );
	};
	add_filter( 'wp_die_handler', $morir, 999 );
	$enviar = static function ( int $uid, array $post, bool $nonce = true ) use ( $vista, $como ): string {
		$como( $uid );
		$_POST = $_REQUEST = $post + ( $nonce ? array( '_wpnonce' => wp_create_nonce( 'asde_presupuestos' ) ) : array() );
		try {
			$vista->handle_post();
			return 'sin redirección';
		} catch ( \RuntimeException $e ) {
			return $e->getMessage();
		} finally {
			$_POST = $_REQUEST = array();
		}
	};
	$n0 = count( PartidasTable::de( $ronda, 'lobatos' ) );
	$post = array( 'asde_action' => 'partida_nueva', 'ronda' => $ronda, 'seccion' => 'lobatos', 'tipo' => 'gasto', 'categoria' => 'Formación', 'concepto' => '', 'veces' => '2', 'importe' => '10' );
	$r = $enviar( $u_lob, $post, false );
	$ok( 'die' === $r && count( PartidasTable::de( $ronda, 'lobatos' ) ) === $n0, 'sin nonce: no se añade nada' );
	$r = $enviar( $u_fam, $post );
	$ok( 'die' === $r && count( PartidasTable::de( $ronda, 'lobatos' ) ) === $n0, 'una familia: 403 y no se añade nada' );
	$r = $enviar( $u_lob, $post );
	$ok( 0 === strpos( $r, 'redirect:' ) && count( PartidasTable::de( $ronda, 'lobatos' ) ) === $n0 + 1, 'el scouter de la sección añade la partida y vuelve a la pantalla' );
	$r = $enviar( $u_lob, array( 'seccion' => 'scouts' ) + $post );
	$ok( count( PartidasTable::de( $ronda, 'scouts' ) ) === 3, 'en otra sección no añade nada' );
	$r = $enviar( $u_lob, array( 'asde_action' => 'guardar_presupuestos', 'ronda' => $ronda, 'presupuesto' => array( 'pct_grupo' => '99' ) ) );
	$ok( 'die' === $r && 40.0 === Config::de( $ronda )['presupuesto']['pct_grupo'], 'un scouter no cambia los porcentajes' );
	$r = $enviar( $u_teso, array( 'asde_action' => 'guardar_presupuestos', 'ronda' => $ronda, 'presupuesto' => array( 'pct_grupo' => '25', 'categorias' => "Campamento\nMaterial\nMaterial\n" ) ) );
	$cfg = Config::de( $ronda )['presupuesto'];
	$ok( 25.0 === $cfg['pct_grupo'] && array( 'Campamento', 'Material' ) === $cfg['categorias'], 'Tesorería cambia el % y las categorías (sin repetidas)' );
	remove_filter( 'wp_redirect', $redirigir, 1 );
	remove_filter( 'wp_die_handler', $morir, 999 );
} catch ( Throwable $e ) {
	++$fails;
	echo 'FAIL excepcion: ', $e->getMessage(), ' (', basename( $e->getFile() ), ':', $e->getLine(), ")\n";
} finally {
	$_POST = $_REQUEST = array();
	wp_set_current_user( 0 );
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id > %d', PartidasTable::table(), $par_antes ) ); // phpcs:ignore WordPress.DB
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id > %d', EstadosTable::table(), $est_antes ) ); // phpcs:ignore WordPress.DB
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id > %d', GastosRealesTable::table(), $gas_antes ) ); // phpcs:ignore WordPress.DB
	$lista = implode( ',', array_map( 'intval', $ids ) ) ?: '0';
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Logger::table() . " WHERE id > %d AND ( user_id IN ($lista) OR objeto = 'presupuesto' OR accion LIKE 'presupuestos%%' )", $log_antes ) ); // phpcs:ignore WordPress.DB
	foreach ( $ids as $uid ) {
		delete_transient( 'asde_ap_notices_' . $uid );
		Ambito::olvidar( (int) $uid );
		wp_delete_user( (int) $uid );
	}
	foreach ( array( 'asde_ap_tesoreria' => $tes_antes, 'asde_ap_modulos' => $modulos_antes ) as $op => $valor ) {
		null === $valor ? delete_option( $op ) : update_option( $op, $valor, false );
	}
	Modulos::olvidar();
	Escritura::usar_para_pruebas( null );
	$crm->desactivar();
	remove_filter( 'pre_http_request', $cortar_crm, 2 );
	remove_filter( 'asde_ap_instantanea_segundos', '__return_zero' );
	wp_using_ext_object_cache( $cache_externa );
	$ahora = array_column( (array) $wpdb->get_results( 'SELECT * FROM ' . SociosTable::table(), ARRAY_A ), null, 'num_socio_asde' ); // phpcs:ignore WordPress.DB
	foreach ( array_diff_key( $ahora, $socios_antes ) as $asde => $f ) {
		$wpdb->delete( SociosTable::table(), array( 'num_socio_asde' => (string) $asde ) );
	}
	Grupo::forzar( null );
}

echo $fails ? "\n{$fails} FALLOS\n" : "\nTODO OK\n";
