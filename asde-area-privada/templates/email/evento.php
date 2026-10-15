<?php
/**
 * Emails de los eventos (v0.19.1): publicación, inscripción confirmada, plaza libre,
 * recordatorio y cancelación.
 *
 * @var string   $tipo       publicado | confirmada | plaza | recordatorio | cancelado
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
					<?php echo esc_html( $grupo ); ?> · <?php asde_e( 'Eventos' ); ?>
				</td></tr>
				<tr><td style="padding:28px;font-size:15px;line-height:1.6;">
					<p style="<?php echo esc_attr( $p ); ?>"><?php echo $saludo ? sprintf( asde_h( 'Hola, %s:' ), esc_html( $saludo ) ) : asde_h( 'Hola:' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></p>

					<?php if ( 'publicado' === $tipo ) : ?>
						<p style="<?php echo esc_attr( $p ); ?>"><?php echo sprintf( asde_h( 'El grupo ha publicado un evento nuevo: %s.' ), $fuerte( $evento ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></p>
					<?php elseif ( 'confirmada' === $tipo ) : ?>
						<p style="<?php echo esc_attr( $p ); ?>"><?php echo sprintf( asde_h( 'La inscripción de %1$s en %2$s está confirmada.' ), $fuerte( $quien ), $fuerte( $evento ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></p>
					<?php elseif ( 'plaza' === $tipo ) : ?>
						<p style="<?php echo esc_attr( $p ); ?>"><?php echo sprintf( asde_h( 'Se ha liberado una plaza en %1$s para %2$s, que estaba en la lista de espera.' ), $fuerte( $evento ), $fuerte( $quien ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></p>
						<p style="<?php echo esc_attr( $p ); ?>"><?php echo sprintf( asde_h( 'Confírmala en «Mis eventos» antes del %s. Si no, pasará a la siguiente persona de la lista.' ), $fuerte( $hasta ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></p>
					<?php elseif ( 'recordatorio' === $tipo ) : ?>
						<p style="<?php echo esc_attr( $p ); ?>"><?php echo sprintf( asde_h( 'Te recordamos que %1$s va a %2$s.' ), $fuerte( $quien ), $fuerte( $evento ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></p>
					<?php else : ?>
						<p style="<?php echo esc_attr( $p ); ?>"><?php echo sprintf( asde_h( 'El evento %s se ha cancelado.' ), $fuerte( $evento ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></p>
						<?php if ( '' !== $motivo ) : ?>
							<p style="<?php echo esc_attr( $p ); ?>"><?php echo sprintf( asde_h( 'Motivo: %s' ), esc_html( $motivo ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></p>
						<?php endif; ?>
						<p style="<?php echo esc_attr( $atenuar ); ?>"><?php asde_e( 'Si ya habías pagado, Tesorería se pondrá en contacto contigo para la devolución.' ); ?></p>
					<?php endif; ?>

					<?php if ( 'cancelado' !== $tipo ) : ?>
						<p style="<?php echo esc_attr( $p ); ?>">
							<?php echo esc_html( $numero . ' · ' . $cuando ); ?>
							<?php if ( '' !== $lugar ) : ?>
								<br><?php echo sprintf( asde_h( 'Lugar: %s' ), esc_html( $lugar ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
							<?php endif; ?>
							<?php if ( in_array( $tipo, array( 'confirmada', 'recordatorio' ), true ) && '' !== $salida ) : ?>
								<br><?php echo sprintf( asde_h( 'Salida: %s' ), esc_html( $salida ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
							<?php endif; ?>
							<?php if ( in_array( $tipo, array( 'confirmada', 'recordatorio' ), true ) && '' !== $llegada ) : ?>
								<br><?php echo sprintf( asde_h( 'Llegada: %s' ), esc_html( $llegada ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
							<?php endif; ?>
						</p>
					<?php endif; ?>

					<?php if ( 'publicado' === $tipo && '' !== $cierre ) : ?>
						<p style="<?php echo esc_attr( $p ); ?>"><?php echo sprintf( asde_h( 'La inscripción se hace en «Mis eventos», hasta el %s.' ), $fuerte( $cierre ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></p>
					<?php endif; ?>

					<?php if ( 'recordatorio' === $tipo && $falta ) : ?>
						<p style="<?php echo esc_attr( $p ); ?>"><?php asde_e( 'Todavía falta:' ); ?></p>
						<ul style="margin:0 0 16px;padding-left:20px;">
							<?php if ( in_array( 'autorizacion', $falta, true ) ) : ?>
								<li><?php asde_e( 'firmar la autorización de participación (en «Mis eventos») o entregarla en papel;' ); ?></li>
							<?php endif; ?>
							<?php if ( in_array( 'pago', $falta, true ) && '' !== $importe ) : ?>
								<li><?php echo sprintf( asde_h( 'pagar %1$s (referencia %2$s).' ), $fuerte( $importe ), esc_html( $referencia ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></li>
							<?php endif; ?>
						</ul>
					<?php endif; ?>

					<?php if ( $url && 'cancelado' !== $tipo ) : ?>
						<p style="margin:0 0 24px;text-align:center;">
							<a href="<?php echo esc_url( $url ); ?>" style="display:inline-block;background:<?php echo esc_attr( $col['primary'] ); ?>;color:<?php echo esc_attr( $col['on_primary'] ); ?>;text-decoration:none;padding:12px 28px;border-radius:4px;font-weight:600;"><?php asde_e( 'Ir a Mis eventos' ); ?></a>
						</p>
					<?php endif; ?>
					<p style="margin:0;font-size:13px;color:<?php echo esc_attr( $col['atenuado'] ); ?>;">
						<?php if ( '' !== $contacto ) : ?>
							<?php echo sprintf( asde_h( '¿Dudas? Escribe a %s.' ), '<a href="mailto:' . esc_attr( $contacto ) . '" style="color:' . esc_attr( $col['primary'] ) . ';">' . esc_html( $contacto ) . '</a>' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
						<?php else : ?>
							<?php asde_e( '¿Dudas? Habla con el equipo de la sección.' ); ?>
						<?php endif; ?>
					</p>
				</td></tr>
			</table>
		</td></tr>
	</table>
</body>
</html>
