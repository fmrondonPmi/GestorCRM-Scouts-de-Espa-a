<?php
namespace AsdeBalears\AreaPrivada\Frontend;

use AsdeBalears\AreaPrivada\Crm\Altas;
use AsdeBalears\AreaPrivada\Domain\Secciones;

defined( 'ABSPATH' ) || exit;

/**
 * Personas → «Alta de socio/a» (v0.23, doc 34, fase 3): los pasos del alta de una
 * persona nueva en el CRM.
 *
 * Cada paso se envía por POST y se contesta en la misma petición (como «¿Está ya en el
 * CRM?»): lo escrito viaja en el propio formulario, en campos ocultos, y no se guarda
 * en WordPress (regla 1). Esos campos no se creen: lo que decide de verdad (si ya tiene
 * ficha en el grupo, si hay que confirmar que está en otro grupo, el cerrojo) lo vuelve
 * a comprobar Crm\Altas al crear.
 *
 * Pasos: documento → datos (persona y membresía) → revisar → hecho. Si ya tiene ficha
 * en el grupo, el alta se para y se lleva a su ficha (P-79).
 */
final class AltaSocio {

	public const DOCUMENTO = 'documento';
	public const DATOS     = 'datos';
	public const REVISAR   = 'revisar';
	public const HECHO     = 'hecho';
	public const EN_GRUPO  = 'en_grupo';

	/** Acciones (asde_action) de los formularios del alta. */
	public const ACCIONES = array( 'alta_documento', 'alta_revisar', 'alta_crear' );

	/**
	 * Estado de la pantalla.
	 *
	 * @return array{paso:string, datos:array<string,string>, seccion:string, inicio:string, sin_documento:bool, comprobado:string, otros:?int, en_grupo:array, error:string, resultado:?array, escrito:string}
	 */
	public static function inicio(): array {
		return array(
			'paso'          => self::DOCUMENTO,
			'datos'         => array(),
			'seccion'       => '',
			'inicio'        => wp_date( 'Y-m-d' ),
			'sin_documento' => false,
			'comprobado'    => '', // Documento ya comprobado (normalizado).
			'otros'         => 0,  // Fichas en otros grupos u organizaciones; null: no se pudo comprobar.
			'en_grupo'      => array(),
			'error'         => '',
			'resultado'     => null,
			'escrito'       => '',
		);
	}

	/**
	 * Atiende el paso enviado y dice cuál se enseña ahora.
	 *
	 * @param array<string, mixed> $post      Lo enviado (ya sin barras).
	 * @param callable             $comprobar fn( string $documento ): array — Duplicados::comprobar() con el límite por persona.
	 */
	public static function atender( string $accion, array $post, callable $comprobar, ?Altas $altas ): array {
		$s = self::desde_post( $post );
		if ( 'alta_crear' === $accion && ! empty( $post['corregir'] ) ) {
			$s['paso'] = self::DATOS;
			return $s;
		}
		switch ( $accion ) {
			case 'alta_documento':
				if ( ! empty( $post['sin_documento'] ) ) {
					// Menor de 14 años sin documento (P-80, P-82): no hay nada que comprobar todavía.
					$s['sin_documento'] = true;
					$s['comprobado']    = '';
					$s['otros']         = 0;
					unset( $s['datos']['stic_identification_number_c'] );
					$s['paso'] = self::DATOS;
					return $s;
				}
				$s['escrito'] = self::texto( $post['documento'] ?? '' );
				$r            = $comprobar( $s['escrito'] );
				if ( '' !== (string) $r['error'] ) {
					$s['error'] = (string) $r['error'];
					return $s;
				}
				return self::tras_comprobar( $s, $r );

			case 'alta_revisar':
				$s = self::validar( $s );
				if ( '' !== $s['error'] ) {
					$s['paso'] = self::DATOS;
					return $s;
				}
				$doc = (string) ( $s['datos']['stic_identification_number_c'] ?? '' );
				if ( '' === $doc ) {
					$s['comprobado'] = '';
					$s['otros']      = 0;
				} elseif ( $doc !== $s['comprobado'] ) {
					// El documento ha cambiado desde el primer paso (o no se puso entonces): se comprueba.
					$r = $comprobar( $doc );
					if ( '' !== (string) $r['error'] ) {
						$s['error'] = (string) $r['error'];
						$s['paso']  = self::DATOS;
						return $s;
					}
					$s = self::tras_comprobar( $s, $r );
					if ( self::EN_GRUPO === $s['paso'] ) {
						return $s;
					}
				}
				$s['paso'] = self::REVISAR;
				return $s;

			case 'alta_crear':
				if ( ! $altas ) {
					$s['error'] = asde_t( 'La conexión con el CRM no está disponible.' );
					$s['paso']  = self::REVISAR;
					return $s;
				}
				$r = $altas->crear_persona( $s['datos'], $s['seccion'], $s['inicio'], ! empty( $post['confirmo'] ) );
				if ( is_array( $r ) ) {
					$s['paso']      = self::HECHO;
					$s['resultado'] = $r;
					return $s;
				}
				$s['error'] = $r->get_error_message();
				switch ( $r->get_error_code() ) {
					case 'asde_alta_confirmar':
						$d          = (array) $r->get_error_data();
						$s['otros'] = isset( $d['otros'] ) ? (int) $d['otros'] : null;
						$s['paso']  = self::REVISAR;
						break;
					case 'asde_alta_ya_en_grupo':
						$s['paso'] = self::EN_GRUPO;
						break;
					case 'asde_alta_datos':
					case 'asde_alta':
						$s['paso'] = self::DATOS;
						break;
					default: // A medias, otra alta en curso, sin permiso…: se puede volver a intentar.
						$s['paso'] = self::REVISAR;
				}
				return $s;
		}
		return self::inicio();
	}

