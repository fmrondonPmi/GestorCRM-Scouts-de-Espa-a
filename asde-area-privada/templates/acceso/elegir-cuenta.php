<?php
/**
 * Acceso al área privada · segundo paso: elegir con qué cuenta se entra (v0.64.3).
 *
 * Se muestra cuando el email y la contraseña valen para varias cuentas del grupo
 * (p. ej. la de scouter y la de tutor/a de la misma persona). La contraseña no va
 * en el formulario: se guarda cifrada unos minutos en el servidor.
 *
 * @var string                $token
 * @var array<string, string> $cuentas      usuario => texto del desplegable
 * @var string                $elegida      Usuario preseleccionado
 * @var string                $error
 * @var bool                  $pedir_codigo
 * @var string                $url_acceso
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
defined( 'ABSPATH' ) || exit;
?>
<section class="asde-card asde-card--narrow asde-acceso">
	<h2><?php esc_html_e( 'Choose your account', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h2>
	<p><?php esc_html_e( 'This email address has more than one account. Choose the one you want to sign in with now; you can sign in again with the other one whenever you need it.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>

	<?php if ( $error ) : ?>
		<div class="asde-notice asde-notice--error" role="alert"><?php echo esc_html( $error ); ?></div>
	<?php endif; ?>

	<form method="post" class="asde-form" autocomplete="off">
		<?php wp_nonce_field( 'asde_acceso_elegir' ); ?>
		<input type="hidden" name="asde_action" value="elegir_cuenta">
		<input type="hidden" name="token" value="<?php echo esc_attr( $token ); ?>">
		<input type="hidden" name="redirect_to" value="<?php echo esc_attr( isset( $_REQUEST['redirect_to'] ) && is_string( $_REQUEST['redirect_to'] ) ? esc_url_raw( wp_unslash( $_REQUEST['redirect_to'] ) ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Solo se rellena el campo oculto; AccesoView::destino() lo vuelve a comprobar. ?>">
		<p>
			<label for="asde-acc-cuenta"><?php esc_html_e( 'Account', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
			<select id="asde-acc-cuenta" name="cuenta" required>
				<?php foreach ( $cuentas as $login => $texto ) : ?>
					<option value="<?php echo esc_attr( $login ); ?>" <?php selected( $elegida, $login ); ?>><?php echo esc_html( $texto ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
		<?php if ( $pedir_codigo ) : ?>
			<p class="asde-destacado">
				<label for="asde-acc-codigo"><?php esc_html_e( 'Verification code', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
				<input type="text" id="asde-acc-codigo" name="wfls-token" autocomplete="one-time-code" spellcheck="false" maxlength="24" required autofocus>
				<span class="asde-help"><?php esc_html_e( 'The 6 digits from your authenticator app or, if you have lost your phone, one of your recovery codes.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span>
			</p>
		<?php endif; ?>
		<p><button type="submit" class="button"><?php esc_html_e( 'Continue', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button></p>
	</form>
	<p class="asde-muted"><a href="<?php echo esc_url( $url_acceso ); ?>"><?php esc_html_e( 'Start again', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a></p>
</section>
