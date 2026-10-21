<?php
/**
 * Email de invitación a dar de alta un grupo (v0.50, doc 50 fase 5; HTML con estilos en línea).
 *
 * @var string $organizacion Nombre de la organización (la oficina que invita)
 * @var string $url          Enlace de un solo uso
 * @var int    $dias         Días de validez
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Frontend\Colores;
use AsdeBalears\AreaPrivada\Idiomas\Idioma;

defined( 'ABSPATH' ) || exit;

$col = Colores::para_email();
?>
<!DOCTYPE html>
<html lang="<?php echo esc_attr( Idioma::actual() ); ?>">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width"></head>
<body style="margin:0;padding:0;background:<?php echo esc_attr( $col['fondo'] ); ?>;font-family:'Open Sans',Helvetica,Arial,sans-serif;color:<?php echo esc_attr( $col['texto'] ); ?>;">
	<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:<?php echo esc_attr( $col['fondo'] ); ?>;padding:24px 12px;">
		<tr><td align="center">
			<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:560px;background:<?php echo esc_attr( $col['tarjeta'] ); ?>;border-radius:6px;overflow:hidden;">
				<tr><td style="background:<?php echo esc_attr( $col['primary'] ); ?>;color:<?php echo esc_attr( $col['on_primary'] ); ?>;padding:20px 28px;font-size:18px;font-weight:600;">
					<?php echo esc_html( $organizacion ); ?> · <?php asde_e( 'Área privada' ); ?>
				</td></tr>
				<tr><td style="padding:28px;font-size:15px;line-height:1.6;">
					<p style="margin:0 0 16px;"><?php asde_e( 'Hola:' ); ?></p>
					<p style="margin:0 0 16px;"><?php echo sprintf( asde_h( 'La oficina de <strong>%s</strong> te invita a dar de alta tu grupo en el área privada.' ), esc_html( $organizacion ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></p>
					<p style="margin:0 0 24px;text-align:center;"><a href="<?php echo esc_url( $url ); ?>" style="display:inline-block;background:<?php echo esc_attr( $col['primary'] ); ?>;color:<?php echo esc_attr( $col['on_primary'] ); ?>;text-decoration:none;font-weight:600;padding:12px 24px;border-radius:4px;"><?php asde_e( 'Dar de alta el grupo' ); ?></a></p>
					<p style="margin:0 0 16px;"><?php echo esc_html( sprintf( asde_t( 'El enlace es de un solo uso y vale %d días. Necesitarás el usuario y la contraseña del CRM de tu grupo.' ), $dias ) ); ?></p>
					<p style="margin:0;font-size:13px;color:<?php echo esc_attr( $col['atenuado'] ); ?>;"><?php asde_e( 'Si no esperabas este correo, puedes ignorarlo.' ); ?></p>
				</td></tr>
			</table>
		</td></tr>
	</table>
</body>
</html>
