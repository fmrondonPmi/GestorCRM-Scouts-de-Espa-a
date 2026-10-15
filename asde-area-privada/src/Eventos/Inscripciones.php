<?php
namespace AsdeBalears\AreaPrivada\Eventos;

use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Auth\Ambito;
use AsdeBalears\AreaPrivada\Crm\CrmException;
use AsdeBalears\AreaPrivada\Crm\Crypto;
use AsdeBalears\AreaPrivada\Crm\PersonaRepository;
use AsdeBalears\AreaPrivada\Domain\Persona;

defined( 'ABSPATH' ) || exit;

/**
 * Inscripciones a los eventos (v0.19.1, doc 32, apartado 3).
 *
 * Quién inscribe a quién (P-64):
 * - la **cuenta familiar**, a sus menores: inscribir ya es firmar la autorización (P-65);
 * - el **asociado/a de 14 a 17**, a sí mismo/a: es una preinscripción y queda pendiente hasta
 *   que la familia firma desde su cuenta o entrega el papel;
 * - el **asociado/a mayor de edad** (el día que empieza el evento), a sí mismo/a, sin autorización;
 * - el **equipo** del evento (quien lo gestiona), a cualquiera del grupo, «en nombre de la
 *   familia»: la autorización llega en papel y quien la recibe la marca. Si la persona no es de
 *   una sección convocada o no tiene la edad, con un motivo (excepción, queda en la auditoría).
 *
 * Una inscripción está **confirmada** cuando tiene la autorización (o no le hace falta) y está
 * pagada (o el evento no tiene coste); hasta entonces, **pendiente**. Si no quedan plazas (en
 * total o en su sección), entra en la **lista de espera**: cuando se libera una, se ofrece por
 * orden con DIAS_OFERTA días para confirmarla; si no se confirma, se pierde y pasa a la siguiente.
 *
 * Lo que se guarda: el nº Scouts de España, la sección y el precio, la autorización (sin datos
 * personales: quién la firmó, cuándo, la versión del texto y una huella de la IP), el transporte
 * y, cifrado, quién recoge al menor y las observaciones. La salud no se copia nunca.
 */
final class Inscripciones {

	public const CAP = 'asde_eventos_inscribir';

	/** Desde esta edad un asociado/a puede preinscribirse (la de las cuentas propias, AltaService). */
	public const EDAD_PREINSCRIPCION = 14;

	/** Días para confirmar una plaza que se libera (supuesto del plan). */
	public const DIAS_OFERTA = 3;

	public const MAX_TEXTO = 1000;

	/** Motivo de la baja automática de una plaza ofrecida sin confirmar (se guarda en castellano y se traduce al enseñarlo). */
	public const MOTIVO_CADUCADA = 'No se confirmó a tiempo la plaza ofrecida';

	// ---------------------------------------------------------------------
	// Quién y para qué evento
	// ---------------------------------------------------------------------

	/** Personas cuyas inscripciones lleva esta cuenta: ella misma (si es socia activa) y los menores que representa. @return array<string, Persona> */
	public static function para_quien( Ambito $a ): array {
		$out = $a->representados;
		if ( $a->persona && $a->socio_activo() ) {
			$out = array( $a->persona->num_socio_asde => $a->persona ) + $out;
		}
		return $out;
	}

	/** Sección del evento por la que va: la primera convocada de las suyas, o null. */
	public static function seccion_de( array $e, Persona $p ): ?string {
		foreach ( $e['secciones'] as $s ) {
			if ( in_array( $s, $p->secciones, true ) ) {
				return $s;
			}
		}
		return null;
	}

	/** ¿Tiene la edad del evento el día que empieza? (sin límites, cualquiera). */
	public static function edad_ok( array $e, Persona $p ): bool {
		if ( null === $e['edad_min'] && null === $e['edad_max'] ) {
			return true;
		}
		$edad = Normativa::edad_en( $p, $e );
		return null !== $edad && ( null === $e['edad_min'] || $edad >= $e['edad_min'] ) && ( null === $e['edad_max'] || $edad <= $e['edad_max'] );
	}

	/** ¿Está convocada (socia activa de una sección convocada y con la edad)? */
	public static function convocada( array $e, Persona $p ): bool {
		return $p->tiene_membresia_activa() && null !== self::seccion_de( $e, $p ) && self::edad_ok( $e, $p );
	}

	/** ¿Es mayor de edad el día que empieza el evento? */
	public static function adulta_en( array $e, Persona $p ): bool {
		$edad = Normativa::edad_en( $p, $e );
		return null !== $edad && $edad >= 18;
	}

