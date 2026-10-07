<?php
/**
 * Servidor de la API de SinergiaCRM SIMULADO (doc 34, P-69: no hay CRM de pruebas).
 *
 * Contesta como el endpoint REST v4_1 (…/rest.php: POST con `method` y `rest_data`)
 * con el mismo CRM simulado de las pruebas automáticas (tests/lib/CrmSimulado.php):
 * fichas, membresías, recuento federal, crear personas y membresías, enlazarlas y lo
 * que hace SinergiaCRM al guardar. Datos INVENTADOS (semilla.php); nada real.
 *
 * No necesita WordPress. Arranque, desde esta carpeta y con cualquier PHP 8:
 *
 *   php -S 127.0.0.1:8790 router.php
 *
 * En el Local: un grupo de pruebas (nº 999) con la URL del CRM http://127.0.0.1:8790
 * y cualquier usuario y contraseña. Solo escucha en 127.0.0.1.
 *
 * Otras rutas (para probar a mano):
 *   /estado   resumen (cuántas fichas, membresías, ajustes)
 *   /reset    vuelve a la semilla
 *   /ajustes?heredar_grupo=0|1&enlazar=0|1&numerar=0|1&fallo=set_entry:stic_Contacts_Relationships
 *             cómo se porta el CRM (P-73: heredar el grupo de seguridad; enlazar la
 *             membresía en el mismo set_entry; poner el nº de socio) y un fallo a propósito.
 *
 * El estado se guarda entre peticiones en un fichero temporal (variable ASDE_CRM_ESTADO,
 * o asde-servidor-crm.json en la carpeta temporal del sistema).
 */

if ( 'cli-server' !== PHP_SAPI ) {
	exit( "Esto se arranca con: php -S 127.0.0.1:8790 router.php\n" );
}
if ( ! in_array( (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ), array( '127.0.0.1', '::1' ), true ) ) {
	http_response_code( 403 );
	exit;
}
define( 'ABSPATH', __DIR__ . '/' ); // Lo exigen los ficheros de pruebas; aquí no hay WordPress.

require __DIR__ . '/sustitutos.php';
require __DIR__ . '/../lib/CrmSimulado.php';

$ruta     = (string) parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '/' ), PHP_URL_PATH );
$fichero  = getenv( 'ASDE_CRM_ESTADO' ) ?: sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'asde-servidor-crm.json';
$cerrojo  = fopen( $fichero, 'c+' );
flock( $cerrojo, LOCK_EX ); // Una petición cada vez: el estado no se pisa.
$guardado = json_decode( (string) stream_get_contents( $cerrojo ), true );
$semilla  = null;
if ( '/reset' === $ruta || ! is_array( $guardado ) ) {
	$semilla  = require __DIR__ . '/semilla.php';
	$guardado = $semilla['estado'] + array(
		'grupo_sg' => $semilla['grupo_sg'],
		'ajustes'  => array(),
	);
}
$ajustes = (array) ( $guardado['ajustes'] ?? array() ) + array(
	'enlazar' => true,
	'numerar' => true,
	'fallos'  => array(),
);
$crm     = new Asde_Prueba_Crm( array(), (string) $guardado['grupo_sg'] );
$crm->importar( $guardado );
$crm->enlazar_en_set_entry = (bool) $ajustes['enlazar'];
$crm->numerar              = (bool) $ajustes['numerar'];
$crm->fallos               = (array) $ajustes['fallos'];

$responder = static function ( $datos, int $codigo = 200 ): void {
	http_response_code( $codigo );
	header( 'Content-Type: application/json; charset=utf-8' );
	echo json_encode( $datos, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
};

if ( str_ends_with( $ruta, '/rest.php' ) ) {
	$metodo = (string) ( $_POST['method'] ?? '' );
	$datos  = json_decode( (string) ( $_POST['rest_data'] ?? '{}' ), true );
	$responder( $crm->responder_http( $metodo, is_array( $datos ) ? $datos : array() ) );
} elseif ( '/ajustes' === $ruta ) {
	$si = static fn( string $k ) => isset( $_GET[ $k ] ) ? '1' === (string) $_GET[ $k ] : null;
	if ( null !== $si( 'heredar_grupo' ) ) {
		$crm->heredar_grupo = $si( 'heredar_grupo' );
	}
	if ( null !== $si( 'enlazar' ) ) {
		$crm->enlazar_en_set_entry = $si( 'enlazar' );
	}
	if ( null !== $si( 'numerar' ) ) {
		$crm->numerar = $si( 'numerar' );
	}
	if ( isset( $_GET['fallo'] ) && preg_match( '/^[a-z_]+(:[A-Za-z_]+)?$/', (string) $_GET['fallo'] ) ) {
		$crm->fallos[ (string) $_GET['fallo'] ] = ( $crm->fallos[ (string) $_GET['fallo'] ] ?? 0 ) + 1;
	}
	$responder( array( 'ok' => true ) );
} elseif ( '/estado' === $ruta || '/reset' === $ruta ) {
	$responder(
		array(
			'reiniciado'      => null !== $semilla,
			'grupo'           => $crm->numgrupo,
			'fichas_grupo'    => count( $crm->personas ),
			'fichas_sin_grupo' => count( $crm->sin_grupo ),
			'otros_grupos'    => count( $crm->otros_grupos ),
			'membresias'      => count( $crm->membresias ),
			'heredar_grupo'   => $crm->heredar_grupo,
			'enlazar'         => $crm->enlazar_en_set_entry,
			'numerar'         => $crm->numerar,
			'fallos'          => $crm->fallos,
		)
	);
} else {
	header( 'Content-Type: text/plain; charset=utf-8' );
	http_response_code( 404 );
	echo "Servidor de la API de SinergiaCRM simulado. Rutas: …/rest.php (API v4_1), /estado, /reset, /ajustes\n";
}

$estado = $crm->exportar() + array(
	'grupo_sg' => (string) $guardado['grupo_sg'],
	'ajustes'  => array(
		'enlazar' => $crm->enlazar_en_set_entry,
		'numerar' => $crm->numerar,
		'fallos'  => array_filter( $crm->fallos ),
	),
);
ftruncate( $cerrojo, 0 );
rewind( $cerrojo );
fwrite( $cerrojo, (string) json_encode( $estado, JSON_UNESCAPED_UNICODE ) );
fflush( $cerrojo );
flock( $cerrojo, LOCK_UN );
fclose( $cerrojo );
