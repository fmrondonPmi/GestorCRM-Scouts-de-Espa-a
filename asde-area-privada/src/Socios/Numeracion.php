<?php
namespace AsdeBalears\AreaPrivada\Socios;

use AsdeBalears\AreaPrivada\Crm\Escritura;
use AsdeBalears\AreaPrivada\Crm\PersonaRepository;
use AsdeBalears\AreaPrivada\Domain\Grupo;
use AsdeBalears\AreaPrivada\Domain\NumeroSocio;
use AsdeBalears\AreaPrivada\Domain\Persona;
use AsdeBalears\AreaPrivada\Usuarios\Usuarios;

defined( 'ABSPATH' ) || exit;

/**
 * Números de socio: cruza lo que hay en el CRM con la tabla de equivalencias,
 * detecta incidencias y asigna números nuevos.
 */
final class Numeracion {

	public const OK        = 'ok';          // Número correcto en el CRM.
	public const CORREGIDO = 'corregido';   // Formato antiguo en el CRM (076840123 / 6840123), normalizado.
	public const GENERADO  = 'generado';    // Asignado aquí, pendiente de introducir en el CRM.
	public const DUPLICADO = 'duplicado';   // El mismo número en varias fichas del CRM.
	public const INVALIDO  = 'invalido';    // Formato irreconocible.
	public const FALTA     = 'falta';       // Sin número.

	public const ETIQUETAS = array(
		self::OK        => 'Correcto',
		self::CORREGIDO => 'Formato antiguo (corregir en el CRM)',
		self::GENERADO  => 'Generado: pendiente de pasar al CRM',
		self::DUPLICADO => 'Duplicado en el CRM',
		self::INVALIDO  => 'Formato no válido',
		self::FALTA     => 'Sin número',
	);

	/** Estados con los que el número NO se puede usar como usuario. */
	public const BLOQUEANTES = array( self::DUPLICADO, self::INVALIDO, self::FALTA );

	private const CACHE = 'asde_ap_numeracion';

	private string $grupo;

	/** @var array<string, array{numero:?string, estado:string}> asde => resultado */
	private array $analisis = array();

	/** @var string[] Números locales válidos presentes en el CRM. */
	private array $numeros_crm = array();

	/** Si ya se ha rehecho el análisis en esta petición porque faltaba una ficha en la caché. */
	private bool $rehecho = false;

	public function __construct( private PersonaRepository $personas ) {
		$this->grupo = Grupo::actual()['numero'] ?? '';
	}

	public function grupo(): string {
		return $this->grupo;
	}

	/**
	 * Analiza un conjunto de fichas del CRM (idealmente, todas las del grupo)
	 * y sincroniza la tabla de equivalencias.
	 *
	 * @param Persona[] $personas
	 * @return array<string, array{numero:?string, estado:string}> asde => resultado
	 */
	public function analizar( array $personas, bool $sincronizar = true ): array {
		$normalizados = array();
		$conteo       = array();
		foreach ( $personas as $p ) {
			$n                                    = NumeroSocio::normalizar( $p->num_socio_local_crm, $this->grupo );
			$normalizados[ $p->num_socio_asde ] = $n;
			if ( $n ) {
				$conteo[ $n ] = ( $conteo[ $n ] ?? 0 ) + 1;
			}
		}

		$generados = SociosTable::generados_pendientes();
		$pares     = array();
		foreach ( $personas as $p ) {
			$asde = $p->num_socio_asde;
			$n    = $normalizados[ $asde ];
			$raw  = trim( $p->num_socio_local_crm );

			if ( $n && $conteo[ $n ] > 1 ) {
				$r = array( 'numero' => $n, 'estado' => self::DUPLICADO );
			} elseif ( $n ) {
				$r            = array( 'numero' => $n, 'estado' => preg_replace( '/\D/', '', $raw ) === $n ? self::OK : self::CORREGIDO );
				$pares[ $asde ] = $n;
			} elseif ( '' !== $raw ) {
				$r = array( 'numero' => null, 'estado' => self::INVALIDO );
			} elseif ( isset( $generados[ $asde ] ) ) {
				$r = array( 'numero' => $generados[ $asde ], 'estado' => self::GENERADO );
			} else {
				$r = array( 'numero' => null, 'estado' => self::FALTA );
			}
			$this->analisis[ $asde ] = $r;
		}

		$this->numeros_crm = array_map( 'strval', array_keys( $conteo ) );
		if ( $sincronizar && '' !== $this->grupo ) {
			SociosTable::sincronizar( $pares );
		}
		return $this->analisis;
	}

