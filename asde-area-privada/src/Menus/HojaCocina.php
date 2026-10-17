<?php
namespace AsdeBalears\AreaPrivada\Menus;

use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Auth\Ambito;
use AsdeBalears\AreaPrivada\Crm\PersonaRepository;
use AsdeBalears\AreaPrivada\Eventos\EventosTable;
use AsdeBalears\AreaPrivada\Eventos\InscripcionesTable;
use AsdeBalears\AreaPrivada\Frontend\TemplateLoader;
use AsdeBalears\AreaPrivada\Idiomas\Idioma;
use AsdeBalears\AreaPrivada\Usuarios\Contacto;
use AsdeBalears\AreaPrivada\Usuarios\RegistroEmails;
use AsdeBalears\AreaPrivada\Usuarios\Usuarios;

defined( 'ABSPATH' ) || exit;

/**
 * La hoja de cocina de un evento (doc 49, B3, apartado 9.1): para el equipo que prepara las comidas, **el nombre de cada persona
 * afectada y su alérgeno** (y la dieta, si el menú no la cumple), solo lo que cruza con el menú elegido. Sin diagnóstico, sin
 * medicación y sin el texto libre de la ficha.
 *
 * - Se compone **en vivo**, en memoria, al imprimirla o enviarla; lleva fecha y hora («válida a fecha de…»). **No se guarda nada**:
 *   el email deja constancia (tipo, destino enmascarado y fecha) pero no su contenido.
 * - La genera quien gestiona el evento. Se envía **solo** a las cuentas del equipo de apoyo (cocina, intendencia…); quien no tiene
 *   cuenta recibe la hoja impresa.
 * - Para avisar de que se ha quedado vieja, de cada hoja entregada solo se recuerda una huella con sal (no se puede volver a los
 *   datos) y cuándo: si luego cambia algo, la pantalla del menú dice que está desfasada. Se vuelve a generar a mano.
 */
final class HojaCocina {

	private const OPCION = 'asde_ap_hoja_cocina_';

	public static function puede_generar( array $e, Ambito $a ): bool {
		return MenuEvento::puede_ver( $e, $a );
	}

	/**
	 * Lo que va en la hoja.
	 *
	 * @return array{momento:string, platos:array[], sin_menu:bool, estado:string}
	 *         platos: cada uno con dia, comida, plato, y personas = [ nombre => [ alergenos => [..], trazas => [..], dieta => [..] ] ].
	 */
	public static function componer( array $e ): array {
		$r = Validacion::evaluar( $e, true );
		$p = array();
		foreach ( MenuEvento::platos( $e ) as $pl ) {
			$id   = $pl['plato']['id'];
			$info = $r['platos'][ $id ] ?? array( 'conflictos' => array(), 'riesgos' => array(), 'dieta' => array() );
			$por  = array();
			foreach ( $info['conflictos'] as $cod => $d ) {
				foreach ( $d['nombres'] as $n ) {
					$por[ $n ]['alergenos'][] = Alergenos::nombre( $cod );
				}
			}
			foreach ( $info['riesgos'] as $cod => $d ) {
				foreach ( $d['nombres'] as $n ) {
					$por[ $n ]['trazas'][] = Alergenos::nombre( $cod );
				}
			}
			foreach ( $info['dieta'] as $dieta => $d ) {
				foreach ( $d['nombres'] as $n ) {
					$por[ $n ]['dieta'][] = 'vegana' === $dieta ? asde_t( 'dieta vegana' ) : asde_t( 'dieta vegetariana' );
				}
			}
			ksort( $por );
			$p[] = array(
				'dia'      => $pl['plato']['dia'],
				'comida'   => $pl['plato']['comida'],
				'plato'    => IngredientesTable::COMUN === $pl['receta']['grupo'] ? asde_t( $pl['receta']['nombre'] ) : $pl['receta']['nombre'],
				'personas' => $por,
			);
		}
		return array(
			'momento'  => $r['momento'],
			'platos'   => $p,
			'sin_menu' => 'sin_menu' === $r['estado'],
			'estado'   => $r['estado'],
			'revisar'  => $r['textos_revisar'],
		);
	}

	/** Huella de lo que lleva la hoja (con sal: no permite volver a los datos). */
	public static function huella( array $hoja ): string {
		$simple = array();
		foreach ( $hoja['platos'] as $pl ) {
			$simple[] = array( $pl['dia'], $pl['comida'], $pl['plato'], $pl['personas'] );
		}
		return hash_hmac( 'sha256', (string) wp_json_encode( $simple ), wp_salt( 'auth' ) );
	}

