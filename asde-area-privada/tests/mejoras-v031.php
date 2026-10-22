<?php
/**
 * Pruebas de la v0.31: listados (10 por página, contadores debajo,
 * zonas que se actualizan sin recargar), Control de pagos y Banco en una línea, cambio de
 * sección y lo que pidió la revisión de WordPress.org (sin <script>/<style>/manejadores en
 * línea, entradas saneadas). Solo lee ficheros y pinta plantillas con datos inventados: no toca
 * la base de datos ni el CRM.
 *
 *   wp eval-file wp-content/plugins/asde-area-privada/tests/mejoras-v031.php
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Prueba: se ejecuta con wp eval-file.
use AsdeBalears\AreaPrivada\Domain\Secciones;
use AsdeBalears\AreaPrivada\Frontend\Tabla;

defined( 'ABSPATH' ) || exit;

$fails = 0;
$ok    = function ( $cond, string $label ) use ( &$fails ) {
	if ( ! $cond ) {
		++$fails;
	}
	echo ( $cond ? 'OK   ' : 'FAIL ' ), $label, "\n";
};
$leer = static fn( string $rel ): string => (string) file_get_contents( ASDE_AP_DIR . $rel );

echo "== Listados ==\n";
$ok( range( 5, 50, 5 ) === Tabla::POR_PAGINA && 10 === Tabla::POR_PAGINA_DEFECTO, 'tamaños de página de 5 en 5 hasta 50; 10 por defecto' );
wp_set_current_user( 0 );
$ok( 10 === Tabla::por_pagina_guardada(), 'sin elegir, 10 por página' );
$pers = $leer( 'templates/personas/listado.php' );
$ok( strpos( $pers, 'asde-contador' ) > strpos( $pers, '</table>' ) && ! str_contains( $pers, "<div data-asde-parcial=\"personas-lista\">\n\t<p class=\"asde-muted\">" ), 'Personas: el contador, debajo de la lista' );
$ok( str_contains( $leer( 'src/Frontend/Views/PersonasView.php' ), "'membresia' // v0.31" ), 'Personas: por defecto, ordenada por sección' );
$ctrl = $leer( 'templates/tesoreria/control.php' );
$ok( str_contains( $ctrl, 'asde-filter--una-linea" data-asde-parcial-form="pagos-lista"' ) && str_contains( $ctrl, '<div data-asde-parcial="pagos-lista">' ) && str_contains( $ctrl, 'data-asde-buscar' ), 'Control de pagos: filtro en una línea que actualiza solo la lista' );
$ok( ! str_contains( $ctrl, '<a class="button" href="<?php echo esc_url( add_query_arg( array( \'familia\'' ) && str_contains( $ctrl, 'asde-tooltip" title=' ) && ! str_contains( $ctrl, "' hijo(s)'" ), 'Control de pagos: «Ver / registrar pago» es un enlace y los hijos van en el tooltip del importe' );
$banco = $leer( 'templates/tesoreria/banco.php' );
$ok( strpos( $banco, 'id="asde-banco-q"' ) < strpos( $banco, 'id="asde-banco-estado"' ) && strpos( $banco, 'asde-contador' ) > strpos( $banco, '</table>' ), 'Banco: primero el cuadro de búsqueda y el contador debajo' );
foreach ( array( 'tienda/gestion' => 'uniformes-lista', 'inventario/compras' => 'inv-compras', 'informes/resultado' => 'informe-resultado' ) as $tpl => $zona ) {
	$ok( str_contains( $leer( "templates/$tpl.php" ), 'data-asde-parcial="' . $zona . '"' ), "$tpl: la lista se actualiza sin recargar ($zona)" );
}
$router = $leer( 'src/Frontend/Router.php' );
$ok( str_contains( $router, "! in_array( \$key, Pages::PUBLICAS, true ) && ! wp_script_is( 'asde-parcial', 'enqueued' )" ), 'parcial.js se carga en todas las páginas del área' );

echo "\n== Cambio de sección ==\n";
$ok( array( 'lobatos' ) === Secciones::destinos_cambio( 'castores' ) && Secciones::ADULTAS === Secciones::destinos_cambio( 'rovers' ), 'un educando solo pasa a la siguiente por edad (de Rovers, a Scouters o Voluntariado, P-119)' );
$ok( ! in_array( 'scouters', Secciones::destinos_cambio( 'scouters' ), true ) && in_array( 'castores', Secciones::destinos_cambio( 'voluntariado' ), true ), 'un adulto cambia a cualquier otra sección' );

echo "\n== Revisión de WordPress.org ==\n";
$en_linea = array();
$etiquetas = array();
$it        = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( ASDE_AP_DIR, FilesystemIterator::SKIP_DOTS ) );
foreach ( $it as $f ) {
	$ruta = str_replace( '\\', '/', (string) $f );
	if ( ! str_ends_with( $ruta, '.php' ) || preg_match( '#/(tests|vendor|languages)/#', $ruta ) ) {
		continue;
	}
	$s = (string) file_get_contents( $ruta );
	if ( preg_match( '/\son(click|submit|change|input|load)="/', $s ) ) {
		$en_linea[] = basename( $ruta );
	}
	if ( preg_match( '/<(script|style)[\s>]/', preg_replace( '#^\s*(//|\*|/\*).*$#m', '', $s ) ) && ! str_ends_with( $ruta, 'Xlsx.php' ) ) {
		$etiquetas[] = basename( $ruta );
	}
}
$ok( ! $en_linea, 'ningún onclick/onsubmit/onchange en el PHP' . ( $en_linea ? ': ' . implode( ', ', $en_linea ) : '' ) );
$ok( ! $etiquetas, 'ningún <script> ni <style> escrito en el PHP' . ( $etiquetas ? ': ' . implode( ', ', $etiquetas ) : '' ) );
$area = $leer( 'assets/js/area.js' );
$ok( str_contains( $area, 'data-asde-confirmar' ) && str_contains( $area, 'data-asde-autoenviar' ) && str_contains( $area, 'data-asde-imprimir-pagina' ), 'area.js atiende confirmar, autoenviar e imprimir' );
$ok( 'Hola' === asde_js( 'Hola' ) && '¿Borrar A?' === asde_js( '¿Borrar %s?', 'A' ), 'asde_js() da el texto sin escapar (se escapa con esc_attr)' );
$ok( is_file( ASDE_AP_DIR . 'assets/css/admin.css' ) && is_file( ASDE_AP_DIR . 'assets/js/admin.js' ) && str_contains( $leer( 'src/Plugin.php' ), 'Admin\\Recursos' ), 'wp-admin: CSS y JS comunes encolados (Admin\\Recursos)' );
$sin = array();
foreach ( array( 'src/Audit/Logger.php', 'src/Auth/Intentos.php', 'src/Auth/Limite.php', 'src/Install/AppRetirada.php', 'src/Sepa/Mandatos.php', 'src/Idiomas/Idioma.php' ) as $rel ) {
	if ( preg_match( '/\$_(SERVER|COOKIE)\[[^\]]+\]\s*\?\?/', $leer( $rel ) ) ) {
		$sin[] = $rel;
	}
}
$ok( ! $sin, '$_SERVER y $_COOKIE se leen saneados' . ( $sin ? ': ' . implode( ', ', $sin ) : '' ) );

echo $fails ? "\n$fails FALLOS\n" : "\nTODO OK (solo lectura de ficheros)\n";
