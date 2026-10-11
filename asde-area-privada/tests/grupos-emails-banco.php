<?php
/**
 * Pruebas de la v0.6 (entorno LOCAL). Ejecutar desde app/public del site Local:
 *
 *   wp eval-file wp-content/plugins/asde-area-privada/tests/grupos-emails-banco.php
 *
 * - Grupos dados de alta a mano (credenciales cifradas, verificación en el CRM,
 *   baja, migración desde la v0.5).
 * - Registro de emails (enviado, error, sin email) y vista del email sin el enlace.
 * - Ordenación y paginación de listados.
 * - Ficheros del banco: Norma 43 y CSV (sintéticos, sin datos reales), importación
 *   sin duplicados y conciliación con familias. Todo en un grupo ficticio (999)
 *   que se borra al terminar.
 * Deja todo como estaba: opciones, usuarios, filas y emails de Mailpit de la prueba.
 */
defined( 'ABSPATH' ) || exit;

use AsdeBalears\AreaPrivada\Crm\ClientFactory;
use AsdeBalears\AreaPrivada\Crm\PersonaRepository;
use AsdeBalears\AreaPrivada\Crm\Settings;
use AsdeBalears\AreaPrivada\Domain\Grupo;
use AsdeBalears\AreaPrivada\Domain\Grupos;
use AsdeBalears\AreaPrivada\Frontend\Pages;
use AsdeBalears\AreaPrivada\Frontend\Tabla;
use AsdeBalears\AreaPrivada\Tesoreria\Banco\BancoTable;
use AsdeBalears\AreaPrivada\Tesoreria\Banco\Codificacion;
use AsdeBalears\AreaPrivada\Tesoreria\Banco\Conciliacion;
use AsdeBalears\AreaPrivada\Tesoreria\Banco\CsvBanco;
use AsdeBalears\AreaPrivada\Tesoreria\Banco\Norma43;
use AsdeBalears\AreaPrivada\Tesoreria\FamiliasTable;
use AsdeBalears\AreaPrivada\Tesoreria\MovimientosTable;
use AsdeBalears\AreaPrivada\Usuarios\Invitacion;
use AsdeBalears\AreaPrivada\Usuarios\RegistroEmails;
use AsdeBalears\AreaPrivada\Usuarios\Usuarios;

require_once ABSPATH . 'wp-admin/includes/user.php';
global $wpdb;

$fails = 0;
$ok    = function ( $cond, string $label ) use ( &$fails ) {
	if ( ! $cond ) {
		++$fails;
	}
	echo ( $cond ? 'OK   ' : 'FAIL ' ), $label, "\n";
};
$mailpit_ids   = static fn() => array_column( (array) ( json_decode( wp_remote_retrieve_body( wp_remote_get( 'http://localhost:10006/api/v1/messages?limit=1000' ) ), true )['messages'] ?? array() ), 'ID' );
$mailpit_antes = $mailpit_ids();
$grupos_orig   = get_option( 'asde_ap_grupos' );
$crm_orig      = get_option( Settings::OPTION );
$real          = Grupos::por_numero( '684' );
$_SERVER['REMOTE_ADDR'] = '192.0.2.' . random_int( 1, 254 );

// Restos de una ejecución anterior interrumpida.
$limpiar_999 = static function () use ( $wpdb ) {
	foreach ( (array) $wpdb->get_col( 'SELECT id FROM ' . FamiliasTable::table() . " WHERE grupo = '999'" ) as $fid ) { // phpcs:ignore WordPress.DB
		$wpdb->delete( MovimientosTable::table(), array( 'familia_id' => (int) $fid ) );
		$wpdb->delete( FamiliasTable::table_miembros(), array( 'familia_id' => (int) $fid ) );
		$wpdb->delete( FamiliasTable::table(), array( 'id' => (int) $fid ) );
	}
	$wpdb->query( 'DELETE FROM ' . BancoTable::table() . " WHERE grupo = '999'" ); // phpcs:ignore WordPress.DB
	$wpdb->query( 'DELETE FROM ' . BancoTable::table_lotes() . " WHERE grupo = '999'" ); // phpcs:ignore WordPress.DB
	$wpdb->query( 'DELETE FROM ' . \AsdeBalears\AreaPrivada\Tesoreria\Banco\TiposMovimiento::table() . " WHERE grupo = '999'" ); // phpcs:ignore WordPress.DB
};
$limpiar_999();
foreach ( array( '76849998', '76849997' ) as $login ) {
	$viejo = get_user_by( 'login', $login );
	if ( $viejo ) {
		$wpdb->delete( RegistroEmails::table(), array( 'user_id' => $viejo->ID ) );
		wp_delete_user( $viejo->ID );
	}
}

