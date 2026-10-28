<?php
/**
 * Evento · resumen: los datos, la lista de comprobación (normativa, seguro, presupuesto)
 * y lo que se puede hacer con él (publicar, volver a borrador, cancelar, borrar).
 *
 * @var array                               $e
 * @var array                               $cfg
 * @var AsdeBalears\AreaPrivada\Auth\Ambito $ambito
 * @var array<int, array{0:string, 1:string}> $lista
 * @var string                              $url
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Domain\Secciones;
use AsdeBalears\AreaPrivada\Eventos\Config;
use AsdeBalears\AreaPrivada\Eventos\EventosTable;
use AsdeBalears\AreaPrivada\Eventos\Normativa;
use AsdeBalears\AreaPrivada\Eventos\Servicio;

defined( 'ABSPATH' ) || exit;

$form = static function ( string $accion, string $boton, string $clase = 'button', string $confirmar = '', string $extra = '' ) use ( $e ): string {
	return sprintf(
		'<form method="post" class="asde-inline-form"%s>%s<input type="hidden" name="asde_action" value="%s"><input type="hidden" name="evento" value="%d"><input type="hidden" name="pestana" value="resumen">%s<button type="submit" class="%s">%s</button></form>',
		'' !== $confirmar ? ' data-asde-confirmar="' . esc_attr( $confirmar ) . '"' : '',
		wp_nonce_field( 'asde_evento_' . $e['id'], '_wpnonce', true, false ),
		esc_attr( $accion ),
		(int) $e['id'],
		$extra,
		esc_attr( $clase ),
		esc_html( $boton )
	);
};
$marca  = array(
	'ok'    => '✓',
	'aviso' => '!',
	'info'  => 'i',
);
$noches = Normativa::noches( $e );
?>
<section class="asde-card">
	<h3 class="asde-card__title"><?php esc_html_e( 'Checklist', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h3>
	<ul class="asde-comprobacion">
		<?php foreach ( $lista as list( $estado, $texto ) ) : ?>
			<li class="asde-comprobacion--<?php echo esc_attr( $estado ); ?>"><span class="asde-comprobacion__marca" aria-hidden="true"><?php echo esc_html( $marca[ $estado ] ?? '' ); ?></span> <?php echo esc_html( $texto ); ?></li>
		<?php endforeach; ?>
	</ul>
	<p class="asde-help"><?php esc_html_e( 'These are notices so you don\'t forget anything: they do not prevent publishing. The only mandatory thing is that Treasury validates the budget of an event with a cost.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
</section>

<section class="asde-card">
	<h3 class="asde-card__title"><?php esc_html_e( 'Details', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h3>
	<dl class="asde-datos">
		<div><dt><?php esc_html_e( 'Scope', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></dt><dd><?php echo esc_html( ( EventosTable::ambitos()[ $e['ambito'] ] ?? $e['ambito'] ) ); ?></dd></div>
		<div><dt><?php esc_html_e( 'Dates', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></dt><dd><?php echo esc_html( EventosTable::cuando( $e ) ); ?><?php echo $noches ? esc_html( ' · ' . sprintf( /* translators: %d: number */ _n( '%d night', '%d nights', $noches , 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $noches ) ) : ''; ?></dd></div>
		<?php if ( '' !== $e['lugar'] || '' !== $e['mapa'] ) : ?>
			<div><dt><?php esc_html_e( 'Place', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></dt><dd><?php echo esc_html( $e['lugar'] ); ?><?php echo '' !== $e['mapa'] ? ' <a href="' . esc_url( $e['mapa'] ) . '" target="_blank" rel="noopener">' . esc_html( __( 'View on the map', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) . '</a>' : ''; ?></dd></div>
		<?php endif; ?>
		<?php if ( '' !== $e['salida'] ) : ?>
			<div><dt><?php esc_html_e( 'Outflow', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></dt><dd><?php echo esc_html( $e['salida'] ); ?></dd></div>
		<?php endif; ?>
		<?php if ( '' !== $e['llegada'] ) : ?>
			<div><dt><?php esc_html_e( 'Arrival', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></dt><dd><?php echo esc_html( $e['llegada'] ); ?></dd></div>
		<?php endif; ?>
		<div>
			<dt><?php esc_html_e( 'Expected participants', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></dt>
			<dd>
				<?php foreach ( $e['secciones'] as $s ) : ?>
					<?php $pl = $e['plazas'][ $s ] ?? array( 'previstas' => 0, 'maximas' => 0 ); ?>
					<?php echo wp_kses( Secciones::badge( $s ), asde_html_permitido() ); ?> <?php echo esc_html( $pl['maximas'] ? sprintf( /* translators: 1: number, 2: number */ __( '%1$d expected out of %2$d places', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $pl['previstas'], $pl['maximas'] ) : sprintf( /* translators: %d: number */ __( '%d expected', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $pl['previstas'] ) ); ?><br>
				<?php endforeach; ?>
				<?php if ( $e['plazas_max'] ) : ?>
					<?php echo esc_html( sprintf( /* translators: %d: number */ __( 'Total places: %d', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $e['plazas_max'] ) ); ?>
				<?php endif; ?>
			</dd>
		</div>
		<?php if ( null !== $e['edad_min'] || null !== $e['edad_max'] ) : ?>
			<div><dt><?php esc_html_e( 'Age', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></dt><dd>
				<?php
				if ( null !== $e['edad_min'] && null !== $e['edad_max'] ) {
					echo esc_html( sprintf( /* translators: 1: number, 2: number */ __( 'From %1$d to %2$d years', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $e['edad_min'], $e['edad_max'] ) );
				} elseif ( null !== $e['edad_min'] ) {
					echo esc_html( sprintf( /* translators: %d: number */ __( 'From %d years', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $e['edad_min'] ) );
				} else {
					echo esc_html( sprintf( /* translators: %d: number */ __( 'Up to %d years', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $e['edad_max'] ) );
				}
				?>
			</dd></div>
		<?php endif; ?>
		<?php if ( '' !== trim( $e['descripcion'] ) ) : ?>
			<div><dt><?php esc_html_e( 'Description', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></dt><dd><?php echo wp_kses( nl2br( esc_html( $e['descripcion'] ) ), asde_html_permitido() ); ?></dd></div>
		<?php endif; ?>
		<?php if ( '' !== trim( $e['material'] ) ) : ?>
			<div><dt><?php esc_html_e( 'Personal equipment', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></dt><dd><?php echo wp_kses( nl2br( esc_html( $e['material'] ) ), asde_html_permitido() ); ?></dd></div>
		<?php endif; ?>
	</dl>
</section>

<?php
$acciones = array();
if ( Servicio::puede_publicar( $e, $ambito ) ) {
	if ( Config::aviso_activo( 'publicado', (string) $e['grupo'] ) ) {
		// v0.52: al publicar se pregunta a quién avisar por email (familias, scouters o todos); sin marcar nada, no sale ningún email.
		$campos = '<fieldset class="asde-publicar__destinos"><legend>' . esc_html( __( 'Notify by email that it has been published?', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) . '</legend>'
			. '<label class="asde-choice"><input type="checkbox" name="avisar[]" value="familias" data-asde-aviso-parte><span>' . esc_html( __( 'Families', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) . '</span></label>'
			. '<label class="asde-choice"><input type="checkbox" name="avisar[]" value="scouters" data-asde-aviso-parte><span>' . esc_html( __( 'Scouters', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) . '</span></label>'
			. '<label class="asde-choice"><input type="checkbox" name="avisar[]" value="todos" data-asde-aviso-todos><span>' . esc_html( __( 'All', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) . '</span></label>'
			. '<p class="asde-help">' . esc_html( __( 'If you tick nothing, the event is published and no email is sent.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) . '</p></fieldset>';
		$form_publicar = sprintf(
			'<form method="post" class="asde-form asde-publicar">%s<input type="hidden" name="asde_action" value="evento_publicar"><input type="hidden" name="evento" value="%d"><input type="hidden" name="pestana" value="resumen"><h3 id="asde-publicar-titulo">%s</h3>%s<p class="asde-form__acciones"><button type="submit" class="button button-primary">%s</button> <button type="button" class="button asde-button-secundario" data-asde-cerrar hidden>%s</button></p></form>',
			wp_nonce_field( 'asde_evento_' . $e['id'], '_wpnonce', true, false ),
			(int) $e['id'],
			esc_html( __( 'Publish the event', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ),
			$campos,
			esc_html( __( 'Publish the event', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ),
			esc_html( __( 'Cancel', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) )
		);
		$acciones[] = '<button type="button" class="button button-primary" data-asde-publicar-abrir hidden>' . esc_html( __( 'Publish the event', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) . '</button>'
			. '<dialog class="asde-modal asde-modal--mini" aria-labelledby="asde-publicar-titulo" data-asde-publicar-dialogo>' . $form_publicar . '</dialog>'
			. '<noscript>' . $form_publicar . '</noscript>';
	} else {
		$acciones[] = $form( 'evento_publicar', __( 'Publish the event', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), 'button button-primary', __( 'Publish the event? The scouters of the invited sections will see it.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
	}
} elseif ( EventosTable::BORRADOR === $e['estado'] && $e['con_coste'] && null === $e['validado_en'] && Servicio::en_gestion( $e, $ambito ) ) {
	$acciones[] = '<span class="asde-help">' . esc_html( __( 'To publish it, Treasury must first validate the budget ("Budget" tab).', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) . '</span>';
}
if ( Servicio::puede_despublicar( $e, $ambito ) ) {
	$acciones[] = $form( 'evento_despublicar', __( 'Back to draft', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), 'asde-btn-link', __( 'Leave the event as a draft again?', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
}
if ( Servicio::puede_borrar( $e, $ambito ) ) {
	$acciones[] = $form( 'evento_borrar', __( 'Delete the draft', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), 'asde-btn-link', __( 'Delete this draft with its team and budget? This cannot be undone.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) );
}
?>
<?php if ( $acciones || Servicio::puede_cancelar( $e, $ambito ) ) : ?>
	<section class="asde-card">
		<h3 class="asde-card__title"><?php esc_html_e( 'Actions', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h3>
		<?php if ( $acciones ) : ?>
			<p class="asde-actions"><?php echo wp_kses( implode( ' ', $acciones ), asde_html_permitido() ); ?></p>
		<?php endif; ?>
		<?php if ( Servicio::puede_cancelar( $e, $ambito ) && EventosTable::BORRADOR !== $e['estado'] ) : ?>
			<details class="asde-rechazar">
				<summary><?php esc_html_e( 'Cancel the event', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></summary>
				<?php echo wp_kses( $form( 'evento_cancelar', esc_html( __( 'Cancel the event', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ), 'button', esc_attr( __( 'Cancel the event? This cannot be undone.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ), '<label>' . esc_html( __( 'Reason', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) . ' <input type="text" name="motivo" maxlength="255" required></label> ' ), asde_html_permitido() ); ?>
			</details>
		<?php endif; ?>
	</section>
<?php endif; ?>
