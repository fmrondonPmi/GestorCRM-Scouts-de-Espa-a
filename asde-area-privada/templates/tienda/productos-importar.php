<?php
/**
 * Cargar productos y existencias desde un CSV (doc 48, fase 2). Después de cargar, el informe:
 * cuántos productos y variantes entraron o cambiaron y qué filas no, con su motivo.
 *
 * @var array|null $resultado   productos_creados, productos_actualizados, variantes_creadas, variantes_actualizadas, errores[{fila, datos, motivo}], fichero, filas
 * @var bool       $caducado    Se pidió un informe que ya no está (caduca en una hora)
 * @var string     $url
 * @var string     $url_lista
 * @var string     $url_ejemplo
 * @var string     $url_errores '' si no hay filas con error
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Tienda\ImportadorProductos;

defined( 'ABSPATH' ) || exit;

$columnas = array_map( static fn( $c ) =>  $c[0] , ImportadorProductos::columnas() );
?>
<p class="asde-back asde-solo-pagina"><a href="<?php echo esc_url( $url_lista ); ?>"><?php esc_html_e( '← Shop inventory', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a></p>

<?php if ( $resultado ) : ?>
	<section class="asde-card asde-inv-importar" data-asde-inicio="abierto">
		<h2 class="asde-card__title"><?php esc_html_e( 'Load result', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h2>
		<p class="asde-muted"><?php echo esc_html( sprintf( /* translators: 1: text, 2: number */ _n( '%1$s: %2$d row read.', '%1$s: %2$d rows read.', $resultado['filas'] , 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $resultado['fichero'], $resultado['filas'] ) ); ?></p>
		<ul class="asde-resumen">
			<li><strong><?php echo (int) $resultado['productos_creados']; ?></strong> <?php echo esc_html( 1 === (int) $resultado['productos_creados'] ? __( 'new product', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) : __( 'new products', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?></li>
			<li><strong><?php echo (int) $resultado['productos_actualizados']; ?></strong> <?php echo esc_html( 1 === (int) $resultado['productos_actualizados'] ? __( 'product updated', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) : __( 'products updated', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?></li>
			<li><strong><?php echo (int) $resultado['variantes_creadas']; ?></strong> <?php echo esc_html( 1 === (int) $resultado['variantes_creadas'] ? __( 'new variant', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) : __( 'new variants', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?></li>
			<li><strong><?php echo (int) $resultado['variantes_actualizadas']; ?></strong> <?php echo esc_html( 1 === (int) $resultado['variantes_actualizadas'] ? __( 'variant updated', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) : __( 'variants updated', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?></li>
			<li><strong><?php echo (int) count( $resultado['errores'] ); ?></strong> <?php echo esc_html( 1 === count( $resultado['errores'] ) ? __( 'row not loaded', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) : __( 'rows not loaded', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?></li>
		</ul>

		<?php if ( $resultado['errores'] ) : ?>
			<h3 class="asde-card__title"><?php esc_html_e( 'Rows that were not loaded', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h3>
			<p class="asde-muted"><?php esc_html_e( 'Correct them and load only those rows again (the others are already in the shop). You can download them as a CSV with the reason for each.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
			<table class="asde-table asde-table--inv-errores">
				<thead>
					<tr>
						<th scope="col" class="asde-num"><?php esc_html_e( 'Row', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Product', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Reason', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $resultado['errores'] as $e ) : ?>
						<tr>
							<td data-label="<?php echo esc_attr( esc_attr__( 'Row', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>" class="asde-num"><?php echo (int) $e['fila']; ?></td>
							<td data-label="<?php echo esc_attr( esc_attr__( 'Product', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo '' !== $e['datos']['producto'] ? esc_html( mb_strimwidth( $e['datos']['producto'], 0, 80, '…' ) ) : '<span class="asde-muted">' . esc_html( __( '(empty)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) . '</span>'; ?></td>
							<td data-label="<?php echo esc_attr( esc_attr__( 'Reason', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo esc_html( $e['motivo'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p class="asde-form__acciones">
				<a class="button no-transition" href="<?php echo esc_url( $url_errores ); ?>" download><?php esc_html_e( 'Download the rows with errors (CSV)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a>
				<a class="button asde-button-secundario" href="<?php echo esc_url( $url ); ?>"><?php esc_html_e( 'Load another file', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a>
			</p>
		<?php else : ?>
			<p><?php esc_html_e( 'All the rows have been loaded.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
			<p class="asde-form__acciones"><a class="button asde-button-secundario" href="<?php echo esc_url( $url ); ?>"><?php esc_html_e( 'Load another file', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a></p>
		<?php endif; ?>
	</section>
<?php else : ?>
	<section class="asde-card asde-inv-importar" data-asde-inicio="abierto">
		<h2 class="asde-card__title"><?php esc_html_e( 'Load products from a CSV', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h2>
		<?php if ( $caducado ) : ?>
			<div class="asde-notice asde-notice--warning" role="status"><?php esc_html_e( 'The report of that load is no longer available (it is kept for an hour).', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></div>
		<?php endif; ?>
		<p><?php esc_html_e( 'Prepare the products in a spreadsheet, save it as CSV and upload it here. Each row is a variant (a size, a colour…); several rows with the same product are variants of the same product. The file is checked first; then the correct rows are loaded and you will see those that are not, with the reason.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
		<p>
			<a class="no-transition" href="<?php echo esc_url( $url_ejemplo ); ?>" download><?php esc_html_e( 'Download an example file', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a>
			<span class="asde-muted"><?php esc_html_e( '(open it with Excel or LibreOffice, replace the example rows with your products and save it as CSV)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span>
		</p>
		<form method="post" action="<?php echo esc_url( $url ); ?>" enctype="multipart/form-data" class="asde-form">
			<?php wp_nonce_field( 'asde_tienda_importar' ); ?>
			<input type="hidden" name="asde_action" value="productos_importar">
			<p>
				<label for="asde-tp-fichero"><?php esc_html_e( 'CSV file', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <span class="asde-obligatorio" aria-hidden="true">*</span></label>
				<input type="file" id="asde-tp-fichero" name="fichero" accept=".csv,text/csv,text/plain" required>
			</p>
			<label class="asde-choice"><input type="checkbox" name="sumar" value="1"><span><?php esc_html_e( 'Add the stock to what already exists (otherwise the file\'s stock replaces the current stock)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span></label>
			<p class="asde-form__acciones">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Load', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
			</p>
		</form>
		<details class="asde-inv-columnas">
			<summary><?php esc_html_e( 'What the file must look like', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></summary>
			<ul>
				<li><?php echo esc_html( sprintf( /* translators: %s: text */ __( 'The first row, the column names: %s. In any order; the ones you don\'t use can be removed.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), implode( ', ', $columnas ) ) ); ?></li>
				<li><?php echo esc_html( sprintf( /* translators: %s: text */ __( 'Required: %s.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), implode( ', ', array_map( static fn( $c ) => ( ImportadorProductos::columnas()[ $c ][0] ), ImportadorProductos::OBLIGATORIAS ) ) ) ); ?></li>
				<li><?php esc_html_e( 'Visibility: "all" or "members". The price, with a comma or point for the decimals (12,50); empty = price to be confirmed. Stock: a whole number or "unlimited".', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></li>
				<li><?php esc_html_e( 'An existing product (same name) is updated only with the filled cells: an empty cell deletes nothing. An existing variant changes its stock.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></li>
				<li><?php echo esc_html( sprintf( /* translators: 1: number, 2: number */ __( 'Separated by semicolons or commas, up to %1$d MB and %2$d rows.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), (int) ( ImportadorProductos::MAX_BYTES / MB_IN_BYTES ), ImportadorProductos::MAX_FILAS ) ); ?></li>
			</ul>
		</details>
	</section>
<?php endif; ?>
