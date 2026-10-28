<?php
/**
 * Personas → «Alta de socio/a» (doc 34, fase 3): documento → datos → revisar → hecho.
 * Se abre en la ventana emergente desde Personas; sin JavaScript, en su página. Cada
 * paso va por POST y se contesta en la misma petición: lo escrito viaja en el propio
 * formulario y no se guarda en WordPress (Frontend\AltaSocio).
 *
 * @var array|null $s          Frontend\AltaSocio (null: las altas no están disponibles en el grupo)
 * @var string     $motivo     Por qué no están disponibles
 * @var string     $url        Esta pantalla
 * @var string     $url_ficha
 * @var string     $url_acceso «Dar acceso» (doc 11)
 * @var string     $volver     Personas
 */
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Crm\Altas;
use AsdeBalears\AreaPrivada\Crm\Duplicados;
use AsdeBalears\AreaPrivada\Domain\Secciones;
use AsdeBalears\AreaPrivada\Frontend\AltaSocio;
use AsdeBalears\AreaPrivada\Frontend\Pages;

defined( 'ABSPATH' ) || exit;

$dma   = static function ( string $f ): string {
	$d = DateTime::createFromFormat( '!Y-m-d', $f );
	return $d ? $d->format( 'd/m/Y' ) : $f;
};
$valor = static fn( string $campo ): string => (string) ( $s['datos'][ $campo ] ?? '' );
$hoy   = wp_date( 'Y-m-d' );
$edad  = static function ( string $f ) use ( $hoy ): ?int {
	$d = DateTime::createFromFormat( '!Y-m-d', $f );
	return $d && $d->format( 'Y-m-d' ) === $f ? (int) $d->diff( new DateTime( $hoy ) )->y : null;
};
?>
<p class="asde-back asde-solo-pagina"><a href="<?php echo esc_url( $volver ); ?>"><?php esc_html_e( '← People', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a></p>
<section class="asde-card asde-alta-socio">
	<h2 class="asde-card__title"><?php esc_html_e( 'Member registration', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h2>

	<?php if ( ! $s ) : ?>
		<div class="asde-notice asde-notice--info" role="status"><p><?php echo esc_html( $motivo ); ?></p></div>
	<?php else : ?>
		<?php
		$pasos  = array(
			AltaSocio::DOCUMENTO => __( 'Document', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
			AltaSocio::DATOS     => __( 'Details', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
			AltaSocio::REVISAR   => __( 'Review', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
			AltaSocio::HECHO     => __( 'Done', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
		);
		$actual = (int) array_search( AltaSocio::EN_GRUPO === $s['paso'] ? AltaSocio::DOCUMENTO : $s['paso'], array_keys( $pasos ), true );
		$otros  = $s['otros'];
		$hecho  = static fn( int $i ): bool => $i < $actual || AltaSocio::HECHO === $s['paso']; // Al terminar, todos hechos.
		?>
		<ol class="asde-pasos asde-pasos--alta">
			<?php foreach ( array_values( $pasos ) as $i => $texto ) : ?>
				<li class="<?php echo $hecho( $i ) ? 'is-hecho' : ( $i === $actual ? 'is-actual' : '' ); ?>"<?php echo $i === $actual ? ' aria-current="step"' : ''; ?>>
					<span class="asde-pasos__marca" aria-hidden="true"><?php echo $hecho( $i ) ? '✓' : (int) ( $i + 1 ); ?></span>
					<?php echo esc_html( $texto ); ?>
				</li>
			<?php endforeach; ?>
		</ol>

		<?php if ( '' !== $s['error'] && AltaSocio::EN_GRUPO !== $s['paso'] ) : ?>
			<div class="asde-notice asde-notice--error" role="alert"><p><?php echo esc_html( $s['error'] ); ?></p></div>
		<?php endif; ?>

		<?php if ( ! empty( $s['espera'] ) && AltaSocio::HECHO !== $s['paso'] ) : ?>
			<p class="asde-help"><?php esc_html_e( 'It comes from the waiting list: the details are those the family wrote. Check them and complete what is missing (the gender). When finished, the sign-up becomes "Member" and its data is deleted from the list.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <a href="<?php echo esc_url( Pages::url( 'lista_espera', array( 'inscripcion' => (string) $s['espera'] ) ) ); ?>"><?php esc_html_e( 'View the sign-up', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a></p>
		<?php endif; ?>

		<?php if ( in_array( $s['paso'], array( AltaSocio::DATOS, AltaSocio::REVISAR ), true ) ) : ?>
			<?php if ( null === $otros ) : ?>
				<div class="asde-notice asde-notice--warning" role="status"><p><?php esc_html_e( 'It could not be checked whether they are registered in other groups or organisations. You can continue anyway.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p></div>
			<?php elseif ( $otros > 0 ) : ?>
				<div class="asde-notice asde-notice--warning" role="status"><p><strong><?php esc_html_e( 'This person is already registered in another Scouts de España group or organisation.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></strong> <?php esc_html_e( 'They can be in several at once: they can also be registered in your group.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p></div>
			<?php endif; ?>
		<?php endif; ?>

		<?php if ( AltaSocio::DOCUMENTO === $s['paso'] ) : ?>
			<p class="asde-muted"><?php esc_html_e( 'Start with their document: it is checked whether they already have a record, in your group or in another Scouts de España group or organisation.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
			<form method="post" action="<?php echo esc_url( $url ); ?>" class="asde-form" data-asde-solo-consulta>
				<?php wp_nonce_field( 'asde_alta_socio' ); ?>
				<input type="hidden" name="asde_action" value="alta_documento">
				<?php if ( ! empty( $s['espera'] ) ) : ?>
					<input type="hidden" name="espera" value="<?php echo (int) $s['espera']; ?>">
				<?php endif; ?>
				<p>
					<label for="asde-alta-doc"><?php esc_html_e( 'ID, foreigner ID or other document', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
					<input type="text" id="asde-alta-doc" name="documento" maxlength="30" required autocomplete="off" spellcheck="false" value="<?php echo esc_attr( $s['escrito'] ); ?>">
					<span class="asde-help"><?php /* translators: %d: edad */ echo esc_html( sprintf( /* translators: %d: number */ __( 'The document is required from age %d.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), Duplicados::EDAD_DOCUMENTO ) ); ?></span>
				</p>
				<p class="asde-form__acciones">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Check and continue', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
					<button type="submit" class="button asde-button-secundario" name="sin_documento" value="1" formnovalidate><?php /* translators: %d: edad */ echo esc_html( sprintf( /* translators: %d: number */ __( 'Is under %d years old and has no document', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), Duplicados::EDAD_DOCUMENTO ) ); ?></button>
				</p>
			</form>

		<?php elseif ( AltaSocio::EN_GRUPO === $s['paso'] ) : ?>
			<?php if ( $s['en_grupo'] ) : ?>
				<div class="asde-notice asde-notice--info" role="status">
					<p><strong><?php echo esc_html( 1 === count( $s['en_grupo'] ) ? __( 'Already has a record in your group: another is not created.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) : __( 'There are several records in your group with this document: they would have to be merged in the CRM.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?></strong></p>
					<ul class="asde-comprobar-crm__fichas">
						<?php foreach ( $s['en_grupo'] as $f ) : ?>
							<?php $p = $f['persona']; ?>
							<li>
								<a href="<?php echo esc_url( add_query_arg( 'socio', $p->num_socio_asde, $url_ficha ) ); ?>" data-asde-modal><?php echo esc_html( $p->nombre_completo() ); ?></a>
								<span class="asde-muted">· <?php /* translators: %s: nº de socio */ echo esc_html( sprintf( /* translators: %s: text */ __( 'Scouts de España no. %s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $p->num_socio_asde ) ); ?> ·</span>
								<?php if ( $f['activa'] ) : ?>
									<?php /* translators: %s: secciones */ echo esc_html( sprintf( /* translators: %s: text */ __( 'active membership: %s', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), implode( ', ', array_map( static fn( $sec ) => Secciones::nombre( $sec ), $p->secciones ) ) ) ); ?>
								<?php else : ?>
									<strong><?php esc_html_e( 'no active membership', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></strong> ·
									<a href="<?php echo esc_url( add_query_arg( array( 'socio' => $p->num_socio_asde, 'membresia' => 'nueva' ), $url_ficha ) ); ?>" data-asde-modal><?php esc_html_e( 'Give them a new membership', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a>
								<?php endif; ?>
							</li>
						<?php endforeach; ?>
					</ul>
					<?php if ( array_filter( array_column( $s['en_grupo'], 'activa' ) ) ) : ?>
						<p><?php esc_html_e( 'Already registered in your group: no other record or membership needs to be created.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
					<?php else : ?>
						<p><?php esc_html_e( 'There is no need to create another record: if they return to the group, they only need a new membership on theirs.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
					<?php endif; ?>
				</div>
			<?php else : ?>
				<div class="asde-notice asde-notice--info" role="status"><p><?php echo esc_html( $s['error'] ); ?></p></div>
			<?php endif; ?>
			<p><a href="<?php echo esc_url( $url ); ?>" data-asde-modal><?php esc_html_e( 'Register another person', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a></p>

		<?php elseif ( AltaSocio::DATOS === $s['paso'] ) : ?>
			<?php
			$anios   = $edad( $valor( 'birthdate' ) );
			$tutores = null === $anios || $anios < 18 || '' !== $valor( 'tutor1_nombre_c' ) . $valor( 'tutor2_nombre_c' );
			$et      = array(
				'nombre'     => __( 'Name', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
				'apellidos'  => __( 'Surnames', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
				'nacimiento' => __( 'Date of birth', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
				'email'      => __( 'Email', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
				'movil'      => __( 'Mobile', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
			);
			$campo   = static function ( string $c, string $etiqueta, string $tipo = 'text', bool $obligatorio = false, string $extra = '' ) use ( $valor ): string {
				return sprintf(
					'<p><label for="asde-alta-%1$s">%2$s%3$s</label><input type="%4$s" id="asde-alta-%1$s" name="d[%1$s]" value="%5$s"%6$s%7$s></p>',
					esc_attr( $c ),
					esc_html( $etiqueta ),
					$obligatorio ? ' <span class="asde-obligatorio" aria-hidden="true">*</span>' : '',
					esc_attr( $tipo ),
					esc_attr( $valor( $c ) ),
					$obligatorio ? ' required' : '',
					$extra
				);
			};
			?>
			<form method="post" action="<?php echo esc_url( $url ); ?>" class="asde-form asde-alta-socio__datos" data-asde-solo-consulta>
				<?php wp_nonce_field( 'asde_alta_socio' ); ?>
				<input type="hidden" name="asde_action" value="alta_revisar">
				<?php echo wp_kses( AltaSocio::ocultos( $s, array( 'd' ) ), asde_html_permitido() ); ?>
				<fieldset>
					<legend><?php esc_html_e( 'Person', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></legend>
					<div class="asde-form__columnas">
						<?php
						// phpcs:disable WordPress.Security.EscapeOutput -- escapado en $campo.
						echo wp_kses( $campo( 'first_name', $et['nombre'], 'text', true, ' maxlength="100" autocomplete="off"' ), asde_html_permitido() );
						echo wp_kses( $campo( 'last_name', $et['apellidos'], 'text', true, ' maxlength="100" autocomplete="off"' ), asde_html_permitido() );
						echo wp_kses( $campo( 'birthdate', $et['nacimiento'], 'date', true, ' min="1900-01-01" max="' . esc_attr( $hoy ) . '"' ), asde_html_permitido() );
						// phpcs:enable WordPress.Security.EscapeOutput
						?>
						<p>
							<label for="asde-alta-stic_gender_c"><?php esc_html_e( 'Gender', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <span class="asde-obligatorio" aria-hidden="true">*</span></label>
							<select id="asde-alta-stic_gender_c" name="d[stic_gender_c]" required>
								<option value=""><?php esc_html_e( 'Choose…', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></option>
								<?php foreach ( ( Altas::generos() ) as $g => $etiqueta ) : ?>
									<option value="<?php echo esc_attr( $g ); ?>" <?php selected( $valor( 'stic_gender_c' ), $g ); ?>><?php echo esc_html( $etiqueta ); ?></option>
								<?php endforeach; ?>
							</select>
						</p>
						<p>
							<label for="asde-alta-stic_identification_number_c"><?php esc_html_e( 'ID, foreigner ID or other document', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></label>
							<input type="text" id="asde-alta-stic_identification_number_c" name="d[stic_identification_number_c]" maxlength="30" autocomplete="off" spellcheck="false" value="<?php echo esc_attr( $valor( 'stic_identification_number_c' ) ); ?>">
							<span class="asde-help"><?php /* translators: %d: edad */ echo esc_html( sprintf( /* translators: %d: number */ __( 'Required from age %d.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), Duplicados::EDAD_DOCUMENTO ) ); ?></span>
						</p>
						<?php
						// phpcs:disable WordPress.Security.EscapeOutput -- escapado en $campo.
						echo wp_kses( $campo( 'email1', $et['email'], 'email', false, ' maxlength="100" autocomplete="off"' ), asde_html_permitido() );
						echo wp_kses( $campo( 'phone_mobile', $et['movil'], 'tel', false, ' maxlength="30" autocomplete="off"' ), asde_html_permitido() );
						// phpcs:enable WordPress.Security.EscapeOutput
						?>
					</div>
				</fieldset>
				<details class="asde-plegable asde-alta-socio__tutores"<?php echo $tutores ? ' open' : ''; ?>>
					<summary class="asde-plegable__titulo"><h3><?php esc_html_e( 'Legal guardians (if a minor)', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></h3></summary>
					<p class="asde-help"><?php /* translators: %d: edad */ echo esc_html( sprintf( /* translators: %d: number */ __( 'Under %d years of age, guardian 1 is required: first name, surname and email.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), Altas::EDAD_ADULTA ) ); ?></p>
					<?php foreach ( array( 1, 2 ) as $n ) : ?>
						<fieldset>
							<legend><?php /* translators: %d: 1 o 2 */ echo esc_html( sprintf( /* translators: %d: number */ __( 'Guardian %d', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $n ) ); ?></legend>
							<div class="asde-form__columnas">
								<?php
								// phpcs:disable WordPress.Security.EscapeOutput -- escapado en $campo.
								echo wp_kses( $campo( "tutor{$n}_nombre_c", $et['nombre'], 'text', false, ' maxlength="100" autocomplete="off"' ), asde_html_permitido() );
								echo wp_kses( $campo( "tutor{$n}_apellidos_c", $et['apellidos'], 'text', false, ' maxlength="100" autocomplete="off"' ), asde_html_permitido() );
								echo wp_kses( $campo( "tutor{$n}_email_c", $et['email'], 'email', false, ' maxlength="100" autocomplete="off"' ), asde_html_permitido() );
								echo wp_kses( $campo( "tutor{$n}_movil_c", $et['movil'], 'tel', false, ' maxlength="30" autocomplete="off"' ), asde_html_permitido() );
								// phpcs:enable WordPress.Security.EscapeOutput
								?>
							</div>
						</fieldset>
					<?php endforeach; ?>
				</details>
				<fieldset>
					<legend><?php esc_html_e( 'Membership', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></legend>
					<div class="asde-form__columnas">
						<p>
							<label for="asde-alta-seccion"><?php esc_html_e( 'Section', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <span class="asde-obligatorio" aria-hidden="true">*</span></label>
							<select id="asde-alta-seccion" name="seccion" required>
								<option value=""><?php esc_html_e( 'Choose…', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></option>
								<?php foreach ( Secciones::all() as $slug => $sec ) : ?>
									<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $s['seccion'], $slug ); ?>><?php echo esc_html( $sec['nombre'] . ( '' !== $sec['edad'] ? ' (' . $sec['edad'] . ')' : '' ) ); ?></option>
								<?php endforeach; ?>
							</select>
						</p>
						<p>
							<label for="asde-alta-inicio"><?php esc_html_e( 'Registration date', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?> <span class="asde-obligatorio" aria-hidden="true">*</span></label>
							<input type="date" id="asde-alta-inicio" name="inicio" required max="<?php echo esc_attr( $hoy ); ?>" value="<?php echo esc_attr( $s['inicio'] ); ?>">
						</p>
					</div>
				</fieldset>
				<p class="asde-form__acciones"><button type="submit" class="button button-primary"><?php esc_html_e( 'Review', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button></p>
			</form>

		<?php elseif ( AltaSocio::REVISAR === $s['paso'] ) : ?>
			<?php
			$d      = $s['datos'];
			$anios  = $edad( (string) ( $d['birthdate'] ?? '' ) );
			$tutor  = static function ( int $n ) use ( $d ): string {
				return implode( ' · ', array_filter( array( trim( ( $d[ "tutor{$n}_nombre_c" ] ?? '' ) . ' ' . ( $d[ "tutor{$n}_apellidos_c" ] ?? '' ) ), $d[ "tutor{$n}_email_c" ] ?? '', $d[ "tutor{$n}_movil_c" ] ?? '' ) ) );
			};
			$filas  = array(
				__( 'Name', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' )               => trim( ( $d['first_name'] ?? '' ) . ' ' . ( $d['last_name'] ?? '' ) ),
				__( 'Date of birth', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' )  => isset( $d['birthdate'] ) ? $dma( $d['birthdate'] ) . ( null !== $anios ? ' · ' . sprintf( /* translators: %d: number */ __( '%d years', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $anios ) : '' ) : '',
				__( 'Gender', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' )               => ( Altas::generos() )[ $d['stic_gender_c'] ?? '' ] ?? '',
				__( 'Document', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' )            => $d['stic_identification_number_c'] ?? __( 'No document', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ),
				__( 'Email', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' )                => $d['email1'] ?? '',
				__( 'Mobile', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' )                => $d['phone_mobile'] ?? '',
				sprintf( /* translators: %d: number */ __( 'Guardian %d', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), 1 ) => $tutor( 1 ),
				sprintf( /* translators: %d: number */ __( 'Guardian %d', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), 2 ) => $tutor( 2 ),
				__( 'Section', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' )              => Secciones::nombre( $s['seccion'] ),
				__( 'Registration date', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' )        => $dma( $s['inicio'] ),
			);
			?>
			<p class="asde-muted"><?php esc_html_e( 'Check the details. On confirming, their record and membership are created in the Scouts de España CRM, and the CRM gives them their member no.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
			<dl class="asde-datos asde-alta-socio__resumen">
				<?php foreach ( array_filter( $filas, static fn( $v ) => '' !== $v ) as $dt => $dd ) : ?>
					<div><dt><?php echo esc_html( $dt ); ?></dt><dd><?php echo esc_html( $dd ); ?></dd></div>
				<?php endforeach; ?>
			</dl>
			<form method="post" action="<?php echo esc_url( $url ); ?>" class="asde-form">
				<?php wp_nonce_field( 'asde_alta_socio' ); ?>
				<input type="hidden" name="asde_action" value="alta_crear">
				<?php
				// Par disable/enable y no phpcs:ignore: tras los phpcs:enable de arriba, PHPCS ya no respeta aquí un ignore.
				// phpcs:disable WordPress.Security.EscapeOutput -- escapado en AltaSocio::ocultos().
				echo AltaSocio::ocultos( $s );
				// phpcs:enable WordPress.Security.EscapeOutput
				?>
				<?php if ( 0 !== $otros ) : ?>
					<p>
						<label class="asde-choice">
							<input type="checkbox" name="confirmo" value="1" required>
							<span><?php echo null === $otros ? esc_html( __( 'It could not be checked whether they are in other groups or organisations: I want to register them anyway.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ) : esc_html( __( 'I know they are already registered in another group or organisation and I want to register them in mine as well.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ) ); ?></span>
						</label>
					</p>
				<?php endif; ?>
				<p class="asde-form__acciones">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Register in the CRM', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
					<button type="submit" class="button asde-button-secundario" name="corregir" value="1" formnovalidate><?php esc_html_e( 'Correct the data', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></button>
				</p>
			</form>

		<?php elseif ( AltaSocio::HECHO === $s['paso'] ) : ?>
			<?php
			$r      = $s['resultado'];
			$nombre = trim( ( $s['datos']['first_name'] ?? '' ) . ' ' . ( $s['datos']['last_name'] ?? '' ) );
			?>
			<div class="asde-notice asde-notice--success" role="status">
				<p><strong><?php esc_html_e( 'Registration done in the CRM.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></strong>
				<?php if ( ! empty( $s['espera_asociada'] ) ) : ?>
					<?php esc_html_e( 'On the waiting list it remains as "Member" and their data has been deleted.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?>
				<?php endif; ?>
				<?php /* translators: 1: nombre, 2: sección, 3: fecha */ echo esc_html( sprintf( /* translators: 1: text, 2: text, 3: text */ __( '%1$s is already a member of the group, in %2$s since %3$s.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ), $nombre, Secciones::nombre( $s['seccion'] ), $dma( $s['inicio'] ) ) ); ?></p>
			</div>
			<?php if ( '' !== $r['numero'] ) : ?>
				<dl class="asde-datos">
					<div><dt><?php esc_html_e( 'Scouts de España member no.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></dt><dd><strong class="asde-alta-socio__numero"><?php echo esc_html( $r['numero'] ); ?></strong></dd></div>
					<?php /* v0.31: el nº territorial se genera siempre al dar de alta; se enseña a quien lo tiene visible. */ ?>
					<?php if ( '' !== ( $r['territorial'] ?? '' ) && \AsdeBalears\AreaPrivada\Socios\Numeros::ver_local() ) : ?>
						<div><dt><?php esc_html_e( 'Territorial no.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></dt><dd><strong><?php echo esc_html( $r['territorial'] ); ?></strong></dd></div>
					<?php endif; ?>
				</dl>
			<?php else : ?>
				<div class="asde-notice asde-notice--warning" role="status"><p><?php esc_html_e( 'The CRM has not yet given them a member no. You will see it on their record when it has.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p></div>
			<?php endif; ?>
			<?php if ( $r['reanudada'] ) : ?>
				<p class="asde-help"><?php esc_html_e( 'A registration that had been left unfinished has been completed: no other record has been created.', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></p>
			<?php endif; ?>
			<p class="asde-actions">
				<?php if ( '' !== $r['numero'] ) : ?>
					<a class="button" href="<?php echo esc_url( add_query_arg( 'socio', $r['numero'], $url_ficha ) ); ?>" data-asde-modal><?php esc_html_e( 'View their record', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a>
					<a class="button asde-button-secundario" href="<?php echo esc_url( add_query_arg( 'socio', $r['numero'], $url_acceso ) ); ?>" data-asde-modal><?php esc_html_e( 'Give website access', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a>
				<?php endif; ?>
				<a class="button asde-button-secundario" href="<?php echo esc_url( $url ); ?>" data-asde-modal><?php esc_html_e( 'Register another person', 'asde-scouts-de-espana-gestor-crm-para-grupos-scouts' ); ?></a>
			</p>
		<?php endif; ?>
	<?php endif; ?>
</section>
