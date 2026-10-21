<?php
namespace AsdeBalears\AreaPrivada\Frontend\Views;

use AsdeBalears\AreaPrivada\Auth\Ambito;
use AsdeBalears\AreaPrivada\Crm\Altas;
use AsdeBalears\AreaPrivada\Crm\CrmException;
use AsdeBalears\AreaPrivada\Crm\Escritura;
use AsdeBalears\AreaPrivada\Crm\PersonaRepository;
use AsdeBalears\AreaPrivada\Sepa\CuentaCrm;
use AsdeBalears\AreaPrivada\Domain\Persona;
use AsdeBalears\AreaPrivada\Domain\Secciones;
use AsdeBalears\AreaPrivada\Fichas\Cambios;
use AsdeBalears\AreaPrivada\Fichas\FichaService;
use AsdeBalears\AreaPrivada\Fichas\PermisosFicha;
use AsdeBalears\AreaPrivada\Frontend\Notices;
use AsdeBalears\AreaPrivada\Socios\Numeracion;
use AsdeBalears\AreaPrivada\Tesoreria\FamiliasTable;
use AsdeBalears\AreaPrivada\Usuarios\AvisoBaja;
use AsdeBalears\AreaPrivada\Usuarios\Usuarios;

defined( 'ABSPATH' ) || exit;

/**
 * Ficha de una persona con todos los datos que el usuario puede ver. Lo que
 * puede cambiar aparece editable (directo al CRM, o como propuesta si es una
 * cuenta familiar); el resto, de solo lectura.
 *
 * ?socio=NNN (nº Scouts de España). Sin él: la ficha propia o la del primer
 * menor de la cuenta familiar.
 *
 * v0.23 (doc 34, fase 3): con las altas en el CRM activadas, Secretaría y
 * Coordinación dan desde aquí una membresía nueva, la baja, el cambio de sección y el
 * documento a quien no lo tiene (?membresia=nueva|baja|seccion|documento).
 */
final class FichaView extends View {

	/** Formularios de la membresía en el CRM (?membresia=…). */
	private const MEMBRESIA = array( 'nueva', 'baja', 'seccion', 'documento' );

	/** asde_action => formulario. */
	private const ACCIONES_MEMBRESIA = array(
		'membresia_nueva'   => 'nueva',
		'membresia_baja'    => 'baja',
		'membresia_seccion' => 'seccion',
		'poner_documento'   => 'documento',
	);

	/** Formulario de la membresía que no se ha podido hacer en esta petición: se vuelve a pintar con lo escrito. */
	private static ?array $membresia = null;

	public function render( string $content = '' ): string {
		$ambito = Ambito::actual();
		$repo   = PersonaRepository::make();
		if ( ! $repo ) {
			return $this->error_crm( 'ficha', asde_t( 'La conexión de tu grupo con el CRM no está configurada o no se ha podido comprobar. Un administrador puede revisarla en wp-admin → Grupos Scouts.' ) );
		}
		$socio = $this->socio( $ambito );
		try {
			$p = '' !== $socio ? $repo->obtener( $socio ) : null;
			if ( ! $p ) {
				return $this->denegado( asde_t( 'No se encuentra esa ficha en el CRM del grupo.' ) );
			}
			$perm = new PermisosFicha( $ambito, $p );
			if ( ! $perm->puede_ver() ) {
				return $this->denegado( asde_t( 'No tienes acceso a esta ficha.' ) );
			}
			$accion = $this->get( 'membresia' );
			if ( in_array( $accion, self::MEMBRESIA, true ) ) {
				return current_user_can( 'asde_manage_users' ) && ! \AsdeBalears\AreaPrivada\Auth\SoloLectura::activo() ? $this->layout( 'personas', $this->membresia( $p, $accion ) ) : $this->denegado();
			}
			if ( $ambito->es_oficina() ) { // v0.50: cada ficha que consulta la oficina queda en la auditoría.
				\AsdeBalears\AreaPrivada\Audit\Logger::log( 'oficina_ficha_consultada', 'persona', $socio );
			}
			$campos = ( new FichaService( $repo ) )->cargar( $p, $perm );
			$numero = ( new Numeracion( $repo ) )->de( $p );
			$sepa   = $this->sepa( $repo, $ambito, $p, $perm );
			$desde  = $this->socio_desde( $repo, $p, $perm );
		} catch ( CrmException $e ) {
			return $this->error_crm( 'ficha', asde_t( 'No se ha podido consultar el CRM: ' ) . $e->getMessage() );
		}

		return $this->layout(
			$perm->es_propia() ? 'ficha' : ( current_user_can( 'asde_manage_users' ) ? 'personas' : 'ficha' ),
			$this->tpl(
				'fichas/ficha',
				array(
					'p'             => $p,
					'perm'          => $perm,
					'campos'        => $campos,
					'numero'        => $numero,
					'cambios'       => Cambios::listado( '', $p->num_socio_asde ),
					'escritura'     => Escritura::disponible(),
					'altas'         => current_user_can( 'asde_manage_users' ) && Altas::disponible(),
					// v0.35.1: «Eliminar de la web» (sin membresía activa; en el CRM no cambia nada).
					'eliminar'      => current_user_can( 'asde_manage_users' ) && ! $perm->es_propia() && ! $p->tiene_membresia_activa() && ! \AsdeBalears\AreaPrivada\Socios\Eliminadas::esta( $p->num_socio_asde ),
					'representados' => $ambito->representados,
					'ambito'        => $ambito,
					'url'           => $this->url( 'ficha' ),
					'url_personas'  => $this->url( 'personas' ),
					'url_cambios'   => $this->url( 'cambios' ),
					'url_alta'      => $this->url( 'alta' ),
					'sepa'          => $sepa,
					'socio_desde'   => $desde,
				)
			)
		);
	}

