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
		<h3 class="asde-card__title"><?php esc_html_e( 'Kitchen sheet', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h3>
		<span class="asde-actions asde-solo-pantalla">
			<button type="button" class="button" data-asde-imprimir-pagina><?php esc_html_e( 'Print', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
			<a href="<?php echo esc_url( $url_menu ); ?>"><?php esc_html_e( 'Back to the menu', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a>
		</span>
	</header>
	<p><strong><?php echo esc_html( $e['nombre'] ); ?></strong> · <?php echo esc_html( sprintf( /* translators: %s: text */ __( 'Valid as of %s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $hoja['momento'] ) ); ?></p>
	<div class="asde-notice asde-notice--warning"><?php esc_html_e( 'Contains health data of minors: do not leave it in sight, do not share it and destroy it when the event ends.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></div>
	<?php if ( 'ok' !== $hoja['estado'] ) : ?>
		<p><?php esc_html_e( 'The sheet could not be prepared: the menu, the attendees or the connection to the CRM is missing.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
	<?php else : ?>
		<?php $hay = false; ?>
		<?php foreach ( $hoja['platos'] as $pl ) : ?>
			<?php if ( ! $pl['personas'] ) : ?>
				<?php continue; ?>
			<?php endif; ?>
			<?php $hay = true; ?>
			<h4><?php echo esc_html( wp_date( 'l d/m/Y', strtotime( $pl['dia'] . ' 12:00 UTC' ) ) . ' · ' . ( MenuTable::comidas()[ $pl['comida'] ] ?? '' ) . ' · ' . $pl['plato'] ); ?></h4>
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
							$partes[] = sprintf( /* translators: %s: text */ __( 'traces of %s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), implode( ', ', array_unique( $d['trazas'] ) ) );
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
			<p><?php esc_html_e( 'Nobody has allergies, intolerances or a diet that match this menu.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
		<?php endif; ?>
		<?php if ( ! empty( $hoja['revisar'] ) ) : ?>
			<h4><?php esc_html_e( 'To check by hand (what is written on their record was not understood)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h4>
			<ul>
				<?php foreach ( $hoja['revisar'] as $nombre => $texto ) : ?>
					<li><strong><?php echo esc_html( $nombre ); ?></strong>: <?php echo esc_html( $texto ); ?></li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
	<?php endif; ?>
</section>
