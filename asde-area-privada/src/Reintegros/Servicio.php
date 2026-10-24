<?php
namespace AsdeBalears\AreaPrivada\Reintegros;

use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Crm\Crypto;
use AsdeBalears\AreaPrivada\Domain\Modulos;
use AsdeBalears\AreaPrivada\Eventos\Cuentas;
use AsdeBalears\AreaPrivada\Eventos\EventosTable;
use AsdeBalears\AreaPrivada\Eventos\GastosTable;
use AsdeBalears\AreaPrivada\Eventos\Justificantes;
use AsdeBalears\AreaPrivada\Sepa\Iban;

defined( 'ABSPATH' ) || exit;

/**
 * Reintegros de gastos (v0.47, doc 53): pedir, aprobar, rechazar, cancelar y marcar pagado.
 *
 * - Pide el reintegro un scouter o Coordinación (`asde_reintegros`), con el justificante, y puede asociarlo a un evento.
 * - Lo aprueba Tesorería (`asde_reintegros_aprobar`; nadie aprueba el suyo) y lo marca pagado Tesorería o
 *   Coordinación (`asde_reintegros_pagar`).
 * - Con evento, al pagarlo se crea el gasto del evento (validado y ya reembolsado), con el mismo justificante.
 * - El IBAN (cobro por transferencia) va cifrado y se borra al terminar.
 */
final class Servicio {

	public const CAP_PEDIR    = 'asde_reintegros';
	public const CAP_APROBAR  = 'asde_reintegros_aprobar';
	public const CAP_PAGAR    = 'asde_reintegros_pagar';

	/** Un gasto de hace más de un año ya no se reintegra. */
	private const DIAS_MAX = 366;

	/** ¿Ve todos los reintegros del grupo? Quien aprueba o paga. */
	public static function ve_todos(): bool {
		return current_user_can( self::CAP_APROBAR ) || current_user_can( self::CAP_PAGAR );
	}

	public static function puede_ver( array $r ): bool {
		return self::ve_todos() || ( $r['solicitante'] === get_current_user_id() && current_user_can( self::CAP_PEDIR ) );
	}

	public static function puede_aprobar( array $r ): bool {
		return current_user_can( self::CAP_APROBAR ) && ReintegrosTable::SOLICITADO === $r['estado'] && $r['solicitante'] !== get_current_user_id();
	}

	public static function puede_pagar( array $r ): bool {
		return current_user_can( self::CAP_PAGAR ) && ReintegrosTable::APROBADO === $r['estado'];
	}

	public static function puede_cancelar( array $r ): bool {
		return ReintegrosTable::SOLICITADO === $r['estado'] && $r['solicitante'] === get_current_user_id() && current_user_can( self::CAP_PEDIR );
	}

	/** Lo que espera a esta persona (para el menú): por aprobar, si aprueba; por pagar, si paga. */
	public static function pendientes(): int {
		$n = 0;
		if ( current_user_can( self::CAP_APROBAR ) ) {
			$n += ReintegrosTable::contar( ReintegrosTable::SOLICITADO );
		}
		if ( current_user_can( self::CAP_PAGAR ) ) {
			$n += ReintegrosTable::contar( ReintegrosTable::APROBADO );
		}
		return $n;
	}

	/**
	 * Eventos a los que se puede asociar un reintegro: los del grupo con las cuentas abiertas (solo con el módulo Eventos).
	 *
	 * @return array<int, string> id => «Nombre · fecha»
	 */
	public static function eventos(): array {
		if ( ! Modulos::activo( Modulos::EVENTOS ) ) {
			return array();
		}
		$out = array();
		foreach ( EventosTable::todos() as $e ) {
			if ( Cuentas::abiertas( $e ) ) {
				$out[ (int) $e['id'] ] = (string) $e['nombre'] . ' · ' . wp_date( 'd/m/Y', strtotime( $e['inicio'] . ' 12:00' ) );
			}
		}
		return $out;
	}

