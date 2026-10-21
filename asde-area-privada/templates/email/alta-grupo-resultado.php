<?php
/**
 * Email al grupo cuando la oficina revisa su alta (v0.50, doc 50 fase 6; HTML con estilos en línea). El rechazo es
 * genérico: no lleva motivo.
 *
 * @var string $organizacion Nombre de la organización (la oficina)
 * @var string $grupo        Nombre del grupo
 * @var bool   $aprobada
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
					<?php if ( $aprobada ) : ?>
						<p style="margin:0 0 16px;"><?php echo sprintf( asde_h( 'La oficina ha revisado el alta de <strong>%s</strong>: <strong>el grupo ya está dado de alta</strong> en el área privada.' ), esc_html( $grupo ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></p>
						<p style="margin:0;"><?php asde_e( 'La primera persona con acceso recibirá un email para activar su cuenta.' ); ?></p>
					<?php else : ?>
						<p style="margin:0 0 16px;"><?php echo sprintf( asde_h( 'La oficina ha revisado el alta de <strong>%s</strong> y, con los datos recibidos, <strong>no ha podido darla</strong>.' ), esc_html( $grupo ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></p>
						<p style="margin:0;"><?php asde_e( 'Si crees que es un error o quieres volver a intentarlo, ponte en contacto con la oficina.' ); ?></p>
					<?php endif; ?>
				</td></tr>
			</table>
		</td></tr>
	</table>
</body>
</html>
