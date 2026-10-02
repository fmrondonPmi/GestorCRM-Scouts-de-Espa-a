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
 * @var string      $url
 * @var string      $url_ronda
 */
use AsdeBalears\AreaPrivada\Sepa\Mandatos;
use AsdeBalears\AreaPrivada\Sepa\Remesas;
use AsdeBalears\AreaPrivada\Tesoreria\Calculadora;

defined( 'ABSPATH' ) || exit;

$fmt_fecha = static fn( string $f ) => '' !== $f ? wp_date( 'd/m/Y', (int) strtotime( $f ) ) : '';
$nombres   = static function ( array $f ) use ( $personas ): string {
	$n = array_map( static fn( $num ) => isset( $personas[ $num ] ) ? $personas[ $num ]->nombre : $num, $f['miembros'] );
	return implode( ', ', $n );
};
?>
<section class="asde-card">
	<h2>Domiciliaciones SEPA</h2>
	<p>Las familias autorizan en «Mis pagos» que se les cobre la cuota por <strong>adeudo directo SEPA</strong>. Aquí se generan los ficheros para el banco (formato XML <code>pain.008</code>, «Cuaderno 19.14»), se avisa a las familias de cada cargo y se registran los cobros.</p>
	<ol class="asde-lista-pasos">
		<li><strong>Datos del acreedor</strong> (el grupo): nombre, identificador del acreedor (ICS) e IBAN. Los da el banco al contratar las domiciliaciones.</li>
		<li>Las familias <strong>firman su autorización</strong> en «Mis pagos».</li>
		<li><strong>Nueva remesa</strong>: eliges el plazo y la fecha de cobro, revisas lo que se cobra a cada familia y descargas el fichero.</li>
		<li>Súbelo al banco (Colonya Directa: «Remesas de adeudos») y márcala como <strong>enviada</strong>. <strong>Avisa a las familias</strong> con los días de antelación que has puesto en los datos del acreedor.</li>
		<li>Cuando el banco cobre, <strong>registra los cobros</strong>: pasan a ser pagos de la familia. Marca los devueltos.</li>
	</ol>
</section>