// ---------------------------------------------------------------------------
echo "== Parte A · Grupos dados de alta a mano ==\n";
$ok( '684' === Grupos::normalizar_numero( '684' ) && '058' === Grupos::normalizar_numero( '58' ) && null === Grupos::normalizar_numero( '0' ) && null === Grupos::normalizar_numero( '1234' ) && null === Grupos::normalizar_numero( 'abc' ), 'nº de grupo: hasta 3 cifras, con ceros delante' );
$ok( $real && '' !== $real['crm_id'] && '' !== $real['usuario'] && Grupos::tiene_clave( '684' ), 'el grupo 684 tiene usuario, contraseña y grupo de seguridad del CRM' );

$g = Grupos::guardar( '999', 'Grupo de prueba', '', null, true );
$ok( is_array( $g ) && Grupos::SIN_PROBAR === $g['estado'] && '' === $g['crm_id'], 'alta de un grupo sin credenciales: queda sin probar' );
$ok( is_wp_error( Grupos::guardar( '999', 'Otra vez', '', null, true ) ), 'no se puede dar de alta dos veces el mismo nº' );
$g = Grupos::guardar( '999', 'Grupo de prueba 999', 'usuario-prueba', 'contraseña-de-prueba', false );
$raw = get_option( 'asde_ap_grupos' );
$ok( is_array( $g ) && 'contraseña-de-prueba' === Grupos::credenciales( '999' )['clave'] && ! str_contains( wp_json_encode( $raw ), 'contraseña-de-prueba' ) && str_starts_with( $raw['999']['clave_enc'], 'v1:' ), 'la contraseña del CRM se guarda cifrada y se puede descifrar' );
$ok( ! isset( Grupos::todos()['999']['clave_enc'] ), 'la lista de grupos no incluye la contraseña' );
Grupo::forzar( Grupos::por_numero( '999' ) );
$ok( null === ClientFactory::reader() && null === PersonaRepository::make(), 'un grupo sin verificar no lee nada del CRM' );
Grupo::forzar( null );
$res = Grupos::verificar( '999' );
$ok( ! $res->ok && Grupos::ERROR === Grupos::por_numero( '999' )['estado'], 'con credenciales incorrectas la verificación falla: ' . $res->message );
$res = Grupos::verificar( '684' );
$ok( $res->ok && Grupos::OK === Grupos::por_numero( '684' )['estado'] && $real['crm_id'] === Grupos::por_numero( '684' )['crm_id'], 'verificación del grupo 684 en el CRM: ' . $res->message );
$ok( Grupos::borrar( '999' ) && null === Grupos::por_numero( '999' ), 'baja del grupo (sus datos se conservan)' );

// Migración desde la v0.5: grupo con clave = id del grupo de seguridad y credenciales globales.
$uid_mig = wp_insert_user(
	array(
		'user_login' => 'prueba-migracion-' . wp_rand( 1000, 9999 ),
		'user_pass'  => wp_generate_password( 24 ),
		'role'       => '',
	)
);
update_user_meta( $uid_mig, Usuarios::META_GRUPO, 'uuid-de-prueba' );
update_option( 'asde_ap_grupos', array( 'uuid-de-prueba' => array( 'id' => 'uuid-de-prueba', 'numero' => '777', 'nombre' => 'Grupo antiguo' ) ), false );
update_option( Settings::OPTION, array( 'api_type' => 'v41', 'url' => 'https://ejemplo.invalid', 'username' => 'api-antiguo', 'password_enc' => \AsdeBalears\AreaPrivada\Crm\Crypto::encrypt( 'clave-antigua' ) ), false );
Grupos::migrar();
$m = Grupos::por_numero( '777' );
$ok( $m && 'uuid-de-prueba' === $m['crm_id'] && 'api-antiguo' === $m['usuario'] && 'clave-antigua' === Grupos::credenciales( '777' )['clave'] && array( 'url' ) === array_keys( Settings::stored() ), 'migración v0.5: el grupo pasa a clave = nº con las credenciales globales; en el CRM queda solo la URL' );
$ok( '777' === get_user_meta( $uid_mig, Usuarios::META_GRUPO, true ), 'migración v0.5: el grupo de los usuarios pasa del id antiguo al nº' );
wp_delete_user( $uid_mig );
update_option( 'asde_ap_grupos', $grupos_orig, false );
update_option( Settings::OPTION, $crm_orig, false );
Grupos::olvidar();

