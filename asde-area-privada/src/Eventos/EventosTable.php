<?php
namespace AsdeBalears\AreaPrivada\Eventos;

use AsdeBalears\AreaPrivada\Domain\Grupo;

defined( 'ABSPATH' ) || exit;

/**
 * Eventos del grupo ({prefix}asde_eventos, doc 32): las actividades (salidas,
 * acampadas, campamentos…) con sus datos, las secciones convocadas y sus plazas,
 * lo que pide la normativa de tiempo libre y el presupuesto.
 *
 * Sin datos personales: las personas del equipo van en asde_evento_inscripciones
 * (solo su nº Scouts de España). Las fechas del evento se guardan en la hora local
 * del sitio, tal como se escriben; las marcas de quién y cuándo, en UTC.
 *
 * Estados guardados: borrador, publicado y cancelado (las inscripciones, el cierre y
 * las cuentas llegan en las versiones siguientes). Lo que se enseña (estado_visible)
 * añade «pendiente de Tesorería», «presupuesto validado», «en curso» y «celebrado».
 */
final class EventosTable {

	public const BORRADOR  = 'borrador';
	public const PUBLICADO = 'publicado';
	public const CANCELADO = 'cancelado';

	/** Etiquetas de lo que se enseña (estado_visible). */
	public const ESTADOS = array(
		'borrador'    => 'Borrador',
		'por_validar' => 'Presupuesto pendiente de Tesorería',
		'validado'    => 'Presupuesto validado',
		'publicado'   => 'Publicado',
		'en_curso'    => 'En curso',
		'celebrado'   => 'Celebrado',
		'cancelado'   => 'Cancelado',
	);

	/** Ámbito del evento (doc 32, apartado 1). */
	public const AMBITOS = array(
		'secciones' => 'De sección (una o varias)',
		'grupo'     => 'De grupo (todas las secciones)',
		'externo'   => 'Externo (lo organiza otra entidad)',
	);

