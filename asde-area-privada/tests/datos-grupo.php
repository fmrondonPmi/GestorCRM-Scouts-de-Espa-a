<?php
/**
 * Pruebas de «Datos del grupo» y del nº de socio/a con provincia (v0.56, doc 58; entorno LOCAL).
 * Ejecutar desde app/public del site Local:
 *
 *   wp eval-file wp-content/plugins/asde-area-privada/tests/datos-grupo.php
 *
 * - Provincias: las 52, con el 0 inicial.
 * - Nº de socio/a = provincia + nº de grupo + 4 cifras; el 0 inicial no se quita; los números de antes
 *   (sin el 0) siguen siendo válidos; sin provincia no se reconoce ni se genera ninguno.
 * - Guardar los datos del grupo (validaciones) y una sola fuente para SEPA.
 * - La pantalla: la ve todo el mundo, la cambia Coordinación; permisos y gestor de permisos.
 * Usa el CRM simulado (fichas inventadas) y deja los grupos, la organización y la SEPA como estaban.
 */
defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/lib/CrmSimulado.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

use AsdeBalears\AreaPrivada\Accesos\Catalogo;
use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Auth\Ambito;
use AsdeBalears\AreaPrivada\Crm\Escritura;
use AsdeBalears\AreaPrivada\Domain\DatosGrupo;
use AsdeBalears\AreaPrivada\Domain\Grupo;
use AsdeBalears\AreaPrivada\Domain\Grupos;
use AsdeBalears\AreaPrivada\Domain\NumeroSocio;
use AsdeBalears\AreaPrivada\Domain\Organizacion;
use AsdeBalears\AreaPrivada\Domain\Provincias;
use AsdeBalears\AreaPrivada\Frontend\Views\DatosGrupoView;
use AsdeBalears\AreaPrivada\Install\Roles;
use AsdeBalears\AreaPrivada\Sepa\Config as SepaConfig;
use AsdeBalears\AreaPrivada\Socios\SociosTable;
use AsdeBalears\AreaPrivada\Usuarios\Usuarios;

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
$n = (string) $grupo['numero'];
foreach ( get_users( array( 'search' => 'prueba-dg-*', 'search_columns' => array( 'user_login' ) ) ) as $u ) {
	exit( 'Ya existe el usuario ' . $u->user_login . " (¿una ejecución anterior interrumpida?). Bórralo antes de repetir la prueba.\n" );
}

// --- Lo que se restaura al final ---
$grupos_antes = get_option( 'asde_ap_grupos', null );
$org_antes    = get_option( Organizacion::OPTION, null );
$sepa_antes   = get_option( 'asde_ap_sepa_' . $n, null );
$log_antes    = (int) $wpdb->get_var( 'SELECT COALESCE(MAX(id), 0) FROM ' . Logger::table() ); // phpcs:ignore WordPress.DB
$socios_antes = array_column( (array) $wpdb->get_results( 'SELECT * FROM ' . SociosTable::table(), ARRAY_A ), null, 'num_socio_asde' ); // phpcs:ignore WordPress.DB
$ids          = array();

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
		$P( 91, 'Eva', 'Dg Lobos', '1990-01-01', 'Scouters' ),
		$P( 92, 'Ian', 'Dg Coord', '1991-01-01', 'Scouters' ),
		$P( 94, 'Leo', 'Dg Hijo', '2016-05-10', 'Lobatos' ),
	),
	$grupo['crm_id']
);
Grupo::forzar( $grupo );
$crm->activar();
Escritura::usar_para_pruebas( $crm );

$poner = static function ( string $provincia ) use ( $n ): void {
	$r = Grupos::guardar_datos( $n, array( 'nombre' => Grupos::por_numero( $n )['nombre'], 'provincia' => $provincia ) );
	if ( is_wp_error( $r ) ) {
		throw new RuntimeException( $r->get_error_message() );
	}
};
$org_codigo = static function ( string $codigo ) use ( $org_antes ): void {
	$v           = is_array( $org_antes ) ? $org_antes : array( 'organizacion' => 'balears', 'identificador' => 'local', 'federacion' => array() );
	$v['codigo'] = $codigo;
	update_option( Organizacion::OPTION, $v, false );
	Organizacion::olvidar();
};

