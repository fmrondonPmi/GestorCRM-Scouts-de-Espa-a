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
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
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
<p class="asde-back asde-solo-pagina"><a href="<?php echo esc_url( $url_list ); ?>"><?php asde_e( '← Listas de compra' ); ?></a></p>
<section class="asde-card asde-inv-compra<?php echo '' === $url_pdf ? ' asde-inv-compra--sin-visto-bueno' : ''; ?>" data-asde-plegable="inventario-compra">
	<header class="asde-card__header asde-card__header--split">
		<h2 class="asde-card__title"><?php echo esc_html( sprintf( asde_t( 'Lista de compra %s' ), $num ) ); ?></h2>
		<span class="asde-badge asde-badge--compra-<?php echo esc_attr( $l['estado'] ); ?>"><?php echo esc_html( asde_t( ComprasTable::ESTADOS[ $l['estado'] ] ?? $l['estado'] ) ); ?></span>
	</header>
	<?php if ( '' === $url_pdf ) : ?>
		<p class="asde-inv-sin-imprimir" hidden><?php asde_e( 'Pendiente del visto bueno de Tesorería: esta lista todavía no se puede imprimir.' ); ?></p>
	<?php endif; ?>
	<ul class="asde-inv-datos">
		<li><?php echo esc_html( sprintf( asde_t( 'Creada el %1$s por %2$s.' ), $fecha( $l['creado'] ), $usuario( $l['creado_por'] ) ?: '—' ) ); ?></li>
		<?php if ( ComprasTable::PENDIENTE === $l['estado'] ) : ?>
			<li><?php echo $l['avisados'] ? esc_html( sprintf( asde_n( 'Se avisó por email a %d persona de Tesorería.', 'Se avisó por email a %d personas de Tesorería.', $l['avisados'] ), $l['avisados'] ) ) : esc_html( asde_t( 'No se pudo avisar a Tesorería por email.' ) ); ?></li>
		<?php elseif ( ComprasTable::APROBADA === $l['estado'] ) : ?>
			<li><?php echo esc_html( sprintf( asde_t( 'Visto bueno de Tesorería: %1$s, el %2$s.' ), $usuario( $l['revisado_por'] ) ?: '—', $fecha( $l['revisado_en'] ) ) ); ?></li>
			<?php if ( $l['impresiones'] ) : ?>
				<li><?php echo esc_html( sprintf( asde_t( 'PDF descargado por primera vez el %s.' ), $fecha( $l['impresa_en'] ) ) ); ?></li>
			<?php endif; ?>
		<?php elseif ( ComprasTable::COMPRADA === $l['estado'] ) : ?>
			<li><?php echo esc_html( sprintf( asde_t( 'Visto bueno de Tesorería: %1$s, el %2$s.' ), $usuario( $l['revisado_por'] ) ?: '—', $fecha( $l['revisado_en'] ) ) ); ?></li>
			<li><?php echo esc_html( sprintf( asde_t( 'Comprada: la marcó %1$s el %2$s. Lo comprado se sumó al inventario.' ), $usuario( $l['comprada_por'] ) ?: '—', $fecha( $l['comprada_en'] ) ) ); ?></li>
		<?php elseif ( ComprasTable::RECHAZADA === $l['estado'] ) : ?>
			<li><?php echo esc_html( sprintf( asde_t( 'Rechazada por %1$s el %2$s. Motivo: %3$s' ), $usuario( $l['revisado_por'] ) ?: '—', $fecha( $l['revisado_en'] ), $l['motivo'] ) ); ?></li>
		<?php else : ?>
			<li><?php echo esc_html( sprintf( asde_t( 'Cancelada por %1$s el %2$s.' ), $usuario( $l['revisado_por'] ) ?: '—', $fecha( $l['revisado_en'] ) ) . ( '' !== $l['motivo'] ? ' ' . sprintf( asde_t( 'Motivo: %s' ), $l['motivo'] ) : '' ) ); ?></li>
		<?php endif; ?>
	</ul>

	<table class="asde-table asde-table--inv-compra">
		<thead>
			<tr>
				<th scope="col"><?php asde_e( 'Material' ); ?></th>
				<th scope="col" class="asde-num"><?php asde_e( 'En inventario' ); ?></th>
				<th scope="col" class="asde-num"><?php asde_e( 'A comprar' ); ?></th>
				<th scope="col" class="asde-num"><?php asde_e( 'Precio' ); ?></th>
				<th scope="col" class="asde-num"><?php asde_e( 'Importe' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $l['lineas'] as $linea ) : ?>
				<tr>
					<td data-label="<?php echo esc_attr( asde_a( 'Material' ) ); ?>">
						<?php echo esc_html( $linea['descripcion'] ); ?>
						<?php
						$extra = array_filter( array( $linea['tipo'], '' !== $linea['num_serie'] ? sprintf( asde_t( 'Nº de serie %s' ), $linea['num_serie'] ) : '' ) );
						echo $extra ? '<br><span class="asde-muted">' . esc_html( implode( ' · ', $extra ) ) . '</span>' : '';
						?>
						<?php if ( 0 === $linea['material'] ) : ?>
							<br><span class="asde-badge asde-badge--inv-nuevo"><?php asde_e( 'Material nuevo' ); ?></span>
						<?php endif; ?>
						<?php if ( $linea['alta'] ) : ?>
							<?php // v0.30: dado de alta en el inventario al marcar la lista como comprada. ?>
							<br><a class="asde-muted" href="<?php echo esc_url( add_query_arg( 'material', (int) $linea['alta'], $url_inv ) ); ?>"><?php asde_e( 'Dado de alta en el inventario' ); ?></a>
						<?php endif; ?>
					</td>
					<td data-label="<?php echo esc_attr( asde_a( 'En inventario' ) ); ?>" class="asde-num"><?php echo 0 === $linea['material'] ? '<span class="asde-muted">—</span>' : (int) $linea['en_inventario']; ?></td>
					<td data-label="<?php echo esc_attr( asde_a( 'A comprar' ) ); ?>" class="asde-num"><strong><?php echo (int) $linea['cantidad']; ?></strong></td>
					<td data-label="<?php echo esc_attr( asde_a( 'Precio' ) ); ?>" class="asde-num"><?php echo null === $linea['precio'] ? '<span class="asde-muted">—</span>' : esc_html( Calculadora::euros( $linea['precio'] ) ); ?></td>
					<td data-label="<?php echo esc_attr( asde_a( 'Importe' ) ); ?>" class="asde-num"><?php echo null === $linea['precio'] ? '<span class="asde-muted">—</span>' : esc_html( Calculadora::euros( $linea['precio'] * $linea['cantidad'] ) ); ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
		<tfoot>
			<tr>
				<th scope="row" colspan="4"><?php asde_e( 'Total estimado' ); ?></th>
				<td class="asde-num" data-label="<?php echo esc_attr( asde_a( 'Total estimado' ) ); ?>"><strong><?php echo esc_html( Calculadora::euros( $l['total'] ) ); ?></strong><?php echo ComprasTable::sin_precio( $l ) ? '<br><span class="asde-muted">' . esc_html( asde_t( 'sin contar el material sin precio' ) ) . '</span>' : ''; ?></td>
			</tr>
		</tfoot>
	</table>
	<?php if ( '' !== trim( $l['nota'] ) ) : ?>
		<p><strong><?php asde_e( 'Nota:' ); ?></strong> <?php echo nl2br( esc_html( $l['nota'] ) ); ?></p>
	<?php endif; ?>

	<?php if ( '' !== $url_pdf ) : ?>
		<p class="asde-form__acciones"><a class="button button-primary" href="<?php echo esc_url( $url_pdf ); ?>" target="_blank" rel="noopener"><?php asde_e( 'Descargar PDF para imprimir' ); ?></a></p>
	<?php elseif ( ComprasTable::PENDIENTE === $l['estado'] ) : ?>
		<p class="asde-help"><?php asde_e( 'El PDF para imprimirla saldrá aquí cuando Tesorería le dé el visto bueno.' ); ?></p>
	<?php endif; ?>
