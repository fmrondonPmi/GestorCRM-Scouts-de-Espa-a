<?php
/**
 * Gestión de uniformes · asignar una camisa del inventario a quien no la ha pedido (v0.29).
 * Se abre en la ventana emergente desde «Camisas del inventario»; sin JavaScript, en su página.
 *
 * @var AsdeBalears\AreaPrivada\Domain\Persona[] $personas  Socios/as con membresía activa que gestiona
 * @var array<string, array<string, int>>        $stock     modelo => talla => unidades
 * @var array                                    $cfg       Configuración de uniformes
 * @var string                                   $socio     Persona elegida de antemano (?socio=)
 * @var string                                   $url
 * @var string                                   $url_lista
 * @var string                                   $url_inv
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Domain\Secciones;
use AsdeBalears\AreaPrivada\Tienda\Config;

defined( 'ABSPATH' ) || exit;

// Por sección, y dentro por apellidos (como en el resto del área).
$por_seccion = array();
foreach ( $personas as $p ) {
	$por_seccion[ $p->secciones[0] ?? '' ][] = $p;
}
$orden = array_flip( array_keys( Secciones::all() ) );
uksort( $por_seccion, static fn( $x, $y ) => ( $orden[ $x ] ?? 99 ) <=> ( $orden[ $y ] ?? 99 ) );
?>
<p class="asde-back asde-solo-pagina"><a href="<?php echo esc_url( $url_lista ); ?>"><?php asde_e( '← Gestión de pedidos' ); ?></a></p>
<section class="asde-card">
	<h2 class="asde-card__title"><?php asde_e( 'Asignar una camisa del inventario' ); ?></h2>
	<p class="asde-muted"><?php asde_e( 'Para dar a alguien una camisa que el grupo ya tiene guardada, sin que la haya pedido: se crea su pedido, ya servido del inventario, y se descuenta la camisa. Después se entrega y se cobra como cualquier otro pedido.' ); ?></p>

	<?php if ( ! $stock ) : ?>
		<p><?php asde_e( 'No hay camisas en el inventario.' ); ?> <a href="<?php echo esc_url( $url_inv ); ?>"><?php asde_e( 'Añádelas en el inventario' ); ?></a> <?php asde_e( '(tipo «Camisa uniforme», con su modelo y su talla).' ); ?></p>
	<?php else : ?>
		<form method="post" action="<?php echo esc_url( $url ); ?>" class="asde-form">
			<?php wp_nonce_field( 'asde_uniformes_asignar' ); ?>
			<input type="hidden" name="asde_action" value="uniforme_asignar">
			<p>
				<label for="asde-asig-socio"><?php asde_e( 'Para quién' ); ?> <span class="asde-obligatorio" aria-hidden="true">*</span></label>
				<select id="asde-asig-socio" name="socio" required>
					<option value=""><?php asde_e( 'Elige…' ); ?></option>
					<?php foreach ( $por_seccion as $s => $lista ) : ?>
						<optgroup label="<?php echo esc_attr( '' !== $s ? Secciones::nombre( $s ) : asde_t( 'Sin sección' ) ); ?>">
							<?php foreach ( $lista as $p ) : ?>
								<option value="<?php echo esc_attr( $p->num_socio_asde ); ?>" <?php selected( $socio, $p->num_socio_asde ); ?>><?php echo esc_html( $p->apellidos . ', ' . $p->nombre ); ?></option>
							<?php endforeach; ?>
						</optgroup>
					<?php endforeach; ?>
				</select>
			</p>
			<p>
				<label for="asde-asig-camisa"><?php asde_e( 'Camisa' ); ?> <span class="asde-obligatorio" aria-hidden="true">*</span></label>
				<select id="asde-asig-camisa" name="camisa" required>
					<option value=""><?php asde_e( 'Elige…' ); ?></option>
					<?php foreach ( $stock as $modelo => $tallas ) : ?>
						<optgroup label="<?php echo esc_attr( Config::nombre_modelo( $cfg, (string) $modelo ) ); ?>">
							<?php foreach ( (array) ( $cfg['modelos'][ $modelo ]['tallas'] ?? array_keys( $tallas ) ) as $t ) : ?>
								<?php if ( ! empty( $tallas[ $t ] ) ) : ?>
									<?php /* translators: 1: talla, 2: unidades que quedan */ ?>
									<option value="<?php echo esc_attr( $modelo . '|' . $t ); ?>"><?php echo esc_html( sprintf( asde_n( 'Talla %1$s · queda %2$d', 'Talla %1$s · quedan %2$d', (int) $tallas[ $t ] ), $t, (int) $tallas[ $t ] ) ); ?></option>
								<?php endif; ?>
							<?php endforeach; ?>
						</optgroup>
					<?php endforeach; ?>
				</select>
			</p>
			<div class="asde-form__columnas">
				<p>
					<?php /* translators: %s: precio de la camisa */ ?>
					<span class="asde-help"><?php echo esc_html( sprintf( $cfg['primera_gratis'] ? asde_t( 'Precio: %s. A un scouter, su primera camisa es gratis (el pedido queda pagado «sin coste»); desde la segunda, paga el precio.' ) : asde_t( 'Precio: %s.' ), \AsdeBalears\AreaPrivada\Tesoreria\Calculadora::euros( (float) $cfg['precio_camisa'] ) ) ); ?></span>
				</p>
				<p>
					<label for="asde-asig-nota"><?php asde_e( 'Observaciones (opcional)' ); ?></label>
					<input type="text" id="asde-asig-nota" name="nota" maxlength="255">
				</p>
			</div>
			<p class="asde-form__acciones"><button type="submit" class="button button-primary"><?php asde_e( 'Asignar y descontar del inventario' ); ?></button></p>
		</form>
	<?php endif; ?>
</section>
