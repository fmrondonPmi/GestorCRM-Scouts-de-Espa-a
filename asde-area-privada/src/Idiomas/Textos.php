<?php
namespace AsdeBalears\AreaPrivada\Idiomas;

use AsdeBalears\AreaPrivada\Domain\Organizacion;
use AsdeBalears\AreaPrivada\Fichas\Cambios;
use AsdeBalears\AreaPrivada\Fichas\CamposFicha;
use AsdeBalears\AreaPrivada\Frontend\Views\AccesoView;
use AsdeBalears\AreaPrivada\Frontend\Views\PersonasView;
use AsdeBalears\AreaPrivada\Frontend\Views\TesoreriaView;
use AsdeBalears\AreaPrivada\Informes\Catalogo;
use AsdeBalears\AreaPrivada\Informes\Informe;
use AsdeBalears\AreaPrivada\Informes\InformesTable;
use AsdeBalears\AreaPrivada\Install\Roles;
use AsdeBalears\AreaPrivada\Sepa\Remesas;
use AsdeBalears\AreaPrivada\Socios\Numeracion;
use AsdeBalears\AreaPrivada\Socios\Numeros;
use AsdeBalears\AreaPrivada\Socios\TareaNumeracion;
use AsdeBalears\AreaPrivada\Tesoreria\Banco\BancoTable;
use AsdeBalears\AreaPrivada\Tesoreria\Banco\Codificacion;
use AsdeBalears\AreaPrivada\Tesoreria\Banco\CsvBanco;
use AsdeBalears\AreaPrivada\Tesoreria\Banco\Norma43;
use AsdeBalears\AreaPrivada\Tesoreria\Calculadora;
use AsdeBalears\AreaPrivada\Tesoreria\MovimientosTable;
use AsdeBalears\AreaPrivada\Uniformes\PedidosTable;
use AsdeBalears\AreaPrivada\Usuarios\RegistroEmails;
use AsdeBalears\AreaPrivada\Usuarios\Solicitudes;

defined( 'ABSPATH' ) || exit;

/**
 * Textos que el código tiene en constantes (una constante no puede llamar a asde_t()) y que se
 * traducen al mostrarlos: sus valores son claves de traducción igual que los literales de
 * asde_t(). El generador de languages/es.php los suma a los que encuentra el Extractor.
 */
final class Textos {

	/**
	 * Texto en castellano => grupo de pantallas.
	 *
	 * @return array<string, string>
	 */
	public static function extras(): array {
		$out = array();
		$add = static function ( array $lista, string $grupo ) use ( &$out ): void {
			array_walk_recursive(
				$lista,
				static function ( $valor ) use ( &$out, $grupo ): void {
					if ( is_string( $valor ) && '' !== trim( $valor ) ) {
						$out[ $valor ] ??= $grupo;
					}
				}
			);
		};

		$add( Cambios::ESTADOS, 'Fichas' );
		$add( CamposFicha::GRUPOS, 'Fichas' );
		$add( CamposFicha::SOLO_LECTURA, 'Fichas' );
		$add( CamposFicha::OPCIONES, 'Fichas' );
		$add( PersonasView::FILTROS, 'Personas' );
		$add( TesoreriaView::FILTROS, 'Tesorería' );
		$add( PedidosTable::METODOS, 'Uniformes' );
		$add( PedidosTable::FILTROS, 'Uniformes' );
		$add( PedidosTable::ESTADOS, 'Uniformes' );
		$add( \AsdeBalears\AreaPrivada\Inventario\ComprasTable::ESTADOS, 'Inventario' );
		$add( array_column( \AsdeBalears\AreaPrivada\Inventario\Importador::COLUMNAS, 0 ), 'Inventario' );
		$add( \AsdeBalears\AreaPrivada\Tesoreria\Banco\TiposMovimiento::NATURALEZAS, 'Tesorería' );
		$add( array_column( \AsdeBalears\AreaPrivada\Tesoreria\Banco\TiposMovimiento::INICIALES, 0 ), 'Tesorería' );
		$add( Remesas::ESTADOS, 'Domiciliaciones SEPA' );
		$add( BancoTable::ESTADOS, 'Tesorería' );
		$add( CsvBanco::CAMPOS, 'Tesorería' );
		$add( Codificacion::LISTA, 'Tesorería' );
		$add( Norma43::CONCEPTOS, 'Tesorería' );
		$add( Calculadora::ETIQUETAS, 'Tesorería' );
		$add( MovimientosTable::METODOS, 'Tesorería' );
		$add( Organizacion::APLICA, 'Tesorería' );
		$add( Solicitudes::ESTADOS, 'Solicitudes de acceso' );
		$add( RegistroEmails::ESTADOS, 'Usuarios' );
		$add( RegistroEmails::TIPOS, 'Usuarios' );
		$add( Informe::ORDENES, 'Informes' );
		$add( array_map( static fn( array $p ) => array( $p[0], $p[1] ), InformesTable::predefinidos() ), 'Informes' );
		$add( Catalogo::GRUPOS, 'Informes' );
		$add( Catalogo::ENUMS, 'Informes' );
		$add( Numeros::CORTO, 'Números de socio' );
		$add( Numeracion::ETIQUETAS, 'Números de socio' );
		$add( TareaNumeracion::ACCIONES, 'Números de socio' );
		$add( TextosJs::LISTA, 'Pantallas con JavaScript' );
		$add( array( AccesoView::MENSAJE_CREDENCIALES ), 'Acceso' );
		$add( array_map( static fn( array $d ) => $d[0], Roles::definitions() ), 'Menú y avisos' );
		return $out;
	}

	/**
	 * Todos los textos traducibles del plugin: los de las llamadas a asde_t() y compañía, más los de las
	 * constantes (clave en castellano => grupo).
	 *
	 * @return array<string, string>
	 */
	public static function todos( string $raiz ): array {
		$out = Extractor::textos( $raiz );
		foreach ( self::extras() as $texto => $grupo ) {
			$out[ $texto ] ??= $grupo;
		}
		ksort( $out, SORT_STRING );
		return $out;
	}
}
