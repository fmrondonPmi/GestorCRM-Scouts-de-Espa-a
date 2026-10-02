<?php
/**
 * Pestañas de las páginas de uniformes.
 *
 * @var array<string, string> $items
 * @var string                $activo
 */
use AsdeBalears\AreaPrivada\Frontend\Pages;

defined( 'ABSPATH' ) || exit;
?>
<div class="asde-subnav">
	<ul class="asde-subnav__tabs">
		<?php foreach ( $items as $key => $label ) : ?>
			<li>
				<a href="<?php echo esc_url( Pages::url( $key ) ); ?>"<?php echo $key === $activo ? ' class="is-active" aria-current="page"' : ''; ?>><?php echo esc_html( $label ); ?></a>
			</li>
		<?php endforeach; ?>
	</ul>
</div>
