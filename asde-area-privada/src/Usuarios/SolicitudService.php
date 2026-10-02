<?php
namespace AsdeBalears\AreaPrivada\Usuarios;

use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Crm\Escritura;
use AsdeBalears\AreaPrivada\Crm\PersonaRepository;
use AsdeBalears\AreaPrivada\Domain\Persona;
use AsdeBalears\AreaPrivada\Install\Roles;
use AsdeBalears\AreaPrivada\Socios\Numeracion;

defined( 'ABSPATH' ) || exit;

/**
 * Solicitudes de acceso (petición del usuario, 2026-09-28).
 *
 * 1. Quien pide escribe el nº de documento y la fecha de nacimiento del socio/a,
 *    su email, su nombre y si es el propio socio/a o su familia.
 * 2. Se comprueba en el CRM del grupo que documento y fecha son de la misma
 *    ficha con membresía activa. Si no hay ninguna ficha con ese documento pero
 *    sí algún menor de 14 años nacido ese día (muchos no tienen DNI), se pide
 *    su nombre y apellidos y se comprueban. Si no cuadra, quien pide ve el email
 *    de contacto del grupo para pedirlo por email.
 * 3. Un scouter de su sección (o Secretaría, Coordinación) la revisa, viendo lo
 *    escrito junto a lo del CRM, y la aprueba.
 * 4. Al aprobarla: si el email no está en la ficha, se guarda en el CRM (en el
 *    campo del socio/a o del tutor/a que toca), se crea la cuenta (o, en una
 *    cuenta familiar que ya existe, se sustituye su email) y le llega la
 *    invitación con un mensaje de bienvenida.
 */
final class SolicitudService {

	public const OK           = 'ok';
	public const PEDIR_NOMBRE = 'pedir_nombre';
	public const NO_EXISTE    = 'no_existe';
	public const NO_COINCIDE  = 'no_coincide';
	public const MENOR_14     = 'menor_14';
	public const ADULTO       = 'adulto';
	public const NO_ELEGIBLE  = 'no_elegible';
	public const YA_TIENE     = 'ya_tiene';
	public const YA_PEDIDA    = 'ya_pedida';

	/** Al aprobar la petición de una familia que ya tiene cuenta: otro tutor/a (cuenta nueva) o la misma persona con otro email. */
	public const MODO_NUEVA     = 'nueva';
	public const MODO_SUSTITUIR = 'sustituir';

	/** Campos del CRM en los que puede ir el email de cada tipo de cuenta. */
	public const CAMPOS = array(
		Solicitudes::SOCIO => array( 'email1' ),
		Solicitudes::TUTOR => array( 'tutor1_email_c', 'tutor2_email_c' ),
	);

	public function __construct( private PersonaRepository $repo ) {}

	public static function make(): ?self {
		$repo = PersonaRepository::make();
		return $repo ? new self( $repo ) : null;
	}

	public function repo(): PersonaRepository {
		return $this->repo;
	}

	/**
	 * Posibles hermanos/as de un menor para la cuenta familiar: otros menores del
	 * grupo que comparten algún email con su ficha.
	 *
	 * @return Persona[]
	 */
	public function hermanos( Persona $p ): array {
		if ( ! $p->emails() ) {
			return array();
		}
		$alta = new AltaService( $this->repo, new Numeracion( $this->repo ) );
		return array_values( array_filter( $this->repo->por_emails( $p->emails() ), static fn( Persona $o ) => $o->num_socio_asde !== $p->num_socio_asde && null === $alta->motivo_no_representable( $o ) ) );
	}

	// ---------------------------------------------------------------------
	// Verificación (formulario público)
	// ---------------------------------------------------------------------

