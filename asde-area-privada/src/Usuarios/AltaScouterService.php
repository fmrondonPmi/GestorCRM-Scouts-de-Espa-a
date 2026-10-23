<?php
namespace AsdeBalears\AreaPrivada\Usuarios;

use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Crm\PersonaRepository;
use AsdeBalears\AreaPrivada\Domain\Persona;
use AsdeBalears\AreaPrivada\Domain\Secciones;
use AsdeBalears\AreaPrivada\Install\Roles;

defined( 'ABSPATH' ) || exit;

/**
 * Alta de scouters con el enlace del grupo (v0.16, doc 31; decisiones del usuario del 2026-10-03):
 *
 * - Se comprueba solo el DNI/NIE: tiene que ser el de una ficha **de Scouter o Voluntariado con
 *   membresía activa** del grupo. El enlace (con su clave) es la otra mitad de la comprobación.
 * - La persona elige **Scouter y sus secciones**: la cuenta se crea al momento y la invitación va
 *   **al email de su ficha del CRM** (no al que escribe: quien solo sepa un DNI no se queda con la cuenta).
 * - **Secretaría, Coordinación y Tesorería** solo se piden: las confirma el equipo de coordinación.
 *   Un «voluntario sin sección» no tiene rol de base, así que su alta entera va a revisión.
 * - Si su ficha **no tiene email**, el alta va a revisión con el email que escribe.
 * - Siempre queda una **solicitud** (de adulto: solo la ven Secretaría y Coordinación) para que el
 *   equipo lo vea y confirme; si la cuenta ya se creó, la solicitud lo dice y se cierra o se completa.
 * - La respuesta en pantalla es la misma pase lo que pase: nadie puede averiguar con el formulario
 *   si un DNI es del grupo.
 */
final class AltaScouterService {

	public const MODO_SCOUTER     = 'scouter';
	public const MODO_SIN_SECCION = 'sin_seccion';

	/** Resultados (solo para el registro y las pruebas: en pantalla se contesta igual). */
	public const CUENTA    = 'cuenta';    // Cuenta creada y activación enviada al email de la ficha.
	public const REVISION  = 'revision';  // Alta pendiente de revisar por el equipo de coordinación.
	public const NO_VALE   = 'no_vale';   // El DNI no es de un scouter o voluntario/a activo del grupo.
	public const YA_TIENE  = 'ya_tiene';  // Ya tiene cuenta activa.
	public const YA_PEDIDA = 'ya_pedida'; // Ya hay una solicitud suya pendiente.

	public function __construct( private PersonaRepository $repo ) {}

	public static function make(): ?self {
		$repo = PersonaRepository::make();
		return $repo ? new self( $repo ) : null;
	}

	/**
	 * Comprueba lo elegido (antes de buscar a nadie en el CRM). @return string[] errores
	 *
	 * @param string[] $secciones
	 * @param string[] $responsabilidades
	 */
	public static function errores( string $modo, array $secciones, array $responsabilidades, string $email ): array {
		$errores = array();
		if ( self::MODO_SCOUTER === $modo && ! array_intersect( Secciones::EDUCATIVAS, $secciones ) ) {
			$errores[] = asde_t( 'Elige al menos una sección en la que colaboras.' );
		}
		if ( self::MODO_SIN_SECCION === $modo && ! array_intersect( AltaService::ROLES_RESPONSABILIDAD, $responsabilidades ) ) {
			$errores[] = asde_t( 'Sin sección, elige al menos una responsabilidad: Secretaría, Coordinación, Tesorería o Intendencia.' );
		}
		if ( ! in_array( $modo, array( self::MODO_SCOUTER, self::MODO_SIN_SECCION ), true ) ) {
			$errores[] = asde_t( 'Elige cómo colaboras en el grupo.' );
		}
		if ( ! is_email( EmailCuenta::normalizar( $email ) ) ) {
			$errores[] = asde_t( 'Escribe un email válido.' );
		}
		return $errores;
	}

