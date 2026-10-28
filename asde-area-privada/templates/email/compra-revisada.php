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
					<?php echo esc_html( $grupo ); ?> · <?php esc_html_e( 'Private area', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?>
				</td></tr>
				<tr><td style="padding:28px;font-size:15px;line-height:1.6;">
					<p style="margin:0 0 16px;"><?php echo wp_kses( '' !== $nombre ? sprintf( /* translators: %s: text */ __( 'Hello, %s:', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), esc_html( $nombre ) ) : __( 'Hello:', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), asde_html_permitido() ); ?></p>
					<?php if ( $aprobada ) : ?>
						<p style="margin:0 0 16px;"><?php echo wp_kses( sprintf( /* translators: %s: text */ __( 'Treasury has <strong>approved</strong> your equipment shopping list <strong>%s</strong>. You can now download it as a PDF to print it.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), esc_html( $num ) ), asde_html_permitido() ); ?></p>
						<p style="margin:0 0 16px;"><?php esc_html_e( 'When you have bought the equipment, mark it as bought in the private area: what has been bought will be added to the inventory.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
					<?php else : ?>
						<p style="margin:0 0 16px;"><?php echo wp_kses( sprintf( /* translators: %s: text */ __( 'Treasury <strong>has not approved</strong> your equipment shopping list <strong>%s</strong>: it has rejected it.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), esc_html( $num ) ), asde_html_permitido() ); ?></p>
						<?php if ( '' !== trim( (string) $lista['motivo'] ) ) : ?>
							<p style="margin:0 0 16px;padding:10px 14px;border-left:4px solid <?php echo esc_attr( $col['primary'] ); ?>;background:<?php echo esc_attr( $col['fondo'] ); ?>;"><strong><?php esc_html_e( 'Reason:', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></strong> <?php echo esc_html( $lista['motivo'] ); ?></p>
						<?php endif; ?>
						<p style="margin:0 0 16px;"><?php esc_html_e( 'If necessary, prepare another list with the changes.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
					<?php endif; ?>
					<?php if ( '' !== $revisor ) : ?>
						<p style="margin:0 0 16px;font-size:14px;color:<?php echo esc_attr( $col['atenuado'] ); ?>;"><?php echo esc_html( sprintf( /* translators: %s: text */ __( 'It has been reviewed by %s.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $revisor ) ); ?></p>
					<?php endif; ?>
					<?php
					$tabla = TemplateLoader::render(
						'email/compra-tabla',
						array(
							'lista' => $lista,
							'col'   => $col,
						)
					);
					echo wp_kses( $tabla, asde_html_permitido() );
					?>
					<p style="margin:0 0 24px;text-align:center;">
						<a href="<?php echo esc_url( $url ); ?>" style="display:inline-block;background:<?php echo esc_attr( $col['primary'] ); ?>;color:<?php echo esc_attr( $col['on_primary'] ); ?>;padding:12px 24px;border-radius:4px;text-decoration:none;font-weight:600;"><?php esc_html_e( 'See the list', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a>
					</p>
					<p style="margin:0;font-size:13px;color:<?php echo esc_attr( $col['atenuado'] ); ?>;"><?php esc_html_e( 'You are receiving this because you requested this shopping list in the group\'s private area. You will need to sign in to see it.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
				</td></tr>
			</table>
		</td></tr>
	</table>
</body>
</html>
