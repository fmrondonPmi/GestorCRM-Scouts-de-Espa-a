<?php
/**
 * Pruebas de la v0.39, fase 4 (entorno LOCAL, doc 48): la tienda pública — comprar sin iniciar sesión con el enlace
 * del grupo, solo lo «para todos», datos cifrados, página personal del pedido, cancelar, emails, caducidad y borrado.
 * Ejecutar desde app/public del site Local:
 *
 *   wp eval-file <ruta completa>/wp-content/plugins/asde-area-privada/tests/tienda-publica.php
 *
 * No usa el CRM (el invitado no está en él). Al terminar se borra lo creado (productos, pedidos, usuarios, registros)
 * y se restauran la configuración de la tienda y el enlace del grupo.
 */
defined( 'ABSPATH' ) || exit;

require_once ABSPATH . 'wp-admin/includes/user.php';

use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Auth\Ambito;
use AsdeBalears\AreaPrivada\Domain\Grupo;
use AsdeBalears\AreaPrivada\Domain\Grupos;
use AsdeBalears\AreaPrivada\Frontend\Notices;
use AsdeBalears\AreaPrivada\Frontend\Pages;
use AsdeBalears\AreaPrivada\Frontend\Views\TiendaConfigView;
use AsdeBalears\AreaPrivada\Frontend\Views\TiendaGestionView;
use AsdeBalears\AreaPrivada\Frontend\Views\TiendaPublicaView;
use AsdeBalears\AreaPrivada\Install\Roles;
use AsdeBalears\AreaPrivada\Tienda\Config;
use AsdeBalears\AreaPrivada\Tienda\Enlace;
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
if ( ! $grupo ) {
	exit( "Hace falta un grupo.\n" );
}
foreach ( get_users( array( 'search' => 'prueba-pub-*', 'search_columns' => array( 'user_login' ) ) ) as $u ) {
	exit( 'Ya existe el usuario ' . $u->user_login . " (¿una ejecución anterior interrumpida?). Bórralo antes de repetir la prueba.\n" );
}
wp_set_current_user( 0 );
Grupo::forzar( $grupo );
PedidosTable::install_table(); // Las columnas de la fase 4 (el plugin no cambia de versión entre pruebas).

// --- Lo que se restaura al final ---------------------------------------------
$max_id      = static fn( string $t ) => (int) $wpdb->get_var( "SELECT COALESCE(MAX(id), 0) FROM $t" ); // phpcs:ignore WordPress.DB
$log_antes   = $max_id( Logger::table() );
$ped_antes   = $max_id( PedidosTable::table() );
$prod_antes  = $max_id( ProductosTable::table() );
$var_antes   = $max_id( VariantesTable::table() );
$email_antes = $max_id( RegistroEmails::table() );
$opcion_cfg  = 'asde_ap_tienda_' . $grupo['numero'];
$cfg_antes   = get_option( $opcion_cfg, null );
$enlaces_antes = get_option( Enlace::OPCION, null );

add_filter( 'pre_wp_mail', '__return_true' );
$cache_externa = wp_using_ext_object_cache( true );
add_filter( 'send_auth_cookies', '__return_false' );
$destino = '';
$redirigir = static function ( $url ) use ( &$destino ) {
	$destino = (string) $url;
	throw new RuntimeException( 'asde-redirect:' . $url );
};
add_filter( 'wp_redirect', $redirigir, 1 );

$ids   = array();
$crear = static function ( string $login, array $roles ) use ( &$ids, $grupo ): int {
	$id = wp_insert_user( array( 'user_login' => $login, 'user_email' => '', 'user_pass' => wp_generate_password( 24 ), 'role' => $roles[0] ) );
	$u  = get_userdata( $id );
	foreach ( array_slice( $roles, 1 ) as $r ) {
		$u->add_role( $r );
	}
	update_user_meta( $id, Usuarios::META_GRUPO, $grupo['id'] );
	update_user_meta( $id, Usuarios::META_ESTADO, Usuarios::ACTIVO );
	$ids[ $login ] = $id;
	return $id;
};
$u_teso  = $crear( 'prueba-pub-teso', array( Roles::SCOUTER, Roles::TESORERIA ) );
$u_coord = $crear( 'prueba-pub-coord', array( Roles::SCOUTER, Roles::COORDINACION ) );
$como    = static function ( int $uid ): void {
	wp_set_current_user( $uid );
	Ambito::olvidar( $uid );
};

