<?php
/**
 * Marcar como revisados (no son cuota) uno o varios movimientos del banco, cada
 * uno con su tipo de gasto o de ingreso (v0.20, doc 16). Se abre en la ventana
 * emergente desde el listado; sin JavaScript, en su página.
 *
 * @var array[]           $movs        Los elegidos (pendientes o ya revisados, para cambiarles el tipo)
 * @var array<int, array> $tipos       Tipos del grupo
 * @var array<int, string> $eventos     Eventos a los que se puede asociar (id => nombre; vacío si el módulo está apagado)
 * @var array<int, string> $familias    Familias a las que se puede asociar (id => etiqueta)
 * @var string            $url
 * @var string            $volver      Listado del que se viene
 * @var int               $conciliados Elegidos que no se pueden revisar (conciliados con una familia)
 * @var string            $url_tipos
 * @var string            $url_mas     El «+» de cada fila (v0.34): los tipos, con la vuelta a esta ventana
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Tesoreria\Banco\BancoTable;
use AsdeBalears\AreaPrivada\Tesoreria\Banco\TiposMovimiento;
use AsdeBalears\AreaPrivada\Tesoreria\Calculadora;

defined( 'ABSPATH' ) || exit;

$gastos   = array_filter( $movs, static fn( $m ) => $m['importe'] < 0 );
$ingresos = count( $movs ) - count( $gastos );
$titulo   = 1 === count( $movs ) ? __( 'Mark as reviewed', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) : __( 'Mark as reviewed', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' );
// Grupos del desplegable: Gastos, Ingresos y «Gasto o ingreso».
$opciones = static function ( float $importe, ?int $elegido ) use ( $tipos ): string {
	$h      = '<option value="">' . esc_html( __( 'Choose the type…', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) . '</option>';
	$grupos = array();
	foreach ( TiposMovimiento::para( $importe ) as $t ) {
		$grupos[ $t['naturaleza'] ][] = $t;
	}
	foreach ( $grupos as $nat => $lista ) {
		$h .= '<optgroup label="' . esc_attr( asde_t( TiposMovimiento::NATURALEZAS[ $nat ] ) ) . '" data-naturaleza="' . esc_attr( $nat ) . '">';
		foreach ( $lista as $t ) {
			$h .= '<option value="' . (int) $t['id'] . '"' . selected( $elegido, $t['id'], false ) . '>' . esc_html( $t['nombre'] ) . '</option>';
		}
		$h .= '</optgroup>';
	}
	return $h;
};
?>
<section class="asde-card asde-banco-revisar" data-asde-plegable="banco-revisar">
	<h2 class="asde-card__title"><?php echo esc_html( $titulo ); ?></h2>
	<?php if ( $conciliados ) : ?>
		<div class="asde-notice asde-notice--warning" role="status"><?php echo esc_html( sprintf( /* translators: %d: number */ _n( '%d chosen transaction is already reconciled with a family and does not appear here: to change it, undo the reconciliation first.', '%d chosen transactions are already reconciled with a family and do not appear here: to change them, undo the reconciliation first.', $conciliados , 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $conciliados ) ); ?></div>
	<?php endif; ?>

	<?php if ( ! $movs ) : ?>
		<p><?php esc_html_e( 'There is no transaction to mark. Choose from the list those that are not fees.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
		<p class="asde-solo-pagina"><a href="<?php echo esc_url( $volver ); ?>"><?php esc_html_e( '← Bank', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a></p>
	<?php elseif ( ! $tipos ) : ?>
		<p><?php esc_html_e( 'There are no expense or income types yet.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <a href="<?php echo esc_url( $url_tipos ); ?>"><?php esc_html_e( 'Create them first', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a>.</p>
	<?php else : ?>
		<p class="asde-muted"><?php esc_html_e( 'For transactions that are not families\' fees: expenses (equipment, transport, fees…) or income (donations, grants…). The type is used to know what money is spent on and where it comes from.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
		<form method="post" action="<?php echo esc_url( $url ); ?>" class="asde-form">
			<?php wp_nonce_field( 'asde_banco_revisar' ); ?>
			<input type="hidden" name="asde_action" value="revisar">
			<input type="hidden" name="volver" value="<?php echo esc_url( $volver ); ?>">
			<?php if ( count( $movs ) > 1 ) : ?>
				<p class="asde-form__fila">
					<label for="asde-tipo-todos"><?php esc_html_e( 'The same type for all', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
					<select id="asde-tipo-todos" data-asde-tipo-todos>
						<option value=""><?php esc_html_e( 'Choose…', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></option>
						<?php foreach ( $tipos as $t ) : ?>
							<option value="<?php echo (int) $t['id']; ?>"><?php echo esc_html( $t['nombre'] . ' (' . mb_strtolower( asde_t( TiposMovimiento::NATURALEZAS[ $t['naturaleza'] ] ) ) . ')' ); ?></option>
						<?php endforeach; ?>
					</select>
					<span class="asde-help"><?php esc_html_e( 'It is applied to those it fits (an expense type, only on expenses). Afterwards you can change each one\'s.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span>
				</p>
			<?php endif; ?>
			<table class="asde-table asde-table--banco-revisar">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Date', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Description', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
						<th scope="col" class="asde-num"><?php esc_html_e( 'Amount', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Type', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
						<?php if ( $eventos ) : ?>
							<th scope="col"><?php esc_html_e( 'Event', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
						<?php endif; ?>
						<?php if ( $familias ) : ?>
							<th scope="col"><?php esc_html_e( 'Family', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
						<?php endif; ?>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $movs as $m ) : ?>
						<?php $ingreso = $m['importe'] > 0; ?>
						<tr>
							<td data-label="<?php echo esc_attr( esc_attr__( 'Date', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo esc_html( wp_date( 'd/m/Y', strtotime( $m['fecha'] . ' 12:00' ) ) ); ?></td>
							<td data-label="<?php echo esc_attr( esc_attr__( 'Description', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
								<?php echo '' !== $m['ordenante'] ? '<strong>' . esc_html( $m['ordenante'] ) . '</strong><br>' : ''; ?>
								<?php echo esc_html( '' !== $m['concepto'] ? $m['concepto'] : '—' ); ?>
								<?php echo BancoTable::IGNORADO === $m['estado'] ? '<br><span class="asde-muted">' . esc_html( __( 'Already reviewed: its type is changed.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) . '</span>' : ''; ?>
							</td>
							<td data-label="<?php echo esc_attr( esc_attr__( 'Amount', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>" class="asde-num asde-importe <?php echo $ingreso ? 'asde-importe--ingreso' : 'asde-importe--gasto'; ?>"><?php echo esc_html( ( $ingreso ? '+' : '' ) . Calculadora::euros( $m['importe'] ) ); ?></td>
							<td data-label="<?php echo esc_attr( esc_attr__( 'Type', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
								<label class="screen-reader-text" for="asde-tipo-<?php echo (int) $m['id']; ?>"><?php esc_html_e( 'Type', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
								<span class="asde-banco-tipo">
									<select id="asde-tipo-<?php echo (int) $m['id']; ?>" name="tipo[<?php echo (int) $m['id']; ?>]" required data-asde-tipo-fila data-asde-signo="<?php echo $ingreso ? 'ingreso' : 'gasto'; ?>">
										<?php echo wp_kses( $opciones( $m['importe'], $m['tipo_id'] ), asde_html_permitido() ); ?>
									</select>
									<a class="button asde-button-secundario asde-banco-mas" href="<?php echo esc_url( $url_mas ); ?>" data-asde-banco-tipo-nuevo title="<?php echo esc_attr( esc_attr__( 'Create a new type', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>" aria-label="<?php echo esc_attr( esc_attr__( 'Create a new type', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">+</a>
								</span>
							</td>
							<?php if ( $eventos ) : ?>
								<td data-label="<?php echo esc_attr( esc_attr__( 'Event', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
									<label class="screen-reader-text" for="asde-evento-<?php echo (int) $m['id']; ?>"><?php esc_html_e( 'Event', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
									<select id="asde-evento-<?php echo (int) $m['id']; ?>" name="evento[<?php echo (int) $m['id']; ?>]">
										<option value=""><?php esc_html_e( 'None', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></option>
										<?php foreach ( $eventos as $eid => $nombre ) : ?>
											<option value="<?php echo (int) $eid; ?>" <?php selected( (int) ( $m['evento_id'] ?? 0 ), (int) $eid ); ?>><?php echo esc_html( $nombre ); ?></option>
										<?php endforeach; ?>
									</select>
								</td>
							<?php endif; ?>
							<?php if ( $familias ) : ?>
								<td data-label="<?php echo esc_attr( esc_attr__( 'Family', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
									<label class="screen-reader-text" for="asde-familia-<?php echo (int) $m['id']; ?>"><?php esc_html_e( 'Family', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
									<select id="asde-familia-<?php echo (int) $m['id']; ?>" name="familia[<?php echo (int) $m['id']; ?>]">
										<option value=""><?php esc_html_e( 'None', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></option>
										<?php foreach ( $familias as $fid => $etiqueta ) : ?>
											<option value="<?php echo (int) $fid; ?>" <?php selected( (int) ( $m['familia_id'] ?? 0 ), (int) $fid ); ?>><?php echo esc_html( $etiqueta ); ?></option>
										<?php endforeach; ?>
									</select>
								</td>
							<?php endif; ?>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p>
				<label for="asde-banco-nota"><?php esc_html_e( 'Note (optional)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
				<input type="text" id="asde-banco-nota" name="nota" maxlength="255" value="<?php echo esc_attr( 1 === count( $movs ) ? $movs[0]['nota'] : '' ); ?>" placeholder="<?php echo esc_attr( esc_attr__( 'For example: invoice 2026/031 from the sports shop', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
			</p>
			<p class="asde-form__acciones">
				<button type="submit" class="button button-primary"><?php echo esc_html( $titulo ); ?></button>
				<a class="asde-solo-pagina" href="<?php echo esc_url( $volver ); ?>"><?php esc_html_e( 'Cancel', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a>
			</p>
			<p class="asde-help">
				<?php echo esc_html( sprintf( /* translators: 1: number, 2: number */ __( '%1$d expenses and %2$d income.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), count( $gastos ), $ingresos ) ); ?>
				<a href="<?php echo esc_url( $url_tipos ); ?>" class="asde-solo-pagina"><?php esc_html_e( 'Manage the expense and income types', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a>
			</p>
		</form>
		<?php // v0.34: la ventana pequeña del «+» (banco.js la abre encima; sin JavaScript no se usa y el «+» va a los tipos). ?>
		<template data-asde-banco-tipo-plantilla>
			<form method="post" action="<?php echo esc_url( $url_tipos ); ?>" class="asde-form asde-banco-tipo-nuevo">
				<h3 id="asde-bt-mini-titulo"><?php esc_html_e( 'New type', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h3>
				<?php wp_nonce_field( 'asde_banco_tipos' ); ?>
				<input type="hidden" name="asde_action" value="tipo_crear">
				<p>
					<label for="asde-bt-mini-nombre"><?php esc_html_e( 'Name of the new type', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
					<input type="text" id="asde-bt-mini-nombre" name="nombre" maxlength="80" required>
				</p>
				<p>
					<label for="asde-bt-mini-clase"><?php esc_html_e( 'Class', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
					<select id="asde-bt-mini-clase" name="naturaleza">
						<?php foreach ( TiposMovimiento::NATURALEZAS as $k => $etiqueta ) : ?>
							<option value="<?php echo esc_attr( $k ); ?>"><?php echo esc_html( asde_t( $etiqueta ) ); ?></option>
						<?php endforeach; ?>
					</select>
				</p>
				<p class="asde-notice asde-notice--error" role="alert" data-asde-error hidden></p>
				<p class="asde-form__acciones">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Create type', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
					<button type="button" class="button asde-button-secundario" data-asde-cerrar><?php esc_html_e( 'Cancel', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
				</p>
			</form>
		</template>
	<?php endif; ?>
</section>
