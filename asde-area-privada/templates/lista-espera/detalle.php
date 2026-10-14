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
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
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
$estados = asde_ta( Inscripciones::ESTADOS );
$medios  = asde_ta( Inscripciones::MEDIOS );
?>
<p class="asde-back"><a href="<?php echo esc_url( $volver ); ?>"><?php asde_e( '← Lista de espera' ); ?></a></p>

<section class="asde-card asde-espera-ficha">
	<h2 class="asde-card__title"><?php echo esc_html( $nombre ); ?></h2>
	<p>
		<strong><?php echo esc_html( $estados[ $f['estado'] ] ?? $f['estado'] ); ?></strong>
		<?php if ( $f['repetida'] ?? false ) : ?>
			<span class="asde-badge asde-badge--estado-invitado"><?php asde_e( 'Repetida' ); ?></span>
		<?php endif; ?>
	</p>
	<?php if ( $sin ) : ?>
		<div class="asde-notice asde-notice--info" role="status"><p><?php echo esc_html( sprintf( asde_t( 'Todavía no ha confirmado su email: no entra en la lista hasta que lo haga. Si no lo confirma en %d días, se borra sola.' ), Inscripciones::DIAS_CONFIRMAR ) ); ?></p></div>
	<?php endif; ?>
	<dl class="asde-datos">
		<dt><?php asde_e( 'Fecha de nacimiento' ); ?></dt>
		<dd><?php echo esc_html( $dma( $d['nacimiento'] ) ); ?><?php echo null !== $f['edad'] ? ' · ' . esc_html( sprintf( asde_t( '%d años' ), $f['edad'] ) ) : ''; ?></dd>
		<dt><?php asde_e( 'Sección por edad' ); ?></dt>
		<dd><?php echo '' !== $f['seccion_edad'] ? Secciones::badge( $f['seccion_edad'] ) : esc_html( asde_t( 'Aún sin edad' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></dd>
		<?php if ( '' !== $f['seccion'] ) : ?>
			<dt><?php asde_e( 'Sección asignada' ); ?></dt>
			<dd><?php echo Secciones::badge( $f['seccion'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></dd>
		<?php endif; ?>
		<dt><?php asde_e( 'Documento' ); ?></dt>
		<dd><?php echo $gestiona ? esc_html( '' !== $d['documento'] ? $d['documento'] : '—' ) : esc_html( '' !== $d['documento'] ? asde_t( 'Sí (lo ven Secretaría y Coordinación)' ) : '—' ); ?></dd>
		<?php if ( '' !== $d['contacto_nombre'] ) : ?>
			<dt><?php asde_e( 'Persona de contacto' ); ?></dt>
			<dd><?php echo esc_html( trim( $d['contacto_nombre'] . ' ' . $d['contacto_apellidos'] ) ); ?></dd>
		<?php endif; ?>
		<dt><?php asde_e( 'Teléfono' ); ?></dt>
		<dd><a href="<?php echo esc_url( 'tel:' . $d['telefono'] ); ?>"><?php echo esc_html( $d['telefono'] ); ?></a></dd>
		<dt><?php asde_e( 'Email' ); ?></dt>
		<dd><?php echo esc_html( $d['email'] ); ?></dd>
		<?php if ( '' !== $d['observaciones'] ) : ?>
			<dt><?php asde_e( 'Observaciones' ); ?></dt>
			<dd><?php echo nl2br( esc_html( $d['observaciones'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></dd>
		<?php endif; ?>
		<dt><?php asde_e( 'Se apuntó' ); ?></dt>
		<dd><?php echo esc_html( $fecha( $f['creada'] ) ); ?><?php echo $f['confirmada_en'] ? ' · ' . esc_html( sprintf( asde_t( 'confirmó el %s' ), $fecha( $f['confirmada_en'] ) ) ) : ''; ?></dd>
		<?php if ( $f['caduca'] ) : ?>
			<dt><?php asde_e( 'Sus datos se borran el' ); ?></dt>
			<dd><?php echo esc_html( $fecha( $f['caduca'] ) ); ?></dd>
		<?php endif; ?>
	</dl>
</section>

<?php if ( ! $sin ) : ?>
	<section class="asde-card">
		<h3><?php asde_e( 'Contactar' ); ?></h3>
		<form method="post" action="<?php echo esc_url( $url ); ?>" class="asde-form">
			<?php $ocultos( 'espera_email' ); ?>
			<p><label for="asde-espera-asunto"><?php asde_e( 'Asunto' ); ?></label><input type="text" id="asde-espera-asunto" name="asunto" value="<?php echo esc_attr( $asunto ); ?>" maxlength="150" required></p>
			<p><label for="asde-espera-msg"><?php asde_e( 'Mensaje' ); ?></label><textarea id="asde-espera-msg" name="mensaje" rows="5" required></textarea></p>
			<p class="asde-help"><?php asde_e( 'Se envía desde la web con el email de contacto del grupo para las respuestas, queda en el historial y la inscripción pasa a «Contactada».' ); ?></p>
			<p><button type="submit" class="button button-primary"><?php asde_e( 'Enviar email' ); ?></button></p>
		</form>
		<form method="post" action="<?php echo esc_url( $url ); ?>" class="asde-form">
			<?php $ocultos( 'espera_contacto' ); ?>
			<h4><?php asde_e( 'Apuntar un contacto hecho por otra vía' ); ?></h4>
			<p>
				<label for="asde-espera-medio"><?php asde_e( 'Cómo' ); ?></label>
				<select id="asde-espera-medio" name="medio">
					<option value="<?php echo esc_attr( Inscripciones::POR_TELEFONO ); ?>"><?php echo esc_html( $medios[ Inscripciones::POR_TELEFONO ] ); ?></option>
					<option value="<?php echo esc_attr( Inscripciones::POR_EMAIL ); ?>"><?php echo esc_html( $medios[ Inscripciones::POR_EMAIL ] ); ?></option>
				</select>
			</p>
			<p><label for="asde-espera-nota"><?php asde_e( 'Nota (opcional)' ); ?></label><textarea id="asde-espera-nota" name="nota" rows="2"></textarea></p>
			<p><button type="submit" class="button"><?php asde_e( 'Apuntar el contacto' ); ?></button></p>
		</form>
	</section>

	<section class="asde-card">
		<h3><?php asde_e( 'Estado' ); ?></h3>
		<form method="post" action="<?php echo esc_url( $url ); ?>" class="asde-form">
			<?php $ocultos( 'espera_estado' ); ?>
			<p>
				<label for="asde-espera-est"><?php asde_e( 'Estado' ); ?></label>
				<select id="asde-espera-est" name="estado">
					<?php foreach ( Inscripciones::ESTADOS_ELEGIBLES as $e ) : ?>
						<option value="<?php echo esc_attr( $e ); ?>" <?php selected( $f['estado'], $e ); ?>><?php echo esc_html( $estados[ $e ] ); ?></option>
					<?php endforeach; ?>
				</select>
			</p>
			<p><label for="asde-espera-enota"><?php asde_e( 'Nota (opcional)' ); ?></label><input type="text" id="asde-espera-enota" name="nota" maxlength="250"></p>
			<p><button type="submit" class="button"><?php asde_e( 'Cambiar el estado' ); ?></button></p>
		</form>
	</section>
<?php endif; ?>

<?php if ( $gestiona ) : ?>
	<section class="asde-card">
		<h3><?php asde_e( 'Sección y alta' ); ?></h3>
		<?php if ( ! $sin ) : ?>
			<form method="post" action="<?php echo esc_url( $url ); ?>" class="asde-form asde-form__fila asde-form__fila--compacta">
				<?php $ocultos( 'espera_seccion' ); ?>
				<label for="asde-espera-sec"><?php asde_e( 'Sección' ); ?></label>
				<select id="asde-espera-sec" name="seccion">
					<option value=""><?php echo esc_html( '' !== $f['seccion_edad'] ? sprintf( asde_t( 'La de su edad (%s)' ), Secciones::nombre( $f['seccion_edad'] ) ) : asde_t( 'La de su edad' ) ); ?></option>
					<?php foreach ( array_keys( Secciones::all() ) as $s ) : ?>
						<option value="<?php echo esc_attr( $s ); ?>" <?php selected( $f['seccion'], $s ); ?>><?php echo esc_html( Secciones::nombre( $s ) ); ?></option>
					<?php endforeach; ?>
				</select>
				<button type="submit" class="button"><?php asde_e( 'Asignar' ); ?></button>
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
					<p><button type="submit" class="button button-primary"><?php asde_e( 'Dar de alta en el CRM' ); ?></button></p>
					<p class="asde-help"><?php asde_e( 'Abre el «Alta de socio/a» con sus datos. Primero se comprueba su documento en el CRM (si ya tiene ficha en el grupo, el alta se para). Al terminar el alta, esta inscripción se borra de la lista.' ); ?></p>
				</form>
			<?php else : ?>
				<p class="asde-help"><?php echo esc_html( $altas_motivo ); ?></p>
			<?php endif; ?>
		<?php endif; ?>
		<form method="post" action="<?php echo esc_url( $url ); ?>" class="asde-form" data-asde-confirmar="<?php echo esc_attr( asde_js( '¿Borrar a %s de la lista de espera? Sus datos se borran y no se puede deshacer.', $nombre ) ); ?>">
			<?php $ocultos( 'espera_quitar' ); ?>
			<p><button type="submit" class="button asde-button-secundario"><?php asde_e( 'Borrar de la lista' ); ?></button></p>
		</form>
	</section>
<?php endif; ?>

<?php if ( $f['historial'] ) : ?>
	<section class="asde-card">
		<h3><?php asde_e( 'Historial' ); ?></h3>
		<ul class="asde-espera-historial">
			<?php foreach ( array_reverse( $f['historial'] ) as $h ) : ?>
				<?php
				$tipo = (string) ( $h['tipo'] ?? '' );
				switch ( $tipo ) {
					case Inscripciones::POR_EMAIL:
						$que = '' !== (string) ( $h['asunto'] ?? '' ) ? sprintf( asde_t( 'Email enviado: «%s»' ), $h['asunto'] ) : asde_t( 'Contacto por email' );
						break;
					case Inscripciones::POR_TELEFONO:
						$que = asde_t( 'Contacto por teléfono' );
						break;
					case 'estado':
						$que = sprintf( asde_t( 'Estado: %s' ), $estados[ (string) ( $h['valor'] ?? '' ) ] ?? (string) ( $h['valor'] ?? '' ) );
						break;
					case 'seccion':
						$que = '' !== (string) ( $h['valor'] ?? '' ) ? sprintf( asde_t( 'Sección asignada: %s' ), Secciones::nombre( (string) $h['valor'] ) ) : asde_t( 'Sección: la de su edad' );
						break;
					default:
						$que = $tipo;
				}
				?>
				<li>
					<span class="asde-muted"><?php echo esc_html( $fecha( (string) ( $h['fecha'] ?? '' ), 'd/m/Y H:i' ) ); ?> · <?php echo esc_html( $autores[ (int) ( $h['user'] ?? 0 ) ] ?? '' ); ?></span><br>
					<strong><?php echo esc_html( $que ); ?></strong>
					<?php if ( '' !== (string) ( $h['nota'] ?? '' ) ) : ?>
						<br><?php echo nl2br( esc_html( (string) $h['nota'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ul>
	</section>
<?php endif; ?>
