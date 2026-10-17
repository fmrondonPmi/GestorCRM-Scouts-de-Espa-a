<?php
namespace AsdeBalears\AreaPrivada\Frontend\Views;

use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Domain\Grupo;
use AsdeBalears\AreaPrivada\Frontend\Notices;
use AsdeBalears\AreaPrivada\Menus\Alergenos;
use AsdeBalears\AreaPrivada\Menus\Conversion;
use AsdeBalears\AreaPrivada\Menus\IngredientesTable;
use AsdeBalears\AreaPrivada\Menus\RecetasTable;
use AsdeBalears\AreaPrivada\Menus\Recetario;

defined( 'ABSPATH' ) || exit;

/**
 * El recetario del grupo (v0.41, doc 49, B1): recetas con sus ingredientes y alérgenos, la biblioteca común de ejemplo,
 * los ingredientes del grupo, el escalado según los comensales y la carga desde CSV.
 *
 * No toca datos de personas: los alérgenos son los de los ingredientes. La comprobación contra los asistentes de un evento
 * llega con el menú del evento (B2 y B3).
 */
final class RecetasView extends EventosBaseView {

	/** Filas de ingredientes que se pueden añadir de golpe al formulario de una receta. */
	private const EXTRA = 3;

	public function render( string $content = '' ): string {
		if ( ! current_user_can( Recetario::CAP ) ) {
			return $this->denegado();
		}
		$cuerpo = $this->pantalla();
		return $this->layout( 'eventos_recetas', $this->subnav( 'eventos_recetas' ) . $cuerpo );
	}

	private function pantalla(): string {
		if ( '1' === $this->get( 'ingredientes' ) ) {
			return $this->ingredientes();
		}
		if ( '1' === $this->get( 'importar' ) ) {
			return $this->tpl(
				'menus/importar',
				array(
					'resultado' => $this->resultado_importacion(),
					'ejemplo'   => str_replace( "\xEF\xBB\xBF", '', Recetario::ejemplo() ),
					'url'       => $this->url( 'eventos_recetas', array( 'importar' => '1' ) ),
					'url_lista' => $this->url( 'eventos_recetas' ),
				)
			);
		}
		$id = $this->get( 'receta' );
		if ( 'nueva' === $id || ctype_digit( $id ) && (int) $id > 0 ) {
			return $this->receta( 'nueva' === $id ? null : RecetasTable::obtener( (int) $id ), 'nueva' === $id );
		}
		return $this->lista();
	}

	private function lista(): string {
		$archivadas = '1' === $this->get( 'archivadas' );
		$q          = Conversion::clave( $this->get( 'q' ) );
		$tipo       = isset( RecetasTable::TIPOS[ $this->get( 'tipo' ) ] ) ? $this->get( 'tipo' ) : '';
		$todas      = array_values(
			array_filter(
				RecetasTable::visibles( $archivadas ),
				static fn( array $r ) => ( '' === $tipo || $r['tipo'] === $tipo ) && ( '' === $q || str_contains( Conversion::clave( $r['nombre'] ), $q ) )
			)
		);
		$alergenos = array();
		foreach ( $todas as $r ) {
			$alergenos[ $r['id'] ] = Recetario::alergenos( RecetasTable::lineas( $r['id'] ) );
		}
		return $this->tpl(
			'menus/recetas',
			array(
				'propias'    => array_values( array_filter( $todas, static fn( $r ) => Grupo::numero() === $r['grupo'] ) ),
				'comunes'    => array_values( array_filter( $todas, static fn( $r ) => IngredientesTable::COMUN === $r['grupo'] ) ),
				'alergenos'  => $alergenos,
				'tipo'       => $tipo,
				'q'          => $this->get( 'q' ),
				'archivadas' => $archivadas,
				'url'        => $this->url( 'eventos_recetas' ),
				'url_nueva'  => $this->url( 'eventos_recetas', array( 'receta' => 'nueva' ) ),
				'url_ing'    => $this->url( 'eventos_recetas', array( 'ingredientes' => '1' ) ),
				'url_csv'    => $this->url( 'eventos_recetas', array( 'importar' => '1' ) ),
			)
		);
	}

