<?php
/**
 * Ficha · datos bancarios y domiciliación SEPA (v0.33, doc 25 §9, P-131): la cuenta está en la
 * ficha del CRM (iban_c, iban_titular_c, iban_entidad_c) y aquí solo se enseña, enmascarada.
 * Se cambia firmando una autorización en «Mis pagos», que la guarda en la ficha de cada miembro
 * de la familia.
 *
 * @var array{iban:string, titular:string, entidad:string}|null $cuenta  La de esta ficha (null: no tiene)
 * @var array|null  $familia  Familia de Tesorería de esta persona
 * @var array|null  $mandato  Autorización vigente de la familia
 * @var bool        $coincide La cuenta de la ficha es la autorizada
 * @var string      $url_gestion «Mis pagos» (familia) o Tesorería → Domiciliaciones ('' si no hay enlace)
 * @var string      $texto_gestion
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Sepa\Iban;

defined( 'ABSPATH' ) || exit;

$fecha = static fn( string $f ) => '' !== $f ? wp_date( 'd/m/Y', (int) strtotime( $f ) ) : '';
?>
<section class="asde-card asde-ficha-sepa" data-asde-plegable="ficha-sepa">
	<h3 class="asde-card__title"><?php esc_html_e( 'Bank details and SEPA direct debit', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h3>
	<dl class="asde-datos">
		<?php if ( $cuenta ) : ?>
			<div><dt><?php esc_html_e( 'Account (IBAN)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></dt><dd><?php echo esc_html( Iban::enmascarar( $cuenta['iban'] ) ); ?></dd></div>
			<div><dt><?php esc_html_e( 'Holder', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></dt><dd><?php echo esc_html( '' !== $cuenta['titular'] ? $cuenta['titular'] : '—' ); ?></dd></div>
			<div><dt><?php esc_html_e( 'Entity', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></dt><dd><?php echo esc_html( '' !== $cuenta['entidad'] ? $cuenta['entidad'] : '—' ); ?></dd></div>
		<?php else : ?>
			<div><dt><?php esc_html_e( 'Account (IBAN)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></dt><dd><?php esc_html_e( 'No bank details on the record', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></dd></div>
		<?php endif; ?>
		<div>
			<dt><?php esc_html_e( 'Direct debit authorisation (SEPA)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></dt>
			<dd>
				<?php if ( $mandato ) : ?>
					<?php /* translators: 1: reference, 2: date */ echo esc_html( sprintf( __( 'In force · %1$s · signed on %2$s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $mandato['referencia'], $fecha( (string) $mandato['fecha_firma'] ) ) ); ?>
					<?php if ( ! $coincide ) : ?>
						<br><span class="asde-help asde-help--warning"><?php esc_html_e( '⚠️ The account on this record is not the authorised one: it will not be charged until a new authorisation is signed.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span>
					<?php endif; ?>
				<?php elseif ( $familia ) : ?>
					<?php esc_html_e( 'No authorisation: the family pays by transfer.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?>
				<?php else : ?>
					<?php esc_html_e( 'Not in any Treasury family.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?>
				<?php endif; ?>
			</dd>
		</div>
	</dl>
	<p class="asde-help"><?php esc_html_e( 'Bank details are stored on the Scouts de España CRM record (on that of each family member). To set or change them, the mother, father, guardian or the adult member themselves signs an authorisation in "My payments".', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
	<?php if ( '' !== $url_gestion ) : ?>
		<p><a href="<?php echo esc_url( $url_gestion ); ?>"><?php echo esc_html( $texto_gestion ); ?></a></p>
	<?php endif; ?>
</section>
