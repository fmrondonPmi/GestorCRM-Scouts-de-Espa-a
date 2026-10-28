<?php
/**
 * Ficha → membresía en el CRM (doc 34, fase 3): nueva membresía (quien vuelve), baja,
 * cambio de sección y poner el documento a quien no lo tiene. Se abre en la ventana
 * emergente desde la ficha; sin JavaScript, en su página.
 *
 * @var AsdeBalears\AreaPrivada\Domain\Persona $p
 * @var string               $accion    nueva|baja|seccion|documento
 * @var bool                 $altas     Altas en el CRM disponibles en el grupo
 * @var string               $motivo    Por qué no lo están
 * @var array                $estado    Lo enviado que no se ha podido hacer: error, valores, confirmar, otros
 * @var list<array>          $activas   Membresías activas (baja y cambio de sección), del CRM
 * @var bool|null            $documento ¿Tiene ya documento? (null: el grupo no ve la ficha)
 * @var string               $cuenta    Cuenta de acceso de la persona ('' si no tiene)
 * @var string[]             $familias  Cuentas familiares que la representan
 * @var string               $familia   Nº de su familia en Tesorería ('' si no está en ninguna)
 * @var string               $url       Este formulario
 * @var string               $url_ficha
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Crm\Altas;
use AsdeBalears\AreaPrivada\Domain\Secciones;

defined( 'ABSPATH' ) || exit;

$hoy     = wp_date( 'Y-m-d' );
$v       = static fn( string $c, string $defecto = '' ): string => (string) ( $estado['valores'][ $c ] ?? $defecto );
$dma     = static function ( string $f ): string {
	$d = DateTime::createFromFormat( '!Y-m-d', $f );
	return $d ? $d->format( 'd/m/Y' ) : $f;
};
$titulos = array(
	'nueva'     => __( 'New membership', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
	'baja'      => __( 'Withdraw', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
	'seccion'   => __( 'Change section', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
	'documento' => __( 'Set the document', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
);
$comun   = static function ( string $asde_action ) use ( $p ): void {
	wp_nonce_field( 'asde_membresia_' . $p->num_socio_asde );
	printf( '<input type="hidden" name="asde_action" value="%s"><input type="hidden" name="socio" value="%s">', esc_attr( $asde_action ), esc_attr( $p->num_socio_asde ) );
};
$fecha   = static function ( string $etiqueta, string $min = '' ) use ( $v, $hoy ): void {
	printf(
		'<p><label for="asde-memb-fecha">%s <span class="asde-obligatorio" aria-hidden="true">*</span></label><input type="date" id="asde-memb-fecha" name="fecha" required max="%s"%s value="%s"></p>',
		esc_html( $etiqueta ),
		esc_attr( $hoy ),
		'' !== $min ? ' min="' . esc_attr( $min ) . '"' : '',
		esc_attr( $v( 'fecha', $hoy ) )
	);
};
$desde   = static fn( array $m ): string => sprintf( /* translators: 1: text, 2: text */ __( '%1$s, since %2$s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), Secciones::nombre( (string) Secciones::from_crm( (string) $m['relationship_type'] ) ), $dma( (string) $m['start_date'] ) );
?>
<p class="asde-back"><a href="<?php echo esc_url( $url_ficha ); ?>" data-asde-modal><?php /* translators: %s: nombre */ echo esc_html( sprintf( /* translators: %s: text */ __( '← %s\'s record', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $p->nombre_completo() ) ); ?></a></p>
<section class="asde-card asde-membresia-crm" data-asde-plegable="ficha-membresia-accion">
	<h2 class="asde-card__title"><?php echo esc_html( $titulos[ $accion ] . ' · ' . $p->nombre_completo() ); ?></h2>

	<?php if ( ! $altas ) : ?>
		<div class="asde-notice asde-notice--info" role="status"><p><?php echo esc_html( $motivo ); ?></p></div>
	<?php else : ?>
		<?php if ( '' !== $estado['error'] && ! $estado['confirmar'] ) : ?>
			<div class="asde-notice asde-notice--error" role="alert"><p><?php echo esc_html( $estado['error'] ); ?></p></div>
		<?php endif; ?>

		<?php if ( 'nueva' === $accion ) : ?>
			<?php if ( $p->tiene_membresia_activa() ) : ?>
				<div class="asde-notice asde-notice--info" role="status">
					<p><?php /* translators: %s: secciones */ echo esc_html( sprintf( /* translators: %s: text */ __( 'Already has an active membership (%s).', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), implode( ', ', array_map( array( Secciones::class, 'nombre' ), $p->secciones ) ) ) ); ?>
					<a href="<?php echo esc_url( add_query_arg( 'membresia', 'seccion', $url_ficha ) ); ?>" data-asde-modal><?php esc_html_e( 'Change section', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a></p>
				</div>
			<?php else : ?>
				<p class="asde-muted"><?php esc_html_e( 'For someone returning to the group: the new membership goes on their usual record, with the same member number. No other record is created.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
				<form method="post" action="<?php echo esc_url( $url ); ?>" class="asde-form">
					<?php $comun( 'membresia_nueva' ); ?>
					<div class="asde-form__columnas">
						<p>
							<label for="asde-memb-seccion"><?php esc_html_e( 'Section', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <span class="asde-obligatorio" aria-hidden="true">*</span></label>
							<select id="asde-memb-seccion" name="seccion" required>
								<option value=""><?php esc_html_e( 'Choose…', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></option>
								<?php foreach ( Secciones::all() as $slug => $sec ) : ?>
									<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $v( 'seccion' ), $slug ); ?>><?php echo esc_html( $sec['nombre'] . ( '' !== $sec['edad'] ? ' (' . $sec['edad'] . ')' : '' ) ); ?></option>
								<?php endforeach; ?>
							</select>
						</p>
						<?php $fecha( __( 'Registration date', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?>
					</div>
					<p class="asde-form__acciones"><button type="submit" class="button button-primary"><?php esc_html_e( 'Give the membership in the CRM', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button></p>
				</form>
			<?php endif; ?>

		<?php elseif ( 'baja' === $accion ) : ?>
			<?php if ( ! $activas ) : ?>
				<div class="asde-notice asde-notice--info" role="status"><p><?php esc_html_e( 'Has no active membership.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p></div>
			<?php else : ?>
				<dl class="asde-datos">
					<div><dt><?php echo esc_html( _n( 'Membership being closed', 'Memberships being closed', count( $activas ) , 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?></dt><dd><?php echo esc_html( implode( ' · ', array_map( $desde, $activas ) ) ); ?></dd></div>
				</dl>
				<div class="asde-notice asde-notice--warning" role="status">
					<p><strong><?php esc_html_e( 'What changes with the withdrawal:', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></strong></p>
					<ul>
						<?php if ( '' !== $cuenta ) : ?>
							<li><?php /* translators: %s: usuario */ echo esc_html( sprintf( /* translators: %s: text */ __( 'Their sign-in account (%s) will no longer be able to sign in to the website.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $cuenta ) ); ?></li>
						<?php endif; ?>
						<?php if ( $familias ) : ?>
							<li><?php /* translators: %s: usuarios */ echo esc_html( sprintf( /* translators: %s: text */ __( 'The family accounts that represent them (%s) will no longer see them; if they do not represent anyone else with an active membership, they will not be able to sign in either.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), implode( ', ', $familias ) ) ); ?></li>
						<?php endif; ?>
						<?php if ( '' !== $familia ) : ?>
							<li><?php /* translators: %s: nº de familia */ echo esc_html( sprintf( /* translators: %s: text */ __( 'They stop counting in their family\'s fee (no. %s). Treasury will see it in "Recent withdrawals".', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $familia ) ); ?></li>
						<?php endif; ?>
						<li><?php esc_html_e( 'Treasury and the family (or the person themselves, if of legal age) are notified by email. With the reason "Death" the family is not written to.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></li>
						<li><?php esc_html_e( 'Nothing is deleted: the record and their previous memberships stay in the CRM. If they return, they are given a new membership.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></li>
					</ul>
				</div>
				<form method="post" action="<?php echo esc_url( $url ); ?>" class="asde-form" data-asde-confirmar="<?php echo esc_attr( sprintf( /* translators: %s: text */ __( 'Withdraw %s in the CRM?', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $p->nombre_completo() ) ); ?>">
					<?php $comun( 'membresia_baja' ); ?>
					<div class="asde-form__columnas">
						<?php $fecha( __( 'Withdrawal date', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), max( array_map( static fn( $m ) => (string) $m['start_date'], $activas ) ) ); ?>
						<p>
							<label for="asde-memb-motivo"><?php esc_html_e( 'Reason', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <span class="asde-obligatorio" aria-hidden="true">*</span></label>
							<select id="asde-memb-motivo" name="motivo" required>
								<option value=""><?php esc_html_e( 'Choose…', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></option>
								<?php foreach ( asde_ta( Altas::MOTIVOS_BAJA ) as $m => $etiqueta ) : ?>
									<option value="<?php echo esc_attr( $m ); ?>" <?php selected( $v( 'motivo' ), $m ); ?>><?php echo esc_html( $etiqueta ); ?></option>
								<?php endforeach; ?>
							</select>
						</p>
					</div>
					<p class="asde-help"><?php esc_html_e( 'The withdrawal date is the day they stop being in the group: from that day they no longer count as a member.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
					<p class="asde-form__acciones"><button type="submit" class="button button-primary"><?php esc_html_e( 'Withdraw in the CRM', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button></p>
				</form>
			<?php endif; ?>

		<?php elseif ( 'seccion' === $accion ) : ?>
			<?php if ( 1 !== count( $activas ) ) : ?>
				<div class="asde-notice asde-notice--info" role="status"><p><?php esc_html_e( 'To change section, they must have an active membership (and only one).', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p></div>
			<?php else : ?>
				<?php $actual = (string) Secciones::from_crm( (string) $activas[0]['relationship_type'] ); ?>
				<dl class="asde-datos">
					<div><dt><?php esc_html_e( 'Now', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></dt><dd><?php echo esc_html( $desde( $activas[0] ) ); ?></dd></div>
				</dl>
				<form method="post" action="<?php echo esc_url( $url ); ?>" class="asde-form">
					<?php $comun( 'membresia_seccion' ); ?>
					<div class="asde-form__columnas">
						<p>
							<label for="asde-memb-seccion"><?php esc_html_e( 'New section', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <span class="asde-obligatorio" aria-hidden="true">*</span></label>
							<select id="asde-memb-seccion" name="seccion" required>
								<?php
								// v0.31: un educando, solo a la siguiente por edad; un scouter, a cualquiera.
								$destinos = '' !== $actual ? Secciones::destinos_cambio( $actual ) : array_keys( Secciones::all() );
								?>
								<?php if ( count( $destinos ) > 1 ) : ?>
									<option value=""><?php esc_html_e( 'Choose…', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></option>
								<?php endif; ?>
								<?php foreach ( Secciones::all() as $slug => $sec ) : ?>
									<?php if ( $slug !== $actual && in_array( $slug, $destinos, true ) ) : ?>
										<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $v( 'seccion' ), $slug ); ?>><?php echo esc_html( $sec['nombre'] . ( '' !== $sec['edad'] ? ' (' . $sec['edad'] . ')' : '' ) ); ?></option>
									<?php endif; ?>
								<?php endforeach; ?>
							</select>
							<?php if ( Secciones::is_educativa( $actual ) ) : ?>
								<span class="asde-help"><?php esc_html_e( 'A youth member moves to the next section by age.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span>
							<?php endif; ?>
						</p>
						<?php $fecha( __( 'Date of the change', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), (string) $activas[0]['start_date'] ); ?>
					</div>
					<p class="asde-help"><?php esc_html_e( 'The current membership is closed with the reason "change of section" and another is opened in the new section, with the same date.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
					<p class="asde-form__acciones"><button type="submit" class="button button-primary"><?php esc_html_e( 'Change section in the CRM', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button></p>
				</form>
			<?php endif; ?>

		<?php else : ?>
			<?php if ( null === $documento ) : ?>
				<div class="asde-notice asde-notice--error" role="alert"><p><?php esc_html_e( 'That record cannot be found in the group\'s CRM.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p></div>
			<?php elseif ( $documento ) : ?>
				<div class="asde-notice asde-notice--info" role="status"><p><?php esc_html_e( 'This record already has a document. If it is wrong, correct it in the CRM.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p></div>
			<?php else : ?>
				<p class="asde-muted"><?php esc_html_e( 'For someone who did not have it yet (for example, a minor who has just got it). Before saving it, it is checked whether it is already on another record in your group or in another Scouts de España group or organisation.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
				<?php if ( $estado['confirmar'] ) : ?>
					<div class="asde-notice asde-notice--warning" role="status"><p><?php echo esc_html( $estado['error'] ); ?></p></div>
				<?php endif; ?>
				<form method="post" action="<?php echo esc_url( $url ); ?>" class="asde-form">
					<?php $comun( 'poner_documento' ); ?>
					<p>
						<label for="asde-memb-doc"><?php esc_html_e( 'ID, foreigner ID or other document', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <span class="asde-obligatorio" aria-hidden="true">*</span></label>
						<input type="text" id="asde-memb-doc" name="documento" maxlength="30" required autocomplete="off" spellcheck="false" value="<?php echo esc_attr( $v( 'documento' ) ); ?>">
					</p>
					<?php if ( $estado['confirmar'] ) : ?>
						<p>
							<label class="asde-choice">
								<input type="checkbox" name="confirmo" value="1" required>
								<span><?php esc_html_e( 'It is the same person: save the document anyway.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span>
							</label>
						</p>
					<?php endif; ?>
					<p class="asde-form__acciones"><button type="submit" class="button button-primary"><?php esc_html_e( 'Check and save', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button></p>
				</form>
			<?php endif; ?>
		<?php endif; ?>
	<?php endif; ?>
</section>