	/** Precio de una sección: el validado por Tesorería (fijo, P-67 bis); 0 si el evento no tiene coste. */
	public static function precio( array $e, string $seccion ): float {
		if ( ! $e['con_coste'] || ! is_array( $e['presupuesto'] ) ) {
			return 0.0;
		}
		$precios = array_map( 'floatval', (array) ( $e['presupuesto']['precios'] ?? array() ) );
		// Una excepción de otra sección paga el precio más alto del evento (Tesorería lo puede ajustar).
		return round( $precios[ $seccion ] ?? ( $precios ? max( $precios ) : 0.0 ), 2 );
	}

	// ---------------------------------------------------------------------
	// Plazo y plazas
	// ---------------------------------------------------------------------

	/** Cuándo se abre la inscripción (marca de tiempo; 0 = al publicar). */
	public static function abre( array $e ): int {
		return null !== $e['inscripcion_desde'] ? EventosTable::marca( $e['inscripcion_desde'] . ' 00:00:00' ) : 0;
	}

	/** Cuándo se cierra: al acabar el día «hasta» o, si no hay, al empezar el evento (lo que llegue antes). */
	public static function cierra( array $e ): int {
		$inicio = EventosTable::marca( $e['inicio'] );
		return null !== $e['inscripcion_hasta'] ? min( $inicio, EventosTable::marca( $e['inscripcion_hasta'] . ' 23:59:59' ) ) : $inicio;
	}

	/** Plazo de las familias: 'no' (sin publicar o cancelado), 'antes', 'abierta' o 'cerrada'. */
	public static function plazo( array $e, ?int $ahora = null ): string {
		if ( EventosTable::PUBLICADO !== $e['estado'] ) {
			return 'no';
		}
		$ahora = $ahora ?? time();
		if ( $ahora < self::abre( $e ) ) {
			return 'antes';
		}
		return $ahora < self::cierra( $e ) ? 'abierta' : 'cerrada';
	}

	/** El equipo puede inscribir y dar de baja hasta que acaba el evento. */
	public static function gestionable( array $e, ?int $ahora = null ): bool {
		return EventosTable::PUBLICADO === $e['estado'] && ( $ahora ?? time() ) < EventosTable::marca( $e['fin'] );
	}

	/** ¿Tiene una plaza ofrecida que aún no ha caducado? */
	public static function con_oferta( array $f, ?int $ahora = null ): bool {
		return InscripcionesTable::ESPERA === $f['estado'] && null !== $f['oferta_hasta'] && self::utc( (string) $f['oferta_hasta'] ) >= ( $ahora ?? time() );
	}

	/** Estado que se enseña: el guardado o «plaza» si tiene una plaza ofrecida. */
	public static function estado( array $f, ?int $ahora = null ): string {
		return self::con_oferta( $f, $ahora ) ? 'plaza' : $f['estado'];
	}

	/** Plazas ocupadas: inscripciones pendientes o confirmadas y plazas ofrecidas. @return array{total:int, seccion:array<string,int>} */
	public static function ocupadas( array $filas, ?int $ahora = null ): array {
		$out = array(
			'total'   => 0,
			'seccion' => array(),
		);
		foreach ( $filas as $f ) {
			if ( in_array( $f['estado'], InscripcionesTable::ACTIVAS, true ) || self::con_oferta( $f, $ahora ) ) {
				++$out['total'];
				$out['seccion'][ $f['seccion'] ] = ( $out['seccion'][ $f['seccion'] ] ?? 0 ) + 1;
			}
		}
		return $out;
	}

	/** ¿Queda plaza para alguien de esta sección? (sin contar la fila $excluir). */
	public static function hay_plaza( array $e, array $filas, string $seccion, int $excluir = 0 ): bool {
		$oc    = self::ocupadas( array_filter( $filas, static fn( array $f ) => $f['id'] !== $excluir ) );
		$max_s = (int) ( $e['plazas'][ $seccion ]['maximas'] ?? 0 );
		if ( $max_s && ( $oc['seccion'][ $seccion ] ?? 0 ) >= $max_s ) {
			return false;
		}
		return ! $e['plazas_max'] || $oc['total'] < $e['plazas_max'];
	}

	/** Puesto en la lista de espera (1 = la siguiente), o 0 si no está en ella. */
	public static function puesto( array $f, array $filas ): int {
		if ( InscripcionesTable::ESPERA !== $f['estado'] ) {
			return 0;
		}
		$lista = self::lista_espera( $filas );
		$pos   = array_search( $f['id'], array_column( $lista, 'id' ), true );
		return false === $pos ? 0 : $pos + 1;
	}

	/** @return array[] Lista de espera por orden de llegada. */
	public static function lista_espera( array $filas ): array {
		$lista = array_values( array_filter( $filas, static fn( array $f ) => InscripcionesTable::ESPERA === $f['estado'] ) );
		usort( $lista, static fn( $x, $y ) => strcmp( (string) $x['espera_en'], (string) $y['espera_en'] ) ?: $x['id'] <=> $y['id'] );
		return $lista;
	}

