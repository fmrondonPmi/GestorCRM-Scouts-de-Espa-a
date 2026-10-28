<?php
/**
 * «Solicitar acceso» (pública).
 *
 * @var array<string, array> $grupos   Grupos con conexión al CRM
 * @var string               $grupo    Grupo elegido
 * @var string               $resultado Resultado del envío ('' si aún no se ha enviado)
 * @var string               $contacto Email de contacto del grupo ('' si no hay)
 * @var array<string,string> $valores  Lo escrito (para no tener que repetirlo)
 * @var string[]             $errores
 * @var string               $sello
 * @var string               $url_login
 * @var string               $url_recuperar
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Domain\Grupos;
use AsdeBalears\AreaPrivada\Domain\Organizacion;
use AsdeBalears\AreaPrivada\Usuarios\SolicitudService as S;
use AsdeBalears\AreaPrivada\Usuarios\Solicitudes;

defined( 'ABSPATH' ) || exit;

$pedir_email = static function ( string $contacto ): string {
	return '' !== $contacto
		? sprintf( /* translators: 1: text, 2: text */ __( 'Request access by email at <a href="mailto:%1$s">%2$s</a>, with the member\'s name and your relationship to them.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), esc_attr( $contacto ), esc_html( $contacto ) )
		: __( 'Request access from your group\'s Secretary\'s office, with the member\'s name and your relationship to them.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' );
};
$terminado  = in_array( $resultado, array( S::OK, S::YA_PEDIDA, S::YA_TIENE ), true );
$ko         = in_array( $resultado, array( S::NO_EXISTE, S::NO_COINCIDE, S::NO_ELEGIBLE, 'sin_crm', 'limite' ), true );
$con_nombre = S::PEDIR_NOMBRE === $resultado || '' !== $valores['nombre_menor'];
?>
<section class="asde-card asde-card--narrow">
	<h2><?php esc_html_e( 'Request access', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h2>

	<?php if ( S::OK === $resultado || S::YA_PEDIDA === $resultado ) : ?>
		<div class="asde-notice asde-notice--success" role="status">
			<strong><?php echo S::OK === $resultado ? esc_html( __( 'Request sent.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) : esc_html( __( 'We already had your request.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?></strong>
			<?php esc_html_e( 'A scouter of the group will review it and, when they approve it, you will receive an email at', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <strong><?php echo esc_html( $valores['email'] ); ?></strong> <?php esc_html_e( 'to activate your account.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?>
		</div>
		<p><a href="<?php echo esc_url( $url_login ); ?>"><?php esc_html_e( 'Back to Member access', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a></p>
	<?php elseif ( S::YA_TIENE === $resultado ) : ?>
		<div class="asde-notice asde-notice--info" role="status">
			<strong><?php esc_html_e( 'You already have an account in the private area.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></strong> <?php esc_html_e( 'Sign in with your member no. or your email. If you don\'t remember the password,', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <a href="<?php echo esc_url( $url_recuperar ); ?>"><?php esc_html_e( 'request a new one', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a>.
		</div>
		<p><a href="<?php echo esc_url( $url_login ); ?>"><?php esc_html_e( 'Go to Member access', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a></p>
	<?php endif; ?>

	<?php if ( ! $terminado ) : ?>
		<?php if ( $ko ) : ?>
			<div class="asde-notice asde-notice--error" role="alert">
				<?php if ( 'limite' === $resultado ) : ?>
					<?php esc_html_e( 'You have made several requests in a row. Wait an hour before trying again, or', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <?php echo wp_kses( lcfirst( $pedir_email( $contacto ) ), array( 'a' => array( 'href' => true ) ) ); ?>
				<?php elseif ( 'sin_crm' === $resultado ) : ?>
					<?php esc_html_e( 'We cannot check your details right now. Try again later or', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <?php echo wp_kses( lcfirst( $pedir_email( $contacto ) ), array( 'a' => array( 'href' => true ) ) ); ?>
				<?php elseif ( S::NO_ELEGIBLE === $resultado ) : ?>
					<?php esc_html_e( 'With those details access cannot be given from here.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <?php echo wp_kses( $pedir_email( $contacto ), array( 'a' => array( 'href' => true ) ) ); ?>
				<?php else : ?>
					<strong><?php esc_html_e( 'We could not check your details', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></strong><?php esc_html_e( ': there is no member of the group with that document and that date of birth.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <?php echo wp_kses( $pedir_email( $contacto ), array( 'a' => array( 'href' => true ) ) ); ?>
				<?php endif; ?>
			</div>
		<?php elseif ( S::PEDIR_NOMBRE === $resultado ) : ?>
			<div class="asde-notice asde-notice--info" role="status">
				<?php echo '' === trim( $valores['documento'] ) ? esc_html( __( 'With no document number, we check it with their name.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) : esc_html( __( 'There is no member with that document number.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>
				<?php esc_html_e( 'If it is', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <strong><?php esc_html_e( 'under 14', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></strong> <?php esc_html_e( 'and has no ID number, enter their first name and surname as they appear in the group.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?>
			</div>
		<?php elseif ( S::MENOR_14 === $resultado ) : ?>
			<div class="asde-notice asde-notice--info" role="status"><?php esc_html_e( 'Members under 14 sign in with their family\'s account: it is requested by their mother, father or guardian. We have ticked it below; check your details and send it again.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></div>
		<?php elseif ( S::ADULTO === $resultado ) : ?>
			<div class="asde-notice asde-notice--info" role="status"><?php esc_html_e( 'That member is already of legal age: the person themselves requests access. We have ticked it below; check the details and send it again.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></div>
		<?php endif; ?>
		<?php if ( $errores ) : ?>
			<div class="asde-notice asde-notice--error" role="alert"><?php echo esc_html( implode( ' ', $errores ) ); ?></div>
		<?php endif; ?>

		<?php if ( ! $grupos ) : ?>
			<p><?php esc_html_e( 'Access requests are not available at the moment.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
		<?php else : ?>
			<?php $org = Organizacion::nombre(); ?>
			<p><?php esc_html_e( 'If you are a member of a group', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?><?php echo '' !== $org ? ' ' . esc_html( sprintf( /* translators: %s: text */ __( 'of %s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $org ) ) : ''; ?><?php esc_html_e( ', or their mother, father or guardian, and you don\'t have an account in the private area yet, request it here. We will check the details against the group\'s and a scouter will approve the request.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
			<form method="post" class="asde-form" novalidate>
				<?php wp_nonce_field( 'asde_solicitar' ); ?>
				<input type="hidden" name="asde_action" value="solicitar">
				<input type="hidden" name="sello" value="<?php echo esc_attr( $sello ); ?>">
				<p class="asde-trampa" aria-hidden="true"><label for="asde-sol-web"><?php esc_html_e( 'Do not write anything here', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label><input type="text" id="asde-sol-web" name="web" value="" tabindex="-1" autocomplete="off"></p>
				<p>
					<label for="asde-sol-grupo"><?php esc_html_e( 'Scout Group', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
					<select id="asde-sol-grupo" name="grupo" required>
						<?php if ( count( $grupos ) > 1 ) : ?>
							<option value=""><?php esc_html_e( 'Choose the group…', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></option>
						<?php endif; ?>
						<?php foreach ( $grupos as $id => $g ) : ?>
							<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $grupo, $id ); ?>><?php echo esc_html( Grupos::etiqueta( $g ) ); ?></option>
						<?php endforeach; ?>
					</select>
				</p>
				<fieldset>
					<legend><?php esc_html_e( 'Who is requesting access?', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></legend>
					<label class="asde-choice"><input type="radio" name="tipo" value="<?php echo esc_attr( Solicitudes::TUTOR ); ?>" <?php checked( $valores['tipo'], Solicitudes::TUTOR ); ?>><span><?php esc_html_e( 'I am their mother, father or guardian (members who are minors)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span></label>
					<label class="asde-choice"><input type="radio" name="tipo" value="<?php echo esc_attr( Solicitudes::SOCIO ); ?>" <?php checked( $valores['tipo'], Solicitudes::SOCIO ); ?>><span><?php esc_html_e( 'I am the member (from age 14)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span></label>
				</fieldset>
				<fieldset>
					<legend><?php esc_html_e( 'Member\'s details', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></legend>
					<p>
						<label for="asde-sol-doc"><?php esc_html_e( 'Document number (ID or foreigner ID)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
						<input type="text" id="asde-sol-doc" name="documento" value="<?php echo esc_attr( $valores['documento'] ); ?>" maxlength="20" autocomplete="off" autocapitalize="characters" spellcheck="false">
						<span class="asde-help"><?php esc_html_e( 'The member\'s. If they are a minor and have no ID number, leave it blank.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span>
					</p>
					<p>
						<label for="asde-sol-nac"><?php esc_html_e( 'Member\'s date of birth', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
						<input type="date" id="asde-sol-nac" name="nacimiento" value="<?php echo esc_attr( $valores['nacimiento'] ); ?>" required>
					</p>
					<?php if ( $con_nombre ) : ?>
						<p class="asde-destacado">
							<label for="asde-sol-menor"><?php esc_html_e( 'Member\'s first name and surname', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
							<input type="text" id="asde-sol-menor" name="nombre_menor" value="<?php echo esc_attr( $valores['nombre_menor'] ); ?>" maxlength="120" required <?php echo S::PEDIR_NOMBRE === $resultado ? 'autofocus' : ''; ?>>
						</p>
					<?php endif; ?>
				</fieldset>
				<fieldset>
					<legend><?php esc_html_e( 'Your details', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></legend>
					<p>
						<label for="asde-sol-nombre"><?php esc_html_e( 'Your first name and surname', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
						<input type="text" id="asde-sol-nombre" name="nombre" value="<?php echo esc_attr( $valores['nombre'] ); ?>" maxlength="120" autocomplete="name" required>
					</p>
					<p>
						<label for="asde-sol-email"><?php esc_html_e( 'Your email', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
						<input type="email" id="asde-sol-email" name="email" value="<?php echo esc_attr( $valores['email'] ); ?>" autocomplete="email" autocapitalize="none" spellcheck="false" required>
						<span class="asde-help"><?php esc_html_e( 'The invitation will arrive here, and with it you will be able to sign in. If the group has another one, on approving the request it will be updated on the record.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span>
					</p>
				</fieldset>
				<p class="asde-help"><?php esc_html_e( 'We use these details only to check the request against the group\'s record. They are stored encrypted and deleted 30 days after it is resolved.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
				<p><button type="submit" class="button"><?php esc_html_e( 'Send request', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button></p>
			</form>
			<p class="asde-muted"><?php esc_html_e( 'Already have an account?', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <a href="<?php echo esc_url( $url_login ); ?>"><?php esc_html_e( 'Sign in here', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a>.</p>
		<?php endif; ?>
	<?php endif; ?>
</section>
