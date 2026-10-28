<?php
/**
 * Tienda pública (sin sesión): el catálogo para comprar con el enlace del grupo y la página personal de un pedido.
 *
 * @var string   $modo         catalogo | pedido | sin_enlace | invalido
 * @var string[] $errores
 * @var array    $valores      Lo escrito si el pedido no se pudo hacer (nombre, email, telefono, nota, forma_pago, cant)
 * @var string   $sello        Sello de tiempo (anti programas)
 * @var string   $clave        Clave del enlace del grupo
 * @var array|null $grupo
 * @var string   $token        Token del enlace personal del pedido
 * @var bool     $nuevo        Se acaba de hacer el pedido
 * @var array[]  $catalogo     Productos que ve cualquiera (producto, variantes)
 * @var array    $cfg          Configuración de la tienda del grupo
 * @var array|null $pedido     El pedido (enlace personal)
 * @var string[] $pagar        Cómo pagarlo
 * @var string   $nombre_grupo
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Frontend\TemplateLoader;
use AsdeBalears\AreaPrivada\Tesoreria\Calculadora;
use AsdeBalears\AreaPrivada\Tienda\Config;
use AsdeBalears\AreaPrivada\Tienda\PedidosTable;
use AsdeBalears\AreaPrivada\Tienda\Servicio;
use AsdeBalears\AreaPrivada\Tienda\VariantesTable;

defined( 'ABSPATH' ) || exit;

$fecha = static fn( ?string $f ): string => $f ? wp_date( 'd/m/Y', strtotime( $f . ( strlen( $f ) > 10 ? ' UTC' : '' ) ) ) : '';
$val   = static fn( string $k ): string => (string) ( $valores[ $k ] ?? '' );
?>
<section class="asde-card asde-tienda asde-tienda--publica">
	<h2 class="asde-card__title"><?php echo '' !== $nombre_grupo ? esc_html( sprintf( /* translators: %s: text */ __( '%s\'s shop', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $nombre_grupo ) ) : esc_html( __( 'Shop', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?></h2>

	<?php if ( 'sin_enlace' === $modo ) : ?>
		<p><?php esc_html_e( 'This address is not valid or the shop is not open right now. Ask the group for the link.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>

	<?php elseif ( 'invalido' === $modo ) : ?>
		<p><?php esc_html_e( 'This link is not valid or has already expired.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>

	<?php elseif ( 'pedido' === $modo ) : ?>
		<?php
		$p      = $pedido;
		$estado = PedidosTable::estado( $p );
		?>
		<?php if ( $nuevo ) : ?>
			<div class="asde-notice asde-notice--success"><?php esc_html_e( 'Order received! We have emailed you this link: keep it to follow your order.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></div>
		<?php endif; ?>
		<?php foreach ( $errores as $error ) : ?>
			<div class="asde-notice asde-notice--error"><?php echo esc_html( $error ); ?></div>
		<?php endforeach; ?>
		<p>
			<strong><?php echo esc_html( PedidosTable::numero( $p ) ); ?></strong>
			<span class="asde-badge asde-badge--uniforme-<?php echo esc_attr( $estado ); ?>"><?php echo esc_html( ( PedidosTable::estados()[ $estado ] ) ); ?></span>
		</p>
		<p><?php echo esc_html( Servicio::descripcion( $p['lineas'] ) ); ?> — <strong><?php echo esc_html( null !== $p['pago_importe'] ? Calculadora::euros( $p['pago_importe'] ) : Servicio::importe( $p ) ); ?></strong></p>
		<?php if ( 'cancelado' === $estado ) : ?>
			<p class="asde-muted"><?php echo esc_html( sprintf( /* translators: %s: text */ __( 'Cancelled on %s.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $fecha( $p['cancelado_en'] ) ) ); ?></p>
		<?php else : ?>
			<ol class="asde-pasos">
				<li class="is-hecho"><span class="asde-pasos__marca" aria-hidden="true">✓</span> <?php esc_html_e( 'Requested', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <span class="asde-muted"><?php echo esc_html( $fecha( $p['creado'] ) ); ?></span></li>
				<li class="<?php echo null !== $p['pago_en'] ? 'is-hecho' : ''; ?>"><span class="asde-pasos__marca" aria-hidden="true"><?php echo null !== $p['pago_en'] ? '✓' : ''; ?></span> <?php esc_html_e( 'Paid', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?><?php echo wp_kses( null !== $p['pago_en'] ? ' <span class="asde-muted">' . esc_html( $fecha( $p['pago_fecha'] ) ) . '</span>' : '', asde_html_permitido() ); ?></li>
				<li class="<?php echo null !== $p['entregado_en'] ? 'is-hecho' : ''; ?>"><span class="asde-pasos__marca" aria-hidden="true"><?php echo null !== $p['entregado_en'] ? '✓' : ''; ?></span> <?php esc_html_e( 'Delivered', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?><?php echo wp_kses( null !== $p['entregado_en'] ? ' <span class="asde-muted">' . esc_html( $fecha( $p['entregado_en'] ) ) . '</span>' : '', asde_html_permitido() ); ?></li>
			</ol>
			<?php if ( null === $p['pago_en'] && $pagar ) : ?>
				<h3><?php esc_html_e( 'How to pay it', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h3>
				<?php foreach ( $pagar as $linea ) : ?>
					<p><?php echo esc_html( $linea ); ?></p>
				<?php endforeach; ?>
				<?php if ( '' !== trim( $cfg['texto_pago'] ) ) : ?>
					<div class="asde-notice asde-notice--info"><?php echo wp_kses_post( wpautop( $cfg['texto_pago'] ) ); ?></div>
				<?php endif; ?>
				<?php if ( $cfg['dias_sin_pagar'] > 0 ) : ?>
					<p class="asde-help"><?php echo esc_html( sprintf( /* translators: %d: number */ __( 'If it is not paid within %d days, the order is cancelled and the items go back to the shop.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), (int) $cfg['dias_sin_pagar'] ) ); ?></p>
				<?php endif; ?>
			<?php endif; ?>
			<?php if ( null === $p['pago_en'] && null === $p['entregado_en'] ) : ?>
				<form method="post" class="asde-pedido__accion" data-asde-confirmar="<?php echo esc_attr( sprintf( /* translators: %s: text */ __( 'Cancel order %s?', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), PedidosTable::numero( $p ) ) ); ?>">
					<?php wp_nonce_field( 'asde_tienda_invitado_cancelar' ); ?>
					<input type="hidden" name="asde_action" value="tienda_invitado_cancelar">
					<input type="hidden" name="pedido" value="<?php echo (int) $p['id']; ?>">
					<input type="hidden" name="t" value="<?php echo esc_attr( $token ); ?>">
					<button type="submit" class="asde-btn-link asde-btn-danger"><?php esc_html_e( 'Cancel the order', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
				</form>
			<?php endif; ?>
		<?php endif; ?>

	<?php else : ?>
		<?php foreach ( $errores as $error ) : ?>
			<div class="asde-notice asde-notice--error"><?php echo esc_html( $error ); ?></div>
		<?php endforeach; ?>
		<?php if ( ! $cfg['abierto'] ) : ?>
			<p><?php esc_html_e( 'Orders are not being accepted right now.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
		<?php elseif ( ! $catalogo ) : ?>
			<p><?php esc_html_e( 'There is nothing on sale yet.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
		<?php else : ?>
			<p class="asde-muted"><?php esc_html_e( 'Choose what you want, leave your details and we will email you the order and how to pay for it.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
			<form method="post" class="asde-form asde-tienda-form">
				<?php wp_nonce_field( 'asde_tienda_invitado_comprar' ); ?>
				<input type="hidden" name="asde_action" value="tienda_invitado_comprar">
				<input type="hidden" name="grupo" value="<?php echo esc_attr( (string) $grupo['id'] ); ?>">
				<input type="hidden" name="clave" value="<?php echo esc_attr( $clave ); ?>">
				<input type="hidden" name="sello" value="<?php echo esc_attr( $sello ); ?>">
				<p class="asde-hp" aria-hidden="true" style="position:absolute;left:-9999px;"><label>Web <input type="text" name="web" tabindex="-1" autocomplete="off"></label></p>
				<?php
				$categoria = null;
				foreach ( $catalogo as $item ) :
					$prod = $item['producto'];
					if ( $prod['categoria'] !== $categoria ) {
						$categoria = $prod['categoria'];
						echo '' !== $categoria ? '<h4 class="asde-tienda__categoria">' . esc_html( $categoria ) . '</h4>' : '';
					}
					$img = Config::imagen( $prod['imagen'], 'thumbnail' );
					?>
					<div class="asde-producto">
						<?php if ( '' !== $img ) : ?>
							<img src="<?php echo esc_url( $img ); ?>" alt="" loading="lazy">
						<?php endif; ?>
						<div class="asde-producto__texto">
							<strong><?php echo esc_html( $prod['nombre'] ); ?></strong>
							<?php echo wp_kses( '' !== $prod['descripcion'] ? '<span class="asde-muted">' . esc_html( $prod['descripcion'] ) . '</span>' : '', asde_html_permitido() ); ?>
							<?php echo wp_kses( $prod['limite_persona'] > 0 ? '<span class="asde-muted">' . esc_html( sprintf( /* translators: %d: number */ __( 'Maximum %d per person', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $prod['limite_persona'] ) ) . '</span>' : '', asde_html_permitido() ); ?>
						</div>
						<?php echo wp_kses( \AsdeBalears\AreaPrivada\Frontend\TemplateLoader::render( 'tienda/lineas', array( 'prod' => $prod, 'variantes' => $item['variantes'], 'es_socio' => false, 'previas' => array_intersect_key( array_map( 'intval', (array) ( $valores['cant'] ?? array() ) ), array_flip( array_column( $item['variantes'], 'id' ) ) ), 'existencias' => false ) ), asde_html_permitido() ); ?>
					</div>
				<?php endforeach; ?>

				<h3><?php esc_html_e( 'Your details', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h3>
				<p>
					<label for="asde-tp-nombre"><?php esc_html_e( 'First name and surname', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
					<input type="text" id="asde-tp-nombre" name="nombre" value="<?php echo esc_attr( $val( 'nombre' ) ); ?>" maxlength="80" required autocomplete="name">
				</p>
				<p>
					<label for="asde-tp-email"><?php esc_html_e( 'Email', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
					<input type="email" id="asde-tp-email" name="email" value="<?php echo esc_attr( $val( 'email' ) ); ?>" maxlength="100" required autocomplete="email">
				</p>
				<p>
					<label for="asde-tp-tel"><?php esc_html_e( 'Phone (optional)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
					<input type="tel" id="asde-tp-tel" name="telefono" value="<?php echo esc_attr( $val( 'telefono' ) ); ?>" maxlength="20" autocomplete="tel">
				</p>
				<?php
				$forma_pago = TemplateLoader::render(
					'tienda/forma-pago',
					array(
						'cfg'         => $cfg,
						'es_socio'    => false,
						'obligatoria' => true,
					)
				);
				echo wp_kses( $forma_pago, asde_html_permitido() );
				?>
				<p>
					<label for="asde-tp-nota"><?php esc_html_e( 'Notes (optional)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
					<input type="text" id="asde-tp-nota" name="nota" value="<?php echo esc_attr( $val( 'nota' ) ); ?>" maxlength="255">
				</p>
				<p class="asde-help"><?php esc_html_e( 'We store your name, email and phone encrypted only to manage this order, and delete them 90 days after it is delivered or cancelled.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
				<p><button type="submit" class="button"><?php esc_html_e( 'Place the order', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button></p>
			</form>
		<?php endif; ?>
	<?php endif; ?>
</section>
