<?php
/**
 * Nueva lista de compra con el material marcado en el inventario (doc 32) y, desde la
 * v0.30 (P-63, doc 37), material nuevo que todavía no está en el inventario.
 *
 * @var array[]           $materiales
 * @var array[]           $nuevos   v0.30: filas de material nuevo (descripcion, cantidad, precio); la última, en blanco
 * @var array<int, array> $tipos
 * @var int               $umbral   Desde esta cantidad, el aviso «¿seguro que hace falta comprar más?» (P-62; sin JavaScript sale aquí, junto al material)
 * @var array             $borrador Lo escrito la vez anterior, si no se pudo guardar
 * @var int               $perdidos Marcados que ya no están en el inventario
 * @var string            $url
 * @var string            $url_inv
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Inventario\MaterialesTable;
use AsdeBalears\AreaPrivada\Tesoreria\Calculadora;

defined( 'ABSPATH' ) || exit;

$euros   = static fn( ?float $v ): string => null === $v ? '' : number_format( $v, 2, ',', '' );
$importe = static function ( string $precio, string $cantidad ): string {
	$p = MaterialesTable::euros( $precio );
	return is_float( $p ) ? esc_html( Calculadora::euros( $p * (int) $cantidad ) ) : '<span class="asde-muted">—</span>';
};
?>
<p class="asde-back"><a href="<?php echo esc_url( $url_inv ); ?>"><?php asde_e( '← Inventario' ); ?></a></p>
<section class="asde-card">
	<h2 class="asde-card__title"><?php asde_e( 'Nueva lista de compra' ); ?></h2>
	<?php if ( $perdidos ) : ?>
		<div class="asde-notice asde-notice--warning" role="status"><?php asde_e( 'Algún material marcado ya no está en el inventario y no sale en la lista.' ); ?></div>
	<?php endif; ?>

	<p class="asde-muted"><?php asde_e( 'Indica cuántas unidades hay que comprar de cada material (0 para quitarlo) y su precio estimado. Al guardarla se envía a Tesorería para que le dé el visto bueno; sin él no se puede imprimir.' ); ?></p>
	<form method="post" action="<?php echo esc_url( $url ); ?>" class="asde-form" data-asde-inv-nueva>
		<?php wp_nonce_field( 'asde_inventario_compra_nueva' ); ?>
		<input type="hidden" name="asde_action" value="compra_crear">

		<?php if ( ! $materiales ) : ?>
			<p><?php asde_e( 'No has marcado ningún material del inventario. Puedes preparar la lista solo con material nuevo (aquí debajo) o volver al inventario y marcar lo que hay que comprar.' ); ?></p>
		<?php else : ?>
			<table class="asde-table asde-table--inv-nueva">
				<thead>
					<tr>
						<th scope="col"><?php asde_e( 'Material' ); ?></th>
						<th scope="col" class="asde-num"><?php asde_e( 'En inventario' ); ?></th>
						<th scope="col"><?php asde_e( 'A comprar' ); ?></th>
						<th scope="col"><?php asde_e( 'Precio por unidad (€)' ); ?></th>
						<th scope="col" class="asde-num"><?php asde_e( 'Importe' ); ?></th>
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
							<td data-label="<?php echo esc_attr( asde_a( 'Material' ) ); ?>">
								<strong><?php echo esc_html( $m['descripcion'] ); ?></strong>
								<?php
								$extra = array_filter( array( $tipos[ $m['tipo'] ]['nombre'] ?? '', '' !== $m['num_serie'] ? sprintf( asde_t( 'Nº de serie %s' ), $m['num_serie'] ) : '' ) );
								echo $extra ? '<br><span class="asde-muted">' . esc_html( implode( ' · ', $extra ) ) . '</span>' : '';
								?>
								<?php if ( $umbral > 0 && $m['cantidad'] >= $umbral ) : ?>
									<?php // P-62 (v0.30): se avisa cuando ya hay bastantes, no cuando hay pocas. ?>
									<br><span class="asde-inv-aviso" role="note"><?php echo esc_html( sprintf( asde_n( 'Ojo: ya hay %d unidad en el inventario. ¿Seguro que hace falta comprar más?', 'Ojo: ya hay %d unidades en el inventario. ¿Seguro que hace falta comprar más?', $m['cantidad'] ), $m['cantidad'] ) ); ?></span>
								<?php endif; ?>
							</td>
							<td data-label="<?php echo esc_attr( asde_a( 'En inventario' ) ); ?>" class="asde-num"><?php echo (int) $m['cantidad']; ?></td>
							<td data-label="<?php echo esc_attr( asde_a( 'A comprar' ) ); ?>">
								<label class="screen-reader-text" for="asde-inv-c-<?php echo (int) $id; ?>"><?php echo esc_html( sprintf( asde_t( 'Unidades de «%s» a comprar' ), $m['descripcion'] ) ); ?></label>
								<input type="number" id="asde-inv-c-<?php echo (int) $id; ?>" name="cantidad[<?php echo (int) $id; ?>]" value="<?php echo esc_attr( $cantidad ); ?>" min="0" max="99999" step="1" inputmode="numeric" class="asde-inv-num" data-asde-inv-cantidad>
							</td>
							<td data-label="<?php echo esc_attr( asde_a( 'Precio por unidad (€)' ) ); ?>">
								<label class="screen-reader-text" for="asde-inv-p-<?php echo (int) $id; ?>"><?php echo esc_html( sprintf( asde_t( 'Precio de «%s»' ), $m['descripcion'] ) ); ?></label>
								<input type="text" id="asde-inv-p-<?php echo (int) $id; ?>" name="precio[<?php echo (int) $id; ?>]" value="<?php echo esc_attr( $precio ); ?>" inputmode="decimal" size="8" placeholder="0,00" class="asde-inv-num" data-asde-inv-precio>
							</td>
							<td data-label="<?php echo esc_attr( asde_a( 'Importe' ) ); ?>" class="asde-num" data-asde-inv-importe><?php echo $importe( $precio, $cantidad ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escapado en $importe ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<?php // v0.30 (P-63): material que todavía no está en el inventario; se da de alta al marcar la lista como comprada. ?>
		<h3 class="asde-card__title" id="asde-inv-nuevos"><?php asde_e( 'Material nuevo (no está en el inventario)' ); ?></h3>
		<p class="asde-muted"><?php asde_e( 'Si hay que comprar algo que todavía no está en el inventario, escríbelo aquí. Cuando la lista se marque como comprada, se dará de alta en el inventario.' ); ?></p>
		<table class="asde-table asde-table--inv-nueva asde-table--inv-nuevo">
			<thead>
				<tr>
					<th scope="col"><?php asde_e( 'Descripción' ); ?></th>
					<th scope="col"><?php asde_e( 'A comprar' ); ?></th>
					<th scope="col"><?php asde_e( 'Precio por unidad (€)' ); ?></th>
					<th scope="col" class="asde-num"><?php asde_e( 'Importe' ); ?></th>
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
						<td data-label="<?php echo esc_attr( asde_a( 'Descripción' ) ); ?>">
							<?php /* translators: %d: nº de la línea */ ?>
							<label class="screen-reader-text" for="asde-inv-nd-<?php echo (int) $i; ?>"><?php echo esc_html( sprintf( asde_t( 'Material nuevo, línea %d' ), $i + 1 ) ); ?></label>
							<input type="text" id="asde-inv-nd-<?php echo (int) $i; ?>" name="nuevo_desc[<?php echo (int) $i; ?>]" value="<?php echo esc_attr( (string) ( $n['descripcion'] ?? '' ) ); ?>" maxlength="255" placeholder="<?php echo esc_attr( asde_a( 'Qué hay que comprar' ) ); ?>" data-asde-inv-desc>
						</td>
						<td data-label="<?php echo esc_attr( asde_a( 'A comprar' ) ); ?>">
							<?php /* translators: %d: nº de la línea */ ?>
							<label class="screen-reader-text" for="asde-inv-nc-<?php echo (int) $i; ?>"><?php echo esc_html( sprintf( asde_t( 'Unidades a comprar, línea %d' ), $i + 1 ) ); ?></label>
							<input type="number" id="asde-inv-nc-<?php echo (int) $i; ?>" name="nuevo_cantidad[<?php echo (int) $i; ?>]" value="<?php echo esc_attr( $cantidad ); ?>" min="0" max="99999" step="1" inputmode="numeric" class="asde-inv-num" data-asde-inv-cantidad>
						</td>
						<td data-label="<?php echo esc_attr( asde_a( 'Precio por unidad (€)' ) ); ?>">
							<?php /* translators: %d: nº de la línea */ ?>
							<label class="screen-reader-text" for="asde-inv-np-<?php echo (int) $i; ?>"><?php echo esc_html( sprintf( asde_t( 'Precio estimado, línea %d (opcional)' ), $i + 1 ) ); ?></label>
							<input type="text" id="asde-inv-np-<?php echo (int) $i; ?>" name="nuevo_precio[<?php echo (int) $i; ?>]" value="<?php echo esc_attr( $precio ); ?>" inputmode="decimal" size="8" placeholder="<?php echo esc_attr( asde_a( 'opcional' ) ); ?>" class="asde-inv-num" data-asde-inv-precio>
						</td>
						<td data-label="<?php echo esc_attr( asde_a( 'Importe' ) ); ?>" class="asde-num" data-asde-inv-importe><?php echo $vacia ? '<span class="asde-muted">—</span>' : $importe( $precio, $cantidad ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escapado en $importe ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<p>
			<?php // Sin JavaScript vuelve a este formulario con una fila más (y lo escrito); con él, la fila se añade aquí mismo (inventario.js). ?>
			<button type="submit" name="mas_lineas" value="1" class="button asde-button-secundario" formnovalidate data-asde-inv-otra><?php asde_e( 'Añadir otra línea' ); ?></button>
		</p>

		<p class="asde-inv-total">
			<?php asde_e( 'Total estimado:' ); ?> <strong data-asde-inv-total><?php asde_e( 'se calcula al guardar' ); ?></strong>
		</p>
		<p>
			<label for="asde-inv-nota"><?php asde_e( 'Nota para Tesorería (opcional)' ); ?></label>
			<textarea id="asde-inv-nota" name="nota" rows="3" maxlength="500" placeholder="<?php echo esc_attr( asde_a( 'Para qué actividad es, dónde se va a comprar…' ) ); ?>"><?php echo esc_textarea( (string) ( $borrador['nota'] ?? '' ) ); ?></textarea>
		</p>
		<p class="asde-form__acciones">
			<button type="submit" class="button button-primary" data-asde-inv-guardar><?php asde_e( 'Guardar y enviar a Tesorería' ); ?></button>
		</p>
	</form>
</section>
