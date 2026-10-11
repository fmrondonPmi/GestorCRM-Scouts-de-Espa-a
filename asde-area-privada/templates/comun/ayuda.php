<?php
/**
 * Ayuda (v0.26; solo texto desde la v0.28): el apartado de la página en el manual del rol de quien
 * entra o, con ?asde_ayuda=manual, el manual completo del rol. Al final, «Ver el manual completo»
 * (en la misma ventana) y, si está su dirección, el manual con capturas (ayuda extra, otra pestaña).
 * El texto lo genera herramientas/generar-manuales.php (ayuda/).
 *
 * @var array{html:string, manual:string, ancla?:string}|null $ayuda
 * @var bool                                                  $completo     ¿Es el manual completo del rol?
 * @var string                                                $url_completo «Ver el manual completo» ('' en el propio manual completo)
 * @var string                                                $extra        Manual con capturas, subido aparte ('' si no está su dirección)
 * @var string                                                $volver       Página de la que es la ayuda ('' en la ventana)
 * @var bool                                                  $castellano   ¿La persona usa el área en castellano?
 */

defined( 'ABSPATH' ) || exit;
?>
<section class="asde-card asde-ayuda<?php echo $completo ? ' asde-ayuda--completo' : ''; ?>" data-asde-no-plegable>
	<?php if ( '' !== $volver ) : ?>
		<p class="asde-back"><a href="<?php echo esc_url( $volver ); ?>"><?php asde_e( '← Volver a la página' ); ?></a></p>
	<?php endif; ?>
	<?php if ( ! $castellano ) : ?>
		<p class="asde-muted asde-ayuda__idioma"><?php asde_e( 'La ayuda y el manual están en castellano.' ); ?></p>
	<?php endif; ?>
	<?php if ( $ayuda ) : ?>
		<?php echo wp_kses_post( $ayuda['html'] ); ?>
	<?php elseif ( $completo ) : ?>
		<h2><?php asde_e( 'Manual de uso' ); ?></h2>
		<p><?php asde_e( 'No hay un manual para tu cuenta.' ); ?></p>
	<?php else : ?>
		<h2><?php asde_e( 'Ayuda' ); ?></h2>
		<p><?php asde_e( 'Esta página todavía no tiene una ayuda propia.' ); ?></p>
	<?php endif; ?>
	<?php if ( '' !== $url_completo || '' !== $extra ) : ?>
		<div class="asde-ayuda__wiki">
			<?php if ( '' !== $url_completo ) : ?>
				<p>
					<a class="button asde-button-secundario" href="<?php echo esc_url( $url_completo ); ?>" data-asde-modal><?php asde_e( 'Ver el manual completo' ); ?></a>
					<span class="asde-muted"><?php asde_e( 'Todo lo que puedes hacer en el área privada, en esta misma ventana.' ); ?></span>
				</p>
			<?php endif; ?>
			<?php if ( '' !== $extra ) : ?>
				<p>
					<a href="<?php echo esc_url( $extra ); ?>" target="_blank" rel="noopener"><?php asde_e( 'Manual con capturas' ); ?></a>
					<span class="asde-muted"><?php asde_e( 'Ayuda extra: el mismo manual con capturas de pantalla, en otra pestaña.' ); ?></span>
				</p>
			<?php endif; ?>
		</div>
	<?php endif; ?>
</section>