	/** Lo que le falta a una inscripción pendiente: 'autorizacion' y 'pago'. @return string[] */
	public static function falta( array $f, array $cobro ): array {
		$out = array();
		if ( '' === $f['autorizacion'] ) {
			$out[] = 'autorizacion';
		}
		if ( $cobro['pendiente'] > 0 ) {
			$out[] = 'pago';
		}
		return $out;
	}

	// ---------------------------------------------------------------------
	// Desde «Mis eventos» (la familia o la propia persona)
	// ---------------------------------------------------------------------

	/**
	 * Inscribe a una persona de esta cuenta.
	 *
	 * @param array $in transporte_ida, transporte_vuelta, recoge, observaciones, salud ('1') y, la familia, acepto ('1').
	 * @return array|\WP_Error La inscripción.
	 */
	public static function inscribir( array $e, Ambito $a, string $num, array $in ) {
		$p = self::para_quien( $a )[ $num ] ?? null;
		if ( ! current_user_can( self::CAP ) || ! $p ) {
			return new \WP_Error( 'asde_inscripcion', asde_t( 'No puedes inscribir a esa persona.' ) );
		}
		if ( 'abierta' !== self::plazo( $e ) ) {
			return new \WP_Error( 'asde_inscripcion', asde_t( 'La inscripción de este evento no está abierta.' ) );
		}
		if ( ! self::convocada( $e, $p ) ) {
			return new \WP_Error( 'asde_inscripcion', asde_t( 'Este evento no es para su sección o su edad.' ) );
		}
		$error = self::ya_esta( $e, $num );
		if ( $error ) {
			return $error;
		}
		$familia = isset( $a->representados[ $num ] );
		$adulta  = self::adulta_en( $e, $p );
		$edad    = Normativa::edad_en( $p, $e );
		if ( ! $familia && ! $adulta && ( null === $edad || $edad < self::EDAD_PREINSCRIPCION ) ) {
			return new \WP_Error( 'asde_inscripcion', asde_t( 'Te tiene que inscribir tu familia desde su cuenta.' ) );
		}
		if ( empty( $in['salud'] ) ) {
			return new \WP_Error( 'asde_inscripcion', asde_t( 'Confirma que has revisado la ficha de salud y alimentación y que está al día.' ) );
		}
		if ( $familia && ! $adulta && empty( $in['acepto'] ) ) {
			return new \WP_Error( 'asde_inscripcion', asde_t( 'Para inscribir hay que aceptar la autorización de participación.' ) );
		}
		$datos = self::leer( $e, $num, $in ) + array( 'salud_revisada' => current_time( 'mysql', true ) );
		if ( $adulta ) {
			$datos['autorizacion'] = 'no';
		} elseif ( $familia ) {
			$datos += self::firma();
		}
		return self::alta( $e, $p, $familia ? 'familia' : 'propia', $datos, '' );
	}

	/** En una convocatoria, la familia (o la persona) responde que no va. @return true|\WP_Error */
	public static function no_participa( array $e, Ambito $a, string $num ) {
		$p = self::para_quien( $a )[ $num ] ?? null;
		if ( ! current_user_can( self::CAP ) || ! $p || 'convocatoria' !== $e['modalidad'] || 'abierta' !== self::plazo( $e ) || ! self::convocada( $e, $p ) ) {
			return new \WP_Error( 'asde_inscripcion', asde_t( 'Ahora no se puede responder a esta convocatoria.' ) );
		}
		$error = self::ya_esta( $e, $num );
		if ( $error ) {
			return $error;
		}
		$id = InscripcionesTable::inscribir(
			$e['id'],
			$num,
			array(
				'secciones' => $p->secciones,
				'adulto'    => self::adulta_en( $e, $p ) ? 1 : 0,
				'seccion'   => (string) self::seccion_de( $e, $p ),
				'precio'    => 0,
				'estado'    => InscripcionesTable::NO_PARTICIPA,
				'como'      => isset( $a->representados[ $num ] ) ? 'familia' : 'propia',
			)
		);
		if ( ! $id ) {
			return new \WP_Error( 'asde_inscripcion', asde_t( 'No se ha podido guardar la respuesta.' ) );
		}
		Logger::log( 'inscripcion_no_participa', 'evento', (string) $e['id'], array( 'inscripcion' => $id ) );
		return true;
	}

