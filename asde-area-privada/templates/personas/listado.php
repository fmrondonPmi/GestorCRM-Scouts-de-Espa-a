<?php
/**
 * Personas del grupo (activas e inactivas) y revisión de números de socio.
 *
 * @var AsdeBalears\AreaPrivada\Domain\Persona[]            $lista    Solo la página que se muestra
 * @var int                                                 $total    Personas que cumplen el filtro
 * @var AsdeBalears\AreaPrivada\Frontend\Tabla              $tabla    Orden y paginación
 * @var array<string, array{numero:?string, estado:string}> $analisis nº Scouts de España => nº local
 * @var array{activas:int, inactivas:int}                   $resumen
 * @var array<string, int>                                  $incid    estado => nº de socios activos
 * @var string                                              $filtro
 * @var string                                              $q
 * @var string                                              $siguiente
 * @var array<string, string>                               $generados nº Scouts de España => nº local
 * @var array<string, WP_User>                              $usuarios
 * @var array<string, WP_User>                              $familiares nº Scouts de España => cuenta familiar que lo representa
 * @var bool                                                $escritura El usuario del CRM puede editar fichas
 * @var bool                                                $altas     Altas en el CRM activadas en el grupo (doc 34, fase 3)
 * @var bool                                                $en_marcha Hay una corrección en segundo plano
 * @var array<int, array{asde:string, nombre:string, motivo:string}> $fallidas Fichas que esa corrección no pudo corregir
 * @var string                                              $url
 * @var string                                              $url_alta
 * @var string                                              $url_ficha
 * @var int|null                                            $edad_datos Segundos desde que se leyeron las fichas del CRM (null si no se guardan)
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Domain\Identidad;
use AsdeBalears\AreaPrivada\Domain\Secciones;
use AsdeBalears\AreaPrivada\Frontend\Views\PersonasView;
use AsdeBalears\AreaPrivada\Socios\Numeracion;
use AsdeBalears\AreaPrivada\Usuarios\Usuarios;

defined( 'ABSPATH' ) || exit;

/** Botón de una acción sobre el número de una ficha (formulario propio con nonce y confirmación). */
$boton = static function ( string $accion, string $socio, string $texto, string $confirmar = '' ): string {
	return sprintf(
		'<form method="post"%s>%s<input type="hidden" name="asde_action" value="%s"><input type="hidden" name="socio" value="%s"><button type="submit" class="asde-btn-link">%s</button></form>',
		$confirmar ? ' data-asde-confirmar="' . esc_attr( $confirmar ) . '"' : '',
		wp_nonce_field( 'asde_numero_' . $accion . '_' . $socio, '_wpnonce', true, false ),
		esc_attr( $accion ),
		esc_attr( $socio ),
		esc_html( $texto )
	);
};

