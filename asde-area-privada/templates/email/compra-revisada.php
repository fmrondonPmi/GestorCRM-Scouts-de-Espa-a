<?php
/**
 * Email a quien pidió una lista de compra de material: Tesorería le ha dado el visto bueno
 * o la ha rechazado, con el motivo (v0.30, P-65; HTML con estilos en línea; ).
 *
 * @var string $nombre  Cómo se llama quien lo recibe ('' si no se sabe)
 * @var string $grupo   Nombre del grupo
 * @var array  $lista   La lista (ComprasTable::obtener), con el estado de la revisión
 * @var string $revisor Quién la ha revisado ('' si no se sabe)
 * @var string $url     Página de la lista en el área privada
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Frontend\Colores;
use AsdeBalears\AreaPrivada\Frontend\TemplateLoader;
use AsdeBalears\AreaPrivada\Idiomas\Idioma;
use AsdeBalears\AreaPrivada\Inventario\ComprasTable;

defined( 'ABSPATH' ) || exit;

$col      = Colores::para_email(); // Los colores elegidos en wp-admin → Colores (los emails no usan el CSS del plugin).
$aprobada = ComprasTable::RECHAZADA !== $lista['estado'];
$num      = ComprasTable::numero( $lista );
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
					<?php if ( $aprobada ) : ?>
						<p style="margin:0 0 16px;"><?php echo sprintf( asde_h( 'Tesorería ha dado el <strong>visto bueno</strong> a tu lista de compra de material <strong>%s</strong>. Ya la puedes descargar en PDF para imprimirla.' ), esc_html( $num ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></p>
						<p style="margin:0 0 16px;"><?php asde_e( 'Cuando hayas comprado el material, márcala como comprada en el área privada: lo comprado se sumará al inventario.' ); ?></p>
					<?php else : ?>
						<p style="margin:0 0 16px;"><?php echo sprintf( asde_h( 'Tesorería <strong>no ha dado el visto bueno</strong> a tu lista de compra de material <strong>%s</strong>: la ha rechazado.' ), esc_html( $num ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></p>
						<?php if ( '' !== trim( (string) $lista['motivo'] ) ) : ?>
							<p style="margin:0 0 16px;padding:10px 14px;border-left:4px solid <?php echo esc_attr( $col['primary'] ); ?>;background:<?php echo esc_attr( $col['fondo'] ); ?>;"><strong><?php asde_e( 'Motivo:' ); ?></strong> <?php echo esc_html( $lista['motivo'] ); ?></p>
						<?php endif; ?>
						<p style="margin:0 0 16px;"><?php asde_e( 'Si hace falta, prepara otra lista con los cambios.' ); ?></p>
					<?php endif; ?>
					<?php if ( '' !== $revisor ) : ?>
						<p style="margin:0 0 16px;font-size:14px;color:<?php echo esc_attr( $col['atenuado'] ); ?>;"><?php echo esc_html( sprintf( asde_t( 'La ha revisado %s.' ), $revisor ) ); ?></p>
					<?php endif; ?>
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
					<p style="margin:0 0 24px;text-align:center;">
						<a href="<?php echo esc_url( $url ); ?>" style="display:inline-block;background:<?php echo esc_attr( $col['primary'] ); ?>;color:<?php echo esc_attr( $col['on_primary'] ); ?>;padding:12px 24px;border-radius:4px;text-decoration:none;font-weight:600;"><?php asde_e( 'Ver la lista' ); ?></a>
					</p>
					<p style="margin:0;font-size:13px;color:<?php echo esc_attr( $col['atenuado'] ); ?>;"><?php asde_e( 'Te llega porque pediste esta lista de compra en el área privada del grupo. Para verla tendrás que iniciar sesión.' ); ?></p>
				</td></tr>
			</table>
		</td></tr>
	</table>
</body>
</html>
