<?php
/**
 * Ficha de un evento: cabecera, pestañas y la pestaña elegida.
 *
 * @var array                                   $e
 * @var array                                   $cfg
 * @var AsdeBalears\AreaPrivada\Auth\Ambito     $ambito
 * @var bool                                    $gestiona
 * @var string                                  $pestana
 * @var string                                  $contenido La pestaña ya pintada
 * @var string                                  $url       La del evento
 * @var string                                  $agenda
 */
use AsdeBalears\AreaPrivada\Domain\Secciones;
use AsdeBalears\AreaPrivada\Eventos\Config;
use AsdeBalears\AreaPrivada\Eventos\EventosTable;
use AsdeBalears\AreaPrivada\Eventos\Servicio;
use AsdeBalears\AreaPrivada\Frontend\Views\EventosView;

defined( 'ABSPATH' ) || exit;

$estado  = EventosTable::estado_visible( $e );
$pestanas = array();
foreach ( asde_ta( EventosView::PESTANAS ) as $p => $etiqueta ) {
	$pestanas[ add_query_arg( 'pestana', $p, $url ) ] = $etiqueta;
}
?>
<p class="asde-back"><a href="<?php echo esc_url( $agenda ); ?>"><?php asde_e( '← Agenda' ); ?></a></p>

<section class="asde-card asde-evento">
	<header class="asde-card__header asde-card__header--split">
		<h2 class="asde-card__title"><?php echo esc_html( $e['nombre'] ); ?></h2>
		<span class="asde-badge asde-badge--evento-<?php echo esc_attr( $estado ); ?>"><?php echo esc_html( asde_t( EventosTable::ESTADOS[ $estado ] ) ); ?></span>
	</header>
	<p>
		<strong><?php echo esc_html( EventosTable::numero( $e ) ); ?></strong> · <?php echo esc_html( Config::nombre_tipo( $cfg, $e['tipo'] ) ); ?> · <?php echo esc_html( EventosTable::cuando( $e ) ); ?>
		<?php if ( '' !== $e['lugar'] ) : ?>
			· <?php echo esc_html( $e['lugar'] ); ?>
		<?php endif; ?>
	</p>
	<p>
		<?php
		foreach ( $e['secciones'] as $s ) {
			echo Secciones::badge( $s ); // phpcs:ignore WordPress.Security.EscapeOutput
		}
		?>
		<?php if ( Servicio::puede_editar( $e, $ambito ) ) : ?>
			<a class="asde-evento__editar" href="<?php echo esc_url( add_query_arg( 'editar', '1', $url ) ); ?>"><?php asde_e( 'Editar los datos' ); ?></a>
		<?php endif; ?>
	</p>
	<?php if ( EventosTable::CANCELADO === $e['estado'] ) : ?>
		<div class="asde-notice asde-notice--warning"><?php echo esc_html( sprintf( asde_t( 'Cancelado el %1$s. Motivo: %2$s' ), wp_date( 'd/m/Y', strtotime( $e['cancelado_en'] . ' UTC' ) ), $e['cancelado_motivo'] ) ); ?></div>
	<?php endif; ?>
</section>

<?php
echo \AsdeBalears\AreaPrivada\Frontend\TemplateLoader::render( // phpcs:ignore WordPress.Security.EscapeOutput
	'eventos/subnav',
	array(
		'items'  => $pestanas,
		'activo' => add_query_arg( 'pestana', $pestana, $url ),
	)
);
echo $contenido; // phpcs:ignore WordPress.Security.EscapeOutput -- ya escapado en su plantilla.
