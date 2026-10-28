<?php
/**
 * «Alta de scouters» (pública, v0.16, doc 31).
 *
 * @var array|null           $grupo     Grupo del enlace (null si el enlace no vale)
 * @var bool                 $enviado
 * @var bool                 $limite
 * @var bool                 $sin_crm
 * @var string[]             $errores
 * @var array                $valores   Lo escrito (para no tener que repetirlo)
 * @var string               $clave
 * @var string               $sello
 * @var string               $contacto  Email de contacto del grupo ('' si no hay)
 * @var string               $url_login
 * @var string               $url_recuperar
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Domain\Grupos;
use AsdeBalears\AreaPrivada\Domain\Secciones;
use AsdeBalears\AreaPrivada\Install\Roles;
use AsdeBalears\AreaPrivada\Usuarios\AltaScouterService as A;
use AsdeBalears\AreaPrivada\Usuarios\AltaService;

defined( 'ABSPATH' ) || exit;
?>
<section class="asde-card asde-card--narrow">
	<h2><?php esc_html_e( 'Scouter registration', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h2>

	<?php if ( ! $grupo ) : ?>
		<div class="asde-notice asde-notice--warning" role="status"><?php esc_html_e( 'This link is not valid or is no longer in use. Ask your group\'s coordination team for the current link.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></div>
		<p><a href="<?php echo esc_url( $url_login ); ?>"><?php esc_html_e( 'Go to Member access', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a></p>
	<?php elseif ( $enviado ) : ?>
		<div class="asde-notice asde-notice--success" role="status">
			<strong><?php esc_html_e( 'Thank you.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></strong>
			<?php esc_html_e( 'If the ID number belongs to a scouter or volunteer of the group, an email to activate your account will be sent to the email address on your group record. If your record has no email, or if you have asked for a responsibility, the coordination team will review your registration and write to you.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?>
		</div>
		<p><?php esc_html_e( 'If you already have an account, sign in with your member number or your email; if you don\'t remember the password,', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <a href="<?php echo esc_url( $url_recuperar ); ?>"><?php esc_html_e( 'request a new one', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a>.</p>
		<p><a href="<?php echo esc_url( $url_login ); ?>"><?php esc_html_e( 'Go to Member access', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a></p>
	<?php else : ?>
		<?php if ( $limite ) : ?>
			<div class="asde-notice asde-notice--error" role="alert"><?php esc_html_e( 'You have made several attempts in a row. Wait an hour before trying again.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></div>
		<?php elseif ( $sin_crm ) : ?>
			<div class="asde-notice asde-notice--error" role="alert"><?php esc_html_e( 'We cannot check your details right now. Try again later.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></div>
		<?php endif; ?>
		<?php if ( $errores ) : ?>
			<div class="asde-notice asde-notice--error" role="alert"><?php echo esc_html( implode( ' ', $errores ) ); ?></div>
		<?php endif; ?>

		<p><?php echo esc_html( sprintf( /* translators: %s: text */ __( 'If you are a scouter or volunteer of %s, register in the private area. We will check your ID number against your group record.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), Grupos::etiqueta( $grupo ) ) ); ?></p>
		<form method="post" class="asde-form" novalidate>
			<?php wp_nonce_field( 'asde_alta_scouter' ); ?>
			<input type="hidden" name="asde_action" value="alta_scouter">
			<input type="hidden" name="grupo" value="<?php echo esc_attr( (string) $grupo['id'] ); ?>">
			<input type="hidden" name="clave" value="<?php echo esc_attr( $clave ); ?>">
			<input type="hidden" name="sello" value="<?php echo esc_attr( $sello ); ?>">
			<p class="asde-trampa" aria-hidden="true"><label for="asde-alta-web"><?php esc_html_e( 'Do not write anything here', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label><input type="text" id="asde-alta-web" name="web" value="" tabindex="-1" autocomplete="off"></p>
			<p>
				<label for="asde-alta-doc"><?php esc_html_e( 'Your ID or foreigner ID number', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
				<input type="text" id="asde-alta-doc" name="documento" value="<?php echo esc_attr( $valores['documento'] ); ?>" maxlength="20" autocomplete="off" autocapitalize="characters" spellcheck="false" required>
			</p>
			<fieldset>
				<legend><?php esc_html_e( 'How do you help in the group?', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></legend>
				<label class="asde-choice"><input type="radio" name="modo" value="<?php echo esc_attr( A::MODO_SCOUTER ); ?>" <?php checked( $valores['modo'], A::MODO_SCOUTER ); ?>><span><?php esc_html_e( 'I am a scouter of one or more sections', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span></label>
				<div class="asde-subopciones">
					<?php foreach ( Secciones::EDUCATIVAS as $slug ) : ?>
						<label class="asde-choice"><input type="checkbox" name="secciones[]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( in_array( $slug, $valores['secciones'], true ) ); ?>><span><?php echo wp_kses( Secciones::badge( $slug ), asde_html_permitido() ); ?></span></label>
					<?php endforeach; ?>
				</div>
				<label class="asde-choice"><input type="radio" name="modo" value="<?php echo esc_attr( A::MODO_SIN_SECCION ); ?>" <?php checked( $valores['modo'], A::MODO_SIN_SECCION ); ?>><span><?php esc_html_e( 'I am a volunteer without a section (Secretary\'s office, Coordination, Treasury or Quartermaster)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span></label>
			</fieldset>
			<fieldset>
				<legend><?php esc_html_e( 'Responsibilities in the group\'s team (optional)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></legend>
				<?php foreach ( AltaService::ROLES_RESPONSABILIDAD as $rol ) : ?>
					<label class="asde-choice"><input type="checkbox" name="responsabilidades[]" value="<?php echo esc_attr( $rol ); ?>" <?php checked( in_array( $rol, $valores['responsabilidades'], true ) ); ?>><span><?php echo esc_html( Roles::label( $rol ) ); ?></span></label>
				<?php endforeach; ?>
				<p class="asde-help"><?php esc_html_e( 'They are confirmed by the coordination team before they take effect. Meanwhile, you sign in as a scouter of your sections.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
			</fieldset>
			<p>
				<label for="asde-alta-email"><?php esc_html_e( 'Your email', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
				<input type="email" id="asde-alta-email" name="email" value="<?php echo esc_attr( $valores['email'] ); ?>" autocomplete="email" autocapitalize="none" spellcheck="false" required>
				<span class="asde-help"><?php esc_html_e( 'The activation is sent to the email address on your group record. This one is only used if your record has no email: the coordination team will then review your registration.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span>
			</p>
			<p class="asde-help"><?php esc_html_e( 'We use these details only to check your registration against your group record. They are stored encrypted and deleted 30 days after being reviewed.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
			<p><button type="submit" class="button"><?php esc_html_e( 'Register me', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button></p>
		</form>
		<p class="asde-muted"><?php esc_html_e( 'Already have an account?', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <a href="<?php echo esc_url( $url_login ); ?>"><?php esc_html_e( 'Sign in here', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a>.</p>
	<?php endif; ?>
</section>
