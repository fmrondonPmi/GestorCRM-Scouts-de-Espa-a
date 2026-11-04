<?php
/**
 * Mi sección → una persona (doc 65): su etapa, un aspecto por fila con su indicador, el objetivo
 * personal, las notas (cifradas) y el histórico de revisiones.
 *
 * @var \AsdeBalears\AreaPrivada\Domain\Persona $p
 * @var string     $seccion
 * @var array      $aspectos  Catalogo::aspectos()
 * @var array|null $actual    Última revisión (Revisiones::actuales) o null
 * @var array[]    $historial Revisiones::historial
 * @var array|null $anio      Catalogo::anio()
 * @var bool       $edita     Puede guardar revisiones
 * @var string     $url       Mi sección de esta sección
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Domain\Secciones;
use AsdeBalears\AreaPrivada\Seguimiento\Catalogo;

defined( 'ABSPATH' ) || exit;

$etapa   = (int) ( $actual['etapa'] ?? 1 );
$etapas  = Catalogo::etapas();
$estados = Catalogo::estados();
?>
<section class="asde-card asde-seguimiento">
	<h2 class="asde-card__title"><?php echo esc_html( $p->nombre_completo() ); ?> <?php echo wp_kses( Secciones::badge( $seccion ), asde_html_permitido() ); ?></h2>
	<p class="asde-help">
		<?php echo null !== $p->edad() ? esc_html( sprintf( /* translators: %d: age */ __( '%d years', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $p->edad() ) ) : ''; ?>
		<?php echo $anio ? ' · ' . esc_html( sprintf( /* translators: 1: year, 2: total years */ __( 'Year %1$d of %2$d', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $anio['n'], $anio['de'] ) ) : ''; ?>
	</p>
	<form method="post" action="<?php echo esc_url( $url ); ?>" class="asde-form">
		<?php wp_nonce_field( 'asde_seguimiento' ); ?>
		<input type="hidden" name="asde_action" value="guardar_seguimiento">
		<input type="hidden" name="persona" value="<?php echo esc_attr( $p->num_socio_asde ); ?>">
		<input type="hidden" name="seccion" value="<?php echo esc_attr( $seccion ); ?>">
		<p>
			<label for="asde-seg-etapa"><?php esc_html_e( 'Current stage', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
			<select id="asde-seg-etapa" name="etapa"<?php disabled( ! $edita ); ?>>
				<?php foreach ( $etapas as $n => $nombre ) : ?>
					<option value="<?php echo (int) $n; ?>" <?php selected( $etapa, $n ); ?>><?php echo esc_html( $nombre ); ?></option>
				<?php endforeach; ?>
			</select>
			<span class="asde-help"><?php esc_html_e( 'You do not need to achieve everything to change stage: the team decides. Changing the stage starts the aspects from scratch.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span>
		</p>
		<?php foreach ( $aspectos as $i => $a ) : ?>
			<fieldset class="asde-seguimiento__aspecto"<?php disabled( ! $edita ); ?>>
				<legend><?php echo esc_html( $a['t'] ); ?></legend>
				<p><?php echo esc_html( $a['e'][ $etapa - 1 ] ); ?></p>
				<span class="asde-seguimiento__estados">
					<?php foreach ( $estados as $e => $nombre ) : ?>
						<label><input type="radio" name="estado[<?php echo (int) $i; ?>]" value="<?php echo (int) $e; ?>" <?php checked( (int) ( $actual['estados'][ $i ] ?? 0 ), $e ); ?>> <?php echo esc_html( $nombre ); ?></label>
					<?php endforeach; ?>
				</span>
				<details>
					<summary><?php esc_html_e( 'See the three stages and add a note', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></summary>
					<ul>
						<?php foreach ( $etapas as $n => $nombre ) : ?>
							<li><strong><?php echo esc_html( $nombre ); ?>:</strong> <?php echo esc_html( $a['e'][ $n - 1 ] ); ?></li>
						<?php endforeach; ?>
					</ul>
					<textarea name="nota[<?php echo (int) $i; ?>]" rows="2" maxlength="1000" placeholder="<?php echo esc_attr( esc_attr__( 'Note (optional, stored encrypted)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo esc_textarea( $actual['datos']['notas'][ $i ] ?? '' ); ?></textarea>
				</details>
			</fieldset>
		<?php endforeach; ?>
		<p>
			<label for="asde-seg-objetivo"><?php esc_html_e( 'Personal goal chosen by the person', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
			<input type="text" id="asde-seg-objetivo" name="objetivo" maxlength="200" value="<?php echo esc_attr( $actual['datos']['objetivo'] ?? '' ); ?>"<?php disabled( ! $edita ); ?>>
		</p>
		<?php if ( $edita ) : ?>
			<p>
				<label><input type="checkbox" name="propuesta" value="1" <?php checked( ! empty( $actual['propuesta'] ) ); ?>> <?php esc_html_e( 'Propose a change to the next stage', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
			</p>
			<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Save review', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button></p>
		<?php endif; ?>
	</form>
	<?php if ( $historial ) : ?>
		<details>
			<summary><?php echo esc_html( sprintf( /* translators: %d: number of reviews */ _n( '%d review', '%d reviews', count( $historial ), 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), count( $historial ) ) ); ?></summary>
			<ul>
				<?php foreach ( $historial as $h ) : ?>
					<li>
						<?php echo esc_html( wp_date( 'd/m/Y', strtotime( $h['creada'] . ' UTC' ) ) . ' · ' . ( $etapas[ $h['etapa'] ] ?? '' ) . ' · ' . sprintf( /* translators: %d: number of aspects */ __( '%d achieved', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), count( array_filter( $h['estados'], static fn( $e ) => 2 === $e ) ) ) ); ?>
						<?php $u = get_userdata( (int) $h['user_id'] ); ?>
						<?php echo $u ? esc_html( '· ' . $u->display_name ) : ''; ?>
					</li>
				<?php endforeach; ?>
			</ul>
		</details>
	<?php endif; ?>
</section>
