<?php
/**
 * Recuperar contraseña.
 *
 * @var bool   $enviado
 * @var string $login     URL del login
 * @var string $solicitar URL de «Solicitar acceso»
 */
use AsdeBalears\AreaPrivada\Domain\Identidad;

defined( 'ABSPATH' ) || exit;
?>
<section class="asde-card asde-card--narrow">
	<h2>Recuperar contraseña</h2>
	<?php if ( $enviado ) : ?>
		<div class="asde-notice asde-notice--success" role="status">
			Si los datos son correctos, en unos minutos recibirás un email con un enlace para elegir una contraseña nueva
			(válido 24 horas) en el email de tu cuenta.
		</div>
		<p>Si ese email es el de varias cuentas (por ejemplo, la tuya y la de tu familia), te llegará un enlace para cada una: cada email dice de qué usuario es.</p>
		<p>¿No te llega? Revisa la carpeta de spam. Si todavía no tienes cuenta, <a href="<?php echo esc_url( $solicitar ); ?>">solicita el acceso</a>.</p>
		<p><a href="<?php echo esc_url( $login ); ?>">Volver a iniciar sesión</a></p>
	<?php else : ?>
		<?php $ejemplo = \AsdeBalears\AreaPrivada\Domain\NumeroSocio::ejemplo_tutor(); ?>
		<p>Escribe tu nº de socio/a (las familias, el nº del menor seguido de una <strong>T</strong><?php echo '' !== $ejemplo ? ', p. ej. ' . esc_html( $ejemplo ) : ''; ?>) o el email al que te llegó la invitación.</p>
		<form method="post" class="asde-form">
			<?php wp_nonce_field( 'asde_recuperar' ); ?>
			<input type="hidden" name="asde_action" value="recuperar">
			<p>
				<label for="asde-usuario"><?php echo esc_html( Identidad::etiqueta_campo() ); ?></label>
				<input type="text" id="asde-usuario" name="usuario" autocomplete="username" autocapitalize="none" autocorrect="off" spellcheck="false" required>
			</p>
			<p><button type="submit" class="button">Enviarme el enlace</button></p>
		</form>
		<p>
			<a href="<?php echo esc_url( $login ); ?>">Volver a iniciar sesión</a><br>
			<span class="asde-muted">¿Aún no tienes cuenta? <a href="<?php echo esc_url( $solicitar ); ?>">Solicita el acceso</a>.</span>
		</p>
	<?php endif; ?>
</section>