	/** La familia firma la autorización de una preinscripción (o de una inscripción del equipo). @return true|\WP_Error */
	public static function firmar( array $e, Ambito $a, int $fila, bool $acepto ) {
		$f = self::fila( $e, $fila );
		if ( ! current_user_can( self::CAP ) || ! $f || ! isset( $a->representados[ $f['socio'] ] ) || '' !== $f['autorizacion'] || ! in_array( $f['estado'], array( InscripcionesTable::PENDIENTE, InscripcionesTable::ESPERA ), true ) || ! self::gestionable( $e ) ) {
			return new \WP_Error( 'asde_inscripcion', asde_t( 'Esta autorización no se puede firmar.' ) );
		}
		if ( ! $acepto ) {
			return new \WP_Error( 'asde_inscripcion', asde_t( 'Para firmar hay que aceptar la autorización de participación.' ) );
		}
		$firma = self::firma();
		InscripcionesTable::actualizar( $f['id'], $firma );
		Logger::log(
			'autorizacion_firmada',
			'evento',
			(string) $e['id'],
			array(
				'inscripcion' => $f['id'],
				'version'     => $firma['autorizacion_version'],
			)
		);
		self::recalcular( $e, $f['id'] );
		return true;
	}

	/** Confirma la plaza que se le ha ofrecido desde la lista de espera. @return true|\WP_Error */
	public static function confirmar_plaza( array $e, Ambito $a, int $fila ) {
		$f = self::fila( $e, $fila );
		if ( ! current_user_can( self::CAP ) || ! $f || ! isset( self::para_quien( $a )[ $f['socio'] ] ) || ! self::con_oferta( $f ) ) {
			return new \WP_Error( 'asde_inscripcion', asde_t( 'Esa plaza ya no está disponible.' ) );
		}
		InscripcionesTable::actualizar(
			$f['id'],
			array(
				'estado'       => InscripcionesTable::PENDIENTE,
				'oferta_hasta' => null,
				'espera_en'    => null,
			)
		);
		Logger::log( 'inscripcion_plaza_aceptada', 'evento', (string) $e['id'], array( 'inscripcion' => $f['id'] ) );
		self::recalcular( $e, $f['id'] );
		return true;
	}

	// ---------------------------------------------------------------------
	// Desde la pestaña «Inscripciones» (el equipo del evento)
	// ---------------------------------------------------------------------

	/**
	 * El equipo inscribe a alguien del grupo en nombre de su familia: la autorización
	 * llega en papel (si es menor). Fuera de las secciones convocadas o de la edad, con motivo.
	 *
	 * @return array|\WP_Error La inscripción.
	 */
	public static function inscribir_gestor( array $e, Ambito $a, string $num, array $in ) {
		if ( ! Servicio::en_gestion( $e, $a ) ) {
			return new \WP_Error( 'asde_inscripcion', asde_t( 'No puedes inscribir en este evento.' ) );
		}
		if ( ! self::gestionable( $e ) ) {
			return new \WP_Error( 'asde_inscripcion', asde_t( 'Solo se puede inscribir en un evento publicado que aún no ha acabado.' ) );
		}
		$repo = PersonaRepository::make();
		try {
			$p = $repo && '' !== $num ? $repo->obtener( $num ) : null;
		} catch ( CrmException $ex ) {
			return new \WP_Error( 'asde_inscripcion', asde_t( 'No se ha podido consultar el CRM: inténtalo dentro de un rato.' ) );
		}
		if ( ! $p || ! $p->tiene_membresia_activa() ) {
			return new \WP_Error( 'asde_inscripcion', asde_t( 'Elige a una persona del grupo.' ) );
		}
		$motivo = mb_substr( trim( sanitize_text_field( (string) ( $in['motivo'] ?? '' ) ) ), 0, 255 );
		if ( ! self::convocada( $e, $p ) && '' === $motivo ) {
			return new \WP_Error( 'asde_inscripcion', asde_t( 'No es de una sección convocada o no tiene la edad del evento: escribe el motivo de la excepción.' ) );
		}
		$error = self::ya_esta( $e, $num );
		if ( $error ) {
			return $error;
		}
		$datos = self::leer( $e, $num, $in );
		if ( self::adulta_en( $e, $p ) ) {
			$datos['autorizacion'] = 'no';
		}
		return self::alta( $e, $p, 'gestor', $datos, self::convocada( $e, $p ) ? '' : $motivo );
	}

	/** El equipo marca que ha recibido la autorización en papel. @return true|\WP_Error */
	public static function papel( array $e, Ambito $a, int $fila ) {
		$f = self::fila( $e, $fila );
		if ( ! $f || ! Servicio::en_gestion( $e, $a ) || '' !== $f['autorizacion'] || ! in_array( $f['estado'], array( InscripcionesTable::PENDIENTE, InscripcionesTable::ESPERA ), true ) ) {
			return new \WP_Error( 'asde_inscripcion', asde_t( 'No se puede marcar esta autorización.' ) );
		}
		InscripcionesTable::actualizar(
			$f['id'],
			array(
				'autorizacion'     => 'papel',
				'autorizacion_por' => get_current_user_id(),
				'autorizacion_en'  => current_time( 'mysql', true ),
			)
		);
		Logger::log( 'autorizacion_papel', 'evento', (string) $e['id'], array( 'inscripcion' => $f['id'] ) );
		self::recalcular( $e, $f['id'] );
		return true;
	}

