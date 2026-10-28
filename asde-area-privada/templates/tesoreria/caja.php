<?php
/**
 * Tesorería → Caja (v0.59, doc 61): saldo y apuntes de una caja de efectivo en un periodo, y los formularios para
 * apuntar, traspasar, contar (arqueo) y poner el saldo inicial.
 *
 * @var string                $caja
 * @var array<string, string> $cajas     Las que ve (clave => nombre)
 * @var array                 $periodo   Periodo::de()
 * @var array<string, string> $periodos  Periodo::opciones()
 * @var array                 $e         Servicio::extracto()
 * @var array<int, array>     $tipos     Tipos de gasto e ingreso
 * @var array<string, string> $hacia     Las otras cajas (para un traspaso)
 * @var bool                  $con_banco Lleva todas las cajas: traspasos con el banco y asociarlos
 * @var array<int, array>     $bancos    Movimientos del banco asociados a los traspasos de la lista
 * @var string                $hoy
 * @var float                 $saldo_hoy
 * @var string                $url
 * @var string                $aqui      Esta caja y este periodo
 * @var string                $url_excel
 * @var callable              $enlace    Dirección del módulo de un apunte automático
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Caja\CajaTable;
use AsdeBalears\AreaPrivada\Caja\LibroMayor;
use AsdeBalears\AreaPrivada\Tesoreria\Banco\TiposMovimiento;
use AsdeBalears\AreaPrivada\Tesoreria\Calculadora;

defined( 'ABSPATH' ) || exit;

$fecha  = static fn( string $f ) => wp_date( 'd/m/Y', strtotime( $f . ' 12:00' ) );
$euros  = static fn( float $v ) => Calculadora::euros( $v );
$ocultos = static function () use ( $caja, $periodo ) {
	wp_nonce_field( 'asde_caja' );
	echo '<input type="hidden" name="caja" value="' . esc_attr( $caja ) . '"><input type="hidden" name="periodo" value="' . esc_attr( $periodo['clave'] ) . '">';
};
$grupos_tipo = array();
foreach ( $tipos as $t ) {
	$grupos_tipo[ $t['naturaleza'] ][] = $t;
}
?>
<section class="asde-card" data-asde-no-plegable>
	<h2><?php echo esc_html( $cajas[ $caja ] ); ?></h2>
	<form method="get" action="<?php echo esc_url( $url ); ?>" class="asde-filter asde-filter--una-linea">
		<?php if ( count( $cajas ) > 1 ) : ?>
			<label for="asde-caja-caja" class="screen-reader-text"><?php esc_html_e( 'Cash box', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
			<select id="asde-caja-caja" name="caja" data-asde-autoenviar>
				<?php foreach ( $cajas as $k => $nombre ) : ?>
					<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $caja, $k ); ?>><?php echo esc_html( $nombre ); ?></option>
				<?php endforeach; ?>
			</select>
		<?php else : ?>
			<input type="hidden" name="caja" value="<?php echo esc_attr( $caja ); ?>">
		<?php endif; ?>
		<label for="asde-caja-periodo" class="screen-reader-text"><?php esc_html_e( 'Period', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
		<select id="asde-caja-periodo" name="periodo" data-asde-autoenviar>
			<?php foreach ( $periodos as $k => $etiqueta ) : ?>
				<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $periodo['clave'], $k ); ?>><?php echo esc_html( $etiqueta ); ?></option>
			<?php endforeach; ?>
		</select>
		<button type="submit" class="button"><?php esc_html_e( 'View', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
		<a class="button asde-button-secundario no-transition" href="<?php echo esc_url( $url_excel ); ?>" data-asde-descarga><?php esc_html_e( 'Download Excel', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a>
	</form>
	<dl class="asde-datos">
		<div><dt><?php esc_html_e( 'At the start', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></dt><dd><?php echo esc_html( $euros( $e['inicial'] ) ); ?></dd></div>
		<div><dt><?php esc_html_e( 'Inflows', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></dt><dd class="asde-importe--ingreso">+<?php echo esc_html( $euros( $e['entradas'] ) ); ?></dd></div>
		<div><dt><?php esc_html_e( 'Outflows', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></dt><dd class="asde-importe--gasto">−<?php echo esc_html( $euros( $e['salidas'] ) ); ?></dd></div>
		<div><dt><?php esc_html_e( 'At the end', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></dt><dd><strong><?php echo esc_html( $euros( $e['final'] ) ); ?></strong></dd></div>
		<div><dt><?php esc_html_e( 'Last cash count', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></dt><dd><?php echo $e['arqueo'] ? esc_html( $fecha( $e['arqueo']['fecha'] ) . ' · ' . ( abs( $e['arqueo']['importe'] ) < 0.01 ? __( 'balanced', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) : sprintf( /* translators: %s: text */ __( 'difference of %s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $euros( $e['arqueo']['importe'] ) ) ) ) : '—'; ?></dd></div>
	</dl>
	<?php if ( ! $e['saldo_inicial'] ) : ?>
		<div class="asde-notice asde-notice--warning" role="status"><?php esc_html_e( 'This cash box has no opening balance yet: enter what it held on the day you start keeping it here. Anything before that date does not count.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></div>
		<form method="post" action="<?php echo esc_url( $url ); ?>" class="asde-form asde-form__fila asde-form__fila--una-linea asde-caja-form">
			<?php $ocultos(); ?>
			<input type="hidden" name="asde_action" value="inicial">
			<label for="asde-caja-ini-fecha"><?php esc_html_e( 'Opening balance on', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
			<input type="date" id="asde-caja-ini-fecha" name="fecha" value="<?php echo esc_attr( $hoy ); ?>" max="<?php echo esc_attr( $hoy ); ?>" required>
			<label for="asde-caja-ini-importe" class="screen-reader-text"><?php esc_html_e( 'Amount (€)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
			<input type="text" id="asde-caja-ini-importe" name="importe" inputmode="decimal" placeholder="<?php echo esc_attr( esc_attr__( 'Amount (€)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>" required>
			<button type="submit" class="button"><?php esc_html_e( 'Save', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
		</form>
	<?php endif; ?>
</section>

<section class="asde-card" data-asde-plegable="caja-apuntes" data-asde-inicio="abierto">
	<h3><?php esc_html_e( 'Entries', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> · <?php echo esc_html( $periodo['etiqueta'] ); ?></h3>
	<?php if ( ! $e['filas'] ) : ?>
		<p><?php esc_html_e( 'There are no entries in this period.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
	<?php else : ?>
		<table class="asde-table asde-table--caja">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Date', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Description', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Item', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Amount', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Balance', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
					<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $e['filas'] as $a ) : ?>
					<tr>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Date', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo esc_html( $fecha( $a['fecha'] ) ); ?></td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Description', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
							<?php echo esc_html( LibroMayor::concepto_caja( $a ) ); ?>
							<?php if ( $a['propio'] && CajaTable::MANUAL === $a['clase'] && '' !== $a['nota'] ) : ?>
								<br><span class="asde-muted"><?php echo esc_html( $a['nota'] ); ?></span>
							<?php endif; ?>
							<?php if ( CajaTable::TRASPASO === $a['clase'] && CajaTable::BANCO === $a['destino'] ) : ?>
								<br><span class="asde-banco-asociado">
									<?php if ( $a['banco_id'] && isset( $bancos[ $a['banco_id'] ] ) ) : ?>
										↔ <?php esc_html_e( 'Bank:', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <?php echo esc_html( $fecha( $bancos[ $a['banco_id'] ]['fecha'] ) . ' · ' . $euros( (float) $bancos[ $a['banco_id'] ]['importe'] ) ); ?>
									<?php else : ?>
										<?php esc_html_e( 'Not linked to the bank', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?>
									<?php endif; ?>
								</span>
							<?php endif; ?>
						</td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Item', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo CajaTable::INICIAL === $a['clase'] ? '—' : esc_html( LibroMayor::partida_caja( $a, $tipos ) ); ?></td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Amount', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>" class="asde-importe <?php echo $a['importe'] >= 0 ? 'asde-importe--ingreso' : 'asde-importe--gasto'; ?>"><?php echo esc_html( ( $a['importe'] > 0 ? '+' : '' ) . $euros( $a['importe'] ) ); ?></td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Balance', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo esc_html( $euros( $a['saldo'] ) ); ?></td>
						<td data-label="">
							<div class="asde-actions">
								<?php if ( ! $a['propio'] ) : ?>
									<?php $destino = $enlace( $a ); ?>
									<?php if ( '' !== $destino ) : ?>
										<a href="<?php echo esc_url( $destino ); ?>"><?php esc_html_e( 'View in place', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a>
									<?php endif; ?>
								<?php else : ?>
									<?php if ( $con_banco && CajaTable::TRASPASO === $a['clase'] && CajaTable::BANCO === $a['destino'] ) : ?>
										<?php if ( $a['banco_id'] ) : ?>
											<form method="post" action="<?php echo esc_url( $url ); ?>">
												<?php $ocultos(); ?>
												<input type="hidden" name="asde_action" value="banco">
												<input type="hidden" name="apunte" value="<?php echo (int) $a['id']; ?>">
												<input type="hidden" name="banco" value="0">
												<button type="submit" class="asde-btn-link"><?php esc_html_e( 'Remove the link', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
											</form>
										<?php else : ?>
											<a href="<?php echo esc_url( add_query_arg( 'banco', (int) $a['id'], $url ) ); ?>" data-asde-modal><?php esc_html_e( 'Link with the bank…', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a>
										<?php endif; ?>
									<?php endif; ?>
									<details class="asde-caja-anular">
										<summary><?php esc_html_e( 'Cancel…', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></summary>
										<form method="post" action="<?php echo esc_url( $url ); ?>" class="asde-form__fila asde-form__fila--una-linea">
											<?php $ocultos(); ?>
											<input type="hidden" name="asde_action" value="anular">
											<input type="hidden" name="apunte" value="<?php echo (int) $a['id']; ?>">
											<label class="screen-reader-text" for="asde-caja-anular-<?php echo (int) $a['id']; ?>"><?php esc_html_e( 'Reason', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
											<input type="text" id="asde-caja-anular-<?php echo (int) $a['id']; ?>" name="motivo" maxlength="255" placeholder="<?php echo esc_attr( esc_attr__( 'Reason', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>" required>
											<button type="submit" class="button"><?php esc_html_e( 'Cancel', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
										</form>
									</details>
								<?php endif; ?>
							</div>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</section>

<section class="asde-card" data-asde-plegable="caja-apuntar" data-asde-inicio="abierto">
	<h3><?php esc_html_e( 'Record an inflow or an outflow', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h3>
	<p class="asde-help"><?php esc_html_e( 'Only what is not elsewhere (stamps, a donation in hand…). Cash collections and payments of fees, events, shop and reimbursements go into the group\'s cash box by themselves.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
	<form method="post" action="<?php echo esc_url( $url ); ?>" class="asde-form asde-form__fila asde-form__fila--una-linea asde-caja-form">
		<?php $ocultos(); ?>
		<input type="hidden" name="asde_action" value="apuntar">
		<label for="asde-caja-a-fecha" class="screen-reader-text"><?php esc_html_e( 'Date', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
		<input type="date" id="asde-caja-a-fecha" name="fecha" value="<?php echo esc_attr( $hoy ); ?>" max="<?php echo esc_attr( $hoy ); ?>" required>
		<label for="asde-caja-a-sentido" class="screen-reader-text"><?php esc_html_e( 'Inflow or outflow', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
		<select id="asde-caja-a-sentido" name="sentido">
			<option value="salida"><?php esc_html_e( 'Goes out', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></option>
			<option value="entrada"><?php esc_html_e( 'Comes in', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></option>
		</select>
		<label for="asde-caja-a-importe" class="screen-reader-text"><?php esc_html_e( 'Amount (€)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
		<input type="text" id="asde-caja-a-importe" name="importe" inputmode="decimal" placeholder="<?php echo esc_attr( esc_attr__( 'Amount (€)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>" required>
		<label for="asde-caja-a-tipo" class="screen-reader-text"><?php esc_html_e( 'Type of expense or income', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
		<select id="asde-caja-a-tipo" name="tipo">
			<option value="0"><?php esc_html_e( 'No type', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></option>
			<?php foreach ( $grupos_tipo as $nat => $lista ) : ?>
				<optgroup label="<?php echo esc_attr( asde_t( TiposMovimiento::NATURALEZAS[ $nat ] ?? $nat ) ); ?>">
					<?php foreach ( $lista as $t ) : ?>
						<option value="<?php echo (int) $t['id']; ?>"><?php echo esc_html( $t['nombre'] ); ?></option>
					<?php endforeach; ?>
				</optgroup>
			<?php endforeach; ?>
		</select>
		<label for="asde-caja-a-concepto" class="screen-reader-text"><?php esc_html_e( 'Description', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
		<input type="text" id="asde-caja-a-concepto" name="concepto" maxlength="190" placeholder="<?php echo esc_attr( esc_attr__( 'Description', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>" required>
		<button type="submit" class="button"><?php esc_html_e( 'Record', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
	</form>
</section>

<section class="asde-card" data-asde-plegable="caja-traspaso" data-asde-inicio="cerrado">
	<h3><?php esc_html_e( 'Transfer between accounts', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h3>
	<p class="asde-help"><?php esc_html_e( 'Money that moves from this cash box to another or to the bank (or is withdrawn from the bank for this cash box). It is not an expense or income of the group.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
	<form method="post" action="<?php echo esc_url( $url ); ?>" class="asde-form asde-form__fila asde-form__fila--una-linea asde-caja-form">
		<?php $ocultos(); ?>
		<input type="hidden" name="asde_action" value="traspasar">
		<label for="asde-caja-t-fecha" class="screen-reader-text"><?php esc_html_e( 'Date', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
		<input type="date" id="asde-caja-t-fecha" name="fecha" value="<?php echo esc_attr( $hoy ); ?>" max="<?php echo esc_attr( $hoy ); ?>" required>
		<label for="asde-caja-t-hacia" class="screen-reader-text"><?php esc_html_e( 'Where it goes', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
		<select id="asde-caja-t-hacia" name="hacia" required>
			<?php foreach ( $hacia as $k => $nombre ) : ?>
				<option value="<?php echo esc_attr( $k ); ?>"><?php echo esc_html( '→ ' . $nombre ); ?></option>
			<?php endforeach; ?>
			<?php if ( $con_banco ) : ?>
				<option value="<?php echo esc_attr( CajaTable::BANCO ); ?>"><?php esc_html_e( 'Pay it into the bank', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></option>
				<option value="desde_banco"><?php esc_html_e( 'Withdraw it from the bank for this cash box', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></option>
			<?php endif; ?>
		</select>
		<label for="asde-caja-t-importe" class="screen-reader-text"><?php esc_html_e( 'Amount (€)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
		<input type="text" id="asde-caja-t-importe" name="importe" inputmode="decimal" placeholder="<?php echo esc_attr( esc_attr__( 'Amount (€)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>" required>
		<label for="asde-caja-t-concepto" class="screen-reader-text"><?php esc_html_e( 'Description', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
		<input type="text" id="asde-caja-t-concepto" name="concepto" maxlength="190" placeholder="<?php echo esc_attr( esc_attr__( 'Description (optional)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
		<button type="submit" class="button"><?php esc_html_e( 'Move funds', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
	</form>
</section>

<section class="asde-card" data-asde-plegable="caja-arqueo" data-asde-inicio="cerrado">
	<h3><?php esc_html_e( 'Cash count', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h3>
	<?php /* translators: %s: saldo de hoy */ ?>
	<p class="asde-help"><?php echo esc_html( sprintf( /* translators: %s: text */ __( 'Count the cash and enter how much there is. Today there should be %s. If it does not add up, enter the reason: the difference is recorded as an adjustment.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $euros( $saldo_hoy ) ) ); ?></p>
	<form method="post" action="<?php echo esc_url( $url ); ?>" class="asde-form asde-form__fila asde-form__fila--una-linea asde-caja-form">
		<?php $ocultos(); ?>
		<input type="hidden" name="asde_action" value="arqueo">
		<label for="asde-caja-q-fecha" class="screen-reader-text"><?php esc_html_e( 'Date', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
		<input type="date" id="asde-caja-q-fecha" name="fecha" value="<?php echo esc_attr( $hoy ); ?>" max="<?php echo esc_attr( $hoy ); ?>" required>
		<label for="asde-caja-q-recuento" class="screen-reader-text"><?php esc_html_e( 'Counted (€)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
		<input type="text" id="asde-caja-q-recuento" name="recuento" inputmode="decimal" placeholder="<?php echo esc_attr( esc_attr__( 'Counted (€)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>" required>
		<label for="asde-caja-q-motivo" class="screen-reader-text"><?php esc_html_e( 'Reason', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
		<input type="text" id="asde-caja-q-motivo" name="motivo" maxlength="255" placeholder="<?php echo esc_attr( esc_attr__( 'Reason (if it does not add up)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
		<button type="submit" class="button"><?php esc_html_e( 'Save the cash count', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
	</form>
</section>
