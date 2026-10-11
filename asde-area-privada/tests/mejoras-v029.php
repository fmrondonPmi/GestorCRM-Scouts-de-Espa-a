<?php
/**
 * Pruebas de la v0.29 (entorno LOCAL, doc 36): informes en Excel y para imprimir,
 * descargas sin pantalla en blanco, cuadros plegables, listado de Personas sin
 * recargar, cambios propuestos agrupados por persona y camisas del uniforme en el
 * inventario. Ejecutar desde app/public del site Local:
 *
 *   wp eval-file wp-content/plugins/asde-area-privada/tests/mejoras-v029.php
 *
 * Usa el CRM simulado de tests/lib (fichas inventadas: ninguna petición llega al CRM
 * real) y guarda las cachés solo en la memoria de este proceso. Al terminar se borra
 * lo creado (usuarios, cambios, material, tipos, pedidos, registros) y la
 * configuración de uniformes queda como estaba.
 */
defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/lib/CrmSimulado.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Auth\Ambito;
use AsdeBalears\AreaPrivada\Crm\Escritura;
use AsdeBalears\AreaPrivada\Crm\PersonaRepository;
use AsdeBalears\AreaPrivada\Domain\Grupo;
use AsdeBalears\AreaPrivada\Domain\Grupos;
use AsdeBalears\AreaPrivada\Fichas\Cambios;
use AsdeBalears\AreaPrivada\Frontend\TemplateLoader;
use AsdeBalears\AreaPrivada\Frontend\Views\CambiosView;
use AsdeBalears\AreaPrivada\Frontend\Views\UniformesGestionView;
use AsdeBalears\AreaPrivada\Frontend\Views\View;
use AsdeBalears\AreaPrivada\Informes\Generador;
use AsdeBalears\AreaPrivada\Informes\Informe;
use AsdeBalears\AreaPrivada\Informes\InformesTable;
use AsdeBalears\AreaPrivada\Informes\Xlsx;
use AsdeBalears\AreaPrivada\Install\Roles;
use AsdeBalears\AreaPrivada\Inventario\Importador;
use AsdeBalears\AreaPrivada\Inventario\MaterialesTable;
use AsdeBalears\AreaPrivada\Inventario\TiposTable;
use AsdeBalears\AreaPrivada\Socios\SociosTable;
use AsdeBalears\AreaPrivada\Uniformes\Config;
use AsdeBalears\AreaPrivada\Uniformes\PedidosTable;
use AsdeBalears\AreaPrivada\Uniformes\Servicio;
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
echo "\n== A. Excel (.xlsx) ==\n";
$ok( 'A' === Xlsx::columna( 0 ) && 'Z' === Xlsx::columna( 25 ) && 'AA' === Xlsx::columna( 26 ) && 'BA' === Xlsx::columna( 52 ), 'letras de las columnas (A, Z, AA, BA)' );
$ok( '12.50' === Xlsx::numero( '12,50' ) && '-3' === Xlsx::numero( '-3' ) && null === Xlsx::numero( '07001' ) && null === Xlsx::numero( '+34600000000' ) && null === Xlsx::numero( '1234567890123456' ) && null === Xlsx::numero( '12 €' ), 'números como números; ceros delante, prefijos, más de 15 cifras y textos, como texto' );
$ok( 'Informe' === Xlsx::nombre_hoja( "''" ) && 'a b' === Xlsx::nombre_hoja( 'a/b' ) && 31 === mb_strlen( Xlsx::nombre_hoja( str_repeat( 'x', 40 ) ) ), 'nombre de la hoja válido para Excel (sin []:*?/\\, 31 caracteres)' );
$bin = Xlsx::generar( 'Lobatos: listado', array( 'Nombre', 'Edad', 'Nota' ), array( array( 'Ana Pérez', '8', '=SUMA(A1)' ), array( "Bea\x01 López & Cía", '9', '' ) ) );
$ok( str_starts_with( $bin, "PK\x03\x04" ), 'el fichero es un zip (.xlsx)' );
$tmp = wp_tempnam( 'prueba-xlsx' );
file_put_contents( $tmp, $bin );
$zip   = new ZipArchive();
$abrir = true === $zip->open( $tmp );
$hoja  = $abrir ? (string) $zip->getFromName( 'xl/worksheets/sheet1.xml' ) : '';
$libro = $abrir ? (string) $zip->getFromName( 'xl/workbook.xml' ) : '';
$tipos = $abrir ? (string) $zip->getFromName( '[Content_Types].xml' ) : '';
if ( $abrir ) {
	$zip->close();
}
wp_delete_file( $tmp );
libxml_use_internal_errors( true );
$ok( $abrir && false !== simplexml_load_string( $hoja ) && false !== simplexml_load_string( $libro ) && str_contains( $tipos, 'spreadsheetml.sheet.main+xml' ), 'se abre y su XML es válido' );
$ok( str_contains( $hoja, '<c r="A1" t="inlineStr" s="1">' ) && str_contains( $hoja, 'state="frozen"' ) && str_contains( $hoja, '<autoFilter ref="A1:C3"/>' ), 'cabecera en negrita, fija y con filtros' );
$ok( str_contains( $hoja, '<c r="B2"><v>8</v></c>' ) && str_contains( $hoja, '=SUMA(A1)</t>' ) && ! str_contains( $hoja, '<f>' ), 'la edad, número; «=SUMA(…)», texto (nunca una fórmula)' );
$ok( str_contains( $hoja, 'Bea López &amp; Cía' ) && ! str_contains( $hoja, "\x01" ), 'texto escapado y sin caracteres de control' );
$ok( str_contains( $libro, 'name="Lobatos listado"' ), 'la hoja se llama como el informe (sin «:»)' );

