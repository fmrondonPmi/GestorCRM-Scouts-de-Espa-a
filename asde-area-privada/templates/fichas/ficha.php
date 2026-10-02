<?php
/**
 * Ficha de una persona: datos del CRM, editables o de solo lectura.
 *
 * @var AsdeBalears\AreaPrivada\Domain\Persona       $p
 * @var AsdeBalears\AreaPrivada\Fichas\PermisosFicha $perm
 * @var array<string, array>                         $campos  campo => [etiqueta, grupo, valor, meta, accion, motivo]
 * @var array{numero:?string, estado:string}         $numero
 * @var array[]                                      $cambios Cambios propuestos de esta ficha (todos los estados)
 * @var bool                                         $escritura El usuario del CRM puede editar fichas
 * @var array<string, AsdeBalears\AreaPrivada\Domain\Persona> $representados
 * @var AsdeBalears\AreaPrivada\Auth\Ambito          $ambito
 * @var string                                       $url
 * @var string                                       $url_personas
 * @var string                                       $url_cambios
 * @var string                                       $url_alta
 */
use AsdeBalears\AreaPrivada\Crm\Escritura;
use AsdeBalears\AreaPrivada\Domain\Secciones;
use AsdeBalears\AreaPrivada\Fichas\Cambios;
use AsdeBalears\AreaPrivada\Fichas\CamposFicha;
use AsdeBalears\AreaPrivada\Fichas\FichaService;
use AsdeBalears\AreaPrivada\Fichas\PermisosFicha;
use AsdeBalears\AreaPrivada\Domain\Identidad;
use AsdeBalears\AreaPrivada\Socios\Numeracion;
use AsdeBalears\AreaPrivada\Socios\Numeros;

defined( 'ABSPATH' ) || exit;

$editables  = array_filter( $campos, static fn( $f ) => PermisosFicha::VER !== $f['accion'] );
$propone    = (bool) array_filter( $campos, static fn( $f ) => PermisosFicha::PROPONER === $f['accion'] );
$pendientes = array();
foreach ( $cambios as $c ) {
	if ( Cambios::PENDIENTE === $c['estado'] ) {
		$pendientes[ $c['campo'] ] = $c;
	}
}

$control = static function ( string $campo, array $f ) use ( $pendientes ): string {
	$id    = 'asde-f-' . $campo;
	$name  = 'campo[' . $campo . ']';
	$v     = $f['valor'];
	$m     = $f['meta'];
	$tipo  = (string) ( $m['tipo'] ?? 'varchar' );
	$extra = ! empty( $m['requerido'] ) ? ' required' : '';
	if ( PermisosFicha::VER === $f['accion'] ) {
		$texto = FichaService::mostrar( $v, $m );
		return 'text' === $tipo
			? sprintf( '<textarea id="%s" class="asde-ro" rows="2" readonly title="%s">%s</textarea>', esc_attr( $id ), esc_attr( $f['motivo'] ), esc_textarea( '—' === $texto ? '' : $texto ) )
			: sprintf( '<input type="text" id="%s" class="asde-ro" value="%s" readonly title="%s">', esc_attr( $id ), esc_attr( $texto ), esc_attr( $f['motivo'] ) );
	}
	$huella = sprintf( '<input type="hidden" name="huella[%s]" value="%s">', esc_attr( $campo ), esc_attr( FichaService::huella( $v ) ) );
	$maxlen = ! empty( $m['len'] ) ? ' maxlength="' . (int) $m['len'] . '"' : '';
	switch ( $tipo ) {
		case 'text':
			return $huella . sprintf( '<textarea id="%s" name="%s" rows="3"%s>%s</textarea>', esc_attr( $id ), esc_attr( $name ), $extra, esc_textarea( $v ) );
		case 'date':
			return $huella . sprintf( '<input type="date" id="%s" name="%s" value="%s"%s>', esc_attr( $id ), esc_attr( $name ), esc_attr( $v ), $extra );
		case 'bool':
			return $huella . sprintf(
				'<input type="hidden" name="%1$s" value="0"><label class="asde-choice"><input type="checkbox" id="%2$s" name="%1$s" value="1"%3$s><span>Sí</span></label>',
				esc_attr( $name ),
				esc_attr( $id ),
				Escritura::iguales( $v, '1' ) ? ' checked' : ''
			);
		case 'enum':
		case 'dynamicenum':
		case 'radioenum':
			$ayudas   = (array) ( $m['ayudas'] ?? array() );
			$opciones = '';
			foreach ( (array) $m['opciones'] as $clave => $etiqueta ) {
				if ( '' === (string) $clave && ! empty( $m['requerido'] ) ) {
					continue;
				}
				$ayuda     = $ayudas[ (string) $clave ] ?? '';
				$opciones .= sprintf(
					'<option value="%s"%s%s>%s</option>',
					esc_attr( (string) $clave ),
					(string) $clave === $v ? ' selected' : '',
					'' !== $ayuda ? ' title="' . esc_attr( $ayuda ) . '" data-ayuda="' . esc_attr( $ayuda ) . '"' : '',
					esc_html( '' === (string) $clave ? '—' : $etiqueta )
				);
			}
			if ( ! array_key_exists( '', (array) $m['opciones'] ) && empty( $m['requerido'] ) ) {
				$opciones = '<option value="">—</option>' . $opciones;
			}
			if ( ! $ayudas ) {
				return $huella . sprintf( '<select id="%s" name="%s"%s>%s</select>', esc_attr( $id ), esc_attr( $name ), $extra, $opciones );
			}
			// Desplegable con explicación de cada opción (al pasar el ratón) y de la elegida (debajo).
			return $huella . sprintf(
				'<select id="%1$s" name="%2$s" class="asde-select-ayuda" aria-describedby="%1$s-ayuda"%3$s>%4$s</select><span id="%1$s-ayuda" class="asde-help asde-select-ayuda__texto">%5$s</span>',
				esc_attr( $id ),
				esc_attr( $name ),
				$extra,
				$opciones,
				esc_html( $ayudas[ $v ] ?? '' )
			);
		default:
			$html_tipo = 'phone' === $tipo ? 'tel' : ( str_contains( $campo, 'email' ) ? 'email' : 'text' );
			return $huella . sprintf( '<input type="%s" id="%s" name="%s" value="%s"%s%s>', $html_tipo, esc_attr( $id ), esc_attr( $name ), esc_attr( $v ), $maxlen, $extra );
	}
};
?>
<?php if ( count( $representados ) > 1 && $perm->es_representado() ) : ?>
	<nav class="asde-subnav" aria-label="Menores a tu cargo">
		<ul class="asde-subnav__tabs">
			<?php foreach ( $representados as $n => $r ) : ?>
				<li><a href="<?php echo esc_url( add_query_arg( 'socio', $n, $url ) ); ?>"<?php echo $n === $p->num_socio_asde ? ' class="is-active" aria-current="page"' : ''; ?>><?php echo esc_html( $r->nombre ); ?></a></li>
			<?php endforeach; ?>
		</ul>
	</nav>
