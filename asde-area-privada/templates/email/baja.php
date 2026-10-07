<?php
/**
 * Aviso de una baja dada desde la web (HTML con estilos en línea; doc 34, P-90).
 * A Tesorería: quién, desde cuándo, el motivo y su familia. A la familia (o a la propia
 * persona, si es mayor de edad): la confirmación, sin el motivo.
 *
 * @var string $para     Usuarios\AvisoBaja::TESORERIA o ::FAMILIA
 * @var string $nombre   Cómo se llama quien lo recibe ('' si no se sabe)
 * @var string $persona  Nombre de quien causa baja
 * @var string $socio    Su nº de socio/a de Scouts de España
 * @var string $fin      Fecha de baja (AAAA-MM-DD)
 * @var string $motivo   Motivo, ya traducido ('' si no hay)
 * @var string $familia  Nº de su familia en Tesorería ('' si no está en ninguna)
 * @var string $grupo    Nombre del grupo
 * @var string $contacto Email de contacto del grupo ('' si no hay)
 * @var string $url      Tesorería en el área privada
 */
use AsdeBalears\AreaPrivada\Frontend\Colores;
use AsdeBalears\AreaPrivada\Idiomas\Idioma;
use AsdeBalears\AreaPrivada\Usuarios\AvisoBaja;

defined( 'ABSPATH' ) || exit;

$col   = Colores::para_email(); // Los colores elegidos en wp-admin → Colores (los emails no usan el CSS del plugin).
$f     = DateTime::createFromFormat( '!Y-m-d', $fin );
$fecha = $f ? $f->format( 'd/m/Y' ) : $fin;
?>
<!DOCTYPE html>
<html lang="<?php echo esc_attr( Idioma::actual() ); ?>">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width"></head>
<body style="margin:0;padding:0;background:<?php echo esc_attr( $col['fondo'] ); ?>;font-family:'Open Sans',Helvetica,Arial,sans-serif;color:<?php echo esc_attr( $col['texto'] ); ?>;">
	<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:<?php echo esc_attr( $col['fondo'] ); ?>;padding:24px 12px;">
		<tr><td align="center">
			<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:560px;background:<?php echo esc_attr( $col['tarjeta'] ); ?>;border-radius:6px;overflow:hidden;">
				<tr><td style="background:<?php echo esc_attr( $col['primary'] ); ?>;color:<?php echo esc_attr( $col['on_primary'] ); ?>;padding:20px 28px;font-size:18px;font-weight:600;">
					<?php echo esc_html( $grupo ); ?> · <?php asde_e( 'Área privada' ); ?>
				</td></tr>
				<tr><td style="padding:28px;font-size:15px;line-height:1.6;">
					<p style="margin:0 0 16px;"><?php echo '' !== $nombre ? sprintf( asde_h( 'Hola, %s:' ), esc_html( $nombre ) ) : asde_h( 'Hola:' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></p>
					<?php if ( AvisoBaja::TESORERIA === $para ) : ?>
						<p style="margin:0 0 16px;"><?php echo sprintf( asde_h( 'Se ha dado de baja en el CRM a <strong>%1$s</strong> (nº de socio/a %2$s) desde el <strong>%3$s</strong>.' ), esc_html( $persona ), esc_html( $socio ), esc_html( $fecha ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></p>
						<?php if ( '' !== $motivo ) : ?>
							<p style="margin:0 0 16px;"><strong><?php asde_e( 'Motivo:' ); ?></strong> <?php echo esc_html( $motivo ); ?></p>
						<?php endif; ?>
						<?php if ( '' !== $familia ) : ?>
							<p style="margin:0 0 16px;"><?php echo sprintf( asde_h( 'Es de la familia <strong>%s</strong>: desde la baja ya no cuenta en su cuota. Revisa si hay que ajustar algo en sus pagos.' ), esc_html( $familia ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></p>
						<?php else : ?>
							<p style="margin:0 0 16px;"><?php asde_e( 'No está en ninguna familia de Tesorería.' ); ?></p>
						<?php endif; ?>
						<p style="margin:0 0 24px;text-align:center;">
							<a href="<?php echo esc_url( $url ); ?>" style="display:inline-block;background:<?php echo esc_attr( $col['primary'] ); ?>;color:<?php echo esc_attr( $col['on_primary'] ); ?>;padding:12px 24px;border-radius:4px;text-decoration:none;font-weight:600;"><?php asde_e( 'Ir a Tesorería' ); ?></a>
						</p>
						<p style="margin:0;font-size:13px;color:<?php echo esc_attr( $col['atenuado'] ); ?>;"><?php asde_e( 'Te llega porque tienes la responsabilidad de Tesorería en el área privada del grupo. Para verlo tendrás que iniciar sesión.' ); ?></p>
					<?php else : ?>
						<p style="margin:0 0 16px;"><?php echo sprintf( asde_h( 'Te confirmamos que <strong>%1$s</strong> ha causado baja en el <strong>%2$s</strong> el %3$s.' ), esc_html( $persona ), esc_html( $grupo ), esc_html( $fecha ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></p>
						<p style="margin:0 0 16px;"><?php asde_e( 'Desde ese día ya no cuenta como socio/a del grupo ni en las cuotas, y su acceso al área privada se cierra. Su ficha se conserva: si algún día vuelve, solo hay que darle de alta de nuevo.' ); ?></p>
						<?php if ( '' !== $contacto ) : ?>
							<p style="margin:0 0 16px;"><?php echo sprintf( asde_h( 'Si es un error o tienes cualquier duda, escríbenos a %s.' ), '<a href="mailto:' . esc_attr( $contacto ) . '" style="color:' . esc_attr( $col['primary'] ) . ';font-weight:600;">' . esc_html( $contacto ) . '</a>' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></p>
						<?php else : ?>
							<p style="margin:0 0 16px;"><?php asde_e( 'Si es un error o tienes cualquier duda, habla con la Secretaría del grupo.' ); ?></p>
						<?php endif; ?>
						<p style="margin:0;"><?php asde_e( '¡Gracias por todo lo compartido!' ); ?></p>
					<?php endif; ?>
				</td></tr>
			</table>
		</td></tr>
	</table>
</body>
</html>
