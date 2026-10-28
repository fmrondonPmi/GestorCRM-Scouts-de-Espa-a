<?php
/**
 * Reintegros (v0.47, doc 53): pedir uno, los míos y, para quien aprueba o paga, los pendientes y el historial.
 *
 * @var bool                $puede_pedir Si puede pedir reintegros (scouter o Coordinación)
 * @var array<int, string>  $eventos     Eventos a los que se puede asociar (id => nombre; vacío sin el módulo Eventos)
 * @var array[]             $mios        Los reintegros de esta persona
 * @var array[]             $todos       Por aprobar y por pagar (solo si aprueba o paga)
 * @var array[]             $historial   Pagados, rechazados y cancelados (solo si aprueba o paga)
 * @var string              $url
 * @var string              $hoy         Y-m-d
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Reintegros\ReintegrosTable;
use AsdeBalears\AreaPrivada\Reintegros\Servicio;
use AsdeBalears\AreaPrivada\Tesoreria\Calculadora;

defined( 'ABSPATH' ) || exit;

$fecha  = static fn( string $f ): string => wp_date( 'd/m/Y', strtotime( $f . ' 12:00' ) );
$nombre = static function ( array $r ) use ( $eventos ): string {
	return $r['evento_id'] ? (string) ( $eventos[ $r['evento_id'] ] ?? __( 'Event', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) : '';
};
/** Una fila de la tabla de reintegros, con lo que se puede hacer con ella. */
$fila = static function ( array $r, bool $con_quien ) use ( $url, $fecha, $nombre, $hoy ): void {
	$iban = Servicio::iban( $r );
	?>
	<tr>
		<td data-label="<?php echo esc_attr( esc_attr__( 'Reimbursement', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
			<strong><?php echo esc_html( ReintegrosTable::numero( $r ) ); ?></strong>
			<span class="asde-badge asde-badge--reintegro-<?php echo esc_attr( $r['estado'] ); ?>"><?php echo esc_html( ( ReintegrosTable::estados()[ $r['estado'] ] ?? '' ) ); ?></span>
			<?php echo wp_kses( $con_quien ? '<br><span class="asde-muted">' . esc_html( Servicio::nombre( $r['solicitante'] ) ) . '</span>' : '', asde_html_permitido() ); ?>
		</td>
		<td data-label="<?php echo esc_attr( esc_attr__( 'What', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
			<?php echo esc_html( $r['concepto'] ); ?><br>
			<span class="asde-muted"><?php echo esc_html( $fecha( $r['fecha'] ) ); ?><?php echo '' !== $nombre( $r ) ? ' · ' . esc_html( $nombre( $r ) ) : ''; ?></span>
			<?php if ( '' !== $r['motivo'] && ReintegrosTable::RECHAZADO === $r['estado'] ) : ?>
				<br><span class="asde-muted"><?php echo esc_html( sprintf( /* translators: %s: text */ __( 'Reason: %s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $r['motivo'] ) ); ?></span>
			<?php endif; ?>
			<?php if ( ReintegrosTable::PAGADO === $r['estado'] && $r['pago_fecha'] ) : ?>
				<br><span class="asde-muted"><?php echo esc_html( sprintf( /* translators: %s: text */ __( 'Paid on %s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $fecha( $r['pago_fecha'] ) ) ); ?><?php echo '' !== $r['pago_ref'] ? ' · ' . esc_html( $r['pago_ref'] ) : ''; ?></span>
			<?php endif; ?>
		</td>
		<td data-label="<?php echo esc_attr( esc_attr__( 'Amount', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
			<?php echo esc_html( Calculadora::euros( $r['importe'] ) ); ?><br>
			<span class="asde-muted"><?php echo esc_html( ( ReintegrosTable::formas()[ $r['forma'] ] ?? '' ) ); ?></span>
			<?php echo wp_kses( '' !== $iban ? '<br><span class="asde-muted">' . esc_html( $iban ) . '</span>' : '', asde_html_permitido() ); ?>
		</td>
		<td data-label="<?php echo esc_attr( esc_attr__( 'Actions', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
			<?php if ( '' !== $r['justificante'] ) : ?>
				<form method="post" action="<?php echo esc_url( $url ); ?>" class="asde-inline-form" data-asde-sin-carga target="_blank">
					<?php wp_nonce_field( 'asde_reintegro_' . $r['id'] ); ?>
					<input type="hidden" name="asde_action" value="reintegro_justificante">
					<input type="hidden" name="reintegro" value="<?php echo (int) $r['id']; ?>">
					<button type="submit" class="asde-btn-link"><?php esc_html_e( 'View receipt', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
				</form>
			<?php endif; ?>
			<?php if ( Servicio::puede_aprobar( $r ) ) : ?>
				<form method="post" action="<?php echo esc_url( $url ); ?>" class="asde-inline-form">
					<?php wp_nonce_field( 'asde_reintegro_' . $r['id'] ); ?>
					<input type="hidden" name="asde_action" value="reintegro_aprobar">
					<input type="hidden" name="reintegro" value="<?php echo (int) $r['id']; ?>">
					<button type="submit" class="button"><?php esc_html_e( 'Approve', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
				</form>
				<form method="post" action="<?php echo esc_url( $url ); ?>" class="asde-form asde-form--compacta">
					<?php wp_nonce_field( 'asde_reintegro_' . $r['id'] ); ?>
					<input type="hidden" name="asde_action" value="reintegro_rechazar">
					<input type="hidden" name="reintegro" value="<?php echo (int) $r['id']; ?>">
					<p class="asde-form__fila">
						<label><?php esc_html_e( 'Reason', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <input type="text" name="motivo" maxlength="255" required></label>
						<button type="submit" class="asde-btn-link asde-btn-danger"><?php esc_html_e( 'Reject', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
					</p>
				</form>
			<?php endif; ?>
			<?php if ( Servicio::puede_pagar( $r ) ) : ?>
				<form method="post" action="<?php echo esc_url( $url ); ?>" class="asde-form asde-form--compacta">
					<?php wp_nonce_field( 'asde_reintegro_' . $r['id'] ); ?>
					<input type="hidden" name="asde_action" value="reintegro_pagar">
					<input type="hidden" name="reintegro" value="<?php echo (int) $r['id']; ?>">
					<p class="asde-form__fila">
						<label><?php esc_html_e( 'Payment date', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <input type="date" name="fecha" value="<?php echo esc_attr( $hoy ); ?>" max="<?php echo esc_attr( $hoy ); ?>" required></label>
						<label><?php esc_html_e( 'Reference (optional)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <input type="text" name="referencia" maxlength="120"></label>
						<button type="submit" class="button"><?php esc_html_e( 'Mark as paid', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
					</p>
				</form>
			<?php endif; ?>
			<?php if ( Servicio::puede_cancelar( $r ) ) : ?>
				<form method="post" action="<?php echo esc_url( $url ); ?>" class="asde-inline-form" data-asde-confirmar="<?php echo esc_attr( sprintf( /* translators: %s: text */ __( 'Cancel reimbursement %s?', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), ReintegrosTable::numero( $r ) ) ); ?>">
					<?php wp_nonce_field( 'asde_reintegro_' . $r['id'] ); ?>
					<input type="hidden" name="asde_action" value="reintegro_cancelar">
					<input type="hidden" name="reintegro" value="<?php echo (int) $r['id']; ?>">
					<button type="submit" class="asde-btn-link asde-btn-danger"><?php esc_html_e( 'Cancel', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
				</form>
			<?php endif; ?>
		</td>
	</tr>
	<?php
};
/** La tabla de una lista de reintegros. */
$tabla = static function ( array $lista, bool $con_quien ) use ( $fila ): void {
	?>
	<table class="asde-table">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Reimbursement', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
				<th scope="col"><?php esc_html_e( 'What', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Amount', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
				<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $lista as $r ) : ?>
				<?php $fila( $r, $con_quien ); ?>
			<?php endforeach; ?>
		</tbody>
	</table>
	<?php
};
?>
<?php if ( $puede_pedir ) : ?>
	<section class="asde-card" data-asde-plegable="reintegros-pedir" data-asde-inicio="abierto">
		<h2 class="asde-card__title"><?php esc_html_e( 'Request a reimbursement', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h2>
		<p class="asde-muted"><?php esc_html_e( 'If you have paid for something for the group out of your own pocket, request here to be repaid. Attach the ticket or invoice. Treasury approves it and Treasury or Coordination pays it.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
		<form method="post" enctype="multipart/form-data" class="asde-form" action="<?php echo esc_url( $url ); ?>" data-asde-reintegro>
			<?php wp_nonce_field( 'asde_reintegro_pedir' ); ?>
			<input type="hidden" name="asde_action" value="reintegro_pedir">
			<p class="asde-form__fila asde-form__fila--una-linea">
				<label for="asde-rei-concepto"><?php esc_html_e( 'What you paid for', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <input type="text" id="asde-rei-concepto" name="concepto" maxlength="120" required></label>
				<label for="asde-rei-fecha"><?php esc_html_e( 'Date of the expense', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <input type="date" id="asde-rei-fecha" name="fecha" value="<?php echo esc_attr( $hoy ); ?>" max="<?php echo esc_attr( $hoy ); ?>" required></label>
				<label for="asde-rei-importe"><?php esc_html_e( 'Amount (€)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <input type="text" id="asde-rei-importe" name="importe" inputmode="decimal" size="8" required></label>
				<?php if ( $eventos ) : ?>
					<label for="asde-rei-evento"><?php esc_html_e( 'Event (if for one)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?>
						<select id="asde-rei-evento" name="evento">
							<option value=""><?php esc_html_e( 'No event', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></option>
							<?php foreach ( $eventos as $id => $nombre_evento ) : ?>
								<option value="<?php echo (int) $id; ?>"><?php echo esc_html( $nombre_evento ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
				<?php endif; ?>
			</p>
			<p class="asde-form__fila asde-form__fila--una-linea">
				<label for="asde-rei-just"><?php esc_html_e( 'Ticket or invoice (image or PDF, up to 5 MB)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <input type="file" id="asde-rei-just" name="justificante" accept="image/jpeg,image/png,image/webp,application/pdf" required></label>
				<label for="asde-rei-forma"><?php esc_html_e( 'How do you prefer to be paid?', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?>
					<select id="asde-rei-forma" name="forma">
						<?php foreach ( ( ReintegrosTable::formas() ) as $forma => $etiqueta ) : ?>
							<option value="<?php echo esc_attr( $forma ); ?>"><?php echo esc_html( $etiqueta ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label for="asde-rei-iban" data-asde-iban><?php esc_html_e( 'IBAN (transfer only)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <input type="text" id="asde-rei-iban" name="iban" maxlength="42" autocomplete="off"></label>
				<button type="submit" class="button"><?php esc_html_e( 'Request the reimbursement', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
			</p>
			<p class="asde-help"><?php esc_html_e( 'Only Treasury and Coordination see it, and it is deleted when the reimbursement is paid, rejected or cancelled.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
		</form>
	</section>
<?php endif; ?>

<?php if ( Servicio::ve_todos() ) : ?>
	<section class="asde-card" data-asde-plegable="reintegros-pendientes" data-asde-inicio="abierto">
		<h2 class="asde-card__title"><?php esc_html_e( 'To approve and to pay', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h2>
		<?php if ( ! $todos ) : ?>
			<p class="asde-muted"><?php esc_html_e( 'There are no pending reimbursements.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
		<?php else : ?>
			<?php $tabla( $todos, true ); ?>
		<?php endif; ?>
	</section>
<?php endif; ?>

<?php if ( $puede_pedir ) : ?>
	<section class="asde-card" data-asde-plegable="reintegros-mios" data-asde-inicio="abierto">
		<h2 class="asde-card__title"><?php esc_html_e( 'My reimbursements', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h2>
		<?php if ( ! $mios ) : ?>
			<p class="asde-muted"><?php esc_html_e( 'You have not requested any reimbursement yet.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
		<?php else : ?>
			<?php $tabla( $mios, false ); ?>
		<?php endif; ?>
	</section>
<?php endif; ?>

<?php if ( Servicio::ve_todos() && $historial ) : ?>
	<section class="asde-card" data-asde-plegable="reintegros-historial" data-asde-inicio="cerrado">
		<h2 class="asde-card__title"><?php esc_html_e( 'History', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h2>
		<?php $tabla( $historial, true ); ?>
	</section>
<?php endif; ?>
