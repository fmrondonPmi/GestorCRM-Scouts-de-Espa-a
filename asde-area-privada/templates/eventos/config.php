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
		<h2 class="asde-card__title"><?php asde_e( 'Eventos · configuración' ); ?></h2>
		<h3><?php asde_e( 'Fondo de emergencia' ); ?></h3>
		<p class="asde-muted"><?php asde_e( 'Al coste de cada participante se le suma este margen: es el fondo de emergencia de la actividad. Es el valor con el que nace cada evento nuevo; en cada uno se puede cambiar hasta que Tesorería valida su presupuesto.' ); ?></p>
		<label class="asde-choice"><input type="checkbox" name="margen_activo" value="1" <?php checked( $cfg['margen_activo'] ); ?>><span><?php asde_e( 'Aplicar el margen del fondo de emergencia' ); ?></span></label>
		<p>
			<label for="asde-evc-margen"><?php asde_e( 'Margen (%)' ); ?></label>
			<input type="text" id="asde-evc-margen" name="margen_pct" value="<?php echo esc_attr( number_format( $cfg['margen_pct'], 2, ',', '' ) ); ?>" inputmode="decimal" size="6">
		</p>
	</section>

	<section class="asde-card">
		<h3 class="asde-card__title"><?php asde_e( 'Tipos de actividad' ); ?></h3>
		<p class="asde-muted"><?php asde_e( 'Cada tipo da los valores con los que nace un evento: si tiene coste y si se comunica a ASDE Baleares (las actividades de 2 o más noches con 10 o más menores).' ); ?></p>
		<table class="asde-table asde-table--tipos">
			<thead>
				<tr>
					<th scope="col"><?php asde_e( 'Nombre' ); ?></th>
					<th scope="col"><?php asde_e( 'Con coste' ); ?></th>
					<th scope="col"><?php asde_e( 'Comunicación' ); ?></th>
					<th scope="col"><?php asde_e( 'Activo' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $cfg['tipos'] as $id => $t ) : ?>
					<tr>
						<td data-label="<?php echo esc_attr( asde_a( 'Nombre' ) ); ?>"><input type="text" name="tipo_nombre_<?php echo esc_attr( $id ); ?>" value="<?php echo esc_attr( $t['nombre'] ); ?>" maxlength="60" aria-label="<?php echo esc_attr( asde_a( 'Nombre' ) ); ?>"></td>
						<td data-label="<?php echo esc_attr( asde_a( 'Con coste' ) ); ?>"><label class="asde-choice asde-choice--inline"><input type="checkbox" name="tipo_coste_<?php echo esc_attr( $id ); ?>" value="1" <?php checked( $t['coste'] ); ?>><span><?php asde_e( 'Sí' ); ?></span></label></td>
						<td data-label="<?php echo esc_attr( asde_a( 'Comunicación' ) ); ?>">
							<select name="tipo_comunicacion_<?php echo esc_attr( $id ); ?>" aria-label="<?php echo esc_attr( asde_a( 'Comunicación' ) ); ?>">
								<option value="auto" <?php selected( $t['comunicacion'], 'auto' ); ?>><?php asde_e( 'Según noches y menores' ); ?></option>
								<option value="no" <?php selected( $t['comunicacion'], 'no' ); ?>><?php asde_e( 'No' ); ?></option>
							</select>
						</td>
						<td data-label="<?php echo esc_attr( asde_a( 'Activo' ) ); ?>"><label class="asde-choice asde-choice--inline"><input type="checkbox" name="tipo_activo_<?php echo esc_attr( $id ); ?>" value="1" <?php checked( $t['activo'] ); ?>><span><?php asde_e( 'Sí' ); ?></span></label></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<fieldset>
			<legend><?php asde_e( 'Nuevo tipo' ); ?></legend>
			<p class="asde-form__fila">
				<label><?php asde_e( 'Nombre' ); ?> <input type="text" name="nuevo_tipo" maxlength="60"></label>
				<label><?php asde_e( 'Comunicación' ); ?>
					<select name="nuevo_comunicacion">
						<option value="auto"><?php asde_e( 'Según noches y menores' ); ?></option>
						<option value="no"><?php asde_e( 'No' ); ?></option>
					</select>
				</label>
			</p>
			<label class="asde-choice"><input type="checkbox" name="nuevo_coste" value="1"><span><?php asde_e( 'Con coste' ); ?></span></label>
		</fieldset>
	</section>

	<section class="asde-card">
		<h3 class="asde-card__title"><?php asde_e( 'Titulaciones de tiempo libre' ); ?></h3>
		<p class="asde-muted"><?php asde_e( 'Qué valores de la formación de tiempo libre de la ficha cuentan como título de director/a (vale también como monitor/a) o de monitor/a. Si no marcas ninguno, se deduce del texto («director», «monitor»).' ); ?></p>
		<?php foreach ( array( 'director' => asde_t( 'Cuentan como director/a' ), 'monitor' => asde_t( 'Cuentan como monitor/a' ) ) as $k => $titulo ) : ?>
			<fieldset>
				<legend><?php echo esc_html( $titulo ); ?></legend>
				<?php if ( $opciones ) : ?>
					<?php foreach ( $opciones as $valor => $etiqueta ) : ?>
						<label class="asde-choice"><input type="checkbox" name="titulacion_<?php echo esc_attr( $k ); ?>[]" value="<?php echo esc_attr( (string) $valor ); ?>" <?php checked( in_array( (string) $valor, $cfg['titulaciones'][ $k ], true ) ); ?>><span><?php echo esc_html( '' !== $etiqueta ? $etiqueta : (string) $valor ); ?></span></label>
					<?php endforeach; ?>
				<?php else : ?>
					<label for="asde-evc-tit-<?php echo esc_attr( $k ); ?>" class="screen-reader-text"><?php echo esc_html( $titulo ); ?></label>
					<textarea id="asde-evc-tit-<?php echo esc_attr( $k ); ?>" name="titulacion_<?php echo esc_attr( $k ); ?>_texto" rows="3" placeholder="<?php echo esc_attr( asde_a( 'Un valor del CRM por línea' ) ); ?>"><?php echo esc_textarea( implode( "\n", $cfg['titulaciones'][ $k ] ) ); ?></textarea>
				<?php endif; ?>
			</fieldset>
		<?php endforeach; ?>
		<?php if ( ! $opciones ) : ?>
			<p class="asde-help"><?php asde_e( 'El CRM no ha dado la lista de valores de ese campo: escríbelos tal como están en el CRM, uno por línea.' ); ?></p>
		<?php endif; ?>
	</section>

	<section class="asde-card">
		<h3 class="asde-card__title"><?php asde_e( 'Inscripciones' ); ?></h3>
		<p>
			<label for="asde-evc-autorizacion"><?php asde_e( 'Texto de la autorización de participación' ); ?></label>
			<textarea id="asde-evc-autorizacion" name="autorizacion_texto" rows="6" maxlength="<?php echo (int) Config::MAX_AUTORIZACION; ?>"><?php echo esc_textarea( Config::autorizacion( $cfg ) ); ?></textarea>
		</p>
		<p class="asde-help"><?php echo esc_html( sprintf( asde_t( 'Es lo que firma la familia al inscribir a un menor (encima sale a quién y a qué evento autoriza). Versión actual: %s. Si lo cambias, las firmas anteriores siguen guardando la versión que firmaron. Déjalo vacío para usar el del plugin.' ), Config::version_autorizacion( $cfg ) ) ); ?></p>
		<p>
			<label for="asde-evc-pago"><?php asde_e( 'Cómo pagar los eventos (cuenta del grupo, Bizum…)' ); ?></label>
			<textarea id="asde-evc-pago" name="texto_pago" rows="3" maxlength="1000"><?php echo esc_textarea( $cfg['texto_pago'] ); ?></textarea>
		</p>
		<p>
			<label for="asde-evc-recordatorio"><?php asde_e( 'Recordatorio a las familias (días antes del evento; 0 = no se manda)' ); ?></label>
			<input type="number" id="asde-evc-recordatorio" name="recordatorio_dias" value="<?php echo (int) $cfg['recordatorio_dias']; ?>" min="0" max="30" inputmode="numeric">
		</p>
	</section>

	<section class="asde-card">
		<h3 class="asde-card__title"><?php asde_e( 'Avisos' ); ?></h3>
		<p class="asde-muted"><?php asde_e( 'Qué avisos manda el área privada a las familias y a las personas inscritas. Cada grupo decide los suyos.' ); ?></p>
		<input type="hidden" name="avisos_enviado" value="1">
		<?php
		$avisos_txt = array(
			'publicado'    => asde_t( 'Evento nuevo: al publicarlo, a las personas convocadas' ),
			'confirmada'   => asde_t( 'Inscripción confirmada' ),
			'plaza'        => asde_t( 'Plaza liberada para quien está en la lista de espera' ),
			'recordatorio' => asde_t( 'Recordatorio antes del evento (con los días de arriba)' ),
			'cancelado'    => asde_t( 'Evento cancelado' ),
		);
		foreach ( $avisos_txt as $a => $txt ) :
			?>
			<label class="asde-choice"><input type="checkbox" name="avisos[]" value="<?php echo esc_attr( $a ); ?>" <?php checked( ! empty( $cfg['avisos'][ $a ] ) ); ?>><span><?php echo esc_html( $txt ); ?></span></label>
		<?php endforeach; ?>
		<label class="asde-choice"><input type="checkbox" name="push" value="1" <?php checked( $cfg['push'] ); ?>><span><?php asde_e( 'Avisar también en la app móvil (si la usa el grupo)' ); ?></span></label>
		<p>
			<label for="asde-evc-oferta"><?php asde_e( 'Días para confirmar una plaza ofrecida de la lista de espera' ); ?></label>
			<input type="number" id="asde-evc-oferta" name="dias_oferta" value="<?php echo (int) $cfg['dias_oferta']; ?>" min="1" max="<?php echo (int) Config::MAX_DIAS_OFERTA; ?>" inputmode="numeric">
		</p>
		<p>
			<label for="asde-evc-extra"><?php asde_e( 'Segundo recordatorio (días antes del evento; 0 = no se manda; menos que el primero)' ); ?></label>
			<input type="number" id="asde-evc-extra" name="recordatorio_extra" value="<?php echo (int) $cfg['recordatorio_extra']; ?>" min="0" max="29" inputmode="numeric">
		</p>
		<p>
			<label for="asde-evc-edad"><?php asde_e( 'Edad desde la que una persona recibe los avisos en su propia cuenta y se inscribe sola' ); ?></label>
			<input type="number" id="asde-evc-edad" name="edad_cuenta_propia" value="<?php echo (int) $cfg['edad_cuenta_propia']; ?>" min="<?php echo (int) Config::EDAD_MIN; ?>" max="<?php echo (int) Config::EDAD_MAX; ?>" inputmode="numeric">
		</p>
		<details>
			<summary><?php asde_e( 'Textos propios de los avisos' ); ?></summary>
			<p class="asde-help"><?php echo esc_html( sprintf( asde_t( 'Un párrafo opcional al principio y otro al final de cada email (hasta %d caracteres). Salen igual en todos los idiomas: escríbelos en el idioma de tus familias.' ), Config::MAX_TEXTO_AVISO ) ); ?></p>
			<?php foreach ( $avisos_txt as $a => $txt ) : ?>
				<fieldset>
					<legend><?php echo esc_html( $txt ); ?></legend>
					<label for="asde-evc-intro-<?php echo esc_attr( $a ); ?>"><?php asde_e( 'Al principio' ); ?></label>
					<textarea id="asde-evc-intro-<?php echo esc_attr( $a ); ?>" name="texto_intro_<?php echo esc_attr( $a ); ?>" rows="2" maxlength="<?php echo (int) Config::MAX_TEXTO_AVISO; ?>"><?php echo esc_textarea( $cfg['textos'][ $a ]['intro'] ); ?></textarea>
					<label for="asde-evc-pie-<?php echo esc_attr( $a ); ?>"><?php asde_e( 'Al final' ); ?></label>
					<textarea id="asde-evc-pie-<?php echo esc_attr( $a ); ?>" name="texto_pie_<?php echo esc_attr( $a ); ?>" rows="2" maxlength="<?php echo (int) Config::MAX_TEXTO_AVISO; ?>"><?php echo esc_textarea( $cfg['textos'][ $a ]['pie'] ); ?></textarea>
				</fieldset>
			<?php endforeach; ?>
		</details>
		<p class="asde-help"><?php asde_e( 'Si desactivas el aviso de plaza liberada, la plaza se sigue ofreciendo, pero la familia no recibe el email: tendrá que mirarlo en la aplicación.' ); ?></p>
	</section>

	<p><button type="submit" class="button button-primary"><?php asde_e( 'Guardar' ); ?></button></p>
