<?php
namespace AsdeBalears\AreaPrivada\Socios;

use AsdeBalears\AreaPrivada\Crm\CrmException;
use AsdeBalears\AreaPrivada\Crm\PersonaRepository;
use AsdeBalears\AreaPrivada\Domain\Identidad;
use AsdeBalears\AreaPrivada\Domain\Persona;

defined( 'ABSPATH' ) || exit;

/**
 * Cómo se ven los números de socio/a de una persona en las pantallas (v0.14, doc 27).
 *
 * - El nº de Scouts de España o el local, según cuál sea el usuario de acceso
 *   (Domain\Identidad), va en negrita.
 * - El nº local/territorial se vigila SIEMPRE (Numeracion): sea o no el usuario.
 * - Con el nº de Scouts de España como usuario, el nº territorial solo se ve si la
 *   persona lo ha pedido («Mostrar nº territorial», en la cabecera del área). Con el
 *   nº local como usuario se ve siempre.
 * - La ADVERTENCIA de un problema del nº territorial (formato antiguo, duplicado, no
 *   válido, sin número o pendiente de pasar al CRM) sale siempre, se vea o no el número,
 *   para quien tiene membresía activa.
 */
final class Numeros {

	public const META_VER = 'asde_ver_numero_local';

	/** Texto corto de cada incidencia, para la advertencia. */
	public const CORTO = array(
		Numeracion::CORREGIDO => 'formato antiguo',
		Numeracion::GENERADO  => 'pendiente de pasar al CRM',
		Numeracion::DUPLICADO => 'duplicado',
		Numeracion::INVALIDO  => 'no válido',
		Numeracion::FALTA     => 'sin número',
	);

	private static ?Numeracion $numeracion = null;
	private static ?PersonaRepository $repo = null;

	/** ¿Ve esta persona el nº territorial en las pantallas? (con el nº local como usuario, siempre) */
	public static function ver_local(): bool {
		if ( ! Identidad::es_asde() ) {
			return true;
		}
		$uid = get_current_user_id();
		return $uid > 0 && '1' === (string) get_user_meta( $uid, self::META_VER, true );
	}

	public static function fijar_ver_local( bool $si ): void {
		$uid = get_current_user_id();
		if ( $uid > 0 ) {
			update_user_meta( $uid, self::META_VER, $si ? '1' : '0' );
		}
	}

	/** Para empezar de cero en la petición (pruebas). */
	public static function olvidar(): void {
		self::$numeracion = null;
		self::$repo       = null;
	}

	/**
	 * Nº local de una persona y su estado (el análisis de todo el grupo, que se
	 * guarda unos minutos). Si el CRM no responde, lo que haya en la tabla de equivalencias.
	 *
	 * @return array{numero:?string, estado:string}
	 */
	public static function de( Persona $p ): array {
		try {
			if ( null === self::$numeracion ) {
				$repo             = PersonaRepository::make();
				self::$numeracion = $repo ? new Numeracion( $repo ) : null;
			}
			if ( self::$numeracion ) {
				return self::$numeracion->de( $p );
			}
		} catch ( CrmException $e ) {
			unset( $e );
		}
		$local = SociosTable::local_de( $p->num_socio_asde );
		return array(
			'numero' => $local,
			'estado' => null === $local ? Numeracion::FALTA : Numeracion::OK,
		);
	}

	/** Quien gestiona (usuarios, Tesorería, informes, cambios) ve las advertencias y puede mostrar el nº territorial. */
	public static function es_gestion(): bool {
		return current_user_can( 'asde_manage_users' ) || current_user_can( 'asde_tesoreria_acceso' ) || current_user_can( 'asde_reports' ) || current_user_can( 'asde_approve_changes' );
	}

	/** Texto de la advertencia del nº territorial de esta persona, o '' si no hay nada que avisar. */
	public static function aviso( Persona $p, ?array $n = null ): string {
		if ( ! $p->tiene_membresia_activa() || ! self::es_gestion() ) {
			return ''; // Las advertencias son para quien gestiona; una familia no tiene nada que corregir.
		}
		$n ??= self::de( $p );
		return Numeracion::OK === $n['estado'] ? '' : ( asde_t( Numeracion::ETIQUETAS[ $n['estado'] ] ?? '' ) );
	}

	/**
	 * Los números de la persona, en HTML ya escapado: el usuario en negrita; el otro
	 * en gris si se ve; y la advertencia del nº territorial si la hay.
	 *
	 * @param bool $compacto Para listados: solo el usuario y, si se ve o hay un problema, el nº territorial.
	 */
	public static function html( Persona $p, ?array $n = null, bool $compacto = false ): string {
		$n     ??= self::de( $p );
		$aviso   = self::aviso( $p, $n );
		$local   = (string) ( $n['numero'] ?? '' );
		$mostrar = self::ver_local() || '' !== $aviso;
		if ( Identidad::es_asde() ) {
			$html = '<strong>' . esc_html( $p->num_socio_asde ) . '</strong>';
			if ( $mostrar ) {
				$html .= ' <span class="asde-muted" title="' . asde_a( 'Nº de socio/a local/territorial' ) . '">' . esc_html( asde_t( 'terr.' ) ) . ' ' . esc_html( '' !== $local ? $local : '—' ) . '</span>';
			}
		} else {
			$html  = '<strong>' . esc_html( '' !== $local ? $local : '—' ) . '</strong>';
			if ( ! $compacto ) {
				$html .= ' <span class="asde-muted" title="' . asde_a( 'Nº de socio/a de Scouts de España' ) . '">ASDE ' . esc_html( $p->num_socio_asde ) . '</span>';
			}
		}
		if ( '' !== $aviso ) {
			$html .= ' <span class="asde-numeros__aviso" title="' . esc_attr( $aviso ) . '">⚠ nº territorial: ' . esc_html( asde_t( self::CORTO[ $n['estado'] ] ?? $aviso ) ) . '</span>';
		}
		return $html;
	}

	/** Lo mismo, a partir del nº de Scouts de España (listados que solo tienen el nº y no la persona). */
	public static function compacto_asde( string $asde ): string {
		if ( ! self::es_gestion() ) {
			return '';
		}
		try {
			self::$repo ??= PersonaRepository::make();
			$p = self::$repo ? self::$repo->obtener( $asde ) : null;
		} catch ( CrmException $e ) {
			return '';
		}
		return $p ? self::compacto( $p ) : '';
	}

	/** Para los listados de gestión (junto al nombre): los números de la persona, o nada si quien mira no gestiona. */
	public static function compacto( Persona $p ): string {
		return self::es_gestion() ? ' <span class="asde-numeros">' . self::html( $p, null, true ) . '</span>' : '';
	}
}
