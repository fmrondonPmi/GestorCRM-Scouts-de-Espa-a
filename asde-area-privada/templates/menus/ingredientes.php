<?php
/**
 * Ingredientes del grupo y de la biblioteca común (v0.41, doc 49, B1).
 *
 * @var array[]          $lista
 * @var array<int, int>  $usos        ingrediente => nº de recetas que lo usan
 * @var array|null       $edicion     El que se está editando (solo los del grupo)
 * @var bool             $archivados
 * @var string           $url
 * @var string           $url_lista
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Menus\Alergenos;
use AsdeBalears\AreaPrivada\Menus\Escalado;
use AsdeBalears\AreaPrivada\Menus\IngredientesTable;

defined( 'ABSPATH' ) || exit;

$e = $edicion ?: array( 'id' => 0, 'nombre' => '', 'unidad' => 'g', 'alergenos' => array(), 'trazas' => array(), 'animal' => false, 'coste' => null, 'revisado' => false );
?>
<section class="asde-card" data-asde-inicio="abierto">
	<header class="asde-card__header asde-card__header--split">
		<h2 class="asde-card__title"><?php esc_html_e( 'Ingredients', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h2>
		<span class="asde-actions"><a href="<?php echo esc_url( $url_lista ); ?>"><?php esc_html_e( 'Back to the recipe book', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a></span>
	</header>
	<p class="asde-muted"><?php esc_html_e( 'Each ingredient carries its allergens; recipes inherit them. "May contain" are the traces the label warns of. Tick "reviewed" when you have checked the label: recipes warn about the ones nobody has reviewed. Those of the example library cannot be changed.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>

	<form method="post" action="<?php echo esc_url( $url ); ?>" class="asde-form">
		<?php wp_nonce_field( 'asde_menus' ); ?>
		<input type="hidden" name="asde_action" value="ingrediente_guardar">
		<input type="hidden" name="ingrediente" value="<?php echo (int) $e['id']; ?>">
		<h3><?php echo esc_html( $e['id'] ? __( 'Edit the ingredient', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) : __( 'New ingredient', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?></h3>
		<p>
			<label for="asde-ing-nombre"><?php esc_html_e( 'Name', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
			<input type="text" id="asde-ing-nombre" name="nombre" maxlength="120" required value="<?php echo esc_attr( $e['nombre'] ); ?>">
		</p>
		<p>
			<label for="asde-ing-uni"><?php esc_html_e( 'Usual unit', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
			<select id="asde-ing-uni" name="unidad">
				<?php foreach ( Escalado::unidades() as $k => $u ) : ?>
					<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $e['unidad'], $k ); ?>><?php echo esc_html(  $u[0]  ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
		<fieldset>
			<legend><?php esc_html_e( 'Contains', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></legend>
			<?php foreach ( Alergenos::lista() as $k => $n ) : ?>
				<label class="asde-choice asde-choice--inline"><input type="checkbox" name="alergenos[]" value="<?php echo esc_attr( $k ); ?>" <?php checked( in_array( $k, $e['alergenos'], true ) ); ?>><span><?php echo esc_html(  $n  ); ?></span></label>
			<?php endforeach; ?>
		</fieldset>
		<fieldset>
			<legend><?php esc_html_e( 'May contain traces of', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></legend>
			<?php foreach ( Alergenos::lista() as $k => $n ) : ?>
				<label class="asde-choice asde-choice--inline"><input type="checkbox" name="trazas[]" value="<?php echo esc_attr( $k ); ?>" <?php checked( in_array( $k, $e['trazas'], true ) ); ?>><span><?php echo esc_html(  $n  ); ?></span></label>
			<?php endforeach; ?>
		</fieldset>
		<label class="asde-choice"><input type="checkbox" name="animal" value="1" <?php checked( $e['animal'] ); ?>><span><?php esc_html_e( 'It is of animal origin (meat, fish, eggs, dairy, honey…)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span></label>
		<label class="asde-choice"><input type="checkbox" name="revisado" value="1" <?php checked( $e['revisado'] ); ?>><span><?php esc_html_e( 'I have checked the allergens of this ingredient', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span></label>
		<p>
			<label for="asde-ing-coste"><?php esc_html_e( 'Cost per usual unit, in euros (optional)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
			<input type="text" inputmode="decimal" id="asde-ing-coste" name="coste" size="8" value="<?php echo null === $e['coste'] ? '' : esc_attr( rtrim( rtrim( number_format( (float) $e['coste'], 4, ',', '' ), '0' ), ',' ) ); ?>">
		</p>
		<p><button type="submit" class="button"><?php esc_html_e( 'Save the ingredient', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button> <?php echo wp_kses( $e['id'] ? '<a href="' . esc_url( $url ) . '">' . esc_html( __( 'Cancel', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) . '</a>' : '', asde_html_permitido() ); ?></p>
	</form>
</section>

<section class="asde-card" data-asde-inicio="abierto">
	<h3 class="asde-card__title"><?php esc_html_e( 'List of ingredients', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h3>
	<form method="get" action="<?php echo esc_url( $url ); ?>" class="asde-filter asde-filter--una-linea">
		<input type="hidden" name="ingredientes" value="1">
		<label class="asde-choice asde-choice--inline"><input type="checkbox" name="archivados" value="1" <?php checked( $archivados ); ?>><span><?php esc_html_e( 'Also show the archived ones', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span></label>
		<button type="submit" class="button"><?php esc_html_e( 'View', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
	</form>
	<table class="asde-table">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Ingredient', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Contains', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
				<th scope="col"><?php esc_html_e( 'May contain', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Recipes', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
				<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $lista as $i ) : ?>
				<?php $comun = IngredientesTable::COMUN === $i['grupo']; ?>
				<tr<?php echo $i['activo'] ? '' : ' class="asde-fila--cancelada"'; ?>>
					<td data-label="<?php echo esc_attr( esc_attr__( 'Ingredient', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
						<?php echo esc_html( $comun ? asde_guardado( $i['nombre'] ) : $i['nombre'] ); ?>
						<?php echo wp_kses( $comun ? ' <span class="asde-badge">' . esc_html( __( 'Library', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) . '</span>' : '', asde_html_permitido() ); ?>
						<?php echo wp_kses( ! $i['revisado'] ? ' <span class="asde-badge asde-badge--pago-parcial">' . esc_html( __( 'Not reviewed', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) . '</span>' : '', asde_html_permitido() ); ?>
						<?php echo wp_kses( $i['animal'] ? ' <span class="asde-muted">' . esc_html( __( '(animal origin)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) . '</span>' : '', asde_html_permitido() ); ?>
					</td>
					<td data-label="<?php echo esc_attr( esc_attr__( 'Contains', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo wp_kses( $i['alergenos'] ? esc_html( Alergenos::texto( $i['alergenos'] ) ) : '<span class="asde-muted">—</span>', asde_html_permitido() ); ?></td>
					<td data-label="<?php echo esc_attr( esc_attr__( 'May contain', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo wp_kses( $i['trazas'] ? esc_html( Alergenos::texto( $i['trazas'] ) ) : '<span class="asde-muted">—</span>', asde_html_permitido() ); ?></td>
					<td data-label="<?php echo esc_attr( esc_attr__( 'Recipes', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo (int) ( $usos[ $i['id'] ] ?? 0 ); ?></td>
					<td data-label="<?php echo esc_attr( esc_attr__( 'Actions', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
						<?php if ( ! $comun ) : ?>
							<form method="post" class="asde-inline" action="<?php echo esc_url( $url ); ?>">
								<?php wp_nonce_field( 'asde_menus' ); ?>
								<input type="hidden" name="ingrediente" value="<?php echo (int) $i['id']; ?>">
								<a href="<?php echo esc_url( add_query_arg( 'ingrediente', (string) $i['id'], $url ) ); ?>"><?php esc_html_e( 'Edit', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a>
								<?php if ( $i['activo'] ) : ?>
									<button type="submit" class="asde-btn-link" name="asde_action" value="ingrediente_archivar"><?php esc_html_e( 'Archive', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
								<?php else : ?>
									<button type="submit" class="asde-btn-link" name="asde_action" value="ingrediente_restaurar"><?php esc_html_e( 'Recover', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
								<?php endif; ?>
							</form>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
</section>
