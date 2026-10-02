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
		<h2 class="asde-card__title">Cambios propuestos por las familias</h2>
		<span class="asde-actions">
			<a href="<?php echo esc_url( $url ); ?>"<?php echo 'pendientes' === $ver ? ' aria-current="page"' : ''; ?>>Pendientes</a>
			<a href="<?php echo esc_url( add_query_arg( 'ver', 'resueltos', $url ) ); ?>"<?php echo 'resueltos' === $ver ? ' aria-current="page"' : ''; ?>>Resueltos</a>
		</span>
	</header>
	<p>Al aprobar un cambio se guarda en el CRM. Si el dato del CRM ha cambiado desde que se propuso, se avisa antes de sobrescribirlo. Nadie puede aprobar sus propias propuestas.</p>
	<?php if ( ! $escritura && 'pendientes' === $ver ) : ?>
		<div class="asde-notice asde-notice--warning" role="status">El usuario del CRM del grupo no puede editar fichas ahora mismo: los cambios no se pueden aprobar hasta que lo tenga.</div>
	<?php endif; ?>

	<?php if ( ! $filas ) : ?>
		<p><?php echo 'pendientes' === $ver ? 'No hay cambios pendientes de revisar. 🎉' : 'Todavía no hay cambios resueltos.'; ?></p>
	<?php else : ?>
		<table class="asde-table">
			<thead>
				<tr>
					<th scope="col">Propuesto</th>
					<th scope="col">Persona</th>
					<th scope="col">Dato</th>
					<?php if ( 'pendientes' === $ver ) : ?>
						<th scope="col">Ahora en el CRM</th>
					<?php endif; ?>
					<th scope="col">Nuevo valor</th>
					<th scope="col"><?php echo 'pendientes' === $ver ? 'Revisar' : 'Resultado'; ?></th>
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
						<td data-label="Propuesto"><?php echo esc_html( wp_date( 'd/m/Y H:i', strtotime( $c['propuesto_en'] . ' UTC' ) ) ); ?><br><span class="asde-muted"><?php echo $autor ? esc_html( $autor->user_login ) : '—'; ?></span></td>
						<td data-label="Persona"><a href="<?php echo esc_url( add_query_arg( 'socio', $c['socio'], $url_ficha ) ); ?>" data-asde-modal><?php echo esc_html( $c['persona']->nombre_completo() ); ?></a><?php echo \AsdeBalears\AreaPrivada\Socios\Numeros::compacto( $c['persona'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
						<td data-label="Dato"><?php echo esc_html( $campos[ $c['campo'] ][0] ?? $c['campo'] ); ?></td>
						<?php if ( 'pendientes' === $ver ) : ?>
							<td data-label="Ahora en el CRM">
								<?php echo esc_html( FichaService::mostrar( $c['actual'], $m ) ); ?>
								<?php if ( $conflicto ) : ?>
									<br><span class="asde-help asde-help--warning">⚠️ Ha cambiado desde la propuesta (entonces era «<?php echo esc_html( FichaService::mostrar( $c['anterior'], $m ) ); ?>»).</span>
								<?php endif; ?>
							</td>
						<?php endif; ?>
						<td data-label="Nuevo valor"><strong><?php echo esc_html( FichaService::mostrar( $c['nuevo'], $m ) ); ?></strong></td>
						<td data-label="">
							<?php if ( 'pendientes' !== $ver ) : ?>
								<?php echo esc_html( Cambios::ESTADOS[ $c['estado'] ] ?? $c['estado'] ); ?>
								<?php if ( '' !== $c['motivo'] ) : ?>
									<br><span class="asde-muted"><?php echo esc_html( $c['motivo'] ); ?></span>
								<?php endif; ?>
							<?php elseif ( (int) $c['propuesto_por'] === get_current_user_id() ) : ?>
								<span class="asde-muted">Propuesto por ti: lo revisa otra persona.</span>
							<?php else : ?>
								<form method="post" class="asde-revisar">
									<?php wp_nonce_field( 'asde_revisar_cambio_' . (int) $c['id'] ); ?>
									<input type="hidden" name="cambio" value="<?php echo (int) $c['id']; ?>">
									<?php if ( $conflicto ) : ?>
										<label class="asde-choice"><input type="checkbox" name="forzar" value="1"><span>Aplicar igualmente</span></label>
									<?php endif; ?>
									<button type="submit" name="asde_action" value="aprobar" class="button" <?php disabled( ! $escritura ); ?>>Aprobar</button>
									<label class="screen-reader-text" for="asde-motivo-<?php echo (int) $c['id']; ?>">Motivo del rechazo</label>
									<input type="text" id="asde-motivo-<?php echo (int) $c['id']; ?>" name="motivo" placeholder="Motivo (si lo rechazas)" maxlength="255">
									<button type="submit" name="asde_action" value="rechazar" class="asde-btn-link asde-btn-danger">Rechazar</button>
								</form>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</section>
