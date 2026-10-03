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
<p class="asde-back"><a href="<?php echo esc_url( $url ); ?>"><?php asde_e( '← Volver al control de pagos' ); ?></a></p>

<section class="asde-card">
	<header class="asde-card__header asde-card__header--split">
		<h2 class="asde-card__title"><?php asde_e( 'Familia' ); ?> <?php echo esc_html( $familia['numero'] ); ?> · <?php echo esc_html( $ronda ); ?></h2>
		<span class="asde-badge asde-badge--pago-<?php echo esc_attr( $c['estado'] ); ?>"><?php echo esc_html( asde_t( Calculadora::ETIQUETAS[ $c['estado'] ] ) ); ?></span>
	</header>
	<ul class="asde-lista-miembros">
		<?php foreach ( $familia['miembros'] as $n ) : ?>
			<?php $p = $personas[ $n ] ?? null; ?>
			<li>
				<?php echo esc_html( $p ? $p->nombre_completo() : asde_t( 'Nº Scouts de España ' ) . $n ); ?><?php echo $p ? \AsdeBalears\AreaPrivada\Socios\Numeros::compacto( $p ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput ?>
				<?php
				if ( $p ) {
					foreach ( $p->secciones as $s ) {
						echo Secciones::badge( $s ); // phpcs:ignore WordPress.Security.EscapeOutput
					}
				}
				?>
				<?php if ( in_array( $n, $c['pagan'], true ) ) : ?>
					<span class="asde-muted"><?php asde_e( 'paga cuota' ); ?></span>
				<?php elseif ( in_array( $n, $c['no_pagan'], true ) ) : ?>
					<span class="asde-muted"><?php asde_e( 'no paga (scouter / voluntariado)' ); ?></span>
				<?php else : ?>
					<span class="asde-help asde-help--warning"><?php asde_e( 'sin membresía activa: no cuenta' ); ?></span>
				<?php endif; ?>
			</li>
		<?php endforeach; ?>
	</ul>
	<p class="asde-muted">
		<?php echo $cuenta ? esc_html( sprintf( asde_t( 'Cuenta familiar: %s' ), $cuenta->user_login ) ) . ' · ' : ''; ?>
		<a href="<?php echo esc_url( $url_editar ); ?>"><?php asde_e( 'Editar socios de la familia' ); ?></a> <?php asde_e( '· Referencia para la transferencia:' ); ?> <strong><?php echo esc_html( $familia['numero'] ); ?></strong>
	</p>
</section>

<section class="asde-card">
	<h3><?php asde_e( 'Cuota de la ronda' ); ?></h3>
	<table class="asde-table asde-table--resumen">
		<tbody>
			<tr><th scope="row"><?php asde_e( 'Inscripción (' ); ?><?php echo (int) count( $c['pagan'] ); ?> × <?php echo esc_html( Calculadora::euros( (float) $cfg['cuotas']['inscripcion'] ) ); ?>)</th><td><?php echo esc_html( Calculadora::euros( $c['cuota']['inscripcion'] ) ); ?></td></tr>
			<tr><th scope="row"><?php asde_e( 'Seguro (' ); ?><?php echo (int) count( $c['pagan'] ); ?> × <?php echo esc_html( Calculadora::euros( (float) $cfg['cuotas']['seguro'] ) ); ?>)</th><td><?php echo esc_html( Calculadora::euros( $c['cuota']['seguro'] ) ); ?></td></tr>
			<tr><th scope="row"><?php asde_e( 'Anualidad (' ); ?><?php echo (int) count( $c['pagan'] ); ?> <?php asde_e( 'hijo/s)' ); ?></th><td><?php echo esc_html( Calculadora::euros( $c['cuota']['anualidad'] ) ); ?></td></tr>
			<?php if ( $c['ajustes'] ) : ?>
				<tr><th scope="row"><?php asde_e( 'Ajustes' ); ?></th><td><?php echo esc_html( Calculadora::euros( $c['ajustes'] ) ); ?></td></tr>
			<?php endif; ?>
			<tr class="asde-total"><th scope="row"><?php asde_e( 'A pagar' ); ?></th><td><?php echo esc_html( Calculadora::euros( $c['a_pagar'] ) ); ?></td></tr>
			<tr><th scope="row"><?php asde_e( 'Pagado' ); ?></th><td><?php echo esc_html( Calculadora::euros( $c['pagado'] ) ); ?></td></tr>
			<tr class="asde-total"><th scope="row"><?php echo $c['pendiente'] < 0 ? asde_t( 'Saldo a favor' ) : asde_t( 'Pendiente' ); ?></th><td><?php echo esc_html( Calculadora::euros( abs( $c['pendiente'] ) ) ); ?></td></tr>
		</tbody>
	</table>
	<?php if ( count( $c['plazos'] ) > 1 || ! empty( $c['plazos'][0]['vencido'] ) ) : ?>
		<h4><?php asde_e( 'Plazos' ); ?></h4>
		<ul>
			<?php foreach ( $c['plazos'] as $pl ) : ?>
				<li>
					<?php echo esc_html( sprintf( asde_t( '%s: %s hasta el %s' ), $pl['nombre'], Calculadora::euros( $pl['importe'] ), wp_date( 'd/m/Y', strtotime( $pl['fecha'] . ' 12:00' ) ) ) ); ?>
					<?php echo $pl['cubierto'] ? '✅' : ( $pl['vencido'] ? '<span class="asde-help asde-help--warning">vencido</span>' : '' ); ?>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
