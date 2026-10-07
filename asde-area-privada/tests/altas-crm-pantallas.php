<?php
/**
 * Pruebas de la v0.23 (entorno LOCAL): altas en el CRM, fase 3 (doc 34): las pantallas.
 * Personas → «Alta de socio/a» en pasos, la membresía en la ficha (nueva, baja, cambio
 * de sección y documento), el aviso de bajas en Tesorería y el interruptor de wp-admin.
 * Ejecutar desde app/public del site Local:
 *
 *   wp eval-file <ruta completa>/wp-content/plugins/asde-area-privada/tests/altas-crm-pantallas.php
 *
 * Todo contra el CRM simulado de tests/lib (fichas inventadas; las «escrituras» se
 * quedan en memoria): ninguna petición llega al CRM real. Al terminar se borra lo
 * creado (operaciones, auditoría, familias, usuarios) y se restauran las opciones.
 */
defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/lib/CrmSimulado.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

use AsdeBalears\AreaPrivada\Admin\ModulosPage;
use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Auth\Ambito;
use AsdeBalears\AreaPrivada\Crm\Altas;
use AsdeBalears\AreaPrivada\Crm\Operaciones;
use AsdeBalears\AreaPrivada\Crm\Permisos;
use AsdeBalears\AreaPrivada\Domain\Grupo;
use AsdeBalears\AreaPrivada\Domain\Grupos;
use AsdeBalears\AreaPrivada\Domain\Modulos;
use AsdeBalears\AreaPrivada\Frontend\Notices;
use AsdeBalears\AreaPrivada\Frontend\Views\FichaView;
use AsdeBalears\AreaPrivada\Frontend\Views\PersonasView;
use AsdeBalears\AreaPrivada\Frontend\Views\TesoreriaView;
use AsdeBalears\AreaPrivada\Frontend\Views\View;
use AsdeBalears\AreaPrivada\Install\Roles;
use AsdeBalears\AreaPrivada\Socios\SociosTable;
use AsdeBalears\AreaPrivada\Tesoreria\FamiliasTable;
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
foreach ( get_users( array( 'search' => 'prueba-pantalta-*', 'search_columns' => array( 'user_login' ) ) ) as $u ) {
	exit( 'Ya existe el usuario ' . $u->user_login . " (¿una ejecución anterior interrumpida?). Bórralo antes de repetir la prueba.\n" );
}
wp_set_current_user( 0 );
Operaciones::install_table();

$max_id        = static fn( string $t ) => (int) $wpdb->get_var( "SELECT COALESCE(MAX(id), 0) FROM $t" ); // phpcs:ignore WordPress.DB
$log_antes     = $max_id( Logger::table() );
$ops_antes     = $max_id( Operaciones::table() );
$opcion_antes  = get_option( Altas::OPCION, null );
$modulos_antes = get_option( 'asde_ap_modulos', null );
$socios_antes  = array_column( (array) $wpdb->get_results( 'SELECT * FROM ' . SociosTable::table(), ARRAY_A ), null, 'num_socio_asde' ); // phpcs:ignore WordPress.DB
$familias      = array();

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
add_filter( 'asde_ap_instantanea_segundos', '__return_zero' );
add_filter( 'send_auth_cookies', '__return_false' );
$redirigir = static function ( $destino ) {
	throw new RuntimeException( 'asde-redirect:' . $destino );
};
add_filter( 'wp_redirect', $redirigir, 1 );
$morir = static fn() => static function ( $mensaje ) {
	throw new RuntimeException( 'asde-die:' . ( is_wp_error( $mensaje ) ? $mensaje->get_error_message() : (string) $mensaje ) );
};
add_filter( 'wp_die_handler', $morir, PHP_INT_MAX );

