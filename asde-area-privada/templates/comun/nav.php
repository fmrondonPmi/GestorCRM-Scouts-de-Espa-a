<?php
/**
 * Menú del área privada.
 *
 * @var array<string, string> $items  clave de página => etiqueta
 * @var string                $activo Pestaña marcada
 * @var string                $pagina Página en la que se está
 * @var WP_User               $user
 * @var string                $nombre Nombre leído del CRM (no se guarda en WordPress)
 */
use AsdeBalears\AreaPrivada\Domain\Identidad;
use AsdeBalears\AreaPrivada\Frontend\Pages;
use AsdeBalears\AreaPrivada\Frontend\Tema;
use AsdeBalears\AreaPrivada\Frontend\TemplateLoader;
use AsdeBalears\AreaPrivada\Socios\Numeros;

defined( 'ABSPATH' ) || exit;
?>
<nav class="asde-nav" aria-label="Área privada">
	<?php
	// v0.15: el menú va por grupos (lo personal/familiar y la gestión del grupo). Con un solo
	// grupo no hace falta rotularlo. Un tema que sobrescriba esta plantilla puede seguir usando $items.
	$grupos  = $grupos ?? array();
	$rotulos = count( $grupos ) > 1;
	$bloques = $grupos ? array_merge( array( array( 'titulo' => '', 'items' => array( 'area' => $items['area'] ?? 'Inicio' ) ) ), array_values( $grupos ) ) : array( array( 'titulo' => '', 'items' => $items ) );
	?>
	<ul class="asde-nav__tabs">
		<?php foreach ( $bloques as $i => $bloque ) : ?>
			<?php if ( $rotulos && $i > 0 ) : ?>
				<li class="asde-nav__grupo" role="presentation"><span><?php echo esc_html( $bloque['titulo'] ); ?></span></li>
			<?php endif; ?>
			<?php foreach ( $bloque['items'] as $key => $label ) : ?>
				<li>
					<a href="<?php echo esc_url( Pages::url( $key ) ); ?>"<?php echo $key === $activo ? ' class="is-active" aria-current="page"' : ''; ?>>
						<?php echo esc_html( $label ); ?>
					</a>
				</li>
			<?php endforeach; ?>
		<?php endforeach; ?>
	</ul>
	<?php if ( $user->exists() ) : ?>
		<p class="asde-nav__user">
			<?php echo esc_html( $nombre ?: $user->display_name ); ?>
			<span class="asde-muted">(<?php echo esc_html( $user->user_login ); ?>)</span> ·
			<a href="<?php echo esc_url( Pages::url( 'seguridad' ) ); ?>"<?php echo 'seguridad' === ( $pagina ?? '' ) ? ' aria-current="page" class="is-active"' : ''; ?>>Seguridad</a> ·
			<a href="<?php echo esc_url( wp_logout_url( home_url( '/' ) ) ); ?>">Salir</a>
			<?php $selector_idioma = trim( TemplateLoader::render( 'comun/idiomas' ) ); ?>
			<?php if ( '' !== $selector_idioma ) : ?>
				· <?php echo $selector_idioma; // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<?php endif; ?>
			<?php if ( Identidad::es_asde() && ( current_user_can( 'asde_manage_users' ) || current_user_can( 'asde_tesoreria_acceso' ) || current_user_can( 'asde_reports' ) || current_user_can( 'asde_approve_changes' ) ) ) : ?>
				<?php $ver_local = Numeros::ver_local(); ?>
				· <a href="<?php echo esc_url( add_query_arg( array( 'asde_ver_local' => $ver_local ? '0' : '1', '_wpnonce' => wp_create_nonce( 'asde_ver_local' ) ) ) ); ?>" title="El nº territorial sale junto al nº de Scouts de España en todas las pantallas. Si tiene algún problema, el aviso sale siempre."><?php echo $ver_local ? 'Ocultar nº territorial' : 'Mostrar nº territorial'; ?></a>
			<?php endif; ?>
			<?php if ( Tema::cabecera_plegable() ) : ?>
				<button type="button" class="asde-nav__cabecera" aria-controls="header" aria-expanded="true" title="Ocultar o mostrar la cabecera del tema para ganar espacio">
					<span class="asde-nav__cabecera-icono" aria-hidden="true">▴</span>
					<span class="asde-nav__cabecera-texto">Ocultar cabecera</span>
				</button>
			<?php endif; ?>
		</p>
	<?php endif; ?>
</nav>
