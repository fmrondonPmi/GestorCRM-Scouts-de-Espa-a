<?php
namespace AsdeBalears\AreaPrivada\Reintegros;

use AsdeBalears\AreaPrivada\Crm\Crypto;
use AsdeBalears\AreaPrivada\Crm\PersonaRepository;
use AsdeBalears\AreaPrivada\Domain\Grupo;
use AsdeBalears\AreaPrivada\Eventos\EventosTable;
use AsdeBalears\AreaPrivada\Frontend\Pages;
use AsdeBalears\AreaPrivada\Frontend\TemplateLoader;
use AsdeBalears\AreaPrivada\Idiomas\Idioma;
use AsdeBalears\AreaPrivada\Inventario\AvisoTesoreria;
use AsdeBalears\AreaPrivada\Sepa\Iban;
use AsdeBalears\AreaPrivada\Tesoreria\Calculadora;
use AsdeBalears\AreaPrivada\Usuarios\Contacto;
use AsdeBalears\AreaPrivada\Usuarios\EmailRechazo;
use AsdeBalears\AreaPrivada\Usuarios\RegistroEmails;

defined( 'ABSPATH' ) || exit;

/**
 * Emails de los reintegros (v0.47, doc 53).
 *
 * - **A Tesorería** cuando alguien pide un reintegro **por transferencia**, con toda la información (quién, concepto,
 *   importe, fecha, evento e IBAN) y el enlace para revisarlo. Va a cada cuenta activa de Tesorería (menos quien lo
 *   pide) o, si no hay ninguna con email, al email de contacto del grupo.
 * - **A quien lo pidió** cuando se aprueba, se rechaza (con el motivo) o se paga.
 * - Queda en el registro de emails (tipo «reintegro») con el destino enmascarado; el IBAN nunca se guarda ahí: la
 *   vista previa lo vuelve a leer mientras el reintegro lo conserve (se borra al terminar).
 */
final class Avisos {

	public const TESORERIA = 'tesoreria';
	public const APROBADO  = 'aprobado';
	public const RECHAZADO = 'rechazado';
	public const PAGADO    = 'pagado';

	public const TIPOS = array( self::TESORERIA, self::APROBADO, self::RECHAZADO, self::PAGADO );

	/** Aviso a Tesorería de un reintegro por transferencia. @return int Emails enviados. */
	public static function a_tesoreria( array $r ): int {
		if ( ReintegrosTable::TRANSFERENCIA !== $r['forma'] ) {
			return 0;
		}
		$datos = array(
			'tipo'      => self::TESORERIA,
			'grupo'     => Grupo::nombre(),
			'reintegro' => $r['id'],
		);
		$repo  = PersonaRepository::make();
		$hecho = array();
		$n     = 0;
		foreach ( AvisoTesoreria::destinatarios() as $u ) {
			$email = Contacto::email( $u, $repo );
			if ( ! $email || isset( $hecho[ strtolower( $email ) ] ) ) {
				continue;
			}
			$hecho[ strtolower( $email ) ] = true;
			$n                            += (int) Idioma::en( Idioma::de_usuario( $u->ID ), static fn() => self::mandar( $u, $email, $datos, Contacto::saludo( $u, $repo ) ) );
		}
		if ( ! $hecho ) {
			$contacto = EmailRechazo::contacto();
			if ( '' !== $contacto ) {
				$n += (int) Idioma::en( Idioma::por_defecto(), static fn() => self::mandar( null, $contacto, $datos, '' ) );
			}
		}
		return $n;
	}

