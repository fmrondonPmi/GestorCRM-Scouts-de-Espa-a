<?php
/**
 * Autorización (orden de domiciliación de adeudo directo SEPA) para ver e imprimir.
 *
 * @var array       $mandato
 * @var array       $familia
 * @var array       $acreedor
 * @var string[]    $texto
 * @var bool        $completo  Se enseña el IBAN entero (quien puede firmar); si no, enmascarado
 * @var WP_User|null $firmante
 * @var string      $url       Mis pagos
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Sepa\Iban;
use AsdeBalears\AreaPrivada\Sepa\Mandatos;

defined( 'ABSPATH' ) || exit;

$d       = $mandato['datos'];
$activo  = Mandatos::ACTIVO === $mandato['estado'];
$fecha   = static fn( string $f ) => '' !== $f ? wp_date( 'd/m/Y', (int) strtotime( $f ) ) : '';
$dir_acr = trim( $acreedor['calle'] . ', ' . trim( $acreedor['cp'] . ' ' . $acreedor['poblacion'] ), ' ,' );
$dir_deu = trim( implode( ', ', array_filter( array( $d['calle'], trim( $d['cp'] . ' ' . $d['poblacion'] ), $d['provincia'], $d['pais'] ) ) ) );
?>
<p class="asde-back asde-no-print"><a href="<?php echo esc_url( $url ); ?>"><?php esc_html_e( '← My payments', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a></p>

<section class="asde-card asde-autorizacion">
	<header class="asde-card__header asde-card__header--split">
		<h2 class="asde-card__title"><?php esc_html_e( 'SEPA direct debit mandate', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h2>
		<span class="asde-badge asde-badge--<?php echo $activo ? 'estado-activo' : 'estado-desactivado'; ?>"><?php echo $activo ? esc_html( __( 'In force', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) : esc_html( __( 'Cancelled', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?></span>
	</header>

	<dl class="asde-datos">
			<div><dt><?php esc_html_e( 'Direct debit mandate reference', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></dt><dd><strong><?php echo esc_html( $mandato['referencia'] ); ?></strong></dd></div>
			<div><dt><?php esc_html_e( 'Creditor identifier', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></dt><dd><?php echo esc_html( $acreedor['ics'] ); ?></dd></div>
			<div><dt><?php esc_html_e( 'Creditor\'s name', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></dt><dd><?php echo esc_html( $acreedor['nombre'] ); ?></dd></div>
			<?php if ( '' !== $dir_acr ) : ?>
				<div><dt><?php esc_html_e( 'Creditor\'s address', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></dt><dd><?php echo esc_html( $dir_acr ); ?></dd></div>
			<?php endif; ?>
			<div><dt><?php esc_html_e( 'Debtor\'s name', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></dt><dd><?php echo $cuenta && '' !== $cuenta['titular'] ? esc_html( $cuenta['titular'] ) : '—'; ?></dd></div>
			<?php if ( '' !== $dir_deu ) : ?>
				<div><dt><?php esc_html_e( 'Debtor\'s address', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></dt><dd><?php echo esc_html( $dir_deu ); ?></dd></div>
			<?php endif; ?>
			<div><dt><?php esc_html_e( 'Direct debit account (IBAN)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></dt><dd><?php echo esc_html( $completo && $cuenta ? Iban::formatear( $cuenta['iban'] ) : $mandato['iban_mask'] ); ?></dd></div>
			<div><dt><?php esc_html_e( 'Payment type', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></dt><dd><?php esc_html_e( 'Recurrent', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></dd></div>
			<div><dt><?php esc_html_e( 'Family (Treasury)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></dt><dd><?php echo esc_html( $familia['numero'] ); ?></dd></div>
			<div><dt><?php esc_html_e( 'Date and place of signing', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></dt><dd><?php echo esc_html( $fecha( $mandato['fecha_firma'] ) . ( '' !== $d['lugar'] ? ', ' . $d['lugar'] : '' ) ); ?></dd></div>
			<div>
			<dt><?php esc_html_e( 'Signature', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></dt>
			<dd>
					<?php esc_html_e( 'Signed electronically from the private area', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?>
					<?php echo $firmante ? esc_html( sprintf( /* translators: %s: text */ __( 'from the account %s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $firmante->user_login ) ) : ''; ?>
					<?php echo esc_html( sprintf( /* translators: 1: text, 2: text */ __( 'on %1$s at %2$s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), get_date_from_gmt( $mandato['firmado_en'], 'd/m/Y' ), get_date_from_gmt( $mandato['firmado_en'], 'H:i' ) ) ); ?>
					<?php esc_html_e( '(authorisation text, version', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <?php echo esc_html( $mandato['texto_version'] ); ?>).
				</dd>
		</div>
			<?php if ( ! $activo && $mandato['revocado_en'] ) : ?>
				<div><dt><?php esc_html_e( 'Cancelled', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></dt><dd><?php echo esc_html( get_date_from_gmt( $mandato['revocado_en'], 'd/m/Y' ) . ( '' !== $mandato['motivo'] ? ' · ' . $mandato['motivo'] : '' ) ); ?></dd></div>
			<?php endif; ?>
		</dl>

	<?php foreach ( $texto as $parrafo ) : ?>
		<p><?php echo esc_html( $parrafo ); ?></p>
	<?php endforeach; ?>

	<p class="asde-no-print"><button type="button" class="button" data-asde-imprimir-pagina><?php esc_html_e( 'Print or save as PDF', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button></p>
</section>