	/**
	 * v0.34 (petición del usuario): «Socio/a desde» de la cabecera. Solo desde Personas (ni en la
	 * ficha propia ni en la de la familia), porque cuesta una consulta más al CRM; si falla, no se
	 * enseña y la ficha sale igual.
	 */
	private function socio_desde( PersonaRepository $repo, Persona $p, PermisosFicha $perm ): string {
		if ( $perm->es_propia() || $perm->es_representado() || ! $p->tiene_membresia_activa() ) {
			return '';
		}
		try {
			return Altas::socio_desde( ( new Altas( $repo->crm() ) )->membresias( $p->id ), wp_date( 'Y-m-d' ) );
		} catch ( CrmException | \InvalidArgumentException $e ) {
			return '';
		}
	}

	/**
	 * v0.33 (P-131): los datos bancarios de la ficha (del CRM, en vivo; no se guardan) y la
	 * autorización SEPA de su familia. Los ven Tesorería (y quien configura la Tesorería), la
	 * familia y el propio asociado mayor de edad; '' para el resto o con el módulo apagado.
	 */
	private function sepa( PersonaRepository $repo, Ambito $a, \AsdeBalears\AreaPrivada\Domain\Persona $p, PermisosFicha $perm ): string {
		if ( ! \AsdeBalears\AreaPrivada\Domain\Modulos::activo( \AsdeBalears\AreaPrivada\Domain\Modulos::SEPA ) ) {
			return '';
		}
		$familiar  = isset( $a->representados[ $p->num_socio_asde ] ) || ( $perm->es_propia() && null !== $p->edad() && $p->edad() >= 18 );
		$tesoreria = current_user_can( 'asde_tesoreria_config' );
		if ( ! $familiar && ! $tesoreria ) {
			return '';
		}
		$v       = $repo->valores( $p, array( CuentaCrm::IBAN, CuentaCrm::TITULAR, CuentaCrm::ENTIDAD ) );
		$iban    = \AsdeBalears\AreaPrivada\Sepa\Iban::normalizar( (string) ( $v[ CuentaCrm::IBAN ] ?? '' ) );
		$cuenta  = '' !== $iban ? array(
			'iban'    => $iban,
			'titular' => trim( (string) ( $v[ CuentaCrm::TITULAR ] ?? '' ) ),
			'entidad' => trim( (string) ( $v[ CuentaCrm::ENTIDAD ] ?? '' ) ),
		) : null;
		$familia = \AsdeBalears\AreaPrivada\Tesoreria\FamiliasTable::de_socio( $p->num_socio_asde );
		$mandato = $familia ? \AsdeBalears\AreaPrivada\Sepa\Mandatos::activo_de_familia( (int) $familia['id'] ) : null;
		return $this->tpl(
			'fichas/sepa',
			array(
				'cuenta'        => $cuenta,
				'familia'       => $familia,
				'mandato'       => $mandato,
				'coincide'      => $mandato && ( null !== \AsdeBalears\AreaPrivada\Sepa\Mandatos::cuenta_local( $mandato ) || ( $cuenta && hash_equals( (string) $mandato['iban_huella'], CuentaCrm::huella( $iban ) ) ) ),
				'url_gestion'   => $familiar ? $this->url( 'pagos' ) . ( $familia ? '#sepa-' . (int) $familia['id'] : '' ) : $this->url( 'sepa' ),
				'texto_gestion' => $familiar ? asde_t( 'Autorizar el cobro o cambiar la cuenta en «Mis pagos»' ) : asde_t( 'Ver las domiciliaciones (Tesorería)' ),
			)
		);
	}

