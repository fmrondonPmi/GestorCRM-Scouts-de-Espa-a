<?php
namespace AsdeBalears\AreaPrivada\Eventos;

use AsdeBalears\AreaPrivada\Domain\Grupo;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- tablas propias del plugin ({prefix}asde_*): no hay función de WordPress para ellas y los datos cambian en cada petición, no se cachean.

/**
 * Personas de un evento ({prefix}asde_evento_inscripciones, doc 43): el equipo
 * dirigente (v0.35) y, desde la segunda fase, los participantes inscritos.
 *
 * De cada persona solo se guarda su nº Scouts de España, sus secciones y si es adulta
 * el día que empieza el evento (para saber quién la gestiona y contar los menores sin
 * consultar el CRM) y su papel en el evento. Una persona está una sola vez en cada evento.
 *
 * De los participantes (papel «participante», v0.35), además: el estado de la
 * inscripción, la sección por la que paga y su precio (fijo), la autorización (firmada en
 * la web —quién, cuándo, versión del texto y una huella de la IP— o en papel —quién lo
 * recibió y cuándo—), el transporte y, **cifrado**, quién recoge al menor y las
 * observaciones para el equipo (se borran al cerrar el evento, P-150). La salud no se copia:
 * solo la fecha en que la familia dijo que la ficha estaba al día.
 */
final class InscripcionesTable {

	/** Papeles del equipo. */
	public const PAPELES = array(
		'director'  => 'Director/a',
		'monitor'   => 'Monitor/a',
		'practicas' => 'En prácticas',
		'apoyo'     => 'Apoyo (cocina, intendencia, transporte)',
	);

	/** Papel de quien se inscribe para participar (v0.35). */
	public const PARTICIPANTE = 'participante';

	// Estados de la inscripción de un participante (doc 43, apartado 3).
	public const PENDIENTE    = 'pendiente';
	public const CONFIRMADA   = 'confirmada';
	public const ESPERA       = 'espera';
	public const NO_PARTICIPA = 'no_participa';
	public const BAJA         = 'baja';

	/** Etiquetas de los estados («sin_respuesta» y «plaza» no se guardan: se deducen). */
	public const ESTADOS = array(
		'sin_respuesta' => 'Sin respuesta',
		'pendiente'     => 'Pendiente',
		'confirmada'    => 'Confirmada',
		'plaza'         => 'Plaza ofrecida',
		'espera'        => 'En lista de espera',
		'no_participa'  => 'No participa',
		'baja'          => 'Baja',
	);

	/** Estados que ocupan plaza (con la lista de espera aparte: ocupa la que tiene una plaza ofrecida). */
	public const ACTIVAS = array( self::PENDIENTE, self::CONFIRMADA );

	/** Cómo está la autorización de participación ('' = falta). */
	public const AUTORIZACIONES = array(
		''      => 'Falta la autorización',
		'web'   => 'Firmada en la web',
		'papel' => 'Entregada en papel',
		'no'    => 'No hace falta (mayor de edad)',
	);

	/** Quién hizo la inscripción. */
	public const COMO = array(
		'familia' => 'La familia',
		'propia'  => 'La propia persona',
		'gestor'  => 'El equipo del grupo',
	);

