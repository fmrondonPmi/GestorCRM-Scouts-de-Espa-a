<?php
/**
 * Recuperar contraseña.
 *
 * @var bool   $enviado
 * @var string $login     URL del login
 * @var string $solicitar URL de «Solicitar acceso»
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Domain\Identidad;

defined( 'ABSPATH' ) || exit;
?>
<section class="asde-card asde-card--narrow">
	<h2><?php esc_html_e( 'Recover password', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h2>
	<?php if ( $enviado ) : ?>
		<div class="asde-notice asde-notice--success" role="status">
			<?php esc_html_e( 'If the details are correct, in a few minutes you will receive an email with a link to choose a new password (valid for 24 hours) at your account\'s email address.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?>
		</div>
		<p><?php esc_html_e( 'If that email address belongs to several accounts (for example, yours and your family\'s), you will receive a link for each: each email says which user it is for.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
		<p><?php esc_html_e( 'Not receiving it? Check your spam folder. If you don\'t have an account yet,', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <a href="<?php echo esc_url( $solicitar ); ?>"><?php esc_html_e( 'request access', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a>.</p>
		<p><a href="<?php echo esc_url( $login ); ?>"><?php esc_html_e( 'Sign in again', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a></p>
	<?php else : ?>
		<?php $ejemplo = \AsdeBalears\AreaPrivada\Domain\NumeroSocio::ejemplo_tutor(); ?>
		<p><?php esc_html_e( 'Enter your member no. (families, the minor\'s no. followed by a', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <strong>T</strong><?php echo '' !== $ejemplo ? ', p. ej. ' . esc_html( $ejemplo ) : ''; ?><?php esc_html_e( ') or the email address your invitation was sent to.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
		<form method="post" class="asde-form">
			<?php wp_nonce_field( 'asde_recuperar' ); ?>
			<input type="hidden" name="asde_action" value="recuperar">
			<p>
				<label for="asde-usuario"><?php echo esc_html( Identidad::etiqueta_campo() ); ?></label>
				<input type="text" id="asde-usuario" name="usuario" autocomplete="username" autocapitalize="none" autocorrect="off" spellcheck="false" required>
			</p>
			<p><button type="submit" class="button"><?php esc_html_e( 'Send me the link', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button></p>
		</form>
		<p>
			<a href="<?php echo esc_url( $login ); ?>"><?php esc_html_e( 'Sign in again', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a><br>
			<span class="asde-muted"><?php esc_html_e( 'Don\'t have an account yet?', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <a href="<?php echo esc_url( $solicitar ); ?>"><?php esc_html_e( 'Request access', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a>.</span>
		</p>
	<?php endif; ?>
</section>