	/**
	 * Busca la ficha que corresponde a lo escrito, en el CRM del grupo actual.
	 *
	 * @return array{resultado:string, persona:?Persona, via:string}
	 */
	public function verificar( string $documento, string $nacimiento, string $nombre_menor = '' ): array {
		$doc = PersonaRepository::normalizar_documento( $documento );
		if ( '' !== $doc ) {
			$con_doc = $this->repo->por_documento( $doc );
			foreach ( $con_doc as $p ) {
				if ( substr( $p->fecha_nacimiento, 0, 10 ) === $nacimiento ) {
					return self::resultado( self::OK, $p, Solicitudes::POR_DOCUMENTO );
				}
			}
			if ( $con_doc ) {
				return self::resultado( self::NO_COINCIDE ); // El documento existe, pero la fecha no es la suya.
			}
		}
		// Sin documento, o ninguna ficha con ese documento: ¿algún menor de 14 nacido ese día?
		$menores = array_values( array_filter( $this->repo->por_nacimiento( $nacimiento ), static fn( Persona $p ) => null !== $p->edad() && $p->edad() < AltaService::EDAD_MINIMA_PARTICIPANTE ) );
		if ( ! $menores ) {
			return self::resultado( self::NO_EXISTE );
		}
		if ( '' === trim( $nombre_menor ) ) {
			return self::resultado( self::PEDIR_NOMBRE );
		}
		foreach ( $menores as $m ) {
			if ( self::mismo_nombre( $nombre_menor, $m->nombre, $m->apellidos ) ) {
				return self::resultado( self::OK, $m, Solicitudes::POR_NOMBRE );
			}
		}
		return self::resultado( self::NO_COINCIDE );
	}

	/**
	 * ¿Es el nombre de esa persona? Sin tildes ni mayúsculas, todas las palabras
	 * escritas tienen que estar en su nombre y apellidos, y tienen que estar su
	 * primer nombre y su primer apellido («Hugo Martín» vale para «Hugo Martín Ferrer»).
	 */
	public static function mismo_nombre( string $escrito, string $nombre, string $apellidos ): bool {
		$palabras = static fn( string $s ): array => array_values( array_filter( explode( ' ', (string) preg_replace( '/[^a-z]+/', ' ', strtolower( remove_accents( $s ) ) ) ) ) );
		$escritas = $palabras( $escrito );
		$nombres  = $palabras( $nombre );
		$apell    = $palabras( $apellidos );
		if ( ! $escritas || ! $nombres || ! $apell ) {
			return false;
		}
		return ! array_diff( $escritas, array_merge( $nombres, $apell ) ) && in_array( $nombres[0], $escritas, true ) && in_array( $apell[0], $escritas, true );
	}

	/** ¿Puede tener esa persona el tipo de cuenta pedido? @return string OK o el motivo. */
	public function comprobar_tipo( Persona $p, string $tipo ): string {
		$edad = $p->edad();
		if ( Solicitudes::TUTOR === $tipo ) {
			return null !== $edad && $edad >= 18 ? self::ADULTO : self::OK;
		}
		if ( null !== $edad && $edad < AltaService::EDAD_MINIMA_PARTICIPANTE ) {
			return self::MENOR_14;
		}
		return $p->es_participante() || $p->es_adulto_voluntario() ? self::OK : self::NO_ELEGIBLE;
	}