try {
	echo "== Parte A · Provincias ==\n";
	$ok( 52 === count( Provincias::todas() ) && 'Illes Balears' === Provincias::nombre( '07' ) && 'Melilla' === Provincias::nombre( '52' ) && 'Madrid' === Provincias::nombre( '28' ), 'las 52 provincias, con su código de dos cifras' );
	$ok( '07' === Provincias::normalizar( '7' ) && '07' === Provincias::normalizar( '07' ) && '28' === Provincias::normalizar( '28' ) && '01' === Provincias::normalizar( ' 1 ' ), 'el 0 inicial se conserva (7 → 07)' );
	$ok( null === Provincias::normalizar( '0' ) && null === Provincias::normalizar( '00' ) && null === Provincias::normalizar( '53' ) && null === Provincias::normalizar( '007' ) && null === Provincias::normalizar( 'ab' ) && null === Provincias::normalizar( '' ), 'códigos que no son provincia: rechazados' );

	echo "== Parte B · Nº de socio/a: provincia + grupo + incremental ==\n";
	$org_codigo( '7' );
	$poner( '07' );
	$ok( '07' === NumeroSocio::provincia( $n ) && '07' . $n === NumeroSocio::prefijo( $n ) && NumeroSocio::configurado( $n ), 'Illes Balears: prefijo 07' . $n );
	$ok( '07' . $n . '0390' === NumeroSocio::formatear( $n, 390 ) && 9 === strlen( NumeroSocio::formatear( $n, 1 ) ), 'formatear: el 0 inicial no se quita (' . NumeroSocio::formatear( $n, 390 ) . ')' );
	$c = '07' . $n . '0390';
	$a = '7' . $n . '0390';
	$ok( $c === NumeroSocio::normalizar( $c, $n ) && NumeroSocio::es_valido( $c, $n ), 'un número completo es válido tal cual' );
	$ok( $a === NumeroSocio::normalizar( $a, $n ) && NumeroSocio::es_valido( $a, $n ), 'el de antes de la v0.56 (sin el 0) también, sin tocarlo' );
	$ok( $c === NumeroSocio::normalizar( '00' . $a, $n ) && $c === NumeroSocio::normalizar( $n . '0390', $n ), 'con ceros de más o sin la provincia se lleva al completo' );
	$ok( null === NumeroSocio::normalizar( '07' . $n . '039', $n ) && null === NumeroSocio::normalizar( '08' . $n . '0390', $n ) && null === NumeroSocio::normalizar( '', $n ) && null === NumeroSocio::normalizar( 'x', $n ), 'formatos inválidos y otra provincia: null' );
	$ok( !NumeroSocio::es_valido( '0' . $a . '1', $n ), 'una cifra de más no vale' );
	$ok( $n === NumeroSocio::grupo_de_login( $a . 'T', array( $n ) ) && $n === NumeroSocio::grupo_de_login( $c . 'T2', array( $n ) ) && $n === NumeroSocio::grupo_de_login( $c, array( $n ) ) && null === NumeroSocio::grupo_de_login( '99990390', array( $n ) ), 'el grupo de un usuario se saca con y sin el 0 (también con T y T2)' );
	$ok( array( $a ) === NumeroSocio::otras_formas( $c ) && array( $c ) === NumeroSocio::otras_formas( $a ) && array() === NumeroSocio::otras_formas( '12345678' ), 'las dos formas de un mismo número' );
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE num_socio_asde LIKE %s', SociosTable::table(), 'prueba-dg-%' ) ); // phpcs:ignore WordPress.DB
	$sig = SociosTable::siguiente( $n, array( $a, '07' . $n . '0391', '07' . $n . '0001' ) );
	$ok( 9 === strlen( $sig ) && str_starts_with( $sig, '07' . $n ) && $sig >= '07' . $n . '0392', "el siguiente cuenta los dos formatos: máximo 0391 → al menos 0392 ($sig)" );

	// Una provincia que no empieza por 0.
	$poner( '28' );
	$ok( '28' . $n . '0005' === NumeroSocio::formatear( $n, 5 ) && '28' . $n . '0005' === NumeroSocio::normalizar( '28' . $n . '0005', $n ) && null === NumeroSocio::normalizar( $n . '00055', $n ), 'Madrid (28): prefijo 28' . $n . ', sin forma antigua' );
	$ok( array() === NumeroSocio::otras_formas( '28' . $n . '0005' ), 'y no tiene otra forma' );

	// Sin provincia: sale del código antiguo de la organización (7 → 07) o no hay.
	$poner( '' );
	$ok( '07' === NumeroSocio::provincia( $n ), 'sin provincia guardada, la sale del código de antes de la v0.56 (7 → 07)' );
	$org_codigo( '' );
	$ok( '' === NumeroSocio::provincia( $n ) && ! NumeroSocio::configurado( $n ) && '' === NumeroSocio::prefijo( $n ), 'sin provincia ni código: sin prefijo' );
	$ok( null === NumeroSocio::normalizar( $c, $n ) && null === NumeroSocio::grupo_de_login( $c, array( $n ) ), 'y no se reconoce ningún número' );
	$err = SociosTable::asignar_siguiente( 'prueba-dg-asde', $n, array() );
	$ok( is_wp_error( $err ), 'ni se genera ninguno (error pidiendo la provincia)' );
	$ok( ! $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE num_socio_asde = %s', SociosTable::table(), 'prueba-dg-asde' ) ), 'y no queda reservado' ); // phpcs:ignore WordPress.DB
	$org_codigo( '7' );

	echo "== Parte C · Guardar los datos del grupo ==\n";
	$base = array( 'nombre' => 'Grupo Prueba', 'denominacion' => 'Asociación Grupo Prueba', 'cif' => 'g-07 123456', 'sede' => 'Calle Mayor 1', 'cp' => '07001', 'poblacion' => 'Palma', 'provincia' => '7', 'email_contacto' => 'Grupo@Example.invalid' );
	$r    = Grupos::guardar_datos( $n, $base );
	$ok( ! is_wp_error( $r ) && 'G07123456' === $r['cif'] && '07' === $r['provincia'] && 'grupo@example.invalid' === $r['email_contacto'] && 'Grupo Prueba' === $r['nombre'], 'guardar: CIF en mayúsculas y sin separadores, provincia 7 → 07, email en minúsculas' );
	$ok( 'Calle Mayor 1, 07001 Palma, Illes Balears' === Grupos::direccion( Grupos::por_numero( $n ) ), 'la dirección en una línea: calle, código postal y población, provincia' );
	$ok( false !== strpos( Grupos::membrete( Grupos::por_numero( $n ) ), 'Asociación Grupo Prueba · CIF G07123456 · Calle Mayor 1, 07001 Palma' ), 'el membrete de los documentos lleva la dirección completa' );
	foreach ( array( 'nombre' => '  ', 'cif' => 'abc', 'cp' => '7001', 'provincia' => '99', 'email_contacto' => 'no-es-email' ) as $campo => $malo ) {
		$e = Grupos::guardar_datos( $n, array( $campo => $malo ) + $base );
		$ok( is_wp_error( $e ), "rechaza $campo = «" . trim( $malo ) . '»' );
	}
	$ok( 'Grupo Prueba' === Grupos::por_numero( $n )['nombre'] && '07001' === Grupos::por_numero( $n )['cp'], 'y lo rechazado no cambia nada' );
	$ok( is_wp_error( Grupos::guardar_datos( '999', $base ) ), 'un grupo que no existe: error' );
	$vacia = Grupos::guardar_datos( $n, array( 'nombre' => 'Grupo Prueba' ) );
	$ok( ! is_wp_error( $vacia ) && '' === $vacia['cif'] && '' === $vacia['sede'], 'se pueden dejar vacíos los datos opcionales' );
	$ok( $grupo['usuario'] === Grupos::por_numero( $n )['usuario'] && $grupo['crm_id'] === Grupos::por_numero( $n )['crm_id'], 'no toca las credenciales ni el grupo de seguridad del CRM' );

	echo "== Parte D · Una sola fuente ==\n";
	update_option( 'asde_ap_sepa_' . $n, array( 'nombre' => 'Acreedor SEPA', 'calle' => 'Calle Vieja 2', 'cp' => '07002', 'poblacion' => 'Inca', 'provincia' => 'Baleares' ), false );
	$d = DatosGrupo::de( $n );
	$ok( 'Calle Vieja 2' === $d['sede'] && '07002' === $d['cp'] && 'Inca' === $d['poblacion'], 'si el grupo no tiene dirección, se aprovecha la que se escribió en las domiciliaciones' );
	$ok( str_starts_with( DatosGrupo::direccion( $n ), 'Calle Vieja 2, 07002 Inca' ) && ! str_contains( Grupos::direccion( Grupos::por_numero( $n ) ), 'Calle Vieja' ), 'la dirección en una línea con respaldos (DatosGrupo) y sin ellos (Grupos, la del membrete) se montan igual (doc 59, RC-PRE-1)' );
	Grupos::guardar_datos( $n, $base );
	$s = SepaConfig::de( $n );
	$ok( 'Calle Mayor 1' === $s['calle'] && '07001' === $s['cp'] && 'Palma' === $s['poblacion'] && 'Illes Balears' === $s['provincia'], 'la SEPA usa la dirección de los datos del grupo (manda sobre la guardada antes)' );
	$ok( 'Acreedor SEPA' === $s['nombre'], 'el nombre del acreedor guardado en la SEPA se respeta' );
	update_option( 'asde_ap_sepa_' . $n, array( 'ics' => 'ES00ZZZG00000000' ), false );
	$ok( 'Asociación Grupo Prueba' === SepaConfig::de( $n )['nombre'], 'sin nombre propio, el acreedor es la denominación fiscal del grupo' );
	$ok( array() === DatosGrupo::faltan( $n ), 'con todo relleno no falta nada' );
	Grupos::guardar_datos( $n, array( 'nombre' => 'Grupo Prueba' ) );
	$ok( 2 <= count( DatosGrupo::faltan( $n ) ), 'sin CIF ni dirección, lo indica' );

	echo "== Parte E · Permisos ==\n";
	$ok( ! empty( get_role( Roles::COORDINACION )->capabilities['asde_grupo_datos'] ), 'Coordinación puede cambiar los datos del grupo' );
	foreach ( array( Roles::SCOUTER, Roles::SECRETARIA, Roles::TESORERIA, Roles::TUTOR, Roles::PARTICIPANTE, Roles::INTENDENTE ) as $rol ) {
		$ok( empty( get_role( $rol )->capabilities['asde_grupo_datos'] ), "el rol $rol no puede" );
	}
	$ok( Catalogo::existe( 'asde_grupo_datos' ) && 'grupo' === Catalogo::modulo( 'asde_grupo_datos' ) && isset( Catalogo::MODULOS['grupo'] ), 'está en el gestor de permisos (módulo «Datos del grupo»)' );

	echo "== Parte F · La pantalla ==\n";
	$crear = static function ( string $login, array $roles, array $meta ) use ( &$ids, $grupo ): int {
		$id = wp_insert_user( array( 'user_login' => $login, 'user_email' => $login . '@example.invalid', 'user_pass' => wp_generate_password( 24 ), 'role' => $roles[0] ) );
		foreach ( array_slice( $roles, 1 ) as $r ) {
			get_userdata( $id )->add_role( $r );
		}
		update_user_meta( $id, Usuarios::META_GRUPO, $grupo['id'] );
		update_user_meta( $id, Usuarios::META_ESTADO, Usuarios::ACTIVO );
		foreach ( $meta as $k => $v ) {
			foreach ( (array) $v as $valor ) {
				add_user_meta( $id, $k, $valor );
			}
		}
		$ids[ $login ] = $id;
		return $id;
	};
	$u_sc    = $crear( 'prueba-dg-sc', array( Roles::SCOUTER ), array( Usuarios::META_SOCIO => '990091', Usuarios::META_SECCION => 'lobatos' ) );
	$u_coord = $crear( 'prueba-dg-coord', array( Roles::COORDINACION ), array( Usuarios::META_SOCIO => '990092' ) );
	$u_fam   = $crear( 'prueba-dg-fam', array( Roles::TUTOR ), array( Usuarios::META_REPRESENTADO => '990094' ) );
	$como    = static function ( int $uid ): void {
		wp_set_current_user( $uid );
		Ambito::olvidar( $uid );
	};
	$vista = new DatosGrupoView();
	Grupos::guardar_datos( $n, $base );
	$pintar = static function ( int $uid ) use ( $vista, $como ): string {
		$como( $uid );
		return $vista->render();
	};
	$html = $pintar( $u_fam );
	$ok( false !== strpos( $html, 'Grupo Prueba' ) && false !== strpos( $html, 'G07123456' ) && false !== strpos( $html, 'Calle Mayor 1' ) && false === strpos( $html, 'name="nombre"' ), 'una familia ve los datos del grupo, sin formulario' );
	$html = $pintar( $u_sc );
	$ok( false !== strpos( $html, 'Illes Balears' ) && false === strpos( $html, 'Guardar los datos del grupo' ), 'un scouter también los ve, sin poder cambiarlos' );
	$html = $pintar( $u_coord );
	$ok( false !== strpos( $html, 'Guardar los datos del grupo' ) && false !== strpos( $html, 'name="provincia"' ) && 1 === preg_match( '/<option value="07"\s+selected/', $html ), 'Coordinación ve el formulario, con la provincia elegida' );
	$ok( false !== strpos( $html, '07' . $n . '0001' ) && false === strpos( $html, 'Warning' ) && false === strpos( $html, 'Fatal' ), 'y el ejemplo del nº de socio/a (' . '07' . $n . '0001)' );

	$redirigir = static function ( $location ) {
		throw new RuntimeException( 'redirect:' . $location );
	};
	$morir = static fn() => static function () {
		throw new RuntimeException( 'die' );
	};
	add_filter( 'wp_redirect', $redirigir, 1 );
	add_filter( 'wp_die_handler', $morir, 999 );
	$enviar = static function ( int $uid, array $post, bool $nonce = true ) use ( $vista, $como ): string {
		$como( $uid );
		$_POST = $_REQUEST = $post + ( $nonce ? array( '_wpnonce' => wp_create_nonce( 'asde_grupo_datos' ) ) : array() );
		try {
			$vista->handle_post();
			return 'sin redirección';
		} catch ( RuntimeException $e ) {
			return $e->getMessage();
		} finally {
			$_POST = $_REQUEST = array();
		}
	};
	$post = array( 'asde_action' => 'guardar_datos_grupo', 'nombre' => 'Grupo Nuevo', 'denominacion' => 'Nueva SL', 'cif' => 'B12345678', 'sede' => 'Plaza 3', 'cp' => '07002', 'poblacion' => 'Manacor', 'provincia' => '07', 'email_contacto' => 'nuevo@example.invalid' );
	$ok( 'die' === $enviar( $u_sc, $post ) && 'Grupo Prueba' === Grupos::por_numero( $n )['nombre'], 'un scouter: 403 y no cambia nada' );
	$ok( 'die' === $enviar( $u_fam, $post ) && 'Grupo Prueba' === Grupos::por_numero( $n )['nombre'], 'una familia: 403 y no cambia nada' );
	$ok( 'die' === $enviar( $u_coord, $post, false ) && 'Grupo Prueba' === Grupos::por_numero( $n )['nombre'], 'sin nonce: no cambia nada' );
	$r = $enviar( $u_coord, $post );
	$ok( 0 === strpos( $r, 'redirect:' ) && 'Grupo Nuevo' === Grupos::por_numero( $n )['nombre'] && 'B12345678' === Grupos::por_numero( $n )['cif'] && 'Manacor' === Grupos::por_numero( $n )['poblacion'], 'Coordinación guarda los datos' );
	$reg = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT detalles FROM ' . Logger::table() . " WHERE id > %d AND accion = 'grupo_datos_guardados' ORDER BY id DESC LIMIT 1", $log_antes ) ); // phpcs:ignore WordPress.DB
	$ok( false !== strpos( $reg, 'nombre' ) && false === strpos( $reg, 'Grupo Nuevo' ), 'queda en el registro qué campos cambiaron (no sus valores)' );
	$r = $enviar( $u_coord, array( 'provincia' => '28' ) + $post );
	$ok( '07' === Grupos::por_numero( $n )['provincia'], 'cambiar la provincia sin marcar la confirmación: no se guarda' );
	$r = $enviar( $u_coord, array( 'provincia' => '28', 'confirmar_provincia' => '1' ) + $post );
	$ok( '28' === Grupos::por_numero( $n )['provincia'] && '28' === NumeroSocio::provincia( $n ), 'con la confirmación, sí (y los números pasan a llevar 28)' );
	$r = $enviar( $u_coord, array( 'cif' => 'mal' ) + $post );
	$ok( 'B12345678' === Grupos::por_numero( $n )['cif'], 'un dato inválido no se guarda' );
	remove_filter( 'wp_redirect', $redirigir, 1 );
	remove_filter( 'wp_die_handler', $morir, 999 );
} catch ( Throwable $e ) {
	++$fails;
	echo 'FAIL excepcion: ', $e->getMessage(), ' (', basename( $e->getFile() ), ':', $e->getLine(), ")\n";
} finally {
	$_POST = $_REQUEST = array();
	wp_set_current_user( 0 );
	foreach ( array( 'asde_ap_grupos' => $grupos_antes, Organizacion::OPTION => $org_antes, 'asde_ap_sepa_' . $n => $sepa_antes ) as $op => $valor ) {
		null === $valor ? delete_option( $op ) : update_option( $op, $valor, false );
	}
	Grupos::olvidar();
	Organizacion::olvidar();
	$lista = implode( ',', array_map( 'intval', $ids ) ) ?: '0';
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Logger::table() . " WHERE id > %d AND ( user_id IN ($lista) OR objeto = 'grupo' )", $log_antes ) ); // phpcs:ignore WordPress.DB
	foreach ( $ids as $uid ) {
		delete_transient( 'asde_ap_notices_' . $uid );
		Ambito::olvidar( (int) $uid );
		wp_delete_user( (int) $uid );
	}
	Escritura::usar_para_pruebas( null );
	$crm->desactivar();
	remove_filter( 'pre_http_request', $cortar_crm, 2 );
	remove_filter( 'asde_ap_instantanea_segundos', '__return_zero' );
	wp_using_ext_object_cache( $cache_externa );
	$ahora = array_column( (array) $wpdb->get_results( 'SELECT * FROM ' . SociosTable::table(), ARRAY_A ), null, 'num_socio_asde' ); // phpcs:ignore WordPress.DB
	foreach ( array_diff_key( $ahora, $socios_antes ) as $asde => $f ) {
		$wpdb->delete( SociosTable::table(), array( 'num_socio_asde' => (string) $asde ) );
	}
	Grupo::forzar( null );
}

echo $fails ? "\n{$fails} FALLOS\n" : "\nTODO OK (el sitio queda como estaba)\n";
