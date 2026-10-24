<?php
namespace AsdeBalears\AreaPrivada\Eventos;

use AsdeBalears\AreaPrivada\Crm\Crypto;
use AsdeBalears\AreaPrivada\Domain\Grupo;

defined( 'ABSPATH' ) || exit;

/**
 * Justificantes de los gastos de un evento (v0.36, doc 43): imagen (JPG, PNG o WebP) o PDF de
 * hasta 5 MB. Se guardan **cifrados** (Crypto, con una clave propia de cada fichero) en
 * wp-content/uploads/asde-privado/<grupo>/, con nombre aleatorio y la carpeta cerrada
 * (.htaccess, web.config e index.php): aunque alguien llegara al fichero, no lo podría leer.
 * Solo se ven desde el gasto, con permiso (EventosView, «Ver justificante»).
 */
final class Justificantes {

	public const MAX = 5242880; // 5 MB.

	/** Tipo real => extensión. */
	public const TIPOS = array(
		'image/jpeg'      => 'jpg',
		'image/png'       => 'png',
		'image/webp'      => 'webp',
		'application/pdf' => 'pdf',
	);

	/** Carpeta del grupo (se crea cerrada si no existe), o '' si no se puede. */
	public static function carpeta(): string {
		$base = wp_upload_dir( null, false );
		if ( ! empty( $base['error'] ) || '' === Grupo::numero() ) {
			return '';
		}
		$raiz = trailingslashit( $base['basedir'] ) . 'asde-privado';
		$dir  = $raiz . '/' . preg_replace( '/[^0-9A-Za-z]/', '', Grupo::numero() );
		if ( ! wp_mkdir_p( $dir ) ) {
			return '';
		}
		foreach ( array( $raiz, $dir ) as $d ) {
			self::cerrar( $d );
		}
		return $dir;
	}

	/**
	 * Comprueba y guarda cifrado el fichero subido.
	 *
	 * @param string $ruta   Fichero temporal (ya comprobado con is_uploaded_file() por la vista).
	 * @param string $nombre Nombre original (solo para enseñarlo).
	 * @return array{justificante:string, justificante_tipo:string, justificante_nombre:string}|\WP_Error
	 */
	public static function guardar( string $ruta, string $nombre ) {
		$error = new \WP_Error( 'asde_justificante', asde_t( 'El justificante tiene que ser una imagen (JPG, PNG o WebP) o un PDF de hasta 5 MB.' ) );
		if ( ! is_readable( $ruta ) || filesize( $ruta ) <= 0 || filesize( $ruta ) > self::MAX ) {
			return $error;
		}
		$tipo = self::tipo( $ruta, $nombre );
		if ( '' === $tipo ) {
			return $error;
		}
		$fs  = self::fs();
		$dir = self::carpeta();
		$raw = $fs->get_contents( $ruta );
		if ( '' === $dir || ! is_string( $raw ) || '' === $raw ) {
			return new \WP_Error( 'asde_justificante', asde_t( 'No se ha podido guardar el justificante. Inténtalo de nuevo.' ) );
		}
		$fichero = bin2hex( random_bytes( 16 ) ) . '.bin';
		if ( ! $fs->put_contents( $dir . '/' . $fichero, Crypto::encrypt_contexto( $raw, self::contexto( $fichero ) ), self::modo() ) ) {
			return new \WP_Error( 'asde_justificante', asde_t( 'No se ha podido guardar el justificante. Inténtalo de nuevo.' ) );
		}
		return array(
			'justificante'        => $fichero,
			'justificante_tipo'   => $tipo,
			'justificante_nombre' => mb_substr( sanitize_file_name( $nombre ), 0, 120 ),
		);
	}

	/** Columnas de una fila (gasto o reintegro) cuyo justificante se borra. */
	public const SIN_JUSTIFICANTE = array(
		'justificante'        => '',
		'justificante_tipo'   => '',
		'justificante_nombre' => '',
	);

	/**
	 * Borra el fichero de un justificante si ya ninguna fila lo usa: ni un gasto de un evento ni un reintegro (el gasto
	 * que crea un reintegro pagado con evento lleva el mismo fichero: Reintegros\Servicio::gasto_del_evento()). Se llama
	 * al rechazar o cancelar un reintegro y al rechazar o retirar un gasto, después de vaciar las columnas de su fila
	 * (SIN_JUSTIFICANTE). Los justificantes de los gastos válidos se conservan (doc 59, RC-DEC-3).
	 *
	 * @return bool Si se ha borrado el fichero.
	 */
	public static function borrar_si_libre( string $fichero ): bool {
		global $wpdb;
		if ( ! preg_match( '/^[0-9a-f]{32}\.bin$/', $fichero ) ) {
			return false;
		}
		$usos = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE justificante = %s', GastosTable::table(), $fichero ) ) // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			+ (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE justificante = %s', \AsdeBalears\AreaPrivada\Reintegros\ReintegrosTable::table(), $fichero ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( $usos > 0 ) {
			return false;
		}
		$dir = self::carpeta();
		if ( '' === $dir || ! is_file( $dir . '/' . $fichero ) ) {
			return false;
		}
		return self::fs()->delete( $dir . '/' . $fichero );
	}

