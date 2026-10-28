<?php
/**
 * Listas de compra de material del grupo (doc 32).
 *
 * @var array[]            $listas
 * @var string             $estado  Filtro ('' = todas)
 * @var array<string, int> $cuenta  estado => nº de listas
 * @var int                $total
 * @var bool               $aprueba Es de Tesorería
 * @var string             $url
 * @var string             $url_inv
 * @var callable           $url_pdf lista => enlace al PDF
 * @var callable           $usuario id => nombre
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Frontend\Views\InventarioComprasView;
use AsdeBalears\AreaPrivada\Inventario\ComprasTable;

defined( 'ABSPATH' ) || exit;

$fecha = static fn( ?string $f ) => $f ? wp_date( 'd/m/Y', (int) strtotime( $f . ' UTC' ) ) : '';
?>
<section class="asde-card">
	<header class="asde-card__header asde-card__header--split">
		<h2 class="asde-card__title"><?php esc_html_e( 'Equipment shopping lists', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h2>
		<span class="asde-actions asde-actions--botones">
			<a class="button" href="<?php echo esc_url( $url_inv ); ?>"><?php esc_html_e( 'Prepare a list from the inventory', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a>
			<?php // v0.30 (P-63): una lista solo de material que todavía no está en el inventario. ?>
			<a class="button asde-button-secundario" href="<?php echo esc_url( add_query_arg( 'nueva', '1', $url ) ); ?>"><?php esc_html_e( 'List of new equipment', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a>
		</span>
	</header>
	<p class="asde-muted">
		<?php esc_html_e( 'They are prepared by ticking the equipment in the inventory (and writing what is not there yet). Treasury receives an email and approves it; only then can the PDF be downloaded to print. Whoever requested it receives an email with the answer. When the equipment has been bought, the list is marked as bought and what was bought is added to the inventory.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?>
	</p>
	<?php if ( $aprueba && $cuenta[ ComprasTable::PENDIENTE ] ) : ?>
		<div class="asde-notice asde-notice--info" role="status">
			<a href="<?php echo esc_url( add_query_arg( 'estado', ComprasTable::PENDIENTE, $url ) ); ?>"><?php echo esc_html( sprintf( /* translators: %d: number */ _n( '%d list awaits your approval.', '%d lists await your approval.', $cuenta[ ComprasTable::PENDIENTE ] , 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $cuenta[ ComprasTable::PENDIENTE ] ) ); ?></a>
		</div>
	<?php endif; ?>

	<form method="get" action="<?php echo esc_url( $url ); ?>" class="asde-filter asde-filter--una-linea" data-asde-parcial-form="inv-compras">
		<label for="asde-inv-estado"><?php esc_html_e( 'Show', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
		<select id="asde-inv-estado" name="estado" data-asde-autoenviar>
			<option value=""><?php echo esc_html( __( 'All', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) . ' (' . $total . ')' ); ?></option>
			<?php foreach ( ( ComprasTable::estados() ) as $e => $etiqueta ) : ?>
				<option value="<?php echo esc_attr( $e ); ?>" <?php selected( $estado, $e ); ?>><?php echo esc_html( $etiqueta . ' (' . $cuenta[ $e ] . ')' ); ?></option>
			<?php endforeach; ?>
		</select>
		<button type="submit" class="button"><?php esc_html_e( 'View', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
	</form>

	<?php /* v0.31: el filtro actualiza solo la lista (parcial.js). */ ?>
	<div data-asde-parcial="inv-compras">
	<?php if ( ! $listas ) : ?>
		<p><?php esc_html_e( 'There are no shopping lists with this filter.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
	<?php else : ?>
		<table class="asde-table asde-table--inv-compras">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'List', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Requested by', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
					<th scope="col" class="asde-num"><?php esc_html_e( 'Equipment items', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
					<th scope="col" class="asde-num"><?php esc_html_e( 'Estimated total', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Status', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
					<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $listas as $l ) : ?>
					<?php $detalle = add_query_arg( 'lista', $l['id'], $url ); ?>
					<tr>
						<td data-label="<?php echo esc_attr( esc_attr__( 'List', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><a href="<?php echo esc_url( $detalle ); ?>" data-asde-modal><?php echo esc_html( ComprasTable::numero( $l ) ); ?></a><br><span class="asde-muted"><?php echo esc_html( $fecha( $l['creado'] ) ); ?></span></td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Requested by', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo esc_html( $usuario( $l['creado_por'] ) ?: '—' ); ?></td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Equipment items', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>" class="asde-num"><?php echo (int) count( $l['lineas'] ); ?></td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Estimated total', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>" class="asde-num"><?php echo esc_html( InventarioComprasView::total( $l ) ); ?></td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Status', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
							<span class="asde-badge asde-badge--compra-<?php echo esc_attr( $l['estado'] ); ?>"><?php echo esc_html( ( ComprasTable::estados()[ $l['estado'] ] ?? $l['estado'] ) ); ?></span>
							<?php if ( '' !== $l['motivo'] ) : ?>
								<br><span class="asde-muted"><?php echo esc_html( $l['motivo'] ); ?></span>
							<?php endif; ?>
						</td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Actions', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
							<?php if ( in_array( $l['estado'], array( ComprasTable::APROBADA, ComprasTable::COMPRADA ), true ) ) : ?>
								<a href="<?php echo esc_url( $url_pdf( $l ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'PDF to print', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a>
							<?php elseif ( $aprueba && ComprasTable::PENDIENTE === $l['estado'] ) : ?>
								<a href="<?php echo esc_url( $detalle ); ?>" data-asde-modal><?php esc_html_e( 'Review', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
	</div>
</section>
