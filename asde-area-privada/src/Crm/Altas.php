<?php
namespace AsdeBalears\AreaPrivada\Crm;

use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Domain\Grupo;
use AsdeBalears\AreaPrivada\Domain\Persona;
use AsdeBalears\AreaPrivada\Domain\Secciones;

defined( 'ABSPATH' ) || exit;

/**
 * Altas, bajas y cambios de sección en el CRM (v0.22, doc 34, fase 2). Escribe solo
 * a través de Crm\Escritura; nace apagado (regla 5): se activa por grupo.
 *
 * - Persona nueva: obligatorios nombre, apellidos, fecha de nacimiento y género, y el
 *   documento a partir de los 14 años (P-75, P-82). Antes se comprueba el documento
 *   (Crm\Duplicados): si ya tiene ficha en el grupo no se crea otra (P-79); si está en
 *   otro grupo u organización hay que confirmarlo y se sigue (P-71, P-81, P-84).
 * - Después de crear: el grupo tiene que ver la ficha (si no, se enlaza a su grupo de
 *   seguridad: P-73), se lee el nº de socio que pone el CRM (P-72) y se crea la
 *   membresía; «Secciones actuales» la recalcula el CRM (P-74).
 * - Cerrojo y reintentos con Crm\Operaciones: un alta a medias sigue donde se quedó.
 * - Baja: se cierra la membresía activa (fecha y motivo); nunca se borra nada.
 * - Cambio de sección: baja con motivo «cambioseccion» y alta en la nueva, el mismo día.
 * - Documento de quien no lo tiene (menores, P-80): se comprueba antes de guardarlo.
 */
final class Altas {

	/** Grupos con las altas en el CRM activadas (nacen apagadas). */
	public const OPCION = 'asde_ap_altas_crm';

	/** Valores de `stic_gender_c` en el CRM de ASDE (doc 04, §7.7). */
	public const GENEROS = array(
		'masculino' => 'Masculino',
		'femenino'  => 'Femenino',
		'nobinario' => 'No binario',
	);

	/** Motivos de baja (`end_reason`); «cambioseccion» lo pone el cambio de sección. */
	public const MOTIVOS_BAJA = array(
		'personal'     => 'Motivos personales',
		'economy'      => 'Motivos económicos',
		'disagreement' => 'Desacuerdo',
		'decease'      => 'Fallecimiento',
		'other'        => 'Otro motivo',
	);

	/** Campos de la ficha que se pueden rellenar al dar de alta a una persona. */
	public const CAMPOS_PERSONA = array( 'first_name', 'last_name', 'birthdate', 'stic_gender_c', 'stic_identification_number_c', 'email1', 'phone_mobile', 'tutor1_nombre_c', 'tutor1_apellidos_c', 'tutor1_email_c', 'tutor1_movil_c', 'tutor2_nombre_c', 'tutor2_apellidos_c', 'tutor2_email_c', 'tutor2_movil_c' );

	/** Campo de la membresía con la persona (se escribe en el mismo set_entry). */
	public const CAMPO_PERSONA = 'stic_contacts_relationships_contactscontacts_ida';

	private const CAMPOS_MEMBRESIA = array( 'id', 'relationship_type', 'start_date', 'end_date', 'end_reason', 'estado_c', 'active', 'numsocio_c' );

	public function __construct( private SinergiaV41Client $crm ) {}

	public static function make(): ?self {
		$c = ClientFactory::reader();
		return $c ? new self( $c ) : null;
	}

	public static function activo( string $grupo = '' ): bool {
		return in_array( '' !== $grupo ? $grupo : Grupo::numero(), array_map( 'strval', (array) get_option( self::OPCION, array() ) ), true );
	}

	public static function activar( string $grupo, bool $si ): void {
		$grupos = array_map( 'strval', (array) get_option( self::OPCION, array() ) );
		$grupos = $si ? array_values( array_unique( array_merge( $grupos, array( $grupo ) ) ) ) : array_values( array_diff( $grupos, array( $grupo ) ) );
		update_option( self::OPCION, $grupos, false );
	}

	// ---------------------------------------------------------------------
	// Persona nueva
	// ---------------------------------------------------------------------