// Documentos inventados con la letra bien calculada.
$P   = array( 'Asde_Prueba_Crm', 'persona' );
$doc = static fn( string $d ): array => array( 'stic_identification_number_c' => $d );
$crm = new Asde_Prueba_Crm(
	array(
		$P( 71, 'Nuria', 'Pantalla Uno', '2012-03-03', 'Scouts', $doc( '12345678Z' ) ),
		$P( 72, 'Pep', 'Pantalla Dos', '2000-01-01', '', $doc( '87654321X' ) ),
		$P( 73, 'Mia', 'Pantalla Tres', '2016-06-06', 'Lobatos' ),
		$P( 74, 'Teo', 'Pantalla Cuatro', '2017-07-07', 'Lobatos' ),
		$P( 75, 'Sara', 'Pantalla Secretaria', '1990-01-01', 'Scouters', $doc( '33333333P' ) ), // La cuenta de Secretaría de la prueba.
	),
	$grupo['crm_id']
);
$crm->otros_grupos = array(
	$P( 81, 'Otra', 'Ficha', '2010-02-02', 'Scouts', $doc( '11111111H' ) ),
	$P( 82, 'Otra', 'Ficha Dos', '2015-02-02', 'Lobatos', $doc( '66666666Q' ) ),
);
$id_de             = static fn( int $n ): string => sprintf( 'de000000-0000-4000-8000-%012d', 9900 + $n );
$crm->alta_membresia( $id_de( 71 ), 'Scouts', '2024-09-01' );
$crm->alta_membresia( $id_de( 72 ), 'Rovers', '2019-09-01', '2023-06-30' );
$crm->alta_membresia( $id_de( 73 ), 'Lobatos', '2024-09-01' );
$crm->alta_membresia( $id_de( 74 ), 'Lobatos', '2025-09-01' );
$crm->alta_membresia( $id_de( 75 ), 'Scouters', '2015-09-01' );
Grupo::forzar( $grupo );
$crm->activar();
Permisos::olvidar( $grupo['numero'] );
delete_transient( 'asde_ap_recuento_federal_' . $grupo['numero'] );

$ids   = array();
$crear = static function ( string $login, string $rol, array $meta = array() ) use ( &$ids, $grupo ): int {
	$id = wp_insert_user(
		array(
			'user_login' => $login,
			'user_email' => '',
			'user_pass'  => wp_generate_password( 24 ),
			'role'       => $rol,
		)
	);
	$ids[] = $id;
	update_user_meta( $id, Usuarios::META_GRUPO, $grupo['id'] );
	update_user_meta( $id, Usuarios::META_ESTADO, Usuarios::ACTIVO );
	foreach ( $meta as $k => $v ) {
		foreach ( (array) $v as $x ) {
			add_user_meta( $id, $k, $x );
		}
	}
	return $id;
};
$u_sec = $crear( 'prueba-pantalta-sec', Roles::SECRETARIA, array( Usuarios::META_SOCIO => '990075' ) );
( new WP_User( $u_sec ) )->add_role( Roles::TESORERIA );
$u_sco = $crear( 'prueba-pantalta-sco', Roles::SCOUTER, array( Usuarios::META_SOCIO => '990071' ) );
$u_mia = $crear( 'prueba-pantalta-mia', Roles::PARTICIPANTE, array( Usuarios::META_SOCIO => '990073' ) );
$u_fam = $crear( 'prueba-pantalta-fam', Roles::TUTOR, array( Usuarios::META_REPRESENTADO => array( '990073' ) ) );
$u_adm = $crear( 'prueba-pantalta-adm', 'administrator' );
$como  = static function ( int $uid ): void {
	wp_set_current_user( $uid );
	Ambito::olvidar( $uid );
};

/**
 * Lo que una vista recuerda de un POST dentro de la misma petición (el paso del alta, el
 * formulario de la membresía que falló): en el sitio cada petición empieza de cero; aquí,
 * todas van en el mismo proceso, así que se vacía antes de cada una.
 */
$reset = static function (): void {
	foreach ( array( array( PersonasView::class, 'alta' ), array( PersonasView::class, 'comprobacion' ), array( FichaView::class, 'membresia' ) ) as list( $clase, $prop ) ) {
		( new ReflectionProperty( $clase, $prop ) )->setValue( null, null );
	}
};
/** Pinta una vista con estos parámetros (en la ventana emergente: sin el menú del área). */
$pintar = static function ( View $vista, array $get = array() ): string {
	$_GET = $get;
	View::en_modal( true );
	try {
		return $vista->render();
	} finally {
		$_GET = array();
		View::en_modal( false );
	}
};
$ver = static function ( View $vista, array $get = array() ) use ( $reset, $pintar ): string {
	$reset();
	return $pintar( $vista, $get );
};
/**
 * Envía un formulario. Si la vista redirige, devuelve [avisos, destino, '']; si no
 * (contesta en la misma petición), [avisos, '', lo que pinta con $get].
 */
