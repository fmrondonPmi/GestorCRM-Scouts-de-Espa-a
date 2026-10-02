<?php
namespace AsdeBalears\AreaPrivada\Sepa;

use AsdeBalears\AreaPrivada\Crm\CrmException;
use AsdeBalears\AreaPrivada\Crm\PersonaRepository;
use AsdeBalears\AreaPrivada\Domain\Grupo;
use AsdeBalears\AreaPrivada\Frontend\Pages;
use AsdeBalears\AreaPrivada\Frontend\TemplateLoader;
use AsdeBalears\AreaPrivada\Tesoreria\Calculadora;
use AsdeBalears\AreaPrivada\Tesoreria\FamiliasTable;
use AsdeBalears\AreaPrivada\Usuarios\Contacto;
use AsdeBalears\AreaPrivada\Usuarios\EmailRechazo;
use AsdeBalears\AreaPrivada\Usuarios\RegistroEmails;
use AsdeBalears\AreaPrivada\Usuarios\Usuarios;

defined( 'ABSPATH' ) || exit;

/**
 * Emails de la domiciliación SEPA:
 * - confirmación a quien firma una autorización;
 * - aviso previo de cada cargo a las cuentas de la familia (la norma SEPA pide
 *   avisar al deudor de la fecha y el importe con antelación).
 *
 * Se anotan en el registro de emails (tipo «sepa») con el destino enmascarado y
 * solo datos no personales: la referencia del mandato, el IBAN enmascarado, el
 * importe y la fecha.
 */
final class Avisos {

	/** Texto de un email a partir de sus datos (el envío y la vista previa del registro usan lo mismo). */
	public static function html( array $datos, string $nombre ): string {
		return TemplateLoader::render(
			'email/sepa',
			array(
				'tipo'     => (string) ( $datos['tipo'] ?? 'autorizacion' ),
				'nombre'   => $nombre,
				'grupo'    => (string) ( $datos['grupo'] ?? '' ),
				'umr'      => (string) ( $datos['umr'] ?? '' ),
				'iban'     => (string) ( $datos['iban'] ?? '' ),
				'importe'  => (string) ( $datos['importe'] ?? '' ),
				'fecha'    => (string) ( $datos['fecha'] ?? '' ),
				'concepto' => (string) ( $datos['concepto'] ?? '' ),
				'url'      => (string) ( $datos['url'] ?? '' ),
				'contacto' => (string) ( $datos['contacto'] ?? '' ),
			)
		);
	}

	/** Confirmación de la autorización a quien la ha firmado. @return bool Si salió. */
	public static function autorizacion( array $mandato, \WP_User $firmante ): bool {
		return self::enviar(
			$firmante,
			'Hemos guardado tu autorización de domiciliación SEPA',
			array(
				'tipo'     => 'autorizacion',
				'grupo'    => Grupo::nombre(),
				'umr'      => $mandato['referencia'],
				'iban'     => $mandato['iban_mask'],
				'url'      => Pages::url( 'pagos' ),
				'contacto' => EmailRechazo::contacto(),
			),
			null
		);
	}

