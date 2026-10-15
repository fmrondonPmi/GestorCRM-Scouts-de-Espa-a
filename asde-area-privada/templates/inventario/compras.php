<?php
/**
 * Listas de compra de material del grupo (doc 32).
 *
 * @var array[]            $listas
 * @var string             $estado  Filtro ('' = todas)
 * @var array<string, int> $cuenta  estado => nº de listas
 * @var int                $total
 * @var bool               $aprueba Es de Tesorería
 * @var string             $url
 * @var string             $url_inv
 * @var callable           $url_pdf lista => enlace al PDF
 * @var callable           $usuario id => nombre
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Frontend\Views\InventarioComprasView;
use AsdeBalears\AreaPrivada\Inventario\ComprasTable;

defined( 'ABSPATH' ) || exit;

$fecha = static fn( ?string $f ) => $f ? wp_date( 'd/m/Y', (int) strtotime( $f . ' UTC' ) ) : '';
?>
<section class="asde-card">
	<header class="asde-card__header asde-card__header--split">
		<h2 class="asde-card__title"><?php asde_e( 'Listas de compra de material' ); ?></h2>
		<span class="asde-actions asde-actions--botones">
			<a class="button" href="<?php echo esc_url( $url_inv ); ?>"><?php asde_e( 'Preparar una lista desde el inventario' ); ?></a>
			<?php // v0.30 (P-63): una lista solo de material que todavía no está en el inventario. ?>
			<a class="button asde-button-secundario" href="<?php echo esc_url( add_query_arg( 'nueva', '1', $url ) ); ?>"><?php asde_e( 'Lista de material nuevo' ); ?></a>
		</span>
	</header>
	<p class="asde-muted">
		<?php asde_e( 'Se preparan marcando el material en el inventario (y escribiendo el que todavía no está). Tesorería recibe un email y le da el visto bueno; solo entonces se puede descargar el PDF para imprimirla. Quien la pidió recibe un email con la respuesta. Cuando el material esté comprado, se marca la lista como comprada y lo comprado se suma al inventario.' ); ?>
	</p>
	<?php if ( $aprueba && $cuenta[ ComprasTable::PENDIENTE ] ) : ?>
		<div class="asde-notice asde-notice--info" role="status">
			<a href="<?php echo esc_url( add_query_arg( 'estado', ComprasTable::PENDIENTE, $url ) ); ?>"><?php echo esc_html( sprintf( asde_n( '%d lista espera tu visto bueno.', '%d listas esperan tu visto bueno.', $cuenta[ ComprasTable::PENDIENTE ] ), $cuenta[ ComprasTable::PENDIENTE ] ) ); ?></a>
		</div>
	<?php endif; ?>

	<form method="get" action="<?php echo esc_url( $url ); ?>" class="asde-filter" data-asde-parcial-form="inv-compras">
		<label for="asde-inv-estado"><?php asde_e( 'Mostrar' ); ?></label>
		<select id="asde-inv-estado" name="estado" data-asde-autoenviar>
			<option value=""><?php echo esc_html( asde_t( 'Todas' ) . ' (' . $total . ')' ); ?></option>
			<?php foreach ( asde_ta( ComprasTable::ESTADOS ) as $e => $etiqueta ) : ?>
				<option value="<?php echo esc_attr( $e ); ?>" <?php selected( $estado, $e ); ?>><?php echo esc_html( $etiqueta . ' (' . $cuenta[ $e ] . ')' ); ?></option>
			<?php endforeach; ?>
		</select>
		<button type="submit" class="button"><?php asde_e( 'Ver' ); ?></button>
	</form>

	<?php /* v0.31: el filtro actualiza solo la lista (parcial.js). */ ?>
	<div data-asde-parcial="inv-compras">
	<?php if ( ! $listas ) : ?>
		<p><?php asde_e( 'No hay listas de compra con este filtro.' ); ?></p>
	<?php else : ?>
		<table class="asde-table asde-table--inv-compras">
			<thead>
				<tr>
					<th scope="col"><?php asde_e( 'Lista' ); ?></th>
					<th scope="col"><?php asde_e( 'Pedida por' ); ?></th>
					<th scope="col" class="asde-num"><?php asde_e( 'Materiales' ); ?></th>
					<th scope="col" class="asde-num"><?php asde_e( 'Total estimado' ); ?></th>
					<th scope="col"><?php asde_e( 'Estado' ); ?></th>
					<th scope="col"><span class="screen-reader-text"><?php asde_e( 'Acciones' ); ?></span></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $listas as $l ) : ?>
					<?php $detalle = add_query_arg( 'lista', $l['id'], $url ); ?>
					<tr>
						<td data-label="<?php echo esc_attr( asde_a( 'Lista' ) ); ?>"><a href="<?php echo esc_url( $detalle ); ?>" data-asde-modal><?php echo esc_html( ComprasTable::numero( $l ) ); ?></a><br><span class="asde-muted"><?php echo esc_html( $fecha( $l['creado'] ) ); ?></span></td>
						<td data-label="<?php echo esc_attr( asde_a( 'Pedida por' ) ); ?>"><?php echo esc_html( $usuario( $l['creado_por'] ) ?: '—' ); ?></td>
						<td data-label="<?php echo esc_attr( asde_a( 'Materiales' ) ); ?>" class="asde-num"><?php echo (int) count( $l['lineas'] ); ?></td>
						<td data-label="<?php echo esc_attr( asde_a( 'Total estimado' ) ); ?>" class="asde-num"><?php echo esc_html( InventarioComprasView::total( $l ) ); ?></td>
						<td data-label="<?php echo esc_attr( asde_a( 'Estado' ) ); ?>">
							<span class="asde-badge asde-badge--compra-<?php echo esc_attr( $l['estado'] ); ?>"><?php echo esc_html( asde_t( ComprasTable::ESTADOS[ $l['estado'] ] ?? $l['estado'] ) ); ?></span>
							<?php if ( '' !== $l['motivo'] ) : ?>
								<br><span class="asde-muted"><?php echo esc_html( $l['motivo'] ); ?></span>
							<?php endif; ?>
						</td>
						<td data-label="<?php echo esc_attr( asde_a( 'Acciones' ) ); ?>">
							<?php if ( in_array( $l['estado'], array( ComprasTable::APROBADA, ComprasTable::COMPRADA ), true ) ) : ?>
								<a href="<?php echo esc_url( $url_pdf( $l ) ); ?>" target="_blank" rel="noopener"><?php asde_e( 'PDF para imprimir' ); ?></a>
							<?php elseif ( $aprueba && ComprasTable::PENDIENTE === $l['estado'] ) : ?>
								<a href="<?php echo esc_url( $detalle ); ?>" data-asde-modal><?php asde_e( 'Revisar' ); ?></a>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
	</div>
</section>
