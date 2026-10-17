<?php
namespace AsdeBalears\AreaPrivada\Eventos;

use AsdeBalears\AreaPrivada\Crm\PersonaRepository;
use AsdeBalears\AreaPrivada\Domain\Grupo;
use AsdeBalears\AreaPrivada\Frontend\Pages;
use AsdeBalears\AreaPrivada\Frontend\TemplateLoader;
use AsdeBalears\AreaPrivada\Idiomas\Idioma;
use AsdeBalears\AreaPrivada\Usuarios\Contacto;
use AsdeBalears\AreaPrivada\Usuarios\EmailRechazo;
use AsdeBalears\AreaPrivada\Usuarios\RegistroEmails;
use AsdeBalears\AreaPrivada\Usuarios\Usuarios;

defined( 'ABSPATH' ) || exit;

/**
 * Avisos del calendario de tareas (doc 49, fase A2, P-186): un email al **responsable** de la tarea (o, si no tiene,
 * al director/a del evento) **3 días antes** de la fecha límite y otro **cuando vence**. Los manda la tarea diaria
 * del mantenimiento, una sola vez cada uno (la tarea guarda cuándo salió), en el idioma de quien lo recibe, y quedan
 * en el registro de emails (tipo «eventos») sin datos personales: solo la tarea, el evento y la fecha. Cada grupo
 * puede desactivarlos (Eventos → Configuración → Calendario de tareas).
 *
 * No se avisa de lo que ya está hecho, ni de lo que se cumple solo (presupuesto, equipo, autorizaciones), ni de
 * eventos cancelados o con las cuentas cerradas.
 */
final class AvisosTareas {

	public const PREVIO = 'tarea_previa';
	public const VENCE  = 'tarea_vence';
	public const TIPOS  = array( self::PREVIO, self::VENCE );

	/** Días antes de la fecha límite en que sale el primer aviso. */
	public const DIAS_PREVIOS = 3;

	/**
	 * Manda los avisos que toquen hoy en el grupo actual.
	 *
	 * @return int Cuántos emails han salido.
	 */
	public static function diario( ?string $hoy = null ): int {
		$cfg = Config::de();
		if ( empty( $cfg['avisos_tareas'] ) ) {
			return 0;
		}
		$hoy      = $hoy ?? wp_date( 'Y-m-d' );
		$enviados = 0;
		foreach ( EventosTable::todos() as $e ) {
			if ( EventosTable::CANCELADO === $e['estado'] || null !== $e['cerrado_en'] ) {
				continue;
			}
			foreach ( TareasTable::sin_hacer_de( array( $e['id'] ) ) as $t ) {
				$d = Tareas::dias( $t, $hoy );
				if ( null === $d ) {
					continue;
				}
				if ( $d <= 0 && null === $t['aviso_vence_en'] ) {
					$tipo = self::VENCE;
				} elseif ( $d > 0 && $d <= self::DIAS_PREVIOS && null === $t['aviso_previo_en'] ) {
					$tipo = self::PREVIO;
				} else {
					continue;
				}
				if ( '' !== $t['auto'] && true === Tareas::comprobar( $e, $t['auto'] ) ) {
					continue; // Ya se cumple sola.
				}
				$n = self::enviar( $e, $t, $tipo );
				if ( $n > 0 ) {
					$ahora = current_time( 'mysql', true );
					TareasTable::actualizar(
						$t['id'],
						self::VENCE === $tipo ? array( 'aviso_vence_en' => $ahora, 'aviso_previo_en' => $t['aviso_previo_en'] ?? $ahora ) : array( 'aviso_previo_en' => $ahora )
					);
					$enviados += $n;
				}
			}
		}
		return $enviados;
	}

