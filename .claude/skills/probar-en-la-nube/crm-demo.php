<?php
/**
 * mu-plugin SOLO para el WordPress de pruebas de la nube: las páginas del área leen el CRM simulado
 * de tests/lib con fichas inventadas, para mirarlas (y fotografiarlas) en un navegador.
 *
 * - Solo en las peticiones web: con WP-CLI (las pruebas) no hace nada, porque contestaría a las que
 *   leen el CRM real y cambiaría sus resultados.
 * - Se copia a $NUBE/wp/wp-content/mu-plugins/. Nunca en el Local ni en producción.
 */
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	return;
}
add_action(
	'plugins_loaded',
	static function () {
		$lib = WP_PLUGIN_DIR . '/asde-area-privada/tests/lib/CrmSimulado.php';
		if ( ! is_readable( $lib ) || ! class_exists( '\AsdeBalears\AreaPrivada\Domain\Grupos' ) ) {
			return;
		}
		require_once $lib;
		$grupo = \AsdeBalears\AreaPrivada\Domain\Grupos::por_numero( '684' );
		if ( ! $grupo ) {
			return;
		}
		$dias = static fn( int $n ): string => gmdate( 'Y-m-d', strtotime( "today -$n days" ) );
		$P    = array( 'Asde_Prueba_Crm', 'persona' );
		// Fichas inventadas: nº Scouts de España 990000 + n. Ampliar aquí lo que haga falta para cada pantalla.
		$GLOBALS['asde_crm_demo'] = new Asde_Prueba_Crm(
			array(
				$P( 41, 'Lucía', 'Ejemplo Ruiz', '2016-05-10', 'Lobatos' ),
				$P( 42, 'Marc', 'Ejemplo Ruiz', '2013-02-20', 'Scouts' ),
				$P( 43, 'Ana', 'Ejemplo Gil', '1990-01-01', 'Scouters', array( 'formaciontiempolibre_c' => 'Monitor/a de tiempo libre', 'certificadods_c' => '1', 'certificadods_fecha_c' => $dias( 60 ) ) ),
				$P( 44, 'Tomás', 'Ejemplo Luz', '1985-05-05', 'Scouters', array( 'certificadods_c' => '1', 'certificadods_fecha_c' => $dias( 400 ) ) ),
				$P( 45, 'Eva', 'Ejemplo Coll', '1980-08-08', 'Scouters', array( 'formaciontiempolibre_c' => 'Director/a de tiempo libre', 'certificadods_c' => '1', 'certificadods_fecha_c' => $dias( 30 ) ) ),
				$P( 46, 'Pol', 'Ejemplo Mas', '1992-03-03', 'Scouters', array( 'formaciontiempolibre_c' => 'Monitor/a de tiempo libre', 'certificadods_c' => '1', 'certificadods_fecha_c' => $dias( 90 ) ) ),
				$P( 47, 'Gil', 'Ejemplo Pons', '1995-09-09', 'Voluntariado' ),
			),
			$grupo['crm_id']
		);
		\AsdeBalears\AreaPrivada\Domain\Grupo::forzar( $grupo );
		$GLOBALS['asde_crm_demo']->activar();
	},
	20
);
