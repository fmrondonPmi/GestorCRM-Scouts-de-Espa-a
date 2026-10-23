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
		<table class="asde-table">
			<thead><tr><th scope="col"><?php asde_e( 'Familia' ); ?></th><th scope="col"><?php asde_e( 'Socios' ); ?></th><th scope="col"><?php asde_e( 'Titular · cuenta' ); ?></th><th scope="col"><?php asde_e( 'Secuencia' ); ?></th><th scope="col"><?php asde_e( 'Importe' ); ?></th></tr></thead>
			<tbody>
				<?php foreach ( $prep['lineas'] as $l ) : ?>
					<tr>
						<td data-label="<?php echo esc_attr( asde_a( 'Familia' ) ); ?>"><?php echo esc_html( $l['familia_n'] ); ?></td>
						<td data-label="<?php echo esc_attr( asde_a( 'Socios' ) ); ?>"><?php echo esc_html( $nombres( $familias[ $l['familia'] ] ?? array( 'miembros' => array() ) ) ); ?></td>
						<td data-label="<?php echo esc_attr( asde_a( 'Titular' ) ); ?>"><?php echo esc_html( $l['titular'] . ' · ' . $l['iban_mask'] ); ?></td>
						<td data-label="<?php echo esc_attr( asde_a( 'Secuencia' ) ); ?>"><?php echo esc_html( 'FRST' === $l['secuencia'] ? asde_t( 'Primer cobro (FRST)' ) : asde_t( 'Recurrente (RCUR)' ) ); ?></td>
						<td data-label="<?php echo esc_attr( asde_a( 'Importe' ) ); ?>"><?php echo esc_html( Calculadora::euros( $l['centimos'] / 100 ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
			<tfoot><tr><th scope="row" colspan="4"><?php asde_e( 'Total ·' ); ?> <?php echo esc_html( Remesas::adeudos( count( $prep['lineas'] ) ) ); ?></th><td><strong><?php echo esc_html( Calculadora::euros( $prep['total'] / 100 ) ); ?></strong></td></tr></tfoot>
		</table>
	<?php endif; ?>
	<?php if ( $prep['omitidas'] ) : ?>
		<h4><?php asde_e( 'No se incluyen' ); ?></h4>
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
	<h2><?php asde_e( 'Domiciliaciones SEPA' ); ?></h2>
	<p><?php asde_e( 'Las familias autorizan en «Mis pagos» que se les cobre la cuota por' ); ?> <strong><?php asde_e( 'adeudo directo SEPA' ); ?></strong><?php asde_e( '. Aquí se generan los ficheros para el banco (formato XML' ); ?> <code><?php asde_e( 'pain.008' ); ?></code><?php asde_e( ', «Cuaderno 19.14»), se avisa a las familias de cada cargo y se registran los cobros.' ); ?></p>
	<ol class="asde-lista-pasos">
		<li><strong><?php asde_e( 'Datos del acreedor' ); ?></strong> <?php asde_e( '(el grupo): nombre, identificador del acreedor (ICS) e IBAN. Los da el banco al contratar las domiciliaciones.' ); ?></li>
		<li><?php asde_e( 'Las familias' ); ?> <strong><?php asde_e( 'firman su autorización' ); ?></strong> <?php asde_e( 'en «Mis pagos».' ); ?></li>
		<li><strong><?php asde_e( 'Nueva remesa' ); ?></strong><?php asde_e( ': eliges el plazo y la fecha de cobro, revisas lo que se cobra a cada familia y descargas el fichero.' ); ?></li>
		<li><?php asde_e( 'Súbelo al banco (Colonya Directa: «Remesas de adeudos») y márcala como' ); ?> <strong><?php asde_e( 'enviada' ); ?></strong>. <strong><?php asde_e( 'Avisa a las familias' ); ?></strong> <?php asde_e( 'con los días de antelación que has puesto en los datos del acreedor.' ); ?></li>
		<li><?php asde_e( 'Cuando el banco cobre,' ); ?> <strong><?php asde_e( 'registra los cobros' ); ?></strong><?php asde_e( ': pasan a ser pagos de la familia. Marca los devueltos.' ); ?></li>
	</ol>
</section>

<section class="asde-card">
	<h3><?php asde_e( 'Datos del acreedor' ); ?></h3>
	<?php if ( $errores_cfg ) : ?>
		<div class="asde-notice asde-notice--warning" role="status">
			<strong><?php asde_e( 'Falta completar:' ); ?></strong>
			<ul><?php foreach ( $errores_cfg as $e ) : ?><li><?php echo esc_html( $e ); ?></li><?php endforeach; ?></ul>
		</div>
	<?php elseif ( $ics_aviso ) : ?>
		<div class="asde-notice asde-notice--warning" role="status"><?php asde_e( 'Las cifras de control del identificador del acreedor no cuadran con su NIF: revisa que lo hayas copiado tal como lo da el banco. (Solo es un aviso.)' ); ?></div>
	<?php endif; ?>
	<form method="post" class="asde-form">
		<?php wp_nonce_field( 'asde_sepa_config' ); ?>
		<input type="hidden" name="asde_action" value="sepa_config">
		<input type="hidden" name="ronda" value="<?php echo esc_attr( $ronda ); ?>">
		<div class="asde-grid-2">
			<p><label for="asde-sepa-nombre"><?php asde_e( 'Nombre del acreedor' ); ?></label><input type="text" id="asde-sepa-nombre" name="nombre" maxlength="70" value="<?php echo esc_attr( $cfg['nombre'] ); ?>" placeholder="<?php echo esc_attr( asde_a( 'p. ej. Grupo Scout Myotragus 684' ) ); ?>"><span class="asde-help"><?php asde_e( 'Como aparecerá en el banco de las familias (sin tildes).' ); ?></span></p>
			<p><label for="asde-sepa-ics"><?php asde_e( 'Identificador del acreedor (ICS)' ); ?></label><input type="text" id="asde-sepa-ics" name="ics" maxlength="35" value="<?php echo esc_attr( $cfg['ics'] ); ?>" placeholder="<?php echo esc_attr( asde_a( 'ES00ZZZG00000000' ) ); ?>"><span class="asde-help"><?php asde_e( 'Empieza por ES y son 16 caracteres.' ); ?></span></p>
			<p><label for="asde-sepa-iban"><?php asde_e( 'IBAN del acreedor' ); ?></label><input type="text" id="asde-sepa-iban" name="iban" maxlength="40" value="" placeholder="<?php echo '' !== $cfg['iban'] ? esc_attr( \AsdeBalears\AreaPrivada\Sepa\Iban::enmascarar( $cfg['iban'] ) . asde_t( ' (guardado; déjalo vacío para conservarlo)' ) ) : 'ES00 0000 0000 0000 0000 0000'; ?>" autocomplete="off"><span class="asde-help"><?php asde_e( 'La cuenta donde el banco ingresa lo cobrado. Se guarda cifrado.' ); ?></span></p>
			<p><label for="asde-sepa-bic"><?php asde_e( 'BIC (opcional)' ); ?></label><input type="text" id="asde-sepa-bic" name="bic" maxlength="11" value="<?php echo esc_attr( $cfg['bic'] ); ?>"></p>
			<p>
				<span class="asde-label"><?php asde_e( 'Dirección del acreedor' ); ?></span>
				<?php $dir_acreedor = trim( implode( ', ', array_filter( array( $cfg['calle'], trim( $cfg['cp'] . ' ' . $cfg['poblacion'] ), $cfg['provincia'] ) ) ) ); ?>
				<span class="asde-valor"><?php echo esc_html( '' !== $dir_acreedor ? $dir_acreedor : '–' ); ?></span>
				<span class="asde-help"><?php asde_e( 'Sale de' ); ?> <a href="<?php echo esc_url( \AsdeBalears\AreaPrivada\Frontend\Pages::url( 'grupo_datos' ) ); ?>"><?php asde_e( 'Datos del grupo' ); ?></a>.</span>
			</p>
			<p><label for="asde-sepa-aviso"><?php asde_e( 'Días de aviso antes de cada cargo' ); ?></label><input type="number" id="asde-sepa-aviso" name="dias_aviso" min="1" max="30" value="<?php echo (int) $cfg['dias_aviso']; ?>"><span class="asde-help"><?php asde_e( 'La norma pide 14 días salvo que se pacte otro plazo: aquí se pacta y sale en la autorización.' ); ?></span></p>
			<p><label for="asde-sepa-concepto"><?php asde_e( 'Concepto de los cargos' ); ?></label><input type="text" id="asde-sepa-concepto" name="concepto" maxlength="90" value="<?php echo esc_attr( $cfg['concepto'] ); ?>"><span class="asde-help"><?php asde_e( 'Puedes usar {grupo} y {ronda}. Al final se añade el nº de familia.' ); ?></span></p>
		</div>
		<p>
			<label class="asde-choice"><input type="checkbox" name="activo" value="1" <?php checked( $cfg['activo'] ); ?>><span><?php asde_e( 'Ofrecer la domiciliación a las familias (aparece en «Mis pagos»)' ); ?></span></label>
		</p>
		<p><button type="submit" class="button"><?php asde_e( 'Guardar los datos del acreedor' ); ?></button></p>
	</form>
</section>

<?php if ( $lista ) : ?>
	<section class="asde-card">
		<h3><?php asde_e( 'Nueva remesa · ronda' ); ?> <?php echo esc_html( $ronda ); ?></h3>
		<form method="get" action="<?php echo esc_url( $url ); ?>" class="asde-form">
			<input type="hidden" name="ronda" value="<?php echo esc_attr( $ronda ); ?>">
			<input type="hidden" name="preparar" value="1">
			<div class="asde-grid-2">
				<p>
					<label for="asde-sepa-plazo"><?php asde_e( 'Qué se cobra' ); ?></label>
					<select id="asde-sepa-plazo" name="plazo">
						<option value="" <?php selected( $plazo, '' ); ?>><?php asde_e( 'Todo lo pendiente de la ronda' ); ?></option>
						<?php foreach ( $plazos as $i => $pl ) : ?>
							<option value="<?php echo (int) $i; ?>" <?php selected( $plazo, (string) $i ); ?>><?php echo esc_html( sprintf( asde_t( '%s (hasta el %s, %s %%)' ), asde_t( (string) $pl['nombre'] ), $fmt_fecha( $pl['fecha'] ), number_format_i18n( (float) $pl['porcentaje'], 0 ) ) ); ?></option>
						<?php endforeach; ?>
					</select>
					<span class="asde-help"><?php asde_e( 'Con un plazo, se cobra a cada familia lo que le falta hasta ese plazo (contando lo que ya ha pagado).' ); ?></span>
				</p>
				<p>
					<label for="asde-sepa-fecha"><?php asde_e( 'Fecha de cobro' ); ?></label>
					<input type="date" id="asde-sepa-fecha" name="fecha" value="<?php echo esc_attr( $fecha ); ?>" min="<?php echo esc_attr( $fecha_min ); ?>">
					<span class="asde-help"><?php asde_e( 'Día laborable, como pronto el' ); ?> <?php echo esc_html( $fmt_fecha( $fecha_min ) ); ?>.</span>
				</p>
				<p class="asde-grid-span">
					<label for="asde-sepa-concepto-r"><?php asde_e( 'Concepto' ); ?></label>
					<input type="text" id="asde-sepa-concepto-r" name="concepto" maxlength="140" value="<?php echo esc_attr( $concepto ); ?>">
				</p>
			</div>
			<p><button type="submit" class="button"><?php asde_e( 'Ver qué se cobraría' ); ?></button></p>
		</form>

		<?php if ( $prep ) : ?>
			<hr>
			<h4><?php asde_e( 'Vista previa' ); ?></h4>
			<?php foreach ( $errores as $e ) : ?>
				<div class="asde-notice asde-notice--error" role="alert"><?php echo esc_html( $e ); ?></div>
			<?php endforeach; ?>
			<?php if ( ! $prep['lineas'] ) : ?>
				<p><?php asde_e( 'No hay ningún adeudo que incluir: ninguna familia con autorización tiene algo pendiente' ); ?> <?php echo '' !== $plazo ? esc_html( asde_t( 'hasta ese plazo' ) ) : ''; ?>.</p>
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
					<p><button type="submit" class="button"><?php asde_e( 'Crear la remesa y preparar el fichero' ); ?></button></p>
				</form>
			<?php endif; ?>
		<?php endif; ?>
	</section>

	<?php if ( $eventos ) : ?>
		<section class="asde-card">
			<h3><?php asde_e( 'Cobrar un evento' ); ?></h3>
			<p class="asde-help"><?php asde_e( 'Un adeudo por familia con autorización, con todo lo pendiente de sus inscripciones activas en el evento (los hermanos, juntos). Al registrar los cobros, el importe se reparte entre sus inscripciones.' ); ?></p>
			<form method="get" action="<?php echo esc_url( $url ); ?>" class="asde-form">
				<input type="hidden" name="ronda" value="<?php echo esc_attr( $ronda ); ?>">
				<input type="hidden" name="preparar_evento" value="1">
				<div class="asde-grid-2">
					<p class="asde-grid-span">
						<label for="asde-sepa-evento"><?php asde_e( 'Evento' ); ?></label>
						<select id="asde-sepa-evento" name="evento_sepa">
							<?php foreach ( $eventos as $id => $ev ) : ?>
								<option value="<?php echo (int) $id; ?>" <?php selected( $ev_id, (int) $id ); ?>><?php echo esc_html( EventosTable::numero( $ev ) . ' · ' . $ev['nombre'] . ' · ' . EventosTable::cuando( $ev ) ); ?></option>
							<?php endforeach; ?>
						</select>
					</p>
					<p>
						<label for="asde-sepa-fecha-evento"><?php asde_e( 'Fecha de cobro' ); ?></label>
						<input type="date" id="asde-sepa-fecha-evento" name="fecha_evento" value="<?php echo esc_attr( $ev_fecha ); ?>" min="<?php echo esc_attr( $fecha_min ); ?>">
					</p>
					<p>
						<label for="asde-sepa-concepto-evento"><?php asde_e( 'Concepto' ); ?></label>
						<input type="text" id="asde-sepa-concepto-evento" name="concepto_evento" maxlength="140" value="<?php echo esc_attr( $ev_concepto ); ?>" placeholder="<?php echo esc_attr( asde_a( 'Por defecto, el número y el nombre del evento' ) ); ?>">
					</p>
				</div>
				<p><button type="submit" class="button"><?php asde_e( 'Ver qué se cobraría' ); ?></button></p>
			</form>

			<?php if ( $ev_prep ) : ?>
				<hr>
				<h4><?php asde_e( 'Vista previa' ); ?></h4>
				<?php foreach ( $ev_errores as $error ) : ?>
					<div class="asde-notice asde-notice--error" role="alert"><?php echo esc_html( $error ); ?></div>
				<?php endforeach; ?>
				<?php if ( ! $ev_prep['lineas'] ) : ?>
					<p><?php asde_e( 'No hay ningún adeudo que incluir: ninguna familia con autorización tiene algo pendiente en este evento.' ); ?></p>
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
						<p><button type="submit" class="button"><?php asde_e( 'Crear la remesa y preparar el fichero' ); ?></button></p>
					</form>
				<?php endif; ?>
			<?php endif; ?>
		</section>
	<?php endif; ?>
<?php endif; ?>

<section class="asde-card">
	<h3><?php asde_e( 'Remesas' ); ?></h3>
	<?php if ( ! $remesas ) : ?>
		<p class="asde-muted"><?php asde_e( 'Todavía no se ha creado ninguna remesa.' ); ?></p>
	<?php else : ?>
		<table class="asde-table">
			<thead><tr><th scope="col"><?php asde_e( 'Remesa' ); ?></th><th scope="col"><?php asde_e( 'Ronda · plazo' ); ?></th><th scope="col"><?php asde_e( 'Cobro' ); ?></th><th scope="col"><?php asde_e( 'Adeudos' ); ?></th><th scope="col"><?php asde_e( 'Total' ); ?></th><th scope="col"><?php asde_e( 'Estado' ); ?></th></tr></thead>
			<tbody>
				<?php foreach ( $remesas as $r ) : ?>
					<tr>
						<td data-label="<?php echo esc_attr( asde_a( 'Remesa' ) ); ?>"><a href="<?php echo esc_url( add_query_arg( array( 'ronda' => $r['ronda'], 'remesa' => $r['id'] ), $url ) ); ?>"><?php echo esc_html( $r['numero'] ); ?></a></td>
						<td data-label="<?php echo esc_attr( asde_a( 'Ronda' ) ); ?>"><?php echo esc_html( $r['ronda'] . ' · ' . asde_t( (string) $r['plazo'] ) ); ?></td>
						<td data-label="<?php echo esc_attr( asde_a( 'Cobro' ) ); ?>"><?php echo esc_html( $fmt_fecha( $r['fecha_cobro'] ) ); ?></td>
						<td data-label="<?php echo esc_attr( asde_a( 'Adeudos' ) ); ?>"><?php echo (int) $r['adeudos']; ?></td>
						<td data-label="<?php echo esc_attr( asde_a( 'Total' ) ); ?>"><?php echo esc_html( Calculadora::euros( $r['total'] ) ); ?></td>
						<td data-label="<?php echo esc_attr( asde_a( 'Estado' ) ); ?>"><?php echo esc_html( asde_t( Remesas::ESTADOS[ $r['estado'] ] ?? $r['estado'] ) ); ?><?php echo Remesas::ENVIADA === $r['estado'] && $r['cobros_en'] ? ' · cobrada' : ''; ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</section>

<section class="asde-card">
	<h3><?php asde_e( 'Autorizaciones de las familias' ); ?></h3>
	<?php
	$con = 0;
	foreach ( $familias as $id => $f ) {
		$con += isset( $mandatos[ $id ] ) ? 1 : 0;
	}
	?>
	<p><?php echo (int) $con; ?> <?php asde_e( 'de' ); ?> <?php echo (int) count( $familias ); ?> <?php asde_e( 'familias tienen una autorización vigente.' ); ?></p>
	<?php if ( $familias ) : ?>
		<table class="asde-table">
			<thead><tr><th scope="col"><?php asde_e( 'Familia' ); ?></th><th scope="col"><?php asde_e( 'Socios' ); ?></th><th scope="col"><?php asde_e( 'Autorización' ); ?></th><th scope="col"><span class="screen-reader-text"><?php asde_e( 'Acciones' ); ?></span></th></tr></thead>
			<tbody>
				<?php foreach ( $familias as $id => $f ) : ?>
					<?php $m = $mandatos[ $id ] ?? null; ?>
					<tr>
						<td data-label="<?php echo esc_attr( asde_a( 'Familia' ) ); ?>"><?php echo esc_html( $f['numero'] ); ?></td>
						<td data-label="<?php echo esc_attr( asde_a( 'Socios' ) ); ?>"><?php echo esc_html( $nombres( $f ) ); ?></td>
						<td data-label="<?php echo esc_attr( asde_a( 'Autorización' ) ); ?>">
							<?php if ( $m ) : ?>
								<?php echo esc_html( sprintf( asde_t( '%s · %s · firmada el %s' ), $m['referencia'], $m['iban_mask'], $fmt_fecha( $m['fecha_firma'] ) ) ); ?>
								<?php $cuenta = null !== $cuentas ? ( $cuentas[ $id ] ?? null ) : false; ?>
								<?php if ( $cuenta ) : ?>
									<br><span class="asde-muted"><?php echo esc_html( sprintf( asde_t( 'Titular: %s' ), $cuenta['titular'] ) ); ?></span>
								<?php elseif ( null === $cuenta ) : ?>
									<br><span class="asde-help asde-help--warning"><?php asde_e( '⚠️ La cuenta de su ficha del CRM no es la autorizada (o no está): no se le cobrará hasta que firme de nuevo.' ); ?></span>
								<?php endif; ?>
								<?php echo null === $m['usado_en'] ? '<br><span class="asde-muted">' . esc_html( asde_t( 'Aún sin usar: el primer cobro irá como FRST.' ) ) . '</span>' : ''; ?>
							<?php else : ?>
								<span class="asde-muted"><?php asde_e( 'Sin autorización' ); ?></span>
							<?php endif; ?>
						</td>
						<td data-label="">
							<?php if ( $m ) : ?>
								<form method="post" class="asde-actions" data-asde-confirmar="<?php echo esc_attr( asde_js( '¿Anular esta autorización?' ) ); ?>">
									<?php wp_nonce_field( 'asde_sepa_revocar' ); ?>
									<input type="hidden" name="asde_action" value="sepa_revocar">
									<input type="hidden" name="ronda" value="<?php echo esc_attr( $ronda ); ?>">
									<input type="hidden" name="mandato" value="<?php echo (int) $m['id']; ?>">
									<input type="text" name="motivo" maxlength="80" placeholder="<?php echo esc_attr( asde_a( 'Motivo' ) ); ?>" aria-label="<?php echo esc_attr( asde_a( 'Motivo de la anulación' ) ); ?>">
									<button type="submit" class="asde-btn-link"><?php asde_e( 'Anular' ); ?></button>
								</form>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
	<p class="asde-help"><?php asde_e( 'La cuenta (IBAN, titular y entidad) se guarda en la ficha del CRM de Scouts de España de cada miembro de la familia; la web guarda la autorización (quién la firmó y cuándo, y la dirección), el IBAN enmascarado y una huella para comprobar, antes de cobrar, que la cuenta del CRM sigue siendo la autorizada. La autorización se conserva mientras esté vigente y 14 meses después de anularla, como pide la norma SEPA.' ); ?></p>
	<?php if ( $locales ) : ?>
		<form method="post" class="asde-form" data-asde-confirmar="<?php echo esc_attr( asde_js( '¿Pasar las cuentas de las autorizaciones a las fichas del CRM? Se escriben en la ficha de cada miembro de la familia y se borran de la web.' ) ); ?>">
			<?php wp_nonce_field( 'asde_sepa_cuentas_crm' ); ?>
			<input type="hidden" name="asde_action" value="sepa_cuentas_crm">
			<input type="hidden" name="ronda" value="<?php echo esc_attr( $ronda ); ?>">
			<p class="asde-help asde-help--warning"><?php asde_e( 'Hay autorizaciones firmadas antes de la versión 0.33 que guardan la cuenta en la web. Pásalas a las fichas del CRM: desde entonces la web ya no guarda ninguna cuenta.' ); ?></p>
			<p><button type="submit" class="button"><?php asde_e( 'Pasar las cuentas al CRM' ); ?></button></p>
		</form>
	<?php endif; ?>
</section>