	/**
	 * Todo el paso del formulario público, en el grupo actual.
	 *
	 * @param array{tipo:string, documento:string, nacimiento:string, email:string, nombre:string, nombre_menor:string} $d
	 * @return array{resultado:string, id:int}
	 */
	public function solicitar( array $d ): array {
		$v = $this->verificar( $d['documento'], $d['nacimiento'], $d['nombre_menor'] );
		if ( self::OK !== $v['resultado'] ) {
			return array(
				'resultado' => $v['resultado'],
				'id'        => 0,
			);
		}
		$p    = $v['persona'];
		$tipo = $this->comprobar_tipo( $p, $d['tipo'] );
		if ( self::OK !== $tipo ) {
			return array(
				'resultado' => $tipo,
				'id'        => 0,
			);
		}
		$propia = Usuarios::por_socio( $p->num_socio_asde );
		if ( Solicitudes::SOCIO === $d['tipo'] && $propia && Usuarios::DESACTIVADO !== Usuarios::estado( $propia ) ) {
			return array(
				'resultado' => self::YA_TIENE,
				'id'        => 0,
			);
		}
		if ( Solicitudes::hay_pendiente( $p->num_socio_asde, $d['tipo'], $d['email'] ) ) {
			return array(
				'resultado' => self::YA_PEDIDA,
				'id'        => 0,
			);
		}
		$id = Solicitudes::crear(
			$d['tipo'],
			$v['via'],
			$p,
			array(
				'nombre'       => $d['nombre'],
				'email'        => EmailCuenta::normalizar( $d['email'] ),
				'documento'    => PersonaRepository::normalizar_documento( $d['documento'] ),
				'nacimiento'   => $d['nacimiento'],
				'nombre_menor' => $d['nombre_menor'],
			)
		);
		Logger::log(
			'solicitud_recibida',
			'solicitud',
			(string) $id,
			array(
				'tipo' => $d['tipo'],
				'via'  => $v['via'],
			)
		);
		return array(
			'resultado' => self::OK,
			'id'        => $id,
		);
	}

	// ---------------------------------------------------------------------
	// Revisión (scouters)
	// ---------------------------------------------------------------------

	/**
	 * Lo escrito comparado con el CRM, para la pantalla de revisión.
	 *
	 * @return array{nombre_socio:?bool, nacimiento:bool, tutor:int, email_en:?string, campo:string, emails:array<string,string>}
	 */
	public function comparacion( array $s, Persona $p ): array {
		$d     = $s['datos'];
		$tutor = 0;
		foreach ( array( 1, 2 ) as $n ) {
			$t = $p->tutores[ $n ] ?? null;
			if ( ! $tutor && $t && self::mismo_nombre( $d['nombre'], $t['nombre'], $t['apellidos'] ) ) {
				$tutor = $n;
			}
		}
		$escrito_socio = Solicitudes::POR_NOMBRE === $s['via'] ? $d['nombre_menor'] : ( Solicitudes::SOCIO === $s['tipo'] ? $d['nombre'] : '' );
		return array(
			'nombre_socio' => '' === $escrito_socio ? null : self::mismo_nombre( $escrito_socio, $p->nombre, $p->apellidos ),
			'nacimiento'   => substr( $p->fecha_nacimiento, 0, 10 ) === $d['nacimiento'],
			'tutor'        => $tutor,
			'email_en'     => '' !== $d['email'] ? AltaService::campo_de_email( $p->emails_por_campo(), $d['email'] ) : null,
			'campo'        => $this->campo_propuesto( $s, $p ),
			'emails'       => $p->emails_por_campo(),
		);
	}

	/**
	 * Campo del CRM donde debe quedar el email de la solicitud: el elegido al
	 * revisar; si no, en el que ya esté; si no, el del socio/a (cuenta propia) o
	 * el del tutor/a cuyo nombre coincide, o el primero vacío, o el del tutor/a 1.
	 */
	public function campo_propuesto( array $s, Persona $p, string $elegido = '' ): string {
		$validos = self::CAMPOS[ $s['tipo'] ] ?? self::CAMPOS[ Solicitudes::SOCIO ];
		if ( in_array( $elegido, $validos, true ) ) {
			return $elegido;
		}
		$ya = '' !== $s['datos']['email'] ? AltaService::campo_de_email( $p->emails_por_campo(), $s['datos']['email'] ) : null;
		if ( null !== $ya ) {
			return $ya;
		}
		if ( Solicitudes::SOCIO === $s['tipo'] ) {
			return 'email1';
		}
		foreach ( array( 1, 2 ) as $n ) {
			$t = $p->tutores[ $n ] ?? null;
			if ( $t && self::mismo_nombre( $s['datos']['nombre'], $t['nombre'], $t['apellidos'] ) ) {
				return "tutor{$n}_email_c";
			}
		}
		foreach ( array( 1, 2 ) as $n ) {
			if ( '' === ( $p->tutores[ $n ]['email'] ?? '' ) ) {
				return "tutor{$n}_email_c";
			}
		}
		return 'tutor1_email_c';
	}

