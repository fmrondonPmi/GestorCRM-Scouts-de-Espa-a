<?php
/** @var string $url_recuperar */
defined( 'ABSPATH' ) || exit;
?>
<section class="asde-card asde-card--narrow">
	<h2><?php esc_html_e( 'Invalid link', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h2>
	<p><?php esc_html_e( 'This link is not valid or has expired.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
	<ul>
		<li><?php esc_html_e( 'If you already have a password,', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <a href="<?php echo esc_url( wp_login_url() ); ?>"><?php esc_html_e( 'sign in', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a>.</li>
		<li><?php esc_html_e( 'If you have forgotten it,', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <a href="<?php echo esc_url( $url_recuperar ); ?>"><?php esc_html_e( 'request a new link', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a>.</li>
		<li><?php esc_html_e( 'If it was an invitation, ask your group\'s Secretary\'s office to resend it.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></li>
	</ul>
</section>
