<?php
/**
 * Tipos de material del inventario (doc 32). También se abre en la ventana emergente.
 *
 * @var array<int, array> $tipos  id => {id, nombre}
 * @var array<int, int>   $usos   id => nº de materiales que lo usan
 * @var string            $url
 * @var string            $volver Formulario del que se viene (sin JavaScript, el «+»), o ''
 */
defined( 'ABSPATH' ) || exit;
?>
<?php if ( '' !== $volver ) : ?>
	<p class="asde-back asde-solo-pagina"><a href="<?php echo esc_url( $volver ); ?>"><?php asde_e( '← Volver al material' ); ?></a></p>
<?php endif; ?>
<section class="asde-card">
	<h2 class="asde-card__title"><?php asde_e( 'Tipos de material' ); ?></h2>
	<p class="asde-muted"><?php asde_e( 'Sirven para ordenar y filtrar el inventario (tiendas, cocina, herramientas…). Un tipo que usa algún material no se puede borrar, pero sí cambiarle el nombre.' ); ?></p>

	<form method="post" action="<?php echo esc_url( $url ); ?>" class="asde-form__fila asde-form__fila--compacta">
		<?php wp_nonce_field( 'asde_inventario_tipos' ); ?>
		<input type="hidden" name="asde_action" value="tipo_crear">
		<input type="hidden" name="volver" value="<?php echo esc_url( $volver ); ?>">
		<label for="asde-inv-tipo-nuevo" class="screen-reader-text"><?php asde_e( 'Nombre del tipo nuevo' ); ?></label>
		<input type="text" id="asde-inv-tipo-nuevo" name="nombre" maxlength="80" required placeholder="<?php echo esc_attr( asde_a( 'Nombre del tipo nuevo' ) ); ?>">
		<button type="submit" class="button button-primary"><?php asde_e( 'Crear tipo' ); ?></button>
	</form>

	<?php if ( ! $tipos ) : ?>
		<p><?php asde_e( 'Todavía no hay ningún tipo.' ); ?></p>
	<?php else : ?>
		<table class="asde-table asde-table--inv-tipos">
			<thead>
				<tr>
					<th scope="col"><?php asde_e( 'Nombre' ); ?></th>
					<th scope="col" class="asde-num"><?php asde_e( 'Materiales' ); ?></th>
					<th scope="col"><span class="screen-reader-text"><?php asde_e( 'Acciones' ); ?></span></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $tipos as $t ) : ?>
					<?php $n = (int) ( $usos[ $t['id'] ] ?? 0 ); ?>
					<tr>
						<td data-label="<?php echo esc_attr( asde_a( 'Nombre' ) ); ?>">
							<form method="post" action="<?php echo esc_url( $url ); ?>" class="asde-form__fila asde-form__fila--compacta">
								<?php wp_nonce_field( 'asde_inventario_tipos' ); ?>
								<input type="hidden" name="asde_action" value="tipo_renombrar">
								<input type="hidden" name="tipo" value="<?php echo (int) $t['id']; ?>">
								<input type="hidden" name="volver" value="<?php echo esc_url( $volver ); ?>">
								<label class="screen-reader-text" for="asde-inv-tipo-<?php echo (int) $t['id']; ?>"><?php echo esc_html( sprintf( asde_t( 'Nombre del tipo «%s»' ), $t['nombre'] ) ); ?></label>
								<input type="text" id="asde-inv-tipo-<?php echo (int) $t['id']; ?>" name="nombre" maxlength="80" required value="<?php echo esc_attr( $t['nombre'] ); ?>">
								<button type="submit" class="asde-btn-link"><?php asde_e( 'Cambiar el nombre' ); ?></button>
							</form>
						</td>
						<td data-label="<?php echo esc_attr( asde_a( 'Materiales' ) ); ?>" class="asde-num"><?php echo (int) $n; ?></td>
						<td data-label="<?php echo esc_attr( asde_a( 'Acciones' ) ); ?>">
							<?php if ( ! $n ) : ?>
								<form method="post" action="<?php echo esc_url( $url ); ?>">
									<?php wp_nonce_field( 'asde_inventario_tipos' ); ?>
									<input type="hidden" name="asde_action" value="tipo_borrar">
									<input type="hidden" name="tipo" value="<?php echo (int) $t['id']; ?>">
									<input type="hidden" name="volver" value="<?php echo esc_url( $volver ); ?>">
									<button type="submit" class="asde-btn-link"><?php asde_e( 'Borrar' ); ?></button>
								</form>
							<?php else : ?>
								<span class="asde-muted"><?php asde_e( 'En uso' ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</section>