/** Reinicia lo que la vista pública se recuerda entre peticiones. */
$olvidar = static function (): void {
	$p = new ReflectionProperty( TiendaPublicaView::class, 'estado' );
	$p->setAccessible( true );
	$p->setValue( null, array() );
};
$sello = static function ( int $segundos = 10 ): string {
	$t = (string) ( time() - $segundos );
	return $t . '.' . substr( hash_hmac( 'sha256', 'tienda|' . $t, wp_salt( 'nonce' ) ), 0, 16 );
};
/** Envía un formulario a la tienda pública como lo haría un visitante; devuelve [dirección a la que redirige o '', HTML con los avisos]. */
$enviar = static function ( array $campos, string $nonce, array $get = array() ) use ( &$destino, $olvidar ): array {
	$olvidar();
	$destino  = '';
	$_GET     = $get;
	$_POST    = $campos + array( '_wpnonce' => wp_create_nonce( $nonce ) );
	$_REQUEST = $_POST;
	$vista    = new TiendaPublicaView();
	try {
		$vista->handle_post();
		$html = $vista->render();
	} catch ( RuntimeException $e ) {
		$html = '';
	} finally {
		$_POST    = array();
		$_REQUEST = array();
		$_GET     = array();
	}
	return array( $destino, $html );
};
$ver = static function ( array $get ) use ( $olvidar ): string {
	$olvidar();
	$_GET = $get;
	try {
		return ( new TiendaPublicaView() )->render();
	} finally {
		$_GET = array();
	}
};
$existencias = static fn( int $v ) => VariantesTable::obtener( $v )['existencias'];
$nuevo       = static function ( string $nombre, string $vis, ?float $precio, ?float $socio, int $limite, array $variantes ): array {
	$id = ProductosTable::crear(
		array(
			'nombre' => $nombre, 'descripcion' => '', 'categoria' => 'Prueba pública', 'visibilidad' => $vis, 'precio' => $precio,
			'precio_socio' => $socio, 'limite_persona' => $limite, 'requiere_proveedor' => 0, 'sale_inventario' => 0, 'activo' => 1,
		)
	);
	$vs = array();
	foreach ( $variantes as $etiqueta => $v ) {
		$vs[ $etiqueta ] = VariantesTable::crear( $id, array( 'etiqueta' => $etiqueta, 'sku' => '', 'precio' => null, 'existencias' => $v[0], 'ilimitado' => $v[1] ) );
	}
	return array( $id, $vs );
};
list( $taza, $v_taza )       = $nuevo( 'Taza pública', 'todos', 5.0, 4.0, 0, array( 'Única' => array( 4, 0 ) ) );
list( $panuelo, $v_panuelo ) = $nuevo( 'Pañuelo de socios', 'socios', 6.0, null, 0, array( 'A' => array( 10, 0 ) ) );
list( $llavero, $v_llavero ) = $nuevo( 'Llavero limitado', 'todos', 2.0, null, 2, array( 'Única' => array( 20, 0 ) ) );

$cfg                   = Config::defaults();
$cfg['publica']        = true;
$cfg['iban']           = 'ES91 2100 0418 4502 0005 1332';
$cfg['bizum']          = '600123456';
$cfg['dias_sin_pagar'] = 14;
Config::guardar( $cfg, $grupo['numero'] );
$g_id = $grupo['id'];

