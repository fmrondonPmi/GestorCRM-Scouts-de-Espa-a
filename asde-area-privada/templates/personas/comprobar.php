<?php
/**
 * Personas → «¿Está ya en el CRM?» (doc 34, fase 1). Se abre en la ventana emergente
 * desde Personas; sin JavaScript, en su página. La comprobación se envía por POST y
 * se contesta en la misma petición: el documento no se guarda en ningún sitio.
 *
 * @var array|null $resultado Duplicados::comprobar() + 'escrito' (lo tecleado), o null antes de comprobar
 * @var string     $url       Esta pantalla
 * @var string     $url_ficha
 * @var string     $volver    Personas
 */
use AsdeBalears\AreaPrivada\Crm\Duplicados;
use AsdeBalears\AreaPrivada\Domain\Secciones;

defined( 'ABSPATH' ) || exit;

$r = $resultado;
?>
<p class="asde-back asde-solo-pagina"><a href="<?php echo esc_url( $volver ); ?>"><?php asde_e( '← Personas' ); ?></a></p>
<section class="asde-card asde-comprobar-crm">
	<h2 class="asde-card__title"><?php asde_e( '¿Está ya en el CRM?' ); ?></h2>
	<p class="asde-muted"><?php asde_e( 'Antes de dar de alta a alguien, comprueba con su documento si ya tiene ficha: en tu grupo o en otro grupo u organización de Scouts de España. El documento no se guarda.' ); ?></p>

	<form method="post" action="<?php echo esc_url( $url ); ?>" class="asde-form__fila asde-form__fila--compacta" data-asde-solo-consulta>
		<?php wp_nonce_field( 'asde_comprobar_documento' ); ?>
		<input type="hidden" name="asde_action" value="comprobar_documento">
		<label for="asde-comprobar-doc" class="screen-reader-text"><?php asde_e( 'DNI, NIE u otro documento' ); ?></label>
		<input type="text" id="asde-comprobar-doc" name="documento" maxlength="30" required autocomplete="off" spellcheck="false" value="<?php echo esc_attr( (string) ( $r['escrito'] ?? '' ) ); ?>" placeholder="<?php echo esc_attr( asde_a( 'DNI, NIE u otro documento' ) ); ?>">
		<button type="submit" class="button"><?php asde_e( 'Comprobar' ); ?></button>
	</form>
	<p class="asde-help"><?php /* translators: %d: edad */ echo esc_html( sprintf( asde_t( 'El documento es obligatorio a partir de los %d años. A quien es menor y aún no lo tiene se le comprueba cuando se le pone en su ficha.' ), Duplicados::EDAD_DOCUMENTO ) ); ?></p>

	<?php if ( $r ) : ?>
		<div class="asde-comprobar-crm__resultado" role="status">
			<?php if ( '' !== $r['error'] ) : ?>
				<div class="asde-notice asde-notice--error"><p><?php echo esc_html( $r['error'] ); ?></p></div>
			<?php else : ?>
				<p class="asde-muted"><?php /* translators: %s: documento */ echo esc_html( sprintf( asde_t( 'Documento comprobado: %s' ), $r['documento'] ) ); ?></p>

				<?php if ( $r['en_grupo'] ) : ?>
					<div class="asde-notice asde-notice--info">
						<p><strong><?php echo esc_html( 1 === count( $r['en_grupo'] ) ? asde_t( 'Ya tiene ficha en tu grupo.' ) : asde_t( 'Hay varias fichas en tu grupo con este documento: habría que unificarlas en el CRM.' ) ); ?></strong></p>
						<ul class="asde-comprobar-crm__fichas">
							<?php foreach ( $r['en_grupo'] as $f ) : ?>
								<?php $p = $f['persona']; ?>
								<li>
									<a href="<?php echo esc_url( add_query_arg( 'socio', $p->num_socio_asde, $url_ficha ) ); ?>"><?php echo esc_html( $p->nombre_completo() ); ?></a>
									<span class="asde-muted">· <?php /* translators: %s: nº de socio */ echo esc_html( sprintf( asde_t( 'Nº Scouts de España %s' ), $p->num_socio_asde ) ); ?> ·</span>
									<?php if ( $f['activa'] ) : ?>
										<?php /* translators: %s: secciones */ echo esc_html( sprintf( asde_t( 'membresía activa: %s' ), implode( ', ', array_map( static fn( $s ) => Secciones::nombre( $s ), $p->secciones ) ) ) ); ?>
									<?php else : ?>
										<strong><?php asde_e( 'sin membresía activa' ); ?></strong>
									<?php endif; ?>
								</li>
							<?php endforeach; ?>
						</ul>
						<?php if ( array_filter( array_column( $r['en_grupo'], 'activa' ) ) ) : ?>
							<p><?php asde_e( 'Ya está dada de alta en tu grupo: no hay que crear otra ficha ni otra membresía.' ); ?></p>
						<?php else : ?>
							<p><?php asde_e( 'No hay que crear otra ficha: si vuelve al grupo, solo necesita una membresía nueva en la suya.' ); ?></p>
						<?php endif; ?>
					</div>
				<?php endif; ?>

				<?php if ( null === $r['otros'] ) : ?>
					<div class="asde-notice asde-notice--warning"><p><?php asde_e( 'No se ha podido comprobar si está dada de alta en otros grupos u organizaciones. Puedes seguir igualmente.' ); ?></p></div>
				<?php elseif ( $r['otros'] > 0 ) : ?>
					<div class="asde-notice asde-notice--warning"><p><strong><?php asde_e( 'Esta persona ya está dada de alta en otro grupo u organización de Scouts de España.' ); ?></strong> <?php asde_e( 'Puede estar en varios a la vez: se puede dar de alta también en tu grupo.' ); ?></p></div>
				<?php elseif ( ! $r['en_grupo'] ) : ?>
					<div class="asde-notice asde-notice--success"><p><?php asde_e( 'No hay ninguna ficha con este documento en el CRM de Scouts de España: se puede dar de alta como persona nueva.' ); ?></p></div>
				<?php endif; ?>
			<?php endif; ?>
		</div>
	<?php endif; ?>
</section>
