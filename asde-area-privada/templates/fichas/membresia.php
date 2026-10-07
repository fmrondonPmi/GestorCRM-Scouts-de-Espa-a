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
	'nueva'     => asde_t( 'Nueva membresía' ),
	'baja'      => asde_t( 'Dar de baja' ),
	'seccion'   => asde_t( 'Cambiar de sección' ),
	'documento' => asde_t( 'Poner el documento' ),
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
$desde   = static fn( array $m ): string => sprintf( asde_t( '%1$s, desde el %2$s' ), Secciones::nombre( (string) Secciones::from_crm( (string) $m['relationship_type'] ) ), $dma( (string) $m['start_date'] ) );
?>
<p class="asde-back"><a href="<?php echo esc_url( $url_ficha ); ?>" data-asde-modal><?php /* translators: %s: nombre */ echo esc_html( sprintf( asde_t( '← Ficha de %s' ), $p->nombre_completo() ) ); ?></a></p>
<section class="asde-card asde-membresia-crm">
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
					<p><?php /* translators: %s: secciones */ echo esc_html( sprintf( asde_t( 'Ya tiene una membresía activa (%s).' ), implode( ', ', array_map( array( Secciones::class, 'nombre' ), $p->secciones ) ) ) ); ?>
					<a href="<?php echo esc_url( add_query_arg( 'membresia', 'seccion', $url_ficha ) ); ?>" data-asde-modal><?php asde_e( 'Cambiar de sección' ); ?></a></p>
				</div>
			<?php else : ?>
				<p class="asde-muted"><?php asde_e( 'Para quien vuelve al grupo: la membresía nueva va en su ficha de siempre, con su mismo nº de socio/a. No se crea otra ficha.' ); ?></p>
				<form method="post" action="<?php echo esc_url( $url ); ?>" class="asde-form">
					<?php $comun( 'membresia_nueva' ); ?>
					<div class="asde-form__columnas">
						<p>
							<label for="asde-memb-seccion"><?php asde_e( 'Sección' ); ?> <span class="asde-obligatorio" aria-hidden="true">*</span></label>
							<select id="asde-memb-seccion" name="seccion" required>
								<option value=""><?php asde_e( 'Elige…' ); ?></option>
								<?php foreach ( Secciones::all() as $slug => $sec ) : ?>
									<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $v( 'seccion' ), $slug ); ?>><?php echo esc_html( $sec['nombre'] . ( '' !== $sec['edad'] ? ' (' . $sec['edad'] . ')' : '' ) ); ?></option>
								<?php endforeach; ?>
							</select>
						</p>
						<?php $fecha( asde_t( 'Fecha de alta' ) ); ?>
					</div>
					<p class="asde-form__acciones"><button type="submit" class="button button-primary"><?php asde_e( 'Dar la membresía en el CRM' ); ?></button></p>
				</form>
			<?php endif; ?>

		<?php elseif ( 'baja' === $accion ) : ?>
			<?php if ( ! $activas ) : ?>
				<div class="asde-notice asde-notice--info" role="status"><p><?php asde_e( 'No tiene ninguna membresía activa.' ); ?></p></div>
			<?php else : ?>
				<dl class="asde-datos">
					<div><dt><?php echo esc_html( asde_n( 'Membresía que se cierra', 'Membresías que se cierran', count( $activas ) ) ); ?></dt><dd><?php echo esc_html( implode( ' · ', array_map( $desde, $activas ) ) ); ?></dd></div>
				</dl>
				<div class="asde-notice asde-notice--warning" role="status">
					<p><strong><?php asde_e( 'Qué cambia con la baja:' ); ?></strong></p>
					<ul>
						<?php if ( '' !== $cuenta ) : ?>
							<li><?php /* translators: %s: usuario */ echo esc_html( sprintf( asde_t( 'Su cuenta de acceso (%s) ya no podrá entrar en la web.' ), $cuenta ) ); ?></li>
						<?php endif; ?>
						<?php if ( $familias ) : ?>
							<li><?php /* translators: %s: usuarios */ echo esc_html( sprintf( asde_t( 'Las cuentas familiares que la representan (%s) dejarán de verla; si no representan a nadie más con membresía activa, tampoco podrán entrar.' ), implode( ', ', $familias ) ) ); ?></li>
						<?php endif; ?>
						<?php if ( '' !== $familia ) : ?>
							<li><?php /* translators: %s: nº de familia */ echo esc_html( sprintf( asde_t( 'Deja de contar en la cuota de su familia (nº %s). Tesorería lo verá en «Bajas recientes».' ), $familia ) ); ?></li>
						<?php endif; ?>
						<li><?php asde_e( 'Se avisa por email a Tesorería y a la familia (o a la propia persona, si es mayor de edad). Con el motivo «Fallecimiento» no se escribe a la familia.' ); ?></li>
						<li><?php asde_e( 'No se borra nada: la ficha y sus membresías anteriores se quedan en el CRM. Si vuelve, se le da una membresía nueva.' ); ?></li>
					</ul>
				</div>
				<form method="post" action="<?php echo esc_url( $url ); ?>" class="asde-form" onsubmit="return confirm(<?php echo esc_attr( asde_js( '¿Dar de baja a %s en el CRM?', $p->nombre_completo() ) ); ?>);">
					<?php $comun( 'membresia_baja' ); ?>
					<div class="asde-form__columnas">
						<?php $fecha( asde_t( 'Fecha de baja' ), max( array_map( static fn( $m ) => (string) $m['start_date'], $activas ) ) ); ?>
						<p>
							<label for="asde-memb-motivo"><?php asde_e( 'Motivo' ); ?> <span class="asde-obligatorio" aria-hidden="true">*</span></label>
							<select id="asde-memb-motivo" name="motivo" required>
								<option value=""><?php asde_e( 'Elige…' ); ?></option>
								<?php foreach ( asde_ta( Altas::MOTIVOS_BAJA ) as $m => $etiqueta ) : ?>
									<option value="<?php echo esc_attr( $m ); ?>" <?php selected( $v( 'motivo' ), $m ); ?>><?php echo esc_html( $etiqueta ); ?></option>
								<?php endforeach; ?>
							</select>
						</p>
					</div>
					<p class="asde-help"><?php asde_e( 'La fecha de baja es el día en que deja de estar en el grupo: desde ese día ya no cuenta como socio/a.' ); ?></p>
					<p class="asde-form__acciones"><button type="submit" class="button button-primary"><?php asde_e( 'Dar de baja en el CRM' ); ?></button></p>
				</form>
			<?php endif; ?>

		<?php elseif ( 'seccion' === $accion ) : ?>
			<?php if ( 1 !== count( $activas ) ) : ?>
				<div class="asde-notice asde-notice--info" role="status"><p><?php asde_e( 'Para cambiar de sección tiene que tener una membresía activa (y solo una).' ); ?></p></div>
			<?php else : ?>
				<?php $actual = (string) Secciones::from_crm( (string) $activas[0]['relationship_type'] ); ?>
				<dl class="asde-datos">
					<div><dt><?php asde_e( 'Ahora' ); ?></dt><dd><?php echo esc_html( $desde( $activas[0] ) ); ?></dd></div>
				</dl>
				<form method="post" action="<?php echo esc_url( $url ); ?>" class="asde-form">
					<?php $comun( 'membresia_seccion' ); ?>
					<div class="asde-form__columnas">
						<p>
							<label for="asde-memb-seccion"><?php asde_e( 'Sección nueva' ); ?> <span class="asde-obligatorio" aria-hidden="true">*</span></label>
							<select id="asde-memb-seccion" name="seccion" required>
								<option value=""><?php asde_e( 'Elige…' ); ?></option>
								<?php foreach ( Secciones::all() as $slug => $sec ) : ?>
									<?php if ( $slug !== $actual ) : ?>
										<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $v( 'seccion' ), $slug ); ?>><?php echo esc_html( $sec['nombre'] . ( '' !== $sec['edad'] ? ' (' . $sec['edad'] . ')' : '' ) ); ?></option>
									<?php endif; ?>
								<?php endforeach; ?>
							</select>
						</p>
						<?php $fecha( asde_t( 'Fecha del cambio' ), (string) $activas[0]['start_date'] ); ?>
					</div>
					<p class="asde-help"><?php asde_e( 'Se cierra la membresía actual con el motivo «cambio de sección» y se abre otra en la sección nueva, con la misma fecha.' ); ?></p>
					<p class="asde-form__acciones"><button type="submit" class="button button-primary"><?php asde_e( 'Cambiar de sección en el CRM' ); ?></button></p>
				</form>
			<?php endif; ?>

		<?php else : ?>
			<?php if ( null === $documento ) : ?>
				<div class="asde-notice asde-notice--error" role="alert"><p><?php asde_e( 'No se encuentra esa ficha en el CRM del grupo.' ); ?></p></div>
			<?php elseif ( $documento ) : ?>
				<div class="asde-notice asde-notice--info" role="status"><p><?php asde_e( 'Esta ficha ya tiene un documento. Si está mal, corrígelo en el CRM.' ); ?></p></div>
			<?php else : ?>
				<p class="asde-muted"><?php asde_e( 'Para quien aún no lo tenía (por ejemplo, un menor que lo acaba de sacar). Antes de guardarlo se comprueba si ya está en otra ficha de tu grupo o en otro grupo u organización de Scouts de España.' ); ?></p>
				<?php if ( $estado['confirmar'] ) : ?>
					<div class="asde-notice asde-notice--warning" role="status"><p><?php echo esc_html( $estado['error'] ); ?></p></div>
				<?php endif; ?>
				<form method="post" action="<?php echo esc_url( $url ); ?>" class="asde-form">
					<?php $comun( 'poner_documento' ); ?>
					<p>
						<label for="asde-memb-doc"><?php asde_e( 'DNI, NIE u otro documento' ); ?> <span class="asde-obligatorio" aria-hidden="true">*</span></label>
						<input type="text" id="asde-memb-doc" name="documento" maxlength="30" required autocomplete="off" spellcheck="false" value="<?php echo esc_attr( $v( 'documento' ) ); ?>">
					</p>
					<?php if ( $estado['confirmar'] ) : ?>
						<p>
							<label class="asde-choice">
								<input type="checkbox" name="confirmo" value="1" required>
								<span><?php asde_e( 'Es la misma persona: guardar el documento igualmente.' ); ?></span>
							</label>
						</p>
					<?php endif; ?>
					<p class="asde-form__acciones"><button type="submit" class="button button-primary"><?php asde_e( 'Comprobar y guardar' ); ?></button></p>
				</form>
			<?php endif; ?>
		<?php endif; ?>
	<?php endif; ?>
</section>
