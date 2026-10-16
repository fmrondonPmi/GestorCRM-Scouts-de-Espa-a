<?php
/**
 * Pruebas de la v0.39 (entorno LOCAL): los avisos de los eventos son configurables por grupo
 * (qué avisos se mandan, aviso push y días de la oferta de plaza). Ejecutar desde app/public:
 *
 *   wp eval-file wp-content/plugins/asde-area-privada/tests/eventos-avisos-config.php
 *
 * No toca el CRM ni envía emails; restaura la configuración del grupo 999 al terminar.
 */
defined( 'ABSPATH' ) || exit;

use AsdeBalears\AreaPrivada\Domain\Grupo;
use AsdeBalears\AreaPrivada\Eventos\Avisos;
use AsdeBalears\AreaPrivada\Eventos\Config;
use AsdeBalears\AreaPrivada\Eventos\Inscripciones;

$grupo   = '999';
$opcion  = 'asde_ap_eventos_' . $grupo;
$antes   = get_option( $opcion, null );
$fallos  = 0;
$ok      = static function ( bool $cond, string $msg ) use ( &$fallos ): void {
	echo ( $cond ? 'OK   ' : 'FAIL ' ) . ' ' . $msg . "\n";
	if ( ! $cond ) {
		++$fallos;
	}
};

try {
	delete_option( $opcion );
	$cfg = Config::de( $grupo );
	$ok( array_keys( $cfg['avisos'] ) === Avisos::TIPOS && ! in_array( false, $cfg['avisos'], true ), 'por defecto están activados todos los avisos' );
	$ok( true === $cfg['push'] && 3 === $cfg['dias_oferta'], 'por defecto: push activado y 3 días de oferta (como antes de la v0.39)' );
	$ok( Config::AVISOS === Avisos::TIPOS, 'la lista de avisos configurables es la de los avisos que existen' );

	$cfg['avisos']['recordatorio'] = false;
	$cfg['avisos']['plaza']        = false;
	$cfg['push']                   = false;
	$cfg['dias_oferta']            = 7;
	Config::guardar( $cfg, $grupo );
	$g = Config::de( $grupo );
	$ok( ! Config::aviso_activo( 'recordatorio', $grupo ) && ! Config::aviso_activo( 'plaza', $grupo ), 'los avisos desactivados se guardan' );
	$ok( Config::aviso_activo( 'publicado', $grupo ) && Config::aviso_activo( 'confirmada', $grupo ) && Config::aviso_activo( 'cancelado', $grupo ), 'los demás siguen activos' );
	$ok( false === $g['push'] && 7 === $g['dias_oferta'], 'se guardan el push y los días de la oferta' );

	// Valores fuera de rango o configuración antigua sin estas claves.
	$g['dias_oferta'] = 500;
	Config::guardar( $g, $grupo );
	$ok( Config::MAX_DIAS_OFERTA === Config::de( $grupo )['dias_oferta'], 'los días de la oferta no pasan del máximo' );
	$g['dias_oferta'] = 0;
	Config::guardar( $g, $grupo );
	$ok( 1 === Config::de( $grupo )['dias_oferta'], 'los días de la oferta son 1 como mínimo' );
	update_option( $opcion, array( 'recordatorio_dias' => 5 ), false );
	$viejo = Config::de( $grupo );
	$ok( 5 === $viejo['recordatorio_dias'] && Config::aviso_activo( 'cancelado', $grupo ) && 3 === $viejo['dias_oferta'], 'una configuración anterior a la v0.39 conserva sus valores y activa todo' );
	$ok( Config::DIAS_OFERTA === Inscripciones::DIAS_OFERTA, 'la constante de Inscripciones es el valor por defecto de Config' );
	// Fase 2: segundo recordatorio, edad de la cuenta propia y textos propios.
	delete_option( $opcion );
	$d = Config::de( $grupo );
	$ok( 0 === $d['recordatorio_extra'] && 14 === $d['edad_cuenta_propia'] && Inscripciones::EDAD_PREINSCRIPCION === 14, 'por defecto: sin segundo recordatorio y 14 años (como antes)' );
	$ok( '' === Config::texto_aviso( 'plaza', 'intro', $grupo ) && '' === Config::texto_aviso( 'cancelado', 'pie', $grupo ), 'por defecto no hay textos propios' );
	$d['recordatorio_dias']  = 5;
	$d['recordatorio_extra'] = 1;
	$d['edad_cuenta_propia'] = 16;
	$d['textos']['recordatorio']['intro'] = '  Traed la mochila.  ';
	$d['textos']['plaza']['pie']          = str_repeat( 'x', 900 );
	Config::guardar( $d, $grupo );
	$g = Config::de( $grupo );
	$ok( 1 === $g['recordatorio_extra'] && 16 === $g['edad_cuenta_propia'], 'se guardan el segundo recordatorio y la edad' );
	$ok( 'Traed la mochila.' === Config::texto_aviso( 'recordatorio', 'intro', $grupo ), 'el texto propio se guarda sin espacios sobrantes' );
	$ok( Config::MAX_TEXTO_AVISO === mb_strlen( Config::texto_aviso( 'plaza', 'pie', $grupo ) ), 'el texto propio no pasa del máximo' );
	$g['recordatorio_extra'] = 9; // Mayor que el primero (5): no vale.
	Config::guardar( $g, $grupo );
	$ok( 0 === Config::de( $grupo )['recordatorio_extra'], 'el segundo recordatorio tiene que ir después del primero' );
	$g['edad_cuenta_propia'] = 3;
	Config::guardar( $g, $grupo );
	$ok( Config::EDAD_MIN === Config::de( $grupo )['edad_cuenta_propia'], 'la edad no baja del mínimo' );

	// El email lleva los textos propios, escapados.
	// (el email lee la configuración del grupo actual: se usa temporalmente la de este.)
	$actual   = Grupo::numero();
	$opc_act  = 'asde_ap_eventos_' . $actual;
	$antes_ac = get_option( $opc_act, null );
	$g['textos']['recordatorio']['pie'] = '<b>Hasta pronto</b>';
	Config::guardar( $g, $actual );
	$html = Avisos::html( array( 'tipo' => 'recordatorio', 'grupo' => 'G', 'evento' => 'Acampada' ), 'Ana', 'Leo' );
	$ok( str_contains( $html, 'Traed la mochila.' ) && str_contains( $html, '&lt;b&gt;Hasta pronto&lt;/b&gt;' ), 'el email lleva el texto del grupo, escapado' );
	$ok( ! str_contains( Avisos::html( array( 'tipo' => 'cancelado' ), 'Ana' ), 'Traed la mochila.' ), 'el texto de un aviso no sale en otro' );
} finally {
	if ( isset( $opc_act ) ) {
		null === $antes_ac ? delete_option( $opc_act ) : update_option( $opc_act, $antes_ac, false );
	}
	if ( null === $antes ) {
		delete_option( $opcion );
	} else {
		update_option( $opcion, $antes, false );
	}
}

echo $fallos ? "\n$fallos FALLO(S)\n" : "\nTodo correcto.\n";
