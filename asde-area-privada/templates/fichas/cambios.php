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
	<p class="asde-back asde-solo-pagina"><a href="<?php echo esc_url( $url_ver ); ?>"><?php asde_e( '← Cambios propuestos' ); ?></a></p>
	<section class="asde-card" data-asde-plegable="cambios-persona">
		<?php /* translators: %s: nombre de la persona */ ?>
		<h2 class="asde-card__title"><?php echo $g ? esc_html( sprintf( asde_t( 'Cambios de %s' ), $g['persona']->nombre_completo() ) ) : esc_html( asde_t( 'Cambios propuestos' ) ); ?></h2>
		<?php if ( ! $g ) : ?>
			<p><?php echo 'pendientes' === $ver ? esc_html( asde_t( 'No quedan cambios pendientes de esta persona.' ) ) : esc_html( asde_t( 'No hay cambios resueltos de esta persona.' ) ); ?></p>
		<?php else : ?>
			<p class="asde-muted"><a href="<?php echo esc_url( add_query_arg( 'socio', $socio, $url_ficha ) ); ?>" data-asde-modal><?php asde_e( 'Ver la ficha' ); ?></a><?php echo Numeros::compacto( $g['persona'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></p>
		<?php endif; ?>

		<?php if ( $g && 'pendientes' === $ver ) : ?>
			<?php
			$revisables = array_filter( $g['cambios'], static fn( $c ) => (int) $c['propuesto_por'] !== $yo );
			$conflictos = array_filter( $revisables, static fn( $c ) => ! Escritura::iguales( (string) ( $c['actual'] ?? '' ), $c['anterior'] ) );
			?>
			<?php if ( ! $escritura ) : ?>
				<div class="asde-notice asde-notice--warning" role="status"><?php asde_e( 'El usuario del CRM del grupo no puede editar fichas ahora mismo: los cambios no se pueden aprobar hasta que lo tenga.' ); ?></div>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( add_query_arg( 'socio', $socio, $url ) ); ?>" class="asde-form asde-cambios-revisar">
				<?php wp_nonce_field( 'asde_revisar_socio_' . $socio ); ?>
				<input type="hidden" name="socio" value="<?php echo esc_attr( $socio ); ?>">
				<table class="asde-table asde-table--cambios">
					<thead>
						<tr>
							<th scope="col" class="asde-col-marca">
								<?php if ( $revisables ) : ?>
									<label class="asde-marcar-todos"><input type="checkbox" data-asde-marcar-todos="cambios[]" <?php checked( ! $conflictos ); ?>> <?php asde_e( 'Todos' ); ?></label>
								<?php endif; ?>
							</th>
							<th scope="col"><?php asde_e( 'Dato' ); ?></th>
							<th scope="col"><?php asde_e( 'Ahora en el CRM' ); ?></th>
							<th scope="col"><?php asde_e( 'Nuevo valor' ); ?></th>
							<th scope="col"><?php asde_e( 'Propuesto' ); ?></th>
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
								<td data-label="<?php echo esc_attr( asde_a( 'Aprobar' ) ); ?>" class="asde-col-marca">
									<?php if ( $propio ) : ?>
										<span class="asde-muted" title="<?php echo esc_attr( asde_a( 'Propuesto por ti: lo revisa otra persona.' ) ); ?>">—</span>
									<?php else : ?>
										<?php /* translators: %s: dato de la ficha */ ?>
										<input type="checkbox" name="cambios[]" value="<?php echo (int) $c['id']; ?>" aria-label="<?php echo esc_attr( sprintf( asde_t( 'Marcar el cambio de «%s»' ), $dato ) ); ?>" <?php checked( ! $conflicto ); ?>>
										<?php if ( $conflicto ) : ?>
											<input type="hidden" name="visto[]" value="<?php echo (int) $c['id']; ?>">
										<?php endif; ?>
									<?php endif; ?>
								</td>
								<td data-label="<?php echo esc_attr( asde_a( 'Dato' ) ); ?>"><?php echo esc_html( $dato ); ?></td>
								<td data-label="<?php echo esc_attr( asde_a( 'Ahora en el CRM' ) ); ?>">
									<?php echo esc_html( $mostrar( $c['campo'], (string) ( $c['actual'] ?? '' ) ) ); ?>
									<?php if ( $conflicto ) : ?>
										<br><span class="asde-help asde-help--warning"><?php asde_e( '⚠️ Ha cambiado desde la propuesta (entonces era «' ); ?><?php echo esc_html( $mostrar( $c['campo'], $c['anterior'] ) ); ?>»).</span>
									<?php endif; ?>
								</td>
								<td data-label="<?php echo esc_attr( asde_a( 'Nuevo valor' ) ); ?>"><strong><?php echo esc_html( $mostrar( $c['campo'], $c['nuevo'] ) ); ?></strong></td>
								<td data-label="<?php echo esc_attr( asde_a( 'Propuesto' ) ); ?>">
									<?php echo esc_html( $fecha( $c['propuesto_en'] ) ); ?><br>
									<span class="asde-muted"><?php echo $propio ? esc_html( asde_t( 'Propuesto por ti: lo revisa otra persona.' ) ) : esc_html( $quien( (int) $c['propuesto_por'] ) ); ?></span>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<?php if ( $revisables ) : ?>
					<p class="asde-help"><?php asde_e( 'Los cambios marcados se guardan en el CRM de una vez. Los que llevan ⚠️ (el dato del CRM ha cambiado desde la propuesta) vienen sin marcar: márcalos si quieres aplicarlos igualmente.' ); ?></p>
					<p class="asde-form__acciones">
						<button type="submit" name="asde_action" value="aprobar_varios" class="button button-primary" <?php disabled( ! $escritura ); ?>><?php asde_e( 'Aprobar los seleccionados' ); ?></button>
					</p>
					<p class="asde-form__fila asde-form__fila--compacta">
						<label for="asde-cambios-motivo" class="screen-reader-text"><?php asde_e( 'Motivo del rechazo' ); ?></label>
						<input type="text" id="asde-cambios-motivo" name="motivo" maxlength="255" placeholder="<?php echo esc_attr( asde_a( 'Motivo (si los rechazas)' ) ); ?>">
						<button type="submit" name="asde_action" value="rechazar_varios" class="asde-btn-link asde-btn-danger"><?php asde_e( 'Rechazar los seleccionados' ); ?></button>
					</p>
				<?php else : ?>
					<p class="asde-muted"><?php asde_e( 'Los has propuesto tú: los revisa otra persona.' ); ?></p>
				<?php endif; ?>
			</form>
		<?php elseif ( $g ) : ?>
			<?php if ( $g['cambios'] ) : ?>
				<h3><?php asde_e( 'Propuestas revisadas' ); ?></h3>
				<table class="asde-table">
					<thead><tr><th scope="col"><?php asde_e( 'Propuesto' ); ?></th><th scope="col"><?php asde_e( 'Dato' ); ?></th><th scope="col"><?php asde_e( 'Nuevo valor' ); ?></th><th scope="col"><?php asde_e( 'Resultado' ); ?></th></tr></thead>
					<tbody>
						<?php foreach ( $g['cambios'] as $c ) : ?>
							<tr>
								<td data-label="<?php echo esc_attr( asde_a( 'Propuesto' ) ); ?>"><?php echo esc_html( $fecha( $c['propuesto_en'] ) ); ?><br><span class="asde-muted"><?php echo esc_html( $quien( (int) $c['propuesto_por'] ) ); ?></span></td>
								<td data-label="<?php echo esc_attr( asde_a( 'Dato' ) ); ?>"><?php echo esc_html( Cambios::etiqueta( $c['campo'] ) ); ?></td>
								<td data-label="<?php echo esc_attr( asde_a( 'Nuevo valor' ) ); ?>"><?php echo esc_html( $mostrar( $c['campo'], $c['nuevo'] ) ); ?></td>
								<td data-label="<?php echo esc_attr( asde_a( 'Resultado' ) ); ?>">
									<?php echo esc_html( asde_t( Cambios::ESTADOS[ $c['estado'] ] ?? $c['estado'] ) ); ?>
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
				<h3><?php asde_e( 'Hechos directamente en la ficha' ); ?></h3>
				<?php /* translators: %d: días */ ?>
				<p class="asde-help"><?php echo esc_html( sprintf( asde_t( 'De los últimos %d días. Solo se sabe qué datos se cambiaron, quién y cuándo: los valores no se guardan en la web.' ), $dias ) ); ?></p>
				<table class="asde-table">
					<thead><tr><th scope="col"><?php asde_e( 'Fecha' ); ?></th><th scope="col"><?php asde_e( 'Quién' ); ?></th><th scope="col"><?php asde_e( 'Datos' ); ?></th></tr></thead>
					<tbody>
						<?php foreach ( $g['directos'] as $d ) : ?>
							<tr>
								<td data-label="<?php echo esc_attr( asde_a( 'Fecha' ) ); ?>"><?php echo esc_html( $fecha( $d['creado'] ) ); ?></td>
								<td data-label="<?php echo esc_attr( asde_a( 'Quién' ) ); ?>"><?php echo esc_html( $quien( $d['user_id'] ) ); ?><br><span class="asde-muted"><?php echo esc_html( asde_t( Cambios::MOTIVOS_DIRECTOS[ $d['motivo'] ] ) ); ?></span></td>
								<td data-label="<?php echo esc_attr( asde_a( 'Datos' ) ); ?>"><?php echo esc_html( implode( ', ', array_map( array( Cambios::class, 'etiqueta' ), $d['campos'] ) ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		<?php endif; ?>
	</section>
<?php else : ?>
	<section class="asde-card">
		<header class="asde-card__header asde-card__header--split">
			<h2 class="asde-card__title"><?php asde_e( 'Cambios propuestos por las familias' ); ?></h2>
			<span class="asde-actions">
				<a href="<?php echo esc_url( $url ); ?>"<?php echo 'pendientes' === $ver ? ' aria-current="page"' : ''; ?>><?php asde_e( 'Pendientes' ); ?></a>
				<a href="<?php echo esc_url( add_query_arg( 'ver', 'resueltos', $url ) ); ?>"<?php echo 'resueltos' === $ver ? ' aria-current="page"' : ''; ?>><?php asde_e( 'Resueltos' ); ?></a>
			</span>
		</header>
		<?php if ( 'pendientes' === $ver ) : ?>
			<p><?php asde_e( 'Al aprobar un cambio se guarda en el CRM. Si el dato del CRM ha cambiado desde que se propuso, se avisa antes de sobrescribirlo. Nadie puede aprobar sus propias propuestas.' ); ?></p>
			<?php if ( ! $escritura ) : ?>
				<div class="asde-notice asde-notice--warning" role="status"><?php asde_e( 'El usuario del CRM del grupo no puede editar fichas ahora mismo: los cambios no se pueden aprobar hasta que lo tenga.' ); ?></div>
			<?php endif; ?>
		<?php else : ?>
			<?php /* translators: %d: días */ ?>
			<p><?php echo esc_html( sprintf( asde_t( 'Las propuestas ya revisadas y los cambios hechos directamente en las fichas por Secretaría, Coordinación o los scouters (de estos, los de los últimos %d días).' ), $dias ) ); ?></p>
		<?php endif; ?>

		<?php if ( ! $grupos ) : ?>
			<p><?php echo 'pendientes' === $ver ? esc_html( asde_t( 'No hay cambios pendientes de revisar. 🎉' ) ) : esc_html( asde_t( 'Todavía no hay cambios resueltos.' ) ); ?></p>
		<?php else : ?>
			<table class="asde-table">
				<thead>
					<tr>
						<th scope="col"><?php asde_e( 'Persona' ); ?></th>
						<th scope="col"><?php asde_e( 'Cambios' ); ?></th>
						<th scope="col"><?php echo 'pendientes' === $ver ? esc_html( asde_t( 'Último propuesto' ) ) : esc_html( asde_t( 'Último' ) ); ?></th>
						<th scope="col"><span class="screen-reader-text"><?php asde_e( 'Acciones' ); ?></span></th>
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
							<td data-label="<?php echo esc_attr( asde_a( 'Persona' ) ); ?>"><a href="<?php echo esc_url( add_query_arg( 'socio', (string) $num, $url_ficha ) ); ?>" data-asde-modal><?php echo esc_html( $fila['persona']->nombre_completo() ); ?></a><?php echo Numeros::compacto( $fila['persona'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
							<td data-label="<?php echo esc_attr( asde_a( 'Cambios' ) ); ?>"><strong><?php echo (int) $n; ?></strong> · <span class="asde-muted"><?php echo esc_html( implode( ', ', array_map( array( Cambios::class, 'etiqueta' ), $datos ) ) ); ?></span></td>
							<td data-label="<?php echo esc_attr( asde_a( 'Último' ) ); ?>"><?php echo esc_html( $fecha( $fila['ultimo'] ) ); ?></td>
							<td data-label="">
								<?php if ( 'pendientes' === $ver ) : ?>
									<?php /* translators: %d: número de cambios */ ?>
									<a class="button" href="<?php echo esc_url( $enlace ); ?>" data-asde-modal><?php echo esc_html( sprintf( asde_t( 'Revisar (%d)' ), $n ) ); ?></a>
								<?php else : ?>
									<a href="<?php echo esc_url( $enlace ); ?>" data-asde-modal><?php asde_e( 'Ver' ); ?></a>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</section>
<?php endif; ?>