// ---------------------------------------------------------------------------
echo "\n== Parte B · Registro de emails ==\n";
Grupo::forzar( Grupos::por_numero( '684' ) );
$repo    = PersonaRepository::make();
$persona = null;
foreach ( $repo ? $repo->todas( true ) : array() as $p ) {
	if ( $p->email && ! Usuarios::por_socio( $p->num_socio_asde ) ) {
		$persona = $p;
		break;
	}
}
$ok( null !== $persona, 'ficha de prueba con email encontrada (no se muestra)' );
$uids = array();
if ( $persona ) {
	$uid    = wp_insert_user(
		array(
			'user_login' => '76849998',
			'user_pass'  => wp_generate_password( 24 ),
			'role'       => '',
		)
	);
	$uids[] = $uid;
	update_user_meta( $uid, Usuarios::META_SOCIO, $persona->num_socio_asde );
	update_user_meta( $uid, Usuarios::META_EMAIL_ORIGEN, $persona->num_socio_asde . ':email1' );
	update_user_meta( $uid, Usuarios::META_GRUPO, '684' );
	$u = get_userdata( $uid );

	$enviado = Invitacion::enviar( $u, Invitacion::ALTA );
	$fila    = RegistroEmails::ultimos( array( $uid ) )[ $uid ] ?? null;
	$ok( $enviado && $fila && RegistroEmails::ENVIADO === $fila['estado'] && '684' === $fila['grupo'] && str_contains( $fila['destino'], '***' ), 'invitación enviada → registrada como «enviado», con el destino enmascarado' );
	$ok( $fila && ! str_contains( (string) $fila['datos'], $persona->email ) && ! str_contains( (string) $fila['datos'], 't=' ) && ! str_contains( (string) $fila['datos'], $persona->nombre ), 'el registro no guarda el email, el nombre ni el enlace personal' );
	$vista = $fila ? RegistroEmails::vista_previa( $fila ) : '';
	$ok( str_contains( $vista, '76849998' ) && str_contains( $vista, 'ENLACE-PERSONAL-NO-GUARDADO' ) && str_contains( $vista, 'Activar mi cuenta' ), 'vista del email: misma plantilla, con el enlace personal sustituido' );

	add_filter( 'pre_wp_mail', '__return_false' );
	$fallido = Invitacion::enviar( $u, Invitacion::RESET );
	remove_filter( 'pre_wp_mail', '__return_false' );
	$fila = RegistroEmails::ultimos( array( $uid ) )[ $uid ] ?? null;
	$ok( ! $fallido && $fila && RegistroEmails::ERROR === $fila['estado'] && '' !== $fila['error'] && Invitacion::RESET === $fila['tipo'], 'si el servidor de correo no lo acepta → «error» con el motivo' );

	$uid2   = wp_insert_user(
		array(
			'user_login' => '76849997',
			'user_pass'  => wp_generate_password( 24 ),
			'role'       => '',
		)
	);
	$uids[] = $uid2;
	update_user_meta( $uid2, Usuarios::META_GRUPO, '684' );
	$sin    = Invitacion::enviar( get_userdata( $uid2 ), Invitacion::ALTA );
	$fila   = RegistroEmails::ultimos( array( $uid2 ) )[ $uid2 ] ?? null;
	$ok( ! $sin && $fila && RegistroEmails::SIN_EMAIL === $fila['estado'], 'sin email en el CRM → «sin email», sin enviar nada' );
	$ok( 3 === count( RegistroEmails::listado( $uid ) ) + count( RegistroEmails::listado( $uid2 ) ), 'listado del registro por usuario' );
	Grupo::forzar( array( 'id' => '999', 'numero' => '999', 'nombre' => 'Otro', 'crm_id' => '' ) );
	$ok( ! RegistroEmails::listado( $uid ) && null === RegistroEmails::obtener( (int) $fila['id'] ), 'otro grupo no ve el registro de emails de este' );
	Grupo::forzar( null );
}

