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
		<h2 class="asde-card__title"><?php echo esc_html( ( RegistroEmails::TIPOS[ $fila['tipo'] ] ?? $fila['tipo'] ) . ' · ' . $fila['login'] ); ?></h2>
		<a href="<?php echo esc_url( $url ); ?>">← Registro de emails</a>
	</header>
	<dl class="asde-datos">
		<div><dt>Fecha</dt><dd><?php echo esc_html( wp_date( 'd/m/Y H:i:s', strtotime( $fila['creado'] . ' UTC' ) ) ); ?></dd></div>
		<?php if ( (int) $fila['user_id'] ) : ?>
			<div><dt>Usuario</dt><dd><a href="<?php echo esc_url( add_query_arg( 'usuario', (int) $fila['user_id'], $url ) ); ?>"><?php echo esc_html( $fila['login'] ); ?></a></dd></div>
		<?php else : ?>
			<div><dt>Solicitud</dt><dd><?php echo esc_html( $fila['login'] ); ?> <span class="asde-muted">(a quien la pidió; aún no tiene cuenta)</span></dd></div>
		<?php endif; ?>
		<div><dt>Enviado a</dt><dd><?php echo '' !== $fila['destino'] ? esc_html( $fila['destino'] ) : '—'; ?></dd></div>
		<div><dt>Asunto</dt><dd><?php echo esc_html( $fila['asunto'] ); ?></dd></div>
		<div>
			<dt>Estado</dt>
			<dd>
				<span class="asde-badge asde-badge--email-<?php echo esc_attr( $fila['estado'] ); ?>"><?php echo esc_html( RegistroEmails::ESTADOS[ $fila['estado'] ] ?? $fila['estado'] ); ?></span>
				<?php if ( '' !== $fila['error'] ) : ?>
					<br><span class="asde-help asde-help--warning"><?php echo esc_html( $fila['error'] ); ?></span>
				<?php endif; ?>
			</dd>
		</div>
		<div><dt>Enviado por</dt><dd><?php echo $autor ? esc_html( $autor->user_login ) : 'La propia persona (desde «¿Has olvidado tu contraseña?»)'; ?></dd></div>
	</dl>
	<?php if ( $mailpit && RegistroEmails::ENVIADO === $fila['estado'] ) : ?>
		<div class="asde-notice asde-notice--info" role="status"><strong>Entorno local:</strong> el email real, con su enlace, está en Mailpit: <a href="<?php echo esc_url( $mailpit ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $mailpit ); ?></a>.</div>
	<?php endif; ?>
</section>

<section class="asde-card">
	<h3>Cómo era el email</h3>
	<p class="asde-muted">Vista reconstruida con la misma plantilla y los mismos datos. El nombre se lee ahora del CRM, y el <strong>enlace personal no se guarda por seguridad</strong>: aquí aparece sustituido.</p>
	<?php if ( RegistroEmails::RECHAZO === $fila['tipo'] ) : ?>
		<p class="asde-muted">Es un texto genérico: el motivo que se anotó al rechazar la solicitud es solo para el grupo y no se envía.</p>
	<?php endif; ?>
	<?php if ( RegistroEmails::SIN_EMAIL === $fila['estado'] ) : ?>
		<p><strong>Este email no llegó a enviarse</strong> porque no había dirección de destino en el CRM.</p>
	<?php endif; ?>
	<iframe class="asde-email-preview" title="Vista del email" sandbox="" srcdoc="<?php echo esc_attr( $html ); ?>"></iframe>
</section>