	/** Aviso a quien pidió el reintegro. @return bool Si el servidor de correo lo aceptó. */
	public static function a_solicitante( array $r, string $tipo ): bool {
		$user = $r ? get_userdata( (int) $r['solicitante'] ) : false;
		if ( ! $user || ! in_array( $tipo, array( self::APROBADO, self::RECHAZADO, self::PAGADO ), true ) ) {
			return false;
		}
		$repo  = PersonaRepository::make();
		$email = Contacto::email( $user, $repo );
		$datos = array(
			'tipo'      => $tipo,
			'grupo'     => Grupo::nombre(),
			'reintegro' => $r['id'],
		);
		return (bool) Idioma::en(
			Idioma::de_usuario( $user->ID ),
			static function () use ( $user, $email, $datos, $repo ): bool {
				if ( ! $email ) {
					RegistroEmails::registrar( $user, RegistroEmails::REINTEGRO, '', self::asunto( $datos ), RegistroEmails::SIN_EMAIL, asde_t( 'La cuenta no tiene un email al que escribir.' ), $datos );
					return false;
				}
				return self::mandar( $user, $email, $datos, Contacto::saludo( $user, $repo ) );
			}
		);
	}

	private static function mandar( ?\WP_User $user, string $email, array $datos, string $saludo ): bool {
		$asunto = self::asunto( $datos );
		$error  = RegistroEmails::enviar( $email, $asunto, self::html( $datos, $saludo ) );
		RegistroEmails::registrar( $user, RegistroEmails::REINTEGRO, Contacto::enmascarar( $email ), $asunto, '' === $error ? RegistroEmails::ENVIADO : RegistroEmails::ERROR, $error, $datos );
		return '' === $error;
	}

	/** Asunto en el idioma de quien lo recibe (se llama dentro de Idioma::en). */
	private static function asunto( array $datos ): string {
		$r   = ReintegrosTable::obtener( (int) ( $datos['reintegro'] ?? 0 ) );
		$num = $r ? ReintegrosTable::numero( $r ) : '';
		switch ( $datos['tipo'] ?? '' ) {
			case self::APROBADO:
				$plantilla = asde_t( '[%1$s] Reintegro aprobado: %2$s' );
				break;
			case self::RECHAZADO:
				$plantilla = asde_t( '[%1$s] Reintegro rechazado: %2$s' );
				break;
			case self::PAGADO:
				$plantilla = asde_t( '[%1$s] Reintegro pagado: %2$s' );
				break;
			default:
				$plantilla = asde_t( '[%1$s] Reintegro por transferencia pendiente de aprobar: %2$s' );
		}
		return sprintf( $plantilla, (string) ( $datos['grupo'] ?? Grupo::nombre() ), $num );
	}

	/** Texto del email (el envío y la vista previa del registro de emails usan lo mismo). */
	public static function html( array $datos, string $saludo ): string {
		$tipo = in_array( $datos['tipo'] ?? '', self::TIPOS, true ) ? (string) $datos['tipo'] : self::TESORERIA;
		$r    = ReintegrosTable::obtener( (int) ( $datos['reintegro'] ?? 0 ) );
		if ( ! $r ) {
			return '';
		}
		$iban = '';
		if ( self::TESORERIA === $tipo && '' !== $r['iban_enc'] ) {
			$v    = Crypto::decrypt( $r['iban_enc'] );
			$iban = is_string( $v ) ? Iban::formatear( $v ) : '';
		}
		$evento = $r['evento_id'] ? EventosTable::obtener( $r['evento_id'] ) : null;
		return TemplateLoader::render(
			'email/reintegro',
			array(
				'tipo'      => $tipo,
				'saludo'    => $saludo,
				'grupo'     => (string) ( $datos['grupo'] ?? Grupo::nombre() ),
				'numero'    => ReintegrosTable::numero( $r ),
				'quien'     => Servicio::nombre( $r['solicitante'] ),
				'concepto'  => $r['concepto'],
				'fecha'     => wp_date( 'd/m/Y', strtotime( $r['fecha'] . ' 12:00' ) ),
				'importe'   => Calculadora::euros( $r['importe'] ),
				'forma'     => asde_t( ReintegrosTable::FORMAS[ $r['forma'] ] ?? '' ),
				'iban'      => $iban,
				'evento'    => $evento ? (string) $evento['nombre'] : '',
				'motivo'    => $r['motivo'],
				'pago'      => $r['pago_fecha'] ? wp_date( 'd/m/Y', strtotime( $r['pago_fecha'] . ' 12:00' ) ) : '',
				'url'       => Pages::url( 'reintegros' ),
				'contacto'  => EmailRechazo::contacto(),
			)
		);
	}
}
