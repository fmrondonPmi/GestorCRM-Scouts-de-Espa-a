<?php
/**
 * @var array<int, array{0:string, 1:string}> $avisos [tipo, mensaje HTML ya escapado]
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
defined( 'ABSPATH' ) || exit;

foreach ( $avisos as list( $tipo, $mensaje ) ) :
	$tipo = in_array( $tipo, array( 'success', 'error', 'warning', 'info' ), true ) ? $tipo : 'info';
	?>
	<div class="asde-notice asde-notice--<?php echo esc_attr( $tipo ); ?>" role="<?php echo 'error' === $tipo ? 'alert' : 'status'; ?>">
		<?php echo wp_kses_post( $mensaje ); ?>
	</div>
	<?php
endforeach;
