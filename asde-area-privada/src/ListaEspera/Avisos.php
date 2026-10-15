<?php
namespace AsdeBalears\AreaPrivada\ListaEspera;

use AsdeBalears\AreaPrivada\Domain\Grupo;
use AsdeBalears\AreaPrivada\Domain\Grupos;
use AsdeBalears\AreaPrivada\Frontend\TemplateLoader;
use AsdeBalears\AreaPrivada\Idiomas\Idioma;
use AsdeBalears\AreaPrivada\Usuarios\Contacto;
use AsdeBalears\AreaPrivada\Usuarios\EmailCuenta;
use AsdeBalears\AreaPrivada\Usuarios\RegistroEmails;

defined( 'ABSPATH' ) || exit;

/**
 * Emails de la lista de espera (v0.32, doc 39), todos con la plantilla email/lista-espera,
 * en el idioma en que se apuntó la persona y con el email de contacto del grupo como
 * «Responder a». Quedan en el registro de emails con el destino enmascarado.
 */
final class Avisos {

	/** @return array{ok:bool, destino:string, error:string} */
	public static function confirmar( array $fila ): array {
		return self::enviar(
			$fila,
			'confirmar',
			static fn( string $grupo ): array => array(
				/* translators: %s: nombre del grupo */
				sprintf( asde_t( '[%s] Confirma la inscripción en la lista de espera' ), $grupo ),
				array(
					/* translators: 1: nombre de la persona inscrita, 2: nombre del grupo */
					sprintf( asde_h( 'Hemos recibido la inscripción de <strong>%1$s</strong> en la lista de espera del <strong>%2$s</strong>.' ), esc_html( self::nombre( $fila ) ), esc_html( $grupo ) ),
					esc_html( asde_t( 'Para que entre en la lista, confirma que este email es tuyo:' ) ),
				),
				Enlaces::url_confirmar( $fila ),
				asde_t( 'Confirmar la inscripción' ),
				/* translators: %d: días */
				esc_html( sprintf( asde_t( 'El enlace vale %d días. Si no has sido tú, ignora este correo: la inscripción se borrará sola.' ), Inscripciones::DIAS_CONFIRMAR ) ),
			)
		);
	}

	/** @return array{ok:bool, destino:string, error:string} */
	public static function confirmada( array $fila ): array {
		return self::enviar(
			$fila,
			'confirmada',
			static fn( string $grupo ): array => array(
				/* translators: %s: nombre del grupo */
				sprintf( asde_t( '[%s] Inscripción confirmada en la lista de espera' ), $grupo ),
				array(
					/* translators: 1: nombre de la persona inscrita, 2: nombre del grupo */
					sprintf( asde_h( 'La inscripción de <strong>%1$s</strong> en la lista de espera del <strong>%2$s</strong> está confirmada. Nos pondremos en contacto contigo cuando haya plaza.' ), esc_html( self::nombre( $fila ) ), esc_html( $grupo ) ),
					/* translators: %s: fecha */
					esc_html( sprintf( asde_t( 'Guardamos tus datos, cifrados, hasta el %s. Antes te avisaremos para que puedas mantenerlos.' ), self::fecha( (string) $fila['caduca'] ) ) ),
				),
				Enlaces::url_acceso( $fila ),
				asde_t( 'Ver mis datos' ),
				esc_html( asde_t( 'Guarda este correo. Para ver tus datos te pediremos este email; desde ahí puedes mantenerlos o borrarlos cuando quieras.' ) ),
			)
		);
	}

	/**
	 * Reenvía los enlaces de todas las inscripciones de un email («¿Ya estás en la lista?»):
	 * un email por grupo.
	 *
	 * @param array[] $filas Inscripciones::por_email()
	 * @return int Emails enviados.
	 */
	public static function enlaces( array $filas ): int {
		$por_grupo = array();
		foreach ( $filas as $f ) {
			$por_grupo[ (string) $f['grupo'] ][] = $f;
		}
		$n = 0;
		foreach ( $por_grupo as $lista ) {
			$r  = self::enviar(
				$lista[0],
				'enlaces',
				static function ( string $grupo ) use ( $lista ): array {
					$items = '';
					foreach ( $lista as $f ) {
						$sin    = Inscripciones::SIN_CONFIRMAR === $f['estado'];
						$items .= sprintf(
							'<li style="margin:0 0 8px;"><a href="%1$s">%2$s</a>%3$s</li>',
							esc_url( $sin ? Enlaces::url_confirmar( $f ) : Enlaces::url_acceso( $f ) ),
							esc_html( trim( $f['datos']['nombre'] . ' ' . $f['datos']['apellidos'] ) ),
							$sin ? ' · ' . esc_html( asde_t( 'sin confirmar: el enlace la confirma' ) ) : ''
						);
					}
					return array(
						/* translators: %s: nombre del grupo */
						sprintf( asde_t( '[%s] Tus enlaces de la lista de espera' ), $grupo ),
						array(
							/* translators: %s: nombre del grupo */
							sprintf( asde_h( 'Estas son las inscripciones con este email en la lista de espera del <strong>%s</strong>:' ), esc_html( $grupo ) ),
							'<ul style="margin:0;padding-left:20px;">' . $items . '</ul>',
						),
						'',
						'',
						esc_html( asde_t( 'Si no has pedido tú estos enlaces, puedes ignorar este correo.' ) ),
					);
				}
			);
			$n += $r['ok'] ? 1 : 0;
		}
		return $n;
	}