</section>

<div class="asde-grid-2 asde-grid-2--top">
	<section class="asde-card">
		<h3><?php asde_e( 'Registrar pago' ); ?></h3>
		<form method="post" class="asde-form">
			<?php wp_nonce_field( 'asde_mov_' . $familia['id'] ); ?>
			<input type="hidden" name="asde_action" value="registrar_pago">
			<input type="hidden" name="familia" value="<?php echo (int) $familia['id']; ?>">
			<input type="hidden" name="ronda" value="<?php echo esc_attr( $ronda ); ?>">
			<p><label for="asde-pago-fecha"><?php asde_e( 'Fecha' ); ?></label><input type="date" id="asde-pago-fecha" name="fecha" value="<?php echo esc_attr( $hoy ); ?>" required></p>
			<p><label for="asde-pago-importe"><?php asde_e( 'Importe (€)' ); ?></label><input type="number" id="asde-pago-importe" name="importe" step="0.01" min="0.01" value="<?php echo esc_attr( $c['pendiente'] > 0 ? number_format( $c['pendiente'], 2, '.', '' ) : '' ); ?>" required></p>
			<p>
				<label for="asde-pago-metodo"><?php asde_e( 'Forma de pago' ); ?></label>
				<select id="asde-pago-metodo" name="metodo">
					<?php foreach ( asde_ta( MovimientosTable::METODOS ) as $k => $label ) : ?>
						<option value="<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</p>
			<p><label for="asde-pago-ref"><?php asde_e( 'Referencia' ); ?></label><input type="text" id="asde-pago-ref" name="referencia" value="<?php echo esc_attr( $familia['numero'] ); ?>" maxlength="120"><span class="asde-help"><?php asde_e( 'Concepto de la transferencia o nº de recibo. Sin datos personales.' ); ?></span></p>
			<p><label for="asde-pago-nota"><?php asde_e( 'Nota (opcional)' ); ?></label><input type="text" id="asde-pago-nota" name="nota" maxlength="255"></p>
			<p><button type="submit" class="button"><?php asde_e( 'Registrar pago' ); ?></button></p>
		</form>
	</section>

	<section class="asde-card">
		<h3><?php asde_e( 'Ajuste de cuota' ); ?></h3>
		<p class="asde-help"><?php asde_e( 'Descuentos, becas o recargos que cambian lo que debe pagar la familia en esta ronda.' ); ?></p>
		<form method="post" class="asde-form">
			<?php wp_nonce_field( 'asde_mov_' . $familia['id'] ); ?>
			<input type="hidden" name="asde_action" value="registrar_ajuste">
			<input type="hidden" name="familia" value="<?php echo (int) $familia['id']; ?>">
			<input type="hidden" name="ronda" value="<?php echo esc_attr( $ronda ); ?>">
			<input type="hidden" name="fecha" value="<?php echo esc_attr( $hoy ); ?>">
			<fieldset>
				<legend><?php asde_e( 'Tipo' ); ?></legend>
				<label class="asde-choice asde-choice--inline"><input type="radio" name="sentido" value="descuento" checked><span><?php asde_e( 'Descuento / beca' ); ?></span></label>
				<label class="asde-choice asde-choice--inline"><input type="radio" name="sentido" value="recargo"><span><?php asde_e( 'Recargo' ); ?></span></label>
			</fieldset>
			<p><label for="asde-aj-importe"><?php asde_e( 'Importe (€)' ); ?></label><input type="number" id="asde-aj-importe" name="importe" step="0.01" min="0.01" required></p>
			<p><label for="asde-aj-nota"><?php asde_e( 'Motivo' ); ?></label><input type="text" id="asde-aj-nota" name="nota" maxlength="255" required></p>
			<p><button type="submit" class="button asde-button-secundario"><?php asde_e( 'Registrar ajuste' ); ?></button></p>
		</form>
	</section>
