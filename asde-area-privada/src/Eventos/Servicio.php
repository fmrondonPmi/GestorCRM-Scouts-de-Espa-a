<?php
namespace AsdeBalears\AreaPrivada\Eventos;

use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Auth\Ambito;
use AsdeBalears\AreaPrivada\Crm\CrmException;
use AsdeBalears\AreaPrivada\Crm\PersonaRepository;
use AsdeBalears\AreaPrivada\Domain\Persona;
use AsdeBalears\AreaPrivada\Domain\Secciones;

defined( 'ABSPATH' ) || exit;

/**
 * Eventos del grupo (v0.35, doc 43): quién crea y gestiona cada uno, sus datos, el
 * equipo dirigente y el presupuesto, y el paso de borrador a publicado.
 *
 * Quién gestiona un evento (P-141):
 * - de sección (una o varias): los scouters de esas secciones, Secretaría, Coordinación y
 *   las personas de su equipo;
 * - de grupo y externo: Coordinación (`asde_eventos_grupo`) y las personas de su equipo.
 * Tesorería (`asde_eventos_economia`) ve todos y valida su presupuesto: con coste, un evento
 * no se publica sin esa validación, y desde ella el precio por sección es fijo (P-145 bis).
 */
final class Servicio {

	public const CAP_GESTION  = 'asde_eventos_gestion';
	public const CAP_GRUPO    = 'asde_eventos_grupo';
	public const CAP_ECONOMIA = 'asde_eventos_economia';
	public const CAP_CONFIG   = 'asde_eventos_config';

	public const MAX_TEXTO  = 4000;
	public const MAX_PLAZAS = 999;

	/** @var array<int, string[]>|null Equipos del grupo (evento => nº Scouts de España), una lectura por petición. */
	private static ?array $equipos = null;

	public static function olvidar(): void {
		self::$equipos = null;
	}

	// ---------------------------------------------------------------------
	// Quién
	// ---------------------------------------------------------------------

	/** ¿Puede entrar en la pantalla de eventos? */
	public static function puede_entrar(): bool {
		return current_user_can( self::CAP_GESTION ) || current_user_can( self::CAP_ECONOMIA );
	}

	/** ¿Ve todos los eventos del grupo? Secretaría, Coordinación, Tesorería y administradores. */
	public static function vision_global( Ambito $a ): bool {
		return $a->vision_global() || current_user_can( self::CAP_GRUPO ) || current_user_can( self::CAP_ECONOMIA );
	}

	/** ¿Está en el equipo del evento? */
	public static function del_equipo( array $e, Ambito $a ): bool {
		if ( ! $a->persona ) {
			return false;
		}
		self::$equipos ??= InscripcionesTable::equipos_del_grupo();
		return in_array( $a->persona->num_socio_asde, self::$equipos[ $e['id'] ] ?? array(), true );
	}

	/** ¿Lo gestiona (editar, equipo, presupuesto, publicar)? */
	public static function en_gestion( array $e, Ambito $a ): bool {
		if ( ! current_user_can( self::CAP_GESTION ) ) {
			return false;
		}
		if ( current_user_can( self::CAP_GRUPO ) || self::del_equipo( $e, $a ) ) {
			return true;
		}
		if ( 'secciones' !== $e['ambito'] ) {
			return false;
		}
		return $a->vision_global() || (bool) array_intersect( $e['secciones'], $a->secciones_scouter() );
	}

	public static function ve( array $e, Ambito $a ): bool {
		return self::vision_global( $a ) || self::en_gestion( $e, $a );
	}

	/** @return array[] Los eventos que ve este ámbito, por fecha de inicio. */
	public static function de_agenda( Ambito $a ): array {
		return array_values( array_filter( EventosTable::todos(), static fn( array $e ) => self::ve( $e, $a ) ) );
	}

	/** Secciones que puede convocar: todas con visión del grupo; un scouter, las suyas. @return string[] */
	public static function secciones_para( Ambito $a ): array {
		$todas = Normativa::secciones_convocables();
		if ( current_user_can( self::CAP_GRUPO ) || $a->vision_global() ) {
			return $todas;
		}
		return array_values( array_intersect( $todas, $a->secciones_scouter() ) );
	}

