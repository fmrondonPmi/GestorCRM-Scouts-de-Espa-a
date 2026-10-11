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
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Plantilla: TemplateLoader::render() la incluye dentro de una función; sus variables son locales.
use AsdeBalears\AreaPrivada\Crm\Altas;
use AsdeBalears\AreaPrivada\Crm\Duplicados;
use AsdeBalears\AreaPrivada\Domain\Secciones;
use AsdeBalears\AreaPrivada\Frontend\AltaSocio;

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
<p class="asde-back asde-solo-pagina"><a href="<?php echo esc_url( $volver ); ?>"><?php asde_e( '← Personas' ); ?></a></p>
<section class="asde-card asde-alta-socio">
	<h2 class="asde-card__title"><?php asde_e( 'Alta de socio/a' ); ?></h2>

	<?php if ( ! $s ) : ?>
		<div class="asde-notice asde-notice--info" role="status"><p><?php echo esc_html( $motivo ); ?></p></div>
	<?php else : ?>
		<?php
		$pasos  = array(
			AltaSocio::DOCUMENTO => asde_t( 'Documento' ),
			AltaSocio::DATOS     => asde_t( 'Datos' ),
			AltaSocio::REVISAR   => asde_t( 'Revisar' ),
			AltaSocio::HECHO     => asde_t( 'Hecho' ),
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

		<?php if ( in_array( $s['paso'], array( AltaSocio::DATOS, AltaSocio::REVISAR ), true ) ) : ?>
			<?php if ( null === $otros ) : ?>
				<div class="asde-notice asde-notice--warning" role="status"><p><?php asde_e( 'No se ha podido comprobar si está dada de alta en otros grupos u organizaciones. Puedes seguir igualmente.' ); ?></p></div>
			<?php elseif ( $otros > 0 ) : ?>
				<div class="asde-notice asde-notice--warning" role="status"><p><strong><?php asde_e( 'Esta persona ya está dada de alta en otro grupo u organización de Scouts de España.' ); ?></strong> <?php asde_e( 'Puede estar en varios a la vez: se puede dar de alta también en tu grupo.' ); ?></p></div>
			<?php endif; ?>
		<?php endif; ?>

		<?php if ( AltaSocio::DOCUMENTO === $s['paso'] ) : ?>
			<p class="asde-muted"><?php asde_e( 'Empieza por su documento: se comprueba si ya tiene ficha, en tu grupo o en otro grupo u organización de Scouts de España.' ); ?></p>
			<form method="post" action="<?php echo esc_url( $url ); ?>" class="asde-form" data-asde-solo-consulta>
				<?php wp_nonce_field( 'asde_alta_socio' ); ?>
				<input type="hidden" name="asde_action" value="alta_documento">
				<p>
					<label for="asde-alta-doc"><?php asde_e( 'DNI, NIE u otro documento' ); ?></label>
					<input type="text" id="asde-alta-doc" name="documento" maxlength="30" required autocomplete="off" spellcheck="false" value="<?php echo esc_attr( $s['escrito'] ); ?>">
					<span class="asde-help"><?php /* translators: %d: edad */ echo esc_html( sprintf( asde_t( 'El documento es obligatorio a partir de los %d años.' ), Duplicados::EDAD_DOCUMENTO ) ); ?></span>
				</p>
				<p class="asde-form__acciones">
					<button type="submit" class="button button-primary"><?php asde_e( 'Comprobar y seguir' ); ?></button>
					<button type="submit" class="button asde-button-secundario" name="sin_documento" value="1" formnovalidate><?php /* translators: %d: edad */ echo esc_html( sprintf( asde_t( 'Es menor de %d años y no tiene documento' ), Duplicados::EDAD_DOCUMENTO ) ); ?></button>
				</p>
			</form>

		<?php elseif ( AltaSocio::EN_GRUPO === $s['paso'] ) : ?>
			<?php if ( $s['en_grupo'] ) : ?>
				<div class="asde-notice asde-notice--info" role="status">
					<p><strong><?php echo esc_html( 1 === count( $s['en_grupo'] ) ? asde_t( 'Ya tiene ficha en tu grupo: no se crea otra.' ) : asde_t( 'Hay varias fichas en tu grupo con este documento: habría que unificarlas en el CRM.' ) ); ?></strong></p>
					<ul class="asde-comprobar-crm__fichas">
						<?php foreach ( $s['en_grupo'] as $f ) : ?>
							<?php $p = $f['persona']; ?>
							<li>
								<a href="<?php echo esc_url( add_query_arg( 'socio', $p->num_socio_asde, $url_ficha ) ); ?>" data-asde-modal><?php echo esc_html( $p->nombre_completo() ); ?></a>
								<span class="asde-muted">· <?php /* translators: %s: nº de socio */ echo esc_html( sprintf( asde_t( 'Nº Scouts de España %s' ), $p->num_socio_asde ) ); ?> ·</span>
								<?php if ( $f['activa'] ) : ?>
									<?php /* translators: %s: secciones */ echo esc_html( sprintf( asde_t( 'membresía activa: %s' ), implode( ', ', array_map( static fn( $sec ) => Secciones::nombre( $sec ), $p->secciones ) ) ) ); ?>
								<?php else : ?>
									<strong><?php asde_e( 'sin membresía activa' ); ?></strong> ·
									<a href="<?php echo esc_url( add_query_arg( array( 'socio' => $p->num_socio_asde, 'membresia' => 'nueva' ), $url_ficha ) ); ?>" data-asde-modal><?php asde_e( 'Darle una membresía nueva' ); ?></a>
								<?php endif; ?>
							</li>
						<?php endforeach; ?>
					</ul>
					<?php if ( array_filter( array_column( $s['en_grupo'], 'activa' ) ) ) : ?>
						<p><?php asde_e( 'Ya está dada de alta en tu grupo: no hay que crear otra ficha ni otra membresía.' ); ?></p>
					<?php else : ?>
						<p><?php asde_e( 'No hay que crear otra ficha: si vuelve al grupo, solo necesita una membresía nueva en la suya.' ); ?></p>
					<?php endif; ?>
				</div>
			<?php else : ?>
				<div class="asde-notice asde-notice--info" role="status"><p><?php echo esc_html( $s['error'] ); ?></p></div>
			<?php endif; ?>
			<p><a href="<?php echo esc_url( $url ); ?>" data-asde-modal><?php asde_e( 'Dar de alta a otra persona' ); ?></a></p>

		<?php elseif ( AltaSocio::DATOS === $s['paso'] ) : ?>
			<?php
			$anios   = $edad( $valor( 'birthdate' ) );
			$tutores = null === $anios || $anios < 18 || '' !== $valor( 'tutor1_nombre_c' ) . $valor( 'tutor2_nombre_c' );
			$et      = array(
				'nombre'     => asde_t( 'Nombre' ),
				'apellidos'  => asde_t( 'Apellidos' ),
				'nacimiento' => asde_t( 'Fecha de nacimiento' ),
				'email'      => asde_t( 'Email' ),
				'movil'      => asde_t( 'Móvil' ),
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
				<?php echo AltaSocio::ocultos( $s, array( 'd' ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- escapado en AltaSocio::ocultos(). ?>
				<fieldset>
					<legend><?php asde_e( 'Persona' ); ?></legend>
					<div class="asde-form__columnas">
						<?php
						// phpcs:disable WordPress.Security.EscapeOutput -- escapado en $campo.
						echo $campo( 'first_name', $et['nombre'], 'text', true, ' maxlength="100" autocomplete="off"' );
						echo $campo( 'last_name', $et['apellidos'], 'text', true, ' maxlength="100" autocomplete="off"' );
						echo $campo( 'birthdate', $et['nacimiento'], 'date', true, ' min="1900-01-01" max="' . esc_attr( $hoy ) . '"' );
						// phpcs:enable WordPress.Security.EscapeOutput
						?>
						<p>
							<label for="asde-alta-stic_gender_c"><?php asde_e( 'Género' ); ?> <span class="asde-obligatorio" aria-hidden="true">*</span></label>
							<select id="asde-alta-stic_gender_c" name="d[stic_gender_c]" required>
								<option value=""><?php asde_e( 'Elige…' ); ?></option>
								<?php foreach ( asde_ta( Altas::GENEROS ) as $g => $etiqueta ) : ?>
									<option value="<?php echo esc_attr( $g ); ?>" <?php selected( $valor( 'stic_gender_c' ), $g ); ?>><?php echo esc_html( $etiqueta ); ?></option>
								<?php endforeach; ?>
							</select>
						</p>
						<p>
							<label for="asde-alta-stic_identification_number_c"><?php asde_e( 'DNI, NIE u otro documento' ); ?></label>
							<input type="text" id="asde-alta-stic_identification_number_c" name="d[stic_identification_number_c]" maxlength="30" autocomplete="off" spellcheck="false" value="<?php echo esc_attr( $valor( 'stic_identification_number_c' ) ); ?>">
							<span class="asde-help"><?php /* translators: %d: edad */ echo esc_html( sprintf( asde_t( 'Obligatorio a partir de los %d años.' ), Duplicados::EDAD_DOCUMENTO ) ); ?></span>
						</p>
						<?php
						// phpcs:disable WordPress.Security.EscapeOutput -- escapado en $campo.
						echo $campo( 'email1', $et['email'], 'email', false, ' maxlength="100" autocomplete="off"' );
						echo $campo( 'phone_mobile', $et['movil'], 'tel', false, ' maxlength="30" autocomplete="off"' );
						// phpcs:enable WordPress.Security.EscapeOutput
						?>
					</div>
				</fieldset>
				<details class="asde-plegable asde-alta-socio__tutores"<?php echo $tutores ? ' open' : ''; ?>>
					<summary class="asde-plegable__titulo"><h3><?php asde_e( 'Tutores legales (si es menor de edad)' ); ?></h3></summary>
					<p class="asde-help"><?php /* translators: %d: edad */ echo esc_html( sprintf( asde_t( 'Por debajo de %d años hace falta el tutor/a 1: nombre, apellidos y email.' ), Altas::EDAD_ADULTA ) ); ?></p>
					<?php foreach ( array( 1, 2 ) as $n ) : ?>
						<fieldset>
							<legend><?php /* translators: %d: 1 o 2 */ echo esc_html( sprintf( asde_t( 'Tutor/a %d' ), $n ) ); ?></legend>
							<div class="asde-form__columnas">
								<?php
								// phpcs:disable WordPress.Security.EscapeOutput -- escapado en $campo.
								echo $campo( "tutor{$n}_nombre_c", $et['nombre'], 'text', false, ' maxlength="100" autocomplete="off"' );
								echo $campo( "tutor{$n}_apellidos_c", $et['apellidos'], 'text', false, ' maxlength="100" autocomplete="off"' );
								echo $campo( "tutor{$n}_email_c", $et['email'], 'email', false, ' maxlength="100" autocomplete="off"' );
								echo $campo( "tutor{$n}_movil_c", $et['movil'], 'tel', false, ' maxlength="30" autocomplete="off"' );
								// phpcs:enable WordPress.Security.EscapeOutput
								?>
							</div>
						</fieldset>
					<?php endforeach; ?>
				</details>
				<fieldset>
					<legend><?php asde_e( 'Membresía' ); ?></legend>
					<div class="asde-form__columnas">
						<p>
							<label for="asde-alta-seccion"><?php asde_e( 'Sección' ); ?> <span class="asde-obligatorio" aria-hidden="true">*</span></label>
							<select id="asde-alta-seccion" name="seccion" required>
								<option value=""><?php asde_e( 'Elige…' ); ?></option>
								<?php foreach ( Secciones::all() as $slug => $sec ) : ?>
									<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $s['seccion'], $slug ); ?>><?php echo esc_html( $sec['nombre'] . ( '' !== $sec['edad'] ? ' (' . $sec['edad'] . ')' : '' ) ); ?></option>
								<?php endforeach; ?>
							</select>
						</p>
						<p>
							<label for="asde-alta-inicio"><?php asde_e( 'Fecha de alta' ); ?> <span class="asde-obligatorio" aria-hidden="true">*</span></label>
							<input type="date" id="asde-alta-inicio" name="inicio" required max="<?php echo esc_attr( $hoy ); ?>" value="<?php echo esc_attr( $s['inicio'] ); ?>">
						</p>
					</div>
				</fieldset>
				<p class="asde-form__acciones"><button type="submit" class="button button-primary"><?php asde_e( 'Revisar' ); ?></button></p>
			</form>

		<?php elseif ( AltaSocio::REVISAR === $s['paso'] ) : ?>
			<?php
			$d      = $s['datos'];
			$anios  = $edad( (string) ( $d['birthdate'] ?? '' ) );
			$tutor  = static function ( int $n ) use ( $d ): string {
				return implode( ' · ', array_filter( array( trim( ( $d[ "tutor{$n}_nombre_c" ] ?? '' ) . ' ' . ( $d[ "tutor{$n}_apellidos_c" ] ?? '' ) ), $d[ "tutor{$n}_email_c" ] ?? '', $d[ "tutor{$n}_movil_c" ] ?? '' ) ) );
			};
			$filas  = array(
				asde_t( 'Nombre' )               => trim( ( $d['first_name'] ?? '' ) . ' ' . ( $d['last_name'] ?? '' ) ),
				asde_t( 'Fecha de nacimiento' )  => isset( $d['birthdate'] ) ? $dma( $d['birthdate'] ) . ( null !== $anios ? ' · ' . sprintf( asde_t( '%d años' ), $anios ) : '' ) : '',
				asde_t( 'Género' )               => asde_ta( Altas::GENEROS )[ $d['stic_gender_c'] ?? '' ] ?? '',
				asde_t( 'Documento' )            => $d['stic_identification_number_c'] ?? asde_t( 'Sin documento' ),
				asde_t( 'Email' )                => $d['email1'] ?? '',
				asde_t( 'Móvil' )                => $d['phone_mobile'] ?? '',
				sprintf( asde_t( 'Tutor/a %d' ), 1 ) => $tutor( 1 ),
				sprintf( asde_t( 'Tutor/a %d' ), 2 ) => $tutor( 2 ),
				asde_t( 'Sección' )              => Secciones::nombre( $s['seccion'] ),
				asde_t( 'Fecha de alta' )        => $dma( $s['inicio'] ),
			);
			?>
			<p class="asde-muted"><?php asde_e( 'Revisa los datos. Al confirmar se crean su ficha y su membresía en el CRM de Scouts de España, y el CRM le da su nº de socio/a.' ); ?></p>
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
							<span><?php echo null === $otros ? esc_html( asde_t( 'No se ha podido comprobar si está en otros grupos u organizaciones: quiero darla de alta igualmente.' ) ) : esc_html( asde_t( 'Sé que ya está dada de alta en otro grupo u organización y quiero darla de alta también en el mío.' ) ); ?></span>
						</label>
					</p>
				<?php endif; ?>
				<p class="asde-form__acciones">
					<button type="submit" class="button button-primary"><?php asde_e( 'Dar de alta en el CRM' ); ?></button>
					<button type="submit" class="button asde-button-secundario" name="corregir" value="1" formnovalidate><?php asde_e( 'Corregir los datos' ); ?></button>
				</p>
			</form>

		<?php elseif ( AltaSocio::HECHO === $s['paso'] ) : ?>
			<?php
			$r      = $s['resultado'];
			$nombre = trim( ( $s['datos']['first_name'] ?? '' ) . ' ' . ( $s['datos']['last_name'] ?? '' ) );
			?>
			<div class="asde-notice asde-notice--success" role="status">
				<p><strong><?php asde_e( 'Alta hecha en el CRM.' ); ?></strong>
				<?php /* translators: 1: nombre, 2: sección, 3: fecha */ echo esc_html( sprintf( asde_t( '%1$s ya es socio/a del grupo, en %2$s desde el %3$s.' ), $nombre, Secciones::nombre( $s['seccion'] ), $dma( $s['inicio'] ) ) ); ?></p>
			</div>
			<?php if ( '' !== $r['numero'] ) : ?>
				<dl class="asde-datos">
					<div><dt><?php asde_e( 'Nº socio/a Scouts de España' ); ?></dt><dd><strong class="asde-alta-socio__numero"><?php echo esc_html( $r['numero'] ); ?></strong></dd></div>
					<?php /* v0.31: el nº territorial se genera siempre al dar de alta; se enseña a quien lo tiene visible. */ ?>
					<?php if ( '' !== ( $r['territorial'] ?? '' ) && \AsdeBalears\AreaPrivada\Socios\Numeros::ver_local() ) : ?>
						<div><dt><?php asde_e( 'Nº territorial' ); ?></dt><dd><strong><?php echo esc_html( $r['territorial'] ); ?></strong></dd></div>
					<?php endif; ?>
				</dl>
			<?php else : ?>
				<div class="asde-notice asde-notice--warning" role="status"><p><?php asde_e( 'El CRM todavía no le ha puesto el nº de socio/a. Lo verás en su ficha cuando lo tenga.' ); ?></p></div>
			<?php endif; ?>
			<?php if ( $r['reanudada'] ) : ?>
				<p class="asde-help"><?php asde_e( 'Se ha terminado un alta que se había quedado a medias: no se ha creado otra ficha.' ); ?></p>
			<?php endif; ?>
			<p class="asde-actions">
				<?php if ( '' !== $r['numero'] ) : ?>
					<a class="button" href="<?php echo esc_url( add_query_arg( 'socio', $r['numero'], $url_ficha ) ); ?>" data-asde-modal><?php asde_e( 'Ver su ficha' ); ?></a>
					<a class="button asde-button-secundario" href="<?php echo esc_url( add_query_arg( 'socio', $r['numero'], $url_acceso ) ); ?>" data-asde-modal><?php asde_e( 'Dar acceso a la web' ); ?></a>
				<?php endif; ?>
				<a class="button asde-button-secundario" href="<?php echo esc_url( $url ); ?>" data-asde-modal><?php asde_e( 'Dar de alta a otra persona' ); ?></a>
			</p>
		<?php endif; ?>
	<?php endif; ?>
</section>
