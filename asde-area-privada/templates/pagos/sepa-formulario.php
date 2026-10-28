<?php
/**
 * Formulario de la autorización SEPA (se incluye desde pagos/sepa.php, con sus variables).
 *
 * @var array    $familia
 * @var array    $acreedor
 * @var string[] $texto
 * @var callable $campo    Valor escrito en el intento anterior, o el de por defecto
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
defined( 'ABSPATH' ) || exit;

$fid = (int) $familia['id'];
?>
<form method="post" class="asde-form" autocomplete="on">
	<?php wp_nonce_field( 'asde_sepa_firmar_' . $fid ); ?>
	<input type="hidden" name="asde_action" value="sepa_firmar">
	<input type="hidden" name="familia" value="<?php echo absint( $fid ); ?>">

	<fieldset>
		<legend><?php esc_html_e( 'Debtor details (account holder)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></legend>
		<p>
			<label for="asde-sepa-titular-<?php echo absint( $fid ); ?>"><?php esc_html_e( 'Account holder\'s first name and surname', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
			<input type="text" id="asde-sepa-titular-<?php echo absint( $fid ); ?>" name="titular" maxlength="70" required autocomplete="name" value="<?php echo esc_attr( $campo( 'titular' ) ); ?>">
		</p>
		<p>
			<label for="asde-sepa-iban-<?php echo absint( $fid ); ?>"><?php esc_html_e( 'Account IBAN', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
			<input type="text" id="asde-sepa-iban-<?php echo absint( $fid ); ?>" name="iban" maxlength="40" required inputmode="text" autocapitalize="characters" spellcheck="false" placeholder="<?php echo esc_attr( esc_attr__( 'ES00 0000 0000 0000 0000 0000', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>" value="<?php echo esc_attr( $campo( 'iban' ) ); ?>">
			<span class="asde-help"><?php esc_html_e( 'Spanish account (24 characters). It is stored, with the holder and the bank, on the CRM record of each family member at Scouts de España; the website only stores your authorisation.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span>
		</p>
		<p>
			<label for="asde-sepa-entidad-<?php echo absint( $fid ); ?>"><?php esc_html_e( 'Bank (optional)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
			<input type="text" id="asde-sepa-entidad-<?php echo absint( $fid ); ?>" name="entidad" maxlength="70" autocomplete="off" value="<?php echo esc_attr( $campo( 'entidad' ) ); ?>">
		</p>
		<p>
			<label for="asde-sepa-calle-<?php echo absint( $fid ); ?>"><?php esc_html_e( 'Address (street and number)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
			<input type="text" id="asde-sepa-calle-<?php echo absint( $fid ); ?>" name="calle" maxlength="70" required autocomplete="street-address" value="<?php echo esc_attr( $campo( 'calle' ) ); ?>">
		</p>
		<div class="asde-grid-2">
			<p>
				<label for="asde-sepa-cp-<?php echo absint( $fid ); ?>"><?php esc_html_e( 'Postcode', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
				<input type="text" id="asde-sepa-cp-<?php echo absint( $fid ); ?>" name="cp" maxlength="10" required autocomplete="postal-code" value="<?php echo esc_attr( $campo( 'cp' ) ); ?>">
			</p>
			<p>
				<label for="asde-sepa-poblacion-<?php echo absint( $fid ); ?>"><?php esc_html_e( 'Town', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
				<input type="text" id="asde-sepa-poblacion-<?php echo absint( $fid ); ?>" name="poblacion" maxlength="50" required autocomplete="address-level2" value="<?php echo esc_attr( $campo( 'poblacion' ) ); ?>">
			</p>
			<p>
				<label for="asde-sepa-provincia-<?php echo absint( $fid ); ?>"><?php esc_html_e( 'Province', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
				<input type="text" id="asde-sepa-provincia-<?php echo absint( $fid ); ?>" name="provincia" maxlength="40" autocomplete="address-level1" value="<?php echo esc_attr( $campo( 'provincia', __( 'Illes Balears', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) ); ?>">
			</p>
			<p>
				<label for="asde-sepa-pais-<?php echo absint( $fid ); ?>"><?php esc_html_e( 'Country', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
				<input type="text" id="asde-sepa-pais-<?php echo absint( $fid ); ?>" name="pais" maxlength="40" autocomplete="country-name" value="<?php echo esc_attr( $campo( 'pais', 'España' ) ); ?>">
			</p>
		</div>
	</fieldset>

	<fieldset>
		<legend><?php esc_html_e( 'Creditor details', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></legend>
		<p class="asde-muted">
			<strong><?php echo esc_html( $acreedor['nombre'] ); ?></strong> <?php esc_html_e( '· Creditor identifier', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <?php echo esc_html( $acreedor['ics'] ); ?>
			<?php echo wp_kses( '' !== trim( $acreedor['calle'] . $acreedor['poblacion'] ) ? '<br>' . esc_html( trim( $acreedor['calle'] . ', ' . trim( $acreedor['cp'] . ' ' . $acreedor['poblacion'] ), ' ,' ) ) : '', asde_html_permitido() ); ?>
		</p>
	</fieldset>

	<div class="asde-destacado">
		<?php foreach ( $texto as $parrafo ) : ?>
			<p class="asde-muted"><?php echo esc_html( $parrafo ); ?></p>
		<?php endforeach; ?>
	</div>

	<p>
		<label class="asde-choice">
			<input type="checkbox" name="acepto" value="1" required>
			<span><?php esc_html_e( 'I am the account holder (or I am authorised to sign on their behalf) and', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <strong><?php esc_html_e( 'I sign this SEPA direct debit mandate', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></strong><?php esc_html_e( '. The electronic signature is recorded with the date and time.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span>
		</label>
	</p>
	<p><button type="submit" class="button"><?php esc_html_e( 'Sign the authorisation', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button></p>
</form>
