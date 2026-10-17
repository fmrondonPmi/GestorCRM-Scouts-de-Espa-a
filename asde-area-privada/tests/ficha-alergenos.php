<?php
/**
 * Pruebas de la v0.41 (B3, entorno LOCAL, doc 49): el selector de alérgenos de la ficha de salud — catálogo por pestañas, el texto que
 * se guarda en el CRM (separado por comas), cómo se interpreta lo escrito (castellano y catalán, a mano o elegido), la ventana de la
 * ficha y su JavaScript. Sin CRM ni datos de personas. Ejecutar desde app/public del site Local:
 *
 *   wp eval-file <ruta completa>/wp-content/plugins/asde-area-privada/tests/ficha-alergenos.php
 */
defined( 'ABSPATH' ) || exit;

use AsdeBalears\AreaPrivada\Fichas\CamposFicha;
use AsdeBalears\AreaPrivada\Fichas\FichaService;
use AsdeBalears\AreaPrivada\Frontend\TemplateLoader;
use AsdeBalears\AreaPrivada\Menus\Alergenos;
use AsdeBalears\AreaPrivada\Menus\SelectorAlergenos as S;

$fails = 0;
$ok    = function ( $cond, string $label ) use ( &$fails ) {
	if ( ! $cond ) {
		++$fails;
	}
	echo ( $cond ? 'OK   ' : 'FAIL ' ), $label, "\n";
};

echo "== Parte A · El catálogo ==\n";
$ok( array( 'alergenos', 'alimentos', 'intolerancias' ) === array_keys( S::PESTANAS ), 'tres pestañas: los 14 alérgenos, alimentos e intolerancias' );
$ok( 14 === count( S::PESTANAS['alergenos']['items'] ) && array_keys( Alergenos::LISTA ) === array_merge( ...array_values( S::PESTANAS['alergenos']['items'] ) ), 'la primera lleva los 14 del Reglamento, cada uno con su código' );
$mal = array();
foreach ( S::PESTANAS as $p ) {
	foreach ( $p['items'] as $etiqueta => $codigos ) {
		if ( $codigos !== Alergenos::limpiar( $codigos ) ) {
			$mal[] = $etiqueta;
		}
		if ( str_contains( $etiqueta, ',' ) || str_contains( $etiqueta, ';' ) ) {
			$mal[] = $etiqueta . ' (separador)';
		}
	}
}
$ok( ! $mal, 'todos los códigos son del catálogo y ninguna etiqueta lleva coma ni punto y coma' . ( $mal ? ': ' . implode( ', ', $mal ) : '' ) );
$ok( S::PESTANAS['alimentos']['items']['Marisco'] === array( 'crustaceos', 'moluscos' ) && S::PESTANAS['intolerancias']['items']['Lactosa'] === array( 'lacteos' ) && S::PESTANAS['intolerancias']['items']['Celiaquía'] === array( 'gluten' ), 'marisco son crustáceos y moluscos; lactosa, lácteos; celiaquía, gluten' );
$ok( isset( S::CAMPOS['alimentacion_alerg_alimentos_c'], S::CAMPOS['alimentacion_intol_alimentos_c'] ) && isset( CamposFicha::todos()['alimentacion_alerg_alimentos_c'] ), 'se aplica a los campos de alergias e intolerancias alimentarias de la ficha' );

echo "\n== Parte B · Lo que se guarda y cómo se lee ==\n";
$ok( array( 'Gluten', 'Huevos', 'kiwi' ) === S::partes( 'Gluten, Huevos; kiwi / HUEVOS' ), 'se separa por comas, punto y coma o barra, sin repetidos' );
$ok( array() === S::partes( ' , ;; ' ), 'solo separadores no son nada' );
$r = S::interpretar( 'Gluten, Huevos, Leche y lácteos' );
$ok( array( 'gluten', 'huevos', 'lacteos' ) === $r['codigos'] && array() === $r['sin_reconocer'], 'lo elegido en el selector se interpreta tal cual' );
$r = S::interpretar( 'Marisco, Lactosa' );
$ok( array( 'gluten' ) !== $r['codigos'] && array( 'crustaceos', 'lacteos', 'moluscos' ) === $r['codigos'], 'marisco y lactosa → crustáceos, moluscos y lácteos' );
$r = S::interpretar( 'Celiaquía' );
$ok( array( 'gluten' ) === $r['codigos'], 'celiaquía → gluten' );
$r = S::interpretar( 'alergia a los frutos secos y al marisco' );
$ok( in_array( 'frutos_cascara', $r['codigos'], true ) && in_array( 'crustaceos', $r['codigos'], true ), 'un texto escrito a mano («alergia a los frutos secos y al marisco») también se entiende' );
$r = S::interpretar( 'Ous, fruits secs, formatge, peix' );
$ok( array( 'huevos', 'pescado', 'lacteos', 'frutos_cascara' ) === $r['codigos'] || ( count( $r['codigos'] ) === 4 && empty( $r['sin_reconocer'] ) ), 'en catalán: ous, fruits secs, formatge, peix' );
$r = S::interpretar( 'Pimiento, Gluten, picante' );
$ok( array( 'gluten' ) === $r['codigos'] && array( 'Pimiento', 'picante' ) === $r['sin_reconocer'], 'lo que no se reconoce (pimiento, picante) no se pierde: sale aparte para revisarlo a mano' );
$r = S::interpretar( 'capilla, mania, pop corn' );
$ok( ! in_array( 'apio', $r['codigos'], true ) && ! in_array( 'cacahuetes', $r['codigos'], true ), 'las palabras cortas solo cuentan enteras («capilla» no es apio; «mania» no es maní)' );
$ok( array( 'sesamo' ) === S::interpretar( 'Sèsam' )['codigos'] && array( 'soja' ) === S::interpretar( 'SOJA' )['codigos'], 'sin importar mayúsculas ni acentos' );
$ok( array() === S::interpretar( '' )['codigos'] && array() === S::interpretar( '' )['sin_reconocer'], 'vacío no es nada' );

