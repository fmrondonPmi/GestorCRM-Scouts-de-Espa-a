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
 * @var bool                                         $altas     Puede dar membresías, bajas y cambios en el CRM (doc 34, fase 3)
 * @var array<string, AsdeBalears\AreaPrivada\Domain\Persona> $representados
 * @var AsdeBalears\AreaPrivada\Auth\Ambito          $ambito
 * @var string                                       $url
 * @var string                                       $url_personas
 * @var string                                       $url_cambios
 * @var string                                       $url_alta
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Crm\Escritura;
use AsdeBalears\AreaPrivada\Domain\Secciones;
use AsdeBalears\AreaPrivada\Fichas\Cambios;
use AsdeBalears\AreaPrivada\Fichas\CamposFicha;
use AsdeBalears\AreaPrivada\Fichas\FichaService;
use AsdeBalears\AreaPrivada\Fichas\PermisosFicha;
use AsdeBalears\AreaPrivada\Informes\Catalogo;
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
	<nav class="asde-subnav" aria-label="<?php echo esc_attr( asde_a( 'Menores a tu cargo' ) ); ?>">
		<ul class="asde-subnav__tabs">
			<?php foreach ( $representados as $n => $r ) : ?>
				<li><a href="<?php echo esc_url( add_query_arg( 'socio', $n, $url ) ); ?>"<?php echo $n === $p->num_socio_asde ? ' class="is-active" aria-current="page"' : ''; ?>><?php echo esc_html( $r->nombre ); ?></a></li>
			<?php endforeach; ?>
		</ul>
	</nav>
<?php endif; ?>

<?php /* v0.29: arriba del todo; pliega también los cuadros de la ficha (ficha.js y area.js). */ ?>
<p class="asde-ficha__plegar" hidden>
	<button type="button" class="asde-btn-link" data-asde-plegar="todo"><?php asde_e( 'Contraer todo' ); ?></button>
	<button type="button" class="asde-btn-link" data-asde-plegar="nada"><?php asde_e( 'Expandir todo' ); ?></button>
</p>

<?php /* Plegable (v0.29): contraído, solo se ven el nombre y la sección o el cargo (la cabecera). */ ?>
<section class="asde-card asde-ficha__cabecera" data-asde-plegable="ficha-cabecera">
	<header class="asde-card__header">
		<h2 class="asde-card__title"><?php echo esc_html( $p->nombre_completo() ); ?></h2>
		<p class="asde-ficha__rol">
			<?php
			if ( $p->tiene_membresia_activa() ) {
				foreach ( $p->secciones as $s ) {
					echo Secciones::badge( $s ); // phpcs:ignore WordPress.Security.EscapeOutput
				}
			} else {
				echo '<span class="asde-badge asde-badge--estado-desactivado">' . esc_html( asde_t( 'Sin membresía activa' ) ) . '</span>';
			}
			?>
			<?php if ( '' !== trim( $p->cargo ) ) : ?>
				<span class="asde-muted"><?php echo esc_html( asde_t( Catalogo::ENUMS['cargoeducativo_c'][ $p->cargo ] ?? $p->cargo ) ); ?></span>
			<?php endif; ?>
		</p>
	</header>
	<?php if ( current_user_can( 'asde_manage_users' ) ) : ?>
		<p class="asde-actions asde-ficha__accesos">
			<a href="<?php echo esc_url( add_query_arg( 'socio', $p->num_socio_asde, $url_alta ) ); ?>" data-asde-modal><?php asde_e( 'Acceso web' ); ?></a>
			<a class="asde-solo-pagina" href="<?php echo esc_url( $url_personas ); ?>"><?php asde_e( '← Personas' ); ?></a>
		</p>
	<?php endif; ?>
	<dl class="asde-datos">
		<div>
			<dt><?php echo Identidad::es_asde() ? esc_html( asde_t( 'Nº socio/a Scouts de España' ) ) : esc_html( asde_t( 'Nº socio/a' ) ); ?></dt>
			<dd><?php echo Numeros::html( $p, $numero ); // phpcs:ignore WordPress.Security.EscapeOutput ?></dd>
		</div>
		<div><dt><?php asde_e( 'Edad' ); ?></dt><dd><?php echo null === $p->edad() ? '—' : sprintf( esc_html( asde_t( '%d años' ) ), (int) $p->edad() ); ?></dd></div>
	</dl>

	<?php if ( ! $escritura ) : ?>
		<div class="asde-notice asde-notice--info" role="status"><?php asde_e( 'El usuario del CRM de tu grupo no tiene permiso para editar fichas: todos los datos son de' ); ?> <strong><?php asde_e( 'solo lectura' ); ?></strong>.</div>
	<?php elseif ( ! $editables ) : ?>
		<p class="asde-muted"><?php asde_e( '🔒 Todos los datos de esta ficha son de solo lectura para ti.' ); ?></p>
	<?php elseif ( $propone ) : ?>
		<div class="asde-notice asde-notice--info" role="status"><?php asde_e( 'Los cambios que hagas los revisará la Secretaría o un scouter de su sección antes de guardarse en el CRM.' ); ?></div>
	<?php else : ?>
		<p class="asde-muted"><?php asde_e( 'Los cambios se guardan directamente en el CRM. 🔒 = solo lectura (pasa el ratón por encima para ver el motivo).' ); ?></p>
	<?php endif; ?>
