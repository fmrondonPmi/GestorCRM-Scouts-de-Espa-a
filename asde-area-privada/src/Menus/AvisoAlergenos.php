<?php
namespace AsdeBalears\AreaPrivada\Menus;

use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Crm\PersonaRepository;
use AsdeBalears\AreaPrivada\Domain\Grupo;
use AsdeBalears\AreaPrivada\Eventos\Config;
use AsdeBalears\AreaPrivada\Eventos\EventosTable;
use AsdeBalears\AreaPrivada\Eventos\InscripcionesTable;
use AsdeBalears\AreaPrivada\Eventos\Tareas;
use AsdeBalears\AreaPrivada\Eventos\TareasTable;
use AsdeBalears\AreaPrivada\Frontend\Pages;
use AsdeBalears\AreaPrivada\Frontend\TemplateLoader;
use AsdeBalears\AreaPrivada\Idiomas\Idioma;
use AsdeBalears\AreaPrivada\Usuarios\Contacto;
use AsdeBalears\AreaPrivada\Usuarios\EmailRechazo;
use AsdeBalears\AreaPrivada\Usuarios\RegistroEmails;
use AsdeBalears\AreaPrivada\Usuarios\Usuarios;

defined( 'ABSPATH' ) || exit;

/**
 * Aviso de «alérgenos sin resolver» en el menú de un evento (doc 49, P-192, v0.43).
 *
 * Para no depender de que alguien abra la pestaña Menú: cuando llega la fecha de la tarea «Revisar alergias…» del calendario (la que
 * lleva el enlace al menú y no se mide sola) y hasta que empieza el evento, la tarea diaria del mantenimiento vuelve a validar el menú
 * contra los asistentes. Si quedan platos que llevan el alérgeno de alguien y nadie ha confirmado la alternativa, escribe **al equipo
 * del evento** (director/a, monitores y en prácticas; el equipo de apoyo recibe la hoja de cocina, que es otro envío).
 *
 * - **Con nombres solo a quien ya ve la salud de todo el grupo**; el resto recibe el plato, el alérgeno y cuántas personas, sin nombres.
 * - **No se guarda nada de salud**: el registro de emails guarda el tipo, el destino enmascarado y la fecha, y de lo avisado solo se
 *   recuerda una huella con sal (para no repetir el mismo aviso cada día: se vuelve a mandar si cambia lo pendiente).
 * - Se desactiva con los demás avisos del calendario (Eventos → Configuración).
 */
final class AvisoAlergenos {

	public const TIPO = 'alergenos';

	private const OPCION = 'asde_ap_aviso_alerg_';

	/**
	 * Manda los avisos que toquen hoy en el grupo actual.
	 *
	 * @param int[]|null $solo Solo estos eventos (las pruebas no tocan los demás).
	 * @return int Emails enviados (o intentados, con su registro).
	 */
	public static function diario( ?string $hoy = null, ?array $solo = null ): int {
		if ( empty( Config::de()['avisos_tareas'] ) ) {
			return 0;
		}
		$hoy      = $hoy ?? wp_date( 'Y-m-d' );
		$enviados = 0;
		foreach ( EventosTable::todos() as $e ) {
			if ( null !== $solo && ! in_array( $e['id'], $solo, true ) ) {
				continue;
			}
			if ( EventosTable::CANCELADO === $e['estado'] || null !== $e['cerrado_en'] || $hoy > substr( (string) $e['inicio'], 0, 10 ) ) {
				continue;
			}
			if ( ! self::toca( $e, $hoy ) ) {
				continue;
			}
			$pendientes = self::pendientes( $e );
			if ( ! $pendientes ) {
				self::olvidar( $e['id'] );
				continue;
			}
			$huella = self::huella( $pendientes );
			$antes  = get_option( self::OPCION . $e['id'], null );
			if ( is_array( $antes ) && hash_equals( (string) ( $antes['huella'] ?? '' ), $huella ) ) {
				continue; // Ya se avisó de esto mismo.
			}
			$n = self::enviar( $e, $pendientes );
			if ( $n > 0 ) {
				update_option( self::OPCION . $e['id'], array( 'huella' => $huella, 'cuando' => current_time( 'mysql', true ) ), false );
				Logger::log( 'menu_aviso_alergenos', 'evento', (string) $e['id'], array( 'enviados' => $n ) );
				$enviados += $n;
			}
		}
		return $enviados;
	}

	public static function olvidar( int $evento_id ): void {
		delete_option( self::OPCION . $evento_id );
	}

	/** ¿Ya ha llegado la fecha de la tarea «Revisar alergias…» de este evento? Sin esa tarea en el calendario, no se avisa. */
	public static function toca( array $e, string $hoy ): bool {
		foreach ( TareasTable::de_evento( $e['id'] ) as $t ) {
			if ( 'menu' === $t['enlace'] && '' === $t['auto'] ) {
				$d = Tareas::dias( $t, $hoy );
				return null !== $d && $d <= 0;
			}
		}
		return false;
	}