// ---------------------------------------------------------------------------
echo "\n== Parte C · Ordenar y paginar ==\n";
$filas = array();
for ( $i = 1; $i <= 60; $i++ ) {
	$filas[] = array(
		'n'      => $i,
		'nombre' => sprintf( 'Persona %02d', 61 - $i ),
	);
}
$_SERVER['REQUEST_URI'] = '/area-privada/personas/?ver=activas';
$_GET                   = array(
	'ver'        => 'activas',
	'orden'      => 'nombre',
	'dir'        => 'asc',
	'pag'        => '2',
	'por_pagina' => '25',
);
$uid_admin = (int) ( get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) )[0] ?? 0 );
wp_set_current_user( $uid_admin );
$pref_orig = get_user_meta( $uid_admin, 'asde_por_pagina', true );
$t         = new Tabla(
	array(
		'n'      => static fn( $f ) => $f['n'],
		'nombre' => static fn( $f ) => $f['nombre'],
	),
	'n'
);
$pag = $t->aplicar( $filas );
$ok( 25 === count( $pag ) && 'Persona 26' === $pag[0]['nombre'] && 3 === $t->paginas(), 'página 2 de 3 (25 por página), ordenada por nombre' );
$html = $t->paginacion();
$ok( str_contains( $html, 'Mostrando 26–50 de 60' ) && str_contains( $html, 'aria-current="page">2<' ) && str_contains( $html, 'name="ver" value="activas"' ), 'barra de paginación con la página actual y los filtros conservados' );
$ok( str_contains( $t->cabecera( 'nombre', 'Nombre' ), 'dir=desc' ) && str_contains( $t->cabecera( 'nombre', 'Nombre' ), '▲' ), 'la columna ordenada se puede invertir (▲ → desc)' );
$_GET = array( 'por_pagina' => '50' );
new Tabla( array( 'n' => static fn( $f ) => $f['n'] ), 'n' );
$_GET = array();
$t2   = new Tabla( array( 'n' => static fn( $f ) => $f['n'] ), 'n', 'desc' );
$pag  = $t2->aplicar( $filas );
$ok( 50 === count( $pag ) && 60 === $pag[0]['n'], 'el tamaño elegido (50) se recuerda para el usuario; orden descendente por defecto' );
$_GET = array( 'por_pagina' => '33' );
$t3   = new Tabla( array( 'n' => static fn( $f ) => $f['n'] ), 'n' );
$ok( 50 === count( $t3->aplicar( $filas ) ), 'solo se admiten tamaños de 5 en 5 hasta 50 (v0.31)' );
$_GET = array();
update_user_meta( $uid_admin, 'asde_por_pagina', $pref_orig );
wp_set_current_user( 0 );

