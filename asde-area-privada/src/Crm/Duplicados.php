<?php
namespace AsdeBalears\AreaPrivada\Crm;

use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Domain\Grupo;
use AsdeBalears\AreaPrivada\Domain\Persona;

defined( 'ABSPATH' ) || exit;

/**
 * Comprobación previa a un alta en el CRM (doc 34, fase 1): ¿tiene ya ficha la
 * persona con este documento, en el grupo o en otro grupo u organización?
 *
 * - En el grupo: get_entry_list (el CRM solo devuelve las fichas del grupo), con o
 *   sin membresía activa, comparando el documento normalizado (el CRM lo guarda sin
 *   separadores, pero puede haber fichas antiguas escritas de otra forma).
 * - En otros grupos: get_entries_count, que en el CRM de ASDE cuenta en todo el CRM
 *   federal (doc 04, §7.6) y solo devuelve un número: no se lee nada de esas fichas
 *   (P-78). El CRM no deja saber en cuál (P-84), así que el aviso es genérico.
 * - Canario: si el recuento dejara de ser federal (contara lo mismo que ve el grupo),
 *   no se puede saber si está en otros grupos y se dice así (P-83).
 *
 * Estar en otro grupo no impide el alta (P-71, P-81): una persona puede estar en dos
 * grupos o asociaciones. Lo que no debe pasar es crear otra ficha en el mismo grupo:
 * quien vuelve solo necesita una membresía nueva en la suya (P-79).
 *
 * No guarda el documento en ningún sitio; la auditoría apunta solo el resultado.
 */
final class Duplicados {

	public const DNI  = 'dni';
	public const NIE  = 'nie';
	public const OTRO = 'otro';

	/** Edad desde la que el documento es obligatorio en el alta (decisión del usuario, 2026-10-07). */
	public const EDAD_DOCUMENTO = 14;

	/** Letra de control del DNI y del NIE (resto de dividir entre 23). */
	private const LETRAS = 'TRWAGMYFPDXBNJZSQVHLCKE';

	/** Comprobaciones por persona y hora: el recuento federal no debe servir para recorrer documentos. */
	public const MAX_POR_HORA = 60;

	public function __construct( private SinergiaV41Client $crm ) {}

	public static function make(): ?self {
		$cliente = ClientFactory::reader();
		return $cliente ? new self( $cliente ) : null;
	}

	/**
	 * Documento normalizado y, si es un DNI o un NIE, con la letra comprobada. Un DNI de
	 * siete cifras se completa con un cero delante (como lo escribe el DNI). Otros
	 * documentos (pasaporte, documentos extranjeros) se aceptan tal cual.
	 *
	 * @return array{ok:bool, documento:string, tipo:string, error:string}
	 */
	public static function validar( string $documento ): array {
		$doc = PersonaRepository::normalizar_documento( $documento );
		if ( preg_match( '/^\d{7}[A-Z]$/', $doc ) ) {
			$doc = '0' . $doc;
		}
		$res = static fn( bool $ok, string $tipo, string $error = '' ): array => array(
			'ok'        => $ok,
			'documento' => $doc,
			'tipo'      => $tipo,
			'error'     => $error,
		);
		if ( '' === $doc ) {
			return $res( false, '', asde_t( 'Escribe el número de documento.' ) );
		}
		if ( preg_match( '/^\d{8}[A-Z]$/', $doc ) ) {
			return self::LETRAS[ (int) substr( $doc, 0, 8 ) % 23 ] === $doc[8]
				? $res( true, self::DNI )
				: $res( false, self::DNI, asde_t( 'La letra del DNI no corresponde a sus números. Revísalo.' ) );
		}
		if ( preg_match( '/^[XYZ]\d{7}[A-Z]$/', $doc ) ) {
			return self::LETRAS[ (int) ( strpos( 'XYZ', $doc[0] ) . substr( $doc, 1, 7 ) ) % 23 ] === $doc[8]
				? $res( true, self::NIE )
				: $res( false, self::NIE, asde_t( 'La letra del NIE no corresponde a sus números. Revísalo.' ) );
		}
		if ( preg_match( '/^\d{7,8}$/', $doc ) ) {
			return $res( false, self::DNI, asde_t( 'Al DNI le falta la letra.' ) );
		}
		if ( strlen( $doc ) < 5 || strlen( $doc ) > 20 ) {
			return $res( false, self::OTRO, asde_t( 'El número de documento debe tener entre 5 y 20 letras o cifras.' ) );
		}
		return $res( true, self::OTRO );
	}