	/** Lo que ha llegado en los campos del formulario (los ocultos de los pasos anteriores incluidos). */
	private static function desde_post( array $post ): array {
		$s     = self::inicio();
		$datos = is_array( $post['d'] ?? null ) ? $post['d'] : array();
		foreach ( Altas::CAMPOS_PERSONA as $c ) {
			$v = self::texto( $datos[ $c ] ?? '' );
			if ( '' !== $v ) {
				$s['datos'][ $c ] = $v;
			}
		}
		$s['seccion']       = sanitize_key( (string) ( $post['seccion'] ?? '' ) );
		$s['inicio']        = self::texto( $post['inicio'] ?? '' ) ?: wp_date( 'Y-m-d' );
		$s['sin_documento'] = ! empty( $post['sin_doc'] );
		$s['comprobado']    = self::texto( $post['comprobado'] ?? '' );
		$otros              = (string) ( $post['otros'] ?? '0' );
		$s['otros']         = 'x' === $otros ? null : absint( $otros );
		return $s;
	}

	/** Lo que ha dicho la comprobación del documento. */
	private static function tras_comprobar( array $s, array $r ): array {
		$s['comprobado']                           = (string) $r['documento'];
		$s['datos']['stic_identification_number_c'] = (string) $r['documento'];
		$s['sin_documento']                        = false;
		$s['error']                                = '';
		if ( $r['en_grupo'] ) {
			$s['en_grupo'] = $r['en_grupo'];
			$s['paso']     = self::EN_GRUPO;
			return $s;
		}
		$s['otros'] = null === $r['otros'] ? null : (int) $r['otros'];
		$s['paso']  = self::DATOS;
		return $s;
	}

	/** Los datos de la persona y de la membresía, como los pedirá Crm\Altas. */
	private static function validar( array $s ): array {
		$limpio = Altas::limpiar_persona( $s['datos'] );
		if ( is_wp_error( $limpio ) ) {
			$s['error'] = $limpio->get_error_message();
			return $s;
		}
		$s['datos'] = $limpio;
		if ( ! Secciones::exists( $s['seccion'] ) ) {
			$s['error'] = asde_t( 'Elige la sección.' );
			return $s;
		}
		$f = \DateTime::createFromFormat( '!Y-m-d', $s['inicio'] );
		if ( ! $f || $f->format( 'Y-m-d' ) !== $s['inicio'] ) {
			$s['error'] = asde_t( 'La fecha de alta no es válida.' );
		} elseif ( $s['inicio'] > wp_date( 'Y-m-d' ) ) {
			$s['error'] = asde_t( 'La fecha de alta no puede ser posterior a hoy.' );
		}
		return $s;
	}

	/** @param mixed $v */
	private static function texto( $v ): string {
		return is_scalar( $v ) ? trim( sanitize_text_field( (string) $v ) ) : '';
	}

	/**
	 * Campos ocultos que llevan lo escrito al paso siguiente.
	 *
	 * @param string[] $sin Campos que el paso enseña como editables (no van ocultos).
	 */
	public static function ocultos( array $s, array $sin = array() ): string {
		$html = '';
		$add  = static function ( string $nombre, string $valor ) use ( &$html ): void {
			$html .= sprintf( '<input type="hidden" name="%s" value="%s">', esc_attr( $nombre ), esc_attr( $valor ) );
		};
		if ( ! in_array( 'd', $sin, true ) ) {
			foreach ( $s['datos'] as $c => $v ) {
				$add( 'd[' . $c . ']', (string) $v );
			}
			$add( 'seccion', $s['seccion'] );
			$add( 'inicio', $s['inicio'] );
		}
		$add( 'comprobado', $s['comprobado'] );
		$add( 'otros', null === $s['otros'] ? 'x' : (string) (int) $s['otros'] );
		if ( $s['sin_documento'] ) {
			$add( 'sin_doc', '1' );
		}
		return $html;
	}
}
