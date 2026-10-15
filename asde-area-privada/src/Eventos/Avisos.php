<?php
namespace AsdeBalears\AreaPrivada\Eventos;

use AsdeBalears\AreaPrivada\Crm\CrmException;
use AsdeBalears\AreaPrivada\Crm\PersonaRepository;
use AsdeBalears\AreaPrivada\Domain\Grupo;
use AsdeBalears\AreaPrivada\Domain\Grupos;
use AsdeBalears\AreaPrivada\Domain\Persona;
use AsdeBalears\AreaPrivada\Frontend\Pages;
use AsdeBalears\AreaPrivada\Frontend\TemplateLoader;
use AsdeBalears\AreaPrivada\Idiomas\Idioma;
use AsdeBalears\AreaPrivada\Tesoreria\Calculadora;
use AsdeBalears\AreaPrivada\Usuarios\Contacto;
use AsdeBalears\AreaPrivada\Usuarios\EmailRechazo;
use AsdeBalears\AreaPrivada\Usuarios\RegistroEmails;
use AsdeBalears\AreaPrivada\Usuarios\Usuarios;

defined( 'ABSPATH' ) || exit;

/**
 * Avisos de los eventos (v0.35, P-152): emails y el enganche para los avisos push.
 *
 * - **publicado**: a las cuentas de las personas convocadas (familias y asociados de 14 años
 *   o más). Se manda en segundo plano (WP-Cron) para no hacer esperar a quien publica.
 * - **confirmada**: cuando una inscripción queda confirmada (autorizada y pagada).
 * - **plaza**: cuando se libera una plaza para alguien de la lista de espera.
 * - **recordatorio**: unos días antes (Config::recordatorio_dias), con lo que falte.
 * - **cancelado**: a quien estaba inscrito/a o en la lista de espera (en segundo plano).
 *
 * Cada email sale en el idioma de quien lo recibe y queda en el registro de emails (tipo
 * «eventos») sin datos personales: el evento, la inscripción (su id) y lo que falta. Cada
 * aviso dispara `do_action( 'asde_ap_evento_aviso', $tipo, $evento_id, $user_ids )` para que
 * la app móvil mande su aviso push (este plugin no usa clases de la app).
 */
final class Avisos {

	/** WP-Cron: avisos masivos (grupo, evento, tipo). */
	public const HOOK = 'asde_ap_eventos_avisos';

	/** Enganche genérico para los avisos push: ( string $tipo, int $evento_id, int[] $user_ids ). */
	public const PUSH = 'asde_ap_evento_aviso';

	public const TIPOS = array( 'publicado', 'confirmada', 'plaza', 'recordatorio', 'cancelado' );

	public static function register(): void {
		add_action( self::HOOK, array( self::class, 'masivo' ), 10, 3 );
	}

	/** Programa el aviso masivo (publicación o cancelación) en segundo plano. */
	public static function programar( array $e, string $tipo ): void {
		if ( in_array( $tipo, array( 'publicado', 'cancelado' ), true ) && ! wp_next_scheduled( self::HOOK, array( $e['grupo'], $e['id'], $tipo ) ) ) {
			wp_schedule_single_event( time(), self::HOOK, array( $e['grupo'], $e['id'], $tipo ) );
		}
	}

	/**
	 * Aviso masivo de un evento (lo llama WP-Cron, en el contexto de su grupo).
	 *
	 * @return array{cuentas:int, enviados:int}
	 */
	public static function masivo( string $grupo, int $evento_id, string $tipo ): array {
		$g = Grupos::por_numero( $grupo );
		if ( ! $g ) {
			return array(
				'cuentas'  => 0,
				'enviados' => 0,
			);
		}
		return Grupo::con( $g, static fn() => self::masivo_en_grupo( $evento_id, $tipo ) );
	}

