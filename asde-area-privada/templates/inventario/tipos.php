<?php
/**
 * Tipos de material del inventario (doc 32). También se abre en la ventana emergente.
 *
 * @var array<int, array> $tipos  id => {id, nombre}
 * @var array<int, int>   $usos   id => nº de materiales que lo usan
 * @var string            $url
 * @var string            $volver Formulario del que se viene (sin JavaScript, el «+»), o ''
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
defined( 'ABSPATH' ) || exit;
?>
<?php if ( '' !== $volver ) : ?>
	<p class="asde-back asde-solo-pagina"><a href="<?php echo esc_url( $volver ); ?>"><?php esc_html_e( '← Back to the equipment', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a></p>
<?php endif; ?>
<section class="asde-card">
	<h2 class="asde-card__title"><?php esc_html_e( 'Equipment types', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h2>
	<p class="asde-muted"><?php esc_html_e( 'They are used to sort and filter the inventory (tents, kitchen, tools…). A type used by any equipment cannot be deleted, but it can be renamed.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>

	<form method="post" action="<?php echo esc_url( $url ); ?>" class="asde-form__fila asde-form__fila--compacta">
		<?php wp_nonce_field( 'asde_inventario_tipos' ); ?>
		<input type="hidden" name="asde_action" value="tipo_crear">
		<input type="hidden" name="volver" value="<?php echo esc_url( $volver ); ?>">
		<label for="asde-inv-tipo-nuevo" class="screen-reader-text"><?php esc_html_e( 'Name of the new type', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
		<input type="text" id="asde-inv-tipo-nuevo" name="nombre" maxlength="80" required placeholder="<?php echo esc_attr( esc_attr__( 'Name of the new type', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
		<button type="submit" class="button button-primary"><?php esc_html_e( 'Create type', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
	</form>

	<?php if ( ! $tipos ) : ?>
		<p><?php esc_html_e( 'There is no type yet.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
	<?php else : ?>
		<table class="asde-table asde-table--inv-tipos" data-asde-paginar>
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Name', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
					<th scope="col" class="asde-num"><?php esc_html_e( 'Equipment items', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
					<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $tipos as $t ) : ?>
					<?php $n = (int) ( $usos[ $t['id'] ] ?? 0 ); ?>
					<tr>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Name', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
							<form method="post" action="<?php echo esc_url( $url ); ?>" class="asde-form__fila asde-form__fila--compacta">
								<?php wp_nonce_field( 'asde_inventario_tipos' ); ?>
								<input type="hidden" name="asde_action" value="tipo_renombrar">
								<input type="hidden" name="tipo" value="<?php echo (int) $t['id']; ?>">
								<input type="hidden" name="volver" value="<?php echo esc_url( $volver ); ?>">
								<label class="screen-reader-text" for="asde-inv-tipo-<?php echo (int) $t['id']; ?>"><?php echo esc_html( sprintf( /* translators: %s: text */ __( 'Name of the type "%s"', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $t['nombre'] ) ); ?></label>
								<input type="text" id="asde-inv-tipo-<?php echo (int) $t['id']; ?>" name="nombre" maxlength="80" required value="<?php echo esc_attr( $t['nombre'] ); ?>">
								<button type="submit" class="asde-btn-link"><?php esc_html_e( 'Rename', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
							</form>
						</td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Equipment items', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>" class="asde-num"><?php echo (int) $n; ?></td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Actions', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
							<?php if ( ! $n ) : ?>
								<form method="post" action="<?php echo esc_url( $url ); ?>">
									<?php wp_nonce_field( 'asde_inventario_tipos' ); ?>
									<input type="hidden" name="asde_action" value="tipo_borrar">
									<input type="hidden" name="tipo" value="<?php echo (int) $t['id']; ?>">
									<input type="hidden" name="volver" value="<?php echo esc_url( $volver ); ?>">
									<button type="submit" class="asde-btn-link"><?php esc_html_e( 'Delete', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
								</form>
							<?php else : ?>
								<span class="asde-muted"><?php esc_html_e( 'In use', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</section>
