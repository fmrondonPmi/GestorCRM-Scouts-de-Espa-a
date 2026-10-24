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

	/** Sustituye el token de los enlaces personales en la vista previa del registro de emails. */
	private const ENLACE_NO_GUARDADO = 'ENLACE-PERSONAL-NO-GUARDADO';

	/** @return array{ok:bool, destino:string, error:string} */
	public static function confirmar( array $fila ): array {
		return self::enviar( $fila, 'confirmar', self::de_confirmar( $fila ) );
	}

	/** @return array{ok:bool, destino:string, error:string} */
	public static function confirmada( array $fila ): array {
		return self::enviar( $fila, 'confirmada', self::de_confirmada( $fila ) );
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
			$r  = self::enviar( $lista[0], 'enlaces', self::de_enlaces( $lista ) );
			$n += $r['ok'] ? 1 : 0;
		}
		return $n;
	}

	/** Aviso de caducidad (tarea diaria). */
	public static function caducidad( array $fila ): bool {
		return self::enviar( $fila, 'caducidad', self::de_caducidad( $fila ) )['ok'];
	}

	/** Mensaje del equipo del grupo a la familia (desde la ficha de la lista). @return array{ok:bool, destino:string, error:string} */
	public static function mensaje( array $fila, string $asunto, string $texto ): array {
		return self::enviar( $fila, 'mensaje', self::de_mensaje( $fila, $asunto, $texto ) );
	}

	/**
	 * Vuelve a componer un email de la lista de espera para el registro de emails, con la misma
	 * plantilla y los datos que se conservan de la inscripción, y los enlaces personales sustituidos.
	 * Si la inscripción ya no tiene datos (se borró o pasó a «Asociado/a»), lo dice en lugar del texto.
	 *
	 * @param array<string, mixed> $datos Los del registro: inscripcion, aviso y grupo.
	 */
	public static function vista_previa( array $datos ): string {
		$fila  = ! empty( $datos['inscripcion'] ) ? Inscripciones::obtener( (int) $datos['inscripcion'] ) : null;
		$fila  = $fila && '' !== $fila['datos']['email'] ? $fila : null;
		$grupo = Grupos::por_numero( Grupo::numero() );
		$nombre = (string) ( $datos['grupo'] ?? '' );
		$nombre = '' !== $nombre ? $nombre : ( $grupo ? (string) $grupo['nombre'] : Grupo::nombre() );
		$idioma = $fila && Idioma::es_valido( $fila['datos']['idioma'] ?: null ) ? (string) $fila['datos']['idioma'] : Idioma::por_defecto();
		return Idioma::en(
			$idioma,
			static function () use ( $fila, $datos, $grupo, $nombre ): string {
				$contenido = null;
				if ( $fila ) {
					switch ( (string) ( $datos['aviso'] ?? '' ) ) {
						case 'confirmar':
							$contenido = self::de_confirmar( $fila, true );
							break;
						case 'confirmada':
							$contenido = self::de_confirmada( $fila, true );
							break;
						case 'caducidad':
							$contenido = self::de_caducidad( $fila, true );
							break;
						case 'enlaces':
							$lista     = array_values( array_filter( Inscripciones::por_email( $fila['datos']['email'] ), static fn( array $f ): bool => (string) $f['grupo'] === (string) $fila['grupo'] ) );
							$contenido = self::de_enlaces( $lista ?: array( $fila ), true );
							break;
						case 'mensaje':
							$contenido = self::de_mensaje( $fila, '', asde_t( '(El texto del mensaje no se guarda en el registro de emails.)' ), true );
							break;
					}
				}
				$contenido = $contenido ?? static fn( string $g ): array => array(
					'',
					array( esc_html( asde_t( 'Los datos de esta inscripción ya no se conservan (se borraron o pasó a asociado/a): no se puede reconstruir el texto del email.' ) ) ),
					'',
					'',
					'',
				);
				return self::html( $fila, $nombre, Grupos::email_contacto( $grupo ), $contenido( $nombre ) );
			}
		);
	}

	/** @return callable fn( string $grupo ): array — ver enviar(). */
	private static function de_confirmar( array $fila, bool $vista = false ): callable {
		return static fn( string $grupo ): array => array(
			/* translators: %s: nombre del grupo */
			sprintf( asde_t( '[%s] Confirma la inscripción en la lista de espera' ), $grupo ),
			array(
				/* translators: 1: nombre de la persona inscrita, 2: nombre del grupo */
				sprintf( asde_h( 'Hemos recibido la inscripción de <strong>%1$s</strong> en la lista de espera del <strong>%2$s</strong>.' ), esc_html( self::nombre( $fila ) ), esc_html( $grupo ) ),
				esc_html( asde_t( 'Para que entre en la lista, confirma que este email es tuyo:' ) ),
			),
			self::enlace( Enlaces::url_confirmar( $fila ), $vista ),
			asde_t( 'Confirmar la inscripción' ),
			/* translators: %d: días */
			esc_html( sprintf( asde_t( 'El enlace vale %d días. Si no has sido tú, ignora este correo: la inscripción se borrará sola.' ), Inscripciones::DIAS_CONFIRMAR ) ),
		);
	}

	/** @return callable fn( string $grupo ): array — ver enviar(). */
	private static function de_confirmada( array $fila, bool $vista = false ): callable {
		return static fn( string $grupo ): array => array(
			/* translators: %s: nombre del grupo */
			sprintf( asde_t( '[%s] Inscripción confirmada en la lista de espera' ), $grupo ),
			array(
				/* translators: 1: nombre de la persona inscrita, 2: nombre del grupo */
				sprintf( asde_h( 'La inscripción de <strong>%1$s</strong> en la lista de espera del <strong>%2$s</strong> está confirmada. Nos pondremos en contacto contigo cuando haya plaza.' ), esc_html( self::nombre( $fila ) ), esc_html( $grupo ) ),
				/* translators: %s: fecha */
				esc_html( sprintf( asde_t( 'Guardamos tus datos, cifrados, hasta el %s. Antes te avisaremos para que puedas mantenerlos.' ), self::fecha( (string) $fila['caduca'] ) ) ),
			),
			self::enlace( Enlaces::url_acceso( $fila ), $vista ),
			asde_t( 'Ver mis datos' ),
			esc_html( asde_t( 'Guarda este correo. Para ver tus datos te pediremos este email; desde ahí puedes mantenerlos o borrarlos cuando quieras.' ) ),
		);
	}

	/**
	 * @param array[] $lista Inscripciones de un mismo email en un grupo.
	 * @return callable fn( string $grupo ): array — ver enviar().
	 */
	private static function de_enlaces( array $lista, bool $vista = false ): callable {
		return static function ( string $grupo ) use ( $lista, $vista ): array {
			$items = '';
			foreach ( $lista as $f ) {
				$sin    = Inscripciones::SIN_CONFIRMAR === $f['estado'];
				$items .= sprintf(
					'<li style="margin:0 0 8px;"><a href="%1$s">%2$s</a>%3$s</li>',
					esc_url( self::enlace( $sin ? Enlaces::url_confirmar( $f ) : Enlaces::url_acceso( $f ), $vista ) ),
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
		};
	}

	/** @return callable fn( string $grupo ): array — ver enviar(). */
	private static function de_caducidad( array $fila, bool $vista = false ): callable {
		return static fn( string $grupo ): array => array(
			/* translators: %s: nombre del grupo */
			sprintf( asde_t( '[%s] Tus datos de la lista de espera caducan pronto' ), $grupo ),
			array(
				/* translators: 1: nombre de la persona inscrita, 2: nombre del grupo, 3: fecha */
				sprintf( asde_h( 'Los datos de <strong>%1$s</strong> en la lista de espera del <strong>%2$s</strong> se borrarán el <strong>%3$s</strong>.' ), esc_html( self::nombre( $fila ) ), esc_html( $grupo ), esc_html( self::fecha( (string) $fila['caduca'] ) ) ),
				/* translators: %d: meses */
				esc_html( sprintf( asde_t( 'Si quieres seguir en la lista, entra y pulsa «Mantener mis datos»: los guardaremos %d meses más.' ), Inscripciones::MESES ) ),
			),
			self::enlace( Enlaces::url_acceso( $fila ), $vista ),
			asde_t( 'Mantener mis datos' ),
			esc_html( asde_t( 'Si no haces nada, se borrarán ese día y saldrás de la lista.' ) ),
		);
	}

	/** @return callable fn( string $grupo ): array — ver enviar(). */
	private static function de_mensaje( array $fila, string $asunto, string $texto, bool $vista = false ): callable {
		return static fn( string $grupo ): array => array(
			sprintf( '[%s] %s', $grupo, $asunto ),
			array( nl2br( esc_html( $texto ) ) ),
			'',
			'',
			/* translators: 1: nombre del grupo, 2: enlace */
			sprintf( asde_h( 'Puedes responder a este correo. Estás en la lista de espera del %1$s: <a href="%2$s">ver mis datos</a>.' ), esc_html( $grupo ), esc_url( self::enlace( Enlaces::url_acceso( $fila ), $vista ) ) ),
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
						return array( $c[0], self::html( $fila, $nombre, $contacto, $c ) );
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

	/**
	 * @param array|null $fila La inscripción (null si ya no tiene datos: sin saludo).
	 * @param array      $c    Lo que devuelve el contenido de un aviso.
	 */
	private static function html( ?array $fila, string $nombre, string $contacto, array $c ): string {
		return TemplateLoader::render(
			'email/lista-espera',
			array(
				'grupo'       => $nombre,
				'saludo'      => $fila ? self::saludo( $fila ) : '',
				'parrafos'    => $c[1],
				'boton_url'   => $c[2],
				'boton_texto' => $c[3],
				'nota'        => $c[4],
				'contacto'    => $contacto,
			)
		);
	}

	/** En la vista previa, el enlace personal sin su token (no se guarda). */
	private static function enlace( string $url, bool $vista ): string {
		return $vista ? add_query_arg( 't', self::ENLACE_NO_GUARDADO, $url ) : $url;
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
