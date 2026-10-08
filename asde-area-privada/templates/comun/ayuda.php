<?php
/**
 * Ayuda de una página (v0.26): el apartado del manual del rol de quien entra y el enlace a su
 * manual completo publicado. El texto lo genera herramientas/generar-manuales.php (ayuda/).
 *
 * @var array{html:string, manual:string, ancla:string}|null $ayuda
 * @var string                                               $wiki       Manual completo ('' si no hay manual publicado)
 * @var string                                               $volver     Página de la que es la ayuda ('' en la ventana)
 * @var bool                                                 $castellano ¿La persona usa el área en castellano?
 */

defined( 'ABSPATH' ) || exit;
?>
<section class="asde-card asde-ayuda">
	<?php if ( '' !== $volver ) : ?>
		<p class="asde-back"><a href="<?php echo esc_url( $volver ); ?>"><?php asde_e( '← Volver a la página' ); ?></a></p>
	<?php endif; ?>
	<?php if ( ! $castellano ) : ?>
		<p class="asde-muted asde-ayuda__idioma"><?php asde_e( 'La ayuda y el manual están en castellano.' ); ?></p>
	<?php endif; ?>
	<?php if ( $ayuda ) : ?>
		<?php echo wp_kses_post( $ayuda['html'] ); ?>
	<?php else : ?>
		<h2><?php asde_e( 'Ayuda' ); ?></h2>
		<p><?php asde_e( 'Esta página todavía no tiene una ayuda propia.' ); ?></p>
	<?php endif; ?>
	<?php if ( '' !== $wiki ) : ?>
		<p class="asde-ayuda__wiki">
			<a class="button asde-button-secundario" href="<?php echo esc_url( $wiki ); ?>" target="_blank" rel="noopener"><?php asde_e( 'Ver el manual completo' ); ?></a>
			<span class="asde-muted"><?php asde_e( 'Se abre en otra pestaña, con todo lo que puedes hacer en el área privada.' ); ?></span>
		</p>
	<?php endif; ?>
</section>
