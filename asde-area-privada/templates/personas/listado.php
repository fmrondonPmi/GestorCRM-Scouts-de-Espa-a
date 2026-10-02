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
 * @var bool                                                $en_marcha Hay una corrección en segundo plano
 * @var array<int, array{asde:string, nombre:string, motivo:string}> $fallidas Fichas que esa corrección no pudo corregir
 * @var string                                              $url
 * @var string                                              $url_alta
 * @var string                                              $url_ficha
 * @var int|null                                            $edad_datos Segundos desde que se leyeron las fichas del CRM (null si no se guardan)
 */
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
		$confirmar ? ' onsubmit="return confirm(' . esc_attr( (string) wp_json_encode( $confirmar ) ) . ');"' : '',
		wp_nonce_field( 'asde_numero_' . $accion . '_' . $socio, '_wpnonce', true, false ),
		esc_attr( $accion ),
		esc_attr( $socio ),
		esc_html( $texto )
	);
};

$columnas = array(
	'nombre'    => 'Nombre',
	'numero'    => Identidad::es_asde() ? 'Nº territorial' : 'Nº socio/a (usuario)',
	'asde'      => Identidad::es_asde() ? 'Nº Scouts de España (usuario)' : 'Nº Scouts de España',
	'membresia' => 'Membresía',
	'acceso'    => 'Acceso web',
);
?>
<section class="asde-card">
	<h2>Personas del grupo</h2>
	<p>Única página del área privada donde aparecen también las personas <strong>sin membresía activa</strong>. En el resto (alta, informes…) solo se ven los socios activos.</p>
	<?php if ( null !== $edad_datos ) : ?>
		<p class="asde-help">
			Las fichas se leen del CRM cada pocos minutos para que las páginas vayan rápido
			(la última lectura fue <?php echo $edad_datos < 60 ? 'hace menos de un minuto' : esc_html( 'hace ' . (int) floor( $edad_datos / 60 ) . ' min' ); ?>).
			Si acabas de cambiar algo directamente en el CRM: <a href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'actualizar', '1', $url ), 'asde_actualizar_personas' ) ); ?>">Actualizar ahora</a>.
		</p>
	<?php endif; ?>
	<dl class="asde-datos">
		<div><dt>Con membresía activa</dt><dd><strong><?php echo (int) $resumen['activas']; ?></strong></dd></div>
		<div><dt>Sin membresía activa</dt><dd><?php echo (int) $resumen['inactivas']; ?></dd></div>
		<div><dt>Siguiente nº de socio/a libre</dt><dd><strong><?php echo esc_html( $siguiente ); ?></strong></dd></div>
		<div><dt>Activos con incidencias de nº</dt><dd><?php echo (int) array_sum( $incid ); ?></dd></div>
	</dl>
	<?php if ( $incid ) : ?>
		<p class="asde-muted">
			<?php foreach ( $incid as $estado => $n ) : ?>
				<?php echo esc_html( Numeracion::ETIQUETAS[ $estado ] . ': ' . $n ); ?> ·
			<?php endforeach; ?>
			<a href="<?php echo esc_url( add_query_arg( 'ver', 'incidencias', $url ) ); ?>">ver</a>
		</p>
		<?php if ( $en_marcha ) : ?>
			<p class="asde-help">Hay una corrección en marcha en segundo plano: su avance se ve en el recuadro de arriba. Mientras dura, no se pueden lanzar otras correcciones para todas las fichas.</p>
		<?php elseif ( $escritura && ( ! empty( $incid[ Numeracion::CORREGIDO ] ) || ! empty( $incid[ Numeracion::DUPLICADO ] ) || $generados ) ) : ?>
			<form method="post" class="asde-actions asde-numeracion-todas">
				<?php wp_nonce_field( 'asde_numeracion_todas' ); ?>
				<?php if ( ! empty( $incid[ Numeracion::CORREGIDO ] ) ) : ?>
					<button type="submit" name="asde_action" value="corregir_todos" class="button" onclick="return confirm('Se guardará en el CRM el formato correcto (7 + grupo + 4 cifras) de todos los números con formato antiguo (también los de fichas sin membresía activa). Se hace en segundo plano: podrás seguir usando la web. ¿Continuar?');">Corregir en el CRM los formatos antiguos</button>
				<?php endif; ?>
				<?php if ( ! empty( $incid[ Numeracion::DUPLICADO ] ) ) : ?>
					<button type="submit" name="asde_action" value="resolver_duplicados" class="button" onclick="return confirm('En cada número repetido lo conservará quien entra en la web con él (o quien tiene membresía activa, o el nº de Scouts de España más antiguo); el resto recibirá el siguiente número libre, guardado en el CRM. Se hace en segundo plano. ¿Continuar?');">Resolver los duplicados</button>
				<?php endif; ?>
				<?php if ( $generados ) : ?>
					<button type="submit" name="asde_action" value="pasar_todos" class="button" onclick="return confirm('Se guardarán en el CRM los números generados desde la web. Se hace en segundo plano. ¿Continuar?');">Pasar al CRM los números generados</button>
				<?php endif; ?>
			</form>
		<?php elseif ( ! $escritura ) : ?>
			<p class="asde-help">Para corregirlos desde aquí, el usuario del CRM del grupo necesita permiso para editar fichas. Mientras tanto, corrígelos en el CRM (campo «Nº de socio/a local/territorial»).</p>
		<?php endif; ?>
	<?php endif; ?>
	<?php if ( $fallidas ) : ?>
		<details class="asde-tarea-fallidas">
			<summary><?php echo esc_html( 1 === count( $fallidas ) ? 'La última corrección no pudo corregir 1 ficha' : sprintf( 'La última corrección no pudo corregir %d fichas', count( $fallidas ) ) ); ?></summary>
			<ul>
				<?php foreach ( array_slice( $fallidas, 0, 100 ) as $f ) : ?>
					<li>
						<a href="<?php echo esc_url( add_query_arg( 'socio', $f['asde'], $url_ficha ) ); ?>"><?php echo esc_html( '' !== $f['nombre'] ? $f['nombre'] : 'Nº Scouts de España ' . $f['asde'] ); ?></a>:
						<?php echo esc_html( $f['motivo'] ); ?>
					</li>
				<?php endforeach; ?>
			</ul>
			<p class="asde-help">Se pueden corregir una a una desde la lista, o volver a lanzar la corrección cuando se haya resuelto el motivo.</p>
		</details>
	<?php endif; ?>
