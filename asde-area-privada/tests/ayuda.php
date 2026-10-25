<?php
/**
 * Pruebas de la v0.26 y la v0.28 (entorno LOCAL): la ayuda de cada página según el rol (el
 * apartado del manual de uso en la ventana emergente), solo texto desde la v0.28, el manual
 * completo de cada rol en la misma ventana y el enlace al manual con capturas (ayuda extra).
 * Ejecutar desde app/public del site Local:
 *
 *   wp eval-file <ruta completa>/wp-content/plugins/asde-area-privada/tests/ayuda.php
 *
 * Usa el CRM simulado de tests/lib (fichas inventadas: ninguna petición llega al CRM real).
 * Los trozos de ayuda (ayuda/) los genera herramientas/generar-manuales.php. Al terminar se
 * borra lo creado y la opción de la organización queda como estaba.
 */
defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/lib/CrmSimulado.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Auth\Ambito;
use AsdeBalears\AreaPrivada\Domain\Grupo;
use AsdeBalears\AreaPrivada\Domain\Grupos;
use AsdeBalears\AreaPrivada\Domain\Organizacion;
use AsdeBalears\AreaPrivada\Frontend\Ayuda;
use AsdeBalears\AreaPrivada\Frontend\Pages;
use AsdeBalears\AreaPrivada\Frontend\Views\AreaPrivadaView;
use AsdeBalears\AreaPrivada\Frontend\Views\AyudaView;
use AsdeBalears\AreaPrivada\Frontend\Views\View;
use AsdeBalears\AreaPrivada\Idiomas\Idioma;
use AsdeBalears\AreaPrivada\Install\Roles;
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
foreach ( get_users( array( 'search' => 'prueba-ayuda-*', 'search_columns' => array( 'user_login' ) ) ) as $u ) {
	exit( 'Ya existe el usuario ' . $u->user_login . " (¿una ejecución anterior interrumpida?). Bórralo antes de repetir la prueba.\n" );
}
wp_set_current_user( 0 );

$log_antes    = (int) $wpdb->get_var( 'SELECT COALESCE(MAX(id), 0) FROM ' . Logger::table() ); // phpcs:ignore WordPress.DB
$socios_antes = array_column( (array) $wpdb->get_results( 'SELECT * FROM ' . SociosTable::table(), ARRAY_A ), null, 'num_socio_asde' ); // phpcs:ignore WordPress.DB
$org_antes    = get_option( Organizacion::OPTION, null );

$cache_externa = wp_using_ext_object_cache( true );
$cortadas      = 0;
$cortar_crm    = static function ( $pre, $args, $url ) use ( &$cortadas ) {
	if ( false === $pre && str_contains( (string) $url, '/rest.php' ) ) {
		++$cortadas;
		return new WP_Error( 'prueba', 'CRM real cortado durante la prueba' );
	}
	return $pre;
};
add_filter( 'pre_http_request', $cortar_crm, 2, 3 );

$P   = array( 'Asde_Prueba_Crm', 'persona' );
$crm = new Asde_Prueba_Crm(
	array(
		$P( 31, 'Leo', 'Ayuda Sanz', '2016-05-10', 'Lobatos' ),
		$P( 32, 'Pol', 'Ayuda Mas', '2011-03-03', 'Escultas' ),
		$P( 33, 'Eva', 'Ayuda Gil', '1990-01-01', 'Scouters' ),
		$P( 34, 'Ana', 'Ayuda Coll', '1980-08-08', 'Scouters' ),
	),
	$grupo['crm_id']
);
Grupo::forzar( $grupo );
$crm->activar();

