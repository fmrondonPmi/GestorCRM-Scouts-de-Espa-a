<?php
namespace AsdeBalears\AreaPrivada\Fichas;

use AsdeBalears\AreaPrivada\Crm\CrmException;
use AsdeBalears\AreaPrivada\Crm\Escritura;
use AsdeBalears\AreaPrivada\Crm\Metadatos;
use AsdeBalears\AreaPrivada\Crm\PersonaRepository;
use AsdeBalears\AreaPrivada\Domain\Persona;

defined( 'ABSPATH' ) || exit;

/**
 * Ficha de una persona: lee del CRM los campos que el usuario puede ver y
 * guarda lo que puede cambiar (directo al CRM o como propuesta).
 */
final class FichaService {

	public function __construct( private PersonaRepository $repo ) {}

	/**
	 * @return array<string, array{etiqueta:string, grupo:string, valor:string, meta:array, accion:string, motivo:string}>
	 * @throws CrmException
	 */
	public function cargar( Persona $p, PermisosFicha $perm ): array {
		$todos   = CamposFicha::todos();
		$campos  = array_values( array_filter( array_keys( $todos ), array( $perm, 've_campo' ) ) );
		$valores = $this->repo->valores( $p, $campos );
		$meta    = Metadatos::campos( Escritura::MODULO, array_keys( $todos ) );
		$out     = array();
		foreach ( $campos as $campo ) {
			$valor = (string) ( $valores[ $campo ] ?? '' );
			$m     = self::con_opciones( $campo, $meta[ $campo ] ?? array(), $valor );
			list( $accion, $motivo ) = $perm->accion( $campo, $m );
			$out[ $campo ]           = array(
				'etiqueta' => $todos[ $campo ][0],
				'grupo'    => $todos[ $campo ][1],
				'valor'    => $valor,
				'meta'     => $m,
				'accion'   => $accion,
				'motivo'   => $motivo,
			);
		}
		return $out;
	}

	/**
	 * Campos de texto libre en el CRM que en la ficha se eligen de una lista
	 * (CamposFicha::OPCIONES): pasan a desplegable, con la explicación de cada
	 * opción. Si el CRM ya tiene otro texto, se conserva como «valor actual».
	 */
	public static function con_opciones( string $campo, array $meta, string $valor ): array {
		$opciones = CamposFicha::OPCIONES[ $campo ] ?? null;
		if ( ! $opciones || ! $meta ) {
			return $meta;
		}
		$lista = array( '' => '—' );
		foreach ( array_keys( $opciones ) as $o ) {
			$lista[ $o ] = $o;
		}
		if ( '' !== $valor && ! isset( $lista[ $valor ] ) ) {
			$lista[ $valor ] = $valor . ' (valor actual)';
		}
		$meta['tipo']     = 'enum';
		$meta['opciones'] = $lista;
		$meta['ayudas']   = $opciones;
		return $meta;
	}

	/**
	 * Aplica lo enviado: lo que el usuario puede EDITAR va al CRM y lo que puede
	 * PROPONER, a la cola de cambios. Lo demás se ignora. Si un dato cambió en el
	 * CRM mientras se editaba (huella distinta), no se sobrescribe.
	 *
	 * @param array<string, string> $enviado campo => valor del formulario
	 * @param array<string, string> $huellas campo => huella del valor que se mostró
	 * @return array{guardados:string[], rechazados:string[], propuestos:string[], errores:string[], conflictos:string[]}
	 * @throws CrmException
	 */
	public function guardar( Persona $p, PermisosFicha $perm, array $enviado, array $huellas ): array {
		$ficha      = $this->cargar( $p, $perm ); // Valores actuales del CRM.
		$res        = array(
			'guardados'  => array(),
			'rechazados' => array(),
			'propuestos' => array(),
			'errores'    => array(),
			'conflictos' => array(),
		);
		$directos   = array();
		$propuestas = array();
		foreach ( $enviado as $campo => $valor ) {
			$f = $ficha[ $campo ] ?? null;
			if ( ! $f || PermisosFicha::VER === $f['accion'] ) {
				continue;
			}
			$v = self::normalizar( $campo, (string) $valor, $f['meta'] );
			if ( is_wp_error( $v ) ) {
				$res['errores'][] = $f['etiqueta'] . ': ' . $v->get_error_message();
				continue;
			}
			if ( Escritura::iguales( $v, $f['valor'] ) ) {
				continue;
			}
			if ( isset( $huellas[ $campo ] ) && ! hash_equals( (string) $huellas[ $campo ], self::huella( $f['valor'] ) ) ) {
				$res['conflictos'][] = $f['etiqueta'];
				continue;
			}
			if ( PermisosFicha::EDITAR === $f['accion'] ) {
				$directos[ $campo ] = $v;
			} else {
				$propuestas[ $campo ] = array( $f['valor'], $v );
			}
		}

		if ( $directos ) {
			$r = Escritura::guardar( $p, $directos, 'ficha' );
			if ( is_wp_error( $r ) ) {
				$res['errores'][] = $r->get_error_message();
			} else {
				$res['guardados']  = array_map( static fn( $c ) => $ficha[ $c ]['etiqueta'], $r['guardados'] );
				$res['rechazados'] = array_map( static fn( $c ) => $ficha[ $c ]['etiqueta'], $r['rechazados'] );
			}
		}
		if ( $propuestas ) {
			Cambios::proponer( $p, $propuestas );
			$res['propuestos'] = array_map( static fn( $c ) => $ficha[ $c ]['etiqueta'], array_keys( $propuestas ) );
		}
		return $res;
	}

