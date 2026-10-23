<?php
/**
 * Pruebas de los presupuestos, fase 1 (v0.54, doc 57; entorno LOCAL). Ejecutar desde app/public del site Local:
 *
 *   wp eval-file wp-content/plugins/asde-area-privada/tests/presupuestos-reparto.php
 *
 * - Calculadora::coste_persona(): coste federativo de una persona; la suma de todas más lo fijo
 *   por grupo da exactamente el total de coste_federativo().
 * - Presupuestos\Reparto: de la cuota al dinero de cada sección (neto, % al grupo por defecto y por
 *   sección, neto negativo, adultos y educandos sin cuota, redondeo, varias secciones).
 * - Config: la parte «presupuesto» (valores por defecto, saneo, no pisa lo demás).
 * No usa el CRM (fichas inventadas en memoria) y deja la configuración como estaba.
 */
defined( 'ABSPATH' ) || exit;

use AsdeBalears\AreaPrivada\Domain\Grupo;
use AsdeBalears\AreaPrivada\Domain\Grupos;
use AsdeBalears\AreaPrivada\Domain\Modulos;
use AsdeBalears\AreaPrivada\Domain\Persona;
use AsdeBalears\AreaPrivada\Presupuestos\Reparto;
use AsdeBalears\AreaPrivada\Tesoreria\Calculadora;
use AsdeBalears\AreaPrivada\Tesoreria\Config;

$fails = 0;
$ok    = function ( $cond, string $label ) use ( &$fails ) {
	if ( ! $cond ) {
		++$fails;
	}
	echo ( $cond ? 'OK   ' : 'FAIL ' ), $label, "\n";
};
$igual = static fn( $a, $b ) => abs( (float) $a - (float) $b ) < 0.001;

$original_tes = get_option( 'asde_ap_tesoreria', null );
register_shutdown_function(
	static function () use ( $original_tes ) {
		if ( null === $original_tes ) {
			delete_option( 'asde_ap_tesoreria' );
		} else {
			update_option( 'asde_ap_tesoreria', $original_tes, false );
		}
	}
);
Grupo::forzar( Grupos::por_numero( '684' ) );

echo "== Parte A · Coste federativo de una persona ==\n";
$cfg = Config::de( '2099-2100' );
$cfg['federacion'] = array(
	'cuota_asociativa' => 17.0,
	'seguro_promedio'  => 3.0,
	'edad_corte'       => 12,
	'anio_primas'      => 2099,
	'primas'           => array( 'menores' => 2.0, 'mayores' => 3.0, 'scouters' => 6.0 ),
	'conceptos'        => array(
		array( 'nombre' => 'Por asociado', 'importe' => 1.0, 'aplica' => 'asociado' ),
		array( 'nombre' => 'Por educando', 'importe' => 2.0, 'aplica' => 'educando' ),
		array( 'nombre' => 'Por adulto', 'importe' => 0.0, 'aplica' => 'adulto' ),
		array( 'nombre' => 'Fijo', 'importe' => 10.0, 'aplica' => 'grupo' ),
	),
);
$ref = new DateTimeImmutable( '2026-10-01', wp_timezone() );
$per = static fn( string $asde, string $secc, string $nac ) => Persona::from_crm( array( 'id' => wp_generate_uuid4(), 'fields' => array( 'numerosocio_c' => $asde, 'stic_relationship_type_c' => $secc, 'birthdate' => $nac ) ) );

$a = $per( 'a', '^Lobatos^', '2018-01-01' );  // 8 años: menor.
$b = $per( 'b', '^Lobatos^', '2017-05-01' );
$c = $per( 'c', '^Scouts^', '2013-06-01' );   // 13 años: mayor (corte en 12).
$r = $per( 'r', '^Rovers^', '2008-02-01' );
$d = $per( 'd', '^Scouters^', '1990-01-01' ); // Adulto.
$x = $per( 'x', '^Castores^', '2019-03-01' ); // Educando sin familia que pague.