	/** Aviso de caducidad (tarea diaria). */
	public static function caducidad( array $fila ): bool {
		return self::enviar(
			$fila,
			'caducidad',
			static fn( string $grupo ): array => array(
				/* translators: %s: nombre del grupo */
				sprintf( asde_t( '[%s] Tus datos de la lista de espera caducan pronto' ), $grupo ),
				array(
					/* translators: 1: nombre de la persona inscrita, 2: nombre del grupo, 3: fecha */
					sprintf( asde_h( 'Los datos de <strong>%1$s</strong> en la lista de espera del <strong>%2$s</strong> se borrarán el <strong>%3$s</strong>.' ), esc_html( self::nombre( $fila ) ), esc_html( $grupo ), esc_html( self::fecha( (string) $fila['caduca'] ) ) ),
					/* translators: %d: meses */
					esc_html( sprintf( asde_t( 'Si quieres seguir en la lista, entra y pulsa «Mantener mis datos»: los guardaremos %d meses más.' ), Inscripciones::MESES ) ),
				),
				Enlaces::url_acceso( $fila ),
				asde_t( 'Mantener mis datos' ),
				esc_html( asde_t( 'Si no haces nada, se borrarán ese día y saldrás de la lista.' ) ),
			)
		)['ok'];
	}

	/** Mensaje del equipo del grupo a la familia (desde la ficha de la lista). @return array{ok:bool, destino:string, error:string} */
	public static function mensaje( array $fila, string $asunto, string $texto ): array {
		return self::enviar(
			$fila,
			'mensaje',
			static fn( string $grupo ): array => array(
				sprintf( '[%s] %s', $grupo, $asunto ),
				array( nl2br( esc_html( $texto ) ) ),
				'',
				'',
				/* translators: 1: nombre del grupo, 2: enlace */
				sprintf( asde_h( 'Puedes responder a este correo. Estás en la lista de espera del %1$s: <a href="%2$s">ver mis datos</a>.' ), esc_html( $grupo ), esc_url( Enlaces::url_acceso( $fila ) ) ),
			)
		);
	}

	/**
	 * @param callable $contenido fn( string $grupo ): array{0:string asunto, 1:string[] párrafos en HTML ya escapado, 2:string url del botón, 3:string texto del botón, 4:string nota en HTML}
	 * @return array{ok:bool, destino:string, error:string}
	 */
	private static function enviar( array $fila, string $aviso, callable $contenido ): array {
		$email = EmailCuenta::normalizar( (string) $fila['datos']['email'] );
		if ( ! is_email( $email ) ) {
			return array(
				'ok'      => false,
				'destino' => '',
				'error'   => asde_t( 'La inscripción no tiene un email válido.' ),
			);
		}
		$grupo = Grupos::por_numero( (string) $fila['grupo'] );
		return Grupo::con(
			$grupo,
			static function () use ( $fila, $aviso, $contenido, $email, $grupo ): array {
				$nombre   = $grupo ? (string) $grupo['nombre'] : Grupo::nombre();
				$contacto = Grupos::email_contacto( $grupo );
				$idioma   = Idioma::es_valido( $fila['datos']['idioma'] ?: null ) ? (string) $fila['datos']['idioma'] : Idioma::por_defecto();
				list( $asunto, $html ) = Idioma::en(
					$idioma,
					static function () use ( $contenido, $nombre, $contacto, $fila ): array {
						$c = $contenido( $nombre );
						return array(
							$c[0],
							TemplateLoader::render(
								'email/lista-espera',
								array(
									'grupo'       => $nombre,
									'saludo'      => self::saludo( $fila ),
									'parrafos'    => $c[1],
									'boton_url'   => $c[2],
									'boton_texto' => $c[3],
									'nota'        => $c[4],
									'contacto'    => $contacto,
								)
							),
						);
					}
				);
				$headers = array( 'Content-Type: text/html; charset=UTF-8' );
				if ( '' !== $contacto ) {
					$headers[] = 'Reply-To: ' . $contacto;
				}
				$error   = RegistroEmails::enviar( $email, $asunto, $html, $headers );
				$ok      = '' === $error;
				$destino = Contacto::enmascarar( $email );
				RegistroEmails::registrar(
					null,
					RegistroEmails::LISTA_ESPERA,
					$destino,
					$asunto,
					$ok ? RegistroEmails::ENVIADO : RegistroEmails::ERROR,
					$error,
					array(
						'inscripcion' => (int) $fila['id'],
						'aviso'       => $aviso,
						'grupo'       => $nombre,
					)
				);
				return array(
					'ok'      => $ok,
					'destino' => $destino,
					'error'   => $error,
				);
			}
		);
	}

	/** Nombre de la persona inscrita. */
	private static function nombre( array $fila ): string {
		return trim( $fila['datos']['nombre'] . ' ' . $fila['datos']['apellidos'] );
	}

	/** A quién se saluda: la persona de contacto o, si no hay, la inscrita. */
	private static function saludo( array $fila ): string {
		$n     = '' !== $fila['datos']['contacto_nombre'] ? $fila['datos']['contacto_nombre'] : $fila['datos']['nombre'];
		$parts = preg_split( '/\s+/', trim( $n ) ) ?: array();
		return mb_substr( (string) ( $parts[0] ?? '' ), 0, 40 );
	}

	private static function fecha( string $utc ): string {
		$t = '' !== $utc ? strtotime( $utc . ' UTC' ) : false;
		return $t ? wp_date( 'd/m/Y', $t ) : '';
	}
}
