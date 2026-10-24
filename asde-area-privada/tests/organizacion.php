<?php
/**
 * Pruebas de la «Organización federada» (v0.9, entorno LOCAL). Ejecutar desde app/public del site Local:
 *
 *   wp eval-file wp-content/plugins/asde-area-privada/tests/organizacion.php
 *
 * - Las 17 organizaciones, la migración de una instalación de Scouts de Balears y
 *   la validación al guardar.
 * - El nº de socio local con otro código de organización (y sin código).
 * - El coste federativo: edad de corte, otros conceptos (por asociado, educando,
 *   adulto o grupo, también descuentos) y rondas guardadas antes de la v0.9.
 * Deja la configuración como estaba. No usa el CRM.
 */
defined( 'ABSPATH' ) || exit;

use AsdeBalears\AreaPrivada\Domain\Grupo;
use AsdeBalears\AreaPrivada\Domain\Grupos;
use AsdeBalears\AreaPrivada\Domain\NumeroSocio;
use AsdeBalears\AreaPrivada\Domain\Organizacion;
use AsdeBalears\AreaPrivada\Domain\Persona;
use AsdeBalears\AreaPrivada\Install\Entorno;
use AsdeBalears\AreaPrivada\Socios\SociosTable;
use AsdeBalears\AreaPrivada\Tesoreria\Calculadora;
use AsdeBalears\AreaPrivada\Tesoreria\Config;

$fails = 0;
$ok    = function ( $cond, string $label ) use ( &$fails ) {
	if ( ! $cond ) {
		++$fails;
	}
	echo ( $cond ? 'OK   ' : 'FAIL ' ), $label, "\n";
};
$original_org = get_option( Organizacion::OPTION, null );
$original_tes = get_option( 'asde_ap_tesoreria', null );
$original_grp = get_option( 'asde_ap_grupos', null );
// Se restaura pase lo que pase (también si la prueba se corta con un error):
// con otro código, los números de socio del sitio dejarían de reconocerse.
$restaurar = static function () use ( $original_org, $original_tes, $original_grp ) {
	foreach ( array( Organizacion::OPTION => $original_org, 'asde_ap_tesoreria' => $original_tes, 'asde_ap_grupos' => $original_grp ) as $opcion => $valor ) {
		if ( null === $valor ) {
			delete_option( $opcion );
		} else {
			update_option( $opcion, $valor, false );
		}
	}
	Organizacion::olvidar();
	Grupos::olvidar();
};
register_shutdown_function( $restaurar );
// Las partes A y B prueban el nº de socio/a de un grupo SIN provincia guardada (sale del código de la
// organización): si el 684 del Local ya tiene una en «Datos del grupo» (v0.56), se aparta mientras dura.
if ( is_array( $original_grp ) && isset( $original_grp['684']['provincia'] ) ) {
	$sin_provincia = $original_grp;
	unset( $sin_provincia['684']['provincia'] );
	update_option( 'asde_ap_grupos', $sin_provincia, false );
	Grupos::olvidar();
}
Grupo::forzar( Grupos::por_numero( '684' ) );

echo "== Parte A · Organización federada ==\n";
$ok( 17 === count( Organizacion::FEDERADAS ) && 'Scouts de Balears' === Organizacion::FEDERADAS['balears'][0] && 'Euskadiko Esploratzaileak' === Organizacion::FEDERADAS['euskadi'][0], 'las 17 organizaciones federadas de scout.es' );
$ok( Organizacion::configurada() && 'balears' === Organizacion::clave() && '7' === Organizacion::codigo_antiguo() && 17.0 === Organizacion::federacion()['cuota_asociativa'], 'esta instalación (anterior a la v0.9) quedó como Scouts de Balears, código 7, con sus importes' );
$ok( '076840390T' === NumeroSocio::ejemplo_tutor(), 'el ejemplo de las ayudas sale de la provincia (07, del código de antes) y del grupo (076840390T)' );

delete_option( Organizacion::OPTION );
Organizacion::olvidar();
Organizacion::migrar();
$ok( 'balears' === Organizacion::clave() && 1.92 === Organizacion::federacion()['primas']['menores'], 'migración: una instalación con grupos y sin organización pasa a Scouts de Balears' );
// Un WordPress nuevo: sin grupos (simulado con un filtro, sin tocar los grupos guardados).
delete_option( Organizacion::OPTION );
Organizacion::olvidar();
add_filter( 'pre_option_asde_ap_grupos', '__return_empty_array' ); // false no sustituye la opción; un array vacío sí.
Organizacion::migrar();
remove_filter( 'pre_option_asde_ap_grupos', '__return_empty_array' );
$ok( ! Organizacion::configurada() && '' === Organizacion::nombre(), 'un WordPress nuevo (sin grupos) no elige organización: la elige quien lo instala' );
$ok( null === NumeroSocio::normalizar( '76840390', '684' ) && is_wp_error( SociosTable::asignar_siguiente( 'PRUEBA-ORG-SIN-CODIGO', '684', array() ) ), 'sin código no se reconoce ni se genera ningún número de socio' );
$ok( Entorno::FALTA === Entorno::comprobar()['organizacion'][0], '«Instalación y entorno» avisa de que falta la organización' );

