<?php
/**
 * Elegir contraseña (activación o recuperación).
 *
 * @var WP_User $user
 * @var string  $tipo      alta | reset
 * @var int     $u
 * @var string  $t
 * @var string  $error
 * @var int     $longitud
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
defined( 'ABSPATH' ) || exit;

$reset = 'reset' === $tipo;
?>
<section class="asde-card asde-card--narrow">
	<h2><?php echo $reset ? esc_html( __( 'Choose a new password', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) : esc_html( __( 'Activate your account', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?></h2>
	<p>
		<?php esc_html_e( 'Your username is:', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <strong><?php echo esc_html( $user->user_login ); ?></strong>
		<?php if ( \AsdeBalears\AreaPrivada\Usuarios\Usuarios::email_origen( $user->ID ) ) : ?>
			<br><span class="asde-help"><?php esc_html_e( 'You will also be able to sign in with the email address this link was sent to.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span>
		<?php endif; ?>
	</p>
	<?php if ( ! $reset ) : ?>
		<p><?php esc_html_e( 'Choose a password to sign in to the private area.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
	<?php endif; ?>

	<?php if ( $error ) : ?>
		<div class="asde-notice asde-notice--error" role="alert"><?php echo esc_html( $error ); ?></div>
	<?php endif; ?>

	<form method="post" class="asde-form" autocomplete="off">
		<?php wp_nonce_field( 'asde_activar_' . $u ); ?>
		<input type="hidden" name="asde_action" value="activar">
		<input type="hidden" name="u" value="<?php echo (int) $u; ?>">
		<input type="hidden" name="t" value="<?php echo esc_attr( $t ); ?>">
		<input type="text" name="username" value="<?php echo esc_attr( $user->user_login ); ?>" autocomplete="username" hidden>
		<p>
			<label for="asde-pass1"><?php esc_html_e( 'Password', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
			<input type="password" id="asde-pass1" name="pass1" minlength="<?php echo (int) $longitud; ?>" autocomplete="new-password" required>
			<span class="asde-help"><?php esc_html_e( 'Minimum', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <?php echo (int) $longitud; ?> <?php esc_html_e( 'characters. Better a phrase that is easy for you to remember and hard to guess.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span>
		</p>
		<p>
			<label for="asde-pass2"><?php esc_html_e( 'Repeat the password', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
			<input type="password" id="asde-pass2" name="pass2" minlength="<?php echo (int) $longitud; ?>" autocomplete="new-password" required>
		</p>
		<p><button type="submit" class="button"><?php echo $reset ? esc_html( __( 'Save password', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) : esc_html( __( 'Activate my account', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?></button></p>
	</form>
</section>