	/**
	 * Analiza TODAS las fichas del grupo (activas e inactivas) y sincroniza.
	 * El resultado (solo números y estados, sin datos personales) se cachea
	 * 10 minutos para no releer todo el grupo en cada ficha.
	 */
	public function analizar_todo( bool $forzar = false ): array {
		$cache = $forzar ? false : get_transient( self::CACHE . '_' . $this->grupo );
		if ( is_array( $cache ) && isset( $cache['analisis'], $cache['numeros_crm'] ) ) {
			$this->analisis    = $cache['analisis'];
			$this->numeros_crm = $cache['numeros_crm'];
			return $this->analisis;
		}
		$this->analizar( $this->personas->todas( false ) );
		set_transient(
			self::CACHE . '_' . $this->grupo,
			array(
				'analisis'    => $this->analisis,
				'numeros_crm' => $this->numeros_crm,
			),
			10 * MINUTE_IN_SECONDS
		);
		return $this->analisis;
	}

	public static function olvidar_cache(): void {
		delete_transient( self::CACHE . '_' . Grupo::numero() );
	}

	/**
	 * Número de socio utilizable como usuario de una persona, o null con el motivo.
	 *
	 * @return array{numero:?string, estado:string}
	 */
	public function de( Persona $p ): array {
		if ( ! isset( $this->analisis[ $p->num_socio_asde ] ) ) {
			// Para detectar duplicados hace falta ver todo el grupo.
			$this->analizar_todo();
		}
		if ( ! isset( $this->analisis[ $p->num_socio_asde ] ) && ! $this->rehecho ) {
			// La caché (10 minutos) no tiene esta ficha (p. ej., es más reciente): se
			// rehace una vez, en vez de darla por «Sin número».
			$this->rehecho = true;
			$this->analizar_todo( true );
		}
		return $this->analisis[ $p->num_socio_asde ] ?? array(
			'numero' => null,
			'estado' => self::FALTA,
		);
	}

	/** Asigna número a quien no tiene o lo tiene mal escrito (solo socios con membresía activa). @return string|\WP_Error */
	public function asignar( Persona $p ) {
		if ( ! $p->tiene_membresia_activa() ) {
			return new \WP_Error( 'asde_num_socio', 'Solo se asigna número a personas con membresía activa.' );
		}
		$actual = $this->de( $p );
		if ( ! in_array( $actual['estado'], array( self::FALTA, self::GENERADO, self::INVALIDO ), true ) ) {
			return new \WP_Error( 'asde_num_socio', 'Esta persona ya tiene número en el CRM (' . self::ETIQUETAS[ $actual['estado'] ] . ').' );
		}
		if ( self::GENERADO === $actual['estado'] ) {
			return (string) $actual['numero'];
		}
		$numero = SociosTable::asignar_siguiente( $p->num_socio_asde, $this->grupo, $this->numeros_crm );
		if ( ! is_wp_error( $numero ) ) {
			$this->analisis[ $p->num_socio_asde ] = array(
				'numero' => $numero,
				'estado' => self::GENERADO,
			);
			self::olvidar_cache();
		}
		return $numero;
	}

	// ---------------------------------------------------------------------
	// Corrección en el CRM (si el usuario del CRM puede editar fichas)
	// ---------------------------------------------------------------------

	/** Guarda el nº local en el CRM (campo «Nº de socio/a local/territorial»). @return true|\WP_Error */
	public function escribir_en_crm( Persona $p, string $numero ) {
		$r = Escritura::guardar( $p, array( 'numsocioantiguo_enficha_c' => $numero ), 'numeracion', true );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		if ( ! $r['guardados'] ) {
			return new \WP_Error( 'asde_num_socio', 'El CRM no ha aceptado el número.' );
		}
		SociosTable::fijar_crm( $p->num_socio_asde, $numero );
		$this->analisis[ $p->num_socio_asde ] = array(
			'numero' => $numero,
			'estado' => self::OK,
		);
		$this->numeros_crm[] = $numero;
		self::olvidar_cache();
		return true;
	}