	/**
	 * Qué se propone al aprobar la petición de una familia que ya tiene cuenta (el
	 * scouter puede cambiarlo): si quien pide es el tutor/a cuyo email es el de una
	 * de las cuentas, «sustituir» esa; si es otro tutor/a (o no se sabe), una cuenta
	 * nueva; y con las dos cuentas ya creadas, solo se puede sustituir.
	 *
	 * @param \WP_User[] $existentes Cuentas familiares de la ficha (Usuarios::cuentas_familiares).
	 * @return array{modo:string, cuenta:string, puede_nueva:bool}
	 */
	public function propuesta( array $s, Persona $p, array $existentes ): array {
		$slot    = $this->comparacion( $s, $p )['tutor'];
		$usados  = self::campos_en_uso( $existentes, $p );
		$elegida = '';
		foreach ( $usados as $login => $campo ) {
			if ( $slot && "tutor{$slot}_email_c" === $campo ) {
				$elegida = $login;
			}
		}
		$puede_nueva = count( $existentes ) < Usuarios::MAX_CUENTAS_FAMILIARES;
		$modo        = ! $puede_nueva || '' !== $elegida ? self::MODO_SUSTITUIR : self::MODO_NUEVA;
		$cuenta      = '' !== $elegida ? $elegida : (string) ( $existentes[0]->user_login ?? '' );
		// Campo del CRM donde iría el email: el de la cuenta que se sustituye o, si es otro tutor/a, uno que no sea de otra cuenta.
		$libres = array_values( array_diff( self::CAMPOS[ Solicitudes::TUTOR ], array_values( $usados ) ) );
		$campo  = self::MODO_SUSTITUIR === $modo ? ( $usados[ $cuenta ] ?? '' ) : ( $slot && in_array( "tutor{$slot}_email_c", $libres, true ) ? "tutor{$slot}_email_c" : ( $libres[0] ?? '' ) );
		return array(
			'modo'        => $modo,
			'cuenta'      => $cuenta,
			'puede_nueva' => $puede_nueva,
			'campo'       => $campo,
		);
	}

	/**
	 * Campo del CRM (de la ficha dada) del que sigue el email cada cuenta familiar.
	 *
	 * @param \WP_User[] $cuentas
	 * @return array<string, string> usuario => campo
	 */
	private static function campos_en_uso( array $cuentas, Persona $p ): array {
		$out = array();
		foreach ( $cuentas as $c ) {
			$o = Usuarios::email_origen( $c->ID );
			if ( $o && $o['asde'] === $p->num_socio_asde && in_array( $o['campo'], self::CAMPOS[ Solicitudes::TUTOR ], true ) ) {
				$out[ $c->user_login ] = $o['campo'];
			}
		}
		return $out;
	}

	/**
	 * Si el email no es el de ese campo de la ficha, lo guarda en el CRM.
	 *
	 * @return array{guardado:bool, cambiado:bool, aviso:string}
	 */
	public function guardar_email_en_crm( Persona $p, string $campo, string $email ): array {
		$email = EmailCuenta::normalizar( $email );
		if ( ( $p->emails_por_campo()[ $campo ] ?? '' ) === $email ) {
			return array(
				'guardado' => true,
				'cambiado' => false,
				'aviso'    => '',
			);
		}
		$etiqueta = Usuarios::etiqueta_campo_email( $campo );
		if ( ! Escritura::disponible() ) {
			return array(
				'guardado' => false,
				'cambiado' => false,
				'aviso'    => sprintf( 'El usuario del CRM del grupo no puede editar fichas: anota a mano el email nuevo en la ficha de %s (%s). La invitación se envía igualmente a ese email.', $p->nombre_completo(), $etiqueta ),
			);
		}
		$r = Escritura::guardar( $p, array( $campo => $email ), 'solicitud_acceso' );
		if ( is_wp_error( $r ) || ! in_array( $campo, $r['guardados'], true ) ) {
			return array(
				'guardado' => false,
				'cambiado' => false,
				'aviso'    => sprintf( 'El CRM no ha guardado el email nuevo en la ficha de %s (%s): anótalo a mano. La invitación se envía igualmente a ese email.', $p->nombre_completo(), $etiqueta ),
			);
		}
		return array(
			'guardado' => true,
			'cambiado' => true,
			'aviso'    => '',
		);
	}

