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
		$confirmar ? ' onsubmit="return confirm(<?php echo asde_js( ' . esc_attr( (string) wp_json_encode( $confirmar ) ) . ' ); ?>);"' : '',
		wp_nonce_field( 'asde_numero_' . $accion . '_' . $socio, '_wpnonce', true, false ),
		esc_attr( $accion ),
		esc_attr( $socio ),
		esc_html( $texto )
	);
};

$columnas = array(
	'nombre'    => asde_t( 'Nombre' ),
	'numero'    => Identidad::es_asde() ? asde_t( 'Nº territorial' ) : asde_t( 'Nº socio/a (usuario)' ),
	'asde'      => Identidad::es_asde() ? asde_t( 'Nº Scouts de España (usuario)' ) : asde_t( 'Nº Scouts de España' ),
	'membresia' => asde_t( 'Membresía' ),
	'acceso'    => asde_t( 'Acceso web' ),
);
?>
<section class="asde-card">
	<h2><?php asde_e( 'Personas del grupo' ); ?></h2>
	<p><?php asde_e( 'Única página del área privada donde aparecen también las personas' ); ?> <strong><?php asde_e( 'sin membresía activa' ); ?></strong><?php asde_e( '. En el resto (alta, informes…) solo se ven los socios activos.' ); ?></p>
	<?php if ( null !== $edad_datos ) : ?>
		<p class="asde-help">
			<?php asde_e( 'Las fichas se leen del CRM cada pocos minutos para que las páginas vayan rápido (la última lectura fue' ); ?> <?php echo $edad_datos < 60 ? esc_html( asde_t( 'hace menos de un minuto' ) ) : esc_html( 'hace ' . (int) floor( $edad_datos / 60 ) . ' min' ); ?><?php asde_e( '). Si acabas de cambiar algo directamente en el CRM:' ); ?> <a href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'actualizar', '1', $url ), 'asde_actualizar_personas' ) ); ?>"><?php asde_e( 'Actualizar ahora' ); ?></a>.
		</p>
	<?php endif; ?>
	<dl class="asde-datos">
		<div><dt><?php asde_e( 'Con membresía activa' ); ?></dt><dd><strong><?php echo (int) $resumen['activas']; ?></strong></dd></div>
		<div><dt><?php asde_e( 'Sin membresía activa' ); ?></dt><dd><?php echo (int) $resumen['inactivas']; ?></dd></div>
		<div><dt><?php asde_e( 'Siguiente nº de socio/a libre' ); ?></dt><dd><strong><?php echo esc_html( $siguiente ); ?></strong></dd></div>
		<div><dt><?php asde_e( 'Activos con incidencias de nº' ); ?></dt><dd><?php echo (int) array_sum( $incid ); ?></dd></div>
	</dl>
	<?php if ( $incid ) : ?>
		<p class="asde-muted">
			<?php foreach ( $incid as $estado => $n ) : ?>
				<?php echo esc_html( asde_t( Numeracion::ETIQUETAS[ $estado ] ) . ': ' . $n ); ?> ·
			<?php endforeach; ?>
			<a href="<?php echo esc_url( add_query_arg( 'ver', 'incidencias', $url ) ); ?>"><?php asde_e( 'ver' ); ?></a>
		</p>
		<?php if ( $en_marcha ) : ?>
			<p class="asde-help"><?php asde_e( 'Hay una corrección en marcha en segundo plano: su avance se ve en el recuadro de arriba. Mientras dura, no se pueden lanzar otras correcciones para todas las fichas.' ); ?></p>
		<?php elseif ( $escritura && ( ! empty( $incid[ Numeracion::CORREGIDO ] ) || ! empty( $incid[ Numeracion::DUPLICADO ] ) || $generados ) ) : ?>
			<form method="post" class="asde-actions asde-numeracion-todas">
				<?php wp_nonce_field( 'asde_numeracion_todas' ); ?>
				<?php if ( ! empty( $incid[ Numeracion::CORREGIDO ] ) ) : ?>
					<button type="submit" name="asde_action" value="corregir_todos" class="button" onclick="return confirm(<?php echo esc_attr( asde_js( 'Se guardará en el CRM el formato correcto (7 + grupo + 4 cifras) de todos los números con formato antiguo (también los de fichas sin membresía activa). Se hace en segundo plano: podrás seguir usando la web. ¿Continuar?' ) ); ?>);"><?php asde_e( 'Corregir en el CRM los formatos antiguos' ); ?></button>
				<?php endif; ?>
				<?php if ( ! empty( $incid[ Numeracion::DUPLICADO ] ) ) : ?>
					<button type="submit" name="asde_action" value="resolver_duplicados" class="button" onclick="return confirm(<?php echo esc_attr( asde_js( 'En cada número repetido lo conservará quien entra en la web con él (o quien tiene membresía activa, o el nº de Scouts de España más antiguo); el resto recibirá el siguiente número libre, guardado en el CRM. Se hace en segundo plano. ¿Continuar?' ) ); ?>);"><?php asde_e( 'Resolver los duplicados' ); ?></button>
				<?php endif; ?>
				<?php if ( $generados ) : ?>
					<button type="submit" name="asde_action" value="pasar_todos" class="button" onclick="return confirm(<?php echo esc_attr( asde_js( 'Se guardarán en el CRM los números generados desde la web. Se hace en segundo plano. ¿Continuar?' ) ); ?>);"><?php asde_e( 'Pasar al CRM los números generados' ); ?></button>
				<?php endif; ?>
			</form>
		<?php elseif ( ! $escritura ) : ?>
			<p class="asde-help"><?php asde_e( 'Para corregirlos desde aquí, el usuario del CRM del grupo necesita permiso para editar fichas. Mientras tanto, corrígelos en el CRM (campo «Nº de socio/a local/territorial»).' ); ?></p>
		<?php endif; ?>
	<?php endif; ?>
	<?php if ( $fallidas ) : ?>
		<details class="asde-tarea-fallidas">
			<summary><?php echo esc_html( 1 === count( $fallidas ) ? asde_t( 'La última corrección no pudo corregir 1 ficha' ) : sprintf( asde_t( 'La última corrección no pudo corregir %d fichas' ), count( $fallidas ) ) ); ?></summary>
			<ul>
				<?php foreach ( array_slice( $fallidas, 0, 100 ) as $f ) : ?>
					<li>
						<a href="<?php echo esc_url( add_query_arg( 'socio', $f['asde'], $url_ficha ) ); ?>"><?php echo esc_html( '' !== $f['nombre'] ? $f['nombre'] : asde_t( 'Nº Scouts de España ' ) . $f['asde'] ); ?></a>:
						<?php echo esc_html( $f['motivo'] ); ?>
					</li>
				<?php endforeach; ?>
			</ul>
			<p class="asde-help"><?php asde_e( 'Se pueden corregir una a una desde la lista, o volver a lanzar la corrección cuando se haya resuelto el motivo.' ); ?></p>
		</details>
	<?php endif; ?>