	/**
	 * El paso del formulario, en el grupo actual.
	 *
	 * @param array{documento:string, modo:string, secciones:string[], responsabilidades:string[], email:string} $d
	 * @return array{resultado:string, solicitud:int, usuario:int}
	 */
	public function registrar( array $d ): array {
		$secciones = array_values( array_intersect( Secciones::EDUCATIVAS, (array) $d['secciones'] ) );
		$resp      = array_values( array_intersect( AltaService::ROLES_RESPONSABILIDAD, (array) $d['responsabilidades'] ) );
		$scouter   = self::MODO_SCOUTER === $d['modo'];
		if ( ! $scouter ) {
			$secciones = array( Secciones::SIN_SECCION );
		}

		$p = $this->ficha( (string) $d['documento'] );
		if ( ! $p ) {
			return $this->resultado( self::NO_VALE );
		}
		$cuenta = Usuarios::por_socio( $p->num_socio_asde );
		if ( $cuenta && Usuarios::DESACTIVADO !== Usuarios::estado( $cuenta ) ) {
			return $this->resultado( self::YA_TIENE, $p );
		}
		$email_ficha = (string) ( $p->emails_por_campo()['email1'] ?? '' );
		$email       = '' !== $email_ficha ? $email_ficha : EmailCuenta::normalizar( (string) $d['email'] );
		if ( Solicitudes::hay_pendiente( $p->num_socio_asde, Solicitudes::SOCIO, $email ) ) {
			return $this->resultado( self::YA_PEDIDA, $p );
		}

		$pedidos = array_merge( $scouter ? array( Roles::SCOUTER ) : array(), $resp );
		$datos   = array(
			'nombre'            => $p->nombre_completo(),
			'email'             => $email,
			'documento'         => PersonaRepository::normalizar_documento( (string) $d['documento'] ),
			'nacimiento'        => '',
			'nombre_menor'      => '',
			'roles_pedidos'     => $pedidos,
			'secciones_pedidas' => $secciones,
			'email_escrito'     => EmailCuenta::normalizar( (string) $d['email'] ),
		);

		// Scouter con email en su ficha (y sin cuenta desactivada): la cuenta se crea al momento.
		$usuario = 0;
		if ( $scouter && '' !== $email_ficha && ! $cuenta ) {
			$alta = AltaService::make();
			$r    = $alta ? $alta->con_bienvenida()->alta_persona( $p->num_socio_asde, array( Roles::SCOUTER ), $secciones, 'email1' ) : null;
			if ( $r && $r->ok && $r->usuario ) {
				$usuario               = $r->usuario->ID;
				$datos['cuenta_creada'] = $usuario;
			} else {
				$datos['error_alta'] = $r ? implode( ' ', $r->errores ) : asde_t( 'CRM no configurado.' );
			}
		}

		$id = Solicitudes::crear( Solicitudes::SOCIO, Solicitudes::POR_ENLACE, $p, $datos );
		$resultado = $usuario ? self::CUENTA : self::REVISION;
		Logger::log(
			'alta_scouter_enlace',
			'solicitud',
			(string) $id,
			array(
				'resultado' => $resultado,
				'usuario'   => $usuario,
				'roles'     => $pedidos,
				'secciones' => $secciones,
			)
		);
		return array(
			'resultado' => $resultado,
			'solicitud' => $id,
			'usuario'   => $usuario,
		);
	}

	/** La ficha de Scouter o Voluntariado activa del grupo con ese documento (si hay una sola). */
	private function ficha( string $documento ): ?Persona {
		$doc = PersonaRepository::normalizar_documento( $documento );
		if ( '' === $doc ) {
			return null;
		}
		$adultos = array_values( array_filter( $this->repo->por_documento( $doc ), static fn( Persona $p ) => $p->es_adulto_voluntario() ) );
		return 1 === count( $adultos ) ? $adultos[0] : null;
	}

	/** @return array{resultado:string, solicitud:int, usuario:int} */
	private function resultado( string $r, ?Persona $p = null ): array {
		// Lo que no prospera también queda anotado (sin el DNI), para ver si alguien prueba documentos.
		Logger::log( 'alta_scouter_enlace', 'socio', $p ? $p->num_socio_asde : '', array( 'resultado' => $r ) );
		return array(
			'resultado' => $r,
			'solicitud' => 0,
			'usuario'   => 0,
		);
	}
}
