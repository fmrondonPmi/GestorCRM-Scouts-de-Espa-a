<?php
namespace AsdeBalears\AreaPrivada\Idiomas;

defined( 'ABSPATH' ) || exit;

/**
 * Textos que escriben los JavaScript del área privada. El servidor los traduce con gettext y los entrega en
 * window.asdeI18n (texto en inglés => texto en el idioma de WordPress); el JS los pide con asdeT( 'texto', arg… ),
 * que rellena los marcadores %s (o %1$s, %2$s…). Si añades un texto a un JS, añádelo aquí también.
 */
final class TextosJs {

	/** @return array<string, string> texto en inglés => texto en el idioma actual. */
	public static function diccionario(): array {
		return array(
			'Copied' => __( 'Copied', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
			'Loading the page' => __( 'Loading the page', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
			'Loading…' => __( 'Loading…', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
			'Open on its page' => __( 'Open on its page', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
			'Open in another tab' => __( 'Open in another tab', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
			'Close' => __( 'Close', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
			'Show header' => __( 'Show header', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
			'Hide header' => __( 'Hide header', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
			'Enter at least %s letters…' => /* translators: %s: text */ __( 'Enter at least %s letters…', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
			'Enter at least %s letters.' => /* translators: %s: text */ __( 'Enter at least %s letters.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
			'Nobody matches "%s"' => /* translators: %s: text */ __( 'Nobody matches "%s"', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
			'Choose an option from the list.' => __( 'Choose an option from the list.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
			'The correction will be stopped. What has already been corrected stays corrected. Stop?' => __( 'The correction will be stopped. What has already been corrected stays corrected. Stop?', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
			'%1$s of %2$s' => /* translators: 1: text, 2: text */ __( '%1$s of %2$s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
			'user' => __( 'user', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
			'users' => __( 'users', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
			'Sort by %1$s (%2$s)' => /* translators: 1: text, 2: text */ __( 'Sort by %1$s (%2$s)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
			'descending' => __( 'descending', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
			'ascending' => __( 'ascending', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
			'Pagination' => __( 'Pagination', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
			'Showing %1$s–%2$s of %3$s' => /* translators: 1: text, 2: text, 3: text */ __( 'Showing %1$s–%2$s of %3$s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
			'First page' => __( 'First page', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
			'Previous page' => __( 'Previous page', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
			'Next page' => __( 'Next page', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
			'Last page' => __( 'Last page', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
			'Page %s' => /* translators: %s: text */ __( 'Page %s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
			'Show' => __( 'Show', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
			'per page' => __( 'per page', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
			'Updating…' => __( 'Updating…', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
			'The list could not be updated. Reload the page.' => __( 'The list could not be updated. Reload the page.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
			'The action could not be completed. Reload the page to see how it has ended up.' => __( 'The action could not be completed. Reload the page to see how it has ended up.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
			'1 item of equipment ticked' => __( '1 item of equipment ticked', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
			'%s items of equipment ticked' => /* translators: %s: text */ __( '%s items of equipment ticked', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
			'There is already 1 unit of "%s" in the inventory. Do more really need to be bought?' => /* translators: %s: text */ __( 'There is already 1 unit of "%s" in the inventory. Do more really need to be bought?', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
			'There are already %1$s units of "%2$s" in the inventory. Do more really need to be bought?' => /* translators: 1: text, 2: text */ __( 'There are already %1$s units of "%2$s" in the inventory. Do more really need to be bought?', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
			'You have not ticked any equipment from the inventory. Prepare a list with new equipment only?' => __( 'You have not ticked any equipment from the inventory. Prepare a list with new equipment only?', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
			'+ no price' => __( '+ no price', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
			'Collapse or expand "%s"' => /* translators: %s: text */ __( 'Collapse or expand "%s"', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
			'Print' => __( 'Print', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
			'Print version' => __( 'Print version', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
			'1 transaction ticked' => __( '1 transaction ticked', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
			'%s transactions ticked' => /* translators: %s: text */ __( '%s transactions ticked', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
			'Tick in the list the transactions that are not fees.' => __( 'Tick in the list the transactions that are not fees.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
			'The type could not be created. Try again.' => __( 'The type could not be created. Try again.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
		);
	}

	/**
	 * Código JS que define asdeI18n, asdeT() y asdeEsc() (va antes de los scripts del área privada).
	 * Dentro de un atributo HTML montado en una cadena, el texto se escribe con asdeEsc( asdeT( '…' ) )
	 * (revisión de seguridad v0.33.1; tests/estilo-wordpress.php lo vigila).
	 */
	public static function script(): string {
		return 'window.asdeI18n=' . wp_json_encode( self::diccionario(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP )
			. ';window.asdeT=function(s){var t=window.asdeI18n&&window.asdeI18n[s]?window.asdeI18n[s]:s,i=0,a=arguments;'
			. 'return t.replace(/%(?:(\d+)\$)?s/g,function(m,n){var k=n?parseInt(n,10):++i;return k<a.length?a[k]:"";});};'
			. 'window.asdeEsc=function(s){return String(s).replace(/[&<>"\']/g,function(c){return"&#"+c.charCodeAt(0)+";";});};';
	}
}
