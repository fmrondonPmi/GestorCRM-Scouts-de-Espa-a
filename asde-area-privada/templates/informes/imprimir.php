<?php
/**
 * Versión para imprimir de un informe (v0.29): una página propia, sin el tema, con la tabla
 * entera (todas las columnas; el texto se parte en vez de cortarse). Se abre en la ventana
 * emergente, dentro de un marco ($marco: sin su barra, que ya la pone la ventana), o en otra
 * pestaña si no hay JavaScript.
 *
 * @var AsdeBalears\AreaPrivada\Informes\Informe $informe
 * @var array                                    $res     Lo que devuelve Generador::ejecutar()
 * @var string                                   $resumen Generador::resumen()
 * @var bool                                     $marco
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Domain\Grupo;
use AsdeBalears\AreaPrivada\Frontend\Colores;
use AsdeBalears\AreaPrivada\Idiomas\Idioma;
use AsdeBalears\AreaPrivada\Informes\Generador;

defined( 'ABSPATH' ) || exit;

$color = static fn( string $v, string $defecto ): string => preg_match( '/^#[0-9a-fA-F]{3,8}$/', $v ) ? $v : $defecto;
$col   = Colores::para_email();
$prim  = $color( (string) $col['primary'], '#632599' );
$texto = $color( (string) $col['texto'], '#333333' );
$suave = $color( (string) $col['fondo'], '#f4f1f7' );
// Con muchas columnas, la hoja en horizontal (las fichas, siempre en vertical: una por página).
$fichas     = $informe->en_fichas();
$horizontal = ! $fichas && count( $res['columnas'] ) > 6;
?>
<!DOCTYPE html>
<html lang="<?php echo esc_attr( Idioma::actual() ); ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?php echo esc_html( $informe->titulo() ); ?></title>
<style>
	@page { size: A4 <?php echo $horizontal ? 'landscape' : 'portrait'; ?>; margin: 12mm 10mm; }
	* { box-sizing: border-box; }
	body { margin: 0; padding: 24px; font: 13px/1.4 -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; color: <?php echo esc_html( $texto ); ?>; background: #ffffff; }
	.barra { display: flex; gap: 8px; justify-content: flex-end; margin: 0 0 16px; }
	.barra button { font: inherit; padding: 6px 14px; border: 1px solid <?php echo esc_html( $prim ); ?>; border-radius: 4px; background: <?php echo esc_html( $prim ); ?>; color: #ffffff; cursor: pointer; }
	.grupo { margin: 0; font-size: 12px; text-transform: uppercase; letter-spacing: .04em; color: <?php echo esc_html( $prim ); ?>; }
	h1 { margin: 2px 0 4px; font-size: 20px; color: <?php echo esc_html( $prim ); ?>; }
	.descripcion { margin: 0 0 4px; }
	.resumen { margin: 0 0 12px; font-size: 12px; opacity: .8; }
	.aviso { margin: 0 0 12px; padding: 8px 10px; border-left: 4px solid <?php echo esc_html( $prim ); ?>; background: <?php echo esc_html( $suave ); ?>; font-size: 12px; }
	table { width: 100%; border-collapse: collapse; font-size: 12px; }
	thead { display: table-header-group; }
	th, td { padding: 4px 6px; border: 1px solid #cccccc; text-align: left; vertical-align: top; overflow-wrap: anywhere; }
	th { background: <?php echo esc_html( $suave ); ?>; font-weight: 600; }
	tbody tr:nth-child(even) td { background: #fafafa; }
	tr { page-break-inside: avoid; break-inside: avoid; }
	.vacio { font-style: italic; }
	.pie { margin: 12px 0 0; font-size: 11px; opacity: .7; }
	/* v0.29: una ficha por persona y, al imprimir, una por página. */
	.ficha { margin: 0 0 24px; padding: 16px 0 0; border-top: 2px solid <?php echo esc_html( $prim ); ?>; }
	.ficha__cabeza { margin: 0 0 4px; font-size: 11px; opacity: .7; }
	.ficha h2 { margin: 0 0 12px; font-size: 22px; color: <?php echo esc_html( $prim ); ?>; }
	.ficha dl { margin: 0; display: grid; grid-template-columns: minmax(150px, 34%) 1fr; border-top: 1px solid #cccccc; }
	.ficha dt, .ficha dd { margin: 0; padding: 7px 8px; border-bottom: 1px solid #cccccc; }
	.ficha dt { font-weight: 600; background: <?php echo esc_html( $suave ); ?>; }
	.ficha dd { white-space: pre-line; overflow-wrap: anywhere; }
	@media print {
		body { padding: 0; }
		.barra { display: none; }
		th, tbody tr:nth-child(even) td, .ficha dt { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
		.ficha { margin: 0; border-top: 0; padding: 0; break-after: page; page-break-after: always; }
		.ficha:last-of-type { break-after: auto; page-break-after: auto; }
		.ficha dl, .ficha dt, .ficha dd { break-inside: avoid; page-break-inside: avoid; }
	}
</style>
</head>
<body>
<?php if ( ! $marco ) : ?>
	<p class="barra"><button type="button" onclick="window.print()"><?php asde_e( 'Imprimir' ); ?></button></p>
<?php endif; ?>
<p class="grupo"><?php echo esc_html( Grupo::nombre() ); ?></p>
<h1><?php echo esc_html( $informe->titulo() ); ?></h1>
<?php if ( '' !== $informe->resumen() ) : ?>
	<p class="descripcion"><?php echo esc_html( $informe->resumen() ); ?></p>
<?php endif; ?>
<p class="resumen"><?php echo esc_html( $resumen ); ?></p>
<?php if ( null !== ( $res['ronda'] ?? null ) ) : ?>
	<?php /* translators: %s: ronda (2026-2027) */ ?>
	<p class="aviso"><?php echo esc_html( sprintf( asde_t( 'Cuota de la ronda %s, de Tesorería: los importes son los de la familia de cada socio (con sus hermanos).' ), $res['ronda'] ) ); ?></p>
<?php endif; ?>
<?php foreach ( $res['avisos'] as $aviso ) : ?>
	<p class="aviso"><?php echo esc_html( $aviso ); ?></p>
<?php endforeach; ?>
<?php if ( $res['salud'] ) : ?>
	<p class="aviso"><?php asde_e( 'Este informe incluye datos de salud: úsalos solo para la actividad, no los reenvíes y destruye las copias impresas al terminar.' ); ?></p>
<?php endif; ?>

<?php if ( ! $res['filas'] ) : ?>
	<p class="vacio"><?php asde_e( 'No hay socios que cumplan estos filtros.' ); ?></p>
<?php elseif ( $fichas ) : ?>
	<?php foreach ( $res['filas'] as $fila ) : ?>
		<?php $ficha = Generador::ficha( $fila, $res['columnas'] ); ?>
		<section class="ficha">
			<p class="ficha__cabeza"><?php echo esc_html( Grupo::nombre() . ' · ' . $informe->titulo() ); ?></p>
			<h2><?php echo esc_html( $ficha['titulo'] ); ?></h2>
			<dl>
				<?php foreach ( $ficha['datos'] as $etiqueta => $valor ) : ?>
					<dt><?php echo esc_html( $etiqueta ); ?></dt>
					<dd><?php echo '' !== $valor ? esc_html( $valor ) : '—'; ?></dd>
				<?php endforeach; ?>
			</dl>
		</section>
	<?php endforeach; ?>
<?php else : ?>
	<table>
		<thead>
			<tr>
				<?php foreach ( $res['columnas'] as $label ) : ?>
					<th scope="col"><?php echo esc_html( $label ); ?></th>
				<?php endforeach; ?>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $res['filas'] as $fila ) : ?>
				<tr>
					<?php foreach ( $fila as $valor ) : ?>
						<td><?php echo esc_html( $valor ); ?></td>
					<?php endforeach; ?>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
<?php endif; ?>
<?php /* translators: %s: fecha y hora */ ?>
<p class="pie"><?php echo esc_html( sprintf( asde_t( 'Impreso desde el área privada el %s.' ), wp_date( 'd/m/Y H:i' ) ) ); ?></p>
</body>
</html>
