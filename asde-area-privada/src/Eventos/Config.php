<?php
namespace AsdeBalears\AreaPrivada\Eventos;

use AsdeBalears\AreaPrivada\Domain\Grupo;

defined( 'ABSPATH' ) || exit;

/**
 * Configuración de los eventos de cada grupo (opción asde_ap_eventos_<grupo>, doc 32):
 *
 * - **tipos**: catálogo de tipos de actividad. Un tipo es una plantilla de valores por
 *   defecto (si tiene coste, si se comunica a ASDE Baleares); en cada evento se cambia.
 * - **margen** del fondo de emergencia: activo y porcentaje (5 % por defecto, P-67).
 * - **titulaciones**: qué valores del campo «formación de tiempo libre» del CRM cuentan
 *   como título de director/a o de monitor/a. Vacío = se deduce del texto del valor.
 */
final class Config {

	private const OPTION = 'asde_ap_eventos_';

	public const MAX_TIPOS = 20;

	/**
	 * Los ocho tipos de actividad del plan (doc 32, apartado 1). Las reuniones de sección y
	 * las de adultos no son eventos (P-62).
	 *
	 * - coste: el evento nace con presupuesto (lo valida Tesorería antes de publicar);
	 * - comunicacion: 'auto' = se pide la comunicación a ASDE Baleares si hay 2 o más noches
	 *   y 10 o más menores (P-76 bis); 'no' = nunca (lo organiza otro, o son adultos);
	 * - crm: `type` de stic_Events para la copia al CRM (última fase).
	 */
	public const TIPOS = array(
		'salida'     => array(
			'nombre'       => 'Salida o excursión',
			'coste'        => false,
			'comunicacion' => 'auto',
			'crm'          => 'jornada',
		),
		'acampada'   => array(
			'nombre'       => 'Acampada',
			'coste'        => true,
			'comunicacion' => 'auto',
			'crm'          => 'campamento',
		),
		'campamento' => array(
			'nombre'       => 'Campamento',
			'coste'        => true,
			'comunicacion' => 'auto',
			'crm'          => 'campamento',
		),
		'ruta'       => array(
			'nombre'       => 'Ruta o travesía',
			'coste'        => true,
			'comunicacion' => 'auto',
			'crm'          => 'campamento',
		),
		'grupo'      => array(
			'nombre'       => 'Actividad de grupo',
			'coste'        => false,
			'comunicacion' => 'auto',
			'crm'          => 'jornada',
		),
		'servicio'   => array(
			'nombre'       => 'Servicio o voluntariado',
			'coste'        => false,
			'comunicacion' => 'auto',
			'crm'          => 'jornada',
		),
		'externo'    => array(
			'nombre'       => 'Evento externo',
			'coste'        => true,
			'comunicacion' => 'no',
			'crm'          => 'festivalof',
		),
		'formacion'  => array(
			'nombre'       => 'Formación',
			'coste'        => false,
			'comunicacion' => 'no',
			'crm'          => 'Curso',
		),
	);

	/** @return array{tipos:array, margen_activo:bool, margen_pct:float, titulaciones:array{director:string[], monitor:string[]}} */
	public static function defaults(): array {
		$tipos = array();
		foreach ( self::TIPOS as $id => $t ) {
			$tipos[ $id ] = $t + array( 'activo' => true );
		}
		return array(
			'tipos'         => $tipos,
			'margen_activo' => true,
			'margen_pct'    => 5.0,
			'titulaciones'  => array(
				'director' => array(),
				'monitor'  => array(),
			),
		);
	}

	/** Configuración del grupo (lo guardado sobre los valores por defecto). */
	public static function de( string $grupo = '' ): array {
		$grupo = '' !== $grupo ? $grupo : Grupo::numero();
		$c     = (array) get_option( self::OPTION . $grupo, array() );
		$d     = self::defaults();
		$cfg   = array_merge( $d, array_intersect_key( $c, $d ) );

		$tipos = array();
		foreach ( (array) $cfg['tipos'] as $id => $t ) {
			$id = sanitize_key( (string) $id );
			if ( '' === $id || '' === trim( (string) ( $t['nombre'] ?? '' ) ) ) {
				continue;
			}
			$tipos[ $id ] = array(
				'nombre'       => mb_substr( (string) $t['nombre'], 0, 60 ),
				'coste'        => ! empty( $t['coste'] ),
				'comunicacion' => 'no' === ( $t['comunicacion'] ?? '' ) ? 'no' : 'auto',
				'crm'          => mb_substr( (string) ( $t['crm'] ?? ( self::TIPOS[ $id ]['crm'] ?? 'jornada' ) ), 0, 30 ),
				'activo'       => ! isset( $t['activo'] ) || (bool) $t['activo'],
			);
		}
		$cfg['tipos']         = $tipos ?: $d['tipos'];
		$cfg['margen_activo'] = (bool) $cfg['margen_activo'];
		$cfg['margen_pct']    = min( 50.0, max( 0.0, round( (float) $cfg['margen_pct'], 2 ) ) );
		$tit                  = (array) $cfg['titulaciones'];
		$cfg['titulaciones']  = array(
			'director' => array_values( array_filter( array_map( 'strval', (array) ( $tit['director'] ?? array() ) ) ) ),
			'monitor'  => array_values( array_filter( array_map( 'strval', (array) ( $tit['monitor'] ?? array() ) ) ) ),
		);
		return $cfg;
	}

	public static function guardar( array $cfg, string $grupo = '' ): void {
		$grupo = '' !== $grupo ? $grupo : Grupo::numero();
		update_option( self::OPTION . $grupo, array_intersect_key( $cfg, self::defaults() ), false );
	}

	public static function borrar( string $grupo ): void {
		delete_option( self::OPTION . $grupo );
	}

	/** @return array<string, array> Tipos con los que se puede crear un evento. */
	public static function tipos_activos( array $cfg ): array {
		return array_filter( $cfg['tipos'], static fn( array $t ) => $t['activo'] );
	}

	/** Nombre de un tipo, también de uno que ya no está en la configuración. */
	public static function nombre_tipo( array $cfg, string $tipo ): string {
		return isset( $cfg['tipos'][ $tipo ] ) ? asde_t( $cfg['tipos'][ $tipo ]['nombre'] ) : $tipo;
	}

	/** Identificador para un tipo nuevo a partir de su nombre. */
	public static function id_para( string $nombre, array $existentes ): string {
		$base = substr( sanitize_key( remove_accents( str_replace( ' ', '-', strtolower( $nombre ) ) ) ), 0, 30 ) ?: 'tipo';
		$id   = $base;
		for ( $n = 2; isset( $existentes[ $id ] ); $n++ ) {
			$id = $base . '-' . $n;
		}
		return $id;
	}
}
