<?php
/**
 * Tesorería · una remesa SEPA: fichero, aviso a las familias, envío al banco y cobros.
 *
 * @var array   $r         Remesa (Sepa\Remesas::obtener)
 * @var array[] $lineas    Sus adeudos
 * @var array   $familias  id => familia
 * @var array   $personas  nº Scouts de España => Persona
 * @var string  $url       Domiciliaciones de la ronda
 * @var string  $url_fam   Control de pagos
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Sepa\Remesas;
use AsdeBalears\AreaPrivada\Tesoreria\Calculadora;

defined( 'ABSPATH' ) || exit;

$fmt_fecha = static fn( string $f ) => '' !== $f ? wp_date( 'd/m/Y', (int) strtotime( $f ) ) : '';
$fmt_dt    = static fn( ?string $f ) => $f ? get_date_from_gmt( $f, 'd/m/Y H:i' ) : '';
$nombres   = static function ( int $id ) use ( $familias, $personas ): string {
	$f = $familias[ $id ] ?? null;
	return $f ? implode( ', ', array_map( static fn( $num ) => isset( $personas[ $num ] ) ? $personas[ $num ]->nombre : $num, $f['miembros'] ) ) : '';
};
$estado_l  = array(
	Remesas::PENDIENTE => __( 'Pending', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
	Remesas::COBRADO   => __( 'Collected', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
	Remesas::DEVUELTO  => __( 'Returned', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
);
$pendientes = array_filter( $lineas, static fn( $l ) => Remesas::PENDIENTE === $l['estado'] );
?>
<p class="asde-back"><a href="<?php echo esc_url( $url ); ?>"><?php esc_html_e( '← Direct debits', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a></p>

<section class="asde-card">
	<header class="asde-card__header asde-card__header--split">
		<h2 class="asde-card__title"><?php esc_html_e( 'Batch', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <?php echo esc_html( $r['numero'] ); ?></h2>
		<span class="asde-badge"><?php echo esc_html( ( Remesas::estados()[ $r['estado'] ] ?? $r['estado'] ) ); ?></span>
	</header>
	<ul class="asde-resumen">
		<li><?php esc_html_e( 'Round', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <strong><?php echo esc_html( $r['ronda'] ); ?></strong> · <?php echo esc_html( asde_guardado( (string) $r['plazo'] ) ); ?></li>
		<li><?php esc_html_e( 'Collection date', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <strong><?php echo esc_html( $fmt_fecha( $r['fecha_cobro'] ) ); ?></strong></li>
		<li><strong><?php echo esc_html( Remesas::adeudos( (int) $r['adeudos'] ) ); ?></strong> · <strong><?php echo esc_html( Calculadora::euros( $r['total'] ) ); ?></strong></li>
	</ul>
	<p class="asde-muted">
		<?php esc_html_e( 'Created on', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <?php echo esc_html( $fmt_dt( $r['creada'] ) ); ?>.
		<?php echo esc_html( $r['avisada_en'] ? sprintf( /* translators: %s: text */ __( 'Families notified on %s. ', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $fmt_dt( $r['avisada_en'] ) ) : __( 'Families have not been notified yet. ', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>
		<?php echo $r['enviada_en'] ? esc_html( sprintf( /* translators: %s: text */ __( 'Sent to the bank on %s. ', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $fmt_dt( $r['enviada_en'] ) ) ) : ''; ?>
		<?php echo $r['descargas'] ? esc_html( sprintf( /* translators: %d: number */ _n( 'Downloaded %d time.', 'Downloaded %d times.', (int) $r['descargas'] , 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), (int) $r['descargas'] ) ) : ''; ?>
	</p>

	<?php if ( Remesas::ANULADA === $r['estado'] ) : ?>
		<div class="asde-notice asde-notice--info" role="status"><?php esc_html_e( 'Batch cancelled. Its data has been deleted.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></div>
	<?php elseif ( ! $lineas ) : ?>
		<div class="asde-notice asde-notice--info" role="status"><?php esc_html_e( 'The data of this batch\'s direct debits is no longer kept (it is deleted 14 months after the collection date).', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></div>
	<?php else : ?>
		<div class="asde-actions">
			<form method="post" data-asde-sin-carga>
				<?php wp_nonce_field( 'asde_sepa_remesa_' . (int) $r['id'] ); ?>
				<input type="hidden" name="asde_action" value="sepa_descargar">
				<input type="hidden" name="remesa" value="<?php echo (int) $r['id']; ?>">
				<button type="submit" class="button"><?php esc_html_e( 'Download the XML file', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
			</form>
			<form method="post" data-asde-confirmar="<?php echo esc_attr( __( 'An email will be sent to each family\'s accounts with the date and amount of the charge. Continue?', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
				<?php wp_nonce_field( 'asde_sepa_remesa_' . (int) $r['id'] ); ?>
				<input type="hidden" name="asde_action" value="sepa_avisar">
				<input type="hidden" name="remesa" value="<?php echo (int) $r['id']; ?>">
				<button type="submit" class="button asde-button-secundario"><?php echo $r['avisada_en'] ? esc_html( __( 'Notify the families again', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) : esc_html( __( 'Notify families by email', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?></button>
			</form>
			<?php if ( Remesas::GENERADA === $r['estado'] ) : ?>
				<form method="post" data-asde-confirmar="<?php echo esc_attr( __( 'Do you confirm that you have uploaded the file to the bank?', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
					<?php wp_nonce_field( 'asde_sepa_remesa_' . (int) $r['id'] ); ?>
					<input type="hidden" name="asde_action" value="sepa_enviada">
					<input type="hidden" name="remesa" value="<?php echo (int) $r['id']; ?>">
					<button type="submit" class="button asde-button-secundario"><?php esc_html_e( 'Mark as sent to the bank', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
				</form>
				<form method="post" data-asde-confirmar="<?php echo esc_attr( __( 'Cancel the batch? Its data will be deleted.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
					<?php wp_nonce_field( 'asde_sepa_remesa_' . (int) $r['id'] ); ?>
					<input type="hidden" name="asde_action" value="sepa_anular">
					<input type="hidden" name="remesa" value="<?php echo (int) $r['id']; ?>">
					<input type="hidden" name="ronda" value="<?php echo esc_attr( $r['ronda'] ); ?>">
					<button type="submit" class="asde-btn-link"><?php esc_html_e( 'Cancel', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
				</form>
			<?php endif; ?>
		</div>
		<?php if ( Remesas::GENERADA === $r['estado'] ) : ?>
			<p class="asde-help"><?php esc_html_e( 'The file carries the families\' accounts unencrypted: keep it only as long as necessary and delete it from the computer after uploading it to the bank.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
		<?php endif; ?>
	<?php endif; ?>
</section>

<?php if ( $lineas ) : ?>
	<section class="asde-card">
		<h3><?php esc_html_e( 'Debit charges', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h3>
		<?php $puede_cobros = Remesas::ENVIADA === $r['estado'] && $pendientes; ?>
		<?php if ( $puede_cobros ) : ?>
			<form method="post" class="asde-form">
				<?php wp_nonce_field( 'asde_sepa_remesa_' . (int) $r['id'] ); ?>
				<input type="hidden" name="asde_action" value="sepa_cobros">
				<input type="hidden" name="remesa" value="<?php echo (int) $r['id']; ?>">
				<input type="hidden" name="ronda" value="<?php echo esc_attr( $r['ronda'] ); ?>">
		<?php endif; ?>
		<table class="asde-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Family', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Members', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Holder · account', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Sequence', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Amount', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Status', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
					<?php if ( $puede_cobros ) : ?><th scope="col"><?php esc_html_e( 'Collected', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th><th scope="col"><?php esc_html_e( 'Returned', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th><?php endif; ?>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $lineas as $l ) : ?>
					<tr>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Family', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><a href="<?php echo esc_url( add_query_arg( array( 'familia' => (int) $l['familia'], 'ronda' => $r['ronda'] ), $url_fam ) ); ?>"><?php echo esc_html( $l['familia_n'] ); ?></a></td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Members', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo esc_html( $nombres( (int) $l['familia'] ) ); ?></td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Holder', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo esc_html( ( '' !== (string) ( $l['titular'] ?? '' ) ? $l['titular'] : '—' ) . ' · ' . ( $l['iban_mask'] ?? '' ) ); ?></td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Sequence', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo esc_html( $l['secuencia'] ); ?></td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Amount', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo esc_html( Calculadora::euros( $l['centimos'] / 100 ) ); ?></td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Status', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo esc_html( $estado_l[ $l['estado'] ] ?? $l['estado'] ); ?></td>
						<?php if ( $puede_cobros ) : ?>
							<?php if ( Remesas::PENDIENTE === $l['estado'] ) : ?>
								<td data-label="<?php echo esc_attr( esc_attr__( 'Collected', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><input type="checkbox" name="cobrada[]" value="<?php echo (int) $l['familia']; ?>" checked aria-label="Cobrado a <?php echo esc_attr( $l['familia_n'] ); ?>"></td>
								<td data-label="<?php echo esc_attr( esc_attr__( 'Returned', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><input type="checkbox" name="devuelta[]" value="<?php echo (int) $l['familia']; ?>" aria-label="Devuelto a <?php echo esc_attr( $l['familia_n'] ); ?>"></td>
							<?php else : ?>
								<td></td><td></td>
							<?php endif; ?>
						<?php endif; ?>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php if ( $puede_cobros ) : ?>
				<p>
					<label for="asde-sepa-cobro-fecha"><?php esc_html_e( 'Date the bank collected', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
					<input type="date" id="asde-sepa-cobro-fecha" name="fecha" value="<?php echo esc_attr( min( $r['fecha_cobro'], current_time( 'Y-m-d' ) ) ); ?>" max="<?php echo esc_attr( current_time( 'Y-m-d' ) ); ?>">
					<span class="asde-help"><?php esc_html_e( 'Tick "Collected" on those that have already left the account (they become the family\'s payments) and "Returned" on those the bank has returned; those you leave unticked remain pending.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span>
				</p>
				<p><button type="submit" class="button"><?php esc_html_e( 'Record the collections', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button></p>
			</form>
		<?php endif; ?>
	</section>
<?php endif; ?>