$columnas = array(
	'nombre'    => __( 'Name', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
	'numero'    => Identidad::es_asde() ? __( 'Territorial no.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) : __( 'Member no. (username)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
	'asde'      => Identidad::es_asde() ? __( 'Scouts de España no. (username)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) : __( 'Scouts de España no.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
	'membresia' => __( 'Membership', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
	'acceso'    => __( 'Web access', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
);
?>
<?php /* Plegable (v0.29, area.js): contraído quedan el título, «Alta de socio/a» y las dos cifras. */ ?>
<section class="asde-card" data-asde-plegable="personas-resumen">
	<header class="asde-card__header asde-card__header--split">
		<h2><?php esc_html_e( 'People in the group', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h2>
		<?php /* translators: 1: people with an active membership, 2: without an active membership */ ?>
		<span class="asde-card__si-plegada asde-muted"><?php echo esc_html( sprintf( __( '%1$d with an active membership · %2$d without an active membership', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), (int) $resumen['activas'], (int) $resumen['inactivas'] ) ); ?></span>
		<?php if ( $altas ) : ?>
			<?php /* Doc 34, fase 3: persona nueva con su primera membresía; el primer paso comprueba si ya tiene ficha. */ ?>
			<a class="button" href="<?php echo esc_url( add_query_arg( 'alta', '1', $url ) ); ?>" data-asde-modal><?php esc_html_e( 'Member registration', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a>
		<?php endif; ?>
	</header>
	<p><?php esc_html_e( 'The only page of the private area where people also appear', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <strong><?php esc_html_e( 'no active membership', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></strong><?php esc_html_e( '. In the rest (registration, reports…) only active members are shown.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
	<?php if ( null !== $edad_datos ) : ?>
		<p class="asde-help">
			<?php esc_html_e( 'Records are read from the CRM every few minutes so that pages go fast (the last read was', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <?php echo $edad_datos < 60 ? esc_html( __( 'less than a minute ago', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) : esc_html( 'hace ' . (int) floor( $edad_datos / 60 ) . ' min' ); ?><?php esc_html_e( '). If you have just changed something directly in the CRM:', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <a href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'actualizar', '1', $url ), 'asde_actualizar_personas' ) ); ?>"><?php esc_html_e( 'Update now', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a>.
		</p>
	<?php endif; ?>
	<?php /* Zona (parcial.js): al cerrar la ventana tras un alta, las cifras se actualizan con la lista. */ ?>
	<dl class="asde-datos" data-asde-parcial="personas-cifras">
		<div><dt><?php esc_html_e( 'With an active membership', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></dt><dd><strong><?php echo (int) $resumen['activas']; ?></strong></dd></div>
		<div><dt><?php esc_html_e( 'No active membership', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></dt><dd><?php echo (int) $resumen['inactivas']; ?></dd></div>
		<div><dt><?php esc_html_e( 'Next free member no.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></dt><dd><strong><?php echo esc_html( $siguiente ); ?></strong></dd></div>
		<div><dt><?php esc_html_e( 'Active with number issues', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></dt><dd><?php echo (int) array_sum( $incid ); ?></dd></div>
	</dl>
	<?php if ( $incid ) : ?>
		<p class="asde-muted">
			<?php foreach ( $incid as $estado => $n ) : ?>
				<?php echo esc_html( ( Numeracion::etiquetas()[ $estado ] ) . ': ' . $n ); ?> ·
			<?php endforeach; ?>
			<a href="<?php echo esc_url( add_query_arg( 'ver', 'incidencias', $url ) ); ?>"><?php esc_html_e( 'view', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a>
		</p>
		<?php if ( $en_marcha ) : ?>
			<p class="asde-help"><?php esc_html_e( 'A correction is running in the background: its progress is shown in the box above. While it lasts, other corrections for all records cannot be launched.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
		<?php elseif ( $escritura && ( ! empty( $incid[ Numeracion::CORREGIDO ] ) || ! empty( $incid[ Numeracion::DUPLICADO ] ) || $generados ) ) : ?>
			<form method="post" class="asde-actions asde-numeracion-todas">
				<?php wp_nonce_field( 'asde_numeracion_todas' ); ?>
				<?php if ( ! empty( $incid[ Numeracion::CORREGIDO ] ) ) : ?>
					<button type="submit" name="asde_action" value="corregir_todos" class="button" data-asde-confirmar="<?php echo esc_attr( __( 'The correct format (7 + group + 4 digits) will be saved in the CRM for all old-format numbers (including those of records without an active membership). It is done in the background: you can keep using the website. Continue?', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php esc_html_e( 'Correct the old formats in the CRM', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
				<?php endif; ?>
				<?php if ( ! empty( $incid[ Numeracion::DUPLICADO ] ) ) : ?>
					<button type="submit" name="asde_action" value="resolver_duplicados" class="button" data-asde-confirmar="<?php echo esc_attr( __( 'For each repeated number, it will be kept by whoever signs in to the website with it (or whoever has an active membership, or the oldest Scouts de España no.); the rest will receive the next free number, saved in the CRM. It is done in the background. Continue?', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php esc_html_e( 'Resolve the duplicates', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
				<?php endif; ?>
				<?php if ( $generados ) : ?>
					<button type="submit" name="asde_action" value="pasar_todos" class="button" data-asde-confirmar="<?php echo esc_attr( __( 'The numbers generated from the website will be saved in the CRM. It is done in the background. Continue?', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php esc_html_e( 'Transfer the generated numbers to the CRM', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
				<?php endif; ?>
			</form>
		<?php elseif ( ! $escritura ) : ?>
			<p class="asde-help"><?php esc_html_e( 'To correct them from here, the group\'s CRM user needs permission to edit records. In the meantime, correct them in the CRM ("Local/territorial member no." field).', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
		<?php endif; ?>
	<?php endif; ?>
	<?php if ( $fallidas ) : ?>
		<details class="asde-tarea-fallidas">
			<summary><?php echo esc_html( 1 === count( $fallidas ) ? __( 'The last correction could not correct 1 record', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) : sprintf( /* translators: %d: number */ __( 'The last correction could not correct %d records', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), count( $fallidas ) ) ); ?></summary>
			<ul>
				<?php foreach ( array_slice( $fallidas, 0, 100 ) as $f ) : ?>
					<li>
						<a href="<?php echo esc_url( add_query_arg( 'socio', $f['asde'], $url_ficha ) ); ?>"><?php echo esc_html( '' !== $f['nombre'] ? $f['nombre'] : __( 'Scouts de España no. ', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) . $f['asde'] ); ?></a>:
						<?php echo esc_html( $f['motivo'] ); ?>
					</li>
				<?php endforeach; ?>
			</ul>
			<p class="asde-help"><?php esc_html_e( 'They can be corrected one by one from the list, or the correction can be launched again once the cause has been resolved.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
		</details>
	<?php endif; ?>
</section>

<?php if ( $generados ) : ?>
	<section class="asde-card">
		<h3><?php esc_html_e( 'Generated numbers pending transfer to the CRM', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h3>
		<p><?php echo $escritura ? esc_html( __( 'These numbers were assigned from the website and are not yet in the CRM. Transfer them with the button above or from each record.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) : esc_html( __( 'These numbers have been assigned from the website. Note them in the CRM ("Local/territorial member no." field); when they are detected in the CRM they will disappear from this list.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?></p>
		<ul>
			<?php foreach ( $generados as $asde => $local ) : ?>
				<li><strong><?php echo esc_html( $local ); ?></strong> <?php esc_html_e( '→ record with Scouts de España no.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <?php echo esc_html( (string) $asde ); ?></li>
			<?php endforeach; ?>
		</ul>
	</section>
<?php endif; ?>

<section class="asde-card">
	<?php /* v0.29: el filtro actualiza solo la lista (parcial.js), y busca al escribir desde la 3.ª letra. */ ?>
	<form method="get" action="<?php echo esc_url( $url ); ?>" class="asde-filter asde-filter--una-linea" data-asde-parcial-form="personas-lista">
		<label for="asde-ver"><?php esc_html_e( 'Show', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
		<select id="asde-ver" name="ver" data-asde-autoenviar>
			<?php foreach ( ( PersonasView::filtros() ) as $k => $label ) : ?>
				<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $filtro, $k ); ?>><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select>
		<span class="asde-filter__buscar">
			<label for="asde-q-personas" class="screen-reader-text"><?php esc_html_e( 'Search', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
			<input type="search" id="asde-q-personas" name="q" value="<?php echo esc_attr( $q ); ?>" placeholder="<?php echo esc_attr( esc_attr__( 'Name or member no.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>" data-asde-buscar autocomplete="off">
			<button type="submit" class="button"><?php esc_html_e( 'Filter', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
		</span>
		<?php // En el móvil no se ven los encabezados de la tabla: se ordena desde aquí. ?>
		<span class="asde-solo-movil">
			<label for="asde-orden-personas"><?php esc_html_e( 'Sort by', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
			<select id="asde-orden-personas" name="orden">
				<?php foreach ( $columnas as $col => $etiqueta ) : ?>
					<option value="<?php echo esc_attr( $col ); ?>" <?php selected( $tabla->orden(), $col ); ?>><?php echo esc_html( $etiqueta ); ?></option>
				<?php endforeach; ?>
			</select>
			<select name="dir" aria-label="<?php echo esc_attr( esc_attr__( 'Direction', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
				<option value="asc" <?php selected( $tabla->dir(), 'asc' ); ?>><?php esc_html_e( 'Ascending', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></option>
				<option value="desc" <?php selected( $tabla->dir(), 'desc' ); ?>><?php esc_html_e( 'Descending', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></option>
			</select>
		</span>
	</form>

	<div data-asde-parcial="personas-lista">
	<?php if ( $lista ) : ?>
		<table class="asde-table">
			<thead>
				<tr>
					<?php foreach ( $columnas as $col => $etiqueta ) : ?>
						<th scope="col"<?php echo wp_kses( $tabla->aria_sort( $col ), asde_html_permitido() ); ?>><?php echo wp_kses( $tabla->cabecera( $col, $etiqueta ), asde_html_permitido() ); ?></th>
					<?php endforeach; ?>
					<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $lista as $p ) : ?>
					<?php
					$n      = $analisis[ $p->num_socio_asde ] ?? array( 'numero' => null, 'estado' => Numeracion::FALTA );
					$activa = $p->tiene_membresia_activa();
					$u      = $usuarios[ $p->num_socio_asde ] ?? null;
					$fam    = $u ? null : ( $familiares[ $p->num_socio_asde ] ?? null );
					?>
					<tr>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Name', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><a href="<?php echo esc_url( add_query_arg( 'socio', $p->num_socio_asde, $url_ficha ) ); ?>" data-asde-modal><?php echo esc_html( $p->nombre_completo() ); ?></a></td>
						<td data-label="<?php echo esc_attr( $columnas['numero'] ); ?>">
							<?php echo $n['numero'] ? '<strong>' . esc_html( $n['numero'] ) . '</strong>' : '—'; ?>
							<?php if ( Numeracion::OK !== $n['estado'] ) : ?>
								<br><span class="asde-help<?php echo in_array( $n['estado'], Numeracion::BLOQUEANTES, true ) ? ' asde-help--warning' : ''; ?>">
									<?php echo esc_html( ( Numeracion::etiquetas()[ $n['estado'] ] ) ); ?>
									<?php echo Numeracion::CORREGIDO === $n['estado'] || Numeracion::INVALIDO === $n['estado'] ? esc_html( sprintf( /* translators: %s: text */ __( ' (in the CRM: %s)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $p->num_socio_local_crm ) ) : ''; ?>
								</span>
							<?php endif; ?>
						</td>
						<td data-label="<?php echo esc_attr( $columnas['asde'] ); ?>"><?php echo esc_html( $p->num_socio_asde ); ?></td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Membership', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
							<?php
							if ( $activa ) {
								foreach ( $p->secciones as $s ) {
									echo wp_kses( Secciones::badge( $s ), asde_html_permitido() );
								}
							} else {
								echo '<span class="asde-badge asde-badge--estado-desactivado">Sin membresía activa</span>';
							}
							?>
						</td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Web access', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
							<?php if ( $u ) : ?>
								<a href="<?php echo esc_url( add_query_arg( 'socio', $p->num_socio_asde, $url_alta ) ); ?>" data-asde-modal title="<?php echo esc_attr( esc_attr__( 'Roles, sections and family account', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo esc_html( $u->user_login ); ?></a>
								<span class="asde-muted">· <?php echo esc_html( Usuarios::etiqueta_estado( Usuarios::estado( $u ) ) ); ?></span>
							<?php elseif ( $fam ) : ?>
								<a href="<?php echo esc_url( add_query_arg( 'socio', $p->num_socio_asde, $url_alta ) ); ?>" data-asde-modal title="<?php echo esc_attr( esc_attr__( 'Family account: siblings and email', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo esc_html( $fam->user_login ); ?></a>
								<span class="asde-muted"><?php esc_html_e( '· Family account ·', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <?php echo esc_html( Usuarios::etiqueta_estado( Usuarios::estado( $fam ) ) ); ?></span>
							<?php elseif ( $activa ) : ?>
								<a href="<?php echo esc_url( add_query_arg( 'socio', $p->num_socio_asde, $url_alta ) ); ?>" data-asde-modal><?php esc_html_e( 'Give access', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a>
							<?php else : ?>
								<span class="asde-muted">—</span>
							<?php endif; ?>
						</td>
						<td data-label="">
							<div class="asde-actions">
								<?php
								$socio = $p->num_socio_asde;
								if ( $activa && in_array( $n['estado'], array( Numeracion::FALTA, Numeracion::INVALIDO ), true ) ) {
									echo wp_kses( $boton( 'asignar_numero', $socio, esc_html( __( 'Assign no.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ), $escritura ? sprintf( esc_html( /* translators: 1: text, 2: text */ __( 'No. %1$s will be assigned to %2$s and saved in the CRM. Continue?', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ), $siguiente, $p->nombre_completo() ) : '' ), asde_html_permitido() );
								} elseif ( $escritura && Numeracion::GENERADO === $n['estado'] ) {
									echo wp_kses( $boton( 'pasar_crm', $socio, esc_html( __( 'Transfer to the CRM', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ), sprintf( esc_html( /* translators: 1: text, 2: text */ __( 'No. %1$s will be saved on %2$s\'s CRM record. Continue?', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ), $n['numero'], $p->nombre_completo() ) ), asde_html_permitido() );
								} elseif ( $escritura && Numeracion::CORREGIDO === $n['estado'] ) {
									echo wp_kses( $boton( 'corregir_formato', $socio, esc_html( __( 'Correct in the CRM', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ), sprintf( esc_html( /* translators: 1: text, 2: text */ __( 'In the CRM it will change from "%1$s" to "%2$s". Continue?', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ), $p->num_socio_local_crm, $n['numero'] ) ), asde_html_permitido() );
								} elseif ( $escritura && Numeracion::DUPLICADO === $n['estado'] ) {
									echo wp_kses( $boton( 'resolver_duplicado', $socio, esc_html( __( 'Give a new no.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ), sprintf( esc_html( /* translators: 1: text, 2: text, 3: text */ __( '%1$s will receive the next free no. (%2$s) and it will be saved in the CRM. The other record keeps %3$s. Continue?', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ), $p->nombre_completo(), $siguiente, $n['numero'] ) ), asde_html_permitido() );
								}
								?>
								<a href="<?php echo esc_url( add_query_arg( 'socio', $socio, $url_ficha ) ); ?>" data-asde-modal><?php esc_html_e( 'View record', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a>
							</div>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
	<?php /* v0.31: el contador, debajo de la lista (antes iba justo después de «Filtrar»). */ ?>
	<p class="asde-muted asde-contador"><?php echo esc_html( sprintf( /* translators: %d: number */ _n( '%d person', '%d people', $total , 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $total ) ); ?></p>
	<?php echo wp_kses( $lista ? $tabla->paginacion() : '', asde_html_permitido() ); ?>
	</div>
</section>
