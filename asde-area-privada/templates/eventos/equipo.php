<?php
/**
 * Evento · equipo dirigente, con lo que dice la ficha de cada persona (formación y
 * certificado de delitos sexuales, leídos en vivo y sin guardar) y la ratio.
 *
 * @var array                                                   $e
 * @var AsdeBalears\AreaPrivada\Auth\Ambito                     $ambito
 * @var array[]                                                 $equipo
 * @var array<string, AsdeBalears\AreaPrivada\Domain\Persona>   $nombres
 * @var array                                                   $check      Normativa::comprobar_equipo
 * @var array<string, AsdeBalears\AreaPrivada\Domain\Persona>   $candidatos Scouters y voluntariado que aún no están
 * @var string                                                  $url
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Domain\Secciones;
use AsdeBalears\AreaPrivada\Eventos\InscripcionesTable;
use AsdeBalears\AreaPrivada\Eventos\Servicio;

defined( 'ABSPATH' ) || exit;

$editable = Servicio::puede_editar( $e, $ambito );
$campos   = static function ( string $accion ) use ( $e ): string {
	return wp_nonce_field( 'asde_evento_' . $e['id'], '_wpnonce', true, false )
		. '<input type="hidden" name="asde_action" value="' . esc_attr( $accion ) . '">'
		. '<input type="hidden" name="evento" value="' . (int) $e['id'] . '">'
		. '<input type="hidden" name="pestana" value="equipo">';
};
$titulos  = array(
	'director' => __( 'Director', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
	'monitor'  => __( 'Monitor', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
	''         => '—',
);
$certs    = array(
	'ok'        => array( 'ok', __( 'Up to date', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ),
	'caducado'  => array( 'aviso', __( 'Expired at the end', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ),
	'sin_fecha' => array( 'aviso', __( 'No date', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ),
	'no'        => array( 'aviso', __( 'Not recorded', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ),
	''          => array( 'info', '—' ),
);
$r        = $check['ratio'];
?>
<section class="asde-card">
	<h3 class="asde-card__title"><?php esc_html_e( 'Leadership team', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h3>
	<p class="asde-muted"><?php esc_html_e( 'A qualified director and the monitors required by the ratio, all of legal age and with a sexual offences certificate less than 12 months old. Training and certificate are read from the CRM record.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
	<?php if ( ! $check['leido'] ) : ?>
		<p class="asde-notice asde-notice--warning"><?php esc_html_e( 'The team\'s training and certificate could not be read in the CRM: check them later.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
	<?php endif; ?>

	<?php if ( ! $equipo ) : ?>
		<p><?php esc_html_e( 'There is nobody on the team yet.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
	<?php else : ?>
		<table class="asde-table asde-table--equipo">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Person', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Function in the team', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Qualification', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Certificate', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
					<?php if ( $editable ) : ?>
						<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Remove', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span></th>
					<?php endif; ?>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $equipo as $m ) : ?>
					<?php
					$p    = $nombres[ $m['socio'] ] ?? null;
					$dato = $check['personas'][ $m['socio'] ] ?? array(
						'menor'       => false,
						'titulo'      => '',
						'certificado' => '',
					);
					$cert = $certs[ $dato['certificado'] ] ?? $certs[''];
					?>
					<tr>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Person', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
							<?php echo esc_html( $p ? $p->nombre_completo() : sprintf( /* translators: %s: text */ __( 'No. %s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $m['socio'] ) ); ?>
							<?php
							foreach ( $m['secciones'] as $s ) {
								echo wp_kses( Secciones::badge( $s ), asde_html_permitido() );
							}
							?>
							<?php if ( $dato['menor'] ) : ?>
								<br><span class="asde-comprobacion--aviso"><?php esc_html_e( 'Minor', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span>
							<?php endif; ?>
						</td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Function in the team', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
							<?php if ( $editable ) : ?>
								<form method="post" class="asde-inline-form">
									<?php echo wp_kses( $campos( 'evento_equipo_papel' ), asde_html_permitido() ); ?>
									<input type="hidden" name="fila" value="<?php echo (int) $m['id']; ?>">
									<label class="screen-reader-text" for="asde-papel-<?php echo (int) $m['id']; ?>"><?php esc_html_e( 'Function in the team', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
									<select id="asde-papel-<?php echo (int) $m['id']; ?>" name="papel">
										<?php foreach ( asde_ta( InscripcionesTable::PAPELES ) as $papel => $etiqueta ) : ?>
											<option value="<?php echo esc_attr( $papel ); ?>" <?php selected( $m['papel'], $papel ); ?>><?php echo esc_html( $etiqueta ); ?></option>
										<?php endforeach; ?>
									</select>
									<button type="submit" class="asde-btn-link"><?php esc_html_e( 'Change', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
								</form>
							<?php else : ?>
								<?php echo esc_html( asde_t( InscripcionesTable::PAPELES[ $m['papel'] ] ?? $m['papel'] ) ); ?>
							<?php endif; ?>
						</td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Qualification', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo esc_html( $check['leido'] ? $titulos[ $dato['titulo'] ] : '—' ); ?></td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Certificate', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><span class="asde-comprobacion--<?php echo esc_attr( $cert[0] ); ?>"><?php echo esc_html( $cert[1] ); ?></span></td>
						<?php if ( $editable ) : ?>
							<td data-label="<?php echo esc_attr( esc_attr__( 'Remove', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
								<form method="post" class="asde-inline-form" data-asde-confirmar="<?php echo esc_attr( __( 'Remove this person from the team?', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
									<?php echo wp_kses( $campos( 'evento_equipo_quitar' ), asde_html_permitido() ); ?>
									<input type="hidden" name="fila" value="<?php echo (int) $m['id']; ?>">
									<button type="submit" class="asde-btn-link"><?php esc_html_e( 'Remove', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
								</form>
							</td>
						<?php endif; ?>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>

	<p class="asde-ratio">
		<?php echo esc_html( sprintf( /* translators: %d: number */ __( 'Participants: %d (the places or, if there are none, the expected ones).', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $r['participantes'] ) ); ?>
		<?php echo esc_html( sprintf( /* translators: 1: number, 2: number, 3: number */ __( '%1$d monitors are needed besides the director (%2$d support monitors for dependency) and there are %3$d.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $r['necesarios'], $r['apoyo'], $r['monitores'] ) ); ?>
	</p>
	<?php if ( $check['avisos'] ) : ?>
		<ul class="asde-comprobacion">
			<?php foreach ( $check['avisos'] as $aviso ) : ?>
				<li class="asde-comprobacion--aviso"><span class="asde-comprobacion__marca" aria-hidden="true">!</span> <?php echo esc_html( $aviso ); ?></li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
</section>

<?php if ( $editable ) : ?>
	<section class="asde-card">
		<h3 class="asde-card__title"><?php esc_html_e( 'Add to the team', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h3>
		<?php if ( ! $candidatos ) : ?>
			<p class="asde-muted"><?php esc_html_e( 'There are no more scouters or volunteers of the group to add.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
		<?php else : ?>
			<form method="post" class="asde-form asde-form--compacta">
				<?php echo wp_kses( $campos( 'evento_equipo_anadir' ), asde_html_permitido() ); ?>
				<p class="asde-form__fila">
					<label><?php esc_html_e( 'Person', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?>
						<select name="socio" required>
							<option value=""><?php esc_html_e( '— Choose —', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></option>
							<?php foreach ( $candidatos as $n => $p ) : ?>
								<option value="<?php echo esc_attr( $n ); ?>"><?php echo esc_html( $p->nombre_completo() . ' · ' . implode( ', ', array_map( array( Secciones::class, 'nombre' ), $p->secciones ) ) ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
					<label><?php esc_html_e( 'Function in the team', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?>
						<select name="papel">
							<?php foreach ( asde_ta( InscripcionesTable::PAPELES ) as $papel => $etiqueta ) : ?>
								<option value="<?php echo esc_attr( $papel ); ?>" <?php selected( 'monitor', $papel ); ?>><?php echo esc_html( $etiqueta ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
					<button type="submit" class="button"><?php esc_html_e( 'Add', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
				</p>
				<p class="asde-help"><?php esc_html_e( 'Whoever is on the team can manage the event. The team does not pay: its cost is spread across the participants\' price.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
			</form>
		<?php endif; ?>
	</section>
<?php endif; ?>
