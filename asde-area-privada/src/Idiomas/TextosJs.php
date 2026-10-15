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
		// v0.30 (P-62): se pregunta cuando ya hay bastantes; y una lista puede ser solo de material nuevo (P-63).
		'Ya hay 1 unidad de «%s» en el inventario. ¿Seguro que hace falta comprar más?',
		'Ya hay %s unidades de «%s» en el inventario. ¿Seguro que hace falta comprar más?',
		'No has marcado ningún material del inventario. ¿Preparar una lista solo con material nuevo?',
		'+ sin precio',
		// v0.29: cuadros plegables (area.js) e imprimir un informe (informes.js).
		'Contraer o expandir «%s»',
		'Imprimir',
		'Versión para imprimir',
		// Banco (v0.20, banco.js).
		'1 movimiento marcado',
		'%s movimientos marcados',
		'Marca en la lista los movimientos que no son cuotas.',
	);

	/** Texto en castellano => texto en el idioma actual. */
	public static function diccionario(): array {
		$out = array();
		foreach ( self::LISTA as $es ) {
			$out[ $es ] = Traducciones::t( $es );
		}
		return $out;
	}

	/**
	 * Código JS que define asdeI18n, asdeT() y asdeEsc() (va antes de los scripts del área privada).
	 * Un texto de asdeT() puede venir de las etiquetas personalizadas (wp-admin → Idioma y etiquetas,
	 * limpias con wp_kses pero con comillas): dentro de un atributo HTML se escribe con
	 * asdeEsc( asdeT( '…' ) ) (v0.33.1, revisión de seguridad; tests/estilo-wordpress.php lo vigila).
	 */
	public static function script(): string {
		return 'window.asdeI18n=' . wp_json_encode( self::diccionario(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP )
			. ';window.asdeT=function(s){var t=window.asdeI18n&&window.asdeI18n[s]?window.asdeI18n[s]:s,i=1,a=arguments;'
			. 'return t.replace(/%s/g,function(){return i<a.length?a[i++]:"";});};'
			. 'window.asdeEsc=function(s){return String(s).replace(/[&<>"\']/g,function(c){return"&#"+c.charCodeAt(0)+";";});};';
	}
}
