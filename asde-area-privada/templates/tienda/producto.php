<?php
/**
 * Un producto de la tienda: crear o editar, con sus variantes y existencias (doc 48, fase 2).
 *
 * @var array|null         $p           null = producto nuevo
 * @var array<int, array>  $variantes
 * @var array              $borrador    Lo escrito en un guardado que falló
 * @var string[]           $categorias
 * @var int                $nuevas      Variantes nuevas que caben en un guardado
 * @var string             $url
 * @var string             $url_lista
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Tienda\Config;
use AsdeBalears\AreaPrivada\Tienda\ProductosTable;

defined( 'ABSPATH' ) || exit;

$id     = $p ? (int) $p['id'] : 0;
$euros  = static fn( $v ): string => null === $v || '' === $v ? '' : number_format( (float) $v, 2, ',', '' );
// Lo que se enseña en cada campo: lo que se escribió y no se pudo guardar, o lo guardado.
$val    = static function ( string $campo, $guardado ) use ( $borrador ) {
	return array_key_exists( $campo, $borrador ) ? wp_unslash( $borrador[ $campo ] ) : $guardado;
};
$marca  = static function ( string $campo, int $guardado ) use ( $borrador ): bool {
	return $borrador ? '1' === (string) ( $borrador[ $campo ] ?? '' ) : 1 === $guardado;
};
$imagen = $p ? Config::imagen( $p['imagen'], 'thumbnail' ) : '';
?>
<p class="asde-back asde-solo-pagina"><a href="<?php echo esc_url( $url_lista ); ?>"><?php esc_html_e( '← Shop inventory', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a></p>

<form method="post" enctype="multipart/form-data" class="asde-form" action="<?php echo esc_url( $url ); ?>">
	<?php wp_nonce_field( 'asde_tienda_producto_' . $id ); ?>
	<input type="hidden" name="asde_action" value="producto_guardar">
	<input type="hidden" name="producto" value="<?php echo (int) $id; ?>">

	<section class="asde-card" data-asde-inicio="abierto">
		<h2 class="asde-card__title"><?php echo $p ? esc_html( $p['nombre'] ) : esc_html( __( 'New product', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>
			<?php if ( $p && ! $p['activo'] ) : ?>
				<span class="asde-badge asde-badge--uniforme-cancelado"><?php esc_html_e( 'Archived', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span>
			<?php endif; ?>
		</h2>
		<p>
			<label for="asde-tp-nombre"><?php esc_html_e( 'Name', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <span class="asde-obligatorio" aria-hidden="true">*</span></label>
			<input type="text" id="asde-tp-nombre" name="nombre" value="<?php echo esc_attr( $val( 'nombre', $p['nombre'] ?? '' ) ); ?>" maxlength="120" size="40" required>
		</p>
		<p>
			<label for="asde-tp-desc"><?php esc_html_e( 'Description', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
			<textarea id="asde-tp-desc" name="descripcion" rows="3" maxlength="2000"><?php echo esc_textarea( $val( 'descripcion', $p['descripcion'] ?? '' ) ); ?></textarea>
		</p>
		<p>
			<label for="asde-tp-cat"><?php esc_html_e( 'Category', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
			<input type="text" id="asde-tp-cat" name="categoria" value="<?php echo esc_attr( $val( 'categoria', $p['categoria'] ?? '' ) ); ?>" maxlength="60" list="asde-tp-cats">
			<datalist id="asde-tp-cats">
				<?php foreach ( $categorias as $c ) : ?>
					<option value="<?php echo esc_attr( $c ); ?>">
				<?php endforeach; ?>
			</datalist>
		</p>
		<p>
			<label for="asde-tp-vis"><?php esc_html_e( 'Who sees it and can buy it', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
			<select id="asde-tp-vis" name="visibilidad">
				<?php foreach ( ProductosTable::visibilidades() as $clave => $etiqueta ) : ?>
					<option value="<?php echo esc_attr( $clave ); ?>" <?php selected( $val( 'visibilidad', $p['visibilidad'] ?? ProductosTable::VIS_TODOS ), $clave ); ?>><?php echo esc_html(  $etiqueta  ); ?></option>
				<?php endforeach; ?>
			</select>
			<span class="asde-muted"><?php esc_html_e( 'A "members only" product does not appear to anyone who has not signed in, neither in the list nor by its address.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span>
		</p>
		<p>
			<label for="asde-tp-precio"><?php esc_html_e( 'Price (€)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
			<input type="text" id="asde-tp-precio" name="precio" value="<?php echo esc_attr( $val( 'precio', $euros( $p['precio'] ?? null ) ) ); ?>" inputmode="decimal" size="8">
			<span class="asde-muted"><?php esc_html_e( 'Empty = "price to be confirmed": it can be ordered and Treasury charges the real amount.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span>
		</p>
		<p>
			<label for="asde-tp-psocio"><?php esc_html_e( 'Member price (€, optional)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
			<input type="text" id="asde-tp-psocio" name="precio_socio" value="<?php echo esc_attr( $val( 'precio_socio', $euros( $p['precio_socio'] ?? null ) ) ); ?>" inputmode="decimal" size="8">
			<span class="asde-muted"><?php esc_html_e( 'If you set it, signed-in members pay this price instead of the general one.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span>
		</p>
		<p>
			<label for="asde-tp-limite"><?php esc_html_e( 'Maximum per person (0 = no limit)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
			<input type="number" id="asde-tp-limite" name="limite_persona" value="<?php echo (int) $val( 'limite_persona', $p['limite_persona'] ?? 0 ); ?>" min="0" max="<?php echo (int) ProductosTable::MAX_LIMITE; ?>" class="small-text">
		</p>
		<label class="asde-choice"><input type="checkbox" name="requiere_proveedor" value="1" <?php checked( $marca( 'requiere_proveedor', (int) ( $p['requiere_proveedor'] ?? 0 ) ) ); ?>><span><?php esc_html_e( 'Ordered from the supplier (goes through "included in the group\'s order" before being delivered)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span></label>
		<p>
			<?php esc_html_e( 'Image:', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?><br>
			<?php if ( '' !== $imagen ) : ?>
				<img class="asde-config-img" src="<?php echo esc_url( $imagen ); ?>" alt="<?php echo esc_attr( $p['nombre'] ); ?>">
				<label class="asde-choice asde-choice--inline"><input type="checkbox" name="imagen_quitar" value="1"><span><?php esc_html_e( 'Remove', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span></label>
			<?php endif; ?>
			<input type="file" name="imagen" accept="image/png,image/jpeg,image/webp,image/gif">
		</p>
	</section>

	<section class="asde-card" data-asde-inicio="abierto">
		<h3 class="asde-card__title"><?php esc_html_e( 'Variants and stock', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h3>
		<p class="asde-muted"><?php esc_html_e( 'A variant is what is bought: a size, a colour… If the product has no variants, leave a single one ("Single"). The stock of variants linked to the general inventory is changed there; for the others it is added or subtracted in "Change stock" (+5, -2) and recorded. "No limit" does not count units (what is ordered from the supplier).', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
		<?php if ( $variantes ) : ?>
			<table class="asde-table asde-table--tienda-variantes" data-asde-sin-paginar="editor de variantes del producto">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Variant', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Reference', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Own price (€)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
						<th scope="col" class="asde-num"><?php esc_html_e( 'Stock', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Change stock', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
						<th scope="col"><?php esc_html_e( 'No limit', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
						<th scope="col"><?php esc_html_e( 'On sale', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $variantes as $v ) : ?>
						<?php $vid = (int) $v['id']; ?>
						<tr<?php echo $v['activo'] ? '' : ' class="asde-fila--cancelada"'; ?>>
							<td data-label="<?php echo esc_attr( esc_attr__( 'Variant', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><input type="text" name="v_etiqueta[<?php echo (int) $vid; ?>]" value="<?php echo esc_attr( $v['etiqueta'] ); ?>" maxlength="80" size="18" aria-label="<?php echo esc_attr( esc_attr__( 'Variant', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"></td>
							<td data-label="<?php echo esc_attr( esc_attr__( 'Reference', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><input type="text" name="v_sku[<?php echo (int) $vid; ?>]" value="<?php echo esc_attr( $v['sku'] ); ?>" maxlength="60" size="10" aria-label="<?php echo esc_attr( esc_attr__( 'Reference', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"></td>
							<td data-label="<?php echo esc_attr( esc_attr__( 'Own price (€)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><input type="text" name="v_precio[<?php echo (int) $vid; ?>]" value="<?php echo esc_attr( $euros( $v['precio'] ) ); ?>" inputmode="decimal" size="7" aria-label="<?php echo esc_attr( esc_attr__( 'Own price (€)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"></td>
							<td data-label="<?php echo esc_attr( esc_attr__( 'Stock', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>" class="asde-num"><?php echo $v['ilimitado'] ? esc_html( __( 'No limit', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) : (int) $v['existencias']; ?></td>
							<td data-label="<?php echo esc_attr( esc_attr__( 'Change stock', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><input type="text" name="v_ajuste[<?php echo (int) $vid; ?>]" value="" <?php disabled( $v['material'] > 0 ); ?> title="<?php echo $v['material'] > 0 ? esc_attr( esc_attr__( 'They are changed in the general inventory', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) : ''; ?>" inputmode="numeric" size="6" placeholder="+5" aria-label="<?php echo esc_attr( esc_attr__( 'Change stock', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>" <?php disabled( (bool) $v['ilimitado'] ); ?>></td>
							<td data-label="<?php echo esc_attr( esc_attr__( 'No limit', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><input type="checkbox" name="v_ilimitado[<?php echo (int) $vid; ?>]" value="1" <?php checked( (bool) $v['ilimitado'] ); ?> <?php disabled( $v['material'] > 0 ); ?> aria-label="<?php echo esc_attr( esc_attr__( 'No limit', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"></td>
							<td data-label="<?php echo esc_attr( esc_attr__( 'On sale', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><input type="checkbox" name="v_activa[<?php echo (int) $vid; ?>]" value="1" <?php checked( (bool) $v['activo'] ); ?> aria-label="<?php echo esc_attr( esc_attr__( 'On sale', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p>
				<label for="asde-tp-motivo"><?php esc_html_e( 'Reason for the stock change (optional)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
				<input type="text" id="asde-tp-motivo" name="motivo_ajuste" value="" maxlength="200" size="40" placeholder="<?php echo esc_attr( esc_attr__( 'For example: order arrival, stocktake…', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
			</p>
		<?php endif; ?>

		<h4><?php echo $variantes ? esc_html( __( 'Add variants', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) : esc_html( __( 'Variants', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?></h4>
		<?php for ( $i = 1; $i <= $nuevas; $i++ ) : ?>
			<p class="asde-form__fila">
				<input type="text" name="nueva_etiqueta_<?php echo (int) $i; ?>" value="<?php echo esc_attr( $val( 'nueva_etiqueta_' . $i, '' ) ); ?>" maxlength="80" size="16" placeholder="<?php echo esc_attr( esc_attr__( 'Variant (size, colour…)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>" aria-label="<?php echo esc_attr( esc_attr__( 'Variant', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
				<input type="text" name="nueva_sku_<?php echo (int) $i; ?>" value="<?php echo esc_attr( $val( 'nueva_sku_' . $i, '' ) ); ?>" maxlength="60" size="10" placeholder="<?php echo esc_attr( esc_attr__( 'Reference', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>" aria-label="<?php echo esc_attr( esc_attr__( 'Reference', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
				<input type="text" name="nueva_precio_<?php echo (int) $i; ?>" value="<?php echo esc_attr( $val( 'nueva_precio_' . $i, '' ) ); ?>" inputmode="decimal" size="7" placeholder="<?php echo esc_attr( esc_attr__( 'Own price', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>" aria-label="<?php echo esc_attr( esc_attr__( 'Own price (€)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
				<input type="text" name="nueva_existencias_<?php echo (int) $i; ?>" value="<?php echo esc_attr( $val( 'nueva_existencias_' . $i, '' ) ); ?>" inputmode="numeric" size="7" placeholder="<?php echo esc_attr( esc_attr__( 'Stock', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>" aria-label="<?php echo esc_attr( esc_attr__( 'Stock', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
				<label class="asde-choice asde-choice--inline"><input type="checkbox" name="nueva_ilimitado_<?php echo (int) $i; ?>" value="1" <?php checked( '1' === (string) $val( 'nueva_ilimitado_' . $i, '' ) ); ?>><span><?php esc_html_e( 'No limit', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span></label>
			</p>
		<?php endfor; ?>
	</section>

	<p class="asde-form__acciones">
		<button type="submit" class="button button-primary"><?php esc_html_e( 'Save', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
		<?php if ( ! $p ) : ?>
			<button type="submit" name="otro" value="1" class="button asde-button-secundario"><?php esc_html_e( 'Save and add another', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
		<?php endif; ?>
		<a class="button asde-button-secundario" href="<?php echo esc_url( $url_lista ); ?>"><?php esc_html_e( 'Back to the list', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a>
	</p>
</form>

<?php if ( $p ) : ?>
	<form method="post" class="asde-form" action="<?php echo esc_url( $url ); ?>"<?php echo $p['activo'] ? ' data-asde-confirmar="' . esc_attr( __( 'Archive this product? It will stop being sold (it can be recovered).', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) . '"' : ''; ?>>
		<?php wp_nonce_field( 'asde_tienda_producto_' . $id ); ?>
		<input type="hidden" name="producto" value="<?php echo (int) $id; ?>">
		<input type="hidden" name="asde_action" value="<?php echo $p['activo'] ? 'producto_archivar' : 'producto_recuperar'; ?>">
		<p class="asde-form__acciones">
			<button type="submit" class="button asde-button-secundario"><?php echo $p['activo'] ? esc_html( __( 'Archive the product', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) : esc_html( __( 'Recover the product', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?></button>
		</p>
	</form>
<?php endif; ?>
