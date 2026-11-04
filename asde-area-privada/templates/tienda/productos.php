<?php
/**
 * Productos de la tienda (doc 48, fase 2): la lista de la administración.
 *
 * @var array<int, array>  $productos
 * @var array<int, array>  $variantes   producto => variantes
 * @var string[]           $categorias
 * @var string             $categoria   Filtro
 * @var string             $q           Filtro
 * @var bool               $archivados  Se ven también los archivados
 * @var string             $url
 * @var string             $url_nuevo
 * @var string             $url_csv
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Tesoreria\Calculadora;
use AsdeBalears\AreaPrivada\Tienda\ProductosTable;
use AsdeBalears\AreaPrivada\Tienda\VariantesTable;

defined( 'ABSPATH' ) || exit;
?>
<section class="asde-card" data-asde-inicio="abierto">
	<header class="asde-card__header asde-card__header--split">
		<h2 class="asde-card__title"><?php esc_html_e( 'Shop inventory', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h2>
		<span class="asde-actions asde-actions--botones">
			<a class="button" href="<?php echo esc_url( $url_nuevo ); ?>"><?php esc_html_e( 'Add product', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a>
			<a class="button asde-button-secundario" href="<?php echo esc_url( $url_csv ); ?>"><?php esc_html_e( 'Load from CSV', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a>
		</span>
	</header>
	<p class="asde-muted"><?php esc_html_e( 'What is here is what the shop can sell. Each product has one or more variants (sizes, colours…) with their stock. "Members only" ones are seen only by those who have signed in.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>

	<form method="get" action="<?php echo esc_url( $url ); ?>" class="asde-filter asde-filter--una-linea">
		<label for="asde-tp-cat"><?php esc_html_e( 'Category', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
		<select id="asde-tp-cat" name="categoria">
			<option value=""><?php esc_html_e( 'All', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></option>
			<?php foreach ( $categorias as $c ) : ?>
				<option value="<?php echo esc_attr( $c ); ?>" <?php selected( $categoria, $c ); ?>><?php echo esc_html( $c ); ?></option>
			<?php endforeach; ?>
		</select>
		<label for="asde-tp-q" class="screen-reader-text"><?php esc_html_e( 'Search', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
		<input type="search" id="asde-tp-q" name="q" value="<?php echo esc_attr( $q ); ?>" placeholder="<?php echo esc_attr( esc_attr__( 'Name or reference', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
		<label class="asde-choice asde-choice--inline"><input type="checkbox" name="archivados" value="1" <?php checked( $archivados ); ?>><span><?php esc_html_e( 'Also show the archived ones', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span></label>
		<button type="submit" class="button"><?php esc_html_e( 'View', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
	</form>

	<?php if ( ! $productos ) : ?>
		<p><?php esc_html_e( 'There are no products with that filter. Start with "Add product" or, if you already have them in a spreadsheet, with "Load from CSV".', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
	<?php else : ?>
		<table class="asde-table asde-table--tienda-productos" data-asde-paginar>
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Product', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Category', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Visible to', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
					<th scope="col" class="asde-num"><?php esc_html_e( 'Price', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Variants', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
					<th scope="col" class="asde-num"><?php esc_html_e( 'Stock', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $productos as $p ) : ?>
					<?php
					$vars  = $variantes[ $p['id'] ] ?? array();
					$total = VariantesTable::total( $vars );
					?>
					<tr<?php echo $p['activo'] ? '' : ' class="asde-fila--cancelada"'; ?>>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Product', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
							<a href="<?php echo esc_url( add_query_arg( 'producto', $p['id'], $url ) ); ?>"><?php echo esc_html( $p['nombre'] ); ?></a>
							<?php if ( ! $p['activo'] ) : ?>
								<span class="asde-badge asde-badge--uniforme-cancelado"><?php esc_html_e( 'Archived', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span>
							<?php endif; ?>
						</td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Category', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo '' !== $p['categoria'] ? esc_html( $p['categoria'] ) : '<span class="asde-muted">—</span>'; ?></td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Visible to', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo ProductosTable::VIS_SOCIOS === $p['visibilidad'] ? esc_html( __( 'Members only', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) : esc_html( __( 'All', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?></td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Price', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>" class="asde-num">
							<?php if ( null === $p['precio'] ) : ?>
								<span class="asde-muted"><?php esc_html_e( 'To be confirmed', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span>
							<?php else : ?>
								<?php echo esc_html( Calculadora::euros( $p['precio'] ) ); ?>
								<?php if ( null !== $p['precio_socio'] ) : ?>
									<br><span class="asde-muted"><?php echo esc_html( sprintf( /* translators: %s: text */ __( 'members: %s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), Calculadora::euros( $p['precio_socio'] ) ) ); ?></span>
								<?php endif; ?>
							<?php endif; ?>
						</td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Variants', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
							<?php
							$etiquetas = array();
							foreach ( $vars as $v ) {
								if ( 1 === $v['activo'] ) {
									$etiquetas[] = VariantesTable::etiqueta( $v );
								}
							}
							echo $etiquetas ? esc_html( wp_trim_words( implode( ', ', $etiquetas ), 12, '…' ) ) : '<span class="asde-muted">—</span>';
							?>
						</td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Stock', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>" class="asde-num">
							<?php echo null === $total ? esc_html( __( 'No limit', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) : (int) $total; ?>
							<?php if ( null !== $total && 0 === $total && $p['activo'] ) : ?>
								<span class="asde-badge asde-badge--inv-pocas"><?php esc_html_e( 'Sold out', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</section>