	/** @return array{cuentas:int, enviados:int} */
	private static function masivo_en_grupo( int $evento_id, string $tipo ): array {
		$res = array(
			'cuentas'  => 0,
			'enviados' => 0,
		);
		$e    = EventosTable::obtener( $evento_id );
		$repo = PersonaRepository::make();
		if ( ! $e || ! $repo ) {
			return $res;
		}
		$personas = array();
		try {
			if ( 'publicado' === $tipo ) {
				if ( EventosTable::PUBLICADO !== $e['estado'] || null !== $e['avisado_en'] ) {
					return $res; // Ya avisado (p. ej. se volvió a borrador y se publicó otra vez).
				}
				foreach ( $repo->todas() as $p ) {
					if ( Inscripciones::convocada( $e, $p ) ) {
						$personas[ $p->num_socio_asde ] = $p;
					}
				}
				EventosTable::marcar_aviso( $e['id'], 'avisado_en' );
			} elseif ( 'cancelado' === $tipo ) {
				if ( EventosTable::CANCELADO !== $e['estado'] ) {
					return $res;
				}
				$nums = array();
				foreach ( InscripcionesTable::participantes( $e['id'] ) as $f ) {
					if ( in_array( $f['estado'], array( InscripcionesTable::PENDIENTE, InscripcionesTable::CONFIRMADA, InscripcionesTable::ESPERA ), true ) ) {
						$nums[] = $f['socio'];
					}
				}
				$personas = $nums ? $repo->obtener_varias( $nums ) : array();
			} else {
				return $res;
			}
		} catch ( CrmException $ex ) {
			return $res; // Sin el CRM no se sabe a quién escribir: no se marca y se puede repetir.
		}
		$datos   = self::datos( $e, $tipo );
		$cuentas = self::cuentas( $personas, $e );
		foreach ( $cuentas as $user ) {
			++$res['cuentas'];
			$res['enviados'] += (int) Idioma::en( Idioma::de_usuario( $user->ID ), static fn() => self::enviar( $user, self::asunto( $tipo, $e ), $datos, '' ) );
		}
		self::push( $tipo, $e, $cuentas );
		return $res;
	}

	/** La inscripción ha quedado confirmada. */
	public static function confirmada( array $e, array $f ): int {
		return self::a_inscripcion( $e, $f, 'confirmada' );
	}

	/** Se ha liberado una plaza para alguien de la lista de espera. */
	public static function plaza( array $e, array $f ): int {
		return self::a_inscripcion( $e, $f, 'plaza' );
	}

	/**
	 * Tarea diaria del grupo actual: mantiene las listas de espera y manda los recordatorios.
	 *
	 * @param int[]|null $solo Solo estos eventos (las pruebas no tocan los demás).
	 * @return int Recordatorios enviados.
	 */
	public static function diario( ?int $ahora = null, ?array $solo = null ): int {
		$ahora    = $ahora ?? time();
		$cfg      = Config::de();
		$enviados = 0;
		foreach ( EventosTable::todos() as $e ) {
			if ( null !== $solo && ! in_array( $e['id'], $solo, true ) ) {
				continue;
			}
			if ( EventosTable::PUBLICADO !== $e['estado'] || $ahora >= EventosTable::marca( $e['fin'] ) ) {
				continue;
			}
			Inscripciones::revisar( $e, $ahora );
			$inicio = EventosTable::marca( $e['inicio'] );
			if ( $cfg['recordatorio_dias'] < 1 || null !== $e['recordatorio_en'] || $ahora >= $inicio || $inicio - $ahora > $cfg['recordatorio_dias'] * DAY_IN_SECONDS ) {
				continue;
			}
			$movs = MovimientosTable::de_evento( $e['id'] );
			foreach ( InscripcionesTable::participantes( $e['id'] ) as $f ) {
				if ( in_array( $f['estado'], InscripcionesTable::ACTIVAS, true ) ) {
					$enviados += self::a_inscripcion( $e, $f, 'recordatorio', $movs[ $f['id'] ] ?? array() );
				}
			}
			EventosTable::marcar_aviso( $e['id'], 'recordatorio_en' );
		}
		return $enviados;
	}

