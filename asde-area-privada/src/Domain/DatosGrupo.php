<?php
namespace AsdeBalears\AreaPrivada\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Datos del grupo (v0.56, doc 58): UNA sola fuente para su nombre, denominación fiscal, CIF,
 * dirección, código postal, población, provincia, email de contacto y nº de grupo. La editan
 * Coordinación (asde_grupo_datos) en el área privada, y los leen las demás pantallas
 * (domiciliaciones SEPA, membrete de las listas, solicitar acceso, el nº de socio/a local…).
 *
 * Se guardan en el propio grupo (Domain\Grupos). Los datos que antes se escribían en la
 * configuración de las domiciliaciones SEPA (dirección del acreedor) se aprovechan mientras
 * el grupo no tenga los suyos, para que nada se pierda al unificar.
 */
final class DatosGrupo {

	/** @return array{numero:string, nombre:string, denominacion:string, cif:string, sede:string, cp:string, poblacion:string, provincia:string, provincia_nombre:string, email_contacto:string} */
	public static function de( string $numero ): array {
		$g    = Grupos::por_numero( $numero ) ?? array();
		$sepa = (array) get_option( 'asde_ap_sepa_' . $numero, array() );
		$cual = static function ( ...$valores ): string {
			foreach ( $valores as $v ) {
				if ( '' !== trim( (string) $v ) ) {
					return trim( (string) $v );
				}
			}
			return '';
		};
		$provincia = Grupos::provincia( $numero );
		return array(
			'numero'           => $numero,
			'nombre'           => $cual( $g['nombre'] ?? '', $numero ),
			'denominacion'     => $cual( $g['denominacion'] ?? '' ),
			'cif'              => $cual( $g['cif'] ?? '' ),
			'sede'             => $cual( $g['sede'] ?? '', $sepa['calle'] ?? '' ),
			'cp'               => $cual( $g['cp'] ?? '', $sepa['cp'] ?? '' ),
			'poblacion'        => $cual( $g['poblacion'] ?? '', $sepa['poblacion'] ?? '' ),
			'provincia'        => $provincia,
			'provincia_nombre' => Provincias::nombre( $provincia ),
			'email_contacto'   => $cual( Grupos::email_contacto( $g ) ),
		);
	}

	/** Dirección en una línea: calle, código postal y población, provincia. */
	public static function direccion( string $numero ): string {
		$d      = self::de( $numero );
		$cp_pob = trim( $d['cp'] . ' ' . $d['poblacion'] );
		return implode( ', ', array_filter( array( $d['sede'], $cp_pob, $d['provincia_nombre'] ) ) );
	}

	/** Lo que falta para que el grupo pueda dar nº de socio/a y firmar documentos. @return string[] */
	public static function faltan( string $numero ): array {
		$d      = self::de( $numero );
		$faltan = array();
		if ( '' === $d['provincia'] ) {
			$faltan[] = asde_t( 'la provincia (sin ella no se pueden generar números de socio)' );
		}
		if ( '' === $d['cif'] ) {
			$faltan[] = asde_t( 'el CIF' );
		}
		if ( '' === $d['sede'] || '' === $d['cp'] || '' === $d['poblacion'] ) {
			$faltan[] = asde_t( 'la dirección completa (calle, código postal y población)' );
		}
		return $faltan;
	}
}