echo "\n== Parte C · Alérgenos escritos a mano ==\n";
$ok( 'Kiwi verde' === S::limpiar_propio( "  Kiwi,\nverde; " ) || 'Kiwi verde' === S::limpiar_propio( 'Kiwi, verde' ), 'sin separadores ni saltos de línea' );
$ok( S::MAX_PROPIO === mb_strlen( S::limpiar_propio( str_repeat( 'a', 200 ) ) ) && 'x' === S::limpiar_propio( '<b>x</b>' ), 'con un máximo de 60 caracteres y sin etiquetas' );
$m = array( 'tipo' => 'varchar', 'len' => 255 );
$v = FichaService::normalizar( 'alimentacion_alerg_alimentos_c', 'Gluten, Huevos, Kiwi verde', $m );
$ok( 'Gluten, Huevos, Kiwi verde' === $v, 'el CRM recibe el texto separado por comas, tal cual' );
$ok( is_wp_error( FichaService::normalizar( 'alimentacion_alerg_alimentos_c', str_repeat( 'Gluten, ', 60 ), $m ) ), 'y se respeta el máximo de caracteres del campo del CRM' );

echo "\n== Parte D · La ventana de la ficha ==\n";
$h = TemplateLoader::render(
	'fichas/alergenos',
	array(
		'id'       => 'asde-f-alimentacion_alerg_alimentos_c',
		'name'     => 'campo[alimentacion_alerg_alimentos_c]',
		'valor'    => 'Gluten, Kiwi verde',
		'extra'    => '',
		'maxlen'   => 255,
		'etiqueta' => 'Alimentos que producen alergia',
		'inicial'  => 'alergenos',
	)
);
$ok( str_contains( $h, 'name="campo[alimentacion_alerg_alimentos_c]"' ) && str_contains( $h, 'value="Gluten, Kiwi verde"' ) && str_contains( $h, 'maxlength="255"' ), 'el campo de texto sigue siendo el que se envía (y sin JavaScript es lo que se ve)' );
$ok( 3 === substr_count( $h, 'role="tab"' ) && 3 === substr_count( $h, 'role="tabpanel"' ) && str_contains( $h, '<dialog' ), 'hay una ventana con tres pestañas y tres paneles' );
$ok( substr_count( $h, 'type="checkbox"' ) === 14 + count( S::PESTANAS['alimentos']['items'] ) + count( S::PESTANAS['intolerancias']['items'] ), 'una casilla por cada alérgeno, alimento e intolerancia' );
$ok( ! preg_match( '/<input type="checkbox"[^>]* name=/', $h ) && 1 === preg_match( '/data-asde-alerg-propio/', $h ), 'las casillas no llevan nombre (no se envían con el formulario) y hay sitio para escribir uno propio' );
$ok( str_contains( $h, 'data-asde-alerg-anadir' ) && str_contains( $h, 'data-asde-alerg-aceptar' ) && str_contains( $h, 'data-asde-alerg-cancelar' ), 'botones Añadir, Aceptar y Cancelar' );
$ok( str_contains( $h, 'aria-selected' ) && str_contains( $h, 'aria-labelledby' ) && str_contains( $h, 'role="alert"' ), 'accesible: pestañas, paneles y avisos con su rol' );
$ok( str_contains( $h, 'data-valor="Leche y lácteos"' ), 'lo que se guarda es el nombre en castellano' );

echo "\n== Parte E · El JavaScript y los ficheros ==\n";
$base = dirname( __DIR__ );
$js   = (string) file_get_contents( $base . '/assets/js/alergenos.js' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$ok( str_contains( $js, "asdeAlergenos" ) && str_contains( $js, 'showModal' ) && str_contains( $js, "'Enter' === e.key" ), 'el script existe, abre la ventana y Enter no envía la ficha' );
$ficha_js = (string) file_get_contents( $base . '/assets/js/ficha.js' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$ok( str_contains( $ficha_js, 'asdeAlergenos.iniciar' ), 'la ficha en la ventana emergente también lo inicia' );
$router = (string) file_get_contents( $base . '/src/Frontend/Router.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$ok( str_contains( $router, "'asde-alergenos'" ), 'se carga con la ficha' );
$plantilla = (string) file_get_contents( $base . '/templates/fichas/ficha.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$ok( str_contains( $plantilla, 'SelectorAlergenos::CAMPOS' ), 'la ficha usa el selector para los campos de alergias e intolerancias' );

echo $fails ? "\n$fails FALLOS\n" : "\nTODO OK (sin CRM ni datos personales)\n";
