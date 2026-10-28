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
	<h2 class="asde-card__title"><?php esc_html_e( 'Waiting list', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h2>
	<p><?php esc_html_e( 'Pass this link to whoever wants to join the group. They leave their details, confirm their email and appear here. Their data is stored encrypted and deleted on registration, on request or after 12 months (unless they say they keep it).', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
	<?php if ( '' !== $enlace ) : ?>
		<p class="asde-copiar">
			<code class="asde-copiar__texto" id="asde-enlace-espera"><?php echo esc_html( $enlace ); ?></code>
			<button type="button" class="asde-btn-link" data-asde-copiar="asde-enlace-espera"><?php esc_html_e( 'Copy the link', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
		</p>
	<?php endif; ?>
	<?php if ( $renovar ) : ?>
		<form method="post" data-asde-confirmar="<?php echo esc_attr( __( 'Generate a new link? The one you already passed on will stop working.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
			<?php wp_nonce_field( 'asde_enlace_espera' ); ?>
			<input type="hidden" name="asde_action" value="renovar_enlace_espera">
			<button type="submit" class="asde-btn-link"><?php esc_html_e( 'Generate a new link', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
			<span class="asde-help"><?php esc_html_e( 'If the link has reached someone it should not have. The personal links of each sign-up remain valid.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span>
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
		<h3 class="asde-card__title"><?php esc_html_e( 'Form\'s own fields', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h3>
		<p><?php esc_html_e( 'In addition to the data that is always requested, your group can add questions to the waiting list form. Each field needs its label (the text the person signing up will see) and you choose its type and whether it is required. Answers are stored encrypted with the key of the signing-up person\'s email and are deleted with the sign-up. Removing or changing a field does not alter what has already been answered.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
		<form method="post" class="asde-form">
			<?php wp_nonce_field( 'asde_espera_campos' ); ?>
			<input type="hidden" name="asde_action" value="espera_campos">
			<table class="asde-table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Label', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Type', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Required', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Remove', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $filas as $i => $c ) : ?>
						<tr>
							<td data-label="<?php echo esc_attr( esc_attr__( 'Label', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
								<input type="hidden" name="campo[<?php echo (int) $i; ?>][id]" value="<?php echo esc_attr( $c['id'] ); ?>">
								<input type="text" name="campo[<?php echo (int) $i; ?>][etiqueta]" value="<?php echo esc_attr( $c['etiqueta'] ); ?>" maxlength="<?php echo (int) CamposExtra::MAX_ETIQUETA; ?>" aria-label="<?php echo esc_attr( esc_attr__( 'Label', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
							</td>
							<td data-label="<?php echo esc_attr( esc_attr__( 'Type', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
								<select name="campo[<?php echo (int) $i; ?>][tipo]" aria-label="<?php echo esc_attr( esc_attr__( 'Type', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
									<?php foreach ( $tipos as $k => $nombre ) : ?>
										<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $c['tipo'], $k ); ?>><?php echo esc_html( $nombre ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
							<td data-label="<?php echo esc_attr( esc_attr__( 'Required', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
								<select name="campo[<?php echo (int) $i; ?>][obligatorio]" aria-label="<?php echo esc_attr( esc_attr__( 'Required', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
									<option value="0" <?php selected( ! $c['obligatorio'] ); ?>><?php esc_html_e( 'Optional', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></option>
									<option value="1" <?php selected( $c['obligatorio'] ); ?>><?php esc_html_e( 'Required', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></option>
								</select>
							</td>
							<td data-label="<?php echo esc_attr( esc_attr__( 'Remove', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
								<?php if ( '' !== $c['id'] ) : ?>
									<label class="asde-choice"><input type="checkbox" name="campo[<?php echo (int) $i; ?>][quitar]" value="1"><span><?php esc_html_e( 'Remove', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span></label>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p>
				<label for="asde-espera-declaracion"><?php esc_html_e( 'Text of the declaration box', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
				<textarea id="asde-espera-declaracion" name="declaracion" rows="3" maxlength="<?php echo (int) CamposExtra::MAX_DECLARACION; ?>" placeholder="<?php echo esc_attr( esc_attr__( 'I declare that all the data I have entered is truthful and I accept the Scout group\'s conditions.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo esc_textarea( $declaracion ); ?></textarea>
				<span class="asde-help"><?php esc_html_e( 'The person signing up must tick this box to continue. Leave it empty to use the default text (the one shown in grey).', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span>
			</p>
			<p class="asde-help"><?php echo esc_html( sprintf( /* translators: %d: number */ __( 'Empty rows are ignored. To add more fields, save and reopen this box. Maximum %d fields.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), CamposExtra::MAX_CAMPOS ) ); ?></p>
			<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Save the fields', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button></p>
		</form>
	</section>
<?php endif; ?>

<section class="asde-card">
	<form method="get" action="<?php echo esc_url( $url ); ?>" class="asde-filter asde-filter--una-linea" data-asde-parcial-form="espera-lista">
		<label for="asde-espera-estado"><?php esc_html_e( 'Show', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
		<select id="asde-espera-estado" name="estado" data-asde-autoenviar>
			<option value="<?php echo esc_attr( ListaEsperaView::ACTIVAS ); ?>" <?php selected( $estado, ListaEsperaView::ACTIVAS ); ?>><?php echo esc_html( __( 'On the list', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) . ' (' . $total . ')' ); ?></option>
			<?php foreach ( asde_ta( Inscripciones::ESTADOS ) as $e => $etiqueta ) : ?>
				<option value="<?php echo esc_attr( $e ); ?>" <?php selected( $estado, $e ); ?>><?php echo esc_html( $etiqueta ); ?></option>
			<?php endforeach; ?>
			<option value="todas" <?php selected( $estado, 'todas' ); ?>><?php esc_html_e( 'All', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></option>
		</select>
		<label for="asde-espera-seccion"><?php esc_html_e( 'Section', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
		<select id="asde-espera-seccion" name="seccion" data-asde-autoenviar>
			<option value=""><?php esc_html_e( 'All', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></option>
			<?php foreach ( array_keys( Secciones::EDAD_DESDE ) as $s ) : ?>
				<option value="<?php echo esc_attr( $s ); ?>" <?php selected( $seccion, $s ); ?>><?php echo esc_html( Secciones::nombre( $s ) ); ?></option>
			<?php endforeach; ?>
			<option value="sin" <?php selected( $seccion, 'sin' ); ?>><?php esc_html_e( 'No age yet', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></option>
		</select>
		<button type="submit" class="button"><?php esc_html_e( 'View', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
	</form>

	<div data-asde-parcial="espera-lista">
	<?php if ( ! $lista ) : ?>
		<p class="asde-muted"><?php esc_html_e( 'There is nobody on the waiting list with this filter.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
	<?php else : ?>
		<table class="asde-table asde-espera-tabla">
			<thead>
				<tr>
					<th scope="col"><abbr title="<?php echo esc_attr( esc_attr__( 'Order of arrival in their section', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php esc_html_e( 'Sort order', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></abbr></th>
					<th scope="col"><?php esc_html_e( 'Name', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Age', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Section', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Status', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
					<th scope="col"><?php esc_html_e( 'On the list since', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $lista as $f ) : ?>
					<tr>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Sort order', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo isset( $orden[ $f['id'] ] ) ? (int) $orden[ $f['id'] ] : '—'; ?></td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Name', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
							<?php if ( Inscripciones::ASOCIADA === $f['estado'] ) : ?>
								<a href="<?php echo esc_url( add_query_arg( 'inscripcion', $f['id'], $url ) ); ?>" data-asde-recarga><?php echo esc_html( sprintf( /* translators: %s: text */ __( 'Member no. %s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), '' !== $f['socio'] ? $f['socio'] : '—' ) ); ?></a>
							<?php else : ?>
								<a href="<?php echo esc_url( add_query_arg( 'inscripcion', $f['id'], $url ) ); ?>" data-asde-recarga><?php echo esc_html( trim( $f['datos']['nombre'] . ' ' . $f['datos']['apellidos'] ) ); ?></a>
							<?php endif; ?>
							<?php if ( $f['repetida'] && Inscripciones::ASOCIADA !== $f['estado'] ) : ?>
								<span class="asde-badge asde-badge--estado-invitado" title="<?php echo esc_attr( esc_attr__( 'Signed up more than once with a different email', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php esc_html_e( 'Repeated', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span>
							<?php endif; ?>
							<?php if ( ! empty( $altas ) && ListaEsperaView::sigue( $f ) ) : ?>
								<form method="post" action="<?php echo esc_url( $url_alta ); ?>" class="asde-linea">
									<?php wp_nonce_field( 'asde_alta_socio' ); ?>
									<input type="hidden" name="asde_action" value="alta_documento">
									<input type="hidden" name="espera" value="<?php echo (int) $f['id']; ?>">
									<button type="submit" class="button button-small"><?php esc_html_e( 'Joins as a member', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
								</form>
							<?php endif; ?>
						</td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Age', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo null !== $f['edad'] ? esc_html( sprintf( /* translators: %d: number */ __( '%d years', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $f['edad'] ) ) : '—'; ?></td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Section', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
							<?php if ( '' !== $f['seccion_final'] ) : ?>
								<?php echo wp_kses( Secciones::badge( $f['seccion_final'] ), asde_html_permitido() ); ?>
								<?php if ( '' !== $f['seccion'] ) : ?>
									<span class="asde-help"><?php esc_html_e( 'assigned', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span>
								<?php endif; ?>
							<?php else : ?>
								<span class="asde-muted"><?php esc_html_e( 'No age yet', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span>
							<?php endif; ?>
						</td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Status', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
							<?php echo esc_html( asde_ta( Inscripciones::ESTADOS )[ $f['estado'] ] ?? $f['estado'] ); ?>
							<?php if ( $f['contactada_en'] ) : ?>
								<br><span class="asde-help"><?php echo esc_html( sprintf( /* translators: 1: text, 2: text */ __( 'Last contact: %1$s, %2$s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), asde_ta( Inscripciones::MEDIOS )[ $f['contacto_medio'] ] ?? '', $fecha( $f['contactada_en'] ) ) ); ?></span>
							<?php endif; ?>
						</td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'On the list since', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo esc_html( $fecha( $f['confirmada_en'] ?: $f['creada'] ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<p class="asde-muted"><?php echo esc_html( sprintf( /* translators: %d: number */ _n( '%d sign-up', '%d sign-ups', count( $lista ) , 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), count( $lista ) ) ); ?></p>
	<?php endif; ?>
	</div>
</section>
