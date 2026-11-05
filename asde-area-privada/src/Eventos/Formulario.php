<?php
namespace AsdeBalears\AreaPrivada\Eventos;

defined( 'ABSPATH' ) || exit;

/**
 * Valores con los que se pinta el formulario de un evento: el de editar y el asistente de crear (doc 66).
 * Salen, por orden, de lo escrito si no se pudo guardar, del evento que se edita (o se copia) y del tipo elegido.
 */
final class Formulario {

	/** Cajas de marcar: si no llegan en el envío, valen «no». */
	private const CASILLAS = array( 'internacional', 'riesgo', 'riesgo_titulado', 'agua', 'socorrista', 'con_coste' );

	/** @return array<string, array> Tipos que se pueden elegir (y el del evento, aunque ya no esté activo). */
	public static function tipos( array $cfg, ?array $e ): array {
		$tipos = Config::tipos_activos( $cfg );
		if ( $e && ! isset( $tipos[ $e['tipo'] ] ) && isset( $cfg['tipos'][ $e['tipo'] ] ) ) {
			$tipos[ $e['tipo'] ] = $cfg['tipos'][ $e['tipo'] ];
		}
		return $tipos;
	}

	/**
	 * @param ?array   $e         Evento que se edita o se copia (null = nuevo en blanco).
	 * @param ?array   $v         Lo escrito si no se pudo guardar.
	 * @param string[] $secciones Secciones que puede convocar.
	 * @param bool     $copia     $e es el modelo de uno nuevo: las fechas pasan al año siguiente.
	 */
	public static function valores( ?array $e, ?array $v, array $cfg, array $secciones, string $tipo = '', bool $copia = false ): array {
		$tipos   = self::tipos( $cfg, $e );
		$inicial = $e ? $e['tipo'] : ( isset( $tipos[ $tipo ] ) ? $tipo : (string) array_key_first( $tipos ) );
		$n       = $e['normativa'] ?? array();
		$dia     = static fn( ?string $f ): string => $f && $copia ? (string) date_create( $f )->modify( '+1 year' )->format( 'Y-m-d' ) : (string) $f; // phpcs:ignore WordPress.DateTime.RestrictedFunctions
		$d       = array(
			'tipo'              => $inicial,
			'nombre'            => $e['nombre'] ?? '',
			'ambito'            => $e['ambito'] ?? 'secciones',
			'secciones'         => $e['secciones'] ?? ( 1 === count( $secciones ) ? $secciones : array() ),
			'inicio_fecha'      => $e ? $dia( substr( $e['inicio'], 0, 10 ) ) : '',
			'inicio_hora'       => $e ? substr( $e['inicio'], 11, 5 ) : '10:00',
			'fin_fecha'         => $e ? $dia( substr( $e['fin'], 0, 10 ) ) : '',
			'fin_hora'          => $e ? substr( $e['fin'], 11, 5 ) : '18:00',
			'lugar'             => $e['lugar'] ?? '',
			'mapa'              => $e['mapa'] ?? '',
			'salida'            => $e['salida'] ?? '',
			'llegada'           => $e['llegada'] ?? '',
			'descripcion'       => $e['descripcion'] ?? '',
			'material'          => $e['material'] ?? '',
			'plazas_max'        => $e && $e['plazas_max'] ? (string) $e['plazas_max'] : '',
			'edad_min'          => null !== ( $e['edad_min'] ?? null ) ? (string) $e['edad_min'] : '',
			'edad_max'          => null !== ( $e['edad_max'] ?? null ) ? (string) $e['edad_max'] : '',
			'con_coste'         => $e ? $e['con_coste'] : ! empty( $tipos[ $inicial ]['coste'] ),
			'comunicacion'      => $n['comunicacion'] ?? ( $tipos[ $inicial ]['comunicacion'] ?? 'auto' ),
			'internacional'     => $n['internacional'] ?? false,
			'seguro_compania'   => $n['seguro_compania'] ?? '',
			'seguro_poliza'     => $n['seguro_poliza'] ?? '',
			'riesgo'            => $n['riesgo'] ?? false,
			'riesgo_titulado'   => $n['riesgo_titulado'] ?? false,
			'agua'              => $n['agua'] ?? false,
			'socorrista'        => $n['socorrista'] ?? false,
			'dependencia12'     => (string) ( $n['dependencia12'] ?? 0 ),
			'dependencia3'      => (string) ( $n['dependencia3'] ?? 0 ),
			'modalidad'         => $e['modalidad'] ?? 'abierta',
			'inscripcion_desde' => $dia( $e['inscripcion_desde'] ?? null ),
			'inscripcion_hasta' => $dia( $e['inscripcion_hasta'] ?? null ),
		);
		foreach ( $secciones as $s ) {
			$d[ 'previstas_' . $s ] = (string) ( $e['plazas'][ $s ]['previstas'] ?? 0 );
			$d[ 'maximas_' . $s ]   = (string) ( $e['plazas'][ $s ]['maximas'] ?? 0 );
		}
		if ( null !== $v ) {
			foreach ( self::CASILLAS as $casilla ) {
				$d[ $casilla ] = ! empty( $v[ $casilla ] );
			}
			$d['secciones'] = array_map( 'strval', (array) ( $v['secciones'] ?? array() ) );
			foreach ( $d as $k => $valor ) {
				if ( is_string( $valor ) && isset( $v[ $k ] ) && is_scalar( $v[ $k ] ) ) {
					$d[ $k ] = (string) $v[ $k ];
				}
			}
		}
		return $d;
	}
}
