<?php
/**
 * Formulario de un evento (crear o editar).
 *
 * @var ?array    $e         El evento que se edita (null al crear)
 * @var ?array    $v         Lo escrito, si no se ha podido guardar
 * @var array     $cfg
 * @var string    $tipo      Tipo elegido al crear (?tipo=)
 * @var string[]  $secciones Secciones que puede convocar
 * @var string[]  $ambitos   Ámbitos que puede elegir
 * @var bool      $validado  Presupuesto validado: secciones, previstos y coste no se cambian
 * @var string    $url
 * @var string    $volver
 */
use AsdeBalears\AreaPrivada\Domain\Secciones;
use AsdeBalears\AreaPrivada\Eventos\Config;
use AsdeBalears\AreaPrivada\Eventos\EventosTable;
use AsdeBalears\AreaPrivada\Eventos\Normativa;

defined( 'ABSPATH' ) || exit;

$tipos = Config::tipos_activos( $cfg );
if ( $e && ! isset( $tipos[ $e['tipo'] ] ) && isset( $cfg['tipos'][ $e['tipo'] ] ) ) {
	$tipos[ $e['tipo'] ] = $cfg['tipos'][ $e['tipo'] ];
}
$tipo_inicial = $e ? $e['tipo'] : ( isset( $tipos[ $tipo ] ) ? $tipo : (string) array_key_first( $tipos ) );

// Valores: lo escrito (si hubo un error), los del evento o los del tipo.
$d = array(
	'tipo'            => $tipo_inicial,
	'nombre'          => $e['nombre'] ?? '',
	'ambito'          => $e['ambito'] ?? 'secciones',
	'secciones'       => $e['secciones'] ?? ( 1 === count( $secciones ) ? $secciones : array() ),
	'inicio_fecha'    => $e ? substr( $e['inicio'], 0, 10 ) : '',
	'inicio_hora'     => $e ? substr( $e['inicio'], 11, 5 ) : '10:00',
	'fin_fecha'       => $e ? substr( $e['fin'], 0, 10 ) : '',
	'fin_hora'        => $e ? substr( $e['fin'], 11, 5 ) : '18:00',
	'lugar'           => $e['lugar'] ?? '',
	'mapa'            => $e['mapa'] ?? '',
	'salida'          => $e['salida'] ?? '',
	'llegada'         => $e['llegada'] ?? '',
	'descripcion'     => $e['descripcion'] ?? '',
	'material'        => $e['material'] ?? '',
	'plazas_max'      => $e && $e['plazas_max'] ? (string) $e['plazas_max'] : '',
	'edad_min'        => null !== ( $e['edad_min'] ?? null ) ? (string) $e['edad_min'] : '',
	'edad_max'        => null !== ( $e['edad_max'] ?? null ) ? (string) $e['edad_max'] : '',
	'con_coste'       => $e ? $e['con_coste'] : ! empty( $tipos[ $tipo_inicial ]['coste'] ),
	'comunicacion'    => $e ? $e['normativa']['comunicacion'] : ( $tipos[ $tipo_inicial ]['comunicacion'] ?? 'auto' ),
	'internacional'   => $e['normativa']['internacional'] ?? false,
	'seguro_compania' => $e['normativa']['seguro_compania'] ?? '',
	'seguro_poliza'   => $e['normativa']['seguro_poliza'] ?? '',
	'riesgo'          => $e['normativa']['riesgo'] ?? false,
	'riesgo_titulado' => $e['normativa']['riesgo_titulado'] ?? false,
	'agua'            => $e['normativa']['agua'] ?? false,
	'socorrista'      => $e['normativa']['socorrista'] ?? false,
	'dependencia12'   => (string) ( $e['normativa']['dependencia12'] ?? 0 ),
	'dependencia3'    => (string) ( $e['normativa']['dependencia3'] ?? 0 ),
	'modalidad'       => $e['modalidad'] ?? 'abierta',
	'inscripcion_desde' => (string) ( $e['inscripcion_desde'] ?? '' ),
	'inscripcion_hasta' => (string) ( $e['inscripcion_hasta'] ?? '' ),
);
foreach ( $secciones as $s ) {
	$d[ 'previstas_' . $s ] = (string) ( $e['plazas'][ $s ]['previstas'] ?? 0 );
	$d[ 'maximas_' . $s ]   = (string) ( $e['plazas'][ $s ]['maximas'] ?? 0 );
}
if ( null !== $v ) {
	foreach ( array( 'internacional', 'riesgo', 'riesgo_titulado', 'agua', 'socorrista', 'con_coste' ) as $casilla ) {
		$d[ $casilla ] = ! empty( $v[ $casilla ] );
	}
	$d['secciones'] = array_map( 'strval', (array) ( $v['secciones'] ?? array() ) );
	foreach ( $d as $k => $valor ) {
		if ( is_string( $valor ) && isset( $v[ $k ] ) && is_scalar( $v[ $k ] ) ) {
			$d[ $k ] = (string) $v[ $k ];
		}
	}
}
$accion = $e ? add_query_arg(
	array(
		'evento' => $e['id'],
		'editar' => '1',
	),
	$url
) : add_query_arg( 'nuevo', '1', $url );
?>
<p class="asde-back"><a href="<?php echo esc_url( $volver ); ?>"><?php echo $e ? esc_html( '← ' . $e['nombre'] ) : asde_t( '← Agenda' ); ?></a></p>

