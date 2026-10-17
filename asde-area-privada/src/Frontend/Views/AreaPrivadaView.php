<?php
namespace AsdeBalears\AreaPrivada\Frontend\Views;

use AsdeBalears\AreaPrivada\Auth\Ambito;
use AsdeBalears\AreaPrivada\Domain\Grupo;
use AsdeBalears\AreaPrivada\Eventos\Inscripciones;
use AsdeBalears\AreaPrivada\Eventos\Servicio as Eventos;
use AsdeBalears\AreaPrivada\Fichas\Cambios;
use AsdeBalears\AreaPrivada\Install\Roles;
use AsdeBalears\AreaPrivada\Integraciones\Wordfence;
use AsdeBalears\AreaPrivada\Socios\SociosTable;
use AsdeBalears\AreaPrivada\Tienda\Servicio;
use AsdeBalears\AreaPrivada\Usuarios\Solicitudes;

defined( 'ABSPATH' ) || exit;

final class AreaPrivadaView extends View {

	public function render( string $content = '' ): string {
		$ambito = Ambito::actual();
		if ( ! $ambito->puede_acceder() ) {
			return $this->denegado( $ambito->motivo() );
		}

		$limite_2fa = null;
		$user       = $ambito->user;
		$uniformes  = Servicio::pendientes( $ambito );
		$gestiona   = current_user_can( Servicio::CAP_GESTION );

		return $this->layout(
			'area',
			$this->tpl(
				'area/inicio',
				array(
					'ambito'        => $ambito,
					'grupo'         => Grupo::nombre(),
					'roles'         => array_map( array( Roles::class, 'label' ), $ambito->roles_vigentes() ),
					'num_local'     => $ambito->persona ? SociosTable::local_de( $ambito->persona->num_socio_asde ) : null,
					'pendiente_2fa' => Wordfence::pendiente( $user, $limite_2fa ),
					'limite_2fa'    => $limite_2fa,
					'url'           => fn( string $k, array $a = array() ) => $this->url( $k, $a ),
					// Lo que espera a que alguien del grupo lo revise (v0.10; uniformes, v0.11).
					'por_revisar'   => array(
						'solicitudes'      => current_user_can( 'asde_aprobar_solicitudes' ) ? Solicitudes::contar_pendientes( $ambito ) : 0,
						'cambios'          => current_user_can( 'asde_approve_changes' ) && $ambito->vision_global() ? Cambios::contar_pendientes() : 0,
						'uniforme_pago'    => $gestiona ? $uniformes['pago'] : 0,
						'uniforme_pedido'  => $gestiona ? $uniformes['pedido'] : 0,
						'uniforme_entrega' => $gestiona ? $uniformes['entrega'] : 0,
						// v0.19: listas de compra de material que esperan el visto bueno de Tesorería.
						'compras'          => current_user_can( \AsdeBalears\AreaPrivada\Inventario\Servicio::CAP_APROBAR ) ? \AsdeBalears\AreaPrivada\Inventario\ComprasTable::contar_pendientes() : 0,
						// v0.32: inscripciones de la lista de espera que nadie ha contactado.
						'lista_espera'     => current_user_can( 'asde_lista_espera' ) ? \AsdeBalears\AreaPrivada\ListaEspera\Inscripciones::contar_sin_contactar() : 0,
						// v0.35: presupuestos de eventos que esperan a Tesorería y autorizaciones por recoger en papel.
						'eventos_validar'  => Eventos::pendientes( $ambito )['validar'],
						'eventos_papel'    => Inscripciones::por_recoger( $ambito ),
					),
					// Uniformes entregados que el socio/a o su familia aún no ha confirmado.
					'por_confirmar' => $uniformes['confirmar'],
					'pide_uniforme' => current_user_can( Servicio::CAP_PEDIR ) && Servicio::propios( $ambito ),
					'gestiona_uniformes' => $gestiona,
					'eventos'            => Eventos::puede_entrar(),
					// v0.35: «Mis eventos» y lo que espera a la familia o a la persona.
					'mis_eventos'        => Inscripciones::en_menu( $ambito ),
					'eventos_pendientes' => Inscripciones::pendientes( $ambito ),
				)
			)
		);
	}
}