	/** @return string[] Ámbitos que puede elegir al crear o editar. */
	public static function ambitos_para( Ambito $a ): array {
		if ( current_user_can( self::CAP_GRUPO ) ) {
			return array_keys( EventosTable::AMBITOS );
		}
		return current_user_can( self::CAP_GESTION ) ? array( 'secciones' ) : array();
	}

	public static function puede_crear( Ambito $a ): bool {
		return current_user_can( self::CAP_GESTION ) && ( current_user_can( self::CAP_GRUPO ) || (bool) self::secciones_para( $a ) );
	}

	public static function puede_editar( array $e, Ambito $a ): bool {
		return EventosTable::CANCELADO !== $e['estado'] && null === ( $e['cerrado_en'] ?? null ) && self::en_gestion( $e, $a );
	}

	/** El presupuesto se puede cambiar: es un borrador y Tesorería aún no lo ha validado. */
	public static function presupuesto_abierto( array $e ): bool {
		return EventosTable::BORRADOR === $e['estado'] && null === $e['validado_en'];
	}

	public static function puede_editar_presupuesto( array $e, Ambito $a ): bool {
		return $e['con_coste'] && self::presupuesto_abierto( $e ) && ( self::en_gestion( $e, $a ) || current_user_can( self::CAP_ECONOMIA ) );
	}

	public static function puede_pedir_validacion( array $e, Ambito $a ): bool {
		return $e['con_coste'] && self::presupuesto_abierto( $e ) && null === $e['validacion_pedida_en'] && self::en_gestion( $e, $a );
	}

	public static function puede_validar( array $e ): bool {
		return $e['con_coste'] && self::presupuesto_abierto( $e ) && current_user_can( self::CAP_ECONOMIA );
	}

	/** Reabrir el presupuesto validado: solo en borrador y mientras no haya nadie inscrito (el precio es fijo para quien se inscribe). */
	public static function puede_reabrir( array $e ): bool {
		return EventosTable::BORRADOR === $e['estado'] && null !== $e['validado_en'] && current_user_can( self::CAP_ECONOMIA ) && ! InscripcionesTable::hay_participantes( $e['id'] );
	}

	public static function puede_publicar( array $e, Ambito $a ): bool {
		return EventosTable::BORRADOR === $e['estado'] && ( ! $e['con_coste'] || null !== $e['validado_en'] ) && self::en_gestion( $e, $a );
	}

	public static function puede_despublicar( array $e, Ambito $a ): bool {
		return EventosTable::PUBLICADO === $e['estado'] && self::en_gestion( $e, $a ) && ! InscripcionesTable::hay_participantes( $e['id'] );
	}

	public static function puede_cancelar( array $e, Ambito $a ): bool {
		return EventosTable::CANCELADO !== $e['estado'] && self::en_gestion( $e, $a );
	}

	public static function puede_borrar( array $e, Ambito $a ): bool {
		return EventosTable::BORRADOR === $e['estado'] && self::en_gestion( $e, $a ) && ! InscripcionesTable::hay_participantes( $e['id'] );
	}

	/**
	 * Lo que le toca hacer (menú e inicio), sin consultar el CRM: los presupuestos que
	 * esperan a Tesorería.
	 *
	 * @return array{validar:int}
	 */
	public static function pendientes( Ambito $a ): array {
		$validar = 0;
		if ( current_user_can( self::CAP_ECONOMIA ) ) {
			foreach ( EventosTable::todos() as $e ) {
				$validar += (int) ( self::puede_validar( $e ) && null !== $e['validacion_pedida_en'] );
			}
		}
		return array( 'validar' => $validar );
	}

	// ---------------------------------------------------------------------
	// Datos del evento
	// ---------------------------------------------------------------------

