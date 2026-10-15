<?php
/**
 * Enrutador del servidor de desarrollo de PHP para el WordPress de pruebas (enlaces permanentes).
 * Uso: NUBE=<carpeta> php -S localhost:8099 -t "$NUBE/wp" router.php
 *
 * Ojo: este fichero se ejecuta en el ámbito global, igual que index.php de WordPress. Sus variables
 * llevan un prefijo propio: una llamada $wp pisaría el objeto global de WordPress (error crítico).
 */
$asde_nube_raiz = rtrim( (string) getenv( 'NUBE' ), '/' ) . '/wp';
$asde_nube_ruta = (string) parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
if ( '/' !== $asde_nube_ruta && is_file( $asde_nube_raiz . $asde_nube_ruta ) ) {
	return false; // Ficheros estáticos (CSS, JS, imágenes): los sirve el propio servidor.
}
chdir( $asde_nube_raiz );
$_SERVER['SCRIPT_FILENAME'] = $asde_nube_raiz . '/index.php';
require $asde_nube_raiz . '/index.php';