$ok( $igual( 22, Calculadora::coste_persona( $a, $cfg, $ref )['total'] ) && 'menores' === Calculadora::coste_persona( $a, $cfg, $ref )['categoria'], 'menor: 17 + 2 + (1 + 2) = 22 €' );
$ok( $igual( 23, Calculadora::coste_persona( $c, $cfg, $ref )['total'] ) && 'mayores' === Calculadora::coste_persona( $c, $cfg, $ref )['categoria'], 'mayor de la edad de corte: 17 + 3 + 3 = 23 €' );
$ok( $igual( 24, Calculadora::coste_persona( $d, $cfg, $ref )['total'] ) && 'scouters' === Calculadora::coste_persona( $d, $cfg, $ref )['categoria'], 'adulto: 17 + 6 + 1 = 24 € (sin el concepto «por educando»)' );
$ok( $igual( 10, Calculadora::coste_fijo_grupo( $cfg ) ), 'lo fijo por grupo: 10 €' );

$activas = array( $a, $b, $c, $r, $d, $x );
$suma    = array_sum( array_map( static fn( $p ) => Calculadora::coste_persona( $p, $cfg, $ref )['total'], $activas ) ) + Calculadora::coste_fijo_grupo( $cfg );
$total   = Calculadora::coste_federativo( $activas, $cfg, $ref );
$ok( $igual( $suma, $total['total_tabla'] ) && $igual( 146, $total['total_tabla'] ), 'equivalencia: Σ por persona + fijo = total de coste_federativo() (146 €)' );

$cfg_sin = $cfg;
$cfg_sin['federacion']['conceptos'] = array();
$suma_sin = array_sum( array_map( static fn( $p ) => Calculadora::coste_persona( $p, $cfg_sin, $ref )['total'], $activas ) );
$ok( $igual( $suma_sin, Calculadora::coste_federativo( $activas, $cfg_sin, $ref )['total_tabla'] ), 'equivalencia también sin otros conceptos' );

echo "== Parte B · Reparto ==\n";
// Familia 1: dos Lobatos (cuota 258 €, 100 € cobrados). Familia 2: un Scout (140 €). Familia 3: un Rover con beca (10 €).
$filas    = array(
	array( 'calc' => array( 'pagan' => array( 'a', 'b' ), 'a_pagar' => 258.0, 'pagado' => 100.0 ) ),
	array( 'calc' => array( 'pagan' => array( 'c' ), 'a_pagar' => 140.0, 'pagado' => 140.0 ) ),
	array( 'calc' => array( 'pagan' => array( 'r' ), 'a_pagar' => 10.0, 'pagado' => 0.0 ) ),
);
$personas = array( 'a' => $a, 'b' => $b, 'c' => $c, 'r' => $r );
$cfg['presupuesto']['pct_grupo']   = 40.0;
$cfg['presupuesto']['pct_seccion'] = array( 'scouts' => 50.0 );

$rep = Reparto::calcular( $activas, $filas, $personas, $cfg, $ref );
$lo  = $rep['secciones']['lobatos'];
$ok( 2 === $lo['educandos'] && $igual( 258, $lo['cuota'] ) && $igual( 100, $lo['cobrado'] ) && $igual( 44, $lo['coste_fed'] ) && $igual( 214, $lo['neto'] ), 'Lobatos: 2 educandos, cuota 258, cobrado 100, coste 44, neto 214' );
$ok( $igual( 85.6, $lo['a_grupo'] ) && $igual( 128.4, $lo['a_seccion'] ), 'Lobatos con el 40 % por defecto: 85,60 al grupo y 128,40 a la sección' );
$sc = $rep['secciones']['scouts'];
$ok( 50.0 === $sc['pct_grupo'] && $igual( 117, $sc['neto'] ) && $igual( 58.5, $sc['a_seccion'] ) && $igual( 58.5, $sc['a_grupo'] ), 'Scouts con su propio 50 %: neto 117 → 58,50 y 58,50' );
$ro = $rep['secciones']['rovers'];
$ok( $igual( -13, $ro['neto'] ) && $igual( -7.8, $ro['a_seccion'] ) && $igual( -5.2, $ro['a_grupo'] ) && 1 === $rep['total']['negativos'], 'neto negativo (beca): aporta −13 y se avisa de 1 educando' );
$ok( 0 === $rep['secciones']['castores']['educandos'] && $igual( 0, $rep['secciones']['castores']['a_seccion'] ), 'Castores sin educandos con cuota: 0 €' );

