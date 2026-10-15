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
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
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
// v0.31: la hoja de estilos y el botón, encolados como pide WordPress (antes, <style> y onclick en la plantilla).
// Se puede pintar más de una vez en la misma petición (pruebas): se registra de nuevo cada vez.
wp_deregister_style( 'asde-informe-imprimir' );
wp_styles()->done = array_diff( wp_styles()->done, array( 'asde-informe-imprimir' ) );
wp_scripts()->done = array_diff( wp_scripts()->done, array( 'asde-imprimir' ) );
wp_register_style( 'asde-informe-imprimir', plugins_url( 'assets/css/informe-imprimir.css', ASDE_AP_FILE ), array(), ASDE_AP_VERSION );
wp_add_inline_style( 'asde-informe-imprimir', sprintf( '@page { size: A4 %1$s; margin: 12mm 10mm; } :root { --asde-prim: %2$s; --asde-texto: %3$s; --asde-suave: %4$s; }', $horizontal ? 'landscape' : 'portrait', $prim, $texto, $suave ) );
wp_register_script( 'asde-imprimir', plugins_url( 'assets/js/imprimir.js', ASDE_AP_FILE ), array(), ASDE_AP_VERSION, true );
?>
<!DOCTYPE html>
<html lang="<?php echo esc_attr( Idioma::actual() ); ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?php echo esc_html( $informe->titulo() ); ?></title>
<?php wp_print_styles( 'asde-informe-imprimir' ); ?>
</head>
<body>
<?php if ( ! $marco ) : ?>
	<p class="barra"><button type="button" data-asde-imprimir-pagina><?php asde_e( 'Imprimir' ); ?></button></p>
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
<?php if ( ! $marco ) { wp_print_scripts( 'asde-imprimir' ); } ?>
</body>
</html>
