<?php
/**
 * Un email del registro: datos del envío y vista del email.
 *
 * @var array        $fila
 * @var string       $html    Email reconstruido (sin el enlace personal)
 * @var WP_User|null $autor
 * @var string       $url     Registro de emails
 * @var string       $mailpit URL de Mailpit (solo en local)
 */
use AsdeBalears\AreaPrivada\Usuarios\RegistroEmails;

defined( 'ABSPATH' ) || exit;
?>
<section class="asde-card" data-asde-plegable="email-detalle">
	<header class="asde-card__header asde-card__header--split">
		<h2 class="asde-card__title"><?php echo esc_html( ( asde_t( RegistroEmails::TIPOS[ $fila['tipo'] ] ?? $fila['tipo'] ) ) . ' · ' . $fila['login'] ); ?></h2>
		<a href="<?php echo esc_url( $url ); ?>"><?php esc_html_e( '← Email log', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a>
	</header>
	<dl class="asde-datos">
		<div><dt><?php esc_html_e( 'Date', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></dt><dd><?php echo esc_html( wp_date( 'd/m/Y H:i:s', strtotime( $fila['creado'] . ' UTC' ) ) ); ?></dd></div>
		<?php if ( (int) $fila['user_id'] ) : ?>
			<div><dt><?php esc_html_e( 'Username', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></dt><dd><a href="<?php echo esc_url( add_query_arg( 'usuario', (int) $fila['user_id'], $url ) ); ?>"><?php echo esc_html( $fila['login'] ); ?></a></dd></div>
		<?php else : ?>
			<div><dt><?php esc_html_e( 'Request', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></dt><dd><?php echo esc_html( $fila['login'] ); ?> <span class="asde-muted"><?php esc_html_e( '(to whoever requested it; they do not have an account yet)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span></dd></div>
		<?php endif; ?>
		<div><dt><?php esc_html_e( 'Sent to', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></dt><dd><?php echo '' !== $fila['destino'] ? esc_html( $fila['destino'] ) : '—'; ?></dd></div>
		<div><dt><?php esc_html_e( 'Subject', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></dt><dd><?php echo esc_html( $fila['asunto'] ); ?></dd></div>
		<div>
			<dt><?php esc_html_e( 'Status', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></dt>
			<dd>
				<span class="asde-badge asde-badge--email-<?php echo esc_attr( $fila['estado'] ); ?>"><?php echo esc_html( asde_t( RegistroEmails::ESTADOS[ $fila['estado'] ] ?? $fila['estado'] ) ); ?></span>
				<?php if ( '' !== $fila['error'] ) : ?>
					<br><span class="asde-help asde-help--warning"><?php echo esc_html( $fila['error'] ); ?></span>
				<?php endif; ?>
			</dd>
		</div>
		<div><dt><?php esc_html_e( 'Sent by', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></dt><dd><?php echo $autor ? esc_html( $autor->user_login ) : esc_html( __( 'The person themselves (from "Forgot your password?")', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?></dd></div>
	</dl>
	<?php if ( $mailpit && RegistroEmails::ENVIADO === $fila['estado'] ) : ?>
		<div class="asde-notice asde-notice--info" role="status"><strong><?php esc_html_e( 'Local environment:', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></strong> <?php esc_html_e( 'the real email, with its link, is in Mailpit:', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <a href="<?php echo esc_url( $mailpit ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $mailpit ); ?></a>.</div>
	<?php endif; ?>
</section>

<section class="asde-card">
	<h3><?php esc_html_e( 'What the email was', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h3>
	<p class="asde-muted"><?php esc_html_e( 'View rebuilt with the same template and the same data. The name is now read from the CRM, and the', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <strong><?php esc_html_e( 'personal link is not stored for security', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></strong><?php esc_html_e( ': it appears replaced here.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
	<?php if ( RegistroEmails::RECHAZO === $fila['tipo'] ) : ?>
		<p class="asde-muted"><?php esc_html_e( 'It is a generic text: the reason noted when rejecting the request is for the group only and is not sent.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
	<?php endif; ?>
	<?php if ( RegistroEmails::SIN_EMAIL === $fila['estado'] ) : ?>
		<p><strong><?php esc_html_e( 'This email was never sent', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></strong> <?php esc_html_e( 'because there was no destination address in the CRM.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
	<?php endif; ?>
	<iframe class="asde-email-preview" title="<?php echo esc_attr( esc_attr__( 'Email view', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>" sandbox="" srcdoc="<?php echo esc_attr( $html ); ?>"></iframe>
</section>
