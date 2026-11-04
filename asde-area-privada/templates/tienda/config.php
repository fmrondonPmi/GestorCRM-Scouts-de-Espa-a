<?php
/**
 * Configuración de la tienda del grupo.
 *
 * @var array  $cfg
 * @var string $url
 * @var string $enlace Enlace de la tienda pública del grupo (v0.39, fase 4)
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.

defined( 'ABSPATH' ) || exit;

?>
<form method="post" enctype="multipart/form-data" class="asde-form" action="<?php echo esc_url( $url ); ?>">
	<?php wp_nonce_field( 'asde_tienda_admin' ); ?>
	<input type="hidden" name="asde_action" value="tienda_config">

	<section class="asde-card">
		<h2 class="asde-card__title"><?php esc_html_e( 'Shop · settings', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h2>
		<label class="asde-choice"><input type="checkbox" name="abierto" value="1" <?php checked( $cfg['abierto'] ); ?>><span><?php esc_html_e( 'Orders are accepted (otherwise families see that they are closed)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span></label>
		<fieldset class="asde-forma-pago">
			<legend><?php esc_html_e( 'Payment methods the group accepts', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></legend>
			<input type="hidden" name="formas_form" value="1">
			<?php foreach ( \AsdeBalears\AreaPrivada\Tienda\PedidosTable::formas() as $forma => $etiqueta ) : ?>
				<label class="asde-choice"><input type="checkbox" name="formas[]" value="<?php echo esc_attr( $forma ); ?>" <?php checked( in_array( $forma, $cfg['formas'], true ) ); ?>><span><?php echo esc_html( $etiqueta ); ?></span></label>
			<?php endforeach; ?>
			<p class="asde-help"><?php esc_html_e( 'Families only see the methods you tick. "In person" is only offered to members, and the card terminal is marked as paid by Treasury on collection.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
		</fieldset>
		<p class="asde-form__fila">
			<label for="asde-uc-iban"><?php esc_html_e( 'Group account (IBAN) for transfers', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <input type="text" id="asde-uc-iban" name="iban" value="<?php echo esc_attr( $cfg['iban'] ); ?>" maxlength="42" autocomplete="off" placeholder="ES00 0000 0000 0000 0000 0000"></label>
			<label for="asde-uc-bizum"><?php esc_html_e( 'The group\'s Bizum phone', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <input type="text" id="asde-uc-bizum" name="bizum" value="<?php echo esc_attr( $cfg['bizum'] ); ?>" maxlength="20" inputmode="tel" autocomplete="off"></label>
		</p>
		<p>
			<label for="asde-uc-texto"><?php esc_html_e( 'How to pay (families see it when ordering)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
			<textarea id="asde-uc-texto" name="texto_pago" rows="3" maxlength="600" placeholder="<?php echo esc_attr( esc_attr__( 'For example: in cash at the meeting place on Saturdays, or by deposit into account ES00 0000… stating the order no.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo esc_textarea( $cfg['texto_pago'] ); ?></textarea>
		</p>
	</section>

	<section class="asde-card">
		<h3 class="asde-card__title"><?php esc_html_e( 'Unpaid orders', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h3>
		<p class="asde-muted"><?php esc_html_e( 'Orders from members and families placed in the shop and not paid: they are reminded by email and, if you say so, are cancelled automatically and what they ordered goes back to the shop. It counts from the day of the order; with 0 it is not done.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
		<p>
			<label for="asde-uc-aviso"><?php esc_html_e( 'Days to remind about payment (0 = do not remind)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
			<input type="number" id="asde-uc-aviso" name="dias_aviso_socios" value="<?php echo (int) $cfg['dias_aviso_socios']; ?>" min="0" max="90" inputmode="numeric">
		</p>
		<p>
			<label for="asde-uc-cancelar"><?php esc_html_e( 'Days to cancel an unpaid order (0 = never)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
			<input type="number" id="asde-uc-cancelar" name="dias_cancelar_socios" value="<?php echo (int) $cfg['dias_cancelar_socios']; ?>" min="0" max="180" inputmode="numeric">
		</p>
	</section>

	<section class="asde-card">
		<h3 class="asde-card__title"><?php esc_html_e( 'Public shop', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h3>
		<p class="asde-muted"><?php esc_html_e( 'With the public shop, anyone with the link can buy without signing in whatever the group has on sale "for everyone" (never "members only"), paying by transfer or Bizum. Their data is stored encrypted and deleted 90 days after the order is delivered or cancelled.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
		<label class="asde-choice"><input type="checkbox" name="publica" value="1" <?php checked( $cfg['publica'] ); ?>><span><?php esc_html_e( 'Open the public shop (purchase without signing in)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span></label>
		<p>
			<label for="asde-uc-dias"><?php esc_html_e( 'Days to pay a purchase made without signing in before cancelling it (0 = never)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
			<input type="number" id="asde-uc-dias" name="dias_sin_pagar" value="<?php echo (int) $cfg['dias_sin_pagar']; ?>" min="0" max="90" inputmode="numeric">
		</p>
		<p>
			<label for="asde-uc-menu"><?php esc_html_e( 'Link in the website menu (text; empty = no link)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
			<input type="text" id="asde-uc-menu" name="menu_tienda" value="<?php echo esc_attr( $cfg['menu_tienda'] ); ?>" maxlength="40" placeholder="<?php echo esc_attr( esc_attr__( 'Shop', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
		</p>
		<?php if ( $cfg['publica'] && '' !== $enlace ) : ?>
			<p><?php esc_html_e( 'Shop link (share it wherever you like):', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?><br><input type="text" readonly value="<?php echo esc_attr( $enlace ); ?>" class="asde-input-ancho" aria-label="<?php echo esc_attr( esc_attr__( 'Shop link', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"></p>
			<p class="asde-help"><?php esc_html_e( 'If the link has been shared where it should not have, create a new one: the previous one stops working (save the changes on this screen first).', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
			<p><button type="submit" class="asde-btn-link" name="asde_action" value="tienda_enlace_renovar" formnovalidate><?php esc_html_e( 'Create a new link', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button></p>
		<?php endif; ?>
	</section>

	<p><button type="submit" class="button"><?php esc_html_e( 'Save', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button></p>
</form>