</section>

<?php if ( $generados ) : ?>
	<section class="asde-card">
		<h3><?php asde_e( 'Números generados pendientes de pasar al CRM' ); ?></h3>
		<p><?php echo $escritura ? esc_html( asde_t( 'Estos números se asignaron desde la web y aún no están en el CRM. Pásalos con el botón de arriba o desde cada ficha.' ) ) : esc_html( asde_t( 'Estos números se han asignado desde la web. Anótalos en el CRM (campo «Nº de socio/a local/territorial»); al detectarlos en el CRM desaparecerán de esta lista.' ) ); ?></p>
		<ul>
			<?php foreach ( $generados as $asde => $local ) : ?>
				<li><strong><?php echo esc_html( $local ); ?></strong> <?php asde_e( '→ ficha con Nº Scouts de España' ); ?> <?php echo esc_html( (string) $asde ); ?></li>
			<?php endforeach; ?>
		</ul>
	</section>
<?php endif; ?>

<section class="asde-card">
	<form method="get" action="<?php echo esc_url( $url ); ?>" class="asde-filter">
		<label for="asde-ver"><?php asde_e( 'Mostrar' ); ?></label>
		<select id="asde-ver" name="ver" onchange="this.form.submit()">
			<?php foreach ( asde_ta( PersonasView::FILTROS ) as $k => $label ) : ?>
				<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $filtro, $k ); ?>><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select>
		<label for="asde-q-personas" class="screen-reader-text"><?php asde_e( 'Buscar' ); ?></label>
		<input type="search" id="asde-q-personas" name="q" value="<?php echo esc_attr( $q ); ?>" placeholder="<?php echo esc_attr( asde_a( 'Nombre o nº de socio' ) ); ?>">
		<?php // En el móvil no se ven los encabezados de la tabla: se ordena desde aquí. ?>
		<span class="asde-solo-movil">
			<label for="asde-orden-personas"><?php asde_e( 'Ordenar por' ); ?></label>
			<select id="asde-orden-personas" name="orden">
				<?php foreach ( $columnas as $col => $etiqueta ) : ?>
					<option value="<?php echo esc_attr( $col ); ?>" <?php selected( $tabla->orden(), $col ); ?>><?php echo esc_html( $etiqueta ); ?></option>
				<?php endforeach; ?>
			</select>
			<select name="dir" aria-label="<?php echo esc_attr( asde_a( 'Sentido' ) ); ?>">
				<option value="asc" <?php selected( $tabla->dir(), 'asc' ); ?>><?php asde_e( 'Ascendente' ); ?></option>
				<option value="desc" <?php selected( $tabla->dir(), 'desc' ); ?>><?php asde_e( 'Descendente' ); ?></option>
			</select>
		</span>
		<button type="submit" class="button"><?php asde_e( 'Filtrar' ); ?></button>
	</form>

	<p class="asde-muted"><?php echo esc_html( sprintf( asde_n( '%d persona', '%d personas', $total ), $total ) ); ?></p>
	<?php if ( $lista ) : ?>
		<table class="asde-table">
			<thead>
				<tr>
					<?php foreach ( $columnas as $col => $etiqueta ) : ?>
						<th scope="col"<?php echo $tabla->aria_sort( $col ); // phpcs:ignore WordPress.Security.EscapeOutput ?>><?php echo $tabla->cabecera( $col, $etiqueta ); // phpcs:ignore WordPress.Security.EscapeOutput ?></th>
					<?php endforeach; ?>
					<th scope="col"><span class="screen-reader-text"><?php asde_e( 'Acciones' ); ?></span></th>
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
						<td data-label="<?php echo esc_attr( asde_a( 'Nombre' ) ); ?>"><a href="<?php echo esc_url( add_query_arg( 'socio', $p->num_socio_asde, $url_ficha ) ); ?>" data-asde-modal><?php echo esc_html( $p->nombre_completo() ); ?></a></td>
						<td data-label="<?php echo esc_attr( $columnas['numero'] ); ?>">
							<?php echo $n['numero'] ? '<strong>' . esc_html( $n['numero'] ) . '</strong>' : '—'; ?>
							<?php if ( Numeracion::OK !== $n['estado'] ) : ?>
								<br><span class="asde-help<?php echo in_array( $n['estado'], Numeracion::BLOQUEANTES, true ) ? ' asde-help--warning' : ''; ?>">
									<?php echo esc_html( asde_t( Numeracion::ETIQUETAS[ $n['estado'] ] ) ); ?>
									<?php echo Numeracion::CORREGIDO === $n['estado'] || Numeracion::INVALIDO === $n['estado'] ? esc_html( sprintf( asde_t( ' (en el CRM: %s)' ), $p->num_socio_local_crm ) ) : ''; ?>
								</span>
							<?php endif; ?>
						</td>
						<td data-label="<?php echo esc_attr( $columnas['asde'] ); ?>"><?php echo esc_html( $p->num_socio_asde ); ?></td>
						<td data-label="<?php echo esc_attr( asde_a( 'Membresía' ) ); ?>">
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
						<td data-label="<?php echo esc_attr( asde_a( 'Acceso web' ) ); ?>">
							<?php if ( $u ) : ?>
								<a href="<?php echo esc_url( add_query_arg( 'socio', $p->num_socio_asde, $url_alta ) ); ?>" data-asde-modal title="<?php echo esc_attr( asde_a( 'Roles, secciones y cuenta familiar' ) ); ?>"><?php echo esc_html( $u->user_login ); ?></a>
								<span class="asde-muted">· <?php echo esc_html( Usuarios::etiqueta_estado( Usuarios::estado( $u ) ) ); ?></span>
							<?php elseif ( $fam ) : ?>
								<a href="<?php echo esc_url( add_query_arg( 'socio', $p->num_socio_asde, $url_alta ) ); ?>" data-asde-modal title="<?php echo esc_attr( asde_a( 'Cuenta familiar: hermanos/as y email' ) ); ?>"><?php echo esc_html( $fam->user_login ); ?></a>
								<span class="asde-muted"><?php asde_e( '· Cuenta familiar ·' ); ?> <?php echo esc_html( Usuarios::etiqueta_estado( Usuarios::estado( $fam ) ) ); ?></span>
							<?php elseif ( $activa ) : ?>
								<a class="button asde-button-secundario" href="<?php echo esc_url( add_query_arg( 'socio', $p->num_socio_asde, $url_alta ) ); ?>" data-asde-modal><?php asde_e( 'Dar acceso' ); ?></a>
							<?php else : ?>
								<span class="asde-muted">—</span>
							<?php endif; ?>
						</td>
						<td data-label="">
							<div class="asde-actions">
								<?php
								$socio = $p->num_socio_asde;
								if ( $activa && in_array( $n['estado'], array( Numeracion::FALTA, Numeracion::INVALIDO ), true ) ) {
									echo $boton( 'asignar_numero', $socio, esc_html( asde_t( 'Asignar nº' ) ), $escritura ? sprintf( esc_html( asde_t( 'Se asignará el nº %s a %s y se guardará en el CRM. ¿Continuar?' ) ), $siguiente, $p->nombre_completo() ) : '' ); // phpcs:ignore WordPress.Security.EscapeOutput
								} elseif ( $escritura && Numeracion::GENERADO === $n['estado'] ) {
									echo $boton( 'pasar_crm', $socio, esc_html( asde_t( 'Pasar al CRM' ) ), sprintf( esc_html( asde_t( 'Se guardará el nº %s en la ficha del CRM de %s. ¿Continuar?' ) ), $n['numero'], $p->nombre_completo() ) ); // phpcs:ignore WordPress.Security.EscapeOutput
								} elseif ( $escritura && Numeracion::CORREGIDO === $n['estado'] ) {
									echo $boton( 'corregir_formato', $socio, esc_html( asde_t( 'Corregir en el CRM' ) ), sprintf( esc_html( asde_t( 'En el CRM pasará de «%s» a «%s». ¿Continuar?' ) ), $p->num_socio_local_crm, $n['numero'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput
								} elseif ( $escritura && Numeracion::DUPLICADO === $n['estado'] ) {
									echo $boton( 'resolver_duplicado', $socio, esc_html( asde_t( 'Dar nº nuevo' ) ), sprintf( esc_html( asde_t( '%s recibirá el siguiente nº libre (%s) y se guardará en el CRM. La otra ficha conserva el %s. ¿Continuar?' ) ), $p->nombre_completo(), $siguiente, $n['numero'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput
								}
								?>
								<a href="<?php echo esc_url( add_query_arg( 'socio', $socio, $url_ficha ) ); ?>" data-asde-modal><?php asde_e( 'Ver ficha' ); ?></a>
							</div>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php echo $tabla->paginacion(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
	<?php endif; ?>
</section>