	/**
	 * Cuentas que llevan las inscripciones de estas personas: las familiares (de los menores) y
	 * la propia de quien tiene 14 años o más el día que empieza el evento. Sin las desactivadas.
	 *
	 * @param array<string, Persona> $personas
	 * @return array<int, \WP_User>
	 */
	public static function cuentas( array $personas, array $e ): array {
		$out = array();
		foreach ( Usuarios::cuentas_familiares( array_keys( $personas ) ) as $u ) {
			$out[ $u->ID ] = $u;
		}
		foreach ( Usuarios::por_socios( array_keys( $personas ) ) as $num => $u ) {
			$edad = isset( $personas[ $num ] ) ? Normativa::edad_en( $personas[ $num ], $e ) : null;
			if ( null !== $edad && $edad >= Inscripciones::EDAD_PREINSCRIPCION ) {
				$out[ $u->ID ] = $u;
			}
		}
		return array_filter( $out, static fn( \WP_User $u ) => Usuarios::DESACTIVADO !== Usuarios::estado( $u ) );
	}

	/** Texto del email (el envío y la vista previa del registro de emails usan lo mismo). */
	public static function html( array $datos, string $saludo, string $quien = '' ): string {
		if ( '' === $quien && ! empty( $datos['inscripcion'] ) ) {
			$quien = self::nombre_de( (int) $datos['inscripcion'] ); // Vista previa: el nombre se vuelve a leer del CRM.
		}
		return TemplateLoader::render(
			'email/evento',
			array(
				'tipo'       => in_array( $datos['tipo'] ?? '', self::TIPOS, true ) ? (string) $datos['tipo'] : 'publicado',
				'saludo'     => $saludo,
				'quien'      => $quien,
				'grupo'      => (string) ( $datos['grupo'] ?? '' ),
				'evento'     => (string) ( $datos['evento'] ?? '' ),
				'numero'     => (string) ( $datos['numero'] ?? '' ),
				'cuando'     => (string) ( $datos['cuando'] ?? '' ),
				'lugar'      => (string) ( $datos['lugar'] ?? '' ),
				'salida'     => (string) ( $datos['salida'] ?? '' ),
				'llegada'    => (string) ( $datos['llegada'] ?? '' ),
				'cierre'     => (string) ( $datos['cierre'] ?? '' ),
				'hasta'      => (string) ( $datos['hasta'] ?? '' ),
				'motivo'     => (string) ( $datos['motivo'] ?? '' ),
				'falta'      => array_map( 'strval', (array) ( $datos['falta'] ?? array() ) ),
				'importe'    => (string) ( $datos['importe'] ?? '' ),
				'referencia' => (string) ( $datos['referencia'] ?? '' ),
				'url'        => (string) ( $datos['url'] ?? '' ),
				'contacto'   => (string) ( $datos['contacto'] ?? '' ),
			)
		);
	}

	// ---------------------------------------------------------------------

	/** Email a las cuentas de una inscripción. @return int Cuántos han salido. */
	private static function a_inscripcion( array $e, array $f, string $tipo, ?array $movs = null ): int {
		$repo = PersonaRepository::make();
		try {
			$p = $repo ? $repo->obtener( $f['socio'] ) : null;
		} catch ( CrmException $ex ) {
			$p = null;
		}
		if ( ! $p ) {
			return 0;
		}
		$datos                = self::datos( $e, $tipo );
		$datos['inscripcion'] = $f['id'];
		if ( in_array( $tipo, array( 'plaza' ), true ) && null !== $f['oferta_hasta'] ) {
			$datos['hasta'] = wp_date( 'd/m/Y H:i', (int) strtotime( $f['oferta_hasta'] . ' UTC' ) );
		}
		if ( 'recordatorio' === $tipo ) {
			$cobro               = Cobros::de( $f, $movs ?? ( MovimientosTable::de_inscripciones( array( $f['id'] ) )[ $f['id'] ] ?? array() ) );
			$datos['falta']      = Inscripciones::falta( $f, $cobro );
			$datos['importe']    = $cobro['pendiente'] > 0 ? Calculadora::euros( $cobro['pendiente'] ) : '';
			$datos['referencia'] = $cobro['pendiente'] > 0 ? Cobros::referencia( $e, $f ) : '';
		}
		$cuentas = self::cuentas( array( $f['socio'] => $p ), $e );
		$n       = 0;
		foreach ( $cuentas as $user ) {
			$n += (int) Idioma::en( Idioma::de_usuario( $user->ID ), static fn() => self::enviar( $user, self::asunto( $tipo, $e ), $datos, $p->nombre ) );
		}
		self::push( $tipo, $e, $cuentas );
		return $n;
	}

