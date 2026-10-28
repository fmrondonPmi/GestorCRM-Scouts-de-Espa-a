<?php
/**
 * Lista de espera → una inscripción (v0.32, doc 39): sus datos, contactar (email desde la web o
 * apuntar una llamada), estado, historial y, para Secretaría y Coordinación, sección, alta en el
 * CRM y borrar.
 *
 * @var array                $f            Inscripción (ListaEspera\Inscripciones::obtener)
 * @var array<int, string>   $autores      id de usuario => nombre (historial)
 * @var bool                 $gestiona     Secretaría o Coordinación (sección, alta, borrar, ver el documento)
 * @var bool                 $altas        Puede dar de alta en el CRM desde aquí
 * @var string               $altas_motivo Por qué no (si gestiona y no puede)
 * @var string               $asunto       Asunto propuesto para el email
 * @var string               $url
 * @var string               $volver
 * @var string               $url_alta     Personas → Alta de socio/a
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Domain\Secciones;
use AsdeBalears\AreaPrivada\ListaEspera\Inscripciones;

defined( 'ABSPATH' ) || exit;

$d       = $f['datos'];
$nombre  = trim( $d['nombre'] . ' ' . $d['apellidos'] );
$sin     = Inscripciones::SIN_CONFIRMAR === $f['estado'];
$nonce   = 'asde_espera_' . $f['id'];
$fecha   = static function ( ?string $utc, string $formato = 'd/m/Y' ): string {
	$t = $utc ? strtotime( $utc . ' UTC' ) : false;
	return $t ? wp_date( $formato, $t ) : '—';
};
$dma     = static function ( string $x ): string {
	$o = DateTime::createFromFormat( '!Y-m-d', $x );
	return $o ? $o->format( 'd/m/Y' ) : $x;
};
$ocultos = static function ( string $accion ) use ( $f, $nonce ): void {
	wp_nonce_field( $nonce );
	echo '<input type="hidden" name="asde_action" value="' . esc_attr( $accion ) . '"><input type="hidden" name="inscripcion" value="' . (int) $f['id'] . '">';
};
$estados = ( Inscripciones::estados() );
$medios  = ( Inscripciones::medios() );
?>
<p class="asde-back"><a href="<?php echo esc_url( $volver ); ?>"><?php esc_html_e( '← Waiting list', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a></p>
<?php if ( Inscripciones::ASOCIADA === $f['estado'] ) : // Doc 59, RC-NUE-1: ya no tiene datos; solo su nº de socio/a y la fecha. ?>
	<section class="asde-card asde-espera-ficha">
		<h2 class="asde-card__title"><?php echo esc_html( sprintf( /* translators: %s: text */ __( 'Member no. %s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), '' !== $f['socio'] ? $f['socio'] : '—' ) ); ?></h2>
		<p><?php echo esc_html( sprintf( /* translators: %s: text */ __( 'Joined as a member on %s. Their waiting list data has been deleted.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $fecha( $f['asociada_en'] ) ) ); ?></p>
	</section>
	<?php
	return;
endif;
?>

<section class="asde-card asde-espera-ficha">
	<h2 class="asde-card__title"><?php echo esc_html( $nombre ); ?></h2>
	<p>
		<strong><?php echo esc_html( $estados[ $f['estado'] ] ?? $f['estado'] ); ?></strong>
		<?php if ( $f['repetida'] ?? false ) : ?>
			<span class="asde-badge asde-badge--estado-invitado"><?php esc_html_e( 'Repeated', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></span>
		<?php endif; ?>
	</p>
	<?php if ( $sin ) : ?>
		<div class="asde-notice asde-notice--info" role="status"><p><?php echo esc_html( sprintf( /* translators: %d: number */ __( 'They have not confirmed their email yet: they do not go on the list until they do. If they don\'t confirm within %d days, it is deleted by itself.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), Inscripciones::DIAS_CONFIRMAR ) ); ?></p></div>
	<?php endif; ?>
	<dl class="asde-datos">
		<dt><?php esc_html_e( 'Date of birth', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></dt>
		<dd><?php echo esc_html( $dma( $d['nacimiento'] ) ); ?><?php echo null !== $f['edad'] ? ' · ' . esc_html( sprintf( /* translators: %d: number */ __( '%d years', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $f['edad'] ) ) : ''; ?></dd>
		<dt><?php esc_html_e( 'Section by age', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></dt>
		<dd><?php echo wp_kses( '' !== $f['seccion_edad'] ? Secciones::badge( $f['seccion_edad'] ) : esc_html( __( 'No age yet', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ), asde_html_permitido() ); ?></dd>
		<?php if ( '' !== $f['seccion'] ) : ?>
			<dt><?php esc_html_e( 'Assigned section', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></dt>
			<dd><?php echo wp_kses( Secciones::badge( $f['seccion'] ), asde_html_permitido() ); ?></dd>
		<?php endif; ?>
		<dt><?php esc_html_e( 'Document', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></dt>
		<dd><?php echo $gestiona ? esc_html( '' !== $d['documento'] ? $d['documento'] : '—' ) : esc_html( '' !== $d['documento'] ? __( 'Yes (the Secretary\'s office and Coordination see it)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) : '—' ); ?></dd>
		<?php if ( '' !== $d['contacto_nombre'] ) : ?>
			<dt><?php esc_html_e( 'Contact person', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></dt>
			<dd><?php echo esc_html( trim( $d['contacto_nombre'] . ' ' . $d['contacto_apellidos'] ) ); ?></dd>
		<?php endif; ?>
		<dt><?php esc_html_e( 'Phone', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></dt>
		<dd><a href="<?php echo esc_url( 'tel:' . $d['telefono'] ); ?>"><?php echo esc_html( $d['telefono'] ); ?></a></dd>
		<dt><?php esc_html_e( 'Email', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></dt>
		<dd><?php echo esc_html( $d['email'] ); ?></dd>
		<?php if ( '' !== $d['observaciones'] ) : ?>
			<dt><?php esc_html_e( 'Notes', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></dt>
			<dd><?php echo wp_kses( nl2br( esc_html( $d['observaciones'] ) ), asde_html_permitido() ); ?></dd>
		<?php endif; ?>
		<?php foreach ( $f['extra'] as $x ) : ?>
			<dt><?php echo esc_html( $x['etiqueta'] ); ?></dt>
			<dd><?php echo wp_kses( nl2br( esc_html( $x['valor'] ) ), asde_html_permitido() ); ?></dd>
		<?php endforeach; ?>
		<dt><?php esc_html_e( 'Signed up', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></dt>
		<dd><?php echo esc_html( $fecha( $f['creada'] ) ); ?><?php echo $f['confirmada_en'] ? ' · ' . esc_html( sprintf( /* translators: %s: text */ __( 'confirmed on %s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $fecha( $f['confirmada_en'] ) ) ) : ''; ?></dd>
		<?php if ( $f['caduca'] ) : ?>
			<dt><?php esc_html_e( 'Their data is deleted on', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></dt>
			<dd><?php echo esc_html( $fecha( $f['caduca'] ) ); ?></dd>
		<?php endif; ?>
	</dl>
</section>

<?php if ( ! $sin ) : ?>
	<section class="asde-card">
		<h3><?php esc_html_e( 'Get in touch', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h3>
		<form method="post" action="<?php echo esc_url( $url ); ?>" class="asde-form">
			<?php $ocultos( 'espera_email' ); ?>
			<p><label for="asde-espera-asunto"><?php esc_html_e( 'Subject', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label><input type="text" id="asde-espera-asunto" name="asunto" value="<?php echo esc_attr( $asunto ); ?>" maxlength="150" required></p>
			<p><label for="asde-espera-msg"><?php esc_html_e( 'Message', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label><textarea id="asde-espera-msg" name="mensaje" rows="5" required></textarea></p>
			<p class="asde-help"><?php esc_html_e( 'It is sent from the website with the group\'s contact email for replies, it is kept in the history and the sign-up becomes "Contacted".', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
			<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Send email', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button></p>
		</form>
		<form method="post" action="<?php echo esc_url( $url ); ?>" class="asde-form">
			<?php $ocultos( 'espera_contacto' ); ?>
			<h4><?php esc_html_e( 'Record a contact made by another means', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h4>
			<p class="asde-form__fila asde-form__fila--una-linea">
				<label for="asde-espera-medio"><?php esc_html_e( 'How', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?>
					<select id="asde-espera-medio" name="medio">
						<option value="<?php echo esc_attr( Inscripciones::POR_TELEFONO ); ?>"><?php echo esc_html( $medios[ Inscripciones::POR_TELEFONO ] ); ?></option>
						<option value="<?php echo esc_attr( Inscripciones::POR_EMAIL ); ?>"><?php echo esc_html( $medios[ Inscripciones::POR_EMAIL ] ); ?></option>
					</select>
				</label>
				<label for="asde-espera-nota"><?php esc_html_e( 'Note (optional)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <input type="text" id="asde-espera-nota" name="nota" maxlength="250"></label>
				<button type="submit" class="button"><?php esc_html_e( 'Record the contact', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
			</p>
		</form>
	</section>

	<section class="asde-card">
		<h3><?php esc_html_e( 'Status', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h3>
		<form method="post" action="<?php echo esc_url( $url ); ?>" class="asde-form">
			<?php $ocultos( 'espera_estado' ); ?>
			<p class="asde-form__fila asde-form__fila--una-linea">
				<label for="asde-espera-est"><?php esc_html_e( 'Status', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?>
					<select id="asde-espera-est" name="estado">
					<?php foreach ( Inscripciones::ESTADOS_ELEGIBLES as $e ) : ?>
						<option value="<?php echo esc_attr( $e ); ?>" <?php selected( $f['estado'], $e ); ?>><?php echo esc_html( $estados[ $e ] ); ?></option>
					<?php endforeach; ?>
				</select>
				</label>
				<label for="asde-espera-enota"><?php esc_html_e( 'Note (optional)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <input type="text" id="asde-espera-enota" name="nota" maxlength="250"></label>
				<button type="submit" class="button"><?php esc_html_e( 'Change the status', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
			</p>
		</form>
	</section>
<?php endif; ?>

<?php if ( $gestiona ) : ?>
	<section class="asde-card">
		<h3><?php esc_html_e( 'Section and registration', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h3>
		<?php if ( ! $sin ) : ?>
			<form method="post" action="<?php echo esc_url( $url ); ?>" class="asde-form asde-form__fila asde-form__fila--compacta">
				<?php $ocultos( 'espera_seccion' ); ?>
				<label for="asde-espera-sec"><?php esc_html_e( 'Section', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
				<select id="asde-espera-sec" name="seccion">
					<option value=""><?php echo esc_html( '' !== $f['seccion_edad'] ? sprintf( /* translators: %s: text */ __( 'The one for their age (%s)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), Secciones::nombre( $f['seccion_edad'] ) ) : __( 'The one for their age', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?></option>
					<?php foreach ( array_keys( Secciones::all() ) as $s ) : ?>
						<option value="<?php echo esc_attr( $s ); ?>" <?php selected( $f['seccion'], $s ); ?>><?php echo esc_html( Secciones::nombre( $s ) ); ?></option>
					<?php endforeach; ?>
				</select>
				<button type="submit" class="button"><?php esc_html_e( 'Assign', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
			</form>
			<?php if ( $altas ) : ?>
				<form method="post" action="<?php echo esc_url( $url_alta ); ?>" class="asde-form">
					<?php wp_nonce_field( 'asde_alta_socio' ); ?>
					<input type="hidden" name="asde_action" value="alta_documento">
					<input type="hidden" name="espera" value="<?php echo (int) $f['id']; ?>">
					<?php if ( '' !== $d['documento'] ) : ?>
						<input type="hidden" name="documento" value="<?php echo esc_attr( $d['documento'] ); ?>">
					<?php else : ?>
						<input type="hidden" name="sin_documento" value="1">
					<?php endif; ?>
					<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Joins as a member', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button></p>
					<p class="asde-help"><?php esc_html_e( 'Opens the "Member registration" with all their details. If they have a document, it is first checked in the CRM (if they already have a record in the group, the registration stops). When the registration is finished, the sign-up becomes "Member" with their member no. and their details are deleted.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
				</form>
			<?php else : ?>
				<p class="asde-help"><?php echo esc_html( $altas_motivo ); ?></p>
			<?php endif; ?>
		<?php endif; ?>
		<form method="post" action="<?php echo esc_url( $url ); ?>" class="asde-form" data-asde-confirmar="<?php echo esc_attr( sprintf( /* translators: %s: text */ __( 'Delete %s from the waiting list? Their data is deleted and this cannot be undone.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $nombre ) ); ?>">
			<?php $ocultos( 'espera_quitar' ); ?>
			<p><button type="submit" class="button asde-button-secundario"><?php esc_html_e( 'Delete from the list', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button></p>
		</form>
	</section>
<?php endif; ?>

<?php if ( $f['historial'] ) : ?>
	<section class="asde-card">
		<h3><?php esc_html_e( 'History', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h3>
		<ul class="asde-espera-historial">
			<?php foreach ( array_reverse( $f['historial'] ) as $h ) : ?>
				<?php
				$tipo = (string) ( $h['tipo'] ?? '' );
				switch ( $tipo ) {
					case Inscripciones::POR_EMAIL:
						$que = '' !== (string) ( $h['asunto'] ?? '' ) ? sprintf( /* translators: %s: text */ __( 'Email sent: "%s"', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $h['asunto'] ) : __( 'Contact by email', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' );
						break;
					case Inscripciones::POR_TELEFONO:
						$que = __( 'Contact by phone', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' );
						break;
					case 'estado':
						$que = sprintf( /* translators: %s: text */ __( 'Status: %s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $estados[ (string) ( $h['valor'] ?? '' ) ] ?? (string) ( $h['valor'] ?? '' ) );
						break;
					case 'seccion':
						$que = '' !== (string) ( $h['valor'] ?? '' ) ? sprintf( /* translators: %s: text */ __( 'Assigned section: %s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), Secciones::nombre( (string) $h['valor'] ) ) : __( 'Section: the one for their age', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' );
						break;
					default:
						$que = $tipo;
				}
				?>
				<li>
					<span class="asde-muted"><?php echo esc_html( $fecha( (string) ( $h['fecha'] ?? '' ), 'd/m/Y H:i' ) ); ?> · <?php echo esc_html( $autores[ (int) ( $h['user'] ?? 0 ) ] ?? '' ); ?></span><br>
					<strong><?php echo esc_html( $que ); ?></strong>
					<?php if ( '' !== (string) ( $h['nota'] ?? '' ) ) : ?>
						<br><?php echo wp_kses( nl2br( esc_html( (string) $h['nota'] ) ), asde_html_permitido() ); ?>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ul>
	</section>
<?php endif; ?>
