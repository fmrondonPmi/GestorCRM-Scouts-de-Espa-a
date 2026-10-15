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
		<h2 class="asde-card__title"><?php asde_e( 'Informes' ); ?></h2>
		<a class="button" href="<?php echo esc_url( add_query_arg( 'nuevo', 1, $url ) ); ?>"><?php asde_e( '+ Nuevo informe' ); ?></a>
	</header>
	<p><?php asde_e( 'Elige un informe o crea uno nuevo seleccionando las columnas que necesitas. Los datos se leen del CRM en el momento y solo incluyen socios con membresía activa' ); ?><?php echo current_user_can( 'asde_manage_users' ) ? '' : esc_html( asde_t( ' de tu sección' ) ); ?>.</p>

	<?php if ( ! $informes ) : ?>
		<p><?php asde_e( 'Todavía no hay informes guardados.' ); ?></p>
	<?php else : ?>
		<table class="asde-table">
			<thead>
				<tr>
					<th scope="col"><?php asde_e( 'Informe' ); ?></th>
					<th scope="col"><?php asde_e( 'Columnas' ); ?></th>
					<th scope="col"><?php asde_e( 'Tipo' ); ?></th>
					<th scope="col"><span class="screen-reader-text"><?php asde_e( 'Acciones' ); ?></span></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $informes as $i ) : ?>
					<tr>
						<td data-label="<?php echo esc_attr( asde_a( 'Informe' ) ); ?>">
							<a href="<?php echo esc_url( add_query_arg( 'informe', $i->id, $url ) ); ?>"><strong><?php echo esc_html( $i->titulo() ); ?></strong></a>
							<?php if ( $i->descripcion ) : ?>
								<br><span class="asde-muted"><?php echo esc_html( $i->resumen() ); ?></span>
							<?php endif; ?>
							<?php if ( Catalogo::incluye_salud( $i->columnas ) ) : ?>
								<br><span class="asde-badge asde-badge--salud"><?php asde_e( 'Incluye datos de salud' ); ?></span>
							<?php endif; ?>
						</td>
						<td data-label="<?php echo esc_attr( asde_a( 'Columnas' ) ); ?>"><?php echo (int) count( $i->columnas ); ?></td>
						<td data-label="<?php echo esc_attr( asde_a( 'Tipo' ) ); ?>">
							<?php
							if ( $i->sistema ) {
								echo esc_html( asde_t( 'Predefinido' ) );
							} elseif ( $i->autor_id === $user->ID ) {
								echo esc_html( $i->compartido ? asde_t( 'Mío · compartido' ) : asde_t( 'Mío' ) );
							} else {
								echo esc_html( asde_t( 'Compartido' ) );
							}
							?>
						</td>
						<td data-label="">
							<div class="asde-actions">
								<a href="<?php echo esc_url( add_query_arg( 'informe', $i->id, $url ) ); ?>"><?php asde_e( 'Ver' ); ?></a>
								<a href="<?php echo esc_url( add_query_arg( array( 'nuevo' => 1, 'desde' => $i->id ), $url ) ); ?>"><?php asde_e( 'Duplicar' ); ?></a>
								<?php if ( $i->puede_editar( $user ) ) : ?>
									<a href="<?php echo esc_url( add_query_arg( 'editar', $i->id, $url ) ); ?>"><?php asde_e( 'Editar' ); ?></a>
									<form method="post" data-asde-confirmar="<?php echo esc_attr( asde_js( '¿Borrar el informe «%s»?', $i->nombre ) ); ?>">
										<?php wp_nonce_field( 'asde_informe_borrar_' . $i->id ); ?>
										<input type="hidden" name="asde_action" value="borrar">
										<input type="hidden" name="id" value="<?php echo (int) $i->id; ?>">
										<button type="submit" class="asde-btn-link asde-btn-danger"><?php asde_e( 'Borrar' ); ?></button>
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
