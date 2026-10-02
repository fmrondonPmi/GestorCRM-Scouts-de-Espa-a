<?php
namespace AsdeBalears\AreaPrivada\Crm;

use AsdeBalears\AreaPrivada\Domain\Grupo;
use AsdeBalears\AreaPrivada\Domain\Grupos;

defined( 'ABSPATH' ) || exit;

/**
 * Instantánea de las fichas del grupo (v0.12.3): la causa de que la web fuese lenta.
 *
 * Cada consulta al CRM tarda entre 0,5 y 1,5 s (el tiempo de ida y vuelta, no el
 * tamaño), y una sola página del área hacía de 1 a 6 consultas seguidas. Ahora se
 * lee el grupo entero UNA vez y se sirve de memoria a todas las páginas durante unos
 * minutos: el resto de las páginas no espera al CRM.
 *
 * Qué se guarda y cómo (doc 08, punto 3: «caché corta para lo no sensible»):
 * - Solo los campos de la lista blanca de PersonaRepository::CAMPOS (nombre,
 *   nacimiento, email, móvil, secciones, cargo y tutores). NUNCA salud ni dietas:
 *   eso se sigue pidiendo al CRM en cada consulta.
 * - Cifrado (libsodium, Crypto) y comprimido, en un transient que caduca solo.
 * - Fresca 5 minutos. Pasado ese tiempo se sigue sirviendo la antigua mientras se
 *   vuelve a leer en segundo plano (WP-Cron); a los 10 minutos ya no se sirve.
 * - Lo que se guarda en el CRM desde la web (Escritura) se aplica también aquí.
 * - Quien edita el CRM por otro lado se ve en la web en unos minutos; «Actualizar»
 *   (Personas y Usuarios) lo fuerza. Con el filtro asde_ap_instantanea_segundos se
 *   cambia el tiempo, y con 0 se desactiva (todo se lee del CRM, como antes).
 */
final class Instantanea {

	public const HOOK = 'asde_ap_instantanea_refrescar';

	private const PREFIJO = 'asde_ap_snap_';
	private const SEGUNDOS = 300;

	/** Cada cuánto como mucho se puede forzar una lectura del CRM desde un botón «Actualizar». */
	public const MIN_FORZAR = 30;

	private const CERROJO = 'asde_ap_snaplock_';

	/** @var array<string, array{t:int, entries:list<array>}> Lo ya descifrado en esta petición. */
	private static array $memoria = array();

	/** @var array<string, array<string, int[]>> Posición de cada ficha en la instantánea, por nº de Scouts de España. */
	private static array $indice = array();

	public static function register(): void {
		add_action( self::HOOK, array( self::class, 'refrescar_en_segundo_plano' ) );
	}

	/** Cuánto tiempo está «fresca» la instantánea; 0 = no se usa. En las pruebas automáticas se desactiva. */
	public static function segundos(): int {
		if ( Escritura::en_pruebas() && ! apply_filters( 'asde_ap_instantanea_en_pruebas', false ) ) {
			return 0;
		}
		return max( 0, (int) apply_filters( 'asde_ap_instantanea_segundos', self::SEGUNDOS ) );
	}

