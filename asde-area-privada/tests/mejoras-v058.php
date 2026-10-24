<?php
/**
 * Pruebas de la v0.58 (entorno LOCAL), peticiones del usuario del 2026-10-24:
 * - el Excel del control de pagos sin las familias que no tienen ningún asociado con membresía activa;
 * - la barra de Tesorería sin el enlace a «Todos los informes» y con el «Informe del banco»;
 * - el scouter ve el DNI de los socios de sus secciones en los informes;
 * - el informe (Excel) de los movimientos del banco;
 * - asociar movimientos del banco entre sí (un gasto y su devolución).
 * Ejecutar desde app/public del site Local:
 *
 *   wp eval-file wp-content/plugins/asde-area-privada/tests/mejoras-v058.php
 *
 * No usa el CRM (personas inventadas en memoria; los movimientos del banco se insertan con el grupo de la
 * prueba y se borran al acabar, por sus ids). El usuario de la prueba (prueba-v058-*) se borra al terminar.
 */
defined( 'ABSPATH' ) || exit;

require_once ABSPATH . 'wp-admin/includes/user.php';

use AsdeBalears\AreaPrivada\Domain\Grupo;
use AsdeBalears\AreaPrivada\Domain\Grupos;
use AsdeBalears\AreaPrivada\Domain\Modulos;
use AsdeBalears\AreaPrivada\Domain\Persona;
use AsdeBalears\AreaPrivada\Frontend\Tabla;
use AsdeBalears\AreaPrivada\Frontend\TemplateLoader;
use AsdeBalears\AreaPrivada\Frontend\Views\BancoView;
use AsdeBalears\AreaPrivada\Frontend\Views\TesoreriaView;
use AsdeBalears\AreaPrivada\Informes\Catalogo;
use AsdeBalears\AreaPrivada\Install\Roles;
use AsdeBalears\AreaPrivada\Tesoreria\Banco\BancoTable;
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

foreach ( get_users( array( 'search' => 'prueba-v058-*', 'search_columns' => array( 'user_login' ) ) ) as $u ) {
	exit( 'Ya existe el usuario ' . $u->user_login . " (¿una ejecución anterior interrumpida?). Bórralo antes de repetir la prueba.\n" );
}
$grupo = Grupos::por_numero( '684' );
if ( ! $grupo ) {
	exit( "Hace falta el grupo 684 en el Local.\n" );
}
Grupo::forzar( $grupo );
$ids_mov = array();
$uid     = 0;