$ids   = array();
$crear = static function ( string $login, array $roles, array $meta ) use ( &$ids, $grupo ): int {
	$id = wp_insert_user(
		array(
			'user_login' => $login,
			'user_email' => '',
			'user_pass'  => wp_generate_password( 24 ),
			'role'       => $roles[0],
		)
	);
	$u = get_userdata( $id );
	foreach ( array_slice( $roles, 1 ) as $r ) {
		$u->add_role( $r );
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
$u_fam   = $crear( 'prueba-ayuda-fam', array( Roles::TUTOR ), array( Usuarios::META_REPRESENTADO => '990031' ) );
$u_pol   = $crear( 'prueba-ayuda-pol', array( Roles::PARTICIPANTE ), array( Usuarios::META_SOCIO => '990032' ) );
$u_sco   = $crear( 'prueba-ayuda-sco', array( Roles::SCOUTER ), array( Usuarios::META_SOCIO => '990033', Usuarios::META_SECCION => 'lobatos' ) );
$u_coord = $crear( 'prueba-ayuda-coord', array( Roles::SCOUTER, Roles::COORDINACION ), array( Usuarios::META_SOCIO => '990034', Usuarios::META_SECCION => 'rovers' ) );
$u_admin = $crear( 'prueba-ayuda-admin', array( 'administrator' ), array() );

$como = static function ( int $uid ): Ambito {
	wp_set_current_user( $uid );
	Ambito::olvidar( $uid );
	return Ambito::actual();
};
$base = 'https://manual.example.invalid/manual/';

try {
	echo "== Parte A · El manual de cada rol ==\n";
	$ok( 'familias' === Ayuda::manual_de( array( Roles::TUTOR ), false ) && 'participantes' === Ayuda::manual_de( array( Roles::PARTICIPANTE ), false ) && 'scouters' === Ayuda::manual_de( array( Roles::SCOUTER ), false ), 'familia → manual de familias; participante → de participantes; scouter → de scouters' );
	$ok( 'coordinacion' === Ayuda::manual_de( array( Roles::SCOUTER, Roles::COORDINACION ), false ) && 'coordinacion' === Ayuda::manual_de( array( Roles::TESORERIA ), false ) && 'coordinacion' === Ayuda::manual_de( array(), true ) && '' === Ayuda::manual_de( array(), false ), 'Secretaría, Coordinación, Tesorería y quien administra la web → el del equipo de coordinación; sin roles, ninguno' );
	$ok( 'familias' === Ayuda::manual_para( $como( $u_fam ) ) && 'participantes' === Ayuda::manual_para( $como( $u_pol ) ) && 'scouters' === Ayuda::manual_para( $como( $u_sco ) ) && 'coordinacion' === Ayuda::manual_para( $como( $u_coord ) ) && 'coordinacion' === Ayuda::manual_para( $como( $u_admin ) ), 'y el de cada cuenta, por los roles con los que entra' );

	echo "\n== Parte B · La ayuda de cada página ==\n";
	$hay = static fn( string $m, array $paginas ) => ! array_filter( $paginas, static fn( $p ) => ! is_readable( Ayuda::dir() . "$m/$p.html" ) );
	$ok( $hay( 'familias', array( 'area', 'ficha', 'pagos', 'tienda_pedidos', 'seguridad' ) ) && $hay( 'participantes', array( 'area', 'ficha', 'pagos', 'tienda_pedidos', 'seguridad' ) ), 'familias y participantes: inicio, ficha, pagos, uniforme y seguridad' );
	$ok( $hay( 'scouters', array( 'area', 'personas', 'ficha', 'solicitudes', 'cambios', 'informes', 'tesoreria', 'tienda_pedidos', 'tienda_gestion', 'inventario', 'seguridad' ) ), 'scouters: cada página que usan' );
	$ok( $hay( 'coordinacion', array( 'area', 'personas', 'ficha', 'alta', 'usuarios', 'emails', 'solicitudes', 'cambios', 'informes', 'tesoreria', 'familias', 'cuotas', 'federacion', 'banco', 'sepa', 'tienda_gestion', 'tienda_config', 'inventario', 'inventario_tipos', 'inventario_compras', 'seguridad' ) ), 'coordinación: cada página de la gestión del grupo' );
	$sin = array_filter( array_diff( array_keys( Pages::DEFINICIONES ), Pages::PUBLICAS ), static fn( $p ) => null === Ayuda::de_pagina( $p, '' ) );
	$ok( ! $sin, 'todas las páginas del área tienen ayuda en algún manual' . ( $sin ? ' (faltan: ' . implode( ', ', $sin ) . ')' : '' ) );
	Organizacion::guardar_manual( $base );
	$banco = Ayuda::de_pagina( 'banco', 'familias' );
	$ok( $banco && 'coordinacion' === $banco['manual'] && 'banco' === $banco['ancla'], 'si el manual del rol no tiene esa página, sale la del de coordinación (con su apartado)' );
	$ok( null === Ayuda::de_pagina( '../../wp-config', 'coordinacion' ) && null === Ayuda::de_pagina( 'no-existe', 'coordinacion' ) && null === Ayuda::de_pagina( '_completo', 'familias' ), 'una página inventada, con «../» o el manual completo como si fuera una página no leen nada' );
	$h = $banco['html'];
	$ok( ! str_contains( $h, '{{' ) && ! str_contains( $h, '<!--' ) && ! preg_match( '~\sid="~', $h ) && ! str_contains( $h, 'class="quien"' ), 'el trozo, sin marcadores, sin comentarios, sin id (chocarían con la página) y sin la etiqueta de quién' );
	$ok( ! str_contains( $h, '<img' ) && ! str_contains( $h, '<figure' ) && str_contains( $h, 'class="asde-ayuda__pantalla"' ) && str_contains( $h, '<h2>Tesorería</h2>' ) && str_contains( $h, 'Marcar varios como revisados' ) && ! str_contains( $h, 'Coste federativo</h3>' ), 'solo texto (v0.28): la entrada del apartado y su parte (Banco, no el coste federativo), sin imágenes; el pie de cada captura queda como una nota' );
	$tes = Ayuda::de_pagina( 'tesoreria', 'coordinacion' )['html'];
	$ok( str_contains( $tes, 'href="' . esc_attr( Ayuda::url_completo( 'coordinacion' ) ) . '#asde-manual-' ) && str_contains( $tes, 'href="' . esc_attr( Ayuda::url_completo( 'familias' ) ) . '#asde-manual-mis-pagos" data-asde-modal' ), 'los enlaces llevan al manual completo (del mismo rol o de otro), en su apartado y en la misma ventana' );

	echo "\n== Parte C · El manual completo de cada rol, solo texto y en la ventana (v0.28) ==\n";
	$malos = array();
	foreach ( Ayuda::MANUALES as $mm ) {
		$c = Ayuda::completo( $mm );
		if ( ! $c || ! str_contains( $c['html'], 'id="asde-manual-indice"' ) || str_contains( $c['html'], '<img' ) || str_contains( $c['html'], '{{' ) ) {
			$malos[] = $mm;
			continue;
		}
		preg_match_all( '~href="#(asde-manual-[^"]+)"~', $c['html'], $enl );
		preg_match_all( '~\sid="(asde-manual-[^"]+)"~', $c['html'], $ids_c );
		if ( array_diff( $enl[1], $ids_c[1] ) ) {
			$malos[] = $mm . ' (enlaces rotos: ' . implode( ', ', array_slice( array_diff( $enl[1], $ids_c[1] ), 0, 3 ) ) . ')';
		}
	}
	$ok( ! $malos, 'cada rol tiene su manual completo: con índice, sin imágenes ni marcas, y cada enlace interno lleva a su apartado' . ( $malos ? ': ' . implode( ', ', $malos ) : '' ) );
	$fam = Ayuda::completo( 'familias' )['html'];
	$ok( str_contains( $fam, '<h2>Manual para familias</h2>' ) && str_contains( $fam, 'id="asde-manual-mis-pagos"' ) && ! preg_match( '~\sid="(?!asde-manual-)~', $fam ), 'el de familias, con sus apartados; todos sus id llevan el prefijo (no chocan con los de la página de detrás)' );
	$ok( null === Ayuda::completo( 'administracion' ) && null === Ayuda::completo( '../x' ), 'el de administración (es de wp-admin) y uno inventado no se leen' );
	$tam      = 0;
	$imagenes = 0;
	foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( Ayuda::dir(), FilesystemIterator::SKIP_DOTS ) ) as $f ) {
		$tam += $f->getSize();
		if ( preg_match( '~\.(png|jpe?g|gif|webp|svg)$~i', $f->getFilename() ) ) {
			++$imagenes;
		}
	}
	$ok( 0 === $imagenes && $tam < 800 * 1024 && ! is_dir( ASDE_AP_DIR . 'manual' ), 'la ayuda del plugin es solo texto y pesa poco (' . round( $tam / 1024 ) . ' KB; con capturas, el zip pasaba de 10 MB) y el plugin no lleva el manual con capturas' );
	$ok( Ayuda::url_completo( 'scouters', 'tesoreria' ) === add_query_arg( array( 'asde_ayuda' => 'manual', 'asde_manual' => 'scouters' ), Pages::url( 'area' ) ) . '#asde-manual-tesoreria', 'la dirección del manual completo: ?asde_ayuda=manual, el manual y el apartado' );

	echo "\n== Parte D · El manual con capturas, ayuda extra (wp-admin → Organización federada) ==\n";
	$copia = get_option( Organizacion::OPTION, array() );
	$sin_clave = is_array( $copia ) ? $copia : array();
	unset( $sin_clave['manual'] );
	update_option( Organizacion::OPTION, $sin_clave, false );
	Organizacion::olvidar();
	$defecto = Organizacion::MANUALES_PUBLICADOS[ Organizacion::clave() ] ?? '';
	$ok( Organizacion::manual() === $defecto && ( 'balears' !== Organizacion::clave() || 'https://baleares.scout.es/manual/' === $defecto ), 'sin guardar nada, la del manual publicado de la organización (Scouts de Balears: https://baleares.scout.es/manual/)' );
	$r = Organizacion::guardar_manual( 'https://manual.example.invalid/docs/manual/index.html?x=1#y' );
	$ok( true === $r && 'https://manual.example.invalid/docs/manual/' === Organizacion::manual(), 'con la dirección de la portada (…/index.html) se guarda la carpeta' );
	$r2 = Organizacion::guardar_manual( 'esto no es una dirección' );
	$ok( is_wp_error( $r2 ) && 'https://manual.example.invalid/docs/manual/' === Organizacion::manual(), 'una dirección que no lo es no se guarda' );
	$o = get_option( Organizacion::OPTION, array() );
	Organizacion::guardar( (string) $o['organizacion'], (string) $o['codigo'], (array) $o['federacion'], (string) $o['identificador'] );
	$ok( 'https://manual.example.invalid/docs/manual/' === Organizacion::manual(), 'guardar la organización no borra la dirección del manual' );
	Organizacion::guardar_manual( '' );
	$ok( '' === Organizacion::manual(), 'vacía: no hay manual con capturas (aunque la organización tenga uno por defecto)' );

	echo "\n== Parte E · En las pantallas ==\n";
	Organizacion::guardar_manual( $base );
	$como( $u_fam );
	$inicio = ( new AreaPrivadaView() )->render();
	$ok( 1 === preg_match( '~<a class="asde-nav__ayuda" href="[^"]*asde_ayuda=1[^"]*" data-asde-modal>Ayuda</a>~', $inicio ), 'en la cabecera de cada página, «Ayuda» (se abre en la ventana emergente)' );
	$como( $u_coord );
	$pagina = ( new AyudaView( 'tesoreria' ) )->render();
	$ok( str_contains( $pagina, 'class="asde-card asde-ayuda"' ) && str_contains( $pagina, '<h2>Tesorería</h2>' ) && str_contains( $pagina, 'href="' . esc_url( Ayuda::url_completo( 'coordinacion', 'control' ) ) . '" data-asde-modal>Ver el manual completo</a>' ) && str_contains( $pagina, '← Volver a la página' ) && str_contains( $pagina, 'asde-nav' ), 'en su página: la ayuda de Tesorería del manual de coordinación, «Ver el manual completo» (en la ventana, en su apartado) y «Volver»' );
	$ok( str_contains( $pagina, 'href="' . $base . 'coordinacion.html#control" target="_blank" rel="noopener">Manual con capturas</a>' ) && 1 === preg_match( '~href="[^"]*asde_ayuda=manual[^"]*#asde-manual-[a-z-]+"\s+data-asde-modal~', $pagina ), 'con su dirección, el manual con capturas (ayuda extra, en otra pestaña); y los enlaces del texto siguen abriéndose en la ventana' );
	View::en_modal();
	$ventana = ( new AyudaView( 'tesoreria' ) )->render();
	View::en_modal( false );
	$ok( str_contains( $ventana, 'asde-ap--modal' ) && ! str_contains( $ventana, 'asde-nav' ) && ! str_contains( $ventana, '← Volver a la página' ) && str_contains( $ventana, 'Ver el manual completo' ), 'en la ventana: solo la ayuda y el enlace al manual completo' );
	$_GET = array( Ayuda::PARAM => 'manual' );
	View::en_modal();
	$completo_v = ( new AyudaView( 'area' ) )->render();
	View::en_modal( false );
	$_GET = array(
		Ayuda::PARAM        => 'manual',
		Ayuda::PARAM_MANUAL => 'familias',
	);
	$otro = ( new AyudaView( 'area' ) )->render();
	$_GET = array(
		Ayuda::PARAM        => 'manual',
		Ayuda::PARAM_MANUAL => 'administracion',
	);
	$raro = ( new AyudaView( 'area' ) )->render();
	$_GET = array();
	$ok( str_contains( $completo_v, 'asde-ap--modal' ) && str_contains( $completo_v, 'asde-ayuda--completo' ) && str_contains( $completo_v, '<h2>Manual del equipo de coordinación</h2>' ) && str_contains( $completo_v, 'id="asde-manual-indice"' ) && ! str_contains( $completo_v, 'data-asde-modal>Ver el manual completo</a>' ) && str_contains( $completo_v, 'Manual con capturas' ), 'con ?asde_ayuda=manual, el manual completo del rol en la ventana, con su índice y el enlace al manual con capturas' );
	$ok( str_contains( $otro, '<h2>Manual para familias</h2>' ) && str_contains( $raro, '<h2>Manual del equipo de coordinación</h2>' ), 'se puede pedir el de otro rol (enlaces entre manuales); uno que no es de la ayuda da el propio' );
	$como( $u_sco );
	$_GET     = array( Ayuda::PARAM => '1' );
	$codigo   = do_shortcode( '[asde_tesoreria]' );
	$_GET     = array();
	$sin_pedir = do_shortcode( '[asde_informes]' );
	$ok( str_contains( $codigo, 'asde-ayuda' ) && str_contains( $codigo, 'Tesorería: pagos y familias' ) && str_contains( $codigo, 'asde_manual=scouters' ) && ! str_contains( $sin_pedir, 'asde-ayuda' ), 'un scouter, con ?asde_ayuda=1, ve en la página la ayuda de su manual (sin pedirla, la página de siempre)' );
	$como( $u_fam );
	Idioma::forzar( 'ca' );
	$en_ca = ( new AyudaView( 'pagos' ) )->render();
	Idioma::forzar( null );
	$ok( str_contains( $en_ca, 'asde-ayuda__idioma' ) && str_contains( $en_ca, 'asde_manual=familias' ) && str_contains( $en_ca, 'asde-manual-mis-pagos' ), 'en otro idioma avisa de que la ayuda está en castellano; la familia ve la de su manual' );
	$como( $u_pol );
	$ok( str_contains( ( new AyudaView( 'no_existe' ) )->render(), 'Esta página todavía no tiene una ayuda propia.' ), 'una página sin ayuda lo dice (y enlaza al manual completo)' );
	$como( $u_coord );
	Organizacion::guardar_manual( '' );
	$sin_extra = ( new AyudaView( 'tesoreria' ) )->render();
	$ok( str_contains( $sin_extra, 'Ver el manual completo' ) && ! str_contains( $sin_extra, 'Manual con capturas' ) && str_contains( $sin_extra, '<h2>Tesorería</h2>' ), 'sin la dirección del manual con capturas: la ayuda y el manual completo igual, sin ese enlace' );

	$ok( 0 === $cortadas, 'ninguna petición ha salido hacia el CRM real' );
} catch ( Throwable $e ) {
	++$fails;
	echo 'FAIL excepcion: ', $e->getMessage(), ' (', basename( $e->getFile() ), ':', $e->getLine(), ")\n";
} finally {
	// Limpieza: todo lo creado fuera; lo tocado, como estaba.
	$_GET = array();
	View::en_modal( false );
	Idioma::forzar( null );
	null === $org_antes ? delete_option( Organizacion::OPTION ) : update_option( Organizacion::OPTION, $org_antes, false );
	Organizacion::olvidar();
	$crm->desactivar();
	remove_filter( 'pre_http_request', $cortar_crm, 2 );
	wp_using_ext_object_cache( $cache_externa );
	wp_set_current_user( 0 );

	$lista = implode( ',', array_map( 'intval', $ids ) ) ?: '0';
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Logger::table() . " WHERE id > %d AND user_id IN ($lista)", $log_antes ) ); // phpcs:ignore WordPress.DB
	foreach ( $ids as $uid ) {
		Ambito::olvidar( (int) $uid );
		wp_delete_user( (int) $uid );
	}
	$ahora = array_column( (array) $wpdb->get_results( 'SELECT * FROM ' . SociosTable::table(), ARRAY_A ), null, 'num_socio_asde' ); // phpcs:ignore WordPress.DB
	foreach ( array_diff_key( $ahora, $socios_antes ) as $asde => $f ) {
		$wpdb->delete( SociosTable::table(), array( 'num_socio_asde' => (string) $asde ) );
	}
	foreach ( $socios_antes as $asde => $f ) {
		if ( ( $ahora[ $asde ] ?? null ) !== $f ) {
			$wpdb->replace( SociosTable::table(), $f );
		}
	}
	Grupo::forzar( null );

	$quedan = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users} WHERE user_login LIKE 'prueba-ayuda-%'" ); // phpcs:ignore WordPress.DB
	$ok( 0 === $quedan && get_option( Organizacion::OPTION, null ) === $org_antes, 'limpieza: sin usuarios de la prueba y la organización como estaba' );
}

echo $fails ? "\n$fails FALLOS\n" : "\nTODO OK (nada tocado en el CRM real; el sitio queda como estaba)\n";