</section>

<?php if ( Servicio::puede_marcar_comprada( $l ) ) : ?>
	<?php // v0.30 (P-66): suma lo comprado al inventario; con confirmación y una sola vez. ?>
	<section class="asde-card" data-asde-no-plegable>
		<h3 class="asde-card__title"><?php asde_e( '¿Ya se ha comprado?' ); ?></h3>
		<p class="asde-muted"><?php asde_e( 'Cuando el material de la lista esté comprado, márcala como comprada: las cantidades se sumarán al inventario y el material nuevo se dará de alta. No se puede deshacer.' ); ?></p>
		<form method="post" action="<?php echo esc_url( $url ); ?>" class="asde-form__fila" data-asde-confirmar="<?php echo esc_attr( asde_js( '¿Marcar la lista %s como comprada? Las cantidades se sumarán al inventario y el material nuevo se dará de alta. No se puede deshacer.', $num ) ); ?>">
			<?php $campos(); ?>
			<input type="hidden" name="asde_action" value="compra_comprada">
			<button type="submit" class="button button-primary"><?php asde_e( 'Marcar como comprada' ); ?></button>
		</form>
	</section>
<?php endif; ?>

<?php if ( Servicio::puede_revisar( $l ) ) : ?>
	<section class="asde-card">
		<h3 class="asde-card__title"><?php asde_e( 'Visto bueno de Tesorería' ); ?></h3>
		<form method="post" action="<?php echo esc_url( $url ); ?>" class="asde-form__fila">
			<?php $campos(); ?>
			<input type="hidden" name="asde_action" value="compra_aprobar">
			<button type="submit" class="button button-primary"><?php asde_e( 'Dar el visto bueno' ); ?></button>
		</form>
		<form method="post" action="<?php echo esc_url( $url ); ?>" class="asde-form__fila">
			<?php $campos(); ?>
			<input type="hidden" name="asde_action" value="compra_rechazar">
			<label for="asde-inv-rechazo" class="screen-reader-text"><?php asde_e( 'Motivo del rechazo' ); ?></label>
			<input type="text" id="asde-inv-rechazo" name="motivo" maxlength="255" required placeholder="<?php echo esc_attr( asde_a( 'Motivo del rechazo (lo verá quien la ha pedido)' ) ); ?>">
			<button type="submit" class="button"><?php asde_e( 'Rechazar' ); ?></button>
		</form>
	</section>
