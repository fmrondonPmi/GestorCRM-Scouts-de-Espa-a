<?php
/**
 * Formulario para editar un evento (crear es el asistente: asistente.php, doc 66).
 *
 * @var array     $e         El evento que se edita
 * @var ?array    $v         Lo escrito, si no se ha podido guardar
 * @var array     $cfg
 * @var string[]  $secciones Secciones que puede convocar
 * @var string[]  $ambitos   Ámbitos que puede elegir
 * @var bool      $validado  Presupuesto validado: secciones, previstos y coste no se cambian
 * @var string    $url
 * @var string    $volver
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Domain\Secciones;
use AsdeBalears\AreaPrivada\Eventos\Config;
use AsdeBalears\AreaPrivada\Eventos\EventosTable;
use AsdeBalears\AreaPrivada\Eventos\Formulario;
use AsdeBalears\AreaPrivada\Eventos\Normativa;

defined( 'ABSPATH' ) || exit;

$tipos = Formulario::tipos( $cfg, $e );
$d     = Formulario::valores( $e, $v, $cfg, $secciones );
$accion = add_query_arg(
	array(
		'evento' => $e['id'],
		'editar' => '1',
	),
	$url
);
?>
<p class="asde-back"><a href="<?php echo esc_url( $volver ); ?>"><?php echo esc_html( '← ' . $e['nombre'] ); ?></a></p>

<form method="post" class="asde-form asde-form--evento" action="<?php echo esc_url( $accion ); ?>">
	<?php wp_nonce_field( 'asde_evento_' . $e['id'] ); ?>
	<input type="hidden" name="asde_action" value="evento_guardar">
	<input type="hidden" name="evento" value="<?php echo (int) $e['id']; ?>">

	<section class="asde-card">
		<h2 class="asde-card__title"><?php echo esc_html( sprintf( /* translators: %s: text */ __( 'Edit %s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), EventosTable::numero( $e ) ) ); ?></h2>
		<p class="asde-form__fila">
			<label><?php esc_html_e( 'Activity type', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?>
				<select name="tipo" required>
					<?php foreach ( $tipos as $t => $def ) : ?>
						<option value="<?php echo esc_attr( $t ); ?>" <?php selected( $d['tipo'], $t ); ?>><?php echo esc_html( Config::nombre_tipo( $cfg, $t ) ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<label class="asde-grid-span"><?php esc_html_e( 'Name', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <input type="text" name="nombre" value="<?php echo esc_attr( $d['nombre'] ); ?>" maxlength="120" required placeholder="<?php echo esc_attr( esc_attr__( 'e.g. Autumn camp', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"></label>
		</p>

		<?php if ( count( $ambitos ) > 1 ) : ?>
			<fieldset>
				<legend><?php esc_html_e( 'Scope', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></legend>
				<?php foreach ( $ambitos as $am ) : ?>
					<label class="asde-choice"><input type="radio" name="ambito" value="<?php echo esc_attr( $am ); ?>" <?php checked( $d['ambito'], $am ); ?>><span><?php echo esc_html( ( EventosTable::ambitos()[ $am ] ) ); ?></span></label>
				<?php endforeach; ?>
			</fieldset>
		<?php else : ?>
			<input type="hidden" name="ambito" value="<?php echo esc_attr( $ambitos[0] ?? 'secciones' ); ?>">
		<?php endif; ?>

		<fieldset>
			<legend><?php esc_html_e( 'Invited sections', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></legend>
			<?php if ( $validado ) : ?>
				<p class="asde-help"><?php esc_html_e( 'The event is published and the price is fixed: the sections and expected participants can no longer be changed.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
				<?php foreach ( $e['secciones'] as $s ) : ?>
					<?php echo wp_kses( Secciones::badge( $s ), asde_html_permitido() ); ?>
					<input type="hidden" name="secciones[]" value="<?php echo esc_attr( $s ); ?>">
				<?php endforeach; ?>
			<?php else : ?>
				<?php foreach ( $secciones as $s ) : ?>
					<label class="asde-choice asde-choice--inline"><input type="checkbox" name="secciones[]" value="<?php echo esc_attr( $s ); ?>" <?php checked( in_array( $s, $d['secciones'], true ) ); ?>><span><?php echo esc_html( Secciones::nombre( $s ) ); ?></span></label>
				<?php endforeach; ?>
				<?php if ( in_array( 'grupo', $ambitos, true ) ) : ?>
					<p class="asde-help"><?php esc_html_e( 'In a group event with no sections ticked, all the educational sections are invited.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
				<?php endif; ?>
			<?php endif; ?>
		</fieldset>

		<p class="asde-form__fila">
			<label><?php esc_html_e( 'Starts', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <input type="date" name="inicio_fecha" value="<?php echo esc_attr( $d['inicio_fecha'] ); ?>" required></label>
			<label><?php esc_html_e( 'at', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <input type="time" name="inicio_hora" value="<?php echo esc_attr( $d['inicio_hora'] ); ?>"></label>
			<label><?php esc_html_e( 'Ends', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <input type="date" name="fin_fecha" value="<?php echo esc_attr( $d['fin_fecha'] ); ?>" required></label>
			<label><?php esc_html_e( 'at', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <input type="time" name="fin_hora" value="<?php echo esc_attr( $d['fin_hora'] ); ?>"></label>
		</p>
		<p class="asde-form__fila">
			<label><?php esc_html_e( 'Place', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <input type="text" name="lugar" value="<?php echo esc_attr( $d['lugar'] ); ?>" maxlength="160"></label>
			<label><?php esc_html_e( 'Map link (optional)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <input type="url" name="mapa" value="<?php echo esc_attr( $d['mapa'] ); ?>" maxlength="255" placeholder="https://"></label>
		</p>
		<p class="asde-form__fila">
			<label><?php esc_html_e( 'Departure (place and time)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <input type="text" name="salida" value="<?php echo esc_attr( $d['salida'] ); ?>" maxlength="160"></label>
			<label><?php esc_html_e( 'Arrival (place and time)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <input type="text" name="llegada" value="<?php echo esc_attr( $d['llegada'] ); ?>" maxlength="160"></label>
		</p>
	</section>

	<section class="asde-card">
		<h3 class="asde-card__title"><?php esc_html_e( 'Participants and places', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h3>
		<p class="asde-help"><?php esc_html_e( 'The expected participants of each section are used to calculate the price and the monitor ratio. Places are the maximum (0 = no limit).', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
		<?php
		$cuentas_eq = ! empty( $e['id'] ) ? \AsdeBalears\AreaPrivada\Eventos\InscripcionesTable::cuentas_equipo( (int) $e['id'] ) : array();
		$auto       = array_filter( $cuentas_eq );
		if ( $auto ) :
			?>
			<p class="asde-help"><?php esc_html_e( 'The places for Scouters and Support volunteers are the people of the team with that function: they change when you add or remove people in the Team tab.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
		<?php endif; ?>
		<table class="asde-table asde-table--plazas" data-asde-sin-paginar="editor de plazas por seccion (pocas filas fijas)">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Section', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Expected participants', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Places', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $validado ? $e['secciones'] : $secciones as $s ) : ?>
					<tr>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Section', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo wp_kses( Secciones::badge( $s ), asde_html_permitido() ); ?></td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Expected participants', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
							<?php if ( $validado ) : ?>
								<?php echo absint( $d[ 'previstas_' . $s ] ); ?><input type="hidden" name="previstas_<?php echo esc_attr( $s ); ?>" value="<?php echo absint( $d[ 'previstas_' . $s ] ); ?>">
							<?php else : ?>
								<input type="number" name="previstas_<?php echo esc_attr( $s ); ?>" value="<?php echo esc_attr( $d[ 'previstas_' . $s ] ?? '0' ); ?>"<?php echo isset( $auto[ $s ] ) ? ' readonly' : ''; ?> min="0" max="999" inputmode="numeric" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: text */ __( 'Expected participants of %s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), Secciones::nombre( $s ) ) ); ?>">
							<?php endif; ?>
						</td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Places', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><input type="number" name="maximas_<?php echo esc_attr( $s ); ?>"<?php echo isset( $auto[ $s ] ) ? ' readonly' : ''; ?> value="<?php echo esc_attr( $d[ 'maximas_' . $s ] ?? '0' ); ?>" min="0" max="999" inputmode="numeric" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: text */ __( 'Places of %s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), Secciones::nombre( $s ) ) ); ?>"></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<p class="asde-form__fila">
			<label><?php esc_html_e( 'Total places (0 = no limit)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <input type="number" name="plazas_max" value="<?php echo esc_attr( $d['plazas_max'] ); ?>" min="0" inputmode="numeric"></label>
			<label><?php esc_html_e( 'Minimum age (optional)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <input type="number" name="edad_min" value="<?php echo esc_attr( $d['edad_min'] ); ?>" min="0" max="99" inputmode="numeric"></label>
			<label><?php esc_html_e( 'Maximum age (optional)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <input type="number" name="edad_max" value="<?php echo esc_attr( $d['edad_max'] ); ?>" min="0" max="99" inputmode="numeric"></label>
		</p>
	</section>

	<section class="asde-card">
		<h3 class="asde-card__title"><?php esc_html_e( 'Sign-up', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h3>
		<fieldset>
			<legend><?php esc_html_e( 'Mode', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></legend>
			<?php foreach ( EventosTable::modalidades() as $m => $etiqueta ) : ?>
				<label class="asde-check"><input type="radio" name="modalidad" value="<?php echo esc_attr( $m ); ?>"<?php checked( $d['modalidad'], $m ); ?>> <?php echo esc_html(  $etiqueta  ); ?></label>
			<?php endforeach; ?>
		</fieldset>
		<p class="asde-form__fila">
			<label><?php esc_html_e( 'Sign-up from (optional)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <input type="date" name="inscripcion_desde" value="<?php echo esc_attr( $d['inscripcion_desde'] ); ?>"></label>
			<label><?php esc_html_e( 'Sign-up until (optional)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <input type="date" name="inscripcion_hasta" value="<?php echo esc_attr( $d['inscripcion_hasta'] ); ?>"></label>
		</p>
		<p class="asde-help"><?php esc_html_e( 'Without dates, sign-up opens on publishing and closes when the event starts. After the deadline, only the team signs people up. If there are no places left, new sign-ups go on the waiting list.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
	</section>

	<section class="asde-card">
		<h3 class="asde-card__title"><?php esc_html_e( 'For families', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h3>
		<p>
			<label for="asde-ev-descripcion"><?php esc_html_e( 'Description or circular', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
			<textarea id="asde-ev-descripcion" name="descripcion" rows="5" maxlength="4000"><?php echo esc_textarea( $d['descripcion'] ); ?></textarea>
		</p>
		<p>
			<label for="asde-ev-material"><?php esc_html_e( 'Personal equipment (what each participant brings)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
			<textarea id="asde-ev-material" name="material" rows="4" maxlength="4000" placeholder="<?php echo esc_attr( esc_attr__( 'e.g. sleeping bag, mat, torch, uniform…', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo esc_textarea( $d['material'] ); ?></textarea>
		</p>
	</section>

	<section class="asde-card">
		<h3 class="asde-card__title"><?php esc_html_e( 'Cost, insurance and regulations', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h3>
		<?php if ( $validado ) : ?>
			<input type="hidden" name="con_coste" value="<?php echo $d['con_coste'] ? '1' : ''; ?>">
			<p><?php echo esc_html( $d['con_coste'] ? __( 'It has a cost for families (the price is fixed).', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) : __( 'No cost for families.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?></p>
		<?php else : ?>
			<label class="asde-choice"><input type="checkbox" name="con_coste" value="1" <?php checked( $d['con_coste'] ); ?>><span><?php esc_html_e( 'It has a cost for families (the price comes from the budget, which Treasury validates)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span></label>
		<?php endif; ?>
		<p>
			<label for="asde-ev-comunicacion"><?php esc_html_e( 'Report to ASDE Baleares (30 days before)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
			<select id="asde-ev-comunicacion" name="comunicacion">
				<?php foreach ( ( Normativa::comunicacion() ) as $c => $etiqueta ) : ?>
					<option value="<?php echo esc_attr( $c ); ?>" <?php selected( $d['comunicacion'], $c ); ?>><?php echo esc_html( $etiqueta ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
		<label class="asde-choice"><input type="checkbox" name="internacional" value="1" <?php checked( $d['internacional'] ); ?>><span><?php esc_html_e( 'International (all or part outside Spain): needs separate insurance', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span></label>
		<p class="asde-form__fila asde-subopciones">
			<label><?php esc_html_e( 'Insurance company', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <input type="text" name="seguro_compania" value="<?php echo esc_attr( $d['seguro_compania'] ); ?>" maxlength="100"></label>
			<label><?php esc_html_e( 'Policy no.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <input type="text" name="seguro_poliza" value="<?php echo esc_attr( $d['seguro_poliza'] ); ?>" maxlength="60"></label>
		</p>
		<label class="asde-choice"><input type="checkbox" name="riesgo" value="1" <?php checked( $d['riesgo'] ); ?>><span><?php esc_html_e( 'There are specialised-risk activities (high mountain, canyoning, climbing, diving, sailing)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span></label>
		<div class="asde-subopciones">
			<label class="asde-choice asde-choice--inline"><input type="checkbox" name="riesgo_titulado" value="1" <?php checked( $d['riesgo_titulado'] ); ?>><span><?php esc_html_e( 'With staff qualified in that activity', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span></label>
		</div>
		<label class="asde-choice"><input type="checkbox" name="agua" value="1" <?php checked( $d['agua'] ); ?>><span><?php esc_html_e( 'There is a swimming pool or sea', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span></label>
		<div class="asde-subopciones">
			<label class="asde-choice asde-choice--inline"><input type="checkbox" name="socorrista" value="1" <?php checked( $d['socorrista'] ); ?>><span><?php esc_html_e( 'A lifeguard will be present', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span></label>
		</div>
		<p class="asde-help"><?php esc_html_e( 'Participants with recognised dependency: write only how many (no names). The regulations require more support monitors.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
		<p class="asde-form__fila">
			<label><?php esc_html_e( 'With grade I or II', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <input type="number" name="dependencia12" value="<?php echo esc_attr( $d['dependencia12'] ); ?>" min="0" max="99" inputmode="numeric"></label>
			<label><?php esc_html_e( 'With grade III', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <input type="number" name="dependencia3" value="<?php echo esc_attr( $d['dependencia3'] ); ?>" min="0" max="99" inputmode="numeric"></label>
		</p>
	</section>

	<p>
		<button type="submit" class="button button-primary"><?php esc_html_e( 'Save the changes', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
		<a href="<?php echo esc_url( $volver ); ?>"><?php esc_html_e( 'Cancel', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a>
	</p>
</form>
