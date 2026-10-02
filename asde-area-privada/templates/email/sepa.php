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
defined( 'ABSPATH' ) || exit;

$aviso = 'preaviso' === $tipo;
?>
<!DOCTYPE html>
<html lang="es">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width"></head>
<body style="margin:0;padding:0;background:#f4f1f7;font-family:'Open Sans',Helvetica,Arial,sans-serif;color:#444444;">
	<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f4f1f7;padding:24px 12px;">
		<tr><td align="center">
			<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:560px;background:#ffffff;border-radius:6px;overflow:hidden;">
				<tr><td style="background:#632599;color:#ffffff;padding:20px 28px;font-size:18px;font-weight:600;">
					<?php echo esc_html( $grupo ); ?> · Domiciliación SEPA
				</td></tr>
				<tr><td style="padding:28px;font-size:15px;line-height:1.6;">
					<p style="margin:0 0 16px;">Hola<?php echo $nombre ? ', ' . esc_html( $nombre ) : ''; ?>:</p>
					<?php if ( $aviso ) : ?>
						<p style="margin:0 0 16px;">Te avisamos de que <strong><?php echo esc_html( $fecha ); ?></strong> se cargarán <strong><?php echo esc_html( $importe ); ?></strong> en la cuenta <strong><?php echo esc_html( $iban ); ?></strong>, en concepto de «<?php echo esc_html( $concepto ); ?>».</p>
						<p style="margin:0 0 16px;font-size:13px;color:#555555;">Es un adeudo directo SEPA, con la autorización que firmaste (referencia <?php echo esc_html( $umr ); ?>). Puedes ver el cargo y tus pagos en «Mis pagos». Si no estás de acuerdo, avísanos antes de esa fecha.</p>
					<?php else : ?>
						<p style="margin:0 0 16px;">Hemos guardado tu <strong>autorización de domiciliación SEPA</strong> para los pagos del <?php echo esc_html( $grupo ); ?>.</p>
						<p style="margin:0 0 16px;">Cuenta: <strong><?php echo esc_html( $iban ); ?></strong><br>Referencia de la autorización: <strong><?php echo esc_html( $umr ); ?></strong></p>
						<p style="margin:0 0 16px;font-size:13px;color:#555555;">Te avisaremos antes de cada cargo, con su fecha y su importe. Puedes ver la autorización, imprimirla o anularla cuando quieras desde «Mis pagos».</p>
					<?php endif; ?>
					<?php if ( $url ) : ?>
						<p style="margin:0 0 24px;text-align:center;">
							<a href="<?php echo esc_url( $url ); ?>" style="display:inline-block;background:#632599;color:#ffffff;text-decoration:none;padding:12px 28px;border-radius:4px;font-weight:600;">Ir a Mis pagos</a>
						</p>
					<?php endif; ?>
					<p style="margin:0;font-size:13px;color:#555555;">
						<?php if ( '' !== $contacto ) : ?>
							¿Dudas? Escribe a <a href="mailto:<?php echo esc_attr( $contacto ); ?>" style="color:#632599;"><?php echo esc_html( $contacto ); ?></a>.
						<?php else : ?>
							¿Dudas? Habla con la Tesorería de tu grupo.
						<?php endif; ?>
					</p>
				</td></tr>
			</table>
		</td></tr>
	</table>
</body>
</html>
