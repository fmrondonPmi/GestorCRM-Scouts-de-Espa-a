<?php
/**
 * Pruebas de la v0.39, fase 2 (entorno LOCAL, doc 48): el catálogo de la tienda — productos, variantes,
 * existencias, siembra desde Uniformes, carga desde CSV y la pantalla «Productos» de la administración.
 * Ejecutar desde app/public del site Local:
 *
 *   wp eval-file <ruta completa>/wp-content/plugins/asde-area-privada/tests/tienda-catalogo.php
 *
 * No usa el CRM (solo cuentas de prueba con sus roles). Al terminar se borra lo creado (productos, variantes,
 * usuarios, registros) y se restaura la configuración de la tienda del grupo.
 */
defined( 'ABSPATH' ) || exit;

require_once ABSPATH . 'wp-admin/includes/user.php';
require_once ABSPATH . 'wp-admin/includes/file.php';

use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Auth\Ambito;
use AsdeBalears\AreaPrivada\Domain\Grupo;
use AsdeBalears\AreaPrivada\Domain\Grupos;
use AsdeBalears\AreaPrivada\Frontend\Pages;
use AsdeBalears\AreaPrivada\Frontend\Router;
use AsdeBalears\AreaPrivada\Frontend\Views\TiendaProductosView;
use AsdeBalears\AreaPrivada\Install\Roles;
use AsdeBalears\AreaPrivada\Tienda\Config;
use AsdeBalears\AreaPrivada\Tienda\ImportadorProductos;
use AsdeBalears\AreaPrivada\Tienda\ProductosTable;
use AsdeBalears\AreaPrivada\Tienda\Sembrado;
use AsdeBalears\AreaPrivada\Tienda\VariantesTable;
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
foreach ( get_users( array( 'search' => 'prueba-cat-*', 'search_columns' => array( 'user_login' ) ) ) as $u ) {
	exit( 'Ya existe el usuario ' . $u->user_login . " (¿una ejecución anterior interrumpida?). Bórralo antes de repetir la prueba.\n" );
}
wp_set_current_user( 0 );
Grupo::forzar( $grupo );

// --- Lo que se restaura al final ---------------------------------------------
$max_id    = static fn( string $t ) => (int) $wpdb->get_var( "SELECT COALESCE(MAX(id), 0) FROM $t" ); // phpcs:ignore WordPress.DB
$log_antes = $max_id( Logger::table() );
$prod_antes = $max_id( ProductosTable::table() );
$var_antes  = $max_id( VariantesTable::table() );
$opcion_cfg = 'asde_ap_tienda_' . $grupo['numero'];
$cfg_antes  = get_option( $opcion_cfg, null );

$ids   = array();
$crear = static function ( string $login, array $roles ) use ( &$ids, $grupo ): int {
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
	$ids[ $login ] = $id;
	return $id;
};
$u_coord   = $crear( 'prueba-cat-coord', array( Roles::SCOUTER, Roles::COORDINACION ) );
$u_teso    = $crear( 'prueba-cat-teso', array( Roles::SCOUTER, Roles::TESORERIA ) );
$u_secre   = $crear( 'prueba-cat-secre', array( Roles::SCOUTER, Roles::SECRETARIA ) );
$u_scouter = $crear( 'prueba-cat-scouter', array( Roles::SCOUTER ) );
$u_fam     = $crear( 'prueba-cat-fam', array( Roles::TUTOR ) );