	/**
	 * Da de alta a una persona NUEVA con su primera membresía.
	 *
	 * @param array<string, string> $datos            Campos del CRM (CAMPOS_PERSONA)
	 * @param bool                  $confirmado_otros Ya se avisó de que está en otro grupo u organización (o de que no se pudo comprobar) y se quiere seguir.
	 * @return array{persona_id:string, membresia_id:string, numero:string, otros:?int, reanudada:bool}|\WP_Error
	 *         Códigos de error: asde_alta_ya_en_grupo (con la persona en los datos), asde_alta_confirmar (falta confirmar), asde_alta_a_medias (se puede reintentar).
	 */
	public function crear_persona( array $datos, string $seccion, string $inicio = '', bool $confirmado_otros = false ) {
		$previo = self::previo();
		if ( is_wp_error( $previo ) ) {
			return $previo;
		}
		$datos = self::limpiar_persona( $datos );
		if ( is_wp_error( $datos ) ) {
			return $datos;
		}
		$inicio = self::fecha( $inicio );
		if ( ! self::seccion_valida( $seccion ) || '' === $inicio ) {
			return new \WP_Error( 'asde_alta', asde_t( 'Elige la sección y la fecha de alta.' ) );
		}
		$doc   = (string) ( $datos['stic_identification_number_c'] ?? '' );
		$clave = '' !== $doc ? 'doc:' . $doc : 'nombre:' . strtoupper( (string) preg_replace( '/[^0-9A-Za-z]/', '', remove_accents( $datos['first_name'] . $datos['last_name'] ) ) ) . ':' . $datos['birthdate'];
		$otros = null;

		// Un alta que se quedó a medias se reanuda sin volver a preguntar: la ficha que
		// encontraría la comprobación es la que creó el primer intento.
		$pendiente = Operaciones::pendiente( $clave );
		if ( ! $pendiente && '' !== $doc ) {
			try {
				$c = ( new Duplicados( $this->crm ) )->comprobar( $doc );
			} catch ( CrmException $e ) {
				return new \WP_Error( 'asde_alta', asde_t( 'No se ha podido consultar el CRM: ' ) . $e->getMessage() );
			}
			if ( $c['en_grupo'] ) {
				return new \WP_Error( 'asde_alta_ya_en_grupo', asde_t( 'Ya tiene ficha en tu grupo: no se crea otra. Si no tiene membresía activa, dale una nueva en su ficha.' ), array( 'personas' => array_map( static fn( $f ) => $f['persona']->num_socio_asde, $c['en_grupo'] ) ) );
			}
			$otros = $c['otros'];
			if ( ( null === $otros || $otros > 0 ) && ! $confirmado_otros ) {
				return new \WP_Error(
					'asde_alta_confirmar',
					null === $otros
						? asde_t( 'No se ha podido comprobar si está dada de alta en otros grupos u organizaciones. Confirma que quieres seguir.' )
						: asde_t( 'Esta persona ya está dada de alta en otro grupo u organización de Scouts de España. Confirma que quieres darla de alta también en tu grupo.' ),
					array( 'otros' => $otros )
				);
			}
		}

		$op = Operaciones::tomar( 'alta_persona', $clave );
		if ( is_wp_error( $op ) ) {
			return $op;
		}
		$d = $op['datos'];
		try {
			if ( empty( $d['persona_id'] ) ) {
				// Justo antes de crear se vuelve a mirar el grupo (otra persona pudo darla de alta entretanto).
				if ( '' !== $doc && $this->en_grupo( $doc ) ) {
					Operaciones::descartar( $op['id'] );
					return new \WP_Error( 'asde_alta_ya_en_grupo', asde_t( 'Ya tiene ficha en tu grupo: no se crea otra. Si no tiene membresía activa, dale una nueva en su ficha.' ) );
				}
				$d['persona_id'] = self::o_excepcion( Escritura::crear( Escritura::MODULO, $datos, 'alta' ) );
				Operaciones::paso( $op['id'], 'persona', $d );
			}
			$ficha = $this->asegurar_grupo( $d['persona_id'] );
			if ( empty( $d['membresia_id'] ) ) {
				$d['membresia_id'] = $this->crear_membresia( $d['persona_id'], $seccion, $inicio, (string) ( $ficha['numerosocio_c'] ?? '' ) );
				Operaciones::paso( $op['id'], 'membresia', $d );
			}
			$numero = (string) ( $this->ficha( $d['persona_id'] )['numerosocio_c'] ?? '' );
		} catch ( CrmException | \InvalidArgumentException $e ) {
			return $this->a_medias( $op['id'], $d, $e );
		}
		Instantanea::olvidar( Grupo::numero() );
		Operaciones::terminar( $op['id'], $d + array( 'numero' => '' !== $numero ? 1 : 0 ) );
		Logger::log(
			'crm_alta_persona',
			'persona',
			$d['persona_id'],
			array(
				'membresia' => $d['membresia_id'],
				'seccion'   => $seccion,
				'otros'     => $otros,
				'reanudada' => $op['reanudada'],
			)
		);
		return array(
			'persona_id'   => $d['persona_id'],
			'membresia_id' => $d['membresia_id'],
			'numero'       => $numero,
			'otros'        => $otros,
			'reanudada'    => $op['reanudada'],
		);
	}

