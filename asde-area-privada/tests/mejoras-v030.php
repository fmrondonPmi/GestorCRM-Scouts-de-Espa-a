<?php
/**
 * Pruebas de la v0.30 (entorno LOCAL): el aviso del inventario al revés (P-62),
 * material nuevo en las listas de compra (P-63), el email a quien pidió la lista al
 * aprobarla o rechazarla (P-65), «Marcar como comprada» (P-66), los repetidos al importar
 * (P-67) y el scouter que pide uniforme para los de su sección (P-48). Ejecutar desde
 * app/public del site Local:
 *
 *   wp eval-file wp-content/plugins/asde-area-privada/tests/mejoras-v030.php
 *
 * Usa el CRM simulado de tests/lib (fichas inventadas: ninguna petición llega al CRM
 * real), guarda las cachés solo en la memoria de este proceso y no envía ningún email
 * (se capturan). Al terminar se borra lo creado (usuarios, material, tipos, listas,
 * pedidos, registros) y lo tocado queda como estaba.
 */
defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/lib/CrmSimulado.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Auth\Ambito;
use AsdeBalears\AreaPrivada\Crm\Escritura;
use AsdeBalears\AreaPrivada\Domain\Grupo;
use AsdeBalears\AreaPrivada\Domain\Grupos;
use AsdeBalears\AreaPrivada\Frontend\Notices;
use AsdeBalears\AreaPrivada\Frontend\Views\InventarioComprasView;
use AsdeBalears\AreaPrivada\Frontend\Views\InventarioView;
use AsdeBalears\AreaPrivada\Frontend\Views\TiendaPedidosView;
use AsdeBalears\AreaPrivada\Frontend\Views\View;
use AsdeBalears\AreaPrivada\Idiomas\TextosJs;
use AsdeBalears\AreaPrivada\Install\Roles;
use AsdeBalears\AreaPrivada\Inventario\AvisoRevision;
use AsdeBalears\AreaPrivada\Inventario\ComprasTable;
use AsdeBalears\AreaPrivada\Inventario\Importador;
use AsdeBalears\AreaPrivada\Inventario\ListaPdf;
use AsdeBalears\AreaPrivada\Inventario\MaterialesTable;
use AsdeBalears\AreaPrivada\Inventario\Servicio as Inventario;
use AsdeBalears\AreaPrivada\Inventario\TiposTable;
use AsdeBalears\AreaPrivada\Socios\SociosTable;
use AsdeBalears\AreaPrivada\Tienda\Config;
use AsdeBalears\AreaPrivada\Tienda\PedidosTable;
use AsdeBalears\AreaPrivada\Tienda\Servicio as Uniformes;
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
$plugin = dirname( __DIR__ );
$leer   = static fn( string $f ): string => (string) file_get_contents( $plugin . '/' . $f );

// ---------------------------------------------------------------------------
// Preparación: grupo, CRM simulado y cuentas.
// ---------------------------------------------------------------------------
$grupo = Grupos::por_numero( '684' ) ?? ( array_values( Grupos::todos() )[0] ?? null );
if ( ! $grupo || '' === $grupo['crm_id'] ) {
	exit( "Hace falta un grupo con la conexión al CRM comprobada.\n" );
}
foreach ( get_users( array( 'search' => 'prueba-v030-*', 'search_columns' => array( 'user_login' ) ) ) as $u ) {
	exit( 'Ya existe el usuario ' . $u->user_login . " (¿una ejecución anterior interrumpida?). Bórralo antes de repetir la prueba.\n" );
}
wp_set_current_user( 0 );

