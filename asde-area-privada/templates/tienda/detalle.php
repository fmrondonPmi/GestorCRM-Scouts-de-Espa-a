<?php
/**
 * Gestión de uniformes · detalle de un pedido (también en la ventana emergente).
 *
 * @var array                                          $p
 * @var ?AsdeBalears\AreaPrivada\Domain\Persona        $persona
 * @var AsdeBalears\AreaPrivada\Auth\Ambito            $ambito
 * @var callable                                       $usuario  fn( id ) → nombre de quien lo hizo
 * @var float                                          $a_cobrar Importe con los precios de hoy
 * @var string                                         $url
 * @var string                                         $url_ficha
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Domain\Secciones;
use AsdeBalears\AreaPrivada\Tesoreria\Calculadora;
use AsdeBalears\AreaPrivada\Tienda\PedidosTable;
use AsdeBalears\AreaPrivada\Tienda\Servicio;

defined( 'ABSPATH' ) || exit;

$fecha   = static fn( ?string $f ) => $f ? wp_date( 'd/m/Y', strtotime( $f . ( strlen( $f ) > 10 ? ' UTC' : '' ) ) ) : '';
$cuando  = static fn( string $con, string $sin, int $id, string $f ): string => sprintf( '' !== $usuario( $id ) ? $con : $sin, $usuario( $id ), $f );
$estado  = PedidosTable::estado( $p );
$activo  = null === $p['cancelado_en'];
$camisa  = $p['lineas']['camisa'];
$del_inv = PedidosTable::REF_INVENTARIO === $p['pedido_ref'];
$form    = static function ( string $accion, string $boton, string $clase = 'asde-btn-link', string $confirmar = '' ) use ( $p ): string {
	return sprintf(
		'<form method="post" class="asde-inline-form"%s>%s<input type="hidden" name="asde_action" value="%s"><input type="hidden" name="pedido" value="%d"><input type="hidden" name="desde" value="detalle"><button type="submit" class="%s">%s</button></form>',
		'' !== $confirmar ? ' data-asde-confirmar="' . esc_attr( $confirmar ) . '"' : '',
		wp_nonce_field( 'asde_uniforme_' . $p['id'], '_wpnonce', true, false ),
		esc_attr( $accion ),
		(int) $p['id'],
		esc_attr( $clase ),
		esc_html( $boton )
	);
};
?>
<p class="asde-back asde-solo-pagina"><a href="<?php echo esc_url( $url ); ?>"><?php esc_html_e( '← Order management', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a></p>

<section class="asde-card" data-asde-plegable="uniforme-pedido">
	<header class="asde-card__header asde-card__header--split">
		<h2 class="asde-card__title"><?php esc_html_e( 'Order', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <?php echo esc_html( PedidosTable::numero( $p ) ); ?></h2>
		<span class="asde-badge asde-badge--uniforme-<?php echo esc_attr( $estado ); ?>"><?php echo esc_html( ( PedidosTable::estados()[ $estado ] ) ); ?></span>
	</header>
	<p>
		<?php if ( $persona ) : ?>
			<a href="<?php echo esc_url( $url_ficha ); ?>" data-asde-modal><?php echo esc_html( $persona->nombre_completo() ); ?></a><?php echo wp_kses( \AsdeBalears\AreaPrivada\Socios\Numeros::compacto( $persona ), asde_html_permitido() ); ?>
		<?php else : ?>
			<?php if ( '' === $p['socio'] ) : ?>
				<?php $inv = \AsdeBalears\AreaPrivada\Tienda\PedidosTable::invitado( $p ); ?>
				<strong><?php echo esc_html( Servicio::comprador( $p ) ); ?></strong>
				<?php if ( $inv ) : ?>
					<br><span class="asde-muted"><?php echo esc_html( $inv['email'] . ( '' !== (string) $inv['telefono'] ? ' · ' . $inv['telefono'] : '' ) ); ?></span>
				<?php endif; ?>
			<?php else : ?>
				<?php esc_html_e( 'Scouts de España no.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <?php echo esc_html( $p['socio'] ); ?>
			<?php endif; ?>
		<?php endif; ?>
		<?php
		foreach ( $p['secciones'] as $s ) {
			echo wp_kses( Secciones::badge( $s ), asde_html_permitido() );
		}
		?>
	</p>
	<p><strong><?php echo esc_html( Servicio::descripcion( $p['lineas'] ) ); ?></strong> — <?php echo esc_html( Servicio::importe( $p ) ); ?></p>
	<?php if ( '' !== $p['nota'] ) : ?>
		<p class="asde-muted"><?php esc_html_e( 'Notes:', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <?php echo esc_html( $p['nota'] ); ?></p>
	<?php endif; ?>
	<p class="asde-muted"><?php echo esc_html( $cuando( /* translators: 2: text, 1: text */ __( 'Ordered on %2$s by %1$s.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), /* translators: %2$s: text */ __( 'Ordered on %2$s.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), (int) $p['creado_por'], $fecha( $p['creado'] ) ) ); ?></p>
	<?php if ( ! $activo ) : ?>
		<div class="asde-notice asde-notice--warning"><?php echo esc_html( $cuando( /* translators: 2: text, 1: text */ __( 'Cancelled on %2$s by %1$s.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), /* translators: %2$s: text */ __( 'Cancelled on %2$s.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), (int) $p['cancelado_por'], $fecha( $p['cancelado_en'] ) ) ); ?> <?php echo esc_html( sprintf( /* translators: %s: text */ __( 'Reason: %s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $p['cancelado_motivo'] ) ); ?></div>
	<?php endif; ?>
</section>

<section class="asde-card">
	<h3 class="asde-card__title"><?php esc_html_e( 'Checks', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h3>
	<ol class="asde-pasos asde-pasos--gestion">
		<li class="<?php echo null !== $p['pago_en'] ? 'is-hecho' : ''; ?>">
			<span class="asde-pasos__marca" aria-hidden="true"><?php echo null !== $p['pago_en'] ? '✓' : ''; ?></span>
			<strong><?php esc_html_e( 'Payment', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></strong> <span class="asde-muted"><?php esc_html_e( '(Treasury)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span>
			<?php if ( null !== $p['pago_en'] ) : ?>
				<br><?php echo esc_html( sprintf( /* translators: 1: text, 2: text, 3: text */ __( '%1$s in %2$s on %3$s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), Calculadora::euros( (float) $p['pago_importe'] ), mb_strtolower( ( PedidosTable::metodos()[ $p['pago_metodo'] ] ?? $p['pago_metodo'] ) ), $fecha( $p['pago_fecha'] ) ) ); ?><?php echo '' !== $p['pago_ref'] ? esc_html( ' · ' . $p['pago_ref'] ) : ''; ?>
				<span class="asde-muted"><?php echo esc_html( $cuando( /* translators: 1: text, 2: text */ __( 'Validated by %1$s on %2$s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), /* translators: %2$s: text */ __( 'Validated on %2$s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), (int) $p['pago_por'], $fecha( $p['pago_en'] ) ) ); ?></span>
				<?php echo wp_kses( Servicio::puede_pagar( $p ) ? $form( 'uniforme_deshacer_pago', esc_html( __( 'Undo', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ), 'asde-btn-link', esc_html( __( 'Undo this order\'s payment?', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) ) : '', asde_html_permitido() ); ?>
			<?php elseif ( $activo && Servicio::puede_pagar( $p ) ) : ?>
				<form method="post" class="asde-form asde-form--compacta">
					<?php wp_nonce_field( 'asde_uniforme_' . $p['id'] ); ?>
					<input type="hidden" name="asde_action" value="uniforme_pago">
					<input type="hidden" name="pedido" value="<?php echo (int) $p['id']; ?>">
					<input type="hidden" name="desde" value="detalle">
					<?php if ( '' !== $p['forma_pago'] ) : ?>
						<p class="asde-help"><?php echo esc_html( sprintf( /* translators: %s: text */ __( 'Whoever ordered chose to pay with: %s.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), ( PedidosTable::formas()[ $p['forma_pago'] ] ?? '' ) ) ); ?></p>
					<?php endif; ?>
					<p class="asde-form__fila asde-form__fila--una-linea">
						<label><?php esc_html_e( 'How', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?>
							<select name="metodo">
								<?php foreach ( ( PedidosTable::metodos() ) as $m => $etiqueta ) : ?>
									<option value="<?php echo esc_attr( $m ); ?>" <?php selected( PedidosTable::METODO_DE_FORMA[ $p['forma_pago'] ] ?? 'efectivo', $m ); ?>><?php echo esc_html( $etiqueta ); ?></option>
								<?php endforeach; ?>
							</select>
						</label>
						<label><?php esc_html_e( 'Date', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <input type="date" name="fecha" value="<?php echo esc_attr( wp_date( 'Y-m-d' ) ); ?>" max="<?php echo esc_attr( wp_date( 'Y-m-d' ) ); ?>" required></label>
						<label><?php esc_html_e( 'Amount (€)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <input type="text" name="importe" value="<?php echo esc_attr( number_format( $a_cobrar, 2, ',', '' ) ); ?>" inputmode="decimal" size="8" required></label>
						<label><?php esc_html_e( 'Reference (optional)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <input type="text" name="referencia" maxlength="120" placeholder="<?php echo esc_attr( PedidosTable::numero( $p ) ); ?>"></label>
						<button type="submit" class="button"><?php esc_html_e( 'Validate the payment', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
					</p>
					<?php if ( $p['provisional'] ) : ?>
						<p class="asde-help asde-help--warning"><?php esc_html_e( 'It was ordered with items that have no price: the proposed amount uses today\'s prices', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?><?php echo Servicio::total( $p['lineas'], \AsdeBalears\AreaPrivada\Tienda\Config::de() )['provisional'] ? esc_html( __( ', and some still have no price: enter what was collected', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) : ''; ?>.</p>
					<?php endif; ?>
				</form>
			<?php else : ?>
				<br><span class="asde-muted"><?php esc_html_e( 'Pending', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span>
			<?php endif; ?>
		</li>

		<li class="<?php echo null !== $p['pedido_en'] ? 'is-hecho' : ''; ?>">
			<span class="asde-pasos__marca" aria-hidden="true"><?php echo null !== $p['pedido_en'] ? '✓' : ''; ?></span>
			<strong><?php esc_html_e( 'Included in the group\'s order', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></strong> <span class="asde-muted"><?php esc_html_e( '(Coordination)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span>
			<?php if ( null !== $p['pedido_en'] ) : ?>
				<br><?php echo esc_html( '«' . ( $del_inv ? asde_guardado( PedidosTable::REF_INVENTARIO ) : $p['pedido_ref'] ) . '»' ); ?>
				<span class="asde-muted"><?php echo esc_html( $cuando( /* translators: 1: text, 2: text */ __( 'by %1$s on %2$s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), /* translators: %2$s: text */ __( 'on %2$s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), (int) $p['pedido_por'], $fecha( $p['pedido_en'] ) ) ); ?></span>
				<?php echo wp_kses( ! $del_inv && Servicio::puede_pedir( $p ) ? $form( 'uniforme_deshacer_pedido', esc_html( __( 'Undo', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ), 'asde-btn-link', esc_html( __( 'Remove this order from the group\'s order?', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) ) : '', asde_html_permitido() ); ?>
			<?php elseif ( $activo && Servicio::puede_pedir( $p ) ) : ?>
				<form method="post" class="asde-form asde-form--compacta">
					<?php wp_nonce_field( 'asde_uniforme_' . $p['id'] ); ?>
					<input type="hidden" name="asde_action" value="uniforme_incluir">
					<input type="hidden" name="pedido" value="<?php echo (int) $p['id']; ?>">
					<input type="hidden" name="desde" value="detalle">
					<p class="asde-form__fila">
						<label><?php esc_html_e( 'Name of the supplier order', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <input type="text" name="referencia_pedido" maxlength="60" value="<?php echo esc_attr( sprintf( /* translators: %s: text */ __( 'Order %s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), wp_date( 'd/m/Y' ) ) ); ?>"></label>
						<button type="submit" class="button"><?php esc_html_e( 'Include in the order', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
					</p>
					<?php if ( null === $p['pago_en'] ) : ?>
						<p class="asde-help asde-help--warning"><?php esc_html_e( 'Payment not yet recorded.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
					<?php endif; ?>
				</form>
			<?php else : ?>
				<br><span class="asde-muted"><?php esc_html_e( 'Pending', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span>
			<?php endif; ?>
		</li>

		<li class="<?php echo null !== $p['entregado_en'] ? 'is-hecho' : ''; ?>">
			<span class="asde-pasos__marca" aria-hidden="true"><?php echo null !== $p['entregado_en'] ? '✓' : ''; ?></span>
			<strong><?php esc_html_e( 'Delivered', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></strong> <span class="asde-muted"><?php esc_html_e( '(the group)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span>
			<?php if ( null !== $p['entregado_en'] ) : ?>
				<span class="asde-muted"><?php echo esc_html( $cuando( /* translators: 1: text, 2: text */ __( 'by %1$s on %2$s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), /* translators: %2$s: text */ __( 'on %2$s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), (int) $p['entregado_por'], $fecha( $p['entregado_en'] ) ) ); ?></span>
				<?php echo wp_kses( Servicio::puede_entregar( $p, $ambito ) ? $form( 'uniforme_deshacer_entrega', esc_html( __( 'Undo', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ), 'asde-btn-link', esc_html( __( 'Undo the delivery? The member\'s confirmation is also deleted, if there was one.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) ) : '', asde_html_permitido() ); ?>
			<?php elseif ( $activo && null !== $p['pedido_en'] && \AsdeBalears\AreaPrivada\Tienda\Proveedor::esperando( $p ) ) : ?>
				<br><span class="asde-help asde-help--warning"><?php esc_html_e( 'It has not arrived from the supplier yet: it can be delivered when Coordination marks its order as received.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span>
			<?php elseif ( $activo && Servicio::puede_entregar( $p, $ambito ) ) : ?>
				<br><?php echo wp_kses( $form( 'uniforme_entregar', esc_html( __( 'Mark as delivered', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ), 'button' ), asde_html_permitido() ); ?>
				<?php if ( null === $p['pedido_en'] ) : ?>
					<span class="asde-help asde-help--warning"><?php esc_html_e( 'It is not in any group order.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span>
				<?php endif; ?>
			<?php else : ?>
				<br><span class="asde-muted"><?php esc_html_e( 'Pending', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span>
			<?php endif; ?>
		</li>

		<li class="<?php echo null !== $p['recibido_en'] ? 'is-hecho' : ''; ?>">
			<span class="asde-pasos__marca" aria-hidden="true"><?php echo null !== $p['recibido_en'] ? '✓' : ''; ?></span>
			<strong><?php esc_html_e( 'Received', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></strong> <span class="asde-muted"><?php esc_html_e( '(confirmed by the member or their family)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span>
			<?php if ( null !== $p['recibido_en'] ) : ?>
				<span class="asde-muted"><?php echo esc_html( $cuando( /* translators: 1: text, 2: text */ __( 'Confirmed by %1$s on %2$s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), /* translators: %2$s: text */ __( 'Confirmed on %2$s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), (int) $p['recibido_por'], $fecha( $p['recibido_en'] ) ) ); ?></span>
			<?php elseif ( null !== $p['entregado_en'] ) : ?>
				<br><span class="asde-muted"><?php esc_html_e( 'Pending confirmation in their private area (', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?><em><?php esc_html_e( 'Uniform', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></em>).</span>
			<?php else : ?>
				<br><span class="asde-muted"><?php esc_html_e( 'Pending', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span>
			<?php endif; ?>
		</li>
	</ol>

	<?php if ( $activo && Servicio::puede_cancelar_gestion( $p ) ) : ?>
		<details class="asde-rechazar">
			<summary><?php esc_html_e( 'Cancel the order', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></summary>
			<form method="post" class="asde-form">
				<?php wp_nonce_field( 'asde_uniforme_' . $p['id'] ); ?>
				<input type="hidden" name="asde_action" value="uniforme_cancelar">
				<input type="hidden" name="pedido" value="<?php echo (int) $p['id']; ?>">
				<input type="hidden" name="desde" value="detalle">
				<p>
					<label for="asde-uni-motivo"><?php esc_html_e( 'Reason', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
					<input type="text" id="asde-uni-motivo" name="motivo" maxlength="255" required>
				</p>
				<p><button type="submit" class="button asde-button-secundario"><?php esc_html_e( 'Cancel the order', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button></p>
			</form>
		</details>
	<?php endif; ?>
</section>