	// ---------------------------------------------------------------------
	// Bajas
	// ---------------------------------------------------------------------

	/**
	 * Da de baja una inscripción (o la saca de la lista de espera). La familia o la propia
	 * persona, mientras el plazo está abierto; el equipo, hasta que acaba el evento y con motivo.
	 * Lo que se haya pagado se queda registrado: la devolución la decide Tesorería (P-67).
	 *
	 * @param bool $gestion Desde la pestaña del equipo (pide motivo) o desde «Mis eventos».
	 * @return true|\WP_Error
	 */
	public static function baja( array $e, Ambito $a, int $fila, string $motivo, bool $gestion ) {
		$f = self::fila( $e, $fila );
		if ( ! $f || ! in_array( $f['estado'], array( InscripcionesTable::PENDIENTE, InscripcionesTable::CONFIRMADA, InscripcionesTable::ESPERA ), true ) ) {
			return new \WP_Error( 'asde_inscripcion', asde_t( 'Esta inscripción no se puede dar de baja.' ) );
		}
		$motivo = mb_substr( trim( sanitize_text_field( $motivo ) ), 0, 255 );
		if ( $gestion ) {
			if ( ! Servicio::en_gestion( $e, $a ) || ! self::gestionable( $e ) ) {
				return new \WP_Error( 'asde_inscripcion', asde_t( 'No puedes dar de baja en este evento.' ) );
			}
			if ( '' === $motivo ) {
				return new \WP_Error( 'asde_inscripcion', asde_t( 'Indica el motivo de la baja.' ) );
			}
		} else {
			if ( ! current_user_can( self::CAP ) || ! isset( self::para_quien( $a )[ $f['socio'] ] ) ) {
				return new \WP_Error( 'asde_inscripcion', asde_t( 'Esta inscripción no se puede dar de baja.' ) );
			}
			if ( 'abierta' !== self::plazo( $e ) ) {
				return new \WP_Error( 'asde_inscripcion', asde_t( 'El plazo de inscripción ya se ha cerrado: para la baja, habla con el equipo del evento.' ) );
			}
		}
		InscripcionesTable::actualizar(
			$f['id'],
			array(
				'estado'       => InscripcionesTable::BAJA,
				'oferta_hasta' => null,
				'baja_por'     => get_current_user_id(),
				'baja_en'      => current_time( 'mysql', true ),
				'baja_motivo'  => $motivo,
			)
		);
		Logger::log(
			'inscripcion_baja',
			'evento',
			(string) $e['id'],
			array(
				'inscripcion' => $f['id'],
				'antes'       => $f['estado'],
				'gestion'     => $gestion,
			)
		);
		self::revisar( $e ); // La plaza que queda libre se ofrece a la lista de espera.
		return true;
	}

	// ---------------------------------------------------------------------
	// Estado, lista de espera y recuentos
	// ---------------------------------------------------------------------

	/**
	 * Pasa una inscripción de pendiente a confirmada (o al revés, si se anula un pago) según
	 * su autorización y lo que queda por pagar. La primera confirmación sale por email.
	 */
	public static function recalcular( array $e, int $fila ): ?array {
		$f = InscripcionesTable::obtener( $fila );
		if ( ! $f || ! in_array( $f['estado'], InscripcionesTable::ACTIVAS, true ) ) {
			return $f;
		}
		$cobro = Cobros::de( $f, MovimientosTable::de_inscripciones( array( $f['id'] ) )[ $f['id'] ] ?? array() );
		$nuevo = '' !== $f['autorizacion'] && $cobro['pendiente'] <= 0 ? InscripcionesTable::CONFIRMADA : InscripcionesTable::PENDIENTE;
		if ( $nuevo === $f['estado'] ) {
			return $f;
		}
		$primera = InscripcionesTable::CONFIRMADA === $nuevo && null === $f['confirmada_en'];
		InscripcionesTable::actualizar( $f['id'], array( 'estado' => $nuevo ) + ( $primera ? array( 'confirmada_en' => current_time( 'mysql', true ) ) : array() ) );
		Logger::log( 'inscripcion_' . $nuevo, 'evento', (string) $e['id'], array( 'inscripcion' => $f['id'] ) );
		$f = InscripcionesTable::obtener( $fila );
		if ( $primera && $f ) {
			Avisos::confirmada( $e, $f );
		}
		return $f;
	}

