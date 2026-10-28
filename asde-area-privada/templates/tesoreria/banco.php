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
 * @var array<int, array>                      $asociados Movimientos con los que están asociados los de la página (v0.58)
 * @var string                                 $url_excel Informe de los movimientos del filtro, en Excel (v0.58)
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
	'fecha'    => __( 'Date', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
	'concepto' => __( 'Description', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
	'importe'  => __( 'Amount', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
	'estado'   => __( 'Status', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
);
$aqui    = add_query_arg( array() ); // Este listado, con sus filtros (para volver después de revisar).
$asociados = $asociados ?? array();
$traspasos = $traspasos ?? array();
$asociar   = static fn( int $id ) => add_query_arg(
	array(
		'asociar' => $id,
		'volver'  => rawurlencode( $aqui ),
	),
	$url
);
$revisar = static fn( array $ids ) => add_query_arg(
	array(
		'revisar' => '1',
		'm'       => $ids,
		'volver'  => rawurlencode( $aqui ),
	),
	$url
);
?>
<section class="asde-card" data-asde-inicio="cerrado">
	<h2><?php esc_html_e( 'Bank', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h2>
	<dl class="asde-datos" data-asde-parcial="banco-resumen">
		<div><dt><?php esc_html_e( 'Balance according to the last file', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></dt><dd><strong><?php echo null !== $resumen['saldo'] ? esc_html( Calculadora::euros( $resumen['saldo'] ) ) : '—'; ?></strong><?php echo $resumen['saldo_fecha'] ? ' <span class="asde-muted">a ' . esc_html( wp_date( 'd/m/Y', strtotime( $resumen['saldo_fecha'] . ' 12:00' ) ) ) . '</span>' : ''; ?></dd></div>
		<div><dt><?php esc_html_e( 'Deposits to reconcile', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></dt><dd><strong><?php echo (int) $resumen['pendientes']; ?></strong> · <?php echo esc_html( Calculadora::euros( $resumen['pendientes_importe'] ) ); ?></dd></div>
	</dl>

	<details class="asde-importar" <?php echo $lotes ? '' : 'open'; ?>>
		<summary><strong><?php esc_html_e( 'Import transactions', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></strong> <?php esc_html_e( '(Norma 43 file or CSV from online banking)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></summary>
		<form method="post" enctype="multipart/form-data" class="asde-form" data-asde-recarga>
			<?php wp_nonce_field( 'asde_banco_analizar' ); ?>
			<input type="hidden" name="asde_action" value="analizar">
			<p>
				<label for="asde-fichero"><?php esc_html_e( 'File', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
				<input type="file" id="asde-fichero" name="fichero" accept=".n43,.q43,.aeb,.txt,.dat,.csv" required>
				<span class="asde-help"><?php esc_html_e( 'Download it from online banking (Colonya Directa) in the format', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <strong><?php esc_html_e( 'Norma 43', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></strong> o <strong><?php esc_html_e( 'CSV', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></strong><?php esc_html_e( '. The file is not stored: its transactions are read and it is discarded. If a transaction was already imported, it is ignored.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span>
			</p>
			<div class="asde-grid-2">
				<p>
					<label for="asde-formato"><?php esc_html_e( 'Format', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
					<select id="asde-formato" name="formato">
						<option value="auto"><?php esc_html_e( 'Detect automatically', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></option>
						<option value="n43"><?php esc_html_e( 'Norma 43 (Cuaderno 43)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></option>
						<option value="csv"><?php esc_html_e( 'CSV', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></option>
					</select>
				</p>
				<p>
					<label for="asde-codificacion"><?php esc_html_e( 'Character set', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
					<select id="asde-codificacion" name="codificacion">
						<?php foreach ( ( Codificacion::lista() ) as $k => $label ) : ?>
							<option value="<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<span class="asde-help"><?php esc_html_e( 'Change it only if accented letters or ñ look wrong in the preview.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span>
				</p>
			</div>
			<p><button type="submit" class="button"><?php esc_html_e( 'Read the file', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button></p>
		</form>
	</details>

	<?php if ( $lotes ) : ?>
		<h3><?php esc_html_e( 'Latest imports', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h3>
		<table class="asde-table">
			<thead><tr><th scope="col"><?php esc_html_e( 'Date', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th><th scope="col"><?php esc_html_e( 'File', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th><th scope="col"><?php esc_html_e( 'Account', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th><th scope="col"><?php esc_html_e( 'Period', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th><th scope="col"><?php esc_html_e( 'Transactions', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th><th scope="col"><?php esc_html_e( 'By', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th></tr></thead>
			<tbody>
				<?php foreach ( $lotes as $l ) : ?>
					<?php $autor = get_userdata( (int) $l['importado_por'] ); ?>
					<tr>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Date', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo esc_html( wp_date( 'd/m/Y H:i', strtotime( $l['creado'] . ' UTC' ) ) ); ?></td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'File', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo esc_html( $l['fichero'] ); ?> <span class="asde-muted">(<?php echo 'n43' === $l['origen'] ? 'Norma 43' : 'CSV'; ?>)</span></td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Account', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo esc_html( $l['cuentas'] ); ?></td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Period', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo $l['desde'] ? esc_html( wp_date( 'd/m/Y', strtotime( $l['desde'] . ' 12:00' ) ) . ' – ' . wp_date( 'd/m/Y', strtotime( $l['hasta'] . ' 12:00' ) ) ) : '—'; ?></td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Transactions', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo (int) $l['nuevos']; ?> <?php esc_html_e( 'new', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?><?php echo (int) $l['duplicados'] ? ' · ' . esc_html( sprintf( /* translators: %d: number */ __( '%d already imported', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), (int) $l['duplicados'] ) ) : ''; ?></td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'By', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo $autor ? esc_html( $autor->user_login ) : '—'; ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</section>

<?php /* v0.20: este listado se actualiza sin recargar la página (assets/js/parcial.js). */ ?>
<section class="asde-card" data-asde-parcial="banco-movs" data-asde-inicio="abierto">
	<header class="asde-card__header asde-card__header--split">
		<h3 class="asde-card__title"><?php esc_html_e( 'Transactions', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h3>
		<span class="asde-actions">
			<a href="<?php echo esc_url( add_query_arg( 'tipos', '1', $url ) ); ?>"><?php esc_html_e( 'Expense and income types', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a>
			<?php if ( ! empty( $url_excel ) ) : ?>
				<a class="no-transition" href="<?php echo esc_url( $url_excel ); ?>" data-asde-descarga title="<?php echo esc_attr( esc_attr__( 'The transactions of this filter (with "All", all the records)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php esc_html_e( 'Download Excel', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a>
			<?php endif; ?>
		</span>
		<?php if ( $sugeridas && BancoTable::PENDIENTE === $filtros['estado'] ) : ?>
			<?php $uno = 1 === (int) $sugeridas; ?>
			<form method="post" data-asde-confirmar="<?php echo esc_attr( $uno ? __( 'Reconcile the deposit that carries its family\'s reference in the description?', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) : sprintf( /* translators: %d: number */ __( 'Reconcile the %d deposits that carry their family\'s reference in the description?', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), (int) $sugeridas ) ); ?>">
				<?php wp_nonce_field( 'asde_banco_sugeridas' ); ?>
				<input type="hidden" name="asde_action" value="conciliar_sugeridas">
				<button type="submit" class="button"><?php echo esc_html( $uno ? __( 'Reconcile the one carrying the family reference', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) : sprintf( /* translators: %d: number */ __( 'Reconcile the %d carrying the family reference', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), (int) $sugeridas ) ); ?></button>
			</form>
		<?php endif; ?>
	</header>

	<?php /* v0.31: primero el cuadro de búsqueda y después los tres desplegables y «Filtrar», en una línea. */ ?>
	<form method="get" action="<?php echo esc_url( $url ); ?>" class="asde-filter asde-filter--una-linea">
		<label for="asde-banco-q" class="screen-reader-text"><?php esc_html_e( 'Search', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
		<input type="search" id="asde-banco-q" name="q" value="<?php echo esc_attr( $filtros['q'] ); ?>" placeholder="<?php echo esc_attr( esc_attr__( 'Description, payer or reference', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
		<label for="asde-banco-estado" class="screen-reader-text"><?php esc_html_e( 'Show', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
		<select id="asde-banco-estado" name="estado" data-asde-autoenviar>
			<option value=""><?php esc_html_e( 'All', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></option>
			<?php foreach ( ( BancoTable::estados() ) as $k => $label ) : ?>
				<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $filtros['estado'], $k ); ?>><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select>
		<select name="tipo" aria-label="<?php echo esc_attr( esc_attr__( 'Type', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>" data-asde-autoenviar>
			<option value=""><?php esc_html_e( 'Income and expenses', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></option>
			<option value="ingresos" <?php selected( $filtros['tipo'], 'ingresos' ); ?>><?php esc_html_e( 'Income only', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></option>
			<option value="gastos" <?php selected( $filtros['tipo'], 'gastos' ); ?>><?php esc_html_e( 'Expenses only', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></option>
		</select>
		<?php if ( $tipos ) : ?>
			<select name="clase" aria-label="<?php echo esc_attr( esc_attr__( 'Type of expense or income', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>" data-asde-autoenviar>
				<option value=""><?php esc_html_e( 'All types', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></option>
				<?php foreach ( $tipos as $t ) : ?>
					<option value="<?php echo (int) $t['id']; ?>" <?php selected( $filtros['clase'], (string) $t['id'] ); ?>><?php echo esc_html( $t['nombre'] ); ?></option>
				<?php endforeach; ?>
				<option value="sin" <?php selected( $filtros['clase'], 'sin' ); ?>><?php esc_html_e( 'Reviewed with no type', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></option>
			</select>
		<?php endif; ?>
		<button type="submit" class="button"><?php esc_html_e( 'Filter', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
	</form>

	<?php if ( ! $movs ) : ?>
		<p><?php echo $filtros['estado'] || $filtros['tipo'] || $filtros['q'] || $filtros['clase'] ? esc_html( __( 'There are no transactions with this filter.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) : esc_html( __( 'There are no transactions yet: import the first bank file.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?></p>
	<?php else : ?>
		<?php $hay_pendientes = (bool) array_filter( $movs, static fn( $m ) => BancoTable::PENDIENTE === $m['estado'] ); ?>
		<?php if ( $hay_pendientes ) : ?>
			<?php /* Selección múltiple: solo para marcar como revisados (con su tipo, en la ventana). Las casillas van con form="…" porque cada fila tiene sus propios formularios. */ ?>
			<form method="get" action="<?php echo esc_url( $url ); ?>" id="asde-banco-lote" class="asde-form__fila asde-banco-lote" data-asde-recarga>
				<input type="hidden" name="revisar" value="1">
				<input type="hidden" name="volver" value="<?php echo esc_url( $aqui ); ?>">
				<button type="submit" class="button"><?php esc_html_e( 'Mark as reviewed…', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
				<span class="asde-muted" data-asde-banco-marcados><?php esc_html_e( 'Tick in the list the transactions that are not fees.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span>
			</form>
		<?php endif; ?>
		<table class="asde-table asde-table--banco">
			<thead>
				<tr>
					<?php if ( $hay_pendientes ) : ?>
						<th scope="col" class="asde-banco-marca"><input type="checkbox" data-asde-banco-todos aria-label="<?php echo esc_attr( esc_attr__( 'Tick all the pending ones on this page', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"></th>
					<?php endif; ?>
					<?php foreach ( $columnas as $col => $etiqueta ) : ?>
						<th scope="col"<?php echo wp_kses( $tabla->aria_sort( $col ), asde_html_permitido() ); ?>><?php echo wp_kses( $tabla->cabecera( $col, $etiqueta ), asde_html_permitido() ); ?></th>
					<?php endforeach; ?>
					<th scope="col"><?php esc_html_e( 'Family', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
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
							<td class="asde-banco-marca" data-label="<?php echo esc_attr( esc_attr__( 'Choose', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
								<?php if ( BancoTable::PENDIENTE === $m['estado'] ) : ?>
									<input type="checkbox" name="m[]" value="<?php echo (int) $m['id']; ?>" form="asde-banco-lote" aria-label="<?php echo esc_attr( sprintf( /* translators: 1: text, 2: text */ __( 'Choose the transaction of %1$s for %2$s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), wp_date( 'd/m/Y', strtotime( $m['fecha'] . ' 12:00' ) ), Calculadora::euros( $m['importe'] ) ) ); ?>">
								<?php endif; ?>
							</td>
						<?php endif; ?>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Date', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo esc_html( wp_date( 'd/m/Y', strtotime( $m['fecha'] . ' 12:00' ) ) ); ?></td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Description', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
							<?php if ( '' !== $m['ordenante'] ) : ?>
								<strong><?php echo esc_html( $m['ordenante'] ); ?></strong><br>
							<?php endif; ?>
							<?php echo esc_html( '' !== $m['concepto'] ? $m['concepto'] : ( ( Norma43::conceptos()[ $m['concepto_comun'] ] ?? '—' ) ) ); ?>
							<?php if ( '' !== $m['referencia1'] . $m['referencia2'] ) : ?>
								<br><span class="asde-muted"><?php esc_html_e( 'Ref.:', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <?php echo esc_html( trim( $m['referencia1'] . ' ' . $m['referencia2'] ) ); ?></span>
							<?php endif; ?>
						</td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Amount', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>" class="asde-importe <?php echo $ingreso ? 'asde-importe--ingreso' : 'asde-importe--gasto'; ?>"><?php echo esc_html( ( $ingreso ? '+' : '' ) . Calculadora::euros( $m['importe'] ) ); ?></td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Status', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
							<span class="asde-badge asde-badge--banco-<?php echo esc_attr( $m['estado'] ); ?>"><?php echo esc_html( ( BancoTable::estados()[ $m['estado'] ] ?? $m['estado'] ) ); ?></span>
							<?php if ( BancoTable::IGNORADO === $m['estado'] ) : ?>
								<br><strong><?php echo esc_html( isset( $tipos[ (int) $m['tipo_id'] ] ) ? $tipos[ (int) $m['tipo_id'] ]['nombre'] : __( 'No type', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?></strong>
								<?php echo '' !== $m['nota'] ? '<br><span class="asde-muted">' . esc_html( $m['nota'] ) . '</span>' : ''; ?>
								<?php if ( ! empty( $m['evento_id'] ) && isset( $eventos[ (int) $m['evento_id'] ] ) ) : ?>
									<br><span class="asde-muted"><?php esc_html_e( 'Event:', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <?php echo esc_html( $eventos[ (int) $m['evento_id'] ] ); ?></span>
								<?php endif; ?>
							<?php endif; ?>
							<?php if ( isset( $traspasos[ (int) $m['id'] ] ) ) : ?>
								<?php /* translators: %s: name of the cash box */ ?>
								<br><span class="asde-banco-asociado">↔ <?php echo esc_html( sprintf( __( 'Transfer: %s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), \AsdeBalears\AreaPrivada\Caja\Cajas::nombre( (string) $traspasos[ (int) $m['id'] ]['caja'] ) ) ); ?></span>
							<?php endif; ?>
							<?php if ( ! empty( $m['asociado_id'] ) ) : ?>
								<?php $otro = $asociados[ (int) $m['asociado_id'] ] ?? null; ?>
								<br><span class="asde-banco-asociado">↔ <?php esc_html_e( 'Linked with', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <?php echo $otro ? esc_html( wp_date( 'd/m/Y', strtotime( $otro['fecha'] . ' 12:00' ) ) . ' · ' . ( $otro['importe'] > 0 ? '+' : '' ) . Calculadora::euros( $otro['importe'] ) ) : '—'; ?></span>
							<?php endif; ?>
						</td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Family', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
							<?php if ( BancoTable::CONCILIADO === $m['estado'] || ( BancoTable::IGNORADO === $m['estado'] && ! empty( $m['familia_id'] ) ) ) : ?>
								<?php echo esc_html( $familias['etiquetas'][ (int) $m['familia_id'] ] ?? '—' ); ?>
							<?php elseif ( BancoTable::PENDIENTE === $m['estado'] && $ingreso ) : ?>
								<?php if ( $sug ) : ?>
									<span class="asde-help<?php echo $sug['fiable'] ? '' : ' asde-help--warning'; ?>"><?php esc_html_e( 'Suggested:', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <?php echo esc_html( $sug['motivo'] ); ?></span>
								<?php endif; ?>
								<?php if ( $familias['etiquetas'] ) : ?>
									<form method="post" class="asde-conciliar">
										<?php wp_nonce_field( 'asde_banco_mov_' . (int) $m['id'] ); ?>
										<input type="hidden" name="asde_action" value="conciliar">
										<input type="hidden" name="mov" value="<?php echo (int) $m['id']; ?>">
										<label class="screen-reader-text" for="asde-fam-<?php echo (int) $m['id']; ?>"><?php esc_html_e( 'Family', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
										<select id="asde-fam-<?php echo (int) $m['id']; ?>" name="familia" required class="asde-combobox" data-min="3" data-placeholder="<?php echo esc_attr( esc_attr__( 'Family: no. or surname (3 letters)…', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
											<option value=""><?php esc_html_e( 'Choose the family…', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></option>
											<?php foreach ( $familias['etiquetas'] as $fid => $etiqueta ) : ?>
												<option value="<?php echo (int) $fid; ?>" <?php selected( $sug ? (int) $sug['familia']['id'] : 0, (int) $fid ); ?>><?php echo esc_html( $etiqueta ); ?></option>
											<?php endforeach; ?>
										</select>
										<label class="screen-reader-text" for="asde-ronda-<?php echo (int) $m['id']; ?>"><?php esc_html_e( 'Round', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
										<select id="asde-ronda-<?php echo (int) $m['id']; ?>" name="ronda_pago">
											<?php $rd = Config::ronda_de_fecha( $m['fecha'] ); ?>
											<?php foreach ( array_unique( array_merge( array( $rd ), $rondas ) ) as $r ) : ?>
												<option value="<?php echo esc_attr( $r ); ?>" <?php selected( $rd, $r ); ?>><?php echo esc_html( $r ); ?></option>
											<?php endforeach; ?>
										</select>
										<button type="submit" class="button"><?php esc_html_e( 'Reconcile', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
									</form>
								<?php else : ?>
									<span class="asde-muted"><?php esc_html_e( 'First create the', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <a href="<?php echo esc_url( $url_fam ); ?>"><?php esc_html_e( 'families', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a>.</span>
								<?php endif; ?>
							<?php endif; ?>
							<div class="asde-actions">
								<?php if ( empty( $m['asociado_id'] ) ) : ?>
									<a href="<?php echo esc_url( $asociar( (int) $m['id'] ) ); ?>" data-asde-modal><?php esc_html_e( 'Link…', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a>
								<?php else : ?>
									<form method="post" data-asde-confirmar="<?php echo esc_attr( __( 'Remove the link between these two transactions?', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
										<?php wp_nonce_field( 'asde_banco_mov_' . (int) $m['id'] ); ?>
										<input type="hidden" name="asde_action" value="desasociar">
										<input type="hidden" name="mov" value="<?php echo (int) $m['id']; ?>">
										<button type="submit" class="asde-btn-link"><?php esc_html_e( 'Remove link', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
									</form>
								<?php endif; ?>
								<?php if ( BancoTable::PENDIENTE === $m['estado'] ) : ?>
									<a href="<?php echo esc_url( $revisar( array( (int) $m['id'] ) ) ); ?>" data-asde-modal><?php echo $ingreso ? esc_html( __( 'It is not a fee…', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) : esc_html( __( 'Mark reviewed…', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?></a>
								<?php else : ?>
									<?php if ( BancoTable::IGNORADO === $m['estado'] ) : ?>
										<a href="<?php echo esc_url( $revisar( array( (int) $m['id'] ) ) ); ?>" data-asde-modal><?php esc_html_e( 'Change the type', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a>
									<?php endif; ?>
									<form method="post" data-asde-confirmar="<?php echo esc_attr( __( 'Undo?', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) . ( BancoTable::CONCILIADO === $m['estado'] ? ' ' . __( 'The payment recorded on the family will be cancelled.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) : '' ) ); ?>">
										<?php wp_nonce_field( 'asde_banco_mov_' . (int) $m['id'] ); ?>
										<input type="hidden" name="asde_action" value="deshacer">
										<input type="hidden" name="mov" value="<?php echo (int) $m['id']; ?>">
										<button type="submit" class="asde-btn-link"><?php esc_html_e( 'Undo', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
									</form>
								<?php endif; ?>
							</div>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php /* v0.31: el contador, debajo de la lista. */ ?>
		<p class="asde-muted asde-contador"><?php echo esc_html( sprintf( /* translators: %d: number */ _n( '%d transaction', '%d transactions', $total , 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $total ) ); ?></p>
		<?php echo wp_kses( $tabla->paginacion(), asde_html_permitido() ); ?>
	<?php endif; ?>
</section>
