<?php
/**
 * Recetario del grupo (v0.41, doc 49, B1): las recetas del grupo y la biblioteca común de ejemplo.
 *
 * @var array[]              $propias
 * @var array[]              $comunes
 * @var array<int, array>    $alergenos  receta => Recetario::alergenos()
 * @var string               $tipo
 * @var string               $q
 * @var bool                 $archivadas
 * @var string               $url
 * @var string               $url_nueva
 * @var string               $url_ing
 * @var string               $url_csv
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Frontend\Pages;
use AsdeBalears\AreaPrivada\Menus\Alergenos;
use AsdeBalears\AreaPrivada\Menus\RecetasTable;

defined( 'ABSPATH' ) || exit;

/** Una fila de receta. */
$fila = static function ( array $r, bool $propia ) use ( $alergenos ): void {
	$a = $alergenos[ $r['id'] ] ?? array( 'contiene' => array(), 'trazas' => array(), 'sin_revisar' => array() );
	?>
	<tr<?php echo $r['activa'] ? '' : ' class="asde-fila--cancelada"'; ?>>
		<td data-label="<?php echo esc_attr( esc_attr__( 'Recipe', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
			<a href="<?php echo esc_url( Pages::url( 'eventos_recetas', array( 'receta' => (string) $r['id'] ) ) ); ?>"><?php echo esc_html( $propia ? $r['nombre'] : asde_guardado( $r['nombre'] ) ); ?></a>
			<?php echo wp_kses( $r['activa'] ? '' : ' <span class="asde-badge">' . esc_html( __( 'Archived', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) . '</span>', asde_html_permitido() ); ?>
		</td>
		<td data-label="<?php echo esc_attr( esc_attr__( 'Type', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo esc_html( ( RecetasTable::tipos()[ $r['tipo'] ] ?? 'Otro' ) ); ?></td>
		<td data-label="<?php echo esc_attr( esc_attr__( 'Base portions', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo (int) $r['porciones']; ?></td>
		<td data-label="<?php echo esc_attr( esc_attr__( 'Allergens', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
			<?php if ( $a['contiene'] ) : ?>
				<strong><?php echo esc_html( Alergenos::texto( $a['contiene'] ) ); ?></strong>
			<?php else : ?>
				<span class="asde-muted"><?php esc_html_e( 'None recorded', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span>
			<?php endif; ?>
			<?php if ( $a['trazas'] ) : ?>
				<br><span class="asde-muted"><?php echo esc_html( sprintf( /* translators: %s: text */ __( 'May contain: %s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), Alergenos::texto( $a['trazas'] ) ) ); ?></span>
			<?php endif; ?>
			<?php if ( $a['sin_revisar'] ) : ?>
				<br><span class="asde-badge asde-badge--pago-parcial"><?php esc_html_e( 'Ingredients not yet reviewed', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span>
			<?php endif; ?>
		</td>
		<td data-label="<?php echo esc_attr( esc_attr__( 'Actions', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
			<form method="post" class="asde-inline">
				<?php wp_nonce_field( 'asde_menus' ); ?>
				<input type="hidden" name="receta" value="<?php echo (int) $r['id']; ?>">
				<?php if ( ! $propia ) : ?>
					<button type="submit" class="asde-btn-link" name="asde_action" value="receta_copiar"><?php esc_html_e( 'Copy to my group', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
				<?php elseif ( $r['activa'] ) : ?>
					<button type="submit" class="asde-btn-link" name="asde_action" value="receta_copiar"><?php esc_html_e( 'Duplicate', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
					<button type="submit" class="asde-btn-link" name="asde_action" value="receta_archivar"><?php esc_html_e( 'Archive', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
				<?php else : ?>
					<button type="submit" class="asde-btn-link" name="asde_action" value="receta_restaurar"><?php esc_html_e( 'Recover', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
				<?php endif; ?>
			</form>
		</td>
	</tr>
	<?php
};
$cabecera = static function (): void {
	?>
	<thead>
		<tr>
			<th scope="col"><?php esc_html_e( 'Recipe', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
			<th scope="col"><?php esc_html_e( 'Type', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
			<th scope="col"><?php esc_html_e( 'Base portions', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
			<th scope="col"><?php esc_html_e( 'Allergens', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
			<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span></th>
		</tr>
	</thead>
	<?php
};
?>
<section class="asde-card" data-asde-inicio="abierto">
	<header class="asde-card__header asde-card__header--split">
		<h2 class="asde-card__title"><?php esc_html_e( 'Recipe book', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h2>
		<span class="asde-actions asde-actions--botones">
			<a class="button" href="<?php echo esc_url( $url_nueva ); ?>"><?php esc_html_e( 'Add recipe', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a>
			<a class="button asde-button-secundario" href="<?php echo esc_url( $url_ing ); ?>"><?php esc_html_e( 'Ingredients', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a>
			<a class="button asde-button-secundario" href="<?php echo esc_url( $url_csv ); ?>"><?php esc_html_e( 'Load from CSV', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a>
		</span>
	</header>
	<p class="asde-muted"><?php esc_html_e( 'The group\'s recipes, with their ingredients. The allergens of each recipe come from those of its ingredients: they are not written by hand. There is no data about any person here.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
	<form method="get" action="<?php echo esc_url( $url ); ?>" class="asde-filter asde-filter--una-linea">
		<label for="asde-rc-tipo"><?php esc_html_e( 'Type', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
		<select id="asde-rc-tipo" name="tipo">
			<option value=""><?php esc_html_e( 'All', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></option>
			<?php foreach ( RecetasTable::tipos() as $k => $n ) : ?>
				<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $tipo, $k ); ?>><?php echo esc_html(  $n  ); ?></option>
			<?php endforeach; ?>
		</select>
		<label for="asde-rc-q" class="screen-reader-text"><?php esc_html_e( 'Search', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
		<input type="search" id="asde-rc-q" name="q" value="<?php echo esc_attr( $q ); ?>" placeholder="<?php echo esc_attr( esc_attr__( 'Recipe name', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
		<label class="asde-choice asde-choice--inline"><input type="checkbox" name="archivadas" value="1" <?php checked( $archivadas ); ?>><span><?php esc_html_e( 'Also show the archived ones', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span></label>
		<button type="submit" class="button"><?php esc_html_e( 'View', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
	</form>

	<h3><?php esc_html_e( 'Group recipes', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h3>
	<?php if ( ! $propias ) : ?>
		<p><?php esc_html_e( 'There are no group recipes yet. Copy one from the example library, add a new one or load several from a CSV.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
	<?php else : ?>
		<table class="asde-table">
			<?php $cabecera(); ?>
			<tbody><?php foreach ( $propias as $r ) { $fila( $r, true ); } ?></tbody>
		</table>
	<?php endif; ?>
</section>

<section class="asde-card" data-asde-plegable="menus-biblioteca" data-asde-inicio="cerrado">
	<h3 class="asde-card__title"><?php esc_html_e( 'Example library', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h3>
	<p class="asde-muted"><?php esc_html_e( 'Example recipes that come with the application, the same for all groups. Copy the one that suits you and edit it: the library one does not change. Always check the allergens of the brands you use before relying on a recipe.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
	<?php if ( ! $comunes ) : ?>
		<p><?php esc_html_e( 'There are no recipes with that filter.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
	<?php else : ?>
		<table class="asde-table">
			<?php $cabecera(); ?>
			<tbody><?php foreach ( $comunes as $r ) { $fila( $r, false ); } ?></tbody>
		</table>
	<?php endif; ?>
</section>
