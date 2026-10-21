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
<p class="asde-back asde-solo-pagina"><a href="<?php echo esc_url( $url ); ?>"><?php asde_e( '← Gestión de pedidos' ); ?></a></p>

<section class="asde-card" data-asde-plegable="uniforme-pedido">
	<header class="asde-card__header asde-card__header--split">
		<h2 class="asde-card__title"><?php asde_e( 'Pedido' ); ?> <?php echo esc_html( PedidosTable::numero( $p ) ); ?></h2>
		<span class="asde-badge asde-badge--uniforme-<?php echo esc_attr( $estado ); ?>"><?php echo esc_html( asde_t( PedidosTable::ESTADOS[ $estado ] ) ); ?></span>
	</header>
	<p>
		<?php if ( $persona ) : ?>
			<a href="<?php echo esc_url( $url_ficha ); ?>" data-asde-modal><?php echo esc_html( $persona->nombre_completo() ); ?></a><?php echo \AsdeBalears\AreaPrivada\Socios\Numeros::compacto( $persona ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
		<?php else : ?>
			<?php if ( '' === $p['socio'] ) : ?>
				<?php $inv = \AsdeBalears\AreaPrivada\Tienda\PedidosTable::invitado( $p ); ?>
				<strong><?php echo esc_html( Servicio::comprador( $p ) ); ?></strong>
				<?php if ( $inv ) : ?>
					<br><span class="asde-muted"><?php echo esc_html( $inv['email'] . ( '' !== (string) $inv['telefono'] ? ' · ' . $inv['telefono'] : '' ) ); ?></span>
				<?php endif; ?>
			<?php else : ?>
				<?php asde_e( 'Nº Scouts de España' ); ?> <?php echo esc_html( $p['socio'] ); ?>
			<?php endif; ?>
		<?php endif; ?>
		<?php
		foreach ( $p['secciones'] as $s ) {
			echo Secciones::badge( $s ); // phpcs:ignore WordPress.Security.EscapeOutput
		}
		?>
	</p>
	<p><strong><?php echo esc_html( Servicio::descripcion( $p['lineas'] ) ); ?></strong> — <?php echo esc_html( Servicio::importe( $p ) ); ?></p>
	<?php if ( '' !== $p['nota'] ) : ?>
		<p class="asde-muted"><?php asde_e( 'Observaciones:' ); ?> <?php echo esc_html( $p['nota'] ); ?></p>
	<?php endif; ?>
	<p class="asde-muted"><?php echo esc_html( $cuando( asde_t( 'Pedido el %2$s por %1$s.' ), asde_t( 'Pedido el %2$s.' ), (int) $p['creado_por'], $fecha( $p['creado'] ) ) ); ?></p>
	<?php if ( ! $activo ) : ?>
		<div class="asde-notice asde-notice--warning"><?php echo esc_html( $cuando( asde_t( 'Cancelado el %2$s por %1$s.' ), asde_t( 'Cancelado el %2$s.' ), (int) $p['cancelado_por'], $fecha( $p['cancelado_en'] ) ) ); ?> <?php echo esc_html( sprintf( asde_t( 'Motivo: %s' ), $p['cancelado_motivo'] ) ); ?></div>
	<?php endif; ?>
</section>

<section class="asde-card">
	<h3 class="asde-card__title"><?php asde_e( 'Comprobaciones' ); ?></h3>
	<ol class="asde-pasos asde-pasos--gestion">
		<li class="<?php echo null !== $p['pago_en'] ? 'is-hecho' : ''; ?>">
			<span class="asde-pasos__marca" aria-hidden="true"><?php echo null !== $p['pago_en'] ? '✓' : ''; ?></span>
			<strong><?php asde_e( 'Pago' ); ?></strong> <span class="asde-muted"><?php asde_e( '(Tesorería)' ); ?></span>
			<?php if ( null !== $p['pago_en'] ) : ?>
				<br><?php echo esc_html( sprintf( asde_t( '%s en %s el %s' ), Calculadora::euros( (float) $p['pago_importe'] ), mb_strtolower( asde_t( PedidosTable::METODOS[ $p['pago_metodo'] ] ?? $p['pago_metodo'] ) ), $fecha( $p['pago_fecha'] ) ) ); ?><?php echo '' !== $p['pago_ref'] ? esc_html( ' · ' . $p['pago_ref'] ) : ''; ?>
				<span class="asde-muted"><?php echo esc_html( $cuando( asde_t( 'Validado por %1$s el %2$s' ), asde_t( 'Validado el %2$s' ), (int) $p['pago_por'], $fecha( $p['pago_en'] ) ) ); ?></span>
				<?php echo Servicio::puede_pagar( $p ) ? $form( 'uniforme_deshacer_pago', esc_html( asde_t( 'Deshacer' ) ), 'asde-btn-link', esc_html( asde_t( '¿Deshacer el pago de este pedido?' ) ) ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<?php elseif ( $activo && Servicio::puede_pagar( $p ) ) : ?>
				<form method="post" class="asde-form asde-form--compacta">
					<?php wp_nonce_field( 'asde_uniforme_' . $p['id'] ); ?>
					<input type="hidden" name="asde_action" value="uniforme_pago">
					<input type="hidden" name="pedido" value="<?php echo (int) $p['id']; ?>">
					<input type="hidden" name="desde" value="detalle">
					<?php if ( '' !== $p['forma_pago'] ) : ?>
						<p class="asde-help"><?php echo esc_html( sprintf( asde_t( 'Quien lo pidió eligió pagar con: %s.' ), asde_t( PedidosTable::FORMAS[ $p['forma_pago'] ] ?? '' ) ) ); ?></p>
					<?php endif; ?>
					<fieldset>
						<legend class="screen-reader-text"><?php asde_e( 'Cómo se ha pagado' ); ?></legend>
						<?php foreach ( asde_ta( PedidosTable::METODOS ) as $m => $etiqueta ) : ?>
							<label class="asde-choice"><input type="radio" name="metodo" value="<?php echo esc_attr( $m ); ?>" <?php checked( PedidosTable::METODO_DE_FORMA[ $p['forma_pago'] ] ?? 'efectivo', $m ); ?>><span><?php echo esc_html( $etiqueta ); ?></span></label>
						<?php endforeach; ?>
					</fieldset>
					<p class="asde-form__fila">
						<label><?php asde_e( 'Fecha' ); ?> <input type="date" name="fecha" value="<?php echo esc_attr( wp_date( 'Y-m-d' ) ); ?>" max="<?php echo esc_attr( wp_date( 'Y-m-d' ) ); ?>" required></label>
						<label><?php asde_e( 'Importe (€)' ); ?> <input type="text" name="importe" value="<?php echo esc_attr( number_format( $a_cobrar, 2, ',', '' ) ); ?>" inputmode="decimal" size="8" required></label>
						<label><?php asde_e( 'Referencia (opcional)' ); ?> <input type="text" name="referencia" maxlength="120" placeholder="<?php echo esc_attr( PedidosTable::numero( $p ) ); ?>"></label>
					</p>
					<?php if ( $p['provisional'] ) : ?>
						<p class="asde-help asde-help--warning"><?php asde_e( 'Se pidió con artículos sin precio: el importe propuesto usa los precios de hoy' ); ?><?php echo Servicio::total( $p['lineas'], \AsdeBalears\AreaPrivada\Tienda\Config::de() )['provisional'] ? esc_html( asde_t( ', y alguna sigue sin precio: escribe lo que se ha cobrado' ) ) : ''; ?>.</p>
					<?php endif; ?>
					<p><button type="submit" class="button"><?php asde_e( 'Validar el pago' ); ?></button></p>
				</form>
			<?php else : ?>
				<br><span class="asde-muted"><?php asde_e( 'Pendiente' ); ?></span>
			<?php endif; ?>
		</li>

		<li class="<?php echo null !== $p['pedido_en'] ? 'is-hecho' : ''; ?>">
			<span class="asde-pasos__marca" aria-hidden="true"><?php echo null !== $p['pedido_en'] ? '✓' : ''; ?></span>
			<strong><?php asde_e( 'Incluido en el pedido del grupo' ); ?></strong> <span class="asde-muted"><?php asde_e( '(Coordinación)' ); ?></span>
			<?php if ( null !== $p['pedido_en'] ) : ?>
				<br><?php echo esc_html( '«' . ( $del_inv ? asde_t( PedidosTable::REF_INVENTARIO ) : $p['pedido_ref'] ) . '»' ); ?>
				<span class="asde-muted"><?php echo esc_html( $cuando( asde_t( 'por %1$s el %2$s' ), asde_t( 'el %2$s' ), (int) $p['pedido_por'], $fecha( $p['pedido_en'] ) ) ); ?></span>
				<?php echo ! $del_inv && Servicio::puede_pedir( $p ) ? $form( 'uniforme_deshacer_pedido', esc_html( asde_t( 'Deshacer' ) ), 'asde-btn-link', esc_html( asde_t( '¿Quitar este pedido del pedido del grupo?' ) ) ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<?php elseif ( $activo && Servicio::puede_pedir( $p ) ) : ?>
				<form method="post" class="asde-form asde-form--compacta">
					<?php wp_nonce_field( 'asde_uniforme_' . $p['id'] ); ?>
					<input type="hidden" name="asde_action" value="uniforme_incluir">
					<input type="hidden" name="pedido" value="<?php echo (int) $p['id']; ?>">
					<input type="hidden" name="desde" value="detalle">
					<p class="asde-form__fila">
						<label><?php asde_e( 'Nombre del pedido al proveedor' ); ?> <input type="text" name="referencia_pedido" maxlength="60" value="<?php echo esc_attr( sprintf( asde_t( 'Pedido %s' ), wp_date( 'd/m/Y' ) ) ); ?>"></label>
						<button type="submit" class="button"><?php asde_e( 'Incluir en el pedido' ); ?></button>
					</p>
					<?php if ( null === $p['pago_en'] ) : ?>
						<p class="asde-help asde-help--warning"><?php asde_e( 'Aún no consta el pago.' ); ?></p>
					<?php endif; ?>
				</form>
			<?php else : ?>
				<br><span class="asde-muted"><?php asde_e( 'Pendiente' ); ?></span>
			<?php endif; ?>
		</li>

		<li class="<?php echo null !== $p['entregado_en'] ? 'is-hecho' : ''; ?>">
			<span class="asde-pasos__marca" aria-hidden="true"><?php echo null !== $p['entregado_en'] ? '✓' : ''; ?></span>
			<strong><?php asde_e( 'Entregado' ); ?></strong> <span class="asde-muted"><?php asde_e( '(el grupo)' ); ?></span>
			<?php if ( null !== $p['entregado_en'] ) : ?>
				<span class="asde-muted"><?php echo esc_html( $cuando( asde_t( 'por %1$s el %2$s' ), asde_t( 'el %2$s' ), (int) $p['entregado_por'], $fecha( $p['entregado_en'] ) ) ); ?></span>
				<?php echo Servicio::puede_entregar( $p, $ambito ) ? $form( 'uniforme_deshacer_entrega', esc_html( asde_t( 'Deshacer' ) ), 'asde-btn-link', esc_html( asde_t( '¿Deshacer la entrega? También se borra la confirmación del socio/a, si la había.' ) ) ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<?php elseif ( $activo && null !== $p['pedido_en'] && \AsdeBalears\AreaPrivada\Tienda\Proveedor::esperando( $p ) ) : ?>
				<br><span class="asde-help asde-help--warning"><?php asde_e( 'Todavía no ha llegado del proveedor: se podrá entregar cuando Coordinación marque su pedido como recibido.' ); ?></span>
			<?php elseif ( $activo && Servicio::puede_entregar( $p, $ambito ) ) : ?>
				<br><?php echo $form( 'uniforme_entregar', esc_html( asde_t( 'Marcar como entregado' ) ), 'button' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				<?php if ( null === $p['pedido_en'] ) : ?>
					<span class="asde-help asde-help--warning"><?php asde_e( 'No consta en ningún pedido del grupo.' ); ?></span>
				<?php endif; ?>
			<?php else : ?>
				<br><span class="asde-muted"><?php asde_e( 'Pendiente' ); ?></span>
			<?php endif; ?>
		</li>

		<li class="<?php echo null !== $p['recibido_en'] ? 'is-hecho' : ''; ?>">
			<span class="asde-pasos__marca" aria-hidden="true"><?php echo null !== $p['recibido_en'] ? '✓' : ''; ?></span>
			<strong><?php asde_e( 'Recibido' ); ?></strong> <span class="asde-muted"><?php asde_e( '(lo confirma el socio/a o su familia)' ); ?></span>
			<?php if ( null !== $p['recibido_en'] ) : ?>
				<span class="asde-muted"><?php echo esc_html( $cuando( asde_t( 'Confirmado por %1$s el %2$s' ), asde_t( 'Confirmado el %2$s' ), (int) $p['recibido_por'], $fecha( $p['recibido_en'] ) ) ); ?></span>
			<?php elseif ( null !== $p['entregado_en'] ) : ?>
				<br><span class="asde-muted"><?php asde_e( 'Pendiente de que lo confirme en su área privada (' ); ?><em><?php asde_e( 'Uniforme' ); ?></em>).</span>
			<?php else : ?>
				<br><span class="asde-muted"><?php asde_e( 'Pendiente' ); ?></span>
			<?php endif; ?>
		</li>
	</ol>

	<?php if ( $activo && Servicio::puede_cancelar_gestion( $p ) ) : ?>
		<details class="asde-rechazar">
			<summary><?php asde_e( 'Cancelar el pedido' ); ?></summary>
			<form method="post" class="asde-form">
				<?php wp_nonce_field( 'asde_uniforme_' . $p['id'] ); ?>
				<input type="hidden" name="asde_action" value="uniforme_cancelar">
				<input type="hidden" name="pedido" value="<?php echo (int) $p['id']; ?>">
				<input type="hidden" name="desde" value="detalle">
				<p>
					<label for="asde-uni-motivo"><?php asde_e( 'Motivo' ); ?></label>
					<input type="text" id="asde-uni-motivo" name="motivo" maxlength="255" required>
				</p>
				<p><button type="submit" class="button asde-button-secundario"><?php asde_e( 'Cancelar el pedido' ); ?></button></p>
			</form>
		</details>
	<?php endif; ?>
</section>
