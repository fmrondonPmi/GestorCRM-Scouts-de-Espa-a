<?php
/**
 * Tesorería → Libro mayor (v0.59, doc 61): las cuentas (banco y cajas) con sus apuntes y el resumen por partida.
 *
 * @var array                 $periodo       Periodo::de()
 * @var array<string, string> $periodos
 * @var array                 $libro         LibroMayor::calcular()
 * @var bool                  $banco         El módulo del banco está activo
 * @var array|null            $banco_inicial El saldo inicial del banco (null si aún no se ha puesto)
 * @var string                $hoy
 * @var string                $url
 * @var string                $url_caja      Adonde van los formularios (la vista de la caja)
 * @var string                $url_apuntes   Excel de los apuntes
 * @var string                $url_resumen   Excel del resumen
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Caja\CajaTable;
use AsdeBalears\AreaPrivada\Frontend\Views\LibroMayorView;
use AsdeBalears\AreaPrivada\Tesoreria\Calculadora;

defined( 'ABSPATH' ) || exit;

$fecha = static fn( string $f ) => wp_date( 'd/m/Y', strtotime( $f . ' 12:00' ) );
$euros = static fn( float $v ) => Calculadora::euros( $v );
$total_e = array_sum( array_column( $libro['resumen'], 'entradas' ) );
$total_s = array_sum( array_column( $libro['resumen'], 'salidas' ) );
?>
<section class="asde-card" data-asde-no-plegable>
	<h2><?php asde_e( 'Libro mayor' ); ?></h2>
	<form method="get" action="<?php echo esc_url( $url ); ?>" class="asde-filter asde-filter--una-linea">
		<label for="asde-lm-periodo" class="screen-reader-text"><?php asde_e( 'Periodo' ); ?></label>
		<select id="asde-lm-periodo" name="periodo" data-asde-autoenviar>
			<?php foreach ( $periodos as $k => $etiqueta ) : ?>
				<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $periodo['clave'], $k ); ?>><?php echo esc_html( $etiqueta ); ?></option>
			<?php endforeach; ?>
		</select>
		<button type="submit" class="button"><?php asde_e( 'Ver' ); ?></button>
		<a class="button asde-button-secundario no-transition" href="<?php echo esc_url( $url_apuntes ); ?>" data-asde-descarga><?php asde_e( 'Excel de los apuntes' ); ?></a>
		<a class="button asde-button-secundario no-transition" href="<?php echo esc_url( $url_resumen ); ?>" data-asde-descarga><?php asde_e( 'Excel del resumen' ); ?></a>
	</form>
	<p class="asde-help"><?php asde_e( 'El banco y cada caja de efectivo, con su saldo al empezar y al acabar el periodo, y el resumen por partida. Los traspasos entre cuentas del grupo salen en cada cuenta, pero no en el resumen.' ); ?></p>
	<table class="asde-table asde-table--resumen">
		<thead>
			<tr>
				<th scope="col"><?php asde_e( 'Cuenta' ); ?></th>
				<th scope="col"><?php asde_e( 'Al empezar' ); ?></th>
				<th scope="col"><?php asde_e( 'Entradas' ); ?></th>
				<th scope="col"><?php asde_e( 'Salidas' ); ?></th>
				<th scope="col"><?php asde_e( 'Al acabar' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $libro['cuentas'] as $c ) : ?>
				<tr>
					<th scope="row" data-label="<?php echo esc_attr( asde_a( 'Cuenta' ) ); ?>"><?php echo esc_html( $c['nombre'] ); ?><?php echo $c['sin_inicial'] ? ' <span class="asde-muted">(' . esc_html( asde_t( 'sin saldo inicial' ) ) . ')</span>' : ''; ?></th>
					<td data-label="<?php echo esc_attr( asde_a( 'Al empezar' ) ); ?>"><?php echo esc_html( $euros( $c['inicial'] ) ); ?></td>
					<td data-label="<?php echo esc_attr( asde_a( 'Entradas' ) ); ?>" class="asde-importe--ingreso">+<?php echo esc_html( $euros( $c['entradas'] ) ); ?></td>
					<td data-label="<?php echo esc_attr( asde_a( 'Salidas' ) ); ?>" class="asde-importe--gasto">−<?php echo esc_html( $euros( $c['salidas'] ) ); ?></td>
					<td data-label="<?php echo esc_attr( asde_a( 'Al acabar' ) ); ?>"><strong><?php echo esc_html( $euros( $c['final'] ) ); ?></strong></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<?php if ( $banco && ! $banco_inicial ) : ?>
		<div class="asde-notice asde-notice--warning" role="status"><?php asde_e( 'El banco aún no tiene saldo inicial: escribe el saldo de la cuenta el día desde el que quieres llevar el libro mayor (sale en el extracto). Lo anterior a esa fecha no cuenta.' ); ?></div>
		<form method="post" action="<?php echo esc_url( $url_caja ); ?>" class="asde-form asde-form__fila asde-form__fila--una-linea asde-caja-form">
			<?php wp_nonce_field( 'asde_caja' ); ?>
			<input type="hidden" name="asde_action" value="inicial">
			<input type="hidden" name="caja" value="<?php echo esc_attr( CajaTable::BANCO ); ?>">
			<input type="hidden" name="periodo" value="<?php echo esc_attr( $periodo['clave'] ); ?>">
			<label for="asde-lm-ini-fecha"><?php asde_e( 'Saldo del banco el' ); ?></label>
			<input type="date" id="asde-lm-ini-fecha" name="fecha" value="<?php echo esc_attr( $hoy ); ?>" max="<?php echo esc_attr( $hoy ); ?>" required>
			<label for="asde-lm-ini-importe" class="screen-reader-text"><?php asde_e( 'Importe (€)' ); ?></label>
			<input type="text" id="asde-lm-ini-importe" name="importe" inputmode="decimal" placeholder="<?php echo esc_attr( asde_a( 'Importe (€)' ) ); ?>" required>
			<button type="submit" class="button"><?php asde_e( 'Guardar' ); ?></button>
		</form>
	<?php elseif ( $banco && $banco_inicial ) : ?>
		<details class="asde-caja-anular">
			<?php /* translators: 1: fecha, 2: importe */ ?>
			<summary><?php echo esc_html( sprintf( asde_t( 'Saldo inicial del banco: %2$s el %1$s' ), $fecha( $banco_inicial['fecha'] ), $euros( (float) $banco_inicial['importe'] ) ) ); ?></summary>
			<form method="post" action="<?php echo esc_url( $url_caja ); ?>" class="asde-form__fila asde-form__fila--una-linea">
				<?php wp_nonce_field( 'asde_caja' ); ?>
				<input type="hidden" name="asde_action" value="anular">
				<input type="hidden" name="caja" value="<?php echo esc_attr( CajaTable::BANCO ); ?>">
				<input type="hidden" name="periodo" value="<?php echo esc_attr( $periodo['clave'] ); ?>">
				<input type="hidden" name="apunte" value="<?php echo (int) $banco_inicial['id']; ?>">
				<label class="screen-reader-text" for="asde-lm-anular"><?php asde_e( 'Motivo' ); ?></label>
				<input type="text" id="asde-lm-anular" name="motivo" maxlength="255" placeholder="<?php echo esc_attr( asde_a( 'Motivo para cambiarlo' ) ); ?>" required>
				<button type="submit" class="button"><?php asde_e( 'Anular para cambiarlo' ); ?></button>
			</form>
		</details>
	<?php endif; ?>
