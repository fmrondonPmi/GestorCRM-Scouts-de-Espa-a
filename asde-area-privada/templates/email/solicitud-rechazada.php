<?php
/**
 * Email a quien pidió el acceso cuando se rechaza su solicitud (HTML con estilos en línea).
 * Es un texto genérico: el motivo que anota quien la revisa es solo para el grupo.
 *
 * @var string $nombre   Cómo se llama quien pidió (lo que escribió en el formulario; puede ir vacío)
 * @var string $grupo    Nombre del grupo
 * @var string $contacto Email de contacto del grupo ('' si no hay)
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
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
					<?php echo esc_html( $grupo ); ?> · <?php asde_e( 'Área privada' ); ?>
				</td></tr>
				<tr><td style="padding:28px;font-size:15px;line-height:1.6;">
					<p style="margin:0 0 16px;"><?php echo '' !== $nombre ? sprintf( asde_h( 'Hola, %s:' ), esc_html( $nombre ) ) : asde_h( 'Hola:' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></p>
					<p style="margin:0 0 16px;"><?php echo sprintf( asde_h( 'Hemos revisado tu solicitud de acceso al área privada del <strong>%s</strong> y, con los datos que nos has enviado, <strong>no hemos podido darte acceso</strong>.' ), esc_html( $grupo ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></p>
					<p style="margin:0 0 16px;"><?php asde_e( 'Suele ser porque algún dato no coincide con la ficha del socio/a o porque la ficha no está al día. Si crees que es un error, o quieres volver a intentarlo, ponte en contacto con nosotros:' ); ?></p>
					<?php if ( '' !== $contacto ) : ?>
						<p style="margin:0 0 16px;"><?php echo sprintf( asde_h( 'Escribe a %s, con el nombre del socio/a y tu relación con él o ella (madre, padre, tutor/a o el propio socio/a).' ), '<a href="mailto:' . esc_attr( $contacto ) . '" style="color:' . esc_attr( $col['primary'] ) . ';font-weight:600;">' . esc_html( $contacto ) . '</a>' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></p>
					<?php else : ?>
						<p style="margin:0 0 16px;"><?php asde_e( 'Habla con la Secretaría de tu grupo, diciéndole el nombre del socio/a y tu relación con él o ella (madre, padre, tutor/a o el propio socio/a).' ); ?></p>
					<?php endif; ?>
					<p style="margin:0;font-size:13px;color:<?php echo esc_attr( $col['atenuado'] ); ?>;"><?php asde_e( 'Si no has pedido tú este acceso, puedes ignorar este correo.' ); ?></p>
				</td></tr>
			</table>
		</td></tr>
	</table>
</body>
</html>
