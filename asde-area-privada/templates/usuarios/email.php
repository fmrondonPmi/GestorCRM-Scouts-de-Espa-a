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
<section class="asde-card">
	<header class="asde-card__header asde-card__header--split">
		<h2 class="asde-card__title"><?php echo esc_html( ( asde_t( RegistroEmails::TIPOS[ $fila['tipo'] ] ?? $fila['tipo'] ) ) . ' · ' . $fila['login'] ); ?></h2>
		<a href="<?php echo esc_url( $url ); ?>"><?php asde_e( '← Registro de emails' ); ?></a>
	</header>
	<dl class="asde-datos">
		<div><dt><?php asde_e( 'Fecha' ); ?></dt><dd><?php echo esc_html( wp_date( 'd/m/Y H:i:s', strtotime( $fila['creado'] . ' UTC' ) ) ); ?></dd></div>
		<?php if ( (int) $fila['user_id'] ) : ?>
			<div><dt><?php asde_e( 'Usuario' ); ?></dt><dd><a href="<?php echo esc_url( add_query_arg( 'usuario', (int) $fila['user_id'], $url ) ); ?>"><?php echo esc_html( $fila['login'] ); ?></a></dd></div>
		<?php else : ?>
			<div><dt><?php asde_e( 'Solicitud' ); ?></dt><dd><?php echo esc_html( $fila['login'] ); ?> <span class="asde-muted"><?php asde_e( '(a quien la pidió; aún no tiene cuenta)' ); ?></span></dd></div>
		<?php endif; ?>
		<div><dt><?php asde_e( 'Enviado a' ); ?></dt><dd><?php echo '' !== $fila['destino'] ? esc_html( $fila['destino'] ) : '—'; ?></dd></div>
		<div><dt><?php asde_e( 'Asunto' ); ?></dt><dd><?php echo esc_html( $fila['asunto'] ); ?></dd></div>
		<div>
			<dt><?php asde_e( 'Estado' ); ?></dt>
			<dd>
				<span class="asde-badge asde-badge--email-<?php echo esc_attr( $fila['estado'] ); ?>"><?php echo esc_html( asde_t( RegistroEmails::ESTADOS[ $fila['estado'] ] ?? $fila['estado'] ) ); ?></span>
				<?php if ( '' !== $fila['error'] ) : ?>
					<br><span class="asde-help asde-help--warning"><?php echo esc_html( $fila['error'] ); ?></span>
				<?php endif; ?>
			</dd>
		</div>
		<div><dt><?php asde_e( 'Enviado por' ); ?></dt><dd><?php echo $autor ? esc_html( $autor->user_login ) : asde_t( 'La propia persona (desde «¿Has olvidado tu contraseña?»)' ); ?></dd></div>
	</dl>
	<?php if ( $mailpit && RegistroEmails::ENVIADO === $fila['estado'] ) : ?>
		<div class="asde-notice asde-notice--info" role="status"><strong><?php asde_e( 'Entorno local:' ); ?></strong> <?php asde_e( 'el email real, con su enlace, está en Mailpit:' ); ?> <a href="<?php echo esc_url( $mailpit ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $mailpit ); ?></a>.</div>
	<?php endif; ?>
</section>

<section class="asde-card">
	<h3><?php asde_e( 'Cómo era el email' ); ?></h3>
	<p class="asde-muted"><?php asde_e( 'Vista reconstruida con la misma plantilla y los mismos datos. El nombre se lee ahora del CRM, y el' ); ?> <strong><?php asde_e( 'enlace personal no se guarda por seguridad' ); ?></strong><?php asde_e( ': aquí aparece sustituido.' ); ?></p>
	<?php if ( RegistroEmails::RECHAZO === $fila['tipo'] ) : ?>
		<p class="asde-muted"><?php asde_e( 'Es un texto genérico: el motivo que se anotó al rechazar la solicitud es solo para el grupo y no se envía.' ); ?></p>
	<?php endif; ?>
	<?php if ( RegistroEmails::SIN_EMAIL === $fila['estado'] ) : ?>
		<p><strong><?php asde_e( 'Este email no llegó a enviarse' ); ?></strong> <?php asde_e( 'porque no había dirección de destino en el CRM.' ); ?></p>
	<?php endif; ?>
	<iframe class="asde-email-preview" title="<?php echo asde_a( 'Vista del email' ); ?>" sandbox="" srcdoc="<?php echo esc_attr( $html ); ?>"></iframe>
</section>
