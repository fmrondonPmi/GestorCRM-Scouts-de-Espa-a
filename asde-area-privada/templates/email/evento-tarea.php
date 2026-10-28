<?php
/**
 * Email del calendario de tareas de un evento (v0.40, doc 49): una tarea vence pronto o vence hoy.
 *
 * @var string $tipo     tarea_previa | tarea_vence
 * @var string $saludo   Nombre para saludar (leído del CRM; puede ir vacío)
 * @var string $grupo
 * @var string $evento   Nombre del evento
 * @var string $numero   E6840012
 * @var string $tarea    Título de la tarea
 * @var string $fecha    Fecha límite, «19/03/2027»
 * @var string $url      Calendario del evento
 * @var string $contacto Email de contacto del grupo ('' si no hay)
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Frontend\Colores;
use AsdeBalears\AreaPrivada\Idiomas\Idioma;

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
			<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:560px;background:<?php echo esc_attr( $col['tarjeta'] ); ?>;border-radius:6px;overflow:hidden;">
				<tr><td style="background:<?php echo esc_attr( $col['primary'] ); ?>;color:<?php echo esc_attr( $col['on_primary'] ); ?>;padding:20px 28px;font-size:18px;font-weight:600;">
					<?php echo esc_html( $grupo ); ?> · <?php esc_html_e( 'Events', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?>
				</td></tr>
				<tr><td style="padding:28px;font-size:15px;line-height:1.6;">
					<p style="<?php echo esc_attr( $p ); ?>"><?php echo wp_kses( $saludo ? sprintf( /* translators: %s: text */ __( 'Hello, %s:', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), esc_html( $saludo ) ) : __( 'Hello:', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), asde_html_permitido() ); ?></p>
					<?php if ( 'tarea_vence' === $tipo ) : ?>
						<p style="<?php echo esc_attr( $p ); ?>"><?php echo wp_kses( sprintf( /* translators: %s: text */ __( 'A task on the %s calendar is due today:', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $fuerte( $evento ) ), asde_html_permitido() ); ?></p>
					<?php else : ?>
						<p style="<?php echo esc_attr( $p ); ?>"><?php echo wp_kses( sprintf( /* translators: 1: text, 2: text */ __( 'A task on the %1$s calendar is due on %2$s:', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $fuerte( $evento ), $fuerte( $fecha ) ), asde_html_permitido() ); ?></p>
					<?php endif; ?>
					<p style="<?php echo esc_attr( $p ); ?>font-size:17px;"><?php echo wp_kses( $fuerte( $tarea ), asde_html_permitido() ); ?></p>
					<p style="<?php echo esc_attr( $p ); ?>font-size:13px;color:<?php echo esc_attr( $col['atenuado'] ); ?>;"><?php echo esc_html( $numero ); ?></p>
					<p style="margin:24px 0;text-align:center;">
						<a href="<?php echo esc_url( $url ); ?>" style="display:inline-block;background:<?php echo esc_attr( $col['primary'] ); ?>;color:<?php echo esc_attr( $col['on_primary'] ); ?>;text-decoration:none;font-weight:600;padding:12px 24px;border-radius:4px;"><?php esc_html_e( 'See the calendar', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a>
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
