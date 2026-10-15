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
 * @var array<int, array>                      $tipos     Tipos de gasto e ingreso (v0.20)
 * @var string                                 $url
 * @var string                                 $url_fam
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Tesoreria\Banco\BancoTable;
use AsdeBalears\AreaPrivada\Tesoreria\Banco\Codificacion;
use AsdeBalears\AreaPrivada\Tesoreria\Banco\Norma43;
use AsdeBalears\AreaPrivada\Tesoreria\Banco\TiposMovimiento;
use AsdeBalears\AreaPrivada\Tesoreria\Calculadora;
use AsdeBalears\AreaPrivada\Tesoreria\Config;

defined( 'ABSPATH' ) || exit;

$columnas = array(
	'fecha'    => asde_t( 'Fecha' ),
	'concepto' => asde_t( 'Concepto' ),
	'importe'  => asde_t( 'Importe' ),
	'estado'   => asde_t( 'Estado' ),
);
$aqui    = add_query_arg( array() ); // Este listado, con sus filtros (para volver después de revisar).
$revisar = static fn( array $ids ) => add_query_arg(
	array(
		'revisar' => '1',
		'm'       => $ids,
		'volver'  => rawurlencode( $aqui ),
	),
	$url
);
?>
<section class="asde-card">
	<h2><?php asde_e( 'Banco' ); ?></h2>
	<dl class="asde-datos" data-asde-parcial="banco-resumen">
		<div><dt><?php asde_e( 'Saldo según el último fichero' ); ?></dt><dd><strong><?php echo null !== $resumen['saldo'] ? esc_html( Calculadora::euros( $resumen['saldo'] ) ) : '—'; ?></strong><?php echo $resumen['saldo_fecha'] ? ' <span class="asde-muted">a ' . esc_html( wp_date( 'd/m/Y', strtotime( $resumen['saldo_fecha'] . ' 12:00' ) ) ) . '</span>' : ''; ?></dd></div>
		<div><dt><?php asde_e( 'Ingresos por conciliar' ); ?></dt><dd><strong><?php echo (int) $resumen['pendientes']; ?></strong> · <?php echo esc_html( Calculadora::euros( $resumen['pendientes_importe'] ) ); ?></dd></div>
	</dl>

	<details class="asde-importar" <?php echo $lotes ? '' : 'open'; ?>>
		<summary><strong><?php asde_e( 'Importar movimientos' ); ?></strong> <?php asde_e( '(fichero Norma 43 o CSV de la banca online)' ); ?></summary>
		<form method="post" enctype="multipart/form-data" class="asde-form" data-asde-recarga>
			<?php wp_nonce_field( 'asde_banco_analizar' ); ?>
			<input type="hidden" name="asde_action" value="analizar">
			<p>
				<label for="asde-fichero"><?php asde_e( 'Fichero' ); ?></label>
				<input type="file" id="asde-fichero" name="fichero" accept=".n43,.q43,.aeb,.txt,.dat,.csv" required>
				<span class="asde-help"><?php asde_e( 'Descárgalo de la banca online (Colonya Directa) en formato' ); ?> <strong><?php asde_e( 'Norma 43' ); ?></strong> o <strong><?php asde_e( 'CSV' ); ?></strong><?php asde_e( '. El fichero no se guarda: se leen sus movimientos y se descarta. Si un movimiento ya estaba importado, se ignora.' ); ?></span>
			</p>
			<div class="asde-grid-2">
				<p>
					<label for="asde-formato"><?php asde_e( 'Formato' ); ?></label>
					<select id="asde-formato" name="formato">
						<option value="auto"><?php asde_e( 'Detectar automáticamente' ); ?></option>
						<option value="n43"><?php asde_e( 'Norma 43 (Cuaderno 43)' ); ?></option>
						<option value="csv"><?php asde_e( 'CSV' ); ?></option>
					</select>
				</p>
				<p>
					<label for="asde-codificacion"><?php asde_e( 'Juego de caracteres' ); ?></label>
					<select id="asde-codificacion" name="codificacion">
						<?php foreach ( asde_ta( Codificacion::LISTA ) as $k => $label ) : ?>
							<option value="<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<span class="asde-help"><?php asde_e( 'Cámbialo solo si las eñes o los acentos salen mal en la vista previa.' ); ?></span>
				</p>
			</div>
			<p><button type="submit" class="button"><?php asde_e( 'Leer el fichero' ); ?></button></p>
		</form>
	</details>

	<?php if ( $lotes ) : ?>
		<h3><?php asde_e( 'Últimas importaciones' ); ?></h3>
		<table class="asde-table">
			<thead><tr><th scope="col"><?php asde_e( 'Fecha' ); ?></th><th scope="col"><?php asde_e( 'Fichero' ); ?></th><th scope="col"><?php asde_e( 'Cuenta' ); ?></th><th scope="col"><?php asde_e( 'Periodo' ); ?></th><th scope="col"><?php asde_e( 'Movimientos' ); ?></th><th scope="col"><?php asde_e( 'Por' ); ?></th></tr></thead>
			<tbody>
				<?php foreach ( $lotes as $l ) : ?>
					<?php $autor = get_userdata( (int) $l['importado_por'] ); ?>
					<tr>
						<td data-label="<?php echo esc_attr( asde_a( 'Fecha' ) ); ?>"><?php echo esc_html( wp_date( 'd/m/Y H:i', strtotime( $l['creado'] . ' UTC' ) ) ); ?></td>
						<td data-label="<?php echo esc_attr( asde_a( 'Fichero' ) ); ?>"><?php echo esc_html( $l['fichero'] ); ?> <span class="asde-muted">(<?php echo 'n43' === $l['origen'] ? 'Norma 43' : 'CSV'; ?>)</span></td>
						<td data-label="<?php echo esc_attr( asde_a( 'Cuenta' ) ); ?>"><?php echo esc_html( $l['cuentas'] ); ?></td>
						<td data-label="<?php echo esc_attr( asde_a( 'Periodo' ) ); ?>"><?php echo $l['desde'] ? esc_html( wp_date( 'd/m/Y', strtotime( $l['desde'] . ' 12:00' ) ) . ' – ' . wp_date( 'd/m/Y', strtotime( $l['hasta'] . ' 12:00' ) ) ) : '—'; ?></td>
						<td data-label="<?php echo esc_attr( asde_a( 'Movimientos' ) ); ?>"><?php echo (int) $l['nuevos']; ?> <?php asde_e( 'nuevos' ); ?><?php echo (int) $l['duplicados'] ? ' · ' . esc_html( sprintf( asde_t( '%d ya importados' ), (int) $l['duplicados'] ) ) : ''; ?></td>
						<td data-label="<?php echo esc_attr( asde_a( 'Por' ) ); ?>"><?php echo $autor ? esc_html( $autor->user_login ) : '—'; ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</section>

