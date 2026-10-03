<?php
namespace AsdeBalears\AreaPrivada\Usuarios;

use AsdeBalears\AreaPrivada\Crm\CrmException;
use AsdeBalears\AreaPrivada\Crm\PersonaRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Datos de contacto de un usuario. Los nombres se leen del CRM en el momento de
 * usarlos (WordPress no los guarda); el email de la cuenta se guarda cifrado
 * (EmailCuenta) y, si viene de un campo del CRM, se mantiene al día con él.
 */
final class Contacto {

	/**
	 * Email al que escribir a la cuenta:
	 * - con email de origen en el CRM, el de ese campo (y si ha cambiado, se guarda el nuevo);
	 * - si no, o si el CRM no responde o el campo está vacío, el guardado en la web;
	 * - usuarios previos (p. ej. administradores): su email de WordPress.
	 */
	public static function email( \WP_User $user, ?PersonaRepository $repo = null ): ?string {
		$guardado = EmailCuenta::leer( $user->ID );
		$origen   = Usuarios::email_origen( $user->ID );
		$repo     = $origen ? ( $repo ?: PersonaRepository::make() ) : null;
		if ( $origen && $repo ) {
			try {
				$p   = $repo->obtener( $origen['asde'] );
				$crm = $p ? ( $p->emails_por_campo()[ $origen['campo'] ] ?? null ) : null;
				if ( $crm ) {
					if ( $crm !== $guardado ) {
						EmailCuenta::guardar( $user->ID, $crm ); // El CRM manda: se sigue su cambio.
					}
					return $crm;
				}
			} catch ( CrmException $e ) {
				// CRM caído: vale el email guardado.
			}
		}
		if ( null !== $guardado ) {
			return $guardado;
		}
		return is_email( $user->user_email ) ? $user->user_email : null;
	}

	/** Nombre para saludar: el de la persona o "familia de …" en cuentas familiares. */
	public static function saludo( \WP_User $user, ?PersonaRepository $repo = null ): string {
		$repo = $repo ?: PersonaRepository::make();
		if ( ! $repo ) {
			return '';
		}
		try {
			$socio = Usuarios::socio( $user->ID );
			if ( $socio && ( $p = $repo->obtener( $socio ) ) ) {
				return $p->nombre;
			}
			$nombres = array_map( static fn( $p ) => $p->nombre, $repo->obtener_varias( Usuarios::representados( $user->ID ) ) );
			if ( $nombres ) {
				return sprintf( asde_t( 'familia de %s' ), self::lista( $nombres ) );
			}
		} catch ( CrmException $e ) {
			return '';
		}
		return '';
	}

	/** "A", "A y B", "A, B y C" (o "A, B o C" con $y = 'o'). */
	public static function lista( array $items, string $y = 'y' ): string {
		$items = array_values( array_filter( $items ) );
		if ( count( $items ) < 2 ) {
			return (string) ( $items[0] ?? '' );
		}
		$ultimo = array_pop( $items );
		return implode( ', ', $items ) . ' ' . $y . ' ' . $ultimo;
	}

	/** Email enmascarado para mostrar sin revelarlo entero: ma***@gm***.com */
	public static function enmascarar( string $email ): string {
		if ( ! str_contains( $email, '@' ) ) {
			return '';
		}
		list( $local, $dominio ) = explode( '@', $email, 2 );
		$partes                  = explode( '.', $dominio );
		$tld                     = count( $partes ) > 1 ? '.' . array_pop( $partes ) : '';
		$dom                     = implode( '.', $partes );
		return mb_substr( $local, 0, 2 ) . '***@' . mb_substr( $dom, 0, 2 ) . '***' . $tld;
	}
}
