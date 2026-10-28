<?php
/**
 * Tienda · mis pedidos y catálogo para comprar.
 *
 * @var array                                              $cfg
 * @var array<string, AsdeBalears\AreaPrivada\Domain\Persona> $para    Para quién puede pedir (nº => persona)
 * @var array[]                                            $pedidos
 * @var array[]                                            $catalogo v0.39: productos de la tienda que puede comprar (producto, variantes)
 * @var bool                                               $es_socio v0.39: si alguna de sus personas es socio/a (productos «solo socios» y pago en físico)
 * @var AsdeBalears\AreaPrivada\Auth\Ambito                $ambito
 * @var string                                             $url
 * @var string                                             $url_pagos
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Frontend\TemplateLoader;
use AsdeBalears\AreaPrivada\Tesoreria\Calculadora;
use AsdeBalears\AreaPrivada\Tienda\PedidosTable;
use AsdeBalears\AreaPrivada\Tienda\Servicio;

defined( 'ABSPATH' ) || exit;

$fecha     = static fn( ?string $f ) => $f ? wp_date( 'd/m/Y', strtotime( $f . ( strlen( $f ) > 10 ? ' UTC' : '' ) ) ) : '';
?>
<section class="asde-card">
	<h2 class="asde-card__title"><?php esc_html_e( 'Shop', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h2>
	<p>
		<?php esc_html_e( 'Choose below what you want to buy. Then pay by transfer, by Bizum or, if you are a member, in person to Treasury, stating the order no. When Treasury receives it you will see it here as paid; the group will hand it over to you and, when you have it, confirm it here.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?>
	</p>
	<?php if ( '' !== trim( $cfg['texto_pago'] ) ) : ?>
		<div class="asde-notice asde-notice--info"><?php echo wp_kses_post( wpautop( $cfg['texto_pago'] ) ); ?></div>
	<?php endif; ?>
	<p class="asde-muted"><?php esc_html_e( 'What you have paid you can see in', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <a href="<?php echo esc_url( $url_pagos ); ?>"><?php esc_html_e( 'My payments', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a>.</p>
</section>

<?php if ( $pedidos ) : ?>
	<section class="asde-card">
		<h3 class="asde-card__title"><?php esc_html_e( 'Your orders', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h3>
		<div class="asde-pedidos">
			<?php foreach ( $pedidos as $p ) : ?>
				<?php
				$persona = $para[ $p['socio'] ] ?? null;
				$estado  = PedidosTable::estado( $p );
				$pasos   = array(
					array( __( 'Requested', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), true, $fecha( $p['creado'] ) ),
					array( __( 'Paid', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), null !== $p['pago_en'], null !== $p['pago_en'] ? $fecha( $p['pago_fecha'] ) . ' · ' . ( asde_t( PedidosTable::METODOS[ $p['pago_metodo'] ] ?? '' ) ) : '' ),
					array( __( 'In the group\'s order', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), null !== $p['pedido_en'], $fecha( $p['pedido_en'] ) ),
					array( __( 'Delivered', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), null !== $p['entregado_en'], $fecha( $p['entregado_en'] ) ),
					array( __( 'Received', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), null !== $p['recibido_en'], $fecha( $p['recibido_en'] ) ),
				);
				?>
				<article class="asde-pedido<?php echo 'cancelado' === $estado ? ' asde-pedido--cancelado' : ''; ?>">
					<header class="asde-pedido__cabecera">
						<strong><?php echo esc_html( PedidosTable::numero( $p ) ); ?></strong>
						<?php echo $persona ? ' · ' . esc_html( $persona->nombre_completo() ) : ''; ?>
						<span class="asde-badge asde-badge--uniforme-<?php echo esc_attr( $estado ); ?>"><?php echo esc_html( asde_t( PedidosTable::ESTADOS[ $estado ] ) ); ?></span>
					</header>
					<p class="asde-pedido__contenido"><?php echo esc_html( Servicio::descripcion( $p['lineas'] ) ); ?> — <strong><?php echo esc_html( null !== $p['pago_importe'] ? Calculadora::euros( $p['pago_importe'] ) : Servicio::importe( $p ) ); ?></strong></p>
					<?php if ( 'cancelado' !== $estado && null === $p['pago_en'] && '' !== $p['forma_pago'] ) : ?>
						<?php foreach ( Servicio::instrucciones_pago( $p, $cfg ) as $linea ) : ?>
							<p class="asde-help"><?php echo esc_html( $linea ); ?></p>
						<?php endforeach; ?>
					<?php endif; ?>
					<?php if ( 'cancelado' === $estado ) : ?>
						<p class="asde-muted"><?php esc_html_e( 'Cancelled on', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <?php echo esc_html( $fecha( $p['cancelado_en'] ) ); ?>.</p>
					<?php else : ?>
						<ol class="asde-pasos">
							<?php foreach ( $pasos as list( $texto, $hecho, $detalle ) ) : ?>
								<li class="<?php echo $hecho ? 'is-hecho' : ''; ?>"><span class="asde-pasos__marca" aria-hidden="true"><?php echo $hecho ? '✓' : ''; ?></span> <?php echo esc_html( $texto ); ?><?php echo $hecho && '' !== $detalle ? ' <span class="asde-muted">' . esc_html( $detalle ) . '</span>' : ''; ?><span class="screen-reader-text"><?php echo $hecho ? ': sí' : esc_html( __( ': not yet', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?></span></li>
							<?php endforeach; ?>
						</ol>
					<?php endif; ?>
					<?php if ( Servicio::puede_confirmar( $p, $ambito ) ) : ?>
						<form method="post" class="asde-pedido__accion">
							<?php wp_nonce_field( 'asde_uniforme_' . $p['id'] ); ?>
							<input type="hidden" name="asde_action" value="uniforme_recibido">
							<input type="hidden" name="pedido" value="<?php echo (int) $p['id']; ?>">
							<button type="submit" class="button"><?php esc_html_e( 'I have received it', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
							<span class="asde-help"><?php esc_html_e( 'The group has marked it as delivered: confirm it when you have it.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span>
						</form>
					<?php elseif ( Servicio::puede_cancelar_propio( $p, $ambito ) ) : ?>
						<form method="post" class="asde-pedido__accion" data-asde-confirmar="<?php echo esc_attr( sprintf( /* translators: %s: text */ __( 'Cancel order %s?', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), PedidosTable::numero( $p ) ) ); ?>">
							<?php wp_nonce_field( 'asde_uniforme_' . $p['id'] ); ?>
							<input type="hidden" name="asde_action" value="uniforme_cancelar">
							<input type="hidden" name="pedido" value="<?php echo (int) $p['id']; ?>">
							<button type="submit" class="asde-btn-link asde-btn-danger"><?php esc_html_e( 'Cancel the order', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
						</form>
					<?php endif; ?>
				</article>
			<?php endforeach; ?>
		</div>
	</section>
<?php endif; ?>

<?php if ( $catalogo ) : ?>
	<?php echo wp_kses( TemplateLoader::render( 'tienda/catalogo', array( 'catalogo' => $catalogo, 'cfg' => $cfg, 'para' => $para, 'es_socio' => $es_socio ) ), asde_html_permitido() ); ?>
<?php endif; ?>
