<?php
namespace AsdeBalears\AreaPrivada\Tienda;

use AsdeBalears\AreaPrivada\Crm\PersonaRepository;
use AsdeBalears\AreaPrivada\Domain\Grupo;
use AsdeBalears\AreaPrivada\Frontend\Pages;
use AsdeBalears\AreaPrivada\Frontend\TemplateLoader;
use AsdeBalears\AreaPrivada\Idiomas\Idioma;
use AsdeBalears\AreaPrivada\Usuarios\Contacto;
use AsdeBalears\AreaPrivada\Usuarios\EmailRechazo;
use AsdeBalears\AreaPrivada\Usuarios\RegistroEmails;

defined( 'ABSPATH' ) || exit;

/**
 * Emails de la tienda (v0.39, doc 48, P-182): pedido recibido (con cómo pagarlo), pago validado y pedido entregado.
 * Salen a la cuenta que hizo el pedido, en su idioma, y quedan en el registro de emails (tipo «tienda») solo con el
 * id del pedido y el tipo: el texto se vuelve a componer al verlo.
 */
final class Avisos {

	public const PEDIDO    = 'pedido';
	public const PAGADO    = 'pagado';
	public const ENTREGADO = 'entregado';
	// v0.40 (fase 5): el pedido sigue sin pagar / se ha cancelado por no pagarlo.
	public const RECORDATORIO = 'recordatorio';
	public const CANCELADO    = 'cancelado';

	public const TIPOS = array( self::PEDIDO, self::PAGADO, self::ENTREGADO, self::RECORDATORIO, self::CANCELADO );

	/** Manda el aviso a quien hizo el pedido. @return bool Si el servidor de correo lo aceptó. */
	public static function enviar( array $pedido, string $tipo ): bool {
		if ( '' === $pedido['socio'] ) {
			return self::enviar_invitado( $pedido, $tipo ); // Compra sin sesión: al email que dejó.
		}
		$user = (int) $pedido['creado_por'] > 0 ? get_userdata( (int) $pedido['creado_por'] ) : false;
		if ( ! $user || ! in_array( $tipo, self::TIPOS, true ) ) {
			return false;
		}
		$datos = array(
			'tipo'   => $tipo,
			'grupo'  => Grupo::nombre(),
			'pedido' => $pedido['id'],
		);
		return (bool) Idioma::en( Idioma::de_usuario( $user->ID ), static fn() => self::mandar( $user, $datos ) );
	}

	/** Aviso a quien compró sin sesión: a su email, en el idioma que tenía, con su enlace personal al pedido. */
	private static function enviar_invitado( array $pedido, string $tipo ): bool {
		$i = PedidosTable::invitado( $pedido );
		if ( ! $i || ! in_array( $tipo, self::TIPOS, true ) || ! is_email( (string) $i['email'] ) ) {
			return false;
		}
		$datos = array(
			'tipo'   => $tipo,
			'grupo'  => Grupo::nombre(),
			'pedido' => $pedido['id'],
		);
		return (bool) Idioma::en(
			Idioma::es_valido( $i['idioma'] ?? null ) ? (string) $i['idioma'] : Idioma::por_defecto(),
			static function () use ( $pedido, $datos, $i ): bool {
				$asunto = self::asunto( $datos['tipo'], $datos );
				$error  = RegistroEmails::enviar( (string) $i['email'], $asunto, self::html( $datos, (string) $i['nombre'] ) );
				RegistroEmails::registrar( null, RegistroEmails::TIENDA, Contacto::enmascarar( (string) $i['email'] ), $asunto, '' === $error ? RegistroEmails::ENVIADO : RegistroEmails::ERROR, $error, $datos );
				return '' === $error;
			}
		);
	}

	private static function mandar( \WP_User $user, array $datos ): bool {
		$asunto = self::asunto( $datos['tipo'], $datos );
		$repo   = PersonaRepository::make();
		$email  = Contacto::email( $user, $repo );
		if ( ! $email ) {
			RegistroEmails::registrar( $user, RegistroEmails::TIENDA, '', $asunto, RegistroEmails::SIN_EMAIL, asde_t( 'La cuenta no tiene un email al que escribir.' ), $datos );
			return false;
		}
		$error = RegistroEmails::enviar( $email, $asunto, self::html( $datos, Contacto::saludo( $user, $repo ) ) );
		RegistroEmails::registrar( $user, RegistroEmails::TIENDA, Contacto::enmascarar( $email ), $asunto, '' === $error ? RegistroEmails::ENVIADO : RegistroEmails::ERROR, $error, $datos );
		return '' === $error;
	}

	/** Asunto en el idioma de quien lo recibe (se llama dentro de Idioma::en). */
	private static function asunto( string $tipo, array $datos ): string {
		$p   = PedidosTable::obtener( (int) ( $datos['pedido'] ?? 0 ) );
		$num = $p ? PedidosTable::numero( $p ) : '';
		switch ( $tipo ) {
			case self::PAGADO:
				$plantilla = asde_t( '[%1$s] Pago recibido: pedido %2$s' );
				break;
			case self::ENTREGADO:
				$plantilla = asde_t( '[%1$s] Pedido entregado: %2$s' );
				break;
			case self::RECORDATORIO:
				$plantilla = asde_t( '[%1$s] Recordatorio: el pedido %2$s está sin pagar' );
				break;
			case self::CANCELADO:
				$plantilla = asde_t( '[%1$s] Pedido cancelado: %2$s' );
				break;
			default:
				$plantilla = asde_t( '[%1$s] Pedido recibido: %2$s' );
		}
		return sprintf( $plantilla, (string) ( $datos['grupo'] ?? Grupo::nombre() ), $num );
	}

	/** Texto del email (el envío y la vista previa del registro de emails usan lo mismo). */
	public static function html( array $datos, string $saludo ): string {
		$tipo = in_array( $datos['tipo'] ?? '', self::TIPOS, true ) ? (string) $datos['tipo'] : self::PEDIDO;
		$p    = PedidosTable::obtener( (int) ( $datos['pedido'] ?? 0 ) );
		if ( ! $p ) {
			return '';
		}
		$cfg    = Config::de();
		$limite = '';
		if ( self::RECORDATORIO === $tipo && $cfg['dias_cancelar_socios'] > 0 && '' !== $p['socio'] ) {
			$limite = wp_date( 'd/m/Y', strtotime( $p['creado'] . ' UTC' ) + $cfg['dias_cancelar_socios'] * DAY_IN_SECONDS );
		}
		$sin_pagar = null === $p['pago_en'] && in_array( $tipo, array( self::PEDIDO, self::RECORDATORIO ), true );
		return TemplateLoader::render(
			'email/tienda',
			array(
				'tipo'        => $tipo,
				'saludo'      => $saludo,
				'grupo'       => (string) ( $datos['grupo'] ?? Grupo::nombre() ),
				'numero'      => PedidosTable::numero( $p ),
				'descripcion' => Servicio::descripcion( $p['lineas'] ),
				'importe'     => Servicio::importe( $p ),
				'pagar'       => $sin_pagar ? Servicio::instrucciones_pago( $p, $cfg ) : array(),
				'texto_pago'  => $sin_pagar ? $cfg['texto_pago'] : '',
				'limite'      => $limite,
				'url'         => '' === $p['socio'] ? Enlace::url_pedido( $p ) : Pages::url( 'tienda_pedidos' ),
				'boton'       => '' === $p['socio'] ? asde_t( 'Ver mi pedido' ) : asde_t( 'Ver mis pedidos' ),
				'contacto'    => EmailRechazo::contacto(),
			)
		);
	}
}
