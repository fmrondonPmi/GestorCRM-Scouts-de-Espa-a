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
use AsdeBalears\AreaPrivada\Domain\Secciones;
use AsdeBalears\AreaPrivada\Tesoreria\Calculadora;
use AsdeBalears\AreaPrivada\Uniformes\PedidosTable;
use AsdeBalears\AreaPrivada\Uniformes\Servicio;

defined( 'ABSPATH' ) || exit;

$fecha   = static fn( ?string $f ) => $f ? wp_date( 'd/m/Y', strtotime( $f . ( strlen( $f ) > 10 ? ' UTC' : '' ) ) ) : '';
$quien   = static fn( int $id ) => '' !== $usuario( $id ) ? ' por ' . $usuario( $id ) : '';
$estado  = PedidosTable::estado( $p );
$activo  = null === $p['cancelado_en'];
$form    = static function ( string $accion, string $boton, string $clase = 'asde-btn-link', string $confirmar = '' ) use ( $p ): string {
	return sprintf(
		'<form method="post" class="asde-inline-form"%s>%s<input type="hidden" name="asde_action" value="%s"><input type="hidden" name="pedido" value="%d"><input type="hidden" name="desde" value="detalle"><button type="submit" class="%s">%s</button></form>',
		'' !== $confirmar ? ' onsubmit="return confirm(\'' . esc_js( $confirmar ) . '\');"' : '',
		wp_nonce_field( 'asde_uniforme_' . $p['id'], '_wpnonce', true, false ),
		esc_attr( $accion ),
		(int) $p['id'],
		esc_attr( $clase ),
		esc_html( $boton )
	);
};
?>
<p class="asde-back asde-solo-pagina"><a href="<?php echo esc_url( $url ); ?>">← Gestión de pedidos</a></p>

