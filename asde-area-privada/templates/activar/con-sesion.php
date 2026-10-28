<?php
/** @var string $url_area */
defined( 'ABSPATH' ) || exit;
?>
<section class="asde-card asde-card--narrow">
	<h2><?php esc_html_e( 'You are already signed in', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h2>
	<p><?php esc_html_e( 'To activate another account, sign out first.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
	<p><a class="button" href="<?php echo esc_url( $url_area ); ?>"><?php esc_html_e( 'Go to the private area', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a></p>
</section>