	/**
	 * Aprueba la solicitud: email al CRM, cuenta e invitación con bienvenida.
	 *
	 * @param array{campo?:string, hermanos?:string[], sustituir?:bool} $opciones
	 * @return array{ok:bool, errores:string[], avisos:string[], usuario:?\WP_User, email_crm:bool}
	 */
	public function aprobar( array $s, int $revisor, array $opciones = array() ): array {
		$res   = array(
			'ok'        => false,
			'errores'   => array(),
			'avisos'    => array(),
			'usuario'   => null,
			'email_crm' => false,
		);
		$email = EmailCuenta::normalizar( (string) $s['datos']['email'] );
		if ( Solicitudes::PENDIENTE !== $s['estado'] ) {
			$res['errores'][] = 'Esta solicitud ya está resuelta.';
			return $res;
		}
		if ( ! is_email( $email ) ) {
			$res['errores'][] = 'La solicitud no tiene un email válido.';
			return $res;
		}
		$p = $this->repo->obtener( (string) $s['socio'] );
		if ( ! $p || ! $p->tiene_membresia_activa() ) {
			$res['errores'][] = 'Esa ficha ya no está en el CRM del grupo o no tiene membresía activa.';
			return $res;
		}
		if ( $p->es_adulto_voluntario() ) {
			$res['errores'][] = 'Es scouter o voluntario/a: dale acceso desde «Dar acceso», con sus roles y secciones.';
			return $res;
		}
		$tipo = $this->comprobar_tipo( $p, (string) $s['tipo'] );
		if ( self::OK !== $tipo ) {
			$res['errores'][] = self::ADULTO === $tipo ? 'Ya es mayor de edad: tiene que pedir el acceso la propia persona.' : 'Esa persona no puede tener ese tipo de cuenta.';
			return $res;
		}
		$hermanos = array_values( array_filter( array_map( 'strval', (array) ( $opciones['hermanos'] ?? array() ) ), array( PersonaRepository::class, 'es_num_asde' ) ) );

		// Antes de tocar el CRM: si la familia ya tiene cuenta, hay que elegir entre
		// «otro tutor/a» (cuenta nueva, T2), «la misma persona con otro email»
		// (sustituir el de esa cuenta) o «ya es su cuenta» (mismo email).
		$existentes = Solicitudes::TUTOR === $s['tipo'] ? Usuarios::cuentas_familiares( array( $p->num_socio_asde ) ) : array();
		$existente  = null; // Cuenta a la que se le cambia el email (o que ya lo tenía) y se le manda el enlace.
		$crear_otra = false;
		if ( $existentes ) {
			$modo  = (string) ( $opciones['modo'] ?? ( ! empty( $opciones['sustituir'] ) ? self::MODO_SUSTITUIR : '' ) );
			$misma = null;
			foreach ( $existentes as $c ) {
				if ( EmailCuenta::leer( $c->ID ) === $email ) {
					$misma = $c;
				}
			}
			if ( $misma ) {
				$existente = $misma; // Ese email ya es el de una de las cuentas: se le vuelve a mandar el acceso.
			} elseif ( self::MODO_SUSTITUIR === $modo ) {
				$login = (string) ( $opciones['cuenta'] ?? '' );
				foreach ( $existentes as $c ) {
					if ( $c->user_login === $login ) {
						$existente = $c;
					}
				}
				$existente ??= $existentes[0];
			} elseif ( self::MODO_NUEVA === $modo ) {
				if ( count( $existentes ) >= Usuarios::MAX_CUENTAS_FAMILIARES ) {
					$res['errores'][] = sprintf( 'Esta familia ya tiene las dos cuentas de tutor/a (%s). Para cambiar el email de una, marca «Sustituir su email por el de la solicitud» y elige cuál.', implode( ' y ', array_map( static fn( \WP_User $c ) => $c->user_login, $existentes ) ) );
					return $res;
				}
				$crear_otra = true;
			} else {
				$res['errores'][] = sprintf( 'Ya existe la cuenta familiar %s. Elige si es otro tutor/a (se le crea su propia cuenta) o la misma persona con otro email («Sustituir su email por el de la solicitud»).', $existentes[0]->user_login );
				return $res;
			}
		}

		// 1. El email en el CRM (si no estaba en la ficha).
		$elegido = (string) ( $opciones['campo'] ?? '' );
		$campo   = $this->campo_propuesto( $s, $p, $elegido );
		if ( $existentes && null === AltaService::campo_de_email( $p->emails_por_campo(), $email ) ) {
			// Sin pisar el email de otra cuenta: cada campo de tutor/a del CRM es de una cuenta.
			$usados = self::campos_en_uso( $existentes, $p );
			$suyo   = $existente ? ( $usados[ $existente->user_login ] ?? '' ) : '';
			if ( $existente && '' === $elegido && '' !== $suyo ) {
				$campo = $suyo; // Al sustituir el email de una cuenta, va en su campo.
			}
			$ajenos = array_values( array_diff_key( $usados, $existente ? array( $existente->user_login => true ) : array() ) );
			if ( in_array( $campo, $ajenos, true ) ) {
				if ( '' === $elegido && $crear_otra && array_diff( self::CAMPOS[ Solicitudes::TUTOR ], $ajenos ) ) {
					$campo = array_values( array_diff( self::CAMPOS[ Solicitudes::TUTOR ], $ajenos ) )[0];
				} else {
					$res['errores'][] = sprintf( 'El campo «%s» del CRM es el email de otra cuenta familiar de esta familia: elige el otro campo, o marca «Sustituir» y elige la cuenta cuyo email cambia.', Usuarios::etiqueta_campo_email( $campo ) );
					return $res;
				}
			}
		}
		$crm = $this->guardar_email_en_crm( $p, $campo, $email );
		if ( '' !== $crm['aviso'] ) {
			$res['avisos'][] = $crm['aviso'];
		}
		$res['email_crm'] = $crm['cambiado'];

		// 2. La cuenta (y la invitación con la bienvenida). Si la cuenta ya existe,
		//    primero pasa a tener este email: la invitación tiene que llegar a él.
		$alta = ( new AltaService( $this->repo, new Numeracion( $this->repo ) ) )->con_bienvenida();
		if ( Solicitudes::TUTOR === $s['tipo'] ) {
			if ( $crear_otra ) {
				$r = $alta->alta_tutor( $p->num_socio_asde, $hermanos, '', $email, true );
				if ( ! $r->ok ) {
					$res['errores'] = $r->errores;
					return $res;
				}
				$user = $r->usuario;
			} elseif ( $existente ) {
				$this->cambiar_email( $existente, $p->num_socio_asde, $email );
				$enviada = false;
				if ( $hermanos || ! in_array( Roles::TUTOR, (array) $existente->roles, true ) ) {
					// Hermanos nuevos o cuenta desactivada: se amplía o se reactiva.
					$r       = $alta->alta_tutor( $p->num_socio_asde, $hermanos, '', $email );
					$enviada = $r->ok && $r->invitacion_enviada;
				}
				if ( ! $enviada ) {
					Invitacion::enviar( $existente, Invitacion::RESET, $this->repo, true );
				}
				$user = $existente;
			} else {
				$r = $alta->alta_tutor( $p->num_socio_asde, $hermanos, '', $email );
				if ( ! $r->ok ) {
					$res['errores'] = $r->errores;
					return $res;
				}
				$user = $r->usuario;
			}
		} else {
			$vinculado = Usuarios::por_socio( $p->num_socio_asde );
			if ( $vinculado ) {
				$this->cambiar_email( $vinculado, $p->num_socio_asde, $email );
			}
			if ( $vinculado && in_array( Roles::PARTICIPANTE, (array) $vinculado->roles, true ) ) {
				// Ya tenía cuenta de participante: solo cambia su email y elige contraseña.
				Invitacion::enviar( $vinculado, Invitacion::RESET, $this->repo, true );
				$user = $vinculado;
			} else {
				$r = $alta->alta_persona( $p->num_socio_asde, array( Roles::PARTICIPANTE ), array(), 'email1', $email );
				if ( ! $r->ok ) {
					$res['errores'] = $r->errores;
					return $res;
				}
				$user = $r->usuario;
				if ( $vinculado && ! $r->invitacion_enviada ) {
					Invitacion::enviar( $user, Invitacion::RESET, $this->repo, true ); // Cuenta reactivada que ya tenía contraseña.
				}
			}
		}

		Solicitudes::resolver( (int) $s['id'], Solicitudes::APROBADA, $revisor, '', $user->ID );
		Logger::log(
			'solicitud_aprobada',
			'solicitud',
			(string) $s['id'],
			array(
				'tipo'      => $s['tipo'],
				'usuario'   => $user->ID,
				'email_crm' => $crm['cambiado'] ? $campo : '',
			)
		);
		$res['ok']      = true;
		$res['usuario'] = get_userdata( $user->ID );
		return $res;
	}

