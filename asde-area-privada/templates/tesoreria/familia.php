<?php
/**
 * Tesorería · ficha de una familia en la ronda.
 *
 * @var array{id:int, numero:string, miembros:string[]} $familia
 * @var array<string, AsdeBalears\AreaPrivada\Domain\Persona> $personas
 * @var array    $calc
 * @var array    $cfg
 * @var string   $ronda
 * @var array[]  $movimientos
 * @var ?WP_User $cuenta
 * @var string   $url
 * @var string   $url_editar
 */
use AsdeBalears\AreaPrivada\Domain\Secciones;
use AsdeBalears\AreaPrivada\Tesoreria\Calculadora;
use AsdeBalears\AreaPrivada\Tesoreria\MovimientosTable;

defined( 'ABSPATH' ) || exit;

$c    = $calc;
$hoy  = wp_date( 'Y-m-d' );
$user = static fn( $id ) => ( $u = get_userdata( (int) $id ) ) ? $u->user_login : '—';
?>
<p class="asde-back"><a href="<?php echo esc_url( $url ); ?>">← Volver al control de pagos</a></p>

<section class="asde-card">
	<header class="asde-card__header asde-card__header--split">
		<h2 class="asde-card__title">Familia <?php echo esc_html( $familia['numero'] ); ?> · <?php echo esc_html( $ronda ); ?></h2>
		<span class="asde-badge asde-badge--pago-<?php echo esc_attr( $c['estado'] ); ?>"><?php echo esc_html( Calculadora::ETIQUETAS[ $c['estado'] ] ); ?></span>
	</header>
	<ul class="asde-lista-miembros">
		<?php foreach ( $familia['miembros'] as $n ) : ?>
			<?php $p = $personas[ $n ] ?? null; ?>
			<li>
				<?php echo esc_html( $p ? $p->nombre_completo() : 'Nº Scouts de España ' . $n ); ?><?php echo $p ? \AsdeBalears\AreaPrivada\Socios\Numeros::compacto( $p ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput ?>
				<?php
				if ( $p ) {
					foreach ( $p->secciones as $s ) {
						echo Secciones::badge( $s ); // phpcs:ignore WordPress.Security.EscapeOutput
					}
				}
				?>
				<?php if ( in_array( $n, $c['pagan'], true ) ) : ?>
					<span class="asde-muted">paga cuota</span>
				<?php elseif ( in_array( $n, $c['no_pagan'], true ) ) : ?>
					<span class="asde-muted">no paga (scouter / voluntariado)</span>
				<?php else : ?>
					<span class="asde-help asde-help--warning">sin membresía activa: no cuenta</span>
				<?php endif; ?>
			</li>
		<?php endforeach; ?>
	</ul>
	<p class="asde-muted">
		<?php echo $cuenta ? esc_html( 'Cuenta familiar: ' . $cuenta->user_login ) . ' · ' : ''; ?>
		<a href="<?php echo esc_url( $url_editar ); ?>">Editar socios de la familia</a> ·
		Referencia para la transferencia: <strong><?php echo esc_html( $familia['numero'] ); ?></strong>
	</p>
</section>

<section class="asde-card">
	<h3>Cuota de la ronda</h3>
	<table class="asde-table asde-table--resumen">
		<tbody>
			<tr><th scope="row">Inscripción (<?php echo (int) count( $c['pagan'] ); ?> × <?php echo esc_html( Calculadora::euros( (float) $cfg['cuotas']['inscripcion'] ) ); ?>)</th><td><?php echo esc_html( Calculadora::euros( $c['cuota']['inscripcion'] ) ); ?></td></tr>
			<tr><th scope="row">Seguro (<?php echo (int) count( $c['pagan'] ); ?> × <?php echo esc_html( Calculadora::euros( (float) $cfg['cuotas']['seguro'] ) ); ?>)</th><td><?php echo esc_html( Calculadora::euros( $c['cuota']['seguro'] ) ); ?></td></tr>
			<tr><th scope="row">Anualidad (<?php echo (int) count( $c['pagan'] ); ?> hijo/s)</th><td><?php echo esc_html( Calculadora::euros( $c['cuota']['anualidad'] ) ); ?></td></tr>
			<?php if ( $c['ajustes'] ) : ?>
				<tr><th scope="row">Ajustes</th><td><?php echo esc_html( Calculadora::euros( $c['ajustes'] ) ); ?></td></tr>
			<?php endif; ?>
			<tr class="asde-total"><th scope="row">A pagar</th><td><?php echo esc_html( Calculadora::euros( $c['a_pagar'] ) ); ?></td></tr>
			<tr><th scope="row">Pagado</th><td><?php echo esc_html( Calculadora::euros( $c['pagado'] ) ); ?></td></tr>
			<tr class="asde-total"><th scope="row"><?php echo $c['pendiente'] < 0 ? 'Saldo a favor' : 'Pendiente'; ?></th><td><?php echo esc_html( Calculadora::euros( abs( $c['pendiente'] ) ) ); ?></td></tr>
		</tbody>
	</table>
	<?php if ( count( $c['plazos'] ) > 1 || ! empty( $c['plazos'][0]['vencido'] ) ) : ?>
		<h4>Plazos</h4>
		<ul>
			<?php foreach ( $c['plazos'] as $pl ) : ?>
				<li>
					<?php echo esc_html( sprintf( '%s: %s hasta el %s', $pl['nombre'], Calculadora::euros( $pl['importe'] ), wp_date( 'd/m/Y', strtotime( $pl['fecha'] . ' 12:00' ) ) ) ); ?>
					<?php echo $pl['cubierto'] ? '✅' : ( $pl['vencido'] ? '<span class="asde-help asde-help--warning">vencido</span>' : '' ); ?>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
</section>

<div class="asde-grid-2 asde-grid-2--top">
	<section class="asde-card">
		<h3>Registrar pago</h3>
		<form method="post" class="asde-form">
			<?php wp_nonce_field( 'asde_mov_' . $familia['id'] ); ?>
			<input type="hidden" name="asde_action" value="registrar_pago">
			<input type="hidden" name="familia" value="<?php echo (int) $familia['id']; ?>">
			<input type="hidden" name="ronda" value="<?php echo esc_attr( $ronda ); ?>">
			<p><label for="asde-pago-fecha">Fecha</label><input type="date" id="asde-pago-fecha" name="fecha" value="<?php echo esc_attr( $hoy ); ?>" required></p>
			<p><label for="asde-pago-importe">Importe (€)</label><input type="number" id="asde-pago-importe" name="importe" step="0.01" min="0.01" value="<?php echo esc_attr( $c['pendiente'] > 0 ? number_format( $c['pendiente'], 2, '.', '' ) : '' ); ?>" required></p>
			<p>
				<label for="asde-pago-metodo">Forma de pago</label>
				<select id="asde-pago-metodo" name="metodo">
					<?php foreach ( MovimientosTable::METODOS as $k => $label ) : ?>
						<option value="<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</p>
			<p><label for="asde-pago-ref">Referencia</label><input type="text" id="asde-pago-ref" name="referencia" value="<?php echo esc_attr( $familia['numero'] ); ?>" maxlength="120"><span class="asde-help">Concepto de la transferencia o nº de recibo. Sin datos personales.</span></p>
			<p><label for="asde-pago-nota">Nota (opcional)</label><input type="text" id="asde-pago-nota" name="nota" maxlength="255"></p>
			<p><button type="submit" class="button">Registrar pago</button></p>
		</form>
	</section>

	<section class="asde-card">
		<h3>Ajuste de cuota</h3>
		<p class="asde-help">Descuentos, becas o recargos que cambian lo que debe pagar la familia en esta ronda.</p>
		<form method="post" class="asde-form">
			<?php wp_nonce_field( 'asde_mov_' . $familia['id'] ); ?>
			<input type="hidden" name="asde_action" value="registrar_ajuste">
			<input type="hidden" name="familia" value="<?php echo (int) $familia['id']; ?>">
			<input type="hidden" name="ronda" value="<?php echo esc_attr( $ronda ); ?>">
			<input type="hidden" name="fecha" value="<?php echo esc_attr( $hoy ); ?>">
			<fieldset>
				<legend>Tipo</legend>
				<label class="asde-choice asde-choice--inline"><input type="radio" name="sentido" value="descuento" checked><span>Descuento / beca</span></label>
				<label class="asde-choice asde-choice--inline"><input type="radio" name="sentido" value="recargo"><span>Recargo</span></label>
			</fieldset>
			<p><label for="asde-aj-importe">Importe (€)</label><input type="number" id="asde-aj-importe" name="importe" step="0.01" min="0.01" required></p>
			<p><label for="asde-aj-nota">Motivo</label><input type="text" id="asde-aj-nota" name="nota" maxlength="255" required></p>
			<p><button type="submit" class="button asde-button-secundario">Registrar ajuste</button></p>
		</form>
	</section>
</div>

<section class="asde-card">
	<h3>Movimientos de la ronda</h3>
	<?php if ( ! $movimientos ) : ?>
		<p>Todavía no hay movimientos.</p>
	<?php else : ?>
		<table class="asde-table">
			<thead>
				<tr>
					<th scope="col">Fecha</th>
					<th scope="col">Tipo</th>
					<th scope="col">Importe</th>
					<th scope="col">Detalle</th>
					<th scope="col">Registrado por</th>
					<th scope="col"><span class="screen-reader-text">Acciones</span></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $movimientos as $m ) : ?>
					<?php $anulado = ! empty( $m['anulado_en'] ); ?>
					<tr class="<?php echo $anulado ? 'asde-anulado' : ''; ?>">
						<td data-label="Fecha"><?php echo esc_html( wp_date( 'd/m/Y', strtotime( $m['fecha'] . ' 12:00' ) ) ); ?></td>
						<td data-label="Tipo"><?php echo esc_html( MovimientosTable::PAGO === $m['tipo'] ? 'Pago' . ( $m['metodo'] ? ' · ' . ( MovimientosTable::METODOS[ $m['metodo'] ] ?? $m['metodo'] ) : '' ) : 'Ajuste' ); ?></td>
						<td data-label="Importe"><?php echo esc_html( Calculadora::euros( (float) $m['importe'] ) ); ?></td>
						<td data-label="Detalle"><?php echo esc_html( trim( $m['referencia'] . ( $m['referencia'] && $m['nota'] ? ' · ' : '' ) . $m['nota'] ) ); ?><?php echo $anulado ? '<br><span class="asde-help">Anulado el ' . esc_html( wp_date( 'd/m/Y', strtotime( $m['anulado_en'] . ' UTC' ) ) . ' por ' . $user( $m['anulado_por'] ) ) . '</span>' : ''; ?></td>
						<td data-label="Registrado por"><?php echo esc_html( $user( $m['registrado_por'] ) ); ?></td>
						<td data-label="">
							<?php if ( ! $anulado ) : ?>
								<form method="post" onsubmit="return confirm('¿Anular este movimiento? Quedará registrado como anulado.');">
									<?php wp_nonce_field( 'asde_mov_' . $familia['id'] ); ?>
									<input type="hidden" name="asde_action" value="anular">
									<input type="hidden" name="familia" value="<?php echo (int) $familia['id']; ?>">
									<input type="hidden" name="ronda" value="<?php echo esc_attr( $ronda ); ?>">
									<input type="hidden" name="movimiento" value="<?php echo (int) $m['id']; ?>">
									<button type="submit" class="asde-btn-link asde-btn-danger">Anular</button>
								</form>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</section>
