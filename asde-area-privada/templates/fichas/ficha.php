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
 * @var string                                       $socio_desde Y-m-d desde el que es socio/a sin cortes (v0.34), o ''
 * @var bool                                         $eliminar    Puede «Eliminar de la web» (sin membresía activa; v0.35.1)
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Crm\Escritura;
use AsdeBalears\AreaPrivada\Domain\Secciones;
use AsdeBalears\AreaPrivada\Fichas\Cambios;
use AsdeBalears\AreaPrivada\Fichas\CamposFicha;
use AsdeBalears\AreaPrivada\Fichas\FichaService;
use AsdeBalears\AreaPrivada\Frontend\TemplateLoader;
use AsdeBalears\AreaPrivada\Menus\SelectorAlergenos;
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
	// v0.41 (doc 49, B3): las alergias e intolerancias alimentarias se eligen en una ventana con pestañas y casillas (siguen siendo texto en el CRM).
	if ( isset( SelectorAlergenos::CAMPOS[ $campo ] ) && in_array( $tipo, array( 'text', 'varchar', 'name', '' ), true ) ) {
		return $huella . TemplateLoader::render(
			'fichas/alergenos',
			array(
				'id'       => $id,
				'name'     => $name,
				'valor'    => $v,
				'extra'    => $extra,
				'maxlen'   => (int) ( $m['len'] ?? 0 ),
				'etiqueta' => $f['etiqueta'],
				'inicial'  => SelectorAlergenos::CAMPOS[ $campo ],
			)
		);
	}
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
	<nav class="asde-subnav" aria-label="<?php echo esc_attr( esc_attr__( 'Minors in your care', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
		<ul class="asde-subnav__tabs">
			<?php foreach ( $representados as $n => $r ) : ?>
				<li><a href="<?php echo esc_url( add_query_arg( 'socio', $n, $url ) ); ?>"<?php echo $n === $p->num_socio_asde ? ' class="is-active" aria-current="page"' : ''; ?>><?php echo esc_html( $r->nombre ); ?></a></li>
			<?php endforeach; ?>
		</ul>
	</nav>
<?php endif; ?>