	/** Resultado del último aviso por email de un rechazo (vacío si no se avisó). @var array{estado:string, destino:string, error:string} */
	public array $aviso = array(
		'estado'  => '',
		'destino' => '',
		'error'   => '',
	);

	/**
	 * Rechaza la solicitud. El motivo es solo para el grupo. Con $avisar, se manda
	 * un email genérico a quien la pidió, con el email de contacto del grupo (P-36).
	 */
	public function rechazar( array $s, int $revisor, string $motivo, bool $avisar = true ): bool {
		$this->aviso = array(
			'estado'  => '',
			'destino' => '',
			'error'   => '',
		);
		$ok = Solicitudes::resolver( (int) $s['id'], Solicitudes::RECHAZADA, $revisor, $motivo );
		if ( $ok ) {
			if ( $avisar ) {
				$this->aviso = EmailRechazo::enviar( $s );
			}
			Logger::log(
				'solicitud_rechazada',
				'solicitud',
				(string) $s['id'],
				array(
					'tipo'  => $s['tipo'],
					'aviso' => $avisar ? $this->aviso['estado'] : 'no',
				)
			);
		}
		return $ok;
	}

	/**
	 * La cuenta pasa a entrar con este email: se guarda y su origen en el CRM es
	 * el campo donde ahora está (o ninguno, para que el CRM no devuelva el anterior).
	 */
	private function cambiar_email( \WP_User $u, string $socio_base, string $email ): void {
		EmailCuenta::guardar( $u->ID, $email );
		$p     = $this->repo->obtener( $socio_base );
		$campo = $p ? AltaService::campo_de_email( $p->emails_por_campo(), $email ) : null;
		if ( $p && null !== $campo ) {
			Usuarios::fijar_email_origen( $u->ID, $p->num_socio_asde, $campo );
		} else {
			delete_user_meta( $u->ID, Usuarios::META_EMAIL_ORIGEN );
		}
	}

	/** @return array{resultado:string, persona:?Persona, via:string} */
	private static function resultado( string $resultado, ?Persona $p = null, string $via = '' ): array {
		return array(
			'resultado' => $resultado,
			'persona'   => $p,
			'via'       => $via,
		);
	}
}
