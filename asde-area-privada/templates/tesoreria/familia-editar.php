<?php
/**
 * Editar los socios de una familia.
 *
 * @var array{id:int, numero:string, miembros:string[]}       $familia
 * @var string                                                $no_borrar '' si se puede eliminar; si no, el motivo (v0.35.1)
 * @var array<string, AsdeBalears\AreaPrivada\Domain\Persona> $personas
 * @var AsdeBalears\AreaPrivada\Domain\Persona[]              $sin_fam
 * @var ?WP_User                                              $cuenta
 * @var string                                                $url
 * @var string                                                $url_pago
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Domain\Secciones;

defined( 'ABSPATH' ) || exit;
?>
<p class="asde-back"><a href="<?php echo esc_url( $url ); ?>"><?php esc_html_e( '← Back to families', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a></p>

<section class="asde-card">
	<header class="asde-card__header asde-card__header--split">
		<h2 class="asde-card__title"><?php esc_html_e( 'Family', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <?php echo esc_html( $familia['numero'] ); ?></h2>
		<a class="button asde-button-secundario" href="<?php echo esc_url( $url_pago ); ?>"><?php esc_html_e( 'View payments', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a>
	</header>
	<?php if ( $cuenta ) : ?>
		<p class="asde-muted"><?php esc_html_e( 'Private area family account:', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <strong><?php echo esc_html( $cuenta->user_login ); ?></strong> <?php esc_html_e( '(access is managed in "People" and "Users").', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
	<?php endif; ?>

	<?php if ( ! $familia['miembros'] ) : ?>
		<p><?php esc_html_e( 'The family has no members.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
	<?php else : ?>
		<table class="asde-table">
			<tbody>
				<?php foreach ( $familia['miembros'] as $n ) : ?>
					<?php $p = $personas[ $n ] ?? null; ?>
					<tr>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Member', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
							<?php echo esc_html( $p ? $p->nombre_completo() : __( 'Scouts de España no. ', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) . $n ); ?><?php echo wp_kses( $p ? \AsdeBalears\AreaPrivada\Socios\Numeros::compacto( $p ) : '', asde_html_permitido() ); ?>
							<?php
							if ( $p ) {
								foreach ( $p->secciones as $s ) {
									echo wp_kses( Secciones::badge( $s ), asde_html_permitido() );
								}
							}
							?>
							<?php echo ( ! $p || ! $p->tiene_membresia_activa() ) ? '<br><span class="asde-help asde-help--warning">' . esc_html( __( 'No active membership: does not count for the fee', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) . '</span>' : ''; ?>
						</td>
						<td data-label="">
							<form method="post" data-asde-confirmar="<?php echo esc_attr( __( 'Remove this member from the family?', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
								<?php wp_nonce_field( 'asde_familias' ); ?>
								<input type="hidden" name="asde_action" value="quitar">
								<input type="hidden" name="familia" value="<?php echo (int) $familia['id']; ?>">
								<input type="hidden" name="socio" value="<?php echo esc_attr( $n ); ?>">
								<button type="submit" class="asde-btn-link asde-btn-danger"><?php esc_html_e( 'Remove', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</section>

<section class="asde-card">
	<h3><?php esc_html_e( 'Add a member', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h3>
	<?php if ( ! $sin_fam ) : ?>
		<p><?php esc_html_e( 'There are no active members without a family.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
	<?php else : ?>
		<form method="post" class="asde-filter asde-filter--una-linea">
			<?php wp_nonce_field( 'asde_familias' ); ?>
			<input type="hidden" name="asde_action" value="anadir">
			<input type="hidden" name="familia" value="<?php echo (int) $familia['id']; ?>">
			<label for="asde-socio-anadir" class="screen-reader-text"><?php esc_html_e( 'Member', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
			<select id="asde-socio-anadir" name="socio" required class="asde-combobox" data-min="3" data-placeholder="<?php echo esc_attr( esc_attr__( 'Search by first name or surname (from 3 letters)…', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
				<option value=""><?php esc_html_e( 'Choose a member without a family…', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></option>
				<?php foreach ( $sin_fam as $p ) : ?>
					<option value="<?php echo esc_attr( $p->num_socio_asde ); ?>"><?php echo esc_html( $p->apellidos . ', ' . $p->nombre . ( $p->secciones ? ' · ' . Secciones::nombre( $p->secciones[0] ) : '' ) ); ?></option>
				<?php endforeach; ?>
			</select>
			<button type="submit" class="button"><?php esc_html_e( 'Add', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
		</form>
	<?php endif; ?>
</section>

<?php if ( ! $familia['miembros'] ) : ?>
	<section class="asde-card">
		<?php if ( '' === $no_borrar ) : ?>
			<form method="post" data-asde-confirmar="<?php echo esc_attr( sprintf( /* translators: %s: text */ __( 'Delete family %s? It has no members. This cannot be undone.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $familia['numero'] ) ); ?>">
				<?php wp_nonce_field( 'asde_familias' ); ?>
				<input type="hidden" name="asde_action" value="borrar">
				<input type="hidden" name="familia" value="<?php echo (int) $familia['id']; ?>">
				<button type="submit" class="asde-btn-link asde-btn-danger"><?php esc_html_e( 'Delete this family', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
			</form>
		<?php else : ?>
			<p class="asde-help"><?php echo esc_html( $no_borrar ); ?></p>
		<?php endif; ?>
	</section>
<?php endif; ?>
