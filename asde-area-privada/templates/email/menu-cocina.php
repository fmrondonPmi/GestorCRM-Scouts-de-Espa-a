<?php
/**
 * Email de la hoja de cocina (v0.41, doc 49, B3): para el equipo que prepara las comidas, el nombre de cada persona afectada y su
 * alérgeno. Sin diagnóstico ni texto de la ficha. El registro de emails no guarda este contenido.
 *
 * @var string $saludo
 * @var string $evento
 * @var string $numero
 * @var string $cuando
 * @var array  $hoja    HojaCocina::componer()
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Frontend\Colores;
use AsdeBalears\AreaPrivada\Idiomas\Idioma;
use AsdeBalears\AreaPrivada\Menus\MenuTable;

defined( 'ABSPATH' ) || exit;

$col = Colores::para_email();
$p   = 'margin:0 0 12px;';
?>
<!DOCTYPE html>
<html lang="<?php echo esc_attr( Idioma::actual() ); ?>">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width"></head>
<body style="margin:0;padding:0;background:<?php echo esc_attr( $col['fondo'] ); ?>;font-family:'Open Sans',Helvetica,Arial,sans-serif;color:<?php echo esc_attr( $col['texto'] ); ?>;">
	<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:<?php echo esc_attr( $col['fondo'] ); ?>;padding:24px 12px;">
		<tr><td align="center">
			<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:600px;background:<?php echo esc_attr( $col['tarjeta'] ); ?>;border-radius:6px;overflow:hidden;">
				<tr><td style="background:<?php echo esc_attr( $col['primary'] ); ?>;color:<?php echo esc_attr( $col['on_primary'] ); ?>;padding:20px 28px;font-size:18px;font-weight:600;">
					<?php asde_e( 'Hoja de cocina' ); ?> · <?php echo esc_html( $evento ); ?>
				</td></tr>
				<tr><td style="padding:28px;font-size:15px;line-height:1.6;">
					<p style="<?php echo esc_attr( $p ); ?>"><?php echo $saludo ? sprintf( asde_h( 'Hola, %s:' ), esc_html( $saludo ) ) : asde_h( 'Hola:' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></p>
					<p style="<?php echo esc_attr( $p ); ?>"><?php echo esc_html( sprintf( asde_t( 'Estas son las personas con alergias, intolerancias o dieta que cruzan con el menú de %1$s (%2$s). Válida a fecha de %3$s: si algo cambia, se volverá a enviar.' ), $numero, $cuando, $hoja['momento'] ) ); ?></p>
					<p style="<?php echo esc_attr( $p ); ?>color:<?php echo esc_attr( $col['atenuado'] ); ?>;font-size:13px;"><?php asde_e( 'Contiene datos de salud de menores: no lo reenvíes, no lo dejes a la vista y bórralo al acabar el evento.' ); ?></p>
					<?php foreach ( $hoja['platos'] as $pl ) : ?>
						<?php if ( ! $pl['personas'] ) : ?>
							<?php continue; ?>
						<?php endif; ?>
						<p style="<?php echo esc_attr( $p ); ?>"><strong><?php echo esc_html( wp_date( 'l d/m', strtotime( $pl['dia'] . ' 12:00 UTC' ) ) . ' · ' . asde_t( MenuTable::COMIDAS[ $pl['comida'] ] ?? '' ) . ' · ' . $pl['plato'] ); ?></strong></p>
						<ul style="margin:0 0 16px;padding-left:20px;">
							<?php foreach ( $pl['personas'] as $nombre => $d ) : ?>
								<li><?php echo esc_html( $nombre ); ?>:
									<?php
									$partes = array();
									if ( ! empty( $d['alergenos'] ) ) {
										$partes[] = implode( ', ', array_unique( $d['alergenos'] ) );
									}
									if ( ! empty( $d['trazas'] ) ) {
										$partes[] = sprintf( asde_t( 'trazas de %s' ), implode( ', ', array_unique( $d['trazas'] ) ) );
									}
									if ( ! empty( $d['dieta'] ) ) {
										$partes[] = implode( ', ', array_unique( $d['dieta'] ) );
									}
									echo esc_html( implode( ' · ', $partes ) );
									?>
								</li>
							<?php endforeach; ?>
						</ul>
					<?php endforeach; ?>
				</td></tr>
			</table>
		</td></tr>
	</table>
</body>
</html>
