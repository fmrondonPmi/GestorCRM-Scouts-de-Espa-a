<?php
/**
 * Uniforme · mis pedidos y nuevo pedido.
 *
 * @var array                                              $cfg
 * @var array<string, AsdeBalears\AreaPrivada\Domain\Persona> $para    Para quién puede pedir (nº => persona)
 * @var array[]                                            $pedidos
 * @var AsdeBalears\AreaPrivada\Auth\Ambito                $ambito
 * @var array<string, array{nombre:string, url:string}>   $guias   Guías de tallas por modelo
 * @var string                                             $colocar Imagen «dónde van las insignias» ('' si no hay)
 * @var string                                             $url
 * @var string                                             $url_pagos
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Tesoreria\Calculadora;
use AsdeBalears\AreaPrivada\Uniformes\Config;
use AsdeBalears\AreaPrivada\Uniformes\PedidosTable;
use AsdeBalears\AreaPrivada\Uniformes\Servicio;

defined( 'ABSPATH' ) || exit;

$fecha     = static fn( ?string $f ) => $f ? wp_date( 'd/m/Y', strtotime( $f . ( strlen( $f ) > 10 ? ' UTC' : '' ) ) ) : '';
$insignias = Config::insignias_activas( $cfg );
?>
<section class="asde-card">
	<h2 class="asde-card__title"><?php asde_e( 'Uniforme' ); ?></h2>
	<p>
		<?php asde_e( 'Pide aquí la' ); ?> <strong><?php asde_e( 'camisa scout' ); ?></strong> (<?php echo esc_html( Calculadora::euros( $cfg['precio_camisa'] ) ); ?>)<?php echo $insignias ? ' ' . wp_kses_post( asde_h( 'y las <strong>insignias</strong>' ) ) : ''; ?><?php asde_e( '. Después, págalo en efectivo o con un ingreso en la cuenta del grupo, indicando el nº de pedido. Cuando Tesorería lo reciba lo verás aquí como pagado; el grupo lo incluirá en su pedido al proveedor y te lo entregará. Cuando lo tengas, confírmalo aquí.' ); ?>
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
						<form method="post" class="asde-pedido__accion" onsubmit="return confirm(<?php echo esc_attr( asde_js( '¿Cancelar el pedido %s?', PedidosTable::numero( $p ) ) ); ?>);">
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

<section class="asde-card">
	<h3 class="asde-card__title"><?php asde_e( 'Nuevo pedido' ); ?></h3>
	<?php if ( ! $cfg['abierto'] ) : ?>
		<p><?php asde_e( 'Ahora mismo no se aceptan pedidos de uniforme. El grupo avisará cuando se abra el próximo.' ); ?></p>
	<?php else : ?>
		<form method="post" class="asde-form asde-uniforme-form" data-asde-uniforme>
			<?php wp_nonce_field( 'asde_uniforme_pedir' ); ?>
			<input type="hidden" name="asde_action" value="uniforme_pedir">

			<?php if ( count( $para ) > 1 ) : ?>
				<fieldset>
					<legend><?php asde_e( '¿Para quién?' ); ?></legend>
					<?php $primero = true; ?>
					<?php foreach ( $para as $n => $persona ) : ?>
						<label class="asde-choice"><input type="radio" name="socio" value="<?php echo esc_attr( $n ); ?>" <?php checked( $primero ); ?>><span><?php echo esc_html( $persona->nombre_completo() ); ?></span></label>
						<?php $primero = false; ?>
					<?php endforeach; ?>
				</fieldset>
			<?php else : ?>
				<?php $persona = reset( $para ); ?>
				<input type="hidden" name="socio" value="<?php echo esc_attr( (string) key( $para ) ); ?>">
				<p><?php asde_e( 'Para:' ); ?> <strong><?php echo esc_html( $persona->nombre_completo() ); ?></strong></p>
			<?php endif; ?>

			<fieldset>
				<legend><?php asde_e( 'Camisa scout' ); ?></legend>
				<label class="asde-choice"><input type="checkbox" name="con_camisa" value="1" checked data-precio="<?php echo esc_attr( (string) $cfg['precio_camisa'] ); ?>"><span><?php asde_e( 'Quiero la camisa (' ); ?><?php echo esc_html( Calculadora::euros( $cfg['precio_camisa'] ) ); ?>)</span></label>
				<p>
					<label for="asde-uni-talla"><?php asde_e( 'Modelo y talla' ); ?></label>
					<select id="asde-uni-talla" name="camisa">
						<option value=""><?php asde_e( 'Elige la talla…' ); ?></option>
						<?php foreach ( $cfg['modelos'] as $mid => $m ) : ?>
							<optgroup label="<?php echo esc_attr( $m['nombre'] ); ?>">
								<?php foreach ( $m['tallas'] as $t ) : ?>
									<option value="<?php echo esc_attr( $mid . '|' . $t ); ?>"><?php echo esc_html( $m['nombre'] . ' · ' . $t . ( Config::es_especial( $cfg, $t ) ? asde_t( ' (talla especial)' ) : '' ) ); ?></option>
								<?php endforeach; ?>
							</optgroup>
						<?php endforeach; ?>
					</select>
				</p>
				<?php if ( $guias ) : ?>
					<?php // Las tablas de medidas se abren encima del pedido (uniforme.js), sin salir de la página; sin JavaScript, en otra pestaña. ?>
					<p class="asde-guia-enlaces">
						<strong><?php asde_e( 'Tabla de medidas:' ); ?></strong>
						<?php foreach ( $guias as $mid => $g ) : ?>
							<a href="<?php echo esc_url( $g['url'] ); ?>" target="_blank" rel="noopener" data-asde-imagen data-modelo="<?php echo esc_attr( (string) $mid ); ?>" data-titulo="<?php echo esc_attr( asde_t( 'Medidas de la ' ) . mb_strtolower( $g['nombre'] ) ); ?>"><?php echo esc_html( $g['nombre'] ); ?></a>
						<?php endforeach; ?>
					</p>
				<?php endif; ?>
			</fieldset>

			<?php if ( $insignias ) : ?>
				<fieldset>
					<legend><?php asde_e( 'Insignias' ); ?></legend>
					<div class="asde-insignias">
						<?php foreach ( $insignias as $iid => $i ) : ?>
							<?php $img = Config::imagen( $i['imagen'], 'thumbnail' ); ?>
							<div class="asde-insignia">
								<?php if ( '' !== $img ) : ?>
									<img src="<?php echo esc_url( $img ); ?>" alt="" loading="lazy">
								<?php endif; ?>
								<div class="asde-insignia__texto">
									<label for="asde-uni-<?php echo esc_attr( $iid ); ?>"><strong><?php echo esc_html( $i['nombre'] ); ?></strong></label>
									<?php echo '' !== $i['lugar'] ? '<span class="asde-muted">' . esc_html( $i['lugar'] ) . '</span>' : ''; ?>
									<span><?php echo null === $i['precio'] ? esc_html( asde_t( 'Precio por confirmar' ) ) : esc_html( sprintf( asde_t( '%s cada una' ), Calculadora::euros( $i['precio'] ) ) ); ?></span>
								</div>
								<input type="number" id="asde-uni-<?php echo esc_attr( $iid ); ?>" name="insignia_<?php echo esc_attr( $iid ); ?>" value="0" min="0" max="<?php echo (int) Config::MAX_INSIGNIAS; ?>" step="1" inputmode="numeric" data-precio="<?php echo esc_attr( null === $i['precio'] ? '' : (string) $i['precio'] ); ?>" aria-label="<?php echo esc_attr( sprintf( asde_t( 'Cantidad: %s' ), $i['nombre'] ) ); ?>">
							</div>
						<?php endforeach; ?>
					</div>
					<?php if ( '' !== $colocar ) : ?>
						<p class="asde-guia-enlaces">
							<a href="<?php echo esc_url( $colocar ); ?>" target="_blank" rel="noopener" data-asde-imagen data-titulo="<?php echo esc_attr( asde_a( 'Dónde va cada insignia en la camisa scout' ) ); ?>"><?php asde_e( 'Ver dónde va cada insignia' ); ?></a>
						</p>
					<?php endif; ?>
				</fieldset>
			<?php endif; ?>

			<p>
				<label for="asde-uni-nota"><?php asde_e( 'Observaciones (opcional)' ); ?></label>
				<input type="text" id="asde-uni-nota" name="nota" maxlength="255">
			</p>
			<p class="asde-uniforme-total"><?php asde_e( 'Total:' ); ?> <strong data-asde-total><?php echo esc_html( Calculadora::euros( $cfg['precio_camisa'] ) ); ?></strong> <span class="asde-muted" data-asde-total-nota hidden><?php asde_e( '+ insignias con el precio por confirmar' ); ?></span></p>
			<p><button type="submit" class="button"><?php asde_e( 'Enviar el pedido' ); ?></button></p>
		</form>
	<?php endif; ?>
</section>
