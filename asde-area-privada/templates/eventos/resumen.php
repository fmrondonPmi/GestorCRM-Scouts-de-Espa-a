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
	<h3 class="asde-card__title"><?php asde_e( 'Lista de comprobación' ); ?></h3>
	<ul class="asde-comprobacion">
		<?php foreach ( $lista as list( $estado, $texto ) ) : ?>
			<li class="asde-comprobacion--<?php echo esc_attr( $estado ); ?>"><span class="asde-comprobacion__marca" aria-hidden="true"><?php echo esc_html( $marca[ $estado ] ?? '' ); ?></span> <?php echo esc_html( $texto ); ?></li>
		<?php endforeach; ?>
	</ul>
	<p class="asde-help"><?php asde_e( 'Son avisos para no olvidar nada: no impiden publicar. Lo único obligatorio es que Tesorería valide el presupuesto de un evento con coste.' ); ?></p>
</section>

<section class="asde-card">
	<h3 class="asde-card__title"><?php asde_e( 'Datos' ); ?></h3>
	<dl class="asde-datos">
		<div><dt><?php asde_e( 'Ámbito' ); ?></dt><dd><?php echo esc_html( asde_t( EventosTable::AMBITOS[ $e['ambito'] ] ?? $e['ambito'] ) ); ?></dd></div>
		<div><dt><?php asde_e( 'Fechas' ); ?></dt><dd><?php echo esc_html( EventosTable::cuando( $e ) ); ?><?php echo $noches ? esc_html( ' · ' . sprintf( asde_n( '%d noche', '%d noches', $noches ), $noches ) ) : ''; ?></dd></div>
		<?php if ( '' !== $e['lugar'] || '' !== $e['mapa'] ) : ?>
			<div><dt><?php asde_e( 'Lugar' ); ?></dt><dd><?php echo esc_html( $e['lugar'] ); ?><?php echo '' !== $e['mapa'] ? ' <a href="' . esc_url( $e['mapa'] ) . '" target="_blank" rel="noopener">' . esc_html( asde_t( 'Ver en el mapa' ) ) . '</a>' : ''; ?></dd></div>
		<?php endif; ?>
		<?php if ( '' !== $e['salida'] ) : ?>
			<div><dt><?php asde_e( 'Salida' ); ?></dt><dd><?php echo esc_html( $e['salida'] ); ?></dd></div>
		<?php endif; ?>
		<?php if ( '' !== $e['llegada'] ) : ?>
			<div><dt><?php asde_e( 'Llegada' ); ?></dt><dd><?php echo esc_html( $e['llegada'] ); ?></dd></div>
		<?php endif; ?>
		<div>
			<dt><?php asde_e( 'Participantes previstos' ); ?></dt>
			<dd>
				<?php foreach ( $e['secciones'] as $s ) : ?>
					<?php $pl = $e['plazas'][ $s ] ?? array( 'previstas' => 0, 'maximas' => 0 ); ?>
					<?php echo Secciones::badge( $s ); // phpcs:ignore WordPress.Security.EscapeOutput ?> <?php echo esc_html( $pl['maximas'] ? sprintf( asde_t( '%1$d previstos de %2$d plazas' ), $pl['previstas'], $pl['maximas'] ) : sprintf( asde_t( '%d previstos' ), $pl['previstas'] ) ); ?><br>
				<?php endforeach; ?>
				<?php if ( $e['plazas_max'] ) : ?>
					<?php echo esc_html( sprintf( asde_t( 'Plazas en total: %d' ), $e['plazas_max'] ) ); ?>
				<?php endif; ?>
			</dd>
		</div>
		<?php if ( null !== $e['edad_min'] || null !== $e['edad_max'] ) : ?>
			<div><dt><?php asde_e( 'Edad' ); ?></dt><dd>
				<?php
				if ( null !== $e['edad_min'] && null !== $e['edad_max'] ) {
					echo esc_html( sprintf( asde_t( 'De %1$d a %2$d años' ), $e['edad_min'], $e['edad_max'] ) );
				} elseif ( null !== $e['edad_min'] ) {
					echo esc_html( sprintf( asde_t( 'Desde %d años' ), $e['edad_min'] ) );
				} else {
					echo esc_html( sprintf( asde_t( 'Hasta %d años' ), $e['edad_max'] ) );
				}
				?>
			</dd></div>
		<?php endif; ?>
		<?php if ( '' !== trim( $e['descripcion'] ) ) : ?>
			<div><dt><?php asde_e( 'Descripción' ); ?></dt><dd><?php echo nl2br( esc_html( $e['descripcion'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></dd></div>
		<?php endif; ?>
		<?php if ( '' !== trim( $e['material'] ) ) : ?>
			<div><dt><?php asde_e( 'Material personal' ); ?></dt><dd><?php echo nl2br( esc_html( $e['material'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></dd></div>
		<?php endif; ?>
	</dl>
</section>

<?php
$acciones = array();
if ( Servicio::puede_publicar( $e, $ambito ) ) {
	if ( Config::aviso_activo( 'publicado', (string) $e['grupo'] ) ) {
		// v0.52: al publicar se pregunta a quién avisar por email (familias, scouters o todos); sin marcar nada, no sale ningún email.
		$campos = '<fieldset class="asde-publicar__destinos"><legend>' . esc_html( asde_t( '¿Avisar por email de que se ha publicado?' ) ) . '</legend>'
			. '<label class="asde-choice"><input type="checkbox" name="avisar[]" value="familias" data-asde-aviso-parte><span>' . esc_html( asde_t( 'Familias' ) ) . '</span></label>'
			. '<label class="asde-choice"><input type="checkbox" name="avisar[]" value="scouters" data-asde-aviso-parte><span>' . esc_html( asde_t( 'Scouters' ) ) . '</span></label>'
			. '<label class="asde-choice"><input type="checkbox" name="avisar[]" value="todos" data-asde-aviso-todos><span>' . esc_html( asde_t( 'Todos' ) ) . '</span></label>'
			. '<p class="asde-help">' . esc_html( asde_t( 'Si no marcas nada, el evento se publica y no se envía ningún correo.' ) ) . '</p></fieldset>';
		$form_publicar = sprintf(
			'<form method="post" class="asde-form asde-publicar">%s<input type="hidden" name="asde_action" value="evento_publicar"><input type="hidden" name="evento" value="%d"><input type="hidden" name="pestana" value="resumen"><h3 id="asde-publicar-titulo">%s</h3>%s<p class="asde-form__acciones"><button type="submit" class="button button-primary">%s</button> <button type="button" class="button asde-button-secundario" data-asde-cerrar hidden>%s</button></p></form>',
			wp_nonce_field( 'asde_evento_' . $e['id'], '_wpnonce', true, false ),
			(int) $e['id'],
			esc_html( asde_t( 'Publicar el evento' ) ),
			$campos,
			esc_html( asde_t( 'Publicar el evento' ) ),
			esc_html( asde_t( 'Cancelar' ) )
		);
		$acciones[] = '<button type="button" class="button button-primary" data-asde-publicar-abrir hidden>' . esc_html( asde_t( 'Publicar el evento' ) ) . '</button>'
			. '<dialog class="asde-modal asde-modal--mini" aria-labelledby="asde-publicar-titulo" data-asde-publicar-dialogo>' . $form_publicar . '</dialog>'
			. '<noscript>' . $form_publicar . '</noscript>';
	} else {
		$acciones[] = $form( 'evento_publicar', asde_t( 'Publicar el evento' ), 'button button-primary', asde_t( '¿Publicar el evento? Lo verán los scouters de las secciones convocadas.' ) );
	}
} elseif ( EventosTable::BORRADOR === $e['estado'] && $e['con_coste'] && null === $e['validado_en'] && Servicio::en_gestion( $e, $ambito ) ) {
	$acciones[] = '<span class="asde-help">' . esc_html( asde_t( 'Para publicarlo, Tesorería tiene que validar antes el presupuesto (pestaña «Presupuesto»).' ) ) . '</span>';
}
if ( Servicio::puede_despublicar( $e, $ambito ) ) {
	$acciones[] = $form( 'evento_despublicar', asde_t( 'Volver a borrador' ), 'asde-btn-link', asde_t( '¿Volver a dejar el evento como borrador?' ) );
}
if ( Servicio::puede_borrar( $e, $ambito ) ) {
	$acciones[] = $form( 'evento_borrar', asde_t( 'Borrar el borrador' ), 'asde-btn-link', asde_t( '¿Borrar este borrador con su equipo y su presupuesto? No se puede deshacer.' ) );
}
?>
<?php if ( $acciones || Servicio::puede_cancelar( $e, $ambito ) ) : ?>
	<section class="asde-card">
		<h3 class="asde-card__title"><?php asde_e( 'Acciones' ); ?></h3>
		<?php if ( $acciones ) : ?>
			<p class="asde-actions"><?php echo implode( ' ', $acciones ); // phpcs:ignore WordPress.Security.EscapeOutput ?></p>
		<?php endif; ?>
		<?php if ( Servicio::puede_cancelar( $e, $ambito ) && EventosTable::BORRADOR !== $e['estado'] ) : ?>
			<details class="asde-rechazar">
				<summary><?php asde_e( 'Cancelar el evento' ); ?></summary>
				<?php echo $form( 'evento_cancelar', esc_html( asde_t( 'Cancelar el evento' ) ), 'button', esc_attr( asde_js( '¿Cancelar el evento? No se puede deshacer.' ) ), '<label>' . esc_html( asde_t( 'Motivo' ) ) . ' <input type="text" name="motivo" maxlength="255" required></label> ' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			</details>
		<?php endif; ?>
	</section>
<?php endif; ?>
