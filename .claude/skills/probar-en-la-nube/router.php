<?php
/**
 * Enrutador del servidor de desarrollo de PHP para el WordPress de pruebas (enlaces permanentes).
 * Uso: NUBE=<carpeta> php -S localhost:8099 -t "$NUBE/wp" router.php
 */
$wp   = rtrim( (string) getenv( 'NUBE' ), '/' ) . '/wp';
$ruta = (string) parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
if ( '/' !== $ruta && is_file( $wp . $ruta ) ) {
	return false; // Ficheros estáticos (CSS, JS, imágenes): los sirve el propio servidor.
}
chdir( $wp );
$_SERVER['SCRIPT_FILENAME'] = $wp . '/index.php';
require $wp . '/index.php';