<?php endif; ?>

<?php if ( Servicio::puede_deshacer( $l ) || Servicio::puede_cancelar( $l ) ) : ?>
	<section class="asde-card">
		<?php if ( Servicio::puede_deshacer( $l ) ) : ?>
			<form method="post" action="<?php echo esc_url( $url ); ?>" class="asde-form__fila">
				<?php $campos(); ?>
				<input type="hidden" name="asde_action" value="compra_deshacer">
				<button type="submit" class="asde-btn-link"><?php asde_e( 'Deshacer el visto bueno' ); ?></button>
				<span class="asde-muted"><?php asde_e( '(solo mientras nadie haya descargado el PDF)' ); ?></span>
			</form>
		<?php endif; ?>
		<?php if ( Servicio::puede_cancelar( $l ) ) : ?>
			<form method="post" action="<?php echo esc_url( $url ); ?>" class="asde-form__fila">
				<?php $campos(); ?>
				<input type="hidden" name="asde_action" value="compra_cancelar">
				<label for="asde-inv-cancelar" class="screen-reader-text"><?php asde_e( 'Motivo' ); ?></label>
				<input type="text" id="asde-inv-cancelar" name="motivo" maxlength="255" placeholder="<?php echo esc_attr( asde_a( 'Motivo (opcional)' ) ); ?>">
				<button type="submit" class="button"><?php asde_e( 'Cancelar la lista' ); ?></button>
			</form>
		<?php endif; ?>
	</section>
<?php endif; ?>
