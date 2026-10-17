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
	<h2 class="asde-card__title"><?php echo '' !== $nombre_grupo ? esc_html( sprintf( asde_t( 'Tienda de %s' ), $nombre_grupo ) ) : esc_html( asde_t( 'Tienda' ) ); ?></h2>

	<?php if ( 'sin_enlace' === $modo ) : ?>
		<p><?php asde_e( 'Esta dirección no es válida o la tienda no está abierta ahora mismo. Pide el enlace al grupo.' ); ?></p>

	<?php elseif ( 'invalido' === $modo ) : ?>
		<p><?php asde_e( 'Este enlace no es válido o ya ha caducado.' ); ?></p>

	<?php elseif ( 'pedido' === $modo ) : ?>
		<?php
		$p      = $pedido;
		$estado = PedidosTable::estado( $p );
		?>
		<?php if ( $nuevo ) : ?>
			<div class="asde-notice asde-notice--success"><?php asde_e( '¡Pedido recibido! Te hemos mandado un email con este enlace: guárdalo para seguir tu pedido.' ); ?></div>
		<?php endif; ?>
		<?php foreach ( $errores as $error ) : ?>
			<div class="asde-notice asde-notice--error"><?php echo esc_html( $error ); ?></div>
		<?php endforeach; ?>
		<p>
			<strong><?php echo esc_html( PedidosTable::numero( $p ) ); ?></strong>
			<span class="asde-badge asde-badge--uniforme-<?php echo esc_attr( $estado ); ?>"><?php echo esc_html( asde_t( PedidosTable::ESTADOS[ $estado ] ) ); ?></span>
		</p>
		<p><?php echo esc_html( Servicio::descripcion( $p['lineas'] ) ); ?> — <strong><?php echo esc_html( null !== $p['pago_importe'] ? Calculadora::euros( $p['pago_importe'] ) : Servicio::importe( $p ) ); ?></strong></p>
		<?php if ( 'cancelado' === $estado ) : ?>
			<p class="asde-muted"><?php echo esc_html( sprintf( asde_t( 'Cancelado el %s.' ), $fecha( $p['cancelado_en'] ) ) ); ?></p>
		<?php else : ?>
			<ol class="asde-pasos">
				<li class="is-hecho"><span class="asde-pasos__marca" aria-hidden="true">✓</span> <?php asde_e( 'Solicitado' ); ?> <span class="asde-muted"><?php echo esc_html( $fecha( $p['creado'] ) ); ?></span></li>
				<li class="<?php echo null !== $p['pago_en'] ? 'is-hecho' : ''; ?>"><span class="asde-pasos__marca" aria-hidden="true"><?php echo null !== $p['pago_en'] ? '✓' : ''; ?></span> <?php asde_e( 'Pagado' ); ?><?php echo null !== $p['pago_en'] ? ' <span class="asde-muted">' . esc_html( $fecha( $p['pago_fecha'] ) ) . '</span>' : ''; // phpcs:ignore WordPress.Security.EscapeOutput ?></li>
				<li class="<?php echo null !== $p['entregado_en'] ? 'is-hecho' : ''; ?>"><span class="asde-pasos__marca" aria-hidden="true"><?php echo null !== $p['entregado_en'] ? '✓' : ''; ?></span> <?php asde_e( 'Entregado' ); ?><?php echo null !== $p['entregado_en'] ? ' <span class="asde-muted">' . esc_html( $fecha( $p['entregado_en'] ) ) . '</span>' : ''; // phpcs:ignore WordPress.Security.EscapeOutput ?></li>
			</ol>
			<?php if ( null === $p['pago_en'] && $pagar ) : ?>
				<h3><?php asde_e( 'Cómo pagarlo' ); ?></h3>
				<?php foreach ( $pagar as $linea ) : ?>
					<p><?php echo esc_html( $linea ); ?></p>
				<?php endforeach; ?>
				<?php if ( '' !== trim( $cfg['texto_pago'] ) ) : ?>
					<div class="asde-notice asde-notice--info"><?php echo wp_kses_post( wpautop( $cfg['texto_pago'] ) ); ?></div>
				<?php endif; ?>
				<?php if ( $cfg['dias_sin_pagar'] > 0 ) : ?>
					<p class="asde-help"><?php echo esc_html( sprintf( asde_t( 'Si no se paga en %d días, el pedido se cancela y los artículos vuelven a la tienda.' ), (int) $cfg['dias_sin_pagar'] ) ); ?></p>
				<?php endif; ?>
			<?php endif; ?>
			<?php if ( null === $p['pago_en'] && null === $p['entregado_en'] ) : ?>
				<form method="post" class="asde-pedido__accion" data-asde-confirmar="<?php echo esc_attr( asde_js( '¿Cancelar el pedido %s?', PedidosTable::numero( $p ) ) ); ?>">
					<?php wp_nonce_field( 'asde_tienda_invitado_cancelar' ); ?>
					<input type="hidden" name="asde_action" value="tienda_invitado_cancelar">
					<input type="hidden" name="pedido" value="<?php echo (int) $p['id']; ?>">
					<input type="hidden" name="t" value="<?php echo esc_attr( $token ); ?>">
					<button type="submit" class="asde-btn-link asde-btn-danger"><?php asde_e( 'Cancelar el pedido' ); ?></button>
				</form>
			<?php endif; ?>
		<?php endif; ?>

	<?php else : ?>
		<?php foreach ( $errores as $error ) : ?>
			<div class="asde-notice asde-notice--error"><?php echo esc_html( $error ); ?></div>
		<?php endforeach; ?>
		<?php if ( ! $cfg['abierto'] ) : ?>
			<p><?php asde_e( 'Ahora mismo no se aceptan pedidos.' ); ?></p>
		<?php elseif ( ! $catalogo ) : ?>
			<p><?php asde_e( 'Todavía no hay nada a la venta.' ); ?></p>
		<?php else : ?>
			<p class="asde-muted"><?php asde_e( 'Elige lo que quieres, deja tus datos y te mandamos por email el pedido y cómo pagarlo.' ); ?></p>
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
							<?php echo '' !== $prod['descripcion'] ? '<span class="asde-muted">' . esc_html( $prod['descripcion'] ) . '</span>' : ''; // phpcs:ignore WordPress.Security.EscapeOutput ?>
							<?php echo $prod['limite_persona'] > 0 ? '<span class="asde-muted">' . esc_html( sprintf( asde_t( 'Máximo %d por persona' ), $prod['limite_persona'] ) ) . '</span>' : ''; // phpcs:ignore WordPress.Security.EscapeOutput ?>
						</div>
						<ul class="asde-producto__variantes">
							<?php foreach ( $item['variantes'] as $v ) : ?>
								<?php
								$precio = Servicio::precio_variante( $prod, $v, false );
								$hay    = VariantesTable::hay( $v );
								$pedida = (int) ( $valores['cant'][ $v['id'] ] ?? 0 );
								?>
								<li>
									<label for="asde-var-<?php echo (int) $v['id']; ?>">
										<?php echo 'Única' === $v['etiqueta'] ? '' : esc_html( VariantesTable::etiqueta( $v ) ) . ' · '; // phpcs:ignore WordPress.Security.EscapeOutput ?>
										<?php echo null === $precio ? esc_html( asde_t( 'Precio por confirmar' ) ) : esc_html( Calculadora::euros( $precio ) ); ?>
										<?php if ( ! $hay ) : ?>
											<span class="asde-muted">(<?php asde_e( 'Agotado' ); ?>)</span>
										<?php endif; ?>
									</label>
									<input type="number" id="asde-var-<?php echo (int) $v['id']; ?>" name="cant[<?php echo (int) $v['id']; ?>]" value="<?php echo (int) $pedida; ?>" min="0" max="<?php echo (int) \AsdeBalears\AreaPrivada\Tienda\ProductosTable::MAX_LIMITE; ?>" step="1" inputmode="numeric" <?php echo $hay ? '' : 'disabled'; ?> aria-label="<?php echo esc_attr( sprintf( asde_t( 'Cantidad: %s' ), $prod['nombre'] . ( 'Única' === $v['etiqueta'] ? '' : ' ' . VariantesTable::etiqueta( $v ) ) ) ); ?>">
								</li>
							<?php endforeach; ?>
						</ul>
					</div>
				<?php endforeach; ?>

				<h3><?php asde_e( 'Tus datos' ); ?></h3>
				<p>
					<label for="asde-tp-nombre"><?php asde_e( 'Nombre y apellidos' ); ?></label>
					<input type="text" id="asde-tp-nombre" name="nombre" value="<?php echo esc_attr( $val( 'nombre' ) ); ?>" maxlength="80" required autocomplete="name">
				</p>
				<p>
					<label for="asde-tp-email"><?php asde_e( 'Email' ); ?></label>
					<input type="email" id="asde-tp-email" name="email" value="<?php echo esc_attr( $val( 'email' ) ); ?>" maxlength="100" required autocomplete="email">
				</p>
				<p>
					<label for="asde-tp-tel"><?php asde_e( 'Teléfono (opcional)' ); ?></label>
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
				echo $forma_pago; // phpcs:ignore WordPress.Security.EscapeOutput -- HTML de una plantilla propia, ya escapado.
				?>
				<p>
					<label for="asde-tp-nota"><?php asde_e( 'Observaciones (opcional)' ); ?></label>
					<input type="text" id="asde-tp-nota" name="nota" value="<?php echo esc_attr( $val( 'nota' ) ); ?>" maxlength="255">
				</p>
				<p class="asde-help"><?php asde_e( 'Guardamos tu nombre, email y teléfono cifrados solo para gestionar este pedido, y los borramos a los 90 días de entregarlo o cancelarlo.' ); ?></p>
				<p><button type="submit" class="button"><?php asde_e( 'Hacer el pedido' ); ?></button></p>
			</form>
		<?php endif; ?>
	<?php endif; ?>
</section>
