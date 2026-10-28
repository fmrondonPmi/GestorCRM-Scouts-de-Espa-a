<?php
/**
 * Acceso al área privada.
 *
 * @var array<string, array{id:string, numero:string, nombre:string}> $grupos
 * @var string $grupo          Grupo seleccionado
 * @var string $usuario
 * @var string $error
 * @var bool   $pedir_codigo
 * @var bool   $activada       Viene de activar la cuenta o cambiar la contraseña
 * @var string $redirect_to
 * @var string $url_recuperar
 * @var string $url_solicitar
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Domain\Identidad;

defined( 'ABSPATH' ) || exit;

$org     = \AsdeBalears\AreaPrivada\Domain\Organizacion::nombre();
$ejemplo = \AsdeBalears\AreaPrivada\Domain\NumeroSocio::ejemplo_tutor();
?>
<section class="asde-card asde-card--narrow asde-acceso">
	<h2><?php esc_html_e( 'Member access', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h2>
	<p><?php echo esc_html( '' !== $org ? sprintf( /* translators: %s: text */ __( 'Private area of the %s groups.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $org ) : __( 'Private area of the Scout Groups.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?></p>

	<?php if ( $activada ) : ?>
		<div class="asde-notice asde-notice--success" role="status"><?php esc_html_e( 'Password saved. You can now sign in with your member number (or your email) and the password you have just chosen.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></div>
	<?php endif; ?>
	<?php if ( $error ) : ?>
		<div class="asde-notice asde-notice--error" role="alert"><?php echo esc_html( $error ); ?></div>
	<?php endif; ?>

	<?php if ( ! $grupos ) : ?>
		<p><?php esc_html_e( 'Sign-in is not available at the moment. Try again later.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
	<?php else : ?>
		<form method="post" class="asde-form" autocomplete="on">
			<?php wp_nonce_field( 'asde_acceso' ); ?>
			<input type="hidden" name="asde_action" value="acceder">
			<input type="hidden" name="redirect_to" value="<?php echo esc_attr( $redirect_to ); ?>">
			<p>
				<label for="asde-acc-grupo"><?php esc_html_e( 'Scout Group', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
				<select id="asde-acc-grupo" name="grupo" required>
					<?php if ( count( $grupos ) > 1 ) : ?>
						<option value=""><?php esc_html_e( 'Choose your group…', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></option>
					<?php endif; ?>
					<?php foreach ( $grupos as $id => $g ) : ?>
						<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $grupo, $id ); ?>><?php echo esc_html( \AsdeBalears\AreaPrivada\Domain\Grupos::etiqueta( $g ) ); ?></option>
					<?php endforeach; ?>
				</select>
			</p>
			<p>
				<label for="asde-acc-usuario"><?php echo esc_html( Identidad::etiqueta_campo() ); ?></label>
				<input type="text" id="asde-acc-usuario" name="usuario" value="<?php echo esc_attr( $usuario ); ?>" autocomplete="username" autocapitalize="none" autocorrect="off" spellcheck="false" required>
				<span class="asde-help"><?php esc_html_e( 'Families: the minor\'s member number followed by a', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <strong>T</strong><?php echo '' !== $ejemplo ? ' (p. ej. ' . esc_html( $ejemplo ) . ')' : ''; ?><?php esc_html_e( '. The email address your invitation was sent to also works', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?><?php echo Identidad::es_asde() ? esc_html( __( ', or your usual local member number', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) : esc_html( __( ', or your Scouts de España member number', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>.</span>
			</p>
			<p>
				<label for="asde-acc-clave"><?php esc_html_e( 'Password', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
				<input type="password" id="asde-acc-clave" name="clave" autocomplete="current-password" required>
			</p>
			<?php /* v0.35.1 (petición del usuario): plegado; abierto cuando Wordfence pide el código. */ ?>
			<details class="asde-acceso__codigo<?php echo $pedir_codigo ? ' asde-destacado' : ''; ?>"<?php echo $pedir_codigo ? ' open' : ''; ?>>
				<summary><?php esc_html_e( 'Verification code', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <?php echo $pedir_codigo ? '' : '<span class="asde-help asde-help--inline">' . esc_html( __( '(only if you have two-step verification turned on)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) . '</span>'; ?></summary>
				<p>
					<label for="asde-acc-codigo" class="screen-reader-text"><?php esc_html_e( 'Verification code', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
					<input type="text" id="asde-acc-codigo" name="wfls-token" autocomplete="one-time-code" spellcheck="false" maxlength="24" <?php echo $pedir_codigo ? 'required autofocus' : ''; ?>>
					<span class="asde-help"><?php esc_html_e( 'The 6 digits from your authenticator app or, if you have lost your phone, one of your recovery codes.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span>
				</p>
			</details>
			<label class="asde-choice"><input type="checkbox" name="recordar" value="1"><span><?php esc_html_e( 'Keep me signed in on this device', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span></label>
			<p><button type="submit" class="button"><?php esc_html_e( 'Sign in', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button></p>
		</form>
		<p class="asde-muted">
			<a href="<?php echo esc_url( $url_recuperar ); ?>"><?php esc_html_e( 'Forgot your password?', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a><br>
			<?php esc_html_e( 'Don\'t have an account yet?', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <a href="<?php echo esc_url( $url_solicitar ); ?>"><?php esc_html_e( 'Request access', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a><?php esc_html_e( ': we check your details against the group\'s and send you an invitation by email.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?>
		</p>
	<?php endif; ?>
</section>
