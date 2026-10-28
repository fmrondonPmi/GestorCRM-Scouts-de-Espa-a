<?php
/**
 * Mis pagos · Domiciliación SEPA de una familia: autorización vigente o formulario para firmarla.
 *
 * @var array       $familia   [id, numero, miembros]
 * @var array|null  $mandato   Autorización vigente (Mandatos::activo_de_familia)
 * @var array|null|false $cuenta La cuenta autorizada, de la ficha del CRM (null: la de la ficha ya no es la autorizada; false: el CRM no responde)
 * @var true|string $puede     true si puede firmar/anular; si no, el motivo
 * @var array[]     $proximos  Próximos adeudos de la familia (Remesas::proximos_de_familia)
 * @var bool        $lista     El grupo tiene activadas las domiciliaciones y sus datos completos
 * @var array       $acreedor  Configuración del acreedor (Sepa\Config::de)
 * @var string[]    $texto     Párrafos de la autorización
 * @var array|null  $valores   Lo escrito en el intento anterior (con errores)
 * @var string      $url       Mis pagos
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Sepa\Iban;
use AsdeBalears\AreaPrivada\Tesoreria\Calculadora;

defined( 'ABSPATH' ) || exit;

if ( ! $mandato && ! $lista ) {
	return; // El grupo aún no ofrece la domiciliación.
}
$v      = is_array( $valores ) ? $valores : array();
$campo  = static fn( string $k, string $defecto = '' ) => isset( $v[ $k ] ) ? (string) $v[ $k ] : $defecto;
$fecha  = static fn( string $f ) => '' !== $f ? wp_date( 'd/m/Y', (int) strtotime( $f ) ) : '';
?>
<section class="asde-card" id="sepa-<?php echo (int) $familia['id']; ?>">
	<header class="asde-card__header asde-card__header--split">
		<h3 class="asde-card__title"><?php esc_html_e( 'SEPA direct debit · family', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <?php echo esc_html( $familia['numero'] ); ?></h3>
		<?php if ( $mandato ) : ?>
			<span class="asde-badge asde-badge--pago-pagado"><?php esc_html_e( 'Authorised', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span>
		<?php else : ?>
			<span class="asde-badge asde-badge--pago-pendiente"><?php esc_html_e( 'Not authorised', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span>
		<?php endif; ?>
	</header>

	<?php if ( $proximos ) : ?>
		<div class="asde-notice asde-notice--info" role="status">
			<strong><?php esc_html_e( 'Next charge:', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></strong>
			<?php foreach ( $proximos as $p ) : ?>
				<br><?php echo esc_html( sprintf( /* translators: 1: text, 2: text, 3: text, 4: text */ __( '%1$s · %2$s on account %3$s (authorisation %4$s)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $fecha( $p['fecha'] ), Calculadora::euros( $p['centimos'] / 100 ), '' !== $p['iban'] ? $p['iban'] : 'autorizada', $p['umr'] ) ); ?>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>

	<?php if ( $mandato ) : ?>
		<p><?php esc_html_e( 'You have authorised', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <strong><?php echo esc_html( $acreedor['nombre'] ?: __( 'the group', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?></strong> <?php esc_html_e( 'to collect the group\'s payments by SEPA direct debit from the account', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <strong><?php echo esc_html( $mandato['iban_mask'] ); ?></strong>.</p>
		<ul class="asde-resumen">
			<li><?php esc_html_e( 'Reference:', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <strong><?php echo esc_html( $mandato['referencia'] ); ?></strong></li>
			<li><?php esc_html_e( 'Signed on', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <strong><?php echo esc_html( $fecha( $mandato['fecha_firma'] ) ); ?></strong></li>
			<?php if ( is_array( $cuenta ) && '' !== $cuenta['titular'] ) : ?>
				<li><?php esc_html_e( 'Holder:', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <strong><?php echo esc_html( $cuenta['titular'] ); ?></strong></li>
			<?php endif; ?>
		</ul>
		<?php if ( null === $cuenta ) : ?>
			<p class="asde-help asde-help--warning"><?php esc_html_e( '⚠️ The account on the CRM record is no longer the one you authorised: until you sign a new authorisation nothing will be charged by direct debit.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
		<?php endif; ?>
		<p class="asde-help"><?php esc_html_e( 'The account details are stored on the CRM record of each family member at Scouts de España; here it is only shown masked.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
		<div class="asde-actions">
			<a class="button" href="<?php echo esc_url( add_query_arg( 'autorizacion', (int) $mandato['id'], $url ) ); ?>"><?php esc_html_e( 'View and print the authorisation', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a>
			<?php if ( true === $puede ) : ?>
				<form method="post" data-asde-confirmar="<?php echo esc_attr( __( 'Cancel the authorisation? From now on nothing will be charged to this account.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
					<?php wp_nonce_field( 'asde_sepa_anular_' . (int) $mandato['id'] ); ?>
					<input type="hidden" name="asde_action" value="sepa_anular">
					<input type="hidden" name="mandato" value="<?php echo (int) $mandato['id']; ?>">
					<button type="submit" class="button asde-button-secundario"><?php esc_html_e( 'Cancel the authorisation', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
				</form>
			<?php endif; ?>
		</div>
		<?php if ( true === $puede && $lista ) : ?>
			<details class="asde-rechazar" <?php echo $v ? 'open' : ''; ?>>
				<summary><?php esc_html_e( 'Change account or holder (sign another authorisation)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></summary>
				<?php include __DIR__ . '/sepa-formulario.php'; ?>
			</details>
		<?php endif; ?>
	<?php else : ?>
		<p><?php esc_html_e( 'If you prefer not to pay by transfer, you can', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <strong><?php esc_html_e( 'authorise collection by bank direct debit (SEPA direct debit)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></strong><?php esc_html_e( ': the group will collect this family\'s fee from your account, always notifying you beforehand with the date and amount. You can cancel it at any time.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
		<?php if ( true === $puede ) : ?>
			<details class="asde-rechazar" <?php echo $v ? 'open' : ''; ?>>
				<summary><?php esc_html_e( 'Fill in the authorisation', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></summary>
				<?php include __DIR__ . '/sepa-formulario.php'; ?>
			</details>
		<?php else : ?>
			<p class="asde-help asde-help--warning"><?php echo esc_html( (string) $puede ); ?></p>
		<?php endif; ?>
	<?php endif; ?>
</section>
