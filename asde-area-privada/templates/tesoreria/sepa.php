<?php
/**
 * Tesorería · Domiciliaciones SEPA.
 *
 * @var string      $ronda
 * @var array       $cfg          Datos del acreedor (Sepa\Config::de)
 * @var string[]    $errores_cfg  Lo que falta o está mal en los datos del acreedor
 * @var bool        $ics_aviso    Las cifras de control del ICS no cuadran (solo aviso)
 * @var bool        $lista        Todo listo para domiciliar
 * @var array[]     $plazos       Plazos de la ronda
 * @var array[]     $familias     id => [id, numero, miembros]
 * @var array       $personas     nº Scouts de España => Persona
 * @var array[]     $mandatos     familia_id => autorización vigente
 * @var array[]     $remesas
 * @var array|null  $prep         Vista previa (Sepa\Remesas::preparar) si se ha pedido
 * @var string[]    $errores      Errores de la vista previa (fecha…)
 * @var string      $plazo        Plazo elegido ('' = todo lo pendiente)
 * @var string      $fecha        Fecha de cobro
 * @var string      $fecha_min
 * @var string      $concepto
 * @var array[]     $eventos      v0.36: eventos que se pueden cobrar (id => evento)
 * @var int         $ev_id        Evento elegido
 * @var array|null  $ev_prep      Vista previa de su remesa (Sepa\Remesas::preparar_evento)
 * @var string[]    $ev_errores
 * @var string      $ev_fecha
 * @var string      $ev_concepto
 * @var string      $url
 * @var string      $url_ronda
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Eventos\EventosTable;
use AsdeBalears\AreaPrivada\Sepa\Mandatos;
use AsdeBalears\AreaPrivada\Sepa\Remesas;
use AsdeBalears\AreaPrivada\Tesoreria\Calculadora;

defined( 'ABSPATH' ) || exit;

$fmt_fecha = static fn( string $f ) => '' !== $f ? wp_date( 'd/m/Y', (int) strtotime( $f ) ) : '';
$nombres   = static function ( array $f ) use ( $personas ): string {
	$n = array_map( static fn( $num ) => isset( $personas[ $num ] ) ? $personas[ $num ]->nombre : $num, $f['miembros'] );
	return implode( ', ', $n );
};
// La vista previa de una remesa (de la cuota o de un evento): los adeudos y lo que no se incluye.
$vista_previa = static function ( array $prep ) use ( $familias, $nombres ): void {
	?>
	<?php if ( $prep['lineas'] ) : ?>
		<table class="asde-table" data-asde-paginar>
			<thead><tr><th scope="col"><?php esc_html_e( 'Family', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th><th scope="col"><?php esc_html_e( 'Members', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th><th scope="col"><?php esc_html_e( 'Holder · account', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th><th scope="col"><?php esc_html_e( 'Sequence', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th><th scope="col"><?php esc_html_e( 'Amount', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th></tr></thead>
			<tbody>
				<?php foreach ( $prep['lineas'] as $l ) : ?>
					<tr>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Family', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo esc_html( $l['familia_n'] ); ?></td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Members', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo esc_html( $nombres( $familias[ $l['familia'] ] ?? array( 'miembros' => array() ) ) ); ?></td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Holder', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo esc_html( $l['titular'] . ' · ' . $l['iban_mask'] ); ?></td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Sequence', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo esc_html( 'FRST' === $l['secuencia'] ? __( 'First collection (FRST)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) : __( 'Recurrent (RCUR)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?></td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Amount', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo esc_html( Calculadora::euros( $l['centimos'] / 100 ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
			<tfoot><tr><th scope="row" colspan="4"><?php esc_html_e( 'Total ·', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <?php echo esc_html( Remesas::adeudos( count( $prep['lineas'] ) ) ); ?></th><td><strong><?php echo esc_html( Calculadora::euros( $prep['total'] / 100 ) ); ?></strong></td></tr></tfoot>
		</table>
	<?php endif; ?>
	<?php if ( $prep['omitidas'] ) : ?>
		<h4><?php esc_html_e( 'Not included', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h4>
		<ul>
			<?php foreach ( $prep['omitidas'] as $o ) : ?>
				<li><?php echo esc_html( sprintf( '%s (%s): %s', $o['familia_n'], Calculadora::euros( $o['centimos'] / 100 ), $o['motivo'] ) ); ?></li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
	<?php
};
?>
<section class="asde-card">
	<h2><?php esc_html_e( 'SEPA direct debits', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h2>
	<p><?php esc_html_e( 'Families authorise in "My payments" that their fee be collected by', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <strong><?php esc_html_e( 'SEPA direct debit', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></strong><?php esc_html_e( '. The files for the bank are generated here (XML format', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <code><?php esc_html_e( 'pain.008', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></code><?php esc_html_e( ', "Cuaderno 19.14"), families are notified of each charge and the collections are recorded.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
	<ol class="asde-lista-pasos">
		<li><strong><?php esc_html_e( 'Creditor details', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></strong> <?php esc_html_e( '(the group): name, creditor identifier (ICS) and IBAN. The bank provides them when direct debits are contracted.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></li>
		<li><?php esc_html_e( 'The families', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <strong><?php esc_html_e( 'sign their authorisation', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></strong> <?php esc_html_e( 'in "My payments".', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></li>
		<li><strong><?php esc_html_e( 'New batch', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></strong><?php esc_html_e( ': you choose the instalment and the collection date, review what is charged to each family and download the file.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></li>
		<li><?php esc_html_e( 'Upload it to the bank (Colonya Directa: "Direct debit batches") and mark it as', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <strong><?php esc_html_e( 'sent', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></strong>. <strong><?php esc_html_e( 'Notify the families', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></strong> <?php esc_html_e( 'with the days of notice you set in the creditor details.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></li>
		<li><?php esc_html_e( 'When the bank collects,', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <strong><?php esc_html_e( 'records the collections', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></strong><?php esc_html_e( ': they become the family\'s payments. Tick the returned ones.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></li>
	</ol>
</section>

<section class="asde-card">
	<h3><?php esc_html_e( 'Creditor details', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h3>
	<?php if ( $errores_cfg ) : ?>
		<div class="asde-notice asde-notice--warning" role="status">
			<strong><?php esc_html_e( 'Still to complete:', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></strong>
			<ul><?php foreach ( $errores_cfg as $e ) : ?><li><?php echo esc_html( $e ); ?></li><?php endforeach; ?></ul>
		</div>
	<?php elseif ( $ics_aviso ) : ?>
		<div class="asde-notice asde-notice--warning" role="status"><?php esc_html_e( 'The check digits of the creditor identifier do not match its tax ID: check that you copied it exactly as the bank gives it. (This is only a warning.)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></div>
	<?php endif; ?>
	<form method="post" class="asde-form">
		<?php wp_nonce_field( 'asde_sepa_config' ); ?>
		<input type="hidden" name="asde_action" value="sepa_config">
		<input type="hidden" name="ronda" value="<?php echo esc_attr( $ronda ); ?>">
		<div class="asde-grid-2">
			<p><label for="asde-sepa-nombre"><?php esc_html_e( 'Creditor\'s name', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label><input type="text" id="asde-sepa-nombre" name="nombre" maxlength="70" value="<?php echo esc_attr( $cfg['nombre'] ); ?>" placeholder="<?php echo esc_attr( esc_attr__( 'e.g. Grupo Scout Myotragus 684', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><span class="asde-help"><?php esc_html_e( 'As it will appear in the families\' bank (without accents).', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span></p>
			<p><label for="asde-sepa-ics"><?php esc_html_e( 'Creditor identifier (ICS)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label><input type="text" id="asde-sepa-ics" name="ics" maxlength="35" value="<?php echo esc_attr( $cfg['ics'] ); ?>" placeholder="<?php echo esc_attr( esc_attr__( 'ES00ZZZG00000000', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><span class="asde-help"><?php esc_html_e( 'It starts with ES and has 16 characters.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span></p>
			<p><label for="asde-sepa-iban"><?php esc_html_e( 'Creditor IBAN', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label><input type="text" id="asde-sepa-iban" name="iban" maxlength="40" value="" placeholder="<?php echo '' !== $cfg['iban'] ? esc_attr( \AsdeBalears\AreaPrivada\Sepa\Iban::enmascarar( $cfg['iban'] ) . __( ' (saved; leave it empty to keep it)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) : 'ES00 0000 0000 0000 0000 0000'; ?>" autocomplete="off"><span class="asde-help"><?php esc_html_e( 'The account where the bank deposits what is collected. It is stored encrypted.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span></p>
			<p><label for="asde-sepa-bic"><?php esc_html_e( 'BIC (optional)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label><input type="text" id="asde-sepa-bic" name="bic" maxlength="11" value="<?php echo esc_attr( $cfg['bic'] ); ?>"></p>
			<p>
				<span class="asde-label"><?php esc_html_e( 'Creditor\'s address', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span>
				<?php $dir_acreedor = trim( implode( ', ', array_filter( array( $cfg['calle'], trim( $cfg['cp'] . ' ' . $cfg['poblacion'] ), $cfg['provincia'] ) ) ) ); ?>
				<span class="asde-valor"><?php echo esc_html( '' !== $dir_acreedor ? $dir_acreedor : '–' ); ?></span>
				<span class="asde-help"><?php esc_html_e( 'Comes out of', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <a href="<?php echo esc_url( \AsdeBalears\AreaPrivada\Frontend\Pages::url( 'grupo_datos' ) ); ?>"><?php esc_html_e( 'Group details', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a>.</span>
			</p>
			<p><label for="asde-sepa-aviso"><?php esc_html_e( 'Days of notice before each charge', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label><input type="number" id="asde-sepa-aviso" name="dias_aviso" min="1" max="30" value="<?php echo (int) $cfg['dias_aviso']; ?>"><span class="asde-help"><?php esc_html_e( 'The rule requires 14 days unless another period is agreed: here it is agreed and appears on the authorisation.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span></p>
			<p><label for="asde-sepa-concepto"><?php esc_html_e( 'Description of the charges', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label><input type="text" id="asde-sepa-concepto" name="concepto" maxlength="90" value="<?php echo esc_attr( $cfg['concepto'] ); ?>"><span class="asde-help"><?php esc_html_e( 'You can use {grupo} and {ronda}. The family no. is added at the end.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span></p>
		</div>
		<p>
			<label class="asde-choice"><input type="checkbox" name="activo" value="1" <?php checked( $cfg['activo'] ); ?>><span><?php esc_html_e( 'Offer direct debit to families (appears in "My payments")', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span></label>
		</p>
		<p><button type="submit" class="button"><?php esc_html_e( 'Save the creditor details', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button></p>
	</form>
</section>

<?php if ( $lista ) : ?>
	<section class="asde-card">
		<h3><?php esc_html_e( 'New batch · round', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <?php echo esc_html( $ronda ); ?></h3>
		<form method="get" action="<?php echo esc_url( $url ); ?>" class="asde-form">
			<input type="hidden" name="ronda" value="<?php echo esc_attr( $ronda ); ?>">
			<input type="hidden" name="preparar" value="1">
			<div class="asde-grid-2">
				<p>
					<label for="asde-sepa-plazo"><?php esc_html_e( 'What is charged', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
					<select id="asde-sepa-plazo" name="plazo">
						<option value="" <?php selected( $plazo, '' ); ?>><?php esc_html_e( 'Everything pending in the round', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></option>
						<?php foreach ( $plazos as $i => $pl ) : ?>
							<option value="<?php echo (int) $i; ?>" <?php selected( $plazo, (string) $i ); ?>><?php echo esc_html( sprintf( /* translators: 1: text, 2: text, 3: text */ __( '%1$s (until %2$s, %3$s %%)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), asde_guardado( (string) $pl['nombre'] ), $fmt_fecha( $pl['fecha'] ), number_format_i18n( (float) $pl['porcentaje'], 0 ) ) ); ?></option>
						<?php endforeach; ?>
					</select>
					<span class="asde-help"><?php esc_html_e( 'With an instalment, each family is charged what it is missing up to that instalment (counting what it has already paid).', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span>
				</p>
				<p>
					<label for="asde-sepa-fecha"><?php esc_html_e( 'Collection date', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
					<input type="date" id="asde-sepa-fecha" name="fecha" value="<?php echo esc_attr( $fecha ); ?>" min="<?php echo esc_attr( $fecha_min ); ?>">
					<span class="asde-help"><?php esc_html_e( 'Working day, at the earliest', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <?php echo esc_html( $fmt_fecha( $fecha_min ) ); ?>.</span>
				</p>
				<p class="asde-grid-span">
					<label for="asde-sepa-concepto-r"><?php esc_html_e( 'Description', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
					<input type="text" id="asde-sepa-concepto-r" name="concepto" maxlength="140" value="<?php echo esc_attr( $concepto ); ?>">
				</p>
			</div>
			<p><button type="submit" class="button"><?php esc_html_e( 'See what would be charged', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button></p>
		</form>

		<?php if ( $prep ) : ?>
			<hr>
			<h4><?php esc_html_e( 'Preview', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h4>
			<?php foreach ( $errores as $e ) : ?>
				<div class="asde-notice asde-notice--error" role="alert"><?php echo esc_html( $e ); ?></div>
			<?php endforeach; ?>
			<?php if ( ! $prep['lineas'] ) : ?>
				<p><?php esc_html_e( 'There is no direct debit to include: no family with an authorisation has anything pending', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <?php echo '' !== $plazo ? esc_html( __( 'up to that instalment', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) : ''; ?>.</p>
			<?php endif; ?>
			<?php $vista_previa( $prep ); ?>
			<?php if ( $prep['lineas'] && ! $errores ) : ?>
				<form method="post" class="asde-form">
					<?php wp_nonce_field( 'asde_sepa_generar' ); ?>
					<input type="hidden" name="asde_action" value="sepa_generar">
					<input type="hidden" name="ronda" value="<?php echo esc_attr( $ronda ); ?>">
					<input type="hidden" name="plazo" value="<?php echo esc_attr( $plazo ); ?>">
					<input type="hidden" name="fecha" value="<?php echo esc_attr( $fecha ); ?>">
					<input type="hidden" name="concepto" value="<?php echo esc_attr( $concepto ); ?>">
					<p><button type="submit" class="button"><?php esc_html_e( 'Create the batch and prepare the file', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button></p>
				</form>
			<?php endif; ?>
		<?php endif; ?>
	</section>

	<?php if ( $eventos ) : ?>
		<section class="asde-card">
			<h3><?php esc_html_e( 'Collect for an event', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h3>
			<p class="asde-help"><?php esc_html_e( 'One direct debit per family with an authorisation, with everything pending from its active sign-ups in the event (siblings together). When the collections are recorded, the amount is split among its sign-ups.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
			<form method="get" action="<?php echo esc_url( $url ); ?>" class="asde-form">
				<input type="hidden" name="ronda" value="<?php echo esc_attr( $ronda ); ?>">
				<input type="hidden" name="preparar_evento" value="1">
				<div class="asde-grid-2">
					<p class="asde-grid-span">
						<label for="asde-sepa-evento"><?php esc_html_e( 'Event', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
						<select id="asde-sepa-evento" name="evento_sepa">
							<?php foreach ( $eventos as $id => $ev ) : ?>
								<option value="<?php echo (int) $id; ?>" <?php selected( $ev_id, (int) $id ); ?>><?php echo esc_html( EventosTable::numero( $ev ) . ' · ' . $ev['nombre'] . ' · ' . EventosTable::cuando( $ev ) ); ?></option>
							<?php endforeach; ?>
						</select>
					</p>
					<p>
						<label for="asde-sepa-fecha-evento"><?php esc_html_e( 'Collection date', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
						<input type="date" id="asde-sepa-fecha-evento" name="fecha_evento" value="<?php echo esc_attr( $ev_fecha ); ?>" min="<?php echo esc_attr( $fecha_min ); ?>">
					</p>
					<p>
						<label for="asde-sepa-concepto-evento"><?php esc_html_e( 'Description', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
						<input type="text" id="asde-sepa-concepto-evento" name="concepto_evento" maxlength="140" value="<?php echo esc_attr( $ev_concepto ); ?>" placeholder="<?php echo esc_attr( esc_attr__( 'By default, the number and name of the event', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
					</p>
				</div>
				<p><button type="submit" class="button"><?php esc_html_e( 'See what would be charged', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button></p>
			</form>

			<?php if ( $ev_prep ) : ?>
				<hr>
				<h4><?php esc_html_e( 'Preview', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h4>
				<?php foreach ( $ev_errores as $error ) : ?>
					<div class="asde-notice asde-notice--error" role="alert"><?php echo esc_html( $error ); ?></div>
				<?php endforeach; ?>
				<?php if ( ! $ev_prep['lineas'] ) : ?>
					<p><?php esc_html_e( 'There is no direct debit to include: no family with an authorisation has anything pending in this event.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
				<?php endif; ?>
				<?php $vista_previa( $ev_prep ); ?>
				<?php if ( $ev_prep['lineas'] && ! $ev_errores ) : ?>
					<form method="post" class="asde-form">
						<?php wp_nonce_field( 'asde_sepa_generar_evento' ); ?>
						<input type="hidden" name="asde_action" value="sepa_generar_evento">
						<input type="hidden" name="ronda" value="<?php echo esc_attr( $ronda ); ?>">
						<input type="hidden" name="evento" value="<?php echo (int) $ev_id; ?>">
						<input type="hidden" name="fecha" value="<?php echo esc_attr( $ev_fecha ); ?>">
						<input type="hidden" name="concepto" value="<?php echo esc_attr( $ev_concepto ); ?>">
						<p><button type="submit" class="button"><?php esc_html_e( 'Create the batch and prepare the file', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button></p>
					</form>
				<?php endif; ?>
			<?php endif; ?>
		</section>
	<?php endif; ?>
<?php endif; ?>

<section class="asde-card">
	<h3><?php esc_html_e( 'Batches', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h3>
	<?php if ( ! $remesas ) : ?>
		<p class="asde-muted"><?php esc_html_e( 'No batch has been created yet.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
	<?php else : ?>
		<table class="asde-table" data-asde-paginar>
			<thead><tr><th scope="col"><?php esc_html_e( 'Batch', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th><th scope="col"><?php esc_html_e( 'Round · instalment', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th><th scope="col"><?php esc_html_e( 'Collection', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th><th scope="col"><?php esc_html_e( 'Debit charges', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th><th scope="col"><?php esc_html_e( 'Total', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th><th scope="col"><?php esc_html_e( 'Status', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th></tr></thead>
			<tbody>
				<?php foreach ( $remesas as $r ) : ?>
					<tr>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Batch', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><a href="<?php echo esc_url( add_query_arg( array( 'ronda' => $r['ronda'], 'remesa' => $r['id'] ), $url ) ); ?>"><?php echo esc_html( $r['numero'] ); ?></a></td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Round', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo esc_html( $r['ronda'] . ' · ' . asde_guardado( (string) $r['plazo'] ) ); ?></td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Collection', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo esc_html( $fmt_fecha( $r['fecha_cobro'] ) ); ?></td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Debit charges', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo (int) $r['adeudos']; ?></td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Total', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo esc_html( Calculadora::euros( $r['total'] ) ); ?></td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Status', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo esc_html( ( Remesas::estados()[ $r['estado'] ] ?? $r['estado'] ) ); ?><?php echo Remesas::ENVIADA === $r['estado'] && $r['cobros_en'] ? ' · cobrada' : ''; ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</section>

<section class="asde-card">
	<h3><?php esc_html_e( 'Families\' authorisations', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h3>
	<?php
	$con = 0;
	foreach ( $familias as $id => $f ) {
		$con += isset( $mandatos[ $id ] ) ? 1 : 0;
	}
	?>
	<p><?php echo (int) $con; ?> <?php esc_html_e( 'of', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <?php echo (int) count( $familias ); ?> <?php esc_html_e( 'families have a valid authorisation.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
	<?php if ( $familias ) : ?>
		<table class="asde-table" data-asde-paginar>
			<thead><tr><th scope="col"><?php esc_html_e( 'Family', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th><th scope="col"><?php esc_html_e( 'Members', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th><th scope="col"><?php esc_html_e( 'Authorisation', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th><th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span></th></tr></thead>
			<tbody>
				<?php foreach ( $familias as $id => $f ) : ?>
					<?php $m = $mandatos[ $id ] ?? null; ?>
					<tr>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Family', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo esc_html( $f['numero'] ); ?></td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Members', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo esc_html( $nombres( $f ) ); ?></td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Authorisation', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
							<?php if ( $m ) : ?>
								<?php echo esc_html( sprintf( /* translators: 1: text, 2: text, 3: text */ __( '%1$s · %2$s · signed on %3$s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $m['referencia'], $m['iban_mask'], $fmt_fecha( $m['fecha_firma'] ) ) ); ?>
								<?php $cuenta = null !== $cuentas ? ( $cuentas[ $id ] ?? null ) : false; ?>
								<?php if ( $cuenta ) : ?>
									<br><span class="asde-muted"><?php echo esc_html( sprintf( /* translators: %s: text */ __( 'Holder: %s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $cuenta['titular'] ) ); ?></span>
								<?php elseif ( null === $cuenta ) : ?>
									<br><span class="asde-help asde-help--warning"><?php esc_html_e( '⚠️ The account on their CRM record is not the authorised one (or is missing): they will not be charged until they sign again.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span>
								<?php endif; ?>
								<?php echo null === $m['usado_en'] ? '<br><span class="asde-muted">' . esc_html( __( 'Not used yet: the first collection will go as FRST.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) . '</span>' : ''; ?>
							<?php else : ?>
								<span class="asde-muted"><?php esc_html_e( 'No authorisation', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span>
							<?php endif; ?>
						</td>
						<td data-label="">
							<?php if ( $m ) : ?>
								<form method="post" class="asde-actions" data-asde-confirmar="<?php echo esc_attr( __( 'Cancel this authorisation?', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
									<?php wp_nonce_field( 'asde_sepa_revocar' ); ?>
									<input type="hidden" name="asde_action" value="sepa_revocar">
									<input type="hidden" name="ronda" value="<?php echo esc_attr( $ronda ); ?>">
									<input type="hidden" name="mandato" value="<?php echo (int) $m['id']; ?>">
									<input type="text" name="motivo" maxlength="80" placeholder="<?php echo esc_attr( esc_attr__( 'Reason', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>" aria-label="<?php echo esc_attr( esc_attr__( 'Reason for the cancellation', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
									<button type="submit" class="asde-btn-link"><?php esc_html_e( 'Cancel', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
								</form>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
	<p class="asde-help"><?php esc_html_e( 'The account (IBAN, holder and bank) is stored on the Scouts de España CRM record of each family member; the website stores the authorisation (who signed it and when, and the address), the masked IBAN and a fingerprint to check, before collecting, that the CRM account is still the authorised one. The authorisation is kept while it is in force and for 14 months after it is cancelled, as the SEPA rules require.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
	<?php if ( $locales ) : ?>
		<form method="post" class="asde-form" data-asde-confirmar="<?php echo esc_attr( __( 'Move the authorisations\' accounts to the CRM records? They are written to the record of each family member and deleted from the website.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
			<?php wp_nonce_field( 'asde_sepa_cuentas_crm' ); ?>
			<input type="hidden" name="asde_action" value="sepa_cuentas_crm">
			<input type="hidden" name="ronda" value="<?php echo esc_attr( $ronda ); ?>">
			<p class="asde-help asde-help--warning"><?php esc_html_e( 'There are authorisations signed before version 0.33 that store the account on the website. Move them to the CRM records: since then the website no longer stores any account.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
			<p><button type="submit" class="button"><?php esc_html_e( 'Move the accounts to the CRM', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button></p>
		</form>
	<?php endif; ?>
</section>
