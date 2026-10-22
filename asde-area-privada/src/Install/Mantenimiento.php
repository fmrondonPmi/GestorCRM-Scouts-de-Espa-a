<?php
namespace AsdeBalears\AreaPrivada\Install;

use AsdeBalears\AreaPrivada\Audit\Logger;
use AsdeBalears\AreaPrivada\Crm\CrmException;
use AsdeBalears\AreaPrivada\Crm\PersonaRepository;
use AsdeBalears\AreaPrivada\Domain\Grupo;
use AsdeBalears\AreaPrivada\Domain\Grupos;
use AsdeBalears\AreaPrivada\Socios\Numeracion;
use AsdeBalears\AreaPrivada\Usuarios\EmailCuenta;
use AsdeBalears\AreaPrivada\Usuarios\Solicitudes;
use AsdeBalears\AreaPrivada\Usuarios\Usuarios;

defined( 'ABSPATH' ) || exit;

/**
 * Tarea diaria (WP-Cron):
 * 1. Sincroniza la tabla de equivalencias de números de socio con el CRM.
 * 2. Retira de las cuentas familiares a los menores que han cumplido 18 años:
 *    a partir de ahí solo accede la propia persona. Si una cuenta familiar se
 *    queda sin representados, pierde el rol de tutor.
 * 3. Mantiene al día el email guardado de las cuentas cuyo email viene de un
 *    campo del CRM (y lo guarda en las de antes de la v0.10).
 * 4. Borra los datos de las solicitudes de acceso ya resueltas hace tiempo.
 * 5. Eventos (v0.35): caduca las plazas ofrecidas sin confirmar, ofrece las libres a la
 *    lista de espera y manda los recordatorios de los que empiezan pronto.
 */
final class Mantenimiento {

	public const HOOK = 'asde_ap_mantenimiento_diario';

	/** Una sola vez tras actualizar a la v0.10: guardar el email de las cuentas existentes. */
	public const HOOK_EMAILS = 'asde_ap_emails_iniciales';

	public static function register(): void {
		add_action( self::HOOK, array( self::class, 'ejecutar' ) );
		add_action( self::HOOK_EMAILS, array( self::class, 'solo_emails' ) );
	}

