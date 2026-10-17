<?php
/**
 * Pruebas de la v0.39, fase 3 (entorno LOCAL, doc 48): catálogo, carrito y pago de la tienda — precios del servidor,
 * «solo socios», «en físico» solo para socios, existencias, quién marca pagado, cancelar con devolución y emails.
 * Ejecutar desde app/public del site Local:
 *
 *   wp eval-file <ruta completa>/wp-content/plugins/asde-area-privada/tests/tienda-pedidos.php
 *
 * Usa el CRM simulado de tests/lib (fichas inventadas) y guarda las cachés solo en la memoria de este proceso.
 * Al terminar se borra lo creado (productos, variantes, pedidos, usuarios, registros) y se restaura la
 * configuración de la tienda del grupo.
 */
defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/lib/CrmSimulado.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Auth\Ambito;
use AsdeBalears\AreaPrivada\Domain\Grupo;
use AsdeBalears\AreaPrivada\Domain\Grupos;
use AsdeBalears\AreaPrivada\Frontend\Notices;
use AsdeBalears\AreaPrivada\Frontend\Views\TiendaGestionView;
use AsdeBalears\AreaPrivada\Frontend\Views\TiendaPedidosView;
use AsdeBalears\AreaPrivada\Frontend\Views\View;
use AsdeBalears\AreaPrivada\Install\Roles;
use AsdeBalears\AreaPrivada\Socios\SociosTable;
use AsdeBalears\AreaPrivada\Tienda\Config;
use AsdeBalears\AreaPrivada\Tienda\PedidosTable;
use AsdeBalears\AreaPrivada\Tienda\ProductosTable;
use AsdeBalears\AreaPrivada\Tienda\Servicio;
use AsdeBalears\AreaPrivada\Tienda\VariantesTable;
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
foreach ( get_users( array( 'search' => 'prueba-tp-*', 'search_columns' => array( 'user_login' ) ) ) as $u ) {
	exit( 'Ya existe el usuario ' . $u->user_login . " (¿una ejecución anterior interrumpida?). Bórralo antes de repetir la prueba.\n" );
}
wp_set_current_user( 0 );

// --- Lo que se restaura al final ---------------------------------------------
$max_id       = static fn( string $t ) => (int) $wpdb->get_var( "SELECT COALESCE(MAX(id), 0) FROM $t" ); // phpcs:ignore WordPress.DB
$log_antes    = $max_id( Logger::table() );
$ped_antes    = $max_id( PedidosTable::table() );
$prod_antes   = $max_id( ProductosTable::table() );
$var_antes    = $max_id( VariantesTable::table() );
$email_antes  = $max_id( RegistroEmails::table() );
$socios_antes = array_column( (array) $wpdb->get_results( 'SELECT * FROM ' . SociosTable::table(), ARRAY_A ), null, 'num_socio_asde' ); // phpcs:ignore WordPress.DB
$opcion_cfg   = 'asde_ap_tienda_' . $grupo['numero'];
$cfg_antes    = get_option( $opcion_cfg, null );