	/**
	 * Un reintegro nuevo.
	 *
	 * @param array<string, mixed>                  $in      Lo escrito en el formulario.
	 * @param array{ruta:string, nombre:string}|null $fichero El justificante subido (ya comprobado con is_uploaded_file()).
	 * @return int|\WP_Error Id del reintegro.
	 */
	public static function solicitar( array $in, ?array $fichero ) {
		if ( ! current_user_can( self::CAP_PEDIR ) || ! Modulos::activo( Modulos::REINTEGROS ) ) {
			return new \WP_Error( 'asde_reintegro', asde_t( 'No tienes permiso para pedir reintegros.' ) );
		}
		$concepto = mb_substr( trim( sanitize_text_field( (string) ( $in['concepto'] ?? '' ) ) ), 0, 120 );
		$fecha    = (string) ( $in['fecha'] ?? '' );
		$importe  = (float) str_replace( ',', '.', (string) ( $in['importe'] ?? '' ) );
		$forma    = sanitize_key( (string) ( $in['forma'] ?? '' ) );
		$evento   = (int) ( $in['evento'] ?? 0 );
		if ( '' === $concepto ) {
			return new \WP_Error( 'asde_reintegro', asde_t( 'Escribe el concepto: qué pagaste.' ) );
		}
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $fecha ) || ! date_create( $fecha ) || $fecha > current_time( 'Y-m-d' ) || strtotime( $fecha ) < time() - self::DIAS_MAX * DAY_IN_SECONDS ) {
			return new \WP_Error( 'asde_reintegro', asde_t( 'La fecha del gasto no puede ser futura ni de hace más de un año.' ) );
		}
		if ( $importe <= 0 || $importe >= 100000 ) {
			return new \WP_Error( 'asde_reintegro', asde_t( 'Escribe un importe mayor que cero.' ) );
		}
		if ( ! isset( ReintegrosTable::FORMAS[ $forma ] ) ) {
			return new \WP_Error( 'asde_reintegro', asde_t( 'Elige cómo prefieres cobrarlo: en efectivo o por transferencia.' ) );
		}
		$iban = '';
		if ( ReintegrosTable::TRANSFERENCIA === $forma ) {
			$iban = Iban::normalizar( (string) ( $in['iban'] ?? '' ) );
			if ( ! Iban::es_valido( $iban ) ) {
				return new \WP_Error( 'asde_reintegro', asde_t( 'Para cobrarlo por transferencia escribe un IBAN válido.' ) );
			}
		}
		if ( $evento && ! isset( self::eventos()[ $evento ] ) ) {
			return new \WP_Error( 'asde_reintegro', asde_t( 'Ese evento no está disponible. Elige otro o déjalo sin evento.' ) );
		}
		if ( ! $fichero || '' === (string) $fichero['ruta'] ) {
			return new \WP_Error( 'asde_reintegro', asde_t( 'Adjunta el justificante: una foto del tique o la factura, o el PDF.' ) );
		}
		$j = Justificantes::guardar( (string) $fichero['ruta'], (string) $fichero['nombre'] );
		if ( is_wp_error( $j ) ) {
			return $j;
		}
		$id = ReintegrosTable::crear(
			$j + array(
				'concepto'  => $concepto,
				'fecha'     => $fecha,
				'importe'   => $importe,
				'evento_id' => $evento,
				'forma'     => $forma,
				'iban_enc'  => '' !== $iban ? Crypto::encrypt( $iban ) : '',
			)
		);
		if ( ! $id ) {
			return new \WP_Error( 'asde_reintegro', asde_t( 'No se ha podido guardar el reintegro. Inténtalo de nuevo.' ) );
		}
		Logger::log( 'reintegro_pedido', 'reintegro', (string) $id, array( 'forma' => $forma, 'evento' => (bool) $evento ) );
		$r = ReintegrosTable::obtener( $id );
		if ( $r ) {
			Avisos::a_tesoreria( $r );
		}
		return $id;
	}

	/** El IBAN descifrado, o '' (solo quien aprueba, paga o pidió el reintegro, y mientras no haya terminado). */
	public static function iban( array $r ): string {
		if ( '' === $r['iban_enc'] || ! self::puede_ver( $r ) ) {
			return '';
		}
		$v = Crypto::decrypt( $r['iban_enc'] );
		return is_string( $v ) ? Iban::formatear( $v ) : '';
	}

	/** @return true|\WP_Error */
	public static function aprobar( array $r ) {
		if ( ! self::puede_aprobar( $r ) ) {
			return new \WP_Error( 'asde_reintegro', asde_t( 'No puedes aprobar este reintegro (lo aprueba Tesorería, y nadie aprueba el suyo).' ) );
		}
		if ( ! ReintegrosTable::cambiar( $r['id'], ReintegrosTable::SOLICITADO, ReintegrosTable::APROBADO, self::revision() ) ) {
			return new \WP_Error( 'asde_reintegro', asde_t( 'Ese reintegro ya se ha revisado.' ) );
		}
		Logger::log( 'reintegro_aprobado', 'reintegro', (string) $r['id'] );
		Avisos::a_solicitante( (array) ReintegrosTable::obtener( $r['id'] ), Avisos::APROBADO );
		return true;
	}

	/** @return true|\WP_Error */
	public static function rechazar( array $r, string $motivo ) {
		$motivo = mb_substr( trim( sanitize_text_field( $motivo ) ), 0, 255 );
		if ( ! current_user_can( self::CAP_APROBAR ) || ReintegrosTable::SOLICITADO !== $r['estado'] || $r['solicitante'] === get_current_user_id() ) {
			return new \WP_Error( 'asde_reintegro', asde_t( 'No puedes rechazar este reintegro.' ) );
		}
		if ( '' === $motivo ) {
			return new \WP_Error( 'asde_reintegro', asde_t( 'Indica el motivo del rechazo.' ) );
		}
		if ( ! ReintegrosTable::cambiar( $r['id'], ReintegrosTable::SOLICITADO, ReintegrosTable::RECHAZADO, self::revision() + array( 'motivo' => $motivo, 'iban_enc' => '' ) + Justificantes::SIN_JUSTIFICANTE ) ) {
			return new \WP_Error( 'asde_reintegro', asde_t( 'Ese reintegro ya se ha revisado.' ) );
		}
		$borrado = Justificantes::borrar_si_libre( (string) $r['justificante'] ); // El justificante se borra si nadie más lo usa (doc 59, RC-DEC-3).
		Logger::log( 'reintegro_rechazado', 'reintegro', (string) $r['id'], array( 'justificante_borrado' => $borrado ) );
		Avisos::a_solicitante( (array) ReintegrosTable::obtener( $r['id'] ), Avisos::RECHAZADO );
		return true;
	}

	/** @return true|\WP_Error */
	public static function cancelar( array $r ) {
		if ( ! self::puede_cancelar( $r ) ) {
			return new \WP_Error( 'asde_reintegro', asde_t( 'Ese reintegro ya no se puede cancelar: ya lo ha revisado Tesorería.' ) );
		}
		if ( ! ReintegrosTable::cambiar( $r['id'], ReintegrosTable::SOLICITADO, ReintegrosTable::CANCELADO, array( 'iban_enc' => '' ) + Justificantes::SIN_JUSTIFICANTE ) ) {
			return new \WP_Error( 'asde_reintegro', asde_t( 'Ese reintegro ya se ha revisado.' ) );
		}
		$borrado = Justificantes::borrar_si_libre( (string) $r['justificante'] ); // Doc 59, RC-DEC-3.
		Logger::log( 'reintegro_cancelado', 'reintegro', (string) $r['id'], array( 'justificante_borrado' => $borrado ) );
		return true;
	}

	/**
	 * Marca el reintegro como pagado (Tesorería o Coordinación). Borra el IBAN y, si tiene evento, añade el gasto a sus cuentas.
	 *
	 * @return array{gasto:bool, evento:bool}|\WP_Error gasto: si se añadió a las cuentas del evento; evento: si tenía evento.
	 */
	public static function pagar( array $r, string $fecha, string $ref ) {
		if ( ! self::puede_pagar( $r ) ) {
			return new \WP_Error( 'asde_reintegro', asde_t( 'No puedes marcar este reintegro como pagado.' ) );
		}
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $fecha ) || ! date_create( $fecha ) || $fecha > current_time( 'Y-m-d' ) ) {
			return new \WP_Error( 'asde_reintegro', asde_t( 'Indica la fecha del pago (no posterior a hoy).' ) );
		}
		$ref = mb_substr( trim( sanitize_text_field( $ref ) ), 0, 120 );
		if ( ! ReintegrosTable::cambiar(
			$r['id'],
			ReintegrosTable::APROBADO,
			ReintegrosTable::PAGADO,
			array(
				'pago_fecha' => $fecha,
				'pago_ref'   => $ref,
				'pagado_en'  => current_time( 'mysql', true ),
				'pagado_por' => get_current_user_id(),
				'iban_enc'   => '',
			)
		) ) {
			return new \WP_Error( 'asde_reintegro', asde_t( 'Ese reintegro ya se ha pagado o ya no está aprobado.' ) );
		}
		$gasto = self::gasto_del_evento( $r, $fecha );
		if ( $gasto ) {
			ReintegrosTable::actualizar( $r['id'], array( 'gasto_id' => $gasto ) );
		}
		Logger::log( 'reintegro_pagado', 'reintegro', (string) $r['id'], array( 'evento' => (bool) $r['evento_id'], 'gasto' => (bool) $gasto ) );
		Avisos::a_solicitante( (array) ReintegrosTable::obtener( $r['id'] ), Avisos::PAGADO );
		return array(
			'gasto'  => (bool) $gasto,
			'evento' => (bool) $r['evento_id'],
		);
	}

	/** El gasto del evento (validado, pagado de su bolsillo y ya reembolsado), con el mismo justificante; 0 si no hay evento o sus cuentas están cerradas. */
	private static function gasto_del_evento( array $r, string $fecha_pago ): int {
		if ( ! $r['evento_id'] || ! Modulos::activo( Modulos::EVENTOS ) ) {
			return 0;
		}
		$e = EventosTable::obtener( $r['evento_id'] );
		if ( ! $e || ! Cuentas::abiertas( $e ) ) {
			return 0;
		}
		return GastosTable::anadir(
			array(
				'evento_id'           => $e['id'],
				'tipo'                => GastosTable::GASTO,
				'fecha'               => $r['fecha'],
				'concepto'            => $r['concepto'],
				'proveedor'           => '',
				'importe'             => $r['importe'],
				'forma'               => 'otro',
				'pagado_por'          => GastosTable::PAGO_PERSONA,
				'persona'             => $r['solicitante'],
				'estado'              => GastosTable::VALIDADO,
				'revisado_en'         => current_time( 'mysql', true ),
				'revisado_por'        => get_current_user_id(),
				'reembolsado_en'      => $fecha_pago,
				'reembolso_forma'     => ReintegrosTable::TRANSFERENCIA === $r['forma'] ? 'transferencia' : 'efectivo',
				'reembolsado_por'     => get_current_user_id(),
				'justificante'        => $r['justificante'],
				'justificante_tipo'   => $r['justificante_tipo'],
				'justificante_nombre' => $r['justificante_nombre'],
			)
		);
	}

	/** @return array<string, mixed> */
	private static function revision(): array {
		return array(
			'revisado_en'  => current_time( 'mysql', true ),
			'revisado_por' => get_current_user_id(),
		);
	}

	/** Nombre de la persona que pidió el reintegro. */
	public static function nombre( int $user_id ): string {
		$u = $user_id ? get_userdata( $user_id ) : false;
		return $u ? (string) $u->display_name : '';
	}
}
