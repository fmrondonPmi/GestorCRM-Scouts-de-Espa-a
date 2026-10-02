<?php
/**
 * Email de invitación o de recuperación (HTML con estilos en línea).
 *
 * @var string   $tipo    alta | reset
 * @var string   $nombre  Saludo (leído del CRM)
 * @var string   $login   Usuario (nº de socio/a o nº + T)
 * @var string[] $roles
 * @var string   $grupo
 * @var string   $autor
 * @var string   $url
 * @var int      $dias
 * @var bool     $bienvenida Viene de una solicitud de acceso aprobada
 */
defined( 'ABSPATH' ) || exit;

$reset      = 'reset' === $tipo;
$bienvenida = ! empty( $bienvenida );
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
					<p style="margin:0 0 16px;">Hola<?php echo $nombre ? ', ' . esc_html( $nombre ) : ''; ?>:</p>
					<?php if ( $bienvenida ) : ?>
						<p style="margin:0 0 16px;font-size:17px;font-weight:600;color:#632599;">¡Te damos la bienvenida al área privada del <?php echo esc_html( $grupo ); ?>!</p>
						<p style="margin:0 0 16px;">Hemos comprobado tu solicitud de acceso y ya está aprobada. Desde el área privada podrás consultar y mantener al día tus datos<?php echo in_array( 'Tutor / Padre / Madre', (array) $roles, true ) ? ' y los de tu familia' : ''; ?>.</p>
					<?php endif; ?>
					<?php if ( $reset && $bienvenida ) : ?>
						<p style="margin:0 0 16px;">Desde ahora la cuenta <strong><?php echo esc_html( $login ); ?></strong> entra con esta dirección de email (o con ese usuario).</p>
						<p style="margin:0 0 24px;">Pulsa el botón para elegir tu contraseña:</p>
					<?php elseif ( $reset ) : ?>
						<p style="margin:0 0 16px;">Hemos recibido una petición para elegir una contraseña nueva para el usuario <strong><?php echo esc_html( $login ); ?></strong> (con el que también puedes entrar usando esta dirección de email).</p>
						<p style="margin:0 0 24px;">Pulsa el botón para elegirla:</p>
					<?php else : ?>
						<p style="margin:0 0 16px;">
							<?php echo $autor ? esc_html( $autor ) . ' te ha dado' : 'Se te ha dado'; ?>
							acceso al área privada del <strong><?php echo esc_html( $grupo ); ?></strong><?php echo $roles ? ' como <strong>' . esc_html( implode( ', ', $roles ) ) . '</strong>' : ''; ?>.
						</p>
						<p style="margin:0 0 8px;">Tu usuario para entrar es:</p>
						<p style="margin:0 0 8px;font-size:22px;font-weight:700;letter-spacing:1px;color:#632599;"><?php echo esc_html( $login ); ?></p>
						<p style="margin:0 0 20px;font-size:13px;color:#555555;">También puedes entrar escribiendo esta dirección de email en lugar del usuario.</p>
						<p style="margin:0 0 24px;">Para activar tu cuenta y elegir tu contraseña, pulsa el botón:</p>
					<?php endif; ?>
					<p style="margin:0 0 24px;text-align:center;">
						<a href="<?php echo esc_url( $url ); ?>" style="display:inline-block;background:#632599;color:#ffffff;text-decoration:none;padding:12px 28px;border-radius:4px;font-weight:600;"><?php echo $reset ? 'Elegir contraseña nueva' : 'Activar mi cuenta'; ?></a>
					</p>
					<p style="margin:0 0 16px;font-size:13px;color:#555555;">
						El enlace es personal, de un solo uso y caduca en <?php echo 1 === (int) $dias ? '24 horas' : (int) $dias . ' días'; ?>.
						Si el botón no funciona, copia esta dirección en tu navegador:<br>
						<span style="word-break:break-all;color:#632599;"><?php echo esc_html( $url ); ?></span>
					</p>
					<p style="margin:0;font-size:13px;color:#555555;">Si no esperabas este correo, puedes ignorarlo.</p>
				</td></tr>
			</table>
		</td></tr>
	</table>
</body>
</html>
