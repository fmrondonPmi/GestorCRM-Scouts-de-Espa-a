<?php
namespace AsdeBalears\AreaPrivada\Frontend\Views;

use AsdeBalears\AreaPrivada\Auth\Ambito;
use AsdeBalears\AreaPrivada\Frontend\Ayuda;
use AsdeBalears\AreaPrivada\Idiomas\Idioma;

defined( 'ABSPATH' ) || exit;

/**
 * Ayuda del área privada (v0.26, ver Frontend\Ayuda): el apartado de la página en el manual del rol
 * de quien entra (?asde_ayuda=1) y, desde la v0.28, el manual completo del rol (?asde_ayuda=manual),
 * los dos solo texto y en la ventana emergente; sin JavaScript, en su página. Al final, el enlace al
 * manual con capturas si está su dirección (ayuda extra, se sube a mano aparte).
 */
final class AyudaView extends View {

	public function __construct( private string $pagina = 'area' ) {}

	public function render( string $content = '' ): string {
		if ( ! is_user_logged_in() ) {
			return $this->denegado();
		}
		$manual = Ayuda::manual_para( Ambito::actual() );
		$datos  = array(
			'volver'     => self::es_modal() ? '' : $this->url( $this->pagina ),
			'castellano' => 'es' === Idioma::actual(),
		);
		if ( Ayuda::pide_completo() ) {
			$m     = '' !== Ayuda::manual_pedido() ? Ayuda::manual_pedido() : $manual;
			$datos += array(
				'ayuda'        => Ayuda::completo( $m ),
				'completo'     => true,
				'url_completo' => '',
				'extra'        => Ayuda::url_manual( $m ),
			);
		} else {
			$ayuda = Ayuda::de_pagina( $this->pagina, $manual );
			$de    = $ayuda ? $ayuda['manual'] : $manual;
			$ancla = $ayuda ? $ayuda['ancla'] : '';
			$datos += array(
				'ayuda'        => $ayuda,
				'completo'     => false,
				'url_completo' => '' !== $de ? Ayuda::url_completo( $de, $ancla ) : '',
				'extra'        => Ayuda::url_manual( $de, $ancla ),
			);
		}
		return $this->layout( $this->pagina, $this->tpl( 'comun/ayuda', $datos ) );
	}
}
