<?php
/**
 * Tesorería · ficha de una familia en la ronda.
 *
 * @var array{id:int, numero:string, miembros:string[]} $familia
 * @var array<string, AsdeBalears\AreaPrivada\Domain\Persona> $personas
 * @var array    $calc
 * @var array    $cfg
 * @var string   $ronda
 * @var array[]  $movimientos  Con los eliminados (anulados)
 * @var array[]  $historial    Pagos::historial() de Tesorería (v0.25)
 * @var ?WP_User $cuenta
 * @var array|null $banco Para asociar un ingreso del banco (TesoreriaView::banco_para), o null si no ve el banco (v0.20.1)
 * @var string   $url
 * @var string   $url_editar
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Domain\Secciones;
use AsdeBalears\AreaPrivada\Tesoreria\Calculadora;
use AsdeBalears\AreaPrivada\Tesoreria\MovimientosTable;

defined( 'ABSPATH' ) || exit;

$c    = $calc;
$historial = $historial ?? array(); // Quien pinta la ficha sin él (p. ej. las pruebas) no tiene historial.
$hoy  = wp_date( 'Y-m-d' );
$user = static fn( $id ) => ( $u = get_userdata( (int) $id ) ) ? $u->user_login : '—';
?>
<p class="asde-back"><a href="<?php echo esc_url( $url ); ?>"><?php asde_e( '← Volver al control de pagos' ); ?></a></p>
<?php /* v0.25: la ficha entera es una zona que se actualiza sin recargar la página (assets/js/parcial.js): registrar, conciliar o eliminar un pago solo cambia esto. */ ?>
<div data-asde-parcial="familia">

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
	<?php /* v0.20: se puede plegar; plegado enseña lo esencial. El navegador recuerda cómo se dejó (area.js, data-asde-recordar). */ ?>
	<details class="asde-plegable" open data-asde-recordar="tesoreria-cuota-ronda">
	<summary>
		<h3><?php asde_e( 'Cuota de la ronda' ); ?></h3>
		<span class="asde-plegable__resumen asde-plegable__resumen--cerrado"><?php echo esc_html( sprintf( asde_t( 'A pagar %1$s · pagado %2$s · %3$s %4$s' ), Calculadora::euros( $c['a_pagar'] ), Calculadora::euros( $c['pagado'] ), $c['pendiente'] < 0 ? mb_strtolower( asde_t( 'Saldo a favor' ) ) : mb_strtolower( asde_t( 'Pendiente' ) ), Calculadora::euros( abs( $c['pendiente'] ) ) ) ); ?></span>
	</summary>
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
			<tr class="asde-total"><th scope="row"><?php echo $c['pendiente'] < 0 ? esc_html( asde_t( 'Saldo a favor' ) ) : esc_html( asde_t( 'Pendiente' ) ); ?></th><td><?php echo esc_html( Calculadora::euros( abs( $c['pendiente'] ) ) ); ?></td></tr>
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
	</details>
</section>