	public const TRANSPORTES = array(
		'grupo'  => 'Con el grupo',
		'propio' => 'Por su cuenta',
	);

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'asde_evento_inscripciones';
	}

	public static function install_table(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$t       = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE $t (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				evento_id bigint(20) unsigned NOT NULL,
				grupo varchar(3) NOT NULL,
				socio varchar(20) NOT NULL,
				secciones varchar(120) NOT NULL DEFAULT '',
				adulto tinyint(1) NOT NULL DEFAULT 0,
				papel varchar(20) NOT NULL,
				creado datetime NOT NULL,
				creado_por bigint(20) unsigned NOT NULL DEFAULT 0,
				estado varchar(20) NOT NULL DEFAULT '',
				seccion varchar(20) NOT NULL DEFAULT '',
				precio decimal(10,2) NOT NULL DEFAULT 0.00,
				como varchar(10) NOT NULL DEFAULT '',
				motivo varchar(255) NOT NULL DEFAULT '',
				espera_en datetime NULL,
				oferta_hasta datetime NULL,
				autorizacion varchar(10) NOT NULL DEFAULT '',
				autorizacion_por bigint(20) unsigned NOT NULL DEFAULT 0,
				autorizacion_en datetime NULL,
				autorizacion_version varchar(20) NOT NULL DEFAULT '',
				autorizacion_huella varchar(64) NOT NULL DEFAULT '',
				transporte_ida varchar(10) NOT NULL DEFAULT '',
				transporte_vuelta varchar(10) NOT NULL DEFAULT '',
				salud_revisada datetime NULL,
				datos_enc text NULL,
				confirmada_en datetime NULL,
				baja_por bigint(20) unsigned NOT NULL DEFAULT 0,
				baja_en datetime NULL,
				baja_motivo varchar(255) NOT NULL DEFAULT '',
				actualizado datetime NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY evento_socio (evento_id,socio),
				KEY grupo (grupo),
				KEY evento_estado (evento_id,estado)
			) $charset;"
		);
	}

	/** @return int Id de la fila (0 si ya estaba o no se ha podido guardar). */
	public static function anadir( int $evento_id, string $socio, array $secciones, bool $adulto, string $papel ): int {
		global $wpdb;
		if ( self::de_socio( $evento_id, $socio ) ) {
			return 0;
		}
		$wpdb->insert(
			self::table(),
			array(
				'evento_id'  => $evento_id,
				'grupo'      => Grupo::numero(),
				'socio'      => $socio,
				'secciones'  => mb_substr( implode( ',', $secciones ), 0, 120 ),
				'adulto'     => $adulto ? 1 : 0,
				'papel'      => $papel,
				'creado'     => current_time( 'mysql', true ),
				'creado_por' => get_current_user_id(),
			)
		);
		return (int) $wpdb->insert_id;
	}

	public static function cambiar_papel( int $id, string $papel ): bool {
		global $wpdb;
		return false !== $wpdb->update(
			self::table(),
			array( 'papel' => $papel ),
			array(
				'id'    => $id,
				'grupo' => Grupo::numero(),
			)
		);
	}

	/** Quita a alguien del equipo (no es un justificante de nada: se borra la fila). */
	public static function quitar( int $id ): bool {
		global $wpdb;
		return (bool) $wpdb->delete(
			self::table(),
			array(
				'id'    => $id,
				'grupo' => Grupo::numero(),
			)
		);
	}

	public static function obtener( int $id ): ?array {
		global $wpdb;
		$fila = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d AND grupo = %s', self::table(), $id, Grupo::numero() ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return $fila ? self::preparar( $fila ) : null;
	}

	public static function de_socio( int $evento_id, string $socio ): ?array {
		global $wpdb;
		$fila = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE evento_id = %d AND grupo = %s AND socio = %s', self::table(), $evento_id, Grupo::numero(), $socio ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return $fila ? self::preparar( $fila ) : null;
	}

	/** @return array[] Equipo del evento: director/a primero, después monitores, prácticas y apoyo. */
	public static function equipo( int $evento_id ): array {
		global $wpdb;
		$papeles = array_keys( self::PAPELES );
		$marcas  = implode( ',', array_fill( 0, count( $papeles ), '%s' ) );
		$filas   = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE evento_id = %d AND grupo = %s AND papel IN ($marcas) ORDER BY id ASC", array_merge( array( self::table(), $evento_id, Grupo::numero() ), $papeles ) ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$filas   = array_map( array( self::class, 'preparar' ), $filas );
		usort( $filas, static fn( $a, $b ) => array_search( $a['papel'], $papeles, true ) <=> array_search( $b['papel'], $papeles, true ) ?: $a['id'] <=> $b['id'] );
		return $filas;
	}

	/** @return array<int, string[]> evento => nº Scouts de España de su equipo (para saber qué eventos gestiona alguien). */
	public static function equipos_del_grupo(): array {
		global $wpdb;
		$papeles = array_keys( self::PAPELES );
		$marcas  = implode( ',', array_fill( 0, count( $papeles ), '%s' ) );
		$filas   = (array) $wpdb->get_results( $wpdb->prepare( "SELECT evento_id, socio FROM %i WHERE grupo = %s AND papel IN ($marcas)", array_merge( array( self::table(), Grupo::numero() ), $papeles ) ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$out     = array();
		foreach ( $filas as $f ) {
			$out[ (int) $f['evento_id'] ][] = (string) $f['socio'];
		}
		return $out;
	}

	/** ¿Tiene el evento personas inscritas (participantes)? Mientras no, el precio se puede volver a validar. */
	public static function hay_participantes( int $evento_id ): bool {
		global $wpdb;
		$papeles = array_keys( self::PAPELES );
		$marcas  = implode( ',', array_fill( 0, count( $papeles ), '%s' ) );
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE evento_id = %d AND grupo = %s AND papel NOT IN ($marcas)", array_merge( array( self::table(), $evento_id, Grupo::numero() ), $papeles ) ) ); // phpcs:ignore WordPress.DB
	}

	// ---------------------------------------------------------------------
	// Participantes (v0.35)
	// ---------------------------------------------------------------------

	/** Columnas de un participante que se pueden escribir (el servicio ya las ha validado). */
	private const COLUMNAS = array( 'secciones', 'adulto', 'estado', 'seccion', 'precio', 'como', 'motivo', 'espera_en', 'oferta_hasta', 'autorizacion', 'autorizacion_por', 'autorizacion_en', 'autorizacion_version', 'autorizacion_huella', 'transporte_ida', 'transporte_vuelta', 'salud_revisada', 'datos_enc', 'confirmada_en', 'baja_por', 'baja_en', 'baja_motivo' );

	/**
	 * Inscribe a un participante. Si ya tenía una fila de participante (una baja o un «no
	 * participa»), se reutiliza: una persona está una sola vez en cada evento.
	 *
	 * @return int Id de la fila (0 si está en el equipo del evento o no se ha podido guardar).
	 */
	public static function inscribir( int $evento_id, string $socio, array $datos ): int {
		global $wpdb;
		$previa = self::de_socio( $evento_id, $socio );
		if ( $previa && self::PARTICIPANTE !== $previa['papel'] ) {
			return 0;
		}
		$fila = array_intersect_key( $datos, array_flip( self::COLUMNAS ) );
		if ( isset( $fila['secciones'] ) ) {
			$fila['secciones'] = mb_substr( implode( ',', (array) $fila['secciones'] ), 0, 120 );
		}
		// Al reutilizar una fila, nada de la inscripción anterior pasa a la nueva (salvo sus pagos, que siguen siendo suyos).
		$limpia = array(
			'motivo'               => '',
			'espera_en'            => null,
			'oferta_hasta'         => null,
			'autorizacion'         => '',
			'autorizacion_por'     => 0,
			'autorizacion_en'      => null,
			'autorizacion_version' => '',
			'autorizacion_huella'  => '',
			'transporte_ida'       => '',
			'transporte_vuelta'    => '',
			'salud_revisada'       => null,
			'datos_enc'            => null,
			'confirmada_en'        => null,
			'baja_por'             => 0,
			'baja_en'              => null,
			'baja_motivo'          => '',
			'actualizado'          => current_time( 'mysql', true ),
		);
		if ( $previa ) {
			$wpdb->update(
				self::table(),
				array_merge( $limpia, $fila, array( 'creado_por' => get_current_user_id() ) ),
				array(
					'id'    => $previa['id'],
					'grupo' => Grupo::numero(),
				)
			);
			return $previa['id'];
		}
		$wpdb->insert(
			self::table(),
			array_merge(
				$limpia,
				$fila,
				array(
					'evento_id'  => $evento_id,
					'grupo'      => Grupo::numero(),
					'socio'      => $socio,
					'papel'      => self::PARTICIPANTE,
					'creado'     => current_time( 'mysql', true ),
					'creado_por' => get_current_user_id(),
				)
			)
		);
		return (int) $wpdb->insert_id;
	}

	/** Cambia columnas de un participante. */
	public static function actualizar( int $id, array $datos ): bool {
		global $wpdb;
		$fila = array_intersect_key( $datos, array_flip( self::COLUMNAS ) );
		if ( ! $fila ) {
			return false;
		}
		$fila['actualizado'] = current_time( 'mysql', true );
		return false !== $wpdb->update(
			self::table(),
			$fila,
			array(
				'id'    => $id,
				'grupo' => Grupo::numero(),
				'papel' => self::PARTICIPANTE,
			)
		);
	}

	/** @return array[] Participantes del evento (todos los estados), por orden de inscripción. */
	public static function participantes( int $evento_id ): array {
		global $wpdb;
		$filas = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE evento_id = %d AND grupo = %s AND papel = %s ORDER BY id ASC', self::table(), $evento_id, Grupo::numero(), self::PARTICIPANTE ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return array_map( array( self::class, 'preparar' ), $filas );
	}

	/** @return array[] Inscripciones de estas personas en los eventos del grupo («Mis eventos», «Mis pagos»). */
	public static function de_socios( array $socios ): array {
		global $wpdb;
		$socios = array_values( array_unique( array_map( 'strval', $socios ) ) );
		if ( ! $socios ) {
			return array();
		}
		$marcas = implode( ',', array_fill( 0, count( $socios ), '%s' ) );
		$filas  = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE grupo = %s AND papel = %s AND socio IN ($marcas) ORDER BY id ASC", array_merge( array( self::table(), Grupo::numero(), self::PARTICIPANTE ), $socios ) ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return array_map( array( self::class, 'preparar' ), $filas );
	}

	/** @return array[] Participantes con estos estados en los eventos del grupo (avisos y recuentos, sin consultar el CRM). */
	public static function con_estado( array $estados ): array {
		global $wpdb;
		$marcas = implode( ',', array_fill( 0, count( $estados ), '%s' ) );
		$filas  = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE grupo = %s AND papel = %s AND estado IN ($marcas) ORDER BY id ASC", array_merge( array( self::table(), Grupo::numero(), self::PARTICIPANTE ), $estados ) ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return array_map( array( self::class, 'preparar' ), $filas );
	}

	/** Nº de la inscripción (referencia de pago si la persona no tiene familia en Tesorería): I + grupo + id. */
	public static function numero( array $f ): string {
		return 'I' . $f['grupo'] . str_pad( (string) $f['id'], 5, '0', STR_PAD_LEFT );
	}

	/** Borra todas las filas de un evento (al borrar un borrador). */
	public static function borrar_evento( int $evento_id ): void {
		global $wpdb;
		$wpdb->delete(
			self::table(),
			array(
				'evento_id' => $evento_id,
				'grupo'     => Grupo::numero(),
			)
		);
	}

	private static function preparar( array $f ): array {
		$f['id']         = (int) $f['id'];
		$f['evento_id']  = (int) $f['evento_id'];
		$f['adulto']     = (bool) (int) $f['adulto'];
		$f['secciones']  = array_values( array_filter( explode( ',', (string) $f['secciones'] ) ) );
		$f['creado_por'] = (int) $f['creado_por'];
		$f['precio']     = round( (float) ( $f['precio'] ?? 0 ), 2 );
		foreach ( array( 'autorizacion_por', 'baja_por' ) as $k ) {
			$f[ $k ] = (int) ( $f[ $k ] ?? 0 );
		}
		foreach ( array( 'estado', 'seccion', 'como', 'motivo', 'autorizacion', 'autorizacion_version', 'autorizacion_huella', 'transporte_ida', 'transporte_vuelta', 'baja_motivo' ) as $k ) {
			$f[ $k ] = (string) ( $f[ $k ] ?? '' );
		}
		return $f;
	}
}
