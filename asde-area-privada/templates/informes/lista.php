<?php
/**
 * Informes guardados.
 *
 * @var AsdeBalears\AreaPrivada\Informes\Informe[] $informes
 * @var WP_User                                    $user
 * @var string                                     $url
 */
use AsdeBalears\AreaPrivada\Informes\Catalogo;

defined( 'ABSPATH' ) || exit;
?>
<section class="asde-card">
	<header class="asde-card__header asde-card__header--split">
		<h2 class="asde-card__title">Informes</h2>
		<a class="button" href="<?php echo esc_url( add_query_arg( 'nuevo', 1, $url ) ); ?>">+ Nuevo informe</a>
	</header>
	<p>Elige un informe o crea uno nuevo seleccionando las columnas que necesitas. Los datos se leen del CRM en el momento y solo incluyen socios con membresía activa<?php echo current_user_can( 'asde_manage_users' ) ? '' : ' de tu sección'; ?>.</p>

	<?php if ( ! $informes ) : ?>
		<p>Todavía no hay informes guardados.</p>
	<?php else : ?>
		<table class="asde-table">
			<thead>
				<tr>
					<th scope="col">Informe</th>
					<th scope="col">Columnas</th>
					<th scope="col">Tipo</th>
					<th scope="col"><span class="screen-reader-text">Acciones</span></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $informes as $i ) : ?>
					<tr>
						<td data-label="Informe">
							<a href="<?php echo esc_url( add_query_arg( 'informe', $i->id, $url ) ); ?>"><strong><?php echo esc_html( $i->nombre ); ?></strong></a>
							<?php if ( $i->descripcion ) : ?>
								<br><span class="asde-muted"><?php echo esc_html( $i->descripcion ); ?></span>
							<?php endif; ?>
							<?php if ( Catalogo::incluye_salud( $i->columnas ) ) : ?>
								<br><span class="asde-badge asde-badge--salud">Incluye datos de salud</span>
							<?php endif; ?>
						</td>
						<td data-label="Columnas"><?php echo (int) count( $i->columnas ); ?></td>
						<td data-label="Tipo">
							<?php
							if ( $i->sistema ) {
								echo 'Predefinido';
							} elseif ( $i->autor_id === $user->ID ) {
								echo $i->compartido ? 'Mío · compartido' : 'Mío';
							} else {
								echo 'Compartido';
							}
							?>
						</td>
						<td data-label="">
							<div class="asde-actions">
								<a href="<?php echo esc_url( add_query_arg( 'informe', $i->id, $url ) ); ?>">Ver</a>
								<a href="<?php echo esc_url( add_query_arg( array( 'nuevo' => 1, 'desde' => $i->id ), $url ) ); ?>">Duplicar</a>
								<?php if ( $i->puede_editar( $user ) ) : ?>
									<a href="<?php echo esc_url( add_query_arg( 'editar', $i->id, $url ) ); ?>">Editar</a>
									<form method="post" onsubmit="return confirm('¿Borrar el informe «<?php echo esc_js( $i->nombre ); ?>»?');">
										<?php wp_nonce_field( 'asde_informe_borrar_' . $i->id ); ?>
										<input type="hidden" name="asde_action" value="borrar">
										<input type="hidden" name="id" value="<?php echo (int) $i->id; ?>">
										<button type="submit" class="asde-btn-link asde-btn-danger">Borrar</button>
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
