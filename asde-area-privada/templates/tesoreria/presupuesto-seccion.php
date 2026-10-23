<?php
/**
 * Presupuesto de una sección o del grupo (v0.55, doc 57 §5): gastos, ingresos, saldo y estado.
 *
 * @var string     $ronda
 * @var string     $url
 * @var string     $vista       Sección (castores…rovers) o «grupo»
 * @var array|null $reparto
 * @var string     $error
 * @var array[]    $partidas
 * @var array      $estado      EstadosTable::de()
 * @var array      $resultado   Servicio::resultado()
 * @var bool       $editable
 * @var string[]   $categorias
 * @var bool       $hay_previa  Hay presupuesto en la ronda anterior
 * @var bool       $vacio       Sin partidas propias
 * @var float|null $pct         % que va al grupo (null en el del grupo)
 * @var bool       $es_config
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Presupuestos\EstadosTable;
use AsdeBalears\AreaPrivada\Presupuestos\PartidasTable;
use AsdeBalears\AreaPrivada\Presupuestos\Servicio;
use AsdeBalears\AreaPrivada\Tesoreria\Calculadora;

defined( 'ABSPATH' ) || exit;

$e       = static fn( float $v ) => Calculadora::euros( $v );
$nombre  = Servicio::nombre( $vista );
$gastos  = array_values( array_filter( $partidas, static fn( $p ) => PartidasTable::GASTO === $p['tipo'] ) );
$ingres  = array_values( array_filter( $partidas, static fn( $p ) => PartidasTable::INGRESO === $p['tipo'] ) );
$bloq    = EstadosTable::BORRADOR !== $estado['estado'];
$campos  = static function ( string $accion, array $extra = array() ) use ( $ronda, $vista ): string {
	$html  = wp_nonce_field( 'asde_presupuestos', '_wpnonce', true, false );
	$html .= '<input type="hidden" name="asde_action" value="' . esc_attr( $accion ) . '">';
	$html .= '<input type="hidden" name="ronda" value="' . esc_attr( $ronda ) . '">';
	$html .= '<input type="hidden" name="seccion" value="' . esc_attr( $vista ) . '">';
	foreach ( $extra as $k => $v ) {
		$html .= '<input type="hidden" name="' . esc_attr( $k ) . '" value="' . esc_attr( (string) $v ) . '">';
	}
	return $html;
};
?>
<section class="asde-card">
	<h2><?php echo esc_html( sprintf( asde_t( 'Presupuesto: %s' ), $nombre ) ); ?> · <?php echo esc_html( $ronda ); ?></h2>
	<p>
		<span class="asde-badge asde-badge--presupuesto-<?php echo esc_attr( $estado['estado'] ); ?>"><?php echo esc_html( asde_t( EstadosTable::ETIQUETAS[ $estado['estado'] ] ) ); ?></span>
		<?php if ( null !== $pct ) : ?>
			<?php echo esc_html( sprintf( asde_t( 'De cada cuota, el %1$s %% va al grupo y el %2$s %% a esta sección.' ), rtrim( rtrim( number_format( $pct, 2, '.', '' ), '0' ), '.' ), rtrim( rtrim( number_format( 100 - $pct, 2, '.', '' ), '0' ), '.' ) ) ); ?>
		<?php endif; ?>
	</p>
	<?php if ( $error ) : ?>
		<div class="asde-notice asde-notice--error" role="alert"><?php echo esc_html( $error ); ?></div>
	<?php endif; ?>
	<?php if ( $bloq ) : ?>
		<div class="asde-notice asde-notice--info" role="status"><?php echo esc_html( EstadosTable::APROBADO === $estado['estado'] ? asde_t( 'Presupuesto aprobado: está bloqueado. Tesorería puede reabrirlo.' ) : asde_t( 'Presupuesto enviado a Tesorería: está bloqueado hasta que se apruebe o se reabra.' ) ); ?></div>
	<?php endif; ?>
</section>

<section class="asde-card" data-asde-plegable="presupuesto-saldo" data-asde-inicio="abierto">
	<h3><?php asde_e( 'Cuentas' ); ?></h3>
	<table class="asde-table asde-table--resumen">
		<tbody>
			<tr><th scope="row"><?php asde_e( 'Disponible de las cuotas' ); ?></th><td><?php echo esc_html( $e( $resultado['disponible'] ) ); ?></td></tr>
			<tr><th scope="row"><?php asde_e( 'Ingresos previstos' ); ?></th><td>+ <?php echo esc_html( $e( $resultado['ingresos'] ) ); ?></td></tr>
			<tr><th scope="row"><?php asde_e( 'Gastos previstos' ); ?></th><td>− <?php echo esc_html( $e( $resultado['gastos'] ) ); ?></td></tr>
			<tr class="asde-total"><th scope="row"><?php asde_e( 'Saldo' ); ?></th><td><strong<?php echo $resultado['saldo'] < 0 ? ' class="asde-negativo"' : ''; ?>><?php echo esc_html( $e( $resultado['saldo'] ) ); ?></strong></td></tr>
			<?php if ( $resultado['recaudacion'] > 0 ) : ?>
				<tr><th scope="row"><?php asde_e( 'Saldo sin contar la recaudación del proyecto' ); ?></th><td><?php echo esc_html( $e( $resultado['sin_recaudacion'] ) ); ?></td></tr>
			<?php endif; ?>
		</tbody>
	</table>
	<?php if ( $resultado['saldo'] < 0 ) : ?>
		<div class="asde-notice asde-notice--warning" role="status"><?php asde_e( 'El presupuesto no cuadra: los gastos superan lo disponible.' ); ?></div>
	<?php endif; ?>
	<p class="asde-help"><?php asde_e( 'La recaudación es un objetivo, no dinero cobrado.' ); ?></p>
</section>

<section class="asde-card" data-asde-plegable="presupuesto-gastos" data-asde-inicio="abierto">
	<h3><?php asde_e( 'Gastos' ); ?></h3>
	<?php if ( $gastos ) : ?>
		<table class="asde-table asde-table--resumen">
			<thead><tr><th scope="col"><?php asde_e( 'Categoría' ); ?></th><th scope="col"><?php asde_e( 'Concepto' ); ?></th><th scope="col"><?php asde_e( '¿Cuántos?' ); ?></th><th scope="col"><?php asde_e( 'Importe de cada uno' ); ?></th><th scope="col"><?php asde_e( 'Total' ); ?></th><th scope="col"><?php asde_e( 'Gastado' ); ?></th><th scope="col"><?php asde_e( 'Queda' ); ?></th><th scope="col"></th></tr></thead>
			<tbody>
				<?php foreach ( $gastos as $p ) : ?>
					<?php $gast = (float) ( $por_partida[ $p['id'] ] ?? 0 ); ?>
					<tr>
						<td data-label="<?php echo esc_attr( asde_a( 'Categoría' ) ); ?>"><?php echo esc_html( asde_t( (string) $p['categoria'] ) ); ?></td>
						<td data-label="<?php echo esc_attr( asde_a( 'Concepto' ) ); ?>"><?php echo esc_html( asde_t( (string) $p['concepto'] ) ); ?><?php echo '' !== $p['nota'] ? ' <span class="asde-muted">· ' . esc_html( $p['nota'] ) . '</span>' : ''; // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
						<td data-label="<?php echo esc_attr( asde_a( '¿Cuántos?' ) ); ?>"><?php echo (int) $p['veces']; ?></td>
						<td data-label="<?php echo esc_attr( asde_a( 'Importe de cada uno' ) ); ?>"><?php echo esc_html( $e( $p['importe'] ) ); ?></td>
						<td data-label="<?php echo esc_attr( asde_a( 'Total' ) ); ?>"><?php echo esc_html( $e( $p['total'] ) ); ?></td>
						<td data-label="<?php echo esc_attr( asde_a( 'Gastado' ) ); ?>"><?php echo esc_html( $e( $gast ) ); ?></td>
						<td data-label="<?php echo esc_attr( asde_a( 'Queda' ) ); ?>"><span<?php echo $p['total'] - $gast < 0 ? ' class="asde-negativo"' : ''; ?>><?php echo esc_html( $e( $p['total'] - $gast ) ); ?></span></td>
						<td>
							<?php if ( $editable ) : ?>
								<form method="post" action="<?php echo esc_url( $url ); ?>"><?php echo $campos( 'partida_quitar', array( 'id' => $p['id'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?><button type="submit" class="button button-small"><?php asde_e( 'Quitar' ); ?></button></form>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php else : ?>
		<p class="asde-muted"><?php asde_e( 'Aún no hay gastos.' ); ?></p>
	<?php endif; ?>
</section>

<section class="asde-card" data-asde-plegable="presupuesto-ingresos" data-asde-inicio="abierto">
	<h3><?php asde_e( 'Ingresos' ); ?></h3>
	<?php if ( $ingres ) : ?>
		<table class="asde-table asde-table--resumen">
			<thead><tr><th scope="col"><?php asde_e( 'Concepto' ); ?></th><th scope="col"><?php asde_e( '¿Cuántos?' ); ?></th><th scope="col"><?php asde_e( 'Importe de cada uno' ); ?></th><th scope="col"><?php asde_e( 'Total' ); ?></th><th scope="col"></th></tr></thead>
			<tbody>
				<?php foreach ( $ingres as $p ) : ?>
					<?php $proy = PartidasTable::PROYECTO === $p['categoria']; ?>
					<tr>
						<td data-label="<?php echo esc_attr( asde_a( 'Concepto' ) ); ?>"><?php echo $proy ? esc_html( sprintf( asde_t( 'Proyecto «%s»: objetivo de recaudación' ), asde_t( (string) $p['concepto'] ) ) ) : esc_html( asde_t( (string) $p['concepto'] ) ); ?><?php echo '' !== $p['nota'] ? ' <span class="asde-muted">· ' . esc_html( $p['nota'] ) . '</span>' : ''; // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
						<td data-label="<?php echo esc_attr( asde_a( '¿Cuántos?' ) ); ?>"><?php echo (int) $p['veces']; ?></td>
						<td data-label="<?php echo esc_attr( asde_a( 'Importe de cada uno' ) ); ?>">
							<?php if ( $proy && $editable ) : ?>
								<form method="post" action="<?php echo esc_url( $url ); ?>" class="asde-linea"><?php echo $campos( 'proyecto' ); // phpcs:ignore WordPress.Security.EscapeOutput ?><input type="number" name="importe" step="0.01" min="0" value="<?php echo esc_attr( number_format( $p['importe'], 2, '.', '' ) ); ?>" aria-label="<?php echo esc_attr( asde_a( 'Objetivo de recaudación (€)' ) ); ?>"> <button type="submit" class="button button-small"><?php asde_e( 'Guardar' ); ?></button></form>
							<?php else : ?>
								<?php echo esc_html( $e( $p['importe'] ) ); ?>
							<?php endif; ?>
						</td>
						<td data-label="<?php echo esc_attr( asde_a( 'Total' ) ); ?>"><?php echo esc_html( $e( $p['total'] ) ); ?></td>
						<td>
							<?php if ( $editable && ! $proy ) : ?>
								<form method="post" action="<?php echo esc_url( $url ); ?>"><?php echo $campos( 'partida_quitar', array( 'id' => $p['id'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?><button type="submit" class="button button-small"><?php asde_e( 'Quitar' ); ?></button></form>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php else : ?>
		<p class="asde-muted"><?php asde_e( 'Aún no hay ingresos.' ); ?></p>
	<?php endif; ?>
</section>

<section class="asde-card" data-asde-plegable="presupuesto-real" data-asde-inicio="cerrado">
	<h3><?php asde_e( 'Gasto real' ); ?></h3>
	<table class="asde-table asde-table--resumen">
		<tbody>
			<tr><th scope="row"><?php asde_e( 'Presupuestado en gastos' ); ?></th><td><?php echo esc_html( $e( $ejecucion['presupuestado'] ) ); ?></td></tr>
			<tr><th scope="row"><?php asde_e( 'Gastado de verdad' ); ?></th><td><?php echo esc_html( $e( $ejecucion['gastado'] ) ); ?></td></tr>
			<tr class="asde-total"><th scope="row"><?php asde_e( 'Queda' ); ?></th><td><strong<?php echo $ejecucion['queda'] < 0 ? ' class="asde-negativo"' : ''; ?>><?php echo esc_html( $e( $ejecucion['queda'] ) ); ?></strong></td></tr>
		</tbody>
	</table>
	<?php if ( $gastos_reales ) : ?>
		<table class="asde-table asde-table--resumen">
			<thead><tr><th scope="col"><?php asde_e( 'Fecha' ); ?></th><th scope="col"><?php asde_e( 'Concepto' ); ?></th><th scope="col"><?php asde_e( 'Partida' ); ?></th><th scope="col"><?php asde_e( 'Importe' ); ?></th><th scope="col"></th></tr></thead>
			<tbody>
				<?php
				$nombres = array();
				foreach ( $gastos as $p ) {
					$nombres[ $p['id'] ] = asde_t( (string) $p['concepto'] );
				}
				?>
				<?php foreach ( $gastos_reales as $g ) : ?>
					<tr>
						<td data-label="<?php echo esc_attr( asde_a( 'Fecha' ) ); ?>"><?php echo esc_html( wp_date( get_option( 'date_format' ), strtotime( $g['fecha'] ) ) ); ?></td>
						<td data-label="<?php echo esc_attr( asde_a( 'Concepto' ) ); ?>"><?php echo esc_html( asde_t( (string) $g['concepto'] ) ); ?></td>
						<td data-label="<?php echo esc_attr( asde_a( 'Partida' ) ); ?>"><?php echo esc_html( $nombres[ $g['partida_id'] ] ?? '–' ); ?></td>
						<td data-label="<?php echo esc_attr( asde_a( 'Importe' ) ); ?>"><?php echo esc_html( $e( $g['importe'] ) ); ?></td>
						<td><form method="post" action="<?php echo esc_url( $url ); ?>"><?php echo $campos( 'gasto_quitar', array( 'id' => $g['id'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?><button type="submit" class="button button-small"><?php asde_e( 'Quitar' ); ?></button></form></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php else : ?>
		<p class="asde-muted"><?php asde_e( 'Aún no se ha anotado ningún gasto real.' ); ?></p>
	<?php endif; ?>
	<?php if ( $gastos ) : ?>
		<form method="post" action="<?php echo esc_url( $url ); ?>" class="asde-form">
			<?php echo $campos( 'gasto_nuevo' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<div class="asde-grid-3">
				<p>
					<label for="asde-pg-partida"><?php asde_e( 'Partida' ); ?></label>
					<select id="asde-pg-partida" name="partida_id">
						<?php foreach ( $gastos as $p ) : ?>
							<option value="<?php echo (int) $p['id']; ?>"><?php echo esc_html( asde_t( (string) $p['concepto'] ) ); ?></option>
						<?php endforeach; ?>
					</select>
				</p>
				<p><label for="asde-pg-fecha"><?php asde_e( 'Fecha' ); ?></label><input type="date" id="asde-pg-fecha" name="fecha" value="<?php echo esc_attr( $hoy ); ?>"></p>
				<p><label for="asde-pg-importe"><?php asde_e( 'Importe (€)' ); ?></label><input type="number" id="asde-pg-importe" name="importe" min="0.01" step="0.01"></p>
				<p><label for="asde-pg-concepto"><?php asde_e( 'Concepto (opcional)' ); ?></label><input type="text" id="asde-pg-concepto" name="concepto" maxlength="160"></p>
			</div>
			<p><button type="submit" class="button"><?php asde_e( 'Anotar el gasto' ); ?></button></p>
		</form>
	<?php endif; ?>
</section>

<?php if ( $editable ) : ?>
	<section class="asde-card" data-asde-plegable="presupuesto-nueva" data-asde-inicio="abierto">
		<h3><?php asde_e( 'Añadir una partida' ); ?></h3>
		<form method="post" class="asde-form" action="<?php echo esc_url( $url ); ?>">
			<?php echo $campos( 'partida_nueva' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<div class="asde-grid-3">
				<p>
					<label for="asde-pp-tipo"><?php asde_e( 'Tipo' ); ?></label>
					<select id="asde-pp-tipo" name="tipo">
						<option value="<?php echo esc_attr( PartidasTable::GASTO ); ?>"><?php asde_e( 'Gasto' ); ?></option>
						<option value="<?php echo esc_attr( PartidasTable::INGRESO ); ?>"><?php asde_e( 'Ingreso' ); ?></option>
					</select>
				</p>
				<p>
					<label for="asde-pp-cat"><?php asde_e( 'Categoría (de un gasto)' ); ?></label>
					<select id="asde-pp-cat" name="categoria">
						<?php foreach ( $categorias as $c ) : ?>
							<option value="<?php echo esc_attr( $c ); ?>"><?php echo esc_html( asde_t( (string) $c ) ); ?></option>
						<?php endforeach; ?>
					</select>
				</p>
				<p><label for="asde-pp-concepto"><?php asde_e( 'Concepto' ); ?></label><input type="text" id="asde-pp-concepto" name="concepto" maxlength="160"></p>
				<p><label for="asde-pp-veces"><?php asde_e( '¿Cuántos?' ); ?></label><input type="number" id="asde-pp-veces" name="veces" min="1" max="<?php echo (int) Servicio::MAX_VECES; ?>" step="1" value="1"></p>
				<p><label for="asde-pp-importe"><?php asde_e( 'Importe de cada uno (€)' ); ?></label><input type="number" id="asde-pp-importe" name="importe" min="0" step="0.01" value="0"></p>
				<p><label for="asde-pp-nota"><?php asde_e( 'Nota (opcional)' ); ?></label><input type="text" id="asde-pp-nota" name="nota" maxlength="255"></p>
			</div>
			<p class="asde-help"><?php asde_e( 'Ejemplo: Excursión, ¿cuántos? 6, importe de cada uno 35 € → 210 €. Los ingresos no llevan categoría: escribe su concepto (subvención, venta de productos…).' ); ?></p>
			<p><button type="submit" class="button"><?php asde_e( 'Añadir partida' ); ?></button></p>
		</form>
		<?php if ( $hay_previa && $vacio ) : ?>
			<form method="post" action="<?php echo esc_url( $url ); ?>"><?php echo $campos( 'copiar' ); // phpcs:ignore WordPress.Security.EscapeOutput ?><p><button type="submit" class="button"><?php asde_e( 'Copiar las partidas de la ronda anterior' ); ?></button></p></form>
		<?php endif; ?>
	</section>
<?php endif; ?>

<section class="asde-card" data-asde-plegable="presupuesto-estado" data-asde-inicio="abierto">
	<h3><?php asde_e( 'Estado del presupuesto' ); ?></h3>
	<?php if ( EstadosTable::BORRADOR === $estado['estado'] ) : ?>
		<?php if ( $editable ) : ?>
			<form method="post" action="<?php echo esc_url( $url ); ?>" class="asde-linea"><?php echo $campos( 'enviar' ); // phpcs:ignore WordPress.Security.EscapeOutput ?><button type="submit" class="button"><?php asde_e( 'Enviar a Tesorería' ); ?></button></form>
		<?php endif; ?>
	<?php endif; ?>
	<?php if ( $es_config && EstadosTable::APROBADO !== $estado['estado'] ) : ?>
		<form method="post" action="<?php echo esc_url( $url ); ?>" class="asde-linea"><?php echo $campos( 'aprobar' ); // phpcs:ignore WordPress.Security.EscapeOutput ?><button type="submit" class="button"><?php asde_e( 'Aprobar' ); ?></button></form>
	<?php endif; ?>
	<?php if ( $es_config && $bloq ) : ?>
		<form method="post" action="<?php echo esc_url( $url ); ?>" class="asde-linea"><?php echo $campos( 'reabrir' ); // phpcs:ignore WordPress.Security.EscapeOutput ?><label for="asde-pp-motivo"><?php asde_e( 'Motivo' ); ?></label> <input type="text" id="asde-pp-motivo" name="motivo" maxlength="200" required> <button type="submit" class="button"><?php asde_e( 'Reabrir' ); ?></button></form>
	<?php endif; ?>
</section>