	private function receta( ?array $r, bool $nueva ): string {
		if ( ! $nueva && ! $r ) {
			return $this->denegado( asde_t( 'Esa receta no existe.' ) );
		}
		$propia    = $nueva || ( $r && Grupo::numero() === $r['grupo'] );
		$lineas    = $r ? RecetasTable::lineas( $r['id'] ) : array();
		$comensales = max( 1, min( 5000, (int) ( $this->get( 'comensales' ) ?: ( $r['porciones'] ?? 10 ) ) ) );
		$borrador  = $this->borrador();
		if ( $borrador ) {
			$lineas = array();
			foreach ( (array) ( $borrador['lineas'] ?? array() ) as $l ) {
				$lineas[] = array( 'ingrediente_id' => (int) $l['ingrediente_id'], 'cantidad' => (float) $l['cantidad'], 'unidad' => (string) $l['unidad'], 'ingrediente' => IngredientesTable::obtener( (int) $l['ingrediente_id'] ) );
			}
		}
		return $this->tpl(
			'menus/receta',
			array(
				'r'            => $r ?: array( 'id' => 0, 'nombre' => '', 'tipo' => 'primero', 'porciones' => 10, 'elaboracion' => '', 'observaciones' => '', 'ejemplo' => false, 'grupo' => Grupo::numero(), 'activa' => true ),
				'borrador'     => $borrador,
				'propia'       => $propia,
				'lineas'       => $lineas,
				'escaladas'    => $r ? Recetario::escalar( $r, $lineas, $comensales ) : array(),
				'alergenos'    => Recetario::alergenos( $lineas ),
				'comensales'   => $comensales,
				'ingredientes' => array_values( array_filter( IngredientesTable::visibles(), static fn( $i ) => $i['activo'] ) ),
				'extra'        => self::EXTRA,
				'url'          => $this->url( 'eventos_recetas', $r ? array( 'receta' => (string) $r['id'] ) : array( 'receta' => 'nueva' ) ),
				'url_lista'    => $this->url( 'eventos_recetas' ),
				'url_ing'      => $this->url( 'eventos_recetas', array( 'ingredientes' => '1' ) ),
			)
		);
	}

	private function ingredientes(): string {
		$id       = (int) $this->get( 'ingrediente' );
		$edicion  = $id ? IngredientesTable::obtener( $id ) : null;
		$archivados = '1' === $this->get( 'archivados' );
		$lista    = IngredientesTable::visibles( $archivados );
		$usos     = array();
		foreach ( $lista as $i ) {
			$usos[ $i['id'] ] = IngredientesTable::en_uso( $i['id'] );
		}
		return $this->tpl(
			'menus/ingredientes',
			array(
				'lista'      => $lista,
				'usos'       => $usos,
				'edicion'    => $edicion && Grupo::numero() === $edicion['grupo'] ? $edicion : null,
				'archivados' => $archivados,
				'url'        => $this->url( 'eventos_recetas', array( 'ingredientes' => '1' ) ),
				'url_lista'  => $this->url( 'eventos_recetas' ),
			)
		);
	}

	/** Lo escrito en el formulario de una receta cuando no se pudo guardar. */
	private function borrador(): array {
		$clave = 'asde_ap_menus_receta_' . get_current_user_id();
		$datos = get_transient( $clave );
		delete_transient( $clave );
		return is_array( $datos ) ? $datos : array();
	}

	private function resultado_importacion(): ?array {
		$clave = sanitize_key( $this->get( 'resultado' ) );
		$datos = '' !== $clave ? get_transient( 'asde_ap_menus_import_' . get_current_user_id() . '_' . $clave ) : false;
		return is_array( $datos ) ? $datos : null;
	}

	// ---------------------------------------------------------------------
	// Acciones
	// ---------------------------------------------------------------------

