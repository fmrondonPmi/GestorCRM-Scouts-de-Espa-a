<?php
namespace AsdeBalears\AreaPrivada\Idiomas;

use AsdeBalears\AreaPrivada\Domain\Organizacion;
use AsdeBalears\AreaPrivada\Eventos\AnticiposTable;
use AsdeBalears\AreaPrivada\Eventos\Config as EventosConfig;
use AsdeBalears\AreaPrivada\Eventos\EventosTable;
use AsdeBalears\AreaPrivada\Eventos\GastosTable;
use AsdeBalears\AreaPrivada\Eventos\Inscripciones;
use AsdeBalears\AreaPrivada\Eventos\InscripcionesTable;
use AsdeBalears\AreaPrivada\Eventos\MovimientosTable as EventosMovimientos;
use AsdeBalears\AreaPrivada\Eventos\Normativa;
use AsdeBalears\AreaPrivada\Eventos\Presupuesto;
use AsdeBalears\AreaPrivada\Fichas\Cambios;
use AsdeBalears\AreaPrivada\Fichas\CamposFicha;
use AsdeBalears\AreaPrivada\Frontend\Views\AccesoView;
use AsdeBalears\AreaPrivada\Frontend\Views\EventosView;
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
use AsdeBalears\AreaPrivada\Tienda\PedidosTable;
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
		$add( Cambios::MOTIVOS_DIRECTOS, 'Fichas' );
		$add( Cambios::ETIQUETAS_EXTRA, 'Fichas' );
		$add( CamposFicha::GRUPOS, 'Fichas' );
		$add( CamposFicha::SOLO_LECTURA, 'Fichas' );
		$add( CamposFicha::OPCIONES, 'Fichas' );
		$add( PersonasView::FILTROS, 'Personas' );
		$add( \AsdeBalears\AreaPrivada\Crm\Altas::GENEROS, 'Personas' );
		$add( \AsdeBalears\AreaPrivada\Crm\Altas::MOTIVOS_BAJA, 'Personas' );
		$add( TesoreriaView::FILTROS, 'Tesorería' );
		$add( PedidosTable::METODOS, 'Uniformes' );
		$add( PedidosTable::FILTROS, 'Uniformes' );
		$add( PedidosTable::ESTADOS, 'Uniformes' );
		$add( array( PedidosTable::REF_INVENTARIO, PedidosTable::REF_SIN_COSTE, PedidosTable::REF_TIENDA, PedidosTable::REF_GRATIS ), 'Uniformes' );
		$add( PedidosTable::FORMAS, 'Tienda' );
		$add( \AsdeBalears\AreaPrivada\Reintegros\ReintegrosTable::ESTADOS, 'Reintegros' );
		$add( \AsdeBalears\AreaPrivada\Reintegros\ReintegrosTable::FORMAS, 'Reintegros' );
		$add( \AsdeBalears\AreaPrivada\Accesos\Catalogo::textos(), 'Accesos' ); // v0.53 (doc 56).
		$add( \AsdeBalears\AreaPrivada\Accesos\Auditoria::ACCIONES, 'Accesos' );
		$add( \AsdeBalears\AreaPrivada\Tienda\Config::PARA_QUIEN, 'Uniformes' );
		// v0.39 (doc 48, fase 2): el catálogo de la tienda.
		$add( \AsdeBalears\AreaPrivada\Tienda\ProductosTable::VISIBILIDADES, 'Tienda' );
		$add( array_column( \AsdeBalears\AreaPrivada\Tienda\ImportadorProductos::COLUMNAS, 0 ), 'Tienda' );
		$add( \AsdeBalears\AreaPrivada\Inventario\ComprasTable::ESTADOS, 'Inventario' );
		$add( \AsdeBalears\AreaPrivada\ListaEspera\Inscripciones::ESTADOS, 'Lista de espera' );
		$add( \AsdeBalears\AreaPrivada\ListaEspera\Inscripciones::MEDIOS, 'Lista de espera' );
		$add( \AsdeBalears\AreaPrivada\ListaEspera\CamposExtra::TIPOS, 'Lista de espera' );
		$add( array_column( \AsdeBalears\AreaPrivada\Inventario\Importador::COLUMNAS, 0 ), 'Inventario' );
		$add( \AsdeBalears\AreaPrivada\Tesoreria\Banco\TiposMovimiento::NATURALEZAS, 'Tesorería' );
		$add( array_map( static fn( array $m ) => array( $m[0], $m[1] ), \AsdeBalears\AreaPrivada\Domain\Modulos::LISTA ), 'Datos del grupo' );
		$add( array_column( \AsdeBalears\AreaPrivada\Tesoreria\Banco\TiposMovimiento::INICIALES, 0 ), 'Tesorería' );
		// Doc 59, RC-TES-4: valores por defecto de la configuración de Tesorería (se guardan en castellano).
		$add( array( \AsdeBalears\AreaPrivada\Tesoreria\Config::PLAZO_DEFECTO ), 'Tesorería' );
		$add( \AsdeBalears\AreaPrivada\Tesoreria\Config::PROYECTOS_DEFECTO, 'Tesorería' );
		$add( \AsdeBalears\AreaPrivada\Tesoreria\Config::CATEGORIAS_DEFECTO, 'Tesorería' );
		$add( array( \AsdeBalears\AreaPrivada\Eventos\Cuentas::MOTIVO_RETIRADO ), 'Eventos' ); // Doc 59, RC-EVE-3.
		$add( Remesas::ESTADOS, 'Domiciliaciones SEPA' );
		// v0.35: eventos (de los tipos, solo el nombre: lo demás son claves internas).
		$add( array_column( EventosConfig::TIPOS, 'nombre' ), 'Eventos' );
		$add( EventosTable::ESTADOS, 'Eventos' );
		$add( EventosTable::AMBITOS, 'Eventos' );
		$add( EventosTable::FILTROS, 'Eventos' );
		$add( InscripcionesTable::PAPELES, 'Eventos' );
		$add( Presupuesto::REPARTOS, 'Eventos' );
		$add( Normativa::COMUNICACION, 'Eventos' );
		$add( EventosView::PESTANAS, 'Eventos' );
		$add( array_merge( \AsdeBalears\AreaPrivada\Menus\Alergenos::LISTA, \AsdeBalears\AreaPrivada\Menus\RecetasTable::TIPOS, array_column( \AsdeBalears\AreaPrivada\Menus\Escalado::UNIDADES, 0 ), \AsdeBalears\AreaPrivada\Menus\Biblioteca::textos(), \AsdeBalears\AreaPrivada\Menus\MenuTable::COMIDAS, array( \AsdeBalears\AreaPrivada\Menus\MenuEvento::CONCEPTO_PARTIDA ), \AsdeBalears\AreaPrivada\Menus\SelectorAlergenos::textos(), \AsdeBalears\AreaPrivada\Menus\AvisosTable::ALTERNATIVAS ), 'Menús' ); // v0.41: recetario.
		$add( \AsdeBalears\AreaPrivada\Eventos\Plantillas::textos(), 'Eventos' ); // v0.40: la plantilla de Acampada.
		// v0.35: inscripciones y cobros (y el texto de la autorización del plugin).
		$add( EventosTable::MODALIDADES, 'Eventos' );
		$add( InscripcionesTable::ESTADOS, 'Eventos' );
		$add( InscripcionesTable::AUTORIZACIONES, 'Eventos' );
		$add( InscripcionesTable::COMO, 'Eventos' );
		$add( InscripcionesTable::TRANSPORTES, 'Eventos' );
		$add( EventosMovimientos::FORMAS, 'Eventos' );
		$add( array( EventosConfig::AUTORIZACION, Inscripciones::MOTIVO_CADUCADA ), 'Eventos' );
		// v0.36: cuentas de los eventos.
		$add( GastosTable::ESTADOS, 'Eventos' );
		$add( GastosTable::PAGADORES, 'Eventos' );
		$add( GastosTable::FORMAS, 'Eventos' );
		$add( AnticiposTable::ESTADOS, 'Eventos' );
		$add( BancoTable::ESTADOS, 'Tesorería' );
		$add( CsvBanco::CAMPOS, 'Tesorería' );
		$add( Codificacion::LISTA, 'Tesorería' );
		$add( Norma43::CONCEPTOS, 'Tesorería' );
		$add( Calculadora::ETIQUETAS, 'Tesorería' );
		$add( \AsdeBalears\AreaPrivada\Presupuestos\EstadosTable::ETIQUETAS, 'Tesorería' ); // v0.55: estados de los presupuestos.
		$add( MovimientosTable::METODOS, 'Tesorería' );
		$add( Organizacion::APLICA, 'Tesorería' );
		$add( Solicitudes::ESTADOS, 'Solicitudes de acceso' );
		$add( RegistroEmails::ESTADOS, 'Usuarios' );
		$add( RegistroEmails::TIPOS, 'Usuarios' );
		$add( Informe::ORDENES, 'Informes' );
		$add( Informe::VISTAS, 'Informes' );
		$add( array_map( static fn( array $p ) => array( $p[0], $p[1] ), InformesTable::predefinidos() ), 'Informes' );
		$add( Catalogo::GRUPOS, 'Informes' );
		$add( Catalogo::FILTROS_PAGO, 'Informes' );
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