<section class="asde-card">
	<header class="asde-card__header asde-card__header--split">
		<h2 class="asde-card__title">Pedido <?php echo esc_html( PedidosTable::numero( $p ) ); ?></h2>
		<span class="asde-badge asde-badge--uniforme-<?php echo esc_attr( $estado ); ?>"><?php echo esc_html( PedidosTable::ESTADOS[ $estado ] ); ?></span>
	</header>
	<p>
		<?php if ( $persona ) : ?>
			<a href="<?php echo esc_url( $url_ficha ); ?>" data-asde-modal><?php echo esc_html( $persona->nombre_completo() ); ?></a><?php echo \AsdeBalears\AreaPrivada\Socios\Numeros::compacto( $persona ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
		<?php else : ?>
			Nº Scouts de España <?php echo esc_html( $p['socio'] ); ?>
		<?php endif; ?>
		<?php
		foreach ( $p['secciones'] as $s ) {
			echo Secciones::badge( $s ); // phpcs:ignore WordPress.Security.EscapeOutput
		}
		?>
	</p>
	<p><strong><?php echo esc_html( Servicio::descripcion( $p['lineas'] ) ); ?></strong> — <?php echo esc_html( Servicio::importe( $p ) ); ?></p>
	<?php if ( '' !== $p['nota'] ) : ?>
		<p class="asde-muted">Observaciones: <?php echo esc_html( $p['nota'] ); ?></p>
	<?php endif; ?>
	<p class="asde-muted">Pedido el <?php echo esc_html( $fecha( $p['creado'] ) . $quien( $p['creado_por'] ) ); ?>.</p>
	<?php if ( ! $activo ) : ?>
		<div class="asde-notice asde-notice--warning">Cancelado el <?php echo esc_html( $fecha( $p['cancelado_en'] ) . $quien( $p['cancelado_por'] ) ); ?>. Motivo: <?php echo esc_html( $p['cancelado_motivo'] ); ?></div>
	<?php endif; ?>
</section>

<section class="asde-card">
	<h3 class="asde-card__title">Comprobaciones</h3>
	<ol class="asde-pasos asde-pasos--gestion">
		<li class="<?php echo null !== $p['pago_en'] ? 'is-hecho' : ''; ?>">
			<span class="asde-pasos__marca" aria-hidden="true"><?php echo null !== $p['pago_en'] ? '✓' : ''; ?></span>
			<strong>Pago</strong> <span class="asde-muted">(Tesorería)</span>
			<?php if ( null !== $p['pago_en'] ) : ?>
				<br><?php echo esc_html( sprintf( '%s en %s el %s', Calculadora::euros( (float) $p['pago_importe'] ), mb_strtolower( PedidosTable::METODOS[ $p['pago_metodo'] ] ?? $p['pago_metodo'] ), $fecha( $p['pago_fecha'] ) ) ); ?><?php echo '' !== $p['pago_ref'] ? esc_html( ' · ' . $p['pago_ref'] ) : ''; ?>
				<span class="asde-muted"><?php echo esc_html( 'Validado' . $quien( $p['pago_por'] ) . ' el ' . $fecha( $p['pago_en'] ) ); ?></span>
				<?php echo Servicio::puede_pagar( $p ) ? $form( 'uniforme_deshacer_pago', 'Deshacer', 'asde-btn-link', '¿Deshacer el pago de este pedido?' ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<?php elseif ( $activo && Servicio::puede_pagar( $p ) ) : ?>
				<form method="post" class="asde-form asde-form--compacta">
					<?php wp_nonce_field( 'asde_uniforme_' . $p['id'] ); ?>
					<input type="hidden" name="asde_action" value="uniforme_pago">
					<input type="hidden" name="pedido" value="<?php echo (int) $p['id']; ?>">
					<input type="hidden" name="desde" value="detalle">
					<fieldset>
						<legend class="screen-reader-text">Cómo se ha pagado</legend>
						<?php foreach ( PedidosTable::METODOS as $m => $etiqueta ) : ?>
							<label class="asde-choice"><input type="radio" name="metodo" value="<?php echo esc_attr( $m ); ?>" <?php checked( 'efectivo', $m ); ?>><span><?php echo esc_html( $etiqueta ); ?></span></label>
						<?php endforeach; ?>
					</fieldset>
					<p class="asde-form__fila">
						<label>Fecha <input type="date" name="fecha" value="<?php echo esc_attr( wp_date( 'Y-m-d' ) ); ?>" max="<?php echo esc_attr( wp_date( 'Y-m-d' ) ); ?>" required></label>
						<label>Importe (€) <input type="text" name="importe" value="<?php echo esc_attr( number_format( $a_cobrar, 2, ',', '' ) ); ?>" inputmode="decimal" size="8" required></label>
						<label>Referencia (opcional) <input type="text" name="referencia" maxlength="120" placeholder="<?php echo esc_attr( PedidosTable::numero( $p ) ); ?>"></label>
					</p>
					<?php if ( $p['provisional'] ) : ?>
						<p class="asde-help asde-help--warning">Se pidió con insignias sin precio: el importe propuesto usa los precios de hoy<?php echo Servicio::total( $p['lineas'], \AsdeBalears\AreaPrivada\Uniformes\Config::de() )['provisional'] ? ', y alguna sigue sin precio: escribe lo que se ha cobrado' : ''; ?>.</p>
					<?php endif; ?>
					<p><button type="submit" class="button">Validar el pago</button></p>
				</form>
			<?php else : ?>
				<br><span class="asde-muted">Pendiente</span>
			<?php endif; ?>
		</li>

		<li class="<?php echo null !== $p['pedido_en'] ? 'is-hecho' : ''; ?>">
			<span class="asde-pasos__marca" aria-hidden="true"><?php echo null !== $p['pedido_en'] ? '✓' : ''; ?></span>
			<strong>Incluido en el pedido del grupo</strong> <span class="asde-muted">(Coordinación)</span>
			<?php if ( null !== $p['pedido_en'] ) : ?>
				<br><?php echo esc_html( '«' . $p['pedido_ref'] . '»' ); ?>
				<span class="asde-muted"><?php echo esc_html( $quien( $p['pedido_por'] ) . ' el ' . $fecha( $p['pedido_en'] ) ); ?></span>
				<?php echo Servicio::puede_pedir( $p ) ? $form( 'uniforme_deshacer_pedido', 'Deshacer', 'asde-btn-link', '¿Quitar este pedido del pedido del grupo?' ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<?php elseif ( $activo && Servicio::puede_pedir( $p ) ) : ?>
				<form method="post" class="asde-form asde-form--compacta">
					<?php wp_nonce_field( 'asde_uniforme_' . $p['id'] ); ?>
					<input type="hidden" name="asde_action" value="uniforme_incluir">
					<input type="hidden" name="pedido" value="<?php echo (int) $p['id']; ?>">
					<input type="hidden" name="desde" value="detalle">
					<p class="asde-form__fila">
						<label>Nombre del pedido al proveedor <input type="text" name="referencia_pedido" maxlength="60" value="<?php echo esc_attr( 'Pedido ' . wp_date( 'd/m/Y' ) ); ?>"></label>
						<button type="submit" class="button">Incluir en el pedido</button>
					</p>
					<?php if ( null === $p['pago_en'] ) : ?>
						<p class="asde-help asde-help--warning">Aún no consta el pago.</p>
					<?php endif; ?>
				</form>
			<?php else : ?>
				<br><span class="asde-muted">Pendiente</span>
			<?php endif; ?>
		</li>

		<li class="<?php echo null !== $p['entregado_en'] ? 'is-hecho' : ''; ?>">
			<span class="asde-pasos__marca" aria-hidden="true"><?php echo null !== $p['entregado_en'] ? '✓' : ''; ?></span>
			<strong>Entregado</strong> <span class="asde-muted">(el grupo)</span>
			<?php if ( null !== $p['entregado_en'] ) : ?>
				<span class="asde-muted"><?php echo esc_html( $quien( $p['entregado_por'] ) . ' el ' . $fecha( $p['entregado_en'] ) ); ?></span>
				<?php echo Servicio::puede_entregar( $p, $ambito ) ? $form( 'uniforme_deshacer_entrega', 'Deshacer', 'asde-btn-link', '¿Deshacer la entrega? También se borra la confirmación del socio/a, si la había.' ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<?php elseif ( $activo && Servicio::puede_entregar( $p, $ambito ) ) : ?>
				<br><?php echo $form( 'uniforme_entregar', 'Marcar como entregado', 'button' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				<?php if ( null === $p['pedido_en'] ) : ?>
					<span class="asde-help asde-help--warning">No consta en ningún pedido del grupo.</span>
				<?php endif; ?>
			<?php else : ?>
				<br><span class="asde-muted">Pendiente</span>
			<?php endif; ?>
		</li>

		<li class="<?php echo null !== $p['recibido_en'] ? 'is-hecho' : ''; ?>">
			<span class="asde-pasos__marca" aria-hidden="true"><?php echo null !== $p['recibido_en'] ? '✓' : ''; ?></span>
			<strong>Recibido</strong> <span class="asde-muted">(lo confirma el socio/a o su familia)</span>
			<?php if ( null !== $p['recibido_en'] ) : ?>
				<span class="asde-muted"><?php echo esc_html( 'Confirmado' . $quien( $p['recibido_por'] ) . ' el ' . $fecha( $p['recibido_en'] ) ); ?></span>
			<?php elseif ( null !== $p['entregado_en'] ) : ?>
				<br><span class="asde-muted">Pendiente de que lo confirme en su área privada (<em>Uniforme</em>).</span>
			<?php else : ?>
				<br><span class="asde-muted">Pendiente</span>
			<?php endif; ?>
		</li>
	</ol>

	<?php if ( $activo && Servicio::puede_cancelar_gestion( $p ) ) : ?>
		<details class="asde-rechazar">
			<summary>Cancelar el pedido</summary>
			<form method="post" class="asde-form">
				<?php wp_nonce_field( 'asde_uniforme_' . $p['id'] ); ?>
				<input type="hidden" name="asde_action" value="uniforme_cancelar">
				<input type="hidden" name="pedido" value="<?php echo (int) $p['id']; ?>">
				<input type="hidden" name="desde" value="detalle">
				<p>
					<label for="asde-uni-motivo">Motivo</label>
					<input type="text" id="asde-uni-motivo" name="motivo" maxlength="255" required>
				</p>
				<p><button type="submit" class="button asde-button-secundario">Cancelar el pedido</button></p>
			</form>
		</details>
	<?php endif; ?>
</section>
