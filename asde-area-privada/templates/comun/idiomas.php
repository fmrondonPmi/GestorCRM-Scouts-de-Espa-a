<?php
/**
 * Selector de idioma del área privada: los idiomas oficiales de España con lengua propia.
 * Solo sale si la administración deja que cada persona elija (wp-admin → Idioma y etiquetas).
 * Cada enlace cambia el idioma con un nonce y vuelve a esta misma página.
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Idiomas\Idioma;

defined( 'ABSPATH' ) || exit;

if ( ! Idioma::se_puede_elegir() ) {
	return;
}
$actual = Idioma::actual();
?>
<span class="asde-idiomas" role="group" aria-label="<?php echo esc_attr( asde_t( 'Idioma' ) ); ?>">
	<?php foreach ( Idioma::disponibles() as $codigo => $nombre ) : ?>
		<?php if ( $codigo === $actual ) : ?>
			<strong lang="<?php echo esc_attr( $codigo ); ?>" aria-current="true"><?php echo esc_html( $nombre ); ?></strong>
		<?php else : ?>
			<a href="<?php echo esc_url( Idioma::url_cambio( $codigo ) ); ?>" lang="<?php echo esc_attr( $codigo ); ?>" hreflang="<?php echo esc_attr( $codigo ); ?>" rel="nofollow"><?php echo esc_html( $nombre ); ?></a>
		<?php endif; ?>
	<?php endforeach; ?>
</span>
