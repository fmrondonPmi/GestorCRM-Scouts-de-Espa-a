<?php
/**
 * Email a quien pidió el acceso cuando se rechaza su solicitud (HTML con estilos en línea).
 * Es un texto genérico: el motivo que anota quien la revisa es solo para el grupo.
 *
 * @var string $nombre   Cómo se llama quien pidió (lo que escribió en el formulario; puede ir vacío)
 * @var string $grupo    Nombre del grupo
 * @var string $contacto Email de contacto del grupo ('' si no hay)
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Frontend\Colores;
use AsdeBalears\AreaPrivada\Idiomas\Idioma;

defined( 'ABSPATH' ) || exit;

$col = Colores::para_email(); // Los colores elegidos en wp-admin → Colores (los emails no usan el CSS del plugin).
?>
<!DOCTYPE html>
<html lang="<?php echo esc_attr( Idioma::actual() ); ?>">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width"></head>
<body style="margin:0;padding:0;background:<?php echo esc_attr( $col['fondo'] ); ?>;font-family:'Open Sans',Helvetica,Arial,sans-serif;color:<?php echo esc_attr( $col['texto'] ); ?>;">
	<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:<?php echo esc_attr( $col['fondo'] ); ?>;padding:24px 12px;">
		<tr><td align="center">
			<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:560px;background:<?php echo esc_attr( $col['tarjeta'] ); ?>;border-radius:6px;overflow:hidden;">
				<tr><td style="background:<?php echo esc_attr( $col['primary'] ); ?>;color:<?php echo esc_attr( $col['on_primary'] ); ?>;padding:20px 28px;font-size:18px;font-weight:600;">
					<?php echo esc_html( $grupo ); ?> · <?php esc_html_e( 'Private area', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?>
				</td></tr>
				<tr><td style="padding:28px;font-size:15px;line-height:1.6;">
					<p style="margin:0 0 16px;"><?php echo wp_kses( '' !== $nombre ? sprintf( /* translators: %s: text */ __( 'Hello, %s:', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), esc_html( $nombre ) ) : __( 'Hello:', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), asde_html_permitido() ); ?></p>
					<p style="margin:0 0 16px;"><?php echo wp_kses( sprintf( /* translators: %s: text */ __( 'We have reviewed your request for access to the private area of <strong>%s</strong> and, with the details you sent us, <strong>we have not been able to give you access</strong>.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), esc_html( $grupo ) ), asde_html_permitido() ); ?></p>
					<p style="margin:0 0 16px;"><?php esc_html_e( 'This is usually because some detail does not match the member\'s record or because the record is not up to date. If you think this is a mistake, or want to try again, contact us:', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
					<?php if ( '' !== $contacto ) : ?>
						<p style="margin:0 0 16px;"><?php echo wp_kses( sprintf( /* translators: %s: text */ __( 'Write to %s, with the member\'s name and your relationship to them (mother, father, guardian or the member themselves).', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), '<a href="mailto:' . esc_attr( $contacto ) . '" style="color:' . esc_attr( $col['primary'] ) . ';font-weight:600;">' . esc_html( $contacto ) . '</a>' ), asde_html_permitido() ); ?></p>
					<?php else : ?>
						<p style="margin:0 0 16px;"><?php esc_html_e( 'Talk to your group\'s Secretary\'s office, telling them the member\'s name and your relationship to them (mother, father, guardian or the member themselves).', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
					<?php endif; ?>
					<p style="margin:0;font-size:13px;color:<?php echo esc_attr( $col['atenuado'] ); ?>;"><?php esc_html_e( 'If you did not request this access, you can ignore this email.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
				</td></tr>
			</table>
		</td></tr>
	</table>
</body>
</html>