<?php /* v0.20: este listado se actualiza sin recargar la página (assets/js/parcial.js). */ ?>
<section class="asde-card" data-asde-parcial="banco-movs">
	<header class="asde-card__header asde-card__header--split">
		<h3 class="asde-card__title"><?php asde_e( 'Movimientos' ); ?></h3>
		<span class="asde-actions"><a href="<?php echo esc_url( add_query_arg( 'tipos', '1', $url ) ); ?>"><?php asde_e( 'Tipos de gasto e ingreso' ); ?></a></span>
		<?php if ( $sugeridas && BancoTable::PENDIENTE === $filtros['estado'] ) : ?>
			<?php $uno = 1 === (int) $sugeridas; ?>
			<form method="post" data-asde-confirmar="<?php echo esc_attr( $uno ? asde_t( '¿Conciliar el ingreso que lleva la referencia de su familia en el concepto?' ) : sprintf( asde_t( '¿Conciliar los %d ingresos que llevan la referencia de su familia en el concepto?' ), (int) $sugeridas ) ); ?>">
				<?php wp_nonce_field( 'asde_banco_sugeridas' ); ?>
				<input type="hidden" name="asde_action" value="conciliar_sugeridas">
				<button type="submit" class="button"><?php echo esc_html( $uno ? asde_t( 'Conciliar el que lleva la referencia de familia' ) : sprintf( asde_t( 'Conciliar los %d que llevan la referencia de familia' ), (int) $sugeridas ) ); ?></button>
			</form>
		<?php endif; ?>
	</header>

	<?php /* v0.31: primero el cuadro de búsqueda y después los tres desplegables y «Filtrar», en una línea. */ ?>
	<form method="get" action="<?php echo esc_url( $url ); ?>" class="asde-filter asde-filter--una-linea">
		<label for="asde-banco-q" class="screen-reader-text"><?php asde_e( 'Buscar' ); ?></label>
		<input type="search" id="asde-banco-q" name="q" value="<?php echo esc_attr( $filtros['q'] ); ?>" placeholder="<?php echo esc_attr( asde_a( 'Concepto, ordenante o referencia' ) ); ?>">
		<label for="asde-banco-estado" class="screen-reader-text"><?php asde_e( 'Mostrar' ); ?></label>
		<select id="asde-banco-estado" name="estado" data-asde-autoenviar>
			<option value=""><?php asde_e( 'Todos' ); ?></option>
			<?php foreach ( asde_ta( BancoTable::ESTADOS ) as $k => $label ) : ?>
				<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $filtros['estado'], $k ); ?>><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select>
		<select name="tipo" aria-label="<?php echo esc_attr( asde_a( 'Tipo' ) ); ?>" data-asde-autoenviar>
			<option value=""><?php asde_e( 'Ingresos y gastos' ); ?></option>
			<option value="ingresos" <?php selected( $filtros['tipo'], 'ingresos' ); ?>><?php asde_e( 'Solo ingresos' ); ?></option>
			<option value="gastos" <?php selected( $filtros['tipo'], 'gastos' ); ?>><?php asde_e( 'Solo gastos' ); ?></option>
		</select>
		<?php if ( $tipos ) : ?>
			<select name="clase" aria-label="<?php echo esc_attr( asde_a( 'Tipo de gasto o ingreso' ) ); ?>" data-asde-autoenviar>
				<option value=""><?php asde_e( 'Todos los tipos' ); ?></option>
				<?php foreach ( $tipos as $t ) : ?>
					<option value="<?php echo (int) $t['id']; ?>" <?php selected( $filtros['clase'], (string) $t['id'] ); ?>><?php echo esc_html( $t['nombre'] ); ?></option>
				<?php endforeach; ?>
				<option value="sin" <?php selected( $filtros['clase'], 'sin' ); ?>><?php asde_e( 'Revisados sin tipo' ); ?></option>
			</select>
		<?php endif; ?>
		<button type="submit" class="button"><?php asde_e( 'Filtrar' ); ?></button>
	</form>

	<?php if ( ! $movs ) : ?>
		<p><?php echo $filtros['estado'] || $filtros['tipo'] || $filtros['q'] || $filtros['clase'] ? esc_html( asde_t( 'No hay movimientos con este filtro.' ) ) : esc_html( asde_t( 'Todavía no hay movimientos: importa el primer fichero del banco.' ) ); ?></p>
	<?php else : ?>
		<?php $hay_pendientes = (bool) array_filter( $movs, static fn( $m ) => BancoTable::PENDIENTE === $m['estado'] ); ?>
		<?php if ( $hay_pendientes ) : ?>
			<?php /* Selección múltiple: solo para marcar como revisados (con su tipo, en la ventana). Las casillas van con form="…" porque cada fila tiene sus propios formularios. */ ?>
			<form method="get" action="<?php echo esc_url( $url ); ?>" id="asde-banco-lote" class="asde-form__fila asde-banco-lote" data-asde-recarga>
				<input type="hidden" name="revisar" value="1">
				<input type="hidden" name="volver" value="<?php echo esc_url( $aqui ); ?>">
				<button type="submit" class="button"><?php asde_e( 'Marcar como revisados…' ); ?></button>
				<span class="asde-muted" data-asde-banco-marcados><?php asde_e( 'Marca en la lista los movimientos que no son cuotas.' ); ?></span>
			</form>
		<?php endif; ?>
		<table class="asde-table asde-table--banco">
			<thead>
				<tr>
					<?php if ( $hay_pendientes ) : ?>
						<th scope="col" class="asde-banco-marca"><input type="checkbox" data-asde-banco-todos aria-label="<?php echo esc_attr( asde_a( 'Marcar todos los pendientes de esta página' ) ); ?>"></th>
					<?php endif; ?>
					<?php foreach ( $columnas as $col => $etiqueta ) : ?>
						<th scope="col"<?php echo $tabla->aria_sort( $col ); // phpcs:ignore WordPress.Security.EscapeOutput ?>><?php echo $tabla->cabecera( $col, $etiqueta ); // phpcs:ignore WordPress.Security.EscapeOutput ?></th>
					<?php endforeach; ?>
					<th scope="col"><?php asde_e( 'Familia' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $movs as $m ) : ?>
					<?php
					$ingreso = $m['importe'] > 0;
					$sug     = $m['sugerencia'];
					?>
					<tr>
						<?php if ( $hay_pendientes ) : ?>
							<td class="asde-banco-marca" data-label="<?php echo esc_attr( asde_a( 'Elegir' ) ); ?>">
								<?php if ( BancoTable::PENDIENTE === $m['estado'] ) : ?>
									<input type="checkbox" name="m[]" value="<?php echo (int) $m['id']; ?>" form="asde-banco-lote" aria-label="<?php echo esc_attr( sprintf( asde_t( 'Elegir el movimiento del %1$s de %2$s' ), wp_date( 'd/m/Y', strtotime( $m['fecha'] . ' 12:00' ) ), Calculadora::euros( $m['importe'] ) ) ); ?>">
								<?php endif; ?>
							</td>
						<?php endif; ?>
						<td data-label="<?php echo esc_attr( asde_a( 'Fecha' ) ); ?>"><?php echo esc_html( wp_date( 'd/m/Y', strtotime( $m['fecha'] . ' 12:00' ) ) ); ?></td>
						<td data-label="<?php echo esc_attr( asde_a( 'Concepto' ) ); ?>">
							<?php if ( '' !== $m['ordenante'] ) : ?>
								<strong><?php echo esc_html( $m['ordenante'] ); ?></strong><br>
							<?php endif; ?>
							<?php echo esc_html( '' !== $m['concepto'] ? $m['concepto'] : ( asde_t( Norma43::CONCEPTOS[ $m['concepto_comun'] ] ?? '—' ) ) ); ?>
							<?php if ( '' !== $m['referencia1'] . $m['referencia2'] ) : ?>
								<br><span class="asde-muted"><?php asde_e( 'Ref.:' ); ?> <?php echo esc_html( trim( $m['referencia1'] . ' ' . $m['referencia2'] ) ); ?></span>
							<?php endif; ?>
						</td>
						<td data-label="<?php echo esc_attr( asde_a( 'Importe' ) ); ?>" class="asde-importe <?php echo $ingreso ? 'asde-importe--ingreso' : 'asde-importe--gasto'; ?>"><?php echo esc_html( ( $ingreso ? '+' : '' ) . Calculadora::euros( $m['importe'] ) ); ?></td>
						<td data-label="<?php echo esc_attr( asde_a( 'Estado' ) ); ?>">
							<span class="asde-badge asde-badge--banco-<?php echo esc_attr( $m['estado'] ); ?>"><?php echo esc_html( asde_t( BancoTable::ESTADOS[ $m['estado'] ] ?? $m['estado'] ) ); ?></span>
							<?php if ( BancoTable::IGNORADO === $m['estado'] ) : ?>
								<br><strong><?php echo esc_html( isset( $tipos[ (int) $m['tipo_id'] ] ) ? $tipos[ (int) $m['tipo_id'] ]['nombre'] : asde_t( 'Sin tipo' ) ); ?></strong>
								<?php echo '' !== $m['nota'] ? '<br><span class="asde-muted">' . esc_html( $m['nota'] ) . '</span>' : ''; ?>
							<?php endif; ?>
						</td>
						<td data-label="<?php echo esc_attr( asde_a( 'Familia' ) ); ?>">
							<?php if ( BancoTable::CONCILIADO === $m['estado'] ) : ?>
								<?php echo esc_html( $familias['etiquetas'][ (int) $m['familia_id'] ] ?? '—' ); ?>
							<?php elseif ( BancoTable::PENDIENTE === $m['estado'] && $ingreso ) : ?>
								<?php if ( $sug ) : ?>
									<span class="asde-help<?php echo $sug['fiable'] ? '' : ' asde-help--warning'; ?>"><?php asde_e( 'Sugerida:' ); ?> <?php echo esc_html( $sug['motivo'] ); ?></span>
								<?php endif; ?>
								<?php if ( $familias['etiquetas'] ) : ?>
									<form method="post" class="asde-conciliar">
										<?php wp_nonce_field( 'asde_banco_mov_' . (int) $m['id'] ); ?>
										<input type="hidden" name="asde_action" value="conciliar">
										<input type="hidden" name="mov" value="<?php echo (int) $m['id']; ?>">
										<label class="screen-reader-text" for="asde-fam-<?php echo (int) $m['id']; ?>"><?php asde_e( 'Familia' ); ?></label>
										<select id="asde-fam-<?php echo (int) $m['id']; ?>" name="familia" required class="asde-combobox" data-min="3" data-placeholder="<?php echo esc_attr( asde_a( 'Familia: nº o apellido (3 letras)…' ) ); ?>">
											<option value=""><?php asde_e( 'Elige la familia…' ); ?></option>
											<?php foreach ( $familias['etiquetas'] as $fid => $etiqueta ) : ?>
												<option value="<?php echo (int) $fid; ?>" <?php selected( $sug ? (int) $sug['familia']['id'] : 0, (int) $fid ); ?>><?php echo esc_html( $etiqueta ); ?></option>
											<?php endforeach; ?>
										</select>
										<label class="screen-reader-text" for="asde-ronda-<?php echo (int) $m['id']; ?>"><?php asde_e( 'Ronda' ); ?></label>
										<select id="asde-ronda-<?php echo (int) $m['id']; ?>" name="ronda_pago">
											<?php $rd = Config::ronda_de_fecha( $m['fecha'] ); ?>
											<?php foreach ( array_unique( array_merge( array( $rd ), $rondas ) ) as $r ) : ?>
												<option value="<?php echo esc_attr( $r ); ?>" <?php selected( $rd, $r ); ?>><?php echo esc_html( $r ); ?></option>
											<?php endforeach; ?>
										</select>
										<button type="submit" class="button"><?php asde_e( 'Conciliar' ); ?></button>
									</form>
								<?php else : ?>
									<span class="asde-muted"><?php asde_e( 'Crea antes las' ); ?> <a href="<?php echo esc_url( $url_fam ); ?>"><?php asde_e( 'familias' ); ?></a>.</span>
								<?php endif; ?>
							<?php endif; ?>
							<div class="asde-actions">
								<?php if ( BancoTable::PENDIENTE === $m['estado'] ) : ?>
									<a href="<?php echo esc_url( $revisar( array( (int) $m['id'] ) ) ); ?>" data-asde-modal><?php echo $ingreso ? esc_html( asde_t( 'No es una cuota…' ) ) : esc_html( asde_t( 'Marcar revisado…' ) ); ?></a>
								<?php else : ?>
									<?php if ( BancoTable::IGNORADO === $m['estado'] ) : ?>
										<a href="<?php echo esc_url( $revisar( array( (int) $m['id'] ) ) ); ?>" data-asde-modal><?php asde_e( 'Cambiar el tipo' ); ?></a>
									<?php endif; ?>
									<form method="post" data-asde-confirmar="<?php echo esc_attr( asde_t( '¿Deshacer?' ) . ( BancoTable::CONCILIADO === $m['estado'] ? ' ' . asde_t( 'Se anulará el pago que se registró en la familia.' ) : '' ) ); ?>">
										<?php wp_nonce_field( 'asde_banco_mov_' . (int) $m['id'] ); ?>
										<input type="hidden" name="asde_action" value="deshacer">
										<input type="hidden" name="mov" value="<?php echo (int) $m['id']; ?>">
										<button type="submit" class="asde-btn-link"><?php asde_e( 'Deshacer' ); ?></button>
									</form>
								<?php endif; ?>
							</div>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php /* v0.31: el contador, debajo de la lista. */ ?>
		<p class="asde-muted asde-contador"><?php echo esc_html( sprintf( asde_n( '%d movimiento', '%d movimientos', $total ), $total ) ); ?></p>
		<?php echo $tabla->paginacion(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
	<?php endif; ?>
</section>