	// ---------------------------------------------------------------------
	// Fichas que ya existen
	// ---------------------------------------------------------------------

	/**
	 * Membresía nueva para alguien que ya tiene ficha en el grupo y no tiene ninguna
	 * activa (quien vuelve, P-79).
	 *
	 * @return array{membresia_id:string}|\WP_Error
	 */
	public function nueva_membresia( Persona $p, string $seccion, string $inicio = '' ) {
		$previo = self::previo();
		if ( is_wp_error( $previo ) ) {
			return $previo;
		}
		$inicio = self::fecha( $inicio );
		if ( ! self::seccion_valida( $seccion ) || '' === $inicio ) {
			return new \WP_Error( 'asde_alta', asde_t( 'Elige la sección y la fecha de alta.' ) );
		}
		try {
			$ficha = $this->ficha( $p->id );
			if ( ! $ficha ) {
				return new \WP_Error( 'asde_alta', asde_t( 'No se encuentra esa persona en el CRM del grupo.' ) );
			}
			$activas = $this->activas( $p->id );
		} catch ( CrmException | \InvalidArgumentException $e ) {
			return new \WP_Error( 'asde_alta', asde_t( 'No se ha podido consultar el CRM: ' ) . $e->getMessage() );
		}
		if ( $activas ) {
			return new \WP_Error( 'asde_alta', asde_t( 'Ya tiene una membresía activa. Para pasarla a otra sección, usa «Cambiar de sección».' ) );
		}
		$op = Operaciones::tomar( 'membresia', 'p:' . $p->id );
		if ( is_wp_error( $op ) ) {
			return $op;
		}
		$d = $op['datos'];
		try {
			if ( empty( $d['membresia_id'] ) ) {
				$d['membresia_id'] = $this->crear_membresia( $p->id, $seccion, $inicio, (string) ( $ficha['numerosocio_c'] ?? '' ) );
			}
		} catch ( CrmException | \InvalidArgumentException $e ) {
			return $this->a_medias( $op['id'], $d, $e );
		}
		Instantanea::olvidar( Grupo::numero() );
		Operaciones::terminar( $op['id'], $d );
		Logger::log( 'crm_membresia_nueva', 'socio', $p->num_socio_asde, array( 'membresia' => $d['membresia_id'], 'seccion' => $seccion ) );
		return array( 'membresia_id' => $d['membresia_id'] );
	}

