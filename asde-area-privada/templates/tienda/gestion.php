<?php
/**
 * Gestión de pedidos de uniforme.
 *
 * @var array                                              $cfg
 * @var AsdeBalears\AreaPrivada\Auth\Ambito                $ambito
 * @var array[]                                            $lista
 * @var array<string, AsdeBalears\AreaPrivada\Domain\Persona> $personas
 * @var array<string, int>                                 $cuentas  filtro => nº de pedidos
 * @var float                                              $cobrado
 * @var float                                              $pendiente
 * @var string                                             $filtro
 * @var string                                             $seccion
 * @var string                                             $q
 * @var bool                                               $global   Ve todos los pedidos del grupo
 * @var int                                                $n_pedir   Líneas pagadas pendientes de pedir al proveedor (v0.48)
 * @var string                                             $url_proveedor Pestaña «Pedido de camisas»
 * @var string                                             $url
 * @var string                                             $url_csv
 * @var string                                             $volver
 * @var string                                             $url_informe Enlace a «Ventas e informes» ('' si no puede verlos)
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Domain\Secciones;
use AsdeBalears\AreaPrivada\Tesoreria\Calculadora;
use AsdeBalears\AreaPrivada\Tienda\Config;
use AsdeBalears\AreaPrivada\Tienda\PedidosTable;
use AsdeBalears\AreaPrivada\Tienda\Servicio;

defined( 'ABSPATH' ) || exit;

$fecha    = static fn( ?string $f ) => $f ? wp_date( 'd/m/Y', strtotime( $f . ( strlen( $f ) > 10 ? ' UTC' : '' ) ) ) : '';
$lote     = current_user_can( Servicio::CAP_PEDIDO );
$marca    = static fn( bool $si, string $detalle = '' ) => $si ? '<span class="asde-check" title="' . esc_attr( $detalle ) . '">✓</span>' . ( '' !== $detalle ? ' <span class="asde-muted">' . esc_html( $detalle ) . '</span>' : '' ) : '<span class="asde-muted">—</span>';
$entregar = array();
$ref      = static fn( string $r ): string => PedidosTable::REF_INVENTARIO === $r ? asde_t( PedidosTable::REF_INVENTARIO ) : $r;
// Resumen de arriba: «1 pendiente de entregar», «2 pendientes de entregar».
$singular = array(
	PedidosTable::SIN_PAGAR     => __( 'pending payment', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
	PedidosTable::POR_PEDIR     => __( 'pending inclusion in the order', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
	PedidosTable::POR_ENTREGAR  => __( 'pending delivery', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
	PedidosTable::POR_CONFIRMAR => __( 'delivered, unconfirmed', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
);
?>
<section class="asde-card">
	<header class="asde-card__header asde-card__header--split">
		<h2 class="asde-card__title"><?php esc_html_e( 'Shop orders', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h2>
		<span class="asde-actions">
			<?php if ( '' !== $url_informe ) : ?>
				<a href="<?php echo esc_url( $url_informe ); ?>"><?php esc_html_e( 'Sales and reports', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a>
			<?php endif; ?>
			<a class="no-transition" href="<?php echo esc_url( $url_csv ); ?>" data-asde-descarga><?php esc_html_e( 'Download CSV', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a>
		</span>
	</header>
	<p class="asde-muted">
		<?php echo $global ? esc_html( __( 'All the group\'s orders.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) : esc_html( __( 'The orders of the members of your sections, and your own.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>
		<?php esc_html_e( 'Treasury validates the payment, Coordination includes them in the supplier order, the group delivers them and the member or their family confirms that they have received it.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?>
	</p>
	<ul class="asde-resumen">
		<?php foreach ( $singular as $f => $uno ) : ?>
			<li><a href="<?php echo esc_url( add_query_arg( 'estado', $f, $url ) ); ?>"<?php echo $f === $filtro ? ' aria-current="true"' : ''; ?>><strong><?php echo (int) $cuentas[ $f ]; ?></strong> <?php echo esc_html( 1 === (int) $cuentas[ $f ] ? $uno : mb_strtolower( asde_t( PedidosTable::FILTROS[ $f ] ) ) ); ?></a></li>
		<?php endforeach; ?>
		<li><strong><?php echo esc_html( Calculadora::euros( $cobrado ) ); ?></strong> <?php esc_html_e( 'collected ·', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <strong><?php echo esc_html( Calculadora::euros( $pendiente ) ); ?></strong> <?php esc_html_e( 'to collect', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></li>
	</ul>

	<?php /* v0.31: el filtro actualiza solo la lista (parcial.js). */ ?>
	<form method="get" action="<?php echo esc_url( $url ); ?>" class="asde-filter asde-filter--una-linea" data-asde-parcial-form="uniformes-lista">
		<label for="asde-uni-estado"><?php esc_html_e( 'Show', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
		<select id="asde-uni-estado" name="estado" data-asde-autoenviar>
			<?php foreach ( asde_ta( PedidosTable::FILTROS ) as $f => $etiqueta ) : ?>
				<option value="<?php echo esc_attr( $f ); ?>" <?php selected( $filtro, $f ); ?>><?php echo esc_html( $etiqueta . ' (' . $cuentas[ $f ] . ')' ); ?></option>
			<?php endforeach; ?>
		</select>
		<label for="asde-uni-seccion"><?php esc_html_e( 'Section', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
		<select id="asde-uni-seccion" name="seccion" data-asde-autoenviar>
			<option value=""><?php esc_html_e( 'All', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></option>
			<?php foreach ( Secciones::all() as $s => $def ) : ?>
				<option value="<?php echo esc_attr( $s ); ?>" <?php selected( $seccion, $s ); ?>><?php echo esc_html( $def['nombre'] ); ?></option>
			<?php endforeach; ?>
		</select>
		<label for="asde-uni-q" class="screen-reader-text"><?php esc_html_e( 'Search', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
		<input type="search" id="asde-uni-q" name="q" value="<?php echo esc_attr( $q ); ?>" placeholder="<?php echo esc_attr( esc_attr__( 'Name or order no.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
		<button type="submit" class="button"><?php esc_html_e( 'View', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
	</form>

	<div data-asde-parcial="uniformes-lista">
	<?php if ( ! $lista ) : ?>
		<p><?php esc_html_e( 'There are no orders with these filters.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
	<?php else : ?>
		<form method="post" id="asde-uni-lote" data-asde-recarga>
			<?php wp_nonce_field( 'asde_uniformes_lote' ); ?>
			<input type="hidden" name="asde_action" value="uniforme_incluir_lote">
			<input type="hidden" name="volver" value="<?php echo esc_url( $volver ); ?>">
			<table class="asde-table asde-table--uniformes">
				<thead>
					<tr>
						<?php if ( $lote ) : ?>
							<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Include in the order', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span></th>
						<?php endif; ?>
						<th scope="col"><?php esc_html_e( 'Order', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Member', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
						<th scope="col"><?php esc_html_e( 'What', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Amount', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Paid', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
						<th scope="col"><?php esc_html_e( 'In the order', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Delivered', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Received', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $lista as $p ) : ?>
						<?php
						$persona = $personas[ $p['socio'] ] ?? null;
						$activo  = null === $p['cancelado_en'];
						$detalle = add_query_arg( 'pedido', $p['id'], $url );
						if ( $activo && null !== $p['pedido_en'] && null === $p['entregado_en'] && Servicio::puede_entregar( $p, $ambito ) ) {
							$entregar[] = $p['id'];
						}
						?>
						<tr<?php echo $activo ? '' : ' class="asde-fila--cancelada"'; ?>>
							<?php if ( $lote ) : ?>
								<td data-label="<?php echo esc_attr( esc_attr__( 'Include', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
									<?php if ( $activo && null === $p['pedido_en'] && null === $p['entregado_en'] ) : ?>
										<input type="checkbox" name="pedidos[]" value="<?php echo (int) $p['id']; ?>" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: text */ __( 'Include %s in the order', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), PedidosTable::numero( $p ) ) ); ?>" <?php checked( null !== $p['pago_en'] ); ?>>
									<?php endif; ?>
								</td>
							<?php endif; ?>
							<td data-label="<?php echo esc_attr( esc_attr__( 'Order', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><a href="<?php echo esc_url( $detalle ); ?>" data-asde-modal><?php echo esc_html( PedidosTable::numero( $p ) ); ?></a><br><span class="asde-muted"><?php echo esc_html( $fecha( $p['creado'] ) ); ?></span></td>
							<td data-label="<?php echo esc_attr( esc_attr__( 'Member', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
								<?php echo esc_html( Servicio::comprador( $p, $persona ) ); ?><?php echo wp_kses( $persona ? \AsdeBalears\AreaPrivada\Socios\Numeros::compacto( $persona ) : '', asde_html_permitido() ); ?>
								<?php
								foreach ( $p['secciones'] as $s ) {
									echo wp_kses( Secciones::badge( $s ), asde_html_permitido() );
								}
								?>
							</td>
							<td data-label="<?php echo esc_attr( esc_attr__( 'What', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
								<?php echo esc_html( Servicio::descripcion( $p['lineas'] ) ); ?>
								<?php echo '' !== $p['nota'] ? '<br><span class="asde-muted">' . esc_html( $p['nota'] ) . '</span>' : ''; ?>
							</td>
							<td data-label="<?php echo esc_attr( esc_attr__( 'Amount', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo esc_html( null !== $p['pago_importe'] ? Calculadora::euros( $p['pago_importe'] ) : Servicio::importe( $p ) ); ?></td>
							<?php if ( ! $activo ) : ?>
								<td data-label="<?php echo esc_attr( esc_attr__( 'Status', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>" colspan="4"><span class="asde-badge asde-badge--uniforme-cancelado"><?php esc_html_e( 'Cancelled', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span> <span class="asde-muted"><?php echo esc_html( $p['cancelado_motivo'] ); ?></span></td>
							<?php else : ?>
								<td data-label="<?php echo esc_attr( esc_attr__( 'Paid', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo wp_kses( $marca( null !== $p['pago_en'], null !== $p['pago_en'] ? ( esc_html( asde_t( PedidosTable::METODOS[ $p['pago_metodo'] ] ?? '' ) ) ) . ' ' . $fecha( $p['pago_fecha'] ) : '' ), asde_html_permitido() ); ?><?php echo null === $p['pago_en'] && Servicio::puede_pagar( $p ) ? ' <a href="' . esc_url( $detalle ) . '" data-asde-modal>Validar</a>' : ''; ?></td>
								<td data-label="<?php echo esc_attr( esc_attr__( 'In the order', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo wp_kses( $marca( null !== $p['pedido_en'], null !== $p['pedido_en'] ? $ref( $p['pedido_ref'] ) : '' ), asde_html_permitido() ); ?></td>
								<td data-label="<?php echo esc_attr( esc_attr__( 'Delivered', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
									<?php if ( null !== $p['entregado_en'] ) : ?>
										<?php echo wp_kses( $marca( true, $fecha( $p['entregado_en'] ) ), asde_html_permitido() ); ?>
									<?php elseif ( in_array( $p['id'], $entregar, true ) ) : ?>
										<button type="submit" form="asde-uni-entregar-<?php echo (int) $p['id']; ?>" class="asde-btn-link"><?php esc_html_e( 'Mark delivered', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
									<?php else : ?>
										<span class="asde-muted">—</span>
									<?php endif; ?>
								</td>
								<td data-label="<?php echo esc_attr( esc_attr__( 'Received', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo wp_kses( null !== $p['recibido_en'] ? $marca( true, $fecha( $p['recibido_en'] ) ) : ( null !== $p['entregado_en'] ? '<span class="asde-muted">Sin confirmar</span>' : '<span class="asde-muted">—</span>' ), asde_html_permitido() ); ?></td>
							<?php endif; ?>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php if ( $lote ) : ?>
				<p class="asde-form__fila">
					<label><?php esc_html_e( 'Name of the supplier order', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <input type="text" name="referencia_pedido" maxlength="60" value="<?php echo esc_attr( sprintf( /* translators: %s: text */ __( 'Order %s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), wp_date( 'd/m/Y' ) ) ); ?>"></label>
					<button type="submit" class="button"><?php esc_html_e( 'Include the ticked ones in the order', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
				</p>
				<p class="asde-help"><?php esc_html_e( 'Those already paid are ticked.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
			<?php endif; ?>
		</form>
		<?php foreach ( $entregar as $id ) : ?>
			<form method="post" id="asde-uni-entregar-<?php echo (int) $id; ?>" hidden data-asde-recarga>
				<?php wp_nonce_field( 'asde_uniforme_' . $id ); ?>
				<input type="hidden" name="asde_action" value="uniforme_entregar">
				<input type="hidden" name="pedido" value="<?php echo (int) $id; ?>">
				<input type="hidden" name="volver" value="<?php echo esc_url( $volver ); ?>">
			</form>
		<?php endforeach; ?>
	<?php endif; ?>
	</div>
</section>

<?php if ( $global && $n_pedir > 0 ) : ?>
	<?php // v0.48 (doc 48, T4): lo que hay que pedir al proveedor tiene su pestaña. ?>
	<section class="asde-card">
		<p><?php echo esc_html( sprintf( /* translators: %d: number */ _n( 'There is paid equipment pending to order from the supplier (%d line).', 'There is paid equipment pending to order from the supplier (%d lines).', $n_pedir , 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $n_pedir ) ); ?> <a href="<?php echo esc_url( $url_proveedor ); ?>"><?php esc_html_e( 'Go to "Shirt order"', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a></p>
	</section>
<?php endif; ?>
