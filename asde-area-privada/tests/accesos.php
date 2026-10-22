<?php
/**
 * Pruebas de la v0.53 (entorno LOCAL, doc 56): «Gestión de accesos». Ejecutar desde app/public del site Local:
 *
 *   wp eval-file <ruta completa>/wp-content/plugins/asde-area-privada/tests/accesos.php
 *
 * Usa el CRM simulado de tests/lib (fichas inventadas, emails @example.invalid): ninguna petición llega al CRM real.
 * Al terminar se borra lo creado (usuarios `prueba-acc-*`, concesiones, cambios de rol, historial) y se restauran
 * los permisos de los roles y los módulos del grupo.
 */
defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/lib/CrmSimulado.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

use AsdeBalears\AreaPrivada\Accesos\Auditoria;
use AsdeBalears\AreaPrivada\Accesos\Catalogo;
use AsdeBalears\AreaPrivada\Accesos\Concesiones;
use AsdeBalears\AreaPrivada\Accesos\PermisosRol;
use AsdeBalears\AreaPrivada\Auth\Ambito;
use AsdeBalears\AreaPrivada\Domain\Grupo;
use AsdeBalears\AreaPrivada\Domain\Grupos;
use AsdeBalears\AreaPrivada\Domain\Modulos;
use AsdeBalears\AreaPrivada\Frontend\Menu;
use AsdeBalears\AreaPrivada\Frontend\Notices;
use AsdeBalears\AreaPrivada\Frontend\Views\AccesosView;
use AsdeBalears\AreaPrivada\Install\Roles;
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
foreach ( get_users( array( 'search' => 'prueba-acc-*', 'search_columns' => array( 'user_login' ) ) ) as $u ) {
	exit( 'Ya existe el usuario ' . $u->user_login . " (¿una ejecución anterior interrumpida?). Bórralo antes de repetir la prueba.\n" );
}
wp_set_current_user( 0 );

// --- Lo que se restaura al final ---------------------------------------------
$aud_antes     = (int) $wpdb->get_var( 'SELECT COALESCE(MAX(id), 0) FROM ' . Auditoria::table() ); // phpcs:ignore WordPress.DB
$cambios_antes = PermisosRol::cambios();
$modulos_antes = get_option( 'asde_ap_modulos', null );
$cache_externa = wp_using_ext_object_cache( true );
$cortar_crm    = static function ( $pre, $args, $url ) {
	return false === $pre && str_contains( (string) $url, '/rest.php' ) ? new WP_Error( 'prueba', 'CRM real cortado durante la prueba' ) : $pre;
};
add_filter( 'pre_http_request', $cortar_crm, 2, 3 );

$P   = array( 'Asde_Prueba_Crm', 'persona' );
$crm = new Asde_Prueba_Crm(
	array(
		$P( 41, 'Cora', 'Acceso Luz', '1980-08-08', 'Scouters', array( 'email1' => 'coord-acc@example.invalid' ) ),
		$P( 42, 'Teo', 'Acceso Gil', '1985-05-05', 'Scouters', array( 'email1' => 'teso-acc@example.invalid' ) ),
		$P( 43, 'Ana', 'Acceso Sanz', '1990-01-01', 'Scouters', array( 'email1' => 'ana-acc@example.invalid' ) ),
		$P( 44, 'Ben', 'Acceso Mas', '1992-02-02', 'Scouters', array( 'email1' => 'ben-acc@example.invalid' ) ),
		$P( 45, 'Ivo', 'Acceso Pons', '1975-03-03', 'Scouters', array( 'email1' => 'ivo-acc@example.invalid' ) ),
		$P( 46, 'Pau', 'Acceso Coll', '2012-07-07', 'Scouts' ),
	),
	$grupo['crm_id']
);
Grupo::forzar( $grupo );
$crm->activar();
Modulos::guardar( (string) $grupo['numero'], array_keys( Modulos::LISTA ) );