$g = $rep['grupo'];
$ok( $igual( 24, $g['adultos'] ) && $igual( 22, $g['sin_cuota'] ) && $igual( 10, $g['fijos'] ) && $igual( 56, $g['sin_dueno'] ), 'sin dueño: adulto 24 + educando sin cuota 22 + fijo 10 = 56 €' );
$ok( $igual( 138.9, $g['a_grupo'] ) && $igual( 82.9, $g['disponible'] ), 'grupo: 138,90 de las cuotas − 56 = 82,90 disponibles' );
$suma_sec = array_sum( array_column( $rep['secciones'], 'a_seccion' ) );
$ok( $igual( 408, $rep['total']['cuota'] ) && $igual( 146, $rep['total']['coste_fed'] ) && $igual( 262, $rep['total']['bolsa'] ), 'totales: cuotas 408 − coste 146 = 262 para repartir' );
$ok( $igual( $rep['total']['bolsa'], $suma_sec + $g['disponible'] ), 'cuadra al céntimo: Σ secciones + grupo = cuotas − coste federativo' );

// 0 % y 100 % al grupo.
$cero = $cfg;
$cero['presupuesto']['pct_grupo']   = 0.0;
$cero['presupuesto']['pct_seccion'] = array();
$r0 = Reparto::calcular( $activas, $filas, $personas, $cero, $ref );
$ok( $igual( 0, $r0['secciones']['lobatos']['a_grupo'] ) && $igual( 214, $r0['secciones']['lobatos']['a_seccion'] ), '0 % al grupo: todo el neto va a la sección' );
$cien = $cero;
$cien['presupuesto']['pct_grupo'] = 100.0;
$r1   = Reparto::calcular( $activas, $filas, $personas, $cien, $ref );
$ok( $igual( 0, $r1['secciones']['lobatos']['a_seccion'] ) && $igual( 214, $r1['secciones']['lobatos']['a_grupo'] ), '100 % al grupo: nada a la sección' );
$ok( $igual( $r1['total']['bolsa'], array_sum( array_column( $r1['secciones'], 'a_seccion' ) ) + $r1['grupo']['disponible'] ), 'con el 100 % también cuadra' );

// Redondeo: tres hijos con una cuota que no se reparte exacta (242 € entre 3 = 80,67) y 33,33 %.
$tres   = array( $per( 'h1', '^Castores^', '2019-01-01' ), $per( 'h2', '^Castores^', '2019-02-01' ), $per( 'h3', '^Castores^', '2019-03-01' ) );
$pt     = array( 'h1' => $tres[0], 'h2' => $tres[1], 'h3' => $tres[2] );
$redond = $cfg;
$redond['presupuesto']['pct_grupo']   = 33.33;
$redond['presupuesto']['pct_seccion'] = array();
$rr     = Reparto::calcular( $tres, array( array( 'calc' => array( 'pagan' => array( 'h1', 'h2', 'h3' ), 'a_pagar' => 242.0, 'pagado' => 0.0 ) ) ), $pt, $redond, $ref );
$ok( $igual( $rr['total']['bolsa'], $rr['secciones']['castores']['a_seccion'] + $rr['grupo']['disponible'] ), 'redondeo: aunque la cuota no se reparta exacta, Σ secciones + grupo cuadra al céntimo' );

// Varias secciones: cuenta una sola vez, en la primera educativa.
$doble = $per( 'z', '^Lobatos^,^Scouts^', '2015-01-01' );
$ok( 'lobatos' === Reparto::seccion_de( $doble, $cfg ), 'con dos secciones cuenta en la primera (Lobatos)' );
$cfg_solo = $cfg;
$cfg_solo['secciones_pagan'] = array( 'scouts' );
$ok( 'scouts' === Reparto::seccion_de( $doble, $cfg_solo ) && null === Reparto::seccion_de( $a, $cfg_solo ), 'solo cuentan las secciones que pagan cuota' );