	/** ¿Hay que pedir el documento a alguien nacido en esta fecha (AAAA-MM-DD)? A partir de los 14 años, sí. */
	public static function documento_obligatorio( string $nacimiento ): bool {
		$fecha = $nacimiento ? date_create( $nacimiento, wp_timezone() ) : false;
		return ! $fecha || date_diff( $fecha, date_create( 'today', wp_timezone() ) )->y >= self::EDAD_DOCUMENTO;
	}

	/**
	 * Comprueba un documento.
	 *
	 * @return array{ok:bool, error:string, documento:string, tipo:string, en_grupo:list<array{persona:Persona, activa:bool}>, otros:?int}
	 *         `otros`: fichas con ese documento en otros grupos u organizaciones (null: no se ha podido comprobar).
	 * @throws CrmException Si el CRM no contesta.
	 */
	public function comprobar( string $documento ): array {
		$v = self::validar( $documento );
		$r = $v + array(
			'en_grupo' => array(),
			'otros'    => null,
		);
		if ( ! $v['ok'] ) {
			return $r;
		}
		$doc      = $v['documento'];
		$formas   = self::formas( $doc, $v['tipo'] );
		$campo    = 'contacts_cstm.stic_identification_number_c';
		$digitos  = (string) preg_replace( '/\D/', '', $doc );
		$in       = implode( ', ', array_map( array( Query::class, 'str' ), $formas ) );
		$where    = strlen( $digitos ) >= 5 ? "$campo LIKE " . Query::contains( ltrim( $digitos, '0' ) ?: $digitos ) : "$campo IN ($in)";
		$entradas = PersonaRepository::filtrar_grupo( $this->crm->list_entries( 'Contacts', $where, array_merge( PersonaRepository::CAMPOS, array( 'stic_identification_number_c' ) ), PersonaRepository::links_grupo(), 0, 50 )['entries'] );

		$contadas = 0; // Las del grupo que el recuento federal también cuenta (para no contarlas como «otros»).
		foreach ( $entradas as $e ) {
			$guardado = (string) ( $e['fields']['stic_identification_number_c'] ?? '' );
			if ( self::validar( $guardado )['documento'] !== $doc ) {
				continue;
			}
			$p                = Persona::from_crm( $e );
			$r['en_grupo'][] = array(
				'persona' => $p,
				'activa'  => $p->tiene_membresia_activa(),
			);
			if ( in_array( strtoupper( trim( $guardado ) ), $formas, true ) ) {
				++$contadas;
			}
		}
		if ( $this->recuento_federal() ) {
			$r['otros'] = max( 0, $this->crm->count( 'Contacts', "$campo IN ($in)" ) - $contadas );
		}
		Logger::log(
			'crm_documento_comprobado',
			'persona',
			'',
			array(
				'en_grupo' => count( $r['en_grupo'] ),
				'otros'    => $r['otros'],
			)
		);
		return $r;
	}