$enviar = static function ( View $vista, array $campos, string $nonce, array $get = array() ) use ( $reset, $pintar ): array {
	$reset();
	$_POST    = $campos + array( '_wpnonce' => wp_create_nonce( $nonce ) );
	$_REQUEST = $_POST;
	$destino  = '';
	$html     = '';
	try {
		$vista->handle_post();
		$html = $pintar( $vista, $get );
	} catch ( RuntimeException $e ) {
		$destino = $e->getMessage();
	} finally {
		$_POST    = array();
		$_REQUEST = array();
	}
	return array( Notices::render(), $destino, $html );
};
$alta  = static fn( array $campos ) => $enviar( new PersonasView(), $campos, 'asde_alta_socio', array( 'alta' => '1' ) );
$memb  = static function ( string $socio, string $accion, array $campos ) use ( $enviar ): array {
	$form = array(
		'membresia_nueva'   => 'nueva',
		'membresia_baja'    => 'baja',
		'membresia_seccion' => 'seccion',
		'poner_documento'   => 'documento',
	)[ $accion ];
	return $enviar(
		new FichaView(),
		$campos + array(
			'asde_action' => $accion,
			'socio'       => $socio,
		),
		'asde_membresia_' . $socio,
		array(
			'socio'     => $socio,
			'membresia' => $form,
		)
	);
};
$ficha    = static function ( string $id ) use ( $crm ): ?array {
	foreach ( array_merge( $crm->personas, $crm->sin_grupo ) as $p ) {
		if ( $p['id'] === $id ) {
			return $p;
		}
	}
	return null;
};
$de       = static fn( string $persona_id ) => array_values( array_filter( $crm->membresias, static fn( $m ) => $m['persona'] === $persona_id ) );
$con_doc  = static fn( string $d ) => count( array_filter( array_merge( $crm->personas, $crm->sin_grupo ), static fn( $p ) => $p['stic_identification_number_c'] === $d ) );
$escritas = static fn() => count( $crm->escrituras );
$hoy      = wp_date( 'Y-m-d' );
$manana   = wp_date( 'Y-m-d', time() + DAY_IN_SECONDS );
$hace     = static fn( int $anios ): string => wp_date( 'Y-m-d', strtotime( "-$anios years" ) );
$datos    = static function ( array $extra = array() ) use ( $hace ): array {
	return array_replace(
		array(
			'first_name'    => 'Aina',
			'last_name'     => 'Pantalla Nueva',
			'birthdate'     => $hace( 15 ),
			'stic_gender_c' => 'femenino',
		),
		$extra
	);
};

