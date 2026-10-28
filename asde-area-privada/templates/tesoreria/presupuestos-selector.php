<?php
/**
 * Selector de presupuesto (v0.55, doc 57): el resumen, cada sección o el grupo, en una línea.
 *
 * @var string   $ronda
 * @var string   $url
 * @var string   $vista
 * @var string[] $secciones Presupuestos que puede abrir
 * @var bool     $es_config
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Presupuestos\Servicio;

defined( 'ABSPATH' ) || exit;

if ( ! $es_config && count( $secciones ) < 2 ) {
	return; // Una sola sección: no hay nada que elegir.
}
?>
<form method="get" class="asde-linea" action="<?php echo esc_url( $url ); ?>">
	<input type="hidden" name="ronda" value="<?php echo esc_attr( $ronda ); ?>">
	<label for="asde-p-vista"><?php esc_html_e( 'View', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
	<select id="asde-p-vista" name="vista" data-asde-autoenviar>
		<?php if ( $es_config ) : ?>
			<option value="resumen" <?php selected( $vista, 'resumen' ); ?>><?php esc_html_e( 'Round summary', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></option>
		<?php endif; ?>
		<?php foreach ( $secciones as $s ) : ?>
			<option value="<?php echo esc_attr( $s ); ?>" <?php selected( $vista, $s ); ?>><?php echo esc_html( sprintf( /* translators: %s: text */ __( 'Budget: %s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), Servicio::nombre( $s ) ) ); ?></option>
		<?php endforeach; ?>
	</select>
	<noscript><button type="submit" class="button"><?php esc_html_e( 'View', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button></noscript>
</form>