	/**
	 * Asigna número (a quien no tiene o lo tiene mal) y, si se puede, lo guarda
	 * también en el CRM.
	 *
	 * @return array{numero:string, crm:bool, error:string}|\WP_Error
	 */
	public function asignar_y_guardar( Persona $p ) {
		$numero = $this->asignar( $p );
		if ( is_wp_error( $numero ) ) {
			return $numero;
		}
		if ( ! Escritura::disponible() ) {
			return array(
				'numero' => $numero,
				'crm'    => false,
				'error'  => '',
			);
		}
		$r = $this->escribir_en_crm( $p, $numero );
		return array(
			'numero' => $numero,
			'crm'    => ! is_wp_error( $r ),
			'error'  => is_wp_error( $r ) ? $r->get_error_message() : '',
		);
	}

	/** Formato antiguo en el CRM (076840123 o 6840123): se guarda el correcto (76840123). @return true|\WP_Error */
	public function corregir_formato( Persona $p ) {
		$actual = $this->de( $p );
		if ( self::CORREGIDO !== $actual['estado'] ) {
			return new \WP_Error( 'asde_num_socio', 'El número de esta ficha no tiene formato antiguo.' );
		}
		return $this->escribir_en_crm( $p, (string) $actual['numero'] );
	}

	/** Número generado aquí y pendiente: se guarda en el CRM. @return true|\WP_Error */
	public function pasar_al_crm( Persona $p ) {
		$actual = $this->de( $p );
		if ( self::GENERADO !== $actual['estado'] ) {
			return new \WP_Error( 'asde_num_socio', 'Esta ficha no tiene un número pendiente de pasar al CRM.' );
		}
		return $this->escribir_en_crm( $p, (string) $actual['numero'] );
	}

	/**
	 * Número duplicado: esta ficha recibe el siguiente número libre y la otra
	 * conserva el suyo. Nunca a quien entra en la web con ese número.
	 *
	 * @return string|\WP_Error El número nuevo.
	 */
	public function resolver_duplicado( Persona $p ) {
		$actual = $this->de( $p );
		if ( self::DUPLICADO !== $actual['estado'] ) {
			return new \WP_Error( 'asde_num_socio', 'El número de esta ficha no está duplicado.' );
		}
		if ( $this->usa_numero_en_la_web( $p, (string) $actual['numero'] ) ) {
			return new \WP_Error( 'asde_num_socio', 'Esta persona (o su familia) entra en la web con ese número: dale el número nuevo a la otra ficha.' );
		}
		if ( ! Escritura::disponible() ) {
			return new \WP_Error( 'asde_num_socio', 'Para resolver un duplicado hace falta que el usuario del CRM pueda editar fichas.' );
		}
		if ( SociosTable::local_de( $p->num_socio_asde ) === $actual['numero'] ) {
			SociosTable::liberar( $p->num_socio_asde ); // Reserva antigua con el número repetido.
		}
		$numero = SociosTable::asignar_siguiente( $p->num_socio_asde, $this->grupo, $this->numeros_crm );
		if ( is_wp_error( $numero ) ) {
			return $numero;
		}
		$r = $this->escribir_en_crm( $p, $numero );
		if ( is_wp_error( $r ) ) {
			SociosTable::liberar( $p->num_socio_asde );
			return $r;
		}
		return $numero;
	}

	/**
	 * Guarda en el CRM todas las fichas con formato antiguo (CORREGIDO) o con
	 * número generado aquí (GENERADO). Lo usa la tarea en segundo plano
	 * (TareaNumeracion), por tandas:
	 *
	 * - con $hasta (microtime), al acabarse el tiempo no se empieza otra ficha:
	 *   lo que falta queda «pendiente» para la tanda siguiente, y ninguna ficha
	 *   queda a medias. Siempre se hace al menos una, para que cada tanda avance;
	 * - $saltar: nº Scouts de España que ya fallaron (no se reintentan en bucle);
	 * - $seguir: si devuelve false (la tarea se detuvo) no se empieza otra ficha.
	 *
	 * @param Persona[] $personas Todas las fichas del grupo (ya analizadas).
	 * @param string[]  $saltar
	 * @return array{resueltos:int, fallidas:array<string,string>, pendientes:int} fallidas: nº Scouts de España => motivo (sin nombres)
	 */
	public function guardar_todos( array $personas, string $estado, ?float $hasta = null, array $saltar = array(), ?callable $seguir = null ): array {
		$res    = array(
			'resueltos'  => 0,
			'fallidas'   => array(),
			'pendientes' => 0,
		);
		$hechas = 0;
		foreach ( $personas as $p ) {
			if ( $estado !== ( $this->analisis[ $p->num_socio_asde ]['estado'] ?? '' ) || in_array( $p->num_socio_asde, $saltar, true ) ) {
				continue;
			}
			if ( $hechas > 0 && ( ( null !== $hasta && microtime( true ) >= $hasta ) || ( $seguir && ! $seguir() ) ) ) {
				++$res['pendientes'];
				continue;
			}
			++$hechas;
			$r = self::CORREGIDO === $estado ? $this->corregir_formato( $p ) : $this->pasar_al_crm( $p );
			if ( is_wp_error( $r ) ) {
				$res['fallidas'][ $p->num_socio_asde ] = $r->get_error_message();
			} else {
				++$res['resueltos'];
			}
		}
		return $res;
	}

