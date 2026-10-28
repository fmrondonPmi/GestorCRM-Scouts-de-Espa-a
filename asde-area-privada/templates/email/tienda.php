<?php
/**
 * Emails de la tienda (v0.39, doc 48): pedido recibido, pago validado y pedido entregado.
 *
 * @var string   $tipo        pedido | pagado | entregado | recordatorio | cancelado
 * @var string   $saludo      Nombre para saludar (leído del CRM; puede ir vacío)
 * @var string   $grupo
 * @var string   $numero      U6840007
 * @var string   $descripcion Lo que se ha pedido
 * @var string   $importe     «25,00 €»
 * @var string[] $pagar       Cómo pagar (solo en «pedido» y «recordatorio», si aún no está pagado)
 * @var string   $limite      Fecha en que se cancelará si sigue sin pagar ('' si no se cancela solo)
 * @var string   $texto_pago  Texto propio del grupo sobre cómo pagar ('' si no hay)
 * @var string   $url         «Mis pedidos» (o, en una compra sin sesión, el enlace personal al pedido)
 * @var string   $boton       Texto del botón
 * @var string   $contacto    Email de contacto del grupo ('' si no hay)
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
					<?php echo esc_html( $grupo ); ?> · <?php esc_html_e( 'Shop', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?>
				</td></tr>
				<tr><td style="padding:28px;font-size:15px;line-height:1.6;">
					<p style="<?php echo esc_attr( $p ); ?>"><?php echo wp_kses( $saludo ? sprintf( /* translators: %s: text */ __( 'Hello, %s:', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), esc_html( $saludo ) ) : __( 'Hello:', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), asde_html_permitido() ); ?></p>

					<?php if ( 'pagado' === $tipo ) : ?>
						<p style="<?php echo esc_attr( $p ); ?>"><?php echo wp_kses( sprintf( /* translators: %s: text */ __( 'Treasury has validated the payment of order %s. Thank you!', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $fuerte( $numero ) ), asde_html_permitido() ); ?></p>
					<?php elseif ( 'recordatorio' === $tipo ) : ?>
						<p style="<?php echo esc_attr( $p ); ?>"><?php echo wp_kses( sprintf( /* translators: %s: text */ __( 'We remind you that order %s is still unpaid.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $fuerte( $numero ) ), asde_html_permitido() ); ?><?php echo wp_kses( '' !== $limite ? ' ' . sprintf( /* translators: %s: text */ __( 'If it is not paid before %s, it will be cancelled and what you ordered will go back to the shop.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $fuerte( $limite ) ) : '', asde_html_permitido() ); ?></p>
					<?php elseif ( 'cancelado' === $tipo ) : ?>
						<p style="<?php echo esc_attr( $p ); ?>"><?php echo wp_kses( sprintf( /* translators: %s: text */ __( 'Order %s has been cancelled because it was not paid in time, and what you ordered has gone back to the shop. If you still want it, you can place a new order.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $fuerte( $numero ) ), asde_html_permitido() ); ?></p>
					<?php elseif ( 'entregado' === $tipo ) : ?>
						<p style="<?php echo esc_attr( $p ); ?>"><?php echo wp_kses( sprintf( /* translators: %s: text */ __( 'Order %s has been delivered. When you have it, confirm it in "My orders".', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $fuerte( $numero ) ), asde_html_permitido() ); ?></p>
					<?php else : ?>
						<p style="<?php echo esc_attr( $p ); ?>"><?php echo wp_kses( sprintf( /* translators: %s: text */ __( 'We have received your order %s.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $fuerte( $numero ) ), asde_html_permitido() ); ?></p>
					<?php endif; ?>

					<p style="<?php echo esc_attr( $p ); ?>"><?php echo esc_html( $descripcion ); ?> — <?php echo wp_kses( $fuerte( $importe ), asde_html_permitido() ); ?></p>

					<?php if ( $pagar ) : ?>
						<p style="<?php echo esc_attr( $p ); ?>"><strong><?php esc_html_e( 'How to pay it', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></strong></p>
						<?php foreach ( $pagar as $linea ) : ?>
							<p style="<?php echo esc_attr( $p ); ?>"><?php echo esc_html( $linea ); ?></p>
						<?php endforeach; ?>
						<?php if ( '' !== trim( $texto_pago ) ) : ?>
							<p style="<?php echo esc_attr( $p ); ?>"><?php echo wp_kses( nl2br( esc_html( $texto_pago ) ), asde_html_permitido() ); ?></p>
						<?php endif; ?>
						<p style="<?php echo esc_attr( $atenuar ); ?>"><?php esc_html_e( 'When Treasury receives the payment, you will see it as paid in "My orders".', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
					<?php endif; ?>

					<p style="margin:24px 0;text-align:center;">
						<a href="<?php echo esc_url( $url ); ?>" style="display:inline-block;background:<?php echo esc_attr( $col['primary'] ); ?>;color:<?php echo esc_attr( $col['on_primary'] ); ?>;text-decoration:none;font-weight:600;padding:12px 24px;border-radius:4px;"><?php echo esc_html( $boton ); ?></a>
					</p>
					<?php if ( '' !== $contacto ) : ?>
						<p style="margin:0;font-size:13px;color:<?php echo esc_attr( $col['atenuado'] ); ?>;"><?php echo wp_kses( sprintf( /* translators: %s: text */ __( 'Questions? Write to us at %s.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), '<a href="mailto:' . esc_attr( $contacto ) . '" style="color:' . esc_attr( $col['primary'] ) . ';">' . esc_html( $contacto ) . '</a>' ), asde_html_permitido() ); ?></p>
					<?php endif; ?>
				</td></tr>
			</table>
		</td></tr>
	</table>
</body>
</html>
