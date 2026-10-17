<?php
/**
 * Emails de la tienda (v0.39, doc 48): pedido recibido, pago validado y pedido entregado.
 *
 * @var string   $tipo        pedido | pagado | entregado
 * @var string   $saludo      Nombre para saludar (leído del CRM; puede ir vacío)
 * @var string   $grupo
 * @var string   $numero      U6840007
 * @var string   $descripcion Lo que se ha pedido
 * @var string   $importe     «25,00 €»
 * @var string[] $pagar       Cómo pagar (solo en «pedido» y si aún no está pagado)
 * @var string   $texto_pago  Texto propio del grupo sobre cómo pagar ('' si no hay)
 * @var string   $url         «Mis pedidos»
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
					<?php echo esc_html( $grupo ); ?> · <?php asde_e( 'Tienda' ); ?>
				</td></tr>
				<tr><td style="padding:28px;font-size:15px;line-height:1.6;">
					<p style="<?php echo esc_attr( $p ); ?>"><?php echo $saludo ? sprintf( asde_h( 'Hola, %s:' ), esc_html( $saludo ) ) : asde_h( 'Hola:' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></p>

					<?php if ( 'pagado' === $tipo ) : ?>
						<p style="<?php echo esc_attr( $p ); ?>"><?php echo sprintf( asde_h( 'Tesorería ha validado el pago del pedido %s. ¡Gracias!' ), $fuerte( $numero ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></p>
					<?php elseif ( 'entregado' === $tipo ) : ?>
						<p style="<?php echo esc_attr( $p ); ?>"><?php echo sprintf( asde_h( 'El pedido %s ya está entregado. Cuando lo tengas, confírmalo en «Mis pedidos».' ), $fuerte( $numero ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></p>
					<?php else : ?>
						<p style="<?php echo esc_attr( $p ); ?>"><?php echo sprintf( asde_h( 'Hemos recibido tu pedido %s.' ), $fuerte( $numero ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></p>
					<?php endif; ?>

					<p style="<?php echo esc_attr( $p ); ?>"><?php echo esc_html( $descripcion ); ?> — <?php echo $fuerte( $importe ); // phpcs:ignore WordPress.Security.EscapeOutput ?></p>

					<?php if ( $pagar ) : ?>
						<p style="<?php echo esc_attr( $p ); ?>"><strong><?php asde_e( 'Cómo pagarlo' ); ?></strong></p>
						<?php foreach ( $pagar as $linea ) : ?>
							<p style="<?php echo esc_attr( $p ); ?>"><?php echo esc_html( $linea ); ?></p>
						<?php endforeach; ?>
						<?php if ( '' !== trim( $texto_pago ) ) : ?>
							<p style="<?php echo esc_attr( $p ); ?>"><?php echo nl2br( esc_html( $texto_pago ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></p>
						<?php endif; ?>
						<p style="<?php echo esc_attr( $atenuar ); ?>"><?php asde_e( 'Cuando Tesorería reciba el pago, lo verás como pagado en «Mis pedidos».' ); ?></p>
					<?php endif; ?>

					<p style="margin:24px 0;text-align:center;">
						<a href="<?php echo esc_url( $url ); ?>" style="display:inline-block;background:<?php echo esc_attr( $col['primary'] ); ?>;color:<?php echo esc_attr( $col['on_primary'] ); ?>;text-decoration:none;font-weight:600;padding:12px 24px;border-radius:4px;"><?php asde_e( 'Ver mis pedidos' ); ?></a>
					</p>
					<?php if ( '' !== $contacto ) : ?>
						<p style="margin:0;font-size:13px;color:<?php echo esc_attr( $col['atenuado'] ); ?>;"><?php echo sprintf( asde_h( '¿Dudas? Escríbenos a %s.' ), '<a href="mailto:' . esc_attr( $contacto ) . '" style="color:' . esc_attr( $col['primary'] ) . ';">' . esc_html( $contacto ) . '</a>' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></p>
					<?php endif; ?>
				</td></tr>
			</table>
		</td></tr>
	</table>
</body>
</html>