	/**
	 * Resuelve todos los duplicados: en cada número repetido lo conserva quien
	 * entra en la web con él o, si no, quien tiene membresía activa o el nº de
	 * Scouts de España más antiguo; el resto recibe números nuevos.
	 * Por tandas, como guardar_todos().
	 *
	 * @param Persona[] $personas Todas las fichas del grupo.
	 * @param string[]  $saltar
	 * @return array{resueltos:int, fallidas:array<string,string>, pendientes:int}
	 */
	public function resolver_duplicados( array $personas, ?float $hasta = null, array $saltar = array(), ?callable $seguir = null ): array {
		$por_numero = array();
		foreach ( $personas as $p ) {
			$r = $this->analisis[ $p->num_socio_asde ] ?? null;
			if ( $r && self::DUPLICADO === $r['estado'] ) {
				$por_numero[ (string) $r['numero'] ][] = $p;
			}
		}
		$res    = array(
			'resueltos'  => 0,
			'fallidas'   => array(),
			'pendientes' => 0,
		);
		$hechas = 0;
		foreach ( $por_numero as $numero => $fichas ) {
			usort(
				$fichas,
				fn( Persona $a, Persona $b ) => array( ! $this->usa_numero_en_la_web( $a, (string) $numero ), ! $a->tiene_membresia_activa(), (int) $a->num_socio_asde )
					<=> array( ! $this->usa_numero_en_la_web( $b, (string) $numero ), ! $b->tiene_membresia_activa(), (int) $b->num_socio_asde )
			);
			$sigue_repetido = false;
			foreach ( array_slice( $fichas, 1 ) as $p ) {
				if ( in_array( $p->num_socio_asde, $saltar, true ) ) {
					$sigue_repetido = true;
					continue;
				}
				if ( $hechas > 0 && ( ( null !== $hasta && microtime( true ) >= $hasta ) || ( $seguir && ! $seguir() ) ) ) {
					++$res['pendientes'];
					$sigue_repetido = true;
					continue;
				}
				++$hechas;
				$r = $this->resolver_duplicado( $p );
				if ( is_wp_error( $r ) ) {
					$res['fallidas'][ $p->num_socio_asde ] = $r->get_error_message();
					$sigue_repetido                        = true;
				} else {
					++$res['resueltos'];
				}
			}
			// Quien conserva el número deja de estar duplicado (si ya no queda nadie más con él).
			if ( ! $sigue_repetido ) {
				$this->analisis[ $fichas[0]->num_socio_asde ]['estado'] = self::OK;
			}
		}
		return $res;
	}

	private function usa_numero_en_la_web( Persona $p, string $numero ): bool {
		$propio = Usuarios::por_socio( $p->num_socio_asde );
		if ( $propio && $propio->user_login === $numero ) {
			return true;
		}
		foreach ( array( 1, 2 ) as $orden ) { // La cuenta de cada tutor/a: T y T2.
			$familiar = get_user_by( 'login', NumeroSocio::usuario_tutor( $numero, $orden ) );
			if ( $familiar && in_array( $p->num_socio_asde, Usuarios::representados( $familiar->ID ), true ) ) {
				return true;
			}
		}
		return false;
	}

	/** Próximo número libre (informativo). */
	public function siguiente(): string {
		if ( ! $this->analisis ) {
			$this->analizar_todo();
		}
		return SociosTable::siguiente( $this->grupo, $this->numeros_crm );
	}
}
