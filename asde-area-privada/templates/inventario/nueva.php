<?php
/**
 * Nueva lista de compra con el material marcado en el inventario (doc 32).
 *
 * @var array[]           $materiales
 * @var array<int, array> $tipos
 * @var int               $umbral   Por debajo, el aviso «¿seguro?» (sin JavaScript sale aquí, junto al material)
 * @var array             $borrador Lo escrito la vez anterior, si no se pudo guardar
 * @var int               $perdidos Marcados que ya no están en el inventario
 * @var string            $url
 * @var string            $url_inv
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Tesoreria\Calculadora;

defined( 'ABSPATH' ) || exit;

$euros = static fn( ?float $v ): string => null === $v ? '' : number_format( $v, 2, ',', '' );
?>
<p class="asde-back"><a href="<?php echo esc_url( $url_inv ); ?>"><?php asde_e( '← Inventario' ); ?></a></p>
<section class="asde-card">
	<h2 class="asde-card__title"><?php asde_e( 'Nueva lista de compra' ); ?></h2>
	<?php if ( $perdidos ) : ?>
		<div class="asde-notice asde-notice--warning" role="status"><?php asde_e( 'Algún material marcado ya no está en el inventario y no sale en la lista.' ); ?></div>
	<?php endif; ?>

	<?php if ( ! $materiales ) : ?>
		<p><?php asde_e( 'No has marcado ningún material. Vuelve al inventario y marca lo que hay que comprar.' ); ?></p>
	<?php else : ?>
		<p class="asde-muted"><?php asde_e( 'Indica cuántas unidades hay que comprar de cada material (0 para quitarlo) y su precio estimado. Al guardarla se envía a Tesorería para que le dé el visto bueno; sin él no se puede imprimir.' ); ?></p>
		<form method="post" action="<?php echo esc_url( $url ); ?>" class="asde-form" data-asde-inv-nueva>
			<?php wp_nonce_field( 'asde_inventario_compra_nueva' ); ?>
			<input type="hidden" name="asde_action" value="compra_crear">
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
								<?php if ( 0 === $m['cantidad'] && $umbral > 0 ) : ?>
									<br><span class="asde-inv-aviso" role="note"><?php asde_e( 'Ojo: no queda ninguna unidad en el inventario. ¿Seguro que quieres añadirlo a la lista de compra?' ); ?></span>
								<?php elseif ( $m['cantidad'] < $umbral ) : ?>
									<br><span class="asde-inv-aviso" role="note"><?php echo esc_html( sprintf( asde_n( 'Ojo: en el inventario solo hay %d unidad. ¿Seguro que quieres añadirlo a la lista de compra?', 'Ojo: en el inventario solo hay %d unidades. ¿Seguro que quieres añadirlo a la lista de compra?', $m['cantidad'] ), $m['cantidad'] ) ); ?></span>
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
							<td data-label="<?php echo esc_attr( asde_a( 'Importe' ) ); ?>" class="asde-num" data-asde-inv-importe>
								<?php
								$p = \AsdeBalears\AreaPrivada\Inventario\MaterialesTable::euros( $precio );
								echo is_float( $p ) ? esc_html( Calculadora::euros( $p * (int) $cantidad ) ) : '<span class="asde-muted">—</span>';
								?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
				<tfoot>
					<tr>
						<th scope="row" colspan="4"><?php asde_e( 'Total estimado' ); ?></th>
						<td class="asde-num" data-label="<?php echo esc_attr( asde_a( 'Total estimado' ) ); ?>"><strong data-asde-inv-total><?php asde_e( 'se calcula al guardar' ); ?></strong></td>
					</tr>
				</tfoot>
			</table>
			<p>
				<label for="asde-inv-nota"><?php asde_e( 'Nota para Tesorería (opcional)' ); ?></label>
				<textarea id="asde-inv-nota" name="nota" rows="3" maxlength="500" placeholder="<?php echo esc_attr( asde_a( 'Para qué actividad es, dónde se va a comprar…' ) ); ?>"><?php echo esc_textarea( (string) ( $borrador['nota'] ?? '' ) ); ?></textarea>
			</p>
			<p class="asde-form__acciones">
				<button type="submit" class="button button-primary"><?php asde_e( 'Guardar y enviar a Tesorería' ); ?></button>
			</p>
		</form>
	<?php endif; ?>
</section>
