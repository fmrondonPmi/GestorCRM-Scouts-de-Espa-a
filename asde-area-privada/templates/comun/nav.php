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
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Domain\Grupo;
use AsdeBalears\AreaPrivada\Domain\Grupos;
use AsdeBalears\AreaPrivada\Domain\Identidad;
use AsdeBalears\AreaPrivada\Domain\Organizacion;
use AsdeBalears\AreaPrivada\Frontend\Ayuda;
use AsdeBalears\AreaPrivada\Frontend\Pages;
use AsdeBalears\AreaPrivada\Frontend\Tema;
use AsdeBalears\AreaPrivada\Frontend\TemplateLoader;
use AsdeBalears\AreaPrivada\Socios\Numeros;

defined( 'ABSPATH' ) || exit;
?>
<nav class="asde-nav" aria-label="<?php echo esc_attr( esc_attr__( 'Private area', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
	<?php
	// v0.15: el menú va por grupos (lo personal/familiar y la gestión del grupo). Con un solo
	// grupo no hace falta rotularlo. Un tema que sobrescriba esta plantilla puede seguir usando $items.
	$grupos  = $grupos ?? array();
	$rotulos = count( $grupos ) > 1;
	$bloques = $grupos ? array_merge( array( array( 'titulo' => '', 'items' => array( 'area' => $items['area'] ?? __( 'Home', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) ) ), array_values( $grupos ) ) : array( array( 'titulo' => '', 'items' => $items ) );
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
			<?php /* v0.26: la ayuda de esta página (el apartado del manual de su rol), en la ventana emergente. */ ?>
			<a class="asde-nav__ayuda" href="<?php echo esc_url( Ayuda::url( Pages::current_key() ?? ( '' !== (string) ( $pagina ?? '' ) ? (string) $pagina : 'area' ) ) ); ?>" data-asde-modal><?php esc_html_e( 'Help', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a> ·
			<a href="<?php echo esc_url( Pages::url( 'seguridad' ) ); ?>"<?php echo 'seguridad' === ( $pagina ?? '' ) ? ' aria-current="page" class="is-active"' : ''; ?>><?php esc_html_e( 'Security', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a> ·
			<a href="<?php echo esc_url( wp_logout_url( home_url( '/' ) ) ); ?>"><?php esc_html_e( 'Sign out', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a>
			<?php if ( Identidad::es_asde() && ( current_user_can( 'asde_manage_users' ) || current_user_can( 'asde_tesoreria_acceso' ) || current_user_can( 'asde_reports' ) || current_user_can( 'asde_approve_changes' ) ) ) : ?>
				<?php $ver_local = Numeros::ver_local(); ?>
				· <a href="<?php echo esc_url( add_query_arg( array( 'asde_ver_local' => $ver_local ? '0' : '1', '_wpnonce' => wp_create_nonce( 'asde_ver_local' ) ) ) ); ?>" title="<?php echo esc_attr( esc_attr__( 'The territorial no. appears next to the Scouts de España no. on all screens. If there is any problem with it, the notice always appears.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo $ver_local ? esc_html( __( 'Hide territorial no.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) : esc_html( __( 'Show territorial no.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?></a>
			<?php endif; ?>
			<?php if ( Tema::cabecera_plegable() ) : ?>
				<button type="button" class="asde-nav__cabecera" aria-controls="header" aria-expanded="true" title="<?php echo esc_attr( esc_attr__( 'Hide or show the theme header to gain space', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
					<span class="asde-nav__cabecera-icono" aria-hidden="true">▴</span>
					<span class="asde-nav__cabecera-texto"><?php esc_html_e( 'Hide header', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span>
				</button>
			<?php endif; ?>
		</p>
	<?php endif; ?>
	<?php if ( Organizacion::es_oficina() && current_user_can( 'asde_multigrupo' ) ) : ?>
		<?php // v0.50 (doc 50, fase 3): la oficina elige el grupo que consulta; todo es de solo lectura. ?>
		<form method="post" action="<?php echo esc_url( Pages::url( Pages::current_key() ?? 'area' ) ); ?>" class="asde-form asde-form--compacta asde-nav__oficina">
			<input type="hidden" name="asde_action" value="oficina_cambiar_grupo">
			<?php wp_nonce_field( 'asde_oficina_grupo' ); ?>
			<p class="asde-form__fila asde-form__fila--una-linea">
				<label for="asde-oficina-grupo"><?php esc_html_e( 'Group', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?>
					<select id="asde-oficina-grupo" name="grupo" data-asde-autoenviar>
						<?php foreach ( Grupos::todos() as $numero => $g ) : ?>
							<option value="<?php echo esc_attr( (string) $numero ); ?>" <?php selected( Grupo::numero(), (string) $numero ); ?>><?php echo esc_html( Grupos::etiqueta( $g ) ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<noscript><button type="submit" class="button"><?php esc_html_e( 'Change group', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button></noscript>
				<span class="asde-muted"><?php esc_html_e( 'Office · read-only', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span>
			</p>
		</form>
	<?php endif; ?>
</nav>
