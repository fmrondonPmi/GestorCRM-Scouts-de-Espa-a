<?php
/**
 * Email a quien pidió el acceso cuando se rechaza su solicitud (HTML con estilos en línea).
 * Es un texto genérico: el motivo que anota quien la revisa es solo para el grupo.
 *
 * @var string $nombre   Cómo se llama quien pidió (lo que escribió en el formulario; puede ir vacío)
 * @var string $grupo    Nombre del grupo
 * @var string $contacto Email de contacto del grupo ('' si no hay)
 */
defined( 'ABSPATH' ) || exit;
?>
<!DOCTYPE html>
<html lang="es">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width"></head>
<body style="margin:0;padding:0;background:#f4f1f7;font-family:'Open Sans',Helvetica,Arial,sans-serif;color:#444444;">
	<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f4f1f7;padding:24px 12px;">
		<tr><td align="center">
			<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:560px;background:#ffffff;border-radius:6px;overflow:hidden;">
				<tr><td style="background:#632599;color:#ffffff;padding:20px 28px;font-size:18px;font-weight:600;">
					<?php echo esc_html( $grupo ); ?> · Área privada
				</td></tr>
				<tr><td style="padding:28px;font-size:15px;line-height:1.6;">
					<p style="margin:0 0 16px;">Hola<?php echo '' !== $nombre ? ', ' . esc_html( $nombre ) : ''; ?>:</p>
					<p style="margin:0 0 16px;">Hemos revisado tu solicitud de acceso al área privada del <strong><?php echo esc_html( $grupo ); ?></strong> y, con los datos que nos has enviado, <strong>no hemos podido darte acceso</strong>.</p>
					<p style="margin:0 0 16px;">Suele ser porque algún dato no coincide con la ficha del socio/a o porque la ficha no está al día. Si crees que es un error, o quieres volver a intentarlo, ponte en contacto con nosotros:</p>
					<?php if ( '' !== $contacto ) : ?>
						<p style="margin:0 0 16px;">Escribe a <a href="mailto:<?php echo esc_attr( $contacto ); ?>" style="color:#632599;font-weight:600;"><?php echo esc_html( $contacto ); ?></a>, con el nombre del socio/a y tu relación con él o ella (madre, padre, tutor/a o el propio socio/a).</p>
					<?php else : ?>
						<p style="margin:0 0 16px;">Habla con la Secretaría de tu grupo, diciéndole el nombre del socio/a y tu relación con él o ella (madre, padre, tutor/a o el propio socio/a).</p>
					<?php endif; ?>
					<p style="margin:0;font-size:13px;color:#555555;">Si no has pedido tú este acceso, puedes ignorar este correo.</p>
				</td></tr>
			</table>
		</td></tr>
	</table>
</body>
</html>