<form method="post" class="asde-form asde-form--evento" action="<?php echo esc_url( $accion ); ?>">
	<?php wp_nonce_field( $e ? 'asde_evento_' . $e['id'] : 'asde_evento_nuevo' ); ?>
	<input type="hidden" name="asde_action" value="<?php echo $e ? 'evento_guardar' : 'evento_crear'; ?>">
	<?php if ( $e ) : ?>
		<input type="hidden" name="evento" value="<?php echo (int) $e['id']; ?>">
	<?php endif; ?>

	<section class="asde-card">
		<h2 class="asde-card__title"><?php echo $e ? esc_html( sprintf( asde_t( 'Editar %s' ), EventosTable::numero( $e ) ) ) : asde_t( 'Nuevo evento' ); ?></h2>
		<p class="asde-form__fila">
			<label><?php asde_e( 'Tipo de actividad' ); ?>
				<select name="tipo" required>
					<?php foreach ( $tipos as $t => $def ) : ?>
						<option value="<?php echo esc_attr( $t ); ?>" <?php selected( $d['tipo'], $t ); ?>><?php echo esc_html( Config::nombre_tipo( $cfg, $t ) ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<label class="asde-grid-span"><?php asde_e( 'Nombre' ); ?> <input type="text" name="nombre" value="<?php echo esc_attr( $d['nombre'] ); ?>" maxlength="120" required placeholder="<?php echo asde_a( 'p. ej. Acampada de otoño' ); ?>"></label>
		</p>

		<?php if ( count( $ambitos ) > 1 ) : ?>
			<fieldset>
				<legend><?php asde_e( 'Ámbito' ); ?></legend>
				<?php foreach ( $ambitos as $am ) : ?>
					<label class="asde-choice"><input type="radio" name="ambito" value="<?php echo esc_attr( $am ); ?>" <?php checked( $d['ambito'], $am ); ?>><span><?php echo esc_html( asde_t( EventosTable::AMBITOS[ $am ] ) ); ?></span></label>
				<?php endforeach; ?>
			</fieldset>
		<?php else : ?>
			<input type="hidden" name="ambito" value="<?php echo esc_attr( $ambitos[0] ?? 'secciones' ); ?>">
		<?php endif; ?>

		<fieldset>
			<legend><?php asde_e( 'Secciones convocadas' ); ?></legend>
			<?php if ( $validado ) : ?>
				<p class="asde-help"><?php asde_e( 'El presupuesto está validado: las secciones y los participantes previstos no se pueden cambiar (Tesorería puede reabrirlo).' ); ?></p>
				<?php foreach ( $e['secciones'] as $s ) : ?>
					<?php echo Secciones::badge( $s ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					<input type="hidden" name="secciones[]" value="<?php echo esc_attr( $s ); ?>">
				<?php endforeach; ?>
			<?php else : ?>
				<?php foreach ( $secciones as $s ) : ?>
					<label class="asde-choice asde-choice--inline"><input type="checkbox" name="secciones[]" value="<?php echo esc_attr( $s ); ?>" <?php checked( in_array( $s, $d['secciones'], true ) ); ?>><span><?php echo esc_html( Secciones::nombre( $s ) ); ?></span></label>
				<?php endforeach; ?>
				<?php if ( in_array( 'grupo', $ambitos, true ) ) : ?>
					<p class="asde-help"><?php asde_e( 'En un evento de grupo sin secciones marcadas se convoca a todas las secciones educativas.' ); ?></p>
				<?php endif; ?>
			<?php endif; ?>
		</fieldset>

		<p class="asde-form__fila">
			<label><?php asde_e( 'Empieza' ); ?> <input type="date" name="inicio_fecha" value="<?php echo esc_attr( $d['inicio_fecha'] ); ?>" required></label>
			<label><?php asde_e( 'a las' ); ?> <input type="time" name="inicio_hora" value="<?php echo esc_attr( $d['inicio_hora'] ); ?>"></label>
			<label><?php asde_e( 'Acaba' ); ?> <input type="date" name="fin_fecha" value="<?php echo esc_attr( $d['fin_fecha'] ); ?>" required></label>
			<label><?php asde_e( 'a las' ); ?> <input type="time" name="fin_hora" value="<?php echo esc_attr( $d['fin_hora'] ); ?>"></label>
		</p>
		<p class="asde-form__fila">
			<label><?php asde_e( 'Lugar' ); ?> <input type="text" name="lugar" value="<?php echo esc_attr( $d['lugar'] ); ?>" maxlength="160"></label>
			<label><?php asde_e( 'Enlace al mapa (opcional)' ); ?> <input type="url" name="mapa" value="<?php echo esc_attr( $d['mapa'] ); ?>" maxlength="255" placeholder="https://"></label>
		</p>
		<p class="asde-form__fila">
			<label><?php asde_e( 'Salida (punto y hora)' ); ?> <input type="text" name="salida" value="<?php echo esc_attr( $d['salida'] ); ?>" maxlength="160"></label>
			<label><?php asde_e( 'Llegada (punto y hora)' ); ?> <input type="text" name="llegada" value="<?php echo esc_attr( $d['llegada'] ); ?>" maxlength="160"></label>
		</p>
	</section>

	<section class="asde-card">
		<h3 class="asde-card__title"><?php asde_e( 'Participantes y plazas' ); ?></h3>
		<p class="asde-help"><?php asde_e( 'Los participantes previstos de cada sección sirven para calcular el precio y la ratio de monitores. Las plazas son el máximo (0 = sin límite).' ); ?></p>
		<table class="asde-table asde-table--plazas">
			<thead>
				<tr>
					<th scope="col"><?php asde_e( 'Sección' ); ?></th>
					<th scope="col"><?php asde_e( 'Participantes previstos' ); ?></th>
					<th scope="col"><?php asde_e( 'Plazas' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $validado ? $e['secciones'] : $secciones as $s ) : ?>
					<tr>
						<td data-label="<?php echo asde_a( 'Sección' ); ?>"><?php echo Secciones::badge( $s ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
						<td data-label="<?php echo asde_a( 'Participantes previstos' ); ?>">
							<?php if ( $validado ) : ?>
								<?php echo (int) $d[ 'previstas_' . $s ]; ?><input type="hidden" name="previstas_<?php echo esc_attr( $s ); ?>" value="<?php echo (int) $d[ 'previstas_' . $s ]; ?>">
							<?php else : ?>
								<input type="number" name="previstas_<?php echo esc_attr( $s ); ?>" value="<?php echo esc_attr( $d[ 'previstas_' . $s ] ?? '0' ); ?>" min="0" max="999" inputmode="numeric" aria-label="<?php echo esc_attr( sprintf( asde_t( 'Participantes previstos de %s' ), Secciones::nombre( $s ) ) ); ?>">
							<?php endif; ?>
						</td>
						<td data-label="<?php echo asde_a( 'Plazas' ); ?>"><input type="number" name="maximas_<?php echo esc_attr( $s ); ?>" value="<?php echo esc_attr( $d[ 'maximas_' . $s ] ?? '0' ); ?>" min="0" max="999" inputmode="numeric" aria-label="<?php echo esc_attr( sprintf( asde_t( 'Plazas de %s' ), Secciones::nombre( $s ) ) ); ?>"></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<p class="asde-form__fila">
			<label><?php asde_e( 'Plazas en total (0 = sin límite)' ); ?> <input type="number" name="plazas_max" value="<?php echo esc_attr( $d['plazas_max'] ); ?>" min="0" inputmode="numeric"></label>
			<label><?php asde_e( 'Edad mínima (opcional)' ); ?> <input type="number" name="edad_min" value="<?php echo esc_attr( $d['edad_min'] ); ?>" min="0" max="99" inputmode="numeric"></label>
			<label><?php asde_e( 'Edad máxima (opcional)' ); ?> <input type="number" name="edad_max" value="<?php echo esc_attr( $d['edad_max'] ); ?>" min="0" max="99" inputmode="numeric"></label>
		</p>
	</section>

	<section class="asde-card">
		<h3 class="asde-card__title"><?php asde_e( 'Inscripción' ); ?></h3>
		<fieldset>
			<legend><?php asde_e( 'Modalidad' ); ?></legend>
			<?php foreach ( EventosTable::MODALIDADES as $m => $etiqueta ) : ?>
				<label class="asde-check"><input type="radio" name="modalidad" value="<?php echo esc_attr( $m ); ?>"<?php checked( $d['modalidad'], $m ); ?>> <?php echo esc_html( asde_t( $etiqueta ) ); ?></label>
			<?php endforeach; ?>
		</fieldset>
		<p class="asde-form__fila">
			<label><?php asde_e( 'Inscripción desde (opcional)' ); ?> <input type="date" name="inscripcion_desde" value="<?php echo esc_attr( $d['inscripcion_desde'] ); ?>"></label>
			<label><?php asde_e( 'Inscripción hasta (opcional)' ); ?> <input type="date" name="inscripcion_hasta" value="<?php echo esc_attr( $d['inscripcion_hasta'] ); ?>"></label>
		</p>
		<p class="asde-help"><?php asde_e( 'Sin fechas, la inscripción se abre al publicar y se cierra cuando empieza el evento. Pasado el plazo, solo inscribe el equipo. Si no quedan plazas, las nuevas inscripciones entran en la lista de espera.' ); ?></p>
	</section>

	<section class="asde-card">
		<h3 class="asde-card__title"><?php asde_e( 'Para las familias' ); ?></h3>
		<p>
			<label for="asde-ev-descripcion"><?php asde_e( 'Descripción o circular' ); ?></label>
			<textarea id="asde-ev-descripcion" name="descripcion" rows="5" maxlength="4000"><?php echo esc_textarea( $d['descripcion'] ); ?></textarea>
		</p>
		<p>
			<label for="asde-ev-material"><?php asde_e( 'Material personal (lo que lleva cada participante)' ); ?></label>
			<textarea id="asde-ev-material" name="material" rows="4" maxlength="4000" placeholder="<?php echo asde_a( 'p. ej. saco de dormir, esterilla, linterna, uniforme…' ); ?>"><?php echo esc_textarea( $d['material'] ); ?></textarea>
		</p>
	</section>

	<section class="asde-card">
		<h3 class="asde-card__title"><?php asde_e( 'Coste, seguro y normativa' ); ?></h3>
		<?php if ( $validado ) : ?>
			<input type="hidden" name="con_coste" value="<?php echo $d['con_coste'] ? '1' : ''; ?>">
			<p><?php echo $d['con_coste'] ? asde_t( 'Tiene coste para las familias (presupuesto validado).' ) : asde_t( 'Sin coste para las familias.' ); ?></p>
		<?php else : ?>
			<label class="asde-choice"><input type="checkbox" name="con_coste" value="1" <?php checked( $d['con_coste'] ); ?>><span><?php asde_e( 'Tiene coste para las familias (el precio sale del presupuesto, que valida Tesorería)' ); ?></span></label>
		<?php endif; ?>
		<p>
			<label for="asde-ev-comunicacion"><?php asde_e( 'Comunicación a ASDE Baleares (30 días antes)' ); ?></label>
			<select id="asde-ev-comunicacion" name="comunicacion">
				<?php foreach ( asde_ta( Normativa::COMUNICACION ) as $c => $etiqueta ) : ?>
					<option value="<?php echo esc_attr( $c ); ?>" <?php selected( $d['comunicacion'], $c ); ?>><?php echo esc_html( $etiqueta ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
		<label class="asde-choice"><input type="checkbox" name="internacional" value="1" <?php checked( $d['internacional'] ); ?>><span><?php asde_e( 'Internacional (toda o parte fuera de España): necesita un seguro aparte' ); ?></span></label>
		<p class="asde-form__fila asde-subopciones">
			<label><?php asde_e( 'Compañía del seguro' ); ?> <input type="text" name="seguro_compania" value="<?php echo esc_attr( $d['seguro_compania'] ); ?>" maxlength="100"></label>
			<label><?php asde_e( 'Nº de póliza' ); ?> <input type="text" name="seguro_poliza" value="<?php echo esc_attr( $d['seguro_poliza'] ); ?>" maxlength="60"></label>
		</p>
		<label class="asde-choice"><input type="checkbox" name="riesgo" value="1" <?php checked( $d['riesgo'] ); ?>><span><?php asde_e( 'Hay actividades de riesgo especializado (alta montaña, barrancos, escalada, buceo, náutica)' ); ?></span></label>
		<div class="asde-subopciones">
			<label class="asde-choice asde-choice--inline"><input type="checkbox" name="riesgo_titulado" value="1" <?php checked( $d['riesgo_titulado'] ); ?>><span><?php asde_e( 'Con personal titulado en esa actividad' ); ?></span></label>
		</div>
		<label class="asde-choice"><input type="checkbox" name="agua" value="1" <?php checked( $d['agua'] ); ?>><span><?php asde_e( 'Hay piscina o mar' ); ?></span></label>
		<div class="asde-subopciones">
			<label class="asde-choice asde-choice--inline"><input type="checkbox" name="socorrista" value="1" <?php checked( $d['socorrista'] ); ?>><span><?php asde_e( 'Habrá un socorrista presente' ); ?></span></label>
		</div>
		<p class="asde-help"><?php asde_e( 'Participantes con dependencia reconocida: escribe solo cuántos (sin nombres). La normativa pide más monitores de apoyo.' ); ?></p>
		<p class="asde-form__fila">
			<label><?php asde_e( 'Con grado I o II' ); ?> <input type="number" name="dependencia12" value="<?php echo esc_attr( $d['dependencia12'] ); ?>" min="0" max="99" inputmode="numeric"></label>
			<label><?php asde_e( 'Con grado III' ); ?> <input type="number" name="dependencia3" value="<?php echo esc_attr( $d['dependencia3'] ); ?>" min="0" max="99" inputmode="numeric"></label>
		</p>
	</section>

	<p>
		<button type="submit" class="button button-primary"><?php echo $e ? asde_t( 'Guardar los cambios' ) : asde_t( 'Crear el evento (borrador)' ); ?></button>
		<a href="<?php echo esc_url( $volver ); ?>"><?php asde_e( 'Cancelar' ); ?></a>
	</p>
</form>