	/**
	 * Baja: se cierra la membresía activa con la fecha y el motivo. No se borra nada.
	 *
	 * @return array{membresias:string[]}|\WP_Error
	 */
	public function baja( Persona $p, string $fin, string $motivo ) {
		$previo = self::previo();
		if ( is_wp_error( $previo ) ) {
			return $previo;
		}
		$fin = self::fecha( $fin );
		if ( ! isset( self::MOTIVOS_BAJA[ $motivo ] ) || '' === $fin ) {
			return new \WP_Error( 'asde_baja', asde_t( 'Elige la fecha y el motivo de la baja.' ) );
		}
		try {
			$activas = $this->activas( $p->id );
		} catch ( CrmException | \InvalidArgumentException $e ) {
			return new \WP_Error( 'asde_baja', asde_t( 'No se ha podido consultar el CRM: ' ) . $e->getMessage() );
		}
		if ( ! $activas ) {
			return new \WP_Error( 'asde_baja', asde_t( 'No tiene ninguna membresía activa.' ) );
		}
		$op = Operaciones::tomar( 'baja', 'p:' . $p->id );
		if ( is_wp_error( $op ) ) {
			return $op;
		}
		$d = $op['datos'];
		foreach ( $activas as $m ) {
			$r = Escritura::actualizar_membresia( $p, $m['id'], self::cierre( $fin, $motivo ), 'baja' );
			if ( is_wp_error( $r ) ) {
				return $this->a_medias( $op['id'], $d, new CrmException( esc_html( $r->get_error_message() ) ) );
			}
			$d['cerradas'][] = $m['id'];
			Operaciones::paso( $op['id'], 'baja', $d );
		}
		Instantanea::olvidar( Grupo::numero() );
		Operaciones::terminar( $op['id'], $d );
		Logger::log( 'crm_baja', 'socio', $p->num_socio_asde, array( 'membresias' => $d['cerradas'], 'motivo' => $motivo ) );
		return array( 'membresias' => $d['cerradas'] );
	}

	/**
	 * Cambio de sección: baja de la membresía activa con motivo «cambioseccion» y alta
	 * en la sección nueva, con la misma fecha.
	 *
	 * @return array{cerrada:string, membresia_id:string}|\WP_Error
	 */
	public function cambio_seccion( Persona $p, string $seccion, string $fecha = '' ) {
		$previo = self::previo();
		if ( is_wp_error( $previo ) ) {
			return $previo;
		}
		$fecha = self::fecha( $fecha );
		if ( ! self::seccion_valida( $seccion ) || '' === $fecha ) {
			return new \WP_Error( 'asde_cambio', asde_t( 'Elige la sección nueva y la fecha.' ) );
		}
		$op = Operaciones::tomar( 'cambio_seccion', 'p:' . $p->id );
		if ( is_wp_error( $op ) ) {
			return $op;
		}
		$d = $op['datos'];
		try {
			if ( empty( $d['cerrada'] ) ) {
				$activas = $this->activas( $p->id );
				if ( 1 !== count( $activas ) || 0 === strcasecmp( (string) $activas[0]['relationship_type'], Secciones::all()[ $seccion ]['crm'] ) ) {
					Operaciones::descartar( $op['id'] );
					return new \WP_Error( 'asde_cambio', 1 !== count( $activas ) ? asde_t( 'Para cambiar de sección tiene que tener una membresía activa (y solo una).' ) : asde_t( 'Ya está en esa sección.' ) );
				}
				$r = Escritura::actualizar_membresia( $p, $activas[0]['id'], self::cierre( $fecha, 'cambioseccion' ), 'cambio de sección' );
				if ( is_wp_error( $r ) ) {
					throw new CrmException( esc_html( $r->get_error_message() ) );
				}
				$d['cerrada'] = $activas[0]['id'];
				Operaciones::paso( $op['id'], 'baja', $d );
			}
			if ( empty( $d['membresia_id'] ) ) {
				$d['membresia_id'] = $this->crear_membresia( $p->id, $seccion, $fecha, $p->num_socio_asde );
				Operaciones::paso( $op['id'], 'alta', $d );
			}
		} catch ( CrmException | \InvalidArgumentException $e ) {
			return $this->a_medias( $op['id'], $d, $e );
		}
		Instantanea::olvidar( Grupo::numero() );
		Operaciones::terminar( $op['id'], $d );
		Logger::log( 'crm_cambio_seccion', 'socio', $p->num_socio_asde, array( 'cerrada' => $d['cerrada'], 'membresia' => $d['membresia_id'], 'seccion' => $seccion ) );
		return array(
			'cerrada'      => $d['cerrada'],
			'membresia_id' => $d['membresia_id'],
		);
	}

