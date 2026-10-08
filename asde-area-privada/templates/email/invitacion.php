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
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Frontend\Colores;
use AsdeBalears\AreaPrivada\Idiomas\Idioma;

defined( 'ABSPATH' ) || exit;

$col = Colores::para_email(); // Los colores elegidos en wp-admin → Colores (los emails no usan el CSS del plugin).

$reset      = 'reset' === $tipo;
$bienvenida = ! empty( $bienvenida );
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
					<p style="margin:0 0 16px;"><?php echo $nombre ? sprintf( asde_h( 'Hola, %s:' ), esc_html( $nombre ) ) : asde_h( 'Hola:' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></p>
					<?php if ( $bienvenida ) : ?>
						<p style="margin:0 0 16px;font-size:17px;font-weight:600;color:<?php echo esc_attr( $col['primary'] ); ?>;"><?php echo sprintf( asde_h( '¡Te damos la bienvenida al área privada del %s!' ), esc_html( $grupo ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></p>
						<p style="margin:0 0 16px;"><?php echo in_array( asde_t( 'Tutor / Padre / Madre' ), (array) $roles, true ) ? asde_h( 'Hemos comprobado tu solicitud de acceso y ya está aprobada. Desde el área privada podrás consultar y mantener al día tus datos y los de tu familia.' ) : asde_h( 'Hemos comprobado tu solicitud de acceso y ya está aprobada. Desde el área privada podrás consultar y mantener al día tus datos.' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></p>
					<?php endif; ?>
					<?php if ( $reset && $bienvenida ) : ?>
						<p style="margin:0 0 16px;"><?php echo sprintf( asde_h( 'Desde ahora la cuenta <strong>%s</strong> entra con esta dirección de email (o con ese usuario).' ), esc_html( $login ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></p>
						<p style="margin:0 0 24px;"><?php asde_e( 'Pulsa el botón para elegir tu contraseña:' ); ?></p>
					<?php elseif ( $reset ) : ?>
						<p style="margin:0 0 16px;"><?php echo sprintf( asde_h( 'Hemos recibido una petición para elegir una contraseña nueva para el usuario <strong>%s</strong> (con el que también puedes entrar usando esta dirección de email).' ), esc_html( $login ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></p>
						<p style="margin:0 0 24px;"><?php asde_e( 'Pulsa el botón para elegirla:' ); ?></p>
					<?php else : ?>
						<p style="margin:0 0 16px;">
							<?php
							$g = '<strong>' . esc_html( $grupo ) . '</strong>';
							if ( $autor && $roles ) {
								echo sprintf( asde_h( '%1$s te ha dado acceso al área privada del %2$s como <strong>%3$s</strong>.' ), esc_html( $autor ), $g, esc_html( implode( ', ', $roles ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput
							} elseif ( $autor ) {
								echo sprintf( asde_h( '%1$s te ha dado acceso al área privada del %2$s.' ), esc_html( $autor ), $g ); // phpcs:ignore WordPress.Security.EscapeOutput
							} elseif ( $roles ) {
								echo sprintf( asde_h( 'Se te ha dado acceso al área privada del %1$s como <strong>%2$s</strong>.' ), $g, esc_html( implode( ', ', $roles ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput
							} else {
								echo sprintf( asde_h( 'Se te ha dado acceso al área privada del %s.' ), $g ); // phpcs:ignore WordPress.Security.EscapeOutput
							}
							?>
						</p>
						<p style="margin:0 0 8px;"><?php asde_e( 'Tu usuario para entrar es:' ); ?></p>
						<p style="margin:0 0 8px;font-size:22px;font-weight:700;letter-spacing:1px;color:<?php echo esc_attr( $col['primary'] ); ?>;"><?php echo esc_html( $login ); ?></p>
						<p style="margin:0 0 20px;font-size:13px;color:<?php echo esc_attr( $col['atenuado'] ); ?>;"><?php asde_e( 'También puedes entrar escribiendo esta dirección de email en lugar del usuario.' ); ?></p>
						<p style="margin:0 0 24px;"><?php asde_e( 'Para activar tu cuenta y elegir tu contraseña, pulsa el botón:' ); ?></p>
					<?php endif; ?>
					<p style="margin:0 0 24px;text-align:center;">
						<a href="<?php echo esc_url( $url ); ?>" style="display:inline-block;background:<?php echo esc_attr( $col['primary'] ); ?>;color:<?php echo esc_attr( $col['on_primary'] ); ?>;text-decoration:none;padding:12px 28px;border-radius:4px;font-weight:600;"><?php echo $reset ? asde_h( 'Elegir contraseña nueva' ) : asde_h( 'Activar mi cuenta' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></a>
					</p>
					<p style="margin:0 0 16px;font-size:13px;color:<?php echo esc_attr( $col['atenuado'] ); ?>;">
						<?php echo 1 === (int) $dias ? asde_h( 'El enlace es personal, de un solo uso y caduca en 24 horas.' ) : sprintf( asde_h( 'El enlace es personal, de un solo uso y caduca en %d días.' ), (int) $dias ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
						<?php asde_e( 'Si el botón no funciona, copia esta dirección en tu navegador:' ); ?><br>
						<span style="word-break:break-all;color:<?php echo esc_attr( $col['primary'] ); ?>;"><?php echo esc_html( $url ); ?></span>
					</p>
					<p style="margin:0;font-size:13px;color:<?php echo esc_attr( $col['atenuado'] ); ?>;"><?php asde_e( 'Si no esperabas este correo, puedes ignorarlo.' ); ?></p>
				</td></tr>
			</table>
		</td></tr>
	</table>
</body>
</html>
