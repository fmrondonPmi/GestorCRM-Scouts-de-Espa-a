<?php
/**
 * Pruebas de la v0.41, B1 (entorno LOCAL, doc 49): el recetario de los menús — alérgenos, escalado, ingredientes, recetas,
 * biblioteca común, copias, carga desde CSV, permisos y pantallas. Ejecutar desde app/public del site Local:
 *
 *   wp eval-file <ruta completa>/wp-content/plugins/asde-area-privada/tests/menus-recetario.php
 *
 * Sin CRM ni datos de personas. Al terminar se borra lo creado (recetas, ingredientes, líneas, usuarios, registros).
 */
defined( 'ABSPATH' ) || exit;

require_once ABSPATH . 'wp-admin/includes/user.php';

use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Auth\Ambito;
use AsdeBalears\AreaPrivada\Domain\Grupo;
use AsdeBalears\AreaPrivada\Domain\Grupos;
use AsdeBalears\AreaPrivada\Domain\Modulos;
use AsdeBalears\AreaPrivada\Frontend\Views\RecetasView;
use AsdeBalears\AreaPrivada\Install\Roles;
use AsdeBalears\AreaPrivada\Menus\Alergenos;
use AsdeBalears\AreaPrivada\Menus\Biblioteca;
use AsdeBalears\AreaPrivada\Menus\Escalado;
use AsdeBalears\AreaPrivada\Menus\IngredientesTable;
use AsdeBalears\AreaPrivada\Menus\RecetasTable;
use AsdeBalears\AreaPrivada\Menus\Recetario;
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
foreach ( get_users( array( 'search' => 'prueba-mn-*', 'search_columns' => array( 'user_login' ) ) ) as $u ) {
	exit( 'Ya existe el usuario ' . $u->user_login . " (¿una ejecución anterior interrumpida?). Bórralo antes de repetir la prueba.\n" );
}
wp_set_current_user( 0 );
Grupo::forzar( $grupo );
IngredientesTable::install_table();
RecetasTable::install_table();
Biblioteca::sembrar();

$max_id     = static fn( string $t ) => (int) $wpdb->get_var( "SELECT COALESCE(MAX(id), 0) FROM $t" ); // phpcs:ignore WordPress.DB
$ing_antes  = $max_id( IngredientesTable::table() );
$rec_antes  = $max_id( RecetasTable::table() );
$lin_antes  = $max_id( RecetasTable::table_lineas() );
$log_antes  = $max_id( Logger::table() );
$mod_antes  = get_option( 'asde_ap_modulos', null );

$destino   = '';
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
$u_sc    = $crear( 'prueba-mn-sc', array( Roles::SCOUTER ) );
$u_coord = $crear( 'prueba-mn-coord', array( Roles::SCOUTER, Roles::COORDINACION ) );
$u_tutor = $crear( 'prueba-mn-tutor', array( Roles::TUTOR ) );
$como    = static function ( int $uid ): void {
	wp_set_current_user( $uid );
	Ambito::olvidar( $uid );
};
/** Envía un formulario a la vista del recetario; devuelve [dirección a la que redirige o '']. */
$enviar = static function ( array $campos, bool $nonce_bueno = true ) use ( &$destino ): string {
	$destino  = '';
	$_POST    = $campos + array( '_wpnonce' => $nonce_bueno ? wp_create_nonce( 'asde_menus' ) : 'mala' );
	$_REQUEST = $_POST;
	try {
		( new RecetasView() )->handle_post();
	} catch ( RuntimeException $e ) {
		// Redirige.
	} finally {
		$_POST    = array();
		$_REQUEST = array();
	}
	return $destino;
};
$ver = static function ( array $get ): string {
	$_GET = $get;
	try {
		return ( new RecetasView() )->render();
	} finally {
		$_GET = array();
	}
};
$comun = static function ( string $nombre ): array {
	foreach ( RecetasTable::visibles() as $r ) {
		if ( $r['nombre'] === $nombre && IngredientesTable::COMUN === $r['grupo'] ) {
			return $r;
		}
	}
	return array();
};