</form>

<section class="asde-card" id="calendario-de-tareas">
	<h3 class="asde-card__title"><?php asde_e( 'Calendario de tareas' ); ?></h3>
	<p class="asde-muted"><?php asde_e( 'Cada evento puede copiar una plantilla de tareas con su fecha límite (por ejemplo, 60 días antes del inicio). Viene la de Acampada; puedes cambiarla o crear las tuyas.' ); ?></p>

	<form method="post" class="asde-form" action="<?php echo esc_url( $url ); ?>">
		<?php wp_nonce_field( 'asde_eventos_config' ); ?>
		<input type="hidden" name="asde_action" value="calendario_guardar">
		<label class="asde-choice"><input type="checkbox" name="calendario_viernes" value="1" <?php checked( $cfg['calendario_viernes'] ); ?>><span><?php asde_e( 'Si una fecha límite cae en sábado o domingo, adelantarla al viernes' ); ?></span></label>
		<label class="asde-choice"><input type="checkbox" name="avisos_tareas" value="1" <?php checked( $cfg['avisos_tareas'] ); ?>><span><?php asde_e( 'Avisar por email al responsable de cada tarea (o al director/a, si no tiene) 3 días antes y cuando vence' ); ?></span></label>
		<p><button type="submit" class="button"><?php asde_e( 'Guardar' ); ?></button></p>
	</form>

	<h4><?php asde_e( 'Plantillas' ); ?></h4>
	<ul class="asde-plantillas">
		<?php foreach ( $plantillas as $pid => $pl ) : ?>
			<li>
				<?php if ( (string) $pid === $plantilla ) : ?>
					<strong><?php echo esc_html( Plantillas::nombre( (string) $pid, $pl ) ); ?></strong>
				<?php else : ?>
					<a href="<?php echo esc_url( add_query_arg( 'plantilla', (string) $pid, $url ) . '#calendario-de-tareas' ); ?>"><?php echo esc_html( Plantillas::nombre( (string) $pid, $pl ) ); ?></a>
				<?php endif; ?>
				<span class="asde-muted">(<?php echo esc_html( sprintf( asde_n( '%d tarea', '%d tareas', count( $pl['tareas'] ) ), count( $pl['tareas'] ) ) ); ?>)</span>
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
			<label for="asde-plantilla-nombre"><?php asde_e( 'Nombre de la plantilla' ); ?></label>
			<input type="text" id="asde-plantilla-nombre" name="nombre" value="<?php echo esc_attr( Plantillas::nombre( $plantilla, $pl ) ); ?>" maxlength="60">
		</p>
		<table class="asde-table asde-table--plantilla">
			<thead>
				<tr>
					<th scope="col"><?php asde_e( 'Tarea' ); ?></th>
					<th scope="col"><?php asde_e( 'Detalle' ); ?></th>
					<th scope="col"><?php asde_e( 'Desde' ); ?></th>
					<th scope="col"><?php asde_e( 'Días' ); ?></th>
					<th scope="col"><?php asde_e( 'Quitar' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $pl['tareas'] as $i => $t ) : ?>
					<tr>
						<td data-label="<?php echo esc_attr( asde_a( 'Tarea' ) ); ?>">
							<input type="text" name="t_titulo_<?php echo (int) $i; ?>" value="<?php echo esc_attr( $t['titulo'] ); ?>" maxlength="200" aria-label="<?php echo esc_attr( asde_a( 'Tarea' ) ); ?>">
							<input type="hidden" name="t_auto_<?php echo (int) $i; ?>" value="<?php echo esc_attr( $t['auto'] ); ?>">
							<input type="hidden" name="t_enlace_<?php echo (int) $i; ?>" value="<?php echo esc_attr( $t['enlace'] ); ?>">
						</td>
						<td data-label="<?php echo esc_attr( asde_a( 'Detalle' ) ); ?>"><input type="text" name="t_detalle_<?php echo (int) $i; ?>" value="<?php echo esc_attr( $t['detalle'] ); ?>" maxlength="400" aria-label="<?php echo esc_attr( asde_a( 'Detalle' ) ); ?>"></td>
						<td data-label="<?php echo esc_attr( asde_a( 'Desde' ) ); ?>">
							<select name="t_ref_<?php echo (int) $i; ?>" aria-label="<?php echo esc_attr( asde_a( 'Desde' ) ); ?>">
								<option value="inicio" <?php selected( $t['ref'], 'inicio' ); ?>><?php asde_e( 'el inicio' ); ?></option>
								<option value="fin" <?php selected( $t['ref'], 'fin' ); ?>><?php asde_e( 'el fin' ); ?></option>
							</select>
						</td>
						<td data-label="<?php echo esc_attr( asde_a( 'Días' ) ); ?>"><input type="number" name="t_desfase_<?php echo (int) $i; ?>" value="<?php echo (int) $t['desfase']; ?>" min="-730" max="730" step="1" aria-label="<?php echo esc_attr( asde_a( 'Días' ) ); ?>"></td>
						<td data-label="<?php echo esc_attr( asde_a( 'Quitar' ) ); ?>"><input type="checkbox" name="t_quitar_<?php echo (int) $i; ?>" value="1" aria-label="<?php echo esc_attr( asde_a( 'Quitar' ) ); ?>"></td>
					</tr>
				<?php endforeach; ?>
				<?php for ( $k = 0; $k < 3; $k++ ) : ?>
					<tr>
						<td data-label="<?php echo esc_attr( asde_a( 'Tarea' ) ); ?>"><input type="text" name="n_titulo_<?php echo (int) $k; ?>" maxlength="200" placeholder="<?php echo esc_attr( asde_a( 'Tarea nueva' ) ); ?>" aria-label="<?php echo esc_attr( asde_a( 'Tarea nueva' ) ); ?>"></td>
						<td data-label="<?php echo esc_attr( asde_a( 'Detalle' ) ); ?>"><input type="text" name="n_detalle_<?php echo (int) $k; ?>" maxlength="400" aria-label="<?php echo esc_attr( asde_a( 'Detalle' ) ); ?>"></td>
						<td data-label="<?php echo esc_attr( asde_a( 'Desde' ) ); ?>">
							<select name="n_ref_<?php echo (int) $k; ?>" aria-label="<?php echo esc_attr( asde_a( 'Desde' ) ); ?>">
								<option value="inicio"><?php asde_e( 'el inicio' ); ?></option>
								<option value="fin"><?php asde_e( 'el fin' ); ?></option>
							</select>
						</td>
						<td data-label="<?php echo esc_attr( asde_a( 'Días' ) ); ?>"><input type="number" name="n_desfase_<?php echo (int) $k; ?>" value="0" min="-730" max="730" step="1" aria-label="<?php echo esc_attr( asde_a( 'Días' ) ); ?>"></td>
						<td></td>
					</tr>
				<?php endfor; ?>
			</tbody>
		</table>
		<p class="asde-help"><?php asde_e( 'Días: negativo = antes (−60 = 60 días antes del inicio); positivo = después (7 = una semana después del fin). Los cambios valen para los eventos que apliquen la plantilla a partir de ahora.' ); ?></p>
		<p><button type="submit" class="button"><?php asde_e( 'Guardar la plantilla' ); ?></button></p>
	</form>

	<?php if ( 'acampada' === $plantilla && ! $original ) : ?>
		<form method="post" action="<?php echo esc_url( $url ); ?>" data-asde-confirmar="<?php echo esc_attr( asde_js( '¿Restaurar la plantilla de Acampada a la original? Se pierden tus cambios en ella.' ) ); ?>">
			<?php wp_nonce_field( 'asde_eventos_config' ); ?>
			<input type="hidden" name="asde_action" value="plantilla_restaurar">
			<button type="submit" class="asde-btn-link"><?php asde_e( 'Restaurar la plantilla original de Acampada' ); ?></button>
		</form>
	<?php elseif ( 'acampada' !== $plantilla ) : ?>
		<form method="post" action="<?php echo esc_url( $url ); ?>" data-asde-confirmar="<?php echo esc_attr( asde_js( '¿Borrar esta plantilla? Los eventos que ya la aplicaron no cambian.' ) ); ?>">
			<?php wp_nonce_field( 'asde_eventos_config' ); ?>
			<input type="hidden" name="asde_action" value="plantilla_borrar">
			<input type="hidden" name="plantilla" value="<?php echo esc_attr( $plantilla ); ?>">
			<button type="submit" class="asde-btn-link asde-btn-danger"><?php asde_e( 'Borrar la plantilla' ); ?></button>
		</form>
	<?php endif; ?>

	<h4><?php asde_e( 'Nueva plantilla' ); ?></h4>
	<form method="post" class="asde-form" action="<?php echo esc_url( $url ); ?>">
		<?php wp_nonce_field( 'asde_eventos_config' ); ?>
		<input type="hidden" name="asde_action" value="plantilla_nueva">
		<p class="asde-form__fila">
			<label><?php asde_e( 'Nombre' ); ?> <input type="text" name="nombre" maxlength="60" placeholder="<?php echo esc_attr( asde_a( 'Por ejemplo: Salida de día' ) ); ?>" required></label>
		</p>
		<label class="asde-choice"><input type="checkbox" name="copiar" value="1"><span><?php asde_e( 'Empezar con una copia de las tareas de Acampada' ); ?></span></label>
		<p><button type="submit" class="button"><?php asde_e( 'Crear la plantilla' ); ?></button></p>
	</form>
</section>