	public static function huella( string $valor ): string {
		return md5( 'ficha|' . $valor );
	}

	/** Valida y normaliza un valor según el tipo del campo en el CRM. @return string|\WP_Error */
	public static function normalizar( string $campo, string $valor, array $meta ) {
		$tipo  = (string) ( $meta['tipo'] ?? 'varchar' );
		$valor = trim( $valor );
		if ( 'bool' === $tipo ) {
			return in_array( $valor, array( '1', 'on', 'true' ), true ) ? '1' : '0';
		}
		if ( '' === $valor ) {
			return ! empty( $meta['requerido'] ) ? new \WP_Error( 'asde_ficha', asde_t( 'es obligatorio.' ) ) : '';
		}
		switch ( $tipo ) {
			case 'date':
				if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $valor, $m ) || ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
					return new \WP_Error( 'asde_ficha', asde_t( 'fecha no válida.' ) );
				}
				return $valor;
			case 'enum':
			case 'dynamicenum':
			case 'radioenum':
				return array_key_exists( $valor, (array) ( $meta['opciones'] ?? array() ) ) ? $valor : new \WP_Error( 'asde_ficha', asde_t( 'opción no válida.' ) );
			case 'int':
				return preg_match( '/^-?\d+$/', $valor ) ? $valor : new \WP_Error( 'asde_ficha', asde_t( 'tiene que ser un número.' ) );
			case 'text':
				$v = sanitize_textarea_field( $valor );
				break;
			default:
				$v = sanitize_text_field( $valor );
		}
		if ( ( 'email1' === $campo || str_contains( $campo, 'email' ) ) && ! is_email( $v ) ) {
			return new \WP_Error( 'asde_ficha', asde_t( 'email no válido.' ) );
		}
		if ( 'phone' === $tipo || str_contains( $campo, 'movil' ) ) {
			if ( ! preg_match( '/^[0-9 +().\-]{6,20}$/', $v ) ) {
				return new \WP_Error( 'asde_ficha', asde_t( 'teléfono no válido.' ) );
			}
		}
		$len = (int) ( $meta['len'] ?? 0 );
		if ( $len > 0 && mb_strlen( $v ) > $len ) {
			/* translators: %d: número máximo de caracteres del campo */
			return new \WP_Error( 'asde_ficha', sprintf( asde_t( 'máximo %d caracteres.' ), $len ) );
		}
		return $v;
	}

	/** Valor para mostrar (etiqueta del desplegable, Sí/No, fecha dd/mm/aaaa). */
	public static function mostrar( string $valor, array $meta ): string {
		$tipo = (string) ( $meta['tipo'] ?? '' );
		if ( '' === $valor ) {
			return 'bool' === $tipo ? asde_t( 'No' ) : '—';
		}
		return match ( true ) {
			'bool' === $tipo                                                 => Escritura::iguales( $valor, '1' ) ? asde_t( 'Sí' ) : asde_t( 'No' ),
			'date' === $tipo && (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', $valor ) => wp_date( 'd/m/Y', strtotime( $valor . ' 12:00' ) ),
			'multienum' === $tipo                                            => implode( ', ', array_map( static fn( $v ) => $meta['opciones'][ $v ] ?? $v, array_filter( explode( ',', str_replace( '^', '', $valor ) ) ) ) ),
			isset( $meta['opciones'][ $valor ] )                             => (string) $meta['opciones'][ $valor ],
			default                                                          => $valor,
		};
	}
}
