<?php
/**
 * Seguridad de la cuenta (verificación en dos pasos de Wordfence).
 *
 * @var bool   $disponible
 * @var bool   $activo
 * @var bool   $pendiente
 * @var ?int   $limite
 * @var string $wordfence  HTML del gestor de Wordfence
 */
defined( 'ABSPATH' ) || exit;
?>
<section class="asde-card">
	<h2><?php esc_html_e( 'Account security', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h2>
	<?php if ( ! $disponible ) : ?>
		<p><?php esc_html_e( 'Two-step verification is not available right now.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
	<?php else : ?>
		<?php if ( $activo ) : ?>
			<div class="asde-notice asde-notice--success" role="status"><?php esc_html_e( 'You have two-step verification', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <strong><?php esc_html_e( 'turned on', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></strong><?php esc_html_e( '. Well done!', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></div>
		<?php elseif ( $pendiente ) : ?>
			<div class="asde-notice asde-notice--warning" role="status">
				<?php esc_html_e( 'For your role, two-step verification is', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <strong><?php esc_html_e( 'mandatory', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></strong><?php echo $limite && $limite > time() ? esc_html( ' ' . sprintf( /* translators: %s: text */ __( 'as of %s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), wp_date( 'd/m/Y', $limite ) ) ) : ''; ?><?php esc_html_e( '. After that date you will not be able to sign in without it.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?>
			</div>
		<?php endif; ?>
		<p><?php esc_html_e( 'Two-step verification protects the group\'s data: besides your password, when signing in it will ask for a 6-digit code from an app on your phone.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
		<ol>
			<li><?php esc_html_e( 'Install an authenticator app on your phone (Google Authenticator, Microsoft Authenticator, Authy…).', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></li>
			<li><?php esc_html_e( 'Scan the QR code below with it.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></li>
			<li><?php esc_html_e( 'Enter the 6-digit code the app shows you and press "Activate".', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></li>
			<li><?php esc_html_e( 'Keep the', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <strong><?php esc_html_e( 'recovery codes', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></strong> <?php esc_html_e( 'in a safe place: you will need them if you lose your phone.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></li>
		</ol>
	<?php endif; ?>
</section>

<?php if ( $disponible ) : ?>
	<section class="asde-card asde-wordfence">
		<?php echo wp_kses( $wordfence, asde_html_permitido() ); ?>
	</section>
<?php endif; ?>
