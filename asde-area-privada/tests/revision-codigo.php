<?php
/**
 * Pruebas de las correcciones de la revisión de código (doc 59; entorno LOCAL).
 * Ejecutar desde app/public del site Local:
 *
 *   wp eval-file wp-content/plugins/asde-area-privada/tests/revision-codigo.php
 *
 * - RC-CRM-1: list_all no devuelve una lista cortada en silencio: con más registros que el límite, CrmException.
 * - RC-TES-2: si los plazos suman el 100 %, el último cierra la cuota (sin céntimos de más ni de menos).
 * - RC-INS-1: un error cualquiera en la tarea diaria se registra y no corta los demás pasos ni las purgas (las escrituras
 *   de las purgas se neutralizan durante la prueba: no se borra nada).
 * - RC-USU-2 y RC-USU-3: el conector de las listas en el idioma de quien lo ve y el error de «sin sección» con Intendencia.
 * - RC-TES-4 y RC-TES-5: los valores por defecto de Tesorería se guardan en castellano; referencias de familia desde la 1000.
 * Las demás correcciones con prueba van en las pruebas de su módulo (banco-gestion, tienda-fase5, sepa, eventos-inscripciones…)
 * o en las partes siguientes de esta.
 * Usa el CRM simulado (fichas inventadas) y deja el sitio como estaba.
 */
defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/lib/CrmSimulado.php';

use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Crm\ClientFactory;
use AsdeBalears\AreaPrivada\Crm\CrmException;
use AsdeBalears\AreaPrivada\Crm\Escritura;
use AsdeBalears\AreaPrivada\Domain\Grupo;
use AsdeBalears\AreaPrivada\Domain\Grupos;
use AsdeBalears\AreaPrivada\Idiomas\Idioma;
use AsdeBalears\AreaPrivada\Install\Mantenimiento;
use AsdeBalears\AreaPrivada\Install\Roles;
use AsdeBalears\AreaPrivada\Sepa\Mandatos;
use AsdeBalears\AreaPrivada\Sepa\Remesas;
use AsdeBalears\AreaPrivada\Usuarios\AltaScouterService;
use AsdeBalears\AreaPrivada\Usuarios\Contacto;
use AsdeBalears\AreaPrivada\Usuarios\Solicitudes;
use AsdeBalears\AreaPrivada\Tesoreria\Banco\Conciliacion;
use AsdeBalears\AreaPrivada\Tesoreria\Calculadora;
use AsdeBalears\AreaPrivada\Tesoreria\Config as TesoreriaConfig;

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

// --- Lo que se restaura al final ---
$log_antes = (int) $wpdb->get_var( 'SELECT COALESCE(MAX(id), 0) FROM ' . Logger::table() ); // phpcs:ignore WordPress.DB

$cache_externa = wp_using_ext_object_cache( true );
$cortar_crm    = static function ( $pre, $args, $url ) {
	if ( false === $pre && str_contains( (string) $url, '/rest.php' ) ) {
		return new WP_Error( 'prueba', 'CRM real cortado durante la prueba' );
	}
	return $pre;
};
add_filter( 'pre_http_request', $cortar_crm, 2, 3 );
add_filter( 'asde_ap_instantanea_segundos', '__return_zero' );
$P   = array( 'Asde_Prueba_Crm', 'persona' );
$crm = new Asde_Prueba_Crm(
	array(
		$P( 81, 'Ana', 'Rc Uno', '2010-01-01', 'Scouts' ),
		$P( 82, 'Bea', 'Rc Dos', '2011-01-01', 'Scouts' ),
		$P( 83, 'Ciro', 'Rc Tres', '2012-01-01', 'Lobatos' ),
		$P( 84, 'Dani', 'Rc Cuatro', '2013-01-01', 'Lobatos' ),
		$P( 85, 'Eli', 'Rc Cinco', '2014-01-01', 'Castores' ),
	),
	$grupo['crm_id']
);
Grupo::forzar( $grupo );
$crm->activar();
Escritura::usar_para_pruebas( $crm );