	/**
	 * Mantiene la lista de espera al día: las plazas ofrecidas que no se han confirmado a tiempo
	 * se pierden (baja) y las plazas libres se ofrecen por orden. Se llama al mirar el evento, al
	 * dar de baja y cada día (WP-Cron).
	 */
	public static function revisar( array $e, ?int $ahora = null ): void {
		if ( EventosTable::PUBLICADO !== $e['estado'] ) {
			return;
		}
		$ahora = $ahora ?? time();
		foreach ( InscripcionesTable::participantes( $e['id'] ) as $f ) {
			if ( InscripcionesTable::ESPERA === $f['estado'] && null !== $f['oferta_hasta'] && self::utc( (string) $f['oferta_hasta'] ) < $ahora ) {
				InscripcionesTable::actualizar(
					$f['id'],
					array(
						'estado'       => InscripcionesTable::BAJA,
						'oferta_hasta' => null,
						'baja_por'     => 0,
						'baja_en'      => current_time( 'mysql', true ),
						'baja_motivo'  => self::MOTIVO_CADUCADA,
					)
				);
				Logger::log( 'inscripcion_oferta_caducada', 'evento', (string) $e['id'], array( 'inscripcion' => $f['id'] ) );
			}
		}
		$inicio = EventosTable::marca( $e['inicio'] );
		if ( $ahora >= $inicio ) {
			return; // Empezado el evento, ya no se ofrecen plazas.
		}
		foreach ( self::lista_espera( InscripcionesTable::participantes( $e['id'] ) ) as $f ) {
			if ( null !== $f['oferta_hasta'] ) {
				continue;
			}
			if ( ! self::hay_plaza( $e, InscripcionesTable::participantes( $e['id'] ), $f['seccion'] ) ) {
				continue;
			}
			$hasta = min( $ahora + self::DIAS_OFERTA * DAY_IN_SECONDS, $inicio );
			InscripcionesTable::actualizar( $f['id'], array( 'oferta_hasta' => gmdate( 'Y-m-d H:i:s', $hasta ) ) );
			Logger::log( 'inscripcion_plaza_ofrecida', 'evento', (string) $e['id'], array( 'inscripcion' => $f['id'] ) );
			$f = InscripcionesTable::obtener( $f['id'] );
			if ( $f ) {
				Avisos::plaza( $e, $f );
			}
		}
	}

	/**
	 * Lo que espera a esta cuenta en «Mis eventos» (menú e inicio; sin consultar el CRM):
	 * autorizaciones por firmar, plazas ofrecidas y convocatorias sin responder.
	 *
	 * @return array{firmar:int, plaza:int, responder:int}
	 */
	public static function pendientes( Ambito $a ): array {
		$out   = array(
			'firmar'    => 0,
			'plaza'     => 0,
			'responder' => 0,
		);
		$quien = self::para_quien( $a );
		if ( ! $quien || ! current_user_can( self::CAP ) ) {
			return $out;
		}
		$eventos = array();
		foreach ( EventosTable::todos() as $e ) {
			if ( EventosTable::PUBLICADO === $e['estado'] && time() < EventosTable::marca( $e['fin'] ) ) {
				$eventos[ $e['id'] ] = $e;
			}
		}
		$tiene = array();
		foreach ( InscripcionesTable::de_socios( array_keys( $quien ) ) as $f ) {
			if ( ! isset( $eventos[ $f['evento_id'] ] ) ) {
				continue;
			}
			$tiene[ $f['evento_id'] . '|' . $f['socio'] ] = true;
			$out['plaza']  += (int) self::con_oferta( $f );
			$out['firmar'] += (int) ( isset( $a->representados[ $f['socio'] ] ) && '' === $f['autorizacion'] && in_array( $f['estado'], array( InscripcionesTable::PENDIENTE, InscripcionesTable::ESPERA ), true ) );
		}
		foreach ( $eventos as $e ) {
			if ( 'convocatoria' !== $e['modalidad'] || 'abierta' !== self::plazo( $e ) ) {
				continue;
			}
			foreach ( $quien as $num => $p ) {
				$out['responder'] += (int) ( ! isset( $tiene[ $e['id'] . '|' . $num ] ) && self::convocada( $e, $p ) && self::puede_inscribirse_sola( $a, $e, $p ) );
			}
		}
		return $out;
	}

	/**
	 * Autorizaciones que el equipo tiene que recoger en papel en los eventos que gestiona
	 * (inscripciones de menores sin firmar). Para el inicio, sin consultar el CRM.
	 */
	public static function por_recoger( Ambito $a ): int {
		if ( ! current_user_can( Servicio::CAP_GESTION ) ) {
			return 0;
		}
		$eventos = array();
		foreach ( EventosTable::todos() as $e ) {
			$eventos[ $e['id'] ] = $e;
		}
		$n = 0;
		foreach ( InscripcionesTable::con_estado( array( InscripcionesTable::PENDIENTE ) ) as $f ) {
			$e  = $eventos[ $f['evento_id'] ] ?? null;
			$n += (int) ( $e && '' === $f['autorizacion'] && self::gestionable( $e ) && Servicio::en_gestion( $e, $a ) );
		}
		return $n;
	}