	/**
	 * Pone el documento a quien no lo tiene (p. ej. un menor que lo ha sacado, P-80).
	 * Antes se comprueba: si otra ficha del grupo ya lo tiene, no se guarda (sería la
	 * misma persona dos veces); si está en otro grupo u organización, hay que confirmarlo.
	 *
	 * @return array{otros:?int}|\WP_Error Códigos: asde_documento_en_grupo, asde_documento_confirmar.
	 */
	public function poner_documento( Persona $p, string $documento, bool $confirmado_otros = false ) {
		$previo = self::previo();
		if ( is_wp_error( $previo ) ) {
			return $previo;
		}
		$v = Duplicados::validar( $documento );
		if ( ! $v['ok'] ) {
			return new \WP_Error( 'asde_documento', $v['error'] );
		}
		try {
			$ficha = $this->ficha( $p->id );
			if ( ! $ficha ) {
				return new \WP_Error( 'asde_documento', asde_t( 'No se encuentra esa persona en el CRM del grupo.' ) );
			}
			if ( '' !== trim( (string) ( $ficha['stic_identification_number_c'] ?? '' ) ) ) {
				return new \WP_Error( 'asde_documento', asde_t( 'Esta ficha ya tiene un documento. Si está mal, corrígelo en el CRM.' ) );
			}
			$c = ( new Duplicados( $this->crm ) )->comprobar( $v['documento'] );
		} catch ( CrmException | \InvalidArgumentException $e ) {
			return new \WP_Error( 'asde_documento', asde_t( 'No se ha podido consultar el CRM: ' ) . $e->getMessage() );
		}
		$otras = array_values( array_filter( $c['en_grupo'], static fn( $f ) => $f['persona']->id !== $p->id ) );
		if ( $otras ) {
			/* translators: %s: nombre de la otra persona */
			return new \WP_Error( 'asde_documento_en_grupo', sprintf( asde_t( 'Otra ficha de tu grupo ya tiene este documento (%s): sería la misma persona dos veces. Revísalo antes de seguir.' ), $otras[0]['persona']->nombre_completo() ) );
		}
		if ( ( null === $c['otros'] || $c['otros'] > 0 ) && ! $confirmado_otros ) {
			return new \WP_Error(
				'asde_documento_confirmar',
				null === $c['otros']
					? asde_t( 'No se ha podido comprobar si este documento está en otros grupos u organizaciones. Confirma que quieres guardarlo.' )
					: asde_t( 'Este documento ya está dado de alta en otro grupo u organización de Scouts de España. Confirma que es la misma persona y quieres guardarlo.' ),
				array( 'otros' => $c['otros'] )
			);
		}
		$op = Operaciones::tomar( 'documento', 'doc:' . $v['documento'] );
		if ( is_wp_error( $op ) ) {
			return $op;
		}
		$r = Escritura::guardar( $p, array( 'stic_identification_number_c' => $v['documento'] ), 'documento' );
		if ( is_wp_error( $r ) || $r['rechazados'] ) {
			Operaciones::descartar( $op['id'] );
			return is_wp_error( $r ) ? $r : new \WP_Error( 'asde_documento', asde_t( 'El CRM no ha guardado el documento.' ) );
		}
		Operaciones::terminar( $op['id'], array( 'persona_id' => $p->id ) );
		return array( 'otros' => $c['otros'] );
	}

	// ---------------------------------------------------------------------
	// Lectura de apoyo
	// ---------------------------------------------------------------------

	/**
	 * Membresías de una persona (las del CRM, por su enlace).
	 *
	 * @return list<array<string, string>> Cada una con CAMPOS_MEMBRESIA.
	 */
	public function membresias( string $persona_id ): array {
		return array_map(
			static fn( $e ) => array( 'id' => $e['id'] ) + array_map( 'strval', $e['fields'] ),
			$this->crm->related( Escritura::MODULO, $persona_id, Escritura::ENLACE_MEMBRESIAS, '', self::CAMPOS_MEMBRESIA )
		);
	}

	/** @return list<array<string, string>> Las membresías activas (estado «activo» y sin fecha de baja pasada). */
	public function activas( string $persona_id ): array {
		$hoy = wp_date( 'Y-m-d' );
		return array_values(
			array_filter(
				$this->membresias( $persona_id ),
				static fn( $m ) => 'activo' === strtolower( (string) $m['estado_c'] ) && ( '' === (string) $m['end_date'] || (string) $m['end_date'] > $hoy )
			)
		);
	}

	// ---------------------------------------------------------------------
	// Pasos internos
	// ---------------------------------------------------------------------