$ok( is_wp_error( Organizacion::guardar( 'narnia', '7', array() ) ), 'solo organizaciones de la lista' );
$ok( is_wp_error( Organizacion::guardar( 'madrid', '0', array() ) ) && is_wp_error( Organizacion::guardar( 'madrid', '07', array() ) ) && is_wp_error( Organizacion::guardar( 'madrid', '1234', array() ) ) && is_wp_error( Organizacion::guardar( 'madrid', 'x', array() ) ), 'código: de 1 a 3 cifras y sin 0 inicial' );
$ok( true === Organizacion::guardar( 'madrid', '12', array() ) && 'Exploradores de Madrid' === Organizacion::nombre() && 'Madrid' === Organizacion::territorio(), 'se guarda la organización elegida y su nombre sale en los textos' );

echo "\n== Parte B · Nº de socio con otro código (12) ==\n";
$ok( '126840001' === NumeroSocio::formatear( '684', 1 ), 'formato: código + grupo + 4 cifras (126840001)' );
$ok( '126840390' === NumeroSocio::normalizar( '126840390', '684' ) && '126840390' === NumeroSocio::normalizar( '0126840390', '684' ) && '126840390' === NumeroSocio::normalizar( '6840390', '684' ), 'normaliza: correcto, con 0 inicial y sin el código' );
$ok( null === NumeroSocio::normalizar( '76840390', '684' ), 'un número con el código de otra organización no vale' );
$ok( '126840390' === NumeroSocio::socio_de_usuario_tutor( '126840390T' ) && '684' === NumeroSocio::grupo_de_login( '126840390T', array( '684' ) ) && '684' === NumeroSocio::grupo_de_login( '126840390', array( '684' ) ), 'cuenta familiar y grupo del usuario con números de 9 cifras' );
$ok( null === NumeroSocio::grupo_de_login( '76840390', array( '684' ) ), 'un usuario con el código anterior no se asigna a ningún grupo' );
$ok( '126840392' === SociosTable::siguiente( '684', array( '126840391', '76849999' ) ), 'siguiente número libre con el nuevo código (no cuenta los del código anterior)' );
$ok( '126840390T' === NumeroSocio::ejemplo_tutor(), 'el ejemplo de las ayudas cambia con el código' );

echo "\n== Parte C · Coste federativo con otros conceptos ==\n";
$fed = Organizacion::sanear_federacion(
	array(
		'cuota_asociativa' => '10',
		'seguro_promedio'  => '2,5',
		'edad_corte'       => 12,
		'primas'           => array( 'menores' => 1, 'mayores' => 2, 'scouters' => 3 ),
		'conceptos'        => array(
			array( 'nombre' => 'Actividades federales', 'importe' => '5', 'aplica' => 'asociado' ),
			array( 'nombre' => 'Material', 'importe' => '1,5', 'aplica' => 'educando' ),
			array( 'nombre' => 'Formación', 'importe' => 4, 'aplica' => 'adulto' ),
			array( 'nombre' => 'Aportación del grupo', 'importe' => 50, 'aplica' => 'grupo' ),
			array( 'nombre' => 'Descuento', 'importe' => -1, 'aplica' => 'asociado' ),
			array( 'nombre' => '', 'importe' => 99, 'aplica' => 'grupo' ),
			array( 'nombre' => 'Aplica raro', 'importe' => 1, 'aplica' => 'marcianos' ),
		),
	)
);
$ok( 6 === count( $fed['conceptos'] ) && 1.5 === $fed['conceptos'][1]['importe'] && -1.0 === $fed['conceptos'][4]['importe'] && 'asociado' === $fed['conceptos'][5]['aplica'] && 2.5 === $fed['seguro_promedio'], 'saneado: sin nombre se quita, comas decimales, descuentos negativos y «se aplica a» válido' );
$muchos = Organizacion::sanear_federacion( array( 'conceptos' => array_fill( 0, 30, array( 'nombre' => 'X', 'importe' => 1, 'aplica' => 'grupo' ) ) ) );
$ok( Organizacion::MAX_CONCEPTOS === count( $muchos['conceptos'] ), 'como mucho ' . Organizacion::MAX_CONCEPTOS . ' conceptos' );
$ok( 'Menores de 12 años' === Organizacion::categorias_seguro( 12 )['menores'], 'las categorías del seguro siguen la edad de corte' );