try {
	echo "== A · Excel del control de pagos sin las familias sin asociados activos ==\n";
	$per      = static fn( string $n, string $nombre ) => Persona::from_crm( array( 'id' => wp_generate_uuid4(), 'fields' => array( 'numerosocio_c' => $n, 'first_name' => $nombre, 'last_name' => 'Prueba', 'stic_relationship_type_c' => '^Lobatos^' ) ) );
	$personas = array( 'p1' => $per( 'p1', 'Ana' ), 'p3' => $per( 'p3', 'Eva' ) );
	$calc     = static fn( array $pagan, array $no_pagan, array $fuera, float $a_pagar, float $pagado, string $estado ) => array(
		'pagan'     => $pagan,
		'no_pagan'  => $no_pagan,
		'fuera'     => $fuera,
		'cuota'     => array( 'total' => $a_pagar ),
		'ajustes'   => 0.0,
		'a_pagar'   => $a_pagar,
		'pagado'    => $pagado,
		'pendiente' => $a_pagar - $pagado,
		'estado'    => $estado,
	);
	$filas = array(
		1 => array( 'familia' => array( 'numero' => 'F1', 'miembros' => array( 'p1' ) ), 'calc' => $calc( array( 'p1' ), array(), array(), 140.0, 140.0, 'pagado' ) ),
		2 => array( 'familia' => array( 'numero' => 'F2', 'miembros' => array( 'p2' ) ), 'calc' => $calc( array(), array(), array( 'p2' ), 0.0, 0.0, 'exento' ) ),
		3 => array( 'familia' => array( 'numero' => 'F3', 'miembros' => array( 'p3' ) ), 'calc' => $calc( array(), array( 'p3' ), array(), 0.0, 0.0, 'exento' ) ),
	);
	list( $datos, $suma ) = TesoreriaView::datos_excel( $filas, $personas );
	$nums = array_column( $datos, 0 );
	$ok( array( 'F1', 'F3' ) === $nums, 'fuera la familia F2 (su único miembro ya no tiene membresía activa); F3 sigue (un scouter activo, aunque no pague)' );
	$ok( $igual( 140, $suma['a_pagar'] ) && $igual( 140, $suma['pagado'] ) && 1 === (int) $suma['hijos'], 'los totales solo suman las familias que salen' );

	echo "\n== B · Informes: el scouter ve el DNI (v0.58) ==\n";
	$cols = Catalogo::columnas();
	$ok( 'global' === $cols['dni'][4] && 'todos' === $cols['nacimiento'][4], 'el DNI sigue en su nivel («global»: no lo ven las familias) y la fecha de nacimiento, para todos' );

	echo "\n== C · Barra de Tesorería ==\n";
	$uid = (int) wp_insert_user(
		array(
			'user_login' => 'prueba-v058-coord',
			'user_email' => '',
			'user_pass'  => wp_generate_password( 24 ),
			'role'       => Roles::COORDINACION,
		)
	);
	update_user_meta( $uid, Usuarios::META_GRUPO, $grupo['id'] );
	update_user_meta( $uid, Usuarios::META_ESTADO, Usuarios::ACTIVO );
	wp_set_current_user( $uid );
	$subnav = new ReflectionMethod( BancoView::class, 'subnav' );
	$subnav->setAccessible( true );
	$html = (string) $subnav->invoke( new BancoView(), 'banco', '2026-2027' );
	$ok( ! str_contains( $html, 'Todos los informes' ), 'sin el enlace a «Todos los informes» (petición del usuario)' );
	if ( Modulos::activo( Modulos::BANCO ) ) {
		$ok( str_contains( $html, 'Informe del banco' ) && str_contains( $html, 'formato=xlsx' ) && str_contains( $html, 'data-asde-descarga' ), 'con «Informe del banco», que descarga el Excel (sin la transición de Enfold)' );
	} else {
		$ok( ! str_contains( $html, 'Informe del banco' ), 'sin el banco activo en el grupo, no sale «Informe del banco»' );
	}
	wp_set_current_user( 0 );

	echo "\n== D · Movimientos del banco asociados entre sí ==\n";
	$nuevo = static function ( string $fecha, float $importe, string $estado = BancoTable::PENDIENTE ) use ( $wpdb, &$ids_mov ): int {
		$wpdb->insert(
			BancoTable::table(),
			array(
				'grupo'   => Grupo::numero(),
				'lote_id' => 0,
				'cuenta'  => 'PRUEBA-V058',
				'fecha'   => $fecha,
				'importe' => $importe,
				'huella'  => hash( 'sha256', 'prueba-v058|' . wp_generate_uuid4() ),
				'estado'  => $estado,
				'nota'    => 'prueba-v058',
				'creado'  => current_time( 'mysql', true ),
			)
		);
		$ids_mov[] = (int) $wpdb->insert_id;
		return (int) $wpdb->insert_id;
	};
	$gasto  = $nuevo( '2099-03-01', -25.0, BancoTable::IGNORADO );
	$devol  = $nuevo( '2099-03-05', 25.0 );
	$otro   = $nuevo( '2099-03-02', 10.0 );
	$gasto2 = $nuevo( '2099-03-03', -10.0 );

	$cand = BancoTable::candidatos( BancoTable::obtener( $gasto ), 'prueba-v058' );
	$ok( $cand && (int) $cand[0]['id'] === $devol && $cand[0]['igual'], 'para asociar un gasto de 25 €, primero sale el ingreso de 25 € (signo contrario y mismo importe)' );
	$ok( ! in_array( $gasto, array_map( 'intval', array_column( $cand, 'id' ) ), true ), 'el propio movimiento no sale entre los candidatos' );

	$r = BancoTable::asociar( $gasto, $devol );
	$ok( true === $r && BancoTable::obtener( $gasto )['asociado_id'] === $devol && BancoTable::obtener( $devol )['asociado_id'] === $gasto, 'asociar: cada uno apunta al otro' );
	$ok( BancoTable::IGNORADO === BancoTable::obtener( $gasto )['estado'] && BancoTable::PENDIENTE === BancoTable::obtener( $devol )['estado'], 'asociarlos no cambia su estado' );
	$ok( is_wp_error( BancoTable::asociar( $gasto, $otro ) ) && null === BancoTable::obtener( $otro )['asociado_id'], 'uno ya asociado no se asocia con un tercero (y el tercero queda sin tocar)' );
	$ok( is_wp_error( BancoTable::asociar( $otro, $otro ) ) && is_wp_error( BancoTable::asociar( $otro, 999999999 ) ), 'ni consigo mismo ni con uno que no existe' );
	$cand2 = BancoTable::candidatos( BancoTable::obtener( $gasto2 ), 'prueba-v058' );
	$ok( array( $otro ) === array_map( 'intval', array_column( $cand2, 'id' ) ) && $cand2[0]['igual'], 'los ya asociados no salen como candidatos; el ingreso de 10 € sí, el primero para el gasto de 10 €' );

	// Si otra petición asocia uno de los dos justo a la vez, no queda ninguno a medias.
	$colarse = static function ( $sql ) use ( $wpdb, $otro, $gasto2, &$colado ) {
		if ( ! $colado && str_starts_with( $sql, 'UPDATE' ) && str_contains( $sql, 'SET asociado_id = ' . $gasto2 ) ) {
			$colado = true;
			$wpdb->query( $wpdb->prepare( 'UPDATE %i SET asociado_id = %d WHERE id = %d', BancoTable::table(), 1, $otro ) ); // phpcs:ignore WordPress.DB
		}
		return $sql;
	};
	$colado = false;
	add_filter( 'query', $colarse );
	$r2 = BancoTable::asociar( $gasto2, $otro );
	remove_filter( 'query', $colarse );
	$ok( $colado && is_wp_error( $r2 ) && null === BancoTable::obtener( $gasto2 )['asociado_id'], 'si otra petición asocia el otro movimiento entretanto, se deshace lo hecho: ninguno queda a medias' );
	$wpdb->query( $wpdb->prepare( 'UPDATE %i SET asociado_id = NULL WHERE id = %d', BancoTable::table(), $otro ) ); // phpcs:ignore WordPress.DB

	echo "\n== E · Informe del banco en Excel ==\n";
	$movs = BancoTable::movimientos( array( 'ids' => $ids_mov ) );
	list( $cab, $filas_x, $op ) = BancoView::informe_excel( $movs );
	$col_imp = array_search( 'Importe', $cab, true );
	$col_aso = array_search( 'Asociado con', $cab, true );
	$ok( 4 === count( $filas_x ) && '01/03/2099' === $filas_x[0][0] && '05/03/2099' === $filas_x[3][0], 'una fila por movimiento, de más antiguo a más nuevo' );
	$ok( false !== $col_imp && $igual( -25, $filas_x[0][ $col_imp ] ) && 'euros' === $op['tipos'][ $col_imp ], 'el importe es un número con formato de moneda' );
	$ok( false !== $col_aso && str_contains( $filas_x[0][ $col_aso ], '05/03/2099' ) && str_contains( $filas_x[3][ $col_aso ], '01/03/2099' ) && '' === $filas_x[1][ $col_aso ], 'la columna «Asociado con» dice la fecha y el importe del otro (vacía si no tiene)' );
	$ok( $igual( 0, $op['totales'][ $col_imp ] ) && str_contains( $op['subtitulo'], '35,00' ), 'totales: la suma de los importes (25 − 25 + 10 − 10 = 0) e ingresos y gastos en el subtítulo' );
	$f = BancoView::filtros_excel( array( 'estado' => 'inventado', 'tipo' => 'gastos', 'clase' => '12', 'q' => ' <b>hola</b> ' ) );
	$ok( '' === $f['estado'] && 'gastos' === $f['tipo'] && '12' === $f['clase'] && 'hola' === $f['q'], 'los filtros del Excel se sanean (un estado que no existe = todos)' );
	$ok( 4 === count( BancoTable::movimientos( array( 'q' => 'prueba-v058' ) ) ), 'sin estado, el informe trae todos los registros (pendientes y revisados)' );

	echo "\n== G · Pantallas del banco ==\n";
	$avisos = array();
	set_error_handler(
		static function ( $n, $msg, $file, $line ) use ( &$avisos ) {
			$avisos[] = basename( $file ) . ':' . $line . ' ' . $msg;
			return true;
		}
	);
	$tabla = new Tabla( array( 'fecha' => static fn( $m ) => $m['fecha'] ), 'fecha', 'desc' );
	$todos = BancoTable::movimientos( array( 'ids' => $ids_mov ) );
	foreach ( $todos as &$m ) {
		$m['sugerencia'] = null;
	}
	unset( $m );
	$h_lista = TemplateLoader::render(
		'tesoreria/banco',
		array(
			'movs'      => $todos,
			'total'     => count( $todos ),
			'sugeridas' => 0,
			'tabla'     => $tabla,
			'filtros'   => array( 'estado' => '', 'tipo' => '', 'q' => '', 'clase' => '' ),
			'familias'  => array( 'por_numero' => array(), 'etiquetas' => array() ),
			'resumen'   => array( 'pendientes' => 0, 'pendientes_importe' => 0.0, 'saldo' => null, 'saldo_fecha' => null ),
			'lotes'     => array(),
			'rondas'    => array( '2098-2099' ),
			'tipos'     => array(),
			'eventos'   => array(),
			'url'       => home_url( '/banco/' ),
			'url_fam'   => home_url( '/familias/' ),
			'asociados' => array_column( $todos, null, 'id' ),
			'url_excel' => home_url( '/banco/?formato=xlsx' ),
		)
	);
	$ok( str_contains( $h_lista, 'Asociado con' ) && str_contains( $h_lista, 'Quitar asociación' ) && str_contains( $h_lista, 'Asociar…' ) && str_contains( $h_lista, 'Descargar Excel' ), 'el listado enseña «Asociado con», «Quitar asociación», «Asociar…» y «Descargar Excel»' );
	$h_vent = TemplateLoader::render(
		'tesoreria/banco-asociar',
		array(
			'hecho'      => false,
			'mov'        => BancoTable::obtener( $gasto2 ),
			'candidatos' => BancoTable::candidatos( BancoTable::obtener( $gasto2 ), 'prueba-v058' ),
			'q'          => 'prueba-v058',
			'url'        => home_url( '/banco/' ),
			'volver'     => home_url( '/banco/' ),
		)
	);
	$ok( str_contains( $h_vent, 'data-asde-en-ventana' ) && str_contains( $h_vent, 'asde-banco-asociar__igual' ) && str_contains( $h_vent, 'value="asociar"' ), 'la ventana «Asociar…»: buscador que se queda en la ventana, el de mismo importe destacado y el botón Asociar' );
	$h_hecho = TemplateLoader::render( 'tesoreria/banco-asociar', array( 'hecho' => true, 'url' => home_url( '/banco/' ) ) );
	restore_error_handler();
	$ok( str_contains( $h_hecho, 'Movimientos asociados' ), 'tras asociar desde la ventana, el aviso para cerrarla' );
	$ok( ! $avisos, 'las plantillas se pintan sin avisos de PHP' . ( $avisos ? ': ' . implode( ' | ', array_slice( $avisos, 0, 3 ) ) : '' ) );

	echo "\n== F · Quitar la asociación ==\n";
	$ok( $devol === BancoTable::desasociar( $gasto ) && null === BancoTable::obtener( $gasto )['asociado_id'] && null === BancoTable::obtener( $devol )['asociado_id'], 'quitarla desde uno la quita en los dos' );
	$ok( 0 === BancoTable::desasociar( $gasto ), 'quitar la de uno sin asociar no hace nada' );
} catch ( Throwable $e ) {
	++$fails;
	echo 'FAIL excepcion: ', $e->getMessage(), ' (', basename( $e->getFile() ), ':', $e->getLine(), ")\n";
} finally {
	if ( isset( $colarse ) ) {
		remove_filter( 'query', $colarse );
	}
	restore_error_handler();
	wp_set_current_user( 0 );
	foreach ( $ids_mov as $id ) {
		$wpdb->delete( BancoTable::table(), array( 'id' => $id ) );
	}
	if ( $uid ) {
		wp_delete_user( $uid );
	}
	Grupo::forzar( null );
	$quedan = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE cuenta = %s', BancoTable::table(), 'PRUEBA-V058' ) ); // phpcs:ignore WordPress.DB
	$ok( 0 === $quedan && ! get_user_by( 'login', 'prueba-v058-coord' ), 'limpieza: sin movimientos ni usuarios de la prueba' );
}

echo $fails ? "\n$fails FALLOS\n" : "\nTODO OK (nada tocado en el CRM; el sitio queda como estaba)\n";
