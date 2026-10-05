<?php
namespace AsdeBalears\AreaPrivada\Idiomas;

defined( 'ABSPATH' ) || exit;

/**
 * Textos que escriben los JavaScript del área privada. El servidor los traduce y los entrega en
 * window.asdeI18n; el JS los pide con asdeT( 'texto', arg… ), que rellena los marcadores %s por orden.
 * Si añades un texto a un JS, añádelo aquí también (el coverage de idiomas lo comprueba).
 */
final class TextosJs {

	public const LISTA = array(
		'Copiado',
		'Cargando la página',
		'Cargando…',
		'Abrir en su página',
		'Abrir en otra pestaña',
		'Cerrar',
		'Mostrar cabecera',
		'Ocultar cabecera',
		'Escribe al menos %s letras…',
		'Escribe al menos %s letras.',
		'Nadie coincide con «%s»',
		'Elige una opción de la lista.',
		'Se detendrá la corrección. Lo ya corregido se queda corregido. ¿Detener?',
		'%s de %s',
		'usuario',
		'usuarios',
		'Ordenar por %s (%s)',
		'descendente',
		'ascendente',
		'Paginación',
		'Mostrando %s–%s de %s',
		'Primera página',
		'Página anterior',
		'Página siguiente',
		'Última página',
		'Página %s',
		'Mostrar',
		'por página',
		'Actualizando…',
		'No se ha podido actualizar la lista. Recarga la página.',
		'No se ha podido completar la acción. Recarga la página para ver cómo ha quedado.',
		// Inventario (v0.19, inventario.js).
		'1 material marcado',
		'%s materiales marcados',
		'No queda ninguna unidad de «%s» en el inventario. ¿Seguro que quieres añadirlo a la lista de compra?',
		'En el inventario solo hay 1 unidad de «%s». ¿Seguro que quieres añadirlo a la lista de compra?',
		'En el inventario solo hay %s unidades de «%s». ¿Seguro que quieres añadirlo a la lista de compra?',
		'Marca el material que hay que comprar.',
		'+ sin precio',
	);

	/** Texto en castellano => texto en el idioma actual. */
	public static function diccionario(): array {
		$out = array();
		foreach ( self::LISTA as $es ) {
			$out[ $es ] = Traducciones::t( $es );
		}
		return $out;
	}

	/** Código JS que define asdeI18n y asdeT() (va antes de los scripts del área privada). */
	public static function script(): string {
		return 'window.asdeI18n=' . wp_json_encode( self::diccionario(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP )
			. ';window.asdeT=function(s){var t=window.asdeI18n&&window.asdeI18n[s]?window.asdeI18n[s]:s,i=1,a=arguments;'
			. 'return t.replace(/%s/g,function(){return i<a.length?a[i++]:"";});};';
	}
}