	/**
	 * Eventos de «Mis eventos»: los publicados que no han acabado a los que está convocada
	 * alguna de sus personas o en los que ya tiene una inscripción, y los cancelados en los que
	 * estaba inscrita (para que se entere).
	 *
	 * @return array[] [ evento, personas => [ nº => [ persona, fila|null, convocada ] ] ]
	 */
	public static function mis_eventos( Ambito $a ): array {
		$quien = self::para_quien( $a );
		if ( ! $quien ) {
			return array();
		}
		$filas = array();
		foreach ( InscripcionesTable::de_socios( array_keys( $quien ) ) as $f ) {
			$filas[ $f['evento_id'] ][ $f['socio'] ] = $f;
		}
		$out = array();
		foreach ( EventosTable::todos() as $e ) {
			if ( time() >= EventosTable::marca( $e['fin'] ) ) {
				continue;
			}
			$publicado = EventosTable::PUBLICADO === $e['estado'];
			$cancelado = EventosTable::CANCELADO === $e['estado'] && null !== $e['publicado_en'];
			if ( ! $publicado && ! $cancelado ) {
				continue;
			}
			$personas = array();
			foreach ( $quien as $num => $p ) {
				$f         = $filas[ $e['id'] ][ $num ] ?? null;
				$convocada = $publicado && self::convocada( $e, $p );
				$activa    = $f && in_array( $f['estado'], array( InscripcionesTable::PENDIENTE, InscripcionesTable::CONFIRMADA, InscripcionesTable::ESPERA ), true );
				if ( ( $publicado && ( $convocada || $f ) ) || ( $cancelado && $activa ) ) {
					$personas[ $num ] = array(
						'persona'   => $p,
						'fila'      => $f,
						'convocada' => $convocada,
					);
				}
			}
			if ( $personas ) {
				$out[] = array(
					'evento'   => $e,
					'personas' => $personas,
				);
			}
		}
		return $out;
	}

	/**
	 * ¿Sale «Mis eventos» en su menú? A las familias y a los asociados de una sección
	 * educativa, siempre; a los scouters, solo si hay algún evento para ellos (una formación).
	 */
	public static function en_menu( Ambito $a ): bool {
		if ( ! current_user_can( self::CAP ) || ! self::para_quien( $a ) ) {
			return false;
		}
		if ( $a->representados || ( $a->persona && $a->persona->es_participante() ) ) {
			return true;
		}
		return (bool) self::mis_eventos( $a );
	}

	/** ¿Puede esta cuenta inscribir a esta persona ella sola? (la familia, o la propia persona de 14 años o más). */
	public static function puede_inscribirse_sola( Ambito $a, array $e, Persona $p ): bool {
		if ( isset( $a->representados[ $p->num_socio_asde ] ) ) {
			return true;
		}
		$edad = Normativa::edad_en( $p, $e );
		return null !== $edad && $edad >= self::EDAD_PREINSCRIPCION;
	}

	/** Recuento para la normativa (ratio y comunicación): participantes y menores inscritos, sin consultar el CRM. @return array{total:int, menores:int, cerrado:bool} */
	public static function recuento( array $e, array $filas ): array {
		$total   = 0;
		$menores = 0;
		foreach ( $filas as $f ) {
			if ( in_array( $f['estado'], InscripcionesTable::ACTIVAS, true ) ) {
				++$total;
				$menores += (int) ! $f['adulto'];
			}
		}
		return array(
			'total'   => $total,
			'menores' => $menores,
			'cerrado' => 'abierta' !== self::plazo( $e ) && 'antes' !== self::plazo( $e ),
		);
	}

	/** Quién recoge y observaciones (descifrados). @return array{recoge:string, observaciones:string} */
	public static function datos( array $f ): array {
		$vacio = array(
			'recoge'        => '',
			'observaciones' => '',
		);
		if ( empty( $f['datos_enc'] ) ) {
			return $vacio;
		}
		$json = Crypto::decrypt_contexto( (string) $f['datos_enc'], self::contexto( $f['evento_id'], $f['socio'] ) );
		$d    = null !== $json ? json_decode( $json, true ) : null;
		return is_array( $d ) ? array_map( 'strval', array_intersect_key( $d, $vacio ) ) + $vacio : $vacio;
	}

	// ---------------------------------------------------------------------
	// Internas
	// ---------------------------------------------------------------------

