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
	<h2 class="asde-card__title"><?php asde_e( 'Tienda' ); ?></h2>
	<p>
		<?php asde_e( 'Elige más abajo lo que quieres comprar. Después, págalo por transferencia, por Bizum o, si eres socio o socia, en físico a Tesorería, indicando el nº de pedido. Cuando Tesorería lo reciba lo verás aquí como pagado; el grupo te lo entregará y, cuando lo tengas, confírmalo aquí.' ); ?>
	</p>
	<?php if ( '' !== trim( $cfg['texto_pago'] ) ) : ?>
		<div class="asde-notice asde-notice--info"><?php echo wp_kses_post( wpautop( $cfg['texto_pago'] ) ); ?></div>
	<?php endif; ?>
	<p class="asde-muted"><?php asde_e( 'Lo que has pagado lo ves en' ); ?> <a href="<?php echo esc_url( $url_pagos ); ?>"><?php asde_e( 'Mis pagos' ); ?></a>.</p>
</section>

<?php if ( $pedidos ) : ?>
	<section class="asde-card">
		<h3 class="asde-card__title"><?php asde_e( 'Tus pedidos' ); ?></h3>
		<div class="asde-pedidos">
			<?php foreach ( $pedidos as $p ) : ?>
				<?php
				$persona = $para[ $p['socio'] ] ?? null;
				$estado  = PedidosTable::estado( $p );
				$pasos   = array(
					array( asde_t( 'Solicitado' ), true, $fecha( $p['creado'] ) ),
					array( asde_t( 'Pagado' ), null !== $p['pago_en'], null !== $p['pago_en'] ? $fecha( $p['pago_fecha'] ) . ' · ' . ( asde_t( PedidosTable::METODOS[ $p['pago_metodo'] ] ?? '' ) ) : '' ),
					array( asde_t( 'En el pedido del grupo' ), null !== $p['pedido_en'], $fecha( $p['pedido_en'] ) ),
					array( asde_t( 'Entregado' ), null !== $p['entregado_en'], $fecha( $p['entregado_en'] ) ),
					array( asde_t( 'Recibido' ), null !== $p['recibido_en'], $fecha( $p['recibido_en'] ) ),
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
						<p class="asde-muted"><?php asde_e( 'Cancelado el' ); ?> <?php echo esc_html( $fecha( $p['cancelado_en'] ) ); ?>.</p>
					<?php else : ?>
						<ol class="asde-pasos">
							<?php foreach ( $pasos as list( $texto, $hecho, $detalle ) ) : ?>
								<li class="<?php echo $hecho ? 'is-hecho' : ''; ?>"><span class="asde-pasos__marca" aria-hidden="true"><?php echo $hecho ? '✓' : ''; ?></span> <?php echo esc_html( $texto ); ?><?php echo $hecho && '' !== $detalle ? ' <span class="asde-muted">' . esc_html( $detalle ) . '</span>' : ''; ?><span class="screen-reader-text"><?php echo $hecho ? ': sí' : esc_html( asde_t( ': todavía no' ) ); ?></span></li>
							<?php endforeach; ?>
						</ol>
					<?php endif; ?>
					<?php if ( Servicio::puede_confirmar( $p, $ambito ) ) : ?>
						<form method="post" class="asde-pedido__accion">
							<?php wp_nonce_field( 'asde_uniforme_' . $p['id'] ); ?>
							<input type="hidden" name="asde_action" value="uniforme_recibido">
							<input type="hidden" name="pedido" value="<?php echo (int) $p['id']; ?>">
							<button type="submit" class="button"><?php asde_e( 'Lo he recibido' ); ?></button>
							<span class="asde-help"><?php asde_e( 'El grupo lo ha marcado como entregado: confírmalo cuando lo tengas.' ); ?></span>
						</form>
					<?php elseif ( Servicio::puede_cancelar_propio( $p, $ambito ) ) : ?>
						<form method="post" class="asde-pedido__accion" data-asde-confirmar="<?php echo esc_attr( asde_js( '¿Cancelar el pedido %s?', PedidosTable::numero( $p ) ) ); ?>">
							<?php wp_nonce_field( 'asde_uniforme_' . $p['id'] ); ?>
							<input type="hidden" name="asde_action" value="uniforme_cancelar">
							<input type="hidden" name="pedido" value="<?php echo (int) $p['id']; ?>">
							<button type="submit" class="asde-btn-link asde-btn-danger"><?php asde_e( 'Cancelar el pedido' ); ?></button>
						</form>
					<?php endif; ?>
				</article>
			<?php endforeach; ?>
		</div>
	</section>
<?php endif; ?>

<?php if ( $catalogo ) : ?>
	<?php echo TemplateLoader::render( 'tienda/catalogo', array( 'catalogo' => $catalogo, 'cfg' => $cfg, 'para' => $para, 'es_socio' => $es_socio ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
<?php endif; ?>
