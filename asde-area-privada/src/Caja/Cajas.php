<?php
namespace AsdeBalears\AreaPrivada\Caja;

use AsdeBalears\AreaPrivada\Auth\Ambito;
use AsdeBalears\AreaPrivada\Domain\Modulos;
use AsdeBalears\AreaPrivada\Domain\Secciones;

defined( 'ABSPATH' ) || exit;

/**
 * Las cajas de efectivo del grupo (v0.59, doc 61): la del grupo y una por sección educativa, todas desde el
 * principio (P-220), y quién ve y lleva cada una (P-210c).
 *
 * - `asde_caja`: ver y llevar la caja de sus secciones (los scouters).
 * - `asde_caja_todas`: todas las cajas y el libro mayor (Tesorería, Secretaría y Coordinación).
 * Las dos se quitan si el módulo «Caja y libro mayor» está apagado en el grupo (nace apagado, regla 5).
 */
final class Cajas {

	public const GRUPO = 'grupo';

	public const CAP       = 'asde_caja';
	public const CAP_TODAS = 'asde_caja_todas';

	/** @return string[] La del grupo y la de cada sección educativa, en ese orden. */
	public static function todas(): array {
		return array_merge( array( self::GRUPO ), Secciones::EDUCATIVAS );
	}

	public static function existe( string $caja ): bool {
		return in_array( $caja, self::todas(), true );
	}

	public static function nombre( string $caja ): string {
		if ( self::GRUPO === $caja ) {
			return asde_t( 'Caja del grupo' );
		}
		if ( CajaTable::BANCO === $caja ) {
			return asde_t( 'Banco' );
		}
		/* translators: %s: nombre de la sección */
		return sprintf( asde_t( 'Caja de %s' ), Secciones::nombre( $caja ) );
	}

	public static function activo(): bool {
		return Modulos::activo( Modulos::CAJA );
	}

	/** ¿Ve y lleva todas las cajas (y el libro mayor)? */
	public static function todas_suyas(): bool {
		return self::activo() && current_user_can( self::CAP_TODAS );
	}

	/**
	 * Las cajas que ve (y lleva) quien está conectado: todas con `asde_caja_todas`; con `asde_caja`, las de sus
	 * secciones educativas.
	 *
	 * @return string[]
	 */
	public static function visibles( ?Ambito $ambito = null ): array {
		if ( ! self::activo() ) {
			return array();
		}
		if ( current_user_can( self::CAP_TODAS ) ) {
			return self::todas();
		}
		if ( ! current_user_can( self::CAP ) ) {
			return array();
		}
		$ambito = $ambito ?: Ambito::actual();
		return array_values( array_intersect( Secciones::EDUCATIVAS, $ambito->secciones_scouter() ) );
	}

	public static function puede( string $caja ): bool {
		return in_array( $caja, self::visibles(), true );
	}
}
