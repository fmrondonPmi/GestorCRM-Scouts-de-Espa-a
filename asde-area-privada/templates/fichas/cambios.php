<?php
/**
 * Bandeja de cambios propuestos.
 *
 * @var array[]              $filas     Cambios (con 'persona' y, si están pendientes, 'actual')
 * @var string               $ver       pendientes | resueltos
 * @var array<string, array> $meta      Metadatos de los campos
 * @var bool                 $escritura
 * @var string               $url
 * @var string               $url_ficha
 */
use AsdeBalears\AreaPrivada\Crm\Escritura;
use AsdeBalears\AreaPrivada\Fichas\Cambios;
use AsdeBalears\AreaPrivada\Fichas\CamposFicha;
use AsdeBalears\AreaPrivada\Fichas\FichaService;

defined( 'ABSPATH' ) || exit;

$campos = CamposFicha::todos();
?>
<section class="asde-card">
	<header class="asde-card__header asde-card__header--split">
		<h2 class="asde-card__title"><?php asde_e( 'Cambios propuestos por las familias' ); ?></h2>
		<span class="asde-actions">
			<a href="<?php echo esc_url( $url ); ?>"<?php echo 'pendientes' === $ver ? ' aria-current="page"' : ''; ?>><?php asde_e( 'Pendientes' ); ?></a>
			<a href="<?php echo esc_url( add_query_arg( 'ver', 'resueltos', $url ) ); ?>"<?php echo 'resueltos' === $ver ? ' aria-current="page"' : ''; ?>><?php asde_e( 'Resueltos' ); ?></a>
		</span>
	</header>
	<p><?php asde_e( 'Al aprobar un cambio se guarda en el CRM. Si el dato del CRM ha cambiado desde que se propuso, se avisa antes de sobrescribirlo. Nadie puede aprobar sus propias propuestas.' ); ?></p>
	<?php if ( ! $escritura && 'pendientes' === $ver ) : ?>
		<div class="asde-notice asde-notice--warning" role="status"><?php asde_e( 'El usuario del CRM del grupo no puede editar fichas ahora mismo: los cambios no se pueden aprobar hasta que lo tenga.' ); ?></div>
	<?php endif; ?>

	<?php if ( ! $filas ) : ?>
		<p><?php echo 'pendientes' === $ver ? asde_t( 'No hay cambios pendientes de revisar. 🎉' ) : asde_t( 'Todavía no hay cambios resueltos.' ); ?></p>
	<?php else : ?>
		<table class="asde-table">
			<thead>
				<tr>
					<th scope="col"><?php asde_e( 'Propuesto' ); ?></th>
					<th scope="col"><?php asde_e( 'Persona' ); ?></th>
					<th scope="col"><?php asde_e( 'Dato' ); ?></th>
					<?php if ( 'pendientes' === $ver ) : ?>
						<th scope="col"><?php asde_e( 'Ahora en el CRM' ); ?></th>
					<?php endif; ?>
					<th scope="col"><?php asde_e( 'Nuevo valor' ); ?></th>
					<th scope="col"><?php echo 'pendientes' === $ver ? asde_t( 'Revisar' ) : asde_t( 'Resultado' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $filas as $c ) : ?>
					<?php
					$m         = $meta[ $c['campo'] ] ?? array();
					$autor     = get_userdata( (int) $c['propuesto_por'] );
					$conflicto = 'pendientes' === $ver && ! Escritura::iguales( $c['actual'], $c['anterior'] );
					?>
					<tr>
						<td data-label="<?php echo asde_a( 'Propuesto' ); ?>"><?php echo esc_html( wp_date( 'd/m/Y H:i', strtotime( $c['propuesto_en'] . ' UTC' ) ) ); ?><br><span class="asde-muted"><?php echo $autor ? esc_html( $autor->user_login ) : '—'; ?></span></td>
						<td data-label="<?php echo asde_a( 'Persona' ); ?>"><a href="<?php echo esc_url( add_query_arg( 'socio', $c['socio'], $url_ficha ) ); ?>" data-asde-modal><?php echo esc_html( $c['persona']->nombre_completo() ); ?></a><?php echo \AsdeBalears\AreaPrivada\Socios\Numeros::compacto( $c['persona'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
						<td data-label="<?php echo asde_a( 'Dato' ); ?>"><?php echo esc_html( $campos[ $c['campo'] ][0] ?? $c['campo'] ); ?></td>
						<?php if ( 'pendientes' === $ver ) : ?>
							<td data-label="<?php echo asde_a( 'Ahora en el CRM' ); ?>">
								<?php echo esc_html( FichaService::mostrar( $c['actual'], $m ) ); ?>
								<?php if ( $conflicto ) : ?>
									<br><span class="asde-help asde-help--warning"><?php asde_e( '⚠️ Ha cambiado desde la propuesta (entonces era «' ); ?><?php echo esc_html( FichaService::mostrar( $c['anterior'], $m ) ); ?>»).</span>
								<?php endif; ?>
							</td>
						<?php endif; ?>
						<td data-label="<?php echo asde_a( 'Nuevo valor' ); ?>"><strong><?php echo esc_html( FichaService::mostrar( $c['nuevo'], $m ) ); ?></strong></td>
						<td data-label="">
							<?php if ( 'pendientes' !== $ver ) : ?>
								<?php echo esc_html( asde_t( Cambios::ESTADOS[ $c['estado'] ] ?? $c['estado'] ) ); ?>
								<?php if ( '' !== $c['motivo'] ) : ?>
									<br><span class="asde-muted"><?php echo esc_html( $c['motivo'] ); ?></span>
								<?php endif; ?>
							<?php elseif ( (int) $c['propuesto_por'] === get_current_user_id() ) : ?>
								<span class="asde-muted"><?php asde_e( 'Propuesto por ti: lo revisa otra persona.' ); ?></span>
							<?php else : ?>
								<form method="post" class="asde-revisar">
									<?php wp_nonce_field( 'asde_revisar_cambio_' . (int) $c['id'] ); ?>
									<input type="hidden" name="cambio" value="<?php echo (int) $c['id']; ?>">
									<?php if ( $conflicto ) : ?>
										<label class="asde-choice"><input type="checkbox" name="forzar" value="1"><span><?php asde_e( 'Aplicar igualmente' ); ?></span></label>
									<?php endif; ?>
									<button type="submit" name="asde_action" value="aprobar" class="button" <?php disabled( ! $escritura ); ?>><?php asde_e( 'Aprobar' ); ?></button>
									<label class="screen-reader-text" for="asde-motivo-<?php echo (int) $c['id']; ?>"><?php asde_e( 'Motivo del rechazo' ); ?></label>
									<input type="text" id="asde-motivo-<?php echo (int) $c['id']; ?>" name="motivo" placeholder="<?php echo asde_a( 'Motivo (si lo rechazas)' ); ?>" maxlength="255">
									<button type="submit" name="asde_action" value="rechazar" class="asde-btn-link asde-btn-danger"><?php asde_e( 'Rechazar' ); ?></button>
								</form>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</section>