	/** Filtros de la agenda. */
	public const FILTROS = array(
		''           => 'Próximos y en curso',
		'pasados'    => 'Ya celebrados',
		'borradores' => 'Borradores',
		'cancelados' => 'Cancelados',
		'todos'      => 'Todos',
	);

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'asde_eventos';
	}

	public static function install_table(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$t       = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE $t (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				grupo varchar(3) NOT NULL,
				tipo varchar(30) NOT NULL,
				ambito varchar(20) NOT NULL DEFAULT 'secciones',
				nombre varchar(120) NOT NULL,
				inicio datetime NOT NULL,
				fin datetime NOT NULL,
				lugar varchar(160) NOT NULL DEFAULT '',
				mapa varchar(255) NOT NULL DEFAULT '',
				salida varchar(160) NOT NULL DEFAULT '',
				llegada varchar(160) NOT NULL DEFAULT '',
				descripcion text NULL,
				material text NULL,
				secciones varchar(120) NOT NULL DEFAULT '',
				plazas text NULL,
				plazas_max int(11) NOT NULL DEFAULT 0,
				edad_min tinyint(3) unsigned NULL,
				edad_max tinyint(3) unsigned NULL,
				normativa text NULL,
				con_coste tinyint(1) NOT NULL DEFAULT 0,
				equipo_previsto smallint(5) unsigned NOT NULL DEFAULT 0,
				margen_activo tinyint(1) NOT NULL DEFAULT 1,
				margen_pct decimal(5,2) NOT NULL DEFAULT 5.00,
				presupuesto text NULL,
				validacion_pedida_por bigint(20) unsigned NOT NULL DEFAULT 0,
				validacion_pedida_en datetime NULL,
				validado_por bigint(20) unsigned NOT NULL DEFAULT 0,
				validado_en datetime NULL,
				estado varchar(20) NOT NULL DEFAULT 'borrador',
				creado datetime NOT NULL,
				creado_por bigint(20) unsigned NOT NULL DEFAULT 0,
				actualizado datetime NULL,
				publicado_por bigint(20) unsigned NOT NULL DEFAULT 0,
				publicado_en datetime NULL,
				cancelado_por bigint(20) unsigned NOT NULL DEFAULT 0,
				cancelado_en datetime NULL,
				cancelado_motivo varchar(255) NOT NULL DEFAULT '',
				crm_id varchar(36) NOT NULL DEFAULT '',
				PRIMARY KEY  (id),
				KEY grupo (grupo),
				KEY inicio (inicio)
			) $charset;"
		);
	}

	/** Nº del evento (referencia para los pagos): E + grupo + id (E6840012). */
	public static function numero( array $e ): string {
		return 'E' . $e['grupo'] . str_pad( (string) $e['id'], 4, '0', STR_PAD_LEFT );
	}

	/** @return int Id del evento. */
	public static function crear( array $datos ): int {
		global $wpdb;
		$wpdb->insert(
			self::table(),
			array_merge(
				self::columnas( $datos ),
				array(
					'grupo'      => Grupo::numero(),
					'estado'     => self::BORRADOR,
					'creado'     => current_time( 'mysql', true ),
					'creado_por' => get_current_user_id(),
				)
			)
		);
		return (int) $wpdb->insert_id;
	}

	/** Cambia los datos del evento (no su estado ni el presupuesto validado). */
	public static function guardar( int $id, array $datos ): bool {
		return self::actualizar( $id, array_merge( self::columnas( $datos ), array( 'actualizado' => current_time( 'mysql', true ) ) ) );
	}

	/** Evento del grupo actual, o null. */
	public static function obtener( int $id ): ?array {
		global $wpdb;
		$fila = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d AND grupo = %s', $id, Grupo::numero() ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return $fila ? self::preparar( $fila ) : null;
	}

	/** @return array[] Todos los del grupo actual, por fecha de inicio. */
	public static function todos(): array {
		global $wpdb;
		$filas = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE grupo = %s ORDER BY inicio ASC, id ASC', Grupo::numero() ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return array_map( array( self::class, 'preparar' ), $filas );
	}

	/**
	 * Lo que se enseña: el estado guardado, con el paso del presupuesto mientras es un
	 * borrador y el de las fechas cuando está publicado.
	 */
	public static function estado_visible( array $e, ?int $ahora = null ): string {
		if ( self::CANCELADO === $e['estado'] ) {
			return 'cancelado';
		}
		if ( self::PUBLICADO === $e['estado'] ) {
			$ahora = $ahora ?? current_datetime()->getTimestamp();
			if ( $ahora > self::marca( $e['fin'] ) ) {
				return 'celebrado';
			}
			return $ahora >= self::marca( $e['inicio'] ) ? 'en_curso' : 'publicado';
		}
		if ( null !== $e['validado_en'] ) {
			return 'validado';
		}
		return null !== $e['validacion_pedida_en'] ? 'por_validar' : 'borrador';
	}

	/** ¿Cumple el filtro de la agenda? ('' = próximos y en curso, sin cancelar). */
	public static function cumple( array $e, string $filtro, ?int $ahora = null ): bool {
		$ahora     = $ahora ?? current_datetime()->getTimestamp();
		$cancelado = self::CANCELADO === $e['estado'];
		$pasado    = $ahora > self::marca( $e['fin'] );
		switch ( $filtro ) {
			case 'todos':
				return true;
			case 'cancelados':
				return $cancelado;
			case 'borradores':
				return ! $cancelado && self::BORRADOR === $e['estado'];
			case 'pasados':
				return ! $cancelado && $pasado;
			default:
				return ! $cancelado && ! $pasado;
		}
	}

	/** «14/11/2026 10:00 – 15/11/2026 18:00» o, si es en un solo día, «14/11/2026, 10:00 – 18:00». */
	public static function cuando( array $e ): string {
		$ini = self::marca( $e['inicio'] );
		$fin = self::marca( $e['fin'] );
		if ( wp_date( 'Y-m-d', $ini ) === wp_date( 'Y-m-d', $fin ) ) {
			return wp_date( 'd/m/Y', $ini ) . ', ' . wp_date( 'H:i', $ini ) . ' – ' . wp_date( 'H:i', $fin );
		}
		return wp_date( 'd/m/Y H:i', $ini ) . ' – ' . wp_date( 'd/m/Y H:i', $fin );
	}

	/** Marca de tiempo de una fecha del evento (hora local del sitio). */
	public static function marca( string $fecha ): int {
		$d = date_create( $fecha, wp_timezone() );
		return $d ? $d->getTimestamp() : 0;
	}

	public static function pedir_validacion( int $id ): bool {
		return self::actualizar(
			$id,
			array(
				'validacion_pedida_por' => get_current_user_id(),
				'validacion_pedida_en'  => current_time( 'mysql', true ),
			)
		);
	}

	/** Tesorería valida el presupuesto: queda guardado el cálculo y el precio por sección ya no cambia. */
	public static function validar( int $id, array $calculo ): bool {
		return self::actualizar(
			$id,
			array(
				'presupuesto'  => (string) wp_json_encode( $calculo ),
				'validado_por' => get_current_user_id(),
				'validado_en'  => current_time( 'mysql', true ),
			)
		);
	}

	/** Vuelve a abrir el presupuesto (solo en borrador): se puede cambiar y hay que validarlo otra vez. */
	public static function reabrir( int $id ): bool {
		return self::actualizar(
			$id,
			array(
				'presupuesto'           => null,
				'validado_por'          => 0,
				'validado_en'           => null,
				'validacion_pedida_por' => 0,
				'validacion_pedida_en'  => null,
			)
		);
	}

	public static function publicar( int $id ): bool {
		return self::actualizar(
			$id,
			array(
				'estado'        => self::PUBLICADO,
				'publicado_por' => get_current_user_id(),
				'publicado_en'  => current_time( 'mysql', true ),
			)
		);
	}

	public static function despublicar( int $id ): bool {
		return self::actualizar(
			$id,
			array(
				'estado'        => self::BORRADOR,
				'publicado_por' => 0,
				'publicado_en'  => null,
			)
		);
	}

	public static function cancelar( int $id, string $motivo ): bool {
		return self::actualizar(
			$id,
			array(
				'estado'           => self::CANCELADO,
				'cancelado_por'    => get_current_user_id(),
				'cancelado_en'     => current_time( 'mysql', true ),
				'cancelado_motivo' => mb_substr( sanitize_text_field( $motivo ), 0, 255 ),
			)
		);
	}

	/** Borra un borrador (no hay pagos ni inscripciones: no es un justificante de nada). */
	public static function borrar( int $id ): bool {
		global $wpdb;
		return (bool) $wpdb->delete(
			self::table(),
			array(
				'id'     => $id,
				'grupo'  => Grupo::numero(),
				'estado' => self::BORRADOR,
			)
		);
	}

	/** Columnas que se guardan desde el formulario (ya validadas por el servicio). */
	private static function columnas( array $d ): array {
		$out = array();
		foreach ( array( 'tipo', 'ambito', 'nombre', 'inicio', 'fin', 'lugar', 'mapa', 'salida', 'llegada', 'descripcion', 'material' ) as $k ) {
			if ( array_key_exists( $k, $d ) ) {
				$out[ $k ] = (string) $d[ $k ];
			}
		}
		if ( array_key_exists( 'secciones', $d ) ) {
			$out['secciones'] = implode( ',', (array) $d['secciones'] );
		}
		foreach ( array( 'plazas', 'normativa' ) as $k ) {
			if ( array_key_exists( $k, $d ) ) {
				$out[ $k ] = (string) wp_json_encode( (array) $d[ $k ] );
			}
		}
		foreach ( array( 'plazas_max', 'con_coste', 'equipo_previsto', 'margen_activo' ) as $k ) {
			if ( array_key_exists( $k, $d ) ) {
				$out[ $k ] = (int) $d[ $k ];
			}
		}
		foreach ( array( 'edad_min', 'edad_max' ) as $k ) {
			if ( array_key_exists( $k, $d ) ) {
				$out[ $k ] = null === $d[ $k ] ? null : (int) $d[ $k ];
			}
		}
		if ( array_key_exists( 'margen_pct', $d ) ) {
			$out['margen_pct'] = round( (float) $d['margen_pct'], 2 );
		}
		return $out;
	}

	private static function actualizar( int $id, array $datos ): bool {
		global $wpdb;
		return false !== $wpdb->update(
			self::table(),
			$datos,
			array(
				'id'    => $id,
				'grupo' => Grupo::numero(),
			)
		);
	}

	private static function preparar( array $f ): array {
		$f['id']            = (int) $f['id'];
		$f['secciones']     = array_values( array_filter( explode( ',', (string) $f['secciones'] ) ) );
		$f['plazas']        = self::plazas( json_decode( (string) $f['plazas'], true ) );
		$f['plazas_max']    = (int) $f['plazas_max'];
		$f['edad_min']      = null === $f['edad_min'] ? null : (int) $f['edad_min'];
		$f['edad_max']      = null === $f['edad_max'] ? null : (int) $f['edad_max'];
		$f['normativa']     = Normativa::datos( (array) json_decode( (string) $f['normativa'], true ) );
		$f['con_coste']     = (bool) (int) $f['con_coste'];
		$f['equipo_previsto'] = (int) $f['equipo_previsto'];
		$f['margen_activo'] = (bool) (int) $f['margen_activo'];
		$f['margen_pct']    = (float) $f['margen_pct'];
		$presupuesto        = json_decode( (string) $f['presupuesto'], true );
		$f['presupuesto']   = is_array( $presupuesto ) ? $presupuesto : null;
		$f['descripcion']   = (string) $f['descripcion'];
		$f['material']      = (string) $f['material'];
		foreach ( array( 'creado_por', 'validacion_pedida_por', 'validado_por', 'publicado_por', 'cancelado_por' ) as $k ) {
			$f[ $k ] = (int) $f[ $k ];
		}
		return $f;
	}

	/** @return array<string, array{previstas:int, maximas:int}> */
	private static function plazas( $raw ): array {
		$out = array();
		foreach ( (array) $raw as $s => $p ) {
			$out[ (string) $s ] = array(
				'previstas' => max( 0, (int) ( $p['previstas'] ?? 0 ) ),
				'maximas'   => max( 0, (int) ( $p['maximas'] ?? 0 ) ),
			);
		}
		return $out;
	}
}