$cache_externa = wp_using_ext_object_cache( true );
add_filter( 'pre_wp_mail', '__return_true' );
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
		$P( 21, 'Leo', 'Tienda Sanz', '2016-05-10', 'Lobatos' ),
		$P( 24, 'Eva', 'Tienda Gil', '1990-01-01', 'Scouters' ),
		$P( 27, 'Iris', 'Tienda Roca', '2012-07-07', '' ), // Sin membresía: no es socia.
	),
	$grupo['crm_id']
);
$falsos = array_map( static fn( $p ) => $p['numerosocio_c'], $crm->personas );
Grupo::forzar( $grupo );
$crm->activar();

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
	foreach ( $meta as $k => $v ) {
		foreach ( (array) $v as $valor ) {
			add_user_meta( $id, $k, $valor );
		}
	}
	$ids[ $login ] = $id;
	return $id;
};
$u_fam   = $crear( 'prueba-tp-fam', array( Roles::TUTOR ), array( Usuarios::META_REPRESENTADO => '990021' ) );
$u_fam2  = $crear( 'prueba-tp-fam2', array( Roles::TUTOR ), array( Usuarios::META_REPRESENTADO => '990027' ) );
$u_teso  = $crear( 'prueba-tp-teso', array( Roles::SCOUTER, Roles::TESORERIA ), array( Usuarios::META_SOCIO => '990024', Usuarios::META_SECCION => 'rovers' ) );
$u_coord = $crear( 'prueba-tp-coord', array( Roles::SCOUTER, Roles::COORDINACION ), array( Usuarios::META_SOCIO => '990024', Usuarios::META_SECCION => 'rovers' ) );
$u_secre = $crear( 'prueba-tp-secre', array( Roles::SCOUTER, Roles::SECRETARIA ), array( Usuarios::META_SOCIO => '990024', Usuarios::META_SECCION => 'rovers' ) );

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
$ultimo   = static fn() => PedidosTable::obtener( $max_id( PedidosTable::table() ) );
$emails   = static fn( string $uid_login = '' ) => array_values(
	array_filter(
		RegistroEmails::listado(),
		static fn( $f ) => (int) $f['id'] > $email_antes && RegistroEmails::TIENDA === $f['tipo'] && ( '' === $uid_login || $f['login'] === $uid_login )
	)
);
$existencias = static fn( int $v ) => VariantesTable::obtener( $v )['existencias'];

// --- Catálogo de prueba (los productos se borran al final) --------------------
$nuevo = static function ( string $nombre, string $vis, ?float $precio, ?float $socio, int $limite, int $proveedor, array $variantes ): array {
	$id = ProductosTable::crear(
		array(
			'nombre'             => $nombre,
			'descripcion'        => '',
			'categoria'          => 'Prueba tienda',
			'visibilidad'        => $vis,
			'precio'             => $precio,
			'precio_socio'       => $socio,
			'limite_persona'     => $limite,
			'requiere_proveedor' => $proveedor,
			'sale_inventario'    => 0,
			'activo'             => 1,
		)
	);
	$vs = array();
	foreach ( $variantes as $etiqueta => $v ) {
		$vs[ $etiqueta ] = VariantesTable::crear(
			$id,
			array(
				'etiqueta'    => $etiqueta,
				'sku'         => '',
				'precio'      => $v[2] ?? null,
				'existencias' => $v[0],
				'ilimitado'   => $v[1],
			)
		);
	}
	return array( $id, $vs );
};
list( $taza, $v_taza )         = $nuevo( 'Taza de prueba', 'todos', 5.0, 4.0, 0, 0, array( 'Única' => array( 3, 0 ) ) );
list( $panuelo, $v_panuelo )   = $nuevo( 'Pañuelo de prueba', 'socios', 6.0, null, 2, 0, array( 'A' => array( 10, 0 ), 'B' => array( 0, 0 ) ) );
list( $sudadera, $v_sudadera ) = $nuevo( 'Sudadera de prueba', 'todos', null, null, 0, 1, array( 'M' => array( 0, 1 ) ) );
list( $gratis, $v_gratis )     = $nuevo( 'Regalo de prueba', 'todos', 0.0, null, 0, 0, array( 'Única' => array( 0, 1 ) ) );

$cfg          = Config::defaults();
$cfg['iban']  = 'ES91 2100 0418 4502 0005 1332';
$cfg['bizum'] = '600123456';
Config::guardar( $cfg, $grupo['numero'] );

