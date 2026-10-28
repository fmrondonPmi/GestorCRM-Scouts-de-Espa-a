<?php
/**
 * Emails de los eventos (v0.35): publicación, inscripción confirmada, plaza libre,
 * recordatorio y cancelación.
 *
 * @var string   $tipo       publicado | confirmada | plaza | recordatorio | cancelado
 * @var string   $intro      Párrafo propio del grupo al principio ('' si no hay)
 * @var string   $pie        Párrafo propio del grupo al final ('' si no hay)
 * @var string   $saludo     Nombre para saludar (leído del CRM; puede ir vacío)
 * @var string   $quien      Nombre de la persona inscrita ('' en los avisos de publicación)
 * @var string   $grupo
 * @var string   $evento     Nombre del evento
 * @var string   $numero     E6840012
 * @var string   $cuando     Fechas
 * @var string   $lugar
 * @var string   $salida
 * @var string   $llegada
 * @var string   $cierre     Fin del plazo de inscripción (publicación)
 * @var string   $hasta      Hasta cuándo se puede confirmar la plaza (plaza)
 * @var string   $motivo     Motivo de la cancelación
 * @var string[] $falta      autorizacion | pago (recordatorio)
 * @var string   $importe    Lo que queda por pagar, «46,00 €» (recordatorio)
 * @var string   $referencia Referencia para la transferencia o el Bizum (recordatorio)
 * @var string   $url        «Mis eventos»
 * @var string   $contacto   Email de contacto del grupo ('' si no hay)
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Frontend\Colores;
use AsdeBalears\AreaPrivada\Idiomas\Idioma;

defined( 'ABSPATH' ) || exit;

$col     = Colores::para_email();
$p       = 'margin:0 0 16px;';
$atenuar = 'margin:0 0 16px;font-size:13px;color:' . esc_attr( $col['atenuado'] ) . ';';
$fuerte  = static fn( string $t ): string => '<strong>' . esc_html( $t ) . '</strong>';
?>
<!DOCTYPE html>
<html lang="<?php echo esc_attr( Idioma::actual() ); ?>">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width"></head>
<body style="margin:0;padding:0;background:<?php echo esc_attr( $col['fondo'] ); ?>;font-family:'Open Sans',Helvetica,Arial,sans-serif;color:<?php echo esc_attr( $col['texto'] ); ?>;">
	<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:<?php echo esc_attr( $col['fondo'] ); ?>;padding:24px 12px;">
		<tr><td align="center">
			<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:560px;background:<?php echo esc_attr( $col['tarjeta'] ); ?>;border-radius:6px;overflow:hidden;">
				<tr><td style="background:<?php echo esc_attr( $col['primary'] ); ?>;color:<?php echo esc_attr( $col['on_primary'] ); ?>;padding:20px 28px;font-size:18px;font-weight:600;">
					<?php echo esc_html( $grupo ); ?> · <?php esc_html_e( 'Events', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?>
				</td></tr>
				<tr><td style="padding:28px;font-size:15px;line-height:1.6;">
					<p style="<?php echo esc_attr( $p ); ?>"><?php echo wp_kses( $saludo ? sprintf( /* translators: %s: text */ __( 'Hello, %s:', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), esc_html( $saludo ) ) : __( 'Hello:', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), asde_html_permitido() ); ?></p>

					<?php if ( 'publicado' === $tipo ) : ?>
						<p style="<?php echo esc_attr( $p ); ?>"><?php echo wp_kses( sprintf( /* translators: %s: text */ __( 'The group has published a new event: %s.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $fuerte( $evento ) ), asde_html_permitido() ); ?></p>
					<?php elseif ( 'confirmada' === $tipo ) : ?>
						<p style="<?php echo esc_attr( $p ); ?>"><?php echo wp_kses( sprintf( /* translators: 1: text, 2: text */ __( 'The sign-up of %1$s for %2$s is confirmed.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $fuerte( $quien ), $fuerte( $evento ) ), asde_html_permitido() ); ?></p>
					<?php elseif ( 'plaza' === $tipo ) : ?>
						<p style="<?php echo esc_attr( $p ); ?>"><?php echo wp_kses( sprintf( /* translators: 1: text, 2: text */ __( 'A place has been freed up in %1$s for %2$s, who was on the waiting list.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $fuerte( $evento ), $fuerte( $quien ) ), asde_html_permitido() ); ?></p>
						<p style="<?php echo esc_attr( $p ); ?>"><?php echo wp_kses( sprintf( /* translators: %s: text */ __( 'Confirm it in "My events" before %s. Otherwise it will go to the next person on the list.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $fuerte( $hasta ) ), asde_html_permitido() ); ?></p>
					<?php elseif ( 'recordatorio' === $tipo ) : ?>
						<p style="<?php echo esc_attr( $p ); ?>"><?php echo wp_kses( sprintf( /* translators: 1: text, 2: text */ __( 'We remind you that %1$s is going to %2$s.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $fuerte( $quien ), $fuerte( $evento ) ), asde_html_permitido() ); ?></p>
					<?php else : ?>
						<p style="<?php echo esc_attr( $p ); ?>"><?php echo wp_kses( sprintf( /* translators: %s: text */ __( 'The event %s has been cancelled.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $fuerte( $evento ) ), asde_html_permitido() ); ?></p>
						<?php if ( '' !== $motivo ) : ?>
							<p style="<?php echo esc_attr( $p ); ?>"><?php echo wp_kses( sprintf( /* translators: %s: text */ __( 'Reason: %s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), esc_html( $motivo ) ), asde_html_permitido() ); ?></p>
						<?php endif; ?>
						<p style="<?php echo esc_attr( $atenuar ); ?>"><?php esc_html_e( 'If you had already paid, Treasury will contact you about the refund.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
					<?php endif; ?>

					<?php if ( '' !== $intro ) : ?>
						<p style="<?php echo esc_attr( $p ); ?>"><?php echo wp_kses( nl2br( esc_html( $intro ) ), asde_html_permitido() ); ?></p>
					<?php endif; ?>

					<?php if ( 'cancelado' !== $tipo ) : ?>
						<p style="<?php echo esc_attr( $p ); ?>">
							<?php echo esc_html( $numero . ' · ' . $cuando ); ?>
							<?php if ( '' !== $lugar ) : ?>
								<br><?php echo wp_kses( sprintf( /* translators: %s: text */ __( 'Place: %s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), esc_html( $lugar ) ), asde_html_permitido() ); ?>
							<?php endif; ?>
							<?php if ( in_array( $tipo, array( 'confirmada', 'recordatorio' ), true ) && '' !== $salida ) : ?>
								<br><?php echo wp_kses( sprintf( /* translators: %s: text */ __( 'Departure: %s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), esc_html( $salida ) ), asde_html_permitido() ); ?>
							<?php endif; ?>
							<?php if ( in_array( $tipo, array( 'confirmada', 'recordatorio' ), true ) && '' !== $llegada ) : ?>
								<br><?php echo wp_kses( sprintf( /* translators: %s: text */ __( 'Arrival: %s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), esc_html( $llegada ) ), asde_html_permitido() ); ?>
							<?php endif; ?>
						</p>
					<?php endif; ?>

					<?php if ( 'publicado' === $tipo && '' !== $cierre ) : ?>
						<p style="<?php echo esc_attr( $p ); ?>"><?php echo wp_kses( sprintf( /* translators: %s: text */ __( 'Sign-up is done in "My events", until %s.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $fuerte( $cierre ) ), asde_html_permitido() ); ?></p>
					<?php endif; ?>

					<?php if ( 'recordatorio' === $tipo && $falta ) : ?>
						<p style="<?php echo esc_attr( $p ); ?>"><?php esc_html_e( 'Still missing:', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
						<ul style="margin:0 0 16px;padding-left:20px;">
							<?php if ( in_array( 'autorizacion', $falta, true ) ) : ?>
								<li><?php esc_html_e( 'sign the participation authorisation (in "My events") or hand it in on paper;', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></li>
							<?php endif; ?>
							<?php if ( in_array( 'pago', $falta, true ) && '' !== $importe ) : ?>
								<li><?php echo wp_kses( sprintf( /* translators: 1: text, 2: text */ __( 'pay %1$s (reference %2$s).', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $fuerte( $importe ), esc_html( $referencia ) ), asde_html_permitido() ); ?></li>
							<?php endif; ?>
						</ul>
					<?php endif; ?>

					<?php if ( '' !== $pie ) : ?>
						<p style="<?php echo esc_attr( $p ); ?>"><?php echo wp_kses( nl2br( esc_html( $pie ) ), asde_html_permitido() ); ?></p>
					<?php endif; ?>

					<?php if ( $url && 'cancelado' !== $tipo ) : ?>
						<p style="margin:0 0 24px;text-align:center;">
							<a href="<?php echo esc_url( $url ); ?>" style="display:inline-block;background:<?php echo esc_attr( $col['primary'] ); ?>;color:<?php echo esc_attr( $col['on_primary'] ); ?>;text-decoration:none;padding:12px 28px;border-radius:4px;font-weight:600;"><?php esc_html_e( 'Go to My events', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a>
						</p>
					<?php endif; ?>
					<p style="margin:0;font-size:13px;color:<?php echo esc_attr( $col['atenuado'] ); ?>;">
						<?php if ( '' !== $contacto ) : ?>
							<?php echo wp_kses( sprintf( /* translators: %s: text */ __( 'Questions? Write to %s.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), '<a href="mailto:' . esc_attr( $contacto ) . '" style="color:' . esc_attr( $col['primary'] ) . ';">' . esc_html( $contacto ) . '</a>' ), asde_html_permitido() ); ?>
						<?php else : ?>
							<?php esc_html_e( 'Questions? Talk to the section team.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?>
						<?php endif; ?>
					</p>
				</td></tr>
			</table>
		</td></tr>
	</table>
</body>
</html>
