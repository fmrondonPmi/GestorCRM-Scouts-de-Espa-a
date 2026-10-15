<?php
namespace AsdeBalears\AreaPrivada\Usuarios;

use AsdeBalears\AreaPrivada\Crm\Altas;
use AsdeBalears\AreaPrivada\Crm\CrmException;
use AsdeBalears\AreaPrivada\Crm\PersonaRepository;
use AsdeBalears\AreaPrivada\Domain\Grupo;
use AsdeBalears\AreaPrivada\Domain\Persona;
use AsdeBalears\AreaPrivada\Frontend\Pages;
use AsdeBalears\AreaPrivada\Frontend\TemplateLoader;
use AsdeBalears\AreaPrivada\Idiomas\Idioma;
use AsdeBalears\AreaPrivada\Inventario\AvisoTesoreria;

defined( 'ABSPATH' ) || exit;

/**
 * Aviso por email de una baja dada desde la web (v0.23.1, doc 34; decisión del
 * usuario, P-90: «se avisa a Tesorería y a la familia»).
 *
 * - Tesorería: cada cuenta activa del grupo con ese rol (menos quien da la baja), en
 *   su idioma; si no hay ninguna con email, el email de contacto del grupo. Lleva el
 *   nombre, el nº de socio/a, la fecha, el motivo y su familia (deja de contar en la cuota).
 * - Familia: si es menor de 18, las cuentas familiares que la representan o, si no hay,
 *   los emails de sus tutores en la ficha; si es mayor de edad, su propia cuenta o el
 *   email de su ficha. Sin el motivo, con el email de contacto del grupo para dudas.
 *   Si el motivo es «fallecimiento», a la familia no se le envía nada automático.
 * - Queda en el registro de emails (tipo «baja») con el destino enmascarado y, para
 *   volver a verlo, solo el nº de socio/a, la fecha y el motivo (el nombre se relee del CRM).
 */
final class AvisoBaja {

	public const TESORERIA = 'tesoreria';
	public const FAMILIA   = 'familia';

	/** Motivos de baja con los que no se escribe a la familia. */
	private const SIN_AVISO_FAMILIA = array( 'decease' );

	/**
	 * @param string $familia Nº de su familia en Tesorería ('' si no está en ninguna).
	 * @return array{tesoreria:int, familia:int, errores:int, sin_email_familia:bool, familia_omitida:bool}
	 */
	public static function enviar( Persona $p, string $fin, string $motivo, string $familia ): array {
		$res   = array(
			'tesoreria'         => 0,
			'familia'           => 0,
			'errores'           => 0,
			'sin_email_familia' => false,
			'familia_omitida'   => in_array( $motivo, self::SIN_AVISO_FAMILIA, true ),
		);
		$repo  = PersonaRepository::make();
		$datos = array(
			'socio'    => $p->num_socio_asde,
			'fin'      => $fin,
			'motivo'   => $motivo,
			'familia'  => $familia,
			'grupo'    => Grupo::nombre(),
			'contacto' => EmailRechazo::contacto(),
		);
		$hecho = array();
		$uno   = static function ( ?\WP_User $u, string $email, string $para, string $nombre, string $idioma ) use ( $p, $datos, &$res, &$hecho ): void {
			$clave = strtolower( $email );
			if ( isset( $hecho[ $clave ] ) ) {
				return;
			}
			$hecho[ $clave ] = true;
			$ok              = Idioma::en( $idioma, static fn() => self::mandar( $u, $email, $para, $nombre, $p, $datos + array( 'para' => $para ) ) );
			$ok ? ++$res[ $para ] : ++$res['errores'];
		};

		// Tesorería.
		foreach ( AvisoTesoreria::destinatarios() as $u ) {
			$email = Contacto::email( $u, $repo );
			if ( $email ) {
				$uno( $u, $email, self::TESORERIA, Contacto::saludo( $u, $repo ), Idioma::de_usuario( $u->ID ) );
			}
		}
		if ( ! $res['tesoreria'] && '' !== $datos['contacto'] ) {
			$uno( null, $datos['contacto'], self::TESORERIA, '', Idioma::por_defecto() );
		}

		// Familia (o la propia persona, si es mayor de edad).
		if ( $res['familia_omitida'] ) {
			return $res;
		}
		$antes  = count( $hecho );
		$menor  = $p->es_menor();
		$cuentas = $menor ? Usuarios::tutores_de( $p->num_socio_asde ) : array_filter( array( Usuarios::por_socio( $p->num_socio_asde ) ) );
		foreach ( $cuentas as $u ) {
			$email = Contacto::email( $u, $repo );
			if ( $email ) {
				$uno( $u, $email, self::FAMILIA, Contacto::saludo( $u, $repo ), Idioma::de_usuario( $u->ID ) );
			}
		}
		if ( count( $hecho ) === $antes ) {
			// Sin cuentas en la web: los emails de la ficha del CRM.
			$fichas = $menor
				? array_map( static fn( array $t ) => array( $t['email'], $t['nombre'] ), $p->tutores )
				: array( array( $p->email, $p->nombre ) );
			foreach ( $fichas as list( $email, $nombre ) ) {
				if ( is_email( (string) $email ) ) {
					$uno( null, (string) $email, self::FAMILIA, (string) $nombre, Idioma::por_defecto() );
				}
			}
		}
		$res['sin_email_familia'] = count( $hecho ) === $antes;
		return $res;
	}