// ---------------------------------------------------------------------------
echo "\n== Parte D · Norma 43 ==\n";
// Fichero sintético (datos inventados), codificado en CP850 como pide la norma.
$n43 = static function ( string $ref, array $extra = array(), ?callable $alterar = null ): string {
	$cp  = static fn( string $s ) => (string) mb_convert_encoding( $s, 'CP850', 'UTF-8' );
	$r22 = static fn( string $fecha, string $comun, string $dh, int $cent, string $doc = '' ) => '22' . '    ' . '0001' . $fecha . $fecha . $comun . '000' . $dh . str_pad( (string) $cent, 14, '0', STR_PAD_LEFT ) . str_pad( $doc, 10, '0', STR_PAD_LEFT ) . str_pad( '', 12 ) . str_pad( '', 16 );
	// array( código, texto ) o, para el ordenante (23-01), array( '01', nombre, código del ordenante ).
	$r23 = static fn( array $c ) => '23' . $c[0] . ( isset( $c[2] ) ? str_pad( $cp( $c[1] ), 66 ) . str_pad( $c[2], 10 ) : str_pad( $cp( $c[1] ), 76 ) );
	$movs = array_merge(
		array(
			array( '260901', '04', '2', 7000, array( array( '01', 'MARTÍNEZ PÉREZ, ANA', 'ORD0000001' ), array( '03', 'CUOTA ' . $ref . ' LUCAS' ) ) ),
			array( '260903', '17', '1', 250, array( array( '01', 'COMISION MANTENIMIENTO' ) ) ),
			array( '260905', '02', '2', 12900, array() ),
		),
		$extra
	);
	$lineas = array( '11' . '2056' . '0001' . '0012345678' . '260901' . '260930' . '2' . str_pad( '100000', 14, '0', STR_PAD_LEFT ) . '978' . '3' . str_pad( 'GRUPO SCOUT PRUEBA', 26 ) . '   ' );
	$debe   = array( 0, 0 );
	$haber  = array( 0, 0 );
	foreach ( $movs as $mv ) {
		$lineas[] = $r22( $mv[0], $mv[1], $mv[2], $mv[3] );
		foreach ( $mv[4] as $c ) {
			$lineas[] = $r23( $c );
		}
		if ( '1' === $mv[2] ) {
			++$debe[0];
			$debe[1] += $mv[3];
		} else {
			++$haber[0];
			$haber[1] += $mv[3];
		}
	}
	$final    = 100000 + $haber[1] - $debe[1];
	$lineas[] = '33' . '2056' . '0001' . '0012345678' . str_pad( (string) $debe[0], 5, '0', STR_PAD_LEFT ) . str_pad( (string) $debe[1], 14, '0', STR_PAD_LEFT ) . str_pad( (string) $haber[0], 5, '0', STR_PAD_LEFT ) . str_pad( (string) $haber[1], 14, '0', STR_PAD_LEFT ) . '2' . str_pad( (string) $final, 14, '0', STR_PAD_LEFT ) . '978' . '    ';
	$lineas[] = '88' . str_repeat( '9', 18 ) . str_pad( (string) count( $lineas ), 6, '0', STR_PAD_LEFT ) . str_repeat( ' ', 54 );
	if ( $alterar ) {
		$lineas = $alterar( $lineas );
	}
	return implode( "\r\n", $lineas ) . "\r\n";
};
$ok( strlen( explode( "\r\n", $n43( 'F684001' ) )[0] ) === 80, 'el fichero de prueba tiene registros de 80 posiciones' );
$l = Norma43::leer( $n43( 'F684001' ) );
$ok( ! $l['errores'] && 1 === count( $l['cuentas'] ) && 3 === count( $l['movimientos'] ) && 'CP850' === $l['codificacion'], 'Norma 43 leída: 1 cuenta, 3 movimientos, CP850 detectado' );
$c = $l['cuentas'][0] ?? array();
$ok( '2056 0001 ******5678' === ( $c['cuenta'] ?? '' ) && 1000.0 === $c['saldo_inicial'] && 1196.5 === $c['saldo_final'] && '2026-09-01' === $c['desde'], 'cuenta enmascarada, saldos inicial (1.000 €) y final (1.196,50 €) y periodo' );
$mv = $l['movimientos'];
$ok( 70.0 === $mv[0]['importe'] && -2.5 === $mv[1]['importe'] && 129.0 === $mv[2]['importe'], 'importes con signo (+70 · −2,50 · +129)' );
$ok( 'MARTÍNEZ PÉREZ, ANA' === $mv[0]['ordenante'] && str_contains( $mv[0]['concepto'], 'CUOTA F684001 LUCAS' ), 'modalidad 3: ordenante (con tildes desde CP850) y concepto SEPA' );
$ok( '' === $mv[1]['ordenante'] && str_contains( $mv[1]['concepto'], 'COMISION' ), 'en un cargo (concepto 17) el 23-01 es concepto, no ordenante' );
$ok( Norma43::parece( $n43( 'X' ) ) && ! Norma43::parece( "Fecha;Concepto;Importe\n01/09/2026;X;1,00" ), 'detecta si un fichero es Norma 43' );
$l = Norma43::leer( $n43( 'F684001', array(), static function ( $ls ) {
	$i        = count( $ls ) - 2;
	$ls[ $i ] = substr_replace( $ls[ $i ], '00002', 20, 5 ); // Registro 33 con 2 cargos en vez de 1.
	return $ls;
} ) );
$ok( (bool) $l['errores'], 'si los totales del registro 33 no cuadran → error (no se importa)' );
$l = Norma43::leer( $n43( 'F684001', array(), static fn( $ls ) => array_slice( $ls, 0, -3 ) ) );
$ok( (bool) $l['errores'], 'fichero cortado (sin 33 ni 88) → error' );
$l = Norma43::leer( $n43( 'F684001', array(), static function ( $ls ) {
	$ls[ count( $ls ) - 1 ] = substr_replace( $ls[ count( $ls ) - 1 ], '000099', 20, 6 );
	return $ls;
} ) );
$ok( (bool) $l['errores'], 'nº de registros del 88 incorrecto → error' );
$utf8 = Norma43::leer( (string) mb_convert_encoding( $n43( 'F684001' ), 'UTF-8', 'CP850' ) );
$ok( ! $utf8['errores'] && 'MARTÍNEZ PÉREZ, ANA' === $utf8['movimientos'][0]['ordenante'], 'también lee el fichero si viene en UTF-8' );

