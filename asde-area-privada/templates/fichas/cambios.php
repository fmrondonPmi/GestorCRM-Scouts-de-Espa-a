<?php
/**
 * Bandeja de cambios propuestos (v0.29: agrupada por persona).
 *
 * Sin $socio, la lista: una fila por persona. Con $socio, la ventana de esa persona:
 * en «Pendientes», todos sus cambios con una casilla en cada uno, «Todos» arriba de la
 * columna y un solo botón para aprobar (o rechazar) los marcados; en «Resueltos», las
 * propuestas revisadas y los cambios hechos directamente en su ficha.
 *
 * @var array<string, array{persona: AsdeBalears\AreaPrivada\Domain\Persona, cambios: array[], directos: array[], ultimo: string}> $grupos
 * @var string               $ver       pendientes | resueltos
 * @var string               $socio     '' o el nº de la persona de la ventana
 * @var int                  $dias      Días de cambios directos que se enseñan
 * @var array<string, array> $meta      Metadatos de los campos
 * @var bool                 $escritura
 * @var string               $url
 * @var string               $url_ficha
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Crm\Escritura;
use AsdeBalears\AreaPrivada\Fichas\Cambios;
use AsdeBalears\AreaPrivada\Fichas\FichaService;
use AsdeBalears\AreaPrivada\Socios\Numeros;

defined( 'ABSPATH' ) || exit;

$fecha   = static fn( ?string $f ): string => $f ? wp_date( 'd/m/Y H:i', (int) strtotime( $f . ' UTC' ) ) : '';
$quien   = static fn( int $id ): string => $id && ( $u = get_userdata( $id ) ) ? $u->user_login : '—';
$mostrar = static fn( string $campo, string $v ): string => FichaService::mostrar( $v, $meta[ $campo ] ?? array() );
$yo      = get_current_user_id();
$g       = '' !== $socio ? ( $grupos[ $socio ] ?? null ) : null;
$url_ver = 'resueltos' === $ver ? add_query_arg( 'ver', 'resueltos', $url ) : $url;
?>
<?php if ( '' !== $socio ) : ?>
	<p class="asde-back asde-solo-pagina"><a href="<?php echo esc_url( $url_ver ); ?>"><?php esc_html_e( '← Proposed changes', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a></p>
	<section class="asde-card" data-asde-plegable="cambios-persona">
		<?php /* translators: %s: nombre de la persona */ ?>
		<h2 class="asde-card__title"><?php echo $g ? esc_html( sprintf( /* translators: %s: text */ __( 'Changes to %s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $g['persona']->nombre_completo() ) ) : esc_html( __( 'Proposed changes', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?></h2>
		<?php if ( ! $g ) : ?>
			<p><?php echo 'pendientes' === $ver ? esc_html( __( 'There are no pending changes left for this person.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) : esc_html( __( 'There are no resolved changes for this person.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?></p>
		<?php else : ?>
			<p class="asde-muted"><a href="<?php echo esc_url( add_query_arg( 'socio', $socio, $url_ficha ) ); ?>" data-asde-modal><?php esc_html_e( 'View the record', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a><?php echo wp_kses( Numeros::compacto( $g['persona'] ), asde_html_permitido() ); ?></p>
		<?php endif; ?>

		<?php if ( $g && 'pendientes' === $ver ) : ?>
			<?php
			$revisables = array_filter( $g['cambios'], static fn( $c ) => (int) $c['propuesto_por'] !== $yo );
			$conflictos = array_filter( $revisables, static fn( $c ) => ! Escritura::iguales( (string) ( $c['actual'] ?? '' ), $c['anterior'] ) );
			?>
			<?php if ( ! $escritura ) : ?>
				<div class="asde-notice asde-notice--warning" role="status"><?php esc_html_e( 'The group\'s CRM user cannot edit records right now: changes cannot be approved until it can.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></div>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( add_query_arg( 'socio', $socio, $url ) ); ?>" class="asde-form asde-cambios-revisar">
				<?php wp_nonce_field( 'asde_revisar_socio_' . $socio ); ?>
				<input type="hidden" name="socio" value="<?php echo esc_attr( $socio ); ?>">
				<table class="asde-table asde-table--cambios">
					<thead>
						<tr>
							<th scope="col" class="asde-col-marca">
								<?php if ( $revisables ) : ?>
									<label class="asde-marcar-todos"><input type="checkbox" data-asde-marcar-todos="cambios[]" <?php checked( ! $conflictos ); ?>> <?php esc_html_e( 'All', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
								<?php endif; ?>
							</th>
							<th scope="col"><?php esc_html_e( 'Item of data', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Now in the CRM', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
							<th scope="col"><?php esc_html_e( 'New value', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Proposed', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $g['cambios'] as $c ) : ?>
							<?php
							$propio    = (int) $c['propuesto_por'] === $yo;
							$conflicto = ! Escritura::iguales( (string) ( $c['actual'] ?? '' ), $c['anterior'] );
							$dato      = Cambios::etiqueta( $c['campo'] );
							?>
							<tr<?php echo $conflicto ? ' class="asde-fila--aviso"' : ''; ?>>
								<td data-label="<?php echo esc_attr( esc_attr__( 'Approve', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>" class="asde-col-marca">
									<?php if ( $propio ) : ?>
										<span class="asde-muted" title="<?php echo esc_attr( esc_attr__( 'Proposed by you: someone else reviews it.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">—</span>
									<?php else : ?>
										<?php /* translators: %s: dato de la ficha */ ?>
										<input type="checkbox" name="cambios[]" value="<?php echo (int) $c['id']; ?>" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: text */ __( 'Tick the change of "%s"', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $dato ) ); ?>" <?php checked( ! $conflicto ); ?>>
										<?php if ( $conflicto ) : ?>
											<input type="hidden" name="visto[]" value="<?php echo (int) $c['id']; ?>">
										<?php endif; ?>
									<?php endif; ?>
								</td>
								<td data-label="<?php echo esc_attr( esc_attr__( 'Item of data', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo esc_html( $dato ); ?></td>
								<td data-label="<?php echo esc_attr( esc_attr__( 'Now in the CRM', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
									<?php echo esc_html( $mostrar( $c['campo'], (string) ( $c['actual'] ?? '' ) ) ); ?>
									<?php if ( $conflicto ) : ?>
										<br><span class="asde-help asde-help--warning"><?php esc_html_e( '⚠️ It has changed since the proposal (then it was "', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?><?php echo esc_html( $mostrar( $c['campo'], $c['anterior'] ) ); ?>»).</span>
									<?php endif; ?>
								</td>
								<td data-label="<?php echo esc_attr( esc_attr__( 'New value', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><strong><?php echo esc_html( $mostrar( $c['campo'], $c['nuevo'] ) ); ?></strong></td>
								<td data-label="<?php echo esc_attr( esc_attr__( 'Proposed', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
									<?php echo esc_html( $fecha( $c['propuesto_en'] ) ); ?><br>
									<span class="asde-muted"><?php echo $propio ? esc_html( __( 'Proposed by you: someone else reviews it.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) : esc_html( $quien( (int) $c['propuesto_por'] ) ); ?></span>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<?php if ( $revisables ) : ?>
					<p class="asde-help"><?php esc_html_e( 'The ticked changes are saved in the CRM at once. Those marked ⚠️ (the CRM value has changed since the proposal) come unticked: tick them if you want to apply them anyway.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
					<p class="asde-form__acciones">
						<button type="submit" name="asde_action" value="aprobar_varios" class="button button-primary" <?php disabled( ! $escritura ); ?>><?php esc_html_e( 'Approve the selected', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
					</p>
					<p class="asde-form__fila asde-form__fila--compacta">
						<label for="asde-cambios-motivo" class="screen-reader-text"><?php esc_html_e( 'Reason for the rejection', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
						<input type="text" id="asde-cambios-motivo" name="motivo" maxlength="255" placeholder="<?php echo esc_attr( esc_attr__( 'Reason (if you reject them)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
						<button type="submit" name="asde_action" value="rechazar_varios" class="asde-btn-link asde-btn-danger"><?php esc_html_e( 'Reject the selected', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
					</p>
				<?php else : ?>
					<p class="asde-muted"><?php esc_html_e( 'You proposed them: someone else reviews them.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
				<?php endif; ?>
			</form>
		<?php elseif ( $g ) : ?>
			<?php if ( $g['cambios'] ) : ?>
				<h3><?php esc_html_e( 'Reviewed proposals', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h3>
				<table class="asde-table">
					<thead><tr><th scope="col"><?php esc_html_e( 'Proposed', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th><th scope="col"><?php esc_html_e( 'Item of data', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th><th scope="col"><?php esc_html_e( 'New value', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th><th scope="col"><?php esc_html_e( 'Result', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th></tr></thead>
					<tbody>
						<?php foreach ( $g['cambios'] as $c ) : ?>
							<tr>
								<td data-label="<?php echo esc_attr( esc_attr__( 'Proposed', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo esc_html( $fecha( $c['propuesto_en'] ) ); ?><br><span class="asde-muted"><?php echo esc_html( $quien( (int) $c['propuesto_por'] ) ); ?></span></td>
								<td data-label="<?php echo esc_attr( esc_attr__( 'Item of data', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo esc_html( Cambios::etiqueta( $c['campo'] ) ); ?></td>
								<td data-label="<?php echo esc_attr( esc_attr__( 'New value', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo esc_html( $mostrar( $c['campo'], $c['nuevo'] ) ); ?></td>
								<td data-label="<?php echo esc_attr( esc_attr__( 'Result', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
									<?php echo esc_html( ( Cambios::estados()[ $c['estado'] ] ?? $c['estado'] ) ); ?>
									<?php if ( '' !== $c['motivo'] ) : ?>
										<br><span class="asde-muted"><?php echo esc_html( $c['motivo'] ); ?></span>
									<?php endif; ?>
									<?php if ( ! empty( $c['revisado_en'] ) ) : ?>
										<br><span class="asde-muted"><?php echo esc_html( $fecha( $c['revisado_en'] ) . ' · ' . $quien( (int) $c['revisado_por'] ) ); ?></span>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
			<?php if ( $g['directos'] ) : ?>
				<h3><?php esc_html_e( 'Made directly on the record', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h3>
				<?php /* translators: %d: días */ ?>
				<p class="asde-help"><?php echo esc_html( sprintf( /* translators: %d: number */ __( 'From the last %d days. Only which data was changed, by whom and when is known: the values are not stored on the website.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $dias ) ); ?></p>
				<table class="asde-table">
					<thead><tr><th scope="col"><?php esc_html_e( 'Date', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th><th scope="col"><?php esc_html_e( 'Who', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th><th scope="col"><?php esc_html_e( 'Details', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th></tr></thead>
					<tbody>
						<?php foreach ( $g['directos'] as $d ) : ?>
							<tr>
								<td data-label="<?php echo esc_attr( esc_attr__( 'Date', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo esc_html( $fecha( $d['creado'] ) ); ?></td>
								<td data-label="<?php echo esc_attr( esc_attr__( 'Who', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo esc_html( $quien( $d['user_id'] ) ); ?><br><span class="asde-muted"><?php echo esc_html( ( Cambios::motivos_directos()[ $d['motivo'] ] ) ); ?></span></td>
								<td data-label="<?php echo esc_attr( esc_attr__( 'Details', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo esc_html( implode( ', ', array_map( array( Cambios::class, 'etiqueta' ), $d['campos'] ) ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		<?php endif; ?>
	</section>
<?php else : ?>
	<section class="asde-card" data-asde-inicio="<?php echo $grupos && 'pendientes' === $ver ? 'abierto' : 'cerrado'; ?>">
		<header class="asde-card__header asde-card__header--split">
			<h2 class="asde-card__title"><?php esc_html_e( 'Changes proposed by families', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h2>
			<span class="asde-actions">
				<a href="<?php echo esc_url( $url ); ?>"<?php echo 'pendientes' === $ver ? ' aria-current="page"' : ''; ?>><?php esc_html_e( 'Pending', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a>
				<a href="<?php echo esc_url( add_query_arg( 'ver', 'resueltos', $url ) ); ?>"<?php echo 'resueltos' === $ver ? ' aria-current="page"' : ''; ?>><?php esc_html_e( 'Resolved', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a>
			</span>
		</header>
		<?php if ( 'pendientes' === $ver ) : ?>
			<p><?php esc_html_e( 'When a change is approved it is saved in the CRM. If the CRM value has changed since it was proposed, you are warned before it is overwritten. Nobody can approve their own proposals.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
			<?php if ( ! $escritura ) : ?>
				<div class="asde-notice asde-notice--warning" role="status"><?php esc_html_e( 'The group\'s CRM user cannot edit records right now: changes cannot be approved until it can.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></div>
			<?php endif; ?>
		<?php else : ?>
			<?php /* translators: %d: días */ ?>
			<p><?php echo esc_html( sprintf( /* translators: %d: number */ __( 'The proposals already reviewed and the changes made directly on records by the Secretary\'s office, Coordination or the scouters (of these, those of the last %d days).', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $dias ) ); ?></p>
		<?php endif; ?>

		<?php if ( ! $grupos ) : ?>
			<p><?php echo 'pendientes' === $ver ? esc_html( __( 'There are no changes pending review. 🎉', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) : esc_html( __( 'There are no resolved changes yet.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?></p>
		<?php else : ?>
			<table class="asde-table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Person', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Changes', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th>
						<th scope="col"><?php echo 'pendientes' === $ver ? esc_html( __( 'Last proposed', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) : esc_html( __( 'Latest', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?></th>
						<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $grupos as $num => $fila ) : ?>
						<?php
						$n     = count( $fila['cambios'] ) + count( $fila['directos'] );
						$datos = array_unique( array_merge( array_column( $fila['cambios'], 'campo' ), ...array_column( $fila['directos'], 'campos' ) ) );
						$enlace = add_query_arg( array_filter( array( 'socio' => (string) $num, 'ver' => 'resueltos' === $ver ? 'resueltos' : '' ) ), $url );
						?>
						<tr>
							<td data-label="<?php echo esc_attr( esc_attr__( 'Person', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><a href="<?php echo esc_url( add_query_arg( 'socio', (string) $num, $url_ficha ) ); ?>" data-asde-modal><?php echo esc_html( $fila['persona']->nombre_completo() ); ?></a><?php echo wp_kses( Numeros::compacto( $fila['persona'] ), asde_html_permitido() ); ?></td>
							<td data-label="<?php echo esc_attr( esc_attr__( 'Changes', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><strong><?php echo (int) $n; ?></strong> · <span class="asde-muted"><?php echo esc_html( implode( ', ', array_map( array( Cambios::class, 'etiqueta' ), $datos ) ) ); ?></span></td>
							<td data-label="<?php echo esc_attr( esc_attr__( 'Latest', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo esc_html( $fecha( $fila['ultimo'] ) ); ?></td>
							<td data-label="">
								<?php if ( 'pendientes' === $ver ) : ?>
									<?php /* translators: %d: número de cambios */ ?>
									<a class="button" href="<?php echo esc_url( $enlace ); ?>" data-asde-modal><?php echo esc_html( sprintf( /* translators: %d: number */ __( 'Review (%d)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $n ) ); ?></a>
								<?php else : ?>
									<a href="<?php echo esc_url( $enlace ); ?>" data-asde-modal><?php esc_html_e( 'View', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</section>
<?php endif; ?>