try {
	echo "== Parte A · RC-CRM-1: list_all no corta en silencio ==\n";
	$cliente = ClientFactory::reader();
	$ok( null !== $cliente, 'hay cliente del CRM (simulado)' );
	$todas = $cliente->list_all( 'Contacts', '', array( 'id' ), array(), '', 10 );
	$ok( 5 === count( $todas ), 'con el límite por encima del total, salen las 5 fichas' );
	$justas = $cliente->list_all( 'Contacts', '', array( 'id' ), array(), '', 5 );
	$ok( 5 === count( $justas ), 'con el límite justo en el total, también (no es un corte)' );
	$error = null;
	try {
		$cliente->list_all( 'Contacts', '', array( 'id' ), array(), '', 3 );
	} catch ( CrmException $e ) {
		$error = $e->getMessage();
	}
	$ok( null !== $error && str_contains( $error, 'más de 3 registros' ), 'con más fichas que el límite: CrmException con un mensaje claro, no una lista parcial' );
	$reg = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT detalles FROM %i WHERE id > %d AND accion = %s ORDER BY id DESC LIMIT 1', Logger::table(), $log_antes, 'crm_limite_lista' ) ); // phpcs:ignore WordPress.DB
	$ok( str_contains( $reg, '"limite":3' ) && str_contains( $reg, '"total":5' ), 'y queda en el registro (límite y total, sin datos personales)' );
	$filtradas = $cliente->list_all( 'Contacts', "contacts.last_name LIKE 'Rc C%'", array( 'id' ), array(), '', 3 );
	$ok( 2 === count( $filtradas ), 'una consulta con menos resultados que el límite no se ve afectada' );

	echo "== Parte B · RC-TES-2: el último plazo cierra la cuota ==\n";
	$cfg     = TesoreriaConfig::defaults_ronda( '2026-2027' );
	$plazo   = static fn( string $fecha, float $pct ): array => array( 'nombre' => 'Plazo ' . $fecha, 'fecha' => $fecha, 'porcentaje' => $pct );
	$familia = array( 'id' => 0, 'numero' => 'F0000', 'miembros' => array() );
	$despues = new DateTimeImmutable( '2027-03-01', wp_timezone() );
	$cfg['plazos'] = array( $plazo( '2026-10-01', 33.33 ), $plazo( '2026-12-01', 33.33 ), $plazo( '2027-02-01', 33.34 ) );
	$r = Calculadora::familia( $familia, array(), $cfg, array( 'pagado' => 50.0, 'ajustes' => 50.0 ), $despues );
	$ok( 50.0 === $r['a_pagar'] && array( 16.67, 16.67, 16.66 ) === array_column( $r['plazos'], 'importe' ) && 50.0 === end( $r['plazos'] )['acumulado'], '50 € en plazos de 33,33/33,33/33,34 %: 16,67 + 16,67 + 16,66 = 50,00 (antes, 50,01)' );
	$ok( ! in_array( false, array_column( $r['plazos'], 'cubierto' ), true ) && Calculadora::PAGADO === $r['estado'], 'quien lo ha pagado todo tiene todos los plazos cubiertos' );
	$cfg['plazos'] = array( $plazo( '2026-10-01', 33.33 ), $plazo( '2026-12-01', 33.33 ), $plazo( '2027-02-01', 33.33 ) );
	$r = Calculadora::familia( $familia, array(), $cfg, array( 'pagado' => 100.0, 'ajustes' => 100.0 ), $despues );
	$ok( 100.0 === end( $r['plazos'] )['acumulado'] && 33.34 === end( $r['plazos'] )['importe'], 'con 3 × 33,33 % (el redondeo de un tercio) también cierra: el último plazo es 33,34 € de 100 €' );
	$cfg['plazos'] = array( $plazo( '2026-10-01', 50 ), $plazo( '2026-12-01', 30 ) );
	$r = Calculadora::familia( $familia, array(), $cfg, array( 'pagado' => 0.0, 'ajustes' => 50.0 ), $despues );
	$ok( array( 25.0, 15.0 ) === array_column( $r['plazos'], 'importe' ), 'si los plazos no suman el 100 %, cada uno es su porcentaje (no se cambia nada)' );

	echo "== Parte C · RC-INS-1: un error cualquiera no corta la tarea diaria ==\n";
	$detalle = static fn( string $grupo ): string => (string) $wpdb->get_var( $wpdb->prepare( 'SELECT detalles FROM %i WHERE id > %d AND accion = %s AND objeto_id = %s ORDER BY id DESC LIMIT 1', Logger::table(), $log_antes, 'mantenimiento_error', $grupo ) ); // phpcs:ignore WordPress.DB
	$ok(
		false === Mantenimiento::paso(
			'901',
			static function (): void {
				throw new TypeError( 'dato raro de prueba' );
			}
		),
		'un paso que lanza un TypeError no corta la tarea (devuelve false)'
	);
	$ok( str_contains( $detalle( '901' ), 'TypeError' ) && ! str_contains( $detalle( '901' ), 'dato raro' ), 'queda en el registro con la clase del error, sin su mensaje' );
	$ok(
		false === Mantenimiento::paso(
			'902',
			static function (): void {
				throw new CrmException( 'CRM sin respuesta (prueba)' );
			}
		) && str_contains( $detalle( '902' ), 'CRM sin respuesta (prueba)' ),
		'un error del CRM, con su mensaje (como antes)'
	);
	$ok( true === Mantenimiento::paso( '903', static function (): void {} ) && '' === $detalle( '903' ), 'un paso que va bien: true y nada en el registro' );

	// La tarea entera, con la lista de grupos rota (TypeError): termina y hace las purgas. Sus escrituras se neutralizan.
	$escrituras = array();
	$neutro     = static function ( $q ) use ( &$escrituras ) {
		$q = (string) $q;
		if ( preg_match( '/^\s*(UPDATE|DELETE|INSERT|REPLACE)\b/i', $q ) && ! str_contains( $q, Logger::table() ) ) {
			$escrituras[] = $q;
			return 'SELECT 1';
		}
		return $q;
	};
	$romper     = static function () {
		throw new TypeError( 'lista de grupos rota (prueba)' );
	};
	add_filter( 'query', $neutro );
	add_filter( 'pre_option_asde_ap_grupos', $romper );
	add_filter( 'pre_wp_mail', '__return_true' );
	Grupos::olvidar();
	try {
		$res = Mantenimiento::ejecutar();
	} finally {
		remove_filter( 'query', $neutro );
		remove_filter( 'pre_option_asde_ap_grupos', $romper );
		remove_filter( 'pre_wp_mail', '__return_true' );
		Grupos::olvidar();
		Grupo::forzar( $grupo );
	}
	$purgas = static fn( string $tabla ): bool => (bool) array_filter( $escrituras, static fn( $q ) => str_contains( $q, $tabla ) );
	$ok( is_array( $res ) && str_contains( $detalle( '' ), 'TypeError' ), 'la tarea diaria termina aunque falle la lista de grupos, y lo registra' );
	$ok( $purgas( Solicitudes::table() ) && $purgas( Mandatos::table() ) && $purgas( Remesas::table() ), 'y las purgas (solicitudes, mandatos y remesas) se ejecutan igualmente' );

	echo "== Parte D · RC-USU-2 y RC-USU-3: textos de Usuarios ==\n";
	$ok( 'Ana, Bea y Ciro' === Contacto::lista( array( 'Ana', 'Bea', 'Ciro' ) ) && 'Ana o Bea' === Contacto::lista( array( 'Ana', 'Bea' ), true ) && 'Ana' === Contacto::lista( array( 'Ana' ) ), 'listas en castellano: «A, B y C» y «A o B»' );
	$en = static fn( string $idioma, bool $o = false ): string => Idioma::en( $idioma, static fn() => Contacto::lista( array( 'Ana', 'Bea' ), $o ) );
	$ok( 'Ana i Bea' === $en( 'ca' ) && 'Ana eta Bea' === $en( 'eu' ) && 'Ana e Bea' === $en( 'gl' ) && 'Ana ou Bea' === $en( 'gl', true ), 'y en catalán, euskera y gallego, con su conector (antes salía «y» en todos)' );
	$err = AltaScouterService::errores( AltaScouterService::MODO_SIN_SECCION, array(), array(), 'voluntaria@example.invalid' );
	$ok( 1 === count( $err ) && str_contains( $err[0], 'Intendencia' ) && array() === AltaScouterService::errores( AltaScouterService::MODO_SIN_SECCION, array(), array( Roles::INTENDENTE ), 'voluntaria@example.invalid' ), 'sin sección: el error nombra también Intendencia, que vale como responsabilidad' );

	echo "== Parte E · RC-TES-4 y RC-TES-5: Tesorería ==\n";
	$en_ca = Idioma::en( 'ca', static fn() => TesoreriaConfig::defaults_ronda( '2026-2027' ) );
	$ok( 'Cuota anual' === $en_ca['plazos'][0]['nombre'] && 'Dique' === $en_ca['presupuesto']['proyectos']['castores'] && 'Campamento de verano' === $en_ca['presupuesto']['categorias'][0], 'los valores por defecto de una ronda salen en castellano aunque quien la configura use el catalán (lo que se guarda no se traduce)' );
	$ok( 'Cuota anual' !== Idioma::en( 'ca', static fn() => asde_t( 'Cuota anual' ) ) && 'Dique' !== Idioma::en( 'eu', static fn() => asde_t( 'Dique' ) ), 'y se traducen al mostrarlos' );
	$ok( 'F6841000' === Conciliacion::referencia( 'Cuota F6841000 hijos', '684' ) && 'F68412345' === Conciliacion::referencia( 'pago f-684-12345', '684' ), 'la referencia de familia se reconoce también desde la familia 1000 (F6841000)' );
	$ok( 'F684001' === Conciliacion::referencia( 'Cuota F-684-001', '684' ) && null === Conciliacion::referencia( 'F6841000', '999' ) && null === Conciliacion::referencia( 'F68400', '684' ), 'las de 3 cifras siguen igual, y solo las del grupo' );
	$ok( '1 adeudo' === Remesas::adeudos( 1 ) && '3 adeudos' === Remesas::adeudos( 3 ) && '3 adeudos' !== Idioma::en( 'ca', static fn() => Remesas::adeudos( 3 ) ), 'RC-SEPA-2: «1 adeudo» / «3 adeudos», traducido en otro idioma' );
} catch ( Throwable $e ) {
	++$fails;
	echo 'FAIL excepcion: ', $e->getMessage(), ' (', basename( $e->getFile() ), ':', $e->getLine(), ")\n";
} finally {
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id > %d AND accion IN (%s, %s)', Logger::table(), $log_antes, 'crm_limite_lista', 'mantenimiento_error' ) ); // phpcs:ignore WordPress.DB
	Escritura::usar_para_pruebas( null );
	$crm->desactivar();
	remove_filter( 'pre_http_request', $cortar_crm, 2 );
	remove_filter( 'asde_ap_instantanea_segundos', '__return_zero' );
	wp_using_ext_object_cache( $cache_externa );
	Grupo::forzar( null );
}

echo $fails ? "\n{$fails} FALLOS\n" : "\nTODO OK (el sitio queda como estaba)\n";