	public function handle_post(): void {
		$ambito = Ambito::actual();
		$socio  = $this->post( 'socio' );
		$volver = $this->url( 'ficha', array( 'socio' => $socio ) );

		if ( isset( self::ACCIONES_MEMBRESIA[ $this->post( 'asde_action' ) ] ) ) {
			$this->membresia_post( $this->post( 'asde_action' ), $socio );
			return;
		}
		if ( 'eliminar_de_la_web' === $this->post( 'asde_action' ) ) {
			$this->eliminar_post( $socio );
			return;
		}
		if ( 'cancelar_cambio' === $this->post( 'asde_action' ) ) {
			$id = (int) $this->post( 'cambio' );
			check_admin_referer( 'asde_cancelar_cambio_' . $id );
			$r = Cambios::cancelar( $id );
			is_wp_error( $r ) ? Notices::add( 'error', esc_html( $r->get_error_message() ) ) : Notices::add( 'success', asde_t( 'Propuesta retirada.' ) );
			$this->redirect( $volver );
		}
		if ( 'guardar_ficha' !== $this->post( 'asde_action' ) || ! PersonaRepository::es_num_asde( $socio ) ) {
			Notices::add( 'error', asde_t( 'Petición no válida.' ) );
			$this->redirect( $this->url( 'ficha' ) );
		}
		check_admin_referer( 'asde_ficha_' . $socio );

		$repo = PersonaRepository::make();
		try {
			$p = $repo ? $repo->obtener( $socio ) : null;
			if ( ! $p ) {
				Notices::add( 'error', asde_t( 'No se encuentra esa ficha en el CRM del grupo.' ) );
				$this->redirect( $this->url( 'ficha' ) );
			}
			$perm = new PermisosFicha( $ambito, $p );
			if ( ! $perm->puede_ver() ) {
				wp_die( esc_html__( 'No tienes permiso para hacer esto.', 'asde-area-privada' ), 403 );
			}
			$enviado = array();
			foreach ( $this->post_array( 'campo' ) as $campo => $valor ) {
				$enviado[ sanitize_key( (string) $campo ) ] = (string) $valor;
			}
			$huellas = array();
			foreach ( $this->post_array( 'huella' ) as $campo => $h ) {
				$huellas[ sanitize_key( (string) $campo ) ] = (string) $h;
			}
			$r = ( new FichaService( $repo ) )->guardar( $p, $perm, $enviado, $huellas );
		} catch ( CrmException $e ) {
			Notices::add( 'error', esc_html( asde_t( 'No se ha podido consultar el CRM: ' ) . $e->getMessage() ) );
			$this->redirect( $volver );
		}

		$lista = static fn( array $a ) => esc_html( implode( ', ', $a ) );
		if ( $r['guardados'] ) {
			Notices::add( 'success', sprintf( asde_t( 'Guardado en el CRM: %s.' ), $lista( $r['guardados'] ) ) );
		}
		if ( $r['propuestos'] ) {
			Notices::add( 'success', sprintf( asde_t( 'Cambios enviados para su aprobación: %s. Te avisaremos en esta ficha cuando se revisen.' ), $lista( $r['propuestos'] ) ) );
		}
		if ( $r['rechazados'] ) {
			Notices::add( 'error', sprintf( asde_t( 'El CRM no ha aceptado: %s. Puede que el usuario del CRM no tenga permiso sobre esos campos.' ), $lista( $r['rechazados'] ) ) );
		}
		if ( $r['conflictos'] ) {
			Notices::add( 'warning', sprintf( asde_t( 'No se ha cambiado %s porque alguien lo modificó en el CRM mientras editabas. Revisa el valor actual y vuelve a intentarlo.' ), $lista( $r['conflictos'] ) ) );
		}
		foreach ( $r['errores'] as $e ) {
			Notices::add( 'error', esc_html( $e ) );
		}
		if ( ! array_filter( $r ) ) {
			Notices::add( 'info', asde_t( 'No había ningún cambio que guardar.' ) );
		}
		$this->redirect( $volver );
	}

