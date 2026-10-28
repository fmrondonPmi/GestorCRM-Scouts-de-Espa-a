<?php
/**
 * «Alta de grupo» (pública, v0.50, doc 50 fase 5): el formulario al que lleva una invitación de la oficina.
 *
 * @var string   $token
 * @var bool     $verificada Ya se ha comprobado el acceso al CRM con este nº de grupo (la credencial no vuelve en el HTML)
 * @var string   $mensaje
 * @var string[] $errores
 * @var bool     $limite
 * @var string   $sello
 * @var array    $valores
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Domain\GrupoSolicitudes;

defined( 'ABSPATH' ) || exit;
?>
<section class="asde-card asde-card--narrow">
	<h2><?php esc_html_e( 'Group registration', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h2>
	<p><?php esc_html_e( 'The office has invited you to register your group in the private area. Fill in the details, check the CRM access and send them: the office will review them.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>

	<?php if ( $limite ) : ?>
		<div class="asde-notice asde-notice--error" role="alert"><?php esc_html_e( 'You have made several attempts in a row. Wait an hour before trying again.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></div>
	<?php endif; ?>
	<?php if ( $errores ) : ?>
		<div class="asde-notice asde-notice--error" role="alert"><?php echo esc_html( implode( ' ', $errores ) ); ?></div>
	<?php endif; ?>
	<?php if ( '' !== $mensaje ) : ?>
		<div class="asde-notice asde-notice--success" role="status"><?php echo esc_html( $mensaje ); ?></div>
	<?php endif; ?>

	<form method="post" class="asde-form" novalidate autocomplete="off">
		<?php wp_nonce_field( 'asde_alta_grupo' ); ?>
		<input type="hidden" name="t" value="<?php echo esc_attr( $token ); ?>">
		<input type="hidden" name="sello" value="<?php echo esc_attr( $sello ); ?>">
		<p class="asde-trampa" aria-hidden="true"><label for="asde-ag-web"><?php esc_html_e( 'Do not write anything here', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label><input type="text" id="asde-ag-web" name="web" value="" tabindex="-1" autocomplete="off"></p>

		<h3><?php esc_html_e( 'The group', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h3>
		<p class="asde-form__fila asde-form__fila--una-linea">
			<label for="asde-ag-numero"><?php esc_html_e( 'Group no.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <input type="text" id="asde-ag-numero" name="numero" value="<?php echo esc_attr( $valores['numero'] ); ?>" maxlength="3" inputmode="numeric" size="4" required></label>
			<label for="asde-ag-nombre"><?php esc_html_e( 'Group name', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <input type="text" id="asde-ag-nombre" name="nombre" value="<?php echo esc_attr( $valores['nombre'] ); ?>" maxlength="120" required></label>
		</p>
		<p class="asde-form__fila asde-form__fila--una-linea">
			<label for="asde-ag-sede"><?php esc_html_e( 'Headquarters address', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <input type="text" id="asde-ag-sede" name="sede" value="<?php echo esc_attr( $valores['sede'] ); ?>" maxlength="255"></label>
			<label for="asde-ag-email"><?php esc_html_e( 'Group email', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <input type="email" id="asde-ag-email" name="email" value="<?php echo esc_attr( $valores['email'] ); ?>" autocomplete="email" required></label>
		</p>
		<p class="asde-form__fila asde-form__fila--una-linea">
			<label for="asde-ag-cif"><?php esc_html_e( 'Tax ID (optional)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <input type="text" id="asde-ag-cif" name="cif" value="<?php echo esc_attr( $valores['cif'] ); ?>" maxlength="20" size="12"></label>
			<label for="asde-ag-den"><?php esc_html_e( 'Tax name (optional)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <input type="text" id="asde-ag-den" name="denominacion" value="<?php echo esc_attr( $valores['denominacion'] ); ?>" maxlength="190"></label>
		</p>

		<h3><?php esc_html_e( 'CRM access', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h3>
		<?php if ( $verificada ) : ?>
			<p class="asde-notice asde-notice--success" role="status"><?php esc_html_e( 'Access checked. The password is stored encrypted and is not shown again.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
		<?php else : ?>
			<p class="asde-form__fila asde-form__fila--una-linea">
				<label for="asde-ag-usuario"><?php esc_html_e( 'Group\'s CRM user', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <input type="text" id="asde-ag-usuario" name="usuario" value="<?php echo esc_attr( $valores['usuario'] ); ?>" maxlength="120" autocomplete="off" autocapitalize="none" spellcheck="false"></label>
				<label for="asde-ag-clave"><?php esc_html_e( 'Password', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <input type="password" id="asde-ag-clave" name="clave" value="" autocomplete="new-password"></label>
			</p>
		<?php endif; ?>
		<p><button type="submit" class="button asde-button-secundario" name="asde_action" value="alta_grupo_comprobar" formnovalidate><?php esc_html_e( 'Check access', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button></p>

		<h3><?php esc_html_e( 'The first person with access', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h3>
		<p class="asde-form__fila asde-form__fila--una-linea">
			<label for="asde-ag-persona"><?php esc_html_e( 'First name and surname', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <input type="text" id="asde-ag-persona" name="persona" value="<?php echo esc_attr( $valores['persona'] ); ?>" maxlength="120" required></label>
			<label for="asde-ag-dni"><?php esc_html_e( 'ID or foreigner ID', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <input type="text" id="asde-ag-dni" name="dni" value="<?php echo esc_attr( $valores['dni'] ); ?>" maxlength="20" size="12" autocapitalize="characters" spellcheck="false" required></label>
			<label for="asde-ag-rol"><?php esc_html_e( 'Role', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?>
				<select id="asde-ag-rol" name="rol" required>
					<?php foreach ( GrupoSolicitudes::ROLES as $rol => $etiqueta ) : ?>
						<option value="<?php echo esc_attr( $rol ); ?>" <?php selected( $valores['rol'], $rol ); ?>><?php echo esc_html( asde_t( $etiqueta ) ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
		</p>

		<p class="asde-help"><?php esc_html_e( 'This data is stored encrypted and only the office sees it. After sending, you will be notified when it is reviewed.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
		<p><button type="submit" class="button" name="asde_action" value="alta_grupo_enviar" <?php disabled( ! $verificada ); ?>><?php esc_html_e( 'Send the registration', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button></p>
	</form>
</section>