// ---------------------------------------------------------------------------
echo "\n== B. Informes: resumen y versión para imprimir ==\n";
$inf = new Informe( 0, 'Lista de prueba', 'Descripción de prueba', array( 'nombre', 'edad' ), Informe::limpiar_filtros( array( 'edad_min' => 7, 'salud' => true ) ), 0, false );
$res = array(
	'columnas'  => array(
		'nombre' => 'Nombre',
		'edad'   => 'Edad',
	),
	'filas'     => array( array( 'nombre' => 'Ana <b>Pérez</b>', 'edad' => '8' ), array( 'nombre' => 'Bea', 'edad' => '9' ) ),
	'secciones' => array(),
	'avisos'    => array(),
	'salud'     => true,
	'ronda'     => null,
	'pago'      => '',
);
$resumen = Generador::resumen( $inf, $res );
$ok( str_starts_with( $resumen, '2 socios con membresía activa · ' ) && str_contains( $resumen, 'desde 7 años' ) && str_contains( $resumen, 'solo con datos de salud relevantes' ), 'resumen del informe en una línea' );
$html = TemplateLoader::render(
	'informes/imprimir',
	array(
		'informe' => $inf,
		'res'     => $res,
		'resumen' => $resumen,
		'marco'   => true,
	)
);
$ok( str_starts_with( ltrim( $html ), '<!DOCTYPE html>' ) && str_contains( $html, '<h1>Lista de prueba</h1>' ) && 2 === substr_count( $html, '<th scope="col">' ) && str_contains( $html, 'Ana &lt;b&gt;Pérez&lt;/b&gt;' ), 'página propia con la tabla entera y los datos escapados' );
$ok( str_contains( $html, 'size: A4 portrait' ) && str_contains( $html, 'display: table-header-group' ) && str_contains( $html, 'destruye las copias impresas' ) && ! str_contains( $html, 'onclick="window.print()"' ), 'hoja A4, cabecera en cada hoja, aviso de salud y, en la ventana, sin su propio botón' );
$res8 = $res;
foreach ( range( 1, 7 ) as $i ) {
	$res8['columnas'][ 'c' . $i ] = 'C' . $i;
}
$ok( str_contains( TemplateLoader::render( 'informes/imprimir', array( 'informe' => $inf, 'res' => $res8, 'resumen' => '', 'marco' => false ) ), 'size: A4 landscape' ), 'con muchas columnas, en horizontal y con «Imprimir»' );
// Fichas reducidas (petición del usuario): una ficha por persona y, al imprimir, una por página.
$ok( 'fichas' === Informe::limpiar_filtros( array( 'vista' => 'fichas' ) )['vista'] && 'tabla' === Informe::limpiar_filtros( array( 'vista' => 'grid' ) )['vista'], 'presentación: tabla (por defecto) o una ficha por persona' );
$pre = array_values( array_filter( InformesTable::predefinidos(), static fn( $p ) => 'Fichas reducidas' === $p[0] ) );
$ok( $pre && array( 'nombre', 'apellidos', 'movil', 'tutor1', 'cesion_imagen', 'alergias', 'alerg_medic', 'alim_alergia', 'alim_intol', 'alim_intol_que', 'alim_regimen', 'alim_regimen_que', 'alim_trastorno', 'enfermedades' ) === $pre[0][2] && 'fichas' === ( $pre[0][3]['vista'] ?? '' ), 'predefinido «Fichas reducidas» con las 14 columnas pedidas, en fichas' );
$ok( (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM %i WHERE sistema = 1 AND nombre = 'Fichas reducidas'", InformesTable::table() ) ), 'se ha creado al actualizar el plugin (sin tocar los demás informes)' );
$fichas_inf = new Informe( 0, 'Fichas reducidas', '', array( 'nombre', 'apellidos', 'movil', 'alergias' ), array( 'vista' => 'fichas' ), 0, false, true );
$fichas_res = array(
	'columnas'  => array(
		'nombre'    => 'Nombre',
		'apellidos' => 'Apellidos',
		'movil'     => 'Móvil',
		'alergias'  => 'Alergias',
	),
	'filas'     => array(
		array( 'nombre' => 'Ana', 'apellidos' => 'Pérez', 'movil' => '600000001', 'alergias' => 'Polen' ),
		array( 'nombre' => 'Bea', 'apellidos' => 'López', 'movil' => '', 'alergias' => '' ),
		array( 'nombre' => 'Cai', 'apellidos' => 'Mir', 'movil' => '600000003', 'alergias' => '' ),
	),
	'secciones' => array( 'lobatos' ),
	'avisos'    => array(),
	'salud'     => true,
	'ronda'     => null,
	'pago'      => '',
);
$f1 = Generador::ficha( $fichas_res['filas'][0], $fichas_res['columnas'] );
$ok( 'Ana Pérez' === $f1['titulo'] && array( 'Móvil' => '600000001', 'Alergias' => 'Polen' ) === $f1['datos'], 'cada fila como ficha: nombre y apellidos de título y el resto de datos' );
$fhtml = TemplateLoader::render( 'informes/imprimir', array( 'informe' => $fichas_inf, 'res' => $fichas_res, 'resumen' => '', 'marco' => true ) );
$ok( 3 === substr_count( $fhtml, '<section class="ficha">' ) && ! str_contains( $fhtml, '<table>' ) && str_contains( $fhtml, '<h2>Bea López</h2>' ) && str_contains( $fhtml, 'page-break-after: always' ) && str_contains( $fhtml, 'size: A4 portrait' ), 'para imprimir: sin tabla, una ficha por página (en vertical)' );
$ok( str_contains( $fhtml, '<dd>—</dd>' ), 'un dato vacío sale como «—»' );
$ok( str_contains( $leer( 'templates/informes/resultado.php' ), 'asde-informe-fichas' ) && str_contains( $leer( 'templates/informes/resultado.php' ), 'name="seccion"' ) && str_contains( $leer( 'templates/informes/editor.php' ), 'name="vista"' ), 'en pantalla, fichas; la sección se elige al verlo; y cualquier informe puede ir en fichas' );
$tpl = $leer( 'templates/informes/resultado.php' );
$ok( str_contains( $tpl, "'Descargar Excel'" ) && str_contains( $tpl, 'data-asde-imprimir' ) && ! str_contains( $tpl, 'Descargar CSV' ) && ! str_contains( $tpl, 'window.print()' ), 'resultado: «Descargar Excel» e «Imprimir» con la versión para imprimir' );
$ok( in_array( 'xlsx', \AsdeBalears\AreaPrivada\Frontend\Views\InformesView::FORMATOS, true ) && str_contains( $leer( 'src/Frontend/Router.php' ), 'InformesView::FORMATOS' ), 'el Router atiende ?formato=xlsx|imprimir|csv de los informes' );

// ---------------------------------------------------------------------------
echo "\n== C. Descargas, plegables y Personas (plantillas y JS) ==\n";
$area = $leer( 'assets/js/area.js' );
$ok( str_contains( $area, 'function esDescarga' ) && str_contains( $area, 'e.stopPropagation();' ) && str_contains( $area, "formato=(csv|xlsx|pdf" ), 'area.js: las descargas no pasan por la transición de página del tema' );
$descargas = array( 'templates/informes/resultado.php', 'templates/tesoreria/control.php', 'templates/uniformes/gestion.php', 'templates/inventario/importar.php' );
$ok( ! array_filter( $descargas, static fn( $f ) => ! str_contains( $leer( $f ), 'no-transition' ) ), 'los enlaces de descarga llevan la clase no-transition (Enfold)' );
$ok( str_contains( $area, 'function plegables' ) && str_contains( $area, 'asde_cuadro_' ) && str_contains( $area, 'data-asde-marcar-todos' ), 'area.js: cuadros plegables que se recuerdan y casilla «Todos»' );
$ok( str_contains( $area, "plegar( card, '1' !== guardado, false )" ) && str_contains( $area, "d.open = '1' === guardado" ) && str_contains( $leer( 'assets/js/ficha.js' ), "'asde_ficha_abiertos'" ) && str_contains( $leer( 'assets/js/ficha.js' ), 'details.asde-plegable[data-grupo]' ), 'por defecto, todo plegado (cuadros, apartados de la ficha y plegables que se recuerdan); se recuerda lo que se abre' );
$ok( str_contains( $leer( 'templates/comun/ayuda.php' ), 'data-asde-no-plegable' ) && str_contains( $leer( 'templates/comun/denegado.php' ), 'data-asde-no-plegable' ) && str_contains( $leer( 'templates/fichas/cambios.php' ), 'data-asde-plegable="cambios-persona"' ) && str_contains( $area, "addEventListener( 'invalid'" ), 'la ayuda y los avisos no se pliegan; los cuadros de título variable tienen clave fija; un campo obligatorio abre su cuadro' );
$ficha = $leer( 'templates/fichas/ficha.php' );
$ok( str_contains( $ficha, 'data-asde-plegable="ficha-cabecera"' ) && str_contains( $ficha, 'data-asde-plegable="ficha-membresia"' ) && strpos( $ficha, 'asde-ficha__plegar' ) < strpos( $ficha, 'data-asde-plegable="ficha-cabecera"' ), 'ficha: nombre y membresía plegables; «Contraer todo» arriba del todo' );
$ok( 1 === substr_count( $ficha, 'data-asde-plegar="todo"' ) && str_contains( $leer( 'assets/js/ficha.js' ), 'window.asdePlegables.plegar' ), '«Contraer todo» pliega también los cuadros' );
$pers = $leer( 'templates/personas/listado.php' );
$ok( str_contains( $pers, 'asde-filter asde-filter--una-linea' ) && str_contains( $leer( 'assets/css/area-privada.css' ), '#top .asde-ap .asde-filter--una-linea select' ), 'personas: «Mostrar», el cuadro de búsqueda y «Filtrar» en la misma línea (contra el #top select de Enfold)' );
$ok( str_contains( $pers, 'data-asde-plegable="personas-resumen"' ) && str_contains( $pers, 'data-asde-parcial-form="personas-lista"' ) && str_contains( $pers, 'data-asde-parcial="personas-lista"' ) && str_contains( $pers, 'data-asde-buscar' ), 'personas: resumen plegable, lista que se actualiza sola y búsqueda al escribir' );
$ok( ! str_contains( $pers, 'class="button asde-button-secundario" href="<?php echo esc_url( add_query_arg( \'socio\', $p->num_socio_asde, $url_alta ) ); ?>" data-asde-modal><?php asde_e( \'Dar acceso\' )' ) && preg_match( '/asde-filter__buscar">.*?data-asde-buscar.*?Filtrar/s', $pers ), '«Dar acceso» es un enlace y «Filtrar» va junto al cuadro de búsqueda' );
$ok( ! str_contains( $pers, 'confirm(<?php echo asde_js( \' .' ) && str_contains( $pers, "' onsubmit=\"return confirm(' . esc_attr( (string) wp_json_encode( \$confirmar ) ) . ');\"'" ) && str_contains( $pers, 'data-asde-parcial="personas-cifras"' ), 'personas: los botones del nº piden confirmación de verdad (antes llevaban PHP sin ejecutar) y las cifras se actualizan con la lista' );
$parcial = $leer( 'assets/js/parcial.js' );
$ok( str_contains( $parcial, 'data-asde-parcial-form' ) && str_contains( $parcial, 'input[data-asde-buscar]' ) && str_contains( $parcial, 'texto.length < 3' ) && str_contains( $parcial, 'AbortController' ), 'parcial.js: formulario de fuera de la zona, búsqueda desde la 3.ª letra y la última consulta manda' );

// ---------------------------------------------------------------------------
// Preparación: grupo, CRM simulado y cuentas.
// ---------------------------------------------------------------------------
$grupo = Grupos::por_numero( '684' ) ?? ( array_values( Grupos::todos() )[0] ?? null );
if ( ! $grupo || '' === $grupo['crm_id'] ) {
	exit( "Hace falta un grupo con la conexión al CRM comprobada.\n" );
}
foreach ( get_users( array( 'search' => 'prueba-v029-*', 'search_columns' => array( 'user_login' ) ) ) as $u ) {
	exit( 'Ya existe el usuario ' . $u->user_login . " (¿una ejecución anterior interrumpida?). Bórralo antes de repetir la prueba.\n" );
}
$max_id       = static fn( string $t ) => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(MAX(id), 0) FROM %i', $t ) );
$log_antes    = $max_id( Logger::table() );
$cam_antes    = $max_id( Cambios::table() );
$mat_antes    = $max_id( MaterialesTable::table() );
$tip_antes    = $max_id( TiposTable::table() );
$ped_antes    = $max_id( PedidosTable::table() );
$tip_especial = (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, especial FROM %i WHERE especial <> ''", TiposTable::table() ), ARRAY_A );
$socios_antes = array_column( (array) $wpdb->get_results( 'SELECT * FROM ' . SociosTable::table(), ARRAY_A ), null, 'num_socio_asde' ); // phpcs:ignore WordPress.DB
$cfg_antes    = get_option( 'asde_ap_uniformes_' . $grupo['numero'], null );

$cache_externa = wp_using_ext_object_cache( true );
add_filter( 'pre_wp_mail', '__return_true' ); // No sale ningún email.
$cortar_crm = static function ( $pre, $args, $url ) {
	return false === $pre && str_contains( (string) $url, '/rest.php' ) ? new WP_Error( 'prueba', 'CRM real cortado durante la prueba' ) : $pre;
};
add_filter( 'pre_http_request', $cortar_crm, 2, 3 );

$P   = array( 'Asde_Prueba_Crm', 'persona' );
$crm = new Asde_Prueba_Crm(
	array(
		$P( 71, 'Sara', 'Mejoras Mir', '1990-01-01', 'Scouters' ),
		$P( 72, 'Joan', 'Mejoras Pons', '1985-02-02', 'Scouters' ),
		$P( 73, 'Nil', 'Mejoras Sala', '2014-06-06', 'Scouts', array( 'phone_mobile' => '600000071', 'email1' => 'nil-v029@example.invalid' ) ),
		$P( 74, 'Ona', 'Mejoras Font', '2015-07-07', 'Lobatos' ),
	),
	$grupo['crm_id']
);
Grupo::forzar( $grupo );
$crm->activar();
Escritura::usar_para_pruebas( $crm );
Config::guardar( Config::defaults() ); // Modelos y tallas de ASDE durante la prueba (se restaura al final).

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
	update_user_meta( $id, Usuarios::META_GRUPO, $grupo['id'] );
	update_user_meta( $id, Usuarios::META_ESTADO, Usuarios::ACTIVO );
	foreach ( $meta as $k => $v ) {
		add_user_meta( $id, $k, $v );
	}
	$ids[ $login ] = $id;
	return $id;
};
$u_secre = $crear( 'prueba-v029-secre', array( Roles::SECRETARIA ), array( Usuarios::META_SOCIO => '990071' ) );
$u_coord = $crear( 'prueba-v029-coord', array( Roles::COORDINACION ), array( Usuarios::META_SOCIO => '990072' ) );
$u_fam   = $crear( 'prueba-v029-fam', array( Roles::TUTOR ), array( Usuarios::META_REPRESENTADO => '990073' ) );
$como    = static function ( int $uid ): Ambito {
	wp_set_current_user( $uid );
	Ambito::olvidar( $uid );
	return Ambito::actual();
};
add_filter( 'send_auth_cookies', '__return_false' );
$redirigir = static function ( $destino ) {
	throw new RuntimeException( 'asde-redirect:' . $destino );
};
add_filter( 'wp_redirect', $redirigir, 1 );
$enviar = static function ( $vista, array $campos, string $nonce ): void {
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
};
$pintar = static function ( $vista, array $get ): string {
	$_GET = $get;
	try {
		return $vista->render();
	} finally {
		$_GET = array();
	}
};
$avisos = static fn( int $uid ): string => wp_json_encode( get_transient( 'asde_ap_notices_' . $uid ) ?: array() );

try {
	// -----------------------------------------------------------------------
	echo "\n== D. Cambios propuestos agrupados por persona ==\n";
	$repo = PersonaRepository::make();
	$nil  = $repo->obtener( '990073' );
	$como( $u_fam );
	Cambios::proponer(
		$nil,
		array(
			'phone_mobile' => array( '600000071', '600000099' ),
			'email1'       => array( 'nil-v029@example.invalid', 'nil-nuevo@example.invalid' ),
			'apodo_c'      => array( '', 'Peque' ),
		)
	);
	$pend = array_column( Cambios::listado( Cambios::PENDIENTE, '990073' ), null, 'campo' );
	$ok( 3 === count( $pend ), 'la familia propone tres cambios de la misma persona' );

	$como( $u_secre );
	$lista = $pintar( new CambiosView(), array() );
	$ok( 1 === substr_count( $lista, 'Mejoras Sala' ) && str_contains( $lista, 'Revisar (3)' ) && str_contains( $lista, 'socio=990073' ), 'la lista agrupa por persona: una fila con «Revisar (3)»' );
	$crm->personas[2]['email1'] = 'otro@example.invalid'; // El email cambia en el CRM después de la propuesta.
	$ventana = $pintar( new CambiosView(), array( 'socio' => '990073' ) );
	$ok( 3 === substr_count( $ventana, 'name="cambios[]"' ) && str_contains( $ventana, 'data-asde-marcar-todos="cambios[]"' ) && str_contains( $ventana, 'Aprobar los seleccionados' ) && str_contains( $ventana, 'Rechazar los seleccionados' ), 'la ventana de la persona: una casilla por cambio, «Todos» y un solo botón' );
	$ok( 1 === substr_count( $ventana, 'name="visto[]"' ) && str_contains( $ventana, 'asde-fila--aviso' ) && ! preg_match( '/data-asde-marcar-todos="cambios\[\]" checked/', $ventana ), 'el cambio cuyo dato cambió en el CRM viene sin marcar y avisado' );

	$antes_escr = count( $crm->escrituras );
	$enviar(
		new CambiosView(),
		array(
			'asde_action' => 'aprobar_varios',
			'socio'       => '990073',
			'cambios'     => array( (string) $pend['phone_mobile']['id'], (string) $pend['email1']['id'], (string) $pend['apodo_c']['id'] ),
		),
		'asde_revisar_socio_990073'
	);
	$escr = array_slice( $crm->escrituras, $antes_escr );
	$ok( 1 === count( $escr ) && array( 'phone_mobile', 'apodo_c' ) === array_keys( $escr[0]['campos'] ) && '600000099' === $crm->campo( '990073', 'phone_mobile' ), 'aprobar los marcados: una sola escritura en el CRM con los dos que no tenían conflicto' );
	$ok( Cambios::APLICADO === Cambios::obtener( (int) $pend['apodo_c']['id'] )['estado'] && Cambios::PENDIENTE === Cambios::obtener( (int) $pend['email1']['id'] )['estado'] && str_contains( $avisos( $u_secre ), '2 cambios aprobados' ) && str_contains( $avisos( $u_secre ), 'no se ha aplicado' ), 'el del conflicto sigue pendiente y se avisa' );
	delete_transient( 'asde_ap_notices_' . $u_secre );
	$enviar(
		new CambiosView(),
		array(
			'asde_action' => 'aprobar_varios',
			'socio'       => '990073',
			'cambios'     => array( (string) $pend['email1']['id'] ),
			'visto'       => array( (string) $pend['email1']['id'] ),
		),
		'asde_revisar_socio_990073'
	);
	$ok( Cambios::APLICADO === Cambios::obtener( (int) $pend['email1']['id'] )['estado'] && 'nil-nuevo@example.invalid' === $crm->campo( '990073', 'email1' ), 'marcado después de ver el aviso: se aplica igualmente' );

	$como( $u_fam );
	Cambios::proponer( $nil, array( 'phone_home' => array( '', '971000073' ) ) );
	$propio = Cambios::listado( Cambios::PENDIENTE, '990073' )[0];
	$como( $u_secre );
	$enviar( new CambiosView(), array( 'asde_action' => 'rechazar_varios', 'socio' => '990073', 'cambios' => array( (string) $propio['id'] ), 'motivo' => '' ), 'asde_revisar_socio_990073' );
	$ok( Cambios::PENDIENTE === Cambios::obtener( (int) $propio['id'] )['estado'], 'rechazar sin motivo: no se rechaza' );
	$enviar( new CambiosView(), array( 'asde_action' => 'rechazar_varios', 'socio' => '990073', 'cambios' => array( (string) $propio['id'] ), 'motivo' => 'No es su teléfono' ), 'asde_revisar_socio_990073' );
	$ok( Cambios::RECHAZADO === Cambios::obtener( (int) $propio['id'] )['estado'] && 'No es su teléfono' === Cambios::obtener( (int) $propio['id'] )['motivo'], 'rechazar los marcados con un motivo' );

	// Cambios hechos directamente en la ficha (de la auditoría).
	Logger::log( 'crm_ficha_editada', 'socio', '990074', array( 'campos' => array( 'phone_mobile' ), 'rechazados' => array(), 'motivo' => 'ficha' ) );
	Logger::log( 'crm_ficha_editada', 'socio', '990074', array( 'campos' => array( 'numsocioantiguo_enficha_c' ), 'rechazados' => array(), 'motivo' => 'numeracion' ) );
	// Revisión de código: un cambio que hizo una cuenta de OTRO grupo (la persona está en los dos) no se ve aquí.
	$u_otro = $crear( 'prueba-v029-otro', array( Roles::SECRETARIA ), array() );
	update_user_meta( $u_otro, Usuarios::META_GRUPO, 'otro-grupo-de-prueba' );
	wp_set_current_user( $u_otro );
	Logger::log( 'crm_ficha_editada', 'socio', '990074', array( 'campos' => array( 'email1' ), 'rechazados' => array(), 'motivo' => 'ficha' ) );
	$como( $u_secre );
	$dir = Cambios::directos( 90, '990074' );
	$ok( 1 === count( $dir ) && array( 'phone_mobile' ) === $dir[0]['campos'] && 'ficha' === $dir[0]['motivo'], 'cambios directos: los de la ficha sí, los de la numeración no' );
	$ok( ! array_filter( Cambios::directos( 90, '990073' ), static fn( $d ) => 'cambios_aprobados' === $d['motivo'] ), 'las propuestas aprobadas no salen dos veces' );
	$res_lista = $pintar( new CambiosView(), array( 'ver' => 'resueltos' ) );
	$ok( str_contains( $res_lista, 'Mejoras Font' ) && str_contains( $res_lista, 'Mejoras Sala' ) && str_contains( $res_lista, 'ver=resueltos' ), '«Resueltos» ya no sale vacío: propuestas revisadas y cambios directos, por persona' );
	$res_ventana = $pintar( new CambiosView(), array( 'ver' => 'resueltos', 'socio' => '990074' ) );
	$ok( str_contains( $res_ventana, 'Hechos directamente en la ficha' ) && str_contains( $res_ventana, 'Móvil' ) && str_contains( $res_ventana, 'prueba-v029-secre' ) && ! str_contains( $res_ventana, 'name="cambios[]"' ), 'su ventana: qué datos, quién y cuándo (sin casillas)' );
	$ok( in_array( 'cambios', \AsdeBalears\AreaPrivada\Frontend\Router::MODALES, true ), 'cambios se abre en la ventana emergente' );

	// -----------------------------------------------------------------------
	echo "\n== E. Camisas del uniforme en el inventario ==\n";
	$a_coord = $como( $u_coord );
	$camisa  = TiposTable::camisa();
	$ok( $camisa > 0 && $camisa === TiposTable::camisa() && TiposTable::es_camisa( $camisa ) && TiposTable::NOMBRE_CAMISA === TiposTable::obtener( $camisa )['nombre'], 'el tipo «Camisa uniforme» se crea solo y una vez' );
	$ok( is_wp_error( TiposTable::borrar( $camisa ) ), 'y no se puede borrar' );
	$ok( 'femenina' === Config::modelo_de( 'Chica', Config::de() ) && 'masculina' === Config::modelo_de( 'camisa masculina', Config::de() ) && '' === Config::modelo_de( 'otra', Config::de() ) && 'XL' === Config::talla_de( Config::de(), 'masculina', 'xl' ), 'modelo por «chica/chico» o por su nombre; talla sin distinguir mayúsculas' );
	$mal = MaterialesTable::validar( array( 'descripcion' => '', 'tipo' => (string) $camisa, 'cantidad' => '2' ) );
	$ok( is_wp_error( $mal ) && str_contains( $mal->get_error_message(), 'modelo (chico o chica)' ), 'una camisa sin modelo ni talla no se guarda' );
	$d1 = MaterialesTable::validar( array( 'descripcion' => '', 'tipo' => (string) $camisa, 'cantidad' => '2', 'modelo' => 'chica', 'talla' => 'm' ) );
	$ok( is_array( $d1 ) && 'femenina' === $d1['modelo'] && 'M' === $d1['talla'] && 'Camisa femenina (chica), talla M' === $d1['descripcion'], 'con modelo y talla, la descripción se pone sola' );
	$d2 = MaterialesTable::validar( array( 'descripcion' => 'Tienda', 'tipo' => '0', 'cantidad' => '1', 'modelo' => 'chica', 'talla' => 'M' ) );
	$ok( is_array( $d2 ) && '' === $d2['modelo'] && '' === $d2['talla'], 'en otro tipo, modelo y talla no se guardan' );
	$m1 = MaterialesTable::crear( $d1 );
	$m2 = MaterialesTable::crear( array( 'cantidad' => 1 ) + $d1 );
	$m3 = MaterialesTable::crear( MaterialesTable::validar( array( 'descripcion' => '', 'tipo' => (string) $camisa, 'cantidad' => '1', 'modelo' => 'chico', 'talla' => 'S' ) ) );
	$stock = MaterialesTable::camisas();
	$ok( 3 === ( $stock['femenina']['M'] ?? 0 ) && 1 === ( $stock['masculina']['S'] ?? 0 ), 'lo que hay, por modelo y talla (sumando los materiales)' );

	$imp = Importador::importar(
		array(
			2 => array( 'descripcion' => '', 'tipo' => TiposTable::NOMBRE_CAMISA, 'cantidad' => '2', 'modelo' => 'chico', 'talla' => 'xl' ),
			3 => array( 'descripcion' => '', 'tipo' => 'camisa UNIFORME', 'cantidad' => '1', 'modelo' => 'chico', 'talla' => '' ),
		),
		false
	);
	$ok( 1 === $imp['importados'] && 1 === count( $imp['errores'] ) && 3 === $imp['errores'][0]['fila'] && 2 === ( MaterialesTable::camisas()['masculina']['XL'] ?? 0 ), 'importar: columnas «Modelo» y «Talla»; sin talla, la fila no entra' );
	$ok( str_contains( Importador::ejemplo(), 'Modelo (camisas)' ) && str_contains( Importador::ejemplo(), TiposTable::NOMBRE_CAMISA ), 'el CSV de ejemplo lleva una camisa' );

	echo "\n== F. Servir, devolver y asignar camisas en la gestión de uniformes ==\n";
	$cfg    = Config::de();
	$linea  = static fn( string $modelo, string $talla ): array => array(
		'modelo'   => $modelo,
		'nombre'   => $cfg['modelos'][ $modelo ]['nombre'],
		'talla'    => $talla,
		'especial' => false,
		'precio'   => $cfg['precio_camisa'],
	);
	$p_solo = PedidosTable::crear( '990073', array( 'scouts' ), false, array( 'camisa' => $linea( 'femenina', 'M' ), 'insignias' => array() ), 25.0, false, '' );
	$p_ins  = PedidosTable::crear( '990074', array( 'lobatos' ), false, array( 'camisa' => $linea( 'masculina', 'S' ), 'insignias' => array( array( 'id' => 'grupo', 'nombre' => 'Insignia de grupo', 'cantidad' => 1, 'precio' => 3.0 ) ) ), 28.0, false, '' );
	$p_sin  = PedidosTable::crear( '990074', array( 'lobatos' ), false, array( 'camisa' => $linea( 'masculina', 'S' ), 'insignias' => array() ), 25.0, false, '' );
	$ok( Servicio::puede_inventario() && Servicio::se_puede_servir( PedidosTable::obtener( $p_solo ) ), 'Coordinación (con el inventario) puede servir camisas del inventario' );
	$como( $u_secre );
	$ok( ! Servicio::puede_inventario(), 'Secretaría no (no incluye pedidos en el pedido al proveedor)' );
	$como( $u_coord );

	$ok( true === Servicio::servir( PedidosTable::obtener( $p_solo ) ), 'servir del inventario la camisa de un pedido' );
	$ps = PedidosTable::obtener( $p_solo );
	$ok( $m1 === $ps['inventario_id'] && PedidosTable::REF_INVENTARIO === $ps['pedido_ref'] && null !== $ps['pedido_en'] && 2 === MaterialesTable::camisas()['femenina']['M'], 'sale del material más antiguo; el pedido pasa a «pendiente de entregar»' );
	$ok( true === Servicio::servir( PedidosTable::obtener( $p_ins ) ) && null === PedidosTable::obtener( $p_ins )['pedido_en'], 'con insignias, el pedido sigue pendiente de pedir (por las insignias)' );
	$resumen_prov = Servicio::resumen( array( PedidosTable::obtener( $p_ins ) ), $cfg );
	$ok( 0 === $resumen_prov['total_camisas'] && 1 === count( $resumen_prov['insignias'] ), 'y su camisa ya no cuenta para el pedido al proveedor' );
	$sin = Servicio::servir( PedidosTable::obtener( $p_sin ) );
	$ok( is_wp_error( $sin ) && str_contains( $sin->get_error_message(), 'No queda ninguna camisa' ) && 0 === PedidosTable::obtener( $p_sin )['inventario_id'], 'sin camisas de esa talla, no se sirve' );
	$ok( true === Servicio::devolver( PedidosTable::obtener( $p_solo ) ) && 3 === MaterialesTable::camisas()['femenina']['M'] && null === PedidosTable::obtener( $p_solo )['pedido_en'] && 0 === PedidosTable::obtener( $p_solo )['inventario_id'], 'devolver la camisa: vuelve al inventario y el pedido, a pendiente de pedir' );

	// Precio (petición del usuario): 25 € a los asociados; a un scouter, la primera camisa gratis y desde la segunda, 25 €.
	$ok( 25.0 === Servicio::precio_camisa( '990074', false, $cfg ) && 0.0 === Servicio::precio_camisa( '990071', true, $cfg ) && 25.0 === Servicio::precio_camisa( '990071', true, array( 'primera_gratis' => false ) + $cfg ), 'precio de la camisa: 25 € a los asociados, la primera de un scouter gratis (se puede apagar en la configuración)' );
	$ona  = $repo->obtener( '990074' );
	$sara = $repo->obtener( '990071' );
	$asig = Servicio::asignar( $sara, 'femenina', 'm', 'Talla grande' );
	$pa   = is_int( $asig ) ? PedidosTable::obtener( $asig ) : null;
	$ok( $pa && '990071' === $pa['socio'] && 'M' === $pa['lineas']['camisa']['talla'] && $pa['inventario_id'] > 0 && null !== $pa['pedido_en'] && null !== $pa['pago_en'] && PedidosTable::REF_SIN_COSTE === $pa['pago_ref'] && 2 === MaterialesTable::camisas()['femenina']['M'], 'asignar una camisa a quien no la pidió: pedido creado, servido y, la primera de un scouter, 0 € y pagado «sin coste»' );
	$ok( 25.0 === Servicio::precio_camisa( '990071', true, $cfg ), 'la segunda camisa de ese scouter ya cuesta 25 €' );
	$asig2 = Servicio::asignar( $ona, 'masculina', 'S', '' );
	$ok( is_wp_error( $asig2 ), 'asignar una talla que no queda: error' );

	// Desde la pantalla: la tarjeta, la ventana de asignar y cancelar un pedido servido.
	$gestion = $pintar( new UniformesGestionView(), array() );
	$ok( str_contains( $gestion, 'Camisas del inventario' ) && str_contains( $gestion, 'asignar=1' ) && str_contains( $gestion, 'Hay en el inventario' ) && str_contains( $gestion, 'Camisa del inventario' ), 'gestión: lo que hay, «Asignar una camisa» y qué pedidos se pueden servir' );
	$asignar = $pintar( new UniformesGestionView(), array( 'asignar' => '1' ) );
	$ok( str_contains( $asignar, 'value="990073"' ) && str_contains( $asignar, 'value="femenina|M"' ) && ! str_contains( $asignar, 'value="masculina|S"' ), 'la ventana de asignar: socios y solo las tallas que quedan' );
	$enviar( new UniformesGestionView(), array( 'asde_action' => 'uniforme_servir', 'pedido' => (string) $p_solo ), 'asde_uniforme_' . $p_solo );
	$ok( $m1 === PedidosTable::obtener( $p_solo )['inventario_id'] && 1 === MaterialesTable::camisas()['femenina']['M'], 'servir desde la ficha del pedido' );
	$enviar( new UniformesGestionView(), array( 'asde_action' => 'uniforme_cancelar', 'pedido' => (string) $p_solo, 'motivo' => 'Ya no la quiere' ), 'asde_uniforme_' . $p_solo );
	$ok( null !== PedidosTable::obtener( $p_solo )['cancelado_en'] && 2 === MaterialesTable::camisas()['femenina']['M'], 'cancelar un pedido servido devuelve la camisa al inventario' );
	$enviar( new UniformesGestionView(), array( 'asde_action' => 'uniforme_asignar', 'socio' => '990073', 'camisa' => 'masculina|XL', 'nota' => '' ), 'asde_uniformes_asignar' );
	$ultimo = PedidosTable::obtener( $max_id( PedidosTable::table() ) );
	$ok( $ultimo && '990073' === $ultimo['socio'] && 25.0 === (float) $ultimo['total'] && null === $ultimo['pago_en'] && 1 === MaterialesTable::camisas()['masculina']['XL'], 'asignar desde la ventana a un asociado: 25 € y pendiente de pago' );
	$ok( true === Servicio::cancelar( PedidosTable::obtener( $p_ins ), 'Prueba' ) && 1 === ( MaterialesTable::camisas()['masculina']['S'] ?? 0 ) && 0 === PedidosTable::obtener( $p_ins )['inventario_id'], 'cancelar un pedido con la camisa servida (con insignias, aún sin pedir) la devuelve al inventario' );
	$ok( str_contains( $leer( 'src/Frontend/Views/UniformeView.php' ), "Servicio::cancelar( \$p, 'Cancelado por el socio/a o su familia' )" ), 'también cuando lo cancela la familia desde «Mi uniforme»' );
	$ok( $m2 > $m1 && $m3 > $m2, '(materiales de la prueba creados)' );
} finally {
	Escritura::usar_para_pruebas( null );
	$crm->desactivar();
	remove_filter( 'pre_http_request', $cortar_crm, 2 );
	remove_filter( 'wp_redirect', $redirigir, 1 );
	remove_filter( 'pre_wp_mail', '__return_true' );
	wp_using_ext_object_cache( $cache_externa );
	View::en_modal( false );
	wp_set_current_user( 0 );

	if ( null === $cfg_antes ) {
		delete_option( 'asde_ap_uniformes_' . $grupo['numero'] );
	} else {
		update_option( 'asde_ap_uniformes_' . $grupo['numero'], $cfg_antes, false );
	}
	$lista = implode( ',', array_map( 'intval', $ids ) ) ?: '0';
	foreach ( array( array( Cambios::table(), $cam_antes ), array( MaterialesTable::table(), $mat_antes ), array( TiposTable::table(), $tip_antes ), array( PedidosTable::table(), $ped_antes ) ) as list( $tabla, $antes ) ) {
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id > %d', $tabla, $antes ) );
	}
	// Un tipo «Camisa uniforme» que ya existía y la prueba marcó: como estaba.
	$wpdb->query( $wpdb->prepare( "UPDATE %i SET especial = '' WHERE id <= %d", TiposTable::table(), $tip_antes ) );
	foreach ( $tip_especial as $t ) {
		$wpdb->update( TiposTable::table(), array( 'especial' => $t['especial'] ), array( 'id' => (int) $t['id'] ) );
	}
	$wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE id > %d AND ( user_id IN ($lista) OR objeto_id IN ('990071','990072','990073','990074') )", Logger::table(), $log_antes ) ); // phpcs:ignore WordPress.DB
	foreach ( $ids as $uid ) {
		delete_transient( 'asde_ap_notices_' . $uid );
		Ambito::olvidar( (int) $uid );
		wp_delete_user( (int) $uid );
	}
	$ahora = array_column( (array) $wpdb->get_results( 'SELECT * FROM ' . SociosTable::table(), ARRAY_A ), null, 'num_socio_asde' ); // phpcs:ignore WordPress.DB
	foreach ( array_diff_key( $ahora, $socios_antes ) as $asde => $f ) {
		$wpdb->delete( SociosTable::table(), array( 'num_socio_asde' => (string) $asde ) );
	}
	Grupo::forzar( null );

	$quedan = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users} WHERE user_login LIKE 'prueba-v029-%'" ); // phpcs:ignore WordPress.DB
	$filas  = 0;
	foreach ( array( array( Cambios::table(), $cam_antes ), array( MaterialesTable::table(), $mat_antes ), array( TiposTable::table(), $tip_antes ), array( PedidosTable::table(), $ped_antes ) ) as list( $tabla, $antes ) ) {
		$filas += (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE id > %d', $tabla, $antes ) );
	}
	$ok( 0 === $quedan && 0 === $filas, 'limpieza: sin usuarios, cambios, material, tipos ni pedidos de la prueba' );
}

echo $fails ? "\n$fails FALLOS\n" : "\nTODO OK (nada tocado en el CRM real; el sitio queda como estaba)\n";
