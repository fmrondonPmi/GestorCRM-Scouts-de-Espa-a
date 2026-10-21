<?php
namespace AsdeBalears\AreaPrivada\Domain;

use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Auth\Ambito;
use AsdeBalears\AreaPrivada\Crm\ClientFactory;
use AsdeBalears\AreaPrivada\Crm\ConnectionResult;
use AsdeBalears\AreaPrivada\Crm\CrmException;
use AsdeBalears\AreaPrivada\Crm\Crypto;
use AsdeBalears\AreaPrivada\Crm\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Credencial del CRM de la oficina federal (v0.50, doc 50 fase 2). Hermana de `Grupos`, pero única:
 * la oficina lee con ella los datos de cualquier grupo (siempre uno cada vez y filtrando por su
 * grupo de seguridad). La contraseña se guarda cifrada y solo la lee `ClientFactory`.
 */
final class Oficina {

	public const OPTION = 'asde_ap_oficina';

	/** Las únicas pantallas del área privada que abre la oficina (fase 4). El resto, aunque tuviera la capability, no. */
	public const PAGINAS = array( 'area', 'personas', 'ficha', 'informes', 'usuarios', 'seguridad' );

	/** @return array{usuario:string, clave_enc:string, estado:string, mensaje:string, probado:int, visibles:int} */
	private static function raw(): array {
		$v = get_option( self::OPTION, array() );
		$v = is_array( $v ) ? $v : array();
		return array(
			'usuario'   => (string) ( $v['usuario'] ?? '' ),
			'clave_enc' => (string) ( $v['clave_enc'] ?? '' ),
			'estado'    => (string) ( $v['estado'] ?? 'sin_probar' ),
			'mensaje'   => (string) ( $v['mensaje'] ?? '' ),
			'probado'   => (int) ( $v['probado'] ?? 0 ),
			'visibles'  => (int) ( $v['visibles'] ?? 0 ),
		);
	}

	/**
	 * Lo que se puede enseñar: nunca la contraseña.
	 *
	 * @return array{usuario:string, tiene_clave:bool, estado:string, mensaje:string, probado:int, visibles:int}
	 */
	public static function datos(): array {
		$r = self::raw();
		return array(
			'usuario'     => $r['usuario'],
			'tiene_clave' => '' !== $r['clave_enc'],
			'estado'      => $r['estado'],
			'mensaje'     => $r['mensaje'],
			'probado'     => $r['probado'],
			'visibles'    => $r['visibles'],
		);
	}

	/**
	 * Solo para `ClientFactory`.
	 *
	 * @return array{usuario:string, clave:string}|null
	 */
	public static function credenciales(): ?array {
		$r = self::raw();
		if ( '' === $r['usuario'] || '' === $r['clave_enc'] ) {
			return null;
		}
		$clave = Crypto::decrypt( $r['clave_enc'] );
		return null === $clave ? null : array(
			'usuario' => $r['usuario'],
			'clave'   => $clave,
		);
	}

	public static function tiene_credencial(): bool {
		return null !== self::credenciales();
	}

	/** Guarda el usuario y, si se escribe, la contraseña (vacía = conservar la guardada). Cambiar algo invalida la última prueba. */
	public static function guardar( string $usuario, ?string $clave ): bool {
		$usuario = trim( $usuario );
		if ( '' === $usuario || mb_strlen( $usuario ) > 120 ) {
			return false;
		}
		$r = self::raw();
		if ( null !== $clave && '' !== $clave ) {
			$r['clave_enc'] = Crypto::encrypt( $clave );
		}
		if ( '' === $r['clave_enc'] ) {
			return false;
		}
		$r['usuario'] = $usuario;
		$r['estado']  = 'sin_probar';
		$r['mensaje'] = '';
		$r['probado'] = 0;
		update_option( self::OPTION, $r, false );
		ClientFactory::olvidar();
		return true;
	}

	/**
	 * La oficina elige el grupo que consulta (fase 3). Solo quien tiene la capability multigrupo, en una web de
	 * oficina y entre los grupos dados de alta: esa lista es la frontera (`Grupo::actual()` la vuelve a validar).
	 */
	public static function cambiar_grupo( int $user_id, string $numero ): bool {
		$user  = get_userdata( $user_id );
		$grupo = Grupos::obtener( $numero );
		if ( ! $user || ! $grupo || ! Organizacion::es_oficina() || ! $user->has_cap( 'asde_multigrupo' ) ) {
			return false;
		}
		update_user_meta( $user_id, Grupo::META_CONTEXTO, $grupo['numero'] );
		ClientFactory::olvidar();
		Ambito::olvidar( $user_id );
		Logger::log( 'oficina_cambio_grupo', 'grupo', $grupo['numero'] );
		return true;
	}

	/** Borra la credencial (volver a empezar). */
	public static function olvidar_credencial(): void {
		delete_option( self::OPTION );
		ClientFactory::olvidar();
	}

	/**
	 * Prueba la conexión: entra con la credencial y cuenta los Grupos Scouts que ve. Es solo lectura.
	 * Que la credencial sea de solo lectura en el CRM no se puede comprobar sin escribir: lo confirma la oficina.
	 */
	public static function probar(): ConnectionResult {
		$client   = ClientFactory::para_oficina();
		$visibles = 0;
		if ( ! $client ) {
			$res = ConnectionResult::fail( '' === Settings::url() ? asde_t( 'Falta la URL del CRM.' ) : asde_t( 'Faltan el usuario o la contraseña del CRM de la oficina.' ) );
		} else {
			$res = $client->ping();
			if ( $res->ok ) {
				try {
					$filas           = $client->list_entries( 'SecurityGroups', '', array( 'id', 'name', 'numgrupo_c', 'tipoasociacion_c' ), array(), 0, 500 )['entries'];
					$visibles        = count( array_filter( $filas, static fn( $f ) => 'GS' === ( $f['fields']['tipoasociacion_c'] ?? '' ) ) );
					$res->message    = sprintf( asde_t( 'Conexión correcta. La credencial de la oficina ve %d Grupos Scouts.' ), $visibles );
					$res->warnings[] = asde_t( 'La web no puede comprobar que esta credencial sea de solo lectura: confírmalo con la oficina en el CRM.' );
				} catch ( CrmException $e ) {
					$res = ConnectionResult::fail( asde_t( 'Conexión correcta, pero no se han podido leer los grupos del CRM: ' ) . $e->getMessage(), $res->details );
				}
			}
		}
		$r             = self::raw();
		$r['estado']   = $res->ok ? 'ok' : 'error';
		$r['mensaje']  = $res->message;
		$r['probado']  = time();
		$r['visibles'] = $visibles;
		update_option( self::OPTION, $r, false );
		return $res;
	}
}
