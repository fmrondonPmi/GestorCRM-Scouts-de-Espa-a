<?php
namespace AsdeBalears\AreaPrivada\Sepa;

use AsdeBalears\AreaPrivada\Crm\Crypto;
use AsdeBalears\AreaPrivada\Crm\CrmException;
use AsdeBalears\AreaPrivada\Crm\Escritura;
use AsdeBalears\AreaPrivada\Crm\PersonaRepository;

defined( 'ABSPATH' ) || exit;

/**
 * La cuenta bancaria de la domiciliación, en la ficha del CRM (v0.33, doc 25 §9; decisión del
 * usuario, P-131): IBAN, titular y entidad se guardan en los campos de ASDE de la ficha de
 * **cada** miembro de la familia (`iban_c`, `iban_titular_c`, `iban_entidad_c`) y la web no
 * los guarda. De la autorización la web conserva la referencia, quién firmó y cuándo, el
 * IBAN enmascarado (para enseñarlo) y una huella (HMAC) del IBAN autorizado, para comprobar,
 * antes de cobrar, que la cuenta del CRM sigue siendo la autorizada.
 *
 * Aunque la cuenta esté repetida en varias fichas, la familia se cobra una sola vez: la remesa
 * va por autorización (una por familia) y toma la cuenta de la primera ficha que coincide.
 * Se lee siempre en vivo (no entra en la instantánea de fichas).
 */
final class CuentaCrm {

	public const IBAN    = 'iban_c';
	public const TITULAR = 'iban_titular_c';
	public const ENTIDAD = 'iban_entidad_c';

	/** Longitud del campo `iban_c` del CRM de ASDE (cabe un IBAN español, 24 caracteres). */
	public const MAX_IBAN = 24;

	/** Huella del IBAN autorizado (la misma que guardaba la v0.12 en la autorización). */
	public static function huella( string $iban ): string {
		return Crypto::hmac( 'sepa|iban|' . Iban::normalizar( $iban ) );
	}

	/**
	 * La cuenta de cada ficha (en vivo).
	 *
	 * @param string[] $socios Nº Scouts de España
	 * @return array<string, array{iban:string, titular:string, entidad:string}> socio => cuenta (solo las fichas con IBAN)
	 * @throws CrmException Si el CRM no responde.
	 */
	public static function leer( PersonaRepository $repo, array $socios ): array {
		$out = array();
		foreach ( $repo->valores_varias( $socios, array( self::IBAN, self::TITULAR, self::ENTIDAD ) ) as $socio => $v ) {
			$iban = Iban::normalizar( (string) $v[ self::IBAN ] );
			if ( '' !== $iban ) {
				$out[ (string) $socio ] = array(
					'iban'    => $iban,
					'titular' => trim( (string) $v[ self::TITULAR ] ),
					'entidad' => trim( (string) $v[ self::ENTIDAD ] ),
				);
			}
		}
		return $out;
	}

	/**
	 * La cuenta autorizada de una familia: la de la primera ficha de sus miembros cuyo IBAN es el
	 * de la autorización (por su huella). null si ninguna coincide (la han cambiado en el CRM o
	 * aún no está).
	 *
	 * @param array<string, array> $cuentas Salida de leer()
	 * @return array{socio:string, iban:string, titular:string, entidad:string}|null
	 */
	public static function de_familia( array $familia, string $huella, array $cuentas ): ?array {
		foreach ( (array) $familia['miembros'] as $socio ) {
			$c = $cuentas[ (string) $socio ] ?? null;
			if ( $c && '' !== $huella && hash_equals( $huella, self::huella( $c['iban'] ) ) ) {
				return array( 'socio' => (string) $socio ) + $c;
			}
		}
		return null;
	}

	/**
	 * Guarda la cuenta en la ficha de cada miembro de la familia (P-131: repetida en cada ficha).
	 *
	 * @param string[]                                          $socios
	 * @param array{iban:string, titular:string, entidad?:string} $datos
	 * @return true|\WP_Error
	 */
	public static function guardar( PersonaRepository $repo, array $socios, array $datos ) {
		if ( ! Escritura::disponible() ) {
			return new \WP_Error( 'asde_sepa_crm', asde_t( 'La cuenta se guarda en la ficha del CRM y el usuario del CRM de tu grupo no tiene permiso para editar fichas. Avisa a la Tesorería del grupo.' ) );
		}
		$valores = array(
			self::IBAN    => Iban::normalizar( $datos['iban'] ),
			self::TITULAR => (string) $datos['titular'],
		);
		if ( '' !== (string) ( $datos['entidad'] ?? '' ) ) {
			$valores[ self::ENTIDAD ] = (string) $datos['entidad'];
		}
		$personas = $repo->obtener_varias( $socios );
		if ( ! $personas ) {
			return new \WP_Error( 'asde_sepa_crm', asde_t( 'No se encuentran en el CRM las fichas de la familia.' ) );
		}
		foreach ( $personas as $p ) {
			$r = Escritura::guardar( $p, $valores, 'Autorización de domiciliación SEPA' );
			if ( is_wp_error( $r ) ) {
				return $r;
			}
			if ( $r['rechazados'] ) {
				return new \WP_Error( 'asde_sepa_crm', asde_t( 'El CRM no ha guardado la cuenta en la ficha. Inténtalo más tarde o avisa a la Tesorería del grupo.' ) );
			}
		}
		return true;
	}
}