</div>

<section class="asde-card">
	<h3><?php asde_e( 'Movimientos de la ronda' ); ?></h3>
	<?php if ( ! $movimientos ) : ?>
		<p><?php asde_e( 'Todavía no hay movimientos.' ); ?></p>
	<?php else : ?>
		<table class="asde-table">
			<thead>
				<tr>
					<th scope="col"><?php asde_e( 'Fecha' ); ?></th>
					<th scope="col"><?php asde_e( 'Tipo' ); ?></th>
					<th scope="col"><?php asde_e( 'Importe' ); ?></th>
					<th scope="col"><?php asde_e( 'Detalle' ); ?></th>
					<th scope="col"><?php asde_e( 'Registrado por' ); ?></th>
					<th scope="col"><span class="screen-reader-text"><?php asde_e( 'Acciones' ); ?></span></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $movimientos as $m ) : ?>
					<?php $anulado = ! empty( $m['anulado_en'] ); ?>
					<tr class="<?php echo $anulado ? 'asde-anulado' : ''; ?>">
						<td data-label="<?php echo asde_a( 'Fecha' ); ?>"><?php echo esc_html( wp_date( 'd/m/Y', strtotime( $m['fecha'] . ' 12:00' ) ) ); ?></td>
						<td data-label="<?php echo asde_a( 'Tipo' ); ?>"><?php echo esc_html( MovimientosTable::PAGO === $m['tipo'] ? asde_t( 'Pago' ) . ( $m['metodo'] ? ' · ' . ( asde_t( MovimientosTable::METODOS[ $m['metodo'] ] ?? $m['metodo'] ) ) : '' ) : asde_t( 'Ajuste' ) ); ?></td>
						<td data-label="<?php echo asde_a( 'Importe' ); ?>"><?php echo esc_html( Calculadora::euros( (float) $m['importe'] ) ); ?></td>
						<td data-label="<?php echo asde_a( 'Detalle' ); ?>"><?php echo esc_html( trim( $m['referencia'] . ( $m['referencia'] && $m['nota'] ? ' · ' : '' ) . $m['nota'] ) ); ?><?php echo $anulado ? '<br><span class="asde-help">' . esc_html( sprintf( asde_t( 'Anulado el %s por %s' ), wp_date( 'd/m/Y', strtotime( $m['anulado_en'] . ' UTC' ) ), $user( $m['anulado_por'] ) ) ) . '</span>' : ''; ?></td>
						<td data-label="<?php echo asde_a( 'Registrado por' ); ?>"><?php echo esc_html( $user( $m['registrado_por'] ) ); ?></td>
						<td data-label="">
							<?php if ( ! $anulado ) : ?>
								<form method="post" onsubmit="return confirm(<?php echo asde_js( '¿Anular este movimiento? Quedará registrado como anulado.' ); ?>);">
									<?php wp_nonce_field( 'asde_mov_' . $familia['id'] ); ?>
									<input type="hidden" name="asde_action" value="anular">
									<input type="hidden" name="familia" value="<?php echo (int) $familia['id']; ?>">
									<input type="hidden" name="ronda" value="<?php echo esc_attr( $ronda ); ?>">
									<input type="hidden" name="movimiento" value="<?php echo (int) $m['id']; ?>">
									<button type="submit" class="asde-btn-link asde-btn-danger"><?php asde_e( 'Anular' ); ?></button>
								</form>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</section>