	/**
	 * Formas en las que puede estar escrito el documento en el CRM (la comparación del
	 * CRM no distingue mayúsculas). Para el recuento federal, que no deja comparar en
	 * memoria: se cuentan las fichas escritas de alguna de estas formas.
	 *
	 * @return string[]
	 */
	public static function formas( string $doc, string $tipo ): array {
		$formas = array( $doc );
		if ( self::DNI === $tipo ) {
			foreach ( array_unique( array( substr( $doc, 0, 8 ), ltrim( substr( $doc, 0, 8 ), '0' ) ) ) as $num ) {
				array_push( $formas, $num . $doc[8], $num . '-' . $doc[8], $num . ' ' . $doc[8] );
			}
		} elseif ( self::NIE === $tipo ) {
			$num = substr( $doc, 1, 7 );
			array_push( $formas, $doc[0] . '-' . $num . '-' . $doc[8], $doc[0] . $num . '-' . $doc[8], $doc[0] . '-' . $num . $doc[8], $doc[0] . ' ' . $num . ' ' . $doc[8] );
		}
		return array_values( array_unique( $formas ) );
	}

	/**
	 * Fichas del grupo que comparten el mismo documento (doc 34, §3.7; informe de wp-admin):
	 * duplicados que ya hubiera de antes, para unificarlos en el CRM. Solo lectura; lee las
	 * fichas del grupo con documento y compara el documento normalizado en memoria.
	 *
	 * @return list<array{documento:string, fichas:list<array{nombre:string, socio:string, activa:bool}>}>
	 * @throws CrmException
	 */
	public function repetidos(): array {
		$campo    = 'contacts_cstm.stic_identification_number_c';
		$entradas = PersonaRepository::filtrar_grupo(
			$this->crm->list_all( 'Contacts', "$campo IS NOT NULL AND $campo <> ''", array_merge( PersonaRepository::CAMPOS, array( 'stic_identification_number_c' ) ), PersonaRepository::links_grupo() )
		);
		$por_doc  = array();
		foreach ( $entradas as $e ) {
			$crudo = (string) ( $e['fields']['stic_identification_number_c'] ?? '' );
			$v     = self::validar( $crudo );
			$doc   = $v['ok'] ? $v['documento'] : PersonaRepository::normalizar_documento( $crudo );
			if ( '' === $doc ) {
				continue;
			}
			$p                = Persona::from_crm( $e );
			$por_doc[ $doc ][] = array(
				'nombre' => $p->nombre_completo(),
				'socio'  => $p->num_socio_asde,
				'activa' => $p->tiene_membresia_activa(),
			);
		}
		$out = array();
		foreach ( $por_doc as $doc => $fichas ) {
			if ( count( $fichas ) > 1 ) {
				$out[] = array(
					'documento' => (string) $doc,
					'fichas'    => $fichas,
				);
			}
		}
		Logger::log( 'crm_documentos_repetidos', 'grupo', Grupo::numero(), array( 'fichas' => count( $entradas ), 'repetidos' => count( $out ) ) );
		return $out;
	}

	/**
	 * Canario: ¿sigue contando get_entries_count en todo el CRM? Se compara lo que
	 * cuenta sin condición con lo que ve el grupo. Se guarda un rato para no repetirlo
	 * en cada comprobación (más tiempo si está bien que si está mal).
	 */
	private function recuento_federal(): bool {
		$clave = 'asde_ap_recuento_federal_' . Grupo::numero();
		$antes = get_transient( $clave );
		if ( false !== $antes ) {
			return '1' === $antes;
		}
		$federal = $this->crm->count( 'Contacts', '' );
		$grupo   = (int) $this->crm->list_entries( 'Contacts', '', array( 'id' ), array(), 0, 1 )['total'];
		$ok      = $federal > $grupo;
		set_transient( $clave, $ok ? '1' : '0', $ok ? 12 * HOUR_IN_SECONDS : 15 * MINUTE_IN_SECONDS );
		if ( ! $ok ) {
			Logger::log(
				'crm_recuento_no_federal',
				'grupo',
				Grupo::numero(),
				array(
					'cuenta' => $federal,
					'grupo'  => $grupo,
				)
			);
		}
		return $ok;
	}
}
