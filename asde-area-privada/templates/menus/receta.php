<?php
/**
 * Una receta (v0.41, doc 49, B1): formulario si es del grupo, ficha si es de la biblioteca; alérgenos y escalado.
 *
 * @var array      $r
 * @var array      $borrador     Lo escrito si no se pudo guardar
 * @var bool       $propia       Es del grupo (se puede editar) o nueva
 * @var array[]    $lineas
 * @var array[]    $escaladas    Las líneas con la cantidad para «comensales»
 * @var array      $alergenos    Recetario::alergenos()
 * @var int        $comensales
 * @var array[]    $ingredientes Los que se pueden elegir
 * @var int        $extra        Filas vacías que se añaden
 * @var string     $url
 * @var string     $url_lista
 * @var string     $url_ing
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Menus\Alergenos;
use AsdeBalears\AreaPrivada\Menus\Escalado;
use AsdeBalears\AreaPrivada\Menus\IngredientesTable;
use AsdeBalears\AreaPrivada\Menus\RecetasTable;

defined( 'ABSPATH' ) || exit;

$nombre_ing = static fn( array $i ): string => IngredientesTable::COMUN === $i['grupo'] ? asde_t( $i['nombre'] ) : $i['nombre'];
$titulo     = $r['id'] ? ( $propia ? $r['nombre'] : asde_t( $r['nombre'] ) ) : __( 'New recipe', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' );
$val        = static fn( string $k ) => $borrador[ $k ] ?? $r[ $k ] ?? '';
?>
<section class="asde-card" data-asde-inicio="abierto">
	<header class="asde-card__header asde-card__header--split">
		<h2 class="asde-card__title"><?php echo esc_html( $titulo ); ?></h2>
		<span class="asde-actions"><a href="<?php echo esc_url( $url_lista ); ?>"><?php esc_html_e( 'Back to the recipe book', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a></span>
	</header>

	<?php if ( $r['id'] && ! $propia ) : ?>
		<div class="asde-notice asde-notice--warning"><?php esc_html_e( 'It is an example recipe from the library: it cannot be changed. Copy it to your group to edit it.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></div>
		<form method="post">
			<?php wp_nonce_field( 'asde_menus' ); ?>
			<input type="hidden" name="receta" value="<?php echo (int) $r['id']; ?>">
			<p><button type="submit" class="button" name="asde_action" value="receta_copiar"><?php esc_html_e( 'Copy to my group', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button></p>
		</form>
	<?php endif; ?>

	<?php if ( $r['id'] || $lineas ) : ?>
		<div class="asde-resumen-alergenos">
			<p>
				<strong><?php esc_html_e( 'Contains:', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></strong>
				<?php echo wp_kses( $alergenos['contiene'] ? esc_html( Alergenos::texto( $alergenos['contiene'] ) ) : '<span class="asde-muted">' . esc_html( __( 'no allergen recorded', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) . '</span>', asde_html_permitido() ); ?>
			</p>
			<?php if ( $alergenos['trazas'] ) : ?>
				<p><strong><?php esc_html_e( 'May contain traces of:', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></strong> <?php echo esc_html( Alergenos::texto( $alergenos['trazas'] ) ); ?></p>
			<?php endif; ?>
			<?php if ( $alergenos['animal'] ) : ?>
				<p class="asde-muted"><?php esc_html_e( 'Contains ingredients of animal origin (not suitable for vegetarian or vegan diets).', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
			<?php endif; ?>
			<?php foreach ( $alergenos['por_alergeno'] as $a => $ings ) : ?>
				<p class="asde-muted"><?php echo esc_html( sprintf( /* translators: 1: text, 2: text */ __( '%1$s: by %2$s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), Alergenos::nombre( $a ), implode( ', ', array_unique( $ings ) ) ) ); ?></p>
			<?php endforeach; ?>
			<?php if ( $alergenos['sin_revisar'] ) : ?>
				<div class="asde-notice asde-notice--warning"><?php echo esc_html( sprintf( /* translators: %s: text */ __( 'Nobody has reviewed the allergens of: %s. Record them in "Ingredients" before relying on this list.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), implode( ', ', $alergenos['sin_revisar'] ) ) ); ?></div>
			<?php endif; ?>
		</div>
	<?php endif; ?>

	<?php if ( $propia ) : ?>
		<form method="post" action="<?php echo esc_url( $url ); ?>" class="asde-form">
			<?php wp_nonce_field( 'asde_menus' ); ?>
			<input type="hidden" name="asde_action" value="receta_guardar">
			<input type="hidden" name="receta" value="<?php echo (int) $r['id']; ?>">
			<p>
				<label for="asde-rec-nombre"><?php esc_html_e( 'Name', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
				<input type="text" id="asde-rec-nombre" name="nombre" maxlength="160" required value="<?php echo esc_attr( (string) $val( 'nombre' ) ); ?>">
			</p>
			<p>
				<label for="asde-rec-tipo"><?php esc_html_e( 'Dish type', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
				<select id="asde-rec-tipo" name="tipo">
					<?php foreach ( RecetasTable::TIPOS as $k => $n ) : ?>
						<option value="<?php echo esc_attr( $k ); ?>" <?php selected( (string) $val( 'tipo' ), $k ); ?>><?php echo esc_html( asde_t( $n ) ); ?></option>
					<?php endforeach; ?>
				</select>
			</p>
			<p>
				<label for="asde-rec-porc"><?php esc_html_e( 'Base portions (how many people the quantities are for)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
				<input type="number" id="asde-rec-porc" name="porciones" min="1" max="500" required value="<?php echo (int) $val( 'porciones' ); ?>">
			</p>
			<h3><?php esc_html_e( 'Ingredients', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h3>
			<p class="asde-help"><?php esc_html_e( 'If an ingredient is missing, create it first in "Ingredients" with its allergens.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <a href="<?php echo esc_url( $url_ing ); ?>"><?php esc_html_e( 'Go to ingredients', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a></p>
			<table class="asde-table">
				<thead><tr><th scope="col"><?php esc_html_e( 'Ingredient', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th><th scope="col"><?php esc_html_e( 'Quantity', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th><th scope="col"><?php esc_html_e( 'Unidad', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th></tr></thead>
				<tbody>
					<?php
					$filas = $lineas;
					for ( $n = 0; $n < $extra; $n++ ) {
						$filas[] = array( 'ingrediente_id' => 0, 'cantidad' => '', 'unidad' => 'g' );
					}
					foreach ( $filas as $n => $l ) :
						?>
						<tr>
							<td data-label="<?php echo esc_attr( esc_attr__( 'Ingredient', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
								<select name="ing[]" aria-label="<?php echo esc_attr( sprintf( /* translators: %d: number */ __( 'Ingredient %d', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $n + 1 ) ); ?>">
									<option value="0">—</option>
									<?php foreach ( $ingredientes as $i ) : ?>
										<option value="<?php echo (int) $i['id']; ?>" <?php selected( (int) $l['ingrediente_id'], $i['id'] ); ?>><?php echo esc_html( $nombre_ing( $i ) ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
							<td data-label="<?php echo esc_attr( esc_attr__( 'Quantity', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><input type="text" inputmode="decimal" name="cant[]" size="8" value="<?php echo '' === $l['cantidad'] ? '' : esc_attr( rtrim( rtrim( number_format( (float) $l['cantidad'], 3, ',', '' ), '0' ), ',' ) ); ?>" aria-label="<?php echo esc_attr( sprintf( /* translators: %d: number */ __( 'Quantity %d', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $n + 1 ) ); ?>"></td>
							<td data-label="<?php echo esc_attr( esc_attr__( 'Unidad', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
								<select name="uni[]" aria-label="<?php echo esc_attr( sprintf( /* translators: %d: number */ __( 'Unit %d', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $n + 1 ) ); ?>">
									<?php foreach ( Escalado::UNIDADES as $k => $u ) : ?>
										<option value="<?php echo esc_attr( $k ); ?>" <?php selected( (string) $l['unidad'], $k ); ?>><?php echo esc_html( asde_t( $u[0] ) ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p class="asde-help"><?php esc_html_e( 'To add more ingredients, save and reopen the recipe: empty rows always appear.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
			<p>
				<label for="asde-rec-ela"><?php esc_html_e( 'Preparation', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
				<textarea id="asde-rec-ela" name="elaboracion" rows="4" maxlength="4000"><?php echo esc_textarea( (string) $val( 'elaboracion' ) ); ?></textarea>
			</p>
			<p>
				<label for="asde-rec-obs"><?php esc_html_e( 'Notes', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
				<textarea id="asde-rec-obs" name="observaciones" rows="2" maxlength="1000"><?php echo esc_textarea( (string) $val( 'observaciones' ) ); ?></textarea>
			</p>
			<p><button type="submit" class="button"><?php esc_html_e( 'Save the recipe', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button></p>
		</form>
	<?php elseif ( $lineas ) : ?>
		<h3><?php echo esc_html( sprintf( /* translators: %d: number */ __( 'Ingredients (for %d portions)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), (int) $r['porciones'] ) ); ?></h3>
		<ul>
			<?php foreach ( $lineas as $l ) : ?>
				<li><?php echo esc_html( ( $l['ingrediente'] ? $nombre_ing( $l['ingrediente'] ) : '—' ) . ': ' . Escalado::texto( $l['cantidad'], $l['unidad'] ) ); ?></li>
			<?php endforeach; ?>
		</ul>
		<?php if ( '' !== $r['observaciones'] ) : ?>
			<p class="asde-muted"><?php echo esc_html( asde_t( $r['observaciones'] ) ); ?></p>
		<?php endif; ?>
	<?php endif; ?>
</section>

<?php if ( $r['id'] && $escaladas ) : ?>
	<section class="asde-card" data-asde-inicio="abierto">
		<h3 class="asde-card__title"><?php esc_html_e( 'Quantities according to diners', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h3>
		<form method="get" action="<?php echo esc_url( $url ); ?>" class="asde-filter asde-filter--una-linea">
			<input type="hidden" name="receta" value="<?php echo (int) $r['id']; ?>">
			<label for="asde-rec-com"><?php esc_html_e( 'Diners', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
			<input type="number" id="asde-rec-com" name="comensales" min="1" max="5000" value="<?php echo (int) $comensales; ?>">
			<button type="submit" class="button"><?php esc_html_e( 'Calculate', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
		</form>
		<table class="asde-table">
			<thead><tr><th scope="col"><?php esc_html_e( 'Ingredient', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th><th scope="col"><?php echo esc_html( sprintf( /* translators: %d: number */ __( 'For %d portions', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), (int) $r['porciones'] ) ); ?></th><th scope="col"><?php echo esc_html( sprintf( /* translators: %d: number */ __( 'For %d diners', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), (int) $comensales ) ); ?></th></tr></thead>
			<tbody>
				<?php foreach ( $escaladas as $l ) : ?>
					<tr>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Ingredient', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo esc_html( $l['ingrediente'] ? $nombre_ing( $l['ingrediente'] ) : '—' ); ?></td>
						<td data-label="<?php echo esc_attr( sprintf( /* translators: %d: number */ esc_attr__( 'For %d portions', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), (int) $r['porciones'] ) ); ?>"><?php echo esc_html( Escalado::texto( $l['cantidad'], $l['unidad'] ) ); ?></td>
						<td data-label="<?php echo esc_attr( sprintf( /* translators: %d: number */ esc_attr__( 'For %d diners', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), (int) $comensales ) ); ?>"><strong><?php echo esc_html( $l['escalada'] ); ?></strong></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</section>
<?php endif; ?>