</section>

<?php if ( $generados ) : ?>
	<section class="asde-card">
		<h3>Números generados pendientes de pasar al CRM</h3>
		<p><?php echo $escritura ? 'Estos números se asignaron desde la web y aún no están en el CRM. Pásalos con el botón de arriba o desde cada ficha.' : 'Estos números se han asignado desde la web. Anótalos en el CRM (campo «Nº de socio/a local/territorial»); al detectarlos en el CRM desaparecerán de esta lista.'; ?></p>
		<ul>
			<?php foreach ( $generados as $asde => $local ) : ?>
				<li><strong><?php echo esc_html( $local ); ?></strong> → ficha con Nº Scouts de España <?php echo esc_html( (string) $asde ); ?></li>
			<?php endforeach; ?>
		</ul>
	</section>
<?php endif; ?>

<section class="asde-card">
	<form method="get" action="<?php echo esc_url( $url ); ?>" class="asde-filter">
		<label for="asde-ver">Mostrar</label>
		<select id="asde-ver" name="ver" onchange="this.form.submit()">
			<?php foreach ( PersonasView::FILTROS as $k => $label ) : ?>
				<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $filtro, $k ); ?>><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select>
		<label for="asde-q-personas" class="screen-reader-text">Buscar</label>
		<input type="search" id="asde-q-personas" name="q" value="<?php echo esc_attr( $q ); ?>" placeholder="Nombre o nº de socio">
		<?php // En el móvil no se ven los encabezados de la tabla: se ordena desde aquí. ?>
		<span class="asde-solo-movil">
			<label for="asde-orden-personas">Ordenar por</label>
			<select id="asde-orden-personas" name="orden">
				<?php foreach ( $columnas as $col => $etiqueta ) : ?>
					<option value="<?php echo esc_attr( $col ); ?>" <?php selected( $tabla->orden(), $col ); ?>><?php echo esc_html( $etiqueta ); ?></option>
				<?php endforeach; ?>
			</select>
			<select name="dir" aria-label="Sentido">
				<option value="asc" <?php selected( $tabla->dir(), 'asc' ); ?>>Ascendente</option>
				<option value="desc" <?php selected( $tabla->dir(), 'desc' ); ?>>Descendente</option>
			</select>
		</span>
		<button type="submit" class="button">Filtrar</button>
	</form>

	<p class="asde-muted"><?php echo esc_html( sprintf( _n( '%d persona', '%d personas', $total, 'asde-area-privada' ), $total ) ); ?></p>
	<?php if ( $lista ) : ?>
		<table class="asde-table">
			<thead>
				<tr>
					<?php foreach ( $columnas as $col => $etiqueta ) : ?>
						<th scope="col"<?php echo $tabla->aria_sort( $col ); // phpcs:ignore WordPress.Security.EscapeOutput ?>><?php echo $tabla->cabecera( $col, $etiqueta ); // phpcs:ignore WordPress.Security.EscapeOutput ?></th>
					<?php endforeach; ?>
					<th scope="col"><span class="screen-reader-text">Acciones</span></th>
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
						<td data-label="Nombre"><a href="<?php echo esc_url( add_query_arg( 'socio', $p->num_socio_asde, $url_ficha ) ); ?>" data-asde-modal><?php echo esc_html( $p->nombre_completo() ); ?></a></td>
						<td data-label="<?php echo esc_attr( $columnas['numero'] ); ?>">
							<?php echo $n['numero'] ? '<strong>' . esc_html( $n['numero'] ) . '</strong>' : '—'; ?>
							<?php if ( Numeracion::OK !== $n['estado'] ) : ?>
								<br><span class="asde-help<?php echo in_array( $n['estado'], Numeracion::BLOQUEANTES, true ) ? ' asde-help--warning' : ''; ?>">
									<?php echo esc_html( Numeracion::ETIQUETAS[ $n['estado'] ] ); ?>
									<?php echo Numeracion::CORREGIDO === $n['estado'] || Numeracion::INVALIDO === $n['estado'] ? esc_html( ' (en el CRM: ' . $p->num_socio_local_crm . ')' ) : ''; ?>
								</span>
							<?php endif; ?>
						</td>
						<td data-label="<?php echo esc_attr( $columnas['asde'] ); ?>"><?php echo esc_html( $p->num_socio_asde ); ?></td>
						<td data-label="Membresía">
							<?php
							if ( $activa ) {
								foreach ( $p->secciones as $s ) {
									echo Secciones::badge( $s ); // phpcs:ignore WordPress.Security.EscapeOutput
								}
							} else {
								echo '<span class="asde-badge asde-badge--estado-desactivado">Sin membresía activa</span>';
							}
							?>
						</td>
						<td data-label="Acceso web">
							<?php if ( $u ) : ?>
								<a href="<?php echo esc_url( add_query_arg( 'socio', $p->num_socio_asde, $url_alta ) ); ?>" data-asde-modal title="Roles, secciones y cuenta familiar"><?php echo esc_html( $u->user_login ); ?></a>
								<span class="asde-muted">· <?php echo esc_html( Usuarios::etiqueta_estado( Usuarios::estado( $u ) ) ); ?></span>
							<?php elseif ( $fam ) : ?>
								<a href="<?php echo esc_url( add_query_arg( 'socio', $p->num_socio_asde, $url_alta ) ); ?>" data-asde-modal title="Cuenta familiar: hermanos/as y email"><?php echo esc_html( $fam->user_login ); ?></a>
								<span class="asde-muted">· Cuenta familiar · <?php echo esc_html( Usuarios::etiqueta_estado( Usuarios::estado( $fam ) ) ); ?></span>
							<?php elseif ( $activa ) : ?>
								<a class="button asde-button-secundario" href="<?php echo esc_url( add_query_arg( 'socio', $p->num_socio_asde, $url_alta ) ); ?>" data-asde-modal>Dar acceso</a>
							<?php else : ?>
								<span class="asde-muted">—</span>
							<?php endif; ?>
						</td>
						<td data-label="">
							<div class="asde-actions">
								<?php
								$socio = $p->num_socio_asde;
								if ( $activa && in_array( $n['estado'], array( Numeracion::FALTA, Numeracion::INVALIDO ), true ) ) {
									echo $boton( 'asignar_numero', $socio, 'Asignar nº', $escritura ? sprintf( 'Se asignará el nº %s a %s y se guardará en el CRM. ¿Continuar?', $siguiente, $p->nombre_completo() ) : '' ); // phpcs:ignore WordPress.Security.EscapeOutput
								} elseif ( $escritura && Numeracion::GENERADO === $n['estado'] ) {
									echo $boton( 'pasar_crm', $socio, 'Pasar al CRM', sprintf( 'Se guardará el nº %s en la ficha del CRM de %s. ¿Continuar?', $n['numero'], $p->nombre_completo() ) ); // phpcs:ignore WordPress.Security.EscapeOutput
								} elseif ( $escritura && Numeracion::CORREGIDO === $n['estado'] ) {
									echo $boton( 'corregir_formato', $socio, 'Corregir en el CRM', sprintf( 'En el CRM pasará de «%s» a «%s». ¿Continuar?', $p->num_socio_local_crm, $n['numero'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput
								} elseif ( $escritura && Numeracion::DUPLICADO === $n['estado'] ) {
									echo $boton( 'resolver_duplicado', $socio, 'Dar nº nuevo', sprintf( '%s recibirá el siguiente nº libre (%s) y se guardará en el CRM. La otra ficha conserva el %s. ¿Continuar?', $p->nombre_completo(), $siguiente, $n['numero'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput
								}
								?>
								<a href="<?php echo esc_url( add_query_arg( 'socio', $socio, $url_ficha ) ); ?>" data-asde-modal>Ver ficha</a>
							</div>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php echo $tabla->paginacion(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
	<?php endif; ?>
</section>