	/** Alta común: sección, precio, plaza o lista de espera, y la confirmación si ya está todo. @return array|\WP_Error */
	private static function alta( array $e, Persona $p, string $como, array $datos, string $motivo ) {
		$filas   = array_filter( InscripcionesTable::participantes( $e['id'] ), static fn( array $f ) => $f['socio'] !== $p->num_socio_asde );
		$propia  = self::seccion_de( $e, $p );
		// Una excepción (de otra sección) ocupa plaza en la primera sección convocada y paga el precio más alto.
		$seccion = $propia ?? (string) ( $e['secciones'][0] ?? '' );
		$espera  = ! self::hay_plaza( $e, $filas, $seccion );
		$id      = InscripcionesTable::inscribir(
			$e['id'],
			$p->num_socio_asde,
			array_merge(
				$datos,
				array(
					'secciones' => $p->secciones,
					'adulto'    => self::adulta_en( $e, $p ) ? 1 : 0,
					'seccion'   => $seccion,
					'precio'    => self::precio( $e, $propia ?? '' ),
					'estado'    => $espera ? InscripcionesTable::ESPERA : InscripcionesTable::PENDIENTE,
					'como'      => $como,
					'motivo'    => $motivo,
					'espera_en' => $espera ? current_time( 'mysql', true ) : null,
				)
			)
		);
		if ( ! $id ) {
			return new \WP_Error( 'asde_inscripcion', asde_t( 'No se ha podido guardar la inscripción.' ) );
		}
		Logger::log(
			$espera ? 'inscripcion_espera' : 'inscripcion_creada',
			'evento',
			(string) $e['id'],
			array(
				'inscripcion'  => $id,
				'como'         => $como,
				'autorizacion' => (string) ( $datos['autorizacion'] ?? '' ),
				'excepcion'    => '' !== $motivo,
				'campos'       => array_keys( array_filter( array_intersect_key( $datos, array_flip( array( 'transporte_ida', 'transporte_vuelta', 'datos_enc' ) ) ) ) ),
			)
		);
		if ( ! $espera ) {
			self::recalcular( $e, $id );
		}
		return InscripcionesTable::obtener( $id ) ?? new \WP_Error( 'asde_inscripcion', asde_t( 'No se ha podido guardar la inscripción.' ) );
	}

	/** Error si la persona ya está en el evento (inscrita, en la lista de espera o en el equipo). */
	private static function ya_esta( array $e, string $num ): ?\WP_Error {
		$previa = InscripcionesTable::de_socio( $e['id'], $num );
		if ( ! $previa ) {
			return null;
		}
		if ( InscripcionesTable::PARTICIPANTE !== $previa['papel'] ) {
			return new \WP_Error( 'asde_inscripcion', asde_t( 'Está en el equipo de este evento.' ) );
		}
		if ( in_array( $previa['estado'], array( InscripcionesTable::BAJA, InscripcionesTable::NO_PARTICIPA ), true ) ) {
			return null;
		}
		return new \WP_Error( 'asde_inscripcion', asde_t( 'Ya está inscrito/a en este evento.' ) );
	}

	/** Transporte, quién recoge y observaciones (cifradas). */
	private static function leer( array $e, string $num, array $in ): array {
		$transporte = static fn( string $k ): string => isset( InscripcionesTable::TRANSPORTES[ $in[ $k ] ?? '' ] ) ? (string) $in[ $k ] : '';
		$recoge     = mb_substr( trim( sanitize_textarea_field( (string) ( $in['recoge'] ?? '' ) ) ), 0, self::MAX_TEXTO );
		$obs        = mb_substr( trim( sanitize_textarea_field( (string) ( $in['observaciones'] ?? '' ) ) ), 0, self::MAX_TEXTO );
		return array(
			'transporte_ida'    => $transporte( 'transporte_ida' ),
			'transporte_vuelta' => $transporte( 'transporte_vuelta' ),
			'datos_enc'         => '' === $recoge . $obs ? null : Crypto::encrypt_contexto(
				(string) wp_json_encode(
					array(
						'recoge'        => $recoge,
						'observaciones' => $obs,
					)
				),
				self::contexto( $e['id'], $num )
			),
		);
	}

	/** La firma de la autorización en la web: quién, cuándo, qué versión del texto y una huella de la IP (no la IP). */
	private static function firma(): array {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		return array(
			'autorizacion'         => 'web',
			'autorizacion_por'     => get_current_user_id(),
			'autorizacion_en'      => current_time( 'mysql', true ),
			'autorizacion_version' => Config::version_autorizacion( Config::de() ),
			'autorizacion_huella'  => '' !== $ip ? Crypto::hmac( 'evento|ip|' . $ip ) : '',
		);
	}

	/** Participante de este evento, o null. */
	private static function fila( array $e, int $fila ): ?array {
		$f = InscripcionesTable::obtener( $fila );
		return $f && $f['evento_id'] === $e['id'] && InscripcionesTable::PARTICIPANTE === $f['papel'] ? $f : null;
	}

	private static function contexto( int $evento_id, string $socio ): string {
		return 'evento|' . $evento_id . '|' . $socio;
	}

	private static function utc( string $fecha ): int {
		return (int) strtotime( $fecha . ' UTC' );
	}
}