// Sin familias: nada que repartir, el coste federativo resta del grupo.
$vacio = Reparto::calcular( $activas, array(), array(), $cfg, $ref );
$ok( $igual( 0, $vacio['total']['cuota'] ) && $igual( -146, $vacio['grupo']['disponible'] ) && $igual( -146, $vacio['total']['bolsa'] ), 'sin cuotas: el coste federativo (146 €) queda en negativo en el grupo' );
// Un socio que paga y no está en las personas leídas no suma nada.
$huerfano = Reparto::calcular( $activas, array( array( 'calc' => array( 'pagan' => array( 'fantasma' ), 'a_pagar' => 100.0, 'pagado' => 0.0 ) ) ), array(), $cfg, $ref );
$ok( $igual( 0, $huerfano['total']['cuota'] ), 'un nº de socio que no está en el CRM no suma cuota' );

echo "== Parte C · Configuración ==\n";
$def = Config::de( '2099-2100' )['presupuesto'];
$ok( 40.0 === $def['pct_grupo'] && array() === $def['pct_seccion'] && 'Dique' === $def['proyectos']['castores'] && 'Descubierta e Intervención' === $def['proyectos']['rovers'] && 'Empresa' === $def['proyectos']['escultas'], 'una ronda sin la clave: 40 % al grupo y los proyectos de siempre' );
$ok( 40.0 === Config::pct_grupo( Config::de( '2099-2100' ), 'lobatos' ), 'el % de una sección sin el suyo es el del grupo' );

Config::guardar(
	'2099-2100',
	array(
		'presupuesto' => array(
			'pct_grupo'   => '35,5',
			'pct_seccion' => array( 'lobatos' => '120', 'castores' => '-5', 'scouts' => '', 'rovers' => '25', 'inventada' => '50' ),
			'proyectos'   => array( 'castores' => '  Mi <b>dique</b> ', 'rovers' => '', 'inventada' => 'x' ),
		),
	)
);
$g = Config::de( '2099-2100' )['presupuesto'];
$ok( 35.5 === $g['pct_grupo'], 'guardar: coma decimal (35,5 %)' );
$ok( 100.0 === $g['pct_seccion']['lobatos'] && 0.0 === $g['pct_seccion']['castores'] && ! isset( $g['pct_seccion']['scouts'] ) && 25.0 === $g['pct_seccion']['rovers'] && ! isset( $g['pct_seccion']['inventada'] ), 'guardar: porcentajes entre 0 y 100, vacío = el del grupo, secciones desconocidas fuera' );
$ok( 'Mi dique' === $g['proyectos']['castores'] && 'Descubierta e Intervención' === $g['proyectos']['rovers'] && ! isset( $g['proyectos']['inventada'] ), 'guardar: nombre del proyecto sin etiquetas; vacío = el de siempre' );
$ok( 25.0 === Config::pct_grupo( Config::de( '2099-2100' ), 'rovers' ) && 35.5 === Config::pct_grupo( Config::de( '2099-2100' ), 'scouts' ), 'pct_grupo(): el de la sección o, si no, el del grupo' );

Config::guardar( '2099-2100', array( 'cuotas' => array( 'inscripcion' => 5, 'seguro' => 5, 'anualidad' => array( 50 ) ) ) );
$ok( 35.5 === Config::de( '2099-2100' )['presupuesto']['pct_grupo'], 'guardar otra parte (las cuotas) no pisa los presupuestos' );
Config::guardar( '2099-2100', array( 'presupuesto' => array( 'pct_grupo' => '' ) ) );
$ok( 35.5 === Config::de( '2099-2100' )['presupuesto']['pct_grupo'], 'un % del grupo vacío no cambia el que había' );
$ok( 40.0 === Config::de( '2098-2099' )['presupuesto']['pct_grupo'], 'otra ronda no se toca' );

$ok( Modulos::activo( Modulos::PRESUPUESTOS ), 'el módulo Presupuestos está encendido por defecto' );
$ok( Modulos::LISTA[ Modulos::PRESUPUESTOS ][3] === Modulos::TESORERIA, 'depende de Tesorería' );

echo $fails ? "\n{$fails} FALLOS\n" : "\nTODO OK\n";
