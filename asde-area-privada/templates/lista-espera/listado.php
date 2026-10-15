<?php
/**
 * Lista de espera (v0.32, doc 39): el enlace del grupo y la lista, con la sección que toca
 * por edad (o la asignada) y el orden de llegada dentro de cada sección.
 *
 * @var array[]          $lista   Inscripciones (ListaEspera\Inscripciones::listado) con el filtro
 * @var array<int, int>  $orden   id => posición en su sección (solo las que siguen en la lista)
 * @var int              $total   Las que siguen en la lista
 * @var string           $estado  Filtro de estado
 * @var string           $seccion Filtro de sección ('' todas, 'sin' sin edad)
 * @var string           $url
 * @var string           $enlace  Enlace para apuntarse
 * @var bool             $renovar Puede generar un enlace nuevo
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Domain\Secciones;
use AsdeBalears\AreaPrivada\Frontend\Views\ListaEsperaView;
use AsdeBalears\AreaPrivada\ListaEspera\Inscripciones;

defined( 'ABSPATH' ) || exit;

$fecha = static function ( ?string $utc ): string {
	$t = $utc ? strtotime( $utc . ' UTC' ) : false;
	return $t ? wp_date( 'd/m/Y', $t ) : '—';
};
?>
<section class="asde-card">
	<h2 class="asde-card__title"><?php asde_e( 'Lista de espera' ); ?></h2>
	<p><?php asde_e( 'Pasa este enlace a quien quiera entrar en el grupo. Deja sus datos, confirma su email y aparece aquí. Sus datos se guardan cifrados y se borran al darle de alta, si lo pide o a los 12 meses (salvo que diga que los mantiene).' ); ?></p>
	<?php if ( '' !== $enlace ) : ?>
		<p class="asde-copiar">
			<code class="asde-copiar__texto" id="asde-enlace-espera"><?php echo esc_html( $enlace ); ?></code>
			<button type="button" class="asde-btn-link" data-asde-copiar="asde-enlace-espera"><?php asde_e( 'Copiar el enlace' ); ?></button>
		</p>
	<?php endif; ?>
	<?php if ( $renovar ) : ?>
		<form method="post" data-asde-confirmar="<?php echo esc_attr( asde_js( '¿Generar un enlace nuevo? El que ya has pasado dejará de servir.' ) ); ?>">
			<?php wp_nonce_field( 'asde_enlace_espera' ); ?>
			<input type="hidden" name="asde_action" value="renovar_enlace_espera">
			<button type="submit" class="asde-btn-link"><?php asde_e( 'Generar enlace nuevo' ); ?></button>
			<span class="asde-help"><?php asde_e( 'Si el enlace ha llegado a quien no debía. Los enlaces personales de cada inscripción siguen valiendo.' ); ?></span>
		</form>
	<?php endif; ?>
</section>

<section class="asde-card">
	<form method="get" action="<?php echo esc_url( $url ); ?>" class="asde-filter asde-filter--una-linea" data-asde-parcial-form="espera-lista">
		<label for="asde-espera-estado"><?php asde_e( 'Mostrar' ); ?></label>
		<select id="asde-espera-estado" name="estado" data-asde-autoenviar>
			<option value="<?php echo esc_attr( ListaEsperaView::ACTIVAS ); ?>" <?php selected( $estado, ListaEsperaView::ACTIVAS ); ?>><?php echo esc_html( asde_t( 'En la lista' ) . ' (' . $total . ')' ); ?></option>
			<?php foreach ( asde_ta( Inscripciones::ESTADOS ) as $e => $etiqueta ) : ?>
				<option value="<?php echo esc_attr( $e ); ?>" <?php selected( $estado, $e ); ?>><?php echo esc_html( $etiqueta ); ?></option>
			<?php endforeach; ?>
			<option value="todas" <?php selected( $estado, 'todas' ); ?>><?php asde_e( 'Todas' ); ?></option>
		</select>
		<label for="asde-espera-seccion"><?php asde_e( 'Sección' ); ?></label>
		<select id="asde-espera-seccion" name="seccion" data-asde-autoenviar>
			<option value=""><?php asde_e( 'Todas' ); ?></option>
			<?php foreach ( array_keys( Secciones::EDAD_DESDE ) as $s ) : ?>
				<option value="<?php echo esc_attr( $s ); ?>" <?php selected( $seccion, $s ); ?>><?php echo esc_html( Secciones::nombre( $s ) ); ?></option>
			<?php endforeach; ?>
			<option value="sin" <?php selected( $seccion, 'sin' ); ?>><?php asde_e( 'Aún sin edad' ); ?></option>
		</select>
		<button type="submit" class="button"><?php asde_e( 'Ver' ); ?></button>
	</form>

	<div data-asde-parcial="espera-lista">
	<?php if ( ! $lista ) : ?>
		<p class="asde-muted"><?php asde_e( 'No hay nadie en la lista de espera con este filtro.' ); ?></p>
	<?php else : ?>
		<table class="asde-table asde-espera-tabla">
			<thead>
				<tr>
					<th scope="col"><abbr title="<?php echo esc_attr( asde_a( 'Orden de llegada en su sección' ) ); ?>"><?php asde_e( 'Orden' ); ?></abbr></th>
					<th scope="col"><?php asde_e( 'Nombre' ); ?></th>
					<th scope="col"><?php asde_e( 'Edad' ); ?></th>
					<th scope="col"><?php asde_e( 'Sección' ); ?></th>
					<th scope="col"><?php asde_e( 'Estado' ); ?></th>
					<th scope="col"><?php asde_e( 'En la lista desde' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $lista as $f ) : ?>
					<tr>
						<td data-label="<?php echo esc_attr( asde_a( 'Orden' ) ); ?>"><?php echo isset( $orden[ $f['id'] ] ) ? (int) $orden[ $f['id'] ] : '—'; ?></td>
						<td data-label="<?php echo esc_attr( asde_a( 'Nombre' ) ); ?>">
							<a href="<?php echo esc_url( add_query_arg( 'inscripcion', $f['id'], $url ) ); ?>" data-asde-recarga><?php echo esc_html( trim( $f['datos']['nombre'] . ' ' . $f['datos']['apellidos'] ) ); ?></a>
							<?php if ( $f['repetida'] ) : ?>
								<span class="asde-badge asde-badge--estado-invitado" title="<?php echo esc_attr( asde_a( 'Apuntada más de una vez con distinto email' ) ); ?>"><?php asde_e( 'Repetida' ); ?></span>
							<?php endif; ?>
						</td>
						<td data-label="<?php echo esc_attr( asde_a( 'Edad' ) ); ?>"><?php echo null !== $f['edad'] ? esc_html( sprintf( asde_t( '%d años' ), $f['edad'] ) ) : '—'; ?></td>
						<td data-label="<?php echo esc_attr( asde_a( 'Sección' ) ); ?>">
							<?php if ( '' !== $f['seccion_final'] ) : ?>
								<?php echo Secciones::badge( $f['seccion_final'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
								<?php if ( '' !== $f['seccion'] ) : ?>
									<span class="asde-help"><?php asde_e( 'asignada' ); ?></span>
								<?php endif; ?>
							<?php else : ?>
								<span class="asde-muted"><?php asde_e( 'Aún sin edad' ); ?></span>
							<?php endif; ?>
						</td>
						<td data-label="<?php echo esc_attr( asde_a( 'Estado' ) ); ?>">
							<?php echo esc_html( asde_ta( Inscripciones::ESTADOS )[ $f['estado'] ] ?? $f['estado'] ); ?>
							<?php if ( $f['contactada_en'] ) : ?>
								<br><span class="asde-help"><?php echo esc_html( sprintf( asde_t( 'Último contacto: %1$s, %2$s' ), asde_ta( Inscripciones::MEDIOS )[ $f['contacto_medio'] ] ?? '', $fecha( $f['contactada_en'] ) ) ); ?></span>
							<?php endif; ?>
						</td>
						<td data-label="<?php echo esc_attr( asde_a( 'En la lista desde' ) ); ?>"><?php echo esc_html( $fecha( $f['confirmada_en'] ?: $f['creada'] ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<p class="asde-muted"><?php echo esc_html( sprintf( asde_n( '%d inscripción', '%d inscripciones', count( $lista ) ), count( $lista ) ) ); ?></p>
	<?php endif; ?>
	</div>
</section>
