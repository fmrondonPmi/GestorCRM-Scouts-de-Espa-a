<?php
/**
 * Evento · presupuesto: gastos e ingresos previstos, el precio que sale para cada
 * sección (coste repartido + fondo de emergencia) y la validación de Tesorería.
 *
 * @var array                               $e
 * @var AsdeBalears\AreaPrivada\Auth\Ambito $ambito
 * @var array[]                             $partidas
 * @var array                               $calculo  Presupuesto::calcular (o el validado, guardado)
 * @var string                              $url
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Domain\Secciones;
use AsdeBalears\AreaPrivada\Eventos\PartidasTable;
use AsdeBalears\AreaPrivada\Eventos\Normativa;
use AsdeBalears\AreaPrivada\Eventos\Presupuesto;
use AsdeBalears\AreaPrivada\Eventos\Servicio;
use AsdeBalears\AreaPrivada\Tesoreria\Calculadora;

defined( 'ABSPATH' ) || exit;

$editable = Servicio::puede_editar_presupuesto( $e, $ambito );
$campos   = static function ( string $accion ) use ( $e ): string {
	return wp_nonce_field( 'asde_evento_' . $e['id'], '_wpnonce', true, false )
		. '<input type="hidden" name="asde_action" value="' . esc_attr( $accion ) . '">'
		. '<input type="hidden" name="evento" value="' . (int) $e['id'] . '">'
		. '<input type="hidden" name="pestana" value="presupuesto">';
};
$quien    = static fn( int $id ): string => $id && ( $u = get_userdata( $id ) ) ? $u->display_name : '';
$reparto  = static function ( array $p ): string {
	if ( PartidasTable::INGRESO === $p['tipo'] ) {
		return __( 'Deducted from the shared amount', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' );
	}
	$texto = ( Presupuesto::repartos()[ $p['reparto'] ] ?? $p['reparto'] );
	return '' !== $p['seccion'] ? sprintf( /* translators: 1: text, 2: text */ __( '%1$s (%2$s)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $texto, Secciones::nombre( $p['seccion'] ) ) : $texto;
};
?>
<?php if ( ! $e['con_coste'] ) : ?>
	<section class="asde-card">
		<p><?php esc_html_e( 'This event has no cost for families. If it does, tick it in "Edit the details" and prepare the budget here.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
	</section>
	<?php return; ?>
<?php endif; ?>

<section class="asde-card">
	<h3 class="asde-card__title"><?php esc_html_e( 'Budget', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h3>
	<?php if ( null !== $e['validado_en'] ) : ?>
		<p class="asde-notice asde-notice--success">
			<?php echo esc_html( sprintf( '' !== $quien( $e['validado_por'] ) ? /* translators: 1: text, 2: text */ __( 'Validated by %1$s on %2$s: the price per section is now fixed.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) : /* translators: %2$s: text */ __( 'Validated on %2$s: the price per section is now fixed.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $quien( $e['validado_por'] ), wp_date( 'd/m/Y', strtotime( $e['validado_en'] . ' UTC' ) ) ) ); ?>
		</p>
	<?php elseif ( null !== $e['validacion_pedida_en'] ) : ?>
		<p class="asde-notice asde-notice--info"><?php echo esc_html( sprintf( /* translators: %s: text */ __( 'Sent to Treasury on %s: it still needs to be validated.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), wp_date( 'd/m/Y', strtotime( $e['validacion_pedida_en'] . ' UTC' ) ) ) ); ?></p>
	<?php else : ?>
		<p class="asde-muted"><?php esc_html_e( 'Enter the planned expenses and how each one is split. Each section\'s price comes from the cost (the team\'s, split among the participants) plus the emergency fund margin, rounded to the euro. When it is ready, send it to Treasury to validate.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
	<?php endif; ?>

	<?php if ( $partidas ) : ?>
		<table class="asde-table asde-table--partidas">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Description', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
					<th scope="col"><?php esc_html_e( 'How it is split', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Amount', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
					<?php if ( $editable ) : ?>
						<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Remove', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span></th>
					<?php endif; ?>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $partidas as $p ) : ?>
					<tr>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Description', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo esc_html( $p['concepto'] ); ?><?php echo PartidasTable::INGRESO === $p['tipo'] ? ' <span class="asde-badge asde-badge--ingreso">' . esc_html( __( 'Income', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) . '</span>' : ''; ?></td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'How it is split', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo esc_html( $reparto( $p ) ); ?></td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Amount', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo esc_html( Calculadora::euros( $p['importe'] ) . ( in_array( $p['reparto'], array( 'participante', 'participante_equipo', 'equipo' ), true ) && PartidasTable::GASTO === $p['tipo'] ? ' ' . __( 'per person', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) : '' ) ); ?></td>
						<?php if ( $editable ) : ?>
							<td data-label="<?php echo esc_attr( esc_attr__( 'Remove', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
								<form method="post" class="asde-inline-form">
									<?php echo wp_kses( $campos( 'evento_partida_quitar' ), asde_html_permitido() ); ?>
									<input type="hidden" name="partida" value="<?php echo (int) $p['id']; ?>">
									<button type="submit" class="asde-btn-link"><?php esc_html_e( 'Remove', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
								</form>
							</td>
						<?php endif; ?>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php else : ?>
		<p><?php esc_html_e( 'There are no planned expenses yet.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
	<?php endif; ?>
</section>

<?php if ( $editable ) : ?>
	<section class="asde-card">
		<h3 class="asde-card__title"><?php esc_html_e( 'Add an item', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h3>
		<form method="post" class="asde-form asde-form--compacta">
			<?php echo wp_kses( $campos( 'evento_partida_anadir' ), asde_html_permitido() ); ?>
			<fieldset>
				<legend class="screen-reader-text"><?php esc_html_e( 'Expense or income', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></legend>
				<label class="asde-choice asde-choice--inline"><input type="radio" name="tipo" value="gasto" checked><span><?php esc_html_e( 'Expense', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span></label>
				<label class="asde-choice asde-choice--inline"><input type="radio" name="tipo" value="ingreso"><span><?php esc_html_e( 'Income (grant, group contribution)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span></label>
			</fieldset>
			<p class="asde-form__fila">
				<label><?php esc_html_e( 'Description', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <input type="text" name="concepto" maxlength="160" required list="asde-partidas-sugeridas" placeholder="<?php echo esc_attr( esc_attr__( 'e.g. Bus', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
					<datalist id="asde-partidas-sugeridas">
						<?php foreach ( Presupuesto::SUGERIDAS as $sugerida ) : ?>
							<option value="<?php echo esc_attr( asde_guardado( $sugerida ) ); ?>"></option>
						<?php endforeach; ?>
					</datalist></label>
				<label><?php esc_html_e( 'Amount (€)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <input type="text" name="importe" inputmode="decimal" size="10" required></label>
			</p>
			<p class="asde-form__fila">
				<label><?php esc_html_e( 'How it is split (the expenses)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?>
					<select name="reparto">
						<?php foreach ( ( Presupuesto::repartos() ) as $k => $etiqueta ) : ?>
							<option value="<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $etiqueta ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label><?php esc_html_e( 'Section (if only one)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?>
					<select name="seccion">
						<option value=""><?php esc_html_e( '— All —', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></option>
						<?php foreach ( $e['secciones'] as $s ) : ?>
							<option value="<?php echo esc_attr( $s ); ?>"><?php echo esc_html( Secciones::nombre( $s ) ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
			</p>
			<p><button type="submit" class="button"><?php esc_html_e( 'Add the item', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button></p>
			<p class="asde-help"><?php esc_html_e( '"Per person: participants and team" is what each person costs (hostel, food): the team\'s is split among the participants. "Team only" is what one scouter costs (e.g. their place at an external event).', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
		</form>
	</section>

	<section class="asde-card">
		<h3 class="asde-card__title"><?php esc_html_e( 'Buses', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h3>
		<?php
		$viajan = max( 0, Normativa::participantes( $e ) ) + (int) $e['equipo_previsto'];
		?>
		<form method="post" class="asde-form asde-form--compacta">
			<?php echo wp_kses( $campos( 'evento_autobuses' ), asde_html_permitido() ); ?>
			<p class="asde-form__fila">
				<label><?php esc_html_e( 'People travelling', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <input type="number" name="personas" value="<?php echo (int) $viajan; ?>" min="1" max="9999" inputmode="numeric" required></label>
				<label><?php esc_html_e( 'Places on each bus', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <input type="number" name="plazas" value="<?php echo (int) Presupuesto::PLAZAS_AUTOBUS; ?>" min="1" max="200" inputmode="numeric" required></label>
				<label><?php esc_html_e( 'Price of one bus (€)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <input type="text" name="precio" inputmode="decimal" size="10" required></label>
			</p>
			<p class="asde-help"><?php echo esc_html( sprintf( /* translators: 2: number, 1: number, 3: number */ _n( 'With %2$d places, %1$d person needs %3$d bus.', 'With %2$d places, %1$d people need %3$d buses.', $viajan , 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $viajan, Presupuesto::PLAZAS_AUTOBUS, Presupuesto::autobuses( $viajan ) ) ); ?></p>
			<p><button type="submit" class="button"><?php esc_html_e( 'Add the bus item', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button></p>
		</form>
	</section>

	<section class="asde-card">
		<h3 class="asde-card__title"><?php esc_html_e( 'Forecast', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h3>
		<form method="post" class="asde-form asde-form--compacta">
			<?php echo wp_kses( $campos( 'evento_prevision' ), asde_html_permitido() ); ?>
			<p class="asde-form__fila">
				<label><?php esc_html_e( 'Expected team members', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <input type="number" name="equipo_previsto" value="<?php echo (int) $e['equipo_previsto']; ?>" min="0" max="999" inputmode="numeric"></label>
				<label><?php esc_html_e( 'Emergency fund margin (%)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <input type="text" name="margen_pct" value="<?php echo esc_attr( number_format( $e['margen_pct'], 2, ',', '' ) ); ?>" inputmode="decimal" size="6"></label>
			</p>
			<label class="asde-choice"><input type="checkbox" name="margen_activo" value="1" <?php checked( $e['margen_activo'] ); ?>><span><?php esc_html_e( 'Apply the emergency fund margin', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span></label>
			<p><button type="submit" class="button"><?php esc_html_e( 'Save and recalculate', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button></p>
			<p class="asde-help"><?php esc_html_e( 'The expected participants of each section are changed in "Edit the details".', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
		</form>
	</section>
<?php endif; ?>

<section class="asde-card">
	<h3 class="asde-card__title"><?php echo esc_html( null !== $e['validado_en'] ? __( 'Validated price', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) : __( 'Resulting price', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?></h3>
	<?php if ( ! empty( $calculo['errores'] ) ) : ?>
		<ul class="asde-comprobacion">
			<?php foreach ( $calculo['errores'] as $error ) : ?>
				<li class="asde-comprobacion--aviso"><span class="asde-comprobacion__marca" aria-hidden="true">!</span> <?php echo esc_html( $error ); ?></li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
	<?php if ( ! empty( $calculo['precios'] ) ) : ?>
		<table class="asde-table asde-table--precios">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Section', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Expected participants', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Cost per participant', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Price', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $calculo['precios'] as $s => $precio ) : ?>
					<tr>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Section', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo wp_kses( Secciones::badge( (string) $s ), asde_html_permitido() ); ?></td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Expected participants', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo (int) ( $calculo['previstos'][ $s ] ?? 0 ); ?></td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Cost per participant', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo esc_html( Calculadora::euros( (float) ( $calculo['costes'][ $s ] ?? 0 ) ) ); ?></td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Price', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><strong><?php echo esc_html( Calculadora::euros( (float) $precio ) ); ?></strong></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<dl class="asde-datos">
			<div><dt><?php esc_html_e( 'Planned expenses', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></dt><dd><?php echo esc_html( Calculadora::euros( (float) $calculo['gastos'] ) ); ?></dd></div>
			<?php if ( (float) $calculo['ingresos'] > 0 ) : ?>
				<div><dt><?php esc_html_e( 'Planned income', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></dt><dd><?php echo esc_html( Calculadora::euros( (float) $calculo['ingresos'] ) ); ?></dd></div>
			<?php endif; ?>
			<div><dt><?php esc_html_e( 'Expected revenue', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></dt><dd><?php echo esc_html( Calculadora::euros( (float) $calculo['recaudacion'] ) ); ?></dd></div>
			<div><dt><?php esc_html_e( 'Planned emergency fund', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></dt><dd><?php echo esc_html( Calculadora::euros( (float) $calculo['fondo'] ) ); ?> <span class="asde-muted"><?php echo esc_html( (float) $calculo['margen'] > 0 ? sprintf( /* translators: %s: text */ __( '(%s %% margin and rounding)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), number_format_i18n( (float) $calculo['margen'], 2 ) ) : __( '(rounding only: margin off)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?></span></dd></div>
			<div><dt><?php esc_html_e( 'Team', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></dt><dd><?php echo esc_html( sprintf( /* translators: %d: number */ _n( '%d person who does not pay: their cost is spread across the price.', '%d people who do not pay: their cost is spread across the price.', (int) $calculo['equipo'] , 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), (int) $calculo['equipo'] ) ); ?></dd></div>
		</dl>
		<p class="asde-help"><?php esc_html_e( 'The price is calculated with the expected participants and, once validated, does not change: any surplus or shortfall is borne by the group.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
	<?php endif; ?>

	<?php
	$botones = array();
	if ( Servicio::puede_pedir_validacion( $e, $ambito ) ) {
		$botones[] = '<form method="post" class="asde-inline-form">' . $campos( 'evento_pedir_validacion' ) . '<button type="submit" class="button">' . esc_html( __( 'Send to Treasury for validation', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) . '</button></form>';
	}
	if ( Servicio::puede_validar( $e ) ) {
		$botones[] = '<form method="post" class="asde-inline-form" data-asde-confirmar="' . esc_attr( __( 'Validate the budget? The price per section will be fixed.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) . '">' . $campos( 'evento_validar' ) . '<button type="submit" class="button button-primary">' . esc_html( __( 'Validate the budget', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) . '</button></form>';
	}
	if ( Servicio::puede_reabrir( $e ) ) {
		$botones[] = '<form method="post" class="asde-inline-form" data-asde-confirmar="' . esc_attr( __( 'Reopen the budget? It will have to be validated again before publishing.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) . '">' . $campos( 'evento_reabrir' ) . '<button type="submit" class="asde-btn-link">' . esc_html( __( 'Reopen the budget', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) . '</button></form>';
	}
	?>
	<?php if ( $botones ) : ?>
		<p class="asde-actions"><?php echo wp_kses( implode( ' ', $botones ), asde_html_permitido() ); ?></p>
	<?php endif; ?>
</section>
