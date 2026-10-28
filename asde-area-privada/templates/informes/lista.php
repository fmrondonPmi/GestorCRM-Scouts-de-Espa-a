<?php
/**
 * Informes guardados.
 *
 * @var AsdeBalears\AreaPrivada\Informes\Informe[] $informes
 * @var WP_User                                    $user
 * @var string                                     $url
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Informes\Catalogo;

defined( 'ABSPATH' ) || exit;
?>
<section class="asde-card">
	<header class="asde-card__header asde-card__header--split">
		<h2 class="asde-card__title"><?php esc_html_e( 'Reports', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h2>
		<a class="button" href="<?php echo esc_url( add_query_arg( 'nuevo', 1, $url ) ); ?>"><?php esc_html_e( '+ New report', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a>
	</header>
	<p><?php esc_html_e( 'Choose a report or create a new one by selecting the columns you need. The data is read from the CRM at that moment and includes only members with an active membership', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?><?php echo current_user_can( 'asde_manage_users' ) ? '' : esc_html( __( ' of your section', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>.</p>

	<?php if ( ! $informes ) : ?>
		<p><?php esc_html_e( 'There are no saved reports yet.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
	<?php else : ?>
		<table class="asde-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Report', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Columns', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Type', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
					<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $informes as $i ) : ?>
					<tr>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Report', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
							<a href="<?php echo esc_url( add_query_arg( 'informe', $i->id, $url ) ); ?>"><strong><?php echo esc_html( $i->titulo() ); ?></strong></a>
							<?php if ( $i->descripcion ) : ?>
								<br><span class="asde-muted"><?php echo esc_html( $i->resumen() ); ?></span>
							<?php endif; ?>
							<?php if ( Catalogo::incluye_salud( $i->columnas ) ) : ?>
								<br><span class="asde-badge asde-badge--salud"><?php esc_html_e( 'Includes health data', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span>
							<?php endif; ?>
						</td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Columns', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo (int) count( $i->columnas ); ?></td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Type', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
							<?php
							if ( $i->sistema ) {
								echo esc_html( __( 'Predefined', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
							} elseif ( $i->autor_id === $user->ID ) {
								echo esc_html( $i->compartido ? __( 'Mine · shared', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) : __( 'Mine', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
							} else {
								echo esc_html( __( 'Shared', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
							}
							?>
						</td>
						<td data-label="">
							<div class="asde-actions">
								<a href="<?php echo esc_url( add_query_arg( 'informe', $i->id, $url ) ); ?>"><?php esc_html_e( 'View', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a>
								<a href="<?php echo esc_url( add_query_arg( array( 'nuevo' => 1, 'desde' => $i->id ), $url ) ); ?>"><?php esc_html_e( 'Duplicate', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a>
								<?php if ( $i->puede_editar( $user ) ) : ?>
									<a href="<?php echo esc_url( add_query_arg( 'editar', $i->id, $url ) ); ?>"><?php esc_html_e( 'Edit', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a>
									<form method="post" data-asde-confirmar="<?php echo esc_attr( sprintf( /* translators: %s: text */ __( 'Delete the report "%s"?', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $i->nombre ) ); ?>">
										<?php wp_nonce_field( 'asde_informe_borrar_' . $i->id ); ?>
										<input type="hidden" name="asde_action" value="borrar">
										<input type="hidden" name="id" value="<?php echo (int) $i->id; ?>">
										<button type="submit" class="asde-btn-link asde-btn-danger"><?php esc_html_e( 'Delete', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
									</form>
								<?php endif; ?>
							</div>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</section>