	/**
	 * Los alérgenos que siguen sin confirmar en algún plato.
	 *
	 * @return array[] Cada uno: plato_id, dia, comida, plato, codigo, alergeno, n, nombres.
	 */
	public static function pendientes( array $e ): array {
		$r = Validacion::evaluar( $e, true );
		if ( 'ok' !== $r['estado'] ) {
			return array();
		}
		$confirmadas = AvisosTable::de_evento( $e['id'] );
		$out         = array();
		foreach ( MenuEvento::platos( $e ) as $pl ) {
			$id = $pl['plato']['id'];
			foreach ( $r['platos'][ $id ]['conflictos'] ?? array() as $cod => $d ) {
				if ( isset( $confirmadas[ $id . '|' . $cod ] ) ) {
					continue;
				}
				$out[] = array(
					'plato_id' => $id,
					'dia'      => $pl['plato']['dia'],
					'comida'   => $pl['plato']['comida'],
					'plato'    => IngredientesTable::COMUN === $pl['receta']['grupo'] ? asde_t( $pl['receta']['nombre'] ) : $pl['receta']['nombre'],
					'codigo'   => $cod,
					'alergeno' => Alergenos::nombre( $cod ),
					'n'        => $d['n'],
					'nombres'  => $d['nombres'],
				);
			}
		}
		return $out;
	}

	private static function huella( array $pendientes ): string {
		$simple = array_map( static fn( array $p ) => array( $p['plato_id'], $p['codigo'], $p['n'] ), $pendientes );
		sort( $simple );
		return hash_hmac( 'sha256', (string) wp_json_encode( $simple ), wp_salt( 'auth' ) );
	}

	/** Las cuentas del equipo del evento (sin el apoyo, que recibe la hoja de cocina). @return \WP_User[] */
	public static function destinatarios( array $e ): array {
		$socios = array();
		foreach ( InscripcionesTable::equipo( $e['id'] ) as $f ) {
			if ( 'apoyo' !== $f['papel'] ) {
				$socios[] = $f['socio'];
			}
		}
		$out = array();
		foreach ( $socios ? Usuarios::por_socios( $socios ) : array() as $u ) {
			if ( Usuarios::DESACTIVADO !== Usuarios::estado( $u ) ) {
				$out[ $u->ID ] = $u;
			}
		}
		return array_values( $out );
	}

	/** ¿Esta cuenta ve la salud de todo el grupo (y por tanto los nombres)? */
	public static function ve_nombres( \WP_User $u ): bool {
		return user_can( $u, 'asde_view_health' ) && user_can( $u, 'asde_view_all' );
	}

	/** @return int Emails enviados (o intentados, con su registro). */
	private static function enviar( array $e, array $pendientes ): int {
		$cuentas = self::destinatarios( $e );
		$datos   = array(
			'tipo'   => self::TIPO,
			'grupo'  => Grupo::nombre(),
			'evento' => $e['nombre'],
			'numero' => EventosTable::numero( $e ),
			'url'    => Pages::url( 'eventos', array( 'evento' => (string) $e['id'], 'pestana' => 'menu' ) ),
		);
		$repo = PersonaRepository::make();
		foreach ( $cuentas as $user ) {
			$con = self::ve_nombres( $user );
			Idioma::en(
				Idioma::de_usuario( $user->ID ),
				static function () use ( $user, $datos, $pendientes, $con, $repo ): void {
					/* translators: 1: grupo, 2: evento */
					$asunto = sprintf( asde_t( '[%1$s] Alérgenos sin resolver en el menú de %2$s' ), (string) $datos['grupo'], (string) $datos['evento'] );
					$email  = Contacto::email( $user, $repo );
					if ( ! $email ) {
						RegistroEmails::registrar( $user, RegistroEmails::EVENTOS, '', $asunto, RegistroEmails::SIN_EMAIL, asde_t( 'La cuenta no tiene un email al que escribir.' ), $datos );
						return;
					}
					$html  = self::html( $datos, Contacto::saludo( $user, $repo ), $pendientes, $con );
					$error = RegistroEmails::enviar( $email, $asunto, $html );
					RegistroEmails::registrar( $user, RegistroEmails::EVENTOS, Contacto::enmascarar( $email ), $asunto, '' === $error ? RegistroEmails::ENVIADO : RegistroEmails::ERROR, $error, $datos );
				}
			);
		}
		return count( $cuentas );
	}

	/** Texto del email. Sin la lista de pendientes (vista previa del registro) solo lleva el aviso general, sin datos de salud. */
	public static function html( array $datos, string $saludo, array $pendientes = array(), bool $con_nombres = false ): string {
		return TemplateLoader::render(
			'email/menu-alergenos',
			array(
				'saludo'      => $saludo,
				'grupo'       => (string) ( $datos['grupo'] ?? '' ),
				'evento'      => (string) ( $datos['evento'] ?? '' ),
				'numero'      => (string) ( $datos['numero'] ?? '' ),
				'url'         => (string) ( $datos['url'] ?? '' ),
				'pendientes'  => $pendientes,
				'con_nombres' => $con_nombres,
				'contacto'    => EmailRechazo::contacto(),
			)
		);
	}
}