$como = static function ( int $uid ): void {
	wp_set_current_user( $uid );
	Ambito::olvidar( $uid );
};
add_filter( 'send_auth_cookies', '__return_false' );
add_filter( 'wp_redirect', static function ( $destino ) {
	throw new RuntimeException( 'asde-redirect:' . $destino );
}, 1 );
/** Envía un formulario a la vista como lo haría el navegador y devuelve los avisos. */
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
	return \AsdeBalears\AreaPrivada\Frontend\Notices::render();
};
$pintar = static function ( array $get = array() ) {
	$_GET = $get;
	try {
		return ( new TiendaProductosView() )->render();
	} finally {
		$_GET = array();
	}
};
$csv = static function ( string $contenido ): string {
	$ruta = wp_tempnam( 'prueba-cat' );
	file_put_contents( $ruta, $contenido ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	return $ruta;
};

try {
	echo "== Parte A · Validar productos y variantes ==\n";
	$v = ProductosTable::validar( array( 'nombre' => '  PruebaCat Taza ', 'precio' => '6,50', 'visibilidad' => 'todos', 'limite_persona' => '3' ) );
	$ok( is_array( $v ) && 'PruebaCat Taza' === $v['nombre'] && 6.5 === $v['precio'] && 3 === $v['limite_persona'] && null === $v['precio_socio'] && 1 === $v['activo'], 'un producto correcto se limpia: nombre sin espacios, precio 6,50, límite 3, sin precio de socio' );
	$ok( is_wp_error( ProductosTable::validar( array( 'nombre' => '' ) ) ), 'sin nombre → error' );
	$ok( is_wp_error( ProductosTable::validar( array( 'nombre' => 'X', 'precio' => 'barato' ) ) ) && is_wp_error( ProductosTable::validar( array( 'nombre' => 'X', 'precio' => '10000' ) ) ), 'precio que no es un importe o pasa de 9999,99 → error' );
	$ok( is_wp_error( ProductosTable::validar( array( 'nombre' => 'X', 'visibilidad' => 'amigos' ) ) ) && is_wp_error( ProductosTable::validar( array( 'nombre' => 'X', 'limite_persona' => 'mucho' ) ) ), 'visibilidad desconocida o límite que no es un número → error' );
	$ok( null === ProductosTable::validar( array( 'nombre' => 'X', 'precio' => '' ) )['precio'], 'precio vacío = por confirmar (null)' );
	$vv = VariantesTable::validar( array( 'etiqueta' => '', 'existencias' => '' ) );
	$ok( 'Única' === $vv['etiqueta'] && 0 === $vv['existencias'] && 0 === $vv['ilimitado'], 'variante sin etiqueta = «Única», sin existencias = 0' );
	$ok( 1 === VariantesTable::validar( array( 'etiqueta' => 'M', 'existencias' => 'ilimitado' ) )['ilimitado'] && is_wp_error( VariantesTable::validar( array( 'etiqueta' => 'M', 'existencias' => '-3' ) ) ) && is_wp_error( VariantesTable::validar( array( 'etiqueta' => 'M', 'existencias' => '100000' ) ) ), '«ilimitado» vale; negativas o pasar de 99999 → error' );

	echo "\n== Parte B · Crear, ver y archivar ==\n";
	$taza = ProductosTable::crear( ProductosTable::validar( array( 'nombre' => 'PruebaCat Taza', 'precio' => '6,50', 'visibilidad' => 'todos' ) ) );
	$cam  = ProductosTable::crear( ProductosTable::validar( array( 'nombre' => 'PruebaCat Camiseta', 'precio' => '12', 'precio_socio' => '10', 'visibilidad' => 'socios', 'categoria' => 'PruebaCat Ropa' ) ) );
	$v_taza = VariantesTable::crear( $taza, VariantesTable::validar( array( 'etiqueta' => 'Única', 'existencias' => '5' ) ) );
	$v_s    = VariantesTable::crear( $cam, VariantesTable::validar( array( 'etiqueta' => 'S', 'existencias' => '3' ) ) );
	$v_m    = VariantesTable::crear( $cam, VariantesTable::validar( array( 'etiqueta' => 'M', 'existencias' => 'ilimitado' ) ) );
	$ok( $taza > $prod_antes && ProductosTable::obtener( $taza )['nombre'] === 'PruebaCat Taza' && count( VariantesTable::de_producto( $cam ) ) === 2, 'producto y variantes creados y leídos' );
	$ver = static fn( bool $socio ) => array_column( array_filter( ProductosTable::visibles( $socio ), static fn( $p ) => str_starts_with( $p['nombre'], 'PruebaCat' ) ), 'nombre' );
	$ok( array( 'PruebaCat Taza' ) === array_values( $ver( false ) ), 'quien no es socio solo ve lo de «todos»: el producto «solo socios» no sale' );
	$ok( 2 === count( $ver( true ) ), 'un socio con sesión ve los dos' );
	$ok( ! ProductosTable::visible_para( ProductosTable::obtener( $cam ), false ) && ProductosTable::visible_para( ProductosTable::obtener( $cam ), true ) && ProductosTable::visible_para( ProductosTable::obtener( $taza ), false ), 'visible_para(): socios solo para socios; todos para todos' );
	$pc = ProductosTable::obtener( $cam );
	$ok( 10.0 === ProductosTable::precio_para( $pc, true ) && 12.0 === ProductosTable::precio_para( $pc, false ) && 6.5 === ProductosTable::precio_para( ProductosTable::obtener( $taza ), true ), 'precio: el de socio para socios si lo hay; el general para los demás' );
	ProductosTable::archivar( $taza );
	$ok( ! in_array( 'PruebaCat Taza', $ver( true ), true ) && null !== ProductosTable::obtener( $taza ) && in_array( $taza, array_column( ProductosTable::todos( true ), 'id' ), true ), 'archivado: ya no se vende (ni a socios) pero sigue en el catálogo de la administración' );
	ProductosTable::archivar( $taza, false );
	$ok( in_array( 'PruebaCat Taza', $ver( false ), true ), 'recuperado: vuelve a venderse' );
	$ok( 'PruebaCat Taza' === ProductosTable::por_nombre( '  pruebacat   TAZA ' )['nombre'] && in_array( 'PruebaCat Ropa', ProductosTable::categorias(), true ), 'búsqueda por nombre sin mayúsculas ni espacios de más; categorías ya usadas' );

	echo "\n== Parte C · Existencias ==\n";
	$ok( VariantesTable::reservar( $v_taza, 2 ) && 3 === VariantesTable::obtener( $v_taza )['existencias'], 'reservar 2 de 5 → quedan 3' );
	$ok( ! VariantesTable::reservar( $v_taza, 4 ) && 3 === VariantesTable::obtener( $v_taza )['existencias'], 'reservar más de las que quedan → falla y no cambia nada' );
	$ok( VariantesTable::reservar( $v_taza, 3 ) && ! VariantesTable::reservar( $v_taza, 1 ) && 0 === VariantesTable::obtener( $v_taza )['existencias'], 'agotarlas del todo; la siguiente reserva falla (nunca queda por debajo de 0)' );
	$ok( ! VariantesTable::hay( VariantesTable::obtener( $v_taza ) ) && VariantesTable::hay( VariantesTable::obtener( $v_s ), 3 ) && ! VariantesTable::hay( VariantesTable::obtener( $v_s ), 4 ) && VariantesTable::hay( VariantesTable::obtener( $v_m ), 500 ), 'hay(): agotada no; 3 de 3 sí, 4 de 3 no; la ilimitada siempre' );
	$ok( VariantesTable::reservar( $v_m, 7 ) && 1 === VariantesTable::obtener( $v_m )['ilimitado'], 'reservar en una ilimitada vale y no la cuenta' );
	$ok( VariantesTable::devolver( $v_taza, 2 ) && 2 === VariantesTable::obtener( $v_taza )['existencias'], 'devolver 2 al cancelar → 2' );
	VariantesTable::devolver( $v_m, 5 );
	$ok( 0 === VariantesTable::obtener( $v_m )['existencias'], 'devolver a una ilimitada no suma' );
	$ok( VariantesTable::ajustar( $v_s, 4, 'llegó el pedido' ) && 7 === VariantesTable::obtener( $v_s )['existencias'] && VariantesTable::ajustar( $v_s, -7, 'recuento' ) && 0 === VariantesTable::obtener( $v_s )['existencias'], 'ajustar +4 y −7 → 7 y 0' );
	$ok( ! VariantesTable::ajustar( $v_s, -1, 'de más' ) && 0 === VariantesTable::obtener( $v_s )['existencias'] && ! VariantesTable::ajustar( $v_m, 3, 'ilimitada' ) && ! VariantesTable::ajustar( $v_s, 0, 'nada' ), 'ajustar por debajo de 0, a una ilimitada o en 0 → no cambia' );
	$ok( ! VariantesTable::ajustar( $v_s, VariantesTable::MAX_EXISTENCIAS + 1, 'enorme' ), 'ajustar por encima del máximo → no cambia' );
	$ok( (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Logger::table() . " WHERE id > %d AND accion = 'tienda_existencias'", $log_antes ) ) === 2, 'los ajustes quedan en la auditoría con su motivo' ); // phpcs:ignore WordPress.DB
	$ok( null === VariantesTable::total( VariantesTable::de_producto( $cam ) ) && 2 === VariantesTable::total( VariantesTable::de_producto( $taza ) ), 'total de existencias: null si hay alguna ilimitada; si no, la suma' );

	echo "\n== Parte D · Siembra desde Uniformes ==\n";
	$cfg                = Config::defaults();
	$cfg['precio_camisa'] = 27.0;
	$cfg['insignias']   = array(
		'pcat1' => array( 'nombre' => 'PruebaCat Insignia', 'lugar' => 'Brazo', 'precio' => 2.5, 'imagen' => 0, 'activa' => true ),
		'pcat2' => array( 'nombre' => 'PruebaCat Retirada', 'lugar' => '', 'precio' => null, 'imagen' => 0, 'activa' => false ),
	);
	Config::guardar( $cfg, $grupo['numero'] );
	$ya_camisa = ProductosTable::por_ref( 'camisa' );
	if ( $ya_camisa ) {
		// La camisa sembrada de verdad se aparta (cambiándole la referencia) y se devuelve al final.
		$wpdb->update( ProductosTable::table(), array( 'ref' => 'camisa-apartada' ), array( 'id' => $ya_camisa['id'] ) );
	}
	$n1 = Sembrado::grupo();
	$camisa = ProductosTable::por_ref( 'camisa' );
	$ok( $n1 >= 3 && $camisa && 27.0 === $camisa['precio'] && ProductosTable::VIS_SOCIOS === $camisa['visibilidad'] && 1 === $camisa['requiere_proveedor'] && 1 === $camisa['sale_inventario'], 'la camisa sembrada: 27 € (el de la configuración), solo socios, se pide al proveedor' );
	$vars = VariantesTable::de_producto( $camisa['id'] );
	$ok( 23 === count( $vars ) && VariantesTable::por_ref( $camisa['id'], 'femenina|3XL' ) && 1 === VariantesTable::por_ref( $camisa['id'], 'masculina|M' )['ilimitado'], 'una variante por modelo y talla (13 + 10) y sin límite' );
	$ins = ProductosTable::por_ref( 'insignia:pcat1' );
	$ret = ProductosTable::por_ref( 'insignia:pcat2' );
	$ok( $ins && 2.5 === $ins['precio'] && 'Dónde va: Brazo' === $ins['descripcion'] && $ret && null === $ret['precio'] && 0 === $ret['activo'], 'las insignias, con su precio, su dónde va y la retirada archivada (precio por confirmar)' );
	ProductosTable::actualizar( $camisa['id'], array( 'precio' => 99.0 ) );
	$n2 = Sembrado::grupo();
	$ok( 0 === $n2 && 99.0 === ProductosTable::por_ref( 'camisa' )['precio'] && 23 === count( VariantesTable::de_producto( $camisa['id'] ) ), 'sembrar otra vez no crea nada ni pisa lo que la administración cambió' );

	echo "\n== Parte E · Cargar desde CSV ==\n";
	$cab = "\xEF\xBB\xBFProducto;Categoría;Visibilidad;Precio;Precio de socio;Variante;Existencias\r\n";
	$f1  = $csv( $cab . "PruebaCat Gorra;Ropa;socios;8,50;7;S;10\r\nPruebaCat Gorra;;;;;M;4\r\nPruebaCat Pegatina;Regalos;todos;1;;;ilimitado\r\n" );
	$l1  = ImportadorProductos::leer( $f1 );
	$ok( is_array( $l1 ) && 3 === count( $l1['filas'] ) && array( 2, 3, 4 ) === array_keys( $l1['filas'] ), 'se lee el CSV (BOM, «;» y tildes): 3 filas, numeradas desde la 2' );
	$r1 = ImportadorProductos::importar( $l1['filas'] );
	$gorra = ProductosTable::por_nombre( 'PruebaCat Gorra' );
	$ok( 2 === $r1['productos_creados'] && 3 === $r1['variantes_creadas'] && ! $r1['errores'] && $gorra && 8.5 === $gorra['precio'] && 7.0 === $gorra['precio_socio'] && ProductosTable::VIS_SOCIOS === $gorra['visibilidad'], 'primera carga: 2 productos y 3 variantes; la gorra a 8,50 € (7 para socios), solo socios' );
	$ok( 10 === VariantesTable::por_etiqueta( $gorra['id'], 'S' )['existencias'] && 4 === VariantesTable::por_etiqueta( $gorra['id'], 'm' )['existencias'], 'existencias de cada variante (la etiqueta se busca sin mayúsculas)' );
	$peg = ProductosTable::por_nombre( 'PruebaCat Pegatina' );
	$ok( $peg && 'Única' === VariantesTable::de_producto( $peg['id'] )[0]['etiqueta'] && 1 === VariantesTable::de_producto( $peg['id'] )[0]['ilimitado'], 'una fila sin variante crea la «Única», aquí ilimitada' );
	// Segunda carga: sustituye. Celdas vacías no borran.
	$f2 = $csv( $cab . "pruebacat gorra;;;9;;S;6\r\n" );
	$r2 = ImportadorProductos::importar( ImportadorProductos::leer( $f2 )['filas'] );
	$gorra = ProductosTable::por_nombre( 'PruebaCat Gorra' );
	$ok( 0 === $r2['productos_creados'] && 1 === $r2['productos_actualizados'] && 1 === $r2['variantes_actualizadas'] && 9.0 === $gorra['precio'] && 7.0 === $gorra['precio_socio'] && 'PruebaCat Ropa' !== $gorra['categoria'] && 'Ropa' === $gorra['categoria'], 'segunda carga: el producto se actualiza solo con las celdas rellenas (precio 9; lo demás se queda)' );
	$ok( 6 === VariantesTable::por_etiqueta( $gorra['id'], 'S' )['existencias'] && 4 === VariantesTable::por_etiqueta( $gorra['id'], 'M' )['existencias'], 'las existencias del fichero sustituyen a las de esa variante y las otras no se tocan' );
	// Sumar.
	$r3 = ImportadorProductos::importar( ImportadorProductos::leer( $csv( $cab . "PruebaCat Gorra;;;;;S;5\r\n" ) )['filas'], true );
	$ok( 11 === VariantesTable::por_etiqueta( $gorra['id'], 'S' )['existencias'] && 1 === $r3['variantes_actualizadas'], 'con «sumar», las existencias se suman a las actuales (6 + 5)' );
	$r3b = ImportadorProductos::importar( ImportadorProductos::leer( $csv( $cab . "PruebaCat Gorra;;;;;S;\r\n" ) )['filas'] );
	$ok( 11 === VariantesTable::por_etiqueta( $gorra['id'], 'S' )['existencias'], 'una celda de existencias vacía no cambia las existencias' );
	// Errores.
	$r4 = ImportadorProductos::importar( ImportadorProductos::leer( $csv( $cab . "PruebaCat Mala;;amigos;5;;;1\r\nPruebaCat Mala2;;todos;gratis;;;1\r\nPruebaCat Mala3;;todos;5;;;-4\r\n;;todos;5;;;1\r\nPruebaCat Buena;;todos;5;;;1\r\n" ) )['filas'] );
	$ok( 4 === count( $r4['errores'] ) && 1 === $r4['productos_creados'] && array( 2, 3, 4, 5 ) === array_column( $r4['errores'], 'fila' ) && null === ProductosTable::por_nombre( 'PruebaCat Mala' ), 'cuatro filas con error (visibilidad, precio, existencias, sin nombre) no crean nada; la buena sí; los errores llevan su nº de fila' );
	$ok( is_wp_error( ImportadorProductos::leer( $csv( "Nombre;Precio\r\nA;1\r\n" ) ) ) === false && is_wp_error( ImportadorProductos::leer( $csv( "Precio;Existencias\r\n1;2\r\n" ) ) ) && is_wp_error( ImportadorProductos::leer( $csv( '' ) ) ) && is_wp_error( ImportadorProductos::leer( $csv( "Producto\r\n" ) ) ), '«Nombre» vale por «Producto»; sin la columna Producto, vacío o solo con cabecera → no se carga nada' );
	$ok( is_wp_error( ImportadorProductos::leer( $csv( "Producto;Precio\0\r\nA;1" ) ) ), 'un fichero binario no se acepta' );
	$ej = ImportadorProductos::leer( $csv( ImportadorProductos::ejemplo() ) );
	$ok( is_array( $ej ) && count( $ej['filas'] ) >= 4, 'el CSV de ejemplo se puede leer con el mismo importador' );
	$f_formula = ImportadorProductos::importar( ImportadorProductos::leer( $csv( $cab . "\"=SUMA(1;1)\";;todos;3;;;1\r\n" ) )['filas'] );
	$ok( 1 === $f_formula['productos_creados'] && null !== ProductosTable::por_nombre( '=SUMA(1;1)' ), 'un nombre que empieza por «=» se guarda como texto (no es una fórmula en la web)' );

	echo "\n== Parte F · La pantalla «Productos» ==\n";
	$ok( isset( Pages::DEFINICIONES['tienda_productos'] ) && isset( ( new ReflectionClass( Router::class ) )->getConstant( 'VIEWS' )['tienda_productos'] ) && ! empty( Pages::id( 'tienda_productos' ) ), 'la página «Productos» existe, con su shortcode y su vista' );
	$ok( get_role( Roles::SECRETARIA )->has_cap( 'asde_tienda_admin' ) && get_role( Roles::COORDINACION )->has_cap( 'asde_tienda_admin' ) && get_role( Roles::TESORERIA )->has_cap( 'asde_tienda_admin' ) && ! get_role( Roles::SCOUTER )->has_cap( 'asde_tienda_admin' ) && ! get_role( Roles::TUTOR )->has_cap( 'asde_tienda_admin' ), 'asde_tienda_admin: Coordinación, Tesorería y Secretaría sí; scouter y familia no' );
	foreach ( array( 'Coordinación' => $u_coord, 'Tesorería' => $u_teso, 'Secretaría' => $u_secre ) as $quien => $uid ) {
		$como( $uid );
		$h = $pintar();
		$ok( str_contains( $h, 'PruebaCat Camiseta' ) && str_contains( $h, 'Añadir producto' ) && str_contains( $h, 'Cargar desde CSV' ), "$quien ve el catálogo con «Añadir producto» y «Cargar desde CSV»" );
	}
	foreach ( array( 'un scouter' => $u_scouter, 'una familia' => $u_fam ) as $quien => $uid ) {
		$como( $uid );
		$ok( ! str_contains( $pintar(), 'PruebaCat Camiseta' ) && str_contains( $pintar(), 'No tienes acceso' ), "$quien no entra en Productos" );
	}
	$como( $u_coord );
	$h = $pintar( array( 'categoria' => 'PruebaCat Ropa' ) );
	$ok( str_contains( $h, 'PruebaCat Camiseta' ) && ! str_contains( $h, 'PruebaCat Pegatina' ), 'el filtro por categoría' );
	$h = $pintar( array( 'q' => 'pegatina' ) );
	$ok( str_contains( $h, 'PruebaCat Pegatina' ) && ! str_contains( $h, 'PruebaCat Camiseta' ), 'el filtro por texto (sin mayúsculas)' );
	$h = $pintar( array( 'producto' => (string) $cam ) );
	$ok( str_contains( $h, 'name="asde_action" value="producto_guardar"' ) && str_contains( $h, 'value="PruebaCat Camiseta"' ) && str_contains( $h, 'name="v_ajuste[' . $v_s . ']"' ) && str_contains( $h, 'Archivar el producto' ), 'el formulario del producto: sus datos, el cambio de existencias de cada variante y «Archivar»' );
	$ok( str_contains( $pintar( array( 'nuevo' => '1' ) ), 'Nuevo producto' ) && str_contains( $pintar( array( 'importar' => '1' ) ), 'name="asde_action" value="productos_importar"' ) && str_contains( $pintar( array( 'producto' => '99999999' ) ), 'no está en la tienda' ), 'formulario nuevo, pantalla de carga y producto que no existe' );

	// Guardar un producto nuevo.
	$antes = $max_id( ProductosTable::table() );
	$av    = $enviar(
		new TiendaProductosView(),
		array(
			'asde_action'       => 'producto_guardar',
			'producto'          => '0',
			'nombre'            => 'PruebaCat Llavero',
			'precio'            => '3,5',
			'visibilidad'       => 'socios',
			'limite_persona'    => '2',
			'nueva_etiqueta_1'  => 'Azul',
			'nueva_existencias_1' => '12',
			'nueva_etiqueta_2'  => 'Rojo',
			'nueva_existencias_2' => '0',
		),
		'asde_tienda_producto_0'
	);
	$llavero = ProductosTable::por_nombre( 'PruebaCat Llavero' );
	$ok( $llavero && $llavero['id'] > $antes && 3.5 === $llavero['precio'] && 2 === $llavero['limite_persona'] && 2 === count( VariantesTable::de_producto( $llavero['id'] ) ) && str_contains( $av, 'guardado' ), 'guardar un producto nuevo con dos variantes (se crea y avisa)' );
	// Errores: no se guarda nada.
	$antes_n = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . ProductosTable::table() ); // phpcs:ignore WordPress.DB
	$av = $enviar( new TiendaProductosView(), array( 'asde_action' => 'producto_guardar', 'producto' => '0', 'nombre' => 'PruebaCat Rota', 'precio' => 'uno', 'visibilidad' => 'todos' ), 'asde_tienda_producto_0' );
	$ok( (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . ProductosTable::table() ) === $antes_n && null === ProductosTable::por_nombre( 'PruebaCat Rota' ) && str_contains( $av, 'precio' ), 'un precio que no vale: no se guarda nada y se explica' ); // phpcs:ignore WordPress.DB
	// Editar con cambio de existencias.
	$vl = VariantesTable::por_etiqueta( $llavero['id'], 'Azul' );
	$av = $enviar(
		new TiendaProductosView(),
		array(
			'asde_action'    => 'producto_guardar',
			'producto'       => (string) $llavero['id'],
			'nombre'         => 'PruebaCat Llavero',
			'precio'         => '4',
			'visibilidad'    => 'todos',
			'v_etiqueta'     => array( $vl['id'] => 'Azul', VariantesTable::por_etiqueta( $llavero['id'], 'Rojo' )['id'] => 'Rojo' ),
			'v_activa'       => array( $vl['id'] => '1', VariantesTable::por_etiqueta( $llavero['id'], 'Rojo' )['id'] => '1' ),
			'v_ajuste'       => array( $vl['id'] => '-5' ),
			'motivo_ajuste'  => 'regalos del campamento',
		),
		'asde_tienda_producto_' . $llavero['id']
	);
	$ok( 4.0 === ProductosTable::obtener( $llavero['id'] )['precio'] && ProductosTable::VIS_TODOS === ProductosTable::obtener( $llavero['id'] )['visibilidad'] && 7 === VariantesTable::obtener( $vl['id'] )['existencias'], 'editar: el precio y la visibilidad cambian y el ajuste −5 deja 7 existencias' );
	$av = $enviar(
		new TiendaProductosView(),
		array(
			'asde_action' => 'producto_guardar',
			'producto'    => (string) $llavero['id'],
			'nombre'      => 'PruebaCat Llavero',
			'visibilidad' => 'todos',
			'v_etiqueta'  => array( $vl['id'] => 'Azul' ),
			'v_activa'    => array( $vl['id'] => '1' ),
			'v_ajuste'    => array( $vl['id'] => '-50' ),
		),
		'asde_tienda_producto_' . $llavero['id']
	);
	$ok( 7 === VariantesTable::obtener( $vl['id'] )['existencias'] && str_contains( $av, 'solo hay 7' ), 'quitar más unidades de las que hay: no se guarda y dice cuántas hay' );
	$rojo = VariantesTable::por_etiqueta( $llavero['id'], 'Rojo' );
	$ok( 1 === VariantesTable::obtener( $rojo['id'] )['activo'], 'la variante «Rojo» sigue en venta' );
	$enviar(
		new TiendaProductosView(),
		array(
			'asde_action' => 'producto_guardar',
			'producto'    => (string) $llavero['id'],
			'nombre'      => 'PruebaCat Llavero',
			'visibilidad' => 'todos',
			'v_etiqueta'  => array( $vl['id'] => 'Azul', $rojo['id'] => 'Rojo' ),
			'v_activa'    => array( $vl['id'] => '1' ),
		),
		'asde_tienda_producto_' . $llavero['id']
	);
	$ok( 0 === VariantesTable::obtener( $rojo['id'] )['activo'] && ! VariantesTable::hay( VariantesTable::obtener( $rojo['id'] ) ), 'desmarcar «En venta» saca la variante de la venta' );
	// Archivar y recuperar con permisos.
	$enviar( new TiendaProductosView(), array( 'asde_action' => 'producto_archivar', 'producto' => (string) $llavero['id'] ), 'asde_tienda_producto_' . $llavero['id'] );
	$ok( 0 === ProductosTable::obtener( $llavero['id'] )['activo'], 'archivar desde la pantalla' );
	$enviar( new TiendaProductosView(), array( 'asde_action' => 'producto_recuperar', 'producto' => (string) $llavero['id'] ), 'asde_tienda_producto_' . $llavero['id'] );
	$ok( 1 === ProductosTable::obtener( $llavero['id'] )['activo'], 'recuperar desde la pantalla' );

	echo "\n== Parte G · La descarga del ejemplo y los datos de otro grupo ==\n";
	$ej_csv = ImportadorProductos::ejemplo();
	$ok( str_starts_with( $ej_csv, "\xEF\xBB\xBF" ) && str_contains( $ej_csv, ';' ) && str_contains( $ej_csv, 'Camiseta del grupo' ), 'el ejemplo lleva BOM, separador «;» y filas de ejemplo' );
	$otro = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . ProductosTable::table() . ' WHERE grupo <> %s LIMIT 1', $grupo['numero'] ) ); // phpcs:ignore WordPress.DB
	$ok( ! $otro || null === ProductosTable::obtener( (int) $otro ), 'un producto de otro grupo no se ve (si lo hubiera)' );
	$ok( null === ProductosTable::obtener( 0 ) && null === ProductosTable::por_ref( '' ), 'ids y referencias vacías no devuelven nada' );
} finally {
	$lista = implode( ',', array_map( 'intval', $ids ) ) ?: '0';
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . VariantesTable::table() . ' WHERE id > %d', $var_antes ) ); // phpcs:ignore WordPress.DB
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . ProductosTable::table() . ' WHERE id > %d', $prod_antes ) ); // phpcs:ignore WordPress.DB
	// Si se quitó la camisa sembrada del Local para probar la siembra, se vuelve a sembrar con la configuración de siempre.
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Logger::table() . " WHERE id > %d AND ( user_id IN ($lista) OR objeto = 'tienda' )", $log_antes ) ); // phpcs:ignore WordPress.DB
	foreach ( $ids as $uid ) {
		Ambito::olvidar( (int) $uid );
		wp_delete_user( (int) $uid );
	}
	if ( null === $cfg_antes ) {
		delete_option( $opcion_cfg );
	} else {
		update_option( $opcion_cfg, $cfg_antes, false );
	}
	$wpdb->update( ProductosTable::table(), array( 'ref' => 'camisa' ), array( 'ref' => 'camisa-apartada' ) ); // Devuelve la camisa sembrada de verdad.
	Sembrado::grupo(); // Y siembra lo que faltara con la configuración real (idempotente).
	Grupo::forzar( null );
	wp_set_current_user( 0 );

	$quedan = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users} WHERE user_login LIKE 'prueba-cat-%'" ); // phpcs:ignore WordPress.DB
	$ok( 0 === $quedan && 0 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . ProductosTable::table() . " WHERE nombre LIKE 'PruebaCat%'" ) && get_option( $opcion_cfg, null ) == $cfg_antes, 'limpieza: sin usuarios, productos ni variantes de la prueba; configuración como estaba' ); // phpcs:ignore WordPress.DB
}

echo $fails ? "\n$fails FALLOS\n" : "\nTODO OK (nada tocado en el CRM real; el sitio queda como estaba)\n";
