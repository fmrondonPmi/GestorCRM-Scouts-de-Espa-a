<?php
/**
 * Emails de los reintegros (v0.47, doc 53): a Tesorería (por transferencia, con todos los datos) y a quien lo pidió.
 *
 * @var string $tipo     tesoreria | aprobado | rechazado | pagado
 * @var string $saludo   Nombre para saludar (puede ir vacío)
 * @var string $grupo
 * @var string $numero   R6840007
 * @var string $quien    Quién pide el reintegro
 * @var string $concepto
 * @var string $fecha    Fecha del gasto
 * @var string $importe  «25,00 €»
 * @var string $forma    «Por transferencia» / «En efectivo»
 * @var string $iban     Solo en el aviso a Tesorería y mientras el reintegro lo conserve ('' si no hay)
 * @var string $evento   Nombre del evento ('' si no tiene)
 * @var string $motivo   Motivo del rechazo
 * @var string $pago     Fecha del pago ('' si no está pagado)
 * @var string $url      Pantalla de Reintegros
 * @var string $contacto Email de contacto del grupo ('' si no hay)
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Frontend\Colores;
use AsdeBalears\AreaPrivada\Idiomas\Idioma;

defined( 'ABSPATH' ) || exit;

$col     = Colores::para_email();
$p       = 'margin:0 0 16px;';
$fuerte  = static fn( string $t ): string => '<strong>' . esc_html( $t ) . '</strong>';
$filas   = array(
	asde_t( 'Reintegro' )         => $numero,
	asde_t( 'Quién lo pide' )     => $quien,
	asde_t( 'Concepto' )          => $concepto,
	asde_t( 'Fecha del gasto' )   => $fecha,
	asde_t( 'Importe' )           => $importe,
	asde_t( 'Cómo quiere cobrarlo' ) => $forma,
);
if ( '' !== $evento ) {
	$filas[ asde_t( 'Evento' ) ] = $evento;
}
if ( '' !== $iban ) {
	$filas[ asde_t( 'IBAN' ) ] = $iban;
}
?>
<!DOCTYPE html>
<html lang="<?php echo esc_attr( Idioma::actual() ); ?>">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width"></head>
<body style="margin:0;padding:0;background:<?php echo esc_attr( $col['fondo'] ); ?>;font-family:'Open Sans',Helvetica,Arial,sans-serif;color:<?php echo esc_attr( $col['texto'] ); ?>;">
	<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:<?php echo esc_attr( $col['fondo'] ); ?>;padding:24px 12px;">
		<tr><td align="center">
			<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:560px;background:<?php echo esc_attr( $col['tarjeta'] ); ?>;border-radius:6px;overflow:hidden;">
				<tr><td style="background:<?php echo esc_attr( $col['primary'] ); ?>;color:<?php echo esc_attr( $col['on_primary'] ); ?>;padding:20px 28px;font-size:18px;font-weight:600;">
					<?php echo esc_html( $grupo ); ?> · <?php asde_e( 'Reintegros' ); ?>
				</td></tr>
				<tr><td style="padding:28px;font-size:15px;line-height:1.6;">
					<p style="<?php echo esc_attr( $p ); ?>"><?php echo $saludo ? sprintf( asde_h( 'Hola, %s:' ), esc_html( $saludo ) ) : asde_h( 'Hola:' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></p>

					<?php if ( 'aprobado' === $tipo ) : ?>
						<p style="<?php echo esc_attr( $p ); ?>"><?php echo sprintf( asde_h( 'Tesorería ha aprobado tu reintegro %s. En cuanto se pague, te lo avisamos.' ), $fuerte( $numero ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></p>
					<?php elseif ( 'rechazado' === $tipo ) : ?>
						<p style="<?php echo esc_attr( $p ); ?>"><?php echo sprintf( asde_h( 'Tesorería no ha aprobado tu reintegro %s.' ), $fuerte( $numero ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></p>
						<?php if ( '' !== $motivo ) : ?>
							<p style="<?php echo esc_attr( $p ); ?>"><?php echo sprintf( asde_h( 'Motivo: %s' ), esc_html( $motivo ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></p>
						<?php endif; ?>
					<?php elseif ( 'pagado' === $tipo ) : ?>
						<p style="<?php echo esc_attr( $p ); ?>"><?php echo sprintf( asde_h( 'Tu reintegro %1$s ya está pagado (%2$s).' ), $fuerte( $numero ), esc_html( $pago ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></p>
					<?php else : ?>
						<p style="<?php echo esc_attr( $p ); ?>"><?php echo sprintf( asde_h( '%1$s pide el reintegro %2$s, que quiere cobrar por transferencia. Hay que aprobarlo y, después, pagarlo.' ), esc_html( $quien ), $fuerte( $numero ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></p>
					<?php endif; ?>

					<table role="presentation" cellspacing="0" cellpadding="4" style="margin:0 0 16px;font-size:14px;">
						<?php foreach ( $filas as $etiqueta => $valor ) : ?>
							<tr>
								<td style="color:<?php echo esc_attr( $col['atenuado'] ); ?>;vertical-align:top;"><?php echo esc_html( $etiqueta ); ?></td>
								<td><?php echo $fuerte( (string) $valor ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
							</tr>
						<?php endforeach; ?>
					</table>

					<p style="margin:24px 0;text-align:center;">
						<a href="<?php echo esc_url( $url ); ?>" style="display:inline-block;background:<?php echo esc_attr( $col['primary'] ); ?>;color:<?php echo esc_attr( $col['on_primary'] ); ?>;text-decoration:none;font-weight:600;padding:12px 22px;border-radius:4px;"><?php asde_e( 'Ver los reintegros' ); ?></a>
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
