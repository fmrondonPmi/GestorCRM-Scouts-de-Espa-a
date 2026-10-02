<?php
/**
 * Tesorería → Banco: importar ficheros y conciliar ingresos.
 *
 * @var array[]                                $movs      Página que se muestra (con 'sugerencia')
 * @var int                                    $total
 * @var int                                    $sugeridas Ingresos pendientes con referencia de familia
 * @var AsdeBalears\AreaPrivada\Frontend\Tabla $tabla
 * @var array{estado:string, tipo:string, q:string} $filtros
 * @var array{por_numero: array, etiquetas: array<int, string>} $familias
 * @var array{pendientes:int, pendientes_importe:float, saldo:?float, saldo_fecha:?string} $resumen
 * @var array[]                                $lotes
 * @var string[]                               $rondas
 * @var string                                 $url
 * @var string                                 $url_fam
 */
use AsdeBalears\AreaPrivada\Tesoreria\Banco\BancoTable;
use AsdeBalears\AreaPrivada\Tesoreria\Banco\Codificacion;
use AsdeBalears\AreaPrivada\Tesoreria\Banco\Norma43;
use AsdeBalears\AreaPrivada\Tesoreria\Calculadora;
use AsdeBalears\AreaPrivada\Tesoreria\Config;

defined( 'ABSPATH' ) || exit;

$columnas = array(
	'fecha'    => 'Fecha',
	'concepto' => 'Concepto',
	'importe'  => 'Importe',
	'estado'   => 'Estado',
);
?>
<section class="asde-card">
	<h2>Banco</h2>
	<dl class="asde-datos">
		<div><dt>Saldo según el último fichero</dt><dd><strong><?php echo null !== $resumen['saldo'] ? esc_html( Calculadora::euros( $resumen['saldo'] ) ) : '—'; ?></strong><?php echo $resumen['saldo_fecha'] ? ' <span class="asde-muted">a ' . esc_html( wp_date( 'd/m/Y', strtotime( $resumen['saldo_fecha'] . ' 12:00' ) ) ) . '</span>' : ''; ?></dd></div>
		<div><dt>Ingresos por conciliar</dt><dd><strong><?php echo (int) $resumen['pendientes']; ?></strong> · <?php echo esc_html( Calculadora::euros( $resumen['pendientes_importe'] ) ); ?></dd></div>
	</dl>

	<details class="asde-importar" <?php echo $lotes ? '' : 'open'; ?>>
		<summary><strong>Importar movimientos</strong> (fichero Norma 43 o CSV de la banca online)</summary>
		<form method="post" enctype="multipart/form-data" class="asde-form">
			<?php wp_nonce_field( 'asde_banco_analizar' ); ?>
			<input type="hidden" name="asde_action" value="analizar">
			<p>
				<label for="asde-fichero">Fichero</label>
				<input type="file" id="asde-fichero" name="fichero" accept=".n43,.q43,.aeb,.txt,.dat,.csv" required>
				<span class="asde-help">Descárgalo de la banca online (Colonya Directa) en formato <strong>Norma 43</strong> o <strong>CSV</strong>. El fichero no se guarda: se leen sus movimientos y se descarta. Si un movimiento ya estaba importado, se ignora.</span>
			</p>
			<div class="asde-grid-2">
				<p>
					<label for="asde-formato">Formato</label>
					<select id="asde-formato" name="formato">
						<option value="auto">Detectar automáticamente</option>
						<option value="n43">Norma 43 (Cuaderno 43)</option>
						<option value="csv">CSV</option>
					</select>
				</p>
				<p>
					<label for="asde-codificacion">Juego de caracteres</label>
					<select id="asde-codificacion" name="codificacion">
						<?php foreach ( Codificacion::LISTA as $k => $label ) : ?>
							<option value="<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<span class="asde-help">Cámbialo solo si las eñes o los acentos salen mal en la vista previa.</span>
				</p>
			</div>
			<p><button type="submit" class="button">Leer el fichero</button></p>
		</form>
	</details>

	<?php if ( $lotes ) : ?>
		<h3>Últimas importaciones</h3>
		<table class="asde-table">
			<thead><tr><th scope="col">Fecha</th><th scope="col">Fichero</th><th scope="col">Cuenta</th><th scope="col">Periodo</th><th scope="col">Movimientos</th><th scope="col">Por</th></tr></thead>
			<tbody>
				<?php foreach ( $lotes as $l ) : ?>
					<?php $autor = get_userdata( (int) $l['importado_por'] ); ?>
					<tr>
						<td data-label="Fecha"><?php echo esc_html( wp_date( 'd/m/Y H:i', strtotime( $l['creado'] . ' UTC' ) ) ); ?></td>
						<td data-label="Fichero"><?php echo esc_html( $l['fichero'] ); ?> <span class="asde-muted">(<?php echo 'n43' === $l['origen'] ? 'Norma 43' : 'CSV'; ?>)</span></td>
						<td data-label="Cuenta"><?php echo esc_html( $l['cuentas'] ); ?></td>
						<td data-label="Periodo"><?php echo $l['desde'] ? esc_html( wp_date( 'd/m/Y', strtotime( $l['desde'] . ' 12:00' ) ) . ' – ' . wp_date( 'd/m/Y', strtotime( $l['hasta'] . ' 12:00' ) ) ) : '—'; ?></td>
						<td data-label="Movimientos"><?php echo (int) $l['nuevos']; ?> nuevos<?php echo (int) $l['duplicados'] ? ' · ' . (int) $l['duplicados'] . ' ya importados' : ''; ?></td>
						<td data-label="Por"><?php echo $autor ? esc_html( $autor->user_login ) : '—'; ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</section>

