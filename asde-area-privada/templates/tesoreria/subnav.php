<?php
/**
 * Pestañas de Tesorería + selector de ronda.
 *
 * @var array<string, string> $items
 * @var string                $activo
 * @var string                $ronda
 * @var string[]              $rondas
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Frontend\Pages;

defined( 'ABSPATH' ) || exit;
?>
<div class="asde-subnav">
	<ul class="asde-subnav__tabs">
		<?php foreach ( $items as $key => $label ) : ?>
			<li>
				<a href="<?php echo esc_url( Pages::url( $key, array( 'ronda' => $ronda ) ) ); ?>"<?php echo $key === $activo ? ' class="is-active" aria-current="page"' : ''; ?>><?php echo esc_html( $label ); ?></a>
			</li>
		<?php endforeach; ?>
	</ul>
	<form method="get" class="asde-subnav__ronda">
		<label for="asde-ronda"><?php asde_e( 'Ronda' ); ?></label>
		<select id="asde-ronda" name="ronda" data-asde-autoenviar>
			<?php foreach ( $rondas as $r ) : ?>
				<option value="<?php echo esc_attr( $r ); ?>" <?php selected( $ronda, $r ); ?>><?php echo esc_html( $r ); ?></option>
			<?php endforeach; ?>
			<?php $siguiente = ( (int) substr( $rondas[0] ?? $ronda, 0, 4 ) + 1 ) . '-' . ( (int) substr( $rondas[0] ?? $ronda, 0, 4 ) + 2 ); ?>
			<?php if ( ! in_array( $siguiente, $rondas, true ) ) : ?>
				<option value="<?php echo esc_attr( $siguiente ); ?>" <?php selected( $ronda, $siguiente ); ?>><?php echo esc_html( sprintf( asde_t( '%s (preparar)' ), $siguiente ) ); ?></option>
			<?php endif; ?>
		</select>
		<noscript><button type="submit" class="button"><?php asde_e( 'Ver' ); ?></button></noscript>
	</form>
</div>