try {
	echo "== Parte A · Qué ve cada cuenta y qué cuesta ==\n";
	$nombres = static fn( array $cat ) => array_column( array_column( $cat, 'producto' ), 'nombre' );
	$socio   = $nombres( Servicio::catalogo( true ) );
	$no      = $nombres( Servicio::catalogo( false ) );
	$ok( in_array( 'Taza de prueba', $socio, true ) && in_array( 'Pañuelo de prueba', $socio, true ), 'un socio/a ve lo «para todos» y lo «solo socios»' );
	$ok( in_array( 'Taza de prueba', $no, true ) && ! in_array( 'Pañuelo de prueba', $no, true ), 'quien no es socio/a no ve lo «solo socios»' );
	$ok( ! in_array( 'Camisa scout', $socio, true ), 'la camisa y las insignias siguen en el formulario de uniforme, no en el catálogo' );
	$l = Servicio::lineas_tienda( true, array( $v_taza['Única'] => 2 ), '990021' );
	$ok( is_array( $l ) && 4.0 === $l[0]['precio'] && 'Taza de prueba' === $l[0]['nombre'], 'el socio/a paga el precio de socio (4 €)' );
	$l = Servicio::lineas_tienda( false, array( $v_taza['Única'] => 2 ), '990027' );
	$ok( is_array( $l ) && 5.0 === $l[0]['precio'], 'quien no es socio/a paga el general (5 €)' );
	$ok( is_wp_error( Servicio::lineas_tienda( false, array( $v_panuelo['A'] => 1 ), '990027' ) ), 'un producto «solo socios» no se cuela aunque se mande su id' );
	$ok( is_wp_error( Servicio::lineas_tienda( true, array( $v_taza['Única'] => 0 ), '990021' ) ) && is_wp_error( Servicio::lineas_tienda( true, array( 999999 => 1 ), '990021' ) ), 'sin nada elegido o con una variante que no existe → error' );
	$ok( is_wp_error( Servicio::lineas_tienda( true, array( $v_panuelo['B'] => 1 ), '990021' ) ), 'una variante agotada no se puede pedir' );
	$ok( is_wp_error( Servicio::lineas_tienda( true, array( $v_taza['Única'] => 100 ), '990021' ) ), 'cantidades disparatadas → error' );
	$l = Servicio::lineas_tienda( true, array( $v_sudadera['M'] => 1 ), '990021' );
	$ok( is_array( $l ) && null === $l[0]['precio'] && 1 === $l[0]['proveedor'], 'un producto sin precio sale «por confirmar» y marcado para el proveedor' );

	echo "\n== Parte B · Comprar (socios) ==\n";
	$a_fam = $como( $u_fam );
	$h     = ( new TiendaPedidosView() )->render();
	$ok( str_contains( $h, 'Taza de prueba' ) && str_contains( $h, 'Pañuelo de prueba' ) && str_contains( $h, 'name="asde_action" value="tienda_comprar"' ), 'la familia de un socio/a ve el catálogo, también lo «solo socios»' );
	$h = $enviar( new TiendaPedidosView(), array( 'asde_action' => 'tienda_comprar', 'socio' => '990021', 'cant' => array( $v_taza['Única'] => '2', $v_panuelo['A'] => '1' ), 'forma_pago' => 'bizum', 'nota' => 'Prueba' ), 'asde_tienda_comprar' );
	$p1 = $ultimo();
	$ok( $p1 && '990021' === $p1['socio'] && 14.0 === $p1['total'] && ! $p1['provisional'] && 'bizum' === $p1['forma_pago'] && 2 === count( $p1['lineas']['productos'] ) && str_contains( $h, PedidosTable::numero( $p1 ) ), 'pedido de 2 tazas (4 €) y 1 pañuelo (6 €) = 14 € con Bizum, y su nº en el aviso' );
	$ok( 1 === $existencias( $v_taza['Única'] ) && 9 === $existencias( $v_panuelo['A'] ), 'las existencias bajan (taza 3→1, pañuelo 10→9)' );
	$ok( null !== $p1['pedido_en'] && PedidosTable::REF_TIENDA === $p1['pedido_ref'] && null === $p1['pago_en'], 'sin nada que pedir al proveedor: queda incluido «de las existencias»; el pago, pendiente' );
	$ok( str_contains( Servicio::descripcion( $p1['lineas'] ), 'Taza de prueba ×2' ), 'descripción legible del pedido' );
	$ok( str_contains( implode( ' ', Servicio::instrucciones_pago( $p1, Config::de( $grupo['numero'] ) ) ), '600123456' ), 'las instrucciones de pago llevan el Bizum del grupo' );
	$h = ( new TiendaPedidosView() )->render();
	$ok( str_contains( $h, '600123456' ) && str_contains( $h, PedidosTable::numero( $p1 ) ), 'en «Tus pedidos» el pedido sin pagar enseña cómo pagarlo' );

	$h = $enviar( new TiendaPedidosView(), array( 'asde_action' => 'tienda_comprar', 'socio' => '990021', 'cant' => array( $v_panuelo['A'] => '2' ), 'forma_pago' => 'fisico' ), 'asde_tienda_comprar' );
	$ok( $ultimo()['id'] === $p1['id'] && 9 === $existencias( $v_panuelo['A'] ) && str_contains( $h, 'solo se pueden pedir 2 por persona' ), 'el límite por persona cuenta lo ya pedido (1 + 2 > 2) y no reserva nada' );
	$enviar( new TiendaPedidosView(), array( 'asde_action' => 'tienda_comprar', 'socio' => '990021', 'cant' => array( $v_panuelo['A'] => '1' ), 'forma_pago' => 'fisico' ), 'asde_tienda_comprar' );
	$p2 = $ultimo();
	$ok( $p2['id'] > $p1['id'] && 'fisico' === $p2['forma_pago'] && 6.0 === $p2['total'] && 8 === $existencias( $v_panuelo['A'] ), 'un socio/a puede pagar «en físico»; con 1 más llega justo al límite' );

	$h = $enviar( new TiendaPedidosView(), array( 'asde_action' => 'tienda_comprar', 'socio' => '990021', 'cant' => array( $v_taza['Única'] => '1' ) ), 'asde_tienda_comprar' );
	$ok( $ultimo()['id'] === $p2['id'] && 1 === $existencias( $v_taza['Única'] ) && str_contains( $h, 'Elige cómo vas a pagar' ), 'sin forma de pago no hay pedido ni se reserva nada' );
	$h = $enviar( new TiendaPedidosView(), array( 'asde_action' => 'tienda_comprar', 'socio' => '990021', 'cant' => array( $v_taza['Única'] => '1' ), 'forma_pago' => 'tarjeta' ), 'asde_tienda_comprar' );
	$ok( $ultimo()['id'] === $p2['id'] && str_contains( $h, 'Elige cómo vas a pagar' ), 'una forma de pago inventada → no')	;

	echo "\n== Parte C · Existencias ==\n";
	$ok( true === VariantesTable::reservar( $v_taza['Única'], 1 ) && false === VariantesTable::reservar( $v_taza['Única'], 1 ) && 0 === $existencias( $v_taza['Única'] ), 'quedaba 1: la primera reserva lo coge y la segunda, no (no se vende lo mismo dos veces)' );
	VariantesTable::devolver( $v_taza['Única'], 1 );
	$h = $enviar( new TiendaPedidosView(), array( 'asde_action' => 'tienda_comprar', 'socio' => '990021', 'cant' => array( $v_taza['Única'] => '2', $v_panuelo['A'] => '0' ), 'forma_pago' => 'bizum' ), 'asde_tienda_comprar' );
	$ok( $ultimo()['id'] === $p2['id'] && 1 === $existencias( $v_taza['Única'] ) && str_contains( $h, 'No quedan unidades suficientes' ), 'pedir 2 con 1 en existencias → error y no cambia nada' );
	// Carrera: otra compra se lleva la última entre que se comprueba y se reserva.
	$lineas_ok = Servicio::lineas_tienda( true, array( $v_taza['Única'] => 1, $v_panuelo['A'] => 1 ), '990024' );
	VariantesTable::reservar( $v_taza['Única'], 1 ); // «el otro comprador».
	$hechas = array();
	$fallo  = false;
	foreach ( $lineas_ok as $i => $l ) {
		if ( ! VariantesTable::reservar( $l['variante'], $l['cantidad'] ) ) {
			$fallo = true;
			Servicio::devolver_lineas( $hechas );
			break;
		}
		$lineas_ok[ $i ]['reservado'] = true;
		$hechas[]                     = $lineas_ok[ $i ];
	}
	$ok( $fallo && 8 === $existencias( $v_panuelo['A'] ) && 0 === $existencias( $v_taza['Única'] ), 'si falla una línea a mitad, lo ya reservado vuelve (pañuelo 8, taza 0)' );
	VariantesTable::devolver( $v_taza['Única'], 1 );

	echo "\n== Parte D · Quien no es socio/a ==\n";
	$ok( is_wp_error( Servicio::forma_valida( 'fisico', false, true ) ) && 'fisico' === Servicio::forma_valida( 'fisico', true, true ) && 'bizum' === Servicio::forma_valida( 'bizum', false, true ), '«en físico» solo para socios/as; transferencia y Bizum, para cualquiera' );
	$ok( is_wp_error( Servicio::forma_valida( '', true, true ) ) && '' === Servicio::forma_valida( '', true, false ) && is_wp_error( Servicio::forma_valida( 'tarjeta', true, false ) ), 'sin forma de pago solo vale donde no es obligatoria; una inventada nunca' );
	$a_fam2 = $como( $u_fam2 );
	$h      = ( new TiendaPedidosView() )->render();
	$ok( ! str_contains( $h, 'tienda_comprar' ) && ! str_contains( $h, 'uniforme_pedir' ) && str_contains( $h, 'ninguna persona' ), 'una cuenta sin ninguna persona con membresía activa no ve formularios para comprar' );
	$antes = $ultimo()['id'];
	$h     = $enviar( new TiendaPedidosView(), array( 'asde_action' => 'tienda_comprar', 'socio' => '990027', 'cant' => array( $v_taza['Única'] => '1' ), 'forma_pago' => 'transferencia' ), 'asde_tienda_comprar' );
	$ok( $ultimo()['id'] === $antes && 1 === $existencias( $v_taza['Única'] ) && str_contains( $h, 'No puedes hacer un pedido para esa persona' ), 'y si manda el pedido a mano, no hay pedido ni se reserva nada' );
	$como( $u_fam );
	$h = $enviar( new TiendaPedidosView(), array( 'asde_action' => 'tienda_comprar', 'socio' => '990027', 'cant' => array( $v_taza['Única'] => '1' ), 'forma_pago' => 'bizum' ), 'asde_tienda_comprar' );
	$ok( $ultimo()['id'] === $antes && str_contains( $h, 'No puedes hacer un pedido para esa persona' ), 'ni se pide para alguien de otra familia' );
	$enviar( new TiendaPedidosView(), array( 'asde_action' => 'tienda_comprar', 'socio' => '990021', 'cant' => array( $v_taza['Única'] => '1' ), 'forma_pago' => 'transferencia' ), 'asde_tienda_comprar' );
	$p3 = $ultimo();
	$ok( $p3['id'] > $antes && 4.0 === $p3['total'] && 'transferencia' === $p3['forma_pago'] && 0 === $existencias( $v_taza['Única'] ), 'la última taza, por transferencia: 4 € y ya no quedan' );
	$h = $enviar( new TiendaPedidosView(), array( 'asde_action' => 'tienda_comprar', 'socio' => '990021', 'cant' => array( $v_taza['Única'] => '1' ), 'forma_pago' => 'bizum' ), 'asde_tienda_comprar' );
	$ok( $ultimo()['id'] === $p3['id'] && str_contains( $h, 'No quedan unidades suficientes' ) && str_contains( ( new TiendaPedidosView() )->render(), 'Agotado' ), 'agotada: no se vende más y el catálogo lo dice' );
	echo "\n== Parte E · Productos por confirmar, gratis y por encargo ==\n";
	$como( $u_fam );
	$enviar( new TiendaPedidosView(), array( 'asde_action' => 'tienda_comprar', 'socio' => '990021', 'cant' => array( $v_sudadera['M'] => '2' ), 'forma_pago' => 'transferencia' ), 'asde_tienda_comprar' );
	$p4 = $ultimo();
	$ok( $p4['provisional'] && 0.0 === $p4['total'] && null === $p4['pedido_en'] && null === $p4['pago_en'], 'sudadera sin precio: importe provisional, pendiente de pagar y de pedir al proveedor' );
	$res = Servicio::resumen( array( $p4 ), Config::de( $grupo['numero'] ) );
	$ok( 1 === count( $res['productos'] ) && 2 === $res['productos'][0]['cantidad'] && 'Sudadera de prueba (M)' === $res['productos'][0]['nombre'], 'el resumen para el proveedor incluye lo del catálogo que se encarga' );
	$enviar( new TiendaPedidosView(), array( 'asde_action' => 'tienda_comprar', 'socio' => '990021', 'cant' => array( $v_gratis['Única'] => '1' ), 'forma_pago' => 'transferencia' ), 'asde_tienda_comprar' );
	$p5 = $ultimo();
	$ok( 0.0 === $p5['total'] && null !== $p5['pago_en'] && PedidosTable::REF_GRATIS === $p5['pago_ref'], 'lo que no cuesta nada queda pagado «sin coste»' );

	echo "\n== Parte F · Pago (solo Tesorería), entrega y recibido ==\n";
	foreach ( array( $u_coord, $u_secre ) as $quien ) {
		$como( $quien );
		$enviar( new TiendaGestionView(), array( 'asde_action' => 'uniforme_pago', 'pedido' => (string) $p1['id'], 'metodo' => 'bizum', 'fecha' => wp_date( 'Y-m-d' ), 'importe' => '14' ), 'asde_uniforme_' . $p1['id'] );
		$ok( null === PedidosTable::obtener( $p1['id'] )['pago_en'] && ! current_user_can( Servicio::CAP_PAGO ), 'Coordinación y Secretaría no marcan pagado' );
	}
	$como( $u_teso );
	$h = ( new TiendaGestionView() )->render();
	$h = ( new TiendaGestionView() )->render();
	$_GET['pedido'] = (string) $p1['id'];
	$detalle        = ( new TiendaGestionView() )->render();
	unset( $_GET['pedido'] );
	$ok( str_contains( $detalle, 'eligió pagar con: Bizum' ) && (bool) preg_match( '/value="bizum"[ ]+checked/', $detalle ), 'Tesorería ve cómo eligió pagar quien compró, y Bizum viene marcado' );
	$enviar( new TiendaGestionView(), array( 'asde_action' => 'uniforme_pago', 'pedido' => (string) $p1['id'], 'metodo' => 'bizum', 'fecha' => wp_date( 'Y-m-d' ), 'importe' => '14', 'referencia' => 'Bizum de prueba' ), 'asde_uniforme_' . $p1['id'] );
	$q = PedidosTable::obtener( $p1['id'] );
	$ok( null !== $q['pago_en'] && 'bizum' === $q['pago_metodo'] && 14.0 === $q['pago_importe'] && $u_teso === $q['pago_por'], 'Tesorería marca pagado por Bizum' );
	$enviar( new TiendaGestionView(), array( 'asde_action' => 'uniforme_deshacer_pago', 'pedido' => (string) $p1['id'] ), 'asde_uniforme_' . $p1['id'] );
	$ok( null === PedidosTable::obtener( $p1['id'] )['pago_en'], 'y lo puede deshacer' );
	$enviar( new TiendaGestionView(), array( 'asde_action' => 'uniforme_pago', 'pedido' => (string) $p1['id'], 'metodo' => 'efectivo', 'fecha' => wp_date( 'Y-m-d' ), 'importe' => '14' ), 'asde_uniforme_' . $p1['id'] );
	$como( $u_coord );
	$enviar( new TiendaGestionView(), array( 'asde_action' => 'uniforme_entregar', 'pedido' => (string) $p1['id'] ), 'asde_uniforme_' . $p1['id'] );
	$ok( null !== PedidosTable::obtener( $p1['id'] )['entregado_en'], 'la administración marca entregado' );
	$como( $u_fam );
	$enviar( new TiendaPedidosView(), array( 'asde_action' => 'uniforme_recibido', 'pedido' => (string) $p1['id'] ), 'asde_uniforme_' . $p1['id'] );
	$ok( null !== PedidosTable::obtener( $p1['id'] )['recibido_en'], 'y la familia confirma que lo ha recibido' );

	echo "\n== Parte G · Cancelar devuelve las existencias ==\n";
	$taza_antes = $existencias( $v_taza['Única'] );
	$pan_antes  = $existencias( $v_panuelo['A'] );
	$enviar( new TiendaPedidosView(), array( 'asde_action' => 'uniforme_cancelar', 'pedido' => (string) $p2['id'] ), 'asde_uniforme_' . $p2['id'] );
	$ok( null !== PedidosTable::obtener( $p2['id'] )['cancelado_en'] && $pan_antes + 1 === $existencias( $v_panuelo['A'] ), 'la familia cancela el pedido del pañuelo y vuelve a las existencias' );
	Servicio::cancelar( PedidosTable::obtener( $p2['id'] ), 'otra vez' );
	$ok( $pan_antes + 1 === $existencias( $v_panuelo['A'] ), 'cancelarlo otra vez no devuelve dos veces' );
	$como( $u_teso );
	Servicio::cancelar( PedidosTable::obtener( $p3['id'] ), 'Prueba' );
	$ok( $taza_antes + 1 === $existencias( $v_taza['Única'] ), 'Tesorería cancela el de la taza y vuelve a las existencias' );
	$como( $u_fam );
	$ok( ! Servicio::puede_cancelar_propio( PedidosTable::obtener( $p1['id'] ), $a_fam ), 'uno ya entregado no se cancela desde la familia' );

	echo "\n== Parte H · Archivado y configuración de pago ==\n";
	ProductosTable::archivar( $taza );
	$ok( is_wp_error( Servicio::lineas_tienda( true, array( $v_taza['Única'] => 1 ), '990021' ) ), 'un producto archivado ya no se puede pedir')	;
	ProductosTable::archivar( $taza, false );
	$ok( 'ES91 2100 0418 4502 0005 1332' === Config::iban( 'es91 2100 0418 4502 0005 1332' ) && '' === Config::iban( 'no es un iban' ) && '600123456' === Config::bizum( '600 123 456' ) && '' === Config::bizum( '12' ), 'el IBAN y el teléfono de Bizum se limpian y se validan' );
	$como( $u_coord );
	$enviar( new \AsdeBalears\AreaPrivada\Frontend\Views\TiendaConfigView(), array( 'abierto' => '1', 'precio_camisa' => '25', 'iban' => 'ES00 mal', 'bizum' => '611 222 333' ), 'asde_tienda_admin' );
	$c = Config::de( $grupo['numero'] );
	$ok( '' === $c['iban'] && '611222333' === $c['bizum'], 'la configuración guarda un Bizum válido y descarta un IBAN que no lo es' );

	echo "\n== Parte I · Formulario de uniforme con forma de pago ==\n";
	$como( $u_fam );
	$antes = $ultimo()['id'];
	$h     = $enviar( new TiendaPedidosView(), array( 'asde_action' => 'uniforme_pedir', 'socio' => '990021', 'con_camisa' => '1', 'camisa' => 'masculina|M', 'forma_pago' => 'tarjeta' ), 'asde_uniforme_pedir' );
	$ok( $ultimo()['id'] === $antes && str_contains( $h, 'Elige cómo vas a pagar' ), 'el uniforme tampoco admite una forma de pago inventada' );
	$enviar( new TiendaPedidosView(), array( 'asde_action' => 'uniforme_pedir', 'socio' => '990021', 'con_camisa' => '1', 'camisa' => 'masculina|M', 'forma_pago' => 'fisico' ), 'asde_uniforme_pedir' );
	$ok( 'fisico' === $ultimo()['forma_pago'] && $ultimo()['id'] > $antes, 'a un socio/a sí, y queda guardada la forma de pago' );
	$enviar( new TiendaPedidosView(), array( 'asde_action' => 'uniforme_pedir', 'socio' => '990021', 'con_camisa' => '1', 'camisa' => 'masculina|L' ), 'asde_uniforme_pedir' );
	$ok( '' === $ultimo()['forma_pago'], 'en el uniforme la forma de pago es opcional')	;

	echo "\n== Parte J · Emails ==\n";
	$e_fam = $emails( 'prueba-tp-fam' );
	$tipos = array_map( static fn( $f ) => (string) $f['asunto'], $e_fam );
	$ok( count( $e_fam ) >= 5, 'cada pedido avisa a quien lo hizo (' . count( $e_fam ) . ' registros)' );
	$ok( (bool) array_filter( $tipos, static fn( $t ) => str_contains( $t, 'Pedido recibido' ) ) && (bool) array_filter( $tipos, static fn( $t ) => str_contains( $t, 'Pago recibido' ) ) && (bool) array_filter( $tipos, static fn( $t ) => str_contains( $t, 'Pedido entregado' ) ), 'salen los tres avisos: pedido recibido, pago recibido y pedido entregado' );
	$ok( RegistroEmails::SIN_EMAIL === $e_fam[0]['estado'], 'una cuenta sin email queda en el registro como «sin email», sin fallar' );
	$vista = RegistroEmails::vista_previa( $e_fam[ count( $e_fam ) - 1 ] );
	$ok( '' !== $vista && str_contains( $vista, 'Mis pedidos' ) || str_contains( $vista, 'pedidos' ), 'el registro de emails sabe volver a componer el texto' );
	$mas = array_filter( $e_fam, static fn( $f ) => str_contains( (string) $f['datos'], '@' ) || str_contains( (string) $f['datos'], 'Leo' ) );
	$ok( ! $mas, 'el registro no guarda nombres ni emails: solo el tipo y el id del pedido' );

	$ok( $crm->llamadas > 0 && 0 === $cortadas, "todas las consultas al CRM las ha respondido el simulador ({$crm->llamadas}); ninguna ha salido hacia el CRM real" );
} finally {
	// ---------------------------------------------------------------------------
	// Limpieza: todo lo creado fuera; lo tocado, como estaba.
	remove_filter( 'wp_redirect', $redirigir, 1 );
	remove_filter( 'send_auth_cookies', '__return_false' );
	remove_filter( 'pre_wp_mail', '__return_true' );
	$_POST = array();
	$_GET  = array();
	View::en_modal( false );
	$crm->desactivar();
	remove_filter( 'pre_http_request', $cortar_crm, 2 );
	wp_using_ext_object_cache( $cache_externa );
	wp_set_current_user( 0 );

	$lista = implode( ',', array_map( 'intval', $ids ) ) ?: '0';
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . PedidosTable::table() . ' WHERE id > %d', $ped_antes ) ); // phpcs:ignore WordPress.DB
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . VariantesTable::table() . ' WHERE id > %d', $var_antes ) ); // phpcs:ignore WordPress.DB
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . ProductosTable::table() . ' WHERE id > %d', $prod_antes ) ); // phpcs:ignore WordPress.DB
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . RegistroEmails::table() . ' WHERE id > %d', $email_antes ) ); // phpcs:ignore WordPress.DB
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Logger::table() . " WHERE id > %d AND ( user_id IN ($lista) OR objeto IN ( 'uniforme', 'tienda' ) OR ( objeto = 'grupo' AND accion LIKE 'uniformes\\_%%' AND user_id IN ($lista) ) )", $log_antes ) ); // phpcs:ignore WordPress.DB
	foreach ( $ids as $uid ) {
		Ambito::olvidar( (int) $uid );
		wp_delete_user( (int) $uid );
	}
	if ( null === $cfg_antes ) {
		delete_option( $opcion_cfg );
	} else {
		update_option( $opcion_cfg, $cfg_antes, false );
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

	$quedan = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users} WHERE user_login LIKE 'prueba-tp-%'" ); // phpcs:ignore WordPress.DB
	$ok( 0 === $quedan && 0 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . PedidosTable::table() . ' WHERE id > ' . (int) $ped_antes ) && 0 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . ProductosTable::table() . ' WHERE id > ' . (int) $prod_antes ) && get_option( $opcion_cfg, null ) == $cfg_antes, 'limpieza: sin usuarios, pedidos ni productos de la prueba; configuración de la tienda como estaba' ); // phpcs:ignore WordPress.DB
}

echo $fails ? "\n$fails FALLOS\n" : "\nTODO OK (nada tocado en el CRM real; el sitio queda como estaba)\n";