<?php endif; ?>

<section class="asde-card">
	<header class="asde-card__header asde-card__header--split">
		<div>
			<h2 class="asde-card__title"><?php echo esc_html( $p->nombre_completo() ); ?></h2>
			<?php
			foreach ( $p->secciones as $s ) {
				echo Secciones::badge( $s ); // phpcs:ignore WordPress.Security.EscapeOutput
			}
			?>
		</div>
		<?php if ( current_user_can( 'asde_manage_users' ) ) : ?>
			<span class="asde-actions">
				<a href="<?php echo esc_url( add_query_arg( 'socio', $p->num_socio_asde, $url_alta ) ); ?>" data-asde-modal>Acceso web</a>
				<a class="asde-solo-pagina" href="<?php echo esc_url( $url_personas ); ?>">← Personas</a>
			</span>
		<?php endif; ?>
	</header>
	<dl class="asde-datos">
		<div>
			<dt><?php echo Identidad::es_asde() ? 'Nº socio/a Scouts de España' : 'Nº socio/a'; ?></dt>
			<dd><?php echo Numeros::html( $p, $numero ); // phpcs:ignore WordPress.Security.EscapeOutput ?></dd>
		</div>
		<div><dt>Edad</dt><dd><?php echo null === $p->edad() ? '—' : (int) $p->edad() . ' años'; ?></dd></div>
	</dl>

	<?php if ( ! $escritura ) : ?>
		<div class="asde-notice asde-notice--info" role="status">El usuario del CRM de tu grupo no tiene permiso para editar fichas: todos los datos son de <strong>solo lectura</strong>.</div>
	<?php elseif ( ! $editables ) : ?>
		<p class="asde-muted">🔒 Todos los datos de esta ficha son de solo lectura para ti.</p>
	<?php elseif ( $propone ) : ?>
		<div class="asde-notice asde-notice--info" role="status">Los cambios que hagas los revisará la Secretaría o un scouter de su sección antes de guardarse en el CRM.</div>
	<?php else : ?>
		<p class="asde-muted">Los cambios se guardan directamente en el CRM. 🔒 = solo lectura (pasa el ratón por encima para ver el motivo).</p>
	<?php endif; ?>
</section>

