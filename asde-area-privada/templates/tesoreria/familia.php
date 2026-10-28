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
 * @var array[]  $otros        HistorialPagos::otros(): pagos de eventos y uniformes de la familia (v0.37)
 * @var array[]  $eventos      HistorialPagos::eventos_para_cobrar(): eventos con inscripción abierta donde la familia debe algo (v0.37)
 * @var ?WP_User $cuenta
 * @var array|null $banco Para asociar un ingreso del banco (TesoreriaView::banco_para), o null si no ve el banco (v0.20.1)
 * @var string   $url
 * @var string   $url_editar
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Domain\Secciones;
use AsdeBalears\AreaPrivada\Tesoreria\Calculadora;
use AsdeBalears\AreaPrivada\Tesoreria\MovimientosTable;

defined( 'ABSPATH' ) || exit;

$c    = $calc;
$historial = $historial ?? array(); // Quien pinta la ficha sin él (p. ej. las pruebas) no tiene historial.
$otros     = $otros ?? array();
$eventos   = $eventos ?? array();
$hoy  = wp_date( 'Y-m-d' );
$user = static fn( $id ) => ( $u = get_userdata( (int) $id ) ) ? $u->user_login : '—';
?>
<p class="asde-back"><a href="<?php echo esc_url( $url ); ?>"><?php esc_html_e( '← Back to payment control', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a></p>
<?php /* v0.25: la ficha entera es una zona que se actualiza sin recargar la página (assets/js/parcial.js): registrar, conciliar o eliminar un pago solo cambia esto. */ ?>
<div data-asde-parcial="familia">

<section class="asde-card">
	<header class="asde-card__header asde-card__header--split">
		<h2 class="asde-card__title"><?php esc_html_e( 'Family', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <?php echo esc_html( $familia['numero'] ); ?> · <?php echo esc_html( $ronda ); ?></h2>
		<span class="asde-badge asde-badge--pago-<?php echo esc_attr( $c['estado'] ); ?>"><?php echo esc_html( ( Calculadora::etiquetas()[ $c['estado'] ] ) ); ?></span>
	</header>
	<ul class="asde-lista-miembros">
		<?php foreach ( $familia['miembros'] as $n ) : ?>
			<?php $p = $personas[ $n ] ?? null; ?>
			<li>
				<?php echo esc_html( $p ? $p->nombre_completo() : __( 'Scouts de España no. ', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) . $n ); ?><?php echo wp_kses( $p ? \AsdeBalears\AreaPrivada\Socios\Numeros::compacto( $p ) : '', asde_html_permitido() ); ?>
				<?php
				if ( $p ) {
					foreach ( $p->secciones as $s ) {
						echo wp_kses( Secciones::badge( $s ), asde_html_permitido() );
					}
				}
				?>
				<?php if ( in_array( $n, $c['pagan'], true ) ) : ?>
					<span class="asde-muted"><?php esc_html_e( 'pays a fee', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span>
				<?php elseif ( in_array( $n, $c['no_pagan'], true ) ) : ?>
					<span class="asde-muted"><?php esc_html_e( 'does not pay (scouter / volunteer)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span>
				<?php else : ?>
					<span class="asde-help asde-help--warning"><?php esc_html_e( 'no active membership: does not count', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span>
				<?php endif; ?>
			</li>
		<?php endforeach; ?>
	</ul>
	<p class="asde-muted">
		<?php echo $cuenta ? esc_html( sprintf( /* translators: %s: text */ __( 'Family account: %s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $cuenta->user_login ) ) . ' · ' : ''; ?>
		<a href="<?php echo esc_url( $url_editar ); ?>"><?php esc_html_e( 'Edit the family\'s members', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a> <?php esc_html_e( '· Reference for the transfer:', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <strong><?php echo esc_html( $familia['numero'] ); ?></strong>
	</p>
</section>

<section class="asde-card">
	<?php /* v0.20: se puede plegar; plegado enseña lo esencial. El navegador recuerda cómo se dejó (area.js, data-asde-recordar). */ ?>
	<details class="asde-plegable" open data-asde-recordar="tesoreria-cuota-ronda">
	<summary>
		<h3><?php esc_html_e( 'Round fee', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h3>
		<span class="asde-plegable__resumen asde-plegable__resumen--cerrado"><?php echo esc_html( sprintf( /* translators: 1: text, 2: text, 3: text, 4: text */ __( 'To pay %1$s · paid %2$s · %3$s %4$s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), Calculadora::euros( $c['a_pagar'] ), Calculadora::euros( $c['pagado'] ), $c['pendiente'] < 0 ? mb_strtolower( __( 'Credit balance', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) : mb_strtolower( __( 'Pending', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ), Calculadora::euros( abs( $c['pendiente'] ) ) ) ); ?></span>
	</summary>
	<table class="asde-table asde-table--resumen">
		<tbody>
			<tr><th scope="row"><?php esc_html_e( 'Registration (', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?><?php echo (int) count( $c['pagan'] ); ?> × <?php echo esc_html( Calculadora::euros( (float) $cfg['cuotas']['inscripcion'] ) ); ?>)</th><td><?php echo esc_html( Calculadora::euros( $c['cuota']['inscripcion'] ) ); ?></td></tr>
			<tr><th scope="row"><?php esc_html_e( 'Insurance (', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?><?php echo (int) count( $c['pagan'] ); ?> × <?php echo esc_html( Calculadora::euros( (float) $cfg['cuotas']['seguro'] ) ); ?>)</th><td><?php echo esc_html( Calculadora::euros( $c['cuota']['seguro'] ) ); ?></td></tr>
			<tr><th scope="row"><?php esc_html_e( 'Annual fee (', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?><?php echo (int) count( $c['pagan'] ); ?> <?php esc_html_e( 'child/ren)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th><td><?php echo esc_html( Calculadora::euros( $c['cuota']['anualidad'] ) ); ?></td></tr>
			<?php if ( $c['ajustes'] ) : ?>
				<tr><th scope="row"><?php esc_html_e( 'Settings', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th><td><?php echo esc_html( Calculadora::euros( $c['ajustes'] ) ); ?></td></tr>
			<?php endif; ?>
			<tr class="asde-total"><th scope="row"><?php esc_html_e( 'To pay', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th><td><?php echo esc_html( Calculadora::euros( $c['a_pagar'] ) ); ?></td></tr>
			<tr><th scope="row"><?php esc_html_e( 'Paid', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th><td><?php echo esc_html( Calculadora::euros( $c['pagado'] ) ); ?></td></tr>
			<tr class="asde-total"><th scope="row"><?php echo $c['pendiente'] < 0 ? esc_html( __( 'Credit balance', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) : esc_html( __( 'Pending', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?></th><td><?php echo esc_html( Calculadora::euros( abs( $c['pendiente'] ) ) ); ?></td></tr>
		</tbody>
	</table>
	<?php if ( count( $c['plazos'] ) > 1 || ! empty( $c['plazos'][0]['vencido'] ) ) : ?>
		<h4><?php esc_html_e( 'Instalments', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h4>
		<ul>
			<?php foreach ( $c['plazos'] as $pl ) : ?>
				<li>
					<?php echo esc_html( sprintf( /* translators: 1: text, 2: text, 3: text */ __( '%1$s: %2$s until %3$s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), asde_guardado( (string) $pl['nombre'] ), Calculadora::euros( $pl['importe'] ), wp_date( 'd/m/Y', strtotime( $pl['fecha'] . ' 12:00' ) ) ) ); ?>
					<?php echo $pl['cubierto'] ? '✅' : ( $pl['vencido'] ? '<span class="asde-help asde-help--warning">vencido</span>' : '' ); ?>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
	</details>
</section>

<div class="asde-grid-2 asde-grid-2--top">
	<section class="asde-card">
		<h3><?php esc_html_e( 'Record payment', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h3>
		<form method="post" class="asde-form">
			<?php wp_nonce_field( 'asde_mov_' . $familia['id'] ); ?>
			<input type="hidden" name="asde_action" value="registrar_pago">
			<input type="hidden" name="familia" value="<?php echo (int) $familia['id']; ?>">
			<input type="hidden" name="ronda" value="<?php echo esc_attr( $ronda ); ?>">
			<?php if ( $eventos ) : ?>
				<?php /* v0.37: el pago puede ser de la cuota o de un evento con la inscripción abierta. */ ?>
				<p>
					<label for="asde-pago-evento"><?php esc_html_e( 'Payment of', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
					<select id="asde-pago-evento" name="evento">
						<option value=""><?php echo esc_html( sprintf( /* translators: %s: text */ __( 'Round %s fee', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $ronda ) ); ?></option>
						<?php foreach ( $eventos as $ev ) : ?>
							<option value="<?php echo (int) $ev['evento']['id']; ?>"><?php echo esc_html( sprintf( /* translators: 1: text, 2: text */ __( 'Event "%1$s" (pending %2$s)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $ev['evento']['nombre'], Calculadora::euros( $ev['pendiente'] ) ) ); ?></option>
						<?php endforeach; ?>
					</select>
					<span class="asde-help"><?php esc_html_e( 'Only events with open sign-up in which this family has something pending appear. For an event: cash, transfer or Bizum, and no bank deposit.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span>
				</p>
			<?php endif; ?>
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
					<p class="asde-pago-banco__titulo"><?php esc_html_e( 'Bank deposit (optional)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
					<p class="asde-pago-banco__elegido" aria-live="polite">
						<span data-asde-pago-banco-elegido<?php echo $el ? '' : ' hidden'; ?>><?php echo $el ? esc_html( wp_date( 'd/m/Y', strtotime( $el['fecha'] . ' 12:00' ) ) . ' · +' . Calculadora::euros( $el['importe'] ) . ' · ' . mb_strimwidth( trim( $el['ordenante'] . ' ' . $el['concepto'] ), 0, 90, '…' ) ) : ''; ?></span>
						<span class="asde-muted" data-asde-pago-banco-ninguno<?php echo $el ? ' hidden' : ''; ?>><?php esc_html_e( 'None: I will write the payment by hand', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span>
					</p>
					<p class="asde-pago-banco__acciones">
						<a class="button asde-button-secundario" href="<?php echo esc_url( $banco['url'] ); ?>" data-asde-modal><?php esc_html_e( 'Search in the bank…', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a>
						<a class="asde-btn-link" href="<?php echo esc_url( $banco['url_quitar'] ); ?>" data-asde-pago-banco-quitar<?php echo $el ? '' : ' hidden'; ?>><?php esc_html_e( 'Remove the deposit', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a>
						<?php if ( $banco['pendientes'] ) : ?>
							<span class="asde-muted">
								<?php echo esc_html( sprintf( /* translators: %d: number */ _n( '%d deposit to reconcile', '%d deposits to reconcile', $banco['pendientes'] , 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $banco['pendientes'] ) ); ?>
								<?php echo $banco['sugeridos'] ? ' · <strong>' . esc_html( sprintf( /* translators: %d: number */ _n( '%d suggested for this family', '%d suggested for this family', $banco['sugeridos'] , 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $banco['sugeridos'] ) ) . '</strong>' : ''; ?>
							</span>
						<?php endif; ?>
					</p>
					<p class="asde-help"><?php esc_html_e( 'Search for the deposit by the guardian\'s or member\'s name, the description or the amount. If you choose one, the payment is recorded with its date and amount and the transaction is reconciled with this family.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
				</div>
			<?php endif; ?>
			<p><label for="asde-pago-fecha"><?php esc_html_e( 'Date', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label><input type="date" id="asde-pago-fecha" name="fecha" value="<?php echo esc_attr( $campos['fecha'] ); ?>" required<?php echo $el ? ' readonly' : ''; ?>></p>
			<p><label for="asde-pago-importe"><?php esc_html_e( 'Amount (€)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label><input type="number" id="asde-pago-importe" name="importe" step="0.01" min="0.01" value="<?php echo esc_attr( $campos['importe'] ); ?>" required<?php echo $el ? ' readonly' : ''; ?>></p>
			<p>
				<label for="asde-pago-metodo"><?php esc_html_e( 'Payment method', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
				<select id="asde-pago-metodo" name="metodo"<?php echo $el ? ' disabled' : ''; ?>>
					<?php foreach ( ( MovimientosTable::metodos() ) as $k => $label ) : ?>
						<option value="<?php echo esc_attr( $k ); ?>"<?php selected( $el ? 'transferencia' : '', $k ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</p>
			<p><label for="asde-pago-ref"><?php esc_html_e( 'Reference', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label><input type="text" id="asde-pago-ref" name="referencia" value="<?php echo esc_attr( $campos['ref'] ); ?>" maxlength="120"><span class="asde-help"><?php esc_html_e( 'Transfer description or receipt no. No personal data.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span></p>
			<p><label for="asde-pago-nota"><?php esc_html_e( 'Note (optional)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label><input type="text" id="asde-pago-nota" name="nota" maxlength="255"></p>
			<p><button type="submit" class="button"><?php esc_html_e( 'Record payment', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button></p>
		</form>
	</section>

	<section class="asde-card">
		<h3><?php esc_html_e( 'Fee adjustment', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h3>
		<p class="asde-help"><?php esc_html_e( 'Discounts, scholarships or surcharges that change what the family must pay this round.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
		<form method="post" class="asde-form">
			<?php wp_nonce_field( 'asde_mov_' . $familia['id'] ); ?>
			<input type="hidden" name="asde_action" value="registrar_ajuste">
			<input type="hidden" name="familia" value="<?php echo (int) $familia['id']; ?>">
			<input type="hidden" name="ronda" value="<?php echo esc_attr( $ronda ); ?>">
			<input type="hidden" name="fecha" value="<?php echo esc_attr( $hoy ); ?>">
			<p class="asde-form__fila asde-form__fila--una-linea">
				<label><?php esc_html_e( 'Type', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?>
					<select name="sentido">
						<option value="descuento" selected><?php esc_html_e( 'Discount / scholarship', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></option>
						<option value="recargo"><?php esc_html_e( 'Surcharge', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></option>
					</select>
				</label>
				<label for="asde-aj-importe"><?php esc_html_e( 'Amount (€)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <input type="number" id="asde-aj-importe" name="importe" step="0.01" min="0.01" size="8" required></label>
				<label for="asde-aj-nota"><?php esc_html_e( 'Reason', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <input type="text" id="asde-aj-nota" name="nota" maxlength="255" required></label>
				<button type="submit" class="button asde-button-secundario"><?php esc_html_e( 'Record adjustment', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
			</p>
		</form>
	</section>
</div>

<section class="asde-card">
	<h3><?php esc_html_e( 'Transactions of the round', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h3>
	<?php $vigentes = array_values( array_filter( $movimientos, static fn( $m ) => empty( $m['anulado_en'] ) ) ); ?>
	<?php if ( ! $vigentes ) : ?>
		<p><?php echo $movimientos ? esc_html( __( 'No transaction is left: the deleted ones are in the history.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) : esc_html( __( 'There are no transactions yet.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?></p>
	<?php else : ?>
		<table class="asde-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Date', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Type', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Amount', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Detail', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Recorded by', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
					<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $vigentes as $m ) : ?>
					<tr>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Date', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo esc_html( wp_date( 'd/m/Y', strtotime( $m['fecha'] . ' 12:00' ) ) ); ?></td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Type', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo esc_html( MovimientosTable::PAGO === $m['tipo'] ? __( 'Payment', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) . ( $m['metodo'] ? ' · ' . ( ( MovimientosTable::metodos()[ $m['metodo'] ] ?? $m['metodo'] ) ) : '' ) : __( 'Adjustment', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?></td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Amount', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo esc_html( Calculadora::euros( (float) $m['importe'] ) ); ?></td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Detail', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo esc_html( trim( $m['referencia'] . ( $m['referencia'] && $m['nota'] ? ' · ' : '' ) . $m['nota'] ) ); ?></td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Recorded by', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo esc_html( $user( $m['registrado_por'] ) ); ?></td>
						<td data-label="">
							<?php /* v0.25: «Eliminar» pide el motivo; el movimiento no se borra: pasa al historial. */ ?>
							<?php if ( \AsdeBalears\AreaPrivada\Tesoreria\Pagos::puede_eliminar() ) : // v0.29: solo Tesorería (P-94). ?>
							<details class="asde-eliminar-mov">
								<summary class="asde-btn-link asde-btn-danger"><?php esc_html_e( 'Delete…', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></summary>
								<form method="post" class="asde-form asde-eliminar-mov__form">
									<?php wp_nonce_field( 'asde_mov_' . $familia['id'] ); ?>
									<input type="hidden" name="asde_action" value="eliminar">
									<input type="hidden" name="familia" value="<?php echo (int) $familia['id']; ?>">
									<input type="hidden" name="ronda" value="<?php echo esc_attr( $ronda ); ?>">
									<input type="hidden" name="movimiento" value="<?php echo (int) $m['id']; ?>">
									<label for="asde-eliminar-<?php echo (int) $m['id']; ?>"><?php esc_html_e( 'Reason', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
									<input type="text" id="asde-eliminar-<?php echo (int) $m['id']; ?>" name="motivo" maxlength="255" required placeholder="<?php echo esc_attr( esc_attr__( 'For example: recorded twice', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
									<?php if ( 'banco' === $m['origen'] ) : ?>
										<span class="asde-help"><?php esc_html_e( 'It comes from a bank deposit: the deposit will be pending reconciliation again.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span>
									<?php elseif ( 'sepa' === $m['origen'] ) : ?>
										<span class="asde-help asde-help--warning"><?php esc_html_e( 'It comes from the collection of a SEPA batch: the batch will still consider it collected.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span>
									<?php endif; ?>
									<button type="submit" class="button asde-button-secundario"><?php echo MovimientosTable::PAGO === $m['tipo'] ? esc_html( __( 'Delete the payment', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) : esc_html( __( 'Delete the adjustment', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?></button>
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

<?php
/*
 * v0.37: el historial junta TODO lo que la familia ha pagado: la cuota de la ronda (con lo eliminado) y
 * los pagos de eventos y de uniformes (HistorialPagos::otros), del más reciente al más antiguo.
 */
$lineas = array();
foreach ( $historial as $h ) {
	$m      = $h['mov'];
	$elim   = 'eliminado' === $h['accion'];
	$origen = array(
		'banco' => __( 'with a bank deposit', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
		'sepa'  => __( 'with the collection of a SEPA batch', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
	)[ $m['origen'] ] ?? '';
	$euros  = Calculadora::euros( (float) $m['importe'] );
	if ( MovimientosTable::PAGO === $m['tipo'] ) {
		/* translators: %s: importe */
		$que = $elim ? sprintf( /* translators: %s: text */ __( 'Payment of %s deleted', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $euros ) : sprintf( /* translators: %s: text */ __( 'Payment of %s recorded', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $euros );
	} else {
		/* translators: %s: importe */
		$que = $elim ? sprintf( /* translators: %s: text */ __( 'Adjustment of %s removed', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $euros ) : sprintf( /* translators: %s: text */ __( 'Adjustment of %s recorded', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $euros );
	}
	$lineas[] = array(
		'cuando'   => $h['cuando'],
		'eliminado' => $elim,
		'que'      => $que,
		'extra'    => $elim ? '' : $origen,
		'quien'    => (int) ( $elim ? $m['anulado_por'] : $m['registrado_por'] ),
		'detalle'  => $elim
			? ( '' !== (string) ( $m['anulado_motivo'] ?? '' ) ? sprintf( /* translators: %s: text */ __( 'Reason: %s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $m['anulado_motivo'] ) : '—' )
			: wp_date( 'd/m/Y', strtotime( $m['fecha'] . ' 12:00' ) ) . ( '' !== trim( $m['referencia'] . $m['nota'] ) ? ' · ' . trim( $m['referencia'] . ( $m['referencia'] && $m['nota'] ? ' · ' : '' ) . $m['nota'] ) : '' ),
	);
}
foreach ( $otros as $o ) {
	$lineas[] = array(
		'cuando'    => $o['cuando'],
		'eliminado' => 'eliminado' === $o['accion'],
		'que'       => $o['que'] . ( 'eliminado' === $o['accion'] ? ' · ' . __( 'cancelled', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) : '' ),
		'extra'     => 'evento' === $o['modulo'] ? __( 'event', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) : ( 'banco' === $o['modulo'] ? __( 'bank', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) : __( 'shop', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ),
		'quien'     => (int) $o['quien'],
		'detalle'   => '' !== $o['detalle'] ? $o['detalle'] : '—',
	);
}
// Lo más reciente primero; en el mismo segundo, lo eliminado antes que lo registrado.
usort( $lineas, static fn( $a, $b ) => strcmp( $b['cuando'], $a['cuando'] ) ?: ( (int) $b['eliminado'] <=> (int) $a['eliminado'] ) );
?>
<?php if ( $lineas ) : ?>
	<section class="asde-card">
		<details class="asde-plegable" data-asde-recordar="tesoreria-historial">
			<summary>
				<h3><?php esc_html_e( 'Payment history', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h3>
				<span class="asde-plegable__resumen asde-muted">
					<?php
					$eliminados = count( array_filter( $lineas, static fn( $h ) => $h['eliminado'] ) );
					/* translators: %d: anotaciones del historial */
					echo esc_html( sprintf( /* translators: %d: number */ _n( '%d entry', '%d entries', count( $lineas ) , 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), count( $lineas ) ) );
					if ( $eliminados ) {
						/* translators: %d: movimientos eliminados */
						echo ' · ' . esc_html( sprintf( /* translators: %d: number */ _n( '%d deleted', '%d deleted', $eliminados , 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $eliminados ) );
					}
					?>
				</span>
			</summary>
			<p class="asde-help"><?php esc_html_e( 'Everything this family has paid: the round\'s fee, events and uniforms, with who recorded it and when. Deleted items do not count.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
			<table class="asde-table asde-historial">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'When', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
						<th scope="col"><?php esc_html_e( 'What', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Who', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Detail', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $lineas as $h ) : ?>
						<tr class="<?php echo $h['eliminado'] ? 'asde-historial__eliminado' : ''; ?>">
							<td data-label="<?php echo esc_attr( esc_attr__( 'When', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo esc_html( wp_date( 'd/m/Y H:i', strtotime( $h['cuando'] . ' UTC' ) ) ); ?></td>
							<td data-label="<?php echo esc_attr( esc_attr__( 'What', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><strong><?php echo esc_html( $h['que'] ); ?></strong><?php echo '' !== $h['extra'] ? ' <span class="asde-muted">' . esc_html( $h['extra'] ) . '</span>' : ''; ?></td>
							<td data-label="<?php echo esc_attr( esc_attr__( 'Who', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo esc_html( $user( $h['quien'] ) ); ?></td>
							<td data-label="<?php echo esc_attr( esc_attr__( 'Detail', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo esc_html( $h['detalle'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</details>
	</section>
<?php endif; ?>
</div>
