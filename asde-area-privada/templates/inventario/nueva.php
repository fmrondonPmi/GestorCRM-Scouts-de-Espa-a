<?php
/**
 * Nueva lista de compra con el material marcado en el inventario (doc 32) y, desde la
 * v0.30 (P-63), material nuevo que todavía no está en el inventario.
 *
 * @var array[]           $materiales
 * @var array[]           $nuevos   v0.30: filas de material nuevo (descripcion, cantidad, precio); la última, en blanco
 * @var array<int, array> $tipos
 * (El aviso «¿seguro que hace falta comprar más?» sale junto al material desde Servicio::umbral(); P-62, sin JavaScript.)
 * @var array             $borrador Lo escrito la vez anterior, si no se pudo guardar
 * @var int               $perdidos Marcados que ya no están en el inventario
 * @var string            $url
 * @var string            $url_inv
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Inventario\MaterialesTable;
use AsdeBalears\AreaPrivada\Inventario\Servicio;
use AsdeBalears\AreaPrivada\Tesoreria\Calculadora;

defined( 'ABSPATH' ) || exit;

$euros   = static fn( ?float $v ): string => null === $v ? '' : number_format( $v, 2, ',', '' );
$importe = static function ( string $precio, string $cantidad ): string {
	$p = MaterialesTable::euros( $precio );
	return is_float( $p ) ? esc_html( Calculadora::euros( $p * (int) $cantidad ) ) : '<span class="asde-muted">—</span>';
};
?>
<p class="asde-back"><a href="<?php echo esc_url( $url_inv ); ?>"><?php esc_html_e( '← Inventory', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a></p>
<section class="asde-card">
	<h2 class="asde-card__title"><?php esc_html_e( 'New shopping list', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h2>
	<?php if ( $perdidos ) : ?>
		<div class="asde-notice asde-notice--warning" role="status"><?php esc_html_e( 'Some ticked equipment is no longer in the inventory and does not appear on the list.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></div>
	<?php endif; ?>

	<p class="asde-muted"><?php esc_html_e( 'State how many units of each item must be bought (0 to remove it) and its estimated price. When saved, it is sent to Treasury for approval; without it, it cannot be printed.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
	<form method="post" action="<?php echo esc_url( $url ); ?>" class="asde-form" data-asde-inv-nueva>
		<?php wp_nonce_field( 'asde_inventario_compra_nueva' ); ?>
		<input type="hidden" name="asde_action" value="compra_crear">

		<?php if ( ! $materiales ) : ?>
			<p><?php esc_html_e( 'You have not ticked any equipment from the inventory. You can prepare the list with new equipment only (below) or go back to the inventory and tick what needs to be bought.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
		<?php else : ?>
			<table class="asde-table asde-table--inv-nueva">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Equipment', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
						<th scope="col" class="asde-num"><?php esc_html_e( 'In inventory', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
						<th scope="col"><?php esc_html_e( 'To buy', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Price per unit (€)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
						<th scope="col" class="asde-num"><?php esc_html_e( 'Amount', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $materiales as $m ) : ?>
						<?php
						$id       = (int) $m['id'];
						$cantidad = (string) ( $borrador['cantidad'][ $id ] ?? '1' );
						$precio   = (string) ( $borrador['precio'][ $id ] ?? $euros( $m['precio'] ) );
						?>
						<tr data-asde-inv-linea>
							<td data-label="<?php echo esc_attr( esc_attr__( 'Equipment', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
								<strong><?php echo esc_html( $m['descripcion'] ); ?></strong>
								<?php
								$extra = array_filter( array( $tipos[ $m['tipo'] ]['nombre'] ?? '', '' !== $m['num_serie'] ? sprintf( /* translators: %s: text */ __( 'Serial no. %s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $m['num_serie'] ) : '' ) );
								echo $extra ? '<br><span class="asde-muted">' . esc_html( implode( ' · ', $extra ) ) . '</span>' : '';
								?>
								<?php if ( Servicio::ya_hay_bastantes( (int) $m['cantidad'] ) ) : ?>
									<?php // P-62 (v0.30): se avisa cuando ya hay bastantes, no cuando hay pocas. ?>
									<br><span class="asde-inv-aviso" role="note"><?php echo esc_html( sprintf( /* translators: %d: number */ _n( 'Careful: there is already %d unit in the inventory. Do more really need to be bought?', 'Careful: there are already %d units in the inventory. Do more really need to be bought?', $m['cantidad'] , 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $m['cantidad'] ) ); ?></span>
								<?php endif; ?>
							</td>
							<td data-label="<?php echo esc_attr( esc_attr__( 'In inventory', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>" class="asde-num"><?php echo (int) $m['cantidad']; ?></td>
							<td data-label="<?php echo esc_attr( esc_attr__( 'To buy', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
								<label class="screen-reader-text" for="asde-inv-c-<?php echo (int) $id; ?>"><?php echo esc_html( sprintf( /* translators: %s: text */ __( 'Units of "%s" to buy', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $m['descripcion'] ) ); ?></label>
								<input type="number" id="asde-inv-c-<?php echo (int) $id; ?>" name="cantidad[<?php echo (int) $id; ?>]" value="<?php echo esc_attr( $cantidad ); ?>" min="0" max="99999" step="1" inputmode="numeric" class="asde-inv-num" data-asde-inv-cantidad>
							</td>
							<td data-label="<?php echo esc_attr( esc_attr__( 'Price per unit (€)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
								<label class="screen-reader-text" for="asde-inv-p-<?php echo (int) $id; ?>"><?php echo esc_html( sprintf( /* translators: %s: text */ __( 'Price of "%s"', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $m['descripcion'] ) ); ?></label>
								<input type="text" id="asde-inv-p-<?php echo (int) $id; ?>" name="precio[<?php echo (int) $id; ?>]" value="<?php echo esc_attr( $precio ); ?>" inputmode="decimal" size="8" placeholder="0,00" class="asde-inv-num" data-asde-inv-precio>
							</td>
							<td data-label="<?php echo esc_attr( esc_attr__( 'Amount', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>" class="asde-num" data-asde-inv-importe><?php echo wp_kses( $importe( $precio, $cantidad ), asde_html_permitido() ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<?php // v0.30 (P-63): material que todavía no está en el inventario; se da de alta al marcar la lista como comprada. ?>
		<h3 class="asde-card__title" id="asde-inv-nuevos"><?php esc_html_e( 'New equipment (not in the inventory)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h3>
		<p class="asde-muted"><?php esc_html_e( 'If something needs to be bought that is not yet in the inventory, write it here. When the list is marked as bought, it will be registered in the inventory.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
		<table class="asde-table asde-table--inv-nueva asde-table--inv-nuevo">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Description', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
					<th scope="col"><?php esc_html_e( 'To buy', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Price per unit (€)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
					<th scope="col" class="asde-num"><?php esc_html_e( 'Amount', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
				</tr>
			</thead>
			<tbody data-asde-inv-nuevos>
				<?php foreach ( $nuevos as $i => $n ) : ?>
					<?php
					$i        = (int) $i;
					$cantidad = (string) ( $n['cantidad'] ?? '1' );
					$precio   = (string) ( $n['precio'] ?? '' );
					$vacia    = '' === trim( (string) ( $n['descripcion'] ?? '' ) );
					?>
					<tr data-asde-inv-linea data-asde-inv-nuevo>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Description', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
							<?php /* translators: %d: line number */ ?>
							<label class="screen-reader-text" for="asde-inv-nd-<?php echo (int) $i; ?>"><?php echo esc_html( sprintf( /* translators: %d: number */ __( 'New equipment, line %d', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $i + 1 ) ); ?></label>
							<input type="text" id="asde-inv-nd-<?php echo (int) $i; ?>" name="nuevo_desc[<?php echo (int) $i; ?>]" value="<?php echo esc_attr( (string) ( $n['descripcion'] ?? '' ) ); ?>" maxlength="255" placeholder="<?php echo esc_attr( esc_attr__( 'What needs to be bought', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>" data-asde-inv-desc>
						</td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'To buy', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
							<?php /* translators: %d: line number */ ?>
							<label class="screen-reader-text" for="asde-inv-nc-<?php echo (int) $i; ?>"><?php echo esc_html( sprintf( /* translators: %d: number */ __( 'Units to buy, line %d', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $i + 1 ) ); ?></label>
							<input type="number" id="asde-inv-nc-<?php echo (int) $i; ?>" name="nuevo_cantidad[<?php echo (int) $i; ?>]" value="<?php echo esc_attr( $cantidad ); ?>" min="0" max="99999" step="1" inputmode="numeric" class="asde-inv-num" data-asde-inv-cantidad>
						</td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Price per unit (€)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
							<?php /* translators: %d: line number */ ?>
							<label class="screen-reader-text" for="asde-inv-np-<?php echo (int) $i; ?>"><?php echo esc_html( sprintf( /* translators: %d: number */ __( 'Estimated price, line %d (optional)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $i + 1 ) ); ?></label>
							<input type="text" id="asde-inv-np-<?php echo (int) $i; ?>" name="nuevo_precio[<?php echo (int) $i; ?>]" value="<?php echo esc_attr( $precio ); ?>" inputmode="decimal" size="8" placeholder="<?php echo esc_attr( esc_attr__( 'optional', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>" class="asde-inv-num" data-asde-inv-precio>
						</td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Amount', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>" class="asde-num" data-asde-inv-importe><?php echo wp_kses( $vacia ? '<span class="asde-muted">—</span>' : $importe( $precio, $cantidad ), asde_html_permitido() ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<p>
			<?php // Sin JavaScript vuelve a este formulario con una fila más (y lo escrito); con él, la fila se añade aquí mismo (inventario.js). ?>
			<button type="submit" name="mas_lineas" value="1" class="button asde-button-secundario" formnovalidate data-asde-inv-otra><?php esc_html_e( 'Add another line', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
		</p>

		<p class="asde-inv-total">
			<?php esc_html_e( 'Estimated total:', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <strong data-asde-inv-total><?php esc_html_e( 'calculated on saving', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></strong>
		</p>
		<p>
			<label for="asde-inv-nota"><?php esc_html_e( 'Note for Treasury (optional)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
			<textarea id="asde-inv-nota" name="nota" rows="3" maxlength="500" placeholder="<?php echo esc_attr( esc_attr__( 'What activity it is for, where it will be bought…', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo esc_textarea( (string) ( $borrador['nota'] ?? '' ) ); ?></textarea>
		</p>
		<p class="asde-form__acciones">
			<button type="submit" class="button button-primary" data-asde-inv-guardar><?php esc_html_e( 'Save and send to Treasury', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
		</p>
	</form>
</section>