	/** Asunto del email, en el idioma de quien lo recibe (se llama dentro de Idioma::en). */
	private static function asunto( string $tipo, array $e ): string {
		switch ( $tipo ) {
			case 'confirmada':
				$plantilla = asde_t( '[%1$s] Inscripción confirmada: %2$s' );
				break;
			case 'plaza':
				$plantilla = asde_t( '[%1$s] Se ha liberado una plaza en %2$s: confírmala' );
				break;
			case 'recordatorio':
				$plantilla = asde_t( '[%1$s] Recordatorio: %2$s' );
				break;
			case 'cancelado':
				$plantilla = asde_t( '[%1$s] Evento cancelado: %2$s' );
				break;
			default:
				$plantilla = asde_t( '[%1$s] Nuevo evento: %2$s' );
		}
		return sprintf( $plantilla, Grupo::nombre(), $e['nombre'] );
	}

	/** Datos del email sin datos personales (se guardan en el registro de emails). */
	private static function datos( array $e, string $tipo ): array {
		return array(
			'tipo'     => $tipo,
			'grupo'    => Grupo::nombre(),
			'evento'   => $e['nombre'],
			'numero'   => EventosTable::numero( $e ),
			'cuando'   => EventosTable::cuando( $e ),
			'lugar'    => $e['lugar'],
			'salida'   => $e['salida'],
			'llegada'  => $e['llegada'],
			'cierre'   => 'publicado' === $tipo ? wp_date( 'd/m/Y H:i', Inscripciones::cierra( $e ) ) : '',
			'motivo'   => 'cancelado' === $tipo ? (string) $e['cancelado_motivo'] : '',
			'url'      => Pages::url( 'mis_eventos' ),
			'contacto' => EmailRechazo::contacto(),
		);
	}

	private static function push( string $tipo, array $e, array $cuentas ): void {
		if ( $cuentas ) {
			do_action( 'asde_ap_evento_aviso', $tipo, $e['id'], array_map( 'intval', array_keys( $cuentas ) ) ); // = self::PUSH (literal para Plugin Check).
		}
	}

	/** @return bool Si el servidor de correo lo aceptó. */
	private static function enviar( \WP_User $user, string $asunto, array $datos, string $quien ): bool {
		$repo  = PersonaRepository::make();
		$email = Contacto::email( $user, $repo );
		if ( ! $email ) {
			RegistroEmails::registrar( $user, RegistroEmails::EVENTOS, '', $asunto, RegistroEmails::SIN_EMAIL, asde_t( 'La cuenta no tiene un email al que escribir.' ), $datos );
			return false;
		}
		$error = RegistroEmails::enviar( $email, $asunto, self::html( $datos, Contacto::saludo( $user, $repo ), $quien ) );
		RegistroEmails::registrar( $user, RegistroEmails::EVENTOS, Contacto::enmascarar( $email ), $asunto, '' === $error ? RegistroEmails::ENVIADO : RegistroEmails::ERROR, $error, $datos );
		return '' === $error;
	}

	/** Nombre de la persona de una inscripción (para la vista previa del registro), o ''. */
	private static function nombre_de( int $inscripcion ): string {
		$f    = InscripcionesTable::obtener( $inscripcion );
		$repo = $f ? PersonaRepository::make() : null;
		try {
			$p = $repo ? $repo->obtener( $f['socio'] ) : null;
		} catch ( CrmException $e ) {
			$p = null;
		}
		return $p ? $p->nombre : '';
	}
}