	/**
	 * Lee y valida el formulario del evento.
	 *
	 * @param array      $in     Lo enviado (sin barras: wp_unslash).
	 * @param array|null $actual El evento que se edita (null al crear).
	 * @return array{datos:array, errores:string[]}
	 */
	public static function leer( array $in, Ambito $a, ?array $actual ): array {
		$cfg     = Config::de();
		$errores = array();
		$texto   = static fn( string $k, int $max ): string => mb_substr( sanitize_text_field( (string) ( $in[ $k ] ?? '' ) ), 0, $max );
		$largo   = static fn( string $k ): string => mb_substr( sanitize_textarea_field( (string) ( $in[ $k ] ?? '' ) ), 0, self::MAX_TEXTO );
		$entero  = static fn( string $k, int $max ): int => min( $max, max( 0, (int) ( $in[ $k ] ?? 0 ) ) );
		$si      = static fn( string $k ): bool => ! empty( $in[ $k ] );

		$tipo = sanitize_key( (string) ( $in['tipo'] ?? '' ) );
		if ( ! isset( Config::tipos_activos( $cfg )[ $tipo ] ) && ! ( $actual && $actual['tipo'] === $tipo ) ) {
			$errores[] = asde_t( 'Elige el tipo de actividad.' );
		}
		$nombre = $texto( 'nombre', 120 );
		if ( '' === $nombre ) {
			$errores[] = asde_t( 'Escribe el nombre del evento.' );
		}

		$ambito = sanitize_key( (string) ( $in['ambito'] ?? 'secciones' ) );
		if ( ! in_array( $ambito, self::ambitos_para( $a ), true ) && ! ( $actual && $actual['ambito'] === $ambito ) ) {
			$errores[] = asde_t( 'Los eventos de grupo y los externos los crea Coordinación.' );
			$ambito    = 'secciones';
		}
		$elegidas   = array_values( array_intersect( Normativa::secciones_convocables(), array_map( 'sanitize_key', (array) ( $in['secciones'] ?? array() ) ) ) );
		$permitidas = self::secciones_para( $a );
		// Con un evento ya creado, las secciones que tenía se pueden conservar aunque no sean de quien lo edita (p. ej. alguien del equipo).
		$conservar  = $actual ? $actual['secciones'] : array();
		if ( array_diff( $elegidas, $permitidas, $conservar ) ) {
			$errores[] = asde_t( 'Solo puedes convocar a las secciones en las que colaboras.' );
			$elegidas  = array_values( array_intersect( $elegidas, array_merge( $permitidas, $conservar ) ) );
		}
		if ( 'grupo' === $ambito && ! $elegidas ) {
			$elegidas = Secciones::EDUCATIVAS;
		}
		if ( ! $elegidas ) {
			$errores[] = asde_t( 'Elige al menos una sección convocada.' );
		}

		$fecha  = static fn( string $f, string $h ): ?string => preg_match( '/^\d{4}-\d{2}-\d{2}$/', $f ) && preg_match( '/^\d{2}:\d{2}$/', $h ) && date_create( $f . ' ' . $h ) ? $f . ' ' . $h . ':00' : null;
		$inicio = $fecha( (string) ( $in['inicio_fecha'] ?? '' ), (string) ( $in['inicio_hora'] ?? '' ) ?: '00:00' );
		$fin    = $fecha( (string) ( $in['fin_fecha'] ?? '' ), (string) ( $in['fin_hora'] ?? '' ) ?: '23:59' );
		if ( null === $inicio || null === $fin ) {
			$errores[] = asde_t( 'Indica la fecha y la hora de inicio y de fin.' );
		} elseif ( $fin < $inicio ) {
			$errores[] = asde_t( 'El evento no puede acabar antes de empezar.' );
		}

		$mapa = esc_url_raw( trim( (string) ( $in['mapa'] ?? '' ) ), array( 'http', 'https' ) );
		if ( '' !== trim( (string) ( $in['mapa'] ?? '' ) ) && '' === $mapa ) {
			$errores[] = asde_t( 'El enlace al mapa tiene que ser una dirección web (https://…).' );
		}

		$plazas = array();
		foreach ( $elegidas as $s ) {
			$plazas[ $s ] = array(
				'previstas' => $entero( 'previstas_' . $s, self::MAX_PLAZAS ),
				'maximas'   => $entero( 'maximas_' . $s, self::MAX_PLAZAS ),
			);
			if ( $plazas[ $s ]['maximas'] && $plazas[ $s ]['previstas'] > $plazas[ $s ]['maximas'] ) {
				$errores[] = sprintf( asde_t( 'En %s hay más participantes previstos que plazas.' ), Secciones::nombre( $s ) );
			}
		}

		$edad     = static fn( string $k ): ?int => '' === trim( (string) ( $in[ $k ] ?? '' ) ) ? null : min( 99, max( 0, (int) $in[ $k ] ) );
		$edad_min = $edad( 'edad_min' );
		$edad_max = $edad( 'edad_max' );
		if ( null !== $edad_min && null !== $edad_max && $edad_min > $edad_max ) {
			$errores[] = asde_t( 'La edad mínima no puede ser mayor que la máxima.' );
		}

		// Inscripción (v0.35): modalidad y plazo (sin «hasta», hasta que empieza el evento).
		$modalidad = isset( EventosTable::MODALIDADES[ $in['modalidad'] ?? '' ] ) ? (string) $in['modalidad'] : 'abierta';
		$dia       = static fn( string $k ): ?string => preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) ( $in[ $k ] ?? '' ) ) && date_create( (string) $in[ $k ] ) ? (string) $in[ $k ] : null;
		$desde     = $dia( 'inscripcion_desde' );
		$hasta     = $dia( 'inscripcion_hasta' );
		if ( $desde && $hasta && $hasta < $desde ) {
			$errores[] = asde_t( 'El plazo de inscripción no puede acabar antes de empezar.' );
		} elseif ( $hasta && null !== $inicio && $hasta > substr( $inicio, 0, 10 ) ) {
			$errores[] = asde_t( 'El plazo de inscripción tiene que acabar antes de que empiece el evento.' );
		}

		$normativa = Normativa::datos(
			array(
				'internacional'   => $si( 'internacional' ),
				'seguro_compania' => $texto( 'seguro_compania', 100 ),
				'seguro_poliza'   => $texto( 'seguro_poliza', 60 ),
				'riesgo'          => $si( 'riesgo' ),
				'riesgo_titulado' => $si( 'riesgo_titulado' ),
				'agua'            => $si( 'agua' ),
				'socorrista'      => $si( 'socorrista' ),
				'dependencia12'   => $entero( 'dependencia12', 99 ),
				'dependencia3'    => $entero( 'dependencia3', 99 ),
				'comunicacion'    => sanitize_key( (string) ( $in['comunicacion'] ?? '' ) ) ?: ( $cfg['tipos'][ $tipo ]['comunicacion'] ?? 'auto' ),
			)
		);
		$con_coste = $si( 'con_coste' );

		// Con el presupuesto validado, el precio es fijo: no se pueden cambiar las secciones, los participantes previstos ni si tiene coste.
		if ( $actual && null !== $actual['validado_en'] ) {
			$cambia = $elegidas !== $actual['secciones'] || $con_coste !== $actual['con_coste'];
			foreach ( $elegidas as $s ) {
				$cambia = $cambia || $plazas[ $s ]['previstas'] !== ( $actual['plazas'][ $s ]['previstas'] ?? 0 );
			}
			if ( $cambia ) {
				$errores[] = asde_t( 'El presupuesto ya está validado y el precio es fijo: para cambiar las secciones, los participantes previstos o si tiene coste, pide a Tesorería que reabra el presupuesto.' );
			}
		}

		return array(
			'datos'   => array(
				'tipo'        => $tipo,
				'ambito'      => $ambito,
				'nombre'      => $nombre,
				'inicio'      => (string) $inicio,
				'fin'         => (string) $fin,
				'lugar'       => $texto( 'lugar', 160 ),
				'mapa'        => $mapa,
				'salida'      => $texto( 'salida', 160 ),
				'llegada'     => $texto( 'llegada', 160 ),
				'descripcion' => $largo( 'descripcion' ),
				'material'    => $largo( 'material' ),
				'secciones'   => $elegidas,
				'plazas'      => $plazas,
				'plazas_max'  => $entero( 'plazas_max', self::MAX_PLAZAS * 10 ),
				'edad_min'    => $edad_min,
				'edad_max'    => $edad_max,
				'normativa'   => $normativa,
				'con_coste'   => $con_coste,
				'modalidad'   => $modalidad,
				'inscripcion_desde' => $desde,
				'inscripcion_hasta' => $hasta,
			),
			'errores' => $errores,
		);
	}

	/** @return int|\WP_Error Id del evento nuevo. */
	public static function crear( Ambito $a, array $in ) {
		if ( ! self::puede_crear( $a ) ) {
			return new \WP_Error( 'asde_evento', asde_t( 'No puedes crear eventos.' ) );
		}
		$v = self::leer( $in, $a, null );
		if ( $v['errores'] ) {
			return new \WP_Error( 'asde_evento', implode( ' ', $v['errores'] ), $v['errores'] );
		}
		$cfg  = Config::de();
		$id   = EventosTable::crear(
			$v['datos'] + array(
				'margen_activo' => $cfg['margen_activo'],
				'margen_pct'    => $cfg['margen_pct'],
			)
		);
		if ( ! $id ) {
			return new \WP_Error( 'asde_evento', asde_t( 'No se ha podido guardar el evento.' ) );
		}
		Logger::log(
			'evento_creado',
			'evento',
			(string) $id,
			array(
				'tipo'      => $v['datos']['tipo'],
				'ambito'    => $v['datos']['ambito'],
				'secciones' => $v['datos']['secciones'],
			)
		);
		return $id;
	}

	/** @return true|\WP_Error */
	public static function guardar( array $e, Ambito $a, array $in ) {
		if ( ! self::puede_editar( $e, $a ) ) {
			return new \WP_Error( 'asde_evento', asde_t( 'No puedes cambiar este evento.' ) );
		}
		$v = self::leer( $in, $a, $e );
		if ( $v['errores'] ) {
			return new \WP_Error( 'asde_evento', implode( ' ', $v['errores'] ), $v['errores'] );
		}
		$cambios = array();
		foreach ( $v['datos'] as $k => $valor ) {
			if ( $valor !== ( $e[ $k ] ?? null ) ) {
				$cambios[] = $k;
			}
		}
		EventosTable::guardar( $e['id'], $v['datos'] );
		Logger::log( 'evento_editado', 'evento', (string) $e['id'], array( 'campos' => $cambios ) );
		$nuevo = EventosTable::obtener( $e['id'] );
		if ( $nuevo ) {
			Inscripciones::revisar( $nuevo ); // Si hay más plazas, se ofrecen a la lista de espera.
		}
		return true;
	}

	// ---------------------------------------------------------------------
	// Equipo dirigente
	// ---------------------------------------------------------------------

	/**
	 * Quién puede estar en el equipo: los adultos del grupo con membresía de Scouter o de
	 * Voluntariado (de la instantánea, sin consultas por fila).
	 *
	 * @return array<string, Persona> nº => ficha, por nombre
	 */
	public static function candidatos(): array {
		$repo = PersonaRepository::make();
		if ( ! $repo ) {
			return array();
		}
		$out = array();
		try {
			$todas = $repo->todas();
		} catch ( CrmException $e ) {
			return array(); // Sin el CRM no se sabe quién es scouter: no se ofrece a nadie.
		}
		foreach ( $todas as $p ) {
			if ( $p->es_adulto_voluntario() ) {
				$out[ $p->num_socio_asde ] = $p;
			}
		}
		uasort( $out, static fn( Persona $x, Persona $y ) => strcoll( remove_accents( $x->nombre_completo() ), remove_accents( $y->nombre_completo() ) ) );
		return $out;
	}

	/** @return int|\WP_Error */
	public static function anadir_equipo( array $e, Ambito $a, string $socio, string $papel ) {
		if ( ! self::puede_editar( $e, $a ) ) {
			return new \WP_Error( 'asde_evento', asde_t( 'No puedes cambiar el equipo de este evento.' ) );
		}
		if ( ! isset( InscripcionesTable::PAPELES[ $papel ] ) ) {
			return new \WP_Error( 'asde_evento', asde_t( 'Elige qué hace en el equipo.' ) );
		}
		$p = self::candidatos()[ $socio ] ?? null;
		if ( ! $p ) {
			return new \WP_Error( 'asde_evento', asde_t( 'Elige a un scouter o voluntario/a del grupo.' ) );
		}
		if ( InscripcionesTable::de_socio( $e['id'], $socio ) ) {
			return new \WP_Error( 'asde_evento', sprintf( asde_t( '%s ya está en este evento.' ), $p->nombre_completo() ) );
		}
		$id = InscripcionesTable::anadir( $e['id'], $socio, $p->secciones, true, $papel );
		if ( ! $id ) {
			return new \WP_Error( 'asde_evento', asde_t( 'No se ha podido guardar.' ) );
		}
		self::olvidar();
		Logger::log( 'evento_equipo_anadido', 'evento', (string) $e['id'], array( 'papel' => $papel ) );
		return $id;
	}

	/** @return true|\WP_Error */
	public static function cambiar_papel( array $e, Ambito $a, int $fila, string $papel ) {
		$m = InscripcionesTable::obtener( $fila );
		if ( ! $m || $m['evento_id'] !== $e['id'] || ! isset( InscripcionesTable::PAPELES[ $m['papel'] ] ) || ! self::puede_editar( $e, $a ) ) {
			return new \WP_Error( 'asde_evento', asde_t( 'No puedes cambiar el equipo de este evento.' ) );
		}
		if ( ! isset( InscripcionesTable::PAPELES[ $papel ] ) ) {
			return new \WP_Error( 'asde_evento', asde_t( 'Elige qué hace en el equipo.' ) );
		}
		InscripcionesTable::cambiar_papel( $fila, $papel );
		Logger::log( 'evento_equipo_papel', 'evento', (string) $e['id'], array( 'papel' => $papel ) );
		return true;
	}

	/** @return true|\WP_Error */
	public static function quitar_equipo( array $e, Ambito $a, int $fila ) {
		$m = InscripcionesTable::obtener( $fila );
		if ( ! $m || $m['evento_id'] !== $e['id'] || ! isset( InscripcionesTable::PAPELES[ $m['papel'] ] ) || ! self::puede_editar( $e, $a ) ) {
			return new \WP_Error( 'asde_evento', asde_t( 'No puedes cambiar el equipo de este evento.' ) );
		}
		InscripcionesTable::quitar( $fila );
		self::olvidar();
		Logger::log( 'evento_equipo_quitado', 'evento', (string) $e['id'], array( 'papel' => $m['papel'] ) );
		return true;
	}

	/**
	 * Comprueba el equipo con la ficha del CRM (una lectura en vivo para todo el equipo).
	 * Si el CRM no responde, comprueba lo que se puede sin la ficha (director/a y ratio).
	 */
	public static function comprobar_equipo( array $e, array $equipo, array $personas ): array {
		$fichas = array();
		$repo   = PersonaRepository::make();
		if ( $equipo ) {
			try {
				$fichas = $repo ? $repo->campos_varias( array_column( $equipo, 'socio' ), Normativa::CAMPOS_EQUIPO ) : null;
			} catch ( CrmException $ex ) {
				$fichas = null;
			}
		}
		return Normativa::comprobar_equipo( $e, $equipo, $personas, $fichas, Config::de() );
	}

	// ---------------------------------------------------------------------
	// Presupuesto
	// ---------------------------------------------------------------------

	/** @return true|\WP_Error */
	public static function guardar_prevision( array $e, Ambito $a, int $equipo, bool $margen_activo, float $margen_pct ) {
		if ( ! self::puede_editar_presupuesto( $e, $a ) ) {
			return new \WP_Error( 'asde_evento', asde_t( 'El presupuesto de este evento no se puede cambiar.' ) );
		}
		EventosTable::guardar(
			$e['id'],
			array(
				'equipo_previsto' => min( self::MAX_PLAZAS, max( 0, $equipo ) ),
				'margen_activo'   => $margen_activo,
				'margen_pct'      => min( 50.0, max( 0.0, $margen_pct ) ),
			)
		);
		Logger::log( 'evento_presupuesto_guardado', 'evento', (string) $e['id'], array( 'campos' => array( 'equipo_previsto', 'margen_activo', 'margen_pct' ) ) );
		return true;
	}

	/** @return int|\WP_Error */
	public static function anadir_partida( array $e, Ambito $a, string $tipo, string $concepto, string $reparto, string $seccion, float $importe ) {
		if ( ! self::puede_editar_presupuesto( $e, $a ) ) {
			return new \WP_Error( 'asde_evento', asde_t( 'El presupuesto de este evento no se puede cambiar.' ) );
		}
		$concepto = trim( sanitize_text_field( $concepto ) );
		if ( '' === $concepto || $importe <= 0 ) {
			return new \WP_Error( 'asde_evento', asde_t( 'Escribe el concepto y un importe mayor que cero.' ) );
		}
		if ( PartidasTable::INGRESO === $tipo ) {
			$reparto = 'total';
			$seccion = '';
		} elseif ( ! isset( Presupuesto::REPARTOS[ $reparto ] ) ) {
			return new \WP_Error( 'asde_evento', asde_t( 'Elige cómo se reparte el gasto.' ) );
		}
		$con_seccion = in_array( $reparto, Presupuesto::CON_SECCION, true ) || 'participante' === $reparto;
		$seccion     = $con_seccion ? sanitize_key( $seccion ) : '';
		if ( '' !== $seccion && ! in_array( $seccion, $e['secciones'], true ) ) {
			return new \WP_Error( 'asde_evento', asde_t( 'Esa sección no está convocada.' ) );
		}
		if ( in_array( $reparto, Presupuesto::CON_SECCION, true ) && '' === $seccion ) {
			return new \WP_Error( 'asde_evento', asde_t( 'Elige la sección entre la que se reparte.' ) );
		}
		$id = PartidasTable::anadir( $e['id'], $tipo, $concepto, $reparto, $seccion, round( $importe, 2 ) );
		Logger::log(
			'evento_partida_anadida',
			'evento',
			(string) $e['id'],
			array(
				'tipo'    => $tipo,
				'reparto' => $reparto,
			)
		);
		return $id;
	}

	/**
	 * Añade el gasto de los autobuses: personas ÷ plazas autobuses, a un precio cada uno (repartido entre todos). v0.40 (doc 49).
	 *
	 * @return int|\WP_Error Id de la partida.
	 */
	public static function anadir_autobuses( array $e, Ambito $a, int $personas, int $plazas, float $precio ) {
		$n = Presupuesto::autobuses( $personas, $plazas );
		if ( $n < 1 || $precio <= 0 ) {
			return new \WP_Error( 'asde_evento', asde_t( 'Escribe cuántas personas viajan, las plazas de cada autobús y lo que cuesta uno.' ) );
		}
		/* translators: 1: nº de autobuses, 2: precio de cada uno */
		$concepto = sprintf( asde_t( 'Autobuses (%1$d × %2$s)' ), $n, \AsdeBalears\AreaPrivada\Tesoreria\Calculadora::euros( $precio ) );
		return self::anadir_partida( $e, $a, PartidasTable::GASTO, $concepto, 'total', '', $n * $precio );
	}

	/** @return true|\WP_Error */
	public static function quitar_partida( array $e, Ambito $a, int $partida ) {
		if ( ! self::puede_editar_presupuesto( $e, $a ) || ! PartidasTable::quitar( $partida, $e['id'] ) ) {
			return new \WP_Error( 'asde_evento', asde_t( 'El presupuesto de este evento no se puede cambiar.' ) );
		}
		Logger::log( 'evento_partida_quitada', 'evento', (string) $e['id'] );
		return true;
	}

	/** @return true|\WP_Error */
	public static function pedir_validacion( array $e, Ambito $a ) {
		if ( ! self::puede_pedir_validacion( $e, $a ) ) {
			return new \WP_Error( 'asde_evento', asde_t( 'Este presupuesto no se puede enviar a Tesorería.' ) );
		}
		$calculo = Presupuesto::del_evento( $e );
		if ( ! $calculo['ok'] ) {
			return new \WP_Error( 'asde_evento', implode( ' ', $calculo['errores'] ) );
		}
		if ( ! PartidasTable::previstas( $e['id'] ) ) {
			return new \WP_Error( 'asde_evento', asde_t( 'Añade al menos un gasto previsto.' ) );
		}
		EventosTable::pedir_validacion( $e['id'] );
		Logger::log( 'evento_presupuesto_enviado', 'evento', (string) $e['id'] );
		return true;
	}

	/** Tesorería valida: queda el cálculo y el precio por sección es fijo. @return array|\WP_Error El cálculo. */
	public static function validar( array $e ) {
		if ( ! self::puede_validar( $e ) ) {
			return new \WP_Error( 'asde_evento', asde_t( 'Validar el presupuesto lo hace Tesorería.' ) );
		}
		$calculo = Presupuesto::del_evento( $e );
		if ( ! $calculo['ok'] ) {
			return new \WP_Error( 'asde_evento', implode( ' ', $calculo['errores'] ) );
		}
		if ( ! PartidasTable::previstas( $e['id'] ) ) {
			return new \WP_Error( 'asde_evento', asde_t( 'Añade al menos un gasto previsto.' ) );
		}
		unset( $calculo['ok'], $calculo['errores'] );
		EventosTable::validar( $e['id'], $calculo );
		Logger::log(
			'evento_presupuesto_validado',
			'evento',
			(string) $e['id'],
			array(
				'precios' => $calculo['precios'],
				'margen'  => $calculo['margen'],
			)
		);
		return $calculo;
	}

	/** @return true|\WP_Error */
	public static function reabrir( array $e ) {
		if ( ! self::puede_reabrir( $e ) ) {
			return new \WP_Error( 'asde_evento', asde_t( 'Este presupuesto ya no se puede reabrir.' ) );
		}
		EventosTable::reabrir( $e['id'] );
		Logger::log( 'evento_presupuesto_reabierto', 'evento', (string) $e['id'] );
		return true;
	}

	// ---------------------------------------------------------------------
	// Estados
	// ---------------------------------------------------------------------

	/** @return true|\WP_Error */
	public static function publicar( array $e, Ambito $a ) {
		if ( ! self::puede_publicar( $e, $a ) ) {
			return new \WP_Error(
				'asde_evento',
				$e['con_coste'] && null === $e['validado_en'] && EventosTable::BORRADOR === $e['estado']
					? asde_t( 'Este evento tiene coste: antes de publicarlo, Tesorería tiene que validar su presupuesto.' )
					: asde_t( 'No puedes publicar este evento.' )
			);
		}
		EventosTable::publicar( $e['id'] );
		Logger::log( 'evento_publicado', 'evento', (string) $e['id'] );
		Avisos::programar( $e, 'publicado' ); // Email a las familias convocadas, en segundo plano (una sola vez).
		return true;
	}

	/** @return true|\WP_Error */
	public static function despublicar( array $e, Ambito $a ) {
		if ( ! self::puede_despublicar( $e, $a ) ) {
			return new \WP_Error( 'asde_evento', asde_t( 'Este evento ya no se puede volver a borrador.' ) );
		}
		EventosTable::despublicar( $e['id'] );
		Logger::log( 'evento_despublicado', 'evento', (string) $e['id'] );
		return true;
	}

	/** @return true|\WP_Error */
	public static function cancelar( array $e, Ambito $a, string $motivo ) {
		if ( ! self::puede_cancelar( $e, $a ) ) {
			return new \WP_Error( 'asde_evento', asde_t( 'No puedes cancelar este evento.' ) );
		}
		if ( '' === trim( $motivo ) ) {
			return new \WP_Error( 'asde_evento', asde_t( 'Indica el motivo de la cancelación.' ) );
		}
		EventosTable::cancelar( $e['id'], $motivo );
		Logger::log( 'evento_cancelado', 'evento', (string) $e['id'] );
		if ( EventosTable::PUBLICADO === $e['estado'] ) {
			Avisos::programar( $e, 'cancelado' ); // A quien estaba inscrito/a o en la lista de espera.
		}
		return true;
	}

	/** @return true|\WP_Error */
	public static function borrar( array $e, Ambito $a ) {
		if ( ! self::puede_borrar( $e, $a ) ) {
			return new \WP_Error( 'asde_evento', asde_t( 'Solo se puede borrar un evento en borrador.' ) );
		}
		PartidasTable::borrar_evento( $e['id'] );
		InscripcionesTable::borrar_evento( $e['id'] );
		TareasTable::borrar_de_evento( $e['id'] );
		\AsdeBalears\AreaPrivada\Menus\MenuTable::borrar_evento( $e['id'] );
		EventosTable::borrar( $e['id'] );
		self::olvidar();
		Logger::log( 'evento_borrado', 'evento', (string) $e['id'] );
		return true;
	}
}