	/** Apunta que se ha entregado una hoja (solo la huella y cuándo). */
	public static function recordar( array $e, array $hoja, string $como ): void {
		update_option( self::OPCION . $e['id'], array( 'huella' => self::huella( $hoja ), 'cuando' => current_time( 'mysql', true ), 'como' => $como ), false );
	}

	/** @return array{cuando:string, como:string, desfasada:bool}|null null si nunca se entregó una hoja. */
	public static function estado( array $e, array $hoja ): ?array {
		$o = get_option( self::OPCION . $e['id'], null );
		if ( ! is_array( $o ) || empty( $o['huella'] ) ) {
			return null;
		}
		return array(
			'cuando'    => (string) $o['cuando'],
			'como'      => (string) ( $o['como'] ?? '' ),
			'desfasada' => ! hash_equals( (string) $o['huella'], self::huella( $hoja ) ),
		);
	}

	public static function olvidar( int $evento_id ): void {
		delete_option( self::OPCION . $evento_id );
	}

	/** @return array<int, \WP_User> Las cuentas del equipo de apoyo (cocina, intendencia…) con cuenta; el resto recibe la hoja impresa. */
	public static function destinatarios( array $e ): array {
		$socios = array();
		foreach ( InscripcionesTable::equipo( $e['id'] ) as $f ) {
			if ( 'apoyo' === $f['papel'] ) {
				$socios[] = $f['socio'];
			}
		}
		$out = array();
		foreach ( Usuarios::por_socios( $socios ) as $u ) {
			$out[ $u->ID ] = $u;
		}
		return $out;
	}

	/**
	 * Envía la hoja por email al equipo de apoyo con cuenta, en el idioma de cada uno. En el registro de emails queda solo el tipo,
	 * el destino enmascarado y la fecha.
	 *
	 * @return array{enviados:int, fallidos:int, sin_cuenta:int}|\WP_Error
	 */
	public static function enviar( array $e, Ambito $a ) {
		if ( ! self::puede_generar( $e, $a ) ) {
			return new \WP_Error( 'asde_menus', asde_t( 'No puedes generar la hoja de cocina de este evento.' ) );
		}
		$hoja = self::componer( $e );
		if ( 'ok' !== $hoja['estado'] ) {
			return new \WP_Error( 'asde_menus', asde_t( 'No se ha podido preparar la hoja: falta el menú, los asistentes o la conexión con el CRM.' ) );
		}
		$dest = self::destinatarios( $e );
		$apoyo = count( array_filter( InscripcionesTable::equipo( $e['id'] ), static fn( $f ) => 'apoyo' === $f['papel'] ) );
		if ( ! $dest ) {
			return new \WP_Error( 'asde_menus', asde_t( 'Nadie del equipo de apoyo tiene cuenta: imprime la hoja y entrégala.' ) );
		}
		$repo = PersonaRepository::make();
		$ok   = 0;
		$mal  = 0;
		foreach ( $dest as $user ) {
			$email = Contacto::email( $user, $repo );
			$datos = array( 'tipo' => 'cocina', 'evento' => $e['id'] );
			$asunto = '';
			$html   = '';
			Idioma::en(
				Idioma::de_usuario( $user->ID ),
				static function () use ( $e, $hoja, $user, $repo, &$asunto, &$html ): void {
					/* translators: 1: evento */
					$asunto = sprintf( asde_t( 'Hoja de cocina: %s' ), $e['nombre'] );
					$html   = TemplateLoader::render(
						'email/menu-cocina',
						array(
							'saludo' => Contacto::saludo( $user, $repo ),
							'evento' => $e['nombre'],
							'numero' => EventosTable::numero( $e ),
							'cuando' => EventosTable::cuando( $e ),
							'hoja'   => $hoja,
						)
					);
				}
			);
			if ( ! $email ) {
				RegistroEmails::registrar( $user, RegistroEmails::EVENTOS, '', $asunto, RegistroEmails::SIN_EMAIL, asde_t( 'La cuenta no tiene un email al que escribir.' ), $datos );
				++$mal;
				continue;
			}
			$error = RegistroEmails::enviar( $email, $asunto, $html );
			RegistroEmails::registrar( $user, RegistroEmails::EVENTOS, Contacto::enmascarar( $email ), $asunto, '' === $error ? RegistroEmails::ENVIADO : RegistroEmails::ERROR, $error, $datos );
			'' === $error ? ++$ok : ++$mal;
		}
		if ( $ok ) {
			self::recordar( $e, $hoja, 'email' );
		}
		Logger::log( 'menu_hoja_enviada', 'evento', (string) $e['id'], array( 'enviados' => $ok, 'fallidos' => $mal ) );
		return array( 'enviados' => $ok, 'fallidos' => $mal, 'sin_cuenta' => max( 0, $apoyo - count( $dest ) ) );
	}
}
