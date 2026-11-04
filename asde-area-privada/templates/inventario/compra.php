<?php
/**
 * Detalle de una lista de compra (también en la ventana emergente): el material,
 * el visto bueno de Tesorería y el PDF para imprimir (solo con ese visto bueno).
 *
 * @var array    $l
 * @var callable $usuario  id => nombre
 * @var string   $url
 * @var string   $url_list
 * @var string   $url_pdf  '' si todavía no se puede imprimir
 * @var string   $url_inv  El inventario (v0.30: enlace al material dado de alta al comprarla)
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Inventario\ComprasTable;
use AsdeBalears\AreaPrivada\Inventario\Servicio;
use AsdeBalears\AreaPrivada\Tesoreria\Calculadora;

defined( 'ABSPATH' ) || exit;

$fecha = static fn( ?string $f ) => $f ? wp_date( 'd/m/Y H:i', (int) strtotime( $f . ' UTC' ) ) : '';
$num   = ComprasTable::numero( $l );
$nonce = 'asde_inventario_compra_' . $l['id'];
$campos = static function () use ( $nonce, $l ): void {
	wp_nonce_field( $nonce );
	echo '<input type="hidden" name="lista" value="' . (int) $l['id'] . '">';
};
?>
<p class="asde-back asde-solo-pagina"><a href="<?php echo esc_url( $url_list ); ?>"><?php esc_html_e( '← Shopping lists', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a></p>
<section class="asde-card asde-inv-compra<?php echo '' === $url_pdf ? ' asde-inv-compra--sin-visto-bueno' : ''; ?>" data-asde-plegable="inventario-compra">
	<header class="asde-card__header asde-card__header--split">
		<h2 class="asde-card__title"><?php echo esc_html( sprintf( /* translators: %s: text */ __( 'Shopping list %s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $num ) ); ?></h2>
		<span class="asde-badge asde-badge--compra-<?php echo esc_attr( $l['estado'] ); ?>"><?php echo esc_html( ( ComprasTable::estados()[ $l['estado'] ] ?? $l['estado'] ) ); ?></span>
	</header>
	<?php if ( '' === $url_pdf ) : ?>
		<p class="asde-inv-sin-imprimir" hidden><?php esc_html_e( 'Pending Treasury\'s approval: this list cannot be printed yet.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
	<?php endif; ?>
	<ul class="asde-inv-datos">
		<li><?php echo esc_html( sprintf( /* translators: 1: text, 2: text */ __( 'Created on %1$s by %2$s.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $fecha( $l['creado'] ), $usuario( $l['creado_por'] ) ?: '—' ) ); ?></li>
		<?php if ( ComprasTable::PENDIENTE === $l['estado'] ) : ?>
			<li><?php echo $l['avisados'] ? esc_html( sprintf( /* translators: %d: number */ _n( '%d person in Treasury was notified by email.', '%d people in Treasury were notified by email.', $l['avisados'] , 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $l['avisados'] ) ) : esc_html( __( 'Treasury could not be notified by email.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?></li>
		<?php elseif ( ComprasTable::APROBADA === $l['estado'] ) : ?>
			<li><?php echo esc_html( sprintf( /* translators: 1: text, 2: text */ __( 'Treasury approval: %1$s, on %2$s.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $usuario( $l['revisado_por'] ) ?: '—', $fecha( $l['revisado_en'] ) ) ); ?></li>
			<?php if ( $l['impresiones'] ) : ?>
				<li><?php echo esc_html( sprintf( /* translators: %s: text */ __( 'PDF downloaded for the first time on %s.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $fecha( $l['impresa_en'] ) ) ); ?></li>
			<?php endif; ?>
		<?php elseif ( ComprasTable::COMPRADA === $l['estado'] ) : ?>
			<li><?php echo esc_html( sprintf( /* translators: 1: text, 2: text */ __( 'Treasury approval: %1$s, on %2$s.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $usuario( $l['revisado_por'] ) ?: '—', $fecha( $l['revisado_en'] ) ) ); ?></li>
			<li><?php echo esc_html( sprintf( /* translators: 1: text, 2: text */ __( 'Bought: marked by %1$s on %2$s. What was bought was added to the inventory.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $usuario( $l['comprada_por'] ) ?: '—', $fecha( $l['comprada_en'] ) ) ); ?></li>
		<?php elseif ( ComprasTable::RECHAZADA === $l['estado'] ) : ?>
			<li><?php echo esc_html( sprintf( /* translators: 1: text, 2: text, 3: text */ __( 'Rejected by %1$s on %2$s. Reason: %3$s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $usuario( $l['revisado_por'] ) ?: '—', $fecha( $l['revisado_en'] ), $l['motivo'] ) ); ?></li>
		<?php else : ?>
			<li><?php echo esc_html( sprintf( /* translators: 1: text, 2: text */ __( 'Cancelled by %1$s on %2$s.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $usuario( $l['revisado_por'] ) ?: '—', $fecha( $l['revisado_en'] ) ) . ( '' !== $l['motivo'] ? ' ' . sprintf( /* translators: %s: text */ __( 'Reason: %s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $l['motivo'] ) : '' ) ); ?></li>
		<?php endif; ?>
	</ul>

	<table class="asde-table asde-table--inv-compra" data-asde-paginar>
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Equipment', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
				<th scope="col" class="asde-num"><?php esc_html_e( 'In inventory', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
				<th scope="col" class="asde-num"><?php esc_html_e( 'To buy', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
				<th scope="col" class="asde-num"><?php esc_html_e( 'Price', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
				<th scope="col" class="asde-num"><?php esc_html_e( 'Amount', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $l['lineas'] as $linea ) : ?>
				<tr>
					<td data-label="<?php echo esc_attr( esc_attr__( 'Equipment', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
						<?php echo esc_html( $linea['descripcion'] ); ?>
						<?php
						$extra = array_filter( array( $linea['tipo'], '' !== $linea['num_serie'] ? sprintf( /* translators: %s: text */ __( 'Serial no. %s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $linea['num_serie'] ) : '' ) );
						echo $extra ? '<br><span class="asde-muted">' . esc_html( implode( ' · ', $extra ) ) . '</span>' : '';
						?>
						<?php if ( 0 === $linea['material'] ) : ?>
							<br><span class="asde-badge asde-badge--inv-nuevo"><?php esc_html_e( 'New equipment', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span>
						<?php endif; ?>
						<?php if ( $linea['alta'] ) : ?>
							<?php // v0.30: dado de alta en el inventario al marcar la lista como comprada. ?>
							<br><a class="asde-muted" href="<?php echo esc_url( add_query_arg( 'material', (int) $linea['alta'], $url_inv ) ); ?>"><?php esc_html_e( 'Registered in the inventory', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a>
						<?php endif; ?>
					</td>
					<td data-label="<?php echo esc_attr( esc_attr__( 'In inventory', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>" class="asde-num"><?php echo 0 === $linea['material'] ? '<span class="asde-muted">—</span>' : (int) $linea['en_inventario']; ?></td>
					<td data-label="<?php echo esc_attr( esc_attr__( 'To buy', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>" class="asde-num"><strong><?php echo (int) $linea['cantidad']; ?></strong></td>
					<td data-label="<?php echo esc_attr( esc_attr__( 'Price', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>" class="asde-num"><?php echo null === $linea['precio'] ? '<span class="asde-muted">—</span>' : esc_html( Calculadora::euros( $linea['precio'] ) ); ?></td>
					<td data-label="<?php echo esc_attr( esc_attr__( 'Amount', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>" class="asde-num"><?php echo null === $linea['precio'] ? '<span class="asde-muted">—</span>' : esc_html( Calculadora::euros( $linea['precio'] * $linea['cantidad'] ) ); ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
		<tfoot>
			<tr>
				<th scope="row" colspan="4"><?php esc_html_e( 'Estimated total', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
				<td class="asde-num" data-label="<?php echo esc_attr( esc_attr__( 'Estimated total', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><strong><?php echo esc_html( Calculadora::euros( $l['total'] ) ); ?></strong><?php echo ComprasTable::sin_precio( $l ) ? '<br><span class="asde-muted">' . esc_html( __( 'not counting equipment with no price', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) . '</span>' : ''; ?></td>
			</tr>
		</tfoot>
	</table>
	<?php if ( '' !== trim( $l['nota'] ) ) : ?>
		<p><strong><?php esc_html_e( 'Note:', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></strong> <?php echo nl2br( esc_html( $l['nota'] ) ); ?></p>
	<?php endif; ?>

	<?php if ( '' !== $url_pdf ) : ?>
		<p class="asde-form__acciones"><a class="button button-primary" href="<?php echo esc_url( $url_pdf ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Download PDF to print', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a></p>
	<?php elseif ( ComprasTable::PENDIENTE === $l['estado'] ) : ?>
		<p class="asde-help"><?php esc_html_e( 'The PDF to print it will appear here when Treasury approves it.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
	<?php endif; ?>
</section>

<?php if ( Servicio::puede_marcar_comprada( $l ) ) : ?>
	<?php // v0.30 (P-66): suma lo comprado al inventario; con confirmación y una sola vez. ?>
	<section class="asde-card" data-asde-no-plegable>
		<h3 class="asde-card__title"><?php esc_html_e( 'Has it been bought yet?', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h3>
		<p class="asde-muted"><?php esc_html_e( 'When the equipment on the list has been bought, mark it as bought: the quantities will be added to the inventory and new equipment will be registered. This cannot be undone.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
		<form method="post" action="<?php echo esc_url( $url ); ?>" class="asde-form__fila" data-asde-confirmar="<?php echo esc_attr( sprintf( /* translators: %s: text */ __( 'Mark list %s as bought? The quantities will be added to the inventory and new equipment will be registered. This cannot be undone.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $num ) ); ?>">
			<?php $campos(); ?>
			<input type="hidden" name="asde_action" value="compra_comprada">
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Mark as bought', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
		</form>
	</section>
<?php endif; ?>

<?php if ( Servicio::puede_revisar( $l ) ) : ?>
	<section class="asde-card">
		<h3 class="asde-card__title"><?php esc_html_e( 'Treasury approval', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h3>
		<form method="post" action="<?php echo esc_url( $url ); ?>" class="asde-form__fila">
			<?php $campos(); ?>
			<input type="hidden" name="asde_action" value="compra_aprobar">
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Give approval', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
		</form>
		<form method="post" action="<?php echo esc_url( $url ); ?>" class="asde-form__fila">
			<?php $campos(); ?>
			<input type="hidden" name="asde_action" value="compra_rechazar">
			<label for="asde-inv-rechazo" class="screen-reader-text"><?php esc_html_e( 'Reason for the rejection', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
			<input type="text" id="asde-inv-rechazo" name="motivo" maxlength="255" required placeholder="<?php echo esc_attr( esc_attr__( 'Reason for the rejection (whoever requested it will see it)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
			<button type="submit" class="button"><?php esc_html_e( 'Reject', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
		</form>
	</section>
<?php endif; ?>

<?php if ( Servicio::puede_deshacer( $l ) || Servicio::puede_cancelar( $l ) ) : ?>
	<section class="asde-card">
		<?php if ( Servicio::puede_deshacer( $l ) ) : ?>
			<form method="post" action="<?php echo esc_url( $url ); ?>" class="asde-form__fila">
				<?php $campos(); ?>
				<input type="hidden" name="asde_action" value="compra_deshacer">
				<button type="submit" class="asde-btn-link"><?php esc_html_e( 'Undo the approval', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
				<span class="asde-muted"><?php esc_html_e( '(only while nobody has downloaded the PDF)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span>
			</form>
		<?php endif; ?>
		<?php if ( Servicio::puede_cancelar( $l ) ) : ?>
			<form method="post" action="<?php echo esc_url( $url ); ?>" class="asde-form__fila">
				<?php $campos(); ?>
				<input type="hidden" name="asde_action" value="compra_cancelar">
				<label for="asde-inv-cancelar" class="screen-reader-text"><?php esc_html_e( 'Reason', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
				<input type="text" id="asde-inv-cancelar" name="motivo" maxlength="255" placeholder="<?php echo esc_attr( esc_attr__( 'Reason (optional)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
				<button type="submit" class="button"><?php esc_html_e( 'Cancel the list', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
			</form>
		<?php endif; ?>
	</section>
<?php endif; ?>
