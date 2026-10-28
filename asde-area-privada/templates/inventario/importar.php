<?php
/**
 * Importar material desde un CSV (doc 32). Se abre en la ventana emergente desde
 * el inventario; sin JavaScript, en su página. Después de importar, el informe:
 * cuántos materiales entraron y qué filas no, con su motivo.
 *
 * @var array|null $resultado   importados, sumados (v0.30), errores[{fila, datos, motivo}], tipos_creados, fichero, filas
 * @var bool       $caducado    Se pidió un informe que ya no está (caduca en una hora)
 * @var string     $url
 * @var string     $url_lista
 * @var string     $url_ejemplo
 * @var string     $url_errores '' si no hay filas con error
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Inventario\Importador;

defined( 'ABSPATH' ) || exit;

$columnas = array_map( static fn( $c ) => asde_t( $c[0] ), Importador::COLUMNAS );
?>
<p class="asde-back asde-solo-pagina"><a href="<?php echo esc_url( $url_lista ); ?>"><?php esc_html_e( '← Inventory', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a></p>

<?php if ( $resultado ) : ?>
	<section class="asde-card asde-inv-importar">
		<h2 class="asde-card__title"><?php esc_html_e( 'Import result', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h2>
		<p class="asde-muted"><?php echo esc_html( sprintf( /* translators: 1: text, 2: number */ _n( '%1$s: %2$d row read.', '%1$s: %2$d rows read.', $resultado['filas'] , 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $resultado['fichero'], $resultado['filas'] ) ); ?></p>
		<ul class="asde-resumen">
			<li><strong><?php echo (int) $resultado['importados']; ?></strong> <?php echo esc_html( 1 === (int) $resultado['importados'] ? __( 'item imported', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) : __( 'items imported', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?></li>
			<?php if ( ! empty( $resultado['sumados'] ) ) : ?>
				<?php // v0.30 (P-67): las filas repetidas suman su cantidad al material que ya estaba. ?>
				<li><strong><?php echo (int) $resultado['sumados']; ?></strong> <?php echo esc_html( 1 === (int) $resultado['sumados'] ? __( 'row added to an item that already existed (same description and type)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) : __( 'rows added to an item that already existed (same description and type)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?></li>
			<?php endif; ?>
			<li><strong><?php echo (int) count( $resultado['errores'] ); ?></strong> <?php echo esc_html( 1 === count( $resultado['errores'] ) ? __( 'row not imported', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) : __( 'rows not imported', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?></li>
		</ul>
		<?php if ( $resultado['tipos_creados'] ) : ?>
			<p><?php echo esc_html( sprintf( /* translators: %s: text */ __( 'New equipment types: %s.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), implode( ', ', $resultado['tipos_creados'] ) ) ); ?></p>
		<?php endif; ?>

		<?php if ( $resultado['errores'] ) : ?>
			<h3 class="asde-card__title"><?php esc_html_e( 'Rows that were not imported', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h3>
			<p class="asde-muted"><?php esc_html_e( 'Correct them and import only those rows again (the others are already in the inventory). You can download them as a CSV with the reason for each.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
			<table class="asde-table asde-table--inv-errores">
				<thead>
					<tr>
						<th scope="col" class="asde-num"><?php esc_html_e( 'Row', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Description', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Reason', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $resultado['errores'] as $e ) : ?>
						<tr>
							<td data-label="<?php echo esc_attr( esc_attr__( 'Row', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>" class="asde-num"><?php echo (int) $e['fila']; ?></td>
							<td data-label="<?php echo esc_attr( esc_attr__( 'Description', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo '' !== $e['datos']['descripcion'] ? esc_html( mb_strimwidth( $e['datos']['descripcion'], 0, 80, '…' ) ) : '<span class="asde-muted">' . esc_html( __( '(empty)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) . '</span>'; ?></td>
							<td data-label="<?php echo esc_attr( esc_attr__( 'Reason', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo esc_html( $e['motivo'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p class="asde-form__acciones">
				<a class="button no-transition" href="<?php echo esc_url( $url_errores ); ?>" download><?php esc_html_e( 'Download the rows with errors (CSV)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a>
				<a class="button asde-button-secundario" href="<?php echo esc_url( $url ); ?>"><?php esc_html_e( 'Import another file', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a>
			</p>
		<?php else : ?>
			<p><?php esc_html_e( 'All the rows have been imported.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
			<p class="asde-form__acciones"><a class="button asde-button-secundario" href="<?php echo esc_url( $url ); ?>"><?php esc_html_e( 'Import another file', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a></p>
		<?php endif; ?>
	</section>
<?php else : ?>
	<section class="asde-card asde-inv-importar">
		<h2 class="asde-card__title"><?php esc_html_e( 'Import equipment from a CSV', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h2>
		<?php if ( $caducado ) : ?>
			<div class="asde-notice asde-notice--warning" role="status"><?php esc_html_e( 'The report of that import is no longer available (it is kept for an hour).', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></div>
		<?php endif; ?>
		<p><?php esc_html_e( 'Prepare the equipment in a spreadsheet, save it as CSV and upload it here. The file is checked first; then the correct rows are imported and you will see those that could not be imported, with the reason.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
		<p>
			<a class="no-transition" href="<?php echo esc_url( $url_ejemplo ); ?>" download><?php esc_html_e( 'Download an example file', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a>
			<span class="asde-muted"><?php esc_html_e( '(open it with Excel or LibreOffice, replace the example rows with your equipment and save it as CSV)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span>
		</p>
		<form method="post" action="<?php echo esc_url( $url ); ?>" enctype="multipart/form-data" class="asde-form">
			<?php wp_nonce_field( 'asde_inventario_importar' ); ?>
			<input type="hidden" name="asde_action" value="material_importar">
			<p>
				<label for="asde-inv-fichero"><?php esc_html_e( 'CSV file', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <span class="asde-obligatorio" aria-hidden="true">*</span></label>
				<input type="file" id="asde-inv-fichero" name="fichero" accept=".csv,text/csv,text/plain" required>
			</p>
			<label class="asde-choice"><input type="checkbox" name="crear_tipos" value="1"><span><?php esc_html_e( 'Create the equipment types that do not exist yet (otherwise those rows are not imported)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span></label>
			<p class="asde-form__acciones">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Import', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
			</p>
		</form>
		<details class="asde-inv-columnas">
			<summary><?php esc_html_e( 'What the file must look like', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></summary>
			<ul>
				<li><?php echo esc_html( sprintf( /* translators: %s: text */ __( 'The first row, the column names: %s. In any order; the ones you don\'t use can be removed.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), implode( ', ', $columnas ) ) ); ?></li>
				<li><?php echo esc_html( sprintf( /* translators: %s: text */ __( 'Required: %s. The quantity, a whole number from 0 upwards.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), implode( ', ', array_map( static fn( $c ) => asde_t( Importador::COLUMNAS[ $c ][0] ), Importador::OBLIGATORIAS ) ) ) ); ?></li>
				<li><?php esc_html_e( 'The price, with a comma or point for the decimals (12,50). The year of purchase, with four digits or blank.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></li>
				<li><?php esc_html_e( 'A serial no. that is already in the inventory (or repeated in the file) is not imported again.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></li>
				<li><?php esc_html_e( 'With no serial no., a row with the same description and the same type as an item already in the inventory (or another row of the file) adds its quantity to that item instead of creating another. Uniform shirts, only if the model and size also match.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></li>
				<li><?php echo esc_html( sprintf( /* translators: 1: number, 2: number */ __( 'Separated by semicolons or commas, up to %1$d MB and %2$d rows.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), (int) ( Importador::MAX_BYTES / MB_IN_BYTES ), Importador::MAX_FILAS ) ); ?></li>
			</ul>
		</details>
	</section>
<?php endif; ?>
