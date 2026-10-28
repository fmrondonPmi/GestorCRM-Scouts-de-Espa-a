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
							$contenido = self::de_mensaje( $fila, '', __( '(The text of the message is not stored in the email log.)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), true );
							break;
					}
				}
				$contenido = $contenido ?? static fn( string $g ): array => array(
					'',
					array( esc_html( __( 'The data of this sign-up is no longer kept (it was deleted or they became a member): the text of the email cannot be rebuilt.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) ),
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
			/* translators: %s: name of the group */
			sprintf( __( '[%s] Confirm your sign-up on the waiting list', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $grupo ),
			array(
				/* translators: 1: name of the person signed up, 2: name of the group */
				sprintf( __( 'We have received the sign-up of <strong>%1$s</strong> on the waiting list of <strong>%2$s</strong>.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), esc_html( self::nombre( $fila ) ), esc_html( $grupo ) ),
				esc_html( __( 'For it to go on the list, confirm that this email address is yours:', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ),
			),
			self::enlace( Enlaces::url_confirmar( $fila ), $vista ),
			__( 'Confirm the sign-up', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
			/* translators: %d: days */
			esc_html( sprintf( __( 'The link is valid for %d days. If it was not you, ignore this email: the sign-up will be deleted by itself.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), Inscripciones::DIAS_CONFIRMAR ) ),
		);
	}

	/** @return callable fn( string $grupo ): array — ver enviar(). */
	private static function de_confirmada( array $fila, bool $vista = false ): callable {
		return static fn( string $grupo ): array => array(
			/* translators: %s: name of the group */
			sprintf( __( '[%s] Sign-up on the waiting list confirmed', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $grupo ),
			array(
				/* translators: 1: name of the person signed up, 2: name of the group */
				sprintf( __( 'The sign-up of <strong>%1$s</strong> on the waiting list of <strong>%2$s</strong> is confirmed. We will contact you when a place is available.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), esc_html( self::nombre( $fila ) ), esc_html( $grupo ) ),
				/* translators: %s: date */
				esc_html( sprintf( __( 'We keep your data, encrypted, until %s. We will notify you beforehand so you can keep it.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), self::fecha( (string) $fila['caduca'] ) ) ),
			),
			self::enlace( Enlaces::url_acceso( $fila ), $vista ),
			__( 'View my details', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
			esc_html( __( 'Keep this email. To see your details we will ask for this email address; from there you can keep or delete them whenever you want.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ),
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
					$sin ? ' · ' . esc_html( __( 'unconfirmed: the link confirms it', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) : ''
				);
			}
			return array(
				/* translators: %s: name of the group */
				sprintf( __( '[%s] Your waiting list links', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $grupo ),
				array(
					/* translators: %s: name of the group */
					sprintf( __( 'These are the sign-ups with this email on the waiting list of <strong>%s</strong>:', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), esc_html( $grupo ) ),
					'<ul style="margin:0;padding-left:20px;">' . $items . '</ul>',
				),
				'',
				'',
				esc_html( __( 'If you did not request these links, you can ignore this email.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ),
			);
		};
	}

	/** @return callable fn( string $grupo ): array — ver enviar(). */
	private static function de_caducidad( array $fila, bool $vista = false ): callable {
		return static fn( string $grupo ): array => array(
			/* translators: %s: name of the group */
			sprintf( __( '[%s] Your waiting list data expires soon', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $grupo ),
			array(
				/* translators: 1: name of the person signed up, 2: name of the group, 3: date */
				sprintf( __( 'The data of <strong>%1$s</strong> on the waiting list of <strong>%2$s</strong> will be deleted on <strong>%3$s</strong>.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), esc_html( self::nombre( $fila ) ), esc_html( $grupo ), esc_html( self::fecha( (string) $fila['caduca'] ) ) ),
				/* translators: %d: months */
				esc_html( sprintf( __( 'If you want to stay on the list, sign in and press "Keep my data": we will keep it for %d more months.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), Inscripciones::MESES ) ),
			),
			self::enlace( Enlaces::url_acceso( $fila ), $vista ),
			__( 'Keep my data', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
			esc_html( __( 'If you do nothing, they will be deleted on that day and you will leave the list.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ),
		);
	}

	/** @return callable fn( string $grupo ): array — ver enviar(). */
	private static function de_mensaje( array $fila, string $asunto, string $texto, bool $vista = false ): callable {
		return static fn( string $grupo ): array => array(
			sprintf( '[%s] %s', $grupo, $asunto ),
			array( nl2br( esc_html( $texto ) ) ),
			'',
			'',
			/* translators: 1: name of the group, 2: link */
			sprintf( __( 'You can reply to this email. You are on the waiting list of %1$s: <a href="%2$s">see my details</a>.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), esc_html( $grupo ), esc_url( self::enlace( Enlaces::url_acceso( $fila ), $vista ) ) ),
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
				'error'   => __( 'The sign-up does not have a valid email address.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
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
