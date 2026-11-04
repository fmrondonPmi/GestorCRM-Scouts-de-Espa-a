<?php
/**
 * Evento · cuentas (v0.36): balance (previsto y real), gastos e ingresos reales con su
 * justificante, reembolsos, anticipos de caja, fondo de emergencia y cierre.
 *
 * @var array                               $e
 * @var AsdeBalears\AreaPrivada\Auth\Ambito $ambito
 * @var bool                                $gestiona   Gestiona el evento (propone gastos)
 * @var array[]                             $gastos     GastosTable::de_evento() (también anulados y rechazados)
 * @var array[]                             $anticipos  AnticiposTable::de_evento()
 * @var array<int, string>                  $personas   user_id => nombre (equipo con cuenta en la web)
 * @var array[]                             $partidas   Partidas del presupuesto
 * @var array                               $balance    Cuentas::balance() o el informe final guardado
 * @var string[]                            $por_cerrar Lo que falta para poder cerrar
 * @var bool                                $economia   Es Tesorería
 * @var bool                                $abiertas   Las cuentas no están cerradas
 * @var array[]                             $banco      Movimientos del banco asociados al evento (BancoTable::revisados_de_evento), solo para Tesorería
 * @var array<int, array>                   $tipos_banco Tipos de gasto e ingreso
 * @var string                              $url
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Eventos\AnticiposTable;
use AsdeBalears\AreaPrivada\Eventos\GastosTable;
use AsdeBalears\AreaPrivada\Tesoreria\Calculadora;

defined( 'ABSPATH' ) || exit;

$campos = static function ( string $accion, string $extra = '' ) use ( $e ): string {
	return wp_nonce_field( 'asde_evento_' . $e['id'], '_wpnonce', true, false )
		. '<input type="hidden" name="asde_action" value="' . esc_attr( $accion ) . '">'
		. '<input type="hidden" name="evento" value="' . (int) $e['id'] . '">'
		. '<input type="hidden" name="pestana" value="cuentas">'
		. $extra;
};
$oculto  = static fn( string $n, int $v ): string => '<input type="hidden" name="' . esc_attr( $n ) . '" value="' . (int) $v . '">';
$quien   = static fn( int $id ): string => $personas[ $id ] ?? ( $id && ( $u = get_userdata( $id ) ) ? $u->display_name : '' );
$fecha   = static fn( ?string $f ): string => $f ? wp_date( 'd/m/Y', (int) strtotime( substr( $f, 0, 10 ) . ' 12:00:00' ) ) : '';
$hoy     = current_time( 'Y-m-d' );
$euros   = array( Calculadora::class, 'euros' );
$formas  = static function (): string {
	$h = '<select name="forma">';
	foreach ( ( GastosTable::formas() ) as $k => $t ) {
		$h .= '<option value="' . esc_attr( $k ) . '">' . esc_html( $t ) . '</option>';
	}
	return $h . '</select>';
};
$partida = static function ( string $tipo ) use ( $partidas ): string {
	$h = '<select name="partida"><option value="0">' . esc_html( __( '— None —', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) . '</option>';
	foreach ( $partidas as $p ) {
		if ( $tipo === $p['tipo'] ) {
			$h .= '<option value="' . (int) $p['id'] . '">' . esc_html( $p['concepto'] ) . '</option>';
		}
	}
	return $h . '</select>';
};
$nombres_partida = array_column( $partidas, 'concepto', 'id' );
$abiertos        = array_values( array_filter( $anticipos, array( AnticiposTable::class, 'abierto' ) ) );
$puede_apuntar   = $abiertas && ( $economia || $gestiona );
$yo              = get_current_user_id();
?>
<?php if ( ! $abiertas ) : ?>
	<div class="asde-notice asde-notice--info">
		<?php echo esc_html( sprintf( /* translators: 1: text, 2: text */ __( 'Accounts closed on %1$s by %2$s: this is the final report. To change anything, Treasury must reopen them.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), wp_date( 'd/m/Y', (int) strtotime( $e['cerrado_en'] . ' UTC' ) ), $quien( $e['cerrado_por'] ) ) ); ?>
	</div>
	<?php if ( $economia ) : ?>
		<details class="asde-accion asde-no-print">
			<summary><?php esc_html_e( 'Reopen the accounts', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></summary>
			<form method="post" class="asde-form asde-form--compacta" data-asde-confirmar="<?php echo esc_attr( __( 'Reopen the event\'s accounts? They will have to be closed again.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
				<?php echo wp_kses( $campos( 'evento_reabrir_cuentas' ), asde_html_permitido() ); ?>
				<p class="asde-help"><?php esc_html_e( 'The event and its accounts can be changed again. Who picks up and the notes, which were deleted on closing, do not come back.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
				<label><?php esc_html_e( 'Reason', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <input type="text" name="motivo" maxlength="255" required></label>
				<button type="submit" class="button"><?php esc_html_e( 'Reopen the accounts', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
			</form>
		</details>
	<?php endif; ?>
<?php endif; ?>

<section class="asde-card asde-cuentas__balance">
	<header class="asde-card__header asde-card__header--split">
		<h3 class="asde-card__title"><?php echo esc_html( $abiertas ? __( 'Overall balance', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) : __( 'Final report', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?></h3>
		<span class="asde-no-print">
			<button type="button" class="button" data-asde-imprimir-pagina><?php esc_html_e( 'Print', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
			<form method="post" class="asde-inline-form" data-asde-sin-carga><?php echo wp_kses( $campos( 'evento_informe' ), asde_html_permitido() ); ?><button type="submit" class="button"><?php esc_html_e( 'Download CSV', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button></form>
		</span>
	</header>
	<p class="asde-muted"><?php echo esc_html( sprintf( /* translators: 1: text, 2: text, 3: text */ __( '%1$s · %2$s · to %3$s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $balance['evento'], $balance['nombre'], $balance['fecha'] ) ); ?></p>

	<?php if ( $balance['lineas'] ) : ?>
		<table class="asde-table asde-table--cuentas" data-asde-paginar>
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Item', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
					<th scope="col" class="asde-num"><?php esc_html_e( 'Planned', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
					<th scope="col" class="asde-num"><?php esc_html_e( 'Actual', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $balance['lineas'] as $l ) : ?>
					<tr>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Item', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo esc_html( $l['concepto'] ); ?><?php echo GastosTable::INGRESO === $l['tipo'] ? ' <span class="asde-badge asde-badge--ingreso">' . esc_html( __( 'Income', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) . '</span>' : ''; ?></td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Planned', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>" class="asde-num"><?php echo esc_html( $euros( $l['previsto'] ) ); ?></td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Actual', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>" class="asde-num"><?php echo esc_html( $euros( $l['real'] ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>

	<dl class="asde-datos">
		<div><dt><?php esc_html_e( 'Collected from families', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></dt><dd>
			<?php echo esc_html( $euros( $balance['cobros']['neto'] ) ); ?>
			<?php if ( $balance['cobros']['devuelto'] > 0 ) : ?>
				<span class="asde-muted"><?php echo esc_html( sprintf( /* translators: 1: text, 2: text */ __( '(paid %1$s, refunded %2$s)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $euros( $balance['cobros']['pagado'] ), $euros( $balance['cobros']['devuelto'] ) ) ); ?></span>
			<?php endif; ?>
		</dd></div>
		<div><dt><?php esc_html_e( 'Pending collection', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></dt><dd><?php echo esc_html( $euros( $balance['cobros']['pendiente'] ) ); ?></dd></div>
		<div><dt><?php esc_html_e( 'Other income', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></dt><dd><?php echo esc_html( $euros( $balance['ingresos'] ) ); ?></dd></div>
		<div><dt><?php esc_html_e( 'Expenses', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></dt><dd>
			<?php echo esc_html( $euros( $balance['gastos'] ) ); ?>
			<span class="asde-muted"><?php echo esc_html( sprintf( /* translators: %s: text */ __( '(expected %s)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $euros( $balance['previsto']['gastos'] ) ) ); ?></span>
		</dd></div>
		<div><dt><?php esc_html_e( 'Emergency fund', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></dt><dd>
			<?php echo esc_html( sprintf( /* translators: 1: text, 2: text, 3: text */ __( 'Collected %1$s · used %2$s · left %3$s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $euros( $balance['fondo']['recaudado'] ), $euros( $balance['fondo']['usado'] ), $euros( $balance['fondo']['disponible'] ) ) ); ?>
		</dd></div>
		<div class="asde-cuentas__resultado"><dt><?php esc_html_e( 'Result', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></dt><dd>
			<strong><?php echo esc_html( $euros( $balance['resultado'] ) ); ?></strong>
			<span class="asde-muted"><?php echo esc_html( $balance['resultado'] < 0 ? __( 'The group bears it.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) : __( 'It stays with the group.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?></span>
		</dd></div>
	</dl>
	<p class="asde-help"><?php esc_html_e( 'Result = what was collected from families (minus refunds) + other income − validated expenses. Expenses pending review do not count until Treasury validates them.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
</section>

<?php if ( ! empty( $banco ) ) : ?>
	<section class="asde-card" data-asde-plegable="evento-banco" data-asde-inicio="cerrado">
		<h3 class="asde-card__title"><?php esc_html_e( 'Linked bank transactions', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h3>
		<p class="asde-muted"><?php esc_html_e( 'Treasury linked them when marking them as reviewed in the bank. They are only a reference: they do not change the balance above.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
		<table class="asde-table" data-asde-paginar>
			<thead><tr><th scope="col"><?php esc_html_e( 'Date', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th><th scope="col"><?php esc_html_e( 'Type', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th><th scope="col" class="asde-num"><?php esc_html_e( 'Amount', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th></tr></thead>
			<tbody>
				<?php foreach ( $banco as $b ) : ?>
					<tr>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Date', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo esc_html( wp_date( 'd/m/Y', strtotime( $b['fecha'] . ' 12:00' ) ) ); ?></td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Type', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo esc_html( isset( $tipos_banco[ (int) $b['tipo_id'] ] ) ? $tipos_banco[ (int) $b['tipo_id'] ]['nombre'] : __( 'No type', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?><?php echo '' !== $b['nota'] ? ' · ' . esc_html( $b['nota'] ) : ''; ?></td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Amount', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>" class="asde-num"><?php echo esc_html( ( $b['importe'] > 0 ? '+' : '' ) . \AsdeBalears\AreaPrivada\Tesoreria\Calculadora::euros( (float) $b['importe'] ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</section>
<?php endif; ?>

<section class="asde-card">
	<h3 class="asde-card__title"><?php esc_html_e( 'Expenses and income', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h3>
	<?php if ( $abiertas ) : ?>
		<?php if ( $balance['por_revisar'] ) : ?>
			<p class="asde-notice asde-notice--warning"><?php echo esc_html( sprintf( /* translators: %d: number */ __( 'There are %d expense(s) to review.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $balance['por_revisar'] ) ); ?></p>
		<?php endif; ?>
		<?php if ( $balance['reembolsos']['n'] ) : ?>
			<p class="asde-notice asde-notice--info"><?php echo esc_html( sprintf( /* translators: 1: number, 2: text */ __( 'Pending reimbursements: %1$d (%2$s).', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $balance['reembolsos']['n'], $euros( $balance['reembolsos']['importe'] ) ) ); ?></p>
		<?php endif; ?>
	<?php endif; ?>
	<?php if ( ! $gastos ) : ?>
		<p><?php esc_html_e( 'No expenses or income have been recorded yet.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
	<?php else : ?>
		<table class="asde-table asde-table--cuentas" data-asde-paginar>
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Date', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Description', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
					<th scope="col" class="asde-num"><?php esc_html_e( 'Amount', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Status', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
					<th scope="col" class="asde-no-print"><?php esc_html_e( 'Actions', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $gastos as $g ) : ?>
					<?php
					$anulado = null !== $g['anulado_en'];
					$estado  = $anulado ? 'anulado' : $g['estado'];
					?>
					<tr class="<?php echo $anulado ? 'asde-anulado' : ''; ?>">
						<td data-label="<?php echo esc_attr( esc_attr__( 'Date', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo esc_html( $fecha( $g['fecha'] ) ); ?></td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Description', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
							<strong><?php echo esc_html( $g['concepto'] ); ?></strong><?php echo GastosTable::INGRESO === $g['tipo'] ? ' <span class="asde-badge asde-badge--ingreso">' . esc_html( __( 'Income', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) . '</span>' : ''; ?>
							<?php if ( '' !== $g['proveedor'] ) : ?>
								· <?php echo esc_html( $g['proveedor'] ); ?>
							<?php endif; ?>
							<br><span class="asde-muted">
								<?php
								$detalle = array( ( GastosTable::formas()[ $g['forma'] ] ?? $g['forma'] ) );
								if ( $g['partida_id'] && isset( $nombres_partida[ $g['partida_id'] ] ) ) {
									$detalle[] = sprintf( /* translators: %s: text */ __( 'item: %s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $nombres_partida[ $g['partida_id'] ] );
								}
								if ( GastosTable::PAGO_PERSONA === $g['pagado_por'] ) {
									$detalle[] = sprintf( /* translators: %s: text */ __( 'paid by %s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $quien( $g['persona'] ) );
								} elseif ( GastosTable::PAGO_ANTICIPO === $g['pagado_por'] ) {
									$detalle[] = sprintf( /* translators: %d: number */ __( 'with advance no. %d', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $g['anticipo_id'] );
								}
								if ( $g['con_fondo'] ) {
									$detalle[] = __( 'charged to the emergency fund', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' );
								}
								echo esc_html( implode( ' · ', $detalle ) );
								?>
							</span>
							<?php if ( $g['creado_por'] ) : ?>
								<br><span class="asde-muted"><?php echo esc_html( sprintf( /* translators: %s: text */ __( 'Recorded by %s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $quien( $g['creado_por'] ) ) ); ?></span>
							<?php endif; ?>
						</td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Amount', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>" class="asde-num"><?php echo esc_html( $euros( $g['importe'] ) ); ?></td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Status', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
							<span class="asde-badge asde-badge--gasto-<?php echo esc_attr( $estado ); ?>"><?php echo esc_html( $anulado ? __( 'Cancelled', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) : ( GastosTable::estados()[ $g['estado'] ] ?? $g['estado'] ) ); ?></span>
							<?php if ( '' !== $g['motivo'] && ( $anulado || GastosTable::RECHAZADO === $g['estado'] ) ) : ?>
								<br><span class="asde-muted"><?php echo esc_html( sprintf( /* translators: %s: text */ __( 'Reason: %s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), asde_guardado( (string) $g['motivo'] ) ) ); ?></span>
							<?php endif; ?>
							<?php if ( null !== $g['reembolsado_en'] ) : ?>
								<br><span class="asde-muted"><?php echo esc_html( sprintf( /* translators: 1: text, 2: text */ __( 'Reimbursed on %1$s (%2$s)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $fecha( $g['reembolsado_en'] ), ( GastosTable::formas()[ $g['reembolso_forma'] ] ?? $g['reembolso_forma'] ) ) ); ?></span>
							<?php elseif ( GastosTable::por_reembolsar( $g ) ) : ?>
								<br><span class="asde-muted"><?php echo esc_html( sprintf( /* translators: %s: text */ __( 'Pending reimbursement to %s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $quien( $g['persona'] ) ) ); ?></span>
							<?php endif; ?>
						</td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Actions', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>" class="asde-no-print">
							<?php if ( '' !== $g['justificante'] ) : ?>
								<form method="post" class="asde-inline-form" target="_blank"><?php echo wp_kses( $campos( 'evento_justificante', $oculto( 'gasto', $g['id'] ) ), asde_html_permitido() ); ?><button type="submit" class="asde-btn-link"><?php esc_html_e( 'View receipt', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button></form>
							<?php endif; ?>
							<?php if ( $abiertas && ! $anulado ) : ?>
								<?php if ( GastosTable::por_revisar( $g ) && $economia ) : ?>
									<form method="post" class="asde-inline-form"><?php echo wp_kses( $campos( 'evento_gasto_validar', $oculto( 'gasto', $g['id'] ) ), asde_html_permitido() ); ?><button type="submit" class="button button-primary"><?php esc_html_e( 'Validate', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button></form>
									<details class="asde-accion">
										<summary><?php esc_html_e( 'Reject', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></summary>
										<form method="post" class="asde-form asde-form--compacta">
											<?php echo wp_kses( $campos( 'evento_gasto_rechazar', $oculto( 'gasto', $g['id'] ) ), asde_html_permitido() ); ?>
											<label><?php esc_html_e( 'Reason', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <input type="text" name="motivo" maxlength="255" required></label>
											<button type="submit" class="button"><?php esc_html_e( 'Reject the expense', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
										</form>
									</details>
								<?php endif; ?>
								<?php if ( GastosTable::por_revisar( $g ) && ( $yo === $g['creado_por'] || $economia ) ) : ?>
									<form method="post" class="asde-inline-form" data-asde-confirmar="<?php echo esc_attr( __( 'Withdraw this expense? It will not count in the accounts.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo wp_kses( $campos( 'evento_gasto_retirar', $oculto( 'gasto', $g['id'] ) ), asde_html_permitido() ); ?><button type="submit" class="asde-btn-link"><?php esc_html_e( 'Remove', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button></form>
								<?php endif; ?>
								<?php if ( GastosTable::por_reembolsar( $g ) && $economia ) : ?>
									<details class="asde-accion">
										<summary><?php esc_html_e( 'Reimburse', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></summary>
										<form method="post" class="asde-form asde-form--compacta">
											<?php echo wp_kses( $campos( 'evento_gasto_reembolsar', $oculto( 'gasto', $g['id'] ) ), asde_html_permitido() ); ?>
											<label><?php esc_html_e( 'Date', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <input type="date" name="fecha" value="<?php echo esc_attr( $hoy ); ?>" max="<?php echo esc_attr( $hoy ); ?>" required></label>
											<label><?php esc_html_e( 'Payment method', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <?php echo wp_kses( $formas(), asde_html_permitido() ); ?></label>
											<button type="submit" class="button button-primary"><?php echo esc_html( sprintf( /* translators: %s: text */ __( 'Reimburse %s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $euros( $g['importe'] ) ) ); ?></button>
										</form>
									</details>
								<?php endif; ?>
								<?php if ( GastosTable::vale( $g ) && null === $g['reembolsado_en'] && $economia ) : ?>
									<details class="asde-accion">
										<summary><?php esc_html_e( 'Cancel', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></summary>
										<form method="post" class="asde-form asde-form--compacta">
											<?php echo wp_kses( $campos( 'evento_gasto_anular', $oculto( 'gasto', $g['id'] ) ), asde_html_permitido() ); ?>
											<label><?php esc_html_e( 'Reason', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <input type="text" name="motivo" maxlength="255" required></label>
											<button type="submit" class="button"><?php esc_html_e( 'Cancel', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
										</form>
									</details>
								<?php endif; ?>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</section>

<?php if ( $puede_apuntar ) : ?>
	<section class="asde-card asde-no-print">
		<h3 class="asde-card__title"><?php esc_html_e( 'Record an expense', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h3>
		<p class="asde-help"><?php echo esc_html( $economia ? __( 'What Treasury records is validated.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) : __( 'Treasury reviews it: until it validates it, it does not count in the balance. If you paid for it, it will reimburse you.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?></p>
		<form method="post" enctype="multipart/form-data" class="asde-form asde-form--compacta">
			<?php echo wp_kses( $campos( 'evento_gasto' ), asde_html_permitido() ); ?>
			<p class="asde-form__fila">
				<label><?php esc_html_e( 'Date', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <input type="date" name="fecha" value="<?php echo esc_attr( $hoy ); ?>" max="<?php echo esc_attr( $hoy ); ?>" required></label>
				<label><?php esc_html_e( 'Amount (€)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <input type="text" name="importe" inputmode="decimal" size="10" required></label>
				<label><?php esc_html_e( 'Payment method', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <?php echo wp_kses( $formas(), asde_html_permitido() ); ?></label>
			</p>
			<p class="asde-form__fila">
				<label><?php esc_html_e( 'Description', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <input type="text" name="concepto" maxlength="120" required placeholder="<?php echo esc_attr( esc_attr__( 'e.g. Food shopping', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"></label>
				<label><?php esc_html_e( 'Supplier', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <input type="text" name="proveedor" maxlength="120"></label>
				<label><?php esc_html_e( 'Budget item', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <?php echo wp_kses( $partida( GastosTable::GASTO ), asde_html_permitido() ); ?></label>
			</p>
			<fieldset>
				<legend><?php esc_html_e( 'Who paid it?', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></legend>
				<label class="asde-choice"><input type="radio" name="pagado_por" value="<?php echo esc_attr( GastosTable::PAGO_GRUPO ); ?>" checked><span><?php echo esc_html( ( GastosTable::pagadores()[ GastosTable::PAGO_GRUPO ] ) ); ?></span></label>
				<?php if ( $abiertos ) : ?>
					<label class="asde-choice"><input type="radio" name="pagado_por" value="<?php echo esc_attr( GastosTable::PAGO_ANTICIPO ); ?>"><span><?php echo esc_html( ( GastosTable::pagadores()[ GastosTable::PAGO_ANTICIPO ] ) ); ?></span></label>
					<label><?php esc_html_e( 'Advance', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?>
						<select name="anticipo">
							<?php foreach ( $abiertos as $an ) : ?>
								<option value="<?php echo (int) $an['id']; ?>"><?php echo esc_html( sprintf( /* translators: 1: number, 2: text, 3: text */ __( 'No. %1$d · %2$s · %3$s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $an['id'], $quien( $an['persona'] ), $euros( $an['importe'] ) ) ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
				<?php endif; ?>
				<?php if ( ! $economia || $personas ) : ?>
					<label class="asde-choice"><input type="radio" name="pagado_por" value="<?php echo esc_attr( GastosTable::PAGO_PERSONA ); ?>"><span><?php echo esc_html( $economia ? ( GastosTable::pagadores()[ GastosTable::PAGO_PERSONA ] ) : __( 'Me, out of my own pocket (Treasury will reimburse me)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?></span></label>
				<?php endif; ?>
				<?php if ( $economia && $personas ) : ?>
					<label><?php esc_html_e( 'Who has to be reimbursed', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?>
						<select name="persona">
							<?php foreach ( $personas as $uid => $nombre ) : ?>
								<option value="<?php echo (int) $uid; ?>"><?php echo esc_html( $nombre ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
				<?php endif; ?>
			</fieldset>
			<label class="asde-choice"><input type="checkbox" name="con_fondo" value="1"><span><?php esc_html_e( 'Charged to the emergency fund (an unforeseen expense)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span></label>
			<p><label><?php esc_html_e( 'Receipt (photo of the ticket or invoice, or PDF; up to 5 MB)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <input type="file" name="justificante" accept="image/jpeg,image/png,image/webp,application/pdf" required></label></p>
			<p><button type="submit" class="button button-primary"><?php echo esc_html( $economia ? __( 'Record the expense', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) : __( 'Send to Treasury', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?></button></p>
		</form>
	</section>
<?php endif; ?>

<?php if ( $abiertas && $economia ) : ?>
	<section class="asde-card asde-no-print">
		<details>
			<summary><strong><?php esc_html_e( 'Record an income (grant, donation…)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></strong></summary>
			<p class="asde-help"><?php esc_html_e( 'Families\' payments are not recorded here: they are recorded in the "Sign-ups" tab.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
			<form method="post" class="asde-form asde-form--compacta">
				<?php echo wp_kses( $campos( 'evento_ingreso' ), asde_html_permitido() ); ?>
				<p class="asde-form__fila">
					<label><?php esc_html_e( 'Date', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <input type="date" name="fecha" value="<?php echo esc_attr( $hoy ); ?>" max="<?php echo esc_attr( $hoy ); ?>" required></label>
					<label><?php esc_html_e( 'Amount (€)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <input type="text" name="importe" inputmode="decimal" size="10" required></label>
					<label><?php esc_html_e( 'Payment method', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <?php echo wp_kses( $formas(), asde_html_permitido() ); ?></label>
				</p>
				<p class="asde-form__fila">
					<label><?php esc_html_e( 'Description', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <input type="text" name="concepto" maxlength="120" required></label>
					<label><?php esc_html_e( 'From whom', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <input type="text" name="proveedor" maxlength="120"></label>
					<label><?php esc_html_e( 'Budget item', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <?php echo wp_kses( $partida( GastosTable::INGRESO ), asde_html_permitido() ); ?></label>
				</p>
				<p><button type="submit" class="button"><?php esc_html_e( 'Record the income', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button></p>
			</form>
		</details>
	</section>
<?php endif; ?>

<?php if ( $anticipos || ( $abiertas && $economia ) ) : ?>
	<section class="asde-card">
		<h3 class="asde-card__title"><?php esc_html_e( 'Cash advances', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h3>
		<?php if ( ! $anticipos ) : ?>
			<p><?php esc_html_e( 'No advance has been given.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
		<?php else : ?>
			<ul class="asde-lista-nombres">
				<?php foreach ( $anticipos as $an ) : ?>
					<?php
					$con    = array_filter( $gastos, static fn( $g ) => $g['anticipo_id'] === $an['id'] && GastosTable::PAGO_ANTICIPO === $g['pagado_por'] && GastosTable::vale( $g ) );
					$usado  = round( array_sum( array_column( $con, 'importe' ) ), 2 );
					$anulado = null !== $an['anulado_en'];
					?>
					<li class="<?php echo $anulado ? 'asde-anulado' : ''; ?>">
						<strong><?php echo esc_html( sprintf( /* translators: 1: number, 2: text */ __( 'No. %1$d · %2$s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $an['id'], $quien( $an['persona'] ) ) ); ?></strong>
						· <?php echo esc_html( sprintf( /* translators: 1: text, 2: text, 3: text */ __( '%1$s on %2$s (%3$s)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $euros( $an['importe'] ), $fecha( $an['fecha'] ), ( GastosTable::formas()[ $an['forma'] ] ?? $an['forma'] ) ) ); ?>
						<?php if ( '' !== $an['nota'] ) : ?>
							· <span class="asde-muted"><?php echo esc_html( $an['nota'] ); ?></span>
						<?php endif; ?>
						<br>
						<?php if ( $anulado ) : ?>
							<span class="asde-muted"><?php esc_html_e( 'Cancelled', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span>
						<?php elseif ( AnticiposTable::LIQUIDADO === $an['estado'] ) : ?>
							<span class="asde-muted"><?php echo esc_html( sprintf( /* translators: 1: text, 2: text, 3: text */ __( 'Settled on %1$s: expenses %2$s, returned %3$s.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), wp_date( 'd/m/Y', (int) strtotime( $an['liquidado_en'] . ' UTC' ) ), $euros( $usado ), $euros( $an['devuelto'] ) ) ); ?><?php echo '' !== $an['liquidacion_nota'] ? ' ' . esc_html( sprintf( /* translators: %s: text */ __( 'Note: %s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $an['liquidacion_nota'] ) ) : ''; ?></span>
						<?php else : ?>
							<span class="asde-muted"><?php echo esc_html( sprintf( /* translators: 1: text, 2: text */ __( 'To settle: validated expenses %1$s; they would have to return %2$s.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $euros( $usado ), $euros( max( 0, $an['importe'] - $usado ) ) ) ); ?></span>
							<?php if ( $abiertas && $economia ) : ?>
								<details class="asde-accion asde-no-print">
									<summary><?php esc_html_e( 'Settle', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></summary>
									<form method="post" class="asde-form asde-form--compacta">
										<?php echo wp_kses( $campos( 'evento_anticipo_liquidar', $oculto( 'anticipo', $an['id'] ) ), asde_html_permitido() ); ?>
										<label><?php esc_html_e( 'Refunded (€)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <input type="text" name="devuelto" inputmode="decimal" value="<?php echo esc_attr( number_format( max( 0, $an['importe'] - $usado ), 2, ',', '' ) ); ?>" required></label>
										<label><?php esc_html_e( 'Note (required if it doesn\'t add up)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <input type="text" name="nota" maxlength="255"></label>
										<button type="submit" class="button button-primary"><?php esc_html_e( 'Settle the advance', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
									</form>
								</details>
								<?php if ( ! $con ) : ?>
									<form method="post" class="asde-inline-form asde-no-print" data-asde-confirmar="<?php echo esc_attr( __( 'Cancel this advance? Do it only if it was recorded by mistake.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo wp_kses( $campos( 'evento_anticipo_anular', $oculto( 'anticipo', $an['id'] ) ), asde_html_permitido() ); ?><button type="submit" class="asde-btn-link"><?php esc_html_e( 'Cancel', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button></form>
								<?php endif; ?>
							<?php endif; ?>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>

		<?php if ( $abiertas && $economia ) : ?>
			<?php if ( ! $personas ) : ?>
				<p class="asde-help"><?php esc_html_e( 'To give an advance, the person must be on the event team and have an account on the website.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
			<?php else : ?>
				<details class="asde-no-print">
					<summary><?php esc_html_e( 'Give an advance', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></summary>
					<form method="post" class="asde-form asde-form--compacta">
						<?php echo wp_kses( $campos( 'evento_anticipo' ), asde_html_permitido() ); ?>
						<label><?php esc_html_e( 'To whom', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?>
							<select name="persona">
								<?php foreach ( $personas as $uid => $nombre ) : ?>
									<option value="<?php echo (int) $uid; ?>"><?php echo esc_html( $nombre ); ?></option>
								<?php endforeach; ?>
							</select>
						</label>
						<label><?php esc_html_e( 'Amount (€)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <input type="text" name="importe" inputmode="decimal" size="10" required></label>
						<label><?php esc_html_e( 'Date', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <input type="date" name="fecha" value="<?php echo esc_attr( $hoy ); ?>" max="<?php echo esc_attr( $hoy ); ?>" required></label>
						<label><?php esc_html_e( 'Payment method', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <?php echo wp_kses( $formas(), asde_html_permitido() ); ?></label>
						<label><?php esc_html_e( 'Note', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <input type="text" name="nota" maxlength="255"></label>
						<button type="submit" class="button button-primary"><?php esc_html_e( 'Give the advance', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
					</form>
				</details>
			<?php endif; ?>
		<?php endif; ?>
	</section>
<?php endif; ?>

<?php if ( $abiertas ) : ?>
	<section class="asde-card asde-no-print">
		<h3 class="asde-card__title"><?php esc_html_e( 'Close the accounts', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h3>
		<?php if ( ! $economia ) : ?>
			<p><?php esc_html_e( 'Treasury closes them when the event is over and everything is reviewed: the balance becomes the final report.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
		<?php elseif ( $por_cerrar ) : ?>
			<p><?php esc_html_e( 'They cannot be closed yet:', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
			<ul class="asde-comprobacion">
				<?php foreach ( $por_cerrar as $motivo ) : ?>
					<li class="asde-comprobacion--aviso"><span class="asde-comprobacion__marca" aria-hidden="true">!</span> <?php echo esc_html( $motivo ); ?></li>
				<?php endforeach; ?>
			</ul>
		<?php else : ?>
			<p><?php esc_html_e( 'When closed, today\'s balance becomes the final report, the collection data and the notes on the sign-ups are deleted and the event becomes read-only. If something needs changing afterwards, Treasury can reopen them (what was deleted does not come back).', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
			<form method="post" data-asde-confirmar="<?php echo esc_attr( __( 'Close the event\'s accounts? Who picks up and the notes on the sign-ups are deleted and do not come back.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
				<?php echo wp_kses( $campos( 'evento_cerrar' ), asde_html_permitido() ); ?>
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Close the accounts', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
			</form>
		<?php endif; ?>
	</section>
<?php endif; ?>