array_pop( $fed['conceptos'] ); // Fuera «Aplica raro» para las cuentas.
$cfg = array( 'federacion' => $fed + array( 'anio_primas' => 2026 ) );
$ref = new DateTimeImmutable( '2026-10-01', wp_timezone() );
$per = static fn( string $asde, string $secc, string $nac ) => Persona::from_crm( array( 'id' => wp_generate_uuid4(), 'fields' => array( 'numerosocio_c' => $asde, 'stic_relationship_type_c' => $secc, 'birthdate' => $nac ) ) );
$act = array( $per( 'a', '^Lobatos^', '2018-01-01' ), $per( 'b', '^Scouts^', '2015-01-01' ), $per( 'c', '^Scouts^', '2013-06-01' ), $per( 'd', '^Scouters^', '1990-01-01' ), $per( 'e', '^Voluntariado^', '1985-01-01' ) );
$cf  = Calculadora::coste_federativo( $act, $cfg, $ref );
$ok( 2 === $cf['categorias']['menores']['n'] && 1 === $cf['categorias']['mayores']['n'] && 2 === $cf['categorias']['scouters']['n'] && 10.0 === $cf['seguro_tabla'], 'edad de corte 12: 2 menores, 1 mayor y 2 adultos (seguro 10 €)' );
$por = array_column( $cf['conceptos'], 'importe', 'nombre' );
$ok( 25.0 === $por['Actividades federales'] && 4.5 === $por['Material'] && 8.0 === $por['Formación'] && 50.0 === $por['Aportación del grupo'] && -5.0 === $por['Descuento'] && 82.5 === $cf['total_conceptos'], 'conceptos: 5×5 + 3×1,5 + 2×4 + 50 − 5 = 82,50 €' );
$ok( 50.0 === $cf['cuota_asociativa'] && 142.5 === $cf['total_tabla'] && 145.0 === $cf['total_promedio'], 'totales: 50 cuota + 10 seguro + 82,50 = 142,50 € (145 € con el promedio)' );

// Una ronda guardada antes de la v0.9 (primas «menores14», sin conceptos): se entiende igual.
Organizacion::guardar( 'balears', '7', array( 'cuota_asociativa' => 17, 'conceptos' => array( array( 'nombre' => 'Cuota de campamento', 'importe' => 2, 'aplica' => 'educando' ) ) ) );
$tes = is_array( $original_tes ) ? $original_tes : array();
$tes['684']['2099-2100'] = array( 'federacion' => array( 'cuota_asociativa' => 18, 'seguro_promedio' => 3, 'primas' => array( 'menores14' => 1.5, 'mayores14' => 2.5, 'scouters' => 6 ), 'anio_primas' => 2099 ) );
update_option( 'asde_ap_tesoreria', $tes, false );
$c = Config::de( '2099-2100' );
$ok( 18.0 === $c['federacion']['cuota_asociativa'] && 1.5 === $c['federacion']['primas']['menores'] && 2.5 === $c['federacion']['primas']['mayores'] && 2099 === $c['federacion']['anio_primas'], 'ronda anterior a la v0.9: primas con los nombres de antes, bien leídas' );
$ok( 1 === count( $c['federacion']['conceptos'] ) && 'Cuota de campamento' === $c['federacion']['conceptos'][0]['nombre'], 'y los otros conceptos (que entonces no existían) salen de la organización' );
$d = Config::defaults_ronda( '2098-2099' );
$ok( 17.0 === $d['federacion']['cuota_asociativa'] && 2098 === $d['federacion']['anio_primas'], 'una ronda nueva empieza con los importes de la organización' );
Config::guardar( '2099-2100', array( 'federacion' => array( 'cuota_asociativa' => 20, 'conceptos' => array() ) ) );
$ok( 20.0 === Config::de( '2099-2100' )['federacion']['cuota_asociativa'] && array() === Config::de( '2099-2100' )['federacion']['conceptos'], 'la Tesorería del grupo guarda sus propios importes y puede quitar todos los conceptos' );

// ---------------------------------------------------------------------------
// Limpieza: la configuración como estaba.
$restaurar();
Grupo::forzar( null );
$ok( get_option( Organizacion::OPTION, null ) === $original_org && get_option( 'asde_ap_tesoreria', null ) === $original_tes && get_option( 'asde_ap_grupos', null ) === $original_grp && '7' === Organizacion::codigo_antiguo(), 'limpieza: organización, Tesorería y grupos como estaban' );

echo $fails ? "\n$fails FALLOS\n" : "\nTODO OK (la configuración queda como estaba)\n";