	/**
	 * Formulario de la membresía en el CRM. Lee del CRM lo que hace falta enseñar: las
	 * membresías activas (baja y cambio de sección) o si ya tiene documento.
	 *
	 * @throws CrmException
	 */
	private function membresia( Persona $p, string $accion ): string {
		$altas = Altas::disponible() ? Altas::make() : null;
		$vars  = array(
			'p'         => $p,
			'accion'    => $accion,
			'altas'     => null !== $altas,
			'motivo'    => Altas::activo() ? asde_t( 'El usuario del CRM de tu grupo no tiene permiso para crear personas y membresías. Un administrador puede revisarlo en wp-admin → Grupos Scouts.' ) : asde_t( 'Las altas en el CRM no están activadas para tu grupo.' ),
			'estado'    => self::$membresia ?? array(
				'error'     => '',
				'valores'   => array(),
				'confirmar' => false,
				'otros'     => 0,
			),
			'activas'   => array(),
			'documento' => null,
			'cuenta'    => '',
			'familias'  => array(),
			'familia'   => '',
			'url'       => $this->url(
				'ficha',
				array(
					'socio'     => $p->num_socio_asde,
					'membresia' => $accion,
				)
			),
			'url_ficha' => $this->url( 'ficha', array( 'socio' => $p->num_socio_asde ) ),
		);
		if ( ! $altas ) {
			return $this->tpl( 'fichas/membresia', $vars );
		}
		if ( in_array( $accion, array( 'baja', 'seccion' ), true ) ) {
			$vars['activas'] = $altas->activas( $p->id );
		}
		if ( 'baja' === $accion ) {
			// Lo que cambia con la baja: las cuentas de acceso y la cuota de su familia.
			$vars['cuenta']   = Usuarios::por_socio( $p->num_socio_asde )?->user_login ?? '';
			$vars['familias'] = array_map( static fn( \WP_User $u ) => $u->user_login, Usuarios::tutores_de( $p->num_socio_asde ) );
			$vars['familia']  = self::familia( $p );
		}
		if ( 'documento' === $accion ) {
			$vars['documento'] = $altas->tiene_documento( $p->id );
		}
		return $this->tpl( 'fichas/membresia', $vars );
	}

	/** Nº de la familia de Tesorería en la que está la persona ('' si no está en ninguna). */
	private static function familia( Persona $p ): string {
		$id = FamiliasTable::mapa_socios()[ $p->num_socio_asde ] ?? 0;
		$f  = $id ? FamiliasTable::obtener( $id ) : null;
		return $f ? (string) $f['numero'] : '';
	}

