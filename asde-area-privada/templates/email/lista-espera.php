<?php
/**
 * Emails de la lista de espera (v0.32, doc 39): confirmar, confirmada, enlaces, caducidad y
 * mensajes del grupo (HTML con estilos en línea). Los textos los pone ListaEspera\Avisos.
 *
 * @var string   $grupo       Nombre del grupo
 * @var string   $saludo      Nombre de quien recibe ('' si no se sabe)
 * @var string[] $parrafos    Párrafos en HTML (ya escapado)
 * @var string   $boton_url   '' si no hay botón
 * @var string   $boton_texto
 * @var string   $nota        Nota final en HTML (ya escapado)
 * @var string   $contacto    Email de contacto del grupo ('' si no hay)
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
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
					<?php echo esc_html( $grupo ); ?> · <?php asde_e( 'Lista de espera' ); ?>
				</td></tr>
				<tr><td style="padding:28px;font-size:15px;line-height:1.6;">
					<p style="margin:0 0 16px;"><?php echo '' !== $saludo ? sprintf( asde_h( 'Hola, %s:' ), esc_html( $saludo ) ) : asde_h( 'Hola:' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></p>
					<?php foreach ( $parrafos as $parrafo ) : ?>
						<div style="margin:0 0 16px;"><?php echo wp_kses_post( $parrafo ); ?></div>
					<?php endforeach; ?>
					<?php if ( '' !== $boton_url ) : ?>
						<p style="margin:24px 0;text-align:center;">
							<a href="<?php echo esc_url( $boton_url ); ?>" style="display:inline-block;background:<?php echo esc_attr( $col['primary'] ); ?>;color:<?php echo esc_attr( $col['on_primary'] ); ?>;text-decoration:none;font-weight:600;padding:12px 24px;border-radius:4px;"><?php echo esc_html( $boton_texto ); ?></a>
						</p>
						<p style="margin:0 0 16px;font-size:13px;color:<?php echo esc_attr( $col['atenuado'] ); ?>;word-break:break-all;"><?php asde_e( 'Si el botón no funciona, copia esta dirección en el navegador:' ); ?> <?php echo esc_html( $boton_url ); ?></p>
					<?php endif; ?>
					<?php if ( '' !== $nota ) : ?>
						<p style="margin:0 0 16px;font-size:13px;color:<?php echo esc_attr( $col['atenuado'] ); ?>;"><?php echo wp_kses_post( $nota ); ?></p>
					<?php endif; ?>
					<?php if ( '' !== $contacto ) : ?>
						<p style="margin:0;font-size:13px;color:<?php echo esc_attr( $col['atenuado'] ); ?>;"><?php echo sprintf( asde_h( '¿Dudas? Escríbenos a %s.' ), '<a href="mailto:' . esc_attr( $contacto ) . '" style="color:' . esc_attr( $col['primary'] ) . ';">' . esc_html( $contacto ) . '</a>' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></p>
					<?php endif; ?>
				</td></tr>
			</table>
		</td></tr>
	</table>
</body>
</html>
