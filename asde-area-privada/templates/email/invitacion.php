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
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
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
					<?php echo esc_html( $grupo ); ?> · <?php esc_html_e( 'Private area', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?>
				</td></tr>
				<tr><td style="padding:28px;font-size:15px;line-height:1.6;">
					<p style="margin:0 0 16px;"><?php echo wp_kses( $nombre ? sprintf( /* translators: %s: text */ __( 'Hello, %s:', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), esc_html( $nombre ) ) : __( 'Hello:', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), asde_html_permitido() ); ?></p>
					<?php if ( $bienvenida ) : ?>
						<p style="margin:0 0 16px;font-size:17px;font-weight:600;color:<?php echo esc_attr( $col['primary'] ); ?>;"><?php echo wp_kses( sprintf( /* translators: %s: text */ __( 'Welcome to the private area of %s!', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), esc_html( $grupo ) ), asde_html_permitido() ); ?></p>
						<p style="margin:0 0 16px;"><?php echo wp_kses( in_array( __( 'Guardian / Father / Mother', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), (array) $roles, true ) ? __( 'We have checked your access request and it has been approved. From the private area you can view and keep up to date your details and your family\'s.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) : __( 'We have checked your access request and it has been approved. From the private area you can view and keep your details up to date.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), asde_html_permitido() ); ?></p>
					<?php endif; ?>
					<?php if ( $reset && $bienvenida ) : ?>
						<p style="margin:0 0 16px;"><?php echo wp_kses( sprintf( /* translators: %s: text */ __( 'From now on the account <strong>%s</strong> signs in with this email address (or with that username).', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), esc_html( $login ) ), asde_html_permitido() ); ?></p>
						<p style="margin:0 0 24px;"><?php esc_html_e( 'Press the button to choose your password:', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
					<?php elseif ( $reset ) : ?>
						<p style="margin:0 0 16px;"><?php echo wp_kses( sprintf( /* translators: %s: text */ __( 'We have received a request to choose a new password for the user <strong>%s</strong> (with which you can also sign in using this email address).', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), esc_html( $login ) ), asde_html_permitido() ); ?></p>
						<p style="margin:0 0 24px;"><?php esc_html_e( 'Press the button to choose it:', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
					<?php else : ?>
						<p style="margin:0 0 16px;">
							<?php
							$g = '<strong>' . esc_html( $grupo ) . '</strong>';
							if ( $autor && $roles ) {
								echo wp_kses( sprintf( /* translators: 1: text, 2: text, 3: text */ __( '%1$s has given you access to the private area of %2$s as <strong>%3$s</strong>.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), esc_html( $autor ), $g, esc_html( implode( ', ', $roles ) ) ), asde_html_permitido() );
							} elseif ( $autor ) {
								echo wp_kses( sprintf( /* translators: 1: text, 2: text */ __( '%1$s has given you access to the private area of %2$s.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), esc_html( $autor ), $g ), asde_html_permitido() );
							} elseif ( $roles ) {
								echo wp_kses( sprintf( /* translators: 1: text, 2: text */ __( 'You have been given access to the private area of %1$s as <strong>%2$s</strong>.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $g, esc_html( implode( ', ', $roles ) ) ), asde_html_permitido() );
							} else {
								echo wp_kses( sprintf( /* translators: %s: text */ __( 'You have been given access to the private area of %s.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $g ), asde_html_permitido() );
							}
							?>
						</p>
						<p style="margin:0 0 8px;"><?php esc_html_e( 'Your username to sign in is:', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
						<p style="margin:0 0 8px;font-size:22px;font-weight:700;letter-spacing:1px;color:<?php echo esc_attr( $col['primary'] ); ?>;"><?php echo esc_html( $login ); ?></p>
						<p style="margin:0 0 20px;font-size:13px;color:<?php echo esc_attr( $col['atenuado'] ); ?>;"><?php esc_html_e( 'You can also sign in by typing this email address instead of the username.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
						<p style="margin:0 0 24px;"><?php esc_html_e( 'To activate your account and choose your password, press the button:', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
					<?php endif; ?>
					<p style="margin:0 0 24px;text-align:center;">
						<a href="<?php echo esc_url( $url ); ?>" style="display:inline-block;background:<?php echo esc_attr( $col['primary'] ); ?>;color:<?php echo esc_attr( $col['on_primary'] ); ?>;text-decoration:none;padding:12px 28px;border-radius:4px;font-weight:600;"><?php echo wp_kses( $reset ? __( 'Choose a new password', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) : __( 'Activate my account', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), asde_html_permitido() ); ?></a>
					</p>
					<p style="margin:0 0 16px;font-size:13px;color:<?php echo esc_attr( $col['atenuado'] ); ?>;">
						<?php echo wp_kses( 1 === (int) $dias ? __( 'The link is personal, single-use and expires in 24 hours.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) : sprintf( /* translators: %d: number */ __( 'The link is personal, single-use and expires in %d days.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), (int) $dias ), asde_html_permitido() ); ?>
						<?php esc_html_e( 'If the button does not work, copy this address into your browser:', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?><br>
						<span style="word-break:break-all;color:<?php echo esc_attr( $col['primary'] ); ?>;"><?php echo esc_html( $url ); ?></span>
					</p>
					<p style="margin:0;font-size:13px;color:<?php echo esc_attr( $col['atenuado'] ); ?>;"><?php esc_html_e( 'If you were not expecting this email, you can ignore it.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
				</td></tr>
			</table>
		</td></tr>
	</table>
</body>
</html>
