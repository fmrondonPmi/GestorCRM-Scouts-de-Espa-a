<?php
/**
 * Emails de la domiciliación SEPA: confirmación de la autorización y aviso previo de un cargo.
 *
 * @var string $tipo     autorizacion | preaviso
 * @var string $nombre   Saludo (leído del CRM; puede ir vacío)
 * @var string $grupo
 * @var string $umr      Referencia del mandato
 * @var string $iban     IBAN enmascarado
 * @var string $importe  «12,50 €» (solo en el aviso previo)
 * @var string $fecha    Fecha del cargo, dd/mm/aaaa (solo en el aviso previo)
 * @var string $concepto Concepto del cargo (solo en el aviso previo)
 * @var string $url      «Mis pagos»
 * @var string $contacto Email de contacto del grupo ('' si no hay)
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Frontend\Colores;
use AsdeBalears\AreaPrivada\Idiomas\Idioma;

defined( 'ABSPATH' ) || exit;

$col = Colores::para_email(); // Los colores elegidos en wp-admin → Colores (los emails no usan el CSS del plugin).

$aviso = 'preaviso' === $tipo;
?>
<!DOCTYPE html>
<html lang="<?php echo esc_attr( Idioma::actual() ); ?>">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width"></head>
<body style="margin:0;padding:0;background:<?php echo esc_attr( $col['fondo'] ); ?>;font-family:'Open Sans',Helvetica,Arial,sans-serif;color:<?php echo esc_attr( $col['texto'] ); ?>;">
	<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:<?php echo esc_attr( $col['fondo'] ); ?>;padding:24px 12px;">
		<tr><td align="center">
			<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:560px;background:<?php echo esc_attr( $col['tarjeta'] ); ?>;border-radius:6px;overflow:hidden;">
				<tr><td style="background:<?php echo esc_attr( $col['primary'] ); ?>;color:<?php echo esc_attr( $col['on_primary'] ); ?>;padding:20px 28px;font-size:18px;font-weight:600;">
					<?php echo esc_html( $grupo ); ?> · <?php asde_e( 'Domiciliación SEPA' ); ?>
				</td></tr>
				<tr><td style="padding:28px;font-size:15px;line-height:1.6;">
					<p style="margin:0 0 16px;"><?php echo $nombre ? sprintf( asde_h( 'Hola, %s:' ), esc_html( $nombre ) ) : asde_h( 'Hola:' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></p>
					<?php if ( $aviso ) : ?>
						<p style="margin:0 0 16px;"><?php echo sprintf( asde_h( 'Te avisamos de que <strong>%1$s</strong> se cargarán <strong>%2$s</strong> en la cuenta <strong>%3$s</strong>, en concepto de «%4$s».' ), esc_html( $fecha ), esc_html( $importe ), esc_html( $iban ), esc_html( $concepto ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></p>
						<p style="margin:0 0 16px;font-size:13px;color:<?php echo esc_attr( $col['atenuado'] ); ?>;"><?php echo sprintf( asde_h( 'Es un adeudo directo SEPA, con la autorización que firmaste (referencia %s). Puedes ver el cargo y tus pagos en «Mis pagos». Si no estás de acuerdo, avísanos antes de esa fecha.' ), esc_html( $umr ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></p>
					<?php else : ?>
						<p style="margin:0 0 16px;"><?php echo sprintf( asde_h( 'Hemos guardado tu <strong>autorización de domiciliación SEPA</strong> para los pagos del %s.' ), esc_html( $grupo ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></p>
						<p style="margin:0 0 16px;"><?php echo sprintf( asde_h( 'Cuenta: <strong>%s</strong>' ), esc_html( $iban ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?><br><?php echo sprintf( asde_h( 'Referencia de la autorización: <strong>%s</strong>' ), esc_html( $umr ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></p>
						<p style="margin:0 0 16px;font-size:13px;color:<?php echo esc_attr( $col['atenuado'] ); ?>;"><?php asde_e( 'Te avisaremos antes de cada cargo, con su fecha y su importe. Puedes ver la autorización, imprimirla o anularla cuando quieras desde «Mis pagos».' ); ?></p>
					<?php endif; ?>
					<?php if ( $url ) : ?>
						<p style="margin:0 0 24px;text-align:center;">
							<a href="<?php echo esc_url( $url ); ?>" style="display:inline-block;background:<?php echo esc_attr( $col['primary'] ); ?>;color:<?php echo esc_attr( $col['on_primary'] ); ?>;text-decoration:none;padding:12px 28px;border-radius:4px;font-weight:600;"><?php asde_e( 'Ir a Mis pagos' ); ?></a>
						</p>
					<?php endif; ?>
					<p style="margin:0;font-size:13px;color:<?php echo esc_attr( $col['atenuado'] ); ?>;">
						<?php if ( '' !== $contacto ) : ?>
							<?php echo sprintf( asde_h( '¿Dudas? Escribe a %s.' ), '<a href="mailto:' . esc_attr( $contacto ) . '" style="color:' . esc_attr( $col['primary'] ) . ';">' . esc_html( $contacto ) . '</a>' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
						<?php else : ?>
							<?php asde_e( '¿Dudas? Habla con la Tesorería de tu grupo.' ); ?>
						<?php endif; ?>
					</p>
				</td></tr>
			</table>
		</td></tr>
	</table>
</body>
</html>