	/**
	 * Avisa del cargo de cada adeudo de la remesa a las cuentas de su familia.
	 *
	 * @return array{enviados:int, sin_email:int, familias:int}
	 */
	public static function preaviso( array $remesa ): array {
		$res  = array(
			'enviados'  => 0,
			'sin_email' => 0,
			'familias'  => 0,
		);
		$repo = PersonaRepository::make();
		$hechos = array();
		foreach ( Remesas::lineas( $remesa ) as $l ) {
			if ( Remesas::PENDIENTE !== $l['estado'] ) {
				continue;
			}
			$familia = FamiliasTable::obtener( (int) $l['familia'] );
			if ( ! $familia ) {
				continue;
			}
			++$res['familias'];
			$datos = array(
				'tipo'     => 'preaviso',
				'grupo'    => Grupo::nombre(),
				'umr'      => $l['umr'],
				'iban'     => $l['iban_mask'] ?? '',
				'importe'  => Calculadora::euros( $l['centimos'] / 100 ),
				'fecha'    => wp_date( 'd/m/Y', (int) strtotime( $remesa['fecha_cobro'] ) ),
				'concepto' => $remesa['concepto'],
				'url'      => Pages::url( 'pagos' ),
				'contacto' => EmailRechazo::contacto(),
			);
			$enviado = false;
			foreach ( self::destinatarios( $familia, $repo, (int) ( Mandatos::obtener( (int) $l['mandato'] )['firmado_por'] ?? 0 ) ) as $user ) {
				$email = Contacto::email( $user, $repo );
				if ( ! $email || isset( $hechos[ $l['familia'] . '|' . strtolower( $email ) ] ) ) {
					continue;
				}
				$hechos[ $l['familia'] . '|' . strtolower( $email ) ] = true;
				if ( self::enviar( $user, sprintf( '[%s] Aviso de cargo el %s', Grupo::nombre(), $datos['fecha'] ), $datos, $email ) ) {
					++$res['enviados'];
					$enviado = true;
				}
			}
			if ( ! $enviado ) {
				++$res['sin_email'];
			}
		}
		Remesas::marcar_avisada( (int) $remesa['id'] );
		return $res;
	}

	/**
	 * Cuentas a las que avisar: las familiares de esa familia, los asociados mayores
	 * de edad que son miembros de ella y quien firmó la autorización.
	 *
	 * @return \WP_User[]
	 */
	private static function destinatarios( array $familia, ?PersonaRepository $repo, int $firmante ): array {
		$cuentas = Usuarios::cuentas_familiares( $familia['miembros'] );
		if ( $repo ) {
			try {
				$personas = $repo->obtener_varias( $familia['miembros'] );
				foreach ( Usuarios::por_socios( $familia['miembros'] ) as $num => $u ) {
					if ( isset( $personas[ $num ] ) && null !== $personas[ $num ]->edad() && $personas[ $num ]->edad() >= 18 ) {
						$cuentas[] = $u;
					}
				}
			} catch ( CrmException $e ) {
				// Sin CRM solo se avisa a las cuentas familiares.
			}
		}
		if ( $firmante ) {
			$u = get_userdata( $firmante );
			if ( $u ) {
				$cuentas[] = $u;
			}
		}
		$unicas = array();
		foreach ( $cuentas as $u ) {
			$unicas[ $u->ID ] = $u;
		}
		return array_values( $unicas );
	}

	/** @return bool Si el servidor de correo lo aceptó. */
	private static function enviar( \WP_User $user, string $asunto, array $datos, ?string $email ): bool {
		$repo  = PersonaRepository::make();
		$email = $email ?: Contacto::email( $user, $repo );
		if ( ! $email ) {
			RegistroEmails::registrar( $user, RegistroEmails::SEPA, '', $asunto, RegistroEmails::SIN_EMAIL, 'La cuenta no tiene un email al que escribir.', $datos );
			return false;
		}
		$html    = self::html( $datos, Contacto::saludo( $user, $repo ) );
		$error   = '';
		$captura = static function ( $e ) use ( &$error ) {
			$error = is_wp_error( $e ) ? $e->get_error_message() : 'Error desconocido';
		};
		add_action( 'wp_mail_failed', $captura );
		$ok = wp_mail( $email, $asunto, $html, array( 'Content-Type: text/html; charset=UTF-8' ) );
		remove_action( 'wp_mail_failed', $captura );
		RegistroEmails::registrar( $user, RegistroEmails::SEPA, Contacto::enmascarar( $email ), $asunto, $ok ? RegistroEmails::ENVIADO : RegistroEmails::ERROR, $ok ? '' : ( $error ?: 'El servidor de correo no ha aceptado el email.' ), $datos );
		return $ok;
	}
}
