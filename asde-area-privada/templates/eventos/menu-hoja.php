<?php
/**
 * La hoja de cocina para imprimir (v0.41, doc 49, B3): nombre y alérgeno de cada persona afectada, comida por comida. Se compone en
 * vivo y no se guarda.
 *
 * @var array  $e
 * @var array  $hoja      HojaCocina::componer()
 * @var array|null $entrega  Última hoja entregada: cuando, como, desfasada
 * @var string $url_menu
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Menus\MenuTable;

defined( 'ABSPATH' ) || exit;
?>
<section class="asde-card" data-asde-inicio="abierto">
	<header class="asde-card__header asde-card__header--split">
		<h3 class="asde-card__title"><?php asde_e( 'Hoja de cocina' ); ?></h3>
		<span class="asde-actions asde-solo-pantalla">
			<button type="button" class="button" data-asde-imprimir-pagina><?php asde_e( 'Imprimir' ); ?></button>
			<a href="<?php echo esc_url( $url_menu ); ?>"><?php asde_e( 'Volver al menú' ); ?></a>
		</span>
	</header>
	<p><strong><?php echo esc_html( $e['nombre'] ); ?></strong> · <?php echo esc_html( sprintf( asde_t( 'Válida a fecha de %s' ), $hoja['momento'] ) ); ?></p>
	<div class="asde-notice asde-notice--warning"><?php asde_e( 'Contiene datos de salud de menores: no la dejes a la vista, no la compartas y destrúyela al acabar el evento.' ); ?></div>
	<?php if ( 'ok' !== $hoja['estado'] ) : ?>
		<p><?php asde_e( 'No se ha podido preparar la hoja: falta el menú, los asistentes o la conexión con el CRM.' ); ?></p>
	<?php else : ?>
		<?php $hay = false; ?>
		<?php foreach ( $hoja['platos'] as $pl ) : ?>
			<?php if ( ! $pl['personas'] ) : ?>
				<?php continue; ?>
			<?php endif; ?>
			<?php $hay = true; ?>
			<h4><?php echo esc_html( wp_date( 'l d/m/Y', strtotime( $pl['dia'] . ' 12:00 UTC' ) ) . ' · ' . asde_t( MenuTable::COMIDAS[ $pl['comida'] ] ?? '' ) . ' · ' . $pl['plato'] ); ?></h4>
			<ul>
				<?php foreach ( $pl['personas'] as $nombre => $d ) : ?>
					<li>
						<strong><?php echo esc_html( $nombre ); ?></strong>:
						<?php
						$partes = array();
						if ( ! empty( $d['alergenos'] ) ) {
							$partes[] = implode( ', ', array_unique( $d['alergenos'] ) );
						}
						if ( ! empty( $d['trazas'] ) ) {
							$partes[] = sprintf( asde_t( 'trazas de %s' ), implode( ', ', array_unique( $d['trazas'] ) ) );
						}
						if ( ! empty( $d['dieta'] ) ) {
							$partes[] = implode( ', ', array_unique( $d['dieta'] ) );
						}
						echo esc_html( implode( ' · ', $partes ) );
						?>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endforeach; ?>
		<?php if ( ! $hay ) : ?>
			<p><?php asde_e( 'Nadie tiene alergias, intolerancias ni dieta que crucen con este menú.' ); ?></p>
		<?php endif; ?>
		<?php if ( ! empty( $hoja['revisar'] ) ) : ?>
			<h4><?php asde_e( 'A revisar a mano (no se ha entendido lo escrito en su ficha)' ); ?></h4>
			<ul>
				<?php foreach ( $hoja['revisar'] as $nombre => $texto ) : ?>
					<li><strong><?php echo esc_html( $nombre ); ?></strong>: <?php echo esc_html( $texto ); ?></li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
	<?php endif; ?>
</section>