	/** ¿Están activas las altas en el grupo y puede crear el usuario del CRM? */
	private static function previo(): ?\WP_Error {
		if ( ! self::activo() ) {
			return new \WP_Error( 'asde_altas_apagadas', asde_t( 'Las altas en el CRM no están activadas para tu grupo.' ) );
		}
		if ( ! Escritura::puede_crear() ) {
			return new \WP_Error( 'asde_altas_permiso', asde_t( 'El usuario del CRM de tu grupo no tiene permiso para crear personas y membresías.' ) );
		}
		return null;
	}

	/**
	 * Datos de una persona nueva: solo los campos permitidos, sin espacios sobrantes,
	 * con los obligatorios y el documento normalizado (obligatorio desde los 14 años).
	 *
	 * @return array<string, string>|\WP_Error
	 */
	public static function limpiar_persona( array $datos ) {
		$d = array();
		foreach ( self::CAMPOS_PERSONA as $c ) {
			$v = trim( (string) preg_replace( '/\s+/u', ' ', (string) ( $datos[ $c ] ?? '' ) ) );
			if ( '' !== $v ) {
				$d[ $c ] = $v;
			}
		}
		foreach ( array(
			'first_name'    => asde_t( 'Falta el nombre.' ),
			'last_name'     => asde_t( 'Faltan los apellidos.' ),
			'birthdate'     => asde_t( 'Falta la fecha de nacimiento.' ),
			'stic_gender_c' => asde_t( 'Falta el género.' ),
		) as $c => $error ) {
			if ( ! isset( $d[ $c ] ) ) {
				return new \WP_Error( 'asde_alta_datos', $error );
			}
		}
		$nacimiento = self::fecha( $d['birthdate'] );
		if ( '' === $nacimiento || $nacimiento > wp_date( 'Y-m-d' ) || $nacimiento < '1900-01-01' ) {
			return new \WP_Error( 'asde_alta_datos', asde_t( 'La fecha de nacimiento no es válida.' ) );
		}
		$d['birthdate'] = $nacimiento;
		if ( ! isset( self::GENEROS[ $d['stic_gender_c'] ] ) ) {
			return new \WP_Error( 'asde_alta_datos', asde_t( 'El género no es válido.' ) );
		}
		if ( isset( $d['stic_identification_number_c'] ) ) {
			$v = Duplicados::validar( $d['stic_identification_number_c'] );
			if ( ! $v['ok'] ) {
				return new \WP_Error( 'asde_alta_datos', $v['error'] );
			}
			$d['stic_identification_number_c'] = $v['documento'];
		} elseif ( Duplicados::documento_obligatorio( $nacimiento ) ) {
			/* translators: %d: edad */
			return new \WP_Error( 'asde_alta_datos', sprintf( asde_t( 'El documento es obligatorio a partir de los %d años.' ), Duplicados::EDAD_DOCUMENTO ) );
		}
		foreach ( array( 'email1', 'tutor1_email_c', 'tutor2_email_c' ) as $c ) {
			if ( isset( $d[ $c ] ) && ! is_email( $d[ $c ] ) ) {
				return new \WP_Error( 'asde_alta_datos', asde_t( 'Hay un email que no es válido.' ) );
			}
		}
		return $d;
	}

	/** Fecha AAAA-MM-DD válida (o hoy si viene vacía); '' si no es válida. */
	private static function fecha( string $fecha ): string {
		if ( '' === trim( $fecha ) ) {
			return wp_date( 'Y-m-d' );
		}
		$f = \DateTime::createFromFormat( '!Y-m-d', trim( $fecha ) );
		return $f && $f->format( 'Y-m-d' ) === trim( $fecha ) ? $f->format( 'Y-m-d' ) : '';
	}

	private static function seccion_valida( string $seccion ): bool {
		return Secciones::exists( $seccion );
	}

	/** Campos que cierran una membresía. */
	private static function cierre( string $fin, string $motivo ): array {
		return array(
			'end_date'   => $fin,
			'end_reason' => $motivo,
			'estado_c'   => 'baja',
		);
	}

	/** Ficha de la persona si el GRUPO la ve (id, nº de socio, secciones y documento), o null. */
	private function ficha( string $persona_id ): ?array {
		$e = PersonaRepository::filtrar_grupo( $this->crm->list_entries( 'Contacts', 'contacts.id = ' . Query::id( $persona_id ), array( 'id', 'numerosocio_c', 'stic_relationship_type_c', 'stic_identification_number_c' ), PersonaRepository::links_grupo(), 0, 1 )['entries'] );
		return $e ? $e[0]['fields'] : null;
	}

