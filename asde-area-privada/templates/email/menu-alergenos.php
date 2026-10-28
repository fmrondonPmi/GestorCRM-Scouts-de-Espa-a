<?php
/**
 * Email «alérgenos sin resolver» en el menú de un evento (v0.43, doc 49, P-192). Con nombres solo para quien ve la salud de todo el
 * grupo. Sin diagnóstico ni texto de la ficha. El registro de emails no guarda este contenido.
 *
 * @var string $saludo      Nombre para saludar (leído del CRM; puede ir vacío)
 * @var string $grupo
 * @var string $evento
 * @var string $numero
 * @var string $url         Pestaña Menú del evento
 * @var array  $pendientes  AvisoAlergenos::pendientes() (vacío en la vista previa)
 * @var bool   $con_nombres ¿Quien lo recibe ve los nombres?
 * @var string $contacto    Email de contacto del grupo ('' si no hay)
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Frontend\Colores;
use AsdeBalears\AreaPrivada\Idiomas\Idioma;
use AsdeBalears\AreaPrivada\Menus\MenuTable;

defined( 'ABSPATH' ) || exit;

$col    = Colores::para_email();
$p      = 'margin:0 0 16px;';
$fuerte = static fn( string $t ): string => '<strong>' . esc_html( $t ) . '</strong>';
?>
<!DOCTYPE html>
<html lang="<?php echo esc_attr( Idioma::actual() ); ?>">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width"></head>
<body style="margin:0;padding:0;background:<?php echo esc_attr( $col['fondo'] ); ?>;font-family:'Open Sans',Helvetica,Arial,sans-serif;color:<?php echo esc_attr( $col['texto'] ); ?>;">
	<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:<?php echo esc_attr( $col['fondo'] ); ?>;padding:24px 12px;">
		<tr><td align="center">
			<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:600px;background:<?php echo esc_attr( $col['tarjeta'] ); ?>;border-radius:6px;overflow:hidden;">
				<tr><td style="background:<?php echo esc_attr( $col['primary'] ); ?>;color:<?php echo esc_attr( $col['on_primary'] ); ?>;padding:20px 28px;font-size:18px;font-weight:600;">
					<?php echo esc_html( $grupo ); ?> · <?php esc_html_e( 'Event menu', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?>
				</td></tr>
				<tr><td style="padding:28px;font-size:15px;line-height:1.6;">
					<p style="<?php echo esc_attr( $p ); ?>"><?php echo wp_kses( $saludo ? sprintf( /* translators: %s: text */ __( 'Hello, %s:', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), esc_html( $saludo ) ) : __( 'Hello:', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), asde_html_permitido() ); ?></p>
					<p style="<?php echo esc_attr( $p ); ?>"><?php echo wp_kses( sprintf( /* translators: 1: text, 2: text */ __( 'In the menu of %1$s (%2$s) there are dishes that contain an allergen of someone attending the event and nobody has yet confirmed what will be done.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $fuerte( $evento ), esc_html( $numero ) ), asde_html_permitido() ); ?></p>
					<?php if ( $pendientes ) : ?>
						<ul style="margin:0 0 16px;padding-left:20px;">
							<?php foreach ( $pendientes as $x ) : ?>
								<li>
									<?php echo esc_html( wp_date( 'l d/m', strtotime( $x['dia'] . ' 12:00 UTC' ) ) . ' · ' . ( MenuTable::comidas()[ $x['comida'] ] ?? '' ) . ' · ' . $x['plato'] ); ?>:
									<strong><?php echo esc_html( $x['alergeno'] ); ?></strong>
									<?php if ( $con_nombres && $x['nombres'] ) : ?>
										— <?php echo esc_html( implode( ', ', $x['nombres'] ) ); ?>
									<?php else : ?>
										— <?php echo esc_html( sprintf( /* translators: %d: number */ _n( '%d person', '%d people', (int) $x['n'] , 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), (int) $x['n'] ) ); ?>
									<?php endif; ?>
								</li>
							<?php endforeach; ?>
						</ul>
						<?php if ( $con_nombres ) : ?>
							<p style="<?php echo esc_attr( $p ); ?>color:<?php echo esc_attr( $col['atenuado'] ); ?>;font-size:13px;"><?php esc_html_e( 'Contains health data of minors: do not forward it, do not leave it in sight and delete it when the event ends.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
						<?php endif; ?>
					<?php endif; ?>
					<p style="<?php echo esc_attr( $p ); ?>"><?php esc_html_e( 'Go to the Menu tab to confirm the alternative for each dish (for example, a gluten-free portion) or to change the menu.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
					<p style="margin:24px 0;text-align:center;">
						<a href="<?php echo esc_url( $url ); ?>" style="display:inline-block;background:<?php echo esc_attr( $col['primary'] ); ?>;color:<?php echo esc_attr( $col['on_primary'] ); ?>;text-decoration:none;font-weight:600;padding:12px 24px;border-radius:4px;"><?php esc_html_e( 'See the menu', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a>
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
