<?php
/**
 * Tienda · catálogo y carrito (v0.39, doc 48, fase 3): los productos que puede comprar esta cuenta, con la cantidad
 * de cada variante, y el pedido se hace de una vez.
 *
 * @var array[]                                               $catalogo Productos visibles con sus variantes (producto, variantes)
 * @var array                                                 $cfg
 * @var array<string, AsdeBalears\AreaPrivada\Domain\Persona> $para     Para quién puede pedir (nº => persona)
 * @var bool                                                  $es_socio
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Frontend\TemplateLoader;
use AsdeBalears\AreaPrivada\Tienda\Config;

defined( 'ABSPATH' ) || exit;

$categoria = null;
?>
<section class="asde-card asde-tienda">
	<h3 class="asde-card__title"><?php asde_e( 'Tienda' ); ?></h3>
	<?php if ( ! $cfg['abierto'] ) : ?>
		<p><?php asde_e( 'Ahora mismo no se aceptan pedidos.' ); ?></p>
	<?php else : ?>
		<form method="post" class="asde-form asde-tienda-form">
			<?php wp_nonce_field( 'asde_tienda_comprar' ); ?>
			<input type="hidden" name="asde_action" value="tienda_comprar">

			<?php if ( count( $para ) > 1 ) : ?>
				<p>
					<label for="asde-tienda-socio"><?php asde_e( '¿Para quién?' ); ?></label>
					<select id="asde-tienda-socio" name="socio">
						<?php foreach ( $para as $n => $persona ) : ?>
							<option value="<?php echo esc_attr( (string) $n ); ?>"><?php echo esc_html( $persona->nombre_completo() ); ?></option>
						<?php endforeach; ?>
					</select>
				</p>
			<?php else : ?>
				<input type="hidden" name="socio" value="<?php echo esc_attr( (string) key( $para ) ); ?>">
			<?php endif; ?>

			<?php foreach ( $catalogo as $item ) : ?>
				<?php
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
						<?php echo 'socios' === $prod['visibilidad'] ? '<span class="asde-badge">' . esc_html( asde_t( 'Solo socios' ) ) . '</span>' : ''; // phpcs:ignore WordPress.Security.EscapeOutput ?>
						<?php echo '' !== $prod['descripcion'] ? '<span class="asde-muted">' . esc_html( $prod['descripcion'] ) . '</span>' : ''; // phpcs:ignore WordPress.Security.EscapeOutput ?>
						<?php echo $prod['limite_persona'] > 0 ? '<span class="asde-muted">' . esc_html( sprintf( asde_t( 'Máximo %d por persona' ), $prod['limite_persona'] ) ) . '</span>' : ''; // phpcs:ignore WordPress.Security.EscapeOutput ?>
					</div>
					<?php echo TemplateLoader::render( 'tienda/lineas', array( 'prod' => $prod, 'variantes' => $item['variantes'], 'es_socio' => $es_socio, 'previas' => array(), 'existencias' => true ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- HTML de una plantilla propia, ya escapado. ?>
				</div>
			<?php endforeach; ?>

			<?php
			$forma_pago = TemplateLoader::render(
				'tienda/forma-pago',
				array(
					'cfg'         => $cfg,
					'es_socio'    => $es_socio,
					'obligatoria' => true,
				)
			);
			echo $forma_pago; // phpcs:ignore WordPress.Security.EscapeOutput -- HTML de una plantilla propia, ya escapado.
			?>
			<p>
				<label for="asde-tienda-nota"><?php asde_e( 'Observaciones (opcional)' ); ?></label>
				<input type="text" id="asde-tienda-nota" name="nota" maxlength="255">
			</p>
			<p><button type="submit" class="button"><?php asde_e( 'Hacer el pedido' ); ?></button></p>
		</form>
	<?php endif; ?>
</section>