<form method="post" class="asde-form asde-ficha">
	<?php wp_nonce_field( 'asde_ficha_' . $p->num_socio_asde ); ?>
	<input type="hidden" name="asde_action" value="guardar_ficha">
	<input type="hidden" name="socio" value="<?php echo esc_attr( $p->num_socio_asde ); ?>">
	<p class="asde-ficha__plegar" hidden>
		<button type="button" class="asde-btn-link" data-asde-plegar="todo">Contraer todo</button>
		<button type="button" class="asde-btn-link" data-asde-plegar="nada">Expandir todo</button>
	</p>
	<?php foreach ( CamposFicha::GRUPOS as $grupo => $titulo ) : ?>
		<?php $del_grupo = array_filter( $campos, static fn( $f ) => $f['grupo'] === $grupo ); ?>
		<?php if ( ! $del_grupo ) : ?>
			<?php continue; ?>
		<?php endif; ?>
		<details class="asde-card asde-plegable" id="apartado-<?php echo esc_attr( $grupo ); ?>" data-grupo="<?php echo esc_attr( $grupo ); ?>" open>
			<summary class="asde-plegable__titulo">
				<h3><?php echo esc_html( $titulo ); ?></h3>
				<span class="asde-plegable__resumen asde-muted"><?php echo esc_html( sprintf( _n( '%d dato', '%d datos', count( $del_grupo ), 'asde-area-privada' ), count( $del_grupo ) ) ); ?></span>
			</summary>
			<div class="asde-grid-2">
				<?php foreach ( $del_grupo as $campo => $f ) : ?>
					<p class="asde-ficha__campo<?php echo PermisosFicha::VER === $f['accion'] ? ' is-ro' : ''; ?>">
						<label for="asde-f-<?php echo esc_attr( $campo ); ?>">
							<?php echo esc_html( $f['etiqueta'] ); ?>
							<?php if ( PermisosFicha::VER === $f['accion'] ) : ?>
								<span class="asde-ro-icon" aria-hidden="true" title="<?php echo esc_attr( $f['motivo'] ); ?>">🔒</span>
								<span class="screen-reader-text">(solo lectura: <?php echo esc_html( $f['motivo'] ); ?>)</span>
							<?php elseif ( PermisosFicha::PROPONER === $f['accion'] ) : ?>
								<span class="asde-help asde-help--inline">(se revisará)</span>
							<?php endif; ?>
						</label>
						<?php echo $control( $campo, $f ); // phpcs:ignore WordPress.Security.EscapeOutput -- escapado en $control. ?>
						<?php if ( isset( $pendientes[ $campo ] ) ) : ?>
							<span class="asde-help asde-help--warning">Cambio pendiente de aprobación: «<?php echo esc_html( FichaService::mostrar( $pendientes[ $campo ]['nuevo'], $f['meta'] ) ); ?>»</span>
						<?php endif; ?>
					</p>
				<?php endforeach; ?>
			</div>
		</details>
	<?php endforeach; ?>
	<?php if ( $editables ) : ?>
		<p class="asde-ficha__guardar"><button type="submit" class="button"><?php echo $propone ? 'Enviar cambios para su aprobación' : 'Guardar cambios en el CRM'; ?></button></p>
	<?php endif; ?>
</form>

<?php if ( $cambios ) : ?>
	<section class="asde-card">
		<h3>Cambios propuestos en esta ficha</h3>
		<table class="asde-table">
			<thead><tr><th scope="col">Fecha</th><th scope="col">Dato</th><th scope="col">Nuevo valor</th><th scope="col">Estado</th><th scope="col"><span class="screen-reader-text">Acciones</span></th></tr></thead>
			<tbody>
				<?php foreach ( array_slice( $cambios, 0, 20 ) as $c ) : ?>
					<?php $f = $campos[ $c['campo'] ] ?? array( 'etiqueta' => $c['campo'], 'meta' => array() ); ?>
					<tr>
						<td data-label="Fecha"><?php echo esc_html( wp_date( 'd/m/Y H:i', strtotime( $c['propuesto_en'] . ' UTC' ) ) ); ?></td>
						<td data-label="Dato"><?php echo esc_html( $f['etiqueta'] ); ?></td>
						<td data-label="Nuevo valor"><?php echo esc_html( FichaService::mostrar( $c['nuevo'], $f['meta'] ) ); ?></td>
						<td data-label="Estado">
							<?php echo esc_html( Cambios::ESTADOS[ $c['estado'] ] ?? $c['estado'] ); ?>
							<?php if ( '' !== $c['motivo'] && in_array( $c['estado'], array( Cambios::RECHAZADO, Cambios::ERROR ), true ) ) : ?>
								<br><span class="asde-help asde-help--warning"><?php echo esc_html( $c['motivo'] ); ?></span>
							<?php endif; ?>
						</td>
						<td data-label="">
							<?php if ( Cambios::PENDIENTE === $c['estado'] && (int) $c['propuesto_por'] === get_current_user_id() ) : ?>
								<form method="post">
									<?php wp_nonce_field( 'asde_cancelar_cambio_' . (int) $c['id'] ); ?>
									<input type="hidden" name="asde_action" value="cancelar_cambio">
									<input type="hidden" name="cambio" value="<?php echo (int) $c['id']; ?>">
									<input type="hidden" name="socio" value="<?php echo esc_attr( $p->num_socio_asde ); ?>">
									<button type="submit" class="asde-btn-link">Retirar</button>
								</form>
							<?php elseif ( Cambios::PENDIENTE === $c['estado'] && current_user_can( 'asde_approve_changes' ) ) : ?>
								<a href="<?php echo esc_url( $url_cambios ); ?>">Revisar</a>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</section>
<?php endif; ?>