	/** @return array{t:int, entries:list<array{id:string, fields:array<string,string>}>}|null */
	public static function leer( string $grupo ): ?array {
		if ( isset( self::$memoria[ $grupo ] ) ) {
			return self::$memoria[ $grupo ];
		}
		$guardado = get_transient( self::PREFIJO . $grupo );
		if ( ! is_string( $guardado ) || '' === $guardado ) {
			return null;
		}
		$bin  = Crypto::decrypt( $guardado );
		$json = null !== $bin ? @gzuncompress( $bin ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors
		$snap = false !== $json ? json_decode( $json, true ) : null;
		if ( ! is_array( $snap ) || ! isset( $snap['t'], $snap['entries'] ) || ! is_array( $snap['entries'] ) ) {
			return null;
		}
		unset( self::$indice[ $grupo ] );
		return self::$memoria[ $grupo ] = $snap;
	}

	/**
	 * Las fichas con estos nº de Scouts de España, buscadas por un índice (se construye una vez
	 * por petición) en vez de recorrer todo el grupo en cada consulta.
	 *
	 * @param string[] $nums
	 * @return list<array{id:string, fields:array<string,string>}>
	 */
	public static function por_numeros( string $grupo, array $nums ): array {
		$snap = self::leer( $grupo );
		if ( ! $snap ) {
			return array();
		}
		if ( ! isset( self::$indice[ $grupo ] ) ) {
			self::$indice[ $grupo ] = array();
			foreach ( $snap['entries'] as $i => $e ) {
				$n = trim( (string) ( $e['fields']['numerosocio_c'] ?? '' ) );
				if ( '' !== $n ) {
					self::$indice[ $grupo ][ $n ][] = $i;
				}
			}
		}
		$out = array();
		foreach ( $nums as $n ) {
			foreach ( self::$indice[ $grupo ][ (string) $n ] ?? array() as $i ) {
				$out[] = $snap['entries'][ $i ];
			}
		}
		return $out;
	}

	/** @param list<array{id:string, fields:array<string,string>}> $entries */
	public static function guardar( string $grupo, array $entries, ?int $t = null ): void {
		$snap = array(
			't'       => $t ?? time(),
			'entries' => $entries,
		);
		$s    = max( 1, self::segundos() );
		set_transient( self::PREFIJO . $grupo, Crypto::encrypt( (string) gzcompress( (string) wp_json_encode( $snap ), 6 ) ), 2 * $s + 60 );
		self::$memoria[ $grupo ] = $snap;
		unset( self::$indice[ $grupo ] );
	}

	/** 'fresca' (se usa tal cual), 'gracia' (se usa y se refresca en segundo plano) o 'caducada' (hay que leer el CRM). */
	public static function estado( ?array $snap ): string {
		if ( ! $snap ) {
			return 'caducada';
		}
		$edad = time() - (int) $snap['t'];
		$s    = self::segundos();
		return $edad < $s ? 'fresca' : ( $edad < 2 * $s ? 'gracia' : 'caducada' );
	}

	/** Segundos desde que se leyó del CRM, o null si no hay instantánea. */
	public static function edad( string $grupo ): ?int {
		$snap = self::leer( $grupo );
		return $snap ? max( 0, time() - (int) $snap['t'] ) : null;
	}

	public static function olvidar( string $grupo = '' ): void {
		$grupo = '' !== $grupo ? $grupo : Grupo::numero();
		unset( self::$memoria[ $grupo ], self::$indice[ $grupo ] );
		delete_transient( self::PREFIJO . $grupo );
	}

	/** ¿Ha pasado el tiempo mínimo desde la última lectura del CRM para dejar que se fuerce otra? (evita machacar el CRM) */
	public static function puede_forzar( string $grupo = '' ): bool {
		$edad = self::edad( '' !== $grupo ? $grupo : Grupo::numero() );
		return null === $edad || $edad >= self::MIN_FORZAR;
	}

	/**
	 * Cerrojo para que, al caducar la instantánea, UNA sola petición lea el CRM y las demás
	 * esperen su resultado (sin él, todas las que llegan a la vez lo leen). Un cerrojo
	 * olvidado (la petición murió) se da por perdido a los 60 s.
	 */
	public static function tomar_cerrojo( string $grupo ): bool {
		$nombre = self::CERROJO . $grupo;
		if ( add_option( $nombre, time(), '', 'no' ) ) {
			return true;
		}
		wp_cache_delete( $nombre, 'options' );
		if ( time() - (int) get_option( $nombre, 0 ) > 60 ) {
			update_option( $nombre, time(), false );
			return true;
		}
		return false;
	}

	public static function soltar_cerrojo( string $grupo ): void {
		delete_option( self::CERROJO . $grupo );
	}

	/**
	 * Espera a que otra petición deje lista la instantánea (hasta unos segundos).
	 *
	 * @return array{t:int, entries:list<array>}|null null si no ha aparecido a tiempo.
	 */
	public static function esperar_fresca( string $grupo ): ?array {
		$espera = max( 0.0, (float) apply_filters( 'asde_ap_instantanea_espera', 8.0 ) );
		$fin    = microtime( true ) + $espera;
		do {
			usleep( 250000 );
			unset( self::$memoria[ $grupo ], self::$indice[ $grupo ] );
			$snap = self::leer( $grupo );
			if ( 'fresca' === self::estado( $snap ) ) {
				return $snap;
			}
		} while ( microtime( true ) < $fin );
		return null;
	}

	/**
	 * Aplica aquí lo que se acaba de guardar en el CRM (sin cambiar la antigüedad de
	 * la instantánea), para que la web lo vea al momento.
	 *
	 * @param array<string, string> $valores campo => valor guardado (tal como lo devolvió el CRM)
	 */
	public static function parchear( string $grupo, string $id, array $valores ): void {
		$snap = self::leer( $grupo );
		if ( ! $snap ) {
			return;
		}
		$campos = array_flip( PersonaRepository::CAMPOS );
		foreach ( $snap['entries'] as $i => $e ) {
			if ( (string) $e['id'] === $id ) {
				foreach ( $valores as $campo => $valor ) {
					if ( isset( $campos[ $campo ] ) ) {
						$snap['entries'][ $i ]['fields'][ $campo ] = (string) $valor;
					}
				}
				self::guardar( $grupo, $snap['entries'], (int) $snap['t'] );
				return;
			}
		}
	}

	/** Pide que se vuelva a leer el CRM en segundo plano (WP-Cron), sin que quien navega espere. */
	public static function pedir_refresco( string $grupo ): void {
		if ( ! wp_next_scheduled( self::HOOK, array( $grupo ) ) ) {
			wp_schedule_single_event( time(), self::HOOK, array( $grupo ) );
			spawn_cron();
		}
	}

	public static function refrescar_en_segundo_plano( string $grupo ): void {
		$datos = Grupos::por_numero( $grupo );
		if ( ! $datos ) {
			return;
		}
		Grupo::forzar( $datos );
		try {
			$repo = PersonaRepository::make();
			if ( $repo ) {
				$repo->refrescar_instantanea();
			}
		} catch ( CrmException $e ) {
			unset( $e ); // Se reintenta con la próxima visita.
		} finally {
			Grupo::forzar( null );
		}
	}
}
