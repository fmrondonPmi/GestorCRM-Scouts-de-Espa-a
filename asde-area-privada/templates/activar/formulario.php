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
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
defined( 'ABSPATH' ) || exit;

$reset = 'reset' === $tipo;
?>
<section class="asde-card asde-card--narrow">
	<h2><?php echo $reset ? esc_html( asde_t( 'Elige una contraseña nueva' ) ) : esc_html( asde_t( 'Activa tu cuenta' ) ); ?></h2>
	<p>
		<?php asde_e( 'Tu usuario es:' ); ?> <strong><?php echo esc_html( $user->user_login ); ?></strong>
		<?php if ( \AsdeBalears\AreaPrivada\Usuarios\Usuarios::email_origen( $user->ID ) ) : ?>
			<br><span class="asde-help"><?php asde_e( 'También podrás entrar con el email al que te ha llegado este enlace.' ); ?></span>
		<?php endif; ?>
	</p>
	<?php if ( ! $reset ) : ?>
		<p><?php asde_e( 'Elige una contraseña para entrar en el área privada.' ); ?></p>
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
			<label for="asde-pass1"><?php asde_e( 'Contraseña' ); ?></label>
			<input type="password" id="asde-pass1" name="pass1" minlength="<?php echo (int) $longitud; ?>" autocomplete="new-password" required>
			<span class="asde-help"><?php asde_e( 'Mínimo' ); ?> <?php echo (int) $longitud; ?> <?php asde_e( 'caracteres. Mejor una frase fácil de recordar para ti y difícil de adivinar.' ); ?></span>
		</p>
		<p>
			<label for="asde-pass2"><?php asde_e( 'Repite la contraseña' ); ?></label>
			<input type="password" id="asde-pass2" name="pass2" minlength="<?php echo (int) $longitud; ?>" autocomplete="new-password" required>
		</p>
		<p><button type="submit" class="button"><?php echo $reset ? esc_html( asde_t( 'Guardar contraseña' ) ) : esc_html( asde_t( 'Activar mi cuenta' ) ); ?></button></p>
	</form>
</section>
