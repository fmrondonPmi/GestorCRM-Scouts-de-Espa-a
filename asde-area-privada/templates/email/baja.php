<?php
/**
 * Aviso de una baja dada desde la web (HTML con estilos en línea; doc 34, P-90).
 * A Tesorería: quién, desde cuándo, el motivo y su familia. A la familia (o a la propia
 * persona, si es mayor de edad): la confirmación, sin el motivo.
 *
 * @var string $para     Usuarios\AvisoBaja::TESORERIA o ::FAMILIA
 * @var string $nombre   Cómo se llama quien lo recibe ('' si no se sabe)
 * @var string $persona  Nombre de quien causa baja
 * @var string $socio    Su nº de socio/a de Scouts de España
 * @var string $fin      Fecha de baja (AAAA-MM-DD)
 * @var string $motivo   Motivo, ya traducido ('' si no hay)
 * @var string $familia  Nº de su familia en Tesorería ('' si no está en ninguna)
 * @var string $grupo    Nombre del grupo
 * @var string $contacto Email de contacto del grupo ('' si no hay)
 * @var string $url      Tesorería en el área privada
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Frontend\Colores;
use AsdeBalears\AreaPrivada\Idiomas\Idioma;
use AsdeBalears\AreaPrivada\Usuarios\AvisoBaja;

defined( 'ABSPATH' ) || exit;

$col   = Colores::para_email(); // Los colores elegidos en wp-admin → Colores (los emails no usan el CSS del plugin).
$f     = DateTime::createFromFormat( '!Y-m-d', $fin );
$fecha = $f ? $f->format( 'd/m/Y' ) : $fin;
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
					<?php if ( AvisoBaja::TESORERIA === $para ) : ?>
						<p style="margin:0 0 16px;"><?php echo wp_kses( sprintf( /* translators: 1: text, 2: text, 3: text */ __( '<strong>%1$s</strong> (member no. %2$s) has been withdrawn in the CRM as of <strong>%3$s</strong>.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), esc_html( $persona ), esc_html( $socio ), esc_html( $fecha ) ), asde_html_permitido() ); ?></p>
						<?php if ( '' !== $motivo ) : ?>
							<p style="margin:0 0 16px;"><strong><?php esc_html_e( 'Reason:', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></strong> <?php echo esc_html( $motivo ); ?></p>
						<?php endif; ?>
						<?php if ( '' !== $familia ) : ?>
							<p style="margin:0 0 16px;"><?php echo wp_kses( sprintf( /* translators: %s: text */ __( 'They belong to the family <strong>%s</strong>: since the withdrawal they no longer count in its fee. Check whether anything needs adjusting in their payments.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), esc_html( $familia ) ), asde_html_permitido() ); ?></p>
						<?php else : ?>
							<p style="margin:0 0 16px;"><?php esc_html_e( 'Not in any Treasury family.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
						<?php endif; ?>
						<p style="margin:0 0 24px;text-align:center;">
							<a href="<?php echo esc_url( $url ); ?>" style="display:inline-block;background:<?php echo esc_attr( $col['primary'] ); ?>;color:<?php echo esc_attr( $col['on_primary'] ); ?>;padding:12px 24px;border-radius:4px;text-decoration:none;font-weight:600;"><?php esc_html_e( 'Go to Treasury', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a>
						</p>
						<p style="margin:0;font-size:13px;color:<?php echo esc_attr( $col['atenuado'] ); ?>;"><?php esc_html_e( 'You are receiving this because you have the Treasury responsibility in the group\'s private area. You will need to sign in to see it.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
					<?php else : ?>
						<p style="margin:0 0 16px;"><?php echo wp_kses( sprintf( /* translators: 1: text, 2: text, 3: text */ __( 'We confirm that <strong>%1$s</strong> left <strong>%2$s</strong> on %3$s.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), esc_html( $persona ), esc_html( $grupo ), esc_html( $fecha ) ), asde_html_permitido() ); ?></p>
						<p style="margin:0 0 16px;"><?php esc_html_e( 'From that day they no longer count as a member of the group, neither in the fees nor otherwise, and their access to the private area is closed. Their record is kept: if they ever return, they just need to be registered again.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
						<?php if ( '' !== $contacto ) : ?>
							<p style="margin:0 0 16px;"><?php echo wp_kses( sprintf( /* translators: %s: text */ __( 'If this is a mistake or you have any questions, write to us at %s.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), '<a href="mailto:' . esc_attr( $contacto ) . '" style="color:' . esc_attr( $col['primary'] ) . ';font-weight:600;">' . esc_html( $contacto ) . '</a>' ), asde_html_permitido() ); ?></p>
						<?php else : ?>
							<p style="margin:0 0 16px;"><?php esc_html_e( 'If this is a mistake or you have any questions, talk to the group\'s Secretary\'s office.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
						<?php endif; ?>
						<p style="margin:0;"><?php esc_html_e( 'Thank you for everything you shared!', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
					<?php endif; ?>
				</td></tr>
			</table>
		</td></tr>
	</table>
</body>
</html>
