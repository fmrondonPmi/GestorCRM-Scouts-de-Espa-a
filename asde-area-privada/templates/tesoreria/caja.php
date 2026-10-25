<?php
/**
 * Tesorería → Caja (v0.59, doc 61): saldo y apuntes de una caja de efectivo en un periodo, y los formularios para
 * apuntar, traspasar, contar (arqueo) y poner el saldo inicial.
 *
 * @var string                $caja
 * @var array<string, string> $cajas     Las que ve (clave => nombre)
 * @var array                 $periodo   Periodo::de()
 * @var array<string, string> $periodos  Periodo::opciones()
 * @var array                 $e         Servicio::extracto()
 * @var array<int, array>     $tipos     Tipos de gasto e ingreso
 * @var array<string, string> $hacia     Las otras cajas (para un traspaso)
 * @var bool                  $con_banco Lleva todas las cajas: traspasos con el banco y asociarlos
 * @var array<int, array>     $bancos    Movimientos del banco asociados a los traspasos de la lista
 * @var string                $hoy
 * @var float                 $saldo_hoy
 * @var string                $url
 * @var string                $aqui      Esta caja y este periodo
 * @var string                $url_excel
 * @var callable              $enlace    Dirección del módulo de un apunte automático
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Caja\CajaTable;
use AsdeBalears\AreaPrivada\Caja\LibroMayor;
use AsdeBalears\AreaPrivada\Tesoreria\Banco\TiposMovimiento;
use AsdeBalears\AreaPrivada\Tesoreria\Calculadora;

defined( 'ABSPATH' ) || exit;

$fecha  = static fn( string $f ) => wp_date( 'd/m/Y', strtotime( $f . ' 12:00' ) );
$euros  = static fn( float $v ) => Calculadora::euros( $v );
$ocultos = static function () use ( $caja, $periodo ) {
	wp_nonce_field( 'asde_caja' );
	echo '<input type="hidden" name="caja" value="' . esc_attr( $caja ) . '"><input type="hidden" name="periodo" value="' . esc_attr( $periodo['clave'] ) . '">';
};
$grupos_tipo = array();
foreach ( $tipos as $t ) {
	$grupos_tipo[ $t['naturaleza'] ][] = $t;
}
?>
<section class="asde-card" data-asde-no-plegable>
	<h2><?php echo esc_html( $cajas[ $caja ] ); ?></h2>
	<form method="get" action="<?php echo esc_url( $url ); ?>" class="asde-filter asde-filter--una-linea">
		<?php if ( count( $cajas ) > 1 ) : ?>
			<label for="asde-caja-caja" class="screen-reader-text"><?php asde_e( 'Caja' ); ?></label>
			<select id="asde-caja-caja" name="caja" data-asde-autoenviar>
				<?php foreach ( $cajas as $k => $nombre ) : ?>
					<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $caja, $k ); ?>><?php echo esc_html( $nombre ); ?></option>
				<?php endforeach; ?>
			</select>
		<?php else : ?>
			<input type="hidden" name="caja" value="<?php echo esc_attr( $caja ); ?>">
		<?php endif; ?>
		<label for="asde-caja-periodo" class="screen-reader-text"><?php asde_e( 'Periodo' ); ?></label>
		<select id="asde-caja-periodo" name="periodo" data-asde-autoenviar>
			<?php foreach ( $periodos as $k => $etiqueta ) : ?>
				<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $periodo['clave'], $k ); ?>><?php echo esc_html( $etiqueta ); ?></option>
			<?php endforeach; ?>
		</select>
		<button type="submit" class="button"><?php asde_e( 'Ver' ); ?></button>
		<a class="button asde-button-secundario no-transition" href="<?php echo esc_url( $url_excel ); ?>" data-asde-descarga><?php asde_e( 'Descargar Excel' ); ?></a>
	</form>
	<dl class="asde-datos">
		<div><dt><?php asde_e( 'Al empezar' ); ?></dt><dd><?php echo esc_html( $euros( $e['inicial'] ) ); ?></dd></div>
		<div><dt><?php asde_e( 'Entradas' ); ?></dt><dd class="asde-importe--ingreso">+<?php echo esc_html( $euros( $e['entradas'] ) ); ?></dd></div>
		<div><dt><?php asde_e( 'Salidas' ); ?></dt><dd class="asde-importe--gasto">−<?php echo esc_html( $euros( $e['salidas'] ) ); ?></dd></div>
		<div><dt><?php asde_e( 'Al acabar' ); ?></dt><dd><strong><?php echo esc_html( $euros( $e['final'] ) ); ?></strong></dd></div>
		<div><dt><?php asde_e( 'Último arqueo' ); ?></dt><dd><?php echo $e['arqueo'] ? esc_html( $fecha( $e['arqueo']['fecha'] ) . ' · ' . ( abs( $e['arqueo']['importe'] ) < 0.01 ? asde_t( 'cuadraba' ) : sprintf( asde_t( 'diferencia de %s' ), $euros( $e['arqueo']['importe'] ) ) ) ) : '—'; ?></dd></div>
	</dl>
	<?php if ( ! $e['saldo_inicial'] ) : ?>
		<div class="asde-notice asde-notice--warning" role="status"><?php asde_e( 'Esta caja aún no tiene saldo inicial: escribe lo que había en ella el día que empezáis a llevarla aquí. Lo anterior a esa fecha no cuenta.' ); ?></div>
		<form method="post" action="<?php echo esc_url( $url ); ?>" class="asde-form asde-form__fila asde-form__fila--una-linea asde-caja-form">
			<?php $ocultos(); ?>
			<input type="hidden" name="asde_action" value="inicial">
			<label for="asde-caja-ini-fecha"><?php asde_e( 'Saldo inicial el' ); ?></label>
			<input type="date" id="asde-caja-ini-fecha" name="fecha" value="<?php echo esc_attr( $hoy ); ?>" max="<?php echo esc_attr( $hoy ); ?>" required>
			<label for="asde-caja-ini-importe" class="screen-reader-text"><?php asde_e( 'Importe (€)' ); ?></label>
			<input type="text" id="asde-caja-ini-importe" name="importe" inputmode="decimal" placeholder="<?php echo esc_attr( asde_a( 'Importe (€)' ) ); ?>" required>
			<button type="submit" class="button"><?php asde_e( 'Guardar' ); ?></button>
		</form>
	<?php endif; ?>
</section>

<section class="asde-card" data-asde-plegable="caja-apuntes" data-asde-inicio="abierto">
	<h3><?php asde_e( 'Apuntes' ); ?> · <?php echo esc_html( $periodo['etiqueta'] ); ?></h3>
	<?php if ( ! $e['filas'] ) : ?>
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
					<th scope="col"><span class="screen-reader-text"><?php asde_e( 'Acciones' ); ?></span></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $e['filas'] as $a ) : ?>
					<tr>
						<td data-label="<?php echo esc_attr( asde_a( 'Fecha' ) ); ?>"><?php echo esc_html( $fecha( $a['fecha'] ) ); ?></td>
						<td data-label="<?php echo esc_attr( asde_a( 'Concepto' ) ); ?>">
							<?php echo esc_html( LibroMayor::concepto_caja( $a ) ); ?>
							<?php if ( $a['propio'] && CajaTable::MANUAL === $a['clase'] && '' !== $a['nota'] ) : ?>
								<br><span class="asde-muted"><?php echo esc_html( $a['nota'] ); ?></span>
							<?php endif; ?>
							<?php if ( CajaTable::TRASPASO === $a['clase'] && CajaTable::BANCO === $a['destino'] ) : ?>
								<br><span class="asde-banco-asociado">
									<?php if ( $a['banco_id'] && isset( $bancos[ $a['banco_id'] ] ) ) : ?>
										↔ <?php asde_e( 'Banco:' ); ?> <?php echo esc_html( $fecha( $bancos[ $a['banco_id'] ]['fecha'] ) . ' · ' . $euros( (float) $bancos[ $a['banco_id'] ]['importe'] ) ); ?>
									<?php else : ?>
										<?php asde_e( 'Sin asociar con el banco' ); ?>
									<?php endif; ?>
								</span>
							<?php endif; ?>
						</td>
						<td data-label="<?php echo esc_attr( asde_a( 'Partida' ) ); ?>"><?php echo CajaTable::INICIAL === $a['clase'] ? '—' : esc_html( LibroMayor::partida_caja( $a, $tipos ) ); ?></td>
						<td data-label="<?php echo esc_attr( asde_a( 'Importe' ) ); ?>" class="asde-importe <?php echo $a['importe'] >= 0 ? 'asde-importe--ingreso' : 'asde-importe--gasto'; ?>"><?php echo esc_html( ( $a['importe'] > 0 ? '+' : '' ) . $euros( $a['importe'] ) ); ?></td>
						<td data-label="<?php echo esc_attr( asde_a( 'Saldo' ) ); ?>"><?php echo esc_html( $euros( $a['saldo'] ) ); ?></td>
						<td data-label="">
							<div class="asde-actions">
								<?php if ( ! $a['propio'] ) : ?>
									<?php $destino = $enlace( $a ); ?>
									<?php if ( '' !== $destino ) : ?>
										<a href="<?php echo esc_url( $destino ); ?>"><?php asde_e( 'Ver en su sitio' ); ?></a>
									<?php endif; ?>
								<?php else : ?>
									<?php if ( $con_banco && CajaTable::TRASPASO === $a['clase'] && CajaTable::BANCO === $a['destino'] ) : ?>
										<?php if ( $a['banco_id'] ) : ?>
											<form method="post" action="<?php echo esc_url( $url ); ?>">
												<?php $ocultos(); ?>
												<input type="hidden" name="asde_action" value="banco">
												<input type="hidden" name="apunte" value="<?php echo (int) $a['id']; ?>">
												<input type="hidden" name="banco" value="0">
												<button type="submit" class="asde-btn-link"><?php asde_e( 'Quitar la asociación' ); ?></button>
											</form>
										<?php else : ?>
											<a href="<?php echo esc_url( add_query_arg( 'banco', (int) $a['id'], $url ) ); ?>" data-asde-modal><?php asde_e( 'Asociar con el banco…' ); ?></a>
										<?php endif; ?>
									<?php endif; ?>
									<details class="asde-caja-anular">
										<summary><?php asde_e( 'Anular…' ); ?></summary>
										<form method="post" action="<?php echo esc_url( $url ); ?>" class="asde-form__fila asde-form__fila--una-linea">
											<?php $ocultos(); ?>
											<input type="hidden" name="asde_action" value="anular">
											<input type="hidden" name="apunte" value="<?php echo (int) $a['id']; ?>">
											<label class="screen-reader-text" for="asde-caja-anular-<?php echo (int) $a['id']; ?>"><?php asde_e( 'Motivo' ); ?></label>
											<input type="text" id="asde-caja-anular-<?php echo (int) $a['id']; ?>" name="motivo" maxlength="255" placeholder="<?php echo esc_attr( asde_a( 'Motivo' ) ); ?>" required>
											<button type="submit" class="button"><?php asde_e( 'Anular' ); ?></button>
										</form>
									</details>
								<?php endif; ?>
							</div>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</section>

<section class="asde-card" data-asde-plegable="caja-apuntar" data-asde-inicio="abierto">
	<h3><?php asde_e( 'Apuntar una entrada o una salida' ); ?></h3>
	<p class="asde-help"><?php asde_e( 'Solo lo que no está en otro sitio (sellos, un donativo en mano…). Los cobros y pagos en efectivo de cuotas, eventos, tienda y reintegros entran solos en la caja del grupo.' ); ?></p>
	<form method="post" action="<?php echo esc_url( $url ); ?>" class="asde-form asde-form__fila asde-form__fila--una-linea asde-caja-form">
		<?php $ocultos(); ?>
		<input type="hidden" name="asde_action" value="apuntar">
		<label for="asde-caja-a-fecha" class="screen-reader-text"><?php asde_e( 'Fecha' ); ?></label>
		<input type="date" id="asde-caja-a-fecha" name="fecha" value="<?php echo esc_attr( $hoy ); ?>" max="<?php echo esc_attr( $hoy ); ?>" required>
		<label for="asde-caja-a-sentido" class="screen-reader-text"><?php asde_e( 'Entrada o salida' ); ?></label>
		<select id="asde-caja-a-sentido" name="sentido">
			<option value="salida"><?php asde_e( 'Sale' ); ?></option>
			<option value="entrada"><?php asde_e( 'Entra' ); ?></option>
		</select>
		<label for="asde-caja-a-importe" class="screen-reader-text"><?php asde_e( 'Importe (€)' ); ?></label>
		<input type="text" id="asde-caja-a-importe" name="importe" inputmode="decimal" placeholder="<?php echo esc_attr( asde_a( 'Importe (€)' ) ); ?>" required>
		<label for="asde-caja-a-tipo" class="screen-reader-text"><?php asde_e( 'Tipo de gasto o ingreso' ); ?></label>
		<select id="asde-caja-a-tipo" name="tipo">
			<option value="0"><?php asde_e( 'Sin tipo' ); ?></option>
			<?php foreach ( $grupos_tipo as $nat => $lista ) : ?>
				<optgroup label="<?php echo esc_attr( asde_t( TiposMovimiento::NATURALEZAS[ $nat ] ?? $nat ) ); ?>">
					<?php foreach ( $lista as $t ) : ?>
						<option value="<?php echo (int) $t['id']; ?>"><?php echo esc_html( $t['nombre'] ); ?></option>
					<?php endforeach; ?>
				</optgroup>
			<?php endforeach; ?>
		</select>
		<label for="asde-caja-a-concepto" class="screen-reader-text"><?php asde_e( 'Concepto' ); ?></label>
		<input type="text" id="asde-caja-a-concepto" name="concepto" maxlength="190" placeholder="<?php echo esc_attr( asde_a( 'Concepto' ) ); ?>" required>
		<button type="submit" class="button"><?php asde_e( 'Apuntar' ); ?></button>
	</form>
</section>

<section class="asde-card" data-asde-plegable="caja-traspaso" data-asde-inicio="cerrado">
	<h3><?php asde_e( 'Traspaso' ); ?></h3>
	<p class="asde-help"><?php asde_e( 'Dinero que pasa de esta caja a otra o al banco (o que se saca del banco para esta caja). No es un gasto ni un ingreso del grupo.' ); ?></p>
	<form method="post" action="<?php echo esc_url( $url ); ?>" class="asde-form asde-form__fila asde-form__fila--una-linea asde-caja-form">
		<?php $ocultos(); ?>
		<input type="hidden" name="asde_action" value="traspasar">
		<label for="asde-caja-t-fecha" class="screen-reader-text"><?php asde_e( 'Fecha' ); ?></label>
		<input type="date" id="asde-caja-t-fecha" name="fecha" value="<?php echo esc_attr( $hoy ); ?>" max="<?php echo esc_attr( $hoy ); ?>" required>
		<label for="asde-caja-t-hacia" class="screen-reader-text"><?php asde_e( 'A dónde va' ); ?></label>
		<select id="asde-caja-t-hacia" name="hacia" required>
			<?php foreach ( $hacia as $k => $nombre ) : ?>
				<option value="<?php echo esc_attr( $k ); ?>"><?php echo esc_html( '→ ' . $nombre ); ?></option>
			<?php endforeach; ?>
			<?php if ( $con_banco ) : ?>
				<option value="<?php echo esc_attr( CajaTable::BANCO ); ?>"><?php asde_e( 'Ingresarlo en el banco' ); ?></option>
				<option value="desde_banco"><?php asde_e( 'Sacarlo del banco para esta caja' ); ?></option>
			<?php endif; ?>
		</select>
		<label for="asde-caja-t-importe" class="screen-reader-text"><?php asde_e( 'Importe (€)' ); ?></label>
		<input type="text" id="asde-caja-t-importe" name="importe" inputmode="decimal" placeholder="<?php echo esc_attr( asde_a( 'Importe (€)' ) ); ?>" required>
		<label for="asde-caja-t-concepto" class="screen-reader-text"><?php asde_e( 'Concepto' ); ?></label>
		<input type="text" id="asde-caja-t-concepto" name="concepto" maxlength="190" placeholder="<?php echo esc_attr( asde_a( 'Concepto (opcional)' ) ); ?>">
		<button type="submit" class="button"><?php asde_e( 'Traspasar' ); ?></button>
	</form>
</section>

<section class="asde-card" data-asde-plegable="caja-arqueo" data-asde-inicio="cerrado">
	<h3><?php asde_e( 'Arqueo' ); ?></h3>
	<?php /* translators: %s: saldo de hoy */ ?>
	<p class="asde-help"><?php echo esc_html( sprintf( asde_t( 'Cuenta el efectivo y escribe cuánto hay. Hoy debería haber %s. Si no cuadra, escribe el motivo: la diferencia queda apuntada como ajuste.' ), $euros( $saldo_hoy ) ) ); ?></p>
	<form method="post" action="<?php echo esc_url( $url ); ?>" class="asde-form asde-form__fila asde-form__fila--una-linea asde-caja-form">
		<?php $ocultos(); ?>
		<input type="hidden" name="asde_action" value="arqueo">
		<label for="asde-caja-q-fecha" class="screen-reader-text"><?php asde_e( 'Fecha' ); ?></label>
		<input type="date" id="asde-caja-q-fecha" name="fecha" value="<?php echo esc_attr( $hoy ); ?>" max="<?php echo esc_attr( $hoy ); ?>" required>
		<label for="asde-caja-q-recuento" class="screen-reader-text"><?php asde_e( 'Lo contado (€)' ); ?></label>
		<input type="text" id="asde-caja-q-recuento" name="recuento" inputmode="decimal" placeholder="<?php echo esc_attr( asde_a( 'Lo contado (€)' ) ); ?>" required>
		<label for="asde-caja-q-motivo" class="screen-reader-text"><?php asde_e( 'Motivo' ); ?></label>
		<input type="text" id="asde-caja-q-motivo" name="motivo" maxlength="255" placeholder="<?php echo esc_attr( asde_a( 'Motivo (si no cuadra)' ) ); ?>">
		<button type="submit" class="button"><?php asde_e( 'Guardar el arqueo' ); ?></button>
	</form>
</section>