// ---------------------------------------------------------------------------
echo "\n== Parte E · CSV ==\n";
$csv = (string) mb_convert_encoding(
	"Cuenta;ES12 2056 0001 2312 3456 7890\r\nFecha exportación;26/09/2026\r\n\r\nFecha;Fecha valor;Concepto;Más datos;Importe;Saldo\r\n"
	. "01/09/2026;01/09/2026;TRANSFERENCIA;CUOTA F684001 IBAÑEZ;70,00;1.070,00\r\n"
	. "03/09/2026;03/09/2026;COMISION;;-2,50;1.067,50\r\n"
	. "05/09/2026;05/09/2026;INGRESO;;1.290,00;2.357,50\r\nSaldo final;;;;;2.357,50\r\n",
	'Windows-1252',
	'UTF-8'
);
$l = CsvBanco::leer( $csv );
$ok( ! $l['errores'] && 3 === count( $l['movimientos'] ) && 'Windows-1252' === $l['codificacion'], 'CSV leído: separador «;», títulos tras líneas informativas, 3 movimientos' );
$mv = $l['movimientos'];
$ok( 70.0 === $mv[0]['importe'] && -2.5 === $mv[1]['importe'] && 1290.0 === $mv[2]['importe'] && '2026-09-01' === $mv[0]['fecha'], 'fechas dd/mm/aaaa e importes con coma decimal y punto de miles' );
$ok( str_contains( $mv[0]['concepto'], 'TRANSFERENCIA CUOTA F684001 IBAÑEZ' ) && '2056 0001 ******7890' === $mv[0]['cuenta'], 'concepto con «Más datos», eñe desde Windows-1252 y cuenta sacada del IBAN' );
$l = CsvBanco::leer( "F.Op,Descr,Cant\n01/09/2026,UNO,\"10,50\"\n02/09/2026,DOS,-3\n" );
$ok( $l['necesita_mapeo'] && array( 'F.Op', 'Descr', 'Cant' ) === $l['columnas'], 'columnas desconocidas → pide elegirlas' );
$l = CsvBanco::leer( "F.Op,Descr,Cant\n01/09/2026,UNO,\"10,50\"\n02/09/2026,DOS,-3\n", array( 'fecha' => 0, 'concepto' => 1, 'importe' => 2 ) );
$ok( ! $l['errores'] && 2 === count( $l['movimientos'] ) && 10.5 === $l['movimientos'][0]['importe'] && -3.0 === $l['movimientos'][1]['importe'], 'con las columnas elegidas a mano lee bien (separador «,»)' );
$l = CsvBanco::leer( "Fecha;Concepto;Cargo;Abono\n02/09/2026;RECIBO LUZ;45,30;\n04/09/2026;TRANSF;;100\n" );
$ok( ! $l['errores'] && -45.3 === $l['movimientos'][0]['importe'] && 100.0 === $l['movimientos'][1]['importe'], 'columnas separadas de cargo y abono' );
$imp = array( '1.234,56' => 1234.56, '-45,00' => -45.0, '1234.56' => 1234.56, '1,234.56' => 1234.56, '1.234' => 1234.0, '12.5' => 12.5, '45,00-' => -45.0, '+30 €' => 30.0 );
$bien = true;
foreach ( $imp as $txt => $esperado ) {
	$bien = $bien && $esperado === CsvBanco::importe( (string) $txt );
}
$ok( $bien && null === CsvBanco::importe( 'abc' ), 'formatos de importe: 1.234,56 · -45,00 · 1234.56 · 1,234.56 · 1.234 · 12.5 · 45,00- · +30 €' );
$ok( '2026-09-01' === CsvBanco::fecha( '01/09/2026' ) && '2026-09-01' === CsvBanco::fecha( '1-9-26' ) && '2026-09-01' === CsvBanco::fecha( '2026-09-01' ) && null === CsvBanco::fecha( '31/02/2026' ), 'formatos de fecha (y fechas imposibles descartadas)' );
$ok( Codificacion::es_binario( "PK\x03\x04xlsx" ) && ! Codificacion::es_binario( $csv ), 'reconoce un Excel (binario) para pedir Norma 43 o CSV' );