$max_id       = static fn( string $t ) => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(MAX(id), 0) FROM %i', $t ) );
$log_antes    = $max_id( Logger::table() );
$mat_antes    = $max_id( MaterialesTable::table() );
$tip_antes    = $max_id( TiposTable::table() );
$com_antes    = $max_id( ComprasTable::table() );
$ema_antes    = $max_id( RegistroEmails::table() );
$ped_antes    = $max_id( PedidosTable::table() );
$mat_cant     = array_column( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT id, cantidad FROM %i', MaterialesTable::table() ), ARRAY_A ), 'cantidad', 'id' );
$tip_especial = (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, especial FROM %i WHERE especial <> ''", TiposTable::table() ), ARRAY_A );
$socios_antes = array_column( (array) $wpdb->get_results( 'SELECT * FROM ' . SociosTable::table(), ARRAY_A ), null, 'num_socio_asde' ); // phpcs:ignore WordPress.DB
$cfg_antes    = get_option( 'asde_ap_uniformes_' . $grupo['numero'], null );

$cache_externa = wp_using_ext_object_cache( true );
$correos       = array();
$capturar      = static function ( $pre, $atts ) use ( &$correos ) {
	$correos[] = $atts;
	return true; // No sale ningún email.
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
		$P( 81, 'Sara', 'Treinta Mir', '1990-01-01', 'Scouters' ),
		$P( 82, 'Abel', 'Treinta Abad', '2016-01-01', 'Lobatos' ),
		$P( 83, 'Bea', 'Treinta Bauzà', '2016-02-02', 'Lobatos' ),
		$P( 84, 'Cai', 'Treinta Coll', '2016-03-03', 'Lobatos' ),
		$P( 85, 'Dani', 'Treinta Duran', '2016-04-04', 'Lobatos' ),
		$P( 86, 'Eli', 'Treinta Estela', '2016-05-05', 'Lobatos' ),
		$P( 87, 'Fede', 'Treinta Font', '2016-06-06', 'Lobatos' ),
		$P( 88, 'Gala', 'Treinta Gili', '2016-07-07', 'Lobatos' ),
		$P( 89, 'Hugo', 'Treinta Homar', '2012-08-08', 'Scouts' ),
		$P( 90, 'Joan', 'Treinta Jaume', '1985-09-09', 'Scouters' ),
		$P( 91, 'Kim', 'Treinta Ksar', '1983-10-10', 'Scouters' ),
		$P( 92, 'Lia', 'Treinta Llull', '1988-11-11', 'Scouters' ),
		$P( 93, 'Max', 'Treinta Mas', '2016-12-12', '' ), // Sin membresía activa: no sale en ninguna sección.
	),
	$grupo['crm_id']
);
Grupo::forzar( $grupo );
$crm->activar();
Escritura::usar_para_pruebas( $crm ); // Además, sin instantánea de fichas: cada lectura del grupo va al CRM simulado.
Config::guardar( Config::defaults() ); // Modelos, tallas y la primera camisa de scouter gratis, durante la prueba.

$ids   = array();
$crear = static function ( string $login, array $roles, array $meta, string $email = '' ) use ( &$ids, $grupo ): int {
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
	if ( '' !== $email ) {
		EmailCuenta::guardar( $id, $email );
	}
	$ids[ $login ] = $id;
	return $id;
};
// Scouter de Lobatos y del Kraal (Scouters), con email: pide listas y uniformes para su sección.
$u_scout = $crear( 'prueba-v030-scout', array( Roles::SCOUTER ), array( Usuarios::META_SOCIO => '990081', Usuarios::META_SECCION => array( 'lobatos', 'scouters' ) ), 'scout-v030@example.invalid' );
$u_teso  = $crear( 'prueba-v030-teso', array( Roles::SCOUTER, Roles::TESORERIA ), array( Usuarios::META_SOCIO => '990091', Usuarios::META_SECCION => 'rovers' ), 'teso-v030@example.invalid' );
$u_secre = $crear( 'prueba-v030-secre', array( Roles::SECRETARIA ), array( Usuarios::META_SOCIO => '990092' ) ); // Sin email.
$u_fam   = $crear( 'prueba-v030-fam', array( Roles::TUTOR ), array( Usuarios::META_REPRESENTADO => '990082' ) );
$u_sc2   = $crear( 'prueba-v030-scouts', array( Roles::SCOUTER ), array( Usuarios::META_SOCIO => '990090', Usuarios::META_SECCION => 'scouts' ) );

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
$morir = static fn() => static function ( $mensaje ) {
	throw new RuntimeException( 'asde-die:' . ( is_wp_error( $mensaje ) ? $mensaje->get_error_message() : (string) $mensaje ) );
};
add_filter( 'wp_die_handler', $morir, PHP_INT_MAX );
/** Envía un formulario a una vista como lo haría el navegador y devuelve los avisos. */
$enviar = static function ( $vista, array $campos, string $nonce ): string {
	$_POST    = $campos + array( '_wpnonce' => wp_create_nonce( $nonce ) );
	$_REQUEST = $_POST;
	try {
		$vista->handle_post();
	} catch ( RuntimeException $e ) {
		// Redirige al terminar.
	} finally {
		$_POST    = array();
		$_REQUEST = array();
	}
	return Notices::render();
};
$ver = static function ( $vista, array $get = array() ): string {
	$_GET = $get;
	try {
		return $vista->render();
	} finally {
		$_GET = array();
	}
};
$material = static function ( string $descripcion, int $cantidad, int $tipo = 0, ?float $precio = null ): int {
	return MaterialesTable::crear(
		array(
			'descripcion' => $descripcion,
			'tipo'        => $tipo,
			'cantidad'    => $cantidad,
			'precio'      => $precio,
			'anio'        => null,
			'num_serie'   => '',
			'notas'       => '',
			'modelo'      => '',
			'talla'       => '',
		)
	);
};
$ultima_lista = static fn() => ComprasTable::obtener( $max_id( ComprasTable::table() ) );
$registro     = static fn( string $tipo ) => (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE id > %d AND tipo = %s ORDER BY id', RegistroEmails::table(), $ema_antes, $tipo ), ARRAY_A ); // phpcs:ignore WordPress.DB

try {
	// -----------------------------------------------------------------------
	echo "\n== A. P-62 · El aviso del inventario, al revés ==\n";
	$como( $u_scout );
	$t_prueba = TiposTable::crear( 'Prueba v030 tipo' );
	$m_muchas = $material( 'Prueba v030 tienda', 6, $t_prueba, 100.0 );
	$m_pocas  = $material( 'Prueba v030 farol', 2, $t_prueba, 8.5 );
	$ok( Inventario::ya_hay_bastantes( 5 ) && Inventario::ya_hay_bastantes( 6 ) && ! Inventario::ya_hay_bastantes( 4 ) && Inventario::pocas_unidades( 4 ) && ! Inventario::pocas_unidades( 5 ), 'con 5 o más unidades se pregunta; por debajo de 5, la etiqueta de «pocas unidades» (como antes)' );
	$cero = static fn() => 0;
	add_filter( 'asde_ap_inventario_umbral', $cero );
	$ok( ! Inventario::ya_hay_bastantes( 100 ), 'con el umbral a 0 no se pregunta nunca' );
	remove_filter( 'asde_ap_inventario_umbral', $cero );
	$h = $ver( new InventarioView() );
	$ok( str_contains( $h, 'Si de alguno ya hay 5 unidades o más, te preguntaremos si de verdad hace falta comprar más' ) && ! str_contains( $h, 'hay menos de 5 unidades, te lo preguntaremos' ), 'el texto de ayuda del inventario lo explica al revés que antes' );
	$ok( 1 === preg_match( '/data-cantidad="2" data-nombre="Prueba v030 farol"/', $h ) && 1 === preg_match( '/asde-badge--inv-pocas[^>]*>2</', $h ), 'la etiqueta amarilla de pocas unidades se queda' );
	$js = $leer( 'assets/js/inventario.js' );
	$ok( str_contains( $js, 'cantidad >= umbral' ) && str_contains( $js, 'umbral > 0' ) && ! str_contains( $js, 'cantidad < umbral' ) && str_contains( $js, '¿Seguro que hace falta comprar más?' ), 'inventario.js pregunta cuando ya hay bastantes (y no cuando hay pocas)' );
	$ok( in_array( 'Ya hay %s unidades de «%s» en el inventario. ¿Seguro que hace falta comprar más?', TextosJs::LISTA, true ) && ! in_array( 'En el inventario solo hay %s unidades de «%s». ¿Seguro que quieres añadirlo a la lista de compra?', TextosJs::LISTA, true ), 'el texto nuevo está en los del JavaScript (y el viejo ya no)' );
	$h = $ver( new InventarioComprasView(), array( 'nueva' => '1', 'm' => array( (string) $m_muchas, (string) $m_pocas ) ) );
	$ok( str_contains( $h, 'Ojo: ya hay 6 unidades en el inventario. ¿Seguro que hace falta comprar más?' ) && 1 === substr_count( $h, 'asde-inv-aviso' ), 'sin JavaScript, el aviso sale en la lista nueva junto al material del que hay 6 (y no junto al que hay 2)' );

	// -----------------------------------------------------------------------
	echo "\n== B. P-63 · Material nuevo en la lista de compra ==\n";
	$l = Inventario::lineas(
		array( $m_pocas => '3' ),
		array( $m_pocas => '8,50' ),
		array(
			array( 'descripcion' => 'Prueba v030 brújula', 'cantidad' => '4', 'precio' => '12,25' ),
			array( 'descripcion' => '', 'cantidad' => '1', 'precio' => '' ), // En blanco: se salta.
			array( 'descripcion' => 'Prueba v030 mapa', 'cantidad' => '2', 'precio' => '' ),
		)
	);
	$ok( is_array( $l ) && 3 === count( $l ) && 0 === $l[1]['material'] && 'Prueba v030 brújula' === $l[1]['descripcion'] && 4 === $l[1]['cantidad'] && 12.25 === $l[1]['precio'] && null === $l[2]['precio'] && 0 === $l[2]['en_inventario'], 'las líneas de material nuevo: descripción, cantidad y precio estimado opcional (la fila en blanco no cuenta)' );
	$e = Inventario::lineas( array(), array(), array( array( 'descripcion' => '', 'cantidad' => '1', 'precio' => '5' ) ) );
	$ok( is_wp_error( $e ) && str_contains( $e->get_error_message(), 'línea 1' ), 'un precio sin descripción: error, con el nº de la línea' );
	$e = Inventario::lineas( array(), array(), array( array( 'descripcion' => 'Prueba v030 X', 'cantidad' => 'dos', 'precio' => '' ) ) );
	$e2 = Inventario::lineas( array(), array(), array( array( 'descripcion' => 'Prueba v030 X', 'cantidad' => '1', 'precio' => 'doce' ) ) );
	$ok( is_wp_error( $e ) && str_contains( $e->get_error_message(), 'cantidad' ) && is_wp_error( $e2 ) && str_contains( $e2->get_error_message(), 'precio' ), 'cantidad y precio del material nuevo se validan como los del inventario' );
	$ok( is_wp_error( Inventario::lineas( array(), array(), array( array( 'descripcion' => '', 'cantidad' => '1', 'precio' => '' ) ) ) ), 'una lista solo con filas en blanco sigue estando vacía' );

	$h = $ver( new InventarioComprasView(), array( 'nueva' => '1' ) );
	$ok( str_contains( $h, 'No has marcado ningún material del inventario' ) && str_contains( $h, 'name="nuevo_desc[0]"' ) && str_contains( $h, 'name="nuevo_cantidad[0]"' ) && str_contains( $h, 'name="nuevo_precio[0]"' ) && str_contains( $h, 'name="mas_lineas"' ) && str_contains( $h, 'Guardar y enviar a Tesorería' ), 'sin nada marcado, el formulario sale igual, con una fila de material nuevo y «Añadir otra línea»' );
	$ok( str_contains( $ver( new InventarioComprasView() ), 'nueva=1' ) && str_contains( $ver( new InventarioComprasView() ), 'Lista de material nuevo' ), 'el listado tiene «Lista de material nuevo»' );

	// Sin JavaScript, «Añadir otra línea» vuelve al formulario con lo escrito y una fila más.
	$antes = $max_id( ComprasTable::table() );
	$enviar(
		new InventarioComprasView(),
		array(
			'asde_action'    => 'compra_crear',
			'mas_lineas'     => '1',
			'cantidad'       => array( $m_pocas => '2' ),
			'nuevo_desc'     => array( 'Prueba v030 brújula' ),
			'nuevo_cantidad' => array( '4' ),
			'nuevo_precio'   => array( '12,25' ),
		),
		'asde_inventario_compra_nueva'
	);
	$h = $ver( new InventarioComprasView(), array( 'nueva' => '1' ) );
	$ok( $antes === $max_id( ComprasTable::table() ) && str_contains( $h, 'value="Prueba v030 brújula"' ) && str_contains( $h, 'name="nuevo_desc[1]"' ) && ! str_contains( $h, 'name="nuevo_desc[2]"' ) && str_contains( $h, 'name="cantidad[' . $m_pocas . ']" value="2"' ), '«Añadir otra línea» sin JavaScript: no crea la lista; vuelve con lo escrito y una fila más' );
	$ok( str_contains( $leer( 'assets/js/inventario.js' ), 'data-asde-inv-otra' ) && str_contains( $leer( 'assets/js/inventario.js' ), 'cloneNode' ), 'con JavaScript, la fila se añade sin recargar' );

	$correos = array();
	$h       = $enviar(
		new InventarioComprasView(),
		array(
			'asde_action'    => 'compra_crear',
			'cantidad'       => array( $m_muchas => '2' ),
			'precio'         => array( $m_muchas => '100' ),
			'nuevo_desc'     => array( 'Prueba v030 brújula', '' ),
			'nuevo_cantidad' => array( '3', '1' ),
			'nuevo_precio'   => array( '12,25', '' ),
			'nota'           => 'Para la acampada',
		),
		'asde_inventario_compra_nueva'
	);
	$l1 = $ultima_lista();
	$ok( $l1 && $l1['id'] > $com_antes && 2 === count( $l1['lineas'] ) && $m_muchas === $l1['lineas'][0]['material'] && 0 === $l1['lineas'][1]['material'] && 3 === $l1['lineas'][1]['cantidad'] && 236.75 === $l1['total'], 'la lista se crea con el material del inventario y el nuevo (2 × 100 + 3 × 12,25 = 236,75 €)' );
	$a_teso = array_values( array_filter( $correos, static fn( $c ) => 'teso-v030@example.invalid' === $c['to'] ) );
	$ok( 1 === count( $a_teso ) && str_contains( (string) $a_teso[0]['message'], 'Prueba v030 brújula' ) && str_contains( (string) $a_teso[0]['message'], 'Material nuevo (no está en el inventario)' ), 'el email a Tesorería lleva el material nuevo, marcado como nuevo' );
	$d = $ver( new InventarioComprasView(), array( 'lista' => (string) $l1['id'] ) );
	$ok( str_contains( $d, 'Prueba v030 brújula' ) && str_contains( $d, 'asde-badge--inv-nuevo' ), 'en la lista se ve, con la etiqueta «Material nuevo»' );
	$pdf = ListaPdf::generar( $l1 );
	$ok( str_starts_with( $pdf, '%PDF-1.4' ) && str_contains( $pdf, '(Material nuevo)' ) && str_contains( $pdf, '(Prueba v030 br' ), 'y en su PDF' );

	$enviar(
		new InventarioComprasView(),
		array(
			'asde_action'    => 'compra_crear',
			'nuevo_desc'     => array( 'Prueba v030 mapa' ),
			'nuevo_cantidad' => array( '1' ),
			'nuevo_precio'   => array( '' ),
		),
		'asde_inventario_compra_nueva'
	);
	$l_solo = $ultima_lista();
	$ok( $l_solo && $l_solo['id'] > $l1['id'] && 1 === count( $l_solo['lineas'] ) && 0 === $l_solo['lineas'][0]['material'] && ComprasTable::sin_precio( $l_solo ), 'una lista solo de material nuevo (sin precio)' );

	// -----------------------------------------------------------------------
	echo "\n== C. P-65 · Email a quien pidió la lista al aprobarla o rechazarla ==\n";
	$como( $u_teso );
	$correos = array();
	$h       = $enviar( new InventarioComprasView(), array( 'asde_action' => 'compra_aprobar', 'lista' => (string) $l1['id'] ), 'asde_inventario_compra_' . $l1['id'] );
	$ok( ComprasTable::APROBADA === ComprasTable::obtener( $l1['id'] )['estado'] && 1 === count( $correos ) && 'scout-v030@example.invalid' === $correos[0]['to'], 'al aprobarla, un email a quien la pidió' );
	$cuerpo = (string) ( $correos[0]['message'] ?? '' );
	$ok( str_contains( (string) $correos[0]['subject'], ComprasTable::numero( $l1 ) ) && str_contains( (string) $correos[0]['subject'], 'visto bueno' ) && str_contains( $cuerpo, 'visto bueno' ) && str_contains( $cuerpo, 'lista=' . $l1['id'] ) && str_contains( $cuerpo, 'Hola, Sara:' ) && str_contains( $cuerpo, 'márcala como comprada' ), 'el email dice el estado, saluda por su nombre y lleva el enlace a la lista' );
	$ok( str_contains( $h, 'Se ha avisado por email a quien la pidió' ), 'Tesorería ve que se le ha avisado' );
	$reg = $registro( RegistroEmails::COMPRA_REVISADA );
	$ok( 1 === count( $reg ) && RegistroEmails::ENVIADO === $reg[0]['estado'] && (int) $reg[0]['user_id'] === $u_scout && ! str_contains( (string) $reg[0]['destino'], 'scout-v030@' ) && str_contains( RegistroEmails::vista_previa( $reg[0] ), ComprasTable::numero( $l1 ) ) && 'Lista de compra revisada' === RegistroEmails::TIPOS[ RegistroEmails::COMPRA_REVISADA ], 'queda en el registro de emails (destino enmascarado) y se puede ver cómo era' );

	$como( $u_scout );
	$enviar( new InventarioComprasView(), array( 'asde_action' => 'compra_crear', 'cantidad' => array( $m_pocas => '1' ) ), 'asde_inventario_compra_nueva' );
	$l_rech = $ultima_lista();
	$como( $u_teso );
	$correos = array();
	$h       = $enviar( new InventarioComprasView(), array( 'asde_action' => 'compra_rechazar', 'lista' => (string) $l_rech['id'], 'motivo' => 'Ya hay faroles en el local' ), 'asde_inventario_compra_' . $l_rech['id'] );
	$cuerpo  = (string) ( $correos[0]['message'] ?? '' );
	$ok( ComprasTable::RECHAZADA === ComprasTable::obtener( $l_rech['id'] )['estado'] && 1 === count( $correos ) && str_contains( (string) $correos[0]['subject'], 'rechazado' ) && str_contains( $cuerpo, 'Ya hay faroles en el local' ) && str_contains( $cuerpo, 'no ha dado el visto bueno' ), 'al rechazarla, el email lleva el motivo' );
	$vp = RegistroEmails::vista_previa( $registro( RegistroEmails::COMPRA_REVISADA )[1] ?? array( 'tipo' => '', 'datos' => '', 'user_id' => 0, 'login' => '' ) );
	$ok( str_contains( $vp, 'Ya hay faroles en el local' ), 'y la vista previa del registro, también' );

	$como( $u_secre );
	$enviar( new InventarioComprasView(), array( 'asde_action' => 'compra_crear', 'cantidad' => array( $m_pocas => '1' ) ), 'asde_inventario_compra_nueva' );
	$l_sin = $ultima_lista();
	$como( $u_teso );
	$correos = array();
	$h       = $enviar( new InventarioComprasView(), array( 'asde_action' => 'compra_rechazar', 'lista' => (string) $l_sin['id'], 'motivo' => 'Duplicada' ), 'asde_inventario_compra_' . $l_sin['id'] );
	$sin     = array_values( array_filter( $registro( RegistroEmails::COMPRA_REVISADA ), static fn( $r ) => (int) $r['user_id'] === $u_secre ) );
	$ok( ! $correos && 1 === count( $sin ) && RegistroEmails::SIN_EMAIL === $sin[0]['estado'] && str_contains( $h, 'no tiene email' ), 'quien la pidió no tiene email: no se envía, queda anotado «sin email» y Tesorería lo sabe' );

	$enviar( new InventarioComprasView(), array( 'asde_action' => 'compra_crear', 'nuevo_desc' => array( 'Prueba v030 silbato' ), 'nuevo_cantidad' => array( '2' ) ), 'asde_inventario_compra_nueva' );
	$l_propia = $ultima_lista();
	$antes    = count( $registro( RegistroEmails::COMPRA_REVISADA ) );
	$correos  = array();
	$enviar( new InventarioComprasView(), array( 'asde_action' => 'compra_aprobar', 'lista' => (string) $l_propia['id'] ), 'asde_inventario_compra_' . $l_propia['id'] );
	$ok( ComprasTable::APROBADA === ComprasTable::obtener( $l_propia['id'] )['estado'] && ! $correos && count( $registro( RegistroEmails::COMPRA_REVISADA ) ) === $antes && '' === AvisoRevision::enviar( ComprasTable::obtener( $l_propia['id'] ) ), 'Tesorería que aprueba su propia lista no se manda un email a sí misma' );

	// -----------------------------------------------------------------------
	echo "\n== D. P-66 · Marcar como comprada ==\n";
	$como( $u_scout );
	$d = $ver( new InventarioComprasView(), array( 'lista' => (string) $l1['id'] ) );
	$ok( str_contains( $d, 'value="compra_comprada"' ) && str_contains( $d, 'Marcar como comprada' ) && 1 === preg_match( '/data-asde-confirmar="[^"]*Marcar la lista LC\d+ como comprada\?[^"]*"/', $d ), 'una lista aprobada tiene «Marcar como comprada», con confirmación' );
	$p_pend = $ver( new InventarioComprasView(), array( 'lista' => (string) $l_solo['id'] ) );
	$ok( ! str_contains( $p_pend, 'value="compra_comprada"' ) && ! Inventario::puede_marcar_comprada( $l_solo ), 'una pendiente, no' );
	$enviar( new InventarioComprasView(), array( 'asde_action' => 'compra_comprada', 'lista' => (string) $l_solo['id'] ), 'asde_inventario_compra_' . $l_solo['id'] );
	$enviar( new InventarioComprasView(), array( 'asde_action' => 'compra_comprada', 'lista' => (string) $l_rech['id'] ), 'asde_inventario_compra_' . $l_rech['id'] );
	$ok( ComprasTable::PENDIENTE === ComprasTable::obtener( $l_solo['id'] )['estado'] && ComprasTable::RECHAZADA === ComprasTable::obtener( $l_rech['id'] )['estado'], 'ni enviándolo a mano se marca una pendiente o una rechazada' );

	$n_mat = count( MaterialesTable::todos( true ) );
	$h     = $enviar( new InventarioComprasView(), array( 'asde_action' => 'compra_comprada', 'lista' => (string) $l1['id'] ), 'asde_inventario_compra_' . $l1['id'] );
	$c1    = ComprasTable::obtener( $l1['id'] );
	$ok( ComprasTable::COMPRADA === $c1['estado'] && $u_scout === $c1['comprada_por'] && null !== $c1['comprada_en'] && str_contains( $h, 'marcada como comprada' ), 'la lista queda comprada (quién y cuándo)' );
	$ok( 8 === MaterialesTable::obtener( $m_muchas )['cantidad'], 'lo comprado se suma al material del inventario (6 + 2 = 8)' );
	$nuevo = MaterialesTable::obtener( $c1['lineas'][1]['alta'] );
	$ok( $n_mat + 1 === count( MaterialesTable::todos( true ) ) && $nuevo && 'Prueba v030 brújula' === $nuevo['descripcion'] && 3 === $nuevo['cantidad'] && 12.25 === $nuevo['precio'] && (int) wp_date( 'Y' ) === $nuevo['anio'] && str_contains( $nuevo['notas'], ComprasTable::numero( $c1 ) ), 'el material nuevo se da de alta con su cantidad, su precio, el año y la lista en las notas' );
	$ok( str_contains( $h, 'Se ha sumado lo comprado a 1 material' ) && str_contains( $h, 'Se ha dado de alta 1 material nuevo' ), 'el aviso dice qué se ha sumado y qué se ha dado de alta' );
	$log = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE accion = %s AND objeto_id = %s ORDER BY id DESC LIMIT 1', Logger::table(), 'inventario_compra_comprada', (string) $l1['id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB
	$det = json_decode( (string) ( $log['detalles'] ?? '' ), true );
	$ok( $log && array( $m_muchas ) === $det['sumados'] && array( $nuevo['id'] ) === $det['creados'] && ! str_contains( (string) $log['detalles'], 'brújula' ) && ! str_contains( (string) $log['detalles'], 'brújula' ), 'queda en la auditoría con los ids (sin descripciones ni datos personales)' );
	$h = $enviar( new InventarioComprasView(), array( 'asde_action' => 'compra_comprada', 'lista' => (string) $l1['id'] ), 'asde_inventario_compra_' . $l1['id'] );
	$ok( 8 === MaterialesTable::obtener( $m_muchas )['cantidad'] && $n_mat + 1 === count( MaterialesTable::todos( true ) ) && str_contains( $h, 'no se puede marcar como comprada' ), 'no se puede marcar dos veces (no vuelve a sumar)' );
	$ok( is_wp_error( Inventario::comprar( array( 'estado' => ComprasTable::APROBADA ) + $l1 ) ) && 8 === MaterialesTable::obtener( $m_muchas )['cantidad'], 'ni llamando al servicio con la lista como estaba antes, aprobada (el cambio de estado es atómico)' );
	$d = $ver( new InventarioComprasView(), array( 'lista' => (string) $l1['id'] ) );
	$ok( Inventario::puede_imprimir( $c1 ) && ! Inventario::puede_cancelar( $c1 ) && ! Inventario::puede_deshacer( $c1 ) && str_contains( $d, 'formato=pdf' ) && str_contains( $d, 'Dado de alta en el inventario' ) && str_contains( $d, 'material=' . $nuevo['id'] ) && ! str_contains( $d, 'value="compra_comprada"' ), 'comprada: se sigue pudiendo imprimir, no se cancela ni se deshace, y enlaza el material dado de alta' );
	$ok( str_contains( ListaPdf::generar( $c1 ), 'Lo comprado se sum' ), 'el PDF de una lista comprada lo dice' );
	$h = $ver( new InventarioComprasView(), array( 'estado' => ComprasTable::COMPRADA ) );
	$ok( str_contains( $h, ComprasTable::numero( $c1 ) ) && str_contains( $h, 'asde-badge--compra-comprada' ) && ! str_contains( $h, ComprasTable::numero( $l_solo ) ), 'el listado filtra las compradas' );

	// Un material que se dio de baja mientras tanto: no se le suma; se da de alta uno nuevo.
	$m_baja = $material( 'Prueba v030 olla', 3, $t_prueba, 20.0 );
	$enviar( new InventarioComprasView(), array( 'asde_action' => 'compra_crear', 'cantidad' => array( $m_baja => '2' ) ), 'asde_inventario_compra_nueva' );
	$l_baja = $ultima_lista();
	$como( $u_teso );
	$enviar( new InventarioComprasView(), array( 'asde_action' => 'compra_aprobar', 'lista' => (string) $l_baja['id'] ), 'asde_inventario_compra_' . $l_baja['id'] );
	MaterialesTable::dar_baja( $m_baja, 'Se quemó' );
	$r = Inventario::comprar( ComprasTable::obtener( $l_baja['id'] ) );
	$alta = is_array( $r ) ? MaterialesTable::obtener( (int) ( $r['creados'][0] ?? 0 ) ) : null;
	$ok( is_array( $r ) && ! $r['sumados'] && 1 === count( $r['creados'] ) && 3 === MaterialesTable::obtener( $m_baja )['cantidad'] && $alta && 2 === $alta['cantidad'] && $t_prueba === $alta['tipo'] && 'Prueba v030 olla' === $alta['descripcion'], 'si el material se dio de baja, lo comprado se da de alta como material nuevo (con su tipo)' );

	// -----------------------------------------------------------------------
	echo "\n== E. P-67 · Importar: los repetidos suman ==\n";
	$como( $u_scout );
	$t_otro  = TiposTable::crear( 'Prueba v030 otro' );
	$camisa  = TiposTable::camisa();
	$m_cuer  = $material( 'Prueba v030 cuerda', 4, $t_prueba );
	$m_cam   = MaterialesTable::crear( MaterialesTable::validar( array( 'descripcion' => 'Prueba v030 camisa', 'tipo' => (string) $camisa, 'cantidad' => '2', 'modelo' => 'chica', 'talla' => 'M' ) ) );
	$m_tope  = $material( 'Prueba v030 piqueta', MaterialesTable::MAX_CANTIDAD - 1, $t_prueba );
	$m_baja2 = $material( 'Prueba v030 mochila', 1, $t_prueba );
	MaterialesTable::dar_baja( $m_baja2, '' );
	$filas = array(
		2  => array( 'descripcion' => '  prueba V030   CUERDA ', 'tipo' => 'Prueba v030 tipo', 'cantidad' => '3' ),          // Suma (mayúsculas y espacios).
		3  => array( 'descripcion' => 'Prueba v030 cuérda', 'tipo' => 'prueba v030 TIPO', 'cantidad' => '1' ),               // Suma (acento).
		4  => array( 'descripcion' => 'Prueba v030 cuerda', 'tipo' => 'Prueba v030 otro', 'cantidad' => '2' ),               // Otro tipo: nuevo.
		5  => array( 'descripcion' => 'Prueba v030 cuerda', 'tipo' => 'Prueba v030 otro', 'cantidad' => '5' ),               // Suma a la fila 4.
		6  => array( 'descripcion' => 'Prueba v030 cuerda', 'tipo' => 'Prueba v030 tipo', 'cantidad' => '1', 'num_serie' => 'V030-S1' ), // Con nº de serie: nuevo.
		7  => array( 'descripcion' => 'Prueba v030 linterna', 'tipo' => '', 'cantidad' => '2' ),                            // Nuevo (sin tipo).
		8  => array( 'descripcion' => 'Prueba v030 LINTERNA', 'tipo' => '', 'cantidad' => '1' ),                            // Suma a la fila 7.
		9  => array( 'descripcion' => 'Prueba v030 camisa', 'tipo' => TiposTable::NOMBRE_CAMISA, 'cantidad' => '3', 'modelo' => 'chica', 'talla' => 'm' ), // Misma camisa (modelo y talla): suma.
		10 => array( 'descripcion' => 'Prueba v030 camisa', 'tipo' => TiposTable::NOMBRE_CAMISA, 'cantidad' => '1', 'modelo' => 'chica', 'talla' => 'L' ), // Otra talla: nueva.
		11 => array( 'descripcion' => 'Prueba v030 camisa', 'tipo' => TiposTable::NOMBRE_CAMISA, 'cantidad' => '1', 'modelo' => 'chico', 'talla' => 'M' ), // Otro modelo: nueva.
		12 => array( 'descripcion' => 'Prueba v030 piqueta', 'tipo' => 'Prueba v030 tipo', 'cantidad' => '5' ),             // Pasaría del máximo: error.
		13 => array( 'descripcion' => 'Prueba v030 mochila', 'tipo' => 'Prueba v030 tipo', 'cantidad' => '2' ),             // La que hay está de baja: nueva.
	);
	$res     = Importador::importar( $filas, false );
	$motivos = array_column( $res['errores'], 'motivo', 'fila' );
	$ok( 5 === $res['sumados'] && 6 === $res['importados'] && array( 12 ) === array_keys( $motivos ), 'de 12 filas: 5 suman a un material que ya estaba, 6 son materiales nuevos y 1 no entra' . " (sumados {$res['sumados']}, importados {$res['importados']})" );
	$ok( 8 === MaterialesTable::obtener( $m_cuer )['cantidad'], 'sin distinguir mayúsculas, acentos ni espacios de más: 4 + 3 + 1 = 8' );
	$otros = array_values( array_filter( MaterialesTable::todos(), static fn( $m ) => 'Prueba v030 cuerda' === $m['descripcion'] && $t_otro === $m['tipo'] ) );
	$ok( 1 === count( $otros ) && 7 === $otros[0]['cantidad'], 'el mismo material con otro tipo es otro; y una fila del fichero suma a otra fila anterior (2 + 5 = 7)' );
	$serie = array_values( array_filter( MaterialesTable::todos(), static fn( $m ) => 'V030-S1' === $m['num_serie'] ) );
	$linte = array_values( array_filter( MaterialesTable::todos(), static fn( $m ) => 'prueba v030 linterna' === mb_strtolower( $m['descripcion'] ) ) );
	$ok( 1 === count( $serie ) && 1 === $serie[0]['cantidad'] && 1 === count( $linte ) && 3 === $linte[0]['cantidad'], 'con nº de serie no se suma (es una unidad concreta); sin tipo también se suman (2 + 1)' );
	$camisas = array_values( array_filter( MaterialesTable::todos(), static fn( $m ) => 'Prueba v030 camisa' === $m['descripcion'] ) );
	$ok( 3 === count( $camisas ) && 5 === MaterialesTable::obtener( $m_cam )['cantidad'], 'las camisas del uniforme solo suman si coinciden también el modelo y la talla (2 + 3 = 5; otra talla y otro modelo, aparte)' );
	$ok( MaterialesTable::MAX_CANTIDAD - 1 === MaterialesTable::obtener( $m_tope )['cantidad'] && str_contains( $motivos[12], 'pasaría de' ), 'si al sumar pasaría de la cantidad máxima, la fila no entra y se dice por qué' );
	$ok( 1 === MaterialesTable::obtener( $m_baja2 )['cantidad'] && 1 === count( array_filter( MaterialesTable::todos(), static fn( $m ) => 'Prueba v030 mochila' === $m['descripcion'] ) ), 'al material dado de baja no se le suma: la fila entra como material nuevo' );
	$clave = 'pruebav030';
	set_transient( 'asde_ap_inv_import_' . $u_scout . '_' . $clave, $res + array( 'fichero' => 'material.csv', 'filas' => 12 ), 60 );
	View::en_modal( true );
	$h = $ver( new InventarioView(), array( 'importar' => '1', 'resultado' => $clave ) );
	View::en_modal( false );
	delete_transient( 'asde_ap_inv_import_' . $u_scout . '_' . $clave );
	$ok( str_contains( $h, '<strong>6</strong> materiales importados' ) && str_contains( $h, '<strong>5</strong> filas sumadas a un material que ya estaba' ), 'el informe de la importación dice cuántas filas se han sumado' );
	$h = $ver( new InventarioView(), array( 'importar' => '1' ) );
	$ok( str_contains( $h, 'suma su cantidad a ese material en vez de crear otro' ), 'y «Cómo tiene que ser el fichero» lo explica' );

	// -----------------------------------------------------------------------
	echo "\n== F. P-177 (v0.44) · Un scouter ya no pide para los de su sección ==\n";
	$a_sc  = $como( $u_scout );
	$antes = $crm->llamadas;
	$para  = Uniformes::para_quien( $a_sc );
	$ok( array( '990081' ) === array_map( 'strval', array_keys( $para ) ) && $crm->llamadas === $antes, 'un scouter solo compra para sí y no lee el CRM para saberlo' );
	$ok( ! method_exists( Uniformes::class, 'de_mis_secciones' ) && ! method_exists( Uniformes::class, 'agrupar_para_quien' ) && ! method_exists( Uniformes::class, 'precios_camisa' ), 'ya no existen las funciones de «pedir para los de mi sección» ni el precio por persona' );
	$h = $ver( new TiendaPedidosView() );
	$ok( ! str_contains( $h, 'Como scouter' ) && ! str_contains( $h, 'asde-uni-socio' ), 'y la pantalla no ofrece elegir a quién' );

	$a_f = $como( $u_fam );
	$ok( array( '990082' ) === array_map( 'strval', array_keys( Uniformes::para_quien( $a_f ) ) ), 'la familia compra para sus menores, como siempre' );
	$a_s2 = $como( $u_sc2 );
	$ok( array( '990090' ) === array_map( 'strval', array_keys( Uniformes::para_quien( $a_s2 ) ) ), 'y otro scouter, solo para sí' );
	$ok( 0 === $cortadas, 'ninguna consulta ha salido hacia el CRM real' );
} finally {
	// ---------------------------------------------------------------------------
	// Limpieza: todo lo creado fuera; lo tocado, como estaba.
	remove_filter( 'wp_redirect', $redirigir, 1 );
	remove_filter( 'wp_die_handler', $morir, PHP_INT_MAX );
	remove_filter( 'send_auth_cookies', '__return_false' );
	remove_filter( 'pre_wp_mail', $capturar, 10 );
	$_POST = array();
	$_GET  = array();
	View::en_modal( false );
	Escritura::usar_para_pruebas( null );
	$crm->desactivar();
	remove_filter( 'pre_http_request', $cortar_crm, 2 );
	wp_using_ext_object_cache( $cache_externa );
	wp_set_current_user( 0 );

	if ( null === $cfg_antes ) {
		delete_option( 'asde_ap_uniformes_' . $grupo['numero'] );
	} else {
		update_option( 'asde_ap_uniformes_' . $grupo['numero'], $cfg_antes, false );
	}
	$lista = implode( ',', array_map( 'intval', $ids ) ) ?: '0';
	foreach ( array( array( MaterialesTable::table(), $mat_antes ), array( TiposTable::table(), $tip_antes ), array( ComprasTable::table(), $com_antes ), array( RegistroEmails::table(), $ema_antes ), array( PedidosTable::table(), $ped_antes ) ) as list( $tabla, $antes ) ) {
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id > %d', $tabla, $antes ) );
	}
	// El material que ya había, con la cantidad de antes (por si alguna suma lo hubiera tocado).
	foreach ( $mat_cant as $id => $cantidad ) {
		$wpdb->update( MaterialesTable::table(), array( 'cantidad' => (int) $cantidad ), array( 'id' => (int) $id ) );
	}
	$wpdb->query( $wpdb->prepare( "UPDATE %i SET especial = '' WHERE id <= %d", TiposTable::table(), $tip_antes ) );
	foreach ( $tip_especial as $t ) {
		$wpdb->update( TiposTable::table(), array( 'especial' => $t['especial'] ), array( 'id' => (int) $t['id'] ) );
	}
	$wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE id > %d AND ( user_id IN ($lista) OR accion LIKE 'inventario\\_%%' OR objeto = 'uniforme' )", Logger::table(), $log_antes ) ); // phpcs:ignore WordPress.DB
	foreach ( $ids as $uid ) {
		delete_transient( 'asde_ap_inv_material_' . $uid );
		delete_transient( 'asde_ap_inv_compra_' . $uid );
		delete_transient( 'asde_ap_notices_' . $uid );
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
	TiposTable::olvidar();
	Grupo::forzar( null );

	$quedan = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users} WHERE user_login LIKE 'prueba-v030-%'" ); // phpcs:ignore WordPress.DB
	$filas  = 0;
	foreach ( array( array( MaterialesTable::table(), $mat_antes ), array( TiposTable::table(), $tip_antes ), array( ComprasTable::table(), $com_antes ), array( RegistroEmails::table(), $ema_antes ), array( PedidosTable::table(), $ped_antes ) ) as list( $tabla, $antes ) ) {
		$filas += (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE id > %d', $tabla, $antes ) );
	}
	$cambiadas = 0;
	foreach ( $mat_cant as $id => $cantidad ) {
		$cambiadas += (int) ( (int) $wpdb->get_var( $wpdb->prepare( 'SELECT cantidad FROM %i WHERE id = %d', MaterialesTable::table(), (int) $id ) ) !== (int) $cantidad );
	}
	$ok( 0 === $quedan && 0 === $filas && 0 === $cambiadas, 'limpieza: sin usuarios, material, tipos, listas, emails ni pedidos de la prueba; el material de antes, como estaba' );
}

echo $fails ? "\n$fails FALLOS\n" : "\nTODO OK (nada tocado en el CRM real; el sitio queda como estaba)\n";
