<?php
namespace AsdeBalears\AreaPrivada\Usuarios;

use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Crm\PersonaRepository;
use AsdeBalears\AreaPrivada\Domain\Grupo;
use AsdeBalears\AreaPrivada\Domain\Identidad;
use AsdeBalears\AreaPrivada\Domain\NumeroSocio;
use AsdeBalears\AreaPrivada\Domain\Persona;
use AsdeBalears\AreaPrivada\Domain\Secciones;
use AsdeBalears\AreaPrivada\Install\Roles;
use AsdeBalears\AreaPrivada\Socios\Numeracion;
use AsdeBalears\AreaPrivada\Tesoreria\FamiliasTable;

defined( 'ABSPATH' ) || exit;

/**
 * Alta de usuarios del área privada a partir de fichas del CRM.
 *
 * Reglas (decididas con el usuario, 2026-09-26):
 * - Dan de alta Secretaría, Coordinación y administradores (asde_manage_users).
 * - Usuario de acceso = nº de socio local (7 + grupo + 4 dígitos). Si la persona
 *   no tiene, se le GENERA automáticamente (pendiente de pasar al CRM).
 * - Participante: membresía activa en una sección educativa y 14 años o más.
 * - Scouter: membresía activa como Scouter o Voluntariado; sus secciones se
 *   asignan aquí (el CRM no las guarda).
 * - Secretaría y Coordinación son responsabilidades de un scouter: solo se
 *   asignan a quien es (o se hace a la vez) Scouter.
 * - Tutores: cuenta familiar "nº del menor + T" (p. ej. 76840390T), que puede
 *   representar a varios hermanos menores de edad.
 * - En WordPress no se guardan nombres. El email de la cuenta (el del campo del
 *   CRM elegido en el alta, o el de una solicitud de acceso) se guarda cifrado
 *   (EmailCuenta); si viene de un campo del CRM, sigue a ese campo.
 *
 * Toda comprobación se repite en el servidor releyendo el CRM.
 */
final class AltaService {

	public const EDAD_MINIMA_PARTICIPANTE = 14;

	public const ROLES_PERSONA         = array( Roles::PARTICIPANTE, Roles::SCOUTER, Roles::SECRETARIA, Roles::COORDINACION, Roles::TESORERIA, Roles::INTENDENTE );
	public const ROLES_RESPONSABILIDAD = array( Roles::SECRETARIA, Roles::COORDINACION, Roles::TESORERIA, Roles::INTENDENTE );

	/** Cargo en el CRM (cargoeducativo_c) → rol que se sugiere en el alta. */
	public const ROL_POR_CARGO = array(
		'secretariogrupoGS'   => Roles::SECRETARIA,
		'coordinadorgrupoGS'  => Roles::COORDINACION,
		'tesorerogrupoGS'     => Roles::TESORERIA,
		'responsablematerialGS' => Roles::INTENDENTE,
		'scouterseccionGS'    => Roles::SCOUTER,
		'coordinadorseccionGS' => Roles::SCOUTER,
	);

	/** La invitación lleva el mensaje de bienvenida (alta desde una solicitud de acceso aprobada). */
	private bool $bienvenida = false;

	public function __construct(
		private PersonaRepository $personas,
		private Numeracion $numeracion
	) {}

	public function con_bienvenida(): self {
		$this->bienvenida = true;
		return $this;
	}

	public static function make(): ?self {
		$repo = PersonaRepository::make();
		return $repo ? new self( $repo, new Numeracion( $repo ) ) : null;
	}

	public function numeracion(): Numeracion {
		return $this->numeracion;
	}