// ---------------------------------------------------------------------------
echo "\n== Parte F · Importar y conciliar (grupo ficticio 999) ==\n";
Grupo::forzar(
	array(
		'id'      => '999',
		'numero'  => '999',
		'nombre'  => 'Grupo de prueba',
		'crm_id'  => '',
		'filtrar' => false,
	)
);
$fam_id = FamiliasTable::crear( array( '99990001', '99990002' ) );
$fam    = is_int( $fam_id ) ? FamiliasTable::obtener( $fam_id ) : null;
$ok( $fam && 'F999001' === $fam['numero'], 'familia de prueba F999001' );

$l = Norma43::leer( $n43( 'F-999-001' ) );
$l['movimientos'] = BancoTable::clasificar( $l['movimientos'] );
$r = BancoTable::importar( $l, 'n43', 'prueba.n43', hash( 'sha256', 'prueba-1' ) );
$ok( is_array( $r ) && 3 === $r['nuevos'] && 0 === $r['duplicados'], 'primera importación: 3 movimientos nuevos' );
$l['movimientos'] = BancoTable::clasificar( $l['movimientos'] );
$r = BancoTable::importar( $l, 'n43', 'prueba.n43', hash( 'sha256', 'prueba-1' ) );
$ok( is_array( $r ) && 0 === $r['nuevos'] && 3 === $r['duplicados'], 'el mismo fichero otra vez: nada nuevo (3 ya importados)' );
$l2 = Norma43::leer( $n43( 'F-999-001', array( array( '260910', '04', '2', 3500, array( array( '01', 'MARTÍNEZ PÉREZ, ANA', 'ORD0000001' ), array( '03', 'SEGUNDO PLAZO' ) ) ) ) ) );
$l2['movimientos'] = BancoTable::clasificar( $l2['movimientos'] );
$ok( 1 === count( array_filter( $l2['movimientos'], static fn( $m ) => ! $m['duplicado'] ) ), 'un fichero que se solapa: solo 1 movimiento nuevo' );
$r = BancoTable::importar( $l2, 'n43', 'prueba-2.n43', hash( 'sha256', 'prueba-2' ) );
$ok( is_array( $r ) && 1 === $r['nuevos'], 'importado el movimiento nuevo' );

// Un fallo inesperado a mitad de la importación (aquí, un concepto que no es texto al cifrarlo) no deja un lote a medias ni la transacción abierta.
$lotes_antes = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . BancoTable::table_lotes() . " WHERE grupo = '999'" ); // phpcs:ignore WordPress.DB
$malo        = $l2;
foreach ( $malo['movimientos'] as $i => $m ) {
	$malo['movimientos'][ $i ]['duplicado'] = false;
	$malo['movimientos'][ $i ]['huella']    = 'malo-' . $i;
}
$malo['movimientos'][0]['concepto'] = array( 'no es texto' );
$r = BancoTable::importar( $malo, 'n43', 'malo.n43', hash( 'sha256', 'prueba-malo' ) );
$lotes_despues = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . BancoTable::table_lotes() . " WHERE grupo = '999'" ); // phpcs:ignore WordPress.DB
$ok( is_wp_error( $r ) && $lotes_antes === $lotes_despues, 'una importación que falla a mitad se deshace entera (sin lote a medias) y avisa con un error' );

$crudo = $wpdb->get_row( $wpdb->prepare( 'SELECT concepto_enc, ordenante_enc, ordenante_hmac FROM ' . BancoTable::table() . " WHERE grupo = '999' AND importe = %f", 70 ), ARRAY_A ); // phpcs:ignore WordPress.DB
$ok( $crudo && str_starts_with( $crudo['concepto_enc'], 'v1:' ) && ! str_contains( $crudo['concepto_enc'] . $crudo['ordenante_enc'], 'CUOTA' ) && 64 === strlen( $crudo['ordenante_hmac'] ), 'en la base de datos el concepto y el ordenante están cifrados' );

