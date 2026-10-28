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
	foreach ( ( Organizacion::aplica() ) as $valor => $etiqueta ) {
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
		esc_attr__( 'Description', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
		esc_attr__( 'Item name', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
		esc_attr__( 'Amount (€)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
		esc_attr__( 'Amount in euros', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
		esc_attr__( 'Applies to', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' )
	);
};
?>
<div class="asde-conceptos" data-asde-conceptos data-siguiente="<?php echo (int) count( $conceptos ) + 1; ?>">
	<table class="asde-table asde-conceptos__tabla">
		<thead>
			<tr><th scope="col"><?php esc_html_e( 'Description', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th><th scope="col"><?php esc_html_e( 'Amount (€)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th><th scope="col"><?php esc_html_e( 'Applies to', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th></tr>
		</thead>
		<tbody data-asde-conceptos-filas>
			<?php foreach ( array_values( $conceptos ) as $i => $c ) : ?>
				<?php echo wp_kses( $fila( (string) $i, $c, $editable ), asde_html_permitido() ); ?>
			<?php endforeach; ?>
			<?php if ( $editable && count( $conceptos ) < Organizacion::MAX_CONCEPTOS ) : ?>
				<?php echo wp_kses( $fila( (string) count( $conceptos ), array(), true ), asde_html_permitido() ); ?>
			<?php endif; ?>
			<?php if ( ! $conceptos && ! $editable ) : ?>
				<tr><td colspan="3" class="asde-muted"><?php esc_html_e( 'No other items.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></td></tr>
			<?php endif; ?>
		</tbody>
	</table>
	<?php if ( $editable ) : ?>
		<template data-asde-conceptos-plantilla><?php echo wp_kses( $fila( '__i__', array(), true ), asde_html_permitido() ); ?></template>
		<p class="asde-conceptos__acciones">
			<button type="button" class="button" data-asde-conceptos-anadir hidden><?php esc_html_e( 'Add item', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
			<span class="asde-help"><?php esc_html_e( 'To remove an item, leave its name empty. A negative amount is a discount. Maximum', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <?php echo (int) Organizacion::MAX_CONCEPTOS; ?>.</span>
		</p>
	<?php endif; ?>
</div>
