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
		<p class="asde-back"><a href="<?php echo esc_url( $volver ); ?>"><?php esc_html_e( '← Back to the page', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a></p>
	<?php endif; ?>
	<?php if ( ! $castellano ) : ?>
		<p class="asde-muted asde-ayuda__idioma"><?php esc_html_e( 'The help and the manual are in Spanish.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
	<?php endif; ?>
	<?php if ( $ayuda ) : ?>
		<?php echo wp_kses_post( $ayuda['html'] ); ?>
	<?php elseif ( $completo ) : ?>
		<h2><?php esc_html_e( 'User manual', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h2>
		<p><?php esc_html_e( 'There is no manual for your account.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
	<?php else : ?>
		<h2><?php esc_html_e( 'Help', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h2>
		<p><?php esc_html_e( 'This page does not have its own help yet.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
	<?php endif; ?>
	<?php if ( '' !== $url_completo || '' !== $extra ) : ?>
		<div class="asde-ayuda__wiki">
			<?php if ( '' !== $url_completo ) : ?>
				<p>
					<a class="button asde-button-secundario" href="<?php echo esc_url( $url_completo ); ?>" data-asde-modal><?php esc_html_e( 'See the full manual', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a>
					<span class="asde-muted"><?php esc_html_e( 'Everything you can do in the private area, in this same window.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span>
				</p>
			<?php endif; ?>
			<?php if ( '' !== $extra ) : ?>
				<p>
					<a href="<?php echo esc_url( $extra ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Manual with screenshots', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a>
					<span class="asde-muted"><?php esc_html_e( 'Extra help: the same manual with screenshots, in another tab.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span>
				</p>
			<?php endif; ?>
		</div>
	<?php endif; ?>
</section>