try {
	echo "== Parte A · Alérgenos y unidades ==\n";
	$ok( 14 === count( Alergenos::LISTA ), 'el catálogo tiene los 14 alérgenos del Reglamento 1169/2011' );
	$ok( array( 'gluten', 'huevos' ) === Alergenos::limpiar( 'huevos, gluten, inventado, gluten' ), 'limpiar: solo códigos válidos, sin repetir y en el orden del catálogo' );
	$ok( array( 'gluten', 'lacteos', 'sesamo' ) === Alergenos::unir( array( array( 'sesamo', 'gluten' ), array( 'lacteos' ) ) ), 'unir: junta varias listas' );
	$ok( 'Gluten, Huevos' === Alergenos::texto( array( 'gluten', 'huevos' ) ), 'texto: nombres legibles' );
	$ok( 'gluten' === Alergenos::INTOLERANCIAS['celiaquia'] && 'lacteos' === Alergenos::INTOLERANCIAS['lactosa'], 'celiaquía → gluten y lactosa → lácteos (para la validación de B3)' );

	echo "\n== Parte B · Escalado ==\n";
	$ok( 6.3 === Escalado::escalar( 1.5, 10, 42 ), 'el ejemplo del plan: 1,5 kg × 42 ÷ 10 = 6,3 kg' );
	$ok( '6,3 kg' === Escalado::linea( 1.5, 'kg', 10, 42 ), 'se escribe «6,3 kg»' );
	$ok( '3,36 kg' === Escalado::linea( 800, 'g', 10, 42 ), '800 g × 42 ÷ 10 = 3.360 g, que se enseñan como 3,36 kg' );
	$ok( '200 g' === Escalado::linea( 400, 'g', 10, 5 ), '400 g para 10 → 200 g para 5 (se queda en gramos)' );
	$ok( '2,5 l' === Escalado::linea( 500, 'ml', 10, 50 ), 'ml y l se convierten' );
	$ok( '21 unidades' === Escalado::linea( 5, 'ud', 10, 42 ), 'las unidades no se convierten' );
	$ok( '4,2 cucharadas' === Escalado::linea( 1, 'cda', 10, 42 ), 'las cucharadas tampoco' );
	$ok( 0.0 === Escalado::escalar( 5, 0, 10 ) && 0.0 !== Escalado::escalar( 5, 1, 1 ), 'sin porciones base no hay división por cero' );
	$ok( 3.5 === \AsdeBalears\AreaPrivada\Menus\Conversion::numero( '3,5' ) && 1250.5 === \AsdeBalears\AreaPrivada\Menus\Conversion::numero( '1.250,5' ) && 2.0 === \AsdeBalears\AreaPrivada\Menus\Conversion::numero( '2' ), 'los números se leen con coma o punto' );

	echo "\n== Parte C · La biblioteca común ==\n";
	$lent = $comun( 'Lentejas estofadas' );
	$ok( 12 <= count( array_filter( RecetasTable::visibles(), static fn( $r ) => IngredientesTable::COMUN === $r['grupo'] ) ) && $lent && $lent['ejemplo'], 'vienen al menos 12 recetas de ejemplo, marcadas como tales' );
	$ok( 0 === Biblioteca::sembrar(), 'sembrar otra vez no repite nada' );
	$a_lent = Recetario::alergenos( RecetasTable::lineas( $lent['id'] ) );
	$ok( array() === $a_lent['contiene'] && ! $a_lent['animal'] && array() === $a_lent['sin_revisar'], 'lentejas estofadas: sin alérgenos, vegana, ingredientes revisados' );
	$mac = Recetario::alergenos( RecetasTable::lineas( $comun( 'Macarrones con tomate' )['id'] ) );
	$ok( array( 'gluten', 'lacteos' ) === $mac['contiene'] && $mac['animal'], 'macarrones con tomate: gluten y lácteos (del queso), y no es vegana' );
	$alb = Recetario::alergenos( RecetasTable::lineas( $comun( 'Albóndigas en salsa de tomate' )['id'] ) );
	$ok( array( 'gluten', 'huevos' ) === $alb['contiene'], 'albóndigas: gluten (pan rallado) y huevos' );
	$boc = Recetario::alergenos( RecetasTable::lineas( $comun( 'Bocadillo de queso' )['id'] ) );
	$ok( array( 'gluten', 'lacteos' ) === $boc['contiene'] && array( 'sesamo' ) === $boc['trazas'], 'bocadillo: el sésamo sale como traza, no como alérgeno' );
	$ok( array( 'pescado' ) === Recetario::alergenos( RecetasTable::lineas( $comun( 'Merluza al horno con limón' )['id'] ) )['contiene'], 'merluza: pescado' );
	$esc = Recetario::escalar( $lent, RecetasTable::lineas( $lent['id'] ), 42 );
	$por = array_column( array_map( static fn( $l ) => array( $l['ingrediente']['nombre'], $l['escalada'] ), $esc ), 1, 0 );
	$ok( '3,36 kg' === $por['Lentejas'] && '4,2 cucharadas' === $por['Pimentón'], 'escalar una receta de la biblioteca a 42 comensales' );

	echo "\n== Parte D · Ingredientes ==\n";
	$como( $u_sc );
	$i_harina = IngredientesTable::guardar( array( 'nombre' => 'Harina de prueba', 'unidad' => 'kg', 'alergenos' => array( 'gluten', 'inventado' ), 'trazas' => array( 'sesamo', 'gluten' ), 'animal' => false, 'coste' => '1,2', 'revisado' => true ) );
	$h        = IngredientesTable::obtener( (int) $i_harina );
	$ok( is_int( $i_harina ) && array( 'gluten' ) === $h['alergenos'] && array( 'sesamo', 'gluten' ) !== $h['trazas'] && $h['revisado'] && 'kg' === $h['unidad'], 'se guarda un ingrediente del grupo con sus alérgenos y trazas (lo inválido se descarta)' );
	$i_huevo = IngredientesTable::guardar( array( 'nombre' => 'Huevo de prueba', 'unidad' => 'ud', 'alergenos' => array( 'huevos' ), 'animal' => true, 'revisado' => false ) );
	$i_sal   = IngredientesTable::guardar( array( 'nombre' => 'Sal de prueba', 'unidad' => 'raro' ) );
	$ok( 'g' === IngredientesTable::obtener( (int) $i_sal )['unidad'] && null === IngredientesTable::obtener( (int) $i_sal )['coste'], 'una unidad inválida pasa a gramos; el coste es opcional' );
	$ok( is_wp_error( IngredientesTable::guardar( array( 'nombre' => 'harina de PRUEBA' ) ) ), 'no se repite el nombre (sin mayúsculas)' );
	$ok( is_wp_error( IngredientesTable::guardar( array( 'nombre' => '  ' ) ) ), 'el nombre es obligatorio' );
	$queso_comun = IngredientesTable::por_nombre( 'Queso rallado' );
	$ok( $queso_comun && IngredientesTable::COMUN === $queso_comun['grupo'], 'se encuentra un ingrediente de la biblioteca por su nombre' );
	$ok( is_wp_error( IngredientesTable::guardar( array( 'nombre' => 'Otro nombre' ), $queso_comun['id'] ) ), 'un ingrediente de la biblioteca no se edita' );
	$ok( true === IngredientesTable::activar( (int) $i_sal, false ) && ! IngredientesTable::obtener( (int) $i_sal )['activo'], 'se archiva' );
	IngredientesTable::activar( (int) $i_sal, true );
	Grupo::forzar( array( 'numero' => '999' ) + $grupo );
	$ok( null === IngredientesTable::obtener( (int) $i_harina ) && null !== IngredientesTable::obtener( $queso_comun['id'] ), 'otro grupo no ve los ingredientes de este, pero sí los de la biblioteca' );
	Grupo::forzar( $grupo );

	echo "\n== Parte E · Recetas ==\n";
	$l_ok = array( array( 'ingrediente_id' => (int) $i_harina, 'cantidad' => 1.5, 'unidad' => 'kg' ), array( 'ingrediente_id' => (int) $i_huevo, 'cantidad' => 6, 'unidad' => 'ud' ) );
	$ok( is_wp_error( RecetasTable::guardar( array( 'nombre' => '', 'porciones' => 10 ), $l_ok ) ), 'sin nombre no se guarda' );
	$ok( is_wp_error( RecetasTable::guardar( array( 'nombre' => 'X', 'porciones' => 0 ), $l_ok ) ) && is_wp_error( RecetasTable::guardar( array( 'nombre' => 'X', 'porciones' => 900 ), $l_ok ) ), 'las porciones van de 1 a 500' );
	$ok( is_wp_error( RecetasTable::guardar( array( 'nombre' => 'X', 'porciones' => 10 ), array() ) ), 'sin ingredientes no se guarda' );
	$ok( is_wp_error( RecetasTable::guardar( array( 'nombre' => 'X', 'porciones' => 10 ), array( array( 'ingrediente_id' => (int) $i_harina, 'cantidad' => 0, 'unidad' => 'g' ) ) ) ), 'cada ingrediente necesita cantidad' );
	$ok( is_wp_error( RecetasTable::guardar( array( 'nombre' => 'X', 'porciones' => 10 ), array( array( 'ingrediente_id' => 999999, 'cantidad' => 1, 'unidad' => 'g' ) ) ) ), 'un ingrediente que no existe se rechaza' );
	$id = RecetasTable::guardar( array( 'nombre' => 'Bizcocho de prueba', 'tipo' => 'postre', 'porciones' => 8, 'elaboracion' => 'Mezclar y hornear.', 'observaciones' => '' ), array_merge( $l_ok, array( array( 'ingrediente_id' => 0, 'cantidad' => '', 'unidad' => 'g' ) ) ) );
	$r  = RecetasTable::obtener( (int) $id );
	$ok( is_int( $id ) && 2 === count( RecetasTable::lineas( $id ) ) && 'postre' === $r['tipo'] && 8 === $r['porciones'], 'se guarda la receta con sus dos líneas (la fila vacía no cuenta)' );
	$al = Recetario::alergenos( RecetasTable::lineas( $id ) );
	$ok( array( 'gluten', 'huevos' ) === $al['contiene'] && $al['animal'] && array( 'Huevo de prueba' ) === $al['sin_revisar'], 'los alérgenos se calculan: gluten y huevos, origen animal, y avisa del ingrediente sin revisar' );
	$ok( array( 'sesamo' ) === $al['trazas'], 'las trazas que ya son alérgeno no se repiten (el gluten no sale como traza)' );
	$ok( array( 'Harina de prueba' ) === $al['por_alergeno']['gluten'], 'se sabe qué ingrediente causa cada alérgeno' );
	$escr = Recetario::escalar( $r, RecetasTable::lineas( $id ), 24 );
	$ok( '4,5 kg' === $escr[0]['escalada'] && '18 unidades' === $escr[1]['escalada'], 'escalar a 24 comensales: 1,5 kg × 24 ÷ 8 = 4,5 kg y 18 huevos' );
	RecetasTable::guardar( array( 'nombre' => 'Bizcocho de prueba', 'tipo' => 'postre', 'porciones' => 8 ), array( $l_ok[0] ), $id );
	$ok( 1 === count( RecetasTable::lineas( $id ) ), 'editar sustituye las líneas' );
	$ok( true === RecetasTable::activar( $id, false ) && ! RecetasTable::obtener( $id )['activa'], 'se archiva' );
	$ok( ! in_array( $id, array_column( RecetasTable::visibles(), 'id' ), true ) && in_array( $id, array_column( RecetasTable::visibles( true ), 'id' ), true ), 'las archivadas no salen salvo que se pidan' );
	RecetasTable::activar( $id, true );
	$ok( is_wp_error( RecetasTable::guardar( array( 'nombre' => 'Cambiada', 'porciones' => 10 ), $l_ok, $lent['id'] ) ), 'una receta de la biblioteca no se edita' );
	Grupo::forzar( array( 'numero' => '999' ) + $grupo );
	$ok( null === RecetasTable::obtener( $id ) && null !== RecetasTable::obtener( $lent['id'] ), 'otro grupo no ve las recetas de este, pero sí las de la biblioteca' );
	Grupo::forzar( $grupo );

	echo "\n== Parte F · Copiar de la biblioteca ==\n";
	$copia = Recetario::copiar( $lent['id'] );
	$rc    = RecetasTable::obtener( (int) $copia );
	$ok( is_int( $copia ) && Grupo::numero() === $rc['grupo'] && 'Lentejas estofadas' === $rc['nombre'] && $lent['id'] === $rc['origen_id'] && count( RecetasTable::lineas( $lent['id'] ) ) === count( RecetasTable::lineas( $copia ) ), 'la copia es del grupo, con el mismo nombre, el origen anotado y las mismas líneas' );
	$copia2 = Recetario::copiar( $lent['id'] );
	$ok( is_int( $copia2 ) && 'Lentejas estofadas (copia 2)' === RecetasTable::obtener( $copia2 )['nombre'], 'una segunda copia lleva «(copia 2)»' );
	$ok( ! is_wp_error( RecetasTable::guardar( array( 'nombre' => 'Lentejas con chorizo', 'porciones' => 12 ), array( array( 'ingrediente_id' => IngredientesTable::por_nombre( 'Lentejas' )['id'], 'cantidad' => 900, 'unidad' => 'g' ) ), $copia ) ), 'la copia se puede editar' );
	$ok( 'Lentejas estofadas' === RecetasTable::obtener( $lent['id'] )['nombre'] && 10 === RecetasTable::obtener( $lent['id'] )['porciones'], 'y la de la biblioteca sigue igual' );
	$ok( is_wp_error( Recetario::copiar( 999999 ) ), 'no se copia lo que no existe' );

	echo "\n== Parte G · Cargar desde CSV ==\n";
	$csv  = "Receta;Tipo;Porciones;Ingrediente;Cantidad;Unidad\n";
	$csv .= "Crema CSV de prueba;primero;12;Harina de prueba;0,5;kg\n";
	$csv .= "Crema CSV de prueba;primero;12;Nata de prueba;300;ml\n";
	$csv .= "Bizcocho de prueba;postre;8;Harina de prueba;1;kg\n";
	$csv .= "Sopa mala;primero;0;Harina de prueba;1;kg\n";
	$csv .= "Sopa rara;primero;4;Harina de prueba;1;litros\n";
	$tmp  = wp_tempnam( 'menus.csv' );
	file_put_contents( $tmp, $csv ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	$res = Recetario::importar( $tmp );
	$ok( is_array( $res ) && 1 === $res['recetas'] && 1 === $res['ingredientes_nuevos'], 'se carga una receta y se crea un ingrediente nuevo (la harina ya existía)' );
	$crema = array_values( array_filter( RecetasTable::visibles(), static fn( $r ) => 'Crema CSV de prueba' === $r['nombre'] ) )[0] ?? null;
	$nata  = IngredientesTable::por_nombre( 'Nata de prueba' );
	$ok( $crema && 12 === $crema['porciones'] && 'primero' === $crema['tipo'] && 2 === count( RecetasTable::lineas( $crema['id'] ) ), 'la receta queda con sus 2 líneas' );
	$ok( $nata && ! $nata['revisado'] && array() === $nata['alergenos'], 'el ingrediente nuevo queda sin alérgenos y SIN REVISAR' );
	$ok( array( 'Nata de prueba' ) === Recetario::alergenos( RecetasTable::lineas( $crema['id'] ) )['sin_revisar'], 'y la receta avisa de que tiene un ingrediente sin revisar' );
	$ok( 4 === count( $res['errores'] ), 'cuatro avisos: receta repetida, porciones mal, unidad inválida y la receta que se queda sin ingredientes' );
	$ok( 1 === count( array_filter( RecetasTable::visibles(), static fn( $r ) => 'Bizcocho de prueba' === $r['nombre'] ) ), 'la receta que ya existía no se pisa ni se duplica' );
		file_put_contents( $tmp, "Otra;cosa\n1;2\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	$ok( is_wp_error( Recetario::importar( $tmp ) ), 'sin la cabecera esperada se rechaza todo' );
	wp_delete_file( $tmp );
	$ok( str_contains( Recetario::ejemplo(), 'Macarrones' ) && str_starts_with( Recetario::ejemplo(), "\xEF\xBB\xBF" ), 'hay un CSV de ejemplo' );

	echo "\n== Parte H · Permisos y pantallas ==\n";
	$ok( user_can( $u_sc, Recetario::CAP ) && user_can( $u_coord, Recetario::CAP ) && ! user_can( $u_tutor, Recetario::CAP ), 'scouters y Coordinación tienen el permiso; una familia, no' );
	$ok( in_array( Recetario::CAP, Modulos::LISTA[ Modulos::EVENTOS ][2], true ), 'el permiso se va con el módulo Eventos (sin módulo propio)' );
	$como( $u_tutor );
	$ok( ! str_contains( $ver( array() ), 'Biblioteca de ejemplo' ), 'una familia no ve el recetario' );
	$como( $u_sc );
	$h = $ver( array() );
	$ok( str_contains( $h, 'Recetario' ) && str_contains( $h, 'Lentejas estofadas' ) && str_contains( $h, 'Bizcocho de prueba' ) && str_contains( $h, 'Copiar a mi grupo' ), 'el scouter ve el recetario: las del grupo y la biblioteca de ejemplo' );
	$ok( str_contains( $h, 'Gluten' ) && str_contains( $h, 'Ingredientes sin revisar' ), 'la lista enseña los alérgenos y avisa de lo sin revisar' );
	$h = $ver( array( 'receta' => (string) $lent['id'], 'comensales' => '42' ) );
	$ok( str_contains( $h, '3,36 kg' ) && str_contains( $h, 'no se puede cambiar' ) && ! str_contains( $h, 'name="nombre"' ), 'la ficha de la biblioteca calcula las cantidades y no ofrece editar' );
	$h = $ver( array( 'receta' => (string) $id ) );
	$ok( str_contains( $h, 'name="nombre"' ) && str_contains( $h, 'Harina de prueba' ) && str_contains( $h, 'name="ing[]"' ), 'la receta del grupo se edita en un formulario' );
	$h = $ver( array( 'ingredientes' => '1' ) );
	$ok( str_contains( $h, 'Harina de prueba' ) && str_contains( $h, 'Biblioteca' ) && str_contains( $h, 'name="alergenos[]"' ), 'la pantalla de ingredientes' );
	$h = $ver( array( 'importar' => '1' ) );
	$ok( str_contains( $h, 'name="fichero"' ), 'la pantalla de carga' );
	$ok( str_contains( $ver( array( 'receta' => '999999' ) ), 'no existe' ), 'una receta que no existe lo dice' );

	$cant = array( 'asde_action' => 'receta_guardar', 'receta' => '0', 'nombre' => 'Receta del formulario', 'tipo' => 'segundo', 'porciones' => '6', 'ing' => array( (string) $i_harina, '0' ), 'cant' => array( '2,5', '' ), 'uni' => array( 'kg', 'g' ), 'elaboracion' => 'x', 'observaciones' => '' );
	$d = $enviar( $cant );
	$nueva = array_values( array_filter( RecetasTable::visibles(), static fn( $r ) => 'Receta del formulario' === $r['nombre'] ) )[0] ?? null;
	$ok( $nueva && str_contains( $d, 'receta=' . $nueva['id'] ) && 1 === count( RecetasTable::lineas( $nueva['id'] ) ) && 2.5 === RecetasTable::lineas( $nueva['id'] )[0]['cantidad'], 'el formulario guarda la receta (cantidad «2,5» con coma)' );
	$d = $enviar( array( 'asde_action' => 'receta_guardar', 'receta' => (string) $lent['id'], 'nombre' => 'Intento', 'tipo' => 'otro', 'porciones' => '5', 'ing' => array( (string) $i_harina ), 'cant' => array( '1' ), 'uni' => array( 'g' ) ) );
	$ok( 'Lentejas estofadas' === RecetasTable::obtener( $lent['id'] )['nombre'], 'mandar a mano el id de una receta de la biblioteca no la cambia' );
	$d = $enviar( array( 'asde_action' => 'receta_guardar', 'receta' => '0', 'nombre' => '', 'porciones' => '5', 'ing' => array(), 'cant' => array(), 'uni' => array() ) );
	$ok( str_contains( $d, 'receta=nueva' ) && false !== get_transient( 'asde_ap_menus_receta_' . $u_sc ), 'si falla, vuelve al formulario y guarda lo escrito' );
	delete_transient( 'asde_ap_menus_receta_' . $u_sc );
	$d = $enviar( array( 'asde_action' => 'receta_copiar', 'receta' => (string) $comun( 'Yogur con fruta' )['id'] ) );
	$ok( 1 === count( array_filter( RecetasTable::visibles(), static fn( $r ) => 'Yogur con fruta' === $r['nombre'] && Grupo::numero() === $r['grupo'] ) ), 'copiar desde la pantalla' );
	$enviar( array( 'asde_action' => 'receta_archivar', 'receta' => (string) $nueva['id'] ) );
	$ok( ! RecetasTable::obtener( $nueva['id'] )['activa'], 'archivar desde la pantalla' );
	$enviar( array( 'asde_action' => 'ingrediente_guardar', 'ingrediente' => '0', 'nombre' => 'Aceite del formulario', 'unidad' => 'ml', 'alergenos' => array(), 'trazas' => array( 'soja' ), 'animal' => '', 'coste' => '3,5', 'revisado' => '1' ) );
	$ing_f = IngredientesTable::por_nombre( 'Aceite del formulario' );
	$ok( $ing_f && array( 'soja' ) === $ing_f['trazas'] && 3.5 === $ing_f['coste'] && $ing_f['revisado'], 'crear un ingrediente desde la pantalla' );
	$como( 0 );
} catch ( Throwable $e ) {
	++$fails;
	echo 'FAIL excepcion: ', $e->getMessage(), ' (', basename( $e->getFile() ), ':', $e->getLine(), ")\n";
} finally {
	remove_filter( 'wp_redirect', $redirigir, 1 );
	$_POST = array();
	$_GET  = array();
	wp_set_current_user( 0 );

	$lista = implode( ',', array_map( 'intval', $ids ) ) ?: '0';
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . RecetasTable::table_lineas() . ' WHERE id > %d AND receta_id > %d', $lin_antes, $rec_antes ) ); // phpcs:ignore WordPress.DB
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . RecetasTable::table() . ' WHERE id > %d', $rec_antes ) ); // phpcs:ignore WordPress.DB
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . IngredientesTable::table() . ' WHERE id > %d', $ing_antes ) ); // phpcs:ignore WordPress.DB
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Logger::table() . " WHERE id > %d AND ( user_id IN ($lista) OR objeto = 'menus' )", $log_antes ) ); // phpcs:ignore WordPress.DB
	foreach ( $ids as $uid ) {
		delete_transient( 'asde_ap_notices_' . $uid );
		Ambito::olvidar( (int) $uid );
		wp_delete_user( (int) $uid );
	}
	if ( null === $mod_antes ) {
		delete_option( 'asde_ap_modulos' );
	} else {
		update_option( 'asde_ap_modulos', $mod_antes );
	}
	Grupo::forzar( null );

	$quedan = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users} WHERE user_login LIKE 'prueba-mn-%'" ); // phpcs:ignore WordPress.DB
	$ok( 0 === $quedan && 0 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . RecetasTable::table() . ' WHERE id > ' . (int) $rec_antes ) && 0 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . IngredientesTable::table() . ' WHERE id > ' . (int) $ing_antes ), 'limpieza: sin usuarios, recetas ni ingredientes de la prueba (la biblioteca queda)' ); // phpcs:ignore WordPress.DB
}

echo $fails ? "\n$fails FALLOS\n" : "\nTODO OK (sin CRM ni datos personales; el sitio queda como estaba)\n";