	/** Sin el nombre de la persona: el asunto se guarda en el registro de emails. */
	public static function asunto( string $para ): string {
		return self::TESORERIA === $para
			/* translators: %s: nombre del grupo */
			? sprintf( asde_t( '[%s] Baja de un socio/a en el CRM' ), Grupo::nombre() )
			/* translators: %s: nombre del grupo */
			: sprintf( asde_t( '[%s] Confirmación de baja en el grupo' ), Grupo::nombre() );
	}

	/** HTML del email (el envío y la vista previa del registro usan la misma plantilla). */
	public static function html( array $datos, string $saludo, string $persona ): string {
		$motivo = Altas::MOTIVOS_BAJA[ $datos['motivo'] ?? '' ] ?? '';
		return TemplateLoader::render(
			'email/baja',
			array(
				'para'     => self::TESORERIA === ( $datos['para'] ?? '' ) ? self::TESORERIA : self::FAMILIA,
				'nombre'   => $saludo,
				'persona'  => $persona,
				'socio'    => (string) ( $datos['socio'] ?? '' ),
				'fin'      => (string) ( $datos['fin'] ?? '' ),
				'motivo'   => '' !== $motivo ? asde_t( $motivo ) : '',
				'familia'  => (string) ( $datos['familia'] ?? '' ),
				'grupo'    => (string) ( $datos['grupo'] ?? '' ),
				'contacto' => (string) ( $datos['contacto'] ?? '' ),
				'url'      => Pages::url( 'tesoreria' ),
			)
		);
	}

	/** Vista previa desde el registro de emails: el nombre se vuelve a leer del CRM. */
	public static function vista_previa( array $datos, string $saludo ): string {
		$persona = '';
		$repo    = PersonaRepository::make();
		try {
			$p       = $repo ? $repo->obtener( (string) ( $datos['socio'] ?? '' ) ) : null;
			$persona = $p ? $p->nombre_completo() : '';
		} catch ( CrmException $e ) {
			$persona = '';
		}
		return self::html( $datos, $saludo, '' !== $persona ? $persona : sprintf( asde_t( 'Nº %s' ), (string) ( $datos['socio'] ?? '' ) ) );
	}

	private static function mandar( ?\WP_User $user, string $email, string $para, string $saludo, Persona $p, array $datos ): bool {
		$asunto  = self::asunto( $para );
		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		if ( self::FAMILIA === $para && '' !== $datos['contacto'] ) {
			$headers[] = 'Reply-To: ' . $datos['contacto'];
		}
		$error = RegistroEmails::enviar( $email, $asunto, self::html( $datos, $saludo, $p->nombre_completo() ), $headers );
		RegistroEmails::registrar(
			$user,
			RegistroEmails::BAJA,
			Contacto::enmascarar( $email ),
			$asunto,
			'' === $error ? RegistroEmails::ENVIADO : RegistroEmails::ERROR,
			$error,
			$datos
		);
		return '' === $error;
	}
}