</section>

<?php if ( $altas ) : ?>
	<?php $membresia = static fn( string $accion ): string => add_query_arg( array( 'socio' => $p->num_socio_asde, 'membresia' => $accion ), $url ); ?>
	<section class="asde-card asde-membresia-crm" data-asde-plegable="ficha-membresia">
		<h3><?php asde_e( 'Membresía en el CRM' ); ?></h3>
		<p>
			<?php if ( $p->tiene_membresia_activa() ) : ?>
				<?php asde_e( 'Membresía activa:' ); ?>
				<?php
				foreach ( $p->secciones as $s ) {
					echo Secciones::badge( $s ); // phpcs:ignore WordPress.Security.EscapeOutput
				}
				?>
			<?php else : ?>
				<strong><?php asde_e( 'Sin membresía activa' ); ?></strong> · <?php asde_e( 'no puede entrar en la web ni cuenta en las cuotas.' ); ?>
			<?php endif; ?>
		</p>
		<p class="asde-actions asde-membresia-crm__acciones">
			<?php if ( $p->tiene_membresia_activa() ) : ?>
				<a class="button asde-button-secundario" href="<?php echo esc_url( $membresia( 'seccion' ) ); ?>" data-asde-modal><?php asde_e( 'Cambiar de sección' ); ?></a>
				<a class="button asde-button-secundario" href="<?php echo esc_url( $membresia( 'baja' ) ); ?>" data-asde-modal><?php asde_e( 'Dar de baja' ); ?></a>
			<?php else : ?>
				<a class="button" href="<?php echo esc_url( $membresia( 'nueva' ) ); ?>" data-asde-modal><?php asde_e( 'Nueva membresía' ); ?></a>
			<?php endif; ?>
			<a href="<?php echo esc_url( $membresia( 'documento' ) ); ?>" data-asde-modal><?php asde_e( 'Poner el documento' ); ?></a>
		</p>
	</section>
<?php endif; ?>