	/** El contenido descifrado del justificante de un gasto, o null. */
	public static function leer( array $gasto ): ?string {
		$fichero = (string) $gasto['justificante'];
		if ( ! preg_match( '/^[0-9a-f]{32}\.bin$/', $fichero ) ) {
			return null;
		}
		$dir = self::carpeta();
		$ruta = $dir . '/' . $fichero;
		if ( '' === $dir || ! is_readable( $ruta ) ) {
			return null;
		}
		$raw = self::fs()->get_contents( $ruta );
		return is_string( $raw ) ? Crypto::decrypt_contexto( $raw, self::contexto( $fichero ) ) : null;
	}

	/** Tipo real del fichero (por su contenido, no por su nombre), o '' si no se admite. */
	private static function tipo( string $ruta, string $nombre ): string {
		$mimes = array();
		foreach ( self::TIPOS as $mime => $ext ) {
			$mimes[ 'jpg' === $ext ? 'jpg|jpeg' : $ext ] = $mime;
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		$check = wp_check_filetype_and_ext( $ruta, $nombre, $mimes );
		$tipo  = (string) ( $check['type'] ?? '' );
		if ( ! isset( self::TIPOS[ $tipo ] ) ) {
			return '';
		}
		// Las imágenes, además, tienen que poder leerse como imagen.
		if ( str_starts_with( $tipo, 'image/' ) && ! wp_getimagesize( $ruta ) ) {
			return '';
		}
		return $tipo;
	}

	private static function contexto( string $fichero ): string {
		return 'evento|justificante|' . Grupo::numero() . '|' . $fichero;
	}

	/** La carpeta, cerrada a la web (Apache, LiteSpeed e IIS) y sin listado. */
	private static function cerrar( string $dir ): void {
		$fs = self::fs();
		$ficheros = array(
			'.htaccess'  => "# Área privada: justificantes cifrados. Nunca se sirven directamente.\nRequire all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n",
			'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n",
			'index.php'  => "<?php\n// Silencio.\n",
		);
		foreach ( $ficheros as $nombre => $contenido ) {
			if ( ! $fs->exists( $dir . '/' . $nombre ) ) {
				$fs->put_contents( $dir . '/' . $nombre, $contenido, self::modo() );
			}
		}
	}

	/**
	 * El justificante que llega en el formulario ($_FILES[$campo]), o null si no se ha subido ninguno; error si pasa del
	 * tamaño máximo. Lo usan los gastos de los eventos y los reintegros (antes, copiado en sus dos vistas: doc 59, RC-PRE-4).
	 * El nonce lo comprueba antes quien llama (handle_post); el contenido se valida al guardarlo (guardar()).
	 *
	 * @return array{ruta:string, nombre:string}|\WP_Error|null
	 */
	public static function subido( string $campo = 'justificante' ) {
		$f = $_FILES[ $campo ] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.NonceVerification.Missing -- el nonce se comprueba antes (check_admin_referer en handle_post); del fichero solo se usan datos validados con is_uploaded_file()
		if ( is_array( $f ) && in_array( $f['error'] ?? 0, array( UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE ), true ) ) {
			return new \WP_Error( 'asde_justificante', asde_t( 'El justificante tiene que ser una imagen (JPG, PNG o WebP) o un PDF de hasta 5 MB.' ) );
		}
		if ( ! is_array( $f ) || UPLOAD_ERR_OK !== ( $f['error'] ?? UPLOAD_ERR_NO_FILE ) || ! is_uploaded_file( (string) $f['tmp_name'] ) ) {
			return null;
		}
		return array(
			'ruta'   => (string) $f['tmp_name'],
			'nombre' => sanitize_file_name( wp_unslash( (string) $f['name'] ) ),
		);
	}

	/**
	 * Envía un justificante (ya descifrado y con permiso comprobado) al navegador y termina. Las imágenes se abren en la
	 * pestaña (con una CSP que no deja ejecutar nada); los PDF se descargan (el visor de PDF del navegador no funciona con
	 * esa CSP y un PDF puede llevar código).
	 *
	 * @param string $nombre Nombre del fichero sin extensión (justificante-12).
	 */
	public static function enviar( string $contenido, string $tipo, string $nombre ): void {
		$imagen = str_starts_with( $tipo, 'image/' );
		nocache_headers();
		header( 'Content-Type: ' . $tipo );
		header( 'Content-Disposition: ' . ( $imagen ? 'inline' : 'attachment' ) . '; filename="' . $nombre . '.' . ( self::TIPOS[ $tipo ] ?? 'bin' ) . '"' );
		header( 'Content-Length: ' . strlen( $contenido ) );
		header( 'X-Content-Type-Options: nosniff' );
		if ( $imagen ) {
			header( "Content-Security-Policy: default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; sandbox" );
		}
		echo $contenido; // phpcs:ignore WordPress.Security.EscapeOutput -- fichero binario (imagen o PDF comprobados al subirlo), con su tipo y nosniff.
		exit;
	}

	/** Acceso directo a los ficheros (sin pedir credenciales FTP: es una carpeta del propio sitio). */
	private static function fs(): \WP_Filesystem_Direct {
		require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
		return new \WP_Filesystem_Direct( null );
	}

	/** Permisos de los ficheros: los de WordPress (FS_CHMOD_FILE) o, si no están, los de su index.php. */
	private static function modo(): int {
		return defined( 'FS_CHMOD_FILE' ) ? (int) FS_CHMOD_FILE : ( fileperms( ABSPATH . 'index.php' ) & 0777 | 0644 );
	}
}