<?php /* v0.29: arriba del todo; pliega también los cuadros de la ficha (ficha.js y area.js). */ ?>
<p class="asde-ficha__plegar" hidden>
	<button type="button" class="asde-btn-link" data-asde-plegar="todo"><?php esc_html_e( 'Collapse all', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
	<button type="button" class="asde-btn-link" data-asde-plegar="nada"><?php esc_html_e( 'Expand all', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
</p>

<?php /* Plegable (v0.29): contraído, solo se ven el nombre y la sección o el cargo (la cabecera). */ ?>
<section class="asde-card asde-ficha__cabecera" data-asde-plegable="ficha-cabecera">
	<header class="asde-card__header">
		<h2 class="asde-card__title"><?php echo esc_html( $p->nombre_completo() ); ?></h2>
		<p class="asde-ficha__rol">
			<?php
			if ( $p->tiene_membresia_activa() ) {
				foreach ( $p->secciones as $s ) {
					echo wp_kses( Secciones::badge( $s ), asde_html_permitido() );
				}
			} else {
				echo '<span class="asde-badge asde-badge--estado-desactivado">' . esc_html( __( 'No active membership', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) . '</span>';
			}
			?>
			<?php if ( '' !== trim( $p->cargo ) && $perm->ve_campo( 'cargoeducativo_c' ) ) : ?>
				<span class="asde-muted"><?php echo esc_html( ( Catalogo::enums()['cargoeducativo_c'][ $p->cargo ] ?? $p->cargo ) ); ?></span>
			<?php endif; ?>
		</p>
	</header>
	<?php if ( current_user_can( 'asde_manage_users' ) ) : ?>
		<p class="asde-actions asde-ficha__accesos">
			<a href="<?php echo esc_url( add_query_arg( 'socio', $p->num_socio_asde, $url_alta ) ); ?>" data-asde-modal><?php esc_html_e( 'Web access', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a>
			<a class="asde-solo-pagina" href="<?php echo esc_url( $url_personas ); ?>"><?php esc_html_e( '← People', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a>
		</p>
	<?php endif; ?>
	<dl class="asde-datos">
		<div>
			<dt><?php echo Identidad::es_asde() ? esc_html( __( 'Scouts de España member no.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) : esc_html( __( 'Member no.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?></dt>
			<dd><?php echo wp_kses( Numeros::html( $p, $numero ), asde_html_permitido() ); ?></dd>
		</div>
		<div><dt><?php esc_html_e( 'Age', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></dt><dd><?php echo null === $p->edad() ? '—' : sprintf( esc_html( /* translators: %d: number */ __( '%d years', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ), (int) $p->edad() ); ?></dd></div>
		<?php if ( ! empty( $socio_desde ) ) : ?>
			<?php $antiguedad = date_diff( date_create( $socio_desde ), date_create( wp_date( 'Y-m-d' ) ) ); ?>
			<div>
				<dt><?php esc_html_e( 'Member since', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></dt>
				<dd>
					<?php echo esc_html( wp_date( 'd/m/Y', (int) strtotime( $socio_desde . ' 12:00' ) ) ); ?>
					<span class="asde-muted asde-ficha__antiguedad"><?php echo esc_html( sprintf( /* translators: 1: text, 2: text, 3: text */ __( '(%1$s, %2$s and %3$s)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), sprintf( /* translators: %d: number */ _n( '%d year', '%d years', $antiguedad->y , 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $antiguedad->y ), sprintf( /* translators: %d: number */ _n( '%d month', '%d months', $antiguedad->m , 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $antiguedad->m ), sprintf( /* translators: %d: number */ _n( '%d day', '%d days', $antiguedad->d , 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $antiguedad->d ) ) ); ?></span>
				</dd>
			</div>
		<?php endif; ?>
	</dl>

	<?php if ( ! $escritura ) : ?>
		<div class="asde-notice asde-notice--info" role="status"><?php esc_html_e( 'Your group\'s CRM user does not have permission to edit records: all the data is', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <strong><?php esc_html_e( 'read-only', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></strong>.</div>
	<?php elseif ( ! $editables ) : ?>
		<p class="asde-muted"><?php esc_html_e( '🔒 All the data on this record is read-only for you.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
	<?php elseif ( $propone ) : ?>
		<div class="asde-notice asde-notice--info" role="status"><?php esc_html_e( 'The changes you make will be reviewed by the Secretary\'s office or a scouter of their section before being saved in the CRM.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></div>
	<?php else : ?>
		<p class="asde-muted"><?php esc_html_e( 'Changes are saved directly in the CRM. 🔒 = read-only (hover over it to see why).', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
	<?php endif; ?>
</section>

<?php if ( $altas ) : ?>
	<?php $membresia = static fn( string $accion ): string => add_query_arg( array( 'socio' => $p->num_socio_asde, 'membresia' => $accion ), $url ); ?>
	<section class="asde-card asde-membresia-crm" data-asde-plegable="ficha-membresia">
		<h3><?php esc_html_e( 'Membership in the CRM', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h3>
		<p>
			<?php if ( $p->tiene_membresia_activa() ) : ?>
				<?php esc_html_e( 'Active membership:', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?>
				<?php
				foreach ( $p->secciones as $s ) {
					echo wp_kses( Secciones::badge( $s ), asde_html_permitido() );
				}
				?>
			<?php else : ?>
				<strong><?php esc_html_e( 'No active membership', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></strong> · <?php esc_html_e( 'cannot sign in to the website or count in the fees.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?>
			<?php endif; ?>
		</p>
		<p class="asde-actions asde-membresia-crm__acciones">
			<?php if ( $p->tiene_membresia_activa() ) : ?>
				<a class="button asde-button-secundario" href="<?php echo esc_url( $membresia( 'seccion' ) ); ?>" data-asde-modal><?php esc_html_e( 'Change section', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a>
				<a class="button asde-button-secundario" href="<?php echo esc_url( $membresia( 'baja' ) ); ?>" data-asde-modal><?php esc_html_e( 'Withdraw', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a>
			<?php else : ?>
				<a class="button" href="<?php echo esc_url( $membresia( 'nueva' ) ); ?>" data-asde-modal><?php esc_html_e( 'New membership', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a>
			<?php endif; ?>
			<a href="<?php echo esc_url( $membresia( 'documento' ) ); ?>" data-asde-modal><?php esc_html_e( 'Set the document', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a>
		</p>
	</section>
<?php endif; ?>

<?php if ( $eliminar ) : ?>
	<section class="asde-card">
		<form method="post" data-asde-confirmar="<?php echo esc_attr( sprintf( /* translators: %s: text */ __( 'Remove %s from the website? They stop appearing in People, leave their family and their sign-in accounts are deactivated. Nothing changes in the CRM.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $p->nombre_completo() ) ); ?>">
			<?php wp_nonce_field( 'asde_eliminar_' . $p->num_socio_asde ); ?>
			<input type="hidden" name="asde_action" value="eliminar_de_la_web">
			<input type="hidden" name="socio" value="<?php echo esc_attr( $p->num_socio_asde ); ?>">
			<button type="submit" class="asde-btn-link asde-btn-danger"><?php esc_html_e( 'Remove from the website', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
			<span class="asde-help"><?php esc_html_e( 'They have no active membership: they stop appearing in People, leave their family and their sign-in accounts are deactivated. Nothing changes in the CRM; if they have an active membership again, they appear again.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span>
		</form>
	</section>
<?php endif; ?>

<form method="post" class="asde-form asde-ficha">
	<?php wp_nonce_field( 'asde_ficha_' . $p->num_socio_asde ); ?>
	<input type="hidden" name="asde_action" value="guardar_ficha">
	<input type="hidden" name="socio" value="<?php echo esc_attr( $p->num_socio_asde ); ?>">
	<?php foreach ( ( CamposFicha::grupos() ) as $grupo => $titulo ) : ?>
		<?php $del_grupo = array_filter( $campos, static fn( $f ) => $f['grupo'] === $grupo ); ?>
		<?php if ( ! $del_grupo ) : ?>
			<?php continue; ?>
		<?php endif; ?>
		<details class="asde-card asde-plegable" id="apartado-<?php echo esc_attr( $grupo ); ?>" data-grupo="<?php echo esc_attr( $grupo ); ?>" open>
			<summary class="asde-plegable__titulo">
				<h3><?php echo esc_html( $titulo ); ?></h3>
				<span class="asde-plegable__resumen asde-muted"><?php echo esc_html( sprintf( /* translators: %d: number */ _n( '%d item of data', '%d items of data', count( $del_grupo ) , 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), count( $del_grupo ) ) ); ?></span>
			</summary>
			<div class="asde-grid-2">
				<?php foreach ( $del_grupo as $campo => $f ) : ?>
					<p class="asde-ficha__campo<?php echo PermisosFicha::VER === $f['accion'] ? ' is-ro' : ''; ?>">
						<label for="asde-f-<?php echo esc_attr( $campo ); ?>">
							<?php echo esc_html( $f['etiqueta'] ); ?>
							<?php if ( PermisosFicha::VER === $f['accion'] ) : ?>
								<span class="asde-ro-icon" aria-hidden="true" title="<?php echo esc_attr( $f['motivo'] ); ?>">🔒</span>
								<span class="screen-reader-text"><?php esc_html_e( '(read-only:', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <?php echo esc_html( $f['motivo'] ); ?>)</span>
							<?php elseif ( PermisosFicha::PROPONER === $f['accion'] ) : ?>
								<span class="asde-help asde-help--inline"><?php esc_html_e( '(will be reviewed)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span>
							<?php endif; ?>
						</label>
						<?php echo wp_kses( $control( $campo, $f ), asde_html_permitido() ); ?>
						<?php if ( isset( $pendientes[ $campo ] ) ) : ?>
							<span class="asde-help asde-help--warning"><?php esc_html_e( 'Change pending approval: "', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?><?php echo esc_html( FichaService::mostrar( $pendientes[ $campo ]['nuevo'], $f['meta'] ) ); ?>»</span>
						<?php endif; ?>
					</p>
				<?php endforeach; ?>
			</div>
		</details>
	<?php endforeach; ?>
	<?php if ( $editables ) : ?>
		<p class="asde-ficha__guardar"><button type="submit" class="button"><?php echo $propone ? esc_html( __( 'Send changes for approval', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) : esc_html( __( 'Save changes in the CRM', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?></button></p>
	<?php endif; ?>
</form>

<?php
if ( '' !== ( $sepa ?? '' ) ) {
	echo wp_kses( $sepa, asde_html_permitido() );
}
?>

<?php if ( $cambios ) : ?>
	<section class="asde-card" data-asde-plegable="ficha-cambios">
		<h3><?php esc_html_e( 'Changes proposed on this record', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h3>
		<table class="asde-table">
			<thead><tr><th scope="col"><?php esc_html_e( 'Date', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th><th scope="col"><?php esc_html_e( 'Item of data', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th><th scope="col"><?php esc_html_e( 'New value', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th><th scope="col"><?php esc_html_e( 'Status', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></th><th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span></th></tr></thead>
			<tbody>
				<?php foreach ( array_slice( $cambios, 0, 20 ) as $c ) : ?>
					<?php $f = $campos[ $c['campo'] ] ?? array( 'etiqueta' => $c['campo'], 'meta' => array() ); ?>
					<tr>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Date', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo esc_html( wp_date( 'd/m/Y H:i', strtotime( $c['propuesto_en'] . ' UTC' ) ) ); ?></td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Item of data', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo esc_html( $f['etiqueta'] ); ?></td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'New value', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>"><?php echo esc_html( FichaService::mostrar( $c['nuevo'], $f['meta'] ) ); ?></td>
						<td data-label="<?php echo esc_attr( esc_attr__( 'Status', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>">
							<?php echo esc_html( ( Cambios::estados()[ $c['estado'] ] ?? $c['estado'] ) ); ?>
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
									<button type="submit" class="asde-btn-link"><?php esc_html_e( 'Remove', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
								</form>
							<?php elseif ( Cambios::PENDIENTE === $c['estado'] && current_user_can( 'asde_approve_changes' ) ) : ?>
								<a href="<?php echo esc_url( add_query_arg( 'socio', $p->num_socio_asde, $url_cambios ) ); ?>" data-asde-modal><?php esc_html_e( 'Review', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</section>
<?php endif; ?>