	/** ¿Hay en el grupo una ficha con este documento? (otra comprobación justo antes de crear) */
	private function en_grupo( string $doc ): bool {
		$campo    = 'contacts_cstm.stic_identification_number_c';
		$digitos  = ltrim( (string) preg_replace( '/\D/', '', $doc ), '0' );
		$entradas = PersonaRepository::filtrar_grupo( $this->crm->list_entries( 'Contacts', "$campo LIKE " . Query::contains( '' !== $digitos ? $digitos : $doc ), array( 'id', 'stic_identification_number_c' ), PersonaRepository::links_grupo(), 0, 50 )['entries'] );
		foreach ( $entradas as $e ) {
			if ( Duplicados::validar( (string) ( $e['fields']['stic_identification_number_c'] ?? '' ) )['documento'] === $doc ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * El grupo tiene que ver la ficha nueva. Si el CRM no la ha puesto en el grupo de
	 * seguridad del usuario que la crea (depende de su configuración, P-73), se enlaza.
	 *
	 * @return array Campos de la ficha.
	 * @throws CrmException
	 */
	private function asegurar_grupo( string $persona_id ): array {
		$ficha = $this->ficha( $persona_id );
		if ( $ficha ) {
			return $ficha;
		}
		self::o_excepcion( Escritura::relacionar( $persona_id, 'SecurityGroups', array( (string) Grupo::actual()['crm_id'] ), 'alta' ) );
		$ficha = $this->ficha( $persona_id );
		if ( ! $ficha ) {
			throw new CrmException( esc_html( asde_t( 'El CRM no ha dejado la ficha nueva en el grupo.' ) ) );
		}
		return $ficha;
	}

	/**
	 * Crea una membresía activa de la persona. El enlace con la persona va en el mismo
	 * set_entry; si el CRM no lo guarda así, se enlaza después.
	 *
	 * @throws CrmException
	 */
	private function crear_membresia( string $persona_id, string $seccion, string $inicio, string $numero ): string {
		$valores = array(
			'relationship_type' => Secciones::all()[ $seccion ]['crm'],
			'start_date'        => $inicio,
			'estado_c'          => 'activo',
			self::CAMPO_PERSONA => $persona_id,
		);
		if ( '' !== $numero ) {
			$valores['numsocio_c'] = $numero;
		}
		$id = self::o_excepcion( Escritura::crear( Escritura::MEMBRESIAS, $valores, 'alta' ) );
		if ( ! in_array( $id, array_column( $this->membresias( $persona_id ), 'id' ), true ) ) {
			self::o_excepcion( Escritura::relacionar( $persona_id, Escritura::ENLACE_MEMBRESIAS, array( $id ), 'alta' ) );
			if ( ! in_array( $id, array_column( $this->membresias( $persona_id ), 'id' ), true ) ) {
				throw new CrmException( esc_html( asde_t( 'El CRM no ha enlazado la membresía con la persona.' ) ) );
			}
		}
		return $id;
	}

	/** Un \WP_Error de Escritura se convierte en excepción (para cortar el alta y dejarla a medias). */
	private static function o_excepcion( $r ) {
		if ( is_wp_error( $r ) ) {
			throw new CrmException( esc_html( $r->get_error_message() ) );
		}
		return $r;
	}

	/** Lo hecho se queda apuntado y el reintento sigue desde ahí; si no se hizo nada, no hay nada que reanudar. */
	private function a_medias( int $op, array $hecho, \Throwable $e ): \WP_Error {
		if ( $hecho ) {
			Operaciones::fallar( $op, $hecho );
		} else {
			Operaciones::descartar( $op );
		}
		Logger::log( 'crm_alta_a_medias', 'operacion', (string) $op, array( 'hecho' => array_keys( $hecho ) ) );
		return new \WP_Error(
			'asde_alta_a_medias',
			$hecho
				? asde_t( 'Se ha quedado a medias. Vuelve a intentarlo: seguirá donde se quedó, sin crear otra ficha. Motivo: ' ) . $e->getMessage()
				: asde_t( 'No se ha podido hacer: ' ) . $e->getMessage()
		);
	}
}
