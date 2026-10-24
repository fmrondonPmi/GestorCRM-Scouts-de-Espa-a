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
 * @var array[]|null     $campos  Campos propios del formulario (null si no puede editarlos)
 * @var string           $declaracion Texto propio de la casilla de declaración ('' = el de por defecto)
 * @var bool             $altas    Puede dar de alta en el CRM («Se incorpora como asociado/a», doc 59, RC-NUE-1)
 * @var string           $url_alta Personas → Alta de socio/a
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Domain\Secciones;
use AsdeBalears\AreaPrivada\ListaEspera\CamposExtra;
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

<?php if ( null !== $campos ) : ?>
	<?php
	$tipos  = asde_ta( CamposExtra::TIPOS );
	$filas  = $campos;
	$libres = max( 2, min( 4, CamposExtra::MAX_CAMPOS - count( $campos ) ) );
	for ( $i = 0; $i < $libres && count( $filas ) < CamposExtra::MAX_CAMPOS; $i++ ) {
		$filas[] = array(
			'id'          => '',
			'etiqueta'    => '',
			'tipo'        => CamposExtra::TEXTO,
			'obligatorio' => false,
		);
	}
	?>
	<section class="asde-card" data-asde-inicio="cerrado">
		<h3 class="asde-card__title"><?php asde_e( 'Campos propios del formulario' ); ?></h3>
		<p><?php asde_e( 'Además de los datos que siempre se piden, tu grupo puede añadir preguntas al formulario de la lista de espera. Cada campo necesita su etiqueta (el texto que verá quien se apunta) y se elige su tipo y si es obligatorio. Las respuestas se guardan cifradas con la clave del email de quien se apunta y se borran con la inscripción. Quitar o cambiar un campo no altera lo que ya contestaron.' ); ?></p>
		<form method="post" class="asde-form">
			<?php wp_nonce_field( 'asde_espera_campos' ); ?>
			<input type="hidden" name="asde_action" value="espera_campos">
			<table class="asde-table">
				<thead>
					<tr>
						<th scope="col"><?php asde_e( 'Etiqueta' ); ?></th>
						<th scope="col"><?php asde_e( 'Tipo' ); ?></th>
						<th scope="col"><?php asde_e( 'Obligatorio' ); ?></th>
						<th scope="col"><?php asde_e( 'Quitar' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $filas as $i => $c ) : ?>
						<tr>
							<td data-label="<?php echo esc_attr( asde_a( 'Etiqueta' ) ); ?>">
								<input type="hidden" name="campo[<?php echo (int) $i; ?>][id]" value="<?php echo esc_attr( $c['id'] ); ?>">
								<input type="text" name="campo[<?php echo (int) $i; ?>][etiqueta]" value="<?php echo esc_attr( $c['etiqueta'] ); ?>" maxlength="<?php echo (int) CamposExtra::MAX_ETIQUETA; ?>" aria-label="<?php echo esc_attr( asde_a( 'Etiqueta' ) ); ?>">
							</td>
							<td data-label="<?php echo esc_attr( asde_a( 'Tipo' ) ); ?>">
								<select name="campo[<?php echo (int) $i; ?>][tipo]" aria-label="<?php echo esc_attr( asde_a( 'Tipo' ) ); ?>">
									<?php foreach ( $tipos as $k => $nombre ) : ?>
										<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $c['tipo'], $k ); ?>><?php echo esc_html( $nombre ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
							<td data-label="<?php echo esc_attr( asde_a( 'Obligatorio' ) ); ?>">
								<select name="campo[<?php echo (int) $i; ?>][obligatorio]" aria-label="<?php echo esc_attr( asde_a( 'Obligatorio' ) ); ?>">
									<option value="0" <?php selected( ! $c['obligatorio'] ); ?>><?php asde_e( 'Opcional' ); ?></option>
									<option value="1" <?php selected( $c['obligatorio'] ); ?>><?php asde_e( 'Obligatorio' ); ?></option>
								</select>
							</td>
							<td data-label="<?php echo esc_attr( asde_a( 'Quitar' ) ); ?>">
								<?php if ( '' !== $c['id'] ) : ?>
									<label class="asde-choice"><input type="checkbox" name="campo[<?php echo (int) $i; ?>][quitar]" value="1"><span><?php asde_e( 'Quitar' ); ?></span></label>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p>
				<label for="asde-espera-declaracion"><?php asde_e( 'Texto de la casilla de declaración' ); ?></label>
				<textarea id="asde-espera-declaracion" name="declaracion" rows="3" maxlength="<?php echo (int) CamposExtra::MAX_DECLARACION; ?>" placeholder="<?php echo esc_attr( asde_a( 'Declaro que todos los datos que he introducido son veraces y acepto las condiciones del grupo scout.' ) ); ?>"><?php echo esc_textarea( $declaracion ); ?></textarea>
				<span class="asde-help"><?php asde_e( 'Quien se apunta tiene que marcar esta casilla para continuar. Déjalo vacío para usar el texto de por defecto (el que se ve en gris).' ); ?></span>
			</p>
			<p class="asde-help"><?php echo esc_html( sprintf( asde_t( 'Las filas vacías se ignoran. Para añadir más campos, guarda y vuelve a abrir este cuadro. Máximo %d campos.' ), CamposExtra::MAX_CAMPOS ) ); ?></p>
			<p><button type="submit" class="button button-primary"><?php asde_e( 'Guardar los campos' ); ?></button></p>
		</form>
	</section>
<?php endif; ?>

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
							<?php if ( Inscripciones::ASOCIADA === $f['estado'] ) : ?>
								<a href="<?php echo esc_url( add_query_arg( 'inscripcion', $f['id'], $url ) ); ?>" data-asde-recarga><?php echo esc_html( sprintf( asde_t( 'Asociado/a nº %s' ), '' !== $f['socio'] ? $f['socio'] : '—' ) ); ?></a>
							<?php else : ?>
								<a href="<?php echo esc_url( add_query_arg( 'inscripcion', $f['id'], $url ) ); ?>" data-asde-recarga><?php echo esc_html( trim( $f['datos']['nombre'] . ' ' . $f['datos']['apellidos'] ) ); ?></a>
							<?php endif; ?>
							<?php if ( $f['repetida'] && Inscripciones::ASOCIADA !== $f['estado'] ) : ?>
								<span class="asde-badge asde-badge--estado-invitado" title="<?php echo esc_attr( asde_a( 'Apuntada más de una vez con distinto email' ) ); ?>"><?php asde_e( 'Repetida' ); ?></span>
							<?php endif; ?>
							<?php if ( ! empty( $altas ) && ListaEsperaView::sigue( $f ) ) : ?>
								<form method="post" action="<?php echo esc_url( $url_alta ); ?>" class="asde-linea">
									<?php wp_nonce_field( 'asde_alta_socio' ); ?>
									<input type="hidden" name="asde_action" value="alta_documento">
									<input type="hidden" name="espera" value="<?php echo (int) $f['id']; ?>">
									<button type="submit" class="button button-small"><?php asde_e( 'Se incorpora como asociado/a' ); ?></button>
								</form>
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