	/**
	 * Roles de persona que se le pueden dar según el CRM.
	 *
	 * @return array<string, ?string> rol => null si es elegible, o el motivo por el que no.
	 */
	public function elegibilidad( Persona $p ): array {
		$edad = $p->edad();

		if ( ! $p->es_participante() ) {
			$participante = __( 'Has no active membership in an educational section.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' );
		} elseif ( null === $edad ) {
			$participante = __( 'Their date of birth is not recorded in the CRM.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' );
		} elseif ( $edad < self::EDAD_MINIMA_PARTICIPANTE ) {
			$participante = sprintf( /* translators: 1: number, 2: number */ __( 'They are %1$d years old: under %2$d years of age only their family has access (family account).', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $edad, self::EDAD_MINIMA_PARTICIPANTE );
		} else {
			$participante = null;
		}

		$adulto = $p->es_adulto_voluntario() ? null : __( 'Has no active membership as Scouter or Support Volunteer.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' );

		return array(
			Roles::PARTICIPANTE => $participante,
			Roles::SCOUTER      => $adulto,
			Roles::SECRETARIA   => $adulto,
			Roles::COORDINACION => $adulto,
			Roles::TESORERIA    => $adulto,
			Roles::INTENDENTE   => $adulto,
		);
	}

	/** null si una cuenta familiar puede representar a esta persona; si no, el motivo. */
	public function motivo_no_representable( Persona $p ): ?string {
		if ( ! $p->es_participante() ) {
			return __( 'Has no active membership in an educational section.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' );
		}
		$edad = $p->edad();
		if ( null !== $edad && $edad >= 18 ) {
			return __( 'They are of legal age: only the person themselves has access.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' );
		}
		return null;
	}

	/**
	 * Emails del CRM utilizables para la cuenta de la propia persona.
	 *
	 * @return array<string, string> campo => email
	 */
	public function emails_persona( Persona $p ): array {
		return array_intersect_key( $p->emails_por_campo(), array( 'email1' => true ) );
	}

	/**
	 * Emails del CRM utilizables para la cuenta familiar de un menor.
	 *
	 * @return array<string, string> campo => email
	 */
	public function emails_familia( Persona $p ): array {
		return $p->emails_por_campo();
	}

	/** Campo del CRM (entre los dados) en el que está ya este email, o null. @param array<string, string> $emails campo => email */
	public static function campo_de_email( array $emails, string $email ): ?string {
		$campo = array_search( EmailCuenta::normalizar( $email ), $emails, true );
		return false === $campo ? null : (string) $campo;
	}

	/**
	 * Da acceso a la persona de una ficha del CRM con uno o varios roles.
	 *
	 * @param string[]    $roles
	 * @param string[]    $secciones Secciones educativas (obligatorio si será Scouter).
	 * @param string      $campo_email Campo del CRM con el email de la cuenta.
	 * @param string|null $email       Email escrito en una solicitud de acceso (en lugar del campo del CRM).
	 */
	public function alta_persona( string $num_asde, array $roles, array $secciones = array(), string $campo_email = 'email1', ?string $email = null ): AltaResult {
		$p = $this->personas->obtener( $num_asde );
		if ( ! $p ) {
			return AltaResult::error( __( 'That person cannot be found in the group\'s CRM.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
		}

		$vinculado  = Usuarios::por_socio( $p->num_socio_asde );
		if ( $vinculado && ! Usuarios::del_grupo_actual( $vinculado ) ) {
			// La cuenta es de otro grupo de la instalación: no se la lleva este sin avisar (doc 59, RC-USU-1).
			return AltaResult::error( __( 'This person\'s website account belongs to another group: it cannot be changed from here.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
		}
		$ya_scouter = $vinculado && in_array( Roles::SCOUTER, (array) $vinculado->roles, true );

		$pedidos = array_values( array_intersect( self::ROLES_PERSONA, $roles ) );
		$roles   = array_values( array_diff( $pedidos, $vinculado ? (array) $vinculado->roles : array() ) );
		// Sin roles nuevos solo tiene sentido si es scouter y se cambian sus secciones.
		if ( ! $roles && ! $ya_scouter ) {
			return AltaResult::error( $pedidos ? __( 'Already has that role: there is nothing to change.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) : __( 'Choose at least one role.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
		}

		$eleg    = $this->elegibilidad( $p );
		$errores = array();
		foreach ( $roles as $rol ) {
			if ( null !== $eleg[ $rol ] ) {
				$errores[] = sprintf( '%s: %s', Roles::label( $rol ), $eleg[ $rol ] );
			}
		}

		$sera_scouter = $ya_scouter || in_array( Roles::SCOUTER, $roles, true );
		$secciones    = array_values( array_intersect( array_merge( Secciones::EDUCATIVAS, array( Secciones::SIN_SECCION ) ), $secciones ) );
		$sin_seccion  = in_array( Secciones::SIN_SECCION, $secciones, true );
		$con_resp     = array_intersect( self::ROLES_RESPONSABILIDAD, array_merge( $roles, $vinculado ? (array) $vinculado->roles : array() ) );
		if ( $sin_seccion ) {
			// Voluntario sin sección: solo Tesorería, Secretaría, Coordinación o Intendencia, sin Scouter.
			if ( count( $secciones ) > 1 ) {
				$errores[] = __( '"Volunteer without a section" cannot be combined with educational sections.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' );
			}
			if ( $sera_scouter ) {
				$errores[] = __( 'A volunteer without a section cannot have the Scouter role: remove it or choose their educational sections.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' );
			}
			if ( ! $con_resp ) {
				$errores[] = __( 'A volunteer without a section can only be given access as Treasury, Secretary\'s office, Coordination or Quartermaster: tick at least one.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' );
			}
		} else {
			if ( array_intersect( self::ROLES_RESPONSABILIDAD, $roles ) && ! $sera_scouter ) {
				$errores[] = __( 'Secretary\'s office, Coordination, Treasury and Quartermaster are responsibilities of a scouter: also tick the Scouter role, or choose "Volunteer without a section".', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' );
			}
			if ( $sera_scouter && ! $secciones ) {
				$errores[] = __( 'State at least one educational section for the Scouter role.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' );
			}
		}
		if ( $errores ) {
			return AltaResult::error( ...$errores );
		}

		$accion = AltaResult::ACTUALIZADO;
		$user   = $vinculado;
		if ( ! $user ) {
			$emails = $this->emails_persona( $p );
			if ( null !== $email ) {
				if ( ! is_email( EmailCuenta::normalizar( $email ) ) ) {
					return AltaResult::error( __( 'The email on the request is not valid.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
				}
				$campo_email = (string) self::campo_de_email( $emails, $email );
			} elseif ( ! isset( $emails[ $campo_email ] ) ) {
				return AltaResult::error( __( 'The record has no email in the CRM. Add it in the CRM (Email field) and try again.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
			}
			$numero = $this->numero_para_usuario( $p );
			if ( is_wp_error( $numero ) ) {
				return AltaResult::error( $numero->get_error_message() );
			}
			$user = $this->crear_usuario( $numero, $roles[0] );
			if ( is_wp_error( $user ) ) {
				return AltaResult::error( $user->get_error_message() );
			}
			update_user_meta( $user->ID, Usuarios::META_SOCIO, $p->num_socio_asde );
			if ( '' !== $campo_email ) {
				Usuarios::fijar_email_origen( $user->ID, $p->num_socio_asde, $campo_email );
			}
			EmailCuenta::guardar( $user->ID, $email ?? $emails[ $campo_email ] );
			$accion = AltaResult::CREADO;
		}

		foreach ( $roles as $rol ) {
			$user->add_role( $rol );
		}
		if ( $sera_scouter || $sin_seccion ) {
			// Las secciones enviadas sustituyen a las anteriores.
			delete_user_meta( $user->ID, Usuarios::META_SECCION );
			Usuarios::anadir_meta_multiple( $user->ID, Usuarios::META_SECCION, $secciones );
		}

		// Solo cambian las secciones → no se reenvía la invitación.
		$enviada = $this->despues_de_alta( $user, $accion, (bool) $roles );
		Logger::log(
			AltaResult::CREADO === $accion ? 'alta_usuario' : 'usuario_actualizado',
			'user',
			(string) $user->ID,
			array(
				'socio'      => $p->num_socio_asde,
				'roles'      => $roles,
				'secciones'  => $sera_scouter || $sin_seccion ? $secciones : array(),
				'invitacion' => $enviada,
			)
		);

		return new AltaResult( true, get_userdata( $user->ID ), $accion, $enviada );
	}

	/**
	 * Da acceso a la familia de un menor (cuenta familiar "nº del menor + T").
	 *
	 * @param string      $num_base    Nº Scouts de España del menor desde cuya ficha se da el alta.
	 * @param string[]    $hermanos    Otros menores (nº Scouts de España) que también representa.
	 * @param string      $campo_email Campo del CRM de la ficha base con el email de la familia.
	 * @param string|null $email       Email escrito en una solicitud de acceso (en lugar del campo del CRM).
	 * @param bool        $otro_tutor  Cuenta propia para el otro tutor/a de una familia que ya tiene la del primero
	 *                                 («nº + T2», con su propio email y la misma familia de Tesorería).
	 */
	public function alta_tutor( string $num_base, array $hermanos, string $campo_email, ?string $email = null, bool $otro_tutor = false ): AltaResult {
		$nums     = array_values( array_unique( array_merge( array( $num_base ), array_map( 'strval', $hermanos ) ) ) );
		$personas = $this->personas->obtener_varias( $nums );
		$base     = $personas[ $num_base ] ?? null;

		$errores = array();
		if ( ! $base ) {
			return AltaResult::error( __( 'That person cannot be found in the group\'s CRM.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
		}
		if ( count( $personas ) !== count( $nums ) ) {
			$errores[] = __( 'One of the chosen participants is not in the group\'s CRM.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' );
		}
		foreach ( $personas as $p ) {
			$motivo = $this->motivo_no_representable( $p );
			if ( null !== $motivo ) {
				$errores[] = sprintf( '%s: %s', $p->nombre_completo(), $motivo );
			}
		}
		$emails = $this->emails_familia( $base );
		if ( null !== $email ) {
			if ( ! is_email( EmailCuenta::normalizar( $email ) ) ) {
				$errores[] = __( 'The email on the request is not valid.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' );
			}
			$campo_email = (string) self::campo_de_email( $emails, $email );
		} elseif ( ! isset( $emails[ $campo_email ] ) ) {
			$errores[] = __( 'Choose an email from the CRM record for the family account (if there is none, add it in the CRM).', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' );
		}
		if ( $errores ) {
			return AltaResult::error( ...$errores );
		}

		// ¿Ya existe una cuenta familiar para alguno de ellos? Se amplía esa (la del primer tutor/a).
		$cuentas = Usuarios::cuentas_familiares( $nums );
		$cuenta  = $cuentas[0] ?? null;
		if ( array_filter( $cuentas, static fn( \WP_User $c ): bool => ! Usuarios::del_grupo_actual( $c ) ) ) {
			// Una cuenta familiar de estos menores es de otro grupo de la instalación (doc 59, RC-USU-1).
			return AltaResult::error( __( 'This person\'s website account belongs to another group: it cannot be changed from here.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
		}

		if ( $otro_tutor ) {
			// Cuenta propia para el otro tutor/a: representa a los mismos menores que la primera.
			if ( ! $cuenta ) {
				return AltaResult::error( __( 'There is no family account yet: first create the first guardian\'s.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
			}
			if ( count( $cuentas ) >= Usuarios::MAX_CUENTAS_FAMILIARES ) {
				return AltaResult::error( sprintf( /* translators: 1: number, 2: text */ __( 'This family already has the %1$d guardian accounts (%2$s). To change the email of one, use "Replace their email" when approving a request.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), Usuarios::MAX_CUENTAS_FAMILIARES, implode( ' y ', array_map( static fn( \WP_User $c ) => $c->user_login, $cuentas ) ) ) );
			}
			$mail = EmailCuenta::normalizar( (string) ( $email ?? ( $emails[ $campo_email ] ?? '' ) ) );
			if ( ! is_email( $mail ) ) {
				return AltaResult::error( __( 'Choose the other guardian\'s email (if the record does not have it, add it in the CRM).', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
			}
			foreach ( $cuentas as $c ) {
				if ( EmailCuenta::leer( $c->ID ) === $mail ) {
					return AltaResult::error( sprintf( /* translators: %s: text */ __( 'That email is already that of the family account %s: it is the same person.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $c->user_login ) );
				}
			}
			$nums = array_values( array_unique( array_merge( $nums, Usuarios::representados( $cuenta->ID ) ) ) );
			$base_num = NumeroSocio::socio_de_usuario_tutor( $cuenta->user_login );
			if ( null !== $base_num ) {
				$base_num = Identidad::convertir( $base_num ); // Si la cuenta es de antes del cambio de usuario de acceso, la nueva sigue el modo actual.
			}
			if ( null === $base_num ) {
				$numero   = $this->numero_para_usuario( $base );
				$base_num = is_wp_error( $numero ) ? null : (string) $numero;
			}
			if ( null === $base_num ) {
				return AltaResult::error( __( 'The second account cannot be created: a valid member no. for the username is missing.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
			}
			$nueva = $this->crear_usuario( NumeroSocio::usuario_tutor( $base_num, 2 ), Roles::TUTOR );
			if ( is_wp_error( $nueva ) ) {
				return AltaResult::error( $nueva->get_error_message() );
			}
			if ( '' !== $campo_email ) {
				Usuarios::fijar_email_origen( $nueva->ID, $base->num_socio_asde, $campo_email );
			}
			EmailCuenta::guardar( $nueva->ID, $mail );
			$cuenta = $nueva;
		}

		$accion    = AltaResult::ACTUALIZADO;
		$novedades = true;
		if ( $otro_tutor ) {
			$accion = AltaResult::CREADO;
		} elseif ( $cuenta ) {
			$nuevos    = array_diff( $nums, Usuarios::representados( $cuenta->ID ) );
			$novedades = $nuevos || ! in_array( Roles::TUTOR, (array) $cuenta->roles, true );
			if ( ! $novedades ) {
				return AltaResult::error( sprintf( /* translators: %s: text */ __( 'The family account %s already represents those participants: there is nothing to change.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $cuenta->user_login ) );
			}
		} else {
			$numero = $this->numero_para_usuario( $base );
			if ( is_wp_error( $numero ) ) {
				return AltaResult::error( $numero->get_error_message() );
			}
			$cuenta = $this->crear_usuario( NumeroSocio::usuario_tutor( $numero ), Roles::TUTOR );
			if ( is_wp_error( $cuenta ) ) {
				return AltaResult::error( $cuenta->get_error_message() );
			}
			if ( '' !== $campo_email ) {
				Usuarios::fijar_email_origen( $cuenta->ID, $base->num_socio_asde, $campo_email );
			}
			EmailCuenta::guardar( $cuenta->ID, $email ?? $emails[ $campo_email ] );
			$accion = AltaResult::CREADO;
		}

		$cuenta->add_role( Roles::TUTOR );
		Usuarios::anadir_meta_multiple( $cuenta->ID, Usuarios::META_REPRESENTADO, $nums );

		// La cuenta familiar va con su familia de Tesorería (se crea o se amplía).
		$familia = FamiliasTable::asegurar( $nums );
		if ( is_wp_error( $familia ) ) {
			Logger::log( 'familia_no_asegurada', 'user', (string) $cuenta->ID, array( 'error' => $familia->get_error_message() ) );
		}

		$enviada = $this->despues_de_alta( $cuenta, $accion, $novedades );
		Logger::log(
			AltaResult::CREADO === $accion ? 'alta_usuario' : 'usuario_actualizado',
			'user',
			(string) $cuenta->ID,
			array(
				'roles'         => array( Roles::TUTOR ),
				'representados' => $nums,
				'invitacion'    => $enviada,
			)
		);

		return new AltaResult( true, get_userdata( $cuenta->ID ), $accion, $enviada );
	}

	/**
	 * Nº utilizable como usuario. Con el nº de Scouts de España como usuario de acceso
	 * (v0.14) es el de la ficha: siempre existe y es único, y las incidencias del nº
	 * local solo se avisan (Numeracion sigue vigilándolas). Con el nº local, el de la
	 * ficha; si no tiene, se genera, y un duplicado o un formato no válido bloquea.
	 *
	 * @return string|\WP_Error
	 */
	private function numero_para_usuario( Persona $p ) {
		if ( Identidad::es_asde() ) {
			return $p->num_socio_asde;
		}
		$n = $this->numeracion->de( $p );
		switch ( $n['estado'] ) {
			case Numeracion::FALTA:
				return $this->numeracion->asignar( $p );
			case Numeracion::DUPLICADO:
				return new \WP_Error( 'asde_alta', sprintf( /* translators: 1: text, 2: text */ __( 'The local member no. of %1$s (%2$s) is duplicated in the CRM. Correct it in the CRM before giving them access.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $p->nombre_completo(), $n['numero'] ) );
			case Numeracion::INVALIDO:
				return new \WP_Error( 'asde_alta', sprintf( /* translators: 1: text, 2: text */ __( 'The local member no. of %1$s in the CRM ("%2$s") is not in a valid format. Correct it in the CRM before giving them access.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $p->nombre_completo(), $p->num_socio_local_crm ) );
			default:
				return (string) $n['numero'];
		}
	}

	/**
	 * Estado e invitación tras crear o ampliar un usuario.
	 *
	 * @param bool $novedades Si hay roles o representados nuevos (si no, no se reenvía la invitación).
	 * @return bool Si se ha enviado invitación.
	 */
	private function despues_de_alta( \WP_User $user, string $accion, bool $novedades = true ): bool {
		if ( AltaResult::CREADO === $accion ) {
			update_user_meta( $user->ID, Usuarios::META_ALTA_POR, get_current_user_id() );
			update_user_meta( $user->ID, Usuarios::META_ALTA_EN, current_time( 'mysql', true ) );
		}
		if ( Grupo::id() && Usuarios::del_grupo_actual( $user ) ) {
			// Nunca se cambia de grupo una cuenta que ya es de otro (doc 59, RC-USU-1; alta_* ya lo ha comprobado).
			update_user_meta( $user->ID, Usuarios::META_GRUPO, Grupo::id() );
		}

		// Quien ya activó su cuenta (o es un usuario previo con contraseña, p. ej. un
		// administrador) no necesita invitación: solo recibe los roles nuevos.
		$ya_tiene_acceso = Usuarios::ha_activado( $user ) || ( AltaResult::ACTUALIZADO === $accion && '' === (string) get_user_meta( $user->ID, Usuarios::META_ESTADO, true ) );
		if ( $ya_tiene_acceso ) {
			update_user_meta( $user->ID, Usuarios::META_ESTADO, Usuarios::ACTIVO );
			return false;
		}
		if ( AltaResult::ACTUALIZADO === $accion && ! $novedades ) {
			return false; // La invitación pendiente sigue siendo válida.
		}

		update_user_meta( $user->ID, Usuarios::META_ESTADO, Usuarios::INVITADO );
		return Invitacion::enviar( get_userdata( $user->ID ), Invitacion::ALTA, $this->personas, $this->bienvenida );
	}

	/**
	 * Usuario sin datos personales: login = nº de socio, sin email ni nombre.
	 *
	 * @return \WP_User|\WP_Error
	 */
	private function crear_usuario( string $login, string $rol ) {
		if ( username_exists( $login ) ) {
			return new \WP_Error( 'asde_alta', sprintf( /* translators: %s: text */ __( 'A web user %s that is not linked to this record already exists. Check it in the user list.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $login ) );
		}
		$id = wp_insert_user(
			array(
				'user_login'           => $login,
				'user_email'           => '',
				// Contraseña aleatoria que nadie conoce: la persona elige la suya al activar.
				'user_pass'            => wp_generate_password( 40, true, true ),
				'display_name'         => $login,
				'nickname'             => $login,
				'role'                 => $rol,
				'show_admin_bar_front' => 'false',
			)
		);
		if ( is_wp_error( $id ) ) {
			return new \WP_Error( 'asde_alta', __( 'The user could not be created: ', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) . $id->get_error_message() );
		}
		return get_userdata( $id );
	}
}