<section class="asde-card">
	<h3>Datos del acreedor</h3>
	<?php if ( $errores_cfg ) : ?>
		<div class="asde-notice asde-notice--warning" role="status">
			<strong>Falta completar:</strong>
			<ul><?php foreach ( $errores_cfg as $e ) : ?><li><?php echo esc_html( $e ); ?></li><?php endforeach; ?></ul>
		</div>
	<?php elseif ( $ics_aviso ) : ?>
		<div class="asde-notice asde-notice--warning" role="status">Las cifras de control del identificador del acreedor no cuadran con su NIF: revisa que lo hayas copiado tal como lo da el banco. (Solo es un aviso.)</div>
	<?php endif; ?>
	<form method="post" class="asde-form">
		<?php wp_nonce_field( 'asde_sepa_config' ); ?>
		<input type="hidden" name="asde_action" value="sepa_config">
		<input type="hidden" name="ronda" value="<?php echo esc_attr( $ronda ); ?>">
		<div class="asde-grid-2">
			<p><label for="asde-sepa-nombre">Nombre del acreedor</label><input type="text" id="asde-sepa-nombre" name="nombre" maxlength="70" value="<?php echo esc_attr( $cfg['nombre'] ); ?>" placeholder="p. ej. Grupo Scout Myotragus 684"><span class="asde-help">Como aparecerá en el banco de las familias (sin tildes).</span></p>
			<p><label for="asde-sepa-ics">Identificador del acreedor (ICS)</label><input type="text" id="asde-sepa-ics" name="ics" maxlength="35" value="<?php echo esc_attr( $cfg['ics'] ); ?>" placeholder="ES00ZZZG00000000"><span class="asde-help">Empieza por ES y son 16 caracteres.</span></p>
			<p><label for="asde-sepa-iban">IBAN del acreedor</label><input type="text" id="asde-sepa-iban" name="iban" maxlength="40" value="" placeholder="<?php echo '' !== $cfg['iban'] ? esc_attr( \AsdeBalears\AreaPrivada\Sepa\Iban::enmascarar( $cfg['iban'] ) . ' (guardado; déjalo vacío para conservarlo)' ) : 'ES00 0000 0000 0000 0000 0000'; ?>" autocomplete="off"><span class="asde-help">La cuenta donde el banco ingresa lo cobrado. Se guarda cifrado.</span></p>
			<p><label for="asde-sepa-bic">BIC (opcional)</label><input type="text" id="asde-sepa-bic" name="bic" maxlength="11" value="<?php echo esc_attr( $cfg['bic'] ); ?>"></p>
			<p><label for="asde-sepa-calle">Dirección (calle y número)</label><input type="text" id="asde-sepa-calle" name="calle" maxlength="70" value="<?php echo esc_attr( $cfg['calle'] ); ?>"></p>
			<p><label for="asde-sepa-cp">Código postal</label><input type="text" id="asde-sepa-cp" name="cp" maxlength="10" value="<?php echo esc_attr( $cfg['cp'] ); ?>"></p>
			<p><label for="asde-sepa-poblacion">Población</label><input type="text" id="asde-sepa-poblacion" name="poblacion" maxlength="50" value="<?php echo esc_attr( $cfg['poblacion'] ); ?>"></p>
			<p><label for="asde-sepa-provincia">Provincia</label><input type="text" id="asde-sepa-provincia" name="provincia" maxlength="40" value="<?php echo esc_attr( $cfg['provincia'] ); ?>"></p>
			<p><label for="asde-sepa-aviso">Días de aviso antes de cada cargo</label><input type="number" id="asde-sepa-aviso" name="dias_aviso" min="1" max="30" value="<?php echo (int) $cfg['dias_aviso']; ?>"><span class="asde-help">La norma pide 14 días salvo que se pacte otro plazo: aquí se pacta y sale en la autorización.</span></p>
			<p><label for="asde-sepa-concepto">Concepto de los cargos</label><input type="text" id="asde-sepa-concepto" name="concepto" maxlength="90" value="<?php echo esc_attr( $cfg['concepto'] ); ?>"><span class="asde-help">Puedes usar {grupo} y {ronda}. Al final se añade el nº de familia.</span></p>
		</div>
		<p>
			<label class="asde-choice"><input type="checkbox" name="activo" value="1" <?php checked( $cfg['activo'] ); ?>><span>Ofrecer la domiciliación a las familias (aparece en «Mis pagos»)</span></label>
		</p>
		<p><button type="submit" class="button">Guardar los datos del acreedor</button></p>
	</form>
</section>

