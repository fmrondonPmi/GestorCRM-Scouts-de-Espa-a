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
					<?php echo esc_html( $grupo ); ?> · <?php asde_e( 'Eventos' ); ?>
				</td></tr>
				<tr><td style="padding:28px;font-size:15px;line-height:1.6;">
					<p style="<?php echo esc_attr( $p ); ?>"><?php echo $saludo ? sprintf( asde_h( 'Hola, %s:' ), esc_html( $saludo ) ) : asde_h( 'Hola:' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></p>
					<?php if ( 'tarea_vence' === $tipo ) : ?>
						<p style="<?php echo esc_attr( $p ); ?>"><?php echo sprintf( asde_h( 'Hoy vence una tarea del calendario de %s:' ), $fuerte( $evento ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></p>
					<?php else : ?>
						<p style="<?php echo esc_attr( $p ); ?>"><?php echo sprintf( asde_h( 'Una tarea del calendario de %1$s vence el %2$s:' ), $fuerte( $evento ), $fuerte( $fecha ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></p>
					<?php endif; ?>
					<p style="<?php echo esc_attr( $p ); ?>font-size:17px;"><?php echo $fuerte( $tarea ); // phpcs:ignore WordPress.Security.EscapeOutput ?></p>
					<p style="<?php echo esc_attr( $p ); ?>font-size:13px;color:<?php echo esc_attr( $col['atenuado'] ); ?>;"><?php echo esc_html( $numero ); ?></p>
					<p style="margin:24px 0;text-align:center;">
						<a href="<?php echo esc_url( $url ); ?>" style="display:inline-block;background:<?php echo esc_attr( $col['primary'] ); ?>;color:<?php echo esc_attr( $col['on_primary'] ); ?>;text-decoration:none;font-weight:600;padding:12px 24px;border-radius:4px;"><?php asde_e( 'Ver el calendario' ); ?></a>
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