$ids   = array();
$crear = static function ( string $login, array $roles, array $meta ) use ( &$ids, $grupo ): int {
	$id = wp_insert_user( array( 'user_login' => $login, 'user_email' => $login . '@example.invalid', 'user_pass' => wp_generate_password( 24 ), 'role' => $roles[0] ) );
	$u  = get_userdata( $id );
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
$u_coord = $crear( 'prueba-acc-coord', array( Roles::COORDINACION ), array( Usuarios::META_SOCIO => '990041' ) );
$u_teso  = $crear( 'prueba-acc-teso', array( Roles::TESORERIA ), array( Usuarios::META_SOCIO => '990042' ) );
$u_sc    = $crear( 'prueba-acc-sc', array( Roles::SCOUTER ), array( Usuarios::META_SOCIO => '990043', Usuarios::META_SECCION => 'rovers' ) );
$u_sc2   = $crear( 'prueba-acc-sc2', array( Roles::SCOUTER ), array( Usuarios::META_SOCIO => '990044', Usuarios::META_SECCION => 'rovers' ) );
$u_int   = $crear( 'prueba-acc-int', array( Roles::INTENDENTE ), array( Usuarios::META_SOCIO => '990045' ) );
$u_fam   = $crear( 'prueba-acc-fam', array( Roles::TUTOR ), array( Usuarios::META_REPRESENTADO => '990046' ) );

$como = static function ( int $uid ): Ambito {
	wp_set_current_user( $uid );
	Ambito::olvidar( $uid );
	Modulos::olvidar();
	return Ambito::actual();
};
$tiene = static fn( int $uid, string $cap ): bool => user_can( $uid, $cap );
add_filter( 'send_auth_cookies', '__return_false' );
$redirigir = static function ( $destino ) {
	throw new RuntimeException( 'asde-redirect:' . $destino );
};
add_filter( 'wp_redirect', $redirigir, 1 );
/** Envía un formulario a la vista como lo haría el navegador y devuelve los avisos. */
$enviar = static function ( array $campos, string $nonce ): string {
	$_POST    = $campos + array( '_wpnonce' => wp_create_nonce( $nonce ) );
	$_REQUEST = $_POST;
	try {
		( new AccesosView() )->handle_post();
	} catch ( RuntimeException $e ) {
		// Redirige al terminar.
	} finally {
		$_POST    = array();
		$_REQUEST = array();
	}
	return Notices::render();
};
$pintar = static function ( array $get ) {
	$_GET = $get;
	try {
		return ( new AccesosView() )->render();
	} finally {
		$_GET = array();
	}
};
$actor = static fn( int $uid ): WP_User => get_userdata( $uid );
$hoy   = wp_date( 'Y-m-d' );
$ayer  = wp_date( 'Y-m-d', time() - DAY_IN_SECONDS );
$manana = wp_date( 'Y-m-d', time() + DAY_IN_SECONDS );

try {
	echo "== Parte A · El catálogo y los permisos de fábrica ==\n";
	$ok( count( Catalogo::todos() ) > 25, 'el catálogo tiene los permisos repartibles (' . count( Catalogo::todos() ) . ')' );
	$sin_def = array_filter( Catalogo::todos(), static function ( string $p ) {
		foreach ( Roles::definitions() as $def ) {
			if ( isset( $def[1][ $p ] ) ) {
				return false;
			}
		}
		return true;
	} );
	$ok( ! $sin_def, 'todo permiso del catálogo existe en algún rol: ' . implode( ', ', $sin_def ) );
	$mal = array();
	foreach ( Catalogo::todos() as $p ) {
		foreach ( Catalogo::requiere( $p ) as $r ) {
			if ( ! Catalogo::existe( $r ) ) {
				$mal[] = "$p→$r";
			}
		}
		if ( Catalogo::VER === Catalogo::tipo( $p ) && Catalogo::requiere( $p ) ) {
			$mal[] = "$p es de ver y pide algo";
		}
		if ( ! isset( Catalogo::MODULOS[ Catalogo::modulo( $p ) ] ) ) {
			$mal[] = "$p sin módulo";
		}
	}
	$ok( ! $mal, 'los requisitos y los módulos del catálogo son coherentes: ' . implode( ', ', $mal ) );
	$viola = array();
	foreach ( Catalogo::ROLES as $rol ) {
		$f = array_filter( PermisosRol::de_fabrica( $rol ) );
		if ( array_diff_key( $f, Catalogo::depurar( $f ) ) ) {
			$viola[] = $rol . ': ' . implode( ',', array_keys( array_diff_key( $f, Catalogo::depurar( $f ) ) ) );
		}
	}
	$ok( ! $viola, 'ningún rol de fábrica tiene una acción sin su «ver»: ' . implode( ' | ', $viola ) );
	$ok( $tiene( $u_coord, 'asde_accesos' ) && $tiene( $u_teso, 'asde_accesos' ) && ! $tiene( $u_sc, 'asde_accesos' ) && ! $tiene( $u_int, 'asde_accesos' ) && ! $tiene( $u_fam, 'asde_accesos' ), 'solo Coordinación y Tesorería (y Secretaría) entran en Gestión de accesos' );
	$ok( ! Catalogo::existe( 'asde_accesos' ) && ! Catalogo::existe( 'asde_view_self' ) && ! Catalogo::existe( 'asde_solo_lectura' ), 'la entrada a Gestión de accesos y las capabilities de identidad no se reparten' );
	$ok( array() !== Catalogo::dependientes( 'asde_view_all' ) && in_array( 'asde_edit_all', Catalogo::dependientes( 'asde_view_all' ), true ), 'los dependientes de «ver todo» incluyen «editar todo»' );

	echo "\n== Parte B · Conceder permisos sueltos ==\n";
	$a = $actor( $u_coord );
	$ok( ! $tiene( $u_sc, 'asde_tesoreria_config' ), 'el scouter no puede cambiar las cuotas de entrada' );
	$r = Concesiones::conceder( $a, $u_coord, 'asde_tienda_pago', null, 'x' );
	$ok( is_wp_error( $r ) && str_contains( $r->get_error_message(), 'ti mismo' ), 'nadie se concede permisos a sí mismo' );
	$r = Concesiones::conceder( $a, $u_fam, 'asde_inventario', null, '' );
	$ok( is_wp_error( $r ), 'no se conceden permisos a una familia' );
	$r = Concesiones::conceder( $actor( $u_sc2 ), $u_sc, 'asde_inventario_aprobar', null, 'x' );
	$ok( is_wp_error( $r ), 'un scouter sin acceso a Gestión de accesos no concede nada' );
	$r = Concesiones::conceder( $a, $u_sc, 'asde_inventario_aprobar', null, '' );
	$ok( is_wp_error( $r ) && str_contains( $r->get_error_message(), 'motivo' ), 'un permiso sensible exige motivo' );
	$r = Concesiones::conceder( $a, $u_sc, 'asde_reports', null, '' );
	$ok( is_wp_error( $r ) && str_contains( $r->get_error_message(), 'por su rol' ), 'lo que ya trae el rol no se concede otra vez' );
	$r = Concesiones::conceder( $a, $u_sc, 'asde_no_existe', null, '' );
	$ok( is_wp_error( $r ), 'un permiso que no está en el catálogo no se concede' );
	$r = Concesiones::conceder( $a, $u_sc, 'asde_inventario_aprobar', $ayer, 'x' );
	$ok( is_wp_error( $r ) && str_contains( $r->get_error_message(), 'ya ha pasado' ), 'una fecha pasada no vale' );
	$r = Concesiones::conceder( $a, $u_sc, 'asde_inventario_aprobar', '2026-13-45', 'x' );
	$ok( is_wp_error( $r ), 'una fecha imposible no vale' );
	$r = Concesiones::conceder( $a, $u_sc, 'asde_edit_all', null, 'x' );
	$ok( is_wp_error( $r ) && str_contains( $r->get_error_message(), 'Antes necesita' ), 'una acción exige antes el permiso de ver' );

	$ok( true === Concesiones::conceder( $a, $u_sc, 'asde_inventario_aprobar', null, 'Compras del grupo' ), 'Coordinación concede un permiso sensible sin caducidad, con motivo' );
	$ok( $tiene( $u_sc, 'asde_inventario_aprobar' ), 'el scouter ya puede dar el visto bueno a las listas de compra' );
	$ok( ! $tiene( $u_sc2, 'asde_inventario_aprobar' ), 'otro scouter sigue sin poder' );
	$ok( '1' === get_option( 'asde_ap_hay_concesiones' ), 'hay concesiones activas' );
	$mapa = Concesiones::mapa( get_userdata( $u_sc ) );
	$ok( 'extra' === ( $mapa['asde_inventario_aprobar']['origen'] ?? '' ) && 'rol' === ( $mapa['asde_reports']['origen'] ?? '' ), 'el mapa distingue lo que viene del rol de lo suelto' );

	$ok( true === Concesiones::conceder( $a, $u_sc, 'asde_view_all', $manana, 'Cubre a Secretaría' ), 'concede «ver todo» hasta mañana' );
	$ok( $tiene( $u_sc, 'asde_view_all' ), '«ver todo» vale hasta la fecha' );
	$ok( true === Concesiones::conceder( $a, $u_sc, 'asde_edit_all', null, 'Cubre a Secretaría' ), 'con «ver todo» ya se puede conceder «editar todo»' );
	$ok( $tiene( $u_sc, 'asde_edit_all' ), 'el scouter edita todas las fichas' );
	$ok( true === Concesiones::conceder( $a, $u_sc, 'asde_view_all', $manana, 'Renovado' ) && 1 === count( array_filter( Concesiones::activas_de( $u_sc ), static fn( $c ) => 'asde_view_all' === $c['permiso'] ) ), 'volver a conceder el mismo permiso lo renueva, no lo duplica' );

	echo "\n== Parte C · Quitar, caducar y cascada ==\n";
	$c_ver = null;
	foreach ( Concesiones::activas_de( $u_sc ) as $c ) {
		if ( 'asde_view_all' === $c['permiso'] ) {
			$c_ver = (int) $c['id'];
		}
	}
	$ok( true === Concesiones::revocar( $a, (int) $c_ver, 'Ya no hace falta' ), 'se retira «ver todo»' );
	$ok( ! $tiene( $u_sc, 'asde_view_all' ) && ! $tiene( $u_sc, 'asde_edit_all' ), 'al quitar «ver todo» se va también «editar todo», que se apoyaba en él' );
	$ok( $tiene( $u_sc, 'asde_inventario_aprobar' ), 'lo demás sigue' );
	$aud = Auditoria::listado( array( 'objetivo' => $u_sc ) );
	$ok( count( array_filter( $aud, static fn( $f ) => Auditoria::REVOCA === $f['accion'] && 'asde_edit_all' === $f['permiso'] ) ) === 1, 'la cascada también queda en el historial' );
	$ok( is_wp_error( Concesiones::revocar( $a, (int) $c_ver ) ), 'una concesión ya retirada no se retira otra vez' );

	// Caducidad: se simula que la fecha de «inventario_aprobar» pasó ayer.
	$ok( true === Concesiones::conceder( $a, $u_sc, 'asde_inventario_aprobar', $hoy, 'Hasta hoy' ), 'concede hasta hoy (incluido)' );
	$ok( $tiene( $u_sc, 'asde_inventario_aprobar' ), 'el último día todavía vale' );
	$wpdb->update( Concesiones::table(), array( 'caduca' => $ayer ), array( 'user_id' => $u_sc, 'permiso' => 'asde_inventario_aprobar', 'estado' => 'activo' ) );
	Concesiones::olvidar();
	$ok( ! $tiene( $u_sc, 'asde_inventario_aprobar' ), 'vencido el día, deja de valer aunque nadie lo haya marcado todavía' );
	$ok( 1 === Concesiones::caducar_vencidas(), 'la tarea diaria marca como caducada la que venció' );
	$ok( 0 === Concesiones::caducar_vencidas(), 'y no la marca dos veces' );
	$aud = Auditoria::listado( array( 'objetivo' => $u_sc, 'accion' => Auditoria::CADUCA ) );
	$ok( 1 === count( $aud ) && 0 === (int) $aud[0]['actor'], 'la caducidad queda en el historial, hecha por el sistema' );
	$ok( '0' === get_option( 'asde_ap_hay_concesiones' ), 'sin concesiones activas el filtro no consulta la base de datos' );

	echo "\n== Parte D · Matriz de permisos por rol ==\n";
	$deseado = static function ( string $rol, array $cambios ): array {
		$base = PermisosRol::de_rol( $rol );
		return array( $rol => $cambios + $base );
	};
	$r = PermisosRol::guardar( $deseado( Roles::SCOUTER, array( 'asde_inventario_aprobar' => true ) ), $a );
	$ok( 1 === $r['cambios'] && $tiene( $u_sc, 'asde_inventario_aprobar' ) && $tiene( $u_sc2, 'asde_inventario_aprobar' ), 'Coordinación da al rol Scouter el visto bueno del inventario: lo tienen todos los scouters' );
	$ok( PermisosRol::cambiado( Roles::SCOUTER, 'asde_inventario_aprobar' ), 'queda marcado como cambiado respecto a los de fábrica' );
	$r = PermisosRol::guardar( $deseado( Roles::COORDINACION, array( 'asde_tienda_pago' => true ) ), $a );
	$ok( 0 === $r['cambios'] && ! $tiene( $u_coord, 'asde_tienda_pago' ) && $r['avisos'], 'nadie se añade permisos a su propio rol' );
	$r = PermisosRol::guardar( $deseado( Roles::INTENDENTE, array( 'asde_tienda_entrega' => true ) ), $a );
	$ok( 0 === $r['cambios'] && ! $tiene( $u_int, 'asde_tienda_entrega' ), 'no se añade una acción a un rol que no tiene su «ver»' );
	$r = PermisosRol::guardar( $deseado( Roles::INTENDENTE, array( 'asde_tienda_gestion' => true, 'asde_tienda_entrega' => true ) ), $a );
	$ok( 2 === $r['cambios'] && $tiene( $u_int, 'asde_tienda_entrega' ), 'el «ver» y su acción juntos sí entran' );
	$r = PermisosRol::guardar( $deseado( Roles::INTENDENTE, array( 'asde_tienda_gestion' => false ) ), $a );
	$ok( $r['cambios'] >= 2 && ! $tiene( $u_int, 'asde_tienda_gestion' ) && ! $tiene( $u_int, 'asde_tienda_entrega' ), 'al quitar el «ver» a un rol se van sus acciones' );
	$r = PermisosRol::guardar( $deseado( Roles::COORDINACION, array( 'asde_crm_sync' => false ) ), $a );
	$ok( 1 === $r['cambios'] && ! $tiene( $u_coord, 'asde_crm_sync' ), 'sí se puede quitar un permiso a su propio rol' );
	$r = PermisosRol::guardar( $deseado( Roles::SCOUTER, array( 'asde_view_section' => false ) ), $actor( $u_teso ) );
	$ok( ! $tiene( $u_sc, 'asde_view_section' ) && ! $tiene( $u_sc, 'asde_edit_section_contact' ) && ! $tiene( $u_sc, 'asde_view_health' ), 'Tesorería quita «ver su sección» a los scouters y se van sus acciones' );
	$ok( -1 === PermisosRol::restaurar( Roles::COORDINACION, $a ) || ! $tiene( $u_coord, 'asde_crm_sync' ), 'Coordinación no puede restaurar su propio rol si eso le devuelve permisos' );
	$ok( PermisosRol::restaurar( Roles::SCOUTER, $actor( $u_teso ) ) > 0 && $tiene( $u_sc, 'asde_view_section' ) && ! $tiene( $u_sc, 'asde_inventario_aprobar' ), 'restaurar el rol Scouter devuelve los permisos de fábrica' );
	$ok( 0 === PermisosRol::restaurar( Roles::INTENDENTE, $actor( $u_teso ) ), 'el rol Intendente ya estaba como de fábrica (sus cambios se anularon al deshacerlos)' );
	$ok( PermisosRol::restaurar( Roles::COORDINACION, $actor( $u_teso ) ) > 0 && $tiene( $u_coord, 'asde_crm_sync' ), 'Tesorería restaura a Coordinación' );
	$ok( ! PermisosRol::cambios(), 'sin cambios guardados al terminar' );
	$rol_aud = Auditoria::listado( array( 'accion' => Auditoria::ROL_ANADE ) );
	$ok( count( $rol_aud ) >= 2, 'los cambios de rol quedan en el historial' );

	// Un cambio de la matriz sobrevive a una actualización del plugin (Roles::install).
	PermisosRol::guardar( $deseado( Roles::SCOUTER, array( 'asde_inventario_aprobar' => true ) ), $a );
	Roles::install();
	$ok( $tiene( $u_sc, 'asde_inventario_aprobar' ), 'Roles::install() vuelve a aplicar lo elegido en la matriz' );
	PermisosRol::restaurar( Roles::SCOUTER, $actor( $u_teso ) );
	$ok( ! $tiene( $u_sc, 'asde_inventario_aprobar' ), 'y al restaurar se va' );

	echo "\n== Parte E · Pantallas ==\n";
	$como( $u_sc );
	$ok( str_contains( $pintar( array( 'vista' => 'matriz' ) ), 'No tienes acceso' ), 'un scouter no entra en Gestión de accesos' );
	$ok( ! isset( Menu::plano( Ambito::actual() )['accesos'] ), 'y no la tiene en el menú' );
	$como( $u_coord );
	$ok( isset( Menu::plano( Ambito::actual() )['accesos'] ), 'Coordinación tiene «Accesos» en el menú' );
	$html = $pintar( array( 'vista' => 'matriz' ) );
	$ok( str_contains( $html, 'Permisos por rol' ) && str_contains( $html, 'name="perm[asde_scouter][asde_reports]"' ) && str_contains( $html, 'Sensible' ), 'la matriz enseña roles × permisos y marca los sensibles' );
	$ok( str_contains( $html, 'data-asde-accesos=' ) && 1 === preg_match( '/name="perm\[asde_coordinacion\]\[asde_tienda_pago\]"[^>]*disabled/', $html ), 'las casillas que no puede añadirse a su propio rol van bloqueadas' );
	$html = $pintar( array( 'vista' => 'personas' ) );
	$ok( str_contains( $html, 'Accesos por persona' ) && str_contains( $html, 'prueba-acc-sc' ) && ! str_contains( $html, 'prueba-acc-fam' ), 'la lista de personas enseña a los de gestión y no a las familias' );
	$html = $pintar( array( 'vista' => 'personas', 'usuario' => (string) $u_sc ) );
	$ok( str_contains( $html, 'Por su rol' ) && str_contains( $html, 'name="permiso" value="asde_inventario_aprobar"' ) && str_contains( $html, 'Antes necesita' ), 'la ficha de accesos de una persona enseña lo del rol, lo que se puede conceder y lo que necesita antes' );
	$html = $pintar( array( 'vista' => 'personas', 'usuario' => (string) $u_coord ) );
	$ok( str_contains( $html, 'no concedértelos' ) && ! str_contains( $html, 'name="asde_action" value="accesos_conceder"' ), 'en sus propios accesos no hay formularios para concederse' );
	$ok( str_contains( $pintar( array( 'vista' => 'personas', 'usuario' => (string) $u_fam ) ), 'No tienes acceso' ) || str_contains( $pintar( array( 'vista' => 'personas', 'usuario' => (string) $u_fam ) ), 'no está entre las que' ), 'no se abre la ficha de accesos de una familia' );

	$av = $enviar( array( 'asde_action' => 'accesos_conceder', 'usuario' => (string) $u_sc, 'permiso' => 'asde_inventario_aprobar', 'caduca_tipo' => 'fecha', 'caduca' => '', 'motivo' => 'x' ), 'asde_accesos_conceder_' . $u_sc );
	$ok( str_contains( $av, 'Elige la fecha' ) && ! $tiene( $u_sc, 'asde_inventario_aprobar' ), 'elegir «hasta una fecha» sin fecha avisa y no concede' );
	$av = $enviar( array( 'asde_action' => 'accesos_conceder', 'usuario' => (string) $u_sc, 'permiso' => 'asde_inventario_aprobar', 'caduca_tipo' => 'fecha', 'caduca' => $manana, 'motivo' => 'Prueba' ), 'asde_accesos_conceder_' . $u_sc );
	$ok( str_contains( $av, 'Permiso concedido' ) && $tiene( $u_sc, 'asde_inventario_aprobar' ), 'conceder desde el formulario hasta una fecha' );
	$av = $enviar( array( 'asde_action' => 'accesos_conceder', 'usuario' => (string) $u_coord, 'permiso' => 'asde_tienda_pago', 'caduca_tipo' => 'sin', 'motivo' => 'Para mí' ), 'asde_accesos_conceder_' . $u_coord );
	$ok( str_contains( $av, 'ti mismo' ) && ! $tiene( $u_coord, 'asde_tienda_pago' ), 'concederse un permiso desde el formulario se rechaza' );
	$id_c = (int) ( Concesiones::activas_de( $u_sc )[0]['id'] ?? 0 );
	$av   = $enviar( array( 'asde_action' => 'accesos_retirar', 'usuario' => (string) $u_sc, 'concesion' => (string) $id_c ), 'asde_accesos_retirar_' . $id_c );
	$ok( str_contains( $av, 'Permiso retirado' ) && ! $tiene( $u_sc, 'asde_inventario_aprobar' ), 'retirar desde el formulario' );
	$av = $enviar( array( 'asde_action' => 'accesos_matriz', 'roles' => array( Roles::SCOUTER ), 'perm' => array( Roles::SCOUTER => array_fill_keys( array_keys( array_filter( PermisosRol::de_rol( Roles::SCOUTER ) ) ), '1' ) + array( 'asde_inventario_aprobar' => '1' ) ) + array() ), 'asde_accesos_matriz' );
	$ok( str_contains( $av, 'Permisos guardados' ) && $tiene( $u_sc, 'asde_inventario_aprobar' ), 'guardar la matriz desde el formulario' );
	$ok( $tiene( $u_teso, 'asde_inventario_aprobar' ) && $tiene( $u_int, 'asde_inventario' ), 'los roles que el formulario no enviaba no se tocan' );
	PermisosRol::restaurar( Roles::SCOUTER, $actor( $u_teso ) );
	foreach ( Catalogo::ROLES as $rol ) {
		PermisosRol::restaurar( $rol, $actor( $u_teso ) );
	}
	$html = $pintar( array( 'vista' => 'historial' ) );
	$ok( str_contains( $html, 'Historial de accesos' ) && str_contains( $html, 'Concede un permiso' ) && str_contains( $html, 'Caduca un permiso' ), 'el historial enseña concesiones y caducidades' );
	$html = $pintar( array( 'vista' => 'historial', 'accion' => 'caduca' ) );
	$ok( str_contains( $html, 'Caduca un permiso' ) && ! str_contains( $html, '<td data-label="Qué">Concede un permiso' ), 'el historial se filtra por tipo' );
	$html = $pintar( array( 'vista' => 'historial', 'persona' => (string) $u_sc2 ) );
	$ok( str_contains( $html, 'No hay nada en el historial' ), 'y por persona (sin nada para quien no tiene movimientos)' );
	$como( $u_teso );
	$ok( str_contains( $pintar( array( 'vista' => 'historial' ) ), 'Historial de accesos' ), 'Tesorería también ve el historial' );

	echo "\n== Parte F · Módulos y cuentas ==\n";
	Concesiones::conceder( $actor( $u_coord ), $u_sc, 'asde_inventario_aprobar', null, 'Prueba de módulos' );
	Modulos::guardar( (string) $grupo['numero'], array_diff( array_keys( Modulos::LISTA ), array( Modulos::INVENTARIO ) ) );
	$como( $u_sc );
	$ok( ! $tiene( $u_sc, 'asde_inventario_aprobar' ), 'con el módulo apagado, un permiso suelto del módulo tampoco vale' );
	Modulos::guardar( (string) $grupo['numero'], array_keys( Modulos::LISTA ) );
	$como( $u_sc );
	$ok( $tiene( $u_sc, 'asde_inventario_aprobar' ), 'al encenderlo vuelve a valer' );
	$u2 = get_userdata( $u_sc );
	$u2->set_role( Roles::TUTOR );
	$ok( ! $tiene( $u_sc, 'asde_inventario_aprobar' ) && ! Concesiones::activas_de( $u_sc ), 'si la persona deja de ser de gestión, sus permisos sueltos se retiran' );
	$u2->set_role( Roles::SCOUTER );
	$ok( ! $tiene( $u_sc, 'asde_inventario_aprobar' ), 'y no reaparecen al volver' );
} finally {
	remove_filter( 'wp_redirect', $redirigir, 1 );
	remove_filter( 'send_auth_cookies', '__return_false' );
	$_POST = array();
	$_GET  = array();
	$crm->desactivar();
	remove_filter( 'pre_http_request', $cortar_crm, 2 );
	wp_using_ext_object_cache( $cache_externa );
	wp_set_current_user( 0 );

	// Roles como estaban: se borran los cambios de la prueba, se ponen los de antes y se reaplican.
	$wpdb->query( 'DELETE FROM ' . PermisosRol::table() ); // phpcs:ignore WordPress.DB
	foreach ( $cambios_antes as $rol => $permisos ) {
		foreach ( $permisos as $p => $v ) {
			$wpdb->insert( PermisosRol::table(), array( 'rol' => $rol, 'permiso' => $p, 'valor' => $v ? 1 : 0, 'cambiado_por' => 0, 'cambiado' => current_time( 'mysql', true ) ) );
		}
	}
	Roles::install();
	$lista = implode( ',', array_map( 'intval', $ids ) ) ?: '0';
	$wpdb->query( "DELETE FROM " . Concesiones::table() . " WHERE user_id IN ($lista)" ); // phpcs:ignore WordPress.DB
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id > %d', Auditoria::table(), $aud_antes ) ); // phpcs:ignore WordPress.DB
	foreach ( $ids as $uid ) {
		Ambito::olvidar( (int) $uid );
		wp_delete_user( (int) $uid );
	}
	if ( null === $modulos_antes ) {
		delete_option( 'asde_ap_modulos' );
	} else {
		update_option( 'asde_ap_modulos', $modulos_antes, false );
	}
	Modulos::olvidar();
	Concesiones::olvidar();
	Grupo::forzar( null );

	$quedan = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users} WHERE user_login LIKE 'prueba-acc-%'" ); // phpcs:ignore WordPress.DB
	$ok( 0 === $quedan && 0 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Auditoria::table() . ' WHERE id > ' . (int) $aud_antes ) && PermisosRol::cambios() === $cambios_antes, 'limpieza: sin usuarios, historial ni cambios de rol de la prueba; módulos como estaban' ); // phpcs:ignore WordPress.DB
}

echo "\n", $fails ? "FALLOS: $fails\n" : "TODO OK\n";