<section class="asde-card">
	<header class="asde-card__header asde-card__header--split">
		<h3 class="asde-card__title">Movimientos</h3>
		<?php if ( $sugeridas && BancoTable::PENDIENTE === $filtros['estado'] ) : ?>
			<?php $uno = 1 === (int) $sugeridas; ?>
			<form method="post" onsubmit="return confirm('<?php echo esc_js( $uno ? '¿Conciliar el ingreso que lleva la referencia de su familia en el concepto?' : sprintf( '¿Conciliar los %d ingresos que llevan la referencia de su familia en el concepto?', (int) $sugeridas ) ); ?>');">
				<?php wp_nonce_field( 'asde_banco_sugeridas' ); ?>
				<input type="hidden" name="asde_action" value="conciliar_sugeridas">
				<button type="submit" class="button"><?php echo esc_html( $uno ? 'Conciliar el que lleva la referencia de familia' : sprintf( 'Conciliar los %d que llevan la referencia de familia', (int) $sugeridas ) ); ?></button>
			</form>
		<?php endif; ?>
	</header>

	<form method="get" action="<?php echo esc_url( $url ); ?>" class="asde-filter">
		<label for="asde-banco-estado">Mostrar</label>
		<select id="asde-banco-estado" name="estado" onchange="this.form.submit()">
			<option value="">Todos</option>
			<?php foreach ( BancoTable::ESTADOS as $k => $label ) : ?>
				<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $filtros['estado'], $k ); ?>><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select>
		<select name="tipo" aria-label="Tipo" onchange="this.form.submit()">
			<option value="">Ingresos y gastos</option>
			<option value="ingresos" <?php selected( $filtros['tipo'], 'ingresos' ); ?>>Solo ingresos</option>
			<option value="gastos" <?php selected( $filtros['tipo'], 'gastos' ); ?>>Solo gastos</option>
		</select>
		<label for="asde-banco-q" class="screen-reader-text">Buscar</label>
		<input type="search" id="asde-banco-q" name="q" value="<?php echo esc_attr( $filtros['q'] ); ?>" placeholder="Concepto, ordenante o referencia">
		<button type="submit" class="button">Filtrar</button>
	</form>

	<?php if ( ! $movs ) : ?>
		<p><?php echo $filtros['estado'] || $filtros['tipo'] || $filtros['q'] ? 'No hay movimientos con este filtro.' : 'Todavía no hay movimientos: importa el primer fichero del banco.'; ?></p>
	<?php else : ?>
		<p class="asde-muted"><?php echo esc_html( sprintf( _n( '%d movimiento', '%d movimientos', $total, 'asde-area-privada' ), $total ) ); ?></p>
		<table class="asde-table asde-table--banco">
			<thead>
				<tr>
					<?php foreach ( $columnas as $col => $etiqueta ) : ?>
						<th scope="col"<?php echo $tabla->aria_sort( $col ); // phpcs:ignore WordPress.Security.EscapeOutput ?>><?php echo $tabla->cabecera( $col, $etiqueta ); // phpcs:ignore WordPress.Security.EscapeOutput ?></th>
					<?php endforeach; ?>
					<th scope="col">Familia</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $movs as $m ) : ?>
					<?php
					$ingreso = $m['importe'] > 0;
					$sug     = $m['sugerencia'];
					?>
					<tr>
						<td data-label="Fecha"><?php echo esc_html( wp_date( 'd/m/Y', strtotime( $m['fecha'] . ' 12:00' ) ) ); ?></td>
						<td data-label="Concepto">
							<?php if ( '' !== $m['ordenante'] ) : ?>
								<strong><?php echo esc_html( $m['ordenante'] ); ?></strong><br>
							<?php endif; ?>
							<?php echo esc_html( '' !== $m['concepto'] ? $m['concepto'] : ( Norma43::CONCEPTOS[ $m['concepto_comun'] ] ?? '—' ) ); ?>
							<?php if ( '' !== $m['referencia1'] . $m['referencia2'] ) : ?>
								<br><span class="asde-muted">Ref.: <?php echo esc_html( trim( $m['referencia1'] . ' ' . $m['referencia2'] ) ); ?></span>
							<?php endif; ?>
						</td>
						<td data-label="Importe" class="asde-importe <?php echo $ingreso ? 'asde-importe--ingreso' : 'asde-importe--gasto'; ?>"><?php echo esc_html( ( $ingreso ? '+' : '' ) . Calculadora::euros( $m['importe'] ) ); ?></td>
						<td data-label="Estado">
							<span class="asde-badge asde-badge--banco-<?php echo esc_attr( $m['estado'] ); ?>"><?php echo esc_html( BancoTable::ESTADOS[ $m['estado'] ] ?? $m['estado'] ); ?></span>
						</td>
						<td data-label="Familia">
							<?php if ( BancoTable::CONCILIADO === $m['estado'] ) : ?>
								<?php echo esc_html( $familias['etiquetas'][ (int) $m['familia_id'] ] ?? '—' ); ?>
							<?php elseif ( BancoTable::PENDIENTE === $m['estado'] && $ingreso ) : ?>
								<?php if ( $sug ) : ?>
									<span class="asde-help<?php echo $sug['fiable'] ? '' : ' asde-help--warning'; ?>">Sugerida: <?php echo esc_html( $sug['motivo'] ); ?></span>
								<?php endif; ?>
								<?php if ( $familias['etiquetas'] ) : ?>
									<form method="post" class="asde-conciliar">
										<?php wp_nonce_field( 'asde_banco_mov_' . (int) $m['id'] ); ?>
										<input type="hidden" name="asde_action" value="conciliar">
										<input type="hidden" name="mov" value="<?php echo (int) $m['id']; ?>">
										<label class="screen-reader-text" for="asde-fam-<?php echo (int) $m['id']; ?>">Familia</label>
										<select id="asde-fam-<?php echo (int) $m['id']; ?>" name="familia" required class="asde-combobox" data-min="3" data-placeholder="Familia: nº o apellido (3 letras)…">
											<option value="">Elige la familia…</option>
											<?php foreach ( $familias['etiquetas'] as $fid => $etiqueta ) : ?>
												<option value="<?php echo (int) $fid; ?>" <?php selected( $sug ? (int) $sug['familia']['id'] : 0, (int) $fid ); ?>><?php echo esc_html( $etiqueta ); ?></option>
											<?php endforeach; ?>
										</select>
										<label class="screen-reader-text" for="asde-ronda-<?php echo (int) $m['id']; ?>">Ronda</label>
										<select id="asde-ronda-<?php echo (int) $m['id']; ?>" name="ronda_pago">
											<?php $rd = Config::ronda_de_fecha( $m['fecha'] ); ?>
											<?php foreach ( array_unique( array_merge( array( $rd ), $rondas ) ) as $r ) : ?>
												<option value="<?php echo esc_attr( $r ); ?>" <?php selected( $rd, $r ); ?>><?php echo esc_html( $r ); ?></option>
											<?php endforeach; ?>
										</select>
										<button type="submit" class="button">Conciliar</button>
									</form>
								<?php else : ?>
									<span class="asde-muted">Crea antes las <a href="<?php echo esc_url( $url_fam ); ?>">familias</a>.</span>
								<?php endif; ?>
							<?php endif; ?>
							<div class="asde-actions">
								<?php if ( BancoTable::PENDIENTE === $m['estado'] ) : ?>
									<form method="post">
										<?php wp_nonce_field( 'asde_banco_mov_' . (int) $m['id'] ); ?>
										<input type="hidden" name="asde_action" value="ignorar">
										<input type="hidden" name="mov" value="<?php echo (int) $m['id']; ?>">
										<button type="submit" class="asde-btn-link"><?php echo $ingreso ? 'No es una cuota' : 'Marcar revisado'; ?></button>
									</form>
								<?php else : ?>
									<form method="post" onsubmit="return confirm('¿Deshacer? <?php echo BancoTable::CONCILIADO === $m['estado'] ? 'Se anulará el pago que se registró en la familia.' : ''; ?>');">
										<?php wp_nonce_field( 'asde_banco_mov_' . (int) $m['id'] ); ?>
										<input type="hidden" name="asde_action" value="deshacer">
										<input type="hidden" name="mov" value="<?php echo (int) $m['id']; ?>">
										<button type="submit" class="asde-btn-link">Deshacer</button>
									</form>
								<?php endif; ?>
							</div>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php echo $tabla->paginacion(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
	<?php endif; ?>
</section>