try {
	echo "== Parte A · El enlace del grupo ==\n";
	$clave = Enlace::clave( $grupo );
	$ok( 24 === strlen( $clave ) && Enlace::valida( $grupo, $clave ) && ! Enlace::valida( $grupo, 'otra' ) && in_array( 'tienda_publica', Pages::PUBLICAS, true ), 'cada grupo tiene su clave de 24 caracteres y la página es pública' );
	$url = Enlace::url( $grupo );
	$ok( str_contains( $url, 'clave=' . $clave ) && str_contains( $url, '/tienda' ), 'el enlace lleva la clave, en la página «tienda»' );

	echo "\n== Parte B · Lo que ve un visitante ==\n";
	$h = $ver( array( 'grupo' => $g_id, 'clave' => $clave ) );
	$ok( str_contains( $h, 'Taza pública' ) && str_contains( $h, 'Llavero limitado' ) && ! str_contains( $h, 'Pañuelo de socios' ), 'con el enlace ve lo «para todos» y NO lo «solo socios»' );
	$ok( str_contains( $h, 'value="tienda_invitado_comprar"' ) && str_contains( $h, 'name="web"' ) && ! str_contains( $h, 'value="fisico"' ), 'el formulario lleva el campo trampa y no ofrece pagar en físico' );
	$ok( str_contains( $h, '5,00' ) && ! str_contains( $h, '4,00' ), 'paga el precio general (5 €), no el de socio' );
	$ok( str_contains( $ver( array( 'grupo' => $g_id, 'clave' => 'mala' ) ), 'no es válida' ) && str_contains( $ver( array() ), 'no es válida' ), 'sin clave o con otra, la tienda no existe');
	$cerrada            = $cfg;
	$cerrada['publica'] = false;
	Config::guardar( $cerrada, $grupo['numero'] );
	$ok( str_contains( $ver( array( 'grupo' => $g_id, 'clave' => $clave ) ), 'no es válida' ), 'con la tienda pública apagada en el grupo, el enlace no vale' );
	$ok( is_wp_error( Servicio::comprar_invitado( array( 'nombre' => 'Ana Prueba', 'email' => 'ana@example.invalid' ), array( $v_taza['Única'] => 1 ), '', 'bizum' ) ), 'ni se puede comprar por la puerta de atrás' );
	Config::guardar( $cfg, $grupo['numero'] );

	echo "\n== Parte C · Comprar sin sesión ==\n";
	$base = array( 'asde_action' => 'tienda_invitado_comprar', 'grupo' => $g_id, 'clave' => $clave, 'sello' => $sello(), 'nombre' => 'Marta Invitada', 'email' => 'marta@example.invalid', 'telefono' => '600 111 222', 'forma_pago' => 'bizum', 'cant' => array( $v_taza['Única'] => '2' ) );
	list( $dest, $h ) = $enviar( $base, 'asde_tienda_invitado_comprar' );
	$p1 = PedidosTable::obtener( $max_id( PedidosTable::table() ) );
	$ok( $p1 && '' === $p1['socio'] && 10.0 === $p1['total'] && 'bizum' === $p1['forma_pago'] && str_contains( $dest, 'pedido=' . $p1['id'] ) && str_contains( $dest, 'nuevo=1' ), 'pedido de 2 tazas a 5 € = 10 €, sin socio, y se manda a la página del pedido' );
	$ok( 2 === $existencias( $v_taza['Única'] ), 'las existencias bajan (4 → 2)' );
	$inv = PedidosTable::invitado( $p1 );
	$ok( $inv && 'Marta Invitada' === $inv['nombre'] && 'marta@example.invalid' === $inv['email'] && '600 111 222' === $inv['telefono'], 'los datos de quien compra se guardan y se pueden leer' );
	$crudo = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT invitado_enc FROM %i WHERE id = %d', PedidosTable::table(), $p1['id'] ) ); // phpcs:ignore WordPress.DB
	$ok( '' !== $crudo && ! str_contains( $crudo, 'Marta' ) && ! str_contains( $crudo, 'example.invalid' ), 'en la base de datos están cifrados: ni el nombre ni el email se leen' );
	$filas = array_values( array_filter( RegistroEmails::listado(), static fn( $f ) => (int) $f['id'] > $email_antes && RegistroEmails::TIENDA === $f['tipo'] ) );
	$ok( 1 === count( $filas ) && str_contains( $filas[0]['asunto'], 'Pedido recibido' ) && '' === $filas[0]['login'] && ! str_contains( (string) $filas[0]['destino'] . (string) $filas[0]['datos'], 'marta@example.invalid' ), 'sale el email de pedido recibido, y el registro lo guarda sin el email completo' );
	$vista = RegistroEmails::vista_previa( $filas[0] );
	$ok( str_contains( $vista, PedidosTable::numero( $p1 ) ) && str_contains( $vista, 'pedido=' . $p1['id'] ) && str_contains( $vista, '600123456' ), 'el email lleva el nº de pedido, cómo pagar y el enlace personal' );

	echo "\n== Parte D · Lo que no se deja comprar ==\n";
	$antes = $p1['id'];
	list( , $h ) = $enviar( array( 'sello' => $sello(), 'cant' => array( $v_panuelo['A'] => '1' ) ) + $base, 'asde_tienda_invitado_comprar' );
	$ok( $max_id( PedidosTable::table() ) === $antes && 10 === $existencias( $v_panuelo['A'] ) && str_contains( $h, 'ya no está disponible' ), 'un producto «solo socios» mandado a mano → rechazado y sin tocar las existencias' );
	list( , $h ) = $enviar( array( 'sello' => $sello(), 'forma_pago' => 'fisico' ) + $base, 'asde_tienda_invitado_comprar' );
	$ok( $max_id( PedidosTable::table() ) === $antes && 2 === $existencias( $v_taza['Única'] ) && str_contains( $h, 'solo está disponible para socios' ), '«en físico» → rechazado' );
	list( , $h ) = $enviar( array( 'sello' => $sello(), 'email' => 'esto no es un email' ) + $base, 'asde_tienda_invitado_comprar' );
	$ok( $max_id( PedidosTable::table() ) === $antes && str_contains( $h, 'email válido' ), 'sin un email válido → no' );
	list( , $h ) = $enviar( array( 'sello' => $sello(), 'cant' => array( $v_llavero['Única'] => '3' ) ) + $base, 'asde_tienda_invitado_comprar' );
	$ok( $max_id( PedidosTable::table() ) === $antes && 20 === $existencias( $v_llavero['Única'] ) && str_contains( $h, 'solo se pueden pedir 2' ), 'el máximo por persona vale por pedido (3 llaveros > 2)' );
	list( $dest, $h ) = $enviar( array( 'sello' => $sello(), 'web' => 'http://spam.invalid' ) + $base, 'asde_tienda_invitado_comprar' );
	$ok( $max_id( PedidosTable::table() ) === $antes && '' === $dest, 'el campo trampa relleno → ningún pedido' );
	list( $dest, $h ) = $enviar( array( 'sello' => $sello( 0 ) ) + $base, 'asde_tienda_invitado_comprar' );
	$ok( $max_id( PedidosTable::table() ) === $antes && '' === $dest, 'enviado en menos de 3 segundos → ningún pedido' );
	list( $dest, $h ) = $enviar( $base, 'otra_cosa' );
	$ok( $max_id( PedidosTable::table() ) === $antes && '' === $dest && str_contains( $h, 'ha caducado' ), 'sin el nonce bueno → ningún pedido' );
	list( , $h ) = $enviar( array( 'sello' => $sello(), 'cant' => array( $v_taza['Única'] => '9' ) ) + $base, 'asde_tienda_invitado_comprar' );
	$ok( $max_id( PedidosTable::table() ) === $antes && 2 === $existencias( $v_taza['Única'] ), 'pedir más de lo que hay → no, sin reservar nada' );

	echo "\n== Parte E · La página personal del pedido ==\n";
	$get = array( 'pedido' => (string) $p1['id'], 't' => Enlace::token( $p1 ) );
	$h   = $ver( $get );
	$ok( str_contains( $h, PedidosTable::numero( $p1 ) ) && str_contains( $h, 'Cómo pagarlo' ) && str_contains( $h, '600123456' ) && str_contains( $h, 'name="asde_action" value="tienda_invitado_cancelar"' ), 'con su enlace ve el pedido, cómo pagarlo y puede cancelarlo' );
	$ok( str_contains( $ver( array( 'pedido' => (string) $p1['id'], 't' => 'falso' ) ), 'no es válido' ) && str_contains( $ver( array( 'pedido' => '999999', 't' => 'x' ) ), 'no es válido' ), 'con un token falso o un pedido que no existe, nada' );
	$otro = $p1;
	$otro['id'] = $p1['id'] + 1;
	$ok( ! Enlace::token_valido( $otro, Enlace::token( $p1 ) ), 'el token de un pedido no vale para otro' );
	list( $dest, ) = $enviar( array( 'asde_action' => 'tienda_invitado_cancelar', 'pedido' => (string) $p1['id'], 't' => 'falso' ), 'asde_tienda_invitado_cancelar', array() );
	$ok( null === PedidosTable::por_id( $p1['id'] )['cancelado_en'], 'cancelar con un token falso no hace nada' );
	list( $dest, ) = $enviar( array( 'asde_action' => 'tienda_invitado_cancelar', 'pedido' => (string) $p1['id'], 't' => Enlace::token( $p1 ) ), 'asde_tienda_invitado_cancelar' );
	$q = PedidosTable::por_id( $p1['id'] );
	$ok( null !== $q['cancelado_en'] && 4 === $existencias( $v_taza['Única'] ), 'cancela su pedido y las tazas vuelven a las existencias (2 → 4)' );
	$enviar( array( 'asde_action' => 'tienda_invitado_cancelar', 'pedido' => (string) $p1['id'], 't' => Enlace::token( $p1 ) ), 'asde_tienda_invitado_cancelar' );
	$ok( 4 === $existencias( $v_taza['Única'] ), 'cancelarlo otra vez no devuelve dos veces' );

	echo "\n== Parte F · Tesorería y los pagos de quien no tiene sesión ==\n";
	$r2 = Servicio::comprar_invitado( array( 'nombre' => 'Luis Visitante', 'email' => 'luis@example.invalid', 'telefono' => '', 'idioma' => 'es' ), array( $v_taza['Única'] => 1, $v_llavero['Única'] => 2 ), 'Para regalo', 'transferencia' );
	$p2 = PedidosTable::por_id( (int) $r2 );
	$ok( ! is_wp_error( $r2 ) && 9.0 === $p2['total'] && 3 === $existencias( $v_taza['Única'] ), 'otro pedido por transferencia: 5 € + 2 × 2 € = 9 €' );
	$como( $u_teso );
	$ok( 'Luis Visitante (sin sesión)' === Servicio::comprador( $p2 ), 'en la gestión se ve quién es, marcado como compra sin sesión' );
	$_GET = array( 'pedido' => (string) $p2['id'] );
	$det  = ( new TiendaGestionView() )->render();
	$_GET = array();
	$ok( str_contains( $det, 'Luis Visitante' ) && str_contains( $det, 'luis@example.invalid' ), 'el detalle enseña su email a la administración' );
	$_POST = array( 'asde_action' => 'uniforme_pago', 'pedido' => (string) $p2['id'], 'metodo' => 'ingreso', 'fecha' => wp_date( 'Y-m-d' ), 'importe' => '9', '_wpnonce' => wp_create_nonce( 'asde_uniforme_' . $p2['id'] ) );
	$_REQUEST = $_POST;
	try {
		( new TiendaGestionView() )->handle_post();
	} catch ( RuntimeException $e ) {
		// Redirige.
	}
	$_POST = array();
	$_REQUEST = array();
	$ok( null !== PedidosTable::por_id( $p2['id'] )['pago_en'], 'Tesorería marca el pago' );
	$filas = array_values( array_filter( RegistroEmails::listado(), static fn( $f ) => (int) $f['id'] > $email_antes && RegistroEmails::TIENDA === $f['tipo'] ) );
	$asuntos = implode( '|', array_column( $filas, 'asunto' ) );
	$ok( str_contains( $asuntos, 'Pago recibido' ), 'y a quien compró le llega el aviso de pago recibido' );
	$como( 0 );

	echo "\n== Parte G · Caducidad y borrado ==\n";
	$r3 = Servicio::comprar_invitado( array( 'nombre' => 'Eva Olvidadiza', 'email' => 'eva@example.invalid', 'telefono' => '', 'idioma' => 'es' ), array( $v_taza['Única'] => 1 ), '', 'bizum' );
	$p3 = PedidosTable::por_id( (int) $r3 );
	$tras = $existencias( $v_taza['Única'] );
	$wpdb->update( PedidosTable::table(), array( 'creado' => gmdate( 'Y-m-d H:i:s', time() - 20 * DAY_IN_SECONDS ) ), array( 'id' => $p3['id'] ) ); // phpcs:ignore WordPress.DB
	$wpdb->update( PedidosTable::table(), array( 'creado' => gmdate( 'Y-m-d H:i:s', time() - 20 * DAY_IN_SECONDS ) ), array( 'id' => $p2['id'] ) ); // phpcs:ignore WordPress.DB
	$socio_antiguo = PedidosTable::crear( '990001', array( 'lobatos' ), false, array( 'camisa' => null, 'insignias' => array(), 'productos' => array() ), 0.0, false, '' );
	$wpdb->update( PedidosTable::table(), array( 'creado' => gmdate( 'Y-m-d H:i:s', time() - 40 * DAY_IN_SECONDS ) ), array( 'id' => $socio_antiguo ) ); // phpcs:ignore WordPress.DB
	$cfg0                   = $cfg;
	$cfg0['dias_sin_pagar'] = 0;
	Config::guardar( $cfg0, $grupo['numero'] );
	$ok( 0 === Servicio::cancelar_invitados_sin_pagar() && null === PedidosTable::por_id( $p3['id'] )['cancelado_en'], 'con «0 días» no se cancela nada nunca' );
	Config::guardar( $cfg, $grupo['numero'] );
	$n = Servicio::cancelar_invitados_sin_pagar();
	$ok( 1 === $n && null !== PedidosTable::por_id( $p3['id'] )['cancelado_en'] && $tras + 1 === $existencias( $v_taza['Única'] ), 'a los 14 días sin pagar, la compra sin sesión se cancela y devuelve las existencias' );
	$ok( null === PedidosTable::por_id( $p2['id'] )['cancelado_en'], 'la que ya estaba pagada no se toca' );
	$ok( null === PedidosTable::por_id( $socio_antiguo )['cancelado_en'], 'ni los pedidos de socios sin pagar (eso se decide en otra fase)' );
	$wpdb->update( PedidosTable::table(), array( 'cancelado_en' => gmdate( 'Y-m-d H:i:s', time() - 100 * DAY_IN_SECONDS ) ), array( 'id' => $p1['id'] ) ); // phpcs:ignore WordPress.DB
	$wpdb->update( PedidosTable::table(), array( 'entregado_en' => gmdate( 'Y-m-d H:i:s', time() - 10 * DAY_IN_SECONDS ) ), array( 'id' => $p2['id'] ) ); // phpcs:ignore WordPress.DB
	$limpiados = PedidosTable::purgar_invitados();
	$ok( 1 === $limpiados && null === PedidosTable::invitado( PedidosTable::por_id( $p1['id'] ) ) && null !== PedidosTable::invitado( PedidosTable::por_id( $p2['id'] ) ), 'a los 90 días de cancelar se borran los datos personales; el entregado hace 10 días conserva los suyos' );
	$ok( str_contains( $ver( array( 'pedido' => (string) $p1['id'], 't' => Enlace::token( $p1 ) ) ), 'no es válido' ), 'y su enlace personal deja de valer');
	$ok( 10.0 === PedidosTable::por_id( $p1['id'] )['total'], 'el pedido queda (importes y cantidades); lo personal, no' );

	echo "\n== Parte H · Configuración: interruptor y enlace nuevo ==\n";
	$como( $u_coord );
	$_POST = array( 'asde_action' => 'tienda_enlace_renovar', '_wpnonce' => wp_create_nonce( 'asde_tienda_admin' ) );
	$_REQUEST = $_POST;
	try {
		( new TiendaConfigView() )->handle_post();
	} catch ( RuntimeException $e ) {
		// Redirige.
	}
	$_POST = array();
	$_REQUEST = array();
	$ok( ! Enlace::valida( $grupo, $clave ) && Enlace::valida( $grupo, Enlace::clave( $grupo ) ), 'Coordinación crea un enlace nuevo: el anterior deja de valer' );
	$h = ( new TiendaConfigView() )->render();
	$ok( str_contains( $h, 'Tienda pública' ) && str_contains( $h, 'name="publica"' ) && str_contains( $h, Enlace::clave( $grupo ) ), 'la configuración enseña el interruptor y el enlace' );
	$como( 0 );
} finally {
	remove_filter( 'wp_redirect', $redirigir, 1 );
	remove_filter( 'send_auth_cookies', '__return_false' );
	remove_filter( 'pre_wp_mail', '__return_true' );
	$_POST = array();
	$_GET  = array();
	wp_using_ext_object_cache( $cache_externa );
	wp_set_current_user( 0 );

	$lista = implode( ',', array_map( 'intval', $ids ) ) ?: '0';
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . PedidosTable::table() . ' WHERE id > %d', $ped_antes ) ); // phpcs:ignore WordPress.DB
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . VariantesTable::table() . ' WHERE id > %d', $var_antes ) ); // phpcs:ignore WordPress.DB
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . ProductosTable::table() . ' WHERE id > %d', $prod_antes ) ); // phpcs:ignore WordPress.DB
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . RegistroEmails::table() . ' WHERE id > %d', $email_antes ) ); // phpcs:ignore WordPress.DB
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Logger::table() . " WHERE id > %d AND ( user_id IN ($lista) OR objeto IN ( 'uniforme', 'tienda' ) OR ( objeto = 'grupo' AND accion = 'tienda_enlace_renovado' ) )", $log_antes ) ); // phpcs:ignore WordPress.DB
	foreach ( $ids as $uid ) {
		Ambito::olvidar( (int) $uid );
		wp_delete_user( (int) $uid );
	}
	if ( null === $cfg_antes ) {
		delete_option( $opcion_cfg );
	} else {
		update_option( $opcion_cfg, $cfg_antes, false );
	}
	if ( null === $enlaces_antes ) {
		delete_option( Enlace::OPCION );
	} else {
		update_option( Enlace::OPCION, $enlaces_antes, false );
	}
	Grupo::forzar( null );

	$quedan = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users} WHERE user_login LIKE 'prueba-pub-%'" ); // phpcs:ignore WordPress.DB
	$ok( 0 === $quedan && 0 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . PedidosTable::table() . ' WHERE id > ' . (int) $ped_antes ) && 0 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . ProductosTable::table() . ' WHERE id > ' . (int) $prod_antes ) && get_option( $opcion_cfg, null ) == $cfg_antes, 'limpieza: sin usuarios, pedidos ni productos de la prueba; configuración y enlace como estaban' ); // phpcs:ignore WordPress.DB
}

echo $fails ? "\n$fails FALLOS\n" : "\nTODO OK (nada tocado en el CRM real; el sitio queda como estaba)\n";
