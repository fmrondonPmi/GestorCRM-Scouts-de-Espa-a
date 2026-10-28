<?php
/**
 * Cargar recetas desde un CSV (v0.41, doc 49, B1).
 *
 * @var array|null $resultado  recetas, ingredientes_nuevos, errores
 * @var string     $ejemplo    CSV de ejemplo, para copiar
 * @var string     $url
 * @var string     $url_lista
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
defined( 'ABSPATH' ) || exit;
?>
<section class="asde-card" data-asde-inicio="abierto">
	<header class="asde-card__header asde-card__header--split">
		<h2 class="asde-card__title"><?php esc_html_e( 'Load recipes from a CSV', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h2>
		<span class="asde-actions"><a href="<?php echo esc_url( $url_lista ); ?>"><?php esc_html_e( 'Back to the recipe book', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a></span>
	</header>
	<p class="asde-muted"><?php esc_html_e( 'One row for each ingredient of each recipe, with the columns: Recipe; Type; Portions; Ingredient; Quantity; Unit. The units are g, kg, ml, l, unit, tbsp and pinch. Ingredients that do not exist are created with no allergens and unreviewed: record them afterwards in "Ingredients". No recipe that already exists is overwritten.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
	<form method="post" action="<?php echo esc_url( $url ); ?>" enctype="multipart/form-data" class="asde-form">
		<?php wp_nonce_field( 'asde_menus' ); ?>
		<input type="hidden" name="asde_action" value="recetas_importar">
		<p>
			<label for="asde-rci-fichero"><?php esc_html_e( 'CSV file', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
			<input type="file" id="asde-rci-fichero" name="fichero" accept=".csv,.txt">
		</p>
		<p><button type="submit" class="button"><?php esc_html_e( 'Load', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button></p>
	</form>
	<h3><?php esc_html_e( 'Example', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h3>
	<pre class="asde-pre"><?php echo esc_html( $ejemplo ); ?></pre>
</section>

<?php if ( $resultado ) : ?>
	<section class="asde-card" data-asde-inicio="abierto">
		<h3 class="asde-card__title"><?php esc_html_e( 'Load result', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h3>
		<p><strong><?php echo esc_html( sprintf( /* translators: %d: number */ _n( '%d recipe loaded.', '%d recipes loaded.', (int) $resultado['recetas'] , 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), (int) $resultado['recetas'] ) ); ?></strong>
			<?php echo esc_html( sprintf( /* translators: %d: number */ _n( '%d new ingredient, to review.', '%d new ingredients, to review.', (int) $resultado['ingredientes_nuevos'] , 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), (int) $resultado['ingredientes_nuevos'] ) ); ?></p>
		<?php if ( $resultado['errores'] ) : ?>
			<h4><?php esc_html_e( 'Not loaded', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h4>
			<ul>
				<?php foreach ( $resultado['errores'] as $e ) : ?>
					<li><?php echo esc_html( sprintf( /* translators: 1: number, 2: text */ __( 'Row %1$d: %2$s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), (int) $e['fila'], $e['motivo'] ) ); ?></li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
	</section>
<?php endif; ?>