</section>

<section class="asde-card" data-asde-plegable="libro-resumen" data-asde-inicio="abierto">
	<h3><?php asde_e( 'Resumen por partida' ); ?></h3>
	<?php if ( ! $libro['resumen'] ) : ?>
		<p><?php asde_e( 'No hay apuntes en este periodo.' ); ?></p>
	<?php else : ?>
		<table class="asde-table asde-table--resumen">
			<thead>
				<tr>
					<th scope="col"><?php asde_e( 'Partida' ); ?></th>
					<th scope="col"><?php asde_e( 'Entradas' ); ?></th>
					<th scope="col"><?php asde_e( 'Salidas' ); ?></th>
					<th scope="col"><?php asde_e( 'Neto' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $libro['resumen'] as $partida => $r ) : ?>
					<tr>
						<th scope="row" data-label="<?php echo esc_attr( asde_a( 'Partida' ) ); ?>"><?php echo esc_html( $partida ); ?></th>
						<td data-label="<?php echo esc_attr( asde_a( 'Entradas' ) ); ?>"><?php echo $r['entradas'] > 0 ? esc_html( $euros( $r['entradas'] ) ) : '—'; ?></td>
						<td data-label="<?php echo esc_attr( asde_a( 'Salidas' ) ); ?>"><?php echo $r['salidas'] > 0 ? esc_html( $euros( $r['salidas'] ) ) : '—'; ?></td>
						<td data-label="<?php echo esc_attr( asde_a( 'Neto' ) ); ?>"><?php echo esc_html( LibroMayorView::con_signo( round( $r['entradas'] - $r['salidas'], 2 ) ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				<tr class="asde-total">
					<th scope="row"><?php asde_e( 'Total' ); ?></th>
					<td><?php echo esc_html( $euros( round( $total_e, 2 ) ) ); ?></td>
					<td><?php echo esc_html( $euros( round( $total_s, 2 ) ) ); ?></td>
					<td><strong><?php echo esc_html( LibroMayorView::con_signo( round( $total_e - $total_s, 2 ) ) ); ?></strong></td>
				</tr>
			</tbody>
		</table>
	<?php endif; ?>
</section>

<?php foreach ( $libro['cuentas'] as $clave => $c ) : ?>
	<section class="asde-card" data-asde-plegable="libro-<?php echo esc_attr( $clave ); ?>" data-asde-inicio="cerrado">
		<h3><?php echo esc_html( $c['nombre'] ); ?></h3>
		<?php if ( ! $c['filas'] ) : ?>
			<p><?php asde_e( 'No hay apuntes en este periodo.' ); ?></p>
		<?php else : ?>
			<table class="asde-table asde-table--caja">
				<thead>
					<tr>
						<th scope="col"><?php asde_e( 'Fecha' ); ?></th>
						<th scope="col"><?php asde_e( 'Concepto' ); ?></th>
						<th scope="col"><?php asde_e( 'Partida' ); ?></th>
						<th scope="col"><?php asde_e( 'Importe' ); ?></th>
						<th scope="col"><?php asde_e( 'Saldo' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<tr>
						<td data-label="<?php echo esc_attr( asde_a( 'Fecha' ) ); ?>">—</td>
						<td data-label="<?php echo esc_attr( asde_a( 'Concepto' ) ); ?>"><em><?php asde_e( 'Saldo al empezar' ); ?></em></td>
						<td data-label="<?php echo esc_attr( asde_a( 'Partida' ) ); ?>">—</td>
						<td data-label="<?php echo esc_attr( asde_a( 'Importe' ) ); ?>">—</td>
						<td data-label="<?php echo esc_attr( asde_a( 'Saldo' ) ); ?>"><?php echo esc_html( $euros( $c['inicial'] ) ); ?></td>
					</tr>
					<?php foreach ( $c['filas'] as $f ) : ?>
						<tr>
							<td data-label="<?php echo esc_attr( asde_a( 'Fecha' ) ); ?>"><?php echo esc_html( $fecha( $f['fecha'] ) ); ?></td>
							<td data-label="<?php echo esc_attr( asde_a( 'Concepto' ) ); ?>"><?php echo esc_html( $f['concepto'] ); ?></td>
							<td data-label="<?php echo esc_attr( asde_a( 'Partida' ) ); ?>"><?php echo esc_html( $f['partida'] ); ?></td>
							<td data-label="<?php echo esc_attr( asde_a( 'Importe' ) ); ?>" class="asde-importe <?php echo $f['importe'] >= 0 ? 'asde-importe--ingreso' : 'asde-importe--gasto'; ?>"><?php echo esc_html( LibroMayorView::con_signo( $f['importe'] ) ); ?></td>
							<td data-label="<?php echo esc_attr( asde_a( 'Saldo' ) ); ?>"><?php echo esc_html( $euros( $f['saldo'] ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</section>
<?php endforeach; ?>