	/** Una acción sobre la membresía: si sale bien, vuelve a la ficha con el aviso; si no, se vuelve a pintar el formulario. */
	private function membresia_post( string $accion, string $socio ): void {
		if ( ! current_user_can( 'asde_manage_users' ) ) {
			wp_die( esc_html__( 'No tienes permiso para hacer esto.', 'asde-area-privada' ), 403 );
		}
		if ( ! PersonaRepository::es_num_asde( $socio ) ) {
			Notices::add( 'error', asde_t( 'Petición no válida.' ) );
			$this->redirect( $this->url( 'personas' ) );
		}
		check_admin_referer( 'asde_membresia_' . $socio );
		$valores = array(
			'seccion'   => sanitize_key( $this->post( 'seccion' ) ),
			'fecha'     => $this->post( 'fecha' ),
			'motivo'    => sanitize_key( $this->post( 'motivo' ) ),
			'documento' => sanitize_text_field( $this->post( 'documento' ) ),
		);
		$fallo   = static function ( string $error, string $codigo = '', $datos = null ) use ( $valores ): void {
			self::$membresia = array(
				'error'     => $error,
				'valores'   => $valores,
				'confirmar' => 'asde_documento_confirmar' === $codigo,
				'otros'     => is_array( $datos ) && array_key_exists( 'otros', $datos ) ? $datos['otros'] : 0,
			);
		};
		$repo  = PersonaRepository::make();
		$altas = Altas::disponible() ? Altas::make() : null;
		if ( ! $repo || ! $altas ) {
			$fallo( Altas::activo() ? asde_t( 'La conexión con el CRM no está disponible.' ) : asde_t( 'Las altas en el CRM no están activadas para tu grupo.' ) );
			return;
		}
		try {
			$p = $repo->obtener( $socio );
		} catch ( CrmException $e ) {
			$fallo( asde_t( 'No se ha podido consultar el CRM: ' ) . $e->getMessage() );
			return;
		}
		if ( ! $p ) {
			Notices::add( 'error', asde_t( 'No se encuentra esa ficha en el CRM del grupo.' ) );
			$this->redirect( $this->url( 'personas' ) );
		}
		$nombre = esc_html( $p->nombre_completo() );
		$fecha  = esc_html( self::dma( '' !== $valores['fecha'] ? $valores['fecha'] : wp_date( 'Y-m-d' ) ) );
		switch ( $accion ) {
			case 'membresia_nueva':
				$r = $altas->nueva_membresia( $p, $valores['seccion'], $valores['fecha'] );
				/* translators: 1: nombre, 2: sección, 3: fecha */
				$ok = sprintf( asde_t( 'Membresía nueva en el CRM: %1$s, en %2$s desde el %3$s.' ), $nombre, esc_html( Secciones::nombre( $valores['seccion'] ) ), $fecha );
				break;
			case 'membresia_baja':
				$familia = self::familia( $p );
				$cuenta  = null !== Usuarios::por_socio( $p->num_socio_asde );
				$tutores = (bool) Usuarios::tutores_de( $p->num_socio_asde );
				$r       = $altas->baja( $p, $valores['fecha'], $valores['motivo'] );
				/* translators: 1: nombre, 2: fecha */
				$ok = sprintf( asde_t( '%1$s ha quedado de baja en el CRM desde el %2$s.' ), $nombre, $fecha );
				if ( $cuenta ) {
					$ok .= ' ' . asde_t( 'Su cuenta de acceso a la web ya no puede entrar.' );
				}
				if ( $tutores ) {
					$ok .= ' ' . asde_t( 'Las cuentas familiares que la representan ya no la ven; si no representan a nadie más con membresía activa, tampoco pueden entrar.' );
				}
				if ( '' !== $familia ) {
					/* translators: %s: nº de familia */
					$ok .= ' ' . sprintf( asde_t( 'Deja de contar en la cuota de la familia nº %s: Tesorería lo verá en «Bajas recientes».' ), esc_html( $familia ) );
				}
				if ( is_array( $r ) ) {
					// P-90: aviso por email a Tesorería y a la familia (nunca bloquea la baja ya hecha).
					$av  = AvisoBaja::enviar( $p, '' !== $valores['fecha'] ? $valores['fecha'] : wp_date( 'Y-m-d' ), $valores['motivo'], $familia );
					/* translators: 1: emails a Tesorería, 2: emails a la familia */
					$ok .= ' ' . sprintf( asde_t( 'Avisos por email: %1$d a Tesorería y %2$d a la familia.' ), $av['tesoreria'], $av['familia'] );
					if ( $av['familia_omitida'] ) {
						$ok .= ' ' . asde_t( 'Por el motivo de la baja, a la familia no se le ha escrito.' );
					} elseif ( $av['sin_email_familia'] ) {
						$ok .= ' ' . asde_t( 'No hay ningún email de la familia al que avisar.' );
					}
					if ( $av['errores'] ) {
						/* translators: %d: emails que no han salido */
						$ok .= ' ' . sprintf( asde_t( '%d no se han podido enviar (mira Usuarios → Emails).' ), $av['errores'] );
					}
				}
				break;
			case 'membresia_seccion':
				$r = $altas->cambio_seccion( $p, $valores['seccion'], $valores['fecha'] );
				/* translators: 1: nombre, 2: sección nueva, 3: fecha */
				$ok = sprintf( asde_t( '%1$s pasa a %2$s desde el %3$s. La membresía anterior queda cerrada con el motivo «cambio de sección».' ), $nombre, esc_html( Secciones::nombre( $valores['seccion'] ) ), $fecha );
				break;
			default: // poner_documento
				$r = $altas->poner_documento( $p, $valores['documento'], '1' === $this->post( 'confirmo' ) );
				/* translators: %s: nombre */
				$ok = sprintf( asde_t( 'Documento guardado en la ficha de %s.' ), $nombre );
				if ( is_array( $r ) && ( null === $r['otros'] || $r['otros'] > 0 ) ) {
					$ok .= ' ' . asde_t( 'Ese documento también está dado de alta en otro grupo u organización de Scouts de España.' );
				}
		}
		if ( is_wp_error( $r ) ) {
			$fallo( $r->get_error_message(), (string) $r->get_error_code(), $r->get_error_data() );
			return;
		}
		Notices::add( 'success', $ok );
		$this->redirect( $this->url( 'ficha', array( 'socio' => $socio ) ) );
	}