$ok( 'F999001' === Conciliacion::referencia( 'CUOTA F-999-001 LUCAS', '999' ) && null === Conciliacion::referencia( 'REF999001', '999' ) && null === Conciliacion::referencia( 'F684001', '999' ), 'referencia de familia en el concepto (y no la de otro grupo)' );
$pend     = BancoTable::movimientos( array( 'estado' => BancoTable::PENDIENTE ) );
$por_num  = array( 'F999001' => $fam );
$m70      = current( array_filter( $pend, static fn( $m ) => 70.0 === $m['importe'] ) );
$m35      = current( array_filter( $pend, static fn( $m ) => 35.0 === $m['importe'] ) );
$cargo    = current( array_filter( $pend, static fn( $m ) => $m['importe'] < 0 ) );
$s        = $m70 ? Conciliacion::sugerencia( $m70, $por_num ) : null;
$ok( 4 === count( $pend ) && $s && $s['fiable'] && $fam_id === $s['familia']['id'], 'sugerencia por la referencia F999001 del concepto' );
$pago = $m70 ? Conciliacion::conciliar( (int) $m70['id'], $fam_id ) : 0;
$mp   = is_int( $pago ) ? MovimientosTable::obtener( $pago ) : null;
$ok( $mp && 'banco' === $mp['origen'] && 70.0 === (float) $mp['importe'] && '2026-2027' === $mp['ronda'] && '2026-09-01' === $mp['fecha'], 'al conciliar se registra el pago en la familia (origen banco, ronda 2026-2027)' );
$ok( BancoTable::CONCILIADO === BancoTable::obtener( (int) $m70['id'] )['estado'] && is_wp_error( Conciliacion::conciliar( (int) $m70['id'], $fam_id ) ), 'el movimiento queda conciliado y no se puede conciliar dos veces' );
$s = $m35 ? Conciliacion::sugerencia( BancoTable::obtener( (int) $m35['id'] ), $por_num ) : null;
$ok( $s && ! $s['fiable'] && $fam_id === $s['familia']['id'], 'otro ingreso del mismo ordenante, sin referencia → sugerido por el ordenante' );
$ok( is_wp_error( Conciliacion::conciliar( (int) $cargo['id'], $fam_id ) ), 'un gasto no se concilia con una familia' );
$ok( true === Conciliacion::deshacer( (int) $m70['id'] ) && null !== MovimientosTable::obtener( $pago )['anulado_en'] && BancoTable::PENDIENTE === BancoTable::obtener( (int) $m70['id'] )['estado'], 'deshacer: el pago se anula y el movimiento vuelve a pendiente' );
$tipo_gasto = \AsdeBalears\AreaPrivada\Tesoreria\Banco\TiposMovimiento::crear( 'Comisiones de prueba', 'gasto' );
$ok( true === Conciliacion::ignorar( (int) $cargo['id'], (int) $tipo_gasto ) && BancoTable::IGNORADO === BancoTable::obtener( (int) $cargo['id'] )['estado'], '«revisado»: un movimiento que no es cuota (con su tipo de gasto, v0.20)' );
$ok( 3 === count( BancoTable::movimientos( array( 'estado' => BancoTable::PENDIENTE ) ) ) && 1 === count( BancoTable::movimientos( array( 'q' => 'segundo plazo' ) ) ), 'filtros por estado y búsqueda en el concepto (descifrado)' );
Grupo::forzar( $real );
$ok( ! array_filter( BancoTable::movimientos(), static fn( $m ) => '999' === $m['grupo'] ), 'el grupo 684 no ve los movimientos del grupo 999' );
Grupo::forzar( null );

$lista = wp_list_pages(
	array(
		'title_li' => '',
		'depth'    => -1,
		'echo'     => 0,
	)
);
$ok( ! str_contains( $lista, 'href="' . Pages::url( 'banco' ) . '"' ) && ! str_contains( $lista, 'href="' . Pages::url( 'emails' ) . '"' ), 'las páginas nuevas (Banco, Registro de emails) tampoco salen en el pie' );

// ---------------------------------------------------------------------------
// Limpieza.
foreach ( $uids as $id ) {
	wp_delete_user( $id );
}
$wpdb->query( 'DELETE FROM ' . RegistroEmails::table() . ' WHERE user_id IN (' . implode( ',', array_map( 'intval', $uids ?: array( 0 ) ) ) . ')' ); // phpcs:ignore WordPress.DB
$limpiar_999();
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_asde\\_ap\\_int\\_%' OR option_name LIKE '\\_transient\\_timeout\\_asde\\_ap\\_int\\_%'" ); // phpcs:ignore WordPress.DB
$de_la_prueba = array_values( array_diff( $mailpit_ids(), $mailpit_antes ) );
if ( $de_la_prueba ) {
	wp_remote_request(
		'http://localhost:10006/api/v1/messages',
		array(
			'method'  => 'DELETE',
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode( array( 'IDs' => $de_la_prueba ) ),
		)
	);
}
unset( $_SERVER['REMOTE_ADDR'] );

echo $fails ? "\n$fails FALLOS\n" : "\nTODO OK (grupos, emails, usuarios y movimientos de prueba borrados)\n";