try {
	echo "== Parte A · Apagado, permisos y wp-admin ==\n";
	Altas::activar( $grupo['numero'], false );
	$como( $u_sec );
	$h = $ver( new PersonasView() );
	$ok( str_contains( $h, '¿Está ya en el CRM?' ) && ! str_contains( $h, 'Alta de socio/a' ), 'apagado: Personas no enseña «Alta de socio/a» (sí «¿Está ya en el CRM?»)' );
	$ok( str_contains( $ver( new PersonasView(), array( 'alta' => '1' ) ), 'no están activadas para tu grupo' ), 'y la pantalla del alta explica que no están activadas' );
	$h = $ver( new FichaView(), array( 'socio' => '990071' ) );
	$ok( ! str_contains( $h, 'Membresía en el CRM' ) && str_contains( $ver( new FichaView(), array( 'socio' => '990071', 'membresia' => 'baja' ) ), 'no están activadas para tu grupo' ), 'la ficha tampoco enseña la membresía en el CRM' );
	$antes = $escritas();
	list( , , $h ) = $alta( array( 'asde_action' => 'alta_crear', 'd' => $datos( $doc( '22222222J' ) ), 'seccion' => 'escultas', 'inicio' => $hoy ) );
	list( , $destino, $h2 ) = $memb( '990073', 'membresia_baja', array( 'fecha' => $hoy, 'motivo' => 'personal' ) );
	$ok( $antes === $escritas() && '' === $destino && str_contains( $h2, 'no están activadas' ), 'aunque se envíen los formularios a mano, apagado no se escribe nada en el CRM' );

	Altas::activar( $grupo['numero'], true );
	$crm->acls['Contacts']['edit'] = false;
	Permisos::olvidar( $grupo['numero'] );
	$h = $ver( new PersonasView() );
	$ok( ! str_contains( $h, 'Alta de socio/a' ) && str_contains( $ver( new PersonasView(), array( 'alta' => '1' ) ), 'no tiene permiso para crear personas' ), 'activadas, pero sin permiso del usuario del CRM para crear: no sale y se explica por qué' );
	$crm->acls['Contacts']['edit'] = true;
	Permisos::olvidar( $grupo['numero'] );

	$como( $u_sco );
	$h = $ver( new FichaView(), array( 'socio' => '990071', 'membresia' => 'baja' ) );
	list( , $destino ) = $memb( '990073', 'membresia_baja', array( 'fecha' => $hoy, 'motivo' => 'personal' ) );
	$ok( ! str_contains( $h, 'name="motivo"' ) && str_starts_with( $destino, 'asde-die:' ) && 1 === count( $de( $id_de( 73 ) ) ), 'un scouter (sin Personas) no ve los formularios ni puede enviarlos' );

	$como( $u_adm );
	ob_start();
	( new ModulosPage() )->render();
	$h = (string) ob_get_clean();
	$ok( str_contains( $h, 'Altas en el CRM' ) && preg_match( '/name="altas\[' . preg_quote( (string) $grupo['numero'], '/' ) . '\]" value="1"\s+checked/', $h ), 'wp-admin → Módulos: columna «Altas en el CRM», marcada en el grupo activado' );
	$guardar = static function ( array $altas ) use ( $grupo ): void {
		$_POST    = array(
			'_wpnonce' => wp_create_nonce( ModulosPage::ACCION ),
			'activos'  => array( (string) $grupo['numero'] => array_keys( Modulos::LISTA ) ),
			'altas'    => $altas,
		);
		$_REQUEST = $_POST;
		try {
			( new ModulosPage() )->handle_guardar();
		} catch ( RuntimeException $e ) {
			unset( $e ); // Redirige a la pantalla: es lo esperado.
		} finally {
			$_POST    = array();
			$_REQUEST = array();
		}
	};
	$guardar( array() );
	$apagada = ! Altas::activo( (string) $grupo['numero'] );
	$guardar( array( (string) $grupo['numero'] => '1' ) );
	$ok( $apagada && Altas::activo( (string) $grupo['numero'] ), 'al guardar se apaga sin la casilla y se enciende con ella, grupo a grupo' );

	echo "\n== Parte B · Alta de socio/a ==\n";
	$como( $u_sec );
	$h = $ver( new PersonasView() );
	$ok( str_contains( $h, 'Alta de socio/a' ) && str_contains( $h, 'alta=1' ), 'activadas: Personas enseña «Alta de socio/a» (en la ventana emergente)' );
	$h = $ver( new PersonasView(), array( 'alta' => '1' ) );
	$ok( str_contains( $h, 'value="alta_documento"' ) && str_contains( $h, 'data-asde-solo-consulta' ) && str_contains( $h, 'name="sin_documento"' ) && str_contains( $h, 'aria-current="step"' ), 'paso 1: el documento (o «es menor de 14 años y no tiene documento»), sin recargar al cerrar' );

	$antes = $escritas();
	list( , , $h ) = $alta( array( 'asde_action' => 'alta_documento', 'documento' => '12345678z' ) );
	$ok( str_contains( $h, 'Ya tiene ficha en tu grupo: no se crea otra.' ) && str_contains( $h, 'socio=990071' ) && str_contains( $h, 'no hay que crear otra ficha ni otra membresía' ), 'con el documento de alguien del grupo con membresía activa: se para y lleva a su ficha' );
	list( , , $h ) = $alta( array( 'asde_action' => 'alta_documento', 'documento' => '87654321X' ) );
	$ok( str_contains( $h, 'sin membresía activa' ) && str_contains( $h, 'membresia=nueva' ) && $antes === $escritas(), 'si está en el grupo sin membresía activa (quien vuelve): «Darle una membresía nueva» en su ficha, sin escribir nada' );
	list( , , $h ) = $alta( array( 'asde_action' => 'alta_documento', 'documento' => '12345678A' ) );
	$ok( str_contains( $h, 'asde-notice--error' ) && str_contains( $h, 'value="alta_documento"' ), 'un documento mal escrito se queda en el paso 1 con el error' );

	list( , , $h ) = $alta( array( 'asde_action' => 'alta_documento', 'documento' => '22222222-j' ) );
	$ok( str_contains( $h, 'value="alta_revisar"' ) && str_contains( $h, 'name="d[stic_identification_number_c]"' ) && str_contains( $h, 'value="22222222J"' ) && str_contains( $h, 'name="comprobado" value="22222222J"' ) && ! str_contains( $h, 'otro grupo u organización de Scouts de España.' ), 'un documento nuevo pasa a los datos, ya normalizado y sin aviso' );
	list( , , $h ) = $alta( array( 'asde_action' => 'alta_documento', 'documento' => '11111111H' ) );
	$ok( str_contains( $h, 'value="alta_revisar"' ) && str_contains( $h, 'Esta persona ya está dada de alta en otro grupo u organización de Scouts de España.' ) && str_contains( $h, 'name="otros" value="1"' ), 'si está en otro grupo u organización: el aviso, y se puede seguir' );

	$paso2 = static fn( array $d, array $mas = array() ) => array_merge(
		array(
			'asde_action' => 'alta_revisar',
			'd'           => $d,
			'seccion'     => 'escultas',
			'inicio'      => $hoy,
			'comprobado'  => (string) ( $d['stic_identification_number_c'] ?? '' ),
			'otros'       => '0',
		),
		$mas
	);
	list( , , $h ) = $alta( $paso2( array_diff_key( $datos( $doc( '22222222J' ) ), array( 'first_name' => 1 ) ) ) );
	$ok( str_contains( $h, 'Falta el nombre' ) && str_contains( $h, 'value="Pantalla Nueva"' ) && str_contains( $h, 'value="alta_revisar"' ), 'datos: sin el nombre se queda en el paso con el error y lo escrito' );
	list( , , $h ) = $alta( $paso2( $datos() ) );
	$ok( str_contains( $h, 'El documento es obligatorio a partir de los 14 años.' ), 'con 15 años y sin documento: el documento es obligatorio' );
	list( , , $h ) = $alta( $paso2( $datos( $doc( '22222222J' ) ), array( 'inicio' => $manana ) ) );
	$ok( str_contains( $h, 'La fecha de alta no puede ser posterior a hoy.' ), 'la fecha de alta no puede ser futura (P-87)' );

	$antes = $escritas();
	list( , , $h ) = $alta( $paso2( $datos( $doc( '22222222J' ) + array( 'email1' => 'aina.pantalla@example.invalid', 'tutor1_nombre_c' => 'Marta' ) ) ) );
	$ok( str_contains( $h, 'value="alta_crear"' ) && str_contains( $h, 'Aina Pantalla Nueva' ) && str_contains( $h, 'Escultas' ) && str_contains( $h, 'name="d[tutor1_nombre_c]" value="Marta"' ) && ! str_contains( $h, 'name="confirmo"' ) && $antes === $escritas(), 'revisar: el resumen, lo escrito en campos ocultos y nada escrito aún en el CRM' );
	$crear = static fn( array $d, array $mas = array() ) => array_merge( $paso2( $d ), array( 'asde_action' => 'alta_crear' ), $mas );
	list( , , $h ) = $alta( $crear( $datos( $doc( '22222222J' ) ), array( 'corregir' => '1' ) ) );
	$ok( str_contains( $h, 'value="alta_revisar"' ) && str_contains( $h, 'value="Aina"' ) && $antes === $escritas(), '«Corregir los datos» vuelve al formulario con todo lo escrito' );

	list( , , $h ) = $alta( $crear( $datos( $doc( '22222222J' ) + array( 'tutor1_nombre_c' => 'Marta' ) ) ) );
	$nueva = array_values( array_filter( $crm->personas, static fn( $p ) => '22222222J' === $p['stic_identification_number_c'] ) )[0] ?? null;
	$ok( str_contains( $h, 'Alta hecha en el CRM.' ) && str_contains( $h, '995001' ) && $nueva && 'Marta' === $nueva['tutor1_nombre_c'] && 1 === count( $de( $nueva['id'] ) ) && 'Escultas' === $de( $nueva['id'] )[0]['relationship_type'], 'confirmar: ficha y membresía en el CRM, y el nº de socio/a que devuelve' );
	$ok( str_contains( $h, 'socio=995001' ) && str_contains( $h, 'Dar acceso a la web' ) && str_contains( $h, 'Dar de alta a otra persona' ), 'al terminar: ver su ficha, darle acceso a la web o dar de alta a otra persona' );
	$guardado = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_value LIKE %s OR option_value LIKE %s", '%22222222J%', '%Pantalla Nueva%' ) ) + (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE datos LIKE %s OR cerrojo LIKE %s', Operaciones::table(), '%22222222J%', '%22222222J%' ) ); // phpcs:ignore WordPress.DB
	$ok( 0 === $guardado, 'ni el documento ni el nombre se guardan en WordPress (opciones ni operaciones): viajan en el formulario' );

	$antes = $escritas();
	list( , , $h ) = $alta( $crear( $datos( $doc( '22222222J' ) + array( 'first_name' => 'Otra Vez' ) ) ) );
	$ok( str_contains( $h, 'Ya tiene ficha en tu grupo' ) && $antes === $escritas() && 1 === $con_doc( '22222222J' ), 'si se reenvía el último paso con el mismo documento: no se crea otra ficha' );

	list( , , $h ) = $alta( $paso2( $datos( $doc( '11111111H' ) ), array( 'otros' => '1' ) ) );
	$ok( str_contains( $h, 'name="confirmo"' ) && str_contains( $h, 'quiero darla de alta también en el mío' ), 'si está en otro grupo, revisar pide confirmarlo con una casilla' );
	list( , , $h ) = $alta( $crear( $datos( $doc( '11111111H' ) ), array( 'otros' => '0' ) ) );
	$ok( str_contains( $h, 'Confirma que quieres darla de alta también en tu grupo.' ) && str_contains( $h, 'name="confirmo"' ) && 0 === $con_doc( '11111111H' ), 'aunque se quite el aviso del formulario, sin confirmarlo no se crea (lo vuelve a comprobar el alta)' );
	list( , , $h ) = $alta( $crear( $datos( $doc( '11111111H' ) + array( 'first_name' => 'Bel' ) ), array( 'otros' => '1', 'confirmo' => '1' ) ) );
	$ok( str_contains( $h, 'Alta hecha en el CRM.' ) && 1 === $con_doc( '11111111H' ), 'confirmado: se da de alta también en el grupo' );

	list( , , $h ) = $alta( array( 'asde_action' => 'alta_documento', 'documento' => '', 'sin_documento' => '1' ) );
	$ok( str_contains( $h, 'value="alta_revisar"' ) && str_contains( $h, 'name="sin_doc" value="1"' ), '«Es menor de 14 años y no tiene documento» pasa a los datos sin comprobar nada' );
	list( , , $h ) = $alta( $crear( $datos( array( 'first_name' => 'Biel', 'birthdate' => $hace( 9 ), 'stic_gender_c' => 'masculino' ) ), array( 'seccion' => 'lobatos', 'sin_doc' => '1' ) ) );
	$biel = array_values( array_filter( $crm->personas, static fn( $p ) => 'Biel' === $p['first_name'] ) )[0] ?? null;
	$ok( str_contains( $h, 'Alta hecha en el CRM.' ) && $biel && '' === (string) $biel['stic_identification_number_c'], 'y se da de alta sin documento' );

	echo "\n== Parte C · La membresía en la ficha ==\n";
	$h = $ver( new FichaView(), array( 'socio' => '990071' ) );
	$ok( str_contains( $h, 'Membresía en el CRM' ) && str_contains( $h, 'membresia=seccion' ) && str_contains( $h, 'membresia=baja' ) && ! str_contains( $h, 'membresia=nueva' ) && str_contains( $h, 'membresia=documento' ), 'ficha con membresía activa: «Cambiar de sección», «Dar de baja» y «Poner el documento»' );
	$h = $ver( new FichaView(), array( 'socio' => '990072' ) );
	$ok( str_contains( $h, 'membresia=nueva' ) && ! str_contains( $h, 'membresia=baja' ), 'sin membresía activa: «Nueva membresía»' );

	$h = $ver( new FichaView(), array( 'socio' => '990071', 'membresia' => 'nueva' ) );
	$ok( str_contains( $h, 'Ya tiene una membresía activa' ) && ! str_contains( $h, 'value="membresia_nueva"' ), 'a quien ya tiene membresía activa no se le ofrece otra' );
	$h = $ver( new FichaView(), array( 'socio' => '990072', 'membresia' => 'nueva' ) );
	list( $avisos, $destino ) = $memb( '990072', 'membresia_nueva', array( 'seccion' => 'scouters', 'fecha' => $hoy ) );
	$ok( str_contains( $h, 'value="membresia_nueva"' ) && str_contains( $destino, 'socio=990072' ) && str_contains( $avisos, 'Membresía nueva en el CRM' ) && 2 === count( $de( $id_de( 72 ) ) ) && '^Scouters^' === $ficha( $id_de( 72 ) )['stic_relationship_type_c'], 'quien vuelve: membresía nueva en su ficha, vuelve a la ficha con el aviso' );

	$h = $ver( new FichaView(), array( 'socio' => '990071', 'membresia' => 'seccion' ) );
	$ok( str_contains( $h, 'Scouts, desde el 01/09/2024' ) && ! str_contains( $h, '<option value="scouts"' ) && str_contains( $h, '<option value="escultas"' ), 'cambiar de sección: enseña la actual (del CRM) y ofrece las demás' );
	list( $avisos, $destino, $h ) = $memb( '990071', 'membresia_seccion', array( 'seccion' => 'escultas', 'fecha' => '2024-08-01' ) );
	$ok( '' === $destino && str_contains( $h, 'anterior a la de alta en la sección actual (01/09/2024)' ) && preg_match( '/<option value="escultas"\s+selected/', $h ) && 1 === count( $de( $id_de( 71 ) ) ), 'una fecha anterior al alta: se vuelve a pintar el formulario con el error y lo elegido, sin escribir' );
	list( $avisos, $destino ) = $memb( '990071', 'membresia_seccion', array( 'seccion' => 'escultas', 'fecha' => $hoy ) );
	$mn = $de( $id_de( 71 ) );
	$ok( str_contains( $destino, 'socio=990071' ) && str_contains( $avisos, 'pasa a Escultas' ) && 2 === count( $mn ) && 'cambioseccion' === $mn[0]['end_reason'] && '^Escultas^' === $ficha( $id_de( 71 ) )['stic_relationship_type_c'], 'y con la fecha bien: cierra la de Scouts con «cambio de sección» y abre la de Escultas' );

	$f_mia = FamiliasTable::crear( array( '990073' ) );
	if ( ! is_wp_error( $f_mia ) ) {
		$familias[] = (int) $f_mia;
	}
	$num_fam = is_wp_error( $f_mia ) ? '' : (string) FamiliasTable::obtener( (int) $f_mia )['numero'];
	$h       = $ver( new FichaView(), array( 'socio' => '990073', 'membresia' => 'baja' ) );
	$ok( '' !== $num_fam && str_contains( $h, 'Lobatos, desde el 01/09/2024' ) && str_contains( $h, 'prueba-pantalta-mia' ) && str_contains( $h, 'prueba-pantalta-fam' ) && str_contains( $h, 'nº ' . $num_fam ) && str_contains( $h, 'name="motivo"' ) && str_contains( $h, 'confirm(' ), 'dar de baja: la membresía que se cierra y qué cambia (su cuenta, las familiares y la cuota de su familia), con confirmación' );
	list( , $destino, $h ) = $memb( '990073', 'membresia_baja', array( 'fecha' => $hoy, 'motivo' => 'inventado' ) );
	list( , $destino2, $h2 ) = $memb( '990073', 'membresia_baja', array( 'fecha' => $manana, 'motivo' => 'personal' ) );
	$ok( '' === $destino && str_contains( $h, 'Elige la fecha y el motivo de la baja.' ) && '' === $destino2 && str_contains( $h2, 'La fecha de baja no puede ser posterior a hoy.' ) && 'activo' === $de( $id_de( 73 ) )[0]['estado_c'], 'sin un motivo de la lista o con fecha futura no se da de baja' );
	list( $avisos, $destino ) = $memb( '990073', 'membresia_baja', array( 'fecha' => $hoy, 'motivo' => 'personal' ) );
	$mm = $de( $id_de( 73 ) )[0];
	$ok( str_contains( $destino, 'socio=990073' ) && 'baja' === $mm['estado_c'] && $hoy === $mm['end_date'] && 'personal' === $mm['end_reason'] && str_contains( $avisos, 'ha quedado de baja en el CRM' ) && str_contains( $avisos, 'Su cuenta de acceso a la web ya no puede entrar.' ) && str_contains( $avisos, 'familia nº ' . $num_fam ), 'baja: se cierra en el CRM y el aviso dice qué cambia (cuenta, familias y cuota)' );

	$h = $ver( new FichaView(), array( 'socio' => '990071', 'membresia' => 'documento' ) );
	$ok( str_contains( $h, 'Esta ficha ya tiene un documento' ) && ! str_contains( $h, 'value="poner_documento"' ), 'poner el documento: a quien ya lo tiene no se le pide' );
	list( , $destino, $h ) = $memb( '990074', 'poner_documento', array( 'documento' => '12345678Z' ) );
	$ok( '' === $destino && str_contains( $h, 'Otra ficha de tu grupo ya tiene este documento (Nuria Pantalla Uno)' ) && '' === (string) $ficha( $id_de( 74 ) )['stic_identification_number_c'], 'con el documento de otra ficha del grupo: no se guarda (sería la misma persona dos veces)' );
	list( , $destino, $h ) = $memb( '990074', 'poner_documento', array( 'documento' => '66666666q' ) );
	$ok( '' === $destino && str_contains( $h, 'Confirma que es la misma persona' ) && str_contains( $h, 'name="confirmo"' ) && str_contains( $h, 'value="66666666q"' ) && '' === (string) $ficha( $id_de( 74 ) )['stic_identification_number_c'], 'si está en otro grupo: pide confirmarlo con una casilla y aún no lo guarda' );
	list( $avisos, $destino ) = $memb( '990074', 'poner_documento', array( 'documento' => '66666666q', 'confirmo' => '1' ) );
	$ok( str_contains( $destino, 'socio=990074' ) && '66666666Q' === $ficha( $id_de( 74 ) )['stic_identification_number_c'] && str_contains( $avisos, 'Documento guardado' ) && str_contains( $avisos, 'también está dado de alta en otro grupo' ), 'confirmado: se guarda normalizado y el aviso lo recuerda' );

	echo "\n== Parte D · Aviso en Tesorería ==\n";
	Modulos::guardar( (string) $grupo['numero'], array_keys( Modulos::LISTA ) );
	Logger::log( 'crm_baja', 'socio', '990074', array( 'grupo' => 'otro', 'fin' => $hoy, 'motivo' => 'personal' ) );
	$recientes = array_column( Altas::bajas_recientes(), 'socio' );
	$ok( in_array( '990073', $recientes, true ) && ! in_array( '990074', $recientes, true ), 'las bajas recientes son solo las del grupo en el que se trabaja' );
	$como( $u_sec );
	$h = $ver( new TesoreriaView() );
	$ok( str_contains( $h, 'Bajas recientes' ) && str_contains( $h, 'Mia Pantalla Tres' ) && str_contains( $h, 'familia nº ' . $num_fam ) && str_contains( $h, 'familia=' . (int) $f_mia ), 'Tesorería → Control de pagos avisa de la baja, con su familia (enlazada)' );

	$ok( 0 === $cortadas, 'ninguna petición ha salido hacia el CRM real' );
} finally {
	// Limpieza: todo lo creado fuera; lo tocado, como estaba.
	$crm->desactivar();
	remove_filter( 'pre_http_request', $cortar_crm, 2 );
	remove_filter( 'asde_ap_instantanea_segundos', '__return_zero' );
	remove_filter( 'send_auth_cookies', '__return_false' );
	remove_filter( 'wp_redirect', $redirigir, 1 );
	remove_filter( 'wp_die_handler', $morir, PHP_INT_MAX );
	$_GET  = array();
	$_POST = array();
	View::en_modal( false );
	null === $opcion_antes ? delete_option( Altas::OPCION ) : update_option( Altas::OPCION, $opcion_antes, false );
	null === $modulos_antes ? delete_option( 'asde_ap_modulos' ) : update_option( 'asde_ap_modulos', $modulos_antes, false );
	Modulos::olvidar();
	Permisos::olvidar( $grupo['numero'] );
	delete_transient( 'asde_ap_recuento_federal_' . $grupo['numero'] );
	wp_using_ext_object_cache( $cache_externa );
	wp_set_current_user( 0 );

	foreach ( $familias as $fid ) {
		$wpdb->delete( FamiliasTable::table_miembros(), array( 'familia_id' => (int) $fid ) );
		$wpdb->delete( FamiliasTable::table(), array( 'id' => (int) $fid ) );
	}
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id > %d', Operaciones::table(), $ops_antes ) );
	$lista = implode( ',', array_map( 'intval', $ids ) ) ?: '0';
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Logger::table() . " WHERE id > %d AND ( user_id IN ($lista) OR accion LIKE 'crm\\_%%' )", $log_antes ) ); // phpcs:ignore WordPress.DB
	foreach ( $ids as $uid ) {
		delete_transient( 'asde_ap_comprobaciones_' . $uid );
		delete_transient( 'asde_ap_notices_' . $uid );
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

	$ok( 0 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE id > %d', Operaciones::table(), $ops_antes ) ) && 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users} WHERE user_login LIKE 'prueba-pantalta-%'" ) && get_option( Altas::OPCION, null ) === $opcion_antes, 'limpieza: sin operaciones, usuarios ni familias de la prueba, y la opción de altas como estaba' ); // phpcs:ignore WordPress.DB
}

echo $fails ? "\n$fails FALLOS\n" : "\nTODO OK (nada tocado en el CRM real; el sitio queda como estaba)\n";