<div class="asde-grid-2 asde-grid-2--top">
	<section class="asde-card">
		<h3><?php asde_e( 'Registrar pago' ); ?></h3>
		<form method="post" class="asde-form">
			<?php wp_nonce_field( 'asde_mov_' . $familia['id'] ); ?>
			<input type="hidden" name="asde_action" value="registrar_pago">
			<input type="hidden" name="familia" value="<?php echo (int) $familia['id']; ?>">
			<input type="hidden" name="ronda" value="<?php echo esc_attr( $ronda ); ?>">
			<?php
			// Lo que se escribe a mano; con un ingreso del banco elegido, lo suyo (y no se puede cambiar).
			$a_mano = array(
				'fecha'   => $hoy,
				'importe' => $c['pendiente'] > 0 ? number_format( $c['pendiente'], 2, '.', '' ) : '',
				'ref'     => $familia['numero'],
			);
			$el     = $banco['elegido'] ?? null;
			$campos = $el ? array(
				'fecha'   => $el['fecha'],
				'importe' => number_format( $el['importe'], 2, '.', '' ),
				'ref'     => '' !== $el['documento'] ? $el['documento'] : $familia['numero'],
			) : $a_mano;
			?>
			<?php if ( $banco && ( $banco['pendientes'] || $el ) ) : ?>
				<div class="asde-pago-banco" data-asde-pago-banco data-fecha="<?php echo esc_attr( $a_mano['fecha'] ); ?>" data-importe="<?php echo esc_attr( $a_mano['importe'] ); ?>" data-ref="<?php echo esc_attr( $a_mano['ref'] ); ?>">
					<input type="hidden" name="banco" value="<?php echo $el ? (int) $el['id'] : ''; ?>" data-asde-pago-banco-id>
					<p class="asde-pago-banco__titulo"><?php asde_e( 'Ingreso del banco (opcional)' ); ?></p>
					<p class="asde-pago-banco__elegido" aria-live="polite">
						<span data-asde-pago-banco-elegido<?php echo $el ? '' : ' hidden'; ?>><?php echo $el ? esc_html( wp_date( 'd/m/Y', strtotime( $el['fecha'] . ' 12:00' ) ) . ' · +' . Calculadora::euros( $el['importe'] ) . ' · ' . mb_strimwidth( trim( $el['ordenante'] . ' ' . $el['concepto'] ), 0, 90, '…' ) ) : ''; ?></span>
						<span class="asde-muted" data-asde-pago-banco-ninguno<?php echo $el ? ' hidden' : ''; ?>><?php asde_e( 'Ninguno: escribo el pago a mano' ); ?></span>
					</p>
					<p class="asde-pago-banco__acciones">
						<a class="button asde-button-secundario" href="<?php echo esc_url( $banco['url'] ); ?>" data-asde-modal><?php asde_e( 'Buscar en el banco…' ); ?></a>
						<a class="asde-btn-link" href="<?php echo esc_url( $banco['url_quitar'] ); ?>" data-asde-pago-banco-quitar<?php echo $el ? '' : ' hidden'; ?>><?php asde_e( 'Quitar el ingreso' ); ?></a>
						<?php if ( $banco['pendientes'] ) : ?>
							<span class="asde-muted">
								<?php echo esc_html( sprintf( asde_n( '%d ingreso por conciliar', '%d ingresos por conciliar', $banco['pendientes'] ), $banco['pendientes'] ) ); ?>
								<?php echo $banco['sugeridos'] ? ' · <strong>' . esc_html( sprintf( asde_n( '%d sugerido para esta familia', '%d sugeridos para esta familia', $banco['sugeridos'] ), $banco['sugeridos'] ) ) . '</strong>' : ''; ?>
							</span>
						<?php endif; ?>
					</p>
					<p class="asde-help"><?php asde_e( 'Busca el ingreso por el nombre del tutor o del socio, el concepto o el importe. Si eliges uno, el pago se registra con su fecha y su importe y el movimiento queda conciliado con esta familia.' ); ?></p>
				</div>
			<?php endif; ?>
			<p><label for="asde-pago-fecha"><?php asde_e( 'Fecha' ); ?></label><input type="date" id="asde-pago-fecha" name="fecha" value="<?php echo esc_attr( $campos['fecha'] ); ?>" required<?php echo $el ? ' readonly' : ''; ?>></p>
			<p><label for="asde-pago-importe"><?php asde_e( 'Importe (€)' ); ?></label><input type="number" id="asde-pago-importe" name="importe" step="0.01" min="0.01" value="<?php echo esc_attr( $campos['importe'] ); ?>" required<?php echo $el ? ' readonly' : ''; ?>></p>
			<p>
				<label for="asde-pago-metodo"><?php asde_e( 'Forma de pago' ); ?></label>
				<select id="asde-pago-metodo" name="metodo"<?php echo $el ? ' disabled' : ''; ?>>
					<?php foreach ( asde_ta( MovimientosTable::METODOS ) as $k => $label ) : ?>
						<option value="<?php echo esc_attr( $k ); ?>"<?php selected( $el ? 'transferencia' : '', $k ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</p>
			<p><label for="asde-pago-ref"><?php asde_e( 'Referencia' ); ?></label><input type="text" id="asde-pago-ref" name="referencia" value="<?php echo esc_attr( $campos['ref'] ); ?>" maxlength="120"><span class="asde-help"><?php asde_e( 'Concepto de la transferencia o nº de recibo. Sin datos personales.' ); ?></span></p>
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
	<?php $vigentes = array_values( array_filter( $movimientos, static fn( $m ) => empty( $m['anulado_en'] ) ) ); ?>
	<?php if ( ! $vigentes ) : ?>
		<p><?php echo $movimientos ? esc_html( asde_t( 'No queda ningún movimiento: los eliminados están en el historial.' ) ) : esc_html( asde_t( 'Todavía no hay movimientos.' ) ); ?></p>
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
				<?php foreach ( $vigentes as $m ) : ?>
					<tr>
						<td data-label="<?php echo esc_attr( asde_a( 'Fecha' ) ); ?>"><?php echo esc_html( wp_date( 'd/m/Y', strtotime( $m['fecha'] . ' 12:00' ) ) ); ?></td>
						<td data-label="<?php echo esc_attr( asde_a( 'Tipo' ) ); ?>"><?php echo esc_html( MovimientosTable::PAGO === $m['tipo'] ? asde_t( 'Pago' ) . ( $m['metodo'] ? ' · ' . ( asde_t( MovimientosTable::METODOS[ $m['metodo'] ] ?? $m['metodo'] ) ) : '' ) : asde_t( 'Ajuste' ) ); ?></td>
						<td data-label="<?php echo esc_attr( asde_a( 'Importe' ) ); ?>"><?php echo esc_html( Calculadora::euros( (float) $m['importe'] ) ); ?></td>
						<td data-label="<?php echo esc_attr( asde_a( 'Detalle' ) ); ?>"><?php echo esc_html( trim( $m['referencia'] . ( $m['referencia'] && $m['nota'] ? ' · ' : '' ) . $m['nota'] ) ); ?></td>
						<td data-label="<?php echo esc_attr( asde_a( 'Registrado por' ) ); ?>"><?php echo esc_html( $user( $m['registrado_por'] ) ); ?></td>
						<td data-label="">
							<?php /* v0.25: «Eliminar» pide el motivo; el movimiento no se borra: pasa al historial. */ ?>
							<?php if ( \AsdeBalears\AreaPrivada\Tesoreria\Pagos::puede_eliminar() ) : // v0.29: solo Tesorería (P-94). ?>
							<details class="asde-eliminar-mov">
								<summary class="asde-btn-link asde-btn-danger"><?php asde_e( 'Eliminar…' ); ?></summary>
								<form method="post" class="asde-form asde-eliminar-mov__form">
									<?php wp_nonce_field( 'asde_mov_' . $familia['id'] ); ?>
									<input type="hidden" name="asde_action" value="eliminar">
									<input type="hidden" name="familia" value="<?php echo (int) $familia['id']; ?>">
									<input type="hidden" name="ronda" value="<?php echo esc_attr( $ronda ); ?>">
									<input type="hidden" name="movimiento" value="<?php echo (int) $m['id']; ?>">
									<label for="asde-eliminar-<?php echo (int) $m['id']; ?>"><?php asde_e( 'Motivo' ); ?></label>
									<input type="text" id="asde-eliminar-<?php echo (int) $m['id']; ?>" name="motivo" maxlength="255" required placeholder="<?php echo esc_attr( asde_a( 'Por ejemplo: registrado dos veces' ) ); ?>">
									<?php if ( 'banco' === $m['origen'] ) : ?>
										<span class="asde-help"><?php asde_e( 'Viene de un ingreso del banco: el ingreso volverá a estar por conciliar.' ); ?></span>
									<?php elseif ( 'sepa' === $m['origen'] ) : ?>
										<span class="asde-help asde-help--warning"><?php asde_e( 'Viene del cobro de una remesa SEPA: la remesa lo seguirá dando por cobrado.' ); ?></span>
									<?php endif; ?>
									<button type="submit" class="button asde-button-secundario"><?php echo MovimientosTable::PAGO === $m['tipo'] ? esc_html( asde_t( 'Eliminar el pago' ) ) : esc_html( asde_t( 'Eliminar el ajuste' ) ); ?></button>
								</form>
							</details>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</section>

<?php if ( $historial ) : ?>
	<section class="asde-card">
		<details class="asde-plegable" data-asde-recordar="tesoreria-historial">
			<summary>
				<h3><?php asde_e( 'Historial de la ronda' ); ?></h3>
				<span class="asde-plegable__resumen asde-muted">
					<?php
					$eliminados = count( array_filter( $historial, static fn( $h ) => 'eliminado' === $h['accion'] ) );
					/* translators: %d: anotaciones del historial */
					echo esc_html( sprintf( asde_n( '%d anotación', '%d anotaciones', count( $historial ) ), count( $historial ) ) );
					if ( $eliminados ) {
						/* translators: %d: movimientos eliminados */
						echo ' · ' . esc_html( sprintf( asde_n( '%d eliminado', '%d eliminados', $eliminados ), $eliminados ) );
					}
					?>
				</span>
			</summary>
			<p class="asde-help"><?php asde_e( 'Todo lo que se ha registrado y eliminado en la ficha de esta familia, con quién y cuándo. Lo eliminado no cuenta en la cuota.' ); ?></p>
			<table class="asde-table asde-historial">
				<thead>
					<tr>
						<th scope="col"><?php asde_e( 'Cuándo' ); ?></th>
						<th scope="col"><?php asde_e( 'Qué' ); ?></th>
						<th scope="col"><?php asde_e( 'Quién' ); ?></th>
						<th scope="col"><?php asde_e( 'Detalle' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $historial as $h ) : ?>
						<?php
						$m      = $h['mov'];
						$quien  = 'eliminado' === $h['accion'] ? $m['anulado_por'] : $m['registrado_por'];
						$origen = array(
							'banco' => asde_t( 'con un ingreso del banco' ),
							'sepa'  => asde_t( 'con el cobro de una remesa SEPA' ),
						)[ $m['origen'] ] ?? '';
						$que    = MovimientosTable::PAGO === $m['tipo']
							/* translators: %s: importe */
							? ( 'eliminado' === $h['accion'] ? sprintf( asde_t( 'Pago de %s eliminado' ), Calculadora::euros( (float) $m['importe'] ) ) : sprintf( asde_t( 'Pago de %s registrado' ), Calculadora::euros( (float) $m['importe'] ) ) )
							/* translators: %s: importe */
							: ( 'eliminado' === $h['accion'] ? sprintf( asde_t( 'Ajuste de %s eliminado' ), Calculadora::euros( (float) $m['importe'] ) ) : sprintf( asde_t( 'Ajuste de %s registrado' ), Calculadora::euros( (float) $m['importe'] ) ) );
						?>
						<tr class="<?php echo 'eliminado' === $h['accion'] ? 'asde-historial__eliminado' : ''; ?>">
							<td data-label="<?php echo esc_attr( asde_a( 'Cuándo' ) ); ?>"><?php echo esc_html( wp_date( 'd/m/Y H:i', strtotime( $h['cuando'] . ' UTC' ) ) ); ?></td>
							<td data-label="<?php echo esc_attr( asde_a( 'Qué' ) ); ?>"><strong><?php echo esc_html( $que ); ?></strong><?php echo 'registrado' === $h['accion'] && '' !== $origen ? ' <span class="asde-muted">' . esc_html( $origen ) . '</span>' : ''; ?></td>
							<td data-label="<?php echo esc_attr( asde_a( 'Quién' ) ); ?>"><?php echo esc_html( $user( $quien ) ); ?></td>
							<td data-label="<?php echo esc_attr( asde_a( 'Detalle' ) ); ?>">
								<?php if ( 'eliminado' === $h['accion'] ) : ?>
									<?php echo '' !== (string) ( $m['anulado_motivo'] ?? '' ) ? esc_html( sprintf( asde_t( 'Motivo: %s' ), $m['anulado_motivo'] ) ) : '—'; ?>
								<?php else : ?>
									<?php echo esc_html( wp_date( 'd/m/Y', strtotime( $m['fecha'] . ' 12:00' ) ) . ( '' !== trim( $m['referencia'] . $m['nota'] ) ? ' · ' . trim( $m['referencia'] . ( $m['referencia'] && $m['nota'] ? ' · ' : '' ) . $m['nota'] ) : '' ) ); ?>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</details>
	</section>
<?php endif; ?>
</div>
