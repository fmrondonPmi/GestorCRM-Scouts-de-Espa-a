<?php
/**
 * Asistente para crear un evento (doc 66): seis pasos en un solo formulario. Sin JavaScript se ven todos los pasos seguidos;
 * con él (assets/js/asistente.js) se enseña uno cada vez, con avisos y resumen en vivo.
 *
 * @var ?array   $copia      Evento anterior del que se parte (null = en blanco)
 * @var ?array   $v          Lo escrito, si no se ha podido guardar
 * @var array    $cfg
 * @var string   $tipo       Tipo elegido (?tipo=)
 * @var string[] $secciones  Secciones que puede convocar
 * @var string[] $ambitos    Ámbitos que puede elegir
 * @var array    $candidatos Adultos que pueden estar en el equipo (nº => Persona)
 * @var array[]  $anteriores Últimos eventos que ve (para copiar)
 * @var string   $url
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Domain\Secciones;
use AsdeBalears\AreaPrivada\Eventos\Config;
use AsdeBalears\AreaPrivada\Eventos\EventosTable;
use AsdeBalears\AreaPrivada\Eventos\Formulario;
use AsdeBalears\AreaPrivada\Eventos\Normativa;

defined( 'ABSPATH' ) || exit;

$tipos  = Formulario::tipos( $cfg, $copia );
$d      = Formulario::valores( $copia, $v, $cfg, $secciones, $tipo, null !== $copia );
$span   = array( 'span' => array( 'data-n' => true ) );
$si_no  = static function ( string $nombre, bool $valor, string $sub = '' ): void {
	?>
	<span class="asde-asistente__sino" data-sino="<?php echo esc_attr( $sub ); ?>">
		<label class="asde-chip"><input type="radio" name="<?php echo esc_attr( $nombre ); ?>" value="1" <?php checked( $valor ); ?>><span><?php esc_html_e( 'Yes', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span></label>
		<label class="asde-chip"><input type="radio" name="<?php echo esc_attr( $nombre ); ?>" value="0" <?php checked( ! $valor ); ?>><span><?php esc_html_e( 'No', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span></label>
	</span>
	<?php
};
$pasos  = array(
	1 => __( 'What', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
	2 => __( 'When and where', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
	3 => __( 'How many', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
	4 => __( 'Money and rules', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
	5 => __( 'Sign-up', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
	6 => __( 'Review', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
);
$datos  = sprintf(
	'data-noches="%d" data-menores="%d" data-dias="%d" data-por-monitor="%d" data-menores-secciones="%s" data-lang="%s" data-inicial="%d"',
	Normativa::NOCHES_COMUNICACION,
	Normativa::MENORES_COMUNICACION,
	Normativa::DIAS_COMUNICACION,
	Normativa::PARTICIPANTES_MONITOR,
	esc_attr( implode( ',', Normativa::SECCIONES_MENORES ) ),
	esc_attr( determine_locale() ),
	null === $v ? 1 : count( $pasos ) // Tras un error se vuelve a enseñar el asistente en el último paso, con el aviso arriba.
);
?>
<p class="asde-back"><a href="<?php echo esc_url( $url ); ?>"><?php esc_html_e( '← Agenda', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a></p>

<form method="post" class="asde-form asde-asistente" action="<?php echo esc_url( add_query_arg( 'nuevo', '1', $url ) ); ?>" data-asde-asistente <?php echo $datos; // phpcs:ignore WordPress.Security.EscapeOutput -- escapado arriba ?>>
	<?php wp_nonce_field( 'asde_evento_nuevo' ); ?>
	<input type="hidden" name="asde_action" value="evento_crear">
	<input type="hidden" name="comunicacion" value="<?php echo esc_attr( $d['comunicacion'] ); ?>">

	<h2 class="asde-card__title"><?php esc_html_e( 'New event', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h2>
	<ol class="asde-asistente__pasos" data-asde-pasos hidden>
		<?php foreach ( $pasos as $n => $nombre ) : ?>
			<li><button type="button" data-ir="<?php echo (int) $n; ?>"><b><?php echo (int) $n; ?></b> <span><?php echo esc_html( $nombre ); ?></span></button></li>
		<?php endforeach; ?>
	</ol>

	<?php /* 1 · Qué */ ?>
	<section class="asde-card asde-paso" data-asde-no-plegable data-paso="1">
		<h3 class="asde-card__title"><?php esc_html_e( 'What are you going to do?', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h3>
		<div class="asde-asistente__tarjetas">
			<?php foreach ( $tipos as $k => $def ) : ?>
				<label class="asde-tarjeta"><input type="radio" name="tipo" value="<?php echo esc_attr( $k ); ?>" data-coste="<?php echo ! empty( $def['coste'] ) ? '1' : '0'; ?>" data-comunicacion="<?php echo esc_attr( $def['comunicacion'] ?? 'auto' ); ?>" <?php checked( $d['tipo'], $k ); ?> required><span><b><?php echo esc_html( Config::nombre_tipo( $cfg, $k ) ); ?></b></span></label>
			<?php endforeach; ?>
		</div>
		<p>
			<label for="asde-as-nombre"><?php esc_html_e( 'Name', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
			<input type="text" id="asde-as-nombre" name="nombre" value="<?php echo esc_attr( $d['nombre'] ); ?>" maxlength="120" required placeholder="<?php echo esc_attr( esc_attr__( 'e.g. Autumn camp', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
		</p>
		<?php if ( count( $ambitos ) > 1 ) : ?>
			<fieldset>
				<legend><?php esc_html_e( 'Scope', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></legend>
				<?php foreach ( $ambitos as $am ) : ?>
					<label class="asde-chip"><input type="radio" name="ambito" value="<?php echo esc_attr( $am ); ?>" <?php checked( $d['ambito'], $am ); ?>><span><?php echo esc_html( EventosTable::ambitos()[ $am ] ); ?></span></label>
				<?php endforeach; ?>
			</fieldset>
		<?php else : ?>
			<input type="hidden" name="ambito" value="<?php echo esc_attr( $ambitos[0] ?? 'secciones' ); ?>">
		<?php endif; ?>
		<fieldset>
			<legend><?php esc_html_e( 'Who is going? (invited sections)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></legend>
			<?php foreach ( $secciones as $s ) : ?>
				<label class="asde-chip"><input type="checkbox" name="secciones[]" value="<?php echo esc_attr( $s ); ?>" <?php checked( in_array( $s, $d['secciones'], true ) ); ?>><span><?php echo esc_html( Secciones::nombre( $s ) ); ?></span></label>
			<?php endforeach; ?>
			<?php if ( in_array( 'grupo', $ambitos, true ) ) : ?>
				<p class="asde-help"><?php esc_html_e( 'In a group event with no sections ticked, all the educational sections are invited.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
			<?php endif; ?>
		</fieldset>
		<?php if ( $anteriores ) : ?>
			<details class="asde-asistente__copiar" <?php echo $copia ? 'open' : ''; ?>>
				<summary><?php echo esc_html( $copia ? sprintf( /* translators: %s: text */ __( 'Copied from "%s" (dates one year later)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $copia['nombre'] ) : __( 'Does it look like a previous one? Copy it', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?></summary>
				<p class="asde-help"><?php esc_html_e( 'The data, place and texts are copied and the dates move one year on. Sign-ups, payments and the team are not copied.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
				<ul>
					<?php foreach ( $anteriores as $o ) : ?>
						<li><a href="<?php echo esc_url( add_query_arg( array( 'nuevo' => '1', 'copiar' => $o['id'] ), $url ) ); ?>"><?php echo esc_html( $o['nombre'] . ' · ' . wp_date( 'd/m/Y', EventosTable::marca( $o['inicio'] ) ) ); ?></a></li>
					<?php endforeach; ?>
				</ul>
			</details>
		<?php endif; ?>
	</section>

	<?php /* 2 · Cuándo y dónde */ ?>
	<section class="asde-card asde-paso" data-asde-no-plegable data-paso="2">
		<h3 class="asde-card__title"><?php esc_html_e( 'When and where?', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h3>
		<p class="asde-form__fila">
			<label><?php esc_html_e( 'Starts', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <input type="date" name="inicio_fecha" value="<?php echo esc_attr( $d['inicio_fecha'] ); ?>" required></label>
			<label><?php esc_html_e( 'at', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <input type="time" name="inicio_hora" value="<?php echo esc_attr( $d['inicio_hora'] ); ?>"></label>
			<label><?php esc_html_e( 'Ends', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <input type="date" name="fin_fecha" value="<?php echo esc_attr( $d['fin_fecha'] ); ?>" required></label>
			<label><?php esc_html_e( 'at', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <input type="time" name="fin_hora" value="<?php echo esc_attr( $d['fin_hora'] ); ?>"></label>
		</p>
		<p class="asde-help" data-duracion hidden>
			<span data-dia hidden><?php esc_html_e( 'A one-day activity, with no overnight stay.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span>
			<span data-noche hidden><?php echo wp_kses( sprintf( /* translators: %s: number of nights */ __( 'Nights away: %s.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), '<span data-n="noches"></span>' ), $span ); ?></span>
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

	<?php /* 3 · Cuántos */ ?>
	<section class="asde-card asde-paso" data-asde-no-plegable data-paso="3">
		<h3 class="asde-card__title"><?php esc_html_e( 'How many are you?', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h3>
		<p class="asde-help"><?php esc_html_e( 'The expected participants give the price and the monitors you need. If more sign up later, the price does not change. Places are the maximum (0 = no limit).', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
		<?php foreach ( $secciones as $s ) : ?>
			<div class="asde-form__fila asde-asistente__plazas" data-seccion="<?php echo esc_attr( $s ); ?>">
				<span><?php echo wp_kses( Secciones::badge( $s ), asde_html_permitido() ); ?></span>
				<label><?php esc_html_e( 'Expected participants', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <input type="number" name="previstas_<?php echo esc_attr( $s ); ?>" value="<?php echo esc_attr( $d[ 'previstas_' . $s ] ); ?>" min="0" max="999" inputmode="numeric"></label>
				<label><?php esc_html_e( 'Places', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <input type="number" name="maximas_<?php echo esc_attr( $s ); ?>" value="<?php echo esc_attr( $d[ 'maximas_' . $s ] ); ?>" min="0" max="999" inputmode="numeric"></label>
			</div>
		<?php endforeach; ?>
		<p class="asde-form__fila">
			<label><?php esc_html_e( 'Minimum age (optional)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <input type="number" name="edad_min" value="<?php echo esc_attr( $d['edad_min'] ); ?>" min="0" max="99" inputmode="numeric"></label>
			<label><?php esc_html_e( 'Maximum age (optional)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <input type="number" name="edad_max" value="<?php echo esc_attr( $d['edad_max'] ); ?>" min="0" max="99" inputmode="numeric"></label>
		</p>
		<input type="hidden" name="plazas_max" value="<?php echo esc_attr( $d['plazas_max'] ); ?>">
		<?php if ( $candidatos ) : ?>
			<p class="asde-help"><?php esc_html_e( 'Event team (optional: you can complete it later).', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
			<p>
				<label for="asde-as-director"><?php esc_html_e( 'Director', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
				<select id="asde-as-director" name="director">
					<option value=""><?php esc_html_e( 'Choose later', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></option>
					<?php foreach ( $candidatos as $num => $p ) : ?>
						<option value="<?php echo esc_attr( (string) $num ); ?>" <?php selected( (string) ( $v['director'] ?? '' ), (string) $num ); ?>><?php echo esc_html( $p->nombre_completo() ); ?></option>
					<?php endforeach; ?>
				</select>
			</p>
			<fieldset>
				<legend><?php esc_html_e( 'Monitors', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></legend>
				<?php foreach ( $candidatos as $num => $p ) : ?>
					<label class="asde-chip"><input type="checkbox" name="monitor[]" value="<?php echo esc_attr( (string) $num ); ?>" <?php checked( in_array( (string) $num, array_map( 'strval', (array) ( $v['monitor'] ?? array() ) ), true ) ); ?>><span><?php echo esc_html( $p->nombre_completo() ); ?></span></label>
				<?php endforeach; ?>
			</fieldset>
		<?php endif; ?>
		<p class="asde-aviso asde-aviso--ok" data-ratio hidden>
			<?php echo wp_kses( sprintf( /* translators: 1: number of participants, 2: number of monitors */ __( '%1$s expected participants: you need a director and at least %2$s monitors.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), '<span data-n="participantes"></span>', '<span data-n="monitores"></span>' ), $span ); ?>
		</p>
	</section>

	<?php /* 4 · Dinero y normativa */ ?>
	<section class="asde-card asde-paso" data-asde-no-plegable data-paso="4">
		<h3 class="asde-card__title"><?php esc_html_e( 'Money and rules', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h3>
		<p class="asde-help"><?php esc_html_e( 'Answer yes or no. We only ask for more when it is needed.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
		<div class="asde-asistente__pregunta"><span><?php esc_html_e( 'Do families pay?', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?><small><?php esc_html_e( 'The price comes from the budget, which Treasury validates before publishing.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></small></span><?php $si_no( 'con_coste', $d['con_coste'] ); ?></div>
		<div class="asde-asistente__pregunta"><span><?php esc_html_e( 'Is it outside Spain?', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?><small><?php esc_html_e( 'It needs separate insurance.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></small></span><?php $si_no( 'internacional', $d['internacional'], 'internacional' ); ?></div>
		<p class="asde-form__fila" data-sub="internacional">
			<label><?php esc_html_e( 'Insurance company', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <input type="text" name="seguro_compania" value="<?php echo esc_attr( $d['seguro_compania'] ); ?>" maxlength="100"></label>
			<label><?php esc_html_e( 'Policy no.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <input type="text" name="seguro_poliza" value="<?php echo esc_attr( $d['seguro_poliza'] ); ?>" maxlength="60"></label>
		</p>
		<div class="asde-asistente__pregunta"><span><?php esc_html_e( 'Is there a swimming pool or sea?', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span><?php $si_no( 'agua', $d['agua'], 'agua' ); ?></div>
		<p data-sub="agua"><label class="asde-choice"><input type="checkbox" name="socorrista" value="1" <?php checked( $d['socorrista'] ); ?>><span><?php esc_html_e( 'A lifeguard will be present', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span></label></p>
		<div class="asde-asistente__pregunta"><span><?php esc_html_e( 'Are there specialised-risk activities?', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?><small><?php esc_html_e( 'High mountain, canyoning, climbing, diving, sailing.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></small></span><?php $si_no( 'riesgo', $d['riesgo'], 'riesgo' ); ?></div>
		<p data-sub="riesgo"><label class="asde-choice"><input type="checkbox" name="riesgo_titulado" value="1" <?php checked( $d['riesgo_titulado'] ); ?>><span><?php esc_html_e( 'With staff qualified in that activity', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span></label></p>
		<div class="asde-asistente__pregunta"><span><?php esc_html_e( 'Are there participants with recognised dependency?', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?><small><?php esc_html_e( 'Write only how many (no names).', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></small></span><?php $si_no( 'dependencia', (bool) ( (int) $d['dependencia12'] + (int) $d['dependencia3'] ), 'dependencia' ); ?></div>
		<p class="asde-form__fila" data-sub="dependencia">
			<label><?php esc_html_e( 'With grade I or II', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <input type="number" name="dependencia12" value="<?php echo esc_attr( $d['dependencia12'] ); ?>" min="0" max="99" inputmode="numeric"></label>
			<label><?php esc_html_e( 'With grade III', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <input type="number" name="dependencia3" value="<?php echo esc_attr( $d['dependencia3'] ); ?>" min="0" max="99" inputmode="numeric"></label>
		</p>
		<div class="asde-aviso" data-tendras hidden>
			<b><?php esc_html_e( 'With what you have said, you will have to:', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></b>
			<ul>
				<li data-aviso="coste" hidden><?php esc_html_e( 'Make the budget and ask Treasury to validate it (it cannot be published without it).', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></li>
				<li data-aviso="comunicacion" hidden><?php echo wp_kses( sprintf( /* translators: %s: date */ __( 'Report it to ASDE Baleares 30 days before: it has enough nights and minors. At the latest on %s.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), '<span data-n="limite"></span>' ), $span ); ?></li>
				<li data-aviso="internacional" hidden><?php esc_html_e( 'Take out separate insurance (international activity).', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></li>
				<li data-aviso="agua" hidden><?php esc_html_e( 'Plan a lifeguard or the water-safety measures.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></li>
				<li data-aviso="riesgo" hidden><?php esc_html_e( 'Make sure there is qualified staff for the risk activity.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></li>
				<li data-aviso="dependencia" hidden><?php esc_html_e( 'Plan extra support monitors because of the dependency.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></li>
			</ul>
		</div>
	</section>

	<?php /* 5 · Inscripción */ ?>
	<section class="asde-card asde-paso" data-asde-no-plegable data-paso="5">
		<h3 class="asde-card__title"><?php esc_html_e( 'Sign-up and message for families', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h3>
		<fieldset>
			<legend><?php esc_html_e( 'How do they sign up?', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></legend>
			<?php foreach ( EventosTable::modalidades() as $m => $etiqueta ) : ?>
				<label class="asde-chip"><input type="radio" name="modalidad" value="<?php echo esc_attr( $m ); ?>" <?php checked( $d['modalidad'], $m ); ?>><span><?php echo esc_html( $etiqueta ); ?></span></label>
			<?php endforeach; ?>
		</fieldset>
		<p class="asde-form__fila">
			<label><?php esc_html_e( 'Sign-up from (optional)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <input type="date" name="inscripcion_desde" value="<?php echo esc_attr( $d['inscripcion_desde'] ); ?>"></label>
			<label><?php esc_html_e( 'Sign-up until (optional)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <input type="date" name="inscripcion_hasta" value="<?php echo esc_attr( $d['inscripcion_hasta'] ); ?>"></label>
		</p>
		<p class="asde-help"><?php esc_html_e( 'Without dates, sign-up opens on publishing and closes when the event starts.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
		<p>
			<label for="asde-as-descripcion"><?php esc_html_e( 'Description or circular', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
			<textarea id="asde-as-descripcion" name="descripcion" rows="4" maxlength="4000"><?php echo esc_textarea( $d['descripcion'] ); ?></textarea>
		</p>
		<p>
			<label for="asde-as-material"><?php esc_html_e( 'Personal equipment (what each participant brings)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
			<textarea id="asde-as-material" name="material" rows="3" maxlength="4000" placeholder="<?php echo esc_attr( esc_attr__( 'e.g. sleeping bag, mat, torch, uniform…', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo esc_textarea( $d['material'] ); ?></textarea>
		</p>
	</section>

	<?php /* 6 · Revisar */ ?>
	<section class="asde-card asde-paso" data-asde-no-plegable data-paso="6">
		<h3 class="asde-card__title"><?php esc_html_e( 'Review and create the draft', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h3>
		<p class="asde-help"><?php esc_html_e( 'Nothing is published yet: a draft is created and you can change anything from its page.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
		<dl class="asde-asistente__resumen" data-resumen>
			<dt><?php esc_html_e( 'Event', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></dt><dd data-r="evento"></dd>
			<dt><?php esc_html_e( 'Invited sections', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></dt><dd data-r="secciones"></dd>
			<dt><?php esc_html_e( 'Dates', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></dt><dd data-r="fechas"></dd>
			<dt><?php esc_html_e( 'Place', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></dt><dd data-r="lugar"></dd>
			<dt><?php esc_html_e( 'Expected participants', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></dt><dd data-r="participantes"></dd>
			<dt><?php esc_html_e( 'Do families pay?', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></dt><dd data-r="coste"></dd>
		</dl>
		<div class="asde-aviso asde-aviso--ok">
			<b><?php esc_html_e( 'After creating it you will see its page with what is left to do:', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></b>
			<ul>
				<li><?php esc_html_e( 'The budget, if families pay, and Treasury\'s validation', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></li>
				<li><?php esc_html_e( 'The full event team', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></li>
				<li><?php esc_html_e( 'The menu and the shopping list', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></li>
				<li><?php esc_html_e( 'The task calendar, with the dates already worked out', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></li>
			</ul>
		</div>
	</section>

	<p class="asde-asistente__botones">
		<button type="button" class="button" data-atras hidden><?php esc_html_e( '← Back', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
		<button type="button" class="button button-primary" data-adelante hidden><?php esc_html_e( 'Next →', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
		<button type="submit" class="button button-primary" data-crear><?php esc_html_e( 'Create the event (draft)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
		<a href="<?php echo esc_url( $url ); ?>"><?php esc_html_e( 'Cancel', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a>
	</p>
</form>