	public static function programar(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK );
		}
	}

	public static function desprogramar(): void {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * Se ejecuta grupo a grupo (cada uno en su propio contexto, sin mezclar datos).
	 *
	 * @return array{numeros:int, tutelas_retiradas:int}
	 */
	public static function ejecutar(): array {
		$total = array(
			'numeros'           => 0,
			'tutelas_retiradas' => 0,
			'emails'            => 0,
		);
		Grupos::verificar_todos();
		foreach ( Grupos::todos() as $grupo ) {
			if ( '' === $grupo['crm_id'] ) {
				continue; // Sin conexión verificada con el CRM.
			}
			Grupo::forzar( $grupo );
			$repo = PersonaRepository::make();
			if ( ! $repo ) {
				continue;
			}
			try {
				$total['numeros']           += count( ( new Numeracion( $repo ) )->analizar_todo( true ) );
				$total['tutelas_retiradas'] += self::revisar_tutelas( $repo );
				$total['emails']            += self::sincronizar_emails( $repo );
			} catch ( CrmException $e ) {
				Logger::log( 'mantenimiento_error', 'crm', $grupo['numero'], array( 'error' => $e->getMessage() ) );
			}
			try {
				// v0.39 (fase 4): las compras sin sesión que no se pagan en N días se cancelan y devuelven las existencias.
				\AsdeBalears\AreaPrivada\Tienda\Servicio::cancelar_invitados_sin_pagar();
				\AsdeBalears\AreaPrivada\Tienda\Servicio::recordar_y_cancelar_socios(); // v0.40 (fase 5): recordatorio y cancelación de los pedidos de socios sin pagar.
				// Solo con el módulo encendido en el grupo (v0.35: nace apagado).
				if ( \AsdeBalears\AreaPrivada\Domain\Modulos::activo( \AsdeBalears\AreaPrivada\Domain\Modulos::EVENTOS, (string) $grupo['numero'] ) ) {
					\AsdeBalears\AreaPrivada\Eventos\Avisos::diario();
					\AsdeBalears\AreaPrivada\Eventos\AvisosTareas::diario(); // v0.40: tareas del calendario que vencen.
					\AsdeBalears\AreaPrivada\Menus\AvisoAlergenos::diario(); // v0.43: alérgenos del menú sin resolver (P-192).
				}
			} catch ( CrmException $e ) {
				Logger::log( 'mantenimiento_error', 'crm', $grupo['numero'], array( 'error' => $e->getMessage() ) );
			}
		}
		Grupo::forzar( null );
		Solicitudes::purgar();
		\AsdeBalears\AreaPrivada\Accesos\Concesiones::caducar_vencidas(); // v0.53 (doc 56): los permisos sueltos con fecha se desactivan solos y queda en el historial.
		\AsdeBalears\AreaPrivada\Tienda\PedidosTable::purgar_invitados(); // v0.39 (fase 4): datos de quien compró sin sesión, a los 90 días de entregar o cancelar.
		\AsdeBalears\AreaPrivada\ListaEspera\Inscripciones::mantenimiento(); // Lista de espera: sin confirmar, caducadas y avisos (v0.32).
		\AsdeBalears\AreaPrivada\Sepa\Mandatos::purgar(); // Mandatos revocados hace más de 14 meses.
		\AsdeBalears\AreaPrivada\Sepa\Remesas::purgar();  // Adeudos de remesas con cobro hace más de 14 meses.
		return $total;
	}

	/** Solo el paso de los emails, en todos los grupos (una vez, tras actualizar). @return int Cuentas actualizadas. */
	public static function solo_emails(): int {
		$n = 0;
		foreach ( Grupos::todos() as $grupo ) {
			if ( '' === $grupo['crm_id'] ) {
				continue;
			}
			Grupo::forzar( $grupo );
			$repo = PersonaRepository::make();
			try {
				$n += $repo ? self::sincronizar_emails( $repo ) : 0;
			} catch ( CrmException $e ) {
				Logger::log( 'mantenimiento_error', 'crm', $grupo['numero'], array( 'error' => $e->getMessage() ) );
			}
		}
		Grupo::forzar( null );
		return $n;
	}

	/**
	 * Cuentas del grupo actual cuyo email viene de un campo del CRM: se guarda el
	 * valor actual de ese campo si falta o ha cambiado (si el campo está vacío, se
	 * deja el guardado). @return int Cuentas actualizadas.
	 */
	public static function sincronizar_emails( PersonaRepository $repo ): int {
		$usuarios = array_filter(
			get_users(
				array(
					'meta_key' => Usuarios::META_EMAIL_ORIGEN, // phpcs:ignore WordPress.DB.SlowDBQuery
				)
			),
			static fn( \WP_User $u ) => ( Grupos::de_usuario( $u )['id'] ?? '' ) === Grupo::id()
		);
		$origenes = array();
		foreach ( $usuarios as $u ) {
			$o = Usuarios::email_origen( $u->ID );
			if ( $o ) {
				$origenes[ $u->ID ] = $o;
			}
		}
		if ( ! $origenes ) {
			return 0;
		}
		$personas = $repo->obtener_varias( array_column( $origenes, 'asde' ) );
		$n        = 0;
		foreach ( $origenes as $id => $o ) {
			$email = isset( $personas[ $o['asde'] ] ) ? ( $personas[ $o['asde'] ]->emails_por_campo()[ $o['campo'] ] ?? null ) : null;
			if ( $email && EmailCuenta::leer( $id ) !== $email ) {
				EmailCuenta::guardar( $id, $email );
				++$n;
			}
		}
		if ( $n ) {
			Logger::log( 'emails_sincronizados', 'grupo', Grupo::numero(), array( 'cuentas' => $n ) );
		}
		return $n;
	}

	/** Retira a los representados mayores de edad (usuarios del grupo actual). @return int Nº de vínculos retirados. */
	public static function revisar_tutelas( PersonaRepository $repo ): int {
		$retiradas = 0;
		$usuarios  = get_users(
			array(
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery
					array( 'key' => Usuarios::META_REPRESENTADO ),
					array(
						'key'   => Usuarios::META_GRUPO,
						'value' => Grupo::id(),
					),
				),
			)
		);
		foreach ( $usuarios as $user ) {
			$reps     = Usuarios::representados( $user->ID );
			$personas = $repo->obtener_varias( $reps );
			foreach ( $reps as $num ) {
				$p = $personas[ $num ] ?? null;
				// Solo se retira por mayoría de edad; si no aparece o no tiene membresía,
				// puede ser temporal: el acceso ya se deniega al entrar (Ambito).
				if ( $p && null !== $p->edad() && $p->edad() >= 18 ) {
					Usuarios::quitar_representado( $user->ID, $num );
					Logger::log( 'tutela_retirada_mayoria_edad', 'user', (string) $user->ID, array( 'socio' => $num ) );
					++$retiradas;
				}
			}
			if ( ! Usuarios::representados( $user->ID ) && in_array( Roles::TUTOR, (array) $user->roles, true ) ) {
				$user->remove_role( Roles::TUTOR );
				if ( ! Usuarios::roles_asde( $user ) ) {
					// v0.26.1: como al desactivar a mano, se borran su contraseña y su verificación en dos pasos.
					Usuarios::desactivar( $user );
				}
				Logger::log( 'cuenta_familiar_sin_representados', 'user', (string) $user->ID );
			}
		}
		return $retiradas;
	}
}