	/** Las cuentas que reciben el aviso: la del responsable o, si no tiene, la del director/a. @return \WP_User[] */
	public static function destinatarios( array $e, array $t ): array {
		$socio = $t['responsable'];
		if ( '' === $socio ) {
			foreach ( InscripcionesTable::equipo( $e['id'] ) as $m ) {
				if ( 'director' === $m['papel'] ) {
					$socio = $m['socio'];
					break;
				}
			}
		}
		$u = '' !== $socio ? Usuarios::por_socio( $socio ) : null;
		return $u && Usuarios::DESACTIVADO !== Usuarios::estado( $u ) ? array( $u ) : array();
	}

	/** @return int Emails enviados (o intentados, con su registro). */
	private static function enviar( array $e, array $t, string $tipo ): int {
		$cuentas = self::destinatarios( $e, $t );
		$datos   = array(
			'tipo'   => $tipo,
			'grupo'  => Grupo::nombre(),
			'evento' => $e['nombre'],
			'numero' => EventosTable::numero( $e ),
			'tarea'  => $t['titulo'],
			'fecha'  => TareasTable::fecha( $t ),
			'url'    => Pages::url( 'eventos', array( 'evento' => (string) $e['id'], 'pestana' => 'calendario' ) ),
		);
		foreach ( $cuentas as $user ) {
			Idioma::en( Idioma::de_usuario( $user->ID ), static fn() => self::mandar( $user, $datos ) );
		}
		if ( $cuentas && Config::de()['push'] ) {
			do_action( 'asde_ap_evento_aviso', 'tarea', $e['id'], array_map( static fn( \WP_User $u ) => (int) $u->ID, $cuentas ) ); // = Avisos::PUSH (literal para Plugin Check).
		}
		return count( $cuentas );
	}

	private static function mandar( \WP_User $user, array $datos ): bool {
		$asunto = self::asunto( $datos );
		$repo   = PersonaRepository::make();
		$email  = Contacto::email( $user, $repo );
		if ( ! $email ) {
			RegistroEmails::registrar( $user, RegistroEmails::EVENTOS, '', $asunto, RegistroEmails::SIN_EMAIL, asde_t( 'La cuenta no tiene un email al que escribir.' ), $datos );
			return false;
		}
		$error = RegistroEmails::enviar( $email, $asunto, self::html( $datos, Contacto::saludo( $user, $repo ) ) );
		RegistroEmails::registrar( $user, RegistroEmails::EVENTOS, Contacto::enmascarar( $email ), $asunto, '' === $error ? RegistroEmails::ENVIADO : RegistroEmails::ERROR, $error, $datos );
		return '' === $error;
	}

	/** Asunto en el idioma de quien lo recibe (se llama dentro de Idioma::en). */
	private static function asunto( array $datos ): string {
		$plantilla = self::VENCE === $datos['tipo']
			? asde_t( '[%1$s] Hoy vence una tarea de %2$s' )
			: asde_t( '[%1$s] Una tarea de %2$s vence pronto' );
		return sprintf( $plantilla, (string) $datos['grupo'], (string) $datos['evento'] );
	}

	/** Texto del email (el envío y la vista previa del registro de emails usan lo mismo). */
	public static function html( array $datos, string $saludo ): string {
		$fecha = (string) ( $datos['fecha'] ?? '' );
		return TemplateLoader::render(
			'email/evento-tarea',
			array(
				'tipo'     => self::VENCE === ( $datos['tipo'] ?? '' ) ? self::VENCE : self::PREVIO,
				'saludo'   => $saludo,
				'grupo'    => (string) ( $datos['grupo'] ?? '' ),
				'evento'   => (string) ( $datos['evento'] ?? '' ),
				'numero'   => (string) ( $datos['numero'] ?? '' ),
				'tarea'    => (string) ( $datos['tarea'] ?? '' ),
				'fecha'    => '' !== $fecha ? wp_date( 'd/m/Y', strtotime( $fecha . ' 12:00:00 UTC' ) ) : '',
				'url'      => (string) ( $datos['url'] ?? '' ),
				'contacto' => EmailRechazo::contacto(),
			)
		);
	}
}
