<?php
/**
 * Email a Tesorería: hay una lista de compra de material esperando su visto bueno
 * (HTML con estilos en línea; doc 32).
 *
 * @var string $nombre Cómo se llama quien lo recibe ('' si no se sabe)
 * @var string $grupo  Nombre del grupo
 * @var array  $lista  La lista (ComprasTable::obtener)
 * @var string $autor  Quién la ha pedido
 * @var string $url    Página de la lista en el área privada
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Frontend\Colores;
use AsdeBalears\AreaPrivada\Frontend\TemplateLoader;
use AsdeBalears\AreaPrivada\Idiomas\Idioma;
use AsdeBalears\AreaPrivada\Inventario\ComprasTable;

defined( 'ABSPATH' ) || exit;

$col = Colores::para_email(); // Los colores elegidos en wp-admin → Colores (los emails no usan el CSS del plugin).
?>
<!DOCTYPE html>
<html lang="<?php echo esc_attr( Idioma::actual() ); ?>">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width"></head>
<body style="margin:0;padding:0;background:<?php echo esc_attr( $col['fondo'] ); ?>;font-family:'Open Sans',Helvetica,Arial,sans-serif;color:<?php echo esc_attr( $col['texto'] ); ?>;">
	<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:<?php echo esc_attr( $col['fondo'] ); ?>;padding:24px 12px;">
		<tr><td align="center">
			<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:600px;background:<?php echo esc_attr( $col['tarjeta'] ); ?>;border-radius:6px;overflow:hidden;">
				<tr><td style="background:<?php echo esc_attr( $col['primary'] ); ?>;color:<?php echo esc_attr( $col['on_primary'] ); ?>;padding:20px 28px;font-size:18px;font-weight:600;">
					<?php echo esc_html( $grupo ); ?> · <?php asde_e( 'Área privada' ); ?>
				</td></tr>
				<tr><td style="padding:28px;font-size:15px;line-height:1.6;">
					<p style="margin:0 0 16px;"><?php echo '' !== $nombre ? sprintf( asde_h( 'Hola, %s:' ), esc_html( $nombre ) ) : asde_h( 'Hola:' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></p>
					<p style="margin:0 0 16px;"><?php echo sprintf( asde_h( '%1$s ha preparado la lista de compra de material <strong>%2$s</strong> y espera el <strong>visto bueno de Tesorería</strong>. Hasta que se lo des, no se puede imprimir.' ), esc_html( '' !== $autor ? $autor : asde_t( 'Alguien del grupo' ) ), esc_html( ComprasTable::numero( $lista ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></p>
					<?php
					$tabla = TemplateLoader::render(
						'email/compra-tabla',
						array(
							'lista' => $lista,
							'col'   => $col,
						)
					);
					echo $tabla; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML de la plantilla de la tabla, ya escapado en ella
					?>
					<?php if ( '' !== trim( $lista['nota'] ) ) : ?>
						<p style="margin:0 0 16px;"><strong><?php asde_e( 'Nota:' ); ?></strong> <?php echo nl2br( esc_html( $lista['nota'] ) ); ?></p>
					<?php endif; ?>
					<p style="margin:0 0 24px;text-align:center;">
						<a href="<?php echo esc_url( $url ); ?>" style="display:inline-block;background:<?php echo esc_attr( $col['primary'] ); ?>;color:<?php echo esc_attr( $col['on_primary'] ); ?>;padding:12px 24px;border-radius:4px;text-decoration:none;font-weight:600;"><?php asde_e( 'Revisar la lista' ); ?></a>
					</p>
					<p style="margin:0;font-size:13px;color:<?php echo esc_attr( $col['atenuado'] ); ?>;"><?php asde_e( 'Te llega porque tienes la responsabilidad de Tesorería en el área privada del grupo. Para revisarla tendrás que iniciar sesión.' ); ?></p>
				</td></tr>
			</table>
		</td></tr>
	</table>
</body>
</html>