<?php if ( $lista ) : ?>
	<section class="asde-card">
		<h3>Nueva remesa · ronda <?php echo esc_html( $ronda ); ?></h3>
		<form method="get" action="<?php echo esc_url( $url ); ?>" class="asde-form">
			<input type="hidden" name="ronda" value="<?php echo esc_attr( $ronda ); ?>">
			<input type="hidden" name="preparar" value="1">
			<div class="asde-grid-2">
				<p>
					<label for="asde-sepa-plazo">Qué se cobra</label>
					<select id="asde-sepa-plazo" name="plazo">
						<option value="" <?php selected( $plazo, '' ); ?>>Todo lo pendiente de la ronda</option>
						<?php foreach ( $plazos as $i => $pl ) : ?>
							<option value="<?php echo (int) $i; ?>" <?php selected( $plazo, (string) $i ); ?>><?php echo esc_html( sprintf( '%s (hasta el %s, %s %%)', $pl['nombre'], $fmt_fecha( $pl['fecha'] ), number_format_i18n( (float) $pl['porcentaje'], 0 ) ) ); ?></option>
						<?php endforeach; ?>
					</select>
					<span class="asde-help">Con un plazo, se cobra a cada familia lo que le falta hasta ese plazo (contando lo que ya ha pagado).</span>
				</p>
				<p>
					<label for="asde-sepa-fecha">Fecha de cobro</label>
					<input type="date" id="asde-sepa-fecha" name="fecha" value="<?php echo esc_attr( $fecha ); ?>" min="<?php echo esc_attr( $fecha_min ); ?>">
					<span class="asde-help">Día laborable, como pronto el <?php echo esc_html( $fmt_fecha( $fecha_min ) ); ?>.</span>
				</p>
				<p class="asde-grid-span">
					<label for="asde-sepa-concepto-r">Concepto</label>
					<input type="text" id="asde-sepa-concepto-r" name="concepto" maxlength="140" value="<?php echo esc_attr( $concepto ); ?>">
				</p>
			</div>
			<p><button type="submit" class="button">Ver qué se cobraría</button></p>
		</form>

		<?php if ( $prep ) : ?>
			<hr>
			<h4>Vista previa</h4>
			<?php foreach ( $errores as $e ) : ?>
				<div class="asde-notice asde-notice--error" role="alert"><?php echo esc_html( $e ); ?></div>
			<?php endforeach; ?>
			<?php if ( ! $prep['lineas'] ) : ?>
				<p>No hay ningún adeudo que incluir: ninguna familia con autorización tiene algo pendiente <?php echo '' !== $plazo ? 'hasta ese plazo' : ''; ?>.</p>
			<?php else : ?>
				<table class="asde-table">
					<thead><tr><th scope="col">Familia</th><th scope="col">Socios</th><th scope="col">Titular · cuenta</th><th scope="col">Secuencia</th><th scope="col">Importe</th></tr></thead>
					<tbody>
						<?php foreach ( $prep['lineas'] as $l ) : ?>
							<tr>
								<td data-label="Familia"><?php echo esc_html( $l['familia_n'] ); ?></td>
								<td data-label="Socios"><?php echo esc_html( $nombres( $familias[ $l['familia'] ] ?? array( 'miembros' => array() ) ) ); ?></td>
								<td data-label="Titular"><?php echo esc_html( $l['titular'] . ' · ' . $l['iban_mask'] ); ?></td>
								<td data-label="Secuencia"><?php echo esc_html( 'FRST' === $l['secuencia'] ? 'Primer cobro (FRST)' : 'Recurrente (RCUR)' ); ?></td>
								<td data-label="Importe"><?php echo esc_html( Calculadora::euros( $l['centimos'] / 100 ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
					<tfoot><tr><th scope="row" colspan="4">Total · <?php echo esc_html( Remesas::adeudos( count( $prep['lineas'] ) ) ); ?></th><td><strong><?php echo esc_html( Calculadora::euros( $prep['total'] / 100 ) ); ?></strong></td></tr></tfoot>
				</table>
			<?php endif; ?>
			<?php if ( $prep['omitidas'] ) : ?>
				<h4>No se incluyen</h4>
				<ul>
					<?php foreach ( $prep['omitidas'] as $o ) : ?>
						<li><?php echo esc_html( sprintf( '%s (%s): %s', $o['familia_n'], Calculadora::euros( $o['centimos'] / 100 ), $o['motivo'] ) ); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
			<?php if ( $prep['lineas'] && ! $errores ) : ?>
				<form method="post" class="asde-form">
					<?php wp_nonce_field( 'asde_sepa_generar' ); ?>
					<input type="hidden" name="asde_action" value="sepa_generar">
					<input type="hidden" name="ronda" value="<?php echo esc_attr( $ronda ); ?>">
					<input type="hidden" name="plazo" value="<?php echo esc_attr( $plazo ); ?>">
					<input type="hidden" name="fecha" value="<?php echo esc_attr( $fecha ); ?>">
					<input type="hidden" name="concepto" value="<?php echo esc_attr( $concepto ); ?>">
					<p><button type="submit" class="button">Crear la remesa y preparar el fichero</button></p>
				</form>
			<?php endif; ?>
		<?php endif; ?>
	</section>
<?php endif; ?>

<section class="asde-card">
	<h3>Remesas</h3>
	<?php if ( ! $remesas ) : ?>
		<p class="asde-muted">Todavía no se ha creado ninguna remesa.</p>
	<?php else : ?>
		<table class="asde-table">
			<thead><tr><th scope="col">Remesa</th><th scope="col">Ronda · plazo</th><th scope="col">Cobro</th><th scope="col">Adeudos</th><th scope="col">Total</th><th scope="col">Estado</th></tr></thead>
			<tbody>
				<?php foreach ( $remesas as $r ) : ?>
					<tr>
						<td data-label="Remesa"><a href="<?php echo esc_url( add_query_arg( array( 'ronda' => $r['ronda'], 'remesa' => $r['id'] ), $url ) ); ?>"><?php echo esc_html( $r['numero'] ); ?></a></td>
						<td data-label="Ronda"><?php echo esc_html( $r['ronda'] . ' · ' . $r['plazo'] ); ?></td>
						<td data-label="Cobro"><?php echo esc_html( $fmt_fecha( $r['fecha_cobro'] ) ); ?></td>
						<td data-label="Adeudos"><?php echo (int) $r['adeudos']; ?></td>
						<td data-label="Total"><?php echo esc_html( Calculadora::euros( $r['total'] ) ); ?></td>
						<td data-label="Estado"><?php echo esc_html( Remesas::ESTADOS[ $r['estado'] ] ?? $r['estado'] ); ?><?php echo Remesas::ENVIADA === $r['estado'] && $r['cobros_en'] ? ' · cobrada' : ''; ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</section>

<section class="asde-card">
	<h3>Autorizaciones de las familias</h3>
	<?php
	$con = 0;
	foreach ( $familias as $id => $f ) {
		$con += isset( $mandatos[ $id ] ) ? 1 : 0;
	}
	?>
	<p><?php echo (int) $con; ?> de <?php echo (int) count( $familias ); ?> familias tienen una autorización vigente.</p>
	<?php if ( $familias ) : ?>
		<table class="asde-table">
			<thead><tr><th scope="col">Familia</th><th scope="col">Socios</th><th scope="col">Autorización</th><th scope="col"><span class="screen-reader-text">Acciones</span></th></tr></thead>
			<tbody>
				<?php foreach ( $familias as $id => $f ) : ?>
					<?php $m = $mandatos[ $id ] ?? null; ?>
					<tr>
						<td data-label="Familia"><?php echo esc_html( $f['numero'] ); ?></td>
						<td data-label="Socios"><?php echo esc_html( $nombres( $f ) ); ?></td>
						<td data-label="Autorización">
							<?php if ( $m ) : ?>
								<?php echo esc_html( sprintf( '%s · %s · firmada el %s', $m['referencia'], $m['iban_mask'], $fmt_fecha( $m['fecha_firma'] ) ) ); ?>
								<?php echo '' !== $m['datos']['titular'] ? '<br><span class="asde-muted">Titular: ' . esc_html( $m['datos']['titular'] ) . '</span>' : ''; // phpcs:ignore WordPress.Security.EscapeOutput ?>
								<?php echo null === $m['usado_en'] ? '<br><span class="asde-muted">Aún sin usar: el primer cobro irá como FRST.</span>' : ''; ?>
							<?php else : ?>
								<span class="asde-muted">Sin autorización</span>
							<?php endif; ?>
						</td>
						<td data-label="">
							<?php if ( $m ) : ?>
								<form method="post" class="asde-actions" onsubmit="return confirm('¿Anular esta autorización?');">
									<?php wp_nonce_field( 'asde_sepa_revocar' ); ?>
									<input type="hidden" name="asde_action" value="sepa_revocar">
									<input type="hidden" name="ronda" value="<?php echo esc_attr( $ronda ); ?>">
									<input type="hidden" name="mandato" value="<?php echo (int) $m['id']; ?>">
									<input type="text" name="motivo" maxlength="80" placeholder="Motivo" aria-label="Motivo de la anulación">
									<button type="submit" class="asde-btn-link">Anular</button>
								</form>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
	<p class="asde-help">La autorización guarda el titular y el IBAN cifrados y solo enseña el IBAN enmascarado. Se conserva mientras esté vigente y 14 meses después de anularla, como pide la norma SEPA.</p>
</section>
