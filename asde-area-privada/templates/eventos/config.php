<?php
/**
 * Configuración de los eventos del grupo.
 *
 * @var array                 $cfg
 * @var array<string, string> $opciones Valores del campo «formación de tiempo libre» del CRM (vacío si no los da)
 * @var array<string, array>  $plantillas Plantillas de tareas del grupo (v0.40, doc 49)
 * @var string                $plantilla  La que se está editando
 * @var bool                  $original   Si la de Acampada es la original, sin cambios
 * @var string                $url
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Eventos\Config;
use AsdeBalears\AreaPrivada\Eventos\Plantillas;

defined( 'ABSPATH' ) || exit;
?>
<form method="post" class="asde-form" action="<?php echo esc_url( $url ); ?>">
	<?php wp_nonce_field( 'asde_eventos_config' ); ?>
	<input type="hidden" name="asde_action" value="eventos_config">

	<section class="asde-card">
		<h2 class="asde-card__title"><?php esc_html_e( 'Events · settings', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h2>
		<h3><?php esc_html_e( 'Emergency fund', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h3>
		<p class="asde-muted"><?php esc_html_e( 'This margin is added to each participant\'s cost: it is the activity\'s emergency fund. It is the value each new event starts with; it can be changed in each one until Treasury validates its budget.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
		<label class="asde-choice"><input type="checkbox" name="margen_activo" value="1" <?php checked( $cfg['margen_activo'] ); ?>><span><?php esc_html_e( 'Apply the emergency fund margin', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span></label>
		<p>
			<label for="asde-evc-margen"><?php esc_html_e( 'Margin (%)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
			<input type="text" id="asde-evc-margen" name="margen_pct" value="<?php echo esc_attr( number_format( $cfg['margen_pct'], 2, ',', '' ) ); ?>" inputmode="decimal" size="6">
		</p>
	</section>

	<section class="asde-card">
		<h3 class="asde-card__title"><?php esc_html_e( 'Activity types', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h3>
		<p class="asde-muted"><?php esc_html_e( 'Each type gives the values a new event starts with: whether it has a cost and whether it is reported to ASDE Baleares (activities of 2 or more nights with 10 or more minors).', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
		<table class="asde-table asde-table--tipos" data-asde-paginar>
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Name', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
					<th scope="col"><?php esc_html_e( 'With a cost', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Reporting', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Active', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $cfg['tipos'] as $id => $t ) : ?>
					<tr>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Name', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><input type="text" name="tipo_nombre_<?php echo esc_attr( $id ); ?>" value="<?php echo esc_attr( $t['nombre'] ); ?>" maxlength="60" aria-label="<?php echo esc_attr( esc_attr__( 'Name', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"></td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'With a cost', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><label class="asde-choice asde-choice--inline"><input type="checkbox" name="tipo_coste_<?php echo esc_attr( $id ); ?>" value="1" <?php checked( $t['coste'] ); ?>><span><?php esc_html_e( 'Yes', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span></label></td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Reporting', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
							<select name="tipo_comunicacion_<?php echo esc_attr( $id ); ?>" aria-label="<?php echo esc_attr( esc_attr__( 'Reporting', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
								<option value="auto" <?php selected( $t['comunicacion'], 'auto' ); ?>><?php esc_html_e( 'Depending on nights and minors', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></option>
								<option value="no" <?php selected( $t['comunicacion'], 'no' ); ?>><?php esc_html_e( 'No', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></option>
							</select>
						</td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Active', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><label class="asde-choice asde-choice--inline"><input type="checkbox" name="tipo_activo_<?php echo esc_attr( $id ); ?>" value="1" <?php checked( $t['activo'] ); ?>><span><?php esc_html_e( 'Yes', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span></label></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<fieldset>
			<legend><?php esc_html_e( 'New type', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></legend>
			<p class="asde-form__fila">
				<label><?php esc_html_e( 'Name', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <input type="text" name="nuevo_tipo" maxlength="60"></label>
				<label><?php esc_html_e( 'Reporting', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?>
					<select name="nuevo_comunicacion">
						<option value="auto"><?php esc_html_e( 'Depending on nights and minors', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></option>
						<option value="no"><?php esc_html_e( 'No', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></option>
					</select>
				</label>
			</p>
			<label class="asde-choice"><input type="checkbox" name="nuevo_coste" value="1"><span><?php esc_html_e( 'With a cost', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span></label>
		</fieldset>
	</section>

	<section class="asde-card">
		<h3 class="asde-card__title"><?php esc_html_e( 'Leisure-time qualifications', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h3>
		<p class="asde-muted"><?php esc_html_e( 'Which values of the leisure-time training on the record count as a director qualification (also valid as monitor) or as a monitor qualification. If you tick none, it is deduced from the text ("director", "monitor").', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
		<?php foreach ( array( 'director' => __( 'Count as director', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), 'monitor' => __( 'Count as monitor', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) as $k => $titulo ) : ?>
			<fieldset>
				<legend><?php echo esc_html( $titulo ); ?></legend>
				<?php if ( $opciones ) : ?>
					<?php foreach ( $opciones as $valor => $etiqueta ) : ?>
						<label class="asde-choice"><input type="checkbox" name="titulacion_<?php echo esc_attr( $k ); ?>[]" value="<?php echo esc_attr( (string) $valor ); ?>" <?php checked( in_array( (string) $valor, $cfg['titulaciones'][ $k ], true ) ); ?>><span><?php echo esc_html( '' !== $etiqueta ? $etiqueta : (string) $valor ); ?></span></label>
					<?php endforeach; ?>
				<?php else : ?>
					<label for="asde-evc-tit-<?php echo esc_attr( $k ); ?>" class="screen-reader-text"><?php echo esc_html( $titulo ); ?></label>
					<textarea id="asde-evc-tit-<?php echo esc_attr( $k ); ?>" name="titulacion_<?php echo esc_attr( $k ); ?>_texto" rows="3" placeholder="<?php echo esc_attr( esc_attr__( 'One CRM value per line', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo esc_textarea( implode( "\n", $cfg['titulaciones'][ $k ] ) ); ?></textarea>
				<?php endif; ?>
			</fieldset>
		<?php endforeach; ?>
		<?php if ( ! $opciones ) : ?>
			<p class="asde-help"><?php esc_html_e( 'The CRM has not provided the list of values for that field: write them as they appear in the CRM, one per line.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
		<?php endif; ?>
	</section>

	<section class="asde-card">
		<h3 class="asde-card__title"><?php esc_html_e( 'Sign-ups', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h3>
		<p>
			<label for="asde-evc-autorizacion"><?php esc_html_e( 'Participation authorisation text', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
			<textarea id="asde-evc-autorizacion" name="autorizacion_texto" rows="6" maxlength="<?php echo (int) Config::MAX_AUTORIZACION; ?>"><?php echo esc_textarea( Config::autorizacion( $cfg ) ); ?></textarea>
		</p>
		<p class="asde-help"><?php echo esc_html( sprintf( /* translators: %s: text */ __( 'This is what the family signs when signing up a minor (above it appears who and which event it authorises). Current version: %s. If you change it, earlier signatures keep the version they signed. Leave it empty to use the plugin\'s.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), Config::version_autorizacion( $cfg ) ) ); ?></p>
		<p>
			<label for="asde-evc-pago"><?php esc_html_e( 'How to pay for events (group account, Bizum…)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
			<textarea id="asde-evc-pago" name="texto_pago" rows="3" maxlength="1000"><?php echo esc_textarea( $cfg['texto_pago'] ); ?></textarea>
		</p>
		<p>
			<label for="asde-evc-recordatorio"><?php esc_html_e( 'Reminder to families (days before the event; 0 = not sent)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
			<input type="number" id="asde-evc-recordatorio" name="recordatorio_dias" value="<?php echo (int) $cfg['recordatorio_dias']; ?>" min="0" max="30" inputmode="numeric">
		</p>
	</section>

	<section class="asde-card">
		<h3 class="asde-card__title"><?php esc_html_e( 'Notices', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h3>
		<p class="asde-muted"><?php esc_html_e( 'Which notices the private area sends to families and to the people signed up. Each group decides its own.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
		<input type="hidden" name="avisos_enviado" value="1">
		<?php
		$avisos_txt = array(
			'publicado'    => __( 'New event: when published, to the invited people', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
			'confirmada'   => __( 'Sign-up confirmed', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
			'plaza'        => __( 'Place freed up for whoever is on the waiting list', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
			'recordatorio' => __( 'Reminder before the event (with the days above)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
			'cancelado'    => __( 'Event cancelled', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
		);
		foreach ( $avisos_txt as $a => $txt ) :
			?>
			<label class="asde-choice"><input type="checkbox" name="avisos[]" value="<?php echo esc_attr( $a ); ?>" <?php checked( ! empty( $cfg['avisos'][ $a ] ) ); ?>><span><?php echo esc_html( $txt ); ?></span></label>
		<?php endforeach; ?>
		<label class="asde-choice"><input type="checkbox" name="push" value="1" <?php checked( $cfg['push'] ); ?>><span><?php esc_html_e( 'Also notify in the mobile app (if the group uses it)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span></label>
		<p>
			<label for="asde-evc-oferta"><?php esc_html_e( 'Days to confirm a place offered from the waiting list', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
			<input type="number" id="asde-evc-oferta" name="dias_oferta" value="<?php echo (int) $cfg['dias_oferta']; ?>" min="1" max="<?php echo (int) Config::MAX_DIAS_OFERTA; ?>" inputmode="numeric">
		</p>
		<p>
			<label for="asde-evc-extra"><?php esc_html_e( 'Second reminder (days before the event; 0 = not sent; less than the first)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
			<input type="number" id="asde-evc-extra" name="recordatorio_extra" value="<?php echo (int) $cfg['recordatorio_extra']; ?>" min="0" max="29" inputmode="numeric">
		</p>
		<p>
			<label for="asde-evc-edad"><?php esc_html_e( 'Age from which a person receives notices in their own account and signs up on their own', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
			<input type="number" id="asde-evc-edad" name="edad_cuenta_propia" value="<?php echo (int) $cfg['edad_cuenta_propia']; ?>" min="<?php echo (int) Config::EDAD_MIN; ?>" max="<?php echo (int) Config::EDAD_MAX; ?>" inputmode="numeric">
		</p>
		<details>
			<summary><?php esc_html_e( 'Own notice texts', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></summary>
			<p class="asde-help"><?php echo esc_html( sprintf( /* translators: %d: number */ __( 'An optional paragraph at the start and another at the end of each email (up to %d characters). They appear the same in all languages: write them in your families\' language.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), Config::MAX_TEXTO_AVISO ) ); ?></p>
			<?php foreach ( $avisos_txt as $a => $txt ) : ?>
				<fieldset>
					<legend><?php echo esc_html( $txt ); ?></legend>
					<label for="asde-evc-intro-<?php echo esc_attr( $a ); ?>"><?php esc_html_e( 'At the start', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
					<textarea id="asde-evc-intro-<?php echo esc_attr( $a ); ?>" name="texto_intro_<?php echo esc_attr( $a ); ?>" rows="2" maxlength="<?php echo (int) Config::MAX_TEXTO_AVISO; ?>"><?php echo esc_textarea( $cfg['textos'][ $a ]['intro'] ); ?></textarea>
					<label for="asde-evc-pie-<?php echo esc_attr( $a ); ?>"><?php esc_html_e( 'At the end', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
					<textarea id="asde-evc-pie-<?php echo esc_attr( $a ); ?>" name="texto_pie_<?php echo esc_attr( $a ); ?>" rows="2" maxlength="<?php echo (int) Config::MAX_TEXTO_AVISO; ?>"><?php echo esc_textarea( $cfg['textos'][ $a ]['pie'] ); ?></textarea>
				</fieldset>
			<?php endforeach; ?>
		</details>
		<p class="asde-help"><?php esc_html_e( 'If you turn off the freed-place notice, the place is still offered, but the family does not receive the email: they will have to look in the application.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
	</section>

	<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Save', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button></p>
</form>

<section class="asde-card" id="material-habitual">
	<h3 class="asde-card__title"><?php esc_html_e( 'Usual equipment for each type of event', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h3>
	<p class="asde-muted"><?php esc_html_e( 'One line per item, with the quantity and the name as it appears in the inventory: "4; Canadian tent". In an event\'s Equipment tab it is loaded with a button. Anything not found in the inventory is flagged.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
	<form method="post" class="asde-form" action="<?php echo esc_url( $url ); ?>">
		<?php wp_nonce_field( 'asde_eventos_config' ); ?>
		<input type="hidden" name="asde_action" value="material_guardar">
		<?php foreach ( $cfg['tipos'] as $tid => $t ) : ?>
			<?php if ( empty( $t['activo'] ) ) : ?>
				<?php continue; ?>
			<?php endif; ?>
			<p>
				<label for="asde-mh-<?php echo esc_attr( (string) $tid ); ?>"><?php echo esc_html( $t['nombre'] ); ?></label>
				<textarea id="asde-mh-<?php echo esc_attr( (string) $tid ); ?>" name="material_<?php echo esc_attr( (string) $tid ); ?>" rows="3" maxlength="<?php echo (int) \AsdeBalears\AreaPrivada\Eventos\Material::MAX_HABITUAL; ?>"><?php echo esc_textarea( (string) ( $cfg['material_por_tipo'][ $tid ] ?? '' ) ); ?></textarea>
			</p>
		<?php endforeach; ?>
		<p><button type="submit" class="button"><?php esc_html_e( 'Save the usual equipment', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button></p>
	</form>
</section>

<section class="asde-card" id="calendario-de-tareas">
	<h3 class="asde-card__title"><?php esc_html_e( 'Task calendar', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h3>
	<p class="asde-muted"><?php esc_html_e( 'Each event can copy a task template with its deadlines (for example, 60 days before the start). The Camp one is included; you can change it or create your own.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>

	<form method="post" class="asde-form" action="<?php echo esc_url( $url ); ?>">
		<?php wp_nonce_field( 'asde_eventos_config' ); ?>
		<input type="hidden" name="asde_action" value="calendario_guardar">
		<label class="asde-choice"><input type="checkbox" name="calendario_viernes" value="1" <?php checked( $cfg['calendario_viernes'] ); ?>><span><?php esc_html_e( 'If a deadline falls on a Saturday or Sunday, move it forward to the Friday', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span></label>
		<label class="asde-choice"><input type="checkbox" name="avisos_tareas" value="1" <?php checked( $cfg['avisos_tareas'] ); ?>><span><?php esc_html_e( 'Email the person in charge of each task (or the director, if there is none) 3 days before and when it is due', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span></label>
		<p><button type="submit" class="button"><?php esc_html_e( 'Save', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button></p>
	</form>

	<h4><?php esc_html_e( 'Templates', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h4>
	<ul class="asde-plantillas">
		<?php foreach ( $plantillas as $pid => $pl ) : ?>
			<li>
				<?php if ( (string) $pid === $plantilla ) : ?>
					<strong><?php echo esc_html( Plantillas::nombre( (string) $pid, $pl ) ); ?></strong>
				<?php else : ?>
					<a href="<?php echo esc_url( add_query_arg( 'plantilla', (string) $pid, $url ) . '#calendario-de-tareas' ); ?>"><?php echo esc_html( Plantillas::nombre( (string) $pid, $pl ) ); ?></a>
				<?php endif; ?>
				<span class="asde-muted">(<?php echo esc_html( sprintf( /* translators: %d: number */ _n( '%d task', '%d tasks', count( $pl['tareas'] ) , 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), count( $pl['tareas'] ) ) ); ?>)</span>
			</li>
		<?php endforeach; ?>
	</ul>

	<?php $pl = $plantillas[ $plantilla ]; ?>
	<form method="post" class="asde-form" action="<?php echo esc_url( $url ); ?>">
		<?php wp_nonce_field( 'asde_eventos_config' ); ?>
		<input type="hidden" name="asde_action" value="plantilla_guardar">
		<input type="hidden" name="plantilla" value="<?php echo esc_attr( $plantilla ); ?>">
		<input type="hidden" name="filas" value="<?php echo count( $pl['tareas'] ); ?>">
		<p>
			<label for="asde-plantilla-nombre"><?php esc_html_e( 'Template name', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
			<input type="text" id="asde-plantilla-nombre" name="nombre" value="<?php echo esc_attr( Plantillas::nombre( $plantilla, $pl ) ); ?>" maxlength="60">
		</p>
		<table class="asde-table asde-table--plantilla" data-asde-sin-paginar="editor de la plantilla del evento">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Task', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Detail', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
					<th scope="col"><?php esc_html_e( 'From', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Days', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Remove', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $pl['tareas'] as $i => $t ) : ?>
					<tr>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Task', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
							<input type="text" name="t_titulo_<?php echo (int) $i; ?>" value="<?php echo esc_attr( $t['titulo'] ); ?>" maxlength="200" aria-label="<?php echo esc_attr( esc_attr__( 'Task', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
							<input type="hidden" name="t_auto_<?php echo (int) $i; ?>" value="<?php echo esc_attr( $t['auto'] ); ?>">
							<input type="hidden" name="t_enlace_<?php echo (int) $i; ?>" value="<?php echo esc_attr( $t['enlace'] ); ?>">
						</td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Detail', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><input type="text" name="t_detalle_<?php echo (int) $i; ?>" value="<?php echo esc_attr( $t['detalle'] ); ?>" maxlength="400" aria-label="<?php echo esc_attr( esc_attr__( 'Detail', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"></td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'From', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
							<select name="t_ref_<?php echo (int) $i; ?>" aria-label="<?php echo esc_attr( esc_attr__( 'From', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
								<option value="inicio" <?php selected( $t['ref'], 'inicio' ); ?>><?php esc_html_e( 'the start', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></option>
								<option value="fin" <?php selected( $t['ref'], 'fin' ); ?>><?php esc_html_e( 'the end', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></option>
							</select>
						</td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Days', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><input type="number" name="t_desfase_<?php echo (int) $i; ?>" value="<?php echo (int) $t['desfase']; ?>" min="-730" max="730" step="1" aria-label="<?php echo esc_attr( esc_attr__( 'Days', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"></td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Remove', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><input type="checkbox" name="t_quitar_<?php echo (int) $i; ?>" value="1" aria-label="<?php echo esc_attr( esc_attr__( 'Remove', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"></td>
					</tr>
				<?php endforeach; ?>
				<?php for ( $k = 0; $k < 3; $k++ ) : ?>
					<tr>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Task', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><input type="text" name="n_titulo_<?php echo (int) $k; ?>" maxlength="200" placeholder="<?php echo esc_attr( esc_attr__( 'New task', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>" aria-label="<?php echo esc_attr( esc_attr__( 'New task', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"></td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Detail', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><input type="text" name="n_detalle_<?php echo (int) $k; ?>" maxlength="400" aria-label="<?php echo esc_attr( esc_attr__( 'Detail', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"></td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'From', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
							<select name="n_ref_<?php echo (int) $k; ?>" aria-label="<?php echo esc_attr( esc_attr__( 'From', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
								<option value="inicio"><?php esc_html_e( 'the start', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></option>
								<option value="fin"><?php esc_html_e( 'the end', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></option>
							</select>
						</td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Days', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><input type="number" name="n_desfase_<?php echo (int) $k; ?>" value="0" min="-730" max="730" step="1" aria-label="<?php echo esc_attr( esc_attr__( 'Days', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"></td>
						<td></td>
					</tr>
				<?php endfor; ?>
			</tbody>
		</table>
		<p class="asde-help"><?php esc_html_e( 'Days: negative = before (−60 = 60 days before the start); positive = after (7 = one week after the end). Changes apply to the events that use the template from now on.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
		<p><button type="submit" class="button"><?php esc_html_e( 'Save the template', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button></p>
	</form>

	<?php if ( 'acampada' === $plantilla && ! $original ) : ?>
		<form method="post" action="<?php echo esc_url( $url ); ?>" data-asde-confirmar="<?php echo esc_attr( __( 'Restore the Camp template to the original? Your changes to it are lost.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
			<?php wp_nonce_field( 'asde_eventos_config' ); ?>
			<input type="hidden" name="asde_action" value="plantilla_restaurar">
			<button type="submit" class="asde-btn-link"><?php esc_html_e( 'Restore the original Camp template', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
		</form>
	<?php elseif ( 'acampada' !== $plantilla ) : ?>
		<form method="post" action="<?php echo esc_url( $url ); ?>" data-asde-confirmar="<?php echo esc_attr( __( 'Delete this template? Events that already applied it do not change.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
			<?php wp_nonce_field( 'asde_eventos_config' ); ?>
			<input type="hidden" name="asde_action" value="plantilla_borrar">
			<input type="hidden" name="plantilla" value="<?php echo esc_attr( $plantilla ); ?>">
			<button type="submit" class="asde-btn-link asde-btn-danger"><?php esc_html_e( 'Delete the template', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
		</form>
	<?php endif; ?>

	<h4><?php esc_html_e( 'New template', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h4>
	<form method="post" class="asde-form" action="<?php echo esc_url( $url ); ?>">
		<?php wp_nonce_field( 'asde_eventos_config' ); ?>
		<input type="hidden" name="asde_action" value="plantilla_nueva">
		<p class="asde-form__fila">
			<label><?php esc_html_e( 'Name', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <input type="text" name="nombre" maxlength="60" placeholder="<?php echo esc_attr( esc_attr__( 'For example: Day outing', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>" required></label>
		</p>
		<label class="asde-choice"><input type="checkbox" name="copiar" value="1"><span><?php esc_html_e( 'Start with a copy of the Camp tasks', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span></label>
		<p><button type="submit" class="button"><?php esc_html_e( 'Create the template', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button></p>
	</form>
</section>
