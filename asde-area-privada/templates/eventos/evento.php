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
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Domain\Secciones;
use AsdeBalears\AreaPrivada\Eventos\Config;
use AsdeBalears\AreaPrivada\Eventos\Cuentas;
use AsdeBalears\AreaPrivada\Eventos\EventosTable;
use AsdeBalears\AreaPrivada\Eventos\Servicio;
use AsdeBalears\AreaPrivada\Frontend\Views\EventosView;

defined( 'ABSPATH' ) || exit;

$estado  = EventosTable::estado_visible( $e );
$pestanas = array();
$solo_material = Servicio::solo_material( $e, $ambito );
foreach ( ( EventosView::pestanas() ) as $p => $etiqueta ) {
	if ( $solo_material && ! in_array( $p, array( 'resumen', 'material' ), true ) ) {
		continue; // v0.43: el Intendente solo ve el resumen y el material.
	}
	if ( 'cuentas' === $p && ! Cuentas::puede_ver( $e, $ambito ) ) {
		continue; // v0.36: las cuentas, solo quien gestiona el evento y Tesorería.
	}
	if ( 'material' === $p && ! \AsdeBalears\AreaPrivada\Eventos\Material::puede_ver( $e, $ambito ) ) {
		continue; // v0.42: el material, solo quien gestiona el evento y usa el inventario.
	}
	if ( 'menu' === $p && ! \AsdeBalears\AreaPrivada\Menus\MenuEvento::puede_ver( $e, $ambito ) ) {
		continue; // v0.41: el menú, solo quien gestiona el evento.
	}
	$pestanas[ add_query_arg( 'pestana', $p, $url ) ] = $etiqueta;
}
?>
<p class="asde-back"><a href="<?php echo esc_url( $agenda ); ?>"><?php esc_html_e( '← Agenda', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a></p>

<section class="asde-card asde-evento">
	<header class="asde-card__header asde-card__header--split">
		<h2 class="asde-card__title"><?php echo esc_html( $e['nombre'] ); ?></h2>
		<span class="asde-badge asde-badge--evento-<?php echo esc_attr( $estado ); ?>"><?php echo esc_html( ( EventosTable::estados()[ $estado ] ) ); ?></span>
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
			echo wp_kses( Secciones::badge( $s ), asde_html_permitido() );
		}
		?>
		<?php if ( Servicio::puede_editar( $e, $ambito ) ) : ?>
			<a class="asde-evento__editar" href="<?php echo esc_url( add_query_arg( 'editar', '1', $url ) ); ?>"><?php esc_html_e( 'Edit the details', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a>
		<?php endif; ?>
	</p>
	<?php if ( EventosTable::CANCELADO === $e['estado'] ) : ?>
		<div class="asde-notice asde-notice--warning"><?php echo esc_html( sprintf( /* translators: 1: text, 2: text */ __( 'Cancelled on %1$s. Reason: %2$s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), wp_date( 'd/m/Y', strtotime( $e['cancelado_en'] . ' UTC' ) ), $e['cancelado_motivo'] ) ); ?></div>
	<?php endif; ?>
</section>

<?php
$subnav = \AsdeBalears\AreaPrivada\Frontend\TemplateLoader::render(
	'eventos/subnav',
	array(
		'items'  => $pestanas,
		'activo' => add_query_arg( 'pestana', $pestana, $url ),
	)
);
echo wp_kses( $subnav, asde_html_permitido() );
echo wp_kses( $contenido, asde_html_permitido() );