	/** «Eliminar de la web» (v0.35.1): solo Secretaría y Coordinación, y a quien no tiene membresía activa. */
	private function eliminar_post( string $socio ): void {
		if ( ! current_user_can( 'asde_manage_users' ) || ! PersonaRepository::es_num_asde( $socio ) ) {
			wp_die( esc_html__( 'No tienes permiso para hacer esto.', 'asde-area-privada' ), 403 );
		}
		check_admin_referer( 'asde_eliminar_' . $socio );
		$repo = PersonaRepository::make();
		try {
			$p = $repo ? $repo->obtener( $socio ) : null;
		} catch ( CrmException $e ) {
			Notices::add( 'error', esc_html( asde_t( 'No se ha podido consultar el CRM: ' ) . $e->getMessage() ) );
			$this->redirect( $this->url( 'ficha', array( 'socio' => $socio ) ) );
		}
		if ( ! $p ) {
			Notices::add( 'error', asde_t( 'No se encuentra esa ficha en el CRM del grupo.' ) );
			$this->redirect( $this->url( 'personas' ) );
		}
		$r = \AsdeBalears\AreaPrivada\Socios\Eliminadas::eliminar( $p, get_current_user_id() );
		if ( is_wp_error( $r ) ) {
			Notices::add( 'error', esc_html( $r->get_error_message() ) );
			$this->redirect( $this->url( 'ficha', array( 'socio' => $socio ) ) );
		}
		/* translators: %s: nombre */
		$ok = sprintf( asde_t( '%s ya no sale en la web. En el CRM no ha cambiado nada.' ), esc_html( $p->nombre_completo() ) );
		if ( '' !== $r['familia'] ) {
			/* translators: %s: nº de familia */
			$ok .= ' ' . sprintf( asde_t( 'Ha salido de la familia nº %s.' ), esc_html( $r['familia'] ) );
		}
		if ( $r['cuenta'] ) {
			$ok .= ' ' . asde_t( 'Su cuenta de acceso se ha desactivado.' );
		}
		if ( $r['familiares'] ) {
			/* translators: 1: cuentas familiares, 2: de ellas, desactivadas */
			$ok .= ' ' . sprintf( asde_t( 'Ya no está en %1$d cuenta(s) familiar(es); %2$d se han desactivado porque no representan a nadie más.' ), $r['familiares'], $r['desactivadas'] );
		}
		Notices::add( 'success', $ok );
		$this->redirect( $this->url( 'personas', array( 'ver' => 'inactivas' ) ) );
	}

	private static function dma( string $fecha ): string {
		$f = \DateTime::createFromFormat( '!Y-m-d', $fecha );
		return $f ? $f->format( 'd/m/Y' ) : $fecha;
	}

	/** Ficha pedida, o por defecto la propia o la del primer menor a cargo. */
	private function socio( Ambito $a ): string {
		$pedido = $this->get( 'socio' );
		if ( PersonaRepository::es_num_asde( $pedido ) ) {
			return $pedido;
		}
		if ( $a->persona ) {
			return $a->persona->num_socio_asde;
		}
		return (string) ( array_key_first( $a->representados ) ?? '' );
	}
}
