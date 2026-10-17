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
 * @var array                                              $resumen  Tallas e insignias de los pagados pendientes de pedir
 * @var int                                                $sin_pagar Pendientes de pedir sin pagar
 * @var string                                             $url
 * @var string                                             $url_csv
 * @var string                                             $volver
 * @var array<string, array<string, int>>|null             $stock       Camisas del inventario (v0.29); null si no las gestiona
 * @var string                                             $url_asignar
 * @var string                                             $url_inv
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
// v0.29: ¿queda en el inventario una camisa como la de este pedido?
$en_stock = static fn( array $p ): bool => null !== $stock && Servicio::se_puede_servir( $p ) && ! empty( $stock[ $p['lineas']['camisa']['modelo'] ?? '' ][ $p['lineas']['camisa']['talla'] ?? '' ] );
$ref      = static fn( string $r ): string => PedidosTable::REF_INVENTARIO === $r ? asde_t( PedidosTable::REF_INVENTARIO ) : $r;
// Resumen de arriba: «1 pendiente de entregar», «2 pendientes de entregar».
$singular = array(
	PedidosTable::SIN_PAGAR     => asde_t( 'pendiente de pago' ),
	PedidosTable::POR_PEDIR     => asde_t( 'pendiente de incluir en el pedido' ),
	PedidosTable::POR_ENTREGAR  => asde_t( 'pendiente de entregar' ),
	PedidosTable::POR_CONFIRMAR => asde_t( 'entregado sin confirmar' ),
);
?>
<section class="asde-card">
	<header class="asde-card__header asde-card__header--split">
		<h2 class="asde-card__title"><?php asde_e( 'Pedidos de uniforme' ); ?></h2>
		<span class="asde-actions"><a class="no-transition" href="<?php echo esc_url( $url_csv ); ?>" data-asde-descarga><?php asde_e( 'Descargar CSV' ); ?></a></span>
	</header>
	<p class="asde-muted">
		<?php echo $global ? esc_html( asde_t( 'Todos los pedidos del grupo.' ) ) : esc_html( asde_t( 'Los pedidos de los socios de tus secciones, y los tuyos.' ) ); ?>
		<?php asde_e( 'Tesorería valida el pago, Coordinación los incluye en el pedido al proveedor, el grupo los entrega y el socio/a o su familia confirma que lo ha recibido.' ); ?>
	</p>
	<ul class="asde-resumen">
		<?php foreach ( $singular as $f => $uno ) : ?>
			<li><a href="<?php echo esc_url( add_query_arg( 'estado', $f, $url ) ); ?>"<?php echo $f === $filtro ? ' aria-current="true"' : ''; ?>><strong><?php echo (int) $cuentas[ $f ]; ?></strong> <?php echo esc_html( 1 === (int) $cuentas[ $f ] ? $uno : mb_strtolower( asde_t( PedidosTable::FILTROS[ $f ] ) ) ); ?></a></li>
		<?php endforeach; ?>
		<li><strong><?php echo esc_html( Calculadora::euros( $cobrado ) ); ?></strong> <?php asde_e( 'cobrados ·' ); ?> <strong><?php echo esc_html( Calculadora::euros( $pendiente ) ); ?></strong> <?php asde_e( 'por cobrar' ); ?></li>
	</ul>

	<?php /* v0.31: el filtro actualiza solo la lista (parcial.js). */ ?>
	<form method="get" action="<?php echo esc_url( $url ); ?>" class="asde-filter" data-asde-parcial-form="uniformes-lista">
		<label for="asde-uni-estado"><?php asde_e( 'Mostrar' ); ?></label>
		<select id="asde-uni-estado" name="estado" data-asde-autoenviar>
			<?php foreach ( asde_ta( PedidosTable::FILTROS ) as $f => $etiqueta ) : ?>
				<option value="<?php echo esc_attr( $f ); ?>" <?php selected( $filtro, $f ); ?>><?php echo esc_html( $etiqueta . ' (' . $cuentas[ $f ] . ')' ); ?></option>
			<?php endforeach; ?>
		</select>
		<label for="asde-uni-seccion"><?php asde_e( 'Sección' ); ?></label>
		<select id="asde-uni-seccion" name="seccion" data-asde-autoenviar>
			<option value=""><?php asde_e( 'Todas' ); ?></option>
			<?php foreach ( Secciones::all() as $s => $def ) : ?>
				<option value="<?php echo esc_attr( $s ); ?>" <?php selected( $seccion, $s ); ?>><?php echo esc_html( $def['nombre'] ); ?></option>
			<?php endforeach; ?>
		</select>
		<label for="asde-uni-q" class="screen-reader-text"><?php asde_e( 'Buscar' ); ?></label>
		<input type="search" id="asde-uni-q" name="q" value="<?php echo esc_attr( $q ); ?>" placeholder="<?php echo esc_attr( asde_a( 'Nombre o nº de pedido' ) ); ?>">
		<button type="submit" class="button"><?php asde_e( 'Ver' ); ?></button>
	</form>

	<div data-asde-parcial="uniformes-lista">
	<?php if ( ! $lista ) : ?>
		<p><?php asde_e( 'No hay pedidos con estos filtros.' ); ?></p>
	<?php else : ?>
		<form method="post" id="asde-uni-lote" data-asde-recarga>
			<?php wp_nonce_field( 'asde_uniformes_lote' ); ?>
			<input type="hidden" name="asde_action" value="uniforme_incluir_lote">
			<input type="hidden" name="volver" value="<?php echo esc_url( $volver ); ?>">
			<table class="asde-table asde-table--uniformes">
				<thead>
					<tr>
						<?php if ( $lote ) : ?>
							<th scope="col"><span class="screen-reader-text"><?php asde_e( 'Incluir en el pedido' ); ?></span></th>
						<?php endif; ?>
						<th scope="col"><?php asde_e( 'Pedido' ); ?></th>
						<th scope="col"><?php asde_e( 'Socio/a' ); ?></th>
						<th scope="col"><?php asde_e( 'Qué' ); ?></th>
						<th scope="col"><?php asde_e( 'Importe' ); ?></th>
						<th scope="col"><?php asde_e( 'Pagado' ); ?></th>
						<th scope="col"><?php asde_e( 'En el pedido' ); ?></th>
						<th scope="col"><?php asde_e( 'Entregado' ); ?></th>
						<th scope="col"><?php asde_e( 'Recibido' ); ?></th>
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
								<td data-label="<?php echo esc_attr( asde_a( 'Incluir' ) ); ?>">
									<?php if ( $activo && null === $p['pedido_en'] && null === $p['entregado_en'] ) : ?>
										<input type="checkbox" name="pedidos[]" value="<?php echo (int) $p['id']; ?>" aria-label="<?php echo esc_attr( sprintf( asde_t( 'Incluir %s en el pedido' ), PedidosTable::numero( $p ) ) ); ?>" <?php checked( null !== $p['pago_en'] ); ?>>
									<?php endif; ?>
								</td>
							<?php endif; ?>
							<td data-label="<?php echo esc_attr( asde_a( 'Pedido' ) ); ?>"><a href="<?php echo esc_url( $detalle ); ?>" data-asde-modal><?php echo esc_html( PedidosTable::numero( $p ) ); ?></a><br><span class="asde-muted"><?php echo esc_html( $fecha( $p['creado'] ) ); ?></span></td>
							<td data-label="<?php echo esc_attr( asde_a( 'Socio/a' ) ); ?>">
								<?php echo esc_html( $persona ? $persona->nombre_completo() : 'Nº ' . $p['socio'] ); ?><?php echo $persona ? \AsdeBalears\AreaPrivada\Socios\Numeros::compacto( $persona ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput ?>
								<?php
								foreach ( $p['secciones'] as $s ) {
									echo Secciones::badge( $s ); // phpcs:ignore WordPress.Security.EscapeOutput
								}
								?>
							</td>
							<td data-label="<?php echo esc_attr( asde_a( 'Qué' ) ); ?>">
								<?php echo esc_html( Servicio::descripcion( $p['lineas'] ) ); ?>
								<?php if ( $p['inventario_id'] ) : ?>
									<br><span class="asde-badge asde-badge--inv-camisa"><?php asde_e( 'Camisa del inventario' ); ?></span>
								<?php elseif ( $en_stock( $p ) ) : ?>
									<br><a class="asde-badge asde-badge--inv-hay" href="<?php echo esc_url( $detalle ); ?>" data-asde-modal title="<?php echo esc_attr( asde_a( 'Ábrelo y pulsa «Servir del inventario»' ) ); ?>"><?php asde_e( 'Hay en el inventario' ); ?></a>
								<?php endif; ?>
								<?php echo '' !== $p['nota'] ? '<br><span class="asde-muted">' . esc_html( $p['nota'] ) . '</span>' : ''; ?>
							</td>
							<td data-label="<?php echo esc_attr( asde_a( 'Importe' ) ); ?>"><?php echo esc_html( null !== $p['pago_importe'] ? Calculadora::euros( $p['pago_importe'] ) : Servicio::importe( $p ) ); ?></td>
							<?php if ( ! $activo ) : ?>
								<td data-label="<?php echo esc_attr( asde_a( 'Estado' ) ); ?>" colspan="4"><span class="asde-badge asde-badge--uniforme-cancelado"><?php asde_e( 'Cancelado' ); ?></span> <span class="asde-muted"><?php echo esc_html( $p['cancelado_motivo'] ); ?></span></td>
							<?php else : ?>
								<td data-label="<?php echo esc_attr( asde_a( 'Pagado' ) ); ?>"><?php echo $marca( null !== $p['pago_en'], null !== $p['pago_en'] ? ( esc_html( asde_t( PedidosTable::METODOS[ $p['pago_metodo'] ] ?? '' ) ) ) . ' ' . $fecha( $p['pago_fecha'] ) : '' ); // phpcs:ignore WordPress.Security.EscapeOutput ?><?php echo null === $p['pago_en'] && Servicio::puede_pagar( $p ) ? ' <a href="' . esc_url( $detalle ) . '" data-asde-modal>Validar</a>' : ''; ?></td>
								<td data-label="<?php echo esc_attr( asde_a( 'En el pedido' ) ); ?>"><?php echo $marca( null !== $p['pedido_en'], null !== $p['pedido_en'] ? $ref( $p['pedido_ref'] ) : '' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
								<td data-label="<?php echo esc_attr( asde_a( 'Entregado' ) ); ?>">
									<?php if ( null !== $p['entregado_en'] ) : ?>
										<?php echo $marca( true, $fecha( $p['entregado_en'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
									<?php elseif ( in_array( $p['id'], $entregar, true ) ) : ?>
										<button type="submit" form="asde-uni-entregar-<?php echo (int) $p['id']; ?>" class="asde-btn-link"><?php asde_e( 'Marcar entregado' ); ?></button>
									<?php else : ?>
										<span class="asde-muted">—</span>
									<?php endif; ?>
								</td>
								<td data-label="<?php echo esc_attr( asde_a( 'Recibido' ) ); ?>"><?php echo null !== $p['recibido_en'] ? $marca( true, $fecha( $p['recibido_en'] ) ) : ( null !== $p['entregado_en'] ? '<span class="asde-muted">Sin confirmar</span>' : '<span class="asde-muted">—</span>' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
							<?php endif; ?>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php if ( $lote ) : ?>
				<p class="asde-form__fila">
					<label><?php asde_e( 'Nombre del pedido al proveedor' ); ?> <input type="text" name="referencia_pedido" maxlength="60" value="<?php echo esc_attr( sprintf( asde_t( 'Pedido %s' ), wp_date( 'd/m/Y' ) ) ); ?>"></label>
					<button type="submit" class="button"><?php asde_e( 'Incluir los marcados en el pedido' ); ?></button>
				</p>
				<p class="asde-help"><?php asde_e( 'Vienen marcados los que ya están pagados.' ); ?></p>
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

<?php if ( null !== $stock ) : ?>
	<?php /* v0.29: las camisas que el grupo ya tiene (inventario, tipo «Camisa uniforme»). */ ?>
	<section class="asde-card" data-asde-plegable="uniformes-inventario">
		<header class="asde-card__header asde-card__header--split">
			<h3 class="asde-card__title"><?php asde_e( 'Camisas del inventario' ); ?></h3>
			<span class="asde-actions">
				<a class="button" href="<?php echo esc_url( $url_asignar ); ?>" data-asde-modal><?php asde_e( 'Asignar una camisa' ); ?></a>
				<a href="<?php echo esc_url( $url_inv ); ?>"><?php asde_e( 'Ver en el inventario' ); ?></a>
			</span>
		</header>
		<p class="asde-muted"><?php asde_e( 'Las camisas que el grupo tiene guardadas. Un pedido con camisa que aún no está en el pedido al proveedor se puede servir de aquí («Hay en el inventario»: ábrelo y pulsa «Servir del inventario»). Para dar una camisa a quien no la ha pedido, «Asignar una camisa».' ); ?></p>
		<?php if ( ! $stock ) : ?>
			<p><?php asde_e( 'No hay camisas en el inventario. Añádelas en el inventario con el tipo «Camisa uniforme», su modelo y su talla.' ); ?></p>
		<?php else : ?>
			<?php foreach ( $stock as $modelo => $tallas ) : ?>
				<?php $orden = array_values( array_intersect( (array) ( $cfg['modelos'][ $modelo ]['tallas'] ?? array() ), array_keys( $tallas ) ) ) ?: array_keys( $tallas ); ?>
				<p><strong><?php echo esc_html( Config::nombre_modelo( $cfg, (string) $modelo ) ); ?>:</strong> <?php echo esc_html( implode( ' · ', array_map( static fn( $t ) => $t . ' × ' . $tallas[ $t ], $orden ) ) ); ?> <span class="asde-muted">(<?php echo (int) array_sum( $tallas ); ?>)</span></p>
			<?php endforeach; ?>
		<?php endif; ?>
	</section>
<?php endif; ?>

<?php if ( $global && ( $resumen['total_camisas'] || $resumen['insignias'] || $sin_pagar ) ) : ?>
	<section class="asde-card">
		<h3 class="asde-card__title"><?php asde_e( 'Para el próximo pedido al proveedor' ); ?></h3>
		<p class="asde-muted"><?php asde_e( 'Lo pagado que aún no está en ningún pedido.' ); ?><?php echo $sin_pagar ? ' ' . esc_html( sprintf( asde_n( 'Además hay %d pedido sin pagar.', 'Además hay %d pedidos sin pagar.', $sin_pagar ), $sin_pagar ) ) : ''; ?></p>
		<?php foreach ( $resumen['camisas'] as $m ) : ?>
			<?php $tallas = array_filter( $m['tallas'] ); ?>
			<?php if ( $tallas ) : ?>
				<p><strong><?php echo esc_html( $m['nombre'] ); ?>:</strong> <?php echo esc_html( implode( ' · ', array_map( static fn( $t, $n ) => $t . ' × ' . $n, array_keys( $tallas ), $tallas ) ) ); ?> <span class="asde-muted">(<?php echo (int) array_sum( $tallas ); ?>)</span></p>
			<?php endif; ?>
		<?php endforeach; ?>
		<?php if ( $resumen['insignias'] ) : ?>
			<p><strong><?php asde_e( 'Insignias:' ); ?></strong> <?php echo esc_html( implode( ' · ', array_map( static fn( $i ) => $i['nombre'] . ' × ' . $i['cantidad'], $resumen['insignias'] ) ) ); ?></p>
		<?php endif; ?>
		<?php if ( ! $resumen['total_camisas'] && ! $resumen['insignias'] ) : ?>
			<p><?php asde_e( 'Todavía no hay nada pagado pendiente de pedir.' ); ?></p>
		<?php endif; ?>
	</section>
<?php endif; ?>