<form method="post" class="asde-form asde-ficha">
	<?php wp_nonce_field( 'asde_ficha_' . $p->num_socio_asde ); ?>
	<input type="hidden" name="asde_action" value="guardar_ficha">
	<input type="hidden" name="socio" value="<?php echo esc_attr( $p->num_socio_asde ); ?>">
	<?php foreach ( asde_ta( CamposFicha::GRUPOS ) as $grupo => $titulo ) : ?>
		<?php $del_grupo = array_filter( $campos, static fn( $f ) => $f['grupo'] === $grupo ); ?>
		<?php if ( ! $del_grupo ) : ?>
			<?php continue; ?>
		<?php endif; ?>
		<details class="asde-card asde-plegable" id="apartado-<?php echo esc_attr( $grupo ); ?>" data-grupo="<?php echo esc_attr( $grupo ); ?>" open>
			<summary class="asde-plegable__titulo">
				<h3><?php echo esc_html( $titulo ); ?></h3>
				<span class="asde-plegable__resumen asde-muted"><?php echo esc_html( sprintf( asde_n( '%d dato', '%d datos', count( $del_grupo ) ), count( $del_grupo ) ) ); ?></span>
			</summary>
			<div class="asde-grid-2">
				<?php foreach ( $del_grupo as $campo => $f ) : ?>
					<p class="asde-ficha__campo<?php echo PermisosFicha::VER === $f['accion'] ? ' is-ro' : ''; ?>">
						<label for="asde-f-<?php echo esc_attr( $campo ); ?>">
							<?php echo esc_html( $f['etiqueta'] ); ?>
							<?php if ( PermisosFicha::VER === $f['accion'] ) : ?>
								<span class="asde-ro-icon" aria-hidden="true" title="<?php echo esc_attr( $f['motivo'] ); ?>">🔒</span>
								<span class="screen-reader-text"><?php asde_e( '(solo lectura:' ); ?> <?php echo esc_html( $f['motivo'] ); ?>)</span>
							<?php elseif ( PermisosFicha::PROPONER === $f['accion'] ) : ?>
								<span class="asde-help asde-help--inline"><?php asde_e( '(se revisará)' ); ?></span>
							<?php endif; ?>
						</label>
						<?php echo $control( $campo, $f ); // phpcs:ignore WordPress.Security.EscapeOutput -- escapado en $control. ?>
						<?php if ( isset( $pendientes[ $campo ] ) ) : ?>
							<span class="asde-help asde-help--warning"><?php asde_e( 'Cambio pendiente de aprobación: «' ); ?><?php echo esc_html( FichaService::mostrar( $pendientes[ $campo ]['nuevo'], $f['meta'] ) ); ?>»</span>
						<?php endif; ?>
					</p>
				<?php endforeach; ?>
			</div>
		</details>
	<?php endforeach; ?>
	<?php if ( $editables ) : ?>
		<p class="asde-ficha__guardar"><button type="submit" class="button"><?php echo $propone ? esc_html( asde_t( 'Enviar cambios para su aprobación' ) ) : esc_html( asde_t( 'Guardar cambios en el CRM' ) ); ?></button></p>
	<?php endif; ?>
</form>

<?php if ( $cambios ) : ?>
	<section class="asde-card" data-asde-plegable="ficha-cambios">
		<h3><?php asde_e( 'Cambios propuestos en esta ficha' ); ?></h3>
		<table class="asde-table">
			<thead><tr><th scope="col"><?php asde_e( 'Fecha' ); ?></th><th scope="col"><?php asde_e( 'Dato' ); ?></th><th scope="col"><?php asde_e( 'Nuevo valor' ); ?></th><th scope="col"><?php asde_e( 'Estado' ); ?></th><th scope="col"><span class="screen-reader-text"><?php asde_e( 'Acciones' ); ?></span></th></tr></thead>
			<tbody>
				<?php foreach ( array_slice( $cambios, 0, 20 ) as $c ) : ?>
					<?php $f = $campos[ $c['campo'] ] ?? array( 'etiqueta' => $c['campo'], 'meta' => array() ); ?>
					<tr>
						<td data-label="<?php echo esc_attr( asde_a( 'Fecha' ) ); ?>"><?php echo esc_html( wp_date( 'd/m/Y H:i', strtotime( $c['propuesto_en'] . ' UTC' ) ) ); ?></td>
						<td data-label="<?php echo esc_attr( asde_a( 'Dato' ) ); ?>"><?php echo esc_html( $f['etiqueta'] ); ?></td>
						<td data-label="<?php echo esc_attr( asde_a( 'Nuevo valor' ) ); ?>"><?php echo esc_html( FichaService::mostrar( $c['nuevo'], $f['meta'] ) ); ?></td>
						<td data-label="<?php echo esc_attr( asde_a( 'Estado' ) ); ?>">
							<?php echo esc_html( asde_t( Cambios::ESTADOS[ $c['estado'] ] ?? $c['estado'] ) ); ?>
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
									<button type="submit" class="asde-btn-link"><?php asde_e( 'Retirar' ); ?></button>
								</form>
							<?php elseif ( Cambios::PENDIENTE === $c['estado'] && current_user_can( 'asde_approve_changes' ) ) : ?>
								<a href="<?php echo esc_url( add_query_arg( 'socio', $p->num_socio_asde, $url_cambios ) ); ?>" data-asde-modal><?php asde_e( 'Revisar' ); ?></a>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</section>
<?php endif; ?>
