<?php
namespace AsdeBalears\AreaPrivada\Frontend\Views;

use AsdeBalears\AreaPrivada\Auth\Ambito;
use AsdeBalears\AreaPrivada\Frontend\Ayuda;
use AsdeBalears\AreaPrivada\Idiomas\Idioma;

defined( 'ABSPATH' ) || exit;

/**
 * Ayuda de una página del área privada (v0.26, ver Frontend\Ayuda): el apartado del manual del
 * rol de quien entra y el enlace a su manual completo. Se abre en la ventana emergente
 * (?asde_ayuda=1 con la cabecera de la ventana); sin JavaScript, en su página.
 */
final class AyudaView extends View {

	public function __construct( private string $pagina = 'area' ) {}

	public function render( string $content = '' ): string {
		if ( ! is_user_logged_in() ) {
			return $this->denegado();
		}
		$manual = Ayuda::manual_para( Ambito::actual() );
		$ayuda  = Ayuda::de_pagina( $this->pagina, $manual );
		return $this->layout(
			$this->pagina,
			$this->tpl(
				'comun/ayuda',
				array(
					'ayuda'      => $ayuda,
					'wiki'       => $ayuda ? Ayuda::url_manual( $ayuda['manual'], $ayuda['ancla'] ) : Ayuda::url_manual( $manual ),
					'volver'     => self::es_modal() ? '' : $this->url( $this->pagina ),
					'castellano' => 'es' === Idioma::actual(),
				)
			)
		);
	}
}