	public function handle_post(): void {
		$accion = $this->post( 'asde_action' );
		if ( ! in_array( $accion, array( 'receta_guardar', 'receta_copiar', 'receta_archivar', 'receta_restaurar', 'ingrediente_guardar', 'ingrediente_archivar', 'ingrediente_restaurar', 'recetas_importar' ), true ) ) {
			return;
		}
		if ( ! current_user_can( Recetario::CAP ) ) {
			wp_die( esc_html__( 'No tienes permiso para hacer esto.', 'asde-area-privada' ), 403 );
		}
		check_admin_referer( 'asde_menus' );
		$lista = $this->url( 'eventos_recetas' );
		$ing   = $this->url( 'eventos_recetas', array( 'ingredientes' => '1' ) );

		switch ( $accion ) {
			case 'receta_guardar':
				$id     = (int) $this->post( 'receta' );
				$lineas = array();
				$ings   = $this->post_array( 'ing' );
				$cants  = $this->post_array( 'cant' );
				$unis   = $this->post_array( 'uni' );
				foreach ( $ings as $n => $i ) {
					$lineas[] = array( 'ingrediente_id' => (int) $i, 'cantidad' => Conversion::numero( (string) ( $cants[ $n ] ?? '' ) ), 'unidad' => (string) ( $unis[ $n ] ?? 'g' ) );
				}
				$datos = array(
					'nombre'        => $this->post( 'nombre' ),
					'tipo'          => $this->post( 'tipo' ),
					'porciones'     => (int) $this->post( 'porciones' ),
					'elaboracion'   => $this->post( 'elaboracion' ),
					'observaciones' => $this->post( 'observaciones' ),
				);
				$r = RecetasTable::guardar( $datos, array_slice( $lineas, 0, RecetasTable::MAX_LINEAS + 20 ), $id );
				if ( is_wp_error( $r ) ) {
					set_transient( 'asde_ap_menus_receta_' . get_current_user_id(), $datos + array( 'lineas' => $lineas ), 10 * MINUTE_IN_SECONDS );
					Notices::add( 'error', esc_html( $r->get_error_message() ) );
					$this->redirect( $this->url( 'eventos_recetas', array( 'receta' => $id ? (string) $id : 'nueva' ) ) );
				}
				Logger::log( $id ? 'menu_receta_editada' : 'menu_receta_creada', 'menus', (string) $r );
				Notices::add( 'success', asde_t( 'Receta guardada.' ) );
				$this->redirect( $this->url( 'eventos_recetas', array( 'receta' => (string) $r ) ) );
				break;

			case 'receta_copiar':
				$r = Recetario::copiar( (int) $this->post( 'receta' ) );
				if ( is_wp_error( $r ) ) {
					Notices::add( 'error', esc_html( $r->get_error_message() ) );
					$this->redirect( $lista );
				}
				Notices::add( 'success', asde_t( 'Receta copiada a tu grupo. Ya puedes editarla.' ) );
				$this->redirect( $this->url( 'eventos_recetas', array( 'receta' => (string) $r ) ) );
				break;

			case 'receta_archivar':
			case 'receta_restaurar':
				$id = (int) $this->post( 'receta' );
				RecetasTable::activar( $id, 'receta_restaurar' === $accion );
				Logger::log( 'receta_archivar' === $accion ? 'menu_receta_archivada' : 'menu_receta_recuperada', 'menus', (string) $id );
				Notices::add( 'success', 'receta_archivar' === $accion ? asde_t( 'Receta archivada.' ) : asde_t( 'Receta recuperada.' ) );
				$this->redirect( $lista );
				break;

			case 'ingrediente_guardar':
				$id = (int) $this->post( 'ingrediente' );
				$r  = IngredientesTable::guardar(
					array(
						'nombre'    => $this->post( 'nombre' ),
						'unidad'    => $this->post( 'unidad' ),
						'alergenos' => $this->post_array( 'alergenos' ),
						'trazas'    => $this->post_array( 'trazas' ),
						'animal'    => '1' === $this->post( 'animal' ),
						'coste'     => '' === trim( $this->post( 'coste' ) ) ? null : Conversion::numero( $this->post( 'coste' ) ),
						'revisado'  => '1' === $this->post( 'revisado' ),
					),
					$id
				);
				if ( is_wp_error( $r ) ) {
					Notices::add( 'error', esc_html( $r->get_error_message() ) );
					$this->redirect( $id ? $this->url( 'eventos_recetas', array( 'ingredientes' => '1', 'ingrediente' => (string) $id ) ) : $ing );
				}
				Logger::log( $id ? 'menu_ingrediente_editado' : 'menu_ingrediente_creado', 'menus', (string) $r );
				Notices::add( 'success', asde_t( 'Ingrediente guardado.' ) );
				$this->redirect( $ing );
				break;

			case 'ingrediente_archivar':
			case 'ingrediente_restaurar':
				$id = (int) $this->post( 'ingrediente' );
				IngredientesTable::activar( $id, 'ingrediente_restaurar' === $accion );
				Logger::log( 'ingrediente_archivar' === $accion ? 'menu_ingrediente_archivado' : 'menu_ingrediente_recuperado', 'menus', (string) $id );
				Notices::add( 'success', 'ingrediente_archivar' === $accion ? asde_t( 'Ingrediente archivado.' ) : asde_t( 'Ingrediente recuperado.' ) );
				$this->redirect( $ing );
				break;

			case 'recetas_importar':
				$this->importar();
				break;
		}
	}

	private function importar(): void {
		$aqui = $this->url( 'eventos_recetas', array( 'importar' => '1' ) );
		$f    = $_FILES['fichero'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.NonceVerification.Missing -- el nonce se comprueba en handle_post(); del fichero solo se usa su ruta temporal, comprobada con is_uploaded_file()
		$err  = is_array( $f ) ? (int) ( $f['error'] ?? UPLOAD_ERR_NO_FILE ) : UPLOAD_ERR_NO_FILE;
		if ( UPLOAD_ERR_NO_FILE === $err ) {
			Notices::add( 'error', asde_t( 'Elige el fichero CSV que quieres importar.' ) );
			$this->redirect( $aqui );
		}
		if ( UPLOAD_ERR_OK !== $err || ! is_uploaded_file( (string) $f['tmp_name'] ) || (int) $f['size'] > Recetario::MAX_BYTES || ! preg_match( '/\.(csv|txt)$/i', (string) $f['name'] ) ) {
			Notices::add( 'error', asde_t( 'El fichero no se ha podido subir. Tiene que ser un CSV de hasta 1 MB.' ) );
			$this->redirect( $aqui );
		}
		$r = Recetario::importar( (string) $f['tmp_name'] );
		if ( is_wp_error( $r ) ) {
			Notices::add( 'error', esc_html( $r->get_error_message() . ' ' . asde_t( 'No se ha cargado nada.' ) ) );
			$this->redirect( $aqui );
		}
		$clave = wp_generate_password( 12, false, false );
		set_transient( 'asde_ap_menus_import_' . get_current_user_id() . '_' . strtolower( $clave ), $r, HOUR_IN_SECONDS );
		$this->redirect( add_query_arg( 'resultado', strtolower( $clave ), $aqui ) );
	}
}
