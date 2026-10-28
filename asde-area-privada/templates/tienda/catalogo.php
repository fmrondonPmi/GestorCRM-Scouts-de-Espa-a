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
	<h3 class="asde-card__title"><?php esc_html_e( 'Shop', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h3>
	<?php if ( ! $cfg['abierto'] ) : ?>
		<p><?php esc_html_e( 'Orders are not being accepted right now.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
	<?php else : ?>
		<form method="post" class="asde-form asde-tienda-form">
			<?php wp_nonce_field( 'asde_tienda_comprar' ); ?>
			<input type="hidden" name="asde_action" value="tienda_comprar">

			<?php if ( count( $para ) > 1 ) : ?>
				<p>
					<label for="asde-tienda-socio"><?php esc_html_e( 'For whom?', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
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
						<?php echo wp_kses( 'socios' === $prod['visibilidad'] ? '<span class="asde-badge">' . esc_html( __( 'Members only', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) . '</span>' : '', asde_html_permitido() ); ?>
						<?php echo wp_kses( '' !== $prod['descripcion'] ? '<span class="asde-muted">' . esc_html( $prod['descripcion'] ) . '</span>' : '', asde_html_permitido() ); ?>
						<?php echo wp_kses( $prod['limite_persona'] > 0 ? '<span class="asde-muted">' . esc_html( sprintf( /* translators: %d: number */ __( 'Maximum %d per person', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $prod['limite_persona'] ) ) . '</span>' : '', asde_html_permitido() ); ?>
					</div>
					<?php echo wp_kses( TemplateLoader::render( 'tienda/lineas', array( 'prod' => $prod, 'variantes' => $item['variantes'], 'es_socio' => $es_socio, 'previas' => array(), 'existencias' => true ) ), asde_html_permitido() ); ?>
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
			echo wp_kses( $forma_pago, asde_html_permitido() );
			?>
			<p>
				<label for="asde-tienda-nota"><?php esc_html_e( 'Notes (optional)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
				<input type="text" id="asde-tienda-nota" name="nota" maxlength="255">
			</p>
			<p><button type="submit" class="button"><?php esc_html_e( 'Place the order', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button></p>
		</form>
	<?php endif; ?>
</section>
