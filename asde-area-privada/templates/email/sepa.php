<?php
/**
 * Emails de la domiciliación SEPA: confirmación de la autorización y aviso previo de un cargo.
 *
 * @var string $tipo     autorizacion | preaviso
 * @var string $nombre   Saludo (leído del CRM; puede ir vacío)
 * @var string $grupo
 * @var string $umr      Referencia del mandato
 * @var string $iban     IBAN enmascarado
 * @var string $importe  «12,50 €» (solo en el aviso previo)
 * @var string $fecha    Fecha del cargo, dd/mm/aaaa (solo en el aviso previo)
 * @var string $concepto Concepto del cargo (solo en el aviso previo)
 * @var string $url      «Mis pagos»
 * @var string $contacto Email de contacto del grupo ('' si no hay)
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Frontend\Colores;
use AsdeBalears\AreaPrivada\Idiomas\Idioma;

defined( 'ABSPATH' ) || exit;

$col = Colores::para_email(); // Los colores elegidos en wp-admin → Colores (los emails no usan el CSS del plugin).

$aviso = 'preaviso' === $tipo;
?>
<!DOCTYPE html>
<html lang="<?php echo esc_attr( Idioma::actual() ); ?>">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width"></head>
<body style="margin:0;padding:0;background:<?php echo esc_attr( $col['fondo'] ); ?>;font-family:'Open Sans',Helvetica,Arial,sans-serif;color:<?php echo esc_attr( $col['texto'] ); ?>;">
	<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:<?php echo esc_attr( $col['fondo'] ); ?>;padding:24px 12px;">
		<tr><td align="center">
			<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:560px;background:<?php echo esc_attr( $col['tarjeta'] ); ?>;border-radius:6px;overflow:hidden;">
				<tr><td style="background:<?php echo esc_attr( $col['primary'] ); ?>;color:<?php echo esc_attr( $col['on_primary'] ); ?>;padding:20px 28px;font-size:18px;font-weight:600;">
					<?php echo esc_html( $grupo ); ?> · <?php esc_html_e( 'SEPA direct debit', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?>
				</td></tr>
				<tr><td style="padding:28px;font-size:15px;line-height:1.6;">
					<p style="margin:0 0 16px;"><?php echo wp_kses( $nombre ? sprintf( /* translators: %s: text */ __( 'Hello, %s:', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), esc_html( $nombre ) ) : __( 'Hello:', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), asde_html_permitido() ); ?></p>
					<?php if ( $aviso ) : ?>
						<p style="margin:0 0 16px;"><?php echo wp_kses( sprintf( /* translators: 1: text, 2: text, 3: text, 4: text */ __( 'We let you know that <strong>%1$s</strong> will be charged <strong>%2$s</strong> to the account <strong>%3$s</strong>, for "%4$s".', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), esc_html( $fecha ), esc_html( $importe ), esc_html( $iban ), esc_html( $concepto ) ), asde_html_permitido() ); ?></p>
						<p style="margin:0 0 16px;font-size:13px;color:<?php echo esc_attr( $col['atenuado'] ); ?>;"><?php echo wp_kses( sprintf( /* translators: %s: text */ __( 'It is a SEPA direct debit, with the authorisation you signed (reference %s). You can see the charge and your payments in "My payments". If you disagree, let us know before that date.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), esc_html( $umr ) ), asde_html_permitido() ); ?></p>
					<?php else : ?>
						<p style="margin:0 0 16px;"><?php echo wp_kses( sprintf( /* translators: %s: text */ __( 'We have saved your <strong>SEPA direct debit authorisation</strong> for the payments of %s.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), esc_html( $grupo ) ), asde_html_permitido() ); ?></p>
						<p style="margin:0 0 16px;"><?php echo wp_kses( sprintf( /* translators: %s: text */ __( 'Account: <strong>%s</strong>', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), esc_html( $iban ) ), asde_html_permitido() ); ?><br><?php echo wp_kses( sprintf( /* translators: %s: text */ __( 'Authorisation reference: <strong>%s</strong>', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), esc_html( $umr ) ), asde_html_permitido() ); ?></p>
						<p style="margin:0 0 16px;font-size:13px;color:<?php echo esc_attr( $col['atenuado'] ); ?>;"><?php esc_html_e( 'We will notify you before each charge, with its date and amount. You can see the authorisation, print it or cancel it at any time from "My payments".', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
					<?php endif; ?>
					<?php if ( $url ) : ?>
						<p style="margin:0 0 24px;text-align:center;">
							<a href="<?php echo esc_url( $url ); ?>" style="display:inline-block;background:<?php echo esc_attr( $col['primary'] ); ?>;color:<?php echo esc_attr( $col['on_primary'] ); ?>;text-decoration:none;padding:12px 28px;border-radius:4px;font-weight:600;"><?php esc_html_e( 'Go to My payments', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a>
						</p>
					<?php endif; ?>
					<p style="margin:0;font-size:13px;color:<?php echo esc_attr( $col['atenuado'] ); ?>;">
						<?php if ( '' !== $contacto ) : ?>
							<?php echo wp_kses( sprintf( /* translators: %s: text */ __( 'Questions? Write to %s.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), '<a href="mailto:' . esc_attr( $contacto ) . '" style="color:' . esc_attr( $col['primary'] ) . ';">' . esc_html( $contacto ) . '</a>' ), asde_html_permitido() ); ?>
						<?php else : ?>
							<?php esc_html_e( 'Questions? Talk to your group\'s Treasury.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?>
						<?php endif; ?>
					</p>
				</td></tr>
			</table>
		</td></tr>
	</table>
</body>
</html>
