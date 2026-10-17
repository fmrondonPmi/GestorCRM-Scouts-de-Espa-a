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
			$participante = asde_t( 'No tiene membresía activa en una sección educativa.' );
		} elseif ( null === $edad ) {
			$participante = asde_t( 'No consta su fecha de nacimiento en el CRM.' );
		} elseif ( $edad < self::EDAD_MINIMA_PARTICIPANTE ) {
			$participante = sprintf( asde_t( 'Tiene %d años: por debajo de %d años solo accede su familia (cuenta familiar).' ), $edad, self::EDAD_MINIMA_PARTICIPANTE );
		} else {
			$participante = null;
		}

		$adulto = $p->es_adulto_voluntario() ? null : asde_t( 'No tiene membresía activa como Scouter o Voluntariado de apoyo.' );

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
			return asde_t( 'No tiene membresía activa en una sección educativa.' );
		}
		$edad = $p->edad();
		if ( null !== $edad && $edad >= 18 ) {
			return asde_t( 'Es mayor de edad: solo accede la propia persona.' );
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
			return AltaResult::error( asde_t( 'No se encuentra esa persona en el CRM del grupo.' ) );
		}

		$vinculado  = Usuarios::por_socio( $p->num_socio_asde );
		$ya_scouter = $vinculado && in_array( Roles::SCOUTER, (array) $vinculado->roles, true );

		$pedidos = array_values( array_intersect( self::ROLES_PERSONA, $roles ) );
		$roles   = array_values( array_diff( $pedidos, $vinculado ? (array) $vinculado->roles : array() ) );
		// Sin roles nuevos solo tiene sentido si es scouter y se cambian sus secciones.
		if ( ! $roles && ! $ya_scouter ) {
			return AltaResult::error( $pedidos ? asde_t( 'Ya tiene ese rol: no hay nada que cambiar.' ) : asde_t( 'Elige al menos un rol.' ) );
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
				$errores[] = asde_t( '«Voluntario sin sección» no se combina con secciones educativas.' );
			}
			if ( $sera_scouter ) {
				$errores[] = asde_t( 'Un voluntario sin sección no puede tener el rol de Scouter: quítalo o elige sus secciones educativas.' );
			}
			if ( ! $con_resp ) {
				$errores[] = asde_t( 'A un voluntario sin sección solo se le puede dar acceso como Tesorería, Secretaría, Coordinación o Intendencia: marca al menos una.' );
			}
		} else {
			if ( array_intersect( self::ROLES_RESPONSABILIDAD, $roles ) && ! $sera_scouter ) {
				$errores[] = asde_t( 'Secretaría, Coordinación, Tesorería e Intendencia son responsabilidades de un scouter: marca también el rol de Scouter, o elige «Voluntario sin sección».' );
			}
			if ( $sera_scouter && ! $secciones ) {
				$errores[] = asde_t( 'Indica al menos una sección educativa para el rol de Scouter.' );
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
					return AltaResult::error( asde_t( 'El email de la solicitud no es válido.' ) );
				}
				$campo_email = (string) self::campo_de_email( $emails, $email );
			} elseif ( ! isset( $emails[ $campo_email ] ) ) {
				return AltaResult::error( asde_t( 'La ficha no tiene email en el CRM. Añádelo en el CRM (campo Correo electrónico) y vuelve a intentarlo.' ) );
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
			return AltaResult::error( asde_t( 'No se encuentra esa persona en el CRM del grupo.' ) );
		}
		if ( count( $personas ) !== count( $nums ) ) {
			$errores[] = asde_t( 'Alguno de los participantes elegidos no está en el CRM del grupo.' );
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
				$errores[] = asde_t( 'El email de la solicitud no es válido.' );
			}
			$campo_email = (string) self::campo_de_email( $emails, $email );
		} elseif ( ! isset( $emails[ $campo_email ] ) ) {
			$errores[] = asde_t( 'Elige un email de la ficha del CRM para la cuenta familiar (si no hay ninguno, añádelo en el CRM).' );
		}
		if ( $errores ) {
			return AltaResult::error( ...$errores );
		}

		// ¿Ya existe una cuenta familiar para alguno de ellos? Se amplía esa (la del primer tutor/a).
		$cuentas = Usuarios::cuentas_familiares( $nums );
		$cuenta  = $cuentas[0] ?? null;

		if ( $otro_tutor ) {
			// Cuenta propia para el otro tutor/a: representa a los mismos menores que la primera.
			if ( ! $cuenta ) {
				return AltaResult::error( asde_t( 'Todavía no hay una cuenta familiar: crea primero la del primer tutor/a.' ) );
			}
			if ( count( $cuentas ) >= Usuarios::MAX_CUENTAS_FAMILIARES ) {
				return AltaResult::error( sprintf( asde_t( 'Esta familia ya tiene las %d cuentas de tutor/a (%s). Para cambiar el email de una, usa «Sustituir su email» al aprobar una solicitud.' ), Usuarios::MAX_CUENTAS_FAMILIARES, implode( ' y ', array_map( static fn( \WP_User $c ) => $c->user_login, $cuentas ) ) ) );
			}
			$mail = EmailCuenta::normalizar( (string) ( $email ?? ( $emails[ $campo_email ] ?? '' ) ) );
			if ( ! is_email( $mail ) ) {
				return AltaResult::error( asde_t( 'Elige el email del otro tutor/a (si la ficha no lo tiene, añádelo en el CRM).' ) );
			}
			foreach ( $cuentas as $c ) {
				if ( EmailCuenta::leer( $c->ID ) === $mail ) {
					return AltaResult::error( sprintf( asde_t( 'Ese email ya es el de la cuenta familiar %s: es la misma persona.' ), $c->user_login ) );
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
				return AltaResult::error( asde_t( 'No se puede crear la segunda cuenta: falta un nº de socio/a válido para el usuario.' ) );
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
				return AltaResult::error( sprintf( asde_t( 'La cuenta familiar %s ya representa a esos participantes: no hay nada que cambiar.' ), $cuenta->user_login ) );
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
				return new \WP_Error( 'asde_alta', sprintf( asde_t( 'El nº de socio local de %s (%s) está duplicado en el CRM. Corrígelo en el CRM antes de darle acceso.' ), $p->nombre_completo(), $n['numero'] ) );
			case Numeracion::INVALIDO:
				return new \WP_Error( 'asde_alta', sprintf( asde_t( 'El nº de socio local de %s en el CRM ("%s") no tiene un formato válido. Corrígelo en el CRM antes de darle acceso.' ), $p->nombre_completo(), $p->num_socio_local_crm ) );
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
		if ( Grupo::id() ) {
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
			return new \WP_Error( 'asde_alta', sprintf( asde_t( 'Ya existe un usuario web %s que no está vinculado a esta ficha. Revísalo en el listado de usuarios.' ), $login ) );
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
			return new \WP_Error( 'asde_alta', asde_t( 'No se ha podido crear el usuario: ' ) . $id->get_error_message() );
		}
		return get_userdata( $id );
	}
}
