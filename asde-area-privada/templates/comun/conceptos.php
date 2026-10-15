<?php
/**
 * «Otros conceptos» del coste federativo (Tesorería del grupo y pantalla de la
 * organización en wp-admin). Sin JavaScript funciona igual: hay una fila vacía
 * para añadir y, para quitar un concepto, se deja su nombre vacío.
 * assets/js/conceptos.js añade el botón de añadir filas.
 *
 * @var array<int, array{nombre:string, importe:float, aplica:string}> $conceptos
 * @var string $campo    Nombre base del campo (p. ej. «federacion[conceptos]»)
 * @var bool   $editable
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Domain\Organizacion;

defined( 'ABSPATH' ) || exit;

$fila = static function ( string $i, array $c, bool $editable ) use ( $campo ): string {
	$ro      = $editable ? '' : ' disabled';
	$opciones = '';
	foreach ( asde_ta( Organizacion::APLICA ) as $valor => $etiqueta ) {
		$opciones .= sprintf( '<option value="%s"%s>%s</option>', esc_attr( $valor ), selected( $c['aplica'] ?? 'asociado', $valor, false ), esc_html( $etiqueta ) );
	}
	return sprintf(
		'<tr class="asde-conceptos__fila"><td data-label="%7$s"><input type="text" name="%1$s[%2$s][nombre]" value="%3$s" maxlength="80" placeholder="%8$s" aria-label="%8$s"%6$s></td><td data-label="%9$s"><input type="number" name="%1$s[%2$s][importe]" value="%4$s" step="0.01" min="-99999" max="99999" aria-label="%10$s"%6$s></td><td data-label="%11$s"><select name="%1$s[%2$s][aplica]" aria-label="%11$s"%6$s>%5$s</select></td></tr>',
		esc_attr( $campo ),
		esc_attr( $i ),
		esc_attr( (string) ( $c['nombre'] ?? '' ) ),
		esc_attr( '' === (string) ( $c['nombre'] ?? '' ) ? '' : (string) (float) ( $c['importe'] ?? 0 ) ),
		$opciones,
		$ro,
		asde_a( 'Concepto' ),
		asde_a( 'Nombre del concepto' ),
		asde_a( 'Importe (€)' ),
		asde_a( 'Importe en euros' ),
		asde_a( 'Se aplica a' )
	);
};
?>
<div class="asde-conceptos" data-asde-conceptos data-siguiente="<?php echo (int) count( $conceptos ) + 1; ?>">
	<table class="asde-table asde-conceptos__tabla">
		<thead>
			<tr><th scope="col"><?php asde_e( 'Concepto' ); ?></th><th scope="col"><?php asde_e( 'Importe (€)' ); ?></th><th scope="col"><?php asde_e( 'Se aplica a' ); ?></th></tr>
		</thead>
		<tbody data-asde-conceptos-filas>
			<?php foreach ( array_values( $conceptos ) as $i => $c ) : ?>
				<?php echo $fila( (string) $i, $c, $editable ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<?php endforeach; ?>
			<?php if ( $editable && count( $conceptos ) < Organizacion::MAX_CONCEPTOS ) : ?>
				<?php echo $fila( (string) count( $conceptos ), array(), true ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<?php endif; ?>
			<?php if ( ! $conceptos && ! $editable ) : ?>
				<tr><td colspan="3" class="asde-muted"><?php asde_e( 'Ningún otro concepto.' ); ?></td></tr>
			<?php endif; ?>
		</tbody>
	</table>
	<?php if ( $editable ) : ?>
		<template data-asde-conceptos-plantilla><?php echo $fila( '__i__', array(), true ); // phpcs:ignore WordPress.Security.EscapeOutput ?></template>
		<p class="asde-conceptos__acciones">
			<button type="button" class="button" data-asde-conceptos-anadir hidden><?php asde_e( 'Añadir concepto' ); ?></button>
			<span class="asde-help"><?php asde_e( 'Para quitar un concepto, deja su nombre vacío. Un importe negativo es un descuento. Máximo' ); ?> <?php echo (int) Organizacion::MAX_CONCEPTOS; ?>.</span>
		</p>
	<?php endif; ?>
</div>
